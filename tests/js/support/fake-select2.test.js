/**
 * Pins `tests/js/support/fake-select2.js`'s own `pick()`/`open()`/`close()` dispatch order
 * against the MEASURED rig order (issue #525; see that file's own docblock and
 * `docs-internal/gotchas/select2-close-fires-before-select2-select.md`):
 *
 *   select2:opening -> jquery change -> select2:closing -> select2:close -> select2:select
 *
 * Nothing enforced this before. Flipping `pick()`'s own `close()`/`select` trigger order left
 * every test in `location-cascade.test.js`/`location-select-modes.test.js` green (issue #525) —
 * because a consumer built on `recordAbandonCandidate()`/`resolveAndSelect()`'s synchronous
 * clear reacts correctly to EITHER order, so only THIS fixture reordering itself, not the
 * consuming suites, can ever catch a regression here. This file is that direct pin.
 *
 * @see tests/js/support/fake-select2.js
 * @see docs-internal/gotchas/select2-close-fires-before-select2-select.md
 */

'use strict';

const { installFakeSelect2 } = require( './fake-select2.js' );

beforeEach( () => {
	document.body.innerHTML = '<select id="city"><option value="a">A</option></select>';
	delete window.jQuery;
	delete global.jQuery;
	delete global.$;

	global.jQuery = require( 'jquery' );
	global.$ = global.jQuery;
	window.jQuery = global.jQuery;
} );

describe( 'fake-select2 — MEASURED event order (issue #525)', () => {
	it( 'open() dispatches select2:opening then select2:open, in that order', () => {
		const instances = installFakeSelect2( window.jQuery );
		const $select = window.jQuery( '#city' );
		const order = [];

		[ 'select2:opening', 'select2:open' ].forEach( function( name ) {
			$select.on( name, function() {
				order.push( name );
			} );
		} );

		$select.select2( { ajax: { transport: jest.fn() } } );
		instances[ 0 ].open();

		expect( order ).toEqual( [ 'select2:opening', 'select2:open' ] );
	} );

	it( 'close() (no pick) dispatches select2:closing then select2:close, in that order', () => {
		const instances = installFakeSelect2( window.jQuery );
		const $select = window.jQuery( '#city' );
		const order = [];

		[ 'select2:closing', 'select2:close' ].forEach( function( name ) {
			$select.on( name, function() {
				order.push( name );
			} );
		} );

		$select.select2( { ajax: { transport: jest.fn() } } );
		instances[ 0 ].open();
		instances[ 0 ].close();

		expect( order ).toEqual( [ 'select2:closing', 'select2:close' ] );
	} );

	it( 'pick() dispatches change, select2:closing, select2:close, select2:select — in EXACTLY that order, never select2:select before select2:close', () => {
		const instances = installFakeSelect2( window.jQuery );
		const $select = window.jQuery( '#city' );
		const order = [];

		[ 'select2:closing', 'select2:close', 'select2:select' ].forEach( function( name ) {
			$select.on( name, function() {
				order.push( name );
			} );
		} );
		$select.on( 'change', function() {
			order.push( 'change' );
		} );

		$select.select2( { ajax: { transport: jest.fn() } } );
		instances[ 0 ].open();
		instances[ 0 ].pick( { id: 'a', text: 'A', key: 'k1' } );

		expect( order ).toEqual( [ 'change', 'select2:closing', 'select2:close', 'select2:select' ] );
	} );
} );
