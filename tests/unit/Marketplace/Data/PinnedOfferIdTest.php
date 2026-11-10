<?php
/**
 * The article a merchant chose before the first export.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Data;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Data\Pinned_Offer_Id;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Article_Policy;

/**
 * Kept on the product, one value per account and marketplace; what is valid comes from the adapter's article policy.
 */
final class PinnedOfferIdTest extends TestCase {
	/**
	 * Boots Brain Monkey.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
	}

	/**
	 * Restores Brain Monkey.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->addToAssertionCount( Mockery::getContainer()->mockery_getExpectationCount() );
		Mockery::close();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The meta key carries the marketplace and the account, so two marketplaces and two cabinets never share a choice; the
	 * uninstaller deletes by the prefix.
	 *
	 * @return void
	 */
	public function test_the_meta_key_names_the_marketplace_and_the_account(): void {
		$pinned = new Pinned_Offer_Id( 'fake', new Fake_Article_Policy() );

		$this->assertSame( '_woodev_fake_offer_id_', Pinned_Offer_Id::meta_prefix( 'fake' ) );
		$this->assertSame( '_woodev_fake_offer_id_7', $pinned->meta_key( 7 ) );
		$this->assertNotSame( $pinned->meta_key( 7 ), ( new Pinned_Offer_Id( 'other', new Fake_Article_Policy() ) )->meta_key( 7 ) );
	}

	/**
	 * A stored article is read back; none, an empty value or a non-string reads as «not chosen».
	 *
	 * @return void
	 */
	public function test_the_chosen_article_is_read_from_the_product(): void {
		$pinned  = new Pinned_Offer_Id( 'fake', new Fake_Article_Policy() );
		$product = Mockery::mock( \WC_Product::class );
		$product->shouldReceive( 'get_meta' )->with( '_woodev_fake_offer_id_7', true )->andReturn( 'MY-ART' );
		$product->shouldReceive( 'get_meta' )->with( '_woodev_fake_offer_id_8', true )->andReturn( '' );
		$product->shouldReceive( 'get_meta' )->with( '_woodev_fake_offer_id_9', true )->andReturn( 42 );

		$this->assertSame( 'MY-ART', $pinned->of( $product, 7 ) );
		$this->assertNull( $pinned->of( $product, 8 ) );
		$this->assertNull( $pinned->of( $product, 9 ) );
	}

	/**
	 * Storing writes the meta and saves it; an empty article clears the choice.
	 *
	 * @return void
	 */
	public function test_storing_writes_or_clears_the_meta(): void {
		$pinned  = new Pinned_Offer_Id( 'fake', new Fake_Article_Policy() );
		$product = Mockery::mock( \WC_Product::class );
		$product->shouldReceive( 'update_meta_data' )->once()->with( '_woodev_fake_offer_id_7', 'MY-ART' );
		$product->shouldReceive( 'delete_meta_data' )->twice()->with( '_woodev_fake_offer_id_7' );
		$product->shouldReceive( 'save_meta_data' )->times( 3 );

		$pinned->store( $product, 7, 'MY-ART' );
		$pinned->store( $product, 7, '' );
		$pinned->store( $product, 7, null );
	}

	/**
	 * An article the adapter's policy would change is a problem with a sentence; a good one, or none, is not.
	 *
	 * @return void
	 */
	public function test_a_typed_article_is_judged_by_the_adapters_policy(): void {
		$pinned = new Pinned_Offer_Id( 'fake', new Fake_Article_Policy( 10 ) );

		$this->assertNull( $pinned->problem( '' ) );
		$this->assertNull( $pinned->problem( 'GOOD-ART' ) );
		$this->assertStringContainsString( '10', (string) $pinned->problem( 'has spaces' ) );
		$this->assertNotNull( $pinned->problem( 'WAY-TOO-LONG-FOR-TEN' ) );
		$this->assertNull( ( new Pinned_Offer_Id( 'fake', new Fake_Article_Policy( 50 ) ) )->problem( 'WAY-TOO-LONG-FOR-TEN' ), 'the cap is the policy\'s' );
	}
}
