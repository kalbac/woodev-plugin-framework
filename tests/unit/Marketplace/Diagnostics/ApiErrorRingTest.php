<?php
/**
 * The error ring writer.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Diagnostics;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Diagnostics\Api_Error_Ring;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Schema;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;

/**
 * Runs the writer's real SQL on SQLite.
 */
final class ApiErrorRingTest extends TestCase {
	/**
	 * Database.
	 *
	 * @var Sqlite_Wpdb
	 */
	private $db;

	/**
	 * Writer under test; the clock stands at 2026-11-08 12:00:00 UTC.
	 *
	 * @var Api_Error_Ring
	 */
	private $ring;

	/**
	 * Opens a database.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->db = new Sqlite_Wpdb();
		Sqlite_Schema::install( $this->db );
		$this->ring = new Api_Error_Ring(
			$this->db,
			Sqlite_Schema::API_ERRORS,
			static function (): int {
				return 1794139200;
			}
		);
	}

	/**
	 * Reads the stored rows, oldest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rows(): array {
		return $this->db->rows( 'SELECT * FROM ' . Sqlite_Schema::API_ERRORS . ' ORDER BY error_id' );
	}

	/**
	 * Every column of spec 3.1 is written.
	 *
	 * @return void
	 */
	public function test_a_failed_call_is_written_with_every_column(): void {
		$this->ring->record( 7, '/v2/products/stocks', 401, '16', 'Invalid Api-Key', '3b2c1421dcf4f205', 'woodev_fake_flush_sync' );

		$row = $this->rows()[0];
		$this->assertSame( '7', (string) $row['account_id'] );
		$this->assertSame( '/v2/products/stocks', $row['path'] );
		$this->assertSame( '401', (string) $row['http_status'] );
		$this->assertSame( '16', $row['code'] );
		$this->assertSame( 'Invalid Api-Key', $row['message'] );
		$this->assertSame( '3b2c1421dcf4f205', $row['trace_id'] );
		$this->assertSame( '2026-11-08 12:00:00', $row['occurred_at'] );
		$this->assertSame( 'woodev_fake_flush_sync', $row['context'] );
	}

	/**
	 * A line that belongs to no account (a site-wide problem) stores NULL, not account 0.
	 *
	 * @return void
	 */
	public function test_a_site_wide_line_has_no_account(): void {
		$this->ring->record( null, '', 0, 'claim_lost', 'The claim was taken over.' );

		$this->assertNull( $this->rows()[0]['account_id'] );
	}

	/**
	 * Long values are cut to their columns, so one huge answer cannot fill the table.
	 *
	 * @return void
	 */
	public function test_long_values_are_cut(): void {
		$this->ring->record( 1, str_repeat( 'p', 300 ), 500, str_repeat( 'c', 100 ), str_repeat( 'я', 3000 ), str_repeat( 't', 100 ), str_repeat( 'x', 100 ) );

		$row = $this->rows()[0];
		$this->assertSame( 191, strlen( $row['path'] ) );
		$this->assertSame( 64, strlen( $row['code'] ) );
		$this->assertSame( Api_Error_Ring::MESSAGE_LENGTH, mb_strlen( $row['message'] ) );
		$this->assertSame( 64, strlen( $row['trace_id'] ) );
		$this->assertSame( 64, strlen( $row['context'] ) );
	}

	/**
	 * The ring keeps the newest 200 lines and drops the oldest.
	 *
	 * @return void
	 */
	public function test_the_ring_keeps_the_newest_two_hundred_lines(): void {
		for ( $i = 1; $i <= 205; $i++ ) {
			$this->ring->record( 1, '/v1/roles', 500, '', 'failure ' . $i );
		}

		$rows = $this->rows();
		$this->assertCount( 200, $rows );
		$this->assertSame( 'failure 6', $rows[0]['message'], 'lines 1 to 5 were dropped' );
		$this->assertSame( 'failure 205', $rows[199]['message'] );
	}

	/**
	 * Below the capacity nothing is deleted.
	 *
	 * @return void
	 */
	public function test_nothing_is_deleted_below_the_capacity(): void {
		for ( $i = 1; $i <= 199; $i++ ) {
			$this->ring->record( 1, '/v1/roles', 500, '', 'failure ' . $i );
		}

		$this->assertCount( 199, $this->rows() );
	}
}
