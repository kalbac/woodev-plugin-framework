<?php
/**
 * The article and the synchronisation cells of the «Товары сайта» table.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Products;

use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Rows;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Rows
 */
final class SiteProductsRowsCellsTest extends TestCase {

	/**
	 * A simple product's cells.
	 *
	 * @param array<string,mixed> $offer Offer keys to override.
	 * @return array<string,mixed>
	 */
	private function cells( array $offer ): array {
		$offer = array_merge(
			[
				'product_id'   => 10,
				'label'        => '',
				'link_id'      => 5,
				'group'        => 'created',
				'sku'          => 'P-10',
				'offer_id'     => 'P-10',
				'site_stock'   => 5,
				'remote_stock' => null,
				'site_price'   => '100',
				'remote_price' => null,
				'sync_stock'   => true,
				'sync_price'   => true,
				'warehouse_id' => null,
				'error'        => '',
				'stock_pool'   => null,
			],
			$offer
		);
		$rows  = new Site_Products_Rows(
			'Тестмаркет',
			[ '22' => 'Основной склад' ],
			static function ( int $id ): string {
				return 'https://shop.test/edit/' . $id;
			}
		);

		return $rows->parent(
			1,
			[
				'id'          => 10,
				'title'       => 'Кружка',
				'sku'         => 'P-10',
				'is_variable' => false,
				'offers'      => [ $offer ],
			],
			[]
		)['cells'];
	}

	public function test_the_article_is_one_line_when_the_marketplace_offer_id_is_the_same(): void {
		$this->assertSame( [ 'P-10' ], array_column( $this->cells( [] )['sku'], 'value' ) );
		$this->assertSame( [ 'P-10', 'OZ-10' ], array_column( $this->cells( [ 'offer_id' => 'OZ-10' ] )['sku'], 'value' ) );
		$this->assertSame( [ 'OZ-10' ], array_column( $this->cells( [ 'sku' => '', 'offer_id' => 'OZ-10' ] )['sku'], 'value' ) );
	}

	public function test_the_sync_cell_lists_only_what_is_on(): void {
		$this->assertSame( [ 'Остатки · Цены' ], array_column( $this->cells( [] )['sync'], 'value' ) );
		$this->assertSame( [ 'Остатки' ], array_column( $this->cells( [ 'sync_price' => false ] )['sync'], 'value' ) );
		$this->assertSame( [ 'Цены' ], array_column( $this->cells( [ 'sync_stock' => false ] )['sync'], 'value' ) );
		$this->assertSame( [ '—' ], array_column( $this->cells( [ 'sync_stock' => false, 'sync_price' => false ] )['sync'], 'value' ), 'Nothing on is a dash, not a sentence.' );
	}

	public function test_the_warehouse_is_written_only_when_the_product_has_one_of_its_own(): void {
		$this->assertSame( [ 'Остатки · Цены', 'Склад: Основной склад' ], array_column( $this->cells( [ 'warehouse_id' => 22 ] )['sync'], 'value' ) );
		$this->assertSame( [ 'Остатки · Цены', 'Склад: #99' ], array_column( $this->cells( [ 'warehouse_id' => 99 ] )['sync'], 'value' ), 'A warehouse the account no longer lists is named by its number.' );
		$this->assertCount( 1, $this->cells( [ 'warehouse_id' => 0 ] )['sync'] );
	}

	public function test_an_unlinked_product_has_no_sync_cell(): void {
		$this->assertNull( $this->cells( [ 'link_id' => null ] )['sync'] );
	}
}
