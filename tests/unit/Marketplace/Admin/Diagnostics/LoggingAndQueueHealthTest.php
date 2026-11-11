<?php
/**
 * Unit tests for Logging, Queue_Health and Marketplace_Plugin logging gating.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Diagnostics;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Logging;
use Woodev\Framework\Marketplace\Admin\Diagnostics\Queue_Health;
use Woodev\Framework\Marketplace\Data\Schema;
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
		$logger  = Mockery::mock( '\WC_Logger_Interface' );
		$logger->shouldReceive( 'log' )->andReturnUsing(
			static function ( string $level, string $message, array $context = [] ) use ( &$entries ): void {
				$entries[] = [
					'level'   => $level,
					'message' => $message,
					'context' => $context,
				];
			}
		);

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

		// One debug line for the debug call, one for the request, one for the response.
		$this->assertCount( 4, $entries );
		$this->assertSame( 'debug', $entries[1]['level'] );
		$this->assertStringNotContainsString( 'my-live-key', $entries[1]['message'] );
		$this->assertSame( 'debug', $entries[2]['level'] );
		$this->assertStringNotContainsString( 'my-live-key', $entries[2]['message'] );
		$this->assertStringNotContainsString( 'abcdef0123456789abcdef0123456789', $entries[2]['message'] );
		$this->assertStringContainsString( 'Запрос', $entries[2]['message'] );
		$this->assertSame( 'debug', $entries[3]['level'] );
		$this->assertStringNotContainsString( 'hidden-tok', $entries[3]['message'] );
		$this->assertStringContainsString( 'Ответ', $entries[3]['message'] );
		$this->assertSame( 'fake_mp', $entries[3]['context']['source'], 'the log source is the plugin id, as the base class writes it' );
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

		// Action Scheduler answers through its public API, scoped to this plugin's group.
		$queries = [];
		Functions\when( 'as_get_scheduled_actions' )->alias(
			static function ( array $query, string $return ) use ( &$queries ): array {
				$queries[] = $query;

				return [ '41' ];
			}
		);
		$store = new class() {
			/** @var array<int,array<string,mixed>> */
			public $counted = [];

			/** @var string[] */
			public $dated = [];

			public function get_date( string $id ): \DateTimeImmutable {
				$this->dated[] = $id;

				return new \DateTimeImmutable( '@' . ( 1794139200 - 45 ) );
			}

			/** @param array<string,mixed> $query */
			public function query_actions( array $query, string $return ): int {
				$this->counted[] = $query;

				return 3;
			}
		};
		$queue = new Queue_Health( $db, $names, $schema, static function (): int { return self::NOW; }, $store );

		$this->assertSame( self::NOW - 45, $queue->last_run_at() );
		$this->assertSame( 'woodev-fake-mp', $queries[0]['group'], 'only this plugin\'s group, not every claim on the site' );
		$this->assertSame( 'complete', $queries[0]['status'] );
		$this->assertSame( [ '41' ], $store->dated );

		$this->assertSame( 3, $queue->overdue_count() );
		$this->assertSame( 'woodev-fake-mp', $store->counted[0]['group'] );
		$this->assertSame( 'pending', $store->counted[0]['status'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::NOW - Queue_Health::OVERDUE_GRACE_SECONDS ), $store->counted[0]['date'] );
		$this->assertSame( '<=', $store->counted[0]['date_compare'] );

		// Nothing finished yet.
		Functions\when( 'as_get_scheduled_actions' )->justReturn( [] );
		$this->assertNull( $queue->last_run_at() );
	}
}
