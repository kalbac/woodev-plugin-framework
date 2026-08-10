/**
 * Woodev Test Fixture — Почта России embedded-widget adapter (issue #251).
 *
 * FIXTURE-OWNED DOMAIN KNOWLEDGE, deliberately kept OUT of the framework — see
 * `Embedded_Map_Provider`'s class docblock, option 2. This file supplies the
 * two dotted-global-path adapter hooks
 * (`Embedded_Map_Provider::__construct()`'s `$init_adapter`/`$select_adapter`)
 * that translate the live `https://widget.pochta.ru/map/` widget's OWN
 * `postMessage` protocol into `map-provider-embedded.js`'s normalized point
 * shape, without a plugin-hosted bridge page.
 *
 * Every field name/shape below (`isMapLoad`, `postData`, `pvzData`, `pvzType`,
 * `indexTo`, `regionTo`/`areaTo`/`cityTo`/`location`/`addressTo`) was MEASURED
 * on the rig against the live widget, not read from vendor docs — see
 * `docs-internal/specs/2026-08-10-embedded-map-provider-adapter-seam.md` §1,
 * measurements M3/M7/M8. The address composition — exactly `regionTo, areaTo,
 * cityTo, location, addressTo`, filtered and joined with `', '` — is copied
 * verbatim from the operator's own production plugin
 * (`plugins-reference/woodev-russian-post/includes/classes/ajax.php`,
 * `set_point()`); do not reorder it.
 *
 * F5 (rig finding, cosmetic, s63): a CONSECUTIVE-duplicate part is dropped
 * before joining, added ON TOP of that field ORDER (not instead of it — the
 * order above is untouched). Measured on the rig for Moscow: the carrier
 * sends `regionTo: "г. Москва"` AND `cityTo: "г. Москва"` for the same
 * point, so the naive `filter( Boolean ).join( ', ' )` produced
 * `"г. Москва, г. Москва, ул. Никольская 7-9 стр. 4"`. {@see dedupeConsecutive}
 * collapses only ADJACENT repeats (never removes a part that recurs
 * non-consecutively, e.g. a region name that also happens to be a street
 * name elsewhere), which is enough to fix this without reordering or
 * dropping anything the reference plugin's own field list keeps. FIXTURE-
 * ONLY: this stays out of `map-provider-embedded.js` and out of
 * `Embedded_Map_Provider` — address composition is domain knowledge, exactly
 * like the rest of this file (see the paragraph above).
 *
 * `window.WoodevTestPochtaEmbedConfig` is localized by
 * `Woodev_Test_Shipping_Method_Plugin::maybe_enqueue_pochta_embed_adapter()`
 * in `woodev-test-shipping-method.php` — `accountId`/`accountType` come from
 * the (never-committed) `WOODEV_TEST_POCHTA_ACCOUNT_ID`/
 * `WOODEV_TEST_POCHTA_ACCOUNT_TYPE` wp-config constants; `weight`/`sumoc`/
 * `startZip`/`startAddress` are fixture placeholders, not real cart data.
 *
 * Plain ES5, no jQuery, no build step — matches every sibling script under
 * `woodev/shipping-method/assets/js/frontend/`.
 *
 * @file
 */

( function() {
	'use strict';

	/** @type {Object} localized by wp_localize_script(), see the file docblock. */
	var CONFIG = window.WoodevTestPochtaEmbedConfig || {};

	/**
	 * Human label for a `pvzType` value — domain knowledge, not the framework's.
	 * The widget itself never sends a label (M7/M8); this is invented here, the
	 * one place that is allowed to.
	 *
	 * @param {string} pvzType
	 * @returns {string}
	 */
	function typeLabel( pvzType ) {
		return 'postamat' === pvzType ? 'Почтомат' : 'Почтовое отделение';
	}

	/**
	 * Drops a part that is IDENTICAL to the one immediately before it — F5 (rig
	 * finding, s63), see the file docblock's own paragraph for the measured
	 * Moscow case this fixes (`regionTo`/`cityTo` both `"г. Москва"`). Only
	 * ADJACENT duplicates are collapsed: the field ORDER from the file docblock
	 * (`regionTo, areaTo, cityTo, location, addressTo`) is preserved exactly,
	 * and a value that repeats NON-consecutively (impossible with today's
	 * measured field set, but not assumed impossible here) would survive
	 * untouched — this is deliberately narrower than a generic "unique
	 * values" filter, which could silently reorder-by-omission or drop a
	 * legitimate repeat far apart in the address.
	 *
	 * @param {string[]} parts Already `filter( Boolean )`ed address parts.
	 * @returns {string[]}
	 */
	function dedupeConsecutive( parts ) {
		var result = [];
		var i;

		for ( i = 0; i < parts.length; i++ ) {
			if ( 0 === result.length || result[ result.length - 1 ] !== parts[ i ] ) {
				result.push( parts[ i ] );
			}
		}

		return result;
	}

	window.WoodevPochtaEmbed = {

		/**
		 * `Embedded_Map_Provider`'s `initAdapter` hook. Answers the widget's own
		 * handshake — `{ isMapLoad: true }`, measured M3 — with the `postData` it
		 * requires before it renders anything at all (M4: it stays blank without
		 * this). Any other message this instance is asked about (not the
		 * handshake) is declined by returning `null`, so `selectAdapter` gets a
		 * chance at it next.
		 *
		 * @param {*} data
		 * @returns {Object|null}
		 */
		onReady: function( data ) {
			if ( ! data || true !== data.isMapLoad ) {
				return null;
			}

			return {
				postData: {
					accountId: CONFIG.accountId || '',
					accountType: CONFIG.accountType || '',
					weight: CONFIG.weight,
					sumoc: CONFIG.sumoc,
					startZip: CONFIG.startZip || '',
					startAddress: CONFIG.startAddress || '',
					url: window.location.href
				}
			};
		},

		/**
		 * `Embedded_Map_Provider`'s `selectAdapter` hook. Translates the widget's
		 * own selection message — `{ pvzData: {...} }`, measured M7 — into this
		 * provider's raw point payload. Deliberately emits NO `lat`/`lng`: the
		 * widget sends neither (M8), and since issue #251 `normalizePoint()` in
		 * `map-provider-embedded.js` no longer requires them. `name` IS still
		 * required there, and the widget sends none either, so it is built here
		 * from `pvzType`/`indexTo` — the one place domain knowledge like this is
		 * allowed to live.
		 *
		 * @param {*} data
		 * @returns {Object|null}
		 */
		toPoint: function( data ) {
			if ( ! data || ! data.pvzData ) {
				return null;
			}

			var p = data.pvzData;

			return {
				id: String( p.id ),
				name: ( 'postamat' === p.pvzType ? 'Почтомат №' : 'Отделение №' ) + p.indexTo,
				address: dedupeConsecutive(
					[ p.regionTo, p.areaTo, p.cityTo, p.location, p.addressTo ].filter( Boolean )
				).join( ', ' ),
				type: {
					code: p.pvzType,
					label: typeLabel( p.pvzType )
				},
				postal_code: String( p.indexTo )
				// No lat/lng — the widget sends neither (M8).
			};
		}
	};

}() );
