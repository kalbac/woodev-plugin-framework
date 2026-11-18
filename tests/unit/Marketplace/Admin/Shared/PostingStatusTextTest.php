<?php
/**
 * Unit tests for the posting-status names of the admin screens.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Shared;

use Woodev\Framework\Marketplace\Admin\Shared\Posting_Status_Names;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Status_Text;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Shared\Posting_Status_Text
 */
final class PostingStatusTextTest extends TestCase {

	/**
	 * @dataProvider known_statuses
	 */
	public function test_a_known_status_is_a_russian_word_with_its_tone( string $code, string $label, string $tone ): void {
		$this->assertSame( $label, Posting_Status_Text::label( $code ) );
		$this->assertSame( $tone, Posting_Status_Text::tone( $code ) );
	}

	/**
	 * @return array<string,array{string,string,string}>
	 */
	public function known_statuses(): array {
		return [
			'awaiting_packaging'     => [ 'awaiting_packaging', 'Ожидает упаковки', 'warning' ],
			'awaiting_deliver'       => [ 'awaiting_deliver', 'Ожидает отгрузки', 'warning' ],
			'acceptance_in_progress' => [ 'acceptance_in_progress', 'Идёт приёмка', 'info' ],
			'delivering'             => [ 'delivering', 'Доставляется', 'info' ],
			'delivered'              => [ 'delivered', 'Доставлено', 'success' ],
			'cancelled'              => [ 'cancelled', 'Отменено', 'danger' ],
			'not_accepted'           => [ 'not_accepted', 'Не принято', 'danger' ],
			'arbitration'            => [ 'arbitration', 'Арбитраж', 'warning' ],
			'awaiting_verification'  => [ 'awaiting_verification', 'Создано', 'warning' ],
			'cancelled_from_split'   => [ 'cancelled_from_split_pending', 'Отменено при разделении', 'danger' ],
		];
	}

	public function test_the_code_is_read_without_regard_to_case_or_separators(): void {
		$this->assertSame( 'Ожидает упаковки', Posting_Status_Text::label( ' Awaiting-Packaging ' ) );
	}

	public function test_an_unknown_code_is_a_generic_word_never_the_code(): void {
		$this->assertSame( 'Другой статус', Posting_Status_Text::label( 'some_future_status' ) );
		$this->assertSame( 'neutral', Posting_Status_Text::tone( 'some_future_status' ) );
	}

	public function test_the_adapter_names_win_over_the_shared_map(): void {
		$names = $this->names( [ 'awaiting_packaging' => 'Ожидает сборки' ] );

		$this->assertSame( 'Ожидает сборки', Posting_Status_Text::label( 'awaiting_packaging', $names ) );
		$this->assertSame( 'warning', Posting_Status_Text::tone( 'awaiting_packaging' ), 'The tone stays the framework\'s.' );
	}

	public function test_a_status_the_adapter_does_not_name_falls_back_to_the_map_then_to_the_generic_word(): void {
		$names = $this->names( [ 'some_future_status' => 'some_future_status' ] );

		$this->assertSame( 'Доставлено', Posting_Status_Text::label( 'delivered', $names ), 'An empty answer falls back to the map.' );
		$this->assertSame( 'Другой статус', Posting_Status_Text::label( 'brand_new', $names ), 'Unknown to both: generic word.' );
		$this->assertSame( 'Другой статус', Posting_Status_Text::label( 'some_future_status', $names ), 'An adapter that echoes the code does not put it on screen.' );
	}

	/**
	 * @param array<string,string> $map Status => name.
	 * @return Posting_Status_Names
	 */
	private function names( array $map ): Posting_Status_Names {
		return new class( $map ) implements Posting_Status_Names {
			/** @var array<string,string> */
			private $map;

			/**
			 * @param array<string,string> $map Status => name.
			 */
			public function __construct( array $map ) {
				$this->map = $map;
			}

			public function posting_status_name( string $status ): string {
				return $this->map[ $status ] ?? '';
			}
		};
	}
}
