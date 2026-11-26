<?php
/**
 * A marketplace that sells a size inside a card.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Flows\Match_Key;
use Woodev\Framework\Marketplace\Flows\Sellable_Units;
use Woodev\Framework\Marketplace\Flows\Shown_Article;
use Woodev\Framework\Marketplace\Links\Unit_Key;

/**
 * The unit key is the card's article and the size; the current article is `unit.article`, which may differ after a rename. The keys, in order: any barcode; the SKU equal to article + separator + size;
 * the SKU equal to the article, for a card with one size. The import writes the article for a card with one size and
 * `article-size` for a size of several.
 */
final class Fake_Sellable_Units implements Sellable_Units {
	private const SEPARATORS = [ '-', '_', '/', ' ', '' ];

	/**
	 * Builds a unit card.
	 *
	 * @param string   $article  Article of the card.
	 * @param string   $size     Size, '' for an unnamed one.
	 * @param string[] $barcodes Barcodes of the size.
	 * @param int      $sizes    How many sizes the card has.
	 * @param string   $card     The card's identifier; the article when ''.
	 * @return array<string,mixed>
	 */
	public static function card( string $article, string $size, array $barcodes = [], int $sizes = 1, string $card = '' ): array {
		return [
			'offer_id'   => Unit_Key::encode( $article, $size ),
			'remote_ids' => [ 'card_ref' => abs( crc32( Unit_Key::encode( $article, $size ) ) ) % 100000 + 1 ],
			'barcodes'   => $barcodes,
			'unit'       => [
				'card'    => '' === $card ? $article : $card,
				'article' => $article,
				'count'   => $sizes,
			],
		];
	}

	/**
	 * Match keys of a card.
	 *
	 * @param array<string,mixed> $card Card.
	 * @return Match_Key[]
	 */
	public function match_keys( array $card ): array {
		$pair  = $this->pair( (string) $card['offer_id'], $card );
		$joint = [];

		foreach ( self::SEPARATORS as $separator ) {
			$joint[] = '' === $pair['unit'] ? '' : $pair['article'] . $separator . $pair['unit'];
		}

		return [
			Match_Key::barcode( (array) ( $card['barcodes'] ?? [] ) ),
			Match_Key::sku( $joint ),
			Match_Key::sku( 1 === (int) ( $card['unit']['count'] ?? 0 ) ? [ $pair['article'] ] : [] ),
		];
	}

	/**
	 * What «Артикул» shows.
	 *
	 * @param string $offer_id Unit key.
	 * @return Shown_Article
	 */
	public function shown_article( string $offer_id, array $card = [] ): Shown_Article {
		if ( null === Unit_Key::decode( $offer_id ) ) {
			return new Shown_Article( $offer_id );
		}

		$pair = $this->pair( $offer_id, $card );

		return new Shown_Article( $pair['article'], $pair['unit'] );
	}

	/**
	 * The SKU the import writes.
	 *
	 * @param array<string,mixed> $card Card.
	 * @return string
	 */
	public function import_sku( array $card ): string {
		$pair = $this->pair( (string) $card['offer_id'], $card );

		return (int) ( $card['unit']['count'] ?? 0 ) > 1 ? $pair['article'] . '-' . $pair['unit'] : $pair['article'];
	}

	/**
	 * The article as it is now and the size of a unit.
	 *
	 * @param string              $offer_id Unit key.
	 * @param array<string,mixed> $card     Card, [] for none.
	 * @return array{article:string,unit:string}
	 */
	private function pair( string $offer_id, array $card ): array {
		$pair = Unit_Key::decode( $offer_id );

		return [
			'article' => (string) ( $card['unit']['article'] ?? $pair['article'] ),
			'unit'    => $pair['unit'],
		];
	}
}
