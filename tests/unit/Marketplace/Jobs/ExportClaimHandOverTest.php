<?php
/**
 * The `export_batch` job hands its account claim to a gateway that asks for it (#2054).
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Jobs\Claim_Lost;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\Poll_Task_Runner;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Tests\Unit\Marketplace\Support\Export_Test_Case;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Catch_All_Error_Classifier;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Claim_Receiving_Export_Gateway;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Scripted_Export_Gateway;

/**
 * @covers \Woodev\Framework\Marketplace\Jobs\Export_Batch_Runner
 */
final class ExportClaimHandOverTest extends Export_Test_Case {
	private const EXPORT = 'woodev_fake_seller_export_batch';

	/**
	 * The scripted cards and answers behind the receiving gateway.
	 *
	 * @var Scripted_Export_Gateway
	 */
	private $scripted;

	/**
	 * Puts a receiving gateway in front of the scripted one.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->scripted = $this->gateway;
		$this->gateway  = new Claim_Receiving_Export_Gateway( $this->scripted );
	}

	/**
	 * The claim arrives once a run, before the first card is built.
	 *
	 * @return void
	 */
	public function test_the_claim_arrives_before_anything_is_built_or_sent(): void {
		$first  = $this->queued( 'FIRST' );
		$second = $this->queued( 'SECOND' );

		$this->run_batch( [ $first, $second ] );

		$this->assertSame(
			[ 'claim ' . $this->account_id, 'build ' . $first->link_id(), 'build ' . $second->link_id(), 'submit' ],
			$this->gateway->calls
		);
	}

	/**
	 * Calling the claim keeps the account with the run for longer than one claim lasts.
	 *
	 * @return void
	 */
	public function test_holding_extends_the_claim(): void {
		$link  = $this->queued( 'NB-1' );
		$other = 'not asked';

		$this->scripted->on_build      = function (): void {
			$this->now += Job_Gate::CLAIM_TTL - 10;
			call_user_func( $this->gateway->holds[0] );
		};
		$this->scripted->before_submit = function () use ( &$other ): void {
			$this->now += Job_Gate::CLAIM_TTL - 10;
			call_user_func( $this->gateway->holds[0] );
			$other = $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );
		};

		$this->run_batch( [ $link ] );

		$this->assertNull( $other, 'no other run may take the account' );
		$this->assertSame( 'task-9001', $this->ledger()->get( 1 )->remote_task_id() );
	}

	/**
	 * Another run took the account between the job's claim and the first card: the gateway is stopped, and the other run's claim is
	 * left alone.
	 *
	 * @return void
	 */
	public function test_a_run_that_lost_the_account_before_its_first_card_stops(): void {
		$link  = $this->queued( 'NB-1' );
		$other = null;

		$this->scripted->on_build = function () use ( &$other ): void {
			$other = $this->take_over();
			call_user_func( $this->gateway->holds[0] );
		};

		$this->run_batch( [ $link ] );

		$this->assertSame( [], $this->scripted->submitted );
		$this->assertTrue( $this->accounts()->holds_claim( $this->account_id, (string) $other ) );
		$this->assertSame( Link_Record::STATE_SUBMITTED, $this->link_of( $link->wc_product_id() )->card_state() );
		$this->assertSame( Async_Task::STATUS_SUBMITTED, $this->ledger()->get( 1 )->status() );
		$this->assertSame( [], $this->scheduler->of( self::EXPORT ), 'the job schedules no retry of its own' );
	}

	/**
	 * A claim lost inside the send records nothing; the recovery sweep queues the links again.
	 *
	 * @return void
	 */
	public function test_a_claim_lost_inside_the_send_is_left_to_the_sweep(): void {
		$link  = $this->queued( 'NB-1' );
		$other = null;

		$this->scripted->before_submit = function () use ( &$other ): void {
			$other = $this->take_over();
			call_user_func( $this->gateway->holds[0] );
		};

		$this->run_batch( [ $link ] );

		$this->assertSame( Link_Record::STATE_SUBMITTED, $this->link_of( $link->wc_product_id() )->card_state() );
		$this->assertSame( Async_Task::STATUS_SUBMITTED, $this->ledger()->get( 1 )->status() );
		$this->assertSame( [], $this->classifier->refused );

		$this->accounts()->release_claim( $this->account_id, (string) $other );
		$this->now += 2 * Poll_Task_Runner::STALE_AFTER;
		$this->poll_runner()->run( 0, 0 );

		$this->assertSame( Link_Record::STATE_QUEUED, $this->link_of( $link->wc_product_id() )->card_state() );
		$this->assertCount( 1, $this->scheduler->of( self::EXPORT ) );
	}

	/**
	 * A classifier that answers for any exception is not asked about a lost claim: no failed task, no retry.
	 *
	 * @return void
	 */
	public function test_a_catch_all_classifier_is_not_asked_about_a_claim_lost_inside_the_send(): void {
		$link             = $this->queued( 'NB-1' );
		$classifier       = new Catch_All_Error_Classifier();
		$this->classifier = $classifier;

		$this->scripted->before_submit = function (): void {
			$this->take_over();
			call_user_func( $this->gateway->holds[0] );
		};

		$this->run_batch( [ $link ] );

		$this->assertSame( [], $classifier->asked );
		$this->assertSame( Link_Record::STATE_SUBMITTED, $this->link_of( $link->wc_product_id() )->card_state() );
		$this->assertSame( Async_Task::STATUS_SUBMITTED, $this->ledger()->get( 1 )->status() );
		$this->assertSame( [], $this->scheduler->of( self::EXPORT ) );
	}

	/**
	 * The claim of a run that ended is refused, and the next run hands over its own.
	 *
	 * @return void
	 */
	public function test_each_run_hands_over_its_own_claim(): void {
		$this->run_batch( [ $this->queued( 'FIRST' ) ] );

		$this->scripted->on_build = function (): void {
			call_user_func( $this->gateway->holds[1] );

			try {
				call_user_func( $this->gateway->holds[0] );
				$this->fail( 'the claim of the first run must be refused' );
			} catch ( Claim_Lost $lost ) {
				$this->addToAssertionCount( 1 );
			}
		};

		$this->run_batch( [ $this->queued( 'SECOND' ) ] );

		$this->assertCount( 2, $this->scripted->submitted );
	}

	/**
	 * A batch that waits for the account takes no claim, so none is handed over.
	 *
	 * @return void
	 */
	public function test_a_waiting_batch_hands_over_nothing(): void {
		$link = $this->queued( 'NB-1' );
		$this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );

		$this->run_batch( [ $link ] );

		$this->assertSame( [], $this->gateway->calls );
	}

	/**
	 * Lets the running claim expire and takes the account for another run.
	 *
	 * @return string The other run's token.
	 */
	private function take_over(): string {
		$this->now += Job_Gate::CLAIM_TTL + 1;
		$token      = $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );

		$this->assertNotNull( $token );

		return $token;
	}

	/**
	 * Creates a valid, queued link through the request-time service.
	 *
	 * @param string $sku Article.
	 * @return Link_Record
	 */
	private function queued( string $sku ): Link_Record {
		$product = $this->add_product( [ 'sku' => $sku ] );
		$this->service()->enqueue( $this->account_now(), [ $product->get_id() ] );

		return $this->link_of( (int) $product->get_id() );
	}

	/**
	 * Runs the job for links, with no export action left over from queueing them.
	 *
	 * @param Link_Record[] $links Links.
	 * @return void
	 */
	private function run_batch( array $links ): void {
		$this->scheduler->actions = array_values(
			array_filter(
				$this->scheduler->actions,
				static function ( array $action ): bool {
					return self::EXPORT !== $action['hook'];
				}
			)
		);

		$this->export_runner()->run(
			$this->account_id,
			array_map(
				static function ( Link_Record $link ): int {
					return (int) $link->link_id();
				},
				$links
			),
			0
		);
	}
}
