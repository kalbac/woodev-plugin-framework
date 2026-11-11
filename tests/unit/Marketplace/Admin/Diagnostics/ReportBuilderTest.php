<?php
/**
 * Unit tests for Report_Builder, Diagnostics_Reader, Site_Facts and Report_Redactor.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Diagnostics;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Credential_Fields;
use Woodev\Framework\Marketplace\Account\Health_Result;
use Woodev\Framework\Marketplace\Account\Secret_Cipher;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Diagnostics_Reader;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Logging;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Queue_Health;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Report_Builder;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Site_Facts;
use Woodev\Framework\Marketplace\Capabilities\Capability;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Catalog_Cache;
use Woodev\Framework\Marketplace\Diagnostics\Api_Error_Ring;
use Woodev\Framework\Marketplace\Diagnostics\Report_Section;
use Woodev\Framework\Marketplace\Intake\Last_Notification_Reader;
use Woodev\Framework\Marketplace\Intake\Notification_Account_Reader;
use Woodev\Framework\Marketplace\Jobs\Job_Names;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Settings\Credential_Field_Spec;
use Woodev\Framework\Marketplace\Settings\Warehouse_Options;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Settings_Descriptor;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace_Adapter;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;
use Woodev\Tests\Unit\TestCase;

final class ReportBuilderTest extends TestCase {
	private const NOW              = 1794139200;
	private const LIVE_API_SECRET  = 'live-secret-val-dummy-token-for-tests';
	private const LIVE_SIGN_SECRET = 'sign-secret-val-dummy-token-for-tests';

	/** @var Sqlite_Wpdb */
	private $db;

	/** @var Schema */
	private $schema;

	/** @var Wpdb_Catalog_Cache */
	private $cache;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		// Action Scheduler has run nothing: the queue line reads «no run yet».
		Functions\when( 'as_get_scheduled_actions' )->justReturn( [] );
		Functions\when( 'wp_date' )->alias(
			static function ( string $format, ?int $ts = null ): string {
				return gmdate( $format, $ts ?? self::NOW );
			}
		);
		Functions\when( 'human_time_diff' )->justReturn( '5 мин' );
		Functions\when( 'get_bloginfo' )->justReturn( '6.7.1' );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\when( 'wp_get_theme' )->justReturn(
			new class() {
				public function get( string $header ): string {
					return 'Name' === $header ? 'Storefront' : '4.6.0';
				}
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://shop.example' );
		Functions\when( 'rest_url' )->alias(
			static function ( string $path = '' ): string {
				return 'https://shop.example/wp-json/' . ltrim( $path, '/' );
			}
		);
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( [ 'woocommerce/woocommerce.php' ] );

		$this->db     = new Sqlite_Wpdb();
		$this->schema = Sqlite_Tables::schema();
		Sqlite_Tables::install( $this->db );
		$this->cache = new Wpdb_Catalog_Cache(
			$this->db,
			$this->schema->table_name( 'catalog_cache', Sqlite_Tables::PREFIX ),
			static function (): int {
				return self::NOW;
			}
		);
	}

	public function test_hides_troubleshooting_hints_diagnostic_sections_and_support_report_when_logging_is_off(): void {
		$fields  = new Credential_Fields( 'cabinet_code', [ 'vendor_key', 'signing_phrase' ] );
		$adapter = $this->make_adapter( $fields, [ Capability::PUSH_NOTIFICATIONS, Capability::IMPORT_PRODUCTS ] );
		$store   = Wpdb_Account_Store::for_adapter(
			$this->db,
			$this->schema->table_name( 'accounts', Sqlite_Tables::PREFIX ),
			new Secret_Cipher( 'salt-a' ),
			$adapter,
			static function (): int {
				return self::NOW;
			}
		);

		$acc_id = $store->save(
			new Account(
				0,
				Sqlite_Tables::MARKETPLACE_ID,
				'Cabinet One',
				true,
				[
					'cabinet_code'   => 'CAB-12345678',
					'vendor_key'     => self::LIVE_API_SECRET,
					'signing_phrase' => self::LIVE_SIGN_SECRET,
				]
			)
		);
		$store->set_health( $acc_id, new Health_Result( Health_Result::ROLE_MISSING, 'Role missing' ) );

		$builder = $this->make_builder( $adapter, $store, false );

		$operational = $builder->operational_sections();
		$this->assertCount( 2, $operational );
		$account_lines = $operational[1]->lines();
		$this->assertArrayNotHasKey( 'Что делать', $account_lines );
		$this->assertSame( [], $builder->diagnostic_sections() );
		$this->assertSame( '', $builder->support_report() );
	}

	public function test_redacts_headers_tokens_custom_secret_fields_and_live_account_secrets_when_logging_is_on(): void {
		$fields     = new Credential_Fields( 'cabinet_code', [ 'vendor_key', 'signing_phrase' ] );
		$descriptor = new Fake_Settings_Descriptor(
			$fields,
			'Acme Market',
			[
				'cabinet_code'   => new Credential_Field_Spec( 'Код кабинета' ),
				'vendor_key'     => new Credential_Field_Spec( 'Ключ поставщика' ),
				'signing_phrase' => new Credential_Field_Spec( 'Фраза подписи' ),
			]
		);

		$adapter = new Fake_Marketplace_Adapter(
			null,
			[
				'id'                  => Sqlite_Tables::MARKETPLACE_ID,
				'capabilities'        => [ Capability::PUSH_NOTIFICATIONS, Capability::IMPORT_PRODUCTS ],
				'credential_fields'   => $fields,
				'settings_descriptor' => $descriptor,
				'support_sections'    => [
					new Report_Section(
						'Эндпоинты адаптера',
						[
							'Фраза подписи'   => 'adapter-accidental-secret',
							'Заголовок теста' => 'Authorization: Bearer jwt-abc-999; vendor_key=inline_val_123',
						]
					),
				],
			]
		);

		$store = Wpdb_Account_Store::for_adapter(
			$this->db,
			$this->schema->table_name( 'accounts', Sqlite_Tables::PREFIX ),
			new Secret_Cipher( 'salt-a' ),
			$adapter,
			static function (): int {
				return self::NOW;
			}
		);

		$acc_id = $store->save(
			new Account(
				0,
				Sqlite_Tables::MARKETPLACE_ID,
				'Acme Primary',
				true,
				[
					'cabinet_code'   => '9876543210',
					'vendor_key'     => self::LIVE_API_SECRET,
					'signing_phrase' => self::LIVE_SIGN_SECRET,
				]
			)
		);

		$tested = [
			'cabinet_code'   => '9876543210',
			'vendor_key'     => self::LIVE_API_SECRET,
			'signing_phrase' => self::LIVE_SIGN_SECRET,
		];
		$store->set_health_for_credentials(
			$acc_id,
			$tested,
			new Health_Result(
				Health_Result::AUTH_FAILED,
				'Auth rejected key ' . self::LIVE_API_SECRET . ' and {"signing_phrase":"raw-json-secret"}',
				null,
				[
					'limits' => [
						'Суточный лимит создания' => [
							'usage'    => 15,
							'limit'    => 100,
							'reset_at' => '00:00 UTC',
						],
					],
				]
			)
		);

		// Populate warehouses, tree cache, an inbound event with a leaked token, and an API error with headers + webhook URL.
		$this->cache->put( Warehouse_Options::key_for( $acc_id ), [ [ 'id' => 77, 'name' => 'Хоругвино' ] ] );
		$this->cache->put( Wpdb_Catalog_Cache::KEY_TREE, [ 'nodes' => [] ] );
		$store->update_fields( $acc_id, [ 'warehouse_id' => 77, 'currency' => 'RUB' ] );

		$notif_reader  = new Notification_Account_Reader( $this->db, $this->schema->table_name( 'accounts', Sqlite_Tables::PREFIX ) );
		$webhook_token = $notif_reader->find( $acc_id )->token();

		$this->db->insert(
			'wp_woodev_fake_events',
			[
				'account_id'  => $acc_id,
				'dedup_key'   => 'd-1',
				'type'        => 'TYPE_NEW_ORDER',
				'payload'     => '{}',
				'status'      => 'failed',
				'attempts'    => 2,
				'result'      => 'Echoed ' . self::LIVE_SIGN_SECRET . ' and token ' . $webhook_token,
				'received_at' => gmdate( 'Y-m-d H:i:s', self::NOW - 180 ),
			]
		);

		$ring = new Api_Error_Ring(
			$this->db,
			$this->schema->table_name( 'api_errors', Sqlite_Tables::PREFIX ),
			static function (): int {
				return self::NOW - 30;
			}
		);
		$ring->record(
			$acc_id,
			'/wp-json/woodev/v1/fake/notifications/' . $acc_id . '/' . $webhook_token,
			403,
			'FORBIDDEN',
			'Api-Key: header-secret-555, X-Api-Key: second-secret-666'
		);

		$builder     = $this->make_builder( $adapter, $store, true );
		$operational = $builder->operational_sections();
		$diagnostic  = $builder->diagnostic_sections();
		$report      = $builder->support_report( $operational, $diagnostic );

		$this->assertNotEmpty( $diagnostic );
		$this->assertStringContainsString( 'Что делать', $report );
		$this->assertStringContainsString( 'Хоругвино', $report );
		$this->assertStringContainsString( '15 / 100 (00:00 UTC)', $report );
		$this->assertStringContainsString( 'обновлено сегодня', $report );
		$this->assertStringContainsString( '***3210', $report );
		$this->assertStringContainsString( 'Кэш объектов: внешний', $report, 'a persistent object cache explains stale transients' );
		$this->assertStringContainsString( 'Storefront: 4.6.0', $report, 'the active theme is a fact support asks for' );

		// Every secret, token, header value and custom secret field value must be redacted!
		$forbidden = [
			self::LIVE_API_SECRET,
			self::LIVE_SIGN_SECRET,
			$webhook_token,
			'raw-json-secret',
			'adapter-accidental-secret',
			'jwt-abc-999',
			'inline_val_123',
			'header-secret-555',
			'second-secret-666',
			'9876543210',
		];

		foreach ( $forbidden as $secret ) {
			$this->assertNotSame( '', $secret );
			$this->assertStringNotContainsString( $secret, $report, "Secret leaked into support report: {$secret}" );
		}
	}

	public function test_link_counts_use_the_real_card_states_and_count_offers_only(): void {
		$rows = [
			[ 1, 'offer', 'queued' ],
			[ 1, 'offer', 'queued' ],
			[ 1, 'offer', 'submitted' ],
			[ 1, 'offer', 'price_sent' ],
			[ 1, 'offer', 'created' ],
			[ 1, 'offer', 'failed' ],
			[ 1, 'parent', 'queued' ],
			[ 2, 'offer', 'queued' ],
		];
		foreach ( $rows as $index => $row ) {
			$this->db->insert(
				'wp_woodev_fake_links',
				[
					'account_id'    => $row[0],
					'wc_product_id' => 100 + $index,
					'kind'          => $row[1],
					'card_state'    => $row[2],
				]
			);
		}

		$counts = ( new Diagnostics_Reader( $this->db, $this->schema ) )->link_counts( 1 );

		$this->assertSame(
			[
				'created'    => 1,
				'failed'     => 1,
				'price_sent' => 1,
				'queued'     => 2,
				'submitted'  => 1,
			],
			$counts,
			'products waiting for export are counted, the parent row and another account are not'
		);
	}

	public function test_quota_snapshot_is_ignored_after_identifier_rotation_and_push_sections_respect_capability(): void {
		$fields  = new Credential_Fields( 'cabinet_code', [ 'vendor_key' ] );
		$adapter = $this->make_adapter( $fields, [] ); // No PUSH_NOTIFICATIONS, no IMPORT_PRODUCTS.
		$store   = Wpdb_Account_Store::for_adapter(
			$this->db,
			$this->schema->table_name( 'accounts', Sqlite_Tables::PREFIX ),
			new Secret_Cipher( 'salt-a' ),
			$adapter,
			static function (): int {
				return self::NOW;
			}
		);

		$acc_id = $store->save(
			new Account(
				0,
				Sqlite_Tables::MARKETPLACE_ID,
				'No Push Shop',
				true,
				[ 'cabinet_code' => 'OLD-1111', 'vendor_key' => 'secret-1111' ],
				null,
				[
					'limits_snapshot' => [
						'external_id' => 'STALE-OTHER-ID',
						'fetched_at'  => self::NOW,
						'limits'      => [ 'Лимит' => '10 / 50' ],
					],
				]
			)
		);

		$builder     = $this->make_builder( $adapter, $store, true );
		$operational = $builder->operational_sections();
		$diagnostic  = $builder->diagnostic_sections();
		$titles      = array_map(
			static function ( Report_Section $s ): string {
				return $s->title();
			},
			$diagnostic
		);

		$this->assertArrayNotHasKey( 'Адрес уведомлений', $operational[1]->lines() );
		foreach ( $titles as $title ) {
			$this->assertStringNotContainsString( 'Лимиты', $title );
			$this->assertStringNotContainsString( 'Последние уведомления', $title );
		}
		$this->assertGreaterThan( 0, $acc_id );
	}

	/**
	 * @param Credential_Fields $fields       Credential fields.
	 * @param string[]          $capabilities Capabilities.
	 */
	private function make_adapter( Credential_Fields $fields, array $capabilities ): Fake_Marketplace_Adapter {
		return new Fake_Marketplace_Adapter(
			null,
			[
				'id'                  => Sqlite_Tables::MARKETPLACE_ID,
				'capabilities'        => $capabilities,
				'credential_fields'   => $fields,
				'settings_descriptor' => new Fake_Settings_Descriptor( $fields, 'Acme Market' ),
			]
		);
	}

	private function make_builder( Fake_Marketplace_Adapter $adapter, Wpdb_Account_Store $store, bool $logging_enabled ): Report_Builder {
		$warehouses = new Warehouse_Options( $this->cache );
		$license    = new License_Gate(
			static function (): bool {
				return true;
			}
		);
		$logging    = new Logging(
			'woodev_fake_advanced_logging',
			static function () use ( $logging_enabled ): string {
				return $logging_enabled ? 'yes' : 'no';
			}
		);
		$clock      = static function (): int {
			return self::NOW;
		};
		$queue      = new Queue_Health( $this->db, new Job_Names( 'fake' ), $this->schema, $clock );
		$reader     = new Diagnostics_Reader( $this->db, $this->schema, $clock );
		$site       = new Site_Facts( $adapter, $this->cache, 'Fake Seller Plugin', '1.2.3', $clock );

		return new Report_Builder(
			$adapter,
			$store,
			$warehouses,
			$license,
			$logging,
			$queue,
			$reader,
			$site,
			new Notification_Account_Reader( $this->db, $this->schema->table_name( 'accounts', Sqlite_Tables::PREFIX ) ),
			new Last_Notification_Reader( $this->db, $this->schema->table_name( 'events', Sqlite_Tables::PREFIX ) ),
			$clock
		);
	}
}
