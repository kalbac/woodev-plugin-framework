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
	i18n: {
		drawerTitle: 'Пункты выдачи в этой области', emptyInView: 'В этой области пунктов выдачи нет',
		// Task 11 (spec V-6): the search layout's own strings — added here (rather than only on a
		// one-off object) so `buildSearchLayout()`'s tests can merge overrides onto this shared config.
		yourAddress: 'Ваш адрес', resetSearch: 'Сбросить поиск', search: 'Найти', noResults: 'Ничего не найдено',
		sectionPoints: 'Пункты выдачи', sectionAddresses: 'Адреса',
	},
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

	// Re-pointed at the stage (Task 6): the open state used to live on
	// `.woodev-pickup-list` itself, but now lives on `.woodev-pickup-stage`
	// (root's own parent) so a single class removal hides the list AND the
	// card together — see the "sidebar toggle" describe block below.
	expect( panels.root.parentNode.classList.contains( 'is-open' ) ).toBe( false );
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
	// Re-pointed (Task 7, spec V-11): the list header this test used to check is gone —
	// `drawerTitle`'s only remaining home is the toggle button's `aria-label`, so the I1 rule
	// (a missing key renders blank, never a hardcoded Russian default) is proven there instead.
	const panels = new Panels( document.createElement( 'div' ), { lang: 'ru_RU', i18n: {} } );
	panels.render();

	expect( panels.root.parentNode.querySelector( '.woodev-pickup-list__toggle' ).getAttribute( 'aria-label' ) )
		.toBe( '' );
} );

// -----------------------------------------------------------------------
// Extra coverage beyond the plan's own spec — see the task report for which
// mutation each one kills.
// -----------------------------------------------------------------------

it( 'renders exactly one list item per reported group once a viewport has been reported', () => {
	// Re-pointed (Task 7, spec V-11): the header used to surface this count as literal `(2)`
	// text; with the header gone, the count is only ever observable as the number of rows.
	const panels = new Panels( document.createElement( 'div' ), config );
	panels.render();
	panels.setVisible( [ group( 'a', 55.75, 37.61 ), group( 'b', 55.76, 37.61 ) ] );

	expect( panels.root.querySelectorAll( '.woodev-pickup-list__item' ) ).toHaveLength( 2 );
} );

it( 'keeps the first 300 groups in caller order, silently dropping only the tail', () => {
	// Re-pointed (Task 7, spec V-11): the header used to surface the `300+` distinction; with no
	// header left to read it from, what actually matters is verified directly — the cap keeps
	// the HEAD of the list, never an arbitrary or re-ordered subset of it.
	const many = Array.from( { length: 400 }, ( _, i ) => group( 'g' + i, 55.75 + i / 10000, 37.61 ) );
	const panels = new Panels( document.createElement( 'div' ), config );
	panels.render();
	panels.setVisible( many );

	const items = [ ...panels.root.querySelectorAll( '.woodev-pickup-list__item' ) ];

	expect( items[ 0 ].dataset.groupKey ).toBe( 'g0' );
	expect( items[ items.length - 1 ].dataset.groupKey ).toBe( 'g299' );
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

it( 'never executes markup smuggled through an i18n label — the toggle carries it as a literal attribute', () => {
	// Re-pointed (Task 7, spec V-11): `drawerTitle` no longer feeds a header; it only reaches the
	// toggle's `aria-label` now, so this proves the SAME string survives there verbatim.
	const panels = new Panels( document.createElement( 'div' ), {
		lang: 'ru_RU',
		i18n: { drawerTitle: '<img src=x>', emptyInView: '' },
	} );
	panels.render();

	const toggle = panels.root.parentNode.querySelector( '.woodev-pickup-list__toggle' );

	expect( toggle.querySelector( 'img' ) ).toBeNull();
	expect( toggle.getAttribute( 'aria-label' ) ).toBe( '<img src=x>' );
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
	panels.root.parentNode.querySelector( '.woodev-pickup-list__toggle' ).click();

	// Re-pointed at the stage (Task 6) — see the "starts closed" test above.
	expect( panels.root.parentNode.classList.contains( 'is-open' ) ).toBe( true );
} );

it( 'names the toggle button after the drawer it opens, since no dedicated i18n key exists for it', () => {
	const panels = new Panels( document.createElement( 'div' ), config );
	panels.render();

	expect( panels.root.parentNode.querySelector( '.woodev-pickup-list__toggle' ).getAttribute( 'aria-label' ) )
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

	// Re-pointed at the stage (Task 6) — see the "starts closed" test above.
	expect( panels.root.parentNode.classList.contains( 'is-open' ) ).toBe( false );
} );

// -----------------------------------------------------------------------
// Sidebar toggle (spec V-3, П-7): one open state lives on the STAGE, not on
// the list and the card independently — before this, collapsing the list
// while a card was open left the card on screen with no way to dismiss it,
// because the card had its own, unrelated `is-open` state.
// -----------------------------------------------------------------------

describe( 'sidebar toggle (spec V-3, П-7)', () => {
	it( 'hides the card as well as the list', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );
		const g = group( 'g1', 55.75, 37.61, 'ПВЗ' );

		panels.render();
		panels.setVisible( [ g ] );
		panels.openCard( g, g.points[ 0 ].id );

		const stage = container.querySelector( '.woodev-pickup-stage' );

		expect( stage.className ).toContain( 'is-open' );
		expect( stage.className ).toContain( 'is-card' );

		panels.toggleList();

		expect( stage.className ).not.toContain( 'is-open' );
		expect( stage.className ).not.toContain( 'is-card' );
	} );

	it( 'reopens to the list, not to the card that was collapsed', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );
		const g = group( 'g1', 55.75, 37.61, 'ПВЗ' );

		panels.render();
		panels.setVisible( [ g ] );
		panels.openCard( g, g.points[ 0 ].id );
		panels.toggleList();
		panels.toggleList();

		const stage = container.querySelector( '.woodev-pickup-stage' );

		expect( stage.className ).toContain( 'is-open' );
		expect( stage.className ).not.toContain( 'is-card' );
	} );

	it( 'closing the card leaves the list open', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );
		const g = group( 'g1', 55.75, 37.61, 'ПВЗ' );

		panels.render();
		panels.setVisible( [ g ] );
		panels.openCard( g, g.points[ 0 ].id );
		panels.closeCard();

		const stage = container.querySelector( '.woodev-pickup-stage' );

		expect( stage.className ).toContain( 'is-open' );
		expect( stage.className ).not.toContain( 'is-card' );
	} );
} );

// -----------------------------------------------------------------------
// Task 13: the point card, with services and CTA states
// -----------------------------------------------------------------------

// The checkbox `change` event (Task 16's type filter) only fires from a real
// `.click()` when the element is connected to `document` — jsdom's default
// activation behaviour toggles `.checked` either way, but skips dispatching
// `change` for a detached tree. `mount()` therefore attaches `panels._stage`
// (root's own parent, see Task 6) to `document.body` — `panels.root` stays
// nested inside it exactly as it is in production, so `panels.root.parentNode`
// still resolves to the stage for tests that need to assert its open state.
// `afterEach` below sweeps it back out so one test's nodes never leak into
// the next (every assertion still scopes its own lookups to `panels.root`,
// never a bare `document.querySelector`).
afterEach( () => {
	document.body.innerHTML = '';
} );

function mount( cfg ) {
	const panels = new Panels( document.createElement( 'div' ), cfg );
	panels.render();
	document.body.appendChild( panels._stage );

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

	// Re-pointed at the stage (Task 6): `is-card` (not a per-element `is-open`)
	// is what the card's own visibility is now driven by — see the "sidebar
	// toggle" describe block above.
	expect( panels.root.parentNode.classList.contains( 'is-card' ) ).toBe( true );

	panels.root.querySelector( '.woodev-pickup-card__close' ).click();

	expect( panels.root.parentNode.classList.contains( 'is-card' ) ).toBe( false );

	// Closing the card must NOT also close the list underneath it — see
	// {@see Panels.prototype.closeCard}'s docblock.
	expect( panels.root.parentNode.classList.contains( 'is-open' ) ).toBe( true );

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
	noResults: 'Ничего не найдено',
} };

// Re-pointed (Task 11, spec V-6): `renderSearchResults()` used to fill a `.woodev-pickup-search`
// div `render()` built directly inside the sidebar list. That div is gone — the results container
// is now owned by `buildSearchLayout()`'s DETACHED layout (ymaps decides where it lives, Task 12),
// so these tests build that layout first and query it directly, rather than `panels.root`.
it( 'renders the point section and the address section separately', () => {
	const panels = mount( searchConfig );
	const layout = panels.buildSearchLayout();
	panels.renderSearchResults( { points: [ point() ], addresses: [ { displayName: 'Москва, Ленина 5' } ] } );

	expect( layout.querySelector( '.woodev-pickup-search__section--points' ) ).not.toBeNull();
	expect( layout.querySelector( '.woodev-pickup-search__section--addresses' ) ).not.toBeNull();
} );

it( 'omits a section that has no results rather than showing an empty heading', () => {
	const panels = mount( searchConfig );
	const layout = panels.buildSearchLayout();
	panels.renderSearchResults( { points: [], addresses: [ { displayName: 'Москва' } ] } );

	expect( layout.querySelector( '.woodev-pickup-search__section--points' ) ).toBeNull();
} );

it( 'emits pointResult with the point id', () => {
	const seen = [];
	const panels = mount( searchConfig );
	const layout = panels.buildSearchLayout();
	panels.on( 'searchPointPicked', ( id ) => seen.push( id ) );
	// Two points with NON-default ids, and the SECOND one clicked: with a single `p1`
	// fixture an emitter hardcoded to 'p1' — or to "always the first result" — passes.
	panels.renderSearchResults( {
		points: [ point( { id: 'PVZ-77' } ), point( { id: 'PVZ-99' } ) ],
		addresses: [],
	} );
	layout.querySelectorAll( '.woodev-pickup-search__item' )[ 1 ].click();

	expect( seen ).toEqual( [ 'PVZ-99' ] );
} );

it( 'emits addressResult with the index so the caller can resolve it', () => {
	const seen = [];
	const panels = mount( searchConfig );
	const layout = panels.buildSearchLayout();
	panels.on( 'searchAddressPicked', ( i ) => seen.push( i ) );
	panels.renderSearchResults( {
		points: [],
		addresses: [ { displayName: 'A' }, { displayName: 'B' }, { displayName: 'C' } ],
	} );

	// Click the third, then the first: a hardcoded `1`, an always-zero, or an
	// always-last emitter each survive a single click on index 1.
	layout.querySelectorAll( '.woodev-pickup-search__item' )[ 2 ].click();
	layout.querySelectorAll( '.woodev-pickup-search__item' )[ 0 ].click();

	expect( seen ).toEqual( [ 2, 0 ] );
} );

it( 'setAnchor with a label sorts the list exactly like without one, and stays silent', () => {
	// Re-pointed (Task 7, spec V-11): the header + reset control this test used to check are
	// gone — `renderListHeader()` was deleted outright, and its replacement (the search field's
	// own clear button) does not exist until Task 11. What's left of `setAnchor( latLng, label )`
	// is purely its distance-sort effect; the label itself has no DOM effect any more, and
	// setting a non-null anchor must never fire `anchorCleared` (see the file docblock).
	const seen = [];
	const panels = mount( searchConfig );
	panels.on( 'anchorCleared', () => seen.push( 1 ) );
	panels.setAnchor( [ 55.75, 37.61 ], 'Москва, Тверская 1' );
	panels.setVisible( [ group( 'far', 55.90, 37.61 ), group( 'near', 55.7501, 37.61 ) ] );

	const ids = [ ...panels.root.querySelectorAll( '.woodev-pickup-list__item' ) ]
		.map( ( el ) => el.dataset.groupKey );

	expect( ids ).toEqual( [ 'near', 'far' ] );
	expect( seen ).toHaveLength( 0 );
} );

it( 'restores the caller-supplied order when the anchor is reset', () => {
	// Re-pointed (Task 7, spec V-11): "restores the plain header" meant nothing more than "the
	// distance sort is gone" — the header itself no longer exists to restore.
	const panels = mount( searchConfig );
	panels.setVisible( [ group( 'b', 55.90, 37.61 ), group( 'a', 55.75, 37.61 ) ] );
	panels.setAnchor( [ 55.75, 37.61 ], 'Москва, Тверская 1' );
	panels.setAnchor( null );

	const ids = [ ...panels.root.querySelectorAll( '.woodev-pickup-list__item' ) ]
		.map( ( el ) => el.dataset.groupKey );

	expect( ids ).toEqual( [ 'b', 'a' ] );
} );

it( 'shows the nothing-nearby state with the nearest distance', () => {
	const panels = mount( searchConfig );
	panels.showNothingNearby( { distanceMeters: 87000, name: 'ПВЗ «Магнит»' } );

	const empty = panels.root.querySelector( '.woodev-pickup-list__nothing-nearby' );

	expect( empty.textContent ).toContain( 'Рядом с этим адресом пунктов выдачи нет.' );
	expect( empty.textContent ).toContain( '87.0 км' );
	// The point's own name is the reason this state is useful rather than just apologetic —
	// without it the customer is told a distance to something unnamed.
	expect( empty.textContent ).toContain( 'ПВЗ «Магнит»' );
	expect( empty.querySelector( 'button' ) ).not.toBeNull();
	expect( empty.querySelector( 'button' ).textContent ).toBe( searchConfig.i18n.showNearest );
} );

// -----------------------------------------------------------------------
// Extra coverage beyond the plan's own spec — see the task report for which
// mutation each one kills.
// -----------------------------------------------------------------------

it( 'fires anchorCleared exactly once when an active anchor is cleared', () => {
	// Re-pointed (Task 7, spec V-11): the reset control this test used to check is gone —
	// deleted along with `renderListHeader()`. What's left to verify is the ONE signal a caller
	// (the mount — see `pickup-mount.js`) still relies on: `anchorCleared` firing when the anchor
	// is cleared (this event had no dedicated test of its own before this re-point).
	const seen = [];
	const panels = mount( searchConfig );
	panels.on( 'anchorCleared', () => seen.push( 1 ) );
	panels.setAnchor( [ 55.75, 37.61 ], 'Москва, Тверская 1' );
	panels.setAnchor( null );

	expect( seen ).toHaveLength( 1 );
} );

it( 'fires anchorCleared on every clearing call, not just the first', () => {
	// Re-pointed (Task 7, spec V-11): there is no reset control left to click — its only caller
	// (`renderListHeader()`) was deleted. The file docblock is explicit that `setAnchor( null )`
	// emits `anchorCleared` EVERY time it clears, not just on the first transition into
	// "cleared" — a flag-guarded ("only once") implementation would satisfy the test above but
	// fail this one.
	const seen = [];
	const panels = mount( searchConfig );
	panels.on( 'anchorCleared', () => seen.push( 1 ) );
	panels.setAnchor( [ 55.75, 37.61 ], 'Москва, Тверская 1' );
	panels.setAnchor( null );
	panels.setAnchor( null );

	expect( seen ).toHaveLength( 2 );
} );

it( 'a lone anchor argument without a label still sorts the list (single-arg callers unaffected)', () => {
	// Re-pointed (Task 7, spec V-11): this used to prove the single-arg (map-centre) call shape
	// left the header alone; with the header gone entirely, what remains to prove is that the
	// single-arg form still does its one real job — sorting — identically to the two-arg form.
	const panels = mount( searchConfig );
	panels.setAnchor( [ 55.75, 37.61 ] );
	panels.setVisible( [ group( 'far', 55.90, 37.61 ), group( 'near', 55.7501, 37.61 ) ] );

	const ids = [ ...panels.root.querySelectorAll( '.woodev-pickup-list__item' ) ]
		.map( ( el ) => el.dataset.groupKey );

	expect( ids ).toEqual( [ 'near', 'far' ] );
} );

it( 'renders escaped point fields (not double-escaped) inside a search point result', () => {
	const panels = mount( searchConfig );
	const layout = panels.buildSearchLayout();
	panels.renderSearchResults( { points: [ point( { name: 'ПВЗ &quot;Ромашка&quot;' } ) ], addresses: [] } );

	expect( layout.querySelector( '.woodev-pickup-search__name' ).textContent ).toBe( 'ПВЗ "Ромашка"' );
} );

it( 'never executes markup smuggled through a geocoder displayName', () => {
	const panels = mount( searchConfig );
	const layout = panels.buildSearchLayout();
	panels.renderSearchResults( { points: [], addresses: [ { displayName: '<img src=x onerror=alert(1)>' } ] } );

	const nameEl = layout.querySelector( '.woodev-pickup-search__display-name' );

	expect( nameEl.querySelector( 'img' ) ).toBeNull();
	expect( nameEl.textContent ).toBe( '<img src=x onerror=alert(1)>' );
} );

it( 'a malicious searched-address label produces no DOM side effect now that the header is gone', () => {
	// Re-pointed (Task 7, spec V-11): the header this test used to check render the label safely
	// via `textContent` was deleted; `label` now has NO DOM effect at all (see `setAnchor()`'s own
	// docblock), so what remains to prove is that a malicious value doesn't leak into the DOM
	// through some other path either.
	const panels = mount( searchConfig );
	panels.setAnchor( [ 55.75, 37.61 ], '<img src=x onerror=alert(1)>' );

	expect( panels.root.parentNode.querySelector( 'img' ) ).toBeNull();
} );

it( 'renders the exact section labels from sectionPoints/sectionAddresses, not hardcoded Russian', () => {
	const panels = mount( searchConfig );
	const layout = panels.buildSearchLayout();
	panels.renderSearchResults( { points: [ point() ], addresses: [ { displayName: 'A' } ] } );

	expect( layout.querySelector( '.woodev-pickup-search__section--points .woodev-pickup-search__section-title' )
		.textContent ).toBe( 'Пункты выдачи' );
	expect( layout
		.querySelector( '.woodev-pickup-search__section--addresses .woodev-pickup-search__section-title' )
		.textContent ).toBe( 'Адреса' );
} );

it( 'renders blank section labels, not a hardcoded default, when sectionPoints/sectionAddresses are missing', () => {
	const panels = mount( withoutI18nKey( withoutI18nKey( searchConfig, 'sectionPoints' ), 'sectionAddresses' ) );
	const layout = panels.buildSearchLayout();
	panels.renderSearchResults( { points: [ point() ], addresses: [ { displayName: 'A' } ] } );

	expect( layout.querySelector( '.woodev-pickup-search__section--points .woodev-pickup-search__section-title' )
		.textContent ).toBe( '' );
	expect( layout
		.querySelector( '.woodev-pickup-search__section--addresses .woodev-pickup-search__section-title' )
		.textContent ).toBe( '' );
} );

it( 'omits both sections and shows noResults when neither points nor addresses have results', () => {
	// Re-pointed (Task 11, spec V-6): "an empty result renders the noResults message rather than
	// an empty box" is new behaviour that only makes sense now that there IS a results container
	// to show/hide (`buildSearchLayout()`'s `.woodev-pickup-search__results`).
	const panels = mount( searchConfig );
	const layout = panels.buildSearchLayout();
	panels.renderSearchResults( { points: [], addresses: [] } );

	expect( layout.querySelector( '.woodev-pickup-search__section--points' ) ).toBeNull();
	expect( layout.querySelector( '.woodev-pickup-search__section--addresses' ) ).toBeNull();
	expect( layout.querySelector( '.woodev-pickup-search__results' ).textContent ).toBe( searchConfig.i18n.noResults );
} );

it( 'rebuilds the search results fully on a second call, not appending to the first', () => {
	const panels = mount( searchConfig );
	const layout = panels.buildSearchLayout();
	panels.renderSearchResults( { points: [ point( { id: 'p1' } ) ], addresses: [] } );
	panels.renderSearchResults( { points: [ point( { id: 'p2' } ) ], addresses: [] } );

	expect( layout.querySelectorAll( '.woodev-pickup-search__item' ) ).toHaveLength( 1 );
	expect( layout.querySelector( '.woodev-pickup-search__item' ).dataset.pointId ).toBe( 'p2' );
} );

it( 'is a no-op when called before the search layout has ever been built', () => {
	// The layout is DETACHED and only built on demand (Task 12 hands it to ymaps) — a caller that
	// calls `renderSearchResults()` without ever calling `buildSearchLayout()` first must not throw.
	const panels = mount( searchConfig );

	expect( () => panels.renderSearchResults( { points: [ point() ], addresses: [] } ) ).not.toThrow();
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
// Task 11: the search + filter layout for `SearchControl` (spec V-6)
// -----------------------------------------------------------------------

describe( 'search layout (spec V-6)', () => {
	const build = ( overrides = {} ) =>
		new Panels( document.createElement( 'div' ), { ...config, ...overrides } ).buildSearchLayout();

	it( 'renders a search form with the placeholder from i18n', () => {
		const el = build();
		const form = el.querySelector( 'form.woodev-pickup-search__form' );
		const input = form.querySelector( 'input.woodev-pickup-search__input' );

		expect( form.getAttribute( 'role' ) ).toBe( 'search' );
		expect( input.getAttribute( 'placeholder' ) ).toBe( config.i18n.yourAddress );
	} );

	it( 'shows the reset button only when the input is non-empty', () => {
		const el = build();
		const reset = el.querySelector( '.woodev-pickup-search__reset' );
		const input = el.querySelector( '.woodev-pickup-search__input' );

		expect( reset.hidden ).toBe( true );

		input.value = 'Тверская';
		input.dispatchEvent( new Event( 'input' ) );

		expect( reset.hidden ).toBe( false );
	} );

	it( 'matches loaded points while typing, debounced, from 3 characters', () => {
		jest.useFakeTimers();

		const panels = new Panels( document.createElement( 'div' ), config );
		const el = panels.buildSearchLayout();
		const onType = jest.fn();

		panels.on( 'searchType', onType );

		const input = el.querySelector( '.woodev-pickup-search__input' );

		input.value = 'Тв';
		input.dispatchEvent( new Event( 'input' ) );
		jest.advanceTimersByTime( 400 );
		expect( onType ).not.toHaveBeenCalled();

		input.value = 'Твер';
		input.dispatchEvent( new Event( 'input' ) );
		input.value = 'Тверс';
		input.dispatchEvent( new Event( 'input' ) );
		jest.advanceTimersByTime( 400 );

		expect( onType ).toHaveBeenCalledTimes( 1 );
		expect( onType ).toHaveBeenCalledWith( { query: 'Тверс' } );

		jest.useRealTimers();
	} );

	it( 'queries addresses only on submit, never while typing', () => {
		jest.useFakeTimers();

		const panels = new Panels( document.createElement( 'div' ), config );
		const el = panels.buildSearchLayout();
		const onSubmit = jest.fn();

		panels.on( 'searchSubmit', onSubmit );

		const input = el.querySelector( '.woodev-pickup-search__input' );
		input.value = 'Тверская 5';
		input.dispatchEvent( new Event( 'input' ) );
		jest.advanceTimersByTime( 1000 );

		expect( onSubmit ).not.toHaveBeenCalled();

		el.querySelector( 'form' ).dispatchEvent( new Event( 'submit', { cancelable: true } ) );

		expect( onSubmit ).toHaveBeenCalledWith( { query: 'Тверская 5' } );

		jest.useRealTimers();
	} );

	it( 'does not navigate the checkout away on submit', () => {
		const el = build();
		const event = new Event( 'submit', { cancelable: true } );

		el.querySelector( 'form' ).dispatchEvent( event );

		expect( event.defaultPrevented ).toBe( true );
	} );

	it( 'resetting clears the value, the results and the anchor', () => {
		const panels = new Panels( document.createElement( 'div' ), config );
		const el = panels.buildSearchLayout();
		const onReset = jest.fn();

		panels.on( 'searchReset', onReset );

		const input = el.querySelector( '.woodev-pickup-search__input' );
		input.value = 'Тверская';
		input.dispatchEvent( new Event( 'input' ) );
		el.querySelector( '.woodev-pickup-search__reset' ).click();

		expect( input.value ).toBe( '' );
		expect( onReset ).toHaveBeenCalled();
	} );

	it( 'builds nothing when the plugin disabled search', () => {
		expect( build( { search: false } ) ).toBeNull();
	} );

	// -------------------------------------------------------------------
	// Extra coverage beyond the plan's own spec
	// -------------------------------------------------------------------

	it( 'labels the reset and submit buttons from i18n, not a hardcoded default', () => {
		const el = build();

		expect( el.querySelector( '.woodev-pickup-search__reset' ).getAttribute( 'aria-label' ) )
			.toBe( config.i18n.resetSearch );
		expect( el.querySelector( '.woodev-pickup-search__submit' ).getAttribute( 'aria-label' ) )
			.toBe( config.i18n.search );
	} );

	it( 'renders blank aria-labels, not a hardcoded Russian default, when the i18n keys are missing', () => {
		const el = build( { i18n: {} } );

		expect( el.querySelector( '.woodev-pickup-search__input' ).getAttribute( 'placeholder' ) ).toBe( '' );
		expect( el.querySelector( '.woodev-pickup-search__reset' ).getAttribute( 'aria-label' ) ).toBe( '' );
		expect( el.querySelector( '.woodev-pickup-search__submit' ).getAttribute( 'aria-label' ) ).toBe( '' );
	} );

	it( 'never submits fewer than 1 non-whitespace character — a blank/whitespace-only query is refused', () => {
		const panels = new Panels( document.createElement( 'div' ), config );
		const el = panels.buildSearchLayout();
		const onSubmit = jest.fn();

		panels.on( 'searchSubmit', onSubmit );

		const input = el.querySelector( '.woodev-pickup-search__input' );
		input.value = '   ';
		el.querySelector( 'form' ).dispatchEvent( new Event( 'submit', { cancelable: true } ) );

		expect( onSubmit ).not.toHaveBeenCalled();
	} );

	it( 'hides the reset button again after it is used to clear the input', () => {
		const el = build();
		const input = el.querySelector( '.woodev-pickup-search__input' );
		const reset = el.querySelector( '.woodev-pickup-search__reset' );

		input.value = 'Тверская';
		input.dispatchEvent( new Event( 'input' ) );
		expect( reset.hidden ).toBe( false );

		reset.click();
		expect( reset.hidden ).toBe( true );
	} );

	it( 'returns a fresh, independent element on every call — never the same detached node twice', () => {
		const panels = new Panels( document.createElement( 'div' ), config );

		expect( panels.buildSearchLayout() ).not.toBe( panels.buildSearchLayout() );
	} );
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

// -----------------------------------------------------------------------
// Task 5 (spec V-3): the modal's `transform`-centred dialog frame makes
// `position: fixed` panels resolve against the WHOLE dialog, header
// included, instead of the viewport. Every panel now lives inside one
// `.woodev-pickup-stage` element that begins below the header, so none of
// them can reach it.
// -----------------------------------------------------------------------

describe( 'stage geometry (spec V-3)', () => {
	it( 'wraps every panel in a single stage element', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );

		panels.render();

		const stage = container.querySelector( '.woodev-pickup-stage' );

		expect( stage ).not.toBeNull();
		expect( stage.querySelector( '.woodev-pickup-map' ) ).not.toBeNull();
		expect( stage.querySelector( '.woodev-pickup-list' ) ).not.toBeNull();
		expect( stage.querySelector( '.woodev-pickup-card' ) ).not.toBeNull();
		expect( stage.querySelector( '.woodev-pickup-list__toggle' ) ).not.toBeNull();
	} );

	it( 'puts nothing directly in the container except the stage', () => {
		const container = document.createElement( 'div' );

		new Panels( container, config ).render();

		expect( container.children ).toHaveLength( 1 );
		expect( container.firstElementChild.className ).toContain( 'woodev-pickup-stage' );
	} );

	it( 'exposes the map element through getMapElement()', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );

		panels.render();

		expect( panels.getMapElement().className ).toContain( 'woodev-pickup-map' );
	} );
} );

// -----------------------------------------------------------------------
// Task 7 (spec V-11): the sidebar list is Russian Post's, without a header —
// address first in bold, name as the muted subtitle, the plugin's own type
// icon only when it supplies one. The plan's own test snippet queries
// `.woodev-pickup-list__point` for a SINGLE-point group, but `buildListItem()`
// only wraps a `.woodev-pickup-list__point` button around each point of a
// CO-LOCATED group — a single-point group's row is the `.woodev-pickup-list__item`
// itself (see that function's own docblock), so the tests below query
// `.woodev-pickup-list__item` for a solo group instead; `pickup.css` styles
// both selectors identically so a solo row and a co-located row look the same.
// -----------------------------------------------------------------------

describe( 'sidebar list (spec V-11)', () => {
	it( 'renders no list header', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );

		panels.render();
		panels.setVisible( [ group( 'g1', 55.75, 37.61, 'ПВЗ' ) ] );

		expect( container.querySelector( '.woodev-pickup-list__header' ) ).toBeNull();
	} );

	it( 'renders address first in bold and the name as the subtitle', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );
		const g = group( 'g1', 55.75, 37.61, 'ПВЗ «Магнит»' );
		g.points[ 0 ].address = 'Москва, Ленина, 5, корп. 2';

		panels.render();
		panels.setVisible( [ g ] );

		const row = container.querySelector( '.woodev-pickup-list__item' );
		const address = row.querySelector( '.woodev-pickup-list__address' );
		const name = row.querySelector( '.woodev-pickup-list__name' );

		expect( row.firstElementChild ).toBe( address );
		expect( address.textContent ).toBe( g.points[ 0 ].short_address );
		expect( name.textContent ).toBe( g.points[ 0 ].name );
		expect( address.getAttribute( 'title' ) ).toBe( g.points[ 0 ].address );
	} );

	it( 'renders the plugin type icon when one is configured, and none otherwise', () => {
		const g = group( 'g1', 55.75, 37.61, 'ПВЗ' );
		g.points[ 0 ].type = { code: 'PVZ' };

		const withIcon = new Panels( document.createElement( 'div' ), {
			...config,
			pointIcons: { PVZ: { default: '/pvz.svg', active: '/pvz-active.svg' } },
		} );
		const withoutIcon = new Panels( document.createElement( 'div' ), { ...config, pointIcons: {} } );

		withIcon.render();
		withIcon.setVisible( [ g ] );
		withoutIcon.render();
		withoutIcon.setVisible( [ g ] );

		expect( withIcon.root.parentNode.querySelector( '.woodev-pickup-list__icon' ) ).not.toBeNull();
		expect( withoutIcon.root.parentNode.querySelector( '.woodev-pickup-list__icon' ) ).toBeNull();
	} );
} );
