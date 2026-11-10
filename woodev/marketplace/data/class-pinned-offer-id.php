<?php
/**
 * The article a merchant chose for a product before its first export.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Data;

use Woodev\Framework\Marketplace\Catalog\Article_Policy;
use Woodev\Framework\Marketplace\Catalog\Offer_Id;

defined( 'ABSPATH' ) || exit;

/**
 * The article (`offer_id`) is editable **only while the product is `unlinked`**; it follows the WooCommerce SKU until the first
 * request carries it, then it is frozen. A typed article is therefore a choice made *before* the freeze, and it must not be
 * confused with the article a link row merely remembers from an earlier computation (that one follows the SKU on purpose).
 *
 * The choice is kept on the product itself, one value per account (`_woodev_{marketplace id}_offer_id_{account id}`), and is read
 * by the export context exactly where the frozen article is read: a frozen article wins, then a request that already carried one,
 * then this choice, then the SKU. It is never written to the link row; the export writes the final article there, and from that
 * moment the choice is ignored.
 *
 * What a valid article is comes from the adapter's {@see Article_Policy}.
 *
 * @since 2.0.29
 */
final class Pinned_Offer_Id {
	/**
	 * Marketplace id.
	 *
	 * @var string
	 */
	private $marketplace_id;

	/**
	 * What the adapter accepts as an article.
	 *
	 * @var Offer_Id
	 */
	private $offer_id;

	/**
	 * Creates the accessor.
	 *
	 * @since 2.0.29
	 *
	 * @param string         $marketplace_id The adapter's id.
	 * @param Article_Policy $policy         The adapter's article policy.
	 */
	public function __construct( string $marketplace_id, Article_Policy $policy ) {
		$this->marketplace_id = $marketplace_id;
		$this->offer_id       = new Offer_Id( $policy );
	}

	/**
	 * Prefix of the meta keys of a marketplace; the account id follows. The uninstaller deletes by it.
	 *
	 * @since 2.0.29
	 *
	 * @param string $marketplace_id The adapter's id.
	 * @return string e.g. `_woodev_ozon_offer_id_`.
	 */
	public static function meta_prefix( string $marketplace_id ): string {
		return '_woodev_' . $marketplace_id . '_offer_id_';
	}

	/**
	 * Names the meta key of an account.
	 *
	 * @since 2.0.29
	 *
	 * @param int $account_id Account id.
	 * @return string
	 */
	public function meta_key( int $account_id ): string {
		return self::meta_prefix( $this->marketplace_id ) . $account_id;
	}

	/**
	 * Reads the chosen article.
	 *
	 * @since 2.0.29
	 *
	 * @param \WC_Product $product    Product or variation.
	 * @param int         $account_id Account id.
	 * @return string|null Null when the merchant chose none.
	 */
	public function of( \WC_Product $product, int $account_id ): ?string {
		$value = $product->get_meta( $this->meta_key( $account_id ), true );

		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Stores or clears the chosen article.
	 *
	 * @since 2.0.29
	 *
	 * @param \WC_Product $product    Product or variation.
	 * @param int         $account_id Account id.
	 * @param string|null $offer_id   Article that passed {@see self::problem()}; null or '' clears the choice.
	 * @return void
	 */
	public function store( \WC_Product $product, int $account_id, ?string $offer_id ): void {
		if ( null === $offer_id || '' === $offer_id ) {
			$product->delete_meta_data( $this->meta_key( $account_id ) );
		} else {
			$product->update_meta_data( $this->meta_key( $account_id ), $offer_id );
		}

		$product->save_meta_data();
	}

	/**
	 * Tells what is wrong with a typed article.
	 *
	 * @since 2.0.29
	 *
	 * @param string $offer_id Typed article, already trimmed.
	 * @return string|null Sentence for the merchant, null when the article is fine.
	 */
	public function problem( string $offer_id ): ?string {
		if ( '' === $offer_id || $this->offer_id->normalise( $offer_id ) === $offer_id ) {
			return null;
		}

		return sprintf(
			/* translators: %d: longest length of an article. */
			__( 'Артикул на маркетплейсе — не длиннее %d символов и без пробелов. Замените пробелы на «-» или сократите артикул.', 'woodev-plugin-framework' ),
			$this->offer_id->max_length()
		);
	}
}
