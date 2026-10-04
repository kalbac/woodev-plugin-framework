/**
 * The chooser's only contact with WooCommerce's client state (SP-11 C-1, #1087): the public
 * `wc/store/cart` and `wc/store/checkout` stores, and the `wcSettings` the server published.
 *
 * Core address-store sync ONLY (the C-1 scope): the native shipping address is read and written
 * through `setShippingAddress` — the store's own subscriber then submits `/cart/update-customer`
 * and the cart's shipping rates are recalculated by core. No framework `/cart/extensions` namespace.
 * Place Order is held through `wc/store/checkout`'s public `disableCheckoutFor`.
 *
 * Kept as one thin module so the component and its tests talk to one seam.
 *
 * @package woodev-plugin-framework
 */

import { dispatch, select } from '@wordpress/data';
import type { CountryStates } from './mapping';
import type { LocalityData, WcAddress } from './types';
import { wcRuntime } from './wc-runtime';

export const CART_STORE = 'wc/store/cart';
export const CHECKOUT_STORE = 'wc/store/checkout';

const EMPTY_ADDRESS: WcAddress = { city: '', state: '', country: '' };

interface CartSelectors {
	getCustomerData?: () => { shippingAddress?: WcAddress; billingAddress?: WcAddress };
}

interface CartActions {
	setShippingAddress?: ( address: WcAddress ) => void;
	setBillingAddress?: ( address: WcAddress ) => void;
	updateCustomerData?: (
		customerData: { shipping_address: Pick< WcAddress, 'city' | 'state' | 'country' > },
		editing?: boolean,
		haveAddressFieldsForShippingRatesChanged?: boolean
	) => Promise< unknown >;
}

interface CheckoutSelectors {
	getUseShippingAsBilling?: () => boolean;
}

interface CheckoutActions {
	disableCheckoutFor?: ( work: () => Promise< unknown > ) => Promise< unknown >;
}

/** The server-published data, or `null` when WooCommerce's settings runtime is absent. */
export function readLocalityData(): LocalityData | null {
	const getSetting = wcRuntime()?.wcSettings?.getSetting;

	if ( typeof getSetting !== 'function' ) {
		return null;
	}

	const data = getSetting< LocalityData | null >( 'woodev-shipping-locality_data', null );

	return data && typeof data === 'object' ? data : null;
}

/** `countryData[ country ].states` — the FINAL `woocommerce_states` list for the country. */
export function readCountryStates( country: string ): CountryStates {
	const getSetting = wcRuntime()?.wcSettings?.getSetting;

	if ( typeof getSetting !== 'function' || country === '' ) {
		return {};
	}

	const countryData = getSetting< Record< string, { states?: CountryStates } > >( 'countryData', {} );
	const states = countryData[ country ]?.states;

	return states && typeof states === 'object' && ! Array.isArray( states ) ? states : {};
}

/**
 * `registrySelect` lets a `useSelect()` callback pass ITS `select`, so the hook subscribes to the
 * store; the default is the global one for non-reactive reads.
 */
export function readShippingAddress( registrySelect: typeof select = select ): WcAddress {
	const selectors = registrySelect( CART_STORE ) as unknown as CartSelectors | undefined;

	return selectors?.getCustomerData?.().shippingAddress ?? EMPTY_ADDRESS;
}

export function readBillingAddress(): WcAddress {
	const selectors = select( CART_STORE ) as unknown as CartSelectors | undefined;

	return selectors?.getCustomerData?.().billingAddress ?? EMPTY_ADDRESS;
}

export function readUseShippingAsBilling(): boolean {
	const selectors = select( CHECKOUT_STORE ) as unknown as CheckoutSelectors | undefined;

	return selectors?.getUseShippingAsBilling?.() ?? false;
}

/**
 * Writes the city (and, when `state` is not `null`, the state) into the native shipping address —
 * merged into the CURRENT full address so names, street and phone survive — and mirrors it into the
 * billing address when the core «use shipping as billing» flag says the two are one.
 *
 * Answers whether the shipping address actually CHANGED: an unchanged address gives core's own
 * address sync nothing to push, so the caller has to ask for the rates itself.
 */
export function writeNativeLocality( city: string, state: string | null ): boolean {
	const actions = dispatch( CART_STORE ) as unknown as CartActions | undefined;
	const patch = state === null ? { city } : { city, state };
	const current = readShippingAddress();
	const changed = current.city !== city || ( state !== null && current.state !== state );

	actions?.setShippingAddress?.( { ...current, ...patch } );

	if ( readUseShippingAsBilling() ) {
		actions?.setBillingAddress?.( { ...readBillingAddress(), ...patch } );
	}

	return changed;
}

/**
 * Recalculates the cart's shipping rates against the saved chain as it NOW stands, without ever
 * overwriting what the shopper has in the address form.
 *
 * Deliberately not a cart re-read (`invalidateResolution( 'getCartData' )`): that hands the store
 * the SERVER's address, which erases a city the shopper typed but core has not pushed yet (its push
 * is debounced 1.5 s). Core's own `updateCustomerData` is used instead, the way core's address sync
 * uses it: it SENDS the client's current locality — so the server rates what the form says — and,
 * called with `editing = true`, takes only the cart contents from the reply
 * (`receiveCartContents`), leaving both addresses in the store alone. Read from WooCommerce 11.1
 * (`wc-blocks-data.js`, `wi=`) and 9.9.0 (`data/cart/thunks.ts`), not recalled.
 *
 * Only country, state and city travel: the server merges a partial address into the one it holds,
 * and a half-typed postcode is core's to validate and push, not ours.
 */
export async function refreshRates(): Promise< void > {
	const actions = dispatch( CART_STORE ) as unknown as CartActions | undefined;

	if ( typeof actions?.updateCustomerData !== 'function' ) {
		return;
	}

	const { city, state, country } = readShippingAddress();

	try {
		await actions.updateCustomerData( { shipping_address: { city, state, country } }, true, true );
	} catch {
		// Core has already recorded the error; the next address push recalculates.
	}
}

/**
 * Runs `work` with Place Order blocked, through `wc/store/checkout`'s public `disableCheckoutFor`
 * (WooCommerce 9.9+: `data/checkout/thunks.ts`; 11.1: `wc-blocks-data.js`, `Na=`). Feature-detected:
 * without it `work` simply runs — the server still refuses to rate or place an order against a
 * record the native address does not name (`Location_Service::is_customer_record_stale()`).
 */
export function gateCheckout( work: () => Promise< void > ): Promise< void > {
	const actions = dispatch( CHECKOUT_STORE ) as unknown as CheckoutActions | undefined;

	if ( typeof actions?.disableCheckoutFor !== 'function' ) {
		return work();
	}

	let started: Promise< void > | null = null;
	const once = (): Promise< void > => ( started ??= work() );

	try {
		return Promise.resolve( actions.disableCheckoutFor( once ) ).then( () => undefined );
	} catch {
		return once();
	}
}
