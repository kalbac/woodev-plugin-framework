<?php
/**
 * A run lost its account claim.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a run finds, right before a side effect, that its account claim expired and another run took it. The run stops
 * without writing; what it left behind is taken over by the run that holds the claim now.
 *
 * @since 2.1.0
 */
final class Claim_Lost extends \RuntimeException {
}
