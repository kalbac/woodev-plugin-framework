<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_License' ) ) :

	class Woodev_License {
		/**
		 * The license status.
		 *
		 * @var string
		 */
		public $license = '';

		/**
		 * The product ID.
		 *
		 * @var int
		 */
		public $item_id;

		/**
		 * The product name.
		 *
		 * @var string
		 */
		public $item_name;

		/**
		 * The license activation limit.
		 *
		 * @var int
		 */
		public $license_limit;

		/**
		 * The number of sites on which this license is active.
		 *
		 * @var int
		 */
		public $site_count = 0;

		/**
		 * The license expiration date.
		 *
		 * @var string
		 */
		public $expires;

		/**
		 * The number of activations left.
		 *
		 * @var int
		 */
		public $activations_left;

		/**
		 * The order ID for the license.
		 *
		 * @var int
		 */
		public $payment_id;

		/**
		 * The product price ID.
		 *
		 * @var false|int
		 */
		public $price_id = false;

		/**
		 * The error code for a license.
		 *
		 * @var string
		 */
		public $error;

		/**
		 * The license key.
		 *
		 * @var string
		 */
		public $key = '';

		/**
		 * Whether the API request was successful.
		 *
		 * @var bool
		 */
		public $success = false;

		/**
		 * The subscription status.
		 *
		 * @var string
		 */
		public $subscription;

		/**
		 * The additional license data.
		 *
		 * @var object|string
		 */
		public $data;

		/**
		 * The option name for the license data.
		 * It must be like "woodev_{$plugin_underscored_id}_license"
		 *
		 * @var string
		 */
		private $option_name;

		/**
		 * The plugin underscored ID
		 *
		 * @var string
		 */
		private $plugin_id;

		/**
		 * The class constructor.
		 *
		 * @param string $plugin_id The plugin ID (underscored).
		 */
		public function __construct( $plugin_id ) {

			$this->plugin_id = str_replace( '-', '_', $plugin_id );

			if ( ! Woodev_Helper::str_starts_with( $this->plugin_id, 'woodev_' ) ) {
				$this->plugin_id = 'woodev_' . $this->plugin_id;
			}

			$this->option_name = sprintf( '%s_license', $this->plugin_id );
			$this->get();
		}

		/**
		 * Saves the license data option.
		 *
		 * @param object $license_data
		 *
		 * @return bool
		 */
		public function save( $license_data ) {

			$updated = update_option(
				$this->option_name,
				$license_data,
				false
			);

			$this->get();

			/**
			 * Fires after a license is saved.
			 *
			 * @param Woodev_License $license      The license object.
			 * @param object         $license_data The license data.
			 *
			 * @since 1.2.1
			 */
			do_action( 'woodev_license_saved', $this, $license_data );

			return $updated;
		}

		/**
		 * Deletes a license key and related license data.
		 *
		 * @return void
		 */
		public function delete() {

			delete_option( $this->option_name );

			$this->get();

			/**
			 * Fires after a license is deleted.
			 *
			 * @param string         $plugin_id The plugin underscored ID
			 * @param Woodev_License $license   The license object.
			 *
			 * @since 1.2.1
			 */
			do_action( 'woodev_license_deleted', $this->plugin_id, $this );
		}

		/**
		 * Selectively update just one piece of the license data.
		 *
		 * @param array $data
		 *
		 * @return bool
		 */
		public function update( array $data ) {

			/** @var stdClass $option */
			$option = get_option( $this->option_name, new StdClass() );
			$update = false;

			foreach ( $data as $key => $value ) {
				if ( ( ! isset( $option->$key ) || $value !== $option->$key ) && in_array( $key, $this->get_editable_keys(), true ) ) {
					$option->$key = $value;
					$update       = true;
				}
			}

			return $update && $this->save( $option );
		}

		/**
		 * Gets the license key for the plugin.
		 *
		 * @return string
		 */
		public function get_license_key() {
			return get_option( sprintf( '%s_license_key', $this->plugin_id ), '' );
		}

		/**
		 * Gets the license object mapped to the class defaults.
		 *
		 * @return Woodev_License
		 */
		public function get() {

			$this->key = $this->get_license_key();

			if ( empty( $this->key ) ) {
				return $this;
			}

			$option = get_option( $this->option_name, false );

			if ( ! $option ) {
				return $this;
			}

			$allowed_keys = [
				'license',
				'item_id',
				'item_name',
				'license_limit',
				'site_count',
				'expires',
				'activations_left',
				'payment_id',
				'price_id',
				'error',
				'key',
				'success',
				'subscription',
				'data',
			];

			foreach ( (array) $option as $key => $value ) {
				if ( in_array( $key, $allowed_keys, true ) ) {
					$this->$key = $value;
				}
			}

			if ( ! $this->success && is_null( $this->error ) && 'valid' !== $this->license ) {
				$this->error = $this->license;
			}

			return $this;
		}

		/**
		 * Presentation-only effective status.
		 *
		 * EDD reports the precise failure reason in `error` while leaving `license`
		 * at the generic 'invalid' (or empty) on activation failures — e.g. activating
		 * at the activation limit yields license='invalid' + error='no_activations_left'.
		 * This returns the more specific `error` token in that case so the status label,
		 * message, badge and card grouping reflect the real reason instead of a generic
		 * "invalid key".
		 *
		 * READ-ONLY: it never mutates `license`, so enforcement (is_active()/is_invalid()/
		 * has_status(), which key off the raw `license`) is unchanged — the anti-pirate
		 * invariant is preserved.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_display_status(): string {

			$license = (string) $this->license;
			$error   = (string) $this->error;

			// Surface the `error` token over a generic '' / 'invalid' license ONLY when it
			// is a machine status code (e.g. `no_activations_left`, `site_inactive`). Some
			// stores put a localized free-text sentence in `error` for a plain bad key
			// (observed: 'Неверно указан лицензионный ключ.'); that must NOT become the
			// status or the presentation can't classify it (falls through to the «unknown»
			// fallback and the user gets stranded). A free-text error keeps `license`, so a
			// bad key resolves to 'invalid' → editable «bad-key» group + the right message.
			if ( '' !== $error && in_array( $license, array( '', 'invalid' ), true ) && 1 === preg_match( '/^[a-z][a-z0-9_]*$/', $error ) ) {
				return $error;
			}

			return $license;
		}

		/**
		 * Only allow certain keys to be modified.
		 *
		 * @return array
		 */
		private function get_editable_keys() {
			return array( 'license', 'error', 'success', 'expires' );
		}
	}

endif;
