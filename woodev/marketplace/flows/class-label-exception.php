<?php
/**
 * A label request that cannot go on.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a sentence the merchant can read (Russian, for a WordPress novice): the licence refusal, an order list the service
 * refuses, or the marketplace's answer to a label request translated by the adapter's label provider.
 *
 * @since 2.0.30
 */
final class Label_Exception extends \RuntimeException {
}
