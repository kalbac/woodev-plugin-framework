<?php
/**
 * Cron handler.
 *
 * @package Woodev\Framework\Handlers
 */

namespace Woodev\Framework\Handlers;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\Woodev\Framework\Handlers\Cron_Handler' ) ) :
	/**
	 * Owns the framework's WP-Cron concern.
	 *
	 * This handler registers the `weekly` cron schedule, schedules the
	 * `woodev_weekly_scheduled_events` event, runs the weekly license check on
	 * that event, and serves the `wp_ajax_woodev_verify_license` AJAX action.
	 *
	 * The schedule key, event hook name, AJAX action name and `cron_schedules`
	 * filter are preserved byte-for-byte from Woodev_Plugin to keep the
	 * installed-site contract stable (sites already have the weekly event
	 * scheduled under the existing hook name).
	 *
	 * @since 2.0.0
	 */
	class Cron_Handler {

		/** @var \Woodev_Plugin current plugin instance */
		private \Woodev_Plugin $plugin;

		/**
		 * Cron handler constructor.
		 *
		 * @since 2.0.0
		 *
		 * @param \Woodev_Plugin $plugin the plugin instance
		 */
		public function __construct( \Woodev_Plugin $plugin ) {

			$this->plugin = $plugin;

			// CRON actions
			add_filter( 'cron_schedules', array( $this, 'add_schedules' ) );
			add_action( 'wp', array( $this, 'schedule_events' ) );
			add_action( 'woodev_weekly_scheduled_events', array( $this, 'weekly_license_check' ) );
			add_action( 'wp_ajax_woodev_verify_license', array( $this, 'ajax_verify_license' ) );
		}

		/**
		 * Registers new cron schedules
		 *
		 * @since 2.0.0
		 *
		 * @param array $schedules
		 *
		 * @return array
		 */
		public function add_schedules( $schedules = array() ) {

			if ( ! isset( $schedules['weekly'] ) ) {
				$schedules['weekly'] = array(
					'interval' => WEEK_IN_SECONDS,
					'display'  => __( 'Once Weekly', 'woodev-plugin-framework' ),
				);
			}

			return $schedules;
		}

		/**
		 * Schedule weekly events
		 *
		 * @since 2.0.0
		 *
		 * @return void
		 */
		public function schedule_events() {
			if ( ! wp_next_scheduled( 'woodev_weekly_scheduled_events' ) ) {
				wp_schedule_event( time(), 'weekly', 'woodev_weekly_scheduled_events' );
			}
		}

		/**
		 * Check if license key is valid once per week
		 *
		 * @since 2.0.0
		 *
		 * @return  void
		 */
		public function weekly_license_check() {

			// Don't fire when saving settings.
			if ( ! empty( $_POST['woodev_settings'] ) ) {
				return;
			}

			if ( ! wp_doing_cron() ) {
				return;
			}

			$license_key = $this->plugin->get_license_instance()->get_license();

			if ( empty( $license_key ) ) {
				return;
			}

			try {
				$this->plugin->get_license_instance()->validate_license( $license_key );
			} catch ( \Throwable $e ) {
				// Woodev server unreachable / transport failure: keep last-known-good license state.
				// Never error out and never relock a previously-valid license on a failed check.
				return;
			}
		}

		/**
		 * Verifies the license key over AJAX.
		 *
		 * @since 2.0.0
		 *
		 * @return void
		 */
		public function ajax_verify_license() {
			check_ajax_referer( 'woodev-admin', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error();
			}

			$license = $this->plugin->get_license_instance()->get_license();

			$this->plugin->get_license_instance()->validate_license( $license ?: __return_empty_string(), true, true );
		}
	}

endif;
