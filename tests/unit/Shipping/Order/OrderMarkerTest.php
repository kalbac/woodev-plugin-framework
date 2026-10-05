<?php
/**
 * Unit: the carrier marker contract (#967, #710 I1b) — {@see Order_Marker} and the
 * `marker_writer` of {@see Orders_Provider}.
 *
 * The properties under test are the ones the orders page depends on (installed-site data
 * contract): the marker meta lands under the provider's key, with a value
 * `Orders_Registry::resolve_provider_for_order()` can read (non-empty scalar, #962 I0), only
 * on orders that carry the provider's shipping method, and a broken writer never breaks the
 * order being placed.
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace Woodev\Tests\Unit\Shipping\Order {

	use Brain\Monkey\Functions;
	use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
	use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
	use Woodev\Framework\Shipping\Exceptions\Shipping_Exception;
	use Woodev\Framework\Shipping\Order\Order_Marker;
	use Woodev\Tests\Unit\TestCase;

	require_once __DIR__ . '/order-marker-fakes.php';

	/**
	 * @covers \Woodev\Framework\Shipping\Order\Order_Marker
	 * @covers \Woodev\Framework\Shipping\Admin\Orders\Orders_Provider::mark_order
	 * @covers \Woodev\Framework\Shipping\Admin\Orders\Orders_Provider::has_marker_writer
	 */
	class OrderMarkerTest extends TestCase {

		private const KEY = '_test_marker';

		/** @var string */
		private $log_file = '';

		/** @var string|false previous `error_log` ini value */
		private $previous_error_log = false;

		protected function setUp(): void {
			parent::setUp();

			Functions\stubs( [ 'add_action', 'remove_action', 'add_filter', 'remove_filter', 'apply_filters' ] );
			Functions\when( 'get_post_meta' )->alias( [ Order_Marker_Fakes::class, 'get_post_meta' ] );

			Order_Marker_Fakes::reset();
			Orders_Registry::instance()->reset_for_tests();

			$this->log_file           = tempnam( sys_get_temp_dir(), 'marker-log' );
			$this->previous_error_log = ini_set( 'error_log', $this->log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- capture the diagnostic lines.
		}

		protected function tearDown(): void {
			ini_set( 'error_log', (string) $this->previous_error_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky

			if ( '' !== $this->log_file && file_exists( $this->log_file ) ) {
				unlink( $this->log_file );
			}

			Orders_Registry::instance()->reset_for_tests();
			Order_Marker_Fakes::reset();

			parent::tearDown();
		}

		/**
		 * @param callable|null $writer the marker writer, or null for none.
		 * @param string        $key    marker meta key.
		 * @param string[]      $ids    shipping method ids.
		 * @param string        $id     provider id.
		 */
		private function register( ?callable $writer, string $key = self::KEY, array $ids = [ 'test_courier', 'test_pickup' ], string $id = 'test' ): Orders_Provider {
			$args = null === $writer ? [] : [ 'marker_writer' => $writer ];

			$provider = Orders_Provider::create( $id, 'Тест', $key, $ids, $args );

			Orders_Registry::instance()->register_provider( $provider );

			return $provider;
		}

		/**
		 * A writer that stores `$value` under the test key on the order object.
		 *
		 * @param mixed  $value the marker value.
		 * @param string $key   the meta key.
		 */
		private static function writing( $value, string $key = self::KEY ): callable {
			return static function ( \WC_Order $order, array $context ) use ( $value, $key ): void {
				$order->update_meta_data( $key, $value );
			};
		}

		private function logged(): string {
			return (string) file_get_contents( $this->log_file );
		}

		public function test_it_marks_an_order_that_carries_the_providers_method_and_persists_it(): void {
			$this->register( self::writing( '1' ) );
			$order = Order_Marker_Fakes::order( [ Order_Marker_Fakes::line( 'test_courier', 3 ) ] );

			$marked = ( new Order_Marker() )->mark_order( $order );

			$this->assertSame( [ 'test' ], $marked );
			// Read through the DB-backed reader: the framework saved what the writer only set.
			$this->assertSame( '1', Order_Marker_Fakes::$db[42][ self::KEY ] );
			$this->assertSame( 1, Order_Marker_Fakes::$saves[42] );
		}

		public function test_it_matches_any_of_the_providers_method_ids(): void {
			$this->register( self::writing( '1' ) );
			$order = Order_Marker_Fakes::order( [ Order_Marker_Fakes::line( 'test_pickup', 5 ) ] );

			$this->assertSame( [ 'test' ], ( new Order_Marker() )->mark_order( $order ) );
		}

		public function test_it_leaves_another_carriers_and_a_free_shipping_order_alone(): void {
			$calls = 0;
			$this->register(
				static function ( \WC_Order $order, array $context ) use ( &$calls ): void {
					++$calls;
					$order->update_meta_data( self::KEY, '1' );
				}
			);

			foreach ( [ 'free_shipping', 'other_carrier' ] as $method ) {
				$order = Order_Marker_Fakes::order( [ Order_Marker_Fakes::line( $method, 1 ) ] );

				$this->assertSame( [], ( new Order_Marker() )->mark_order( $order ) );
				$this->assertArrayNotHasKey( 42, Order_Marker_Fakes::$saves );
			}

			$this->assertSame( 0, $calls );
			$this->assertSame( [], Order_Marker_Fakes::$db );
		}

		public function test_it_removes_a_marker_when_the_order_no_longer_carries_the_carriers_rate(): void {
			$this->register( self::writing( '1' ) );
			$order = Order_Marker_Fakes::order( [ Order_Marker_Fakes::line( 'test_courier', 3 ) ] );
			$marker = new Order_Marker();
			$marker->mark_order( $order );

			$order = Order_Marker_Fakes::order( [ Order_Marker_Fakes::line( 'free_shipping', 1 ) ] );
			Order_Marker_Fakes::$db[42][ self::KEY ] = '1';
			$this->assertSame( [], $marker->mark_order( $order ) );
			$this->assertArrayNotHasKey( self::KEY, Order_Marker_Fakes::$db[42] );
		}

		public function test_it_keeps_a_marker_when_the_carriers_rate_is_present(): void {
			$this->register( self::writing( '1' ) );
			$order = Order_Marker_Fakes::order( [ Order_Marker_Fakes::line( 'test_courier', 3 ) ] );
			$this->assertSame( [ 'test' ], ( new Order_Marker() )->mark_order( $order ) );
			$this->assertSame( '1', Order_Marker_Fakes::$db[42][ self::KEY ] );
		}

		public function test_removing_one_carriers_marker_leaves_another_carriers_marker_untouched(): void {
			$this->register( self::writing( '1', '_test_marker' ), '_test_marker', [ 'test_courier' ], 'test' );
			$this->register( self::writing( '1', '_other_marker' ), '_other_marker', [ 'other_courier' ], 'other' );
			$order = Order_Marker_Fakes::order( [ Order_Marker_Fakes::line( 'other_courier', 1 ) ] );
			Order_Marker_Fakes::$db[42] = [ '_test_marker' => '1', '_other_marker' => '1' ];

			$this->assertSame( [ 'other' ], ( new Order_Marker() )->mark_order( $order ) );
			$this->assertArrayNotHasKey( '_test_marker', Order_Marker_Fakes::$db[42] );
			$this->assertSame( '1', Order_Marker_Fakes::$db[42]['_other_marker'] );
		}

		public function test_an_order_with_no_shipping_line_is_not_marked(): void {
			$this->register( self::writing( '1' ) );

			$this->assertSame( [], ( new Order_Marker() )->mark_order( Order_Marker_Fakes::order( [] ) ) );
		}

		public function test_a_provider_without_a_writer_is_skipped_quietly(): void {
			$this->register( null );
			$order = Order_Marker_Fakes::order( [ Order_Marker_Fakes::line( 'test_courier', 3 ) ] );

			$this->assertSame( [], ( new Order_Marker() )->mark_order( $order ) );
			$this->assertSame( '', $this->logged() );
		}

		public function test_an_empty_registry_never_touches_the_order(): void {
			// A bare object: any call on it beyond this class's own stand-in would fatal.
			$this->assertSame( [], ( new Order_Marker() )->mark_order( new \WC_Order() ) );
		}

		public function test_the_writer_receives_the_full_context_derived_from_the_shipping_line(): void {
			$seen = null;
			$this->register(
				static function ( \WC_Order $order, array $context ) use ( &$seen ): void {
					$seen = $context;
					$order->update_meta_data( self::KEY, '1' );
				}
			);
			$order = Order_Marker_Fakes::order( [ Order_Marker_Fakes::line( 'test_courier', 3, [ 'Items' => 'Чайник × 1' ] ) ] );

			( new Order_Marker() )->mark_order( $order );

			$this->assertSame(
				[
					'provider_id'    => 'test',
					'method_id'      => 'test_courier',
					'instance_id'    => 3,
					'rate'           => [
						'id'          => 'test_courier:3',
						'method_id'   => 'test_courier',
						'instance_id' => 3,
						'label'       => 'Курьер',
						'cost'        => '350.00',
						'meta'        => [ 'Items' => 'Чайник × 1' ],
					],
					'fields'         => [],
					'pickup_point'   => null,
					'carrier_fields' => [],
				],
				$seen
			);
		}

		public function test_a_callers_overrides_replace_only_the_four_open_keys(): void {
			$seen = null;
			$this->register(
				static function ( \WC_Order $order, array $context ) use ( &$seen ): void {
					$seen = $context;
					$order->update_meta_data( self::KEY, '1' );
				}
			);
			$order = Order_Marker_Fakes::order( [ Order_Marker_Fakes::line( 'test_courier', 3 ) ] );

			( new Order_Marker() )->mark_order(
				$order,
				[
					'rate'           => [ 'id' => 'edited' ],
					'fields'         => [ 'carrier_pickup_point' => 'P1' ],
					'pickup_point'   => [ 'id' => 'P1' ],
					'carrier_fields' => [ 'declared_value' => 100 ],
					'provider_id'    => 'hijacked',
					'method_id'      => 'hijacked',
				]
			);

			$this->assertSame( [ 'id' => 'edited' ], $seen['rate'] );
			$this->assertSame( [ 'carrier_pickup_point' => 'P1' ], $seen['fields'] );
			$this->assertSame( [ 'id' => 'P1' ], $seen['pickup_point'] );
			$this->assertSame( [ 'declared_value' => 100 ], $seen['carrier_fields'] );
			$this->assertSame( 'test', $seen['provider_id'] );
			$this->assertSame( 'test_courier', $seen['method_id'] );
		}

		public function test_an_already_valid_marker_is_not_rewritten_unless_refreshed(): void {
			$calls = 0;
			$this->register(
				static function ( \WC_Order $order, array $context ) use ( &$calls ): void {
					++$calls;
					$order->update_meta_data( self::KEY, '1' );
				}
			);
			$order  = Order_Marker_Fakes::order( [ Order_Marker_Fakes::line( 'test_courier', 3 ) ] );
			$marker = new Order_Marker();

			$this->assertSame( [ 'test' ], $marker->mark_order( $order ) );
			// The second carrier plugin's checkout handler reaches the same order.
			$this->assertSame( [ 'test' ], $marker->mark_order( $order ) );
			$this->assertSame( 1, $calls );

			// The admin editor re-saving the order.
			$this->assertSame( [ 'test' ], $marker->mark_order( $order, [], true ) );
			$this->assertSame( 2, $calls );
		}

		/**
		 * @return array<string, array{0: mixed}>
		 */
		public function invalid_marker_values(): array {
			return [
				'empty string'  => [ '' ],
				'false'         => [ false ],
				'null'          => [ null ],
				'array (v1 edostavka shape)' => [ [ 'tariff_data' => [ 'code' => 1 ] ] ],
			];
		}

		/**
		 * @dataProvider invalid_marker_values
		 *
		 * @param mixed $value what the writer wrote.
		 */
		public function test_a_marker_the_orders_page_cannot_read_is_reported_not_counted( $value ): void {
			$this->register( self::writing( $value ) );
			$order = Order_Marker_Fakes::order( [ Order_Marker_Fakes::line( 'test_courier', 3 ) ] );

			$this->assertSame( [], ( new Order_Marker() )->mark_order( $order ) );
			$this->assertStringContainsString( 'left no valid "' . self::KEY . '"', $this->logged() );
		}

		public function test_a_writer_that_writes_nothing_is_reported(): void {
			$this->register( static function ( \WC_Order $order, array $context ): void {} );
			$order = Order_Marker_Fakes::order( [ Order_Marker_Fakes::line( 'test_courier', 3 ) ] );

			$this->assertSame( [], ( new Order_Marker() )->mark_order( $order ) );
			$this->assertStringContainsString( 'left no valid', $this->logged() );
		}

		public function test_a_writer_that_writes_under_another_key_is_reported(): void {
			$this->register( self::writing( '1', '_some_other_key' ) );
			$order = Order_Marker_Fakes::order( [ Order_Marker_Fakes::line( 'test_courier', 3 ) ] );

			$this->assertSame( [], ( new Order_Marker() )->mark_order( $order ) );
		}

		public function test_a_throwing_writer_never_breaks_the_order_and_is_logged(): void {
			$this->register(
				static function (): void {
					throw new \RuntimeException( 'carrier exploded' );
				}
			);
			$order = Order_Marker_Fakes::order( [ Order_Marker_Fakes::line( 'test_courier', 3 ) ] );

			$this->assertSame( [], ( new Order_Marker() )->mark_order( $order ) );
			$this->assertStringContainsString( 'carrier exploded', $this->logged() );
		}

		public function test_one_failing_provider_does_not_stop_the_next(): void {
			$this->register(
				static function (): void {
					throw new \RuntimeException( 'first fails' );
				},
				'_first_marker',
				[ 'first_method' ],
				'first'
			);
			$this->register( self::writing( '1', '_second_marker' ), '_second_marker', [ 'second_method' ], 'second' );

			$order = Order_Marker_Fakes::order(
				[ Order_Marker_Fakes::line( 'first_method', 1 ), Order_Marker_Fakes::line( 'second_method', 2 ) ]
			);

			$this->assertSame( [ 'second' ], ( new Order_Marker() )->mark_order( $order ) );
		}

		public function test_an_order_with_lines_of_two_carriers_is_marked_for_both(): void {
			$this->register( self::writing( '1', '_first_marker' ), '_first_marker', [ 'first_method' ], 'first' );
			$this->register( self::writing( '1', '_second_marker' ), '_second_marker', [ 'second_method' ], 'second' );

			$order = Order_Marker_Fakes::order(
				[ Order_Marker_Fakes::line( 'first_method', 1 ), Order_Marker_Fakes::line( 'second_method', 2 ) ]
			);

			$this->assertSame( [ 'first', 'second' ], ( new Order_Marker() )->mark_order( $order ) );
		}

		public function test_an_order_id_is_resolved_through_wc_get_order(): void {
			$this->register( self::writing( '1' ) );
			$order = Order_Marker_Fakes::order( [ Order_Marker_Fakes::line( 'test_courier', 3 ) ], 77 );

			Functions\expect( 'wc_get_order' )->once()->with( 77 )->andReturn( $order );

			$this->assertSame( [ 'test' ], ( new Order_Marker() )->mark_order( 77 ) );
			$this->assertSame( '1', Order_Marker_Fakes::$db[77][ self::KEY ] );
		}

		public function test_an_unresolvable_order_id_marks_nothing(): void {
			$this->register( self::writing( '1' ) );

			Functions\when( 'wc_get_order' )->justReturn( false );

			$this->assertSame( [], ( new Order_Marker() )->mark_order( 9 ) );
			$this->assertSame( [], ( new Order_Marker() )->mark_order( 0 ) );
		}

		/**
		 * @return array<string, array{0: mixed, 1: bool}>
		 */
		public function marker_values(): array {
			return [
				'one'         => [ '1', true ],
				'true'        => [ true, true ],
				'zero string' => [ '0', true ],
				'zero int'    => [ 0, true ],
				'text'        => [ 'NEW', true ],
				'empty'       => [ '', false ],
				'false'       => [ false, false ],
				'null'        => [ null, false ],
				'array'       => [ [ 'a' => 1 ], false ],
				'object'      => [ new \stdClass(), false ],
			];
		}

		/**
		 * The same rule `Orders_Registry::resolve_provider_for_order()` applies —
		 * `'' !== (string) $value` — restricted to what casts without a warning.
		 *
		 * @dataProvider marker_values
		 *
		 * @param mixed $value    a stored marker value.
		 * @param bool  $expected whether the orders page can read it.
		 */
		public function test_is_valid_value_matches_what_the_orders_page_reads( $value, bool $expected ): void {
			$this->assertSame( $expected, Order_Marker::is_valid_value( $value ) );
		}

		public function test_a_provider_declares_a_writer_and_reports_it(): void {
			$with    = Orders_Provider::create( 'a', 'A', '_a', [ 'a' ], [ 'marker_writer' => self::writing( '1', '_a' ) ] );
			$without = Orders_Provider::create( 'b', 'B', '_b', [ 'b' ] );

			$this->assertTrue( $with->has_marker_writer() );
			$this->assertFalse( $without->has_marker_writer() );
		}

		public function test_mark_order_hands_the_writer_the_order_and_the_context(): void {
			$got      = [];
			$provider = Orders_Provider::create(
				'a',
				'A',
				'_a',
				[ 'a' ],
				[
					'marker_writer' => static function ( \WC_Order $order, array $context ) use ( &$got ): void {
						$got = [ $order, $context ];
					},
				]
			);
			$order    = new \WC_Order();

			$provider->mark_order( $order, [ 'k' => 'v' ] );

			$this->assertSame( [ $order, [ 'k' => 'v' ] ], $got );
		}

		public function test_mark_order_without_a_writer_throws(): void {
			$this->expectException( Shipping_Exception::class );

			Orders_Provider::create( 'b', 'B', '_b', [ 'b' ] )->mark_order( new \WC_Order(), [] );
		}

		public function test_a_declared_writer_that_is_not_callable_is_refused_at_construction(): void {
			$this->expectException( Shipping_Exception::class );
			$this->expectExceptionMessage( 'marker_writer' );

			Orders_Provider::create( 'a', 'A', '_a', [ 'a' ], [ 'marker_writer' => 'no_such_function_anywhere' ] );
		}
	}
}
