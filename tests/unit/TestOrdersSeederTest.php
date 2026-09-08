<?php
/**
 * Unit tests for the rig's SP-10 #830 demo-orders seeding decision
 * (`tests/_fixtures/woodev-test-shipping-method/class-test-orders-seeder.php`).
 *
 * Only the pure half — {@see \Woodev_Test_Orders_Seeder::should_seed()} and
 * {@see \Woodev_Test_Orders_Seeder::demo_orders()} — is exercised here, same split
 * {@see \Woodev_Test_Credential_Seeder} already established
 * (`TestCredentialSeederTest`), so this decision and this shape are testable
 * without a database. The impure half ({@see \Woodev_Test_Orders_Seeder::maybe_seed()})
 * is thin WordPress/WooCommerce glue over this same decision and is exercised on
 * the rig instead (it requires `wc_create_order()`, which does not exist outside a
 * real WooCommerce runtime).
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

require_once dirname( __DIR__, 2 ) . '/tests/_fixtures/woodev-test-shipping-method/class-test-orders-seeder.php';

/**
 * @covers \Woodev_Test_Orders_Seeder
 */
final class TestOrdersSeederTest extends TestCase {

	/**
	 * The rig trigger on and never-seeded-before must seed.
	 */
	public function test_seeds_when_trigger_is_enabled_and_option_is_empty(): void {
		$this->assertTrue( \Woodev_Test_Orders_Seeder::should_seed( true, '' ) );
	}

	/**
	 * The rig trigger off must never seed, regardless of the option's state.
	 */
	public function test_never_seeds_when_the_trigger_is_disabled(): void {
		$this->assertFalse( \Woodev_Test_Orders_Seeder::should_seed( false, '' ) );
		$this->assertFalse( \Woodev_Test_Orders_Seeder::should_seed( false, '1' ) );
	}

	/**
	 * Once seeded, the option is never re-consulted against the trigger — seeding
	 * must never run twice on the same site, even while the trigger stays on.
	 */
	public function test_never_reseeds_once_the_option_is_set(): void {
		$this->assertFalse( \Woodev_Test_Orders_Seeder::should_seed( true, '1' ) );
	}

	/**
	 * Three demo orders are declared: the two mapped raw statuses ('ON_THE_WAY',
	 * 'ARRIVED_PVZ') and the provider's own deliberately unmapped raw value
	 * ('LOST_IN_TRANSIT') — its analogous gap to `realistic`'s omitted
	 * `CUSTOMS_HOLD', see `init_test_shipping_orders_page()`'s own docblock —
	 * present so the unknown-status branch is visible on THIS carrier's tab too,
	 * not only in a test.
	 */
	public function test_demo_orders_include_the_deliberately_unmapped_raw_status(): void {
		$raw_statuses = array_column( \Woodev_Test_Orders_Seeder::demo_orders(), 'raw_status' );

		$this->assertContains( 'LOST_IN_TRANSIT', $raw_statuses );
		$this->assertNotEmpty( array_diff( $raw_statuses, [ 'LOST_IN_TRANSIT' ] ), 'at least one demo order must carry a MAPPED raw status too' );
	}

	/**
	 * At least two demo orders — the counter (D6) needs more than one summand to
	 * be a genuine SUM rather than a single carrier's own count.
	 */
	public function test_at_least_two_demo_orders_are_declared(): void {
		$this->assertGreaterThanOrEqual( 2, count( \Woodev_Test_Orders_Seeder::demo_orders() ) );
	}

	/**
	 * Every demo order definition carries the three keys `seed_one()` reads —
	 * a malformed definition would only surface as a fatal on the rig, at seed
	 * time, which this test catches without ever touching WordPress.
	 */
	public function test_every_demo_order_definition_has_the_expected_shape(): void {
		foreach ( \Woodev_Test_Orders_Seeder::demo_orders() as $definition ) {
			$this->assertArrayHasKey( 'status', $definition );
			$this->assertArrayHasKey( 'raw_status', $definition );
			$this->assertArrayHasKey( 'tracking', $definition );
			$this->assertIsString( $definition['status'] );
			$this->assertIsString( $definition['raw_status'] );
			$this->assertTrue( null === $definition['tracking'] || is_string( $definition['tracking'] ) );
		}
	}
}
