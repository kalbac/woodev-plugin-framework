<?php
/**
 * Concurrency, secret-rotation and unreadable-salt tests for Accounts_Sections and Settings_Handler.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Settings;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Credential_Fields;
use Woodev\Framework\Marketplace\Account\Secret_Cipher;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Catalog_Cache;
use Woodev\Framework\Marketplace\Settings\Account_Status;
use Woodev\Framework\Marketplace\Settings\Accounts_Sections;
use Woodev\Framework\Marketplace\Settings\Settings_Handler;
use Woodev\Framework\Marketplace\Settings\Warehouse_Options;
use Woodev\Framework\Settings\Field_Schema;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace_Adapter;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;
use Woodev\Tests\Unit\TestCase;

final class SettingsConcurrencyTest extends TestCase {
	private const NOW = 1794139200;

	/** @var Sqlite_Wpdb */
	private $db;

	/** @var Schema */
	private $schema;

	/** @var Wpdb_Catalog_Cache */
	private $cache;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, array $defaults = [] ): array {
				return array_merge( $defaults, (array) $args );
			}
		);
		Functions\when( 'sanitize_key' )->alias( 'strtolower' );
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'delete_option' )->justReturn( true );

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

	public function test_stale_tab_saving_name_and_price_rule_preserves_concurrently_rotated_secret_and_limits_snapshot(): void {
		$fields  = new Credential_Fields( 'cabinet_id', [ 'api_token' ] );
		$adapter = new Fake_Marketplace_Adapter(
			null,
			[
				'id'                => Sqlite_Tables::MARKETPLACE_ID,
				'capabilities'      => [],
				'credential_fields' => $fields,
			]
		);
		$store   = Wpdb_Account_Store::for_adapter(
			$this->db,
			$this->schema->table_name( 'accounts', Sqlite_Tables::PREFIX ),
			new Secret_Cipher( 'salt-initial' ),
			$adapter,
			static function (): int {
				return self::NOW;
			}
		);

		$acc_id = $store->save(
			new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'Original', true, [ 'cabinet_id' => '900', 'api_token' => 'old-secret-111' ] )
		);

		// Tab A loads BEFORE Tab B rotates the secret and BEFORE a job writes limits_snapshot.
		$tab_a = $this->make_handler( $adapter, $store );

		// Concurrent rotation + limits snapshot in DB!
		$store->replace_secrets( $acc_id, [ 'api_token' => 'rotated-secret-999' ] );
		$store->set_health_for_credentials(
			$acc_id,
			[ 'cabinet_id' => '900', 'api_token' => 'rotated-secret-999' ],
			new \Woodev\Framework\Marketplace\Account\Health_Result(
				\Woodev\Framework\Marketplace\Account\Health_Result::OK,
				'OK',
				null,
				[ 'limits' => [ 'Daily' => '5 / 100' ] ]
			)
		);

		// Tab A submits a blank masked secret ('') and updates price_markup + name.
		$tab_a->get_setting( "account_{$acc_id}_api_token" )->set_value( '' );
		$tab_a->get_setting( "account_{$acc_id}_name" )->set_value( 'Updated By Tab A' );
		$tab_a->get_setting( "account_{$acc_id}_price_markup_kind" )->set_value( 'fixed' );
		$tab_a->get_setting( "account_{$acc_id}_price_markup" )->set_value( '250' );
		$tab_a->save();

		$after = $store->get( $acc_id );
		$this->assertNotNull( $after );
		$this->assertSame( 'Updated By Tab A', $after->name() );
		$this->assertSame( 'rotated-secret-999', $after->credentials()['api_token'] );
		$this->assertSame( '250', $after->setting( 'price_rule' )['markup'] ?? null );
		$this->assertSame( [ 'Daily' => '5 / 100' ], $after->setting( 'limits_snapshot' )['limits'] ?? null );
	}

	public function test_salt_rotation_surfaces_unreadable_status_blocks_blank_test_and_recovers_on_new_secret(): void {
		$fields  = new Credential_Fields( 'cabinet_id', [ 'api_token' ] );
		$adapter = new Fake_Marketplace_Adapter(
			null,
			[
				'id'                => Sqlite_Tables::MARKETPLACE_ID,
				'capabilities'      => [],
				'credential_fields' => $fields,
			]
		);

		$old_store = Wpdb_Account_Store::for_adapter(
			$this->db,
			$this->schema->table_name( 'accounts', Sqlite_Tables::PREFIX ),
			new Secret_Cipher( 'old-wp-salt' ),
			$adapter
		);

		$acc_id = $old_store->save(
			new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'Salt Shop', true, [ 'cabinet_id' => '321', 'api_token' => 'encrypted-under-old-salt' ] )
		);

		// WordPress salts change: reopen store with new salt.
		$new_store = Wpdb_Account_Store::for_adapter(
			$this->db,
			$this->schema->table_name( 'accounts', Sqlite_Tables::PREFIX ),
			new Secret_Cipher( 'new-wp-salt' ),
			$adapter
		);

		$handler = $this->make_handler( $adapter, $new_store );
		$by_id   = Field_Schema::from_handler( $handler );

		// Field_Schema marks the secret as not set (`is_set === false`) so merchant knows to re-enter.
		$this->assertFalse( $by_id[ "account_{$acc_id}_api_token" ]['is_set'] );

		// Connection status reports unreadable error immediately.
		$status = $handler->get_connection_status( "account_connection_{$acc_id}" );
		$this->assertNotNull( $status );
		$this->assertFalse( $status->is_success() );
		$this->assertStringContainsString( 'расшифровать', $status->get_message() );

		// Pressing "Test connection" without entering a new secret fails with the unreadable message.
		$test_res = $handler->test_connection(
			"account_connection_{$acc_id}",
			[
				"account_{$acc_id}_cabinet_id" => '321',
				"account_{$acc_id}_api_token"  => '',
			]
		);
		$this->assertFalse( $test_res->is_success() );
		$this->assertStringContainsString( 'расшифровать', $test_res->get_message() );

		// Saving a fresh secret re-encrypts cleanly under the new salt.
		$handler->get_setting( "account_{$acc_id}_api_token" )->set_value( 'fresh-secret-under-new-salt' );
		$handler->save( "account_{$acc_id}_api_token" );

		$recovered = $new_store->get( $acc_id );
		$this->assertNotNull( $recovered );
		$this->assertFalse( $recovered->credentials()[ Wpdb_Account_Store::CREDENTIAL_UNREADABLE ] );
		$this->assertSame( 'fresh-secret-under-new-salt', $recovered->credentials()['api_token'] );
	}

	private function make_handler( Fake_Marketplace_Adapter $adapter, Wpdb_Account_Store $store ): Settings_Handler {
		$status   = new Account_Status( $store, $adapter );
		$wh       = new Warehouse_Options( $this->cache );
		$accounts = new Accounts_Sections( $store, $adapter, $status, $wh, 'fake_mp' );

		return new Settings_Handler( 'fake_mp', [ $accounts ], 'Fake Market' );
	}
}
