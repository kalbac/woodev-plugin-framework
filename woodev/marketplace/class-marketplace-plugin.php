<?php
/**
 * Woodev Marketplace Plugin
 *
 * Base plugin class for WooCommerce plugins that connect a shop to a marketplace.
 *
 * @package Woodev\Framework
 */

namespace Woodev\Framework\Marketplace;

use Woodev\Framework\Marketplace\Admin\Admin_Collector;
use Woodev\Framework\Marketplace\Admin\Menu;
use Woodev\Framework\Marketplace\Admin\Page;
use Woodev\Framework\Marketplace\Data\Lifecycle;
use Woodev\Framework\Settings\Settings_Provider;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\\Woodev\\Framework\\Marketplace\\Marketplace_Plugin' ) ) :

	/**
	 * Base class of a marketplace plugin, like {@see \Woodev\Framework\Shipping\Shipping_Plugin} for carriers and
	 * `Woodev_Payment_Gateway_Plugin` for gateways: the plugin supplies its adapter and the data of its menu, the framework owns
	 * the menu, the pages, the single routing bundle and the legacy redirects (ADR-013, decision 4).
	 *
	 * A plugin declares data only:
	 *
	 *     protected function get_marketplace_menu(): array {
	 *         return [ 'id' => 'ozon-seller', 'title' => 'OZON Seller', 'icon' => $this->get_plugin_url() . '/assets/img/icon.svg', 'position' => '55.63' ];
	 *     }
	 *
	 * @since 2.0.29
	 */
	abstract class Marketplace_Plugin extends \Woodev\Framework\Woocommerce_Plugin {
		/**
		 * @var Menu|null|false The built menu; null when the definition was refused, false before it is built.
		 */
		private $menu = false;

		/**
		 * Initializes the marketplace plugin.
		 *
		 * @since 2.0.29
		 *
		 * @param string $id      Plugin id.
		 * @param string $version Plugin version.
		 * @param array  $args    Plugin configuration arguments, see {@see \Woodev\Framework\Woocommerce_Plugin::__construct()}.
		 */
		public function __construct( string $id, string $version, array $args = [] ) {
			parent::__construct( $id, $version, $args );

			Admin_Collector::instance()->register_plugin( $this );
		}

		/**
		 * Installs the marketplace lifecycle: the plugin's tables are created on install and re-synced on every upgrade
		 * ({@see Lifecycle}), the way every marketplace plugin gets the same persistence. A plugin hooks its own data migration to
		 * the `woodev_{plugin id}_installed` / `_updated` actions, which fire after the tables exist.
		 *
		 * @since 2.0.29
		 *
		 * @return void
		 */
		protected function init_lifecycle_handler() {
			$this->lifecycle_handler = new Lifecycle( $this );
		}

		/**
		 * The plugin's adapter: everything the framework asks of one marketplace (as carriers supply `get_api()`).
		 *
		 * @since 2.0.29
		 *
		 * @return Capabilities\Marketplace_Adapter
		 */
		abstract public function get_marketplace_adapter(): Capabilities\Marketplace_Adapter;

		/**
		 * The menu root: `id` (lower-case words joined by dashes, unique among marketplace plugins), `title`, `icon`, `position`,
		 * `capability` (default `manage_woocommerce`). Strings are already translated in the plugin's text domain.
		 *
		 * @since 2.0.29
		 *
		 * @return array<string,mixed>
		 */
		abstract protected function get_marketplace_menu(): array;

		/**
		 * The pages after «Настройки», in menu order. The default is the framework's five; a plugin overrides it to drop a page its
		 * marketplace cannot support, to add one, or to attach `legacy_page` URLs to a default (call the parent, then change the
		 * array). «Настройки» is not listed: it comes first, by itself, when the plugin has settings providers.
		 *
		 * A descriptor has `slug`, `title`, `kind` (`records` with `tables`, `custom` with `view`), optional `capability`, optional
		 * `legacy_page` (one v1 admin URL or a list; a bare value is the `page` argument) and `analytics => null`, the reserved slot.
		 *
		 * @since 2.0.29
		 *
		 * @return array<int,array<string,mixed>>
		 */
		protected function get_marketplace_pages(): array {
			return [
				[
					'slug'   => Page::LANDING_SLUG,
					'title'  => __( 'Товары', 'woodev-plugin-framework' ),
					'kind'   => Page::KIND_RECORDS,
					'tables' => [ 'products' ],
				],
				[
					'slug'   => 'orders',
					'title'  => __( 'Заказы', 'woodev-plugin-framework' ),
					'kind'   => Page::KIND_RECORDS,
					'tables' => [ 'orders' ],
				],
				[
					'slug'  => 'mappings',
					'title' => __( 'Сопоставление', 'woodev-plugin-framework' ),
					'kind'  => Page::KIND_CUSTOM,
					'view'  => 'mappings',
				],
				[
					'slug'  => 'tools',
					'title' => __( 'Инструменты', 'woodev-plugin-framework' ),
					'kind'  => Page::KIND_CUSTOM,
					'view'  => 'tools',
				],
				[
					'slug'  => 'diagnostics',
					'title' => __( 'Диагностика', 'woodev-plugin-framework' ),
					'kind'  => Page::KIND_CUSTOM,
					'view'  => 'diagnostics',
				],
			];
		}

		/**
		 * React views the plugin brings for `custom` pages: view handle => script handle. The bundle renders a view the script
		 * registers through the `woodev.marketplace.views` filter; PHP never sends JavaScript source. None by default.
		 *
		 * @since 2.0.29
		 *
		 * @return array<string,string>
		 */
		protected function get_marketplace_views(): array {
			return [];
		}

		/**
		 * URL of one of this plugin's pages, inside the wc-admin app.
		 *
		 * @since 2.0.29
		 *
		 * @param string              $page_id Page slug: `settings`, `products`, ….
		 * @param array<string,mixed> $query   Extra query arguments.
		 * @return string The URL; '' when the plugin has no such page.
		 */
		final public function get_marketplace_page_url( string $page_id, array $query = [] ): string {
			$menu = $this->get_marketplace_admin_menu();
			$page = null === $menu ? null : $menu->get_page( $page_id );

			if ( null === $menu || null === $page ) {
				return '';
			}

			return Admin_Collector::instance()->get_page_url( $menu, $page, $query );
		}

		/**
		 * The plugin's own «Настройки» page, opened on its first tab — the same URL
		 * {@see \Woodev\Framework\Settings\Settings_Page_Registry::get_tab_url()} gives that tab, so a plugin-list link, a
		 * "not configured" notice and the legacy redirect all land in one place.
		 *
		 * @since 2.0.29
		 *
		 * @param string|null $plugin_id Unused.
		 * @return string '' when the plugin has no settings page.
		 */
		public function get_settings_url( $plugin_id = null ): string {
			foreach ( (array) $this->get_settings_providers() as $provider ) {
				if ( $provider instanceof Settings_Provider ) {
					return $this->get_settings_tab_url( $provider->get_id() );
				}
			}

			return $this->get_marketplace_page_url( Page::SETTINGS_SLUG );
		}

		/**
		 * The URL of one tab of the plugin's «Настройки» page.
		 *
		 * @internal Read by {@see \Woodev\Framework\Settings\Settings_Page_Registry::get_tab_url()}, the one builder of tab URLs.
		 *
		 * @since 2.0.29
		 *
		 * @param string $tab_id The tab id (= the settings provider id).
		 * @return string '' when the plugin has no settings page.
		 */
		public function get_settings_tab_url( string $tab_id ): string {
			return $this->get_marketplace_page_url( Page::SETTINGS_SLUG, [ 'tab' => $tab_id ] );
		}

		/**
		 * The plugin's menu, built and checked once.
		 *
		 * @internal Read by {@see Admin_Collector}.
		 *
		 * @since 2.0.29
		 *
		 * @return Menu|null Null when the plugin's definition was refused (reported as a developer error).
		 */
		public function get_marketplace_admin_menu(): ?Menu {
			if ( false === $this->menu ) {
				$this->menu = $this->build_menu();
			}

			return $this->menu;
		}

		/**
		 * @return Menu|null
		 */
		private function build_menu(): ?Menu {
			try {
				$pages = [];

				if ( [] !== $this->get_settings_providers() ) {
					$pages[] = $this->build_settings_page();
				}

				foreach ( $this->get_marketplace_pages() as $descriptor ) {
					$pages[] = Page::from_array( (array) $descriptor );
				}

				return new Menu( $this->get_id(), $this->get_marketplace_menu(), $pages, $this->get_marketplace_views() );
			} catch ( \InvalidArgumentException $exception ) {
				_doing_it_wrong(
					self::class . '::get_marketplace_menu',
					sprintf( 'Plugin "%1$s": %2$s', esc_html( $this->get_id() ), esc_html( $exception->getMessage() ) ),
					'2.0.29'
				);

				return null;
			}
		}

		/**
		 * @return Page
		 */
		private function build_settings_page(): Page {
			return Page::from_array(
				[
					'slug'  => Page::SETTINGS_SLUG,
					'title' => __( 'Настройки', 'woodev-plugin-framework' ),
					'kind'  => Page::KIND_SETTINGS,
				]
			);
		}
	}

endif;
