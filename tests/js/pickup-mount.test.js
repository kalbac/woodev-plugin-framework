/**
 * Tests for pickup-mount.js
 *
 * Covers SP-5 Task 12's original wiring (idempotent trigger placement, the click → modal →
 * provider → dataSource plumbing, writing a selection THROUGH the field's OWN owning store,
 * firing `change`/`change.select2` after every write, address replacement target resolution,
 * missing-option handling for the city select, the non-destructive degrade-to-notice once a
 * point set is drawn, retry always rebuilding the provider from scratch, i18n keys read from
 * the SHAPE the PHP side actually emits, and the no-duplicate-session guarantee) PLUS Task 20's
 * own wiring: this file, not the provider, now owns fetching (bulk fetches once on `init()`
 * resolve, viewport fetches per `boundsChange`); the `ownsChrome` branch (no panels at all for
 * an embedded-style provider); provider↔panels event bridging both ways; the four
 * `woodev_pickup_*` `document.body` events; `refresh()`; the trigger's `i18n.trigger`/
 * `i18n.triggerChange` label toggle; and the "your address" pin cannot outlive the search that
 * created it (the panels' own `anchorCleared` event → `provider.clearAddress()`).
 *
 * `jest.useFakeTimers()` is installed BEFORE pickup-mount.js is required, so
 * the module's own top-level `setTimeout()` calls (initial mount +
 * `updated_checkout` defer) are captured under fake-timer control from the
 * very first require — a real timer registered before fake timers are
 * installed would otherwise fire uncontrolled, mid test, with whatever
 * `window` state happened to exist at that moment. Fake timers never affect
 * native Promise microtasks, so `await`-ing a chain of `.then()`s (see
 * {@see flushAsync}) works identically regardless.
 *
 * No real jQuery is loaded in this environment (none is a project
 * dependency), so `window.jQuery` is undefined and pickup-mount.js's
 * `onCheckoutUpdated()` falls back to a plain native `updated_checkout` event
 * on `document.body` — exactly the fallback its own docblock documents. The
 * `change.select2`-firing branch is likewise unreachable without jQuery, so a
 * dedicated tiny jQuery stub is installed for the ONE test that needs it.
 *
 * PANELS ARE A STUB (`StubPanels`, not the real `pickup-panels.js`), installed as
 * `window.WoodevPickupPanels` in `beforeEach` — this file tests the WIRING contract mount.js
 * establishes with whatever panels object it is handed, not the panels' own rendering (that is
 * `pickup-panels.test.js`'s job). `StubPanels.render()` still builds the minimal REAL DOM markup
 * (`.woodev-pickup-list`/`.woodev-pickup-card`) the `ownsChrome` branch tests check for, and
 * exposes `emit()` so a test can drive it exactly like `StubProvider`. One dedicated test near
 * the end uses the REAL `Panels` class instead, to prove `buildPanelsConfig()` actually produces
 * a shape the real class accepts.
 *
 * @see woodev/shipping-method/assets/js/frontend/pickup-mount.js
 */

'use strict';

jest.useFakeTimers();

/**
 * Records `focusGroup()`/`openCard()` calls in the order the STUBS actually saw them — the only
 * way to prove `pickup-mount.js` calls them in the sequence the `cardOpened` funnel requires
 * (focus, THEN open) rather than merely calling both somewhere. This ordering is separate from,
 * and survives, round 2's D6 pan/zoom split (spec V-10's "identical path" claim is overruled —
 * see pickup-mount.js's own `cardOpened` comment; the SEQUENCE stays the same for every origin,
 * only the camera's zoom option now differs). Reset in `beforeEach()`.
 *
 * @type {Array<string>}
 */
let callOrder = [];

const { createStore } = require( '../../woodev/shipping-method/assets/js/frontend/checkout-field-store' );
require( '../../woodev/assets/js/frontend/woodev-modal' ); // side effect: window.WoodevModal
const { mountAll, getSession } = require( '../../woodev/shipping-method/assets/js/frontend/pickup-mount' );
const RealPanels = require( '../../woodev/shipping-method/assets/js/frontend/pickup-panels' );

const FIELD_ID = 'carrier_pickup_point';

/**
 * A test-controlled `Map_Provider` double covering the FULL Task 20 contract (`init/on/destroy`
 * plus `setPoints`/`setTypeFilter`/`focusGroup`/`getFocusedKey`/`resolveAddress`/`focusAddress`/
 * `clearAddress`/`setMargin`) — every call is recorded so a test can assert exactly what mount.js
 * sent it. `on()` accepts ANY event name (not a fixed list) so this one double serves every
 * provider event this file wires. Every constructed instance is pushed onto
 * `StubProvider.instances` so a test can assert how many concurrently-live providers ever
 * existed and which were destroyed.
 */
function StubProvider() {
	this.handlers = {};
	this.destroyed = false;
	this.initCalls = [];
	this.setPointsCalls = [];
	// The `options` argument of each setPoints() call, positionally paired with setPointsCalls —
	// `{ fit: false }` is how the mount suppresses the bulk camera fit on the restore pass (s52).
	this.setPointsOptions = [];
	// The sidebar reservation that was in force at the MOMENT of each `setPoints()` call, paired
	// positionally with the two arrays above. The restore pass's focus move carries
	// `useMapMargin: true`, so "was the panel's area reserved yet?" is the whole difference
	// between the restored point centring on the visible strip and centring on the full map —
	// an ordering fact no "were both called?" assertion can see. `null` before the first
	// `setMargin()` of a session (there is one at init, so in practice only if that regressed).
	this.marginAtSetPoints = [];
	this.setTypeFilterCalls = [];
	this.focusGroupCalls = [];
	this.resolveAddressCalls = [];
	this.clearAddressCalls = 0;
	this.setMarginCalls = [];
	this.focusGroupOptions = [];
	this.zoomByCalls = [];
	this.matchLoadedPointsCalls = [];
	this.matchLoadedPointsResult = [];
	// The real `map-provider-yandex.js` only builds this once `init()` has run and
	// `config.searchLayoutEl` was truthy — the stub builds it unconditionally up front since no
	// test in this file exercises the "search disabled" provider-side branch (that is
	// `map-provider-yandex.test.js`'s own job); every test here only cares that pickup-mount.js
	// calls `provider.searchControl.search( query )` on submit.
	this.searchControl = { search: jest.fn() };
	StubProvider.instances.push( this );
}

StubProvider.instances = [];

StubProvider.prototype.init = function( container, config, dataSource ) {
	this.initCalls.push( { container: container, config: config, dataSource: dataSource } );
};

StubProvider.prototype.on = function( event, cb ) {
	if ( ! this.handlers[ event ] ) {
		this.handlers[ event ] = [];
	}

	this.handlers[ event ].push( cb );
};

StubProvider.prototype.emit = function( event, payload ) {
	( this.handlers[ event ] || [] ).forEach( function( cb ) {
		cb( payload );
	} );
};

StubProvider.prototype.destroy = function() {
	this.destroyed = true;
};

StubProvider.prototype.setPoints = function( groups, options ) {
	this.setPointsCalls.push( groups );
	this.setPointsOptions.push( options );
	this.marginAtSetPoints.push( this.setMarginCalls[ this.setMarginCalls.length - 1 ] || null );
};

StubProvider.prototype.setTypeFilter = function( codes ) {
	this.setTypeFilterCalls.push( codes );
};

/**
 * Returns a promise that NEVER resolves — deliberately. The camera move must NOT be awaited
 * before the card opens (the card is our own DOM, unrelated to the viewport); a never-resolving
 * stub is what makes a test that awaits nothing after `emit()` prove that, since an (incorrect)
 * `.then( openCard )` chain here would leave `openCard()` forever uncalled.
 *
 * `options` (round 2, D6) is recorded separately in `focusGroupOptions`, parallel to
 * `focusGroupCalls`, rather than folded into that array's own shape — every EXISTING assertion
 * in this file already reads `focusGroupCalls` as a bare array of keys; changing its element
 * shape would break every one of them for no reason the pan/zoom split needs.
 */
StubProvider.prototype.focusGroup = function( key, options ) {
	this.focusGroupCalls.push( key );
	this.focusGroupOptions.push( options );
	callOrder.push( 'focusGroup:' + key );

	return new Promise( function() {} );
};

StubProvider.prototype.getFocusedKey = function() {
	return null;
};

StubProvider.prototype.resolveAddress = function( displayName ) {
	this.resolveAddressCalls.push( displayName );

	return Promise.resolve();
};

StubProvider.prototype.focusAddress = function() {
	return Promise.resolve();
};

StubProvider.prototype.clearAddress = function() {
	this.clearAddressCalls += 1;
};

StubProvider.prototype.setMargin = function( open, width ) {
	this.setMarginCalls.push( { open: open, width: width } );
};

StubProvider.prototype.zoomBy = function( step ) {
	this.zoomByCalls.push( step );
};

StubProvider.prototype.matchLoadedPoints = function( query ) {
	this.matchLoadedPointsCalls.push( query );

	return this.matchLoadedPointsResult;
};

/**
 * Round 4 — the real provider's `ymaps.suggest()`-backed typing path. Deliberately NOT defined on
 * the prototype: `pickup-mount.js` feature-detects it, and a provider that owns its own chrome
 * (the embedded one) legitimately has none, so tests must be able to exercise BOTH branches.
 * {@see withSuggest} installs it on one instance.
 *
 * It RESOLVES rather than emitting `searchResults` — that split is the whole point (emitting would
 * drive the completed-search renderer and put "Поиск не дал результатов." back on screen while the
 * customer is still typing), so a stub that emitted instead would agree with the very bug this
 * guards.
 *
 * @param {StubProvider} provider
 * @param {Object}       result   the `{ points, addresses }` the suggestion should resolve with.
 * @returns {StubProvider}
 */
function withSuggest( provider, result ) {
	provider.suggestAddressesCalls = [];
	provider.suggestAddresses = function( query ) {
		provider.suggestAddressesCalls.push( query );

		return Promise.resolve( result );
	};

	return provider;
}

/**
 * A minimal `window.WoodevPickupPanels` double — see the file docblock's "PANELS ARE A STUB"
 * note. `render()` builds just enough REAL DOM (`.woodev-pickup-list`/`.woodev-pickup-card`) for
 * the `ownsChrome` branch tests to query for; every other method just records its last call.
 */
function StubPanels( container, config ) {
	this.container = container;
	this.config = config;
	this._listeners = {};
	this.root = null;

	// Task 11's three selection-confirmation calls, as PER-INSTANCE `jest.fn()`s rather than
	// recording arrays: the confirmation tests assert on the ARGUMENTS of a specific call
	// (`toHaveBeenCalledWith( 'P1', { allowed: false, reason: … } )`) and on which call came
	// LAST (`toHaveBeenLastCalledWith( false )` — the busy state must be released on every
	// settlement path), both of which jest's own matchers say far more precisely than a
	// hand-rolled array would. Per instance, not on the prototype, so one session's calls can
	// never bleed into the next one's expectations.
	this.setSelectionBusy = jest.fn();
	this.setPointVerdict = jest.fn();
	this.showSelectionError = jest.fn();

	// Issue #223: the verdict-pending card lock, as a per-instance `jest.fn()` for the same
	// reason `setSelectionBusy` above is one — tests assert on `toHaveBeenLastCalledWith()`
	// across the whole in-flight window (start, success, failure, the card moving on), which a
	// hand-rolled array would only say less precisely.
	this.setVerdictPending = jest.fn();

	/** @type {Array} every `setZoomLimits()` payload, in order. */
	this.setZoomLimitsCalls = [];

	/** @type {Array} every `updatePoint()` call, in order (issue #219). */
	this.updatePointCalls = [];

	/** @type {Array<boolean>} every `setLoading()` call, in order (issues #222/#224). */
	this.setLoadingCalls = [];

	StubPanels.instances.push( this );
}

StubPanels.instances = [];

StubPanels.prototype.render = function() {
	this.root = document.createElement( 'div' );
	this.root.className = 'woodev-pickup-panels';

	const list = document.createElement( 'div' );
	list.className = 'woodev-pickup-list';
	const card = document.createElement( 'div' );
	card.className = 'woodev-pickup-card';

	// The panels own the map element now (spec V-3) — the provider mounts INTO it instead of
	// creating a sibling canvas in the dialog body. The stub has to carry it too, or the mount
	// hands `provider.init()` an undefined host.
	this.mapEl = document.createElement( 'div' );
	this.mapEl.className = 'woodev-pickup-map';

	this.root.appendChild( this.mapEl );
	this.root.appendChild( list );
	this.root.appendChild( card );
	this.container.appendChild( this.root );
};

StubPanels.prototype.getMapElement = function() {
	return this.mapEl;
};

/**
 * Mirrors the real `Panels.prototype.buildSearchLayout()`'s one externally-observable contract
 * (Task 12, spec V-6): a detached element, or `null` when the config disabled search
 * (`config.search === false`) — this file's own wiring test for THAT branch needs the stub to
 * honour it, not just always return an element.
 */
StubPanels.prototype.buildSearchLayout = function() {
	if ( false === this.config.search ) {
		return null;
	}

	this.builtSearchLayoutEl = document.createElement( 'div' );
	this.builtSearchLayoutEl.className = 'stub-search-layout';

	return this.builtSearchLayoutEl;
};

StubPanels.prototype.on = function( event, cb ) {
	( this._listeners[ event ] = this._listeners[ event ] || [] ).push( cb );
};

StubPanels.prototype.emit = function( event, payload ) {
	( this._listeners[ event ] || [] ).forEach( function( cb ) {
		cb( payload );
	} );
};

StubPanels.prototype.setVisible = function( groups ) {
	this.lastVisible = groups;
};

StubPanels.prototype.setTypes = function( types ) {
	this.lastTypes = types;
};

StubPanels.prototype.showNothingNearby = function( info ) {
	this.lastNothingNearby = info;
};

StubPanels.prototype.renderSearchResults = function( results ) {
	this.lastSearchResults = results;
};

/**
 * Round 3 — the PREVIEW half of the search pair, recorded separately from
 * {@see StubPanels#renderSearchResults} on purpose: the whole point of the fix is that a typed
 * keystroke and a completed search no longer reach the same renderer, so a stub that collapsed
 * them back into one field could not tell the two apart and would agree with the bug.
 *
 * @param {Object} results
 * @returns {void}
 */
StubPanels.prototype.previewSearchResults = function( results ) {
	this.lastSearchPreview = results;
};

/**
 * D1a/round 2 — records every `hideSearchResults()` call (the `searchCleared` handler's own job,
 * replacing the old empty-`searchResults` hack). No DOM to assert on here — that is
 * `pickup-panels.test.js`'s job; this file only proves pickup-mount.js calls it.
 */
StubPanels.prototype.hideSearchResultsCalls = 0;

StubPanels.prototype.hideSearchResults = function() {
	this.hideSearchResultsCalls = ( this.hideSearchResultsCalls || 0 ) + 1;
};

/**
 * Work item 5/round 2 — records every `setSearchBusy( busy )` call, in order, so a test can
 * assert both that it was called and with which value at which point in a sequence (busy on
 * submit, cleared on whichever of the three outcomes actually answers).
 */
StubPanels.prototype.setSearchBusy = function( busy ) {
	this.setSearchBusyCalls = this.setSearchBusyCalls || [];
	this.setSearchBusyCalls.push( !! busy );
};

/**
 * Models the real `setStageOpen()` — the ONE place `Panels` flips the sidebar's open state and,
 * only when that state actually CHANGES, emits `listToggle` with the list's own current width.
 * The mount turns that event into `provider.setMargin()`, so a stub whose `openCard()` opened the
 * sidebar silently would let a test believe the map's margin had been reserved when nothing had
 * reserved it.
 *
 * The width is a fixed 320 rather than a measured `offsetWidth`: jsdom lays nothing out and
 * reports 0 for every element, and 320 is what the real panel measures on the rig
 * (`max-width: min( 320px, calc( 100% - 48px ) )` in `pickup.css`). A stub reporting 0 would make
 * every margin assertion in this file agree with a reservation of zero pixels — precisely the
 * shape of the bug `ymaps-margin-area-needs-explicit-width` records.
 *
 * @param {StubPanels} self
 * @param {boolean}    open
 * @returns {void}
 */
function stubStageOpen( self, open ) {
	if ( !! self._stageOpen === !! open ) {
		return;
	}

	self._stageOpen = !! open;
	self.emit( 'listToggle', { open: !! open, width: open ? 320 : 0 } );
}

StubPanels.prototype.openCard = function( group, pointId, origin ) {
	this.lastOpenCard = { group: group, pointId: pointId, origin: origin };

	// The real `openCard()` opens the sidebar through `setStageOpen()` BEFORE it emits
	// `cardOpened` — an ordering its own docblock marks load-bearing, because the mount answers
	// the first with `provider.setMargin()` and the second with `provider.focusGroup()`, and a
	// camera move that precedes its own margin reservation lands the point under the panel.
	stubStageOpen( this, true );

	// Whatever `setSelectedId()` had recorded by the time this card opened. The real
	// `renderCard()` reads `_selectedId` to choose between «Выбрать» and «Продолжить оформление»,
	// so "was the id already set?" is an ordering fact a caller can get wrong — and the
	// reopen-restore path (06.08.2026) exists precisely to show the second label. Captured here
	// rather than pushed onto `callOrder`, which other tests assert by exact equality.
	this.selectedIdWhenCardOpened = this.lastSelectedId;
	this.openCardCalls = ( this.openCardCalls || 0 ) + 1;

	// The real class emits this from `openCard()`, BEFORE it renders — the single funnel every
	// route to a card passes through, and what the mount listens to in order to move the camera.
	// `origin` (round 2, D6) rides along verbatim — it is what lets that ONE listener pan-only for
	// a marker and centre-and-zoom for everything else (spec V-10's "identical path" claim is
	// overruled; see pickup-mount.js's own `cardOpened` listener). Mirrors the real class'
	// `undefined`-OR-`null` defaulting (`Panels.prototype.openCard`'s own docblock), since callers
	// now legitimately pass `null` for "no specific point" (the marker/nearest routes). The stub
	// has to model both the event and its position, or every camera assertion here silently tests
	// nothing and the documented focus-then-card order goes unchecked.
	this.emit( 'cardOpened', {
		group: group,
		pointId: ( undefined === pointId || null === pointId ) ? group.points[ 0 ].id : pointId,
		origin: origin,
	} );

	callOrder.push( 'openCard:' + ( group && group.key ) );
};

StubPanels.prototype.closeCard = function() {
	this.closeCardCalls = ( this.closeCardCalls || 0 ) + 1;
};

StubPanels.prototype.setSelectedId = function( id ) {
	this.lastSelectedId = id;
};

StubPanels.prototype.setAnchor = function( latLng, label ) {
	this.setAnchorCalls = this.setAnchorCalls || [];
	this.setAnchorCalls.push( { latLng: latLng, label: label } );
};

StubPanels.prototype.toggleList = function() {};

StubPanels.prototype.openList = function() {
	this.openListCalls = ( this.openListCalls || 0 ) + 1;

	// Same `setStageOpen()` funnel as `openCard()` above — the real `openList()` routes through it
	// too, so a search picking an address behind a CLOSED sidebar reserves the margin here.
	stubStageOpen( this, true );
};

/**
 * Task 16 (spec V-4): the real `Panels.prototype.setBusy()`/`isBusy()` contract, minimally —
 * every OTHER test in this file constructs a session through the stub, and pickup-mount.js now
 * calls `panels.setBusy()` unconditionally whenever `panels` is truthy (matching how it already
 * calls `setTypes()`/`setVisible()` etc. with no feature-detection — see those call sites). The
 * stub has to carry the method or every test in this file that never opted into the `RealPanels`
 * swap (see the "loading stages" describe block below) would throw.
 */
StubPanels.prototype.setBusy = function( busy ) {
	this._busy = !! busy;
};

StubPanels.prototype.isBusy = function() {
	return !! this._busy;
};

/**
 * Issues #222/#224: records every `setLoading()` call, in order — the shared background-load
 * indicator. The real method's DOM contract (the `is-loading` class, the two indicator elements)
 * is `pickup-panels.test.js`'s job; this file only proves WHEN and how many times the mount calls
 * it, via `panels.setLoadingCalls`.
 */
StubPanels.prototype.setLoading = function( loading ) {
	this.setLoadingCalls.push( !! loading );
};

/**
 * Records every `setZoomLimits()` call — the return leg of the zoom wiring (the provider
 * reports a reached limit, the panels dim that button). The real method's DOM contract is
 * `pickup-panels.test.js`'s job; this file only proves the forwarding.
 */
StubPanels.prototype.setZoomLimits = function( limits ) {
	this.setZoomLimitsCalls.push( limits );
};

/**
 * Issue #219: records the landing half of the viewport lazy-detail fetch. The real method's
 * merge semantics are `pickup-panels.test.js`'s job; this file proves only WHEN the mount
 * calls it.
 */
StubPanels.prototype.updatePoint = function( pointId, fields ) {
	this.updatePointCalls.push( { pointId: pointId, fields: fields } );
};

/**
 * Task 17 (spec V-5): records every `showMessage( key )`/`hideMessage()` call — this file's own
 * tests assert on `panels.showMessageCalls`/`panels.hideMessageCalls` rather than any modal-level
 * DOM, since the real `Panels.prototype.showMessage()`'s own DOM contract is `pickup-panels.test.js`'s
 * job (spec V-5's centred card lives on the panels, not the dialog).
 */
StubPanels.prototype.showMessage = function( key ) {
	this.showMessageCalls = this.showMessageCalls || [];
	this.showMessageCalls.push( key );
};

StubPanels.prototype.hideMessageCalls = 0;

StubPanels.prototype.hideMessage = function() {
	this.hideMessageCalls = ( this.hideMessageCalls || 0 ) + 1;
};

/**
 * Builds a fake `WoodevPickupDataSource` factory whose `fetchPoints()`
 * resolves/rejects with whatever `impl` returns — no real `fetch`, no
 * debounce, fully synchronous-microtask-controlled so tests stay fast and
 * deterministic. `fetchDetails()` is unused by this file and stubbed trivially.
 *
 * The returned factory records the `options` object `openSession()` calls it with, on
 * `factory.lastOptions` — needed so a test can invoke `options.nonce()` itself and prove
 * `pickup-mount.js`'s live-nonce-reader closure (issue #157, {@see currentNonce} in
 * pickup-mount.js) is the ACTUAL function wired into the datasource, not just a helper
 * that exists somewhere unreferenced. Every OTHER test in this file already ignored the
 * factory's argument entirely (the returned object never changes shape), so recording it
 * here is additive only.
 *
 * `selectPoint()` (Task 11) resolves, by default, with the shape the select route emits when
 * the domain accepted the point and volunteered no advice at all — `close`/`refresh_checkout`
 * both `null`, i.e. "defer to the plugin's configured default". That default keeps every test
 * whose subject is the WRITE (the store, the address fields, the trigger label) able to drive a
 * selection through without restating the response shape; the tests whose subject IS the
 * response drive it themselves through {@see openPicker}.
 *
 * @param {Function} impl `function( query ) { return Promise }`
 */
function fakeDataSourceFactory( impl ) {
	function factory( options ) {
		factory.lastOptions = options;

		return {
			fetchPoints: impl,
			fetchDetails: factory.fetchDetails,
			selectPoint: factory.selectPoint,
		};
	}

	// Issue #219: resolves with the full record `Pickup_Controller::get_point_data()` returns —
	// the same shape a listing point has, plus a freshly computed `selectable`. Overridable per
	// test (`factory.fetchDetails = …`) for the staleness/failure paths.
	factory.fetchDetails = jest.fn( function( pointId ) {
		return Promise.resolve( {
			id: pointId,
			selectable: { allowed: false, reason: 'Только предоплата.' },
		} );
	} );

	factory.selectPoint = jest.fn( function() {
		return Promise.resolve( { allowed: true, reason: null, close: null, refresh_checkout: null } );
	} );

	return factory;
}

/**
 * Awaits several microtask hops — enough for pickup-mount.js's own
 * `provider.init().then()` → `fetchAndSetPoints()`'s `dataSource.fetchPoints().then()` chain
 * (and anything a test drives on top of it) to fully settle. Native Promise microtasks are
 * NEVER affected by `jest.useFakeTimers()` — only macrotask APIs (`setTimeout`, …) are faked.
 */
async function flushAsync() {
	for ( let i = 0; i < 6; i++ ) {
		await Promise.resolve();
	}
}

/**
 * The i18n shape `Pickup_Handler::get_js_config()` ACTUALLY emits (see
 * class-pickup-handler.php) — used as the default so a test proves the mount
 * reads the real key names, not a hypothetical/convenient one.
 */
function phpI18n( overrides ) {
	return Object.assign(
		{
			modalTitle: 'Выберите пункт выдачи',
			close: 'Закрыть',
			select: 'Выбрать этот пункт',
			loading: 'Загрузка пунктов выдачи…',
			error: 'Не удалось загрузить пункты выдачи. Попробуйте ещё раз.',
			noResults: 'Поиск не дал результатов.',
			blocked: 'Этот пункт выдачи недоступен для вашего заказа.',
			trigger: 'Выбрать пункт выдачи',
			triggerChange: 'Выбрать другой пункт выдачи',
			retry: 'Повторить',
			upstreamError: 'Сервис пунктов выдачи временно недоступен. Попробуйте ещё раз позже.',
			rateLimited: 'Слишком много запросов. Подождите немного и попробуйте снова.',
			notFound: 'Этот пункт выдачи больше не найден. Пожалуйста, выберите другой.',
			zoomIn: 'Приблизьте карту, чтобы увидеть пункты выдачи',
			// #157: a 403 on ANY pickup route — points fetch included — means the page's nonce
			// has outlived the session it was minted for, not a normal fetch failure.
			stalePage: 'Страница устарела. Обновите её и выберите пункт выдачи заново.',
			// Task 4's three confirmation strings. `selectFailed` is deliberately NOT `error`
			// above — that one is worded for a failed points FETCH and would describe the wrong
			// operation entirely under a button just pressed to CONFIRM a point.
			continueCheckout: 'Продолжить оформление заказа',
			confirming: 'Проверяем…',
			selectFailed: 'Не удалось подтвердить выбор. Попробуйте ещё раз.',
		},
		overrides
	);
}

function makeConfig( overrides ) {
	const base = {
		fieldId: FIELD_ID,
		provider: 'testProvider',
		strategy: 'bulk',
		restRoot: 'https://example.test/wp-json/woodev/v1/shipping/pickup/p/points',
		nonce: 'nonce-1',
		// #157: the DOM id of the fragment node `Pickup_Handler::print_nonce_node()`/
		// `inject_nonce_fragment()` both target — real value shape, see that class'
		// `nonce_node_id()`.
		nonceNodeId: 'woodev-pickup-nonce-p',
		i18n: phpI18n(),
		mapConfig: { center: [ 55.75, 37.61 ] },
		replaceAddress: { enabled: true, billingOnly: false },
		// TOP-LEVEL keys `Pickup_Handler::get_js_config()` really emits and the map provider
		// really reads. They were missing from this fixture, which is exactly why nothing here
		// noticed that buildProviderConfig() never forwarded them: the map opened at its
		// technical [0,0]/zoom-2 fallback instead of the buyer's city, ObjectManager creates
		// overlays only for VISIBLE objects, so there were no markers and — through the same
		// bounds test — no sidebar entries either. Keep this fixture shaped like the real
		// config; a fixture poorer than production hides production's bugs.
		defaultLocation: { center: [ 55.76, 37.64 ], zoom: 12 },
		pointIcons: { PVZ: { default: 'https://example.test/pvz.svg', active: 'https://example.test/pvz-a.svg' } },
		accentColor: '#06aedd',
		modal: { width: 920, bodyHeight: 'min(80vh, 800px)' },
		search: true,
		// The PRODUCTION defaults, verbatim from `Pickup_Handler::get_js_config()`: both are
		// `false`, and both are the framework's own deliberate behaviour rather than an unset
		// setting. A fixture that quietly defaulted `close` to `true` — because most of this
		// file's older tests happen to want a closing modal — would be a fixture RICHER than
		// production in exactly the direction that hides the `?? not ||` bug this task exists
		// to prevent. Tests that want the modal to close say so explicitly instead.
		selection: { close: false, refreshCheckout: false },
	};

	return Object.assign( {}, base, overrides );
}

/**
 * Spec-style config helper (matches the T20 spec's own `configWith( overrides )` calls):
 * `{ ownsChrome }` is a convenience TOP-LEVEL key that maps onto `mapConfig.ownsChrome` — the
 * actual field `pickup-mount.js` reads — never a real top-level config key of its own.
 *
 * @param {Object} [overrides]
 */
function configWith( overrides ) {
	const opts = Object.assign( {}, overrides );
	const mapConfig = Object.assign( { center: [ 55.75, 37.61 ] }, opts.mapConfig );

	if ( undefined !== opts.ownsChrome ) {
		mapConfig.ownsChrome = opts.ownsChrome;
	}

	delete opts.ownsChrome;
	delete opts.mapConfig;

	return makeConfig( Object.assign( { mapConfig: mapConfig }, opts ) );
}

function buildCheckoutDom() {
	document.body.innerHTML =
		'<div data-woodev-pickup-slot="' + FIELD_ID + '" style="display:none;"></div>' +
		'<input id="' + FIELD_ID + '" type="hidden" value="" />' +
		'<input id="billing_address_1" value="" />' +
		'<select id="billing_city"><option value="">--</option></select>' +
		'<input id="billing_postcode" value="" />' +
		'<input id="shipping_address_1" value="" />' +
		'<select id="shipping_city"><option value="">--</option></select>' +
		'<input id="shipping_postcode" value="" />' +
		'<input type="checkbox" name="ship_to_different_address" />';
}

/**
 * Registers a §8 store that manages ONLY the pickup field and `billing_city` —
 * a realistic shape (a plugin's Checkout_Fields commonly declares a takeover
 * target like `billing_city`), deliberately NOT `billing_address_1`/
 * `billing_postcode`/`shipping_*` — those are plain WooCommerce core fields no
 * real §8 config registers, proving C2: the mount must degrade to DOM-only for
 * them rather than silently "succeeding" against a fabricated store no §8
 * consumer would ever read.
 */
function makeStore() {
	return createStore( {
		fields: {
			carrier_pickup_point: { id: FIELD_ID },
			billing_city: { id: 'billing_city' },
		},
	} );
}

function setConfig( config ) {
	window.woodev_pickup_config_p = config;
}

function clickTrigger() {
	const trigger = document.querySelector( '.woodev-pickup-trigger' );
	trigger.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
	return trigger;
}

/**
 * Sets a `<select>` city field's value — `billing_city`/`shipping_city` are real
 * `<select>` elements in {@see buildCheckoutDom}, so a bare `.value = x` assignment with
 * no matching `<option>` silently no-ops (jsdom faithfully replicates the real DOM here —
 * the exact bounded-option behaviour `ensureOption()` in pickup-mount.js itself works
 * around). Adds the missing option first, mirroring that production helper.
 */
function setCitySelectValue( fieldId, value ) {
	const select = document.getElementById( fieldId );

	if ( ! Array.prototype.slice.call( select.options ).some( ( o ) => o.value === value ) ) {
		const option = document.createElement( 'option' );

		option.value = value;
		option.text = value;
		select.appendChild( option );
	}

	select.value = value;
}

/**
 * A normalized point, with valid `lat`/`lng`/`type` by default (Task 20's `groupByPosition()`
 * wiring needs a real position; earlier tasks' fixtures never carried one).
 */
function point( overrides ) {
	return Object.assign(
		{
			id: 'PVZ-1',
			name: 'Точка',
			address: 'ул. Ленина, 1',
			short_address: 'Ленина, 1',
			locality: 'Москва',
			postal_code: '101000',
			lat: 55.75,
			lng: 37.61,
			type: { code: 'pvz', label: 'ПВЗ' },
		},
		overrides
	);
}

/**
 * Task 12: a single map-position "group" of points for {@see openPicker}'s `drawPoints()`.
 *
 * The plan's own illustrative snippet called this `group( 'g1', [ point( 'P1' ) ] )` — an
 * imaginary named-group shape. The REAL `groupByPosition()` (`pickup-geo.js`) has no such
 * concept: its key is literally `lat.toFixed(4) + ',' + lng.toFixed(4)`, computed from the
 * points' own coordinates (see that function's own docblock). This helper builds real points at
 * one shared `[lat, lng]` and returns the key `groupByPosition()` will actually produce for
 * them, so a test can assert `provider.focusGroup()` was called with a value that is really
 * reachable rather than a label that only exists in the test.
 *
 * @param {number} lat
 * @param {number} lng
 * @param {Array<string>} pointIds
 * @returns {{ key: string, points: Array<Object> }}
 */
function group( lat, lng, pointIds ) {
	return {
		key: lat.toFixed( 4 ) + ',' + lng.toFixed( 4 ),
		points: pointIds.map( function( id ) {
			return point( { id: id, lat: lat, lng: lng } );
		} ),
	};
}

/**
 * Spec-style session helper: sets the config, mounts, clicks the trigger, and flushes the
 * `init()` → initial-fetch microtask chain — matching the T20 spec's own `openSession( config )`
 * calls. Returns the most recently constructed provider/panels doubles plus the session's own
 * `refresh()`, exactly the shape the spec's literal test bodies use
 * (`session.panels.emit(...)`, `session.provider.emit(...)`, `session.refresh`).
 *
 * @param {Object} config
 */
async function openSession( config ) {
	setConfig( config );
	mountAll();
	clickTrigger();
	await flushAsync();

	const session = getSession( config.fieldId );

	return {
		provider: StubProvider.instances[ StubProvider.instances.length - 1 ],
		panels: StubPanels.instances.length ? StubPanels.instances[ StubPanels.instances.length - 1 ] : null,
		refresh: session ? session.refresh : null,
	};
}

/**
 * Drives one selection all the way through the server round trip, for the tests whose subject
 * is what the mount WRITES (the §8 store, the address fields, the trigger label, the public
 * events) rather than the round trip itself. Task 11 made `handleSelection()` asynchronous —
 * nothing is written until `dataSource.selectPoint()` answers — so every one of those tests now
 * has to let that answer land before it looks. The default fake datasource resolves with
 * "accepted, no advice" ({@see fakeDataSourceFactory}).
 *
 * @param {Object} emitter a `StubProvider` or `StubPanels` instance — either side may report a
 *                        selection (the panels' card CTA normally, the provider itself under
 *                        `ownsChrome`), and both funnel into the same `handleSelection()`.
 * @param {Object} chosen  the point object to report.
 */
async function selectAndConfirm( emitter, chosen ) {
	emitter.emit( 'select', chosen );

	await flushAsync();
}

/**
 * The selection-confirmation harness: one open picker whose `selectPoint()` round trip is held
 * OPEN so a test can decide, statement by statement, when and how it answers.
 *
 * It is a distinct helper from {@see openSession} rather than an extension of it because the
 * two want opposite things from the datasource. `openSession()` exists to get a session past
 * its opening fetch and hand back the doubles; this one exists to freeze a confirmation
 * mid-flight — `emitSelect()` starts it and NOTHING settles until the test calls
 * `resolveSelect()`/`rejectSelect()`, which is the only way to assert on the in-flight state
 * (the busy lock, the request payload) at all. It is deliberately SYNCHRONOUS: the opening
 * `init()`/fetch chain is irrelevant to a confirmation, and awaiting it here would mean every
 * test in the block awaiting a promise it does not care about.
 *
 * `overrides.selection` and `overrides.i18n` are MERGED onto the production-shaped defaults
 * rather than replacing them (a test naming one i18n key must not blank out the other thirty);
 * everything else passes straight through to {@see configWith}.
 *
 * @param {Object} [overrides]
 */
function openPicker( overrides ) {
	const opts = Object.assign( {}, overrides );
	const selection = Object.assign( { close: false, refreshCheckout: false }, opts.selection );
	const i18n = phpI18n( opts.i18n );

	delete opts.selection;
	delete opts.i18n;

	/** @type {Array<{resolve: Function, reject: Function}>} one entry per in-flight confirmation. */
	const inFlight = [];
	const selectPoint = jest.fn( function() {
		return new Promise( ( resolve, reject ) => {
			inFlight.push( { resolve, reject } );
		} );
	} );

	// Task 12: `drawPoints()` (added to the returned object below) points this at a fresh array
	// before flushing, so the session's PENDING opening fetch — `openPicker()`, unlike
	// {@see openSession}, never awaits past `clickTrigger()` — resolves with whatever a test
	// wants drawn instead of the default empty result.
	let nextPoints = [];
	const factory = fakeDataSourceFactory( () => Promise.resolve( nextPoints ) );
	factory.selectPoint = selectPoint;
	window.WoodevPickupDataSource = factory;

	// A jQuery double, because `update_checkout` is a jQuery custom event and unreachable
	// without one (see pickup-mount.js's own docblock on the identical asymmetry for
	// `updated_checkout`). `one()` is recorded rather than executed so a test can prove the
	// busy state is HELD until the refresh settles, not released immediately — and `off()` is
	// recorded so a test can prove the waiter is UNBOUND when the session dies before
	// WooCommerce ever answers (a `one()` that never fires never cleans itself up).
	const jq = { triggered: [], one: [], off: [] };
	window.jQuery = () => ( {
		one: ( type, handler ) => jq.one.push( { type, handler } ),
		off: ( type, handler ) => jq.off.push( { type, handler } ),
		trigger: ( type ) => jq.triggered.push( type ),
	} );

	makeStore();

	// Address replacement is another task's subject, and every field it writes that no §8
	// store owns logs a `console.warn` the WP jest preset turns into a failure — an
	// acknowledgement in twelve tests that are not about it would be pure noise.
	const config = configWith( Object.assign(
		{ selection, i18n, replaceAddress: { enabled: false, billingOnly: true } },
		opts
	) );

	setConfig( config );
	mountAll();
	clickTrigger();

	const session = getSession( config.fieldId );
	const provider = StubProvider.instances[ StubProvider.instances.length - 1 ];
	// null under `ownsChrome` — the framework builds no chrome of its own for an embedded
	// provider, so there is no card to lock and the provider reports its own selections.
	const panels = StubPanels.instances.length ? StubPanels.instances[ StubPanels.instances.length - 1 ] : null;

	// Spied on the INSTANCE (the real `WoodevModal` still runs — `close()` must really close,
	// or the vetoed/not-vetoed distinction the mount branches on would be fiction).
	jest.spyOn( session.modal, 'close' );

	return {
		config,
		panels,
		provider,
		modal: session.modal,
		jq,
		dataSource: { selectPoint },
		field: document.getElementById( config.fieldId ),

		/**
		 * Reports a selection the way the card's CTA does — or, with no card of ours in play
		 * (`ownsChrome`), the way the embedded provider reports its own. Both funnel into the
		 * same `handleSelection()`.
		 *
		 * @param {Object} pointOverrides merged onto the standard {@see point} fixture.
		 */
		emitSelect( pointOverrides ) {
			( panels || provider ).emit( 'select', point( pointOverrides ) );
		},

		/**
		 * Task 12: resolves ONE fetch with the given {@see group}s' points and settles every
		 * microtask this causes — the mount's `restoreSelection()` reads `groupsByKey`, which
		 * only exists once `fetchAndSetPoints()` has actually run against them.
		 *
		 * Under `strategy: 'bulk'` (the default) the session's own ONE automatic fetch — on
		 * `init()` resolve — is still pending the first time a test calls this, so a flush alone
		 * reaches it. Under `'viewport'` nothing ever fetches on its own; a real pan is what
		 * reports a bbox, so this drives one via `boundsChange` — which also makes THIS call
		 * reusable for a SECOND, THIRD, … fetch within the same session (a real pan back onto
		 * a point, a type-filter refetch), the exact repeated-fetch shape discrepancy (a) is
		 * about.
		 *
		 * @param {Array<{ key: string, points: Array<Object> }>} groups
		 */
		async drawPoints( groups ) {
			nextPoints = groups.reduce( ( all, g ) => all.concat( g.points ), [] );

			if ( 'viewport' === config.strategy ) {
				provider.emit( 'boundsChange', [ 0, 0, 1, 1 ] );
			}

			await flushAsync();
		},

		/**
		 * Answers the OLDEST in-flight confirmation with a server verdict, then drains the
		 * microtask queue so the mount has fully applied it by the time the caller asserts.
		 *
		 * @param {Object} result the select route's response body.
		 */
		async resolveSelect( result ) {
			inFlight.shift().resolve( result );

			await flushAsync();
		},

		/**
		 * Fails the OLDEST in-flight confirmation the way the datasource reports a transport
		 * failure — the `{ status, code, message }` shape, never an `Error`.
		 *
		 * @param {Object} reason
		 */
		async rejectSelect( reason ) {
			inFlight.shift().reject( reason );

			await flushAsync();
		},

		/**
		 * Moves the card onto a DIFFERENT point while a confirmation is in flight — a marker
		 * click on the map, which the card lock cannot intercept (it locks the card, not the
		 * map). Drives the real `cardOpened` event the panels emit from `openCard()`.
		 *
		 * @param {string} pointId
		 */
		setActivePoint( pointId ) {
			panels.emit( 'cardOpened', {
				group: { key: 'g-' + pointId, points: [ point( { id: pointId } ) ] },
				pointId,
				origin: 'marker',
			} );
		},
	};
}

/**
 * The factory `beforeEach()` installs on `window` — held here so a test can read its
 * `fetchDetails` spy or swap it for a controllable one (issue #219). `openPicker()` installs its
 * OWN factory and is deliberately not covered by this handle.
 *
 * @type {Function}
 */
let dataSourceFactory;

beforeEach( () => {
	StubProvider.instances = [];
	StubPanels.instances = [];
	callOrder = [];
	buildCheckoutDom();
	window.WoodevPickupMapProviders = { testProvider: StubProvider };
	dataSourceFactory = fakeDataSourceFactory( () => Promise.resolve( [] ) );
	window.WoodevPickupDataSource = dataSourceFactory;
	window.WoodevPickupPanels = StubPanels;
} );

afterEach( () => {
	document.body.innerHTML = '';
	delete window.woodev_pickup_config_p;
	delete window.WoodevPickupMapProviders;
	delete window.WoodevPickupDataSource;
	delete window.WoodevPickupPanels;
	delete window.jQuery;
} );

// -------------------------------------------------------------------------
// Idempotent mounting
// -------------------------------------------------------------------------

test( 'mounts exactly one trigger into the anchor, labelled from the PHP-emitted i18n.trigger key', () => {
	setConfig( makeConfig() );
	mountAll();

	const slot = document.querySelector( '[data-woodev-pickup-slot="' + FIELD_ID + '"]' );
	const triggers = slot.querySelectorAll( '.woodev-pickup-trigger' );
	expect( triggers.length ).toBe( 1 );
	expect( triggers[ 0 ].textContent ).toBe( 'Выбрать пункт выдачи' );
} );

test( 'mounting again on the SAME slot never duplicates the trigger', () => {
	setConfig( makeConfig() );
	mountAll();
	mountAll();
	mountAll();

	const slot = document.querySelector( '[data-woodev-pickup-slot="' + FIELD_ID + '"]' );
	expect( slot.querySelectorAll( '.woodev-pickup-trigger' ).length ).toBe( 1 );
} );

test( 'a slot RE-CREATED by §8 (WooCommerce replaced the fragment) gets re-mounted, still only once', () => {
	setConfig( makeConfig() );
	mountAll();

	const oldSlot = document.querySelector( '[data-woodev-pickup-slot="' + FIELD_ID + '"]' );
	oldSlot.parentNode.removeChild( oldSlot );
	const freshSlot = document.createElement( 'div' );
	freshSlot.setAttribute( 'data-woodev-pickup-slot', FIELD_ID );
	document.body.appendChild( freshSlot );

	mountAll();

	expect( freshSlot.querySelectorAll( '.woodev-pickup-trigger' ).length ).toBe( 1 );
	expect( document.querySelectorAll( '.woodev-pickup-trigger' ).length ).toBe( 1 );
} );

test( 'hooks `updated_checkout`, deferred by EXACTLY 60ms, and re-mounts through it', () => {
	setConfig( makeConfig() );

	document.body.dispatchEvent( new Event( 'updated_checkout' ) );
	expect( document.querySelectorAll( '.woodev-pickup-trigger' ).length ).toBe( 0 );

	jest.advanceTimersByTime( 59 );
	expect( document.querySelectorAll( '.woodev-pickup-trigger' ).length ).toBe( 0 );

	jest.advanceTimersByTime( 1 );
	expect( document.querySelectorAll( '.woodev-pickup-trigger' ).length ).toBe( 1 );
} );

// -------------------------------------------------------------------------
// Trigger label toggle: i18n.trigger vs i18n.triggerChange
// -------------------------------------------------------------------------

test( 'the trigger reads i18n.trigger when the field has no value yet', () => {
	setConfig( makeConfig() );
	mountAll();

	expect( document.querySelector( '.woodev-pickup-trigger' ).textContent ).toBe( 'Выбрать пункт выдачи' );
} );

test( 'a re-mount with an already-selected field value shows i18n.triggerChange immediately', () => {
	document.getElementById( FIELD_ID ).value = 'PVZ-EXISTING';
	setConfig( makeConfig() );
	mountAll();

	expect( document.querySelector( '.woodev-pickup-trigger' ).textContent )
		.toBe( 'Выбрать другой пункт выдачи' );
} );

test( 'the trigger switches to i18n.triggerChange right after a NEW selection is applied', async () => {
	makeStore();
	setConfig( makeConfig( { replaceAddress: { enabled: false, billingOnly: true } } ) );
	mountAll();
	clickTrigger();

	await selectAndConfirm( StubProvider.instances[ 0 ], point( { id: 'PVZ-9' } ) );

	expect( document.querySelector( '.woodev-pickup-trigger' ).textContent )
		.toBe( 'Выбрать другой пункт выдачи' );
} );

// -------------------------------------------------------------------------
// Click → modal → provider
// -------------------------------------------------------------------------

test( 'clicking the trigger opens the shell and calls provider.init with the container, config, dataSource', () => {
	const config = makeConfig();
	setConfig( config );
	mountAll();

	clickTrigger();

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog ).not.toBeNull();

	expect( StubProvider.instances.length ).toBe( 1 );
	const calls = StubProvider.instances[ 0 ].initCalls;
	expect( calls.length ).toBe( 1 );
	expect( calls[ 0 ].container ).toBeInstanceOf( HTMLElement );
	expect( dialog.contains( calls[ 0 ].container ) ).toBe( true );
	// Specifically the PANELS' map element, not the dialog body: a canvas built as a sibling of
	// the stage sits outside the stage's positioning context, and the page then carries two
	// `.woodev-pickup-map` nodes (spec V-3).
	expect( calls[ 0 ].container ).toBe( StubPanels.instances[ 0 ].getMapElement() );
	// The provider config is the MERGE buildProviderConfig() builds — mapConfig's own
	// keys plus strategy/i18n/locality — never config.mapConfig passed through raw.
	expect( calls[ 0 ].config ).toEqual( {
		center: [ 55.75, 37.61 ],
		strategy: 'bulk',
		i18n: config.i18n,
		locality: '',
		defaultLocation: config.defaultLocation,
		pointIcons: config.pointIcons,
		accentColor: config.accentColor,
		// Task 12, spec V-6: the search layout panels.buildSearchLayout() built ONCE, handed
		// through as a plain DOM element — never a reference to the panels instance itself.
		searchLayoutEl: StubPanels.instances[ 0 ].builtSearchLayoutEl,
	} );
	// Task 20: the provider contract dropped fetching, but the raw dataSource is still
	// passed as the 3rd arg for a provider that (like Embedded_Map_Provider) still declares
	// it, unused, in its own signature.
	expect( typeof calls[ 0 ].dataSource.fetchPoints ).toBe( 'function' );
	expect( typeof calls[ 0 ].dataSource.fetchDetails ).toBe( 'function' );
} );

// -------------------------------------------------------------------------
// Live nonce reader (#157) — the datasource is handed a FUNCTION (never a
// captured string) that reads whichever node currently holds a valid nonce,
// so a nonce rotated by a later `update_checkout` fragment refresh is still
// picked up. Exercises the REAL closure `openSession()` builds — via
// `fakeDataSourceFactory`'s captured `options` (see its own docblock) —
// rather than exporting `currentNonce()` as a second, test-only surface.
// -------------------------------------------------------------------------

test( 'the datasource nonce reader prefers the live fragment node over the page-load config.nonce', () => {
	const config = makeConfig( { nonce: 'page-load-nonce' } );
	setConfig( config );

	const node = document.createElement( 'span' );
	node.id = config.nonceNodeId;
	node.setAttribute( 'data-woodev-pickup-nonce', 'fresh-fragment-nonce' );
	document.body.appendChild( node );

	mountAll();
	clickTrigger();

	expect( window.WoodevPickupDataSource.lastOptions.nonce() ).toBe( 'fresh-fragment-nonce' );
} );

test( 'the datasource nonce reader falls back to config.nonce when the fragment node is absent', () => {
	const config = makeConfig( { nonce: 'page-load-nonce' } );
	setConfig( config );

	mountAll();
	clickTrigger();

	expect( document.getElementById( config.nonceNodeId ) ).toBeNull();
	expect( window.WoodevPickupDataSource.lastOptions.nonce() ).toBe( 'page-load-nonce' );
} );

test( 'the session tags its modal with the documented pickup modalId on every modal event', () => {
	const opened = [];
	const closed = [];
	document.body.addEventListener( 'woodev_modal_opened', ( e ) => opened.push( e.detail ) );
	document.body.addEventListener( 'woodev_modal_closed', ( e ) => closed.push( e.detail ) );

	setConfig( makeConfig() );
	mountAll();
	clickTrigger();

	// D-14 fixes this literal value: a consumer filters the pickup dialog out of the
	// framework's generic modal stream by it, exactly as the reference integrations filter
	// WooCommerce's backbone modal by its `target`. An empty modalId reaches every listener
	// and matches none of them — green code, dead feature.
	expect( opened ).toHaveLength( 1 );
	expect( opened[ 0 ].modalId ).toBe( 'woodev-pickup-map' );

	document.querySelector( '.woodev-modal__close' ).click();

	expect( closed ).toHaveLength( 1 );
	expect( closed[ 0 ].modalId ).toBe( 'woodev-pickup-map' );
	expect( closed[ 0 ].reason ).toBe( 'button' );
} );

test( 'config.modal.width/bodyHeight reach the real WoodevModal dialog and body (spec V-1, Task 18)', () => {
	setConfig( makeConfig( { modal: { width: 920, bodyHeight: 'min(80vh, 800px)' } } ) );
	mountAll();
	clickTrigger();

	const dialog = document.querySelector( '[role="dialog"]' );
	const body = dialog.querySelector( '.woodev-modal__body' );

	expect( dialog.style.minWidth ).toBe( '920px' );
	expect( body.style.height ).toBe( 'min(80vh, 800px)' );
} );

// -------------------------------------------------------------------------
// buildProviderConfig() — the mapConfig/strategy/i18n/locality merge handed
// to the map provider's init(), and locality's LIVE resolution
// -------------------------------------------------------------------------

test( 'the provider config merges mapConfig with strategy, i18n, and the resolved locality', () => {
	const config = makeConfig( {
		strategy: 'viewport',
		mapConfig: { scriptUrl: 'https://example.test/ymaps.js', ns: 'WoodevPickupMap', hasApiKey: true },
	} );
	setConfig( config );
	mountAll();

	setCitySelectValue( 'billing_city', 'Казань' );

	clickTrigger();

	const receivedConfig = StubProvider.instances[ 0 ].initCalls[ 0 ].config;
	expect( receivedConfig ).toEqual( {
		scriptUrl: 'https://example.test/ymaps.js',
		ns: 'WoodevPickupMap',
		hasApiKey: true,
		strategy: 'viewport',
		i18n: config.i18n,
		locality: 'Казань',
		// Everything below is a TOP-LEVEL key of the mount config that the provider reads off
		// the config it is handed. `toEqual` is deliberate: it fails on a MISSING key as loudly
		// as on a wrong one, which a per-key `toMatchObject` would not.
		defaultLocation: config.defaultLocation,
		pointIcons: config.pointIcons,
		accentColor: config.accentColor,
		searchLayoutEl: StubPanels.instances[ 0 ].builtSearchLayoutEl,
	} );
} );

test( 'every top-level key the provider reads survives the provider-config merge', () => {
	// The regression this pins was silent and total: with `defaultLocation` missing the map
	// opened on the Atlantic instead of the buyer's city, and with `pointIcons` missing every
	// marker rendered as an empty box. Neither threw, neither logged.
	setConfig( makeConfig() );
	mountAll();
	clickTrigger();

	const received = StubProvider.instances[ 0 ].initCalls[ 0 ].config;

	[ 'defaultLocation', 'pointIcons', 'accentColor', 'strategy', 'i18n', 'searchLayoutEl' ]
		.forEach( ( key ) => {
			expect( received[ key ] ).toBeDefined();
		} );

	expect( received.defaultLocation ).toEqual( { center: [ 55.76, 37.64 ], zoom: 12 } );
	expect( received.pointIcons.PVZ.default ).toBe( 'https://example.test/pvz.svg' );
	expect( received.accentColor ).toBe( '#06aedd' );
} );

test( 'the BULK points query carries the live locality, not just the type filter', async () => {
	// The bug this pins shipped green: the bulk fetch sent only `{ types }`, so the server
	// got a query naming neither a locality nor a bbox, correctly refused it, and the
	// customer saw an empty map in a city full of points — with no error anywhere. Found on
	// the rig, invisible to every test in this file at the time.
	const queries = [];
	window.WoodevPickupDataSource = fakeDataSourceFactory( ( query ) => {
		queries.push( query );

		return Promise.resolve( [] );
	} );

	setConfig( makeConfig( { strategy: 'bulk' } ) );
	mountAll();
	setCitySelectValue( 'billing_city', 'Москва' );

	clickTrigger();
	await flushAsync();

	expect( queries ).toHaveLength( 1 );
	expect( queries[ 0 ].locality ).toBe( 'Москва' );
} );

test( 'refresh() re-reads the city, so a locality changed while the map is open is used', async () => {
	const queries = [];
	window.WoodevPickupDataSource = fakeDataSourceFactory( ( query ) => {
		queries.push( query );

		return Promise.resolve( [] );
	} );

	setConfig( makeConfig( { strategy: 'bulk' } ) );
	mountAll();
	setCitySelectValue( 'billing_city', 'Москва' );

	clickTrigger();
	await flushAsync();

	setCitySelectValue( 'billing_city', 'Казань' );
	await getSession( FIELD_ID ).refresh();

	expect( queries.map( ( q ) => q.locality ) ).toEqual( [ 'Москва', 'Казань' ] );
} );

test( 'locality is resolved against the LIVE ship-to-different-address target, not billing unconditionally', () => {
	const config = makeConfig( { replaceAddress: { enabled: true, billingOnly: false } } );
	setConfig( config );
	document.querySelector( '[name="ship_to_different_address"]' ).checked = true;
	setCitySelectValue( 'shipping_city', 'Новосибирск' );
	setCitySelectValue( 'billing_city', 'Москва' ); // must be ignored — shipping is the live target
	mountAll();

	clickTrigger();

	expect( StubProvider.instances[ 0 ].initCalls[ 0 ].config.locality ).toBe( 'Новосибирск' );
} );

test( 'locality is an empty string, never undefined, when the resolved city field is absent or blank', () => {
	setConfig( makeConfig() );
	mountAll();

	clickTrigger();

	expect( StubProvider.instances[ 0 ].initCalls[ 0 ].config.locality ).toBe( '' );
} );

test( 'locality is resolved fresh on EACH open, not cached from the first', () => {
	const config = makeConfig( { replaceAddress: { enabled: false, billingOnly: true } } );
	setConfig( config );
	mountAll();

	setCitySelectValue( 'billing_city', 'Первый Город' );
	clickTrigger();
	expect( StubProvider.instances[ 0 ].initCalls[ 0 ].config.locality ).toBe( 'Первый Город' );

	// Close the session, change the field, and open a fresh one.
	document.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );
	setCitySelectValue( 'billing_city', 'Второй Город' );
	clickTrigger();

	expect( StubProvider.instances[ 1 ].initCalls[ 0 ].config.locality ).toBe( 'Второй Город' );
} );

test( 'the modal title comes from the PHP-emitted i18n.modalTitle key', () => {
	const config = makeConfig( { i18n: phpI18n( { modalTitle: 'Заголовок из PHP' } ) } );
	setConfig( config );
	mountAll();
	clickTrigger();

	const dialog = document.querySelector( '[role="dialog"]' );
	const titleId = dialog.getAttribute( 'aria-labelledby' );
	expect( document.getElementById( titleId ).textContent ).toBe( 'Заголовок из PHP' );
} );

test( 'an unresolvable provider id shows the generic error without throwing', () => {
	setConfig( makeConfig( { provider: 'does_not_exist' } ) );
	mountAll();

	expect( () => clickTrigger() ).not.toThrow();

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog.textContent ).toContain( 'Не удалось загрузить пункты выдачи' );
	expect( StubProvider.instances.length ).toBe( 0 );
} );

// -------------------------------------------------------------------------
// select → write THROUGH the field's OWN owning store, fire change, close with reason 'select'
// -------------------------------------------------------------------------

test( 'select writes the point id through the store (not the DOM directly) and fires change on the field', async () => {
	const store = makeStore();
	setConfig( makeConfig( { replaceAddress: { enabled: false, billingOnly: true } } ) );
	mountAll();
	clickTrigger();

	const field = document.getElementById( FIELD_ID );
	const changeSpy = jest.fn();
	field.addEventListener( 'change', changeSpy );

	await selectAndConfirm( StubProvider.instances[ 0 ], point( { id: 'PVZ-42' } ) );

	expect( store.getValue( FIELD_ID ) ).toBe( 'PVZ-42' );
	expect( changeSpy ).toHaveBeenCalledTimes( 1 );
	// A real native Event, not a synthetic no-op — checkout-field-classic.js's own gate
	// treats only a truthy `originalEvent` (jQuery's name for this) as meaningful.
	expect( changeSpy.mock.calls[ 0 ][ 0 ].bubbles ).toBe( true );
} );

test( 'select fires woodev_pickup_point_selected and closes the shell with reason "select"', async () => {
	makeStore();
	// `selection.close: true` is stated, not assumed: the framework's own default is `false`
	// (the customer stays in the map and gets a «Продолжить оформление» CTA — Task 11/D-3), so
	// a test about the CLOSING path has to be the plugin that configured closing.
	setConfig( makeConfig( { selection: { close: true, refreshCheckout: false } } ) );
	mountAll();
	clickTrigger();

	const selected = [];
	const closed = [];
	document.body.addEventListener( 'woodev_pickup_point_selected', ( e ) => selected.push( e.detail ) );
	document.body.addEventListener( 'woodev_modal_closed', ( e ) => closed.push( e.detail ) );

	await selectAndConfirm( StubProvider.instances[ 0 ], point( { id: 'PVZ-1' } ) );

	expect( selected ).toHaveLength( 1 );
	expect( selected[ 0 ].fieldId ).toBe( FIELD_ID );
	expect( selected[ 0 ].point.id ).toBe( 'PVZ-1' );
	expect( closed[ 0 ].reason ).toBe( 'select' );

	expect( document.querySelector( '[role="dialog"]' ) ).toBeNull();
	expect( StubProvider.instances[ 0 ].destroyed ).toBe( true );
	// The default config's address replacement writes billing_address_1/postcode too,
	// which no store in this test manages — an expected, acknowledged warn (see C2).
	expect( console ).toHaveWarned();
} );

// -------------------------------------------------------------------------
// C2 — per-field store resolution: a field with no owning store still gets
// written to the DOM, but NOT through a fabricated store no §8 consumer reads
// -------------------------------------------------------------------------

test( 'a field with no owning §8 store (billing_address_1) is written to the DOM but not through any store', async () => {
	const store = makeStore(); // manages carrier_pickup_point + billing_city only
	setConfig( makeConfig( { replaceAddress: { enabled: true, billingOnly: true } } ) );
	mountAll();
	clickTrigger();

	await selectAndConfirm( StubProvider.instances[ 0 ], point() );

	// The DOM is authoritative for the unmanaged field...
	expect( document.getElementById( 'billing_address_1' ).value ).toBe( 'ул. Ленина, 1' );
	// ...but no store anywhere claims to hold it (store.getValue on the ONE store that
	// exists must not have been used as a dumping ground for a field it does not manage).
	expect( store.getValue( 'billing_address_1' ) ).toBeUndefined();
	// The write logs precisely BECAUSE no store owns the field — acknowledge it.
	expect( console ).toHaveWarned();
} );

test( 'a field WITH an owning §8 store (billing_city) is written through that store', async () => {
	const store = makeStore();
	setConfig( makeConfig( { replaceAddress: { enabled: true, billingOnly: true } } ) );
	mountAll();
	clickTrigger();

	await selectAndConfirm( StubProvider.instances[ 0 ], point() );

	expect( store.getValue( 'billing_city' ) ).toBe( 'Москва' );
	expect( document.getElementById( 'billing_city' ).value ).toBe( 'Москва' );
	// address_1/postcode are written too (unmanaged, DOM-only) — acknowledge the warn.
	expect( console ).toHaveWarned();
} );

// -------------------------------------------------------------------------
// Address replacement — target resolution + missing option
// -------------------------------------------------------------------------

test( 'address replacement writes to billing_* when billingOnly is true, regardless of the checkbox', async () => {
	makeStore();
	setConfig( makeConfig( { replaceAddress: { enabled: true, billingOnly: true } } ) );
	document.querySelector( '[name="ship_to_different_address"]' ).checked = true; // must be ignored
	mountAll();
	clickTrigger();

	await selectAndConfirm( StubProvider.instances[ 0 ], point() );

	expect( document.getElementById( 'billing_address_1' ).value ).toBe( 'ул. Ленина, 1' );
	expect( document.getElementById( 'billing_city' ).value ).toBe( 'Москва' );
	expect( document.getElementById( 'billing_postcode' ).value ).toBe( '101000' );

	expect( document.getElementById( 'shipping_address_1' ).value ).toBe( '' );
	expect( console ).toHaveWarned(); // address_1/postcode are unmanaged — acknowledge.
} );

test( 'address replacement writes to billing_* when the "ship to a different address" checkbox is UNCHECKED', async () => {
	makeStore();
	setConfig( makeConfig( { replaceAddress: { enabled: true, billingOnly: false } } ) );
	document.querySelector( '[name="ship_to_different_address"]' ).checked = false;
	mountAll();
	clickTrigger();

	await selectAndConfirm( StubProvider.instances[ 0 ], point() );

	expect( document.getElementById( 'billing_city' ).value ).toBe( 'Москва' );
	expect( document.getElementById( 'shipping_city' ).value ).toBe( '' );
	expect( console ).toHaveWarned(); // address_1/postcode are unmanaged — acknowledge.
} );

test( 'address replacement writes to shipping_* when the "ship to a different address" checkbox IS checked', async () => {
	makeStore();
	setConfig( makeConfig( { replaceAddress: { enabled: true, billingOnly: false } } ) );
	document.querySelector( '[name="ship_to_different_address"]' ).checked = true;
	mountAll();
	clickTrigger();

	await selectAndConfirm( StubProvider.instances[ 0 ], point() );

	expect( document.getElementById( 'shipping_address_1' ).value ).toBe( 'ул. Ленина, 1' );
	expect( document.getElementById( 'shipping_city' ).value ).toBe( 'Москва' );
	expect( document.getElementById( 'shipping_postcode' ).value ).toBe( '101000' );

	expect( document.getElementById( 'billing_address_1' ).value ).toBe( '' );
	expect( console ).toHaveWarned(); // shipping_* fields are unmanaged — acknowledge.
} );

test( 'a city with no matching <option> gets one added before the value is set', async () => {
	const store = makeStore();
	setConfig( makeConfig( { replaceAddress: { enabled: true, billingOnly: true } } ) );
	mountAll();
	clickTrigger();

	const citySelect = document.getElementById( 'billing_city' );
	expect( Array.prototype.slice.call( citySelect.options ).some( ( o ) => o.value === 'Казань' ) ).toBe( false );

	await selectAndConfirm( StubProvider.instances[ 0 ], point( { locality: 'Казань' } ) );

	expect( Array.prototype.slice.call( citySelect.options ).some( ( o ) => o.value === 'Казань' ) ).toBe( true );
	expect( citySelect.value ).toBe( 'Казань' );
	expect( store.getValue( 'billing_city' ) ).toBe( 'Казань' );
	expect( console ).toHaveWarned(); // address_1/postcode are unmanaged — acknowledge.
} );

test( 'replaceAddress.enabled: false writes no address field at all', async () => {
	const store = makeStore();
	setConfig( makeConfig( { replaceAddress: { enabled: false, billingOnly: true } } ) );
	mountAll();
	clickTrigger();

	await selectAndConfirm( StubProvider.instances[ 0 ], point() );

	expect( document.getElementById( 'billing_address_1' ).value ).toBe( '' );
	expect( document.getElementById( 'billing_city' ).value ).toBe( '' );
	expect( store.getValue( 'billing_city' ) ).toBeUndefined();
	// The pickup field id itself is unaffected by the enabled flag — only address replacement is gated.
	expect( store.getValue( FIELD_ID ) ).toBe( 'PVZ-1' );
} );

// -------------------------------------------------------------------------
// C3 — every written address field fires a real change, and change.select2
// when it is select2-enhanced (a tiny jQuery stub is needed for this one)
// -------------------------------------------------------------------------

test( 'a select2-enhanced address field gets change.select2 fired through jQuery, mirroring §8', async () => {
	makeStore();
	setConfig( makeConfig( { replaceAddress: { enabled: true, billingOnly: true } } ) );

	const citySelect = document.getElementById( 'billing_city' );
	citySelect.classList.add( 'select2-hidden-accessible' );

	const namespacedCalls = [];
	function FakeJQuery( el ) {
		return {
			trigger: function( eventName ) {
				if ( el === citySelect ) {
					namespacedCalls.push( eventName );
				}
			},
		};
	}
	window.jQuery = FakeJQuery;

	mountAll();
	clickTrigger();

	await selectAndConfirm( StubProvider.instances[ 0 ], point() );

	expect( namespacedCalls ).toContain( 'change.select2' );
	expect( console ).toHaveWarned(); // address_1/postcode are unmanaged — acknowledge.
} );

test( 'a plain (non-select2) address field does NOT get change.select2', async () => {
	makeStore();
	setConfig( makeConfig( { replaceAddress: { enabled: true, billingOnly: true } } ) );

	const calls = [];
	window.jQuery = function( el ) {
		return { trigger: function( eventName ) { calls.push( { el: el, eventName: eventName } ); } };
	};

	mountAll();
	clickTrigger();

	await selectAndConfirm( StubProvider.instances[ 0 ], point() );

	expect( calls.length ).toBe( 0 );
	expect( console ).toHaveWarned(); // address_1/postcode are unmanaged — acknowledge.
} );

// -------------------------------------------------------------------------
// dataSource error/empty mapping (Task 20: THIS FILE now calls fetchPoints()
// itself, right after provider.init() resolves under strategy: 'bulk')
// -------------------------------------------------------------------------

test.each( [
	[ 'woodev_pickup_upstream_error', 'upstreamError' ],
	[ 'woodev_pickup_rate_limited', 'rateLimited' ],
	[ 'woodev_pickup_point_not_found', 'notFound' ],
] )( 'dataSource code %s calls panels.showMessage( %s ) — the i18n KEY, not a pre-resolved string '
	+ '(Task 17, spec V-5: showMessage() resolves its own text, so a plugin\'s '
	+ '`woodev_pickup_map_i18n` override applies here exactly like everywhere else)',
async ( code, i18nKey ) => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () =>
		Promise.reject( { status: 502, code: code, message: 'raw ' + code } )
	);
	setConfig( makeConfig() );
	mountAll();
	clickTrigger();

	await flushAsync();

	const panels = StubPanels.instances[ StubPanels.instances.length - 1 ];
	expect( panels.showMessageCalls ).toEqual( [ i18nKey ] );
} );

test( 'an unmapped/unknown code calls panels.showMessage( \'error\' ) — the generic key, never the '
	+ 'raw code', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () =>
		Promise.reject( { status: 500, code: 'something_else', message: 'raw' } )
	);
	setConfig( makeConfig() );
	mountAll();
	clickTrigger();

	await flushAsync();

	const panels = StubPanels.instances[ StubPanels.instances.length - 1 ];
	expect( panels.showMessageCalls ).toEqual( [ 'error' ] );
} );

// -------------------------------------------------------------------------
// Stale nonce (#157b) — a 403 on the points fetch itself maps to the same
// "page is stale" message a stale select-route nonce does, not the generic
// fetch-error text. Two distinct codes collapse to one message: WordPress's
// own `rest_cookie_check_errors()` rejects an INVALID nonce before any
// `permission_callback` runs (`rest_cookie_invalid_nonce`), while a MISSING
// nonce header falls through to our own select-route permission callback
// (`woodev_pickup_invalid_nonce`) — see pickup-mount.js's ERROR_MESSAGE_KEYS
// docblock. Exercised the same observable way as the mappings above: a
// dataSource rejection reaching `panels.showMessage()` with the resolved
// i18n KEY, never a synthetic test-only export.
// -------------------------------------------------------------------------

test.each( [
	[ 'rest_cookie_invalid_nonce' ],
	[ 'woodev_pickup_invalid_nonce' ],
] )( 'dataSource code %s calls panels.showMessage( \'stalePage\' ), not the generic error', async ( code ) => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () =>
		Promise.reject( { status: 403, code: code, message: 'raw ' + code } )
	);
	setConfig( makeConfig() );
	mountAll();
	clickTrigger();

	await flushAsync();

	const panels = StubPanels.instances[ StubPanels.instances.length - 1 ];
	expect( panels.showMessageCalls ).toEqual( [ 'stalePage' ] );
} );

test( 'a genuinely empty BULK result calls panels.showMessage( \'emptyLocality\' ) — never the '
	+ 'generic `noResults` (reserved for the search view finding nothing, spec V-5) — and never '
	+ 'a destructive modal state (Task 20: panels share modal.getContainer() with the map — a '
	+ 'destructive showEmpty() would wipe them out for no reason a dataSource hiccup justifies)',
async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( [] ) );
	setConfig( makeConfig( { strategy: 'bulk' } ) );
	mountAll();
	clickTrigger();

	await flushAsync();

	const panels = StubPanels.instances[ StubPanels.instances.length - 1 ];
	expect( panels.showMessageCalls ).toEqual( [ 'emptyLocality' ] );

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog.querySelector( '.woodev-modal__message--error' ) ).toBeNull();
	expect( dialog.querySelector( '.woodev-modal__message--empty' ) ).toBeNull();
	expect( dialog.querySelector( '.woodev-modal__notice' ) ).toBeNull();
	// The panels chrome survived — it lives in the SAME container the destructive empty state
	// would otherwise have wiped.
	expect( dialog.querySelector( '.woodev-pickup-list' ) ).not.toBeNull();
} );

test( 'a genuinely empty VIEWPORT (boundsChange) result calls panels.showMessage( \'emptyInView\' ), '
	+ 'never `emptyLocality` (that key is bulk-only) or the generic `noResults` (spec V-5)', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( [] ) );
	setConfig( makeConfig( { strategy: 'viewport' } ) );
	mountAll();
	clickTrigger();
	await flushAsync();

	const panels = StubPanels.instances[ StubPanels.instances.length - 1 ];
	const provider = StubProvider.instances[ StubProvider.instances.length - 1 ];

	provider.emit( 'boundsChange', [ 1, 2, 3, 4 ] );
	await flushAsync();

	expect( panels.showMessageCalls ).toEqual( [ 'emptyInView' ] );
} );

// -------------------------------------------------------------------------
// #234 — viewport point accumulation: the drawn set is the UNION of every
// listing this session, never just the last one.
// -------------------------------------------------------------------------

test( 'viewport: two listings with disjoint points draw the UNION, not the last listing', async () => {
	const listings = [
		[ point( { id: 'A', lat: 55.1, lng: 37.1 } ) ],
		[ point( { id: 'B', lat: 55.2, lng: 37.2 } ) ],
	];
	let call = 0;
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( listings[ call++ ] || [] ) );
	setConfig( makeConfig( { strategy: 'viewport' } ) );
	mountAll();
	clickTrigger();
	await flushAsync();

	const provider = StubProvider.instances[ StubProvider.instances.length - 1 ];

	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();
	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();

	const drawn = provider.setPointsCalls[ provider.setPointsCalls.length - 1 ];
	const ids = drawn.reduce( ( acc, group ) => acc.concat( group.points.map( ( p ) => p.id ) ), [] ).sort();

	expect( ids ).toEqual( [ 'A', 'B' ] );
} );

test( 'viewport: a point in BOTH listings appears once, carrying the SECOND listing\'s values', async () => {
	const listings = [
		[ point( { id: 'A', name: 'Старое имя' } ) ],
		[ point( { id: 'A', name: 'Новое имя' } ) ],
	];
	let call = 0;
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( listings[ call++ ] || [] ) );
	setConfig( makeConfig( { strategy: 'viewport' } ) );
	mountAll();
	clickTrigger();
	await flushAsync();

	const provider = StubProvider.instances[ StubProvider.instances.length - 1 ];

	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();
	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();

	const drawn = provider.setPointsCalls[ provider.setPointsCalls.length - 1 ];
	const all = drawn.reduce( ( acc, group ) => acc.concat( group.points ), [] );

	expect( all ).toHaveLength( 1 );
	expect( all[ 0 ].name ).toBe( 'Новое имя' );
} );

test( 'bulk is unaffected — its listing still REPLACES the drawn set', async () => {
	const listings = [
		[ point( { id: 'A' } ) ],
		[ point( { id: 'B' } ) ],
	];
	let call = 0;
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( listings[ call++ ] || [] ) );
	setConfig( makeConfig( { strategy: 'bulk' } ) );
	mountAll();
	clickTrigger();
	await flushAsync();

	const provider = StubProvider.instances[ StubProvider.instances.length - 1 ];

	// bulk's second fetch can only come from refresh() — dispatching `updated_checkout` on
	// `document.body` alone does NOT call it. Verified against the source: refresh() is an
	// EXTERNAL hook exposed only via getSession() (see the file docblock, "the hook a
	// payment-method change elsewhere on the page uses"), nothing in this file wires it to
	// the `updated_checkout` DOM event itself, and every existing refresh() test in this
	// file invokes it the same way.
	await getSession( FIELD_ID ).refresh();

	const drawn = provider.setPointsCalls[ provider.setPointsCalls.length - 1 ];
	const ids = drawn.reduce( ( acc, group ) => acc.concat( group.points.map( ( p ) => p.id ) ), [] );

	expect( ids ).toEqual( [ 'B' ] );
} );

test( 'retry (start()) drops the pool — a fresh session starts from nothing', async () => {
	const listings = [
		[ point( { id: 'A' } ) ],
		[ point( { id: 'B' } ) ],
	];
	let call = 0;
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( listings[ call++ ] || [] ) );
	setConfig( makeConfig( { strategy: 'viewport' } ) );
	mountAll();
	clickTrigger();
	await flushAsync();

	let provider = StubProvider.instances[ StubProvider.instances.length - 1 ];
	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();

	const panels = StubPanels.instances[ StubPanels.instances.length - 1 ];
	panels.emit( 'retryRequested' );
	await flushAsync();

	provider = StubProvider.instances[ StubProvider.instances.length - 1 ];
	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();

	const drawn = provider.setPointsCalls[ provider.setPointsCalls.length - 1 ];
	const ids = drawn.reduce( ( acc, group ) => acc.concat( group.points.map( ( p ) => p.id ) ), [] );

	expect( ids ).toEqual( [ 'B' ] );
} );

// -------------------------------------------------------------------------
// #234 — the generation barrier: a reset must survive an in-flight listing.
//
// The plan this was written from drives the reset via `document.body.dispatchEvent(
// new Event( 'updated_checkout' ) )`. Verified against the source and against every
// other refresh() test in this file: that event alone never calls refresh() — it is an
// EXTERNAL hook exposed only via `getSession( fieldId ).refresh()` (see the file
// docblock, "the hook a payment-method change elsewhere on the page uses"), and
// `refresh()` itself does not call `resetPointPool()` yet — that wiring is Task 2, which
// this commit deliberately does not include. The only reset trigger implemented at this
// point in the plan's own sequence is `start()` (Task 1, step 6), reached the same way
// the "retry (start()) drops the pool" test above reaches it: a `retryRequested` event.
// -------------------------------------------------------------------------

test( '#234: a listing already in flight when the pool is reset is DROPPED on arrival', async () => {
	let releaseFirst;
	const first = new Promise( ( resolve ) => { releaseFirst = resolve; } );
	const responses = [ first, Promise.resolve( [ point( { id: 'B' } ) ] ) ];
	let call = 0;
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => responses[ call++ ] || Promise.resolve( [] ) );
	setConfig( makeConfig( { strategy: 'viewport' } ) );
	mountAll();
	clickTrigger();
	await flushAsync();

	const provider = StubProvider.instances[ StubProvider.instances.length - 1 ];
	const panels = StubPanels.instances[ StubPanels.instances.length - 1 ];

	// A listing goes out and does NOT settle yet.
	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();

	// A retry rebuilds the provider and resets the pool while the first listing is still
	// travelling.
	panels.emit( 'retryRequested' );
	await flushAsync();

	const freshProvider = StubProvider.instances[ StubProvider.instances.length - 1 ];

	// A second listing goes out on the fresh provider and settles normally.
	freshProvider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();

	// Only NOW does the stale first listing come back.
	releaseFirst( [ point( { id: 'STALE' } ) ] );
	await flushAsync();

	const drawn = freshProvider.setPointsCalls[ freshProvider.setPointsCalls.length - 1 ];
	const ids = drawn.reduce( ( acc, group ) => acc.concat( group.points.map( ( p ) => p.id ) ), [] );

	expect( ids ).not.toContain( 'STALE' );
} );

test( '#234: a listing in flight across NO reset still lands normally — the guard is not a '
	+ 'blanket drop', async () => {
	let releaseFirst;
	const first = new Promise( ( resolve ) => { releaseFirst = resolve; } );
	let call = 0;
	const responses = [ first ];
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => responses[ call++ ] || Promise.resolve( [] ) );
	setConfig( makeConfig( { strategy: 'viewport' } ) );
	mountAll();
	clickTrigger();
	await flushAsync();

	const provider = StubProvider.instances[ StubProvider.instances.length - 1 ];

	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();

	releaseFirst( [ point( { id: 'A' } ) ] );
	await flushAsync();

	const drawn = provider.setPointsCalls[ provider.setPointsCalls.length - 1 ];
	const ids = drawn.reduce( ( acc, group ) => acc.concat( group.points.map( ( p ) => p.id ) ), [] );

	expect( ids ).toEqual( [ 'A' ] );
} );

test( '#234 invariant: refresh() clears the pool AND the details memo in ONE call', async () => {
	const listings = [
		[ point( { id: 'A' } ) ],
		[ point( { id: 'B' } ) ],
	];
	let call = 0;
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( listings[ call++ ] || [] ) );
	window.WoodevPickupDataSource.fetchDetails = () =>
		Promise.resolve( point( { id: 'A', work_time: 'из деталей' } ) );

	setConfig( makeConfig( { strategy: 'viewport' } ) );
	mountAll();
	clickTrigger();
	await flushAsync();

	const provider = StubProvider.instances[ StubProvider.instances.length - 1 ];
	const panels = StubPanels.instances[ StubPanels.instances.length - 1 ];

	provider.emit( 'boundsChange', [ 55, 37, 56, 38 ] );
	await flushAsync();

	// Learn a detail for A, so the memo is demonstrably non-empty. The payload shape is
	// `{ group, pointId, origin }` — verified against this file's existing cardOpened tests,
	// NOT guessed.
	panels.emit( 'cardOpened', {
		group: provider.setPointsCalls[ provider.setPointsCalls.length - 1 ][ 0 ],
		pointId: 'A',
		origin: 'list',
	} );
	await flushAsync();

	// The cart changes. NOTE: `updated_checkout` does NOT reach refresh() — this file wires
	// that event to mountAll() only, and getSession() has no production caller at all (#238).
	// Every existing refresh() test in this file drives it directly, and so does this one.
	await window.WoodevPickupMount.getSession( FIELD_ID ).refresh();
	await flushAsync();

	const drawn = provider.setPointsCalls[ provider.setPointsCalls.length - 1 ];
	const all = drawn.reduce( ( acc, group ) => acc.concat( group.points ), [] );

	// Pool cleared: only the refresh listing's own point is drawn.
	expect( all.map( ( p ) => p.id ) ).toEqual( [ 'B' ] );
	// Memo cleared: nothing carries the stale detail field.
	expect( all.some( ( p ) => 'из деталей' === p.work_time ) ).toBe( false );
} );

test( 'a non-empty result calls neither showMessage() (nothing to show) nor leaves any destructive '
	+ 'modal state', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( [ point() ] ) );
	setConfig( makeConfig() );
	mountAll();
	clickTrigger();

	await flushAsync();

	const panels = StubPanels.instances[ StubPanels.instances.length - 1 ];
	expect( panels.showMessageCalls ).toBeUndefined();

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog.querySelector( '.woodev-modal__message--error' ) ).toBeNull();
	expect( dialog.querySelector( '.woodev-modal__message--empty' ) ).toBeNull();
} );

// -------------------------------------------------------------------------
// C1 — non-destructive degradation once a point set has been drawn (Task 20:
// the SECOND fetch within a bulk session now comes from refresh(), the only
// way to re-fetch without a real viewport/type-filter change), and retry
// always destroying the live provider and constructing a fresh one
// -------------------------------------------------------------------------

/**
 * A provider double that marks its container as "drawn" on init() — shared by the two C1 tests
 * below to prove drawn content survives a subsequent empty/failed fetch.
 */
function DrawingProvider() {
	StubProvider.call( this );
}
DrawingProvider.prototype = Object.create( StubProvider.prototype );
DrawingProvider.prototype.init = function( container, config, dataSource ) {
	StubProvider.prototype.init.call( this, container, config, dataSource );

	if ( ! container.querySelector( '.drawn-map-marker' ) ) {
		const marker = document.createElement( 'div' );
		marker.className = 'drawn-map-marker';
		container.appendChild( marker );
	}
};

test( 'once a set is drawn, a SUBSEQUENT empty refresh() calls panels.showMessage( \'emptyLocality\' ), '
	+ 'keeping the drawn content — the card never destroys anything (spec V-5), so this no longer '
	+ 'needs the OLD hasDrawnPoints-gated notice-vs-destructive-replace distinction', async () => {
	let resolveWith = [ point() ];
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( resolveWith ) );
	setConfig( makeConfig() );
	window.WoodevPickupMapProviders = { testProvider: DrawingProvider };

	mountAll();
	clickTrigger();
	await flushAsync();

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog.querySelector( '.drawn-map-marker' ) ).not.toBeNull();

	// A changed viewport/payment method, via refresh() — the only re-fetch trigger under
	// `strategy: 'bulk'` with no real provider driving boundsChange.
	resolveWith = [];
	const panels = StubPanels.instances[ StubPanels.instances.length - 1 ];
	await getSession( FIELD_ID ).refresh();

	expect( dialog.querySelector( '.drawn-map-marker' ) ).not.toBeNull(); // still there!
	expect( panels.showMessageCalls ).toEqual( [ 'emptyLocality' ] );
} );

test( 'once drawn, a failed refresh() calls panels.showMessage( \'upstreamError\' ), keeping the '
	+ 'drawn content, and the card\'s own retryRequested event destroys the OLD provider and '
	+ 'builds a fresh one, never re-init()ing the live instance', async () => {
	let shouldFail = false;
	window.WoodevPickupDataSource = fakeDataSourceFactory( () =>
		shouldFail
			? Promise.reject( { status: 502, code: 'woodev_pickup_upstream_error', message: 'x' } )
			: Promise.resolve( [ point() ] )
	);
	setConfig( makeConfig() );
	window.WoodevPickupMapProviders = { testProvider: DrawingProvider };

	mountAll();
	clickTrigger();
	await flushAsync();

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog.querySelector( '.drawn-map-marker' ) ).not.toBeNull();

	shouldFail = true;
	const panels = StubPanels.instances[ StubPanels.instances.length - 1 ];
	await getSession( FIELD_ID ).refresh();

	expect( dialog.querySelector( '.drawn-map-marker' ) ).not.toBeNull(); // still there!
	expect( panels.showMessageCalls ).toEqual( [ 'upstreamError' ] );

	// Retrying — the card's own `retryRequested` event in the real class, `panels.emit()` here —
	// destroys the OLD provider and builds a fresh one, never re-init()s the live instance.
	const oldProvider = StubProvider.instances[ StubProvider.instances.length - 1 ];

	shouldFail = false;
	panels.emit( 'retryRequested' );
	await flushAsync();

	expect( oldProvider.destroyed ).toBe( true );
	expect( StubProvider.instances.length ).toBe( 2 );
	expect( StubProvider.instances[ 1 ] ).not.toBe( oldProvider );
} );

test( 'a successful refresh() after an empty/failed one calls panels.hideMessage(), so the card '
	+ 'never lingers over a map that has since drawn real points', async () => {
	let resolveWith = [];
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( resolveWith ) );
	setConfig( makeConfig() );

	mountAll();
	clickTrigger();
	await flushAsync();

	const panels = StubPanels.instances[ StubPanels.instances.length - 1 ];
	expect( panels.showMessageCalls ).toEqual( [ 'emptyLocality' ] );
	expect( panels.hideMessageCalls || 0 ).toBe( 0 );

	resolveWith = [ point() ];
	await getSession( FIELD_ID ).refresh();

	expect( panels.hideMessageCalls ).toBe( 1 );
} );

test( 'BEFORE anything is drawn, a provider-level error still uses the destructive showError (nothing to lose)', () => {
	setConfig( makeConfig() );
	mountAll();
	clickTrigger();

	const provider = StubProvider.instances[ 0 ];
	provider.emit( 'error', { status: 502, code: 'woodev_pickup_upstream_error', message: 'x' } );

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog.querySelector( '.woodev-modal__message--error' ) ).not.toBeNull();
	expect( dialog.querySelector( '.woodev-modal__notice' ) ).toBeNull();
} );

test( 'a provider-emitted error retry destroys the old provider and constructs a fresh one, never re-init()ing', () => {
	setConfig( makeConfig() );
	mountAll();
	clickTrigger();

	const provider = StubProvider.instances[ 0 ];
	provider.emit( 'error', { status: 429, code: 'woodev_pickup_rate_limited', message: 'raw' } );

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog.textContent ).toContain( 'Слишком много запросов' );

	const retryButton = dialog.querySelector( '.woodev-modal__retry' );
	expect( retryButton ).not.toBeNull();
	retryButton.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );

	expect( provider.destroyed ).toBe( true );
	expect( StubProvider.instances.length ).toBe( 2 );
	expect( StubProvider.instances[ 1 ].destroyed ).toBe( false );
	expect( StubProvider.instances[ 1 ].initCalls.length ).toBe( 1 );
} );

// -------------------------------------------------------------------------
// No stacked sessions — including across a slot recreated mid-session (I2) —
// and NO panels/providers left alive either (Task 20)
// -------------------------------------------------------------------------

test( 'clicking the trigger twice in a row never leaves two providers or two panels alive', () => {
	setConfig( makeConfig() );
	mountAll();

	const trigger = clickTrigger();
	trigger.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );

	expect( StubProvider.instances.length ).toBe( 2 );
	expect( StubProvider.instances[ 0 ].destroyed ).toBe( true );
	expect( StubProvider.instances[ 1 ].destroyed ).toBe( false );
	expect( document.querySelectorAll( '[role="dialog"]' ).length ).toBe( 1 );

	// Task 20: one panels instance is constructed per SESSION (not per retry), and a second
	// click opens a second, independent one — the old session's panels DOM went with its
	// (destroyed) modal.
	expect( StubPanels.instances.length ).toBe( 2 );
	expect( document.querySelectorAll( '.woodev-pickup-list' ).length ).toBe( 1 );
} );

test( 'closing via Escape, then clicking again, opens a clean new session', () => {
	setConfig( makeConfig() );
	mountAll();
	clickTrigger();

	document.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );
	expect( document.querySelector( '[role="dialog"]' ) ).toBeNull();

	clickTrigger();

	expect( StubProvider.instances.length ).toBe( 2 );
	expect( document.querySelectorAll( '[role="dialog"]' ).length ).toBe( 1 );
} );

test( 're-mounting an already-mounted slot never attaches a second click listener', () => {
	setConfig( makeConfig() );
	mountAll();
	mountAll();
	mountAll();

	clickTrigger();

	expect( StubProvider.instances.length ).toBe( 1 );
} );

test( 'I2: a session opened before §8 recreates the anchor is still torn down when the NEW trigger is clicked', () => {
	setConfig( makeConfig() );
	mountAll();

	const oldTrigger = clickTrigger(); // opens session #1, mounted on the OLD button
	expect( StubProvider.instances.length ).toBe( 1 );
	expect( document.querySelectorAll( '[role="dialog"]' ).length ).toBe( 1 );

	// §8 recreates the whole anchor — the old button (and any state closed over only by
	// ITS click handler) is discarded, exactly like a real `updated_checkout` AJAX swap.
	const oldSlot = document.querySelector( '[data-woodev-pickup-slot="' + FIELD_ID + '"]' );
	oldSlot.parentNode.removeChild( oldSlot );
	const freshSlot = document.createElement( 'div' );
	freshSlot.setAttribute( 'data-woodev-pickup-slot', FIELD_ID );
	document.body.appendChild( freshSlot );
	mountAll();

	expect( document.body.contains( oldTrigger ) ).toBe( false );

	// Clicking the NEW trigger must tear down session #1 (still tracked in module scope,
	// not lost with the old button) before opening a second one — never two live at once.
	clickTrigger();

	expect( StubProvider.instances.length ).toBe( 2 );
	expect( StubProvider.instances[ 0 ].destroyed ).toBe( true );
	expect( document.querySelectorAll( '[role="dialog"]' ).length ).toBe( 1 );
} );

// =========================================================================
// Task 20 — the wiring that makes the feature actually work
// =========================================================================

// -------------------------------------------------------------------------
// The ownsChrome branch (D-3): no panels at all for a provider that owns
// the whole container — not merely hidden
// -------------------------------------------------------------------------

test( 'renders panels for a provider that does not own the chrome', async () => {
	await openSession( configWith( { ownsChrome: false } ) );

	expect( document.querySelector( '.woodev-pickup-list' ) ).not.toBeNull();
	expect( StubPanels.instances.length ).toBe( 1 );
} );

test( 'renders no panels for a provider that owns the chrome', async () => {
	await openSession( configWith( { ownsChrome: true } ) );

	expect( document.querySelector( '.woodev-pickup-list' ) ).toBeNull();
	// Never constructed — not just hidden/unrendered.
	expect( StubPanels.instances.length ).toBe( 0 );
} );

// -------------------------------------------------------------------------
// The four woodev_pickup_* document.body events
// -------------------------------------------------------------------------

test( 'fires woodev_pickup_map_ready once the provider init resolves, naming fieldId AND provider (D-14)', async () => {
	const seen = [];
	document.body.addEventListener( 'woodev_pickup_map_ready', ( e ) => seen.push( e.detail ) );
	await openSession( configWith() );

	// Exact equality — pins the full D-14 payload shape, not just one field of it. `provider`
	// is the whole point of this event for an integrator hooking a SPECIFIC map: without it
	// there is no way to tell which provider just initialised.
	expect( seen[ 0 ] ).toEqual( { fieldId: FIELD_ID, provider: 'testProvider' } );
} );

test( 'fires woodev_pickup_map_ready for an ownsChrome provider too, still naming the provider', async () => {
	const seen = [];
	document.body.addEventListener( 'woodev_pickup_map_ready', ( e ) => seen.push( e.detail ) );
	await openSession( configWith( { ownsChrome: true } ) );

	expect( seen[ 0 ] ).toEqual( { fieldId: FIELD_ID, provider: 'testProvider' } );
} );

test( 'every woodev_pickup_* event bubbles (jQuery delegation relies on it, see the file docblock)', async () => {
	const seenOnDocument = [];
	// Listening on `document` — the PARENT of `document.body`, where these events are
	// actually dispatched — only sees them if `bubbles: true` was set; a non-bubbling event
	// dispatched on `document.body` would never reach here.
	[
		'woodev_pickup_map_ready', 'woodev_pickup_points_loaded', 'woodev_pickup_point_selected', 'woodev_pickup_error',
		// Task 11's two confirmation events are held to the same rule — see the constants'
		// own comment in pickup-mount.js.
		'woodev_pickup_point_select_requested', 'woodev_pickup_point_select_resolved',
	]
		.forEach( ( type ) => document.addEventListener( type, ( e ) => seenOnDocument.push( e.type ) ) );

	const session = await openSession(
		configWith( { strategy: 'bulk', replaceAddress: { enabled: false, billingOnly: true } } )
	);
	session.provider.emit( 'error', { code: 'x', message: 'y' } );
	await selectAndConfirm( session.panels, point( { id: 'p1' } ) );

	expect( seenOnDocument ).toEqual( expect.arrayContaining( [
		'woodev_pickup_map_ready', 'woodev_pickup_points_loaded', 'woodev_pickup_error', 'woodev_pickup_point_selected',
		'woodev_pickup_point_select_requested', 'woodev_pickup_point_select_resolved',
	] ) );
} );

test( 'fires woodev_pickup_points_loaded with the count and strategy', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () =>
		Promise.resolve( [ point( { id: 'p1', lat: 1, lng: 2 } ), point( { id: 'p2', lat: 3, lng: 4 } ) ] )
	);
	const seen = [];
	document.body.addEventListener( 'woodev_pickup_points_loaded', ( e ) => seen.push( e.detail ) );
	await openSession( configWith( { strategy: 'bulk' } ) );

	expect( seen[ 0 ] ).toEqual( { fieldId: FIELD_ID, count: 2, strategy: 'bulk' } );
} );

test( 'never fires woodev_pickup_points_loaded for an ownsChrome provider (it never fetches)', async () => {
	const seen = [];
	document.body.addEventListener( 'woodev_pickup_points_loaded', ( e ) => seen.push( e.detail ) );
	await openSession( configWith( { ownsChrome: true } ) );

	expect( seen ).toHaveLength( 0 );
} );

test( 'fires woodev_pickup_point_selected (fieldId + point) and closes with reason select', async () => {
	const selected = [];
	const closed = [];
	document.body.addEventListener( 'woodev_pickup_point_selected', ( e ) => selected.push( e.detail ) );
	document.body.addEventListener( 'woodev_modal_closed', ( e ) => closed.push( e.detail ) );

	const selectedPoint = point( { id: 'p1' } );
	// Explicitly the closing plugin — the framework's own default is to stay open (D-3).
	const session = await openSession( configWith( { selection: { close: true, refreshCheckout: false } } ) );
	await selectAndConfirm( session.panels, selectedPoint );

	// Exact equality — pins fieldId AND the point object, not just one of the two.
	expect( selected[ 0 ] ).toEqual( { fieldId: FIELD_ID, point: selectedPoint } );
	expect( closed[ 0 ].reason ).toBe( 'select' );
	// The default config's address replacement writes billing_address_1/postcode too, which
	// no §8 store in this test manages — an expected, acknowledged warn (see C2 above).
	expect( console ).toHaveWarned();
} );

test( 'fires woodev_pickup_error when the provider reports a fatal error', async () => {
	const seen = [];
	document.body.addEventListener( 'woodev_pickup_error', ( e ) => seen.push( e.detail ) );

	const session = await openSession( configWith() );
	session.provider.emit( 'error', { code: 'map_script', message: 'ymaps script failed to load' } );

	// Exact equality — pins fieldId AND message, not just code.
	expect( seen[ 0 ] ).toEqual( {
		fieldId: FIELD_ID,
		code: 'map_script',
		message: 'ymaps script failed to load',
	} );
} );

test( 'does NOT fire woodev_pickup_error for a transient (non-fatal) dataSource fetch failure', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () =>
		Promise.reject( { status: 502, code: 'woodev_pickup_upstream_error', message: 'x' } )
	);
	const seen = [];
	document.body.addEventListener( 'woodev_pickup_error', ( e ) => seen.push( e.detail ) );

	await openSession( configWith() );

	expect( seen ).toHaveLength( 0 );
} );

// -------------------------------------------------------------------------
// refresh()
// -------------------------------------------------------------------------

test( 'exposes refresh() on the open session', async () => {
	const session = await openSession( configWith() );

	expect( typeof session.refresh ).toBe( 'function' );
} );

test( 'refresh() re-runs the bulk fetch and fires a fresh points_loaded', async () => {
	let fetchCalls = 0;
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => {
		fetchCalls += 1;
		return Promise.resolve( [] );
	} );
	const seen = [];
	document.body.addEventListener( 'woodev_pickup_points_loaded', ( e ) => seen.push( e.detail ) );

	const session = await openSession( configWith( { strategy: 'bulk' } ) );
	const callsBeforeRefresh = fetchCalls;
	const seenBeforeRefresh = seen.length;

	await session.refresh();

	expect( fetchCalls ).toBe( callsBeforeRefresh + 1 );
	expect( seen.length ).toBe( seenBeforeRefresh + 1 );
} );

test( 'refresh() is safe to call twice in a row', async () => {
	const session = await openSession( configWith() );

	await expect( Promise.all( [ session.refresh(), session.refresh() ] ) ).resolves.toBeDefined();
} );

test( 'refresh() is safe to call after the session has been fully torn down', async () => {
	const config = configWith( { selection: { close: true, refreshCheckout: false } } );
	const session = await openSession( config );

	// Tears the session down via handleSelection — but only once the server has CONFIRMED the
	// point and the configured `close` has taken effect (Task 11); a selection no longer closes
	// anything on its own.
	await selectAndConfirm( session.provider, point() );
	// The default config's address replacement writes billing_address_1/postcode too, which
	// no §8 store in this test manages — an expected, acknowledged warn (see C2 above).
	expect( console ).toHaveWarned();

	await expect( session.refresh() ).resolves.toBeUndefined();
} );

test( 'refresh() is a no-op for an ownsChrome provider (nothing here ever fetches for it)', async () => {
	const session = await openSession( configWith( { ownsChrome: true } ) );

	await expect( session.refresh() ).resolves.toBeUndefined();
} );

// -------------------------------------------------------------------------
// Provider → panels wiring
// -------------------------------------------------------------------------

test( 'provider pointClick opens the card for the matching group', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () =>
		Promise.resolve( [ point( { id: 'p1', lat: 1, lng: 2 } ) ] )
	);
	const session = await openSession( configWith() );

	session.provider.emit( 'pointClick', '1.0000,2.0000' );

	expect( session.panels.lastOpenCard.group.key ).toBe( '1.0000,2.0000' );
} );

test( 'a marker click focuses the group BEFORE opening its card, in that order (the cardOpened '
	+ 'funnel — unaffected by round 2\'s pan/zoom split, D6)', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () =>
		Promise.resolve( [ point( { id: 'p1', lat: 1, lng: 2 } ) ] )
	);
	const session = await openSession( configWith() );

	session.provider.emit( 'pointClick', '1.0000,2.0000' );

	expect( session.provider.focusGroupCalls ).toEqual( [ '1.0000,2.0000' ] );
	expect( callOrder ).toEqual( [ 'focusGroup:1.0000,2.0000', 'openCard:1.0000,2.0000' ] );
} );

test( 'a marker click opens the card WITHOUT waiting for focusGroup()\'s camera move to settle '
	+ '(the card is our own DOM, not the viewport)', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () =>
		Promise.resolve( [ point( { id: 'p1', lat: 1, lng: 2 } ) ] )
	);
	const session = await openSession( configWith() );

	session.provider.emit( 'pointClick', '1.0000,2.0000' );

	// `StubProvider.focusGroup()` returns a promise that NEVER resolves. No `await`/flush
	// happens between the `emit()` above and this assertion — if `pickup-mount.js` chained the
	// card open off that promise, `lastOpenCard` would still be unset here, forever.
	expect( session.panels.lastOpenCard ).toBeDefined();
	expect( session.panels.lastOpenCard.group.key ).toBe( '1.0000,2.0000' );
} );

// -------------------------------------------------------------------------
// D6 (live-review round 2) — the pan/zoom split: `cardOpened`'s `origin` decides whether
// `focusGroup()` pans only or centres-and-zooms. Spec V-10 ("a marker click and a sidebar row
// click must behave identically") is overruled — see pickup-mount.js's own `cardOpened` comment.
// -------------------------------------------------------------------------

test( 'a MARKER click (origin "marker") pans only — focusGroup() gets { zoom: false }', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () =>
		Promise.resolve( [ point( { id: 'p1', lat: 1, lng: 2 } ) ] )
	);
	const session = await openSession( configWith() );

	session.provider.emit( 'pointClick', '1.0000,2.0000' );

	expect( session.provider.focusGroupOptions ).toEqual( [ { zoom: false } ] );
	expect( session.panels.lastOpenCard.origin ).toBe( 'marker' );
	// The marker route hands no specific point id — the card falls back to the group's first
	// point, exactly like it always has.
	expect( session.panels.lastOpenCard.pointId ).toBeNull();
} );

test( 'a SIDEBAR ROW click (origin "list") centres AND zooms — focusGroup() gets { zoom: true }', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () =>
		Promise.resolve( [ point( { id: 'p1', lat: 1, lng: 2 } ) ] )
	);
	const session = await openSession( configWith() );

	// The real sidebar row builder passes 'list' — see pickup-panels.js's own renderList().
	// This exercises the SAME 'cardOpened' funnel the mount listens on, driven directly (a
	// dedicated REAL-Panels integration test below covers the row click itself).
	session.panels.openCard( { key: '1.0000,2.0000', points: [ point( { id: 'p1' } ) ] }, 'p1', 'list' );

	expect( session.provider.focusGroupOptions ).toEqual( [ { zoom: true } ] );
} );

test( 'a search-result pick (origin "search", via searchPointPicked) centres AND zooms', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () =>
		Promise.resolve( [ point( { id: 'p9', lat: 10, lng: 20 } ) ] )
	);
	const session = await openSession( configWith() );

	session.panels.emit( 'searchPointPicked', 'p9' );

	expect( session.provider.focusGroupOptions ).toEqual( [ { zoom: true } ] );
	expect( session.panels.lastOpenCard.origin ).toBe( 'search' );
} );

test( '"show the nearest" (origin "nearest", via showNearestRequested) centres AND zooms', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () =>
		Promise.resolve( [ point( { id: 'p1', lat: 55.8, lng: 37.7 } ) ] )
	);
	const session = await openSession( configWith() );

	session.panels.emit( 'showNearestRequested', { key: '55.8000,37.7000', distanceMeters: 100, name: 'X' } );

	expect( session.provider.focusGroupOptions ).toEqual( [ { zoom: true } ] );
	expect( session.panels.lastOpenCard.origin ).toBe( 'nearest' );
	expect( session.panels.lastOpenCard.pointId ).toBeNull();
} );

test( 'addressMatchedPoint (origin "search") also centres AND zooms — see the dedicated '
	+ 'addressMatchedPoint section below for the wiring itself', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () =>
		Promise.resolve( [ point( { id: 'p1', lat: 1, lng: 2 } ) ] )
	);
	const session = await openSession( configWith() );

	session.provider.emit( 'addressMatchedPoint', { key: '1.0000,2.0000' } );

	expect( session.provider.focusGroupOptions ).toEqual( [ { zoom: true } ] );
	expect( session.panels.lastOpenCard.origin ).toBe( 'search' );
} );

test( 'provider visibleChange resolves keys to groups and calls panels.setVisible', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( [
		point( { id: 'a', lat: 1, lng: 2 } ),
		point( { id: 'b', lat: 3, lng: 4 } ),
	] ) );
	const session = await openSession( configWith() );

	session.provider.emit( 'visibleChange', [ '1.0000,2.0000' ] );

	expect( session.panels.lastVisible ).toHaveLength( 1 );
	expect( session.panels.lastVisible[ 0 ].key ).toBe( '1.0000,2.0000' );
} );

test( 'provider nothingNearby calls panels.showNothingNearby with the same payload', async () => {
	const session = await openSession( configWith() );
	const info = { key: 'x', distanceMeters: 999, name: 'Y' };

	session.provider.emit( 'nothingNearby', info );

	expect( session.panels.lastNothingNearby ).toBe( info );
} );

test( 'provider bboxTooWide calls panels.showMessage( \'zoomIn\' ) WITHOUT destroying the map/panels '
	+ 'the customer is being asked to zoom', async () => {
	// `strategy: 'viewport'` — bboxTooWide is a viewport-only concept, and the bulk strategy's own
	// initial fetch (the default `beforeEach` dataSource resolves `[]`) would otherwise push its
	// OWN `emptyLocality` call before this test ever gets to bboxTooWide.
	const session = await openSession( configWith( { strategy: 'viewport' } ) );

	session.provider.emit( 'bboxTooWide', null );

	// NON-destructive: `panels.showMessage()` (spec V-5), never the whole-body
	// showError()/showEmpty() replacement — wiping the map/panels here would make the "zoom in"
	// instruction impossible to follow, and the search/filter controls must stay usable too.
	expect( session.panels.showMessageCalls ).toEqual( [ 'zoomIn' ] );

	const dialog = document.querySelector( '[role="dialog"]' );
	expect( dialog.querySelector( '.woodev-modal__message--error' ) ).toBeNull();
	expect( dialog.querySelector( '.woodev-modal__message--empty' ) ).toBeNull();
	expect( dialog.querySelector( '.woodev-modal__notice' ) ).toBeNull();
	expect( dialog.querySelector( '.woodev-pickup-list' ) ).not.toBeNull();
} );

test( 'provider searchResults forwards to panels.renderSearchResults verbatim', async () => {
	const session = await openSession( configWith() );
	const results = { points: [ point() ], addresses: [ { displayName: 'Тверская 1' } ] };

	session.provider.emit( 'searchResults', results );

	expect( session.panels.lastSearchResults ).toBe( results );
} );

// -------------------------------------------------------------------------
// Panels → provider wiring
// -------------------------------------------------------------------------

test( 'panels listToggle calls provider.setMargin with the open state and width, ON TOP OF the '
	+ 'init-time call (D8/round 2 — see the dedicated test below)', async () => {
	const session = await openSession( configWith() );

	session.panels.emit( 'listToggle', { open: true, width: 320 } );

	expect( session.provider.setMarginCalls ).toEqual( [
		{ open: false, width: 0 }, // the init-time reservation — see below
		{ open: true, width: 320 },
	] );
} );

// -------------------------------------------------------------------------
// D8/п.5,п.8 (live-review round 2): setMargin() reserved at init, not only
// after the first listToggle — see pickup-mount.js's own comment at the call
// site for why `false, 0` is the panels' genuine starting state, not a guess.
// -------------------------------------------------------------------------

test( 'provider.setMargin() is called once, with the panels\' closed starting state, right after '
	+ 'init() resolves — BEFORE any listToggle ever fires', async () => {
	const session = await openSession( configWith() );

	expect( session.provider.setMarginCalls ).toEqual( [ { open: false, width: 0 } ] );
} );

test( 'an ownsChrome provider never gets an init-time setMargin() call (no panels exist to read '
	+ 'a starting state from)', async () => {
	const session = await openSession( configWith( { ownsChrome: true } ) );

	expect( session.provider.setMarginCalls ).toEqual( [] );
} );

test( 'panels zoom calls provider.zoomBy with the signed step (Task 14, spec V-13)', async () => {
	const session = await openSession( configWith() );

	session.panels.emit( 'zoom', { step: 1 } );
	session.panels.emit( 'zoom', { step: -1 } );

	expect( session.provider.zoomByCalls ).toEqual( [ 1, -1 ] );
} );

// -----------------------------------------------------------------------
// Issue #219 — the viewport strategy's lazy detail fetch. Every piece of it existed (the REST
// route, `Point_Source::fetch_details()`, the server-side verdict recomputation, the datasource
// method and its own tests) except a production caller: `fetchPoints` was rewired onto the mount
// during the Task 20 migration and `fetchDetails` was left behind. A `STRATEGY_VIEWPORT` carrier
// may omit `accepts_cod`/`max_weight` from its bbox listing, so without this every point stayed
// selectable and the refusal only arrived at confirmation.
// -----------------------------------------------------------------------
describe( 'viewport lazy detail fetch (#219)', () => {
	const openCardOn = ( session, pointId ) => {
		const group = { key: 'g1', lat: 55.75, lng: 37.61, points: [ { id: pointId } ] };

		session.panels.emit( 'cardOpened', { group: group, pointId: pointId, origin: 'list' } );
	};

	test( 'opening a card pulls that point\'s full record and merges it in', async () => {
		const session = await openSession( configWith( { strategy: 'viewport' } ) );

		openCardOn( session, 'FIX-VIEW-2' );
		await flushAsync();

		expect( dataSourceFactory.fetchDetails ).toHaveBeenCalledWith( 'FIX-VIEW-2' );
		expect( session.panels.updatePointCalls ).toEqual( [ {
			pointId: 'FIX-VIEW-2',
			fields: { id: 'FIX-VIEW-2', selectable: { allowed: false, reason: 'Только предоплата.' } },
		} ] );
	} );

	// The bulk listing already carried the full record; a request per card open would be pure
	// waste against the merchant's carrier quota.
	test( 'bulk never asks — its listing already carried the full record', async () => {
		const session = await openSession( configWith( { strategy: 'bulk' } ) );

		openCardOn( session, 'P1' );
		await flushAsync();

		expect( dataSourceFactory.fetchDetails ).not.toHaveBeenCalled();
		expect( session.panels.updatePointCalls ).toHaveLength( 0 );
	} );

	// Re-opening a card, switching tabs inside a co-located group and re-entering from the map
	// all funnel through `cardOpened`, and none of them learn anything new.
	test( 'asks once per point per listing, however many times the card reopens', async () => {
		const session = await openSession( configWith( { strategy: 'viewport' } ) );

		openCardOn( session, 'P1' );
		await flushAsync();
		openCardOn( session, 'P1' );
		openCardOn( session, 'P1' );
		await flushAsync();

		expect( dataSourceFactory.fetchDetails ).toHaveBeenCalledTimes( 1 );
	} );

	// …but a fresh listing carries freshly computed verdicts, because the cart weight or the
	// payment method may be exactly what changed.
	// CONTRACT CHANGED IN #232. This used to assert that any new listing re-asked. It does not
	// any more, and the old behaviour was the visible defect: a bbox refetch cannot change the
	// cart, so re-asking on every pan only made the open card drop its own content for a beat
	// and fetch it again. What invalidates a verdict is a CART change, which arrives as
	// `refresh()` — see the test below.
	test( 'a pan does NOT re-ask: a bbox refetch is not a cart change', async () => {
		const session = await openSession( configWith( { strategy: 'viewport' } ) );

		openCardOn( session, 'P1' );
		await flushAsync();

		expect( dataSourceFactory.fetchDetails ).toHaveBeenCalledTimes( 1 );

		session.provider.emit( 'boundsChange', [ 1, 2, 3, 4 ] );
		await flushAsync();

		openCardOn( session, 'P1' );
		await flushAsync();

		expect( dataSourceFactory.fetchDetails ).toHaveBeenCalledTimes( 1 );
	} );

	// The other half of the same contract: a checkout update CAN have changed the weight or the
	// payment method, so every stored verdict is suspect and the open card must ask again.
	test( 'refresh() forgets details, so the open card re-asks once', async () => {
		const session = await openSession( configWith( { strategy: 'viewport' } ) );

		openCardOn( session, 'P1' );
		await flushAsync();

		expect( dataSourceFactory.fetchDetails ).toHaveBeenCalledTimes( 1 );

		// `refresh()` under viewport re-fetches the LAST bbox, so one has to exist first —
		// otherwise it early-returns and never reaches a listing at all.
		session.provider.emit( 'boundsChange', [ 1, 2, 3, 4 ] );
		await flushAsync();

		expect( dataSourceFactory.fetchDetails ).toHaveBeenCalledTimes( 1 );

		await session.refresh();
		await flushAsync();

		expect( dataSourceFactory.fetchDetails ).toHaveBeenCalledTimes( 2 );
		expect( dataSourceFactory.fetchDetails ).toHaveBeenLastCalledWith( 'P1' );
	} );

	// #233: the SECOND point of a co-located group. A tab click reports `cardOpened` with origin
	// `'tab'`, so this funnel must treat it like any other move — fetch that point's details —
	// while skipping the camera, since every point in the group shares one coordinate.
	test( "a tab switch fetches the new point's details and moves no camera", async () => {
		const session = await openSession( configWith( { strategy: 'viewport' } ) );
		const group = {
			key: 'g1',
			lat: 55.75,
			lng: 37.61,
			points: [ { id: 'OFFICE' }, { id: 'LOCKER' } ],
		};

		session.panels.emit( 'cardOpened', { group: group, pointId: 'OFFICE', origin: 'list' } );
		await flushAsync();

		expect( dataSourceFactory.fetchDetails ).toHaveBeenCalledTimes( 1 );
		expect( dataSourceFactory.fetchDetails ).toHaveBeenLastCalledWith( 'OFFICE' );

		session.provider.focusGroupCalls.length = 0;

		session.panels.emit( 'cardOpened', { group: group, pointId: 'LOCKER', origin: 'tab' } );
		await flushAsync();

		// The whole defect: before this, the second point was never asked about at all.
		expect( dataSourceFactory.fetchDetails ).toHaveBeenCalledTimes( 2 );
		expect( dataSourceFactory.fetchDetails ).toHaveBeenLastCalledWith( 'LOCKER' );

		// …and the camera stays put — same coordinate, nothing to move to.
		expect( session.provider.focusGroupCalls ).toHaveLength( 0 );
	} );

	// A marker click and a sidebar row both swap the card without waiting for anything, so an
	// answer can land about a point the customer is no longer reading.
	test( 'a late answer is dropped when the card has moved to another point', async () => {
		let settle;

		dataSourceFactory.fetchDetails = jest.fn( () => new Promise( ( resolve ) => {
			settle = resolve;
		} ) );

		const session = await openSession( configWith( { strategy: 'viewport' } ) );

		openCardOn( session, 'P1' );
		openCardOn( session, 'P2' );

		settle( { id: 'P1', selectable: { allowed: false, reason: 'нет' } } );
		await flushAsync();

		const forP1 = session.panels.updatePointCalls.filter( ( c ) => 'P1' === c.pointId );

		expect( forP1 ).toHaveLength( 0 );
	} );

	// Degrades to exactly the pre-#219 behaviour: the SELECT route runs `fetch_details()` +
	// `Constraint_Checker` itself, so a refused point is still refused — just later. The memo
	// must not swallow the retry, though.
	test( 'a failed fetch is quiet and the next card open retries it', async () => {
		dataSourceFactory.fetchDetails = jest.fn( () => Promise.reject( { status: 502 } ) );

		const session = await openSession( configWith( { strategy: 'viewport' } ) );

		openCardOn( session, 'P1' );
		await flushAsync();

		expect( session.panels.updatePointCalls ).toHaveLength( 0 );

		openCardOn( session, 'P1' );
		await flushAsync();

		expect( dataSourceFactory.fetchDetails ).toHaveBeenCalledTimes( 2 );
	} );

	// Issue #225's own gap (Part 3, "the gap Part 1 exposes"): before this fix, NOTHING re-asked
	// for a card that stayed OPEN across a listing refetch — `detailedPoints` was correctly wiped
	// (the cart may have changed), but only a FRESH `cardOpened` (an explicit reopen, as the test
	// above drives) ever re-triggered `refreshPointDetails()`. This test drives the refetch with
	// NO further `cardOpened` in between — the card stays open on P1 throughout — proving the
	// mount itself re-asks automatically.
	// REPLACES the #225 test that asserted a re-ask on every listing. #232: what an open card
	// actually needs across a pan is not a new REQUEST but not to LOSE what it already has —
	// `geo.groupByPosition()` rebuilds groups from the sparse listing, so without re-applying the
	// stored detail record the card silently reverts to the permissive-by-omission verdict a
	// detail fetch had already overturned. That revert is what the customer saw as content
	// appearing and then vanishing.
	test( 'a listing re-applies stored details over the rebuilt sparse groups', async () => {
		dataSourceFactory = fakeDataSourceFactory( () => Promise.resolve( [ point( { id: 'P1' } ) ] ) );
		dataSourceFactory.fetchDetails = jest.fn( () => Promise.resolve( {
			id: 'P1',
			work_time: 'Пн-Пт 10:00-19:00',
			selectable: { allowed: false, reason: 'нет оплаты при получении' },
		} ) );
		window.WoodevPickupDataSource = dataSourceFactory;

		const session = await openSession( configWith( { strategy: 'viewport' } ) );

		openCardOn( session, 'P1' );
		await flushAsync();

		expect( dataSourceFactory.fetchDetails ).toHaveBeenCalledTimes( 1 );

		// A pan: a fresh SPARSE listing lands, carrying the permissive default again.
		session.provider.emit( 'boundsChange', [ 1, 2, 3, 4 ] );
		await flushAsync();

		// No second request — the memo survives a pan now.
		expect( dataSourceFactory.fetchDetails ).toHaveBeenCalledTimes( 1 );

		// …and the groups handed to the provider carry the DETAIL verdict, not the listing's.
		const drawn = session.provider.setPointsCalls[ session.provider.setPointsCalls.length - 1 ];
		const drawnPoint = drawn[ 0 ].points[ 0 ];

		expect( drawnPoint.selectable.allowed ).toBe( false );
		expect( drawnPoint.work_time ).toBe( 'Пн-Пт 10:00-19:00' );
	} );

	// The restore pass (spec D-15) is one of the two callers of `openCard()`/`cardOpened` that can
	// make a card "already open" the MOMENT a listing lands — the Part 3 re-ask must not fire a
	// SECOND time on top of what the restore's own `cardOpened` already triggered.
	test( 'the restore pass\'s own cardOpened already covers the re-ask — no double fetch', async () => {
		document.getElementById( FIELD_ID ).value = 'P1';

		// The restore only finds a group once the listing actually carries the stored point id —
		// the default `beforeEach()` factory resolves `[]`, which would leave nothing to restore.
		dataSourceFactory = fakeDataSourceFactory( () => Promise.resolve( [ point( { id: 'P1' } ) ] ) );
		window.WoodevPickupDataSource = dataSourceFactory;

		const session = await openSession( configWith( { strategy: 'viewport' } ) );

		session.provider.emit( 'boundsChange', [ 1, 2, 3, 4 ] );
		await flushAsync();

		expect( dataSourceFactory.fetchDetails ).toHaveBeenCalledTimes( 1 );
		expect( dataSourceFactory.fetchDetails ).toHaveBeenCalledWith( 'P1' );
	} );
} );

// -----------------------------------------------------------------------
// Issue #223 — the card's CTA locks while `refreshPointDetails()`'s own detail fetch is in
// flight for the point the card currently shows: the sparse listing verdict on screen right now
// is exactly what the fetch may be about to overturn. `panels.setVerdictPending()` (a jest.fn()
// on `StubPanels`, mirroring `setSelectionBusy`) is the SEPARATE lock this drives — never folded
// into the confirmation lock, since a `refreshCheckout()` confirmation-lock and a fresh detail
// fetch can genuinely overlap on one card (see `pickup-panels.js`'s own `setVerdictPending()`
// docblock for the scenario).
// -----------------------------------------------------------------------
describe( 'the card lock during a lazy detail fetch (issue #223)', () => {
	const openCardOn = ( session, pointId ) => {
		const group = { key: 'g1', lat: 55.75, lng: 37.61, points: [ { id: pointId } ] };

		session.panels.emit( 'cardOpened', { group: group, pointId: pointId, origin: 'list' } );
	};

	test( 'locks on fetch start and unlocks on success', async () => {
		let settle;

		dataSourceFactory.fetchDetails = jest.fn( () => new Promise( ( resolve ) => {
			settle = resolve;
		} ) );

		const session = await openSession( configWith( { strategy: 'viewport' } ) );

		openCardOn( session, 'P1' );
		await flushAsync();

		expect( session.panels.setVerdictPending ).toHaveBeenLastCalledWith( true );

		settle( { id: 'P1', selectable: { allowed: true, reason: null } } );
		await flushAsync();

		expect( session.panels.setVerdictPending ).toHaveBeenLastCalledWith( false );
	} );

	// THE STORM GUARD, and the reason `detailsInFlight` exists alongside the per-listing memo.
	//
	// A successful listing wipes `detailedPoints` (correct — a new listing may mean a new cart) and
	// then re-asks for the still-open card. Under a rapidly panning customer that is a listing, a
	// wipe and another request PER PAN, all for the same point, all in flight at once. The landed-
	// memo cannot stop it, because the wipe is what clears it. Found by the Codex critic pass.
	test( 'a listing re-ask does NOT start a second request for a point already being fetched', async () => {
		const settlers = [];

		dataSourceFactory.fetchDetails = jest.fn( () => new Promise( ( resolve ) => {
			settlers.push( resolve );
		} ) );

		const session = await openSession( configWith( { strategy: 'viewport' } ) );

		openCardOn( session, 'P1' );
		await flushAsync();

		expect( settlers ).toHaveLength( 1 );
		expect( session.panels.setVerdictPending ).toHaveBeenLastCalledWith( true );

		// Three listings land back to back, each wiping the landed-memo and re-asking.
		session.provider.emit( 'boundsChange', [ 1, 2, 3, 4 ] );
		await flushAsync();
		session.provider.emit( 'boundsChange', [ 2, 3, 4, 5 ] );
		await flushAsync();
		session.provider.emit( 'boundsChange', [ 3, 4, 5, 6 ] );
		await flushAsync();

		// Still exactly ONE detail request in flight for P1 — not four.
		expect( settlers ).toHaveLength( 1 );

		// And when it lands it still applies, and still releases the lock it owns.
		settlers[ 0 ]( { id: 'P1', selectable: { allowed: false, reason: 'too heavy' } } );
		await flushAsync();

		expect( session.panels.setVerdictPending ).toHaveBeenLastCalledWith( false );
		expect( session.panels.updatePointCalls ).toHaveLength( 1 );
		expect( session.panels.updatePointCalls[ 0 ].fields.selectable.allowed ).toBe( false );
	} );

	// The other half of the same guard: leaving the point and coming BACK while its request is
	// still travelling must not discard the answer. The lock was released on the way out and
	// `detailsInFlight` starts no new request on the way back, so this answer is the ONLY verdict
	// anyone will fetch — ownership decides who may release the lock, `cardPointId` decides whose
	// answer may land.
	test( 'an answer still applies after the card left the point and came back', async () => {
		const settlers = [];

		dataSourceFactory.fetchDetails = jest.fn( () => new Promise( ( resolve ) => {
			settlers.push( resolve );
		} ) );

		const session = await openSession( configWith( { strategy: 'viewport' } ) );

		openCardOn( session, 'P1' );
		await flushAsync();

		openCardOn( session, 'P2' );
		await flushAsync();

		openCardOn( session, 'P1' );
		await flushAsync();

		session.panels.updatePointCalls.length = 0;

		settlers[ 0 ]( { id: 'P1', selectable: { allowed: false, reason: 'too heavy' } } );
		await flushAsync();

		expect( session.panels.updatePointCalls ).toHaveLength( 1 );
		expect( session.panels.updatePointCalls[ 0 ].fields.selectable.allowed ).toBe( false );
	} );

	// Non-negotiable per the brief: a FAILED fetch must not leave the card locked forever either.
	test( 'unlocks on failure too', async () => {
		let settle;

		dataSourceFactory.fetchDetails = jest.fn( () => new Promise( ( resolve, reject ) => {
			settle = reject;
		} ) );

		const session = await openSession( configWith( { strategy: 'viewport' } ) );

		openCardOn( session, 'P1' );
		await flushAsync();

		expect( session.panels.setVerdictPending ).toHaveBeenLastCalledWith( true );

		settle( { status: 502 } );
		await flushAsync();

		expect( session.panels.setVerdictPending ).toHaveBeenLastCalledWith( false );
	} );

	// The card moving to another point mid-flight releases the OLD lock immediately (matching
	// `invalidateSelection()`'s own "release on the move, not on the settle" discipline) — and the
	// abandoned fetch, once it finally answers, must not stomp the NEW point's own, still-live
	// lock. This is the s53 shape the brief calls out by name: a naive staleness guard that skips
	// the RELEASE too, rather than transferring ownership at the move, leaves the wrong card
	// locked.
	test( 'releases on the move, not on the settle — an abandoned fetch does not stomp the new '
		+ 'point\'s lock', async () => {
		let settleP1;
		let settleP2;

		dataSourceFactory.fetchDetails = jest.fn( ( pointId ) => new Promise( ( resolve ) => {
			if ( 'P1' === pointId ) {
				settleP1 = resolve;
			} else {
				settleP2 = resolve;
			}
		} ) );

		const session = await openSession( configWith( { strategy: 'viewport' } ) );

		openCardOn( session, 'P1' );
		await flushAsync();

		expect( session.panels.setVerdictPending ).toHaveBeenLastCalledWith( true );

		session.panels.setVerdictPending.mockClear();

		// The card moves to P2 before P1's fetch ever answers.
		openCardOn( session, 'P2' );
		await flushAsync();

		// Released for the move (P1's lock), THEN re-acquired for P2's own fresh fetch — in that
		// order, both inside this one flush.
		expect( session.panels.setVerdictPending.mock.calls.map( ( c ) => c[ 0 ] ) ).toEqual( [ false, true ] );

		session.panels.setVerdictPending.mockClear();

		// P1's abandoned fetch finally answers — it must NOT touch P2's still-live lock.
		settleP1( { id: 'P1', selectable: { allowed: true, reason: null } } );
		await flushAsync();

		expect( session.panels.setVerdictPending ).not.toHaveBeenCalled();

		// P2's own fetch settling DOES release its own, still-owned lock.
		settleP2( { id: 'P2', selectable: { allowed: true, reason: null } } );
		await flushAsync();

		expect( session.panels.setVerdictPending ).toHaveBeenLastCalledWith( false );
	} );

	// The real overlap case `pickup-panels.js`'s own `setVerdictPending()` docblock documents:
	// `refreshCheckout()` holds `setSelectionBusy( true )` on the still-open card while a
	// post-confirmation totals refresh is in flight; if the customer opens a DIFFERENT point's
	// card during that same window, a fresh detail fetch locks `_verdictPending` too — two
	// independent locks on one `panels` instance, live at once. Neither release may stomp the
	// other.
	test( 'a confirmation lock (refreshCheckout) and a detail fetch overlapping do not stomp '
		+ 'each other\'s release', async () => {
		// A jQuery double, same minimal shape `openPicker()`'s own `jq` fixture uses — `refreshCheckout()`
		// needs `.one()`/`.trigger()`, `dropRefreshWaiter()` needs `.off()`. `.one()` is RECORDED
		// rather than wired to `.trigger()` (jQuery's real `.one()` self-unbinds; this double does
		// not need to reproduce that to prove the point), so firing it is `jq.one[ 0 ].handler()`,
		// matching every other `updated_checkout` test in this file.
		const jq = { triggered: [], one: [], off: [] };

		window.jQuery = () => ( {
			one: ( type, handler ) => jq.one.push( { type: type, handler: handler } ),
			off: ( type, handler ) => jq.off.push( { type: type, handler: handler } ),
			trigger: ( type ) => jq.triggered.push( type ),
		} );

		let settleDetails;

		dataSourceFactory.fetchDetails = jest.fn( () => new Promise( ( resolve ) => {
			settleDetails = resolve;
		} ) );
		dataSourceFactory.selectPoint = jest.fn( () => Promise.resolve( {
			allowed: true, reason: null, close: false, refresh_checkout: true,
		} ) );

		const session = await openSession( configWith( {
			strategy: 'viewport',
			selection: { close: false, refreshCheckout: true },
			// Address replacement is not this test's subject — every field it would write that no
			// §8 store owns logs a `console.warn` the WP jest preset turns into a failure (see
			// `openPicker()`'s own identical override, above).
			replaceAddress: { enabled: false, billingOnly: true },
		} ) );

		// P1 is confirmed and accepted; `refresh_checkout: true` + `close: false` leaves the
		// card open and locked via `setSelectionBusy( true )` while WooCommerce's totals refresh
		// is pending.
		session.panels.emit( 'select', { id: 'P1' } );
		await flushAsync();

		expect( session.panels.setSelectionBusy ).toHaveBeenLastCalledWith( true );

		// A DIFFERENT point's card opens during that same window — its own detail fetch locks
		// `_verdictPending` independently.
		openCardOn( session, 'P2' );
		await flushAsync();

		expect( session.panels.setVerdictPending ).toHaveBeenLastCalledWith( true );
		// The confirmation lock is untouched by the detail fetch starting.
		expect( session.panels.setSelectionBusy ).toHaveBeenLastCalledWith( true );

		// The detail fetch settles first — the confirmation lock must survive it.
		settleDetails( { id: 'P2', selectable: { allowed: true, reason: null } } );
		await flushAsync();

		expect( session.panels.setVerdictPending ).toHaveBeenLastCalledWith( false );
		expect( session.panels.setSelectionBusy ).toHaveBeenLastCalledWith( true );

		// WooCommerce answers the totals refresh — the confirmation lock finally releases, and
		// the (already-released) verdict-pending lock is untouched by it.
		jq.one[ 0 ].handler();

		expect( session.panels.setSelectionBusy ).toHaveBeenLastCalledWith( false );
	} );
} );

// -----------------------------------------------------------------------
// Issues #222/#224 — the shared background-load indicator. `fetchAndSetPoints()` (every bbox
// refetch) and `refreshPointDetails()` (issue #219's lazy detail fetch) both drive ONE shared
// counter, so a later task (#223, the card CTA lock) can hang off the same counter instead of
// racing a second spinner. A COUNTER, not a boolean: a bbox refetch and a detail fetch genuinely
// overlap, and a boolean would let whichever settles FIRST switch the indicator off while the
// other is still running. `panels.setLoadingCalls` (a plain array — `toHaveLength( 0 )`, never
// `toEqual( [] )`: this repo's test doubles record onto plain arrays, and `toEqual` ignores
// `undefined` items, so an accidental `setLoading( undefined )` call would pass a `toEqual( [] )`
// check it should have failed) records every call, in order.
// -----------------------------------------------------------------------
describe( 'the shared background-load indicator (issues #222/#224)', () => {
	const openCardOn = ( session, pointId ) => {
		const group = { key: 'g1', lat: 55.75, lng: 37.61, points: [ { id: pointId } ] };

		session.panels.emit( 'cardOpened', { group: group, pointId: pointId, origin: 'list' } );
	};

	/**
	 * Swaps in a datasource whose `fetchPoints()` AND `fetchDetails()` both stay pending until
	 * the test explicitly settles them — the only way to observe the two requests genuinely
	 * OVERLAPPING (a plain `fakeDataSourceFactory()` default settles `fetchPoints()` immediately,
	 * which is fine for every OTHER describe block in this file but useless for proving the
	 * counter's own 0→1→2→1→0 shape).
	 */
	function pendingFactory() {
		let pointsSettle;

		const factory = fakeDataSourceFactory( () => new Promise( ( resolve, reject ) => {
			pointsSettle = { resolve, reject };
		} ) );

		let detailsSettle;

		factory.fetchDetails = jest.fn( () => new Promise( ( resolve, reject ) => {
			detailsSettle = { resolve, reject };
		} ) );

		window.WoodevPickupDataSource = factory;

		return {
			resolvePoints: ( points ) => pointsSettle.resolve( points || [] ),
			rejectPoints: ( reason ) => pointsSettle.reject( reason ),
			resolveDetails: ( point ) => detailsSettle.resolve( point ),
			rejectDetails: ( reason ) => detailsSettle.reject( reason ),
		};
	}

	test( 'a bbox refetch drives setLoading( true ) then setLoading( false )', async () => {
		const ds = pendingFactory();
		const session = await openSession( configWith( { strategy: 'viewport' } ) );

		session.provider.emit( 'boundsChange', [ 1, 2, 3, 4 ] );
		await flushAsync();

		expect( session.panels.setLoadingCalls ).toEqual( [ true ] );

		ds.resolvePoints( [] );
		await flushAsync();

		expect( session.panels.setLoadingCalls ).toEqual( [ true, false ] );
	} );

	test( 'a FAILED bbox refetch still returns the indicator to false', async () => {
		const ds = pendingFactory();
		const session = await openSession( configWith( { strategy: 'viewport' } ) );

		session.provider.emit( 'boundsChange', [ 1, 2, 3, 4 ] );
		await flushAsync();

		ds.rejectPoints( { status: 0, code: 'woodev_pickup_upstream_error', message: 'offline' } );
		await flushAsync();

		expect( session.panels.setLoadingCalls ).toEqual( [ true, false ] );
	} );

	test( 'a point-detail fetch (#219) also drives setLoading( true ) then ( false )', async () => {
		// Immediately-resolving is enough here — this test only proves BOTH calls happen, in
		// order; the OVERLAP shape (a settle that must NOT clear the indicator) has its own test
		// below, where genuinely holding the promise open is what the assertion needs.
		dataSourceFactory.fetchDetails = jest.fn( () =>
			Promise.resolve( { id: 'P1', selectable: { allowed: true, reason: null } } ) );

		const session = await openSession( configWith( { strategy: 'viewport' } ) );

		openCardOn( session, 'P1' );
		await flushAsync();

		expect( session.panels.setLoadingCalls ).toEqual( [ true, false ] );
	} );

	test( 'a FAILED point-detail fetch still returns the indicator to false', async () => {
		dataSourceFactory.fetchDetails = jest.fn( () => Promise.reject( { status: 502 } ) );

		const session = await openSession( configWith( { strategy: 'viewport' } ) );

		openCardOn( session, 'P1' );
		await flushAsync();

		expect( session.panels.setLoadingCalls ).toEqual( [ true, false ] );
	} );

	test( 'overlap: a detail fetch settling first must NOT clear the indicator while the bbox '
		+ 'refetch it overlapped with is still in flight', async () => {
		const ds = pendingFactory();
		const session = await openSession( configWith( { strategy: 'viewport' } ) );

		// Request #1: the bbox refetch — left pending.
		session.provider.emit( 'boundsChange', [ 1, 2, 3, 4 ] );
		await flushAsync();

		expect( session.panels.setLoadingCalls ).toEqual( [ true ] );

		// Request #2: the detail fetch — overlaps #1, also left pending.
		openCardOn( session, 'P1' );
		await flushAsync();

		// Still just the ONE 0→1 transition — a second overlapping request must not re-fire it.
		expect( session.panels.setLoadingCalls ).toEqual( [ true ] );

		// #2 settles first — #1 is STILL in flight, so the indicator must stay ON.
		ds.resolveDetails( { id: 'P1', selectable: { allowed: true, reason: null } } );
		await flushAsync();

		expect( session.panels.setLoadingCalls ).toEqual( [ true ] );

		// #1 settles — and now the counter really does reach zero. Before #232 a third request
		// started here (the listing re-asked for the still-open card), so the indicator flicked
		// off and straight back on inside this one flush. A pan is not a cart change, so it no
		// longer re-asks, and the indicator simply rests.
		ds.resolvePoints( [] );
		await flushAsync();

		expect( session.panels.setLoadingCalls ).toEqual( [ true, false ] );
	} );

	// Adversarial-review finding: `bumpLoading()` runs, then `realDataSource.fetchPoints()`/
	// `fetchDetails()` is called — if THAT call throws SYNCHRONOUSLY (before ever returning a
	// promise), neither `.then()` handler below it would run, `dropLoading()` would never fire,
	// and the counter (and the `is-loading` class it drives) would stay pinned on forever — the
	// exact failure mode `dropLoading()`'s own docblock rules out. Both `fetchAndSetPoints()` and
	// `refreshPointDetails()` now wrap the dataSource call in `Promise.resolve().then( … )` so a
	// synchronous throw lands in the SAME reject handler an async rejection already does.
	test( 'fetchPoints() throwing SYNCHRONOUSLY still returns the indicator to false, not a leak', async () => {
		window.WoodevPickupDataSource = fakeDataSourceFactory( () => {
			throw new Error( 'synchronous boom' );
		} );

		const session = await openSession( configWith() );

		expect( session.panels.setLoadingCalls ).toEqual( [ true, false ] );
	} );

	test( 'fetchDetails() throwing SYNCHRONOUSLY still returns the indicator to false, not a leak, '
		+ 'and the memo is evicted so the next card open retries it', async () => {
		dataSourceFactory.fetchDetails = jest.fn( () => {
			throw new Error( 'synchronous boom' );
		} );

		const session = await openSession( configWith( { strategy: 'viewport' } ) );

		openCardOn( session, 'P1' );
		await flushAsync();

		expect( session.panels.setLoadingCalls ).toEqual( [ true, false ] );

		// Matches the async-rejection sibling test above ("a failed fetch is quiet and the next
		// card open retries it") — the memo eviction in the reject branch runs identically whether
		// the rejection arrived synchronously or asynchronously.
		openCardOn( session, 'P1' );
		await flushAsync();

		expect( dataSourceFactory.fetchDetails ).toHaveBeenCalledTimes( 2 );
	} );
} );

// The return leg of the same wiring: the provider owns the zoom RANGE (it owns the camera), so
// it is what decides a limit has been reached; the panels only dim the button it names.
test( 'provider zoomChange forwards to panels.setZoomLimits', async () => {
	const session = await openSession( configWith() );

	session.provider.emit( 'zoomChange', { canZoomIn: false, canZoomOut: true } );

	expect( session.panels.setZoomLimitsCalls ).toEqual( [ { canZoomIn: false, canZoomOut: true } ] );
} );

// Where the fail-open actually lives. `on()` is part of the provider contract proper (`start()`
// calls it bare for `select`/`error`), so the degradation worth pinning is not "a provider with
// no event surface" — that one cannot mount at all — but a provider that registers and simply
// never reports a limit. Both buttons must then stay untouched, i.e. live.
test( 'a provider that never emits zoomChange leaves the buttons alone', async () => {
	const session = await openSession( configWith() );

	// `toHaveLength( 0 )`, not `toEqual( [] )` — see the project's own
	// `jest-toequal-empty-array-ignores-undefined` gotcha.
	expect( session.panels.setZoomLimitsCalls ).toHaveLength( 0 );
} );

test( 'panels searchAddressPicked resolves the address AT THAT INDEX against the provider', async () => {
	const session = await openSession( configWith() );
	session.provider.emit( 'searchResults', { points: [], addresses: [ { displayName: 'A' }, { displayName: 'B' } ] } );

	session.panels.emit( 'searchAddressPicked', 1 );

	expect( session.provider.resolveAddressCalls ).toEqual( [ 'B' ] );
} );

// -------------------------------------------------------------------------
// searchType/searchSubmit/searchReset (Task 12, spec V-6) — the layout `pickup-panels.js`
// builds ONCE via `buildSearchLayout()`, and the two-events-two-costs wiring to the provider
// -------------------------------------------------------------------------

test( 'the search layout is built ONCE, at session-open time, and handed to provider.init() as '
	+ 'searchLayoutEl — not built by, or handed through, the provider itself', async () => {
	const session = await openSession( configWith() );

	expect( session.panels.builtSearchLayoutEl ).toBeInstanceOf( HTMLElement );
	expect( session.provider.initCalls[ 0 ].config.searchLayoutEl ).toBe( session.panels.builtSearchLayoutEl );
} );

test( 'an ownsChrome provider never builds a search layout at all (no panels exist to build one)', async () => {
	const session = await openSession( configWith( { ownsChrome: true } ) );

	expect( session.provider.initCalls[ 0 ].config.searchLayoutEl ).toBeNull();
} );

test( 'a plugin that disabled search (config.search: false) gets searchLayoutEl: null — '
	+ 'buildSearchLayout() itself returned null', async () => {
	const session = await openSession( configWith( { search: false } ) );

	expect( session.panels.builtSearchLayoutEl ).toBeUndefined();
	expect( session.provider.initCalls[ 0 ].config.searchLayoutEl ).toBeNull();
} );

test( 'panels searchType filters the ALREADY LOADED pool via provider.matchLoadedPoints() and '
	+ 'PREVIEWS it — free, no provider.searchControl.search() call', async () => {
	const session = await openSession( configWith() );

	session.provider.matchLoadedPointsResult = [ point( { id: 'p1' } ) ];

	session.panels.emit( 'searchType', { query: 'Тверская' } );

	expect( session.provider.matchLoadedPointsCalls ).toEqual( [ 'Тверская' ] );
	expect( session.panels.lastSearchPreview ).toEqual( {
		points: [ point( { id: 'p1' } ) ],
		addresses: [],
	} );
	expect( session.provider.searchControl.search ).not.toHaveBeenCalled();
} );

// Live-review round 3 (operator: "начинаешь писать адрес … появляется «Поиск не дал результатов.»
// и висит"): a keystroke must reach the PREVIEW renderer, never the completed-search one. Both
// used to share `renderSearchResults()`, so an unmatched keystroke rendered the same "nothing
// found" verdict a real empty search does — and typing a street the geocoder has not been asked
// about yet is the normal case, so that verdict was usually a lie. Asserting the negative is the
// point of this test: it is the wiring, not the renderer, that keeps the two apart.
test( 'panels searchType NEVER reaches the completed-search renderer, even when the local pool '
	+ 'matches nothing (round 3)', async () => {
	const session = await openSession( configWith() );

	session.provider.matchLoadedPointsResult = [];

	session.panels.emit( 'searchType', { query: 'Несуществующая' } );

	expect( session.panels.lastSearchPreview ).toEqual( { points: [], addresses: [] } );
	expect( session.panels.lastSearchResults ).toBeUndefined();
} );

// Live-review round 4. The operator typed "Чертановская 66к1" and got a metro station in full
// English postal form where Yandex.Delivery gives "Чертановская улица, 66к1". The provider now
// answers the typing path from `ymaps.suggest()` instead of the geocoder; this file's job is
// simply to PREFER that path when the provider offers it, and to keep the preview renderer.
test( 'panels searchType uses provider.suggestAddresses() when the provider offers it, and '
	+ 'previews its RESOLVED value (round 4)', async () => {
	const session = await openSession( configWith() );
	const suggested = {
		points: [ point( { id: 'p1' } ) ],
		addresses: [ { displayName: 'Чертановская улица, 66к1', query: 'Россия, Москва, Чертановская улица, 66к1' } ],
	};

	withSuggest( session.provider, suggested );

	session.panels.emit( 'searchType', { query: 'Чертановская 66к1' } );
	await flushAsync();

	expect( session.provider.suggestAddressesCalls ).toEqual( [ 'Чертановская 66к1' ] );
	expect( session.panels.lastSearchPreview ).toEqual( suggested );
	// The verdict renderer must stay untouched — see the round-3 guard above.
	expect( session.panels.lastSearchResults ).toBeUndefined();
	expect( session.provider.searchControl.search ).not.toHaveBeenCalled();
} );

test( 'panels searchType still previews the local matches when the provider has no '
	+ 'suggestAddresses() — an embedded provider keeps a working preview (round 4)', async () => {
	const session = await openSession( configWith() );

	session.provider.matchLoadedPointsResult = [ point( { id: 'p1' } ) ];

	session.panels.emit( 'searchType', { query: 'Тверская' } );
	await flushAsync();

	expect( session.provider.matchLoadedPointsCalls ).toEqual( [ 'Тверская' ] );
	expect( session.panels.lastSearchPreview ).toEqual( { points: [ point( { id: 'p1' } ) ], addresses: [] } );
} );

// A suggestion carries the SHORT form the customer reads and the FULL one the geocoder needs.
// Resolving the short form would hand the geocoder a street with no city — the exact ambiguity
// `strictBounds` exists to prevent everywhere else in the provider.
test( 'searchAddressPicked resolves the FULL query string, not the short display one (round 4)', async () => {
	const session = await openSession( configWith() );

	withSuggest( session.provider, {
		points: [],
		addresses: [ { displayName: 'Чертановская улица, 66к1', query: 'Россия, Москва, Чертановская улица, 66к1' } ],
	} );

	session.panels.emit( 'searchType', { query: 'Чертановская 66к1' } );
	await flushAsync();

	session.panels.emit( 'searchAddressPicked', 0 );

	expect( session.provider.resolveAddressCalls ).toEqual( [ 'Россия, Москва, Чертановская улица, 66к1' ] );
} );

test( 'searchAddressPicked falls back to displayName when a suggestion carries no query '
	+ '(round 4)', async () => {
	const session = await openSession( configWith() );

	withSuggest( session.provider, { points: [], addresses: [ { displayName: 'Тверская, 5' } ] } );

	session.panels.emit( 'searchType', { query: 'Тверская' } );
	await flushAsync();

	session.panels.emit( 'searchAddressPicked', 0 );

	expect( session.provider.resolveAddressCalls ).toEqual( [ 'Тверская, 5' ] );
} );

// #179 — the magnifier used to run `provider.searchControl.search()`, i.e. a SECOND search, on
// the POI-ranking geocoder, while the dropdown the customer was looking at had come from
// `suggest()`. Typing «Чертановская 66» offered five house numbers; pressing the magnifier
// replaced them with «Chertanovskaya metro station». The button now resolves what is already on
// screen instead of asking a different service the same question.
test( 'panels searchSubmit resolves the TOP SUGGESTION and never starts a second search (#179)', async () => {
	const session = await openSession( configWith() );

	withSuggest( session.provider, {
		points: [],
		addresses: [
			{ displayName: 'Чертановская улица, 66к1', query: 'Россия, Москва, Чертановская улица, 66к1' },
			{ displayName: 'Чертановская улица, 66к2', query: 'Россия, Москва, Чертановская улица, 66к2' },
		],
	} );

	session.panels.emit( 'searchSubmit', { query: 'Чертановская 66' } );
	await flushAsync();

	// The FULL `query` form, not the trimmed `displayName` — the same rule the click-a-row path
	// already follows, because the trimmed text drops the city and re-geocodes ambiguously.
	expect( session.provider.resolveAddressCalls ).toEqual( [ 'Россия, Москва, Чертановская улица, 66к1' ] );
	expect( session.provider.searchControl.search ).not.toHaveBeenCalled();
} );

// Operator, 07.08.2026: pressing the magnifier moved the camera but left the suggestion list
// hanging open over the map, while clicking a row closed it — the row path calls
// `hideSearchResults()` inside `pickup-panels.js`, the submit path went through the mount and
// nobody closed anything. Same outcome, so the same closing behaviour.
test( 'searchSubmit closes the suggestion list once the address resolves, same as a row click', async () => {
	const session = await openSession( configWith() );

	withSuggest( session.provider, {
		points: [],
		addresses: [ { displayName: 'Цветной бульвар', query: 'Москва, Цветной бульвар' } ],
	} );

	session.panels.emit( 'searchSubmit', { query: 'Цветной бульвар' } );
	await flushAsync();

	expect( session.panels.hideSearchResultsCalls ).toBe( 1 );
} );

// ...but NOT when there was nothing to resolve: that path renders "ничего не найдено" into the
// very box this would close, and closing it would swallow the only answer the customer gets.
test( 'searchSubmit leaves the list open when nothing was suggested — that is where the '
	+ '"nothing found" answer is rendered', async () => {
	const session = await openSession( configWith() );

	withSuggest( session.provider, { points: [], addresses: [] } );

	session.panels.emit( 'searchSubmit', { query: 'йцукен' } );
	await flushAsync();

	expect( session.panels.hideSearchResultsCalls ).toBe( 0 );
} );

test( 'panels searchReset clears the provider\'s address state via clearAddress(), same as '
	+ 'anchorCleared', async () => {
	const session = await openSession( configWith() );

	session.panels.emit( 'searchReset', {} );

	expect( session.provider.clearAddressCalls ).toBe( 1 );
} );

// -------------------------------------------------------------------------
// Work item 5/round 2 — the submit button's busy state: on while a real search is in flight,
// released on whichever of the three outcomes actually answers. Both the button and that flag are
// gone (operator, 07.08.2026 — see pickup-panels.js's "NO SUBMIT BUTTON" note); what survives is
// the reason they existed: a submit spends a geocode, so a second one must not start while the
// first is still in flight. The mount owns that guard now, because the mount owns the round trip.
// -------------------------------------------------------------------------

test( 'searchSubmit is a no-op on a provider that cannot suggest — nothing to resolve, and no '
	+ 'state left raised behind it', async () => {
	const session = await openSession( configWith() );

	// The embedded provider owns its own chrome and offers no suggestAddresses() at all.
	session.provider.suggestAddresses = undefined;

	session.panels.emit( 'searchSubmit', { query: 'Тверская 5' } );
	await flushAsync();

	expect( session.provider.resolveAddressCalls ).toEqual( [] );
} );

test( 'a second searchSubmit while the first is still in flight is ignored — one Enter, one '
	+ 'geocode', async () => {
	const session = await openSession( configWith() );
	let release;

	session.provider.suggestAddressesCalls = [];
	session.provider.suggestAddresses = function( query ) {
		session.provider.suggestAddressesCalls.push( query );

		return new Promise( ( resolve ) => {
			release = () => resolve( {
				points: [],
				addresses: [ { displayName: 'Тверская, 5', query: 'Москва, Тверская, 5' } ],
			} );
		} );
	};

	session.panels.emit( 'searchSubmit', { query: 'Тверская 5' } );
	session.panels.emit( 'searchSubmit', { query: 'Тверская 5' } );

	expect( session.provider.suggestAddressesCalls ).toEqual( [ 'Тверская 5' ] );

	release();
	await flushAsync();

	// ...and the guard lifts once it settles, so the field is not dead afterwards.
	session.panels.emit( 'searchSubmit', { query: 'Тверская 5' } );

	expect( session.provider.suggestAddressesCalls ).toHaveLength( 2 );
} );

// -------------------------------------------------------------------------
// D1a/round 2 (the "crossik" bug) — searchCleared REPLACES the old empty-searchResults hack:
// clearAddress() no longer round-trips through an empty searchResults, which used to re-open the
// results box the customer had just closed and print "не найдено" at them.
// -------------------------------------------------------------------------

test( 'provider searchCleared hides the search results box', async () => {
	const session = await openSession( configWith() );

	session.provider.emit( 'searchCleared', {} );

	expect( session.panels.hideSearchResultsCalls ).toBe( 1 );
} );

// -------------------------------------------------------------------------
// addressMatchedPoint (late addition, live-review round 2) — a searched address that resolves
// onto one of our own points becomes that point: routes through 'cardOpened' with origin
// 'search', which both opens the card and (D6) centres-and-zooms the camera onto it.
// -------------------------------------------------------------------------

test( 'provider addressMatchedPoint opens the matched point\'s card, origin "search"', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () =>
		Promise.resolve( [ point( { id: 'p1', lat: 1, lng: 2 } ) ] )
	);
	const session = await openSession( configWith() );

	session.provider.emit( 'addressMatchedPoint', { key: '1.0000,2.0000' } );

	expect( session.panels.lastOpenCard.group.key ).toBe( '1.0000,2.0000' );
	expect( session.panels.lastOpenCard.origin ).toBe( 'search' );
} );

test( 'provider addressMatchedPoint is a silent no-op when its key names a group that is not '
	+ 'currently loaded (a stale key from an in-flight geocode racing a refetch)', async () => {
	const session = await openSession( configWith() );

	expect( () => session.provider.emit( 'addressMatchedPoint', { key: 'ghost' } ) ).not.toThrow();
	expect( session.panels.lastOpenCard ).toBeUndefined();
} );

test( 'provider addressFocused moves the panels\' distance anchor to the SAME latLng/label (D-6)', async () => {
	const session = await openSession( configWith() );

	session.provider.emit( 'addressFocused', { latLng: [ 55.75, 37.61 ], label: 'Москва, Тверская 1' } );

	expect( session.panels.setAnchorCalls ).toEqual( [
		{ latLng: [ 55.75, 37.61 ], label: 'Москва, Тверская 1' },
	] );
} );

test( 'provider addressFocused also opens the list — a stale open card must not survive an '
	+ 'address pick (rig verification finding)', async () => {
	const session = await openSession( configWith() );

	session.provider.emit( 'addressFocused', { latLng: [ 55.75, 37.61 ], label: 'Москва, Тверская 1' } );

	expect( session.panels.openListCalls ).toBe( 1 );
} );

test( 'provider addressFocused moves the anchor even when nothing turns out to be nearby '
	+ '(the pin still dropped)', async () => {
	const session = await openSession( configWith() );

	session.provider.emit( 'addressFocused', { latLng: [ 1, 2 ], label: 'Далеко' } );
	session.provider.emit( 'nothingNearby', { key: 'g', distanceMeters: 99999, name: 'X' } );

	expect( session.panels.setAnchorCalls ).toEqual( [ { latLng: [ 1, 2 ], label: 'Далеко' } ] );
	expect( session.panels.lastNothingNearby ).toEqual( { key: 'g', distanceMeters: 99999, name: 'X' } );
} );

test( 'panels searchPointPicked focuses the owning group and opens its card on the exact point', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () =>
		Promise.resolve( [ point( { id: 'p9', lat: 10, lng: 20 } ) ] )
	);
	const session = await openSession( configWith() );

	session.panels.emit( 'searchPointPicked', 'p9' );

	expect( session.provider.focusGroupCalls ).toEqual( [ '10.0000,20.0000' ] );
	expect( session.panels.lastOpenCard.pointId ).toBe( 'p9' );
} );

test( 'panels showNearestRequested focuses the group named by info.key and opens its card', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () =>
		Promise.resolve( [ point( { id: 'p1', lat: 55.8, lng: 37.7 } ) ] )
	);
	const session = await openSession( configWith() );

	session.panels.emit( 'showNearestRequested', { key: '55.8000,37.7000', distanceMeters: 100, name: 'X' } );

	expect( session.provider.focusGroupCalls ).toEqual( [ '55.8000,37.7000' ] );
	expect( session.panels.lastOpenCard.group.key ).toBe( '55.8000,37.7000' );
} );

test( 'panels showNearestRequested is a no-op when info.key names a group that is no longer loaded', async () => {
	const session = await openSession( configWith() );

	expect( () => session.panels.emit( 'showNearestRequested', { key: 'ghost', distanceMeters: 1, name: 'X' } ) )
		.not.toThrow();
	expect( session.provider.focusGroupCalls ).toEqual( [] );
} );

test( 'panels anchorCleared clears the address — the "your address" pin cannot outlive its search', async () => {
	const session = await openSession( configWith() );

	session.panels.emit( 'anchorCleared', null );

	expect( session.provider.clearAddressCalls ).toBe( 1 );
} );

test( 'panels.setSelectedId is seeded from the field\'s current value at session-open time', async () => {
	document.getElementById( FIELD_ID ).value = 'PVZ-EXISTING';

	const session = await openSession( configWith() );

	expect( session.panels.lastSelectedId ).toBe( 'PVZ-EXISTING' );
} );

test( 'panels.setSelectedId is never called when the field has no value yet', async () => {
	const session = await openSession( configWith() );

	expect( session.panels.lastSelectedId ).toBeUndefined();
} );

// -------------------------------------------------------------------------
// The strategy-dependent type-filter destination (D-10) — getting this
// backwards is invisible under a loosely-stubbed dataSource, so both sides
// are pinned by VALUE, not just "some branch was taken"
// -------------------------------------------------------------------------

test( 'typeFilterChange under bulk calls provider.setTypeFilter and does NOT refetch', async () => {
	let fetchCalls = 0;
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => {
		fetchCalls += 1;
		return Promise.resolve( [] );
	} );

	const session = await openSession( configWith( { strategy: 'bulk' } ) );
	const callsBefore = fetchCalls;

	session.panels.emit( 'typeFilterChange', [ 'pvz' ] );
	await flushAsync();

	expect( session.provider.setTypeFilterCalls ).toEqual( [ [ 'pvz' ] ] );
	expect( fetchCalls ).toBe( callsBefore ); // client-side filter only — a refetch would be waste
} );

test( 'typeFilterChange under viewport refetches the SAME bbox + new types, never setTypeFilter', async () => {
	const queries = [];
	window.WoodevPickupDataSource = fakeDataSourceFactory( ( query ) => {
		queries.push( query );
		return Promise.resolve( [] );
	} );

	const session = await openSession( configWith( { strategy: 'viewport' } ) );

	session.provider.emit( 'boundsChange', [ 1, 2, 3, 4 ] );
	await flushAsync();

	session.panels.emit( 'typeFilterChange', [ 'pvz' ] );
	await flushAsync();

	expect( session.provider.setTypeFilterCalls ).toEqual( [] ); // never a client-side filter under viewport
	expect( queries[ queries.length - 1 ] ).toEqual( { bounds: [ 1, 2, 3, 4 ], types: [ 'pvz' ] } );
} );

test( 'typeFilterChange under viewport, before any boundsChange, does not throw and does not fetch', async () => {
	let fetchCalls = 0;
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => {
		fetchCalls += 1;
		return Promise.resolve( [] );
	} );

	const session = await openSession( configWith( { strategy: 'viewport' } ) );
	const callsBefore = fetchCalls;

	expect( () => session.panels.emit( 'typeFilterChange', [ 'pvz' ] ) ).not.toThrow();
	await flushAsync();

	expect( fetchCalls ).toBe( callsBefore );
} );

// -------------------------------------------------------------------------
// boundsChange (viewport) drives the fetch → setPoints() → panels.setTypes()
// chain end to end
// -------------------------------------------------------------------------

test( 'a viewport boundsChange fetches, groups, and hands the groups to provider.setPoints()', async () => {
	window.WoodevPickupDataSource = fakeDataSourceFactory( () => Promise.resolve( [
		point( { id: 'a', lat: 1, lng: 2 } ),
		point( { id: 'b', lat: 1, lng: 2 } ), // co-located with 'a' — folds into the SAME group
	] ) );

	const session = await openSession( configWith( { strategy: 'viewport' } ) );

	session.provider.emit( 'boundsChange', [ 1, 2, 3, 4 ] );
	await flushAsync();

	const lastGroups = session.provider.setPointsCalls[ session.provider.setPointsCalls.length - 1 ];
	expect( lastGroups ).toHaveLength( 1 );
	expect( lastGroups[ 0 ].points ).toHaveLength( 2 );
	expect( session.panels.lastTypes ).toEqual( [ { code: 'pvz', label: 'ПВЗ' } ] );
} );

// -------------------------------------------------------------------------
// A real Panels integration smoke test — proves buildPanelsConfig() actually
// produces a shape the REAL pickup-panels.js class accepts and renders from
// -------------------------------------------------------------------------

test( 'INTEGRATION: the real Panels class renders correctly from buildPanelsConfig()\'s output', async () => {
	window.WoodevPickupPanels = RealPanels;

	const config = configWith( {
		mapConfig: { center: [ 55.75, 37.61 ], lang: 'ru_RU' },
		i18n: phpI18n( { drawerTitle: 'Пункты выдачи в этой области' } ),
	} );
	setConfig( config );
	mountAll();
	clickTrigger();
	await flushAsync();

	const dialog = document.querySelector( '[role="dialog"]' );

	// The real class builds its whole structure from that config: the stage, the map element the
	// provider mounts into, and both panels.
	const stage = dialog.querySelector( '.woodev-pickup-stage' );
	expect( stage ).not.toBeNull();
	expect( stage.querySelector( '.woodev-pickup-map' ) ).not.toBeNull();
	expect( stage.querySelector( '.woodev-pickup-list' ) ).not.toBeNull();
	expect( stage.querySelector( '.woodev-pickup-card' ) ).not.toBeNull();

	// And the i18n map reached it. This used to be asserted through the list header's text; Task 7
	// (spec V-11) deleted that header — neither reference has one and it stated something the
	// customer can see — so `drawerTitle` now names the control that opens the drawer instead.
	expect( stage.querySelector( '.woodev-pickup-list__toggle' ).getAttribute( 'aria-label' ) )
		.toBe( 'Пункты выдачи в этой области' );
} );

test( 'INTEGRATION: a REAL click on a sidebar list row reaches focusGroup() with { zoom: true } — '
	+ 'UNLIKE a marker click (D6, round 2: spec V-10\'s "identical path" claim is overruled) — '
	+ 'pickup-panels.js itself is never touched by this file', async () => {
	window.WoodevPickupPanels = RealPanels;
	window.WoodevPickupDataSource = fakeDataSourceFactory( () =>
		Promise.resolve( [ point( { id: 'p1', lat: 1, lng: 2 } ) ] )
	);

	const config = configWith();
	setConfig( config );
	mountAll();
	clickTrigger();
	await flushAsync();

	// The real Panels' list only ever shows what the provider last reported visible — the stub
	// provider never emits that on its own, so this drives it exactly like a real one would once
	// its viewport settles.
	const provider = StubProvider.instances[ StubProvider.instances.length - 1 ];
	provider.emit( 'visibleChange', [ '1.0000,2.0000' ] );

	const dialog = document.querySelector( '[role="dialog"]' );
	dialog.querySelector( '.woodev-pickup-list__item' ).click();

	expect( provider.focusGroupCalls ).toEqual( [ '1.0000,2.0000' ] );
	// The real Panels' own list-row builder passes origin: 'list' (pickup-panels.js's
	// renderList()) — unlike a marker click's 'marker', so the camera centres AND zooms here.
	expect( provider.focusGroupOptions ).toEqual( [ { zoom: true } ] );
} );

// -------------------------------------------------------------------------
// Task 16 (spec V-4): three loading stages, and the modal stays closable in every one of them.
// `window.WoodevPickupPanels = RealPanels` throughout — the `.woodev-pickup-stage`/
// `.woodev-pickup-overlay` DOM these tests assert on is a REAL Panels artifact the StubPanels
// double used everywhere else in this file never builds (see that double's own docblock).
// -------------------------------------------------------------------------
describe( 'loading stages (spec V-4)', () => {
	beforeEach( () => {
		window.WoodevPickupPanels = RealPanels;
	} );

	/**
	 * Swaps in a dataSource whose `fetchPoints()` never settles until the test calls the
	 * returned `resolve()`/`reject()` — the only way to observe stage 2 (map drawn, first fetch
	 * still in flight) as a state distinct from stage 3. `flushAsync()` alone cannot do this: it
	 * only drains ALREADY-SCHEDULED microtasks, never forces an unsettled Promise to settle.
	 *
	 * @returns {{resolve: Function, reject: Function}}
	 */
	function pendingDataSource() {
		let settle;

		window.WoodevPickupDataSource = fakeDataSourceFactory( () => new Promise( ( resolve, reject ) => {
			settle = { resolve, reject };
		} ) );

		return {
			resolve: ( points ) => settle.resolve( points || [] ),
			reject: ( reason ) => settle.reject( reason ),
		};
	}

	test( 'stage 1: the modal spinner shows before the map is ready; the stage is not yet busy', () => {
		pendingDataSource();
		setConfig( configWith() );
		mountAll();
		clickTrigger();

		// No `await` at all — `provider.init()`'s OWN `Promise.resolve( initResult ).then()` has not
		// had a microtask turn to run yet, so this is still exactly stage 1.
		const dialog = document.querySelector( '[role="dialog"]' );

		expect( dialog.querySelector( '.woodev-modal__loading' ) ).not.toBeNull();
		expect( dialog.querySelector( '.woodev-pickup-stage' ).className ).not.toContain( 'is-busy' );
		expect( dialog.querySelector( '.woodev-pickup-overlay' ).hidden ).toBe( true );
	} );

	test( 'stage 2: once the map is drawn the modal spinner is gone and the stage is busy '
		+ 'until the first fetch settles', async () => {
		pendingDataSource();
		setConfig( configWith() );
		mountAll();
		clickTrigger();

		await flushAsync();

		const dialog = document.querySelector( '[role="dialog"]' );

		expect( dialog.querySelector( '.woodev-modal__loading' ) ).toBeNull();
		expect( dialog.querySelector( '.woodev-pickup-stage' ).className ).toContain( 'is-busy' );
		expect( dialog.querySelector( '.woodev-pickup-overlay' ).hidden ).toBe( false );
	} );

	test( 'stage 3: once points arrive the busy state clears and the map is interactive again', async () => {
		const fetch = pendingDataSource();
		setConfig( configWith() );
		mountAll();
		clickTrigger();
		await flushAsync();

		fetch.resolve( [ point() ] );
		await flushAsync();

		const dialog = document.querySelector( '[role="dialog"]' );

		expect( dialog.querySelector( '.woodev-pickup-stage' ).className ).not.toContain( 'is-busy' );
		expect( dialog.querySelector( '.woodev-pickup-overlay' ).hidden ).toBe( true );
	} );

	test( 'a FAILED first fetch still clears the busy state — the map must not stay stuck '
		+ 'non-interactive over a request that will never come back', async () => {
		const fetch = pendingDataSource();
		setConfig( configWith() );
		mountAll();
		clickTrigger();
		await flushAsync();

		fetch.reject( { status: 0, code: 'woodev_pickup_upstream_error', message: 'offline' } );
		await flushAsync();

		const dialog = document.querySelector( '[role="dialog"]' );

		expect( dialog.querySelector( '.woodev-pickup-stage' ).className ).not.toContain( 'is-busy' );
	} );

	test( 'an initial viewport too wide to fetch at all still clears the busy state', async () => {
		pendingDataSource();
		setConfig( configWith( { strategy: 'viewport' } ) );
		mountAll();
		clickTrigger();
		await flushAsync();

		const provider = StubProvider.instances[ StubProvider.instances.length - 1 ];

		provider.emit( 'bboxTooWide' );
		await flushAsync();

		const dialog = document.querySelector( '[role="dialog"]' );

		expect( dialog.querySelector( '.woodev-pickup-stage' ).className ).not.toContain( 'is-busy' );
	} );

	test( 'a later viewport refetch never re-arms the busy overlay once stage 3 was reached', async () => {
		let fetchCalls = 0;
		let secondFetchSettle = null;

		window.WoodevPickupDataSource = fakeDataSourceFactory( () => {
			fetchCalls += 1;

			// The FIRST fetch (the opening one this describe block is about) settles immediately;
			// the SECOND is left deliberately pending, so the test can prove the overlay stays
			// down while it is in flight.
			return 1 === fetchCalls
				? Promise.resolve( [ point() ] )
				: new Promise( ( resolve ) => { secondFetchSettle = resolve; } );
		} );

		setConfig( configWith( { strategy: 'viewport' } ) );
		mountAll();
		clickTrigger();
		await flushAsync();

		const provider = StubProvider.instances[ StubProvider.instances.length - 1 ];
		const dialog = document.querySelector( '[role="dialog"]' );

		provider.emit( 'boundsChange', [ 1, 2, 3, 4 ] );
		await flushAsync();

		expect( dialog.querySelector( '.woodev-pickup-stage' ).className ).not.toContain( 'is-busy' );

		provider.emit( 'boundsChange', [ 5, 6, 7, 8 ] );
		await flushAsync();

		expect( dialog.querySelector( '.woodev-pickup-stage' ).className ).not.toContain( 'is-busy' );
		expect( secondFetchSettle ).not.toBeNull(); // proves the 2nd fetch really is still pending
	} );

	test( 'the modal stays closable while stage 1 (the modal spinner) is showing', () => {
		pendingDataSource();
		setConfig( configWith() );
		mountAll();
		clickTrigger();

		const onClose = jest.fn();

		// `WoodevModal` has no `.on()` method — it dispatches native CustomEvents on
		// `document.body` (`woodev_modal_closed` and friends); every event test in
		// woodev-modal.test.js follows this same idiom.
		document.body.addEventListener( 'woodev_modal_closed', onClose );
		document.querySelector( '.woodev-modal__close' ).click();
		document.body.removeEventListener( 'woodev_modal_closed', onClose );

		expect( onClose ).toHaveBeenCalled();
	} );

	test( 'the modal stays closable while the stage is busy (stage 2)', async () => {
		pendingDataSource();
		setConfig( configWith() );
		mountAll();
		clickTrigger();
		await flushAsync();

		expect( document.querySelector( '.woodev-pickup-stage' ).className ).toContain( 'is-busy' );

		const onClose = jest.fn();

		document.body.addEventListener( 'woodev_modal_closed', onClose );
		document.querySelector( '.woodev-modal__close' ).click();
		document.body.removeEventListener( 'woodev_modal_closed', onClose );

		expect( onClose ).toHaveBeenCalled();
	} );

	// Adversarial-review finding: `dropLoading()` deliberately skips `panels.setLoading( false )`
	// once `destroyed` is true, so IF the stage element survived teardown a request settling after
	// `destroy()` would leave it pinned `is-loading` forever. It does not survive: this session's
	// own `destroy()` calls `panels.destroy()`, whose own docblock states it removes `this._stage`
	// from the DOM ("Panels.prototype.destroy" in pickup-panels.js) — SYNCHRONOUSLY, before the
	// in-flight request ever gets a chance to settle. This test pins that invariant directly
	// (rather than re-deriving it from the two files' docblocks on faith) so a future change to
	// either `destroy()` cannot silently re-open the leak this finding worried about.
	test( 'destroying the session while a fetch is in flight removes the stage from the DOM — a '
		+ 'later settle has nothing left to leave stuck is-loading', async () => {
		const fetch = pendingDataSource();

		setConfig( configWith() );
		mountAll();
		clickTrigger();
		await flushAsync();

		const stage = document.querySelector( '.woodev-pickup-stage' );

		expect( stage ).not.toBeNull();
		expect( document.body.contains( stage ) ).toBe( true );

		getSession( FIELD_ID ).destroy();

		// The stage is gone from the document IMMEDIATELY — destroy() is synchronous end to end
		// (panels.destroy() included), never waiting on the still-pending fetch below.
		expect( document.body.contains( stage ) ).toBe( false );

		// The request settles AFTER teardown — `dropLoading()`'s `destroyed` guard skips
		// `setLoading( false )` here, exactly as designed, and it is harmless: there is no live
		// `.woodev-pickup-stage` left anywhere for a stale `is-loading` class to be visible on.
		fetch.resolve( [] );
		await flushAsync();

		expect( document.querySelectorAll( '.woodev-pickup-stage' ) ).toHaveLength( 0 );
	} );
} );

// -------------------------------------------------------------------------
// Task 11 — the selection is confirmed with the server BEFORE it is accepted
// (spec §5, D-2/D-5/D-6/D-7/D-9/D-11)
// -------------------------------------------------------------------------

describe( 'selection confirmation', () => {
	it( 'fires the requested event, locks the card and posts the point', async () => {
		const { panels, dataSource, emitSelect } = openPicker( { selection: { close: false } } );
		const seen = [];
		document.body.addEventListener( 'woodev_pickup_point_select_requested', ( e ) => seen.push( e.detail ) );

		emitSelect( { id: 'P1' } );

		expect( panels.setSelectionBusy ).toHaveBeenCalledWith( true );
		expect( seen[ 0 ].point.id ).toBe( 'P1' );
		expect( dataSource.selectPoint ).toHaveBeenCalledWith(
			expect.objectContaining( { pointId: 'P1' } )
		);
	} );

	it( 'writes the field and shows continueCheckout when close is false', async () => {
		const { emitSelect, resolveSelect, modal, field } = openPicker( { selection: { close: false } } );

		emitSelect( { id: 'P1' } );
		await resolveSelect( { allowed: true, reason: null, close: null, refresh_checkout: null } );

		expect( field.value ).toBe( 'P1' );
		expect( modal.close ).not.toHaveBeenCalled();
	} );

	it( 'closes immediately when the domain says so, overriding a false config default', async () => {
		const { emitSelect, resolveSelect, modal } = openPicker( { selection: { close: false } } );

		emitSelect( { id: 'P1' } );
		await resolveSelect( { allowed: true, reason: null, close: true, refresh_checkout: null } );

		expect( modal.close ).toHaveBeenCalledWith( 'select' );
	} );

	it( 'honours an explicit false over a true config default — ?? not ||', async () => {
		const { emitSelect, resolveSelect, modal } = openPicker( { selection: { close: true } } );

		emitSelect( { id: 'P1' } );
		await resolveSelect( { allowed: true, reason: null, close: false, refresh_checkout: null } );

		expect( modal.close ).not.toHaveBeenCalled();
	} );

	it( 'records a refusal on the point and does not write the field', async () => {
		const { emitSelect, resolveSelect, panels, field } = openPicker( {} );

		emitSelect( { id: 'P1' } );
		await resolveSelect( { allowed: false, reason: 'Тяжело', close: null, refresh_checkout: null } );

		expect( panels.setPointVerdict ).toHaveBeenCalledWith( 'P1', { allowed: false, reason: 'Тяжело' } );
		expect( field.value ).toBe( '' );
	} );

	it( 'shows a transient error on a transport failure and keeps the point usable', async () => {
		const { emitSelect, rejectSelect, panels } = openPicker( {
			i18n: { selectFailed: 'Не удалось', stalePage: 'Устарела' },
		} );

		emitSelect( { id: 'P1' } );
		await rejectSelect( { status: 500, code: 'woodev_pickup_upstream_error' } );

		expect( panels.showSelectionError ).toHaveBeenCalledWith( 'Не удалось' );
		expect( panels.setPointVerdict ).not.toHaveBeenCalled();
	} );

	it( 'names a stale page instead of the generic failure', async () => {
		const { emitSelect, rejectSelect, panels } = openPicker( {
			i18n: { selectFailed: 'Не удалось', stalePage: 'Устарела' },
		} );

		emitSelect( { id: 'P1' } );
		await rejectSelect( { status: 403, code: 'rest_cookie_invalid_nonce' } );

		expect( panels.showSelectionError ).toHaveBeenCalledWith( 'Устарела' );
	} );

	it( 'discards an answer for a point the card no longer shows', async () => {
		const { emitSelect, resolveSelect, panels, field, setActivePoint } = openPicker( {} );

		emitSelect( { id: 'P1' } );
		setActivePoint( 'P2' );
		await resolveSelect( { allowed: true, reason: null, close: null, refresh_checkout: null } );

		expect( field.value ).toBe( '' );
		expect( panels.setPointVerdict ).not.toHaveBeenCalled();
	} );

	it( 'always clears the busy state, on every outcome', async () => {
		const { emitSelect, rejectSelect, panels } = openPicker( {} );

		emitSelect( { id: 'P1' } );
		await rejectSelect( { status: 500, code: 'woodev_pickup_upstream_error' } );

		expect( panels.setSelectionBusy ).toHaveBeenLastCalledWith( false );
	} );

	it( 'triggers update_checkout only when asked', async () => {
		const { emitSelect, resolveSelect, jq } = openPicker( { selection: { refreshCheckout: false } } );

		emitSelect( { id: 'P1' } );
		await resolveSelect( { allowed: true, reason: null, close: null, refresh_checkout: true } );

		expect( jq.triggered ).toContain( 'update_checkout' );
	} );

	it( 'does not trigger update_checkout when nobody asked', async () => {
		const { emitSelect, resolveSelect, jq } = openPicker( { selection: { refreshCheckout: false } } );

		emitSelect( { id: 'P1' } );
		await resolveSelect( { allowed: true, reason: null, close: null, refresh_checkout: null } );

		expect( jq.triggered ).not.toContain( 'update_checkout' );
	} );

	it( 'a second click on continueCheckout closes without a second request', async () => {
		const { emitSelect, resolveSelect, dataSource, modal } = openPicker( { selection: { close: false } } );

		emitSelect( { id: 'P1' } );
		await resolveSelect( { allowed: true, reason: null, close: null, refresh_checkout: null } );

		emitSelect( { id: 'P1' } ); // the CTA now reads continueCheckout

		expect( dataSource.selectPoint ).toHaveBeenCalledTimes( 1 );
		expect( modal.close ).toHaveBeenCalledWith( 'select' );
	} );

	// The three below are additions to the plan's own twelve, covering what settles against
	// something that is NOT an ordinary open card: a discarded answer (the busy release is the
	// one thing that still has to happen), no card at all (`ownsChrome`), and a card whose
	// whole session has been destroyed. None of the twelve reaches any of them, and the first
	// is the plan's own ordering bug — it released the busy state only AFTER the staleness
	// guard's early return, which leaves the card locked forever on a discarded answer.

	it( 'releases the card even for an answer it then discards as stale', async () => {
		const { emitSelect, resolveSelect, panels, setActivePoint } = openPicker( {} );

		emitSelect( { id: 'P1' } );
		setActivePoint( 'P2' );
		await resolveSelect( { allowed: true, reason: null, close: null, refresh_checkout: null } );

		// The third of `setSelectionBusy()`'s own documented settlement paths (see its docblock
		// in pickup-panels.js): accepted, refused, AND discarded-as-stale. A card left locked
		// here has a dead CTA reading «Проверяем…» over a request that already came back.
		expect( panels.setSelectionBusy ).toHaveBeenLastCalledWith( false );
	} );

	it( 'confirms an embedded provider\'s selection with no card of ours to lock (ownsChrome)', async () => {
		const { emitSelect, resolveSelect, dataSource, modal, field } = openPicker( {
			ownsChrome: true,
			selection: { close: true },
		} );

		emitSelect( { id: 'P1' } );
		await resolveSelect( { allowed: true, reason: null, close: null, refresh_checkout: null } );

		expect( dataSource.selectPoint ).toHaveBeenCalledWith( expect.objectContaining( { pointId: 'P1' } ) );
		expect( field.value ).toBe( 'P1' );
		expect( modal.close ).toHaveBeenCalledWith( 'select' );
	} );

	// The three dialog-dismissal paths spec D-9 names alongside the card moving to another
	// point. Driven through the REAL DOM interactions rather than `modal.close( reason )`,
	// because the thing under test is that each of them reaches the guard at all — a test that
	// called `close()` itself would pass even if the modal's own Escape/backdrop bindings had
	// been the ones to go missing.
	it.each( [
		[ 'Escape', () => document.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape' } ) ) ],
		[ 'the backdrop', () => document.querySelector( '[role="dialog"]' ).parentNode
			.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) ) ],
		[ 'the close button', () => document.querySelector( '.woodev-modal__close' ).click() ],
	] )( 'discards an answer for a dialog %s had already dismissed', async ( _label, dismiss ) => {
		const { emitSelect, resolveSelect, panels, field } = openPicker( {} );
		const requested = [];
		const resolved = [];
		document.body.addEventListener( 'woodev_pickup_point_select_requested', () => requested.push( 1 ) );
		document.body.addEventListener( 'woodev_pickup_point_select_resolved', () => resolved.push( 1 ) );

		emitSelect( { id: 'P1' } );
		dismiss();

		await resolveSelect( { allowed: true, reason: null, close: null, refresh_checkout: null } );

		// Nothing is applied to a picker the customer has already walked away from — not the
		// field, and not the point's stored verdict. The server may well hold P1 by now; D-10
		// accepts that divergence explicitly and still says to ignore the answer.
		expect( field.value ).toBe( '' );
		expect( panels.setPointVerdict ).not.toHaveBeenCalled();

		// The discarded outcome is SILENT, and the two confirmation events are therefore not
		// always paired: `_requested` went out, `_resolved` never follows. Pinned here because
		// a plugin listening to `_resolved` writes the point's address into the checkout fields
		// (D-14) — firing it for a point we just threw away would leave the customer with the
		// address of somewhere they are not collecting from.
		expect( requested ).toHaveLength( 1 );
		expect( resolved ).toHaveLength( 0 );
	} );

	it( 'unbinds its updated_checkout waiter when the session dies before WooCommerce answers', async () => {
		const { emitSelect, resolveSelect, panels, jq } = openPicker( {
			selection: { close: false, refreshCheckout: true },
		} );

		emitSelect( { id: 'P1' } );
		await resolveSelect( { allowed: true, reason: null, close: null, refresh_checkout: null } );

		// The modal stayed open, so the card is HELD busy until the totals settle — otherwise
		// «Продолжить оформление» is clickable in the middle of a checkout update (spec §5.2).
		expect( jq.triggered ).toContain( 'update_checkout' );
		expect( jq.one ).toHaveLength( 1 );
		expect( panels.setSelectionBusy ).toHaveBeenLastCalledWith( true );

		// A fresh trigger click destroys this session mid-refresh. `updated_checkout` may now
		// never fire at all (a failed checkout ajax is free not to fire it), and a `one()` only
		// cleans itself up if it DOES — so an unbound waiter would keep the panels and their
		// whole DOM graph alive for the life of the document.
		clickTrigger();

		expect( jq.off ).toHaveLength( 1 );
		expect( jq.off[ 0 ].type ).toBe( 'updated_checkout' );
		expect( jq.off[ 0 ].handler ).toBe( jq.one[ 0 ].handler );

		// And belt-and-braces: even reached anyway — jQuery gone by teardown, so the `off()`
		// above could not run — it no longer touches the dead session's panels.
		panels.setSelectionBusy.mockClear();
		jq.one[ 0 ].handler();

		expect( panels.setSelectionBusy ).not.toHaveBeenCalled();
	} );

	it( 'discards an answer whose whole session was torn down while it was in flight', async () => {
		const { emitSelect, resolveSelect, field } = openPicker( {} );

		emitSelect( { id: 'P1' } );

		// A fresh click on the checkout trigger — the one path that destroys a live session
		// outright (`mountOne()` closes whatever session the field currently has before opening
		// another). The answer below is about a picker that no longer exists.
		clickTrigger();

		await resolveSelect( { allowed: true, reason: null, close: null, refresh_checkout: null } );

		expect( field.value ).toBe( '' );
	} );
} );

// -------------------------------------------------------------------------
// The staleness guard is a GENERATION, not a point id (adversarial review, findings 1+2)
//
// Tracking only the point id makes the guard an ABA test: a confirmation dropped by the guard
// and a LATER one for the SAME point are indistinguishable, so the first one's answer is applied
// as though it were the second's — and clears the second's marker on the way out, so the answer
// the customer is actually waiting for is the one that gets thrown away. Both halves below are
// one design: every confirmation is minted a unique, monotonic token, and the card's busy lock
// belongs to whichever token currently holds it.
//
// TWO INVARIANTS, and they pull in opposite directions — which is why "always release" (what
// this file did) and "release only when the answer is applied" (what the original plan did) are
// both wrong:
//
//   1. A discarded answer never leaves the card locked. Whatever DROPS a pending confirmation
//      releases the lock it was holding, at the moment it drops it — the card moving on, the
//      dialog being dismissed, the session being destroyed.
//   2. A live confirmation's lock is never released by a stale one settling. A settling answer
//      touches the lock ONLY while it still owns it.
// -------------------------------------------------------------------------

describe( 'the staleness guard is a generation, not a point id', () => {
	it( 'discards a superseded answer for the point a NEW confirmation is in flight for (ABA)', async () => {
		const { emitSelect, resolveSelect, dataSource, panels, field, setActivePoint } = openPicker( {} );

		// A leaves for P1.
		emitSelect( { id: 'P1' } );

		// The card moves off P1 and back onto it — the marker-click path the lock cannot
		// intercept. A is dropped by the guard here; nothing about the card says so afterwards.
		setActivePoint( 'P2' );
		setActivePoint( 'P1' );

		// B leaves for the SAME point. Under an id-only guard, A and B are now indistinguishable.
		emitSelect( { id: 'P1' } );

		expect( dataSource.selectPoint ).toHaveBeenCalledTimes( 2 );

		// A answers first, and its verdict is about a confirmation the customer walked away from.
		await resolveSelect( { allowed: false, reason: 'Устаревший ответ', close: null, refresh_checkout: null } );

		expect( panels.setPointVerdict ).not.toHaveBeenCalled();
		expect( field.value ).toBe( '' );

		// ...and B's real answer must still land, rather than being discarded because A cleared
		// the pending marker on its way through.
		await resolveSelect( { allowed: true, reason: null, close: null, refresh_checkout: null } );

		expect( field.value ).toBe( 'P1' );
	} );

	it( 'never unlocks the card a LIVE confirmation holds when a stale one settles', async () => {
		const { emitSelect, resolveSelect, panels, setActivePoint } = openPicker( {} );

		emitSelect( { id: 'P1' } );  // A
		setActivePoint( 'P2' );      // A dropped — its lock is released right here
		emitSelect( { id: 'P2' } );  // B, live, re-locks the card

		panels.setSelectionBusy.mockClear();

		await resolveSelect( { allowed: true, reason: null, close: null, refresh_checkout: null } );

		// The lock belongs to B now. A settling must not hand the customer a live CTA over a
		// confirmation that is still out — that is the overlapping-submit hole.
		expect( panels.setSelectionBusy ).not.toHaveBeenCalledWith( false );
	} );

	it( 'releases the card the moment the confirmation holding it is dropped, not when it answers', () => {
		const { emitSelect, panels, setActivePoint } = openPicker( {} );

		emitSelect( { id: 'P1' } );

		expect( panels.setSelectionBusy ).toHaveBeenLastCalledWith( true );

		// Invariant 1. Nothing is in flight FOR THE CARD any more, so it must be usable for the
		// point it now shows — not left reading «Проверяем…» until an answer nobody wants lands.
		setActivePoint( 'P2' );

		expect( panels.setSelectionBusy ).toHaveBeenLastCalledWith( false );
	} );

	it( 'releases the card when the dialog is dismissed out from under a confirmation', () => {
		const { emitSelect, panels } = openPicker( {} );

		emitSelect( { id: 'P1' } );
		document.querySelector( '.woodev-modal__close' ).click();

		expect( panels.setSelectionBusy ).toHaveBeenLastCalledWith( false );
	} );

	it( 'releases the card lock when the session is destroyed mid-confirmation', () => {
		const { emitSelect, panels } = openPicker( {} );

		emitSelect( { id: 'P1' } );

		expect( panels.setSelectionBusy ).toHaveBeenLastCalledWith( true );

		// A fresh trigger click destroys this session. `setSelectionBusy()` does not track why it
		// was called and never self-balances (see its own docblock) — an unpaired `true` locks
		// every card the instance opens afterwards, and nothing else would ever pair it.
		clickTrigger();

		expect( panels.setSelectionBusy ).toHaveBeenLastCalledWith( false );
	} );

	it( 'releases the lock a checkout refresh was holding when the session is destroyed', async () => {
		const { emitSelect, resolveSelect, panels } = openPicker( {
			selection: { close: false, refreshCheckout: true },
		} );

		emitSelect( { id: 'P1' } );
		await resolveSelect( { allowed: true, reason: null, close: null, refresh_checkout: null } );

		expect( panels.setSelectionBusy ).toHaveBeenLastCalledWith( true );

		clickTrigger();

		expect( panels.setSelectionBusy ).toHaveBeenLastCalledWith( false );
	} );
} );

// -------------------------------------------------------------------------
// A checkout refresh that never answers (adversarial review, finding 3)
//
// `refreshCheckout()` locks the card and waits for WooCommerce's `updated_checkout`. That event
// is not guaranteed: the checkout ajax can fail, be aborted by a newer one, or be answered by a
// build that never fires it. With the `one()` waiter as the ONLY release, the CTA stays dead for
// the rest of the session and the waiter stays bound to `document.body` holding the whole panels
// graph alive. The timeout below is a bounded last resort, not a UX timer.
// -------------------------------------------------------------------------

describe( 'a checkout refresh that never answers', () => {
	it( 'unlocks the card and unbinds the waiter when `updated_checkout` never fires', async () => {
		const { emitSelect, resolveSelect, panels, jq } = openPicker( {
			selection: { close: false, refreshCheckout: true },
		} );

		emitSelect( { id: 'P1' } );
		await resolveSelect( { allowed: true, reason: null, close: null, refresh_checkout: null } );

		expect( jq.triggered ).toContain( 'update_checkout' );
		expect( panels.setSelectionBusy ).toHaveBeenLastCalledWith( true );

		// Still held right up to the bound — the timeout must not cut a refresh that is merely
		// slow, only one that is never going to answer.
		jest.advanceTimersByTime( 9999 );

		expect( panels.setSelectionBusy ).toHaveBeenLastCalledWith( true );
		expect( jq.off ).toHaveLength( 0 );

		jest.advanceTimersByTime( 1 );

		expect( panels.setSelectionBusy ).toHaveBeenLastCalledWith( false );
		expect( jq.off ).toHaveLength( 1 );
		expect( jq.off[ 0 ].handler ).toBe( jq.one[ 0 ].handler );
	} );

	it( 'never fires the timeout release once `updated_checkout` has answered', async () => {
		const { emitSelect, resolveSelect, panels, jq } = openPicker( {
			selection: { close: false, refreshCheckout: true },
		} );

		emitSelect( { id: 'P1' } );
		await resolveSelect( { allowed: true, reason: null, close: null, refresh_checkout: null } );

		jq.one[ 0 ].handler();

		expect( panels.setSelectionBusy ).toHaveBeenLastCalledWith( false );

		panels.setSelectionBusy.mockClear();
		jest.advanceTimersByTime( 60000 );

		// A stale timer firing against a card the customer has since re-locked (a second
		// confirmation) would unlock it under a live request — the same hole finding 2 is about.
		expect( panels.setSelectionBusy ).not.toHaveBeenCalled();
	} );
} );

// -------------------------------------------------------------------------
// Synchronous listeners on the two confirmation events (adversarial review, finding 5)
//
// `fireDocumentEvent()` is `dispatchEvent()`, which runs every listener INLINE before it returns
// — a listener is free to dismiss the dialog or tear the session down in the middle of the
// function that fired the event. Both events are observational (D-2: no waiting, no veto), so
// nothing here lets a listener change the outcome; what it must not do is leave the guard
// describing a picker that no longer exists.
// -------------------------------------------------------------------------

describe( 'a synchronous listener on the confirmation events', () => {
	it( 'still has its answer discarded when a `_requested` listener dismisses the dialog', async () => {
		const { emitSelect, resolveSelect, dataSource, panels, field } = openPicker( {} );
		const dismiss = () => document.querySelector( '.woodev-modal__close' ).click();

		document.body.addEventListener( 'woodev_pickup_point_select_requested', dismiss );
		emitSelect( { id: 'P1' } );
		document.body.removeEventListener( 'woodev_pickup_point_select_requested', dismiss );

		// The request still leaves — `_requested` is observational and grants no veto (the veto
		// path is `woodev_modal_before_close`). It is the ANSWER that must be thrown away.
		expect( dataSource.selectPoint ).toHaveBeenCalledTimes( 1 );

		await resolveSelect( { allowed: true, reason: null, close: null, refresh_checkout: null } );

		expect( field.value ).toBe( '' );
		expect( panels.setPointVerdict ).not.toHaveBeenCalled();
	} );

	it( 'applies nothing when a `_resolved` listener dismisses the dialog', async () => {
		const { emitSelect, resolveSelect, panels, field, jq } = openPicker( {
			selection: { close: false, refreshCheckout: true },
		} );
		const dismiss = () => document.querySelector( '.woodev-modal__close' ).click();

		document.body.addEventListener( 'woodev_pickup_point_select_resolved', dismiss );

		emitSelect( { id: 'P1' } );
		await resolveSelect( { allowed: true, reason: null, close: null, refresh_checkout: null } );

		document.body.removeEventListener( 'woodev_pickup_point_select_resolved', dismiss );

		// The guard is re-run AFTER the event, not only before it: a dialog the customer is no
		// longer looking at gets neither the field write nor the checkout refresh behind it.
		expect( field.value ).toBe( '' );
		expect( panels.lastSelectedId ).toBeUndefined();
		expect( jq.triggered ).not.toContain( 'update_checkout' );
	} );

	it( 'applies nothing when a `_resolved` listener destroys the session', async () => {
		const { emitSelect, resolveSelect, field, jq } = openPicker( {
			selection: { close: false, refreshCheckout: true },
		} );
		const tearDown = () => getSession( FIELD_ID ).destroy();

		document.body.addEventListener( 'woodev_pickup_point_select_resolved', tearDown );

		emitSelect( { id: 'P1' } );
		await resolveSelect( { allowed: true, reason: null, close: null, refresh_checkout: null } );

		document.body.removeEventListener( 'woodev_pickup_point_select_resolved', tearDown );

		expect( field.value ).toBe( '' );
		expect( jq.triggered ).not.toContain( 'update_checkout' );
	} );
} );

// -------------------------------------------------------------------------
// Task 12 (spec D-15): restoring a previously chosen point when the map reopens
// -------------------------------------------------------------------------

describe( 'restoring a previous selection', () => {
	it( 'opens the map AT the point, opens that point\'s CARD and marks it selected', async () => {
		const { panels, provider, drawPoints, field } = openPicker( {} );
		field.value = 'P2';

		const g1 = group( 1, 2, [ 'P1' ] );
		const g2 = group( 3, 4, [ 'P2' ] );

		await drawPoints( [ g1, g2 ] );

		// This file's own StubPanels/StubProvider convention (see their constructors above):
		// `setSelectedId`/`openCard` record onto plain properties, not `jest.fn()`s — only the
		// Task 11 confirmation calls are per-instance mocks. `toHaveBeenCalledWith` therefore
		// does not apply here; the plan's own snippet used it, but that snippet's `group()`/
		// `point()` shapes were imaginary too (discrepancy (b)) — asserted the idiomatic way
		// instead.
		expect( panels.lastSelectedId ).toBe( 'P2' );

		// Operator decision, 06.08.2026 (supersedes spec §5.3's `openList()`): the reopened picker
		// shows the chosen point's DETAILS and its «Продолжить оформление» CTA, not the sidebar
		// list. The card is opened on the STORED id, never on the group's first point — a
		// co-located group can hold several and only one of them is the customer's.
		// `lastOpenCard.group` is the mount's OWN regrouped object (`geo.groupByPosition()` adds
		// `lat`/`lng`/`size`/`typeCode`), not the `group()` helper's raw literal — compared by key
		// rather than by `toEqual( g2 )`, which is the shape mismatch this assertion first tripped on.
		expect( panels.openListCalls ).toBeUndefined();
		expect( panels.lastOpenCard.group.key ).toBe( g2.key );
		expect( panels.lastOpenCard.pointId ).toBe( 'P2' );
		expect( panels.lastOpenCard.origin ).toBe( 'restore' );

		// `setSelectedId()` BEFORE the card: `renderCard()` reads `_selectedId` to pick the CTA
		// label, so the reverse order would render «Выбрать» on the very card that exists to say
		// «Продолжить оформление».
		expect( panels.selectedIdWhenCardOpened ).toBe( 'P2' );

		// s52: the camera and the marker's active state are `setPoints()`'s job on this pass, not
		// a `focusGroup()` call made after the draw — that order left the restored marker's
		// overlay parked off screen. See map-provider-yandex.js's setPoints() docblock.
		//
		// This is ALSO the regression guard for the 06.08.2026 change: the stub emits the real
		// `cardOpened` event from `openCard()`, and the mount's listener answers every OTHER
		// origin with `provider.focusGroup()`. Without the `'restore'` early return the card-open
		// would issue a SECOND camera move here and this assertion would fail.
		expect( provider.setPointsOptions ).toEqual( [ { focus: g2.key } ] );
		expect( provider.focusGroupCalls ).toHaveLength( 0 );
	} );

	it( 'reserves the sidebar\'s map margin BEFORE the focus move, not after it', async () => {
		const { provider, drawPoints, field } = openPicker( {} );
		field.value = 'P2';

		const g2 = group( 3, 4, [ 'P2' ] );

		await drawPoints( [ group( 1, 2, [ 'P1' ] ), g2 ] );

		// The restore pass makes exactly ONE camera move, and it is `setPoints()`'s own
		// `useMapMargin: true` focus move (see `map-provider-yandex.js`). That option can only
		// read a reservation that ALREADY EXISTS, and the thing that makes the reservation is the
		// card opening (`openCard()` → `setStageOpen()` → `listToggle` → `setMargin()`). With the
		// panels half running after `setPoints()` — as it did until 06.08.2026 — the move went out
		// 8ms ahead of the `addArea()` call (rig-measured) and the restored point centred on the
		// map's geometric middle: x=640 on a 1024px map, instead of x=480, the midpoint of the
		// strip still visible beside the 320px panel. Both numbers were measured on the rig before
		// and after this ordering; jsdom can see neither, which is exactly why the assertion is on
		// the ORDER rather than on any pixel.
		//
		// Asserted through the margin state captured AT the `setPoints()` call rather than by
		// comparing two call counts: `{ open: false, width: 0 }` here would mean the mount had
		// only ever made the init-time reservation, which is the regression.
		expect( provider.marginAtSetPoints ).toEqual( [ { open: true, width: 320 } ] );

		// …and it is still ONE move, from `setPoints()` alone. Reserving the margin earlier must
		// not have been paid for with a second, post-open `focusGroup()` — that is the s52 race
		// that parks the restored marker's overlay off screen.
		expect( provider.setPointsOptions ).toEqual( [ { focus: g2.key } ] );
		expect( provider.focusGroupCalls ).toHaveLength( 0 );
	} );

	it( 'reserves nothing ahead of an ordinary, non-restoring draw — the map still fits normally', async () => {
		const { provider, drawPoints, field } = openPicker( {} );
		field.value = '';

		await drawPoints( [ group( 1, 2, [ 'P1' ] ) ] );

		// The mirror of the test above: with nothing to restore there is no card, so the sidebar
		// stays shut and the only reservation in force is the closed one made at init. A change
		// that reserved the panel's width unconditionally would fit the bulk camera to a strip of
		// map the customer has no panel covering.
		expect( provider.marginAtSetPoints ).toEqual( [ { open: false, width: 0 } ] );
	} );

	it( 'leaves an unrelated in-flight confirmation alone — the restore card is about the point '
		+ 'that confirmation is already about', async () => {
		// The `cardOpened` listener also drops a pending confirmation whose point the card has
		// moved off (spec D-9). On the restore path there is nothing in flight, so it is a no-op —
		// verified here rather than assumed, since the guard reads shared session state.
		const { panels, drawPoints, field } = openPicker( {} );
		field.value = 'P2';

		await drawPoints( [ group( 1, 2, [ 'P1' ] ), group( 3, 4, [ 'P2' ] ) ] );

		// `setSelectionBusy( false )` is what `invalidateSelection()` would have emitted through
		// `releaseSelectionBusy()`. Nothing armed a token, so nothing releases one.
		expect( panels.setSelectionBusy ).not.toHaveBeenCalled();
	} );

	it( 'opens normally and silently when the selected point is gone', async () => {
		const { panels, provider, drawPoints, field } = openPicker( {} );
		field.value = 'GONE';

		await drawPoints( [ group( 1, 2, [ 'P1' ] ) ] );

		// `toHaveLength`, not `toEqual( [] )` — `toEqual` ignores `undefined` array items (a
		// documented jest quirk: `expect( [ undefined ] ).toEqual( [] )` PASSES), so it would
		// not have caught a regression that called `focusGroup( undefined, … )` for a vanished
		// point instead of skipping the call outright. Caught by this task's own deliberate
		// regression check.
		expect( provider.focusGroupCalls ).toHaveLength( 0 );
		// D-15's "opens in its ordinary default view" means the SIDEBAR stays shut too, not
		// only the camera — every `fetchAndSetPoints()` call site chains `.catch( () => {} )`,
		// so a thrown exception inside `restoreSelection()` (e.g. a `!group` guard removed,
		// then `group.key` accessed on `null`) is silently swallowed rather than failing loudly;
		// `lastOpenCard` is the assertion that actually catches that shape of regression, since
		// `openCard()` would already have run before such a throw. (Before 06.08.2026 this
		// watched `openListCalls`; the restore opens the card now, so the tripwire moved with it.)
		expect( panels.lastOpenCard ).toBeUndefined();
		expect( panels.openListCalls ).toBeUndefined();
		expect( panels.showMessageCalls ).toBeUndefined();
		// A stale field is left alone here — spec D-15 hands the judgement to the
		// checkout-processing backstop, not to this restore. `toBe()` takes exactly one
		// argument; jest silently ignores a second, so the reasoning above lives in this
		// comment rather than a (no-op) assertion message.
		expect( field.value ).toBe( 'GONE' );
	} );

	it( 'does nothing at all when no point was ever selected', async () => {
		const { provider, drawPoints, field } = openPicker( {} );
		field.value = '';

		await drawPoints( [ group( 1, 2, [ 'P1' ] ) ] );

		expect( provider.focusGroupCalls ).toEqual( [] );
	} );

	it( 'asks for no opening focus when there is nothing to restore — the map fits normally', async () => {
		const { provider, drawPoints, field } = openPicker( {} );
		field.value = '';

		await drawPoints( [ group( 1, 2, [ 'P1' ] ) ] );

		expect( provider.setPointsOptions ).toEqual( [ null ] );
	} );

	it( 'asks for no opening focus when the stored point is not among the drawn groups — the map '
		+ 'has to open SOMEWHERE', async () => {
		const { provider, drawPoints, field } = openPicker( {} );
		field.value = 'GONE';

		await drawPoints( [ group( 1, 2, [ 'P1' ] ) ] );

		expect( provider.setPointsOptions ).toEqual( [ null ] );
	} );

	it( 'asks for no opening focus on any LATER fetch — the restore is one-shot, so a pan or a '
		+ 'type-filter refetch must still fit normally', async () => {
		const { provider, drawPoints, field } = openPicker( { strategy: 'viewport' } );
		field.value = 'P2';

		const g2 = group( 3, 4, [ 'P2' ] );

		await drawPoints( [ group( 1, 2, [ 'P1' ] ), g2 ] );
		await drawPoints( [ g2 ] );

		expect( provider.setPointsOptions ).toEqual( [ { focus: g2.key }, null ] );
	} );

	it( 'only attempts the restore ONCE per session — a later fetch never re-triggers it '
		+ '(discrepancy (a): the plan\'s call site would otherwise fire on every pan/refetch)', async () => {
		const { panels, provider, drawPoints, field } = openPicker( { strategy: 'viewport' } );
		field.value = 'P2';

		const g2 = group( 3, 4, [ 'P2' ] );

		// Under `viewport` nothing fetches until a bbox is reported — `drawPoints()` drives that
		// via `boundsChange` (see its own docblock), the same event a real pan fires.
		await drawPoints( [ group( 1, 2, [ 'P1' ] ), g2 ] );

		expect( provider.setPointsOptions ).toEqual( [ { focus: g2.key } ] );
		expect( panels.openCardCalls ).toBe( 1 );

		// A SECOND fetch that draws the SAME previously-selected point again — a real pan back
		// onto it, a type-filter refetch — must not re-open the card, nor open the map at that
		// point again (which would yank the camera back off wherever the customer panned to).
		// Nothing here retracts the restore; it just must not fire a second time.
		await drawPoints( [ g2 ] );

		expect( provider.setPointsOptions ).toEqual( [ { focus: g2.key }, null ] );
		expect( panels.openCardCalls ).toBe( 1 );
	} );
} );
