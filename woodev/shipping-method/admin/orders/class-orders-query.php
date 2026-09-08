<?php
/**
 * Shipping orders — scope query
 *
 * @since 2.0.2
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Admin\Orders;

use Woodev\Framework\Shipping\Order\Delivery_Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Admin\\Orders\\Orders_Query' ) ) :

	/**
	 * Builds `wc_get_orders()` arguments from the {@see Orders_Registry} and runs them
	 * (SP-10 spec M2).
	 *
	 * All three shipped carrier plugins select their rows the same way — one
	 * `wc_get_orders()` call scoped to their marker key, `type` restricted to
	 * `wc_get_order_types( 'view-orders' )`, and `wc-cancelled`/`wc-failed` excluded
	 * from the status list. The aggregate (all carriers) view is the SAME single query
	 * matching ANY registered provider's marker key — never N queries stitched
	 * together.
	 *
	 * **The scope mechanism itself branches on the order datastore** (round 2 of this
	 * increment; round 1 assumed one mechanism covered both and was wrong):
	 *
	 * - HPOS: a real `meta_query` — measured correct against a real HPOS install on the
	 *   rig, 07.09.2026 (SP-10 spec M2).
	 * - Legacy CPT: `WC_Order_Data_Store_CPT` does not support `meta_query` at all — it
	 *   fires `_doing_it_wrong` (WC ≥9.2) and silently returns UNFILTERED results, so a
	 *   `meta_query` arg must never reach it. This class instead emits the
	 *   {@see self::QUERY_VAR_MARKER_KEYS} custom query var, which
	 *   {@see Orders_Registry::translate_marker_keys_query_var()} turns into a real
	 *   `meta_query` on WooCommerce's own `woocommerce_order_data_store_cpt_get_orders_query`
	 *   filter — the same technique all three shipped carrier plugins already use.
	 *
	 * @since 2.0.2
	 */
	class Orders_Query {

		/** @var int default page size, absent an explicit `per_page`. */
		const DEFAULT_PER_PAGE = 20;

		/**
		 * Custom query var carrying the marker meta keys in scope, for the legacy CPT
		 * datastore path. One var covers both the single-carrier and aggregate cases —
		 * it always carries an array, one entry for a single carrier, N for the
		 * aggregate, zero for "matches nothing".
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		const QUERY_VAR_MARKER_KEYS = 'woodev_shipping_marker_keys';

		/**
		 * A `meta_query` clause that can never match a real order — used both for the
		 * zero-provider case and for a carrier id the registry does not recognize, so
		 * neither silently falls back to "matches everything". Shared by both datastore
		 * paths through {@see self::meta_query_for_keys()}, so they cannot silently
		 * diverge on what "matches nothing" means.
		 *
		 * @since 2.0.2
		 *
		 * @var array<int, array<string, string>>
		 */
		const NO_MATCH_META_QUERY = [
			[
				'key'     => '_woodev_shipping_orders_none',
				'value'   => '__woodev_shipping_orders_query_never_matches__',
				'compare' => '=',
			],
		];

		/**
		 * Custom query var carrying the already-built delivery-status meta clauses, for
		 * the legacy CPT datastore path (SP-10 spec D10). Built by
		 * {@see self::delivery_status_meta_clauses()} — one clause per participating
		 * provider — never combined into a `relation` shape itself; the CPT-side
		 * translation combines it the same way {@see self::build_args()} does on HPOS, so
		 * the two paths cannot silently diverge on what the delivery-status filter means.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		const QUERY_VAR_STATUS_CLAUSES = 'woodev_shipping_status_clauses';

		/**
		 * Custom query var carrying the already-built tracking-presence meta clauses, for
		 * the legacy CPT datastore path (SP-10 spec D10). Same shape and reason as
		 * {@see self::QUERY_VAR_STATUS_CLAUSES}, built by
		 * {@see self::tracking_meta_clauses()}.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		const QUERY_VAR_TRACKING_CLAUSES = 'woodev_shipping_tracking_clauses';

		/**
		 * Registry to read providers from.
		 *
		 * @since 2.0.2
		 *
		 * @var Orders_Registry
		 */
		private $registry;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param Orders_Registry|null $registry registry; defaults to the singleton.
		 */
		public function __construct( ?Orders_Registry $registry = null ) {
			$this->registry = $registry ?? Orders_Registry::instance();
		}

		/**
		 * Builds the `wc_get_orders()` args for a request (pure; injectable for tests).
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $request {
		 *     Optional. Request-shaped params.
		 *
		 *     @type string   $carrier         provider id, or 'all'/''/omitted for the aggregate.
		 *     @type int      $page            1-based page number. Default 1.
		 *     @type int      $per_page        page size. Default {@see self::DEFAULT_PER_PAGE}.
		 *     @type string   $orderby         wc_get_orders() orderby. Default 'date'.
		 *     @type string   $order           'ASC' or 'DESC'. Default 'DESC'.
		 *     @type string   $search          free-text search term.
		 *     @type string   $after           ISO 8601 `YYYY-MM-DD`; orders created on/after this
		 *                                     day (SP-10 spec D10/D11). Independent of `$before`.
		 *     @type string   $before          ISO 8601 `YYYY-MM-DD`; orders created on/before this
		 *                                     day. Independent of `$after`.
		 *     @type string[] $status          native WC order statuses, with or without the `wc-`
		 *                                     prefix. An unrecognized value is dropped; an
		 *                                     empty/all-dropped result keeps the default status
		 *                                     list (every status except cancelled/failed) rather
		 *                                     than an unfiltered one.
		 *     @type string   $delivery_status one of
		 *                                     {@see \Woodev\Framework\Shipping\Order\Delivery_Status::canonical_states()}
		 *                                     or {@see \Woodev\Framework\Shipping\Order\Delivery_Status::UNKNOWN}.
		 *                                     An unrecognized value is ignored (no filter applied).
		 *     @type bool     $has_tracking    present (any value) => filters on whether the
		 *                                     matched carrier's own tracking-number meta exists;
		 *                                     absent => not filtered. Read via `array_key_exists()`,
		 *                                     not `isset()`, so an explicit `false` still counts as
		 *                                     present.
		 * }
		 * @return array<string,mixed>
		 */
		public function build_args( array $request = [] ): array {
			$carrier  = isset( $request['carrier'] ) ? (string) $request['carrier'] : '';
			$page     = isset( $request['page'] ) ? max( 1, (int) $request['page'] ) : 1;
			$per_page = isset( $request['per_page'] ) ? max( 1, (int) $request['per_page'] ) : self::DEFAULT_PER_PAGE;
			$orderby  = isset( $request['orderby'] ) && '' !== $request['orderby'] ? (string) $request['orderby'] : 'date';
			$order    = isset( $request['order'] ) ? strtoupper( (string) $request['order'] ) : 'DESC';
			$order    = in_array( $order, [ 'ASC', 'DESC' ], true ) ? $order : 'DESC';
			$search   = isset( $request['search'] ) ? trim( (string) $request['search'] ) : '';

			$args = [
				'type'     => wc_get_order_types( 'view-orders' ),
				'status'   => array_keys( array_diff_key( wc_get_order_statuses(), array_flip( [ 'wc-cancelled', 'wc-failed' ] ) ) ),
				'limit'    => $per_page,
				'paged'    => $page,
				'orderby'  => $orderby,
				'order'    => $order,
				'paginate' => true,
			];

			$date_created = $this->build_date_created_arg( $request );
			if ( null !== $date_created ) {
				$args['date_created'] = $date_created;
			}

			$requested_statuses = $this->resolve_requested_statuses( $request );
			if ( [] !== $requested_statuses ) {
				$args['status'] = $requested_statuses;
			}

			$providers = $this->resolve_providers( $carrier );
			$keys      = array_map(
				static function ( Orders_Provider $provider ): string {
					return $provider->get_marker_meta_key();
				},
				$providers
			);

			$meta_query_parts = [ self::meta_query_for_keys( $keys ) ];

			$delivery_status = isset( $request['delivery_status'] ) ? (string) $request['delivery_status'] : '';
			$status_clauses  = null;
			if ( '' !== $delivery_status && in_array( $delivery_status, array_merge( Delivery_Status::canonical_states(), [ Delivery_Status::UNKNOWN ] ), true ) ) {
				$status_clauses     = $this->delivery_status_meta_clauses( $providers, $delivery_status );
				$meta_query_parts[] = self::meta_query_for_clauses( $status_clauses );
			}

			$tracking_clauses = null;
			if ( array_key_exists( 'has_tracking', $request ) ) {
				$tracking_clauses   = $this->tracking_meta_clauses( $providers, wc_string_to_bool( $request['has_tracking'] ) );
				$meta_query_parts[] = self::meta_query_for_clauses( $tracking_clauses );
			}

			if ( $this->is_hpos_enabled() ) {
				$args['meta_query'] = self::combine_meta_queries( $meta_query_parts ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the framework's supported HPOS row-scope/filter mechanism (SP-10 spec M2, D10); never reached on the legacy CPT datastore (see class docblock).
			} else {
				// `meta_query` is unsupported on the legacy CPT datastore (fires
				// _doing_it_wrong and is silently ignored) — pass one custom query var per
				// part instead; Orders_Registry::translate_marker_keys_query_var() rebuilds
				// and combines them through the SAME self::combine_meta_queries(), so the two
				// datastore paths cannot silently diverge on what any of these filters mean.
				$args[ self::QUERY_VAR_MARKER_KEYS ] = $keys;

				if ( null !== $status_clauses ) {
					$args[ self::QUERY_VAR_STATUS_CLAUSES ] = $status_clauses;
				}

				if ( null !== $tracking_clauses ) {
					$args[ self::QUERY_VAR_TRACKING_CLAUSES ] = $tracking_clauses;
				}
			}

			if ( '' !== $search ) {
				$args['s'] = $search;
			}

			/**
			 * Filters the built `wc_get_orders()` args before the query runs.
			 *
			 * @since 2.0.2
			 *
			 * @param array<string,mixed> $args    built args.
			 * @param string              $carrier requested carrier id, or '' for the aggregate.
			 */
			$filtered = apply_filters( 'woodev_shipping_orders_query_args', $args, $carrier );

			return is_array( $filtered ) ? $filtered : $args;
		}

		/**
		 * Builds the args and runs the query.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $request see {@see self::build_args()}.
		 * @return object wc_get_orders() paginated result (orders/total/max_num_pages).
		 */
		public function get_results( array $request = [] ) {
			return wc_get_orders( $this->build_args( $request ) );
		}

		/**
		 * Builds the `meta_query` shape for a set of marker keys.
		 *
		 * Shared by the HPOS path ({@see self::build_args()}) and the legacy-CPT
		 * translation ({@see Orders_Registry::translate_marker_keys_query_var()}), so
		 * the two paths cannot silently diverge on what "one carrier", "several
		 * carriers" or "matches nothing" means. A thin wrapper over
		 * {@see self::meta_query_for_clauses()} — every key becomes an `EXISTS` clause,
		 * which is the same OR-across-providers shape the delivery-status and
		 * tracking-presence filters build (SP-10 spec D10).
		 *
		 * @since 2.0.2
		 *
		 * @param string[] $keys marker meta keys; empty means "matches nothing".
		 * @return array<int|string, mixed>
		 */
		public static function meta_query_for_keys( array $keys ): array {
			return self::meta_query_for_clauses(
				array_map(
					static function ( string $key ): array {
						return [
							'key'     => $key,
							'compare' => 'EXISTS',
						];
					},
					$keys
				)
			);
		}

		/**
		 * Builds the `meta_query` shape for a set of already-built clauses — the general
		 * form of {@see self::meta_query_for_keys()}, reused for the delivery-status and
		 * tracking-presence filters (SP-10 spec D10) rather than inventing a second
		 * shape: one clause per participating provider, OR'd together, or the
		 * {@see self::NO_MATCH_META_QUERY} sentinel when nothing participates.
		 *
		 * @since 2.0.2
		 *
		 * @param array<int,array<string,mixed>> $clauses one clause per participating provider.
		 * @return array<int|string, mixed>
		 */
		public static function meta_query_for_clauses( array $clauses ): array {
			if ( [] === $clauses ) {
				return self::NO_MATCH_META_QUERY;
			}

			if ( 1 === count( $clauses ) ) {
				return [ reset( $clauses ) ];
			}

			$meta_query = [ 'relation' => 'OR' ];

			foreach ( $clauses as $clause ) {
				$meta_query[] = $clause;
			}

			return $meta_query;
		}

		/**
		 * Combines several already-built `meta_query` parts — the marker-key scope plus
		 * an optional delivery-status and/or tracking-presence filter — by ANDing them
		 * together (SP-10 spec D10). Each part already owns its own OR-across-providers
		 * shape, so this never merges their internal `relation`s, only wraps them.
		 * Returns the single part unchanged when there is only one, so the existing
		 * scope-only shape survives byte for byte when no extra filter was requested.
		 *
		 * `public`, not `private`: {@see Orders_Registry::translate_marker_keys_query_var()}
		 * calls this too, on the legacy-CPT path — the two datastore paths share this one
		 * combination rule rather than each growing their own.
		 *
		 * @since 2.0.2
		 *
		 * @param array<int, array<int|string, mixed>> $parts one or more meta_query parts.
		 * @return array<int|string, mixed>
		 */
		public static function combine_meta_queries( array $parts ): array {
			if ( 1 === count( $parts ) ) {
				return $parts[0];
			}

			return array_merge( [ 'relation' => 'AND' ], $parts );
		}

		/**
		 * Resolves the providers in scope for one carrier or the aggregate.
		 *
		 * @since 2.0.2
		 *
		 * @param string $carrier provider id, or 'all'/'' for the aggregate.
		 * @return Orders_Provider[] empty means "matches nothing" (unknown carrier, or zero providers).
		 */
		private function resolve_providers( string $carrier ): array {
			if ( '' !== $carrier && 'all' !== $carrier ) {
				$provider = $this->registry->get_provider( $carrier );

				// An unrecognized carrier must never fall back to the aggregate.
				return null !== $provider ? [ $provider ] : [];
			}

			return array_values( $this->registry->get_providers() );
		}

		/**
		 * Builds one delivery-status meta clause per provider able to say anything about
		 * a canonical state, inverting each provider's OWN `status_map` (SP-10 spec D10) —
		 * never a single shared map, because carriers do not share raw vocabularies.
		 *
		 * `unknown` is deliberately BOTH things {@see Delivery_Status::resolve()} already
		 * treats as unknown: a raw value absent from the map, and a raw value present but
		 * mapped to something that is not one of the nine canonical states. WordPress's
		 * `meta_query` `NOT IN` compare already matches an order with no such meta key at
		 * all — it LEFT JOINs and includes the null-meta rows for every negative compare
		 * (`NOT IN`, `NOT EXISTS`, `!=`, …) — so one `NOT IN` clause against the
		 * provider's own known-good raw values covers both cases in a single condition,
		 * never two clauses that could disagree with each other.
		 *
		 * A provider with no status concept of its own (`get_status_meta_key()` is null,
		 * or its `status_map` maps nothing to a valid canonical state) is either ALWAYS
		 * unknown — for which its marker key (guaranteed present within scope, so this is
		 * never a false positive) stands in for "always true" — or NEVER matches a
		 * specific canonical state, in which case it contributes no clause at all.
		 *
		 * @since 2.0.2
		 *
		 * @param Orders_Provider[] $providers providers in scope.
		 * @param string            $canonical one of {@see Delivery_Status::canonical_states()}
		 *                                     or {@see Delivery_Status::UNKNOWN}.
		 * @return array<int,array<string,mixed>> one clause per participating provider.
		 */
		private function delivery_status_meta_clauses( array $providers, string $canonical ): array {
			$clauses = [];

			foreach ( $providers as $provider ) {
				$status_key = $provider->get_status_meta_key();

				if ( null === $status_key ) {
					if ( Delivery_Status::UNKNOWN === $canonical ) {
						$clauses[] = [
							'key'     => $provider->get_marker_meta_key(),
							'compare' => 'EXISTS',
						];
					}

					continue;
				}

				$inverted = Delivery_Status::invert_status_map( $provider->get_status_map() );

				if ( Delivery_Status::UNKNOWN === $canonical ) {
					$known = [];
					foreach ( $inverted as $raw_values_for_state ) {
						$known = array_merge( $known, $raw_values_for_state );
					}
					$known = array_values( array_unique( $known ) );

					if ( [] === $known ) {
						$clauses[] = [
							'key'     => $provider->get_marker_meta_key(),
							'compare' => 'EXISTS',
						];

						continue;
					}

					$clauses[] = [
						'key'     => $status_key,
						'value'   => $known,
						'compare' => 'NOT IN',
					];

					continue;
				}

				$raw_values = $inverted[ $canonical ] ?? [];

				if ( [] === $raw_values ) {
					continue;
				}

				$clauses[] = [
					'key'     => $status_key,
					'value'   => $raw_values,
					'compare' => 'IN',
				];
			}

			return $clauses;
		}

		/**
		 * Builds one tracking-presence meta clause per provider (SP-10 spec D10). A
		 * provider with no tracking concept of its own (`get_tracking_meta_key()` is
		 * null) can never report `true`, so it contributes nothing to that case; for
		 * `false` it always counts as "no tracking" — its marker key (guaranteed present
		 * within scope) stands in for "always true", exactly like
		 * {@see self::delivery_status_meta_clauses()}'s no-status-concept branch.
		 *
		 * @since 2.0.2
		 *
		 * @param Orders_Provider[] $providers    providers in scope.
		 * @param bool              $has_tracking true => the tracking-number meta must
		 *                                        exist; false => it must not.
		 * @return array<int,array<string,mixed>> one clause per participating provider.
		 */
		private function tracking_meta_clauses( array $providers, bool $has_tracking ): array {
			$clauses = [];

			foreach ( $providers as $provider ) {
				$tracking_key = $provider->get_tracking_meta_key();

				if ( null === $tracking_key ) {
					if ( ! $has_tracking ) {
						$clauses[] = [
							'key'     => $provider->get_marker_meta_key(),
							'compare' => 'EXISTS',
						];
					}

					continue;
				}

				$clauses[] = [
					'key'     => $tracking_key,
					'compare' => $has_tracking ? 'EXISTS' : 'NOT EXISTS',
				];
			}

			return $clauses;
		}

		/**
		 * Builds the `date_created` arg from `after`/`before`, both optional and
		 * independent (SP-10 spec D10/D11). Uses WooCommerce's own documented date-query
		 * syntax for `wc_get_orders()` — a `>=`/`<=` prefix, or a `...` range when both
		 * bounds are given — read from WooCommerce's developer documentation
		 * ("`wc_get_orders()` and order queries"), not recalled. `date_created` maps to
		 * `post_date` (CPT) / the HPOS date column through WooCommerce's own `date_query`
		 * mechanism on BOTH datastores, never `meta_query`, so — unlike the
		 * delivery-status and tracking filters — it needs no legacy-CPT translation.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $request see {@see self::build_args()}.
		 * @return string|null null when neither bound is a valid ISO 8601 date.
		 */
		private function build_date_created_arg( array $request ): ?string {
			$after  = self::normalize_iso_date( $request['after'] ?? '' );
			$before = self::normalize_iso_date( $request['before'] ?? '' );

			if ( null !== $after && null !== $before ) {
				return $after . '...' . $before;
			}

			if ( null !== $after ) {
				return '>=' . $after;
			}

			if ( null !== $before ) {
				return '<=' . $before;
			}

			return null;
		}

		/**
		 * Validates an ISO 8601 `YYYY-MM-DD` date string, including calendar validity
		 * (rejects e.g. `2026-02-30`).
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $value candidate date.
		 * @return string|null the value unchanged when valid, else null.
		 */
		private static function normalize_iso_date( $value ): ?string {
			if ( ! is_string( $value ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches ) ) {
				return null;
			}

			if ( ! checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] ) ) {
				return null;
			}

			return $value;
		}

		/**
		 * Resolves an explicit native WC order-status filter (SP-10 spec D10) —
		 * validated against {@see wc_get_order_statuses()}, tolerating the value with or
		 * without its `wc-` prefix (both are seen in the wild: `wc_get_order_statuses()`
		 * keys always carry it, but a status is commonly referred to without it, e.g.
		 * {@see \WC_Order::update_status()}). An unrecognized value is dropped rather
		 * than rejected here — {@see self::build_args()} is "pure; injectable for tests"
		 * and must degrade sanely on its own; the REST layer additionally rejects
		 * anything not a string outright before this is ever reached. An empty result
		 * (nothing requested, or nothing requested survived) means "no override" — the
		 * caller keeps the default status list, deliberately including its
		 * cancelled/failed exclusion; an explicit `status` filter overrides that default
		 * entirely, cancelled/failed included, since the merchant asked for exactly those
		 * statuses.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $request see {@see self::build_args()}.
		 * @return string[] valid `wc-`-prefixed statuses, or empty for "no override".
		 */
		private function resolve_requested_statuses( array $request ): array {
			if ( ! isset( $request['status'] ) || ! is_array( $request['status'] ) ) {
				return [];
			}

			$valid = array_keys( wc_get_order_statuses() );

			$normalized = array_map(
				static function ( $status ): string {
					$status = trim( (string) $status );

					return ( '' !== $status && 0 !== strpos( $status, 'wc-' ) ) ? 'wc-' . $status : $status;
				},
				$request['status']
			);

			return array_values( array_unique( array_intersect( $normalized, $valid ) ) );
		}

		/**
		 * Whether the store uses HPOS. Thin wrapper over
		 * {@see \Woodev_Plugin_Compatibility::is_hpos_enabled()}, `protected` so a unit
		 * test can flip it deterministically — the real static call always reports
		 * `false` under Brain Monkey (no `OrderUtil` class is loaded there; see
		 * `PluginCompatibilityTest::is_hpos_enabled_returns_false_when_order_util_not_available()`),
		 * which is not a substitute for exercising the HPOS branch.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		protected function is_hpos_enabled(): bool {
			return \Woodev_Plugin_Compatibility::is_hpos_enabled();
		}
	}

endif;
