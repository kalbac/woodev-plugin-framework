/**
 * The chooser's only contact with WooCommerce's client state (SP-11 C-1, #1087): the public
 * `wc/store/cart` and `wc/store/checkout` stores, and the `wcSettings` the server published.
 *
 * Core address-store sync ONLY (the C-1 scope): the native shipping address is read and written
 * through `setShippingAddress` — the store's own subscriber then submits `/cart/update-customer`
 * and the cart's shipping rates are recalculated by core. No framework `/cart/extensions` namespace.
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
	invalidateResolutionForStoreSelector?: ( selector: string ) => void;
}

interface CheckoutSelectors {
	getUseShippingAsBilling?: () => boolean;
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
 */
export function writeNativeLocality( city: string, state: string | null ): void {
	const actions = dispatch( CART_STORE ) as unknown as CartActions | undefined;
	const patch = state === null ? { city } : { city, state };

	actions?.setShippingAddress?.( { ...readShippingAddress(), ...patch } );

	if ( readUseShippingAsBilling() ) {
		actions?.setBillingAddress?.( { ...readBillingAddress(), ...patch } );
	}
}

/**
 * Asks the cart store to re-read the cart from the server. Used after the server-side chain was
 * dropped for a hand-edited city: core's own address sync may have recalculated the rates BEFORE the
 * `/forget` write landed, and this makes sure they are recalculated once more against the cleared chain.
 */
export function refreshCart(): void {
	const actions = dispatch( CART_STORE ) as unknown as CartActions | undefined;

	actions?.invalidateResolutionForStoreSelector?.( 'getCartData' );
}
