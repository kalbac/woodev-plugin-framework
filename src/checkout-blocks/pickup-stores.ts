/**
 * The pickup button's only contact with WooCommerce's client state (SP-11 C-2b, #1089): the cart
 * store's shipping rates and `woodev-shipping` extension data, the payment and validation stores,
 * and the `cart/extensions` transport.
 *
 * NOTHING HERE DECIDES WHICH RATE IS OURS. The server names the owner of the cart's chosen rate
 * (`Store_Api_Pickup::owner()`), and the confirmed point is the server's snapshot. What this module
 * adds is the one check the server cannot make for the browser: that both still describe the rate
 * the shopper is looking at RIGHT NOW. WooCommerce's rate selection is a round trip, and until it
 * answers, the extension data in the store is about the previous rate.
 *
 * Kept as one thin module so the component and its tests talk to one seam (as `wc-stores.ts`).
 *
 * @package woodev-plugin-framework
 */

import { dispatch, select, subscribe } from '@wordpress/data';
import type {
	PickupConfig,
	PickupData,
	PickupDestination,
	PickupEcho,
	PickupExtension,
	PickupFailure,
	PickupFieldDescriptor,
	PickupOwner,
	PickupSelectionResult,
	PickupSessionApi,
	PickupSnapshot,
} from './pickup-types';
import type { WcAddress } from './types';
import {
	adoptDestination,
	CART_STORE,
	readBillingAddress,
	readShippingAddress,
	restoreDestination,
} from './wc-stores';
import { wcRuntime } from './wc-runtime';

export const PAYMENT_STORE = 'wc/store/payment';
export const VALIDATION_STORE = 'wc/store/validation';
export const NOTICES_STORE = 'core/notices';

/** The notice context the checkout shows at the top of its form (WooCommerce's `noticeContexts.CHECKOUT`). */
export const CHECKOUT_NOTICES = 'wc/checkout';

/** The Store API error code every pickup refusal carries (`Store_Api_Pickup::refuse()`). */
export const REFUSAL_CODE = 'woodev_pickup_validation';

interface ShippingPackage {
	shipping_rates?: Array< { rate_id?: string; selected?: boolean } >;
}

interface CartSelectors {
	getCartData?: () => { extensions?: Record< string, unknown > };
	getShippingRates?: () => ShippingPackage[];
}

interface PaymentSelectors {
	getActivePaymentMethod?: () => string;
}

interface ValidationSelectors {
	getValidationError?: ( id: string ) => { message?: string; hidden?: boolean } | undefined;
}

interface NoticeActions {
	createErrorNotice?: ( message: string, options: { id: string; context: string; isDismissible: boolean } ) => void;
	removeNotice?: ( id: string, context: string ) => void;
}

interface ValidationActions {
	setValidationErrors?: ( errors: Record< string, { message: string; hidden: boolean } > ) => void;
	clearValidationError?: ( id: string ) => void;
}

/** The server-published data, or `null` when WooCommerce's settings runtime is absent. */
export function readPickupData(): PickupData | null {
	const getSetting = wcRuntime()?.wcSettings?.getSetting;

	if ( typeof getSetting !== 'function' ) {
		return null;
	}

	const data = getSetting< PickupData | null >( 'woodev-shipping-pickup_data', null );

	return data && typeof data === 'object' ? data : null;
}

/**
 * `cart.extensions[ namespace ]` — the SAME object the store holds, so a `useSelect()` that returns
 * it re-renders only when the cart really changed. `registrySelect` lets a `useSelect()` callback
 * pass ITS `select`; the default is the global one for non-reactive reads.
 */
export function readExtension( namespace: string, registrySelect: typeof select = select ): PickupExtension | null {
	const selectors = registrySelect( CART_STORE ) as unknown as CartSelectors | undefined;
	const extension = selectors?.getCartData?.().extensions?.[ namespace ];

	return extension && typeof extension === 'object' ? ( extension as PickupExtension ) : null;
}

/**
 * The full id of the rate chosen for the FIRST package — the one delivery chain the framework
 * supports (operator decision D-6 A) — or `''`.
 */
export function readSelectedRateId( registrySelect: typeof select = select ): string {
	const selectors = registrySelect( CART_STORE ) as unknown as CartSelectors | undefined;
	const rates = selectors?.getShippingRates?.()?.[ 0 ]?.shipping_rates;
	const chosen = Array.isArray( rates ) ? rates.find( ( rate ) => rate.selected === true ) : undefined;

	return typeof chosen?.rate_id === 'string' ? chosen.rate_id : '';
}

/** What the button shows: nothing, a prompt, or the confirmed point. */
export interface PickupView {
	/** The field owning the chosen rate; `null` when the rate is not one of ours. */
	field: PickupFieldDescriptor | null;
	owner: PickupOwner | null;
	/** The point confirmed for THIS rate; `null` when there is none. */
	confirmed: PickupSnapshot | null;
}

const NO_VIEW: PickupView = { field: null, owner: null, confirmed: null };

/**
 * Reduces the server's extension data to what applies to the rate the shopper has chosen NOW.
 *
 * An owner or a snapshot naming another rate is not «ours with an old point» — it is data about a
 * rate the shopper has already left (the selection round trip has not answered yet), and showing
 * its button or its point would offer, or claim, a pickup point for a courier rate.
 */
export function resolveView(
	fields: PickupFieldDescriptor[],
	extension: PickupExtension | null,
	selectedRateId: string
): PickupView {
	const owner = extension?.owner ?? null;

	if ( ! owner || selectedRateId === '' || owner.rate_id !== selectedRateId ) {
		return NO_VIEW;
	}

	const field = fields.find( ( entry ) => entry.pluginId === owner.plugin_id && entry.fieldId === owner.field_id );

	if ( ! field ) {
		return NO_VIEW;
	}

	const snapshot = extension?.pickup?.[ field.pluginId ]?.[ field.fieldId ] ?? null;
	const confirmed =
		snapshot && typeof snapshot.point_id === 'string' && snapshot.point_id !== '' && snapshot.rate_id === selectedRateId
			? snapshot
			: null;

	return { field, owner, confirmed };
}

/** The view as the stores stand at this instant — for the session's live reads. */
export function currentView( data: PickupData ): PickupView {
	const namespace = data.namespace ?? '';

	return resolveView( data.fields ?? [], readExtension( namespace ), readSelectedRateId() );
}

/** The picker config the owning handler localized, or `null` when its script was not enqueued. */
export function readConfig( field: PickupFieldDescriptor ): PickupConfig | null {
	const config = ( window as unknown as Record< string, unknown > )[ field.configKey ];

	return config && typeof config === 'object' && typeof ( config as PickupConfig ).fieldId === 'string'
		? ( config as PickupConfig )
		: null;
}

/** `pickup-session.js`'s API, or `null` when the script is not on the page. */
export function readSessionApi(): PickupSessionApi | null {
	const api = ( window as unknown as { WoodevPickupSession?: PickupSessionApi } ).WoodevPickupSession;

	return api && typeof api.open === 'function' ? api : null;
}

/**
 * The server GATEWAY ID of the payment method the shopper has chosen, or `''`.
 *
 * The payment store names the active REGISTRATION, and a registration's name is not the gateway's
 * id: a payment method may register as `name: 'prepaid-ui', paymentMethodId: 'bacs'`. WooCommerce
 * resolves the name through its public registry before it posts the order
 * (`wc-cart-checkout-base-frontend.js`: `{ ...getExpressPaymentMethods(), ...getPaymentMethods() }
 * [ active ]?.paymentMethodId`, read from 11.1) and so does this — the server checks what it is sent
 * against its own gateway ids and refuses a registration name it has never heard of.
 *
 * A name the registry does not know is sent as it is: it is the gateway id wherever the two were
 * never told apart, and the server verifies it either way.
 */
export function readActivePaymentMethod(): string {
	const selectors = select( PAYMENT_STORE ) as unknown as PaymentSelectors | undefined;
	const active = selectors?.getActivePaymentMethod?.();

	if ( typeof active !== 'string' || active === '' ) {
		return '';
	}

	const registry = wcRuntime()?.wcBlocksRegistry;
	const registrations = { ...registry?.getExpressPaymentMethods?.(), ...registry?.getPaymentMethods?.() };
	const gatewayId = Object.prototype.hasOwnProperty.call( registrations, active )
		? registrations[ active ]?.paymentMethodId
		: undefined;

	return typeof gatewayId === 'string' && gatewayId !== '' ? gatewayId : active;
}

/**
 * What the checkout request echoes for every pickup field: the confirmation's identity for the
 * field that owns the chosen rate, `null` for every other one. The server refuses an echo that
 * disagrees with its own snapshot, and an echo for a field that does not own the order's rate.
 */
export function buildEcho(
	fields: PickupFieldDescriptor[],
	view: PickupView
): Record< string, Record< string, PickupEcho | null > > {
	const echo: Record< string, Record< string, PickupEcho | null > > = {};

	for ( const field of fields ) {
		const confirmed = view.field === field ? view.confirmed : null;

		echo[ field.pluginId ] = {
			...echo[ field.pluginId ],
			[ field.fieldId ]: confirmed
				? {
						plugin_id: confirmed.plugin_id,
						field_id: confirmed.field_id,
						point_id: confirmed.point_id,
						locality: confirmed.locality,
						rate_id: confirmed.rate_id,
				  }
				: null,
		};
	}

	return echo;
}

function isRecord( value: unknown ): value is Record< string, unknown > {
	return typeof value === 'object' && value !== null;
}

function failure( error: unknown ): PickupFailure {
	const source = isRecord( error ) ? error : {};
	const data = isRecord( source.data ) ? source.data : {};

	return {
		status: typeof data.status === 'number' ? data.status : 0,
		code: typeof source.code === 'string' ? source.code : '',
		message: typeof source.message === 'string' ? source.message : '',
	};
}

/** Settles when the last confirmation asked for has been answered; `null` while none is in flight. */
let confirmationInFlight: Promise< void > | null = null;

/**
 * {@link movableAddress} as the last confirmation's own move left it, while confirmations are in
 * flight — the one change to those fields between a command being asked and its answer landing
 * that is not the shopper's. `null` when nothing in flight has moved the address.
 */
let addressAsMoved: string | null = null;

/**
 * The native address fields a confirmation may replace (`adoptDestination()`), as one comparable
 * value: the shipping address's street line and postcode and, where the store ships to the billing
 * address, the billing address's — there the billing form is the one the shopper types in.
 */
function movableAddress( billingIsShipping: boolean ): string {
	const fields = ( address: WcAddress ): string[] => [ address.address_1 ?? '', address.postcode ?? '' ];

	return JSON.stringify( [
		fields( readShippingAddress() ),
		billingIsShipping ? fields( readBillingAddress() ) : null,
	] );
}

/**
 * `address` — a {@link movableAddress} value — as a confirmation's own move leaves it: the fields
 * `destination` names hold the point's, in every address the value covers.
 *
 * That is the change the store goes through when the reply lands and nothing is unsaved: core takes
 * the reply's addresses into the cart store BEFORE `extensionCartUpdate()` resolves (WooCommerce
 * 11.1, `wc-blocks-data.js`: `gi=` — `receiveCart( response )`, addresses included unless
 * `WOOCOMMERCE_CHECKOUT_IS_CUSTOMER_DATA_DIRTY`), and the server has already moved them.
 */
function asMovedTo( address: string, destination: PickupDestination ): string {
	const take = ( fields: string[] | null ): string[] | null =>
		fields && [ destination.address_1 ?? fields[ 0 ], destination.postcode ?? fields[ 1 ] ];
	const [ shipping, billing ] = JSON.parse( address ) as [ string[], string[] | null ];

	return JSON.stringify( [ take( shipping ), take( billing ) ] );
}

/**
 * Records every value {@link movableAddress} takes from now until `stop()`, oldest first — what a
 * confirmation's answer is judged against when it lands.
 *
 * The value read AFTER the answer cannot say whether the shopper edited the address meanwhile. Core
 * puts the reply's own address over it whenever nothing is unsaved at that instant, and an edit
 * core has already pushed is not unsaved: the store then reads exactly as it does after an
 * untouched confirmation (#1091). The values it went through tell the two apart — every write to
 * those fields is a dispatch on the cart store (`SET_SHIPPING_ADDRESS` from the form,
 * `SET_CART_DATA` for a reply), so its subscribers see each value before the next replaces it.
 */
function watchAddress( billingIsShipping: boolean ): { seen: string[]; stop: () => void } {
	const seen = [ movableAddress( billingIsShipping ) ];
	const stop = subscribe( () => {
		const now = movableAddress( billingIsShipping );

		if ( now !== seen[ seen.length - 1 ] ) {
			seen.push( now );
		}
	}, CART_STORE );

	return { seen, stop };
}

/**
 * The street line and postcode a {@link movableAddress} value holds: the shipping address's, and
 * the billing address's where the value covers it (`null` where it does not).
 */
function addressFields( address: string ): [ PickupDestination, PickupDestination | null ] {
	const fields = ( [ street, postcode ]: string[] ): PickupDestination => ( { address_1: street, postcode } );
	const [ shipping, billing ] = JSON.parse( address ) as [ string[], string[] | null ];

	return [ fields( shipping ), billing && fields( billing ) ];
}

/**
 * Whether the reply's snapshot is the server's confirmation of the command for `pointId`: that
 * point, or the one the server corrected it to. A domain may correct the point while confirming
 * (`woodev_shipping_pickup_point_selection`); the server then keeps the CORRECTED point, the
 * snapshot names it, and the verdict carries it (`selection.point`) for the map session to take in
 * place of the one it holds. The verdict carrying a point is the test, not that point's id — that
 * copy is escaped for the browser, the snapshot's is not.
 */
function confirmsCommand( snapshot: PickupSnapshot, pointId: string ): boolean {
	if ( typeof snapshot.point_id !== 'string' || snapshot.point_id === '' ) {
		return false;
	}

	return snapshot.point_id === pointId || isRecord( snapshot.selection?.point );
}

/**
 * Confirms `pointId` for `field` through the Store API (`cart/extensions`, operator decision
 * D-3 B) and answers in the classic confirmation's own shape, which the map session applies.
 *
 * - RESOLVES with the server's verdict: the snapshot's `selection` once the server kept the point,
 *   or `{ allowed: false, reason }` when it REFUSED — the reason is the server's own customer-safe
 *   message, shown on the point's card.
 * - REJECTS when there is no verdict: the request failed, the quota was hit (429), or the reply
 *   carries no confirmation of this command (see `confirmsCommand()` — a point the server
 *   CORRECTED the choice to is its confirmation, and is taken with its address like any other).
 *
 * Only the point's ID travels: the server re-fetches the point and reads the rate, the
 * destination and the cart weight itself. WooCommerce takes the recalculated cart into its store
 * before this resolves, so the button renders from the returned snapshot without another request.
 *
 * Where the store replaces the address with the point's (`pickup_replace_address`), the server has
 * moved the destination in the same request and the confirmation names the fields it moved; they
 * are taken into the native address here, before this resolves — so still under the caller's
 * checkout gate. `billingIsShipping`: the store ships to the billing address.
 *
 * THREE GUARDS AGAINST A LATE ANSWER (SP-11 C-3, #1090):
 *
 * - Commands leave ONE AT A TIME, in the order they were asked. The cart store takes whichever
 *   reply arrives last, so two confirmations in flight together could leave the button showing
 *   the point of the one the server processed first.
 * - `isCurrent()` is asked when the reply lands: whether the shopper is still on the rate the
 *   point was asked for. A reply that lands after they left it REJECTS (`…_superseded`) and moves
 *   no address — the form must not take a pickup point's street for a courier order. The caller
 *   answers from its own record, never from the cart store: the late reply has just overwritten
 *   the store's selected rate with the one the server saw (`receiveCart()`).
 * - The ADDRESS is the shopper's as much as the rate is. A reply that would move the street line
 *   or the postcode REJECTS (`…_superseded`) when the shopper's last word on those fields is no
 *   longer what they were when the point was ASKED for — the shopper dismissed the dialog and
 *   typed their own, on the same rate. Theirs is the newer word, and the server drops the
 *   confirmation it made for the point's address once it hears of it. The fields are watched from
 *   the click (`watchAddress()`), not from when the command leaves (it may wait behind another
 *   one), and the changes that are not the shopper's are told apart: an earlier confirmation's
 *   own move (`addressAsMoved`), and this reply's — core takes the reply's addresses into the
 *   store before the reply reaches this code whenever nothing is unsaved (`asMovedTo()`, #1091).
 *   So the shopper's last word is the address as it stood BEFORE that, not the one read now. An
 *   edit core has not pushed is still in the store, and core pushes it. One it HAS pushed is
 *   under the reply's address by now: it is put back and sent again before this rejects
 *   (`restoreDestination()`), so the form keeps what the shopper typed, the cart stops showing
 *   the point, and a command waiting behind this one reaches a server that has heard of it.
 */
export function confirmPoint(
	namespace: string,
	field: PickupFieldDescriptor,
	pointId: string,
	billingIsShipping = false,
	isCurrent: () => boolean = () => true
): Promise< PickupSelectionResult > {
	const watch = watchAddress( billingIsShipping );
	const send = (): Promise< PickupSelectionResult > =>
		sendConfirmation( namespace, field, pointId, billingIsShipping, isCurrent, watch.seen );
	const result = confirmationInFlight ? confirmationInFlight.then( send ) : send();
	const settled: Promise< void > = result
		.then(
			() => undefined,
			() => undefined
		)
		.then( () => {
			watch.stop();

			if ( confirmationInFlight === settled ) {
				confirmationInFlight = null;
				addressAsMoved = null;
			}
		} );

	confirmationInFlight = settled;

	return result;
}

async function sendConfirmation(
	namespace: string,
	field: PickupFieldDescriptor,
	pointId: string,
	billingIsShipping: boolean,
	isCurrent: () => boolean,
	seen: string[]
): Promise< PickupSelectionResult > {
	const update = wcRuntime()?.blocksCheckout?.extensionCartUpdate;

	if ( typeof update !== 'function' ) {
		throw { status: 0, code: 'woodev_pickup_transport_missing', message: '' } as PickupFailure;
	}

	const command: Record< string, string > = { point_id: pointId };
	const payment = readActivePaymentMethod();

	if ( payment !== '' ) {
		command.payment_method = payment;
	}

	let cart: unknown;

	try {
		cart = await update( {
			namespace,
			data: { pickup: { [ field.pluginId ]: { [ field.fieldId ]: command } } },
		} );
	} catch ( error ) {
		const reason = failure( error );

		if ( reason.code === REFUSAL_CODE && reason.status !== 429 && reason.message !== '' ) {
			return { allowed: false, reason: reason.message };
		}

		throw reason;
	}

	const extensions = isRecord( cart ) && isRecord( cart.extensions ) ? cart.extensions : {};
	const extension = isRecord( extensions[ namespace ] ) ? ( extensions[ namespace ] as PickupExtension ) : null;
	const snapshot = extension?.pickup?.[ field.pluginId ]?.[ field.fieldId ] ?? null;

	if ( ! snapshot || ! confirmsCommand( snapshot, pointId ) ) {
		throw { status: 0, code: 'woodev_pickup_not_confirmed', message: '' } as PickupFailure;
	}

	if ( ! isCurrent() ) {
		throw { status: 0, code: 'woodev_pickup_superseded', message: '' } as PickupFailure;
	}

	const destination = movedDestination( snapshot );

	if ( destination ) {
		const now = movableAddress( billingIsShipping );

		if ( now !== seen[ seen.length - 1 ] ) {
			seen.push( now );
		}

		// The last change is this reply's own move: core has taken its address into the store, over
		// whatever was there. The shopper's last word is then the address as it stood before.
		const before = seen.length > 1 ? seen[ seen.length - 2 ] : null;
		const replaced = before !== null && now === asMovedTo( before, destination );
		const theirs = replaced ? before : now;

		// Not as asked, and not as an earlier confirmation's own move left it: the shopper edited.
		if ( theirs !== seen[ 0 ] && theirs !== addressAsMoved ) {
			if ( replaced ) {
				await restoreDestination( ...addressFields( theirs ) );
			}

			throw { status: 0, code: 'woodev_pickup_superseded', message: '' } as PickupFailure;
		}

		adoptDestination( destination, billingIsShipping );
		addressAsMoved = movableAddress( billingIsShipping );
	}

	return { ...snapshot.selection, allowed: true };
}

/** The address fields a confirmation moved to the point's own, or `null` when it moved none. */
function movedDestination( snapshot: PickupSnapshot ): PickupDestination | null {
	const source = snapshot.destination;

	if ( ! isRecord( source ) || Array.isArray( source ) ) {
		return null;
	}

	const destination: PickupDestination = {};

	for ( const key of [ 'address_1', 'postcode' ] as const ) {
		const value = source[ key ];

		if ( typeof value === 'string' && value !== '' ) {
			destination[ key ] = value;
		}
	}

	return Object.keys( destination ).length > 0 ? destination : null;
}

/** The validation-store id of one field's «choose a pickup point» error. */
export function validationId( field: PickupFieldDescriptor ): string {
	return `woodev-pickup-${ field.pluginId }-${ field.fieldId }`;
}

/**
 * Blocks Place Order until a point is chosen. `hidden`: WooCommerce reveals the message when the
 * shopper tries to place the order — any error in the store blocks, shown or not.
 */
export function requirePoint( id: string, message: string ): void {
	const actions = dispatch( VALIDATION_STORE ) as unknown as ValidationActions | undefined;

	actions?.setValidationErrors?.( { [ id ]: { message, hidden: true } } );
}

/** Clears ONE field's own error — never the whole validation store. */
export function releasePoint( id: string ): void {
	const actions = dispatch( VALIDATION_STORE ) as unknown as ValidationActions | undefined;

	actions?.clearValidationError?.( id );
}

/**
 * Says a REVEALED «choose a pickup point» error in the checkout's own notices, at the top of the
 * form — where WooCommerce scrolls to when it refuses to place the order and none of its own fields
 * is invalid. Keyed by the error's id, so saying it again replaces it; not dismissible, so it is
 * there on every further attempt until {@link withdrawPoint}.
 */
export function announcePoint( id: string, message: string ): void {
	const actions = dispatch( NOTICES_STORE ) as unknown as NoticeActions | undefined;

	actions?.createErrorNotice?.( message, { id, context: CHECKOUT_NOTICES, isDismissible: false } );
}

/** Takes ONE field's own notice back — never another notice of the checkout. */
export function withdrawPoint( id: string ): void {
	const actions = dispatch( NOTICES_STORE ) as unknown as NoticeActions | undefined;

	actions?.removeNotice?.( id, CHECKOUT_NOTICES );
}

/** The message to show for `id`, or `''` while there is no error or it is still hidden. */
export function readVisibleError( id: string, registrySelect: typeof select = select ): string {
	const selectors = registrySelect( VALIDATION_STORE ) as unknown as ValidationSelectors | undefined;
	const error = selectors?.getValidationError?.( id );

	return error && error.hidden === false && typeof error.message === 'string' ? error.message : '';
}
