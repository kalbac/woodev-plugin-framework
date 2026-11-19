<?php
/**
 * The job that sends the articles the merchant changed after the export.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Marketplace\Diagnostics\Api_Error_Ring;
use Woodev\Framework\Marketplace\Flows\Offer_Id_Renamer;
use Woodev\Framework\Marketplace\Flows\Offer_Id_Renames;

defined( 'ABSPATH' ) || exit;

/**
 * `woodev_{plugin id}_rename_offer_ids( account_id, attempt, put_offs )` runs in the {@see Account_Job} skeleton (gate, claim, the adapter's verdict on a refused
 * request), then sends the account's waiting changes batch by batch ({@see Offer_Id_Renames::send()}) until nothing waits or {@see Offer_Id_Renames::MAX_BATCHES}
 * batches went out, when it hands the rest to a new run.
 *
 * It is a **write** to the marketplace, so the plugin's write gate holds it back ({@see Job_Gate::allows_writes()}) like the export and the flush; the rows keep waiting.
 * A request that fails as a whole and cannot be repeated (a refusal, the last attempt, a refused key) leaves the rows of its batch on the old article with the reason,
 * so the merchant sees it on the product and can save again.
 *
 * @since 2.1.13
 */
final class Rename_Offer_Ids_Runner {
	public const FAILURE_CODE = 'rename_offer_ids';

	/**
	 * Job gate.
	 *
	 * @var Job_Gate
	 */
	private $gate;

	/**
	 * The service that reads, sends and settles.
	 *
	 * @var Offer_Id_Renames
	 */
	private $renames;

	/**
	 * The adapter's part.
	 *
	 * @var Offer_Id_Renamer
	 */
	private $renamer;

	/**
	 * Scheduler.
	 *
	 * @var Rename_Scheduler
	 */
	private $scheduler;

	/**
	 * The skeleton the work runs in.
	 *
	 * @var Account_Job
	 */
	private $job;

	/**
	 * Writes a line to the error log: `function ( string $line ): void`.
	 *
	 * @var callable
	 */
	private $log_error;

	/**
	 * Creates the runner.
	 *
	 * @since 2.1.13
	 *
	 * @param Job_Gate         $gate       Job gate.
	 * @param Offer_Id_Renames $renames    Service.
	 * @param Offer_Id_Renamer $renamer    The adapter's part.
	 * @param Rename_Scheduler $scheduler  Scheduler.
	 * @param Error_Classifier $classifier The adapter's error classifier.
	 * @param Api_Error_Ring   $ring       Error ring.
	 * @param callable         $log_error  `function ( string $line ): void`.
	 */
	public function __construct( Job_Gate $gate, Offer_Id_Renames $renames, Offer_Id_Renamer $renamer, Rename_Scheduler $scheduler, Error_Classifier $classifier, Api_Error_Ring $ring, callable $log_error ) {
		$this->gate      = $gate;
		$this->renames   = $renames;
		$this->renamer   = $renamer;
		$this->scheduler = $scheduler;
		$this->log_error = $log_error;
		$this->job       = new Account_Job( $gate, $classifier, $ring, $log_error, '', self::FAILURE_CODE );
	}

	/**
	 * Hooks the job.
	 *
	 * @since 2.1.13
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( $this->scheduler->hook(), [ $this, 'run' ], 10, 3 );
	}

	/**
	 * Runs the job.
	 *
	 * @since 2.1.13
	 *
	 * @param mixed $account_id Account id.
	 * @param mixed $attempt    Failed attempts so far.
	 * @param mixed $put_offs   Waits so far.
	 * @return void
	 */
	public function run( $account_id = 0, $attempt = 0, $put_offs = 0 ): void {
		$account_id = (int) $account_id;

		$this->job->run(
			$account_id,
			$this->scheduler->hook(),
			function ( int $delay, int $failed, int $waits ) use ( $account_id ): bool {
				$this->scheduler->again( $account_id, $delay, $failed, $waits );

				return true;
			},
			function ( string $text ) use ( $account_id ): void {
				$this->renames->fail_in_flight( $text );
				call_user_func( $this->log_error, 'rename_offer_ids: account ' . $account_id . ' gave up: ' . $text );
			},
			max( 0, (int) $attempt ),
			function ( Account $account, Claim_Fence $fence ): void {
				$this->work( $account, $fence );
			},
			max( 0, (int) $put_offs )
		);
	}

	/**
	 * Sends the waiting changes under the account's claim.
	 *
	 * @param Account     $account Account.
	 * @param Claim_Fence $fence   The run's claim.
	 * @return void
	 */
	private function work( Account $account, Claim_Fence $fence ): void {
		if ( ! $this->gate->allows_writes( $account ) ) {
			$this->scheduler->again( $account->id(), Job_Gate::WRITE_DELAY, 0, 0 );

			return;
		}

		for ( $batch = 0; $batch < Offer_Id_Renames::MAX_BATCHES; $batch++ ) {
			if ( 0 === $this->renames->send( $account, $this->renamer, $this->scheduler->hook() ) ) {
				return;
			}

			$this->job->made_progress();
			$this->job->hold( $account->id(), $fence );
		}

		$this->scheduler->again( $account->id(), 0, 0, 0 ); // More than one run's worth waits: the next run carries on.
	}
}
