<?php
/**
 * What one `export_batch` run has done so far.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Lets the runner recover from an unexpected exception at any step: which task it opened, which links it claimed, whether the
 * request was sent and whether its outcome is already recorded.
 *
 * @since 2.0.30
 */
final class Export_Run {
	/**
	 * Task id, 0 before the task row exists.
	 *
	 * @var int
	 */
	public $task_id = 0;

	/**
	 * Links the run claimed.
	 *
	 * @var int[]
	 */
	public $claimed = [];

	/**
	 * Links in the request being sent; empty before.
	 *
	 * @var int[]
	 */
	public $sending = [];

	/**
	 * Whether the run's outcome is already written (submitted, refused or failed), so recovery must not touch it.
	 *
	 * @var bool
	 */
	public $settled = false;
}
