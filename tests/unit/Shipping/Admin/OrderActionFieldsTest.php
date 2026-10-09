<?php
/**
 * Unit: Order_Action_Fields — the input schema an extra order action declares and the check of what a
 * merchant typed against it (card #1180).
 *
 * Pins both halves: `sanitize()` (what a malformed declaration loses) and `validate()` (each type, required,
 * bounds, option membership, the shape of the answer).
 *
 * @package Woodev\Tests\Unit\Shipping\Admin
 */

namespace Woodev\Tests\Unit\Shipping\Admin;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Admin\Orders\Order_Action_Fields;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Order_Action_Fields
 */
final class OrderActionFieldsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		// The framework's textarea value goes through WordPress's own sanitiser; a double that strips tags is enough here.
		Functions\when( 'sanitize_textarea_field' )->alias(
			static function ( string $text ): string {
				return strip_tags( $text );
			}
		);
	}

	/** The whole courier-call declaration: one field of every type. */
	private function declared(): array {
		return Order_Action_Fields::sanitize(
			[
				[
					'id'       => 'day',
					'type'     => 'date',
					'label'    => 'День',
					'required' => true,
					'default'  => '2026-10-12',
					'min'      => '2026-10-12',
					'max'      => '2026-10-26',
				],
				[
					'id'       => 'window',
					'type'     => 'time_range',
					'label'    => 'Время',
					'required' => true,
					'default'  => [
						'from' => '09:00',
						'to'   => '18:00',
					],
					'min'      => '09:00',
					'max'      => '21:00',
				],
				[
					'id'      => 'service',
					'type'    => 'select',
					'label'   => 'Забор',
					'options' => [
						'standard' => 'Обычный',
						'express'  => 'Срочный',
					],
				],
				[
					'id'        => 'comment',
					'type'      => 'textarea',
					'label'     => 'Комментарий',
					'maxlength' => 10,
				],
			]
		);
	}

	// ----- sanitize() -----

	public function test_a_non_array_declares_nothing(): void {
		$this->assertSame( [], Order_Action_Fields::sanitize( null ) );
		$this->assertSame( [], Order_Action_Fields::sanitize( 'day' ) );
	}

	public function test_every_kept_field_carries_the_keys_of_its_type(): void {
		$fields = $this->declared();

		$this->assertSame( [ 'day', 'window', 'service', 'comment' ], array_column( $fields, 'id' ) );
		$this->assertSame(
			[
				'id'       => 'day',
				'type'     => 'date',
				'label'    => 'День',
				'required' => true,
				'default'  => '2026-10-12',
				'min'      => '2026-10-12',
				'max'      => '2026-10-26',
			],
			$fields[0]
		);
		$this->assertSame(
			[
				'from' => '09:00',
				'to'   => '18:00',
			],
			$fields[1]['default']
		);
		$this->assertSame(
			[
				[
					'value' => 'standard',
					'label' => 'Обычный',
				],
				[
					'value' => 'express',
					'label' => 'Срочный',
				],
			],
			$fields[2]['options'],
			'a value => label map is accepted and becomes the list the client reads'
		);
		$this->assertFalse( $fields[2]['required'], 'required defaults to false' );
		$this->assertSame( 10, $fields[3]['maxlength'] );
	}

	public function test_malformed_fields_are_dropped(): void {
		$fields = Order_Action_Fields::sanitize(
			[
				'not-an-array',
				[
					'type'  => 'date',
					'label' => 'No id',
				],
				[
					'id'    => 'Bad Id',
					'type'  => 'date',
					'label' => 'Upper case and a space',
				],
				[
					'id'   => 'nolabel',
					'type' => 'date',
				],
				[
					'id'    => 'unknown',
					'type'  => 'color',
					'label' => 'Unknown type',
				],
				[
					'id'      => 'empty_select',
					'type'    => 'select',
					'label'   => 'No options',
					'options' => [],
				],
				[
					'id'    => 'ok',
					'type'  => 'date',
					'label' => 'Fine',
				],
				[
					'id'    => 'ok',
					'type'  => 'textarea',
					'label' => 'Same id again',
				],
			]
		);

		$this->assertSame( [ 'ok' ], array_column( $fields, 'id' ) );
		$this->assertSame( 'date', $fields[0]['type'], 'the first of two fields with one id wins' );
	}

	public function test_a_bound_or_default_that_is_not_a_real_value_is_ignored(): void {
		$fields = Order_Action_Fields::sanitize(
			[
				[
					'id'      => 'day',
					'type'    => 'date',
					'label'   => 'День',
					'default' => '2026-02-30',
					'min'     => 'tomorrow',
				],
				[
					'id'      => 'service',
					'type'    => 'select',
					'label'   => 'Забор',
					'options' => [ 'a', 'b' ],
					'default' => 'not-an-option',
				],
				[
					'id'      => 'window',
					'type'    => 'time_range',
					'label'   => 'Время',
					'default' => [
						'from' => '25:00',
						'to'   => '18:00',
					],
					'max'     => '24:00',
				],
			]
		);

		$this->assertSame( '', $fields[0]['default'], 'February 30th is not a date' );
		$this->assertArrayNotHasKey( 'min', $fields[0] );
		$this->assertSame( '', $fields[1]['default'] );
		$this->assertSame(
			[
				'from' => '',
				'to'   => '',
			],
			$fields[2]['default']
		);
		$this->assertArrayNotHasKey( 'max', $fields[2] );
	}

	public function test_a_textarea_without_a_limit_gets_the_default_and_a_huge_one_is_clamped(): void {
		$fields = Order_Action_Fields::sanitize(
			[
				[
					'id'    => 'a',
					'type'  => 'textarea',
					'label' => 'A',
				],
				[
					'id'        => 'b',
					'type'      => 'textarea',
					'label'     => 'B',
					'maxlength' => 999999,
				],
			]
		);

		$this->assertSame( Order_Action_Fields::DEFAULT_TEXTAREA_MAXLENGTH, $fields[0]['maxlength'] );
		$this->assertSame( Order_Action_Fields::MAX_TEXTAREA_MAXLENGTH, $fields[1]['maxlength'] );
	}

	// ----- validate() -----

	private function valid_payload(): array {
		return [
			'day'     => '2026-10-13',
			'window'  => [
				'from' => '10:00',
				'to'   => '14:00',
			],
			'service' => 'express',
			'comment' => 'Позвонить',
		];
	}

	public function test_a_valid_payload_comes_back_whole_and_without_errors(): void {
		$result = Order_Action_Fields::validate( $this->declared(), $this->valid_payload() );

		$this->assertSame( [], $result['errors'] );
		$this->assertSame( $this->valid_payload(), $result['values'] );
	}

	public function test_only_declared_ids_come_out(): void {
		$result = Order_Action_Fields::validate( $this->declared(), $this->valid_payload() + [ 'injected' => 'x' ] );

		$this->assertSame( [ 'day', 'window', 'service', 'comment' ], array_keys( $result['values'] ) );
	}

	public function test_an_empty_optional_field_is_present_and_empty(): void {
		$payload = $this->valid_payload();

		unset( $payload['service'], $payload['comment'] );

		$result = Order_Action_Fields::validate( $this->declared(), $payload );

		$this->assertSame( [], $result['errors'] );
		$this->assertSame( '', $result['values']['service'] );
		$this->assertSame( '', $result['values']['comment'] );
	}

	public function test_a_payload_that_is_not_an_array_misses_every_required_field(): void {
		$result = Order_Action_Fields::validate( $this->declared(), 'nonsense' );

		$this->assertSame( [ 'day', 'window' ], array_column( $result['errors'], 'field' ) );
		$this->assertSame( [ 'required', 'required' ], array_column( $result['errors'], 'code' ) );
	}

	public function test_the_error_shape_is_field_code_message(): void {
		$result = Order_Action_Fields::validate( $this->declared(), [] );

		$this->assertSame(
			[
				'field'   => 'day',
				'code'    => 'required',
				'message' => 'Заполните это поле.',
			],
			$result['errors'][0]
		);
	}

	/**
	 * @dataProvider provide_wrong_dates
	 */
	public function test_a_date_must_be_a_real_date_inside_its_bounds( string $value, string $code ): void {
		$payload        = $this->valid_payload();
		$payload['day'] = $value;

		$result = Order_Action_Fields::validate( $this->declared(), $payload );

		$this->assertSame( [ 'day' ], array_column( $result['errors'], 'field' ) );
		$this->assertSame( $code, $result['errors'][0]['code'] );
	}

	public function provide_wrong_dates(): array {
		return [
			'not a date'                => [ '13.10.2026', 'invalid' ],
			'not a calendar day'        => [ '2026-02-30', 'invalid' ],
			'before min'                => [ '2026-10-11', 'out_of_range' ],
			'after max'                 => [ '2026-10-27', 'out_of_range' ],
		];
	}

	public function test_the_bounds_of_a_date_are_inclusive(): void {
		foreach ( [ '2026-10-12', '2026-10-26' ] as $edge ) {
			$payload        = $this->valid_payload();
			$payload['day'] = $edge;

			$this->assertSame( [], Order_Action_Fields::validate( $this->declared(), $payload )['errors'], $edge );
		}
	}

	public function test_a_select_value_must_be_one_of_the_options(): void {
		$payload            = $this->valid_payload();
		$payload['service'] = 'teleport';

		$result = Order_Action_Fields::validate( $this->declared(), $payload );

		$this->assertSame( 'invalid_option', $result['errors'][0]['code'] );
		$this->assertSame( 'service', $result['errors'][0]['field'] );
	}

	public function test_a_required_select_left_empty_is_missing(): void {
		$fields = Order_Action_Fields::sanitize(
			[
				[
					'id'       => 'service',
					'type'     => 'select',
					'label'    => 'Забор',
					'required' => true,
					'options'  => [ 'a' ],
				],
			]
		);

		$this->assertSame( 'required', Order_Action_Fields::validate( $fields, [ 'service' => '' ] )['errors'][0]['code'] );
	}

	/**
	 * @dataProvider provide_wrong_windows
	 */
	public function test_a_time_range_is_two_times_in_order_inside_its_bounds( $value, string $code ): void {
		$payload           = $this->valid_payload();
		$payload['window'] = $value;

		$result = Order_Action_Fields::validate( $this->declared(), $payload );

		$this->assertSame( $code, $result['errors'][0]['code'] );
		$this->assertSame( 'window', $result['errors'][0]['field'] );
	}

	public function provide_wrong_windows(): array {
		return [
			'empty (required)'        => [
				[
					'from' => '',
					'to'   => '',
				],
				'required',
			],
			'only one end'            => [
				[
					'from' => '10:00',
					'to'   => '',
				],
				'invalid',
			],
			'not a time'              => [
				[
					'from' => '10:00',
					'to'   => 'noon',
				],
				'invalid',
			],
			'ends where it starts'    => [
				[
					'from' => '10:00',
					'to'   => '10:00',
				],
				'invalid_range',
			],
			'ends before it starts'   => [
				[
					'from' => '14:00',
					'to'   => '10:00',
				],
				'invalid_range',
			],
			'starts before the min'   => [
				[
					'from' => '08:00',
					'to'   => '12:00',
				],
				'out_of_range',
			],
			'ends after the max'      => [
				[
					'from' => '12:00',
					'to'   => '22:00',
				],
				'out_of_range',
			],
			'a bare string'           => [ '10:00-14:00', 'required' ],
		];
	}

	public function test_a_text_over_its_limit_is_an_error_not_a_cut(): void {
		$payload            = $this->valid_payload();
		$payload['comment'] = str_repeat( 'я', 11 );

		$result = Order_Action_Fields::validate( $this->declared(), $payload );

		$this->assertSame( 'too_long', $result['errors'][0]['code'] );
		$this->assertSame( 'Не больше 10 символов.', $result['errors'][0]['message'], 'characters, not bytes' );
	}

	public function test_a_text_exactly_at_its_limit_passes_and_markup_is_stripped(): void {
		$payload            = $this->valid_payload();
		$payload['comment'] = '<b>' . str_repeat( 'я', 10 ) . '</b>';

		$result = Order_Action_Fields::validate( $this->declared(), $payload );

		$this->assertSame( [], $result['errors'] );
		$this->assertSame( str_repeat( 'я', 10 ), $result['values']['comment'] );
	}

	public function test_every_wrong_field_is_reported_once_in_declaration_order(): void {
		$result = Order_Action_Fields::validate(
			$this->declared(),
			[
				'day'     => 'x',
				'window'  => [
					'from' => '14:00',
					'to'   => '10:00',
				],
				'service' => 'teleport',
				'comment' => str_repeat( 'a', 11 ),
			]
		);

		$this->assertSame( [ 'day', 'window', 'service', 'comment' ], array_column( $result['errors'], 'field' ) );
	}

	// ----- help (s164) -----

	public function test_a_help_is_kept_as_one_plain_sentence_on_every_type(): void {
		Functions\when( 'wp_strip_all_tags' )->alias(
			static function ( string $text ): string {
				return strip_tags( $text );
			}
		);

		$fields = Order_Action_Fields::sanitize(
			[
				[
					'id'    => 'day',
					'type'  => 'date',
					'label' => 'День',
					'help'  => "  На один адрес — <b>один</b> вызов\n  в день.  ",
				],
				[
					'id'      => 'service',
					'type'    => 'select',
					'label'   => 'Забор',
					'options' => [ 'a' ],
					'help'    => 'Подсказка',
				],
				[
					'id'    => 'note',
					'type'  => 'textarea',
					'label' => 'Комментарий',
				],
			]
		);

		$this->assertSame( 'На один адрес — один вызов в день.', $fields[0]['help'] );
		$this->assertSame( 'Подсказка', $fields[1]['help'] );
		$this->assertArrayNotHasKey( 'help', $fields[2], 'no help declared => no key, so a field keeps exactly the shape it had' );
	}

	public function test_a_help_that_is_not_text_is_dropped_and_a_long_one_is_cut(): void {
		Functions\when( 'wp_strip_all_tags' )->alias(
			static function ( string $text ): string {
				return strip_tags( $text );
			}
		);

		$fields = Order_Action_Fields::sanitize(
			[
				[
					'id'    => 'a',
					'type'  => 'date',
					'label' => 'A',
					'help'  => [ 'not text' ],
				],
				[
					'id'    => 'b',
					'type'  => 'date',
					'label' => 'B',
					'help'  => '   ',
				],
				[
					'id'    => 'c',
					'type'  => 'date',
					'label' => 'C',
					'help'  => str_repeat( 'я', 900 ),
				],
			]
		);

		$this->assertArrayNotHasKey( 'help', $fields[0] );
		$this->assertArrayNotHasKey( 'help', $fields[1] );
		$this->assertSame( Order_Action_Fields::MAX_HELP_LENGTH, mb_strlen( $fields[2]['help'] ) );
	}

	// ----- orders (s164) -----

	/** @return array<int,array<string,mixed>> */
	private function orders_options(): array {
		return [
			[
				'value' => '1047',
				'label' => '#1047 · Екатеринбург',
			],
			[
				'value' => '1050',
				'label' => '#1050 · Москва',
			],
			[
				'value' => '1051',
				'label' => '#1051 · Казань',
			],
		];
	}

	/** @return array<int,array<string,mixed>> */
	private function orders_field( array $extra = [] ): array {
		return Order_Action_Fields::sanitize(
			[
				array_merge(
					[
						'id'       => 'orders',
						'type'     => 'orders',
						'label'    => 'Заказы',
						'required' => true,
						'options'  => $this->orders_options(),
					],
					$extra
				),
			],
			true
		);
	}

	public function test_the_orders_type_exists_only_where_it_is_allowed(): void {
		$declared = [
			[
				'id'      => 'orders',
				'type'    => 'orders',
				'label'   => 'Заказы',
				'options' => $this->orders_options(),
			],
		];

		$this->assertSame( [], Order_Action_Fields::sanitize( $declared ), 'a per-order action never carries the order picker' );
		$this->assertSame( [ 'orders' ], array_column( Order_Action_Fields::sanitize( $declared, true ), 'id' ) );
	}

	public function test_every_order_is_preselected_unless_the_declaration_names_the_ones_to_start_with(): void {
		$this->assertSame( [ '1047', '1050', '1051' ], $this->orders_field()[0]['default'] );
		$this->assertSame( [ '1050' ], $this->orders_field( [ 'default' => [ 1050, '9999' ] ] )[0]['default'], 'a default outside the options is dropped' );
		$this->assertSame( [], $this->orders_field( [ 'default' => [] ] )[0]['default'], 'an explicit empty default preselects nothing' );
	}

	public function test_an_orders_field_without_a_single_usable_option_is_dropped(): void {
		$this->assertSame( [], $this->orders_field( [ 'options' => [] ] ) );
	}

	public function test_more_options_than_the_ceiling_are_cut(): void {
		$options = [];

		for ( $i = 1; $i <= Order_Action_Fields::MAX_ORDERS + 20; $i++ ) {
			$options[] = [
				'value' => (string) $i,
				'label' => '#' . $i,
			];
		}

		$this->assertCount( Order_Action_Fields::MAX_ORDERS, $this->orders_field( [ 'options' => $options ] )[0]['options'] );
	}

	public function test_chosen_orders_come_back_as_a_unique_list_of_option_values(): void {
		$result = Order_Action_Fields::validate( $this->orders_field(), [ 'orders' => [ '1050', 1047, '1050' ] ] );

		$this->assertSame( [], $result['errors'] );
		$this->assertSame( [ '1050', '1047' ], $result['values']['orders'] );
	}

	public function test_an_order_the_dialog_never_offered_refuses_the_whole_field(): void {
		$result = Order_Action_Fields::validate( $this->orders_field(), [ 'orders' => [ '1047', '777' ] ] );

		$this->assertSame( 'invalid_option', $result['errors'][0]['code'] );
		$this->assertSame( 'orders', $result['errors'][0]['field'] );
		$this->assertSame( [], $result['values']['orders'], 'nothing is forwarded from a refused field' );
	}

	public function test_a_required_orders_field_left_empty_is_missing_and_a_non_list_is_empty(): void {
		foreach ( [ [], null, 'x' ] as $posted ) {
			$result = Order_Action_Fields::validate( $this->orders_field(), [ 'orders' => $posted ] );

			$this->assertSame( 'required', $result['errors'][0]['code'] );
		}

		$optional = Order_Action_Fields::validate( $this->orders_field( [ 'required' => false ] ), [] );

		$this->assertSame( [], $optional['errors'] );
		$this->assertSame( [], $optional['values']['orders'] );
	}

	public function test_a_list_item_that_is_not_a_scalar_is_an_invalid_option(): void {
		$result = Order_Action_Fields::validate( $this->orders_field(), [ 'orders' => [ [ '1047' ] ] ] );

		$this->assertSame( 'invalid_option', $result['errors'][0]['code'] );
	}
}
