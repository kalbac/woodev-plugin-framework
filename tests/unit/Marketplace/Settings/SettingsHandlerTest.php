<?php
/**
 * Unit tests for Settings_Handler, Accounts_Sections, Account_Status and Warehouse_Options.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Settings;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Credential_Fields;
use Woodev\Framework\Marketplace\Account\Credential_Health;
use Woodev\Framework\Marketplace\Account\Health_Result;
use Woodev\Framework\Marketplace\Account\Secret_Cipher;
use Woodev\Framework\Marketplace\Capabilities\Capability;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Catalog_Cache;
use Woodev\Framework\Marketplace\Settings\Account_Status;
use Woodev\Framework\Marketplace\Settings\Accounts_Sections;
use Woodev\Framework\Marketplace\Settings\Credential_Field_Spec;
use Woodev\Framework\Marketplace\Settings\Settings_Handler;
use Woodev\Framework\Marketplace\Settings\Warehouse_Options;
use Woodev\Framework\Settings\Field_Schema;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Settings_Descriptor;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace_Adapter;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;
use Woodev\Tests\Unit\TestCase;

final class SettingsHandlerTest extends TestCase {
	private const NOW = 1794139200;

	/** @var Sqlite_Wpdb */
	private $db;

	/** @var Schema */
	private $schema;

	/** @var Wpdb_Catalog_Cache */
	private $cache;

	/** @var array<string,mixed> */
	private $options = [];

	protected function setUp(): void {
		parent::setUp();
		$this->options = [];

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, array $defaults = [] ): array {
				return array_merge( $defaults, (array) $args );
			}
		);
		Functions\when( 'sanitize_key' )->alias(
			static function ( string $key ): string {
				return strtolower( (string) preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) );
			}
		);
		Functions\when( 'wp_date' )->alias(
			static function ( string $format, ?int $ts = null ): string {
				return gmdate( $format, $ts ?? self::NOW );
			}
		);
		Functions\when( 'wc_string_to_bool' )->alias(
			static function ( string $val ): bool {
				return in_array( strtolower( $val ), [ 'yes', 'true', '1' ], true );
			}
		);
		Functions\when( 'wc_bool_to_string' )->alias(
			static function ( bool $val ): string {
				return $val ? 'yes' : 'no';
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( string $key, $default = false ) {
				return array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ): bool {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $key ): bool {
				unset( $this->options[ $key ] );
				return true;
			}
		);

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

	public function test_renders_multi_secret_adapter_fields_masks_secrets_in_schema_and_preserves_blank_saves(): void {
		$fields     = new Credential_Fields( 'merchant_uid', [ 'access_token', 'signing_secret' ] );
		$descriptor = new Fake_Settings_Descriptor(
			$fields,
			'MultiSecret Market',
			[
				'merchant_uid'   => new Credential_Field_Spec(
					'UID продавца',
					'Числовой код продавца',
					'100500',
					static function ( string $val ): ?string {
						return ctype_digit( $val ) ? null : 'UID продавца должен состоять только из цифр.';
					}
				),
				'access_token'   => new Credential_Field_Spec( 'Токен доступа', 'Основной токен' ),
				'signing_secret' => new Credential_Field_Spec( 'Секрет подписи', 'Ключ подписи запросов' ),
			]
		);

		$adapter = new Fake_Marketplace_Adapter(
			null,
			[
				'id'                  => Sqlite_Tables::MARKETPLACE_ID,
				'capabilities'        => [ Capability::INBOUND_STOCK, Capability::IMPORT_PRODUCTS ],
				'credential_fields'   => $fields,
				'settings_descriptor' => $descriptor,
			]
		);

		$store = Wpdb_Account_Store::for_adapter(
			$this->db,
			$this->schema->table_name( 'accounts', Sqlite_Tables::PREFIX ),
			new Secret_Cipher( 'salt-1' ),
			$adapter,
			static function (): int {
				return self::NOW;
			}
		);

		$acc_id = $store->save(
			new Account(
				0,
				Sqlite_Tables::MARKETPLACE_ID,
				'Shop One',
				true,
				[
					'merchant_uid'   => '424242',
					'access_token'   => 'super-secret-access-token-111',
					'signing_secret' => 'super-secret-signing-key-222',
				]
			)
		);

		$this->cache->put(
			Warehouse_Options::key_for( $acc_id ),
			[ 'warehouses' => [ [ 'warehouse_id' => 91, 'name' => 'Склад Юг' ] ] ]
		);

		$handler = $this->build_handler( $adapter, $store );

		// Field_Schema must mark both secrets sensitive, set is_set=true, value='', and never leak plaintext secrets!
		$by_id     = Field_Schema::from_handler( $handler );
		$json_dump = (string) json_encode( $by_id );

		$this->assertStringNotContainsString( 'super-secret-access-token-111', $json_dump );
		$this->assertStringNotContainsString( 'super-secret-signing-key-222', $json_dump );

		$this->assertSame( 'UID продавца', $by_id[ "account_{$acc_id}_merchant_uid" ]['name'] );
		$this->assertSame( '424242', $by_id[ "account_{$acc_id}_merchant_uid" ]['value'] );

		foreach ( [ 'access_token', 'signing_secret' ] as $sec_key ) {
			$field_entry = $by_id[ "account_{$acc_id}_{$sec_key}" ];
			$this->assertTrue( $field_entry['sensitive'] );
			$this->assertTrue( $field_entry['is_set'] );
			$this->assertSame( '', $field_entry['value'] );
			$this->assertSame( \Woodev_Control::TYPE_PASSWORD, $field_entry['controlType'] );
		}

		// Saving with blank ('') secret inputs preserves both stored secrets while updating the name & warehouse.
		$handler->get_setting( "account_{$acc_id}_name" )->set_value( 'Renamed Shop' );
		$handler->get_setting( "account_{$acc_id}_access_token" )->set_value( '' );
		$handler->get_setting( "account_{$acc_id}_signing_secret" )->set_value( '' );
		$handler->get_setting( "account_{$acc_id}_warehouse_id" )->set_value( '91' );
		$handler->save();

		$reloaded = $store->get( $acc_id );
		$this->assertNotNull( $reloaded );
		$this->assertSame( 'Renamed Shop', $reloaded->name() );
		$this->assertSame( 91, $reloaded->setting( 'warehouse_id' ) );
		$this->assertSame( 'super-secret-access-token-111', $reloaded->credentials()['access_token'] );
		$this->assertSame( 'super-secret-signing-key-222', $reloaded->credentials()['signing_secret'] );
	}

	public function test_validates_adapter_rules_duplicates_half_filled_new_block_and_price_rules(): void {
		$fields     = new Credential_Fields( 'merchant_uid', [ 'access_token' ] );
		$descriptor = new Fake_Settings_Descriptor(
			$fields,
			'Acme',
			[
				'merchant_uid' => new Credential_Field_Spec(
					'UID',
					'',
					'',
					static function ( string $val ): ?string {
						return ctype_digit( $val ) ? null : 'Только цифры.';
					}
				),
			]
		);

		$adapter = new Fake_Marketplace_Adapter(
			null,
			[
				'id'                  => Sqlite_Tables::MARKETPLACE_ID,
				'capabilities'        => [],
				'credential_fields'   => $fields,
				'settings_descriptor' => $descriptor,
			]
		);

		$store = Wpdb_Account_Store::for_adapter(
			$this->db,
			$this->schema->table_name( 'accounts', Sqlite_Tables::PREFIX ),
			new Secret_Cipher( 'salt-1' ),
			$adapter
		);

		$id1 = $store->save(
			new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'First', true, [ 'merchant_uid' => '111', 'access_token' => 'tok1' ] )
		);

		$handler = $this->build_handler( $adapter, $store );

		// 1. Adapter format error + half-filled new block + invalid min_percent.
		$errors = $handler->validate_values(
			[
				"account_{$id1}_merchant_uid"      => 'abc-not-digits',
				"account_{$id1}_price_min_percent" => '150',
				'account_new_merchant_uid'         => '222',
				'account_new_access_token'         => '',
			]
		);

		$this->assertSame( 'Только цифры.', $errors[ "account_{$id1}_merchant_uid" ] ?? null );
		$this->assertArrayHasKey( "account_{$id1}_price_min_percent", $errors );
		$this->assertArrayHasKey( 'account_new_access_token', $errors );

		// 2. Duplicate identifier on new block.
		$dup_errors = $handler->validate_values(
			[
				'account_new_merchant_uid' => '111',
				'account_new_access_token' => 'tok2',
			]
		);
		$this->assertArrayHasKey( 'account_new_merchant_uid', $dup_errors );
	}

	public function test_omits_unsupported_capabilities_and_fires_price_recompute_hook_and_connection_test_callback(): void {
		$fields        = new Credential_Fields( 'cabinet_id', [ 'api_token' ] );
		$health_called = 0;

		$health = new class( $health_called ) implements Credential_Health {
			/** @var int */
			private $calls;

			public function __construct( int &$calls ) {
				$this->calls = &$calls;
			}

			public function check( Account $account ): Health_Result {
				++$this->calls;
				return new Health_Result(
					Health_Result::OK,
					'OK leaked ' . (string) ( $account->credentials()['api_token'] ?? '' ),
					new \DateTimeImmutable( '@' . ( 1794139200 + 60 * 86400 ) ),
					[ 'currency' => 'KZT' ]
				);
			}
		};

		// No INBOUND_STOCK, no IMPORT_PRODUCTS, posting_handler=null, price_reader=null.
		$adapter = new Fake_Marketplace_Adapter(
			null,
			[
				'id'                => Sqlite_Tables::MARKETPLACE_ID,
				'capabilities'      => [],
				'credential_fields' => $fields,
				'credential_health' => $health,
				'posting_handler'   => null,
				'price_reader'      => null,
			]
		);

		$store = Wpdb_Account_Store::for_adapter(
			$this->db,
			$this->schema->table_name( 'accounts', Sqlite_Tables::PREFIX ),
			new Secret_Cipher( 'salt-1' ),
			$adapter,
			static function (): int {
				return self::NOW;
			}
		);

		$acc_id = $store->save(
			new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'Minimal Cap', true, [ 'cabinet_id' => '700', 'api_token' => 'secret-token-700' ] )
		);

		$connected_ids = [];
		$handler       = $this->build_handler(
			$adapter,
			$store,
			static function ( int $id ) use ( &$connected_ids ): void {
				$connected_ids[] = $id;
			}
		);

		// Unsupported capabilities must NOT register settings at all (D2).
		$this->assertNull( $handler->get_setting( "account_{$acc_id}_stock_mode" ) );
		$this->assertNull( $handler->get_setting( "account_{$acc_id}_import_enabled" ) );
		$this->assertNull( $handler->get_setting( "account_{$acc_id}_orders_enabled" ) );

		// Unchecked account returns null connection status without calling remote health!
		$this->assertNull( $handler->get_connection_status( "account_connection_{$acc_id}" ) );
		$this->assertSame( 0, $health_called );

		// Live test_connection falls back to stored secret when input is '', redacts message, updates currency, calls on_connected.
		$res = $handler->test_connection(
			"account_connection_{$acc_id}",
			[
				"account_{$acc_id}_cabinet_id" => '700',
				"account_{$acc_id}_api_token"  => '',
			]
		);

		$this->assertTrue( $res->is_success() );
		$this->assertStringNotContainsString( 'secret-token-700', $res->get_message() );
		$this->assertSame( [ $acc_id ], $connected_ids );
		$this->assertSame( 'KZT', $store->get( $acc_id )->setting( 'currency' ) );

		$stored_status = $handler->get_connection_status( "account_connection_{$acc_id}" );
		$this->assertNotNull( $stored_status );
		$this->assertTrue( $stored_status->is_success() );
		$this->assertStringContainsString( 'KZT', $stored_status->get_message() );

		// Saving a price rule change fires `woodev_fake_mp_account_price_rules_changed`.
		Actions\expectDone( 'woodev_fake_mp_account_price_rules_changed' )->once()->with( $acc_id );
		$handler->get_setting( "account_{$acc_id}_price_markup_kind" )->set_value( 'percent' );
		$handler->get_setting( "account_{$acc_id}_price_markup" )->set_value( '15' );
		$handler->save( "account_{$acc_id}_price_markup_kind" );
	}

	private function build_handler(
		Fake_Marketplace_Adapter $adapter,
		Wpdb_Account_Store $store,
		?callable $on_connected = null
	): Settings_Handler {
		$status   = new Account_Status(
			$store,
			$adapter,
			static function (): int {
				return self::NOW;
			}
		);
		$wh       = new Warehouse_Options( $this->cache );
		$accounts = new Accounts_Sections( $store, $adapter, $status, $wh, 'fake_mp', $on_connected );

		return new Settings_Handler( 'fake_mp', [ $accounts ], 'Fake Market' );
	}
}
