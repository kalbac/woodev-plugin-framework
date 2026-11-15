<?php
/**
 * The time one import run may take.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * A run's clock. It is checked before every card and before every image, and it stops the run with {@see Budget_Spent} once the
 * time is spent — but **only after the run has finished a card**. An image that passed the check is not progress: the writer asks
 * before it looks for a cached copy, and a download may fail, so counting it would let a requeue repeat for ever (a slow read plus
 * a card of several images, or an image that times out). A slow read of the cards that eats the whole budget by itself therefore
 * still ends with one card finished, and a requeue that keeps its attempt always moves the batch forward.
 *
 * @since 2.1.6
 */
final class Import_Budget {
	/**
	 * Unix time in seconds, fractions allowed: `function (): float`.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Time the run began, in the clock's seconds.
	 *
	 * @var float
	 */
	private $started;

	/**
	 * Seconds the run may take.
	 *
	 * @var int
	 */
	private $seconds;

	/**
	 * Whether the run has finished a card.
	 *
	 * @var bool
	 */
	private $advanced = false;

	/**
	 * Starts the run's clock.
	 *
	 * @since 2.1.6
	 *
	 * @param callable $clock   `function (): float`, the time now in seconds.
	 * @param int      $seconds Seconds the run may take.
	 */
	public function __construct( callable $clock, int $seconds ) {
		$this->clock   = $clock;
		$this->seconds = $seconds;
		$this->started = (float) call_user_func( $clock );
	}

	/**
	 * Checks the time before a card or an image.
	 *
	 * @since 2.1.6
	 *
	 * @return void
	 * @throws Budget_Spent When the time is spent and the run has finished a card.
	 */
	public function check(): void {
		if ( $this->advanced && $this->spent() ) {
			throw new Budget_Spent( 'The import run used its time.' );
		}
	}

	/**
	 * Records a finished card.
	 *
	 * @since 2.1.6
	 *
	 * @return void
	 */
	public function card_finished(): void {
		$this->advanced = true;
	}

	/**
	 * Tells whether the time is spent.
	 *
	 * @return bool
	 */
	private function spent(): bool {
		return (float) call_user_func( $this->clock ) - $this->started >= $this->seconds;
	}
}
