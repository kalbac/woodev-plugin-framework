<?php
/**
 * A named lock a test can hold, steal and lose.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support;

use Woodev\Framework\Marketplace\Jobs\Bulk_Run_Locking;

/**
 * Stands in for MySQL's `GET_LOCK()`, which SQLite cannot run: the integration test proves the real one on two connections.
 */
final class Scripted_Bulk_Run_Lock implements Bulk_Run_Locking {
	/**
	 * Whether another connection holds the lock.
	 *
	 * @var bool
	 */
	public $taken_by_other = false;

	/**
	 * Whether this connection holds the lock now.
	 *
	 * @var bool
	 */
	public $mine = false;

	/**
	 * Answers of the next `is_held()` calls; when empty the answer is whether this connection holds the lock.
	 *
	 * @var bool[]
	 */
	public $held_script = [];

	/**
	 * How often the lock was released.
	 *
	 * @var int
	 */
	public $releases = 0;

	/**
	 * How often `is_held()` was asked.
	 *
	 * @var int
	 */
	public $checks = 0;

	/**
	 * @param int $account_id Account.
	 * @return bool
	 */
	public function acquire( int $account_id ): bool {
		if ( $this->taken_by_other ) {
			return false;
		}

		$this->mine = true;

		return true;
	}

	/**
	 * @param int $account_id Account.
	 * @return void
	 */
	public function release( int $account_id ): void {
		++$this->releases;
		$this->mine = false;
	}

	/**
	 * @param int $account_id Account.
	 * @return bool
	 */
	public function is_held( int $account_id ): bool {
		++$this->checks;

		return [] === $this->held_script ? $this->mine : (bool) array_shift( $this->held_script );
	}
}
