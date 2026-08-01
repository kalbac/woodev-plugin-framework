/**
 * Woodev Pickup Geo — pure geometry/colour helpers shared by the pickup-map
 * panels and provider scripts.
 *
 * Plain functions, ES5-safe, no build step — this file is enqueued directly
 * (or required by jest under CommonJS), never bundled, matching every other
 * file in this directory (see pickup-datasource.js's own docblock).
 *
 * Home of `safeColor()`/`contrastFor()` (SP-5 Task 8B, D-15) — the client
 * half of the pickup map's accent-colour pipeline — and `groupByPosition()`
 * (Task 9, D-4), which folds co-located points into one map marker.
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

	/**
	 * @typedef {Object} WoodevPickupGeo
	 * @property {function(*, string): string} safeColor
	 * @property {function(string): string}     contrastFor
	 * @property {function(Array): Array}       groupByPosition
	 */

	var WoodevPickupGeo = {
		safeColor: safeColor,
		contrastFor: contrastFor,
		groupByPosition: groupByPosition,
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
