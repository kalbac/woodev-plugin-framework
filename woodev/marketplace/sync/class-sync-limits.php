<?php
/**
 * Per-request limits of a marketplace's stock and price calls.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * What one request to the marketplace can carry and how long it may take. The senders count requests against a run's budget,
 * so they — not the pusher — cut the work into requests, and they start no request that cannot finish inside the run's time
 * ({@see \Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter::sync_limits()}).
 *
 * @since 2.0.30
 */
final class Sync_Limits {
	/**
	 * Most stock lines one push request takes.
	 *
	 * @var int
	 */
	private $stock_batch;

	/**
	 * Most prices one push request takes.
	 *
	 * @var int
	 */
	private $price_batch;

	/**
	 * Seconds one request may take (the HTTP timeout of the plugin's client).
	 *
	 * @var float
	 */
	private $request_seconds;

	/**
	 * Creates the limits.
	 *
	 * @since 2.0.30
	 *
	 * @param int   $stock_batch     Most stock lines one push request takes.
	 * @param int   $price_batch     Most prices one push request takes.
	 * @param float $request_seconds Seconds one request may take.
	 * @throws \InvalidArgumentException When a batch is below 1 or the time is not positive.
	 */
	public function __construct( int $stock_batch, int $price_batch, float $request_seconds ) {
		if ( $stock_batch < 1 || $price_batch < 1 || $request_seconds <= 0 ) {
			throw new \InvalidArgumentException( 'A batch takes at least one item and a request takes some time.' );
		}

		$this->stock_batch     = $stock_batch;
		$this->price_batch     = $price_batch;
		$this->request_seconds = $request_seconds;
	}

	/**
	 * Returns the most stock lines one push request takes.
	 *
	 * @since 2.0.30
	 *
	 * @return int
	 */
	public function stock_batch(): int {
		return $this->stock_batch;
	}

	/**
	 * Returns the most prices one push request takes.
	 *
	 * @since 2.0.30
	 *
	 * @return int
	 */
	public function price_batch(): int {
		return $this->price_batch;
	}

	/**
	 * Returns the seconds one request may take.
	 *
	 * @since 2.0.30
	 *
	 * @return float
	 */
	public function request_seconds(): float {
		return $this->request_seconds;
	}
}
