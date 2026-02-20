<?php

defined( 'ABSPATH' ) or exit;

if ( ! class_exists( 'Woodev_License_Messages' ) ) :

	/**
	 * Messages for license key activation results.
	 *
	 * @since 1.2.1
	 */
	class Woodev_License_Messages {

		/**
		 * The instance of Woodev_License class.
		 *
		 * @var Woodev_License
		 */
		private $license_data;

		/**
		 * The license expiration as a timestamp, or false if no expiration.
		 *
		 * @var bool|int
		 */
		private $expiration = false;

		/**
		 * The current timestamp.
		 *
		 * @var int
		 */
		private $now;

		public function __construct( Woodev_License $license ) {

			$this->license_data = $license;
			$this->now          = current_time( 'timestamp' );

			if ( ! empty( $this->license_data->expires ) && $this->license_data->expires !== 'lifetime' ) {
				if ( ! is_numeric( $this->license_data->expires ) ) {
					$this->expiration = strtotime( $this->license_data->expires, $this->now );
				} else {
					$this->expiration = $this->license_data->expires;
				}
			}
		}

		/**
		 * Gets the appropriate licensing message from an array of license data.
		 *
		 * @return string
		 */
		public function get_message() {
			return $this->build_message();
		}

		/**
		 * Gets the plugin name. If the name is not existing, returns just 'plugin' string
		 *
		 * @return string
		 */
		private function get_plugin_name() {
			return $this->license_data->item_name ?: __( 'plugin', 'woodev-plugin-framework' );
		}

		/**
		 * Retrieves a localized
		 *
		 * @param int $timestamp Timestamp. Can either be based on UTC or WP settings
		 *
		 * @return string The formatted date, translated if locale specifies it.
		 */
		private function get_date_i18n( $timestamp ) {
			if ( is_numeric( $timestamp ) ) {
				$timestamp = date_i18n( wc_date_format(), $timestamp );
			}

			return wc_format_datetime( wc_string_to_datetime( $timestamp ) );
		}

		/**
		 * Given a URL, run it through query arg additions.
		 *
		 * @param string $base_url   The base URL for the generation.
		 * @param array  $query_args The arguments to add to the $base_url.
		 *
		 * @return string.
		 */
		private function get_link_helper( $base_url = 'https://woodev.ru', $query_args = array() ) {

			$args = wp_parse_args( $query_args, array(
				'utm_source'   => str_replace( '.', '_', wp_parse_url( home_url(), PHP_URL_HOST ) ),
				'utm_medium'   => is_admin() ? Woodev_Helper::get_current_screen()->id : 'organic',
				'utm_content'  => $this->license_data->license,
				'utm_campaign' => 'woodev',
			) );

			// Ensure we sanitize the medium and content.
			$args['utm_medium']  = str_replace( '_', '-', sanitize_title( $args['utm_medium'] ) );
			$args['utm_content'] = str_replace( '_', '-', sanitize_title( $args['utm_content'] ) );

			$url = add_query_arg( $args, trailingslashit( $base_url ) );

			return esc_url( $url );
		}

		private function get_renewal_link() {
			return $this->get_link_helper( 'https://woodev.ru/checkout/', array(
				'utm_medium'      => 'license-notice',
				'edd_license_key' => $this->license_data->get_license_key(),
				'download_id'     => $this->license_data->item_id
			) );
		}

		/**
		 * Builds the message based on the license data.
		 *
		 * @return string
		 */
		private function build_message() {

			switch ( $this->license_data->license ) {

				case 'expired':
					$message = $this->get_expired_message();
					break;

				case 'revoked':
				case 'disabled':
					$message = $this->get_disabled_message();
					break;

				case 'missing':
					$message = $this->get_missing_message();
					break;

				case 'site_inactive':
					$message = $this->get_inactive_message();
					break;

				case 'invalid':
				case 'invalid_item_id':
				case 'item_name_mismatch':
				case 'key_mismatch':
					$message = sprintf( __( 'This appears to be an invalid license key for %s.', 'woodev-plugin-framework' ), $this->get_plugin_name() );
					break;

				case 'no_activations_left':
					$message = $this->get_no_activations_message();
					break;

				case 'license_not_activable':
					$message = __( 'The key you entered belongs to a bundle, please use the product specific license key.', 'woodev-plugin-framework' );
					break;

				case 'deactivated':
					$message = __( 'Your license key has been deactivated.', 'woodev-plugin-framework' );
					break;

				case 'valid':
					$message = $this->get_valid_message();
					break;

				default:
					$message = __( 'Unlicensed: currently not receiving updates.', 'woodev-plugin-framework' );
					break;
			}

			return $message;
		}

		/**
		 * Gets the message text for a valid license.
		 *
		 * @return string
		 */
		private function get_valid_message() {

			if ( ! empty( $this->license_data->expires ) && 'lifetime' === $this->license_data->expires ) {
				return __( 'License key never expires.', 'woodev-plugin-framework' );
			}

			if ( ( $this->expiration > $this->now ) && ( ( $this->expiration - $this->now ) < MONTH_IN_SECONDS ) ) {
				return sprintf(
					__( 'Your license key expires soon! It expires on %1$s. %2$sRenew your key%3$s before it expires.', 'woodev-plugin-framework' ),
					$this->get_date_i18n( $this->expiration ),
					'<a href="' . $this->get_renewal_link() . '" target="_blank">',
					'</a>'
				);
			}

			return sprintf( __( 'Your license key expires on %s.', 'woodev-plugin-framework' ), $this->get_date_i18n( $this->expiration ) );
		}

		/**
		 * Gets the message for an expired license.
		 *
		 * @return string
		 */
		private function get_expired_message() {

			if ( $this->expiration ) {
				return sprintf(
				/* translators: 1. license expiration date; 2. opening link tag; 3. closing link tag. */
					__( 'Your license key expired on %1$s. Please %2$srenew your license key%3$s.', 'woodev-plugin-framework' ),
					$this->get_date_i18n( $this->expiration ),
					'<a href="' . $this->get_renewal_link() . '" target="_blank">',
					'</a>'
				);
			}

			return sprintf(
			/* translators: 1. opening link tag; 2. closing link tag. */
				__( 'Your license key has expired. Please %1$srenew your license key%2$s.', 'woodev-plugin-framework' ),
				'<a href="' . $this->get_renewal_link() . '" target="_blank">',
				'</a>'
			);
		}

		/**
		 * Gets the message for a disabled license.
		 *
		 * @return string
		 */
		private function get_disabled_message() {

			$url = $this->get_link_helper( 'https://woodev.ru/support/', array(
				'utm_medium' => 'license-notice',
				'wpf4766_3'  => urlencode( 'Проблемы с лицензией' ),
				'wpf4766_5'  => $this->license_data->item_id,
				'wpf4766_7'  => site_url(),
				'wpf4766-6'  => $this->license_data->get_license_key()
			) );

			return sprintf(
			/* translators: 1. opening link tag; 2. closing link tag. */
				__( 'Your license key has been disabled. Please %1$scontact support%2$s for more information.', 'woodev-plugin-framework' ),
				'<a href="' . $url . '" target="_blank">',
				'</a>'
			);
		}

		/**
		 * Gets the message for a license at its activation limit.
		 *
		 * @return string
		 */
		private function get_no_activations_message() {

			$url = $this->get_link_helper( 'https://woodev.ru/my-account/', array(
				'utm_medium' => 'license-notice',
				'action'     => 'manage_licenses',
				'payment_id' => $this->license_data->payment_id,
				'view'       => 'upgrades'
			) );

			return sprintf(
			/* translators: 1. opening link tag; 2 closing link tag. */
				__( 'Your license key has reached its activation limit. %1$sView possible upgrades%2$s now.', 'woodev-plugin-framework' ),
				'<a href="' . $url . '">',
				'</a>'
			);
		}

		/**
		 * Gets the message for an inactive license.
		 *
		 * @return string
		 */
		private function get_inactive_message() {

			$url = $this->get_link_helper( 'https://woodev.ru/my-account/', array(
				'utm_medium' => 'license-notice',
				'action'     => 'manage_licenses',
				'payment_id' => $this->license_data->payment_id
			) );

			return sprintf(
			/* translators: 1. the plugin name; 2. opening link tag; 3. closing link tag. */
				__( 'Your %1$s license key is not active for this URL. Please %2$svisit your account page%3$s to manage your license keys.', 'woodev-plugin-framework' ),
				esc_html( $this->get_plugin_name() ),
				'<a href="' . $url . '" target="_blank">',
				'</a>'
			);
		}

		/**
		 * Gets the message for a missing license.
		 *
		 * @return string
		 */
		private function get_missing_message() {
			return sprintf(
			/* translators: 1. opening link tag; 2. closing link tag. */
				__( 'Invalid license. Please %1$svisit your account page%2$s and verify it.', 'woodev-plugin-framework' ),
				'<a href="' . $this->get_link_helper( 'https://woodev.ru/my-account/' ) . '" target="_blank">',
				'</a>'
			);
		}
	}

endif;