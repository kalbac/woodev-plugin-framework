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
 * SORTING HAS ONE RULE, NOT TWO MODES: both the list and any future search
 * result are ordered by distance from a SINGLE anchor point set via
 * `setAnchor()` — the map centre by default, a searched address when a
 * search is active (Task 15 sets it the same way). Distances render only
 * when an anchor is set; with no anchor, the list keeps the caller-supplied
 * order verbatim (deterministic — it never reshuffles on its own between
 * calls with the same input).
 *
 * THE RENDERED LIST IS CAPPED AT {@see LIST_CAP} ITEMS — silently dropping
 * the tail is not acceptable (spec), so the header's own count reads
 * `{cap}+` (e.g. `300+`) whenever the viewport holds more than the cap,
 * rather than the true count or the capped one.
 *
 * TAB LABELS (co-located groups, D-4): one tab per point, labelled by
 * `type.label`; the WHOLE group falls back to `name` labels the moment ANY
 * two points in it share a `type.label` — never a per-point decision, or the
 * tabs of one group would read inconsistently (spec).
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
	var DEFAULT_ACCENT = '#7f54b3';

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
	 * Formats the list header's in-view count — the plain number, or
	 * `{cap}+` once the viewport holds more groups than {@see LIST_CAP}
	 * actually renders. Never the raw over-cap count (spec: silently
	 * dropping the tail without saying so is not acceptable).
	 *
	 * @param {number} total
	 * @returns {string}
	 */
	function countLabel( total ) {
		return total > LIST_CAP ? LIST_CAP + '+' : String( total );
	}

	/**
	 * Rebuilds the list header's text: the `drawerTitle` i18n label, plus a
	 * `(count)` suffix once a viewport has actually been reported at least
	 * once via `setVisible()` — never before, so a config with no groups yet
	 * set reads as JUST the title (see the "blank i18n key" test: with an
	 * empty title AND no `setVisible()` call, the header must read `''`).
	 * Assigned via `textContent` — an i18n label containing markup renders
	 * as literal text, never executes.
	 *
	 * @param {Panels} self
	 * @returns {void}
	 */
	function renderListHeader( self ) {
		var title = text( self._config, 'drawerTitle' );
		var parts = [];

		if ( title ) {
			parts.push( title );
		}

		if ( self._hasViewport ) {
			parts.push( '(' + countLabel( self._groups.length ) + ')' );
		}

		self._listHeaderEl.textContent = parts.join( ' ' );
	}

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
	 * Builds one list row for a single-point group: name, short address, and
	 * (when an anchor is set) the formatted distance. Point fields are
	 * written via `innerHTML` (already escaped, see the file docblock).
	 *
	 * @param {Object}   point
	 * @param {number[]|null} anchor
	 * @param {Object}   group
	 * @param {string}   locale
	 * @returns {HTMLElement}
	 */
	function buildSinglePointRow( point, anchor, group, locale ) {
		var nameEl = document.createElement( 'span' );
		nameEl.className = 'woodev-pickup-list__name';
		nameEl.innerHTML = fieldValue( point.name ); // eslint-disable-line -- server-escaped.

		var addressEl = document.createElement( 'span' );
		addressEl.className = 'woodev-pickup-list__address';
		addressEl.innerHTML = fieldValue( point.short_address ); // eslint-disable-line -- server-escaped.

		var wrap = document.createDocumentFragment();
		wrap.appendChild( nameEl );
		wrap.appendChild( addressEl );

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
				button.appendChild( buildSinglePointRow( point, anchor, group, locale ) );
				button.addEventListener( 'click', function() {
					self.openCard( group, point.id );
				} );
				item.appendChild( button );
			} );

			return item;
		}

		var onlyPoint = group.points[ 0 ];
		item.appendChild( buildSinglePointRow( onlyPoint, anchor, group, locale ) );
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
	 * Rebuilds the whole list panel (header + body) from current state.
	 *
	 * @param {Panels} self
	 * @returns {void}
	 */
	function renderList( self ) {
		renderListHeader( self );
		renderListBody( self );
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
		var tabs = buildTabs( self, group );

		if ( tabs ) {
			self._cardEl.appendChild( tabs );
		}

		self._cardEl.appendChild( buildCardBody( self._config, point ) );
		self._cardEl.appendChild( buildCardFooter( self, point ) );
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
		this._hasViewport = false;
		this._selectedId = null;
		this._activeGroup = null;
		this._activeIndex = 0;
		this._listeners = {};

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
	 * Builds the panels' DOM subtree (list + card) and appends it to the
	 * container supplied at construction. Idempotent in the sense that this
	 * project never calls it twice on the same instance; a second call would
	 * append a second subtree, so callers must not do that.
	 *
	 * @returns {void}
	 */
	Panels.prototype.render = function() {
		var root = document.createElement( 'div' );
		root.className = 'woodev-pickup-panels';

		applyAccentColor( root, this._config );

		var list = document.createElement( 'div' );
		list.className = 'woodev-pickup-list';

		var header = document.createElement( 'div' );
		header.className = 'woodev-pickup-list__header';

		// No dedicated i18n key exists for this control (the PHP handler's own
		// `get_js_config()` i18n array does not carry one) — it is a purely
		// visual chevron affordance (Task 21 styles it), accessibly NAMED by
		// the drawer it opens/closes, `drawerTitle`, rather than inventing an
		// untested key that would render permanently blank (I1).
		var toggle = document.createElement( 'button' );
		toggle.type = 'button';
		toggle.className = 'woodev-pickup-list__toggle';
		toggle.setAttribute( 'aria-label', text( this._config, 'drawerTitle' ) );

		var body = document.createElement( 'div' );
		body.className = 'woodev-pickup-list__body';

		list.appendChild( header );
		list.appendChild( toggle );
		list.appendChild( body );

		var card = document.createElement( 'div' );
		card.className = 'woodev-pickup-card';

		root.appendChild( list );
		root.appendChild( card );

		this._container.appendChild( root );

		this.root = root;
		this._listEl = list;
		this._listHeaderEl = header;
		this._listBodyEl = body;
		this._cardEl = card;

		var self = this;
		toggle.addEventListener( 'click', function() {
			self.toggleList();
		} );

		renderList( this );
	};

	/**
	 * Sets (or clears) the distance anchor — the map centre by default, a
	 * searched address when a search is active (Task 15); one rule, not two
	 * modes (see the file docblock). Re-renders the list immediately so an
	 * already-open list re-sorts without waiting for the next `setVisible()`.
	 *
	 * @param {number[]|null} latLng `[lat, lng]`, or null to clear.
	 * @returns {void}
	 */
	Panels.prototype.setAnchor = function( latLng ) {
		this._anchor = latLng || null;

		if ( this.root ) {
			renderList( this );
		}
	};

	/**
	 * Sets the groups currently in the map's viewport and re-renders the
	 * list. Marks that a viewport has been reported at least once — see
	 * {@see renderListHeader} for why that gate exists.
	 *
	 * @param {Array} groups
	 * @returns {void}
	 */
	Panels.prototype.setVisible = function( groups ) {
		this._groups = groups || [];
		this._hasViewport = true;

		renderList( this );
	};

	/**
	 * Flips the list panel open/closed and emits `listToggle` with the new
	 * state plus the list's own current width, so a caller (the map
	 * provider) can size the map's margin to avoid the panel covering it.
	 *
	 * @returns {void}
	 */
	Panels.prototype.toggleList = function() {
		var open = ! this._listEl.classList.contains( 'is-open' );

		this._listEl.classList.toggle( 'is-open', open );

		this._emit( 'listToggle', { open: open, width: this._listEl.offsetWidth } );
	};

	/**
	 * Opens the card on one group, showing `pointId` when given (and found
	 * in the group), otherwise the group's first point. This is what a click
	 * on the SECOND point of a co-located list row must do — always the
	 * REQUESTED point, never always the first (spec).
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

		renderCard( this );
		this._cardEl.classList.add( 'is-open' );
	};

	/**
	 * Closes the card, covering it back with the list (the card sits ABOVE
	 * the list at a higher `z-index` rather than replacing it — spec).
	 *
	 * @returns {void}
	 */
	Panels.prototype.closeCard = function() {
		this._cardEl.classList.remove( 'is-open' );
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
