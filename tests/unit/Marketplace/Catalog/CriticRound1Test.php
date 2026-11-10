<?php
/**
 * Regression tests for critic round 1 of PR #40 (findings 1-6).
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Catalog;

use Woodev\Framework\Marketplace\Catalog\Field_Definition;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Catalog\Rules\Images_Rule;
use Woodev\Framework\Marketplace\Catalog\Source;

/**
 * Each test names the finding it pins and fails on head 06bd1e3.
 */
final class CriticRound1Test extends Validator_Test_Case {
	/**
	 * Finding 1: a migrated `FIELD parent_name` source gives a variation its parent's name in the FULL scope.
	 *
	 * @return void
	 */
	public function test_f1_migrated_parent_name_source_resolves_in_the_full_validator(): void {
		$fields  = array(
			new Field_Definition( '85', 'Бренд', true, Field_Definition::TYPE_DICTIONARY ),
			new Field_Definition( '9048', 'Название модели', true, Field_Definition::TYPE_STRING ),
		);
		$mapping = $this->mapping( array( '9048' => new Source( Source::FIELD, '', 'parent_name' ) ) );
		$parent  = $this->product( array( 'get_name' => 'Диван Лофт' ) );

		$variation = $this->product( array( 'get_name' => 'Диван Лофт - красный' ) );
		$context   = $this->context(
			array(
				'fields'         => $fields,
				'parent_product' => $parent,
			)
		);
		$this->assertSame( array(), $this->validate( $variation, $mapping, $context )->errors() );

		$nameless = $this->product( array( 'get_name' => '' ) );
		$this->assertSame( array( 'required_field_empty' ), $this->error_codes( $this->validate( $nameless, $mapping, $this->context( array( 'fields' => $fields ) ) ) ), 'a simple product reads its own name, which is empty here' );
	}

	/**
	 * Finding 1: description and dimension fields fall back to the parent like v1.
	 *
	 * @return void
	 */
	public function test_f1_parent_fallback_fields(): void {
		$fields  = array( new Field_Definition( '4191', 'Аннотация', true, Field_Definition::TYPE_MULTILINE ) );
		$mapping = $this->mapping( array( '4191' => new Source( Source::FIELD, '', 'description' ) ) );
		$child   = $this->product( array( 'get_description' => '' ) );
		$parent  = $this->product( array( 'get_description' => 'Описание родителя' ) );

		$this->assertTrue(
			$this->validate(
				$child,
				$mapping,
				$this->context(
					array(
						'fields'         => array_merge( $fields, $this->context()['fields'] ),
						'parent_product' => $parent,
					)
				)
			)->is_valid()
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
	}

	/**
	 * Finding 2: an explicit profile currency is checked even when shop and cabinet currencies match.
	 *
	 * @return void
	 */
	public function test_f2_explicit_profile_currency_is_checked_when_shop_and_cabinet_match(): void {
		$profile = new Profile( null, 'П', array( 'currency_rule' => 'fixed:USD' ) );
		$report  = $this->validate( $this->product(), $this->mapping(), $this->context(), $profile );

		$this->assertSame( array( 'currency_mismatch' ), $this->error_codes( $report ) );
		$this->assertStringContainsString( 'USD', $report->first_error()['message'] );
	}

	/**
	 * Finding 2: a conversion rule needs a supported shape and a rate above zero.
	 *
	 * @dataProvider invalid_currency_rules
	 * @param string $rule Rule.
	 * @return void
	 */
	public function test_f2_invalid_conversion_rules_are_refused( string $rule ): void {
		$profile = new Profile( null, 'П', array( 'currency_rule' => $rule ) );
		$context = $this->context( array( 'account_currency' => 'CNY' ) );

		$this->assertSame( array( 'currency_mismatch' ), $this->error_codes( $this->validate( $this->product(), $this->mapping(), $context, $profile ) ), $rule );
	}

	/**
	 * Rules that must fail.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function invalid_currency_rules(): array {
		return array(
			'zero rate'      => array( 'rate:0' ),
			'negative rate'  => array( 'rate:-2' ),
			'text rate'      => array( 'rate:abc' ),
			'unknown shape'  => array( 'something' ),
			'empty field'    => array( 'field:' ),
			'bad fixed code' => array( 'fixed:' ),
		);
	}

	/**
	 * Finding 3: siblings with the same CHOSEN dictionary id (any source kind) are the same aspect, whatever their labels.
	 *
	 * @return void
	 */
	public function test_f3_same_chosen_dictionary_id_means_equal_aspects(): void {
		$colour = new Source( Source::WC_ATTRIBUTE, '', 'pa_color', '901' );
		$mapped = $this->mapping( array( '10096' => $colour ) );
		$label  = static function ( string $value ): array {
			return array(
				'get_attributes' => array( 'pa_color' => new Fake_Attribute( array( $value ), true ) ),
				'get_attribute'  => $value,
			);
		};
		$fields = array(
			new Field_Definition( '85', 'Бренд', true, Field_Definition::TYPE_DICTIONARY ),
			new Field_Definition( '10096', 'Цвет', false, Field_Definition::TYPE_DICTIONARY ),
		);

		$context = $this->context(
			array(
				'fields'           => $fields,
				'aspect_field_ids' => array( '10096' ),
				'siblings'         => array(
					array(
						'product' => $this->product( array_merge( $label( 'Black' ), array( 'get_name' => 'Чёрный' ) ) ),
						'mapping' => $mapped,
					),
				),
			)
		);

		$this->assertSame( array( 'variation_aspects_equal' ), $this->error_codes( $this->validate( $this->product( $label( 'Red' ) ), $mapped, $context ) ) );
	}

	/**
	 * Finding 3: two labels the lookup resolves to the same id are one aspect; two different ids are two.
	 *
	 * @return void
	 */
	public function test_f3_labels_are_compared_by_their_looked_up_ids(): void {
		$mapped = $this->mapping( array( '10096' => new Source( Source::WC_ATTRIBUTE, '', 'pa_color' ) ) );
		$label  = static function ( string $value ): array {
			return array(
				'get_attributes' => array( 'pa_color' => new Fake_Attribute( array( $value ), true ) ),
				'get_attribute'  => $value,
			);
		};
		$fields = array(
			new Field_Definition( '85', 'Бренд', true, Field_Definition::TYPE_DICTIONARY ),
			new Field_Definition( '10096', 'Цвет', false, Field_Definition::TYPE_DICTIONARY ),
		);
		$build  = function ( array $ids ) use ( $mapped, $label, $fields ): array {
			return $this->context(
				array(
					'fields'            => $fields,
					'aspect_field_ids'  => array( '10096' ),
					'dictionary_lookup' => static function ( Field_Definition $field, string $text ) use ( $ids ): ?string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Callback signature.
						return $ids[ $text ] ?? null;
					},
					'siblings'          => array(
						array(
							'product' => $this->product( array_merge( $label( 'Black' ), array( 'get_name' => 'Чёрный' ) ) ),
							'mapping' => $mapped,
						),
					),
				)
			);
		};

		$same = $this->validate(
			$this->product( $label( 'Red' ) ),
			$mapped,
			$build(
				array(
					'Red'   => '901',
					'Black' => '901',
				)
			)
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$this->assertSame( array( 'variation_aspects_equal' ), $this->error_codes( $same ) );

		$different = $this->validate(
			$this->product( $label( 'Red' ) ),
			$mapped,
			$build(
				array(
					'Red'   => '901',
					'Black' => '902',
				)
			)
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$this->assertTrue( $different->is_valid() );
	}

	/**
	 * Finding 5: a custom multi-value attribute is read as its options, so each one is looked up, not «Red | Blue».
	 *
	 * @return void
	 */
	public function test_f5_custom_multi_value_attribute_is_looked_up_per_option(): void {
		$asked   = array();
		$fields  = array(
			new Field_Definition( '85', 'Бренд', true, Field_Definition::TYPE_DICTIONARY ),
			new Field_Definition( '10096', 'Цвет', false, Field_Definition::TYPE_DICTIONARY ),
		);
		$mapping = $this->mapping( array( '10096' => new Source( Source::WC_ATTRIBUTE, '', 'Цвет' ) ) );
		$product = $this->product(
			array(
				'get_attributes' => array( 'Цвет' => new Fake_Attribute( array( 'Red', 'Blue' ), false ) ),
				'get_attribute'  => 'Red | Blue',
			)
		);
		$context = $this->context(
			array(
				'fields'            => $fields,
				'dictionary_lookup' => static function ( Field_Definition $field, string $text ) use ( &$asked ): ?string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Callback signature.
					$asked[] = $text;

					return in_array( $text, array( 'Red', 'Blue' ), true ) ? '1' : null;
				},
			)
		);

		$this->assertTrue( $this->validate( $product, $mapping, $context )->is_valid() );
		$this->assertSame( array( 'Red', 'Blue' ), $asked );
	}

	/**
	 * Finding 6: trailing-dot hosts and numeric IP spellings are local.
	 *
	 * @dataProvider local_hosts
	 * @param string $url URL.
	 * @return void
	 */
	public function test_f6_disguised_local_hosts_are_refused( string $url ): void {
		$this->assertFalse( Images_Rule::is_public_https( $url ), $url );
	}

	/**
	 * Disguised local URLs.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function local_hosts(): array {
		return array(
			'localhost with dot'  => array( 'https://localhost./a.jpg' ),
			'private ip with dot' => array( 'https://192.168.1.1./a.jpg' ),
			'short loopback'      => array( 'https://127.1/a.jpg' ),
			'decimal loopback'    => array( 'https://2130706433/a.jpg' ),
			'hex loopback'        => array( 'https://0x7f.0.0.1/a.jpg' ),
			'local suffix + dot'  => array( 'https://shop.local./a.jpg' ),
		);
	}

	/**
	 * Finding 6: a real public host with a trailing dot is still public.
	 *
	 * @return void
	 */
	public function test_f6_a_public_host_with_a_trailing_dot_is_public(): void {
		$this->assertTrue( Images_Rule::is_public_https( 'https://cdn.example.ru./a.jpg' ) );
	}

	/**
	 * The report carries the unsupported image source error (finding 4's explicit handling).
	 *
	 * @return void
	 */
	public function test_f4_an_unsupported_image_source_is_an_error(): void {
		$report = $this->validate( $this->product(), $this->mapping(), $this->context( array( 'unsupported_image_sources' => array( 'sku' ) ) ) );

		$this->assertSame( array( 'image_source_unsupported' ), $this->error_codes( $report ) );
	}

	/**
	 * R2 finding 2: a `field:KEY` currency rule needs a number above zero in that field (fails on 08374fc: every key passed).
	 *
	 * @dataProvider unusable_field_values
	 * @param mixed $value Meta value.
	 * @return void
	 */
	public function test_r2_field_currency_rule_needs_a_usable_value( $value ): void {
		$profile = new Profile( null, 'П', array( 'currency_rule' => 'field:_cny_price' ) );
		$context = $this->context( array( 'account_currency' => 'CNY' ) );
		$product = $this->product( array( 'get_meta' => $value ) );

		$this->assertSame( array( 'currency_mismatch' ), $this->error_codes( $this->validate( $product, $this->mapping(), $context, $profile ) ) );
	}

	/**
	 * Unusable field values.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public function unusable_field_values(): array {
		return array(
			'absent'   => array( '' ),
			'zero'     => array( '0' ),
			'negative' => array( '-3' ),
			'text'     => array( 'abc' ),
		);
	}

	/**
	 * R2 finding 2: a positive number in the field passes.
	 *
	 * @return void
	 */
	public function test_r2_field_currency_rule_with_a_positive_value_passes(): void {
		$profile = new Profile( null, 'П', array( 'currency_rule' => 'field:_cny_price' ) );
		$context = $this->context( array( 'account_currency' => 'CNY' ) );

		$this->assertTrue( $this->validate( $this->product( array( 'get_meta' => '88.5' ) ), $this->mapping(), $context, $profile )->is_valid() );
	}

	/**
	 * R2 finding 5: a custom attribute is found by its name when WooCommerce stored it under the slug (fails on 08374fc).
	 *
	 * @return void
	 */
	public function test_r2_custom_attribute_found_by_name_under_a_normalized_key(): void {
		$asked   = array();
		$fields  = array(
			new Field_Definition( '85', 'Бренд', true, Field_Definition::TYPE_DICTIONARY ),
			new Field_Definition( '10096', 'Цвет', false, Field_Definition::TYPE_DICTIONARY ),
		);
		$mapping = $this->mapping( array( '10096' => new Source( Source::WC_ATTRIBUTE, '', 'Colour' ) ) );
		$product = $this->product(
			array(
				'get_attributes' => array( 'colour' => new Fake_Attribute( array( 'Red', 'Blue' ), false, 'Colour' ) ),
				'get_attribute'  => 'Red | Blue',
			)
		);
		$context = $this->context(
			array(
				'fields'            => $fields,
				'dictionary_lookup' => static function ( Field_Definition $field, string $text ) use ( &$asked ): ?string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Callback signature.
					$asked[] = $text;

					return '1';
				},
			)
		);

		$this->assertTrue( $this->validate( $product, $mapping, $context )->is_valid() );
		$this->assertSame( array( 'Red', 'Blue' ), $asked );
	}
}
