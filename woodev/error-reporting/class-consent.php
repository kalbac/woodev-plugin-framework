<?php
/**
 * Error-reporting consent and receiver configuration.
 *
 * @package Woodev\Framework\Error_Reporting
 */

namespace Woodev\Framework\Error_Reporting;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\Woodev\Framework\Error_Reporting\Consent' ) ) :

	/**
	 * The single place that answers «may this site send error reports, and where to?».
	 *
	 * Two independent switches, both required: the merchant's consent (a site-wide option,
	 * default OFF) and a receiver DSN (a constant or a filter — there is deliberately no
	 * built-in default, so a framework without a configured receiver sends nothing).
	 *
	 * @since 2.0.2
	 */
	final class Consent {

		/** Site-wide consent option. `yes` | `no`; absent means `no`. */
		const OPTION = 'woodev_error_reporting_enabled';

		/** wp-config constant carrying the receiver DSN. */
		const DSN_CONSTANT = 'WOODEV_ERROR_REPORTING_DSN';

		/**
		 * Whether the merchant ticked the checkbox.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public static function is_enabled(): bool {
			return 'yes' === get_option( self::OPTION, 'no' );
		}

		/**
		 * Stores the merchant's choice.
		 *
		 * @since 2.0.2
		 *
		 * @param bool $enabled Whether reports may be sent.
		 * @return void
		 */
		public static function set_enabled( bool $enabled ): void {
			update_option( self::OPTION, $enabled ? 'yes' : 'no' );
		}

		/**
		 * The configured receiver, if any.
		 *
		 * @since 2.0.2
		 *
		 * @return Dsn|null
		 */
		public static function get_dsn(): ?Dsn {
			$raw = defined( self::DSN_CONSTANT ) ? constant( self::DSN_CONSTANT ) : '';

			/**
			 * Filters the error-reporting receiver DSN.
			 *
			 * @since 2.0.2
			 *
			 * @param string $dsn Sentry/GlitchTip DSN; empty string disables the reporter.
			 */
			$raw = apply_filters( 'woodev_error_reporting_dsn', $raw );

			return is_string( $raw ) ? Dsn::parse( $raw ) : null;
		}

		/**
		 * Whether a receiver is configured (the checkbox is only offered when it is).
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public static function is_available(): bool {
			return null !== self::get_dsn();
		}

		/**
		 * Whether reports may leave this site right now: receiver configured AND consent given.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public static function is_active(): bool {
			return self::is_available() && self::is_enabled();
		}

		/**
		 * The state handed to the license-page app and returned by the REST controller.
		 *
		 * @since 2.0.2
		 *
		 * @return array{enabled: bool, available: bool}
		 */
		public static function get_state(): array {
			return [
				'enabled'   => self::is_enabled(),
				'available' => self::is_available(),
			];
		}
	}

endif;
