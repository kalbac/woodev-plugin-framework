<?php
/**
 * Asynchronous task ledger contract.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the tasks a marketplace accepted now and finishes later (card imports, labels, price uploads) and the per-item result of
 * each (spec 3.1, `tasks` / `task_items`). The per-item rows are what the products screen shows in its «Ошибки» column.
 *
 * @since 2.0.29
 */
interface Task_Ledger {
	public const ITEM_PENDING  = 'pending';
	public const ITEM_IMPORTED = 'imported';
	public const ITEM_SKIPPED  = 'skipped';
	public const ITEM_FAILED   = 'failed';

	/**
	 * Stores a new task and one pending item per link, before anything is sent.
	 *
	 * @since 2.0.29
	 *
	 * @param Async_Task        $task  Task; its id and remote id are ignored.
	 * @param array<int,string> $items Link id => seller article.
	 * @return int Task id.
	 * @throws \RuntimeException When the database refuses the write.
	 */
	public function open( Async_Task $task, array $items ): int;

	/**
	 * Adds pending items to a stored task.
	 *
	 * @since 2.0.29
	 *
	 * @param int               $task_id Task id.
	 * @param array<int,string> $items   Link id => seller article.
	 * @return void
	 * @throws \RuntimeException When the database refuses the write.
	 */
	public function add_items( int $task_id, array $items ): void;

	/**
	 * Records the marketplace's task id once the submit succeeded.
	 *
	 * @since 2.0.29
	 *
	 * @param int    $task_id        Task id.
	 * @param string $remote_task_id Marketplace task id.
	 * @param string $request_hash   SHA-1 of the request body.
	 * @return void
	 */
	public function attach_remote( int $task_id, string $remote_task_id, string $request_hash ): void;

	/**
	 * Records, just before the request goes out, that it is being sent: the body's SHA-1 and the links in it. From here on the
	 * articles of those links count as sent (an uncertain outcome included), see {@see self::sent_offer_id()}.
	 *
	 * @since 2.0.29
	 *
	 * @param int    $task_id      Task id.
	 * @param string $request_hash SHA-1 of the request body.
	 * @param int[]  $link_ids     Links in the body.
	 * @return void
	 */
	public function mark_sending( int $task_id, string $request_hash, array $link_ids ): void;

	/**
	 * Takes the mark back when the request provably never left (held back locally before any byte was sent).
	 *
	 * @since 2.0.29
	 *
	 * @param int $task_id Task id.
	 * @return void
	 */
	public function unmark_sending( int $task_id ): void;

	/**
	 * Returns the article a link was last sent with, or null when no request ever carried it.
	 *
	 * @since 2.0.29
	 *
	 * @param int $link_id Link id.
	 * @return string|null
	 */
	public function sent_offer_id( int $link_id ): ?string;

	/**
	 * Ends a link's send history: no earlier request counts as having carried its article any more. The tasks and their per-item
	 * results stay as history; only the «this link was in the request» mark is taken off, so a card the link is tied to later starts
	 * with a free article. Called when a link is detached from its card.
	 *
	 * @since 2.0.29
	 *
	 * @param int $link_id Link id.
	 * @return void
	 * @throws \RuntimeException When the database refuses a write.
	 */
	public function forget_sent( int $link_id ): void;

	/**
	 * Stamps a poll that left the status alone (the lifecycle phase of a finished task).
	 *
	 * @since 2.0.29
	 *
	 * @param int $task_id Task id.
	 * @return void
	 */
	public function mark_polled( int $task_id ): void;

	/**
	 * Lists tasks of a kind nobody has looked at since a time: no submit, poll or creation after `$before`, and submitted after
	 * `$since`. The recovery sweep schedules them again.
	 *
	 * @since 2.0.29
	 *
	 * @param string   $kind     `Async_Task::KIND_*`.
	 * @param string[] $statuses `Async_Task::STATUS_*` to look at.
	 * @param int      $before   Unix time; tasks touched later are left out.
	 * @param int      $since    Unix time; tasks submitted earlier are left out.
	 * @param int      $limit    Most tasks.
	 * @return Async_Task[]
	 */
	public function untouched_since( string $kind, array $statuses, int $before, int $since, int $limit ): array;

	/**
	 * Lists, among the given tasks, those of a kind nobody has looked at since a time, whatever their status or age, least recently
	 * touched first. The recovery sweep uses it for tasks that still own unfinished work.
	 *
	 * @since 2.0.29
	 *
	 * @param string $kind     `Async_Task::KIND_*`.
	 * @param int[]  $task_ids Task ids to look at.
	 * @param int    $before   Unix time; tasks touched later are left out.
	 * @param int    $limit    Most tasks.
	 * @return Async_Task[]
	 */
	public function untouched_among( string $kind, array $task_ids, int $before, int $limit ): array;

	/**
	 * Loads a task.
	 *
	 * @since 2.0.29
	 *
	 * @param int $task_id Task id.
	 * @return Async_Task|null
	 */
	public function get( int $task_id ): ?Async_Task;

	/**
	 * Lists the items of a task.
	 *
	 * @since 2.0.29
	 *
	 * @param int $task_id Task id.
	 * @return array<int,array{link_id:int,offer_id:string,status:string,errors:array<int,mixed>}> Keyed by link id.
	 */
	public function items( int $task_id ): array;

	/**
	 * Lists, for a set of links of one account, the items whose task has not finished and whose own result is still pending: the
	 * work a card import still owes those links. One read for the whole set, so a screen that shows many rows asks once. It changes
	 * nothing, claims nothing and calls no marketplace.
	 *
	 * @since 2.0.29
	 *
	 * @param int   $account_id Account id; tasks of other accounts are never returned.
	 * @param int[] $link_ids   Link ids, as many as one screen shows.
	 * @return array<int,array<int,array{task_id:int,kind:string,status:string,offer_id:string}>> Link id => its open items, oldest task
	 *                                        first; `status` is the status of the task (`Async_Task::STATUS_SUBMITTED` or `STATUS_POLLING`).
	 *                                        A link with none is absent.
	 */
	public function open_items( int $account_id, array $link_ids ): array;

	/**
	 * Writes the result of one item.
	 *
	 * @since 2.0.29
	 *
	 * @param int          $task_id Task id.
	 * @param int          $link_id Link id.
	 * @param string       $status  `ITEM_*`.
	 * @param array<mixed> $errors  Marketplace errors, verbatim.
	 * @return void
	 */
	public function settle_item( int $task_id, int $link_id, string $status, array $errors = [] ): void;

	/**
	 * Counts one poll and moves the task to a status (`Async_Task::STATUS_*`); a final status also stamps the finish time.
	 *
	 * @since 2.0.29
	 *
	 * @param int                      $task_id Task id.
	 * @param string                   $status  New status.
	 * @param array<string,mixed>|null $result  New result payload, or null to keep the stored one.
	 * @return void
	 */
	public function record_poll( int $task_id, string $status, ?array $result = null ): void;
}
