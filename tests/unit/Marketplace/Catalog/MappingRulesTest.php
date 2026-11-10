<?php
/**
 * Export validator: the category and field-source rules of spec 5.1.1.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Catalog;

use Woodev\Framework\Marketplace\Catalog\Field_Definition;
use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Source;
use Woodev\Framework\Marketplace\Catalog\Validation_Report;

/**
 * One group of cases per rule. The baseline product and context pass everything, so each case names the single reason it fails.
 */
final class MappingRulesTest extends Validator_Test_Case {
	/**
	 * The baseline passes, so the cases below prove each rule on its own.
	 *
	 * @return void
	 */
	public function test_the_baseline_product_passes_every_rule(): void {
		$report = $this->validate( $this->product(), $this->mapping(), $this->context() );

		$this->assertSame( array(), $report->issues() );
		$this->assertTrue( $report->is_valid() );
	}

	/**
	 * Rule: category mapped.
	 *
	 * @return void
	 */
	public function test_category_not_mapped(): void {
		$report = $this->validate( $this->product(), new Mapping( null, array(), null ), $this->context( array( 'marketplace_name' => 'Ozon' ) ) );
		$error  = $report->first_error();

		$this->assertSame( 'category_not_mapped', $error['code'] );
		$this->assertSame( 'Категория товара не сопоставлена с категорией Ozon — откройте «Сопоставление».', $error['message'] );
		$this->assertSame( Validation_Report::FIX_MAPPING, $error['fix'] );
		$this->assertSame( array( 'category_not_mapped' ), $this->error_codes( $report ), 'the category-dependent rules stay silent without a category' );
	}

	/**
	 * Rule: the mapped category is still in the tree.
	 *
	 * @return void
	 */
	public function test_category_gone_from_the_tree(): void {
		$gone = $this->validate( $this->product(), $this->mapping(), $this->context( array( 'remote_category_exists' => false ) ) );
		$this->assertSame( array( 'category_gone' ), $this->error_codes( $gone ) );
		$this->assertSame( Validation_Report::FIX_MAPPING, $gone->first_error()['fix'] );

		$unknown_context = $this->context();
		unset( $unknown_context['remote_category_exists'] );
		$this->assertTrue( $this->validate( $this->product(), $this->mapping(), $unknown_context )->is_valid(), 'no cached tree yet is not an error' );
	}

	/**
	 * Rule: a required field without a source; an optional one is not checked.
	 *
	 * @return void
	 */
	public function test_required_field_without_a_source(): void {
		$fields = array(
			new Field_Definition( '85', 'Бренд', true, Field_Definition::TYPE_DICTIONARY ),
			new Field_Definition( '22232', 'Код ТН ВЭД', true, Field_Definition::TYPE_STRING ),
			new Field_Definition( '9999', 'Заметка', false, Field_Definition::TYPE_STRING ),
		);

		$report = $this->validate( $this->product(), $this->mapping(), $this->context( array( 'fields' => $fields ) ) );

		$this->assertSame( array( 'required_field_has_no_source' ), $this->error_codes( $report ) );
		$this->assertSame( '22232', $report->first_error()['field_id'] );
		$this->assertStringContainsString( 'Код ТН ВЭД', $report->first_error()['message'] );

		$incomplete = $this->mapping( array( '22232' => new Source( Source::META, '', '' ) ) );
		$this->assertSame( array( 'required_field_has_no_source' ), $this->error_codes( $this->validate( $this->product(), $incomplete, $this->context( array( 'fields' => $fields ) ) ) ) );
	}

	/**
	 * Rule: the source must give THIS product a value, or fall back to the stored default.
	 *
	 * @return void
	 */
	public function test_required_field_with_a_source_that_gives_the_product_no_value(): void {
		$fields = array( new Field_Definition( '23536', 'Цвет', true, Field_Definition::TYPE_STRING ) );
		$source = new Source( Source::WC_ATTRIBUTE, '', 'pa_color' );

		$empty = $this->validate( $this->product(), $this->mapping( array( '23536' => $source ) ), $this->context( array( 'fields' => $fields ) ) );
		$this->assertSame( array( 'required_field_empty' ), $this->error_codes( $empty ) );
		$this->assertSame( Validation_Report::FIX_PRODUCT, $empty->first_error()['fix'] );

		$has_value = $this->product(
			array(
				'get_attribute' => static function ( string $key ): string {
					return 'pa_color' === $key ? 'Красный' : '';
				},
			)
		);
		$this->assertTrue( $this->validate( $has_value, $this->mapping( array( '23536' => $source ) ), $this->context( array( 'fields' => $fields ) ) )->is_valid() );

		$fallback = new Source( Source::WC_ATTRIBUTE, 'Без цвета', 'pa_color' );
		$this->assertTrue( $this->validate( $this->product(), $this->mapping( array( '23536' => $fallback ) ), $this->context( array( 'fields' => $fields ) ) )->is_valid(), 'the v1 default is the fallback' );
	}

	/**
	 * Rule: the list of required fields is unknown when the category schema is not cached.
	 *
	 * @return void
	 */
	public function test_unknown_category_fields_block_the_export_but_not_the_mapping_scope(): void {
		$context = $this->context();
		unset( $context['fields'] );

		$this->assertSame( array( 'category_fields_unknown' ), $this->error_codes( $this->validate( $this->product(), $this->mapping(), $context ) ) );

		$context['scope'] = 'mapping';
		$this->assertTrue( $this->validate( $this->product(), $this->mapping(), $context )->is_valid() );
	}

	/**
	 * The mapping scope runs only the category and the source checks, so a bare product mock is enough.
	 *
	 * @return void
	 */
	public function test_mapping_scope_skips_the_product_rules(): void {
		$bare = \Mockery::mock( 'WC_Product' );

		$report = $this->validate( $bare, $this->mapping(), array( 'scope' => 'mapping' ) );

		$this->assertTrue( $report->is_valid() );
	}

	/**
	 * Rule: dictionary attributes resolve to a dictionary value id.
	 *
	 * @return void
	 */
	public function test_dictionary_values(): void {
		$fields = array( new Field_Definition( '23536', 'Цвет', false, Field_Definition::TYPE_DICTIONARY ) );
		$colour = $this->product(
			array(
				'get_attributes' => array( 'pa_color' => new Fake_Attribute( array( 'Красный', 'Синий' ), true ) ),
			)
		);
		$source = new Source( Source::WC_ATTRIBUTE, '', 'pa_color' );

		$no_lookup = $this->validate( $colour, $this->mapping( array( '23536' => $source ) ), $this->context( array( 'fields' => $fields ) ) );
		$this->assertSame( array( 'dictionary_value_unresolved' ), $this->error_codes( $no_lookup ) );
		$this->assertSame( Validation_Report::FIX_DICTIONARY, $no_lookup->first_error()['fix'] );
		$this->assertStringContainsString( 'Красный», «Синий', $no_lookup->first_error()['message'] );
		$this->assertStringContainsString( 'Найти в словаре', $no_lookup->first_error()['message'] );

		$partial     = $this->context(
			array(
				'fields'            => $fields,
				'dictionary_lookup' => static function ( Field_Definition $field, string $label ): ?string {
					return 'Красный' === $label ? '901' : null;
				},
			)
		);
		$one_missing = $this->validate( $colour, $this->mapping( array( '23536' => $source ) ), $partial );
		$this->assertStringContainsString( '«Синий»', $one_missing->first_error()['message'] );
		$this->assertStringNotContainsString( 'Красный', $one_missing->first_error()['message'] );

		$resolved = $this->context(
			array(
				'fields'            => $fields,
				'dictionary_lookup' => static function ( Field_Definition $field, string $label ): ?string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Callback signature.
					return '900';
				},
			)
		);
		$this->assertTrue( $this->validate( $colour, $this->mapping( array( '23536' => $source ) ), $resolved )->is_valid() );
	}

	/**
	 * A fixed free-text value on a dictionary field is refused (never sent as text); a chosen id passes; an empty optional field is left alone.
	 *
	 * @return void
	 */
	public function test_dictionary_free_text_is_refused_and_a_chosen_id_passes(): void {
		$fields = array( new Field_Definition( '23536', 'Цвет', false, Field_Definition::TYPE_DICTIONARY ) );

		$text = $this->mapping( array( '23536' => new Source( Source::FIXED, 'Красный' ) ) );
		$this->assertSame( array( 'dictionary_value_unresolved' ), $this->error_codes( $this->validate( $this->product(), $text, $this->context( array( 'fields' => $fields ) ) ) ) );

		$chosen = $this->mapping( array( '23536' => new Source( Source::FIXED, 'Красный', '', '901' ) ) );
		$this->assertTrue( $this->validate( $this->product(), $chosen, $this->context( array( 'fields' => $fields ) ) )->is_valid() );

		$empty = $this->mapping( array( '23536' => new Source( Source::WC_ATTRIBUTE, '', 'pa_color' ) ) );
		$this->assertTrue( $this->validate( $this->product(), $empty, $this->context( array( 'fields' => $fields ) ) )->is_valid(), 'an optional field with no value is not sent' );
	}
}
