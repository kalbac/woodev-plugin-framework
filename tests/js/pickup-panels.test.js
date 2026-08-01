/**
 * Tests for pickup-panels.js
 *
 * Covers SP-5 Task 12 (the list panel): closed-by-default, viewport-synced,
 * anchor-relative sorting, the 300-item render cap surfaced in the header
 * count, the empty state, the `listToggle` event a caller uses to size the
 * map's margin, and rule I1 (a missing i18n key renders blank, never a
 * hardcoded Russian default).
 *
 * @see woodev/shipping-method/assets/js/frontend/pickup-panels.js
 */

'use strict';

const Panels = require( '../../woodev/shipping-method/assets/js/frontend/pickup-panels' );

const config = {
	lang: 'ru_RU',
	i18n: { drawerTitle: 'Пункты выдачи в этой области', emptyInView: 'В этой области пунктов выдачи нет' },
};

const group = ( id, lat, lng, name ) => ( {
	key: id, lat, lng, size: 1,
	points: [ { id, name, short_address: name + ' addr', locality: 'Москва' } ],
} );

it( 'starts closed', () => {
	const panels = new Panels( document.createElement( 'div' ), config );
	panels.render();

	expect( panels.root.querySelector( '.woodev-pickup-list' ).classList.contains( 'is-open' ) ).toBe( false );
} );

it( 'sorts by distance from the anchor', () => {
	const panels = new Panels( document.createElement( 'div' ), config );
	panels.render();
	panels.setAnchor( [ 55.75, 37.61 ] );
	panels.setVisible( [ group( 'far', 55.90, 37.61 ), group( 'near', 55.7501, 37.61 ) ] );

	const ids = [ ...panels.root.querySelectorAll( '.woodev-pickup-list__item' ) ]
		.map( ( el ) => el.dataset.groupKey );

	expect( ids ).toEqual( [ 'near', 'far' ] );
} );

it( 'caps the rendered list at 300 items', () => {
	const many = Array.from( { length: 400 }, ( _, i ) => group( 'g' + i, 55.75 + i / 10000, 37.61 ) );
	const panels = new Panels( document.createElement( 'div' ), config );
	panels.render();
	panels.setVisible( many );

	expect( panels.root.querySelectorAll( '.woodev-pickup-list__item' ) ).toHaveLength( 300 );
} );

it( 'shows the empty state when nothing is in view', () => {
	const panels = new Panels( document.createElement( 'div' ), config );
	panels.render();
	panels.setVisible( [] );

	expect( panels.root.querySelector( '.woodev-pickup-list__empty' ).textContent )
		.toBe( 'В этой области пунктов выдачи нет' );
} );

it( 'reports its open state and width so the caller can set the map margin', () => {
	const seen = [];
	const panels = new Panels( document.createElement( 'div' ), config );
	panels.on( 'listToggle', ( e ) => seen.push( e ) );
	panels.render();
	panels.toggleList();

	expect( seen[ 0 ].open ).toBe( true );
	expect( typeof seen[ 0 ].width ).toBe( 'number' );
} );

it( 'renders a blank label rather than a Russian default when an i18n key is missing', () => {
	const panels = new Panels( document.createElement( 'div' ), { lang: 'ru_RU', i18n: {} } );
	panels.render();

	expect( panels.root.querySelector( '.woodev-pickup-list__header' ).textContent ).toBe( '' );
} );

// -----------------------------------------------------------------------
// Extra coverage beyond the plan's own spec — see the task report for which
// mutation each one kills.
// -----------------------------------------------------------------------

it( 'shows the plain in-view count in the header once a viewport has been reported', () => {
	const panels = new Panels( document.createElement( 'div' ), config );
	panels.render();
	panels.setVisible( [ group( 'a', 55.75, 37.61 ), group( 'b', 55.76, 37.61 ) ] );

	expect( panels.root.querySelector( '.woodev-pickup-list__header' ).textContent )
		.toBe( 'Пункты выдачи в этой области (2)' );
} );

it( 'shows a 300+ count, never the raw 400, once the viewport exceeds the render cap', () => {
	const many = Array.from( { length: 400 }, ( _, i ) => group( 'g' + i, 55.75 + i / 10000, 37.61 ) );
	const panels = new Panels( document.createElement( 'div' ), config );
	panels.render();
	panels.setVisible( many );

	expect( panels.root.querySelector( '.woodev-pickup-list__header' ).textContent )
		.toBe( 'Пункты выдачи в этой области (300+)' );
} );

it( 'keeps the caller-supplied order when no anchor is set, rather than reshuffling', () => {
	const panels = new Panels( document.createElement( 'div' ), config );
	panels.render();
	panels.setVisible( [ group( 'b', 55.90, 37.61 ), group( 'a', 55.75, 37.61 ) ] );

	const ids = [ ...panels.root.querySelectorAll( '.woodev-pickup-list__item' ) ]
		.map( ( el ) => el.dataset.groupKey );

	expect( ids ).toEqual( [ 'b', 'a' ] );
} );

it( 'decodes an already-escaped point name in a list row rather than showing the literal entity', () => {
	const panels = new Panels( document.createElement( 'div' ), config );
	panels.render();
	panels.setVisible( [ group( 'k', 55.75, 37.61, 'ПВЗ &quot;Ромашка&quot;' ) ] );

	expect( panels.root.querySelector( '.woodev-pickup-list__name' ).textContent ).toBe( 'ПВЗ "Ромашка"' );
} );

it( 'never executes markup smuggled through an i18n label — the header renders it as plain text', () => {
	const panels = new Panels( document.createElement( 'div' ), {
		lang: 'ru_RU',
		i18n: { drawerTitle: '<img src=x>', emptyInView: '' },
	} );
	panels.render();

	const header = panels.root.querySelector( '.woodev-pickup-list__header' );

	expect( header.querySelector( 'img' ) ).toBeNull();
	expect( header.textContent ).toBe( '<img src=x>' );
} );

it( 'toggles closed again on a second call, with open:false in the event', () => {
	const seen = [];
	const panels = new Panels( document.createElement( 'div' ), config );
	panels.on( 'listToggle', ( e ) => seen.push( e ) );
	panels.render();
	panels.toggleList();
	panels.toggleList();

	expect( seen[ 1 ].open ).toBe( false );
	expect( panels.root.querySelector( '.woodev-pickup-list' ).classList.contains( 'is-open' ) ).toBe( false );
} );
