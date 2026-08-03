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
 * `cardOpened` (Task 10, spec V-10): emitted by `openCard()` for EVERY route to a card — a marker
 * click, a sidebar row, a search result, "show the nearest" — carrying `{ group, pointId }`. It
 * exists so a listener can react to "this point became the subject" without caring who asked; the
 * mount uses it to move the camera, which is what keeps a marker click and a sidebar-row click
 * behaving identically. Emitted BEFORE the card renders, so the asynchronous camera flight and the
 * synchronous DOM land together rather than the map lurching after the card is already readable.
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
 * THE TYPE FILTER (Task 16, reworked Task 13, D-10/V-8): `setTypes( types )` accumulates distinct
 * `{ code, label }` pairs FIRST-SEEN across every call and renders the filter control once a
 * SECOND distinct type has ever been seen — and, once shown, it never disappears again, even if a
 * later call reports only one type (a momentary single-type viewport must not flicker the control
 * away). The last CHECKED type cannot be unchecked (the Yandex reference's own rule): the click is
 * silently refused — the checkbox is reverted and no `typeFilterChange` fires — because an empty
 * selection would read to the customer as "no pickup points exist" (see the file docblock's own
 * operator-instruction note, immediately below the reference's opposite "empty means unfiltered"
 * behaviour is deliberately NOT copied). The count badge shows only while the selection is PARTIAL
 * (never for "all selected", never as a plain type count) and carries the number of types
 * currently SELECTED. `typeFilterChange` carries the selected codes as a plain array; whether that
 * becomes a client-side filter or a server refetch is the caller's decision (Task 20), not this
 * file's.
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

	/** @type {number} minimum query length before point matching fires at all (spec V-6). */
	var SEARCH_MIN_CHARS = 3;

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
	 * Builds one labelled element: a wrapper `div.{cls}` containing a
	 * `textContent`-safe label and an `innerHTML` value carrying an
	 * ALREADY-escaped point field. Used for every optional point-detail row
	 * (phone, work time, weight, payment methods, …) so the label/value
	 * escaping split from the file docblock is applied in exactly one place.
	 *
	 * @param {string} cls   BEM block/element class, e.g. 'woodev-pickup-card__phone'.
	 * @param {string} label i18n label text (unescaped; written via textContent).
	 * @param {string} value ALREADY-escaped point field HTML (written via innerHTML).
	 * @returns {HTMLElement}
	 */
	function labelledRow( cls, label, value ) {
		var row = document.createElement( 'div' );
		row.className = cls;

		var labelEl = document.createElement( 'span' );
		labelEl.className = cls + '-label';
		labelEl.textContent = label;

		var valueEl = document.createElement( 'span' );
		valueEl.className = cls + '-value';
		valueEl.innerHTML = value; // eslint-disable-line -- value is server-escaped, see file docblock.

		row.appendChild( labelEl );
		row.appendChild( document.createTextNode( ' ' ) );
		row.appendChild( valueEl );

		return row;
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
	 * The plugin's icon URL for a point's type, or `''` when the plugin supplies none.
	 * The sidebar shows the PLUGIN's icon only — the framework's own default marker (V-9) is
	 * map furniture and would read as decoration in a list.
	 *
	 * @param {Object} config
	 * @param {Object} point
	 * @returns {string}
	 */
	function pointIconUrl( config, point ) {
		var icons = ( config && config.pointIcons ) || {};
		var code = ( point && point.type && point.type.code ) || '';

		return ( icons[ code ] && icons[ code ].default ) || '';
	}

	/**
	 * Builds one list row for a single-point group (spec V-11): the plugin's type icon (only
	 * when {@see pointIconUrl} finds one), address in bold, name/description as the muted
	 * subtitle, and — when an anchor is set — the formatted distance. Icon, then address, then
	 * name is the order the spec asks for: the address is what the customer scans the list FOR,
	 * the name/description is secondary detail. `short_address`/`address`/`name` are
	 * already-escaped point fields (see the file docblock) and are written via `innerHTML` here,
	 * same as everywhere else in this file; the `title` attributes carry the DECODED text
	 * instead (see {@see decodeForTitle}) because an HTML attribute value is never re-parsed as
	 * markup the way `innerHTML` is, so the raw escaped string would show literal entities on
	 * hover.
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
		var iconUrl = pointIconUrl( config, point );

		if ( iconUrl ) {
			var icon = document.createElement( 'img' );
			icon.className = 'woodev-pickup-list__icon';
			icon.src = iconUrl;
			icon.alt = '';
			wrap.appendChild( icon );
		}

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

		if ( group.points.length > 1 ) {
			group.points.forEach( function( point ) {
				var button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'woodev-pickup-list__point';
				button.dataset.pointId = String( point.id );
				button.appendChild( buildSinglePointRow( point, anchor, group, locale, self._config ) );
				button.addEventListener( 'click', function() {
					self.openCard( group, point.id );
				} );
				item.appendChild( button );
			} );

			return item;
		}

		var onlyPoint = group.points[ 0 ];
		item.appendChild( buildSinglePointRow( onlyPoint, anchor, group, locale, self._config ) );
		item.addEventListener( 'click', function() {
			self.openCard( group, onlyPoint.id );
		} );

		return item;
	}

	/**
	 * Rebuilds the list body: the empty state, or up to {@see LIST_CAP}
	 * ordered items — never more, see the file docblock.
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
		var capped = ordered.slice( 0, LIST_CAP );

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
	 * list-row click does.
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
	 * that array and resolving it.
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
	 * Builds the card's tab bar for a co-located group, or `null` for a
	 * single-point one (D-4: no tab bar when there is nothing to switch
	 * between). Tabs are labelled by `type.label`; the WHOLE group falls
	 * back to `name` the moment ANY two points in it share a label — never a
	 * per-point decision (see the file docblock).
	 *
	 * @param {Panels} self
	 * @param {Object} group
	 * @returns {HTMLElement|null}
	 */
	function buildTabs( self, group ) {
		if ( group.points.length <= 1 ) {
			return null;
		}

		var typeLabels = group.points.map( function( point ) {
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
			? group.points.map( function( point ) {
				return fieldValue( point.name );
			} )
			: typeLabels;

		var tabs = document.createElement( 'div' );
		tabs.className = 'woodev-pickup-card__tabs';

		group.points.forEach( function( point, index ) {
			var tab = document.createElement( 'button' );
			tab.type = 'button';
			tab.className = 'woodev-pickup-card__tab' + ( index === self._activeIndex ? ' is-active' : '' );
			tab.innerHTML = labels[ index ]; // eslint-disable-line -- server-escaped point field, see file docblock.
			tab.addEventListener( 'click', function() {
				self._activeIndex = index;
				renderCard( self );
			} );
			tabs.appendChild( tab );
		} );

		return tabs;
	}

	/**
	 * Builds the card's header row: the tab bar (only for a co-located
	 * group — {@see buildTabs} returns `null` for a single-point one) plus a
	 * close control that ALWAYS renders, tabs or not — this is the
	 * customer's only way back to the list without dismissing the whole
	 * modal (spec §6, STATE 3). Named via the EXISTING `close` i18n key (the
	 * same one the modal shell's own close button already uses), not an
	 * invented one — see the file docblock's I1 note and the toggle
	 * button's own `aria-label` for the identical discipline.
	 *
	 * @param {Panels} self
	 * @param {Object} group
	 * @returns {HTMLElement}
	 */
	function buildCardHeader( self, group ) {
		var header = document.createElement( 'div' );
		header.className = 'woodev-pickup-card__header';

		var tabs = buildTabs( self, group );

		if ( tabs ) {
			header.appendChild( tabs );
		}

		var close = document.createElement( 'button' );
		close.type = 'button';
		close.className = 'woodev-pickup-card__close';
		close.setAttribute( 'aria-label', text( self._config, 'close' ) );
		close.textContent = '✕'; // decorative; aria-label carries the meaning (matches woodev-modal.js's close button).
		close.addEventListener( 'click', function() {
			self.closeCard();
		} );
		header.appendChild( close );

		return header;
	}

	/**
	 * Builds the card body for one point: title, optional postal code and
	 * address, an optional "how to get there" detail, services as chips
	 * (omitted entirely when there are none), payment methods, phone, work
	 * time and a formatted weight limit — each optional section rendered
	 * only when its field is actually present (mirrors the balloon builder
	 * `map-provider-yandex.js` is being retired in favour of).
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

		if ( fieldValue( point.address ) ) {
			var address = document.createElement( 'div' );
			address.className = 'woodev-pickup-card__address';
			address.innerHTML = fieldValue( point.address ); // eslint-disable-line -- server-escaped.
			body.appendChild( address );
		}

		if ( fieldValue( point.instruction ) ) {
			var howto = document.createElement( 'details' );
			howto.className = 'woodev-pickup-card__howto';

			var summary = document.createElement( 'summary' );
			summary.textContent = text( config, 'howToGet' );

			var content = document.createElement( 'div' );
			content.innerHTML = fieldValue( point.instruction ); // eslint-disable-line -- server-escaped.

			howto.appendChild( summary );
			howto.appendChild( content );
			body.appendChild( howto );
		}

		if ( Array.isArray( point.services ) && point.services.length > 0 ) {
			var services = document.createElement( 'div' );
			services.className = 'woodev-pickup-card__services';

			var servicesLabel = document.createElement( 'span' );
			servicesLabel.className = 'woodev-pickup-card__services-label';
			servicesLabel.textContent = text( config, 'services' );
			services.appendChild( servicesLabel );

			point.services.forEach( function( service ) {
				var chip = document.createElement( 'span' );
				chip.className = 'woodev-pickup-card__service';
				chip.innerHTML = fieldValue( service ); // eslint-disable-line -- server-escaped.
				services.appendChild( chip );
			} );

			body.appendChild( services );
		}

		if ( Array.isArray( point.payment_methods ) && point.payment_methods.length > 0 ) {
			var paymentsValue = point.payment_methods.map( fieldValue ).join( ', ' );
			var paymentsLabel = text( config, 'paymentMethods' );
			body.appendChild( labelledRow( 'woodev-pickup-card__payments', paymentsLabel, paymentsValue ) );
		}

		if ( fieldValue( point.phone ) ) {
			var phoneLabel = text( config, 'phone' );
			body.appendChild( labelledRow( 'woodev-pickup-card__phone', phoneLabel, fieldValue( point.phone ) ) );
		}

		if ( fieldValue( point.work_time ) ) {
			var workTimeLabel = text( config, 'workTime' );
			var workTimeValue = fieldValue( point.work_time );
			body.appendChild( labelledRow( 'woodev-pickup-card__worktime', workTimeLabel, workTimeValue ) );
		}

		if ( null !== point.max_weight && undefined !== point.max_weight ) {
			var weightLabel = text( config, 'maxWeight' );
			var weightValue = formatWeightKg( point.max_weight );
			body.appendChild( labelledRow( 'woodev-pickup-card__weight', weightLabel, weightValue ) );
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
		cta.className = 'woodev-pickup-card__cta';
		cta.textContent = isSelected ? text( self._config, 'continueCheckout' ) : text( self._config, 'select' );
		cta.disabled = ! selectable.allowed;
		cta.addEventListener( 'click', function() {
			if ( ! selectable.allowed ) {
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
	 * point, never a stale one left over from a previous render.
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

		self._cardEl.appendChild( buildCardHeader( self, group ) );
		self._cardEl.appendChild( buildCardBody( self._config, point ) );
		self._cardEl.appendChild( buildCardFooter( self, point ) );
	}

	// -------------------------------------------------------------------------
	// Type filter menu (Task 16, moved into the search control by Task 13, D-10/V-8)
	// -------------------------------------------------------------------------

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

			var checkbox = document.createElement( 'input' );
			checkbox.type = 'checkbox';
			checkbox.className = 'woodev-pickup-filter__checkbox';
			checkbox.checked = Boolean( self._filterSelected[ code ] );
			checkbox.dataset.code = code;
			checkbox.addEventListener( 'change', function() {
				handleFilterCheckboxChange( self, code, checkbox );
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
	 * Shows or hides the partial-selection count badge: visible only while
	 * the selection is PARTIAL (strictly fewer selected types than known
	 * types) — never for "all selected" (there would be nothing to call out)
	 * and never as a plain count of types (spec). Its text is the number of
	 * types CURRENTLY SELECTED, not the number excluded. Task 13 changed this
	 * from attach/detach to a plain `.hidden` flip — the badge is now a
	 * permanent child of the toggle (see {@see ensureFilterEl}), never
	 * inserted into or removed from the menu.
	 *
	 * @param {Panels} self
	 * @returns {void}
	 */
	function updateFilterBadge( self ) {
		var selectedCount = self._filterOrder.filter( function( code ) {
			return Boolean( self._filterSelected[ code ] );
		} ).length;

		var partial = selectedCount < self._filterOrder.length;

		self._badgeEl.hidden = ! partial;

		if ( partial ) {
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
	 * at all". Every other change is accepted, updates the badge, and emits
	 * the full list of currently selected codes.
	 *
	 * @param {Panels}       self
	 * @param {string}       code
	 * @param {HTMLInputElement} checkbox
	 * @returns {void}
	 */
	function handleFilterCheckboxChange( self, code, checkbox ) {
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
		updateFilterBadge( self );

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

		// Task 11 (spec V-6): set only once `buildSearchLayout()` actually runs — a Panels instance
		// the caller never asks for a search layout (e.g. `config.search === false`) never gets
		// these, which is exactly why `renderSearchResults()` guards on `_searchResults` being unset.
		this._searchTimer = null;
		this._searchInput = null;
		this._searchResults = null;

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
	 * Idempotent in the sense that this project never calls it twice on the same instance; a
	 * second call would append a second subtree, so callers must not do that.
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

		// No dedicated i18n key exists for this control (the PHP handler's own
		// `get_js_config()` i18n array does not carry one) — it is a purely
		// visual chevron affordance (Task 21 styles it), accessibly NAMED by
		// the drawer it opens/closes, `drawerTitle`, rather than inventing an
		// untested key that would render permanently blank (I1). This is also
		// `drawerTitle`'s ONLY remaining home: Task 7 (spec V-11) deleted the
		// list header the key used to feed, since neither reference has one
		// and it stated something the customer could already see.
		var toggle = document.createElement( 'button' );
		toggle.type = 'button';
		toggle.className = 'woodev-pickup-list__toggle';
		toggle.setAttribute( 'aria-label', text( this._config, 'drawerTitle' ) );

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

		empty( this._container );
		this._container.appendChild( stage );

		this.root = root;
		this._stage = stage;
		this._mapEl = map;
		this._listEl = list;
		this._listBodyEl = body;
		this._cardEl = card;

		var self = this;
		toggle.addEventListener( 'click', function() {
			self.toggleList();
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
	 * that method's own docblock for what happens when it is called before this one.
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

		var submit = document.createElement( 'button' );
		submit.type = 'submit';
		submit.className = 'woodev-pickup-search__submit';
		submit.setAttribute( 'aria-label', text( this._config, 'search' ) );

		var results = document.createElement( 'div' );
		results.className = 'woodev-pickup-search__results';
		results.hidden = true;

		form.appendChild( input );
		form.appendChild( reset );
		form.appendChild( submit );
		search.appendChild( form );
		search.appendChild( results );
		wrap.appendChild( search );

		form.addEventListener( 'submit', function( event ) {
			// Without this the browser submits the CHECKOUT form the modal was opened from —
			// see the task report/docblock note on why this line cannot be skipped.
			event.preventDefault();

			var value = input.value.trim();

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
			results.hidden = true;
			empty( results );
			self._emit( 'searchReset', {} );
		} );

		this._searchInput = input;
		this._searchResults = results;
		this._controlsEl = wrap;

		return wrap;
	};

	/**
	 * Renders the search view's two independent sections (Task 15, D-6) into the results
	 * container {@see Panels.prototype.buildSearchLayout} builds: matching pool points and
	 * geocoder address suggestions. Each section is OMITTED ENTIRELY (not an empty heading) when
	 * its own array is empty — see {@see buildSearchSection} — and when BOTH are empty, renders
	 * `text( config, 'noResults' )` instead of an empty box (Task 11, spec V-6). Fully rebuilds
	 * the results container on every call, matching every other render function in this file, and
	 * un-hides it (the layout starts with `results.hidden = true`).
	 *
	 * A no-op when `buildSearchLayout()` has never been called (`_searchResults` unset) — the
	 * layout is built on demand and this method must not throw just because it ran first.
	 *
	 * Task 13 (spec V-8): this un-hiding is also the OTHER half of "opening either menu closes the
	 * other" — see `buildSearchLayout()`'s filter toggle handler for the reverse direction. A
	 * filter menu left open while a search result appears would sit on top of it or beside it
	 * fighting for the same corner of screen, so this is the one place results actually become
	 * visible and the one place that needs to close it.
	 *
	 * @param {Object} results
	 * @param {Array}  [results.points]    matching points from the loaded pool.
	 * @param {Array}  [results.addresses] geocoder address suggestions, `{ displayName }` each.
	 * @returns {void}
	 */
	Panels.prototype.renderSearchResults = function( results ) {
		var self = this;
		var points = ( results && results.points ) || [];
		var addresses = ( results && results.addresses ) || [];

		if ( ! this._searchResults ) {
			return;
		}

		if ( this._filterMenuEl ) {
			this._filterMenuEl.hidden = true;
		}

		empty( this._searchResults );
		this._searchResults.hidden = false;

		var pointsSection = buildSearchSection(
			'points',
			text( this._config, 'sectionPoints' ),
			points,
			function( point ) {
				return buildSearchPointItem( self, point );
			}
		);

		if ( pointsSection ) {
			this._searchResults.appendChild( pointsSection );
		}

		var addressesSection = buildSearchSection(
			'addresses',
			text( this._config, 'sectionAddresses' ),
			addresses,
			function( address, index ) {
				return buildSearchAddressItem( self, address, index );
			}
		);

		if ( addressesSection ) {
			this._searchResults.appendChild( addressesSection );
		}

		if ( ! pointsSection && ! addressesSection ) {
			var noResults = document.createElement( 'div' );
			noResults.className = 'woodev-pickup-search__empty';
			noResults.textContent = text( this._config, 'noResults' );
			this._searchResults.appendChild( noResults );
		}
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
	 * Flips the STAGE's open/closed state and emits `listToggle` with the new
	 * state plus the list's own current width, so a caller (the map
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
	 * @returns {void}
	 */
	Panels.prototype.toggleList = function() {
		var open = this._stage.classList.contains( 'is-open' );

		this._stage.classList.toggle( 'is-open', ! open );

		if ( open ) {
			this._stage.classList.remove( 'is-card' );
		}

		this._emit( 'listToggle', { open: ! open, width: this._listEl.offsetWidth } );
	};

	/**
	 * Opens the card on one group, showing `pointId` when given (and found
	 * in the group), otherwise the group's first point. This is what a click
	 * on the SECOND point of a co-located list row must do — always the
	 * REQUESTED point, never always the first (spec).
	 *
	 * Adds BOTH `is-open` and `is-card` to `_stage` — see {@see toggleList}'s
	 * docblock for why the open state lives there rather than on `_cardEl`
	 * itself: a single class removal (a sidebar collapse) then hides the
	 * card along with the list, instead of leaving it stranded on screen.
	 *
	 * @param {Object}      group
	 * @param {string|number} [pointId]
	 * @returns {void}
	 */
	Panels.prototype.openCard = function( group, pointId ) {
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

		// EVERY route to a card passes through here — a marker click, a sidebar row, a search
		// result, "show the nearest" — so this is the one place a listener can learn that a
		// point became the subject, whoever asked. The mount uses it to move the camera, which
		// is what makes a marker click and a sidebar-row click behave identically (spec V-10).
		//
		// Emitted BEFORE the card renders, in that order deliberately: the camera move is a
		// ~400ms animation and the card is synchronous DOM, so starting the flight first means
		// the two land together instead of the map lurching after the card is already readable.
		// Nothing here awaits the move — the card owes the viewport nothing.
		this._emit( 'cardOpened', { group: group, pointId: group.points[ index ].id } );

		renderCard( this );
		this._stage.classList.add( 'is-open' );
		this._stage.classList.add( 'is-card' );
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
	 * @param {string|number|null} id
	 * @returns {void}
	 */
	Panels.prototype.setSelectedId = function( id ) {
		this._selectedId = ( undefined !== id && null !== id ) ? String( id ) : null;

		if ( this._activeGroup ) {
			renderCard( this );
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
