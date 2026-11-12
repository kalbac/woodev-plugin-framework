<?php
/**
 * Unit tests for Notifications_Section and Advanced_Section.
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
use Woodev\Framework\Marketplace\Data\Uninstaller;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Intake\Last_Notification_Reader;
use Woodev\Framework\Marketplace\Intake\Notification_Account;
use Woodev\Framework\Marketplace\Intake\Notification_Account_Reader;
use Woodev\Framework\Marketplace\Intake\Webhook_Type;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Settings\Advanced_Section;
use Woodev\Framework\Marketplace\Settings\Notifications_Section;
use Woodev\Framework\Marketplace\Settings\Push_Admin;
use Woodev\Framework\Marketplace\Settings\Settings_Handler;
use Woodev\Framework\Marketplace\Settings\Settings_Section_Provider;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Push_Protocol;
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
		$this->assertNull( $handler->get_setting( 'webhook_order_created' ) );
	}

	public function test_notifications_section_shows_the_full_url_and_runs_four_actions_gated_on_license(): void {
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

		$descriptor = new Fake_Settings_Descriptor( $fields, 'PushMarket' );

		$adapter = new Fake_Marketplace_Adapter(
			null,
			[
				'id'                  => Sqlite_Tables::MARKETPLACE_ID,
				'capabilities'        => [ Capability::PUSH_NOTIFICATIONS ],
				'credential_fields'   => $fields,
				'settings_descriptor' => $descriptor,
				'push_protocol'       => new Fake_Push_Protocol(),
				'push_admin'          => static function () use ( $push_admin ): Push_Admin {
					return $push_admin;
				},
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

		$this->assertCount( 2, $sections, 'the switches card and one account card' );
		$this->assertSame( 'notifications_webhooks', $sections[0]->get_id() );
		$desc = $sections[1]->get_description();
		$this->assertStringContainsString( '<code>https://shop.example/wp-json/woodev/v1/marketplace/' . Sqlite_Tables::MARKETPLACE_ID . '/notifications/' . $acc_id . '/' . $raw_token . '</code>', $desc, 'the merchant copies the full address into the cabinet' );
		$this->assertStringNotContainsString( '[скрыто]', $desc );
		$this->assertStringContainsString( 'вписывать его вручную не нужно', $sections[0]->get_description(), 'this adapter registers the address itself' );
		$this->assertStringContainsString( 'Новые заказы, Остатки', $desc );

		// Execute all 4 tools when licence is valid; state is refreshed in DB.
		$actions = $sections[1]->get_actions();
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
	}

	public function test_each_declared_notification_type_gets_a_switch_with_its_explanation(): void {
		$types   = [
			new Webhook_Type( 'new_order', 'Новый заказ', 'Сообщает сайту о новом заказе сразу.', 'Кабинет → Интеграции' ),
			new Webhook_Type( 'stocks', 'Остатки', 'Сообщает об изменении остатков.', '', false ),
		];
		$notif   = $this->push_section( new Fake_Push_Protocol( '203.0.113.0/24', $types ) );
		$handler = new Settings_Handler( 'fake_mp', [ $notif ] );

		$this->assertTrue( $handler->get_value( 'webhook_new_order' ), 'on until the merchant changes it' );
		$this->assertFalse( $handler->get_value( 'webhook_stocks' ), 'the type declares itself off by default' );
		$this->assertNull( $handler->get_setting( 'ip_allowlist' ), 'the sender addresses are no merchant setting' );
		$this->assertNull( $handler->get_setting( 'trusted_proxies' ), 'neither are the proxies' );

		$sections = $notif->sections();

		$this->assertCount( 1, $sections );
		$this->assertSame( [ 'webhook_new_order', 'webhook_stocks' ], $sections[0]->get_setting_ids() );
		$this->assertStringContainsString( 'Сообщает сайту о новом заказе сразу.', $sections[0]->get_description() );
		$this->assertStringContainsString( 'Где указать адрес: Кабинет → Интеграции.', $sections[0]->get_description() );
		$this->assertStringNotContainsString( 'Где указать адрес: .', $sections[0]->get_description(), 'no empty cabinet location' );

		$handler->get_setting( 'webhook_stocks' )->set_value( true );
		$handler->save();

		$this->assertSame( 'yes', $this->options['woodev_fake_mp_webhook_stocks'] ?? null, 'the row the intake reads' );
	}

	public function test_an_adapter_that_does_not_register_remotely_tells_the_merchant_to_copy_the_address(): void {
		$notif = $this->push_section( new Fake_Push_Protocol() );
		$store = new Wpdb_Account_Store(
			$this->db,
			$this->schema->table_name( 'accounts', Sqlite_Tables::PREFIX ),
			new Secret_Cipher( 'salt' ),
			Sqlite_Tables::MARKETPLACE_ID,
			new Credential_Fields( 'cabinet_id', [ 'api_token' ] )
		);
		$store->save( new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'Shop', true, [ 'cabinet_id' => 'CAB-1', 'api_token' => 'sec-1' ] ) );

		$sections = $notif->sections();

		$this->assertStringContainsString( 'Скопируйте адрес сайта для уведомлений', $sections[0]->get_description() );
		$this->assertStringNotContainsString( 'Зарегистрировать URL', $sections[0]->get_description() );
		$this->assertStringContainsString( '/notifications/', $sections[1]->get_description(), 'the address to copy is in the account card' );
	}

	public function test_an_adapter_without_notification_types_gets_no_switches_card(): void {
		$notif = $this->push_section( new Fake_Push_Protocol( '', [] ) );

		$this->assertSame( [], $notif->sections() );
	}

	public function test_the_proxy_notice_needs_a_proxy_and_a_recent_request_on_the_old_address_even_one_the_proxy_made_refuse(): void {
		$remote = $_SERVER['REMOTE_ADDR'] ?? null;

		if ( ! class_exists( '\\WC_Geolocation' ) ) {
			require_once dirname( __DIR__, 2 ) . '/Shipping/Rest_Api/wc-geolocation-stub.php';
		}

		$_SERVER['REMOTE_ADDR']   = '10.0.0.2';
		\WC_Geolocation::$address = '198.51.100.20';

		try {
			$notif = $this->push_section( new Fake_Push_Protocol() );

			$this->assertStringNotContainsString( 'за прокси', $notif->sections()[0]->get_description(), 'behind a proxy, but nothing arrives on the old address' );

			// The proxy's address is not the marketplace's: the old address refuses the request with a 403 and stores nothing.
			$answer = $this->intake()->handle_tokenless( 'cabinet-4417', '{}', [ 'REMOTE_ADDR' => Fake_Push_Protocol::FOREIGN_IP ] );
			$this->assertSame( 403, $answer->status() );

			$description = $notif->sections()[0]->get_description();

			$this->assertStringContainsString( 'за прокси', $description, 'shown without the logging setting' );
			$this->assertStringContainsString( 'старый адрес на новый', $description );
			$this->assertStringContainsString( 'woodev_fake_push_trusted_proxies', $description );

			$this->options['woodev_fake_mp_tokenless_seen_at'] = time() - 30 * DAY_IN_SECONDS;
			$this->assertStringNotContainsString( 'за прокси', $notif->sections()[0]->get_description(), 'an old address that went quiet needs no notice' );

			$this->options['woodev_fake_mp_tokenless_seen_at'] = time();
			\WC_Geolocation::$address = '10.0.0.2';
			$this->assertStringNotContainsString( 'за прокси', $notif->sections()[0]->get_description(), 'no forwarded address, no proxy' );
		} finally {
			\WC_Geolocation::$address = null;

			if ( null === $remote ) {
				unset( $_SERVER['REMOTE_ADDR'] );
			} else {
				$_SERVER['REMOTE_ADDR'] = $remote;
			}
		}
	}

	public function test_a_request_on_the_old_address_is_noted_at_most_once_an_hour(): void {
		$now  = 1000000;
		$seen = new \Woodev\Framework\Marketplace\Intake\Tokenless_Seen(
			'fake_mp',
			static function () use ( &$now ): int {
				return $now;
			}
		);

		$seen->note();
		$this->assertSame( 1000000, $this->options['woodev_fake_mp_tokenless_seen_at'] );

		$now += 1800;
		$seen->note();
		$this->assertSame( 1000000, $this->options['woodev_fake_mp_tokenless_seen_at'], 'within the hour nothing is written' );

		$now += 1800;
		$seen->note();
		$this->assertSame( 1003600, $this->options['woodev_fake_mp_tokenless_seen_at'] );
		$this->assertTrue( $seen->seen_since( 1003000 ) );
		$this->assertFalse( $seen->seen_since( 1004000 ) );
	}

	/**
	 * An intake of the fake marketplace that notes the old address the way the plugin wires it.
	 *
	 * @return \Woodev\Framework\Marketplace\Intake\Notification_Intake
	 */
	private function intake(): \Woodev\Framework\Marketplace\Intake\Notification_Intake {
		$protocol = new Fake_Push_Protocol();

		return new \Woodev\Framework\Marketplace\Intake\Notification_Intake(
			new \Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Scripted_Inbound_Decoder(),
			$protocol,
			new \Woodev\Framework\Marketplace\Intake\Sender_Network( Sqlite_Tables::MARKETPLACE_ID, $protocol ),
			new \Woodev\Framework\Marketplace\Intake\Webhook_Switches( [], 'woodev_fake_mp_' ),
			new \Woodev\Framework\Marketplace\Ledger\Wpdb_Event_Ledger( $this->db, $this->schema->table_name( 'events', Sqlite_Tables::PREFIX ) ),
			new Notification_Account_Reader( $this->db, $this->schema->table_name( 'accounts', Sqlite_Tables::PREFIX ) ),
			new \Woodev\Framework\Marketplace\Jobs\Drain_Scheduler( new \Woodev\Framework\Marketplace\Jobs\Job_Names( 'fake_mp' ) ),
			new \Woodev\Framework\Marketplace\Intake\Push_Rate_Limiter(),
			null,
			new \Woodev\Framework\Marketplace\Intake\Tokenless_Seen( 'fake_mp' )
		);
	}

	/**
	 * A notifications section over a push-capable fake marketplace with the given protocol.
	 *
	 * @param Fake_Push_Protocol $protocol Protocol.
	 * @return Notifications_Section
	 */
	private function push_section( Fake_Push_Protocol $protocol ): Notifications_Section {
		$adapter = new Fake_Marketplace_Adapter(
			null,
			[
				'id'                  => Sqlite_Tables::MARKETPLACE_ID,
				'capabilities'        => [ Capability::PUSH_NOTIFICATIONS ],
				'credential_fields'   => new Credential_Fields( 'cabinet_id', [ 'api_token' ] ),
				'settings_descriptor' => new Fake_Settings_Descriptor( new Credential_Fields( 'cabinet_id', [ 'api_token' ] ), 'PushMarket' ),
				'push_protocol'       => $protocol,
			]
		);

		return new Notifications_Section(
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
	}

	public function test_option_backed_settings_use_the_base_class_type_conversion(): void {
		$provider = new class() implements Settings_Section_Provider {
			public function register_settings( Settings_Handler $handler ): void {
				$handler->register_setting( 'sync_hour', \Woodev_Setting::TYPE_INTEGER, [ 'name' => 'Hour' ] );
				$handler->register_setting( 'ratio', \Woodev_Setting::TYPE_FLOAT, [ 'name' => 'Ratio' ] );
			}

			public function sections(): array {
				return [];
			}
		};

		$this->options['woodev_fake_mp_sync_hour'] = '3';
		$this->options['woodev_fake_mp_ratio']     = '1.5';

		$handler = new Settings_Handler( 'fake_mp', [ $provider ] );

		$this->assertSame( 3, $handler->get_value( 'sync_hour' ), 'an integer setting loads as an integer, not as the stored string' );
		$this->assertSame( 1.5, $handler->get_value( 'ratio' ) );
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
		$this->assertSame( 'woodev_fake_mp_' . Uninstaller::DELETE_DATA_SETTING, 'woodev_fake_mp_advanced_delete_data_on_uninstall', 'the id the Uninstaller reads' );
	}
}
