<?php
/** Offline carrier document source for rig demonstrations. */

defined( 'ABSPATH' ) || exit;

/**
 * Demonstrates immediate binary and asynchronous pending carrier results.
 */
final class Woodev_Realistic_Document_Source implements \Woodev\Framework\Shipping\Order\Document_Source {
	/** @inheritDoc */
	public function get_document_types( \WC_Order $order ): array { return [ 'waybill', 'barcode' ]; }

	/** @inheritDoc */
	public function get_document( \WC_Order $order, string $type ): \Woodev\Framework\Shipping\Order\Document_Result {
		if ( 'barcode' === $type ) { return \Woodev\Framework\Shipping\Order\Document_Result::pending( 5 ); }
		return \Woodev\Framework\Shipping\Order\Document_Result::binary( "%PDF-1.4\n% Woodev fixture waybill for order " . $order->get_id() . "\n%%EOF\n" );
	}
}
