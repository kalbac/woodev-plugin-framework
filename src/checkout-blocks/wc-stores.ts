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
	hasFinishedResolution?: ( selector: string, args?: unknown[] ) => boolean;
	getCartData?: () => { extensions?: unknown };
	isCustomerDataUpdating?: () => boolean;
	getCartErrors?: () => unknown[];
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

/**
 * Whether the address {@link readShippingAddress} answers is the customer's — WooCommerce's cart
 * data has arrived — rather than the store's empty defaults from before it did. The same test core
 * uses for «the cart is loaded» (`hasFinishedResolution( 'getCartData' )` — read in WooCommerce
 * 11.1's `wc-blocks-data.js` and `wc-cart-checkout-base-frontend.js`; a standard `@wordpress/data`
 * meta-selector of any store with resolvers).
 *
 * Until it is, an address that names no city or another country proves nothing about the saved
 * locality. A store without the meta-selector is taken at its word once it names a country: the
 * empty defaults never do.
 */
export function isShippingAddressAuthoritative( registrySelect: typeof select = select ): boolean {
	const selectors = registrySelect( CART_STORE ) as unknown as CartSelectors | undefined;

	if ( typeof selectors?.hasFinishedResolution === 'function' ) {
		return selectors.hasFinishedResolution( 'getCartData' ) === true;
	}

	return ( selectors?.getCustomerData?.().shippingAddress?.country ?? '' ) !== '';
}

/** The fields a locality is resolved from, compared without case or stray spaces. */
function localityAddressKey( address: Partial< WcAddress > ): string {
	return [ address.country, address.state, address.city ]
		.map( ( part ) => String( part ?? '' ).trim().replace( /\s+/g, ' ' ).toLowerCase() )
		.join( '|' );
}

/** The native shipping address's country/state/city, as the lifecycle compares them (#1110). */
export function readShippingAddressKey( registrySelect: typeof select = select ): string {
	return localityAddressKey( readShippingAddress( registrySelect ) );
}

/**
 * Whether core's customer-data push is IN FLIGHT (`isCustomerDataUpdating`, a public selector of
 * `wc/store/cart` — 11.1 `wc-blocks-data.js`, 9.9.0 `data/cart/selectors.ts`). It does NOT cover the
 * 1.5 s debounce before the push (a module-private timer there), nor tell a failed push from a
 * successful one — see {@link readCartReply}.
 */
export function isCustomerDataUpdating( registrySelect: typeof select = select ): boolean {
	const selectors = registrySelect( CART_STORE ) as unknown as CartSelectors | undefined;

	return selectors?.isCustomerDataUpdating?.() === true;
}

/**
 * A handle on «the cart answered»: the `extensions` object of the cart data. Every reply core takes
 * into the store passes through `camelCaseKeys`, so each one leaves a NEW object here; a failed or
 * aborted request leaves the previous one in place. (The shipping address cannot serve: core writes
 * the form's own edit into `cartData.shippingAddress` — `getCartData()` and `getCustomerData()`
 * read the same state — so it never differs from the form.)
 */
export function readCartReply( registrySelect: typeof select = select ): unknown {
	const selectors = registrySelect( CART_STORE ) as unknown as CartSelectors | undefined;

	return selectors?.getCartData?.().extensions;
}

/** Whether the cart store holds an error — an API refusal of the last request, a refusal's cart included. */
export function hasCartError( registrySelect: typeof select = select ): boolean {
	const selectors = registrySelect( CART_STORE ) as unknown as CartSelectors | undefined;
	const errors = selectors?.getCartErrors?.();

	return Array.isArray( errors ) && errors.length > 0;
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
 * Core's own address sync pushes a changed address only after its 1.5 s debounce, and an unchanged
 * one never — so the caller always follows this with {@link refreshRates}, under the checkout gate.
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
 * Takes the destination the server moved to a pickup point's address — together with the point's
 * confirmation, in one request (`Store_Api_Pickup::replace_destination()`, #1089) — into the native
 * shipping address, so the form shows, and the order is placed with, the address the confirmation
 * is bound to. Written explicitly: WooCommerce keeps the reply's addresses out of the store while it
 * considers the shopper's own edits unsaved (`wc-blocks-data.js`: `overwriteDirtyCustomerData`).
 *
 * Only the named fields move; names, city and phone stay the shopper's. The billing address follows
 * only where it is the same address — `mirrorBilling`, or core's «use shipping as billing» flag — and
 * a separate billing address is never touched.
 */
export function adoptDestination(
	destination: Pick< WcAddress, 'address_1' | 'postcode' >,
	mirrorBilling: boolean
): void {
	const actions = dispatch( CART_STORE ) as unknown as CartActions | undefined;
	const differs = ( address: WcAddress ): boolean =>
		( [ 'address_1', 'postcode' ] as const ).some(
			( key ) => destination[ key ] !== undefined && ( address[ key ] ?? '' ) !== destination[ key ]
		);

	const shipping = readShippingAddress();

	if ( differs( shipping ) ) {
		actions?.setShippingAddress?.( { ...shipping, ...destination } );
	}

	if ( mirrorBilling || readUseShippingAsBilling() ) {
		const billing = readBillingAddress();

		if ( differs( billing ) ) {
			actions?.setBillingAddress?.( { ...billing, ...destination } );
		}
	}
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
 * Subscribes to WooCommerce's public failed-checkout event. The cart is refreshed only after a
 * payment attempt fails, when its order now carries the placed pickup point and the server can
 * return that confirmation in the cart extension snapshot.
 */
export function subscribeCheckoutFailure( listener: () => void ): () => void {
	const subscribe = wcRuntime()?.blocksCheckoutEvents?.checkoutEvents?.onCheckoutFail;

	return typeof subscribe === 'function' ? subscribe( listener ) : () => {};
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
