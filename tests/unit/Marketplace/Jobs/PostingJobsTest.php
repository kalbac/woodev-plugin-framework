<?php
/**
 * Action Scheduler jobs of the posting flow.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Jobs;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Data\Persistence_Exception;
use Woodev\Framework\Marketplace\Flows\History_Import;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\Posting_History_Cutoffs;
use Woodev\Framework\Marketplace\Jobs\Posting_Jobs;
use Woodev\Framework\Marketplace\Jobs\Posting_Poll_Times;
use Woodev\Framework\Marketplace\Jobs\Remote_Error;
use Woodev\Framework\Marketplace\Orders\Order_Claim_Exception;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Posting_Handler;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Remote_Exception;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace_Adapter;
use Woodev\Tests\Unit\Marketplace\Support\Sync_Test_Case;

/**
 * Moved from the Ozon plugin's `PostingFlowTest` (the job half): the refresh queues once and retries a busy order, the poll serves
 * the accounts that make orders or keep the site's stock, and neither calls the marketplace without a licence or for a paused
 * account. The order flow itself is the adapter's `Posting_Handler`.
 */
final class PostingJobsTest extends Sync_Test_Case {
	private const REFRESH = 'woodev_fake_seller_refresh_posting';
	private const POLL    = 'woodev_fake_seller_poll_postings';

	/**
	 * The adapter's order flow.
	 *
	 * @var Fake_Posting_Handler
	 */
	private $handler;

	/**
	 * Options the test set.
	 *
	 * @var array<string,mixed>
	 */
	private $options = [];

	/**
	 * Transients the jobs set.
	 *
	 * @var array<string,string>
	 */
	private $transients = [];

	/**
	 * Builds the handler and the option and transient doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->handler    = new Fake_Posting_Handler();
		$this->options    = [];
		$this->transients = [];

		Functions\when( 'get_option' )->alias(
			function ( string $name, $default = false ) {
				return $this->options[ $name ] ?? $default;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( string $name ) {
				return $this->transients[ $name ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $name, string $value ): bool {
				$this->transients[ $name ] = $value;

				return true;
			}
		);
	}

	/**
	 * Names, group and the donor's job names (queued actions survive the move).
	 *
	 * @return void
	 */
	public function test_the_hooks_are_named_after_the_plugin(): void {
		$this->assertSame( self::REFRESH, $this->jobs()->refresh_hook() );
		$this->assertSame( self::POLL, $this->jobs()->poll_hook() );
	}

	/**
	 * The refresh of a posting is queued once, whatever asks for it.
	 *
	 * @return void
	 */
	public function test_the_refresh_is_queued_once_per_posting(): void {
		$jobs = $this->jobs();

		$jobs->queue_refresh( $this->account_id, 'P-1' );
		$jobs->queue_refresh( $this->account_id, 'P-1', 30 );
		$jobs->queue_refresh( $this->account_id, 'P-2' );

		$queued = $this->scheduler->of( self::REFRESH );
		$this->assertCount( 2, $queued );
		$this->assertSame( [ $this->account_id, 'P-1' ], $queued[0]['args'] );
		$this->assertSame( 'woodev-fake-seller', $queued[0]['group'] );
	}

	/**
	 * Action Scheduler refusing to store the refresh is an error the event or poll page must see.
	 *
	 * @return void
	 */
	public function test_a_refresh_that_could_not_be_stored_is_an_exception(): void {
		$this->scheduler->zero_for[ self::REFRESH ] = true;

		$this->expectException( Persistence_Exception::class );
		$this->jobs()->queue_refresh( $this->account_id, 'P-1' );
	}

	/**
	 * The refresh hands the posting to the marketplace's order flow.
	 *
	 * @return void
	 */
	public function test_the_refresh_runs_the_order_flow(): void {
		$this->jobs()->refresh( (string) $this->account_id, 'P-1' );

		$this->assertSame( [ [ $this->account_id, 'P-1' ] ], $this->handler->refreshed );
		$this->assertSame( [], $this->scheduler->of( self::REFRESH ) );
	}

	/**
	 * A posting another writer holds is tried again in two minutes.
	 *
	 * @return void
	 */
	public function test_a_busy_posting_is_retried_in_two_minutes(): void {
		$this->handler->fails = Order_Claim_Exception::busy( 'P-1' );

		$this->jobs()->refresh( $this->account_id, 'P-1' );

		$this->assertCount( 1, $this->scheduler->of( self::REFRESH ) );
		$this->assertEqualsWithDelta( Order_Claim_Exception::RETRY_SECONDS, $this->scheduler->of( self::REFRESH )[0]['delay'], 2 );
	}

	/**
	 * The first poll of an account fixes the account's history cutoff — the moment it began — and every poll after it, whether the
	 * first one finished the import or only a part of it, writes with that same cutoff (#1925).
	 *
	 * @return void
	 */
	public function test_every_poll_after_the_first_keeps_the_cutoff_of_the_first(): void {
		$jobs = $this->jobs_that_record_polls();

		$before = time();
		$jobs->poll( $this->account_id );
		$after  = time();
		$cutoff = $this->handler->cutoffs[0];

		$this->assertGreaterThanOrEqual( $before, $cutoff );
		$this->assertLessThanOrEqual( $after, $cutoff );

		$jobs->poll( $this->account_id );
		$jobs->poll( $this->account_id );

		$this->assertSame( [ $cutoff, $cutoff, $cutoff ], $this->handler->cutoffs, 'a poll that returned normally did not close the history' );
		$this->assertSame( 0, History_Import::cutoff(), 'the poll closes its history stretch' );
	}

	/**
	 * A refresh — queued by the marketplace's own code, with the two arguments it always had — writes with the cutoff the first poll
	 * fixed, wherever it runs and whoever queued it.
	 *
	 * @return void
	 */
	public function test_a_refresh_writes_with_the_cutoff_of_its_account(): void {
		$jobs = $this->jobs_that_record_polls();

		$jobs->refresh( $this->account_id, 'P-0' );
		$jobs->poll( $this->account_id );
		$jobs->refresh( $this->account_id, 'P-1' );
		$jobs->refresh( (string) $this->account_id, 'P-2' );

		$cutoff = $this->handler->cutoffs[1];
		$this->assertGreaterThan( 0, $cutoff );
		$this->assertSame( [ 0, $cutoff, $cutoff, $cutoff ], $this->handler->cutoffs, 'a refresh before the first poll fixes no cutoff and is live' );
	}

	/**
	 * A poll that failed still fixed the cutoff: the retry is the same history, not a new one.
	 *
	 * @return void
	 */
	public function test_a_failed_first_poll_keeps_its_cutoff_for_the_retry(): void {
		$jobs                 = $this->jobs_that_record_polls();
		$this->handler->fails = Fake_Remote_Exception::of( Remote_Error::RETRY, 'busy' );

		try {
			$jobs->poll( $this->account_id );
		} catch ( Fake_Remote_Exception $e ) {
			$this->assertSame( 0, History_Import::cutoff(), 'a failure closes the history stretch too' );
		}

		$this->handler->fails = null;
		$jobs->poll( $this->account_id );

		$this->assertSame( $this->handler->cutoffs[0], $this->handler->cutoffs[1] );
		$this->assertGreaterThan( 0, $this->handler->cutoffs[0] );
	}

	/**
	 * An account that was polled before the cutoffs were recorded has no history to protect; without the record of polls nothing
	 * tells a first poll from a later one. Both stay live, as they were.
	 *
	 * @return void
	 */
	public function test_an_account_that_was_polled_before_has_no_history(): void {
		$jobs = $this->jobs_that_record_polls();
		( new Posting_Poll_Times( self::PLUGIN_ID ) )->record( $this->account_id, time() - 3600 );

		$jobs->poll( $this->account_id );
		$this->jobs()->poll( $this->account_id );

		$this->assertSame( [ 0, 0 ], $this->handler->cutoffs );
	}

	/**
	 * A refresh that waits for the licence is queued as it always was, with two arguments.
	 *
	 * @return void
	 */
	public function test_a_deferred_refresh_keeps_its_two_arguments(): void {
		$this->licensed = false;
		$this->jobs_that_record_polls()->refresh( $this->account_id, 'P-1' );

		$this->assertSame( [ $this->account_id, 'P-1' ], $this->scheduler->of( self::REFRESH )[0]['args'] );
	}

	/**
	 * A throttled refresh waits as long as the marketplace asked; a temporary failure takes the first step of the ladder.
	 *
	 * @return void
	 */
	public function test_a_temporary_failure_is_requeued_after_its_wait(): void {
		$this->handler->fails = Fake_Remote_Exception::of( Remote_Error::THROTTLE, 'slow', 45 );
		$this->jobs()->refresh( $this->account_id, 'P-1' );
		$this->assertEqualsWithDelta( 45, $this->scheduler->of( self::REFRESH )[0]['delay'], 2 );

		$this->scheduler->actions = [];
		$this->handler->fails     = Fake_Remote_Exception::of( Remote_Error::RETRY, 'bad gateway' );
		$this->jobs()->refresh( $this->account_id, 'P-1' );
		$this->assertEqualsWithDelta( 60, $this->scheduler->of( self::REFRESH )[0]['delay'], 2 );
	}

	/**
	 * A lasting failure, and one nobody classified, reach Action Scheduler (it records the failure).
	 *
	 * @return void
	 */
	public function test_a_lasting_or_unknown_failure_is_rethrown(): void {
		foreach ( [ Fake_Remote_Exception::of( Remote_Error::FAIL, 'no' ), new \RuntimeException( 'boom' ) ] as $failure ) {
			$this->handler->fails = $failure;

			try {
				$this->jobs()->refresh( $this->account_id, 'P-1' );
				$this->fail( 'The failure must reach Action Scheduler.' );
			} catch ( \Throwable $e ) {
				$this->assertSame( $failure, $e );
			}
		}

		$this->assertSame( [], $this->scheduler->of( self::REFRESH ) );
	}

	/**
	 * A refused key pauses the account; the next refresh waits without a call.
	 *
	 * @return void
	 */
	public function test_a_refused_key_pauses_the_account(): void {
		$this->handler->fails = Fake_Remote_Exception::of( Remote_Error::PAUSE_ACCOUNT, 'bad key' );

		try {
			$this->jobs()->refresh( $this->account_id, 'P-1' );
			$this->fail( 'The refusal must reach Action Scheduler.' );
		} catch ( Fake_Remote_Exception $e ) {
			$this->assertSame( [ $this->account_id ], $this->classifier->refused );
		}

		$this->handler->fails = null;
		$this->jobs()->refresh( $this->account_id, 'P-1' );

		$this->assertCount( 1, $this->handler->refreshed, 'no second call while the account is paused' );
		$this->assertCount( 1, $this->scheduler->of( self::REFRESH ), 'the refresh waits for the pause to end' );
		$this->assertGreaterThanOrEqual( 60, $this->scheduler->of( self::REFRESH )[0]['delay'] );
	}

	/**
	 * While the licence is not in force the refresh waits an hour and the order flow is not called.
	 *
	 * @return void
	 */
	public function test_a_lapsed_licence_defers_the_refresh_by_an_hour(): void {
		$this->licensed = false;

		$this->jobs()->refresh( $this->account_id, 'P-1' );

		$this->assertSame( [], $this->handler->refreshed );
		$this->assertEqualsWithDelta( Job_Gate::LICENCE_DELAY, $this->scheduler->of( self::REFRESH )[0]['delay'], 2 );
	}

	/**
	 * A disabled or missing account is left alone.
	 *
	 * @return void
	 */
	public function test_a_disabled_or_missing_account_is_left_alone(): void {
		$this->accounts()->set_enabled( $this->account_id, false );

		$this->jobs()->refresh( $this->account_id, 'P-1' );
		$this->jobs()->refresh( 404, 'P-1' );
		$this->jobs()->poll( $this->account_id );
		$this->jobs()->poll( 404 );

		$this->assertSame( [], $this->handler->refreshed );
		$this->assertSame( [], $this->handler->caught_up );
		$this->assertSame( [], $this->scheduler->actions );
	}

	/**
	 * The poll serves an account that creates orders, or one whose stock the site keeps; one that does neither is skipped.
	 *
	 * @return void
	 */
	public function test_the_poll_serves_the_accounts_that_need_postings(): void {
		$cases = [
			'orders on, site stock'          => [ true, 'site', true ],
			'orders on, marketplace stock'   => [ true, 'marketplace', true ],
			'orders off, site stock'         => [ false, 'site', true ],
			'orders off, marketplace stock'  => [ false, 'marketplace', false ],
		];

		foreach ( $cases as $name => $case ) {
			$this->handler->caught_up = [];
			$this->set_account(
				[
					'orders_enabled' => $case[0],
					'stock_mode'     => $case[1],
				]
			);

			$this->jobs()->poll( (string) $this->account_id );

			$this->assertSame( $case[2] ? [ $this->account_id ] : [], $this->handler->caught_up, $name );
			$this->assertSame( $case[2], Posting_Jobs::wants_postings( $this->account_now() ), $name );
		}
	}

	/**
	 * Without a licence the poll asks the marketplace nothing; a failure of the catch-up reaches Action Scheduler and a refused
	 * key pauses the account.
	 *
	 * @return void
	 */
	public function test_the_poll_respects_the_licence_and_pauses_on_a_refused_key(): void {
		$this->licensed = false;
		$this->jobs()->poll( $this->account_id );
		$this->assertSame( [], $this->handler->caught_up );

		$this->licensed       = true;
		$this->handler->fails = Fake_Remote_Exception::of( Remote_Error::PAUSE_ACCOUNT, 'bad key' );

		try {
			$this->jobs()->poll( $this->account_id );
			$this->fail( 'The failure must reach Action Scheduler.' );
		} catch ( Fake_Remote_Exception $e ) {
			$this->assertSame( [ $this->account_id ], $this->classifier->refused );
		}

		$this->handler->fails = null;
		$this->jobs()->poll( $this->account_id );
		$this->assertCount( 1, $this->handler->caught_up, 'a paused account is not polled' );
	}

	/**
	 * A poll attempt that ended without an error is recorded as «Последний опрос» (a handler that stopped within its budget
	 * included — `catch_up()` cannot say so); a skipped or failed one is not.
	 *
	 * @return void
	 */
	public function test_only_a_finished_poll_is_recorded_as_the_accounts_last_read(): void {
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;

				return true;
			}
		);
		$times = new Posting_Poll_Times( self::PLUGIN_ID );
		$jobs  = new Posting_Jobs( $this->job_gate(), $this->accounts(), $this->handler, $this->classifier, $this->names(), Sqlite_Tables::MARKETPLACE_ID, Posting_Jobs::option_key( self::PLUGIN_ID ), $times );

		$this->licensed = false;
		$jobs->poll( $this->account_id );
		$this->assertNull( $times->last( $this->account_id ), 'Skipped without a licence: nothing was read.' );

		$this->licensed       = true;
		$this->handler->fails = Fake_Remote_Exception::of( Remote_Error::RETRY, 'busy' );

		try {
			$jobs->poll( $this->account_id );
		} catch ( Fake_Remote_Exception $e ) {
			$this->assertNull( $times->last( $this->account_id ), 'A failed read is not a read.' );
		}

		$this->handler->fails = null;
		$before               = time();
		$jobs->poll( $this->account_id );

		$this->assertGreaterThanOrEqual( $before, $times->last( $this->account_id ) );
		$this->assertArrayHasKey( 'woodev_' . self::PLUGIN_ID . '_postings_polled', $this->options, 'In the plugin\'s option family, which the uninstaller takes.' );
	}

	/**
	 * The recurring poll is created once per account that needs it, every `poll_postings_minutes`, and checked at most once an hour.
	 *
	 * @return void
	 */
	public function test_ensure_polls_creates_one_recurring_poll_per_needy_account_once_an_hour(): void {
		$this->options['woodev_fake-seller_poll_postings_minutes'] = 7;

		$intervals = [];
		Functions\when( 'as_schedule_recurring_action' )->alias(
			function ( int $when, int $interval, string $hook, array $args = [], string $group = '' ) use ( &$intervals ): int {
				$intervals[] = $interval;

				return $this->scheduler->add( $hook, $args, 'recurring', 0, $group );
			}
		);

		$jobs = $this->jobs();
		$jobs->ensure_polls();
		$jobs->ensure_polls();

		$polls = $this->scheduler->of( self::POLL );
		$this->assertCount( 1, $polls );
		$this->assertSame( [ $this->account_id ], $polls[0]['args'] );
		$this->assertSame( 'recurring', $polls[0]['kind'] );
		$this->assertSame( [ 420 ], $intervals, 'the saved seven minutes, not the default ten' );
		$this->assertArrayHasKey( 'woodev_fake_seller_poll_check', $this->transients );
	}

	/**
	 * The option key is the settings-API key of the plugin id as it is, dashes kept: a site that saved an interval keeps it. The
	 * underscore form (the hook names') is not read.
	 *
	 * @return void
	 */
	public function test_the_poll_interval_option_keeps_the_plugin_id_as_it_is(): void {
		$this->assertSame( 'woodev_fake-seller_poll_postings_minutes', Posting_Jobs::option_key( 'fake-seller' ) );

		$this->options['woodev_fake_seller_poll_postings_minutes'] = 7;

		$intervals = [];
		Functions\when( 'as_schedule_recurring_action' )->alias(
			function ( int $when, int $interval ) use ( &$intervals ): int {
				$intervals[] = $interval;

				return 1;
			}
		);

		$this->jobs()->ensure_polls();

		$this->assertSame( [ 600 ], $intervals, 'only the key of the saved setting counts' );
	}

	/**
	 * An account that wants no postings, or is switched off, gets no poll; an account that already has one does not get a second.
	 *
	 * @return void
	 */
	public function test_ensure_polls_skips_accounts_that_need_none_and_existing_polls(): void {
		$this->set_account(
			[
				'orders_enabled' => false,
				'stock_mode'     => 'marketplace',
			]
		);
		$this->jobs()->ensure_polls();
		$this->assertSame( [], $this->scheduler->of( self::POLL ) );

		$this->transients = [];
		$this->set_account( [ 'orders_enabled' => true ] );
		$this->jobs()->ensure_polls();
		$this->transients = [];
		$this->jobs()->ensure_polls();
		$this->assertCount( 1, $this->scheduler->of( self::POLL ) );

		$this->transients = [];
		$this->accounts()->set_enabled( $this->account_id, false );
		$this->scheduler->actions = [];
		$this->jobs()->ensure_polls();
		$this->assertSame( [], $this->scheduler->of( self::POLL ) );
	}

	/**
	 * Registering hooks the refresh with its two arguments, the poll and the Action Scheduler init.
	 *
	 * @return void
	 */
	public function test_register_hooks_the_jobs(): void {
		$jobs = $this->jobs();
		$jobs->register();

		$this->assertNotFalse( has_action( self::REFRESH, [ $jobs, 'refresh' ] ) );
		$this->assertNotFalse( has_action( self::POLL, [ $jobs, 'poll' ] ) );
		$this->assertNotFalse( has_action( 'action_scheduler_init', [ $jobs, 'ensure_polls' ] ) );
	}

	/**
	 * Builds the jobs from the fake adapter's answers.
	 *
	 * @return Posting_Jobs
	 */
	private function jobs(): Posting_Jobs {
		$adapter = new Fake_Marketplace_Adapter(
			null,
			[
				'error_classifier' => $this->classifier,
				'posting_handler'  => $this->handler,
			]
		);

		return new Posting_Jobs( $this->job_gate(), $this->accounts(), $adapter->posting_handler(), $adapter->error_classifier(), $this->names(), Sqlite_Tables::MARKETPLACE_ID, Posting_Jobs::option_key( self::PLUGIN_ID ) );
	}

	/**
	 * Jobs that keep the time of each finished poll, as the plugin's wiring does.
	 *
	 * @return Posting_Jobs
	 */
	private function jobs_that_record_polls(): Posting_Jobs {
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( string $name, $value ): bool {
				if ( array_key_exists( $name, $this->options ) ) {
					return false;
				}

				$this->options[ $name ] = $value;

				return true;
			}
		);

		return new Posting_Jobs( $this->job_gate(), $this->accounts(), $this->handler, $this->classifier, $this->names(), Sqlite_Tables::MARKETPLACE_ID, Posting_Jobs::option_key( self::PLUGIN_ID ), new Posting_Poll_Times( self::PLUGIN_ID ), new Posting_History_Cutoffs( self::PLUGIN_ID ) );
	}
}
