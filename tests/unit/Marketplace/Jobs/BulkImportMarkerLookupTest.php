<?php
/**
 * The production bridge from a half-made product's import marker to the order the bulk import feeds its cards in.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Jobs;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Data\Bulk_Run_Store;
use Woodev\Framework\Marketplace\Flows\Product_Writer;
use Woodev\Framework\Marketplace\Jobs\Bulk_Import_Feeder;
use Woodev\Framework\Marketplace\Jobs\Import_Scheduler;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Export_Test_Case;

/**
 * Runs {@see Bulk_Import_Feeder::marked_in_database()} itself, on SQLite over the two WordPress tables it reads (`wc_product_meta_lookup`
 * and `postmeta`), and feeds its answer to the ordering rule: a lookup that returned nothing, asked about the wrong meta key or was not
 * chunked would pass every test that injects its own answer, and put a sibling in front of the card that resumes its group.
 *
 * @covers \Woodev\Framework\Marketplace\Jobs\Bulk_Import_Feeder
 */
final class BulkImportMarkerLookupTest extends Export_Test_Case {
	private const LOOKUP_TABLE = 'wp_wc_product_meta_lookup';

	/**
	 * @var string
	 */
	private $marker;

	protected function setUp(): void {
		parent::setUp();

		$this->marker = Product_Writer::marker_key( Sqlite_Tables::MARKETPLACE_ID );

		$this->db->postmeta = 'wp_postmeta';
		$this->db->define( 'CREATE TABLE ' . self::LOOKUP_TABLE . ' ( product_id INTEGER PRIMARY KEY, sku TEXT )' );
		$this->db->define( 'CREATE TABLE wp_postmeta ( meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT )' );

		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	protected function tearDown(): void {
		$this->db->statements = []; // The test object outlives the test; the statements it ran need not.

		parent::tearDown();
	}

	/**
	 * Adds a shop product with a SKU, and optionally one meta row.
	 *
	 * @param int         $id    Product id.
	 * @param string      $sku   SKU.
	 * @param string|null $key   Meta key; none when null.
	 * @param string      $value Meta value.
	 * @return void
	 */
	private function shop_product( int $id, string $sku, ?string $key = null, string $value = '1' ): void {
		$this->db->insert(
			self::LOOKUP_TABLE,
			[
				'product_id' => $id,
				'sku'        => $sku,
			]
		);

		if ( null !== $key ) {
			$this->db->insert(
				'wp_postmeta',
				[
					'post_id'    => $id,
					'meta_key'   => $key,
					'meta_value' => $value,
				]
			);
		}
	}

	/**
	 * The shop: `$count` filler products, three of which (one per lookup batch) are half-made imports, and the lookalikes that are not.
	 *
	 * @param int $count Fillers.
	 * @return string[] Every article, in the order the snapshot lists them: the sibling first, the resuming card last.
	 */
	private function shop( int $count ): array {
		$articles = [ 'S-1' ];

		$this->shop_product( 1, 'S-1' ); // The sibling: a product with no marker at all.

		for ( $number = 1; $number <= $count; $number++ ) {
			$article    = 'F-' . $number;
			$articles[] = $article;
			$marked     = in_array( $number, [ 40, 1500, 2200 ], true );

			$this->shop_product( 100 + $number, $article, $marked ? $this->marker : null );
		}

		// Looks like a marker and is not one: an emptied marker (the import finished), and another plugin's meta.
		$this->shop_product( 5001, 'E-1', $this->marker, '' );
		$this->shop_product( 5002, 'W-1', '_woodev_other_import_in_progress' );
		$this->shop_product( 5003, 'R-1', $this->marker );

		return array_merge( $articles, [ 'E-1', 'W-1', 'R-1' ] );
	}

	/**
	 * Lookup queries the database saw (the inserts that set the shop up are not counted).
	 *
	 * @return int
	 */
	private function lookups(): int {
		return count(
			array_filter(
				$this->db->statements,
				static function ( string $sql ): bool {
					return 0 === strpos( $sql, 'SELECT' ) && false !== strpos( $sql, self::LOOKUP_TABLE );
				}
			)
		);
	}

	public function test_it_names_only_the_skus_of_products_that_carry_the_marker_in_batches_of_a_thousand(): void {
		$articles = $this->shop( 2300 );
		$lookup   = Bulk_Import_Feeder::marked_in_database( $this->db, $this->marker );

		$marked = $lookup( $articles );
		sort( $marked );

		$this->assertSame( [ 'F-1500', 'F-2200', 'F-40', 'R-1' ], $marked, 'Marked rows from every batch; not the emptied marker, not another meta key, not a product with no meta.' );
		$this->assertSame( 3, $this->lookups(), '2 304 articles in batches of 1 000: three queries, not one per article.' );
	}

	public function test_it_answers_nothing_for_articles_that_are_not_products(): void {
		$this->shop( 5 );
		$lookup = Bulk_Import_Feeder::marked_in_database( $this->db, $this->marker );

		$this->assertSame( [], $lookup( [ 'NO-SUCH-1', 'NO-SUCH-2' ] ) );
		$this->assertSame( [], $lookup( [] ), 'Nothing asked, nothing queried.' );
		$this->assertSame( 1, $this->lookups() );
	}

	public function test_the_resuming_card_that_sorts_after_its_sibling_is_fed_first_through_the_production_lookup(): void {
		$articles = $this->shop( 1300 ); // The snapshot lists S-1 first and R-1 last, past the first batch.
		$cards    = array_map(
			static function ( string $article ): array {
				return [ $article, in_array( $article, [ 'S-1', 'R-1' ], true ) ? 'model-7' : '' ];
			},
			array_values( array_diff( $articles, [ 'E-1', 'W-1' ] ) )
		);
		$store    = new Bulk_Run_Store( $this->db, Sqlite_Tables::table( 'bulk_runs' ), $this->clock() );
		$feeder   = new Bulk_Import_Feeder(
			$store,
			new Import_Scheduler( $this->names() ),
			static function () use ( $cards ): array {
				return [
					'cards'   => $cards,
					'on_site' => [],
				];
			},
			Bulk_Import_Feeder::marked_in_database( $this->db, $this->marker )
		);

		$this->assertTrue( $store->start( $this->account_id, 'run-1', 'import_selected', [], [ 'search' => '', 'filters' => [] ], 3, count( $cards ) ) );
		$run = $store->find( $this->account_id );
		$this->assertNotNull( $run );

		$this->assertNull( $feeder->refusal( $this->account_now(), $run ) );
		$part = $feeder->part(
			$this->account_now(),
			$run,
			[],
			static function (): bool {
				return true;
			}
		);
		$items = array_column( $part['items'], 0 );

		$this->assertSame( [ 'F-40', 'R-1' ], array_slice( $items, 0, 2 ), 'The half-made products come first, in the snapshot order — R-1 although it is the last card of the snapshot.' );
		$this->assertLessThan( array_search( 'S-1', $items, true ), array_search( 'R-1', $items, true ), 'The resuming card is fed before its sibling.' );
		$this->assertCount( count( $cards ), $items, 'Every card is kept once.' );
		$this->assertSame( 2, $this->lookups(), '1 302 articles: two queries.' );
	}
}
