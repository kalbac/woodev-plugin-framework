<?php
/**
 * Unit tests for the «Диагностика» payload and its report gate.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Pages\Diagnostics;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Logging;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Report_Builder;
use Woodev\Framework\Marketplace\Admin\Pages\Diagnostics\Diagnostics_Controller;
use Woodev\Framework\Marketplace\Admin\Pages\Diagnostics\Diagnostics_Payload;
use Woodev\Framework\Marketplace\Diagnostics\Report_Section;
use Woodev\Tests\Unit\TestCase;

final class DiagnosticsPageTest extends TestCase {
	public function test_sections_become_titled_label_value_rows_in_order(): void {
		$sections = [
			new Report_Section(
				'Лицензия',
				[
					'Статус'   => 'активна',
					'Истекает' => '01.12.2026',
				]
			),
			new Report_Section( 'Аккаунт «Главный»', [ 'Соединение' => 'работает' ] ),
		];

		$this->assertSame(
			[
				[
					'title' => 'Лицензия',
					'lines' => [
						[
							'label' => 'Статус',
							'value' => 'активна',
						],
						[
							'label' => 'Истекает',
							'value' => '01.12.2026',
						],
					],
				],
				[
					'title' => 'Аккаунт «Главный»',
					'lines' => [
						[
							'label' => 'Соединение',
							'value' => 'работает',
						],
					],
				],
			],
			Diagnostics_Payload::sections( $sections )
		);
	}

	public function test_a_numeric_label_and_a_scalar_value_are_sent_as_strings(): void {
		$payload = Diagnostics_Payload::sections( [ new Report_Section( 'Очередь', [ 0 => 12 ] ) ] );

		$this->assertSame(
			[
				'label' => '0',
				'value' => '12',
			],
			$payload[0]['lines'][0]
		);
	}

	public function test_the_report_is_refused_with_the_logging_sentence_while_logging_is_off(): void {
		$this->assertSame( Logging::REFUSAL, Diagnostics_Controller::report_refusal( false ) );
		$this->assertNull( Diagnostics_Controller::report_refusal( true ) );
	}

	public function test_the_switch_takes_a_real_boolean_and_stores_it(): void {
		Functions\expect( 'update_option' )->once()->with( 'woodev_fake_logging', 'yes' );
		$on = $this->controller( 'no' )->set_logging( $this->request( [ 'enabled' => true ] ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $on );

		Functions\expect( 'update_option' )->once()->with( 'woodev_fake_logging', 'no' );
		$this->controller( 'yes' )->set_logging( $this->request( [ 'enabled' => false ] ) );
	}

	public function test_the_switch_refuses_anything_but_a_lone_boolean_and_stores_nothing(): void {
		Functions\expect( 'update_option' )->never();

		$bodies = [
			'a text'        => [ 'enabled' => 'yes' ],
			'a text true'   => [ 'enabled' => 'true' ],
			'a number'      => [ 'enabled' => 1 ],
			'null'          => [ 'enabled' => null ],
			'extra key'     => [ 'enabled' => true, 'force' => true ],
			'missing key'   => [ 'switch' => true ],
			'empty body'    => [],
		];

		foreach ( $bodies as $label => $body ) {
			$answer = $this->controller( 'no' )->set_logging( $this->request( $body ) );

			$this->assertInstanceOf( \WP_Error::class, $answer, $label );
			$this->assertSame( 'woodev_diagnostics_bad_body', $answer->code, $label );
			$this->assertSame( 400, $answer->data['status'], $label );
		}

		$this->assertInstanceOf( \WP_Error::class, $this->controller( 'no' )->set_logging( $this->request( null ) ) );
	}

	/**
	 * @param array<string,mixed>|null $body JSON body; null for a request with no JSON at all.
	 */
	private function request( $body ): \WP_REST_Request {
		$request = Mockery::mock( \WP_REST_Request::class );
		$request->shouldReceive( 'get_json_params' )->andReturn( $body );

		return $request;
	}

	/**
	 * A controller whose switch reads the given stored value. The report builder is not used by the switch route.
	 */
	private function controller( string $stored ): Diagnostics_Controller {
		$builder = ( new \ReflectionClass( Report_Builder::class ) )->newInstanceWithoutConstructor();

		return new Diagnostics_Controller( 'fake_seller_plugin', 'manage_woocommerce', $builder, new Logging( 'woodev_fake_logging', static fn (): string => $stored ) );
	}
}
