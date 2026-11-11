<?php
/**
 * Pairing the cards of a cabinet with shop products by article.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Catalog\Offer_Id_Likeness;
use Woodev\Framework\Marketplace\Flows\Card_Matcher;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Article_Policy;

/**
 * Exact match after the export's normalisation, near matches only reported, an existing link never rewritten.
 *
 * @covers \Woodev\Framework\Marketplace\Flows\Card_Matcher
 */
final class CardMatcherTest extends TestCase {
	/**
	 * Builds a card.
	 *
	 * @param int    $ref      The marketplace's card reference.
	 * @param string $offer_id Article.
	 * @return array{offer_id:string,remote_ids:array<string,int>,archived:bool}
	 */
	private function card( int $ref, string $offer_id ): array {
		return [
			'offer_id'   => $offer_id,
			'remote_ids' => [ 'card_ref' => $ref ],
			'archived'   => false,
		];
	}

	/**
	 * Builds a product.
	 *
	 * @param int    $id        Product id.
	 * @param string $sku       SKU.
	 * @param int    $parent_id Parent id.
	 * @return array{id:int,parent_id:int,sku:string}
	 */
	private function product( int $id, string $sku, int $parent_id = 0 ): array {
		return [
			'id'        => $id,
			'parent_id' => $parent_id,
			'sku'       => $sku,
		];
	}

	/**
	 * Matches with the fake marketplace's article rules (spaces become dashes).
	 *
	 * @param array<int,array<string,mixed>> $cards    Cards.
	 * @param array<int,array<string,mixed>> $products Products.
	 * @param array<int,array<string,mixed>> $linked   Identity map.
	 * @return array<string,mixed>
	 */
	private function match( array $cards, array $products, array $linked = [] ): array {
		return Card_Matcher::match( $cards, $products, $linked, new Fake_Article_Policy( 50 ) );
	}

	/**
	 * The cards of a product's row, as `Wpdb_Link_Store::identity_map()` lists it.
	 *
	 * @param string|null $offer_id       Article.
	 * @param bool        $has_remote_ids Whether the marketplace gave the row an identifier.
	 * @return array{offer_id:?string,has_remote_ids:bool,card_state:string}
	 */
	private function row( ?string $offer_id, bool $has_remote_ids ): array {
		return [
			'offer_id'       => $offer_id,
			'has_remote_ids' => $has_remote_ids,
			'card_state'     => 'unlinked',
		];
	}

	/**
	 * An equal article pairs the card with the product, and a SKU is normalised like the export does.
	 *
	 * @return void
	 */
	public function test_exact_match_after_the_export_normalisation(): void {
		$result = $this->match(
			[ $this->card( 11, 'woodev-test-1' ), $this->card( 12, 'var-red-5' ), $this->card( 13, 'only-on-market' ) ],
			[ $this->product( 101, 'woodev-test-1' ), $this->product( 202, ' var red 5 ', 200 ), $this->product( 303, 'only-on-site' ) ]
		);

		$this->assertSame( [ 11, 12 ], array_column( array_column( array_column( $result['exact'], 'card' ), 'remote_ids' ), 'card_ref' ) );
		$this->assertSame( [ 101, 202 ], array_column( array_column( $result['exact'], 'product' ), 'id' ) );
		$this->assertSame( [], $result['near'] );
	}

	/**
	 * A difference of case is never an exact match: it is reported as a near match.
	 *
	 * @return void
	 */
	public function test_a_case_difference_is_near_not_exact(): void {
		$result = $this->match( [ $this->card( 11, 'ABC-1' ) ], [ $this->product( 101, 'abc-1' ) ] );

		$this->assertSame( [], $result['exact'] );
		$this->assertCount( 1, $result['near'] );
		$this->assertSame( Offer_Id_Likeness::CASE, $result['near'][0]['relation'] );
		$this->assertSame( 101, $result['near'][0]['product']['id'] );
		$this->assertSame( 'ABC-1', $result['near'][0]['card']['offer_id'] );
	}

	/**
	 * Cyrillic and Latin look-alikes, and the letter O for a zero, are near matches.
	 *
	 * @return void
	 */
	public function test_look_alike_letters_are_near(): void {
		$result = $this->match(
			[ $this->card( 11, 'САР-1' ), $this->card( 12, 'W00-1' ) ],
			[ $this->product( 101, 'CAP-1' ), $this->product( 102, 'WOO-1' ) ]
		);

		$this->assertSame( [], $result['exact'] );
		$this->assertCount( 2, $result['near'] );
		$this->assertSame( [ Offer_Id_Likeness::LOOK_ALIKE, Offer_Id_Likeness::LOOK_ALIKE ], array_column( $result['near'], 'relation' ) );
	}

	/**
	 * A card that matched exactly is not offered as the near match of another product.
	 *
	 * @return void
	 */
	public function test_an_exactly_paired_card_is_not_a_near_match_of_another_product(): void {
		$result = $this->match( [ $this->card( 11, 'ABC-1' ) ], [ $this->product( 101, 'ABC-1' ), $this->product( 102, 'abc-1' ) ] );

		$this->assertCount( 1, $result['exact'] );
		$this->assertSame( [], $result['near'] );
	}

	/**
	 * A product that already has an article or a marketplace identifier, and a card whose article a row carries, are left alone.
	 *
	 * @return void
	 */
	public function test_existing_links_are_never_offered(): void {
		$result = $this->match(
			[ $this->card( 11, 'A-1' ), $this->card( 12, 'B-2' ), $this->card( 13, 'C-3' ) ],
			[ $this->product( 101, 'A-1' ), $this->product( 102, 'B-2' ), $this->product( 103, 'C-3' ) ],
			[
				101 => $this->row( 'A-1', true ),
				102 => $this->row( null, true ),
			]
		);

		$this->assertSame( [ 103 ], array_column( array_column( $result['exact'], 'product' ), 'id' ) );
		$this->assertSame( 1, $result['already_linked_cards'] );
		$this->assertSame( 2, $result['already_linked_products'] );
	}

	/**
	 * An idle row (no article, no marketplace identifier) does not stop the product being linked.
	 *
	 * @return void
	 */
	public function test_an_idle_row_is_still_linkable(): void {
		$result = $this->match( [ $this->card( 11, 'A-1' ) ], [ $this->product( 101, 'A-1' ) ], [ 101 => $this->row( null, false ) ] );

		$this->assertCount( 1, $result['exact'] );
	}

	/**
	 * Two products with the same normalised article link to nobody: guessing would link the wrong one.
	 *
	 * @return void
	 */
	public function test_ambiguous_products_are_not_linked(): void {
		$result = $this->match( [ $this->card( 11, 'A-B' ) ], [ $this->product( 101, 'A B' ), $this->product( 102, 'A-B' ) ] );

		$this->assertSame( [], $result['exact'] );
		$this->assertSame( [], $result['near'] );
		$this->assertSame( 1, $result['ambiguous'] );
	}

	/**
	 * A product without an article has nothing to match.
	 *
	 * @return void
	 */
	public function test_a_product_without_an_article_is_skipped(): void {
		$result = $this->match( [ $this->card( 11, 'A-1' ) ], [ $this->product( 101, '  ' ) ] );

		$this->assertSame( 1, $result['no_article'] );
		$this->assertSame( [], $result['exact'] );
	}

	/**
	 * The article rules are the adapter's: a policy that cuts the article short pairs what the export would send under that cut.
	 *
	 * @return void
	 */
	public function test_the_matching_follows_the_adapters_article_rules(): void {
		$result = Card_Matcher::match( [ $this->card( 11, 'LONG-ART' ) ], [ $this->product( 101, 'LONG-ARTICLE-9' ) ], [], new Fake_Article_Policy( 8 ) );

		$this->assertCount( 1, $result['exact'] );
	}
}
