<?php
/**
 * Asynchronous marketplace task.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * A job the marketplace accepted now and finishes later: a card import, a label render, a price upload.
 *
 * `remote_task_id` is a string and nullable: Ozon ids are int64 numbers, Wildberries price tasks have ids that may not
 * be numeric, and Wildberries card uploads have no task id at all (the error list is read later).
 *
 * @since 2.0.29
 */
final class Async_Task {
	public const KIND_IMPORT = 'import';
	public const KIND_LABEL  = 'label';
	public const KIND_PRICE  = 'price';

	public const STATUS_SUBMITTED        = 'submitted';
	public const STATUS_POLLING          = 'polling';
	public const STATUS_DONE             = 'done';
	public const STATUS_DONE_WITH_ERRORS = 'done_with_errors';
	public const STATUS_FAILED           = 'failed';
	public const STATUS_EXPIRED          = 'expired';

	/**
	 * Local id, null before the task is stored.
	 *
	 * @var int|null
	 */
	private $task_id;

	/**
	 * Account id.
	 *
	 * @var int
	 */
	private $account_id;

	/**
	 * Task kind.
	 *
	 * @var string
	 */
	private $kind;

	/**
	 * Marketplace task id, null when the marketplace has none.
	 *
	 * @var string|null
	 */
	private $remote_task_id;

	/**
	 * Status.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * Submission time.
	 *
	 * @var \DateTimeImmutable|null
	 */
	private $submitted_at;

	/**
	 * Number of items in the task.
	 *
	 * @var int
	 */
	private $item_count;

	/**
	 * Result payload (label file reference, import summary).
	 *
	 * @var array<string,mixed>
	 */
	private $result;

	/**
	 * Creates a task.
	 *
	 * @since 2.0.29
	 *
	 * @param int                     $account_id     Account id.
	 * @param string                  $kind           `KIND_*`.
	 * @param string|null             $remote_task_id Marketplace task id.
	 * @param string                  $status         `STATUS_*`.
	 * @param int                     $item_count     Items in the task.
	 * @param \DateTimeImmutable|null $submitted_at   Submission time.
	 * @param int|null                $task_id        Local id once stored.
	 * @param array<string,mixed>     $result         Result payload.
	 * @throws \InvalidArgumentException On an unknown kind or status.
	 */
	public function __construct(
		int $account_id,
		string $kind,
		?string $remote_task_id = null,
		string $status = self::STATUS_SUBMITTED,
		int $item_count = 0,
		?\DateTimeImmutable $submitted_at = null,
		?int $task_id = null,
		array $result = []
	) {
		if ( ! in_array( $kind, [ self::KIND_IMPORT, self::KIND_LABEL, self::KIND_PRICE ], true ) ) {
			throw new \InvalidArgumentException( 'Unknown task kind.' );
		}

		if ( ! in_array( $status, self::statuses(), true ) ) {
			throw new \InvalidArgumentException( 'Unknown task status.' );
		}

		$this->task_id        = $task_id;
		$this->account_id     = $account_id;
		$this->kind           = $kind;
		$this->remote_task_id = $remote_task_id;
		$this->status         = $status;
		$this->submitted_at   = $submitted_at;
		$this->item_count     = $item_count;
		$this->result         = $result;
	}

	/**
	 * Lists every status.
	 *
	 * @since 2.0.29
	 *
	 * @return string[]
	 */
	public static function statuses(): array {
		return [ self::STATUS_SUBMITTED, self::STATUS_POLLING, self::STATUS_DONE, self::STATUS_DONE_WITH_ERRORS, self::STATUS_FAILED, self::STATUS_EXPIRED ];
	}

	/**
	 * Returns a copy with another status and optionally a new result.
	 *
	 * @since 2.0.29
	 *
	 * @param string                   $status `STATUS_*`.
	 * @param array<string,mixed>|null $result New result, or null to keep the current one.
	 * @return self
	 */
	public function with_status( string $status, ?array $result = null ): self {
		return new self( $this->account_id, $this->kind, $this->remote_task_id, $status, $this->item_count, $this->submitted_at, $this->task_id, $result ?? $this->result );
	}

	/**
	 * Tells whether the task will not change any more.
	 *
	 * @since 2.0.29
	 *
	 * @return bool
	 */
	public function is_final(): bool {
		return ! in_array( $this->status, [ self::STATUS_SUBMITTED, self::STATUS_POLLING ], true );
	}

	/**
	 * Returns the local id, or null before the task is stored.
	 *
	 * @since 2.0.29
	 *
	 * @return int|null
	 */
	public function task_id(): ?int {
		return $this->task_id;
	}

	/**
	 * Returns the account id.
	 *
	 * @since 2.0.29
	 *
	 * @return int
	 */
	public function account_id(): int {
		return $this->account_id;
	}

	/**
	 * Returns the kind.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * Returns the marketplace task id, or null when it has none.
	 *
	 * @since 2.0.29
	 *
	 * @return string|null
	 */
	public function remote_task_id(): ?string {
		return $this->remote_task_id;
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
	 * Returns the submission time.
	 *
	 * @since 2.0.29
	 *
	 * @return \DateTimeImmutable|null
	 */
	public function submitted_at(): ?\DateTimeImmutable {
		return $this->submitted_at;
	}

	/**
	 * Returns the item count.
	 *
	 * @since 2.0.29
	 *
	 * @return int
	 */
	public function item_count(): int {
		return $this->item_count;
	}

	/**
	 * Returns the result payload.
	 *
	 * @since 2.0.29
	 *
	 * @return array<string,mixed>
	 */
	public function result(): array {
		return $this->result;
	}
}
