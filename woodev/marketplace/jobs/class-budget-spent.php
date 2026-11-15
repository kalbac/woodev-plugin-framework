<?php
/**
 * An import run used its time.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by {@see Import_Budget} before a card or an image when the run's time is spent and the run has already advanced. The
 * import job catches it, puts the unfinished cards back in the queue and ends the run; it never reaches {@see Account_Job}.
 *
 * @since 2.1.6
 */
final class Budget_Spent extends \RuntimeException {
}
