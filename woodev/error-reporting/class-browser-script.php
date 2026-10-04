<?php
/**
 * The browser reporter script and its config.
 *
 * @package Woodev\Framework\Error_Reporting
 */

namespace Woodev\Framework\Error_Reporting;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\Woodev\Framework\Error_Reporting\Browser_Script' ) ) :

	/**
	 * Enqueues `woodev-error-reporter.js` (#1081, D7) — called only while reporting is active.
	 *
	 * The script sits in the `<head>` so it is listening before any of our other scripts runs. Its config
	 * is the REST endpoint, a `wp_rest` nonce and the asset base URL of every registered plugin (the
	 * browser filters its own errors by them). The DSN is never part of it.
	 *
	 * @since 2.0.2
	 */
	final class Browser_Script {

		/** Script handle (and the config global's owner). */
		const HANDLE = 'woodev-error-reporter';

		/** Name of the config global. */
		const GLOBAL = 'woodevErrorReporting';

		/**
		 * @since 2.0.2
		 *
		 * @param Plugin_Scope $scope The registered plugins.
		 * @return void
		 */
		public static function enqueue( Plugin_Scope $scope ): void {
			$bases = $scope->url_bases();

			if ( [] === $bases ) {
				return;
			}

			$framework_dir = dirname( __DIR__ );
			$relative      = 'assets/js/frontend/woodev-error-reporter.js';
			$path          = $framework_dir . '/' . $relative;

			wp_register_script(
				self::HANDLE,
				plugins_url( $relative, $framework_dir . '/bootstrap.php' ),
				[],
				file_exists( $path ) ? (string) filemtime( $path ) : '2.0.2',
				false
			);

			wp_localize_script(
				self::HANDLE,
				self::GLOBAL,
				[
					'endpoint' => rest_url( trim( \Woodev_REST_V1_Registrar::ROUTE_NAMESPACE . Browser_Rest_Controller::ROUTE, '/' ) ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'bases'    => $bases,
				]
			);

			wp_enqueue_script( self::HANDLE );
		}
	}

endif;
