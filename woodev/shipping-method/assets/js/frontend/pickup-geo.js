/**
 * Woodev Pickup Geo — pure geometry/colour helpers shared by the pickup-map
 * panels and provider scripts.
 *
 * Plain functions, ES5-safe, no build step — this file is enqueued directly
 * (or required by jest under CommonJS), never bundled, matching every other
 * file in this directory (see pickup-datasource.js's own docblock).
 *
 * Home of `safeColor()`/`contrastFor()` (SP-5 Task 8B, D-15) — the client
 * half of the pickup map's accent-colour pipeline — `groupByPosition()`
 * (Task 9, D-4), which folds co-located points into one map marker —
 * `distanceMeters()` / `formatDistance()` / `nearest()` / `boundsFor()`
 * (Task 10, D-6, D-12), the sidebar's distance math and locale-aware units —
 * and `matchPoints()` (Task 11, D-6), free-text search over the loaded pool.
 *
 * UMD-ish dual export (matches woodev-modal.js / pickup-datasource.js):
 *   - Browser global: window.WoodevPickupGeo = WoodevPickupGeo
 *   - CommonJS:       module.exports = WoodevPickupGeo  (for jest)
 *
 * @file
 * @since 2.0.2
 */

( function() {
	'use strict';

	/**
	 * The only shapes allowed to reach CSS — 3, 6 or 8 hex digits, with or
	 * without an alpha channel. Server-sanitised too (`sanitize_hex_color()`
	 * in class-pickup-handler.php, spec D-15) — validated on BOTH ends
	 * deliberately: the server check can be bypassed by a filter returning
	 * garbage, and this client check is not authoritative on its own.
	 *
	 * @type {RegExp}
	 */
	var HEX_COLOR = /^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i;

	/**
	 * Returns `colour` when it is a hex colour safe to write into CSS via
	 * `style.setProperty()`, `fallback` otherwise. Never writes anything
	 * itself — the caller is responsible for the actual CSSOM write, per D-15
	 * ("CSS custom properties set through the CSSOM, never a generated
	 * `<style>` block and never a string-built `style=` attribute").
	 *
	 * @param {*}      colour
	 * @param {string} fallback
	 * @returns {string}
	 */
	function safeColor( colour, fallback ) {
		return 'string' === typeof colour && HEX_COLOR.test( colour ) ? colour : fallback;
	}

	/**
	 * Expands a validated 3/6/8-digit hex colour to its `[ r, g, b ]` byte
	 * triplet (0-255 each). An 8-digit colour's trailing alpha byte is
	 * dropped — text contrast is decided against the colour itself, not its
	 * transparency.
	 *
	 * @param {string} hex a colour {@see HEX_COLOR} already matched.
	 * @returns {number[]}
	 */
	function toRgbBytes( hex ) {
		var h = hex.replace( '#', '' );

		if ( 3 === h.length ) {
			h = h.charAt( 0 ) + h.charAt( 0 ) + h.charAt( 1 ) + h.charAt( 1 ) + h.charAt( 2 ) + h.charAt( 2 );
		}

		return [
			parseInt( h.substring( 0, 2 ), 16 ),
			parseInt( h.substring( 2, 4 ), 16 ),
			parseInt( h.substring( 4, 6 ), 16 ),
		];
	}

	/**
	 * Gamma-linearizes one sRGB channel (0-255) per the WCAG relative
	 * luminance formula.
	 *
	 * @param {number} channel 0-255.
	 * @returns {number}
	 */
	function linearize( channel ) {
		var s = channel / 255;

		return s <= 0.03928 ? s / 12.92 : Math.pow( ( s + 0.055 ) / 1.055, 2.4 );
	}

	/**
	 * Picks black or white for text drawn on `hex`, by WCAG relative
	 * luminance — so a merchant who chooses yellow is not asked to also
	 * choose a text colour and get it wrong (spec D-15).
	 *
	 * Each channel is gamma-linearized per the WCAG formula, then combined
	 * with WCAG's OWN Rec.709 luma weights (0.2126 / 0.7152 / 0.0722) — the
	 * standard, auditable relative-luminance formula, not a bespoke variant.
	 * The 0.179 threshold is not arbitrary: it is the luminance at which
	 * black and white text give an EQUAL contrast ratio against the
	 * background (`sqrt(1.05 * 0.05) - 0.05`); above it, black measurably
	 * wins, below it, white does. This is why CDEK's own green (`#0a8c37`,
	 * L ~= 0.1909, just above the threshold) gets BLACK text here — the
	 * contrast ratio is 4.82:1 with black against 4.36:1 with white, so
	 * black is the objectively more readable choice even though CDEK's own
	 * site uses white. Do not "fix" this back to white on that basis; see
	 * the boundary-pinning tests in pickup-geo.test.js.
	 *
	 * @param {string} hex a validated 3/6/8-digit hex colour.
	 * @returns {string} '#000000' or '#ffffff'.
	 */
	function contrastFor( hex ) {
		var rgb = toRgbBytes( hex );
		var r = linearize( rgb[ 0 ] );
		var g = linearize( rgb[ 1 ] );
		var b = linearize( rgb[ 2 ] );
		var luminance = 0.2126 * r + 0.7152 * g + 0.0722 * b;

		return luminance > 0.179 ? '#000000' : '#ffffff';
	}

	/**
	 * Number of decimal digits kept in a `groupByPosition()` key — 4 decimal
	 * degrees is roughly 11 metres at the equator, well inside ymaps' own
	 * pixel-clustering radius, so two points closer than that can never be
	 * separated by zooming and are grouped up front instead (spec D-4).
	 *
	 * @type {number}
	 */
	var POSITION_PRECISION = 4;

	/**
	 * True for a finite JS `number` — i.e. safe to feed to `toFixed()`.
	 *
	 * Deliberately narrower than a "looks numeric" check: `null`/`undefined`
	 * coerce to `0`/`NaN`, `NaN` and `±Infinity` are numbers but not usable
	 * coordinates, and a numeric STRING (`'55.7558'`) is rejected too — the
	 * dataSource contract is that `lat`/`lng` are already JS numbers
	 * ({@see pickup-datasource.js}), so a string in that slot means the
	 * upstream shape broke and the point should be skipped, not silently
	 * coerced into a location that may or may not be right.
	 *
	 * @param {*} value
	 * @returns {boolean}
	 */
	function isFiniteNumber( value ) {
		return 'number' === typeof value && isFinite( value );
	}

	/**
	 * Groups points that share a map position, so co-located points (e.g. a
	 * PVZ and a postamat in the same building) render as ONE marker with a
	 * count badge and a tab bar on the point card, instead of a placemark
	 * permanently clustered by ymaps that nobody can ever open (spec D-4).
	 *
	 * The grouping key rounds `lat`/`lng` to {@see POSITION_PRECISION}
	 * decimals — rounding, not truncation (`toFixed()`'s own behaviour),
	 * so two coordinates a hair below a rounding boundary correctly land in
	 * the same bucket while two a hair above the SAME nominal value land in
	 * different ones. This also folds carrier float noise
	 * (`55.7558` vs `55.75580001` for the same building) into one group.
	 *
	 * A point with a missing/non-numeric/NaN/Infinite `lat` or `lng` is
	 * skipped entirely rather than grouped — `Number( null )` is `0`, so
	 * without this guard every broken point would silently cluster at
	 * `0,0`, off the coast of Africa (see {@see isFiniteNumber}).
	 *
	 * Groups are returned in first-seen order (stable sidebar ordering) and
	 * take their `lat`/`lng`/`typeCode` (icon) from the FIRST point of the
	 * group — later points in the same group only add to `points`/`size`.
	 *
	 * @param {Array} points normalized points from the dataSource.
	 * @returns {Array} `{ key, lat, lng, typeCode, size, points }`, one per
	 *                  distinct rounded position, in first-seen order.
	 */
	function groupByPosition( points ) {
		var byKey = {};
		var order = [];

		( points || [] ).forEach( function( point ) {
			if ( ! point || ! isFiniteNumber( point.lat ) || ! isFiniteNumber( point.lng ) ) {
				return;
			}

			var key = point.lat.toFixed( POSITION_PRECISION ) + ',' + point.lng.toFixed( POSITION_PRECISION );

			if ( ! Object.prototype.hasOwnProperty.call( byKey, key ) ) {
				byKey[ key ] = {
					key: key,
					lat: point.lat,
					lng: point.lng,
					typeCode: ( point.type && point.type.code ) || '',
					size: 0,
					points: [],
				};
				order.push( key );
			}

			byKey[ key ].points.push( point );
			byKey[ key ].size = byKey[ key ].points.length;
		} );

		return order.map( function( key ) {
			return byKey[ key ];
		} );
	}

	/** @type {number} Mean earth radius in metres (IUGG value), the haversine input. */
	var EARTH_RADIUS_METERS = 6371008.8;

	/** @type {number} Metres per kilometre — the metric small→large unit threshold. */
	var METERS_PER_KM = 1000;

	/**
	 * Metres per mile. `formatDistance()` uses this SAME constant both to
	 * decide the displayed value and, incidentally, to define what "one
	 * mile" means here — a value pinned by test rather than left implicit,
	 * since a drift to another common approximation (e.g. 1609.344) would
	 * silently shift every displayed imperial distance.
	 *
	 * @type {number}
	 */
	var METERS_PER_MILE = 1609.34;

	/**
	 * Converts degrees to radians.
	 *
	 * @param {number} degrees
	 * @returns {number}
	 */
	function toRadians( degrees ) {
		return degrees * ( Math.PI / 180 );
	}

	/**
	 * Great-circle distance between two `[lat, lng]` pairs (decimal degrees),
	 * in metres, via the haversine formula. Symmetric in its two arguments;
	 * zero for two identical points.
	 *
	 * @param {number[]} a `[lat, lng]`.
	 * @param {number[]} b `[lat, lng]`.
	 * @returns {number} distance in metres.
	 */
	function distanceMeters( a, b ) {
		var lat1 = toRadians( a[ 0 ] );
		var lat2 = toRadians( b[ 0 ] );
		var dLat = toRadians( b[ 0 ] - a[ 0 ] );
		var dLng = toRadians( b[ 1 ] - a[ 1 ] );
		var sinDLat = Math.sin( dLat / 2 );
		var sinDLng = Math.sin( dLng / 2 );
		var h = sinDLat * sinDLat + Math.cos( lat1 ) * Math.cos( lat2 ) * sinDLng * sinDLng;
		var c = 2 * Math.atan2( Math.sqrt( h ), Math.sqrt( 1 - h ) );

		return EARTH_RADIUS_METERS * c;
	}

	/**
	 * Returns `i18n[ key ]` when it is a non-empty string, `fallback` otherwise (issue
	 * #646) — an absent key, a stale cached config that predates this key, or a filter
	 * returning garbage all degrade to the framework's own English default here, never to
	 * `undefined` concatenated into the string.
	 *
	 * @param {Object} i18n
	 * @param {string} key
	 * @param {string} fallback
	 * @returns {string}
	 */
	function unitLabel( i18n, key, fallback ) {
		return 'string' === typeof i18n[ key ] && i18n[ key ].length > 0 ? i18n[ key ] : fallback;
	}

	/**
	 * Formats a metre distance for display, in the unit system PHP resolved for this store
	 * (issue #646) — `Pickup_Handler::get_js_config()` computes `distanceUnitSystem` from
	 * the SAME resolved map `lang` region ymaps itself renders with (D-12: `RU`/`UA`/`TR`
	 * region -> metric, `US` -> imperial), so the sidebar and the map can never disagree.
	 * The unit WORDS (`м`/`km`/…) come from `config.i18n`, the same translation-catalogue
	 * map every other customer-facing string on this modal goes through — this file no
	 * longer carries its own `'ru' === lang` branch (that used to pick the WORD from the
	 * locale's LANGUAGE half while the unit SYSTEM was decided from its REGION half — two
	 * different axes of the same string, now both resolved server-side instead).
	 *
	 * Below one large unit (1 km / 1 mi) a metric distance is shown as whole metres;
	 * at/above it, one decimal of kilometres. An imperial distance is always shown as one
	 * decimal of miles — there is no second, smaller imperial unit in this UI (no feet), so
	 * there is no threshold to switch at; `1609.34` metres (one `METERS_PER_MILE`) reads as
	 * `'1.0 mi'`, not a rounded whole mile.
	 *
	 * Degrades in two independent places — this file is raw JS served to the browser,
	 * never versioned with the PHP that built the page, so a stale cached config is a real
	 * state, not a hypothetical:
	 *  - a missing `config.i18n` label falls back to the English default for that label
	 *    ({@see unitLabel}).
	 *  - a missing `config.distanceUnitSystem` (a config cached before this flag existed)
	 *    falls back to exactly the PREVIOUS `'US' === region` check against the legacy
	 *    `config.lang` locale string, so a stale config cannot change which unit system a
	 *    shopper sees.
	 *
	 * @param {number} meters
	 * @param {Object} [config] the mounted pickup config, or any subset of it — every
	 *                          field read from it is optional.
	 * @param {Object} [config.i18n] `{ distanceMeters, distanceKilometers, distanceMiles }`.
	 * @param {string} [config.distanceUnitSystem] `'metric'` or `'imperial'`.
	 * @param {string} [config.lang] legacy fallback locale, `{language}_{REGION}`.
	 * @returns {string}
	 */
	function formatDistance( meters, config ) {
		config = config || {};

		var i18n = config.i18n || {};
		var unitSystem = config.distanceUnitSystem;

		if ( 'metric' !== unitSystem && 'imperial' !== unitSystem ) {
			var parts = String( config.lang || '' ).split( '_' );
			var region = ( parts[ 1 ] || '' ).toUpperCase();

			unitSystem = 'US' === region ? 'imperial' : 'metric';
		}

		if ( 'imperial' === unitSystem ) {
			return ( meters / METERS_PER_MILE ).toFixed( 1 ) + ' ' + unitLabel( i18n, 'distanceMiles', 'mi' );
		}

		var smallWord = unitLabel( i18n, 'distanceMeters', 'm' );
		var largeWord = unitLabel( i18n, 'distanceKilometers', 'km' );

		if ( meters < METERS_PER_KM ) {
			return Math.round( meters ) + ' ' + smallWord;
		}

		return ( meters / METERS_PER_KM ).toFixed( 1 ) + ' ' + largeWord;
	}

	/**
	 * Returns the `n` groups closest to `anchor`, closest first. Does not
	 * mutate `groups` — a sidebar list re-sorted in place out from under its
	 * own caller would silently scramble unrelated UI state (spec: nearest
	 * must tolerate empty input and never mutate its arguments).
	 *
	 * @param {Array}    groups objects with numeric `lat`/`lng`.
	 * @param {number[]} anchor `[lat, lng]`.
	 * @param {number}   n
	 * @returns {Array} at most `n` of `groups`' own elements (same references), nearest first.
	 */
	function nearest( groups, anchor, n ) {
		return ( groups || [] )
			.map( function( group ) {
				return { group: group, distance: distanceMeters( anchor, [ group.lat, group.lng ] ) };
			} )
			.sort( function( x, y ) {
				return x.distance - y.distance;
			} )
			.slice( 0, n )
			.map( function( ranked ) {
				return ranked.group;
			} );
	}

	/**
	 * Reduces an anchor point plus a list of groups to the smallest
	 * `[[minLat, minLng], [maxLat, maxLng]]` box containing all of them —
	 * "the address plus the N nearest points", framed for the map. The
	 * anchor always contributes to the box, even when every group already
	 * lies within it; with no groups the box degenerates to the anchor
	 * point repeated twice. Never mutates `groups`.
	 *
	 * @param {number[]} anchor `[lat, lng]`.
	 * @param {Array}    groups objects with numeric `lat`/`lng`.
	 * @returns {number[][]} `[[minLat, minLng], [maxLat, maxLng]]`.
	 */
	function boundsFor( anchor, groups ) {
		var minLat = anchor[ 0 ];
		var maxLat = anchor[ 0 ];
		var minLng = anchor[ 1 ];
		var maxLng = anchor[ 1 ];

		( groups || [] ).forEach( function( group ) {
			minLat = Math.min( minLat, group.lat );
			maxLat = Math.max( maxLat, group.lat );
			minLng = Math.min( minLng, group.lng );
			maxLng = Math.max( maxLng, group.lng );
		} );

		return [ [ minLat, minLng ], [ maxLat, maxLng ] ];
	}

	/** @type {number} minimum query length (after trimming) that triggers a search. */
	var MIN_QUERY_LENGTH = 3;

	/**
	 * A single detached element reused by `decodeEntities()`. Created once
	 * (not per field, not per call) — only its `innerHTML` is written and
	 * `textContent` read, it is never attached to the document.
	 *
	 * `null` outside a DOM environment (this file is required in that
	 * position too, e.g. a future non-browser tool); `decodeEntities()`
	 * degrades to returning the input unchanged rather than throwing.
	 *
	 * @type {HTMLElement|null}
	 */
	var decodeEl = 'undefined' !== typeof document ? document.createElement( 'div' ) : null;

	/**
	 * Decodes HTML entities in a string (`&quot;` -> `"`, etc.) via a
	 * detached element's `innerHTML`/`textContent` round-trip — the same
	 * technique the browser itself uses to interpret markup, so it is
	 * correct for every entity the server can emit, not just a hand-picked
	 * subset. A non-string or empty input decodes to `''`.
	 *
	 * @param {*} value
	 * @returns {string}
	 */
	function decodeEntities( value ) {
		if ( 'string' !== typeof value || 0 === value.length ) {
			return '';
		}

		if ( ! decodeEl ) {
			return value;
		}

		decodeEl.innerHTML = value;

		return decodeEl.textContent || '';
	}

	/**
	 * Per-point cache of decoded, lower-cased searchable fields, keyed by
	 * point object identity. `matchPoints()` is called once per keystroke
	 * over a pool that does not change between keystrokes, so decoding
	 * every field of every point on every call would be a repeated
	 * O(pool size) DOM round-trip for no new information — this cache
	 * makes each point's fields decode exactly once for as long as that
	 * point object is reused across calls (a fresh pool, e.g. after a
	 * dataSource refetch, simply misses the cache and decodes again).
	 *
	 * `undefined` in an environment without `WeakMap` — `getDecodedFields()`
	 * falls back to decoding on every call rather than throwing.
	 *
	 * @type {WeakMap|undefined}
	 */
	var decodedFieldsCache = 'undefined' !== typeof WeakMap ? new WeakMap() : undefined;

	/**
	 * Returns `point`'s decoded, lower-cased searchable fields, computing
	 * and caching them on first use (see {@see decodedFieldsCache}).
	 *
	 * @param {Object} point
	 * @returns {{name: string, address: string, shortAddress: string, instruction: string, postalCode: string}}
	 */
	function getDecodedFields( point ) {
		if ( decodedFieldsCache && decodedFieldsCache.has( point ) ) {
			return decodedFieldsCache.get( point );
		}

		var fields = {
			name: decodeEntities( point.name ).toLowerCase(),
			address: decodeEntities( point.address ).toLowerCase(),
			shortAddress: decodeEntities( point.short_address ).toLowerCase(),
			instruction: decodeEntities( point.instruction ).toLowerCase(),
			postalCode: decodeEntities( point.postal_code ).toLowerCase(),
		};

		if ( decodedFieldsCache ) {
			decodedFieldsCache.set( point, fields );
		}

		return fields;
	}

	/**
	 * Filters `points` down to those matching free-text `query`, for the
	 * search box's "matches from the already-loaded pool" section (spec
	 * D-6). A query shorter than {@see MIN_QUERY_LENGTH} characters AFTER
	 * trimming returns no results — 3 raw characters padded with spaces
	 * must NOT match, and exactly 3 non-space characters must.
	 *
	 * Point fields arrive already `esc_html()`-escaped by the server (they
	 * are written into `innerHTML` as-is by the panels), so a point named
	 * `ПВЗ "Ромашка"` is carried as `ПВЗ &quot;Ромашка&quot;`. Matching the
	 * raw escaped string would silently break both a search for the literal
	 * quote character and one for a word next to the entity, so every
	 * candidate field is decoded (see {@see decodeEntities}) before
	 * comparison, never the other way around.
	 *
	 * `name`, `address`, `short_address` and `instruction` are matched as
	 * case-insensitive substrings; `postal_code` is matched exactly (a
	 * partial postal code is not a useful match on its own).
	 *
	 * @param {Array}  points normalized points, fields already `esc_html()`-escaped.
	 * @param {string} query free-text query, not yet escaped.
	 * @returns {Array} the matching points, in their original order.
	 */
	function matchPoints( points, query ) {
		var trimmed = String( query || '' ).trim();

		if ( trimmed.length < MIN_QUERY_LENGTH ) {
			return [];
		}

		var needle = trimmed.toLowerCase();

		return ( points || [] ).filter( function( point ) {
			var fields = getDecodedFields( point );

			return needle === fields.postalCode ||
				fields.name.indexOf( needle ) !== -1 ||
				fields.address.indexOf( needle ) !== -1 ||
				fields.shortAddress.indexOf( needle ) !== -1 ||
				fields.instruction.indexOf( needle ) !== -1;
		} );
	}

	/**
	 * @typedef {Object} WoodevPickupGeo
	 * @property {function(*, string): string}          safeColor
	 * @property {function(string): string}              contrastFor
	 * @property {function(Array): Array}                groupByPosition
	 * @property {function(number[], number[]): number}  distanceMeters
	 * @property {function(number, Object=): string}     formatDistance
	 * @property {function(Array, number[], number): Array} nearest
	 * @property {function(number[], Array): number[][]} boundsFor
	 * @property {function(Array, string): Array}        matchPoints
	 */

	var WoodevPickupGeo = {
		safeColor: safeColor,
		contrastFor: contrastFor,
		groupByPosition: groupByPosition,
		distanceMeters: distanceMeters,
		formatDistance: formatDistance,
		nearest: nearest,
		boundsFor: boundsFor,
		matchPoints: matchPoints,
	};

	// -------------------------------------------------------------------------
	// UMD-ish dual export
	// -------------------------------------------------------------------------

	// Browser global
	if ( typeof window !== 'undefined' ) {
		window.WoodevPickupGeo = WoodevPickupGeo;
	}

	// CommonJS (jest)
	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = WoodevPickupGeo;
	}

}() );
