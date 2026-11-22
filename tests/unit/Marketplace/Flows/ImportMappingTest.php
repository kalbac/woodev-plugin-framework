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
use Woodev\Tests\Unit\Marketplace\Support\Wc_Attribute_Functions;

require_once __DIR__ . '/../Support/wc-product-attribute-stub.php';

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
		Wc_Attribute_Functions::install();

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
	 * @param bool   $resolves_labels Whether the marketplace resolves a dictionary label to a value id locally.
	 * @param string $marketplace     The marketplace's name for the merchant's sentences.
	 * @return Import_Mapping
	 */
	private function writer( bool $resolves_labels = false, string $marketplace = 'Ozon' ): Import_Mapping {
		return new Import_Mapping( $this->mappings, new Import_Category( $this->mappings ), new Category_Finder( $this->schema ), $marketplace, $resolves_labels );
	}

	/**
	 * Builds a card of a type, with a category path and, when given, the aspects of its group.
	 *
	 * @param array<string,int>    $remote  The card's type.
	 * @param string[]             $path    Category names from the root down.
	 * @param array<string,string> $aspects Aspects of the card's group.
	 * @param int                  $size    How many cards the group has.
	 * @param array<int,array>     $characteristics The characteristics the adapter gave: `id` (optional), `name` and `values` each.
	 * @return Card_Content
	 */
	private function card( array $remote, array $path = [ 'Электроника', 'Смартфоны' ], array $aspects = [], int $size = 0, array $characteristics = [] ): Card_Content {
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
				'characteristics' => $characteristics,
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
		$this->assertSame( [], $this->links_of( Import_Fake_Schema::PHONES ), 'a card with no aspects links nothing' );
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

		$note = $this->writer()->ensure( $this->card( Import_Fake_Schema::PHONES, [ 'Электроника', 'Смартфоны' ], [ 'Размер' => '42' ], 2 ) );

		$this->assertSame( '', $note );
		$this->assertSame( $before, $this->mappings->find( $mine ), 'the mapping is as the merchant left it, and no «Размер» link was added' );
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
		$this->assertSame( 'Категория «Гаджеты»: в Ozon карточки разных типов, сопоставление создано по типу «Электроника › Смартфоны».', $second );
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
	 * Point 4: a characteristic the import wrote as a product attribute is linked to it, by name, and the rest is not. A dictionary
	 * characteristic is linked only when the marketplace resolves its labels locally.
	 *
	 * @return void
	 */
	public function test_the_attributes_the_import_wrote_are_linked_to_their_characteristics(): void {
		$this->writer( true )->ensure( $this->card( Import_Fake_Schema::PHONES, [ 'Электроника', 'Смартфоны' ], [ 'Цвет' => 'красный', 'размер' => '42', 'Вариант' => 'A-1' ], 3 ) );

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
			$this->links_of( Import_Fake_Schema::PHONES ),
			'«Цвет» and «Размер» are linked (the case of a name does not matter); «Вариант» is no characteristic and is left out'
		);
	}

	/**
	 * Must 2: a marketplace that cannot resolve a dictionary label locally gets no link on a dictionary characteristic — the export's
	 * validation would refuse the label — while the other characteristics are linked as before.
	 *
	 * @return void
	 */
	public function test_a_dictionary_characteristic_is_not_linked_when_labels_cannot_be_resolved(): void {
		$this->writer( false )->ensure( $this->card( Import_Fake_Schema::PHONES, [ 'Электроника', 'Смартфоны' ], [ 'Цвет' => 'красный', 'Размер' => '42' ], 3 ) );

		$this->assertSame( [ '9533' ], $this->linked_ids( Import_Fake_Schema::PHONES ) );
	}

	/**
	 * Must 1: the first card of a type decides nothing for good — a standalone card made the mapping with no links, a later grouped
	 * card of the same type adds its links to the table the import made, a third adds the one the second lacked.
	 *
	 * @return void
	 */
	public function test_a_later_card_adds_the_links_the_first_card_did_not_have(): void {
		$writer = $this->writer( true );

		$writer->ensure( $this->card( Import_Fake_Schema::PHONES ) );
		$this->assertSame( [], $this->links_of( Import_Fake_Schema::PHONES ), 'a standalone card: nothing to link yet' );

		$writer->ensure( $this->card( Import_Fake_Schema::PHONES, [ 'Электроника', 'Смартфоны' ], [ 'Цвет' => 'красный' ], 3 ) );
		$this->assertSame( [ '10096' ], $this->linked_ids( Import_Fake_Schema::PHONES ) );

		$writer->ensure( $this->card( Import_Fake_Schema::PHONES, [ 'Электроника', 'Смартфоны' ], [ 'Цвет' => 'синий', 'Размер' => '42' ], 3 ) );
		$this->assertSame( [ '10096', '9533' ], $this->linked_ids( Import_Fake_Schema::PHONES ) );
		$this->assertCount( 1, $this->mappings->all_term_bound(), 'still the one mapping' );
	}

	/**
	 * A row that is in the table is never overwritten by a later card.
	 *
	 * @return void
	 */
	public function test_a_later_card_never_overwrites_a_link(): void {
		$writer = $this->writer( true );

		$writer->ensure( $this->card( Import_Fake_Schema::PHONES, [ 'Электроника', 'Смартфоны' ], [ 'Размер' => '42' ], 3 ) );
		$writer->ensure( $this->card( Import_Fake_Schema::PHONES, [ 'Электроника', 'Смартфоны' ], [ 'размер' => '44' ], 3 ) );

		$this->assertSame( 'Размер', $this->links_of( Import_Fake_Schema::PHONES )['9533']['wc_key'] );
	}

	/**
	 * A mapping the merchant has changed since the import made it is the merchant's: a later card adds nothing, whatever was changed.
	 *
	 * @return void
	 */
	public function test_a_mapping_the_merchant_changed_is_not_completed_by_a_later_card(): void {
		foreach ( [ 'name', 'profile', 'table' ] as $change ) {
			$this->setUp();

			$writer = $this->writer( true );
			$writer->ensure( $this->card( Import_Fake_Schema::PHONES, [ 'Электроника', 'Смартфоны' ], [ 'Размер' => '42' ], 3 ) );

			$stored   = $this->mappings->for_category( Import_Fake_Schema::PHONES )['mapping'];
			$name     = 'name' === $change ? 'Мои телефоны' : $stored['name'];
			$profile  = 'profile' === $change ? 7 : $stored['profile_id'];
			$table    = 'table' === $change ? [ '85' => [ 'source' => Source::FIXED, 'value' => 'Acme' ] ] : $stored['attributes'];
			$this->mappings->update( $stored['mapping_id'], $name, $stored['category'], $profile, $table );
			$before = $this->mappings->find( $stored['mapping_id'] );

			$writer->ensure( $this->card( Import_Fake_Schema::PHONES, [ 'Электроника', 'Смартфоны' ], [ 'Цвет' => 'красный' ], 3 ) );

			$this->assertSame( $before, $this->mappings->find( $stored['mapping_id'] ), 'changed: ' . $change );
		}
	}

	/**
	 * Returns the ids of the fields the mapping of a type links, as strings (PHP keeps a numeric key an integer).
	 *
	 * @param array<string,int> $remote The type.
	 * @return string[]
	 */
	private function linked_ids( array $remote ): array {
		return array_map( 'strval', array_keys( $this->links_of( $remote ) ) );
	}

	/**
	 * Returns the attribute sources of the mapping of a type, without the import's mark.
	 *
	 * @param array<string,int> $remote The type.
	 * @return array<string,array<string,string>>
	 */
	private function links_of( array $remote ): array {
		$found = $this->mappings->for_category( $remote );

		$this->assertNotNull( $found );

		return array_diff_key( $found['mapping']['attributes'], [ '_import_created' => true ] );
	}

	/**
	 * A card in a group of one is no longer left without links: the import writes its aspects as attributes like any card's.
	 *
	 * @return void
	 */
	public function test_a_card_alone_in_its_group_links_its_aspects(): void {
		$this->writer()->ensure( $this->card( Import_Fake_Schema::PHONES, [ 'Электроника', 'Смартфоны' ], [ 'Размер' => '42' ], 1 ) );

		$this->assertSame( [ '9533' ], $this->linked_ids( Import_Fake_Schema::PHONES ) );
	}

	/**
	 * The pilot card of #1949: a simple card, no group, no aspect — its characteristics are written as attributes, and the plain ones
	 * are linked (a dictionary one only when labels can be resolved, which the adapter of #131 will allow).
	 *
	 * @return void
	 */
	public function test_a_simple_card_links_its_plain_characteristics(): void {
		$characteristics = [
			[
				'name'   => 'Бренд',
				'values' => [ 'Acme' ],
			],
			[
				'name'   => 'Цвет',
				'values' => [ 'красный' ],
			],
		];

		$this->writer( false )->ensure( $this->card( Import_Fake_Schema::PHONES, [ 'Электроника', 'Смартфоны' ], [], 0, $characteristics ) );

		$this->assertSame(
			[
				'85' => [
					'source' => Source::WC_ATTRIBUTE,
					'wc_key' => 'Бренд',
				],
			],
			$this->links_of( Import_Fake_Schema::PHONES ),
			'«Бренд» is linked; «Цвет» is a dictionary field and waits for the label lookup'
		);
	}

	/**
	 * With the label lookup the dictionary characteristic is linked too: the attribute was written either way.
	 *
	 * @return void
	 */
	public function test_a_dictionary_characteristic_is_linked_once_labels_can_be_resolved(): void {
		$characteristics = [
			[
				'name'   => 'Цвет',
				'values' => [ 'красный' ],
			],
		];

		$this->writer( true )->ensure( $this->card( Import_Fake_Schema::PHONES, [ 'Электроника', 'Смартфоны' ], [], 0, $characteristics ) );

		$this->assertSame( [ '10096' ], $this->linked_ids( Import_Fake_Schema::PHONES ) );
	}

	/**
	 * A variable group: the aspect and the other characteristics are linked together, under the names the attributes have.
	 *
	 * @return void
	 */
	public function test_a_group_links_its_aspects_and_its_other_characteristics(): void {
		$characteristics = [
			[
				'name'   => 'Размер',
				'values' => [ '42' ],
			],
			[
				'name'   => 'Бренд',
				'values' => [ 'Acme' ],
			],
		];

		$this->writer( true )->ensure( $this->card( Import_Fake_Schema::PHONES, [ 'Электроника', 'Смартфоны' ], [ 'Цвет' => 'красный' ], 3, $characteristics ) );

		$this->assertSame( [ '10096', '9533', '85' ], $this->linked_ids( Import_Fake_Schema::PHONES ) );
	}

	/**
	 * A characteristic that names its field id is linked to that field, whatever the field is called; an id the schema does not
	 * have links nothing — it is not matched by name behind the adapter's back.
	 *
	 * @return void
	 */
	public function test_a_characteristic_with_a_field_id_is_linked_by_the_id(): void {
		$characteristics = [
			[
				'id'     => '9533',
				'name'   => 'Габарит',
				'values' => [ '42' ],
			],
			[
				'id'     => '404',
				'name'   => 'Бренд',
				'values' => [ 'Acme' ],
			],
		];

		$this->writer()->ensure( $this->card( Import_Fake_Schema::PHONES, [ 'Электроника', 'Смартфоны' ], [], 0, $characteristics ) );

		$this->assertSame(
			[
				'9533' => [
					'source' => Source::WC_ATTRIBUTE,
					'wc_key' => 'Габарит',
				],
			],
			$this->links_of( Import_Fake_Schema::PHONES )
		);
	}

	/**
	 * Two characteristics whose names WordPress makes one slug of (here: punctuation) are two attributes, and each link names the
	 * attribute that holds its own value — the second one under the prefixed name the writer stores it by.
	 *
	 * @return void
	 */
	public function test_characteristics_that_share_a_slug_are_linked_under_the_names_the_attributes_have(): void {
		Wc_Attribute_Functions::lossy_slug();

		$characteristics = [
			[
				'id'     => '9533',
				'name'   => 'Размер, см',
				'values' => [ '42' ],
			],
			[
				'id'     => '85',
				'name'   => 'Размер см',
				'values' => [ 'Acme' ],
			],
		];

		$this->writer()->ensure( $this->card( Import_Fake_Schema::PHONES, [ 'Электроника', 'Смартфоны' ], [], 0, $characteristics ) );

		$this->assertSame(
			[
				'9533' => [
					'source' => Source::WC_ATTRIBUTE,
					'wc_key' => 'Размер, см',
				],
				'85'   => [
					'source' => Source::WC_ATTRIBUTE,
					'wc_key' => '[85] Размер см',
				],
			],
			$this->links_of( Import_Fake_Schema::PHONES )
		);
	}

	/**
	 * The product the attributes go to is asked: an attribute of the merchant's that holds the slug makes the link name the writer's
	 * prefixed attribute, not the merchant's.
	 *
	 * @return void
	 */
	public function test_a_link_names_the_attribute_beside_a_merchants_that_holds_the_slug(): void {
		Wc_Attribute_Functions::lossy_slug();

		$merchant = new \WC_Product_Attribute();
		$merchant->set_name( 'Размер см' );

		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_attributes' )->andReturn( [ sanitize_title( 'Размер см' ) => $merchant ] );

		$characteristics = [
			[
				'id'     => '9533',
				'name'   => 'Размер, см',
				'values' => [ '42' ],
			],
		];

		$this->writer()->ensure( $this->card( Import_Fake_Schema::PHONES, [ 'Электроника', 'Смартфоны' ], [], 0, $characteristics ), $product );

		$this->assertSame( '[9533] Размер, см', $this->links_of( Import_Fake_Schema::PHONES )['9533']['wc_key'] );
	}

	/**
	 * A mapping the merchant made is not touched by a card with characteristics either.
	 *
	 * @return void
	 */
	public function test_a_merchant_mapping_is_not_changed_by_a_card_with_characteristics(): void {
		$mine = $this->mappings->create( 'Мои телефоны', null, Import_Fake_Schema::PHONES, 9, [ '85' => [ 'source' => Source::FIXED, 'value' => 'Acme' ] ] );
		$this->mappings->link_term_if_absent( 61, $mine );
		$before = $this->mappings->find( $mine );

		$this->writer( true )->ensure(
			$this->card(
				Import_Fake_Schema::PHONES,
				[ 'Электроника', 'Смартфоны' ],
				[],
				0,
				[
					[
						'name'   => 'Размер',
						'values' => [ '42' ],
					],
				]
			)
		);

		$this->assertSame( $before, $this->mappings->find( $mine ) );
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
		$this->assertSame( [], $this->links_of( Import_Fake_Schema::PHONES ) );
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

	/**
	 * Without a marketplace name the sentence says «на маркетплейсе».
	 *
	 * @return void
	 */
	public function test_the_note_falls_back_to_a_neutral_marketplace(): void {
		$writer = $this->writer( false, '' );
		$writer->ensure( $this->card( Import_Fake_Schema::PHONES, [ 'Электроника', 'Гаджеты' ] ) );

		$this->assertStringContainsString( ': на маркетплейсе карточки разных типов', $writer->ensure( $this->card( Import_Fake_Schema::TABLETS, [ 'Электроника', 'Гаджеты' ] ) ) );
	}
}
