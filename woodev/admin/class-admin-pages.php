<?php
/**
 * Setup menus in WP admin.
 *
 * @since 1.2.1
 */

defined( 'ABSPATH' ) or exit;

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
		public function instance( Woodev_Plugin $woodev_plugin ) {

			$this->woodev_plugin = $woodev_plugin;

			add_action( 'admin_menu', array( $this, 'admin_menu' ) );
			add_action( 'admin_menu', array( $this, 'menu_remove_top_item' ), 100 );
			add_action( 'admin_menu', array( $this, 'licenses_menu' ), 20 );

			if ( apply_filters( 'woodev_show_extensions_page', true ) ) {
				add_action( 'admin_menu', array( $this, 'extensions_menu' ), 30 );
			}

			add_filter( 'menu_order', array( $this, 'menu_order' ) );
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
			add_action( 'load-' . $license_page, array( $this, 'license_page_init' ) );
		}

		public function load_licenses_page_scripts() {
			wp_enqueue_style(
				'woodev-plugin-license-page',
				$this->woodev_plugin->get_framework_assets_url() . '/css/admin/woodev-license-page.css',
				array(),
				$this->woodev_plugin->get_version()
			);
		}

		/**
		 * Generates license settings page HTML markup section
		 * The fields must be added via main plugin class. @return void
		 * @see Woodev_License_Settings::register_license_settings()
		 *
		 */
		public function license_page_init() {

			add_settings_section(
				'woodev_licenses_section',
				esc_html( get_admin_page_title() ),
				array( $this, 'license_section_description' ),
				'woodev_licenses_page',
				array(
					'before_section' => '<div class="%s">',
					'after_section'  => '</div><div class="clear"></div>',
					'section_class'  => 'wrap wrap-licenses'
				)
			);
		}

		/**
		 * Displays license page description on top (below the title section)
		 *
		 * @return void
		 */
		public function license_section_description() {
			echo wp_kses_post(
				wpautop(
					sprintf(
						__( 'To use Woodev plugins, please provide your valid license key and activate it below. License key was sending to your email after purchase. Also, you can get key on <a href="%s" target="_blank">your account page</a>.', 'woodev-plugin-framework' ),
						esc_url( 'https://woodev.ru/my-account' )
					)
				)
			);
		}

		public function license_page() {

			echo '<form method="POST" id="woodev-plugin-license-settings" action="options.php">';

			settings_fields( 'woodev_license_fields_group' );

			do_settings_sections( 'woodev_licenses_page' );

			submit_button( __( 'Save changes', 'woodev-plugin-framework' ), 'primary', 'submit', false );

			echo '</form><!-- end form#woodev-plugin-license-settings -->';

			$this->get_settings_section();

		}

		private function get_settings_section() {
			include_once( $this->woodev_plugin->get_framework_path() . '/admin/pages/views/html-settings-section.php' );
		}

		public function extensions_menu() {

			$extensions_suffix = add_submenu_page(
				'woodev',
				__( 'Woodev all plugins', 'woodev-plugin-framework' ),
				__( 'Plugins', 'woodev-plugin-framework' ),
				'manage_options',
				'woodev-extensions',
				array( $this, 'extensions_page' )
			);

			add_action( 'admin_print_scripts-' . $extensions_suffix, array( $this, 'load_plugins_page_scripts' ) );
			add_action( 'load-' . $extensions_suffix, array( $this, 'extensions_page_init' ) );
		}

		public function load_plugins_page_scripts() {

			wp_enqueue_style(
				'woodev-plugin-plugins-page',
				$this->woodev_plugin->get_framework_assets_url() . '/css/admin/woodev-plugins-page.css',
				array(),
				$this->woodev_plugin->get_version()
			);
		}

		public function extensions_page_init() {
			include_once( $this->woodev_plugin->get_framework_path() . '/admin/pages/class-admin-plugins.php' );
		}

		public function extensions_page() {
			Woodev_Admin_Plugins::output();
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