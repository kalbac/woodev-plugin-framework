<?php
/**
 * How often and how long a sync queue retries.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Governor;

defined( 'ABSPATH' ) || exit;

/**
 * The two numbers a marketplace imposes on a sync queue: the shortest time between two accepted sends of one product, and the
 * number of counted failures after which a pair stops being selected. They are the marketplace's, not the layer's: one marketplace
 * allows ten price updates per product per hour, another has no such limit, so the adapter hands the queue its own cadence.
 *
 * @since 2.0.29
 */
final class Sync_Cadence {
	/**
	 * Seconds between two accepted sends of one product; 0 means no minimum.
	 *
	 * @var int
	 */
	private $min_interval;

	/**
	 * Counted failures after which a pair is failed.
	 *
	 * @var int
	 */
	private $max_attempts;

	/**
	 * Creates the cadence.
	 *
	 * @since 2.0.29
	 *
	 * @param int $min_interval Seconds between two accepted sends of one product; 0 = none.
	 * @param int $max_attempts Counted failures after which a pair stops being selected; at least 1.
	 * @throws \InvalidArgumentException When the interval is negative or the attempt cap is below one.
	 */
	public function __construct( int $min_interval, int $max_attempts ) {
		if ( $min_interval < 0 ) {
			throw new \InvalidArgumentException( 'The minimum interval cannot be negative.' );
		}

		if ( $max_attempts < 1 ) {
			throw new \InvalidArgumentException( 'The attempt cap must be at least one.' );
		}

		$this->min_interval = $min_interval;
		$this->max_attempts = $max_attempts;
	}

	/**
	 * Returns the shortest time between two accepted sends of one product.
	 *
	 * @since 2.0.29
	 *
	 * @return int Seconds; 0 = no minimum.
	 */
	public function min_interval(): int {
		return $this->min_interval;
	}

	/**
	 * Returns the number of counted failures after which a pair stops being selected.
	 *
	 * @since 2.0.29
	 *
	 * @return int
	 */
	public function max_attempts(): int {
		return $this->max_attempts;
	}
}
