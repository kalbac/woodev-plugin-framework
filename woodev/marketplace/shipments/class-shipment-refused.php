<?php
/**
 * Marketplace domain — a refused shipment action.
 *
 * @package Woodev\Framework\Marketplace\Shipments
 */

namespace Woodev\Framework\Marketplace\Shipments;

defined( 'ABSPATH' ) || exit;

/**
 * Nothing was done with a shipment; the message is the sentence the merchant reads, in Russian.
 *
 * @since 2.1.28
 */
final class Shipment_Refused extends \RuntimeException {
}
