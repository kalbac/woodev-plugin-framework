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
	 * `withRateListItem: false` drops the `<li>` wrapper and leaves the radio a direct
	 * child of the `<ul>` — a theme that overrode `templates/cart/cart-shipping.php`.
	 * Stock WooCommerce always renders the `<li>` (with a single method it only swaps the
	 * input type, `radio` → `hidden`), so this models a THEME, never WooCommerce itself.
	 * It is the case that leaves the `'rate'` anchor unresolvable while `'review'` is
	 * still perfectly available — issue #323's mandatory fallback.
	 *
	 * `preChecked: false` renders a SECOND rate and checks neither. `resolvePlacementAnchor()`
	 * only falls back to "the sole radio" when there is exactly one, so with two unchecked
	 * rates the `'rate'` anchor is unresolvable — and becomes resolvable the moment the
	 * customer picks a method. That is the sequence that turns #323's fallback into #323's own
	 * symptom if `placeSlots()` can only ever ADD slots.
	 *
	 * `packages: 2` renders the `<ul id="shipping_method">` block TWICE, each with its own
	 * checked rate — a multi-package cart. That is stock WooCommerce, not a theme:
	 * `templates/cart/cart-shipping.php` hardcodes `id="shipping_method"` and is included once
	 * per package, so a real checkout genuinely carries duplicate ids and one checked radio per
	 * package (`shipping_method[0]`, `shipping_method[1]`).
	 *
	 * @param {{withRateListItem?: boolean, preChecked?: boolean, packages?: number}} [options]
	 * @returns {void}
	 */
	function installPickupMarkup( options ) {
		const opts         = options || {};
		const withListItem = false !== opts.withRateListItem;
		const preChecked   = false !== opts.preChecked;
		const packages     = opts.packages || 1;
		const rate         = ( index, value, label, checked ) => `
			<input type="radio" name="shipping_method[${ index }]" value="${ value }"
				id="shipping_method_${ index }_${ value }" ${ checked ? 'checked="checked"' : '' } />
			<label for="shipping_method_${ index }_${ value }">${ label }</label>
		`;
		const wrap         = ( html ) => ( withListItem ? `<li>${ html }</li>` : html );
		const list         = ( index ) => `
			<tr class="woocommerce-shipping-totals shipping">
				<td>
					<ul id="shipping_method" class="woocommerce-shipping-methods">
						${ wrap( rate( index, 'carrier_pickup', 'ПВЗ СДЭК', preChecked ) ) }
						${ preChecked ? '' : wrap( rate( index, 'flat_rate', 'Курьер', false ) ) }
					</ul>
				</td>
			</tr>
		`;

		document.body.innerHTML = `
			<table class="shop_table woocommerce-checkout-review-order-table">
				${ Array.from( { length: packages }, ( unused, index ) => list( index ) ).join( '' ) }
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
	 * @param {string[]|undefined}           placements See {@see buildPickupConfig}.
	 * @param {{withRateListItem?: boolean}} [markup]   See {@see installPickupMarkup}.
	 * @returns {void}
	 */
	function bootPickup( placements, markup ) {
		installPickupMarkup( markup );

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

	it( 'mounts the framework default — ONE slot, inside the selected rate\'s <li>', () => {
		bootPickup( [ 'rate' ] );

		const mounted = slots();

		expect( mounted ).toHaveLength( 1 );
		expect( mounted[ 0 ].getAttribute( 'data-woodev-pickup-placement' ) ).toBe( 'rate' );
		expect( mounted[ 0 ].closest( 'li' ) ).not.toBeNull();
	} );

	/**
	 * Issue #323's mandatory fallback: a theme that overrode
	 * `templates/cart/cart-shipping.php` and dropped the `<li>` leaves the `'rate'` anchor
	 * unresolvable, and before this fix `placeSlot()` returned silently — carrying away the
	 * customer's ONLY way to pick a point, with no error anywhere. `'review'` is still
	 * available on such a page, so the trigger lands there instead of nowhere.
	 */
	it( 'falls back to the "review" anchor when the requested one is not in the DOM', () => {
		bootPickup( [ 'rate' ], { withRateListItem: false } );

		const mounted = slots();

		expect( mounted ).toHaveLength( 1 );
		expect( mounted[ 0 ].getAttribute( 'data-woodev-pickup-placement' ) ).toBe( 'review' );
		expect( mounted[ 0 ].previousElementSibling.id ).toBe( 'shipping_method' );
	} );

	/**
	 * The fallback must not fire when the requested anchor DID mount — otherwise #323's own
	 * symptom (two identical buttons) comes back through the fix meant to prevent losing the
	 * button. Pins the negative case the test above cannot: same list, anchor present.
	 */
	it( 'adds no fallback slot when the requested placement mounted normally', () => {
		bootPickup( [ 'rate' ] );

		expect( document.querySelector( '[data-woodev-pickup-placement="review"]' ) ).toBeNull();
	} );

	/**
	 * The explicitly-empty list (issue #308 item 2 — a plugin that renders its own trigger)
	 * is the ONE case that gets no fallback either: it is a decision, not a failure to
	 * resolve an anchor, and #323's fallback must not undo it. Same missing-`<li>` page as
	 * the fallback test above, so the only difference is the list itself.
	 */
	it( 'never falls back for an explicitly empty placement list', () => {
		bootPickup( [], { withRateListItem: false } );

		expect( slots() ).toEqual( [] );
	} );

	/**
	 * The fallback must not be able to reintroduce #323's own symptom. `placeSlots()` runs
	 * again on every shipping-method change, so a page that needed the fallback once and can
	 * resolve the real anchor later would end up with BOTH slots mounted — two identical
	 * buttons, which is the exact defect this card is about — unless a slot outside the
	 * intended set is removed rather than merely left alone.
	 *
	 * Reproduced through a real WooCommerce shape: two rates, neither pre-checked, so
	 * `resolvePlacementAnchor( 'rate' )` has nothing to resolve until the customer picks one.
	 */
	it( 'removes the fallback slot once the requested anchor becomes resolvable', () => {
		bootPickup( [ 'rate' ], { preChecked: false } );

		expect( slots().map( ( el ) => el.getAttribute( 'data-woodev-pickup-placement' ) ) )
			.toEqual( [ 'review' ] );

		// The customer picks the pickup method — now the rate's <li> is a resolvable anchor.
		const radio = document.getElementById( 'shipping_method_0_carrier_pickup' );

		radio.checked = true;
		global.jQuery( radio ).trigger( 'change' );

		const mounted = slots();

		expect( mounted ).toHaveLength( 1 );
		expect( mounted[ 0 ].getAttribute( 'data-woodev-pickup-placement' ) ).toBe( 'rate' );
	} );

	/**
	 * A multi-package cart renders `templates/cart/cart-shipping.php` once per package, so
	 * there are several `<ul id="shipping_method">` blocks and one checked radio PER PACKAGE.
	 * `$( 'input[name^="shipping_method"]' ).filter( ':checked' ).closest( 'li' )` therefore
	 * resolves to MORE than one anchor, and `$anchor.append( $slot )` makes jQuery clone the
	 * slot into every target but the last — two anchors, two identical triggers, and two DOM
	 * nodes sharing one id. That is #323's own symptom in a stock WooCommerce configuration,
	 * and `pruneSlots()` cannot see it: both copies carry the placement that is in `keep`.
	 *
	 * The `'review'` branch of `resolvePlacementAnchor()` already guards this with `.first()`;
	 * the `'rate'` branch, added by #274, did not. First-checked-radio is also exactly what
	 * `selectedShippingMethod()` reads, so the slot lands beside the rate the framework itself
	 * considers chosen.
	 */
	it( 'mounts ONE slot on a multi-package cart, not one per package', () => {
		bootPickup( [ 'rate' ], { packages: 2 } );

		const mounted = slots();

		expect( mounted ).toHaveLength( 1 );
		expect( mounted[ 0 ].closest( 'li' ) ).not.toBeNull();
	} );

	/**
	 * `pruneSlots()` must only ever remove anchors the FRAMEWORK created. An explicitly empty
	 * placement list means "I render my own trigger" (issue #308 item 2), and the documented
	 * way such a plugin uses the picker is its own server-rendered `[data-woodev-pickup-slot]`
	 * anchor — so a prune that swept every matching node would turn a contract that used to
	 * mean "the framework adds nothing" into "the framework deletes yours, on every pass".
	 */
	it( 'never removes a pickup anchor the framework did not create', () => {
		installPickupMarkup();
		document.body.insertAdjacentHTML(
			'beforeend',
			'<div id="a-plugins-own-anchor" data-woodev-pickup-slot="' + PICKUP_FIELD + '"></div>'
		);

		global.jQuery = require( 'jquery' );
		global.$      = global.jQuery;
		window.jQuery = global.jQuery;

		window.WoodevCheckoutFieldStore = require(
			'../../woodev/shipping-method/assets/js/frontend/checkout-field-store.js'
		);
		window[ CONFIG_GLOBAL ] = buildPickupConfig( [] );

		ajaxCalls = stubAjax();

		require( '../../woodev/shipping-method/assets/js/frontend/checkout-field-classic.js' );

		jest.runAllTimers();

		expect( document.getElementById( 'a-plugins-own-anchor' ) ).not.toBeNull();
	} );

	/**
	 * A slot is not an empty box by the time a placement can change: `pickup-mount.js` has put
	 * the trigger button — and, for a customer who already chose a point, the address block —
	 * inside it. `pickup-mount.js` re-mounts only on `updated_checkout`, and `placeSlots()`
	 * also runs on a plain `shipping_method` change, so a prune that DESTROYED the old slot
	 * would leave the customer with no button until (and unless) WooCommerce's ajax lands.
	 */
	it( 'carries a mounted trigger across a placement swap instead of destroying it', () => {
		bootPickup( [ 'rate' ], { preChecked: false } );

		// What pickup-mount.js's mountSlot() puts into whichever slot §8 placed.
		slots()[ 0 ].insertAdjacentHTML(
			'beforeend',
			'<button type="button" class="woodev-pickup-trigger">Выбрать пункт выдачи</button>'
		);

		const radio = document.getElementById( 'shipping_method_0_carrier_pickup' );

		radio.checked = true;
		global.jQuery( radio ).trigger( 'change' );

		const trigger = document.querySelectorAll( '.woodev-pickup-trigger' );

		expect( trigger ).toHaveLength( 1 );
		expect( trigger[ 0 ].closest( '[data-woodev-pickup-slot]' )
			.getAttribute( 'data-woodev-pickup-placement' ) ).toBe( 'rate' );
	} );

	/**
	 * The other half of the transfer rule, and the one the single-placement tests cannot reach:
	 * when the SURVIVING slot already holds a trigger, the stale slot's must not be carried
	 * into it. `pickup-mount.js`'s `mountSlot()` mounts a trigger into EVERY slot, so under the
	 * both-at-once configuration (`woodev_pickup_slot_placements` returning `[ 'review',
	 * 'rate' ]`) both are populated; losing the `'rate'` anchor afterwards would then stack two
	 * identical «Выбрать пункт выдачи» buttons inside the review slot — #323's own symptom,
	 * reintroduced by the repair for #323's other symptom.
	 *
	 * The survivor is not freshly created here, which is what makes the case reachable at all:
	 * `.after()` MOVES an existing `'review'` node, children and all.
	 */
	it( 'does not stack a second trigger into a survivor that already has one', () => {
		bootPickup( [ 'review', 'rate' ] );

		// What pickup-mount.js does: one trigger per mounted slot.
		slots().forEach( ( slot ) => slot.insertAdjacentHTML(
			'beforeend',
			'<button type="button" class="woodev-pickup-trigger">Выбрать пункт выдачи</button>'
		) );

		expect( document.querySelectorAll( '.woodev-pickup-trigger' ) ).toHaveLength( 2 );

		// A re-quote leaves two rates with none checked — the 'rate' anchor is gone, the
		// 'review' one is untouched.
		const radio = document.getElementById( 'shipping_method_0_carrier_pickup' );
		const other = document.createElement( 'input' );

		radio.checked = false;
		other.type    = 'radio';
		other.name    = 'shipping_method[0]';
		other.value   = 'flat_rate';
		radio.closest( 'li' ).appendChild( other );

		global.jQuery( other ).trigger( 'change' );

		expect( slots().map( ( el ) => el.getAttribute( 'data-woodev-pickup-placement' ) ) )
			.toEqual( [ 'review' ] );
		expect( document.querySelectorAll( '.woodev-pickup-trigger' ) ).toHaveLength( 1 );
	} );

	it( 'mounts BOTH placements when the filter asks for both — after the list AND inside the selected rate\'s <li>', () => {
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
