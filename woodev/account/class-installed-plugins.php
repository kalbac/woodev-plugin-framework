<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Installed_Plugins' ) ) :

	/**
	 * Pure collector of installed framework-plugin download ids.
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

			$ids = array();

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
	}

endif;
