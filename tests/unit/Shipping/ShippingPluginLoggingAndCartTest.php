<?php
/**
 * Unit: «Логирование» and «Не показывать на странице корзины» as the plugin applies them (s158).
 *
 * Logging off = only errors reach the log (written at the ERROR level — the inherited `log()` is NOTICE, which an
 * Error threshold drops); on = also debug lines and the carrier API requests/responses, at the DEBUG level. The
 * cart option marks the cart page's packages and removes this carrier's rates from a marked package — never in
 * `calculate_shipping()`, and never by re-detecting the page inside `woocommerce_package_rates`, because WooCommerce
 * runs that filter only when it recalculates and then caches the filtered result under the package's hash. The
 * real cache branch is exercised in the integration suite (`ShippingCartPageRateCacheTest`).
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
 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::log_error
 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::mark_cart_page_packages
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
	 * @param bool $methods_registered whether `woocommerce_shipping_methods` has already filled the plugin's method map
	 * @return Shipping_Plugin&\Mockery\MockInterface
	 */
	private function plugin( bool $logging = false, bool $on_cart = false, bool $methods_registered = true ) {
		$this->options['woodev_cdek_advanced_enable_debug']           = $logging ? 'yes' : 'no';
		$this->options['woodev_cdek_advanced_disable_methods_on_cart'] = $on_cart ? 'yes' : 'no';

		$plugin = Mockery::mock( Shipping_Plugin::class )->makePartial()->shouldAllowMockingProtectedMethods();
		$plugin->shouldReceive( 'get_advanced_settings' )->andReturnUsing( static fn() => new Advanced_Settings( 'cdek' ) );
		$plugin->shouldReceive( 'get_id' )->andReturn( 'cdek' );

		$property = new \ReflectionProperty( Shipping_Plugin::class, 'methods' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( $plugin, $methods_registered ? [ 'cdek_courier' => 'Courier_Class' ] : [] );

		return $plugin;
	}

	// ----- logging -----

	public function test_the_switch_is_the_one_stored_key_and_wp_debug_does_not_turn_it_on(): void {
		$this->assertFalse( $this->plugin( false )->is_debug_enabled() );
		$this->assertTrue( $this->plugin( true )->is_debug_enabled() );
		$this->assertArrayHasKey( 'woodev_cdek_advanced_enable_debug', $this->options );
	}

	/** @return \Mockery\MockInterface the WooCommerce logger the plugin writes to. */
	private function logger() {
		$logger = Mockery::mock( '\WC_Logger_Interface' );
		Functions\when( 'wc_get_logger' )->justReturn( $logger );

		return $logger;
	}

	public function test_logging_off_writes_no_debug_line(): void {
		$logger = $this->logger();
		$logger->shouldReceive( 'log' )->never();
		$logger->shouldReceive( 'add' )->never();

		$this->plugin( false )->log_debug( 'a debug line' );

		$this->addToAssertionCount( 1 );
	}

	public function test_logging_on_writes_the_debug_line_at_the_debug_level_to_the_given_log(): void {
		$logger = $this->logger();
		$logger->shouldReceive( 'log' )->once()->with( 'debug', 'a debug line', [ 'source' => 'cdek_courier' ] );

		$this->plugin( true )->log_debug( 'a debug line', 'cdek_courier' );

		$this->addToAssertionCount( 1 );
	}

	public function test_a_failure_is_written_at_the_error_level_whatever_the_switch_says(): void {
		$logger = $this->logger();
		$logger->shouldReceive( 'log' )->twice()->with( 'error', 'something failed', [ 'source' => 'cdek' ] );

		$this->plugin( false )->log_error( 'something failed' );
		$this->plugin( true )->log_error( 'something failed' );

		$this->addToAssertionCount( 1 );
	}

	public function test_the_inherited_log_is_untouched_so_other_callers_keep_their_severity(): void {
		$logger = $this->logger();
		$logger->shouldReceive( 'add' )->once()->with( 'cdek', 'a legacy line' );

		$this->plugin( false )->log( 'a legacy line' );

		$this->addToAssertionCount( 1 );
	}

	public function test_carrier_api_requests_and_responses_are_logged_at_debug_only_with_logging_on(): void {
		$logger = $this->logger();
		$logger->shouldReceive( 'log' )->never();
		$this->plugin( false )->log_api_request( [ 'uri' => 'https://api.example.test' ], [ 'code' => 200 ] );

		$on_logger = $this->logger();
		$on_logger->shouldReceive( 'log' )->twice()->with( 'debug', Mockery::type( 'string' ), [ 'source' => 'cdek' ] );
		$this->plugin( true )->log_api_request( [ 'uri' => 'https://api.example.test' ], [ 'code' => 200 ] );

		$this->addToAssertionCount( 1 );
	}

	// ----- hide on the cart page -----

	/** @return \WC_Shipping_Rate&\Mockery\MockInterface */
	private function rate( string $method_id ) {
		$rate = Mockery::mock( '\WC_Shipping_Rate' );
		$rate->shouldReceive( 'get_method_id' )->andReturn( $method_id );

		return $rate;
	}

	/** @return array<int,array<string,mixed>> */
	private function packages(): array {
		return [ [ 'contents' => [ 3 => [ 'product_id' => 3, 'quantity' => 1 ] ], 'destination' => [ 'city' => 'Омск' ] ] ];
	}

	/**
	 * Runs isolated: it defines `is_cart()` / `WC()` as real functions, and a later test's `function_exists()` must not see them.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_on_the_cart_page_with_the_option_on_the_packages_are_marked_with_this_plugin(): void {
		Functions\when( 'is_cart' )->justReturn( true );

		$marked = $this->plugin( false, true )->mark_cart_page_packages( $this->packages() );

		$this->assertSame( [ 'cdek' ], $marked[0]['woodev_hidden_on_cart'] );
		$this->assertSame( $this->packages()[0]['contents'], $marked[0]['contents'], 'nothing else in the package changes' );
	}

	/**
	 * Runs isolated: it defines `is_cart()` / `WC()` as real functions, and a later test's `function_exists()` must not see them.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_second_plugin_adds_its_id_without_dropping_the_first(): void {
		Functions\when( 'is_cart' )->justReturn( true );

		$packages                                  = $this->packages();
		$packages[0]['woodev_hidden_on_cart']      = [ 'boxberry' ];
		$marked                                    = $this->plugin( false, true )->mark_cart_page_packages( $packages );

		$this->assertSame( [ 'boxberry', 'cdek' ], $marked[0]['woodev_hidden_on_cart'] );
	}

	/**
	 * Runs isolated: it defines `is_cart()` / `WC()` as real functions, and a later test's `function_exists()` must not see them.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_at_checkout_the_packages_are_left_unmarked_even_with_the_option_on(): void {
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'WC' )->justReturn( new \stdClass() );

		$this->assertSame( $this->packages(), $this->plugin( false, true )->mark_cart_page_packages( $this->packages() ) );
	}

	/**
	 * Runs isolated: it defines `is_cart()` / `WC()` as real functions, and a later test's `function_exists()` must not see them.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_with_the_option_off_the_cart_page_packages_keep_their_hash(): void {
		Functions\when( 'is_cart' )->justReturn( true );

		$this->assertSame( $this->packages(), $this->plugin( false, false )->mark_cart_page_packages( $this->packages() ) );
	}

	/**
	 * Runs isolated: it defines `is_cart()` / `WC()` as real functions, and a later test's `function_exists()` must not see them.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_block_cart_store_api_request_from_the_cart_page_marks_the_packages_but_one_from_checkout_does_not(): void {
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

		$_SERVER['HTTP_REFERER'] = 'https://shop.test/cart';
		$this->assertSame( [ 'cdek' ], $this->plugin( false, true )->mark_cart_page_packages( $this->packages() )[0]['woodev_hidden_on_cart'] );

		$_SERVER['HTTP_REFERER'] = 'https://shop.test/checkout/';
		$this->assertSame( $this->packages(), $this->plugin( false, true )->mark_cart_page_packages( $this->packages() ) );

		unset( $_SERVER['HTTP_REFERER'] );
	}

	/**
	 * Runs isolated: it defines `is_cart()` / `WC()` as real functions, and a later test's `function_exists()` must not see them.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_cart_page_packages_are_marked_while_the_method_map_is_still_empty(): void {
		Functions\when( 'is_cart' )->justReturn( true );

		$marked = $this->plugin( false, true, false )->mark_cart_page_packages( $this->packages() );

		$this->assertSame( [ 'cdek' ], $marked[0]['woodev_hidden_on_cart'], 'WooCommerce collects the packages before the methods register' );
	}

	/**
	 * Runs isolated: it defines `is_cart()` / `WC()` as real functions, and a later test's `function_exists()` must not see them.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_empty_method_map_changes_nothing_at_checkout_or_with_the_option_off(): void {
		Functions\when( 'is_cart' )->justReturn( true );
		$this->assertSame( $this->packages(), $this->plugin( false, false, false )->mark_cart_page_packages( $this->packages() ) );

		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'WC' )->justReturn( new \stdClass() );
		$this->assertSame( $this->packages(), $this->plugin( false, true, false )->mark_cart_page_packages( $this->packages() ) );
	}

	public function test_foreign_filter_values_are_returned_untouched_when_marking(): void {
		$this->assertSame( 'not-an-array', $this->plugin( false, true )->mark_cart_page_packages( 'not-an-array' ) );
		$this->assertSame( [], $this->plugin( false, true )->mark_cart_page_packages( [] ) );
	}

	public function test_a_package_marked_for_this_plugin_loses_this_carriers_rates_and_others_stay(): void {
		$ours   = $this->rate( 'cdek_courier' );
		$theirs = $this->rate( 'flat_rate' );

		$package = $this->packages()[0] + [ 'woodev_hidden_on_cart' => [ 'cdek' ] ];

		$this->assertSame(
			[ 'flat_rate:1' => $theirs ],
			$this->plugin( false, true )->hide_rates_on_cart_page( [ 'cdek_courier:3' => $ours, 'flat_rate:1' => $theirs ], $package )
		);
	}

	public function test_a_package_marked_only_for_another_plugin_keeps_this_carriers_rates(): void {
		$ours = $this->rate( 'cdek_courier' );

		$package = $this->packages()[0] + [ 'woodev_hidden_on_cart' => [ 'boxberry' ] ];

		$this->assertSame( [ 'cdek_courier:3' => $ours ], $this->plugin( false, true )->hide_rates_on_cart_page( [ 'cdek_courier:3' => $ours ], $package ) );
	}

	public function test_an_unmarked_package_keeps_the_rates_so_checkout_offers_the_carrier(): void {
		$ours = $this->rate( 'cdek_courier' );

		$this->assertSame( [ 'cdek_courier:3' => $ours ], $this->plugin( false, true )->hide_rates_on_cart_page( [ 'cdek_courier:3' => $ours ], $this->packages()[0] ) );
		$this->assertSame( [ 'cdek_courier:3' => $ours ], $this->plugin( false, true )->hide_rates_on_cart_page( [ 'cdek_courier:3' => $ours ] ) );
	}

	public function test_a_foreign_filter_value_is_returned_untouched(): void {
		$this->assertSame( 'not-an-array', $this->plugin( false, true )->hide_rates_on_cart_page( 'not-an-array', [ 'woodev_hidden_on_cart' => [ 'cdek' ] ] ) );
	}
}
