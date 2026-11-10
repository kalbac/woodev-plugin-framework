<?php
/**
 * Mappings and export profiles on SQLite, over the real columns of the schema.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Data;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Catalog\Source;
use Woodev\Framework\Marketplace\Data\Persistence_Exception;
use Woodev\Framework\Marketplace\Data\Wpdb_Mapping_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Profile_Store;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;

/**
 * Mappings keep the adapter's category descriptor as it is; terms belong to one mapping; profiles are insert-only when a
 * migration seeds them and fire the price-changed action when a price term moves.
 */
final class WpdbMappingAndProfileStoreTest extends TestCase {
	/**
	 * Database.
	 *
	 * @var Sqlite_Wpdb
	 */
	private $db;

	/**
	 * Mapping store.
	 *
	 * @var Wpdb_Mapping_Store
	 */
	private $mappings;

	/**
	 * Profile store.
	 *
	 * @var Wpdb_Profile_Store
	 */
	private $profiles;

	/**
	 * Boots the stubs and the tables.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->db = new Sqlite_Wpdb();
		Sqlite_Tables::install( $this->db );
		$this->mappings = new Wpdb_Mapping_Store(
			$this->db,
			Sqlite_Tables::table( 'mappings' ),
			Sqlite_Tables::table( 'mapping_terms' ),
			static function (): int {
				return 1794139200;
			}
		);
		$this->profiles = new Wpdb_Profile_Store( $this->db, Sqlite_Tables::table( 'profiles' ), Wpdb_Profile_Store::price_changed_action( 'fake' ) );
	}

	/**
	 * Tears the stubs down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A mapping keeps the id it is given and is insert-only; its category descriptor comes back whole, whatever its shape.
	 *
	 * @return void
	 */
	public function test_a_mapping_keeps_its_given_id_and_is_insert_only(): void {
		$attributes = [
			'85' => [
				'source'              => 'fixed',
				'value'               => 'Brand',
				'dictionary_value_id' => '5',
				'wc_key'              => '',
			],
		];
		$category   = [
			'description_category_id' => 17028742,
			'type_id'                 => 971047,
		];

		$this->assertTrue( $this->mappings->insert_if_absent( 12, 'Обувь', null, $category, 3, $attributes ) );
		$this->assertFalse( $this->mappings->insert_if_absent( 12, 'Другое имя', null, [ 'subject_id' => 1 ], null, [] ) );

		$row = $this->mappings->find( 12 );
		$this->assertSame( 'Обувь', $row['name'] );
		$this->assertSame( $category, $row['category'] );
		$this->assertSame( 3, $row['profile_id'] );
		$this->assertNull( $row['wc_product_id'] );

		$mapping = $this->mappings->get( 12 );
		$this->assertSame( $category, $mapping->remote_category() );
		$this->assertSame( 3, $mapping->profile_id() );
		$source = $mapping->source_for( '85' );
		$this->assertInstanceOf( Source::class, $source );
		$this->assertSame( '5', $source->dictionary_value_id() );
	}

	/**
	 * A marketplace whose categories are one id is stored the same way, and a mapping with no category reads as having none.
	 *
	 * @return void
	 */
	public function test_the_store_does_not_look_inside_the_category_descriptor(): void {
		$one_id = $this->mappings->create( 'Футболки', null, [ 'subject_id' => 8126 ], null, [] );
		$none   = $this->mappings->create( 'Без категории', null, [], null, [] );

		$this->assertSame( [ 'subject_id' => 8126 ], $this->mappings->get( $one_id )->remote_category() );
		$this->assertTrue( $this->mappings->get( $one_id )->has_category() );
		$this->assertSame( [], $this->mappings->get( $none )->remote_category() );
		$this->assertFalse( $this->mappings->get( $none )->has_category() );
	}

	/**
	 * A category term belongs to one mapping.
	 *
	 * @return void
	 */
	public function test_a_category_term_belongs_to_one_mapping(): void {
		$this->assertTrue( $this->mappings->link_term_if_absent( 40, 12 ) );
		$this->assertFalse( $this->mappings->link_term_if_absent( 40, 13 ) );
		$this->assertSame( 12, $this->mappings->mapping_id_for_term( 40 ) );
		$this->assertNull( $this->mappings->mapping_id_for_term( 41 ) );
	}

	/**
	 * A product-scoped mapping is found by its product and gets a generated id.
	 *
	 * @return void
	 */
	public function test_a_product_scoped_mapping_is_found_by_its_product(): void {
		$this->mappings->insert_if_absent( 12, 'Копия', null, [ 'subject_id' => 1 ], null, [] );
		$id = $this->mappings->create( 'Товар №500', 500, [ 'subject_id' => 2 ], null, [] );

		$this->assertGreaterThan( 12, $id );
		$this->assertSame( $id, $this->mappings->product_scoped_id( 500 ) );
		$this->assertNull( $this->mappings->product_scoped_id( 501 ) );
		$this->assertSame( 500, $this->mappings->find( $id )['wc_product_id'] );
	}

	/**
	 * The list holds only term-bound mappings, by name.
	 *
	 * @return void
	 */
	public function test_the_list_holds_only_term_bound_mappings_by_name(): void {
		$this->mappings->create( 'Ручки', null, [ 'subject_id' => 2 ], null, [] );
		$this->mappings->create( 'Блокноты', null, [ 'subject_id' => 3 ], null, [] );
		$this->mappings->create( 'Товар 9', 9, [ 'subject_id' => 4 ], null, [] );

		$this->assertSame( [ 'Блокноты', 'Ручки' ], array_column( $this->mappings->all_term_bound(), 'name' ) );
	}

	/**
	 * Update changes a term-bound mapping only.
	 *
	 * @return void
	 */
	public function test_update_changes_a_term_bound_mapping_only(): void {
		$id      = $this->mappings->create( 'Ручки', null, [ 'subject_id' => 2 ], null, [] );
		$scoped  = $this->mappings->create( 'Товар 9', 9, [ 'subject_id' => 4 ], null, [] );
		$sources = [
			'85' => [
				'source' => 'fixed',
				'value'  => 'Brand',
			],
		];

		$this->assertTrue( $this->mappings->update( $id, 'Шариковые', [ 'subject_id' => 94100 ], 5, $sources ) );

		$row = $this->mappings->find( $id );
		$this->assertSame( 'Шариковые', $row['name'] );
		$this->assertSame( [ 'subject_id' => 94100 ], $row['category'] );
		$this->assertSame( 5, $row['profile_id'] );
		$this->assertSame( $sources, $row['attributes'] );
		$this->assertFalse( $this->mappings->update( $scoped, 'x', [], null, [] ), 'a product-scoped mapping belongs to the product tab' );
		$this->assertFalse( $this->mappings->update( 999, 'x', [], null, [] ) );
	}

	/**
	 * Replacing terms releases the ones dropped, takes the new ones, and never steals one another mapping holds.
	 *
	 * @return void
	 */
	public function test_replace_terms_releases_takes_and_does_not_steal(): void {
		$first  = $this->mappings->create( 'A', null, [], null, [] );
		$second = $this->mappings->create( 'B', null, [], null, [] );

		$this->assertSame( [], $this->mappings->replace_terms( $first, [ 10, 11 ] ) );
		$this->assertSame( [ 11 ], $this->mappings->replace_terms( $second, [ 11, 12 ] ), '11 belongs to A' );
		$this->assertSame(
			[
				$first  => [ 10, 11 ],
				$second => [ 12 ],
			],
			$this->mappings->terms_by_mapping()
		);

		$this->assertSame( [], $this->mappings->replace_terms( $first, [ 10 ] ) );
		$this->assertSame( $first, $this->mappings->mapping_id_for_term( 10 ) );
		$this->assertNull( $this->mappings->mapping_id_for_term( 11 ), '11 was released' );
	}

	/**
	 * Deleting removes a term-bound mapping and its terms; a product-scoped one is not deleted from here.
	 *
	 * @return void
	 */
	public function test_delete_removes_the_mapping_and_its_terms(): void {
		$id     = $this->mappings->create( 'A', null, [], null, [] );
		$scoped = $this->mappings->create( 'Товар 9', 9, [], null, [] );
		$this->mappings->replace_terms( $id, [ 10 ] );

		$this->assertTrue( $this->mappings->delete( $id ) );
		$this->assertNull( $this->mappings->find( $id ) );
		$this->assertNull( $this->mappings->mapping_id_for_term( 10 ) );
		$this->assertFalse( $this->mappings->delete( $scoped ) );
		$this->assertNotNull( $this->mappings->find( $scoped ) );
	}

	/**
	 * Usage counts guard a delete: profiles used by mappings, mappings used by links.
	 *
	 * @return void
	 */
	public function test_usage_counts(): void {
		$profile = $this->profiles->create( 'P', [] );
		$id      = $this->mappings->create( 'A', null, [], $profile, [] );
		$links   = Sqlite_Tables::table( 'links' );
		$this->db->query( "INSERT INTO {$links} (account_id, wc_product_id, mapping_id) VALUES (1, 5, {$id}), (1, 6, {$id}), (1, 7, NULL)" );

		$this->assertSame( 1, $this->mappings->count_using_profile( $profile ) );
		$this->assertSame( 0, $this->mappings->count_using_profile( $profile + 1 ) );
		$this->assertSame( 2, $this->mappings->count_linked( $links, $id ) );
	}

	/**
	 * A refused seed insert is an exception, not a silent false.
	 *
	 * @return void
	 */
	public function test_a_refused_seed_insert_throws(): void {
		$this->db->query( 'DROP TABLE ' . Sqlite_Tables::table( 'mappings' ) );

		$this->expectException( Persistence_Exception::class );

		$this->mappings->insert_if_absent( 1, 'x', null, [], null, [] );
	}

	/**
	 * A profile seeded with a given id is insert-only.
	 *
	 * @return void
	 */
	public function test_a_profile_is_insert_only(): void {
		$this->assertTrue( $this->profiles->insert_if_absent( 4, 'Обувь', [ 'vat_rate' => '0.2' ] ) );
		$this->assertFalse( $this->profiles->insert_if_absent( 4, 'Другое', [] ) );

		$profile = $this->profiles->get( 4 );
		$this->assertSame( 'Обувь', $profile->name() );
		$this->assertSame( '0.2', $profile->setting( 'vat_rate' ) );
		$this->assertNull( $this->profiles->get( 5 ) );
	}

	/**
	 * Profile CRUD, in name order.
	 *
	 * @return void
	 */
	public function test_profile_crud(): void {
		$b = $this->profiles->create( 'Б', [ 'vat_rate' => '0.2' ] );
		$a = $this->profiles->create( 'А', [] );

		$this->assertSame(
			[ 'А', 'Б' ],
			array_map(
				static function ( $profile ) {
					return $profile->name();
				},
				$this->profiles->all()
			)
		);
		$this->assertSame( '0.2', $this->profiles->get( $b )->setting( 'vat_rate' ) );
		$this->assertTrue( $this->profiles->update( $b, 'Б2', [ 'vat_rate' => '0.1' ] ) );
		$this->assertSame( 'Б2', $this->profiles->get( $b )->name() );
		$this->assertSame( '0.1', $this->profiles->get( $b )->setting( 'vat_rate' ) );
		$this->assertFalse( $this->profiles->update( 999, 'x', [] ) );
		$this->assertTrue( $this->profiles->delete( $a ) );
		$this->assertNull( $this->profiles->get( $a ) );
		$this->assertFalse( $this->profiles->delete( $a ) );
	}

	/**
	 * The price-changed action is the marketplace's own, fires only when a price or currency rule moved, and carries the profile id.
	 *
	 * @return void
	 */
	public function test_a_price_term_change_fires_the_marketplaces_own_action(): void {
		$id = $this->profiles->create( 'P', [ 'price_rule' => [ 'markup' => '10' ], 'vat_rate' => '0.2' ] );

		Actions\expectDone( 'woodev_fake_profile_price_changed' )->once()->with( $id );
		Actions\expectDone( 'woodev_other_profile_price_changed' )->never();

		$this->profiles->update( $id, 'P', [ 'price_rule' => [ 'markup' => '10' ], 'vat_rate' => '0.1' ] );
		$this->profiles->update( $id, 'P', [ 'price_rule' => [ 'markup' => '15' ], 'vat_rate' => '0.1' ] );

		$this->assertSame( 'woodev_fake_profile_price_changed', Wpdb_Profile_Store::price_changed_action( 'fake' ) );
	}
}
