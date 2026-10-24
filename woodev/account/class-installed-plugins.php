<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Installed_Plugins' ) ) :

	/**
	 * Pure collectors of framework-plugin download ids from instances and resolver records.
	 *
	 * Maps active plugin instances (each a singleton exposing get_download_id()) to a
	 * deduped list of positive integer EDD download ids — the «installed» set the
	 * «Плагины» catalog uses to badge already-installed products. PURE: depends only
	 * on the passed instances, so it is unit-tested with stubs and the live wiring
	 * just feeds it the bootstrap's resolved instances.
	 *
	 * @since 2.0.2
	 */
	final class Woodev_Installed_Plugins {

		/**
		 * Deduped positive-integer download ids from a list of plugin instances.
		 *
		 * @since 2.0.2
		 *
		 * @param array<int,mixed> $plugins Plugin instances (objects with get_download_id()).
		 *
		 * @return array<int,int> Deduped, order-preserving list of download ids.
		 */
		public static function download_ids( array $plugins ): array {

			$ids = [];

			foreach ( $plugins as $plugin ) {

				if ( ! is_object( $plugin ) || ! method_exists( $plugin, 'get_download_id' ) ) {
					continue;
				}

				$id = (int) $plugin->get_download_id();

				if ( $id > 0 ) {
					$ids[ $id ] = $id; // keyed by id → dedup; insertion order preserved.
				}
			}

			return array_values( $ids );
		}

		/**
		 * Collects download ids from loader records for plugins refused at bootstrap.
		 *
		 * @since 2.0.12
		 *
		 * @param array<int,mixed> $plugins Resolver plugin records.
		 *
		 * @return array<int,int> Deduped positive download ids.
		 */
		public static function download_ids_from_records( array $plugins ): array {
			$ids = [];

			foreach ( $plugins as $plugin ) {
				$definition = is_array( $plugin ) ? ( $plugin['definition'] ?? null ) : null;

				if ( ! is_object( $definition ) || ! method_exists( $definition, 'get_download_id' ) ) {
					continue;
				}

				$id = (int) $definition->get_download_id();

				if ( $id > 0 ) {
					$ids[ $id ] = $id;
				}
			}

			return array_values( $ids );
		}

		/**
		 * Collects store ids from legacy v1 tombstone records by known plugin folder.
		 *
		 * The v1 tombstone records contain only `plugin_name` and the main-file `path`.
		 * The store catalog does not expose plugin folders, so known folder names are
		 * mapped to download ids here. Unknown folders are ignored.
		 *
		 * @since 2.0.12
		 *
		 * @param array<int,mixed> $plugins Mixed-fleet tombstone records.
		 * @return array<int,int> Deduped positive download ids.
		 */
		public static function download_ids_from_mixed_fleet_records( array $plugins ): array {
			$product_ids = [
				'woocommerce-edostavka'       => 216,
				'woocommerce-yandex-delivery' => 821,
				'woodev-russian-post'         => 8772,
			];
			$ids = [];

			foreach ( $plugins as $plugin ) {
				if ( ! is_array( $plugin ) || empty( $plugin['path'] ) || ! is_string( $plugin['path'] ) ) {
					continue;
				}

				$path   = $plugin['path'];
				$folder = basename( dirname( $path ) );
				$id     = $product_ids[ $folder ] ?? 0;

				if ( $id > 0 ) {
					$ids[ $id ] = $id;
				}
			}

			return array_values( $ids );
		}
	}

endif;
