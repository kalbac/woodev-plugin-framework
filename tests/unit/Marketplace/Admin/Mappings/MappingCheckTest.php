<?php
/**
 * The products a mapping covers and the check over them.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Mappings
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Mappings;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mapping_Check;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mapping_Products;
use Woodev\Tests\Unit\TestCase;

require_once __DIR__ . '/Mappings_Harness.php';

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mapping_Products
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mapping_Check
 */
final class MappingCheckTest extends TestCase {
	/**
	 * Stubs WordPress and clears the query stand-in.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		\WP_Query::$ids     = [];
		\WP_Query::$queries = [];
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_term_children' )->alias(
			static function ( $term_id ) {
				return [ 5 => [ 6, 7 ] ][ $term_id ] ?? [];
			}
		);
	}

	/**
	 * A nearer mapping on a child category takes its subtree away from the parent mapping.
	 *
	 * @return void
	 */
	public function test_a_nearer_mapping_takes_its_subtree_away_from_the_parent(): void {
		$products = new Mapping_Products(
			static function (): array {
				return [
					1 => [ 5 ],
					2 => [ 6 ],
					3 => [ 9 ],
				];
			}
		);

		$products->count( 1 );
		$tax = \WP_Query::$queries[0]['tax_query'];

		$this->assertSame( [ 5 ], $tax[0]['terms'] );
		$this->assertTrue( $tax[0]['include_children'] );
		$this->assertSame( 'NOT IN', $tax[1]['operator'] );
		$this->assertSame( [ 6 ], $tax[1]['terms'], 'child 6 belongs to mapping 2; 9 is unrelated and is left alone' );
		$this->assertFalse( \WP_Query::$queries[0]['no_found_rows'], 'a count needs the total' );
	}

	/**
	 * Several owned categories are alternatives, and a mapping without categories covers nothing and costs no query.
	 *
	 * @return void
	 */
	public function test_owned_categories_are_alternatives_and_none_means_no_query(): void {
		$products = new Mapping_Products(
			static function (): array {
				return [ 1 => [ 8, 9 ] ];
			}
		);

		$this->assertSame( [], $products->ids( 2, 10 ) );
		$this->assertSame( 0, $products->count( 2 ) );
		$this->assertSame( [], \WP_Query::$queries );

		$products->ids( 1, 10, 20 );
		$this->assertSame( 'OR', \WP_Query::$queries[0]['tax_query']['relation'] );
		$this->assertSame( 20, \WP_Query::$queries[0]['offset'] );
	}

	/**
	 * Products without a category mapped are grouped by their first error, most frequent first, and batches add up.
	 *
	 * @return void
	 */
	public function test_the_check_groups_the_first_error_and_adds_batches_up(): void {
		$h = new Mappings_Harness();
		$h->mappings->create( 'Без категории', null, [], null, [] );
		$h->mappings->link_term_if_absent( 5, 1 );

		\WP_Query::$ids = [ 11, 12, 13 ];
		Functions\when( 'wc_get_product' )->alias(
			static function ( $id ) {
				return $id === 13 ? false : \Mockery::mock( 'WC_Product' )->shouldIgnoreMissing();
			}
		);

		$products = new Mapping_Products( [ $h->mappings, 'terms_by_mapping' ] );
		$check    = new Mapping_Check( $h->mappings, $h->profiles, new \Woodev\Framework\Marketplace\Catalog\Export_Validator( [ new \Woodev\Framework\Marketplace\Catalog\Rules\Category_Mapped_Rule() ] ), $h->finder, $products, static function ( $product, $mapping, $profile, array $base ): array {
			return $base;
		} );

		$report = $check->run( 1 );

		$this->assertSame( 2, $report['checked'], 'a product that cannot be loaded is not counted' );
		$this->assertSame( 0, $report['ok'] );
		$this->assertCount( 1, $report['groups'] );
		$this->assertSame( 2, $report['groups'][0]['count'] );
		$this->assertSame( 0, $report['next_offset'], 'fewer products than the batch size: done' );

		$next = $check->run( 1, 0, $report );
		$this->assertSame( 4, $next['checked'] );
		$this->assertSame( 4, $next['groups'][0]['count'] );
		$this->assertSame( 0, $h->schema->calls['fields'] ?? 0, 'a mapping with no category never asks for fields' );
	}

	/**
	 * A mapping that does not exist gives null, not an exception.
	 *
	 * @return void
	 */
	public function test_a_missing_mapping_gives_null(): void {
		$h = new Mappings_Harness();

		$this->assertNull( $h->check->run( 404 ) );
	}
}
