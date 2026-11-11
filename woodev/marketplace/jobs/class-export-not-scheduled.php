<?php
/**
 * Some export batches could not be scheduled.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by {@see Export_Scheduler::queue_export()} after it tried every batch: Action Scheduler refused (or threw on) some of
 * them. It names the links of those batches, so the caller can take them out of the queue — a link that is `queued` with no task
 * and no scheduled batch is found by nothing and would never be exported.
 *
 * @since 2.0.30
 */
final class Export_Not_Scheduled extends \RuntimeException {
	/**
	 * Links whose batch was not scheduled.
	 *
	 * @var int[]
	 */
	private $link_ids;

	/**
	 * Creates the exception.
	 *
	 * @since 2.0.30
	 *
	 * @param int[] $link_ids Links whose batch was not scheduled.
	 */
	public function __construct( array $link_ids ) {
		parent::__construct( 'Action Scheduler did not queue every export batch.' );

		$this->link_ids = array_values( array_map( 'intval', $link_ids ) );
	}

	/**
	 * Returns the links whose batch was not scheduled.
	 *
	 * @since 2.0.30
	 *
	 * @return int[]
	 */
	public function link_ids(): array {
		return $this->link_ids;
	}
}
