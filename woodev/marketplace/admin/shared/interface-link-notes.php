<?php
/**
 * What a plugin has stored about a card that the merchant should see at the product.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Shared;

defined( 'ABSPATH' ) || exit;

/**
 * An **optional** part of a marketplace adapter, for a marketplace that may accept a card and still have something to say about it:
 * a verdict of its checks, an entry of its error list. The product tab, «Товары сайта» and the badge of a card in «Карточки на
 * маркетплейсе» show the note. An adapter that does not implement it shows no notes.
 *
 * The screens ask while they are drawn, for all the rows they draw at once, so the method **reads what the plugin has stored and
 * nothing else: no request to the marketplace**. The plugin writes its notes from its jobs.
 *
 * @since 2.1.24
 */
interface Link_Notes {
	/**
	 * Returns the notes of some links of one account.
	 *
	 * @since 2.1.24
	 *
	 * @param int   $account_id Account id.
	 * @param int[] $link_ids   Offer rows of the account, in any state; at least one.
	 * @return array<int,Link_Note> By link id; a link with nothing to say is absent.
	 */
	public function link_notes( int $account_id, array $link_ids ): array;
}
