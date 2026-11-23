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

	/** @var int Seconds the test clock runs ahead of NOW. */
	private $clock_shift = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->options     = [];
		$this->clock_shift = 0;

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
				'capabilities'        => [ Capability::INBOUND_STOCK, Capability::IMPORT_PRODUCTS, Capability::OVERSIZE_WAREHOUSE ],
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
		$handler->get_setting( "account_{$acc_id}_oversize_warehouse_id" )->set_value( '91' );
		$handler->get_setting( "account_{$acc_id}_stock_mode" )->set_value( 'off' );
		$handler->save();

		$reloaded = $store->get( $acc_id );
		$this->assertNotNull( $reloaded );
		$this->assertSame( 'Renamed Shop', $reloaded->name() );
		$this->assertSame( 91, $reloaded->setting( 'warehouse_id' ) );
		$this->assertSame( 91, $reloaded->setting( 'oversize_warehouse_id' ), 'the oversize warehouse lives in the settings JSON' );
		$this->assertSame( 'off', $reloaded->setting( 'stock_mode' ), 'the stock mode «Не синхронизировать»' );
		$this->assertSame( 'super-secret-access-token-111', $reloaded->credentials()['access_token'] );
		$this->assertSame( 'super-secret-signing-key-222', $reloaded->credentials()['signing_secret'] );
	}

	public function test_stock_mode_offers_site_and_off_always_and_marketplace_only_with_inbound_stock(): void {
		[ $bare_handler, , $bare_id ]       = $this->stock_mode_handler( [] );
		[ $inbound_handler, , $inbound_id ] = $this->stock_mode_handler( [ Capability::INBOUND_STOCK ], '2' );

		$this->assertSame( [ 'site', 'off' ], $this->stock_mode_values( $bare_handler, $bare_id ), 'without INBOUND_STOCK «Не синхронизировать» stays' );
		$this->assertSame( [ 'site', 'marketplace', 'off' ], $this->stock_mode_values( $inbound_handler, $inbound_id ) );
	}

	public function test_saving_stock_mode_marketplace_is_refused_without_inbound_stock_and_off_is_kept(): void {
		[ $handler, $store, $acc_id ] = $this->stock_mode_handler( [] );

		$handler->get_setting( "account_{$acc_id}_stock_mode" )->set_value( 'off' );
		$handler->save();
		$this->assertSame( 'off', $store->get( $acc_id )->setting( 'stock_mode' ), 'off is saved without the capability' );

		$handler->get_setting( "account_{$acc_id}_stock_mode" )->set_value( 'marketplace' );
		$handler->save();
		$this->assertSame( 'off', $store->get( $acc_id )->setting( 'stock_mode' ), 'marketplace is refused: the stored value stays' );
	}

	public function test_saving_stock_mode_marketplace_is_accepted_with_inbound_stock(): void {
		[ $handler, $store, $acc_id ] = $this->stock_mode_handler( [ Capability::INBOUND_STOCK ] );

		$handler->get_setting( "account_{$acc_id}_stock_mode" )->set_value( 'marketplace' );
		$handler->save();

		$this->assertSame( 'marketplace', $store->get( $acc_id )->setting( 'stock_mode' ) );
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
		$this->assertNotNull( $handler->get_setting( "account_{$acc_id}_stock_mode" ), 'the stock select is always there (site and off)' );
		$this->assertNull( $handler->get_setting( "account_{$acc_id}_import_enabled" ) );
		$this->assertNull( $handler->get_setting( "account_{$acc_id}_orders_enabled" ) );

		// Unchecked account returns null connection status without calling remote health!
		$this->assertNull( $handler->get_connection_status( "account_connection_{$acc_id}" ) );
		$this->assertSame( 0, $health_called );

		// Live test_connection falls back to stored secret when input is '', redacts message, updates currency, calls on_connected.
		Actions\expectDone( 'woodev_fake_mp_account_connected' )->once()->with( $acc_id );
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

	public function test_a_failed_connection_test_does_not_fire_account_connected(): void {
		$fields = new Credential_Fields( 'cabinet_id', [ 'api_token' ] );
		$health = new class() implements Credential_Health {
			public function check( Account $account ): Health_Result {
				return new Health_Result( Health_Result::AUTH_FAILED, 'Ключ отклонён', null, [] );
			}
		};

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

		$acc_id  = $store->save(
			new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'Failing Shop', true, [ 'cabinet_id' => '701', 'api_token' => 'secret-token-701' ] )
		);
		$handler = $this->build_handler( $adapter, $store );

		Actions\expectDone( 'woodev_fake_mp_account_connected' )->never();
		$res = $handler->test_connection(
			"account_connection_{$acc_id}",
			[
				"account_{$acc_id}_cabinet_id" => '701',
				"account_{$acc_id}_api_token"  => '',
			]
		);

		$this->assertFalse( $res->is_success() );
	}

	public function test_the_oversize_warehouse_list_puts_flagged_warehouses_first_and_marks_them(): void {
		$this->cache->put(
			Warehouse_Options::key_for( 5 ),
			[
				'warehouses' => [
					[ 'warehouse_id' => 1, 'name' => 'Склад А' ],
					[ 'warehouse_id' => 2, 'name' => 'Склад Б', 'oversize' => true ],
					[ 'warehouse_id' => 3, 'name' => 'Склад В' ],
					[ 'warehouse_id' => 4, 'name' => 'Склад Г', 'oversize' => true ],
				],
			]
		);
		$options = new Warehouse_Options( $this->cache, $this->clock() );

		$this->assertSame( [ 1, 2, 3, 4 ], array_keys( $options->for_account( 5 ) ), 'the default warehouse select keeps the order' );
		$this->assertSame( 'Склад Б', $options->for_account( 5 )['2'], 'and does not mark anything' );
		$this->assertSame( [ 2, 4, 1, 3 ], array_keys( $options->for_account( 5, true ) ), 'flagged first, stable inside the groups' );
		$this->assertSame( 'Склад Б (КГТ)', $options->for_account( 5, true )['2'] );
		$this->assertSame( 'Склад А', $options->for_account( 5, true )['1'] );
	}

	/**
	 * Fills the cached warehouse list of an account.
	 *
	 * @param int   $account_id Account id.
	 * @param int[] $ids        Ids of the available warehouses.
	 * @return void
	 */
	private function cache_warehouses( int $account_id, array $ids ): void {
		$this->cache->put(
			Warehouse_Options::key_for( $account_id ),
			[
				'warehouses' => array_map(
					static function ( int $id ): array {
						return [ 'warehouse_id' => $id, 'name' => 'Склад ' . $id ];
					},
					$ids
				),
			]
		);
	}

	public function test_a_saved_warehouse_missing_from_a_known_list_is_noted_as_switched_off(): void {
		[ $handler, , $acc_id ] = $this->stock_mode_handler( [], '1', true, [ 'warehouse_id' => 7 ], [ 1, 2 ] );

		$schema  = Field_Schema::from_handler( $handler );
		$options = array_column( $schema[ "account_{$acc_id}_warehouse_id" ]['options'] ?? [], 'label', 'value' );

		$this->assertSame( '#7 (Склад отключён в маркетплейсе — выберите другой)', $options['7'] );
		$this->assertSame( 'Склад 1', $options['1'], 'the available ones are listed as before' );
	}

	public function test_a_saved_warehouse_stays_a_bare_id_while_the_list_is_not_cached(): void {
		[ $handler, , $acc_id ] = $this->stock_mode_handler( [], '1', true, [ 'warehouse_id' => 7 ] );

		$schema  = Field_Schema::from_handler( $handler );
		$options = array_column( $schema[ "account_{$acc_id}_warehouse_id" ]['options'] ?? [], 'label', 'value' );

		$this->assertSame( '#7', $options['7'], 'an empty cache proves nothing' );
	}

	public function test_the_account_status_reports_an_unavailable_warehouse_once_and_only_for_a_known_list(): void {
		[ , $store, $acc_id ] = $this->stock_mode_handler( [] );
		$store->update_fields( $acc_id, [ 'warehouse_id' => 7 ] );
		$store->set_health( $acc_id, new Health_Result( Health_Result::OK ) );
		$adapter = new Fake_Marketplace_Adapter(
			null,
			[
				'id'                  => Sqlite_Tables::MARKETPLACE_ID,
				'capabilities'        => [],
				'credential_fields'   => new Credential_Fields( 'merchant_uid', [ 'access_token' ] ),
				'settings_descriptor' => new Fake_Settings_Descriptor( new Credential_Fields( 'merchant_uid', [ 'access_token' ] ), 'Acme', [] ),
			]
		);
		$status  = new Account_Status(
			$store,
			$adapter,
			static function (): int {
				return self::NOW;
			},
			new Warehouse_Options( $this->cache, $this->clock() )
		);

		$this->assertTrue( $status->for_account( $store->get( $acc_id ) )->is_success(), 'no cache, nothing to report' );

		$this->cache_warehouses( $acc_id, [ 1, 7 ] );
		$this->assertTrue( $status->for_account( $store->get( $acc_id ) )->is_success(), 'the warehouse is listed' );

		$this->cache_warehouses( $acc_id, [ 1, 2 ] );
		$result = $status->for_account( $store->get( $acc_id ) );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 1, substr_count( $result->get_message(), 'Склад #7 отключён в маркетплейсе' ), 'one line, not one per product' );

		$store->update_fields( $acc_id, [ 'warehouse_id' => 2 ] );
		$this->assertTrue( $status->for_account( $store->get( $acc_id ) )->is_success(), 'another warehouse picked: the reason is gone' );
	}

	/**
	 * A clock that can be moved forward.
	 *
	 * @return callable
	 */
	private function clock(): callable {
		return function (): int {
			return self::NOW + $this->clock_shift;
		};
	}

	public function test_a_stale_warehouse_list_does_not_count(): void {
		[ , , $acc_id ] = $this->stock_mode_handler( [], '1', true, [ 'warehouse_id' => 7 ], [ 1, 2 ] );
		$options = new Warehouse_Options( $this->cache, $this->clock() );

		$this->assertTrue( $options->is_unavailable( $acc_id, 7 ), 'a fresh list without the id counts' );

		Warehouse_Options::forget_current();
		$this->clock_shift = Warehouse_Options::FRESH_FOR + 1;
		$this->assertFalse( $options->is_unavailable( $acc_id, 7 ), 'the refresh has not succeeded for over the window: the list says nothing' );
	}

	public function test_the_add_account_form_starts_with_the_account_switched_on(): void {
		[ $handler ] = $this->stock_mode_handler( [] );

		$this->assertTrue( $handler->get_value( 'account_new_enabled' ), 'a new account works as soon as it is connected' );

		// Only the blank form starts on: a saved account that was switched off still reads off (and Save must not turn it on).
		[ $off_handler, , $off_id ] = $this->stock_mode_handler( [], '9', false );
		$this->assertFalse( $off_handler->get_value( "account_{$off_id}_enabled" ), 'an existing disabled account stays off' );
	}

	public function test_the_credential_fields_are_required_settings_for_a_saved_account_and_for_the_add_form(): void {
		[ $handler, , $acc_id ] = $this->stock_mode_handler( [] );

		$by_id = Field_Schema::from_handler( $handler );

		foreach ( [ (string) $acc_id, 'new' ] as $key ) {
			$this->assertTrue( $by_id[ "account_{$key}_merchant_uid" ]['required'], "the identifier of «{$key}» carries the required marker" );
			$this->assertTrue( $by_id[ "account_{$key}_access_token" ]['required'], "the secret of «{$key}» carries the required marker" );
			$this->assertFalse( $by_id[ "account_{$key}_name" ]['required'], 'the label is optional' );
			$this->assertFalse( $by_id[ "account_{$key}_description" ]['required'], 'the description is optional' );
		}

		// A saved secret stays masked: the screen counts it as filled through `is_set`, so the gate needs no retyped value.
		$this->assertTrue( $by_id[ "account_{$acc_id}_access_token" ]['is_set'] );
		$this->assertSame( '', $by_id[ "account_{$acc_id}_access_token" ]['value'] );
	}

	public function test_clearing_a_credential_of_a_saved_account_is_refused_while_untouched_fields_are_not_submitted(): void {
		[ $handler, , $acc_id ] = $this->stock_mode_handler( [] );

		$errors = $handler->validate_values(
			[
				"account_{$acc_id}_merchant_uid" => '',
				"account_{$acc_id}_access_token" => '',
			]
		);

		$this->assertArrayHasKey( "account_{$acc_id}_merchant_uid", $errors );
		$this->assertArrayHasKey( "account_{$acc_id}_access_token", $errors );

		// A save posts only the edited fields, so editing another field never trips the required credentials.
		$this->assertSame( [], $handler->validate_values( [ "account_{$acc_id}_name" => 'Renamed' ] ) );

		// A half-filled add form keeps its own message for the missing credential (the block rule still runs).
		$half = $handler->validate_values( [ 'account_new_merchant_uid' => '222' ] );
		$this->assertSame( 'Заполните поле «Токен доступа» для нового аккаунта.', $half['account_new_access_token'] ?? null );
	}

	public function test_the_price_example_closes_the_price_card_and_no_longer_heads_the_tab(): void {
		Functions\when( 'wc_price' )->alias(
			static function ( $amount, array $args = [] ): string {
				return '<span class="amount">' . number_format( (float) $amount, (int) ( $args['decimals'] ?? 2 ), ',', ' ' ) . '&nbsp;<span>₽</span></span>';
			}
		);
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		$fields     = new Credential_Fields( 'merchant_uid', [ 'access_token' ] );
		$descriptor = new Fake_Settings_Descriptor( $fields, 'Acme', [ 'merchant_uid' => new Credential_Field_Spec( 'UID', '' ), 'access_token' => new Credential_Field_Spec( 'Токен', '' ) ] );
		$adapter    = new Fake_Marketplace_Adapter(
			null,
			[
				'id'                  => Sqlite_Tables::MARKETPLACE_ID,
				'capabilities'        => [],
				'credential_fields'   => $fields,
				'settings_descriptor' => $descriptor,
			]
		);
		$store      = Wpdb_Account_Store::for_adapter(
			$this->db,
			$this->schema->table_name( 'accounts', Sqlite_Tables::PREFIX ),
			new Secret_Cipher( 'salt-1' ),
			$adapter,
			static function (): int {
				return self::NOW;
			}
		);
		$store->save( new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'Shop', true, [ 'merchant_uid' => '1', 'access_token' => 't' ] ) );

		$accounts = new Accounts_Sections( $store, $adapter, new Account_Status( $store, $adapter ), new Warehouse_Options( $this->cache, $this->clock() ), 'fake_mp' );
		$params   = array_values(
			array_filter(
				$accounts->sections(),
				static function ( $section ): bool {
					return 0 === strpos( $section->get_id(), 'account_params_' );
				}
			)
		)[0];
		$prices   = array_values(
			array_filter(
				$params->get_groups(),
				static function ( $group ): bool {
					return 0 === strpos( $group->get_id(), 'prices_' );
				}
			)
		);

		$this->assertNotSame( [], $prices, 'the fake adapter prices, so the card exists' );
		$this->assertStringContainsString( "Например: на сайте товар за 1 000\u{00A0}₽ (было 1 200\u{00A0}₽) → на Acme цена 1 000\u{00A0}₽, старая цена 1 200\u{00A0}₽.", $prices[0]->get_footnote(), 'the example is the card\'s footnote, in the shop\'s own price format and with the marketplace\'s name' );
		$this->assertStringNotContainsString( 'Например:', $params->get_description(), 'and no longer the head of the whole tab' );
	}

	public function test_the_price_direction_options_are_short_and_the_explanations_sit_in_the_tooltip(): void {
		[ $handler, , $acc_id ] = $this->stock_mode_handler( [] );

		$schema  = Field_Schema::from_handler( $handler );
		$options = $schema[ "account_{$acc_id}_price_direction" ]['options'] ?? [];

		$this->assertNotSame( [], $options );
		$this->assertContains( 'С сайта на Acme', array_column( $options, 'label' ), 'short enough not to be cut off in the select' );
		$this->assertContains( 'Не синхронизировать цены', array_column( $options, 'label' ) );

		foreach ( array_column( $options, 'label' ) as $label ) {
			$this->assertStringNotContainsString( 'считаются от цен сайта', $label );
		}

		$this->assertStringContainsString( 'цены на Acme считаются от цен сайта', $schema[ "account_{$acc_id}_price_direction" ]['tooltip'] ?? '' );
	}

	/**
	 * Builds a handler over one saved account whose adapter declares only the given capabilities.
	 *
	 * @param string[] $capabilities Capabilities the fake adapter declares.
	 * @param string   $uid          Merchant identifier (unique per account in one test).
	 * @param bool     $enabled      Whether the saved account is switched on.
	 * @param array<string,mixed> $columns Account columns to set before the handler reads the account (e.g. `warehouse_id`).
	 * @param int[]|null $available Ids of the cached available warehouses, null for no cache yet.
	 * @return array{0:Settings_Handler,1:Wpdb_Account_Store,2:int}
	 */
	private function stock_mode_handler( array $capabilities, string $uid = '1', bool $enabled = true, array $columns = [], ?array $available = null ): array {
		$fields     = new Credential_Fields( 'merchant_uid', [ 'access_token' ] );
		$descriptor = new Fake_Settings_Descriptor(
			$fields,
			'Acme',
			[
				'merchant_uid' => new Credential_Field_Spec( 'UID продавца', 'Код продавца' ),
				'access_token' => new Credential_Field_Spec( 'Токен доступа', 'Основной токен' ),
			]
		);
		$adapter    = new Fake_Marketplace_Adapter(
			null,
			[
				'id'                  => Sqlite_Tables::MARKETPLACE_ID,
				'capabilities'        => $capabilities,
				'credential_fields'   => $fields,
				'settings_descriptor' => $descriptor,
			]
		);
		$store      = Wpdb_Account_Store::for_adapter(
			$this->db,
			$this->schema->table_name( 'accounts', Sqlite_Tables::PREFIX ),
			new Secret_Cipher( 'salt-1' ),
			$adapter,
			static function (): int {
				return self::NOW;
			}
		);
		$acc_id     = $store->save(
			new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'Shop', $enabled, [ 'merchant_uid' => $uid, 'access_token' => 'token-1' ] )
		);

		if ( [] !== $columns ) {
			$store->update_fields( $acc_id, $columns );
		}

		if ( null !== $available ) {
			$this->cache_warehouses( $acc_id, $available );
		}

		return [ $this->build_handler( $adapter, $store ), $store, $acc_id ];
	}

	/**
	 * Lists the values the rendered `stock_mode` select offers (the field schema is what the admin form renders).
	 *
	 * @param Settings_Handler $handler Handler.
	 * @param int              $acc_id  Account id.
	 * @return string[]
	 */
	private function stock_mode_values( Settings_Handler $handler, int $acc_id ): array {
		$schema = Field_Schema::from_handler( $handler );

		return array_column( $schema[ "account_{$acc_id}_stock_mode" ]['options'] ?? [], 'value' );
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
		$wh       = new Warehouse_Options( $this->cache, $this->clock() );
		$accounts = new Accounts_Sections( $store, $adapter, $status, $wh, 'fake_mp', $on_connected );

		return new Settings_Handler( 'fake_mp', [ $accounts ], 'Fake Market' );
	}
}
