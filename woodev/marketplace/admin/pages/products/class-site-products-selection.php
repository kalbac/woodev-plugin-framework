<?php
/**
 * Turns the selected row ids of the «Товары сайта» table into the products an action reaches.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

defined( 'ABSPATH' ) || exit;

/**
 * A selection can name a variable product, some of its variations, or both. The engine's rule (B4): selecting a parent selects its
 * eligible children, and the server normalises the selection to **distinct domain targets**, so a variation is never processed
 * twice. A target is an offer: a simple product, or one variation.
 *
 * @since 2.1.4
 */
final class Site_Products_Selection {
	/**
	 * Most offers one action reaches. A selection that expands past it is refused whole, never cut: validation builds the whole
	 * card of every offer.
	 */
	public const MAX_OFFERS = 500;

	/**
	 * @var Site_Products_Reader
	 */
	private $reader;

	/**
	 * Creates the resolver.
	 *
	 * @since 2.1.4
	 *
	 * @param Site_Products_Reader $reader Reader.
	 */
	public function __construct( Site_Products_Reader $reader ) {
		$this->reader = $reader;
	}

	/**
	 * Resolves row ids to offers.
	 *
	 * @since 2.1.4
	 *
	 * @param int      $account_id Account the table is open for.
	 * @param string[] $row_ids    Row ids.
	 * @return array{offers:int[],editable:array<int,int>}|null Null when any id is not a row of this account's table.
	 *         `editable` maps each offer to the product whose edit right decides it: the parent of a variation, the product itself otherwise.
	 */
	public function resolve( int $account_id, array $row_ids ): ?array {
		$product_ids = [];

		foreach ( $row_ids as $row_id ) {
			$product_id = Site_Products_Ids::product_id( (string) $row_id, $account_id );

			if ( null === $product_id ) {
				return null;
			}

			$product_ids[] = $product_id;
		}

		$kinds = $this->reader->kinds( $product_ids );

		if ( count( array_unique( $product_ids ) ) !== count( $kinds ) ) {
			return null;
		}

		$variations = $this->reader->variation_ids(
			array_keys(
				array_filter(
					$kinds,
					static function ( array $kind ): bool {
						return ! $kind['is_variation'];
					}
				)
			)
		);
		$offers     = [];
		$editable   = [];

		foreach ( $kinds as $product_id => $kind ) {
			$members = $kind['is_variation'] ? [ $product_id ] : ( $variations[ $product_id ] ?? [ $product_id ] );

			foreach ( $members as $member ) {
				$offers[ $member ]   = $member;
				$editable[ $member ] = $kind['is_variation'] ? $kind['parent'] : $product_id;
			}
		}

		return [
			'offers'   => array_values( $offers ),
			'editable' => $editable,
		];
	}

	/**
	 * Tells whether a row is a variable product with variations to read.
	 *
	 * @since 2.1.4
	 *
	 * @param int    $account_id Account.
	 * @param string $row_id  Row id.
	 * @return int|null The product id when the row can expand; null otherwise.
	 */
	public function expandable( int $account_id, string $row_id ): ?int {
		$product_id = Site_Products_Ids::product_id( $row_id, $account_id );
		$kinds      = null === $product_id ? [] : $this->reader->kinds( [ $product_id ] );

		if ( ! isset( $kinds[ $product_id ] ) || $kinds[ $product_id ]['is_variation'] ) {
			return null;
		}

		return [] === $this->reader->variation_ids( [ $product_id ] ) ? null : $product_id;
	}
}
