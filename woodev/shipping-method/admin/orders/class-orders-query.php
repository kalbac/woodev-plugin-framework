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
		 * Custom query var carrying the already-built pickup-point-presence meta clauses,
		 * for the legacy CPT datastore path (#836). Same shape and reason as
		 * {@see self::QUERY_VAR_TRACKING_CLAUSES}, built by
		 * {@see self::pickup_point_meta_clauses()}.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		const QUERY_VAR_PICKUP_POINT_CLAUSES = 'woodev_shipping_pickup_point_clauses';

		/**
		 * Custom query var carrying the already-built export-presence meta clauses,
		 * for the legacy CPT datastore path (SP-10 #841). Same shape and reason as
		 * {@see self::QUERY_VAR_PICKUP_POINT_CLAUSES}, built by
		 * {@see self::is_exported_meta_clauses()}.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		const QUERY_VAR_EXPORTED_CLAUSES = 'woodev_shipping_exported_clauses';

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
		 *     @type string   $carrier             provider id, or 'all'/''/omitted for the aggregate.
		 *     @type int      $page                1-based page number. Default 1.
		 *     @type int      $per_page            page size. Default {@see self::DEFAULT_PER_PAGE}.
		 *     @type string   $orderby             wc_get_orders() orderby. Default 'date'.
		 *     @type string   $order               'ASC' or 'DESC'. Default 'DESC'.
		 *     @type string   $search              free-text search term.
		 *     @type string   $after               ISO 8601 `YYYY-MM-DD`; orders created on/after this
		 *                                         day (SP-10 spec D10/D11). Independent of `$before`.
		 *     @type string   $before              ISO 8601 `YYYY-MM-DD`; orders created on/before this
		 *                                         day. Independent of `$after`.
		 *     @type string[] $status              native WC order statuses ('is'), with or without the
		 *                                         `wc-` prefix. Omitted or empty keeps the default status
		 *                                         list (every status except cancelled/failed).
		 *                                         Recognized values override that default entirely.
		 *                                         A non-empty request in which NOTHING is recognized
		 *                                         narrows to nothing, through the same
		 *                                         {@see self::NO_MATCH_META_QUERY} an unrecognized
		 *                                         carrier gets; it does NOT fall back to the
		 *                                         unfiltered table. Takes precedence over `$status_not`
		 *                                         if both are somehow sent.
		 *     @type string[] $status_not          native WC order statuses ('is not', #836) — "every
		 *                                         valid status except these", computed against the FULL
		 *                                         valid list (cancelled/failed included), never the
		 *                                         default view's narrower one. This is a native `status`
		 *                                         arg, not a meta clause — order status lives on the
		 *                                         order itself. Ignored when `$status` is also present.
		 *     @type string   $delivery_status     one of
		 *                                         {@see \Woodev\Framework\Shipping\Order\Delivery_Status::canonical_states()}
		 *                                         or {@see \Woodev\Framework\Shipping\Order\Delivery_Status::UNKNOWN}
		 *                                         ('is'). An unrecognized value is ignored (no filter
		 *                                         applied). Takes precedence over `$delivery_status_not`
		 *                                         if both are somehow sent.
		 *     @type string   $delivery_status_not same value set as `$delivery_status` ('is not', #836).
		 *                                         Ignored when `$delivery_status` is also present.
		 *     @type bool     $has_tracking        present (any value) => filters on whether the
		 *                                         matched carrier's own tracking-number meta exists;
		 *                                         absent => not filtered. Read via `array_key_exists()`,
		 *                                         not `isset()`, so an explicit `false` still counts as
		 *                                         present.
		 *     @type bool     $has_pickup_point    present (any value) => filters on whether the matched
		 *                                         carrier's own pickup-point meta exists (#836); absent
		 *                                         => not filtered. Same presence rule as `$has_tracking`.
		 *     @type bool     $is_exported         present (any value) => filters on whether the matched
		 *                                         carrier's own carrier-order-id meta exists (SP-10 #841)
		 *                                         — i.e. whether the order has ever been exported to the
		 *                                         carrier; absent => not filtered. Same presence rule as
		 *                                         `$has_tracking`.
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

			/*
			 * `null` means no status override; `[]` means the merchant DID ask for statuses
			 * and none of them is real, which must narrow to nothing (#837 defect 3).
			 *
			 * ⚠ "Narrow to nothing" is expressed by emptying the PROVIDER scope, so it flows
			 * through {@see self::NO_MATCH_META_QUERY} — the one "matches nothing" mechanism
			 * both datastore paths already share. The obvious alternative, passing a bogus
			 * status slug, was implemented first and is WRONG: it empties the table on HPOS
			 * but does nothing at all on the legacy CPT datastore, because `WP_Query` walks
			 * the REGISTERED post statuses and silently drops any value that is not one, so
			 * the status condition disappears and every row comes back. Measured both ways
			 * 08.09.2026 — HPOS returned 0, the CPT integration suite returned the row.
			 * An empty status ARRAY is worse still: HPOS's `OrdersTableQuery::sanitize_status()`
			 * expands it into every valid status.
			 */
			$requested_statuses = $this->resolve_requested_statuses( $request );
			$status_matches_nothing = ( [] === $requested_statuses );

			if ( null !== $requested_statuses && ! $status_matches_nothing ) {
				$args['status'] = $requested_statuses;
			}

			$providers = $status_matches_nothing ? [] : $this->resolve_providers( $carrier );
			$keys      = array_map(
				static function ( Orders_Provider $provider ): string {
					return $provider->get_marker_meta_key();
				},
				$providers
			);

			$meta_query_parts = [ self::meta_query_for_keys( $keys ) ];

			// `delivery_status` ('is') and `delivery_status_not` ('is not', #836) are mutually
			// exclusive from the UI's own rule picker; if a caller somehow sends both, 'is'
			// wins — there is exactly one status_clauses part either way.
			$valid_delivery_statuses = array_merge( Delivery_Status::canonical_states(), [ Delivery_Status::UNKNOWN ] );
			$delivery_status         = isset( $request['delivery_status'] ) ? (string) $request['delivery_status'] : '';
			$delivery_status_not     = isset( $request['delivery_status_not'] ) ? (string) $request['delivery_status_not'] : '';
			$status_clauses          = null;

			if ( '' !== $delivery_status && in_array( $delivery_status, $valid_delivery_statuses, true ) ) {
				$status_clauses = $this->delivery_status_meta_clauses( $providers, $delivery_status, false );
			} elseif ( '' !== $delivery_status_not && in_array( $delivery_status_not, $valid_delivery_statuses, true ) ) {
				$status_clauses = $this->delivery_status_meta_clauses( $providers, $delivery_status_not, true );
			}

			if ( null !== $status_clauses ) {
				$meta_query_parts[] = self::meta_query_for_clauses( $status_clauses );
			}

			$tracking_clauses = null;
			if ( array_key_exists( 'has_tracking', $request ) ) {
				$tracking_clauses   = $this->tracking_meta_clauses( $providers, wc_string_to_bool( $request['has_tracking'] ) );
				$meta_query_parts[] = self::meta_query_for_clauses( $tracking_clauses );
			}

			$pickup_point_clauses = null;
			if ( array_key_exists( 'has_pickup_point', $request ) ) {
				$pickup_point_clauses = $this->pickup_point_meta_clauses( $providers, wc_string_to_bool( $request['has_pickup_point'] ) );
				$meta_query_parts[]   = self::meta_query_for_clauses( $pickup_point_clauses );
			}

			$exported_clauses = null;
			if ( array_key_exists( 'is_exported', $request ) ) {
				$exported_clauses   = $this->is_exported_meta_clauses( $providers, wc_string_to_bool( $request['is_exported'] ) );
				$meta_query_parts[] = self::meta_query_for_clauses( $exported_clauses );
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

				if ( null !== $pickup_point_clauses ) {
					$args[ self::QUERY_VAR_PICKUP_POINT_CLAUSES ] = $pickup_point_clauses;
				}

				if ( null !== $exported_clauses ) {
					$args[ self::QUERY_VAR_EXPORTED_CLAUSES ] = $exported_clauses;
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
		 * `$negate` (#836) builds the 'is not' rule instead of 'is' — see below.
		 *
		 * `unknown` is deliberately BOTH things {@see Delivery_Status::resolve()} already
		 * treats as unknown: a raw value absent from the map, and a raw value present but
		 * mapped to something that is not one of the nine canonical states. ⚠ Those two
		 * need TWO clauses OR'd together, not one: only `NOT EXISTS` makes WP_Meta_Query
		 * LEFT JOIN, so a lone `NOT IN` silently drops every order that has no status
		 * meta at all — which is the commonest unknown there is. See the clause itself.
		 *
		 * ⚠ And that OR must itself be bound to the provider's own marker, for the same
		 * reason {@see self::tracking_meta_clauses()}'s negative case is — see the comment
		 * on the clause below.
		 *
		 * A provider with no status concept of its own (`get_status_meta_key()` is null,
		 * or its `status_map` maps nothing to a valid canonical state) is either ALWAYS
		 * unknown — for which its marker key (guaranteed present within scope, so this is
		 * never a false positive) stands in for "always true" — or NEVER matches a
		 * specific canonical state, in which case it contributes no clause at all.
		 *
		 * `$negate` (#836, "is not X"): honestly, "not X" includes every order this
		 * provider could report that ISN'T X — including one with no status meta at all,
		 * because "unknown" trivially satisfies "not X" too. So the negative branches
		 * reuse the exact same NOT-EXISTS-OR-NOT-IN shape the `unknown` branch already
		 * needs, just scoped to X's own raw values instead of every known raw value; and
		 * a provider that never maps anything to X has EVERY order qualifying as "not X",
		 * the same "always true via the marker" shape the no-status-concept branch above
		 * already uses. "Is not unknown" (negating the `unknown` canonical itself) is the
		 * mirror image: a definite known status, i.e. the plain `IN $known` clause.
		 *
		 * @since 2.0.2
		 *
		 * @param Orders_Provider[] $providers providers in scope.
		 * @param string            $canonical one of {@see Delivery_Status::canonical_states()}
		 *                                     or {@see Delivery_Status::UNKNOWN}.
		 * @param bool              $negate    false => 'is' (default); true => 'is not' (#836).
		 * @return array<int,array<string,mixed>> one clause per participating provider.
		 */
		private function delivery_status_meta_clauses( array $providers, string $canonical, bool $negate = false ): array {
			$clauses = [];

			foreach ( $providers as $provider ) {
				$status_key = $provider->get_status_meta_key();

				if ( null === $status_key ) {
					// A provider with no status concept of its own is ALWAYS unknown.
					$provider_matches = ( Delivery_Status::UNKNOWN === $canonical ) !== $negate;

					if ( $provider_matches ) {
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
						// Maps nothing to any canonical state => this provider is ALWAYS unknown.
						if ( ! $negate ) {
							$clauses[] = [
								'key'     => $provider->get_marker_meta_key(),
								'compare' => 'EXISTS',
							];
						}

						continue;
					}

					if ( ! $negate ) {
						/*
						 * TWO status clauses, not one, and this is the whole point: `NOT IN`
						 * alone does NOT match an order with no status meta at all. Only `NOT
						 * EXISTS` makes WP_Meta_Query use a LEFT JOIN — WordPress says so
						 * itself in `class-wp-meta-query.php`: «If any JOINs are LEFT JOINs
						 * (as in the case of NOT EXISTS), then all JOINs should be LEFT.
						 * Otherwise posts with no metadata will be excluded from results.»
						 * An order that never received a carrier status is the COMMONEST
						 * unknown, so a lone `NOT IN` silently hides most of what the filter
						 * exists to find. Measured, not reasoned: the integration test
						 * covering the no-status order failed with exactly that shape.
						 *
						 * ⚠ And the pair MUST be bound to this provider's own marker, for the
						 * same reason the negative tracking case below is. These clauses are
						 * OR-ed across providers, and «carrier B wrote no status meta» is
						 * trivially TRUE of every carrier A order — a carrier never writes
						 * another's meta. Unbound, the OR therefore matches the entire table:
						 * measured on the rig 08.09.2026 with two carriers,
						 * `delivery_status=unknown` returned 71 of 71 (#837 defect 2), while
						 * each single-carrier view was correct, because only one provider
						 * participates there. Same defect, same shape, same fix as the
						 * `has_tracking=false` clause repaired in s127.
						 */
						$clauses[] = [
							'relation' => 'AND',
							[
								'key'     => $provider->get_marker_meta_key(),
								'compare' => 'EXISTS',
							],
							[
								'relation' => 'OR',
								[
									'key'     => $status_key,
									'compare' => 'NOT EXISTS',
								],
								[
									'key'     => $status_key,
									'value'   => $known,
									'compare' => 'NOT IN',
								],
							],
						];

						continue;
					}

					// "is not unknown" (#836): a definite known status. `IN` on the provider's
					// own key already implies both existence and that provider's order.
					$clauses[] = [
						'key'     => $status_key,
						'value'   => $known,
						'compare' => 'IN',
					];

					continue;
				}

				$raw_values = $inverted[ $canonical ] ?? [];

				if ( ! $negate ) {
					if ( [] === $raw_values ) {
						continue;
					}

					// `IN` on a carrier's OWN status key already implies that carrier's
					// order, so it needs no binding — the same asymmetry as `EXISTS` versus
					// `NOT EXISTS` in {@see self::tracking_meta_clauses()}.
					$clauses[] = [
						'key'     => $status_key,
						'value'   => $raw_values,
						'compare' => 'IN',
					];

					continue;
				}

				// "is not X" (#836):
				if ( [] === $raw_values ) {
					// This provider never maps anything to X, so every one of its orders
					// qualifies as "not X" — its marker key stands in for "always true",
					// same as the no-status-concept branch above.
					$clauses[] = [
						'key'     => $provider->get_marker_meta_key(),
						'compare' => 'EXISTS',
					];

					continue;
				}

				/*
				 * bound(marker) AND (status NOT EXISTS OR status NOT IN raw-values-for-X) —
				 * the same two-clause shape the `unknown` branch above needs, because "no
				 * status meta at all" trivially satisfies "not X" too, and only `NOT EXISTS`
				 * makes WP_Meta_Query LEFT JOIN (see the `unknown` branch's comment). Bound
				 * to the marker for the same reason: OR-ed across providers, «carrier B's
				 * status is not X» is trivially true of every carrier A order.
				 */
				$clauses[] = [
					'relation' => 'AND',
					[
						'key'     => $provider->get_marker_meta_key(),
						'compare' => 'EXISTS',
					],
					[
						'relation' => 'OR',
						[
							'key'     => $status_key,
							'compare' => 'NOT EXISTS',
						],
						[
							'key'     => $status_key,
							'value'   => $raw_values,
							'compare' => 'NOT IN',
						],
					],
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

				if ( $has_tracking ) {
					// `EXISTS` on a carrier's OWN tracking key already implies that
					// carrier's order, so it needs no binding.
					$clauses[] = [
						'key'     => $tracking_key,
						'compare' => 'EXISTS',
					];

					continue;
				}

				/*
				 * ⚠ The NEGATIVE case MUST be bound to this provider's own marker.
				 *
				 * These clauses are OR-ed across providers, and «carrier B's tracking
				 * key does not exist» is trivially TRUE of every carrier A order — a
				 * carrier never writes another's meta. Unbound, the OR therefore matches
				 * the entire table: measured on the rig 08.09.2026 with two carriers,
				 * `has_tracking=false` returned 71 of 71 instead of 24, while each
				 * single-carrier view (11 of 34, 13 of 37) was correct, because only one
				 * provider participates there. That is why this is bound and `EXISTS`
				 * above is not.
				 */
				$clauses[] = [
					'relation' => 'AND',
					[
						'key'     => $provider->get_marker_meta_key(),
						'compare' => 'EXISTS',
					],
					[
						'key'     => $tracking_key,
						'compare' => 'NOT EXISTS',
					],
				];
			}

			return $clauses;
		}


		/**
		 * Builds one pickup-point-presence meta clause per provider (#836) — the exact
		 * same asymmetry as {@see self::tracking_meta_clauses()}. A provider with no
		 * pickup-point concept of its own (`get_pickup_point_meta_key()` is null) can
		 * never report `true`, so it contributes nothing to that case; for `false` it
		 * always counts as "no pickup point", its marker key standing in for "always
		 * true".
		 *
		 * @since 2.0.2
		 *
		 * @param Orders_Provider[] $providers        providers in scope.
		 * @param bool              $has_pickup_point true => the pickup-point meta must
		 *                                            exist; false => it must not.
		 * @return array<int,array<string,mixed>> one clause per participating provider.
		 */
		private function pickup_point_meta_clauses( array $providers, bool $has_pickup_point ): array {
			$clauses = [];

			foreach ( $providers as $provider ) {
				$pickup_point_key = $provider->get_pickup_point_meta_key();

				if ( null === $pickup_point_key ) {
					if ( ! $has_pickup_point ) {
						$clauses[] = [
							'key'     => $provider->get_marker_meta_key(),
							'compare' => 'EXISTS',
						];
					}

					continue;
				}

				if ( $has_pickup_point ) {
					// `EXISTS` on a carrier's OWN pickup-point key already implies that
					// carrier's order, so it needs no binding.
					$clauses[] = [
						'key'     => $pickup_point_key,
						'compare' => 'EXISTS',
					];

					continue;
				}

				// ⚠ The NEGATIVE case MUST be bound to this provider's own marker — same
				// reason as {@see self::tracking_meta_clauses()}'s negative case: OR-ed
				// across providers, «carrier B's pickup-point key does not exist» is
				// trivially true of every carrier A order.
				$clauses[] = [
					'relation' => 'AND',
					[
						'key'     => $provider->get_marker_meta_key(),
						'compare' => 'EXISTS',
					],
					[
						'key'     => $pickup_point_key,
						'compare' => 'NOT EXISTS',
					],
				];
			}

			return $clauses;
		}


		/**
		 * Builds one export-presence meta clause per provider (SP-10 #841) — the exact
		 * same asymmetry as {@see self::pickup_point_meta_clauses()}. A provider with
		 * no declared carrier-order-id key (`get_carrier_order_id_meta_key()` is null)
		 * can never report `true` — the framework has no way to know whether such a
		 * carrier's orders were exported, so it does not guess — and for `false` it
		 * always counts as "not exported", its marker key standing in for "always
		 * true": every one of its orders belongs in the "new" bucket by definition.
		 *
		 * @since 2.0.2
		 *
		 * @param Orders_Provider[] $providers   providers in scope.
		 * @param bool              $is_exported true => the carrier-order-id meta must
		 *                                       exist; false => it must not.
		 * @return array<int,array<string,mixed>> one clause per participating provider.
		 */
		private function is_exported_meta_clauses( array $providers, bool $is_exported ): array {
			$clauses = [];

			foreach ( $providers as $provider ) {
				$carrier_order_id_key = $provider->get_carrier_order_id_meta_key();

				if ( null === $carrier_order_id_key ) {
					if ( ! $is_exported ) {
						$clauses[] = [
							'key'     => $provider->get_marker_meta_key(),
							'compare' => 'EXISTS',
						];
					}

					continue;
				}

				if ( $is_exported ) {
					// `EXISTS` on a carrier's OWN carrier-order-id key already implies
					// that carrier's order, so it needs no binding.
					$clauses[] = [
						'key'     => $carrier_order_id_key,
						'compare' => 'EXISTS',
					];

					continue;
				}

				// ⚠ The NEGATIVE case MUST be bound to this provider's own marker — same
				// reason as {@see self::pickup_point_meta_clauses()}'s negative case: OR-ed
				// across providers, «carrier B's carrier-order-id key does not exist» is
				// trivially true of every carrier A order.
				$clauses[] = [
					'relation' => 'AND',
					[
						'key'     => $provider->get_marker_meta_key(),
						'compare' => 'EXISTS',
					],
					[
						'key'     => $carrier_order_id_key,
						'compare' => 'NOT EXISTS',
					],
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
		 * Resolves an explicit native WC order-status filter (SP-10 spec D10; `is not`
		 * added #836) — validated against {@see wc_get_order_statuses()}, tolerating the
		 * value with or without its `wc-` prefix (both are seen in the wild:
		 * `wc_get_order_statuses()` keys always carry it, but a status is commonly
		 * referred to without it, e.g. {@see \WC_Order::update_status()}).
		 *
		 * ⚠ Three outcomes, and the middle one is the defect this method used to have
		 * (#837 defect 3):
		 *
		 * - `null` — nothing was asked for. The caller keeps its default status list,
		 *   cancelled/failed exclusion included.
		 * - `[]` — a filter WAS asked for and nothing in it is a real status. The request
		 *   must narrow to NOTHING. This used to return the same empty array as the case
		 *   above, so `status=["Pending payment"]` or `status=["nonsense"]` silently
		 *   returned the whole unfiltered table — a garbage value WIDENED the selection
		 *   instead of emptying it. Measured on the rig 08.09.2026: 71 of 71 rows.
		 * - a non-empty list — recognized statuses, which override the default entirely,
		 *   cancelled/failed included, since the merchant asked for exactly those.
		 *
		 * `status_not` ('is not', #836) is native `status`, not a meta clause — order
		 * status lives on the order itself, so "is not X" is expressible directly as
		 * "every valid status except X" against the FULL valid list (cancelled/failed
		 * included — this is an explicit filter overriding the default entirely, the same
		 * as `status` already does). `status` takes precedence when both are present.
		 *
		 * Values that are not strings never reach here — the REST layer rejects them
		 * outright — and this method still degrades sanely on its own, because
		 * {@see self::build_args()} is "pure; injectable for tests".
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $request see {@see self::build_args()}.
		 * @return string[]|null `null` for "no override", `[]` for "matches nothing",
		 *                       otherwise the recognized `wc-`-prefixed statuses.
		 */
		private function resolve_requested_statuses( array $request ): ?array {
			$valid = array_keys( wc_get_order_statuses() );

			$positive = self::normalize_status_list( $request['status'] ?? null );
			if ( null !== $positive ) {
				return array_values( array_unique( array_intersect( $positive, $valid ) ) );
			}

			$negative = self::normalize_status_list( $request['status_not'] ?? null );
			if ( null !== $negative ) {
				$excluded = array_intersect( $negative, $valid );

				return array_values( array_diff( $valid, $excluded ) );
			}

			return null;
		}

		/**
		 * Normalizes a raw `status`/`status_not` request value: trims each entry, adds
		 * the `wc-` prefix when missing, and drops blanks. Shared by both directions of
		 * {@see self::resolve_requested_statuses()} so they cannot silently diverge on
		 * what counts as "no override" versus "asked for something".
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $value candidate request value.
		 * @return string[]|null `null` when not an array, or an all-blank/empty one
		 *                       (indistinguishable from "no override"); otherwise the
		 *                       normalized, non-blank entries.
		 */
		private static function normalize_status_list( $value ): ?array {
			if ( ! is_array( $value ) ) {
				return null;
			}

			$normalized = array_map(
				static function ( $status ): string {
					$status = trim( (string) $status );

					return ( '' !== $status && 0 !== strpos( $status, 'wc-' ) ) ? 'wc-' . $status : $status;
				},
				$value
			);

			// An all-blank request ('', '   ') is indistinguishable from asking for nothing
			// at all, so it keeps meaning "no override" — only a request with actual content
			// that matches no real status is a narrowing.
			$normalized = array_values(
				array_filter(
					$normalized,
					static function ( string $status ): bool {
						return '' !== $status && 'wc-' !== $status;
					}
				)
			);

			return [] === $normalized ? null : $normalized;
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
