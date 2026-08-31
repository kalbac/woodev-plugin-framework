/**
 * Woodev Phone Mask — card #503 round 2, the IMask rewrite.
 *
 * Round 1 (a hand-rolled `#`-template formatter) was rejected on the rig 31.08.2026:
 * garbage output on ordinary typing ("8" → "+7 (8", then "8800" → "+7 (777) 008-8") and
 * `#billing_phone` only — `#shipping_phone` (WC 11.0.1 renders it in the shipping
 * section) got nothing. This rewrite drops the hand-rolled formatter entirely and masks
 * BOTH fields with IMask (MIT, https://imask.js.org, vendored at `../vendor/imask.min.js`
 * — see that file's own header for the pinned version and update instructions) — caret
 * position, backspace in the middle, overtyping and selection replacement are IMask's
 * problem now, not this file's ("Все сценарии не описать", operator, 31.08.2026).
 *
 * CONFIG DISCOVERY mirrors `checkout-field-classic.js`: scans `window` for the
 * `woodev_checkout_field_config_` PREFIX (one global per shipping plugin on a
 * multi-plugin store) and reads the first `config.phone_mask` block it finds — the
 * option is a single store-wide setting, so every plugin's config carries the identical
 * value. `Checkout_Handler::enqueue_assets()` only enqueues this file at all when the
 * merchant turned the option on, so `phone_mask` is always present by the time this runs.
 * `phone_mask.trunk_prefixes` is new in this round — see TRUNK PREFIX below.
 *
 * TEMPLATE TRANSLATION. `Phone_Mask_Patterns`' PUBLIC filter contract
 * (`FILTER_PATTERNS`) uses `#` as its digit placeholder and the operator has already seen
 * that shape — this file does NOT string-replace `#` to IMask's own default `0`
 * placeholder, because IMask's built-in `0`/`a`/`*` definitions stay ACTIVE even after
 * adding a custom one (measured: a template containing a literal `0` — none of the
 * current RU/CIS table's entries do, but a plugin could add one via the filter, e.g. a
 * hypothetical Turkey "+90" or Egypt "+20") would otherwise have that `0` silently
 * swallowed as a SECOND digit placeholder instead of staying the literal calling-code
 * digit it is. So the boundary translation ({@see toImaskPattern}) does two things
 * instead: tells IMask `#` itself is the digit placeholder (`definitions: {'#': ...}`,
 * {@see buildMaskOptions}), and escapes every literal `0` in the template as `\0` — the
 * mechanism IMask's own guide documents for pinning a definition character as fixed
 * ("Pattern Mask > Mask Definitions"). A no-op for every pattern shipped today.
 *
 * TRUNK PREFIX (the RU/KZ "8 stands for +7" convention, operator's worked example,
 * 31.08.2026): typing or pasting a leading `8` where the field is still completely empty
 * — `8` alone converts to `+7`; `89296008090` pasted becomes `+7 (929) 600-80-90`, same as
 * pasting `+79296008090` or the bare `9296008090` — while `+` typed alone waits for the
 * next character, and `+7` already typed is left as is. This is a numbering-plan
 * convention specific to SOME countries (`Phone_Mask_Patterns::get_trunk_prefixes()`,
 * RU/KZ only, filterable the same way as the pattern table itself) — never assumed for
 * every entry ({@see resolveTrunkDigit}). Implemented as a single IMask `prepare` hook
 * ({@see buildPrepare}) that fires once per typed keystroke OR once for an entire pasted
 * string (IMask's own documented distinction between `prepare`, called once per batch,
 * and `prepareChar`, called once per character): while the field is still empty, a
 * leading trunk digit is swapped for the ACTIVE template's own calling-code digits, which
 * then flow through the template's literal "+<code>" prefix by ORDINARY IMask literal
 * matching — never a separate digit. Every other combination in the operator's worked
 * example (`+` waits, `+7` stays, a bare number gets `+7` prepended, a `+`-prefixed paste
 * is kept) needs NO special code at all: IMask's own literal auto-insertion/auto-skip
 * for a pattern mask already produces exactly that behaviour.
 *
 * COUNTRY CHANGE AND `updated_checkout` RE-ATTACH share ONE mechanism
 * ({@see reconcileField}), the same "gate on a REAL transition, not on the event" and
 * "a replaced node is detached and re-attached fresh" philosophy
 * `location-cascade.js`'s `reconcileAfterCheckoutUpdate()` already uses for the location
 * cascade: `#billing_phone`/`#shipping_phone` each track their OWN country field
 * (`#billing_country`/`#shipping_country`) independently, and WooCommerce firing
 * `country_to_state_changed` for the field this module does NOT own (e.g. shipping
 * changed while reconciling the billing entry) is absorbed by the same real-transition
 * gate rather than by inspecting the event's own arguments. A template that is merely
 * UNCHANGED skips reformatting entirely (`IMask#updateOptions()` never runs) so a
 * customer's in-progress typing on the field WC did not touch survives every fire, on
 * every entry, every time.
 *
 * @file
 * @since 2.0.2
 */

( function( $, IMask ) {
	'use strict'

	var PREFIX = 'woodev_checkout_field_config_'

	// -------------------------------------------------------------------------
	// Pure helpers — template/trunk resolution, exported for tests.
	// -------------------------------------------------------------------------

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
	 * Translates a `#`-placeholder template into an IMask pattern string — see the file
	 * docblock's TEMPLATE TRANSLATION section. A no-op for every template with no literal
	 * `0` digit, which today is every shipped entry.
	 *
	 * @param {string} template
	 * @returns {string}
	 */
	function toImaskPattern( template ) {
		return template.replace( /0/g, function() {
			return '\\0'
		} )
	}

	/**
	 * Which country code is actually active: the checkout's own selection in `auto` mode,
	 * the fixed mode value otherwise.
	 *
	 * @param {string} mode 'off' | 'auto' | a fixed country code.
	 * @param {string} country the field's own checkout country, only used for 'auto'.
	 * @returns {string}
	 */
	function activeCode( mode, country ) {
		return 'auto' === mode ? country : mode
	}

	/**
	 * Resolves which template applies right now.
	 *
	 * @param {string} mode 'off' | 'auto' | a country code.
	 * @param {string} country the field's own checkout country (only used for 'auto').
	 * @param {Object.<string,string>} patterns country => template.
	 * @returns {string|null}
	 */
	function resolvePattern( mode, country, patterns ) {
		if( ! mode || 'off' === mode ) {
			return null
		}

		return ( patterns && patterns[ activeCode( mode, country ) ] ) || null
	}

	/**
	 * Resolves the active country's trunk-prefix digit, if it has one — see the file
	 * docblock's TRUNK PREFIX section. `null` for every country not in the (deliberately
	 * small, filterable) trunk-prefix table, never a guessed default.
	 *
	 * @param {string} mode 'off' | 'auto' | a country code.
	 * @param {string} country the field's own checkout country (only used for 'auto').
	 * @param {Object.<string,string>} trunkPrefixes country => single trunk digit.
	 * @returns {string|null}
	 */
	function resolveTrunkDigit( mode, country, trunkPrefixes ) {
		if( ! mode || 'off' === mode ) {
			return null
		}

		return ( trunkPrefixes && trunkPrefixes[ activeCode( mode, country ) ] ) || null
	}

	/**
	 * Builds the IMask `prepare` hook that implements the TRUNK PREFIX rule, or `undefined`
	 * for a country with no trunk digit (IMask then falls back to its own default,
	 * pass-through `prepare`).
	 *
	 * @param {string} template the RAW `#`-template (for its own calling-code digits).
	 * @param {string|null} trunkDigit
	 * @returns {(function(string,Object):string)|undefined}
	 */
	function buildPrepare( template, trunkDigit ) {
		if( ! trunkDigit ) {
			return undefined
		}

		var callingCode = literalPrefixDigits( template )

		return function( appended, masked ) {
			if( masked.value ) {
				return appended // not the very first character — the rule only ever fires once.
			}

			var digitIndex = appended.search( /\d/ )

			if( digitIndex === -1 || appended.charAt( digitIndex ) !== trunkDigit ) {
				return appended
			}

			// Swap exactly the one leading trunk digit for the calling code's own digits —
			// e.g. RU "8" -> "7", so it matches the template's literal "+7" prefix instead
			// of landing in the first placeholder. Whatever follows (a full pasted number)
			// rides along in this SAME call, `prepare` being a once-per-batch hook.
			return appended.slice( 0, digitIndex ) + callingCode + appended.slice( digitIndex + 1 )
		}
	}

	/**
	 * Builds the IMask options for one template/trunk-digit pair.
	 *
	 * @param {string} template the RAW `#`-template.
	 * @param {string|null} trunkDigit
	 * @returns {Object}
	 */
	function buildMaskOptions( template, trunkDigit ) {
		return {
			mask: toImaskPattern( template ),
			definitions: { '#': /[0-9]/ },
			lazy: true,
			prepare: buildPrepare( template, trunkDigit ),
		}
	}

	// -------------------------------------------------------------------------
	// Config discovery — unchanged from round 1.
	// -------------------------------------------------------------------------

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
	 * A field's current value, `''` if the field is absent.
	 *
	 * @param {string} id
	 * @returns {string}
	 */
	function fieldValue( id ) {
		var el = document.getElementById( id )

		return el ? ( el.value || '' ) : ''
	}

	// -------------------------------------------------------------------------
	// Field wiring — one entry per phone field, each tracking its OWN country field.
	// -------------------------------------------------------------------------

	/**
	 * @param {string} fieldId
	 * @param {string} countryFieldId
	 * @returns {Object}
	 */
	function createEntry( fieldId, countryFieldId ) {
		return {
			fieldId: fieldId,
			countryFieldId: countryFieldId,
			el: null,
			imask: null,
			template: null,
		}
	}

	/**
	 * @param {Object} entry
	 * @returns {void}
	 */
	function destroyImask( entry ) {
		if( entry.imask ) {
			entry.imask.destroy()
			entry.imask = null
		}
	}

	/**
	 * Re-verifies ONE entry against the live document and the currently active
	 * mode/patterns/trunk-prefixes — see the file docblock's COUNTRY CHANGE AND
	 * `updated_checkout` RE-ATTACH section. Called once per entry on boot, and again on
	 * every `updated_checkout`/`country_to_state_changed`.
	 *
	 * @param {Object} entry
	 * @param {string} mode
	 * @param {Object.<string,string>} patterns
	 * @param {Object.<string,string>} trunkPrefixes
	 * @returns {void}
	 */
	function reconcileField( entry, mode, patterns, trunkPrefixes ) {
		var live = document.getElementById( entry.fieldId )

		if( ! live ) {
			destroyImask( entry )
			entry.el = null
			entry.template = null

			return
		}

		if( entry.el !== live ) {
			// WooCommerce replaced the address fragment wholesale (`updated_checkout`) — any
			// existing IMask instance is now attached to a DETACHED node. Mirrors
			// `location-cascade.js`'s own `reconcileAfterCheckoutUpdate()`: a replaced node is
			// detached and re-attached fresh, never patched in place.
			destroyImask( entry )
			entry.el = live
		}

		var country = fieldValue( entry.countryFieldId )
		var nextTemplate = resolvePattern( mode, country, patterns )

		if( entry.imask && nextTemplate === entry.template ) {
			return // gate on a REAL transition, not on the event firing.
		}

		entry.template = nextTemplate

		if( ! nextTemplate ) {
			// No known pattern for this country (or the field's country is genuinely blank) —
			// same as picking «Не использовать»: leave the field unmasked, never wipe it.
			destroyImask( entry )

			return
		}

		if( ! IMask ) {
			return // `woodev-imask` failed to load — nothing this file can safely do.
		}

		var options = buildMaskOptions( nextTemplate, resolveTrunkDigit( mode, country, trunkPrefixes ) )

		if( entry.imask ) {
			// Re-groups the customer's ALREADY-TYPED significant digits under the new
			// template automatically — `unmaskedValue` only ever held placeholder-matched
			// digits, never the old template's own literal calling code, so there is no
			// separate "strip the old prefix" step left to do (round 1 needed one; IMask's
			// `unmaskedValue` makes it structurally impossible to need one).
			entry.imask.updateOptions( options )
		} else {
			entry.imask = IMask( live, options )
		}
	}

	/**
	 * Wires the mask onto `#billing_phone` and `#shipping_phone`, each independently
	 * tracking its own country field. A no-op when the merchant left the option off.
	 *
	 * @param {Object} phoneMask {mode, patterns, trunk_prefixes}.
	 * @returns {void}
	 */
	function boot( phoneMask ) {
		if( ! phoneMask || 'off' === phoneMask.mode ) {
			return
		}

		var mode = phoneMask.mode
		var patterns = phoneMask.patterns || {}
		var trunkPrefixes = phoneMask.trunk_prefixes || {}

		var entries = [
			createEntry( 'billing_phone', 'billing_country' ),
			createEntry( 'shipping_phone', 'shipping_country' ),
		]

		function reconcileAll() {
			entries.forEach( function( entry ) {
				reconcileField( entry, mode, patterns, trunkPrefixes )
			} )
		}

		reconcileAll()

		/**
		 * Binds an event on `document.body`, jQuery-preferred with a native fallback for
		 * testability — mirrors `location-cascade.js`'s own `bindCheckoutUpdatedWatcher()`
		 * exactly: both `updated_checkout` and `country_to_state_changed` are jQuery CUSTOM
		 * events in production (WooCommerce fires them via `$(document.body).trigger(...)`,
		 * which never calls `dispatchEvent()` for a non-native event type), so only a
		 * through-jQuery binding ever sees a real WooCommerce fire.
		 *
		 * @param {string} eventName
		 * @returns {void}
		 */
		function bind( eventName ) {
			if( window.jQuery ) {
				window.jQuery( document.body ).on( eventName, reconcileAll )

				return
			}

			document.body.addEventListener( eventName, reconcileAll )
		}

		// A fragment replace can happen under ANY mode — always re-attach.
		bind( 'updated_checkout' )

		// A country change only ever matters in 'auto' mode — a fixed mode's template never
		// depends on the checkout's own country selection.
		if( 'auto' === mode ) {
			bind( 'country_to_state_changed' )
		}
	}

	$( function() {
		boot( findConfig() )
	} )

	// -------------------------------------------------------------------------
	// UMD-ish dual export (jest)
	// -------------------------------------------------------------------------

	var api = {
		resolvePattern: resolvePattern,
		resolveTrunkDigit: resolveTrunkDigit,
		toImaskPattern: toImaskPattern,
		buildMaskOptions: buildMaskOptions,
		reconcileField: reconcileField,
		createEntry: createEntry,
		boot: boot,
	}

	if( typeof module !== 'undefined' && module.exports ) {
		module.exports = api
	}

}( jQuery, ( 'undefined' !== typeof window ? window.IMask : undefined ) ) )
