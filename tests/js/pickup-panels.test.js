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
const WoodevPickupGeo = require( '../../woodev/shipping-method/assets/js/frontend/pickup-geo' );

const config = {
	lang: 'ru_RU',
	i18n: { drawerTitle: 'Пункты выдачи в этой области', emptyInView: 'В этой области пунктов выдачи нет' },
};

const group = ( id, lat, lng, name ) => ( {
	key: id, lat, lng, size: 1,
	points: [ { id, name, short_address: name + ' addr', locality: 'Москва' } ],
} );

/**
 * Returns a shallow copy of `cfg` with `key` removed from its `i18n` object —
 * used to prove rule I1 (a missing i18n key renders BLANK, never a
 * hardcoded-but-plausible Russian default) for keys beyond the one the plan's
 * own spec happened to test.
 */
function withoutI18nKey( cfg, key ) {
	const i18n = Object.assign( {}, cfg.i18n );
	delete i18n[ key ];

	return Object.assign( {}, cfg, { i18n } );
}

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

it( 'never executes markup smuggled through the empty-state i18n label', () => {
	const panels = new Panels( document.createElement( 'div' ), {
		lang: 'ru_RU',
		i18n: { emptyInView: '<img src=x>' },
	} );
	panels.render();
	panels.setVisible( [] );

	const emptyEl = panels.root.querySelector( '.woodev-pickup-list__empty' );

	expect( emptyEl.querySelector( 'img' ) ).toBeNull();
	expect( emptyEl.textContent ).toBe( '<img src=x>' );
} );

it( 'renders the empty state blank, not a hardcoded default, when emptyInView is missing', () => {
	const panels = new Panels( document.createElement( 'div' ), { lang: 'ru_RU', i18n: {} } );
	panels.render();
	panels.setVisible( [] );

	expect( panels.root.querySelector( '.woodev-pickup-list__empty' ).textContent ).toBe( '' );
} );

it( 'renders the exact formatted distance from the anchor, and omits it entirely without one', () => {
	const anchor = [ 55.75, 37.61 ];
	const g = group( 'near', 55.7501, 37.61 );
	const expectedDistance = WoodevPickupGeo.formatDistance(
		WoodevPickupGeo.distanceMeters( anchor, [ g.lat, g.lng ] ),
		config.lang
	);

	const withAnchor = new Panels( document.createElement( 'div' ), config );
	withAnchor.render();
	withAnchor.setAnchor( anchor );
	withAnchor.setVisible( [ g ] );

	expect( withAnchor.root.querySelector( '.woodev-pickup-list__distance' ).textContent ).toBe( expectedDistance );

	const withoutAnchor = new Panels( document.createElement( 'div' ), config );
	withoutAnchor.render();
	withoutAnchor.setVisible( [ g ] );

	expect( withoutAnchor.root.querySelector( '.woodev-pickup-list__distance' ) ).toBeNull();
} );

it( 'toggles the list open via a REAL click on the toggle button, not just the method', () => {
	const panels = new Panels( document.createElement( 'div' ), config );
	panels.render();
	panels.root.querySelector( '.woodev-pickup-list__toggle' ).click();

	expect( panels.root.querySelector( '.woodev-pickup-list' ).classList.contains( 'is-open' ) ).toBe( true );
} );

it( 'names the toggle button after the drawer it opens, since no dedicated i18n key exists for it', () => {
	const panels = new Panels( document.createElement( 'div' ), config );
	panels.render();

	expect( panels.root.querySelector( '.woodev-pickup-list__toggle' ).getAttribute( 'aria-label' ) )
		.toBe( 'Пункты выдачи в этой области' );
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

// The checkbox `change` event (Task 16's type filter) only fires from a real
// `.click()` when the element is connected to `document` — jsdom's default
// activation behaviour toggles `.checked` either way, but skips dispatching
// `change` for a detached tree. `mount()` therefore attaches `panels.root` to
// `document.body`; `afterEach` below sweeps it back out so one test's nodes
// never leak into the next (every assertion still scopes its own lookups to
// `panels.root`, never a bare `document.querySelector`).
afterEach( () => {
	document.body.innerHTML = '';
} );

function mount( cfg ) {
	const panels = new Panels( document.createElement( 'div' ), cfg );
	panels.render();
	document.body.appendChild( panels.root );

	return panels;
}

const cardConfig = { lang: 'ru_RU', i18n: {
	select: 'Забрать здесь', continueCheckout: 'Продолжить оформление заказа',
	services: 'Услуги', paymentMethods: 'Способы оплаты', howToGet: 'Как добраться',
	phone: 'Телефон', workTime: 'Часы работы', maxWeight: 'Максимальный вес', blocked: 'Недоступен',
	close: 'Закрыть',
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

it( 'the handler itself refuses to emit select even when the disabled attribute is bypassed', () => {
	// `.click()` on a genuinely `disabled` native button never reaches any listener (the DOM
	// itself suppresses it) — the test above only proves THAT guard. This proves the SECOND,
	// independent guard inside the click handler: force the attribute off and click again.
	const seen = [];
	const panels = mount( cardConfig );
	panels.on( 'select', ( p ) => seen.push( p ) );
	panels.openCard( { key: 'k', size: 1, points: [
		point( { selectable: { allowed: false, reason: 'нет' } } ) ] } );

	const cta = panels.root.querySelector( '.woodev-pickup-card__cta' );
	cta.disabled = false;
	cta.click();

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

it( 'renders blank, not a hardcoded default, when select is missing', () => {
	const panels = mount( withoutI18nKey( cardConfig, 'select' ) );
	panels.openCard( { key: 'k', size: 1, points: [ point() ] } );

	expect( panels.root.querySelector( '.woodev-pickup-card__cta' ).textContent ).toBe( '' );
} );

it( 'renders blank, not a hardcoded default, when continueCheckout is missing', () => {
	const panels = mount( withoutI18nKey( cardConfig, 'continueCheckout' ) );
	panels.setSelectedId( 'p1' );
	panels.openCard( { key: 'k', size: 1, points: [ point() ] } );

	expect( panels.root.querySelector( '.woodev-pickup-card__cta' ).textContent ).toBe( '' );
} );

it( 'renders blank, not a hardcoded default, when blocked is missing and reason is empty', () => {
	const panels = mount( withoutI18nKey( cardConfig, 'blocked' ) );
	panels.openCard( { key: 'k', size: 1, points: [ point( { selectable: { allowed: false, reason: '' } } ) ] } );

	expect( panels.root.querySelector( '.woodev-pickup-card__warning' ).textContent ).toBe( '' );
} );

it( 'shows a close control in the card header, named from the EXISTING close i18n key', () => {
	const panels = mount( cardConfig );
	panels.openCard( { key: 'k', size: 1, points: [ point() ] } );

	const close = panels.root.querySelector( '.woodev-pickup-card__close' );

	expect( close ).not.toBeNull();
	expect( close.getAttribute( 'aria-label' ) ).toBe( 'Закрыть' );
} );

it( 'renders the close control whether or not the tab bar is present', () => {
	const single = mount( cardConfig );
	single.openCard( { key: 'k', size: 1, points: [ point() ] } );
	expect( single.root.querySelector( '.woodev-pickup-card__close' ) ).not.toBeNull();

	const multi = mount( cardConfig );
	multi.openCard( { key: 'k', size: 2, points: [
		point( { id: 'a', type: { code: 'pvz', label: 'ПВЗ' } } ),
		point( { id: 'b', type: { code: 'postamat', label: 'Постамат' } } ),
	] } );
	expect( multi.root.querySelector( '.woodev-pickup-card__close' ) ).not.toBeNull();
	expect( multi.root.querySelector( '.woodev-pickup-card__tabs' ) ).not.toBeNull();
} );

it( 'closing via a real click on the close control removes the open state, leaving the list usable', () => {
	const panels = mount( cardConfig );
	panels.openCard( { key: 'k', size: 1, points: [ point() ] } );
	expect( panels.root.querySelector( '.woodev-pickup-card' ).classList.contains( 'is-open' ) ).toBe( true );

	panels.root.querySelector( '.woodev-pickup-card__close' ).click();

	expect( panels.root.querySelector( '.woodev-pickup-card' ).classList.contains( 'is-open' ) ).toBe( false );

	// The list underneath was never touched by opening/closing the card on top of it.
	panels.setVisible( [ { key: 'g', lat: 1, lng: 1, size: 1, points: [ point( { id: 'g1', name: 'G' } ) ] } ] );
	expect( panels.root.querySelectorAll( '.woodev-pickup-list__item' ) ).toHaveLength( 1 );
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

// -----------------------------------------------------------------------
// Task 15: the search view (D-6)
// -----------------------------------------------------------------------

const searchConfig = { lang: 'ru_RU', i18n: {
	drawerTitle: 'Пункты выдачи в этой области', emptyInView: 'В этой области пунктов выдачи нет',
	nearestTo: 'Ближайшие к «%s»', resetSearch: 'Сбросить', nothingNearby: 'Рядом с этим адресом пунктов выдачи нет.',
	showNearest: 'Показать ближайший', sectionPoints: 'Пункты выдачи', sectionAddresses: 'Адреса',
} };

it( 'renders the point section and the address section separately', () => {
	const panels = mount( searchConfig );
	panels.renderSearchResults( { points: [ point() ], addresses: [ { displayName: 'Москва, Ленина 5' } ] } );

	expect( panels.root.querySelector( '.woodev-pickup-search__section--points' ) ).not.toBeNull();
	expect( panels.root.querySelector( '.woodev-pickup-search__section--addresses' ) ).not.toBeNull();
} );

it( 'omits a section that has no results rather than showing an empty heading', () => {
	const panels = mount( searchConfig );
	panels.renderSearchResults( { points: [], addresses: [ { displayName: 'Москва' } ] } );

	expect( panels.root.querySelector( '.woodev-pickup-search__section--points' ) ).toBeNull();
} );

it( 'emits pointResult with the point id', () => {
	const seen = [];
	const panels = mount( searchConfig );
	panels.on( 'searchPointPicked', ( id ) => seen.push( id ) );
	panels.renderSearchResults( { points: [ point() ], addresses: [] } );
	panels.root.querySelector( '.woodev-pickup-search__item' ).click();

	expect( seen ).toEqual( [ 'p1' ] );
} );

it( 'emits addressResult with the index so the caller can resolve it', () => {
	const seen = [];
	const panels = mount( searchConfig );
	panels.on( 'searchAddressPicked', ( i ) => seen.push( i ) );
	panels.renderSearchResults( { points: [], addresses: [ { displayName: 'A' }, { displayName: 'B' } ] } );
	panels.root.querySelectorAll( '.woodev-pickup-search__item' )[ 1 ].click();

	expect( seen ).toEqual( [ 1 ] );
} );

it( 'shows the anchor header and a reset control once an address is active', () => {
	const panels = mount( searchConfig );
	panels.setAnchor( [ 55.75, 37.61 ], 'Москва, Тверская 1' );

	expect( panels.root.querySelector( '.woodev-pickup-list__header' ).textContent )
		.toBe( 'Ближайшие к «Москва, Тверская 1»' );
	expect( panels.root.querySelector( '.woodev-pickup-list__reset' ) ).not.toBeNull();
} );

it( 'restores the plain header when the anchor is reset', () => {
	const panels = mount( searchConfig );
	panels.setAnchor( [ 55.75, 37.61 ], 'Москва, Тверская 1' );
	panels.setAnchor( null );

	expect( panels.root.querySelector( '.woodev-pickup-list__header' ).textContent )
		.toBe( 'Пункты выдачи в этой области' );
} );

it( 'shows the nothing-nearby state with the nearest distance', () => {
	const panels = mount( searchConfig );
	panels.showNothingNearby( { distanceMeters: 87000, name: 'ПВЗ «Магнит»' } );

	const empty = panels.root.querySelector( '.woodev-pickup-list__nothing-nearby' );

	expect( empty.textContent ).toContain( 'Рядом с этим адресом пунктов выдачи нет.' );
	expect( empty.textContent ).toContain( '87.0 км' );
	expect( empty.querySelector( 'button' ) ).not.toBeNull();
} );

// -----------------------------------------------------------------------
// Extra coverage beyond the plan's own spec — see the task report for which
// mutation each one kills.
// -----------------------------------------------------------------------

it( 'removes the reset control once the anchor is cleared, not just the header text', () => {
	const panels = mount( searchConfig );
	panels.setAnchor( [ 55.75, 37.61 ], 'Москва, Тверская 1' );
	panels.setAnchor( null );

	expect( panels.root.querySelector( '.woodev-pickup-list__reset' ) ).toBeNull();
} );

it( 'a real click on the reset control clears the anchor and restores the plain header', () => {
	const panels = mount( searchConfig );
	panels.setAnchor( [ 55.75, 37.61 ], 'Москва, Тверская 1' );
	panels.root.querySelector( '.woodev-pickup-list__reset' ).click();

	expect( panels.root.querySelector( '.woodev-pickup-list__header' ).textContent )
		.toBe( 'Пункты выдачи в этой области' );
	expect( panels.root.querySelector( '.woodev-pickup-list__reset' ) ).toBeNull();
} );

it( 'a lone anchor argument without a label never turns on the search header (single-arg callers unaffected)', () => {
	const panels = mount( searchConfig );
	panels.setAnchor( [ 55.75, 37.61 ] );

	expect( panels.root.querySelector( '.woodev-pickup-list__header' ).textContent )
		.toBe( 'Пункты выдачи в этой области' );
	expect( panels.root.querySelector( '.woodev-pickup-list__reset' ) ).toBeNull();
} );

it( 'renders escaped point fields (not double-escaped) inside a search point result', () => {
	const panels = mount( searchConfig );
	panels.renderSearchResults( { points: [ point( { name: 'ПВЗ &quot;Ромашка&quot;' } ) ], addresses: [] } );

	expect( panels.root.querySelector( '.woodev-pickup-search__name' ).textContent ).toBe( 'ПВЗ "Ромашка"' );
} );

it( 'never executes markup smuggled through a geocoder displayName', () => {
	const panels = mount( searchConfig );
	panels.renderSearchResults( { points: [], addresses: [ { displayName: '<img src=x onerror=alert(1)>' } ] } );

	const nameEl = panels.root.querySelector( '.woodev-pickup-search__display-name' );

	expect( nameEl.querySelector( 'img' ) ).toBeNull();
	expect( nameEl.textContent ).toBe( '<img src=x onerror=alert(1)>' );
} );

it( 'never executes markup smuggled through the searched-address label in the anchor header', () => {
	const panels = mount( searchConfig );
	panels.setAnchor( [ 55.75, 37.61 ], '<img src=x onerror=alert(1)>' );

	const header = panels.root.querySelector( '.woodev-pickup-list__header' );

	expect( header.querySelector( 'img' ) ).toBeNull();
	expect( header.textContent ).toBe( 'Ближайшие к «<img src=x onerror=alert(1)>»' );
} );

it( 'renders the exact section labels from sectionPoints/sectionAddresses, not hardcoded Russian', () => {
	const panels = mount( searchConfig );
	panels.renderSearchResults( { points: [ point() ], addresses: [ { displayName: 'A' } ] } );

	expect( panels.root.querySelector( '.woodev-pickup-search__section--points .woodev-pickup-search__section-title' )
		.textContent ).toBe( 'Пункты выдачи' );
	expect( panels.root
		.querySelector( '.woodev-pickup-search__section--addresses .woodev-pickup-search__section-title' )
		.textContent ).toBe( 'Адреса' );
} );

it( 'renders blank section labels, not a hardcoded default, when sectionPoints/sectionAddresses are missing', () => {
	const panels = mount( withoutI18nKey( withoutI18nKey( searchConfig, 'sectionPoints' ), 'sectionAddresses' ) );
	panels.renderSearchResults( { points: [ point() ], addresses: [ { displayName: 'A' } ] } );

	expect( panels.root.querySelector( '.woodev-pickup-search__section--points .woodev-pickup-search__section-title' )
		.textContent ).toBe( '' );
	expect( panels.root
		.querySelector( '.woodev-pickup-search__section--addresses .woodev-pickup-search__section-title' )
		.textContent ).toBe( '' );
} );

it( 'omits both sections when neither points nor addresses have results', () => {
	const panels = mount( searchConfig );
	panels.renderSearchResults( { points: [], addresses: [] } );

	expect( panels.root.querySelector( '.woodev-pickup-search__section--points' ) ).toBeNull();
	expect( panels.root.querySelector( '.woodev-pickup-search__section--addresses' ) ).toBeNull();
} );

it( 'rebuilds the search results fully on a second call, not appending to the first', () => {
	const panels = mount( searchConfig );
	panels.renderSearchResults( { points: [ point( { id: 'p1' } ) ], addresses: [] } );
	panels.renderSearchResults( { points: [ point( { id: 'p2' } ) ], addresses: [] } );

	expect( panels.root.querySelectorAll( '.woodev-pickup-search__item' ) ).toHaveLength( 1 );
	expect( panels.root.querySelector( '.woodev-pickup-search__item' ).dataset.pointId ).toBe( 'p2' );
} );

it( 'emits showNearestRequested with the same info when the show-nearest button is clicked', () => {
	const seen = [];
	const panels = mount( searchConfig );
	panels.on( 'showNearestRequested', ( info ) => seen.push( info ) );
	panels.showNothingNearby( { distanceMeters: 87000, name: 'ПВЗ «Магнит»' } );
	panels.root.querySelector( '.woodev-pickup-list__nothing-nearby button' ).click();

	expect( seen ).toEqual( [ { distanceMeters: 87000, name: 'ПВЗ «Магнит»' } ] );
} );

// -----------------------------------------------------------------------
// Task 16: the type filter menu (D-10)
// -----------------------------------------------------------------------

const filterConfig = { lang: 'ru_RU', i18n: {
	drawerTitle: 'Пункты выдачи в этой области', emptyInView: 'В этой области пунктов выдачи нет',
	filterTypes: 'Тип пунктов',
} };

it( 'does not render the filter until a second type appears', () => {
	const panels = mount( filterConfig );
	panels.setTypes( [ { code: 'pvz', label: 'ПВЗ' } ] );

	expect( panels.root.querySelector( '.woodev-pickup-filter' ) ).toBeNull();
} );

it( 'renders one checkbox per type, all checked, once there are two', () => {
	const panels = mount( filterConfig );
	panels.setTypes( [ { code: 'pvz', label: 'ПВЗ' }, { code: 'postamat', label: 'Постамат' } ] );

	const boxes = [ ...panels.root.querySelectorAll( '.woodev-pickup-filter__checkbox' ) ];

	expect( boxes ).toHaveLength( 2 );
	expect( boxes.every( ( b ) => b.checked ) ).toBe( true );
} );

it( 'never disappears again once shown', () => {
	const panels = mount( filterConfig );
	panels.setTypes( [ { code: 'pvz', label: 'ПВЗ' }, { code: 'postamat', label: 'Постамат' } ] );
	panels.setTypes( [ { code: 'pvz', label: 'ПВЗ' } ] );

	expect( panels.root.querySelector( '.woodev-pickup-filter' ) ).not.toBeNull();
} );

it( 'refuses to uncheck the last checked type', () => {
	const panels = mount( filterConfig );
	panels.setTypes( [ { code: 'pvz', label: 'ПВЗ' }, { code: 'postamat', label: 'Постамат' } ] );

	const boxes = [ ...panels.root.querySelectorAll( '.woodev-pickup-filter__checkbox' ) ];
	boxes[ 0 ].click();
	boxes[ 1 ].click();

	expect( boxes[ 1 ].checked ).toBe( true );
} );

it( 'shows the badge only when the selection is partial', () => {
	const panels = mount( filterConfig );
	panels.setTypes( [ { code: 'pvz', label: 'ПВЗ' }, { code: 'postamat', label: 'Постамат' } ] );

	expect( panels.root.querySelector( '.woodev-pickup-filter__badge' ) ).toBeNull();

	panels.root.querySelectorAll( '.woodev-pickup-filter__checkbox' )[ 0 ].click();

	expect( panels.root.querySelector( '.woodev-pickup-filter__badge' ).textContent ).toBe( '1' );
} );

it( 'emits the selected codes on change', () => {
	const seen = [];
	const panels = mount( filterConfig );
	panels.on( 'typeFilterChange', ( codes ) => seen.push( codes ) );
	panels.setTypes( [ { code: 'pvz', label: 'ПВЗ' }, { code: 'postamat', label: 'Постамат' } ] );
	panels.root.querySelectorAll( '.woodev-pickup-filter__checkbox' )[ 0 ].click();

	expect( seen ).toEqual( [ [ 'postamat' ] ] );
} );

// -----------------------------------------------------------------------
// Extra coverage beyond the plan's own spec — see the task report for which
// mutation each one kills.
// -----------------------------------------------------------------------

it( 'never emits typeFilterChange when the last-checked uncheck is refused', () => {
	const seen = [];
	const panels = mount( filterConfig );
	panels.on( 'typeFilterChange', ( codes ) => seen.push( codes ) );
	panels.setTypes( [ { code: 'pvz', label: 'ПВЗ' }, { code: 'postamat', label: 'Постамат' } ] );

	const boxes = [ ...panels.root.querySelectorAll( '.woodev-pickup-filter__checkbox' ) ];
	boxes[ 0 ].click();
	seen.length = 0;
	boxes[ 1 ].click();

	expect( seen ).toHaveLength( 0 );
} );

it( 'keeps both checkboxes present after a later call reports only one type, not shrinking the list', () => {
	const panels = mount( filterConfig );
	panels.setTypes( [ { code: 'pvz', label: 'ПВЗ' }, { code: 'postamat', label: 'Постамат' } ] );
	panels.setTypes( [ { code: 'pvz', label: 'ПВЗ' } ] );

	const codes = [ ...panels.root.querySelectorAll( '.woodev-pickup-filter__checkbox' ) ]
		.map( ( b ) => b.dataset.code );

	expect( codes ).toEqual( [ 'pvz', 'postamat' ] );
} );

it( 'shows the exact partial count (not the excluded count) for a 2-of-3 selection', () => {
	const panels = mount( filterConfig );
	panels.setTypes( [
		{ code: 'pvz', label: 'ПВЗ' }, { code: 'postamat', label: 'Постамат' }, { code: 'locker', label: 'Локер' },
	] );

	panels.root.querySelectorAll( '.woodev-pickup-filter__checkbox' )[ 0 ].click();

	expect( panels.root.querySelector( '.woodev-pickup-filter__badge' ).textContent ).toBe( '2' );
} );

it( 'shows the filter title from the filterTypes i18n key', () => {
	const panels = mount( filterConfig );
	panels.setTypes( [ { code: 'pvz', label: 'ПВЗ' }, { code: 'postamat', label: 'Постамат' } ] );

	expect( panels.root.querySelector( '.woodev-pickup-filter__title' ).textContent ).toBe( 'Тип пунктов' );
} );

it( 'renders blank, not a hardcoded default, filter title when filterTypes is missing', () => {
	const panels = mount( withoutI18nKey( filterConfig, 'filterTypes' ) );
	panels.setTypes( [ { code: 'pvz', label: 'ПВЗ' }, { code: 'postamat', label: 'Постамат' } ] );

	expect( panels.root.querySelector( '.woodev-pickup-filter__title' ).textContent ).toBe( '' );
} );

it( 'renders escaped type labels (not double-escaped) in the checkbox rows', () => {
	const panels = mount( filterConfig );
	panels.setTypes( [
		{ code: 'pvz', label: 'ПВЗ &quot;А&quot;' }, { code: 'postamat', label: 'Постамат' },
	] );

	const labels = [ ...panels.root.querySelectorAll( '.woodev-pickup-filter__label' ) ].map( ( l ) => l.textContent );

	expect( labels ).toEqual( [ 'ПВЗ "А"', 'Постамат' ] );
} );

it( 'ignores a type whose code is missing/non-string rather than crashing or rendering a blank row', () => {
	const panels = mount( filterConfig );
	panels.setTypes( [ { code: 'pvz', label: 'ПВЗ' }, { label: 'no code' }, { code: 'postamat', label: 'Постамат' } ] );

	expect( panels.root.querySelectorAll( '.woodev-pickup-filter__checkbox' ) ).toHaveLength( 2 );
} );
