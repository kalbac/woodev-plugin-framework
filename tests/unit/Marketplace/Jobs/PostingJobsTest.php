<?php
/**
 * Action Scheduler jobs of the posting flow.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Jobs;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Data\Persistence_Exception;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\Posting_Jobs;
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
	 * The recurring poll is created once per account that needs it, every `poll_postings_minutes`, and checked at most once an hour.
	 *
	 * @return void
	 */
	public function test_ensure_polls_creates_one_recurring_poll_per_needy_account_once_an_hour(): void {
		$this->options['woodev_fake_seller_poll_postings_minutes'] = 7;

		$jobs = $this->jobs();
		$jobs->ensure_polls();
		$jobs->ensure_polls();

		$polls = $this->scheduler->of( self::POLL );
		$this->assertCount( 1, $polls );
		$this->assertSame( [ $this->account_id ], $polls[0]['args'] );
		$this->assertSame( 'recurring', $polls[0]['kind'] );
		$this->assertArrayHasKey( 'woodev_fake_seller_poll_check', $this->transients );
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

		return new Posting_Jobs( $this->job_gate(), $this->accounts(), $adapter->posting_handler(), $adapter->error_classifier(), $this->names(), Sqlite_Tables::MARKETPLACE_ID );
	}
}
