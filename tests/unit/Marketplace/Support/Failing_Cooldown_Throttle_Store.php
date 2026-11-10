<?php
/**
 * A throttle store whose hold write is refused by the database.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support;

use Woodev\Framework\Marketplace\Governor\Throttle_Row;
use Woodev\Framework\Marketplace\Governor\Throttle_Store;

/**
 * Delegates everything to a real store except `raise_until()`, which fails the way a refused statement does.
 */
final class Failing_Cooldown_Throttle_Store implements Throttle_Store {
	/**
	 * Wrapped store.
	 *
	 * @var Throttle_Store
	 */
	private $inner;

	/**
	 * Creates the wrapper.
	 *
	 * @param Throttle_Store $inner Store to wrap.
	 */
	public function __construct( Throttle_Store $inner ) {
		$this->inner = $inner;
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
	 * Swaps a bucket.
	 *
	 * @param string              $account_key      Account.
	 * @param string              $bucket           Bucket.
	 * @param int                 $expected_version Version.
	 * @param array<string,mixed> $state            State.
	 * @param int|null            $disabled_until   Switch-off time.
	 * @param string              $disabled_reason  Reason.
	 * @return bool
	 */
	public function swap( string $account_key, string $bucket, int $expected_version, array $state, ?int $disabled_until, string $disabled_reason ): bool {
		return $this->inner->swap( $account_key, $bucket, $expected_version, $state, $disabled_until, $disabled_reason );
	}

	/**
	 * Refuses the write.
	 *
	 * @param string $account_key Account.
	 * @param string $bucket      Bucket.
	 * @param int    $until       Unix time.
	 * @return bool Always false.
	 */
	public function raise_until( string $account_key, string $bucket, int $until ): bool {
		return false;
	}
}
