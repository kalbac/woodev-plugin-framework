<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Plugin_Install_Tab' ) ) :

	/**
	 * Adds a "Плагины Woodev" tab to /wp-admin/plugin-install.php that redirects
	 * to the React «Плагины» catalog (admin.php?page=woodev-extensions).
	 *
	 * Mirrors WooCommerce's marketplace tab: the tab exists only as a familiar
	 * entry point on the native plugin-install screen; activating it bounces the
	 * user to the dedicated catalog page rather than rendering a second view.
	 *
	 * @since 2.0.2
	 */
	class Woodev_Plugin_Install_Tab {

		/**
		 * Register WordPress hooks.
		 *
		 * @since 2.0.2
		 * @return void
		 */
		public function init(): void {
			add_filter( 'install_plugins_tabs', [ $this, 'register_tab' ] );
			add_action( 'load-plugin-install.php', [ $this, 'maybe_redirect' ] );
		}

		/**
		 * Append the Woodev tab to the plugin-install.php tab list.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, string> $tabs Tab slug => label map.
		 * @return array<string, string>
		 */
		public function register_tab( array $tabs ): array {
			$tabs['woodev'] = __( 'Плагины Woodev', 'woodev-plugin-framework' );
			return $tabs;
		}

		/**
		 * Redirect to the React catalog when the Woodev tab is active.
		 *
		 * Fires on load-plugin-install.php — before any markup is sent — so the
		 * redirect is header-safe. Non-Woodev tabs are left untouched.
		 *
		 * @internal
		 * @since 2.0.2
		 * @return void
		 */
		public function maybe_redirect(): void {
			$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab slug check, no state change.

			if ( 'woodev' !== $tab ) {
				return;
			}

			wp_safe_redirect( $this->get_redirect_url() );
			exit;
		}

		/**
		 * The catalog page the Woodev tab redirects to.
		 *
		 * @since 2.0.2
		 * @return string Absolute admin URL of the «Плагины» catalog.
		 */
		public function get_redirect_url(): string {
			return admin_url( 'admin.php?page=woodev-extensions' );
		}
	}

endif;
