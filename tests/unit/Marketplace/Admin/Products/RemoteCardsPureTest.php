<?php
/**
 * The pure parts of the «Карточки на маркетплейсе» table: row ids and the filter values (the filtering itself is SQL: WpdbCardStoreTest).
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

	public function test_the_on_site_filter_reads_into_the_stores_flag(): void {
		$this->assertTrue( Remote_Cards_Query::on_site_flag( Remote_Cards_Query::ON_SITE ) );
		$this->assertFalse( Remote_Cards_Query::on_site_flag( Remote_Cards_Query::NOT_ON_SITE ) );
		$this->assertNull( Remote_Cards_Query::on_site_flag( '' ) );
		$this->assertNull( Remote_Cards_Query::on_site_flag( 'whatever' ) );
	}
}
