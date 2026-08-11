/**
 * Tests for checkout-field-classic.js — the classic-checkout adapter of the §8 field layer.
 *
 * FIRST test coverage this file has ever had. That absence is why #272 survived: the cascade
 * destroyed a server-rendered value on every page load, and nothing but a browser could say so.
 *
 * The contract under test here is the CASCADE's destructiveness gate. A cascade drops the child's
 * value by design ("the region changed, so the city is stale"), but WooCommerce fires PROGRAMMATIC
 * `change` events on address fields while initialising the checkout, carrying the value the field
 * already had. Those pass the adapter's own `meaningful` gate legitimately (non-empty value), so
 * before #272 they ran a full destructive cascade against a parent that had not changed:
 *
 *   DCL                <input value="Москва">      server rendered it
 *   cascadeChild       $child.val( '' )            synchronous wipe of DOM + store
 *   +50ms              WC's own update_order_review posts billing_city=''  → WC()->customer
 *   +2900ms            the source answers, value restored — into a DETACHED node, too late
 *
 * Hence the two assertions every test here makes: the value must survive SYNCHRONOUSLY (the POST
 * window is ~50 ms, long before any response), and the late write must land on the node that is
 * actually IN the document (the takeover converts `<input>` → `<select>` mid-flight).
 *
 * Real jQuery is loaded (it IS a devDependency — `package.json` `devDependencies.jquery`; the
 * claim to the contrary in `pickup-mount.test.js`'s docblock is stale). `$.ajax` is stubbed so
 * each test drives the response timing by hand. select2 is deliberately absent, so
 * `select2Method()` returns '' and `initSuggest()` no-ops — exactly the "no select2 on the page"
 * branch, which keeps the DOM assertions about plain `<option>` elements honest.
 *
 * @see woodev/shipping-method/assets/js/frontend/checkout-field-classic.js
 */

'use strict';

jest.useFakeTimers();

const CONFIG_GLOBAL = 'woodev_checkout_field_config_test';
const ENDPOINT      = 'https://example.test/wp-json/woodev/v1/test/field-source';

let ajaxCalls;

/**
 * Installs the WooCommerce classic-checkout markup the adapter reaches for.
 *
 * `billing_city` is rendered as a text `<input>` carrying a value, which is what the server
 * really emits for a field WooCommerce has no concept of (measured on the rig, s66).
 *
 * @param {string} city  Server-rendered city value.
 * @param {string} state Server-rendered region value.
 * @returns {void}
 */
function installMarkup( city, state ) {
	document.body.innerHTML = `
		<form class="checkout woocommerce-checkout">
			<div class="woocommerce-billing-fields__field-wrapper">
				<p id="billing_country_field" class="form-row">
					<select id="billing_country" name="billing_country">
						<option value="RU" selected>Россия</option>
						<option value="US">США</option>
					</select>
				</p>
				<p id="billing_state_field" class="form-row">
					<select id="billing_state" name="billing_state">
						<option value="77">Москва</option>
						<option value="78">Санкт-Петербург</option>
					</select>
				</p>
				<p id="billing_city_field" class="form-row">
					<input type="text" id="billing_city" name="billing_city" value="${ city }" />
				</p>
			</div>
			<div id="shipping_method"></div>
			<button type="submit" id="place_order"></button>
		</form>
	`;

	document.getElementById( 'billing_state' ).value = state;
}

/**
 * Builds the localized config global the adapter discovers by prefix.
 *
 * @returns {Object}
 */
function buildConfig() {
	return {
		endpoint: ENDPOINT,
		nonce:    'test-nonce',
		// No `required` key: #274 removed the inline «Заполните обязательное поле.» caption
		// and, with it, the only consumer of that string — a fixture carrying it would be
		// richer than production in exactly the direction that hides a regression.
		i18n:     { placeholder: 'Выберите…' },
		fields:   {
			billing_state: { source_kind: 'options', required: true },
			billing_city:  { source_kind: 'suggest', depends_on: 'billing_state', required: true },
		},
		takeover: {
			billing_state: { RU: true },
			billing_city:  { RU: true },
		},
	};
}

/**
 * Replaces `$.ajax` with a stub whose responses each test resolves by hand.
 *
 * Returns the recorded calls; every entry exposes `resolve()`/`reject()` so a test can decide
 * WHEN the source answers — the whole defect lives in the window before it does.
 *
 * @returns {Array<Object>}
 */
function stubAjax() {
	const calls = [];

	global.jQuery.ajax = jest.fn( function( opts ) {
		const handlers = { done: [], fail: [] };
		const chain    = {
			done( cb ) { handlers.done.push( cb ); return chain; },
			fail( cb ) { handlers.fail.push( cb ); return chain; },
		};

		calls.push( {
			opts,
			resolve( response ) { handlers.done.forEach( ( cb ) => cb( response ) ); },
			reject( error ) { handlers.fail.forEach( ( cb ) => cb( {}, 'error', error ) ); },
		} );

		return chain;
	} );

	return calls;
}

/**
 * Loads the adapter fresh and runs its boot (jQuery ready + the deferred takeover tick).
 *
 * @param {string} city  Server-rendered city value.
 * @param {string} state Server-rendered region value.
 * @returns {void}
 */
function boot( city = 'Москва', state = '77' ) {
	installMarkup( city, state );

	global.jQuery = require( 'jquery' );
	global.$      = global.jQuery;
	window.jQuery = global.jQuery;

	window.WoodevCheckoutFieldStore = require(
		'../../woodev/shipping-method/assets/js/frontend/checkout-field-store.js'
	);
	window[ CONFIG_GLOBAL ] = buildConfig();

	ajaxCalls = stubAjax();

	require( '../../woodev/shipping-method/assets/js/frontend/checkout-field-classic.js' );

	// Three timer generations have to drain here, which is why this is `runAllTimers`
	// rather than a counted number of `runOnlyPendingTimers()` passes: jQuery schedules
	// `jQuery.ready` on a timer when the document is already complete, jQuery 3+ then
	// fires `readyList.then( … )` — i.e. our ready callback — ASYNCHRONOUSLY on a second
	// timer, and that callback finally registers the takeover's own `setTimeout( …, 0 )`.
	// Two passes left the takeover un-run, so `#billing_city` stayed a text `<input>` and
	// every option assertion silently compared against an empty list.
	jest.runAllTimers();
}

/**
 * Returns the `#billing_city` element currently IN the document.
 *
 * Deliberately re-queried on every call: the takeover replaces the node, so a reference held
 * across a cascade is exactly the stale-reference bug this file pins.
 *
 * @returns {HTMLElement}
 */
function city() {
	return document.getElementById( 'billing_city' );
}

/**
 * Lists the option values of the live `#billing_city` element.
 *
 * @returns {Array<string>}
 */
function cityOptions() {
	const el = city();

	return el && el.options ? Array.prototype.map.call( el.options, ( o ) => o.value ) : [];
}

/**
 * Fires a jQuery `change` on the region select — programmatic, so no `originalEvent`,
 * which is precisely how WooCommerce's own churn arrives.
 *
 * @param {string} value Region value to report.
 * @returns {void}
 */
function changeState( value ) {
	global.jQuery( '#billing_state' ).val( value ).trigger( 'change' );
}

/**
 * The cascade request issued for `billing_city`, if any.
 *
 * @returns {Object|undefined}
 */
function cityRequest() {
	return ajaxCalls.filter( ( c ) => String( c.opts.url ).indexOf( 'billing_city' ) !== -1 ).pop();
}

beforeEach( () => {
	jest.resetModules();
	delete window[ CONFIG_GLOBAL ];
	delete window.WoodevCheckoutFieldStore;
	document.body.innerHTML = '';
} );

describe( 'cascade destructiveness gate (#272)', () => {

	it( 'keeps the server-rendered child value when the parent reports the SAME value', () => {
		boot( 'Москва', '77' );

		expect( city().value ).toBe( 'Москва' );

		// WooCommerce's init-time programmatic change: same region, no user action.
		changeState( '77' );

		// The POST window. Nothing may have cleared the field by now.
		expect( city().value ).toBe( 'Москва' );
		expect( cityOptions() ).toContain( 'Москва' );
	} );

	it( 'DOES clear the child when the parent value genuinely changed', () => {
		boot( 'Москва', '77' );

		changeState( '78' );

		expect( city().value ).toBe( '' );
	} );

	it( 'restores the value as a selected option when the source omits it and the parent is unchanged', () => {
		boot( 'Москва', '77' );

		changeState( '77' );

		const request = cityRequest();
		expect( request ).toBeDefined();
		expect( request.opts.data.parent ).toBe( '77' );

		// A truncated / query-scoped set that does not carry the current value.
		request.resolve( { options: [ { value: 'Зеленоград', label: 'Зеленоград' } ] } );

		expect( cityOptions() ).toContain( 'Москва' );
		expect( city().value ).toBe( 'Москва' );
	} );

	it( 'drops the value when the source omits it AFTER a genuine parent change', () => {
		boot( 'Москва', '77' );

		changeState( '78' );

		const request = cityRequest();
		request.resolve( { options: [ { value: 'Кронштадт', label: 'Кронштадт' } ] } );

		expect( cityOptions() ).not.toContain( 'Москва' );
		expect( city().value ).toBe( '' );
	} );

	it( 'writes the late response into the node that is IN the document, not the captured one', () => {
		boot( 'Москва', '77' );

		changeState( '78' );

		const request  = cityRequest();
		const captured = city();

		// Mimic the takeover swapping the element while the request is in flight
		// (ensureSelect() does exactly this, measured at ~6 ms after the cascade starts).
		const fresh = document.createElement( 'select' );
		fresh.id    = 'billing_city';
		fresh.name  = 'billing_city';
		captured.parentNode.replaceChild( fresh, captured );

		request.resolve( { options: [ { value: 'Санкт-Петербург', label: 'Санкт-Петербург' } ] } );

		expect( city() ).toBe( fresh );
		expect( cityOptions() ).toContain( 'Санкт-Петербург' );

		// The detached node must NOT be where the answer landed. It legitimately still
		// carries the options the boot takeover gave it — what must be absent is the
		// RESPONSE, which is the whole point: a write there is invisible to the customer
		// and to the form serialization alike.
		const strandedOptions = captured.options
			? Array.prototype.map.call( captured.options, ( o ) => o.value )
			: [];
		expect( strandedOptions ).not.toContain( 'Санкт-Петербург' );
	} );

	it( 'retries after a failed source request instead of considering the parent resolved', () => {
		boot( 'Москва', '77' );

		changeState( '78' );
		const first = cityRequest();
		expect( first ).toBeDefined();
		first.reject( 'boom' );

		const before = global.jQuery.ajax.mock.calls.length;

		// The very same region value again: a released `fetched` entry must let it through.
		changeState( '78' );

		expect( global.jQuery.ajax.mock.calls.length ).toBeGreaterThan( before );
		// The adapter logs the transport failure through logError(); assert it so
		// @wordpress/jest-console does not fail the suite on an unexpected console.error.
		expect( console ).toHaveErrored();
	} );

	it( 'does not re-issue a cascade for a repeated no-op change once resolved', () => {
		boot( 'Москва', '77' );

		changeState( '77' );
		const first = cityRequest();
		first.resolve( { options: [ { value: 'Москва', label: 'Москва' } ] } );

		const after = global.jQuery.ajax.mock.calls.length;

		changeState( '77' );

		expect( global.jQuery.ajax.mock.calls.length ).toBe( after );
		expect( city().value ).toBe( 'Москва' );
	} );
} );
