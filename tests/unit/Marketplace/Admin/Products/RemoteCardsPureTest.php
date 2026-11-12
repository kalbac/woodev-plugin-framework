<?php
/**
 * The pure parts of the «Карточки на маркетплейсе» table: row ids and the filter over the snapshot.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Products
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Products;

use Woodev\Framework\Admin\Records\Query_Validator;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Ids;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Query;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Ids
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Query
 */
final class RemoteCardsPureTest extends TestCase {

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function cards(): array {
		return [
			[ 'offer_id' => 'A-1', 'name' => 'Кружка синяя' ],
			[ 'offer_id' => 'B-2', 'name' => 'Футболка' ],
			[ 'offer_id' => 'C 3/x', 'name' => 'Кружка красная' ],
			[ 'offer_id' => 'D-4', 'name' => '' ],
		];
	}

	public function test_a_row_id_is_an_opaque_token_whatever_the_article_holds(): void {
		foreach ( [ 'A-1', 'C 3/x', 'Кружка №5', str_repeat( 'я', 191 ), "tab\there" ] as $offer_id ) {
			$id = Remote_Cards_Ids::row_id( 7, $offer_id );

			$this->assertTrue( Query_Validator::is_row_id( $id ), 'the engine accepts the id of ' . $offer_id );
			$this->assertSame( Remote_Cards_Ids::key( $offer_id ), Remote_Cards_Ids::key_of( $id, 7 ) );
		}
	}

	public function test_two_articles_never_share_an_id_and_the_same_article_always_does(): void {
		$this->assertSame( Remote_Cards_Ids::row_id( 1, 'A-1' ), Remote_Cards_Ids::row_id( 1, 'A-1' ) );
		$this->assertNotSame( Remote_Cards_Ids::row_id( 1, 'A-1' ), Remote_Cards_Ids::row_id( 1, 'a-1' ) );
		$this->assertNotSame( Remote_Cards_Ids::row_id( 1, 'A-1' ), Remote_Cards_Ids::row_id( 2, 'A-1' ) );
	}

	public function test_an_id_of_another_account_or_a_malformed_one_names_no_card(): void {
		$id = Remote_Cards_Ids::row_id( 7, 'A-1' );

		$this->assertNull( Remote_Cards_Ids::key_of( $id, 8 ) );
		$this->assertNull( Remote_Cards_Ids::key_of( '7:' . strtoupper( Remote_Cards_Ids::key( 'A-1' ) ), 7 ) );
		$this->assertNull( Remote_Cards_Ids::key_of( '7:A-1', 7 ) );
		$this->assertNull( Remote_Cards_Ids::key_of( '07:' . Remote_Cards_Ids::key( 'A-1' ), 7 ) );
		$this->assertNull( Remote_Cards_Ids::key_of( '', 7 ) );
	}

	public function test_the_table_opens_on_the_cards_no_product_carries(): void {
		$linked = [ 'B-2' => 41 ];

		$page = Remote_Cards_Query::page( $this->cards(), $linked, Remote_Cards_Query::NOT_ON_SITE, '', 1, 20 );

		$this->assertSame( 3, $page['total'] );
		$this->assertSame( [ 'A-1', 'C 3/x', 'D-4' ], array_column( $page['cards'], 'offer_id' ) );
		$this->assertSame( [ 0, 0, 0 ], array_column( $page['cards'], 'wc_product_id' ) );
	}

	public function test_the_cards_on_the_site_carry_their_product(): void {
		$page = Remote_Cards_Query::page( $this->cards(), [ 'B-2' => 41 ], Remote_Cards_Query::ON_SITE, '', 1, 20 );

		$this->assertSame( [ 'B-2' ], array_column( $page['cards'], 'offer_id' ) );
		$this->assertSame( [ 41 ], array_column( $page['cards'], 'wc_product_id' ) );
	}

	public function test_no_filter_shows_every_card_in_the_marketplaces_order(): void {
		$page = Remote_Cards_Query::page( $this->cards(), [ 'B-2' => 41 ], '', '', 1, 20 );

		$this->assertSame( [ 'A-1', 'B-2', 'C 3/x', 'D-4' ], array_column( $page['cards'], 'offer_id' ) );
		$this->assertSame( 4, $page['total'] );
	}

	public function test_a_search_looks_in_the_article_and_the_name_without_regard_to_case(): void {
		$cards = $this->cards();

		$this->assertSame( [ 'A-1', 'C 3/x' ], array_column( Remote_Cards_Query::page( $cards, [], '', 'кружка', 1, 20 )['cards'], 'offer_id' ) );
		$this->assertSame( [ 'C 3/x' ], array_column( Remote_Cards_Query::page( $cards, [], '', ' c 3/X ', 1, 20 )['cards'], 'offer_id' ) );
		$this->assertSame( [], Remote_Cards_Query::page( $cards, [], '', 'нет такой', 1, 20 )['cards'] );
	}

	public function test_a_page_is_a_slice_of_the_filtered_list_and_the_total_is_the_whole_of_it(): void {
		$cards = $this->cards();

		$second = Remote_Cards_Query::page( $cards, [], '', '', 2, 3 );

		$this->assertSame( [ 'D-4' ], array_column( $second['cards'], 'offer_id' ) );
		$this->assertSame( 4, $second['total'] );
		$this->assertSame( [], Remote_Cards_Query::page( $cards, [], '', '', 3, 3 )['cards'], 'a page past the end is empty, not wrapped' );
	}

	public function test_a_card_is_found_by_the_key_of_its_article(): void {
		$cards = $this->cards();

		$this->assertSame( 'Футболка', Remote_Cards_Query::find( $cards, Remote_Cards_Ids::key( 'B-2' ) )['name'] );
		$this->assertNull( Remote_Cards_Query::find( $cards, Remote_Cards_Ids::key( 'nope' ) ) );
		$this->assertNull( Remote_Cards_Query::find( [], Remote_Cards_Ids::key( 'B-2' ) ) );
	}
}
