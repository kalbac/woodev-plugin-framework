<?php
/**
 * Collects the marketplace menus of every active plugin and wires them into wp-admin once.
 *
 * @package Woodev\Framework
 */

namespace Woodev\Framework\Marketplace\Admin;

use Woodev\Framework\Marketplace\Marketplace_Plugin;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Admin_Collector::class, false ) ) :

	/**
	 * One per request, however many marketplace plugins are active — like `Orders_Registry::instance()` for shipping.
	 *
	 * Each {@see Marketplace_Plugin} hands itself over in its constructor. The collector then registers every plugin's menu
	 * through `wc_admin_register_page()`, prints the menu CSS, redirects v1 URLs, and enqueues the ONE routing bundle with a
	 * manifest that lists every menu — so two marketplace plugins share a bundle, and a menu id one of them already owns is
	 * refused instead of silently replacing the first.
	 *
	 * @internal
	 *
	 * @since 2.0.29
	 */
	class Admin_Collector {

		/**
		 * Script handle of the routing bundle.
		 */
		public const SCRIPT_HANDLE = 'woodev-marketplace-admin';

		/**
		 * Stylesheet handle of the routing bundle.
		 */
		public const STYLE_HANDLE = 'woodev-marketplace-admin';

		/**
		 * Build directory of the bundle, under the framework's `assets/build/`.
		 */
		public const BUNDLE_DIR = 'marketplace-admin';

		/**
		 * The global the manifest is published as.
		 */
		public const MANIFEST_GLOBAL = 'woodevMarketplaceAdmin';

		/**
		 * `admin_menu` priority of the registration: after WooCommerce's own pages, like the delivery orders page.
		 */
		private const REGISTER_PRIORITY = 40;

		/**
		 * `admin_menu` priority of the submenu clean-up: after every registration, whoever made it.
		 */
		private const CLEAN_UP_PRIORITY = 99;

		/**
		 * @var self|null
		 */
		private static $instance;

		/**
		 * @var Marketplace_Plugin[] Active marketplace plugins, keyed by plugin id.
		 */
		private $plugins = [];

		/**
		 * @var bool
		 */
		private $hooked = false;

		/**
		 * @var Menu[]|null Menus of the request, keyed by menu id; built once.
		 */
		private $menus;

		/**
		 * @return self
		 */
		public static function instance(): self {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Adds a plugin's menu to the request and hooks the collector the first time.
		 *
		 * @since 2.0.29
		 *
		 * @param Marketplace_Plugin $plugin The plugin.
		 * @return void
		 */
		public function register_plugin( Marketplace_Plugin $plugin ): void {
			$this->plugins[ $plugin->get_id() ] = $plugin;
			$this->menus                        = null;

			$this->add_hooks();
		}

		/**
		 * The menus of the request, the first plugin to claim an id keeping it.
		 *
		 * @since 2.0.29
		 *
		 * @return Menu[] Keyed by menu id.
		 */
		public function get_menus(): array {
			if ( null !== $this->menus ) {
				return $this->menus;
			}

			$this->menus = [];

			foreach ( $this->plugins as $plugin ) {
				$menu = $plugin->get_marketplace_admin_menu();

				if ( null === $menu ) {
					continue;
				}

				if ( isset( $this->menus[ $menu->get_id() ] ) ) {
					_doing_it_wrong(
						__METHOD__,
						sprintf(
							'Marketplace menu "%1$s" of plugin "%2$s" is already registered by plugin "%3$s"; the second one is ignored.',
							esc_html( $menu->get_id() ),
							esc_html( $plugin->get_id() ),
							esc_html( $this->menus[ $menu->get_id() ]->get_plugin_id() )
						),
						'2.0.29'
					);

					continue;
				}

				$this->menus[ $menu->get_id() ] = $menu;
			}

			return $this->menus;
		}

		/**
		 * Registers every menu with WooCommerce Admin: each root, then its children.
		 *
		 * Without WooCommerce Admin there is nothing to register into, and no classic page replaces the React ones, so the
		 * merchant is told once, in the admin's own notice.
		 *
		 * @internal
		 *
		 * @since 2.0.29
		 *
		 * @return void
		 */
		public function register_menus(): void {
			if ( ! function_exists( 'wc_admin_register_page' ) ) {
				$this->report_missing_wc_admin();

				return;
			}

			foreach ( $this->get_menus() as $menu ) {
				foreach ( $menu->build_registrations() as $arguments ) {
					wc_admin_register_page( $arguments );
				}
			}
		}

		/**
		 * Marks the duplicate root entry WordPress put into each submenu, see {@see Menu::mark_root_entry()}.
		 *
		 * @internal
		 *
		 * @since 2.0.29
		 *
		 * @return void
		 */
		public function clean_up_submenus(): void {
			global $submenu;

			if ( ! is_array( $submenu ) ) {
				return;
			}

			foreach ( $this->get_menus() as $menu ) {
				$slug = $menu->get_root_slug();

				if ( isset( $submenu[ $slug ] ) && is_array( $submenu[ $slug ] ) ) {
					$submenu[ $slug ] = $menu->mark_root_entry( $submenu[ $slug ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the submenu is the menu API's data.
				}
			}
		}

		/**
		 * Prints the icon rule and the hidden-entry rule of every menu.
		 *
		 * @internal
		 *
		 * @since 2.0.29
		 *
		 * @return void
		 */
		public function print_menu_styles(): void {
			$css = '';

			foreach ( $this->get_menus() as $menu ) {
				$css .= $menu->build_menu_css();
			}

			if ( '' !== $css ) {
				printf( '<style id="woodev-marketplace-menu">%s</style>', $css ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from validated slugs and constants only.
			}
		}

		/**
		 * Sends a v1 URL to the page that replaced it.
		 *
		 * Hooked onto `admin_page_access_denied`, not `admin_init`: a v1 slug no plugin registers any more is denied by core before
		 * `admin_init` runs (see `Orders_Registry::maybe_redirect_legacy_page()`). It runs after the capability check — a user who
		 * could not open the destination is not redirected to it.
		 *
		 * @internal
		 *
		 * @since 2.0.29
		 *
		 * @return void
		 */
		public function maybe_redirect_legacy(): void {
			$target = $this->resolve_legacy_redirect();

			if ( null === $target ) {
				return;
			}

			wp_safe_redirect( $target );
			exit;
		}

		/**
		 * The destination of the current request when it is a v1 URL, else null. Split from the redirect so a test can reach it.
		 *
		 * @since 2.0.29
		 *
		 * @return string|null
		 */
		public function resolve_legacy_redirect(): ?string {
			if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				return null;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- read-only URL routing, no state change.
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
			$request     = Page::parse_query( (string) wp_parse_url( $request_uri, PHP_URL_QUERY ) );

			if ( [] === $request || 'wc-admin' === ( $request['page'] ?? '' ) ) {
				return null; // the destination's own `page` value: never redirect away from it.
			}

			foreach ( $this->get_menus() as $menu ) {
				$page = $menu->find_page_for_legacy_request( $request );

				if ( null === $page || ! current_user_can( $page->get_capability( $menu->get_capability() ) ) ) {
					continue;
				}

				return $this->get_page_url( $menu, $page );
			}

			return null;
		}

		/**
		 * Enqueues the routing bundle once, on every WooCommerce Admin screen.
		 *
		 * Every screen, not only the menu's own: wc-admin navigates between its pages without a reload, so a bundle loaded only on
		 * our screens would leave a client-side visit from WooCommerce's own pages with no route (the delivery orders page does
		 * the same). The plugin's own view scripts follow by handle.
		 *
		 * @internal
		 *
		 * @since 2.0.29
		 *
		 * @return void
		 */
		public function enqueue_assets(): void {
			if ( wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) || ! $this->is_wc_admin_screen() ) {
				return;
			}

			$manifest = $this->build_manifest();
			$plugin   = reset( $this->plugins );

			if ( [] === $manifest['menus'] || false === $plugin ) {
				return;
			}

			$asset_dir  = $plugin->get_framework_path() . '/assets/build/' . self::BUNDLE_DIR;
			$asset_file = $asset_dir . '/index.asset.php';
			$asset      = file_exists( $asset_file ) ? include $asset_file : null;

			if ( ! is_array( $asset ) ) {
				error_log( sprintf( '[woodev] Marketplace admin asset manifest missing: %s', $asset_file ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for a missing build artifact.

				$asset = [
					'dependencies' => [],
					'version'      => $plugin->get_version(),
				];
			}

			$build_url = $plugin->get_framework_assets_url() . '/build/' . self::BUNDLE_DIR;

			// `wc-admin-app` orders the bundle after the wc-admin shell, so `woocommerce_admin_pages_list` is read with our pages on it.
			// `@wordpress/dependency-extraction-webpack-plugin` does not know `@woocommerce/*`, so the handle is added by hand.
			$dependencies = array_merge( (array) $asset['dependencies'], [ 'wc-admin-app' ] );

			wp_enqueue_script( self::SCRIPT_HANDLE, $build_url . '/index.js', $dependencies, $asset['version'], true );
			wp_add_inline_script(
				self::SCRIPT_HANDLE,
				'window.' . self::MANIFEST_GLOBAL . ' = ' . wp_json_encode( $manifest ) . ';',
				'before'
			);

			if ( file_exists( $asset_dir . '/style-index.css' ) ) {
				wp_enqueue_style( self::STYLE_HANDLE, $build_url . '/style-index.css', [], $asset['version'] );
			}

			$this->enqueue_view_scripts();
		}

		/**
		 * The manifest the bundle reads: every menu, limited to the pages the current user may open.
		 *
		 * @since 2.0.29
		 *
		 * @return array{menus: array<int,array<string,mixed>>}
		 */
		public function build_manifest(): array {
			$menus = [];
			$can   = static function ( string $capability ): bool {
				return current_user_can( $capability ); // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- the capability is the page's own.
			};

			foreach ( $this->get_menus() as $menu ) {
				$entry = $menu->build_manifest( $can );

				if ( null !== $entry ) {
					$menus[] = $entry;
				}
			}

			return [ 'menus' => $menus ];
		}

		/**
		 * URL of a page of a menu, inside the wc-admin app.
		 *
		 * @since 2.0.29
		 *
		 * @param Menu                $menu  The menu.
		 * @param Page                $page  A page of it.
		 * @param array<string,mixed> $query Extra query arguments.
		 * @return string
		 */
		public function get_page_url( Menu $menu, Page $page, array $query = [] ): string {
			return add_query_arg(
				array_merge(
					[
						'page' => 'wc-admin',
						'path' => $menu->get_page_path( $page ),
					],
					$query
				),
				admin_url( 'admin.php' )
			);
		}

		/**
		 * Forgets everything; for tests, which share one process.
		 *
		 * @internal
		 *
		 * @since 2.0.29
		 *
		 * @return void
		 */
		public function reset_for_tests(): void {
			$this->plugins = [];
			$this->menus   = null;
			$this->hooked  = false;
		}

		/**
		 * Whether the current screen is a WooCommerce Admin page. A seam: `wc_admin_is_registered_page()` does not exist under
		 * Brain Monkey, and stubbing it once would leak its existence into every later test (`Orders_Registry::is_wc_admin_screen()`).
		 *
		 * @return bool
		 */
		protected function is_wc_admin_screen(): bool {
			return function_exists( 'wc_admin_is_registered_page' ) && wc_admin_is_registered_page();
		}

		/**
		 * @return void
		 */
		private function add_hooks(): void {
			if ( $this->hooked ) {
				return;
			}

			$this->hooked = true;

			add_action( 'admin_menu', [ $this, 'register_menus' ], self::REGISTER_PRIORITY );
			add_action( 'admin_menu', [ $this, 'clean_up_submenus' ], self::CLEAN_UP_PRIORITY );
			add_action( 'admin_head', [ $this, 'print_menu_styles' ] );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
			add_action( 'admin_page_access_denied', [ $this, 'maybe_redirect_legacy' ] );
		}

		/**
		 * Enqueues the plugins' own view scripts that are registered.
		 *
		 * @return void
		 */
		private function enqueue_view_scripts(): void {
			foreach ( $this->get_menus() as $menu ) {
				foreach ( $menu->get_views() as $script_handle ) {
					if ( wp_script_is( $script_handle, 'registered' ) ) {
						wp_enqueue_script( $script_handle );
					}
				}
			}
		}

		/**
		 * @return void
		 */
		private function report_missing_wc_admin(): void {
			foreach ( $this->plugins as $plugin ) {
				$plugin->get_admin_notice_handler()->add_admin_notice(
					sprintf(
						/* translators: %s: plugin name */
						__( 'Страницы плагина «%s» работают в панели WooCommerce Admin, а она сейчас недоступна. Включите WooCommerce Admin или обновите WooCommerce.', 'woodev-plugin-framework' ),
						esc_html( $plugin->get_plugin_name() )
					),
					$plugin->get_id() . '-marketplace-wc-admin-missing',
					[
						'dismissible' => false,
						'notice_class' => 'error',
					]
				);
			}
		}
	}

endif;
