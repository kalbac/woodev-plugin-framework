<?php
/**
 * The marketplace's own words for its posting statuses.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Shared;

defined( 'ABSPATH' ) || exit;

/**
 * An optional capability of the adapter: a marketplace that already names its posting statuses for merchants (Ozon's
 * `Ozon_Posting_Statuses`) hands those names to the order and product screens through it, so the framework keeps no second list
 * for that marketplace. Without it, {@see Posting_Status_Text} names the codes the marketplaces share.
 *
 * @since 2.1.13
 */
interface Posting_Status_Names {
	/**
	 * Names a posting status.
	 *
	 * @since 2.1.13
	 *
	 * @param string $status The marketplace's status, as stored.
	 * @return string The Russian name; an empty string for a status the marketplace does not name (never the code itself).
	 */
	public function posting_status_name( string $status ): string;
}
