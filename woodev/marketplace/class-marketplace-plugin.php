<?php
/**
 * Woodev Marketplace Plugin
 *
 * Base plugin class for WooCommerce plugins that connect a shop to a marketplace.
 *
 * @package Woodev\Framework
 */

namespace Woodev\Framework\Marketplace;

use Woodev\Framework\Marketplace\Account\Account_Store;
use Woodev\Framework\Marketplace\Account\Secret_Cipher;
use Woodev\Framework\Marketplace\Admin\Admin_Collector;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Logging;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Report_Builder;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Report_Redactor;
use Woodev\Framework\Marketplace\Admin\Menu;
use Woodev\Framework\Marketplace\Admin\Notices\Key_Expiry_Notices;
use Woodev\Framework\Marketplace\Admin\Page;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mappings_Module;
use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Module;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Module;
use Woodev\Framework\Marketplace\Admin\Pages\Diagnostics\Diagnostics_Module;
use Woodev\Framework\Marketplace\Admin\Pages\Tools\Tools_Module;
use Woodev\Framework\Marketplace\Admin\Product\Embedded_Screens;
use Woodev\Framework\Marketplace\Data\Lifecycle;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Settings\Settings_Handler;
use Woodev\Framework\Marketplace\Settings\Settings_Module;
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
		 * @var Settings_Handler|null|false Default settings handler; false before first build.
		 */
		private $settings_handler = false;

		public function __construct( string $id, string $version, array $args = [] ) {
			parent::__construct( $id, $version, $args );

			Admin_Collector::instance()->register_plugin( $this );
			Mappings_Module::register( $this );

			// Priority 0: the runners must be hooked before Action Scheduler starts at `init` priority 1, and the plugin's adapter
			// is ready by then (the plugin's own constructor has finished).
			add_action( 'init', [ $this, 'register_marketplace_flows' ], 0 );
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
		 * Registers every flow the framework runs for the plugin: event intake and drain, stock and price sync, export, links,
		 * catalogue refresh, postings and labels — the hooks, the Action Scheduler runners and schedules, and the intake REST
		 * route, all built from {@see self::get_marketplace_adapter()} ({@see Flow_Wiring}).
		 *
		 * Runs by itself on `init`. It is safe to call again: a plugin id is wired once, so a second call, or a second
		 * marketplace plugin on the same framework copy, adds no hook, no schedule and no route twice. It makes no remote call;
		 * every runner asks the licence gate and the account's state before it does.
		 *
		 * The plugin's write gate ({@see self::account_write_gate()}) is read once here and given to every job gate. A plugin whose account store is not the
		 * framework's table store (see {@see self::get_account_store()}) has no claim to take and runs no flow.
		 *
		 * @since 2.1.0
		 *
		 * @return void
		 */
		public function register_marketplace_flows(): void {
			global $wpdb;

			$accounts = $this->get_account_store();

			if ( ! $accounts instanceof Wpdb_Account_Store ) {
				return;
			}

			( new Flow_Wiring(
				$this->get_id(),
				$this->get_marketplace_adapter(),
				$accounts,
				$wpdb,
				$wpdb->prefix,
				License_Gate::for_plugin( $this ),
				function ( string $line ): void {
					$this->log_error( $line );
				},
				$this->account_write_gate()
			) )->register();

			Embedded_Screens::register( $this, $accounts, $wpdb );
			Site_Products_Module::register( $this, $accounts, $wpdb );
			Tools_Module::register( $this, $accounts );
			Diagnostics_Module::register( $this, $accounts );
			Site_Orders_Module::register( $this, $accounts, $wpdb );
		}

		/**
		 * The plugin's write gate: whether an account may write to the marketplace right now. The write runners (stock flush, price flush, export) ask it before they call out, after the licence and pause checks; reads and polls never do. An account it refuses is not claimed, and nothing is sent for it in that run. Return null, the default, to let every account write.
		 *
		 * It is read once, when {@see self::register_marketplace_flows()} wires the flows, so the callable itself must answer
		 * without a remote call or a query.
		 *
		 * @since 2.1.1
		 *
		 * @return callable|null `function ( Account $account ): bool`; true lets the account write, null lets every account write.
		 */
		protected function account_write_gate(): ?callable {
			return null;
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
		 * The pages besides «Настройки», in menu order. The default is the framework's five; a plugin overrides it to drop a page its
		 * marketplace cannot support, to add one, or to attach `legacy_page` URLs to a default (call the parent, then change the
		 * array). «Настройки» is not listed: the framework adds it, by itself, when the plugin has settings providers — first while
		 * {@see self::is_configured()} is false, last once it is true.
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
		 * Whether the plugin has an account it can work with: at least one account of its adapter whose required credentials — the
		 * identifier and every secret {@see Capabilities\Marketplace_Adapter::credential_fields()} declares — are all filled in.
		 * The merchant's on/off switch and the last health check are not part of it: a switched-off or failing account is
		 * configured, just not working, and says so on its own screen.
		 *
		 * The menu reads it to decide the order of its pages (see {@see self::build_menu()}), and the base plugin shows its
		 * "not configured" notice, which links to {@see self::get_settings_url()}, while it is false. It reads the accounts table once
		 * (a handful of rows, decrypted locally) and makes no remote call, so it is fit to run on every admin page.
		 * It is not memoised: the base class reads it again on `admin_footer`, after a settings save of the same request.
		 *
		 * @since 2.0.29
		 *
		 * @return bool
		 */
		public function is_configured(): bool {
			$adapter  = $this->get_marketplace_adapter();
			$fields   = $adapter->credential_fields();
			$required = array_merge( [ $fields->identifier() ], $fields->secrets() );

			foreach ( $this->get_account_store()->all( $adapter->id(), false ) as $account ) {
				$credentials = $account->credentials();

				foreach ( $required as $name ) {
					if ( '' === trim( (string) ( $credentials[ $name ] ?? '' ) ) ) {
						continue 2;
					}
				}

				return true;
			}

			return false;
		}

		/**
		 * The plugin's account store, the one {@see self::is_configured()} reads. The secrets are opened with the site's
		 * `secure_auth` salt, the key material {@see Secret_Cipher} names as its example; a plugin whose accounts are
		 * encrypted with another key overrides this one method.
		 *
		 * @since 2.0.29
		 *
		 * @return Account_Store
		 */
		protected function get_account_store(): Account_Store {
			global $wpdb;

			$adapter = $this->get_marketplace_adapter();

			return Wpdb_Account_Store::for_adapter(
				$wpdb,
				( new Schema( $adapter->id() ) )->table_name( 'accounts', $wpdb->prefix ),
				new Secret_Cipher( wp_salt( 'secure_auth' ) ),
				$adapter
			);
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
		 * Returns the default settings tab (`accounts`, optional `notifications`, `advanced`) placed on this plugin's own menu.
		 *
		 * @since 2.1.2
		 *
		 * @return Settings_Provider[]
		 */
		public function get_settings_providers(): array {
			$handler = $this->get_marketplace_settings_handler();

			return null === $handler ? [] : [ $handler->get_settings_provider() ];
		}

		/**
		 * Returns the default {@see Settings_Handler} built from the plugin's adapter and account store, or null when the
		 * account store is not {@see Wpdb_Account_Store}.
		 *
		 * @since 2.1.2
		 *
		 * @return Settings_Handler|null
		 */
		protected function get_marketplace_settings_handler(): ?Settings_Handler {
			if ( false === $this->settings_handler ) {
				$this->settings_handler = Settings_Module::handler_for( $this, $this->get_account_store() );
			}

			return $this->settings_handler;
		}

		/**
		 * Whether the menu gets a «Настройки» page.
		 *
		 * The default {@see self::get_settings_providers()} builds a handler that loads every account, so the menu, which is built
		 * on each `rest_api_init`, must not call it just to learn that there is a tab (#1727). For the default implementation the
		 * answer is whether the account store is the {@see Wpdb_Account_Store} that implementation needs. A plugin that overrides
		 * `get_settings_providers()` has its own providers to count, and they are asked.
		 *
		 * @since 2.1.2
		 *
		 * @return bool
		 */
		protected function has_settings_page(): bool {
			$declaring = ( new \ReflectionMethod( $this, 'get_settings_providers' ) )->getDeclaringClass()->getName();

			if ( self::class === $declaring ) {
				return $this->get_account_store() instanceof Wpdb_Account_Store;
			}

			return [] !== $this->get_settings_providers();
		}

		/**
		 * Whether the lifecycle has created the tables at least once. A plugin without the marketplace lifecycle counts as installed.
		 *
		 * @return bool
		 */
		private function is_schema_installed(): bool {
			$lifecycle = $this->get_lifecycle_handler();

			return ! $lifecycle instanceof Lifecycle || $lifecycle->is_installed();
		}

		/**
		 * Tells whether the current admin screen is this plugin's «Настройки» page under its own menu.
		 *
		 * @since 2.1.2
		 *
		 * @return bool
		 */
		public function is_plugin_settings() {
			if ( ! isset( $_GET['page'], $_GET['path'] ) || 'wc-admin' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reads current screen slug only.
				return false;
			}

			$menu = $this->get_marketplace_admin_menu();
			$page = null === $menu ? null : $menu->get_page( Page::SETTINGS_SLUG );

			return null !== $page && $menu->get_page_path( $page ) === $_GET['path']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		/**
		 * Adds tiered credential-expiry notices alongside base plugin notices for shop managers.
		 *
		 * @since 2.1.2
		 *
		 * @return void
		 */
		public function add_admin_notices() {
			parent::add_admin_notices();

			if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
				return;
			}

			Key_Expiry_Notices::for_plugin( $this, $this->get_account_store(), $this->get_marketplace_adapter() )->add();
		}

		/**
		 * Returns the reader for this plugin's «Логирование» toggle (`woodev_{plugin_id}_advanced_logging`).
		 *
		 * @since 2.1.2
		 *
		 * @return Logging
		 */
		public function logging(): Logging {
			return Logging::for_plugin( $this );
		}

		/**
		 * Builds the diagnostics {@see Report_Builder} for this plugin.
		 *
		 * @since 2.1.2
		 *
		 * @return Report_Builder
		 */
		public function diagnostics_report_builder(): Report_Builder {
			return Settings_Module::report_builder_for( $this, $this->get_account_store() );
		}

		/**
		 * Writes a failure at the ERROR level, whatever «Логирование» says, with credentials scrubbed out of the text.
		 *
		 * The inherited {@see self::log()} writes at WooCommerce's default NOTICE severity, which a shop that raised the logging
		 * threshold to «Error» silently drops — so a failure belongs here, as in {@see \Woodev\Framework\Shipping\Shipping_Plugin}.
		 *
		 * @since 2.1.2
		 *
		 * @param string      $message What failed.
		 * @param string|null $log_id  Optional log source, defaults to the plugin id.
		 * @return void
		 */
		public function log_error( $message, $log_id = null ): void {
			$this->log_at_level( 'error', (string) $message, $log_id );
		}

		/**
		 * Writes a debug line, scrubbed, ONLY while «Логирование» is on.
		 *
		 * @since 2.1.2
		 *
		 * @param string      $message Debug line.
		 * @param string|null $log_id  Optional log source, defaults to the plugin id.
		 * @return void
		 */
		public function log_debug( $message, $log_id = null ): void {
			if ( $this->logging()->is_enabled() ) {
				$this->log_at_level( 'debug', (string) $message, $log_id );
			}
		}

		/**
		 * Logs an API request and its response at the DEBUG level, only while «Логирование» is on.
		 *
		 * @since 2.1.2
		 *
		 * @param array<string,mixed> $request  Request data.
		 * @param array<string,mixed> $response Response data.
		 * @param string|null         $log_id   Optional log source.
		 * @return void
		 */
		public function log_api_request( $request, $response, $log_id = null ) {
			if ( ! $this->logging()->is_enabled() ) {
				return;
			}

			$this->log_debug( "Запрос\n" . $this->get_api_log_message( $request ), $log_id );

			if ( ! empty( $response ) ) {
				$this->log_debug( "Ответ\n" . $this->get_api_log_message( $response ), $log_id );
			}
		}

		/**
		 * Writes a line to the WooCommerce log at an explicit severity, keeping the log source id and scrubbing credentials.
		 *
		 * @param string      $level   A `WC_Log_Levels` level: `error`, `debug`.
		 * @param string      $message The line.
		 * @param string|null $log_id  Log source id, defaults to the plugin id.
		 * @return void
		 */
		private function log_at_level( string $level, string $message, $log_id ): void {
			$safe = Report_Redactor::redact_text( $message, $this->get_marketplace_adapter()->credential_fields()->secrets() );

			$this->logger()->log( $level, $safe, [ 'source' => is_string( $log_id ) && '' !== $log_id ? $log_id : $this->get_id() ] );
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
				$pages    = [];
				$settings = $this->has_settings_page() ? [ $this->build_settings_page() ] : [];

				foreach ( $this->get_marketplace_pages() as $descriptor ) {
					$pages[] = Page::from_array( (array) $descriptor );
				}

				// WordPress links the root to the first page of the menu: «Настройки» (onboarding) until the plugin is configured,
				// the working pages first once it is. Without a settings page the order cannot change, so the table is not read.
				if ( [] !== $settings ) {
					// A site whose tables were not created yet (a fresh activation, or a refused CREATE) is not configured, and the
					// accounts table is not read to find out (#1727).
					$configured = $this->is_schema_installed() && $this->is_configured();
					$pages      = $configured ? array_merge( $pages, $settings ) : array_merge( $settings, $pages );
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
