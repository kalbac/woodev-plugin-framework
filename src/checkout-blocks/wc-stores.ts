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

import { dispatch, select, subscribe } from '@wordpress/data';
import type { CountryStates } from './mapping';
import type { LocalityData, WcAddress } from './types';
import { wcRuntime } from './wc-runtime';

export const CART_STORE = 'wc/store/cart';
export const CHECKOUT_STORE = 'wc/store/checkout';

const EMPTY_ADDRESS: WcAddress = { city: '', state: '', country: '' };

interface CartSelectors {
	getCustomerData?: () => { shippingAddress?: WcAddress; billingAddress?: WcAddress };
	getNeedsShipping?: () => boolean;
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

/**
 * Asks the cart store for its cart WHILE THE BUNDLE IS BEING EVALUATED, so the store resolves it
 * from the server on every page load and never stands on its persisted copy alone (#1111).
 *
 * WooCommerce keeps the cart store in `localStorage.storeApiCartData`. When `storeApiCartHash`
 * equals the `woocommerce_cart_hash` cookie it starts the store from that copy and, on the window's
 * `load`, marks `getCartData` resolved WITHOUT running its resolver — the cart the page preloaded is
 * never applied (11.1 `wc-blocks-data.js`: `Wi()` and the `load` listener; 9.9.0
 * `data/cart/index.ts` and `persistence-layer.ts`). The hash covers the cart's items and total only
 * (`WC_Cart::get_cart_hash()`), so the copy persisted before an order still matches the same
 * product added again: the store then shows that order's rate as selected and its pickup point as
 * confirmed, the server holds neither, and the order is refused.
 *
 * A selector call queues its resolver, and a resolver once queued runs whatever marks the selector
 * resolved in the meantime. Made here — before `load` — it is therefore the page's own cart the
 * store ends up with: core's resolver reads `/wc/store/v1/cart`, which the Checkout page preloads,
 * so this costs no request there. On a first visit it changes nothing: the resolver runs anyway.
 *
 * WHAT THIS STANDS ON, AND WHERE IT WAS READ. «A queued resolver survives `finishResolution`» is how
 * `@wordpress/data` is written, not something it promises: `fulfillSelector()` marks the resolver
 * running and starts it from a `setTimeout( 0 )` that does not ask again whether the selector got
 * resolved meanwhile. Read in `@wordpress/data` 10.53.0 (this repository's test dependency) and in
 * `wp-includes/js/dist/data.js` of WordPress 6.6 and 7.1 (the rig's); the public resolver
 * documentation defines fulfilment, not this ordering. `checkout-blocks-persisted-cart.test.ts` runs
 * on the real package and is the alarm for a version that changes it. And it holds only for a call
 * made BEFORE the window's `load`: a bundle evaluated after it (a «delay JavaScript» optimiser)
 * finds the selector already marked resolved, the call starts nothing, and core's behaviour stays.
 */
export function resolveCartFromServer(): void {
	const selectors = select( CART_STORE ) as unknown as CartSelectors | undefined;

	if ( typeof selectors?.getCartData !== 'function' ) {
		return;
	}

	const resolved = selectors.hasFinishedResolution?.( 'getCartData' ) === true;

	selectors.getCartData();

	if ( ! resolved ) {
		keepEditsMadeBeforeTheCart();
	}
}

/** The address fields `edited` holds differently from `origin`. */
function addressEdits( origin: WcAddress, edited: WcAddress ): Partial< WcAddress > {
	return Object.fromEntries(
		Object.keys( edited )
			.filter( ( key ) => edited[ key ] !== origin[ key ] )
			.map( ( key ) => [ key, edited[ key ] ] )
	);
}

/**
 * Keeps an address edit made BEFORE the cart {@link resolveCartFromServer} asked for arrives.
 *
 * With a persisted copy core marks the cart resolved on `load` — the address form may render — while
 * the queued resolver has not brought the server's cart yet. That cart is taken whole, addresses
 * included, whatever the shopper has typed since: the resolver's `receiveCart()` does not look at
 * the dirty flag core keeps for exactly this (9.9.0 `data/cart/resolvers.ts`, `thunks.ts`,
 * `reducers.ts`; the same in 11.1). The window is a few milliseconds on a desktop and a few hundred
 * on a slow phone, so nothing is blocked for it; what was typed in it is written back over the
 * arrived address, field by field, and core pushes it like any other edit.
 *
 * Until that first reply no request of the page has answered, so every address change in the store
 * is the form's. The import cannot be told by the selector's resolution state: on the rig (WooCommerce
 * 11.1) the resolver is already running when `load` marks it finished, so it reads «resolved, not
 * resolving» as its cart lands. It is told by being FIRST — and the watch ends, untouched, as soon as
 * core's own address push is in flight: a reply after that answers the push, not the page load.
 */
function keepEditsMadeBeforeTheCart(): void {
	if ( typeof subscribe !== 'function' ) {
		return;
	}

	const read = () => ( { shipping: readShippingAddress(), billing: readBillingAddress() } );
	const origin = read();
	const reply = readCartReply();
	let typed = origin;

	const unsubscribe = subscribe( () => {
		if ( isCustomerDataUpdating() ) {
			unsubscribe();
			return;
		}

		if ( readCartReply() === reply ) {
			typed = read();
			return;
		}

		unsubscribe();

		const arrived = read();
		const actions = dispatch( CART_STORE ) as unknown as CartActions | undefined;
		const restore = ( key: 'shipping' | 'billing', write?: ( address: WcAddress ) => void ): void => {
			const edits = addressEdits( origin[ key ], typed[ key ] );

			if ( Object.keys( addressEdits( arrived[ key ], { ...arrived[ key ], ...edits } ) ).length > 0 ) {
				write?.( { ...arrived[ key ], ...edits } );
			}
		};

		restore( 'shipping', actions?.setShippingAddress );
		restore( 'billing', actions?.setBillingAddress );
	}, CART_STORE );
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

/** The delivery address; billing is the destination when WooCommerce forces billing-only delivery. */
export function readDeliveryAddress( billingOnly: boolean, registrySelect: typeof select = select ): WcAddress {
	if ( ! billingOnly ) {
		return readShippingAddress( registrySelect );
	}

	const selectors = registrySelect( CART_STORE ) as unknown as CartSelectors | undefined;

	return selectors?.getCustomerData?.().billingAddress ?? EMPTY_ADDRESS;
}

/** Whether the cart has a delivery address at all (virtual-only carts do not show the chooser). */
export function cartNeedsShipping( registrySelect: typeof select = select ): boolean {
	const selectors = registrySelect( CART_STORE ) as unknown as CartSelectors | undefined;

	return selectors?.getNeedsShipping?.() !== false;
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

/** Whether the active delivery address has arrived from WooCommerce's cart store. */
export function isDeliveryAddressAuthoritative( billingOnly: boolean, registrySelect: typeof select = select ): boolean {
	if ( ! billingOnly ) {
		return isShippingAddressAuthoritative( registrySelect );
	}

	const selectors = registrySelect( CART_STORE ) as unknown as CartSelectors | undefined;

	if ( typeof selectors?.hasFinishedResolution === 'function' ) {
		return selectors.hasFinishedResolution( 'getCartData' ) === true;
	}

	return ( selectors?.getCustomerData?.().billingAddress?.country ?? '' ) !== '';
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

/** Writes locality to the billing delivery address when the store forces delivery there. */
export function writeDeliveryLocality( city: string, state: string | null, billingOnly: boolean ): void {
	if ( ! billingOnly ) {
		writeNativeLocality( city, state );
		return;
	}

	const actions = dispatch( CART_STORE ) as unknown as CartActions | undefined;
	const patch = state === null ? { city } : { city, state };

	actions?.setBillingAddress?.( { ...readBillingAddress(), ...patch } );
	// WooCommerce mirrors every billing edit to the shipping store in forced mode. Programmatic
	// address writes do not run its mount-only synchronization, but framework consumers still read
	// shippingAddress for locality and pickup context.
	actions?.setShippingAddress?.( { ...readShippingAddress(), ...patch } );
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
export async function refreshRates( billingOnly = false ): Promise< void > {
	const actions = dispatch( CART_STORE ) as unknown as CartActions | undefined;

	if ( typeof actions?.updateCustomerData !== 'function' ) {
		return;
	}

	const { city, state, country } = readDeliveryAddress( billingOnly );

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
