<?php
/**
 * Integration: «Не показывать на странице корзины» against WooCommerce's REAL shipping-rate cache (s158 FW-B round 2, F1).
 *
 * `WC_Shipping::calculate_shipping_for_package()` runs `woocommerce_package_rates` only when it RECALCULATES: it
 * stores the already-filtered rates in the session under the package's hash and, on a hash hit, returns them
 * without running the filter. A filter that hid the carrier on the cart page alone therefore leaked both ways —
 * cart rates served at checkout, checkout rates served on the cart. The plugin marks the cart page's packages
 * (`woodev_hidden_on_cart`), so each context has its own hash; this test walks the REAL cache branch, with
 * WooCommerce's shipping debug mode OFF (the one switch that bypasses the cache), in both directions and for both
 * the classic pages and the block cart's Store API requests.
 *
 * The carrier's rate is injected on `woocommerce_package_rates` ahead of the plugin's filter: what is under test is
 * the cache and the plugin's filter, not a carrier API.
 *
 * @package Woodev\Tests\Integration\Shipping
 * @since   2.0.2
 */

namespace Woodev\Tests\Integration\Shipping {

	use Woodev\Tests\Integration\TestCase;

	/**
	 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::mark_cart_page_packages
	 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::hide_rates_on_cart_page
	 */
	class ShippingCartPageRateCacheTest extends TestCase {

		private const CARRIER_RATE_ID = 'woodev_realistic_shipping:3';
		private const OTHER_RATE_ID   = 'flat_rate:1';

		/** @var mixed WooCommerce's session before the test. */
		private $original_session;

		/** @var int how many times the rate filters ran, i.e. how many times WooCommerce recalculated. */
		private int $recalculations = 0;

		/** @var int the cart page. */
		private int $cart_page_id = 0;

		/** @var string|null */
		private ?string $original_request_uri = null;

		/** @var string|null */
		private ?string $original_referer = null;

		/** @return \Woodev\Framework\Shipping\Shipping_Plugin */
		private function plugin() {
			return woodev_realistic_shipping_plugin();
		}

		protected function setUp(): void {
			parent::setUp();

			$this->original_session     = WC()->session;
			unset( $GLOBALS['post'] );
			$this->original_request_uri = $_SERVER['REQUEST_URI'] ?? null;
			$this->original_referer     = $_SERVER['HTTP_REFERER'] ?? null;

			WC()->session = new Woodev_Cart_Cache_Test_Session();

			// the cache must be in play: WooCommerce's own switch to bypass it stays OFF
			update_option( 'woocommerce_shipping_debug_mode', 'no' );

			// a cart page `is_cart()` recognises by its shortcode
			$this->cart_page_id = self::factory()->post->create(
				[
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'Cart',
					'post_content' => '[woocommerce_cart]',
				]
			);
			update_option( 'woocommerce_cart_page_id', $this->cart_page_id );

			// the plugin's `$methods` map is filled on this filter
			apply_filters( 'woocommerce_shipping_methods', [] );

			$this->recalculations = 0;

			// the carrier's rate, then a foreign one; a counter LAST — it runs only when WooCommerce recalculates
			add_filter(
				'woocommerce_package_rates',
				static function ( $rates ) {
					$rates[ self::CARRIER_RATE_ID ] = new \WC_Shipping_Rate( self::CARRIER_RATE_ID, 'Realistic', 100, [], 'woodev_realistic_shipping', 3 );
					$rates[ self::OTHER_RATE_ID ]   = new \WC_Shipping_Rate( self::OTHER_RATE_ID, 'Flat', 50, [], 'flat_rate', 1 );

					return $rates;
				},
				1
			);
			add_filter(
				'woocommerce_package_rates',
				function ( $rates ) {
					++$this->recalculations;

					return $rates;
				},
				999
			);

			$this->set_hide_on_cart( true );
		}

		protected function tearDown(): void {
			$this->set_hide_on_cart( false );

			WC()->session = $this->original_session;
			unset( $GLOBALS['post'] );
			$this->forget_the_remembered_page_type();

			foreach ( [
				'REQUEST_URI'  => $this->original_request_uri,
				'HTTP_REFERER' => $this->original_referer,
			] as $key => $value ) {
				if ( null === $value ) {
					unset( $_SERVER[ $key ] );
				} else {
					$_SERVER[ $key ] = $value;
				}
			}

			parent::tearDown();
		}

		private function set_hide_on_cart( bool $on ): void {
			$this->plugin()->get_advanced_settings()->update_value( 'disable_methods_on_cart', $on );
		}

		// ----- contexts -----

		/**
		 * Goes to a page that is NOT the cart. `go_to()` leaves the previous request's global `$post` behind, and
		 * `is_cart()` also recognises the cart shortcode in it — so a leftover cart page would still read as the cart.
		 *
		 * @param string $url the page.
		 */
		private function go_to_a_page_that_is_not_the_cart( string $url ): void {
			$this->go_to( $url );
			unset( $GLOBALS['post'] );
			$this->forget_the_remembered_page_type();
		}

		/**
		 * WooCommerce remembers `is_cart()`'s block-based answer in a static for the rest of the PROCESS (one request
		 * in real life, the whole suite here), so a test that moves between pages must clear it.
		 */
		private function forget_the_remembered_page_type(): void {
			$property = new \ReflectionProperty( \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::class, 'is_cart_page' );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$property->setValue( null, null );
		}

		/** The classic cart page. */
		private function classic_cart(): void {
			unset( $_SERVER['HTTP_REFERER'] );
			$this->go_to( get_permalink( $this->cart_page_id ) );
			$this->forget_the_remembered_page_type();
		}

		/** The classic checkout (any page that is not the cart). */
		private function classic_checkout(): void {
			unset( $_SERVER['HTTP_REFERER'] );
			$this->go_to_a_page_that_is_not_the_cart( '/checkout/' );
		}

		/** A block cart's Store API request: `is_cart()` is false, the referer is the cart. */
		private function block_cart(): void {
			$this->go_to_a_page_that_is_not_the_cart( '/' );
			$_SERVER['REQUEST_URI']  = '/wp-json/wc/store/v1/cart';
			$_SERVER['HTTP_REFERER'] = get_permalink( $this->cart_page_id );
		}

		/** A block checkout's Store API request: the referer is not the cart. */
		private function block_checkout(): void {
			$this->go_to_a_page_that_is_not_the_cart( '/' );
			$_SERVER['REQUEST_URI']  = '/wp-json/wc/store/v1/batch';
			$_SERVER['HTTP_REFERER'] = home_url( '/checkout/' );
		}

		/**
		 * What WooCommerce does for the cart's first package, through the REAL packages filter and the REAL
		 * `WC_Shipping` cache branch: the same package, whichever page asks.
		 *
		 * @return string[] rate ids it ends up with.
		 */
		private function rate_ids(): array {
			$base = [
				'contents'        => [],
				'contents_cost'   => 0,
				'applied_coupons' => [],
				'user'            => [ 'ID' => 0 ],
				'destination'     => [
					'country'   => 'RU',
					'state'     => '',
					'postcode'  => '644000',
					'city'      => 'Омск',
					'address'   => '',
					'address_1' => '',
					'address_2' => '',
				],
				'cart_subtotal'   => 0,
			];

			$packages = apply_filters( 'woocommerce_cart_shipping_packages', [ $base ] );
			$package  = WC()->shipping()->calculate_shipping_for_package( $packages[0], 0 );

			$this->assertIsArray( $package );

			return array_keys( $package['rates'] );
		}

		/**
		 * A NEW request's starting point: the plugin object is fresh, so its `$methods` map is EMPTY until
		 * WooCommerce fires `woocommerce_shipping_methods` — which, in the real flow, happens inside the rate
		 * calculation, AFTER the cart's packages were collected. Nothing here pre-registers the methods.
		 */
		private function start_a_cold_request(): void {
			$property = new \ReflectionProperty( \Woodev\Framework\Shipping\Shipping_Plugin::class, 'methods' );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$property->setValue( $this->plugin(), [] );

			$this->assertSame( [], $property->getValue( $this->plugin() ), 'the method map is empty when the packages are collected' );
		}

		/** @return array<string,array{0:string,1:string}> */
		public function cart_and_checkout_pairs(): array {
			return [
				'classic cart, then classic checkout'     => [ 'classic_cart', 'classic_checkout' ],
				'classic checkout, then classic cart'     => [ 'classic_checkout', 'classic_cart' ],
				'block cart, then block checkout'         => [ 'block_cart', 'block_checkout' ],
				'block checkout, then block cart'         => [ 'block_checkout', 'block_cart' ],
				'classic cart, then block checkout'       => [ 'classic_cart', 'block_checkout' ],
				'block checkout, then classic cart'       => [ 'block_checkout', 'classic_cart' ],
			];
		}

		/**
		 * @dataProvider cart_and_checkout_pairs
		 *
		 * @param string $first  the context asked first.
		 * @param string $second the context asked second, for the SAME package.
		 */
		public function test_moving_between_cart_and_checkout_never_serves_the_other_pages_rates( string $first, string $second ): void {

			$this->assertContains( 'woodev_realistic_shipping', $this->plugin()->get_shipping_method_ids(), 'the plugin knows its methods, or it would never hide anything' );

			$this->$first();
			$first_ids = $this->rate_ids();

			$this->$second();
			$second_ids = $this->rate_ids();

			$is_cart = static fn( string $context ): bool => false !== strpos( $context, 'cart' );

			$this->assertSame(
				$is_cart( $first ) ? [ self::OTHER_RATE_ID ] : [ self::CARRIER_RATE_ID, self::OTHER_RATE_ID ],
				$first_ids,
				"the carrier is hidden on the cart page and offered everywhere else ($first)"
			);
			$this->assertSame(
				$is_cart( $second ) ? [ self::OTHER_RATE_ID ] : [ self::CARRIER_RATE_ID, self::OTHER_RATE_ID ],
				$second_ids,
				"the other page must not be served the first page's cached rates ($second)"
			);
			$this->assertSame( 2, $this->recalculations, 'each context calculates once — they do not share a cache entry' );
		}

		/**
		 * F1 of the round-2 review: the marker used to give up while the method map was empty, but on a COLD request
		 * the packages are collected before the methods register — so cart and checkout shared one unmarked hash.
		 * Every ask below starts from an empty map, with shipping debug mode off, through the real `WC_Shipping` cache.
		 *
		 * @dataProvider cart_and_checkout_pairs
		 *
		 * @param string $first  the context asked first.
		 * @param string $second the context asked second, for the SAME package.
		 */
		public function test_on_a_cold_request_the_packages_are_marked_before_the_methods_register( string $first, string $second ): void {

			$is_cart = static fn( string $context ): bool => false !== strpos( $context, 'cart' );

			$this->$first();
			$this->start_a_cold_request();
			$first_ids = $this->rate_ids();

			$this->$second();
			$this->start_a_cold_request();
			$second_ids = $this->rate_ids();

			$this->assertSame(
				$is_cart( $first ) ? [ self::OTHER_RATE_ID ] : [ self::CARRIER_RATE_ID, self::OTHER_RATE_ID ],
				$first_ids,
				"the carrier is hidden on the cart page and offered everywhere else, cold ($first)"
			);
			$this->assertSame(
				$is_cart( $second ) ? [ self::OTHER_RATE_ID ] : [ self::CARRIER_RATE_ID, self::OTHER_RATE_ID ],
				$second_ids,
				"the other page must not be served the first page's cached rates, cold ($second)"
			);
			$this->assertSame( 2, $this->recalculations, 'each context calculates once — they do not share a cache entry' );
		}

		public function test_on_a_cold_request_with_the_option_off_nothing_is_marked_and_the_pages_share_one_cache_entry(): void {

			$this->set_hide_on_cart( false );

			$this->classic_cart();
			$this->start_a_cold_request();
			$cart = $this->rate_ids();

			$this->classic_checkout();
			$this->start_a_cold_request();
			$checkout = $this->rate_ids();

			$this->assertSame( [ self::CARRIER_RATE_ID, self::OTHER_RATE_ID ], $cart );
			$this->assertSame( $cart, $checkout );
			$this->assertSame( 1, $this->recalculations, 'a shop that never turns the option on keeps its hashes — and its cache hits' );
		}

		public function test_asking_again_in_the_same_context_is_still_served_from_the_cache(): void {

			$this->classic_cart();
			$this->assertSame( [ self::OTHER_RATE_ID ], $this->rate_ids() );
			$this->assertSame( [ self::OTHER_RATE_ID ], $this->rate_ids() );

			$this->assertSame( 1, $this->recalculations, 'the second ask is a cache hit: the filter did not run again' );

			$this->classic_checkout();
			$this->assertSame( [ self::CARRIER_RATE_ID, self::OTHER_RATE_ID ], $this->rate_ids() );
			$this->assertSame( [ self::CARRIER_RATE_ID, self::OTHER_RATE_ID ], $this->rate_ids() );

			$this->assertSame( 2, $this->recalculations );
		}

		public function test_with_the_option_off_cart_and_checkout_share_one_cache_entry_and_the_carrier_shows_on_both(): void {

			$this->set_hide_on_cart( false );

			$this->classic_cart();
			$cart = $this->rate_ids();

			$this->classic_checkout();
			$checkout = $this->rate_ids();

			$this->assertSame( [ self::CARRIER_RATE_ID, self::OTHER_RATE_ID ], $cart );
			$this->assertSame( $cart, $checkout );
			$this->assertSame( 1, $this->recalculations, 'a shop that never turns the option on keeps its hashes — and its cache hits' );
		}

		public function test_the_cache_this_file_exercises_is_real_the_old_design_leaked_cart_rates_to_checkout(): void {

			// CONTROL: the DESIGN this fix replaced — hide while `is_cart()` inside the rates filter, packages unmarked.
			// If it did not leak here, the walk above would prove nothing.
			$plugin = $this->plugin();
			remove_filter( 'woocommerce_cart_shipping_packages', [ $plugin, 'mark_cart_page_packages' ] );

			$old_design = static function ( $rates ) {
				if ( is_cart() ) {
					unset( $rates[ self::CARRIER_RATE_ID ] );
				}

				return $rates;
			};
			add_filter( 'woocommerce_package_rates', $old_design, 20 );

			try {
				$this->classic_cart();
				$this->assertSame( [ self::OTHER_RATE_ID ], $this->rate_ids() );

				$this->classic_checkout();
				$this->assertSame( [ self::OTHER_RATE_ID ], $this->rate_ids(), 'WooCommerce serves the cart page\'s filtered rates at checkout' );
				$this->assertSame( 1, $this->recalculations, 'checkout never recalculated: the filter did not run there' );
			} finally {
				remove_filter( 'woocommerce_package_rates', $old_design, 20 );
				add_filter( 'woocommerce_cart_shipping_packages', [ $plugin, 'mark_cart_page_packages' ] );
			}
		}
	}

	/**
	 * An in-memory session — what `WC_Session_Handler` is, minus the cookie and the table.
	 */
	final class Woodev_Cart_Cache_Test_Session extends \WC_Session {
	}
}
