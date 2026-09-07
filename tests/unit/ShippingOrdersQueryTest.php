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

		Orders_Registry::instance()->reset_for_tests();
	}

	protected function tearDown(): void {
		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	private function provider( string $id, string $marker ): Orders_Provider {
		return Orders_Provider::create( $id, ucfirst( $id ), $marker );
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
}
