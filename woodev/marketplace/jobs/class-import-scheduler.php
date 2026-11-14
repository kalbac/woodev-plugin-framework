<?php
/**
 * Scheduling of the card import job.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Data\Persistence_Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler calls of the import job `woodev_{plugin id}_import_cards( account_id, offer_ids[≤10], attempt )`.
 *
 * A queued chunk is one action, so a notification that names one card queues one action, and a bulk import queues ten cards per
 * action. The queue is Action Scheduler's own: this class keeps no list of its own, and {@see self::pending_offer_ids()} reads the
 * queue for the rows that are being imported. The job itself writes nothing to the marketplace.
 *
 * @since 2.1.6
 */
final class Import_Scheduler {
	public const IMPORT_JOB = 'import_cards';

	/**
	 * Articles per action.
	 */
	public const BATCH = 10;

	/**
	 * Job names of the plugin.
	 *
	 * @var Job_Names
	 */
	private $names;

	/**
	 * Creates the scheduler.
	 *
	 * @since 2.1.6
	 *
	 * @param Job_Names $names Job names of the plugin.
	 */
	public function __construct( Job_Names $names ) {
		$this->names = $names;
	}

	/**
	 * Returns the hook of the import job.
	 *
	 * @since 2.1.6
	 *
	 * @return string
	 */
	public function hook(): string {
		return $this->names->hook( self::IMPORT_JOB );
	}

	/**
	 * Queues the import of cards, in chunks of {@see self::BATCH}. A chunk that is already waiting with the same arguments is not
	 * queued twice.
	 *
	 * @since 2.1.6
	 *
	 * @param int      $account_id Account id.
	 * @param string[] $offer_ids  Articles.
	 * @return void
	 * @throws Persistence_Exception When Action Scheduler is not available or could not store an action.
	 */
	public function import( int $account_id, array $offer_ids ): void {
		foreach ( array_chunk( array_values( array_unique( $offer_ids ) ), self::BATCH ) as $chunk ) {
			$this->enqueue( [ $account_id, $chunk, 0 ], 0 );
		}
	}

	/**
	 * Schedules a chunk again after a failed attempt.
	 *
	 * @since 2.1.6
	 *
	 * @param int      $account_id Account id.
	 * @param string[] $offer_ids  Articles of the chunk.
	 * @param int      $attempt    Failed attempts so far.
	 * @param int      $delay      Seconds to wait.
	 * @return void
	 * @throws Persistence_Exception When Action Scheduler could not store the action.
	 */
	public function retry( int $account_id, array $offer_ids, int $attempt, int $delay ): void {
		$this->enqueue( [ $account_id, array_values( $offer_ids ), $attempt ], $delay );
	}

	/**
	 * Returns the articles of an account that have a pending or running import action: the rows that are being imported.
	 *
	 * @since 2.1.6
	 *
	 * @param int $account_id Account id.
	 * @return string[]
	 */
	public function pending_offer_ids( int $account_id ): array {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return [];
		}

		$actions = as_get_scheduled_actions(
			[
				'hook'     => $this->hook(),
				'group'    => $this->names->group(),
				'status'   => [ 'pending', 'in-progress' ],
				'per_page' => -1,
			],
			'objects'
		);

		$offer_ids = [];
		foreach ( (array) $actions as $action ) {
			$args = (array) $action->get_args();

			if ( (int) ( $args[0] ?? 0 ) !== $account_id ) {
				continue;
			}

			foreach ( (array) ( $args[1] ?? [] ) as $offer_id ) {
				$offer_ids[] = (string) $offer_id;
			}
		}

		return array_values( array_unique( $offer_ids ) );
	}

	/**
	 * Schedules one import action, unless the same one is already waiting for a first attempt.
	 *
	 * @param array<int,mixed> $args  Arguments: account id, articles, attempt.
	 * @param int              $delay Seconds to wait.
	 * @return void
	 * @throws Persistence_Exception When Action Scheduler is not available or could not store the action.
	 */
	private function enqueue( array $args, int $delay ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			throw Persistence_Exception::queue( 'the import of cards' );
		}

		if ( 0 === $delay && as_has_scheduled_action( $this->hook(), $args, $this->names->group() ) ) {
			return;
		}

		if ( 0 === (int) as_schedule_single_action( time() + max( 0, $delay ), $this->hook(), $args, $this->names->group() ) ) {
			throw Persistence_Exception::queue( 'the import of cards' );
		}
	}
}
