<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Plugin_Install_Tab' ) ) :

	/**
	 * Adds a "Woodev" tab to /wp-admin/plugin-install.php,
	 * rendering the Woodev marketplace when the tab is active.
	 *
	 * @since 2.0.2
	 */
	class Woodev_Plugin_Install_Tab {

		/**
		 * Main plugin instance.
		 *
		 * @since 2.0.2
		 * @var Woodev_Plugin
		 */
		private Woodev_Plugin $plugin;

		/**
		 * @since 2.0.2
		 *
		 * @param Woodev_Plugin $plugin Main plugin instance.
		 */
		public function __construct( Woodev_Plugin $plugin ) {
			$this->plugin = $plugin;
		}

		/**
		 * Register WordPress hooks.
		 *
		 * @since 2.0.2
		 * @return void
		 */
		public function init(): void {
			add_filter( 'install_plugins_tabs', [ $this, 'register_tab' ] );
			add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue_styles' ] );
			add_action( 'install_plugins_pre_woodev', [ $this, 'render' ] );
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
			$tabs['woodev'] = __( 'Woodev', 'woodev-plugin-framework' );
			return $tabs;
		}

		/**
		 * Enqueue marketplace CSS when on the Woodev plugin-install tab.
		 *
		 * Fires on admin_enqueue_scripts so the stylesheet lands in <head>.
		 *
		 * @since 2.0.2
		 *
		 * @param string $hook Current admin page hook suffix.
		 * @return void
		 */
		public function maybe_enqueue_styles( string $hook ): void {
			$tab = sanitize_key( $_GET['tab'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab slug check, no state change.

			if ( 'plugin-install.php' !== $hook || 'woodev' !== $tab ) {
				return;
			}

			wp_enqueue_style(
				'woodev-plugin-install-tab',
				$this->plugin->get_framework_assets_url() . '/css/admin/woodev-plugins-page.css',
				[],
				$this->plugin->get_version()
			);
		}

		/**
		 * Render the Woodev marketplace content and exit cleanly.
		 *
		 * Fires via install_plugins_pre_woodev, after admin-header.php has been printed.
		 * We output our page content, include admin-footer.php, then exit so the native
		 * plugin list table is never displayed.
		 *
		 * @since 2.0.2
		 * @return void
		 */
		public function render(): void {
			if ( ! class_exists( 'Woodev_Admin_Plugins' ) ) {
				include_once $this->plugin->get_framework_path() . '/admin/pages/class-admin-plugins.php';
			}

			$section  = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$search   = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$sections = Woodev_Admin_Plugins::get_sections();
			$addons   = ( 'all' === $section && '' === $search )
				? ( Woodev_Admin_Plugins::get_all_extension() ?: [] )
				: ( Woodev_Admin_Plugins::get_extension_by_query() ?: [] );
			$base_url = admin_url( 'plugin-install.php?tab=woodev' );

			include __DIR__ . '/pages/views/html-plugin-install-tab.php';

			require_once ABSPATH . 'wp-admin/admin-footer.php';
			exit;
		}
	}

endif;
