<?php
/**
 * Unit tests for Notifications_Section, Ip_List_Validator and Advanced_Section.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Settings;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Credential_Fields;
use Woodev\Framework\Marketplace\Account\Secret_Cipher;
use Woodev\Framework\Marketplace\Capabilities\Capability;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Intake\Last_Notification_Reader;
use Woodev\Framework\Marketplace\Intake\Notification_Account;
use Woodev\Framework\Marketplace\Intake\Notification_Account_Reader;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Settings\Advanced_Section;
use Woodev\Framework\Marketplace\Settings\Ip_List_Validator;
use Woodev\Framework\Marketplace\Settings\Notifications_Section;
use Woodev\Framework\Marketplace\Settings\Push_Admin;
use Woodev\Framework\Marketplace\Settings\Settings_Handler;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Settings_Descriptor;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace_Adapter;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;
use Woodev\Tests\Unit\TestCase;

final class NotificationsAndAdvancedSectionsTest extends TestCase {
	private const NOW = 1794139200;

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
		Functions\when( 'rest_url' )->alias(
			static function ( string $path = '' ): string {
				return 'https://shop.example/wp-json/' . ltrim( $path, '/' );
			}
		);
		Functions\when( 'human_time_diff' )->justReturn( '2 мин' );
		Functions\when( 'wc_string_to_bool' )->alias(
			static function ( string $val ): bool {
				return 'yes' === $val || '1' === $val || 'true' === $val;
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

	public function test_notifications_section_is_completely_omitted_when_adapter_lacks_push_capability(): void {
		$fields  = new Credential_Fields( 'cabinet_id', [ 'api_token' ] );
		$adapter = new Fake_Marketplace_Adapter(
			null,
			[
				'id'                => Sqlite_Tables::MARKETPLACE_ID,
				'capabilities'      => [],
				'credential_fields' => $fields,
			]
		);
		$notif   = new Notifications_Section(
			$adapter,
			new Notification_Account_Reader( $this->db, $this->schema->table_name( 'accounts', Sqlite_Tables::PREFIX ) ),
			new Last_Notification_Reader( $this->db, $this->schema->table_name( 'events', Sqlite_Tables::PREFIX ) ),
			new License_Gate(
				static function (): bool {
					return true;
				}
			),
			'fake_mp'
		);

		$handler = new Settings_Handler( 'fake_mp', [ $notif ] );

		$this->assertSame( [], $notif->sections() );
		$this->assertNull( $handler->get_setting( Notifications_Section::ALLOWLIST_ID ) );
	}

	public function test_notifications_section_masks_token_runs_four_actions_gates_on_license_and_validates_cidr(): void {
		$fields = new Credential_Fields( 'cabinet_id', [ 'api_token' ] );
		$store  = new Wpdb_Account_Store(
			$this->db,
			$this->schema->table_name( 'accounts', Sqlite_Tables::PREFIX ),
			new Secret_Cipher( 'salt' ),
			Sqlite_Tables::MARKETPLACE_ID,
			$fields
		);
		$acc_id = $store->save(
			new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'Push Shop', true, [ 'cabinet_id' => 'CAB-88', 'api_token' => 'sec-88' ] )
		);

		$push_admin = new class() implements Push_Admin {
			public function registration_of( string $url ): ?array {
				return [
					'enabled' => true,
					'status'  => 'GREEN',
					'url_seen' => '' !== $url,
				];
			}

			public function register( Notification_Account $account, string $url ): string {
				return Push_Admin::REGISTERED;
			}

			public function update_types( Notification_Account $account, string $url ): string {
				return Push_Admin::UPDATED;
			}

			public function enable( Notification_Account $account, string $url ): bool {
				return true;
			}

			public function check( Notification_Account $account, string $url ): array {
				return [
					'active' => true,
					'errors' => [],
				];
			}

			public function wanted_descriptions( Notification_Account $account ): array {
				return [ 'Новые заказы', 'Остатки' ];
			}

			public function describe_state( array $state ): string {
				return 'активно (' . (string) ( $state['status'] ?? '' ) . ')';
			}
		};

		$descriptor = new Fake_Settings_Descriptor(
			$fields,
			'PushMarket',
			[],
			static function () use ( $push_admin ): Push_Admin {
				return $push_admin;
			},
			"198.51.100.0/24\n2001:db8::/32"
		);

		$adapter = new Fake_Marketplace_Adapter(
			null,
			[
				'id'                  => Sqlite_Tables::MARKETPLACE_ID,
				'capabilities'        => [ Capability::PUSH_NOTIFICATIONS ],
				'credential_fields'   => $fields,
				'settings_descriptor' => $descriptor,
			]
		);

		$license_valid = true;
		$notif_reader  = new Notification_Account_Reader( $this->db, $this->schema->table_name( 'accounts', Sqlite_Tables::PREFIX ) );
		$raw_token     = $notif_reader->find( $acc_id )->token();

		$notif = new Notifications_Section(
			$adapter,
			$notif_reader,
			new Last_Notification_Reader( $this->db, $this->schema->table_name( 'events', Sqlite_Tables::PREFIX ) ),
			new License_Gate(
				static function () use ( &$license_valid ): bool {
					return $license_valid;
				}
			),
			'fake_mp',
			static function (): int {
				return self::NOW;
			}
		);

		$handler  = new Settings_Handler( 'fake_mp', [ $notif ] );
		$sections = $notif->sections();

		$this->assertCount( 2, $sections );
		$desc = $sections[0]->get_description();
		$this->assertStringNotContainsString( $raw_token, $desc );
		$this->assertStringContainsString( '[скрыто]', $desc );
		$this->assertStringContainsString( 'Новые заказы, Остатки', $desc );

		// Execute all 4 tools when licence is valid; state is refreshed in DB.
		$actions = $sections[0]->get_actions();
		$this->assertCount( 4, $actions );
		foreach ( $actions as $tool ) {
			$res = call_user_func( $tool->get_callback(), [] );
			$this->assertTrue( $res->is_success() );
		}
		$this->assertSame( 'GREEN', $notif_reader->find( $acc_id )->state()['status'] ?? null );

		// Licence invalid blocks remote push actions.
		$license_valid = false;
		$blocked       = call_user_func( $actions[0]->get_callback(), [] );
		$this->assertFalse( $blocked->is_success() );
		$this->assertStringContainsString( 'Лицензия не действует', $blocked->get_message() );

		// IP/CIDR validation accepts IPv4, IPv6, comments, and rejects malformed CIDRs.
		$valid_errs = $handler->validate_values(
			[
				Notifications_Section::ALLOWLIST_ID       => "198.51.100.0/24 # edge\n2001:db8::/32, 10.0.0.5",
				Notifications_Section::TRUSTED_PROXIES_ID => '127.0.0.1',
			]
		);
		$this->assertSame( [], $valid_errs );
		$this->assertTrue( Ip_List_Validator::contains( '198.51.100.42', '198.51.100.0/24' ) );
		$this->assertFalse( Ip_List_Validator::contains( '198.51.101.42', '198.51.100.0/24' ) );

		$bad_errs = $handler->validate_values(
			[
				Notifications_Section::ALLOWLIST_ID => '999.1.1.1/24',
			]
		);
		$this->assertArrayHasKey( Notifications_Section::ALLOWLIST_ID, $bad_errs );
	}

	public function test_advanced_section_persists_logging_and_delete_data_toggles(): void {
		$advanced = new Advanced_Section();
		$handler  = new Settings_Handler( 'fake_mp', [ $advanced ] );

		$this->assertCount( 1, $advanced->sections() );
		$this->assertFalse( $handler->get_value( Advanced_Section::LOGGING_ID ) );
		$this->assertFalse( $handler->get_value( Advanced_Section::DELETE_DATA_ID ) );

		$handler->get_setting( Advanced_Section::LOGGING_ID )->set_value( true );
		$handler->get_setting( Advanced_Section::DELETE_DATA_ID )->set_value( true );
		$handler->save();

		$this->assertSame( 'yes', $this->options['woodev_fake_mp_advanced_logging'] ?? null );
		$this->assertSame( 'yes', $this->options['woodev_fake_mp_advanced_delete_data_on_uninstall'] ?? null );
	}
}
