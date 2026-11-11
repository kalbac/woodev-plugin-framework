<?php
/**
 * Outcome of one catalogue refresh pass.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

defined( 'ABSPATH' ) || exit;

/**
 * What a pass of {@see Catalog_Refresher} did: how many cache rows it wrote, which categories it could not refresh and why, and
 * whether it reached the end or stopped on its time budget.
 *
 * @since 2.1.0
 */
final class Refresh_Result {
	/**
	 * Rows written.
	 *
	 * @var int
	 */
	private $refreshed;

	/**
	 * Failure texts by category key.
	 *
	 * @var array<string,string>
	 */
	private $failures;

	/**
	 * Whether the pass reached the end.
	 *
	 * @var bool
	 */
	private $complete;

	/**
	 * Creates the result.
	 *
	 * @since 2.1.0
	 *
	 * @param int                  $refreshed Rows written.
	 * @param array<string,string> $failures  Failure text by category key (the cache key of its descriptor).
	 * @param bool                 $complete  Whether the pass reached the end.
	 */
	public function __construct( int $refreshed, array $failures, bool $complete ) {
		$this->refreshed = $refreshed;
		$this->failures  = $failures;
		$this->complete  = $complete;
	}

	/**
	 * Returns the number of cache rows written.
	 *
	 * @since 2.1.0
	 *
	 * @return int
	 */
	public function refreshed(): int {
		return $this->refreshed;
	}

	/**
	 * Returns the categories that could not be refreshed.
	 *
	 * @since 2.1.0
	 *
	 * @return array<string,string> Failure text by category key.
	 */
	public function failures(): array {
		return $this->failures;
	}

	/**
	 * Tells whether the pass reached the end; false means the time budget ran out and another pass must follow.
	 *
	 * @since 2.1.0
	 *
	 * @return bool
	 */
	public function is_complete(): bool {
		return $this->complete;
	}
}
