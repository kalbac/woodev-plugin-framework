<?php
/**
 * The deletion's queue step on the real Action Scheduler.
 *
 * The unit test's stand-in store passes whatever it is given; the real `ActionScheduler_DBStore::query_actions()` throws for a
 * second argument other than `select` or `count`. Here the cleaner reads and cancels actions that the real store holds.
 *
 * @package Woodev\Tests\Integration
 */

namespace Woodev\Tests\Integration\Marketplace;

use Woodev\Framework\Marketplace\Deletion\Account_Queue_Cleaner;
use Woodev\Tests\Integration\TestCase;

class AccountQueueCleanerActionSchedulerTest extends TestCase {
	private const GROUP  = 'woodev-itest-seller';
	private const PREFIX = 'woodev_itest_seller_';

	/**
	 * Only the account's own pending jobs are cancelled, and they leave the pending list.
	 *
	 * @return void
	 */
	public function test_the_cleaner_cancels_an_accounts_pending_jobs_on_the_real_store(): void {
		$ours = [
			$this->schedule( 'flush_sync', [ 7 ] ),
			$this->schedule( 'link_cards', [ 7, 0, 0 ] ),
		];
		$kept = [
			$this->schedule( 'flush_sync', [ 8 ] ),
			$this->schedule( 'poll_task', [ 7, 0 ] ),
			$this->schedule( 'delete_account', [ 7, 0 ] ),
			$this->schedule( 'flush_sync', [ 0 ] ),
			$this->schedule( 'flush_sync', [ 7 ], 'woodev_other_plugin_' ),
		];

		$cleaner = new Account_Queue_Cleaner( self::GROUP, self::PREFIX, [ self::PREFIX . 'poll_task', self::PREFIX . 'delete_account' ] );

		$this->assertSame( 2, $cleaner->cancel_for( 7 ) );

		foreach ( $ours as $id ) {
			$this->assertSame( \ActionScheduler_Store::STATUS_CANCELED, \ActionScheduler::store()->get_status( (string) $id ), 'our job is cancelled' );
		}

		foreach ( $kept as $id ) {
			$this->assertSame( \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler::store()->get_status( (string) $id ), 'the others stay pending' );
		}

		$this->assertSame( 0, $cleaner->cancel_for( 7 ), 'a second pass finds nothing left' );
	}

	/**
	 * Schedules a pending job in the plugin's group.
	 *
	 * @param string $job    Job name.
	 * @param array  $args   Arguments.
	 * @param string $prefix Hook prefix.
	 * @return int Action id.
	 */
	private function schedule( string $job, array $args, string $prefix = self::PREFIX ): int {
		$id = as_schedule_single_action( time() + HOUR_IN_SECONDS, $prefix . $job, $args, self::GROUP );

		$this->assertGreaterThan( 0, $id, 'Action Scheduler accepted ' . $prefix . $job );

		return (int) $id;
	}
}
