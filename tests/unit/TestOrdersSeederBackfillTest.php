<?php
/**
 * Unit tests for the pure half of the demo-orders seeders' backfill (#868): which fields of an
 * EXISTING seeded order get written when `SEED_VERSION` rises.
 *
 * Covers both fixtures' seeders — `Woodev_Test_Orders_Seeder` and `Woodev_Realistic_Orders_Seeder`
 * — with one shared contract: fill EMPTY fields only, never overwrite a non-empty one, idempotent.
 * The impure half (`backfill_existing_orders()`, which walks real orders through the WooCommerce
 * CRUD and skips foreign ones) is covered by the integration test
 * `tests/integration/Shipping/OrdersSeederBackfillDatastoresTest.php`.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

require_once dirname( __DIR__, 2 ) . '/tests/_fixtures/woodev-test-shipping-method/class-test-orders-seeder.php';
require_once dirname( __DIR__, 2 ) . '/tests/_fixtures/woodev-realistic-shipping-plugin/includes/class-realistic-orders-seeder.php';

/**
 * @covers \Woodev_Test_Orders_Seeder::backfill_plan
 * @covers \Woodev_Realistic_Orders_Seeder::backfill_plan
 */
final class TestOrdersSeederBackfillTest extends TestCase {

	/**
	 * @return array<string, array{0:class-string}>
	 */
	public static function seeder_provider(): array {
		return [
			'test-shipping-method seeder' => [ \Woodev_Test_Orders_Seeder::class ],
			'realistic-shipping seeder'   => [ \Woodev_Realistic_Orders_Seeder::class ],
		];
	}

	/**
	 * An order with nothing set gets every persona and payment field.
	 *
	 * @dataProvider seeder_provider
	 *
	 * @param class-string $seeder seeder class under test.
	 */
	public function test_fills_every_field_of_an_empty_order( string $seeder ): void {
		$plan = $seeder::backfill_plan( 7, [] );

		foreach ( [ 'billing_first_name', 'billing_last_name', 'billing_email', 'billing_city', 'billing_address_1', 'billing_country', 'shipping_city', 'shipping_address_1', 'payment_method', 'payment_method_title' ] as $field ) {
			$this->assertArrayHasKey( $field, $plan, $field . ' must be backfilled on an empty order' );
			$this->assertNotSame( '', $plan[ $field ] );
		}
	}

	/**
	 * A non-empty value — a manual rig edit — is never in the plan, so it can never be overwritten;
	 * only the still-empty fields are.
	 *
	 * @dataProvider seeder_provider
	 *
	 * @param class-string $seeder seeder class under test.
	 */
	public function test_keeps_non_empty_values_and_fills_only_the_empty_ones( string $seeder ): void {
		$plan = $seeder::backfill_plan(
			7,
			[
				'billing_first_name' => 'Ручное Имя',
				'billing_city'       => 'Ручной город',
				'payment_method'     => 'cod',
			]
		);

		$this->assertArrayNotHasKey( 'billing_first_name', $plan );
		$this->assertArrayNotHasKey( 'billing_city', $plan );
		$this->assertArrayNotHasKey( 'payment_method', $plan );
		$this->assertArrayHasKey( 'billing_last_name', $plan, 'a sibling field that IS empty still gets filled' );
	}

	/**
	 * Applying a plan and planning again yields nothing: a second run changes nothing.
	 *
	 * @dataProvider seeder_provider
	 *
	 * @param class-string $seeder seeder class under test.
	 */
	public function test_is_idempotent( string $seeder ): void {
		foreach ( [ 1, 2, 3, 10, 231, 4096 ] as $order_id ) {
			$state = $seeder::backfill_plan( $order_id, [] );

			$this->assertSame( [], $seeder::backfill_plan( $order_id, $state ), "order {$order_id}: second pass must be empty" );
		}
	}

	/**
	 * An order that already has a payment METHOD but no title gets that method's own title —
	 * never the id-derived method's, which would contradict the kept method.
	 *
	 * @dataProvider seeder_provider
	 *
	 * @param class-string $seeder seeder class under test.
	 */
	public function test_a_kept_payment_method_gets_its_own_title( string $seeder ): void {
		$titles = array_column( $seeder::payment_method_pool(), 'title', 'method' );

		$plan = $seeder::backfill_plan( 3, [ 'payment_method' => 'cod' ] );

		$this->assertSame( $titles['cod'], $plan['payment_method_title'] );

		// A method outside the pool has no known title: nothing is invented.
		$this->assertArrayNotHasKey( 'payment_method_title', $seeder::backfill_plan( 3, [ 'payment_method' => 'some_other_gateway' ] ) );
	}

	/**
	 * The same order id always derives the same values, and different ids cycle through the
	 * personas rather than collapsing onto one.
	 *
	 * @dataProvider seeder_provider
	 *
	 * @param class-string $seeder seeder class under test.
	 */
	public function test_derivation_is_deterministic_and_cycles( string $seeder ): void {
		$this->assertSame( $seeder::backfill_plan( 42, [] ), $seeder::backfill_plan( 42, [] ) );

		$emails = [];

		foreach ( range( 1, count( $seeder::customer_pool() ) ) as $order_id ) {
			$emails[ $seeder::backfill_plan( $order_id, [] )['billing_email'] ] = true;
		}

		$this->assertCount( count( $seeder::customer_pool() ), $emails );
	}

	/**
	 * The line-item quantity varies across ids within 1..3, like fresh seeding.
	 */
	public function test_backfill_qty_stays_within_the_seeded_range(): void {
		foreach ( range( 1, 30 ) as $order_id ) {
			$this->assertGreaterThanOrEqual( 1, \Woodev_Test_Orders_Seeder::backfill_qty( $order_id ) );
			$this->assertLessThanOrEqual( 3, \Woodev_Test_Orders_Seeder::backfill_qty( $order_id ) );
			$this->assertSame( \Woodev_Test_Orders_Seeder::backfill_qty( $order_id ), \Woodev_Realistic_Orders_Seeder::backfill_qty( $order_id ) );
		}
	}

	/**
	 * The realistic seeder alternates its two shipping methods for an order that has none.
	 */
	public function test_realistic_method_id_is_one_of_its_two_methods(): void {
		$seen = [];

		foreach ( range( 1, 4 ) as $order_id ) {
			$seen[] = \Woodev_Realistic_Orders_Seeder::backfill_method_id( $order_id );
		}

		$this->assertSame( [], array_diff( $seen, \Woodev_Realistic_Orders_Seeder::METHOD_IDS ) );
		$this->assertCount( 2, array_unique( $seen ) );
	}
}
