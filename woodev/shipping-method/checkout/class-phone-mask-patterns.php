<?php
/**
 * Woodev Phone Mask Patterns
 *
 * The country → mask template table {@see Checkout_Field_Settings}'s
 * `phone_field_format` option and `phone-mask.js` both read from — a single
 * table shared by the PHP side (building the option list, deciding which
 * shipping countries have a known mask) and the JS side (formatting the
 * `#billing_phone` input), so the two never drift apart.
 *
 * A template string uses `#` as a digit placeholder; every other character is
 * literal and rendered verbatim (spaces, parentheses, dashes, the leading
 * `+<calling code>`). `phone-mask.js`'s `formatPhone()` is the sole reader of
 * this shape — see that file's own docblock for the formatting algorithm.
 *
 * RU/CIS-first (card #503): the table is deliberately small and is the
 * extension point for the rest — a plugin adds a country via the
 * {@see self::FILTER_PATTERNS} filter rather than this class growing without
 * bound.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Checkout\\Phone_Mask_Patterns' ) ) :

	/**
	 * Country → phone mask template table.
	 *
	 * @since 2.0.2
	 */
	final class Phone_Mask_Patterns {

		/**
		 * Filter tag a plugin uses to add or override a country's mask template.
		 *
		 * @since 2.0.2
		 */
		public const FILTER_PATTERNS = 'woodev_phone_mask_patterns';

		/**
		 * Filter tag a plugin uses to add or override a country's national trunk-prefix
		 * digit (card #503 round 2, the IMask rewrite) — {@see self::get_trunk_prefixes()}.
		 *
		 * @since 2.0.2
		 */
		public const FILTER_TRUNK_PREFIXES = 'woodev_phone_mask_trunk_prefixes';

		/**
		 * Gets the country → mask template map, RU/CIS first, filtered.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string,string> ISO-3166 alpha-2 country code => `#`-placeholder template.
		 */
		public static function get(): array {
			$patterns = [
				// BEGIN GENERATED — bin/generate-phone-masks.mjs
				'RU' => '+7 (###) ###-##-##',
				'KZ' => '+7 (###) ###-##-##',
				'BY' => '+375 ## ### ## ##',
				'UA' => '+380 ## ### ####',
				'AM' => '+374 ## ######',
				'AZ' => '+994 ## ### ## ##',
				'GE' => '+995 ### ## ## ##',
				'KG' => '+996 ### ### ###',
				'TJ' => '+992 ## ### ####',
				'TM' => '+993 ## ######',
				'UZ' => '+998 ## ### ## ##',
				'MD' => '+373 ### ## ###',
				// END GENERATED
			];

			/**
			 * Filters the country → phone mask template table.
			 *
			 * @since 2.0.2
			 *
			 * @param array<string,string> $patterns ISO-3166 alpha-2 country code => `#`-placeholder template.
			 */
			return (array) apply_filters( self::FILTER_PATTERNS, $patterns );
		}

		/**
		 * Gets the country → national trunk-prefix-digit map, filtered.
		 *
		 * card #503 round 2 (IMask rewrite): RU/KZ dial a domestic number with a leading
		 * `8` in place of the `+7` calling code (`8 929 600-80-90` ⇔ `+7 929 600-80-90`) —
		 * a numbering-plan convention, not something every country shares, so it lives in
		 * its OWN small declarative table next to {@see self::get()} rather than being
		 * assumed for every pattern entry. `phone-mask.js`'s `resolveTrunkDigit()` reads
		 * this to swap a leading trunk digit for the active template's own calling code the
		 * moment it is typed or pasted as (or at the start of) the very first character —
		 * see that file's own docblock, TRUNK PREFIX section.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string,string> ISO-3166 alpha-2 country code => single trunk digit.
		 */
		public static function get_trunk_prefixes(): array {
			$trunk_prefixes = [
				'RU' => '8',
				'KZ' => '8',
			];

			/**
			 * Filters the country → national trunk-prefix-digit map.
			 *
			 * @since 2.0.2
			 *
			 * @param array<string,string> $trunk_prefixes ISO-3166 alpha-2 country code => single trunk digit.
			 */
			return (array) apply_filters( self::FILTER_TRUNK_PREFIXES, $trunk_prefixes );
		}
	}

endif;
