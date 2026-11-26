<?php
/**
 * Pairing unit cards with shop products by the adapter's match keys.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Flows\Unit_Matcher;
use Woodev\Framework\Marketplace\Links\Unit_Key;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Sellable_Units;

/**
 * The first rank that finds anything decides: one product is a pair, several are a conflict, never a guess.
 *
 * @covers \Woodev\Framework\Marketplace\Flows\Unit_Matcher
 * @covers \Woodev\Framework\Marketplace\Flows\Match_Key
 */
final class UnitMatcherTest extends TestCase {
	/**
	 * Builds a candidate product.
	 *
	 * @param int      $id        Product id.
	 * @param string   $sku       SKU.
	 * @param string[] $barcodes  GTIN and custom barcode field values.
	 * @param string   $importing Unit it is being imported for.
	 * @return array<string,mixed>
	 */
	private function product( int $id, string $sku, array $barcodes = [], string $importing = '' ): array {
		return [
			'id'        => $id,
			'parent_id' => 0,
			'sku'       => $sku,
			'barcodes'  => $barcodes,
			'importing' => $importing,
		];
	}

	/**
	 * Runs the matcher.
	 *
	 * @param array<int,array<string,mixed>> $cards    Cards.
	 * @param array<int,array<string,mixed>> $products Products.
	 * @param array<int,array<string,mixed>> $linked   Identity map.
	 * @return array<string,mixed>
	 */
	private function match( array $cards, array $products, array $linked = [] ): array {
		return Unit_Matcher::match( $cards, $products, $linked, new Fake_Sellable_Units() );
	}

	/**
	 * Reduces pairs to `unit key => product id`.
	 *
	 * @param array<string,mixed> $result Result.
	 * @return array<string,int>
	 */
	private function pairs( array $result ): array {
		$pairs = [];

		foreach ( $result['exact'] as $pair ) {
			$pairs[ $pair['card']['offer_id'] ] = $pair['product']['id'];
		}

		return $pairs;
	}

	/**
	 * Any barcode of the size, compared byte for byte with the GTIN field, the barcode field or the SKU.
	 *
	 * @return void
	 */
	public function test_a_barcode_matches_the_gtin_the_field_or_the_sku_as_a_raw_string(): void {
		$result = $this->match(
			[
				Fake_Sellable_Units::card( 'a', 'S', [ '2000000000001', '12345Ejf5' ], 3 ),
				Fake_Sellable_Units::card( 'a', 'M', [ '2000000000002' ], 3 ),
				Fake_Sellable_Units::card( 'a', 'L', [ '0012' ], 3 ),
			],
			[
				$this->product( 1, 'one', [ '12345Ejf5' ] ),
				$this->product( 2, '2000000000002' ),
				$this->product( 3, 'three', [ '12' ] ),
				$this->product( 4, 'four', [ '12345EJF5' ] ),
			]
		);

		$this->assertSame(
			[
				'a|S' => 1,
				'a|M' => 2,
			],
			$this->pairs( $result )
		);
		$this->assertSame( [], $result['conflicts'] );
	}

	/**
	 * Article, a separator and the size; the plain article only for a card with one size.
	 *
	 * @return void
	 */
	public function test_the_article_with_the_size_and_the_plain_article(): void {
		$result = $this->match(
			[
				Fake_Sellable_Units::card( 'dress', '42', [], 2 ),
				Fake_Sellable_Units::card( 'dress', '44', [], 2 ),
				Fake_Sellable_Units::card( 'mug', '', [], 1 ),
				Fake_Sellable_Units::card( 'shirt', 'M', [], 2 ),
			],
			[
				$this->product( 1, 'dress_42' ),
				$this->product( 2, 'dress 44' ),
				$this->product( 3, 'mug' ),
				$this->product( 4, 'shirt' ),
			]
		);

		$this->assertSame(
			[
				'dress|42' => 1,
				'dress|44' => 2,
				'mug|'     => 3,
			],
			$this->pairs( $result )
		);
	}

	/**
	 * Several products at the deciding rank are a conflict, and a weaker key is not tried instead.
	 *
	 * @return void
	 */
	public function test_an_ambiguous_key_is_reported_and_never_guessed(): void {
		$result = $this->match(
			[ Fake_Sellable_Units::card( 'dress', '42', [ '777' ], 1 ) ],
			[
				$this->product( 1, '777' ),
				$this->product( 2, 'x', [ '777' ] ),
				$this->product( 3, 'dress-42' ),
			]
		);

		$this->assertSame( [], $result['exact'] );
		$this->assertCount( 1, $result['conflicts'] );
		$this->assertSame( Unit_Matcher::AMBIGUOUS, $result['conflicts'][0]['reason'] );
		$this->assertSame( [ 1, 2 ], $result['conflicts'][0]['product_ids'] );
	}

	/**
	 * The collision pair of the import SKU: both cards name one product at the same strength, so neither gets it.
	 *
	 * @return void
	 */
	public function test_one_product_claimed_by_two_cards_goes_to_neither(): void {
		$result = $this->match(
			[
				Fake_Sellable_Units::card( 'coat', 'M-L', [], 2 ),
				Fake_Sellable_Units::card( 'coat-M', 'L', [], 2 ),
			],
			[ $this->product( 1, 'coat-M-L' ) ]
		);

		$this->assertSame( [], $result['exact'] );
		$this->assertSame( [ Unit_Matcher::CONTESTED, Unit_Matcher::CONTESTED ], array_column( $result['conflicts'], 'reason' ) );
	}

	/**
	 * A stronger key wins the product; the card with the weaker claim is told.
	 *
	 * @return void
	 */
	public function test_a_stronger_key_takes_the_product_before_a_weaker_one(): void {
		$result = $this->match(
			[
				Fake_Sellable_Units::card( 'coat-M', '', [], 1 ),
				Fake_Sellable_Units::card( 'coat', 'M', [], 1 ),
			],
			[ $this->product( 1, 'coat-M' ) ]
		);

		$this->assertSame( [ 'coat|M' => 1 ], $this->pairs( $result ) );
		$this->assertSame( 'coat-M|', $result['conflicts'][0]['card']['offer_id'] );
		$this->assertSame( Unit_Matcher::CONTESTED, $result['conflicts'][0]['reason'] );
	}

	/**
	 * A linked card, a linked product and a product being imported for another unit are left alone.
	 *
	 * @return void
	 */
	public function test_linked_and_half_imported_rows_are_no_candidates(): void {
		$result = $this->match(
			[
				Fake_Sellable_Units::card( 'a', '', [], 1 ),
				Fake_Sellable_Units::card( 'b', '', [], 1 ),
				Fake_Sellable_Units::card( 'c', '', [], 1 ),
				Fake_Sellable_Units::card( 'd', '', [], 1 ),
			],
			[
				$this->product( 1, 'a' ),
				$this->product( 2, 'b' ),
				$this->product( 3, 'c', [], Unit_Key::encode( 'other' ) ),
				$this->product( 4, 'd', [], 'd|' ),
			],
			[
				1 => [
					'offer_id'       => 'a|',
					'has_remote_ids' => true,
					'card_state'     => 'created',
				],
				2 => [
					'offer_id'       => 'something|',
					'has_remote_ids' => false,
					'card_state'     => 'unlinked',
				],
			]
		);

		$this->assertSame( [ 'd|' => 4 ], $this->pairs( $result ) );
		$this->assertSame( 1, $result['already_linked_cards'] );
		$this->assertSame( 2, $result['already_linked_products'] );
		$this->assertSame( [], $result['conflicts'] );
	}

	/**
	 * One card on its own: the products of its first rank that finds any.
	 *
	 * @return void
	 */
	public function test_the_products_of_one_card(): void {
		$card     = Fake_Sellable_Units::card( 'dress', '42', [ '777' ], 1 );
		$products = [ $this->product( 3, 'dress-42' ), $this->product( 5, 'dress' ) ];

		$this->assertSame( [ 3 ], Unit_Matcher::products_of( $card, $products, new Fake_Sellable_Units() ) );
		$this->assertSame( [ 9 ], Unit_Matcher::products_of( $card, array_merge( $products, [ $this->product( 9, '777' ) ] ), new Fake_Sellable_Units() ) );
		$this->assertSame( [], Unit_Matcher::products_of( $card, [], new Fake_Sellable_Units() ) );
	}
}
