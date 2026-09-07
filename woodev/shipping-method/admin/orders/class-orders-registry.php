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
	 * when at least one provider is present, and registers the aggregated REST
	 * controller through {@see \Woodev_REST_V1_Registrar}. Increment 1 only: no assets
	 * are enqueued and {@see self::render_page()} prints an empty mount point — the
	 * React shell is increment 2 (SP-10 spec D7).
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
		 * @param Orders_Provider $provider carrier descriptor.
		 * @return void
		 */
		public function register_provider( Orders_Provider $provider ): void {
			$this->providers[ $provider->get_id() ] = $provider;
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
		 * Adds the shared menu / REST hooks exactly once.
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

			add_submenu_page(
				'woodev',
				__( 'Заказы доставки', 'woodev-plugin-framework' ),
				__( 'Заказы доставки', 'woodev-plugin-framework' ),
				$this->get_page_capability(),
				self::PAGE_SLUG,
				[ $this, 'render_page' ]
			);
		}

		/**
		 * Renders the wrapper + an empty mount point.
		 *
		 * No assets are enqueued in this increment — the React shell lands in increment 2
		 * (SP-10 spec D7).
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function render_page(): void {
			echo '<div class="wrap woodev-shipping-orders-wrap">';
			echo '<div id="woodev-shipping-orders-app"></div>';
			echo '</div>';
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

			$this->providers = [];
			$this->hooked    = false;
		}
	}

endif;
