<?php
/**
 * What the mapping an import creates does to the export of a new variant of the same model: import → effective mapping → validation.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Category_Finder;
use Woodev\Framework\Marketplace\Catalog\Card_Content;
use Woodev\Framework\Marketplace\Catalog\Field_Definition;
use Woodev\Framework\Marketplace\Data\Wpdb_Mapping_Store;
use Woodev\Framework\Marketplace\Flows\Import_Category;
use Woodev\Framework\Marketplace\Flows\Import_Mapping;
use Woodev\Tests\Unit\Marketplace\Catalog\Validator_Test_Case;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;
use Woodev\Tests\Unit\Marketplace\Support\Wc_Attribute_Functions;

/**
 * The rules the export validator runs on the mapping the import made: a linked dictionary characteristic must be resolvable, so it is
 * linked only when the marketplace resolves labels locally.
 *
 * @covers \Woodev\Framework\Marketplace\Flows\Import_Mapping
 */
final class ImportedMappingExportTest extends Validator_Test_Case {
	/**
	 * Mappings.
	 *
	 * @var Wpdb_Mapping_Store
	 */
	private $mappings;

	/**
	 * Boots the stubs, the tables and a shop with one category.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\stubTranslationFunctions();
		Wc_Attribute_Functions::install();
		Functions\when( 'get_terms' )->justReturn( [ 71 ] );
		Functions\when( 'get_term_field' )->justReturn( 'Смартфоны' );

		$db = new Sqlite_Wpdb();
		Sqlite_Tables::install( $db );
		$this->mappings = new Wpdb_Mapping_Store( $db, Sqlite_Tables::table( 'mappings' ), Sqlite_Tables::table( 'mapping_terms' ) );
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
	 * Imports a card of a group of three, with a colour and a size, and returns the validation of a new variant of that model.
	 *
	 * @param bool                $resolves_labels Whether the marketplace resolves a dictionary label locally.
	 * @param array<string,mixed> $lookup          The `dictionary_lookup` the validation context carries, if any.
	 * @return \Woodev\Framework\Marketplace\Catalog\Validation_Report
	 */
	private function validation_after_import( bool $resolves_labels, array $lookup ) {
		$writer = new Import_Mapping( $this->mappings, new Import_Category( $this->mappings ), new Category_Finder( new Import_Fake_Schema() ), 'Ozon', $resolves_labels );
		$card   = new Card_Content(
			[ 'offer_id' => 'A-1' ],
			[
				'remote_category' => Import_Fake_Schema::PHONES,
				'category_path'   => [ 'Смартфоны' ],
				'group'           => [
					'key'     => 'model-1',
					'size'    => 3,
					'aspects' => [
						'Цвет'   => 'красный',
						'Размер' => '42',
					],
				],
			]
		);

		$writer->ensure( $card );

		$mapping = $this->mappings->get( $this->mappings->for_category( Import_Fake_Schema::PHONES )['mapping']['mapping_id'] );
		$product = $this->product(
			[
				'get_attribute' => static function ( string $key ): string {
					return [
						'Цвет'   => 'красный',
						'Размер' => '42',
					][ $key ] ?? '';
				},
			]
		);
		$fields  = [
			new Field_Definition( '10096', 'Цвет', false, Field_Definition::TYPE_DICTIONARY ),
			new Field_Definition( '9533', 'Размер', false, Field_Definition::TYPE_STRING ),
		];

		return $this->validate( $product, $mapping, $this->context( [ 'fields' => $fields ] + $lookup ) );
	}

	/**
	 * With no local lookup the dictionary characteristic is left unlinked, so the new variant is not refused for a label nobody can
	 * resolve — the failure a linked «Цвет» would give.
	 *
	 * @return void
	 */
	public function test_a_marketplace_without_a_lookup_exports_the_new_variant_without_the_dictionary_link(): void {
		$report = $this->validation_after_import( false, [] );

		$this->assertSame( [], $this->error_codes( $report ) );
	}

	/**
	 * With a local lookup the dictionary characteristic is linked and resolves through it.
	 *
	 * @return void
	 */
	public function test_a_marketplace_with_a_lookup_resolves_the_linked_dictionary_characteristic(): void {
		$known   = static function ( Field_Definition $field, string $label ): ?string {
			return 'красный' === $label ? '901' : null;
		};
		$unknown = static function ( Field_Definition $field, string $label ): ?string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Callback signature.
			return null;
		};

		$this->assertSame( [], $this->error_codes( $this->validation_after_import( true, [ 'dictionary_lookup' => $known ] ) ) );
		$this->assertSame( [ 'dictionary_value_unresolved' ], $this->error_codes( $this->validation_after_import( true, [ 'dictionary_lookup' => $unknown ] ) ), 'the link is really there: a label nobody knows is refused' );
	}
}
