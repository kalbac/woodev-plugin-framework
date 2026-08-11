<?php
/**
 * Woodev Pickup Selection
 *
 * The framework's session-backed mechanism behind pickup-selection persistence (SP-5 T5,
 * issue #176). Owns a three-level map — locality, then type, then the remembered point —
 * and nothing else: it never interprets what a locality or a type MEANS, only ever
 * comparing one opaque string produced by a {@see Selection_Scope} to another produced by
 * the SAME scope.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Pickup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Pickup\\Pickup_Selection' ) ) :

	/**
	 * Reads, writes and clears one plugin's pickup-selection map in `WC()->session`.
	 *
	 * Stored shape, under {@see Selection_Scope::session_key()}:
	 *
	 * ```
	 * [ <locality> => [ <type code> => [ 'id' => <point id>, 'seq' => <int> ] ] ]
	 * ```
	 *
	 * `seq` is a monotonic sequence number stamped on every write, INCLUDING an
	 * overwrite of an existing `(locality, type)` entry. This is deliberate, not
	 * incidental: a PHP array does not track insertion order across a re-assignment —
	 * `$map[ $loc ][ $type ] = $id` on a key that already existed keeps that key's
	 * ORIGINAL position, so the customer's most recent choice would look like the
	 * oldest one to anything inferring recency from array order. Both
	 * {@see self::recall_latest()} and the eviction in {@see self::remember()} read
	 * `seq`, never array position.
	 *
	 * @since 2.0.2
	 */
	class Pickup_Selection {

		/**
		 * Default cap on total remembered entries across every locality, used when the
		 * `woodev_pickup_max_remembered_selections` filter is not hooked or returns
		 * something non-numeric.
		 *
		 * Small on purpose: unlike the point POOL a customer accumulates while panning
		 * a map ({@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler}'s own
		 * `woodev_pickup_max_accumulated_points`, which defaults to unbounded because a
		 * realistic session pools roughly 1300 points), a selection entry is written
		 * once per (locality, type) the customer actually CONFIRMS a point in — a
		 * handful of localities visited during one checkout, not a map-panning session.
		 *
		 * @since 2.0.2
		 */
		private const DEFAULT_MAX_ENTRIES = 20;

		/**
		 * The owning plugin's seam — see {@see Selection_Scope} for why the framework
		 * never reads a locality or a type key itself.
		 *
		 * @since 2.0.2
		 * @var Selection_Scope
		 */
		private Selection_Scope $scope;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param Selection_Scope $scope The owning plugin's scope.
		 */
		public function __construct( Selection_Scope $scope ) {
			$this->scope = $scope;
		}

		/**
		 * Remembers a point for a (locality, type) pair, overwriting whatever was
		 * remembered there before.
		 *
		 * A missing session (no WooCommerce, or no session started yet) is a silent
		 * no-op — the same "no scope, no persistence" discipline
		 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::handle_checkout_order_processed()}
		 * already applies to full-point persistence; nothing here may fatal when
		 * WooCommerce is absent.
		 *
		 * @since 2.0.2
		 *
		 * @param string $locality Opaque locality key from {@see Selection_Scope}.
		 * @param string $type     Opaque type code from {@see Selection_Scope}.
		 * @param string $point_id The carrier point id to remember.
		 *
		 * @return void
		 */
		public function remember( string $locality, string $type, string $point_id ): void {
			if ( ! self::is_usable_key( $locality ) || ! self::is_usable_key( $type ) || '' === $point_id ) {
				return;
			}

			$session = $this->session();

			if ( null === $session ) {
				return;
			}

			$map = $this->read_map( $session );

			$map[ $locality ][ $type ] = [
				'id'  => $point_id,
				'seq' => $this->next_sequence( $map ),
			];

			$map = $this->evict_over_cap( $map, $locality, $type );

			$session->set( $this->scope->session_key(), $map );
		}

		/**
		 * Recalls the point id remembered for an exact (locality, type) pair.
		 *
		 * @since 2.0.2
		 *
		 * @param string $locality Opaque locality key from {@see Selection_Scope}.
		 * @param string $type     Opaque type code from {@see Selection_Scope}.
		 *
		 * @return string|null `null` when nothing is remembered for that pair (or
		 *                      WooCommerce/the session is unavailable).
		 */
		public function recall( string $locality, string $type ): ?string {
			if ( ! self::is_usable_key( $locality ) || ! self::is_usable_key( $type ) ) {
				return null;
			}

			$map = $this->read_map( $this->session() );

			$id = $map[ $locality ][ $type ]['id'] ?? null;

			return is_string( $id ) ? $id : null;
		}

		/**
		 * Recalls the most recently remembered point across every type stored for one
		 * locality — the {@see Selection_Scope::TYPE_ANY} fallback.
		 *
		 * "Most recently" means highest `seq`, never array order — see the class
		 * docblock for why array order cannot be trusted here.
		 *
		 * @since 2.0.2
		 *
		 * @param string $locality Opaque locality key from {@see Selection_Scope}.
		 *
		 * @return string|null `null` when the locality has nothing remembered (or
		 *                      WooCommerce/the session is unavailable).
		 */
		public function recall_latest( string $locality ): ?string {
			if ( ! self::is_usable_key( $locality ) ) {
				return null;
			}

			$map     = $this->read_map( $this->session() );
			$entries = $map[ $locality ] ?? [];

			if ( ! is_array( $entries ) || empty( $entries ) ) {
				return null;
			}

			$latest_id  = null;
			$latest_seq = -1;

			foreach ( $entries as $entry ) {
				if ( ! is_array( $entry ) || ! isset( $entry['id'], $entry['seq'] ) ) {
					continue;
				}

				if ( (int) $entry['seq'] > $latest_seq ) {
					$latest_seq = (int) $entry['seq'];
					$latest_id  = is_string( $entry['id'] ) ? $entry['id'] : null;
				}
			}

			return $latest_id;
		}

		/**
		 * Clears the whole map — every locality, every type.
		 *
		 * Called once an order is created; see
		 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::handle_checkout_order_processed()}.
		 * A missing session is a silent no-op, same as {@see self::remember()}.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function forget_all(): void {
			$session = $this->session();

			if ( null === $session ) {
				return;
			}

			$session->set( $this->scope->session_key(), [] );
		}

		/**
		 * Reads the live `WC()->session`, or `null` when WooCommerce is unavailable or
		 * no session has been started yet.
		 *
		 * `protected` as a test seam — mirrors
		 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::wc_session_chosen_shipping_methods()}'s
		 * own shape and reasoning: a probe overrides this single line rather than
		 * `WC()` needing to be a real function in the unit-test process.
		 *
		 * @since 2.0.2
		 *
		 * @return \WC_Session|null
		 */
		protected function session() {
			if ( ! function_exists( 'WC' ) || ! WC()->session ) {
				return null;
			}

			return WC()->session;
		}

		/**
		 * Reads and shape-guards the stored map.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Session|null $session The session to read from, or `null`.
		 *
		 * @return array<string, array<string, array{id: string, seq: int}>>
		 */
		private function read_map( $session ): array {
			if ( null === $session ) {
				return [];
			}

			$map = $session->get( $this->scope->session_key() );

			return is_array( $map ) ? $map : [];
		}

		/**
		 * Reports whether a key a {@see Selection_Scope} handed back can be used as one.
		 *
		 * The framework never INTERPRETS a locality or a type — that is the whole point of
		 * the seam — but `''` is not a value in that vocabulary, it is the scope failing to
		 * name one: a point whose locality the plugin cannot map, or a
		 * {@see Selection_Scope::current_locality()} asked before WooCommerce could answer.
		 * The same reading `''` already has for a shipping-method id on
		 * {@see Selection_Scope::type_for_method()} and on
		 * `woodev_shipping_pickup_point_selection`'s `$context['method_id']`.
		 *
		 * Treating it as an ordinary key is not merely untidy, it restores the WRONG POINT:
		 * every unnameable locality collapses into one shared `''` bucket, and a later
		 * `current_locality()` that also cannot answer would then recall a point belonging
		 * to some other locality entirely. Refusing it on both the read and the write side
		 * is what makes that unreachable rather than unlikely.
		 *
		 * @since 2.0.2
		 *
		 * @param string $key A locality key or a type code from the scope.
		 *
		 * @return bool
		 */
		private static function is_usable_key( string $key ): bool {
			return '' !== $key;
		}

		/**
		 * Computes the next monotonic sequence number for a write — one past the
		 * highest `seq` anywhere in the map, so an overwrite of an existing entry is
		 * always the newest, never the position it already had.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, array<string, array{id: string, seq: int}>> $map The map
		 *                                                                       as read
		 *                                                                       before
		 *                                                                       this write.
		 *
		 * @return int
		 */
		private function next_sequence( array $map ): int {
			$max = 0;

			foreach ( $map as $types ) {
				if ( ! is_array( $types ) ) {
					continue;
				}

				foreach ( $types as $entry ) {
					if ( is_array( $entry ) && isset( $entry['seq'] ) && (int) $entry['seq'] > $max ) {
						$max = (int) $entry['seq'];
					}
				}
			}

			return $max + 1;
		}

		/**
		 * Evicts the oldest entries (lowest `seq`) once the map exceeds the configured
		 * cap, never evicting the entry that was just written.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, array<string, array{id: string, seq: int}>> $map           The
		 *                                                                                  map,
		 *                                                                                  already
		 *                                                                                  containing
		 *                                                                                  the
		 *                                                                                  just-written
		 *                                                                                  entry.
		 * @param string                                                    $just_written_locality Locality of the entry just written.
		 * @param string                                                    $just_written_type     Type of the entry just written.
		 *
		 * @return array<string, array<string, array{id: string, seq: int}>>
		 */
		private function evict_over_cap( array $map, string $just_written_locality, string $just_written_type ): array {
			$cap = $this->max_entries();

			// 0 means unbounded, matching `woodev_pickup_max_accumulated_points`'s own
			// convention (see Pickup_Handler::get_js_config()).
			if ( $cap <= 0 ) {
				return $map;
			}

			$entries = [];

			foreach ( $map as $locality => $types ) {
				if ( ! is_array( $types ) ) {
					continue;
				}

				foreach ( $types as $type => $entry ) {
					if ( ! is_array( $entry ) || ! isset( $entry['seq'] ) ) {
						continue;
					}

					$entries[] = [
						'locality' => $locality,
						'type'     => $type,
						'seq'      => (int) $entry['seq'],
					];
				}
			}

			$entry_count = count( $entries );

			while ( $entry_count > $cap ) {
				$oldest_index = null;
				$oldest_seq   = null;

				foreach ( $entries as $index => $entry ) {
					if ( $entry['locality'] === $just_written_locality && $entry['type'] === $just_written_type ) {
						continue;
					}

					if ( null === $oldest_seq || $entry['seq'] < $oldest_seq ) {
						$oldest_seq   = $entry['seq'];
						$oldest_index = $index;
					}
				}

				// Nothing left to evict except the just-written entry — a cap smaller
				// than 1 leaves it alone rather than deleting the write that was just
				// requested.
				if ( null === $oldest_index ) {
					break;
				}

				unset( $map[ $entries[ $oldest_index ]['locality'] ][ $entries[ $oldest_index ]['type'] ] );

				if ( empty( $map[ $entries[ $oldest_index ]['locality'] ] ) ) {
					unset( $map[ $entries[ $oldest_index ]['locality'] ] );
				}

				unset( $entries[ $oldest_index ] );
				--$entry_count;
			}

			return $map;
		}

		/**
		 * Resolves the configured entry cap.
		 *
		 * A non-numeric filter return falls back to {@see self::DEFAULT_MAX_ENTRIES} —
		 * NOT to unbounded — because unlike `woodev_pickup_max_accumulated_points`
		 * (whose safe default already IS unbounded), a filter nobody wrote correctly
		 * for THIS knob must not silently disable the bound it exists to enforce.
		 * A negative value clips to 0 (unbounded), mirroring the sibling filter's own
		 * "a negative bound is meaningless" clipping.
		 *
		 * @since 2.0.2
		 *
		 * @return int
		 */
		private function max_entries(): int {
			/**
			 * Caps how many (locality, type) selections the session remembers at once
			 * (issue #176).
			 *
			 * Point density and how many localities a real customer visits during one
			 * checkout are domain knowledge — the same reasoning that produced
			 * `woodev_pickup_max_accumulated_points` (#234). Unlike that filter, this
			 * one's safe default is a small positive number, not unbounded: a
			 * selection map grows only as fast as the customer confirms points, but is
			 * still unbounded by construction if never capped (spec §6).
			 *
			 * No plugin id is passed, unlike `woodev_pickup_max_accumulated_points`:
			 * {@see Pickup_Selection} knows only the {@see Selection_Scope} it was
			 * constructed with, never a plugin id — the scope's own
			 * {@see Selection_Scope::session_key()} is what a filter callback should
			 * discriminate on if it needs to answer differently per plugin.
			 *
			 * @since 2.0.2
			 *
			 * @param int $max 0 for unlimited; a positive entry count to bound the map.
			 */
			$max = apply_filters( 'woodev_pickup_max_remembered_selections', self::DEFAULT_MAX_ENTRIES );

			$max = is_numeric( $max ) ? (float) $max : (float) self::DEFAULT_MAX_ENTRIES;

			// Only a value that is ITSELF zero-or-below means unbounded. Casting to int
			// first would fold a positive fractional cap (`0.5`, `'0.5'`) into `0` and so
			// silently switch off the bound this filter exists to impose — the one
			// direction a malformed value must never fail in. Anything above zero is a
			// request to bound, so it bounds, at the smallest bound that can mean
			// anything.
			if ( $max <= 0 ) {
				return 0;
			}

			return max( 1, (int) $max );
		}
	}

endif;
