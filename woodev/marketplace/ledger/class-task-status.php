<?php
/**
 * Poll outcome of an asynchronous task.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * What `Card_Exporter::poll()` learned: the task's new status and the per-item results known so far.
 *
 * @since 2.0.29
 */
final class Task_Status {
	/**
	 * One of `Async_Task::STATUS_*`.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * Per-item results.
	 *
	 * @var Item_Result[]
	 */
	private $items;

	/**
	 * Marketplace payload worth keeping on the task (summary, file reference).
	 *
	 * @var array<string,mixed>
	 */
	private $result;

	/**
	 * Creates a status.
	 *
	 * @since 2.0.29
	 *
	 * @param string              $status `Async_Task::STATUS_*`.
	 * @param Item_Result[]       $items  Per-item results.
	 * @param array<string,mixed> $result Payload to store on the task.
	 * @throws \InvalidArgumentException On an unknown status.
	 */
	public function __construct( string $status, array $items = [], array $result = [] ) {
		if ( ! in_array( $status, Async_Task::statuses(), true ) ) {
			throw new \InvalidArgumentException( 'Unknown task status.' );
		}

		$this->status = $status;
		$this->items  = $items;
		$this->result = $result;
	}

	/**
	 * Returns the status.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Returns the per-item results.
	 *
	 * @since 2.0.29
	 *
	 * @return Item_Result[]
	 */
	public function items(): array {
		return $this->items;
	}

	/**
	 * Returns the payload to store on the task.
	 *
	 * @since 2.0.29
	 *
	 * @return array<string,mixed>
	 */
	public function result(): array {
		return $this->result;
	}

	/**
	 * Tells whether polling can stop.
	 *
	 * @since 2.0.29
	 *
	 * @return bool
	 */
	public function is_final(): bool {
		return ! in_array( $this->status, [ Async_Task::STATUS_SUBMITTED, Async_Task::STATUS_POLLING ], true );
	}
}
