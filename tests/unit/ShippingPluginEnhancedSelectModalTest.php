<?php
/**
 * Unit: Shipping_Plugin::enqueue_instance_form_script() also re-fires WooCommerce's
 * `wc-enhanced-select-init` when a shipping-method modal opens (s158 FW-E), so AJAX-searchable
 * selects in a carrier method's form become usable. The script is added on the shipping-settings
 * screen only, and once even with several carrier plugins active.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Shipping_Plugin;

class ShippingPluginEnhancedSelectModalTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$_GET = [];
	}

	protected function tearDown(): void {
		$_GET = [];
		parent::tearDown();
	}

	private function plugin(): Shipping_Plugin {
		return Mockery::mock( Shipping_Plugin::class )->makePartial();
	}

	public function test_inline_script_is_registered_on_the_shipping_settings_screen(): void {
		$_GET = [
			'page' => 'wc-settings',
			'tab'  => 'shipping',
		];

		Functions\when( 'wp_script_is' )->justReturn( false );
		Functions\when( 'plugins_url' )->justReturn( 'https://example.com/instance-field-conditions.js' );
		// the city limit's own bundle (#1176) looks for its build next to the framework copy; there is none here, so it enqueues nothing
		Functions\when( 'plugin_dir_path' )->justReturn( '/nonexistent-framework-copy/' );
		Functions\expect( 'wp_enqueue_script' )->once()->with( 'woodev-instance-field-conditions', Mockery::any(), [ 'jquery' ], Mockery::any(), true );

		$captured = null;
		Functions\expect( 'wp_add_inline_script' )
			->once()
			->andReturnUsing(
				static function ( $handle, $code ) use ( &$captured ) {
					$captured = [ $handle, $code ];
					return true;
				}
			);

		$this->plugin()->enqueue_instance_form_script();

		$this->assertNotNull( $captured );
		$this->assertSame( 'woodev-instance-field-conditions', $captured[0] );
		$this->assertStringContainsString( 'wc_backbone_modal_loaded', $captured[1] );
		$this->assertStringContainsString( 'wc-enhanced-select-init', $captured[1] );
	}

	public function test_nothing_is_added_when_another_carrier_plugin_already_enqueued_the_script(): void {
		$_GET = [
			'page' => 'wc-settings',
			'tab'  => 'shipping',
		];

		Functions\when( 'wp_script_is' )->justReturn( true );
		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_add_inline_script' )->never();

		$this->plugin()->enqueue_instance_form_script();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @dataProvider other_screens
	 *
	 * @param array<string,string> $get Query string of a screen that is not the shipping settings.
	 */
	public function test_nothing_is_added_elsewhere( array $get ): void {
		$_GET = $get;

		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_add_inline_script' )->never();

		$this->plugin()->enqueue_instance_form_script();

		$this->addToAssertionCount( 1 );
	}

	/** @return array<string,array{0:array<string,string>}> */
	public function other_screens(): array {
		return [
			'no query'          => [ [] ],
			'other wc tab'      => [ [ 'page' => 'wc-settings', 'tab' => 'general' ] ],
			'other admin page'  => [ [ 'page' => 'woodev', 'tab' => 'shipping' ] ],
		];
	}
}
