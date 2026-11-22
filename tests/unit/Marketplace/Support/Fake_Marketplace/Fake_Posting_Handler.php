<?php
/**
 * Recording order flow of the fake marketplace.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Flows\History_Import;
use Woodev\Framework\Marketplace\Jobs\Posting_Handler;

/**
 * Remembers what the posting jobs asked and throws what the test scripted.
 */
final class Fake_Posting_Handler implements Posting_Handler {
	/**
	 * Remote orders refreshed: `[ account id, external id ]`.
	 *
	 * @var array<int,array{0:int,1:string}>
	 */
	public $refreshed = [];

	/**
	 * Accounts caught up.
	 *
	 * @var int[]
	 */
	public $caught_up = [];

	/**
	 * For each `refresh()` and each `catch_up()`, in order, the history cutoff of the work when it ran; 0 for work without a history.
	 *
	 * @var int[]
	 */
	public $cutoffs = [];

	/**
	 * What the next `catch_up()` does besides recording itself, with the account: for a test that queues refreshes the way a poll does.
	 *
	 * @var callable|null
	 */
	public $on_catch_up;

	/**
	 * Thrown by the next `refresh()` or `catch_up()`.
	 *
	 * @var \Throwable|null
	 */
	public $fails;

	/**
	 * Reads one remote order.
	 *
	 * @param int    $account_id  Account id.
	 * @param string $external_id Remote order number.
	 * @return void
	 * @throws \Throwable The scripted failure.
	 */
	public function refresh( int $account_id, string $external_id ): void {
		$this->refreshed[] = [ $account_id, $external_id ];
		$this->cutoffs[]   = History_Import::cutoff();

		if ( null !== $this->fails ) {
			throw $this->fails;
		}
	}

	/**
	 * Catches up on the changed orders.
	 *
	 * @param Account $account Account.
	 * @return void
	 * @throws \Throwable The scripted failure.
	 */
	public function catch_up( Account $account ): void {
		$this->caught_up[] = $account->id();
		$this->cutoffs[]   = History_Import::cutoff();

		if ( null !== $this->on_catch_up ) {
			call_user_func( $this->on_catch_up, $account );
		}

		if ( null !== $this->fails ) {
			throw $this->fails;
		}
	}
}
