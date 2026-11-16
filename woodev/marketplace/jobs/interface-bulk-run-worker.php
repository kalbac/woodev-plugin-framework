<?php
/**
 * What a bulk run does in each part.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Bulk_Run;

defined( 'ABSPATH' ) || exit;

/**
 * The strategy of {@see Bulk_Run_Runner}: the runner owns the lifecycle (the lock, the tick token, the budget, the saves, the stop);
 * the worker owns the work. The «Товары сайта» run reads parents under a filter; the remote-cards run (E3) feeds a frozen list.
 *
 * @since 2.1.8
 */
interface Bulk_Run_Worker {
	/**
	 * Why the run must end `stopped` instead of working: a lapsed licence for an action that writes to the marketplace, a paused
	 * account, a user who lost the right. Asked once per tick, after the user is set.
	 *
	 * @since 2.1.8
	 *
	 * @param Account  $account Account.
	 * @param Bulk_Run $run     The run.
	 * @return string|null The sentence the account line shows; null when the run may go on.
	 */
	public function refusal( Account $account, Bulk_Run $run ): ?string;

	/**
	 * Reads the next part from the cursor and does it.
	 *
	 * @since 2.1.8
	 *
	 * @param Account             $account Account.
	 * @param Bulk_Run            $run     The run.
	 * @param array<string,mixed> $cursor  Where the previous part stopped.
	 * @param callable            $owns    `function (): bool` — whether this tick still holds the run; asked before every product write.
	 * @return array{cursor:array<string,mixed>,finished:bool,counts:array<string,int>,groups:array<int,array<string,mixed>>,busy:int}
	 *         `counts` are what this part adds (`products_done`, `offers_accepted`, `offers_skipped`, `offers_failed`); `busy` is a
	 *         number of seconds when the part could not start and must be tried again unchanged, 0 otherwise.
	 * @throws Bulk_Run_Lost When `$owns` says no.
	 */
	public function part( Account $account, Bulk_Run $run, array $cursor, callable $owns ): array;
}
