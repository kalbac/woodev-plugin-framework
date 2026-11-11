<?php
/**
 * Pairs the cards of a marketplace cabinet with the products of the shop by article.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Catalog\Article_Policy;
use Woodev\Framework\Marketplace\Catalog\Offer_Id_Likeness;

defined( 'ABSPATH' ) || exit;

/**
 * The marketplace's `offer_id` is matched to the WooCommerce SKU after the same normalisation the export applies
 * ({@see Article_Policy::normalise()}), and **exactly**. A pair that differs only in case or in look-alike letters is a *near match*:
 * it is reported, never linked on its own.
 *
 * Pure: it reads three lists and returns lists, so it is tested without a database.
 *
 * - A card whose `offer_id` already belongs to a link row, and a product that already has an identity (an article or a marketplace
 *   identifier), are left out: linking never rewrites an existing link.
 * - If two products normalise to the same article, no card is paired with either of them: guessing which one is the card's would
 *   link the wrong product.
 * - A card that was paired exactly is not offered as a near match of another product.
 *
 * @since 2.0.30
 */
final class Card_Matcher {
	/**
	 * Pairs cards with products.
	 *
	 * @since 2.0.30
	 *
	 * @param array<int,array<string,mixed>>                                           $cards    Cards of the cabinet; each carries at least `offer_id`, every other key of a {@see Link_Resolver} card is passed through.
	 * @param array<int,array{id:int,parent_id:int,sku:string}>                        $products Shop products and variations that may carry a card (never a variable parent).
	 * @param array<int,array{offer_id:?string,has_remote_ids:bool,card_state:string}> $linked  Existing offer rows by WooCommerce product id (`Wpdb_Link_Store::identity_map()`).
	 * @param Article_Policy                                                           $policy   The marketplace's article rules, the same the export uses.
	 * @return array{exact:array<int,array{product:array<string,mixed>,card:array<string,mixed>}>,near:array<int,array{product:array<string,mixed>,card:array<string,mixed>,relation:string}>,already_linked_cards:int,already_linked_products:int,ambiguous:int,no_article:int}
	 */
	public static function match( array $cards, array $products, array $linked, Article_Policy $policy ): array {
		$result = [
			'exact'                   => [],
			'near'                    => [],
			'already_linked_cards'    => 0,
			'already_linked_products' => 0,
			'ambiguous'               => 0,
			'no_article'              => 0,
		];

		$taken_offers = [];
		foreach ( $linked as $identity ) {
			if ( null !== $identity['offer_id'] && '' !== $identity['offer_id'] ) {
				$taken_offers[ $identity['offer_id'] ] = true;
			}
		}

		$free_cards = [];
		foreach ( $cards as $card ) {
			if ( isset( $taken_offers[ (string) $card['offer_id'] ] ) ) {
				++$result['already_linked_cards'];
				continue;
			}

			$free_cards[ (string) $card['offer_id'] ] = $card;
		}

		$by_article = [];
		foreach ( $products as $product ) {
			if ( self::has_identity( $linked[ (int) $product['id'] ] ?? null ) ) {
				++$result['already_linked_products'];
				continue;
			}

			$article = $policy->normalise( (string) $product['sku'] );

			if ( '' === $article ) {
				++$result['no_article'];
				continue;
			}

			$by_article[ $article ][] = $product;
		}

		list( $exact, $left_products, $left_cards, $ambiguous ) = self::pair_exactly( $free_cards, $by_article );

		$result['exact']     = $exact;
		$result['ambiguous'] = $ambiguous;
		$result['near']      = self::pair_nearly( $left_cards, $left_products );

		return $result;
	}

	/**
	 * Tells whether an existing row already says which card the product is.
	 *
	 * @param array{offer_id:?string,has_remote_ids:bool,card_state:string}|null $identity The product's row, if any.
	 * @return bool
	 */
	private static function has_identity( ?array $identity ): bool {
		if ( null === $identity ) {
			return false;
		}

		return ( null !== $identity['offer_id'] && '' !== $identity['offer_id'] ) || $identity['has_remote_ids'];
	}

	/**
	 * Pairs a card with the one product whose article equals its `offer_id`.
	 *
	 * @param array<string,array<string,mixed>>            $cards      Free cards by `offer_id`.
	 * @param array<string,array<int,array<string,mixed>>> $by_article Free products by normalised article.
	 * @return array{0:array<int,array{product:array<string,mixed>,card:array<string,mixed>}>,1:array<string,array<int,array<string,mixed>>>,2:array<string,array<string,mixed>>,3:int} Pairs, products left, cards left, products given up as ambiguous.
	 */
	private static function pair_exactly( array $cards, array $by_article ): array {
		$pairs     = [];
		$ambiguous = 0;

		foreach ( $cards as $offer_id => $card ) {
			if ( ! isset( $by_article[ $offer_id ] ) ) {
				continue;
			}

			if ( 1 === count( $by_article[ $offer_id ] ) ) {
				$pairs[] = [
					'product' => $by_article[ $offer_id ][0],
					'card'    => $card,
				];
			} else {
				++$ambiguous;
			}

			// An ambiguous card is not a candidate of anyone else either.
			unset( $cards[ $offer_id ], $by_article[ $offer_id ] );
		}

		return [ $pairs, $by_article, $cards, $ambiguous ];
	}

	/**
	 * Finds the pairs that differ in case or in look-alike letters.
	 *
	 * @param array<string,array<string,mixed>>            $cards      Cards no product matched exactly, by `offer_id`.
	 * @param array<string,array<int,array<string,mixed>>> $by_article Products no card matched exactly, by article.
	 * @return array<int,array{product:array<string,mixed>,card:array<string,mixed>,relation:string}>
	 */
	private static function pair_nearly( array $cards, array $by_article ): array {
		$cards_by_fold = [];
		foreach ( $cards as $offer_id => $card ) {
			$cards_by_fold[ Offer_Id_Likeness::fold( (string) $offer_id ) ][] = $card;
		}

		$pairs = [];
		foreach ( $by_article as $article => $products ) {
			foreach ( $cards_by_fold[ Offer_Id_Likeness::fold( (string) $article ) ] ?? [] as $card ) {
				$relation = Offer_Id_Likeness::relation( (string) $article, (string) $card['offer_id'] );

				if ( Offer_Id_Likeness::DIFFERENT === $relation || Offer_Id_Likeness::SAME === $relation ) {
					continue;
				}

				foreach ( $products as $product ) {
					$pairs[] = [
						'product'  => $product,
						'card'     => $card,
						'relation' => $relation,
					];
				}
			}
		}

		return $pairs;
	}
}
