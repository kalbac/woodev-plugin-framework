<?php
/**
 * Unit tests for Popular_Settlement_Store — the popular-settlements table (#488
 * slice 2, spec D1-D3), the D4 provider-capability gate, the D4a derived-key gate,
 * the two independent clocks (D2), ranking by order_count, cap eviction, that a
 * foreign provider_id is never offered, the checkout-candidate breadcrumb, and (round
 * 2 critic findings) the atomic upsert against a real `(provider_id, locality_key)`
 * unique key (MEDIUM 5) and a schema/install smoke test (MEDIUM 4).
 *
 * Pure PHP — no WooCommerce or WordPress runtime required; the store is driven
 * through a fake \wpdb double that GENUINELY applies the WHERE clause it is handed
 * (extracted from the query text, not hardcoded to "always filter by provider_id") and
 * genuinely simulates `INSERT … ON DUPLICATE KEY UPDATE` against its in-memory row
 * set — round 2 fix for the critic finding that the round-1 fake ignored the SQL
 * text entirely and so would have stayed green even if the production WHERE clause
 * were deleted.
 *
 * @package Woodev\Tests\Unit\Shipping\Location
 */

namespace {

	require_once dirname( __DIR__, 4 ) . '/woodev/class-helper.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-scope.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/abstract-location-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-entry.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-popular-settlement-store.php';

	use Woodev\Framework\Shipping\Location\Abstract_Location_Provider;
	use Woodev\Framework\Shipping\Location\Location_Record;
	use Woodev\Framework\Shipping\Location\Location_Scope;

	// WP row-format constants the store passes to wpdb reads.
	if ( ! defined( 'OBJECT' ) ) {
		define( 'OBJECT', 'OBJECT' );
	}
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}

	// install() requires dbDelta(); stub it so the "no such function" branch in
	// install() (which requires a real wp-admin/includes/upgrade.php) never runs.
	if ( ! function_exists( 'dbDelta' ) ) {
		function dbDelta( $sql ) {
			$GLOBALS['popular_settlement_store_test_last_dbdelta_sql'] = $sql;

			return [];
		}
	}

	// The store constructor type-hints \wpdb; a minimal stub lets the fake below satisfy it.
	if ( ! class_exists( '\wpdb', false ) ) {
		class PopularSettlementStore_Wpdb_Stub {}

		class_alias( PopularSettlementStore_Wpdb_Stub::class, 'wpdb' );
	}

	/**
	 * A small, real in-memory SQL simulator scoped exactly to the four query shapes
	 * Popular_Settlement_Store issues: `SELECT … WHERE col = %s [AND col2 = %s]`,
	 * `INSERT … ON DUPLICATE KEY UPDATE …`, and `DELETE` (via wpdb::delete()'s own
	 * `$where` array, never raw SQL).
	 *
	 * `apply_where()` extracts the compared COLUMN NAMES from the query TEXT itself
	 * (a `` `col` = %s `` regex) and zips them against the bound `prepare()` args —
	 * it does not know in advance which columns the production query filters by, so
	 * if the store ever dropped a `WHERE` clause (or a bound column), this fake's
	 * filtering would silently change too, and a test asserting the read RESULT
	 * (not merely "prepare() was called with these args") would fail. This is what
	 * closes the round-2 critic finding (MEDIUM 4) against the round-1 fake, whose
	 * own docblock admitted it ignored the SQL text.
	 */
	class Popular_Settlement_Store_Fake_Wpdb extends \wpdb {

		/** @var string table prefix */
		public string $prefix = 'wp_';

		/** @var array<int,array<string,mixed>> the in-memory table */
		public array $rows = [];

		/** @var array<int,array{sql: string, args: array<int,mixed>}> every raw query() call (the atomic upsert) */
		public array $queries = [];

		/** @var array<int,array<string,mixed>> recorded delete() calls */
		public array $deletes = [];

		/** @var string|null the raw query TEXT the last prepare() call received */
		private ?string $last_query = null;

		/** @var array<int,mixed> the bound args the last prepare() call received */
		private array $last_args = [];

		/** @var int next auto-increment id for a row this fake inserts */
		private int $next_id = 1;

		public function get_charset_collate() {
			return 'DEFAULT CHARACTER SET utf8mb4';
		}

		/**
		 * Captures the query text + bound args for the next read/write to use.
		 *
		 * @param string $query   SQL with %s/%d placeholders
		 * @param mixed  ...$args bound arguments
		 * @return string the SAME query text, unmodified (real substitution is not needed by these tests)
		 */
		public function prepare( $query, ...$args ) {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}

			$this->last_query = $query;
			$this->last_args  = $args;

			return $query;
		}

		/**
		 * Returns the in-memory rows filtered by whatever `` `col` = %s `` pairs the
		 * last prepared query text actually contains.
		 *
		 * @param string $query  SQL query (ignored — filtering reads {@see self::$last_query})
		 * @param string $output output type (ignored)
		 * @return array<int,array<string,mixed>>
		 */
		public function get_results( $query, $output = OBJECT ) {
			return array_values( $this->apply_where( $this->rows ) );
		}

		/**
		 * Returns the first row matching the last prepared query's WHERE columns, or null.
		 *
		 * @param string $query  SQL query (ignored)
		 * @param string $output output type (ignored)
		 * @return array<string,mixed>|null
		 */
		public function get_row( $query, $output = OBJECT ) {
			$rows = $this->apply_where( $this->rows );

			return $rows[0] ?? null;
		}

		/**
		 * Records a raw query() call and, when it is the atomic upsert (detected by
		 * the `ON DUPLICATE KEY UPDATE` text it always carries), actually applies it
		 * to the in-memory row set — matching a real `(provider_id, locality_key)`
		 * unique key: an existing row bumps `order_count`/`last_ordered_at` only, a
		 * new key inserts a fresh row.
		 *
		 * @param string $query the prepared query (as returned by prepare(), i.e. unchanged)
		 * @return int
		 */
		public function query( $query ) {
			$this->queries[] = [
				'sql'  => $this->last_query,
				'args' => $this->last_args,
			];

			if ( false !== strpos( (string) $this->last_query, 'ON DUPLICATE KEY UPDATE' ) ) {
				$this->apply_upsert();
			}

			return 1;
		}

		/**
		 * Records a delete() call and actually removes matching rows, so a test can
		 * observe the resulting state (e.g. "the evicted row is really gone, the
		 * newly-inserted one is really there").
		 *
		 * @param string              $table table name (ignored)
		 * @param array<string,mixed> $where column => value, ALL of which must match
		 * @return int
		 */
		public function delete( $table, $where ) {
			$this->deletes[] = [
				'table' => $table,
				'where' => $where,
			];

			$this->rows = array_values(
				array_filter(
					$this->rows,
					function ( array $row ) use ( $where ): bool {
						foreach ( $where as $column => $value ) {
							if ( (string) ( $row[ $column ] ?? null ) !== (string) $value ) {
								return true; // some criterion doesn't match — row survives
							}
						}

						return false; // every criterion matched — row is deleted
					}
				)
			);

			return 1;
		}

		/**
		 * Extracts `` `col` = %s `` column names from {@see self::$last_query} and
		 * filters `$rows` by zipping them against {@see self::$last_args}. Returns
		 * `$rows` unfiltered when the query text has no such comparisons at all.
		 *
		 * @param array<int,array<string,mixed>> $rows
		 * @return array<int,array<string,mixed>>
		 */
		private function apply_where( array $rows ): array {
			preg_match_all( '/`(\w+)`\s*=\s*%[sd]/', (string) $this->last_query, $matches );
			$columns = $matches[1] ?? [];

			if ( empty( $columns ) ) {
				return $rows;
			}

			return array_values(
				array_filter(
					$rows,
					function ( array $row ) use ( $columns ): bool {
						foreach ( $columns as $i => $column ) {
							if ( ( $row[ $column ] ?? null ) !== ( $this->last_args[ $i ] ?? null ) ) {
								return false;
							}
						}

						return true;
					}
				)
			);
		}

		/**
		 * Applies the atomic upsert to {@see self::$rows}: matches an existing row by
		 * `(provider_id, locality_key)` and bumps ONLY `order_count`/`last_ordered_at`
		 * (never `record`/`country` — real `ON DUPLICATE KEY UPDATE` only touches the
		 * columns named in its own clause), or inserts a fresh row.
		 *
		 * @return void
		 */
		private function apply_upsert(): void {
			// Bound arg order matches the store's own prepare() call exactly: provider_id,
			// locality_key, country, record, last_ordered_at, created_at (order_count and
			// last_verified_at are literals in the SQL, not placeholders).
			[ $provider_id, $locality_key, $country, $record, $last_ordered_at, $created_at ] = $this->last_args;

			foreach ( $this->rows as &$row ) {
				if ( ( $row['provider_id'] ?? null ) === $provider_id && ( $row['locality_key'] ?? null ) === $locality_key ) {
					$row['order_count']     = ( (int) $row['order_count'] ) + 1;
					$row['last_ordered_at'] = $last_ordered_at;

					return;
				}
			}
			unset( $row );

			$this->rows[] = [
				'id'                => (string) $this->next_id++,
				'provider_id'       => $provider_id,
				'locality_key'      => $locality_key,
				'country'           => $country,
				'record'            => $record,
				'order_count'       => '1',
				'last_ordered_at'   => $last_ordered_at,
				'last_verified_at'  => null,
				'created_at'        => $created_at,
			];
		}
	}

	/**
	 * Declares CAPABILITY_RESOLVE_KEY (via reflection: overriding resolve_key()) —
	 * exactly the D4-eligible shape a provider needs to gain a popular list at all.
	 */
	class Popular_Settlement_Resolving_Fixture_Provider extends Abstract_Location_Provider {

		/** @var string */
		private string $id;

		public function __construct( string $id = 'dadata' ) {
			$this->id = $id;
		}

		public function get_id(): string {
			return $this->id;
		}

		public function get_name(): string {
			return 'Resolving Fixture';
		}

		public function get_countries(): array {
			return [ 'RU' ];
		}

		protected function declare_suggest_levels(): array {
			return [ Location_Record::LEVEL_SETTLEMENT ];
		}

		public function suggest( string $query, Location_Scope $scope ): array {
			return [];
		}

		public function resolve_key( string $key ): ?Location_Record {
			return null;
		}
	}

	/**
	 * Does NOT override resolve_key() — a D4-ineligible provider: no popular list at
	 * all, regardless of what record is handed to enroll().
	 */
	class Popular_Settlement_Non_Resolving_Fixture_Provider extends Abstract_Location_Provider {

		public function get_id(): string {
			return 'geonames';
		}

		public function get_name(): string {
			return 'Non-Resolving Fixture';
		}

		public function get_countries(): array {
			return [ 'RU' ];
		}

		protected function declare_suggest_levels(): array {
			return [ Location_Record::LEVEL_SETTLEMENT ];
		}

		public function suggest( string $query, Location_Scope $scope ): array {
			return [];
		}
	}
}

namespace Woodev\Tests\Unit\Shipping\Location {

	use Brain\Monkey\Functions;
	use Woodev\Framework\Shipping\Location\Locality_Key;
	use Woodev\Framework\Shipping\Location\Location_Record;
	use Woodev\Framework\Shipping\Location\Popular_Settlement_Store;
	use Woodev\Tests\Unit\TestCase;

	/**
	 * @covers \Woodev\Framework\Shipping\Location\Popular_Settlement_Store
	 */
	final class PopularSettlementStoreTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();

			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'current_time' )->alias(
				static function ( $type ) {
					return 'mysql' === $type ? '2026-08-24 12:00:00' : 1756036800;
				}
			);
			Functions\when( 'wp_json_encode' )->alias(
				static function ( $data ) {
					return json_encode( $data );
				}
			);
			Functions\when( 'get_option' )->justReturn( false );
			Functions\when( 'update_option' )->justReturn( true );
			Functions\when( 'get_post_meta' )->justReturn( '' );
			Functions\when( 'update_post_meta' )->justReturn( true );
		}

		private function record( string $provider_id, string $native_id ): Location_Record {
			return Location_Record::from_array(
				[
					'key'         => $provider_id . ':' . $native_id,
					'provider_id' => $provider_id,
					'level'       => Location_Record::LEVEL_SETTLEMENT,
					'country'     => 'RU',
					'settlement'  => [ 'name' => 'Moscow', 'type' => 'city' ],
				]
			);
		}

		/**
		 * Builds a raw stored row for a given record + counters — used ONLY for
		 * read-path tests (ranking, foreign-provider isolation) that seed state
		 * directly rather than through enroll().
		 *
		 * @param Location_Record $record
		 * @return array<string,mixed>
		 */
		private function row( Location_Record $record, int $order_count = 1, ?string $last_ordered_at = '2026-08-24 12:00:00', int $id = 1 ): array {
			return [
				'id'                => (string) $id,
				'provider_id'       => $record->provider_id(),
				'locality_key'      => $record->key(),
				'country'           => $record->country(),
				'record'            => json_encode( $record->to_array() ),
				'order_count'       => (string) $order_count,
				'last_ordered_at'   => $last_ordered_at,
				'last_verified_at'  => null,
				'created_at'        => '2026-08-24 12:00:00',
			];
		}

		/**
		 * D4a: a derived key (never issued by the provider) is never enrolled — not
		 * enrolled-and-exempted, simply never written.
		 */
		public function test_a_derived_key_is_never_enrolled(): void {
			$wpdb     = new \Popular_Settlement_Store_Fake_Wpdb();
			$store    = new Popular_Settlement_Store( $wpdb );
			$provider = new \Popular_Settlement_Resolving_Fixture_Provider();

			$derived_key = Locality_Key::derive( $provider->get_id(), [ 'name' => 'Some Town' ] );

			$record = Location_Record::from_array(
				[
					'key'         => $derived_key,
					'provider_id' => $provider->get_id(),
					'level'       => Location_Record::LEVEL_SETTLEMENT,
					'country'     => 'RU',
				]
			);

			$store->enroll( $provider, $record );

			$this->assertCount( 0, $wpdb->queries, 'A derived key must never even be attempted as a write.' );
		}

		/**
		 * D4: a provider that does not declare CAPABILITY_RESOLVE_KEY gets no popular
		 * list at all — nothing is written even for an otherwise-eligible record.
		 */
		public function test_a_provider_without_the_capability_gets_no_popular_list(): void {
			$wpdb     = new \Popular_Settlement_Store_Fake_Wpdb();
			$store    = new Popular_Settlement_Store( $wpdb );
			$provider = new \Popular_Settlement_Non_Resolving_Fixture_Provider();

			$record = $this->record( $provider->get_id(), '77' );

			$store->enroll( $provider, $record );

			$this->assertCount( 0, $wpdb->queries );
		}

		/**
		 * enroll() refuses a record whose own provider_id disagrees with the given
		 * provider — a caller contract violation, not a business gate.
		 */
		public function test_enroll_refuses_a_mismatched_provider(): void {
			$provider = new \Popular_Settlement_Resolving_Fixture_Provider( 'dadata' );
			$record   = $this->record( 'some-other-provider', '1' );

			$wpdb  = new \Popular_Settlement_Store_Fake_Wpdb();
			$store = new Popular_Settlement_Store( $wpdb );

			$this->expectException( \InvalidArgumentException::class );

			$store->enroll( $provider, $record );
		}

		/**
		 * A genuinely new, eligible settlement is enrolled through a SINGLE atomic
		 * write (round 2, MEDIUM 5) — never a separate insert/update pair — landing
		 * with order_count = 1, last_ordered_at stamped, and last_verified_at left
		 * null (D2: only verification, a later slice, ever writes that column).
		 */
		public function test_a_new_eligible_settlement_is_enrolled_via_one_atomic_write(): void {
			$wpdb     = new \Popular_Settlement_Store_Fake_Wpdb();
			$store    = new Popular_Settlement_Store( $wpdb );
			$provider = new \Popular_Settlement_Resolving_Fixture_Provider();
			$record   = $this->record( $provider->get_id(), '1' );

			$store->enroll( $provider, $record );

			$this->assertCount( 1, $wpdb->queries, 'enroll() must issue exactly one atomic statement.' );
			$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE', $wpdb->queries[0]['sql'] );

			$entries = $store->all_for_provider( $provider->get_id() );

			$this->assertCount( 1, $entries );
			$this->assertSame( 1, $entries[0]->order_count() );
			$this->assertNotNull( $entries[0]->last_ordered_at() );
			$this->assertNull( $entries[0]->last_verified_at(), 'last_verified_at is only ever written by verification (D5/D6), never enrolment.' );
			$this->assertSame( $record->key(), $entries[0]->record()->key() );
		}

		/**
		 * D2/MEDIUM 5: enrolling the SAME settlement twice — driven through the real
		 * atomic-upsert code path, not a pre-seeded row — converges to ONE row whose
		 * order_count is 2, proving the unique key + ON DUPLICATE KEY UPDATE behave
		 * as the two-concurrent-writers case requires: no duplicate row, no lost
		 * increment.
		 */
		public function test_enrolling_the_same_settlement_twice_converges_to_one_row_with_summed_count(): void {
			$wpdb     = new \Popular_Settlement_Store_Fake_Wpdb();
			$store    = new Popular_Settlement_Store( $wpdb );
			$provider = new \Popular_Settlement_Resolving_Fixture_Provider();
			$record   = $this->record( $provider->get_id(), '1' );

			$store->enroll( $provider, $record );
			$store->enroll( $provider, $record );

			$entries = $store->all_for_provider( $provider->get_id() );

			$this->assertCount( 1, $entries, 'Two enrolments of the SAME settlement must never produce two rows.' );
			$this->assertSame( 2, $entries[0]->order_count() );
			$this->assertCount( 2, $wpdb->queries, 'Still two atomic statements — the convergence happens at the DB engine, not by PHP branching insert vs. update.' );
		}

		/**
		 * Ranking: all_for_provider() orders entries by order_count descending.
		 */
		public function test_all_for_provider_ranks_by_order_count_descending(): void {
			$provider = new \Popular_Settlement_Resolving_Fixture_Provider();

			$low  = $this->record( $provider->get_id(), 'low' );
			$high = $this->record( $provider->get_id(), 'high' );
			$mid  = $this->record( $provider->get_id(), 'mid' );

			$wpdb       = new \Popular_Settlement_Store_Fake_Wpdb();
			$wpdb->rows = [
				$this->row( $low, 2, '2026-08-24 12:00:00', 1 ),
				$this->row( $high, 50, '2026-08-24 12:00:00', 2 ),
				$this->row( $mid, 10, '2026-08-24 12:00:00', 3 ),
			];
			$store = new Popular_Settlement_Store( $wpdb );

			$entries = $store->all_for_provider( $provider->get_id() );

			$this->assertCount( 3, $entries );
			$this->assertSame( [ 50, 10, 2 ], array_map( static fn( $e ) => $e->order_count(), $entries ) );
		}

		/**
		 * D3: an entry whose provider_id is not the requested (active) provider is
		 * never returned. This test is now driven through a fake that GENUINELY
		 * filters by whatever WHERE columns the production query text names (see the
		 * fake's own docblock) — it would fail, not merely stay green, if the store's
		 * `WHERE provider_id = %s` clause were ever dropped.
		 */
		public function test_a_foreign_provider_id_is_never_offered(): void {
			$active_provider = new \Popular_Settlement_Resolving_Fixture_Provider( 'dadata' );
			$other_provider  = new \Popular_Settlement_Resolving_Fixture_Provider( 'cdek' );

			$mine    = $this->record( $active_provider->get_id(), '1' );
			$foreign = $this->record( $other_provider->get_id(), '1' );

			$wpdb       = new \Popular_Settlement_Store_Fake_Wpdb();
			$wpdb->rows = [
				$this->row( $mine, 1, '2026-08-24 12:00:00', 1 ),
				$this->row( $foreign, 999, '2026-08-24 12:00:00', 2 ),
			];
			$store = new Popular_Settlement_Store( $wpdb );

			$entries = $store->all_for_provider( $active_provider->get_id() );

			$this->assertCount( 1, $entries );
			$this->assertSame( 'dadata', $entries[0]->provider_id() );
		}

		/**
		 * Eviction: when a provider's live row count is already at the (filtered) cap,
		 * enrolling a genuinely NEW settlement deletes the least-recently-ordered row
		 * first — eviction is keyed on last_ordered_at, not order_count (a different
		 * axis from ranking) — and the new settlement is still enrolled afterwards.
		 */
		public function test_enrolling_past_the_cap_evicts_the_least_recently_ordered_row(): void {
			Functions\when( 'apply_filters' )->alias(
				static function ( $tag, $value ) {
					return Popular_Settlement_Store::FILTER_LIST_CAP === $tag ? 2 : $value;
				}
			);

			$provider = new \Popular_Settlement_Resolving_Fixture_Provider();

			$stale  = $this->record( $provider->get_id(), 'stale' );
			$recent = $this->record( $provider->get_id(), 'recent' );
			$new    = $this->record( $provider->get_id(), 'new' );

			$wpdb       = new \Popular_Settlement_Store_Fake_Wpdb();
			$wpdb->rows = [
				$this->row( $stale, 1, '2026-01-01 00:00:00', 1 ),
				$this->row( $recent, 1, '2026-08-01 00:00:00', 2 ),
			];
			$store = new Popular_Settlement_Store( $wpdb );

			$store->enroll( $provider, $new );

			$this->assertCount( 1, $wpdb->deletes, 'Exactly one row must be evicted to make room.' );
			$this->assertSame( [ 'id' => 1 ], $wpdb->deletes[0]['where'], 'The STALEST row (oldest last_ordered_at) must be evicted, not the lowest order_count.' );

			$entries = $store->all_for_provider( $provider->get_id() );
			$keys    = array_map( static fn( $e ) => $e->record()->key(), $entries );

			$this->assertContains( $recent->key(), $keys, 'The survivor must still be present.' );
			$this->assertContains( $new->key(), $keys, 'The new settlement must be enrolled after eviction made room.' );
			$this->assertNotContains( $stale->key(), $keys, 'The evicted settlement must really be gone.' );
		}

		/**
		 * remember_candidate() / recall_candidate() round-trip a Location_Record
		 * through order meta (the checkout-time breadcrumb, round 2 HIGH 2).
		 */
		public function test_remember_and_recall_candidate_round_trips(): void {
			$store  = new Popular_Settlement_Store( new \Popular_Settlement_Store_Fake_Wpdb() );
			$record = $this->record( 'dadata', '1' );

			$stored_json = null;

			Functions\when( 'update_post_meta' )->alias(
				static function ( $post_id, $key, $value ) use ( &$stored_json ) {
					$stored_json = $value;

					return true;
				}
			);

			$order = \Mockery::mock( '\WC_Order' );
			$order->shouldReceive( 'get_id' )->andReturn( 42 );

			$store->remember_candidate( $order, $record );

			$this->assertNotNull( $stored_json );

			Functions\when( 'get_post_meta' )->alias(
				static function ( $post_id, $key, $single ) use ( &$stored_json ) {
					return $stored_json;
				}
			);

			$recalled = $store->recall_candidate( $order );

			$this->assertNotNull( $recalled );
			$this->assertSame( $record->key(), $recalled->key() );
		}

		/**
		 * recall_candidate() degrades to null (never throws) for missing or
		 * malformed stored meta.
		 */
		public function test_recall_candidate_is_null_for_missing_or_invalid_meta(): void {
			$store = new Popular_Settlement_Store( new \Popular_Settlement_Store_Fake_Wpdb() );
			$order = \Mockery::mock( '\WC_Order' );
			$order->shouldReceive( 'get_id' )->andReturn( 42 );

			Functions\when( 'get_post_meta' )->justReturn( '' );
			$this->assertNull( $store->recall_candidate( $order ), 'Missing meta must be null.' );

			Functions\when( 'get_post_meta' )->justReturn( 'not json {' );
			$this->assertNull( $store->recall_candidate( $order ), 'Malformed JSON must be null, not throw.' );

			Functions\when( 'get_post_meta' )->justReturn( json_encode( [ 'level' => 'settlement' ] ) );
			$this->assertNull( $store->recall_candidate( $order ), 'A shape Location_Record::from_array() rejects must be null, not throw.' );
		}

		/**
		 * Schema/install smoke test (round 2, MEDIUM 4: "nothing exercises install()
		 * / the table lifecycle at all"). A live dbDelta()/MySQL run is integration-
		 * test territory (needs wp-env, not available to this Brain Monkey unit
		 * suite) — this proves at the unit level that the DDL install() hands to
		 * dbDelta() actually carries the MEDIUM 5 fix (a real `locality_key` column
		 * and its unique key), via a stubbed global dbDelta() that records what it
		 * was called with.
		 */
		public function test_install_hands_dbdelta_a_schema_with_the_locality_key_unique_constraint(): void {
			unset( $GLOBALS['popular_settlement_store_test_last_dbdelta_sql'] );

			$store = new Popular_Settlement_Store( new \Popular_Settlement_Store_Fake_Wpdb() );
			$store->install();

			$sql = $GLOBALS['popular_settlement_store_test_last_dbdelta_sql'] ?? null;

			$this->assertNotNull( $sql, 'install() must call dbDelta().' );
			$this->assertStringContainsString( 'locality_key', $sql );
			$this->assertStringContainsString( 'UNIQUE KEY provider_locality (provider_id, locality_key)', $sql );
		}
	}
}
