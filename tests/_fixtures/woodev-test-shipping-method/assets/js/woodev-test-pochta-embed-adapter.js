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
				address: [ p.regionTo, p.areaTo, p.cityTo, p.location, p.addressTo ]
					.filter( Boolean )
					.join( ', ' ),
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
