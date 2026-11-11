<?php
/**
 * Unit tests for D7 Sync_Reason_Text mapper.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Shared;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Shared\Sync_Reason_Text;
use Woodev\Framework\Marketplace\Diagnostics\Sync_Reason;
use Woodev\Framework\Marketplace\Diagnostics\Why_Not_Syncing;
use Woodev\Framework\Marketplace\Links\Product_Link;
use Woodev\Tests\Unit\TestCase;

final class SyncReasonTextTest extends TestCase {

	public function test_maps_every_d7_sync_reason_code_to_russian_sentence(): void {
		$cases = [
			[
				new Sync_Reason( Sync_Reason::LICENSE_INVALID ),
				'Лицензия не действует',
			],
			[
				new Sync_Reason( Sync_Reason::ACCOUNT_DISABLED ),
				'Аккаунт выключен',
			],
			[
				new Sync_Reason( Sync_Reason::KEY_ROLE_MISSING ),
				'нет нужных прав',
			],
			[
				new Sync_Reason( Sync_Reason::KEY_INVALID ),
				'Ключ недействителен или истёк',
			],
			[
				new Sync_Reason( Sync_Reason::NOT_LINKED ),
				'не связан с карточкой маркетплейса',
			],
			[
				new Sync_Reason( Sync_Reason::CARD_PENDING, [ 'state' => 'moderating' ] ),
				'moderating',
			],
			[
				new Sync_Reason( Sync_Reason::STOCK_SYNC_OFF ),
				'Синхронизация остатков выключена',
			],
			[
				new Sync_Reason( Sync_Reason::NO_WAREHOUSE ),
				'Не выбран склад',
			],
			[
				new Sync_Reason( Sync_Reason::RATE_LIMITED, [ 'seconds' => 42 ] ),
				'42 с.',
			],
			[
				new Sync_Reason( Sync_Reason::LAST_ERROR, [ 'message' => 'SKU mismatch' ] ),
				'SKU mismatch',
			],
		];

		foreach ( $cases as list( $reason, $needle ) ) {
			$sentence = Sync_Reason_Text::sentence( $reason );
			$this->assertStringContainsString( $needle, $sentence );
			$this->assertStringNotContainsStringIgnoringCase( 'ozon', $sentence );
		}
	}

	public function test_falls_back_to_code_for_unknown_future_reason(): void {
		$reason = new Sync_Reason( 'future_custom_reason' );

		$this->assertSame( 'future_custom_reason', Sync_Reason_Text::sentence( $reason ) );
	}

	public function test_sentences_maps_why_not_syncing_output_in_priority_order(): void {
		$link    = new Product_Link( 1, 10, 0, 'SKU-10', [], Product_Link::STATE_UNLINKED, [ 'sync_stock' => false ] );
		$account = new Account( 1, 'fake', 'Shop', false, [ 'cabinet' => '1', 'token' => 't' ] );
		$reasons = Why_Not_Syncing::reasons(
			$link,
			$account,
			[
				'license_ok'           => false,
				'default_warehouse_id' => null,
			]
		);

		$sentences = Sync_Reason_Text::sentences( $reasons );

		$this->assertCount( count( $reasons ), $sentences );
		$this->assertStringContainsString( 'Лицензия не действует', $sentences[0] );
		$this->assertStringContainsString( 'Аккаунт выключен', $sentences[1] );
	}
}
