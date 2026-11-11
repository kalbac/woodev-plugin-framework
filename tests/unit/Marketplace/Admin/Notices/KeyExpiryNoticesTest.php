<?php
/**
 * Unit tests for tiered, fingerprint-scoped Key_Expiry_Notices.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Notices;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Credential_Fields;
use Woodev\Framework\Marketplace\Admin\Notices\Key_Expiry_Notices;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Account_Store;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace_Adapter;
use Woodev\Tests\Unit\TestCase;

final class KeyExpiryNoticesTest extends TestCase {
	private const NOW = 1794139200; // 2026-11-08 12:00:00 UTC.

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_date' )->alias(
			static function ( string $format, ?int $ts = null ): string {
				return gmdate( $format, $ts ?? self::NOW );
			}
		);
	}

	public function test_skips_healthy_null_expiry_disabled_and_foreign_adapter_accounts(): void {
		$store   = new Fake_Account_Store();
		$adapter = new Fake_Marketplace_Adapter(
			null,
			[
				'id'                => 'fake',
				'capabilities'      => [],
				'credential_fields' => new Credential_Fields( 'cabinet_id', [ 'api_token' ] ),
			]
		);

		// > 30 days left -> no notice.
		$store->save(
			new Account(
				0,
				'fake',
				'Far Future',
				true,
				[ 'cabinet_id' => '100', 'api_token' => 'tok' ],
				new \DateTimeImmutable( '@' . ( self::NOW + 45 * 86400 ) )
			)
		);
		// Null expiry -> no notice.
		$store->save( new Account( 0, 'fake', 'No Expiry', true, [ 'cabinet_id' => '101', 'api_token' => 'tok' ] ) );
		// Disabled even though expired -> no notice.
		$store->save(
			new Account(
				0,
				'fake',
				'Disabled',
				false,
				[ 'cabinet_id' => '102', 'api_token' => 'tok' ],
				new \DateTimeImmutable( '@' . ( self::NOW - 3600 ) )
			)
		);
		// Foreign adapter -> no notice.
		$store->save(
			new Account(
				0,
				'other_mp',
				'Foreign',
				true,
				[ 'cabinet_id' => '103', 'api_token' => 'tok' ],
				new \DateTimeImmutable( '@' . ( self::NOW - 3600 ) )
			)
		);

		$handler = Mockery::mock( \Woodev_Admin_Notice_Handler::class );
		$handler->shouldNotReceive( 'add_admin_notice' );

		$registrar = new Key_Expiry_Notices(
			$handler,
			$store,
			$adapter,
			'https://shop.test/wp-admin/admin.php?page=wc-admin&path=%2Ffake%2Fsettings',
			'fake-mp',
			static function (): int {
				return self::NOW;
			}
		);

		$registrar->add();
	}

	public function test_emits_distinct_fingerprint_and_tier_ids_and_marks_expired_non_dismissible(): void {
		$store   = new Fake_Account_Store();
		$adapter = new Fake_Marketplace_Adapter(
			null,
			[
				'id'                => 'fake',
				'capabilities'      => [],
				'credential_fields' => new Credential_Fields( 'cabinet_id', [ 'api_token' ] ),
			]
		);

		$id_warn = $store->save(
			new Account(
				0,
				'fake',
				'Main Shop',
				true,
				[ 'cabinet_id' => '555', 'api_token' => 'tok-a' ],
				new \DateTimeImmutable( '@' . ( self::NOW + 5 * 86400 ) ),
				[ 'secret_fingerprint' => 'abcdef1234567890' ]
			)
		);

		$id_exp = $store->save(
			new Account(
				0,
				'fake',
				'',
				true,
				[ 'cabinet_id' => 'CAB-777', 'api_token' => 'tok-b' ],
				new \DateTimeImmutable( '@' . ( self::NOW - 60 ) ),
				[ 'secret_fingerprint' => '98765432fedcba00' ]
			)
		);

		$captured = [];
		$handler  = Mockery::mock( \Woodev_Admin_Notice_Handler::class );
		$handler->shouldReceive( 'add_admin_notice' )
			->twice()
			->andReturnUsing(
				static function ( string $msg, string $notice_id, array $params ) use ( &$captured ): void {
					$captured[ $notice_id ] = [
						'message' => $msg,
						'params'  => $params,
					];
				}
			);

		$registrar = new Key_Expiry_Notices(
			$handler,
			$store,
			$adapter,
			'https://shop.test/wp-admin/admin.php?page=wc-admin&path=%2Ffake%2Fsettings',
			'fake-mp',
			static function (): int {
				return self::NOW;
			}
		);

		$registrar->add();

		$warn_key = "fake-mp-key-expiry-{$id_warn}-abcdef12-7";
		$exp_key  = "fake-mp-key-expiry-{$id_exp}-98765432-expired";

		$this->assertArrayHasKey( $warn_key, $captured );
		$this->assertSame( 'notice-warning', $captured[ $warn_key ]['params']['notice_class'] );
		$this->assertTrue( $captured[ $warn_key ]['params']['dismissible'] );
		$this->assertStringContainsString( 'Main Shop', $captured[ $warn_key ]['message'] );
		$this->assertStringContainsString( 'осталось дней: 5', $captured[ $warn_key ]['message'] );
		$this->assertStringContainsString( 'Открыть настройки', $captured[ $warn_key ]['message'] );

		$this->assertArrayHasKey( $exp_key, $captured );
		$this->assertSame( 'notice-error', $captured[ $exp_key ]['params']['notice_class'] );
		$this->assertFalse( $captured[ $exp_key ]['params']['dismissible'] );
		$this->assertStringContainsString( 'CAB-777', $captured[ $exp_key ]['message'] );
		$this->assertStringContainsString( 'Срок действия ключа истёк', $captured[ $exp_key ]['message'] );
	}
}
