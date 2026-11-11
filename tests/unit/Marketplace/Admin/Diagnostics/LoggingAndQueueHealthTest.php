<?php
/**
 * Unit tests for Logging, Error_Log, Queue_Health and Marketplace_Plugin logging gating.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Diagnostics;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Error_Log;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Logging;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Queue_Health;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Diagnostics\Api_Error_Ring;
use Woodev\Framework\Marketplace\Jobs\Job_Names;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Tests\Unit\Marketplace\Admin\Test_Marketplace_Plugin;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Account_Store;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace_Adapter;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;
use Woodev\Tests\Unit\TestCase;

final class LoggingAndQueueHealthTest extends TestCase {
	private const NOW = 1794139200;

	public function test_logging_reads_expected_option_states(): void {
		$this->assertSame( 'woodev_fake_seller_advanced_logging', Logging::option_name_for( 'fake_seller' ) );

		$off = new Logging(
			'woodev_fake_seller_advanced_logging',
			static function (): string {
				return 'no';
			}
		);
		$on  = new Logging(
			'woodev_fake_seller_advanced_logging',
			static function (): string {
				return 'yes';
			}
		);

		$this->assertFalse( $off->is_enabled() );
		$this->assertTrue( $on->is_enabled() );
	}

	public function test_marketplace_plugin_gates_debug_and_api_logs_behind_logging_option_and_redacts(): void {
		$entries = [];
		$logger  = new class( $entries ) {
			/** @var array<int,array{level:string,message:string,context:array<string,mixed>}> */
			public $entries;

			/** @param array<int,array{level:string,message:string,context:array<string,mixed>}> $entries */
			public function __construct( array &$entries ) {
				$this->entries = &$entries;
			}

			/** @param array<string,mixed> $context */
			public function log( string $level, string $message, array $context = [] ): void {
				$this->entries[] = [
					'level'   => $level,
					'message' => $message,
					'context' => $context,
				];
			}
		};

		$logging_option = 'no';
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) use ( &$logging_option ) {
				if ( 'woodev_fake_mp_advanced_logging' === $key ) {
					return $logging_option;
				}
				return $default;
			}
		);
		Functions\when( 'wc_get_logger' )->justReturn( $logger );

		$plugin = new Test_Marketplace_Plugin(
			'fake_mp',
			[
				'menu_slug'  => 'fake-mp',
				'menu_title' => 'Fake MP',
				'page_title' => 'Fake MP',
			]
		);

		// Logging OFF: debug and API request logs stay silent, error log still writes (redacted).
		$plugin->log_debug( 'Verbose trace with token=super-secret-value' );
		$plugin->log_api_request( [ 'uri' => 'https://api.example/v1?api_key=rawkey123' ], [ 'code' => 200 ] );
		$plugin->log_error( 'Fatal upstream Authorization: Bearer secret-jwt-token' );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'error', $entries[0]['level'] );
		$this->assertStringNotContainsString( 'secret-jwt-token', $entries[0]['message'] );
		$this->assertStringContainsString( '[скрыто]', $entries[0]['message'] );

		// Logging ON: debug and API request logs are written with secrets scrubbed.
		$logging_option = 'yes';
		$plugin->log_debug( 'Debug line Api-Key: my-live-key' );
		$plugin->log_api_request(
			[
				'method'  => 'POST',
				'uri'     => 'https://shop.test/wp-json/woodev/v1/fake/notifications/4/abcdef0123456789abcdef0123456789',
				'headers' => [ 'Api-Key' => 'my-live-key' ],
			],
			[
				'code' => 200,
				'body' => '{"token":"hidden-tok"}',
			]
		);

		$this->assertCount( 3, $entries );
		$this->assertSame( 'debug', $entries[1]['level'] );
		$this->assertStringNotContainsString( 'my-live-key', $entries[1]['message'] );
		$this->assertSame( 'debug', $entries[2]['level'] );
		$this->assertStringNotContainsString( 'my-live-key', $entries[2]['message'] );
		$this->assertStringNotContainsString( 'abcdef0123456789abcdef0123456789', $entries[2]['message'] );
		$this->assertStringNotContainsString( 'hidden-tok', $entries[2]['message'] );
		$this->assertStringContainsString( 'Запрос', $entries[2]['message'] );
		$this->assertStringContainsString( 'Ответ', $entries[2]['message'] );
	}

	public function test_error_log_records_redacted_entry_in_ring_and_invokes_sink(): void {
		$db = new Sqlite_Wpdb();
		Sqlite_Tables::install( $db );
		$schema = Sqlite_Tables::schema();
		$ring   = new Api_Error_Ring(
			$db,
			$schema->table_name( 'api_errors', Sqlite_Tables::PREFIX ),
			static function (): int {
				return self::NOW;
			}
		);

		$logged = [];
		$err    = new Error_Log(
			$ring,
			static function ( string $line ) use ( &$logged ): void {
				$logged[] = $line;
			},
			[ 'vendor_secret_key' ]
		);

		$err->record_failure(
			3,
			'/v1/push/notifications/3/0123456789abcdef0123456789abcdef',
			401,
			'AUTH',
			'Rejected header vendor-secret-key: ultra-secret-999',
			'trace-42'
		);

		$this->assertCount( 1, $logged );
		$this->assertStringNotContainsString( 'ultra-secret-999', $logged[0] );
		$this->assertStringNotContainsString( '0123456789abcdef0123456789abcdef', $logged[0] );

		$rows = $db->get_results( 'SELECT path, message FROM wp_woodev_fake_api_errors', 'ARRAY_A' );
		$this->assertCount( 1, $rows );
		$this->assertStringNotContainsString( 'ultra-secret-999', (string) $rows[0]['message'] );
		$this->assertStringContainsString( '[скрыто]', (string) $rows[0]['message'] );
		$this->assertStringContainsString( '/notifications/3/[скрыто]', (string) $rows[0]['path'] );
	}

	public function test_queue_health_reads_action_scheduler_and_ledger_tables_and_tolerates_missing_as_tables(): void {
		$db = new Sqlite_Wpdb();
		Sqlite_Tables::install( $db );
		$schema = new Schema( Sqlite_Tables::MARKETPLACE_ID );
		$names  = new Job_Names( 'fake_mp' );

		// Without Action Scheduler tables: returns null / 0 gracefully, while reading events & tasks.
		$db->insert(
			'wp_woodev_fake_events',
			[
				'account_id'  => 1,
				'dedup_key'   => 'ev-1',
				'type'        => 'ORDER_NEW',
				'payload'     => '{}',
				'status'      => 'new',
				'received_at' => gmdate( 'Y-m-d H:i:s', self::NOW - 120 ),
			]
		);
		$db->insert(
			'wp_woodev_fake_events',
			[
				'account_id'  => 1,
				'dedup_key'   => 'ev-2',
				'type'        => 'ORDER_CANCEL',
				'payload'     => '{}',
				'status'      => 'failed',
				'received_at' => gmdate( 'Y-m-d H:i:s', self::NOW - 60 ),
			]
		);
		$db->insert(
			'wp_woodev_fake_tasks',
			[
				'account_id'   => 1,
				'kind'         => Async_Task::KIND_IMPORT,
				'status'       => Async_Task::STATUS_POLLING,
				'submitted_at' => gmdate( 'Y-m-d H:i:s', self::NOW - 300 ),
			]
		);
		$task_id = (int) $db->insert_id;
		$db->insert(
			'wp_woodev_fake_task_items',
			[
				'task_id' => $task_id,
				'link_id' => 10,
				'status'  => 'failed',
			]
		);

		$queue = new Queue_Health(
			$db,
			$names,
			$schema,
			static function (): int {
				return self::NOW;
			}
		);

		$this->assertNull( $queue->last_run_at() );
		$this->assertSame( 0, $queue->overdue_count() );

		$summary = $queue->ledger_summary();
		$this->assertSame( 1, $summary['events_pending'] );
		$this->assertSame( 1, $summary['events_failed'] );
		$this->assertSame( 120, $summary['oldest_event_age'] );
		$this->assertSame( 1, $summary['open_tasks'] );
		$this->assertSame( 1, $summary['failed_task_items'] );

		// Now install Action Scheduler tables and verify group-scoped overdue count and last_run_at.
		$db->query( 'CREATE TABLE wp_actionscheduler_groups (group_id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT)' );
		$db->query( 'CREATE TABLE wp_actionscheduler_claims (claim_id INTEGER PRIMARY KEY AUTOINCREMENT, date_created_gmt TEXT)' );
		$db->query( 'CREATE TABLE wp_actionscheduler_actions (action_id INTEGER PRIMARY KEY AUTOINCREMENT, group_id INTEGER, status TEXT, scheduled_date_gmt TEXT, last_attempt_gmt TEXT)' );

		$db->insert( 'wp_actionscheduler_groups', [ 'slug' => $names->group() ] );
		$own_group = (int) $db->insert_id;
		$db->insert( 'wp_actionscheduler_groups', [ 'slug' => 'other_plugin' ] );
		$other_group = (int) $db->insert_id;

		$db->insert( 'wp_actionscheduler_claims', [ 'date_created_gmt' => gmdate( 'Y-m-d H:i:s', self::NOW - 45 ) ] );
		// Overdue action in our group (10 min ago).
		$db->insert(
			'wp_actionscheduler_actions',
			[
				'group_id'           => $own_group,
				'status'             => 'pending',
				'scheduled_date_gmt' => gmdate( 'Y-m-d H:i:s', self::NOW - 600 ),
				'last_attempt_gmt'   => '0000-00-00 00:00:00',
			]
		);
		// Within grace window (60s ago) -> not overdue.
		$db->insert(
			'wp_actionscheduler_actions',
			[
				'group_id'           => $own_group,
				'status'             => 'pending',
				'scheduled_date_gmt' => gmdate( 'Y-m-d H:i:s', self::NOW - 60 ),
				'last_attempt_gmt'   => '0000-00-00 00:00:00',
			]
		);
		// Overdue in another plugin's group -> ignored.
		$db->insert(
			'wp_actionscheduler_actions',
			[
				'group_id'           => $other_group,
				'status'             => 'pending',
				'scheduled_date_gmt' => gmdate( 'Y-m-d H:i:s', self::NOW - 3600 ),
				'last_attempt_gmt'   => '0000-00-00 00:00:00',
			]
		);

		$this->assertSame( self::NOW - 45, $queue->last_run_at() );
		$this->assertSame( 1, $queue->overdue_count() );
		$this->assertSame( 2, $queue->pending_actions_count() );
	}
}
