<?php
/**
 * Unit tests for the «Диагностика» payload and its report gate.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Pages\Diagnostics;

use Woodev\Framework\Marketplace\Admin\Diagnostics\Logging;
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
}
