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

import { dispatch, select } from '@wordpress/data';
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
import { adoptDestination, CART_STORE } from './wc-stores';
import { wcRuntime } from './wc-runtime';

export const PAYMENT_STORE = 'wc/store/payment';
export const VALIDATION_STORE = 'wc/store/validation';

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
 * Confirms `pointId` for `field` through the Store API (`cart/extensions`, operator decision
 * D-3 B) and answers in the classic confirmation's own shape, which the map session applies.
 *
 * - RESOLVES with the server's verdict: the snapshot's `selection` once the server kept the point,
 *   or `{ allowed: false, reason }` when it REFUSED — the reason is the server's own customer-safe
 *   message, shown on the point's card.
 * - REJECTS when there is no verdict: the request failed, the quota was hit (429), or the reply
 *   carries no confirmation for this point.
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
 * TWO GUARDS AGAINST A LATE ANSWER (SP-11 C-3, #1090):
 *
 * - Commands leave ONE AT A TIME, in the order they were asked. The cart store takes whichever
 *   reply arrives last, so two confirmations in flight together could leave the button showing
 *   the point of the one the server processed first.
 * - `isCurrent()` is asked when the reply lands: whether the shopper is still on the rate the
 *   point was asked for. A reply that lands after they left it REJECTS (`…_superseded`) and moves
 *   no address — the form must not take a pickup point's street for a courier order. The caller
 *   answers from its own record, never from the cart store: the late reply has just overwritten
 *   the store's selected rate with the one the server saw (`receiveCart()`).
 */
export function confirmPoint(
	namespace: string,
	field: PickupFieldDescriptor,
	pointId: string,
	billingIsShipping = false,
	isCurrent: () => boolean = () => true
): Promise< PickupSelectionResult > {
	const send = (): Promise< PickupSelectionResult > =>
		sendConfirmation( namespace, field, pointId, billingIsShipping, isCurrent );
	const result = confirmationInFlight ? confirmationInFlight.then( send ) : send();
	const settled: Promise< void > = result
		.then(
			() => undefined,
			() => undefined
		)
		.then( () => {
			if ( confirmationInFlight === settled ) {
				confirmationInFlight = null;
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
	isCurrent: () => boolean
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

	if ( ! snapshot || snapshot.point_id !== pointId ) {
		throw { status: 0, code: 'woodev_pickup_not_confirmed', message: '' } as PickupFailure;
	}

	if ( ! isCurrent() ) {
		throw { status: 0, code: 'woodev_pickup_superseded', message: '' } as PickupFailure;
	}

	const destination = movedDestination( snapshot );

	if ( destination ) {
		adoptDestination( destination, billingIsShipping );
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

/** The message to show for `id`, or `''` while there is no error or it is still hidden. */
export function readVisibleError( id: string, registrySelect: typeof select = select ): string {
	const selectors = registrySelect( VALIDATION_STORE ) as unknown as ValidationSelectors | undefined;
	const error = selectors?.getValidationError?.( id );

	return error && error.hidden === false && typeof error.message === 'string' ? error.message : '';
}
