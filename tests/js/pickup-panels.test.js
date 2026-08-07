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
		// Task 14 (spec V-13): the zoom control's two `aria-label`s — distinct from `zoomIn`,
		// which labels the unrelated "zoom in to see points" bbox-too-wide message.
		zoomInLabel: 'Приблизить карту', zoomOutLabel: 'Отдалить карту',
		// Task 17 (spec V-5): `showMessage()`'s own keys — the two OTHER empty/error states
		// (`emptyInView` above already existed) plus the generic error text and the retry
		// control's label.
		emptyLocality: 'В выбранном населённом пункте нет пунктов выдачи',
		error: 'Не удалось загрузить пункты выдачи. Попробуйте ещё раз.',
		zoomIn: 'Приблизьте карту, чтобы увидеть пункты выдачи',
		retry: 'Повторить',
		// #168: the sidebar toggle's own label — the only state that RENDERS it is the mobile
		// open-list bar, but it is also the control's accessible name whenever the sidebar is open.
		showMap: 'Показать карту',
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

it( 'reports the panel width PLUS its 16px gutter, so the reserved strip matches what it covers', () => {
	const seen = [];
	const panels = new Panels( document.createElement( 'div' ), config );
	panels.on( 'listToggle', ( e ) => seen.push( e ) );
	panels.render();

	// jsdom lays nothing out, so every `offsetWidth` is 0 — pin the CSS cap (320px) so the
	// arithmetic this test exists for is observable at all.
	Object.defineProperty(
		panels.root.querySelector( '.woodev-pickup-list' ),
		'offsetWidth',
		{ value: 320 }
	);

	panels.toggleList();

	// #168: the panel no longer sits flush against the stage's right edge — it floats 16px in
	// from it. The strip it occupies, measured from that edge (which is what `map.margin`
	// reserves), is therefore its own width PLUS that gutter. Reserving the bare width would
	// under-reserve by exactly 16px, the same class of defect as
	// `ymaps-margin-area-needs-explicit-width`, where a reservation that looked right was
	// silently smaller than the panel it stood for.
	expect( seen[ 0 ].width ).toBe( 336 );
} );

describe( 'sidebar toggle label (#168 — the mobile open-list bar)', () => {
	const toggleOf = ( panels ) => panels.root.parentNode.querySelector( '.woodev-pickup-list__toggle' );

	it( 'carries a visible label taken from the showMap i18n key', () => {
		const panels = new Panels( document.createElement( 'div' ), config );
		panels.render();

		expect( toggleOf( panels ).querySelector( '.woodev-pickup-list__toggle-label' ).textContent )
			.toBe( 'Показать карту' );
	} );

	it( 'renders that label BLANK rather than a Russian default when showMap is missing (rule I1)', () => {
		const panels = new Panels( document.createElement( 'div' ), withoutI18nKey( config, 'showMap' ) );
		panels.render();

		expect( toggleOf( panels ).querySelector( '.woodev-pickup-list__toggle-label' ).textContent )
			.toBe( '' );
	} );

	// WCAG 2.5.3 (Label in Name): once the bar shows «Показать карту», an accessible name that
	// still said «Пункты выдачи в этой области» would not contain the visible text. The name
	// tracks the state instead — which is also simply truer, since the control does two opposite
	// things depending on it.
	it( 'names itself by what pressing it will do — showMap while open, drawerTitle while closed', () => {
		const panels = new Panels( document.createElement( 'div' ), config );
		panels.render();

		expect( toggleOf( panels ).getAttribute( 'aria-label' ) ).toBe( 'Пункты выдачи в этой области' );

		panels.toggleList();
		expect( toggleOf( panels ).getAttribute( 'aria-label' ) ).toBe( 'Показать карту' );

		panels.toggleList();
		expect( toggleOf( panels ).getAttribute( 'aria-label' ) ).toBe( 'Пункты выдачи в этой области' );
	} );
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

describe( 'accent colours (issue #203 — brand identity split from the filled-text surface)', () => {
	// The derivation (darken-to-30%-then-black WCAG algorithm) runs server-side, once
	// (`Pickup_Handler::resolve_accent_fill_color()`/`resolve_accent_contrast_color()`) — these
	// tests prove the CLIENT half only: it applies whatever the config carries verbatim (through
	// `safeColor()`), and falls back to a pinned literal PER PROPERTY when the config is missing
	// or unsafe. They deliberately do NOT re-derive fill/contrast from an accent value — that
	// would be exactly the mirrored-evaluator duplication the framework avoids elsewhere (see
	// `pickup-panels.js`'s own `applyAccentColor()` docblock).

	it( 'writes the framework default triplet when the config carries none', () => {
		const panels = new Panels( document.createElement( 'div' ), config );
		panels.render();

		expect( panels.root.style.getPropertyValue( '--woodev-pickup-accent' ) ).toBe( '#06aedd' );
		expect( panels.root.style.getPropertyValue( '--woodev-pickup-accent-fill' ) ).toBe( '#047a9b' );
		expect( panels.root.style.getPropertyValue( '--woodev-pickup-accent-contrast' ) ).toBe( '#ffffff' );
	} );

	it( 'applies a server-resolved triplet verbatim, including a BLACK contrast', () => {
		// `#ffeb3b` (issue #203's own light-colour example): the server would resolve fill
		// unchanged and contrast to black — proves the client does not assume contrast is
		// always white.
		const panels = new Panels( document.createElement( 'div' ), Object.assign( {}, config, {
			accentColor: '#ffeb3b',
			accentFillColor: '#ffeb3b',
			accentContrastColor: '#000000',
		} ) );
		panels.render();

		expect( panels.root.style.getPropertyValue( '--woodev-pickup-accent' ) ).toBe( '#ffeb3b' );
		expect( panels.root.style.getPropertyValue( '--woodev-pickup-accent-fill' ) ).toBe( '#ffeb3b' );
		expect( panels.root.style.getPropertyValue( '--woodev-pickup-accent-contrast' ) ).toBe( '#000000' );
	} );

	it( 'falls back to the default accent ALONE when only accentColor is unsafe', () => {
		const panels = new Panels( document.createElement( 'div' ), Object.assign( {}, config, {
			accentColor: 'javascript:alert(1)',
			accentFillColor: '#098534',
			accentContrastColor: '#ffffff',
		} ) );
		panels.render();

		expect( panels.root.style.getPropertyValue( '--woodev-pickup-accent' ) ).toBe( '#06aedd' );
		expect( panels.root.style.getPropertyValue( '--woodev-pickup-accent-fill' ) ).toBe( '#098534' );
		expect( panels.root.style.getPropertyValue( '--woodev-pickup-accent-contrast' ) ).toBe( '#ffffff' );
	} );

	it( 'falls back to the default fill ALONE when only accentFillColor is missing', () => {
		const panels = new Panels( document.createElement( 'div' ), Object.assign( {}, config, {
			accentColor: '#0a8c37',
			accentContrastColor: '#ffffff',
		} ) );
		panels.render();

		expect( panels.root.style.getPropertyValue( '--woodev-pickup-accent' ) ).toBe( '#0a8c37' );
		expect( panels.root.style.getPropertyValue( '--woodev-pickup-accent-fill' ) ).toBe( '#047a9b' );
		expect( panels.root.style.getPropertyValue( '--woodev-pickup-accent-contrast' ) ).toBe( '#ffffff' );
	} );

	it( 'falls back to the default contrast ALONE when only accentContrastColor is unsafe', () => {
		const panels = new Panels( document.createElement( 'div' ), Object.assign( {}, config, {
			accentColor: '#0a8c37',
			accentFillColor: '#098534',
			accentContrastColor: 'not-a-colour',
		} ) );
		panels.render();

		expect( panels.root.style.getPropertyValue( '--woodev-pickup-accent' ) ).toBe( '#0a8c37' );
		expect( panels.root.style.getPropertyValue( '--woodev-pickup-accent-fill' ) ).toBe( '#098534' );
		expect( panels.root.style.getPropertyValue( '--woodev-pickup-accent-contrast' ) ).toBe( '#ffffff' );
	} );
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
// Task 10: the sidebar's persistent record of "which one did I choose". Until now the only trace
// of a selection was the card CTA's label flipping to `continueCheckout` (Task 8) — invisible
// once the customer scrolls the list away from the open card. The row (or, for a co-located
// group, the per-point button inside it) carries its own `is-selected` marker, computed at BUILD
// time from `self._selectedId` so it is correct regardless of call order.
// -----------------------------------------------------------------------

describe( 'selected row highlight (Task 10)', () => {
	it( 'marks the selected point row', () => {
		const panels = new Panels( document.createElement( 'div' ), config );
		panels.render();
		panels.setSelectedId( 'p2' );
		panels.setVisible( [ group( 'p1', 55.75, 37.61, 'ПВЗ 1' ), group( 'p2', 55.76, 37.61, 'ПВЗ 2' ) ] );

		const rows = panels.root.querySelectorAll( '.woodev-pickup-list__item' );

		expect( rows[ 0 ].classList.contains( 'is-selected' ) ).toBe( false );
		expect( rows[ 1 ].classList.contains( 'is-selected' ) ).toBe( true );
	} );

	it( 'marks only the selected point inside a co-located group', () => {
		const panels = new Panels( document.createElement( 'div' ), config );
		panels.render();
		panels.setSelectedId( 'b' );
		panels.setVisible( [ {
			key: 'g1', lat: 55.75, lng: 37.61, size: 2,
			points: [
				{ id: 'a', name: 'ПВЗ', short_address: 'x' },
				{ id: 'b', name: 'Постамат', short_address: 'y' },
			],
		} ] );

		const buttons = panels.root.querySelectorAll( '.woodev-pickup-list__point' );

		expect( buttons[ 0 ].classList.contains( 'is-selected' ) ).toBe( false );
		expect( buttons[ 1 ].classList.contains( 'is-selected' ) ).toBe( true );
	} );

	it( 'moves the highlight when the selection changes', () => {
		const panels = new Panels( document.createElement( 'div' ), config );
		panels.render();
		panels.setVisible( [ group( 'g1', 55.75, 37.61, 'ПВЗ 1' ), group( 'g2', 55.76, 37.61, 'ПВЗ 2' ) ] );

		panels.setSelectedId( 'g1' );

		expect( panels.root.querySelectorAll( '.woodev-pickup-list__item.is-selected' ) ).toHaveLength( 1 );
		expect( panels.root.querySelector( '.woodev-pickup-list__item.is-selected' ).dataset.groupKey )
			.toBe( 'g1' );
	} );

	// Matching the sibling guard already proven for `setSelectionBusy`/`showSelectionError` (Task
	// 8/9, both above `mount()`'s own definition) — `setSelectedId()` now touches the list, not
	// only the card, so it needs the same before-`render()` guard those two already carry.
	it( 'does not throw when called before render()', () => {
		const panels = new Panels( document.createElement( 'div' ), config );

		expect( () => panels.setSelectedId( 'p1' ) ).not.toThrow();
	} );
} );

// -----------------------------------------------------------------------
// #172: `setSelectedId()` used to rebuild the whole list (`renderListBody()` — up to LIST_CAP
// DOM nodes recreated, every row's click listener re-attached) EVERY time it was called, even
// when the id it was handed is the exact one already recorded. `pickup-mount.js`'s restore pass
// does exactly that on session open under `strategy: 'viewport'`: `alreadySelected` seeds
// `_selectedId` before the first fetch, then `restoreSelection()` calls `setSelectedId()` again
// with the SAME value once the map is ready — a second full rebuild that moves no class and
// changes nothing on screen. Reproduced here at the row-identity level (a real rebuild replaces
// every row with a fresh node; an unchanged id must leave the existing ones alone), the smallest
// place the redundant work is directly observable — `pickup-mount.js`'s own tests use a
// `StubPanels` double that records calls onto plain properties, never real DOM, so the
// duplicate rebuild is invisible from there.
// -----------------------------------------------------------------------

describe( 'setSelectedId does not rebuild the list for an unchanged id (#172)', () => {
	it( 'leaves the existing row nodes alone when called twice with the same id', () => {
		const panels = new Panels( document.createElement( 'div' ), config );
		panels.render();
		panels.setVisible( [ group( 'p1', 55.75, 37.61, 'ПВЗ 1' ), group( 'p2', 55.76, 37.61, 'ПВЗ 2' ) ] );
		panels.setSelectedId( 'p2' );

		const before = [ ...panels.root.querySelectorAll( '.woodev-pickup-list__item' ) ];

		// The exact scenario #172 traces: a second call with the id already in force — nothing
		// about the selection changed, so nothing about the list should either.
		panels.setSelectedId( 'p2' );

		const after = [ ...panels.root.querySelectorAll( '.woodev-pickup-list__item' ) ];

		// `renderListBody()` always does `empty( self._listBodyEl )` then rebuilds every row from
		// scratch — a real rebuild therefore hands back BRAND NEW nodes, never the same
		// references. Same references here is exactly "the second call did no list work".
		expect( after ).toHaveLength( before.length );
		expect( after[ 0 ] ).toBe( before[ 0 ] );
		expect( after[ 1 ] ).toBe( before[ 1 ] );
	} );

	it( 'still rebuilds the list body the FIRST time an id is recorded, even with no group data yet', () => {
		// Mirrors `pickup-mount.js`'s own opening sequence: `setSelectedId()` is seeded before
		// `setVisible()` ever runs (session open, before the first fetch). `_selectedId` moves
		// from `null` to a real value here — a genuine change — so this must NOT be skipped, or
		// a customer reopening the picker on an already-chosen point would never get the
		// highlight once the real groups do arrive without ANOTHER unrelated change first.
		const panels = new Panels( document.createElement( 'div' ), config );
		panels.render();

		expect( () => panels.setSelectedId( 'p2' ) ).not.toThrow();

		panels.setVisible( [ group( 'p1', 55.75, 37.61, 'ПВЗ 1' ), group( 'p2', 55.76, 37.61, 'ПВЗ 2' ) ] );

		const rows = panels.root.querySelectorAll( '.woodev-pickup-list__item' );

		expect( rows[ 1 ].classList.contains( 'is-selected' ) ).toBe( true );
	} );

	it( 'still rebuilds the list body when the id actually changes', () => {
		// Regression guard alongside the skip above — normal customer-driven reselection (a
		// different sidebar row, a different marker) must keep rebuilding exactly as before.
		const panels = new Panels( document.createElement( 'div' ), config );
		panels.render();
		panels.setVisible( [ group( 'p1', 55.75, 37.61, 'ПВЗ 1' ), group( 'p2', 55.76, 37.61, 'ПВЗ 2' ) ] );
		panels.setSelectedId( 'p1' );

		const before = [ ...panels.root.querySelectorAll( '.woodev-pickup-list__item' ) ];

		panels.setSelectedId( 'p2' );

		const after = [ ...panels.root.querySelectorAll( '.woodev-pickup-list__item' ) ];

		expect( after[ 0 ] ).not.toBe( before[ 0 ] );
		expect( after[ 1 ] ).not.toBe( before[ 1 ] );
		expect( after[ 1 ].classList.contains( 'is-selected' ) ).toBe( true );
	} );

	it( 'still updates the open card\'s CTA when setSelectedId is called with the unchanged id', () => {
		// The trap the brief calls out: `setSelectedId()` also drives `renderCard()` (the CTA's
		// `continueCheckout`/`select` label). Gating the LIST rebuild on "id changed" must not
		// gate the card too — proven by opening a card, THEN calling `setSelectedId()` again with
		// the id already in force, and checking the CTA still reflects it correctly.
		const cfg = {
			...config,
			i18n: { ...config.i18n, select: 'Забрать здесь', continueCheckout: 'Продолжить оформление заказа' },
		};
		const panels = new Panels( document.createElement( 'div' ), cfg );
		panels.render();
		panels.setSelectedId( 'p1' );
		panels.openCard( { key: 'g1', size: 1, points: [ { id: 'p1', name: 'ПВЗ 1', short_address: 'x' } ] } );

		expect( panels.root.querySelector( '.woodev-pickup-card__cta' ).textContent )
			.toBe( 'Продолжить оформление заказа' );

		panels.setSelectedId( 'p1' );

		expect( panels.root.querySelector( '.woodev-pickup-card__cta' ).textContent )
			.toBe( 'Продолжить оформление заказа' );
	} );
} );

// -----------------------------------------------------------------------
// Round 2 (D6): `openCard( group, pointId, origin )` — `origin` is what lets the mount tell a
// marker click (pan only) apart from every other route (zoom in), now that the original V-10
// "must behave identically" claim has been overruled (see the file docblock's revised
// `cardOpened` note). Every internal call site in THIS file (the sidebar row builders) passes
// `'list'`; a search pick and "show nearest" route through `openCard()` from the mount, outside
// this file, so their own label is the mount's responsibility, not tested here.
// -----------------------------------------------------------------------

describe( 'origin threading on cardOpened (round 2, D6)', () => {
	it( 'a single-point row click carries origin "list"', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );
		const g = group( 'g1', 55.75, 37.61, 'ПВЗ' );
		const seen = [];

		panels.render();
		panels.setVisible( [ g ] );
		panels.on( 'cardOpened', ( payload ) => seen.push( payload ) );

		container.querySelector( '.woodev-pickup-list__item' ).click();

		expect( seen ).toEqual( [ { group: g, pointId: g.points[ 0 ].id, origin: 'list' } ] );
	} );

	it( 'a co-located group\'s per-point row click also carries origin "list"', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );
		const g = {
			key: 'g1', lat: 55.75, lng: 37.61, size: 2,
			points: [
				{ id: 'a', name: 'ПВЗ', short_address: 'x' },
				{ id: 'b', name: 'Постамат', short_address: 'y' },
			],
		};
		const seen = [];

		panels.render();
		panels.setVisible( [ g ] );
		panels.on( 'cardOpened', ( payload ) => seen.push( payload ) );

		container.querySelectorAll( '.woodev-pickup-list__point' )[ 1 ].click();

		expect( seen[ 0 ].pointId ).toBe( 'b' );
		expect( seen[ 0 ].origin ).toBe( 'list' );
	} );

	it( 'openCard() called directly passes its origin argument straight through to cardOpened', () => {
		const panels = mount( cardConfig );
		const seen = [];

		panels.on( 'cardOpened', ( payload ) => seen.push( payload ) );
		panels.openCard( { key: 'k', size: 1, points: [ point() ] }, undefined, 'marker' );

		expect( seen[ 0 ].origin ).toBe( 'marker' );
	} );
} );

// -----------------------------------------------------------------------
// Coordinator follow-up (round 2): the sidebar could become visible without anyone being told —
// `openList()` and `openCard()` used to flip `is-open` on WITHOUT emitting `listToggle`, the ONE
// event the mount listens to in order to call `provider.setMargin()` (ymaps' `map.margin.addArea()`).
// A marker click opened the sidebar, ymaps was never told, and `focusGroup()`'s camera move landed
// the point off-centre under the panel that had just slid over it (operator defect 5, second half) —
// and the same missing reservation let the sidebar cover ymaps' own copyright strip (defect 8).
// `setStageOpen()` is the fix: all three open-state methods route through it, and it emits
// `listToggle` only when the visible state actually changes.
// -----------------------------------------------------------------------

describe( 'listToggle notification on every open-state transition (round 2, defect 5+8)', () => {
	it( 'a marker-origin openCard() on a closed sidebar emits listToggle {open:true} exactly once, before cardOpened', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );
		const g = group( 'g1', 55.75, 37.61, 'ПВЗ' );
		const seen = [];

		panels.render();
		panels.setVisible( [ g ] );
		panels.on( 'listToggle', ( e ) => seen.push( { type: 'listToggle', payload: e } ) );
		panels.on( 'cardOpened', ( e ) => seen.push( { type: 'cardOpened', payload: e } ) );

		panels.openCard( g, g.points[ 0 ].id, 'marker' );

		// Ordering is load-bearing (see `openCard()`'s own docblock): the mount turns `listToggle`
		// into `setMargin()` and `cardOpened` into `focusGroup()`, both synchronous, so the margin
		// reservation must land first or the camera move happens before there is anything to avoid.
		expect( seen.map( ( s ) => s.type ) ).toEqual( [ 'listToggle', 'cardOpened' ] );
		expect( seen[ 0 ].payload.open ).toBe( true );
		expect( typeof seen[ 0 ].payload.width ).toBe( 'number' );
		expect( seen.filter( ( s ) => 'listToggle' === s.type ) ).toHaveLength( 1 );
	} );

	it( 'a second openCard() while the sidebar is already open emits no further listToggle', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );
		const g1 = group( 'g1', 55.75, 37.61, 'ПВЗ' );
		const g2 = group( 'g2', 55.76, 37.62, 'Постамат' );
		const seen = [];

		panels.render();
		panels.setVisible( [ g1, g2 ] );
		panels.openCard( g1, g1.points[ 0 ].id, 'marker' ); // opens the sidebar first.

		panels.on( 'listToggle', ( e ) => seen.push( e ) );
		panels.openCard( g2, g2.points[ 0 ].id, 'marker' ); // sidebar already open — must stay silent.

		expect( seen ).toHaveLength( 0 );
	} );

	it( 'openList() on a closed sidebar emits listToggle {open:true}', () => {
		const panels = new Panels( document.createElement( 'div' ), config );
		const seen = [];

		panels.render();
		panels.on( 'listToggle', ( e ) => seen.push( e ) );

		panels.openList();

		expect( seen ).toHaveLength( 1 );
		expect( seen[ 0 ].open ).toBe( true );
	} );

	it( 'openList() while the sidebar is already open emits no listToggle', () => {
		const panels = new Panels( document.createElement( 'div' ), config );
		const seen = [];

		panels.render();
		panels.toggleList(); // opens.
		panels.on( 'listToggle', ( e ) => seen.push( e ) );

		panels.openList();

		expect( seen ).toHaveLength( 0 );
	} );

	it( 'toggleList() behaves exactly as before: always emits, alternating open/closed', () => {
		const panels = new Panels( document.createElement( 'div' ), config );
		const seen = [];

		panels.render();
		panels.on( 'listToggle', ( e ) => seen.push( e.open ) );

		panels.toggleList();
		panels.toggleList();
		panels.toggleList();

		expect( seen ).toEqual( [ true, false, true ] );
	} );
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
// openList() (rig verification finding): picking an address must show the
// sidebar regardless of what was on screen before — a stale card must never
// survive it. setAnchor() only re-sorts the list BODY; it never touched
// which panel was visible, so a card left open before an address search
// stayed open, invisible sorted list and all, behind it.
// -----------------------------------------------------------------------

describe( 'openList() (spec V-6/D-6 — the sidebar opens automatically on an address pick)', () => {
	it( 'shows the list and dismisses a card that was open', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );
		const g = group( 'g1', 55.75, 37.61, 'ПВЗ' );

		panels.render();
		panels.setVisible( [ g ] );
		panels.openCard( g, g.points[ 0 ].id );

		panels.openList();

		const stage = container.querySelector( '.woodev-pickup-stage' );

		expect( stage.className ).toContain( 'is-open' );
		expect( stage.className ).not.toContain( 'is-card' );
	} );

	it( 'is a no-op on the state itself when the list was already open', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );

		panels.render();
		panels.setVisible( [ group( 'g1', 55.75, 37.61, 'ПВЗ' ) ] );
		panels.toggleList();

		panels.openList();

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
	address: 'Адрес', services: 'Услуги', paymentMethods: 'Способы оплаты', howToGet: 'Как добраться',
	phone: 'Телефон', workTime: 'Часы работы', maxWeight: 'Максимальный вес', blocked: 'Недоступен',
	close: 'Закрыть',
} };

const point = ( over ) => Object.assign( {
	id: 'p1', name: 'ПВЗ «Магнит»', address: 'Москва, Ленина 5', short_address: 'Ленина, 5',
	postal_code: '101000', phone: '', instruction: '', work_time: '', max_weight: null,
	payment_methods: [], services: [], type: { code: 'pvz', label: 'ПВЗ' },
	selectable: { allowed: true, reason: null },
}, over );

/**
 * Finds the "Способы оплаты" card section by its title text (issue #200: payment methods now
 * share the exact same container/chip classes as services — `.woodev-pickup-card__services`/
 * `__service` — so scoping by class alone can no longer tell the two sections apart; scoping by
 * the section's own title, the way a sighted customer would, is what a query needs instead).
 *
 * @returns {HTMLElement|null}
 */
function paymentsSection( panels ) {
	const sections = [ ...panels.root.querySelectorAll( '.woodev-pickup-card__section' ) ];

	return sections.find(
		( s ) => s.querySelector( '.woodev-pickup-card__section-title' ).textContent === cardConfig.i18n.paymentMethods
	) || null;
}

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

describe( 'setSelectionBusy', () => {
	const busyConfig = { ...cardConfig, i18n: { ...cardConfig.i18n, confirming: 'Проверяем…' } };

	it( 'disables the CTA, swaps its label and locks the card', () => {
		const panels = mount( busyConfig );
		panels.openCard( { key: 'k', size: 1, points: [ point() ] } );

		panels.setSelectionBusy( true );

		const cta = panels.root.querySelector( '.woodev-pickup-card__cta' );

		expect( cta.disabled ).toBe( true );
		expect( cta.textContent ).toBe( 'Проверяем…' );
		expect( panels.root.querySelector( '.woodev-pickup-card' ).classList.contains( 'is-locked' ) ).toBe( true );
	} );

	it( 'restores the CTA when the request settles', () => {
		const panels = mount( busyConfig );
		panels.openCard( { key: 'k', size: 1, points: [ point() ] } );

		panels.setSelectionBusy( true );
		panels.setSelectionBusy( false );

		const cta = panels.root.querySelector( '.woodev-pickup-card__cta' );

		expect( cta.disabled ).toBe( false );
		expect( cta.textContent ).toBe( 'Забрать здесь' );
		expect( panels.root.querySelector( '.woodev-pickup-card' ).classList.contains( 'is-locked' ) ).toBe( false );
	} );

	it( 'does not emit select while busy, even if something bypasses the disabled attribute', () => {
		// As with the plain `selectable.allowed` guard above (see "the handler itself refuses..."),
		// a genuinely `disabled` native button never dispatches `click` to its listeners at all —
		// jsdom (like real browsers) suppresses it before any handler runs. Asserting against a
		// disabled CTA alone would only re-prove that suppression, not the independent behavioural
		// guard inside the click handler (`self._selectionBusy`). Force `disabled` off first so the
		// click actually reaches the listener, and confirm the handler still refuses on its own.
		const onSelect = jest.fn();
		const panels = mount( busyConfig );
		panels.on( 'select', onSelect );
		panels.openCard( { key: 'k', size: 1, points: [ point() ] } );

		panels.setSelectionBusy( true );

		const cta = panels.root.querySelector( '.woodev-pickup-card__cta' );
		cta.disabled = false;
		cta.click();

		expect( onSelect ).not.toHaveBeenCalled();
	} );

	it( 'does not throw when called before render()', () => {
		const panels = new Panels( document.createElement( 'div' ), busyConfig );

		expect( () => panels.setSelectionBusy( true ) ).not.toThrow();
	} );
} );

// -----------------------------------------------------------------------
// Task 9 (spec D-6/D-7): a domain refusal is remembered on the held point (so a re-render or a
// later tab switch still shows it); a transport failure is shown once and forgotten on the next
// render. `setPointVerdict()`/`showSelectionError()` are the two primitives that draw that line.
// -----------------------------------------------------------------------

describe( 'setPointVerdict', () => {
	it( 'writes the refusal into the held point so it survives a re-render', () => {
		const panels = mount( cardConfig );
		const g = { key: 'k', size: 1, points: [ point() ] };
		panels.setVisible( [ g ] );
		panels.openCard( g, 'p1', 'list' );

		panels.setPointVerdict( 'p1', { allowed: false, reason: 'Слишком тяжело' } );
		panels.openCard( g, 'p1', 'list' ); // full re-render, as a second click would do.

		expect( panels.root.querySelector( '.woodev-pickup-card__warning' ).textContent ).toBe( 'Слишком тяжело' );
		expect( panels.root.querySelector( '.woodev-pickup-card__cta' ).disabled ).toBe( true );
	} );

	it( 'leaves other points in the same group alone', () => {
		const panels = mount( cardConfig );
		const g = { key: 'k', size: 2, points: [ point( { id: 'a' } ), point( { id: 'b' } ) ] };
		panels.setVisible( [ g ] );

		panels.setPointVerdict( 'a', { allowed: false, reason: 'Нет' } );
		panels.openCard( g, 'b', 'list' );

		expect( panels.root.querySelector( '.woodev-pickup-card__cta' ).disabled ).toBe( false );
	} );
} );

describe( 'showSelectionError', () => {
	it( 'shows a transient message without disabling the CTA', () => {
		const panels = mount( cardConfig );
		panels.openCard( { key: 'k', size: 1, points: [ point() ] } );

		panels.showSelectionError( 'Не удалось. Попробуйте ещё раз.' );

		expect( panels.root.querySelector( '.woodev-pickup-card__warning' ).textContent )
			.toBe( 'Не удалось. Попробуйте ещё раз.' );
		expect( panels.root.querySelector( '.woodev-pickup-card__cta' ).disabled ).toBe( false );
	} );

	it( 'clears on the next card render — a failure is not a verdict', () => {
		const panels = mount( cardConfig );
		const g = { key: 'k', size: 1, points: [ point() ] };
		panels.openCard( g );

		panels.showSelectionError( 'Не удалось' );
		panels.openCard( g );

		expect( panels.root.querySelector( '.woodev-pickup-card__warning' ) ).toBeNull();
	} );

	it( 'does not throw when called before render()', () => {
		const panels = new Panels( document.createElement( 'div' ), cardConfig );

		expect( () => panels.showSelectionError( 'Не удалось' ) ).not.toThrow();
	} );
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
	expect( paymentsSection( panels ) ).toBeNull();
} );

it( 'renders phone, work time and a 2-decimal kilogram weight when present', () => {
	const panels = mount( cardConfig );
	panels.openCard( { key: 'k', size: 1, points: [ point( {
		phone: '+7 495 000-00-00', work_time: 'ежедневно 9:00-21:00', max_weight: 5000,
	} ) ] } );

	expect( panels.root.querySelector( '.woodev-pickup-card__phone' ).textContent ).toBe( '+7 495 000-00-00' );
	expect( panels.root.querySelector( '.woodev-pickup-card__worktime' ).textContent )
		.toBe( 'ежедневно 9:00-21:00' );
	expect( panels.root.querySelector( '.woodev-pickup-card__weight' ).textContent ).toBe( '5.00' );
} );

// -----------------------------------------------------------------------
// Issue #200: payment methods used to render as a bare `', '`-joined string while the
// neighbouring "Услуги" block was already chips — "два разных языка для двух однотипных
// списков" in the operator's own words. Both lists now share the SAME chip markup/classes
// (`buildChipList()`), so these tests mirror the existing "renders services as chips" one,
// scoped through {@see paymentsSection} since the classes alone no longer distinguish them.
// -----------------------------------------------------------------------

it( 'renders payment methods as chips, the same presentation as services', () => {
	const panels = mount( cardConfig );
	panels.openCard( { key: 'k', size: 1, points: [ point( { payment_methods: [ 'Картой', 'Наличными' ] } ) ] } );

	const section = paymentsSection( panels );

	expect( section ).not.toBeNull();
	expect( [ ...section.querySelectorAll( '.woodev-pickup-card__service' ) ].map( ( n ) => n.textContent ) )
		.toEqual( [ 'Картой', 'Наличными' ] );
} );

it( 'keeps payment and service chips in their own sections when both are present', () => {
	const panels = mount( cardConfig );
	panels.openCard( { key: 'k', size: 1, points: [ point( {
		payment_methods: [ 'Картой' ], services: [ 'Примерка', 'Частичный выкуп' ],
	} ) ] } );

	const paymentChips = [ ...paymentsSection( panels ).querySelectorAll( '.woodev-pickup-card__service' ) ]
		.map( ( n ) => n.textContent );

	expect( paymentChips ).toEqual( [ 'Картой' ] );
	// Total chips on the card = 1 payment + 2 services — proves the shared class did not merge
	// the two sections into one, only their MARKUP.
	expect( panels.root.querySelectorAll( '.woodev-pickup-card__service' ) ).toHaveLength( 3 );
} );

describe( 'sectioned card body (spec V-12)', () => {
	it( 'renders one titled section per populated field, in the fixed order', () => {
		const panels = mount( cardConfig );

		panels.openCard( { key: 'k', size: 1, points: [ point( {
			address: 'Москва, ул. Тверская, д. 5',
			payment_methods: [ 'Картой' ],
			services: [ 'Примерка' ],
			phone: '+7 495 000-00-00',
			work_time: 'ежедневно 9:00-21:00',
			max_weight: 5000,
		} ) ] } );

		const titles = [ ...panels.root.querySelectorAll( '.woodev-pickup-card__section-title' ) ]
			.map( ( el ) => el.textContent );

		expect( titles ).toEqual( [
			cardConfig.i18n.address,
			cardConfig.i18n.paymentMethods,
			cardConfig.i18n.services,
			cardConfig.i18n.phone,
			cardConfig.i18n.workTime,
			cardConfig.i18n.maxWeight,
		] );
	} );

	it( 'omits a section whose field is empty, keeping the rest', () => {
		const panels = mount( cardConfig );

		panels.openCard( { key: 'k', size: 1, points: [ point( { address: 'Москва, Тверская 5' } ) ] } );

		const titles = [ ...panels.root.querySelectorAll( '.woodev-pickup-card__section-title' ) ]
			.map( ( el ) => el.textContent );

		expect( titles ).toEqual( [ cardConfig.i18n.address ] );
	} );

	it( 'puts «Как добраться» inside the Адрес section, not as its own section', () => {
		const panels = mount( cardConfig );

		panels.openCard( { key: 'k', size: 1, points: [ point( {
			address: 'Москва, Тверская 5', instruction: 'Вход со двора',
		} ) ] } );

		const addressSection = panels.root.querySelector( '.woodev-pickup-card__section' );

		expect( addressSection.querySelector( '.woodev-pickup-card__howto' ) ).not.toBeNull();
		expect( panels.root.querySelectorAll( '.woodev-pickup-card__section' ) ).toHaveLength( 1 );
	} );

	// Issue #171: the chip is the framework's OWN glyph now, never conditional on a
	// plugin-supplied icon — it always renders, defaulting to the `warehouse` glyph, and a
	// plugin reaches the two override outcomes via `config.pointGlyphs`, not `pointIcons`
	// (that map still exists, but it now drives only the MAP's own marker pins).
	it( 'always renders the chip, defaulting to the warehouse glyph', () => {
		const panels = mount( cardConfig );
		panels.openCard( { key: 'k', size: 1, points: [ point( { type: { code: 'PVZ', label: 'ПВЗ' } } ) ] } );

		const chip = panels.root.querySelector( '.woodev-pickup-card__chip' );

		expect( chip ).not.toBeNull();
		expect( chip.querySelector( '.woodev-pickup-card__chip-icon svg' ) ).not.toBeNull();
	} );

	it( 'a plugin-supplied pointIcons map has no effect on the card chip any more', () => {
		const panels = mount( { ...cardConfig, pointIcons: { PVZ: { default: '/pvz.svg' } } } );
		panels.openCard( { key: 'k', size: 1, points: [ point( { type: { code: 'PVZ', label: 'ПВЗ' } } ) ] } );

		expect( panels.root.querySelector( '.woodev-pickup-card__chip img' ) ).toBeNull();
		expect( panels.root.querySelector( '.woodev-pickup-card__chip-icon svg' ) ).not.toBeNull();
	} );

	it( 'swaps in the OTHER built-in glyph via config.pointGlyphs', () => {
		const panels = mount( { ...cardConfig, pointGlyphs: { POSTAMAT: { glyph: 'package', markup: null } } } );
		panels.openCard( { key: 'k', size: 1, points: [ point( { type: { code: 'POSTAMAT', label: 'Постамат' } } ) ] } );

		const warehouseDefault = mount( cardConfig );
		warehouseDefault.openCard( { key: 'k', size: 1, points: [ point( { type: { code: 'PVZ', label: 'ПВЗ' } } ) ] } );

		const swapped = panels.root.querySelector( '.woodev-pickup-card__chip-icon svg' ).outerHTML;
		const defaulted = warehouseDefault.root.querySelector( '.woodev-pickup-card__chip-icon svg' ).outerHTML;

		expect( swapped ).not.toBe( defaulted );
	} );

	it( 'renders a plugin-supplied raw markup override verbatim, already sanitised server-side', () => {
		// jsdom's own innerHTML serializer re-emits a self-closing `<path/>` as `<path></path>`
		// on read-back — this is written with an explicit closing tag from the start so the
		// assertion below compares like with like rather than tripping over that normalisation.
		const customSvg = '<svg viewBox="0 0 24 24"><path d="M1 1"></path></svg>';
		const panels = mount( { ...cardConfig, pointGlyphs: { CUSTOM: { glyph: null, markup: customSvg } } } );
		panels.openCard( { key: 'k', size: 1, points: [ point( { type: { code: 'CUSTOM', label: 'Свой' } } ) ] } );

		expect( panels.root.querySelector( '.woodev-pickup-card__chip-icon' ).innerHTML ).toBe( customSvg );
	} );
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

// -----------------------------------------------------------------------
// Round 4 (operator live-review): "текст «Пункт выдачи заказов» не помещается в кнопку"
// (a real type label doesn't fit the segmented-control button when it competes with the chip
// and the close button for one row). Fix: chip + close share a FIRST row
// (`.woodev-pickup-card__header-row`), the tab bar gets its own row below, full header width —
// both still inside `.woodev-pickup-card__header`. `.woodev-pickup-card__header-row` is the only
// new class this round introduces.
// -----------------------------------------------------------------------

describe( 'card header layout (round 4 — chip+close row, tabs on their own row below)', () => {
	it( 'puts the chip and the close control inside one header-row, and the tabs OUTSIDE it', () => {
		const panels = mount( cardConfig );

		panels.openCard( { key: 'k', size: 2, points: [
			point( { id: 'a', type: { code: 'pvz', label: 'ПВЗ' } } ),
			point( { id: 'b', type: { code: 'postamat', label: 'Постамат' } } ),
		] } );

		const header = panels.root.querySelector( '.woodev-pickup-card__header' );
		const row = header.querySelector( '.woodev-pickup-card__header-row' );

		expect( row ).not.toBeNull();
		expect( row.querySelector( '.woodev-pickup-card__chip' ) ).not.toBeNull();
		expect( row.querySelector( '.woodev-pickup-card__close' ) ).not.toBeNull();

		// The tab bar is a SIBLING of the row, not nested inside it — it gets the header's full
		// width on its own line, which is the whole point of the fix.
		const tabs = header.querySelector( '.woodev-pickup-card__tabs' );

		expect( tabs ).not.toBeNull();
		expect( tabs.parentNode ).toBe( header );
		expect( row.contains( tabs ) ).toBe( false );
	} );

	// Issue #171: the chip is no longer conditional (see the "always renders the chip" test
	// above), so this now proves the header-row holds BOTH the chip and the close control even
	// with no tab bar — the "no chip" half of the old title is gone along with the behaviour.
	it( 'renders the header-row (chip + close, no tab bar) for a single-point group', () => {
		const panels = mount( cardConfig );
		panels.openCard( { key: 'k', size: 1, points: [ point() ] } );

		const row = panels.root.querySelector( '.woodev-pickup-card__header-row' );

		expect( row ).not.toBeNull();
		expect( row.querySelector( '.woodev-pickup-card__close' ) ).not.toBeNull();
		expect( row.querySelector( '.woodev-pickup-card__chip' ) ).not.toBeNull();
		expect( panels.root.querySelector( '.woodev-pickup-card__tabs' ) ).toBeNull();
	} );

	it( 'keeps the chip inside the header-row even without a tab bar (single-point group)', () => {
		const panels = mount( cardConfig );
		panels.openCard( { key: 'k', size: 1, points: [ point( { type: { code: 'pvz', label: 'ПВЗ' } } ) ] } );

		const row = panels.root.querySelector( '.woodev-pickup-card__header-row' );

		expect( row.querySelector( '.woodev-pickup-card__chip-icon svg' ) ).not.toBeNull();
		expect( panels.root.querySelector( '.woodev-pickup-card__tabs' ) ).toBeNull();
	} );

	it( 'the close control inside the header-row still closes the card', () => {
		const panels = mount( cardConfig );
		panels.openCard( { key: 'k', size: 1, points: [ point() ] } );
		panels.root.querySelector( '.woodev-pickup-card__header-row .woodev-pickup-card__close' ).click();

		expect( panels.root.parentNode.className ).not.toContain( 'is-card' );
	} );
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

// -----------------------------------------------------------------------
// Issue #199: co-located points sharing a type label used to fall back to the point's full
// NAME as the tab text ("Пункт выдачи заказов Яндекс Маркета" on live data — does not fit,
// gets clipped). The framework now NUMBERS the colliding labels instead
// ("ПВЗ 1"/"ПВЗ 2"); the domain may still override the base label per point via the optional
// `point_short_name` field. See `buildTabs()`'s own docblock for the full algorithm and the
// `??`-vs-`||` reasoning.
// -----------------------------------------------------------------------

it( 'numbers the tabs when two points in a group share a type label (types identical)', () => {
	const panels = mount( cardConfig );
	panels.openCard( { key: 'k', size: 2, points: [
		point( { id: 'a', name: 'ПВЗ «Магнит»' } ),
		point( { id: 'b', name: 'ПВЗ «Пятёрочка»' } ),
	] } );

	expect( [ ...panels.root.querySelectorAll( '.woodev-pickup-card__tab' ) ].map( ( t ) => t.textContent ) )
		.toEqual( [ 'ПВЗ 1', 'ПВЗ 2' ] );
} );

it( 'uses the domain-supplied point_short_name as the base label instead of the type label', () => {
	const panels = mount( cardConfig );
	panels.openCard( { key: 'k', size: 2, points: [
		point( { id: 'a', name: 'A', point_short_name: 'У метро' } ),
		point( { id: 'b', name: 'B', point_short_name: 'У дома' } ),
	] } );

	// Distinct short names -> distinct labels -> no numbering needed (types differ in effect).
	expect( [ ...panels.root.querySelectorAll( '.woodev-pickup-card__tab' ) ].map( ( t ) => t.textContent ) )
		.toEqual( [ 'У метро', 'У дома' ] );
} );

it( 'numbers colliding point_short_name values the same way it numbers type labels', () => {
	const panels = mount( cardConfig );
	panels.openCard( { key: 'k', size: 2, points: [
		point( { id: 'a', name: 'A', point_short_name: 'Терминал' } ),
		point( { id: 'b', name: 'B', point_short_name: 'Терминал' } ),
	] } );

	expect( [ ...panels.root.querySelectorAll( '.woodev-pickup-card__tab' ) ].map( ( t ) => t.textContent ) )
		.toEqual( [ 'Терминал 1', 'Терминал 2' ] );
} );

it( 'falls back to the type label when point_short_name is absent — same as an explicit empty string', () => {
	const absent = { key: 'k', size: 2, points: [
		point( { id: 'a', name: 'A' } ), // no point_short_name at all
		point( { id: 'b', name: 'B' } ),
	] };
	const empty = { key: 'k', size: 2, points: [
		point( { id: 'a', name: 'A', point_short_name: '' } ), // explicit '' — treated the same
		point( { id: 'b', name: 'B', point_short_name: '' } ),
	] };

	const withAbsent = mount( cardConfig );
	withAbsent.openCard( absent );
	const withEmpty = mount( cardConfig );
	withEmpty.openCard( empty );

	const labelsOf = ( p ) => [ ...p.root.querySelectorAll( '.woodev-pickup-card__tab' ) ].map( ( t ) => t.textContent );

	expect( labelsOf( withAbsent ) ).toEqual( [ 'ПВЗ 1', 'ПВЗ 2' ] );
	expect( labelsOf( withEmpty ) ).toEqual( labelsOf( withAbsent ) );
} );

it( 'renumbers over the VISIBLE subset only when the type filter hides a colliding tab', () => {
	// Three points sharing the SAME display label but DIFFERENT type codes (the exact live
	// shape #199 documents: 5post and Yandex Market both show as "ПВЗ" but are different
	// operators/codes underneath) — filtering out the middle one must renumber the survivors
	// down to 1/2, never leave a "ПВЗ 1"/"ПВЗ 3" gap.
	const panels = mount( filterConfig );
	const g = {
		key: 'k', size: 3,
		points: [
			point( { id: 'a', name: 'A', type: { code: 'pvz-a', label: 'ПВЗ' } } ),
			point( { id: 'b', name: 'B', type: { code: 'pvz-b', label: 'ПВЗ' } } ),
			point( { id: 'c', name: 'C', type: { code: 'pvz-c', label: 'ПВЗ' } } ),
		],
	};

	panels.setTypes( [
		{ code: 'pvz-a', label: 'ПВЗ' }, { code: 'pvz-b', label: 'ПВЗ' }, { code: 'pvz-c', label: 'ПВЗ' },
	] );
	panels.openCard( g );

	expect( [ ...panels.root.querySelectorAll( '.woodev-pickup-card__tab' ) ].map( ( t ) => t.textContent ) )
		.toEqual( [ 'ПВЗ 1', 'ПВЗ 2', 'ПВЗ 3' ] );

	panels.root.querySelectorAll( '.woodev-pickup-filter__checkbox' )
		.forEach( ( box ) => {
			if ( 'pvz-b' === box.dataset.code ) {
				box.click();
			}
		} );

	expect( [ ...panels.root.querySelectorAll( '.woodev-pickup-card__tab' ) ].map( ( t ) => t.textContent ) )
		.toEqual( [ 'ПВЗ 1', 'ПВЗ 2' ] );
} );

it( 'gives a numbered tab an aria-label with the point\'s own name, distinguishing it for a screen reader', () => {
	const panels = mount( cardConfig );
	panels.openCard( { key: 'k', size: 2, points: [
		point( { id: 'a', name: 'ПВЗ «Магнит»' } ),
		point( { id: 'b', name: 'ПВЗ «Пятёрочка»' } ),
	] } );

	const tabs = [ ...panels.root.querySelectorAll( '.woodev-pickup-card__tab' ) ];

	expect( tabs[ 0 ].getAttribute( 'aria-label' ) ).toBe( 'ПВЗ «Магнит»' );
	expect( tabs[ 1 ].getAttribute( 'aria-label' ) ).toBe( 'ПВЗ «Пятёрочка»' );
} );

it( 'does not add an aria-label override to a non-colliding tab (types already unambiguous)', () => {
	const panels = mount( cardConfig );
	panels.openCard( two );

	const tabs = [ ...panels.root.querySelectorAll( '.woodev-pickup-card__tab' ) ];

	expect( tabs[ 0 ].hasAttribute( 'aria-label' ) ).toBe( false );
	expect( tabs[ 1 ].hasAttribute( 'aria-label' ) ).toBe( false );
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
	const addresses = [ { displayName: 'A' }, { displayName: 'B' }, { displayName: 'C' } ];

	panels.on( 'searchAddressPicked', ( i ) => seen.push( i ) );
	panels.renderSearchResults( { points: [], addresses } );

	// Click the third, then re-render and click the first: a hardcoded `1`, an always-zero, or an
	// always-last emitter each survive a single click on index 1. The re-render between clicks is
	// round 2's own change (D1e): a pick now closes the results box
	// ({@see Panels.prototype.hideSearchResults}), so a SECOND independent pick needs the box
	// showing again first — exactly what the mount does before the customer can pick again.
	layout.querySelectorAll( '.woodev-pickup-search__item' )[ 2 ].click();

	panels.renderSearchResults( { points: [], addresses } );
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

// -----------------------------------------------------------------------
// Round 3 (operator live-review, defect A): "«Поиск не дал результатов.» ... висит так до тех
// пор пока не нажмёшь иконку «лупа» или «крестик»." The debounced local match (`searchType`) is
// a PREVIEW that has not spent the geocoding quota — matching nothing while the customer is
// still typing is the NORMAL case, not a verdict. `previewSearchResults()` is the fix: it shares
// `renderSearchResults()`'s painting but never shows `noResults` — an empty preview closes the
// box outright instead. The empty/`noResults` verdict stays exclusive to `renderSearchResults()`,
// the COMPLETED-search half of the pair (a real submit that genuinely found nothing).
// -----------------------------------------------------------------------

describe( 'previewSearchResults() (round 3, defect A)', () => {
	it( 'shows matching points, same as a completed search', () => {
		const panels = mount( searchConfig );
		const layout = panels.buildSearchLayout();

		panels.previewSearchResults( { points: [ point() ], addresses: [] } );

		expect( layout.querySelector( '.woodev-pickup-search__section--points' ) ).not.toBeNull();
		expect( layout.querySelector( '.woodev-pickup-search__results' ).hidden ).toBe( false );
	} );

	it( 'shows nothing at all — never the noResults verdict — when nothing matches yet', () => {
		const panels = mount( searchConfig );
		const layout = panels.buildSearchLayout();

		panels.previewSearchResults( { points: [], addresses: [] } );

		const results = layout.querySelector( '.woodev-pickup-search__results' );

		expect( results.hidden ).toBe( true );
		expect( results.textContent ).toBe( '' );
	} );

	it( 'hides an already-open preview the instant a later keystroke matches nothing', () => {
		// The operator's own repro: results appear while typing, then the next keystroke matches
		// nothing — the box must close immediately, not keep showing a stale (or noResults) state.
		const panels = mount( searchConfig );
		const layout = panels.buildSearchLayout();

		panels.previewSearchResults( { points: [ point() ], addresses: [] } );
		expect( layout.querySelector( '.woodev-pickup-search__results' ).hidden ).toBe( false );

		panels.previewSearchResults( { points: [], addresses: [] } );

		const results = layout.querySelector( '.woodev-pickup-search__results' );

		expect( results.hidden ).toBe( true );
		expect( results.textContent ).toBe( '' );
	} );

	it( 'never renders the noResults text, even called repeatedly with empty results', () => {
		const panels = mount( searchConfig );
		const layout = panels.buildSearchLayout();

		panels.previewSearchResults( { points: [], addresses: [] } );
		panels.previewSearchResults( { points: [], addresses: [] } );

		expect( layout.querySelector( '.woodev-pickup-search__empty' ) ).toBeNull();
	} );

	it( 'is a no-op before buildSearchLayout() has ever run', () => {
		const panels = mount( searchConfig );

		expect( () => panels.previewSearchResults( { points: [ point() ], addresses: [] } ) ).not.toThrow();
	} );

	it( 'a completed search (renderSearchResults) still shows noResults for a genuinely empty result', () => {
		// Regression guard: splitting the two methods must not have broken the LEGITIMATE
		// empty-verdict case for a search that actually ran and actually found nothing.
		const panels = mount( searchConfig );
		const layout = panels.buildSearchLayout();

		panels.renderSearchResults( { points: [], addresses: [] } );

		expect( layout.querySelector( '.woodev-pickup-search__results' ).textContent )
			.toBe( searchConfig.i18n.noResults );
	} );
} );

// -----------------------------------------------------------------------
// Round 2 (D1a/D1e): the results box never used to close — a clear round-trip through
// `renderSearchResults()` re-opened it the instant it was told to close. `hideSearchResults()`
// is the fix: empties + hides, and is the ONLY thing every closing route (a point pick, an
// address pick, the reset button, focusout) now calls — never `renderSearchResults()` itself.
// -----------------------------------------------------------------------

describe( 'hideSearchResults() (round 2, D1a/D1e)', () => {
	it( 'empties and hides an open results box', () => {
		const panels = mount( searchConfig );
		const layout = panels.buildSearchLayout();
		panels.renderSearchResults( { points: [ point() ], addresses: [] } );

		panels.hideSearchResults();

		const results = layout.querySelector( '.woodev-pickup-search__results' );

		expect( results.hidden ).toBe( true );
		expect( results.children ).toHaveLength( 0 );
	} );

	it( 'never renders the noResults empty state — that stays renderSearchResults()\'s job alone', () => {
		const panels = mount( searchConfig );
		const layout = panels.buildSearchLayout();
		panels.renderSearchResults( { points: [], addresses: [] } ); // a genuinely empty completed search.

		expect( layout.querySelector( '.woodev-pickup-search__results' ).textContent )
			.toBe( searchConfig.i18n.noResults );

		panels.hideSearchResults();

		expect( layout.querySelector( '.woodev-pickup-search__results' ).textContent ).toBe( '' );
	} );

	it( 'is a no-op before buildSearchLayout() has ever run', () => {
		const panels = mount( searchConfig );

		expect( () => panels.hideSearchResults() ).not.toThrow();
	} );

	it( 'closes the results box on a search point pick', () => {
		const panels = mount( searchConfig );
		const layout = panels.buildSearchLayout();
		panels.renderSearchResults( { points: [ point() ], addresses: [] } );

		layout.querySelector( '.woodev-pickup-search__item--point' ).click();

		expect( layout.querySelector( '.woodev-pickup-search__results' ).hidden ).toBe( true );
	} );

	it( 'closes the results box on a search address pick', () => {
		const panels = mount( searchConfig );
		const layout = panels.buildSearchLayout();
		panels.renderSearchResults( { points: [], addresses: [ { displayName: 'Москва' } ] } );

		layout.querySelector( '.woodev-pickup-search__item--address' ).click();

		expect( layout.querySelector( '.woodev-pickup-search__results' ).hidden ).toBe( true );
	} );

	it( 'the reset button closes the results box without reopening it', () => {
		// Re-pointed/strengthened (round 2, D1e): "resetting clears the value, the results and the
		// anchor" (above) already proves `input.value`/`onReset`; this proves the box specifically
		// never shows the noResults empty state as a side effect of clearing.
		const panels = mount( searchConfig );
		const layout = panels.buildSearchLayout();
		panels.renderSearchResults( { points: [ point() ], addresses: [] } );

		layout.querySelector( '.woodev-pickup-search__reset' ).click();

		const results = layout.querySelector( '.woodev-pickup-search__results' );

		expect( results.hidden ).toBe( true );
		expect( results.textContent ).toBe( '' );
	} );

	it( 'focusout closes the results box once focus leaves the search wrap entirely', () => {
		const panels = mount( searchConfig );
		const layout = panels.buildSearchLayout();
		document.body.appendChild( layout );
		panels.renderSearchResults( { points: [ point() ], addresses: [] } );

		const search = layout.querySelector( '.woodev-pickup-search' );
		const outside = document.createElement( 'button' );
		document.body.appendChild( outside );

		search.dispatchEvent( new FocusEvent( 'focusout', { relatedTarget: outside, bubbles: true } ) );

		expect( layout.querySelector( '.woodev-pickup-search__results' ).hidden ).toBe( true );
	} );

	it( 'focusout does NOT close the box when focus only moved to another element inside the wrap', () => {
		const panels = mount( searchConfig );
		const layout = panels.buildSearchLayout();
		document.body.appendChild( layout );
		panels.renderSearchResults( { points: [ point() ], addresses: [] } );

		const search = layout.querySelector( '.woodev-pickup-search' );
		const submit = layout.querySelector( '.woodev-pickup-search__submit' );

		search.dispatchEvent( new FocusEvent( 'focusout', { relatedTarget: submit, bubbles: true } ) );

		expect( layout.querySelector( '.woodev-pickup-search__results' ).hidden ).toBe( false );
	} );

	it( 'focusout does NOT close the box when relatedTarget is null (focus left the document)', () => {
		// A null relatedTarget means focus left the DOCUMENT entirely — an alt-tab, a click on
		// browser chrome — not "the customer moved on". Treating it as a close would blank the
		// results out from under a customer who only switched tabs mid-search.
		const panels = mount( searchConfig );
		const layout = panels.buildSearchLayout();
		document.body.appendChild( layout );
		panels.renderSearchResults( { points: [ point() ], addresses: [] } );

		const search = layout.querySelector( '.woodev-pickup-search' );

		search.dispatchEvent( new FocusEvent( 'focusout', { relatedTarget: null, bubbles: true } ) );

		expect( layout.querySelector( '.woodev-pickup-search__results' ).hidden ).toBe( false );
	} );

	// -------------------------------------------------------------------
	// Round 4 (operator live-review): "результат поиска (список) висит открытым пока не выбрать
	// из списка, т.е. потеря фокуса из поля поиска не закрывает список результата поиска." The
	// customer's actual dismissal gesture is clicking the MAP, which does not move DOM focus at
	// all, so `focusout` alone never fired. Same pattern already proven for the filter menu: a
	// `document`-level outside-click listener.
	// -------------------------------------------------------------------

	it( 'closes on a click outside the search wrap — the map-click case focusout alone cannot cover', () => {
		const panels = mount( searchConfig );
		const layout = panels.buildSearchLayout();
		document.body.appendChild( layout );
		panels.renderSearchResults( { points: [ point() ], addresses: [] } );

		const outside = document.createElement( 'div' );
		document.body.appendChild( outside );

		outside.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );

		expect( layout.querySelector( '.woodev-pickup-search__results' ).hidden ).toBe( true );
	} );

	it( 'does NOT close on a click inside the search wrap (e.g. the input or a result row)', () => {
		const panels = mount( searchConfig );
		const layout = panels.buildSearchLayout();
		document.body.appendChild( layout );
		panels.renderSearchResults( { points: [ point() ], addresses: [] } );

		const input = layout.querySelector( '.woodev-pickup-search__input' );

		input.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );

		expect( layout.querySelector( '.woodev-pickup-search__results' ).hidden ).toBe( false );
	} );

	it( 'an outside click does not open/close the filter menu as a side effect', () => {
		const panels = mount( filterConfig );
		const layout = panels.buildSearchLayout();
		document.body.appendChild( layout );
		panels.setTypes( twoTypes );
		panels.renderSearchResults( { points: [ point() ], addresses: [] } );

		const menu = layout.querySelector( '.woodev-pickup-filter__menu' );
		const outside = document.createElement( 'div' );
		document.body.appendChild( outside );

		outside.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );

		expect( layout.querySelector( '.woodev-pickup-search__results' ).hidden ).toBe( true );
		expect( menu.hidden ).toBe( true ); // untouched — was never opened, must not become opened.
	} );

	it( 'destroy() removes the outside-click listener so a later click cannot reach a dead instance', () => {
		const panels = mount( searchConfig );
		const layout = panels.buildSearchLayout();
		document.body.appendChild( layout );
		panels.renderSearchResults( { points: [ point() ], addresses: [] } );

		const results = layout.querySelector( '.woodev-pickup-search__results' );
		const outside = document.createElement( 'div' );
		document.body.appendChild( outside );

		expect( results.hidden ).toBe( false );

		panels.destroy();
		outside.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );

		// destroy() itself never touches `results.hidden` — if the listener had survived, this
		// click would have flipped it to `true` anyway, proving it is truly gone (same "listener
		// outlives its instance" class of bug as the search debounce timer, see `destroy()`'s own
		// docblock).
		expect( results.hidden ).toBe( false );
	} );
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

describe( 'search lifecycle (Codex critic findings)', () => {
	it( 'submitting cancels a debounce still in flight, so one query runs one path', () => {
		jest.useFakeTimers();

		const panels = new Panels( document.createElement( 'div' ), config );
		const el = panels.buildSearchLayout();
		const onType = jest.fn();
		const onSubmit = jest.fn();

		panels.on( 'searchType', onType );
		panels.on( 'searchSubmit', onSubmit );

		const input = el.querySelector( '.woodev-pickup-search__input' );
		input.value = 'Тверская';
		input.dispatchEvent( new Event( 'input' ) );

		// Enter, inside the debounce window.
		el.querySelector( 'form' ).dispatchEvent( new Event( 'submit', { cancelable: true } ) );
		jest.advanceTimersByTime( 1000 );

		expect( onSubmit ).toHaveBeenCalledTimes( 1 );
		// The local keystroke result would otherwise land AFTER the geocoder's and overwrite the
		// richer answer with the poorer one.
		expect( onType ).not.toHaveBeenCalled();

		jest.useRealTimers();
	} );

	it( 'destroy() cancels a pending debounce so it never fires against a dead instance', () => {
		jest.useFakeTimers();

		const panels = new Panels( document.createElement( 'div' ), config );
		const el = panels.buildSearchLayout();
		const onType = jest.fn();

		panels.on( 'searchType', onType );

		const input = el.querySelector( '.woodev-pickup-search__input' );
		input.value = 'Тверская';
		input.dispatchEvent( new Event( 'input' ) );

		panels.destroy();
		jest.advanceTimersByTime( 1000 );

		expect( onType ).not.toHaveBeenCalled();

		jest.useRealTimers();
	} );

	it( 'destroy() drops the listeners and detaches the stage, and is idempotent', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );
		const onToggle = jest.fn();

		panels.render();
		panels.on( 'listToggle', onToggle );
		panels.destroy();

		expect( container.children ).toHaveLength( 0 );

		panels.toggleList();
		expect( onToggle ).not.toHaveBeenCalled();

		expect( () => panels.destroy() ).not.toThrow();
	} );

	it( 'destroy() is safe before render() ever ran', () => {
		expect( () => new Panels( document.createElement( 'div' ), config ).destroy() ).not.toThrow();
	} );
} );

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

	it( 'labels the reset button from i18n, not a hardcoded default', () => {
		const el = build();

		expect( el.querySelector( '.woodev-pickup-search__reset' ).getAttribute( 'aria-label' ) )
			.toBe( config.i18n.resetSearch );
	} );

	// The magnifier was removed (operator, 07.08.2026) — Enter and a phone's "Перейти" key submit
	// the form, which was always the same path the button used. A regression that brought the
	// button back would also bring back the `search` i18n key it was the only consumer of.
	it( 'has no submit button at all — the form itself is the whole submit path', () => {
		const el = build();

		expect( el.querySelector( '.woodev-pickup-search__submit' ) ).toBeNull();
		expect( el.querySelector( 'button[type="submit"]' ) ).toBeNull();
	} );

	it( 'renders blank aria-labels, not a hardcoded Russian default, when the i18n keys are missing', () => {
		const el = build( { i18n: {} } );

		expect( el.querySelector( '.woodev-pickup-search__input' ).getAttribute( 'placeholder' ) ).toBe( '' );
		expect( el.querySelector( '.woodev-pickup-search__reset' ).getAttribute( 'aria-label' ) ).toBe( '' );
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

	// -------------------------------------------------------------------
	// Round 2 (D1c): inline Lucide SVG icons, replacing the CSS `content` emoji the operator
	// called "стиль аля web 2000".
	// -------------------------------------------------------------------

	it( 'draws the reset button as an inline Lucide x glyph, not text or CSS content', () => {
		const el = build();
		const reset = el.querySelector( '.woodev-pickup-search__reset' );
		const svg = reset.querySelector( 'svg' );

		expect( svg ).not.toBeNull();
		expect( svg.getAttribute( 'viewBox' ) ).toBe( '0 0 24 24' );
		expect( svg.getAttribute( 'stroke' ) ).toBe( 'currentColor' );
		expect( reset.textContent.trim() ).toBe( '' );
	} );

	// -------------------------------------------------------------------
	// Round 2 (D1d): the submit button's disabled state machine.
	// -------------------------------------------------------------------

} );

// -----------------------------------------------------------------------
// Task 16: the type filter menu (D-10)
// -----------------------------------------------------------------------

const filterConfig = { lang: 'ru_RU', i18n: {
	drawerTitle: 'Пункты выдачи в этой области', emptyInView: 'В этой области пунктов выдачи нет',
	filterTypes: 'Тип пунктов', allTypes: 'Все типы пунктов',
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

// Re-pointed (SP-5 round 2, plan D2 — the operator's own live-review finding): with exactly the
// two types this project's own fixture ships (PVZ, POSTAMAT), "partial" can only ever mean
// "1 of 2 selected" — a numeric badge on a two-type carrier is arithmetically incapable of ever
// reading as anything but a permanently-stuck "1". The badge now shows a number only at 3+ known
// types; below that, `.is-filtered` on the toggle alone carries the "something is filtered" signal.
it( 'adds is-filtered to the toggle when partial, but keeps the numeric badge hidden with only two types', () => {
	const panels = mount( filterConfig );
	panels.setTypes( [ { code: 'pvz', label: 'ПВЗ' }, { code: 'postamat', label: 'Постамат' } ] );

	const toggle = panels.root.querySelector( '.woodev-pickup-filter__toggle' );
	const badge = panels.root.querySelector( '.woodev-pickup-filter__badge' );

	expect( toggle.classList.contains( 'is-filtered' ) ).toBe( false );
	expect( badge.hidden ).toBe( true );

	panels.root.querySelectorAll( '.woodev-pickup-filter__checkbox' )[ 0 ].click();

	expect( toggle.classList.contains( 'is-filtered' ) ).toBe( true );
	expect( badge.hidden ).toBe( true );
} );

it( 'removes is-filtered again once every type is reselected', () => {
	const panels = mount( filterConfig );
	panels.setTypes( [ { code: 'pvz', label: 'ПВЗ' }, { code: 'postamat', label: 'Постамат' } ] );

	const toggle = panels.root.querySelector( '.woodev-pickup-filter__toggle' );
	const boxes = [ ...panels.root.querySelectorAll( '.woodev-pickup-filter__checkbox' ) ];

	boxes[ 0 ].click();
	expect( toggle.classList.contains( 'is-filtered' ) ).toBe( true );

	boxes[ 0 ].click();
	expect( toggle.classList.contains( 'is-filtered' ) ).toBe( false );
} );

it( 'shows the numeric badge once there are 3+ types and the selection is partial', () => {
	const panels = mount( filterConfig );
	panels.setTypes( [
		{ code: 'pvz', label: 'ПВЗ' }, { code: 'postamat', label: 'Постамат' }, { code: 'locker', label: 'Локер' },
	] );

	const toggle = panels.root.querySelector( '.woodev-pickup-filter__toggle' );
	const badge = panels.root.querySelector( '.woodev-pickup-filter__badge' );

	panels.root.querySelectorAll( '.woodev-pickup-filter__checkbox' )[ 0 ].click();

	expect( toggle.classList.contains( 'is-filtered' ) ).toBe( true );
	expect( badge.hidden ).toBe( false );
	expect( badge.textContent ).toBe( '2' );
} );

it( 'writes data-checked on each filter row, kept in sync with its own checkbox', () => {
	const panels = mount( filterConfig );
	panels.setTypes( [ { code: 'pvz', label: 'ПВЗ' }, { code: 'postamat', label: 'Постамат' } ] );

	const rows = [ ...panels.root.querySelectorAll( '.woodev-pickup-filter__row' ) ];

	expect( rows.map( ( r ) => r.dataset.checked ) ).toEqual( [ 'true', 'true' ] );

	panels.root.querySelectorAll( '.woodev-pickup-filter__checkbox' )[ 0 ].click();

	expect( rows[ 0 ].dataset.checked ).toBe( 'false' );
	expect( rows[ 1 ].dataset.checked ).toBe( 'true' );
} );

it( 'leaves data-checked alone when the last-checked uncheck is refused', () => {
	const panels = mount( filterConfig );
	panels.setTypes( [ { code: 'pvz', label: 'ПВЗ' }, { code: 'postamat', label: 'Постамат' } ] );

	const rows = [ ...panels.root.querySelectorAll( '.woodev-pickup-filter__row' ) ];
	const boxes = [ ...panels.root.querySelectorAll( '.woodev-pickup-filter__checkbox' ) ];

	boxes[ 0 ].click();
	boxes[ 1 ].click(); // refused: pvz is the last checked type left.

	expect( rows[ 1 ].dataset.checked ).toBe( 'true' );
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
// Task 13 (spec V-8): the filter moves into the SAME control as search — one `SearchControl`
// layout, two menus (the button/menu pair below, and the search field's own results dropdown),
// rather than the filter living in the list panel as it did above. `buildSearchLayout()` must be
// called before `setTypes()` for the filter to land inside its returned tree — real usage
// (`pickup-mount.js`) always calls them in that order. Kept event name/payload: `typeFilterChange`
// with a plain array of codes — the plan's own draft used `typeFilter`/`{ types }`, but that would
// require rewiring `pickup-mount.js`'s already-tested routing (bulk vs viewport, D-10) for no
// behavioural gain, so this file keeps the shape the mount already speaks.
// -----------------------------------------------------------------------

const twoTypes = [
	{ code: 'pvz', label: 'ПВЗ' },
	{ code: 'postamat', label: 'Постамат' },
];

/**
 * Builds a fresh `Panels`, calls `buildSearchLayout()` (search enabled), then `setTypes()` — the
 * exact call order `pickup-mount.js` uses, and the order every test below relies on for the filter
 * to land inside the returned layout element rather than falling back to the list panel.
 *
 * Appends the returned layout to `document.body` — same reason `mount()` above appends `_stage`:
 * a detached checkbox's `.click()` flips its own `.checked` (the browser's native default action)
 * but jsdom does not dispatch the follow-up `change` event unless the element is connected to a
 * document, and `handleFilterCheckboxChange()` (the refuse-the-last-uncheck rule, the emit) is
 * wired to `change`, not `click`.
 */
function layoutWith( types ) {
	const panels = new Panels( document.createElement( 'div' ), filterConfig );
	const el = panels.buildSearchLayout();

	if ( el ) {
		document.body.appendChild( el );
	}

	panels.setTypes( types );

	return { panels, el };
}

it( 'renders no filter button for a single type, inside the search layout', () => {
	const { el } = layoutWith( [ twoTypes[ 0 ] ] );

	expect( el.querySelector( '.woodev-pickup-filter' ) ).toBeNull();
} );

it( 'renders a toggle and a checkbox per type for two types, inside the search layout', () => {
	const { el } = layoutWith( twoTypes );

	expect( el.querySelectorAll( '.woodev-pickup-filter__checkbox' ) ).toHaveLength( 2 );
	expect( el.querySelector( '.woodev-pickup-filter__toggle' ) ).not.toBeNull();
} );

it( 'labels the toggle from allTypes and the menu title from filterTypes — two different i18n keys', () => {
	const { el } = layoutWith( twoTypes );

	expect( el.querySelector( '.woodev-pickup-filter__toggle' ).getAttribute( 'aria-label' ) )
		.toBe( 'Все типы пунктов' );
	expect( el.querySelector( '.woodev-pickup-filter__title' ).textContent ).toBe( 'Тип пунктов' );
} );

it( 'refuses to uncheck the last remaining type and emits typeFilterChange only for accepted changes', () => {
	const { panels, el } = layoutWith( twoTypes );
	const onFilter = jest.fn();

	panels.on( 'typeFilterChange', onFilter );

	const boxes = [ ...el.querySelectorAll( '.woodev-pickup-filter__checkbox' ) ];
	boxes[ 0 ].click();
	boxes[ 1 ].click();

	expect( boxes[ 1 ].checked ).toBe( true );
	expect( onFilter ).toHaveBeenCalledTimes( 1 );
	expect( onFilter ).toHaveBeenLastCalledWith( [ 'postamat' ] );
} );

it( 'closes the results menu when the filter menu opens', () => {
	const { el } = layoutWith( twoTypes );

	el.querySelector( '.woodev-pickup-search__results' ).hidden = false;
	el.querySelector( '.woodev-pickup-filter__toggle' ).click();

	expect( el.querySelector( '.woodev-pickup-search__results' ).hidden ).toBe( true );
	expect( el.querySelector( '.woodev-pickup-filter__menu' ).hidden ).toBe( false );
} );

it( 'closes the filter menu when the results menu opens', () => {
	const { panels, el } = layoutWith( twoTypes );

	el.querySelector( '.woodev-pickup-filter__toggle' ).click();
	panels.renderSearchResults( { points: [], addresses: [ { displayName: 'Somewhere' } ] } );

	expect( el.querySelector( '.woodev-pickup-filter__menu' ).hidden ).toBe( true );
} );

it( 'toggle click closes the menu again on a second click', () => {
	const { el } = layoutWith( twoTypes );
	const toggle = el.querySelector( '.woodev-pickup-filter__toggle' );
	const menu = el.querySelector( '.woodev-pickup-filter__menu' );

	toggle.click();
	expect( menu.hidden ).toBe( false );

	toggle.click();
	expect( menu.hidden ).toBe( true );
} );

// -----------------------------------------------------------------------
// Round 3 (operator live-review, defect B): "Когда фокус теряется с фильтра, список фильтров
// должен автоматически закрыться, а не висеть постоянно открытым, пока не кликнешь на иконку
// «Фильтр»." Fixed with the same care already applied to the search results box: `focusout`
// (relatedTarget outside the wrap counts as leaving; `null` does not), PLUS a document-level
// outside-click listener, since the customer's most likely dismissal gesture — clicking the
// map — does not necessarily move DOM focus at all.
// -----------------------------------------------------------------------

describe( 'filter menu auto-closes (round 3, defect B)', () => {
	it( 'closes on focusout once focus leaves the wrap entirely', () => {
		const { el } = layoutWith( twoTypes );
		const toggle = el.querySelector( '.woodev-pickup-filter__toggle' );
		const menu = el.querySelector( '.woodev-pickup-filter__menu' );
		const wrap = el.querySelector( '.woodev-pickup-filter' );
		const outside = document.createElement( 'button' );

		document.body.appendChild( outside );
		toggle.click();
		expect( menu.hidden ).toBe( false );

		wrap.dispatchEvent( new FocusEvent( 'focusout', { relatedTarget: outside, bubbles: true } ) );

		expect( menu.hidden ).toBe( true );
	} );

	it( 'does NOT close on focusout when focus only moved to another element inside the wrap', () => {
		const { el } = layoutWith( twoTypes );
		const toggle = el.querySelector( '.woodev-pickup-filter__toggle' );
		const menu = el.querySelector( '.woodev-pickup-filter__menu' );
		const wrap = el.querySelector( '.woodev-pickup-filter' );
		const checkbox = el.querySelector( '.woodev-pickup-filter__checkbox' );

		toggle.click();
		wrap.dispatchEvent( new FocusEvent( 'focusout', { relatedTarget: checkbox, bubbles: true } ) );

		expect( menu.hidden ).toBe( false );
	} );

	it( 'does NOT close on focusout when relatedTarget is null (focus left the document)', () => {
		const { el } = layoutWith( twoTypes );
		const toggle = el.querySelector( '.woodev-pickup-filter__toggle' );
		const menu = el.querySelector( '.woodev-pickup-filter__menu' );
		const wrap = el.querySelector( '.woodev-pickup-filter' );

		toggle.click();
		wrap.dispatchEvent( new FocusEvent( 'focusout', { relatedTarget: null, bubbles: true } ) );

		expect( menu.hidden ).toBe( false );
	} );

	it( 'closes on a click outside the wrap — the map-click case focusout alone cannot cover', () => {
		const { el } = layoutWith( twoTypes );
		const toggle = el.querySelector( '.woodev-pickup-filter__toggle' );
		const menu = el.querySelector( '.woodev-pickup-filter__menu' );
		const outside = document.createElement( 'div' );

		document.body.appendChild( outside );
		toggle.click();
		expect( menu.hidden ).toBe( false );

		outside.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );

		expect( menu.hidden ).toBe( true );
	} );

	it( 'does NOT close on a click inside the wrap (e.g. a checkbox row)', () => {
		const { el } = layoutWith( twoTypes );
		const toggle = el.querySelector( '.woodev-pickup-filter__toggle' );
		const menu = el.querySelector( '.woodev-pickup-filter__menu' );
		const row = el.querySelector( '.woodev-pickup-filter__row' );

		toggle.click();
		row.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );

		expect( menu.hidden ).toBe( false );
	} );

	it( 'destroy() removes the outside-click listener so a later click cannot reach a dead instance', () => {
		const { panels, el } = layoutWith( twoTypes );
		const toggle = el.querySelector( '.woodev-pickup-filter__toggle' );
		const menu = el.querySelector( '.woodev-pickup-filter__menu' );
		const outside = document.createElement( 'div' );

		document.body.appendChild( outside );
		toggle.click();
		expect( menu.hidden ).toBe( false );

		panels.destroy();

		outside.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );

		// destroy() itself never touches `menu.hidden` — if the listener had survived destroy(),
		// this click would have flipped it to `true` anyway, proving it is truly gone rather than
		// merely harmless (this file has already been bitten once by a listener that outlived its
		// instance — the search debounce timer, see `destroy()`'s own docblock).
		expect( menu.hidden ).toBe( false );
	} );
} );

it( 'falls back to the list panel when the plugin disabled search entirely', () => {
	// A carrier without a geocoding budget (config.search === false) still gets two point types —
	// buildSearchLayout() returns null (spec V-6) and has nowhere to hand the filter, so it must
	// still have SOME home rather than vanishing (see the task report for this decision).
	const panels = mount( { ...filterConfig, search: false } );

	expect( panels.buildSearchLayout() ).toBeNull();

	const seen = [];
	panels.on( 'typeFilterChange', ( codes ) => seen.push( codes ) );
	panels.setTypes( twoTypes );

	const filterEl = panels.root.querySelector( '.woodev-pickup-filter' );

	expect( filterEl ).not.toBeNull();

	panels.root.querySelectorAll( '.woodev-pickup-filter__checkbox' )[ 0 ].click();

	expect( seen ).toEqual( [ [ 'postamat' ] ] );
} );

// -----------------------------------------------------------------------
// Round 3 (coordinator fix, found live on the rig): the type filter reached the map's markers
// (provider-side, by GROUP) but not the sidebar list or an open card's tab bar — point-level
// filtering, and this file is the only place that renders individual points. A co-located group
// holding a PVZ and a postomat correctly stays on the map once POSTAMAT is unchecked (it still
// has a visible PVZ), but the excluded postomat POINT inside it must stop being offered.
// -----------------------------------------------------------------------

describe( 'type filter also gates the sidebar list and card (round 3, coordinator fix)', () => {
	const mixedGroup = () => ( {
		key: 'mixed', lat: 55.75, lng: 37.61, size: 2,
		points: [
			{
				id: 'pvz-1', name: 'ПВЗ «Магнит»', short_address: 'Б. Татарская, 9',
				type: { code: 'pvz', label: 'ПВЗ' }, selectable: { allowed: true, reason: null },
			},
			{
				id: 'postamat-1', name: 'Постамат «Замоскворечье»', short_address: 'Б. Татарская, 9',
				type: { code: 'postamat', label: 'Постамат' }, selectable: { allowed: true, reason: null },
			},
		],
	} );

	const soloPostamat = () => ( {
		key: 'solo-postamat', lat: 55.76, lng: 37.62, size: 1,
		points: [ {
			id: 'postamat-2', name: 'Постамат «Северный»', short_address: 'Ленина, 1',
			type: { code: 'postamat', label: 'Постамат' }, selectable: { allowed: true, reason: null },
		} ],
	} );

	function uncheck( panels, code ) {
		const boxes = [ ...panels.root.querySelectorAll( '.woodev-pickup-filter__checkbox' ) ];

		boxes.find( ( b ) => code === b.dataset.code ).click();
	}

	it( 'removes a solo excluded-type group from the list entirely, synchronously, with no new setVisible()', () => {
		const panels = mount( filterConfig );
		panels.setTypes( twoTypes );
		panels.setVisible( [ soloPostamat() ] );

		expect( panels.root.querySelectorAll( '.woodev-pickup-list__item' ) ).toHaveLength( 1 );

		uncheck( panels, 'postamat' );

		// No panels.setVisible() call in between — the operator's own repro was "uncheck a type
		// and look at the list", no map movement at all.
		expect( panels.root.querySelectorAll( '.woodev-pickup-list__item' ) ).toHaveLength( 0 );
	} );

	it( 'a mixed-type co-located group survives, but the excluded point stops being offered', () => {
		const panels = mount( filterConfig );
		panels.setTypes( twoTypes );
		panels.setVisible( [ mixedGroup() ] );

		expect( panels.root.querySelectorAll( '.woodev-pickup-list__point' ) ).toHaveLength( 2 );

		uncheck( panels, 'postamat' );

		const item = panels.root.querySelector( '.woodev-pickup-list__item' );

		expect( item ).not.toBeNull(); // the group itself still renders — it still has a PVZ.
		// Reduced to exactly one visible point: a plain single row, not a one-item sub-row list.
		expect( item.querySelectorAll( '.woodev-pickup-list__point' ) ).toHaveLength( 0 );
		expect( item.textContent ).toContain( 'ПВЗ «Магнит»' );
		expect( item.textContent ).not.toContain( 'Постамат «Замоскворечье»' );
	} );

	it( 'the surviving single row opens the correct (surviving) point, not the excluded one', () => {
		const panels = mount( filterConfig );
		panels.setTypes( twoTypes );
		panels.setVisible( [ mixedGroup() ] );
		uncheck( panels, 'postamat' );

		const seen = [];
		panels.on( 'cardOpened', ( e ) => seen.push( e ) );
		panels.root.querySelector( '.woodev-pickup-list__item' ).click();

		expect( seen[ 0 ].pointId ).toBe( 'pvz-1' );
	} );

	it( 'the card tab bar drops the tab for an excluded point, and falls back off it if it was active', () => {
		const panels = mount( filterConfig );
		const g = mixedGroup();

		panels.setTypes( twoTypes );
		panels.setVisible( [ g ] );
		panels.openCard( g, 'postamat-1', 'marker' ); // opens on the postomat.

		expect( panels.root.querySelectorAll( '.woodev-pickup-card__tab' ) ).toHaveLength( 2 );
		expect( panels.root.querySelector( '.woodev-pickup-card__title' ).textContent )
			.toBe( 'Постамат «Замоскворечье»' );

		uncheck( panels, 'postamat' );

		// No tab bar left at all — only one point survives the filter (D-4: nothing left to
		// switch between) — and the card fell back to the still-visible PVZ rather than staying
		// on the point the customer just excluded.
		expect( panels.root.querySelector( '.woodev-pickup-card__tabs' ) ).toBeNull();
		expect( panels.root.querySelector( '.woodev-pickup-card__title' ).textContent ).toBe( 'ПВЗ «Магнит»' );
	} );

	it( 'leaves an open card on the still-visible point alone when the excluded point was never active', () => {
		const panels = mount( filterConfig );
		const g = mixedGroup();

		panels.setTypes( twoTypes );
		panels.setVisible( [ g ] );
		panels.openCard( g, 'pvz-1', 'marker' ); // opens on the PVZ — never the postomat.

		uncheck( panels, 'postamat' );

		const stage = panels.root.parentNode;

		expect( stage.className ).toContain( 'is-card' ); // still open — the ACTIVE point was never excluded.
		expect( panels.root.querySelector( '.woodev-pickup-card__title' ).textContent ).toBe( 'ПВЗ «Магнит»' );
	} );

	it( 'closes the card outright if every point in its group ends up filtered out', () => {
		// A 3rd type is required: BOTH of a 2-type group's points must be individually excluded
		// while a third type elsewhere keeps the "last selected type" guarantee satisfied globally.
		const panels = mount( filterConfig );
		const g = mixedGroup();
		const other = {
			key: 'other', lat: 55.80, lng: 37.65, size: 1,
			points: [ {
				id: 'locker-1', name: 'Локер', short_address: 'Where',
				type: { code: 'locker', label: 'Локер' }, selectable: { allowed: true, reason: null },
			} ],
		};

		panels.setTypes( [
			{ code: 'pvz', label: 'ПВЗ' }, { code: 'postamat', label: 'Постамат' }, { code: 'locker', label: 'Локер' },
		] );
		panels.setVisible( [ g, other ] );
		panels.openCard( g, 'pvz-1', 'marker' );

		uncheck( panels, 'pvz' ); // falls back to the still-visible postamat inside the same group.
		uncheck( panels, 'postamat' ); // now nothing in `g` survives — locker carries the invariant.

		const stage = panels.root.parentNode;

		expect( stage.className ).not.toContain( 'is-card' );
	} );

	it( 're-renders the list synchronously on typeFilterChange even with the search-layout home', () => {
		// Same fix, the OTHER home for the filter control (spec V-8: a SIBLING of the search field
		// inside `buildSearchLayout()`'s own detached layout, rather than the list-panel fallback
		// every other test in this block exercises) — proves the re-render is not tied to one
		// particular attachment point. The filter DOM lands inside `layout`, not `panels.root`, in
		// this configuration (see the file docblock's "THE CONTROL'S HOME" note), so the checkbox
		// is queried off `layout`.
		const panels = new Panels( document.createElement( 'div' ), filterConfig );

		panels.render();
		document.body.appendChild( panels._stage );

		const layout = panels.buildSearchLayout();
		document.body.appendChild( layout );

		panels.setTypes( twoTypes );
		panels.setVisible( [ soloPostamat() ] );

		expect( panels.root.querySelectorAll( '.woodev-pickup-list__item' ) ).toHaveLength( 1 );

		const boxes = [ ...layout.querySelectorAll( '.woodev-pickup-filter__checkbox' ) ];

		boxes.find( ( b ) => 'postamat' === b.dataset.code ).click();

		expect( panels.root.querySelectorAll( '.woodev-pickup-list__item' ) ).toHaveLength( 0 );
	} );
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

	it( 'renders the glyph first, then address in bold, then the name as the subtitle', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );
		const g = group( 'g1', 55.75, 37.61, 'ПВЗ «Магнит»' );
		g.points[ 0 ].address = 'Москва, Ленина, 5, корп. 2';

		panels.render();
		panels.setVisible( [ g ] );

		const row = container.querySelector( '.woodev-pickup-list__item' );
		const icon = row.querySelector( '.woodev-pickup-list__icon' );
		const address = row.querySelector( '.woodev-pickup-list__address' );
		const name = row.querySelector( '.woodev-pickup-list__name' );

		// Issue #171: the framework's own glyph is now always the row's first element (grid
		// places it in its own 'icon' area spanning every text row — see pickup.css); address
		// is the first TEXT element, immediately after it.
		expect( row.firstElementChild ).toBe( icon );
		expect( icon.nextElementSibling ).toBe( address );
		expect( address.textContent ).toBe( g.points[ 0 ].short_address );
		expect( name.textContent ).toBe( g.points[ 0 ].name );
		expect( address.getAttribute( 'title' ) ).toBe( g.points[ 0 ].address );
	} );

	// Issue #171 (operator decision): a teardrop map pin is a pointer at a coordinate — shrunk
	// into a list row there is nothing left to point at, so the framework now draws its OWN
	// square glyph here, ALWAYS, regardless of whether the plugin configured a MAP marker via
	// `config.pointIcons` (that map still exists, but only the map reads it now).
	describe( 'the framework-owned type glyph (issue #195)', () => {
		function pointOfType( code ) {
			const g = group( 'g1', 55.75, 37.61, 'ПВЗ' );
			g.points[ 0 ].type = { code: code };

			return g;
		}

		it( 'always renders a glyph, even with no plugin configuration at all', () => {
			const panels = new Panels( document.createElement( 'div' ), config );
			panels.render();
			panels.setVisible( [ pointOfType( 'PVZ' ) ] );

			expect( panels.root.parentNode.querySelector( '.woodev-pickup-list__icon svg' ) ).not.toBeNull();
		} );

		it( 'a plugin-supplied pointIcons map (the MAP marker contract) has no effect here', () => {
			const panels = new Panels( document.createElement( 'div' ), {
				...config,
				pointIcons: { PVZ: { default: '/pvz.svg', active: '/pvz-active.svg' } },
			} );
			panels.render();
			panels.setVisible( [ pointOfType( 'PVZ' ) ] );

			expect( panels.root.parentNode.querySelector( '.woodev-pickup-list__icon img' ) ).toBeNull();
			expect( panels.root.parentNode.querySelector( '.woodev-pickup-list__icon svg' ) ).not.toBeNull();
		} );

		it( 'defaults an unrecognised type to the warehouse glyph, never a POSTAMAT/LOCKER string guess', () => {
			const withUnknownType = new Panels( document.createElement( 'div' ), config );
			withUnknownType.render();
			withUnknownType.setVisible( [ pointOfType( 'SOME_CARRIER_SPECIFIC_LOCKER_CODE' ) ] );

			const defaultPanels = new Panels( document.createElement( 'div' ), config );
			defaultPanels.render();
			defaultPanels.setVisible( [ pointOfType( 'PVZ' ) ] );

			// Same markup either way — the framework never sniffs the type code's own text for
			// carrier vocabulary, it only ever consults config.pointGlyphs (absent here).
			expect(
				withUnknownType.root.parentNode.querySelector( '.woodev-pickup-list__icon' ).innerHTML
			).toBe(
				defaultPanels.root.parentNode.querySelector( '.woodev-pickup-list__icon' ).innerHTML
			);
		} );

		it( 'swaps in the OTHER built-in glyph via config.pointGlyphs', () => {
			const swapped = new Panels( document.createElement( 'div' ), {
				...config,
				pointGlyphs: { POSTAMAT: { glyph: 'package', markup: null } },
			} );
			swapped.render();
			swapped.setVisible( [ pointOfType( 'POSTAMAT' ) ] );

			const defaulted = new Panels( document.createElement( 'div' ), config );
			defaulted.render();
			defaulted.setVisible( [ pointOfType( 'PVZ' ) ] );

			expect(
				swapped.root.parentNode.querySelector( '.woodev-pickup-list__icon' ).innerHTML
			).not.toBe(
				defaulted.root.parentNode.querySelector( '.woodev-pickup-list__icon' ).innerHTML
			);
		} );

		it( 'renders a plugin-supplied raw markup override verbatim', () => {
			// See the identical note in the card-chip version of this test, above: jsdom
			// re-serialises a self-closing `<path/>` as `<path></path>` on innerHTML read-back.
			const customSvg = '<svg viewBox="0 0 24 24"><path d="M1 1"></path></svg>';
			const panels = new Panels( document.createElement( 'div' ), {
				...config,
				pointGlyphs: { CUSTOM: { glyph: null, markup: customSvg } },
			} );
			panels.render();
			panels.setVisible( [ pointOfType( 'CUSTOM' ) ] );

			expect( panels.root.parentNode.querySelector( '.woodev-pickup-list__icon' ).innerHTML ).toBe( customSvg );
		} );
	} );
} );

// -----------------------------------------------------------------------
// Task 14 (spec V-13): our own zoom control replaces ymaps' `ZoomControl` — two square
// buttons, «+» over «−», a stage sibling exactly like the list toggle (see `render()`'s own
// docblock on why a control that must stay fixed to a screen corner cannot live inside a panel
// that hides/resizes). The provider owns the actual zooming ({@see map-provider-yandex.test.js});
// this file only proves the DOM and the signed `step` the panels emit on click.
// -----------------------------------------------------------------------
describe( 'zoom control (spec V-13)', () => {
	it( 'renders two buttons and emits a signed step', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );
		const onZoom = jest.fn();

		panels.render();
		panels.on( 'zoom', onZoom );

		const buttons = container.querySelectorAll( '.woodev-pickup-zoom__button' );
		expect( buttons ).toHaveLength( 2 );

		buttons[ 0 ].click();
		buttons[ 1 ].click();

		expect( onZoom ).toHaveBeenNthCalledWith( 1, { step: 1 } );
		expect( onZoom ).toHaveBeenNthCalledWith( 2, { step: -1 } );
	} );

	it( 'types both buttons "button" so a checkout form never submits on click', () => {
		const container = document.createElement( 'div' );

		new Panels( container, config ).render();

		const buttons = container.querySelectorAll( '.woodev-pickup-zoom__button' );

		expect( buttons[ 0 ].type ).toBe( 'button' );
		expect( buttons[ 1 ].type ).toBe( 'button' );
	} );

	it( 'names each button from its own i18n key, not the unrelated bbox "zoomIn" message', () => {
		const container = document.createElement( 'div' );

		new Panels( container, config ).render();

		const buttons = container.querySelectorAll( '.woodev-pickup-zoom__button' );

		expect( buttons[ 0 ].getAttribute( 'aria-label' ) ).toBe( config.i18n.zoomInLabel );
		expect( buttons[ 1 ].getAttribute( 'aria-label' ) ).toBe( config.i18n.zoomOutLabel );
	} );

	it( 'is a sibling of the panels on the stage, not a child of a hidden/resizing panel', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );

		panels.render();

		expect( panels._stage.querySelector( '.woodev-pickup-zoom' ) ).not.toBeNull();
		expect( panels.root.querySelector( '.woodev-pickup-zoom' ) ).toBeNull();
	} );
} );

// -----------------------------------------------------------------------
// Task 16 (spec V-4 stage 2/3): setBusy()/isBusy() — the stage-wide overlay + non-interactive
// map that covers the gap between "the map is drawn" and "the first points fetch settled". The
// sequencing itself (pickup-mount.js calling this at the right times) is `pickup-mount.test.js`'s
// job; this file only proves the API's own DOM contract.
// -----------------------------------------------------------------------
describe( 'setBusy()/isBusy() (spec V-4)', () => {
	it( 'is not busy, and the overlay is hidden, right after render()', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );

		panels.render();

		expect( panels.isBusy() ).toBe( false );
		expect( container.querySelector( '.woodev-pickup-overlay' ) ).not.toBeNull();
		expect( container.querySelector( '.woodev-pickup-overlay' ).hidden ).toBe( true );
	} );

	it( 'setBusy( true ) marks the stage is-busy and un-hides the overlay', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );

		panels.render();
		panels.setBusy( true );

		expect( panels.isBusy() ).toBe( true );
		expect( panels._stage.className ).toContain( 'is-busy' );
		expect( container.querySelector( '.woodev-pickup-overlay' ).hidden ).toBe( false );
	} );

	it( 'setBusy( false ) clears is-busy and hides the overlay again — the same node, not a new one', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );

		panels.render();
		panels.setBusy( true );
		const overlay = container.querySelector( '.woodev-pickup-overlay' );
		panels.setBusy( false );

		expect( panels.isBusy() ).toBe( false );
		expect( panels._stage.className ).not.toContain( 'is-busy' );
		expect( container.querySelector( '.woodev-pickup-overlay' ) ).toBe( overlay );
		expect( overlay.hidden ).toBe( true );
	} );

	it( 'is idempotent: calling setBusy( true ) twice keeps exactly one overlay', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );

		panels.render();
		panels.setBusy( true );
		panels.setBusy( true );

		expect( container.querySelectorAll( '.woodev-pickup-overlay' ) ).toHaveLength( 1 );
	} );

	it( 'does not throw when called before render()', () => {
		const panels = new Panels( document.createElement( 'div' ), config );

		expect( () => panels.setBusy( true ) ).not.toThrow();
		expect( panels.isBusy() ).toBe( true );
	} );
} );

// -----------------------------------------------------------------------
// Task 17 (spec V-5): showMessage()/hideMessage() — the plugin's own empty/error text as a
// centred card over the map. NEVER a replacement for the whole interface (s48 decision, kept
// here): unlike setBusy()'s stage-wide overlay above, this must not toggle `is-busy` — the list,
// search and filter controls stay usable so the customer can still search or change the filter
// while the card is showing. `pickup-mount.js`'s own wiring of WHEN to call this is that file's
// test's job; this file only proves the method's own DOM contract.
// -----------------------------------------------------------------------
describe( 'showMessage()/hideMessage() (spec V-5)', () => {
	it( 'is hidden right after render()', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );

		panels.render();

		expect( container.querySelector( '.woodev-pickup-message' ) ).not.toBeNull();
		expect( container.querySelector( '.woodev-pickup-message' ).hidden ).toBe( true );
	} );

	it( 'un-hides the card with the resolved i18n text for the key', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );

		panels.render();
		panels.showMessage( 'emptyLocality' );

		const message = container.querySelector( '.woodev-pickup-message' );

		expect( message.hidden ).toBe( false );
		expect( message.textContent ).toContain( 'В выбранном населённом пункте нет пунктов выдачи' );
	} );

	it( 'never toggles is-busy or hides the search/filter controls (controls stay usable)', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );

		panels.render();
		panels.showMessage( 'zoomIn' );

		expect( panels._stage.className ).not.toContain( 'is-busy' );
	} );

	it( 'renders NO retry control for a non-error key', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );

		panels.render();
		panels.showMessage( 'emptyInView' );

		expect( container.querySelector( '.woodev-pickup-message__retry' ) ).toBeNull();
	} );

	it( "renders a retry control for the mount's own SPECIFIC error keys too "
		+ '(e.g. `upstreamError`), not just the literal `error` key — every failed-request key '
		+ 'is retryable, only the three empty/zoom states are not', () => {
		const container = document.createElement( 'div' );
		const withUpstreamError = Object.assign( {}, config, {
			i18n: Object.assign( {}, config.i18n, {
				upstreamError: 'Сервис пунктов выдачи временно недоступен. Попробуйте ещё раз позже.',
			} ),
		} );
		const panels = new Panels( container, withUpstreamError );

		panels.render();
		panels.showMessage( 'upstreamError' );

		const retryButton = container.querySelector( '.woodev-pickup-message__retry' );
		expect( retryButton ).not.toBeNull();
		expect( container.querySelector( '.woodev-pickup-message__text' ).textContent )
			.toBe( 'Сервис пунктов выдачи временно недоступен. Попробуйте ещё раз позже.' );
	} );

	it( "renders a retry control for the 'error' key, and clicking it emits retryRequested", () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );
		const onRetry = jest.fn();

		panels.render();
		panels.on( 'retryRequested', onRetry );
		panels.showMessage( 'error' );

		const retryButton = container.querySelector( '.woodev-pickup-message__retry' );

		expect( retryButton ).not.toBeNull();
		expect( retryButton.textContent ).toBe( 'Повторить' );

		retryButton.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );

		expect( onRetry ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'a later non-error showMessage() call removes a previously-rendered retry control', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );

		panels.render();
		panels.showMessage( 'error' );
		panels.showMessage( 'emptyInView' );

		expect( container.querySelector( '.woodev-pickup-message__retry' ) ).toBeNull();
		expect( container.querySelector( '.woodev-pickup-message' ).textContent )
			.toContain( 'В этой области пунктов выдачи нет' );
	} );

	it( 'renders BLANK, never a hardcoded Russian fallback, for a missing i18n key (rule I1)', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, withoutI18nKey( config, 'emptyLocality' ) );

		panels.render();
		panels.showMessage( 'emptyLocality' );

		const textEl = container.querySelector( '.woodev-pickup-message__text' );

		expect( textEl.textContent ).toBe( '' );
	} );

	it( 'hideMessage() hides the card again without removing it from the DOM', () => {
		const container = document.createElement( 'div' );
		const panels = new Panels( container, config );

		panels.render();
		panels.showMessage( 'error' );
		const message = container.querySelector( '.woodev-pickup-message' );
		panels.hideMessage();

		expect( container.querySelector( '.woodev-pickup-message' ) ).toBe( message );
		expect( message.hidden ).toBe( true );
	} );

	it( 'does not throw when called before render()', () => {
		const panels = new Panels( document.createElement( 'div' ), config );

		expect( () => panels.showMessage( 'error' ) ).not.toThrow();
		expect( () => panels.hideMessage() ).not.toThrow();
	} );
} );
