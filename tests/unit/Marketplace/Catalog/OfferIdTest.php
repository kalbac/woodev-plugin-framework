<?php
/**
 * The seller's article of a product.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Catalog;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Catalog\Article_Policy;
use Woodev\Framework\Marketplace\Catalog\Offer_Id;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Article_Policy;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace_Adapter;

/**
 * D4 of the marketplace design: freezing the article and the normalise-then-fallback order belong to the layer; the length, the
 * cleaning and the fallback belong to the marketplace's {@see Article_Policy}.
 */
final class OfferIdTest extends TestCase {
	/**
	 * Donor parity with the Ozon values: a trimmed SKU with dashes, at most 50 characters, `wc-{id}` for no SKU.
	 *
	 * @return void
	 */
	public function test_donor_parity_with_the_ozon_values(): void {
		$offer_id = new Offer_Id( Fake_Marketplace_Adapter::with_donor_values()->article_policy() );

		$this->assertSame( 'Диван-красный-3м', $offer_id->for_product( null, '  Диван красный 3м  ', 10 ) );
		$this->assertSame( 'wc-10', $offer_id->for_product( null, '   ', 10 ) );
		$this->assertSame( str_repeat( 'Я', 50 ), $offer_id->for_product( null, str_repeat( 'Я', 60 ), 10 ) );
		$this->assertSame( 50, $offer_id->max_length() );
		$this->assertSame( 'SKU-1', $offer_id->normalise( ' SKU 1' ) );
	}

	/**
	 * The default fake marketplace accepts 20 characters, and its adapter says so.
	 *
	 * @return void
	 */
	public function test_the_adapters_policy_decides_the_length(): void {
		$offer_id = new Offer_Id( ( new Fake_Marketplace_Adapter() )->article_policy() );

		$this->assertSame( 20, $offer_id->max_length() );
		$this->assertSame( str_repeat( 'Я', 20 ), $offer_id->for_product( null, str_repeat( 'Я', 60 ), 10 ) );
	}

	/**
	 * Once sent, the article never changes with the SKU; an empty frozen value is not a frozen article.
	 *
	 * @return void
	 */
	public function test_a_sent_article_is_frozen(): void {
		$offer_id = new Offer_Id( new Fake_Article_Policy() );

		$this->assertSame( 'OLD-SKU', $offer_id->for_product( 'OLD-SKU', 'NEW-SKU', 10 ) );
		$this->assertSame( 'NEW-SKU', $offer_id->for_product( '', 'NEW-SKU', 10 ) );
		$this->assertSame( 'NEW-SKU', $offer_id->for_product( null, 'NEW-SKU', 10 ) );
	}

	/**
	 * Another marketplace's policy changes the result; nothing of the first one is left in the layer.
	 *
	 * @return void
	 */
	public function test_another_policy_gives_another_article(): void {
		$short = new Offer_Id( new Fake_Article_Policy( 8 ) );

		$this->assertSame( 'ABCDEFGH', $short->for_product( null, 'ABCDEFGHIJKL', 10 ) );
		$this->assertSame( 8, $short->max_length() );

		$upper = new Offer_Id(
			new class() implements Article_Policy {
				/**
				 * Longest article.
				 *
				 * @return int
				 */
				public function max_length(): int {
					return 20;
				}

				/**
				 * Upper-cases the SKU.
				 *
				 * @param string $sku SKU.
				 * @return string
				 */
				public function normalise( string $sku ): string {
					return strtoupper( trim( $sku ) );
				}

				/**
				 * Fallback article.
				 *
				 * @param int $product_id Product id.
				 * @return string
				 */
				public function fallback( int $product_id ): string {
					return 'ID' . $product_id;
				}
			}
		);

		$this->assertSame( 'ABC', $upper->for_product( null, ' abc ', 7 ) );
		$this->assertSame( 'ID7', $upper->for_product( null, '', 7 ) );
	}
}
