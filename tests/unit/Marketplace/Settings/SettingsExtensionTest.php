<?php
/**
 * Unit tests for the «Общие» side of an adapter's {@see Settings_Extension}.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Settings;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Account\Credential_Fields;
use Woodev\Framework\Marketplace\Account\Secret_Cipher;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Settings\Credential_Field_Spec;
use Woodev\Framework\Marketplace\Settings\Defaults_Section;
use Woodev\Framework\Marketplace\Settings\Effective_Settings;
use Woodev\Framework\Marketplace\Settings\Extension_Field;
use Woodev\Framework\Marketplace\Settings\Settings_Extension;
use Woodev\Framework\Marketplace\Settings\Settings_Handler;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Settings_Descriptor;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Settings_Extension;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace_Adapter;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;
use Woodev\Tests\Unit\TestCase;

/**
 * An adapter's own fields on «Общие»: they register, draw in a card of their own, validate, save in the defaults option, survive a
 * reload and are read through {@see Effective_Settings}. An adapter without the interface changes nothing.
 */
final class SettingsExtensionTest extends TestCase {
	private const PLUGIN = 'fake_mp';
	private const OPTION = 'woodev_' . Sqlite_Tables::MARKETPLACE_ID . '_account_defaults';

	/** @var Sqlite_Wpdb */
	private $db;

	/** @var Schema */
	private $schema;

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
	}

	public function test_the_extension_card_follows_the_built_in_ones_and_the_tab_lists_its_ids(): void {
		$section  = $this->section( new Fake_Settings_Extension() );
		$sections = $section->sections();
		$groups   = $sections[0]->get_groups();

		$this->assertSame( [ 'defaults_prices', 'defaults_exchange', 'defaults_ext_buy' ], array_map( static function ( $g ) { return $g->get_id(); }, $groups ) );
		$this->assertSame( [ 'defaults_ext_buy_button' ], $groups[2]->get_setting_ids() );
		$this->assertContains( 'defaults_ext_buy_button', $sections[0]->get_setting_ids() );
		$this->assertTrue( $section->handles( 'defaults_ext_buy_button' ) );
		$this->assertFalse( $section->handles( 'defaults_ext_nope' ) );
	}

	public function test_without_an_extension_the_tab_is_exactly_what_it_was(): void {
		$plain    = $this->section( null );
		$with_ids = $this->section( new Fake_Settings_Extension() )->sections()[0]->get_setting_ids();

		$this->assertNotContains( 'defaults_ext_buy_button', $plain->sections()[0]->get_setting_ids() );
		$this->assertSame( array_merge( $plain->sections()[0]->get_setting_ids(), [ 'defaults_ext_buy_button' ] ), $with_ids );
		$this->assertFalse( $plain->handles( 'defaults_ext_buy_button' ) );
		$this->assertFalse( ( new Fake_Marketplace_Adapter( null, [ 'id' => Sqlite_Tables::MARKETPLACE_ID ] ) ) instanceof Settings_Extension, 'the fake adapter does not opt in, so the module passes null' );
	}

	public function test_an_adapter_with_nothing_else_to_default_still_gets_the_tab(): void {
		$adapter = new Fake_Marketplace_Adapter(
			null,
			[
				'id'                  => Sqlite_Tables::MARKETPLACE_ID,
				'settings_descriptor' => $this->descriptor(),
			]
		);

		$this->assertTrue( ( new Defaults_Section( $adapter, $this->store( $adapter ), self::PLUGIN, new Fake_Settings_Extension() ) )->has_fields() );
	}

	public function test_the_field_is_registered_with_its_default_and_options(): void {
		$handler = $this->handler( new Fake_Settings_Extension() );
		$setting = $handler->get_setting( 'defaults_ext_buy_button' );

		$this->assertNotNull( $setting );
		$this->assertSame( 'none', $setting->get_value() );
		$this->assertSame( [ 'none', 'always', 'stock' ], array_keys( $setting->get_options() ) );
		$this->assertSame( 'none', Effective_Settings::for_adapter( Sqlite_Tables::MARKETPLACE_ID )->extra( 'buy_button', 'none' ) );
	}

	public function test_an_unknown_option_is_refused(): void {
		$handler = $this->handler( new Fake_Settings_Extension() );

		$this->assertArrayHasKey( 'defaults_ext_buy_button', $handler->validate_values( [ 'defaults_ext_buy_button' => 'sometimes' ] ) );
		$this->assertSame( [], $handler->validate_values( [ 'defaults_ext_buy_button' => 'stock' ] ) );
	}

	public function test_a_saved_value_survives_a_reload_and_is_readable_through_the_resolver(): void {
		$handler = $this->handler( new Fake_Settings_Extension() );
		$handler->get_setting( 'defaults_ext_buy_button' )->set_value( 'stock' );
		$handler->save( 'defaults_ext_buy_button' );

		$reloaded = $this->handler( new Fake_Settings_Extension() ); // A fresh screen over the same option.

		$this->assertSame( 'stock', $reloaded->get_setting( 'defaults_ext_buy_button' )->get_value() );
		$this->assertSame( 'stock', Effective_Settings::for_adapter( Sqlite_Tables::MARKETPLACE_ID )->extra( 'buy_button' ) );
		$this->assertSame( [ 'buy_button' => 'stock' ], $this->options[ self::OPTION ][ Effective_Settings::EXTRA_KEY ] );
	}

	public function test_saving_an_extension_field_leaves_the_framework_defaults_alone(): void {
		$resolver = Effective_Settings::for_adapter( Sqlite_Tables::MARKETPLACE_ID );
		$before   = $resolver->defaults();
		$handler  = $this->handler( new Fake_Settings_Extension() );
		$handler->get_setting( 'defaults_ext_buy_button' )->set_value( 'always' );
		$handler->save( 'defaults_ext_buy_button' );

		$this->assertSame( $before, $resolver->defaults() );
		$this->assertArrayNotHasKey( 'buy_button', $resolver->defaults() );
		$this->assertArrayNotHasKey( Effective_Settings::SEEDED_KEY, $this->options[ self::OPTION ], 'a field of the adapter is not a default the first-run fill must respect' );
	}

	public function test_a_value_the_field_cannot_hold_is_not_stored(): void {
		$section = $this->section( new Fake_Settings_Extension() );
		$section->write( 'defaults_ext_buy_button', 'sometimes' );
		$section->write( 'defaults_ext_buy_button', [ 'always' ] );

		$this->assertArrayNotHasKey( self::OPTION, $this->options );
		$this->assertSame( 'none', $section->read( 'defaults_ext_buy_button' ) );
	}

	public function test_a_stored_value_the_adapter_no_longer_lists_reads_as_the_default(): void {
		$this->options[ self::OPTION ] = [ Effective_Settings::EXTRA_KEY => [ 'buy_button' => 'retired' ] ];

		$this->assertSame( 'none', $this->section( new Fake_Settings_Extension() )->read( 'defaults_ext_buy_button' ) );
	}

	public function test_a_toggle_defaults_reads_and_saves_as_a_bool(): void {
		$extension = new class() implements Settings_Extension {
			public function default_groups(): array {
				return [ new \Woodev\Framework\Marketplace\Settings\Extension_Group( 'g', 'G', [ Extension_Field::toggle( 'flag', 'Flag', true ) ] ) ];
			}

			public function product_groups(): array {
				return [];
			}
		};
		$section   = $this->section( $extension );

		$this->assertTrue( $section->read( 'defaults_ext_flag' ) );

		$section->write( 'defaults_ext_flag', '0' );

		$this->assertFalse( $section->read( 'defaults_ext_flag' ) );
		$this->assertFalse( Effective_Settings::for_adapter( Sqlite_Tables::MARKETPLACE_ID )->extra( 'flag', true ) );
	}

	public function test_a_field_id_is_a_plain_key(): void {
		$this->expectException( \InvalidArgumentException::class );

		Extension_Field::toggle( 'Not A Key', 'x' );
	}

	public function test_a_select_needs_options_and_a_default_among_them(): void {
		$this->expectException( \InvalidArgumentException::class );

		Extension_Field::select( 'a', 'A', [ 'x' => 'X' ], 'y' );
	}

	/**
	 * Builds the descriptor of the fake marketplace.
	 *
	 * @return Fake_Settings_Descriptor
	 */
	private function descriptor(): Fake_Settings_Descriptor {
		return new Fake_Settings_Descriptor(
			new Credential_Fields( 'merchant_uid', [ 'access_token' ] ),
			'Acme',
			[
				'merchant_uid' => new Credential_Field_Spec( 'UID продавца', 'Код продавца' ),
				'access_token' => new Credential_Field_Spec( 'Токен доступа', 'Основной токен' ),
			]
		);
	}

	/**
	 * Builds the adapter and its account store.
	 *
	 * @param Fake_Marketplace_Adapter $adapter Adapter.
	 * @return Wpdb_Account_Store
	 */
	private function store( Fake_Marketplace_Adapter $adapter ): Wpdb_Account_Store {
		return Wpdb_Account_Store::for_adapter( $this->db, $this->schema->table_name( 'accounts', Sqlite_Tables::PREFIX ), new Secret_Cipher( 'salt-1' ), $adapter );
	}

	/**
	 * Builds the «Общие» provider of an adapter with the price rule and the exchange switches.
	 *
	 * @param Settings_Extension|null $extension Extension.
	 * @return Defaults_Section
	 */
	private function section( ?Settings_Extension $extension ): Defaults_Section {
		$adapter = new Fake_Marketplace_Adapter(
			null,
			[
				'id'                  => Sqlite_Tables::MARKETPLACE_ID,
				'credential_fields'   => new Credential_Fields( 'merchant_uid', [ 'access_token' ] ),
				'settings_descriptor' => $this->descriptor(),
			]
		);

		return new Defaults_Section( $adapter, $this->store( $adapter ), self::PLUGIN, $extension );
	}

	/**
	 * Builds the settings handler over the «Общие» provider.
	 *
	 * @param Settings_Extension|null $extension Extension.
	 * @return Settings_Handler
	 */
	private function handler( ?Settings_Extension $extension ): Settings_Handler {
		return new Settings_Handler( self::PLUGIN, [ $this->section( $extension ) ], 'Fake Market' );
	}
}
