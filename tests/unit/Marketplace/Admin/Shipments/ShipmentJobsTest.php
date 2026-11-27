<?php
/**
 * The job that keeps the shop's copy of the shipments.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Shipments;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Data\Wpdb_Shipment_Store;
use Woodev\Framework\Marketplace\Jobs\Remote_Error;
use Woodev\Framework\Marketplace\Jobs\Shipment_Jobs;
use Woodev\Tests\Unit\Marketplace\Admin\Support\Fake_Shipment_Marketplace;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Remote_Exception;

/**
 * @covers \Woodev\Framework\Marketplace\Jobs\Shipment_Jobs
 * @covers \Woodev\Framework\Marketplace\Flows\Shipment_Snapshot
 * @covers \Woodev\Framework\Marketplace\Data\Wpdb_Shipment_Store
 */
final class ShipmentJobsTest extends Shipments_Test_Case {
	/**
	 * A refresh reads every page, ties units to their orders and records when it finished.
	 *
	 * @return void
	 */
	public function test_a_refresh_reads_every_page_and_the_units(): void {
		$this->marketplace->page = 1;
		$this->give( 'S-1', 'u101', 'P-101' );
		$this->give( 'S-2', 'u999', 'P-999' );

		$this->refresh();

		$store = $this->shop_store();
		$this->assertSame( 2, $store->count_for_account( $this->account_id ) );
		$this->assertSame( '101', (string) $store->units( $this->account_id, Wpdb_Shipment_Store::key( 'S-1' ) )[0]['order_id'] );
		$this->assertSame( '0', (string) $store->units( $this->account_id, Wpdb_Shipment_Store::key( 'S-2' ) )[0]['order_id'], 'A unit with no order in the shop has none.' );
		$this->assertSame( self::NOW, $store->refreshed_at( $this->account_id ) );
		$this->assertFalse( $store->is_refreshing( $this->account_id ) );
		$this->assertSame( [], $this->scheduler->listed( $this->names()->hook( Shipment_Jobs::JOB_REFRESH ), 'pending' ) );
	}

	/**
	 * A handed-over shipment is read once; the open ones are read again. One the marketplace no longer lists goes.
	 *
	 * @return void
	 */
	public function test_closed_shipments_are_read_once_and_vanished_ones_go(): void {
		$this->give( 'S-1', 'u101', 'P-101' );
		$this->give( 'S-2', 'u102', 'P-102' );
		$this->marketplace->shipments['S-2']['open'] = false;
		$this->refresh();

		$this->marketplace->remote = [];
		$this->refresh();

		$this->assertSame( 1, count( array_keys( $this->marketplace->remote, 'read_shipment', true ) ), 'Only the open one.' );

		unset( $this->marketplace->shipments['S-2'] );
		$this->refresh();

		$store = $this->shop_store();
		$this->assertSame( 1, $store->count_for_account( $this->account_id ) );
		$this->assertSame( [], $store->units( $this->account_id, Wpdb_Shipment_Store::key( 'S-2' ) ), 'Its units go with it.' );
	}

	/**
	 * A handed-over shipment the marketplace is still working on, and one that holds units to ship again, are read again: a unit
	 * becomes movable only then.
	 *
	 * @return void
	 */
	public function test_a_handed_over_shipment_is_read_again_when_it_still_changes(): void {
		$this->give( 'S-1', 'u101', 'P-101' );
		$this->give( 'S-2', 'u102', 'P-102' );
		$this->marketplace->shipments['S-1']['open']     = false;
		$this->marketplace->shipments['S-2']['open']     = false;
		$this->marketplace->shipments['S-2']['changing'] = true;
		$this->refresh();

		$this->marketplace->shipments['S-1']['units']['u101']['movable'] = true;
		$this->marketplace->reship                                       = [ 'S-1' => [ 'u101' ] ];
		$this->marketplace->remote                                       = [];
		$this->refresh();

		$this->assertSame( 2, count( array_keys( $this->marketplace->remote, 'read_shipment', true ) ) );

		$unit = $this->shop_store()->units( $this->account_id, Wpdb_Shipment_Store::key( 'S-1' ) )[0];
		$this->assertSame( '1', (string) $unit['is_movable'] );
		$this->assertSame( '1', (string) $unit['needs_reshipment'] );
	}

	/**
	 * A long list and a long read continue in further runs, and the copy is never emptied on the way.
	 *
	 * @return void
	 */
	public function test_a_run_stops_at_its_budget_and_continues(): void {
		$this->marketplace->page = 1;

		for ( $i = 1; $i <= Shipment_Jobs::PAGES_PER_RUN + 2; $i++ ) {
			$this->marketplace->shipments[ 'S-' . $i ] = Fake_Shipment_Marketplace::remote_shipment( 'N' . $i );
		}

		$this->refresh();

		$store = $this->shop_store();
		$this->assertSame( Shipment_Jobs::PAGES_PER_RUN, $store->count_for_account( $this->account_id ) );
		$this->assertNotContains( 'read_shipment', $this->marketplace->remote );
		$this->assertTrue( $store->is_refreshing( $this->account_id ) );

		$this->run_pending();

		$this->assertSame( Shipment_Jobs::PAGES_PER_RUN + 2, $store->count_for_account( $this->account_id ) );
		$this->assertSame( Shipment_Jobs::PAGES_PER_RUN + 2, count( array_keys( $this->marketplace->remote, 'read_shipment', true ) ), 'Twelve open shipments, each read once across the runs.' );
		$this->assertFalse( $store->is_refreshing( $this->account_id ) );
	}

	/**
	 * More shipments to read than one run takes.
	 *
	 * @return void
	 */
	public function test_reading_continues_in_a_further_run(): void {
		for ( $i = 1; $i <= Shipment_Jobs::READS_PER_RUN + 3; $i++ ) {
			$this->marketplace->shipments[ 'S-' . $i ] = Fake_Shipment_Marketplace::remote_shipment( 'N' . $i );
		}

		$this->refresh();
		$this->assertSame( Shipment_Jobs::READS_PER_RUN, count( array_keys( $this->marketplace->remote, 'read_shipment', true ) ) );

		$this->marketplace->remote = [];
		$this->run_pending();

		$this->assertSame( [ 'read_shipment', 'read_shipment', 'read_shipment' ], $this->marketplace->remote, 'The continuation lists nothing again.' );
	}

	/**
	 * A throttled marketplace is asked again later; a lasting failure of one shipment is skipped, not repeated forever.
	 *
	 * @return void
	 */
	public function test_failures(): void {
		$this->give( 'S-1', 'u101', 'P-101' );
		$this->marketplace->failures['read_shipment'] = Fake_Remote_Exception::of( Remote_Error::THROTTLE, 'slow', 40 );

		$this->refresh();

		$hook    = $this->names()->hook( Shipment_Jobs::JOB_REFRESH );
		$pending = array_values( $this->scheduler->listed( $hook, 'pending' ) );
		$this->assertCount( 1, $pending );

		$this->marketplace->failures = [ 'read_shipment' => Fake_Remote_Exception::of( Remote_Error::RETRY, 'bad shipment' ) ];
		$this->run_pending();

		$this->assertSame( [], $this->shop_store()->stale( $this->account_id, 10 ), 'Put off: no run takes it again at once.' );
		$this->assertSame( self::NOW, $this->shop_store()->refreshed_at( $this->account_id ) );
		$this->assertStringContainsString( 'Не всё удалось прочитать', $this->shop_store()->last_error( $this->account_id ) );
		$this->assertStringContainsString( 'bad shipment', implode( ' ', $this->log ) );
	}

	/**
	 * A handed-over shipment whose first read failed is read by a later refresh, and the error stays until then.
	 *
	 * @return void
	 */
	public function test_a_failed_read_of_a_closed_shipment_is_tried_again(): void {
		$this->give( 'S-1', 'u101', 'P-101' );
		$this->marketplace->shipments['S-1']['open']  = false;
		$this->marketplace->failures['read_shipment'] = new \RuntimeException( 'network' );
		$this->refresh();

		$this->marketplace->failures = [];
		$this->marketplace->remote   = [];
		$this->now                  += 60;
		$this->refresh();

		$this->assertNotContains( 'read_shipment', $this->marketplace->remote, 'Not before the pause is over.' );
		$this->assertStringContainsString( 'Не всё удалось прочитать', $this->shop_store()->last_error( $this->account_id ), 'Nothing was read: the refresh is not complete.' );

		$this->now += Shipment_Jobs::POLL_MINUTES * 60;
		$this->refresh();

		$row = $this->shop_store()->find( $this->account_id, Wpdb_Shipment_Store::key( 'S-1' ) );
		$this->assertNotNull( $row['read_at'] );
		$this->assertSame( '1', (string) $row['unit_count'] );
		$this->assertSame( '', $this->shop_store()->last_error( $this->account_id ), 'Read at last: the error goes.' );
	}

	/**
	 * Every further failure doubles the pause, up to its ceiling, and the shipment is never given up.
	 *
	 * @return void
	 */
	public function test_the_pause_after_a_failed_read_grows_and_is_bounded(): void {
		$this->give( 'S-1', 'u101', 'P-101' );
		$this->marketplace->shipments['S-1']['open']  = false;
		$this->marketplace->failures['read_shipment'] = new \RuntimeException( 'always' );
		$key                                          = Wpdb_Shipment_Store::key( 'S-1' );
		$pauses                                       = [];

		for ( $attempt = 0; $attempt < 9; $attempt++ ) {
			$this->refresh();

			$row       = $this->shop_store()->find( $this->account_id, $key );
			$pauses[]  = strtotime( $row['retry_at'] . ' UTC' ) - $this->now;
			$this->now = strtotime( $row['retry_at'] . ' UTC' );
		}

		$this->assertSame( [ 600, 1200, 2400, 4800, 9600, 19200, 21600, 21600, 21600 ], $pauses );
		$this->assertSame( 9, count( array_keys( $this->marketplace->remote, 'read_shipment', true ) ), 'One try per due refresh.' );
	}

	/**
	 * A shipment whose read after an action failed is read by the next refresh, a handed-over one included.
	 *
	 * @return void
	 */
	public function test_a_failed_read_after_an_action_is_made_up_for(): void {
		$this->give( 'S-1', 'u101', 'P-101' );
		$this->marketplace->shipments['S-1']['open'] = false;
		$this->refresh();

		$this->marketplace->failures['read_shipment'] = new \RuntimeException( 'network' );
		$this->shipment_service()->reread( $this->account(), Wpdb_Shipment_Store::key( 'S-1' ) );

		$this->assertCount( 1, $this->shop_store()->stale( $this->account_id, 10 ) );
	}

	/**
	 * A failed listing keeps the last copy and says why.
	 *
	 * @return void
	 */
	public function test_a_failed_listing_keeps_the_copy(): void {
		$this->give( 'S-1', 'u101', 'P-101' );
		$this->refresh();

		$this->marketplace->failures['list_shipments'] = new \RuntimeException( 'gone' );

		try {
			$this->refresh();
			$this->fail( 'The scheduler records the failure.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'gone', $e->getMessage() );
		}

		$store = $this->shop_store();
		$this->assertSame( 1, $store->count_for_account( $this->account_id ) );
		$this->assertNotSame( '', $store->last_error( $this->account_id ) );
		$this->assertFalse( $store->is_refreshing( $this->account_id ) );
	}

	/**
	 * No licence, no call, and no «refreshing» line left behind: the recurring poll tries again.
	 *
	 * @return void
	 */
	public function test_without_a_licence_the_job_does_nothing(): void {
		$this->shop_store()->mark_refreshing( $this->account_id );
		$this->licensed = false;

		$this->refresh();

		$this->assertSame( [], $this->marketplace->remote );
		$this->assertFalse( $this->shop_store()->is_refreshing( $this->account_id ) );
		$this->assertSame( [], $this->scheduler->listed( $this->names()->hook( Shipment_Jobs::JOB_REFRESH ), 'pending' ) );
	}

	/**
	 * A recurring poll per enabled account, created once.
	 *
	 * @return void
	 */
	public function test_a_recurring_poll_per_account(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$this->shipment_jobs()->ensure_polls();
		$this->shipment_jobs()->ensure_poll_for( $this->account_id );

		$this->assertCount( 1, array_filter( $this->scheduler->actions, fn( array $action ): bool => $action['hook'] === $this->names()->hook( Shipment_Jobs::JOB_POLL ) ) );
	}

	/**
	 * Gives the marketplace a shipment with one unit.
	 *
	 * @param string $shipment Shipment id.
	 * @param string $unit     Unit id.
	 * @param string $posting  Posting key.
	 * @return void
	 */
	private function give( string $shipment, string $unit, string $posting ): void {
		$this->marketplace->shipments[ $shipment ] = Fake_Shipment_Marketplace::remote_shipment( $shipment );

		$this->marketplace->shipments[ $shipment ]['units'][ $unit ] = [
			'posting'      => $posting,
			'label'        => 'Футболка',
			'movable'      => false,
			'requirements' => [],
		];
	}

	/**
	 * Runs the pending refresh actions until none is left, as Action Scheduler does.
	 *
	 * @return void
	 */
	private function run_pending(): void {
		$hook = $this->names()->hook( Shipment_Jobs::JOB_REFRESH );

		for ( $guard = 0; $guard < 10; $guard++ ) {
			$found = null;

			foreach ( $this->scheduler->actions as $index => $action ) {
				if ( $action['hook'] === $hook && 'pending' === $action['status'] ) {
					$found = $index;
					break;
				}
			}

			if ( null === $found ) {
				return;
			}

			$args = $this->scheduler->actions[ $found ]['args'];
			unset( $this->scheduler->actions[ $found ] );

			$this->shipment_jobs()->refresh( ...$args );
		}

		$this->fail( 'The refresh never ends.' );
	}
}
