<?php
/**
 * A bulk run tick lost the run.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by the ownership check a tick makes before a product write: the connection that took the named lock is gone (a reconnect
 * after error 2006 runs on a new one that holds nothing), so another tick may own the run now. The tick stops without writing.
 *
 * It extends `\Exception`, not `\RuntimeException`: the code between the check and the write catches `\RuntimeException` for its own
 * reasons and must not swallow this.
 *
 * @since 2.1.8
 */
final class Bulk_Run_Lost extends \Exception {
}
