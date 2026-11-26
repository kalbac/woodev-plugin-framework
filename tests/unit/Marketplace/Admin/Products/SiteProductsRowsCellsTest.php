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
	 * The tooltip of the state badge: what is synchronised, the warehouse and the error, one sentence a line.
	 *
	 * @param array<string,mixed> $offer Offer keys to override.
	 * @return string[]
	 */
	private function tooltip_lines( array $offer ): array {
		$badge = $this->cells( $offer )['state'][0]['value'];

		return isset( $badge['tooltip'] ) ? explode( "\n", $badge['tooltip'] ) : [];
	}

	public function test_the_state_cell_is_one_badge_whose_tooltip_lists_what_is_synchronised(): void {
		$this->assertCount( 1, $this->cells( [] )['state'] );
		$this->assertSame( 'badge', $this->cells( [] )['state'][0]['type'] );
		$this->assertSame( [ 'Синхронизируются: остатки, цены' ], $this->tooltip_lines( [] ) );
		$this->assertSame( [ 'Синхронизируются: остатки' ], $this->tooltip_lines( [ 'sync_price' => false ] ) );
		$this->assertSame( [ 'Синхронизируются: цены' ], $this->tooltip_lines( [ 'sync_stock' => false ] ) );
		$this->assertSame( [ 'Синхронизация выключена' ], $this->tooltip_lines( [ 'sync_stock' => false, 'sync_price' => false ] ) );
	}

	public function test_the_warehouse_is_written_only_when_the_product_has_one_of_its_own(): void {
		$this->assertSame( [ 'Синхронизируются: остатки, цены', 'Склад: Основной склад' ], $this->tooltip_lines( [ 'warehouse_id' => 22 ] ) );
		$this->assertSame( [ 'Синхронизируются: остатки, цены', 'Склад: #99' ], $this->tooltip_lines( [ 'warehouse_id' => 99 ] ), 'A warehouse the account no longer lists is named by its number.' );
		$this->assertCount( 1, $this->tooltip_lines( [ 'warehouse_id' => 0 ] ) );
	}

	public function test_an_unlinked_product_has_a_bare_badge_and_says_nothing_about_synchronisation(): void {
		$state = $this->cells( [ 'link_id' => null ] )['state'];

		$this->assertCount( 1, $state );
		$this->assertArrayNotHasKey( 'tooltip', $state[0]['value'] );
	}

	public function test_the_error_is_the_last_line_of_the_badge_tooltip_and_there_is_no_error_or_sync_column(): void {
		$cells = $this->cells( [ 'error' => 'Не заполнен бренд' ] );
		$lines = $this->tooltip_lines( [ 'error' => 'Не заполнен бренд' ] );

		$this->assertCount( 1, $cells['state'], 'One badge, not a badge and a red one under it.' );
		$this->assertSame( 'Ошибка: Не заполнен бренд', end( $lines ) );
		$this->assertArrayNotHasKey( 'error', $cells );
		$this->assertArrayNotHasKey( 'sync', $cells );
	}

	/**
	 * Why the new price did not take effect is a line of the same tooltip: a refusal quotes the marketplace, a quarantine is shown
	 * as stored.
	 *
	 * @return void
	 */
	public function test_a_price_that_did_not_take_effect_is_said_in_the_badge_tooltip(): void {
		$refused     = $this->tooltip_lines( [ 'price_error_code' => 'price_refused', 'price_error' => 'Too low' ] );
		$quarantined = $this->tooltip_lines( [ 'price_error_code' => 'price_quarantined', 'price_error' => 'Тестмаркет держит новую цену в карантине: она намного ниже текущей' ] );

		$this->assertSame( 'Маркетплейс не принял новую цену: Too low', end( $refused ) );
		$this->assertSame( 'Тестмаркет держит новую цену в карантине: она намного ниже текущей', end( $quarantined ) );
	}

	/**
	 * A parent row counts a variation with a price reason among its errors.
	 *
	 * @return void
	 */
	public function test_a_variable_product_counts_a_price_reason_among_the_errors_of_its_variations(): void {
		$cells = $this->parent_cells( [ 'created', 'created', 'error' ], [ 1 => 'price_quarantined' ] );

		$this->assertSame( 'Ошибка у 2 вариантов', $cells['state'][0]['value']['tooltip'] );
	}

	public function test_stock_and_price_are_one_text_site_slash_marketplace_with_a_dash_for_a_missing_side(): void {
		$this->assertSame( '5 / —', $this->cells( [] )['stock'] );
		$this->assertSame( '100 / —', $this->cells( [] )['price'] );
		$this->assertSame( '5 / 4', $this->cells( [ 'remote_stock' => 4 ] )['stock'] );
		$this->assertSame( '100 / 120', $this->cells( [ 'remote_price' => '120' ] )['price'] );
		$this->assertSame( '— / —', $this->cells( [ 'site_stock' => null, 'site_price' => null ] )['stock'] );
	}

	/**
	 * The state cell of a variable product with the given variation groups, one variation per entry.
	 *
	 * @param string[]          $groups Group of each variation; a variation of the group `error` carries an error text.
	 * @param array<int,string> $prices Variation index => price reason code.
	 * @return array<string,mixed> The cells of the parent row.
	 */
	private function parent_cells( array $groups, array $prices = [] ): array {
		$offers = [];

		foreach ( $groups as $index => $group ) {
			$offers[] = [
				'product_id'   => 30 + $index,
				'label'        => '',
				'link_id'      => 5 + $index,
				'group'        => $group,
				'sku'          => 'V-' . $index,
				'offer_id'     => 'V-' . $index,
				'site_stock'   => 1,
				'remote_stock' => null,
				'site_price'   => '100',
				'remote_price' => null,
				'sync_stock'   => true,
				'sync_price'   => true,
				'warehouse_id' => null,
				'error'        => 'error' === $group ? 'Не заполнен бренд' : '',
				'stock_pool'   => null,
				'price_error_code' => $prices[ $index ] ?? '',
			];
		}

		$rows = new Site_Products_Rows(
			'Тестмаркет',
			[],
			static function ( int $id ): string {
				return 'https://shop.test/edit/' . $id;
			}
		);

		return $rows->parent( 1, [ 'id' => 10, 'title' => 'Футболка', 'sku' => '', 'is_variable' => true, 'offers' => $offers ], [] )['cells'];
	}

	/**
	 * @param string[] $groups Group of each variation.
	 * @return string[] The labels of the state cell.
	 */
	private function parent_state_labels( array $groups ): array {
		return array_column( array_column( $this->parent_cells( $groups )['state'], 'value' ), 'label' );
	}

	public function test_a_variable_product_shows_every_state_group_of_its_variations_up_to_the_stack_limit(): void {
		// Four groups, the biggest first; none of them is dropped to make room for lines a parent never has.
		$this->assertSame(
			[ 'Создан × 4', 'Не связан × 3', 'В очереди × 2', 'В архиве' ],
			$this->parent_state_labels( [ 'created', 'created', 'created', 'created', 'unlinked', 'unlinked', 'unlinked', 'queued', 'queued', 'archived' ] )
		);
	}

	public function test_a_variable_product_says_the_errors_of_its_variations_once_in_the_tooltip_of_its_first_badge(): void {
		$cells = $this->parent_cells( [ 'created', 'created', 'created', 'created', 'unlinked', 'unlinked', 'unlinked', 'queued', 'queued', 'archived', 'error', 'error' ] );
		$state = array_column( $cells['state'], 'value' );

		$this->assertCount( 5, $state, 'All five lines the engine holds are state badges.' );
		$this->assertSame( 'Ошибка у 2 вариантов', $state[0]['tooltip'] );
		$this->assertArrayNotHasKey( 'tooltip', $state[1] );
	}

	public function test_a_variable_product_without_an_error_has_no_tooltip(): void {
		$state = array_column( $this->parent_cells( [ 'created', 'unlinked' ] )['state'], 'value' );

		$this->assertArrayNotHasKey( 'tooltip', $state[0] );
	}

	/**
	 * The photo column: the product's picture (or the placeholder, which the picture callable answers with) in front of the name, the
	 * variation's own picture on its row; no callable, no photo cell.
	 */
	public function test_the_photo_cell_is_the_picture_the_callable_gives_for_the_row_s_own_product(): void {
		$offer = [
			'product_id'   => 11, 'label' => 'S', 'link_id' => null, 'group' => 'not_linked', 'sku' => '', 'offer_id' => '',
			'site_stock'   => 1, 'remote_stock' => null, 'site_price' => '1', 'remote_price' => null, 'sync_stock' => false,
			'sync_price'   => false, 'warehouse_id' => null, 'error' => '', 'stock_pool' => null,
		];
		$edit  = static function ( int $id ): string {
			return 'https://shop.test/edit/' . $id;
		};
		$rows  = new Site_Products_Rows( 'Тестмаркет', [], $edit, static fn( int $id ): string => 'https://shop.test/img-' . $id . '.jpg' );
		$child = $rows->child( 1, 10, $offer, [] );

		$this->assertSame( [ [ 'type' => 'image', 'value' => [ 'url' => 'https://shop.test/img-11.jpg', 'alt' => 'S' ] ] ], $child['cells']['image'] );
		$this->assertNull( ( new Site_Products_Rows( 'Тестмаркет', [], $edit ) )->child( 1, 10, $offer, [] )['cells']['image'] );
		$this->assertSame( 'image', array_key_first( $child['cells'] ), 'The photo is the first cell.' );
	}
}
