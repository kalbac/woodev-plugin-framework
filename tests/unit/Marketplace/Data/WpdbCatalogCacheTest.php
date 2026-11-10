<?php
/**
 * The catalogue cache table.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Data;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Data\Wpdb_Catalog_Cache;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;

/**
 * Gzip JSON in a longblob row keyed `tree` / `attributes:…`, written whole and replaced on refresh.
 */
final class WpdbCatalogCacheTest extends TestCase {
	private const NOW = 1794139200;

	/**
	 * Database.
	 *
	 * @var Sqlite_Wpdb
	 */
	private $db;

	/**
	 * Cache.
	 *
	 * @var Wpdb_Catalog_Cache
	 */
	private $cache;

	/**
	 * Clock.
	 *
	 * @var int
	 */
	private $now = self::NOW;

	/**
	 * Boots the stubs and the table.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->now = self::NOW;
		$this->db  = new Sqlite_Wpdb();
		Sqlite_Tables::install( $this->db );
		$this->cache = new Wpdb_Catalog_Cache(
			$this->db,
			Sqlite_Tables::table( 'catalog_cache' ),
			function (): int {
				return $this->now;
			}
		);
	}

	/**
	 * Tears the stubs down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A payload survives the round trip, including multibyte text, and is stored gzip-compressed.
	 *
	 * @return void
	 */
	public function test_a_payload_round_trips_compressed(): void {
		$payload = [
			[
				'category_name' => 'Дом и сад',
				'children'      => [],
			],
		];

		$this->cache->put( Wpdb_Catalog_Cache::KEY_TREE, $payload );

		$this->assertSame( $payload, $this->cache->get( Wpdb_Catalog_Cache::KEY_TREE ) );
		$stored = (string) $this->db->rows( 'SELECT payload FROM ' . Sqlite_Tables::table( 'catalog_cache' ) )[0]['payload'];
		$this->assertSame( "\x1f\x8b", substr( $stored, 0, 2 ), 'the row holds gzip bytes' );
	}

	/**
	 * A key nobody wrote is a miss, with no timestamp.
	 *
	 * @return void
	 */
	public function test_a_missing_key_is_a_miss(): void {
		$this->assertNull( $this->cache->get( 'tree' ) );
		$this->assertNull( $this->cache->fetched_at( 'tree' ) );
	}

	/**
	 * A second write replaces the payload and moves `fetched_at` (a UTC datetime).
	 *
	 * @return void
	 */
	public function test_a_second_write_replaces_the_row(): void {
		$key = Wpdb_Catalog_Cache::attributes_key(
			[
				'type_id'                 => 94091,
				'description_category_id' => 17029018,
			]
		);
		$this->cache->put( $key, [ [ 'id' => 1 ] ] );
		$this->now += 600;
		$this->cache->put( $key, [ [ 'id' => 2 ] ] );

		$this->assertSame( 'attributes:17029018:94091', $key, 'values in the order of the sorted field names' );
		$this->assertSame( [ [ 'id' => 2 ] ], $this->cache->get( $key ) );
		$this->assertSame( self::NOW + 600, $this->cache->fetched_at( $key ) );
		$this->assertCount( 1, $this->db->rows( 'SELECT 1 FROM ' . Sqlite_Tables::table( 'catalog_cache' ) . " WHERE cache_key NOT LIKE 'fetched:%'" ) );
	}

	/**
	 * A one-id category descriptor gives a one-id key.
	 *
	 * @return void
	 */
	public function test_the_attributes_key_fits_any_category_shape(): void {
		$this->assertSame( 'attributes:8126', Wpdb_Catalog_Cache::attributes_key( [ 'subject_id' => 8126 ] ) );
	}

	/**
	 * A damaged row reads as a miss, never as an error; plain JSON (a host without zlib) is still read.
	 *
	 * @return void
	 */
	public function test_a_damaged_row_is_a_miss_and_plain_json_is_read(): void {
		$table = Sqlite_Tables::table( 'catalog_cache' );
		$this->db->replace(
			$table,
			[
				'cache_key'  => 'a',
				'payload'    => "\x1f\x8bnot gzip",
				'fetched_at' => '2026-11-08 00:00:00',
			]
		);
		$this->db->replace(
			$table,
			[
				'cache_key'  => 'b',
				'payload'    => '[1,2]',
				'fetched_at' => '2026-11-08 00:00:00',
			]
		);

		$this->assertNull( $this->cache->get( 'a' ) );
		$this->assertSame( [ 1, 2 ], $this->cache->get( 'b' ) );
	}

	/**
	 * A conditional write lands only on the row revision the caller saw, or on no row when none was seen.
	 *
	 * @return void
	 */
	public function test_a_conditional_write_needs_the_row_the_caller_saw(): void {
		$this->assertTrue( $this->cache->replace_if_unchanged( 'k', [ 1 ], null ), 'absent row, nothing seen' );
		$this->assertFalse( $this->cache->replace_if_unchanged( 'k', [ 2 ], null ), 'the row appeared meanwhile' );
		$this->assertSame( [ 1 ], $this->cache->get( 'k' ) );

		$seen       = $this->cache->revision( 'k' );
		$this->now += 5;
		$this->assertTrue( $this->cache->replace_if_unchanged( 'k', [ 3 ], $seen ) );
		$this->assertFalse( $this->cache->replace_if_unchanged( 'k', [ 4 ], $seen ), 'the row moved on after the first write' );
		$this->assertSame( [ 3 ], $this->cache->get( 'k' ) );
	}
}
