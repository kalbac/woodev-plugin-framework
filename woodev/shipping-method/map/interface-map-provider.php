<?php
/**
 * Woodev Map Provider Interface
 *
 * Defines the pluggable seam for pickup-point (PVZ) map rendering. Per decision
 * §6a the real provider boundary lives in the JS adapter contract (see
 * assets/js/frontend/pickup-map.js); the PHP side is a thin descriptor that
 * only declares which JS adapter to use, enqueues the provider's assets, and
 * supplies the config the adapter consumes at runtime.
 *
 * No markup is produced here — rendering is the JS adapter's responsibility. A
 * provider that needs an API key (e.g. Yandex.Maps) ships in the plugin that
 * holds the key and self-registers; the framework guarantees only this seam and
 * the no-API-key Leaflet default.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping\Map;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! interface_exists( '\\Woodev\\Framework\\Shipping\\Map\\Map_Provider' ) ) :

	/**
	 * Pickup-point map provider contract.
	 *
	 * Implementations wrap a map library (Leaflet by default, Yandex.Maps in the
	 * Yandex plugin) and describe to PHP how to load it. All actual rendering and
	 * interaction happens in the JS adapter identified by get_js_adapter_handle().
	 *
	 * @since 1.5.0
	 */
	interface Map_Provider {

		/**
		 * Gets the provider's unique identifier.
		 *
		 * Used as the registry key and to select a provider from plugin
		 * configuration (e.g. 'leaflet', 'yandex').
		 *
		 * @since 1.5.0
		 *
		 * @return string provider id
		 */
		public function get_id(): string;

		/**
		 * Enqueues the provider's frontend assets.
		 *
		 * Registers and enqueues the map library together with the JS adapter
		 * that implements the MapAdapter contract. Produces no markup.
		 *
		 * @since 1.5.0
		 *
		 * @return void
		 */
		public function enqueue_assets(): void;

		/**
		 * Gets the provider-specific settings fields.
		 *
		 * Returned in WooCommerce settings-field format for merging into the
		 * shipping integration settings. A no-API-key provider returns no
		 * credential fields.
		 *
		 * @since 1.5.0
		 *
		 * @return array settings field definitions keyed by field id
		 */
		public function get_settings_fields(): array;

		/**
		 * Gets the registered handle of the JS adapter script.
		 *
		 * The provider-agnostic map core instantiates the adapter exposed under
		 * this handle; it must match a handle enqueued by enqueue_assets().
		 *
		 * @since 1.5.0
		 *
		 * @return string script handle
		 */
		public function get_js_adapter_handle(): string;

		/**
		 * Gets the configuration handed to the JS adapter at runtime.
		 *
		 * Provider-agnostic map options (center, zoom, tiles/API key) consumed by
		 * the adapter's init(). Carries no installed-site contract data — AJAX
		 * action names, nonces and the like are merged in by the host plugin.
		 *
		 * @since 1.5.0
		 *
		 * @return array localized adapter configuration
		 */
		public function get_localized_config(): array;
	}

endif;
