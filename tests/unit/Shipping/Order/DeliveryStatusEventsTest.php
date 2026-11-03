<?php
/**
 * Unit tests for the shared delivery status change event.
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace Woodev\Tests\Unit\Shipping\Order;

use Brain\Monkey\Actions;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Order\{Delivery_Status, Delivery_Status_Events, Delivery_Status_Log};
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-order-lock.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-delivery-status-log.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-delivery-status-events.php';
require_once __DIR__ . '/delivery-status-order-fakes.php';

/** @covers \Woodev\Framework\Shipping\Order\Delivery_Status_Events */
final class DeliveryStatusEventsTest extends TestCase {

	use Fakes_Status_Orders;

	protected function setUp(): void {
		parent::setUp();
		$this->fake_status_orders();
	}

	protected function tearDown(): void {
		$this->restore_status_wpdb();
		parent::tearDown();
	}

	private function provider(): Orders_Provider {
		return Orders_Provider::create(
			'test',
			'Тестовая доставка',
			'_test_marker',
			[ 'test_shipping' ],
			[
				'status_meta_key' => '_test_status',
				'status_map'      => [
					'NEW'       => Delivery_Status::CREATED,
					'ROAD'      => Delivery_Status::IN_TRANSIT,
					'DELIVERED' => Delivery_Status::DELIVERED,
				],
			]
		);
	}

	private function order(): \WC_Order {
		return $this->snapshot_order();
	}

	public function test_notify_resolves_and_publishes_the_current_canonical_state(): void {
		$this->meta['_test_status'] = 'ROAD';
		$order                      = $this->order();
		$provider                   = $this->provider();

		Actions\expectDone( 'woodev_shipping_delivery_status_changed' )
			->once()
			->with( $order, Delivery_Status::CREATED, Delivery_Status::IN_TRANSIT, $provider );

		$this->assertSame( Delivery_Status::IN_TRANSIT, Delivery_Status_Events::notify( $order, $provider, Delivery_Status::CREATED ) );
		$this->assertSame( Delivery_Status::IN_TRANSIT, $this->meta['_woodev_delivery_status_published_test'], 'the published state is remembered' );
	}

	public function test_notify_publishes_nothing_when_the_canonical_state_did_not_change(): void {
		$this->meta['_test_status'] = 'ROAD';

		Actions\expectDone( 'woodev_shipping_delivery_status_changed' )->never();

		$this->assertSame( Delivery_Status::IN_TRANSIT, Delivery_Status_Events::notify( $this->order(), $this->provider(), Delivery_Status::IN_TRANSIT ) );
	}

	public function test_a_null_previous_state_means_the_last_one_published(): void {
		$this->meta['_test_status']                    = 'ROAD';
		$this->meta['_woodev_delivery_status_published_test'] = Delivery_Status::IN_TRANSIT;

		Actions\expectDone( 'woodev_shipping_delivery_status_changed' )->never();

		// A cron re-poll that re-writes the same raw status and calls notify() with no previous state.
		Delivery_Status_Events::notify( $this->order(), $this->provider() );
	}

	public function test_a_first_ever_status_is_published_with_a_null_previous_state(): void {
		$this->meta['_test_status'] = 'NEW';
		$order                      = $this->order();
		$provider                   = $this->provider();

		Actions\expectDone( 'woodev_shipping_delivery_status_changed' )->once()->with( $order, null, Delivery_Status::CREATED, $provider );

		Delivery_Status_Events::notify( $order, $provider );
	}

	public function test_sync_publishes_a_real_change_against_the_remembered_state(): void {
		$this->meta['_test_status']                    = 'DELIVERED';
		$this->meta['_woodev_delivery_status_published_test'] = Delivery_Status::IN_TRANSIT;
		$order                                         = $this->order();
		$provider                                      = $this->provider();

		Actions\expectDone( 'woodev_shipping_delivery_status_changed' )->once()->with( $order, Delivery_Status::IN_TRANSIT, Delivery_Status::DELIVERED, $provider );

		$this->assertSame( Delivery_Status::DELIVERED, Delivery_Status_Events::sync( $order, $provider ) );
		$this->assertNull( Delivery_Status_Events::sync( $order, $provider ), 'the same state again publishes nothing' );
	}

	/**
	 * Shipments already in flight when the watcher started have no remembered state; their next re-poll must not
	 * mail the buyer an old status. They are adopted silently.
	 */
	public function test_sync_adopts_an_order_with_no_remembered_state_silently(): void {
		$this->meta['_test_status'] = 'ROAD';

		Actions\expectDone( 'woodev_shipping_delivery_status_changed' )->never();

		$this->assertNull( Delivery_Status_Events::sync( $this->order(), $this->provider() ) );
		$this->assertSame( Delivery_Status::IN_TRANSIT, $this->meta['_woodev_delivery_status_published_test'] );
	}

	public function test_sync_publishes_the_first_write_of_a_status(): void {
		$this->meta['_test_status'] = 'NEW';
		$order                      = $this->order();
		$provider                   = $this->provider();

		Actions\expectDone( 'woodev_shipping_delivery_status_changed' )->once()->with( $order, null, Delivery_Status::CREATED, $provider );

		$this->assertSame( Delivery_Status::CREATED, Delivery_Status_Events::sync( $order, $provider, true ) );
	}

	/** A real change is journalled once, with its time, in the carrier's own log (#1564). */
	public function test_a_published_change_is_appended_to_the_status_log(): void {
		$this->meta['_test_status'] = 'ROAD';
		$before                     = time();

		Delivery_Status_Events::notify( $this->order(), $this->provider(), Delivery_Status::CREATED );

		$log = Delivery_Status_Log::decode( $this->meta['_woodev_delivery_status_log_test'] );
		$this->assertCount( 1, $log );
		$this->assertSame( Delivery_Status::IN_TRANSIT, $log[0][0] );
		$this->assertGreaterThanOrEqual( $before, $log[0][1] );
	}

	public function test_nothing_is_logged_when_the_state_did_not_change(): void {
		$this->meta['_test_status'] = 'ROAD';

		Delivery_Status_Events::notify( $this->order(), $this->provider(), Delivery_Status::IN_TRANSIT );
		Delivery_Status_Events::notify( $this->order(), $this->provider() );

		$this->assertArrayNotHasKey( '_woodev_delivery_status_log_test', $this->meta );
	}

	public function test_the_log_follows_the_states_and_a_re_poll_adds_nothing(): void {
		$order    = $this->order();
		$provider = $this->provider();

		$this->meta['_test_status'] = 'NEW';
		Delivery_Status_Events::sync( $order, $provider, true );
		$this->meta['_test_status'] = 'ROAD';
		Delivery_Status_Events::sync( $order, $provider );
		Delivery_Status_Events::sync( $order, $provider );
		$this->meta['_test_status'] = 'DELIVERED';
		Delivery_Status_Events::sync( $order, $provider );

		$this->assertSame(
			[ Delivery_Status::CREATED, Delivery_Status::IN_TRANSIT, Delivery_Status::DELIVERED ],
			array_column( Delivery_Status_Log::decode( $this->meta['_woodev_delivery_status_log_test'] ), 0 )
		);
	}

	/** An order adopted silently (already in flight when the framework started) gets no back-filled entry. */
	public function test_an_order_adopted_silently_is_not_back_filled(): void {
		$this->meta['_test_status'] = 'ROAD';

		Delivery_Status_Events::sync( $this->order(), $this->provider() );

		$this->assertArrayNotHasKey( '_woodev_delivery_status_log_test', $this->meta );
	}

	/**
	 * A CDEK-like carrier: «received at the sender's warehouse» is still `created` to the framework, but the provider
	 * declares it as the moment the carrier holds the parcel.
	 *
	 * @param string[] $handover raw statuses declared as the handover.
	 */
	private function warehouse_provider( array $handover = [ 'AT_WAREHOUSE' ] ): Orders_Provider {
		return Orders_Provider::create(
			'test',
			'Тестовая доставка',
			'_test_marker',
			[ 'test_shipping' ],
			[
				'status_meta_key'   => '_test_status',
				'status_map'        => [
					'REGISTERED'   => Delivery_Status::CREATED,
					'AT_WAREHOUSE' => Delivery_Status::CREATED,
					'ROAD'         => Delivery_Status::IN_TRANSIT,
					'AT_PICKUP'    => Delivery_Status::READY_FOR_PICKUP,
					'DELIVERED'    => Delivery_Status::DELIVERED,
				],
				'handover_statuses' => $handover,
			]
		);
	}

	/** @return array<int,array{0:string,1:string}> the stored log as `[ state, raw ]` pairs. */
	private function logged(): array {
		return array_map(
			static fn( array $entry ): array => [ $entry[0], $entry[2] ],
			Delivery_Status_Log::decode( $this->meta['_woodev_delivery_status_log_test'] ?? '' )
		);
	}

	/** Every entry keeps the carrier's raw status beside the canonical state it produced (#1564). */
	public function test_an_entry_keeps_the_raw_status_that_produced_it(): void {
		$provider = $this->warehouse_provider();

		$this->meta['_test_status'] = 'REGISTERED';
		Delivery_Status_Events::sync( $this->order(), $provider, true );
		$this->meta['_test_status'] = 'ROAD';
		Delivery_Status_Events::sync( $this->order(), $provider );

		$this->assertSame( [ [ 'created', 'REGISTERED' ], [ 'in_transit', 'ROAD' ] ], $this->logged() );
	}

	/**
	 * The raw code of a declared handover is journalled even though the canonical state stays `created`: without it the
	 * warehouse receipt would never reach the log and the metric would start two days late.
	 */
	public function test_a_declared_handover_code_gets_an_entry_although_the_state_is_unchanged(): void {
		$provider = $this->warehouse_provider();

		Actions\expectDone( 'woodev_shipping_delivery_status_changed' )->once();

		$this->meta['_test_status'] = 'REGISTERED';
		Delivery_Status_Events::sync( $this->order(), $provider, true );
		$this->meta['_test_status'] = 'AT_WAREHOUSE';
		$this->assertNull( Delivery_Status_Events::sync( $this->order(), $provider ), 'the state did not change: no event' );
		Delivery_Status_Events::sync( $this->order(), $provider );

		$this->assertSame( [ [ 'created', 'REGISTERED' ], [ 'created', 'AT_WAREHOUSE' ] ], $this->logged(), 'one entry, however often it is re-polled' );
	}

	public function test_a_provider_that_declares_nothing_gets_no_extra_entry(): void {
		$provider = $this->warehouse_provider( [] );

		$this->meta['_test_status'] = 'REGISTERED';
		Delivery_Status_Events::sync( $this->order(), $provider, true );
		$this->meta['_test_status'] = 'AT_WAREHOUSE';
		Delivery_Status_Events::sync( $this->order(), $provider );

		$this->assertSame( [ [ 'created', 'REGISTERED' ] ], $this->logged() );
	}

	/** A shipment adopted while already in flight has no history to extend: a handover code must not be stamped «now». */
	public function test_an_adopted_order_gets_no_entry_for_a_declared_handover_code(): void {
		$provider = $this->warehouse_provider();

		$this->meta['_test_status'] = 'AT_WAREHOUSE';
		Delivery_Status_Events::sync( $this->order(), $provider );
		Delivery_Status_Events::sync( $this->order(), $provider );

		$this->assertArrayNotHasKey( '_woodev_delivery_status_log_test', $this->meta );
		$this->assertSame( Delivery_Status::CREATED, $this->meta['_woodev_delivery_status_published_test'] );
	}

	/**
	 * The lost update: two requests loaded the order at the same moment, so both hold a snapshot with the log
	 * `[ created ]`. The one that runs second must append to what the first wrote, not to its own stale copy.
	 */
	public function test_a_request_with_a_stale_snapshot_appends_to_what_the_other_request_wrote(): void {
		$provider = $this->provider();

		$this->meta['_test_status'] = 'NEW';
		Delivery_Status_Events::sync( $this->order(), $provider, true );

		$request_a = $this->order();
		$request_b = $this->order();

		$seen = [];
		Actions\expectDone( 'woodev_shipping_delivery_status_changed' )->whenHappen(
			function ( $order, $previous, $current ) use ( &$seen ) {
				$seen[] = [ $previous, $current ];
			}
		);

		$this->meta['_test_status'] = 'ROAD';
		Delivery_Status_Events::sync( $request_a, $provider );
		$this->meta['_test_status'] = 'DELIVERED';
		Delivery_Status_Events::sync( $request_b, $provider );

		$this->assertSame(
			[ Delivery_Status::CREATED, Delivery_Status::IN_TRANSIT, Delivery_Status::DELIVERED ],
			array_column( $this->logged(), 0 ),
			'the in-transit entry survived the second request'
		);
		$this->assertSame( Delivery_Status::DELIVERED, $this->meta['_woodev_delivery_status_published_test'] );
		$this->assertSame(
			[ [ Delivery_Status::CREATED, Delivery_Status::IN_TRANSIT ], [ Delivery_Status::IN_TRANSIT, Delivery_Status::DELIVERED ] ],
			$seen,
			'the second request compared with the state the first one published, not with its own stale copy'
		);
	}

	/** The request that lost the race for a change the other already recorded neither logs nor announces it again. */
	public function test_a_change_another_request_already_recorded_is_not_recorded_twice(): void {
		$provider = $this->provider();

		$this->meta['_test_status'] = 'NEW';
		Delivery_Status_Events::sync( $this->order(), $provider, true );

		$request_a = $this->order();
		$request_b = $this->order();

		Actions\expectDone( 'woodev_shipping_delivery_status_changed' )->once();

		$this->meta['_test_status'] = 'ROAD';
		Delivery_Status_Events::sync( $request_a, $provider );
		Delivery_Status_Events::sync( $request_b, $provider );

		$this->assertSame( [ Delivery_Status::CREATED, Delivery_Status::IN_TRANSIT ], array_column( $this->logged(), 0 ) );
	}

	public function test_the_decision_is_taken_under_the_order_lock_and_the_lock_is_released(): void {
		$this->meta['_test_status'] = 'ROAD';

		Delivery_Status_Events::notify( $this->order(), $this->provider(), Delivery_Status::CREATED );

		$this->assertSame( [ 'acquire', 'release' ], $this->lock_wpdb->log );
		$this->assertSame( 1, $this->saves, 'the log and the published state are saved together, once' );
	}

	public function test_an_ordinary_re_poll_takes_no_lock_and_writes_nothing(): void {
		$this->meta['_test_status']                           = 'ROAD';
		$this->meta['_woodev_delivery_status_published_test'] = Delivery_Status::IN_TRANSIT;

		Delivery_Status_Events::sync( $this->order(), $this->provider() );

		$this->assertSame( [], $this->lock_wpdb->log );
		$this->assertSame( 0, $this->saves );
	}

	/** A lock that is not granted records nothing — not even the published state — so the next call retries the change. */
	public function test_a_lock_that_is_not_granted_leaves_the_change_to_be_retried(): void {
		$provider = $this->provider();

		$this->meta['_test_status']  = 'ROAD';
		$this->lock_wpdb->grant      = false;

		Actions\expectDone( 'woodev_shipping_delivery_status_changed' )->once();

		$this->assertNull( Delivery_Status_Events::sync( $this->order(), $provider, true ), 'nothing was published' );
		$this->assertSame( Delivery_Status::IN_TRANSIT, Delivery_Status_Events::notify( $this->order(), $provider ), 'the state is still answered' );
		$this->assertArrayNotHasKey( '_woodev_delivery_status_published_test', $this->meta );
		$this->assertArrayNotHasKey( '_woodev_delivery_status_log_test', $this->meta );
		$this->assertNotContains( 'release', $this->lock_wpdb->log, 'a lock never held is never released' );

		$this->lock_wpdb->grant = true;

		$this->assertSame( Delivery_Status::IN_TRANSIT, Delivery_Status_Events::sync( $this->order(), $provider, true ), 'the retry publishes it' );
		$this->assertSame( [ Delivery_Status::IN_TRANSIT ], array_column( $this->logged(), 0 ) );
	}

	/** A failed save releases the lock, leaves the published state behind the change, and lets the error out: the next call retries. */
	public function test_a_failed_save_releases_the_lock_and_keeps_the_change_retryable(): void {
		$provider = $this->provider();

		$this->meta['_test_status'] = 'ROAD';

		Actions\expectDone( 'woodev_shipping_delivery_status_changed' )->once();

		try {
			Delivery_Status_Events::sync( $this->snapshot_order( true ), $provider, true );
			$this->fail( 'the database error is not swallowed' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'database error', $e->getMessage() );
		}

		$this->assertSame( [ 'acquire', 'release' ], $this->lock_wpdb->log );
		$this->assertArrayNotHasKey( '_woodev_delivery_status_published_test', $this->meta );

		$this->assertSame( Delivery_Status::IN_TRANSIT, Delivery_Status_Events::sync( $this->order(), $provider, true ) );
		$this->assertSame( [ Delivery_Status::IN_TRANSIT ], array_column( $this->logged(), 0 ) );
	}
}
