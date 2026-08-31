/**
 * Tests for phone-mask.js — card #503.
 *
 * @see woodev/shipping-method/assets/js/frontend/phone-mask.js
 */

'use strict';

const RU = '+7 (###) ###-##-##';
const BY = '+375 (##) ###-##-##';

function loadModule() {
	jest.resetModules();
	delete global.jQuery;
	delete global.$;
	delete window.jQuery;

	global.jQuery = require( 'jquery' );
	global.$      = global.jQuery;
	window.jQuery = global.jQuery;

	return require( '../../woodev/shipping-method/assets/js/frontend/phone-mask.js' );
}

// -----------------------------------------------------------------------
// formatPhone() — the pure formatter
// -----------------------------------------------------------------------

describe( 'formatPhone', () => {
	const { formatPhone } = loadModule();

	test( 'bare 10-digit RU number → the card #503 worked example', () => {
		expect( formatPhone( '9296008090', RU ) ).toBe( '+7 (929) 600-80-90' );
	} );

	test( '8-prefixed 11-digit number lands on the same mask as +7', () => {
		expect( formatPhone( '89296008090', RU ) ).toBe( '+7 (929) 600-80-90' );
		expect( formatPhone( '+79296008090', RU ) ).toBe( '+7 (929) 600-80-90' );
	} );

	test( 'a pasted foreign number keeps its rightmost digits, never refused', () => {
		expect( formatPhone( '+1 415 555 0198', RU ) ).toBe( '+7 (415) 555-01-98' );
	} );

	test( 'under-filled number renders a partial mask, never padded', () => {
		expect( formatPhone( '929', RU ) ).toBe( '+7 (929' );
		expect( formatPhone( '9', RU ) ).toBe( '+7 (9' );
	} );

	test( 'empty input stays empty — no mask forced on an untouched field', () => {
		expect( formatPhone( '', RU ) ).toBe( '' );
		expect( formatPhone( null, RU ) ).toBe( '' );
	} );

	test( 'null template passes the raw value through untouched', () => {
		expect( formatPhone( 'anything', null ) ).toBe( 'anything' );
	} );

	test( 'BY template groups 9 digits differently from RU', () => {
		expect( formatPhone( '291234567', BY ) ).toBe( '+375 (29) 123-45-67' );
	} );
} );

// -----------------------------------------------------------------------
// resolvePattern() — mode → template
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

	test( 'a fixed country with no known pattern resolves to null', () => {
		expect( resolvePattern( 'DE', 'RU', patterns ) ).toBeNull();
	} );
} );

// -----------------------------------------------------------------------
// significantDigits() — country-switch re-derivation (#458/#490 class of bug)
// -----------------------------------------------------------------------

describe( 'significantDigits', () => {
	const { significantDigits } = loadModule();

	test( 'strips the OLD template\'s own leading calling code', () => {
		expect( significantDigits( '+7 (929) 600-80-90', RU ) ).toBe( '9296008090' );
	} );

	test( 'a longer calling code (BY, "375") is stripped the same way', () => {
		expect( significantDigits( '+375 (29) 123-45-67', BY ) ).toBe( '291234567' );
	} );

	test( 'no previous template — every digit is significant', () => {
		expect( significantDigits( '9296008090', null ) ).toBe( '9296008090' );
	} );
} );

// -----------------------------------------------------------------------
// positionForDigitCount() — caret preservation for mid-string backspace
// -----------------------------------------------------------------------

describe( 'positionForDigitCount', () => {
	const { positionForDigitCount } = loadModule();

	test( 'lands right after the Nth digit', () => {
		// '+7 (929) 600-80-90' — digit characters sit at indices 1,4,5,6,9,10,11,13,14,16,17;
		// the 3rd one is the '2' of "929", at index 5.
		expect( positionForDigitCount( '+7 (929) 600-80-90', 3 ) ).toBe( 6 );
	} );

	test( 'zero digits → start of string', () => {
		expect( positionForDigitCount( '+7 (929)', 0 ) ).toBe( 0 );
	} );

	test( 'more digits requested than present → end of string', () => {
		expect( positionForDigitCount( '+7 (9', 99 ) ).toBe( 5 );
	} );
} );

// -----------------------------------------------------------------------
// boot() — DOM wiring: initial format, typing, and the country-change path
// -----------------------------------------------------------------------

describe( 'boot', () => {
	function installMarkup( country, phone ) {
		document.body.innerHTML = `
			<form class="checkout woocommerce-checkout">
				<select id="billing_country">
					<option value="RU" selected>Россия</option>
					<option value="BY">Беларусь</option>
					<option value="DE">Германия</option>
				</select>
				<input type="text" id="billing_phone" value="${ phone }" />
			</form>
		`;
		document.getElementById( 'billing_country' ).value = country;
	}

	test( 'formats a session-restored value on boot (fixed RU mode)', () => {
		installMarkup( 'RU', '9296008090' );
		const { boot } = loadModule();

		boot( { mode: 'RU', patterns: { RU, BY } } );

		expect( document.getElementById( 'billing_phone' ).value ).toBe( '+7 (929) 600-80-90' );
	} );

	test( '"off" mode never touches the field', () => {
		installMarkup( 'RU', '9296008090' );
		const { boot } = loadModule();

		boot( { mode: 'off', patterns: { RU } } );

		expect( document.getElementById( 'billing_phone' ).value ).toBe( '9296008090' );
	} );

	test( 'typing reformats live', () => {
		installMarkup( 'RU', '' );
		const { boot } = loadModule();

		boot( { mode: 'auto', patterns: { RU, BY } } );

		const phone = document.getElementById( 'billing_phone' );
		phone.value = '9296008090';
		phone.dispatchEvent( new Event( 'input', { bubbles: true } ) );

		expect( phone.value ).toBe( '+7 (929) 600-80-90' );
	} );

	test( 'country switch (auto mode) re-applies the mask WITHOUT losing the typed number', () => {
		installMarkup( 'RU', '' );
		const { boot } = loadModule();

		boot( { mode: 'auto', patterns: { RU, BY } } );

		const phone = document.getElementById( 'billing_phone' );
		phone.value = '9296008090';
		phone.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		expect( phone.value ).toBe( '+7 (929) 600-80-90' );

		global.jQuery( document.body ).trigger( 'country_to_state_changed', [ 'BY' ] );

		// Same digit sequence (capped to BY's 9 placeholders, rightmost kept),
		// regrouped under the BY template — never wiped.
		expect( phone.value ).toBe( '+375 (29) 600-80-90' );
	} );

	test( 'switching to a country with no known pattern stops masking without wiping the field', () => {
		installMarkup( 'RU', '' );
		const { boot } = loadModule();

		boot( { mode: 'auto', patterns: { RU } } );

		const phone = document.getElementById( 'billing_phone' );
		phone.value = '9296008090';
		phone.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		expect( phone.value ).toBe( '+7 (929) 600-80-90' );

		global.jQuery( document.body ).trigger( 'country_to_state_changed', [ 'DE' ] );

		expect( phone.value ).toBe( '9296008090' );

		// No pattern active now: further typing passes through unformatted.
		phone.value = '9296008090x';
		phone.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		expect( phone.value ).toBe( '9296008090x' );
	} );

	test( 'a repeat event for the SAME country is a no-op (gate on a real transition)', () => {
		installMarkup( 'RU', '' );
		const { boot } = loadModule();

		boot( { mode: 'auto', patterns: { RU, BY } } );

		const phone = document.getElementById( 'billing_phone' );
		phone.value = '9296008090';
		phone.dispatchEvent( new Event( 'input', { bubbles: true } ) );

		global.jQuery( document.body ).trigger( 'country_to_state_changed', [ 'RU' ] );

		expect( phone.value ).toBe( '+7 (929) 600-80-90' );
	} );

	test( 'missing #billing_phone is a no-op, never throws', () => {
		document.body.innerHTML = '<form class="checkout"></form>';
		const { boot } = loadModule();

		expect( () => boot( { mode: 'auto', patterns: { RU } } ) ).not.toThrow();
	} );
} );
