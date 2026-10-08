<?php
/**
 * Unit: Instance_Field_Conditions turns a `show_if` declaration of a shipping-method instance form into the
 * data attribute the browser handler reads, and ignores a declaration it cannot honour.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Instance_Field_Conditions;

final class InstanceFieldConditionsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	public function id( string $key ): string {
		return 'woocommerce_cdek_' . $key;
	}

	/** @param array<string,mixed> $show_if the declaration of the field `target`. */
	private function attribute( $show_if ): ?string {
		$fields = Instance_Field_Conditions::apply(
			[
				'tariff' => [ 'type' => 'select' ],
				'insure' => [ 'type' => 'checkbox' ],
				'target' => [ 'type' => 'text', 'show_if' => $show_if ],
			],
			[ $this, 'id' ]
		);

		return $fields['target']['custom_attributes'][ Instance_Field_Conditions::ATTRIBUTE ] ?? null;
	}

	public function test_a_select_condition_with_a_list_becomes_a_resolved_json_attribute(): void {
		$json = $this->attribute( [ 'setting' => 'tariff', 'operator' => 'in', 'value' => [ 137, '138' ] ] );

		$this->assertSame(
			[
				'relation'   => 'AND',
				'conditions' => [ [ 'field' => 'woocommerce_cdek_tariff', 'operator' => 'in', 'value' => [ '137', '138' ] ] ],
			],
			json_decode( (string) $json, true )
		);
	}

	public function test_the_operator_defaults_to_equals_and_a_checkbox_value_is_a_string(): void {
		$json = json_decode( (string) $this->attribute( [ 'setting' => 'insure', 'value' => true ] ), true );

		$this->assertSame( [ [ 'field' => 'woocommerce_cdek_insure', 'operator' => '=', 'value' => 'yes' ] ], $json['conditions'] );
	}

	public function test_a_group_keeps_its_relation_and_every_member(): void {
		$json = json_decode(
			(string) $this->attribute(
				[
					'relation' => 'or',
					[ 'setting' => 'tariff', 'operator' => 'not_in', 'value' => '5' ],
					[ 'setting' => 'insure', 'operator' => '!=', 'value' => 'no' ],
				]
			),
			true
		);

		$this->assertSame( 'OR', $json['relation'] );
		$this->assertSame( [ '5' ], $json['conditions'][0]['value'] );
		$this->assertSame( 'woocommerce_cdek_insure', $json['conditions'][1]['field'] );
	}

	public function test_a_closure_is_called_with_the_field_key(): void {
		$seen = null;
		$json = $this->attribute(
			static function ( string $key ) use ( &$seen ): array {
				$seen = $key;
				return [ 'setting' => 'tariff', 'value' => '1' ];
			}
		);

		$this->assertSame( 'target', $seen );
		$this->assertNotNull( $json );
	}

	/**
	 * @dataProvider invalid_declarations
	 * @param mixed $declaration declaration to ignore.
	 */
	public function test_a_declaration_it_cannot_honour_is_ignored_and_the_field_stays_as_it_was( $declaration ): void {
		$this->assertNull( $this->attribute( $declaration ) );
	}

	/** @return array<string,array{0:mixed}> */
	public function invalid_declarations(): array {
		return [
			'not an array'               => [ 'tariff' ],
			'empty'                      => [ [] ],
			'unknown operator'           => [ [ 'setting' => 'tariff', 'operator' => '>', 'value' => '1' ] ],
			'unknown controlling field'  => [ [ 'setting' => 'nope', 'value' => '1' ] ],
			'depends on itself'          => [ [ 'setting' => 'target', 'value' => '1' ] ],
			'empty setting'              => [ [ 'setting' => '', 'value' => '1' ] ],
			'array for equals'           => [ [ 'setting' => 'tariff', 'operator' => '=', 'value' => [ '1' ] ] ],
			'empty list'                 => [ [ 'setting' => 'tariff', 'operator' => 'in', 'value' => [] ] ],
			'missing list'               => [ [ 'setting' => 'tariff', 'operator' => 'in' ] ],
			'nested list'                => [ [ 'setting' => 'tariff', 'operator' => 'in', 'value' => [ [ '1' ] ] ] ],
			'bad relation'               => [ [ 'relation' => 'XOR', [ 'setting' => 'tariff', 'value' => '1' ] ] ],
			'one bad member spoils all'  => [ [ [ 'setting' => 'tariff', 'value' => '1' ], [ 'setting' => 'nope', 'value' => '1' ] ] ],
			'closure returning a string' => [ static function (): string { return 'x'; } ],
		];
	}

	public function test_fields_without_a_declaration_are_returned_untouched_and_existing_attributes_survive(): void {
		$fields = [
			'tariff' => [ 'type' => 'select' ],
			'target' => [ 'type' => 'text', 'custom_attributes' => [ 'data-x' => '1' ], 'show_if' => [ 'setting' => 'tariff', 'value' => '1' ] ],
		];

		$result = Instance_Field_Conditions::apply( $fields, [ $this, 'id' ] );

		$this->assertSame( $fields['tariff'], $result['tariff'] );
		$this->assertSame( '1', $result['target']['custom_attributes']['data-x'] );
		$this->assertArrayHasKey( Instance_Field_Conditions::ATTRIBUTE, $result['target']['custom_attributes'] );
	}
}
