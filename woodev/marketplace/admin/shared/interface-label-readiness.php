<?php
/**
 * The marketplace's say on whether a posting can have a label made yet.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Shared;

defined( 'ABSPATH' ) || exit;

/**
 * An optional capability of the adapter (additive, like {@see Posting_Status_Names}): a marketplace that makes labels only for postings
 * in certain statuses says so here. A posting it is not ready for gets no label button and no label request ({@see Label_Availability}).
 * An adapter without it is asked nothing: its button is on, and the marketplace's own refusal is shown after the click.
 *
 * It judges from the status the postings registry stores, so it never calls the marketplace.
 *
 * @since 2.1.13
 */
interface Label_Readiness {
	/**
	 * Tells why a posting in this status cannot have a label made yet.
	 *
	 * @since 2.1.13
	 *
	 * @param string $status The marketplace's status of the posting, as stored.
	 * @return string|null Null when the label can be asked for; otherwise the sentence for the merchant, in Russian.
	 */
	public function label_readiness( string $status ): ?string;
}
