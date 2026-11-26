<?php
/**
 * Which shop product a unit card is.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Data\Wpdb_Card_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;

defined( 'ABSPATH' ) || exit;

/**
 * The one decision the import job and the preview of «Импортировать» share for a marketplace that sells units
 * ({@see Sellable_Units}). A unit's product cannot be found by its SKU — the unit key is never one, and a candidate SKU that is
 * taken leaves the product without any — so:
 *
 * - while its import is not finished the product carries {@see self::pending_key()} (`{account id}:{unit key}`), written with the
 *   importer's first save and removed once the card is linked;
 * - a product the importer made keeps {@see self::made_for_key()} (the same value) for good, so the card whose link was removed
 *   is imported into the product it already has, SKU or no SKU, and not into a second one;
 * - otherwise the adapter's match keys find it among the products that have no link ({@see Unit_Matcher::products_of()}).
 *
 * It also keeps what the merchant is told when the import could not give the product its SKU ({@see self::sku_conflict_text()}).
 *
 * @since 2.1.23
 */
final class Unit_Products {
	/**
	 * The adapter's unit rules.
	 *
	 * @var Sellable_Units
	 */
	private $units;

	/**
	 * Shop products.
	 *
	 * @var Unit_Product_Source
	 */
	private $source;

	/**
	 * Links.
	 *
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * How an article is shown.
	 *
	 * @var Article_Display
	 */
	private $articles;

	/**
	 * Creates the lookup.
	 *
	 * @since 2.1.23
	 *
	 * @param Sellable_Units       $units  The adapter's unit rules.
	 * @param Unit_Product_Source  $source Shop products.
	 * @param Wpdb_Link_Store      $links  Links.
	 * @param Wpdb_Card_Store|null $cards  The stored cards list, for the current article of a card named in a message.
	 */
	public function __construct( Sellable_Units $units, Unit_Product_Source $source, Wpdb_Link_Store $links, ?Wpdb_Card_Store $cards = null ) {
		$this->units    = $units;
		$this->source   = $source;
		$this->links    = $links;
		$this->articles = new Article_Display( $units, $cards );
	}

	/**
	 * Returns what a message shows for the article of a unit.
	 *
	 * @since 2.1.23
	 *
	 * @param int    $account_id Account.
	 * @param string $offer_id   Unit key.
	 * @return string
	 */
	public function shown( int $account_id, string $offer_id ): string {
		return $this->articles->of_link( $account_id, $offer_id );
	}

	/**
	 * Returns the meta key of a product whose import for a unit has not ended with a link.
	 *
	 * @since 2.1.23
	 *
	 * @param string $marketplace_id The adapter's id.
	 * @return string
	 */
	public static function pending_key( string $marketplace_id ): string {
		return '_woodev_' . $marketplace_id . '_import_unit';
	}

	/**
	 * Returns the meta key that names, for good, the unit the importer made a product for.
	 *
	 * @since 2.1.23
	 *
	 * @param string $marketplace_id The adapter's id.
	 * @return string
	 */
	public static function made_for_key( string $marketplace_id ): string {
		return '_woodev_' . $marketplace_id . '_unit';
	}

	/**
	 * Returns the value of {@see self::pending_key()} and {@see self::made_for_key()} for a unit of an account.
	 *
	 * @since 2.1.23
	 *
	 * @param int    $account_id Account id.
	 * @param string $offer_id   Unit key.
	 * @return string
	 */
	public static function mark_value( int $account_id, string $offer_id ): string {
		return $account_id . ':' . $offer_id;
	}

	/**
	 * Returns the meta key that keeps the SKU another product held when the import created this one.
	 *
	 * @since 2.1.23
	 *
	 * @param string $marketplace_id The adapter's id.
	 * @return string
	 */
	public static function sku_conflict_key( string $marketplace_id ): string {
		return '_woodev_' . $marketplace_id . '_sku_conflict';
	}

	/**
	 * Says why a product the import created has no SKU, for as long as it has none and another product holds the one it wanted.
	 *
	 * @since 2.1.23
	 *
	 * @param string      $marketplace_id The adapter's id.
	 * @param \WC_Product $product        Product or variation.
	 * @return string '' when there is nothing to say.
	 */
	public static function sku_conflict_text( string $marketplace_id, \WC_Product $product ): string {
		$wanted = (string) $product->get_meta( self::sku_conflict_key( $marketplace_id ) );

		if ( '' === $wanted || '' !== (string) $product->get_sku( 'edit' ) ) {
			return '';
		}

		$holder = (int) wc_get_product_id_by_sku( $wanted );
		$other  = $holder > 0 && $holder !== (int) $product->get_id() ? wc_get_product( $holder ) : null;

		if ( ! $other ) {
			return '';
		}

		/* translators: 1: article (SKU), 2: name of the product that holds it, 3: its number. */
		return sprintf( __( 'Артикул «%1$s» уже занят товаром «%2$s» (№%3$d), поэтому товар создан без артикула. Задайте ему свой артикул.', 'woodev-plugin-framework' ), $wanted, $other->get_name(), $holder );
	}

	/**
	 * Returns the adapter's unit rules.
	 *
	 * @since 2.1.23
	 *
	 * @return Sellable_Units
	 */
	public function units(): Sellable_Units {
		return $this->units;
	}

	/**
	 * Finds the products of unit cards.
	 *
	 * @since 2.1.23
	 *
	 * @param int                            $account_id Account.
	 * @param array<int,array<string,mixed>> $cards      Unit cards.
	 * @return array<string,array{pending:int,matches:int[]}> By unit key: `pending` is the product an unfinished import of the
	 *                                                       card made (0 for none); otherwise `matches` are, among the products with
	 *                                                       no link, those the importer made for the card, else those the match keys
	 *                                                       find — one is the card's, several are a conflict.
	 */
	public function find( int $account_id, array $cards ): array {
		$values = [];
		$offers = [];

		foreach ( $cards as $card ) {
			$offers[] = (string) $card['offer_id'];

			foreach ( $this->units->match_keys( $card ) as $key ) {
				$values = array_merge( $values, $key->values() );
			}
		}

		$products = [] === $offers ? [] : $this->source->matching( $account_id, $values, $offers );
		$linked   = [] === $products ? [] : $this->links->identity_map( $account_id );
		$free     = array_values(
			array_filter(
				$products,
				static function ( array $product ) use ( $linked ): bool {
					$identity = $linked[ $product['id'] ] ?? null;

					return null === $identity || ( empty( $identity['offer_id'] ) && ! $identity['has_remote_ids'] );
				}
			)
		);
		$found    = [];

		foreach ( $cards as $card ) {
			$offer_id = (string) $card['offer_id'];
			$pending  = self::marked( $free, 'importing', $offer_id );
			$matches  = [];

			if ( [] === $pending ) {
				$made    = self::marked( $free, 'made_for', $offer_id );
				$matches = [] === $made ? Unit_Matcher::products_of( $card, $free, $this->units ) : $made;
			}

			$found[ $offer_id ] = [
				'pending' => $pending[0] ?? 0,
				'matches' => $matches,
			];
		}

		return $found;
	}

	/**
	 * Returns the products whose mark of one kind names a unit.
	 *
	 * @param array<int,array<string,mixed>> $products Products of the source.
	 * @param string                         $mark     `importing` or `made_for`.
	 * @param string                         $offer_id Unit key.
	 * @return int[] Product ids.
	 */
	private static function marked( array $products, string $mark, string $offer_id ): array {
		$ids = [];

		foreach ( $products as $product ) {
			if ( $product[ $mark ] === $offer_id ) {
				$ids[] = $product['id'];
			}
		}

		return $ids;
	}
}
