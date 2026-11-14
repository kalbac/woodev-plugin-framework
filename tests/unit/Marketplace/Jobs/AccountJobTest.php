<?php
/**
 * The skeleton of the account jobs: gate, claim, work, classify.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Marketplace\Jobs\Account_Job;
use Woodev\Framework\Marketplace\Jobs\Backoff;
use Woodev\Framework\Marketplace\Jobs\Claim_Lost;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\Remote_Error;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Export_Test_Case;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Remote_Exception;

/**
 * @covers \Woodev\Framework\Marketplace\Jobs\Account_Job
 */
final class AccountJobTest extends Export_Test_Case {
	private const CONTEXT = 'woodev_fake_seller_import_cards';
	private const PATH    = '/fake/import';
	private const CODE    = 'import_cards_failed';

	/**
	 * Retries asked for: `[ delay, attempt ]`.
	 *
	 * @var array<int,array{0:int,1:int}>
	 */
	private $retries_asked = [];

	/**
	 * Texts the job gave up with.
	 *
	 * @var string[]
	 */
	private $given_up = [];

	/**
	 * Runs of the work.
	 *
	 * @var int
	 */
	private $worked = 0;

	/**
	 * Resets the recorders.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->retries_asked = [];
		$this->given_up      = [];
		$this->worked        = 0;
	}

	/**
	 * Builds the skeleton.
	 *
	 * @param string[] $give_up_on Exceptions the work gives the run up with.
	 * @return Account_Job
	 */
	private function job( array $give_up_on = [] ): Account_Job {
		return new Account_Job( $this->job_gate(), $this->classifier, $this->ring(), [ $this, 'log_line' ], self::PATH, self::CODE, $give_up_on );
	}

	/**
	 * Runs a job whose work does what the test says.
	 *
	 * @param callable|null $work     `function ( Account, Claim_Fence ): void`.
	 * @param int           $attempt  Failed attempts so far.
	 * @param string[]      $give_up_on Exceptions the work gives the run up with.
	 * @return void
	 */
	private function run_job( ?callable $work = null, int $attempt = 0, array $give_up_on = [] ): void {
		$this->job( $give_up_on )->run(
			$this->account_id,
			self::CONTEXT,
			function ( int $delay, int $failures ): bool {
				$this->retries_asked[] = [ $delay, $failures ];

				return true;
			},
			function ( string $text ): void {
				$this->given_up[] = $text;
			},
			$attempt,
			$work ?? function (): void {
				++$this->worked;
			}
		);
	}

	/**
	 * Work that throws.
	 *
	 * @param \Throwable $exception What it throws.
	 * @return callable
	 */
	private function throwing( \Throwable $exception ): callable {
		return static function () use ( $exception ): void {
			throw $exception;
		};
	}

	/**
	 * Rows of the error ring.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function ring_rows(): array {
		return (array) $this->db->get_results( 'SELECT * FROM ' . Sqlite_Tables::table( 'api_errors' ), ARRAY_A );
	}

	/**
	 * A clean run does the work once with the account and a claim, and releases the claim.
	 *
	 * @return void
	 */
	public function test_a_clean_run_works_once_and_releases_the_claim(): void {
		$seen = [];

		$this->run_job(
			function ( Account $account, Claim_Fence $fence ) use ( &$seen ): void {
				$seen = [ $account->id(), $fence ];
			}
		);

		$this->assertSame( $this->account_id, $seen[0] );
		$this->assertInstanceOf( Claim_Fence::class, $seen[1] );
		$this->assertSame( [], $this->retries_asked );
		$this->assertSame( [], $this->given_up );
		$this->assertNotNull( $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL ), 'the claim was released' );
	}

	/**
	 * A disabled account is not worked and the merchant gets the reason.
	 *
	 * @return void
	 */
	public function test_a_disabled_account_gives_up_without_working(): void {
		$this->accounts()->set_enabled( $this->account_id, false );

		$this->run_job();

		$this->assertSame( 0, $this->worked );
		$this->assertCount( 1, $this->given_up );
		$this->assertSame( [], $this->retries_asked );
	}

	/**
	 * A lapsed licence waits an hour with the same attempt count.
	 *
	 * @return void
	 */
	public function test_a_lapsed_licence_waits_without_working(): void {
		$this->licensed = false;

		$this->run_job( null, 2 );

		$this->assertSame( 0, $this->worked );
		$this->assertSame( [ [ Job_Gate::LICENCE_DELAY, 2 ] ], $this->retries_asked );
		$this->assertSame( [], $this->given_up );
	}

	/**
	 * A busy account retries in thirty seconds with the same attempt count.
	 *
	 * @return void
	 */
	public function test_a_busy_account_retries_soon(): void {
		$this->assertNotNull( $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL ) );

		$this->run_job( null, 1 );

		$this->assertSame( 0, $this->worked );
		$this->assertSame( [ [ Job_Gate::BUSY_DELAY, 1 ] ], $this->retries_asked );
	}

	/**
	 * A refused credential pauses the account and gives the run up with the marketplace's text.
	 *
	 * @return void
	 */
	public function test_a_refused_key_pauses_the_account(): void {
		$this->run_job( $this->throwing( Fake_Remote_Exception::of( Remote_Error::PAUSE_ACCOUNT, 'key refused' ) ) );

		$this->assertSame( [ $this->account_id ], $this->classifier->refused );
		$this->assertSame( [], $this->retries_asked );
		$this->assertCount( 1, $this->given_up );
		$this->assertStringContainsString( 'fake_pause_account', implode( "\n", $this->log ) );
	}

	/**
	 * A throttle waits as long as asked, at least a second, and is not an attempt.
	 *
	 * @return void
	 */
	public function test_a_throttle_is_not_an_attempt(): void {
		$this->run_job( $this->throwing( Fake_Remote_Exception::of( Remote_Error::THROTTLE, 'slow down', 45 ) ), 2 );
		$this->run_job( $this->throwing( Fake_Remote_Exception::of( Remote_Error::THROTTLE, 'slow down', 0 ) ), 2 );

		$this->assertSame( [ [ 45, 2 ], [ 1, 2 ] ], $this->retries_asked );
		$this->assertSame( [], $this->given_up );
	}

	/**
	 * A server error retries on the ladder, then gives up after MAX_ATTEMPTS.
	 *
	 * @return void
	 */
	public function test_a_server_error_retries_on_the_ladder_then_gives_up(): void {
		$this->run_job( $this->throwing( Fake_Remote_Exception::of( Remote_Error::RETRY, 'HTTP 503' ) ), 0 );

		$this->assertSame( [ [ Backoff::seconds_after( 1 ), 1 ] ], $this->retries_asked );
		$this->assertSame( [], $this->given_up );

		$this->run_job( $this->throwing( Fake_Remote_Exception::of( Remote_Error::RETRY, 'HTTP 503' ) ), Account_Job::MAX_ATTEMPTS - 1 );

		$this->assertCount( 1, $this->retries_asked, 'no further retry' );
		$this->assertSame( [ 'fake_retry: HTTP 503' ], $this->given_up );
	}

	/**
	 * A refusal nothing can fix gives the run up with the marketplace's text.
	 *
	 * @return void
	 */
	public function test_any_other_refusal_gives_up(): void {
		$this->run_job( $this->throwing( Fake_Remote_Exception::of( Remote_Error::FAIL, 'not allowed' ) ) );

		$this->assertSame( [], $this->retries_asked );
		$this->assertSame( [ 'fake_fail: not allowed' ], $this->given_up );
	}

	/**
	 * An exception nobody classified is recorded, not thrown, and the claim is released.
	 *
	 * @return void
	 */
	public function test_an_unexpected_exception_is_recorded_not_thrown(): void {
		$this->run_job( $this->throwing( new \RuntimeException( 'boom' ) ) );

		$this->assertCount( 1, $this->given_up );
		$this->assertStringContainsString( 'boom', $this->given_up[0] );
		$this->assertStringContainsString( 'RuntimeException: boom', implode( "\n", $this->log ) );
		$this->assertNotNull( $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL ), 'the claim was released' );
	}

	/**
	 * An exception the job names gives the run up with its own message, whatever the classifier thinks of it.
	 *
	 * @return void
	 */
	public function test_a_named_give_up_exception_ends_the_run_with_its_message(): void {
		$this->run_job( $this->throwing( new \DomainException( 'not trusted' ) ), 0, [ \DomainException::class ] );

		$this->assertSame( [ 'not trusted' ], $this->given_up );
		$this->assertSame( [], $this->retries_asked );

		$this->given_up = [];

		$this->run_job( $this->throwing( new \DomainException( 'not trusted' ) ) );

		$this->assertStringContainsString( 'not trusted', $this->given_up[0] );
		$this->assertNotSame( 'not trusted', $this->given_up[0], 'unnamed, it is an unexpected failure with the generic text' );
	}

	/**
	 * A lost claim retries soon and records nothing for the merchant.
	 *
	 * @return void
	 */
	public function test_a_lost_claim_retries_without_a_verdict(): void {
		$this->run_job( $this->throwing( new Claim_Lost( 'taken' ) ), 1 );

		$this->assertSame( [ [ Job_Gate::BUSY_DELAY, 1 ] ], $this->retries_asked );
		$this->assertSame( [], $this->given_up );
	}

	/**
	 * The ring names the path and the code the job was built with.
	 *
	 * @return void
	 */
	public function test_the_ring_carries_the_jobs_path_and_code(): void {
		$this->run_job( $this->throwing( new \RuntimeException( 'boom' ) ) );

		$rows = $this->ring_rows();

		$this->assertCount( 1, $rows );
		$this->assertSame( self::PATH, $rows[0]['path'] );
		$this->assertSame( self::CODE, $rows[0]['code'] );
		$this->assertSame( self::CONTEXT, $rows[0]['context'] );
	}

	/**
	 * `hold()` passes while the claim is ours and throws once another run took it.
	 *
	 * @return void
	 */
	public function test_hold_throws_when_the_claim_was_taken_over(): void {
		$job    = $this->job();
		$thrown = null;

		$this->run_job(
			function ( Account $account, Claim_Fence $fence ) use ( $job, &$thrown ): void {
				$job->hold( $this->account_id, $fence );
				++$this->worked;

				$this->db->update(
					Sqlite_Tables::table( 'accounts' ),
					[
						'claim_token' => 'another-run',
						'claim_until' => '2100-01-01 00:00:00',
					],
					[ 'account_id' => $account->id() ]
				);

				try {
					$job->hold( $this->account_id, $fence );
				} catch ( Claim_Lost $e ) {
					$thrown = $e;
				}
			}
		);

		$this->assertSame( 1, $this->worked, 'the first hold passed' );
		$this->assertInstanceOf( Claim_Lost::class, $thrown );
	}
}
