/**
 * Pure state helpers of the delivery step ④ (#710 D1 / D2 / D3, increment I5a of card #970):
 * what the rates request is built from, how a chosen tariff becomes the payload's
 * `shipping_line`, how the editable delivery price relates to the carrier's own, and what the
 * pickup routes are told about the order. No React, no network — the rules the server contract
 * depends on are tested on their own, like `wizard-data.ts`.
 *
 * @package woodev-plugin-framework
 */

import { isFieldVisible } from '../../components/validate';
import type { CarrierField, RateGroup, RateOption, RatesResponse, WizardData, WizardRest } from './types';

/** A rate's `meta` reduced to what the payload accepts: scalars only (`Order_Payload_Validator::scalar_map()`). */
export function scalarMeta( meta: unknown ): Record<string, string | number | boolean> {
	const out: Record<string, string | number | boolean> = {};

	if ( ! meta || 'object' !== typeof meta ) {
		return out;
	}

	for ( const [ key, value ] of Object.entries( meta as Record<string, unknown> ) ) {
		if ( 'string' === typeof value || 'number' === typeof value || 'boolean' === typeof value ) {
			out[ key ] = value;
		}
	}

	return out;
}

/**
 * The body of `POST /shipping/orders/rates`, or `null` while the wizard cannot ask yet (no
 * country, or no line that is a real product). Steps ①–③ have already been checked when the
 * manager reaches ④, so a line that fails here is a state the step never expects — it is left
 * out rather than sent, and the server's own 422 (`unknown product`) stays the last word.
 *
 * The destination carries WooCommerce STATE CODES (step ② holds a code), the lines carry the
 * EDITED price (O8), and the settlement record the manager picked travels whole as `location`
 * — see {@link WizardData.settlementRecord}.
 *
 * @param {WizardData} data the wizard state.
 * @return {Record<string, unknown>|null} the request body.
 */
export function buildRatesRequest( data: WizardData ): Record<string, unknown> | null {
	const items = data.items
		.filter( ( line ) => line.product_id > 0 && Number.isInteger( Number( line.quantity ) ) && Number( line.quantity ) >= 1 )
		.map( ( line ) => {
			const price = Number( line.price );

			return {
				product_id: line.product_id,
				variation_id: line.variation_id,
				quantity: Number( line.quantity ),
				...( line.price.trim() !== '' && Number.isFinite( price ) && price >= 0 ? { price } : {} ),
			};
		} );

	const { country, state, city, postcode, address_1, address_2 } = data.shipping;

	if ( 0 === items.length || '' === country.trim() ) {
		return null;
	}

	const request: Record<string, unknown> = {
		items,
		destination: { country, state, city, postcode, address: address_1, address_2 },
		customer_id: data.customer.id,
	};

	if ( data.settlementRecord ) {
		request.location = data.settlementRecord;
	}

	return request;
}

/** Same request ⇒ same key: the step asks again only when this changes. */
export function ratesRequestKey( request: Record<string, unknown> | null ): string {
	return null === request ? '' : JSON.stringify( request );
}

/** The rate id (`method:instance`) the wizard state currently holds, or ''. */
export function chosenRateId( rest: WizardRest ): string {
	const line = rest.shipping_line;

	return line && 'string' === typeof line.rate_id ? line.rate_id : '';
}

/** Finds a tariff by its rate id across all carriers. */
export function findRate( response: RatesResponse | null, rateId: string ): { group: RateGroup; rate: RateOption } | null {
	if ( ! response || '' === rateId ) {
		return null;
	}

	for ( const group of response.providers ) {
		const rate = group.rates.find( ( candidate ) => candidate.id === rateId );

		if ( rate ) {
			return { group, rate };
		}
	}

	return null;
}

/** The chosen rate as the payload's `shipping_line`, priced at `cost`. */
function toShippingLine( rate: RateOption, cost: string ): Record<string, unknown> {
	return {
		method_id: rate.method_id,
		instance_id: rate.instance_id,
		rate_id: rate.id,
		label: rate.label,
		cost,
		meta: scalarMeta( rate.meta ),
	};
}

/** A price as the text a field holds: no trailing zeros, no exponent. */
const asText = ( amount: number ): string => String( Number( amount.toFixed( 4 ) ) );

/**
 * Picks a tariff. The price starts at the carrier's own (the manager may then override it, O8);
 * a different tariff drops the pickup point, which belonged to the previous one — the same rule
 * the server applies to a stale point (`check_pickup_point()`, #745). Choosing the tariff that
 * is already chosen changes nothing, so a re-click does not throw away the manager's price.
 *
 * @param {WizardData} data the wizard state.
 * @param {RateOption} rate the tariff.
 * @return {WizardData} the new state.
 */
export function chooseRate( data: WizardData, rate: RateOption ): WizardData {
	if ( chosenRateId( data.rest ) === rate.id ) {
		return data;
	}

	const cost = asText( rate.cost );

	return {
		...data,
		rest: {
			...data.rest,
			shipping_line: toShippingLine( rate, cost ),
			pickup_point: null,
			rate_cost: cost,
			rate_is_pickup: rate.is_pickup,
			// The carrier's fields belong to the tariff: another tariff asks for its own (D7).
			carrier_fields: {},
			carrier_schema: carrierFieldsOf( rate ),
		},
	};
}

/** Whether the price in the line is one the manager typed over the carrier's own. */
export function isCostOverridden( rest: WizardRest ): boolean {
	const line = rest.shipping_line;

	if ( ! line || '' === rest.rate_cost ) {
		return false;
	}

	const typed = String( line.cost ?? '' ).trim();

	return '' !== typed && Number( typed ) !== Number( rest.rate_cost );
}

/** The manager types a delivery price (O8). Kept as text while typing, like an item's price. */
export function setDeliveryCost( data: WizardData, cost: string ): WizardData {
	if ( ! data.rest.shipping_line ) {
		return data;
	}

	return { ...data, rest: { ...data.rest, shipping_line: { ...data.rest.shipping_line, cost } } };
}

/** Back to the carrier's own price. */
export function resetDeliveryCost( data: WizardData ): WizardData {
	if ( ! data.rest.shipping_line || '' === data.rest.rate_cost ) {
		return data;
	}

	return setDeliveryCost( data, data.rest.rate_cost );
}

/**
 * Whether the tariff the state holds is no longer among those the server just offered. The
 * step says so and asks for a new choice — a tariff the destination or the package no longer
 * supports must never ride into the order silently.
 */
export function isChosenRateGone( data: WizardData, response: RatesResponse ): boolean {
	const id = chosenRateId( data.rest );

	return '' !== id && null === findRate( response, id );
}

/**
 * Reconciles the state with a fresh rates answer.
 *
 * - The chosen tariff is gone → the line and its point are dropped (see {@link isChosenRateGone}).
 * - It is still offered → its label, meta and pickup flag follow the fresh answer. The price
 *   follows it too, unless the manager typed their own: the typed price is theirs (O8) and is
 *   never overwritten by a re-quote. A line loaded for edit has no reference price yet
 *   (`rate_cost` is '' until now); its saved price is kept, and the reference is set so
 *   «изменено» reads correctly from then on.
 *
 * Returns the very same object when nothing changes, so it can be applied on every answer
 * without re-rendering the wizard for nothing.
 *
 * @param {WizardData}    data     the wizard state.
 * @param {RatesResponse} response the fresh answer.
 * @return {WizardData} the reconciled state.
 */
export function applyRates( data: WizardData, response: RatesResponse ): WizardData {
	const id = chosenRateId( data.rest );

	if ( '' === id ) {
		return data;
	}

	const found = findRate( response, id );

	if ( ! found ) {
		return {
			...data,
			rest: { ...data.rest, shipping_line: null, pickup_point: null, rate_cost: '', rate_is_pickup: false, carrier_fields: {}, carrier_schema: [] },
		};
	}

	const { rate } = found;
	const fresh = asText( rate.cost );
	const overridden = isCostOverridden( data.rest );
	const cost = overridden || '' === data.rest.rate_cost ? String( data.rest.shipping_line?.cost ?? fresh ) : fresh;
	const line = toShippingLine( rate, cost );
	const schema = carrierFieldsOf( rate );
	const carrierFields = keepDeclared( data.rest.carrier_fields, schema );
	const same =
		JSON.stringify( line ) === JSON.stringify( data.rest.shipping_line ) &&
		data.rest.rate_cost === fresh &&
		data.rest.rate_is_pickup === rate.is_pickup &&
		JSON.stringify( schema ) === JSON.stringify( data.rest.carrier_schema ) &&
		Object.keys( carrierFields ).length === Object.keys( data.rest.carrier_fields ).length;

	if ( same ) {
		return data;
	}

	return {
		...data,
		rest: {
			...data.rest,
			shipping_line: line,
			rate_cost: fresh,
			rate_is_pickup: rate.is_pickup,
			// A point belongs to a pickup tariff only.
			pickup_point: rate.is_pickup ? data.rest.pickup_point : null,
			// The definitions follow the fresh answer; what the manager (or a saved order) already holds for a field
			// the tariff still asks for stays, and a value of a field it no longer asks for goes.
			carrier_fields: carrierFields,
			carrier_schema: schema,
		},
	};
}

/** The fields the carrier asks for under a tariff (D7); none when it declares none. */
export function carrierFieldsOf( rate: RateOption ): CarrierField[] {
	return Array.isArray( rate.order_fields ) ? rate.order_fields : [];
}

/** The values kept for the declared fields only. */
function keepDeclared( values: Record<string, unknown>, schema: CarrierField[] ): Record<string, unknown> {
	const kept: Record<string, unknown> = {};

	for ( const field of schema ) {
		if ( Object.prototype.hasOwnProperty.call( values, field.id ) ) {
			kept[ field.id ] = values[ field.id ];
		}
	}

	return kept;
}

/**
 * A carrier field's value as the wizard shows and checks it: what the manager set, else the default the
 * carrier declared. Nothing is written into the state for an untouched field — the server applies the same
 * default to a field the request leaves out — so merely looking at a tariff never reads as an edit.
 */
export function carrierFieldValue( field: CarrierField, values: Record<string, unknown> ): unknown {
	return Object.prototype.hasOwnProperty.call( values, field.id ) ? values[ field.id ] : field.value;
}

/** The effective value of every declared field, by id — what `show_if` conditions are judged against. */
export function effectiveCarrierValues( schema: CarrierField[], values: Record<string, unknown> ): Record<string, unknown> {
	const effective: Record<string, unknown> = {};

	for ( const field of schema ) {
		effective[ field.id ] = carrierFieldValue( field, values );
	}

	return effective;
}

/** The fields to show now: those whose `show_if` holds for the values as they stand. */
export function visibleCarrierFields( schema: CarrierField[], values: Record<string, unknown> ): CarrierField[] {
	const effective = effectiveCarrierValues( schema, values );

	return schema.filter( ( field ) => isFieldVisible( field, effective ) );
}

/** The manager sets one carrier field. */
export function setCarrierField( data: WizardData, id: string, value: unknown ): WizardData {
	return { ...data, rest: { ...data.rest, carrier_fields: { ...data.rest.carrier_fields, [ id ]: value } } };
}

/** The point the manager picked in the picker (or typed by code): kept as `{ id, name, address }`. */
export function setPickupPoint( data: WizardData, point: { id: string; name?: string; address?: string } | null ): WizardData {
	return {
		...data,
		rest: {
			...data.rest,
			pickup_point: point && '' !== point.id
				? { id: point.id, ...( point.name ? { name: point.name } : {} ), ...( point.address ? { address: point.address } : {} ) }
				: null,
		},
	};
}

/**
 * Remembers which carrier's points route and which package weight the chosen pickup point was
 * picked against, so step ⑤ can ask whether the point still suits the payment method chosen after
 * it (D3). Returns the very same object when nothing changes.
 *
 * @param {WizardData} data     the wizard state.
 * @param {string}     provider the carrier whose picker config holds the points route.
 * @param {number}     weight   the package weight in grams the rates answer reported.
 * @return {WizardData} the new state.
 */
export function withPickupCheck( data: WizardData, provider: string, weight: number ): WizardData {
	const current = data.rest.pickup_check;

	if ( current && current.provider === provider && current.weight === weight ) {
		return data;
	}

	return { ...data, rest: { ...data.rest, pickup_check: { provider, weight } } };
}

/** The id of the point held in the state, or ''. */
export function pickedPointId( rest: WizardRest ): string {
	const point = rest.pickup_point;

	return point && ( 'string' === typeof point.id || 'number' === typeof point.id ) ? String( point.id ) : '';
}

/**
 * What the admin pickup routes are told besides the query (`Pickup_Controller::register_admin_routes()`):
 * the order weight in grams, the payment method if step ⑤ already has one (empty = «not chosen
 * yet», so nothing is refused on cash-on-delivery, and ⑤ re-validates), and the destination
 * record. Empty values are left out — an absent key reads as absent server-side.
 */
export function pickupContext( data: WizardData, weightGrams: number ): Record<string, unknown> {
	return {
		weight: weightGrams,
		...( data.rest.payment_method ? { payment_method: data.rest.payment_method } : {} ),
		...( data.settlementRecord ? { location: data.settlementRecord } : {} ),
	};
}

/**
 * Point fields arrive `esc_html()`-escaped (`Pickup_Point::to_browser_array()`) and are written
 * into `innerHTML` by the storefront panels; React text would show `&quot;` literally, so the
 * step decodes them for its own display.
 */
export function decodeEntities( value: string ): string {
	if ( ! value || ! value.includes( '&' ) ) {
		return value;
	}

	const area = document.createElement( 'textarea' );

	area.innerHTML = value;

	return area.value;
}
