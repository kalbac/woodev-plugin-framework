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

/**
 * placeSlot()/placeSlots() — pickup-slot anchor placement (issue #274 item 3).
 *
 * A separate describe block, its own markup/config helpers: the cascade-gate tests above
 * build no `is_pickup_slot` field at all, and reusing their `installMarkup()`/`buildConfig()`
 * would entangle two unrelated fixtures. The field id (`carrier_pvz`) is used nowhere else
 * in this file, so a stale delegated listener from an earlier test (this file's own
 * `beforeEach()` clears `document.body.innerHTML` but never replaces the body node itself —
 * see gotcha `jest-resetmodules-leaves-listeners-on-the-surviving-body`) has nothing of
 * this field's to act on.
 */
describe( 'pickup slot placements (#274 item 3)', () => {
	const PICKUP_FIELD = 'carrier_pvz';

	/**
	 * Classic-checkout shipping-methods markup, measured on the rig (issue #274's own
	 * comment on the card): `#shipping_method` is a `<ul>` INSIDE `tr.shipping td`, each
	 * rate a `<li>` holding a radio + label. One rate, pre-checked, matching the
	 * `chosen_shipping_method` value the `required` condition-spec below tests against.
	 *
	 * @returns {void}
	 */
	function installPickupMarkup() {
		document.body.innerHTML = `
			<table class="shop_table woocommerce-checkout-review-order-table">
				<tr class="woocommerce-shipping-totals shipping">
					<td>
						<ul id="shipping_method" class="woocommerce-shipping-methods">
							<li>
								<input type="radio" name="shipping_method[0]" value="carrier_pickup"
									id="shipping_method_0_carrier_pickup" checked="checked" />
								<label for="shipping_method_0_carrier_pickup">ПВЗ СДЭК</label>
							</li>
						</ul>
					</td>
				</tr>
			</table>
			<button type="submit" id="place_order"></button>
		`;
	}

	/**
	 * @param {string[]|undefined} placements `pickup_slot_placements` to put on the field —
	 *                                         `undefined` OMITS the key entirely (the
	 *                                         mixed-fleet fallback case).
	 * @returns {Object}
	 */
	function buildPickupConfig( placements ) {
		const field = {
			id:              PICKUP_FIELD,
			is_pickup_slot:  true,
			required:        { state: 'chosen_shipping_method', operator: 'in', value: [ 'carrier_pickup' ] },
		};

		if ( undefined !== placements ) {
			field.pickup_slot_placements = placements;
		}

		return {
			endpoint: ENDPOINT,
			nonce:    'test-nonce',
			i18n:     { placeholder: 'Выберите…' },
			fields:   { [ PICKUP_FIELD ]: field },
			takeover: {},
		};
	}

	/**
	 * @param {string[]|undefined} placements See {@see buildPickupConfig}.
	 * @returns {void}
	 */
	function bootPickup( placements ) {
		installPickupMarkup();

		global.jQuery = require( 'jquery' );
		global.$      = global.jQuery;
		window.jQuery = global.jQuery;

		window.WoodevCheckoutFieldStore = require(
			'../../woodev/shipping-method/assets/js/frontend/checkout-field-store.js'
		);
		window[ CONFIG_GLOBAL ] = buildPickupConfig( placements );

		ajaxCalls = stubAjax();

		require( '../../woodev/shipping-method/assets/js/frontend/checkout-field-classic.js' );

		jest.runAllTimers();
	}

	/**
	 * Every `[data-woodev-pickup-slot]` anchor currently in the document for
	 * {@see PICKUP_FIELD}.
	 *
	 * @returns {HTMLElement[]}
	 */
	function slots() {
		return Array.prototype.slice.call(
			document.querySelectorAll( '[data-woodev-pickup-slot="' + PICKUP_FIELD + '"]' )
		);
	}

	it( 'mounts BOTH placements by default — after the list AND inside the selected rate\'s <li>', () => {
		bootPickup( [ 'review', 'rate' ] );

		const placements = slots().map( ( el ) => el.getAttribute( 'data-woodev-pickup-placement' ) ).sort();

		expect( placements ).toEqual( [ 'rate', 'review' ] );

		const rateSlot = document.querySelector( '[data-woodev-pickup-placement="rate"]' );
		const reviewSlot = document.querySelector( '[data-woodev-pickup-placement="review"]' );

		// 'rate': INSIDE the <li> of the checked rate, under its label — the anchor this
		// framework was missing before #274 (mirrors woocommerce_after_shipping_rate).
		expect( rateSlot.closest( 'li' ) ).not.toBeNull();
		expect( rateSlot.closest( 'ul#shipping_method' ) ).not.toBeNull();

		// 'review': a SIBLING right after the <ul>, never inside a rate's <li> — the
		// framework's ORIGINAL, pre-#274 placement (mirrors
		// woocommerce_review_order_after_shipping — already measured to sit exactly there).
		expect( reviewSlot.closest( 'ul#shipping_method' ) ).toBeNull();
		expect( reviewSlot.previousElementSibling.id ).toBe( 'shipping_method' );
	} );

	it( 'mounts only the placements PHP actually sent — a suppressed placement never appears', () => {
		bootPickup( [ 'rate' ] );

		const placements = slots().map( ( el ) => el.getAttribute( 'data-woodev-pickup-placement' ) );

		expect( placements ).toEqual( [ 'rate' ] );
	} );

	it( 'degrades to the single "review" placement when pickup_slot_placements is absent '
		+ '(a mixed-fleet field from an older framework version)', () => {
		bootPickup( undefined );

		const placements = slots().map( ( el ) => el.getAttribute( 'data-woodev-pickup-placement' ) );

		expect( placements ).toEqual( [ 'review' ] );
	} );

	/**
	 * Issue #308 item 2 (adversarial review of #274 item 3): a plugin whose own
	 * `woodev_pickup_slot_placements` filter deliberately returns `[]` — it renders its OWN
	 * trigger and wants neither of the framework's anchors — must end up with NO mounted
	 * slot at all, not the same `[ 'review' ]` fallback an ABSENT/malformed list gets (the
	 * test right above this one). `Checkout_Config::resolve_pickup_slot_placements()` now
	 * emits a real `[]` for this case (never `null`, which is reserved for a malformed
	 * filter return) — `buildPickupConfig( [] )` here reproduces exactly that wire shape.
	 */
	it( 'mounts NO slot at all when pickup_slot_placements is explicitly empty — a plugin '
		+ 'owning its own trigger, not the mixed-fleet default', () => {
		bootPickup( [] );

		expect( slots() ).toEqual( [] );
	} );

	it( 'shows both slots when the pickup method is chosen and hides both when it is not', () => {
		bootPickup( [ 'review', 'rate' ] );

		slots().forEach( ( slot ) => expect( slot.style.display ).not.toBe( 'none' ) );

		// A different method now chosen — neither placement's `required` condition holds.
		document.getElementById( 'shipping_method_0_carrier_pickup' ).checked = false;
		const other = document.createElement( 'input' );

		other.type = 'radio';
		other.name = 'shipping_method[0]';
		other.value = 'flat_rate';
		other.checked = true;
		document.querySelector( '#shipping_method li' ).appendChild( other );

		global.jQuery( other ).trigger( 'change' );

		slots().forEach( ( slot ) => expect( slot.style.display ).toBe( 'none' ) );
	} );
} );
