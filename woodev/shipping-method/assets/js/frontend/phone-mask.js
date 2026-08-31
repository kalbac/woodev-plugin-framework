/**
 * Woodev Phone Mask — card #503.
 *
 * Bonus checkout convenience, no library: formats `#billing_phone` against a
 * country's `#`-placeholder template ({@see \Woodev\Framework\Shipping\Checkout\Phone_Mask_Patterns},
 * the PHP table this file's `patterns` config is built from). NOT validation —
 * the mask caps a number from ABOVE only (extra digits cannot be typed); an
 * under-filled number is never blocked from submitting. `WC_Validation`/
 * `wc_format_phone_number()` are a carrier's own concern, not this file's.
 *
 * CONFIG DISCOVERY mirrors `checkout-field-classic.js`: scans `window` for the
 * `woodev_checkout_field_config_` PREFIX (one global per shipping plugin on a
 * multi-plugin store) and reads the first `config.phone_mask` block it finds —
 * the option is a single store-wide setting, so every plugin's config carries
 * the identical value. `Checkout_Handler::enqueue_assets()` only enqueues this
 * file at all when the merchant turned the option on, so `phone_mask` is
 * always present by the time this runs.
 *
 * FORMATTING ALGORITHM. A template is `#`-placeholders for the significant
 * (national) digits, with everything else literal (`+7 (###) ###-##-##`).
 * `formatPhone()`:
 *   1. strips everything but digits;
 *   2. if there are MORE digits than the template has placeholders, keeps the
 *      RIGHTMOST ones — the digits that actually identify the subscriber.
 *      This one rule is what lets `8 929 600 80 90`, `+7 929 600 80 90` and
 *      `9296008090` all land on the same `+7 (929) 600-80-90`, with no
 *      per-country "trunk prefix" table: an 11-digit `8…`/`7…` RU number and
 *      the bare 10-digit number both keep the same rightmost 10 digits;
 *   3. walks the template left to right, emitting literal characters
 *      untouched and consuming one digit per `#`, stopping the moment digits
 *      run out — an under-filled number renders as a partial mask, never
 *      padded, never refused.
 *
 * COUNTRY CHANGE (`mode: 'auto'`) must re-apply the mask WITHOUT mangling
 * what the customer already typed — the same class of problem as the
 * location cascade's country switch (issues #458/#490,
 * docs-internal/gotchas/a-programmatic-parent-change-must-not-run-a-destructive-cascade.md).
 * That gotcha's rule of thumb — gate a destructive reaction on a REAL
 * transition, not on an event — applies here too: `onCountryChange()` only
 * reformats when the resolved template actually differs from the one already
 * active, and it re-derives the customer's SIGNIFICANT digits before
 * reformatting rather than clearing the field. Simply re-extracting digits
 * from the currently MASKED text would double-count the old template's own
 * literal calling code (`+7`'s "7") as a significant digit, so
 * `significantDigits()` strips that known prefix first when it is still
 * present at the front of the digit string.
 *
 * @file
 * @since 2.0.2
 */

( function( $ ) {
	'use strict'

	var PREFIX = 'woodev_checkout_field_config_'

	/**
	 * Keeps only digit characters.
	 *
	 * @param {*} value
	 * @returns {string}
	 */
	function extractDigits( value ) {
		return String( null === value || undefined === value ? '' : value ).replace( /\D/g, '' )
	}

	/**
	 * Counts the `#` placeholders in a template.
	 *
	 * @param {string} template
	 * @returns {number}
	 */
	function placeholderCount( template ) {
		var count = 0

		for( var i = 0; i < template.length; i++ ) {
			if( template.charAt( i ) === '#' ) {
				count++
			}
		}

		return count
	}

	/**
	 * The template's own leading literal digits (its calling code, e.g. "7" for
	 * "+7 (###)…", "375" for "+375 (##)…") — everything before the first `#`.
	 *
	 * @param {string} template
	 * @returns {string}
	 */
	function literalPrefixDigits( template ) {
		var out = ''

		for( var i = 0; i < template.length; i++ ) {
			var ch = template.charAt( i )

			if( ch === '#' ) {
				break
			}

			if( /\d/.test( ch ) ) {
				out += ch
			}
		}

		return out
	}

	/**
	 * Formats a raw value against a `#`-placeholder template. See the file
	 * docblock's FORMATTING ALGORITHM section.
	 *
	 * @param {string} rawValue
	 * @param {string|null} template
	 * @returns {string}
	 */
	function formatPhone( rawValue, template ) {
		if( ! template ) {
			return String( null === rawValue || undefined === rawValue ? '' : rawValue )
		}

		var digits = extractDigits( rawValue )
		var maxDigits = placeholderCount( template )

		if( digits.length > maxDigits ) {
			digits = digits.slice( digits.length - maxDigits )
		}

		if( '' === digits ) {
			return ''
		}

		var out = ''
		var di = 0

		for( var i = 0; i < template.length && di < digits.length; i++ ) {
			var ch = template.charAt( i )

			if( ch === '#' ) {
				out += digits.charAt( di )
				di++
			} else {
				out += ch
			}
		}

		return out
	}

	/**
	 * The customer's own significant digits, with a PREVIOUSLY applied
	 * template's leading calling code stripped off if still present at the
	 * front — see the file docblock's COUNTRY CHANGE section.
	 *
	 * @param {string} maskedValue
	 * @param {string|null} previousTemplate
	 * @returns {string}
	 */
	function significantDigits( maskedValue, previousTemplate ) {
		var digits = extractDigits( maskedValue )

		if( ! previousTemplate ) {
			return digits
		}

		var prefix = literalPrefixDigits( previousTemplate )

		return ( prefix && digits.indexOf( prefix ) === 0 ) ? digits.slice( prefix.length ) : digits
	}

	/**
	 * Resolves which template applies right now.
	 *
	 * @param {string} mode 'off' | 'auto' | a country code.
	 * @param {string} country the checkout's currently selected country (only used for 'auto').
	 * @param {Object.<string,string>} patterns country => template.
	 * @returns {string|null}
	 */
	function resolvePattern( mode, country, patterns ) {
		if( ! mode || 'off' === mode ) {
			return null
		}

		var code = 'auto' === mode ? country : mode

		return ( patterns && patterns[ code ] ) || null
	}

	/**
	 * How many digit characters precede `pos` in `value`.
	 *
	 * @param {string} value
	 * @param {number} pos
	 * @returns {number}
	 */
	function digitsBefore( value, pos ) {
		return extractDigits( value.slice( 0, pos ) ).length
	}

	/**
	 * The caret position in `masked` right after its Nth digit — end of string
	 * if `masked` has fewer than `n` digits. Keeps the caret glued to the digit
	 * the customer just typed/deleted instead of jumping to the end on every
	 * keystroke (backspace in the middle must stay usable).
	 *
	 * @param {string} masked
	 * @param {number} n
	 * @returns {number}
	 */
	function positionForDigitCount( masked, n ) {
		if( n <= 0 ) {
			return 0
		}

		var seen = 0

		for( var i = 0; i < masked.length; i++ ) {
			if( /\d/.test( masked.charAt( i ) ) ) {
				seen++

				if( seen === n ) {
					return i + 1
				}
			}
		}

		return masked.length
	}

	/**
	 * Finds the first `phone_mask` config block among the plugin-suffixed
	 * `woodev_checkout_field_config_*` globals (see file docblock).
	 *
	 * @returns {Object|null}
	 */
	function findConfig() {
		var keys = Object.keys( window )

		for( var i = 0; i < keys.length; i++ ) {
			if( keys[ i ].indexOf( PREFIX ) === 0 ) {
				var config = window[ keys[ i ] ]

				if( config && config.phone_mask ) {
					return config.phone_mask
				}
			}
		}

		return null
	}

	/**
	 * Returns the checkout's currently selected country, '' if the field is absent.
	 *
	 * @returns {string}
	 */
	function currentCountry() {
		var $country = $( '#billing_country' )

		return $country.length ? ( $country.val() || '' ) : ''
	}

	/**
	 * Wires the mask onto `#billing_phone`. A no-op when the field is missing
	 * or the merchant left the option off — `Checkout_Handler::enqueue_assets()`
	 * already gates the enqueue on `mode !== 'off'`, this is a defensive second
	 * gate for a config block reused across surfaces.
	 *
	 * @param {Object} phoneMask {mode, patterns}.
	 * @returns {void}
	 */
	function boot( phoneMask ) {
		var $phone = $( '#billing_phone' )

		if( ! $phone.length || ! phoneMask || 'off' === phoneMask.mode ) {
			return
		}

		var patterns = phoneMask.patterns || {}
		var activeTemplate = resolvePattern( phoneMask.mode, currentCountry(), patterns )

		function reformat() {
			if( ! activeTemplate ) {
				return
			}

			var el = $phone.get( 0 )
			var digitsSoFar = digitsBefore( el.value, el.selectionStart )
			var next = formatPhone( el.value, activeTemplate )

			if( next !== el.value ) {
				el.value = next

				var caret = positionForDigitCount( next, digitsSoFar )

				el.setSelectionRange( caret, caret )
			}
		}

		// Первичное применение — форматирует значение, восстановленное сессией
		// WooCommerce (уже сохранённый заказ, `updated_checkout`, автозаполнение).
		reformat()

		$phone.on( 'input', reformat )

		if( 'auto' === phoneMask.mode ) {
			$( document.body ).on( 'country_to_state_changed', function( event, country ) {
				var nextTemplate = resolvePattern( phoneMask.mode, country || currentCountry(), patterns )

				// Гейт на РЕАЛЬНЫЙ переход, а не на событие — WooCommerce шлёт это же
				// событие и когда страна не менялась (см. докблок файла, COUNTRY CHANGE).
				if( nextTemplate === activeTemplate ) {
					return
				}

				var el = $phone.get( 0 )
				var significant = significantDigits( el.value, activeTemplate )

				activeTemplate = nextTemplate
				el.value = nextTemplate ? formatPhone( significant, nextTemplate ) : significant
			} )
		}
	}

	$( function() {
		boot( findConfig() )
	} )

	// -------------------------------------------------------------------------
	// UMD-ish dual export (jest)
	// -------------------------------------------------------------------------

	var api = {
		formatPhone: formatPhone,
		resolvePattern: resolvePattern,
		significantDigits: significantDigits,
		positionForDigitCount: positionForDigitCount,
		boot: boot,
	}

	if( typeof module !== 'undefined' && module.exports ) {
		module.exports = api
	}

}( jQuery ) )
