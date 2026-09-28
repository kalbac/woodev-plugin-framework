/**
 * Pure helpers of the payment step ⑤ (#710 D1 / O7 / O8, increment I5b of card #971): the option
 * lists for the two selects, the totals the manager sees, and the readable lines of the summary.
 * No React, no network — tested on their own, like `delivery-state.ts`.
 *
 * @package woodev-plugin-framework
 */

import type { PrefillOrder, WizardAddress, WizardData } from './types';
import { itemsSubtotal } from './wizard-data';

export interface SelectOption {
	value: string;
	label: string;
}

/**
 * The payment-method options: «not specified» first, then the shop's enabled gateways. An order
 * loaded for edit may carry a gateway the shop has since switched off — it is added under its own
 * id so the select shows what the order really has instead of silently showing «not specified»
 * and dropping it on save.
 *
 * @param {Record<string,string>|undefined} methods gateway id → title (the bootstrap; may arrive as `[]`).
 * @param {string}                          current the payment method the state holds.
 * @param {string}                          none    the label of the empty option.
 * @return {SelectOption[]} the options.
 */
export function paymentOptions( methods: Record<string, string> | undefined, current: string, none: string ): SelectOption[] {
	const options: SelectOption[] = [ { value: '', label: none } ];
	const known = Object.entries( methods || {} );

	for ( const [ id, title ] of known ) {
		options.push( { value: id, label: title || id } );
	}

	if ( '' !== current && ! known.some( ( [ id ] ) => id === current ) ) {
		options.push( { value: current, label: current } );
	}

	return options;
}

/**
 * The status options. On update the validator refuses a final status as a target
 * (`Order_Payload_Validator::check_status()`), so those are not offered; the order's own current
 * status is always present so the select can show it.
 *
 * @param {Record<string,string>|undefined} statuses slug (no `wc-`) → name.
 * @param {string[]|undefined}              finals   the slugs an edit may not move an order into.
 * @param {'create'|'edit'}                 mode     the wizard's mode.
 * @param {string}                          current  the status the state holds ('' = the server's default / «keep»).
 * @return {SelectOption[]} the options.
 */
export function statusOptions(
	statuses: Record<string, string> | undefined,
	finals: string[] | undefined,
	mode: 'create' | 'edit',
	current: string
): SelectOption[] {
	const closed = 'edit' === mode ? finals || [] : [];
	const options = Object.entries( statuses || {} )
		.filter( ( [ slug ] ) => slug === current || ! closed.includes( slug ) )
		.map( ( [ slug, name ] ) => ( { value: slug, label: name || slug } ) );

	if ( '' !== current && ! options.some( ( option ) => option.value === current ) ) {
		options.push( { value: current, label: current } );
	}

	return options;
}

/**
 * What the status select shows. A new order sent with no status is created `pending` by the server
 * (`Order_Editor::create()`), so that is what an untouched select honestly reads; an edited order
 * carries its own status from the prefill.
 */
export function shownStatus( data: WizardData, mode: 'create' | 'edit' ): string {
	return '' !== data.rest.status ? data.rest.status : 'create' === mode ? 'pending' : '';
}

export interface Totals {
	items: number;
	delivery: number;
	total: number;
}

/** The delivery price the manager sees, `NaN`-safe: a price that is not a number counts as 0. */
export function deliveryCost( data: WizardData ): number {
	const cost = Number( data.rest.shipping_line?.cost );

	return Number.isFinite( cost ) ? cost : 0;
}

/**
 * Items + delivery, at the prices the manager set (O8). WooCommerce adds taxes when it computes the
 * order's own totals (`calculate_totals()`), which the wizard cannot know — see `taxesEnabled`.
 *
 * @param {WizardData} data the wizard state.
 * @return {Totals} the three amounts.
 */
export function orderTotals( data: WizardData ): Totals {
	const items = itemsSubtotal( data.items );
	const delivery = deliveryCost( data );

	return { items, delivery, total: items + delivery };
}

export interface PaidTotalChange {
	/** The total the order has now (saved). */
	was: number;
	/** What it comes to with the edits: the saved total moved by what the manager changed. */
	now: number;
}

/**
 * O14: an already PAID order whose total the edit changes. `null` when the order is not paid, was
 * not loaded, or the edit leaves the total where it was.
 *
 * The change is measured on the wizard's own arithmetic — items + delivery now against the same
 * sum for the order as loaded (`baseline`) — and applied to the SAVED total, so WooCommerce's taxes
 * (which the wizard cannot compute) stay inside «было» and never trigger the warning on their own.
 * A difference that rounds to the same cent is arithmetic noise, not an edit.
 *
 * @param {PrefillOrder|null} order    the loaded order.
 * @param {number|null}       baseline the wizard's total for it as loaded.
 * @param {WizardData}        data     the wizard state now.
 * @return {PaidTotalChange|null} the change to warn about.
 */
export function paidTotalChange( order: PrefillOrder | null, baseline: number | null | undefined, data: WizardData ): PaidTotalChange | null {
	if ( ! order || ! order.is_paid || null === baseline || undefined === baseline ) {
		return null;
	}

	const saved = Number( order.total );

	if ( ! Number.isFinite( saved ) ) {
		return null;
	}

	const delta = orderTotals( data ).total - baseline;

	if ( Math.abs( delta ) < 0.005 ) {
		return null;
	}

	return { was: saved, now: saved + delta };
}

/** «Имя Фамилия» of an address, '' when both are empty. */
export function personName( address: Pick<WizardAddress, 'first_name' | 'last_name'> ): string {
	return `${ address.first_name } ${ address.last_name }`.trim();
}

/**
 * The delivery address as one line: street, city, region, postcode, country — empty parts left
 * out. A region is shown by its NAME when the country's list has it, by the raw value otherwise.
 *
 * @param {WizardAddress}                            address   the delivery address.
 * @param {Record<string,string>}                    countries country code → name.
 * @param {Record<string,Record<string,string>>}     states    country → { state code → name }.
 * @return {string} the line.
 */
export function addressLine(
	address: WizardAddress,
	countries: Record<string, string>,
	states: Record<string, Record<string, string>>
): string {
	const region = ( states[ address.country ] || {} )[ address.state ] || address.state;

	return [
		address.address_1,
		address.address_2,
		address.city,
		region,
		address.postcode,
		countries[ address.country ] || address.country,
	]
		.map( ( part ) => part.trim() )
		.filter( Boolean )
		.join( ', ' );
}
