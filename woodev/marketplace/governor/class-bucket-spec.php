<?php
/**
 * Rate-limit bucket description.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Governor;

defined( 'ABSPATH' ) || exit;

/**
 * Data that describes one rate-limit bucket of a marketplace, so the governor is generic and each adapter only
 * supplies numbers.
 *
 * Ozon: `default` 50 / 1 s burst 50, `stocks` 80 / 60 s, `product_ops` 30000 / 60 s counted in items.
 * Wildberries: `marketplace` 300 / 60 s burst 20, where a 4xx answer costs 10 requests (`cost_4xx`).
 *
 * @since 2.0.29
 */
final class Bucket_Spec {
	public const COST_REQUEST = 'request';
	public const COST_ITEMS   = 'items';

	/**
	 * Bucket key.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Tokens added per period.
	 *
	 * @var int
	 */
	private $limit;

	/**
	 * Period length in seconds.
	 *
	 * @var int
	 */
	private $period_seconds;

	/**
	 * Largest number of tokens the bucket holds.
	 *
	 * @var int
	 */
	private $burst;

	/**
	 * What one acquisition costs.
	 *
	 * @var string
	 */
	private $cost_mode;

	/**
	 * Cost of a request the marketplace answered with a 4xx.
	 *
	 * @var int
	 */
	private $cost_4xx;

	/**
	 * Creates a bucket description.
	 *
	 * @since 2.0.29
	 *
	 * @param string $key            Bucket key.
	 * @param int    $limit          Tokens per period, at least 1.
	 * @param int    $period_seconds Period in seconds, at least 1.
	 * @param int    $burst          Bucket capacity; 0 means the same as `$limit`.
	 * @param string $cost_mode      `COST_REQUEST` (one token per request) or `COST_ITEMS` (one per item).
	 * @param int    $cost_4xx       Tokens one 4xx answer costs, at least 1.
	 * @throws \InvalidArgumentException On a non-positive number or an unknown cost mode.
	 */
	public function __construct( string $key, int $limit, int $period_seconds, int $burst = 0, string $cost_mode = self::COST_REQUEST, int $cost_4xx = 1 ) {
		if ( '' === $key || $limit < 1 || $period_seconds < 1 || $burst < 0 || $cost_4xx < 1 ) {
			throw new \InvalidArgumentException( 'Bucket needs a key, a positive limit and period, and a cost of at least 1.' );
		}

		if ( ! in_array( $cost_mode, [ self::COST_REQUEST, self::COST_ITEMS ], true ) ) {
			throw new \InvalidArgumentException( 'Unknown bucket cost mode.' );
		}

		$this->key            = $key;
		$this->limit          = $limit;
		$this->period_seconds = $period_seconds;
		$this->burst          = 0 === $burst ? $limit : $burst;
		$this->cost_mode      = $cost_mode;
		$this->cost_4xx       = $cost_4xx;
	}

	/**
	 * Returns the bucket key.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * Returns the tokens added per period.
	 *
	 * @since 2.0.29
	 *
	 * @return int
	 */
	public function limit(): int {
		return $this->limit;
	}

	/**
	 * Returns the period length in seconds.
	 *
	 * @since 2.0.29
	 *
	 * @return int
	 */
	public function period_seconds(): int {
		return $this->period_seconds;
	}

	/**
	 * Returns the bucket capacity.
	 *
	 * @since 2.0.29
	 *
	 * @return int
	 */
	public function burst(): int {
		return $this->burst;
	}

	/**
	 * Returns the cost mode.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function cost_mode(): string {
		return $this->cost_mode;
	}

	/**
	 * Returns the cost of a 4xx answer.
	 *
	 * @since 2.0.29
	 *
	 * @return int
	 */
	public function cost_4xx(): int {
		return $this->cost_4xx;
	}
}
