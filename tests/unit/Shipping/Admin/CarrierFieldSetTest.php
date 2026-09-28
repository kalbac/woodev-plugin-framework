<?php
/**
 * Unit: a carrier's own order fields for one tariff (#710 spec D7, O13, card #973).
 *
 * The carrier declares them in the Settings API's vocabulary; the framework turns the declaration
 * into a real settings handler, so what is pinned here is the three things the wizard leans on:
 * the definitions the React `ControlField` renders, the server-side check of what comes back, and
 * the round trip through the order's meta (one key per field, `yes` / `no` for a boolean).
 *
 * @package Woodev\Tests\Unit\Shipping\Admin
 */

namespace Woodev\Tests\Unit\Shipping\Admin;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Carrier_Field_Set;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__ ) . '/Order/order-persistence-fixtures.php';

/**
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Carrier_Field_Set
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Orders_Provider::get_order_fields
 */
final class CarrierFieldSetTest extends TestCase {

	/** @var array<int,array<string,mixed>> the fake post meta table: order id => key => value. */
	private $meta = [];

	/** @var array<int,array<string,mixed>> the contexts the carrier's declaration was asked with. */
	private $asked = [];

	protected function setUp(): void {
		parent::setUp();

		$this->meta  = [];
		$this->asked = [];

		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = [] ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);
		Functions\when( 'wc_clean' )->alias(
			static function ( $value ) {
				return is_string( $value ) ? trim( $value ) : $value;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			function ( int $post_id, string $key ) {
				return $this->meta[ $post_id ][ $key ] ?? '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( int $post_id, string $key, $value ) {
				$this->meta[ $post_id ][ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( int $post_id, string $key ) {
				unset( $this->meta[ $post_id ][ $key ] );

				return true;
			}
		);
	}

	/**
	 * The declaration used by most tests: a float with a floor, a required select with a default,
	 * a toggle, and a text field only shown for one package type.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function declaration(): array {
		return [
			'declared_value' => [
				'meta_key'    => '_cdek_declared_value',
				'type'        => 'float',
				'control'     => 'number',
				'name'        => 'Объявленная ценность',
				'description' => 'Сумма страховки.',
				'min'         => 0,
				'step'        => 0.01,
				'tooltip'     => 'В рублях',
			],
			'package_type'   => [
				'meta_key' => '_cdek_package_type',
				'name'     => 'Упаковка',
				'options'  => [
					'box'      => 'Коробка',
					'envelope' => 'Конверт',
				],
				'default'  => 'box',
				'required' => true,
			],
			'fragile'        => [
				'meta_key' => '_cdek_fragile',
				'control'  => 'toggle',
				'name'     => 'Хрупкое',
			],
			'box_note'       => [
				'meta_key' => '_cdek_box_note',
				'name'     => 'Что в коробке',
				'show_if'  => [
					'setting'  => 'package_type',
					'operator' => '=',
					'value'    => 'box',
				],
			],
		];
	}

	/**
	 * @param callable|null $declare the carrier's `order_fields` callable; null = none declared.
	 * @return Orders_Provider
	 */
	private function provider( ?callable $declare ): Orders_Provider {
		return Orders_Provider::create(
			'cdek',
			'СДЭК',
			'_cdek_marker',
			[ 'cdek_courier', 'cdek_pickup' ],
			null === $declare ? [] : [ 'order_fields' => $declare ]
		);
	}

	private function set( ?array $declaration = null ): Carrier_Field_Set {
		$declaration = $declaration ?? $this->declaration();

		return Carrier_Field_Set::for_rate(
			$this->provider(
				function ( array $context ) use ( $declaration ): array {
					$this->asked[] = $context;

					return $declaration;
				}
			),
			'cdek_courier',
			0
		);
	}

	private function order(): \WC_Order {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );

		return $order;
	}

	private function quietly( callable $run ) {
		$previous = ini_set( 'error_log', '/dev/null' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- silence the diagnostic line.

		try {
			return $run();
		} finally {
			ini_set( 'error_log', false === $previous ? '' : $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}
	}

	public function test_a_carrier_that_declares_nothing_asks_for_nothing(): void {
		$set = Carrier_Field_Set::for_rate( $this->provider( null ), 'cdek_courier', 0 );

		$this->assertTrue( $set->is_empty() );
		$this->assertSame( [], $set->to_schema() );
		$this->assertSame( [ 'values' => [], 'errors' => [] ], $set->normalize( [ 'declared_value' => 100 ] ) );
	}

	public function test_the_declaration_is_asked_for_the_tariff_with_its_context(): void {
		$this->set();

		$this->assertCount( 1, $this->asked );
		$this->assertSame(
			[
				'provider_id' => 'cdek',
				'method_id'   => 'cdek_courier',
				'instance_id' => 0,
				'rate_id'     => 'cdek_courier',
				'is_pickup'   => false,
				'method'      => null,
			],
			$this->asked[0]
		);
	}

	public function test_a_declaration_that_throws_or_is_not_an_array_reads_as_no_fields(): void {
		$throwing = $this->provider(
			static function (): array {
				throw new \RuntimeException( 'boom' );
			}
		);
		$scalar   = $this->provider(
			static function () {
				return 'nope';
			}
		);

		$this->quietly(
			function () use ( $throwing, $scalar ): void {
				$this->assertTrue( Carrier_Field_Set::for_rate( $throwing, 'cdek_courier', 0 )->is_empty() );
				$this->assertTrue( Carrier_Field_Set::for_rate( $scalar, 'cdek_courier', 0 )->is_empty() );
			}
		);
	}

	public function test_a_provider_refuses_an_order_fields_declaration_that_is_not_callable(): void {
		$this->expectException( \Woodev\Framework\Shipping\Exceptions\Shipping_Exception::class );

		Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek_courier' ], [ 'order_fields' => 'no_such_function_973' ] );
	}

	public function test_the_schema_is_what_the_settings_page_controls_render_in_declaration_order(): void {
		$schema = $this->set()->to_schema();

		$this->assertSame( [ 'declared_value', 'package_type', 'fragile', 'box_note' ], array_column( $schema, 'id' ) );

		$value = $schema[0];

		$this->assertSame( 'number', $value['controlType'] );
		$this->assertSame( 'float', $value['type'] );
		$this->assertSame( 'Объявленная ценность', $value['name'] );
		$this->assertSame( 'Сумма страховки.', $value['description'] );
		$this->assertSame( 'В рублях', $value['tooltip'] );
		$this->assertEquals( 0, $value['min'] );
		$this->assertSame( 0.01, $value['step'] );

		$package = $schema[1];

		$this->assertSame( 'select', $package['controlType'], 'options without a control read as a select' );
		$this->assertSame( [ 'box' => 'Коробка', 'envelope' => 'Конверт' ], $package['options'] );
		$this->assertSame( 'box', $package['value'], 'the declared default is the field\'s starting value' );
		$this->assertTrue( $package['required'] );

		$this->assertSame( 'toggle', $schema[2]['controlType'] );
		$this->assertSame( 'boolean', $schema[2]['type'], 'a toggle without a type reads as a boolean' );
		$this->assertArrayHasKey( 'show_if', $schema[3] );
	}

	public function test_a_definition_that_cannot_be_registered_is_skipped_and_logged_not_fatal(): void {
		// The Settings API itself reports a type it does not know.
		Functions\when( '_doing_it_wrong' )->justReturn( null );

		$set = $this->quietly(
			function (): Carrier_Field_Set {
				return $this->set(
					[
						'no_key'   => [ 'name' => 'Без ключа' ],
						'Bad Id!'  => [ 'meta_key' => '_x', 'name' => 'Плохой id' ],
						'good'     => [ 'meta_key' => '_cdek_good', 'name' => 'Хорошее' ],
						'unusable' => [ 'meta_key' => '_cdek_unusable', 'type' => 'no-such-type' ],
					]
				);
			}
		);

		$this->assertSame( [ 'good' ], $set->field_ids() );
		$this->assertSame( [ 'good' => '_cdek_good' ], $set->meta_keys() );
	}

	public function test_normalize_reads_only_declared_ids_and_types_the_values(): void {
		$result = $this->set()->normalize(
			[
				'declared_value' => ' 1500.5 ',
				'package_type'   => 'envelope',
				'fragile'        => true,
				'secret_extra'   => 'must not reach the order',
			]
		);

		$this->assertSame( [], $result['errors'] );
		$this->assertSame( 1500.5, $result['values']['declared_value'], 'a numeric string becomes the float the setting declares' );
		$this->assertSame( 'envelope', $result['values']['package_type'] );
		$this->assertTrue( $result['values']['fragile'] );
		$this->assertArrayNotHasKey( 'secret_extra', $result['values'] );
	}

	public function test_a_field_left_out_takes_its_declared_default_and_a_required_one_without_is_reported(): void {
		$set = $this->set(
			[
				'package_type' => [ 'meta_key' => '_a', 'options' => [ 'box' => 'Коробка' ], 'default' => 'box', 'required' => true ],
				'contact'      => [ 'meta_key' => '_b', 'name' => 'Контакт', 'required' => true ],
			]
		);

		$result = $set->normalize( [] );

		$this->assertSame( 'box', $result['values']['package_type'] );
		$this->assertCount( 1, $result['errors'] );
		$this->assertSame( 'carrier_fields.contact', $result['errors'][0]['field'] );
		$this->assertSame( 'invalid_carrier_field', $result['errors'][0]['code'] );
		$this->assertSame( 'Обязательное поле.', $result['errors'][0]['message'] );
	}

	public function test_a_value_the_declaration_refuses_is_reported_on_its_own_field(): void {
		$result = $this->set()->normalize( [ 'declared_value' => '-5', 'package_type' => 'crate' ] );

		$fields = array_column( $result['errors'], 'field' );

		$this->assertContains( 'carrier_fields.declared_value', $fields, 'below the declared min' );
		$this->assertContains( 'carrier_fields.package_type', $fields, 'not one of the declared options' );
		$this->assertArrayNotHasKey( 'declared_value', $result['values'] );
	}

	public function test_a_field_hidden_by_show_if_is_neither_checked_nor_kept(): void {
		$set = $this->set(
			[
				'package_type' => [ 'meta_key' => '_a', 'options' => [ 'box' => 'Коробка', 'envelope' => 'Конверт' ], 'default' => 'box' ],
				'box_note'     => [
					'meta_key' => '_b',
					'name'     => 'Что в коробке',
					'required' => true,
					'show_if'  => [ 'setting' => 'package_type', 'operator' => '=', 'value' => 'box' ],
				],
			]
		);

		$hidden = $set->normalize( [ 'package_type' => 'envelope', 'box_note' => 'книги' ] );

		$this->assertSame( [], $hidden['errors'], 'a hidden required field does not block the order' );
		$this->assertSame( [ 'package_type' => 'envelope' ], $hidden['values'] );

		$shown = $set->normalize( [ 'package_type' => 'box' ] );

		$this->assertSame( 'carrier_fields.box_note', $shown['errors'][0]['field'], 'shown, it is required again' );
	}

	public function test_the_carriers_own_validate_callback_has_the_last_word(): void {
		$set = $this->set(
			[
				'code' => [
					'meta_key'         => '_a',
					'name'             => 'Код',
					'validate'         => static function ( $value ): bool {
						return 1 === preg_match( '/^[A-Z]{3}$/', (string) $value );
					},
					'validate_message' => 'Код — три заглавные буквы.',
				],
			]
		);

		$this->assertSame( [], $set->normalize( [ 'code' => 'ABC' ] )['errors'] );
		$this->assertSame( 'Код — три заглавные буквы.', $set->normalize( [ 'code' => 'abc' ] )['errors'][0]['message'] );
	}

	public function test_persist_writes_one_meta_per_field_and_a_boolean_as_yes_no(): void {
		$set    = $this->set();
		$result = $set->normalize( [ 'declared_value' => '99.5', 'package_type' => 'box', 'fragile' => false, 'box_note' => 'книги' ] );

		$set->persist( $this->order(), $result['values'] );

		$this->assertSame(
			[
				'_cdek_declared_value' => 99.5,
				'_cdek_package_type'   => 'box',
				'_cdek_fragile'        => 'no',
				'_cdek_box_note'       => 'книги',
			],
			$this->meta[42]
		);
	}

	public function test_persist_removes_what_the_edit_cleared_or_hid(): void {
		$set = $this->set();

		$this->meta[42] = [
			'_cdek_declared_value' => '99.5',
			'_cdek_box_note'       => 'книги',
			'_cdek_package_type'   => 'box',
		];

		// The manager blanked the value and switched to an envelope, which hides the note.
		$result = $set->normalize( [ 'declared_value' => '', 'package_type' => 'envelope', 'box_note' => 'книги' ] );

		$set->persist( $this->order(), $result['values'] );

		$this->assertArrayNotHasKey( '_cdek_declared_value', $this->meta[42] );
		$this->assertArrayNotHasKey( '_cdek_box_note', $this->meta[42] );
		$this->assertSame( 'envelope', $this->meta[42]['_cdek_package_type'] );
	}

	public function test_read_is_the_inverse_of_persist(): void {
		$set    = $this->set();
		$values = $set->normalize( [ 'declared_value' => '99.5', 'package_type' => 'box', 'fragile' => true, 'box_note' => 'книги' ] )['values'];

		$set->persist( $this->order(), $values );

		$this->assertSame( $values, $set->read( $this->order() ) );
		$this->assertSame( [], $set->read( Mockery::mock( '\WC_Order', [ 'get_id' => 7 ] ) ), 'an order with nothing stored reads as nothing' );
	}

	public function test_forget_except_removes_only_the_meta_the_new_tariff_does_not_use(): void {
		$previous = $this->set();

		$this->meta[42] = [
			'_cdek_declared_value' => '10',
			'_cdek_fragile'        => 'yes',
		];

		$previous->forget_except( $this->order(), [ '_cdek_declared_value' ] );

		$this->assertSame( [ '_cdek_declared_value' => '10' ], $this->meta[42] );
	}
}
