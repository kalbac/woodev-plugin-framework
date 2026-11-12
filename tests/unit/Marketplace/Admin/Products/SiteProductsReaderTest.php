<?php
/**
 * The SQL of the «Товары сайта» table, run on SQLite.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Products
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Products;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Filter;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Reader;
use Woodev\Framework\Marketplace\Admin\Pages\Products\State_Groups;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Reader
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Filter
 */
final class SiteProductsReaderTest extends TestCase {
	/**
	 * @var Products_Shop
	 */
	private $shop;

	/**
	 * @var Site_Products_Reader
	 */
	private $reader;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->shop   = new Products_Shop();
		$this->reader = new Site_Products_Reader(
			$this->shop->db,
			Sqlite_Tables::table( 'links' ),
			Sqlite_Tables::table( 'sync_state' ),
			static function ( int $term_id ): array {
				return 7 === $term_id ? [ 7, 8 ] : [ $term_id ];
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string,mixed> $query Query as the engine hands it over.
	 * @return int[]
	 */
	private function ids( array $query, int $page = 1, int $per_page = 20 ): array {
		return $this->reader->page( Site_Products_Filter::from_query( Products_Shop::ACCOUNT, $query ), $page, $per_page )['ids'];
	}

	public function test_only_simple_and_variable_products_are_listed_sorted_by_title(): void {
		$page = $this->reader->page( new Site_Products_Filter( Products_Shop::ACCOUNT ), 1, 20 );

		$this->assertSame( 3, $page['total'] );
		$this->assertSame( [ 20, 10, 30 ], $page['ids'], 'Кроссовки, Платье, Сумка: by title.' );
	}

	public function test_sorting_and_paging_cover_the_whole_table_not_one_page(): void {
		$this->assertSame( [ 30, 10, 20 ], $this->ids( [ 'sort' => 'name', 'direction' => 'desc' ] ) );
		$this->assertSame( [ 10, 30, 20 ], $this->ids( [ 'sort' => 'price', 'direction' => 'desc' ] ), 'Price descending: 100, 50, then the parent with none.' );

		$second = $this->reader->page( Site_Products_Filter::from_query( Products_Shop::ACCOUNT, [ 'sort' => 'name' ] ), 2, 2 );

		$this->assertSame( 3, $second['total'] );
		$this->assertSame( [ 30 ], $second['ids'], 'The second page continues the sorted whole.' );
	}

	public function test_an_unknown_sort_key_falls_back_to_the_title(): void {
		$filter = new Site_Products_Filter( 1, '', 0, false, '', 'title; DROP TABLE x', 'sideways' );

		$this->assertSame( 'p.post_title ASC, p.ID ASC', $filter->order_by() );
	}

	public function test_the_state_filter_finds_a_variable_product_when_any_variation_matches(): void {
		$this->assertSame( [ 20 ], $this->ids( [ 'filters' => [ 'state' => State_Groups::ERROR ] ] ) );
		$this->assertSame( [ 10 ], $this->ids( [ 'filters' => [ 'state' => State_Groups::CREATED ] ] ) );
		$this->assertSame( [], $this->ids( [ 'filters' => [ 'state' => State_Groups::ARCHIVED ] ] ) );
	}

	public function test_unlinked_means_a_product_or_any_of_its_variations_has_no_live_link(): void {
		// 10 is created; 20 has an unlinked variation (22, no row) and a failed one; 30 has no row.
		$this->assertSame( [ 20, 30 ], $this->ids( [ 'filters' => [ 'state' => State_Groups::UNLINKED ] ] ) );
	}

	public function test_only_errors_category_and_search_narrow_the_table(): void {
		$this->assertSame( [ 20 ], $this->ids( [ 'filters' => [ 'only_errors' => true ] ] ) );
		$this->assertSame( [ 20 ], $this->ids( [ 'filters' => [ 'category' => '7' ] ] ) );
		$this->assertSame( [], $this->ids( [ 'filters' => [ 'category' => '8' ] ] ), 'The product sits in the parent category only.' );
		$this->assertSame( [ 30 ], $this->ids( [ 'filters' => [ 'q' => 'B-30' ] ] ) );
		$this->assertSame( [ 20 ], $this->ids( [ 'filters' => [ 'q' => 'S-22' ] ] ), 'A variation SKU finds its parent.' );
		$this->assertSame( [ 10 ], $this->ids( [ 'filters' => [ 'q' => 'OZ-10' ] ] ), 'The article on the marketplace is searched too.' );
	}

	public function test_a_draft_is_listed_and_a_trashed_product_is_not(): void {
		$this->shop->product( 40, 'Шарф', 'F-40', 1, '10', 'simple', [], 'draft' );
		$this->shop->product( 41, 'Шапка', 'H-41', 1, '10', 'simple', [], 'trash' );

		$this->assertContains( 40, $this->ids( [] ) );
		$this->assertNotContains( 41, $this->ids( [] ) );
	}

	public function test_rows_carry_the_offers_with_state_error_and_marketplace_stock(): void {
		$this->shop->readback( (int) $this->shop->links->find( 1, 10 )->link_id(), 9, 2 );

		$rows = $this->reader->rows( 1, [ 20, 10 ] );

		$this->assertSame( [ 20, 10 ], array_column( $rows, 'id' ) );
		$this->assertTrue( $rows[0]['is_variable'] );
		$this->assertSame( [ 21, 22 ], array_column( $rows[0]['offers'], 'product_id' ) );
		$this->assertSame( [ 'красный', 'синий' ], array_column( $rows[0]['offers'], 'label' ) );
		$this->assertSame( [ State_Groups::ERROR, State_Groups::UNLINKED ], array_column( $rows[0]['offers'], 'group' ) );
		$this->assertSame( 'Не заполнен бренд', $rows[0]['offers'][0]['error'] );

		$dress = $rows[1]['offers'][0];
		$this->assertFalse( $rows[1]['is_variable'] );
		$this->assertSame( 'OZ-10', $dress['offer_id'] );
		$this->assertSame( 7, $dress['remote_stock'], 'Present minus reserved.' );
		$this->assertSame( 5, $dress['site_stock'] );
		$this->assertSame( '100', $dress['site_price'] );
	}

	public function test_a_variation_without_its_own_stock_takes_the_parents_pool(): void {
		$this->shop->product( 50, 'Рубашка', 'R-50', 12, null, 'variable' );
		$this->shop->variation( 51, 50, 'R-51', null, '10', 'S' );

		// The parent's own lookup row holds the pool: no product is loaded to learn it.
		$offer = $this->reader->rows( 1, [ 50 ] )[0]['offers'][0];

		$this->assertSame( 12, $offer['site_stock'] );
		$this->assertSame( 50, $offer['stock_pool'], 'The parent id marks a shared pool, so a sum counts it once.' );
	}

	public function test_stock_sorts_by_what_the_cell_shows_a_variable_product_by_the_sum_of_its_variations(): void {
		// 20 keeps its stock on variations 21 and 22 (3 + 4 = 7); 10 has 5, 30 has 0.
		$this->assertSame( [ 30, 10, 20 ], $this->ids( [ 'sort' => 'stock' ] ) );
		$this->assertSame( [ 20, 10, 30 ], $this->ids( [ 'sort' => 'stock', 'direction' => 'desc' ] ) );
	}

	public function test_variations_are_read_a_page_at_a_time(): void {
		$first = $this->reader->variation_page( 1, 20, 1, 1 );
		$next  = $this->reader->variation_page( 1, 20, 2, 1 );

		$this->assertSame( 2, $first['total'] );
		$this->assertSame( [ 21 ], array_column( $first['offers'], 'product_id' ) );
		$this->assertSame( [ 22 ], array_column( $next['offers'], 'product_id' ) );
		$this->assertSame( [], $this->reader->variation_page( 1, 20, 3, 1 )['offers'] );
	}

	public function test_kinds_tells_listed_products_from_variations_and_from_strangers(): void {
		$this->shop->product( 60, 'Скрытый', 'X', 1, '1', 'simple', [], 'trash' );

		$kinds = $this->reader->kinds( [ 10, 20, 21, 60, 999 ] );

		$this->assertSame( [ 10, 20, 21 ], array_keys( $kinds ) );
		$this->assertFalse( $kinds[10]['is_variation'] );
		$this->assertTrue( $kinds[21]['is_variation'] );
		$this->assertSame( 20, $kinds[21]['parent'] );
		$this->assertSame( [ 20 => [ 21, 22 ] ], $this->reader->variation_ids( [ 20, 10 ] ) );
	}

	public function test_another_accounts_links_are_not_shown(): void {
		$this->shop->link( 30, 0, 'OTHER-30', 'created', [], Products_Shop::OTHER );

		$rows = $this->reader->rows( Products_Shop::ACCOUNT, [ 30 ] );

		$this->assertSame( '', $rows[0]['offers'][0]['offer_id'] );
		$this->assertSame( State_Groups::UNLINKED, $rows[0]['offers'][0]['group'] );
	}
}
