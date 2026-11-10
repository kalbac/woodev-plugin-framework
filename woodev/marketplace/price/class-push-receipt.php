<?php
/**
 * Outcome of submitting prices.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Price;

use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Framework\Marketplace\Ledger\Item_Result;

defined( 'ABSPATH' ) || exit;

/**
 * What a price submission returned. Ozon answers per item at once (`immediate`). Wildberries answers with a task that
 * is settled later (`pending`), and HTTP 208 means «this task already exists» (`duplicate_of` carries its remote id, so
 * the caller reuses the task instead of creating a second one).
 *
 * @since 2.0.29
 */
final class Push_Receipt {
	/**
	 * Results known now.
	 *
	 * @var Item_Result[]
	 */
	private $immediate;

	/**
	 * Task to settle later, or null.
	 *
	 * @var Async_Task|null
	 */
	private $pending;

	/**
	 * Remote id of the existing task this submission duplicated, or null.
	 *
	 * @var string|null
	 */
	private $duplicate_of;

	/**
	 * Creates a receipt; prefer the named constructors.
	 *
	 * @since 2.0.29
	 *
	 * @param Item_Result[]   $immediate    Results known now.
	 * @param Async_Task|null $pending      Task to settle later.
	 * @param string|null     $duplicate_of Remote id of the duplicated task.
	 */
	public function __construct( array $immediate = [], ?Async_Task $pending = null, ?string $duplicate_of = null ) {
		$this->immediate    = $immediate;
		$this->pending      = $pending;
		$this->duplicate_of = $duplicate_of;
	}

	/**
	 * Builds a receipt whose results are all known.
	 *
	 * @since 2.0.29
	 *
	 * @param Item_Result[] $results Per-item results.
	 * @return self
	 */
	public static function immediate( array $results ): self {
		return new self( $results );
	}

	/**
	 * Builds a receipt for a task that finishes later.
	 *
	 * @since 2.0.29
	 *
	 * @param Async_Task $task Task of kind `price`.
	 * @return self
	 */
	public static function pending( Async_Task $task ): self {
		return new self( [], $task );
	}

	/**
	 * Builds a receipt for a submission the marketplace recognised as a duplicate of a running task.
	 *
	 * @since 2.0.29
	 *
	 * @param string     $remote_task_id Remote id of the existing task.
	 * @param Async_Task $task           The existing task, to be reused.
	 * @return self
	 */
	public static function duplicate( string $remote_task_id, Async_Task $task ): self {
		return new self( [], $task, $remote_task_id );
	}

	/**
	 * Returns the results known now (may be empty).
	 *
	 * @since 2.0.29
	 *
	 * @return Item_Result[]
	 */
	public function immediate_results(): array {
		return $this->immediate;
	}

	/**
	 * Returns the task to settle later, or null when every result is known.
	 *
	 * @since 2.0.29
	 *
	 * @return Async_Task|null
	 */
	public function pending_task(): ?Async_Task {
		return $this->pending;
	}

	/**
	 * Returns the remote id of the duplicated task, or null.
	 *
	 * @since 2.0.29
	 *
	 * @return string|null
	 */
	public function duplicate_of(): ?string {
		return $this->duplicate_of;
	}

	/**
	 * Tells whether a task still has to be settled.
	 *
	 * @since 2.0.29
	 *
	 * @return bool
	 */
	public function is_pending(): bool {
		return null !== $this->pending;
	}
}
