<?php
/**
 * Unit: Orders_Query args-building (SP-10 increment 1, spec M2 + round 2).
 *
 * Pins the ARGS built by Orders_Query::build_args(), not wc_get_orders() itself —
 * the aggregate `relation => OR` shape was measured against a real HPOS install on
 * the rig (SP-10 spec M2); this test only pins that the class still asks for it.
 *
 * Round 2: the legacy CPT order datastore does not support `meta_query` at all (fires
 * `_doing_it_wrong` and silently returns UNFILTERED results), so build_args() branches
 * on the datastore — every test below runs against BOTH branches via
 * {@see self::query_with_hpos()}, which overrides the real
 * `Woodev_Plugin_Compatibility::is_hpos_enabled()` static call: that call always
 * returns false under Brain Monkey (no `OrderUtil` class is loaded here — see
 * `PluginCompatibilityTest::is_hpos_enabled_returns_false_when_order_util_not_available()`),
 * which cannot exercise the HPOS branch at all, so `Orders_Query::is_hpos_enabled()` was
 * made `protected` specifically so a test subclass can flip it deterministically.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Query;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Delivery_Status;

class ShippingOrdersQueryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubs( [ 'add_action', 'remove_action', 'add_filter', 'remove_filter' ] );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		Functions\when( 'wc_get_order_types' )->justReturn( [ 'shop_order' ] );
		Functions\when( 'wc_get_order_statuses' )->justReturn(
			[
				'wc-pending'    => 'Pending',
				'wc-processing' => 'Processing',
				'wc-cancelled'  => 'Cancelled',
				'wc-failed'     => 'Failed',
			]
		);
		Functions\when( 'wc_string_to_bool' )->alias(
			static function ( $value ): bool {
				return is_bool( $value ) ? $value : ( 'yes' === $value || 1 === $value || 'true' === $value || '1' === $value );
			}
		);

		Orders_Registry::instance()->reset_for_tests();
	}

	protected function tearDown(): void {
		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	private function provider( string $id, string $marker ): Orders_Provider {
		return Orders_Provider::create( $id, ucfirst( $id ), $marker, [ $id ] );
	}

	/**
	 * Builds an Orders_Query whose datastore detection is pinned to $hpos, bypassing
	 * the real (always-false-under-Brain-Monkey) static call. See the class docblock.
	 */
	private function query_with_hpos( bool $hpos, ?Orders_Registry $registry = null ): Orders_Query {
		return new class( $registry, $hpos ) extends Orders_Query {
			/** @var bool */
			private $hpos;

			public function __construct( ?Orders_Registry $registry, bool $hpos ) {
				parent::__construct( $registry );
				$this->hpos = $hpos;
			}

			protected function is_hpos_enabled(): bool {
				return $this->hpos;
			}
		};
	}

	// ----- HPOS: real meta_query -----

	public function test_hpos_single_carrier_builds_one_exists_clause(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider( 'cdek', '_cdek_marker' ) );
		$registry->register_provider( $this->provider( 'yandex', '_yandex_marker' ) );

		$args = $this->query_with_hpos( true, $registry )->build_args( [ 'carrier' => 'cdek' ] );

		$this->assertSame(
			[
				[
					'key'     => '_cdek_marker',
					'compare' => 'EXISTS',
				],
			],
			$args['meta_query']
		);
		$this->assertArrayNotHasKey( Orders_Query::QUERY_VAR_MARKER_KEYS, $args );
	}

	public function test_hpos_aggregate_builds_an_or_clause_per_registered_provider(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider( 'cdek', '_cdek_marker' ) );
		$registry->register_provider( $this->provider( 'yandex', '_yandex_marker' ) );

		$args = $this->query_with_hpos( true, $registry )->build_args( [ 'carrier' => 'all' ] );

		$this->assertSame(
			[
				'relation' => 'OR',
				[
					'key'     => '_cdek_marker',
					'compare' => 'EXISTS',
				],
				[
					'key'     => '_yandex_marker',
					'compare' => 'EXISTS',
				],
			],
			$args['meta_query']
		);
	}

	/**
	 * Omitting `carrier` entirely must behave exactly like `all` — the default.
	 */
	public function test_hpos_omitted_carrier_defaults_to_the_aggregate(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider( 'cdek', '_cdek_marker' ) );
		$registry->register_provider( $this->provider( 'yandex', '_yandex_marker' ) );

		$args = $this->query_with_hpos( true, $registry )->build_args( [] );

		$this->assertArrayHasKey( 'relation', $args['meta_query'] );
	}

	/**
	 * Zero providers must build a query that matches nothing, never one that matches
	 * everything (SP-10 spec M2).
	 */
	public function test_hpos_zero_providers_builds_a_query_that_matches_nothing(): void {
		$args = $this->query_with_hpos( true )->build_args( [ 'carrier' => 'all' ] );

		$this->assertSame( Orders_Query::NO_MATCH_META_QUERY, $args['meta_query'] );
	}

	/**
	 * An unrecognized single-carrier id must never silently fall back to the
	 * aggregate — same "matches nothing" shape as the zero-provider case.
	 */
	public function test_hpos_unknown_carrier_id_builds_a_query_that_matches_nothing(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider( 'cdek', '_cdek_marker' ) );

		$args = $this->query_with_hpos( true, $registry )->build_args( [ 'carrier' => 'ghost' ] );

		$this->assertSame( Orders_Query::NO_MATCH_META_QUERY, $args['meta_query'] );
	}

	// ----- Legacy CPT: no meta_query, the marker-keys query var instead (round 2) -----

	/**
	 * The regression itself: on the legacy CPT datastore `meta_query` must never be
	 * emitted at all — its mere presence is what fires `_doing_it_wrong` and makes
	 * WooCommerce silently ignore the whole arg, returning UNFILTERED results.
	 */
	public function test_legacy_cpt_single_carrier_never_emits_meta_query_and_carries_the_key(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider( 'cdek', '_cdek_marker' ) );
		$registry->register_provider( $this->provider( 'yandex', '_yandex_marker' ) );

		$args = $this->query_with_hpos( false, $registry )->build_args( [ 'carrier' => 'cdek' ] );

		$this->assertArrayNotHasKey( 'meta_query', $args );
		$this->assertSame( [ '_cdek_marker' ], $args[ Orders_Query::QUERY_VAR_MARKER_KEYS ] );
	}

	public function test_legacy_cpt_aggregate_never_emits_meta_query_and_carries_every_key(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider( 'cdek', '_cdek_marker' ) );
		$registry->register_provider( $this->provider( 'yandex', '_yandex_marker' ) );

		$args = $this->query_with_hpos( false, $registry )->build_args( [ 'carrier' => 'all' ] );

		$this->assertArrayNotHasKey( 'meta_query', $args );
		$this->assertSame( [ '_cdek_marker', '_yandex_marker' ], $args[ Orders_Query::QUERY_VAR_MARKER_KEYS ] );
	}

	/**
	 * The worst version of the bug (round 2 brief, point 3): on the legacy datastore a
	 * naive "just skip meta_query" fix would make "matches nothing" mean "matches
	 * everything". The var must still be present, carrying an EMPTY array, so the
	 * translation filter (tested on Orders_Registry) can turn it into a real
	 * "matches nothing" meta_query — never simply absent.
	 */
	public function test_legacy_cpt_zero_providers_carries_an_empty_marker_keys_array(): void {
		$args = $this->query_with_hpos( false )->build_args( [ 'carrier' => 'all' ] );

		$this->assertArrayNotHasKey( 'meta_query', $args );
		$this->assertArrayHasKey( Orders_Query::QUERY_VAR_MARKER_KEYS, $args );
		$this->assertSame( [], $args[ Orders_Query::QUERY_VAR_MARKER_KEYS ] );
	}

	public function test_legacy_cpt_unknown_carrier_carries_an_empty_marker_keys_array(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider( 'cdek', '_cdek_marker' ) );

		$args = $this->query_with_hpos( false, $registry )->build_args( [ 'carrier' => 'ghost' ] );

		$this->assertArrayNotHasKey( 'meta_query', $args );
		$this->assertSame( [], $args[ Orders_Query::QUERY_VAR_MARKER_KEYS ] );
	}

	// ----- Neutral (status/type/pagination) — identical on both datastores -----

	public function test_status_excludes_cancelled_and_failed(): void {
		$args = $this->query_with_hpos( true )->build_args( [] );

		$this->assertSame( [ 'wc-pending', 'wc-processing' ], $args['status'] );
	}

	public function test_type_is_view_orders_types(): void {
		$args = $this->query_with_hpos( true )->build_args( [] );

		$this->assertSame( [ 'shop_order' ], $args['type'] );
	}

	public function test_pagination_and_ordering_defaults(): void {
		$args = $this->query_with_hpos( true )->build_args( [] );

		$this->assertTrue( $args['paginate'] );
		$this->assertSame( 1, $args['paged'] );
		$this->assertSame( Orders_Query::DEFAULT_PER_PAGE, $args['limit'] );
		$this->assertSame( 'date', $args['orderby'] );
		$this->assertSame( 'DESC', $args['order'] );
		$this->assertArrayNotHasKey( 's', $args );
	}

	public function test_pagination_and_ordering_are_accepted_from_the_request(): void {
		$args = $this->query_with_hpos( true )->build_args(
			[
				'page'     => 3,
				'per_page' => 50,
				'orderby'  => 'ID',
				'order'    => 'asc',
				'search'   => 'ACME-100',
			]
		);

		$this->assertSame( 3, $args['paged'] );
		$this->assertSame( 50, $args['limit'] );
		$this->assertSame( 'ID', $args['orderby'] );
		$this->assertSame( 'ASC', $args['order'] );
		$this->assertSame( 'ACME-100', $args['s'] );
	}

	public function test_an_invalid_order_direction_falls_back_to_desc(): void {
		$args = $this->query_with_hpos( true )->build_args( [ 'order' => 'sideways' ] );

		$this->assertSame( 'DESC', $args['order'] );
	}

	// ----- meta_query_for_keys() — the shape shared by both datastore paths -----

	public function test_meta_query_for_keys_single_key_is_one_flat_exists_clause(): void {
		$this->assertSame(
			[
				[
					'key'     => '_cdek_marker',
					'compare' => 'EXISTS',
				],
			],
			Orders_Query::meta_query_for_keys( [ '_cdek_marker' ] )
		);
	}

	public function test_meta_query_for_keys_several_keys_is_relation_or(): void {
		$this->assertSame(
			[
				'relation' => 'OR',
				[
					'key'     => '_cdek_marker',
					'compare' => 'EXISTS',
				],
				[
					'key'     => '_yandex_marker',
					'compare' => 'EXISTS',
				],
			],
			Orders_Query::meta_query_for_keys( [ '_cdek_marker', '_yandex_marker' ] )
		);
	}

	public function test_meta_query_for_keys_empty_is_the_no_match_sentinel(): void {
		$this->assertSame( Orders_Query::NO_MATCH_META_QUERY, Orders_Query::meta_query_for_keys( [] ) );
	}

	// ----- date range: after/before (SP-10 spec D10/D11) -----

	public function test_after_only_builds_a_gte_bound(): void {
		$args = $this->query_with_hpos( true )->build_args( [ 'after' => '2026-01-15' ] );

		$this->assertSame( '>=2026-01-15', $args['date_created'] );
	}

	public function test_before_only_builds_a_lte_bound(): void {
		$args = $this->query_with_hpos( true )->build_args( [ 'before' => '2026-01-31' ] );

		$this->assertSame( '<=2026-01-31', $args['date_created'] );
	}

	public function test_after_and_before_build_the_ellipsis_range(): void {
		$args = $this->query_with_hpos( true )->build_args(
			[
				'after'  => '2026-01-01',
				'before' => '2026-01-31',
			]
		);

		$this->assertSame( '2026-01-01...2026-01-31', $args['date_created'] );
	}

	public function test_neither_bound_omits_date_created(): void {
		$args = $this->query_with_hpos( true )->build_args( [] );

		$this->assertArrayNotHasKey( 'date_created', $args );
	}

	public function test_a_malformed_date_is_ignored(): void {
		$args = $this->query_with_hpos( true )->build_args( [ 'after' => '15-01-2026' ] );

		$this->assertArrayNotHasKey( 'date_created', $args );
	}

	/**
	 * Format-valid but calendar-invalid (30 February does not exist) — checkdate()
	 * must reject it, not just the regex.
	 */
	public function test_a_calendar_invalid_date_is_ignored(): void {
		$args = $this->query_with_hpos( true )->build_args( [ 'before' => '2026-02-30' ] );

		$this->assertArrayNotHasKey( 'date_created', $args );
	}

	// ----- native WC order status override (SP-10 spec D10) -----

	public function test_an_explicit_valid_status_overrides_the_default_list(): void {
		$args = $this->query_with_hpos( true )->build_args( [ 'status' => [ 'wc-processing' ] ] );

		$this->assertSame( [ 'wc-processing' ], $args['status'] );
	}

	/**
	 * A status without its `wc-` prefix is tolerated — `wc_get_order_statuses()`
	 * keys always carry it, but a status is commonly referred to without it.
	 */
	public function test_a_status_without_the_wc_prefix_is_normalized(): void {
		$args = $this->query_with_hpos( true )->build_args( [ 'status' => [ 'processing' ] ] );

		$this->assertSame( [ 'wc-processing' ], $args['status'] );
	}

	/**
	 * An explicit status filter is NATIVE pass-through — it deliberately overrides
	 * the default cancelled/failed exclusion when the merchant asks for exactly that
	 * status.
	 */
	public function test_an_explicit_status_filter_can_include_cancelled(): void {
		$args = $this->query_with_hpos( true )->build_args( [ 'status' => [ 'wc-cancelled' ] ] );

		$this->assertSame( [ 'wc-cancelled' ], $args['status'] );
	}

	public function test_an_unrecognized_status_is_dropped_and_the_default_survives_if_nothing_else_valid(): void {
		$args = $this->query_with_hpos( true )->build_args( [ 'status' => [ 'not-a-real-status' ] ] );

		$this->assertSame( [ 'wc-pending', 'wc-processing' ], $args['status'] );
	}

	public function test_a_mixed_valid_and_invalid_status_list_keeps_only_the_valid_entries(): void {
		$args = $this->query_with_hpos( true )->build_args( [ 'status' => [ 'wc-processing', 'ghost-status' ] ] );

		$this->assertSame( [ 'wc-processing' ], $args['status'] );
	}

	// ----- delivery-status filter (SP-10 spec D10 — the inversion) -----

	private function provider_with_status(
		string $id,
		string $marker,
		?string $status_meta_key = null,
		array $status_map = []
	): Orders_Provider {
		return Orders_Provider::create(
			$id,
			ucfirst( $id ),
			$marker,
			[ $id ],
			[
				'status_meta_key' => $status_meta_key,
				'status_map'      => $status_map,
			]
		);
	}

	public function test_delivery_status_hpos_single_carrier_builds_an_in_clause_of_its_own_raw_values(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider(
			$this->provider_with_status(
				'cdek',
				'_cdek_marker',
				'_cdek_status',
				[
					'CDEK_ACCEPTED' => Delivery_Status::IN_TRANSIT,
					'CDEK_ENROUTE'  => Delivery_Status::IN_TRANSIT,
				]
			)
		);

		$args = $this->query_with_hpos( true, $registry )->build_args(
			[
				'carrier'         => 'cdek',
				'delivery_status' => Delivery_Status::IN_TRANSIT,
			]
		);

		$this->assertSame(
			[
				'relation' => 'AND',
				[
					[
						'key'     => '_cdek_marker',
						'compare' => 'EXISTS',
					],
				],
				[
					[
						'key'     => '_cdek_status',
						'value'   => [ 'CDEK_ACCEPTED', 'CDEK_ENROUTE' ],
						'compare' => 'IN',
					],
				],
			],
			$args['meta_query']
		);
	}

	public function test_delivery_status_hpos_aggregate_ors_across_participating_providers(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider_with_status( 'cdek', '_cdek_marker', '_cdek_status', [ 'CDEK_DONE' => Delivery_Status::DELIVERED ] ) );
		$registry->register_provider( $this->provider_with_status( 'yandex', '_yandex_marker', '_yandex_status', [ 'YAN_DONE' => Delivery_Status::DELIVERED ] ) );

		$args = $this->query_with_hpos( true, $registry )->build_args(
			[
				'carrier'         => 'all',
				'delivery_status' => Delivery_Status::DELIVERED,
			]
		);

		$this->assertSame(
			[
				'key'     => '_cdek_status',
				'value'   => [ 'CDEK_DONE' ],
				'compare' => 'IN',
			],
			$args['meta_query'][1][0]
		);
		$this->assertSame(
			[
				'key'     => '_yandex_status',
				'value'   => [ 'YAN_DONE' ],
				'compare' => 'IN',
			],
			$args['meta_query'][1][1]
		);
		$this->assertSame( 'OR', $args['meta_query'][1]['relation'] );
	}

	/**
	 * A provider with no status concept at all is ALWAYS unknown — its marker key
	 * (guaranteed present within scope) stands in for "always true".
	 */
	public function test_delivery_status_unknown_matches_a_provider_with_no_status_concept_via_its_marker_key(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider_with_status( 'novendor', '_novendor_marker' ) );

		$args = $this->query_with_hpos( true, $registry )->build_args(
			[
				'carrier'         => 'novendor',
				'delivery_status' => Delivery_Status::UNKNOWN,
			]
		);

		$this->assertSame(
			[
				'key'     => '_novendor_marker',
				'compare' => 'EXISTS',
			],
			$args['meta_query'][1][0]
		);
	}

	/**
	 * `unknown` against a provider WITH a status concept: a `NOT IN` of its own
	 * known-good raw values — covers both "no meta at all" and "an unmapped raw
	 * value" in one clause (WordPress's `meta_query` LEFT JOINs for negative
	 * compares).
	 */
	public function test_delivery_status_unknown_against_a_real_status_map_builds_not_in_known_values(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider(
			$this->provider_with_status( 'cdek', '_cdek_marker', '_cdek_status', [ 'CDEK_ACCEPTED' => Delivery_Status::IN_TRANSIT ] )
		);

		$args = $this->query_with_hpos( true, $registry )->build_args(
			[
				'carrier'         => 'cdek',
				'delivery_status' => Delivery_Status::UNKNOWN,
			]
		);

		$this->assertSame(
			[
				'key'     => '_cdek_status',
				'value'   => [ 'CDEK_ACCEPTED' ],
				'compare' => 'NOT IN',
			],
			$args['meta_query'][1][0]
		);
	}

	/**
	 * A carrier that CANNOT ever report a given canonical state (its status_map
	 * never maps to it) contributes no clause — the filter must match nothing, not
	 * silently fall back to the unfiltered scope.
	 */
	public function test_delivery_status_no_participating_provider_builds_the_no_match_sentinel(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider(
			$this->provider_with_status( 'cdek', '_cdek_marker', '_cdek_status', [ 'CDEK_ACCEPTED' => Delivery_Status::IN_TRANSIT ] )
		);

		$args = $this->query_with_hpos( true, $registry )->build_args(
			[
				'carrier'         => 'cdek',
				'delivery_status' => Delivery_Status::DELIVERED,
			]
		);

		$this->assertSame( Orders_Query::NO_MATCH_META_QUERY, $args['meta_query'][1] );
	}

	public function test_delivery_status_unrecognized_value_is_ignored_entirely(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider( 'cdek', '_cdek_marker' ) );

		$args = $this->query_with_hpos( true, $registry )->build_args(
			[
				'carrier'         => 'cdek',
				'delivery_status' => 'not-a-real-canonical-state',
			]
		);

		// Scope-only shape survives byte for byte — no extra AND part was added.
		$this->assertSame(
			[
				[
					'key'     => '_cdek_marker',
					'compare' => 'EXISTS',
				],
			],
			$args['meta_query']
		);
	}

	public function test_delivery_status_legacy_cpt_carries_the_status_clauses_query_var_not_meta_query(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider(
			$this->provider_with_status( 'cdek', '_cdek_marker', '_cdek_status', [ 'CDEK_DONE' => Delivery_Status::DELIVERED ] )
		);

		$args = $this->query_with_hpos( false, $registry )->build_args(
			[
				'carrier'         => 'cdek',
				'delivery_status' => Delivery_Status::DELIVERED,
			]
		);

		$this->assertArrayNotHasKey( 'meta_query', $args );
		$this->assertSame(
			[
				[
					'key'     => '_cdek_status',
					'value'   => [ 'CDEK_DONE' ],
					'compare' => 'IN',
				],
			],
			$args[ Orders_Query::QUERY_VAR_STATUS_CLAUSES ]
		);
	}

	// ----- tracking-presence filter (SP-10 spec D10) -----

	public function test_has_tracking_true_builds_an_exists_clause(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider(
			Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ], [ 'tracking_meta_key' => '_cdek_tracking' ] )
		);

		$args = $this->query_with_hpos( true, $registry )->build_args(
			[
				'carrier'      => 'cdek',
				'has_tracking' => true,
			]
		);

		$this->assertSame(
			[
				[
					'key'     => '_cdek_tracking',
					'compare' => 'EXISTS',
				],
			],
			$args['meta_query'][1]
		);
	}

	public function test_has_tracking_false_builds_a_not_exists_clause(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider(
			Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ], [ 'tracking_meta_key' => '_cdek_tracking' ] )
		);

		$args = $this->query_with_hpos( true, $registry )->build_args(
			[
				'carrier'      => 'cdek',
				'has_tracking' => false,
			]
		);

		$this->assertSame(
			[
				[
					'key'     => '_cdek_tracking',
					'compare' => 'NOT EXISTS',
				],
			],
			$args['meta_query'][1]
		);
	}

	/**
	 * A carrier without a tracking concept at all can never report `true`.
	 */
	public function test_has_tracking_true_for_a_carrier_without_tracking_builds_the_no_match_sentinel(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider( 'cdek', '_cdek_marker' ) );

		$args = $this->query_with_hpos( true, $registry )->build_args(
			[
				'carrier'      => 'cdek',
				'has_tracking' => true,
			]
		);

		$this->assertSame( Orders_Query::NO_MATCH_META_QUERY, $args['meta_query'][1] );
	}

	/**
	 * The same carrier ALWAYS counts as "no tracking" — never having any tracking
	 * concept means it never has a tracking number either.
	 */
	public function test_has_tracking_false_for_a_carrier_without_tracking_matches_via_its_marker_key(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider( 'cdek', '_cdek_marker' ) );

		$args = $this->query_with_hpos( true, $registry )->build_args(
			[
				'carrier'      => 'cdek',
				'has_tracking' => false,
			]
		);

		$this->assertSame(
			[
				[
					'key'     => '_cdek_marker',
					'compare' => 'EXISTS',
				],
			],
			$args['meta_query'][1]
		);
	}

	public function test_has_tracking_absent_never_adds_a_meta_query_part(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider( 'cdek', '_cdek_marker' ) );

		$args = $this->query_with_hpos( true, $registry )->build_args( [ 'carrier' => 'cdek' ] );

		$this->assertSame(
			[
				[
					'key'     => '_cdek_marker',
					'compare' => 'EXISTS',
				],
			],
			$args['meta_query']
		);
	}

	/**
	 * `has_param()`-style tri-state: an EXPLICIT `false` must still filter, not be
	 * mistaken for "absent" — read via `array_key_exists()`, not `isset()`.
	 */
	public function test_has_tracking_explicit_false_is_not_mistaken_for_absent(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider(
			Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ], [ 'tracking_meta_key' => '_cdek_tracking' ] )
		);

		$args = $this->query_with_hpos( true, $registry )->build_args(
			[
				'carrier'      => 'cdek',
				'has_tracking' => false,
			]
		);

		$this->assertArrayHasKey( 'meta_query', $args );
		$this->assertCount( 3, $args['meta_query'] ); // relation + scope part + tracking part.
	}

	public function test_has_tracking_legacy_cpt_carries_the_tracking_clauses_query_var_not_meta_query(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider(
			Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ], [ 'tracking_meta_key' => '_cdek_tracking' ] )
		);

		$args = $this->query_with_hpos( false, $registry )->build_args(
			[
				'carrier'      => 'cdek',
				'has_tracking' => true,
			]
		);

		$this->assertArrayNotHasKey( 'meta_query', $args );
		$this->assertSame(
			[
				[
					'key'     => '_cdek_tracking',
					'compare' => 'EXISTS',
				],
			],
			$args[ Orders_Query::QUERY_VAR_TRACKING_CLAUSES ]
		);
	}

	// ----- combining more than one filter -----

	/**
	 * Delivery-status AND tracking-presence together must both be ANDed with the
	 * scope — three independent parts, none of them merging another's `relation`.
	 */
	public function test_delivery_status_and_has_tracking_together_and_with_the_scope(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider(
			Orders_Provider::create(
				'cdek',
				'СДЭК',
				'_cdek_marker',
				[ 'cdek' ],
				[
					'status_meta_key'   => '_cdek_status',
					'status_map'        => [ 'CDEK_DONE' => Delivery_Status::DELIVERED ],
					'tracking_meta_key' => '_cdek_tracking',
				]
			)
		);

		$args = $this->query_with_hpos( true, $registry )->build_args(
			[
				'carrier'         => 'cdek',
				'delivery_status' => Delivery_Status::DELIVERED,
				'has_tracking'    => true,
			]
		);

		$this->assertSame( 'AND', $args['meta_query']['relation'] );
		$this->assertCount( 4, $args['meta_query'] ); // relation + scope part + status part + tracking part.
	}
}
