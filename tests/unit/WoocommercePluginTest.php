<?php
/**
 * WooCommerce plugin base tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

if ( ! class_exists( '\Woodev_Plugin', false ) ) {
	/**
	 * Minimal base plugin stub for isolated WooCommerce plugin tests.
	 */
	abstract class Woodev_Plugin_Test_Base {

		/** Framework version used by resolver tests after this stub is loaded. */
		public const VERSION = '2.0.0';
	}

	class_alias( Woodev_Plugin_Test_Base::class, 'Woodev_Plugin' );
}

require_once dirname( __DIR__, 2 ) . '/woodev/class-woocommerce-plugin.php';
require_once dirname( __DIR__, 2 ) . '/woodev/class-woocommerce-plugin-alias.php';

/**
 * Test helper exposing protected hook registration.
 */
class Testable_Woocommerce_Plugin extends \Woodev_Woocommerce_Plugin {

	/**
	 * No-op callback used for hook registration assertions.
	 *
	 * @return void
	 */
	public function handle_features_compatibility(): void {}

	/**
	 * No-op callback used for hook registration assertions.
	 *
	 * @return void
	 */
	public function add_class_form_wrap_start(): void {}

	/**
	 * No-op callback used for hook registration assertions.
	 *
	 * @return void
	 */
	public function add_class_form_wrap_end(): void {}

	/**
	 * No-op callback used for hook registration assertions.
	 *
	 * @param array<string,mixed> $rows System status rows.
	 * @return array<string,mixed>
	 */
	public function add_system_status_php_information( array $rows ): array {
		return $rows;
	}

	/**
	 * Exposes WooCommerce hook registration for assertions.
	 *
	 * @return void
	 */
	public function register_woocommerce_hooks(): void {
		$this->add_woocommerce_hooks();
	}
}

/**
 * Class WoocommercePluginTest
 */
class WoocommercePluginTest extends TestCase {

	/**
	 * WooCommerce runtime hooks should be owned by the WooCommerce plugin base.
	 */
	public function test_registers_woocommerce_runtime_hooks(): void {
		$plugin = new Testable_Woocommerce_Plugin();

		Actions\expectAdded( 'before_woocommerce_init' )
			->once()
			->with( [ $plugin, 'handle_features_compatibility' ] );

		foreach ( [ 'shipping', 'checkout', 'integration' ] as $tab ) {
			Actions\expectAdded( 'woocommerce_before_settings_' . $tab )
				->once()
				->with( [ $plugin, 'add_class_form_wrap_start' ] );

			Actions\expectAdded( 'woocommerce_after_settings_' . $tab )
				->once()
				->with( [ $plugin, 'add_class_form_wrap_end' ] );
		}

		Filters\expectAdded( 'woocommerce_system_status_environment_rows' )
			->once()
			->with( [ $plugin, 'add_system_status_php_information' ] );

		$plugin->register_woocommerce_hooks();
	}
}
