<?php
/** Carrier document source contract. */

namespace Woodev\Framework\Shipping\Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Carrier owned document lookup. Implementations must return pending instead of waiting for slow carrier generation.
 *
 * @since 2.0.2
 */
interface Document_Source {
	/** @since 2.0.2 @param \WC_Order $order shipment order @return string[] supported document types */
	public function get_document_types( \WC_Order $order ): array;

	/** @since 2.0.2 @param \WC_Order $order shipment order @param string $type document type @return Document_Result */
	public function get_document( \WC_Order $order, string $type ): Document_Result;
}
