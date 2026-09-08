<?php
/**
 * Unit: Delivery_Sync_Status — the delivery-status sync freshness seam (SP-10 spec D9,
 * #828). Covers the nullable states the spec calls out explicitly: never synced, no
 * cron hook, and (paired with OrdersControllerSyncStatusTest) an aggregate where one
 * carrier is much staler than another.
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace Woodev\Tests\Unit\Shipping\Order;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Order\Delivery_Sync_Status;
use Woodev\Tests\Unit\TestCase;

final class DeliverySyncStatusTest extends TestCase {

	/**
	 * In-memory fake of the wp_options table, keyed by option name.
	 *
	 * @var array<string,mixed>
	 */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();

		$this->options = [];

		Functions\when( 'sanitize_key' )->alias(
			static function ( $key ) {
				$key = strtolower( (string) $key );
				return preg_replace( '/[^a-z0-9_\-]/', '', $key );
			}
		);

		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);

		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return $this->options[ $name ] ?? $default;
			}
		);
	}

	// ---- last_updated: the "never synced" state ----

	public function test_a_carrier_that_never_synced_returns_null(): void {
		$this->assertNull( Delivery_Sync_Status::get_last_updated( 'cdek' ) );
	}

	public function test_record_then_get_round_trips_the_timestamp(): void {
		Delivery_Sync_Status::record_last_updated( 'cdek', 1700000000 );

		$this->assertSame( 1700000000, Delivery_Sync_Status::get_last_updated( 'cdek' ) );
	}

	/**
	 * `time()` is a native PHP function Patchwork does not redefine by default (only
	 * `function_exists`/`error_log` are configured in patchwork.json), so this pins
	 * the default via a real wall-clock bracket rather than stubbing it.
	 */
	public function test_record_last_updated_defaults_to_now_when_no_timestamp_is_given(): void {
		$before = time();

		Delivery_Sync_Status::record_last_updated( 'cdek' );

		$after     = time();
		$recorded  = Delivery_Sync_Status::get_last_updated( 'cdek' );

		$this->assertNotNull( $recorded );
		$this->assertGreaterThanOrEqual( $before, $recorded );
		$this->assertLessThanOrEqual( $after, $recorded );
	}

	public function test_a_blank_carrier_id_records_nothing(): void {
		Functions\expect( 'update_option' )->never();

		Delivery_Sync_Status::record_last_updated( '', 1700000000 );

		$this->assertNull( Delivery_Sync_Status::get_last_updated( '' ) );
	}

	public function test_get_last_updated_treats_a_blank_id_as_never_synced_without_reading_storage(): void {
		Functions\expect( 'get_option' )->never();

		$this->assertNull( Delivery_Sync_Status::get_last_updated( '' ) );
	}

	/**
	 * A stored value that is not a positive int (corrupted option, or some other code
	 * writing `0`/a non-numeric value directly) must fail closed to "never synced" —
	 * never surface a bogus timestamp.
	 */
	public function test_a_non_positive_stored_value_reads_back_as_never_synced(): void {
		$this->options[ Delivery_Sync_Status::OPTION_PREFIX . 'cdek' ] = 0;

		$this->assertNull( Delivery_Sync_Status::get_last_updated( 'cdek' ) );
	}

	// ---- two carriers never collide (one option per carrier) ----

	public function test_two_carriers_are_stored_independently(): void {
		Delivery_Sync_Status::record_last_updated( 'cdek', 1700000000 );
		Delivery_Sync_Status::record_last_updated( 'yandex', 1650000000 );

		$this->assertSame( 1700000000, Delivery_Sync_Status::get_last_updated( 'cdek' ) );
		$this->assertSame( 1650000000, Delivery_Sync_Status::get_last_updated( 'yandex' ) );
	}

	// ---- next_update: no cron hook ----

	public function test_get_next_update_is_null_when_the_carrier_declares_no_cron_hook(): void {
		Functions\expect( 'wp_next_scheduled' )->never();

		$this->assertNull( Delivery_Sync_Status::get_next_update( null ) );
	}

	public function test_get_next_update_is_null_for_an_empty_cron_hook_string(): void {
		Functions\expect( 'wp_next_scheduled' )->never();

		$this->assertNull( Delivery_Sync_Status::get_next_update( '' ) );
	}

	public function test_get_next_update_reads_wp_next_scheduled_for_a_declared_hook(): void {
		Functions\when( 'wp_next_scheduled' )->alias(
			static fn( $hook ) => 'wc_edostavka_orders_update' === $hook ? 1700003600 : false
		);

		$this->assertSame( 1700003600, Delivery_Sync_Status::get_next_update( 'wc_edostavka_orders_update' ) );
	}

	/**
	 * A declared hook with nothing currently scheduled (e.g. the cron toggle is off)
	 * must also read as null, not zero or false.
	 */
	public function test_get_next_update_is_null_when_nothing_is_currently_scheduled(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( false );

		$this->assertNull( Delivery_Sync_Status::get_next_update( 'wc_edostavka_orders_update' ) );
	}

	// ---- extension hooks ----

	public function test_record_last_updated_fires_the_recorded_action(): void {
		Actions\expectDone( 'woodev_shipping_delivery_sync_recorded' )->once()->with( 'cdek', 1700000000 );

		Delivery_Sync_Status::record_last_updated( 'cdek', 1700000000 );
	}

	public function test_record_last_updated_applies_the_timestamp_filter(): void {
		Filters\expectApplied( 'woodev_shipping_delivery_sync_timestamp' )
			->once()
			->with( 1700000000, 'cdek' )
			->andReturn( 1234 );

		Delivery_Sync_Status::record_last_updated( 'cdek', 1700000000 );

		$this->assertSame( 1234, Delivery_Sync_Status::get_last_updated( 'cdek' ), 'the FILTERED timestamp must be what gets stored' );
	}
}
