<?php
/**
 * The `import_cards` job.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Catalog\Card_Content;
use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Marketplace\Data\Link_Report_Store;
use Woodev\Framework\Marketplace\Diagnostics\Api_Error_Ring;
use Woodev\Framework\Marketplace\Flows\Card_Import;
use Woodev\Framework\Marketplace\Flows\Card_Importer;

defined( 'ABSPATH' ) || exit;

/**
 * **`import_cards( account_id, offer_ids[≤10], attempt )`** reads the cards of a batch and imports each one through
 * {@see Card_Import}. It writes to the shop and to our tables and never to the marketplace.
 *
 * 1. {@see Account_Job}: the job gate (account on, licence in force, pause) and the account claim; a failure is classified by the
 *    adapter, so a refused credential pauses the account, a wait is not an attempt and a server error retries on the
 *    {@see Backoff} ladder; a lost claim retries after {@see Job_Gate::BUSY_DELAY}. No write gate: nothing is sent to the
 *    marketplace.
 * 2. One request reads the cards of the batch ({@see Card_Importer::read_cards()}), then every card is imported in turn. A card
 *    that fails on its own (WooCommerce refused the save) gets a note and the batch goes on.
 * 3. **The claim** is extended before every card and before every image; a lost claim stops the run.
 * 4. **The budget** ({@see self::BUDGET_SECONDS}) is checked before every card and every image. Once it is spent, the run puts the
 *    cards it has not finished — the current one included — back in the queue with the attempt unchanged. A run always advances
 *    first ({@see Import_Budget}), so that a requeue cannot repeat for ever.
 * 5. When the run gives up, every card of the batch gets a note: a fixed Russian sentence for the merchant, and the technical
 *    reason kept apart in the note's `detail`.
 *
 * @since 2.1.6
 */
final class Import_Jobs {
	/**
	 * Seconds one run may take. A PHP time limit during image downloads is the real case, and the cards a cut run leaves are taken
	 * up by the next one (see {@see Card_Import}).
	 */
	public const BUDGET_SECONDS = 20;

	private const FAILURE_CODE = 'import_cards_failed';

	/**
	 * The gate → claim → work → classify skeleton.
	 *
	 * @var Account_Job
	 */
	private $job;

	/**
	 * The adapter's importer part.
	 *
	 * @var Card_Importer
	 */
	private $adapter;

	/**
	 * Imports one card.
	 *
	 * @var Card_Import
	 */
	private $import;

	/**
	 * Import notes.
	 *
	 * @var Link_Report_Store
	 */
	private $notes;

	/**
	 * Scheduler.
	 *
	 * @var Import_Scheduler
	 */
	private $scheduler;

	/**
	 * Writes a line to the error log.
	 *
	 * @var callable
	 */
	private $log_error;

	/**
	 * Time now in seconds: `function (): float`.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Creates the job.
	 *
	 * @since 2.1.6
	 *
	 * @param Job_Gate          $gate       Job gate.
	 * @param Card_Importer     $adapter    The adapter's importer part.
	 * @param Card_Import       $import     Imports one card.
	 * @param Link_Report_Store $notes      Import notes.
	 * @param Import_Scheduler  $scheduler  Scheduler.
	 * @param Error_Classifier  $classifier The adapter's error classifier.
	 * @param Api_Error_Ring    $ring       Error ring.
	 * @param callable          $log_error  `function ( string $line ): void`.
	 * @param callable|null     $clock      `function (): float`, the time now in seconds; the system clock when null.
	 */
	public function __construct( Job_Gate $gate, Card_Importer $adapter, Card_Import $import, Link_Report_Store $notes, Import_Scheduler $scheduler, Error_Classifier $classifier, Api_Error_Ring $ring, callable $log_error, ?callable $clock = null ) {
		$this->adapter   = $adapter;
		$this->import    = $import;
		$this->notes     = $notes;
		$this->scheduler = $scheduler;
		$this->log_error = $log_error;
		$this->clock     = $clock ?? static function (): float {
			return microtime( true );
		};
		$this->job       = new Account_Job( $gate, $classifier, $ring, $log_error, $adapter->import_path(), self::FAILURE_CODE );
	}

	/**
	 * Hooks the job.
	 *
	 * @since 2.1.6
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( $this->scheduler->hook(), [ $this, 'import_cards' ], 10, 3 );
	}

	/**
	 * Runs `import_cards`.
	 *
	 * @since 2.1.6
	 *
	 * @param mixed $account_id Account id.
	 * @param mixed $offer_ids  Articles of the batch.
	 * @param mixed $attempt    Failed attempts so far.
	 * @return void
	 */
	public function import_cards( $account_id = 0, $offer_ids = [], $attempt = 0 ): void {
		$account_id = (int) $account_id;
		$attempt    = (int) $attempt;
		$offer_ids  = array_values( array_map( 'strval', is_array( $offer_ids ) ? $offer_ids : [] ) );

		if ( [] === $offer_ids ) {
			return;
		}

		$this->job->run(
			$account_id,
			$this->scheduler->hook(),
			function ( int $delay, int $failures ) use ( $account_id, $offer_ids ): bool {
				$this->scheduler->retry( $account_id, $offer_ids, $failures, $delay );

				return true;
			},
			function ( string $reason ) use ( $account_id, $offer_ids ): void {
				$this->give_up( $account_id, $offer_ids, $reason );
			},
			$attempt,
			function ( Account $account, Claim_Fence $fence ) use ( $offer_ids, $attempt ): void {
				$this->run( $account, $fence, $offer_ids, $attempt );
			}
		);
	}

	/**
	 * Reads the batch and imports its cards one by one, until the budget is spent.
	 *
	 * @param Account     $account   Account.
	 * @param Claim_Fence $fence     The run's claim.
	 * @param string[]    $offer_ids Articles of the batch.
	 * @param int         $attempt   Failed attempts so far.
	 * @return void
	 * @throws Claim_Lost When another run took the account claim.
	 */
	private function run( Account $account, Claim_Fence $fence, array $offer_ids, int $attempt ): void {
		$budget = new Import_Budget( $this->clock, self::BUDGET_SECONDS );
		$hold   = function () use ( $account, $fence ): void {
			$this->job->hold( $account->id(), $fence );
		};
		$cards  = $this->adapter->read_cards( $account, $this->scheduler->hook(), $offer_ids, $hold );
		$renew  = static function () use ( $hold, $budget ): void {
			$hold();
			$budget->before_image();
		};

		foreach ( $offer_ids as $index => $offer_id ) {
			try {
				$hold();
				$budget->before_card();
				$this->import_card( $account, $fence, $offer_id, $cards[ $offer_id ] ?? null, $renew );
				$budget->card_finished();
			} catch ( Budget_Spent $spent ) {
				$this->scheduler->retry( $account->id(), array_slice( $offer_ids, $index ), $attempt, 0 );

				return;
			}
		}
	}

	/**
	 * Imports one card; a failure of this card alone is noted and does not stop the batch.
	 *
	 * @param Account           $account  Account.
	 * @param Claim_Fence       $fence    The run's claim.
	 * @param string            $offer_id Article.
	 * @param Card_Content|null $content  The card, null when the marketplace did not answer for it.
	 * @param callable          $renew    Extends the claim and checks the budget before an image.
	 * @return void
	 * @throws Claim_Lost    When another run took the account claim.
	 * @throws Budget_Spent  When the run's time is spent.
	 */
	private function import_card( Account $account, Claim_Fence $fence, string $offer_id, ?Card_Content $content, callable $renew ): void {
		if ( null === $content ) {
			$this->notes->set_import_note( $account->id(), $offer_id, __( 'Маркетплейс не вернул данные карточки. Обновите список карточек и попробуйте ещё раз.', 'woodev-plugin-framework' ) );

			return;
		}

		try {
			$this->import->import( $account, $content, $fence, $renew );
		} catch ( Claim_Lost | Budget_Spent $stop ) {
			throw $stop;
		} catch ( \Throwable $failure ) {
			$detail = get_class( $failure ) . ': ' . $failure->getMessage();

			$this->notes->set_import_note( $account->id(), $offer_id, __( 'Не удалось создать товар из карточки.', 'woodev-plugin-framework' ), $detail );
			call_user_func( $this->log_error, $this->scheduler->hook() . ': account ' . $account->id() . ': ' . $offer_id . ': ' . $detail );
		}
	}

	/**
	 * The run is given up: every card of the batch gets a note.
	 *
	 * @param int      $account_id Account id.
	 * @param string[] $offer_ids  Articles of the batch.
	 * @param string   $reason     Why the run ended: a technical text, except for the account's own «disabled» sentence.
	 * @return void
	 */
	private function give_up( int $account_id, array $offer_ids, string $reason ): void {
		$disabled = __( 'Аккаунт маркетплейса отключён или удалён.', 'woodev-plugin-framework' );
		$text     = $reason === $disabled ? $disabled : __( 'Не удалось импортировать карточку. Попробуйте ещё раз позже.', 'woodev-plugin-framework' );
		$detail   = $reason === $disabled ? '' : $reason;

		foreach ( $offer_ids as $offer_id ) {
			$this->notes->set_import_note( $account_id, $offer_id, $text, $detail );
		}
	}
}
