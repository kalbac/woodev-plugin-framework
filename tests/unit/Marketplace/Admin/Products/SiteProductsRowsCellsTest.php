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

	/**
	 * The text lines of the «Состояние» cell: what is synchronised, under the state badge.
	 *
	 * @param array<string,mixed> $offer Offer keys to override.
	 * @return string[]
	 */
	private function sync_lines( array $offer ): array {
		$items = array_filter(
			$this->cells( $offer )['state'],
			static function ( array $item ): bool {
				return 'text' === $item['type'];
			}
		);

		return array_values( array_column( $items, 'value' ) );
	}

	public function test_the_state_cell_lists_only_what_is_synchronised(): void {
		$this->assertSame( [ 'Остатки · Цены' ], $this->sync_lines( [] ) );
		$this->assertSame( [ 'Остатки' ], $this->sync_lines( [ 'sync_price' => false ] ) );
		$this->assertSame( [ 'Цены' ], $this->sync_lines( [ 'sync_stock' => false ] ) );
		$this->assertSame( [ '—' ], $this->sync_lines( [ 'sync_stock' => false, 'sync_price' => false ] ), 'Nothing on is a dash, not a sentence.' );
	}

	public function test_the_warehouse_is_written_only_when_the_product_has_one_of_its_own(): void {
		$this->assertSame( [ 'Остатки · Цены', 'Склад: Основной склад' ], $this->sync_lines( [ 'warehouse_id' => 22 ] ) );
		$this->assertSame( [ 'Остатки · Цены', 'Склад: #99' ], $this->sync_lines( [ 'warehouse_id' => 99 ] ), 'A warehouse the account no longer lists is named by its number.' );
		$this->assertCount( 1, $this->sync_lines( [ 'warehouse_id' => 0 ] ) );
	}

	public function test_an_unlinked_product_has_only_its_badge_in_the_state_cell(): void {
		$this->assertCount( 1, $this->cells( [ 'link_id' => null ] )['state'] );
	}

	public function test_the_error_is_a_red_badge_in_the_state_cell_and_there_is_no_error_or_sync_column(): void {
		$cells = $this->cells( [ 'error' => 'Не заполнен бренд' ] );
		$last  = end( $cells['state'] );

		$this->assertSame( 'badge', $last['type'] );
		$this->assertSame( [ 'label' => 'Не заполнен бренд', 'tone' => 'danger' ], $last['value'] );
		$this->assertArrayNotHasKey( 'error', $cells );
		$this->assertArrayNotHasKey( 'sync', $cells );
	}
}
