<?php
/**
 * The pure parts of the mappings view: category search and the two form readers.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Mappings
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Mappings;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Category_Finder;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mapping_Input;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mapping_Messages;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Profile_Choices;
use Woodev\Framework\Marketplace\Admin\Pages\Mappings\Profile_Input;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Field_Price_Source;
use Woodev\Tests\Unit\TestCase;

require_once __DIR__ . '/Mappings_Harness.php';

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Mappings\Category_Finder
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Mappings\Mapping_Input
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Mappings\Profile_Input
 */
final class MappingInputsTest extends TestCase {
	/**
	 * Stubs the sanitizers the readers call.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $value ) {
				return trim( strip_tags( (string) $value ) );
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	// -- Category_Finder ------------------------------------------------------------------------

	/**
	 * A search needs every word, ignores case, and never offers a disabled node.
	 *
	 * @return void
	 */
	public function test_search_matches_every_word_and_skips_disabled_nodes(): void {
		$finder = new Category_Finder( new Fake_Tree_Schema() );

		$this->assertSame( [ 'Одежда › Обувь › Ботинки' ], array_column( $finder->search( 'ОДЕЖДА боти' ), 'label' ) );
		$this->assertCount( 2, $finder->search( '' ) );
		$this->assertSame( [], $finder->search( 'Закрытая' ), 'a disabled node is never offered' );
		$this->assertSame( [], $finder->search( 'платья ботинки' ), 'every word must be in the same path' );
	}

	/**
	 * The key follows the identity only, so display data cannot change what is stored and compared.
	 *
	 * @return void
	 */
	public function test_the_key_is_the_identity_in_any_key_order(): void {
		$finder = new Category_Finder( new Fake_Tree_Schema() );
		$found  = $finder->find( Category_Finder::key_of( [ 'type_id' => 2, 'category_id' => 11 ] ) );

		$this->assertNotNull( $found );
		$this->assertSame( [ 'category_id' => 11, 'type_id' => 2 ], $found['identity'] );
		$this->assertArrayNotHasKey( 'path', $found['identity'] );
		$this->assertSame( $found, $finder->find_by_identity( [ 'category_id' => 11, 'type_id' => 2 ] ) );
	}

	/**
	 * A forged, stale or empty key resolves to nothing.
	 *
	 * @return void
	 */
	public function test_an_unknown_key_or_a_disabled_identity_resolves_to_nothing(): void {
		$finder = new Category_Finder( new Fake_Tree_Schema() );

		$this->assertNull( $finder->find( 'deadbeefdeadbeef' ) );
		$this->assertNull( $finder->find_by_identity( [] ) );
		$this->assertNull( $finder->find_by_identity( [ 'category_id' => 12, 'type_id' => 3 ] ), 'a disabled node cannot be saved' );
	}

	/**
	 * The schema is read once per request.
	 *
	 * @return void
	 */
	public function test_the_schema_is_read_once(): void {
		$schema = new Fake_Tree_Schema();
		$finder = new Category_Finder( $schema );

		$finder->search( 'a' );
		$finder->find( 'x' );
		$finder->is_loaded();

		$this->assertSame( 1, $schema->calls['categories'] );
	}

	// -- Mapping_Input --------------------------------------------------------------------------

	/**
	 * Builds the fields map the editor hands the reader.
	 *
	 * @return array<string,array{name:string,dict:bool}>
	 */
	private function fields(): array {
		return [
			'1' => [ 'name' => 'Бренд', 'dict' => false ],
			'2' => [ 'name' => 'Цвет', 'dict' => true ],
		];
	}

	/**
	 * Reads a mapping body with the usual collaborators.
	 *
	 * @param array<string,mixed>      $body     Body.
	 * @param array<string,mixed>|null $category Category identity.
	 * @param array<string,mixed>|null $stored   Stored mapping.
	 * @return Mapping_Input
	 */
	private function read( array $body, ?array $category = [ 'category_id' => 10 ], ?array $stored = null ): Mapping_Input {
		// A table travels with the key of the category it was loaded for, as the view sends it.
		if ( isset( $body['attributes'] ) && null !== $category && ! array_key_exists( 'attributes_category', $body ) ) {
			$body['attributes_category'] = Category_Finder::key_of( $category );
		}

		return Mapping_Input::from_request(
			$body,
			$category,
			$this->fields(),
			static function ( int $term_id ): bool {
				return $term_id < 100;
			},
			[ 'sku' => 'Артикул' ],
			$stored
		);
	}

	/**
	 * A full valid body is read into the stored shape; terms that are not categories are dropped.
	 *
	 * @return void
	 */
	public function test_a_valid_mapping_body_is_read_into_the_stored_shape(): void {
		$input = $this->read(
			[
				'name'       => ' Платья ',
				'terms'      => [ 5, '6', 5, 500, 'x' ],
				'profile_id' => '3',
				'attributes' => [
					'1' => [ 'source' => 'field', 'key' => 'sku' ],
					'2' => [ 'source' => 'fixed', 'value' => 'красный', 'dictionary_value_id' => '7' ],
					'9' => [ 'source' => 'fixed', 'value' => 'not a field of the category' ],
				],
			]
		);

		$this->assertTrue( $input->is_valid() );
		$this->assertSame( 'Платья', $input->name() );
		$this->assertSame( [ 5, 6 ], $input->term_ids() );
		$this->assertSame( 3, $input->profile_id() );
		$this->assertSame(
			[
				'1' => [ 'source' => 'field', 'wc_key' => 'sku' ],
				'2' => [ 'source' => 'fixed', 'value' => 'красный', 'dictionary_value_id' => '7' ],
			],
			$input->attributes()
		);
	}

	/**
	 * Each way a body can be wrong gives one sentence and saves nothing.
	 *
	 * @return void
	 */
	public function test_invalid_bodies_report_why(): void {
		$cases = [
			'empty name'            => [ [ 'name' => ' ' ], 1 ],
			'long name'             => [ [ 'name' => str_repeat( 'я', 201 ) ], 1 ],
			'terms not a list'      => [ [ 'name' => 'A', 'terms' => 'x' ], 1 ],
			'unknown source kind'   => [ [ 'name' => 'A', 'attributes' => [ '1' => [ 'source' => 'php' ] ] ], 1 ],
			'field not offered'     => [ [ 'name' => 'A', 'attributes' => [ '1' => [ 'source' => 'field', 'key' => 'secret' ] ] ], 1 ],
			'no key for a meta'     => [ [ 'name' => 'A', 'attributes' => [ '1' => [ 'source' => 'meta' ] ] ], 1 ],
			'dictionary not chosen' => [ [ 'name' => 'A', 'attributes' => [ '2' => [ 'source' => 'fixed', 'value' => 'x' ] ] ], 1 ],
			'fixed value empty'     => [ [ 'name' => 'A', 'attributes' => [ '1' => [ 'source' => 'fixed' ] ] ], 1 ],
			'row not an object'     => [ [ 'name' => 'A', 'attributes' => [ '1' => 'x' ] ], 1 ],
		];

		foreach ( $cases as $label => list( $body, $count ) ) {
			$input = $this->read( array_merge( [ 'attributes' => [] ], $body ) );

			$this->assertFalse( $input->is_valid(), $label );
			$this->assertCount( $count, $input->errors(), $label );
		}
	}

	/**
	 * A save that sends no table keeps the stored sources for the same category and refuses to guess for another.
	 *
	 * @return void
	 */
	public function test_an_absent_table_never_clears_the_stored_sources(): void {
		$stored = [
			'category'   => [ 'category_id' => 10 ],
			'attributes' => [ '1' => [ 'source' => 'meta', 'wc_key' => '_brand' ] ],
		];

		$same = $this->read( [ 'name' => 'A' ], [ 'category_id' => 10 ], $stored );
		$this->assertTrue( $same->is_valid() );
		$this->assertSame( $stored['attributes'], $same->attributes() );

		$other = $this->read( [ 'name' => 'A' ], [ 'category_id' => 11 ], $stored );
		$this->assertFalse( $other->is_valid(), 'a new category needs its table' );

		$none = $this->read( [ 'name' => 'A' ], null, $stored );
		$this->assertTrue( $none->is_valid() );
		$this->assertSame( [], $none->attributes(), 'no category means no sources' );
	}

	/**
	 * A row the merchant did not touch is kept whole, including what the form cannot show.
	 *
	 * @return void
	 */
	public function test_an_untouched_row_keeps_what_the_form_cannot_show(): void {
		$stored = [
			'category'   => [ 'category_id' => 10 ],
			'attributes' => [ '1' => [ 'source' => 'meta', 'wc_key' => '_brand', 'value' => 'fallback' ] ],
		];

		$input = $this->read( [ 'name' => 'A', 'attributes' => [ '1' => [ 'source' => 'meta', 'key' => '_brand' ] ] ], [ 'category_id' => 10 ], $stored );

		$this->assertSame( $stored['attributes'], $input->attributes() );
	}

	/**
	 * A table loaded for another category (a late response) is refused, not saved under the current one.
	 *
	 * @return void
	 */
	public function test_a_table_loaded_for_another_category_is_refused(): void {
		$table = [ '1' => [ 'source' => 'fixed', 'value' => 'Zara' ] ];

		$stale = $this->read( [ 'name' => 'A', 'attributes' => $table, 'attributes_category' => Category_Finder::key_of( [ 'category_id' => 11 ] ) ] );
		$this->assertFalse( $stale->is_valid() );
		$this->assertSame( [ Mapping_Messages::attributes_of_other_category() ], $stale->errors() );
		$this->assertSame( [], $stale->attributes() );

		$unbound = Mapping_Input::from_request( [ 'name' => 'A', 'attributes' => $table ], [ 'category_id' => 10 ], $this->fields(), static function (): bool {
			return true;
		}, [ 'sku' => 'Артикул' ] );
		$this->assertFalse( $unbound->is_valid(), 'a table that names no category is refused too' );

		$fresh = $this->read( [ 'name' => 'A', 'attributes' => $table ] );
		$this->assertTrue( $fresh->is_valid() );
		$this->assertSame( 'Zara', $fresh->attributes()['1']['value'] );
	}

	// -- Profile_Input --------------------------------------------------------------------------

	/**
	 * Reads a profile body.
	 *
	 * @param array<string,mixed> $body   Body.
	 * @param array<string,mixed> $stored Stored settings.
	 * @param bool                $field  Whether the marketplace has a price source.
	 * @return Profile_Input
	 */
	private function profile( array $body, array $stored = [], bool $field = false ): Profile_Input {
		return Profile_Input::from_request( $body, Profile_Choices::standard(), $field ? new Fake_Field_Price_Source() : null, $stored );
	}

	/**
	 * A profile with only a name takes the default sources.
	 *
	 * @return void
	 */
	public function test_a_name_alone_gives_the_default_sources(): void {
		$input = $this->profile( [ 'name' => 'Основной' ] );

		$this->assertTrue( $input->is_valid() );
		$this->assertSame( 'sku', $input->settings()['offer_id_source'] );
		$this->assertSame( 'gallery', $input->settings()['images_source'] );
		$this->assertArrayNotHasKey( 'currency_rule', $input->settings() );
		$this->assertArrayNotHasKey( 'price_rule', $input->settings() );
	}

	/**
	 * Each wrong profile value is named.
	 *
	 * @return void
	 */
	public function test_invalid_profile_values_report_why(): void {
		$cases = [
			'no name'              => [ [] ],
			'custom field no key'  => [ [ 'name' => 'A', 'barcode_source' => 'meta' ] ],
			'source not offered'   => [ [ 'name' => 'A', 'offer_id_source' => 'gtin' ] ],
			'fallback not number'  => [ [ 'name' => 'A', 'fallback_weight' => 'heavy' ] ],
			'fallback zero'        => [ [ 'name' => 'A', 'fallback_width' => '0' ] ],
			'vat not listed'       => [ [ 'name' => 'A', 'vat_rate' => '0.33' ] ],
			'rate not number'      => [ [ 'name' => 'A', 'currency_kind' => 'rate', 'currency_rate' => 'x' ] ],
			'unknown currency'     => [ [ 'name' => 'A', 'currency_kind' => 'bitcoin' ] ],
			'field without source' => [ [ 'name' => 'A', 'currency_kind' => 'field', 'currency_field' => '_price_eur' ] ],
			'bad price rule'       => [ [ 'name' => 'A', 'price_override' => true, 'price_base' => 'nonsense' ] ],
		];

		foreach ( $cases as $label => list( $body ) ) {
			$this->assertFalse( $this->profile( $body )->is_valid(), $label );
		}
	}

	/**
	 * A marketplace's own currency rule is accepted only when its price source handles the shape.
	 *
	 * @return void
	 */
	public function test_the_marketplace_currency_rule_needs_its_price_source(): void {
		$input = $this->profile( [ 'name' => 'A', 'currency_kind' => 'field', 'currency_field' => '_price_eur' ], [], true );

		$this->assertTrue( $input->is_valid() );
		$this->assertSame( 'field:_price_eur', $input->settings()['currency_rule'] );
	}

	/**
	 * Comma decimals work, and a legacy unit survives until the merchant changes it.
	 *
	 * @return void
	 */
	public function test_decimals_and_legacy_values(): void {
		$input = $this->profile(
			[
				'name'            => 'A',
				'fallback_weight' => '1,5',
				'currency_kind'   => 'rate',
				'currency_rate'   => '12,5',
				'unit_weight'     => 'lb',
				'unit_dimension'  => 'cm',
			],
			[ 'units' => [ 'weight' => 'lb' ] ]
		);

		$this->assertTrue( $input->is_valid() );
		$this->assertSame( 1.5, $input->settings()['fallback_weight'] );
		$this->assertSame( 'rate:12.5', $input->settings()['currency_rule'] );
		$this->assertSame( [ 'weight' => 'lb', 'dimension' => 'cm' ], $input->settings()['units'] );
	}

	/**
	 * A valid price override is stored in the rule's own words.
	 *
	 * @return void
	 */
	public function test_a_price_override_is_stored_as_the_rule_reads_it(): void {
		$input = $this->profile( [ 'name' => 'A', 'price_override' => true, 'price_markup_kind' => 'percent', 'price_markup' => '10,5', 'price_min_percent' => '80' ] );

		$this->assertTrue( $input->is_valid() );
		$this->assertSame( 'percent', $input->settings()['price_rule']['markup_kind'] );
		$this->assertSame( '10.5', $input->settings()['price_rule']['markup'] );
		$this->assertSame( '80', $input->settings()['price_rule']['min_percent'] );
	}

	/**
	 * The keys a profile body may hold come from the choices.
	 *
	 * @return void
	 */
	public function test_the_body_keys_cover_the_sources_and_their_custom_keys(): void {
		$keys = Profile_Input::body_keys( Profile_Choices::standard() );

		$this->assertContains( 'offer_id_source', $keys );
		$this->assertContains( 'offer_id_source_key', $keys );
		$this->assertContains( 'price_min_percent', $keys );
		$this->assertNotContains( 'profile_id', $keys );
	}
}
