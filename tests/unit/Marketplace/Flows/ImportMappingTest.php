<?php
/**
 * The mapping an import creates for the shop category of a card that has none.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Category_Finder;
use Woodev\Framework\Marketplace\Catalog\Card_Content;
use Woodev\Framework\Marketplace\Catalog\Source;
use Woodev\Framework\Marketplace\Data\Wpdb_Mapping_Store;
use Woodev\Framework\Marketplace\Flows\Import_Category;
use Woodev\Framework\Marketplace\Flows\Import_Mapping;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;

/**
 * @covers \Woodev\Framework\Marketplace\Flows\Import_Mapping
 */
final class ImportMappingTest extends TestCase {
	/**
	 * Mappings.
	 *
	 * @var Wpdb_Mapping_Store
	 */
	private $mappings;

	/**
	 * The catalogue the importer reads.
	 *
	 * @var Import_Fake_Schema
	 */
	private $schema;

	/**
	 * The shop's categories: `[ id, name, parent ]` each, in the order they were made.
	 *
	 * @var array<int,array{0:int,1:string,2:int}>
	 */
	private $terms = [];

	/**
	 * Boots the stubs, the tables and a shop that has the category «Электроника» only.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\stubTranslationFunctions();

		$this->terms = [ [ 60, 'Электроника', 0 ] ];

		Functions\when( 'get_terms' )->alias(
			function ( array $args ): array {
				$found = [];

				foreach ( $this->terms as $term ) {
					if ( $args['name'] === $term[1] && $args['parent'] === $term[2] ) {
						$found[] = $term[0];
					}
				}

				return $found;
			}
		);
		Functions\when( 'wp_insert_term' )->alias(
			function ( string $name, string $taxonomy, array $args ): array {
				$id            = 100 + count( $this->terms );
				$this->terms[] = [ $id, $name, (int) $args['parent'] ];

				return [ 'term_id' => $id ];
			}
		);
		Functions\when( 'get_term_field' )->alias(
			function ( string $field, int $term_id ): string {
				foreach ( $this->terms as $term ) {
					if ( $term_id === $term[0] ) {
						return $term[1];
					}
				}

				return '';
			}
		);

		$db             = new Sqlite_Wpdb();
		$this->schema   = new Import_Fake_Schema();
		$this->mappings = new Wpdb_Mapping_Store( $db, Sqlite_Tables::table( 'mappings' ), Sqlite_Tables::table( 'mapping_terms' ) );

		Sqlite_Tables::install( $db );
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
	 * Builds the writer over the stores and the catalogue of the test.
	 *
	 * @return Import_Mapping
	 */
	private function writer(): Import_Mapping {
		return new Import_Mapping( $this->mappings, new Import_Category( $this->mappings ), new Category_Finder( $this->schema ) );
	}

	/**
	 * Builds a card of a type, with a category path and, when given, the aspects of its group.
	 *
	 * @param array<string,int>    $remote  The card's type.
	 * @param string[]             $path    Category names from the root down.
	 * @param array<string,string> $aspects Aspects of the card's group.
	 * @param int                  $size    How many cards the group has.
	 * @return Card_Content
	 */
	private function card( array $remote, array $path = [ 'Электроника', 'Смартфоны' ], array $aspects = [], int $size = 0 ): Card_Content {
		$group = [] === $aspects ? [] : [
			'key'     => 'model-1',
			'size'    => $size,
			'aspects' => $aspects,
		];

		return new Card_Content(
			[ 'offer_id' => 'A-1' ],
			[
				'remote_category' => $remote,
				'category_path'   => $path,
				'group'           => $group,
			]
		);
	}

	/**
	 * Returns the id of the shop category made or found for a name.
	 *
	 * @param string $name Category name.
	 * @return int
	 */
	private function term_named( string $name ): int {
		foreach ( $this->terms as $term ) {
			if ( $name === $term[1] ) {
				return $term[0];
			}
		}

		$this->fail( 'No category «' . $name . '».' );
	}

	/**
	 * Point 1, a new category: the import made it, and now it is mapped to the card's type, with no profile.
	 *
	 * @return void
	 */
	public function test_a_category_the_import_creates_gets_a_mapping_with_no_profile(): void {
		$note = $this->writer()->ensure( $this->card( Import_Fake_Schema::PHONES ) );

		$term_id = $this->term_named( 'Смартфоны' );
		$found   = $this->mappings->for_category( Import_Fake_Schema::PHONES );

		$this->assertSame( '', $note );
		$this->assertNotNull( $found, 'the card\'s type finds its mapping backwards' );
		$this->assertSame( [ $term_id ], $found['term_ids'] );
		$this->assertSame( 'Смартфоны', $found['mapping']['name'] );
		$this->assertNull( $found['mapping']['wc_product_id'], 'a term-bound mapping, the kind the «Сопоставление» page lists' );
		$this->assertNull( $found['mapping']['profile_id'], 'the profile of a mapping made by hand: none' );
		$this->assertSame( [], $found['mapping']['attributes'], 'a card with no aspects links nothing' );
	}

	/**
	 * Point 1, an existing category with no mapping: it is reused, not duplicated, and mapped.
	 *
	 * @return void
	 */
	public function test_an_existing_category_without_a_mapping_gets_one(): void {
		$this->terms[] = [ 77, 'Смартфоны', 60 ];

		$this->writer()->ensure( $this->card( Import_Fake_Schema::PHONES ) );

		$found = $this->mappings->for_category( Import_Fake_Schema::PHONES );

		$this->assertNotNull( $found );
		$this->assertSame( [ 77 ], $found['term_ids'] );
		$this->assertCount( 2, $this->terms, 'no second «Смартфоны»' );
	}

	/**
	 * Point 2: a mapping for the card's type is the merchant's word; the import neither changes it nor makes another.
	 *
	 * @return void
	 */
	public function test_a_mapping_that_exists_for_the_type_is_never_changed(): void {
		$mine = $this->mappings->create( 'Мои телефоны', null, Import_Fake_Schema::PHONES, 9, [ '85' => [ 'source' => Source::FIXED, 'value' => 'Acme' ] ] );
		$this->mappings->link_term_if_absent( 61, $mine );
		$before = $this->mappings->find( $mine );

		$note = $this->writer()->ensure( $this->card( Import_Fake_Schema::PHONES, [ 'Электроника', 'Смартфоны' ], [ 'Цвет' => 'красный' ], 2 ) );

		$this->assertSame( '', $note );
		$this->assertSame( $before, $this->mappings->find( $mine ), 'the mapping is as the merchant left it' );
		$this->assertCount( 1, $this->mappings->all_term_bound(), 'and no other was made' );
		$this->assertCount( 1, $this->terms, 'the card\'s own path was not even looked up' );
	}

	/**
	 * Point 2, the other way: a shop category the merchant mapped to another type keeps that mapping.
	 *
	 * @return void
	 */
	public function test_a_category_the_merchant_mapped_to_another_type_keeps_its_mapping(): void {
		$this->terms[] = [ 77, 'Смартфоны', 60 ];
		$mine          = $this->mappings->create( 'Планшеты как телефоны', null, Import_Fake_Schema::TABLETS, 4, [] );
		$this->mappings->link_term_if_absent( 77, $mine );
		$before = $this->mappings->find( $mine );

		$this->writer()->ensure( $this->card( Import_Fake_Schema::PHONES ) );

		$this->assertSame( $before, $this->mappings->find( $mine ) );
		$this->assertSame( [ 77 ], $this->mappings->terms_by_mapping()[ $mine ] );
		$this->assertNull( $this->mappings->for_category( Import_Fake_Schema::PHONES ), 'the category is in one mapping only' );
		$this->assertCount( 1, $this->mappings->all_term_bound() );
	}

	/**
	 * Point 3: cards of two types in one shop category — the first type maps it, the second only says so.
	 *
	 * @return void
	 */
	public function test_cards_of_different_types_in_one_category_map_by_the_first_type(): void {
		$writer = $this->writer();

		$first  = $writer->ensure( $this->card( Import_Fake_Schema::PHONES, [ 'Электроника', 'Гаджеты' ] ) );
		$second = $writer->ensure( $this->card( Import_Fake_Schema::TABLETS, [ 'Электроника', 'Гаджеты' ] ) );

		$this->assertSame( '', $first );
		$this->assertSame( 'Категория «Гаджеты»: на маркетплейсе карточки разных типов, сопоставление создано по типу «Электроника › Смартфоны».', $second );
		$this->assertCount( 1, $this->mappings->all_term_bound(), 'one mapping, by the first type' );
		$this->assertNotNull( $this->mappings->for_category( Import_Fake_Schema::PHONES ) );
		$this->assertNull( $this->mappings->for_category( Import_Fake_Schema::TABLETS ) );
	}

	/**
	 * A third card of the type that was skipped says it again: each card's own note tells the merchant.
	 *
	 * @return void
	 */
	public function test_every_card_of_the_skipped_type_carries_the_note(): void {
		$writer = $this->writer();
		$writer->ensure( $this->card( Import_Fake_Schema::PHONES, [ 'Электроника', 'Гаджеты' ] ) );

		$this->assertNotSame( '', $writer->ensure( $this->card( Import_Fake_Schema::TABLETS, [ 'Электроника', 'Гаджеты' ] ) ) );
		$this->assertNotSame( '', $writer->ensure( $this->card( Import_Fake_Schema::TABLETS, [ 'Электроника', 'Гаджеты' ] ) ) );
	}

	/**
	 * Point 4: a characteristic the import wrote as a product attribute is linked to it, by name, and the rest is not.
	 *
	 * @return void
	 */
	public function test_the_attributes_the_import_wrote_are_linked_to_their_characteristics(): void {
		$this->writer()->ensure( $this->card( Import_Fake_Schema::PHONES, [ 'Электроника', 'Смартфоны' ], [ 'Цвет' => 'красный', 'размер' => '42', 'Вариант' => 'A-1' ], 3 ) );

		$attributes = $this->mappings->for_category( Import_Fake_Schema::PHONES )['mapping']['attributes'];

		$this->assertSame(
			[
				'10096' => [
					'source' => Source::WC_ATTRIBUTE,
					'wc_key' => 'Цвет',
				],
				'9533'  => [
					'source' => Source::WC_ATTRIBUTE,
					'wc_key' => 'размер',
				],
			],
			$attributes,
			'«Цвет» and «Размер» are linked (the case of a name does not matter); «Вариант» is no characteristic and is left out'
		);
	}

	/**
	 * The import writes aspects only for a group of several cards: a lone card in a group links nothing.
	 *
	 * @return void
	 */
	public function test_a_card_alone_in_its_group_links_no_attribute(): void {
		$this->writer()->ensure( $this->card( Import_Fake_Schema::PHONES, [ 'Электроника', 'Смартфоны' ], [ 'Цвет' => 'красный' ], 1 ) );

		$this->assertSame( [], $this->mappings->for_category( Import_Fake_Schema::PHONES )['mapping']['attributes'] );
	}

	/**
	 * With the fields not cached the mapping is still made — only the attribute table stays empty, and no request is made.
	 *
	 * @return void
	 */
	public function test_a_schema_with_no_cached_fields_makes_the_mapping_with_no_links(): void {
		$this->schema->warm = false;

		$this->writer()->ensure( $this->card( Import_Fake_Schema::PHONES, [ 'Электроника', 'Смартфоны' ], [ 'Цвет' => 'красный' ], 2 ) );

		$found = $this->mappings->for_category( Import_Fake_Schema::PHONES );

		$this->assertNotNull( $found );
		$this->assertSame( [], $found['mapping']['attributes'] );
	}

	/**
	 * A card with no type, or no category path, maps nothing — an empty path must not map the shop's default category.
	 *
	 * @return void
	 */
	public function test_a_card_without_a_type_or_a_path_maps_nothing(): void {
		$this->writer()->ensure( $this->card( [] ) );
		$this->writer()->ensure( $this->card( Import_Fake_Schema::PHONES, [] ) );

		$this->assertSame( [], $this->mappings->all_term_bound() );
		$this->assertCount( 1, $this->terms, 'no category was made either' );
	}
}
