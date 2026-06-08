<?php
/**
 * Woodev Leaflet Map Provider
 *
 * Framework default Map_Provider: a no-API-key provider backed by Leaflet and
 * OpenStreetMap tiles. It is the guaranteed fallback so the shipping module can
 * always render a pickup-point map without any provider credentials.
 *
 * Thin descriptor only (decision §6a): it enqueues the Leaflet library, the
 * provider-agnostic map core and the Leaflet JS adapter, and describes the map
 * defaults. No markup is generated — the JS adapter does the rendering.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping\Map;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Map\\Leaflet_Map_Provider' ) ) :

	/**
	 * Leaflet (OpenStreetMap) map provider — the framework default.
	 *
	 * @since 1.5.0
	 */
	class Leaflet_Map_Provider implements Map_Provider {

		/** @var string provider identifier */
		private const ID = 'leaflet';

		/** @var string registered handle of the Leaflet JS adapter */
		private const ADAPTER_HANDLE = 'woodev-pickup-map-adapter-leaflet';

		/** @var string registered handle of the provider-agnostic map core */
		private const CORE_HANDLE = 'woodev-pickup-map';

		/** @var string registered handle of the Leaflet library */
		private const LIBRARY_HANDLE = 'woodev-leaflet';

		/** @var string Leaflet library version served from the public CDN */
		private const LIBRARY_VERSION = '1.9.4';

		/**
		 * Gets the provider id.
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		public function get_id(): string {
			return self::ID;
		}

		/**
		 * Enqueues Leaflet, the map core and the Leaflet adapter.
		 *
		 * @since 1.5.0
		 *
		 * @return void
		 */
		public function enqueue_assets(): void {

			$version = $this->get_assets_version();

			// Leaflet library (no API key required) from the public CDN.
			wp_enqueue_style(
				self::LIBRARY_HANDLE,
				'https://unpkg.com/leaflet@' . self::LIBRARY_VERSION . '/dist/leaflet.css',
				[],
				self::LIBRARY_VERSION
			);

			wp_enqueue_script(
				self::LIBRARY_HANDLE,
				'https://unpkg.com/leaflet@' . self::LIBRARY_VERSION . '/dist/leaflet.js',
				[],
				self::LIBRARY_VERSION,
				true
			);

			// Provider-agnostic map shell and core.
			wp_enqueue_style( self::CORE_HANDLE, self::get_asset_url( 'css/frontend/pickup-map.css' ), [], $version );

			wp_enqueue_script(
				self::CORE_HANDLE,
				self::get_asset_url( 'js/frontend/pickup-map.js' ),
				[ 'jquery' ],
				$version,
				true
			);

			// Leaflet adapter implementing the MapAdapter contract.
			wp_enqueue_script(
				self::ADAPTER_HANDLE,
				self::get_asset_url( 'js/frontend/map-adapter-leaflet.js' ),
				[ 'jquery', self::LIBRARY_HANDLE, self::CORE_HANDLE ],
				$version,
				true
			);
		}

		/**
		 * Gets the Leaflet provider settings fields.
		 *
		 * Leaflet over OpenStreetMap needs no credentials, so no API-key field is
		 * exposed; the provider contributes no extra settings.
		 *
		 * @since 1.5.0
		 *
		 * @return array always empty
		 */
		public function get_settings_fields(): array {
			return [];
		}

		/**
		 * Gets the Leaflet JS adapter handle.
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		public function get_js_adapter_handle(): string {
			return self::ADAPTER_HANDLE;
		}

		/**
		 * Gets the default Leaflet map configuration.
		 *
		 * Mirrors the adapter's own defaults (Moscow centre, OpenStreetMap
		 * tiles); host plugins may override any key. Contains no API key.
		 *
		 * @since 1.5.0
		 *
		 * @return array
		 */
		public function get_localized_config(): array {
			return [
				'center'      => [ 55.751244, 37.618423 ],
				'zoom'        => 10,
				'tileUrl'     => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
				'tileOptions' => [
					'maxZoom'     => 19,
					'attribution' => '© OpenStreetMap',
				],
			];
		}

		/**
		 * Resolves a URL within the shipping-framework assets directory.
		 *
		 * The map providers live in map/, a direct child of the shipping-method
		 * root; assets/ is that root's sibling. Resolving from this file's
		 * location keeps the provider self-contained — it needs no plugin
		 * instance to locate its assets.
		 *
		 * @since 1.5.0
		 *
		 * @param string $relative path relative to the assets directory
		 * @return string absolute URL to the asset
		 */
		private static function get_asset_url( string $relative ): string {
			$file = dirname( __DIR__ ) . '/assets/' . ltrim( $relative, '/' );

			return plugins_url( basename( $file ), $file );
		}

		/**
		 * Gets the version string used for asset cache-busting.
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		private static function get_assets_version(): string {
			return (string) \Woodev_Plugin::VERSION;
		}
	}

endif;
