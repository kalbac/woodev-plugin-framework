<?php
/**
 * The `refresh_catalog` job: account choice, licence and pause, the time budget, retries and what reaches the error ring.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Jobs;

use Brain\Monkey\Actions;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Flows\Refresh_Result;
use Woodev\Framework\Marketplace\Jobs\Account_Pause;
use Woodev\Framework\Marketplace\Jobs\Backoff;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\Refresh_Catalog_Runner;
use Woodev\Framework\Marketplace\Jobs\Refresh_Catalog_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Remote_Error;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Export_Test_Case;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Remote_Exception;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Scripted_Catalog_Refresher;

/**
 * @covers \Woodev\Framework\Marketplace\Jobs\Refresh_Catalog_Runner
 * @covers \Woodev\Framework\Marketplace\Jobs\Refresh_Catalog_Scheduler
 */
final class RefreshCatalogRunnerTest extends Export_Test_Case {
	private const HOOK = 'woodev_fake_seller_refresh_catalog';

	/**
	 * The marketplace's refresher.
	 *
	 * @var Scripted_Catalog_Refresher
	 */
	private $refresher;

	/**
	 * Boots the refresher double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->refresher = new Scripted_Catalog_Refresher();
	}

	/**
	 * Builds the runner.
	 *
	 * @return Refresh_Catalog_Runner
	 */
	private function runner(): Refresh_Catalog_Runner {
		return new Refresh_Catalog_Runner(
			$this->accounts(),
			Sqlite_Tables::MARKETPLACE_ID,
			$this->job_gate(),
			$this->refresher,
			new Refresh_Catalog_Scheduler( $this->names() ),
			$this->classifier,
			$this->ring(),
			[ $this, 'log_line' ],
			$this->clock()
		);
	}

	/**
	 * Adds another account.
	 *
	 * @param string $name    Name.
	 * @param bool   $enabled Whether it is enabled.
	 * @return int Account id.
	 */
	private function another_account( string $name, bool $enabled ): int {
		return $this->accounts()->save(
			new Account(
				0,
				Sqlite_Tables::MARKETPLACE_ID,
				$name,
				$enabled,
				[
					'cabinet_ref' => 'cabinet-' . $name,
					'api_token'   => 'tok-' . $name,
				]
			)
		);
	}

	/**
	 * Lists the ring rows the job wrote itself.
	 *
	 * @return string[] Messages.
	 */
	private function ring_messages(): array {
		return array_column( $this->db->rows( 'SELECT message FROM ' . Sqlite_Tables::table( 'api_errors' ) . " WHERE code = 'refresh_catalog_failed' ORDER BY error_id" ), 'message' );
	}

	/**
	 * Returns the continuations scheduled.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function continuations(): array {
		return $this->scheduler->of( self::HOOK );
	}

	/**
	 * A run for an enabled account refreshes the cache with the freshness limit and the budget, and schedules nothing.
	 *
	 * @return void
	 */
	public function test_a_run_refreshes_the_cache(): void {
		$this->runner()->run( $this->account_id, 0, 0 );

		$this->assertSame( [ [ $this->account_id, self::HOOK, self::NOW, (float) ( self::NOW + Refresh_Catalog_Runner::BUDGET_SECONDS ) ] ], $this->refresher->calls );
		$this->assertSame( [], $this->continuations() );
		$this->assertSame( [], $this->ring_messages() );
	}

	/**
	 * The weekly run has no account (0): the first enabled one pays, a disabled one never does.
	 *
	 * @return void
	 */
	public function test_account_zero_means_the_first_enabled_account(): void {
		$this->accounts()->set_enabled( $this->account_id, false );
		$second = $this->another_account( 'second', true );

		$this->runner()->run( Refresh_Catalog_Runner::FIRST_ENABLED );

		$this->assertSame( [ $second ], array_column( $this->refresher->calls, 0 ) );
	}

	/**
	 * With no enabled account the weekly run has nothing to do.
	 *
	 * @return void
	 */
	public function test_account_zero_without_an_enabled_account_does_nothing(): void {
		$this->accounts()->set_enabled( $this->account_id, false );

		$this->runner()->run( Refresh_Catalog_Runner::FIRST_ENABLED );

		$this->assertSame( [], $this->refresher->calls );
		$this->assertSame( [], $this->continuations() );
	}

	/**
	 * A disabled, deleted or absent account ends its chain whatever the licence says.
	 *
	 * @return void
	 */
	public function test_a_disabled_or_missing_account_ends_its_chain_even_without_a_licence(): void {
		$this->licensed = false;
		$disabled       = $this->another_account( 'off', false );

		$this->runner()->run( $disabled, 123, 1 );
		$this->runner()->run( 9999, 123, 1 );

		$this->assertSame( [], $this->refresher->calls );
		$this->assertSame( [], $this->continuations(), 'a lapsed licence does not keep a dead chain alive' );
	}

	/**
	 * Without a licence the job asks the marketplace nothing and comes back in an hour with the same arguments.
	 *
	 * @return void
	 */
	public function test_without_a_licence_it_reschedules_hourly_and_calls_nothing(): void {
		$this->licensed = false;

		$this->runner()->run( $this->account_id, 777, 2 );

		$this->assertSame( [], $this->refresher->calls );

		$continuations = $this->continuations();

		$this->assertCount( 1, $continuations );
		$this->assertSame( [ $this->account_id, 777, 2 ], $continuations[0]['args'] );
		$this->assertSame( Job_Gate::LICENCE_DELAY, $continuations[0]['delay'] );
	}

	/**
	 * An account in its pause waits for the pause without a call.
	 *
	 * @return void
	 */
	public function test_a_paused_account_waits_for_the_pause(): void {
		$this->pause()->apply( $this->account_now(), new Remote_Error( Remote_Error::PAUSE_ACCOUNT, 'auth', 'key refused' ) );
		$this->scheduler->actions = [];

		$this->runner()->run( $this->account_id, 0, 0 );

		$this->assertSame( [], $this->refresher->calls );

		$continuations = $this->continuations();

		$this->assertCount( 1, $continuations );
		$this->assertGreaterThanOrEqual( 60, $continuations[0]['delay'] );
	}

	/**
	 * A pass that runs out of time continues in a minute with the same freshness limit and attempt count.
	 *
	 * @return void
	 */
	public function test_an_unfinished_pass_continues_with_the_same_limit(): void {
		$this->refresher->result = new Refresh_Result( 3, [], false );

		$this->runner()->run( $this->account_id, 555, 1 );

		$continuations = $this->continuations();

		$this->assertCount( 1, $continuations );
		$this->assertSame( [ $this->account_id, 555, 1 ], $continuations[0]['args'] );
		$this->assertSame( Refresh_Catalog_Runner::CONTINUE_DELAY, $continuations[0]['delay'] );
		$this->assertSame( 555, $this->refresher->calls[0][2], 'the pass keeps its freshness limit' );
	}

	/**
	 * The categories a pass could not refresh reach the ring, one line each.
	 *
	 * @return void
	 */
	public function test_failed_categories_reach_the_ring(): void {
		$this->refresher->result = new Refresh_Result( 1, [ 'attributes:11:22' => 'нет ответа' ], true );

		$this->runner()->run( $this->account_id );

		$messages = $this->ring_messages();

		$this->assertCount( 1, $messages );
		$this->assertStringContainsString( 'attributes:11:22', $messages[0] );
		$this->assertStringContainsString( 'нет ответа', $messages[0] );
	}

	/**
	 * When Action Scheduler refuses the continuation the loss is written to the ring and the log.
	 *
	 * @return void
	 */
	public function test_a_refused_continuation_is_recorded(): void {
		$this->refresher->result                = new Refresh_Result( 0, [], false );
		$this->scheduler->zero_for[ self::HOOK ] = true;

		$this->runner()->run( $this->account_id );

		$this->assertCount( 1, $this->ring_messages() );
		$this->assertStringContainsString( 'could not be continued', $this->ring_messages()[0] );
		$this->assertStringContainsString( 'could not be continued', implode( "\n", $this->log ) );
	}

	/**
	 * Server errors retry on the ladder, and the fifth failed attempt ends the job in a recorded failure.
	 *
	 * @return void
	 */
	public function test_server_errors_back_off_on_the_ladder_then_give_up(): void {
		$this->refresher->failure = Fake_Remote_Exception::of( Remote_Error::RETRY, 'HTTP 503' );

		$this->runner()->run( $this->account_id, 0, 0 );

		$continuations = $this->continuations();

		$this->assertCount( 1, $continuations );
		$this->assertSame( [ $this->account_id, self::NOW, 1 ], $continuations[0]['args'] );
		$this->assertSame( Backoff::seconds_after( 1 ), $continuations[0]['delay'] );

		$this->refresher->failure = Fake_Remote_Exception::of( Remote_Error::RETRY, 'HTTP 503' );

		$this->runner()->run( $this->account_id, self::NOW, Refresh_Catalog_Runner::MAX_ATTEMPTS - 1 );

		$this->assertCount( 1, $this->continuations(), 'no retry after the last attempt' );
		$this->assertStringContainsString( 'gave up', implode( "\n", $this->ring_messages() ) );
	}

	/**
	 * A throttled call waits the time the marketplace named, not the ladder.
	 *
	 * @return void
	 */
	public function test_a_throttled_call_waits_the_retry_after(): void {
		$this->refresher->failure = Fake_Remote_Exception::of( Remote_Error::THROTTLE, 'slow down', 45 );

		$this->runner()->run( $this->account_id, 0, 0 );

		$continuations = $this->continuations();

		$this->assertCount( 1, $continuations );
		$this->assertSame( 45, $continuations[0]['delay'] );
	}

	/**
	 * A refused key pauses the account and the job comes back after the pause with the same attempt count.
	 *
	 * @return void
	 */
	public function test_a_refused_key_pauses_the_account(): void {
		$this->refresher->failure = Fake_Remote_Exception::of( Remote_Error::PAUSE_ACCOUNT, 'key refused' );

		$this->runner()->run( $this->account_id, 0, 2 );

		$this->assertSame( [ $this->account_id ], $this->classifier->refused );

		$continuations = $this->continuations();

		$this->assertCount( 1, $continuations );
		$this->assertSame( [ $this->account_id, self::NOW, 2 ], $continuations[0]['args'] );
		$this->assertSame( Account_Pause::SECONDS, $continuations[0]['delay'] );
	}

	/**
	 * A refusal nothing can fix stops the job; the ring says why.
	 *
	 * @return void
	 */
	public function test_a_refusal_nothing_can_fix_stops_the_job(): void {
		$this->refresher->failure = Fake_Remote_Exception::of( Remote_Error::API_CHANGED, 'schema moved' );

		$this->runner()->run( $this->account_id );

		$this->assertSame( [], $this->continuations() );
		$this->assertStringContainsString( 'stopped', implode( "\n", $this->ring_messages() ) );
	}

	/**
	 * An exception that is not the marketplace's refusal is written to the ring and the log, and the job retries on the ladder.
	 *
	 * @return void
	 */
	public function test_an_unexpected_exception_reaches_the_ring_and_retries(): void {
		$this->refresher->failure = new \RuntimeException( 'client factory failed' );

		$this->runner()->run( $this->account_id, 0, 0 );

		$this->assertStringContainsString( 'client factory failed', implode( "\n", $this->ring_messages() ) );
		$this->assertStringContainsString( 'client factory failed', implode( "\n", $this->log ) );

		$continuations = $this->continuations();

		$this->assertCount( 1, $continuations );
		$this->assertSame( 1, $continuations[0]['args'][2] );
	}

	/**
	 * Registration hooks the job with three arguments so a continuation receives its limit and attempt.
	 *
	 * @return void
	 */
	public function test_it_hooks_the_job_with_three_arguments(): void {
		Actions\expectAdded( self::HOOK )->once();

		$this->runner()->register();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * The weekly run is created once; a run now and a continuation are not queued twice.
	 *
	 * @return void
	 */
	public function test_the_scheduler_queues_each_run_once(): void {
		$scheduler = new Refresh_Catalog_Scheduler( $this->names() );

		$scheduler->ensure_weekly();
		$scheduler->ensure_weekly();

		$this->assertCount( 1, $this->continuations() );
		$this->assertSame( [ 0 ], $this->continuations()[0]['args'] );

		$this->assertTrue( $scheduler->queue_now( $this->account_id ) );
		$this->assertTrue( $scheduler->queue_now( $this->account_id ) );

		$scheduler->continue_later( $this->account_id, 5, 1, 60 );
		$scheduler->continue_later( $this->account_id, 5, 1, 60 );

		$this->assertCount( 3, $this->continuations() );
	}

	/**
	 * A refused weekly run is an error, not a silent gap.
	 *
	 * @return void
	 */
	public function test_a_refused_weekly_run_throws(): void {
		$this->scheduler->zero_for[ self::HOOK ] = true;

		$this->expectException( \RuntimeException::class );

		( new Refresh_Catalog_Scheduler( $this->names() ) )->ensure_weekly();
	}
}
