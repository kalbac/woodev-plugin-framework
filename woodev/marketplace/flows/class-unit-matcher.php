<?php
/**
 * Pairing unit cards with shop products by the adapter's match keys.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

defined( 'ABSPATH' ) || exit;

/**
 * The counterpart of {@see Card_Matcher} for a marketplace that sells units ({@see Sellable_Units}). The adapter declares ordered
 * match keys; for each card the **first rank at which any product matches** decides:
 *
 * - one product, claimed by no other card → the pair;
 * - several products → a conflict ({@see self::AMBIGUOUS}), and none of them is linked to anything by this run;
 * - one product that another card also claims → a conflict ({@see self::CONTESTED}) for every card that does not own it.
 *
 * Nothing is guessed: a conflict is reported and a lower key is not tried for that card. Values are compared as raw strings.
 *
 * A product that already has an identity (an article or a marketplace identifier), and a product the importer is making for
 * another unit, are never candidates ({@see Unit_Product_Source} leaves out what another account's import is making). A card whose article already belongs to a row is left out.
 *
 * Pure: it reads lists and returns lists.
 *
 * @since 2.1.23
 */
final class Unit_Matcher {
	/**
	 * Several products match the card.
	 */
	public const AMBIGUOUS = 'ambiguous';

	/**
	 * The one matching product is also another card's.
	 */
	public const CONTESTED = 'contested';

	/**
	 * Pairs cards with products.
	 *
	 * @since 2.1.23
	 *
	 * @param array<int,array<string,mixed>>                                                       $cards    Unit cards.
	 * @param array<int,array{id:int,parent_id:int,sku:string,barcodes:string[],importing:string}> $products Candidate products ({@see Unit_Product_Source}); `importing` is the unit key the importer is making the product for, '' otherwise.
	 * @param array<int,array{offer_id:?string,has_remote_ids:bool,card_state:string}>             $linked   Existing offer rows by product id (`Wpdb_Link_Store::identity_map()`).
	 * @param Sellable_Units                                                                       $units    The adapter's unit rules.
	 * @return array{exact:array<int,array{product:array<string,mixed>,card:array<string,mixed>}>,conflicts:array<int,array{card:array<string,mixed>,reason:string,product_ids:int[]}>,already_linked_cards:int,already_linked_products:int}
	 */
	public static function match( array $cards, array $products, array $linked, Sellable_Units $units ): array {
		$result = [
			'exact'                   => [],
			'conflicts'               => [],
			'already_linked_cards'    => 0,
			'already_linked_products' => 0,
		];

		$taken = [];
		foreach ( $linked as $identity ) {
			if ( null !== $identity['offer_id'] && '' !== $identity['offer_id'] ) {
				$taken[ $identity['offer_id'] ] = true;
			}
		}

		$open = [];
		foreach ( $cards as $card ) {
			if ( isset( $taken[ (string) $card['offer_id'] ] ) ) {
				++$result['already_linked_cards'];
				continue;
			}

			$open[ 'card:' . $card['offer_id'] ] = $card;
		}

		$free = [];
		foreach ( $products as $product ) {
			$identity = $linked[ (int) $product['id'] ] ?? null;

			if ( null !== $identity && ( ! empty( $identity['offer_id'] ) || $identity['has_remote_ids'] ) ) {
				++$result['already_linked_products'];
				continue;
			}

			$free[ (int) $product['id'] ] = $product;
		}

		$index = self::index( $free );
		$keys  = [];
		$ranks = 0;

		foreach ( $open as $name => $card ) {
			$keys[ $name ] = array_values( $units->match_keys( $card ) );
			$ranks         = max( $ranks, count( $keys[ $name ] ) );
		}

		$spoken_for = []; // Products an earlier rank paired or named in a conflict.

		for ( $rank = 0; $rank < $ranks; $rank++ ) {
			$claims = [];

			foreach ( $open as $name => $card ) {
				$key   = $keys[ $name ][ $rank ] ?? null;
				$found = null === $key ? [] : self::candidates( $key, $index, (string) $card['offer_id'] );

				if ( [] === $found ) {
					continue;
				}

				if ( count( $found ) > 1 ) {
					$result['conflicts'][] = self::conflict( $card, self::AMBIGUOUS, $found );
					$spoken_for           += array_fill_keys( $found, true );
					unset( $open[ $name ] );
					continue;
				}

				$claims[ $found[0] ][ $name ] = $card;
			}

			foreach ( $claims as $product_id => $claimants ) {
				if ( 1 === count( $claimants ) && ! isset( $spoken_for[ $product_id ] ) ) {
					$result['exact'][] = [
						'product' => $free[ $product_id ],
						'card'    => reset( $claimants ),
					];
				} else {
					foreach ( $claimants as $card ) {
						$result['conflicts'][] = self::conflict( $card, self::CONTESTED, [ $product_id ] );
					}
				}

				$spoken_for[ $product_id ] = true;
				$open                      = array_diff_key( $open, $claimants );
			}
		}

		return $result;
	}

	/**
	 * Returns the products one card matches on its own: those of the first rank at which any matches. The decision of a single
	 * import, where there is no other card to compete with.
	 *
	 * @since 2.1.23
	 *
	 * @param array<string,mixed>                                                                  $card     Unit card.
	 * @param array<int,array{id:int,parent_id:int,sku:string,barcodes:string[],importing:string}> $products Candidate products, none of them linked.
	 * @param Sellable_Units                                                                       $units    The adapter's unit rules.
	 * @return int[] Product ids; several are a conflict.
	 */
	public static function products_of( array $card, array $products, Sellable_Units $units ): array {
		$index = self::index( array_column( $products, null, 'id' ) );

		foreach ( $units->match_keys( $card ) as $key ) {
			$found = self::candidates( $key, $index, (string) $card['offer_id'] );

			if ( [] !== $found ) {
				return $found;
			}
		}

		return [];
	}

	/**
	 * Indexes products by the values each kind of key is compared with.
	 *
	 * @param array<int,array<string,mixed>> $products Products by id.
	 * @return array<string,array<string,array<int,string>>> Kind => value => product id => the unit it is being imported for.
	 */
	private static function index( array $products ): array {
		$index = [
			Match_Key::SKU     => [],
			Match_Key::BARCODE => [],
		];

		foreach ( $products as $id => $product ) {
			$sku       = (string) $product['sku'];
			$importing = (string) ( $product['importing'] ?? '' );

			if ( '' !== $sku ) {
				$index[ Match_Key::SKU ][ $sku ][ $id ] = $importing;
			}

			foreach ( array_merge( [ $sku ], (array) ( $product['barcodes'] ?? [] ) ) as $barcode ) {
				if ( '' !== (string) $barcode ) {
					$index[ Match_Key::BARCODE ][ (string) $barcode ][ $id ] = $importing;
				}
			}
		}

		return $index;
	}

	/**
	 * Returns the products a key finds for a card.
	 *
	 * @param Match_Key                                     $key      Key.
	 * @param array<string,array<string,array<int,string>>> $index    {@see self::index()}.
	 * @param string                                        $offer_id The card's unit key.
	 * @return int[] Distinct product ids, ascending.
	 */
	private static function candidates( Match_Key $key, array $index, string $offer_id ): array {
		$found = [];

		foreach ( $key->values() as $value ) {
			foreach ( $index[ $key->kind() ][ $value ] ?? [] as $id => $importing ) {
				if ( '' === $importing || $importing === $offer_id ) {
					$found[ $id ] = true;
				}
			}
		}

		$ids = array_keys( $found );
		sort( $ids );

		return $ids;
	}

	/**
	 * Builds a conflict.
	 *
	 * @param array<string,mixed> $card        Card.
	 * @param string              $reason      {@see self::AMBIGUOUS} or {@see self::CONTESTED}.
	 * @param int[]               $product_ids Products named by it.
	 * @return array{card:array<string,mixed>,reason:string,product_ids:int[]}
	 */
	private static function conflict( array $card, string $reason, array $product_ids ): array {
		return [
			'card'        => $card,
			'reason'      => $reason,
			'product_ids' => array_values( $product_ids ),
		];
	}
}
