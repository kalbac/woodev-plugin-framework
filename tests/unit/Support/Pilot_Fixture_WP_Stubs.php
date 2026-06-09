<?php
/**
 * Shared WordPress/WooCommerce stubs for the pilot-fixture unit tests.
 *
 * @package Woodev\Tests\Unit\Support
 */

namespace Woodev\Tests\Unit\Support;

use Brain\Monkey\Functions;

/**
 * Provides the WordPress runtime function stubs and the idempotent WC/REST
 * class-alias stub installer that each pilot-fixture test previously copied.
 *
 * Both helpers are safe to run repeatedly: the function stubs are re-declared
 * per test by Brain Monkey, and every class stub is guarded by
 * `class_exists( ..., false )` so re-use across test classes is a no-op.
 */
trait Pilot_Fixture_WP_Stubs {

	/**
	 * Defines WordPress function stubs required by isolated plugin construction.
	 *
	 * @param bool $stub_add_filter Whether to stub `add_filter` as a no-op returning
	 *                              true. Fixtures that assert on `add_filter` set up
	 *                              their own expectation instead and pass false.
	 * @return void
	 */
	private function mock_wordpress_runtime_functions( bool $stub_add_filter = false ): void {
		Functions\when( 'wp_parse_args' )->alias(
			static function ( array $args, array $defaults ): array {
				return array_replace_recursive( $defaults, $args );
			}
		);
		Functions\when( 'plugin_dir_path' )->alias(
			static function ( string $file ): string {
				return rtrim( dirname( $file ), '/\\' ) . '/';
			}
		);
		Functions\when( 'plugin_basename' )->returnArg();
		Functions\when( 'trailingslashit' )->alias(
			static function ( string $path ): string {
				return rtrim( $path, '/\\' ) . '/';
			}
		);
		Functions\when( 'untrailingslashit' )->alias(
			static function ( string $path ): string {
				return rtrim( $path, '/\\' );
			}
		);
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'has_action' )->justReturn( false );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'get_option' )->returnArg( 2 );
		Functions\when( 'is_multisite' )->justReturn( false );

		if ( $stub_add_filter ) {
			Functions\when( 'add_filter' )->justReturn( true );
		}
	}

	/**
	 * Installs minimal WooCommerce/WordPress base-class stubs for isolated unit
	 * construction. Each stub is an empty class aliased to the canonical name and
	 * guarded so it is declared at most once per PHP process.
	 *
	 * @return void
	 */
	private function install_woocommerce_class_stubs(): void {
		if ( ! class_exists( 'WP_REST_Controller', false ) ) {
			class_alias( get_class( new class {} ), 'WP_REST_Controller' );
		}
		if ( ! class_exists( 'WC_Shipping_Method', false ) ) {
			class_alias( get_class( new class {} ), 'WC_Shipping_Method' );
		}
		if ( ! class_exists( 'WC_Integration', false ) ) {
			class_alias( get_class( new class {} ), 'WC_Integration' );
		}
		if ( ! class_exists( 'WC_Payment_Gateway', false ) ) {
			class_alias( get_class( new class {} ), 'WC_Payment_Gateway' );
		}
	}
}
