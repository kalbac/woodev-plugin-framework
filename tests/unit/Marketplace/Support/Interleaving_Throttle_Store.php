<?php
/**
 * A throttle store that lets another writer in between a read and the swap.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support;

use Woodev\Framework\Marketplace\Governor\Throttle_Row;
use Woodev\Framework\Marketplace\Governor\Throttle_Store;

/**
 * Wraps a real store and, once before each of the first swaps, runs a callback: the other Action Scheduler run that
 * writes after this one has read. Counts the swaps it was asked for.
 */
final class Interleaving_Throttle_Store implements Throttle_Store {
	/**
	 * Wrapped store.
	 *
	 * @var Throttle_Store
	 */
	private $inner;

	/**
	 * The competing writer, run before a swap.
	 *
	 * @var callable|null
	 */
	private $before_swap;

	/**
	 * How many swaps the competing writer gets in front of.
	 *
	 * @var int
	 */
	private $interruptions;

	/**
	 * Swaps asked for so far.
	 *
	 * @var int
	 */
	public $swaps = 0;

	/**
	 * Hold writes asked for so far.
	 *
	 * @var int
	 */
	public $raises = 0;

	/**
	 * Creates the wrapper.
	 *
	 * @param Throttle_Store $inner         Store to wrap.
	 * @param callable|null  $before_swap   Competing writer.
	 * @param int            $interruptions Number of swaps it runs before; `PHP_INT_MAX` for every swap.
	 */
	public function __construct( Throttle_Store $inner, ?callable $before_swap = null, int $interruptions = 1 ) {
		$this->inner         = $inner;
		$this->before_swap   = $before_swap;
		$this->interruptions = $interruptions;
	}

	/**
	 * Reads a bucket.
	 *
	 * @param string $account_key Account.
	 * @param string $bucket      Bucket.
	 * @return Throttle_Row|null
	 */
	public function read( string $account_key, string $bucket ): ?Throttle_Row {
		return $this->inner->read( $account_key, $bucket );
	}

	/**
	 * Creates a bucket.
	 *
	 * @param string              $account_key Account.
	 * @param string              $bucket      Bucket.
	 * @param array<string,mixed> $state       State.
	 * @return void
	 */
	public function create( string $account_key, string $bucket, array $state ): void {
		$this->inner->create( $account_key, $bucket, $state );
	}

	/**
	 * Runs the competing writer, then swaps.
	 *
	 * @param string              $account_key      Account.
	 * @param string              $bucket           Bucket.
	 * @param int                 $expected_version Version the caller read.
	 * @param array<string,mixed> $state            New state.
	 * @param int|null            $disabled_until   Switch-off time.
	 * @param string              $disabled_reason  Switch-off reason.
	 * @return bool
	 */
	public function swap( string $account_key, string $bucket, int $expected_version, array $state, ?int $disabled_until, string $disabled_reason ): bool {
		++$this->swaps;
		if ( null !== $this->before_swap && $this->swaps <= $this->interruptions ) {
			call_user_func( $this->before_swap );
		}

		return $this->inner->swap( $account_key, $bucket, $expected_version, $state, $disabled_until, $disabled_reason );
	}

	/**
	 * Runs the competing writer, then raises the hold; the competitor gets in front of this write just as it does for a swap.
	 *
	 * @param string $account_key Account.
	 * @param string $bucket      Bucket.
	 * @param int    $until       Unix time to hold until.
	 * @return bool
	 */
	public function raise_until( string $account_key, string $bucket, int $until ): bool {
		++$this->raises;
		if ( null !== $this->before_swap && $this->raises <= $this->interruptions ) {
			call_user_func( $this->before_swap );
		}

		return $this->inner->raise_until( $account_key, $bucket, $until );
	}
}
