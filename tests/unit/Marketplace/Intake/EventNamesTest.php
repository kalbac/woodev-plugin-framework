<?php
/**
 * Unit tests for Event_Names: the merchant-facing names of notification types.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Intake;

use Woodev\Framework\Marketplace\Intake\Event_Names;
use Woodev\Framework\Marketplace\Intake\Webhook_Type;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Push_Protocol;
use Woodev\Tests\Unit\TestCase;

final class EventNamesTest extends TestCase {

	public function test_a_declared_type_is_named_by_its_switch_in_the_lower_case_a_sentence_wants(): void {
		$names = new Event_Names(
			new Fake_Push_Protocol(
				'',
				[
					new Webhook_Type( 'TYPE_POSTING_CANCELLED', 'Отмена отправления', 'p', '' ),
					new Webhook_Type( 'TYPE_FBS_READY', 'FBS-отправление готово', 'p', '' ),
				]
			)
		);

		$this->assertSame( 'отмена отправления', $names->name( 'TYPE_POSTING_CANCELLED' ) );
		$this->assertSame( 'отмена отправления', $names->name( 'type_posting_cancelled' ), 'the decoder\'s case does not matter' );
		$this->assertSame( 'FBS-отправление готово', $names->name( 'TYPE_FBS_READY' ), 'an acronym keeps its capitals' );
	}

	public function test_a_type_nobody_declared_stays_as_the_decoder_gave_it(): void {
		$names = new Event_Names( new Fake_Push_Protocol( '', [] ) );

		$this->assertSame( 'TYPE_UNKNOWN', $names->name( 'TYPE_UNKNOWN' ) );
		$this->assertSame( 'whatever', ( new Event_Names( null ) )->name( 'whatever' ), 'a marketplace that pushes nothing declares no names' );
	}
}
