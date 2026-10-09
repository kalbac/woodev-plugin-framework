<?php
/**
 * Unit: Order_Row_Flags — the badges a carrier plugin hangs under a row's tracking number (s164).
 *
 * Pins what the filter's answer is allowed to look like (label, tone, title, the cap and the de-duplication), that a
 * misbehaving callback cannot break a row, and that the row builder carries the list under `flags`.
 *
 * @package Woodev\Tests\Unit\Shipping\Admin
 */

namespace Woodev\Tests\Unit\Shipping\Admin;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Order_Row_Flags;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Order_Row_Flags
 */
final class OrderRowFlagsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_strip_all_tags' )->alias(
			static function ( string $text ): string {
				return strip_tags( $text );
			}
		);
	}

	public function test_a_flag_keeps_its_label_its_known_tone_and_an_optional_title(): void {
		$flags = Order_Row_Flags::sanitize(
			[
				[
					'label' => 'Нужно вызвать курьера',
					'tone'  => 'warn',
					'title' => 'Курьер ещё не вызван',
				],
			]
		);

		$this->assertSame(
			[
				[
					'label' => 'Нужно вызвать курьера',
					'tone'  => 'warn',
					'title' => 'Курьер ещё не вызван',
				],
			],
			$flags
		);
	}

	public function test_a_flag_without_a_title_carries_no_title_key(): void {
		$this->assertSame(
			[
				[
					'label' => 'Курьер вызван',
					'tone'  => 'info',
				],
			],
			Order_Row_Flags::sanitize(
				[
					[
						'label' => 'Курьер вызван',
						'tone'  => 'info',
					],
				]
			)
		);
	}

	public function test_an_unknown_or_missing_tone_falls_back_to_the_neutral_one(): void {
		$flags = Order_Row_Flags::sanitize(
			[
				[
					'label' => 'A',
					'tone'  => 'purple',
				],
				[ 'label' => 'B' ],
				[
					'label' => 'C',
					'tone'  => [ 'warn' ],
				],
			]
		);

		$this->assertSame( [ 'muted', 'muted', 'muted' ], array_column( $flags, 'tone' ) );
	}

	public function test_every_tone_the_delivery_badge_has_is_accepted(): void {
		foreach ( [ 'ok', 'warn', 'error', 'info', 'muted' ] as $tone ) {
			$this->assertSame(
				$tone,
				Order_Row_Flags::sanitize(
					[
						[
							'label' => 'x',
							'tone'  => $tone,
						],
					]
				)[0]['tone']
			);
		}
	}

	public function test_malformed_empty_and_repeated_flags_are_dropped(): void {
		$flags = Order_Row_Flags::sanitize(
			[
				'text',
				[],
				[ 'label' => '' ],
				[ 'label' => '   ' ],
				[ 'label' => [ 'x' ] ],
				[ 'label' => '<b></b>' ],
				[ 'label' => 'Курьер вызван' ],
				[
					'label' => 'Курьер вызван',
					'tone'  => 'ok',
				],
			]
		);

		$this->assertSame( [ 'Курьер вызван' ], array_column( $flags, 'label' ) );
		$this->assertSame( 'muted', $flags[0]['tone'], 'the first of two flags with one label wins' );
	}

	public function test_a_label_is_one_plain_line_and_a_long_one_is_cut(): void {
		$flags = Order_Row_Flags::sanitize(
			[
				[ 'label' => "Курьер\n  <b>вызван</b>" ],
				[ 'label' => str_repeat( 'я', 200 ) ],
			]
		);

		$this->assertSame( 'Курьер вызван', $flags[0]['label'] );
		$this->assertSame( Order_Row_Flags::MAX_LABEL_LENGTH, mb_strlen( $flags[1]['label'] ) );
	}

	public function test_a_row_never_carries_more_than_the_ceiling(): void {
		$declared = [];

		for ( $i = 1; $i <= 10; $i++ ) {
			$declared[] = [ 'label' => 'Флаг ' . $i ];
		}

		$flags = Order_Row_Flags::sanitize( $declared );

		$this->assertCount( Order_Row_Flags::MAX_FLAGS, $flags );
		$this->assertSame( 'Флаг 1', $flags[0]['label'], 'the first ones are kept, in the order given' );
	}

	public function test_a_filter_answer_that_is_not_a_list_is_no_flags(): void {
		foreach ( [ null, 'x', 5, false ] as $answer ) {
			$this->assertSame( [], Order_Row_Flags::sanitize( $answer ) );
		}
	}

	public function test_for_order_runs_the_filter_with_the_order_and_the_provider_and_sanitises_its_answer(): void {
		$order    = Mockery::mock( '\WC_Order' );
		$provider = Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ] );
		$seen     = [];

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, ...$args ) use ( &$seen ) {
				$seen = [ $hook, $value, $args ];

				return [
					[
						'label' => 'Нужно вызвать курьера',
						'tone'  => 'warn',
					],
					'junk',
				];
			}
		);

		$flags = Order_Row_Flags::for_order( $order, $provider );

		$this->assertSame( 'woodev_shipping_order_row_flags', $seen[0] );
		$this->assertSame( [], $seen[1], 'the framework adds none itself' );
		$this->assertSame( [ $order, $provider ], $seen[2] );
		$this->assertSame(
			[
				[
					'label' => 'Нужно вызвать курьера',
					'tone'  => 'warn',
				],
			],
			$flags
		);
	}

	public function test_for_order_with_nothing_hooked_is_an_empty_list(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$this->assertSame( [], Order_Row_Flags::for_order( Mockery::mock( '\WC_Order' ), null ) );
	}
}
