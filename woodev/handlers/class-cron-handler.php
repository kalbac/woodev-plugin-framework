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
		 * Guards the weekly nonce-store prune so it runs once per request.
		 *
		 * Every active framework plugin registers its own Cron_Handler and hooks the
		 * shared `woodev_weekly_scheduled_events` event, but the per-nonce option store
		 * is site-global — N instances must prune ONCE, not N times.
		 *
		 * @since 2.0.0
		 *
		 * @var bool
		 */
		private static bool $nonces_pruned = false;

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
			// Piggyback the scheduled prune on the EXISTING weekly event (no new cron
			// hook = no new cron contract — §9.2). The hook name/recurrence/payload are
			// untouched; this is an added listener only.
			add_action( 'woodev_weekly_scheduled_events', array( $this, 'prune_license_command_nonces' ) );
			add_action( 'wp_ajax_woodev_verify_license', array( $this, 'ajax_verify_license' ) );
		}

		/**
		 * Prunes expired signed-command nonce rows on the weekly cron (§9.2).
		 *
		 * Static once-per-request guard so N plugin instances prune exactly once. The
		 * class_exists guard keeps a mixed-version fleet (a plugin loaded by an older
		 * framework copy that lacks the store) from fataling.
		 *
		 * @internal
		 *
		 * @since 2.0.0
		 *
		 * @return void
		 */
		public function prune_license_command_nonces(): void {

			if ( self::$nonces_pruned ) {
				return;
			}

			self::$nonces_pruned = true;

			if ( ! class_exists( '\Woodev_License_Command_Nonce_Store' ) ) {
				return;
			}

			( new \Woodev_License_Command_Nonce_Store() )->prune();
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
