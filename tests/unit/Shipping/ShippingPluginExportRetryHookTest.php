<?php
/**
 * Unit: `Shipping_Plugin::add_hooks()` binds the delayed-export-retry action to the ONE registry callback,
 * whichever shipping plugin runs it (card #1009).
 *
 * WordPress's `add_action()` ignores a second identical callback, so «one callback however many carrier
 * plugins are loaded» holds exactly as long as EVERY plugin hands it the SAME callable — the
 * `Orders_Registry` singleton's `run_export_retry`, never one of the plugin's own. This test pins that
 * half with the real (private) `add_hooks()` of two plugins; the integration tier's
 * `ExportRetryHookBoundOnceTest` pins the other half — that the real `$wp_filter` then holds one.
 *
 * @package Woodev\Tests\Unit\Shipping
 */

namespace Woodev\Tests\Unit\Shipping;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Export_Retry;
use Woodev\Framework\Shipping\Shipping_Plugin;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 3 ) . '/woodev/class-woocommerce-plugin.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/class-shipping-plugin.php';

/**
 * A carrier plugin that registers nothing of its own — never constructed, only reflected on.
 */
class Export_Retry_Hook_Plugin_A extends Shipping_Plugin {

	protected function get_shipping_method_classes(): array {
		return [];
	}

	public function get_api(): ?\Woodev\Framework\Shipping\Api\Shipping_API {
		return null;
	}

	protected function get_file() {
		return __FILE__;
	}

	public function get_plugin_name() {
		return 'Export retry hook plugin A';
	}

	public function get_download_id() {
		return 0;
	}
}

/**
 * The second carrier plugin of the same process.
 */
class Export_Retry_Hook_Plugin_B extends Export_Retry_Hook_Plugin_A {

	public function get_plugin_name() {
		return 'Export retry hook plugin B';
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::add_hooks
 */
final class ShippingPluginExportRetryHookTest extends TestCase {

	/** @var array<int,array{0:string,1:mixed}> every `add_action()` call the plugins made: hook, callback. */
	private array $added = [];

	protected function setUp(): void {
		parent::setUp();

		$this->added = [];

		Functions\stubs( [ 'add_filter', 'wp_parse_args' ] );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback ) {
				$this->added[] = [ $hook, $callback ];

				return true;
			}
		);
	}

	private function run_add_hooks( Shipping_Plugin $plugin ): void {
		$add_hooks = new \ReflectionMethod( Shipping_Plugin::class, 'add_hooks' );
		if ( PHP_VERSION_ID < 80100 ) {
			$add_hooks->setAccessible( true );
		}
		$add_hooks->invoke( $plugin );
	}

	/** @return array<int,mixed> the callbacks bound to the retry hook, in call order. */
	private function retry_callbacks(): array {
		$callbacks = [];

		foreach ( $this->added as [ $hook, $callback ] ) {
			if ( Export_Retry::HOOK === $hook ) {
				$callbacks[] = $callback;
			}
		}

		return $callbacks;
	}

	public function test_two_plugins_bind_the_retry_hook_to_the_same_registry_callback(): void {
		foreach ( [ Export_Retry_Hook_Plugin_A::class, Export_Retry_Hook_Plugin_B::class ] as $class ) {
			$this->run_add_hooks( ( new \ReflectionClass( $class ) )->newInstanceWithoutConstructor() );
		}

		$callbacks = $this->retry_callbacks();

		$this->assertCount( 2, $callbacks, 'each plugin wires the hook in its own request' );
		$this->assertSame( [ Orders_Registry::instance(), 'run_export_retry' ], $callbacks[0] );
		$this->assertSame( $callbacks[0], $callbacks[1], 'the SAME callable, or add_action() would keep both and every retry would run twice' );
	}
}
