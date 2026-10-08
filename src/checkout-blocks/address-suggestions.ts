/**
 * Enhances WooCommerce's native delivery address input with the existing location typeahead.
 *
 * @package woodev-plugin-framework
 */

import { dispatch, subscribe } from '@wordpress/data';
import { initializeSettlementScope, readSettlementScope, subscribeSettlementScope } from './address-scope';
import { forgetSelection, selectRecord, SuggestUnavailableError } from './rest';
import { sharedChainSync } from './chain-sync';
import { CART_STORE, gateCheckout, readDeliveryAddress, readLocalityData, refreshRates } from './wc-stores';
import { wcRuntime } from './wc-runtime';
import type { LocationConfig, LocationRecord, WcAddress } from './types';

interface AddressRecord extends LocationRecord {
	street?: { name?: string; type?: string } | null;
	house?: string | null;
	block?: string | null;
	postcode?: string | null;
}

interface AddressSuggestion {
	key: string;
	label: string;
	value: string;
	record: AddressRecord;
}

interface TypeaheadApi {
	detach: () => void;
}

interface TypeaheadOptions {
	minChars?: number;
	debounceMs?: number;
	emptyText?: string;
	errorText?: string;
	fetch: ( query: string, signal?: AbortSignal ) => Promise< AddressSuggestion[] >;
	onSelect: ( suggestion: AddressSuggestion ) => void;
}

type AttachTypeahead = ( input: HTMLInputElement, options: TypeaheadOptions ) => TypeaheadApi;

interface CartActions {
	setShippingAddress?: ( address: WcAddress ) => void;
	setBillingAddress?: ( address: WcAddress ) => void;
}

interface RuntimeWindow extends Window {
	WoodevLocationTypeahead?: AttachTypeahead;
}

function forcedBillingAddress(): boolean {
	return wcRuntime()?.wcSettings?.getSetting?.< boolean >( 'forcedBillingAddress', false ) === true;
}

function addressTarget( config: LocationConfig ): 'shipping' | 'billing' {
	return config.billingOnly === true && forcedBillingAddress() ? 'billing' : 'shipping';
}

function addressValue( record: AddressRecord ): string {
	const street = record.street;
	const parts = [
		street ? `${ street.type ?? '' } ${ street.name ?? '' }`.trim() : '',
		record.house,
		record.block,
	].filter( ( part ): part is string => typeof part === 'string' && part.trim() !== '' );

	return parts.join( ', ' ) || ( typeof record.label === 'string' ? record.label : '' );
}

function addressSuggestion( record: AddressRecord ): AddressSuggestion {
	return { key: record.key, label: record.label ?? '', value: addressValue( record ), record };
}

async function suggestAddress(
	config: LocationConfig,
	query: string,
	country: string,
	signal?: AbortSignal
): Promise< AddressSuggestion[] > {
	const url = new URL( config.endpoints.suggest, window.location.href );
	url.searchParams.set( 'q', query );
	url.searchParams.set( 'level', 'address' );
	url.searchParams.set( 'country', country );
	const within = readSettlementScope() ?? '';

	if ( within ) {
		url.searchParams.set( 'within', within );
	}

	let response: Response;
	try {
		response = await fetch( url.toString(), {
			credentials: 'same-origin',
			headers: { Accept: 'application/json' },
			signal,
		} );
	} catch ( error ) {
		if ( ( error as { name?: string } ).name === 'AbortError' ) {
			throw error;
		}
		throw new SuggestUnavailableError( 'network' );
	}

	if ( ! response.ok ) {
		throw new SuggestUnavailableError( String( response.status ) );
	}

	const body = ( await response.json() ) as { suggestions?: Array< { record?: AddressRecord } > };
	return Array.isArray( body.suggestions )
		? body.suggestions.flatMap( ( suggestion ) => suggestion.record ? [ addressSuggestion( suggestion.record ) ] : [] )
		: [];
}

function patchAddress( record: AddressRecord ): Partial< WcAddress > {
	const patch: Partial< WcAddress > = { address_1: addressValue( record ) };
	if ( typeof record.postcode === 'string' && record.postcode.trim() !== '' ) {
		patch.postcode = record.postcode;
	}
	return patch;
}

function writeAddress( target: 'shipping' | 'billing', address: WcAddress ): void {
	const actions = dispatch( CART_STORE ) as unknown as CartActions | undefined;
	if ( target === 'billing' ) {
		actions?.setBillingAddress?.( address );
	} else {
		actions?.setShippingAddress?.( address );
	}
}

/** Watches React's address field lifecycle and returns a teardown for tests and page cleanup. */
export function watchBlockAddressSuggestions( root: ParentNode = document ): () => void {
	const data = readLocalityData();
	const config = data?.enabled ? data.location : null;
	const attach = ( window as RuntimeWindow ).WoodevLocationTypeahead;
	if ( ! config || typeof attach !== 'function' ) {
		return () => {};
	}
	initializeSettlementScope( config.selection?.record.key ?? null );

	let input: HTMLInputElement | null = null;
	let inputCountry = '';
	let widget: TypeaheadApi | null = null;
	let stopped = false;
	let selectionVersion = 0;
	const target = addressTarget( config );
	const readAddress = () => readDeliveryAddress( target === 'billing' );
	const sync = sharedChainSync( {
		select: ( record ) => selectRecord( config, record ),
		forget: () => forgetSelection( config ),
		refresh: () => refreshRates( target === 'billing' ),
		gate: gateCheckout,
		retryDelayMs: 500,
	} );

	const detach = (): void => {
		widget?.detach();
		widget = null;
		input = null;
		inputCountry = '';
	};

	const refresh = (): void => {
		if ( stopped ) {
			return;
		}
		const address = readAddress();
		const supported = config.levels[ address.country.toUpperCase() ]?.address === true;
		const id = target === 'billing' ? 'billing-address_1' : 'shipping-address_1';
		const next = supported ? root.querySelector< HTMLInputElement >( `#${ id }` ) : null;
		if ( next === input && widget && address.country === inputCountry ) {
			return;
		}
		detach();
		if ( ! next ) {
			return;
		}
		input = next;
		inputCountry = address.country;
		widget = attach( next, {
			fetch: ( query, signal ) => suggestAddress( config, query, address.country, signal ),
			onSelect: ( suggestion ) => {
				const chosenInput = next;
				const selectedValue = suggestion.value;
				const currentAtPick = readAddress();
				const version = ++selectionVersion;
				sync.request( { kind: 'select', record: suggestion.record }, ( outcome ) => {
					if ( outcome.status !== 'applied' || version !== selectionVersion || ! chosenInput.isConnected || chosenInput.value !== selectedValue ) {
						return outcome.status === 'applied';
					}
					const latest = readAddress();
					const patch = patchAddress( suggestion.record );
					// A pickup point can replace postcode while /select is in flight; keep the newer value.
					if ( ( latest.postcode ?? '' ) !== ( currentAtPick.postcode ?? '' ) ) {
						patch.postcode = latest.postcode;
					}
					writeAddress( target, { ...latest, ...patch } );
					return true;
				} );
			},
		} );
	};

	const observer = new MutationObserver( refresh );
	observer.observe( root === document ? document.documentElement : root, { childList: true, subtree: true } );
	const unsubscribeStore = subscribe( refresh, CART_STORE );
	const unsubscribeScope = subscribeSettlementScope( () => {
		selectionVersion++;
		detach();
		refresh();
	} );
	refresh();

	return () => {
		stopped = true;
		observer.disconnect();
		unsubscribeStore();
		unsubscribeScope();
		detach();
	};
}
