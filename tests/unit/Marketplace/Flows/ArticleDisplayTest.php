<?php
/**
 * The article as a merchant reads it.
 *
 * @package Woodev\Tests\Unit\Marketplace\Flows
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use Woodev\Framework\Marketplace\Flows\Article_Display;
use Woodev\Framework\Marketplace\Links\Unit_Key;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Sellable_Units;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Marketplace\Flows\Article_Display
 */
final class ArticleDisplayTest extends TestCase {
	public function test_a_marketplace_whose_article_is_the_offer_id_shows_it_as_it_is(): void {
		$display = new Article_Display();

		$this->assertSame( 'OZ-10', $display->of_link( 1, 'OZ-10' ) );
		$this->assertSame( 'OZ-10', $display->card_article_of_link( 1, 'OZ-10' ) );
		$this->assertSame( '', $display->card_article_of_link( 1, '' ) );
	}

	public function test_the_card_article_of_a_unit_leaves_out_what_tells_the_unit_apart(): void {
		$display = new Article_Display( new Fake_Sellable_Units() );
		$key     = Unit_Key::encode( 'coat', 'M' );

		$this->assertSame( 'coat · M', $display->of_link( 1, $key ) );
		$this->assertSame( 'coat', $display->card_article_of_link( 1, $key ) );
		$this->assertSame( '', $display->card_article_of_link( 1, '' ) );
	}
}
