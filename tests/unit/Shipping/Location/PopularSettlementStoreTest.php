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
 * Round 3 (MEDIUM 4): the upsert simulation now parses the `ON DUPLICATE KEY
 * UPDATE` clause's own assignments (and the INSERT column list) instead of
 * hard-coding "bump order_count/last_ordered_at only" — the round-2 fake got
 * the WHERE clause right but still hard-coded the UPDATE semantics, so it would
 * have stayed green even if production started also overwriting `record`,
 * `created_at` or `last_verified_at` on a bump; see
 * {@see \Popular_Settlement_Store_Fake_Wpdb::apply_upsert()}.
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

		/** @var array<int,array{table: string, data: array<string,mixed>, where: array<string,mixed>}> recorded update() calls */
		public array $updates = [];

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
		 * unique key: an existing row is updated with EXACTLY the columns/expressions
		 * the `ON DUPLICATE KEY UPDATE` clause TEXT names (round 3, MEDIUM 4 — see
		 * {@see self::apply_upsert()}'s own docblock), a new key inserts a fresh row.
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

			$before = count( $this->rows );

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

			return $before - count( $this->rows );
		}

		/**
		 * Records an update() call and actually applies it to every matching row —
		 * mirrors {@see self::delete()}'s own "genuinely apply the $where" fix
		 * (round 2 critic finding) rather than a no-op spy.
		 *
		 * @param string               $table        table name (ignored)
		 * @param array<string,mixed>  $data         column => new value
		 * @param array<string,mixed>  $where        column => value, ALL of which must match
		 * @param array<int,string>|null $format     column formats (ignored)
		 * @param array<int,string>|null $where_format where-clause formats (ignored)
		 * @return int number of rows updated
		 */
		public function update( $table, $data, $where, $format = null, $where_format = null ) {
			$this->updates[] = [
				'table' => $table,
				'data'  => $data,
				'where' => $where,
			];

			$updated = 0;

			foreach ( $this->rows as &$row ) {
				foreach ( $where as $column => $value ) {
					if ( (string) ( $row[ $column ] ?? null ) !== (string) $value ) {
						continue 2;
					}
				}

				foreach ( $data as $column => $value ) {
					$row[ $column ] = $value;
				}

				++$updated;
			}
			unset( $row );

			return $updated;
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
		 * Applies the atomic upsert to {@see self::$rows}, GENUINELY driven by the
		 * `ON DUPLICATE KEY UPDATE` clause TEXT (round 3, MEDIUM 4 — the round-2 fake
		 * this replaces hard-coded "bump order_count/last_ordered_at only", so it
		 * would have stayed green even if production started also overwriting
		 * `record`/`created_at`/`last_verified_at` on a bump). On a match, this
		 * parses each `` `col` = expr`` assignment out of the UPDATE clause and
		 * applies EXACTLY that — an unrecognised expression fails loudly (via
		 * `self::fail()`-worthy `RuntimeException`) rather than silently doing
		 * nothing, so a new expression shape this fake does not yet understand is
		 * caught, not masked. On no match, inserts a fresh row from the INSERT
		 * clause's own column list + bound values, so adding/reordering a column
		 * there is honoured too, not assumed by positional destructuring.
		 *
		 * @return void
		 */
		private function apply_upsert(): void {
			$sql = (string) $this->last_query;

			preg_match( '/INSERT INTO `[^`]+` \(([^)]+)\)\s*VALUES\s*\(([^)]+)\)/is', $sql, $insert_matches );
			$insert_columns = array_map(
				static fn( string $col ): string => trim( $col, " `\t\n\r\0\x0B" ),
				explode( ',', $insert_matches[1] )
			);
			$value_tokens = array_map( 'trim', explode( ',', $insert_matches[2] ) );

			$arg_index     = 0;
			$insert_values = [];
			foreach ( $insert_columns as $i => $column ) {
				$token = $value_tokens[ $i ];

				if ( 'NULL' === strtoupper( $token ) ) {
					$insert_values[ $column ] = null;
				} elseif ( '%s' === $token || '%d' === $token ) {
					$insert_values[ $column ] = $this->last_args[ $arg_index++ ];
				} else {
					$insert_values[ $column ] = is_numeric( $token ) ? $token : trim( $token, "'" );
				}
			}

			foreach ( $this->rows as &$row ) {
				if ( ( $row['provider_id'] ?? null ) !== $insert_values['provider_id']
					|| ( $row['locality_key'] ?? null ) !== $insert_values['locality_key']
				) {
					continue;
				}

				preg_match( '/ON DUPLICATE KEY UPDATE\s*(.+)$/is', $sql, $update_matches );

				foreach ( explode( ',', $update_matches[1] ) as $assignment ) {
					if ( ! preg_match( '/`(\w+)`\s*=\s*(.+)$/s', trim( $assignment ), $assignment_matches ) ) {
						throw new \RuntimeException( 'Unrecognised ON DUPLICATE KEY UPDATE assignment: ' . $assignment );
					}

					[ , $target_column, $expr ] = $assignment_matches;
					$expr                       = trim( $expr );

					if ( preg_match( '/^`(\w+)`\s*\+\s*(\d+)$/', $expr, $increment_matches ) ) {
						$row[ $target_column ] = ( (int) ( $row[ $increment_matches[1] ] ?? 0 ) ) + (int) $increment_matches[2];
					} elseif ( preg_match( '/^VALUES\(`(\w+)`\)$/', $expr, $values_matches ) ) {
						$row[ $target_column ] = $insert_values[ $values_matches[1] ];
					} else {
						throw new \RuntimeException( 'Unrecognised ON DUPLICATE KEY UPDATE expression: ' . $expr );
					}
				}

				return;
			}
			unset( $row );

			$this->rows[] = array_merge( [ 'id' => (string) $this->next_id++ ], $insert_values );
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
		 * Round 3 (MEDIUM 4): a bump must leave `record`, `country` and `created_at`
		 * untouched — the real `ON DUPLICATE KEY UPDATE` clause only ever names
		 * `order_count` and `last_ordered_at`. Driven through the fake's genuine SQL
		 * parsing (see {@see \Popular_Settlement_Store_Fake_Wpdb::apply_upsert()}):
		 * the SECOND enrolment carries a DIFFERENT record payload (same key) so this
		 * would fail, not merely stay green, if production ever started applying
		 * `VALUES(`record`)`/`VALUES(`created_at`)` on a bump.
		 */
		public function test_a_bump_leaves_record_and_created_at_untouched(): void {
			$wpdb     = new \Popular_Settlement_Store_Fake_Wpdb();
			$store    = new Popular_Settlement_Store( $wpdb );
			$provider = new \Popular_Settlement_Resolving_Fixture_Provider();

			$original = Location_Record::from_array(
				[
					'key'         => $provider->get_id() . ':1',
					'provider_id' => $provider->get_id(),
					'level'       => Location_Record::LEVEL_SETTLEMENT,
					'country'     => 'RU',
					'label'       => 'Original',
				]
			);
			$changed  = Location_Record::from_array(
				[
					'key'         => $provider->get_id() . ':1',
					'provider_id' => $provider->get_id(),
					'level'       => Location_Record::LEVEL_SETTLEMENT,
					'country'     => 'RU',
					'label'       => 'Changed',
				]
			);

			$store->enroll( $provider, $original );
			$created_at_after_first = $wpdb->rows[0]['created_at'];

			$store->enroll( $provider, $changed );

			$this->assertCount( 1, $wpdb->rows, 'A bump must never create a second row.' );
			$this->assertSame(
				$original->to_array(),
				json_decode( (string) $wpdb->rows[0]['record'], true ),
				'A bump must leave the stored record untouched — only verification (D5/D6) ever overwrites it in place.'
			);
			$this->assertSame( $created_at_after_first, $wpdb->rows[0]['created_at'], 'created_at must never change on a bump.' );
			$this->assertNull( $wpdb->rows[0]['last_verified_at'], 'last_verified_at is only ever written by verification, never enrolment.' );
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

		/**
		 * find_entry_by_key() (#488 slice 3, D5): the same indexed lookup
		 * find_row_by_key() already performs, wrapped as a public Entry-returning
		 * accessor. Absent for a foreign provider_id or an unknown key — never a
		 * scan of every row for the provider.
		 */
		public function test_find_entry_by_key_finds_or_misses(): void {
			$provider = new \Popular_Settlement_Resolving_Fixture_Provider();
			$record   = $this->record( $provider->get_id(), '1' );

			$wpdb       = new \Popular_Settlement_Store_Fake_Wpdb();
			$wpdb->rows = [ $this->row( $record, 5, '2026-08-24 12:00:00', 7 ) ];
			$store      = new Popular_Settlement_Store( $wpdb );

			$found = $store->find_entry_by_key( $provider->get_id(), $record->key() );
			$this->assertNotNull( $found );
			$this->assertSame( 7, $found->id() );
			$this->assertSame( $record->key(), $found->record()->key() );

			$this->assertNull( $store->find_entry_by_key( $provider->get_id(), 'no-such-key' ) );
			$this->assertNull( $store->find_entry_by_key( 'some-other-provider', $record->key() ) );
		}

		/**
		 * touch_verified() (spec D6 "alive, unchanged"): bumps ONLY
		 * `last_verified_at` — `record`, `order_count` and `last_ordered_at` are
		 * left completely untouched.
		 */
		public function test_touch_verified_bumps_only_last_verified_at(): void {
			$provider = new \Popular_Settlement_Resolving_Fixture_Provider();
			$record   = $this->record( $provider->get_id(), '1' );

			$wpdb       = new \Popular_Settlement_Store_Fake_Wpdb();
			$wpdb->rows = [ $this->row( $record, 5, '2026-08-01 00:00:00', 7 ) ];
			$store      = new Popular_Settlement_Store( $wpdb );

			$store->touch_verified( 7, 1787572800 );

			$this->assertCount( 1, $wpdb->rows );
			$this->assertSame( '2026-08-24 12:00:00', $wpdb->rows[0]['last_verified_at'] );
			$this->assertSame( '5', $wpdb->rows[0]['order_count'], 'order_count must be untouched.' );
			$this->assertSame( '2026-08-01 00:00:00', $wpdb->rows[0]['last_ordered_at'], 'last_ordered_at must be untouched.' );
			$this->assertSame(
				json_encode( $record->to_array() ),
				$wpdb->rows[0]['record'],
				'record must be untouched.'
			);
		}

		/**
		 * replace_record() (spec D6 "alive, changed", incl. a changed key):
		 * overwrites `record`/`locality_key`/`country`, bumps `last_verified_at`,
		 * and leaves `order_count`/`last_ordered_at` — the usage clock (D2), a
		 * different axis — completely untouched. The row keeps its surrogate id.
		 */
		public function test_replace_record_overwrites_record_and_key_leaves_usage_clock_alone(): void {
			$provider = new \Popular_Settlement_Resolving_Fixture_Provider();
			$original = $this->record( $provider->get_id(), 'old-native-id' );

			$wpdb       = new \Popular_Settlement_Store_Fake_Wpdb();
			$wpdb->rows = [ $this->row( $original, 9, '2026-08-01 00:00:00', 7 ) ];
			$store      = new Popular_Settlement_Store( $wpdb );

			$fresh = Location_Record::from_array(
				[
					'key'         => $provider->get_id() . ':new-native-id',
					'provider_id' => $provider->get_id(),
					'level'       => Location_Record::LEVEL_SETTLEMENT,
					'country'     => 'RU',
					'settlement'  => [ 'name' => 'Renamed', 'type' => 'city' ],
				]
			);

			$store->replace_record( 7, $fresh, 1787572800 );

			$this->assertSame( $fresh->key(), $wpdb->rows[0]['locality_key'], 'The key change must land (D6).' );
			$this->assertSame(
				$fresh->to_array(),
				json_decode( (string) $wpdb->rows[0]['record'], true ),
				'The stored record must be overwritten with the fresh one.'
			);
			$this->assertSame( '2026-08-24 12:00:00', $wpdb->rows[0]['last_verified_at'] );
			$this->assertSame( '9', $wpdb->rows[0]['order_count'], 'order_count must be untouched.' );
			$this->assertSame( '2026-08-01 00:00:00', $wpdb->rows[0]['last_ordered_at'], 'last_ordered_at must be untouched.' );
			$this->assertSame( '7', $wpdb->rows[0]['id'], 'The surrogate id must survive the key change (D3/D6).' );
		}

		/**
		 * delete_entry() (spec D6 "gone"): removes exactly the one row by its
		 * surrogate id.
		 */
		public function test_delete_entry_removes_the_row(): void {
			$provider = new \Popular_Settlement_Resolving_Fixture_Provider();
			$a        = $this->record( $provider->get_id(), 'a' );
			$b        = $this->record( $provider->get_id(), 'b' );

			$wpdb       = new \Popular_Settlement_Store_Fake_Wpdb();
			$wpdb->rows = [
				$this->row( $a, 1, '2026-08-24 12:00:00', 1 ),
				$this->row( $b, 1, '2026-08-24 12:00:00', 2 ),
			];
			$store = new Popular_Settlement_Store( $wpdb );

			$store->delete_entry( 1 );

			$this->assertCount( 1, $wpdb->rows );
			$this->assertSame( '2', $wpdb->rows[0]['id'] );
		}

		/**
		 * clear_provider() (D8's "Очистить список популярных городов"): removes
		 * every row for the given provider, leaves other providers' rows alone,
		 * and returns the number of rows deleted.
		 */
		public function test_clear_provider_removes_only_that_providers_rows(): void {
			$mine    = new \Popular_Settlement_Resolving_Fixture_Provider( 'dadata' );
			$foreign = new \Popular_Settlement_Resolving_Fixture_Provider( 'cdek' );

			$wpdb       = new \Popular_Settlement_Store_Fake_Wpdb();
			$wpdb->rows = [
				$this->row( $this->record( $mine->get_id(), '1' ), 1, '2026-08-24 12:00:00', 1 ),
				$this->row( $this->record( $mine->get_id(), '2' ), 1, '2026-08-24 12:00:00', 2 ),
				$this->row( $this->record( $foreign->get_id(), '1' ), 1, '2026-08-24 12:00:00', 3 ),
			];
			$store = new Popular_Settlement_Store( $wpdb );

			$deleted = $store->clear_provider( $mine->get_id() );

			$this->assertSame( 2, $deleted );
			$this->assertCount( 1, $wpdb->rows );
			$this->assertSame( $foreign->get_id(), $wpdb->rows[0]['provider_id'] );
		}

		/**
		 * is_stale() (spec D2): a never-verified entry (`last_verified_at ===
		 * null`) is stale; a recently-verified one is not; one older than the TTL
		 * is stale again.
		 */
		public function test_is_stale_reflects_the_freshness_clock(): void {
			$store = new Popular_Settlement_Store( new \Popular_Settlement_Store_Fake_Wpdb() );

			$record = $this->record( 'dadata', '1' );

			$never_verified = new \Woodev\Framework\Shipping\Location\Popular_Settlement_Entry(
				1,
				'dadata',
				'RU',
				$record,
				1,
				1756036800,
				null,
				1756036800
			);
			$this->assertTrue( $store->is_stale( $never_verified ), 'last_verified_at === null must be stale.' );

			$fresh = new \Woodev\Framework\Shipping\Location\Popular_Settlement_Entry(
				1,
				'dadata',
				'RU',
				$record,
				1,
				1756036800,
				1756036800 - 100,
				1756036800
			);
			$this->assertFalse(
				$store->is_stale( $fresh, WEEK_IN_SECONDS ),
				'A row verified 100 seconds ago must not be stale against a one-week TTL.'
			);

			$stale = new \Woodev\Framework\Shipping\Location\Popular_Settlement_Entry(
				1,
				'dadata',
				'RU',
				$record,
				1,
				1756036800,
				1756036800 - ( 2 * WEEK_IN_SECONDS ),
				1756036800
			);
			$this->assertTrue(
				$store->is_stale( $stale, WEEK_IN_SECONDS ),
				'A row verified two weeks ago must be stale against a one-week TTL.'
			);
		}

		/**
		 * verify_ttl_seconds() (D2's SECOND clock) is independently filterable
		 * from ttl_seconds() (the usage clock) — the two must never collapse into
		 * one number.
		 */
		public function test_verify_ttl_seconds_is_independently_filterable(): void {
			Functions\when( 'apply_filters' )->alias(
				static function ( $tag, $value ) {
					if ( Popular_Settlement_Store::FILTER_VERIFY_TTL_SECONDS === $tag ) {
						return 12345;
					}

					return $value;
				}
			);

			$this->assertSame( 12345, Popular_Settlement_Store::verify_ttl_seconds() );
			$this->assertSame(
				Popular_Settlement_Store::DEFAULT_TTL_SECONDS,
				Popular_Settlement_Store::ttl_seconds(),
				'The usage-clock TTL must be unaffected by a verify-clock filter override.'
			);
		}
	}
}
