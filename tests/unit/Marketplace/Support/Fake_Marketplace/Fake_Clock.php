<?php
/**
 * Controllable clock for the fake marketplace.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

/**
 * A clock that only moves when a test says so, shared by every fake so quarantine windows and token refills are exact.
 */
final class Fake_Clock {
	/**
	 * Current time.
	 *
	 * @var \DateTimeImmutable
	 */
	private $now;

	/**
	 * Starts the clock.
	 *
	 * @param string $start ISO 8601 start time.
	 */
	public function __construct( string $start = '2026-11-08T10:00:00+00:00' ) {
		$this->now = new \DateTimeImmutable( $start );
	}

	/**
	 * Returns the current time.
	 *
	 * @return \DateTimeImmutable
	 */
	public function now(): \DateTimeImmutable {
		return $this->now;
	}

	/**
	 * Moves the clock forward.
	 *
	 * @param int $seconds Seconds to add.
	 * @return void
	 */
	public function advance( int $seconds ): void {
		$this->now = $this->now->modify( '+' . $seconds . ' seconds' );
	}
}
