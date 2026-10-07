<?php
/**
 * Unit: «Логирование» and «Не показывать на странице корзины» as the plugin applies them (s158).
 *
 * Logging off = only errors reach the log; on = also debug lines and the carrier API requests/responses. The
 * cart option removes this carrier's rates while the cart page is shown — on `woocommerce_package_rates`, never
 * in `calculate_shipping()`, because WooCommerce reuses the cart page's session-cached rates at checkout.
 *
 * @package Woodev\Tests\Unit\Shipping
 */

namespace Woodev\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Settings\Advanced_Settings;
use Woodev\Framework\Shipping\Shipping_Plugin;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/class-setting.php';
require_once dirname( __DIR__, 3 ) . '/woodev/settings-api/abstract-class-settings.php';
require_once dirname( __DIR__, 3 ) . '/woodev/shipping-method/settings/class-advanced-settings.php';

/**
 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::is_debug_enabled
 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::log_debug
 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::log_api_request
 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::hide_rates_on_cart_page
 */
final class ShippingPluginLoggingAndCartTest extends TestCase {

	/** @var array<string,mixed> the `wp_options` table, in memory. */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();

		$this->options = [];

		Functions\when( 'get_option' )->alias( fn( string $name, $default = false ) => array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults = [] ) => array_merge( (array) $defaults, (array) $args ) );
		Functions\when( 'wc_string_to_bool' )->alias( static fn( $value ) => in_array( strtolower( (string) $value ), [ 'yes', 'true', '1' ], true ) );
	}

	/**
	 * @param bool $logging  «Логирование»
	 * @param bool $on_cart  «Не показывать на странице корзины»
	 * @return Shipping_Plugin&\Mockery\MockInterface
	 */
	private function plugin( bool $logging = false, bool $on_cart = false ) {
		$this->options['woodev_cdek_advanced_enable_debug']           = $logging ? 'yes' : 'no';
		$this->options['woodev_cdek_advanced_disable_methods_on_cart'] = $on_cart ? 'yes' : 'no';

		$plugin = Mockery::mock( Shipping_Plugin::class )->makePartial()->shouldAllowMockingProtectedMethods();
		$plugin->shouldReceive( 'get_advanced_settings' )->andReturnUsing( static fn() => new Advanced_Settings( 'cdek' ) );

		$property = new \ReflectionProperty( Shipping_Plugin::class, 'methods' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( $plugin, [ 'cdek_courier' => 'Courier_Class' ] );

		return $plugin;
	}

	// ----- logging -----

	public function test_the_switch_is_the_one_stored_key_and_wp_debug_does_not_turn_it_on(): void {
		$this->assertFalse( $this->plugin( false )->is_debug_enabled() );
		$this->assertTrue( $this->plugin( true )->is_debug_enabled() );
		$this->assertArrayHasKey( 'woodev_cdek_advanced_enable_debug', $this->options );
	}

	public function test_logging_off_writes_no_debug_line(): void {
		$plugin = $this->plugin( false );
		$plugin->shouldReceive( 'log' )->never();

		$plugin->log_debug( 'a debug line' );

		$this->addToAssertionCount( 1 );
	}

	public function test_logging_on_writes_the_debug_line_to_the_given_log(): void {
		$plugin = $this->plugin( true );
		$plugin->shouldReceive( 'log' )->once()->with( 'a debug line', 'cdek_courier' );

		$plugin->log_debug( 'a debug line', 'cdek_courier' );

		$this->addToAssertionCount( 1 );
	}

	public function test_an_error_is_logged_whatever_the_switch_says(): void {
		$logger = Mockery::mock( '\WC_Logger_Interface' );
		$logger->shouldReceive( 'add' )->once()->with( 'cdek', 'something failed' );
		Functions\when( 'wc_get_logger' )->justReturn( $logger );

		$plugin = $this->plugin( false );
		$plugin->shouldReceive( 'get_id' )->andReturn( 'cdek' );

		$plugin->log( 'something failed' );

		$this->addToAssertionCount( 1 );
	}

	public function test_carrier_api_requests_and_responses_are_logged_only_with_logging_on(): void {
		$off = $this->plugin( false );
		$off->shouldReceive( 'log' )->never();
		$off->log_api_request( [ 'uri' => 'https://api.example.test' ], [ 'code' => 200 ] );

		$on = $this->plugin( true );
		$on->shouldReceive( 'log' )->twice();
		$on->log_api_request( [ 'uri' => 'https://api.example.test' ], [ 'code' => 200 ] );

		$this->addToAssertionCount( 1 );
	}

	// ----- hide on the cart page -----

	/** @return \WC_Shipping_Rate&\Mockery\MockInterface */
	private function rate( string $method_id ) {
		$rate = Mockery::mock( '\WC_Shipping_Rate' );
		$rate->shouldReceive( 'get_method_id' )->andReturn( $method_id );

		return $rate;
	}

	/**
	 * Runs isolated: it defines `is_cart()` / `WC()` as real functions, and a later test's `function_exists()` must not see them.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_on_the_cart_page_with_the_option_on_this_carriers_rates_are_removed_and_others_stay(): void {
		Functions\when( 'is_cart' )->justReturn( true );

		$ours   = $this->rate( 'cdek_courier' );
		$theirs = $this->rate( 'flat_rate' );

		$rates = $this->plugin( false, true )->hide_rates_on_cart_page( [ 'cdek_courier:3' => $ours, 'flat_rate:1' => $theirs ] );

		$this->assertSame( [ 'flat_rate:1' => $theirs ], $rates );
	}

	/**
	 * Runs isolated: it defines `is_cart()` / `WC()` as real functions, and a later test's `function_exists()` must not see them.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_at_checkout_the_rates_stay_even_with_the_option_on(): void {
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'WC' )->justReturn( new \stdClass() );

		$ours = $this->rate( 'cdek_courier' );

		$this->assertSame( [ 'cdek_courier:3' => $ours ], $this->plugin( false, true )->hide_rates_on_cart_page( [ 'cdek_courier:3' => $ours ] ) );
	}

	/**
	 * Runs isolated: it defines `is_cart()` / `WC()` as real functions, and a later test's `function_exists()` must not see them.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_with_the_option_off_the_cart_page_keeps_the_rates(): void {
		Functions\when( 'is_cart' )->justReturn( true );

		$ours = $this->rate( 'cdek_courier' );

		$this->assertSame( [ 'cdek_courier:3' => $ours ], $this->plugin( false, false )->hide_rates_on_cart_page( [ 'cdek_courier:3' => $ours ] ) );
	}

	/**
	 * Runs isolated: it defines `is_cart()` / `WC()` as real functions, and a later test's `function_exists()` must not see them.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_block_cart_store_api_request_from_the_cart_page_hides_the_rates_but_one_from_checkout_does_not(): void {
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'wc_get_cart_url' )->justReturn( 'https://shop.test/cart/' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'untrailingslashit' )->alias( static fn( string $value ) => rtrim( $value, '/' ) );

		$woocommerce = new class() {
			public function is_store_api_request(): bool {
				return true;
			}
		};
		Functions\when( 'WC' )->justReturn( $woocommerce );

		$ours = $this->rate( 'cdek_courier' );

		$_SERVER['HTTP_REFERER'] = 'https://shop.test/cart';
		$this->assertSame( [], $this->plugin( false, true )->hide_rates_on_cart_page( [ 'cdek_courier:3' => $ours ] ) );

		$_SERVER['HTTP_REFERER'] = 'https://shop.test/checkout/';
		$this->assertSame( [ 'cdek_courier:3' => $ours ], $this->plugin( false, true )->hide_rates_on_cart_page( [ 'cdek_courier:3' => $ours ] ) );

		unset( $_SERVER['HTTP_REFERER'] );
	}

	public function test_a_foreign_filter_value_is_returned_untouched(): void {
		$this->assertSame( 'not-an-array', $this->plugin( false, true )->hide_rates_on_cart_page( 'not-an-array' ) );
	}
}
