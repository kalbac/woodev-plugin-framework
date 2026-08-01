/**
 * Woodev Pickup Geo — pure geometry/colour helpers shared by the pickup-map
 * panels and provider scripts.
 *
 * Plain functions, ES5-safe, no build step — this file is enqueued directly
 * (or required by jest under CommonJS), never bundled, matching every other
 * file in this directory (see pickup-datasource.js's own docblock).
 *
 * Home of `safeColor()`/`contrastFor()` (SP-5 Task 8B, D-15) — the client
 * half of the pickup map's accent-colour pipeline. Task 9 adds
 * `groupByPosition()` to this same file.
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
	 * @typedef {Object} WoodevPickupGeo
	 * @property {function(*, string): string} safeColor
	 * @property {function(string): string}     contrastFor
	 */

	var WoodevPickupGeo = {
		safeColor: safeColor,
		contrastFor: contrastFor,
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
