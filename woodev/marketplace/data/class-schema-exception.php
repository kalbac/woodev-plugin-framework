<?php
/**
 * Exception of the schema installer.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Data;

defined( 'ABSPATH' ) || exit;

/**
 * A table is still missing after `dbDelta` ({@see Lifecycle::create_tables()}).
 *
 * @since 2.0.29
 */
final class Schema_Exception extends \RuntimeException {
}
