/**
 * Tests for phone-mask.js — card #503 round 2, the IMask rewrite.
 *
 * @see woodev/shipping-method/assets/js/frontend/phone-mask.js
 */

'use strict';

const RU = '+7 (###) ###-##-##';
const BY = '+375 (##) ###-##-##';
const TRUNK_PREFIXES = { RU: '8', KZ: '8' };

function loadModule() {
	jest.resetModules();
	delete global.jQuery;
	delete global.$;
	delete window.jQuery;
	delete window.IMask;

	global.jQuery = require( 'jquery' );
	global.$      = global.jQuery;
	window.jQuery = global.jQuery;

	// Vendored verbatim — see phone-mask.js's own docblock and the vendor file's own
	// header comment. Loaded first, exactly as `Checkout_Handler::enqueue_assets()`
	// orders it in production (`woodev-phone-mask` depends on `woodev-imask`).
	require( '../../woodev/shipping-method/assets/js/vendor/imask.min.js' );

	return require( '../../woodev/shipping-method/assets/js/frontend/phone-mask.js' );
}

// -----------------------------------------------------------------------
// resolvePattern() / resolveTrunkDigit() — mode → template / trunk digit
// -----------------------------------------------------------------------

describe( 'resolvePattern', () => {
	const { resolvePattern } = loadModule();
	const patterns = { RU, BY };

	test( '"off" never resolves a template', () => {
		expect( resolvePattern( 'off', 'RU', patterns ) ).toBeNull();
		expect( resolvePattern( '', 'RU', patterns ) ).toBeNull();
	} );

	test( '"auto" follows the given country', () => {
		expect( resolvePattern( 'auto', 'RU', patterns ) ).toBe( RU );
		expect( resolvePattern( 'auto', 'BY', patterns ) ).toBe( BY );
	} );

	test( '"auto" against a country with no known pattern resolves to null', () => {
		expect( resolvePattern( 'auto', 'DE', patterns ) ).toBeNull();
	} );

	test( 'a fixed country code ignores the checkout country entirely', () => {
		expect( resolvePattern( 'RU', 'BY', patterns ) ).toBe( RU );
		expect( resolvePattern( 'BY', 'RU', patterns ) ).toBe( BY );
	} );
} );

describe( 'resolveTrunkDigit', () => {
	const { resolveTrunkDigit } = loadModule();

	test( 'RU and KZ carry the "8" trunk digit', () => {
		expect( resolveTrunkDigit( 'auto', 'RU', TRUNK_PREFIXES ) ).toBe( '8' );
		expect( resolveTrunkDigit( 'auto', 'KZ', TRUNK_PREFIXES ) ).toBe( '8' );
	} );

	test( 'a country outside the (deliberately small) table has none', () => {
		expect( resolveTrunkDigit( 'auto', 'BY', TRUNK_PREFIXES ) ).toBeNull();
	} );

	test( '"off" never resolves a trunk digit', () => {
		expect( resolveTrunkDigit( 'off', 'RU', TRUNK_PREFIXES ) ).toBeNull();
	} );

	test( 'a fixed country code ignores the checkout country entirely', () => {
		expect( resolveTrunkDigit( 'RU', 'BY', TRUNK_PREFIXES ) ).toBe( '8' );
	} );
} );

// -----------------------------------------------------------------------
// toImaskPattern() — the `#` → IMask boundary translation
// -----------------------------------------------------------------------

describe( 'toImaskPattern', () => {
	const { toImaskPattern } = loadModule();

	test( 'every shipped template (no literal "0") passes through unchanged', () => {
		expect( toImaskPattern( RU ) ).toBe( RU );
		expect( toImaskPattern( BY ) ).toBe( BY );
	} );

	test( 'a literal "0" in a template (e.g. a future +90/+20 entry) is escaped, not left to collide with IMask\'s own built-in "0" placeholder', () => {
		expect( toImaskPattern( '+90 (###) ###-##-##' ) ).toBe( '+9\\0 (###) ###-##-##' );
	} );
} );

// -----------------------------------------------------------------------
// buildMaskOptions() through a headless IMask — the operator's worked example
// (headless `IMask.createMask()` + `.resolve()`, which IMask's own docs describe as
// "exactly the same as if a value was pasted in input" — the right surface for paste
// scenarios; `.append()` for one-keystroke-at-a-time typing).
// -----------------------------------------------------------------------

describe( 'buildMaskOptions — trunk-prefix conversion and paste (RU worked example)', () => {
	let IMask;
	let buildMaskOptions;
	let resolveTrunkDigit;

	beforeEach( () => {
		( { buildMaskOptions, resolveTrunkDigit } = loadModule() );
		IMask = window.IMask;
	} );

	function ruMask() {
		return IMask.createMask( buildMaskOptions( RU, resolveTrunkDigit( 'auto', 'RU', TRUNK_PREFIXES ) ) );
	}

	test( 'typing "8" as the very first character converts it to "+7"', () => {
		const mask = ruMask();
		mask.append( '8', { input: true } );
		expect( mask.value ).toBe( '+7' );
	} );

	test( 'typing "+" first waits — nothing forced yet', () => {
		const mask = ruMask();
		mask.append( '+', { input: true } );
		expect( mask.value ).toBe( '+' );
	} );

	test( '"+7" typed is left as is', () => {
		const mask = ruMask();
		mask.append( '+', { input: true } );
		mask.append( '7', { input: true } );
		expect( mask.value ).toBe( '+7' );
	} );

	test( 'typing the rest digit by digit after "8" lands on the worked example', () => {
		const mask = ruMask();
		'89296008090'.split( '' ).forEach( ( ch ) => mask.append( ch, { input: true } ) );
		expect( mask.value ).toBe( '+7 (929) 600-80-90' );
	} );

	test( 'paste beginning with "+7" is kept', () => {
		const mask = ruMask();
		mask.resolve( '+79296008090' );
		expect( mask.value ).toBe( '+7 (929) 600-80-90' );
	} );

	test( 'paste beginning with "8" has that "8" replaced with "+7"', () => {
		const mask = ruMask();
		mask.resolve( '89296008090' );
		expect( mask.value ).toBe( '+7 (929) 600-80-90' );
	} );

	test( 'paste beginning with neither gets "+7" prepended', () => {
		const mask = ruMask();
		mask.resolve( '9296008090' );
		expect( mask.value ).toBe( '+7 (929) 600-80-90' );
	} );

	test( 'a country with a different calling code and no trunk digit (BY) formats on its own template', () => {
		const mask = IMask.createMask( buildMaskOptions( BY, resolveTrunkDigit( 'auto', 'BY', TRUNK_PREFIXES ) ) );
		mask.resolve( '291234567' );
		expect( mask.value ).toBe( '+375 (29) 123-45-67' );
	} );

	test( 'unmaskedValue holds only the significant digits, never the template\'s own calling code — country-switch regrouping needs no separate "strip the old prefix" step', () => {
		const mask = ruMask();
		mask.resolve( '9296008090' );
		expect( mask.unmaskedValue ).toBe( '9296008090' );

		mask.updateOptions( buildMaskOptions( BY, resolveTrunkDigit( 'auto', 'BY', TRUNK_PREFIXES ) ) );
		expect( mask.value ).toBe( '+375 (92) 960-08-09' );
	} );
} );

// -----------------------------------------------------------------------
// boot() — DOM wiring: both fields, typing, country switch, missing fields, `off`.
// -----------------------------------------------------------------------

describe( 'boot', () => {
	function installMarkup( { billingCountry = 'RU', billingPhone = '', shippingCountry = 'RU', shippingPhone = '', withShipping = true } = {} ) {
		document.body.innerHTML = `
			<form class="checkout woocommerce-checkout">
				<select id="billing_country">
					<option value="RU" selected>Россия</option>
					<option value="BY">Беларусь</option>
					<option value="DE">Германия</option>
				</select>
				<input type="text" id="billing_phone" value="${ billingPhone }" />
				${ withShipping ? `
				<select id="shipping_country">
					<option value="RU" selected>Россия</option>
					<option value="BY">Беларусь</option>
					<option value="DE">Германия</option>
				</select>
				<input type="text" id="shipping_phone" value="${ shippingPhone }" />
				` : '' }
			</form>
		`;
		document.getElementById( 'billing_country' ).value = billingCountry;
		if( withShipping ) {
			document.getElementById( 'shipping_country' ).value = shippingCountry;
		}
	}

	function type( id, value ) {
		const el = document.getElementById( id );
		el.value = value;
		el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	}

	test( 'formats a session-restored value on boot, on BOTH billing and shipping', () => {
		installMarkup( { billingPhone: '9296008090', shippingPhone: '89296008090' } );
		const { boot } = loadModule();

		boot( { mode: 'auto', patterns: { RU, BY }, trunk_prefixes: TRUNK_PREFIXES } );

		expect( document.getElementById( 'billing_phone' ).value ).toBe( '+7 (929) 600-80-90' );
		expect( document.getElementById( 'shipping_phone' ).value ).toBe( '+7 (929) 600-80-90' );
	} );

	test( '"off" mode never touches either field', () => {
		installMarkup( { billingPhone: '9296008090', shippingPhone: '9296008090' } );
		const { boot } = loadModule();

		boot( { mode: 'off', patterns: { RU }, trunk_prefixes: TRUNK_PREFIXES } );

		expect( document.getElementById( 'billing_phone' ).value ).toBe( '9296008090' );
		expect( document.getElementById( 'shipping_phone' ).value ).toBe( '9296008090' );
	} );

	test( 'typing on billing_phone and shipping_phone reformats each live, independently', () => {
		installMarkup();
		const { boot } = loadModule();

		boot( { mode: 'auto', patterns: { RU, BY }, trunk_prefixes: TRUNK_PREFIXES } );

		type( 'billing_phone', '89296008090' );
		type( 'shipping_phone', '9161234567' );

		expect( document.getElementById( 'billing_phone' ).value ).toBe( '+7 (929) 600-80-90' );
		expect( document.getElementById( 'shipping_phone' ).value ).toBe( '+7 (916) 123-45-67' );
	} );

	test( 'missing shipping_phone is a no-op for that field, billing still masks', () => {
		installMarkup( { withShipping: false, billingPhone: '9296008090' } );
		const { boot } = loadModule();

		expect( () => boot( { mode: 'auto', patterns: { RU }, trunk_prefixes: TRUNK_PREFIXES } ) ).not.toThrow();
		expect( document.getElementById( 'billing_phone' ).value ).toBe( '+7 (929) 600-80-90' );
	} );

	test( 'missing both fields is a no-op, never throws', () => {
		document.body.innerHTML = '<form class="checkout"></form>';
		const { boot } = loadModule();

		expect( () => boot( { mode: 'auto', patterns: { RU }, trunk_prefixes: TRUNK_PREFIXES } ) ).not.toThrow();
	} );

	test( 'country switch (auto mode) regroups the typed digits under the new template, per field, without losing them', () => {
		installMarkup();
		const { boot } = loadModule();

		boot( { mode: 'auto', patterns: { RU, BY }, trunk_prefixes: TRUNK_PREFIXES } );

		type( 'billing_phone', '9296008090' );
		expect( document.getElementById( 'billing_phone' ).value ).toBe( '+7 (929) 600-80-90' );

		document.getElementById( 'billing_country' ).value = 'BY';
		global.jQuery( document.body ).trigger( 'country_to_state_changed', [ 'BY' ] );

		expect( document.getElementById( 'billing_phone' ).value ).toBe( '+375 (92) 960-08-09' );
		// shipping_phone's own country field never changed — its field is untouched.
		expect( document.getElementById( 'shipping_phone' ).value ).toBe( '' );
	} );

	test( 'a repeat event with no real country transition is a no-op', () => {
		installMarkup();
		const { boot } = loadModule();

		boot( { mode: 'auto', patterns: { RU, BY }, trunk_prefixes: TRUNK_PREFIXES } );

		type( 'billing_phone', '9296008090' );
		global.jQuery( document.body ).trigger( 'country_to_state_changed', [ 'RU' ] );

		expect( document.getElementById( 'billing_phone' ).value ).toBe( '+7 (929) 600-80-90' );
	} );

	test( 're-attaches after `updated_checkout` replaces the address fragment (issue-#337-class defect)', () => {
		installMarkup();
		const { boot } = loadModule();

		boot( { mode: 'auto', patterns: { RU, BY }, trunk_prefixes: TRUNK_PREFIXES } );

		type( 'billing_phone', '9296008090' );
		expect( document.getElementById( 'billing_phone' ).value ).toBe( '+7 (929) 600-80-90' );

		// WooCommerce's checkout AJAX response replaces the WHOLE fragment with fresh markup
		// carrying whatever the server rendered — a brand-new, unmasked node.
		const form = document.querySelector( 'form.checkout' );
		form.innerHTML = `
			<select id="billing_country"><option value="BY" selected>Беларусь</option></select>
			<input type="text" id="billing_phone" value="291234567" />
			<select id="shipping_country"><option value="BY" selected>Беларусь</option></select>
			<input type="text" id="shipping_phone" value="299876543" />
		`;

		const replacedBillingPhone = document.getElementById( 'billing_phone' );

		global.jQuery( document.body ).trigger( 'updated_checkout' );

		// The NEW node is the one that ends up formatted — proof a fresh IMask instance
		// actually attached to it, not a stale reference to the detached old node.
		expect( document.getElementById( 'billing_phone' ) ).toBe( replacedBillingPhone );
		expect( replacedBillingPhone.value ).toBe( '+375 (29) 123-45-67' );
		expect( document.getElementById( 'shipping_phone' ).value ).toBe( '+375 (29) 987-65-43' );

		// And the re-attached instance is live: typing on the NEW node still reformats.
		type( 'billing_phone', '291234567x' );
		expect( document.getElementById( 'billing_phone' ).value ).toBe( '+375 (29) 123-45-67' );
	} );

	test( 'switching to a country with no known pattern stops masking without wiping the field', () => {
		installMarkup();
		const { boot } = loadModule();

		boot( { mode: 'auto', patterns: { RU }, trunk_prefixes: TRUNK_PREFIXES } );

		type( 'billing_phone', '9296008090' );
		expect( document.getElementById( 'billing_phone' ).value ).toBe( '+7 (929) 600-80-90' );

		document.getElementById( 'billing_country' ).value = 'DE';
		global.jQuery( document.body ).trigger( 'country_to_state_changed', [ 'DE' ] );

		expect( document.getElementById( 'billing_phone' ).value ).toBe( '+7 (929) 600-80-90' );

		// No pattern active now: further typing passes through unformatted.
		type( 'billing_phone', '+7 (929) 600-80-90x' );
		expect( document.getElementById( 'billing_phone' ).value ).toBe( '+7 (929) 600-80-90x' );
	} );
} );
