<?php
/**
 * Unit tests for Popular_Settlement_Store — the popular-settlements table (#488
 * slice 2, spec D1-D3), the D4 provider-capability gate, the D4a derived-key gate,
 * the two independent clocks (D2), ranking by order_count, cap eviction, and that a
 * foreign provider_id is never offered.
 *
 * Pure PHP — no WooCommerce or WordPress runtime required; the store is driven
 * through a fake \wpdb double that records the calls it receives and filters reads
 * by the bound provider_id, mirroring the precedent in WarehouseStorageIdTest.php.
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

	// The store constructor type-hints \wpdb; a minimal stub lets the fake below satisfy it.
	if ( ! class_exists( '\wpdb', false ) ) {
		class PopularSettlementStore_Wpdb_Stub {}

		class_alias( PopularSettlementStore_Wpdb_Stub::class, 'wpdb' );
	}

	/**
	 * Records the wpdb calls the store makes so assertions can inspect them.
	 *
	 * `get_results()` filters the preconfigured {@see $rows} by the single bound
	 * `provider_id` argument the store's own `raw_rows_for_provider()` always
	 * passes to `prepare()` — real enough to prove a foreign provider's rows are
	 * never returned, without needing a real database.
	 */
	class Popular_Settlement_Store_Fake_Wpdb extends \wpdb {

		/** @var string table prefix */
		public string $prefix = 'wp_';

		/** @var int insert id returned by the next insert() */
		public int $insert_id = 0;

		/** @var array<int,array<string,mixed>> full preconfigured row set (every provider) */
		public array $rows = [];

		/** @var array<int,array<string,mixed>> recorded insert() calls */
		public array $inserts = [];

		/** @var array<int,array<string,mixed>> recorded update() calls */
		public array $updates = [];

		/** @var array<int,array<string,mixed>> recorded delete() calls */
		public array $deletes = [];

		/** @var string|null the provider_id bound by the last prepare() call */
		private ?string $last_bound_provider_id = null;

		/**
		 * Captures the bound provider_id for the next get_results() to filter by.
		 *
		 * @param string $query   SQL with a single %s placeholder (ignored)
		 * @param mixed  ...$args bound arguments — the store always binds provider_id first
		 * @return string
		 */
		public function prepare( $query, ...$args ) {
			$this->last_bound_provider_id = (string) ( $args[0] ?? '' );

			return $query;
		}

		/**
		 * Returns the preconfigured rows filtered by the last bound provider_id.
		 *
		 * @param string $query  SQL query (ignored)
		 * @param string $output output type (ignored)
		 * @return array<int,array<string,mixed>>
		 */
		public function get_results( $query, $output = OBJECT ) {
			return array_values(
				array_filter(
					$this->rows,
					function ( array $row ): bool {
						return ( $row['provider_id'] ?? '' ) === $this->last_bound_provider_id;
					}
				)
			);
		}

		/**
		 * Records an insert and reports one affected row.
		 *
		 * @param string              $table table name
		 * @param array<string,mixed> $data  column => value
		 * @return int
		 */
		public function insert( $table, $data ) {
			$this->inserts[] = [
				'table' => $table,
				'data'  => $data,
			];

			return 1;
		}

		/**
		 * Records an update and reports one affected row.
		 *
		 * @param string              $table table name
		 * @param array<string,mixed> $data  column => value
		 * @param array<string,mixed> $where where column => value
		 * @return int
		 */
		public function update( $table, $data, $where ) {
			$this->updates[] = [
				'table' => $table,
				'data'  => $data,
				'where' => $where,
			];

			return 1;
		}

		/**
		 * Records a delete and reports one affected row.
		 *
		 * @param string              $table table name
		 * @param array<string,mixed> $where where column => value
		 * @return int
		 */
		public function delete( $table, $where ) {
			$this->deletes[] = [
				'table' => $table,
				'where' => $where,
			];

			return 1;
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
		}

		/**
		 * Builds a settlement-level record for a given provider/native id.
		 *
		 * @param string $provider_id
		 * @param string $native_id
		 * @return Location_Record
		 */
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
		 * Builds a raw stored row for a given record + counters.
		 *
		 * @param Location_Record $record
		 * @param int             $order_count
		 * @param string|null     $last_ordered_at
		 * @return array<string,mixed>
		 */
		private function row( Location_Record $record, int $order_count = 1, ?string $last_ordered_at = '2026-08-24 12:00:00', int $id = 1 ): array {
			return [
				'id'                => (string) $id,
				'provider_id'       => $record->provider_id(),
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

			$this->assertCount( 0, $wpdb->inserts, 'A derived key must never be inserted.' );
			$this->assertCount( 0, $wpdb->updates, 'A derived key must never be bumped either.' );
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

			$this->assertCount( 0, $wpdb->inserts );
			$this->assertCount( 0, $wpdb->updates );
		}

		/**
		 * A genuinely new, eligible settlement is inserted with order_count = 1,
		 * last_ordered_at stamped, and last_verified_at left null (D2: only
		 * verification, a later slice, ever writes that column).
		 */
		public function test_a_new_eligible_settlement_is_inserted_once(): void {
			$wpdb     = new \Popular_Settlement_Store_Fake_Wpdb();
			$store    = new Popular_Settlement_Store( $wpdb );
			$provider = new \Popular_Settlement_Resolving_Fixture_Provider();
			$record   = $this->record( $provider->get_id(), '1' );

			$store->enroll( $provider, $record );

			$this->assertCount( 1, $wpdb->inserts );
			$this->assertCount( 0, $wpdb->updates );

			$data = $wpdb->inserts[0]['data'];

			$this->assertSame( 1, $data['order_count'] );
			$this->assertSame( '2026-08-24 12:00:00', $data['last_ordered_at'] );
			$this->assertNull( $data['last_verified_at'], 'last_verified_at is only ever written by verification (D5/D6), never enrolment.' );
		}

		/**
		 * D2: enrolling an already-enrolled settlement bumps order_count and
		 * last_ordered_at ONLY — last_verified_at is not part of the update at all,
		 * proving the two clocks move independently.
		 */
		public function test_enrolling_an_existing_settlement_bumps_the_usage_clock_only(): void {
			$provider = new \Popular_Settlement_Resolving_Fixture_Provider();
			$record   = $this->record( $provider->get_id(), '1' );

			$wpdb       = new \Popular_Settlement_Store_Fake_Wpdb();
			$wpdb->rows = [ $this->row( $record, 4, '2026-01-01 00:00:00', 9 ) ];
			$store      = new Popular_Settlement_Store( $wpdb );

			$store->enroll( $provider, $record );

			$this->assertCount( 0, $wpdb->inserts );
			$this->assertCount( 1, $wpdb->updates );

			$update = $wpdb->updates[0];

			$this->assertSame( [ 'id' => 9 ], $update['where'] );
			$this->assertSame( 5, $update['data']['order_count'], 'order_count must increment by exactly one.' );
			$this->assertSame( '2026-08-24 12:00:00', $update['data']['last_ordered_at'] );
			$this->assertArrayNotHasKey( 'last_verified_at', $update['data'], 'A bump must never touch the freshness clock.' );
			$this->assertArrayNotHasKey( 'record', $update['data'], 'A bump must never overwrite the stored record — only verification does that (D6).' );
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
		 * never returned — the store's own read query already scopes to provider_id,
		 * so a foreign provider's rows never surface.
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
		 * axis from ranking).
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
			$this->assertCount( 1, $wpdb->inserts, 'The new settlement must still be inserted after eviction.' );
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
	}
}
