/**
 * Woodev Yandex Map Provider — draws the map canvas ONLY: camera, markers, clustering.
 *
 * SP-5 presentation rework (D-3, D-4, D-5, D-7, D-11): the operator rejected the previous
 * version of this file, which owned a ymaps balloon, a viewport-synced drawer control and a
 * type-filter control — point information belongs in a side panel the framework itself
 * renders, not markup owned by one specific map library. Everything that used to live here —
 * the point card, the co-located tab bar, the search results, the type filter menu — now
 * lives in `pickup-panels.js`. This file's whole job is: build a ymaps map, put a marker per
 * GROUP on it via `ymaps.ObjectManager`, and move the camera. It never renders a point field.
 *
 * A "group" is `{ key, lat, lng, typeCode, size, points: [...] }` — several co-located points
 * (a PVZ and a postamat in the same building) folded into ONE marker by
 * `pickup-geo.js`'s `groupByPosition()`. This file receives GROUPS, never raw points, and
 * knows only each group's SIZE (for the count badge) — never a point's name, address or any
 * other display field.
 *
 * THIS FILE NO LONGER FETCHES ANYTHING ITSELF. The old version pulled points through an
 * injected `dataSource` (`fetchPoints`/`fetchDetails`) and decided when to call it. That is
 * now the caller's job (Task 20's mount wiring): this provider only reports WHERE the camera
 * is — `boundsChange( bbox )` under `strategy: 'viewport'`, once right after the initial
 * viewport resolves and again on every `boundschange` — and the caller decides when/how to
 * fetch and hands the result back through `setPoints( groups )`. Under `strategy: 'bulk'` no
 * bbox is ever emitted; the caller fetches once by `config.locality` on its own schedule, same
 * as before. BOTH strategies still register a `boundschange` listener, though: the panel's
 * "points currently in the viewport" list (`visibleChange`) has to track a camera the customer
 * is free to pan/zoom themselves under `bulk` too, even though nothing needs re-fetching there.
 *
 * PUBLIC SURFACE: `init( container, config )`, `setPoints( groups )`, `focusGroup( key, options )`,
 * `setTypeFilter( codes )`, `setMargin( open, width )`, `getFocusedKey()`, `getCenter()`,
 * `matchLoadedPoints( query )`, `suggestAddresses( query )`, `resolveAddress( displayName )`,
 * `focusAddress( latLng, label )`, `clearAddress()`, `on( event, cb )`, `destroy()`. Events out:
 * `pointClick( key )`, `clusterClick( { coords } )`, `boundsChange( bbox )`, `bboxTooWide()`,
 * `visibleChange( keys )`, `zoomChange( { canZoomIn, canZoomOut } )`,
 * `nothingNearby( { key, distanceMeters, name } )`,
 * `searchResults( { points, addresses } )`, `searchCleared()`,
 * `addressFocused( { latLng, label } )`, `addressMatchedPoint( { key } )`,
 * `error( { code, message } )`. `bbox` is the flat `[lat1,lng1,lat2,lng2]` shape
 * `pickup-datasource.js`'s `serializePointsQuery()` expects (see {@see flattenBounds}).
 *
 * CONFIG is FLAT — the merge `pickup-mount.js`'s `buildProviderConfig()` builds from
 * `mapConfig` (`scriptUrl`, `ns`, `hasApiKey`, `lang`, `layers`, `copyrights`) plus
 * `strategy`/`locality`/`i18n`, and (Task 20) the plugin-level `defaultLocation`
 * (`{ center: [lat,lng], zoom }`, ALWAYS present — a required plugin argument),
 * `pointIcons` (`{ typeCode: { default, active } }`, `active` always filled — cascade
 * tier 2, see the "ICONS ARE AN HTML LAYOUT" section below for tier 1, a POINT's own
 * `icons`),
 * `accentColor`, and
 * `searchLayoutEl` (Task 12, spec V-6 — a DETACHED `HTMLElement` built by
 * `pickup-panels.js`'s `buildSearchLayout()`, or `null` when the plugin disabled search; see
 * {@see _buildSearchControl}). This file reads all of these at the top level of `config` —
 * never nested.
 *
 * TWO LESSONS THIS FILE HAS ALREADY TAUGHT (s46 — a browser found both, green tests did not):
 *
 * 1. YMAPS CAMERA MOVES ARE ASYNCHRONOUS. `map.setBounds()` animates and resolves once the
 *    move COMPLETES, not when it starts. Reading `map.getBounds()` right after calling
 *    `setBounds()` without awaiting its promise returns the PRE-move viewport — which once
 *    produced a planet-wide bbox the server's cap correctly refused, reporting "no points" for
 *    a locality that had them. This lesson recurred once already inside THIS rewrite, in
 *    {@see setPoints}'s own `bulk` camera fit: an un-awaited fit let `visibleChange` read the
 *    map's PRE-fit bounds, so a locality whose points sit outside the technical placeholder
 *    viewport reported an empty sidebar over a map that was actually full of pins — see that
 *    method's own comment. Every `setBounds()` call in this file is awaited — and, since the
 *    live-review pan/zoom split ({@see focusGroup}), so is every `setCenter()`/`panTo()` call:
 *    the SAME promise-not-dropped discipline applies to all three, ymaps gives none of them a
 *    synchronous completion signal. EVERY camera move in this file is also explicitly animated
 *    (`duration` set — 400ms for a fit that may travel far, 200ms for a short focus/zoom hop),
 *    matching both references: an instant cut reads as "did the map even move?", which is
 *    exactly the operator's own live-review complaint. Two moves are also not guaranteed to
 *    resolve in call order (animation duration depends on distance, and now every move actually
 *    animates instead of jumping, which makes out-of-order resolution MORE likely, not less), so
 *    concurrent camera commands need sequencing — see {@see focusGroup}'s `_focusSeq` and (Task
 *    12/19's own address flow, live-review round 4) {@see resolveAddress}'s `_addressSeq`: a
 *    customer who edits and re-submits a search before the FIRST `ymaps.geocode()` round-trip
 *    resolves must never have the STALE resolution win the camera — see that method's own
 *    docblock. `setBounds()` is asynchronous in one FURTHER way this lesson originally missed
 *    (s52): it also ISSUES its camera command late, delegating to `map.setCenter()` only once it
 *    has resolved the bounds against the projection — ~50 ms after the call, as measured on the
 *    rig. So "I called `setBounds()` first, then `setCenter()`" does NOT mean the `setCenter()`
 *    wins: the fit starts last and overwrites it. Sequencing is therefore not only about which
 *    promise RESOLVES first — see {@see focusGroup}'s `_cameraFit` gate.
 * 2. A PLACEMARK FOLDED INTO A CLUSTER HAS NO BALLOON OF ITS OWN, and whether a group is
 *    clustered depends on the current zoom, so the same group can work at one zoom and break
 *    at another. This file owns no balloon any more, so that specific crash is gone — but the
 *    DEGENERATE case survives: two groups sitting on IDENTICAL coordinates share one
 *    pixel-grid cell at EVERY zoom level, so `checkZoomRange` can never separate them and
 *    zooming is futile. {@see focusGroup} carries the guard for this (the "Russian Post"
 *    guard — spec §7.5): before collapsing the camera to a cluster's location, check whether
 *    every feature folded into it shares one coordinate, and skip the move entirely when it
 *    does. The check runs again AFTER the move resolves, since ymaps' own report of what is
 *    clustered can change once the camera settles.
 *
 * ICONS ARE AN HTML LAYOUT, NOT `iconLayout: 'default#image'` (D-5): a plain image marker
 * cannot show the group-count badge or express focused/unfocused state as a CSS class, so
 * every feature gets a custom `templateLayoutFactory` layout ({@see buildMarkerLayoutClass}).
 * ymaps cannot read pixel sizes from CSS, so `iconImageSize`/`iconImageOffset` are passed
 * alongside for `getShape()`/auto-pan to read — {@see ICON_BOX} for the resting state,
 * {@see ICON_BOX_ACTIVE} for the group {@see focusGroup} last focused. THOSE TWO OPTIONS ARE NOT
 * WHAT MAKES A CUSTOM LAYOUT CLICKABLE, THOUGH (spec V-9) — a custom `templateLayoutFactory`
 * layout takes its hit area from `iconShape` alone, computed from the same box by
 * {@see iconShapeFor}; without it clicks fall through to the map's own POI layer (gotcha
 * `ymaps-html-icon-layout-needs-iconshape.md`). If either box ever disagrees with the CSS
 * (`pickup.css`), clicks land in the wrong place — the CSS must use the SAME pixel values. A
 * group whose `typeCode` has no entry in `config.pointIcons` still gets a
 * marker — {@see WoodevYandexMapProvider#_renderMarker} draws the framework's own inline SVG pin
 * (spec V-9; {@see PIN_DEFAULT}/{@see PIN_ACTIVE}) instead of leaving an empty/broken `<img>`; it
 * is never invisible or unclickable. D-5's full
 * contract is up to FOUR urls per type (default/active × the plugin's own choice of which it
 * actually supplies) — {@see focusGroup} writes `data-state="active"|"resting"` onto the
 * marker root AND swaps in `pointIcons[type].active` for the image, so a plugin that supplies
 * distinct images (the Yandex reference) gets a real image swap, and a plugin that supplies
 * only `default` (CDEK's own approach — `active` mirrors `default` server-side, see
 * `Pickup_Handler::normalized_point_icons()`) gets the SAME image drawn larger, both driven by
 * the one `data-state` attribute Task 21's CSS keys off.
 *
 * ICON RESOLUTION IS A THREE-TIER CASCADE (issue #193): a POINT's own icon
 * ({@see pointOwnIcons}, `group.points[n].icons`) beats `config.pointIcons[ typeCode ]`
 * (the tier described above), which beats the framework's default pin. This exists because
 * a type CODE is not always enough to tell branded networks apart — Yandex.Delivery mixes
 * points from different operators in one response under the SAME type code (a live,
 * 812-point Moscow sample measured 679 `5post` + 129 `market_l4g` points, both reporting
 * `type: "pickup_point"`), so resolving by type code alone draws them identically even
 * though they are visually distinct networks. {@see WoodevYandexMapProvider#_buildProperties}
 * resolves both tiers with `??`, never `||` — see {@see pointOwnIcons}'s own docblock for
 * why an empty icon URL at either tier means "keep falling through", the opposite decision
 * from the `close` flag's `??` (PR #192), and why that is still the correct, deliberate
 * choice for a URL rather than an oversight. The per-point tier is resolved from the
 * group's REPRESENTATIVE point (`displayPoints[0]`, the SAME point that decides
 * `typeCode`) — a co-located group (`pickup-geo.js`'s `groupByPosition()`) therefore shows
 * only the representative's own icon, never a blend of its members'; a marker sitting at
 * one coordinate that folds a 5post point together with a Yandex-branded one draws
 * whichever sorts first. This is a consequence of the existing grouping, not a defect this
 * tier introduces. SCOPE: this cascade is the MAP marker's own icon resolution only — the
 * sidebar list row and the point card keep the framework's own glyphs regardless (issue
 * #195/PR #196, `woodev_pickup_map_point_glyphs`); a point's own `icons` never reaches
 * those surfaces.
 *
 * MAP MARGINS ARE TWO SEPARATE, NEVER-CONFUSED RESERVATIONS, both via ymaps' native
 * `map.margin.addArea()` — whose RETURNED ACCESSOR is what removes it again, there is no
 * `map.margin.removeArea()` — never a plain `map.margin = [...]` assignment, which is not this
 * API's shape (see ADR-010). The STATIC one, {@see _buildMap}'s own top-chrome strip
 * (`{ top: 0, left: 0, width: '100%', height: '64px' }`, kept in `this._topMarginArea`), reserves
 * the space our own search bar occupies — added exactly ONCE, at build time, and released only by
 * `destroy()`, because unlike the sidebar it never changes size or visibility for the life of the
 * map; that is also why it is not exposed as a public method the way `setMargin()` is — nothing
 * outside this file ever needs to vary it. Both references reserve the identical strip:
 * Yandex.Delivery `widget-map.js` — `this.map.margin.addArea({ top: 0, left: 0, width: '100%',
 * height: '64px' })`; the Russian Post bundle —
 * `[{top:0,left:0,width:"100%",height:"64px"}].forEach(t => d.margin.addArea(t))`. Without it, a
 * camera fit ({@see focusGroup}, {@see focusAddress}, the initial-viewport/bulk fits) is free to
 * frame a point directly underneath the search bar, which ymaps has no other way of knowing
 * occupies screen space. The DYNAMIC one, {@see setMargin}'s own sidebar strip
 * (`{ right: 0, top: 0, width: <width>, height: '100%' }`, kept SEPARATELY in `this._marginArea`),
 * toggles on and off with the panels' own list open/closed state — Task 20's mount calls it from
 * the panels' `listToggle` event. `right: 0` anchors it to the right edge; `width` is what gives
 * it SIZE — {@see setMargin}'s own docblock records the incident where this shape was gotten
 * wrong (`right: <width>` with no `width` key at all, reserving zero pixels; see gotcha
 * `ymaps-margin-area-needs-explicit-width.md`). The two fields are NEVER touched by each other's
 * code path: `setMargin()` removes and re-adds only `this._marginArea` on every toggle, and must
 * never remove the top strip.
 *
 * ADDRESS SEARCH (Task 12/19, D-6, spec V-6/V-7; live-review round 4, Finding A): the customer
 * types THEIR OWN address — the search box's placeholder is literally "Ваш адрес" — not a
 * pickup-point search term. `ymaps.control.SearchControl` keeps its ENGINE (`search()`,
 * `getResultsCount()`, `showResult()`) and loses its CHROME — its default view is replaced by the
 * framework's OWN layout ({@see _buildSearchControl}), built by `pickup-panels.js`'s
 * `buildSearchLayout()` and handed to this file as `config.searchLayoutEl` (a plain DOM element,
 * never a reference to the panels instance itself — see the next paragraph). Before anything has
 * ever loaded, {@see _loadedBounds} returns null and every bound below is simply omitted — there
 * is nothing yet to bound the search to, not a degenerate box.
 *
 * ONE ENGINE NOW (issue #180 — an earlier round replaced `ymaps.suggest()` with `ymaps.geocode()`
 * ENTIRELY, citing the Russian Post bundle; the operator called this out directly: "механизм
 * поиска ты опять выдумал, хотя в эталоне «Яндекс доставка» работает именно так как должно" —
 * typing "Чертановская 66к1" geocoded to an English-language, full-postal-form TRANSIT STATION
 * instead of the short "Чертановская улица, 66к1" the reference returns, because
 * `ymaps.geocode()` ranks POIs (transit stops, landmarks) alongside addresses, while
 * `ymaps.suggest()` — the reference's OWN data source for exactly this moment, via its native
 * `noSuggestPanel: false` widget — is address-shaped and address-ranked by design):
 *
 * - {@see suggestAddresses} — powers the TYPING dropdown via `ymaps.suggest()`, bounded to the
 *   loaded point area (see {@see _loadedBounds}, spec V-7, and that method's own docblock for WHY
 *   it is bounded — a Moscow buyer typing a Moscow street name must never be offered a same-named
 *   street in Tolyatti; gotcha `ymaps-control-options-must-be-nested.md`). This is what the
 *   reference gets automatically, for free, from ymaps' own native suggest widget — since this
 *   file replaces the control's ENTIRE chrome with the framework's own layout (D-3), there is no
 *   native widget left to do it for us, so this method reproduces it explicitly.
 *   `pickup-panels.js`'s own layout emits the debounced `searchType` event on every keystroke for
 *   exactly this; wiring it to this method is Task 20's mount's job — which RETURNS
 *   `{ points, addresses }` rather than emitting `searchResults` (round 4, second follow-up):
 *   `searchResults` is the COMPLETED-SEARCH event, wired to the renderer that prints "no results
 *   found", and this method runs on the TYPING path — emitting it here would drive that verdict
 *   mid-keystroke, reopening the operator's own round-3 defect. The mount calls
 *   `provider.suggestAddresses( query ).then( r => panels.previewSearchResults( r ) )` on every
 *   keystroke AND, again, once per deliberate `searchSubmit` (Enter/the magnifier) — #179 made the
 *   submit handler simply re-ask THIS SAME method for what is already on screen and resolve its
 *   top hit via {@see resolveAddress}, rather than running a second, differently-ranked search of
 *   its own (`pickup-mount.js`'s own `searchSubmit` handler carries that history; gotcha
 *   `ymaps-suggest-not-geocode-for-address-lists`). `SearchControl` itself is, as of #180, purely
 *   a DOM HOST for `config.searchLayoutEl` ({@see _buildSearchControl}) — its OWN
 *   `search()`/`getResultsCount()`/`showResult()` engine is never configured with a geocode
 *   provider and is never called by this codebase either: #179 removed `control.search()`'s only
 *   caller, which made `_searchGeocodeProvider()` — the geocode provider that used to back that
 *   engine — unreachable, and #180 deleted it.
 *
 * `ymaps.geocode()` is called from {@see resolveAddress} alone, exactly once per picked
 * suggestion — matching the reference's own model precisely: `suggest()` powers the LIST (cheap,
 * local-feeling, no meaningful quota cost), `geocode()` resolves ONLY the one thing the customer
 * actually picked, never the whole list at once.
 *
 * THIS FILE DOES NOT RENDER THE RESULTS OR KNOW THE PANELS EXIST (D-3: no map-library file
 * renders point information, and this file never holds a reference to the panels object) —
 * `config.searchLayoutEl` is as far as that goes: a bare element this file mounts into an inert
 * `templateLayoutFactory` wrapper and otherwise never touches. `{ points, addresses }` never
 * reaches the `SearchControl`'s OWN engine at all (as of #180 there is no geocode provider left to
 * feed it — see the "ONE ENGINE NOW" paragraph above — and `search()`/`getResultsCount()`/
 * `showResult()` are never called by this codebase either way; see the next paragraph for why a
 * matched POINT was never mixed into that engine even before). The typing/submit preview reaches
 * the panels as {@see suggestAddresses}'s own RETURN value instead — Task 20's mount calls
 * `provider.suggestAddresses( query ).then( r => panels.previewSearchResults( r ) )`, as the "ONE
 * ENGINE NOW" paragraph above describes. The ONLY thing this file still emits as a `searchResults`
 * EVENT is {@see resolveAddress}'s own two FAILURE states — a geocode that resolves to nothing
 * usable, or one that is rejected outright — carrying an empty `{ points: [], addresses: [] }`;
 * Task 20's mount wires `provider.on( 'searchResults', panels.renderSearchResults )` for exactly
 * that, so a narrowed-down query that resolves to nothing does not leave the PREVIOUS (now stale)
 * results on screen. The SUCCESS path never emits it — {@see resolveAddress} calls
 * {@see focusAddress} instead — because `searchResults` is the COMPLETED-SEARCH verdict event and
 * a resolved address is not a "no results" verdict.
 *
 * WHY A MATCHED POINT WAS NEVER MIXED INTO THE CONTROL'S OWN GEO-OBJECT COLLECTION (historical —
 * the collection itself no longer exists after #180, but the reasoning is why it never grew a
 * point-carrying variant in the first place): an earlier draft of this design (spec V-6) proposed
 * wrapping each matched point in a synthetic `ymaps.Placemark`, tagged, alongside the real
 * geocoded addresses, so the control's own `showResult()` could resolve either kind. That was
 * never actually needed: `pickup-panels.js`'s search-result rows already attach their OWN click
 * handlers (`searchPointPicked`/`searchAddressPicked`), which Task 20's mount wires directly to
 * `panels.openCard()`/`provider.resolveAddress()` — `control.showResult()` is never called by this
 * codebase at all. Mixing a synthetic Placemark into a collection the control expected to hold
 * real geocode results would only have added untested risk (its internal book-keeping is not
 * documented for a foreign object type) for zero behavioural benefit.
 *
 * Picking a POINT result opens its card (Task 20's job, via the panels' own `searchPointPicked`).
 * Picking an ADDRESS result resolves through {@see focusAddress}, which draws NO pin of any kind
 * (live-review fix, D4 — an earlier version dropped a bare, unstyled `ymaps.Placemark`; both
 * references never draw one either, `noPlacemark: true` in {@see _buildSearchControl}) and takes
 * one of two paths depending on what the resolved coordinate actually lands on:
 *
 * - Within {@see SAME_PLACE_THRESHOLD_M} of an already-loaded group, the search is treated as
 *   having selected that POINT, not a neighbourhood: `addressMatchedPoint( { key } )` fires and
 *   nothing else does — no `addressFocused`, no camera move, no nearest-N fit. The mount (Task
 *   20/T3) wires this straight to `focusGroup( key, { zoom: true } )`, which supplies the actual
 *   camera move, the active marker state, AND the sidebar card open — this file never opens a
 *   card itself (D-3).
 * - Otherwise, `addressFocused( { latLng, label } )` fires and the camera frames the FOUND
 *   OBJECT'S OWN BOUNDS — the geocoder's `boundedBy` for the hit, threaded in by
 *   {@see resolveAddress} ({@see focusAddress}, #167). A house gives a house-sized frame, a
 *   street a street, a city a city: the granularity comes free from what was actually searched
 *   for, and this file owns no zoom number for it at all ({@see ADDRESS_FALLBACK_ZOOM} covers
 *   only a hit with no bounds).
 *
 *   This is the reference's own policy, arrived at by deleting ours: Yandex.Delivery hands search
 *   to ymaps' `SearchControl`, which frames the result itself, and has no notion of keeping
 *   nearby points in shot anywhere in its file. Ours used to fit a box containing the address AND
 *   its N nearest groups, which meant an address 14km from the loaded points produced a ~28km
 *   frame — "half of Moscow, and you still have to find the address" (operator, 07.08.2026). Note
 *   what the reference DOES use its point cloud for: `boundedBy` on the geocode call, i.e. which
 *   candidates to OFFER. Never which frame to land on. That split is the same one the gotcha
 *   `bounding-the-address-resolve-breaks-the-normal-case` records.
 *
 *   When the settled frame turns out to contain no point at all, `nothingNearby` is emitted
 *   naming the nearest group and its distance, so the customer gets an explicit "nothing here"
 *   rather than a silently empty viewport. That test is GEOMETRIC — {@see _groupsInsideBounds}
 *   against the margin-aware viewport, evaluated after the move settles — replacing a 50km
 *   constant that also, wrongly, decided whether to move the camera AT ALL.
 *
 * `objectManager.setFilter()`, NEVER A REBUILD, drives {@see setTypeFilter}: rebuilding the
 * manager would tear down and recreate every feature, losing the camera state this file just
 * fought to keep asynchronously correct and detaching every click binding along with it.
 *
 * `geoObjectOpenBalloonOnClick: false` / `clusterHasBalloon: false` are load-bearing, not
 * decorative: the panels own the point card's DOM now, and ymaps opening a balloon of its own
 * on top of it would fight the framework's own rendering.
 *
 * `pickup-geo.js` is enqueued alongside this file (Task 20 wires the script dependency) and
 * used for its bounds/colour arithmetic ({@see geo}) rather than this file re-implementing it.
 *
 * SCRIPT LOADING IS IDEMPOTENT ACROSS SESSIONS, NOT PER-INSTANCE — see {@see loadYmapsScript},
 * UNCHANGED from the previous version of this file: a retry always constructs a fresh provider
 * instance, so the loading promise is cached in MODULE scope, keyed by `config.ns`.
 *
 * DESTROY IS IDEMPOTENT AND SAFE BEFORE `init()` SETTLES: `_destroyed` is checked at every
 * async continuation this file adds (script load, initial viewport, every `focusGroup()` move)
 * before touching `this.map` — a `destroy()` racing an in-flight `init()`/`focusGroup()` never
 * throws or half-builds a map nobody wants any more.
 *
 * UMD-ish dual export (matches every sibling SP-5 frontend file):
 *   - Browser global: window.WoodevPickupMapProviders.yandex = WoodevYandexMapProvider
 *   - CommonJS:       module.exports = WoodevYandexMapProvider  (for jest)
 *
 * @file
 * @since 2.0.2
 */

( function() {
	'use strict';

	// See pickup-panels.js's own docblock for the identical fallback — this file is required
	// (CommonJS, jest) before `window.WoodevPickupGeo` would exist in a browser, and loaded as a
	// plain <script> (global) in production, so both paths are supported.
	var geo = ( 'undefined' !== typeof window && window.WoodevPickupGeo ) ||
		( 'function' === typeof require ? require( './pickup-geo' ) : null );

	/** @type {number} technical placeholder center, used ONLY when config.defaultLocation is
	 *  somehow absent — the contract guarantees it is always present; see the file docblock. */
	var DEFAULT_CENTER = [ 0, 0 ];

	/** @type {number} technical placeholder zoom paired with {@see DEFAULT_CENTER}. */
	var DEFAULT_ZOOM = 2;

	/** @type {number} minimum zoom the map allows the customer to reach (Task 18, D-7). */
	var MIN_ZOOM = 8;

	/** @type {number} maximum zoom the map allows the customer to reach. */
	var MAX_ZOOM = 18;

	/**
	 * Maximum span, in degrees, either side of the viewport bbox may have before a
	 * `boundschange` is reported as too wide to fetch instead of fetched (Task 18, D-4). The
	 * server enforces the SAME cap — this file mirrors it so the customer sees "zoom in", not a
	 * false "no points here" for a locality that has them but was asked for at planet scale.
	 *
	 * @type {number}
	 */
	var BBOX_CAP_DEGREES = 10;

	/**
	 * Fallback cluster icon colour, used only when `config.accentColor` fails
	 * {@see geo.safeColor}'s validation — matches the reference implementation's own cluster
	 * colour exactly, so an unbranded install still gets a colour that reads as "map", not an
	 * error state.
	 *
	 * @type {string}
	 */
	var CLUSTER_ICON_COLOR_FALLBACK = '#FCE000';

	/**
	 * Marker hit-box for the RESTING state — must match `pickup.css`'s own dimensions for the
	 * `.woodev-pickup-marker` box exactly, or clicks land in the wrong place (see the file
	 * docblock's "ICONS ARE AN HTML LAYOUT" section).
	 *
	 * @type {{size: number[], offset: number[]}}
	 */
	var ICON_BOX = { size: [ 45, 45 ], offset: [ -22, -23 ] };

	/**
	 * Marker hit-box for the FOCUSED state (the group {@see WoodevYandexMapProvider#focusGroup}
	 * most recently focused) — a taller pin shape, matching the reference implementation's own
	 * active-marker treatment.
	 *
	 * @type {{size: number[], offset: number[]}}
	 */
	var ICON_BOX_ACTIVE = { size: [ 50, 70 ], offset: [ -25, -40 ] };

	/**
	 * The clickable region for one feature (spec V-9; see gotcha
	 * `ymaps-html-icon-layout-needs-iconshape.md`). `iconImageSize`/`iconImageOffset` are options
	 * of the `default#image` layout only — this file's custom `templateLayoutFactory` layout
	 * (D-5) does not consume them at all, and takes its hit area from `iconShape` alone. Without
	 * one the overlay has no clickable region whatsoever: clicks pass straight through the
	 * marker onto the map's own POI layer, and Yandex's organisation card opens instead of ours.
	 *
	 * @since 2.0.2
	 * @param {Array<number>} offset `[ x, y ]`, negative — the icon's top-left relative to the anchor.
	 * @param {Array<number>} size   `[ width, height ]`.
	 * @returns {{type: string, coordinates: Array<Array<number>>}}
	 */
	function iconShapeFor( offset, size ) {
		return {
			type: 'Rectangle',
			coordinates: [
				[ offset[ 0 ], offset[ 1 ] ],
				[ offset[ 0 ] + size[ 0 ], offset[ 1 ] + size[ 1 ] ],
			],
		};
	}

	/**
	 * Zoom {@see focusAddress} centres on when a geocoder hit carries no `boundedBy` of its own —
	 * the only case where this file picks a zoom NUMBER rather than framing the found object
	 * (#167). Street level: close enough to be useful, wide enough not to strand the customer in
	 * a courtyard. A degenerate box under `checkZoomRange: true` is deliberately NOT used as the
	 * fallback — that resolves to the deepest zoom the map allows, which for an address is far too
	 * close (it is the right answer for a chosen POINT, which is why {@see focusGroup} uses it).
	 *
	 * @since 2.0.2
	 * @type {number}
	 */
	var ADDRESS_FALLBACK_ZOOM = 16;

	/**
	 * Distance, in metres, within which a resolved search address is treated as having selected
	 * an EXISTING POINT rather than a nearby location (operator requirement, live-review round
	 * 2, verbatim: "если из списка выбран адрес, точно совпадающая с точкой на карте (ПВЗ/
	 * Постамат/Отделение), то фокусируемся только на этой точке и делаем её активной"). Checked
	 * BEFORE any camera move inside {@see focusAddress}; when the nearest loaded group is this
	 * close, the address search stops there — {@see focusAddress}'s own docblock covers what
	 * fires instead.
	 *
	 * 30m, not tighter or looser: a geocoder resolves a street ADDRESS to a building footprint or
	 * entrance, not the exact coordinate a plugin recorded for its own pickup-point counter —
	 * tens of metres of disagreement between "the building" and "the counter inside it" is normal
	 * and expected, so a materially tighter threshold would rarely fire at all. Materially wider
	 * risks matching a genuinely different, adjacent building instead. This is the "same building"
	 * case; its opposite number, "nothing near here at all", is no longer a distance at all but a
	 * geometric fact about the settled frame (#167) — see {@see _groupsInsideBounds}.
	 *
	 * @since 2.0.2
	 * @type {number}
	 */
	var SAME_PLACE_THRESHOLD_M = 30;

	/**
	 * Number of address results requested per call — {@see suggestAddresses}'s `ymaps.suggest()`
	 * call, its only remaining consumer since issue #180 deleted the geocode-on-submit engine that
	 * used to share this constant. `results` is CONFIRMED to work identically to `ymaps.geocode()`'s
	 * own same-named option (live-review round 4 follow-up, rig-measured — see that method's own
	 * docblock).
	 *
	 * @type {number}
	 */
	var SEARCH_RESULT_COUNT = 5;

	/**
	 * Half-height/half-width, in degrees, of the smallest box an address search may be bounded to
	 * (Task 12, spec V-7).
	 *
	 * `strictBounds: true` against a ZERO-AREA box matches nothing, and the box collapses whenever
	 * every loaded point sits on one coordinate — a city served by a single pickup point, or a
	 * pickup point and a postamat sharing a building. The customer could then type their own
	 * address forever and never get a result.
	 *
	 * 0.05° is roughly 5.5 km north-south, and less east-west the further from the equator (~3 km
	 * at Moscow's latitude) — a city-district-sized frame around the one point we know about,
	 * which is the honest answer to "what is near this point" when that point is all there is.
	 *
	 * @type {number}
	 */
	var MIN_SEARCH_BOUNDS_DEGREES = 0.05;

	// -------------------------------------------------------------------------
	// Small pure helpers
	// -------------------------------------------------------------------------

	/**
	 * Flattens a ymaps bounds pair `[[lat1,lng1],[lat2,lng2]]` into the flat
	 * `[lat1,lng1,lat2,lng2]` array `pickup-datasource.js`'s `serializePointsQuery()` expects
	 * for `bounds`, and the same shape `boundsChange` emits.
	 *
	 * @param {Array} bounds `[[Number, Number], [Number, Number]]`.
	 * @returns {Array} `[Number, Number, Number, Number]`.
	 */
	function flattenBounds( bounds ) {
		return [ bounds[ 0 ][ 0 ], bounds[ 0 ][ 1 ], bounds[ 1 ][ 0 ], bounds[ 1 ][ 1 ] ];
	}

	/**
	 * Grows a bounds pair so each side spans at least `2 * minHalfSpan` degrees, keeping its
	 * centre. A box already wider than that is returned untouched.
	 *
	 * Exists for the degenerate case: every loaded point on ONE coordinate collapses the box to
	 * zero area, and a zero-area box under `strictBounds: true` matches nothing (see
	 * {@see MIN_SEARCH_BOUNDS_DEGREES}). Latitude is clamped to the poles; longitude is
	 * deliberately NOT wrapped at ±180 — ymaps accepts out-of-range longitudes in a bounds pair
	 * and normalises them itself, whereas wrapping here would invert the box (min > max) for a
	 * point near the meridian and match everything except the intended area.
	 *
	 * @param {Array}  bounds      `[[Number, Number], [Number, Number]]`.
	 * @param {number} minHalfSpan Half the minimum span, in degrees.
	 * @returns {Array} `[[Number, Number], [Number, Number]]`.
	 */
	function padBounds( bounds, minHalfSpan ) {
		var minLat = bounds[ 0 ][ 0 ];
		var minLng = bounds[ 0 ][ 1 ];
		var maxLat = bounds[ 1 ][ 0 ];
		var maxLng = bounds[ 1 ][ 1 ];
		var latPad = Math.max( 0, minHalfSpan - ( maxLat - minLat ) / 2 );
		var lngPad = Math.max( 0, minHalfSpan - ( maxLng - minLng ) / 2 );

		return [
			[ Math.max( -90, minLat - latPad ), minLng - lngPad ],
			[ Math.min( 90, maxLat + latPad ), maxLng + lngPad ],
		];
	}

	/**
	 * Drops the LEADING comma-separated parts of a `ymaps.suggest()` `value` string, and trims
	 * whitespace, via TWO strategies tried in order (live-review round 4 follow-up — both
	 * rig-measured against a REAL `ymaps.suggest()` call, not assumed).
	 *
	 * STRATEGY 1 (PRIMARY, exact): drop every part up to and including the one matching
	 * `locality` by NAME. `value` for "Чертановская 66к1", requested UNBOUNDED on a Russian-locale
	 * rig, comes back as `"Россия, Москва, Чертановская улица, 66к1"`; matching `locality`
	 * (`"Москва"`) against the parts and keeping everything after it produces the operator's own
	 * target, matching the Yandex.Delivery reference exactly: `"Чертановская улица, 66к1"`.
	 * Deliberately does NOT hard-code "drop the first two parts": a region can sit between the
	 * country and the city (`"Россия, Московская область, Москва, ..."`), and some results carry
	 * no country at all — either would make a fixed-offset drop cut the wrong parts, or the wrong
	 * NUMBER of parts. Matching the locality BY NAME is the only rule that survives both shapes,
	 * and it is exact wherever it applies — always preferred when it finds a match.
	 *
	 * STRATEGY 2 (FALLBACK, heuristic — ONLY when strategy 1 finds nothing): drop
	 * `min( 2, max( 0, parts.length - 2 ) )` leading parts. This exists for exactly the case
	 * strategy 1 cannot solve: `config.locality` and the geocoder's OWN response language
	 * disagree. Rig-measured on THIS project (live-review round 4, second follow-up): with the
	 * rig's WordPress locale forcing `lang=en_US`, `ymaps.suggest()` for the SAME query returned
	 * `"Russian Federation, Moscow, Chertanovskaya Street, 66к1"` — `locality` is the merchant's
	 * own configured `"Москва"`, which never matches `"Moscow"` by string equality, so strategy 1
	 * correctly finds nothing and falls through here. This is not only a locale mismatch, either:
	 * a shop could spell its own city differently than ymaps does even in ONE language, so this
	 * fallback is worth having independent of the `en_US` rig artifact that surfaced it.
	 *
	 * `drops` is DELIBERATELY CLAMPED TO A MAXIMUM OF TWO — NOT "keep the last two parts" (a
	 * different rule that silently discards everything else, and is WRONG the moment a house
	 * number carries a trailing sub-entry). Checked against every shape actually seen or
	 * plausible for this feature:
	 * - `"Russian Federation, Moscow, Chertanovskaya Street, 66к1"` (4 parts) → drop 2 →
	 *   `"Chertanovskaya Street, 66к1"`. Correct.
	 * - `"Russian Federation, Moscow, Chertanovskaya Street, 66к1, entrance 3"` (5 parts) →
	 *   STILL drop only 2 (the `Math.min( 2, … )` clamp) → `"Chertanovskaya Street, 66к1,
	 *   entrance 3"` — the sub-entry survives intact. "Keep the last two parts" would instead
	 *   produce `"66к1, entrance 3"`, silently losing the street name. DO NOT "simplify" this
	 *   into a last-N-parts rule; that is the exact regression this comment exists to prevent.
	 * - `"Москва, Тверская улица, 5"` (3 parts, no country at all) → `max( 0, 3-2 ) = 1` → drop 1
	 *   → `"Тверская улица, 5"`. Correct — clamping to `parts.length - 2` (never negative) is
	 *   what keeps a short, country-less value from being over-trimmed.
	 * - `"Тверская улица, 5"` (2 parts) → `max( 0, 2-2 ) = 0` → untouched. Correct — already as
	 *   short as this heuristic will ever cut.
	 *
	 * Falls back further still to the (whitespace-trimmed, otherwise untouched) full `value` only
	 * when `value` itself is empty — there is nothing left to drop parts FROM at that point.
	 *
	 * @since 2.0.2
	 * @param {string} value    a `ymaps.suggest()` item's own `value` field, comma-separated,
	 *                          broadest-to-narrowest (country → region → city → street → house).
	 * @param {string} locality `config.locality` — the plugin's own configured city/locality name.
	 * @returns {string}
	 */
	function trimAddressValue( value, locality ) {
		var trimmed = 'string' === typeof value ? value.trim() : '';

		if ( ! trimmed ) {
			return trimmed;
		}

		var parts = trimmed.split( ',' ).map( function( part ) {
			return part.trim();
		} );

		if ( locality ) {
			var needle = String( locality ).trim().toLowerCase();

			for ( var i = 0; i < parts.length; i++ ) {
				if ( parts[ i ].toLowerCase() === needle ) {
					// Strategy 1 — exact, preferred whenever it finds a match.
					return parts.slice( i + 1 ).join( ', ' );
				}
			}
		}

		// Strategy 2 — language-independent heuristic fallback. See this function's own docblock
		// for the exact rig measurement that motivated the `Math.min( 2, … )` clamp; do not
		// change this to a "keep the last two parts" rule.
		var drops = Math.min( 2, Math.max( 0, parts.length - 2 ) );

		return parts.slice( drops ).join( ', ' );
	}

	/**
	 * Projects one `ymaps.suggest()` item into `{ displayName, query }` (live-review round 4
	 * follow-up — the coordinator's own rig measurement against a REAL `ymaps.suggest()` call).
	 *
	 * `item.displayName` is REVERSED (house number FIRST — `"66к1, Чертановская улица, Москва,
	 * Россия"`) and carries the full country/locality prefix; `item.value` is broadest-to-
	 * narrowest and geocodable AS-IS (`"Россия, Москва, Чертановская улица, 66к1 "`). BOTH
	 * returned fields are therefore derived from `value`, never from the (differently-ordered)
	 * `displayName`, EXCEPT when `value` is missing entirely — only then does `item.displayName`
	 * stand in for both, since there is nothing else to project.
	 *
	 * `query` is the FULL, untrimmed-by-locality `value` (whitespace trimmed only) — this is what
	 * {@see resolveAddress} must geocode, never the short `displayName`: "Чертановская улица,
	 * 66к1" with no city is exactly the ambiguity `strictBounds` exists to prevent everywhere
	 * else in this file. `displayName` is the SHORT, locality-trimmed form
	 * ({@see trimAddressValue}) — what the customer actually reads in the results list, matching
	 * the reference's own "Чертановская улица, 66к1" exactly.
	 *
	 * @since 2.0.2
	 * @param {Object} item     one `ymaps.suggest()` result item (`{ type, displayName, value, hl }`).
	 * @param {string} locality `config.locality`, passed straight through to {@see trimAddressValue}.
	 * @returns {{displayName: string, query: string}}
	 */
	function projectSuggestion( item, locality ) {
		var value = item && 'string' === typeof item.value ? item.value.trim() : '';

		if ( value ) {
			return { displayName: trimAddressValue( value, locality ), query: value };
		}

		var fallback = item && 'string' === typeof item.displayName ? item.displayName.trim() : '';

		return { displayName: fallback, query: fallback };
	}

	/**
	 * Extracts a usable bounds pair from a `ymaps.geocode()` result's first hit, or null when
	 * the result is empty/malformed — the caller degrades to `config.defaultLocation` either
	 * way, never throws.
	 *
	 * @param {Object} result
	 * @returns {Array|null}
	 */
	function extractGeocodeBounds( result ) {
		var geoObjects = result && result.geoObjects;
		var first = geoObjects && 'function' === typeof geoObjects.get ? geoObjects.get( 0 ) : null;
		var properties = first && first.properties;
		var bounds = properties && 'function' === typeof properties.get ? properties.get( 'boundedBy' ) : null;

		return Array.isArray( bounds ) ? bounds : null;
	}

	/**
	 * Extracts the first hit's own coordinates from a `ymaps.geocode()` result — used by
	 * {@see WoodevYandexMapProvider#resolveAddress} to anchor {@see WoodevYandexMapProvider#focusAddress}'s
	 * same-place check and nearest-N fit, as opposed to {@see extractGeocodeBounds}'s use of the
	 * SAME shape of result for the initial-viewport bounding box. Returns null for an empty/
	 * malformed result; the caller degrades to doing nothing rather than throwing or guessing a
	 * location.
	 *
	 * @param {Object} result
	 * @returns {Array|null} `[lat, lng]`, or null.
	 */
	function extractGeocodeCoordinates( result ) {
		var geoObjects = result && result.geoObjects;
		var first = geoObjects && 'function' === typeof geoObjects.get ? geoObjects.get( 0 ) : null;
		var geometry = first && first.geometry;
		var coordinates = geometry && 'function' === typeof geometry.getCoordinates
			? geometry.getCoordinates()
			: null;

		return Array.isArray( coordinates ) ? coordinates : null;
	}

	/**
	 * The first feature's coordinates in a `getObjectState().cluster` — the point this file
	 * collapses the camera bounds to when un-clustering a group (see {@see focusGroup}). Which
	 * specific member of the cluster anchors the zoom does not matter for un-clustering: a
	 * degenerate (zero-area) bbox with `checkZoomRange: true` forces the deepest zoom the map
	 * allows AT that spot, and once deep enough every genuinely distinct coordinate in the
	 * cluster separates, regardless of which one centred the move.
	 *
	 * @param {Object} cluster
	 * @returns {Array|null} `[lat, lng]`, or null when the cluster carries no features.
	 */
	function clusterAnchorCoordinates( cluster ) {
		var features = ( cluster && cluster.features ) || [];

		return features.length > 0 ? features[ 0 ].geometry.coordinates : null;
	}

	/**
	 * The "Russian Post" guard (spec §7.5): true when EVERY feature folded into `cluster`
	 * shares one coordinate — meaning no camera move, however deep, can ever separate them, so
	 * {@see focusGroup} must not try. False for an empty cluster (nothing to guard against).
	 *
	 * @param {Object} cluster
	 * @returns {boolean}
	 */
	function isSingleCoordinateCluster( cluster ) {
		var features = ( cluster && cluster.features ) || [];

		if ( 0 === features.length ) {
			return false;
		}

		var first = features[ 0 ].geometry.coordinates;

		return features.every( function( feature ) {
			var coords = feature.geometry.coordinates;

			return coords[ 0 ] === first[ 0 ] && coords[ 1 ] === first[ 1 ];
		} );
	}

	/**
	 * One point's own type code — `pickup-panels.js`'s own per-point filter reads it the SAME
	 * way (`point.type.code`), so the two files never diverge on what a point's "type" is.
	 *
	 * @param {Object} point
	 * @returns {string} the code, or `''` when the point carries no usable type info.
	 */
	function pointTypeCode( point ) {
		return ( point && point.type && point.type.code ) || '';
	}

	/**
	 * A single point's own icon override (issue #193 — cascade tier 1: the point's own icon
	 * beats the domain's type-keyed icon, which beats the framework's default pin).
	 * `{ default, active }`, or `null` when the point carries no usable icon of its own.
	 *
	 * `??`, never `||` — the SAME discipline `pickup-mount.js`'s `resolveFlag()` established
	 * for the `close` flag (PR #192), but the DECISION here is the opposite of that flag's:
	 * `resolveFlag()` must preserve an explicit `false` as distinct from "unspoken", because a
	 * boolean's `false` is itself a meaningful state. A URL has no such state — a blank string
	 * can never be rendered as an image, so an explicitly empty `default` carries no more
	 * information than an absent `icons` key, and both fall through to the next cascade tier
	 * identically. `Pickup_Point::sanitize_icons()` already guarantees the server never emits
	 * an `icons` object with an empty `default`; the truthy check below is defensive, for a
	 * point object built directly (a test, or a future caller bypassing that sanitisation).
	 *
	 * @since 2.0.2
	 * @param {Object} point
	 * @returns {{default: string, active: string}|null}
	 */
	function pointOwnIcons( point ) {
		var icons = ( point && point.icons ) ?? null;

		if ( ! icons || 'string' !== typeof icons.default || '' === icons.default ) {
			return null;
		}

		var active = 'string' === typeof icons.active && icons.active ? icons.active : icons.default;

		return { default: icons.default, active: active };
	}

	/**
	 * Every DISTINCT point-level type code inside `group.points`, in first-seen order (live-review
	 * round 3, Finding 1). `group.typeCode` alone is only the FIRST point's own type
	 * (`pickup-geo.js`'s own `groupByPosition()` convention — see the file docblock's opening
	 * paragraph) — filtering a co-located group by that ALONE hid the WHOLE marker the instant the
	 * first point's type was unchecked, even while a second, different-typed point inside that
	 * SAME group still passed the filter and stayed correctly listed in the sidebar (`pickup-panels.js`
	 * filters per POINT, never per group). {@see WoodevYandexMapProvider#setTypeFilter}'s predicate
	 * tests against this full set instead, so a group survives whenever ANY of its points does.
	 * Falls back to `[ group.typeCode ]` only when no point carries usable type info at all
	 * (defensive — should not happen for a real fixture).
	 *
	 * @since 2.0.2
	 * @param {Object} group
	 * @returns {Array<string>}
	 */
	function groupTypeCodes( group ) {
		var seen = {};
		var codes = [];

		( group.points || [] ).forEach( function( point ) {
			var code = pointTypeCode( point );

			if ( code && ! seen[ code ] ) {
				seen[ code ] = true;
				codes.push( code );
			}
		} );

		if ( 0 === codes.length && group.typeCode ) {
			codes.push( group.typeCode );
		}

		return codes;
	}

	// -------------------------------------------------------------------------
	// Script loading — module-scope cache, unchanged from the previous version of this file
	// -------------------------------------------------------------------------

	/** @type {Object.<string, Promise>} pending/settled script loads, keyed by `config.ns`. */
	var scriptPromises = {};

	/**
	 * Loads `config.scriptUrl` and resolves with the ready ymaps namespace object
	 * (`window[config.ns]`). Idempotent across every provider instance and every session on the
	 * page — see the file docblock's "SCRIPT LOADING" section.
	 *
	 * @param {Object} config
	 * @returns {Promise<Object>}
	 */
	function loadYmapsScript( config ) {
		var ns = config.ns;

		if ( ! scriptPromises[ ns ] ) {
			scriptPromises[ ns ] = new Promise( function( resolve, reject ) {
				if ( window[ ns ] && 'function' === typeof window[ ns ].ready ) {
					resolve( window[ ns ] );

					return;
				}

				var script = document.createElement( 'script' );

				script.src = config.scriptUrl;
				script.async = true;
				script.onload = function() {
					if ( ! window[ ns ] || 'function' !== typeof window[ ns ].ready ) {
						reject( new Error( 'woodev-pickup-map: ymaps namespace missing after script load' ) );

						return;
					}

					resolve( window[ ns ] );
				};
				script.onerror = function() {
					reject( new Error( 'woodev-pickup-map: ymaps script failed to load' ) );
				};

				document.head.appendChild( script );
			} ).catch( function( err ) {
				// Allow a genuine retry (a fresh provider instance, see pickup-mount.js) to try
				// loading again rather than being permanently wedged on a transient failure.
				delete scriptPromises[ ns ];

				return Promise.reject( err );
			} );
		}

		return scriptPromises[ ns ].then( function( api ) {
			return Promise.resolve( api.ready() ).then( function() {
				return api;
			} );
		} );
	}

	// -------------------------------------------------------------------------
	// Marker icon layout — an HTML layout, not iconLayout: 'default#image' (D-5)
	// -------------------------------------------------------------------------

	/**
	 * Builds the per-feature marker layout class via `ymaps.templateLayoutFactory.createClass`.
	 * `build()`/`clear()` are kept THIN: all real rendering lives in
	 * {@see WoodevYandexMapProvider#_renderMarker}, independently unit-testable without going
	 * through ymaps' own layout machinery — matches the pattern the previous version of this
	 * file used for its balloon layout.
	 *
	 * @param {Object}                 ymaps
	 * @param {WoodevYandexMapProvider} provider
	 * @returns {Function}
	 */
	/**
	 * Returns a reader for one feature's properties, tolerating BOTH shapes ymaps hands a
	 * layout.
	 *
	 * A `Placemark`'s layout receives a data-manager with `.get( key )`. An `ObjectManager`
	 * overlay's layout receives the feature's `properties` as a PLAIN OBJECT — the JSON the
	 * feature was added with. Calling `.get()` on that threw
	 * `properties.get is not a function` inside ymaps' own (cross-origin) script, where the
	 * browser reports it only as a bare "Script error." with no stack: every marker rendered as
	 * an empty box, the click bindings never attached, and dragging the map then span forever on
	 * `map.action.Continuous: ticking while inactive`.
	 *
	 * @param {Object} properties
	 * @returns {function(string): *}
	 */
	function readProperty( properties ) {
		if ( properties && 'function' === typeof properties.get ) {
			return function( key ) {
				return properties.get( key );
			};
		}

		return function( key ) {
			return properties ? properties[ key ] : undefined;
		};
	}

	function buildMarkerLayoutClass( ymaps, provider ) {
		return ymaps.templateLayoutFactory.createClass(
			'<div class="woodev-pickup-marker"></div>',
			{
				build: function() {
					this.constructor.superclass.build.call( this );
					provider._renderMarker( this.getElement(), this.getData() );
				},
				clear: function() {
					this.constructor.superclass.clear.call( this );
				},
			}
		);
	}

	// -------------------------------------------------------------------------
	// Provider
	// -------------------------------------------------------------------------

	/**
	 * @constructor
	 */
	function WoodevYandexMapProvider() {
		this.ymaps = null;
		this.map = null;
		this.objectManager = null;
		this.config = null;
		this.container = null;

		/** @type {Function|null} the shared marker layout class — built once, in init(). */
		this._iconLayoutClass = null;

		this.handlers = {
			pointClick: [],
			clusterClick: [],
			boundsChange: [],
			bboxTooWide: [],
			visibleChange: [],
			zoomChange: [],
			nothingNearby: [],
			searchResults: [],
			searchCleared: [],
			addressFocused: [],
			addressMatchedPoint: [],
			error: [],
		};

		/** @type {Object|null} the last `{ canZoomIn, canZoomOut }` pair reported through
		 *  `zoomChange` — the dedupe baseline in {@see _emitZoomChange}. `null` means "nothing
		 *  reported yet", which is why the first call always emits. */
		this._zoomLimits = null;

		/** @type {Object.<string, Object>} the current groups, by key — see {@see setPoints}. */
		this._groupsByKey = {};

		/** @type {string|null} the group key {@see focusGroup} last successfully focused. */
		this._focusedKey = null;

		/** @type {Array<string>|null} the currently active type-filter code list, as
		 *  {@see setTypeFilter} last set it — `null` means "every type shows". Consulted by
		 *  {@see _buildProperties}/{@see _survivingPoints} so a marker's icon and badge count
		 *  reflect the SURVIVING subset of a co-located group, not its full membership
		 *  (live-review round 3, Finding 1) — see {@see setTypeFilter}'s own docblock. */
		this._activeTypeFilter = null;

		/** @type {Object|null} the `ymaps.control.SearchControl` built in init() — see
		 *  {@see _buildSearchControl}. */
		this.searchControl = null;

		/** @type {HTMLElement|null} the element ymaps draws into — either the container itself,
		 *  when the caller handed us the panels' `.woodev-pickup-map`, or one this file created
		 *  inside it. See {@see WoodevYandexMapProvider#_buildMap}. Null until `init()` builds
		 *  the map, and again after `destroy()`. */
		this.canvasEl = null;

		/** @type {boolean} true only when `init()` CREATED `canvasEl`, which is the only case in
		 *  which `destroy()` may remove it from the DOM. */
		this.ownsCanvasEl = false;

		/** @type {number} bumped on every {@see focusGroup} call — discards a stale
		 *  continuation when a later call's camera move resolves before an earlier one's. */
		this._focusSeq = 0;

		/** @type {Promise|null} the {@see setPoints} `bulk` camera fit that is CURRENTLY in
		 *  flight, or null when none is — {@see focusGroup} gates its own camera move behind it.
		 *  Null (not a resolved promise) so a focus issued when nothing is fitting still moves the
		 *  camera synchronously, inside the click's own task. See {@see focusGroup}'s gate for the
		 *  race this exists to close. */
		this._cameraFit = null;

		/** @type {number} bumped on every {@see focusAddress} call (and captured at the start of
		 *  every {@see resolveAddress} call) — discards a stale `resolveAddress()` continuation
		 *  when a later address search/pick resolves before an earlier one's (live-review round 4;
		 *  see {@see resolveAddress}'s own docblock). The address-focus equivalent of
		 *  {@see this._focusSeq}. */
		this._addressSeq = 0;

		/** @type {*} the ACCESSOR {@see setMargin} last got back from `map.margin.addArea()`
		 *  — removal goes through its own `remove()`, there is no `margin.removeArea()`.
		 *  Null when nothing is currently reserved. */
		this._marginArea = null;

		/** @type {*} the ACCESSOR {@see _buildMap} got back from reserving the STATIC top-chrome
		 *  strip — kept entirely separate from {@see this._marginArea} (the sidebar's own,
		 *  dynamic reservation): `setMargin()` must never remove this one, and this one is never
		 *  re-added after the first build (see {@see _buildMap}'s own docblock and the file
		 *  docblock's "MAP MARGINS" section). Null until `init()` builds the map, and again after
		 *  `destroy()`. */
		this._topMarginArea = null;

		this._destroyed = false;
	}

	/**
	 * Registers a handler for one of this provider's events — see the file docblock's
	 * "PUBLIC SURFACE" section for the full list.
	 *
	 * @param {string}   event
	 * @param {Function} cb
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype.on = function( event, cb ) {
		if ( this.handlers[ event ] && 'function' === typeof cb ) {
			this.handlers[ event ].push( cb );
		}
	};

	/**
	 * Fires every handler registered for `event`.
	 *
	 * @param {string} event
	 * @param {*}      payload
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype.emit = function( event, payload ) {
		( this.handlers[ event ] || [] ).forEach( function( cb ) {
			cb( payload );
		} );
	};

	/**
	 * Initializes the map inside `container`. `hasApiKey === false` or a script/ready failure
	 * emits a `map_script` error and draws nothing; otherwise the map and the object manager are
	 * built and, under `strategy: 'viewport'`, the initial viewport is resolved and the
	 * `boundschange` watcher is armed. This file never calls `setPoints()` itself — the caller
	 * (Task 20's mount) does, once it has fetched the groups for whatever viewport/locality this
	 * provider reports.
	 *
	 * @param {HTMLElement} container
	 * @param {Object}      config the MERGED provider config — see the file docblock.
	 * @returns {Promise<void>}
	 */
	WoodevYandexMapProvider.prototype.init = function( container, config ) {
		var self = this;

		this.container = container;
		this.config = config || {};

		if ( false === this.config.hasApiKey ) {
			this.emit( 'error', { code: 'map_script', message: '' } );

			return Promise.resolve();
		}

		return loadYmapsScript( this.config ).then(
			function( ymaps ) {
				if ( self._destroyed ) {
					return undefined;
				}

				self.ymaps = ymaps;
				self._buildMap();
				self._buildObjectManager();
				self._buildSearchControl();

				return self._resolveInitialViewport();
			},
			function() {
				if ( ! self._destroyed ) {
					self.emit( 'error', { code: 'map_script', message: '' } );
				}
			}
		).then( function() {
			if ( self._destroyed || ! self.map ) {
				return;
			}

			// Strategy-independent, unlike everything below it: the zoom range is reached the
			// same way whether or not a pan refetches. Fired once for the viewport the map
			// settled on, so the buttons start out already correct — a map that opens AT
			// `MAX_ZOOM` (what `focusGroup()` does on a restored selection) must not show a
			// live «+» until the customer first moves the camera.
			self._emitZoomChange();

			if ( 'viewport' === self.config.strategy ) {
				// Fire once for the viewport just resolved, THEN start listening — in that
				// order, so this initial call is never immediately followed by a redundant
				// second one from a listener that was not registered yet.
				self._checkAndEmitBounds();
				self.map.events.add( 'boundschange', function() {
					self._checkAndEmitBounds();

					// Issue #222: without this, the sidebar list only updated once the SERVER
					// answered the `boundsChange` refetch above (7-10s on the rig) — the map could
					// already be showing a different, freshly-drawn set of pins while the list still
					// listed the PREVIOUS viewport's points. `_emitVisibleChange()` is a pure
					// client-side recompute over `_groupsByKey` (the last set `setPoints()` drew) —
					// no request — so there is no reason to wait for the refetch before the sidebar
					// catches up with what the camera already shows. Matches the `bulk` listener
					// below, which has called this unconditionally from day one.
					self._emitVisibleChange();
					self._emitZoomChange();
				} );

				return;
			}

			// bulk: nothing to (re)fetch on pan/zoom — the whole locality is already loaded —
			// but the panel's own "points currently in the viewport" list must still track a
			// camera the customer is free to move themselves. See the file docblock.
			self.map.events.add( 'boundschange', function() {
				self._emitVisibleChange();
				self._emitZoomChange();
			} );
		} );
	};

	/**
	 * Builds the map, reserves the static top-chrome strip our own search bar occupies (see the
	 * file docblock's "MAP MARGINS" section), and adds its custom tile layers/copyrights (D-8) —
	 * everything that does not depend on point data.
	 *
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._buildMap = function() {
		var ymaps = this.ymaps;
		var config = this.config;
		var defaultLocation = config.defaultLocation || { center: DEFAULT_CENTER, zoom: DEFAULT_ZOOM };

		// The canvas host is whatever we were handed, when that element is already the panels'
		// map element (spec V-3: `.woodev-pickup-map` inside `.woodev-pickup-stage`, sized by the
		// stage). Creating a second one here — which this file used to do unconditionally — put
		// TWO `.woodev-pickup-map` nodes on the page: the panels' inside the stage, and ymaps'
		// nested within it, so every rule written against that class matched twice.
		//
		// The fallback still creates one, because a caller may legitimately hand us a bare
		// container: `Embedded_Map_Provider`'s `ownsChrome` branch has no panels at all, and the
		// class is what `pickup.css` sizes the map through — without an element carrying it the
		// map has no height and renders as a zero-pixel strip.
		if ( this.container.classList && this.container.classList.contains( 'woodev-pickup-map' ) ) {
			this.canvasEl = this.container;
			this.ownsCanvasEl = false;
		} else {
			this.canvasEl = document.createElement( 'div' );
			this.canvasEl.className = 'woodev-pickup-map';
			this.container.appendChild( this.canvasEl );
			this.ownsCanvasEl = true;
		}

		this.map = new ymaps.Map(
			this.canvasEl,
			{ center: defaultLocation.center, zoom: defaultLocation.zoom, controls: [] },
			{
				suppressMapOpenBlock: true,
				minZoom: MIN_ZOOM,
				maxZoom: MAX_ZOOM,
				// D3 (live-review defect): without this, Yandex's own POI layer keeps its click
				// handlers underneath our markers and opens ITS organisation card instead of
				// ours — this file owns click handling on this map now (see _buildObjectManager
				// / iconShapeFor), so the map's own POI interactivity must be off.
				yandexMapDisablePoiInteractivity: true,
			}
		);

		// The STATIC top-chrome reservation (live-review follow-up, T1) — the space our own
		// search bar occupies, never the sidebar's (that is {@see setMargin}'s own, DYNAMIC
		// `this._marginArea`; the two fields are never touched by each other's code path — see
		// the file docblock's "MAP MARGINS" section). Added exactly once, here, because unlike
		// the sidebar this strip never changes size or visibility for the life of the map — there
		// is nothing for a caller to vary, so this is not exposed as a public method the way
		// `setMargin()` is. Both references reserve the identical strip: Yandex.Delivery
		// `widget-map.js` — `this.map.margin.addArea({ top: 0, left: 0, width: '100%',
		// height: '64px' })`; the Russian Post bundle —
		// `[{top:0,left:0,width:"100%",height:"64px"}].forEach(t => d.margin.addArea(t))`. Without
		// it, a camera fit (`focusGroup()`, `focusAddress()`, the initial-viewport/bulk fits) is
		// free to frame a point directly underneath the search bar, which ymaps has no other way
		// of knowing occupies screen space.
		this._topMarginArea = this.map.margin.addArea( { top: 0, left: 0, width: '100%', height: '64px' } );

		this._addLayers( config.layers );
		this._addCopyrights( config.copyrights );
	};

	/**
	 * Adds the plugin's optional custom tile layers (D-8) — an arbitrary ymaps layer descriptor
	 * `{ url, projection }`; `projection`, when a recognised name (e.g. `'sphericalMercator'`),
	 * resolves to the matching `ymaps.projection.*` object, mirroring the reference
	 * implementation's own `map.layers.add( new ym.Layer( url, { projection } ) )` call.
	 *
	 * @param {Array} layers
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._addLayers = function( layers ) {
		var self = this;

		( layers || [] ).forEach( function( layer ) {
			if ( ! layer || 'string' !== typeof layer.url ) {
				return;
			}

			var options = {};
			var projection = self.ymaps.projection;

			if ( 'string' === typeof layer.projection && projection && projection[ layer.projection ] ) {
				options.projection = projection[ layer.projection ];
			}

			self.map.layers.add( new self.ymaps.Layer( layer.url, options ) );
		} );
	};

	/**
	 * Adds the plugin's optional custom map copyright/attribution strings (D-8) via ymaps' own
	 * `copyrights.add()` — never `innerHTML`; these are plugin-author-supplied construction
	 * values, not customer input, but this is still ymaps' own attribution surface, not ours.
	 *
	 * @param {Array} copyrights
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._addCopyrights = function( copyrights ) {
		var self = this;

		( copyrights || [] ).forEach( function( text ) {
			if ( 'string' === typeof text ) {
				self.map.copyrights.add( text );
			}
		} );
	};

	/**
	 * Builds the `ObjectManager` every group becomes one feature of (D-4, D-11). Filtering
	 * (D-10) goes through `setFilter()`, never a rebuild — see {@see setTypeFilter}.
	 * `geoObjectOpenBalloonOnClick`/`clusterHasBalloon` are both `false`: the panels own the
	 * point card's DOM, ymaps must not open anything of its own on top of it.
	 *
	 * Also wires the CLUSTER icon's own click ({@see _handleClusterClick}, live-review defect —
	 * clicking a cluster used to do nothing at all, neither reference leaves it inert): a
	 * feature click (`objects.events`) and a cluster-icon click (`clusters.events`) are two
	 * DIFFERENT ymaps event streams on this same ObjectManager, never the same handler.
	 *
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._buildObjectManager = function() {
		var self = this;
		var clusterColor = geo.safeColor( this.config.accentColor, CLUSTER_ICON_COLOR_FALLBACK );

		this._iconLayoutClass = buildMarkerLayoutClass( this.ymaps, this );

		this.objectManager = new this.ymaps.ObjectManager( {
			clusterize: true,
			clusterIconColor: clusterColor,
			geoObjectOpenBalloonOnClick: false,
			clusterHasBalloon: false,
		} );

		this.objectManager.objects.events.add( 'click', function( e ) {
			self.emit( 'pointClick', e.get( 'objectId' ) );
		} );

		this.objectManager.clusters.events.add( 'click', function( e ) {
			self._handleClusterClick( e.get( 'objectId' ) );
		} );

		this.map.geoObjects.add( this.objectManager );
	};

	/**
	 * Handles a click on a CLUSTER icon (as opposed to a single feature — see
	 * {@see _buildObjectManager}'s own docblock) — a live-review defect: no handler existed at
	 * all, so clicking a cluster did nothing. Both references leave the clusterer's/cluster
	 * layer's own default expand behaviour in place rather than owning this themselves; this
	 * file's markers are a custom HTML layout on a bare `ObjectManager`, which has no default
	 * expand of its own, so this method supplies one: step the zoom in by 2 (clamped to
	 * {@see MAX_ZOOM}) centred on the cluster's own anchor coordinate.
	 *
	 * `objectManager.clusters.getById( objectId )` returns the SAME GeoJSON-ish shape
	 * {@see clusterAnchorCoordinates} already reads elsewhere in this file (`geometry.coordinates`
	 * as a plain array, not a `getCoordinates()` method) — ObjectManager clusters are POJOs, not
	 * `Placemark` instances, so this stays consistent with every other cluster read in this file.
	 *
	 * `setCenter()` is asynchronous (see the file docblock's first lesson) but this method does
	 * not await it: nothing downstream needs to know when the zoom settles, and `clusterClick` is
	 * informational only — the mount ignores it (see the frozen contract in the SP-5 live-review
	 * plan); it exists for a future consumer, not because this codebase currently listens for it.
	 *
	 * @since 2.0.2
	 * @param {string} objectId the cluster's own id, as `clusters.events`'s `click` event carries it.
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._handleClusterClick = function( objectId ) {
		if ( ! this.map || ! this.objectManager || ! this.objectManager.clusters ) {
			return;
		}

		var cluster = 'function' === typeof this.objectManager.clusters.getById
			? this.objectManager.clusters.getById( objectId )
			: null;
		var coords = cluster && cluster.geometry && cluster.geometry.coordinates;

		if ( ! coords ) {
			return;
		}

		var nextZoom = Math.min( this.map.getZoom() + 2, MAX_ZOOM );

		// `useMapMargin: true` (live-review round 3, Finding 3) — every OTHER camera move in this
		// file passes it (`focusGroup()`, `focusAddress()`, the initial-viewport/bulk fits); this
		// one was the sole exception, so a clicked cluster could zoom in centred underneath the
		// static top strip or the sidebar's own reservation, which those margins exist to prevent.
		this.map.setCenter( coords, nextZoom, { duration: 200, useMapMargin: true } );
		this.emit( 'clusterClick', { coords: coords } );
	};

	/**
	 * Builds the address-search control (Task 12, spec V-6/V-7; gotcha
	 * `ymaps-control-options-must-be-nested.md`). `ymaps.control.SearchControl` keeps its ENGINE
	 * (`search()`, `getResultsCount()`, `showResult()`) and loses its default CHROME: the panels'
	 * own layout ({@see WoodevYandexMapProvider}'s file docblock, "ADDRESS SEARCH") replaces it,
	 * wrapped in a `templateLayoutFactory` class whose `build()`/`clear()` just append/detach that
	 * ALREADY-BUILT element — this file never constructs so much as a `<div>` of search markup
	 * itself (D-3).
	 *
	 * EVERY option below is nested under `options` — `layout`, `noPlacemark`, `float`,
	 * `position`. ymaps controls take exactly `{ data, options, state }` at the root
	 * and silently drop anything else; the previous version of this file passed `provider`/
	 * `layout`/`resultsLayout`/`noPlacemark` at the ROOT, so ymaps kept its own default (English,
	 * worldwide-geocoding) chrome and none of this file's configuration ever took effect. See the
	 * gotcha file for the full incident.
	 *
	 * NO `provider` OPTION HERE (issue #180) — the control's own `search()`/`getResultsCount()`/
	 * `showResult()` engine is deliberately left unconfigured. It used to run a bounded
	 * `ymaps.geocode()` via `_searchGeocodeProvider()`, triggered by `control.search()` on the
	 * magnifier; #179 replaced that call with `resolveAddress()` on the top {@see suggestAddresses}
	 * hit instead, which left `control.search()` with no caller and its geocode provider
	 * unreachable. `SearchControl` survives in this file purely as a DOM HOST: `searchLayoutEl` is
	 * injected into its layout below, which is where its 16/16 position on the map comes from
	 * (`options.position`) — it is not, any more, a search engine this file calls.
	 *
	 * `config.searchLayoutEl` is null when the plugin disabled search (`config.search === false`
	 * — `pickup-panels.js`'s own `buildSearchLayout()` returns null in that case) — this method
	 * is then a no-op: no control is built at all, matching spec V-6's "Visibility: search =>
	 * true|false" contract exactly (no empty/disabled control left sitting on the map).
	 *
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._buildSearchControl = function() {
		var self = this;
		var layoutEl = this.config.searchLayoutEl;

		if ( ! layoutEl ) {
			return;
		}

		var layout = this.ymaps.templateLayoutFactory.createClass( '<div></div>', {
			build: function() {
				this.constructor.superclass.build.call( this );
				this.getElement().appendChild( layoutEl );
			},
			clear: function() {
				if ( layoutEl.parentNode ) {
					layoutEl.parentNode.removeChild( layoutEl );
				}

				this.constructor.superclass.clear.call( this );
			},
		} );

		this.searchControl = new this.ymaps.control.SearchControl( {
			options: {
				layout: layout,
				noPlacemark: true,
				float: 'none',
				position: { left: '16px', right: 'auto', top: '16px' },
			},
		} );

		this.map.controls.add( this.searchControl );
	};

	/**
	 * Flattens every currently-loaded group's points into one array — the pool
	 * {@see suggestAddresses}/{@see matchLoadedPoints} search. Rebuilt on every call rather
	 * than cached: the pool changes on every {@see setPoints}, and this runs once per search, not
	 * once per point.
	 *
	 * @returns {Array}
	 */
	WoodevYandexMapProvider.prototype._allPoints = function() {
		var groupsByKey = this._groupsByKey;
		var points = [];

		Object.keys( groupsByKey ).forEach( function( key ) {
			points = points.concat( groupsByKey[ key ].points || [] );
		} );

		return points;
	};

	/**
	 * The bounds of every currently-loaded group, or null before anything has ever been loaded
	 * (spec V-7 — the address search is then simply un-bounded, never a degenerate box). Computed
	 * from {@see _groupsByKey} via `pickup-geo.boundsFor()` — the SAME arithmetic
	 * {@see setPoints}'s own `bulk`-strategy camera fit already uses — rather than any
	 * `ObjectManager`-native bounds query, which the real API does not expose.
	 *
	 * @returns {Array|null} `[[minLat, minLng], [maxLat, maxLng]]`, or null.
	 */
	WoodevYandexMapProvider.prototype._loadedBounds = function() {
		var groupsByKey = this._groupsByKey;
		var keys = Object.keys( groupsByKey );

		if ( 0 === keys.length ) {
			return null;
		}

		var groups = keys.map( function( key ) {
			return groupsByKey[ key ];
		} );

		var bounds = geo.boundsFor( [ groups[ 0 ].lat, groups[ 0 ].lng ], groups );

		// A single loaded group — or several in one building — yields a ZERO-AREA box, and
		// `strictBounds: true` against zero area matches nothing at all: in a city served by one
		// pickup point the customer could type their own address forever and never see a result
		// (the same one-point city that issue #150 is about). Pad any box thinner than the
		// minimum so there is something to search inside.
		return padBounds( bounds, MIN_SEARCH_BOUNDS_DEGREES );
	};

	/**
	 * Matches `query` against the already-loaded point pool — free, local, no network (spec V-6).
	 * The POINT half of what powers the typing dropdown; {@see suggestAddresses} is the ADDRESS
	 * half — Task 20's mount wires this to the panels' own debounced `searchType` event, which
	 * fires on every keystroke and must never touch the geocoder (that would burn the merchant's
	 * quota once per keystroke instead of once per deliberate search) — matching points cost
	 * nothing to check locally, so this one alone never needed the suggest/geocode split at all.
	 *
	 * @since 2.0.2
	 * @param {string} query free-text query, as typed.
	 * @returns {Array} matching points, in their original order.
	 */
	WoodevYandexMapProvider.prototype.matchLoadedPoints = function( query ) {
		return geo.matchPoints( this._allPoints(), query );
	};

	/**
	 * Powers the CUSTOM typing dropdown's ADDRESS half via `ymaps.suggest()` (live-review round 4,
	 * Finding A.1) — the reference's OWN data source for exactly this moment: Yandex.Delivery's
	 * `widget-map.js` keeps ymaps' NATIVE suggest widget alive (`noSuggestPanel: false`), which
	 * calls `ymaps.suggest()` internally, automatically, for free, as the customer types. This file
	 * replaces the control's ENTIRE chrome with the framework's own layout (D-3), so there is no
	 * native widget left to do that for us — this method reproduces it explicitly. Task 20's mount
	 * wires the panels' own debounced `searchType` event to this method (the point half stays
	 * {@see matchLoadedPoints}, unchanged).
	 *
	 * `ymaps.suggest()`, NOT `ymaps.geocode()`, is deliberate and load-bearing, not a style choice:
	 * `suggest()` returns short, ADDRESS-shaped strings and ranks street addresses over points of
	 * interest; `geocode()` ranks POIs (a transit station, a landmark) alongside addresses with no
	 * such bias, which is the entire root cause of the operator's live-review round 3 report —
	 * typing the exact address "Чертановская 66к1" geocoded to an English-language, full-postal-
	 * form TRANSIT STATION instead of the reference's own short "Чертановская улица, 66к1". See
	 * the file docblock's "ADDRESS SEARCH" section for the full two-engine design this method is
	 * one half of.
	 *
	 * BOUNDED to the loaded point area (see {@see _loadedBounds}, spec V-7) — omitted before
	 * anything has ever loaded, never a degenerate box.
	 *
	 * WHY BOUNDED HERE BUT NOT IN {@see resolveAddress} — gotcha
	 * `bounding-the-address-resolve-breaks-the-normal-case` (s51, self-inflicted): this call
	 * OFFERS candidates, so bounding it to the loaded points is correct — under `bulk` the loaded
	 * set is exactly the buyer's own locality, so a Moscow shopper typing a Moscow street name is
	 * never offered a same-named street in Tolyatti; under `viewport` the loaded set follows the
	 * viewport, so one rule serves both strategies. `resolveAddress()` RESOLVES the one suggestion
	 * already picked — there is nothing left to disambiguate by then, the picked string already
	 * carries its own full country/locality prefix (see {@see projectSuggestion}'s `query`) — and
	 * bounding it the SAME way is a DIFFERENT, WRONG rule: the customer's own address is routinely
	 * OUTSIDE the area the loaded pickup points cover, which is the entire reason they are
	 * searching for it. Measured live against the fixture: with `resolveAddress()` bounded like
	 * this call, an address ~14km from the fixture's Moscow-cluster points made `geocode()` return
	 * zero hits, and the click silently did nothing — no camera move, no error, no fallback,
	 * nothing observable at all, indistinguishable from the click never registering. Bound the
	 * calls that OFFER candidates ({@see suggestAddresses}, here); never the call that RESOLVES an
	 * already-chosen one ({@see resolveAddress}).
	 *
	 * RETURNS `{ points, addresses }` — it does NOT emit `searchResults` (live-review round 4,
	 * second follow-up: an earlier version of this method DID emit `searchResults`, which
	 * reintroduced the operator's own round-3 defect — "начинаешь писать адрес … появляется
	 * «Поиск не дал результатов.» и висит"). `searchResults` is the COMPLETED-SEARCH event;
	 * `pickup-mount.js` wires it to `panels.renderSearchResults()`, the renderer that prints the
	 * "no results" verdict. This method runs on the DEBOUNCED TYPING path — routing its output
	 * through that same event would drive the completed-search verdict mid-keystroke, exactly the
	 * bug the `previewSearchResults()`/`renderSearchResults()` split already exists to prevent.
	 * The mount instead calls `provider.suggestAddresses( query ).then( r =>
	 * panels.previewSearchResults( r ) )` — the SAME "plain return value, never an event" shape
	 * {@see matchLoadedPoints} already has (that method just never needed a promise, being
	 * synchronous), extended here to a method that now does network I/O.
	 *
	 * NEVER calls `ymaps.geocode()` — resolving a picked suggestion to real coordinates stays
	 * {@see resolveAddress}'s own, single, deliberate job, matching the reference's "geocode once
	 * per deliberate pick" model exactly (see the file docblock's "ADDRESS SEARCH" section).
	 *
	 * CONFIRMED against a REAL `ymaps.suggest()` call (live-review round 4 follow-up — no longer a
	 * guess): `results`/`boundedBy`/`strictBounds` ARE honoured identically to `ymaps.geocode()`'s
	 * own same-named options — a bounded call returned results on the right street only. The item
	 * shape is `{ type, displayName, value, hl }`; each is projected to `{ displayName, query }`
	 * via {@see projectSuggestion} — `item.displayName` is REVERSED (house number first,
	 * `"66к1, Чертановская улица, Москва, Россия"`) and carries the full country/locality prefix,
	 * so BOTH returned fields are derived from `item.value` instead (broadest-to-narrowest,
	 * geocodable as-is), never from `displayName`, except when `value` is missing entirely.
	 * `query` — the FULL, un-trimmed `value` — is what `pickup-mount.js` prefers
	 * (`address.query || address.displayName`) when calling {@see resolveAddress}: geocoding the
	 * SHORT `displayName` alone ("Чертановская улица, 66к1" with no city) is exactly the
	 * ambiguity `strictBounds` exists to prevent everywhere else in this file.
	 *
	 * A refused/failed `suggest()` call resolves with the locally-matched points and an EMPTY
	 * address list — never rejects — so a network hiccup degrades to the free local matches
	 * instead of blanking the preview; a call that outlives `destroy()` resolves with nothing at
	 * all (`{ points: [], addresses: [] }`) rather than handing the caller results to apply to a
	 * torn-down (or freshly reopened) panels instance.
	 *
	 * @since 2.0.2
	 * @param {string} query free-text query, as typed.
	 * @returns {Promise<{points: Array, addresses: Array}>}
	 */
	WoodevYandexMapProvider.prototype.suggestAddresses = function( query ) {
		var self = this;
		var matches = geo.matchPoints( this._allPoints(), query );
		var locality = this.config.locality;
		var suggestOptions = { results: SEARCH_RESULT_COUNT };
		var bounds = this._loadedBounds();

		if ( bounds ) {
			suggestOptions.boundedBy = bounds;
			suggestOptions.strictBounds = true;
		}

		if ( ! this.ymaps || 'function' !== typeof this.ymaps.suggest ) {
			return Promise.resolve( { points: matches, addresses: [] } );
		}

		return this.ymaps.suggest( query, suggestOptions ).then( function( items ) {
			if ( self._destroyed ) {
				// A stale in-flight request resolves with nothing meaningful rather than handing
				// the caller results to apply to a torn-down (or freshly reopened) panels
				// instance — same discipline every async continuation in this file follows once
				// `_destroyed` is set (see the file docblock's "DESTROY IS IDEMPOTENT" section).
				return { points: [], addresses: [] };
			}

			var addresses = ( Array.isArray( items ) ? items : [] ).map( function( item ) {
				return projectSuggestion( item, locality );
			} );

			return { points: matches, addresses: addresses };
		} ).catch( function() {
			// A refused/failed suggest() call must not leave the customer staring at nothing —
			// the locally-matched points still stand, so they're still returned, with an empty
			// address list rather than a rejection.
			return { points: matches, addresses: [] };
		} );
	};

	/**
	 * Resolves the map's initial viewport for `strategy: 'viewport'` (Task 18, D-7) — a no-op
	 * for `strategy: 'bulk'`, which fits the camera to whatever {@see setPoints} is first called
	 * with instead (see that method). A known `config.locality` is geocoded and its bounds
	 * APPLIED (awaited — see the file docblock's first lesson); an empty locality, or a geocode
	 * that resolves nothing, falls back to `config.defaultLocation` via `map.setCenter()`.
	 * Neither fallback path emits an `error` — an unresolved geocode is not a broken map, just a
	 * wider first viewport.
	 *
	 * `config.locality` MUST be a geocodable place name, not an opaque carrier code —
	 * `ymaps.geocode()` is a free-text geocoder; see `pickup-mount.js`'s own
	 * `buildProviderConfig()` docblock for the field-source contract this relies on.
	 *
	 * @returns {Promise<void>}
	 */
	WoodevYandexMapProvider.prototype._resolveInitialViewport = function() {
		var self = this;

		if ( 'viewport' !== this.config.strategy ) {
			return Promise.resolve();
		}

		var locality = this.config.locality;

		if ( ! locality ) {
			this._applyDefaultLocation();

			return Promise.resolve();
		}

		return this.ymaps.geocode( locality ).then(
			function( result ) {
				if ( self._destroyed ) {
					return undefined;
				}

				var bounds = extractGeocodeBounds( result );

				if ( ! bounds ) {
					self._applyDefaultLocation();

					return undefined;
				}

				// setBounds() is ASYNCHRONOUS — always RETURNED/awaited, never fire-and-forgotten.
				// See the file docblock's first lesson: dropping this return lets the very next
				// step (emitting boundsChange from the PRE-move viewport) run before the camera
				// has actually moved. `duration: 400` (live-review fix): an un-animated jump cut
				// to the resolved locality read as the map silently teleporting.
				return self.map.setBounds( bounds, { checkZoomRange: true, duration: 400 } );
			},
			function() {
				if ( ! self._destroyed ) {
					self._applyDefaultLocation();
				}
			}
		);
	};

	/**
	 * Centres the map on `config.defaultLocation` — the shared fallback for every "could not
	 * resolve a real viewport" case under `strategy: 'viewport'` (see {@see _resolveInitialViewport}).
	 *
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._applyDefaultLocation = function() {
		var location = this.config.defaultLocation;

		if ( location ) {
			this.map.setCenter( location.center, location.zoom );
		}
	};

	/**
	 * The map's CURRENT centre `[lat, lng]` — issue #163's seam. `pickup-mount.js` reads this
	 * exactly once per session, the first time `visibleChange` fires, to set the panels'
	 * distance anchor to "the centre of the initial viewport" (see `pickup-panels.js`'s own
	 * `setAnchor()` docblock) without ever following the camera afterwards. A synchronous
	 * accessor rather than a one-shot event deliberately: BOTH the `viewport` strategy's
	 * initial fit ({@see _resolveInitialViewport}, awaited inside {@see init}) and the `bulk`
	 * strategy's initial fit ({@see setPoints}'s own, on its first call) already settle their
	 * camera BEFORE their respective first `visibleChange` — so a caller reading this from
	 * inside that handler always sees the POST-move centre, never the pre-move one (the file
	 * docblock's first lesson). This is what makes the accessor work identically under both
	 * strategies where a live `boundsChange`-driven anchor could not: that event is
	 * `viewport`-only (see {@see _emitZoomChange}'s own comment), so following it would have
	 * turned "one rule, not two modes" into exactly two.
	 *
	 * @since 2.0.2
	 * @returns {number[]|null} `[lat, lng]`, or null before {@see init} has built the map.
	 */
	WoodevYandexMapProvider.prototype.getCenter = function() {
		return this.map ? this.map.getCenter() : null;
	};

	/**
	 * Checks the map's CURRENT bounds against the server's own bbox cap (D-4) and emits
	 * exactly one of `boundsChange`/`bboxTooWide` accordingly — called once for the initial
	 * viewport and again on every `boundschange`, under `strategy: 'viewport'` only.
	 *
	 * @returns {void}
	 */
	/**
	 * Reports whether the camera can still move IN and OUT, as `zoomChange`
	 * `{ canZoomIn, canZoomOut }` — what lets the panels' own zoom buttons show a disabled
	 * state at either end of the range instead of staying clickable no-ops (operator's call,
	 * 08.08.2026: a permanently-clickable button that does nothing reads as a bug).
	 *
	 * The RANGE lives here, not in the panels, for the same D-3 reason `zoomBy()` does: the
	 * panels emit a signed step and know nothing about map-library zoom levels. `MIN_ZOOM`/
	 * `MAX_ZOOM` are the same two constants `_buildMap()` constrains ymaps' own drag/wheel
	 * zoom to and `zoomBy()` clamps against, so the button's disabled state and the camera's
	 * actual refusal to move can never disagree.
	 *
	 * Emitted on BOTH strategies, unlike `boundsChange` — that one is `viewport`-only because
	 * it drives refetching, whereas a zoom limit is reached identically under `bulk`. Emitted
	 * only when the pair CHANGES: ymaps fires `boundschange` throughout an animated move, and
	 * a listener that re-wrote the same two booleans on every frame would be pure noise.
	 *
	 * @since 2.0.2
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._emitZoomChange = function() {
		if ( ! this.map ) {
			return;
		}

		var zoom = this.map.getZoom();
		var next = {
			canZoomIn: zoom < MAX_ZOOM,
			canZoomOut: zoom > MIN_ZOOM,
		};

		if (
			this._zoomLimits &&
			this._zoomLimits.canZoomIn === next.canZoomIn &&
			this._zoomLimits.canZoomOut === next.canZoomOut
		) {
			return;
		}

		this._zoomLimits = next;

		this.emit( 'zoomChange', next );
	};

	WoodevYandexMapProvider.prototype._checkAndEmitBounds = function() {
		var bounds = this.map.getBounds();
		var latSpan = Math.abs( bounds[ 1 ][ 0 ] - bounds[ 0 ][ 0 ] );
		var lngSpan = Math.abs( bounds[ 1 ][ 1 ] - bounds[ 0 ][ 1 ] );

		if ( latSpan > BBOX_CAP_DEGREES || lngSpan > BBOX_CAP_DEGREES ) {
			this.emit( 'bboxTooWide', null );

			return;
		}

		this.emit( 'boundsChange', flattenBounds( bounds ) );
	};

	/**
	 * Replaces the drawn groups with `groups` — a full rebuild (`removeAll()` then `add()`),
	 * never an incremental diff: the caller (Task 20's mount) is the one that decides what the
	 * current full set is (including any cross-fetch de-duplication), so this file always draws
	 * exactly what it was handed.
	 *
	 * Under `strategy: 'bulk'` a non-empty set also fits the camera to it via {@see geo}'s
	 * `boundsFor()` — the ONLY place this file fits the camera to loaded data, matching the
	 * previous version's `bulk` behaviour. That fit is AWAITED before `visibleChange` is
	 * emitted — see the file docblock's first lesson: emitting it from the map's PRE-fit bounds
	 * would report an empty (or stale) visible set for a locality whose points sit outside the
	 * technical placeholder viewport, and `bulk` has no fetch-driven refresh cycle to correct it
	 * later the way `viewport` does. A rebuild also resets every feature back to its resting
	 * options/properties, so a group that is STILL the focused one after this call gets its
	 * active state re-applied; a focused group that is GONE from the new set clears
	 * `getFocusedKey()` instead — see {@see _setMarkerState}.
	 *
	 * `options.focus: <group key>` OPENS THE MAP AT ONE GROUP instead: no `bulk` fit, the camera
	 * settles on that group at {@see MAX_ZOOM} FIRST, the features are drawn SECOND, and the group
	 * is marked active third. The mount passes it on the one pass that restores a previously
	 * chosen point (spec D-15). The order is the whole point, and s52's rig pass is what
	 * established it: drawing first and moving after — however carefully the move is sequenced
	 * behind the fit — leaves the newly un-clustered feature's overlay parked at ymaps' own
	 * off-screen sentinel (`left/top: -32760px`) until some LATER zoom change re-lays it out. The
	 * camera was right, `data-state="active"` was right, `getFocusedKey()` was right, and the map
	 * showed no pin at all. ymaps rebuilds its ObjectManager overlays in a burst that begins AFTER
	 * `actionend` — i.e. after the previous move's own promise has already resolved — so no
	 * amount of awaiting a camera promise avoids the race; only not moving the camera across the
	 * ObjectManager's first layout does. Measured on the rig: move-then-draw renders the marker
	 * on screen every time, draw-then-move parks it every time.
	 *
	 * A `focus` naming a group that is not in `groups` is ignored (the map opens normally) —
	 * "the point I chose last time is gone" is exactly the case spec D-15 says must degrade
	 * silently, and the map still has to open somewhere.
	 *
	 * @param {Array}  groups
	 * @param {Object} [options]
	 * @param {string} [options.focus] group key to open the map at, marked active, instead of
	 *                                 fitting the camera to the whole set.
	 * @returns {Promise<void>} resolves once the camera work this call started has settled and
	 *                          the points are drawn.
	 */
	WoodevYandexMapProvider.prototype.setPoints = function( groups, options ) {
		var self = this;
		var list = groups || [];
		var opts = options || {};

		this._groupsByKey = {};

		var features = list.map( function( group ) {
			self._groupsByKey[ group.key ] = group;

			return self._buildFeature( group );
		} );

		var focusGroup = opts.focus ? this._groupsByKey[ opts.focus ] : null;

		if ( focusGroup ) {
			var mySeq = ++this._focusSeq;

			// CAMERA FIRST, FEATURES SECOND — see this method's docblock for the measurement
			// behind that order. Sequenced through `_focusSeq` like every other focus, so a
			// customer who clicks something else while this move is still travelling wins.
			return this.map.setCenter(
				[ focusGroup.lat, focusGroup.lng ],
				MAX_ZOOM,
				{ useMapMargin: true, duration: 200 }
			).then( function() {
				if ( self._destroyed || mySeq !== self._focusSeq ) {
					return;
				}

				self._drawFeatures( features );
				self._applyFocus( opts.focus );
				self._emitVisibleChange();
			} );
		}

		this._drawFeatures( features );

		if ( 'bulk' === this.config.strategy && list.length > 0 ) {
			var anchor = [ list[ 0 ].lat, list[ 0 ].lng ];

			// setBounds() is ASYNCHRONOUS — awaited, exactly like _resolveInitialViewport()'s
			// own call. See the docblock comment above and the file docblock's first lesson.
			// `duration: 400` (live-review fix) — this fit can travel across the whole loaded
			// set, so it gets the SAME "long move" duration as the initial-viewport fit above.
			// The continuation is ALSO published as `_cameraFit` — the gate {@see focusGroup}
			// waits on, so a focus issued while this fit is still in flight cannot be overwritten
			// by it. It never rejects (both handlers swallow) — it is an ordering signal, not a
			// result — and it clears ITSELF (identity-checked, so a fit superseded by a newer
			// `setPoints()` never clears the newer one's gate) to keep a LATER focus synchronous.
			var fit = this.map.setBounds( geo.boundsFor( anchor, list ), { checkZoomRange: true, duration: 400 } )
				.then( function() {
					if ( fit === self._cameraFit ) {
						self._cameraFit = null;
					}

					if ( self._destroyed ) {
						return;
					}

					self._emitVisibleChange();
				}, function() {
					if ( fit === self._cameraFit ) {
						self._cameraFit = null;
					}
				} );

			this._cameraFit = fit;

			return fit;
		}

		this._emitVisibleChange();

		return Promise.resolve();
	};

	/**
	 * The drawing half of {@see setPoints} — the full `removeAll()`/`add()` rebuild plus the
	 * currently-focused group's reconciliation against the new set. Split out because the `focus`
	 * path runs it AFTER its camera move rather than before (see {@see setPoints}'s docblock),
	 * and the two paths must not grow two different ideas of what "draw these groups" means.
	 *
	 * `_groupsByKey` is already populated by the caller — this only touches the map.
	 *
	 * @since 2.0.2
	 * @param {Array} features
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._drawFeatures = function( features ) {
		this.objectManager.removeAll();
		this.objectManager.add( features );

		if ( this._focusedKey && ! Object.prototype.hasOwnProperty.call( this._groupsByKey, this._focusedKey ) ) {
			// A refetch that no longer contains the currently focused group must not leave a
			// stale key behind — the caller has nothing left to visually mark as active.
			this._focusedKey = null;
		} else if ( this._focusedKey ) {
			this._setMarkerState( this._focusedKey, 'active' );
		}
	};

	/**
	 * The subset of `group.points` that pass the CURRENTLY active type filter
	 * ({@see this._activeTypeFilter}, live-review round 3, Finding 1) — `null` (no filter) returns
	 * every point, unfiltered, same reference array. {@see _buildProperties} uses this to compute
	 * the marker's DISPLAYED state (which icon, what badge count) from what actually survived,
	 * never from the group's full membership, so a marker whose first point's type got unchecked
	 * shrinks/re-icons to the surviving point instead of staying visually stuck showing a type the
	 * sidebar no longer credits it with (or, worse, disappearing entirely — see
	 * {@see groupTypeCodes}'s own docblock for the bug this replaces).
	 *
	 * @since 2.0.2
	 * @param {Object} group
	 * @returns {Array} `group.points`' own elements (same references), filtered.
	 */
	WoodevYandexMapProvider.prototype._survivingPoints = function( group ) {
		var activeFilter = this._activeTypeFilter;
		var points = group.points || [];

		if ( ! activeFilter ) {
			return points;
		}

		return points.filter( function( point ) {
			return -1 !== activeFilter.indexOf( pointTypeCode( point ) );
		} );
	};

	/**
	 * Builds a feature's `properties` bag from a group — shared by {@see _buildFeature} (the
	 * initial `add()`) and {@see _setMarkerState} (an in-place `setObjectProperties()` update),
	 * so the two never drift into building this shape two different ways. `state` defaults to
	 * `'resting'`; {@see _setMarkerState} overwrites it when re-sending this same shape for a
	 * focus change.
	 *
	 * `iconHref`/`iconHrefActive` are now derived from {@see _survivingPoints} — the subset that
	 * passes the CURRENTLY active type filter — rather than from `group`'s full membership
	 * (live-review round 3, Finding 1): a co-located group whose FIRST point's type is the one
	 * unchecked must re-icon to whatever DID survive, never keep showing the filtered-out type's
	 * icon (or vanish entirely, the actual reported bug — see {@see setTypeFilter}'s own
	 * docblock). `groupSize` follows the SAME surviving subset, but ONLY while a filter is
	 * actually active (`this._activeTypeFilter` truthy) — with no filter it mirrors `group.size`
	 * directly, unchanged from before this fix, so a caller whose `size` and `points.length`
	 * legitimately disagree sees no behaviour change in the common (unfiltered) case. A group with
	 * NO surviving points (every one of its types filtered out) falls back to its full, unfiltered
	 * membership for the icon/type computation — harmless, since `setTypeFilter()`'s predicate
	 * already hides such a feature from the map entirely; there is no visible marker left for
	 * these properties to describe.
	 *
	 * `typeCodes` (plural — {@see groupTypeCodes}) is the group's FULL set of distinct point
	 * types, always unfiltered: it is what {@see setTypeFilter}'s predicate itself tests against
	 * to decide whether the feature is drawn AT ALL, so it must never shrink to only the
	 * currently-surviving subset the way `typeCode`/`iconHref` do.
	 *
	 * `iconHref`/`iconHrefActive` are resolved through the FULL three-tier cascade (issue
	 * #193): the representative point's own icon ({@see pointOwnIcons}), else
	 * `config.pointIcons[ <the surviving subset's representative type> ]`, else empty — in
	 * which case {@see _renderMarker} still draws the framework's own default pin, never an
	 * invisible/broken one. Both tiers are resolved with `??`, never `||` — see
	 * {@see pointOwnIcons}'s own docblock for why an empty value at either tier means "keep
	 * falling through", not "stop here with nothing". `active` is guaranteed filled whenever
	 * `default` is (mirroring it when only one image was supplied — D-5, applied identically
	 * at both tiers: {@see pointOwnIcons} for the point, `Pickup_Handler::normalized_point_icons()`
	 * for the type), so `iconHrefActive` is never a broken/empty URL whenever `iconHref` isn't.
	 *
	 * The representative point is the SAME one that decides `typeCode` — `displayPoints[0]`.
	 * A co-located group (`pickup-geo.js`'s `groupByPosition()`) therefore shows only the
	 * REPRESENTATIVE's own icon resolution, never a blend of its members': a group folding a
	 * 5post point and a Yandex-branded point onto one coordinate draws whichever one sorts
	 * first, exactly as it already does for the type-code badge. This is a consequence of the
	 * existing grouping, not a defect introduced here.
	 *
	 * @param {Object} group
	 * @returns {Object}
	 */
	WoodevYandexMapProvider.prototype._buildProperties = function( group ) {
		var activeFilter = this._activeTypeFilter;
		var survivors = this._survivingPoints( group );
		var displayPoints = survivors.length > 0 ? survivors : ( group.points || [] );
		var displayType = pointTypeCode( displayPoints[ 0 ] ) || group.typeCode;
		var typeIcons = ( this.config.pointIcons && this.config.pointIcons[ displayType ] ) || null;
		var icons = pointOwnIcons( displayPoints[ 0 ] ) ?? typeIcons ?? null;

		return {
			// Unfiltered, `groupSize` mirrors `group.size` DIRECTLY, exactly as before this fix —
			// never derived from `points.length` in that case, so a caller whose `size` and
			// `points` legitimately disagree (this file trusts `pickup-geo.js`'s own count) sees
			// no behaviour change at all when no filter is active. Only WHILE a filter is active
			// does the badge need to shrink to the surviving subset's own count.
			groupSize: activeFilter ? displayPoints.length : group.size,
			typeCode: displayType,
			typeCodes: groupTypeCodes( group ),
			iconHref: icons ? icons.default : '',
			iconHrefActive: icons ? icons.active : '',
			state: 'resting',
		};
	};

	/**
	 * Builds one ObjectManager feature for a group. `options.iconImageHref` is deliberately
	 * never set anywhere in this file — see the file docblock's "ICONS ARE AN HTML LAYOUT"
	 * section. Every newly-added feature starts in the RESTING box/shape — {@see setPoints}
	 * re-applies the active box (via {@see _setMarkerState}) to the currently focused group,
	 * if any, right after this rebuild, so a feature is never stuck resting while its own
	 * `data-state` reads active.
	 *
	 * `options.iconShape` (spec V-9) is what actually gives the custom HTML layout a hit area —
	 * see {@see iconShapeFor}'s own docblock.
	 *
	 * @param {Object} group
	 * @returns {Object} a GeoJSON-ish ObjectManager feature.
	 */
	WoodevYandexMapProvider.prototype._buildFeature = function( group ) {
		return {
			type: 'Feature',
			id: group.key,
			geometry: { type: 'Point', coordinates: [ group.lat, group.lng ] },
			properties: this._buildProperties( group ),
			options: {
				iconLayout: this._iconLayoutClass,
				iconImageSize: ICON_BOX.size,
				iconImageOffset: ICON_BOX.offset,
				iconShape: iconShapeFor( ICON_BOX.offset, ICON_BOX.size ),
			},
		};
	};

	/**
	 * Filled `map-pin` silhouette (Lucide's geometry, ISC-licensed, redrawn as one path) — the
	 * framework's own default marker for the RESTING state (spec V-9), drawn by {@see _renderMarker}
	 * only when the group's type has no `iconHref` configured. Inline SVG, not a file, so
	 * `.woodev-pickup-marker__pin`'s CSS `color` (the merchant's `--woodev-pickup-accent`) reaches
	 * it via `currentColor` — a plugin that ships no icons of its own still gets the merchant's
	 * colour on the map. Filled rather than Lucide's own stroke original: a 1.5px stroke is
	 * invisible against map tiles at 45px.
	 *
	 * @since 2.0.2
	 * @type {string}
	 */
	var PIN_DEFAULT =
		'<svg class="woodev-pickup-marker__pin" data-pin="resting" viewBox="0 0 24 24" ' +
		'aria-hidden="true" focusable="false">' +
		'<path fill="currentColor" d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7z"/>' +
		'<circle cx="12" cy="9" r="2.6" fill="#fff"/></svg>';

	/**
	 * The same pin with a tick in the head (`map-pin-check`) — the framework default for the
	 * ACTIVE state, drawn under the same no-icon condition as {@see PIN_DEFAULT}.
	 *
	 * @since 2.0.2
	 * @type {string}
	 */
	var PIN_ACTIVE =
		'<svg class="woodev-pickup-marker__pin" data-pin="active" viewBox="0 0 24 24" ' +
		'aria-hidden="true" focusable="false">' +
		'<path fill="currentColor" d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7z"/>' +
		'<path d="M9.2 9.1l1.9 1.9 3.7-3.7" stroke="#fff" stroke-width="1.8" fill="none" ' +
		'stroke-linecap="round" stroke-linejoin="round"/></svg>';

	/**
	 * Renders one marker's DOM: an `<img>` for the icon matching the CURRENT `state`
	 * (`iconHrefActive` when `'active'`, `iconHref` otherwise), or the framework's own inline SVG
	 * pin — {@see PIN_DEFAULT}/{@see PIN_ACTIVE} — when that URL is empty (spec V-9), plus a
	 * count badge for a group of more than one point. `data-state` is written onto the root so
	 * Task 21's CSS can key off `[data-state="active"]` for the size/style change
	 * {@see ICON_BOX_ACTIVE}'s hit-box already reserves room for (D-5). Deliberately independent
	 * of ymaps' own layout `build()` machinery — directly unit-testable, matching the pattern the
	 * previous version of this file used for its balloon body.
	 *
	 * @param {HTMLElement} container
	 * @param {Object}      data the layout's `getData()` result (`.properties` is a ymaps
	 *                            get/set data manager).
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._renderMarker = function( container, data ) {
		var properties = ( data && data.properties ) || {};
		var read = readProperty( properties );
		var groupSize = read( 'groupSize' );
		var state = read( 'state' ) || 'resting';
		var isActive = 'active' === state;
		var iconHref = isActive ? read( 'iconHrefActive' ) : read( 'iconHref' );
		var root = ( container && container.querySelector( '.woodev-pickup-marker' ) ) || container;
		var isGroup = groupSize > 1;

		if ( ! root ) {
			return;
		}

		root.setAttribute( 'data-state', state );
		root.classList.toggle( 'woodev-pickup-marker--group', isGroup );
		root.innerHTML = '';

		if ( iconHref ) {
			var img = document.createElement( 'img' );

			img.className = 'woodev-pickup-marker__image';
			img.src = iconHref;
			img.alt = '';
			root.appendChild( img );
		} else {
			// The framework's own default (spec V-9) — never an empty box (see file docblock's
			// "ICONS ARE AN HTML LAYOUT" section).
			root.insertAdjacentHTML( 'beforeend', isActive ? PIN_ACTIVE : PIN_DEFAULT );
		}

		if ( isGroup ) {
			var badge = document.createElement( 'span' );

			badge.className = 'woodev-pickup-marker__badge';
			badge.textContent = String( groupSize );
			root.appendChild( badge );
		}
	};

	/**
	 * Emits `visibleChange` with the keys of every currently-loaded group whose position falls
	 * inside the bounds ACTUALLY VISIBLE to the customer — a plain point-in-rectangle test against
	 * {@see WoodevYandexMapProvider#_groupsByKey}, not a query against ymaps' own object model
	 * (ObjectManager exposes no equivalent of the previous version's `geoQuery(...).searchInside()`
	 * over a plain Clusterer). Called after every {@see setPoints}.
	 *
	 * `getBounds( { useMapMargin: true } )` (live-review round 4, Finding C — operator: "когда мы
	 * раскрываем сайдбар, видим в нём например 10 доступных точек, но на карте по факту видно
	 * только 3 … остальные точки спрятаны за сайдбаром"): the plain `getBounds()` this method used
	 * to call returns the map's FULL canvas rectangle, including the area the static top strip and
	 * the sidebar's own reservation ({@see _buildMap}/{@see setMargin}) cover — so a group sitting
	 * physically underneath either was still counted as "visible" and listed, even though the
	 * customer cannot see it at all. `useMapMargin: true` is the SAME option every camera FIT in
	 * this file already passes to `setBounds()`/`setCenter()`/`panTo()` to keep a MOVE clear of
	 * those areas; passing it to `getBounds()` too asks ymaps for the SAME margin-aware rectangle
	 * as a READ instead, which is the shape to check first — this file makes no attempt to
	 * re-derive the reserved areas' own pixel geometry by hand, unlike a camera fit (which has to
	 * pick a target and a zoom), a bounds READ has nothing to compute beyond asking ymaps for the
	 * number it already knows. UNVERIFIED against a real map (flag on the rig): whether
	 * `getBounds()` actually honours this option the way `setBounds()`/`setCenter()` do, or
	 * silently ignores it and returns the full canvas rectangle regardless — a silent ignore would
	 * degrade to today's (already-shipped, not a regression) full-canvas behaviour, never throw.
	 *
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._emitVisibleChange = function() {
		var groupsByKey = this._groupsByKey;
		var groups = Object.keys( groupsByKey ).map( function( key ) {
			return groupsByKey[ key ];
		} );

		this.emit( 'visibleChange', this._groupsInsideBounds( groups ).map( function( group ) {
			return group.key;
		} ) );
	};

	/**
	 * The groups currently inside the map's MARGIN-AWARE viewport — the one definition of "in
	 * frame" this file has, shared by {@see _emitVisibleChange} (which turns it into the sidebar's
	 * list) and {@see focusAddress} (which turns "none of them" into `nothingNearby`). Extracted
	 * when the second caller appeared (#167) rather than copied: two independent inequality chains
	 * over the same rectangle would be two chances to disagree about what the customer can see.
	 *
	 * `useMapMargin: true` is what makes it the VISIBLE area rather than the full canvas — the
	 * sidebar covers the right-hand strip, and a point hidden under it is not one the customer can
	 * see on the map (they can still read it in the list beside them, which is the list this very
	 * method feeds).
	 *
	 * @since 2.0.2
	 * @param {Array} groups
	 * @returns {Array} the subset of `groups` inside the viewport; never null.
	 */
	WoodevYandexMapProvider.prototype._groupsInsideBounds = function( groups ) {
		var bounds = this.map.getBounds( { useMapMargin: true } );

		if ( ! Array.isArray( bounds ) || 2 !== bounds.length ) {
			return [];
		}

		var minLat = Math.min( bounds[ 0 ][ 0 ], bounds[ 1 ][ 0 ] );
		var maxLat = Math.max( bounds[ 0 ][ 0 ], bounds[ 1 ][ 0 ] );
		var minLng = Math.min( bounds[ 0 ][ 1 ], bounds[ 1 ][ 1 ] );
		var maxLng = Math.max( bounds[ 0 ][ 1 ], bounds[ 1 ][ 1 ] );

		return ( groups || [] ).filter( function( group ) {
			return group.lat >= minLat && group.lat <= maxLat
				&& group.lng >= minLng && group.lng <= maxLng;
		} );
	};

	/**
	 * Filters the drawn groups by type (D-10) via `objectManager.setFilter()` — NEVER a
	 * rebuild; see the file docblock. `codes` of `null`/an empty array clears the filter (every
	 * group matches).
	 *
	 * ymaps calls the filter with ONE argument — the feature itself — not `(objectId, object)`.
	 * Reading a second parameter left `object` permanently `undefined`, so `typeCode` was always
	 * `undefined` too: any non-empty `codes` list filtered out EVERY marker, not just the ones
	 * that did not match. Invisible to every existing test, because the jest stub's own tests
	 * called the stored function with the same wrong two-argument shape the code expected —
	 * matching production's bug rather than ymaps' real signature. Confirmed against a live
	 * `ymaps.ObjectManager` on the rig, not assumed.
	 *
	 * THE PREDICATE TESTS `properties.typeCodes` (plural, {@see groupTypeCodes}) — the group's
	 * FULL set of distinct point types — NOT the singular `properties.typeCode` (live-review
	 * round 3, Finding 1, confirmed on the rig AND independently reported by the operator: "на
	 * карте отображается иконка ПВЗ, если в фильтре отключить Пункт выдачи, то маркер с карты
	 * исчезает, хотя у этой точки есть ещё и Постамат"). `typeCode` alone names only the group's
	 * FIRST point (`pickup-geo.js`'s own `groupByPosition()` convention), so testing against it
	 * hid a WHOLE co-located marker the instant that one point's type was unchecked, even while a
	 * DIFFERENT type inside the SAME group still passed the filter and stayed correctly listed in
	 * the sidebar (`pickup-panels.js` filters per POINT, never per group — the two disagreed). A
	 * group now survives whenever the selected set intersects ANY of its distinct point types.
	 *
	 * `this._activeTypeFilter` is stored SEPARATELY from what the predicate closes over, so
	 * {@see _buildProperties} (called by {@see _refreshMarkerProperties} below, and by every
	 * later {@see setPoints}/{@see _setMarkerState} call) can consult the SAME active filter to
	 * decide what a marker's icon/badge should DISPLAY — `setFilter()` alone only decides whether
	 * ymaps draws a feature at all; it says nothing about what is already rendered for one that
	 * stays visible with a SMALLER surviving subset than before.
	 *
	 * @param {Array|null} codes `type.code`s to show, or `null`/empty for "all types".
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype.setTypeFilter = function( codes ) {
		var list = Array.isArray( codes ) && codes.length > 0 ? codes : null;

		this._activeTypeFilter = list;

		this.objectManager.setFilter( function( object ) {
			if ( ! list ) {
				return true;
			}

			var properties = object && object.properties;
			var typeCodes = properties && Array.isArray( properties.typeCodes ) ? properties.typeCodes : [];

			return typeCodes.some( function( code ) {
				return -1 !== list.indexOf( code );
			} );
		} );

		this._refreshMarkerProperties();
	};

	/**
	 * Re-renders every currently-loaded feature's PROPERTIES (icon, badge count, representative
	 * type) after a filter change (live-review round 3, Finding 1) — `setFilter()` alone only
	 * decides whether ymaps DRAWS a feature at all; it does not touch what is already rendered
	 * for one that stays visible with fewer surviving points than before. A co-located group
	 * whose FIRST point's type just got unchecked must not keep showing that point's icon and the
	 * FULL group's badge count — it must shrink/re-icon to whatever {@see _survivingPoints}
	 * actually kept.
	 *
	 * Never touches the icon HIT-BOX (`setObjectOptions`) — sizing is {@see _setMarkerState}'s own
	 * concern, driven by FOCUS, not by the filter, and a filter change alone never changes which
	 * group is focused. The currently focused group's `state: 'active'` is explicitly preserved
	 * here (`_buildProperties()` itself always returns `'resting'`) rather than letting a filter
	 * change silently revert it to resting.
	 *
	 * A no-op before `_buildObjectManager()` has run (`this.objectManager` null) — mirrors every
	 * other post-destroy/pre-init guard in this file.
	 *
	 * @since 2.0.2
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._refreshMarkerProperties = function() {
		var self = this;
		var objects = this.objectManager && this.objectManager.objects;

		if ( ! objects || 'function' !== typeof objects.setObjectProperties ) {
			return;
		}

		Object.keys( this._groupsByKey ).forEach( function( key ) {
			var group = self._groupsByKey[ key ];
			var properties = self._buildProperties( group );

			if ( key === self._focusedKey ) {
				properties.state = 'active';
			}

			objects.setObjectProperties( key, properties );
		} );
	};

	/**
	 * Steps the map's zoom by a signed amount (Task 14, spec V-13) — the provider-side half of
	 * our own zoom control: two square buttons the panels render at the stage's bottom-left
	 * (Russian Post's look, Yandex.Delivery's position), replacing ymaps' own `ZoomControl`
	 * (deleted from {@see _buildMap}, never re-added — `controls: []` stays). The panels emit
	 * the signed `step` on click; this method owns the actual camera move so map-library
	 * behaviour stays out of the panels file (D-3).
	 *
	 * Clamped to `[MIN_ZOOM, MAX_ZOOM]` — the SAME range `_buildMap()` already constrains ymaps'
	 * own drag/scroll-wheel zoom to — so a spam-click past either edge is a harmless no-op
	 * instead of an out-of-range value ymaps would otherwise reject or clamp itself less
	 * predictably. Animated via `{ duration: 200 }`, matching a native zoom nudge rather than a
	 * jump cut; no other camera move in this file uses this option ({@see focusGroup}'s
	 * `setBounds()` calls animate on their own terms), so the duration is written here, not
	 * pulled from a shared constant nothing else would reference.
	 *
	 * A no-op once `destroy()` has torn the map down (`this.map` is then null) — mirrors every
	 * other post-destroy guard in this file (see {@see setMargin}); a button click racing
	 * `destroy()` must not throw.
	 *
	 * @since 2.0.2
	 * @param {number} step Signed step — the panels only ever emit `+1` (zoom in) or `-1`
	 *                      (zoom out), but any signed integer clamps the same way.
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype.zoomBy = function( step ) {
		if ( ! this.map ) {
			return;
		}

		var target = this.map.getZoom() + step;
		var clamped = Math.min( MAX_ZOOM, Math.max( MIN_ZOOM, target ) );

		this.map.setZoom( clamped, { duration: 200 } );
	};

	/**
	 * Reserves (or releases) the screen area the framework's own sidebar panel covers, via
	 * ymaps' native `map.margin.addArea()`/`removeArea()` (Task 20) — never a plain
	 * `map.margin = [...]` array assignment, which is not this API's shape (see ADR-010).
	 * `right: 0` anchors the area to the right edge; `width` is what actually gives it SIZE —
	 * matching both reference implementations exactly: Yandex.Delivery `widget-map.js` —
	 * `map.margin.addArea({ right: 0, top: 0, width: 320, height: '100%' })`; the Russian Post
	 * bundle — `{right:0,top:0,width:300,height:"100%"}`. Task 20's mount calls this from the
	 * panels' own `listToggle` event; every `setBounds()` camera move in this file that passes
	 * `useMapMargin: true` (see {@see focusGroup}) already reads whatever THIS method most
	 * recently reserved, so an un-clustered point ends up clear of the open panel instead of
	 * centred underneath it.
	 *
	 * GOTCHA (live-review round 2, rig-verified 2026-08-05 — see
	 * `docs-internal/gotchas/ymaps-margin-area-needs-explicit-width.md`): the design spec this
	 * method was originally built against (`docs-internal/specs/2026-08-01-sp5-pickup-map-rework-
	 * design.md` §6) specified `map.margin.addArea({ right: <width>, top: 0, height: '100%' })` —
	 * the panel's pixel WIDTH poured into `right`, which is an OFFSET, with no `width` key at all.
	 * The spec was WRONG, and this method copied it faithfully: `right` is where the area's edge
	 * SITS, not how big it is, so an area with no `width` reserves ZERO pixels. Every
	 * `useMapMargin: true` camera move in this file still resolved and still looked correct in
	 * every jest test (nothing here asserts screen pixels), because the bug is invisible off a
	 * real map — it only shows up as "the focused point centred on the WHOLE map, sidebar or not"
	 * and "ymaps' copyright strip sitting underneath the sidebar panel" on the actual rig. Fixed
	 * to `{ right: 0, top: 0, width: width, height: '100%' }`.
	 *
	 * The previous reservation, if any, is always released FIRST — opening twice in a row,
	 * or closing when nothing is reserved, never leaks a stale area. A no-op before `init()`
	 * has built a map, or once `destroy()` has torn it down (`this.map`/`this.map.margin` is
	 * then null/absent) — mirrors every other post-destroy guard in this file.
	 *
	 * This method owns `this._marginArea` ONLY — the STATIC top-chrome strip
	 * {@see _buildMap} reserves for our own search bar lives in the SEPARATE
	 * `this._topMarginArea` field and is never read, removed, or re-added here (see the file
	 * docblock's "MAP MARGINS" section); the sidebar toggling on and off must never touch it.
	 *
	 * @param {boolean} open  whether the sidebar panel is now open.
	 * @param {number}  width the width of the strip the panel occupies MEASURED FROM THE MAP'S
	 *                        RIGHT EDGE, in CSS pixels — since #168 that is the panel's own width
	 *                        plus its 16px gutter, not the panel's width alone (the caller,
	 *                        `pickup-panels.js`'s `setStageOpen()`, adds it). This method reserves
	 *                        from that edge inwards, so it wants the covered strip, not the
	 *                        element. Ignored when `open` is false.
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype.setMargin = function( open, width ) {
		if ( ! this.map || ! this.map.margin ) {
			return;
		}

		// `map.margin.addArea()` returns an ACCESSOR, and the accessor is what removes the area
		// — there is no `map.margin.removeArea( id )`. Calling one threw
		// `this.map.margin.removeArea is not a function` on the very first sidebar toggle, which
		// killed the toggle handler and left the map in a half-initialised drag state
		// (`map.action.Continuous: ticking while inactive`, repeating forever).
		if ( this._marginArea ) {
			if ( 'function' === typeof this._marginArea.remove ) {
				this._marginArea.remove();
			}

			this._marginArea = null;
		}

		if ( open ) {
			// `right` is an OFFSET from the right edge, not a size — `width` is the size. A build
			// that put `width` INTO `right` (see this method's own docblock for the incident)
			// declared an area with no `width` at all, which ymaps reserves as ZERO pixels: every
			// `useMapMargin: true` camera move in this file still "worked", just against nothing.
			this._marginArea = this.map.margin.addArea( { right: 0, top: 0, width: width, height: '100%' } );
		}
	};

	// -------------------------------------------------------------------------
	// Address search (Task 19, D-6) — see the file docblock's "ADDRESS SEARCH" section
	// -------------------------------------------------------------------------

	/**
	 * Resolves a chosen address SUGGESTION to real coordinates — the ONLY place this file calls
	 * `ymaps.geocode()` from the search flow, and it does so EXACTLY ONCE per selection (see the
	 * file docblock). `displayName` is the suggestion's own resolvable text — the caller (Task
	 * 20's mount) holds the `{ displayName, query }` entry {@see suggestAddresses} returned and
	 * passes back `query` (the FULL, un-trimmed string) when present, falling back to
	 * `displayName` only if `query` is absent.
	 *
	 * DELIBERATELY THE LEAST CONSTRAINED GEOCODE/SUGGEST CALL IN THIS FILE — NO `boundedBy`, NO
	 * `strictBounds` (live-review round 4, SECOND follow-up, reverting a round-4 change that made
	 * this call `strictBounds`-bounded like {@see suggestAddresses}; that change was WRONG and the
	 * operator caught it live: picking a suggestion for an address outside `_loadedBounds()`
	 * silently did NOTHING — `strictBounds: true` made the geocode return ZERO hits, so
	 * `extractGeocodeCoordinates()` saw `null` and this method quietly gave up). This is NOT an
	 * edge case: the customer's own address is ROUTINELY outside the area the loaded pickup
	 * points cover — that is the entire reason they are searching for it in the first place. The
	 * other call in this file ({@see suggestAddresses}) is correctly bounded because it is
	 * offering CANDIDATES near the loaded points; THIS call resolves the ONE the customer has
	 * ALREADY picked, an entirely different job with an entirely different area of interest — the
	 * ambiguity `strictBounds` guards against elsewhere is already handled upstream, since
	 * `displayName` here came from a BOUNDED `suggest()` call and carries its own full
	 * country/locality prefix (see {@see projectSuggestion}'s `query`). See
	 * {@see suggestAddresses}'s own docblock for the full incident (gotcha
	 * `bounding-the-address-resolve-breaks-the-normal-case`) — DO NOT re-add `boundedBy`/
	 * `strictBounds` to this call without re-reading it.
	 *
	 * A geocode that resolves to NOTHING USABLE is NOT a silent no-op (round-4 fix, same
	 * follow-up): it emits `searchResults` with EMPTY arrays, already wired to
	 * `panels.renderSearchResults()`'s own empty-state message. Reusing this EXISTING, ALREADY-
	 * WIRED surface (rather than inventing a new event `pickup-mount.js` would need new wiring
	 * for) closes the "customer picks a row and the map just sits there, no move, no message, no
	 * error" gap the operator found — a REJECTED `ymaps.geocode()` call (network/quota) degrades
	 * the SAME way, matching {@see suggestAddresses}'s own graceful-degradation discipline (an
	 * empty `addresses` array on failure, never a rejection).
	 *
	 * SEQUENCED via `_addressSeq` (live-review round 4 — operator: "иногда когда я пишу адрес и
	 * потом нажимаю на лупу, карта сразу смещается, но даже не к тому адресу что я написал"):
	 * `ymaps.geocode()` is exactly as asynchronous as every camera-adjacent call in this file (the
	 * file docblock's first lesson), and two overlapping calls are NOT guaranteed to resolve in
	 * the order they were issued. A customer who edits the query and submits again before the
	 * FIRST round-trip returns must never have that stale round-trip win — `mySeq` is captured at
	 * call time; the continuation only proceeds (to {@see focusAddress}, OR to the "nothing
	 * found" emit) if `_addressSeq` is still `mySeq` once the geocode settles, one way or the
	 * other. {@see focusAddress} itself also bumps `_addressSeq` on every call (including a call
	 * NOT routed through this method), so any in-flight `resolveAddress()` that started before it
	 * is retroactively marked stale too — one shared sequence for every path that can end in a
	 * camera move for an address, exactly like {@see focusGroup}'s own `_focusSeq`.
	 *
	 * @param {string} displayName the text to geocode — the mount passes the picked suggestion's
	 *                             `query` (preferred) or `displayName` (fallback); this method
	 *                             itself is agnostic to which, it just geocodes whatever it gets.
	 * @returns {Promise<void>}
	 */
	WoodevYandexMapProvider.prototype.resolveAddress = function( displayName ) {
		var self = this;
		var mySeq = ++this._addressSeq;

		function stillCurrent() {
			return ! self._destroyed && mySeq === self._addressSeq;
		}

		return this.ymaps.geocode( displayName ).then( function( result ) {
			if ( ! stillCurrent() ) {
				return undefined;
			}

			var coordinates = extractGeocodeCoordinates( result );

			if ( ! coordinates ) {
				// Resolves to nothing usable — surface it via searchResults' own "nothing found"
				// empty state, rather than a silent no-op the customer reads as "did my click
				// even register?".
				self.emit( 'searchResults', { points: [], addresses: [] } );

				return undefined;
			}

			// The hit's own `boundedBy` is what the camera frames (#167) — the SAME extractor the
			// initial-viewport resolve already uses on the same shape of result, not a second
			// reader of ymaps' geo-object shape.
			return self.focusAddress( coordinates, displayName, extractGeocodeBounds( result ) );
		} ).catch( function() {
			// A rejected geocode (network/quota) degrades the same way a resolved-but-empty one
			// does — matching suggestAddresses()'s own catch discipline.
			if ( stillCurrent() ) {
				self.emit( 'searchResults', { points: [], addresses: [] } );
			}
		} );
	};

	/**
	 * Resolves a searched address against what is actually on the map — NEVER drops a pin of its
	 * own (D4, live-review fix: an earlier version dropped a bare, unstyled `ymaps.Placemark`;
	 * both references never draw one either). Two outcomes, decided by distance to the nearest
	 * currently-loaded group:
	 *
	 * - WITHIN {@see SAME_PLACE_THRESHOLD_M} of a loaded group: the address search is treated as
	 *   having selected that exact POINT (operator requirement, live-review round 2) —
	 *   `addressMatchedPoint( { key } )` fires and this method returns immediately. No
	 *   `addressFocused`, no camera move, no nearest-N fit: the mount (Task 20/T3) wires this
	 *   straight to `focusGroup( key, { zoom: true } )`, which supplies the camera move, the
	 *   active marker state, AND opens the sidebar card — this file never opens a card itself
	 *   (D-3), so it must not race that call with a fit of its own.
	 * - OTHERWISE: `addressFocused( { latLng, label } )` fires and the camera frames `bounds` —
	 *   the geocoder's own `boundedBy` for the hit, so the zoom matches the granularity of what
	 *   was searched for (#167). A hit with no bounds degrades to `latLng` at
	 *   {@see ADDRESS_FALLBACK_ZOOM}. The loaded points do not enter this decision at all; see the
	 *   file docblock's "ADDRESS SEARCH" section for why the previous nearest-N fit was deleted
	 *   rather than tuned.
	 *
	 *   AFTER the move settles, if the frame contains no loaded group ({@see _groupsInsideBounds}),
	 *   `nothingNearby` fires naming the nearest group's distance and (already-`esc_html()`-
	 *   escaped) name, so the customer sees an explicit "nothing here" rather than a silently
	 *   empty viewport. Note the ordering: the camera ALWAYS moves to the address first — the old
	 *   50km threshold decided not to move at all, leaving the customer on the previous viewport
	 *   with no explanation on the map itself. With NO groups currently loaded, `addressFocused`
	 *   and the camera move still happen (the panels' sort anchor still moves) but nothing is
	 *   reported — there is nothing to match or name as "nearest".
	 *
	 * The nearest-N/same-place computation reads ONLY the currently loaded groups
	 * ({@see _groupsByKey}) that have at least one point SURVIVING the active type filter
	 * ({@see _survivingPoints}, live-review round 3, Finding 2) — a group every one of whose
	 * points the filter hides is, from the customer's own point of view, not on the map at all,
	 * so it must never be offered as a same-place match or count toward the nearest-N fit. Without
	 * this, a search could `addressMatchedPoint` a group the sidebar has hidden: the panels would
	 * open a card with nothing behind it on screen, and — because that path returns immediately —
	 * `addressFocused` would never fire either, so the sidebar's own search-anchor never updates.
	 * Same asymmetry as Finding 1: the map-side filter and the list-side filter must agree on what
	 * currently "exists".
	 *
	 * `setBounds()` is ASYNCHRONOUS — this method RETURNS it directly, exactly like
	 * {@see _resolveInitialViewport}'s own successful branch, so a caller that awaits this
	 * promise sees the POST-fit camera, never the pre-fit one (the file docblock's first lesson).
	 * `duration: 400` (live-review fix): this fit can travel from wherever the map currently sits
	 * to the searched address, the same "long move" case {@see setPoints}'s bulk fit and
	 * {@see _resolveInitialViewport} already animate. `useMapMargin: true` (live-review round 4 —
	 * an earlier round of this exact fit MISSED this option, the same class of oversight
	 * Finding 3 caught on the cluster-click zoom) keeps the address clear of the sidebar/top-strip
	 * reservations, matching every other camera move in this file.
	 *
	 * `addressFocused( { latLng, label } )`, when it fires, does so BEFORE the nearest-N
	 * computation below decides whether a fit or a `nothingNearby` follows — it is the seam the
	 * panels' own distance anchor moves through (Task 20's mount wires
	 * `provider.on( 'addressFocused', ( info ) => panels.setAnchor( info.latLng, info.label ) )`),
	 * matching the `searchResults` event's own "this file never calls into pickup-panels.js
	 * directly" discipline (D-3).
	 *
	 * BUMPS `_addressSeq` UNCONDITIONALLY, even when this call did not arrive via
	 * {@see resolveAddress} — see that method's own docblock for the out-of-order-resolution
	 * this guards against. This method's OWN fit is not itself re-checked against the sequence
	 * (there is only ever one `setBounds()` call per invocation here, nothing to race against
	 * itself), only {@see resolveAddress}'s continuation reads the ticket back.
	 *
	 * @param {number[]} latLng `[lat, lng]`, the resolved address location.
	 * @param {string}   label  the address text — used (via `addressFocused`) as the panels'
	 *                          `nearestTo` header label; unused in the `addressMatchedPoint` path.
	 * @returns {Promise<void>}
	 */
	WoodevYandexMapProvider.prototype.focusAddress = function( latLng, label, bounds ) {
		var self = this;

		this._addressSeq += 1;

		var groupsByKey = this._groupsByKey;
		var groups = Object.keys( groupsByKey ).map( function( key ) {
			return groupsByKey[ key ];
		} ).filter( function( group ) {
			// Finding 2 — a group entirely hidden by the active type filter is not a candidate,
			// exactly matching what the sidebar list itself would offer.
			return self._survivingPoints( group ).length > 0;
		} );
		var nearestGroups = geo.nearest( groups, latLng, 1 );
		var closest = nearestGroups.length > 0 ? nearestGroups[ 0 ] : null;
		var closestDistance = closest ? geo.distanceMeters( latLng, [ closest.lat, closest.lng ] ) : null;

		if ( closest && closestDistance <= SAME_PLACE_THRESHOLD_M ) {
			this.emit( 'addressMatchedPoint', { key: closest.key } );

			return Promise.resolve();
		}

		this.emit( 'addressFocused', { latLng: latLng, label: label } );

		// The camera frames the FOUND OBJECT, and nothing else (#167). `bounds` is the geocoder's
		// own `boundedBy` for the hit — a house gives a house-sized box, a street a street, a city
		// a city — so the zoom matches what was actually searched for without this file owning a
		// single zoom number or knowing anything about the points. That is the reference's whole
		// policy: Yandex.Delivery hands search to ymaps' `SearchControl`, which frames the result
		// itself, and has no nearest-N notion anywhere in its file.
		//
		// setBounds() is ASYNCHRONOUS — awaited, exactly like every other camera move here (the
		// file docblock's first lesson). The fallback exists only for a hit with no `boundedBy`
		// at all; a degenerate box under `checkZoomRange` would slam to the deepest zoom the map
		// allows, so a plain centre + street-level zoom is the safer degradation.
		var move = Array.isArray( bounds ) && 2 === bounds.length
			? this.map.setBounds( bounds, { checkZoomRange: true, duration: 400, useMapMargin: true } )
			: this.map.setCenter( latLng, ADDRESS_FALLBACK_ZOOM, { duration: 400, useMapMargin: true } );

		return move.then( function() {
			if ( self._destroyed || ! closest ) {
				return;
			}

			// "Nothing nearby" is a GEOMETRIC fact, decided AFTER the camera settles: can the
			// customer see a point from here or not. It replaced a 50km constant that no one could
			// justify and that, worse, decided whether to MOVE AT ALL — so an address 40km from
			// the nearest point counted as "nearby" and dragged the frame out to contain both.
			// Read through the same margin-aware bounds `_emitVisibleChange()` uses, so "in frame"
			// means the same thing in both places: what is visible BESIDE the sidebar, not what is
			// hidden underneath it.
			if ( self._groupsInsideBounds( groups ).length > 0 ) {
				return;
			}

			var closestPoint = closest.points && closest.points[ 0 ];

			// `key` (Task 20): lets the mount focus/open THIS exact group when the
			// customer accepts the "show it anyway" offer — the group's own identity
			// token, never its (display-only, non-unique) name. See
			// pickup-panels.js's own note on `showNearestRequested`.
			self.emit( 'nothingNearby', {
				key: closest.key,
				distanceMeters: closestDistance,
				name: ( closestPoint && closestPoint.name ) || '',
			} );
		} );
	};

	/**
	 * Clears the address search entirely. The OLD behaviour — emitting an EMPTY `searchResults`
	 * (`{ points: [], addresses: [] }`) — is GONE (D1, live-review fix): it made a genuine clear
	 * indistinguishable from "a real search came back with zero rows", so the panels re-opened
	 * the results box it had just closed. This method now emits a plain `searchCleared` event
	 * instead, so the panels can tell the two apart. The panels' own reset control (`«Сбросить»`)
	 * calls neither this method nor `setAnchor()` itself — it only emits `searchReset`
	 * (`pickup-panels.js`'s own file docblock, "EVENT SEMANTICS"/`anchorCleared` section) — so
	 * THIS method is what Task 20's mount wiring calls when the customer clears the search.
	 * Idempotent: a call with no prior search state is a
	 * safe no-op beyond the unconditional `searchCleared` emit, which every caller can rely on
	 * firing every time. Draws/removes no pin — there has never been one to remove since D4.
	 *
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype.clearAddress = function() {
		this.emit( 'searchCleared', {} );
	};

	/**
	 * Focuses group `key`: marks it visually active (swaps its icon hit-box to
	 * {@see ICON_BOX_ACTIVE}, its icon image to `iconHrefActive`, and `data-state` to
	 * `'active'` — see {@see _setMarkerState} — reverting the previously focused group, if any,
	 * back to resting) and moves the camera onto it. `options.zoom` is the live-review pan/zoom
	 * split (D6) that REPLACES the old "a marker click and a sidebar row click behave
	 * identically" spec §7.5/V-10 rule — that sentence turned out to be wrong for BOTH
	 * references (see the SP-5 live-review plan's own root-cause note): a marker click only pans
	 * (`options.zoom` falsy), a sidebar row/search/nearest-N click pans AND zooms
	 * (`options.zoom === true`). The mount (Task 20/T3) is the one that decides which: it calls
	 * `focusGroup( key, { zoom: 'marker' !== origin } )` from the `cardOpened` event's own
	 * `origin` field.
	 *
	 * THE ZOOM BRANCH (`options.zoom === true`) calls `map.setCenter( <the GROUP's own lat/lng>,
	 * MAX_ZOOM, { useMapMargin: true, duration: 200 } )` — matching the reference implementations'
	 * own sidebar-row centring call exactly (see the plan's "Reference truth" table). The group's
	 * OWN coordinates are the target even when it is currently folded into a cluster: zooming to
	 * MAX_ZOOM there separates it from its cluster-mates just as reliably as zooming to the
	 * cluster's anchor would, and unlike the anchor (an arbitrary OTHER member of the cluster,
	 * which s52's rig pass measured landing 2 km away from the point the customer had actually
	 * picked) it leaves the chosen point on screen — where its marker gets an overlay at all, and
	 * so can carry the `data-state="active"` {@see _applyFocus} writes. The move is skipped —
	 * focus still applies directly — in two cases: every feature in the cluster shares one
	 * coordinate, since no zoom, however deep, could ever separate them (the "Russian Post"
	 * guard, spec §7.5; see the file docblock's second lesson); or `key` has no known group
	 * (defensive — should not happen in practice, since a click always names a group this
	 * provider itself drew). `useMapMargin: true` keeps the point inside the area the panels
	 * leave free via `map.margin`, so it does not end up centred underneath the open sidebar
	 * where the customer cannot see it.
	 *
	 * THE PAN BRANCH (`options.zoom` falsy) calls `map.panTo( target, { useMapMargin: true,
	 * duration: 200 } )` unconditionally whenever a target exists (co-located or not) — matching
	 * both references' marker-click behaviour exactly: pan only, zoom untouched. It keeps TWO
	 * targets: the CLUSTER's anchor when `key` is folded into one — a pan cannot un-cluster
	 * anything (zoom is what separates co-located points, and this branch never touches zoom), so
	 * "show roughly where it is" is the most it can offer — and the GROUP's own `lat`/`lng`
	 * otherwise. Earlier versions of this method moved the camera ONLY in the clustered branch,
	 * which is why a plain marker click visibly did nothing on the rig — that bug was invisible to
	 * every test that exercised it, because none of them ever gave the group its own coordinates
	 * via `setPoints()` first. The co-located guard does not apply to this branch at all, and —
	 * unlike the zoom branch — there is no POST-move re-check to gate: nothing about clustering
	 * could possibly have changed as a result of a move that left zoom alone.
	 *
	 * `attemptedMove` (well, `target`) gates the POST-move re-check for the ZOOM branch only, and
	 * ONLY when the move was an un-clustering attempt (`wasClustered`): a group focused WITHOUT
	 * moving (co-located or unknown) applies immediately, never re-evaluated against a "did the
	 * move actually un-cluster it" check that only makes sense there.
	 *
	 * SEQUENCED against a slower-to-resolve EARLIER call via `_focusSeq`: two ymaps camera moves
	 * are not guaranteed to resolve in the order they were started (animation duration depends
	 * on distance travelled), so a stale continuation must never apply its OWN (now outdated)
	 * focus on top of a more recent call's. `mySeq` captures `_focusSeq` at call time; the
	 * continuation only proceeds if `_focusSeq` is still `mySeq` once the move (or the
	 * synchronous "nothing to move" case) settles. `setCenter()`/`panTo()` are exactly as
	 * asynchronous as `setBounds()` (the file docblock's first lesson) — both are awaited here.
	 *
	 * GATED BEHIND `_cameraFit`, {@see setPoints}'s in-flight `bulk` fit (s52 defect — the
	 * restore-on-reopen path, `pickup-mount.js`'s `restoreSelection()`, focused a group TWO
	 * MILLISECONDS after `setPoints()` and got no visible camera move at all). `map.setBounds()`
	 * is asynchronous in a second, sharper way than the file docblock's first lesson describes:
	 * it does not merely RESOLVE late, it ISSUES its real camera command late — internally it
	 * delegates to `map.setCenter()` only once it has resolved the bounds against the projection,
	 * which the rig measured at ~50 ms after the `setBounds()` call. A `setCenter()` issued in
	 * between therefore STARTS AND FINISHES first and is then overwritten by the fit, which the
	 * customer sees as "the map snapped back". Worse, the snap-back re-clusters the group, and a
	 * clustered feature has no overlay of its own, so the `data-state="active"` {@see _applyFocus}
	 * had already written vanished with it — while `getFocusedKey()` still (correctly) named the
	 * group, which is exactly what made the defect look like a focus that had "no effect".
	 * Waiting for the fit is the ordering signal, never a timer. `_cameraFit` is NULL whenever no
	 * fit is in flight, so the overwhelmingly common case — a click on a settled map — still
	 * issues its camera move synchronously, inside the click's own task.
	 *
	 * @param {string}  key
	 * @param {Object}  [options]
	 * @param {boolean} [options.zoom] `true` centres AND zooms to {@see MAX_ZOOM} (a sidebar row/
	 *                                 search/nearest-N pick); any other value (including omitted)
	 *                                 pans only, zoom untouched (a marker click).
	 * @returns {Promise<void>}
	 */
	WoodevYandexMapProvider.prototype.focusGroup = function( key, options ) {
		var self = this;
		var mySeq = ++this._focusSeq;
		var opts = options || {};
		var wantsZoom = true === opts.zoom;
		var fit = this._cameraFit;

		if ( ! fit ) {
			return this._moveAndFocus( key, wantsZoom, mySeq );
		}

		return fit.then( function() {
			// A focus superseded WHILE it was still waiting for the fit never moves the camera at
			// all — the same staleness rule the post-move continuation applies, one step earlier.
			if ( self._destroyed || mySeq !== self._focusSeq ) {
				return undefined;
			}

			return self._moveAndFocus( key, wantsZoom, mySeq );
		} );
	};

	/**
	 * {@see focusGroup}'s body, past its `_cameraFit` gate — picks the camera target, moves, and
	 * applies the focus. Split out purely so the gate above reads as one decision; every rule it
	 * implements is documented on {@see focusGroup} itself.
	 *
	 * Reading `getObjectState()` HERE, rather than at call time, is deliberate: whether `key` is
	 * clustered is a property of the CURRENT viewport, so the answer is only meaningful once the
	 * fit that is moving that viewport has settled.
	 *
	 * @since 2.0.2
	 * @param {string}  key
	 * @param {boolean} wantsZoom
	 * @param {number}  mySeq the `_focusSeq` value captured when the caller entered
	 *                        {@see focusGroup}.
	 * @returns {Promise<void>}
	 */
	WoodevYandexMapProvider.prototype._moveAndFocus = function( key, wantsZoom, mySeq ) {
		var self = this;
		var state = this.objectManager.getObjectState( key );
		var wasClustered = !! ( state && state.isClustered );
		var group = this._groupsByKey[ key ];
		var mover = Promise.resolve();
		var target = null;

		if ( wantsZoom ) {
			// The group's OWN coordinates, clustered or not — see focusGroup()'s docblock for why
			// the cluster anchor is the wrong place to send a customer who picked THIS point.
			if ( group && ! ( wasClustered && isSingleCoordinateCluster( state.cluster ) ) ) {
				target = [ group.lat, group.lng ];
			}
		} else if ( wasClustered ) {
			target = clusterAnchorCoordinates( state.cluster );
		} else if ( group ) {
			target = [ group.lat, group.lng ];
		}

		if ( target ) {
			mover = wantsZoom
				? this.map.setCenter( target, MAX_ZOOM, { useMapMargin: true, duration: 200 } )
				: this.map.panTo( target, { useMapMargin: true, duration: 200 } );
		}

		return mover.then( function() {
			if ( self._destroyed || mySeq !== self._focusSeq ) {
				return;
			}

			if ( wantsZoom && target && wasClustered ) {
				var settled = self.objectManager.getObjectState( key );

				if ( settled && settled.isClustered && isSingleCoordinateCluster( settled.cluster ) ) {
					return;
				}
			}

			self._applyFocus( key );
		} );
	};

	/**
	 * Applies the visual "focused" state to `key` and reverts the previously focused group (if
	 * any, and if different) back to its resting state.
	 *
	 * @param {string} key
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._applyFocus = function( key ) {
		var previous = this._focusedKey;

		if ( previous && previous !== key ) {
			this._setMarkerState( previous, 'resting' );
		}

		this._setMarkerState( key, 'active' );
		this._focusedKey = key;
	};

	/**
	 * Sets one feature's icon hit-box AND, when the group's own data is still known
	 * (`_groupsByKey`), its rendered state (`data-state` and which icon URL is shown — D-5),
	 * via `objectManager.objects.setObjectOptions()`/`setObjectProperties()` — the documented
	 * way to update an already-added feature in place, without a `removeAll()`/`add()` rebuild.
	 *
	 * The hit-box updates UNCONDITIONALLY (it needs no group data, only the fixed box
	 * constants); the properties update is skipped when the group is unknown — defensive only,
	 * `setPoints()` always populates `_groupsByKey` before any `focusGroup()` a real caller
	 * would issue.
	 *
	 * `iconShape` (spec V-9) is re-sent alongside the box on every call — the active box is a
	 * different rectangle than the resting one, so a shape left describing the small box would
	 * leave a focused marker clickable only across part of its own (now larger) artwork. See
	 * gotcha `ymaps-html-icon-layout-needs-iconshape.md`.
	 *
	 * @param {string} key
	 * @param {string} state `'active'` or `'resting'`.
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype._setMarkerState = function( key, state ) {
		var objects = this.objectManager && this.objectManager.objects;

		if ( ! objects ) {
			return;
		}

		var box = 'active' === state ? ICON_BOX_ACTIVE : ICON_BOX;

		if ( 'function' === typeof objects.setObjectOptions ) {
			objects.setObjectOptions( key, {
				iconImageSize: box.size,
				iconImageOffset: box.offset,
				iconShape: iconShapeFor( box.offset, box.size ),
			} );
		}

		var group = this._groupsByKey[ key ];

		if ( group && 'function' === typeof objects.setObjectProperties ) {
			var properties = this._buildProperties( group );

			properties.state = state;

			objects.setObjectProperties( key, properties );
		}
	};

	/**
	 * The group key {@see focusGroup} most recently, successfully focused — `null` before the
	 * first call, or once that group is no longer among the currently drawn ones (see
	 * {@see setPoints}).
	 *
	 * @returns {string|null}
	 */
	WoodevYandexMapProvider.prototype.getFocusedKey = function() {
		return this._focusedKey;
	};

	/**
	 * Tears the provider down: removes the object manager, destroys the map, resets every
	 * internal collection. Idempotent — a second call, or a call before `init()`/`focusGroup()`
	 * has settled, is a safe no-op (every async continuation checks `_destroyed` first).
	 *
	 * @returns {void}
	 */
	WoodevYandexMapProvider.prototype.destroy = function() {
		if ( this._destroyed ) {
			return;
		}

		this._destroyed = true;

		if ( this.map ) {
			if ( this.objectManager ) {
				this.map.geoObjects.remove( this.objectManager );
			}

			try {
				this.map.destroy();
			} catch ( e ) {
				// Defensive — a map already torn down by ymaps itself must not fail this call.
			}
		}

		// Only remove the node when this provider created it. When the panels own it, tearing it
		// out would delete the stage's map element and a reopen would mount into nothing.
		if ( this.ownsCanvasEl && this.canvasEl && this.canvasEl.parentNode ) {
			this.canvasEl.parentNode.removeChild( this.canvasEl );
		}

		this.canvasEl = null;
		this.ownsCanvasEl = false;
		this.map = null;
		this.objectManager = null;
		this.ymaps = null;
		this.container = null;
		this._iconLayoutClass = null;
		this._groupsByKey = {};
		this._focusedKey = null;
		this._activeTypeFilter = null;
		// Nothing may still be gated behind a fit belonging to a map that no longer exists — the
		// continuations all check `_destroyed`, but a torn-down provider holding a forever-pending
		// promise would keep any later focusGroup() waiting on it for good.
		this._cameraFit = null;
		this.searchControl = null;
		this._marginArea = null;
		// `this.map.destroy()` above already tears down every margin reservation along with the
		// rest of the map — this just forgets the stale accessor, matching `_marginArea`'s own
		// treatment right above; there is no separate `.remove()` call to make here either.
		this._topMarginArea = null;
		this._zoomLimits = null;
		this.handlers = {
			pointClick: [],
			clusterClick: [],
			boundsChange: [],
			bboxTooWide: [],
			visibleChange: [],
			zoomChange: [],
			nothingNearby: [],
			searchResults: [],
			searchCleared: [],
			addressFocused: [],
			addressMatchedPoint: [],
			error: [],
		};
	};

	// -------------------------------------------------------------------------
	// UMD-ish dual export
	// -------------------------------------------------------------------------

	// Browser global
	if ( typeof window !== 'undefined' ) {
		window.WoodevPickupMapProviders = window.WoodevPickupMapProviders || {};
		window.WoodevPickupMapProviders.yandex = WoodevYandexMapProvider;
	}

	// CommonJS (jest)
	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = WoodevYandexMapProvider;
	}

}() );
