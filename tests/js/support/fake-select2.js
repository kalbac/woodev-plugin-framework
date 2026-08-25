/**
 * A minimal fake `jQuery.fn.select2` for jest (issue #450; open/close/pick sequencing added
 * issue #517 round 3).
 *
 * jsdom has no select2/selectWoo — the package is not vendored in this repo, it arrives from
 * WordPress/WooCommerce at runtime (see `location-select-modes.js`'s own "SELECT2 IS OPTIONAL
 * AT RUNTIME" docblock section). Before this fake existed, `ensureSelect2()`'s own defensive
 * `'function' !== typeof $select.select2` guard always took the "select2 absent" branch in
 * every test, so the entire `config` object it builds — `ajax.transport`, `minimumInputLength`,
 * `ajax.delay`, `placeholder` — ran zero times under jest (issue #450's own root cause).
 *
 * This fake does NOT vendor real select2. It RECORDS the config `.select2()` was called with,
 * reproduces the two ajax contract points that decide whether OUR OWN `ajax.transport` plays
 * correctly with a real select2/selectWoo instance, and (round 3) dispatches the OPEN/CLOSE/
 * SELECT jQuery events a real pick actually produces, IN THE MEASURED ORDER, never an assumed
 * one:
 *
 * - The `minimumInputLength` gate (selectWoo.full.js:3885-3902, a data-adapter DECORATOR that
 *   runs BEFORE the ajax adapter at all): a query shorter than the floor never reaches
 *   `ajax.transport`.
 * - The abort contract (selectWoo.full.js:3564-3571, `AjaxAdapter.prototype.query`): the
 *   REAL adapter stores whatever `ajax.transport` returns and, on the NEXT query, aborts it
 *   ONLY if `this._request != null && 'function' === typeof this._request.abort` — never
 *   blindly. A transport that returns nothing (our own code, today) never gets its in-flight
 *   request aborted, because select2 never even tries — this fake reproduces that exact check
 *   so a test can tell "transport returned something abortable" from "transport returned
 *   nothing" by whether the PREVIOUS request's `abort` spy was actually called.
 * - THE PICK EVENT ORDER (issue #517 round 3, critic MJ-3) — MEASURED on the live rig
 *   (`:8973/classic-checkout/`, `shipping_city` under `ajax-select2`, provider `test-cdek`), by
 *   attaching jQuery listeners to the real widget and picking a real result: `select2:opening`
 *   → `change` (jQuery) → `select2:closing` → `select2:close` → `select2:select`. Reproduced
 *   identically four times — WooCommerce's own `billing_state` selectWoo instance (mouse ×2,
 *   keyboard Enter ×1) and this repo's own ajax-backed field (mouse ×1) all agree. **`close`
 *   fires BEFORE `select`, not after** — a fake that hand-dispatched the two in the OTHER order
 *   (this file's own state before round 3) could never have caught round 2's actual bug
 *   (critic PROBE P2), because it was testing a sequence the browser does not produce.
 *   {@see instance.pick} reproduces exactly the four events after `opening` (which
 *   {@see instance.query}'s own auto-open already covers), in the measured order, with no
 *   configurable "which order" parameter — there is only one order, and it is this one.
 *
 * `ajax.delay` is NOT reproduced as a timer — selectWoo applies it itself, before the
 * transport is ever invoked (research doc §2.3); it is not the transport's concern, so a test
 * that cares about it asserts the recorded `config.ajax.delay` value directly (a shape
 * assertion, not a timing one).
 *
 * @see docs-internal/research/2026-08-21-select2-location-fields.md §2.2, §2.4
 * @see docs-internal/reviews/2026-08-25-517-critic.md — MJ-3's rig measurement (round 3)
 * @see woodev/shipping-method/assets/js/frontend/location-select-modes.js
 */

'use strict';

/**
 * Installs the fake onto `$.fn.select2` and returns the array of instances it will push to —
 * one entry per `.select2( config )` call observed, in call order.
 *
 * @param {Object} $ jQuery (real jQuery — a devDependency of this repo).
 * @returns {Array<{
 *   config: Object,
 *   query: function(string=): ({success: jest.Mock, failure: jest.Mock}|null),
 *   open: function(): void,
 *   close: function(): void,
 *   pick: function(Object): void,
 *   el: Object,
 * }>}
 */
function installFakeSelect2( $ ) {
	var instances = [];

	$.fn.select2 = jest.fn( function( config ) {
		var $el = this;
		var request = null;
		var isOpen = false;

		/**
		 * `select2:opening` + `select2:open` — the moment a search session begins. Idempotent:
		 * a search already in progress does not re-open (mirrors a real dropdown, which stays
		 * open across keystrokes within one session).
		 *
		 * @returns {void}
		 */
		function open() {
			if ( isOpen ) {
				return;
			}

			isOpen = true;
			$el.trigger( 'select2:opening' );
			$el.trigger( 'select2:open' );
		}

		/**
		 * `select2:closing` + `select2:close` alone — a customer leaving WITHOUT picking
		 * (Escape, an outside click, blur). A real pick goes through {@see pick} instead, whose
		 * own event set differs (see that method's own docblock).
		 *
		 * @returns {void}
		 */
		function close() {
			if ( ! isOpen ) {
				return;
			}

			isOpen = false;
			$el.trigger( 'select2:closing' );
			$el.trigger( 'select2:close' );
		}

		var instance = {
			el: $el,
			config: config,

			/**
			 * Fires `config.ajax.transport` for `term`, reproducing selectWoo's own
			 * minimumInputLength gate and pre-abort-then-store sequence. Opens the dropdown
			 * first (searching requires it to already be open, exactly like a real session).
			 *
			 * @param {string} [term]
			 * @returns {{success: jest.Mock, failure: jest.Mock}|null} `null` when the
			 *   minimumInputLength gate blocked the query BEFORE it reached the transport —
			 *   mirrors selectWoo's own `MinimumInputLength.prototype.query` short-circuit.
			 */
			query: function( term ) {
				if ( ! config.ajax || 'function' !== typeof config.ajax.transport ) {
					throw new Error( 'fake select2: query() called on a config with no ajax.transport' );
				}

				open();

				var value = term || '';

				if ( 'number' === typeof config.minimumInputLength && value.length < config.minimumInputLength ) {
					return null;
				}

				if ( request != null && 'function' === typeof request.abort ) {
					request.abort();
				}

				var success = jest.fn();
				var failure = jest.fn();

				request = config.ajax.transport( { data: { term: value } }, success, failure );

				return { success: success, failure: failure };
			},

			/**
			 * Opens the dropdown (`select2:opening` + `select2:open`) without searching — for a
			 * test that needs `dropdownOpen` tracking active before its first `query()` (e.g.
			 * a local/non-ajax instance, which never calls `query()` at all).
			 *
			 * @returns {void}
			 */
			open: open,

			/**
			 * Closes the dropdown WITHOUT a pick (`select2:closing` + `select2:close`) — the
			 * "customer leaves without picking" shape.
			 *
			 * @returns {void}
			 */
			close: close,

			/**
			 * Simulates a REAL pick of `resultItem` (`{id, text, key}`, the same shape
			 * `selectConfigFor()`'s own transport `success()` reports) — dispatches the FOUR
			 * events a real pick fires after the session is already open, in the MEASURED
			 * order (see this file's own docblock): `change` (jQuery, value already set),
			 * `select2:closing`, `select2:close`, `select2:select`. Never `select2:select`
			 * before `close` — that was never the real order, and a fake that dispatched it
			 * that way could not have caught issue #517's own round-2 regression.
			 *
			 * @param {{id: string, text: string, key: string}} resultItem
			 * @returns {void}
			 */
			pick: function( resultItem ) {
				$el.val( resultItem.id );
				$el.trigger( 'change' );

				isOpen = false;
				$el.trigger( 'select2:closing' );
				$el.trigger( 'select2:close' );

				$el.trigger( $.Event( 'select2:select', { params: { data: resultItem } } ) );
			},
		};

		instances.push( instance );

		return $el;
	} );

	return instances;
}

module.exports = { installFakeSelect2: installFakeSelect2 };
