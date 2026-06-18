<?php

defined( 'ABSPATH' ) || exit;

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
					$this->expiration = (int) $this->license_data->expires;
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
			return $this->license_data->item_name ?: __( 'плагина', 'woodev-plugin-framework' );
		}

		/**
		 * Retrieves a localized
		 *
		 * @param int $timestamp Timestamp. Can either be based on UTC or WP settings
		 *
		 * @return string The formatted date, translated if locale specifies it.
		 */
		private function get_date_i18n( $timestamp ) {
			if ( function_exists( 'wc_string_to_datetime' ) && function_exists( 'wc_format_datetime' ) ) {
				if ( is_numeric( $timestamp ) ) {
					$timestamp = date_i18n( $this->get_date_format(), (int) $timestamp );
				}

				return wc_format_datetime( wc_string_to_datetime( $timestamp ), $this->get_date_format() );
			}

			$raw_timestamp = $timestamp;
			$timestamp     = $this->get_timestamp_for_site_timezone( $timestamp );

			if ( false === $timestamp ) {
				return (string) $raw_timestamp;
			}

			return $this->format_timestamp_i18n( $timestamp );
		}

		/**
		 * Gets the WooCommerce-compatible date format without requiring WooCommerce helpers.
		 *
		 * @return string
		 */
		private function get_date_format() {

			if ( function_exists( 'wc_date_format' ) ) {
				return wc_date_format();
			}

			$date_format = get_option( 'date_format' );

			if ( empty( $date_format ) ) {
				$date_format = 'F j, Y';
			}

			return apply_filters( 'woocommerce_date_format', $date_format );
		}

		/**
		 * Gets a timestamp for a date value using WordPress timezone semantics.
		 *
		 * @param int|string $timestamp Timestamp or parseable date string.
		 * @return int|false
		 */
		private function get_timestamp_for_site_timezone( $timestamp ) {

			if ( is_numeric( $timestamp ) ) {
				return (int) $timestamp;
			}

			$timestamp = (string) $timestamp;

			if ( '' === $timestamp ) {
				return false;
			}

			try {
				$datetime = $this->has_explicit_timezone( $timestamp )
					? new DateTimeImmutable( $timestamp )
					: new DateTimeImmutable( $timestamp, $this->get_site_timezone() );
			} catch ( Exception $exception ) {
				return false;
			}

			return $datetime->getTimestamp();
		}

		/**
		 * Determines whether a date string carries an explicit timezone.
		 *
		 * @param string $timestamp Date string.
		 * @return bool
		 */
		private function has_explicit_timezone( $timestamp ) {
			return 1 === preg_match( '/(?:Z|[+-]\d{2}:?\d{2})$/', $timestamp );
		}

		/**
		 * Formats a timestamp for the WordPress site timezone.
		 *
		 * @param int $timestamp Timestamp.
		 * @return string
		 */
		private function format_timestamp_i18n( $timestamp ) {
			$date_format = $this->get_date_format();

			if ( function_exists( 'wp_date' ) ) {
				return wp_date( $date_format, $timestamp, $this->get_site_timezone() );
			}

			return date_i18n( $date_format, $timestamp );
		}

		/**
		 * Gets the WordPress site timezone.
		 *
		 * @return DateTimeZone
		 */
		private function get_site_timezone() {

			if ( function_exists( 'wp_timezone' ) ) {
				return wp_timezone();
			}

			$timezone_string = get_option( 'timezone_string' );

			if ( ! empty( $timezone_string ) ) {
				return new DateTimeZone( $timezone_string );
			}

			$offset  = (float) get_option( 'gmt_offset', 0 );
			$sign    = $offset < 0 ? '-' : '+';
			$hours   = (int) abs( $offset );
			$minutes = (int) round( ( abs( $offset ) - $hours ) * 60 );

			return new DateTimeZone( sprintf( '%s%02d:%02d', $sign, $hours, $minutes ) );
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

			$args = wp_parse_args(
				$query_args,
				array(
					'utm_source'   => str_replace( '.', '_', wp_parse_url( home_url(), PHP_URL_HOST ) ),
					'utm_medium'   => is_admin() ? Woodev_Helper::get_current_screen()->id : 'organic',
					'utm_content'  => $this->license_data->license,
					'utm_campaign' => 'woodev',
				)
			);

			// Ensure we sanitize the medium and content.
			$args['utm_medium']  = str_replace( '_', '-', sanitize_title( $args['utm_medium'] ) );
			$args['utm_content'] = str_replace( '_', '-', sanitize_title( $args['utm_content'] ) );

			$url = add_query_arg( $args, trailingslashit( $base_url ) );

			return esc_url( $url );
		}

		/**
		 * Public renewal-checkout URL for the current license.
		 *
		 * Single source of truth for the «Продлить» button in the license page UI
		 * and for the renewal CTAs embedded in the status messages.
		 *
		 * @since 2.0.2
		 *
		 * @return string The checkout URL with edd_license_key + download_id.
		 */
		public function get_renewal_url() {
			return $this->get_renewal_link();
		}

		private function get_renewal_link() {
			return $this->get_link_helper(
				'https://woodev.ru/checkout/',
				array(
					'utm_medium'      => 'license-notice',
					'edd_license_key' => $this->license_data->get_license_key(),
					'download_id'     => $this->license_data->item_id,
				)
			);
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
				case 'missing_url':
					$message = sprintf( __( 'Похоже, это неверный лицензионный ключ для %s.', 'woodev-plugin-framework' ), $this->get_plugin_name() );
					break;

				case 'no_activations_left':
					$message = $this->get_no_activations_message();
					break;

				case 'license_not_activable':
					$message = __( 'Введённый ключ относится к комплекту. Используйте ключ конкретного товара.', 'woodev-plugin-framework' );
					break;

				case 'deactivated':
					$message = __( 'Лицензионный ключ деактивирован.', 'woodev-plugin-framework' );
					break;

				case 'valid':
					$message = $this->get_valid_message();
					break;

				default:
					$message = __( 'Без лицензии: обновления сейчас не поступают.', 'woodev-plugin-framework' );
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
				return __( 'Бессрочный лицензионный ключ.', 'woodev-plugin-framework' );
			}

			if ( ( $this->expiration > $this->now ) && ( ( $this->expiration - $this->now ) < MONTH_IN_SECONDS ) ) {
				return sprintf(
					__( 'Срок действия ключа скоро истекает — %1$s. %2$sПродлите ключ%3$s заранее.', 'woodev-plugin-framework' ),
					$this->get_date_i18n( $this->expiration ),
					'<a href="' . $this->get_renewal_link() . '" target="_blank">',
					'</a>'
				);
			}

			return sprintf( __( 'Срок действия ключа — до %s.', 'woodev-plugin-framework' ), $this->get_date_i18n( $this->expiration ) );
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
					__( 'Срок действия ключа истёк %1$s. %2$sПродлите лицензионный ключ%3$s.', 'woodev-plugin-framework' ),
					$this->get_date_i18n( $this->expiration ),
					'<a href="' . $this->get_renewal_link() . '" target="_blank">',
					'</a>'
				);
			}

			return sprintf(
			/* translators: 1. opening link tag; 2. closing link tag. */
				__( 'Срок действия лицензионного ключа истёк. %1$sПродлите ключ%2$s.', 'woodev-plugin-framework' ),
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

			$url = $this->get_link_helper(
				'https://woodev.ru/support/',
				array(
					'utm_medium' => 'license-notice',
					'wpf4766_3'  => urlencode( 'Проблемы с лицензией' ),
					'wpf4766_5'  => $this->license_data->item_id,
					'wpf4766_7'  => site_url(),
					'wpf4766-6'  => $this->license_data->get_license_key(),
				)
			);

			return sprintf(
			/* translators: 1. opening link tag; 2. closing link tag. */
				__( 'Лицензионный ключ отключён. %1$sОбратитесь в поддержку%2$s за подробностями.', 'woodev-plugin-framework' ),
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

			$url = $this->get_link_helper(
				'https://woodev.ru/my-account/',
				array(
					'utm_medium' => 'license-notice',
					'action'     => 'manage_licenses',
					'payment_id' => $this->license_data->payment_id,
					'view'       => 'upgrades',
				)
			);

			return sprintf(
			/* translators: 1. opening link tag; 2 closing link tag. */
				__( 'Достигнут лимит активаций ключа. %1$sПосмотрите варианты расширения%2$s.', 'woodev-plugin-framework' ),
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

			$url = $this->get_link_helper(
				'https://woodev.ru/my-account/',
				array(
					'utm_medium' => 'license-notice',
					'action'     => 'manage_licenses',
					'payment_id' => $this->license_data->payment_id,
				)
			);

			return sprintf(
			/* translators: 1. the plugin name; 2. opening link tag; 3. closing link tag. */
				__( 'Ключ %1$s не активирован для этого адреса. %2$sПерейдите в личный кабинет%3$s, чтобы управлять ключами.', 'woodev-plugin-framework' ),
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
				__( 'Лицензия недействительна. %1$sПерейдите в личный кабинет%2$s и проверьте её.', 'woodev-plugin-framework' ),
				'<a href="' . $this->get_link_helper( 'https://woodev.ru/my-account/' ) . '" target="_blank">',
				'</a>'
			);
		}
	}

endif;
