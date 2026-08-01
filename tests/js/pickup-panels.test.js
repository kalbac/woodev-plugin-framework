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

// -----------------------------------------------------------------------
// Task 13: the point card, with services and CTA states
// -----------------------------------------------------------------------

function mount( cfg ) {
	const panels = new Panels( document.createElement( 'div' ), cfg );
	panels.render();

	return panels;
}

const cardConfig = { lang: 'ru_RU', i18n: {
	select: 'Забрать здесь', continueCheckout: 'Продолжить оформление заказа',
	services: 'Услуги', paymentMethods: 'Способы оплаты', howToGet: 'Как добраться',
	phone: 'Телефон', workTime: 'Часы работы', maxWeight: 'Максимальный вес', blocked: 'Недоступен',
} };

const point = ( over ) => Object.assign( {
	id: 'p1', name: 'ПВЗ «Магнит»', address: 'Москва, Ленина 5', short_address: 'Ленина, 5',
	postal_code: '101000', phone: '', instruction: '', work_time: '', max_weight: null,
	payment_methods: [], services: [], type: { code: 'pvz', label: 'ПВЗ' },
	selectable: { allowed: true, reason: null },
}, over );

it( 'renders services as chips', () => {
	const panels = mount( cardConfig );
	panels.openCard( { key: 'k', size: 1, points: [ point( { services: [ 'Примерка', 'Частичный выкуп' ] } ) ] } );

	expect( [ ...panels.root.querySelectorAll( '.woodev-pickup-card__service' ) ].map( ( n ) => n.textContent ) )
		.toEqual( [ 'Примерка', 'Частичный выкуп' ] );
} );

it( 'omits the services section entirely when there are none', () => {
	const panels = mount( cardConfig );
	panels.openCard( { key: 'k', size: 1, points: [ point() ] } );

	expect( panels.root.querySelector( '.woodev-pickup-card__services' ) ).toBeNull();
} );

it( 'disables the CTA and shows the reason when the point is not selectable', () => {
	const panels = mount( cardConfig );
	panels.openCard( { key: 'k', size: 1, points: [
		point( { selectable: { allowed: false, reason: 'Оплата при получении недоступна' } } ) ] } );

	expect( panels.root.querySelector( '.woodev-pickup-card__cta' ).disabled ).toBe( true );
	expect( panels.root.querySelector( '.woodev-pickup-card__warning' ).textContent )
		.toBe( 'Оплата при получении недоступна' );
} );

it( 'switches the CTA when this point is already the selected one', () => {
	const panels = mount( cardConfig );
	panels.setSelectedId( 'p1' );
	panels.openCard( { key: 'k', size: 1, points: [ point() ] } );

	expect( panels.root.querySelector( '.woodev-pickup-card__cta' ).textContent )
		.toBe( 'Продолжить оформление заказа' );
} );

it( 'emits select with the point when the CTA is pressed', () => {
	const seen = [];
	const panels = mount( cardConfig );
	panels.on( 'select', ( p ) => seen.push( p ) );
	panels.openCard( { key: 'k', size: 1, points: [ point() ] } );
	panels.root.querySelector( '.woodev-pickup-card__cta' ).click();

	expect( seen[ 0 ].id ).toBe( 'p1' );
} );

it( 'never emits select from a disabled CTA', () => {
	const seen = [];
	const panels = mount( cardConfig );
	panels.on( 'select', ( p ) => seen.push( p ) );
	panels.openCard( { key: 'k', size: 1, points: [
		point( { selectable: { allowed: false, reason: 'нет' } } ) ] } );
	panels.root.querySelector( '.woodev-pickup-card__cta' ).click();

	expect( seen ).toHaveLength( 0 );
} );

it( 'renders escaped point text without double-escaping it', () => {
	const panels = mount( cardConfig );
	panels.openCard( { key: 'k', size: 1, points: [ point( { name: 'ПВЗ &quot;Ромашка&quot;' } ) ] } );

	expect( panels.root.querySelector( '.woodev-pickup-card__title' ).textContent ).toBe( 'ПВЗ "Ромашка"' );
} );

// -----------------------------------------------------------------------
// Extra coverage beyond the plan's own spec
// -----------------------------------------------------------------------

it( 'hides the warning entirely when the point is selectable', () => {
	const panels = mount( cardConfig );
	panels.openCard( { key: 'k', size: 1, points: [ point() ] } );

	expect( panels.root.querySelector( '.woodev-pickup-card__warning' ) ).toBeNull();
} );

it( 'falls back to the blocked i18n label when selectable.reason is empty', () => {
	const panels = mount( cardConfig );
	panels.openCard( { key: 'k', size: 1, points: [ point( { selectable: { allowed: false, reason: '' } } ) ] } );

	expect( panels.root.querySelector( '.woodev-pickup-card__warning' ).textContent ).toBe( 'Недоступен' );
} );

it( 'omits phone/work-time/weight rows individually when each field is blank', () => {
	const panels = mount( cardConfig );
	panels.openCard( { key: 'k', size: 1, points: [ point() ] } );

	expect( panels.root.querySelector( '.woodev-pickup-card__phone' ) ).toBeNull();
	expect( panels.root.querySelector( '.woodev-pickup-card__worktime' ) ).toBeNull();
	expect( panels.root.querySelector( '.woodev-pickup-card__weight' ) ).toBeNull();
	expect( panels.root.querySelector( '.woodev-pickup-card__payments' ) ).toBeNull();
} );

it( 'renders phone, work time and a 2-decimal kilogram weight when present', () => {
	const panels = mount( cardConfig );
	panels.openCard( { key: 'k', size: 1, points: [ point( {
		phone: '+7 495 000-00-00', work_time: 'ежедневно 9:00-21:00', max_weight: 5000,
		payment_methods: [ 'Картой', 'Наличными' ],
	} ) ] } );

	expect( panels.root.querySelector( '.woodev-pickup-card__phone-value' ).textContent ).toBe( '+7 495 000-00-00' );
	expect( panels.root.querySelector( '.woodev-pickup-card__worktime-value' ).textContent )
		.toBe( 'ежедневно 9:00-21:00' );
	expect( panels.root.querySelector( '.woodev-pickup-card__weight-value' ).textContent ).toBe( '5.00' );
	expect( panels.root.querySelector( '.woodev-pickup-card__payments-value' ).textContent )
		.toBe( 'Картой, Наличными' );
} );

it( 'never executes markup smuggled through selectable.reason — rendered as plain text', () => {
	const panels = mount( cardConfig );
	panels.openCard( { key: 'k', size: 1, points: [
		point( { selectable: { allowed: false, reason: '<b>нет</b>' } } ) ] } );

	const warning = panels.root.querySelector( '.woodev-pickup-card__warning' );

	expect( warning.querySelector( 'b' ) ).toBeNull();
	expect( warning.textContent ).toBe( '<b>нет</b>' );
} );

// -----------------------------------------------------------------------
// Task 14: the tab bar for co-located points (D-4)
// -----------------------------------------------------------------------

const two = {
	key: 'k', size: 2,
	points: [
		point( { id: 'a', name: 'ПВЗ «Магнит»', type: { code: 'pvz', label: 'ПВЗ' } } ),
		point( { id: 'b', name: 'Постамат №4', type: { code: 'postamat', label: 'Постамат' } } ),
	],
};

it( 'renders no tab bar for a single-point group', () => {
	const panels = mount( cardConfig );
	panels.openCard( { key: 'k', size: 1, points: [ point() ] } );

	expect( panels.root.querySelector( '.woodev-pickup-card__tabs' ) ).toBeNull();
} );

it( 'renders one tab per point, labelled by type, first active', () => {
	const panels = mount( cardConfig );
	panels.openCard( two );

	const tabs = [ ...panels.root.querySelectorAll( '.woodev-pickup-card__tab' ) ];

	expect( tabs.map( ( t ) => t.textContent ) ).toEqual( [ 'ПВЗ', 'Постамат' ] );
	expect( tabs[ 0 ].classList.contains( 'is-active' ) ).toBe( true );
} );

it( 'swaps the body when a tab is clicked', () => {
	const panels = mount( cardConfig );
	panels.openCard( two );
	panels.root.querySelectorAll( '.woodev-pickup-card__tab' )[ 1 ].click();

	expect( panels.root.querySelector( '.woodev-pickup-card__title' ).textContent ).toBe( 'Постамат №4' );
} );

it( 'falls back to the point name when two points in a group share a type', () => {
	const panels = mount( cardConfig );
	panels.openCard( { key: 'k', size: 2, points: [
		point( { id: 'a', name: 'ПВЗ «Магнит»' } ),
		point( { id: 'b', name: 'ПВЗ «Пятёрочка»' } ),
	] } );

	expect( [ ...panels.root.querySelectorAll( '.woodev-pickup-card__tab' ) ].map( ( t ) => t.textContent ) )
		.toEqual( [ 'ПВЗ «Магнит»', 'ПВЗ «Пятёрочка»' ] );
} );

it( 'opens on the requested point when the list drove the click', () => {
	const panels = mount( cardConfig );
	panels.openCard( two, 'b' );

	expect( panels.root.querySelector( '.woodev-pickup-card__title' ).textContent ).toBe( 'Постамат №4' );
} );

it( 'emits select for the ACTIVE tab, not the first point', () => {
	const seen = [];
	const panels = mount( cardConfig );
	panels.on( 'select', ( p ) => seen.push( p ) );
	panels.openCard( two );
	panels.root.querySelectorAll( '.woodev-pickup-card__tab' )[ 1 ].click();
	panels.root.querySelector( '.woodev-pickup-card__cta' ).click();

	expect( seen[ 0 ].id ).toBe( 'b' );
} );

// -----------------------------------------------------------------------
// Extra coverage beyond the plan's own spec
// -----------------------------------------------------------------------

it( 'moves is-active off the previous tab when switching, never leaving two active at once', () => {
	const panels = mount( cardConfig );
	panels.openCard( two );
	panels.root.querySelectorAll( '.woodev-pickup-card__tab' )[ 1 ].click();

	const tabs = [ ...panels.root.querySelectorAll( '.woodev-pickup-card__tab' ) ];

	expect( tabs[ 0 ].classList.contains( 'is-active' ) ).toBe( false );
	expect( tabs[ 1 ].classList.contains( 'is-active' ) ).toBe( true );
} );

it( 'keeps each point in a 3-point group addressable by its own tab, in order', () => {
	const three = {
		key: 'k', size: 3,
		points: [
			point( { id: 'a', name: 'A', type: { code: 'pvz', label: 'ПВЗ' } } ),
			point( { id: 'b', name: 'B', type: { code: 'postamat', label: 'Постамат' } } ),
			point( { id: 'c', name: 'C', type: { code: 'locker', label: 'Локер' } } ),
		],
	};
	const panels = mount( cardConfig );
	panels.openCard( three, 'c' );

	expect( panels.root.querySelector( '.woodev-pickup-card__title' ).textContent ).toBe( 'C' );

	panels.root.querySelectorAll( '.woodev-pickup-card__tab' )[ 1 ].click();

	expect( panels.root.querySelector( '.woodev-pickup-card__title' ).textContent ).toBe( 'B' );
} );

it( 'reflects the ACTIVE point\'s own selectable state, not the first point\'s, after a tab switch', () => {
	const mixed = {
		key: 'k', size: 2,
		points: [
			point( { id: 'a', name: 'A', selectable: { allowed: true, reason: null } } ),
			point( { id: 'b', name: 'B', selectable: { allowed: false, reason: 'Блокировано' } } ),
		],
	};
	const panels = mount( cardConfig );
	panels.openCard( mixed );

	expect( panels.root.querySelector( '.woodev-pickup-card__cta' ).disabled ).toBe( false );

	panels.root.querySelectorAll( '.woodev-pickup-card__tab' )[ 1 ].click();

	expect( panels.root.querySelector( '.woodev-pickup-card__cta' ).disabled ).toBe( true );
	expect( panels.root.querySelector( '.woodev-pickup-card__warning' ).textContent ).toBe( 'Блокировано' );
} );

it( 'shows continueCheckout on the specific tab matching the selected id, not just the first tab', () => {
	const panels = mount( cardConfig );
	panels.setSelectedId( 'b' );
	panels.openCard( two );

	expect( panels.root.querySelector( '.woodev-pickup-card__cta' ).textContent ).toBe( 'Забрать здесь' );

	panels.root.querySelectorAll( '.woodev-pickup-card__tab' )[ 1 ].click();

	const cta = panels.root.querySelector( '.woodev-pickup-card__cta' );
	expect( cta.textContent ).toBe( 'Продолжить оформление заказа' );
} );
