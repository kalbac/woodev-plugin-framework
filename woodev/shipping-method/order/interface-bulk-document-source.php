<?php
/** Optional carrier capability: one document for several orders. */

namespace Woodev\Framework\Shipping\Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * A {@see Document_Source} that can also print one document for a SET of orders in a single file (#1192).
 *
 * Optional: a carrier whose API takes one order per print task simply does not implement it, and the orders page offers
 * no bulk print for that carrier. The framework never calls a method of this interface on a source that does not
 * implement it.
 *
 * The same rules as the single-order seam apply: answer {@see Document_Result::pending()} instead of waiting, and be
 * idempotent — the client re-asks with the same orders until the document is ready, so the carrier task must be
 * remembered by the SET (a hash of the sorted carrier order ids), never by one order's meta.
 *
 * @since 2.0.2
 */
interface Bulk_Document_Source extends Document_Source {
	/**
	 * Document types that can be printed for several orders at once. Called while building the orders page, so
	 * answer from local data and never call the carrier.
	 *
	 * @since 2.0.2
	 * @return string[] a subset of the types {@see Document_Source::get_document_types()} offers, e.g. `[ 'waybill', 'barcode' ]`
	 */
	public function get_bulk_document_types(): array;

	/**
	 * The most orders the carrier accepts in one document. The route refuses a larger selection with a clear
	 * message rather than splitting it into several files.
	 *
	 * @since 2.0.2
	 * @return int at least 1
	 */
	public function get_bulk_document_limit(): int;

	/**
	 * One document for all `$orders`. Every order handed in is already checked by the framework: it exists, belongs to
	 * THIS carrier, has a carrier order id and is offered `$type`; a selection that mixes carriers never gets here.
	 *
	 * A carrier that silently leaves an order out of the file (CDEK answers `READY` with only a warning) must say so:
	 * return the result with {@see Document_Result::with_skipped()} so the merchant is told who is missing.
	 *
	 * @since 2.0.2
	 * @param \WC_Order[] $orders shipment orders, in the order the merchant selected them
	 * @param string      $type   document type from {@see self::get_bulk_document_types()}
	 * @return Document_Result
	 */
	public function get_bulk_document( array $orders, string $type ): Document_Result;
}
