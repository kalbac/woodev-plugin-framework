/**
 * A minimal fake `jQuery.fn.select2` for jest (issue #450).
 *
 * jsdom has no select2/selectWoo — the package is not vendored in this repo, it arrives from
 * WordPress/WooCommerce at runtime (see `location-select-modes.js`'s own "SELECT2 IS OPTIONAL
 * AT RUNTIME" docblock section). Before this fake existed, `ensureSelect2()`'s own defensive
 * `'function' !== typeof $select.select2` guard always took the "select2 absent" branch in
 * every test, so the entire `config` object it builds — `ajax.transport`, `minimumInputLength`,
 * `ajax.delay`, `placeholder` — ran zero times under jest (issue #450's own root cause).
 *
 * This fake does NOT vendor real select2. It only RECORDS the config `.select2()` was called
 * with and reproduces the two contract points that decide whether OUR OWN `ajax.transport`
 * plays correctly with a real select2/selectWoo instance:
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
 *
 * `ajax.delay` is NOT reproduced as a timer — selectWoo applies it itself, before the
 * transport is ever invoked (research doc §2.3); it is not the transport's concern, so a test
 * that cares about it asserts the recorded `config.ajax.delay` value directly (a shape
 * assertion, not a timing one).
 *
 * @see docs-internal/research/2026-08-21-select2-location-fields.md §2.2, §2.4
 * @see woodev/shipping-method/assets/js/frontend/location-select-modes.js
 */

'use strict';

/**
 * Installs the fake onto `$.fn.select2` and returns the array of instances it will push to —
 * one entry per `.select2( config )` call observed, in call order.
 *
 * @param {Object} $ jQuery (real jQuery — a devDependency of this repo).
 * @returns {Array<{config: Object, query: function(string=): ({success: jest.Mock, failure: jest.Mock}|null)}>}
 */
function installFakeSelect2( $ ) {
	var instances = [];

	$.fn.select2 = jest.fn( function( config ) {
		var $el = this;
		var request = null;

		var instance = {
			el: $el,
			config: config,

			/**
			 * Fires `config.ajax.transport` for `term`, reproducing selectWoo's own
			 * minimumInputLength gate and pre-abort-then-store sequence.
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
		};

		instances.push( instance );

		return $el;
	} );

	return instances;
}

module.exports = { installFakeSelect2: installFakeSelect2 };
