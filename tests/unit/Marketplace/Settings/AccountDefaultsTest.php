<?php
/**
 * Unit tests for the «Общие» defaults: the resolver, the first-run fill, the tab, and an account's «как в Общих / свои» switch.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Settings;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Credential_Fields;
use Woodev\Framework\Marketplace\Account\Secret_Cipher;
use Woodev\Framework\Marketplace\Capabilities\Capability;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Catalog_Cache;
use Woodev\Framework\Marketplace\Flows\Export_Price_Policy;
use Woodev\Framework\Marketplace\Intake\Notification_Account_Reader;
use Woodev\Framework\Marketplace\Jobs\Posting_Jobs;
use Woodev\Framework\Marketplace\Price\Price_Rule;
use Woodev\Framework\Marketplace\Settings\Account_Status;
use Woodev\Framework\Marketplace\Settings\Accounts_Sections;
use Woodev\Framework\Marketplace\Settings\Credential_Field_Spec;
use Woodev\Framework\Marketplace\Settings\Defaults_Migration;
use Woodev\Framework\Marketplace\Settings\Defaults_Section;
use Woodev\Framework\Marketplace\Settings\Effective_Settings;
use Woodev\Framework\Marketplace\Settings\Settings_Handler;
use Woodev\Framework\Marketplace\Settings\Settings_Tab;
use Woodev\Framework\Marketplace\Settings\Warehouse_Options;
use Woodev\Framework\Settings\Field_Schema;
use Woodev\Framework\Settings\Settings_Section;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Settings_Descriptor;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace_Adapter;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;
use Woodev\Tests\Unit\TestCase;

final class AccountDefaultsTest extends TestCase {
	private const NOW    = 1794139200;
	private const PLUGIN = 'fake_mp';
	private const HOOK   = 'woodev_fake_mp_account_price_rules_changed';

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

	// Resolver ------------------------------------------------------------------------------------------------------------

	public function test_an_account_without_the_flag_keeps_its_own_values(): void {
		$account = new Account(
			1,
			Sqlite_Tables::MARKETPLACE_ID,
			'Old',
			true,
			[],
			null,
			[
				'price_rule'     => [ 'markup_kind' => 'percent', 'markup' => '15' ],
				'orders_enabled' => false,
				'import_enabled' => true,
			]
		);
		$this->options[ Effective_Settings::option_name( Sqlite_Tables::MARKETPLACE_ID ) ] = [ 'markup' => '99', 'orders_enabled' => true ];

		$resolver = Effective_Settings::for_adapter( Sqlite_Tables::MARKETPLACE_ID );

		$this->assertSame( [ 'markup_kind' => 'percent', 'markup' => '15' ], $resolver->price_rule( $account ), 'a legacy account is «свои»: the defaults do not reach it' );
		$this->assertFalse( $resolver->orders_enabled( $account ) );
		$this->assertTrue( $resolver->import_enabled( $account ) );
	}

	public function test_a_group_that_follows_the_defaults_reads_them_and_the_other_group_stays_own(): void {
		$account = new Account(
			1,
			Sqlite_Tables::MARKETPLACE_ID,
			'New',
			true,
			[],
			null,
			[
				'price_rule'                        => [ 'markup' => '15' ],
				'orders_enabled'                    => false,
				'import_enabled'                    => false,
				Effective_Settings::OVERRIDES_KEY => [
					Effective_Settings::GROUP_PRICES   => false,
					Effective_Settings::GROUP_EXCHANGE => true,
				],
			]
		);
		$this->options[ Effective_Settings::option_name( Sqlite_Tables::MARKETPLACE_ID ) ] = [
			'markup_kind'    => 'fixed',
			'markup'         => '150',
			'orders_enabled' => true,
		];

		$resolver = Effective_Settings::for_adapter( Sqlite_Tables::MARKETPLACE_ID );
		$rule     = $resolver->price_rule( $account );

		$this->assertSame( 'fixed', $rule['markup_kind'] );
		$this->assertSame( '150', $rule['markup'] );
		$this->assertSame( 'active', $rule['base'], 'a key the merchant never saved comes from the built-in defaults' );
		$this->assertFalse( $resolver->orders_enabled( $account ), 'the exchange group is «свои»: its own switch wins over the default' );
	}

	public function test_exchange_switches_of_a_table_row_follow_the_same_rule(): void {
		$this->options[ Effective_Settings::option_name( Sqlite_Tables::MARKETPLACE_ID ) ] = [ 'orders_enabled' => false, 'import_enabled' => true ];
		$resolver = Effective_Settings::for_adapter( Sqlite_Tables::MARKETPLACE_ID );

		$own       = [ 'orders_enabled' => 1, 'import_enabled' => 0, 'settings' => '{}' ];
		$following = [ 'orders_enabled' => 1, 'import_enabled' => 0, 'settings' => '{"overrides":{"exchange":false}}' ];

		$this->assertSame( [ 'orders_enabled' => true, 'import_enabled' => false ], $resolver->exchange_of_row( $own ) );
		$this->assertSame( [ 'orders_enabled' => false, 'import_enabled' => true ], $resolver->exchange_of_row( $following ) );
	}

	// First-run fill ------------------------------------------------------------------------------------------------------

	public function test_the_defaults_are_filled_from_the_first_account_once_and_no_account_is_touched(): void {
		[ , $store ] = $this->adapter_and_store();
		$first       = $store->save( new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'A', true, [ 'merchant_uid' => '1', 'access_token' => 't1' ], null, [ 'price_rule' => [ 'markup_kind' => 'percent', 'markup' => '12', 'min_percent' => '80' ], 'orders_enabled' => false, 'import_enabled' => true ] ) );
		$store->save( new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'B', true, [ 'merchant_uid' => '2', 'access_token' => 't2' ], null, [ 'price_rule' => [ 'markup' => '40' ] ] ) );

		$migration = new Defaults_Migration( $store, Sqlite_Tables::MARKETPLACE_ID );

		$this->assertTrue( $migration->run() );

		$saved = $this->options[ Effective_Settings::option_name( Sqlite_Tables::MARKETPLACE_ID ) ];
		$this->assertSame( 'percent', $saved['markup_kind'] );
		$this->assertSame( '12', $saved['markup'] );
		$this->assertSame( '80', $saved['min_percent'] );
		$this->assertFalse( $saved['orders_enabled'] );
		$this->assertTrue( $saved['import_enabled'] );
		$this->assertTrue( Effective_Settings::for_adapter( Sqlite_Tables::MARKETPLACE_ID )->is_seeded() );

		$this->options[ Effective_Settings::option_name( Sqlite_Tables::MARKETPLACE_ID ) ]['markup'] = '77';
		$this->assertFalse( $migration->run(), 'the second run does nothing' );
		$this->assertSame( '77', $this->options[ Effective_Settings::option_name( Sqlite_Tables::MARKETPLACE_ID ) ]['markup'] );

		$this->assertArrayNotHasKey( Effective_Settings::OVERRIDES_KEY, $store->get( $first )->settings(), 'an existing account is not written: no flag already means «свои»' );
		$this->assertTrue( Effective_Settings::uses_own( $store->get( $first )->settings(), Effective_Settings::GROUP_PRICES ) );
	}

	public function test_a_shop_without_accounts_is_not_marked_and_keeps_the_built_in_defaults(): void {
		[ , $store ] = $this->adapter_and_store();

		$this->assertFalse( ( new Defaults_Migration( $store, Sqlite_Tables::MARKETPLACE_ID ) )->run() );

		$resolver = Effective_Settings::for_adapter( Sqlite_Tables::MARKETPLACE_ID );
		$defaults = $resolver->defaults();
		$this->assertFalse( $resolver->is_seeded(), 'the plugin\'s own migration may still create the accounts' );
		$this->assertArrayNotHasKey( Effective_Settings::option_name( Sqlite_Tables::MARKETPLACE_ID ), $this->options );
		$this->assertSame( '0', $defaults['markup'] );
		$this->assertTrue( $defaults['orders_enabled'] );
		$this->assertFalse( $defaults['import_enabled'] );
	}

	public function test_accounts_that_appear_after_an_empty_run_still_seed_the_defaults(): void {
		[ , $store ] = $this->adapter_and_store();
		$migration   = new Defaults_Migration( $store, Sqlite_Tables::MARKETPLACE_ID );

		$this->assertFalse( $migration->run(), 'the handler was built before the v1 accounts were migrated' );

		$store->save( new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'A', true, [ 'merchant_uid' => '1', 'access_token' => 't1' ], null, [ 'price_rule' => [ 'markup_kind' => 'percent', 'markup' => '12' ] ] ) );

		$this->assertTrue( $migration->run() );
		$this->assertSame( '12', Effective_Settings::for_adapter( Sqlite_Tables::MARKETPLACE_ID )->defaults()['markup'] );
	}

	public function test_a_default_the_merchant_saved_first_is_never_overwritten_by_a_later_fill(): void {
		[ $adapter, $store ] = $this->adapter_and_store();
		$section             = new Defaults_Section( $adapter, $store, self::PLUGIN );

		$section->write( 'defaults_price_markup', '30' );

		$this->assertTrue( Effective_Settings::for_adapter( Sqlite_Tables::MARKETPLACE_ID )->is_seeded() );

		$store->save( new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'A', true, [ 'merchant_uid' => '1', 'access_token' => 't1' ], null, [ 'price_rule' => [ 'markup' => '12' ] ] ) );

		$this->assertFalse( ( new Defaults_Migration( $store, Sqlite_Tables::MARKETPLACE_ID ) )->run() );
		$this->assertSame( '30', Effective_Settings::for_adapter( Sqlite_Tables::MARKETPLACE_ID )->defaults()['markup'] );
	}

	// «Общие» tab ---------------------------------------------------------------------------------------------------------

	public function test_the_defaults_tab_reads_writes_and_validates(): void {
		[ $adapter, $store ] = $this->adapter_and_store();
		$section             = new Defaults_Section( $adapter, $store, self::PLUGIN );

		$this->assertTrue( $section->handles( 'defaults_price_markup' ) );
		$this->assertTrue( $section->handles( 'defaults_orders_enabled' ) );
		$this->assertFalse( $section->handles( 'defaults_nonsense' ) );
		$this->assertFalse( $section->handles( 'account_1_price_markup' ) );

		$section->write( 'defaults_price_markup_kind', 'percent' );
		$section->write( 'defaults_price_markup', ' 15 ' );
		$section->write( 'defaults_price_min_percent', '' );
		$section->write( 'defaults_orders_enabled', false );

		$this->assertSame( 'percent', $section->read( 'defaults_price_markup_kind' ) );
		$this->assertSame( '15', $section->read( 'defaults_price_markup' ) );
		$this->assertSame( '', $section->read( 'defaults_price_min_percent' ) );
		$this->assertFalse( $section->read( 'defaults_orders_enabled' ) );

		$this->assertArrayHasKey( 'defaults_price_min_percent', $section->validate( [ 'defaults_price_min_percent' => '150' ] ) );
		$this->assertArrayHasKey( 'defaults_price_markup', $section->validate( [ 'defaults_price_markup' => 'abc' ] ) );
		$this->assertSame( [], $section->validate( [ 'defaults_price_markup' => '10' ] ) );
		$this->assertSame( [], $section->validate( [ 'account_1_price_markup' => 'abc' ] ), 'an account field is not this tab\'s business' );
	}

	public function test_the_defaults_tab_has_a_card_for_prices_and_one_for_orders_and_cards(): void {
		[ $adapter, $store ] = $this->adapter_and_store( [ Capability::IMPORT_PRODUCTS ] );
		$section             = new Defaults_Section( $adapter, $store, self::PLUGIN );

		$sections = $section->sections();

		$this->assertCount( 1, $sections );
		$groups = $sections[0]->get_groups();
		$this->assertSame( [ 'defaults_prices', 'defaults_exchange' ], array_map( static function ( $g ) { return $g->get_id(); }, $groups ) );
		$this->assertSame(
			[ 'defaults_price_base', 'defaults_price_markup_kind', 'defaults_price_markup', 'defaults_price_rounding', 'defaults_price_old_price_rule', 'defaults_price_min_percent' ],
			$groups[0]->get_setting_ids()
		);
		$this->assertSame( [ 'defaults_orders_enabled', 'defaults_import_enabled' ], $groups[1]->get_setting_ids() );
	}

	public function test_saving_a_price_default_queues_only_the_accounts_that_follow_it(): void {
		[ $adapter, $store ] = $this->adapter_and_store();
		$following           = $store->save( new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'F', true, [ 'merchant_uid' => '1', 'access_token' => 't1' ], null, [ Effective_Settings::OVERRIDES_KEY => Effective_Settings::default_overrides() ] ) );
		$store->save( new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'O', true, [ 'merchant_uid' => '2', 'access_token' => 't2' ] ) );

		Actions\expectDone( self::HOOK )->once()->with( $following );

		( new Defaults_Section( $adapter, $store, self::PLUGIN ) )->write( 'defaults_price_markup', '25' );
	}

	public function test_an_unchanged_default_queues_nothing(): void {
		[ $adapter, $store ] = $this->adapter_and_store();
		$store->save( new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'F', true, [ 'merchant_uid' => '1', 'access_token' => 't1' ], null, [ Effective_Settings::OVERRIDES_KEY => Effective_Settings::default_overrides() ] ) );
		$section = new Defaults_Section( $adapter, $store, self::PLUGIN );
		$section->write( 'defaults_price_markup', '25' );

		Actions\expectDone( self::HOOK )->never();

		$section->write( 'defaults_price_markup', '25' );
	}

	// Account switch ------------------------------------------------------------------------------------------------------

	public function test_a_legacy_account_shows_own_and_the_fields_are_hidden_while_it_follows_the_defaults(): void {
		[ $adapter, $store ] = $this->adapter_and_store( [ Capability::IMPORT_PRODUCTS ] );
		$legacy              = $store->save( new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'Old', true, [ 'merchant_uid' => '1', 'access_token' => 't1' ] ) );
		$following           = $store->save( new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'New', true, [ 'merchant_uid' => '2', 'access_token' => 't2' ], null, [ Effective_Settings::OVERRIDES_KEY => Effective_Settings::default_overrides() ] ) );

		$schema = Field_Schema::from_handler( $this->handler( $adapter, $store ) );

		$this->assertSame( 'own', $schema[ "account_{$legacy}_override_prices" ]['value'] );
		$this->assertSame( 'own', $schema[ "account_{$legacy}_override_exchange" ]['value'] );
		$this->assertSame( 'defaults', $schema[ "account_{$following}_override_prices" ]['value'] );
		$this->assertSame( 'defaults', $schema[ "account_{$following}_override_exchange" ]['value'] );

		$condition = [ 'setting' => "account_{$following}_override_prices", 'operator' => '=', 'value' => 'own' ];
		$this->assertSame( $condition, $schema[ "account_{$following}_price_markup" ]['show_if'] );
		$this->assertSame( 'own', $schema[ "account_{$following}_orders_enabled" ]['show_if']['value'] ?? null );
		$this->assertArrayNotHasKey( 'show_if', $schema[ "account_{$following}_warehouse_id" ], 'the stock group is always the account\'s own' );
		$this->assertArrayNotHasKey( 'show_if', $schema[ "account_{$following}_price_direction" ], 'so is the price direction' );
	}

	public function test_switching_a_group_saves_the_flag_and_a_price_switch_queues_the_recompute(): void {
		[ $adapter, $store ] = $this->adapter_and_store();
		$id                  = $store->save( new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'Old', true, [ 'merchant_uid' => '1', 'access_token' => 't1' ], null, [ 'price_rule' => [ 'markup' => '15' ] ] ) );
		$handler             = $this->handler( $adapter, $store );

		Actions\expectDone( self::HOOK )->twice()->with( $id ); // Once for each switch of the price group; the exchange switch queues nothing.

		$handler->update_value( "account_{$id}_override_prices", 'defaults' );
		$handler->update_value( "account_{$id}_override_exchange", 'defaults' );

		$settings = $store->get( $id )->settings();
		$this->assertFalse( $settings[ Effective_Settings::OVERRIDES_KEY ][ Effective_Settings::GROUP_PRICES ] );
		$this->assertFalse( $settings[ Effective_Settings::OVERRIDES_KEY ][ Effective_Settings::GROUP_EXCHANGE ] );
		$this->assertSame( [ 'markup' => '15' ], $settings['price_rule'], 'the account\'s own values are kept for the way back' );

		$handler->update_value( "account_{$id}_override_prices", 'own' );
		$this->assertTrue( Effective_Settings::uses_own( $store->get( $id )->settings(), Effective_Settings::GROUP_PRICES ) );
	}

	public function test_an_account_that_follows_the_defaults_shows_them_in_its_fields(): void {
		[ $adapter, $store ] = $this->adapter_and_store( [ Capability::IMPORT_PRODUCTS ] );
		$this->options[ Effective_Settings::option_name( Sqlite_Tables::MARKETPLACE_ID ) ] = [ 'markup_kind' => 'percent', 'markup' => '10', 'rounding' => 'ends_99', 'orders_enabled' => false, 'import_enabled' => true ];
		$id = $store->save( new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'New', true, [ 'merchant_uid' => '2', 'access_token' => 't2' ], null, [ Effective_Settings::OVERRIDES_KEY => Effective_Settings::default_overrides() ] ) );

		$schema = Field_Schema::from_handler( $this->handler( $adapter, $store ) );

		$this->assertSame( '10', $schema[ "account_{$id}_price_markup" ]['value'] );
		$this->assertSame( 'ends_99', $schema[ "account_{$id}_price_rounding" ]['value'] );
		$this->assertFalse( $schema[ "account_{$id}_orders_enabled" ]['value'] );
		$this->assertTrue( $schema[ "account_{$id}_import_enabled" ]['value'] );
	}

	public function test_switching_a_group_to_own_starts_from_the_defaults_in_force(): void {
		[ $adapter, $store ] = $this->adapter_and_store( [ Capability::IMPORT_PRODUCTS ] );
		$this->options[ Effective_Settings::option_name( Sqlite_Tables::MARKETPLACE_ID ) ] = [ 'markup_kind' => 'percent', 'markup' => '10', 'rounding' => 'ends_99', 'min_percent' => '80', 'orders_enabled' => false, 'import_enabled' => true ];
		$id      = $store->save( new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'New', true, [ 'merchant_uid' => '2', 'access_token' => 't2' ], null, [ Effective_Settings::OVERRIDES_KEY => Effective_Settings::default_overrides() ] ) );
		$handler = $this->handler( $adapter, $store );

		// The screen sends the switches first, then the one field the merchant edited.
		$handler->update_value( "account_{$id}_override_prices", 'own' );
		$handler->update_value( "account_{$id}_override_exchange", 'own' );
		$handler->update_value( "account_{$id}_price_markup", '15' );

		$account = $store->get( $id );
		$rule    = $account->setting( 'price_rule' );

		$this->assertSame( '15', $rule['markup'] );
		$this->assertSame( 'percent', $rule['markup_kind'], 'the rest of the shared rule is kept' );
		$this->assertSame( 'ends_99', $rule['rounding'] );
		$this->assertSame( '80', $rule['min_percent'] );
		$this->assertFalse( $account->setting( 'orders_enabled' ) );
		$this->assertTrue( $account->setting( 'import_enabled' ) );
		$this->assertTrue( Effective_Settings::uses_own( $account->settings(), Effective_Settings::GROUP_PRICES ) );
	}

	public function test_an_account_that_already_uses_its_own_values_is_not_overwritten_by_the_defaults(): void {
		[ $adapter, $store ] = $this->adapter_and_store();
		$this->options[ Effective_Settings::option_name( Sqlite_Tables::MARKETPLACE_ID ) ] = [ 'markup' => '99' ];
		$id      = $store->save( new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'Old', true, [ 'merchant_uid' => '1', 'access_token' => 't1' ], null, [ 'price_rule' => [ 'markup' => '15' ] ] ) );
		$handler = $this->handler( $adapter, $store );

		$handler->update_value( "account_{$id}_override_prices", 'own' );

		$this->assertSame( '15', $store->get( $id )->setting( 'price_rule' )['markup'] );
	}

	public function test_an_account_created_on_the_screen_follows_the_defaults(): void {
		[ $adapter, $store ] = $this->adapter_and_store();
		$handler             = $this->handler( $adapter, $store );

		$handler->get_setting( 'account_new_name' )->set_value( 'Fresh' );
		$handler->get_setting( 'account_new_merchant_uid' )->set_value( '555' );
		$handler->get_setting( 'account_new_access_token' )->set_value( 'tok' );
		$handler->save();

		$created = $store->all( Sqlite_Tables::MARKETPLACE_ID, false )[0];

		$this->assertFalse( Effective_Settings::uses_own( $created->settings(), Effective_Settings::GROUP_PRICES ) );
		$this->assertFalse( Effective_Settings::uses_own( $created->settings(), Effective_Settings::GROUP_EXCHANGE ) );
	}

	public function test_the_price_rule_is_validated_for_every_account_that_submits_one(): void {
		[ $adapter, $store ] = $this->adapter_and_store();
		$id                  = $store->save( new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'A', true, [ 'merchant_uid' => '1', 'access_token' => 't1' ] ) );

		$errors = $this->handler( $adapter, $store )->validate_values( [ "account_{$id}_price_min_percent" => '500' ] );

		$this->assertArrayHasKey( "account_{$id}_price_min_percent", $errors );
	}

	public function test_a_price_switch_to_own_is_validated_against_the_defaults_it_copies(): void {
		[ $adapter, $store ] = $this->adapter_and_store();
		$this->options[ Effective_Settings::option_name( Sqlite_Tables::MARKETPLACE_ID ) ] = [ 'markup_kind' => Price_Rule::MARKUP_FIXED, 'markup' => '100' ];
		$id                  = $store->save( new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'A', true, [ 'merchant_uid' => '1', 'access_token' => 't1' ], null, [ Effective_Settings::OVERRIDES_KEY => Effective_Settings::default_overrides() ] ) );

		$errors = $this->handler( $adapter, $store )->validate_values(
			[
				"account_{$id}_override_prices" => 'own',
				"account_{$id}_price_markup"    => '-50',
			]
		);

		$this->assertArrayHasKey( "account_{$id}_price_markup", $errors, 'the markup is checked against the «Общие» kind that the switch copies' );
		$this->assertFalse( Effective_Settings::uses_own( $store->get( $id )->settings(), Effective_Settings::GROUP_PRICES ), 'validation saves nothing' );
	}

	// Runtime readers -----------------------------------------------------------------------------------------------------

	public function test_the_runtime_readers_see_the_effective_values(): void {
		[ , $store ] = $this->adapter_and_store();
		$this->options[ Effective_Settings::option_name( Sqlite_Tables::MARKETPLACE_ID ) ] = [
			'markup_kind'    => 'percent',
			'markup'         => '20',
			'orders_enabled' => false,
			'import_enabled' => true,
		];
		$following = $store->save( new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'F', true, [ 'merchant_uid' => '1', 'access_token' => 't1' ], null, [ 'stock_mode' => 'off', Effective_Settings::OVERRIDES_KEY => Effective_Settings::default_overrides() ] ) );
		$own       = $store->save( new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'O', true, [ 'merchant_uid' => '2', 'access_token' => 't2' ], null, [ 'stock_mode' => 'off', 'orders_enabled' => true, 'price_rule' => [ 'markup_kind' => 'fixed', 'markup' => '5' ] ] ) );

		$rule = Export_Price_Policy::rule( $store->get( $following ), new Profile( null, 'p' ) );
		$this->assertSame( 'percent', $rule->markup_kind() );
		$this->assertSame( '20', $rule->markup() );
		$this->assertSame( 'fixed', Export_Price_Policy::rule( $store->get( $own ), new Profile( null, 'p' ) )->markup_kind() );

		$this->assertFalse( Posting_Jobs::wants_postings( $store->get( $following ) ), 'the default turns order receiving off' );
		$this->assertTrue( Posting_Jobs::wants_postings( $store->get( $own ) ) );

		$reader = new Notification_Account_Reader( $this->db, Sqlite_Tables::table( 'accounts' ), Effective_Settings::for_adapter( Sqlite_Tables::MARKETPLACE_ID ) );
		$this->assertFalse( $reader->find( $following )->orders_enabled() );
		$this->assertTrue( $reader->find( $following )->import_enabled() );
		$this->assertTrue( $reader->find( $own )->orders_enabled() );

		$raw = new Notification_Account_Reader( $this->db, Sqlite_Tables::table( 'accounts' ) );
		$this->assertTrue( $raw->find( $following )->orders_enabled(), 'a reader built without the resolver keeps reading the columns' );
	}

	// Tabs ----------------------------------------------------------------------------------------------------------------

	public function test_the_handler_lists_one_provider_per_tab_and_only_the_first_keeps_the_id_and_the_legacy_address(): void {
		[ $adapter, $store ] = $this->adapter_and_store();
		$accounts            = $this->accounts_sections( $adapter, $store );
		$handler             = new Settings_Handler(
			self::PLUGIN,
			[
				$accounts,
				new Settings_Tab( 'connection', 'Подключение', [ $accounts, 'connection_sections' ] ),
				new Settings_Tab( 'parameters', 'Параметры', [ $accounts, 'parameter_sections' ] ),
			],
			'Fake Market',
			[ 'legacy_page' => 'page=old' ]
		);

		$providers = $handler->get_settings_providers();

		$this->assertSame( [ 'fake_mp', 'fake_mp-parameters' ], array_map( static function ( $p ) { return $p->get_id(); }, $providers ) );
		$this->assertSame( [ 'Подключение', 'Параметры' ], array_map( static function ( $p ) { return $p->get_label(); }, $providers ) );
		$this->assertSame( 'page=old', $providers[0]->get_legacy_page() );
		$this->assertNull( $providers[1]->get_legacy_page() );
	}

	public function test_a_handler_without_tabs_keeps_the_single_tab(): void {
		[ $adapter, $store ] = $this->adapter_and_store();

		$providers = $this->handler( $adapter, $store )->get_settings_providers();

		$this->assertCount( 1, $providers );
		$this->assertSame( 'fake_mp', $providers[0]->get_id() );
	}

	public function test_the_parameters_tab_asks_to_connect_an_account_when_there_is_none(): void {
		[ $adapter, $store ] = $this->adapter_and_store();

		$sections = $this->accounts_sections( $adapter, $store )->parameter_sections();

		$this->assertCount( 1, $sections );
		$this->assertSame( [], $sections[0]->get_setting_ids() );
		$this->assertStringContainsString( 'Подключите аккаунт на вкладке «Подключение»', $sections[0]->get_description() );
	}

	public function test_every_account_gets_its_own_stacked_parameters_section_under_its_name(): void {
		[ $adapter, $store ] = $this->adapter_and_store();
		$store->save( new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'Shop one', true, [ 'merchant_uid' => '1001', 'access_token' => 't1' ] ) );
		$store->save( new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'Shop two', true, [ 'merchant_uid' => '1002', 'access_token' => 't2' ] ) );

		$sections = $this->accounts_sections( $adapter, $store )->parameter_sections();

		$this->assertSame( [ 'Shop one', 'Shop two' ], array_map( static function ( $s ) { return $s->get_label(); }, $sections ) );
		$this->assertSame( [ 'UID продавца: 1001', 'UID продавца: 1002' ], array_map( static function ( $s ) { return $s->get_subtitle(); }, $sections ), 'the identifier sits beside the name' );
		$this->assertSame( [ true, true ], array_map( static function ( $s ) { return $s->is_stacked(); }, $sections ) );
		$this->assertSame( [], array_intersect( $sections[0]->get_setting_ids(), $sections[1]->get_setting_ids() ), 'two accounts, two separate blocks' );
	}

	public function test_the_notice_without_accounts_is_not_stacked(): void {
		[ $adapter, $store ] = $this->adapter_and_store();

		$this->assertFalse( $this->accounts_sections( $adapter, $store )->parameter_sections()[0]->is_stacked() );
	}

	public function test_a_stacked_tab_draws_every_section_stacked_and_keeps_a_subtitle_that_is_already_there(): void {
		$sections = [
			Settings_Section::create( 'switches', 'Switches', [] ),
			Settings_Section::create( 'account_1', 'Account', [] )->stacked( 'Client-Id: 7' ),
		];
		$builder  = static function () use ( $sections ): array {
			return $sections;
		};

		$plain   = ( new Settings_Tab( 'notifications', 'Уведомления', $builder ) )->sections();
		$stacked = ( new Settings_Tab( 'notifications', 'Уведомления', $builder, true ) )->sections();

		$this->assertFalse( $plain[0]->is_stacked() );
		$this->assertSame( [ true, true ], array_map( static function ( $s ) { return $s->is_stacked(); }, $stacked ) );
		$this->assertSame( 'Client-Id: 7', $stacked[1]->get_subtitle() );
	}

	public function test_connection_sections_are_cards_and_the_last_one_adds_an_account(): void {
		[ $adapter, $store ] = $this->adapter_and_store();
		$id                  = $store->save( new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'Main', true, [ 'merchant_uid' => '777', 'access_token' => 't1' ] ) );

		$sections = $this->accounts_sections( $adapter, $store )->connection_sections();

		$this->assertCount( 2, $sections );
		$card = $sections[0]->get_card();
		$this->assertSame( 'Main', $card['title'] );
		$this->assertSame( '777', $card['identifier'] );
		$this->assertSame( "account_{$id}_enabled", $card['enabled_field'] );
		$this->assertTrue( $card['enabled'] );
		$this->assertNotSame( '', $card['delete_hint'], 'the delete button is disabled with a hint: deletion is a later card' );
		$this->assertSame( [ 'new' => true ], $sections[1]->get_card() );
	}

	public function test_a_disabled_account_on_a_screen_without_deletion_says_so(): void {
		[ $adapter, $store ] = $this->adapter_and_store();
		$store->save( new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'Off', false, [ 'merchant_uid' => '778', 'access_token' => 't2' ] ) );

		$card = $this->accounts_sections( $adapter, $store )->connection_sections()[0]->get_card();

		$this->assertSame( 'Удаление аккаунта недоступно.', $card['delete_hint'] );
		$this->assertArrayNotHasKey( 'delete_url', $card );
	}

	// Support -------------------------------------------------------------------------------------------------------------

	/**
	 * @param string[] $capabilities Adapter capabilities.
	 * @return array{0:Fake_Marketplace_Adapter,1:Wpdb_Account_Store}
	 */
	private function adapter_and_store( array $capabilities = [] ): array {
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

		return [ $adapter, $store ];
	}

	private function accounts_sections( Fake_Marketplace_Adapter $adapter, Wpdb_Account_Store $store ): Accounts_Sections {
		$status = new Account_Status(
			$store,
			$adapter,
			static function (): int {
				return self::NOW;
			}
		);

		return new Accounts_Sections( $store, $adapter, $status, new Warehouse_Options( $this->cache ), self::PLUGIN );
	}

	private function handler( Fake_Marketplace_Adapter $adapter, Wpdb_Account_Store $store ): Settings_Handler {
		return new Settings_Handler( self::PLUGIN, [ $this->accounts_sections( $adapter, $store ) ], 'Fake Market' );
	}
}
