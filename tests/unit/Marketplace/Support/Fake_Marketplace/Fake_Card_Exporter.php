<?php
/**
 * Card export with the 30-minute sync window.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Catalog\Card_Exporter;
use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Framework\Marketplace\Ledger\Item_Result;
use Woodev\Framework\Marketplace\Ledger\Task_Status;

/**
 * `POST /content/v2/cards/upload` is asynchronous and returns no task id; for up to 30 minutes the card exists only as
 * «accepted» and neither stock nor price may be set. After that `nmID`, `imtID` and the per-size `chrtID` appear, or the
 * error list says why not.
 */
final class Fake_Card_Exporter implements Card_Exporter {
	public const SYNC_WINDOW_SECONDS = 1800;

	/**
	 * Clock.
	 *
	 * @var Fake_Clock
	 */
	private $clock;

	/**
	 * Submitted links by local task id.
	 *
	 * @var array<int,\Woodev\Framework\Marketplace\Links\Product_Link[]>
	 */
	private $submitted = array();

	/**
	 * Creates the exporter.
	 *
	 * @param Fake_Clock $clock Clock.
	 */
	public function __construct( Fake_Clock $clock ) {
		$this->clock = $clock;
	}

	/**
	 * Submits cards; the returned task has no remote id.
	 *
	 * @param Account                                  $account Account.
	 * @param \Woodev\Framework\Marketplace\Links\Product_Link[] $links   Links.
	 * @param Mapping                                  $mapping Mapping.
	 * @param Profile                                  $profile Profile.
	 * @return Async_Task
	 */
	public function submit( Account $account, array $links, Mapping $mapping, Profile $profile ): Async_Task {
		$task_id                     = count( $this->submitted ) + 1;
		$this->submitted[ $task_id ] = $links;

		return new Async_Task( $account->id(), Async_Task::KIND_IMPORT, null, Async_Task::STATUS_POLLING, count( $links ), $this->clock->now(), $task_id );
	}

	/**
	 * Reads the outcome: nothing inside the window, then ids or errors.
	 *
	 * @param Account    $account Account.
	 * @param Async_Task $task    Task from `submit()`.
	 * @return Task_Status
	 */
	public function poll( Account $account, Async_Task $task ): Task_Status {
		$submitted_at = $task->submitted_at();
		if ( null === $submitted_at || $this->clock->now()->getTimestamp() - $submitted_at->getTimestamp() < self::SYNC_WINDOW_SECONDS ) {
			return new Task_Status( Async_Task::STATUS_POLLING );
		}

		$items  = array();
		$failed = false;
		foreach ( $this->submitted[ (int) $task->task_id() ] ?? array() as $link ) {
			$link_id = (int) $link->link_id();
			if ( '' === $link->seller_sku() ) {
				$items[] = Item_Result::failure( $link_id, 'empty_vendor_code', 'Не указан vendorCode.' );
				$failed  = true;
				continue;
			}

			$items[] = Item_Result::success(
				$link_id,
				array(
					'nmID'   => 5000000 + $link_id,
					'imtID'  => 7000000 + $link_id,
					'chrtID' => 9000000 + $link_id,
				)
			);
		}

		return new Task_Status( $failed ? Async_Task::STATUS_DONE_WITH_ERRORS : Async_Task::STATUS_DONE, $items );
	}
}
