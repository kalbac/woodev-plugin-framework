<?php
/**
 * Unit: which shipping rate gets the additional-info card, and where its stylesheet loads (card #1052).
 *
 * `Shipping_Plugin::is_rate_selected()` is the whole server-side "only for the chosen method" rule, kept
 * pure on purpose so it is tested without WooCommerce (and without a process-wide `WC()` stub, which
 * would leak into every later test class — see the Brain Monkey gotcha in BackgroundJobHandlerTest).
 *
 * @package Woodev\Tests\Unit\Shipping
 */

namespace Woodev\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Shipping_Plugin;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 3 ) . '/woodev/class-woocommerce-plugin.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/class-shipping-plugin.php';

/**
 * A carrier plugin that registers nothing — only its (unconstructed) class is reflected on.
 */
class Additional_Info_Plugin extends Shipping_Plugin {

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
		return 'Additional info plugin';
	}

	public function get_download_id() {
		return 0;
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::is_rate_selected
 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::enqueue_additional_info_styles
 */
final class ShippingPluginAdditionalInfoTest extends TestCase {

	public function test_the_chosen_rate_is_selected(): void {
		$this->assertTrue( Shipping_Plugin::is_rate_selected( 'edostavka:7', 0, [ 'edostavka:7' ], 3 ) );
	}

	public function test_another_rate_of_the_same_package_is_not_selected(): void {
		$this->assertFalse( Shipping_Plugin::is_rate_selected( 'edostavka:8', 0, [ 'edostavka:7' ], 3 ) );
	}

	public function test_a_lone_rate_is_always_selected_even_when_the_session_names_another(): void {
		// WooCommerce prints a lone rate as a hidden input, never a radio: nothing to choose.
		$this->assertTrue( Shipping_Plugin::is_rate_selected( 'edostavka:8', 0, [ 'edostavka:7' ], 1 ) );
		$this->assertTrue( Shipping_Plugin::is_rate_selected( 'edostavka:8', 0, [], 1 ) );
	}

	public function test_without_a_session_the_card_is_shown(): void {
		$this->assertTrue( Shipping_Plugin::is_rate_selected( 'edostavka:8', 0, null, 3 ) );
	}

	public function test_an_unknown_rate_count_shows_the_card(): void {
		$this->assertTrue( Shipping_Plugin::is_rate_selected( 'edostavka:8', 0, [ 'edostavka:7' ], 0 ) );
	}

	public function test_several_rates_and_nothing_chosen_shows_no_card(): void {
		$this->assertFalse( Shipping_Plugin::is_rate_selected( 'edostavka:7', 0, [], 3 ) );
		$this->assertFalse( Shipping_Plugin::is_rate_selected( 'edostavka:7', 0, [ 1 => 'edostavka:7' ], 3 ), 'The choice of package 1 says nothing about package 0.' );
	}

	public function test_each_package_is_judged_by_its_own_choice(): void {
		$chosen = [ 'flat_rate:1', 'edostavka:7' ];

		$this->assertTrue( Shipping_Plugin::is_rate_selected( 'edostavka:7', 1, $chosen, 2 ) );
		$this->assertFalse( Shipping_Plugin::is_rate_selected( 'edostavka:7', 0, $chosen, 2 ) );
	}

	public function test_the_stylesheet_is_not_enqueued_off_the_checkout_and_cart(): void {
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\expect( 'wp_enqueue_style' )->never();

		$this->plugin_without_constructor()->enqueue_additional_info_styles();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @dataProvider provide_pages_that_print_the_block
	 */
	public function test_the_stylesheet_is_enqueued_on_the_checkout_and_cart( bool $checkout, bool $cart ): void {
		Functions\when( 'is_checkout' )->justReturn( $checkout );
		Functions\when( 'is_cart' )->justReturn( $cart );
		Functions\when( 'plugins_url' )->alias( static fn( $path ) => 'https://example.test/' . $path );

		Functions\expect( 'wp_enqueue_style' )
			->once()
			->with(
				'woodev-shipping-additional-info',
				'https://example.test/additional-info.css',
				[],
				\Mockery::pattern( '/^\d+$/' )
			);

		$this->plugin_without_constructor()->enqueue_additional_info_styles();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @return array<string,array{0:bool,1:bool}>
	 */
	public static function provide_pages_that_print_the_block(): array {
		return [
			'checkout' => [ true, false ],
			'cart'     => [ false, true ],
		];
	}

	private function plugin_without_constructor(): Additional_Info_Plugin {
		return ( new \ReflectionClass( Additional_Info_Plugin::class ) )->newInstanceWithoutConstructor();
	}
}
