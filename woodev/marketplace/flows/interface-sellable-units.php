<?php
/**
 * A marketplace whose sellable unit is a part of a card.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

defined( 'ABSPATH' ) || exit;

/**
 * An **optional** part of a marketplace adapter, for a marketplace that sells a size inside a card: one link is one unit, its
 * `offer_id` is a unit key ({@see \Woodev\Framework\Marketplace\Links\Unit_Key}) and neither a shop SKU nor a text for the
 * merchant. An adapter that does not implement it keeps the plain rules: the article is the SKU, matched after
 * {@see \Woodev\Framework\Marketplace\Catalog\Article_Policy::normalise()}, shown as it is and written by the import.
 *
 * A **unit card** is a card of {@see Link_Resolver} (and of {@see Card_Importer::read_cards()}, {@see Card_List_Source}) that also
 * carries, where the marketplace has them:
 *
 * - `barcodes` (string[]): every barcode of the unit, byte for byte, in the marketplace's order. The link stores the whole list;
 *   the import writes the first one that is a GTIN into WooCommerce's GTIN field.
 * - `unit` (array): `card` (string) names the card of the marketplace the unit belongs to — the units of one card are listed under
 *   it; '' stands for a unit that is listed alone. `article` (string) is the card's seller article **as it is now**, which
 *   «Поиск по артикулу» looks in. Any other scalar the adapter wants back in {@see self::match_keys()} or
 *   {@see self::shown_article()} (how many units the card has, say) may be added: the framework keeps the whole array with the
 *   stored card list.
 *
 * **A renamed article.** `offer_id` is the key frozen at linking. A marketplace that lets the article change must keep answering
 * the **stored** key for the card — the adapter finds the link by its remote identifiers
 * ({@see \Woodev\Framework\Marketplace\Data\Wpdb_Link_Store::by_remote_id()}) before it builds a key from the current
 * article — and show the current article through `unit.article`.
 *
 * Every card handed to the methods below holds at least `offer_id`, `remote_ids`, `barcodes` and `unit` as the adapter gave them.
 * The methods are asked while tables are drawn, so they read the card and nothing else: no request, no query.
 *
 * @since 2.1.23
 */
interface Sellable_Units {
	/**
	 * Names how a unit card may be found among the shop's products, the most trusted key first.
	 *
	 * Every card gives the same keys in the same order, so that their ranks compare: a key that does not apply to a card is given
	 * with no values. The first rank at which any product matches decides: one product is the card's, several are a conflict the
	 * merchant is told about, and nothing is guessed.
	 *
	 * @since 2.1.23
	 *
	 * @param array<string,mixed> $card Unit card.
	 * @return Match_Key[]
	 */
	public function match_keys( array $card ): array;

	/**
	 * Says what «Артикул» shows for a unit: the card's article as it is now, which may differ from the one in the key.
	 *
	 * @since 2.1.23
	 *
	 * @param string              $offer_id Unit key.
	 * @param array<string,mixed> $card     The unit card as the stored list holds it; [] when the list does not, and the key is all there is.
	 * @return Shown_Article
	 */
	public function shown_article( string $offer_id, array $card = [] ): Shown_Article;

	/**
	 * Says which SKU the import wants to write into the unit's product. It is a candidate: when another product holds it, the
	 * product is created with an empty SKU and the merchant is told.
	 *
	 * @since 2.1.23
	 *
	 * @param array<string,mixed> $card Unit card.
	 * @return string The SKU, '' for none.
	 */
	public function import_sku( array $card ): string;
}
