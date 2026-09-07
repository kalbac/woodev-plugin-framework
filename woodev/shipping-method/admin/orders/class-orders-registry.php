<?php
/**
 * Shipping orders — registry
 *
 * @since 2.0.2
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Admin\Orders;

use Woodev\Framework\Settings\Settings_Page_Registry;
use Woodev\Framework\Shipping\Rest_Api\Orders_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Admin\\Orders\\Orders_Registry' ) ) :

	/**
	 * Singleton aggregator for the framework-owned «Заказы доставки» page (SP-10 spec D1).
	 *
	 * The exact structural mirror of {@see Settings_Page_Registry}: collects
	 * {@see Orders_Provider} descriptors registered by carrier plugins, registers the
	 * `woodev-shipping-orders` submenu under the existing `woodev` top-level menu only
	 * when at least one provider is present, registers the aggregated REST controller
	 * through {@see \Woodev_REST_V1_Registrar}, and enqueues the React shell on its own
	 * page hook (increment 2b, SP-10 spec D7).
	 *
	 * @since 2.0.2
	 */
	final class Orders_Registry {

		/** @var string admin page slug. */
		const PAGE_SLUG = 'woodev-shipping-orders';

		/** @var self|null singleton. */
		private static $instance = null;

		/** @var array<string, Orders_Provider> providers keyed by id. */
		private $providers = [];

		/** @var bool whether the shared hooks were added. */
		private $hooked = false;

		/**
		 * Any one registered plugin, to source the shared framework asset path and
		 * version from (increment 2b). @see self::register_provider().
		 *
		 * @since 2.0.2
		 *
		 * @var \Woodev_Plugin|null
		 */
		private $plugin;

		/**
		 * Returns the singleton.
		 *
		 * @since 2.0.2
		 *
		 * @return self
		 */
		public static function instance(): self {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Registers a carrier's descriptor.
		 *
		 * Keyed by id: registering a second provider under an id already in use replaces
		 * the first (last write wins) rather than silently producing two tabs for the
		 * same id.
		 *
		 * @since 2.0.2
		 *
		 * @param Orders_Provider     $provider carrier descriptor.
		 * @param \Woodev_Plugin|null $plugin  owning plugin, to source the shared framework
		 *                                     asset path/version from (increment 2b). Any one
		 *                                     registered plugin works — the framework copy is
		 *                                     identical across every plugin that vendors it,
		 *                                     the same assumption {@see Settings_Page_Registry::get_asset_plugin()}
		 *                                     already makes. The first plugin passed wins;
		 *                                     later calls (with or without one) do not replace it.
		 * @return void
		 */
		public function register_provider( Orders_Provider $provider, $plugin = null ): void {
			$this->providers[ $provider->get_id() ] = $provider;

			if ( null === $this->plugin && $plugin instanceof \Woodev_Plugin ) {
				$this->plugin = $plugin;
			}

			$this->add_hooks();
		}

		/**
		 * Returns every registered provider, filterable (#{@see 'woodev_shipping_orders_providers'}).
		 *
		 * Always leave this extension point even with no consumer yet — a plugin composing
		 * carriers dynamically (e.g. hiding one behind a feature flag) needs a seam that
		 * does not require touching the framework.
		 *
		 * @since 2.0.2
		 *
		 * @return Orders_Provider[]
		 */
		public function get_providers(): array {
			/**
			 * Filters the registered orders providers.
			 *
			 * @since 2.0.2
			 *
			 * @param Orders_Provider[] $providers providers keyed by id.
			 */
			$filtered = apply_filters( 'woodev_shipping_orders_providers', $this->providers );

			return is_array( $filtered ) ? $filtered : $this->providers;
		}

		/**
		 * Returns one provider by id, or null when unregistered.
		 *
		 * @since 2.0.2
		 *
		 * @param string $id carrier/tab id.
		 * @return Orders_Provider|null
		 */
		public function get_provider( string $id ): ?Orders_Provider {
			return $this->get_providers()[ $id ] ?? null;
		}

		/**
		 * Whether at least one provider is registered.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function has_providers(): bool {
			return [] !== $this->get_providers();
		}

		/**
		 * Returns the page/REST capability.
		 *
		 * Reuses {@see Settings_Page_Registry::resolve_capability()} rather than inventing a
		 * second capability rule (per the SP-10 spec's D1 mirror decision). `Orders_Provider`
		 * carries no per-provider capability override in this increment, so every provider is
		 * treated as WooCommerce-dependent — a shipping order is inherently a WC concept —
		 * which resolves to the single constant `manage_woocommerce`.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_page_capability(): string {
			return Settings_Page_Registry::resolve_capability( null, true );
		}

		/**
		 * Adds the shared menu / REST / CPT-query-translation hooks exactly once.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function add_hooks(): void {
			if ( $this->hooked ) {
				return;
			}
			$this->hooked = true;

			add_action( 'admin_menu', [ $this, 'register_page' ], 40 );
			add_action( 'rest_api_init', [ $this, 'register_rest' ], 5 );
			add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', [ $this, 'translate_marker_keys_query_var' ], 10, 2 );
		}

		/**
		 * Registers the «Заказы доставки» submenu when ≥1 provider is present.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function register_page(): void {
			if ( ! $this->has_providers() ) {
				return;
			}

			$hook = add_submenu_page(
				'woodev',
				__( 'Заказы доставки', 'woodev-plugin-framework' ),
				__( 'Заказы доставки', 'woodev-plugin-framework' ),
				$this->get_page_capability(),
				self::PAGE_SLUG,
				[ $this, 'render_page' ]
			);

			if ( $hook ) {
				add_action( "admin_print_scripts-{$hook}", [ $this, 'enqueue_assets' ] );
			}
		}

		/**
		 * Renders the wrapper + the React mount point.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function render_page(): void {
			echo '<div class="wrap woodev-shipping-orders-wrap">';
			echo '<h1 class="wp-heading-inline">' . esc_html__( 'Заказы доставки', 'woodev-plugin-framework' ) . '</h1>';
			echo '<hr class="wp-header-end">';
			echo '<div id="woodev-shipping-orders-app"></div>';
			echo '<noscript><p>' . esc_html__( 'Для страницы заказов нужен JavaScript. Включите его и обновите страницу.', 'woodev-plugin-framework' ) . '</p></noscript>';
			echo '</div>';
		}

		/**
		 * Enqueues the shipping-orders-page React bundle + inline bootstrap.
		 *
		 * Mirrors {@see Settings_Page_Registry::enqueue_assets()}. Rows are NOT
		 * inlined — the app fetches them from `GET woodev/v1/shipping/orders`
		 * (cap-filtered server-side, increment 1); what IS inlined is what the shell
		 * needs before its first fetch: the REST root, a nonce, and the provider list
		 * (id, label, and a count each tab can show immediately without first
		 * switching to it).
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function enqueue_assets(): void {
			$plugin = $this->get_asset_plugin();

			if ( ! $plugin ) {
				return;
			}

			$asset_file = $plugin->get_framework_path() . '/assets/build/shipping-orders-page/index.asset.php';

			if ( file_exists( $asset_file ) ) {
				$asset = include $asset_file;
			} else {
				error_log( sprintf( '[woodev] Shipping orders page asset manifest missing: %s', $asset_file ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for a missing build artifact.
				$asset = [
					'dependencies' => [],
					'version'      => $plugin->get_version(),
				];
			}

			$build_url     = $plugin->get_framework_assets_url() . '/build/shipping-orders-page';
			$style_path    = $plugin->get_framework_path() . '/assets/build/shipping-orders-page/style-index.css';
			$style_version = file_exists( $style_path ) ? (string) filemtime( $style_path ) : $asset['version'];

			wp_enqueue_style( 'wp-components' );
			wp_enqueue_style( 'woodev-shipping-orders-page', $build_url . '/style-index.css', [ 'wp-components' ], $style_version );
			wp_enqueue_script( 'woodev-shipping-orders-page', $build_url . '/index.js', $asset['dependencies'], $asset['version'], true );

			wp_add_inline_script(
				'woodev-shipping-orders-page',
				'window.woodevShippingOrders = ' . wp_json_encode(
					[
						'restRoot'  => esc_url_raw( rest_url( \Woodev_REST_V1_Registrar::ROUTE_NAMESPACE . '/shipping/orders' ) ),
						'nonce'     => wp_create_nonce( 'wp_rest' ),
						'adminUrl'  => esc_url_raw( admin_url() ),
						'providers' => $this->build_bootstrap_providers(),
					]
				) . ';',
				'before'
			);
		}

		/**
		 * Builds the inlined provider list: the aggregate entry first, then one per
		 * registered provider, each carrying a cheap `wc_get_orders()` count
		 * (`per_page => 1`, only `total` is read) so every tab can show a count
		 * before it is ever the active one — the aggregate tab's own count is this
		 * same mechanism, not a separate one.
		 *
		 * @since 2.0.2
		 *
		 * @return array<int,array{id:string,label:string,count:int}>
		 */
		private function build_bootstrap_providers(): array {
			$query = new Orders_Query( $this );

			$entries   = [];
			$entries[] = [
				'id'    => 'all',
				'label' => __( 'Все перевозчики', 'woodev-plugin-framework' ),
				'count' => (int) $query->get_results(
					[
						'carrier' => 'all',
						'per_page' => 1,
					]
				)->total,
			];

			foreach ( $this->get_providers() as $provider ) {
				$entries[] = [
					'id'    => $provider->get_id(),
					'label' => $provider->get_label(),
					'count' => (int) $query->get_results(
						[
							'carrier' => $provider->get_id(),
							'per_page' => 1,
						]
					)->total,
				];
			}

			return $entries;
		}

		/**
		 * Returns any registered plugin to source framework asset paths/version from.
		 *
		 * @since 2.0.2
		 *
		 * @return \Woodev_Plugin|null
		 */
		private function get_asset_plugin(): ?\Woodev_Plugin {
			return $this->plugin;
		}

		/**
		 * Registers the REST controller through the `woodev/v1` registrar.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function register_rest(): void {
			\Woodev_REST_V1_Registrar::register_controller( new Orders_Controller( $this ) );
		}

		/**
		 * Translates {@see Orders_Query::QUERY_VAR_MARKER_KEYS} into a real `meta_query`
		 * on the legacy CPT order datastore.
		 *
		 * On HPOS, {@see Orders_Query::build_args()} emits `meta_query` directly — measured
		 * correct against a real HPOS install (SP-10 spec M2). On the legacy CPT datastore
		 * WooCommerce's `WC_Order_Data_Store_CPT` does not support a `meta_query` arg at
		 * all: passing one fires `_doing_it_wrong` (WC ≥9.2) and silently returns
		 * UNFILTERED results — every carrier's orders leaking into every tab, the worst
		 * version of this bug because it fails open, not closed. All three shipped carrier
		 * plugins solve exactly this the same way — a custom query var, translated into
		 * `meta_query` through this exact filter — so this mirrors them instead of
		 * inventing a second mechanism.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $query      WP_Query-shaped args the CPT datastore is building.
		 * @param array<string,mixed> $query_vars the original wc_get_orders() args.
		 * @return array<string,mixed>
		 */
		public function translate_marker_keys_query_var( array $query, array $query_vars ): array {
			if ( ! array_key_exists( Orders_Query::QUERY_VAR_MARKER_KEYS, $query_vars ) ) {
				return $query;
			}

			$query['meta_query'] = Orders_Query::meta_query_for_keys( (array) $query_vars[ Orders_Query::QUERY_VAR_MARKER_KEYS ] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- translating the framework's own custom query var; the only CPT-safe way to scope this query (SP-10 round 2).

			/**
			 * Filters the CPT-datastore query after the marker-keys var is translated.
			 *
			 * @since 2.0.2
			 *
			 * @param array<string,mixed> $query      translated query.
			 * @param array<string,mixed> $query_vars the original wc_get_orders() args.
			 */
			$filtered = apply_filters( 'woodev_shipping_orders_cpt_query_args', $query, $query_vars );

			return is_array( $filtered ) ? $filtered : $query;
		}

		/**
		 * Resets registration state. Test-only.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function reset_for_tests(): void {
			remove_action( 'admin_menu', [ $this, 'register_page' ], 40 );
			remove_action( 'rest_api_init', [ $this, 'register_rest' ], 5 );
			remove_filter( 'woocommerce_order_data_store_cpt_get_orders_query', [ $this, 'translate_marker_keys_query_var' ], 10 );

			$this->providers = [];
			$this->hooked    = false;
			$this->plugin    = null;
		}
	}

endif;
