/**
 * Pure state helpers of the order wizard (#969): the empty state, prefill → state, state →
 * request payload, a picked WooCommerce customer → state, and the small formatters the steps
 * share. No React, no network — so the transformations the server contract depends on are
 * tested on their own.
 *
 * @package woodev-plugin-framework
 */

import type {
	OrderPrefill,
	WizardAddress,
	WizardBilling,
	WizardData,
	WizardItem,
} from './types';

/** The address keys the validator reads, in the order the step shows them. */
export const ADDRESS_KEYS = [
	'first_name',
	'last_name',
	'company',
	'address_1',
	'address_2',
	'city',
	'state',
	'postcode',
	'country',
	'phone',
] as const;

/** The keys that make up WHERE something is delivered (customer identity excluded). */
const PLACE_KEYS = [ 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ] as const;

let itemSequence = 0;

/** A React key for a new line — unique for the page's lifetime. */
export function nextItemKey(): string {
	itemSequence += 1;

	return `line-${ itemSequence }`;
}

export function emptyAddress( country = '' ): WizardAddress {
	return {
		first_name: '',
		last_name: '',
		company: '',
		address_1: '',
		address_2: '',
		city: '',
		state: '',
		postcode: '',
		country,
		phone: '',
	};
}

/** The state a NEW order starts from. */
export function emptyWizardData( defaultCountry = '' ): WizardData {
	return {
		customer: { id: 0, create_account: false, label: '' },
		billing: { ...emptyAddress( defaultCountry ), email: '' },
		shipping: emptyAddress( defaultCountry ),
		billingFollowsShipping: true,
		items: [],
		rest: {
			shipping_line: null,
			pickup_point: null,
			fields: {},
			carrier_fields: {},
			payment_method: '',
			status: '',
		},
		settlementKey: '',
	};
}

const str = ( value: unknown ): string => ( 'string' === typeof value ? value : value == null ? '' : String( value ) );

/** Every address key of a loose object, as strings. */
function readAddress( raw: Partial<WizardAddress> | undefined ): WizardAddress {
	const address = emptyAddress();

	for ( const key of ADDRESS_KEYS ) {
		address[ key ] = str( raw ? raw[ key ] : '' );
	}

	return address;
}

const samePlace = ( a: WizardAddress, b: WizardAddress ): boolean =>
	PLACE_KEYS.every( ( key ) => a[ key ].trim() === b[ key ].trim() );

/** Whether any of the delivery-place fields holds text. */
export function hasPlace( address: WizardAddress ): boolean {
	return PLACE_KEYS.some( ( key ) => '' !== address[ key ].trim() );
}

/**
 * An edit's starting state — the load route's prefill (`Order_Editor::build_prefill()`).
 *
 * An order with no shipping address of its own delivers to its billing one, exactly as the
 * server reads an empty `shipping` block; the wizard then shows that address in step ② and
 * keeps billing tied to it.
 */
export function prefillToData( prefill: OrderPrefill ): WizardData {
	const billing: WizardBilling = { ...readAddress( prefill.billing ), email: str( prefill.billing?.email ) };
	const shipping = readAddress( prefill.shipping );
	const delivers = hasPlace( shipping ) ? shipping : { ...shipping, ...pickPlace( billing ) };

	return {
		customer: {
			id: Number( prefill.customer?.id ) || 0,
			create_account: false,
			// The load route sends the user id only; the order's own billing contact reads as who it is.
			label: Number( prefill.customer?.id ) > 0
				? customerLabel( { id: 0, first_name: billing.first_name, last_name: billing.last_name, email: billing.email } )
				: '',
		},
		billing,
		shipping: delivers,
		billingFollowsShipping: ! hasPlace( shipping ) || samePlace( billing, shipping ),
		items: ( prefill.items || [] ).map( ( line ) => ( {
			key: nextItemKey(),
			item_id: Number( line.item_id ) || 0,
			product_id: Number( line.product_id ) || 0,
			variation_id: Number( line.variation_id ) || 0,
			name: str( line.name ),
			quantity: str( line.quantity ),
			price: str( line.price ),
		} ) ),
		rest: {
			shipping_line: prefill.shipping_line || null,
			pickup_point: prefill.pickup_point || null,
			fields: prefill.fields || {},
			carrier_fields: prefill.carrier_fields || {},
			payment_method: str( prefill.payment_method ),
			status: str( prefill.status ),
		},
		settlementKey: '',
	};
}

function pickPlace( address: WizardAddress ): Pick<WizardAddress, typeof PLACE_KEYS[ number ]> {
	return {
		address_1: address.address_1,
		address_2: address.address_2,
		city: address.city,
		state: address.state,
		postcode: address.postcode,
		country: address.country,
	};
}

/** The customer record `wc/v3/customers/{id}` returns — only what the wizard reads. */
export interface WcCustomerRecord {
	id: number;
	email?: string;
	first_name?: string;
	last_name?: string;
	username?: string;
	billing?: Partial<WizardBilling>;
	shipping?: Partial<WizardAddress>;
}

/** «Имя Фамилия (email)» — what the customer search and the picked chip show. */
export function customerLabel( record: WcCustomerRecord ): string {
	const name = `${ str( record.first_name ) } ${ str( record.last_name ) }`.trim() || str( record.username );
	const email = str( record.email );

	return email && email !== name ? `${ name } (${ email })` : name;
}

/**
 * Applies a picked existing customer: identity into billing, and the address they last used
 * into step ②'s delivery address — their shipping address when it has one, else their billing
 * one (D1: «prefilled from ①'s shipping (else billing) address»). A customer with no saved
 * address leaves the country the wizard already carries.
 */
export function applyCustomer( data: WizardData, record: WcCustomerRecord ): WizardData {
	const billing = readAddress( record.billing );
	const shipping = readAddress( record.shipping );
	const delivery = hasPlace( shipping ) ? shipping : billing;
	const email = str( record.billing?.email ) || str( record.email );

	return {
		...data,
		customer: { id: Number( record.id ) || 0, create_account: false, label: customerLabel( record ) },
		billing: {
			...data.billing,
			first_name: billing.first_name || str( record.first_name ),
			last_name: billing.last_name || str( record.last_name ),
			phone: billing.phone || delivery.phone,
			email,
		},
		shipping: {
			...data.shipping,
			first_name: delivery.first_name,
			last_name: delivery.last_name,
			phone: delivery.phone,
			...( hasPlace( delivery )
				? pickPlace( delivery )
				: { country: data.shipping.country } ),
		},
		billingFollowsShipping: true,
		settlementKey: '',
	};
}

/** Detaches the picked customer — back to a guest, contact fields kept for editing. */
export function clearCustomer( data: WizardData ): WizardData {
	return { ...data, customer: { id: 0, create_account: false, label: '' } };
}

/**
 * The request body (`Order_Payload_Validator`'s transport contract).
 *
 * - `billing`'s ADDRESS follows the delivery address while `billingFollowsShipping`; its
 *   names / email / phone are step ①'s as typed.
 * - `shipping` takes the recipient's names and phone from billing when its own are empty.
 * - `status` is left out when nothing chose one: the server then applies its own default
 *   (`pending` on create, «keep» on update).
 */
export function buildPayload( data: WizardData ): Record<string, unknown> {
	const billing: WizardBilling = data.billingFollowsShipping
		? { ...data.billing, ...pickPlace( data.shipping ) }
		: data.billing;

	const shipping: WizardAddress = {
		...data.shipping,
		first_name: data.shipping.first_name || data.billing.first_name,
		last_name: data.shipping.last_name || data.billing.last_name,
		phone: data.shipping.phone || data.billing.phone,
	};

	const payload: Record<string, unknown> = {
		customer: { id: data.customer.id, create_account: data.customer.create_account },
		billing,
		shipping,
		items: data.items.map( ( line ) => ( {
			...( line.item_id > 0 ? { item_id: line.item_id } : {} ),
			product_id: line.product_id,
			variation_id: line.variation_id,
			quantity: Number( line.quantity ),
			price: line.price.trim(),
		} ) ),
		shipping_line: data.rest.shipping_line,
		pickup_point: data.rest.pickup_point,
		fields: data.rest.fields,
		carrier_fields: data.rest.carrier_fields,
		payment_method: data.rest.payment_method,
	};

	if ( '' !== data.rest.status ) {
		payload.status = data.rest.status;
	}

	return payload;
}

/** Whether two states differ — the unsaved-input check behind the close confirmation (C3). */
export function isDirty( initial: WizardData, current: WizardData ): boolean {
	return JSON.stringify( initial ) !== JSON.stringify( current );
}

/** A line as the items step adds it. */
export function newItem( product: { product_id: number; variation_id?: number; name: string; price: string } ): WizardItem {
	return {
		key: nextItemKey(),
		item_id: 0,
		product_id: product.product_id,
		variation_id: product.variation_id || 0,
		name: product.name,
		quantity: '1',
		price: product.price,
	};
}

/** Sum of `quantity × price` over the lines, `NaN`-safe (an unparseable line counts as 0). */
export function itemsSubtotal( items: WizardItem[] ): number {
	return items.reduce( ( sum, line ) => {
		const quantity = Number( line.quantity );
		const price = Number( line.price );

		return sum + ( Number.isFinite( quantity ) && Number.isFinite( price ) ? quantity * price : 0 );
	}, 0 );
}

/** A money amount with the shop's currency symbol when the bootstrap carries one. */
export function formatMoney( amount: number, symbol = '' ): string {
	const text = new Intl.NumberFormat( 'ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 } ).format( amount );

	return symbol ? `${ text } ${ symbol }` : text;
}

/**
 * The WooCommerce STATE CODE a location record's region name stands for, or '' when it is not
 * one region unambiguously. The record says «Московская» / «Москва»; WooCommerce's Russian
 * states are the upper-cased region names («МОСКОВСКАЯ ОБЛАСТЬ», «МОСКВА») with title-case
 * labels — the mapping is a name match, and a wrong guess would put a wrong region on the
 * order, so an ambiguous name maps to nothing and the manager picks.
 */
export function matchState( states: Record<string, string> | undefined, regionName: string ): string {
	const wanted = regionName.trim().toLowerCase().replace( /ё/g, 'е' );

	if ( ! states || '' === wanted ) {
		return '';
	}

	const norm = ( text: string ) => text.trim().toLowerCase().replace( /ё/g, 'е' );
	const entries = Object.entries( states );
	const exact = entries.filter( ( [ code, label ] ) => norm( code ) === wanted || norm( label ) === wanted );

	if ( 1 === exact.length ) {
		return exact[ 0 ][ 0 ];
	}

	if ( exact.length > 1 ) {
		return '';
	}

	const partial = entries.filter( ( [ , label ] ) => norm( label ).startsWith( wanted ) );

	return 1 === partial.length ? partial[ 0 ][ 0 ] : '';
}
