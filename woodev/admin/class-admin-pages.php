<?php
/**
 * Setup menus in WP admin.
 *
 * @since 1.2.1
 */

defined( 'ABSPATH' ) || exit;

/**
 * Woodev_Admin_Pages Class.
 */
if ( ! class_exists( 'Woodev_Admin_Pages' ) ) :

	class Woodev_Admin_Pages {

		/**
		 * Main plugin class instance
		 *
		 * @var Woodev_Plugin
		 */
		private $woodev_plugin;

		/**
		 * Initialize class.
		 *
		 * @param Woodev_Plugin $woodev_plugin
		 *
		 * @return void
		 */
		public function instance( Woodev_Plugin $woodev_plugin ): void {

			$this->woodev_plugin = $woodev_plugin;

			add_action( 'admin_menu', array( $this, 'admin_menu' ) );
			add_action( 'admin_menu', array( $this, 'menu_remove_top_item' ), 100 );
			add_action( 'admin_menu', array( $this, 'licenses_menu' ), 20 );

			if ( apply_filters( 'woodev_show_extensions_page', true ) ) {
				add_action( 'admin_menu', array( $this, 'extensions_menu' ), 30 );
			}

			add_filter( 'menu_order', array( $this, 'menu_order' ) );

			$this->init_plugin_install_tab();
		}

		/**
		 * Register the Woodev tab on the plugin-install.php screen.
		 *
		 * @since 2.0.2
		 * @return void
		 */
		private function init_plugin_install_tab(): void {
			include_once $this->woodev_plugin->get_framework_path() . '/admin/class-plugin-install-tab.php';
			( new Woodev_Plugin_Install_Tab( $this->woodev_plugin ) )->init();
		}

		public function admin_menu() {
			global $menu;

			if ( current_user_can( 'manage_options' ) ) {
				$menu[] = array( '', 'read', 'separator-woodev', '', 'wp-menu-separator woodev' );
			}

			add_menu_page(
				__( 'Woodev', 'woodev-plugin-framework' ),
				__( 'Woodev', 'woodev-plugin-framework' ),
				'manage_options',
				'woodev',
				null,
				$this->woodev_plugin->get_framework_assets_url() . '/images/woodev-icon-16x16.png',
				'65.5'
			);
		}

		public function licenses_menu() {

			$license_page = add_submenu_page(
				'woodev',
				__( 'Woodev license keys', 'woodev-plugin-framework' ),
				__( 'Licenses', 'woodev-plugin-framework' ),
				'manage_options',
				'woodev-licenses',
				array( $this, 'license_page' )
			);

			add_action( 'admin_print_scripts-' . $license_page, array( $this, 'load_licenses_page_scripts' ) );
		}

		/**
		 * Enqueues the React license-page app scripts and styles.
		 *
		 * Reads build-time dependency manifest from index.asset.php so WordPress
		 * enqueues exactly the handles webpack recorded (wp-element, wp-components,
		 * wp-api-fetch, wp-i18n). Falls back to empty deps + plugin version when
		 * the manifest file is absent (e.g. before first build on a fresh checkout).
		 *
		 * Inlines window.woodevLicenses BEFORE the bundle so the app can access
		 * restRoot, restNonce, and the initial plugin states on first render.
		 *
		 * @since 2.0.0
		 *
		 * @return void
		 */
		public function load_licenses_page_scripts(): void {

			$asset_file = $this->woodev_plugin->get_framework_path() . '/assets/build/license-page/index.asset.php';

			if ( file_exists( $asset_file ) ) {
				$asset = include $asset_file;
			} else {
				$asset = array(
					'dependencies' => array(),
					'version'      => $this->woodev_plugin->get_version(),
				);
			}

			$build_url = $this->woodev_plugin->get_framework_assets_url() . '/build/license-page';

			// Native WP component library styles (externalized in bundle).
			wp_enqueue_style( 'wp-components' );

			// Framework license-page app styles.
			wp_enqueue_style(
				'woodev-license-app',
				$build_url . '/style-index.css',
				array( 'wp-components' ),
				$asset['version']
			);

			// Framework license-page app bundle.
			wp_enqueue_script(
				'woodev-license-app',
				$build_url . '/index.js',
				$asset['dependencies'],
				$asset['version'],
				true
			);

			// Collect initial state for every registered license engine.
			$states = array();
			foreach ( Woodev_Plugins_License::get_registered_instances() as $license_engine ) {
				$states[] = $license_engine->get_state();
			}

			// Inline bootstrap data BEFORE the bundle so it is available on first render.
			wp_add_inline_script(
				'woodev-license-app',
				'window.woodevLicenses = ' . wp_json_encode(
					array(
						'restRoot'   => esc_url_raw( rest_url() ),
						'restNonce'  => wp_create_nonce( 'wp_rest' ),
						'plugins'    => array_values( $states ),
					)
				) . ';',
				'before'
			);
		}

		public function license_page(): void {
			echo '<div class="wrap woodev-licenses-wrap">';
			echo '<h1 class="wp-heading-inline">' . esc_html__( 'Лицензии Woodev', 'woodev-plugin-framework' ) . '</h1>';
			echo '<hr class="wp-header-end">';
			echo '<div id="woodev-licenses-app">';
			$this->render_license_skeleton();
			echo '</div>';
			$this->get_settings_section();
			echo '</div>';
		}

		/**
		 * Renders a loading skeleton inside the React mount node — one placeholder
		 * card per registered license engine plus an intro bar.
		 *
		 * The skeleton reserves the card-grid height so the page does not "jump"
		 * when the React bundle finishes loading; React's createRoot().render()
		 * replaces this markup on mount. Emits nothing when no engine is registered.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		private function render_license_skeleton(): void {

			$count = count( Woodev_Plugins_License::get_registered_instances() );

			if ( $count < 1 ) {
				return;
			}

			echo '<div class="woodev-licenses-skeleton" aria-hidden="true">';
			echo '<div class="woodev-skeleton woodev-skeleton--intro"></div>';
			echo '<div class="woodev-licenses-grid">';

			for ( $i = 0; $i < $count; $i++ ) {
				echo '<div class="woodev-skeleton-card">'
					. '<div class="woodev-skeleton-card__header">'
						. '<span class="woodev-skeleton woodev-skeleton--title"></span>'
						. '<span class="woodev-skeleton woodev-skeleton--badge"></span>'
					. '</div>'
					. '<div class="woodev-skeleton-card__body">'
						. '<span class="woodev-skeleton woodev-skeleton--line"></span>'
						. '<span class="woodev-skeleton woodev-skeleton--field"></span>'
					. '</div>'
					. '<div class="woodev-skeleton-card__footer">'
						. '<span class="woodev-skeleton woodev-skeleton--button"></span>'
						. '<span class="woodev-skeleton woodev-skeleton--toggle"></span>'
					. '</div>'
				. '</div>';
			}

			echo '</div>';
			echo '</div>';
		}

		private function get_settings_section() {
			include_once $this->woodev_plugin->get_framework_path() . '/admin/pages/views/html-settings-section.php';
		}

		public function extensions_menu() {

			$extensions_suffix = add_submenu_page(
				'woodev',
				__( 'Плагины Woodev', 'woodev-plugin-framework' ),
				__( 'Плагины', 'woodev-plugin-framework' ),
				'manage_options',
				'woodev-extensions',
				array( $this, 'extensions_page' )
			);

			add_action( 'admin_print_scripts-' . $extensions_suffix, array( $this, 'load_plugins_page_scripts' ) );
		}

		/**
		 * Enqueues the React «Плагины» app bundle + inline bootstrap.
		 *
		 * Reads the build-time dependency manifest from index.asset.php so WordPress
		 * enqueues exactly the handles webpack recorded (wp-element, wp-components,
		 * wp-api-fetch, wp-i18n), falling back to empty deps + plugin version when the
		 * manifest is absent (e.g. before the first build on a fresh checkout). Inlines
		 * window.woodevExtensions BEFORE the bundle so the app has the REST root, nonce,
		 * and account feature flag on first render.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function load_plugins_page_scripts(): void {

			$asset_file = $this->woodev_plugin->get_framework_path() . '/assets/build/plugins-page/index.asset.php';

			if ( file_exists( $asset_file ) ) {
				$asset = include $asset_file;
			} else {
				$asset = array(
					'dependencies' => array(),
					'version'      => $this->woodev_plugin->get_version(),
				);
			}

			$build_url = $this->woodev_plugin->get_framework_assets_url() . '/build/plugins-page';

			// Native WP component library styles (externalized in bundle).
			wp_enqueue_style( 'wp-components' );

			// Framework plugins-page app styles.
			wp_enqueue_style(
				'woodev-plugins-app',
				$build_url . '/style-index.css',
				array( 'wp-components' ),
				$asset['version']
			);

			// Framework plugins-page app bundle.
			wp_enqueue_script(
				'woodev-plugins-app',
				$build_url . '/index.js',
				$asset['dependencies'],
				$asset['version'],
				true
			);

			// Inline bootstrap data BEFORE the bundle so it is available on first render.
			wp_add_inline_script(
				'woodev-plugins-app',
				'window.woodevExtensions = ' . wp_json_encode(
					array(
						'restRoot'       => esc_url_raw( rest_url() ),
						'restNonce'      => wp_create_nonce( 'wp_rest' ),
						/**
						 * Gates the woodev.ru account-connection UI (Phase B). Default
						 * false until the woodev-account-connector plugin ships the
						 * OAuth endpoints.
						 *
						 * @since 2.0.2
						 *
						 * @param bool $enabled Whether to enable the account-connection UI.
						 */
						'accountEnabled' => (bool) apply_filters( 'woodev_extensions_account_enabled', false ),
					)
				) . ';',
				'before'
			);
		}

		public function extensions_page(): void {
			echo '<div class="wrap woodev-extensions-wrap">';
			echo '<h1 class="wp-heading-inline">' . esc_html__( 'Плагины Woodev', 'woodev-plugin-framework' ) . '</h1>';
			echo '<hr class="wp-header-end">';
			echo '<div id="woodev-extensions-app"></div>';
			echo '</div>';
		}

		/**
		 * Reorder the Woodev menu items in admin.
		 *
		 * @param array $menu_order Menu order.
		 *
		 * @return array
		 */
		public function menu_order( $menu_order ) {
			// Initialize our custom order array.
			$woodev_menu_order = array();

			// Get the index of our custom separator.
			$woodev_separator = array_search( 'separator-woodev', $menu_order, true );

			// Loop through menu order and do some rearranging.
			foreach ( $menu_order as $index => $item ) {

				if ( 'woodev' === $item ) {
					$woodev_menu_order[] = 'separator-woodev';
					$woodev_menu_order[] = $item;
					unset( $menu_order[ $woodev_separator ] );
				} elseif ( $item !== 'separator-woodev' ) {
					$woodev_menu_order[] = $item;
				}
			}

			// Return order.
			return $woodev_menu_order;
		}

		/**
		 * Remove top level menu item
		 *
		 * @return void
		 */
		public function menu_remove_top_item() {
			global $submenu;

			if ( isset( $submenu['woodev'] ) ) {
				unset( $submenu['woodev'][0] );
			}
		}
	}

endif;
