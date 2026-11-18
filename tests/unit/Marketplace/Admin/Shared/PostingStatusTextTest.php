<?php
/**
 * Unit tests for the posting-status names of the admin screens.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Shared;

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
		];
	}

	public function test_the_code_is_read_without_regard_to_case_or_separators(): void {
		$this->assertSame( 'Ожидает упаковки', Posting_Status_Text::label( ' Awaiting-Packaging ' ) );
	}

	public function test_an_unknown_code_is_a_generic_word_never_the_code(): void {
		$this->assertSame( 'Другой статус', Posting_Status_Text::label( 'some_future_status' ) );
		$this->assertSame( 'neutral', Posting_Status_Text::tone( 'some_future_status' ) );
	}
}
