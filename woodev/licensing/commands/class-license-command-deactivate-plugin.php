<?php
/**
 * Remote deactivate_plugin command handler (D-W1, §4).
 *
 * This is the ONLY v1 vocabulary entry. It deactivates a single plugin
 * installation, persists a dismissible admin notice so the site admin
 * learns why, fires the public extensibility hook, and logs one line.
 *
 * Power boundary (D-W1): this command NEVER deletes files and NEVER
 * writes license state. `is_license_valid()` / `is_active()` read only
 * the server-signed claim — deactivating the plugin does not change the
 * claim, so the enforcement seam is unaffected.
 *
 * Registration (holistic-round ruling, 2026-06-11 — SEALED): the dispatcher
 * constructs this handler itself inside its private get_commands() builder.
 * There is no runtime registration step and no public mutation API — the
 * vocabulary ships as code.
 *
 * @package Woodev\Framework\Licensing\Commands
 * @since   2.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_License_Command_Deactivate_Plugin' ) ) :

	/**
	 * Handles the 'deactivate_plugin' remote command.
	 *
	 * @since 2.0.0
	 */
	class Woodev_License_Command_Deactivate_Plugin implements Woodev_License_Command {

		/**
		 * Site-level option name for pending remote-deactivation notices.
		 * Autoload 'no' — only read on admin_notices, not on every request.
		 *
		 * @since 2.0.0
		 *
		 * @var string
		 */
		const NOTICES_OPTION = 'woodev_license_remote_deactivation_notices';

		/**
		 * Per-request notice dedup guard. Multiple surviving license instances
		 * call notices() each; only the FIRST call reads the option and renders
		 * (§9.9 notice deduplication, one get_option read per request).
		 *
		 * @since 2.0.0
		 *
		 * @var bool
		 */
		private static $notices_rendered = false;

		/**
		 * Resets the static notice-dedup state for tests.
		 * TEST SEAM ONLY — not called in production code.
		 *
		 * @since 2.0.0
		 *
		 * @return void
		 */
		public static function reset_notice_dedup_for_tests(): void {
			self::$notices_rendered = false;
		}

		/**
		 * Renders pending remote-deactivation notices for a given license engine.
		 *
		 * The static dedup guard runs BEFORE the option read, so a request with N
		 * surviving license instances performs exactly ONE get_option read and
		 * renders each stored entry exactly once — through the FIRST instance's
		 * Woodev_Admin_Notice_Handler (§9.9 notice deduplication). Each entry is
		 * rendered as a dismissible notice with message_id
		 * `woodev_{target_id}_remote_deactivated`.
		 *
		 * Called from Woodev_Plugins_License::notices().
		 *
		 * @since 2.0.0
		 *
		 * @param Woodev_Plugins_License $engine The license engine rendering notices.
		 * @return void
		 */
		public static function render_remote_deactivation_notices( Woodev_Plugins_License $engine ): void {

			// Dedup guard FIRST: one option read + one render pass per request.
			if ( self::$notices_rendered ) {
				return;
			}

			self::$notices_rendered = true;

			$notices = get_option( self::NOTICES_OPTION, array() );

			if ( ! is_array( $notices ) || empty( $notices ) ) {
				return;
			}

			$notice_handler = $engine->get_plugin()->get_admin_notice_handler();

			foreach ( $notices as $target_id => $entry ) {

				if ( ! is_array( $entry ) || empty( $entry['message'] ) ) {
					continue;
				}

				$message_id = 'woodev_' . (string) $target_id . '_remote_deactivated';

				$notice_handler->add_admin_notice(
					(string) $entry['message'],
					$message_id,
					array( 'notice_class' => 'error' )
				);
			}
		}

		/**
		 * Clears all pending remote-deactivation artifacts for a plugin that is
		 * (again) active.
		 *
		 * Called from Woodev_Lifecycle::handle_activation() on a genuine
		 * (re)activation transition (Finding A, s12): a plugin that is running must
		 * not show a stale "you were disabled" banner — or WC Admin inbox note — for
		 * itself. Removes the plugin's own entry from NOTICES_OPTION (deleting the
		 * whole option when it becomes empty) and removes the WC Admin inbox
		 * breadcrumb note when WooCommerce Admin is present. No-op when the plugin
		 * has no pending entry, so it adds no option churn on routine activations.
		 *
		 * @since 2.0.2
		 *
		 * @param Woodev_Plugin $plugin The plugin that has just (re)activated.
		 * @return void
		 */
		public static function clear_remote_deactivation_artifacts( Woodev_Plugin $plugin ): void {

			$plugin_id = (string) $plugin->get_id();

			$notices = get_option( self::NOTICES_OPTION, array() );

			if ( is_array( $notices ) && array_key_exists( $plugin_id, $notices ) ) {

				unset( $notices[ $plugin_id ] );

				if ( empty( $notices ) ) {
					delete_option( self::NOTICES_OPTION );
				} else {
					update_option( self::NOTICES_OPTION, $notices, 'no' );
				}
			}

			// Remove the WC Admin inbox breadcrumb (Finding B), if WooCommerce Admin is
			// present. Guarded on the same Note marker the writer uses (the option path
			// above is platform-neutral and intentionally runs without it), and wrapped
			// so a WooCommerce/DB hiccup while deleting the inbox note can never abort
			// the activation flow that called us — clearing the breadcrumb is best-effort.
			if ( class_exists( '\Automattic\WooCommerce\Admin\Notes\Note' ) ) {
				try {
					\Automattic\WooCommerce\Admin\Notes\Notes::delete_notes_with_name( self::get_breadcrumb_note_name( $plugin ) );
				} catch ( \Throwable $exception ) {
					// Best-effort: the next admin_notices pass / dismissal still resolves it.
					unset( $exception );
				}
			}
		}

		/**
		 * Builds the deterministic WC Admin inbox note name for a plugin's remote
		 * deactivation breadcrumb (Finding B, s12).
		 *
		 * @since 2.0.2
		 *
		 * @param Woodev_Plugin $plugin The target plugin.
		 * @return string
		 */
		private static function get_breadcrumb_note_name( Woodev_Plugin $plugin ): string {
			return 'woodev-' . $plugin->get_id_dasherized() . '-remote-deactivated';
		}

		/**
		 * Whether the WP plugin API needs loading before this command can run.
		 *
		 * Gates on the FULL set of plugin-API functions execute() calls — NOT just
		 * is_plugin_active(). A context can define is_plugin_active() while
		 * is_plugin_active_for_network() / deactivate_plugins() are still missing
		 * (they all live in wp-admin/includes/plugin.php, but partial polyfills
		 * exist in the wild); gating on one function would skip the require and
		 * degrade execution to a retryable 'failed' instead of loading the API.
		 *
		 * @since 2.0.0
		 *
		 * @return bool True when any required plugin-API function is missing.
		 */
		public static function needs_plugin_api(): bool {
			return ! function_exists( 'is_plugin_active' )
				|| ! function_exists( 'is_plugin_active_for_network' )
				|| ! function_exists( 'deactivate_plugins' );
		}

		/**
		 * Returns the command vocabulary name.
		 *
		 * @since 2.0.0
		 *
		 * @return string
		 */
		public function get_name(): string {
			return 'deactivate_plugin';
		}

		/**
		 * Executes the remote deactivation command.
		 *
		 * Decision tree (all paths are terminal):
		 *
		 * 1. require_once plugin.php (not guaranteed in REST context).
		 * 2. Multisite + network-active → 'network_active_unsupported', NO action.
		 * 3. Already inactive → 'already', NO deactivate_plugins call.
		 * 4. Active → deactivate_plugins(), then: notice + hook + log → 'executed'.
		 *
		 * ANTI-PIRATE INVARIANT: this method NEVER writes a license state option.
		 * The only persistent write on the 'executed' path is the
		 * `woodev_license_remote_deactivation_notices` option (autoload 'no').
		 *
		 * @since 2.0.0
		 *
		 * @param Woodev_Plugins_License $target  The resolved target license engine.
		 * @param array<string, mixed>   $payload The verified signed payload.
		 * @return string Terminal ack status: 'executed' | 'already' | 'network_active_unsupported'.
		 *
		 * @throws \Throwable On unrecoverable execution failure (dispatcher wraps → 'failed').
		 */
		public function execute( Woodev_Plugins_License $target, array $payload ): string {

			$plugin      = $target->get_plugin();
			$plugin_id   = (string) $plugin->get_id();
			$plugin_file = (string) $plugin->get_plugin_file();

			// Step 1: load the WP plugin API (NOT guaranteed in REST context).
			// needs_plugin_api() gates on EVERY function this method calls — see its
			// docblock for why is_plugin_active() alone is not a sufficient sentinel.
			if ( self::needs_plugin_api() ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			// Step 2: multisite + network-active → reject; per-site deactivation only.
			if ( is_multisite() && is_plugin_active_for_network( $plugin_file ) ) {
				return 'network_active_unsupported';
			}

			// Step 3: already inactive → idempotent ack, no side effects.
			if ( ! is_plugin_active( $plugin_file ) ) {
				return 'already';
			}

			// Step 4: deactivate. A Throwable from here propagates to the dispatcher
			// (→ 'failed', nonce stays 'processing' for §9.1 takeover retry).
			deactivate_plugins( $plugin_file, false, false );

			// Write the persistent site-level notice so the admin knows why the plugin
			// was deactivated. autoload 'no' — only read on admin_notices, not on every
			// page load.
			$this->write_notice( $plugin_id, $plugin->get_plugin_name(), $plugin->get_support_url() );

			// Also leave a WooCommerce Admin inbox breadcrumb (Finding B, s12).
			// WooCommerce renders it independent of this plugin's active state, so the
			// admin still learns why a SINGLE-v2-plugin site went dark — the
			// admin_notices banner above can only be drawn by a still-active sibling.
			$this->maybe_add_breadcrumb_note( $plugin );

			/**
			 * Fires after a remote deactivate_plugin command executes successfully.
			 *
			 * This is a FROZEN PUBLIC CONTRACT (frozen §9.8, s8-p6 pins it).
			 * The hook name template is `woodev_{plugin_id}_remote_deactivated` where
			 * `{plugin_id}` is the value of `Woodev_Plugin::get_id()` for the target
			 * plugin. The single argument is the full verified signed payload array.
			 *
			 * Fired EXACTLY ONCE per successful deactivation, AFTER deactivate_plugins()
			 * returns. It is NOT fired on 'already', 'network_active_unsupported', or any
			 * Throwable path. Listeners are expected to be idempotent (the dispatcher may
			 * re-deliver via §9.1 takeover in crash-recovery scenarios).
			 *
			 * @since 2.0.0
			 *
			 * @param array<string, mixed> $payload The verified signed command payload.
			 */
			do_action( "woodev_{$plugin_id}_remote_deactivated", $payload );

			// One log line via the plugin's existing logger seam.
			$plugin->log(
				sprintf(
					'Remote deactivate_plugin command executed (nonce: %s)',
					(string) ( $payload['nonce'] ?? '' )
				)
			);

			return 'executed';
		}

		/**
		 * Persists a deactivation notice entry into the site-level notices option.
		 *
		 * Writes a Russian, count-neutral message (no _n() — gotcha
		 * i18n/russian-source-plural-n) keyed by the target plugin id so that
		 * notices() can render it on the next admin page load.
		 *
		 * The message is intentionally NEUTRAL about the cause: the signed payload
		 * carries no reason (args: {}), so the client must not assert a specific
		 * reason (e.g. "license expired") that is frequently wrong (wrong domain,
		 * revoked, refunded). It states the plugin is off because the license is
		 * not valid for this site and points the admin at their account. The
		 * "contact us" tail is appended ONLY when the plugin supplies a support
		 * URL — get_support_url() returns null in the base, so a plugin that did
		 * not override it must not render an empty <a href="">.
		 *
		 * @since 2.0.1
		 *
		 * @param string      $plugin_id   The plugin id (get_id()).
		 * @param string      $plugin_name The plugin display name.
		 * @param string|null $support_url Optional support URL (get_support_url()); null/empty omits the contact tail.
		 * @return void
		 */
		private function write_notice( string $plugin_id, string $plugin_name, ?string $support_url = null ): void {

			$notices = get_option( self::NOTICES_OPTION, array() );

			if ( ! is_array( $notices ) ) {
				$notices = array();
			}

			$support_url = is_string( $support_url ) ? trim( $support_url ) : '';

			// Russian, count-neutral (no _n()), cause-neutral: names the plugin and
			// states the license is not valid for this site (the signed payload
			// carries no reason). Translation domain woodev-plugin-framework.
			if ( '' !== $support_url ) {
				$message = sprintf(
					/* translators: 1: plugin name, 2: support URL */
					__( 'Плагин %1$s отключён: лицензия недействительна для этого сайта. Проверьте статус лицензии в личном кабинете на woodev.ru или <a href="%2$s">свяжитесь с нами</a>.', 'woodev-plugin-framework' ),
					$plugin_name,
					esc_url( $support_url )
				);
			} else {
				$message = sprintf(
					/* translators: %s: plugin name */
					__( 'Плагин %s отключён: лицензия недействительна для этого сайта. Проверьте статус лицензии в личном кабинете на woodev.ru.', 'woodev-plugin-framework' ),
					$plugin_name
				);
			}

			$notices[ $plugin_id ] = array(
				'message' => $message,
				'ts'      => time(),
			);

			update_option( self::NOTICES_OPTION, $notices, 'no' );
		}

		/**
		 * Leaves a WooCommerce Admin inbox breadcrumb for a remote deactivation.
		 *
		 * The admin_notices banner (NOTICES_OPTION) can only be drawn by an ACTIVE
		 * Woodev_Plugins_License engine — on a site whose only v2 plugin is the one
		 * just deactivated, NO framework code loads at all, so the banner never shows
		 * (Finding B, s12). A WC Admin note is stored in WooCommerce's own table and
		 * rendered by WooCommerce regardless of this plugin's state, so it survives
		 * the deactivation. Cleared on reactivation by
		 * clear_remote_deactivation_artifacts(). Best-effort: a no-op when WooCommerce
		 * Admin is not present (e.g. a pure-WP plugin or the unit suite), which is why
		 * the class_exists guard runs FIRST — before any plugin accessor — so the
		 * heavily mocked command tests never reach the WC path.
		 *
		 * @since 2.0.2
		 *
		 * @param Woodev_Plugin $plugin The plugin being remotely deactivated.
		 * @return void
		 */
		private function maybe_add_breadcrumb_note( Woodev_Plugin $plugin ): void {

			if ( ! class_exists( '\Automattic\WooCommerce\Admin\Notes\Note' ) || ! class_exists( 'Woodev_Notes_Helper' ) ) {
				return;
			}

			$support_url = trim( (string) $plugin->get_support_url() );

			$actions = array();

			if ( '' !== $support_url ) {
				$actions[] = array(
					'name'  => 'woodev-remote-deactivated-support',
					'label' => __( 'Связаться с нами', 'woodev-plugin-framework' ),
					'url'   => $support_url,
				);
			}

			Woodev_Notes_Helper::add_note(
				self::get_breadcrumb_note_name( $plugin ),
				$plugin->get_id_dasherized(),
				sprintf(
					/* translators: %s: plugin name */
					__( 'Плагин %s отключён', 'woodev-plugin-framework' ),
					$plugin->get_plugin_name()
				),
				__( 'Лицензия недействительна для этого сайта. Проверьте статус лицензии в личном кабинете на woodev.ru.', 'woodev-plugin-framework' ),
				$actions
			);
		}
	}

endif;
