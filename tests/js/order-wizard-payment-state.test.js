/**
 * The pure rules of the payment step ⑤ (#971): option lists, what the status select shows, the
 * totals, the address line, and the pieces of validation / state that step relies on.
 *
 * @see src/shipping-orders-page/order-wizard/payment-state.ts
 */

import {
	addressLine,
	deliveryCost,
	exportOffered,
	orderTotals,
	paidTotalChange,
	paymentOptions,
	personName,
	shownStatus,
	statusOptions,
} from '../../src/shipping-orders-page/order-wizard/payment-state';
import { withPickupCheck } from '../../src/shipping-orders-page/order-wizard/delivery-state';
import { validateAll, validatePayment, validateStep } from '../../src/shipping-orders-page/order-wizard/validation';
import { emptyWizardData, isDirty, newItem } from '../../src/shipping-orders-page/order-wizard/wizard-data';

const COUNTRIES = { RU: 'Россия' };
const STATES = { RU: { МОСКВА: 'Москва' } };

/** A state that passes every step. */
const validState = () => {
	const data = emptyWizardData( 'RU' );

	data.shipping = { ...data.shipping, country: 'RU', city: 'Москва' };
	data.items = [ newItem( { product_id: 12, name: 'Кружка', price: '1000' } ) ];
	data.rest = { ...data.rest, shipping_line: { rate_id: 'a:1', cost: '100' } };

	return data;
};

describe( 'paymentOptions', () => {
	test( 'none first, then the gateways in the shop\'s order', () => {
		expect( paymentOptions( { cod: 'Наложенный платёж', bacs: 'Перевод' }, '', 'Не указан' ) ).toEqual( [
			{ value: '', label: 'Не указан' },
			{ value: 'cod', label: 'Наложенный платёж' },
			{ value: 'bacs', label: 'Перевод' },
		] );
	} );

	test( 'a PHP empty array arrives as [] and an absent list as undefined — both mean «none offered»', () => {
		expect( paymentOptions( [], '', 'Не указан' ) ).toEqual( [ { value: '', label: 'Не указан' } ] );
		expect( paymentOptions( undefined, '', 'Не указан' ) ).toEqual( [ { value: '', label: 'Не указан' } ] );
	} );

	test( 'a method the order carries that the list lacks is added under its own id, once', () => {
		expect( paymentOptions( { cod: 'COD' }, 'bacs', 'Нет' ).map( ( o ) => o.value ) ).toEqual( [ '', 'cod', 'bacs' ] );
		expect( paymentOptions( { cod: 'COD' }, 'cod', 'Нет' ).map( ( o ) => o.value ) ).toEqual( [ '', 'cod' ] );
	} );
} );

describe( 'statusOptions / shownStatus', () => {
	const statuses = { pending: 'Ожидает', processing: 'В обработке', completed: 'Выполнен' };
	const finals = [ 'completed', 'cancelled' ];

	test( 'a create offers every status', () => {
		expect( statusOptions( statuses, finals, 'create', 'pending' ).map( ( o ) => o.value ) ).toEqual( [ 'pending', 'processing', 'completed' ] );
	} );

	test( 'an edit drops the final ones — the validator refuses them as a target', () => {
		expect( statusOptions( statuses, finals, 'edit', 'processing' ).map( ( o ) => o.value ) ).toEqual( [ 'pending', 'processing' ] );
	} );

	test( 'the current status stays even if it is final, so the select can show it', () => {
		expect( statusOptions( statuses, finals, 'edit', 'completed' ).map( ( o ) => o.value ) ).toContain( 'completed' );
	} );

	test( 'an unknown current status is added rather than shown as something else', () => {
		expect( statusOptions( statuses, finals, 'edit', 'wc-custom' ).map( ( o ) => o.value ) ).toContain( 'wc-custom' );
	} );

	test( 'no list at all gives no options (an older bootstrap)', () => {
		expect( statusOptions( undefined, undefined, 'create', '' ) ).toEqual( [] );
	} );

	test( 'a new order untouched reads «pending», an edited one its own, an edit with none reads empty', () => {
		const data = emptyWizardData( 'RU' );

		expect( shownStatus( data, 'create' ) ).toBe( 'pending' );
		expect( shownStatus( data, 'edit' ) ).toBe( '' );
		expect( shownStatus( { ...data, rest: { ...data.rest, status: 'on-hold' } }, 'create' ) ).toBe( 'on-hold' );
	} );
} );

describe( 'totals', () => {
	test( 'items + delivery at the prices set', () => {
		const data = validState();

		data.items = [ newItem( { product_id: 1, name: 'A', price: '10.5' } ), { ...newItem( { product_id: 2, name: 'B', price: '3' } ), quantity: '2' } ];
		data.rest.shipping_line = { cost: '99.5' };

		expect( orderTotals( data ) ).toEqual( { items: 16.5, delivery: 99.5, total: 116 } );
	} );

	test( 'no tariff, or a price that is not a number, counts as 0 — never NaN', () => {
		const data = validState();

		data.rest.shipping_line = null;
		expect( deliveryCost( data ) ).toBe( 0 );

		data.rest.shipping_line = { cost: 'abc' };
		expect( orderTotals( data ).total ).toBe( 1000 );
	} );
} );

describe( 'summary lines', () => {
	test( 'personName joins what there is', () => {
		expect( personName( { first_name: 'Иван', last_name: 'Петров' } ) ).toBe( 'Иван Петров' );
		expect( personName( { first_name: '', last_name: 'Петров' } ) ).toBe( 'Петров' );
		expect( personName( { first_name: '', last_name: '' } ) ).toBe( '' );
	} );

	test( 'addressLine shows names for the country and the region, and skips empty parts', () => {
		const address = { ...emptyWizardData( 'RU' ).shipping, address_1: 'ул Тверская 1', city: 'Москва', state: 'МОСКВА', postcode: '', country: 'RU' };

		expect( addressLine( address, COUNTRIES, STATES ) ).toBe( 'ул Тверская 1, Москва, Москва, Россия' );
	} );

	test( 'a region or country the lists do not know is shown as it is', () => {
		const address = { ...emptyWizardData( 'KZ' ).shipping, city: 'Алматы', state: 'ALA', country: 'KZ' };

		expect( addressLine( address, COUNTRIES, STATES ) ).toBe( 'Алматы, ALA, KZ' );
	} );
} );

describe( 'validation of ⑤ and the last look', () => {
	test( 'a status the lists do not know is refused with the server\'s sentence; an empty one, and an absent list, pass', () => {
		const data = validState();

		expect( validatePayment( { ...data, rest: { ...data.rest, status: 'bogus' } }, { pending: 'Ожидает' } ) ).toEqual( { status: [ 'Такого статуса заказа нет.' ] } );
		expect( validatePayment( { ...data, rest: { ...data.rest, status: 'wc-pending' } }, { pending: 'Ожидает' } ) ).toEqual( {} );
		expect( validatePayment( data, { pending: 'Ожидает' } ) ).toEqual( {} );
		expect( validatePayment( { ...data, rest: { ...data.rest, status: 'bogus' } }, undefined ) ).toEqual( {} );
	} );

	test( 'validateStep runs ⑤\'s check', () => {
		const data = validState();

		expect( validateStep( 4, { ...data, rest: { ...data.rest, status: 'bogus' } }, COUNTRIES, STATES, { pending: 'Ожидает' } ) ).toHaveProperty( 'status' );
	} );

	test( 'validateAll merges every step: a good state is clean, a broken one names each broken field', () => {
		const data = validState();

		expect( validateAll( data, COUNTRIES, STATES, { pending: 'Ожидает' } ) ).toEqual( {} );

		const broken = { ...data, items: [], rest: { ...data.rest, shipping_line: null } };
		const problems = validateAll( broken, COUNTRIES, STATES, { pending: 'Ожидает' } );

		expect( Object.keys( problems ).sort() ).toEqual( [ 'items', 'shipping_line' ] );
	} );
} );

describe( 'withPickupCheck', () => {
	test( 'records the carrier and the weight, and returns the very same object when nothing changed', () => {
		const data = validState();
		const once = withPickupCheck( data, 'cdek', 3250 );

		expect( once.rest.pickup_check ).toEqual( { provider: 'cdek', weight: 3250 } );
		expect( withPickupCheck( once, 'cdek', 3250 ) ).toBe( once );
		expect( withPickupCheck( once, 'cdek', 4000 ).rest.pickup_check ).toEqual( { provider: 'cdek', weight: 4000 } );
	} );

	test( 'it is bookkeeping, not an edit: the close confirmation does not count it', () => {
		const data = validState();

		expect( isDirty( data, withPickupCheck( data, 'cdek', 3250 ) ) ).toBe( false );
	} );
} );

describe( 'paidTotalChange (O14)', () => {
	/** The order as loaded: one mug at 1000 and a courier at 250.5, saved at 1250.50. */
	const loaded = ( patch = {} ) => ( {
		id: 7,
		number: '7',
		status: 'processing',
		status_name: 'В обработке',
		is_paid: true,
		total: '1250.50',
		currency: 'RUB',
		...patch,
	} );

	const state = () => {
		const data = validState();

		data.rest = { ...data.rest, shipping_line: { rate_id: 'a:1', cost: '250.5' } };

		return data;
	};

	const baselineOf = ( data ) => orderTotals( data ).total;

	test( 'nothing changed → nothing to say', () => {
		const data = state();

		expect( paidTotalChange( loaded(), baselineOf( data ), data ) ).toBeNull();
	} );

	test( 'a changed line price on a paid order → was the saved total, now the saved total moved by the change', () => {
		const data = state();
		const baseline = baselineOf( data );
		const edited = { ...data, items: [ { ...data.items[ 0 ], price: '1100' } ] };

		expect( paidTotalChange( loaded(), baseline, edited ) ).toEqual( { was: 1250.5, now: 1350.5 } );
	} );

	test( 'a changed delivery price, a changed quantity and a removed line all count', () => {
		const data = state();
		const baseline = baselineOf( data );

		const dearer = { ...data, rest: { ...data.rest, shipping_line: { ...data.rest.shipping_line, cost: '300' } } };
		const twice = { ...data, items: [ { ...data.items[ 0 ], quantity: '2' } ] };
		const empty = { ...data, items: [] };

		expect( paidTotalChange( loaded(), baseline, dearer ).now ).toBeCloseTo( 1300, 5 );
		expect( paidTotalChange( loaded(), baseline, twice ).now ).toBeCloseTo( 2250.5, 5 );
		expect( paidTotalChange( loaded(), baseline, empty ).now ).toBeCloseTo( 250.5, 5 );
	} );

	test( 'an order that is not paid needs no warning, whatever the edit', () => {
		const data = state();
		const edited = { ...data, items: [ { ...data.items[ 0 ], price: '1100' } ] };

		expect( paidTotalChange( loaded( { is_paid: false } ), baselineOf( data ), edited ) ).toBeNull();
	} );

	test( 'nothing loaded (a create, or the load has not landed) → nothing to compare', () => {
		const data = state();
		const edited = { ...data, items: [ { ...data.items[ 0 ], price: '1100' } ] };

		expect( paidTotalChange( null, 1250.5, edited ) ).toBeNull();
		expect( paidTotalChange( loaded(), null, edited ) ).toBeNull();
		expect( paidTotalChange( loaded(), undefined, edited ) ).toBeNull();
		expect( paidTotalChange( loaded( { total: 'n/a' } ), baselineOf( data ), edited ) ).toBeNull();
	} );

	test( 'taxes inside the saved total are not an edit: it is the change that counts, not the difference from the wizard\'s own sum', () => {
		const data = state();
		// WooCommerce saved 1 500,60 — the wizard cannot know the 250,10 of tax, and the unedited state must stay quiet.
		const taxed = loaded( { total: '1500.60' } );

		expect( paidTotalChange( taxed, baselineOf( data ), data ) ).toBeNull();

		const edited = { ...data, items: [ { ...data.items[ 0 ], price: '1100' } ] };

		expect( paidTotalChange( taxed, baselineOf( data ), edited ) ).toEqual( { was: 1500.6, now: 1600.6 } );
	} );

	test( 'float noise is not a change, one cent is', () => {
		const data = state();
		const baseline = baselineOf( data );
		const noise = { ...data, items: [ { ...data.items[ 0 ], price: String( 1000 + 1e-9 ) } ] };
		const cent = { ...data, items: [ { ...data.items[ 0 ], price: '1000.01' } ] };

		expect( paidTotalChange( loaded(), baseline, noise ) ).toBeNull();
		expect( paidTotalChange( loaded(), baseline, cent ) ).not.toBeNull();
	} );
} );

describe( 'exportOffered (D6, #974)', () => {
	test( 'the box may be ticked only under a status the export is offered in', () => {
		expect( exportOffered( 'processing', [ 'pending', 'on-hold', 'processing' ] ) ).toBe( true );
		expect( exportOffered( 'completed', [ 'pending', 'on-hold', 'processing' ] ) ).toBe( false );
	} );

	test( 'a bootstrap without the list restricts nothing — the server still refuses, with its own reason', () => {
		expect( exportOffered( 'completed', undefined ) ).toBe( true );
	} );
} );
