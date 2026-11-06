/**
 * Tests for location-select-modes.js — the city-mode renderers (range-shipping city mode spec §4.2):
 * `closed-select` (several listed places: a plain select) and `closed-text` (one place: plain text).
 *
 * @see woodev/shipping-method/assets/js/frontend/location-select-modes.js
 */

'use strict';

const { attachClosedSelect, attachClosedText } = require( '../../woodev/shipping-method/assets/js/frontend/location-select-modes.js' );

const place = ( key, value, label ) => ( { key, value, label, level: 'settlement', record: { key, label, level: 'settlement', country: 'RU' } } );
const MOSCOW = place( 'moscow', 'Москва', 'г Москва' );
const KHIMKI = place( 'khimki', 'Химки', 'г Химки' );

function install( value = '' ) {
	document.body.innerHTML = `<p id="row"><span class="woocommerce-input-wrapper"><input type="text" id="billing_city" name="billing_city" class="input-text" value="${ value }" /></span></p>`;

	return document.getElementById( 'billing_city' );
}

const options = ( places, onSelect = jest.fn() ) => ( {
	closedList: () => places,
	onSelect,
	location: { i18n: { placeholder: 'Выбрать…' } },
} );

describe( 'attachClosedSelect', () => {
	it( 'replaces the input by a select with the same id, name and class and one option per place', () => {
		const api = attachClosedSelect( install(), options( [ MOSCOW, KHIMKI ] ) );
		const select = document.getElementById( 'billing_city' );

		expect( api.el ).toBe( select );
		expect( select.tagName ).toBe( 'SELECT' );
		expect( select.name ).toBe( 'billing_city' );
		expect( select.className ).toBe( 'input-text' );
		expect( Array.from( select.options ).map( ( option ) => [ option.value, option.textContent ] ) ).toEqual( [
			[ '', 'Выбрать…' ],
			[ 'Москва', 'г Москва' ],
			[ 'Химки', 'г Химки' ],
		] );
		expect( select.value ).toBe( '' );
		expect( document.querySelectorAll( '#billing_city' ) ).toHaveLength( 1 );
	} );

	it( 'hands the picked place\'s record to onSelect once per pick, and again after reset()', () => {
		const onSelect = jest.fn();
		const api = attachClosedSelect( install(), options( [ MOSCOW, KHIMKI ], onSelect ) );
		const select = api.el;
		const choose = ( index ) => {
			select.selectedIndex = index;
			select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		};

		choose( 2 );
		choose( 2 );
		expect( onSelect ).toHaveBeenCalledTimes( 1 );
		expect( onSelect ).toHaveBeenCalledWith( { record: KHIMKI.record } );

		api.reset();
		choose( 2 );
		expect( onSelect ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'does not announce the blank option', () => {
		const onSelect = jest.fn();
		const api = attachClosedSelect( install(), options( [ MOSCOW, KHIMKI ], onSelect ) );

		api.el.selectedIndex = 0;
		api.el.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect( onSelect ).not.toHaveBeenCalled();
	} );

	it( 'restores the original input in place on detach', () => {
		const input = install( 'Химки' );
		const api = attachClosedSelect( input, options( [ MOSCOW, KHIMKI ] ) );

		expect( api.el.value ).toBe( 'Химки' );

		api.detach();

		expect( document.getElementById( 'billing_city' ) ).toBe( input );
		expect( input.parentNode.className ).toBe( 'woocommerce-input-wrapper' );
	} );

	it( 'declines without places, or on something that is not an input', () => {
		expect( attachClosedSelect( install(), options( null ) ) ).toBeNull();
		expect( attachClosedSelect( document.createElement( 'select' ), options( [ MOSCOW ] ) ) ).toBeNull();
	} );
} );

describe( 'attachClosedText', () => {
	it( 'keeps the input as a hidden field carrying the place and shows the place as text', () => {
		const input = install( 'Тула' );

		attachClosedText( input, options( [ MOSCOW ] ) );

		const text = document.querySelector( '.woodev-city-mode__place' );

		expect( input.type ).toBe( 'hidden' );
		expect( input.value ).toBe( 'Москва' );
		expect( text.tagName ).toBe( 'STRONG' );
		expect( text.textContent ).toBe( 'Москва' );
	} );

	it( 'restores the input on detach', () => {
		const input = install();
		const api = attachClosedText( input, options( [ MOSCOW ] ) );

		api.detach();

		expect( input.type ).toBe( 'text' );
		expect( document.querySelector( '.woodev-city-mode__place' ) ).toBeNull();
	} );

	it( 'declines when there is not exactly one place', () => {
		expect( attachClosedText( install(), options( [ MOSCOW, KHIMKI ] ) ) ).toBeNull();
		expect( attachClosedText( install(), options( null ) ) ).toBeNull();
	} );
} );
