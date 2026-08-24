<?php
/**
 * Woodev Popular Settlement Store
 *
 * Framework-owned table backing the popular-settlements feature (#488): the shop's
 * ~20-30 most-shipped-to settlements, stored whole (spec D1), ranked by order count,
 * and evicted by a usage-recency TTL. One shared table across every shipping plugin
 * on the site — `provider_id` is part of every row (spec D3) so switching the active
 * provider never surfaces another provider's entries.
 *
 * A NEW framework mechanism, not an existing data contract — see
 * {@see \Woodev\Framework\Shipping\Pickup\Abstract_Warehouse_Store}, the precedent
 * this mirrors (dbDelta-backed table, own install path). Unlike that class this one
 * is concrete, not abstract: the schema is fixed by spec D3, not plugin-owned. Unlike
 * that class, this one also does NOT bind `$wpdb` eagerly in its constructor (round 2
 * critic finding, HIGH 1) — `new self()` is always cheap/safe (e.g. to register a
 * lazy accessor on {@see Location_Provider_Registry} at hook-registration time); the
 * global is only ever touched by {@see self::wpdb()}, lazily, on first real DB access.
 *
 * This slice stores D1-D3 and enforces the D4/D4a enrolment gates. Lazy verification
 * (D5/D6), the customer-facing miss (D7) and the two merchant admin actions (D8) are
 * a later slice — see docs-internal/specs/2026-08-24-popular-settlements-design.md.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Location;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Location\\Popular_Settlement_Store' ) ) :

	/**
	 * CRUD + ranking/eviction over the popular-settlements table.
	 *
	 * @since 2.0.2
	 */
	class Popular_Settlement_Store {

		/**
		 * Default cap on the number of live rows kept per provider. Calibration,
		 * not design (spec: "Numbers, deliberately not invented here") — set
		 * generously; a site can override via {@see self::FILTER_LIST_CAP}.
		 *
		 * @since 2.0.2
		 * @var int
		 */
		public const DEFAULT_LIST_CAP = 30;

		/**
		 * Filter tag overriding {@see self::DEFAULT_LIST_CAP}.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const FILTER_LIST_CAP = 'woodev_location_popular_settlement_list_cap';

		/**
		 * Default usage-clock TTL, in seconds: the operator floated ~2 months as a
		 * starting point (spec: "Numbers, deliberately not invented here"). A site
		 * can override via {@see self::FILTER_TTL_SECONDS}.
		 *
		 * @since 2.0.2
		 * @var int
		 */
		public const DEFAULT_TTL_SECONDS = 2 * MONTH_IN_SECONDS;

		/**
		 * Filter tag overriding {@see self::DEFAULT_TTL_SECONDS}.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const FILTER_TTL_SECONDS = 'woodev_location_popular_settlement_ttl_seconds';

		/**
		 * Order-meta key a framework listener stamps with the settlement the
		 * customer picked at checkout ({@see self::remember_candidate()}), read back
		 * by an enrolment caller via {@see self::recall_candidate()} when it does not
		 * already know the settlement. A framework-owned key (not per-plugin) — this
		 * is the SAME breadcrumb regardless of which carrier plugin later exports the
		 * order, so it is written once, by the framework, not by each carrier.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const ORDER_META_KEY = '_woodev_popular_settlement_candidate';

		/**
		 * Injected or lazily-resolved database layer. Deliberately nullable and
		 * touched by nothing but {@see self::wpdb()} — see the class docblock.
		 *
		 * @var \wpdb|null
		 */
		private ?\wpdb $wpdb;

		/**
		 * Constructor.
		 *
		 * Does NOT touch the global `$wpdb` when `$wpdb` is omitted — construction is
		 * always cheap and safe; {@see self::wpdb()} resolves the global lazily, on
		 * first real use.
		 *
		 * @since 2.0.2
		 *
		 * @param \wpdb|null $wpdb database layer; when omitted, resolved lazily from the global on first use
		 */
		public function __construct( ?\wpdb $wpdb = null ) {
			$this->wpdb = $wpdb;
		}

		/**
		 * Resolves the database layer, lazily binding the global `$wpdb` on first
		 * call when none was injected.
		 *
		 * This is the ONLY place the global is touched (round 2 critic finding,
		 * HIGH 1: a constructor that grabs `global $wpdb` eagerly makes the class
		 * unconstructable wherever the global is absent or the wrong type — which is
		 * exactly what broke ~150 unrelated unit tests when installation was wired
		 * synchronously into a widely-shared hook in round 1). Deferring the touch to
		 * here means `new self()` is always safe, and only actual DB-touching calls
		 * (`install()`, `enroll()`, `all_for_provider()`, …) ever require a real
		 * `\wpdb`.
		 *
		 * @since 2.0.2
		 *
		 * @return \wpdb
		 */
		private function wpdb(): \wpdb {
			if ( null === $this->wpdb ) {
				global $wpdb;

				$this->wpdb = $wpdb;
			}

			return $this->wpdb;
		}

		/**
		 * Gets the filtered list cap.
		 *
		 * @since 2.0.2
		 *
		 * @return int
		 */
		public static function list_cap(): int {
			return (int) apply_filters( self::FILTER_LIST_CAP, self::DEFAULT_LIST_CAP );
		}

		/**
		 * Gets the filtered usage-clock TTL, in seconds.
		 *
		 * @since 2.0.2
		 *
		 * @return int
		 */
		public static function ttl_seconds(): int {
			return (int) apply_filters( self::FILTER_TTL_SECONDS, self::DEFAULT_TTL_SECONDS );
		}

		/**
		 * Gets the fully-qualified (prefixed) table name.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		protected function get_table_name(): string {
			return $this->wpdb()->prefix . 'woodev_popular_settlements';
		}

		/**
		 * Gets the `CREATE TABLE` statement handed to `dbDelta()` by {@see self::install()}.
		 *
		 * `id` is a surrogate primary key, deliberately NOT the provider's locality
		 * key (spec D3/D6: the provider key can change under a row without losing its
		 * identity). `locality_key` is a SECONDARY, indexed column carrying the
		 * CURRENT key — added in round 2 (critic finding, MEDIUM 5) so `(provider_id,
		 * locality_key)` can be a durable UNIQUE constraint: two concurrent orders for
		 * the same settlement must converge to ONE row with a correctly summed
		 * `order_count`, not two rows or a lost increment. A surrogate `id` primary
		 * key and a secondary unique key are compatible — D6 (a later slice) updates
		 * `locality_key` in place when verification learns the provider's key changed,
		 * exactly like it already updates `record`.
		 *
		 * `record` carries the whole serialized {@see Location_Record} (spec D1).
		 * `last_ordered_at` and `last_verified_at` are separate columns driven by
		 * different events (spec D2) — this slice only ever writes the former; the
		 * latter's column exists but its writer arrives with lazy verification (D5/D6).
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Added `locality_key` + a `(provider_id, locality_key)` unique
		 *              key (round 2, MEDIUM 5) so a concurrent enrolment race cannot
		 *              duplicate a settlement or lose a count.
		 *
		 * @return string
		 */
		protected function get_schema(): string {
			$table           = $this->get_table_name();
			$charset_collate = $this->wpdb()->get_charset_collate();

			return "CREATE TABLE `{$table}` (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider_id VARCHAR(191) NOT NULL,
  locality_key VARCHAR(191) NOT NULL,
  country VARCHAR(2) NOT NULL,
  record LONGTEXT NOT NULL,
  order_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_ordered_at DATETIME NULL DEFAULT NULL,
  last_verified_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY provider_locality (provider_id, locality_key),
  KEY last_ordered_at (last_ordered_at)
) {$charset_collate};";
		}

		/**
		 * Creates or migrates the backing table from {@see self::get_schema()}.
		 *
		 * A new framework mechanism, not an existing contract — see
		 * {@see \Woodev\Framework\Shipping\Pickup\Abstract_Warehouse_Store::install()},
		 * the precedent this mirrors. Idempotent (`dbDelta()`); callers are expected
		 * to gate how often this actually runs (see
		 * {@see Location_Provider_Registry::maybe_install_popular_settlements_table()}),
		 * not to call it on every request.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function install(): void {
			if ( ! function_exists( 'dbDelta' ) ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			}

			dbDelta( $this->get_schema() );
		}

		/**
		 * Stamps the order with the settlement the customer picked at checkout, so a
		 * later enrolment caller (e.g. {@see \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler::export()}
		 * via its caller) can find it without needing a live customer session — see
		 * {@see self::ORDER_META_KEY}.
		 *
		 * This does NOT enrol anything by itself — it is a cheap breadcrumb write,
		 * not a table write, and is safe to call for every order regardless of
		 * whether the active provider even has the D4 capability (that gate lives in
		 * {@see self::enroll()}, checked once, at the point of an actual table write).
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order       $order  The order to stamp.
		 * @param Location_Record $record The settlement the customer picked.
		 *
		 * @return void
		 */
		public function remember_candidate( \WC_Order $order, Location_Record $record ): void {
			\Woodev_Order_Compatibility::update_order_meta( $order, self::ORDER_META_KEY, wp_json_encode( $record->to_array() ) );
		}

		/**
		 * Reads back the settlement {@see self::remember_candidate()} stamped onto an
		 * order, or null when none was stamped or the stored value is unusable.
		 *
		 * Deliberately tolerant: a missing, non-JSON, or now-invalid (e.g. an older
		 * shape) stored value degrades to null rather than throwing — a caller
		 * resolving this as a default for enrolment must never be able to crash an
		 * otherwise-successful export over stale breadcrumb data.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order The order to read from.
		 *
		 * @return Location_Record|null
		 */
		public function recall_candidate( \WC_Order $order ): ?Location_Record {
			$raw = \Woodev_Order_Compatibility::get_order_meta( $order, self::ORDER_META_KEY );

			if ( ! is_string( $raw ) || '' === $raw ) {
				return null;
			}

			$decoded = json_decode( $raw, true );

			if ( ! is_array( $decoded ) ) {
				return null;
			}

			try {
				return Location_Record::from_array( $decoded );
			} catch ( \InvalidArgumentException $exception ) {
				return null;
			}
		}

		/**
		 * Enrols a settlement into the popular list, or bumps its usage if already
		 * enrolled — the path that fires when an order ships to a settlement
		 * (popular-settlements spec D2).
		 *
		 * Two gates, both silent no-ops (never an exception — a caller exports an
		 * order regardless of whether this feature is even in use):
		 *
		 * - D4: `$provider` must declare {@see Location_Provider::CAPABILITY_RESOLVE_KEY}.
		 *   A provider that cannot resolve by key gets no popular list at all — nothing
		 *   is ever written for it.
		 * - D4a (operator, #491): `$record`'s key must not be
		 *   {@see Locality_Key::is_derived()}. A derived key can never be resolved
		 *   again, so the freshness clock could never tick for it — such a record is
		 *   never enrolled, not enrolled-and-exempted.
		 *
		 * The actual write is a SINGLE atomic `INSERT … ON DUPLICATE KEY UPDATE`
		 * against the `(provider_id, locality_key)` unique key (round 2, MEDIUM 5) —
		 * not a read-then-branch. Two concurrent orders for the same settlement race
		 * at the database engine, not in PHP: MySQL serializes them onto one row and
		 * `order_count` still ends up correctly incremented by both, because the
		 * increment is expressed as `order_count = order_count + 1` inside the SAME
		 * statement the engine already serializes, not as a value computed from a
		 * PHP-side read that could go stale between read and write. The stored
		 * `record` is left untouched on a bump — only verification (D5/D6, a later
		 * slice) ever overwrites it in place.
		 *
		 * A genuinely new settlement (checked via a plain read — see
		 * {@see self::find_row_by_key()}) past the per-provider cap (spec: ~20-30
		 * rows, {@see self::FILTER_LIST_CAP}) evicts the least recently ordered row
		 * first (ranking and eviction are deliberately different axes: `order_count`
		 * orders the list, `last_ordered_at` decides who leaves it). That read-based
		 * cap check is NOT itself race-free — under heavy concurrency the cap can be
		 * exceeded by a row or two before the next enrolment/sweep corrects it — but
		 * this is a soft bound on the calibration number, not the correctness the
		 * unique key protects (no duplicate settlement row, no lost count).
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Round 2 (MEDIUM 5): replaced the read-then-insert-or-update
		 *              pair with a single atomic upsert against a new
		 *              `(provider_id, locality_key)` unique key.
		 *
		 * @param Location_Provider $provider The provider that produced `$record` — checked for D4.
		 * @param Location_Record   $record   The settlement to enrol.
		 *
		 * @return void
		 *
		 * @throws \InvalidArgumentException When `$record`'s own `provider_id()` does not match `$provider->get_id()`.
		 */
		public function enroll( Location_Provider $provider, Location_Record $record ): void {
			if ( $record->provider_id() !== $provider->get_id() ) {
				throw new \InvalidArgumentException(
					sprintf(
						'Popular_Settlement_Store::enroll(): record provider_id ("%s") does not match the given provider ("%s").',
						$record->provider_id(),
						$provider->get_id()
					)
				);
			}

			if ( ! in_array( Location_Provider::CAPABILITY_RESOLVE_KEY, $provider->get_capabilities(), true ) ) {
				return; // D4: no popular list at all for a provider that cannot resolve by key.
			}

			if ( Locality_Key::is_derived( $record->key() ) ) {
				return; // D4a: a derived key can never be resolved again, so it is never enrolled.
			}

			if ( null === $this->find_row_by_key( $record->provider_id(), $record->key() ) ) {
				$this->evict_if_over_cap( $record->provider_id() );
			}

			$now   = current_time( 'mysql' );
			$table = $this->get_table_name();

			$this->wpdb()->query(
				$this->wpdb()->prepare(
					"INSERT INTO `{$table}` (`provider_id`, `locality_key`, `country`, `record`, `order_count`, `last_ordered_at`, `last_verified_at`, `created_at`)
					 VALUES (%s, %s, %s, %s, 1, %s, NULL, %s)
					 ON DUPLICATE KEY UPDATE `order_count` = `order_count` + 1, `last_ordered_at` = VALUES(`last_ordered_at`)",
					$record->provider_id(),
					$record->key(),
					$record->country(),
					wp_json_encode( $record->to_array() ),
					$now,
					$now
				)
			);
		}

		/**
		 * Gets every stored entry for a provider, ranked by `order_count` descending
		 * (spec: "order_count orders the list").
		 *
		 * @since 2.0.2
		 *
		 * @param string $provider_id The provider whose entries to read.
		 *
		 * @return Popular_Settlement_Entry[]
		 */
		public function all_for_provider( string $provider_id ): array {
			$entries = array_map( [ $this, 'entry_from_row' ], $this->raw_rows_for_provider( $provider_id ) );

			usort(
				$entries,
				static function ( Popular_Settlement_Entry $a, Popular_Settlement_Entry $b ): int {
					return $b->order_count() <=> $a->order_count();
				}
			);

			return $entries;
		}

		/**
		 * Deletes every row for a provider whose usage clock has passed the TTL
		 * (spec D2/D5: "Cron: cleanup only — never resolve_key()"). This method is
		 * the cleanup primitive itself; wiring it to an actual schedule is a later
		 * slice (D5 is explicitly out of scope here).
		 *
		 * A never-ordered row (should not normally occur — {@see self::enroll()}
		 * always sets `last_ordered_at` on insert) is treated as expired.
		 *
		 * @since 2.0.2
		 *
		 * @param string   $provider_id The provider whose entries to sweep.
		 * @param int|null $ttl_seconds TTL override, in seconds; defaults to {@see self::ttl_seconds()}.
		 *
		 * @return int Number of rows deleted.
		 */
		public function evict_expired( string $provider_id, ?int $ttl_seconds = null ): int {
			$threshold = current_time( 'timestamp' ) - ( $ttl_seconds ?? self::ttl_seconds() );
			$deleted   = 0;

			foreach ( $this->raw_rows_for_provider( $provider_id ) as $row ) {
				$last_ordered_at = $this->to_timestamp( $row['last_ordered_at'] ?? null );

				if ( null === $last_ordered_at || $last_ordered_at < $threshold ) {
					$this->wpdb()->delete( $this->get_table_name(), [ 'id' => (int) $row['id'] ] );
					++$deleted;
				}
			}

			return $deleted;
		}

		/**
		 * Finds the raw row for a provider's settlement by locality key, or null.
		 *
		 * A direct indexed lookup against the `(provider_id, locality_key)` unique key
		 * (round 2 — previously a full scan+decode of every row for the provider).
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Round 2: indexed lookup instead of scan+decode, now that
		 *              `locality_key` is a real column.
		 *
		 * @param string $provider_id The owning provider's id.
		 * @param string $key         The locality key to find.
		 *
		 * @return array<string, mixed>|null
		 */
		private function find_row_by_key( string $provider_id, string $key ): ?array {
			$row = $this->wpdb()->get_row(
				$this->wpdb()->prepare(
					'SELECT * FROM `' . $this->get_table_name() . '` WHERE `provider_id` = %s AND `locality_key` = %s',
					$provider_id,
					$key
				),
				ARRAY_A
			);

			return is_array( $row ) ? $row : null;
		}

		/**
		 * Evicts the least-recently-ordered row for a provider when its live row
		 * count is already at {@see self::list_cap()} — making room for a genuinely
		 * new settlement about to be inserted.
		 *
		 * Deliberately keyed on `last_ordered_at`, not `order_count`: ranking and
		 * eviction are different axes (spec) — a settlement ordered once, long ago,
		 * is evicted before a settlement ordered many times but not recently, even
		 * though the latter might rank lower on a given day.
		 *
		 * @since 2.0.2
		 *
		 * @param string $provider_id The provider about to gain a new row.
		 *
		 * @return void
		 */
		private function evict_if_over_cap( string $provider_id ): void {
			$rows = $this->raw_rows_for_provider( $provider_id );

			if ( count( $rows ) < self::list_cap() ) {
				return;
			}

			usort(
				$rows,
				static function ( array $a, array $b ): int {
					return strcmp( (string) ( $a['last_ordered_at'] ?? '' ), (string) ( $b['last_ordered_at'] ?? '' ) );
				}
			);

			$victim = $rows[0];

			$this->wpdb()->delete( $this->get_table_name(), [ 'id' => (int) $victim['id'] ] );
		}

		/**
		 * Reads every raw row for a provider.
		 *
		 * @since 2.0.2
		 *
		 * @param string $provider_id The provider whose rows to read.
		 *
		 * @return array<int, array<string, mixed>>
		 */
		private function raw_rows_for_provider( string $provider_id ): array {
			$rows = $this->wpdb()->get_results(
				$this->wpdb()->prepare( 'SELECT * FROM `' . $this->get_table_name() . '` WHERE `provider_id` = %s', $provider_id ),
				ARRAY_A
			);

			return is_array( $rows ) ? $rows : [];
		}

		/**
		 * Maps a raw row into a {@see Popular_Settlement_Entry}.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $row Raw database row.
		 *
		 * @return Popular_Settlement_Entry
		 */
		private function entry_from_row( array $row ): Popular_Settlement_Entry {
			return new Popular_Settlement_Entry(
				(int) $row['id'],
				(string) $row['provider_id'],
				(string) $row['country'],
				Location_Record::from_array( json_decode( (string) $row['record'], true ) ),
				(int) $row['order_count'],
				$this->to_timestamp( $row['last_ordered_at'] ?? null ),
				$this->to_timestamp( $row['last_verified_at'] ?? null ),
				(int) $this->to_timestamp( $row['created_at'] ?? null )
			);
		}

		/**
		 * Converts a stored MySQL `DATETIME` value to a unix timestamp, or null when
		 * absent/empty/unparseable.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $value Raw column value.
		 *
		 * @return int|null
		 */
		private function to_timestamp( $value ): ?int {
			if ( null === $value || '' === $value || '0000-00-00 00:00:00' === $value ) {
				return null;
			}

			$timestamp = strtotime( (string) $value );

			return false === $timestamp ? null : $timestamp;
		}
	}

endif;
