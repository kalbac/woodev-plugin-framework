<?php
/** Optional carrier capability: names and labels of the document types a source offers. */

namespace Woodev\Framework\Shipping\Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * A {@see Document_Source} that declares the types it offers (#1257): the key, the label of the single-order button
 * and, for a type that can be printed for several orders, the label of the bulk entry.
 *
 * Optional: a source that declares nothing keeps the framework's two built-in types — `waybill` («Накладная») and
 * `barcode` («Штрихкод») — and every other type it returns from {@see Document_Source::get_document_types()} stays
 * hidden, because the framework cannot name a document it was not told about. A carrier with its own documents (a
 * label, a handover act) declares them here; a declaration for a built-in key overrides its default label.
 *
 * Which types are bulk-capable is still decided by {@see Bulk_Document_Source::get_bulk_document_types()}; `bulk_label`
 * only words the entry. Labels are admin strings, supplied by the carrier.
 *
 * @since 2.0.2
 */
interface Document_Type_Declarations {
	/**
	 * The document types this source can name. Called while building the orders page: answer from local data.
	 *
	 * @since 2.0.2
	 * @return array<string,array{label:string,bulk_label?:string,icon?:string}> declarations keyed by the stable type key
	 *         (the key {@see Document_Source::get_document_types()} returns and the documents REST route takes);
	 *         `label` — the single-order button, `bulk_label` — the bulk picker entry (absent => `label`),
	 *         `icon` — a Dashicons slug without the `dashicons-` prefix (absent => the built-in or neutral glyph).
	 */
	public function get_document_type_declarations(): array;
}
