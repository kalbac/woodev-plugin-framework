<?php
/**
 * Opens the price task of an upload the marketplace decides on later.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Framework\Marketplace\Ledger\Task_Ledger;
use Woodev\Framework\Marketplace\Price\Price_Intent;
use Woodev\Framework\Marketplace\Price\Price_Line;
use Woodev\Framework\Marketplace\Price\Price_Pusher;
use Woodev\Framework\Marketplace\Price\Price_Sync_Queue;
use Woodev\Framework\Marketplace\Price\Push_Receipt;

defined( 'ABSPATH' ) || exit;

/**
 * The price flush calls it for a pending or duplicate receipt: the ledger gets one price task per upload (the marketplace's task id,
 * one item per link, the revisions sent), every pair is made to wait for that task, and its first poll is scheduled. An upload the
 * marketplace recognises as one that is still open joins that task instead of getting a second one.
 *
 * The result payload of the pending receipt's task is stored with the task, so the pusher finds there what it needs to read the
 * answers.
 *
 * @since 2.1.24
 */
final class Price_Task_Opener {
	/**
	 * Task ledger.
	 *
	 * @var Task_Ledger
	 */
	private $ledger;

	/**
	 * Price queue.
	 *
	 * @var Price_Sync_Queue
	 */
	private $queue;

	/**
	 * Pusher, which may set the poll interval.
	 *
	 * @var Price_Pusher
	 */
	private $pusher;

	/**
	 * Schedules the task poll.
	 *
	 * @var Export_Scheduler
	 */
	private $scheduler;

	/**
	 * Writes a line to the error log: `function ( string $line ): void`.
	 *
	 * @var callable
	 */
	private $log_error;

	/**
	 * Creates the opener.
	 *
	 * @since 2.1.24
	 *
	 * @param Task_Ledger      $ledger    Task ledger.
	 * @param Price_Sync_Queue $queue     Price queue.
	 * @param Price_Pusher     $pusher    Pusher.
	 * @param Export_Scheduler $scheduler Schedules the task poll.
	 * @param callable         $log_error `function ( string $line ): void`.
	 */
	public function __construct( Task_Ledger $ledger, Price_Sync_Queue $queue, Price_Pusher $pusher, Export_Scheduler $scheduler, callable $log_error ) {
		$this->ledger    = $ledger;
		$this->queue     = $queue;
		$this->pusher    = $pusher;
		$this->scheduler = $scheduler;
		$this->log_error = $log_error;
	}

	/**
	 * Records the upload of some prices and makes them wait for its result.
	 *
	 * @since 2.1.24
	 *
	 * @param int                                           $account_id Account id.
	 * @param Push_Receipt                                  $receipt    A pending or duplicate receipt.
	 * @param array<int,array{0:Price_Intent,1:Price_Line}> $awaited    The prices sent that have no answer yet.
	 * @param string                                        $token      Account claim token.
	 * @return bool False when a price could not be made to wait: the claim is no longer held.
	 * @throws \InvalidArgumentException When the receipt is not pending.
	 * @throws \RuntimeException When the database refuses a write.
	 */
	public function open( int $account_id, Push_Receipt $receipt, array $awaited, string $token ): bool {
		$pending = $receipt->pending_task();

		if ( null === $pending ) {
			throw new \InvalidArgumentException( 'Only a pending receipt has a price task.' );
		}

		$remote_task_id = $receipt->duplicate_of() ?? $pending->remote_task_id();
		$items          = [];
		$revisions      = [];

		foreach ( $awaited as list( $intent, $line ) ) {
			$items[ $intent->link_id() ]     = $line->link()->seller_sku();
			$revisions[ $intent->link_id() ] = $intent->revision();
		}

		$result   = array_replace( $pending->result(), [ 'revisions' => $revisions ] );
		$existing = null === $remote_task_id ? null : $this->ledger->find_open( $account_id, Async_Task::KIND_PRICE, $remote_task_id );
		$task_id  = null === $existing
			? $this->create( $account_id, $remote_task_id, $items, $result )
			: $this->join( $existing, $items, $result );

		foreach ( $awaited as list( $intent ) ) {
			if ( ! $this->queue->await( $account_id, $intent, $task_id, $token ) ) {
				return false;
			}
		}

		if ( null === $existing ) {
			$this->schedule_first_poll( $task_id );
		}

		return true;
	}

	/**
	 * Stores a new price task.
	 *
	 * @param int                 $account_id     Account id.
	 * @param string|null         $remote_task_id Marketplace task id.
	 * @param array<int,string>   $items          Link id => seller article.
	 * @param array<string,mixed> $result         Result payload.
	 * @return int Task id.
	 */
	private function create( int $account_id, ?string $remote_task_id, array $items, array $result ): int {
		$task    = new Async_Task( $account_id, Async_Task::KIND_PRICE, null, Async_Task::STATUS_SUBMITTED, count( $items ), null, null, $result );
		$task_id = $this->ledger->open( $task, $items );

		if ( null !== $remote_task_id ) {
			// No request hash: it marks the requests that carried an article to the marketplace's catalogue.
			$this->ledger->attach_remote( $task_id, $remote_task_id, '' );
		}

		return $task_id;
	}

	/**
	 * Adds prices to the open task of the same upload.
	 *
	 * @param Async_Task          $task   The open task.
	 * @param array<int,string>   $items  Link id => seller article.
	 * @param array<string,mixed> $result Result payload.
	 * @return int Task id.
	 */
	private function join( Async_Task $task, array $items, array $result ): int {
		$task_id = (int) $task->task_id();
		$known   = $this->ledger->items( $task_id );

		$this->ledger->add_items( $task_id, array_diff_key( $items, $known ) );

		foreach ( array_keys( array_intersect_key( $items, $known ) ) as $link_id ) {
			$this->ledger->settle_item( $task_id, $link_id, Task_Ledger::ITEM_PENDING );
		}

		$this->ledger->merge_result( $task_id, array_replace_recursive( $task->result(), $result ) );

		return $task_id;
	}

	/**
	 * Schedules the first poll; a refusal by Action Scheduler is logged, and the sweep picks the task up.
	 *
	 * @param int $task_id Task id.
	 * @return void
	 */
	private function schedule_first_poll( int $task_id ): void {
		try {
			$this->scheduler->poll_later( $task_id, 0, Price_Poll_Runner::interval_of( $this->pusher ) );
		} catch ( \Throwable $e ) {
			call_user_func( $this->log_error, 'price task ' . $task_id . ' could not be scheduled; the sweep picks it up: ' . $e->getMessage() );
		}
	}
}
