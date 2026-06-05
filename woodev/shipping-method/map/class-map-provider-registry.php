<?php
/**
 * Woodev Map Provider Registry
 *
 * Holds the set of available pickup-point map providers and resolves the one a
 * shipping plugin should use. Providers self-register (the Yandex provider ships
 * in the Yandex plugin); the framework only guarantees this registry seam plus
 * the Leaflet default returned by get_default().
 *
 * Pure PHP — no WooCommerce calls — so it stays unit-testable. See
 * docs-internal/platform-v2-s1-shipping-spec.md and decision §6a.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping\Map;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Map\\Map_Provider_Registry' ) ) :

	/**
	 * Registry of map providers.
	 *
	 * @since 1.5.0
	 */
	class Map_Provider_Registry {

		/** @var array<string, Map_Provider> registered providers keyed by id */
		private array $providers = [];

		/** @var Map_Provider|null cached framework default (Leaflet) */
		private ?Map_Provider $default = null;

		/**
		 * Registers a map provider.
		 *
		 * A provider registered under an existing id replaces the previous one,
		 * letting a host plugin override a bundled provider if needed.
		 *
		 * @since 1.5.0
		 *
		 * @param Map_Provider $provider provider to register
		 * @return void
		 */
		public function register( Map_Provider $provider ): void {
			$this->providers[ $provider->get_id() ] = $provider;
		}

		/**
		 * Gets a registered provider by id.
		 *
		 * @since 1.5.0
		 *
		 * @param string $id provider id
		 * @return Map_Provider|null the provider, or null when none is registered under $id
		 */
		public function get( string $id ): ?Map_Provider {
			return $this->providers[ $id ] ?? null;
		}

		/**
		 * Gets the framework default provider.
		 *
		 * Always the no-API-key Leaflet provider — the guaranteed fallback when a
		 * plugin configures no provider or its configured provider is absent.
		 *
		 * @since 1.5.0
		 *
		 * @return Map_Provider the Leaflet default
		 */
		public function get_default(): Map_Provider {

			if ( ! $this->default instanceof Map_Provider ) {
				$this->default = new Leaflet_Map_Provider();
			}

			return $this->default;
		}
	}

endif;
