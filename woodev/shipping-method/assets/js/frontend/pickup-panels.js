/**
 * Woodev Pickup Panels — the framework-owned presentation layer that sits
 * ON TOP of a pickup map provider: the viewport list, the point card, and
 * (Task 15/16) the search view and type filter. A map provider (Yandex,
 * embedded, …) draws ONLY the map surface — clustering, placemarks, the
 * camera — and hands groups/points to this object through its public
 * methods; it never renders point details itself (D-4 operator rejection of
 * the ymaps balloon: information about a point belongs in a side panel, not
 * a balloon owned by one specific map library).
 *
 * A "group" is `{ key, lat, lng, typeCode, size, points: [...] }`, the shape
 * `pickup-geo.js`'s `groupByPosition()` returns — several co-located points
 * (a PVZ and a postamat in the same building) arrive as ONE group. Panels
 * receive groups, never bare points.
 *
 * ESCAPING (two rules, opposite directions — see the project's own gotcha on
 * this): point display fields (`name`, `address`, `short_address`,
 * `locality`, `instruction`, `work_time`, `payment_methods`, `services`,
 * `type.label`, …) arrive from PHP ALREADY `esc_html()`-escaped
 * (`Pickup_Point::to_browser_array()`) and are written into `innerHTML`
 * AS-IS, so the browser's own parser decodes the entity (a point named
 * `ПВЗ "Ромашка"` round-trips correctly). i18n labels and
 * `point.selectable.reason` are the OPPOSITE: plain, unescaped strings, never
 * concatenated into an HTML string here — every place this file shows one, it
 * is assigned via `textContent`, which is inherently injection-safe and needs
 * no entity decoding (it was never escaped to begin with). Mixing these two
 * up in either direction is exactly the bug class both rules exist to
 * prevent: showing the customer a literal `&quot;`, or opening a markup hole
 * through an i18n string a filter could return unsanitised.
 *
 * A MISSING i18n KEY RENDERS BLANK, NEVER A HARDCODED RUSSIAN DEFAULT that
 * happens to read the same — see {@see text} — because a fallback would mask
 * a PHP/JS i18n-key mismatch instead of surfacing it (the same I1 rule
 * `pickup-mount.js` and `map-provider-yandex.js` already carry).
 *
 * ACCENT COLOUR is delivered on `config.accentColor` (top level, not inside
 * `mapConfig` — Task 8B/D-15) and applied through the CSSOM via
 * `pickup-geo.js`'s `safeColor()`/`contrastFor()`, never a generated
 * `<style>` block or a string-built `style=` attribute — see {@see applyAccentColor}.
 * The value is already sanitised server-side; the client re-validates too,
 * deliberately, because a filter can return garbage and the server check is
 * not authoritative on the client.
 *
 * EVENT SEMANTICS: `on( event, cb )` ADDS a listener — a second `on()` call
 * for the same event fires BOTH callbacks, it never replaces the first. This
 * matches a plain DOM-style emitter and avoids a caller silently losing a
 * handler it registered earlier; Task 20 (the mount wiring) relies on this.
 *
 * `cardOpened` (Task 10, spec V-10 — REVISED in the SP-5 round-2 live review, see the plan's D6):
 * emitted by `openCard()` for EVERY route to a card — a marker click, a sidebar row, a search
 * result, "show the nearest", the reopen-restore — carrying `{ group, pointId, origin }`. `origin`
 * is the caller's own label for which route this was
 * (`'marker'|'list'|'search'|'nearest'|'restore'`); every internal call site
 * in THIS file passes `'list'` (the sidebar row builders, below) — a search-result pick and
 * "show the nearest" route through `openCard()` from OUTSIDE this file (the mount, in response to
 * `searchPointPicked`/`searchAddressPicked`/`showNearestRequested`), so THEIR label is that
 * caller's responsibility, not this file's. `cardOpened` exists so a listener can react to "this
 * point became the subject" without caring who asked, AND — this is what `origin` is actually FOR —
 * so it can tell a marker click apart from every other route: round-2's live rig review found that
 * neither reference treats them identically the way the original V-10 sentence claimed. A marker
 * click only PANS the camera (zoom untouched); a sidebar row, a search pick, and "show nearest" all
 * zoom in; `'restore'` (the mount reopening on a previously chosen point, 06.08.2026) moves the
 * camera not at all, since `setPoints( groups, { focus } )` already did that BEFORE the draw.
 * `map-provider-yandex.js`'s `focusGroup( key, { zoom: 'marker' !== origin } )` is what
 * reads this field. The original V-10 text ("a marker click and a sidebar row click must behave
 * identically") is WRONG and this file does not restore it. Emitted BEFORE the card renders, so the
 * asynchronous camera flight and the synchronous DOM land together rather than the map lurching
 * after the card is already readable.
 *
 * `anchorCleared` (Task 20, D-6): before this event existed, `setAnchor( null )` emitted NOTHING
 * of its own — a caller polling for "did the customer just clear their search" had no signal to
 * poll (the reset control that used to call `setAnchor( null )` for the customer was deleted in
 * Task 7, spec V-11, along with the header it lived in). Task 11's search layout reset button
 * (below) does NOT call `setAnchor( null )` itself — it only emits `searchReset`; translating that
 * into an actual `setAnchor( null )` call is the mount's job (Task 20), so the event contract here
 * still does not depend on where the call comes from. `setAnchor( null )` emits this event EVERY time it is
 * called with a falsy `latLng`, so the mount can drop whatever provider-side state belongs to the
 * search (Task 20's mount wires this straight to the map provider's own `clearAddress()`, which is
 * what actually removes the "your address" pin — see `map-provider-yandex.js`'s own docblock on
 * why THAT file, not this one, owns dropping it). `setAnchor( latLng )` with a non-null value
 * never fires it.
 *
 * SORTING HAS ONE RULE, NOT TWO MODES: both the list and any future search
 * result are ordered by distance from a SINGLE anchor point set via
 * `setAnchor()` — the map centre by default, a searched address when a
 * search is active (Task 15 sets it the same way). Distances render only
 * when an anchor is set; with no anchor, the list keeps the caller-supplied
 * order verbatim (deterministic — it never reshuffles on its own between
 * calls with the same input).
 *
 * THE RENDERED LIST IS CAPPED AT {@see LIST_CAP} ITEMS — silently dropping the tail is not
 * acceptable (spec), so the cap keeps the HEAD of the caller-supplied/sorted order, never an
 * arbitrary subset of it; there is no header left to surface a `{cap}+` count through (Task 7,
 * spec V-11), so this is now purely a rendering-cost guard, not a customer-visible statement.
 *
 * TAB LABELS (co-located groups, D-4): one tab per point, labelled by
 * `type.label`; the WHOLE group falls back to `name` labels the moment ANY
 * two points in it share a `type.label` — never a per-point decision, or the
 * tabs of one group would read inconsistently (spec).
 *
 * THE SEARCH VIEW (Task 15, D-6; layout by Task 11, spec V-6): `renderSearchResults( { points,
 * addresses } )` renders TWO independent sections — matching points from the already-loaded
 * pool, and address suggestions from the geocoder — each OMITTED ENTIRELY (not shown as an empty
 * heading) when its own list is empty; when BOTH are empty it renders `text( config, 'noResults' )`
 * instead of an empty box. Picking a point emits `searchPointPicked` with the point id; picking an
 * address emits `searchAddressPicked` with its INDEX into the caller's own result array, never the
 * address object itself, because the caller (Task 12's `SearchControl` wiring) is the one holding
 * the geocoder response and resolving it. A geocoder `displayName` is untrusted, runtime,
 * third-party text — UNLIKE a point field, it is NOT pre-escaped — so it is written via
 * `textContent`, never `innerHTML`; point fields inside the same results stay on the usual
 * already-escaped/`innerHTML` side of the split.
 *
 * THE RESULTS BOX LIFECYCLE (SP-5 round 2, plan D1a/D1e) — it used to never close: the map
 * provider's `clearAddress()` used to emit an EMPTY `searchResults`, which this file's own
 * `renderSearchResults()` dutifully un-hid and filled with `noResults` — the clear round-trip
 * re-opened the box it had just been told to close. The provider now emits `searchCleared` instead
 * (see `map-provider-yandex.js`) and the mount wires that to {@see Panels.prototype.hideSearchResults}
 * — a SEPARATE method from `renderSearchResults()`, never routed through it — so an empty result
 * from a genuinely completed search (which DOES still show `noResults`, unchanged) and "the search
 * was cleared" (which shows nothing at all) can no longer be confused for one another.
 * `hideSearchResults()` empties and hides the box and is the ONE thing every closing route calls:
 * picking a point, picking an address (both below), the reset button's own click handler,
 * `focusout` of the search wrap once focus leaves it ENTIRELY — a `relatedTarget` outside the wrap
 * (a NULL `relatedTarget`, focus leaving the document altogether — an alt-tab, a click on browser
 * chrome — deliberately does NOT count as leaving; treating it as "leave" would blank the results
 * out from under a customer who only switched tabs mid-search) — and, round 4, a `click` anywhere
 * OUTSIDE the search wrap: `focusout` alone missed the customer's actual dismissal gesture, a
 * click on the MAP, which does not move DOM focus at all. See
 * {@see Panels.prototype.buildSearchLayout}'s own docblock for the outside-click listener, which
 * reuses the exact pattern {@see ensureFilterEl} already established for the filter menu.
 *
 * THE CLEAR GLYPH (round 2, D1c) is an inline `<svg>` authored in THIS file
 * ({@see CLEAR_ICON_SVG} — Lucide's `x` geometry, ISC-licensed, redrawn, same convention
 * `map-provider-yandex.js` set with `PIN_DEFAULT`/`PIN_ACTIVE`) — not the CSS `content: '\2715'`
 * emoji it replaces, which the operator called "стиль аля web 2000".
 *
 * NO SUBMIT BUTTON (operator, 07.08.2026). The field used to carry a magnifier, kept sane by a
 * three-reason disabled state machine. With live suggestions on screen its only job was "guess the
 * best match for me", which is strictly weaker than picking the row you actually want — and it was
 * never the only way in, because the magnifier and Enter were ONE path (the form's `submit`
 * event). Removing the button removed a control, not a capability: Enter still resolves the top
 * suggestion, and a phone keyboard's own "Перейти" key submits the form the same way. The
 * `updateSubmitState`/`_searchSubmitSpent`/`setSearchBusy` machinery existed only to service the
 * button and went with it; re-entry while a round trip is in flight is guarded by the mount, which
 * is what owns that round trip.
 *
 * The results container itself (`this._searchResults`) is NOT built by `render()` any more — it is
 * one of the elements {@see Panels.prototype.buildSearchLayout} builds inside its own detached
 * `SearchControl` layout (Task 11 REPLACED the plain `.woodev-pickup-search` div `render()` used to
 * append as a sibling of the list body: two parallel search UIs would have meant two things a
 * customer could type into). `renderSearchResults()` is therefore a no-op until
 * `buildSearchLayout()` has been called at least once — the layout is built on demand (Task 12
 * hands it to ymaps, which decides where it actually lives), so calling it before that must not
 * throw.
 *
 * `setAnchor( latLng, label )`'s second argument still has no DOM effect (Task 7, spec V-11, deleted
 * the list header it used to feed, along with the reset control the header carried — neither
 * reference has one, and the header stated something the customer could already see). V-11's
 * replacement for that reset control is Task 11's search-field reset button (below), which clears
 * the INPUT, not the anchor label; feeding `label` back into the input's own displayed value (so a
 * searched address re-shows there) is left to the mount (Task 20) — `label` today is stored only so
 * the anchor itself keeps sorting the list.
 * `showNothingNearby( { distanceMeters, name } )` is the explicit "empty map"
 * state (never a silently empty result): it names the nearest point and its
 * distance and offers to show it anyway, rather than leaving the customer to
 * conclude there are no points at all.
 *
 * THE TYPE FILTER (Task 16, reworked Task 13, reworked again SP-5 round 2 — D2/D-10/V-8):
 * `setTypes( types )` accumulates distinct `{ code, label }` pairs FIRST-SEEN across every call
 * and renders the filter control once a SECOND distinct type has ever been seen — and, once shown,
 * it never disappears again, even if a later call reports only one type (a momentary single-type
 * viewport must not flicker the control away). The last CHECKED type cannot be unchecked (the
 * Yandex reference's own rule): the click is silently refused — the checkbox is reverted and no
 * `typeFilterChange` fires — because an empty selection would read to the customer as "no pickup
 * points exist" (see the file docblock's own operator-instruction note, immediately below the
 * reference's opposite "empty means unfiltered" behaviour is deliberately NOT copied). Every row
 * (`.woodev-pickup-filter__row`) carries `data-checked="true"|"false"`, kept in sync with its own
 * checkbox on every accepted change, for T4's styling to key off.
 *
 * THE BADGE'S 3+ RULE (round 2, D2 — the operator's own live-review finding): the TOGGLE
 * (`.woodev-pickup-filter__toggle`) carries `.is-filtered` whenever the selection is PARTIAL
 * (strictly fewer selected than known), full stop — that alone is the whole "something is
 * filtered" signal. The numeric count badge is a SEPARATE, stricter thing: it shows only once
 * there are 3+ known types AND the selection is partial. With exactly two known types (this
 * plugin's own fixture: `PVZ`, `POSTAMAT`) "partial" can only ever mean "1 of 2 selected" — the
 * badge is arithmetically incapable of ever reading as anything but a permanently-stuck "1", which
 * is precisely what the operator saw and reported as broken. `typeFilterChange` carries the
 * selected codes as a plain array; whether that becomes a client-side filter or a server refetch
 * is the caller's decision (Task 20), not this file's.
 *
 * THE FILTER ALSO GATES WHICH POINTS THIS FILE RENDERS (round 3, coordinator fix — the rig found
 * "the filter applies to the map but not to the sidebar list"): the map provider filters by
 * GROUP — a group's type is its first point's type — so a co-located group holding, say, a PVZ
 * and a postomat correctly STAYS on the map once `POSTAMAT` is unchecked (the group still has a
 * visible PVZ); but the individual postomat POINT inside that group must stop being offered
 * anywhere a customer could still pick it. That is point-level filtering, and this file is the
 * only place that renders individual points, so {@see pointPassesFilter}/{@see filterGroupPoints}
 * live here, not in the map provider. Both the sidebar list ({@see buildListItem}/
 * {@see renderListBody} — a group with zero surviving points renders no row) and the card's tab
 * bar ({@see buildTabs}, {@see renderCard}'s own fallback-or-close logic) apply the same rule.
 * `handleFilterCheckboxChange()` re-renders the list body and any open card SYNCHRONOUSLY on
 * every accepted change — never waits for the next `setVisible()` viewport update, since a
 * customer un-checking a type with no map movement in between must see the list update
 * immediately, not on the next pan/zoom.
 *
 * THE CONTROL'S HOME (Task 13, spec V-8): the filter is one button (`.woodev-pickup-filter__toggle`,
 * carrying the badge) plus one hidden dropdown menu (`.woodev-pickup-filter__menu`) — Russian
 * Post's own shape — and it is built LAZILY, the first time `setTypes()` ever sees a second
 * distinct type, never eagerly in `render()`. Where it attaches depends on whether
 * {@see Panels.prototype.buildSearchLayout} ever ran and actually built a control (it returns
 * `null` when the plugin disabled search, spec V-6): when it did, the filter becomes a SIBLING of
 * `.woodev-pickup-search` inside that SAME detached layout — one `SearchControl`, two menus,
 * neither owning the other's geometry — because that is genuinely how the reference wires it
 * (`state`, not two independently-positioned ymaps controls). When search is disabled the filter
 * has no search layout to live beside, so it falls back to being appended to the list panel
 * instead (its Task 16 home) — a carrier with a locked-down geocoding budget but two-or-more point
 * types is a real combination (spec), and "no control at all" is worse than "the control lives
 * somewhere slightly different". Opening either menu closes the other, matching the reference's
 * `menu--open` behaviour — see `buildSearchLayout()`'s toggle handler and `renderSearchResults()`.
 *
 * TWO i18n KEYS, TWO DIFFERENT JOBS: `allTypes` labels the TOGGLE BUTTON itself (an
 * always-present, accessible name for "the point-type filter control" — PHP already reserves this
 * key for the Task 13/14 map-provider scripts, see `class-pickup-handler.php`), `filterTypes`
 * titles the MENU once it opens (Task 16's original, unchanged key/string). Neither is a fallback
 * for the other; a missing key still renders blank per rule I1.
 *
 * UMD-ish dual export (matches every sibling SP-5 frontend file):
 *   - Browser global: window.WoodevPickupPanels = Panels
 *   - CommonJS:       module.exports = Panels  (for jest)
 *
 * @file
 * @since 2.0.2
 */

( function() {
	'use strict';

	/**
	 * `pickup-geo.js`'s exports — read off `window` when it was loaded as a
	 * sibling `<script>` (the real, enqueued browser case: Task 20 declares
	 * it a hard dependency), otherwise required directly by relative path —
	 * the case a jest test exercises when it requires this module WITHOUT
	 * first requiring `pickup-geo.js` itself for its `window` side effect.
	 *
	 * @type {Object}
	 */
	var geo = ( 'undefined' !== typeof window && window.WoodevPickupGeo ) ||
		( 'function' === typeof require ? require( './pickup-geo' ) : null );

	/** @type {number} maximum number of groups actually rendered into the list body — see the file docblock. */
	var LIST_CAP = 300;

	/** @type {string} fallback text colour, used only when `config.accentColor` is absent/unsafe. */
	var DEFAULT_ACCENT = '#06aedd';

	/**
	 * @type {number} debounce (ms) between the last keystroke and the `searchType` event —
	 * {@see Panels.prototype.buildSearchLayout}.
	 */
	var SEARCH_DEBOUNCE_MS = 300;

	/**
	 * @type {string[]} the `showMessage()` keys with nothing to retry — see that method's own
	 * docblock for why these three are the exception rather than the rule.
	 */
	var NO_RETRY_MESSAGE_KEYS = [ 'emptyLocality', 'emptyInView', 'zoomIn' ];

	/** @type {number} minimum query length before point matching fires at all (spec V-6). */
	var SEARCH_MIN_CHARS = 3;

	/**
	 * The gap between the floating panels' right edge and the stage's own right edge (#168) —
	 * `pickup.css`'s `right: 16px` on `.woodev-pickup-list`/`.woodev-pickup-card`, matching the
	 * search field's own `16px` inset. Mirrored here because {@see setStageOpen} reports the strip
	 * the panel occupies MEASURED FROM THE STAGE EDGE, which is the panel's width plus this gap;
	 * that number becomes ymaps' `map.margin` reservation, and a reservation smaller than the thing
	 * it stands for is the `ymaps-margin-area-needs-explicit-width` defect all over again.
	 *
	 * Applied unconditionally, INCLUDING below the ≤782px breakpoint where the media query zeroes
	 * the gutter and the panel goes full-bleed again — deliberately, not by oversight. Reading the
	 * breakpoint from JS would duplicate a number the stylesheet owns, and there is nothing to
	 * gain: at that width the panel already covers the entire map, so the reservation is degenerate
	 * (no usable area either way) and 16px past the map's own edge changes nothing anyone can see.
	 *
	 * @type {number}
	 */
	var PANEL_GUTTER_PX = 16;

	/**
	 * Lucide's `filter` glyph (ISC-licensed), used as-is — the same "redraw/reuse a Lucide shape"
	 * convention `map-provider-yandex.js` established for the marker pins (spec V-9). Purely
	 * decorative: `currentColor` inherits the toggle button's own text colour, and the button
	 * carries its own `aria-label` (see {@see Panels.prototype.buildSearchLayout}) rather than
	 * relying on the glyph to convey meaning.
	 *
	 * @type {string}
	 */
	var FILTER_ICON_SVG = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">' +
		'<path fill="currentColor" d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/>' +
		'</svg>';

	/**
	 * Lucide's `x` glyph (ISC-licensed, redrawn) — the reset button's icon (D1c), replacing the CSS
	 * `content: '\2715'` (✕) glyph.
	 *
	 * @since 2.0.2
	 * @type {string}
	 */
	var CLEAR_ICON_SVG = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" ' +
		'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' +
		'<path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';

	/**
	 * The framework's own built-in point-type glyphs (issue #171, operator decision) — the
	 * sidebar list row and the point card's chip now ALWAYS show one of these, replacing the
	 * old "plugin supplies a marker URL or nothing renders" contract (see {@see pointGlyphMarkup}).
	 * A teardrop marker pin is a POINTER AT A COORDINATE; shrunk into a list row there is
	 * nothing left to point at, so the framework draws two flat, square glyphs instead —
	 * `warehouse` (a staffed point) and `package` (a parcel locker) — geometry taken verbatim
	 * from Lucide (ISC-licensed: https://lucide.dev/icons/warehouse,
	 * https://lucide.dev/icons/package; https://github.com/lucide-icons/lucide, `icons/
	 * warehouse.svg` / `icons/package.svg`), matching the "redraw/reuse a Lucide shape"
	 * convention {@see FILTER_ICON_SVG}/{@see CLEAR_ICON_SVG} above and the map's own marker
	 * pins already established.
	 *
	 * Deliberately carry NO `width`/`height` attribute of their own, unlike the two constants
	 * above — this pair renders at TWO different sizes (the list row's icon, the card's larger
	 * chip), so the CONSUMING element's own CSS sizes the svg (`width: 100%; height: 100%` on
	 * `.woodev-pickup-list__icon svg` / `.woodev-pickup-card__chip-icon svg`, pickup.css) rather
	 * than a fixed intrinsic size baked into shared markup that would be wrong for one of the two.
	 * `stroke="currentColor"`, `fill="none"` — square, transparent background, and readable in
	 * whatever text colour the consuming element sets, exactly like every other icon in this file.
	 *
	 * @since 2.0.2
	 * @type {Object<string, string>}
	 */
	var GLYPH_SVG = {
		warehouse: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
			'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' +
			'<path d="M18 21V10a1 1 0 0 0-1-1H7a1 1 0 0 0-1 1v11"/>' +
			'<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8a2 2 0 0 1 1.132-1.803l7.95-3.974a2 2 0 0 1 ' +
			'1.837 0l7.948 3.974A2 2 0 0 1 22 8z"/>' +
			'<path d="M6 13h12"/>' +
			'<path d="M6 17h12"/>' +
			'</svg>',
		'package': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
			'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' +
			'<path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 ' +
			'4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/>' +
			'<path d="M12 22V12"/>' +
			'<polyline points="3.29 7 12 12 20.71 7"/>' +
			'<path d="m7.5 4.27 9 5.15"/>' +
			'</svg>'
	};

	/**
	 * The glyph key {@see pointGlyphMarkup} falls back to for every type unless a plugin says
	 * otherwise (issue #171) — a carrier's type CODE (`PVZ`, `POSTAMAT`, whatever a carrier
	 * invents) is arbitrary domain vocabulary, and sniffing it for a substring like
	 * "POSTAMAT"/"LOCKER"/"TERMINAL" is exactly the kind of guess over someone else's naming
	 * this framework refuses to make (mirrors the file docblock's "framework owns mechanism,
	 * plugin owns domain" line). Every type reads as a staffed pickup point unless
	 * `Pickup_Handler`'s `woodev_pickup_map_point_glyphs` filter names it otherwise.
	 *
	 * @since 2.0.2
	 * @type {string}
	 */
	var DEFAULT_GLYPH = 'warehouse';

	/**
	 * @type {number} minimum number of known point types before the filter badge shows a NUMBER at
	 * all — see the file docblock's "THE BADGE'S 3+ RULE" note (D2). Below this, `.is-filtered` on
	 * the toggle carries the whole "something is filtered" signal by itself.
	 */
	var FILTER_BADGE_MIN_TYPES = 3;

	/**
	 * A single detached element reused by {@see decodeForTitle} — same technique, same
	 * once-created/never-attached lifetime as `pickup-geo.js`'s own `decodeEl`. `null` outside a
	 * DOM environment; `decodeForTitle()` degrades to returning its input unchanged rather than
	 * throwing.
	 *
	 * @type {HTMLElement|null}
	 */
	var titleDecodeEl = ( 'undefined' !== typeof document ) ? document.createElement( 'div' ) : null;

	// -------------------------------------------------------------------------
	// Small pure helpers
	// -------------------------------------------------------------------------

	/**
	 * Reads an i18n string off the config — empty string when absent/blank,
	 * NEVER a JS-side hardcoded default. See the file docblock's I1 note.
	 *
	 * @param {Object} config
	 * @param {string} key
	 * @returns {string}
	 */
	function text( config, key ) {
		var i18n = ( config && config.i18n ) || {};

		return 'string' === typeof i18n[ key ] && i18n[ key ].length > 0 ? i18n[ key ] : '';
	}

	/**
	 * Returns `value` when it is a non-empty-worth-rendering string, `''`
	 * otherwise — the guard every "write this ALREADY-escaped point field
	 * into innerHTML" call below shares.
	 *
	 * @param {*} value
	 * @returns {string}
	 */
	function fieldValue( value ) {
		return 'string' === typeof value ? value : '';
	}

	/**
	 * Decodes HTML entities in an already-escaped point field for use in a PLAIN-TEXT ATTRIBUTE
	 * (`title`) — `innerHTML`, this file's usual write target for these fields (see the file
	 * docblock), is what actually decodes an entity; `setAttribute()` never re-parses its
	 * argument as markup, so writing the raw escaped string straight into `title` would show the
	 * customer a literal `&quot;` in a tooltip instead of `"`. `pickup-geo.js` has the identical
	 * round-trip in its own `decodeEntities()`, but that helper is module-private there (never
	 * part of the `WoodevPickupGeo` export), so this is a small local duplicate for the one place
	 * this file needs it.
	 *
	 * @param {*} value
	 * @returns {string}
	 */
	function decodeForTitle( value ) {
		var raw = fieldValue( value );

		if ( '' === raw || ! titleDecodeEl ) {
			return raw;
		}

		titleDecodeEl.innerHTML = raw; // eslint-disable-line -- server-escaped; read back via textContent below.

		return titleDecodeEl.textContent;
	}

	/**
	 * Removes every child of `el` — the shared "fully rebuild this subtree"
	 * primitive every render function below uses, so a re-render (a new
	 * viewport, a tab switch) never leaves a stale node from the previous
	 * pass behind.
	 *
	 * @param {HTMLElement} el
	 * @returns {void}
	 */
	function empty( el ) {
		while ( el.firstChild ) {
			el.removeChild( el.firstChild );
		}
	}

	/**
	 * Builds one card section: an i18n title over its content, separated from the section above
	 * it by a rule (spec V-12).
	 *
	 * Replaces the previous inline label-then-value row, which ran every detail together as one
	 * unbroken block of small text — the operator's whole complaint about the card ("сейчас всё
	 * одним сплошным текстом, всё ужато"). Both references give each fact its own titled block.
	 *
	 * The escaping split from the file docblock lives here, in one place: `title` is an i18n
	 * string — plain, unescaped, `textContent` — while `content` is an element the caller has
	 * already built from ALREADY-escaped point fields.
	 *
	 * @since 2.0.2
	 * @param {string}      title   i18n label (unescaped; written via textContent).
	 * @param {HTMLElement} content the section's body element.
	 * @returns {HTMLElement}
	 */
	function cardSection( title, content ) {
		var section = document.createElement( 'div' );
		section.className = 'woodev-pickup-card__section';

		var titleEl = document.createElement( 'div' );
		titleEl.className = 'woodev-pickup-card__section-title';
		titleEl.textContent = title;

		content.className = 'woodev-pickup-card__section-content ' + content.className;

		section.appendChild( titleEl );
		section.appendChild( content );

		return section;
	}

	/**
	 * Wraps ALREADY-escaped point-field HTML in a plain element, for {@see cardSection}'s content.
	 *
	 * @since 2.0.2
	 * @param {string} cls  modifier class for the value element.
	 * @param {string} html already-escaped point field.
	 * @returns {HTMLElement}
	 */
	function cardValue( cls, html ) {
		var el = document.createElement( 'div' );
		el.className = cls;
		el.innerHTML = html; // eslint-disable-line -- server-escaped, see file docblock.

		return el;
	}

	// -------------------------------------------------------------------------
	// Accent colour — CSSOM only, see the file docblock
	// -------------------------------------------------------------------------

	/**
	 * Sets the two accent CSS custom properties on `root`, through the CSSOM
	 * (`style.setProperty`) — never a generated `<style>` block, never a
	 * string-built `style=` attribute (D-15). Re-validates `config.accentColor`
	 * client-side via `pickup-geo.js`'s `safeColor()` even though the server
	 * already sanitised it: a filter returning garbage bypasses the server
	 * check, and this client check is not authoritative on its own either —
	 * both layers are deliberate.
	 *
	 * @param {HTMLElement} root
	 * @param {Object}      config
	 * @returns {void}
	 */
	function applyAccentColor( root, config ) {
		if ( ! geo ) {
			return;
		}

		var accent = geo.safeColor( config && config.accentColor, DEFAULT_ACCENT );

		root.style.setProperty( '--woodev-pickup-accent', accent );
		root.style.setProperty( '--woodev-pickup-accent-contrast', geo.contrastFor( accent ) );
	}

	// -------------------------------------------------------------------------
	// List panel
	// -------------------------------------------------------------------------

	/**
	 * Orders `groups` for display: by ascending distance from `anchor` when
	 * one is set, otherwise left in the caller's own order (see the file
	 * docblock's "SORTING HAS ONE RULE" note) — never mutates its argument.
	 *
	 * @param {Array}      groups
	 * @param {number[]|null} anchor
	 * @returns {Array}
	 */
	function orderGroups( groups, anchor ) {
		if ( ! anchor ) {
			return groups.slice();
		}

		return groups
			.map( function( g, index ) {
				return { group: g, index: index, distance: geo.distanceMeters( anchor, [ g.lat, g.lng ] ) };
			} )
			.sort( function( a, b ) {
				return a.distance - b.distance || a.index - b.index;
			} )
			.map( function( ranked ) {
				return ranked.group;
			} );
	}

	/**
	 * The framework-owned glyph markup for a point's type, for the sidebar list row and the
	 * point card's chip (issue #171, replacing the old `pointIconUrl()` this function used to
	 * be) — ALWAYS returns something renderable, never `''`. `config.pointIcons` (a
	 * plugin-supplied MARKER url) is deliberately never read here any more: that map drives
	 * the MAP's own pins (map furniture, spec V-9) and stays exactly that; this surface is a
	 * SEPARATE contract, `config.pointGlyphs` (`Pickup_Handler::normalized_point_glyphs()`).
	 *
	 * `pointGlyphs[ code ].markup`, when present, is ALREADY sanitised server-side
	 * ({@see wp_kses()} on the PHP side) and is written via `innerHTML` verbatim by this
	 * function's two callers — same discipline as every other server-escaped field this file
	 * writes (see the file docblock's escaping rule). `pointGlyphs[ code ].glyph` selects the
	 * framework's OTHER built-in ({@see GLYPH_SVG}) instead. A type with no override, or whose
	 * override the server dropped as unsafe/unusable, falls back to {@see DEFAULT_GLYPH} —
	 * never a guess at the carrier's own type-code vocabulary.
	 *
	 * @since 2.0.2
	 * @param {Object} config
	 * @param {Object} point
	 * @returns {string} SVG markup, safe to assign to `innerHTML` as-is.
	 */
	function pointGlyphMarkup( config, point ) {
		var code = ( point && point.type && point.type.code ) || '';
		var glyphs = ( config && config.pointGlyphs ) || {};
		var override = Object.prototype.hasOwnProperty.call( glyphs, code ) ? glyphs[ code ] : null;

		if ( override && 'string' === typeof override.markup && override.markup.length > 0 ) {
			return override.markup;
		}

		var key = ( override && 'string' === typeof override.glyph && override.glyph.length > 0 )
			? override.glyph
			: DEFAULT_GLYPH;

		return GLYPH_SVG[ key ] || GLYPH_SVG[ DEFAULT_GLYPH ];
	}

	/**
	 * Whether `point` currently PASSES the type filter (round 3 coordinator fix — "the filter
	 * applies to the map but not to the sidebar list"). The map provider filters by GROUP — a
	 * group's type is its first point's type, per `map-provider-yandex.js`'s own contract — so a
	 * co-located group holding, say, a PVZ and a postomat correctly stays on the map once its type
	 * is unchecked (the group still has a visible PVZ), but the individual EXCLUDED point inside it
	 * must stop being offered here — that is point-level filtering, and the panels are the only
	 * place that renders individual points, so it lives here rather than in the provider.
	 *
	 * A point whose type carries no code, or whose code the filter has never heard of (no
	 * `setTypes()` call ever reported it), passes by default — there is nothing to filter it BY,
	 * and refusing to show it would be a bug of its own (an unfilterable point silently vanishing).
	 *
	 * @since 2.0.2
	 * @param {Panels} self
	 * @param {Object} point
	 * @returns {boolean}
	 */
	function pointPassesFilter( self, point ) {
		var code = point && point.type && point.type.code;

		if ( ! code || ! Object.prototype.hasOwnProperty.call( self._filterSelected, code ) ) {
			return true;
		}

		return Boolean( self._filterSelected[ code ] );
	}

	/**
	 * The SUBSET of `group.points` currently passing the type filter — see {@see pointPassesFilter}.
	 * Used both by the list ({@see buildListItem}/{@see renderListBody}: a group with zero visible
	 * points renders no row at all) and by the card's tab bar ({@see buildTabs}: no tab for an
	 * excluded point). Never mutates `group.points` itself.
	 *
	 * @since 2.0.2
	 * @param {Panels} self
	 * @param {Object} group
	 * @returns {Array}
	 */
	function filterGroupPoints( self, group ) {
		return group.points.filter( function( point ) {
			return pointPassesFilter( self, point );
		} );
	}

	/**
	 * Builds one list row for a single-point group (spec V-11): the framework's own type glyph
	 * (issue #171 — ALWAYS rendered now, see {@see pointGlyphMarkup}), address in bold,
	 * name/description as the muted subtitle, and — when an anchor is set — the formatted
	 * distance. Icon, then address, then name is the order the spec asks for: the address is
	 * what the customer scans the list FOR, the name/description is secondary detail.
	 * `short_address`/`address`/`name` are already-escaped point fields (see the file
	 * docblock) and are written via `innerHTML` here, same as everywhere else in this file; the
	 * `title` attributes carry the DECODED text instead (see {@see decodeForTitle}) because an
	 * HTML attribute value is never re-parsed as markup the way `innerHTML` is, so the raw
	 * escaped string would show literal entities on hover.
	 *
	 * @param {Object}        point
	 * @param {number[]|null} anchor
	 * @param {Object}        group
	 * @param {string}        locale
	 * @param {Object}        config
	 * @returns {HTMLElement}
	 */
	function buildSinglePointRow( point, anchor, group, locale, config ) {
		var wrap = document.createDocumentFragment();

		var icon = document.createElement( 'span' );
		icon.className = 'woodev-pickup-list__icon';
		icon.setAttribute( 'aria-hidden', 'true' );
		icon.innerHTML = pointGlyphMarkup( config, point ); // eslint-disable-line -- framework constant or server-sanitised markup, see pointGlyphMarkup's own docblock.
		wrap.appendChild( icon );

		var addressEl = document.createElement( 'span' );
		addressEl.className = 'woodev-pickup-list__address';
		addressEl.innerHTML = fieldValue( point.short_address ) || fieldValue( point.address ); // eslint-disable-line -- server-escaped.
		addressEl.setAttribute( 'title', decodeForTitle( point.address ) );
		wrap.appendChild( addressEl );

		var nameEl = document.createElement( 'span' );
		nameEl.className = 'woodev-pickup-list__name';
		nameEl.innerHTML = fieldValue( point.name ); // eslint-disable-line -- server-escaped.
		nameEl.setAttribute( 'title', decodeForTitle( point.name ) );
		wrap.appendChild( nameEl );

		if ( anchor ) {
			var meters = geo.distanceMeters( anchor, [ group.lat, group.lng ] );
			var distanceEl = document.createElement( 'span' );
			distanceEl.className = 'woodev-pickup-list__distance';
			distanceEl.textContent = geo.formatDistance( meters, locale );
			wrap.appendChild( distanceEl );
		}

		return wrap;
	}

	/**
	 * Builds one list item for a group: a single clickable row for a
	 * single-point group, or one clickable sub-row per point for a
	 * co-located group — each opens the CARD on the point it represents
	 * (never always the first), which is what a click on the second point of
	 * a shared row has to do (see the file docblock's tab-bar note).
	 *
	 * `group.key`/`point.id` are identity tokens, assigned via the DOM
	 * property API (`dataset`), never concatenated into an HTML string —
	 * they are never "display text" and so the point-field escaping rule
	 * does not apply to them (mirrors `map-provider-yandex.js`'s own note
	 * that `point.id` is deliberately never rendered).
	 *
	 * Round 3 (coordinator fix): renders only the points that PASS the type filter (see
	 * {@see filterGroupPoints}) — never `group.points` directly. The caller
	 * ({@see renderListBody}) already guarantees at least one point survives the filter before
	 * calling this, so `points[ 0 ]` below is always defined; whether the SHAPE is "one row" or
	 * "one sub-row per point" now depends on the SURVIVING count, not the group's raw point count
	 * — a co-located group reduced to exactly one visible point renders as a plain single row, not
	 * a one-item sub-row list.
	 *
	 * @param {Panels} self
	 * @param {Object} group
	 * @returns {HTMLElement}
	 */
	function buildListItem( self, group ) {
		var item = document.createElement( 'div' );
		item.className = 'woodev-pickup-list__item';
		item.dataset.groupKey = group.key;

		var locale = self._config.lang;
		var anchor = self._anchor;
		var points = filterGroupPoints( self, group );

		/*
		 * "Selected" lives HERE and in the CTA's label — never as a third marker state on the
		 * map. The plugin-facing icon contract is exactly two images per type
		 * (`pointIcons: { typeCode: { default, active } }`); a third would oblige every plugin
		 * to draw one for every point type, a breaking change to an outward contract for a
		 * nuance this row already carries permanently. On the map, `active` means FOCUSED.
		 */
		var selectedId = self._selectedId;

		if ( points.length > 1 ) {
			points.forEach( function( point ) {
				var button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'woodev-pickup-list__point'
					+ ( null !== selectedId && String( point.id ) === selectedId ? ' is-selected' : '' );
				button.dataset.pointId = String( point.id );
				button.appendChild( buildSinglePointRow( point, anchor, group, locale, self._config ) );
				button.addEventListener( 'click', function() {
					self.openCard( group, point.id, 'list' );
				} );
				item.appendChild( button );
			} );

			return item;
		}

		var onlyPoint = points[ 0 ];
		item.appendChild( buildSinglePointRow( onlyPoint, anchor, group, locale, self._config ) );
		item.addEventListener( 'click', function() {
			self.openCard( group, onlyPoint.id, 'list' );
		} );

		if ( null !== selectedId && String( onlyPoint.id ) === selectedId ) {
			item.classList.add( 'is-selected' );
		}

		return item;
	}

	/**
	 * Rebuilds the list body: the empty state, or up to {@see LIST_CAP}
	 * ordered items — never more, see the file docblock.
	 *
	 * Round 3 (coordinator fix): a group with ZERO points passing the type filter
	 * ({@see filterGroupPoints}) renders NO row at all — filtered before capping, so a run of
	 * excluded groups never squeezes a visible one out of the {@see LIST_CAP} window. This can
	 * never make the WHOLE list empty on its own — the last selected type can never be unchecked
	 * (see the file docblock's "THE TYPE FILTER" note), so at least one type, and therefore at
	 * least one point somewhere in `self._groups`, always survives — which is why this function
	 * still only shows `emptyInView` for the pre-existing "nothing in the viewport at all" case
	 * below, never a second "everything got filtered" state that cannot occur.
	 *
	 * @param {Panels} self
	 * @returns {void}
	 */
	function renderListBody( self ) {
		empty( self._listBodyEl );

		if ( 0 === self._groups.length ) {
			var emptyEl = document.createElement( 'div' );
			emptyEl.className = 'woodev-pickup-list__empty';
			emptyEl.textContent = text( self._config, 'emptyInView' );
			self._listBodyEl.appendChild( emptyEl );

			return;
		}

		var ordered = orderGroups( self._groups, self._anchor );
		var visible = ordered.filter( function( group ) {
			return filterGroupPoints( self, group ).length > 0;
		} );
		var capped = visible.slice( 0, LIST_CAP );

		capped.forEach( function( group ) {
			self._listBodyEl.appendChild( buildListItem( self, group ) );
		} );
	}

	/**
	 * Rebuilds the list panel's body from current state. A thin alias today — kept as its own
	 * function (rather than inlined at each of its three call sites: `render()`, `setAnchor()`,
	 * `setVisible()`) because it used to also rebuild the header (Task 7, spec V-11, deleted
	 * that half outright: no reference has one, and it stated something the customer could
	 * already see). The search view (Task 11) does not hook in here — it lives in its own
	 * detached `SearchControl` layout, not inside the list panel this function rebuilds.
	 *
	 * @param {Panels} self
	 * @returns {void}
	 */
	function renderList( self ) {
		renderListBody( self );
	}

	// -------------------------------------------------------------------------
	// Search view (Task 15, D-6)
	// -------------------------------------------------------------------------

	/**
	 * Builds one clickable row for a matching POOL point in the search
	 * results: name and short address, both written via `innerHTML` — these
	 * are the same already-`esc_html()`-escaped point fields the list panel
	 * renders, not new/untrusted strings. Clicking emits `searchPointPicked`
	 * with the point's id, so the caller can open its card the same way a
	 * list-row click does, then closes the results box (round 2, D1e — see
	 * {@see Panels.prototype.hideSearchResults}).
	 *
	 * @param {Panels} self
	 * @param {Object} point
	 * @returns {HTMLElement}
	 */
	function buildSearchPointItem( self, point ) {
		var item = document.createElement( 'div' );
		item.className = 'woodev-pickup-search__item woodev-pickup-search__item--point';
		item.dataset.pointId = String( point.id );

		var nameEl = document.createElement( 'span' );
		nameEl.className = 'woodev-pickup-search__name';
		nameEl.innerHTML = fieldValue( point.name ); // eslint-disable-line -- server-escaped, see file docblock.

		var addressEl = document.createElement( 'span' );
		addressEl.className = 'woodev-pickup-search__address';
		addressEl.innerHTML = fieldValue( point.short_address ); // eslint-disable-line -- server-escaped.

		item.appendChild( nameEl );
		item.appendChild( addressEl );

		item.addEventListener( 'click', function() {
			self._emit( 'searchPointPicked', point.id );

			// Round 2, D1e: a pick closes the results box — it must not linger over the map once
			// the customer has already told this file which point they mean.
			self.hideSearchResults();
		} );

		return item;
	}

	/**
	 * Builds one clickable row for a geocoder address suggestion:
	 * `address.displayName`, written via `textContent` — UNLIKE a point
	 * field, this string arrives at runtime from a third-party geocoder and
	 * is NOT pre-escaped, so `innerHTML` here would open exactly the markup
	 * hole the file docblock's escaping rule exists to prevent (see the
	 * "THE SEARCH VIEW" note). Clicking emits `searchAddressPicked` with
	 * `index` — the position in the caller's OWN results array — never the
	 * address object itself, since the caller (Task 12) is the one holding
	 * that array and resolving it — then closes the results box (round 2,
	 * D1e — see {@see Panels.prototype.hideSearchResults}), same as a point
	 * pick above.
	 *
	 * @param {Panels} self
	 * @param {Object} address `{ displayName, ... }`, untrusted geocoder shape.
	 * @param {number} index
	 * @returns {HTMLElement}
	 */
	function buildSearchAddressItem( self, address, index ) {
		var item = document.createElement( 'div' );
		item.className = 'woodev-pickup-search__item woodev-pickup-search__item--address';
		item.dataset.index = String( index );

		var nameEl = document.createElement( 'span' );
		nameEl.className = 'woodev-pickup-search__display-name';
		nameEl.textContent = ( address && 'string' === typeof address.displayName ) ? address.displayName : '';

		item.appendChild( nameEl );

		item.addEventListener( 'click', function() {
			self._emit( 'searchAddressPicked', index );

			// Round 2, D1e: same as a point pick, above — closes the box rather than leaving it
			// open over the map once the customer has picked one of its suggestions.
			self.hideSearchResults();
		} );

		return item;
	}

	/**
	 * Builds one search-results section — a heading plus its items — or
	 * returns `null` when `items` is empty, so a section with no results is
	 * OMITTED ENTIRELY rather than rendered as an empty heading (spec).
	 *
	 * @param {string}   modifier   BEM modifier, e.g. 'points' or 'addresses'.
	 * @param {string}   heading    i18n section label.
	 * @param {Array}    items
	 * @param {Function} buildItem  `function( item, index ): HTMLElement`.
	 * @returns {HTMLElement|null}
	 */
	function buildSearchSection( modifier, heading, items, buildItem ) {
		if ( ! items || 0 === items.length ) {
			return null;
		}

		var section = document.createElement( 'div' );
		section.className = 'woodev-pickup-search__section woodev-pickup-search__section--' + modifier;

		var title = document.createElement( 'div' );
		title.className = 'woodev-pickup-search__section-title';
		title.textContent = heading;
		section.appendChild( title );

		items.forEach( function( item, index ) {
			section.appendChild( buildItem( item, index ) );
		} );

		return section;
	}

	// -------------------------------------------------------------------------
	// Point card
	// -------------------------------------------------------------------------

	/**
	 * Formats a GRAMS weight limit as kilograms with two decimals — matches
	 * `map-provider-yandex.js`'s own `formatWeightKg()` exactly, so the two
	 * places a weight limit can appear on screen never disagree. No unit
	 * suffix: the `maxWeight` i18n label is expected to carry it.
	 *
	 * @param {number} grams
	 * @returns {string}
	 */
	function formatWeightKg( grams ) {
		return ( grams / 1000 ).toFixed( 2 );
	}

	/**
	 * Builds the card's tab bar for a co-located group, or `null` when there is nothing to switch
	 * between — either a single-point group to begin with (D-4), or a co-located one reduced to a
	 * single VISIBLE point by the type filter (round 3 coordinator fix: a tab for an excluded
	 * point would let the customer switch straight back to the thing they just filtered out).
	 * Tabs are labelled by `type.label`; the shown SUBSET falls back to `name` the moment ANY two
	 * of the VISIBLE points share a label — never a per-point decision, and never influenced by a
	 * label collision that only exists among points the filter has already hidden (see the file
	 * docblock).
	 *
	 * `index` in the click handler below is the point's REAL index into `group.points` (not its
	 * position among the visible subset) — `self._activeIndex` is that same indexing scheme
	 * everywhere else in this file (`openCard()`'s own point-id lookup, {@see renderCard}), so this
	 * function must not renumber it just because some points are hidden.
	 *
	 * @param {Panels} self
	 * @param {Object} group
	 * @returns {HTMLElement|null}
	 */
	function buildTabs( self, group ) {
		var visibleIndexes = [];

		group.points.forEach( function( point, index ) {
			if ( pointPassesFilter( self, point ) ) {
				visibleIndexes.push( index );
			}
		} );

		if ( visibleIndexes.length <= 1 ) {
			return null;
		}

		var typeLabels = visibleIndexes.map( function( index ) {
			var point = group.points[ index ];

			return ( point.type && 'string' === typeof point.type.label ) ? point.type.label : '';
		} );
		var seen = {};
		var hasCollision = false;

		typeLabels.forEach( function( label ) {
			if ( Object.prototype.hasOwnProperty.call( seen, label ) ) {
				hasCollision = true;
			}

			seen[ label ] = true;
		} );

		var labels = hasCollision
			? visibleIndexes.map( function( index ) {
				return fieldValue( group.points[ index ].name );
			} )
			: typeLabels;

		var tabs = document.createElement( 'div' );
		tabs.className = 'woodev-pickup-card__tabs';

		visibleIndexes.forEach( function( index, position ) {
			var tab = document.createElement( 'button' );
			tab.type = 'button';
			tab.className = 'woodev-pickup-card__tab' + ( index === self._activeIndex ? ' is-active' : '' );
			tab.innerHTML = labels[ position ]; // eslint-disable-line -- server-escaped point field, see file docblock.
			tab.addEventListener( 'click', function() {
				self._activeIndex = index;
				renderCard( self );
			} );
			tabs.appendChild( tab );
		} );

		return tabs;
	}

	/**
	 * Builds the card's header: a close control that ALWAYS renders (this is the customer's only
	 * way back to the list without dismissing the whole modal, spec §6 STATE 3) plus the icon chip
	 * (Task 15, spec V-12 — issue #171: now ALSO ALWAYS renders, via the SAME
	 * {@see pointGlyphMarkup} lookup the sidebar row uses, so both surfaces agree on what glyph
	 * a point's type gets) — both on a FIRST inner row, `.woodev-pickup-card__header-row` — and
	 * the tab bar (only for a co-located group;
	 * {@see buildTabs} returns `null` for a single-point one) on its OWN row below that, still
	 * inside `.woodev-pickup-card__header`.
	 *
	 * ROUND 4 (operator live-review): before this split, the chip, tabs, and close control all
	 * competed for ONE row, and a real type label ("Пункт выдачи заказов") did not fit inside a
	 * segmented-control button squeezed between the two — his own words: "текст «Пункт выдачи
	 * заказов» не помещается в кнопку". Giving the tab bar the header's FULL width on its own row
	 * fixes that; `.woodev-pickup-card__header-row` is the ONLY new class this round introduces —
	 * every existing class (`.woodev-pickup-card__chip`, `.woodev-pickup-card__close`,
	 * `.woodev-pickup-card__tabs`/`__tab`/`.is-active`) is unchanged, since the CSS agent is
	 * styling those directly. This function only restructures the DOM; it does not style anything
	 * itself — that stays the CSS agent's half.
	 *
	 * Named via the EXISTING `close` i18n key (the same one the modal shell's own close button
	 * already uses), not an invented one — see the file docblock's I1 note and the toggle button's
	 * own `aria-label` for the identical discipline.
	 *
	 * @param {Panels} self
	 * @param {Object} group
	 * @param {Object} point the point currently shown in the body (the active tab, if any).
	 * @returns {HTMLElement}
	 */
	function buildCardHeader( self, group, point ) {
		var header = document.createElement( 'div' );
		header.className = 'woodev-pickup-card__header';

		var headerRow = document.createElement( 'div' );
		headerRow.className = 'woodev-pickup-card__header-row';

		// The chip (spec V-12, issue #171: ALWAYS renders now). It shares {@see pointGlyphMarkup}
		// with the sidebar row builder rather than a second lookup, so both surfaces agree on
		// exactly which glyph a point's type gets.
		var chip = document.createElement( 'div' );
		chip.className = 'woodev-pickup-card__chip';
		chip.setAttribute( 'aria-hidden', 'true' );

		var chipIcon = document.createElement( 'span' );
		chipIcon.className = 'woodev-pickup-card__chip-icon';
		chipIcon.innerHTML = pointGlyphMarkup( self._config, point ); // eslint-disable-line -- framework constant or server-sanitised markup, see pointGlyphMarkup's own docblock.
		chip.appendChild( chipIcon );

		headerRow.appendChild( chip );

		var close = document.createElement( 'button' );
		close.type = 'button';
		close.className = 'woodev-pickup-card__close';
		close.setAttribute( 'aria-label', text( self._config, 'close' ) );
		close.textContent = '✕'; // decorative; aria-label carries the meaning (matches woodev-modal.js's close button).
		close.addEventListener( 'click', function() {
			self.closeCard();
		} );
		headerRow.appendChild( close );

		header.appendChild( headerRow );

		// The tab bar (round 4) is now a SIBLING of the row above, not a child squeezed inside it
		// between the chip and the close button — see this function's own docblock.
		var tabs = buildTabs( self, group );

		if ( tabs ) {
			header.appendChild( tabs );
		}

		return header;
	}

	/**
	 * Builds the card body for one point: title, optional postal code, then one titled section
	 * per populated field, IN THIS FIXED ORDER (spec V-12) — Адрес (with "how to get there" as a
	 * `<details>` inside it, not its own section), Способы оплаты, Услуги, Телефон, Часы работы,
	 * Ограничение веса. A field that is empty means its whole section is OMITTED, never rendered
	 * with a blank body — see {@see cardSection}'s own callers, each guarding its own `if`.
	 *
	 * Replaces the previous flat label-then-value rows this ran together as one unbroken block
	 * of small text — the operator's own words on the card before this task: "всё одним сплошным
	 * текстом, всё ужато".
	 *
	 * @param {Object} config
	 * @param {Object} point
	 * @returns {HTMLElement}
	 */
	function buildCardBody( config, point ) {
		var body = document.createElement( 'div' );
		body.className = 'woodev-pickup-card__body';

		var title = document.createElement( 'div' );
		title.className = 'woodev-pickup-card__title';
		title.innerHTML = fieldValue( point.name ); // eslint-disable-line -- server-escaped.
		body.appendChild( title );

		if ( fieldValue( point.postal_code ) ) {
			var postal = document.createElement( 'div' );
			postal.className = 'woodev-pickup-card__postal';
			postal.innerHTML = fieldValue( point.postal_code ); // eslint-disable-line -- server-escaped.
			body.appendChild( postal );
		}

		// Fixed order, spec V-12: Адрес → Способы оплаты → Услуги → Телефон → Часы работы →
		// Ограничение веса. A section whose value is empty is OMITTED entirely — never rendered
		// with a blank body — which is `cardSection()`'s callers each guarding their own `if`.
		if ( fieldValue( point.address ) ) {
			var addressContent = cardValue( 'woodev-pickup-card__address', fieldValue( point.address ) );

			if ( fieldValue( point.instruction ) ) {
				var howto = document.createElement( 'details' );
				howto.className = 'woodev-pickup-card__howto';

				var summary = document.createElement( 'summary' );
				summary.className = 'woodev-pickup-card__howto-summary';
				summary.textContent = text( config, 'howToGet' );

				var howtoContent = document.createElement( 'div' );
				howtoContent.innerHTML = fieldValue( point.instruction ); // eslint-disable-line -- server-escaped.

				howto.appendChild( summary );
				howto.appendChild( howtoContent );
				addressContent.appendChild( howto );
			}

			body.appendChild( cardSection( text( config, 'address' ), addressContent ) );
		}

		if ( Array.isArray( point.payment_methods ) && point.payment_methods.length > 0 ) {
			var paymentsValue = point.payment_methods.map( fieldValue ).join( ', ' );

			body.appendChild( cardSection(
				text( config, 'paymentMethods' ),
				cardValue( 'woodev-pickup-card__payments', paymentsValue )
			) );
		}

		if ( Array.isArray( point.services ) && point.services.length > 0 ) {
			var services = document.createElement( 'div' );
			services.className = 'woodev-pickup-card__services';

			point.services.forEach( function( service ) {
				var chip = document.createElement( 'span' );
				chip.className = 'woodev-pickup-card__service';
				chip.innerHTML = fieldValue( service ); // eslint-disable-line -- server-escaped.
				services.appendChild( chip );
			} );

			body.appendChild( cardSection( text( config, 'services' ), services ) );
		}

		if ( fieldValue( point.phone ) ) {
			body.appendChild( cardSection(
				text( config, 'phone' ),
				cardValue( 'woodev-pickup-card__phone', fieldValue( point.phone ) )
			) );
		}

		if ( fieldValue( point.work_time ) ) {
			body.appendChild( cardSection(
				text( config, 'workTime' ),
				cardValue( 'woodev-pickup-card__worktime', fieldValue( point.work_time ) )
			) );
		}

		if ( null !== point.max_weight && undefined !== point.max_weight ) {
			var weightValue = document.createElement( 'div' );
			weightValue.className = 'woodev-pickup-card__weight';
			weightValue.textContent = formatWeightKg( point.max_weight ); // computed, plain text — not a point field.

			body.appendChild( cardSection( text( config, 'maxWeight' ), weightValue ) );
		}

		return body;
	}

	/**
	 * Builds the card's sticky footer: the "not selectable" warning (omitted
	 * entirely when the point IS selectable) and the CTA, whose label
	 * depends on whether this point is already the caller's selected one.
	 * `selectable.reason` is NOT a pre-escaped point field (see the file
	 * docblock) — it and the `blocked` i18n fallback are both written via
	 * `textContent`, never concatenated into markup.
	 *
	 * A disabled CTA is genuinely inert: the click listener itself checks
	 * `allowed` before emitting, so a disabled button can never emit
	 * `select` even if something external clicked it programmatically —
	 * `disabled` on the element and "the handler refuses" are two different
	 * guarantees, and both are needed (spec).
	 *
	 * NOT the only writer of `.woodev-pickup-card__warning` any more —
	 * {@see Panels.prototype.showSelectionError} writes into this same slot directly, for a
	 * transient transport failure, without going through a full render.
	 *
	 * @param {Panels} self
	 * @param {Object} point
	 * @returns {HTMLElement}
	 */
	function buildCardFooter( self, point ) {
		var footer = document.createElement( 'div' );
		footer.className = 'woodev-pickup-card__footer';

		var selectable = point.selectable || { allowed: true, reason: null };

		if ( ! selectable.allowed ) {
			var warning = document.createElement( 'div' );
			warning.className = 'woodev-pickup-card__warning';
			warning.textContent = selectable.reason || text( self._config, 'blocked' );
			footer.appendChild( warning );
		}

		var isSelected = null !== self._selectedId && String( point.id ) === self._selectedId;

		var cta = document.createElement( 'button' );
		cta.type = 'button';
		cta.className = 'woodev-pickup-card__cta' + ( self._selectionBusy ? ' is-busy' : '' );
		cta.textContent = self._selectionBusy
			? text( self._config, 'confirming' )
			: ( isSelected ? text( self._config, 'continueCheckout' ) : text( self._config, 'select' ) );
		cta.disabled = ! selectable.allowed || self._selectionBusy;
		cta.addEventListener( 'click', function() {
			/*
			 * Two guards, not one, exactly as the pre-existing `selectable.allowed` guard is
			 * doubled by the `disabled` attribute: `disabled` is presentation, the refusal
			 * here is behaviour, and a programmatic `.click()` respects only the second.
			 */
			if ( ! selectable.allowed || self._selectionBusy ) {
				return;
			}

			self._emit( 'select', point );
		} );
		footer.appendChild( cta );

		return footer;
	}

	/**
	 * Fully rebuilds the card from `self`'s current `_activeGroup`/
	 * `_activeIndex`/`_selectedId` — a no-op when no group is open. Called on
	 * every `openCard()`, tab click, and `setSelectedId()` while a card is
	 * open, so the CTA/warning/tabs always reflect the CURRENTLY active
	 * point, never a stale one left over from a previous render. Also called from
	 * `handleFilterCheckboxChange()` (round 3, coordinator fix) so an OPEN card reacts to the
	 * customer's own filter change immediately, not only on the next `setVisible()`.
	 *
	 * Round 3: if the point at `_activeIndex` has since been filtered out (its type was
	 * unchecked while the card was showing it — see {@see pointPassesFilter}), this falls back to
	 * the first STILL-VISIBLE point in the same group, updating `_activeIndex` to match, rather
	 * than rendering a card for a point the customer just excluded. If the group has no visible
	 * point left at all — every one of its types deselected, which can happen to one particular
	 * GROUP even though the LAST SELECTED TYPE GLOBALLY can never be unchecked (see the file
	 * docblock's "THE TYPE FILTER" note: a different group can keep that last type alive) — the
	 * card closes instead of showing an excluded point or an empty box.
	 *
	 * @param {Panels} self
	 * @returns {void}
	 */
	function renderCard( self ) {
		empty( self._cardEl );

		var group = self._activeGroup;

		if ( ! group ) {
			return;
		}

		var point = group.points[ self._activeIndex ] || group.points[ 0 ];

		if ( ! pointPassesFilter( self, point ) ) {
			var fallbackIndex = -1;

			for ( var i = 0; i < group.points.length; i++ ) {
				if ( pointPassesFilter( self, group.points[ i ] ) ) {
					fallbackIndex = i;
					break;
				}
			}

			if ( -1 === fallbackIndex ) {
				// Nothing left in this group passes the filter — close rather than show an
				// excluded point or an empty card (see this function's own docblock).
				self._activeGroup = null;
				self._stage.classList.remove( 'is-card' );

				return;
			}

			self._activeIndex = fallbackIndex;
			point = group.points[ fallbackIndex ];
		}

		self._cardEl.appendChild( buildCardHeader( self, group, point ) );
		self._cardEl.appendChild( buildCardBody( self._config, point ) );
		self._cardEl.appendChild( buildCardFooter( self, point ) );
	}

	// -------------------------------------------------------------------------
	// Type filter menu (Task 16, moved into the search control by Task 13, D-10/V-8)
	// -------------------------------------------------------------------------

	/**
	 * Closes the filter menu (`.hidden = true`) if a filter has ever been built — a no-op
	 * otherwise. The one place that actually flips the menu shut, shared by the toggle's own
	 * click handler, {@see Panels.prototype.renderSearchResults}'s "opening either menu closes the
	 * other" half, and round 3's new auto-close listeners below ({@see ensureFilterEl}'s
	 * `focusout` and outside-`click` handlers) — one function, so every closing path agrees.
	 *
	 * @since 2.0.2
	 * @param {Panels} self
	 * @returns {void}
	 */
	function closeFilterMenu( self ) {
		if ( self._filterMenuEl ) {
			self._filterMenuEl.hidden = true;
		}
	}

	/**
	 * Builds the filter's toggle/badge/menu DOM exactly ONCE per `Panels` instance — a no-op on a
	 * second call (guarded on `self._filterWrapEl`). Deferred until `setTypes()` actually needs it
	 * (a second distinct type has been seen) rather than built eagerly in `render()`, because
	 * whether a Panels instance ever needs a filter at all depends on data this constructor does
	 * not have yet (see the file docblock's "THE CONTROL'S HOME" note).
	 *
	 * The toggle and the menu are two independent, permanent children of the returned wrap — the
	 * badge is a permanent child of the TOGGLE (never inserted/removed the way Task 16's original
	 * version did), toggled purely via `.hidden`, matching every other optional-visibility element
	 * in this file (`{@see Panels.prototype.buildSearchLayout}`'s own reset button, for instance).
	 *
	 * ROUND 3 (operator live-review, defect B — "фильтр... висит постоянно открытым"): the menu
	 * now closes itself on TWO independent signals, matching the reference's own `ListBox`
	 * (`collapseOnBlur: true`) and mirroring the exact care already applied to the search results
	 * box (see {@see Panels.prototype.buildSearchLayout}'s own `focusout` handler):
	 *
	 *   - `focusout` on `wrap`, closing when focus lands OUTSIDE it (`relatedTarget` set and not a
	 *     descendant of `wrap`) — a `null` `relatedTarget` (focus left the document entirely — an
	 *     alt-tab, a click on browser chrome) deliberately does NOT close it, same reasoning as the
	 *     search box: that is not "the customer moved on".
	 *   - a `click` on `document`, closing when the click landed outside `wrap` — the customer's
	 *     most likely dismissal gesture is clicking the MAP, and a plain map click does not
	 *     necessarily move DOM focus at all, so `focusout` alone would miss it. This listener is
	 *     the one thing in this file attached to `document` rather than an element THIS file
	 *     owns, so it is also the one thing {@see Panels.prototype.destroy} must explicitly
	 *     remove — an element-scoped listener dies with its element when the stage is detached,
	 *     but a `document` listener does not, and this file has already been bitten once by a
	 *     listener that outlived its instance (the search debounce timer, see `destroy()`'s own
	 *     docblock).
	 *
	 * @param {Panels} self
	 * @returns {void}
	 */
	function ensureFilterEl( self ) {
		if ( self._filterWrapEl ) {
			return;
		}

		var wrap = document.createElement( 'div' );
		wrap.className = 'woodev-pickup-filter';

		var toggle = document.createElement( 'button' );
		toggle.type = 'button';
		toggle.className = 'woodev-pickup-filter__toggle';
		// `allTypes`, not `filterTypes` — see the file docblock's "TWO i18n KEYS" note; the menu
		// itself is titled separately, below.
		toggle.setAttribute( 'aria-label', text( self._config, 'allTypes' ) );
		toggle.innerHTML = FILTER_ICON_SVG; // eslint-disable-line -- static, framework-authored markup, no user input.

		var badge = document.createElement( 'span' );
		badge.className = 'woodev-pickup-filter__badge';
		badge.hidden = true;
		toggle.appendChild( badge );

		var menu = document.createElement( 'div' );
		menu.className = 'woodev-pickup-filter__menu';
		menu.hidden = true;

		var title = document.createElement( 'div' );
		title.className = 'woodev-pickup-filter__title';
		title.textContent = text( self._config, 'filterTypes' );
		menu.appendChild( title );

		// "Opening either menu closes the other" (spec V-8, Russian Post's own `menu--open`
		// behaviour): opening the filter menu hides the search results dropdown built by
		// `buildSearchLayout()`, when one exists (it does not when search is disabled). The
		// opposite direction — a results dropdown opening closes THIS menu — lives in
		// `renderSearchResults()`, the one place results actually become visible.
		toggle.addEventListener( 'click', function() {
			var opening = menu.hidden;

			menu.hidden = ! opening;

			if ( opening && self._searchResults ) {
				self._searchResults.hidden = true;
			}
		} );

		// Round 3, defect B — see this function's own docblock for both signals below.
		wrap.addEventListener( 'focusout', function( event ) {
			var next = event.relatedTarget;

			if ( null === next || wrap.contains( next ) ) {
				return;
			}

			closeFilterMenu( self );
		} );

		self._filterOutsideClickHandler = function( event ) {
			if ( ! menu.hidden && ! wrap.contains( event.target ) ) {
				closeFilterMenu( self );
			}
		};

		document.addEventListener( 'click', self._filterOutsideClickHandler );

		wrap.appendChild( toggle );
		wrap.appendChild( menu );

		self._filterWrapEl = wrap;
		self._filterToggleEl = toggle;
		self._filterMenuEl = menu;
		self._badgeEl = badge;
	}

	/**
	 * Attaches the (already-built) filter wrap to its home, exactly once: a SIBLING of
	 * `.woodev-pickup-search` inside the search control's own layout when
	 * {@see Panels.prototype.buildSearchLayout} built one (`self._controlsEl`), or the list panel
	 * otherwise — see the file docblock's "THE CONTROL'S HOME" note for why there are two homes at
	 * all. A no-op once already attached, and a no-op (rather than a throw) when NEITHER host
	 * exists yet, which cannot happen via the real call order (`render()` always runs before any
	 * `setTypes()` call) but costs nothing to guard.
	 *
	 * @param {Panels} self
	 * @returns {void}
	 */
	function attachFilterEl( self ) {
		if ( self._filterWrapEl.parentNode ) {
			return;
		}

		var host = self._controlsEl || self._listEl;

		if ( host ) {
			host.appendChild( self._filterWrapEl );
		}
	}

	/**
	 * Rebuilds the filter's checkbox rows from `self._filterOrder` — every
	 * type ever seen, in first-seen order, never fewer even when the LATEST
	 * `setTypes()` call reported only some of them (see the file docblock's
	 * "THE TYPE FILTER" note). Only the `.woodev-pickup-filter__row` elements
	 * are torn down and rebuilt here — the title lives in the same menu but is
	 * a separate, longer-lived child, appended once by {@see ensureFilterEl}
	 * and left alone here.
	 *
	 * Each row carries `data-checked="true"|"false"` (D2) — a plain reflection of its own
	 * checkbox's state at render time, for T4's CSS to key off without reaching for the sibling
	 * `<input>`'s `:checked` pseudo-class (a `<label>` row wrapping the checkbox makes that
	 * selector awkward to write correctly). {@see handleFilterCheckboxChange} keeps it in sync on
	 * every ACCEPTED change — never touched on a refused uncheck, since the checkbox itself is
	 * reverted and the row's true state (`true`) never actually changed.
	 *
	 * `label` is the same already-escaped shape a point's `type.label` is
	 * elsewhere in this file (it originates from the very same field), so it
	 * is written via `innerHTML` here too, not `textContent`.
	 *
	 * @param {Panels} self
	 * @returns {void}
	 */
	function renderFilterRows( self ) {
		var rows = self._filterMenuEl.querySelectorAll( '.woodev-pickup-filter__row' );

		Array.prototype.forEach.call( rows, function( row ) {
			row.parentNode.removeChild( row );
		} );

		self._filterOrder.forEach( function( code ) {
			var row = document.createElement( 'label' );
			row.className = 'woodev-pickup-filter__row';

			var checked = Boolean( self._filterSelected[ code ] );

			row.dataset.checked = checked ? 'true' : 'false';

			var checkbox = document.createElement( 'input' );
			checkbox.type = 'checkbox';
			checkbox.className = 'woodev-pickup-filter__checkbox';
			checkbox.checked = checked;
			checkbox.dataset.code = code;
			checkbox.addEventListener( 'change', function() {
				handleFilterCheckboxChange( self, code, checkbox, row );
			} );

			var labelEl = document.createElement( 'span' );
			labelEl.className = 'woodev-pickup-filter__label';
			labelEl.innerHTML = fieldValue( self._filterLabels[ code ] ); // eslint-disable-line -- server-escaped.

			row.appendChild( checkbox );
			row.appendChild( labelEl );
			self._filterMenuEl.appendChild( row );
		} );
	}

	/**
	 * Reflects the current selection onto the toggle's `.is-filtered` class and the badge's
	 * `hidden`/text (D2, round 2 rework — see the file docblock's "THE BADGE'S 3+ RULE" note).
	 *
	 * `.is-filtered` on `.woodev-pickup-filter__toggle` is the WHOLE "something is filtered off"
	 * signal for a 2-type carrier: present whenever the selection is PARTIAL (strictly fewer
	 * selected than known types), full stop. The numeric badge is a stricter, ADDITIONAL signal —
	 * it un-hides only once BOTH the selection is partial AND there are
	 * {@see FILTER_BADGE_MIN_TYPES} or more known types, and its text is the number of types
	 * CURRENTLY SELECTED, never the number excluded. Below that type count a badge can only ever
	 * read "1" (two types, one deselected — there is no other partial state to be in), which is
	 * arithmetically indistinguishable from useful information; `.is-filtered` alone carries the
	 * signal instead. Task 13 changed the badge from attach/detach to a plain `.hidden` flip — it
	 * is a permanent child of the toggle (see {@see ensureFilterEl}), never inserted into or
	 * removed from the menu; this rework does not change that.
	 *
	 * @param {Panels} self
	 * @returns {void}
	 */
	function updateFilterBadge( self ) {
		var selectedCount = self._filterOrder.filter( function( code ) {
			return Boolean( self._filterSelected[ code ] );
		} ).length;

		var partial = selectedCount < self._filterOrder.length;

		self._filterToggleEl.classList.toggle( 'is-filtered', partial );

		var showBadge = partial && self._filterOrder.length >= FILTER_BADGE_MIN_TYPES;

		self._badgeEl.hidden = ! showBadge;

		if ( showBadge ) {
			self._badgeEl.textContent = String( selectedCount );
		}
	}

	/**
	 * Handles one checkbox's native `change` event.
	 *
	 * Refuses to leave the selection empty: unchecking the LAST currently
	 * selected type reverts the checkbox back to checked and returns without
	 * emitting `typeFilterChange` — silently re-checking the box is the
	 * Yandex reference's own rule (see the file docblock), because an empty
	 * selection would read to the customer as "there are no pickup points
	 * at all". Every other change is accepted, updates `row`'s `data-checked`
	 * and the badge/`.is-filtered` state, and emits the full list of
	 * currently selected codes.
	 *
	 * Round 3 (coordinator fix — "the filter applies to the map but not to the sidebar list"):
	 * also re-renders the LIST BODY here, synchronously, and the CARD if one is open
	 * ({@see renderCard}'s own fallback logic) — never waits for the next `setVisible()` viewport
	 * update. The operator's repro was exactly "uncheck a type and look at the list" with no map
	 * movement in between; a fix that only took effect on the next viewport change would have
	 * looked identical to the bug. Guarded on `self.root` (unset before `render()` has ever run) —
	 * a filter built via `buildSearchLayout()` alone in a test harness that never calls `render()`
	 * has no list body to rebuild yet, matching every other `self.root`/`self._listBodyEl` guard in
	 * this file.
	 *
	 * @param {Panels}       self
	 * @param {string}       code
	 * @param {HTMLInputElement} checkbox
	 * @param {HTMLElement}  row      the checkbox's own `.woodev-pickup-filter__row` (D2).
	 * @returns {void}
	 */
	function handleFilterCheckboxChange( self, code, checkbox, row ) {
		if ( ! checkbox.checked ) {
			var stillSelected = self._filterOrder.some( function( otherCode ) {
				return otherCode !== code && Boolean( self._filterSelected[ otherCode ] );
			} );

			if ( ! stillSelected ) {
				checkbox.checked = true;

				return;
			}
		}

		self._filterSelected[ code ] = checkbox.checked;
		row.dataset.checked = checkbox.checked ? 'true' : 'false';
		updateFilterBadge( self );

		if ( self.root ) {
			renderList( self );
		}

		if ( self._activeGroup ) {
			renderCard( self );
		}

		var selected = self._filterOrder.filter( function( otherCode ) {
			return Boolean( self._filterSelected[ otherCode ] );
		} );

		self._emit( 'typeFilterChange', selected );
	}

	// -------------------------------------------------------------------------
	// Panels constructor
	// -------------------------------------------------------------------------

	/**
	 * @param {HTMLElement} container element the panels' root is appended into.
	 * @param {Object}      config    the pickup config (`i18n`, `lang`, `accentColor`, …).
	 * @constructor
	 */
	function Panels( container, config ) {
		this._container = container;
		this._config = config || {};
		this._groups = [];
		this._anchor = null;
		this._anchorLabel = null;
		this._selectedId = null;

		/**
		 * @type {boolean} true while a selection confirmation is in flight — see
		 * {@see Panels.prototype.setSelectionBusy}.
		 */
		this._selectionBusy = false;
		this._activeGroup = null;
		this._activeIndex = 0;
		this._listeners = {};

		// Task 16: the type filter menu accumulates first-seen types across
		// every `setTypes()` call and never forgets one — see the file
		// docblock's "THE TYPE FILTER" note.
		this._filterOrder = [];
		this._filterLabels = {};
		this._filterSelected = {};
		this._filterShown = false;

		// Task 13 (spec V-8): the filter's own DOM — built lazily by `setTypes()` (see the file
		// docblock's "THE CONTROL'S HOME" note), never here. `_controlsEl` is set only when
		// `buildSearchLayout()` actually builds a control (null when search is disabled), which is
		// exactly the signal `setTypes()` uses to decide where the filter attaches.
		this._controlsEl = null;
		this._filterWrapEl = null;
		this._filterToggleEl = null;
		this._filterMenuEl = null;
		this._badgeEl = null;

		// Round 3 (defect B): the `document`-level outside-click listener {@see ensureFilterEl}
		// attaches — unlike every other listener in this file, it is not scoped to an element this
		// instance owns, so `destroy()` must remove it explicitly by this same reference.
		this._filterOutsideClickHandler = null;

		// Task 11 (spec V-6): set only once `buildSearchLayout()` actually runs — a Panels instance
		// the caller never asks for a search layout (e.g. `config.search === false`) never gets
		// these, which is exactly why `renderSearchResults()` guards on `_searchResults` being unset.
		this._searchTimer = null;
		this._searchInput = null;
		this._searchResults = null;

		// Round 4: the `document`-level outside-click listener {@see Panels.prototype.buildSearchLayout}
		// attaches for the results box, same pattern/same reason as `_filterOutsideClickHandler`
		// above — not scoped to an element this instance owns, so `destroy()` must remove it by
		// this same reference.
		this._searchOutsideClickHandler = null;

		// Task 16 (spec V-4 stage 2): whether the stage is currently blocked on the FIRST points
		// fetch after the map was drawn — see {@see Panels.prototype.setBusy}. False until `render()`
		// builds the overlay `setBusy()` toggles; a caller asking before `render()` gets the correct
		// "not busy" answer regardless.
		this._busy = false;
		this._overlayEl = null;

		// Task 17 (spec V-5): the message card {@see Panels.prototype.showMessage} shows/hides —
		// null until `render()` builds it, exactly like `_overlayEl` above.
		this._messageEl = null;
		this._messageCardEl = null;
		this._messageTextEl = null;
		this._messageRetryEl = null;

		this.root = null;
	}

	/**
	 * Registers a listener for `event` — ADDS it, never replaces a
	 * previously-registered one for the same event (see the file docblock's
	 * "EVENT SEMANTICS" note).
	 *
	 * @param {string}   event
	 * @param {Function} cb
	 * @returns {void}
	 */
	Panels.prototype.on = function( event, cb ) {
		if ( ! this._listeners[ event ] ) {
			this._listeners[ event ] = [];
		}

		this._listeners[ event ].push( cb );
	};

	/**
	 * Calls every listener registered for `event` with `payload`.
	 *
	 * @param {string} event
	 * @param {*}      payload
	 * @returns {void}
	 */
	Panels.prototype._emit = function( event, payload ) {
		( this._listeners[ event ] || [] ).forEach( function( cb ) {
			cb( payload );
		} );
	};

	/**
	 * Builds the panels' DOM subtree — a single `.woodev-pickup-stage` wrapping a map mount
	 * point (see {@see getMapElement}) and the list/card panels — and appends the STAGE, never
	 * the panels directly, to the container supplied at construction (spec V-3). The stage is
	 * the positioning context every panel is `position: absolute` against; it begins BELOW the
	 * modal header, so no panel can reach it the way the old `position: fixed` panels did once
	 * `.woodev-modal__content` grew a centring `transform` — see `pickup.css`'s own docblock.
	 * This project never calls it twice on the same instance, but a second call would not
	 * duplicate the subtree either way: a pre-existing `.woodev-pickup-stage` is removed first
	 * (Task 16 fix — see that removal's own comment below for why this can no longer be a blind
	 * "clear everything" on the whole container).
	 *
	 * @returns {void}
	 */
	Panels.prototype.render = function() {
		var stage = document.createElement( 'div' );
		stage.className = 'woodev-pickup-stage';

		// The map mount point — this task only builds the DOM/CSS plumbing for it; a later
		// task rewires the caller to hand `getMapElement()` to the map provider's `init()`
		// instead of the raw modal container. Painted first so every panel draws over it.
		var map = document.createElement( 'div' );
		map.className = 'woodev-pickup-map';

		var root = document.createElement( 'div' );
		root.className = 'woodev-pickup-panels';

		applyAccentColor( root, this._config );

		var list = document.createElement( 'div' );
		list.className = 'woodev-pickup-list';

		// Two names, because this ONE control does two opposite things: closed it opens the
		// drawer (`drawerTitle`), open it collapses the drawer back to the map (`showMap`).
		// {@see setStageOpen} swaps the `aria-label` between them on every transition; the
		// initial state is closed, so it starts on `drawerTitle`.
		//
		// `drawerTitle` is also this key's ONLY remaining home: Task 7 (spec V-11) deleted the
		// list header it used to feed, since neither reference has one and it stated something
		// the customer could already see.
		var toggle = document.createElement( 'button' );
		toggle.type = 'button';
		toggle.className = 'woodev-pickup-list__toggle';
		toggle.setAttribute( 'aria-label', text( this._config, 'drawerTitle' ) );

		// The VISIBLE label (#168). Rendered in exactly one state — the mobile open-list bar,
		// where the control spans the panel's full width and a bare chevron would read as
		// decoration; `pickup.css` hides it everywhere else, where the button is a 44×44 icon.
		// `aria-hidden` because the button's own `aria-label` above is already its accessible
		// name (and matches this text whenever it is on screen), same convention as the filter
		// toggle's decorative SVG. Blank, never a hardcoded Russian default, if the key is
		// absent — rule I1.
		var toggleLabel = document.createElement( 'span' );
		toggleLabel.className = 'woodev-pickup-list__toggle-label';
		toggleLabel.setAttribute( 'aria-hidden', 'true' );
		toggleLabel.textContent = text( this._config, 'showMap' );

		toggle.appendChild( toggleLabel );

		var body = document.createElement( 'div' );
		body.className = 'woodev-pickup-list__body';

		list.appendChild( body );

		var card = document.createElement( 'div' );
		card.className = 'woodev-pickup-card';

		root.appendChild( list );
		root.appendChild( card );

		stage.appendChild( map );
		stage.appendChild( root );

		// The toggle is a SIBLING of the panels, on the stage — never a child of the list it
		// controls. Two reasons, both learned by getting it wrong first:
		//
		// 1. Geometry. `right`/`bottom` on an absolutely-positioned element resolve against its
		//    nearest positioned ancestor. Inside the list, `right: calc( min( 320px, 100% - 48px )
		//    + 16px )` measured `100%` against the LIST's own 320px box, not the stage's — the
		//    open-state offset came out 288px instead of 336px and the button sat on top of the
		//    panel it was supposed to stand beside.
		// 2. Visibility. A control that opens a hidden panel must not live inside it. As a child
		//    it could only survive `visibility: hidden` (a descendant can restore its own
		//    visibility; nothing survives `display: none`), which forced the panels to be hidden
		//    the weaker way and left their full-height boxes swallowing clicks meant for the map.
		stage.appendChild( toggle );

		// Task 14 (spec V-13): our own zoom control — two square 36×36 buttons, «+» over «−», at
		// `left: 12px; bottom: 70px`. A stage sibling for the SAME two reasons the toggle above
		// is (geometry resolves against the stage, not a panel; a control fixed to a screen
		// corner must not live inside something that can go `display: none`). ymaps' own
		// `ZoomControl` is deleted from map-provider-yandex.js's `_buildMap()` in its favour —
		// Russian Post's square-button look at Yandex.Delivery's bottom-left position, replacing
		// the default slider that used to sit adrift at `left: 70` in the middle of the map.
		var zoom = document.createElement( 'div' );
		zoom.className = 'woodev-pickup-zoom';

		var zoomIn = document.createElement( 'button' );
		zoomIn.type = 'button'; // Never omit: inside a checkout page a type-less button submits it.
		zoomIn.className = 'woodev-pickup-zoom__button woodev-pickup-zoom__button--in';
		zoomIn.setAttribute( 'aria-label', text( this._config, 'zoomInLabel' ) );
		zoomIn.textContent = '+';

		var zoomOut = document.createElement( 'button' );
		zoomOut.type = 'button';
		zoomOut.className = 'woodev-pickup-zoom__button woodev-pickup-zoom__button--out';
		zoomOut.setAttribute( 'aria-label', text( this._config, 'zoomOutLabel' ) );
		zoomOut.textContent = '−'; // U+2212 MINUS SIGN — a true minus, not a hyphen glyph.

		zoom.appendChild( zoomIn );
		zoom.appendChild( zoomOut );

		stage.appendChild( zoom );

		// Task 16 (spec V-4 stage 2): built once here, always present, HIDDEN by default — never
		// created/destroyed by `setBusy()` itself, matching `WoodevModal#showLoading()`'s own
		// "idempotent, additive" node-reuse discipline (see that file's docblock). A stage sibling,
		// appended LAST so it paints over the map/panels/zoom regardless of DOM order (the actual
		// stacking is `pickup.css`'s `z-index: 6` — the highest this file uses).
		var overlay = document.createElement( 'div' );
		overlay.className = 'woodev-pickup-overlay';
		overlay.setAttribute( 'role', 'status' );
		overlay.hidden = true;

		var spinner = document.createElement( 'span' );
		spinner.className = 'woodev-pickup-spinner';
		spinner.setAttribute( 'aria-hidden', 'true' );
		overlay.appendChild( spinner );

		stage.appendChild( overlay );

		// Task 17 (spec V-5): built once here, always present, HIDDEN by default — same
		// node-reuse discipline as the busy overlay above. Deliberately a SEPARATE element from
		// it, not a second use of `_overlayEl`: the busy overlay covers the WHOLE stage and its
		// `is-busy` companion class hides the search/filter controls on purpose (nothing has
		// loaded yet, see that overlay's own docblock) — this message must do neither, since
		// the customer can still search or change the filter while it shows (s48 decision, see
		// {@see Panels.prototype.showMessage}). The wrapper itself is `pointer-events: none`
		// (`pickup.css`) so it never blocks clicks anywhere except the small centred card inside
		// it, which is what actually paints over the map (spec V-5's "centred card over the
		// map, never a replacement for the interface").
		var message = document.createElement( 'div' );
		message.className = 'woodev-pickup-message';
		message.setAttribute( 'role', 'status' );
		message.hidden = true;

		var messageCard = document.createElement( 'div' );
		messageCard.className = 'woodev-pickup-message__card';

		var messageText = document.createElement( 'p' );
		messageText.className = 'woodev-pickup-message__text';
		messageCard.appendChild( messageText );

		message.appendChild( messageCard );
		stage.appendChild( message );

		// Removes a STALE stage only — never the whole container (Task 16 fix; this used to be a
		// blind `empty( this._container )`). `pickup-mount.js` calls `modal.showLoading()` BEFORE
		// constructing this instance at all (spec V-4 stage 1), appending its spinner overlay
		// straight into this SAME container; wiping every child here deleted that overlay the
		// instant the panels rendered, so the modal's "map not ready yet" spinner was visible for
		// effectively zero time. This project still only ever calls `render()` once per instance
		// (see the docblock above) — this guard is for a hypothetical re-render, not the common case.
		var existingStage = this._container.querySelector( '.woodev-pickup-stage' );

		if ( existingStage ) {
			this._container.removeChild( existingStage );
		}

		this._container.appendChild( stage );

		this.root = root;
		this._stage = stage;
		this._mapEl = map;
		this._listEl = list;
		this._listBodyEl = body;
		this._cardEl = card;
		this._messageEl = message;
		this._messageCardEl = messageCard;
		this._messageTextEl = messageText;
		this._messageRetryEl = null;
		this._overlayEl = overlay;
		this._toggleEl = toggle;

		var self = this;
		toggle.addEventListener( 'click', function() {
			self.toggleList();
		} );

		// The panels only emit the signed step (Task 14) — the map provider owns the actual
		// camera zoom (`WoodevYandexMapProvider#zoomBy`), keeping map-library behaviour out of
		// this file (D-3); the mount is what wires this event to that method.
		zoomIn.addEventListener( 'click', function() {
			self._emit( 'zoom', { step: 1 } );
		} );
		zoomOut.addEventListener( 'click', function() {
			self._emit( 'zoom', { step: -1 } );
		} );

		renderList( this );
	};

	/**
	 * The element the map provider mounts its canvas into — a child of the stage (see
	 * {@see render}), painted first so every panel draws over it.
	 *
	 * @since 2.0.2
	 * @returns {HTMLElement}
	 */
	Panels.prototype.getMapElement = function() {
		return this._mapEl;
	};

	/**
	 * Toggles the busy state (Task 16, spec V-4 stage 2): a stage-wide spinner overlay and a
	 * non-interactive map, shown from the moment the map is drawn until the FIRST points fetch
	 * settles. Marks `.woodev-pickup-stage` with `is-busy` — `pickup.css`'s own rule off that class
	 * is what actually disables the map (`pointer-events: none`) and hides the search/filter
	 * controls (searching a pool that has not loaded yet is meaningless); this method only owns the
	 * class and the overlay's `hidden` flag, never the map's interactivity directly, so a map
	 * provider never has to know this state exists.
	 *
	 * A no-op on the class/overlay when called before `render()` — `isBusy()` still answers
	 * correctly either way, since `_busy` itself is always tracked.
	 *
	 * @since 2.0.2
	 *
	 * @param {boolean} busy
	 * @returns {void}
	 */
	Panels.prototype.setBusy = function( busy ) {
		this._busy = !! busy;

		if ( this._stage ) {
			this._stage.classList.toggle( 'is-busy', this._busy );
		}

		if ( this._overlayEl ) {
			this._overlayEl.hidden = ! this._busy;
		}
	};

	/**
	 * @since 2.0.2
	 * @returns {boolean}
	 */
	Panels.prototype.isBusy = function() {
		return this._busy;
	};

	/**
	 * Shows the plugin's own i18n text as a small centred card over the map (Task 17, spec V-5) —
	 * the framework's answer to "the pool is genuinely empty" or "the last request failed",
	 * looked up the SAME way every other label in this file is (see the file docblock's rule I1:
	 * a missing key renders BLANK, never a hardcoded Russian fallback that would mask a PHP/JS
	 * i18n-key mismatch). Deliberately NOT `setBusy()`'s stage-wide overlay: that one hides the
	 * search/filter controls and disables the map on purpose (nothing has loaded yet); this one
	 * must not, because the customer can still search or change the filter while an empty/error
	 * card is showing (s48 decision) — see {@see Panels.prototype.render}'s own note on why the
	 * card is a SEPARATE element from the busy overlay, never a second use of it.
	 *
	 * Every key grows a retry control EXCEPT the three in {@see NO_RETRY_MESSAGE_KEYS}: an empty
	 * bulk/viewport result and a too-wide bbox have nothing to retry, only a different
	 * search/zoom to try — matching `WoodevModal#showError()` vs `#showEmpty()`'s identical
	 * split. Every OTHER key — the generic `'error'` and the mount's own specific
	 * `upstreamError`/`rateLimited`/`notFound` mappings alike — is a failed REQUEST, which is
	 * always worth retrying. Clicking retry emits `retryRequested` (no payload) rather than
	 * calling a caller-supplied function directly — this file never holds a reference to the
	 * mount's own `start()`, exactly like every other cross-file action here (`zoom`,
	 * `showNearestRequested`, …).
	 *
	 * Idempotent and replaceable: calling this again — with the same key or a different one —
	 * overwrites the previous text/retry state rather than stacking a second card. A no-op
	 * before `render()` (nothing to show it over yet).
	 *
	 * @since 2.0.2
	 *
	 * @param {string} key an i18n key, e.g. `'emptyLocality'`, `'emptyInView'`, `'error'`, `'zoomIn'`,
	 *                     or one of the mount's own specific error keys (`'upstreamError'`, …).
	 * @returns {void}
	 */
	Panels.prototype.showMessage = function( key ) {
		if ( ! this._messageEl ) {
			return;
		}

		this._messageTextEl.textContent = text( this._config, key );

		if ( this._messageRetryEl ) {
			this._messageCardEl.removeChild( this._messageRetryEl );
			this._messageRetryEl = null;
		}

		if ( -1 === NO_RETRY_MESSAGE_KEYS.indexOf( key ) ) {
			var self = this;
			var retryButton = document.createElement( 'button' );

			retryButton.type = 'button'; // Never omit: inside a checkout page a type-less button submits it.
			retryButton.className = 'woodev-pickup-message__retry';
			retryButton.textContent = text( this._config, 'retry' );
			retryButton.addEventListener( 'click', function() {
				self._emit( 'retryRequested', null );
			} );

			this._messageCardEl.appendChild( retryButton );
			this._messageRetryEl = retryButton;
		}

		this._messageEl.hidden = false;
	};

	/**
	 * Hides whatever {@see Panels.prototype.showMessage} last showed, if anything — called once a
	 * later fetch settles with a non-empty result, so the empty/error card never lingers over a
	 * map that has since drawn real points. The same node is reused on the next `showMessage()`
	 * call, matching `setBusy()`'s own "toggle `hidden`, never rebuild" discipline. A no-op
	 * before `render()`, and a harmless no-op when no message is currently showing.
	 *
	 * @since 2.0.2
	 * @returns {void}
	 */
	Panels.prototype.hideMessage = function() {
		if ( this._messageEl ) {
			this._messageEl.hidden = true;
		}
	};

	/**
	 * Sets (or clears) the distance anchor — the map centre by default, a
	 * searched address when a search is active (Task 15); one rule, not two
	 * modes (see the file docblock). Re-renders the list immediately so an
	 * already-open list re-sorts without waiting for the next `setVisible()`.
	 *
	 * `label` (Task 15) is the searched address text; it used to switch the list header to the
	 * `nearestTo` template and show a reset control, both DELETED in Task 7 (spec V-11) along
	 * with the plain header they shared an element with. `label` still has no OTHER effect today
	 * — Task 11 gave the search its own real affordance ({@see Panels.prototype.buildSearchLayout}),
	 * but that layout does not read `label` back into the input's displayed value; `label` is
	 * stored purely so this call shape survives, and so the anchor itself keeps driving the list's
	 * sort order. Existing single-argument callers (the map-centre case) are unaffected either way.
	 *
	 * `anchorCleared` fires whenever this call CLEARS the anchor (`latLng` is
	 * null/falsy) — see the file docblock's "EVENT SEMANTICS" note.
	 *
	 * @param {number[]|null} latLng `[lat, lng]`, or null to clear.
	 * @param {string}        [label] the searched address; stored for the anchor's sort order, no
	 *                                DOM effect (see above).
	 * @returns {void}
	 */
	Panels.prototype.setAnchor = function( latLng, label ) {
		this._anchor = latLng || null;
		this._anchorLabel = ( this._anchor && 'string' === typeof label && label.length > 0 ) ? label : null;

		if ( this.root ) {
			renderList( this );
		}

		if ( ! this._anchor ) {
			this._emit( 'anchorCleared', null );
		}
	};

	/**
	 * Sets the groups currently in the map's viewport and re-renders the list.
	 *
	 * Stores `groups` BY REFERENCE, not a copy — the group and point objects inside it stay
	 * shared with whoever handed them to us (the mount builds them once and also hands them to
	 * the map provider). `panels` holds no private copy, so {@see Panels.prototype.setPointVerdict}
	 * mutating a point in place is visible to every other holder of that same object, not just
	 * this instance.
	 *
	 * @param {Array} groups
	 * @returns {void}
	 */
	Panels.prototype.setVisible = function( groups ) {
		this._groups = groups || [];

		renderList( this );
	};

	/**
	 * Builds the DOM and handlers for the `SearchControl`'s custom layout (Task 11, spec V-6).
	 *
	 * Returns a DETACHED element rather than mounting it: the map provider hands it to ymaps
	 * through `options.layout`, and ymaps decides where it actually lives (Task 12). Keeping
	 * construction here — rather than in the map-provider file — keeps D-3 intact (no map-library
	 * file renders point information) and lets this be tested without ymaps in the room.
	 *
	 * Two different events, deliberately — see the file docblock's "THE SEARCH VIEW" note:
	 *   - `searchType`   — debounced {@see SEARCH_DEBOUNCE_MS}ms, from {@see SEARCH_MIN_CHARS}
	 *                      characters, while typing. Filters the ALREADY LOADED pool. Free, local,
	 *                      no network.
	 *   - `searchSubmit` — on Enter or the magnifier only. Runs the geocoder, which spends the
	 *                      merchant's quota, so it never fires per keystroke — Russian Post's own
	 *                      model (verified in its bundle): it calls `control.search( value )` on
	 *                      submit and never uses `ymaps.suggest`.
	 *
	 * `renderSearchResults()` fills the `.woodev-pickup-search__results` element built here — see
	 * that method's own docblock for what happens when it is called before this one; this method's
	 * own {@see Panels.prototype.hideSearchResults} is the one that closes it again (round 2, D1e).
	 *
	 * `search` (the wrap containing the form and results) grows a `focusout` listener: when focus
	 * leaves it ENTIRELY — `event.relatedTarget` is set and NOT a descendant of `search` — the
	 * results box closes. A `relatedTarget` of `null` (focus left the document altogether — an
	 * alt-tab, a click on browser chrome) deliberately does NOT close it: treating that as "left"
	 * would blank the results out from under a customer who only switched tabs mid-search.
	 *
	 * ROUND 4 (operator live-review): `focusout` alone does not cover the customer's actual
	 * dismissal gesture — clicking the MAP — because a plain map click does not move DOM focus at
	 * all, so `focusout` never fires and the box stayed open ("результат поиска (список) висит
	 * открытым пока не выбрать из списка"). Reuses the EXACT pattern {@see ensureFilterEl} already
	 * established for the filter menu rather than inventing a second one: a `document`-level
	 * `click` listener, stored on `self._searchOutsideClickHandler` and removed in
	 * {@see Panels.prototype.destroy}, closing the box when the click landed outside `search`. A
	 * click INSIDE `search` (the input, the buttons, a result row) is excluded by the same
	 * `contains()` check, so it never fights with a result row's own pick handler or the reset
	 * button's own click handler. This listener only ever calls
	 * {@see Panels.prototype.hideSearchResults} — never touches the filter menu — so it cannot
	 * open/close that as a side effect either; the filter's own outside-click listener runs
	 * independently against its own wrap.
	 *
	 * There is no submit button — see the file docblock's "NO SUBMIT BUTTON" note. The form's own
	 * `submit` event (Enter, or a phone keyboard's "Перейти") is the whole of that path.
	 *
	 * @since 2.0.2
	 * @returns {HTMLElement|null} null when the plugin disabled search (`config.search === false`).
	 */
	Panels.prototype.buildSearchLayout = function() {
		var self = this;

		if ( false === this._config.search ) {
			return null;
		}

		var wrap = document.createElement( 'div' );
		wrap.className = 'woodev-pickup-controls';

		var search = document.createElement( 'div' );
		search.className = 'woodev-pickup-search';

		var form = document.createElement( 'form' );
		form.className = 'woodev-pickup-search__form';
		form.setAttribute( 'role', 'search' );

		var input = document.createElement( 'input' );
		input.type = 'search';
		input.className = 'woodev-pickup-search__input';
		input.setAttribute( 'placeholder', text( this._config, 'yourAddress' ) );
		input.setAttribute( 'aria-label', text( this._config, 'yourAddress' ) );

		var reset = document.createElement( 'button' );
		reset.type = 'button';
		reset.className = 'woodev-pickup-search__reset';
		reset.hidden = true;
		reset.setAttribute( 'aria-label', text( this._config, 'resetSearch' ) );
		reset.innerHTML = CLEAR_ICON_SVG; // eslint-disable-line -- static, framework-authored markup, no user input.

		var results = document.createElement( 'div' );
		results.className = 'woodev-pickup-search__results';
		results.hidden = true;

		form.appendChild( input );
		form.appendChild( reset );
		search.appendChild( form );
		search.appendChild( results );
		wrap.appendChild( search );

		form.addEventListener( 'submit', function( event ) {
			// Without this the browser submits the CHECKOUT form the modal was opened from —
			// see the task report/docblock note on why this line cannot be skipped.
			event.preventDefault();

			var value = input.value.trim();

			// Submitting supersedes whatever the customer was mid-typing: without this, pressing
			// Enter inside the debounce window runs BOTH paths for one query, and the local
			// keystroke result lands after the geocoder's and overwrites the richer answer with
			// the poorer one.
			window.clearTimeout( self._searchTimer );

			if ( value.length ) {
				self._emit( 'searchSubmit', { query: value } );
			}
		} );

		input.addEventListener( 'input', function() {
			var value = input.value.trim();

			reset.hidden = 0 === value.length;

			window.clearTimeout( self._searchTimer );

			if ( value.length < SEARCH_MIN_CHARS ) {
				return;
			}

			self._searchTimer = window.setTimeout( function() {
				self._emit( 'searchType', { query: value } );
			}, SEARCH_DEBOUNCE_MS );
		} );

		reset.addEventListener( 'click', function() {
			window.clearTimeout( self._searchTimer );
			input.value = '';
			reset.hidden = true;
			self.hideSearchResults();
			self._emit( 'searchReset', {} );
		} );

		// Round 2, D1e: closes the results box the moment focus leaves the search wrap entirely —
		// see this method's own docblock for the `relatedTarget` null-vs-outside distinction.
		search.addEventListener( 'focusout', function( event ) {
			var next = event.relatedTarget;

			if ( null === next || search.contains( next ) ) {
				return;
			}

			self.hideSearchResults();
		} );

		// Round 4: the map-click case `focusout` cannot cover — see this method's own docblock.
		// Same pattern as {@see ensureFilterEl}'s outside-click listener, own reference stored so
		// {@see Panels.prototype.destroy} can remove it.
		self._searchOutsideClickHandler = function( event ) {
			if ( ! results.hidden && ! search.contains( event.target ) ) {
				self.hideSearchResults();
			}
		};

		document.addEventListener( 'click', self._searchOutsideClickHandler );

		this._searchInput = input;
		this._searchResults = results;
		this._controlsEl = wrap;
		return wrap;
	};

	/**
	 * Builds the search results' TWO independent sections (Task 15, D-6) into
	 * `self._searchResults` — matching pool points and geocoder address suggestions, each OMITTED
	 * ENTIRELY (not an empty heading) when its own array is empty (see {@see buildSearchSection}).
	 * Fully rebuilds the container on every call, matching every other render function in this
	 * file. The SHARED painting logic behind both {@see Panels.prototype.renderSearchResults} (a
	 * COMPLETED search) and {@see Panels.prototype.previewSearchResults} (round 3, defect A — the
	 * live, still-typing preview) — those two methods differ ONLY in what an empty result means,
	 * which is exactly the distinction defect A was about; the painting itself is identical either
	 * way, so it lives here once rather than twice.
	 *
	 * @param {Panels} self
	 * @param {Object} results
	 * @param {Array}  [results.points]    matching points from the loaded pool.
	 * @param {Array}  [results.addresses] geocoder address suggestions, `{ displayName }` each.
	 * @returns {boolean} true when at least one section was actually painted.
	 */
	function paintSearchSections( self, results ) {
		var points = ( results && results.points ) || [];
		var addresses = ( results && results.addresses ) || [];

		empty( self._searchResults );

		var pointsSection = buildSearchSection(
			'points',
			text( self._config, 'sectionPoints' ),
			points,
			function( point ) {
				return buildSearchPointItem( self, point );
			}
		);

		if ( pointsSection ) {
			self._searchResults.appendChild( pointsSection );
		}

		var addressesSection = buildSearchSection(
			'addresses',
			text( self._config, 'sectionAddresses' ),
			addresses,
			function( address, index ) {
				return buildSearchAddressItem( self, address, index );
			}
		);

		if ( addressesSection ) {
			self._searchResults.appendChild( addressesSection );
		}

		return Boolean( pointsSection || addressesSection );
	}

	/**
	 * Renders the results of a COMPLETED search — the deliberate submit that actually spent the
	 * merchant's geocoding quota — into the results container
	 * {@see Panels.prototype.buildSearchLayout} builds. When BOTH sections come back empty, renders
	 * `text( config, 'noResults' )` instead of an empty box (Task 11, spec V-6): for a search that
	 * genuinely ran and genuinely found nothing, that verdict is correct and stays correct until
	 * the next submit, reset, or pick.
	 *
	 * Round 3, defect A: this is now explicitly the COMPLETED-search half of a two-method pair —
	 * see {@see Panels.prototype.previewSearchResults} for the other half, the live/instant preview
	 * while the customer is still typing, which must NEVER show this same `noResults` verdict for
	 * a search that has not actually run. Before this split, one method served both callers and
	 * the wrong one showed a verdict for a preview that had not finished — the operator's own
	 * words: "«Поиск не дал результатов.» ... висит так до тех пор пока не нажмёшь иконку «лупа»
	 * или «крестик»". Naming the two calls differently is deliberate — the distinction is made
	 * explicit at the CALL BOUNDARY, not guessed at from the shape of `results` inside a single
	 * renderer, so a future caller cannot mix the two up by accident.
	 *
	 * A no-op when `buildSearchLayout()` has never been called (`_searchResults` unset) — the
	 * layout is built on demand and this method must not throw just because it ran first.
	 *
	 * Task 13 (spec V-8): un-hiding the results box is also the OTHER half of "opening either menu
	 * closes the other" — see `buildSearchLayout()`'s filter toggle handler for the reverse
	 * direction. A filter menu left open while a search result appears would sit on top of it or
	 * beside it fighting for the same corner of screen, so this is one of the two places (with
	 * {@see Panels.prototype.previewSearchResults}) that actually shows results and therefore needs
	 * to close it.
	 *
	 * @param {Object} results
	 * @param {Array}  [results.points]    matching points from the loaded pool.
	 * @param {Array}  [results.addresses] geocoder address suggestions, `{ displayName }` each.
	 * @returns {void}
	 */
	Panels.prototype.renderSearchResults = function( results ) {
		if ( ! this._searchResults ) {
			return;
		}

		closeFilterMenu( this );

		var painted = paintSearchSections( this, results );

		this._searchResults.hidden = false;

		if ( ! painted ) {
			var noResults = document.createElement( 'div' );
			noResults.className = 'woodev-pickup-search__empty';
			noResults.textContent = text( this._config, 'noResults' );
			this._searchResults.appendChild( noResults );
		}
	};

	/**
	 * Renders the search view's live, INSTANT preview while the customer is still typing (round 3,
	 * defect A). The debounced local match against the ALREADY-LOADED pool has not spent the
	 * merchant's geocoding quota and the customer has not finished typing, so matching nothing YET
	 * is the normal case, not a failure — unlike {@see Panels.prototype.renderSearchResults} (the
	 * COMPLETED-search half of this pair), an empty preview must show NOTHING at all, never the
	 * `noResults` verdict. Shares {@see paintSearchSections} with that method; the two differ ONLY
	 * in what an empty result means for their respective caller.
	 *
	 * An empty preview calls {@see Panels.prototype.hideSearchResults} — closes the box outright,
	 * exactly like a reset or a pick — rather than painting anything, so nothing lingers on screen
	 * asserting a verdict about a search that was never actually run.
	 *
	 * A no-op when `buildSearchLayout()` has never been called, matching every other guarded method
	 * in this section.
	 *
	 * @since 2.0.2
	 * @param {Object} results
	 * @param {Array}  [results.points]    matching points from the ALREADY-LOADED pool.
	 * @param {Array}  [results.addresses] unused for a preview — the geocoder is never queried by
	 *                                     `searchType`, only by `searchSubmit` — accepted anyway so
	 *                                     the two methods share one call shape.
	 * @returns {void}
	 */
	Panels.prototype.previewSearchResults = function( results ) {
		if ( ! this._searchResults ) {
			return;
		}

		var painted = paintSearchSections( this, results );

		if ( ! painted ) {
			this.hideSearchResults();

			return;
		}

		closeFilterMenu( this );
		this._searchResults.hidden = false;
	};

	/**
	 * Empties and hides the search-results container WITHOUT ever rendering anything into it —
	 * round 2's direct answer to D1e/D1a ("the clear round-trip re-opens the box it just closed"):
	 * before this method existed, the ONLY way to close the results box was
	 * {@see Panels.prototype.renderSearchResults} itself, and calling that with a genuinely empty
	 * result immediately reopens it to show `noResults` — there was no way to say "close this and
	 * show nothing" as a distinct instruction from "a search came back empty".
	 *
	 * Called from every place a search interaction ends without the customer wanting to see the
	 * list any more: a point pick, an address pick (both in the "Search view" section above), the
	 * reset button's own click handler, and a `focusout` of the search wrap once focus leaves it
	 * entirely (all three wired in {@see Panels.prototype.buildSearchLayout}). The mount wires this
	 * to the map provider's `searchCleared` event too (T3's side of the contract; this file does
	 * not listen for that event itself).
	 *
	 * A no-op before `buildSearchLayout()` has ever run (`_searchResults` unset), matching every
	 * other guarded method in this section — closing a box that was never built is not an error.
	 *
	 * @since 2.0.2
	 * @returns {void}
	 */
	Panels.prototype.hideSearchResults = function() {
		if ( ! this._searchResults ) {
			return;
		}

		empty( this._searchResults );
		this._searchResults.hidden = true;
	};

	/**
	 * Renders the explicit "nothing nearby" empty state (Task 15, D-6): the
	 * `nothingNearby` message, the nearest point's name and distance, and a
	 * button offering to show it anyway — never a silently empty map, which
	 * would read to the customer as "there are no pickup points at all".
	 * Replaces the list body's current content; `info.name` is an
	 * already-`esc_html()`-escaped point field (written via `innerHTML`,
	 * matching every other point field in this file), `info.distanceMeters`
	 * is formatted through `pickup-geo.js`'s own `formatDistance()` so it
	 * never disagrees with the distance shown anywhere else on screen.
	 *
	 * @param {Object} info
	 * @param {number} info.distanceMeters distance to the nearest point, in metres.
	 * @param {string} info.name           the nearest point's (already-escaped) name.
	 * @returns {void}
	 */
	Panels.prototype.showNothingNearby = function( info ) {
		var self = this;

		empty( this._listBodyEl );

		var wrap = document.createElement( 'div' );
		wrap.className = 'woodev-pickup-list__nothing-nearby';

		var message = document.createElement( 'p' );
		message.className = 'woodev-pickup-list__nothing-nearby-message';
		message.textContent = text( this._config, 'nothingNearby' );
		wrap.appendChild( message );

		var detail = document.createElement( 'p' );
		detail.className = 'woodev-pickup-list__nothing-nearby-detail';

		var nameEl = document.createElement( 'span' );
		nameEl.innerHTML = fieldValue( info && info.name ); // eslint-disable-line -- server-escaped point field.
		detail.appendChild( nameEl );

		var distanceEl = document.createElement( 'span' );
		distanceEl.textContent = ' (' + geo.formatDistance( info && info.distanceMeters, this._config.lang ) + ')';
		detail.appendChild( distanceEl );

		wrap.appendChild( detail );

		var button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'woodev-pickup-list__show-nearest';
		button.textContent = text( this._config, 'showNearest' );
		button.addEventListener( 'click', function() {
			self._emit( 'showNearestRequested', info );
		} );
		wrap.appendChild( button );

		this._listBodyEl.appendChild( wrap );
	};

	/**
	 * Reports the point types currently known to the caller (Task 16, D-10; relocated by Task 13,
	 * spec V-8). Accumulates every distinct `{ code, label }` pair FIRST-SEEN across every call —
	 * a type once seen is never forgotten, even when a later call reports fewer — and builds/shows
	 * the filter once a SECOND distinct type has ever been seen. Once shown, the filter is never
	 * detached again (see the file docblock's "THE TYPE FILTER" note). A newly-seen type defaults
	 * to selected; a type already known keeps its current selection state across repeated calls.
	 *
	 * The DOM itself is built and attached HERE, lazily, on whichever call first reaches 2+ types —
	 * never in `render()` — because where it attaches (see {@see attachFilterEl}) depends on
	 * whether `buildSearchLayout()` ever ran, which this method has no control over and must not
	 * assume either way.
	 *
	 * @param {Array} types `{ code, label }` pairs.
	 * @returns {void}
	 */
	Panels.prototype.setTypes = function( types ) {
		var self = this;

		( types || [] ).forEach( function( type ) {
			if ( ! type || 'string' !== typeof type.code ) {
				return;
			}

			if ( ! Object.prototype.hasOwnProperty.call( self._filterLabels, type.code ) ) {
				self._filterOrder.push( type.code );
				self._filterSelected[ type.code ] = true;
			}

			self._filterLabels[ type.code ] = type.label;
		} );

		if ( ! self._filterShown && self._filterOrder.length >= 2 ) {
			self._filterShown = true;
		}

		if ( ! self._filterShown ) {
			return;
		}

		ensureFilterEl( self );
		attachFilterEl( self );

		renderFilterRows( self );
		updateFilterBadge( self );
	};

	/**
	 * Sets `.woodev-pickup-stage`'s `is-open` class to exactly `open`, and emits `listToggle`
	 * (`{ open, width }`) — but ONLY when this call actually CHANGES the visible open state (round
	 * 2, coordinator fix — the second half of operator defect 5, plus defect 8). `listToggle` is
	 * the ONLY event the mount listens to in order to call `provider.setMargin( open, width )`
	 * (ymaps' `map.margin.addArea()`), which is what reserves the sidebar's screen area so the map
	 * knows not to centre a point underneath it. Before this helper existed, {@see
	 * Panels.prototype.openList} and {@see Panels.prototype.openCard} flipped `is-open` on WITHOUT
	 * telling anyone: a marker click slid the sidebar in, ymaps was never told, and
	 * `focusGroup()`'s camera move (`useMapMargin: true`) had nothing reserved to avoid — the point
	 * landed off-centre, under the panel that had just opened. The same missing reservation also
	 * let the sidebar sit over ymaps' own copyright strip (defect 8), which its ToS forbids.
	 *
	 * {@see Panels.prototype.toggleList}, {@see Panels.prototype.openList}, and
	 * {@see Panels.prototype.openCard} all route through this ONE place now, so `listToggle` fires
	 * exactly once per ACTUAL transition and stays silent on a call that finds the sidebar already
	 * in the requested state — re-opening an already-open sidebar (the common case for
	 * `openCard()`: most card opens happen with the list already showing) must NOT re-emit, or the
	 * mount would churn `addArea()`/`remove()` on every single card click.
	 *
	 * `width` is NOT the panel's own width (#168): it is the width of the strip the panel occupies
	 * measured from the STAGE's right edge, i.e. `offsetWidth + PANEL_GUTTER_PX` — the panels float
	 * 16px in from that edge now rather than sitting flush against it. The consumer is ymaps'
	 * `map.margin.addArea()`, which reserves from the map's edge inwards and knows nothing about
	 * our gap; reporting the bare `offsetWidth` would leave the reservation 16px short of the area
	 * actually covered, which is precisely the shape of the `ymaps-margin-area-needs-explicit-width`
	 * defect (a reservation that resolved and looked correct while standing for less than it should).
	 *
	 * Deliberately does NOT touch `is-card` — whether a transition ALSO changes which panel is
	 * showing (list vs. card) is each caller's own business (see their own docblocks), but whether
	 * the sidebar itself is showing at all is not, which is exactly the one thing this helper owns.
	 *
	 * @param {Panels}  self
	 * @param {boolean} open
	 * @returns {void}
	 */
	function setStageOpen( self, open ) {
		var wasOpen = self._stage.classList.contains( 'is-open' );

		self._stage.classList.toggle( 'is-open', open );

		// The toggle's accessible name follows the state, because pressing it does the opposite
		// thing in each (#168): open → collapse back to the map, closed → open the drawer. This
		// is also what keeps WCAG 2.5.3 (Label in Name) satisfied once the mobile bar renders
		// «Показать карту» visibly — a fixed `drawerTitle` name would no longer contain the
		// visible text. Set OUTSIDE the `wasOpen === open` early return below on purpose: that
		// guard exists to stop `listToggle` re-firing, and the name must be correct even on a
		// call that changes nothing.
		if ( self._toggleEl ) {
			self._toggleEl.setAttribute( 'aria-label', text( self._config, open ? 'showMap' : 'drawerTitle' ) );
		}

		if ( wasOpen === open ) {
			return;
		}

		self._emit( 'listToggle', { open: open, width: self._listEl.offsetWidth + PANEL_GUTTER_PX } );
	}

	/**
	 * Flips the STAGE's open/closed state — routes through {@see setStageOpen}, which is what
	 * actually emits `listToggle` (unconditionally here, since a flip always changes the state by
	 * definition) with the new state plus the list's own current width, so a caller (the map
	 * provider) can size the map's margin to avoid the panel covering it.
	 *
	 * The open/closed state lives on `_stage`, not on `_listEl`/`_cardEl`
	 * independently — see the file's Task 6 note (D-6/П-7): `is-open` means
	 * "a right-hand panel is showing", `is-card` means "that panel is the
	 * card". Collapsing (`open` was true) always clears BOTH classes, so a
	 * card left open when the customer collapses the sidebar is dismissed
	 * along with it — before this, the card had its own independent
	 * `is-open` state and stayed on screen with no way to dismiss it once the
	 * toggle button (which sits on the list, not the card) slid away.
	 * Reopening always returns to the LIST, never back to the card that was
	 * showing when it got collapsed (`is-card` is never restored here).
	 *
	 * Round 2: `is-open` is no longer a purely visual/CSS concern — it is also what the mount
	 * learns about through `listToggle` (see {@see setStageOpen}) in order to reserve the map's
	 * margin, so getting the open state right here is now a correctness requirement for the
	 * camera, not just for what the customer sees.
	 *
	 * @returns {void}
	 */
	Panels.prototype.toggleList = function() {
		var wasOpen = this._stage.classList.contains( 'is-open' );
		var nextOpen = ! wasOpen;

		if ( ! nextOpen ) {
			this._stage.classList.remove( 'is-card' );
		}

		setStageOpen( this, nextOpen );
	};

	/**
	 * Shows the list, DETERMINISTICALLY — unlike {@see toggleList}, which flips whatever is
	 * showing now, this always ends in the list state: `is-open` on, `is-card` off, whatever a
	 * card was showing dismissed.
	 *
	 * Exists for picking an address (spec V-6, D-6): "the sidebar opens automatically, sorted
	 * by distance from the searched address" is not conditional on nothing else being open. The
	 * mount's `setAnchor()` call re-sorts the list body, but does not touch which panel is
	 * VISIBLE — before this method existed, picking an address while a point's card happened to
	 * be open left that stale card on screen, with the newly-sorted list invisible behind it.
	 *
	 * Round 2: routes the open-state change through {@see setStageOpen}, so this now emits
	 * `listToggle {open:true}` exactly when it actually opens a closed sidebar (a search picking an
	 * address behind a closed sidebar is precisely the case that used to leave the map's margin
	 * unreserved), and stays silent when the sidebar was already open — dismissing a stale card
	 * while the sidebar itself stays open is not a margin change, so it must not re-fire.
	 *
	 * @since 2.0.2
	 * @returns {void}
	 */
	Panels.prototype.openList = function() {
		this._stage.classList.remove( 'is-card' );
		this._activeGroup = null;

		setStageOpen( this, true );
	};

	/**
	 * Opens the card on one group, showing `pointId` when given (and found
	 * in the group), otherwise the group's first point. This is what a click
	 * on the SECOND point of a co-located list row must do — always the
	 * REQUESTED point, never always the first (spec).
	 *
	 * Adds BOTH `is-open` (via {@see setStageOpen}) and `is-card` to `_stage` — see
	 * {@see toggleList}'s docblock for why the open state lives there rather than on `_cardEl`
	 * itself: a single class removal (a sidebar collapse) then hides the
	 * card along with the list, instead of leaving it stranded on screen.
	 *
	 * `origin` (round 2, D6 — see the file docblock's revised `cardOpened` note) is REQUIRED at
	 * every internal call site in this file (the sidebar row builders pass `'list'`) and is
	 * carried verbatim into the `cardOpened` payload, unexamined — this method does not branch on
	 * it, it only threads it through for whoever is listening (the mount) to act on. A caller from
	 * outside this file (the mount, routing a marker click, a search pick, or "show nearest") is
	 * responsible for its own label; omitting it is not a crash, just an `undefined` `origin` in
	 * the payload, since jest exercises this method directly without a mount in the room.
	 *
	 * ORDERING IS LOAD-BEARING (round 2 coordinator fix): `listToggle` — via
	 * {@see setStageOpen}, fired only when a CLOSED sidebar is opening — is emitted BEFORE
	 * `cardOpened`, never after. The mount turns `listToggle` into `provider.setMargin()` and
	 * `cardOpened` into `provider.focusGroup()`; both handlers run synchronously off these two
	 * emits, so if `cardOpened` fired first, the camera would move BEFORE the map margin was
	 * reserved — the exact "point lands under the panel" defect this fix exists for, just moved
	 * one line later and harder to notice. DO NOT reorder these two emits, even to "tidy" the
	 * method into "state first, then business event" — that instinct is what would reintroduce
	 * the bug.
	 *
	 * @param {Object}        group
	 * @param {string|number} [pointId]
	 * @param {string}        [origin] `'marker'|'list'|'search'|'nearest'|'restore'` — see the file
	 *                                 docblock.
	 * @returns {void}
	 */
	Panels.prototype.openCard = function( group, pointId, origin ) {
		var index = 0;

		if ( undefined !== pointId && null !== pointId ) {
			for ( var i = 0; i < group.points.length; i++ ) {
				if ( String( group.points[ i ].id ) === String( pointId ) ) {
					index = i;
					break;
				}
			}
		}

		this._activeGroup = group;
		this._activeIndex = index;

		// listToggle (if the sidebar was closed) MUST land before cardOpened — see this method's
		// own "ORDERING IS LOAD-BEARING" docblock note above. Do not move this below the
		// `cardOpened` emit.
		setStageOpen( this, true );
		this._stage.classList.add( 'is-card' );

		// EVERY route to a card passes through here — a marker click, a sidebar row, a search
		// result, "show the nearest" — so this is the one place a listener can learn that a point
		// became the subject, whoever asked. `origin` is what lets that listener (the mount) tell
		// the routes apart — see the file docblock's revised `cardOpened` note (D6): a marker click
		// only pans the camera, everything else zooms in, and the original "must behave
		// identically" sentence this replaced was wrong.
		//
		// Emitted BEFORE the card renders, in that order deliberately: the camera move is a
		// ~400ms animation and the card is synchronous DOM, so starting the flight first means
		// the two land together instead of the map lurching after the card is already readable.
		// Nothing here awaits the move — the card owes the viewport nothing.
		this._emit( 'cardOpened', { group: group, pointId: group.points[ index ].id, origin: origin } );

		renderCard( this );
	};

	/**
	 * Closes the card, covering it back with the list (the card sits ABOVE
	 * the list at a higher `z-index` rather than replacing it — spec).
	 *
	 * Removes `is-card` ONLY — `is-open` is left alone, so the list (which
	 * was underneath the card the whole time) stays visible rather than
	 * closing the whole sidebar as a side effect of dismissing the card.
	 *
	 * @returns {void}
	 */
	Panels.prototype.closeCard = function() {
		this._stage.classList.remove( 'is-card' );
		this._activeGroup = null;
	};

	/**
	 * Records the caller's currently selected point id, so a later
	 * `openCard()` (or an already-open card, re-rendered here) shows the
	 * CTA's `continueCheckout` label instead of `select` for that one point.
	 *
	 * Also rebuilds the list (Task 10): the sidebar row carries its own persistent `is-selected`
	 * marker, since the CTA's label flip alone is invisible once the customer scrolls the open
	 * card's row out of view. That rebuild is a FULL `renderListBody()` — up to {@see LIST_CAP}
	 * DOM nodes recreated and every row's click listener re-attached, just to move one class — and
	 * only runs once `render()` exists (`this.root`); called before that, this method still only
	 * updates `_selectedId` and, if a card is open, `renderCard()`.
	 *
	 * SKIPS THAT REBUILD WHEN THE NORMALIZED ID HASN'T ACTUALLY CHANGED (#172).
	 * `pickup-mount.js`'s restore pass (spec D-15) calls this method TWICE with the exact same
	 * value for one session open under `strategy: 'viewport'`: `alreadySelected` seeds
	 * `_selectedId` before the first fetch even starts, and `restoreSelection()` calls this again,
	 * once the map is ready, with the SAME id read off the SAME field — nothing about the
	 * selection moved between the two calls, so the second `renderListBody()` recreates every row
	 * only to reproduce the identical `is-selected` class the first render already painted. Every
	 * OTHER reason the list might need rebuilding already goes through its own method
	 * (`setVisible()` when the groups change, `setAnchor()` when the sort anchor moves, the type
	 * filter's own handler) — this method's contract is "reflect a selection CHANGE", and a
	 * repeated call carrying the CHANGED id (a customer picking a different row, or the mount's
	 * own restore call finding a DIFFERENT current value than what was seeded) still rebuilds
	 * exactly as before; only the no-op repeat is now free.
	 *
	 * `renderCard()` stays UNCONDITIONAL, gated only on `this._activeGroup`, never on whether the
	 * id changed — it is NOT redundant work the way the list rebuild is. Two real call paths rely
	 * on it running even when `_selectedId` itself did not just move in THIS call:
	 *
	 * - `pickup-mount.js`'s `finishSelection()` (a confirmed selection) clears the busy lock via
	 *   `setSelectionBusy( false )` — which reruns `renderCard()` against the STILL-OLD
	 *   `_selectedId` — BEFORE this method ever runs with the point's real id. This call is
	 *   therefore the one that actually flips the CTA from `confirming` to `continueCheckout`; it
	 *   never sees an "unchanged id" in that flow (the id is genuinely new), but the causality
	 *   matters here: it is proof this method's OWN `renderCard()` call, not
	 *   `setSelectionBusy()`'s, is what paints the label a customer actually sees, so it cannot be
	 *   the one gated.
	 * - `restoreSelection()` itself calls this method with a value already seeded, then opens the
	 *   card on that SAME id via `openCard()` — which calls `renderCard()` unconditionally on its
	 *   own, so the card is covered there regardless of what this method does. But a caller that
	 *   re-asserts the CTA state without going through `openCard()` (an already-open card, e.g. a
	 *   confirmation that lands while the customer is still looking at the same point) must not
	 *   silently stop working just because the id happens to already match.
	 *
	 * @param {string|number|null} id
	 * @returns {void}
	 */
	Panels.prototype.setSelectedId = function( id ) {
		var normalized = ( undefined !== id && null !== id ) ? String( id ) : null;
		var changed = normalized !== this._selectedId;

		this._selectedId = normalized;

		// The list carries the highlight too now (Task 10), so it must be rebuilt as well — not
		// only the card, which is all this method used to touch when `_selectedId` affected
		// nothing but the CTA's label. Guarded the same way `setAnchor()`/
		// `handleFilterCheckboxChange()` guard their own `renderList()` call: `self._listBodyEl`
		// does not exist before `render()` builds it, and `renderListBody()`'s `empty()` call has
		// no null-check, so an unconditional call here would throw for a caller that sets the
		// selection before the list has ever been rendered. `changed` is checked FIRST (see this
		// method's own docblock on #172) so a repeat call carrying the same id neither throws nor
		// rebuilds — both guards independently gate the SAME call, not two alternatives.
		if ( changed && this.root ) {
			renderList( this );
		}

		if ( this._activeGroup ) {
			renderCard( this );
		}
	};

	/**
	 * Marks a selection confirmation as in flight (or settled).
	 *
	 * Locking the card is not merely "do not click twice": it is what makes a SERVER-side
	 * ordering inversion impossible. Without it a customer can confirm point A, switch to B
	 * and confirm that too, B can reach the server first, and the server ends holding A while
	 * the browser shows B. A second request cannot leave while this is true.
	 *
	 * The stage's own `is-busy` is deliberately NOT reused: it is the "no data exists yet"
	 * state and hides the search and filter controls entirely (see `pickup.css`), which would
	 * make the search bar vanish under a customer who is merely confirming a point.
	 *
	 * The caller (Task 11's mount, wiring `dataSource.selectPoint()` — not this file, which has
	 * no network of its own) is expected to call `setSelectionBusy( true )` when a confirmation
	 * starts and `setSelectionBusy( false )` once it settles, on ALL THREE of its outcomes: the
	 * domain accepts the point, the domain refuses it, and — per spec D-9's staleness guard — an
	 * answer arrives for a point the card no longer shows and is discarded. This method does not
	 * track WHY it was called or guard against an unbalanced pair — the caller owns that
	 * discipline, same as {@see Panels.prototype.setBusy}'s own docblock note. Leaving a
	 * `true` unpaired locks every card this instance opens afterward, forever.
	 *
	 * A no-op on the class/re-render when called before `render()` — matching
	 * {@see Panels.prototype.setBusy}'s own guard shape — but `_selectionBusy` itself is always
	 * tracked, so a card opened later still starts locked if the flag was set early.
	 *
	 * @since 2.0.2
	 *
	 * @param {boolean} busy
	 * @returns {void}
	 */
	Panels.prototype.setSelectionBusy = function( busy ) {
		this._selectionBusy = !! busy;

		if ( this._cardEl ) {
			this._cardEl.classList.toggle( 'is-locked', this._selectionBusy );
		}

		if ( this._activeGroup ) {
			renderCard( this );
		}
	};

	/**
	 * Records a domain verdict against one point, so a refusal SURVIVES a card re-render (spec
	 * D-6/D-7). The framework's own fetch-time `selectable` verdict ({@see Constraint_Checker})
	 * is deliberately permissive about data a carrier's list response omits — a selection
	 * confirmation is where the real answer arrives. Writing it into the point object
	 * `self._groups` already holds needs no new rendering path: {@see buildCardFooter} already
	 * draws a warning and a dead CTA whenever `selectable.allowed === false`, so this render and
	 * every later one do the right thing on their own.
	 *
	 * Deliberately NOT reflected in the sidebar row (spec D-8): the list has no notion of a
	 * blocked point today, and giving it one would be new UI surface with new states.
	 *
	 * A no-op on the current card's DOM when the verdict's point is not the one currently
	 * shown — the write still lands on the held point, and the next `openCard()`/tab switch to
	 * it will read it correctly.
	 *
	 * @since 2.0.2
	 *
	 * @param {string|number}                             pointId
	 * @param {{allowed: boolean, reason: (string|null)}} verdict
	 * @returns {void}
	 */
	Panels.prototype.setPointVerdict = function( pointId, verdict ) {
		var id = String( pointId );

		this._groups.forEach( function( group ) {
			group.points.forEach( function( point ) {
				if ( String( point.id ) === id ) {
					point.selectable = {
						allowed: !! verdict.allowed,
						reason: 'string' === typeof verdict.reason ? verdict.reason : null,
					};
				}
			} );
		} );

		if ( this._activeGroup ) {
			renderCard( this );
		}
	};

	/**
	 * Shows a TRANSIENT selection failure — a dropped request, a timeout, a stale page — in the
	 * card's existing `.woodev-pickup-card__warning` slot (spec D-6/D-7). Deliberately NOT
	 * stored anywhere, unlike {@see Panels.prototype.setPointVerdict}: nothing about the point
	 * was refused, so the very next render (a re-render, a tab switch, a card close/reopen)
	 * must forget this message entirely and leave the CTA alive. Conflating the two would grey
	 * out a perfectly good point because one request happened to drop.
	 *
	 * A no-op when called before `render()` (no card element exists yet) or before any card has
	 * been opened (no footer to append into) — matching
	 * {@see Panels.prototype.setSelectionBusy}'s own guard shape.
	 *
	 * @since 2.0.2
	 *
	 * @param {string} message already-resolved text — the caller owns the i18n lookup.
	 * @returns {void}
	 */
	Panels.prototype.showSelectionError = function( message ) {
		if ( ! this._cardEl ) {
			return;
		}

		var footer = this._cardEl.querySelector( '.woodev-pickup-card__footer' );

		if ( ! footer ) {
			return;
		}

		var existing = footer.querySelector( '.woodev-pickup-card__warning' );

		if ( existing ) {
			existing.textContent = message;

			return;
		}

		var warning = document.createElement( 'div' );
		warning.className = 'woodev-pickup-card__warning';
		warning.textContent = message;
		footer.insertBefore( warning, footer.firstChild );
	};

	/**
	 * Tears the panels down: cancels anything pending and drops every listener.
	 *
	 * The picker is destroyed and rebuilt on every reopen, and the search debounce outlives the
	 * DOM it belongs to — a customer who types three characters and closes the dialog inside the
	 * debounce window leaves a timer that fires against a dead instance, keeping it (and its whole
	 * element tree) alive until it does. Dropping the listener map matters for the same reason:
	 * the mount registers fresh callbacks on the new instance every time, and a retained old
	 * instance would answer with the previous session's closures.
	 *
	 * Round 3 (defect B) adds a second "outlives its instance" hazard of the exact same shape:
	 * {@see ensureFilterEl}'s outside-click listener is attached to `document`, not to an element
	 * this instance owns, so removing the stage from the DOM does NOT remove it the way every
	 * other listener in this file is removed for free — it must be un-registered by the same
	 * function reference this stores on `_filterOutsideClickHandler`, or it keeps calling
	 * `closeFilterMenu()` against a dead instance's detached menu on every future click anywhere
	 * in the document, for as long as the page lives. Round 4 adds a THIRD one of the identical
	 * shape: {@see Panels.prototype.buildSearchLayout}'s own `_searchOutsideClickHandler` for the
	 * search results box, removed the same way for the same reason.
	 *
	 * Idempotent, and safe to call before `render()` ever ran.
	 *
	 * @since 2.0.2
	 * @returns {void}
	 */
	Panels.prototype.destroy = function() {
		if ( this._searchTimer ) {
			window.clearTimeout( this._searchTimer );
			this._searchTimer = null;
		}

		if ( this._searchOutsideClickHandler ) {
			document.removeEventListener( 'click', this._searchOutsideClickHandler );
			this._searchOutsideClickHandler = null;
		}

		if ( this._filterOutsideClickHandler ) {
			document.removeEventListener( 'click', this._filterOutsideClickHandler );
			this._filterOutsideClickHandler = null;
		}

		this._listeners = {};

		if ( this._stage && this._stage.parentNode ) {
			this._stage.parentNode.removeChild( this._stage );
		}
	};

	// -------------------------------------------------------------------------
	// UMD-ish dual export
	// -------------------------------------------------------------------------

	// Browser global
	if ( 'undefined' !== typeof window ) {
		window.WoodevPickupPanels = Panels;
	}

	// CommonJS (jest)
	if ( 'undefined' !== typeof module && module.exports ) {
		module.exports = Panels;
	}

}() );
