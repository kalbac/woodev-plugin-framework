<?php
/**
 * Unit tests for Notification_State_Text: the sentence on how an account's notifications are doing.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Shared;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Admin\Shared\Notification_State_Text;
use Woodev\Framework\Marketplace\Intake\Event_Names;
use Woodev\Framework\Marketplace\Intake\Webhook_Type;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Push_Protocol;
use Woodev\Tests\Unit\TestCase;

final class NotificationStateTextTest extends TestCase {
	private const NOW = 1794139200;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'human_time_diff' )->justReturn( '3 дня' );
	}

	private function text(): Notification_State_Text {
		return new Notification_State_Text(
			new Event_Names( new Fake_Push_Protocol( '', [ new Webhook_Type( 'TYPE_POSTING_CANCELLED', 'Отмена отправления', 'p', '' ) ] ) )
		);
	}

	public function test_a_never_checked_address_with_a_notification_is_two_plain_sentences_not_a_contradiction(): void {
		$this->assertSame(
			'Связь не проверялась. Последнее уведомление: отмена отправления, 3 дня назад.',
			$this->text()->describe( [], null, [ 'type' => 'TYPE_POSTING_CANCELLED', 'received_at' => self::NOW - 3 * 86400, 'status' => 'processed' ], self::NOW )
		);
	}

	public function test_no_notification_yet_is_said_plainly(): void {
		$this->assertSame(
			'Уведомления включены. Уведомления пока не поступали.',
			$this->text()->describe( [ 'enabled' => true ], null, null, self::NOW )
		);
		$this->assertSame(
			'Уведомления отключены. Уведомления пока не поступали.',
			$this->text()->describe( [ 'enabled' => false ], null, null, self::NOW )
		);
	}

	public function test_sentence_capitalises_and_closes_a_line_once(): void {
		$this->assertSame( 'Активно (GREEN).', Notification_State_Text::sentence( 'активно (GREEN)' ) );
		$this->assertSame( 'Уже готово.', Notification_State_Text::sentence( 'Уже готово.' ) );
		$this->assertSame( '', Notification_State_Text::sentence( '  ' ) );
	}
}
