<?php
/**
 * #1362: on non-HPOS sites `update_post_meta()` / `add_post_meta()` / `delete_post_meta()`
 * run `wp_unslash()` on the value, so a JSON string such as `{"name":"\u0421\u0430"}` came back
 * as `{"name":"u0421u0430"}`. Woodev_Order_Compatibility must slash the value first, as
 * WooCommerce's own CPT data store does.
 *
 * The WordPress pair is simulated faithfully: the `*_post_meta()` stand-ins store
 * `wp_unslash( $value )`, and `wp_slash()` / `wp_unslash()` behave like the real ones, so a
 * test only passes when what is stored equals what was passed in, byte for byte.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	require_once dirname( __DIR__, 2 ) . '/woodev/compatibility/class-plugin-compatibility.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/compatibility/class-order-compatibility.php';
}

namespace Woodev\Tests\Unit {

	use Automattic\WooCommerce\Utilities\OrderUtil;
	use Brain\Monkey\Functions;
	use Mockery;

	/**
	 * @covers \Woodev_Order_Compatibility::update_order_meta
	 * @covers \Woodev_Order_Compatibility::add_order_meta
	 * @covers \Woodev_Order_Compatibility::delete_order_meta
	 */
	final class OrderCompatibilityMetaSlashTest extends TestCase {

		/** @var array<int,mixed> values as the simulated `*_post_meta()` functions stored them */
		private array $stored = [];

		protected function setUp(): void {
			parent::setUp();

			if ( ! class_exists( OrderUtil::class, false ) ) {
				// Defined by OrderCompatibilityEmptyCarrierIdTest in the same process.
				eval( 'namespace Automattic\\WooCommerce\\Utilities; class OrderUtil { public static $hpos_enabled = false; public static function custom_orders_table_usage_is_enabled(): bool { return self::$hpos_enabled; } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
			}

			OrderUtil::$hpos_enabled = false;
			$this->stored            = [];

			// Real WordPress semantics: wp_slash() adds slashes, the meta API removes one level.
			$slash   = static function ( $value ) use ( &$slash ) {
				if ( is_array( $value ) ) {
					return array_map( $slash, $value );
				}

				return is_string( $value ) ? addslashes( $value ) : $value;
			};
			$unslash = static function ( $value ) use ( &$unslash ) {
				if ( is_array( $value ) ) {
					return array_map( $unslash, $value );
				}

				return is_string( $value ) ? stripslashes( $value ) : $value;
			};

			Functions\when( 'wp_slash' )->alias( $slash );
			Functions\when( 'update_post_meta' )->alias(
				function ( $post_id, $key, $value ) use ( $unslash ) {
					$this->stored[ $post_id ][ $key ] = $unslash( $value );

					return true;
				}
			);
			Functions\when( 'add_post_meta' )->alias(
				function ( $post_id, $key, $value ) use ( $unslash ) {
					$this->stored[ $post_id ][ $key ] = $unslash( $value );

					return 1;
				}
			);
		}

		protected function tearDown(): void {
			OrderUtil::$hpos_enabled = false;

			parent::tearDown();
		}

		/**
		 * @return array<string,array{0:mixed}>
		 */
		public function backslash_values(): array {
			return [
				'json with unicode escapes' => [ '{"name":"\u0421\u0430\u043d\u043a\u0442-\u041f\u0435\u0442\u0435\u0440\u0431\u0443\u0440\u0433"}' ],
				'windows path'              => [ 'C:\path\to\file' ],
				'json with quote escapes'   => [ '{"note":"say \"hi\" \\ done"}' ],
				'no backslash'              => [ 'plain value' ],
				'empty string'              => [ '' ],
				'array with backslash'      => [ [ 'path' => 'C:\path', 'nested' => [ 'a' => '\u0421' ] ] ],
				'int'                       => [ 42 ],
			];
		}

		/**
		 * @dataProvider backslash_values
		 *
		 * @param mixed $value
		 */
		public function test_update_order_meta_round_trips_the_value_without_hpos( $value ): void {
			$order = Mockery::mock( '\WC_Order' );
			$order->shouldReceive( 'get_id' )->andReturn( 7 );

			\Woodev_Order_Compatibility::update_order_meta( $order, '_k', $value );

			$this->assertSame( $value, $this->stored[7]['_k'] );
		}

		/**
		 * @dataProvider backslash_values
		 *
		 * @param mixed $value
		 */
		public function test_add_order_meta_round_trips_the_value_without_hpos( $value ): void {
			\Woodev_Order_Compatibility::add_order_meta( 7, '_k', $value );

			$this->assertSame( $value, $this->stored[7]['_k'] );
		}

		/**
		 * delete_post_meta() unslashes its comparison value too; it must be slashed to match the row.
		 */
		public function test_delete_order_meta_passes_a_slashed_comparison_value_without_hpos(): void {
			$seen = null;

			Functions\when( 'delete_post_meta' )->alias(
				static function ( $post_id, $key, $value ) use ( &$seen ) {
					$seen = stripslashes( $value );

					return true;
				}
			);

			\Woodev_Order_Compatibility::delete_order_meta( 7, '_k', 'C:\path' );

			$this->assertSame( 'C:\path', $seen );
		}

		/**
		 * The HPOS branch is untouched: WC_Order::update_meta_data() takes the raw, unslashed value.
		 */
		public function test_hpos_branch_receives_the_raw_value(): void {
			OrderUtil::$hpos_enabled = true;

			$json = '{"name":"\u0421\u0430"}';

			$order = Mockery::mock( '\WC_Order' );
			$order->shouldReceive( 'update_meta_data' )->once()->with( '_k', $json );
			$order->shouldReceive( 'save_meta_data' )->once();

			\Woodev_Order_Compatibility::update_order_meta( $order, '_k', $json );

			$this->assertSame( [], $this->stored, 'HPOS must not touch the post meta API.' );
		}
	}
}
