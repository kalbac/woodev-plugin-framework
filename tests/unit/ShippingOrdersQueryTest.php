<?php
/**
 * Unit: Orders_Query args-building (SP-10 increment 1, spec M2 + round 2; #928).
 *
 * Pins the TREE Orders_Query::build_meta_query() builds and the ARGS build_args() hands
 * to wc_get_orders() — never wc_get_orders() itself. The aggregate `relation => OR`
 * shape was measured against a real HPOS install on the rig (SP-10 spec M2); this
 * file only pins that the class still asks for it.
 *
 * #928: the tree no longer reaches the datastore as `meta_query`. build_args() resolves
 * it to order ids through the `protected` seam `Orders_Query::resolve_order_ids()` and
 * passes them as `post__in`; a `meta_query` (HPOS) or the empty marker-keys var (legacy
 * CPT) is emitted only to say "matches nothing". So the shape tests read the tree from
 * build_meta_query(), and the args tests run against a subclass whose resolver returns
 * a fixed id list ({@see self::query_with_hpos()}) — the real one would need `$wpdb`.
 *
 * Round 2: the legacy CPT order datastore does not support `meta_query` at all (fires
 * `_doing_it_wrong` and silently returns UNFILTERED results), so the sentinel branches
 * on the datastore — exercised through the same subclass, which also overrides the real
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
use Woodev\Framework\Shipping\Admin\Orders\Order_Actions;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Query;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Delivery_Status;
use Woodev\Framework\Shipping\Order\Shipment_Cancellation;

class ShippingOrdersQueryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubs( [ 'add_action', 'remove_action', 'add_filter', 'remove_filter' ] );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		Functions\when( 'wc_get_order_types' )->justReturn( [ 'shop_order' ] );
		Functions\when( 'get_post_status_object' )->justReturn( null ); // no post status objects: every status is kept (#1011).
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
	 * A registry with ONE provider, for tests whose subject is not the carrier scope.
	 *
	 * ⚠ Needed because an EMPTY registry already produces `NO_MATCH_META_QUERY` on its
	 * own: a test that asserts "the query matches nothing" against no providers passes
	 * whatever the code under test does, and stays green when the fix is reverted. Found
	 * by the s128 critic on exactly the three status tests below — the assertion was
	 * about the fixture, not about the behaviour.
	 */
	private function registry_with_one_provider(): Orders_Registry {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider( 'cdek', '_cdek_marker' ) );

		return $registry;
	}

	/** The ids the stubbed resolver answers with, unless a test asks for another list. */
	private const STUB_IDS = [ 11, 12, 13 ];

	/**
	 * Builds an Orders_Query whose datastore detection is pinned to $hpos, bypassing
	 * the real (always-false-under-Brain-Monkey) static call, and whose id resolver
	 * answers $ids without a database, recording what it was asked (`->resolved`,
	 * one `[ marker keys, tree ]` pair per call). See the class docblock.
	 *
	 * @return Orders_Query&object{resolved:array<int,array{0:string[],1:array<int|string,mixed>}>}
	 */
	private function query_with_hpos( bool $hpos, ?Orders_Registry $registry = null, array $ids = self::STUB_IDS ): Orders_Query {
		return new class( $registry, $hpos, $ids ) extends Orders_Query {
			/** @var bool */
			private $hpos;

			/** @var int[] */
			private $ids;

			/** @var array<int,array{0:string[],1:array<int|string,mixed>}> */
			public $resolved = [];

			public function __construct( ?Orders_Registry $registry, bool $hpos, array $ids ) {
				parent::__construct( $registry );
				$this->hpos = $hpos;
				$this->ids  = $ids;
			}

			protected function is_hpos_enabled(): bool {
				return $this->hpos;
			}

			protected function resolve_order_ids( array $marker_keys, array $meta_query ): array {
				$this->resolved[] = [ $marker_keys, $meta_query ];

				return $this->ids;
			}
		};
	}

	/**
	 * Splits a combined tree into its top-level parts, regardless of which shape
	 * {@see Orders_Query::combine_meta_queries()} returned: a single unwrapped part
	 * (the scope alone), or the `relation => AND` wrapper around several.
	 *
	 * The PRESENCE of a `relation` key does not tell the two apart: the scope alone
	 * carries its own top-level `relation => OR` with two carriers, and branching on
	 * `array_key_exists( 'relation', … )` alone then shreds that OR-group into fake
	 * top-level parts. What IS unambiguous is the wrapper's own shape: {@see
	 * Orders_Query::combine_meta_queries()} only ever builds it as
	 * `array_merge( [ 'relation' => 'AND' ], $parts )` with the marker-key scope part
	 * ALWAYS first — {@see Orders_Query::build_meta_query()} pushes it before any
	 * filter part is added (since #928 unconditionally: the id query's driver, not a
	 * join, so the #839 redundancy drop is gone). So the wrapper is recognised by its
	 * known first child being byte-equal to the scope part built from the SAME marker
	 * keys — never by matching the scope's shape against every child, which is what
	 * misreads a filter part that happens to equal the scope on its own (see
	 * {@see self::meta_query_filter_part()}).
	 *
	 * @param array<int|string, mixed> $meta_query
	 * @param array<int|string, mixed> $scope the marker-key scope part built from the SAME marker keys ({@see Orders_Query::meta_query_for_keys()}), used to recognise the wrapper by its known first child.
	 * @return array<int, array<int|string, mixed>>
	 */
	private function meta_query_top_level_parts( array $meta_query, array $scope ): array {
		if ( 'AND' === ( $meta_query['relation'] ?? null ) && ( $meta_query[0] ?? null ) === $scope ) {
			unset( $meta_query['relation'] );

			return array_values( $meta_query );
		}

		return [ $meta_query ];
	}

	/**
	 * Returns the single FILTER part of a combined `meta_query` built from the
	 * marker-key SCOPE part plus exactly one active filter — every call site in
	 * this file that reaches for a specific filter part combines just those
	 * two. Located by ROLE via {@see self::meta_query_top_level_parts()}: the
	 * scope is always the first of exactly two top-level parts (never found by
	 * matching its shape against the filter's own content, which breaks the moment
	 * a filter part is itself shaped like the scope — see that method's docblock),
	 * so the filter is the second part by position.
	 *
	 * @param array<int|string, mixed> $meta_query
	 * @param string[]                 $scope_marker_keys marker keys of the providers in scope for the request.
	 * @return array<int|string, mixed>
	 */
	private function meta_query_filter_part( array $meta_query, array $scope_marker_keys ): array {
		$scope = Orders_Query::meta_query_for_keys( $scope_marker_keys );
		$parts = $this->meta_query_top_level_parts( $meta_query, $scope );

		self::assertLessThanOrEqual(
			2,
			count( $parts ),
			'This helper resolves the scope plus exactly ONE filter part. A query combining two filters needs a helper that names WHICH filter it wants, or this silently returns the first of them.'
		);

		return 1 === count( $parts ) ? $parts[0] : $parts[1];
	}

	/**
	 * The status-map half of a delivery-status filter part that must EXCLUDE cancelled orders (#1037).
	 *
	 * Asserts the wrapper the builder puts around it — `AND( <status-map clauses>, marker NOT EXISTS )` — and
	 * hands back the clauses inside it in the shape the part had before the marker existed: the OR group of a
	 * multi-provider filter, or a one-clause list for a single provider.
	 *
	 * @param array<int|string, mixed> $filter_part the part {@see self::meta_query_filter_part()} found.
	 * @return array<int|string, mixed>
	 */
	private function status_map_part( array $filter_part ): array {
		$this->assertCount( 1, $filter_part, 'one wrapper clause' );
		$wrapper = $filter_part[0];

		$this->assertSame( 'AND', $wrapper['relation'] );
		$this->assertSame(
			[
				'key'     => Shipment_Cancellation::CANCELLED_AT_META,
				'compare' => 'NOT EXISTS',
			],
			$wrapper[1],
			'a cancelled order must not match a state its raw status maps to'
		);

		// A provider's own clause is a leaf or an AND, never an OR: an OR here is the group of several providers.
		return 'OR' === ( $wrapper[0]['relation'] ?? '' ) ? $wrapper[0] : [ $wrapper[0] ];
	}

	// ----- HPOS: real meta_query -----

	public function test_hpos_single_carrier_builds_one_exists_clause(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider( 'cdek', '_cdek_marker' ) );
		$registry->register_provider( $this->provider( 'yandex', '_yandex_marker' ) );

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query( [ 'carrier' => 'cdek' ] );

		$this->assertSame(
			[
				[
					'key'     => '_cdek_marker',
					'compare' => 'EXISTS',
				],
			],
			$tree
		);
	}

	public function test_hpos_aggregate_builds_an_or_clause_per_registered_provider(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider( 'cdek', '_cdek_marker' ) );
		$registry->register_provider( $this->provider( 'yandex', '_yandex_marker' ) );

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query( [ 'carrier' => 'all' ] );

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
			$tree
		);
	}

	/**
	 * Omitting `carrier` entirely must behave exactly like `all` — the default.
	 */
	public function test_hpos_omitted_carrier_defaults_to_the_aggregate(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider( 'cdek', '_cdek_marker' ) );
		$registry->register_provider( $this->provider( 'yandex', '_yandex_marker' ) );

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query( [] );

		$this->assertArrayHasKey( 'relation', $tree );
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

	// ----- #928: the tree is resolved to ids and travels as post__in, on BOTH datastores -----

	/** @return array<string,array{0:bool}> */
	public function datastore_provider(): array {
		return [
			'HPOS'       => [ true ],
			'legacy CPT' => [ false ],
		];
	}

	/**
	 * The re-form itself (#928): neither datastore ever sees the scope as a
	 * `meta_query` (whose OR-ed marker `EXISTS` clauses cost one un-predicated join
	 * each, `~d^N`) nor as the marker-keys query var — the ids the resolver returned
	 * are the whole scope, as `post__in`. On the legacy CPT datastore that is ALSO the
	 * round-2 regression guard: `meta_query`'s mere presence fires `_doing_it_wrong`
	 * there and WooCommerce silently returns the UNFILTERED table.
	 *
	 * @dataProvider datastore_provider
	 */
	public function test_the_resolved_ids_travel_as_post__in_and_nothing_else_scopes_the_query( bool $hpos ): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider( 'cdek', '_cdek_marker' ) );
		$registry->register_provider( $this->provider( 'yandex', '_yandex_marker' ) );

		$query = $this->query_with_hpos( $hpos, $registry );
		$args  = $query->build_args( [ 'carrier' => 'cdek' ] );

		$this->assertSame( self::STUB_IDS, $args['post__in'] );
		$this->assertArrayNotHasKey( 'meta_query', $args );
		$this->assertArrayNotHasKey( Orders_Query::QUERY_VAR_MARKER_KEYS, $args );
	}

	/**
	 * What the resolver is handed is exactly the marker keys in scope and the tree
	 * {@see Orders_Query::build_meta_query()} builds for the same request — so a shape
	 * test on the tree is a test of what the id query will resolve, on both datastores.
	 *
	 * @dataProvider datastore_provider
	 */
	public function test_the_resolver_is_handed_the_marker_keys_and_the_built_tree( bool $hpos ): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider( 'cdek', '_cdek_marker' ) );
		$registry->register_provider( $this->provider( 'yandex', '_yandex_marker' ) );

		$request = [
			'carrier'      => 'all',
			'has_tracking' => false,
		];

		$query = $this->query_with_hpos( $hpos, $registry );
		$query->build_args( $request );

		$this->assertCount( 1, $query->resolved, 'exactly one id query per build' );
		$this->assertSame( [ '_cdek_marker', '_yandex_marker' ], $query->resolved[0][0] );
		$this->assertSame( $query->build_meta_query( $request ), $query->resolved[0][1] );
	}

	/**
	 * ⚠ An EMPTY `post__in` fails OPEN on both datastores — HPOS reads `[]` as "argument
	 * not set" (`OrdersTableQuery::SKIPPED_VALUES`), `WP_Query` tests the var for truth —
	 * so "the id query found no order" must become the one "matches nothing" sentinel
	 * each datastore already has, never an empty list.
	 *
	 * @dataProvider datastore_provider
	 */
	public function test_an_empty_id_result_becomes_the_sentinel_never_an_empty_post__in( bool $hpos ): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider( 'cdek', '_cdek_marker' ) );

		$query = $this->query_with_hpos( $hpos, $registry, [] );
		$args  = $query->build_args( [ 'carrier' => 'cdek' ] );

		$this->assertCount( 1, $query->resolved, 'the resolver WAS asked — the scope itself is not empty' );
		$this->assertArrayNotHasKey( 'post__in', $args );

		if ( $hpos ) {
			$this->assertSame( Orders_Query::NO_MATCH_META_QUERY, $args['meta_query'] );
			$this->assertArrayNotHasKey( Orders_Query::QUERY_VAR_MARKER_KEYS, $args );
		} else {
			$this->assertArrayNotHasKey( 'meta_query', $args );
			$this->assertSame( [], $args[ Orders_Query::QUERY_VAR_MARKER_KEYS ] );
		}
	}

	/**
	 * A tree that can match nothing — no provider in scope, or a filter no provider can
	 * satisfy — is answered without asking the database at all.
	 *
	 * @dataProvider datastore_provider
	 */
	public function test_a_tree_that_matches_nothing_is_never_resolved( bool $hpos ): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider( 'cdek', '_cdek_marker' ) );

		$unknown_carrier = $this->query_with_hpos( $hpos, $registry );
		$unknown_carrier->build_args( [ 'carrier' => 'ghost' ] );

		$this->assertSame( [], $unknown_carrier->resolved, 'an unrecognised carrier is known to match nothing' );

		$no_participant = $this->query_with_hpos( $hpos, $registry );
		$no_participant->build_args(
			[
				'carrier'      => 'cdek',
				'has_tracking' => true, // this carrier has no tracking concept => the sentinel part.
			]
		);

		$this->assertSame( [], $no_participant->resolved, 'a filter no provider can satisfy is known to match nothing' );
	}

	/**
	 * The #839 step-2 rule is REVERSED on purpose: it dropped the scope part whenever
	 * every filter clause already bound its own marker, to save one join per carrier.
	 * In the id query the scope is the driver — one index range on `meta_key` — so it
	 * costs nothing to keep and it always comes first, which is what lets the resolver
	 * fold it into the driver predicate.
	 */
	public function test_the_scope_part_stays_first_even_when_every_clause_binds_its_own_marker(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider(
			$this->provider_with_status( 'cdek', '_cdek_marker', '_cdek_status', [ 'CDEK_ACCEPTED' => Delivery_Status::IN_TRANSIT ] )
		);
		$registry->register_provider(
			$this->provider_with_status( 'yandex', '_yandex_marker', '_yandex_status', [ 'YA_SHIPPED' => Delivery_Status::IN_TRANSIT ] )
		);

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query( [ 'delivery_status' => Delivery_Status::UNKNOWN ] );

		$this->assertSame( 'AND', $tree['relation'] );
		$this->assertSame( Orders_Query::meta_query_for_keys( [ '_cdek_marker', '_yandex_marker' ] ), $tree[0] );
		$this->assertCount( 3, $tree ); // relation + scope part + status part.
	}

	// ----- Legacy CPT: "matches nothing" is the EMPTY marker-keys query var (round 2) -----

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

	/**
	 * #1011 (operator, 01.10.2026): the default view is an order LIST — every status, cancelled
	 * and failed included. v1's export-queue exclusion is gone, and a cancelled order keeps its
	 * «not cancelled at the carrier» marker on screen.
	 */
	public function test_the_default_view_shows_every_status_cancelled_and_failed_included(): void {
		$args = $this->query_with_hpos( true )->build_args( [] );

		$this->assertSame( [ 'wc-pending', 'wc-processing', 'wc-cancelled', 'wc-failed' ], $args['status'] );
	}

	/**
	 * Shop with `wc-checkout-draft` (WooCommerce lists it in `wc_get_order_statuses()`, but its post
	 * status object has `show_in_admin_all_list = false`, so WC's own «All» list hides it) and a
	 * custom status with no post status object at all.
	 */
	private function stub_a_shop_with_a_checkout_draft(): void {
		Functions\when( 'wc_get_order_statuses' )->justReturn(
			[
				'wc-pending'        => 'Pending',
				'wc-checkout-draft' => 'Draft',
				'wc-cancelled'      => 'Cancelled',
				'wc-custom'         => 'Custom (HPOS, no post status object)',
			]
		);
		Functions\when( 'get_post_status_object' )->alias(
			static function ( string $status ): ?object {
				$flags = [
					'wc-pending'        => true,
					'wc-checkout-draft' => false,
					'wc-cancelled'      => true,
				];

				return array_key_exists( $status, $flags ) ? (object) [ 'show_in_admin_all_list' => $flags[ $status ] ] : null;
			}
		);
	}

	/** #1011: the default view mirrors WC's own «All» list — a status hidden from it there is hidden here. */
	public function test_the_default_view_leaves_out_a_status_hidden_from_the_wc_all_list(): void {
		$this->stub_a_shop_with_a_checkout_draft();

		$args = $this->query_with_hpos( true )->build_args( [] );

		$this->assertSame( [ 'wc-pending', 'wc-cancelled', 'wc-custom' ], $args['status'] );
		$this->assertNotContains( 'wc-checkout-draft', $args['status'] );
	}

	public function test_the_new_scope_leaves_out_the_hidden_status_too(): void {
		$this->stub_a_shop_with_a_checkout_draft();

		$args = $this->query_with_hpos( true )->build_args( [ 'is_exported' => false ] );

		// A custom status is not exportable either (#1024), so only `pending` survives of this shop's four.
		$this->assertSame( [ 'wc-pending' ], $args['status'] );
	}

	/** The explicit filter is validated against the FULL status list, so the hidden status stays reachable. */
	public function test_an_explicit_status_filter_can_still_request_the_hidden_status(): void {
		$this->stub_a_shop_with_a_checkout_draft();

		$query = $this->query_with_hpos( true );

		$this->assertSame( [ 'wc-checkout-draft' ], $query->build_args( [ 'status' => [ 'checkout-draft' ] ] )['status'] );
		$this->assertContains( 'wc-checkout-draft', $query->build_args( [ 'status_not' => [ 'wc-pending' ] ] )['status'] );
	}

	/** An absent `is_exported` and an «exported» one are both the default view, not the «new» scope. */
	public function test_only_the_new_scope_narrows_the_default_status_list(): void {
		$query = $this->query_with_hpos( true );

		$this->assertSame(
			[ 'wc-pending', 'wc-processing', 'wc-cancelled', 'wc-failed' ],
			$query->build_args( [ 'is_exported' => true ] )['status']
		);
		$this->assertSame(
			[ 'wc-pending', 'wc-processing', 'wc-cancelled', 'wc-failed' ],
			$query->build_args( [ 'carrier' => 'all' ] )['status']
		);
	}

	/**
	 * «Новые» and the menu badge count WORK TO DO: an unexported order that cannot be exported
	 * (cancelled, failed — and, #1024, completed or refunded) is not. Both ask `Orders_Query`
	 * with `is_exported = false`, so one rule here keeps them in agreement — the badge request
	 * is {@see ShippingOrdersRegistryTest::badge_request()}.
	 */
	public function test_the_new_scope_keeps_cancelled_and_failed_out_of_its_default_status_list(): void {
		$args = $this->query_with_hpos( true )->build_args( [ 'is_exported' => false ] );

		$this->assertSame( [ 'wc-pending', 'wc-processing' ], $args['status'] );
	}

	/**
	 * A shop with every status the framework page can meet — the four the default stub knows plus
	 * `on-hold`, `completed` and `refunded`.
	 */
	private function stub_a_shop_with_every_status(): void {
		Functions\when( 'wc_get_order_statuses' )->justReturn(
			[
				'wc-pending'    => 'Pending',
				'wc-processing' => 'Processing',
				'wc-on-hold'    => 'On hold',
				'wc-completed'  => 'Completed',
				'wc-cancelled'  => 'Cancelled',
				'wc-refunded'   => 'Refunded',
				'wc-failed'     => 'Failed',
			]
		);
	}

	/** #1024 (operator, 01.10.2026): a completed or refunded order nobody exported is not work either. */
	public function test_the_new_scope_leaves_out_completed_and_refunded_but_the_default_view_keeps_them(): void {
		$this->stub_a_shop_with_every_status();

		$query = $this->query_with_hpos( true );
		$new   = $query->build_args( [ 'is_exported' => false ] )['status'];
		$all   = $query->build_args( [] )['status'];

		$this->assertSame( [ 'wc-pending', 'wc-processing', 'wc-on-hold' ], $new );
		$this->assertNotContains( 'wc-completed', $new );
		$this->assertNotContains( 'wc-refunded', $new );
		$this->assertContains( 'wc-completed', $all );
		$this->assertContains( 'wc-refunded', $all );
		$this->assertContains( 'wc-cancelled', $all );
		$this->assertContains( 'wc-failed', $all );
	}

	/**
	 * The «new» set IS `Order_Actions::EXPORTABLE_STATUSES` (prefixed) — the list the «Выгрузить» button
	 * reads — not a second hand-typed list. Pinned on a shop that registers every status, so the
	 * intersection can only be as wide as the constant says.
	 */
	public function test_the_new_scope_is_exactly_the_exportable_statuses(): void {
		$this->stub_a_shop_with_every_status();

		$expected = array_map(
			static function ( string $status ): string {
				return 'wc-' . $status;
			},
			Order_Actions::EXPORTABLE_STATUSES
		);
		$actual   = $this->query_with_hpos( true )->build_args( [ 'is_exported' => false ] )['status'];

		$this->assertEqualsCanonicalizing( $expected, $actual );
		$this->assertNotSame( [], $actual );
	}

	/** The scope is read the way `build_scope()` reads it — `wc_string_to_bool()` — so `'false'` is «new» too. */
	public function test_the_new_scope_is_recognised_from_a_query_string_value(): void {
		$args = $this->query_with_hpos( true )->build_args( [ 'is_exported' => 'false' ] );

		$this->assertSame( [ 'wc-pending', 'wc-processing' ], $args['status'] );
	}

	/** #1024: an explicit status filter applies ON TOP of the «new» scope — the intersection, never past it. */
	public function test_an_explicit_status_is_intersected_with_the_new_scope(): void {
		$this->stub_a_shop_with_every_status();

		$args = $this->query_with_hpos( true )->build_args(
			[
				'is_exported' => false,
				'status'      => [ 'wc-processing', 'wc-completed' ],
			]
		);

		$this->assertSame( [ 'wc-processing' ], $args['status'] );
	}

	/** `status_not` is the complement of the full list; the «new» scope still cuts it down to the exportable ones. */
	public function test_status_not_is_intersected_with_the_new_scope(): void {
		$this->stub_a_shop_with_every_status();

		$args = $this->query_with_hpos( true )->build_args(
			[
				'is_exported' => false,
				'status_not'  => [ 'wc-pending' ],
			]
		);

		$this->assertSame( [ 'wc-processing', 'wc-on-hold' ], $args['status'] );
	}

	/** A status filter that names only non-exportable statuses selects nothing under «Новые». */
	public function test_an_explicit_status_with_nothing_exportable_narrows_the_new_scope_to_nothing(): void {
		$registry = $this->registry_with_one_provider();

		$args = $this->query_with_hpos( true, $registry )->build_args(
			[
				'is_exported' => false,
				'status'      => [ 'wc-cancelled' ],
			]
		);

		$this->assertSame( Orders_Query::NO_MATCH_META_QUERY, $args['meta_query'] );
		$this->assertArrayNotHasKey( 'post__in', $args );
	}

	/** The same explicit status without the «new» scope still reaches cancelled orders («Все»). */
	public function test_an_explicit_cancelled_status_still_works_outside_the_new_scope(): void {
		$args = $this->query_with_hpos( true )->build_args( [ 'status' => [ 'wc-cancelled' ] ] );

		$this->assertSame( [ 'wc-cancelled' ], $args['status'] );
	}

	/** Filtering by `processing` returns processing orders only — the default list is not merged in. */
	public function test_filtering_by_processing_does_not_carry_cancelled_orders(): void {
		$args = $this->query_with_hpos( true )->build_args( [ 'status' => [ 'processing' ] ] );

		$this->assertSame( [ 'wc-processing' ], $args['status'] );
		$this->assertNotContains( 'wc-cancelled', $args['status'] );
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

	/**
	 * A status filter the merchant DID request, in which nothing is a real status,
	 * must narrow to nothing — never widen back to the default list (#837 defect 3).
	 *
	 * ⚠ This test asserted the opposite until the rig disproved it: `status=["nonsense"]`
	 * returned all 71 rows, because "requested but nothing recognized" was indistinguishable
	 * from "nothing requested".
	 *
	 * ⚠ And the SHAPE of the answer matters as much as the answer. Two mechanisms were tried
	 * and both are wrong: an empty status array (HPOS's `OrdersTableQuery::sanitize_status()`
	 * expands it into EVERY valid status) and a bogus status slug (empties the table on HPOS,
	 * does nothing on the legacy CPT datastore, where `WP_Query` drops unregistered statuses
	 * and the condition vanishes — caught by the integration suite, not by this file). What
	 * survives is the one "matches nothing" mechanism both datastore paths already share.
	 */
	public function test_a_status_request_with_nothing_recognized_narrows_to_nothing(): void {
		$registry = $this->registry_with_one_provider();

		$args = $this->query_with_hpos( true, $registry )->build_args( [ 'status' => [ 'not-a-real-status' ] ] );

		$this->assertSame( Orders_Query::NO_MATCH_META_QUERY, $args['meta_query'] );
		$this->assertSame( [ 'wc-pending', 'wc-processing', 'wc-cancelled', 'wc-failed' ], $args['status'], 'The status arg itself is left alone — the narrowing is expressed in the meta_query.' );
	}

	/**
	 * The operator's own reproduction — a human-readable STATUS LABEL pasted where a slug
	 * belongs, which is exactly what the broken filter UI was submitting (#837 defect 1).
	 */
	public function test_a_status_label_where_a_slug_belongs_narrows_to_nothing(): void {
		$registry = $this->registry_with_one_provider();

		$args = $this->query_with_hpos( true, $registry )->build_args( [ 'status' => [ 'Pending payment' ] ] );

		$this->assertSame( Orders_Query::NO_MATCH_META_QUERY, $args['meta_query'] );
	}

	/**
	 * The legacy CPT datastore must narrow the same way — it carries marker keys as a query
	 * var rather than a `meta_query`, and an empty list is what
	 * {@see Orders_Registry::translate_marker_keys_query_var()} turns back into the sentinel.
	 * This is the path on which the bogus-slug attempt silently returned every row.
	 */
	public function test_a_status_request_with_nothing_recognized_narrows_to_nothing_on_the_legacy_cpt_path(): void {
		$registry = $this->registry_with_one_provider();

		$args = $this->query_with_hpos( false, $registry )->build_args( [ 'status' => [ 'nonsense' ] ] );

		$this->assertSame( [], $args[ Orders_Query::QUERY_VAR_MARKER_KEYS ] );
		$this->assertSame( Orders_Query::NO_MATCH_META_QUERY, Orders_Query::meta_query_for_keys( $args[ Orders_Query::QUERY_VAR_MARKER_KEYS ] ) );
	}

	/**
	 * An all-blank request is indistinguishable from asking for nothing at all, so it
	 * keeps meaning "no override" rather than emptying the table.
	 */
	public function test_a_blank_status_request_still_means_no_override(): void {
		$args = $this->query_with_hpos( true )->build_args( [ 'status' => [ '', '   ' ] ] );

		$this->assertSame( [ 'wc-pending', 'wc-processing', 'wc-cancelled', 'wc-failed' ], $args['status'] );
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

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query(
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
						'relation' => 'AND',
						[
							'key'     => '_cdek_status',
							'value'   => [ 'CDEK_ACCEPTED', 'CDEK_ENROUTE' ],
							'compare' => 'IN',
						],
						[
							'key'     => '_woodev_shipment_cancelled_at',
							'compare' => 'NOT EXISTS',
						],
					],
				],
			],
			$tree
		);
	}

	public function test_delivery_status_hpos_aggregate_ors_across_participating_providers(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider_with_status( 'cdek', '_cdek_marker', '_cdek_status', [ 'CDEK_DONE' => Delivery_Status::DELIVERED ] ) );
		$registry->register_provider( $this->provider_with_status( 'yandex', '_yandex_marker', '_yandex_status', [ 'YAN_DONE' => Delivery_Status::DELIVERED ] ) );

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query(
			[
				'carrier'         => 'all',
				'delivery_status' => Delivery_Status::DELIVERED,
			]
		);

		$status_part = $this->status_map_part( $this->meta_query_filter_part( $tree, [ '_cdek_marker', '_yandex_marker' ] ) );

		$this->assertSame(
			[
				'key'     => '_cdek_status',
				'value'   => [ 'CDEK_DONE' ],
				'compare' => 'IN',
			],
			$status_part[0]
		);
		$this->assertSame(
			[
				'key'     => '_yandex_status',
				'value'   => [ 'YAN_DONE' ],
				'compare' => 'IN',
			],
			$status_part[1]
		);
		$this->assertSame( 'OR', $status_part['relation'] );
	}

	/**
	 * A provider with no status concept at all is ALWAYS unknown — its marker key
	 * (guaranteed present within scope) stands in for "always true".
	 */
	public function test_delivery_status_unknown_matches_a_provider_with_no_status_concept_via_its_marker_key(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider_with_status( 'novendor', '_novendor_marker' ) );

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query(
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
			$this->status_map_part( $this->meta_query_filter_part( $tree, [ '_novendor_marker' ] ) )[0]
		);
	}

	/**
	 * `unknown` against a provider WITH a status concept needs BOTH halves OR'd:
	 * an order with no status meta at all, and one carrying a raw value absent
	 * from the map.
	 *
	 * ⚠ It cannot be one `NOT IN` clause, which is what this test asserted until
	 * the integration suite disproved it on real rows. Only `NOT EXISTS` makes
	 * `WP_Meta_Query` LEFT JOIN — WordPress says so in `class-wp-meta-query.php`:
	 * "If any JOINs are LEFT JOINs (as in the case of NOT EXISTS), then all JOINs
	 * should be LEFT. Otherwise posts with no metadata will be excluded from
	 * results." A lone `NOT IN` therefore hides the commonest unknown of all: the
	 * order the carrier has never reported on.
	 *
	 * ⚠ And that OR pair must itself sit under an `AND` with the provider's own marker —
	 * see {@see self::test_delivery_status_unknown_on_the_aggregate_binds_each_provider_to_its_marker()}
	 * for why, and for the defect that shape prevents.
	 */
	public function test_delivery_status_unknown_against_a_real_status_map_covers_missing_and_unmapped(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider(
			$this->provider_with_status( 'cdek', '_cdek_marker', '_cdek_status', [ 'CDEK_ACCEPTED' => Delivery_Status::IN_TRANSIT ] )
		);

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query(
			[
				'carrier'         => 'cdek',
				'delivery_status' => Delivery_Status::UNKNOWN,
			]
		);

		$this->assertSame(
			[
				'relation' => 'AND',
				[
					'key'     => '_cdek_marker',
					'compare' => 'EXISTS',
				],
				[
					'relation' => 'OR',
					[
						'key'     => '_cdek_status',
						'compare' => 'NOT EXISTS',
					],
					[
						'key'     => '_cdek_status',
						'value'   => [ 'CDEK_ACCEPTED' ],
						'compare' => 'NOT IN',
					],
				],
			],
			$this->status_map_part( $this->meta_query_filter_part( $tree, [ '_cdek_marker' ] ) )[0]
		);
	}

	/**
	 * ⚠ The regression this whole fix exists for (#837 defect 2), and it is INVISIBLE with a
	 * single provider registered — which is why every earlier test above passed.
	 *
	 * Each provider's `unknown` clause is a NEGATIVE statement about that provider's own
	 * status meta, and these clauses are OR-ed across providers. «Carrier B wrote no status
	 * meta» is trivially TRUE of every carrier A order, because a carrier never writes
	 * another's meta — so unbound, the OR matches the entire table. Measured on the rig
	 * 08.09.2026 with two carriers: `delivery_status=unknown` returned 71 of 71.
	 *
	 * Binding each half to its own provider's marker is the same repair the
	 * `has_tracking=false` clause needed in s127, and the gotcha
	 * `a-negative-meta-clause-or-ed-across-providers-matches-every-order` is the shared
	 * root. So this test asserts the BINDING, not merely the OR.
	 */
	public function test_delivery_status_unknown_on_the_aggregate_binds_each_provider_to_its_marker(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider(
			$this->provider_with_status( 'cdek', '_cdek_marker', '_cdek_status', [ 'CDEK_ACCEPTED' => Delivery_Status::IN_TRANSIT ] )
		);
		$registry->register_provider(
			$this->provider_with_status( 'yandex', '_yandex_marker', '_yandex_status', [ 'YA_SHIPPED' => Delivery_Status::IN_TRANSIT ] )
		);

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query(
			[ 'delivery_status' => Delivery_Status::UNKNOWN ]
		);

		$status_part = $this->status_map_part( $this->meta_query_filter_part( $tree, [ '_cdek_marker', '_yandex_marker' ] ) );

		$this->assertSame( 'OR', $status_part['relation'] );

		foreach ( [ 0, 1 ] as $index ) {
			$this->assertSame(
				'AND',
				$status_part[ $index ]['relation'],
				'Each provider clause must be an AND binding it to its own marker, or the OR matches every order.'
			);
			$this->assertSame( 'EXISTS', $status_part[ $index ][0]['compare'] );
			$this->assertStringEndsWith( '_marker', $status_part[ $index ][0]['key'] );
		}

		$this->assertSame( '_cdek_marker', $status_part[0][0]['key'] );
		$this->assertSame( '_yandex_marker', $status_part[1][0]['key'] );
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

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query(
			[
				'carrier'         => 'cdek',
				'delivery_status' => Delivery_Status::DELIVERED,
			]
		);

		$this->assertSame( Orders_Query::NO_MATCH_META_QUERY, $this->meta_query_filter_part( $tree, [ '_cdek_marker' ] ) );
	}

	public function test_delivery_status_unrecognized_value_is_ignored_entirely(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider( 'cdek', '_cdek_marker' ) );

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query(
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
			$tree
		);
	}


	// ----- tracking-presence filter (SP-10 spec D10) -----

	public function test_has_tracking_true_builds_an_exists_clause(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider(
			Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ], [ 'tracking_meta_key' => '_cdek_tracking' ] )
		);

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query(
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
			$this->meta_query_filter_part( $tree, [ '_cdek_marker' ] )
		);
	}

	/**
	 * The NEGATIVE case is bound to the provider's own marker — see the aggregate
	 * test below for why that binding is not decoration.
	 */
	public function test_has_tracking_false_binds_not_exists_to_the_providers_own_marker(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider(
			Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ], [ 'tracking_meta_key' => '_cdek_tracking' ] )
		);

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query(
			[
				'carrier'      => 'cdek',
				'has_tracking' => false,
			]
		);

		$this->assertSame(
			[
				[
					'relation' => 'AND',
					[
						'key'     => '_cdek_marker',
						'compare' => 'EXISTS',
					],
					[
						'key'     => '_cdek_tracking',
						'compare' => 'NOT EXISTS',
					],
				],
			],
			$this->meta_query_filter_part( $tree, [ '_cdek_marker' ] )
		);
	}

	/**
	 * The regression this binding exists for, and it needs TWO providers to show at
	 * all — which is exactly why it went unnoticed: every earlier tracking test
	 * registered one.
	 *
	 * Unbound, the clauses OR to «cdek has no tracking» OR «yandex has no tracking»,
	 * and the second half is trivially TRUE of every cdek order, because a carrier
	 * never writes another carrier's meta. The aggregate then matched the whole
	 * table. Measured on the rig 08.09.2026 before the fix: `has_tracking=false`
	 * returned 71 of 71 rows instead of 24, while each single-carrier view was
	 * right (11 of 34 and 13 of 37).
	 */
	public function test_has_tracking_false_on_the_aggregate_does_not_match_every_order(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider(
			Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ], [ 'tracking_meta_key' => '_cdek_tracking' ] )
		);
		$registry->register_provider(
			Orders_Provider::create( 'yandex', 'Яндекс', '_yandex_marker', [ 'yandex' ], [ 'tracking_meta_key' => '_yandex_tracking' ] )
		);

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query(
			[
				'carrier'      => 'all',
				'has_tracking' => false,
			]
		);

		$tracking_part = $this->meta_query_filter_part( $tree, [ '_cdek_marker', '_yandex_marker' ] );

		$this->assertSame( 'OR', $tracking_part['relation'] );

		// Every branch of that OR must name its own marker, or it is satisfiable by
		// an order belonging to the OTHER carrier.
		foreach ( [ 0, 1 ] as $index ) {
			$branch = $tracking_part[ $index ];

			$this->assertSame( 'AND', $branch['relation'], 'each branch must bind marker AND tracking' );

			$keys = [ $branch[0]['key'], $branch[1]['key'] ];

			$this->assertContains( 'NOT EXISTS', [ $branch[0]['compare'], $branch[1]['compare'] ] );
			$this->assertNotEmpty(
				preg_grep( '/_marker$/', $keys ),
				'a NOT EXISTS branch that names no marker matches the other carrier\'s orders'
			);
		}
	}

	/**
	 * A carrier without a tracking concept at all can never report `true`.
	 */
	public function test_has_tracking_true_for_a_carrier_without_tracking_builds_the_no_match_sentinel(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider( 'cdek', '_cdek_marker' ) );

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query(
			[
				'carrier'      => 'cdek',
				'has_tracking' => true,
			]
		);

		$this->assertSame( Orders_Query::NO_MATCH_META_QUERY, $this->meta_query_filter_part( $tree, [ '_cdek_marker' ] ) );
	}

	/**
	 * The same carrier ALWAYS counts as "no tracking" — never having any tracking
	 * concept means it never has a tracking number either.
	 */
	public function test_has_tracking_false_for_a_carrier_without_tracking_matches_via_its_marker_key(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider( 'cdek', '_cdek_marker' ) );

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query(
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
			$this->meta_query_filter_part( $tree, [ '_cdek_marker' ] )
		);
	}

	public function test_has_tracking_absent_never_adds_a_meta_query_part(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider( 'cdek', '_cdek_marker' ) );

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query( [ 'carrier' => 'cdek' ] );

		$this->assertSame(
			[
				[
					'key'     => '_cdek_marker',
					'compare' => 'EXISTS',
				],
			],
			$tree
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

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query(
			[
				'carrier'      => 'cdek',
				'has_tracking' => false,
			]
		);

		$this->assertCount( 3, $tree ); // relation + scope part + tracking part.
	}


	// ----- delivery-status 'is not' rule (#836) -----

	public function test_delivery_status_not_hpos_single_carrier_builds_the_not_in_or_not_exists_shape(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider(
			$this->provider_with_status( 'cdek', '_cdek_marker', '_cdek_status', [ 'CDEK_ACCEPTED' => Delivery_Status::IN_TRANSIT ] )
		);

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query(
			[
				'carrier'             => 'cdek',
				'delivery_status_not' => Delivery_Status::IN_TRANSIT,
			]
		);

		$this->assertSame(
			[
				'relation' => 'AND',
				[
					'key'     => '_cdek_marker',
					'compare' => 'EXISTS',
				],
				[
					'relation' => 'OR',
					[
						'key'     => '_cdek_status',
						'compare' => 'NOT EXISTS',
					],
					[
						'key'     => '_cdek_status',
						'value'   => [ 'CDEK_ACCEPTED' ],
						'compare' => 'NOT IN',
					],
				],
			],
			$this->meta_query_filter_part( $tree, [ '_cdek_marker' ] )[0]
		);
	}

	/**
	 * ⚠ Needs TWO providers, the same regression shape `has_tracking=false` and
	 * `delivery_status=unknown` needed — an unbound negative clause OR-ed across
	 * providers would let carrier B's orders satisfy "carrier A's status is not X"
	 * for free.
	 */
	public function test_delivery_status_not_on_the_aggregate_binds_each_provider_to_its_marker(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider(
			$this->provider_with_status( 'cdek', '_cdek_marker', '_cdek_status', [ 'CDEK_DONE' => Delivery_Status::DELIVERED ] )
		);
		$registry->register_provider(
			$this->provider_with_status( 'yandex', '_yandex_marker', '_yandex_status', [ 'YA_DONE' => Delivery_Status::DELIVERED ] )
		);

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query(
			[ 'delivery_status_not' => Delivery_Status::DELIVERED ]
		);

		$status_part = $this->meta_query_filter_part( $tree, [ '_cdek_marker', '_yandex_marker' ] );

		$this->assertSame( 'OR', $status_part['relation'] );

		foreach ( [ 0, 1 ] as $index ) {
			$this->assertSame(
				'AND',
				$status_part[ $index ]['relation'],
				'Each provider clause must be an AND binding it to its own marker, or the OR matches every order.'
			);
			$this->assertSame( 'EXISTS', $status_part[ $index ][0]['compare'] );
			$this->assertStringEndsWith( '_marker', $status_part[ $index ][0]['key'] );
		}
	}

	/**
	 * A provider that never maps anything to X has EVERY order qualifying as "not X" —
	 * its marker key stands in for "always true", the same shape the no-status-concept
	 * branch already uses.
	 */
	public function test_delivery_status_not_a_provider_that_never_maps_to_x_matches_via_its_marker_key(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider(
			$this->provider_with_status( 'cdek', '_cdek_marker', '_cdek_status', [ 'CDEK_ACCEPTED' => Delivery_Status::IN_TRANSIT ] )
		);

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query(
			[
				'carrier'             => 'cdek',
				'delivery_status_not' => Delivery_Status::DELIVERED,
			]
		);

		$this->assertSame(
			[
				'key'     => '_cdek_marker',
				'compare' => 'EXISTS',
			],
			$this->meta_query_filter_part( $tree, [ '_cdek_marker' ] )[0]
		);
	}

	/**
	 * A provider with no status concept at all is ALWAYS unknown, so it always
	 * qualifies as "not X" for any real canonical state X.
	 */
	public function test_delivery_status_not_a_provider_with_no_status_concept_matches_via_its_marker_key(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider_with_status( 'novendor', '_novendor_marker' ) );

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query(
			[
				'carrier'             => 'novendor',
				'delivery_status_not' => Delivery_Status::DELIVERED,
			]
		);

		$this->assertSame(
			[
				'key'     => '_novendor_marker',
				'compare' => 'EXISTS',
			],
			$this->meta_query_filter_part( $tree, [ '_novendor_marker' ] )[0]
		);
	}

	/**
	 * "Is not unknown" — the mirror image of `unknown`: a definite known status, i.e.
	 * the plain `IN $known` clause.
	 */
	public function test_delivery_status_not_unknown_builds_an_in_known_clause(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider(
			$this->provider_with_status(
				'cdek',
				'_cdek_marker',
				'_cdek_status',
				[
					'CDEK_ACCEPTED' => Delivery_Status::IN_TRANSIT,
					'CDEK_DONE'     => Delivery_Status::DELIVERED,
				]
			)
		);

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query(
			[
				'carrier'             => 'cdek',
				'delivery_status_not' => Delivery_Status::UNKNOWN,
			]
		);

		$this->assertSame(
			[
				'key'     => '_cdek_status',
				'value'   => [ 'CDEK_ACCEPTED', 'CDEK_DONE' ],
				'compare' => 'IN',
			],
			$this->meta_query_filter_part( $tree, [ '_cdek_marker' ] )[0]
		);
	}

	public function test_delivery_status_takes_precedence_over_delivery_status_not_when_both_are_sent(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider(
			$this->provider_with_status( 'cdek', '_cdek_marker', '_cdek_status', [ 'CDEK_ACCEPTED' => Delivery_Status::IN_TRANSIT ] )
		);

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query(
			[
				'carrier'             => 'cdek',
				'delivery_status'     => Delivery_Status::IN_TRANSIT,
				'delivery_status_not' => Delivery_Status::IN_TRANSIT,
			]
		);

		// The 'is' clause won, not the 'is not' one — a plain IN, not a NOT-IN/AND shape.
		$this->assertSame(
			[
				'key'     => '_cdek_status',
				'value'   => [ 'CDEK_ACCEPTED' ],
				'compare' => 'IN',
			],
			$this->status_map_part( $this->meta_query_filter_part( $tree, [ '_cdek_marker' ] ) )[0]
		);
	}


	// ----- the framework's own cancellation marker (#1037) -----

	private function cdek_registry_for_cancellation(): Orders_Registry {
		$registry = Orders_Registry::instance();
		$registry->register_provider(
			$this->provider_with_status( 'cdek', '_cdek_marker', '_cdek_status', [ 'CDEK_ACCEPTED' => Delivery_Status::IN_TRANSIT ] )
		);

		return $registry;
	}

	private function cancelled_exists(): array {
		return [
			'key'     => '_woodev_shipment_cancelled_at',
			'compare' => 'EXISTS',
		];
	}

	/** «Отменено»: the marker is the whole filter — no raw status maps to it. Scope already confines it to a carrier's orders. */
	public function test_delivery_status_cancelled_is_the_marker_alone(): void {
		$tree = $this->query_with_hpos( true, $this->cdek_registry_for_cancellation() )->build_meta_query( [ 'delivery_status' => Delivery_Status::CANCELLED ] );

		$this->assertSame( [ $this->cancelled_exists() ], $this->meta_query_filter_part( $tree, [ '_cdek_marker' ] ) );
	}

	/** A carrier that DOES map a raw status to cancelled keeps matching it, beside the marker. */
	public function test_delivery_status_cancelled_keeps_the_carriers_own_cancelled_raw_values(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider(
			$this->provider_with_status( 'cdek', '_cdek_marker', '_cdek_status', [ 'CDEK_CANCELLED' => Delivery_Status::CANCELLED ] )
		);

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query( [ 'delivery_status' => Delivery_Status::CANCELLED ] );

		$this->assertSame(
			[
				'relation' => 'OR',
				[
					'key'     => '_cdek_status',
					'value'   => [ 'CDEK_CANCELLED' ],
					'compare' => 'IN',
				],
				$this->cancelled_exists(),
			],
			$this->meta_query_filter_part( $tree, [ '_cdek_marker' ] )
		);
	}

	/** «Не отменено»: every order of the carrier, minus the cancelled ones. */
	public function test_delivery_status_not_cancelled_excludes_the_marker(): void {
		$tree = $this->query_with_hpos( true, $this->cdek_registry_for_cancellation() )->build_meta_query( [ 'delivery_status_not' => Delivery_Status::CANCELLED ] );

		$this->assertSame(
			[
				[
					'relation' => 'AND',
					[
						'key'     => '_cdek_marker',
						'compare' => 'EXISTS',
					],
					[
						'key'     => '_woodev_shipment_cancelled_at',
						'compare' => 'NOT EXISTS',
					],
				],
			],
			$this->meta_query_filter_part( $tree, [ '_cdek_marker' ] )
		);
	}

	/** A cancelled order is «not in transit» whatever its raw status says: the marker is one more disjunct. */
	public function test_delivery_status_not_x_also_matches_a_cancelled_order(): void {
		$tree = $this->query_with_hpos( true, $this->cdek_registry_for_cancellation() )->build_meta_query( [ 'delivery_status_not' => Delivery_Status::IN_TRANSIT ] );
		$part = $this->meta_query_filter_part( $tree, [ '_cdek_marker' ] );

		$this->assertSame( 'OR', $part['relation'] );
		$this->assertSame( $this->cancelled_exists(), $part[1] );
	}

	/** …and «cancelled» is a known status, so a cancelled order is «not unknown» too. */
	public function test_delivery_status_not_unknown_also_matches_a_cancelled_order(): void {
		$tree = $this->query_with_hpos( true, $this->cdek_registry_for_cancellation() )->build_meta_query( [ 'delivery_status_not' => Delivery_Status::UNKNOWN ] );
		$part = $this->meta_query_filter_part( $tree, [ '_cdek_marker' ] );

		$this->assertSame( 'OR', $part['relation'] );
		$this->assertSame( $this->cancelled_exists(), $part[1] );
	}

	/** Every state but cancelled EXCLUDES a cancelled order: its raw status may still map to the state asked for. */
	public function test_delivery_status_x_excludes_a_cancelled_order(): void {
		foreach ( [ Delivery_Status::IN_TRANSIT, Delivery_Status::UNKNOWN ] as $canonical ) {
			Orders_Registry::instance()->reset_for_tests();

			$tree = $this->query_with_hpos( true, $this->cdek_registry_for_cancellation() )->build_meta_query( [ 'delivery_status' => $canonical ] );
			$part = $this->meta_query_filter_part( $tree, [ '_cdek_marker' ] );

			$this->assertSame( 'AND', $part[0]['relation'], $canonical );
			$this->assertSame(
				[
					'key'     => '_woodev_shipment_cancelled_at',
					'compare' => 'NOT EXISTS',
				],
				$part[0][1],
				$canonical
			);
		}
	}

	/** One marker leaf for the whole filter, not one per carrier: the id query grows by a constant. */
	public function test_the_cancellation_leaf_is_added_once_not_per_carrier(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider_with_status( 'cdek', '_cdek_marker', '_cdek_status', [ 'A' => Delivery_Status::IN_TRANSIT ] ) );
		$registry->register_provider( $this->provider_with_status( 'yandex', '_yandex_marker', '_yandex_status', [ 'B' => Delivery_Status::IN_TRANSIT ] ) );

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query( [ 'delivery_status_not' => Delivery_Status::IN_TRANSIT ] );

		$this->assertSame( 1, substr_count( (string) json_encode( $tree ), '_woodev_shipment_cancelled_at' ) );
	}


	// ----- native WC order status 'is not' rule (#836) -----

	public function test_status_not_excludes_the_requested_status_from_the_full_valid_list(): void {
		$args = $this->query_with_hpos( true )->build_args( [ 'status_not' => [ 'wc-processing' ] ] );

		$this->assertSame( [ 'wc-pending', 'wc-cancelled', 'wc-failed' ], $args['status'] );
	}

	/**
	 * The 'is not' baseline is the FULL valid list (cancelled/failed included), not the
	 * default view's narrower one — the brief's own words: "every valid status except X".
	 */
	public function test_status_not_baseline_includes_cancelled_and_failed(): void {
		$args = $this->query_with_hpos( true )->build_args( [ 'status_not' => [ 'wc-pending' ] ] );

		$this->assertContains( 'wc-cancelled', $args['status'] );
		$this->assertContains( 'wc-failed', $args['status'] );
	}

	public function test_a_status_not_without_the_wc_prefix_is_normalized(): void {
		$args = $this->query_with_hpos( true )->build_args( [ 'status_not' => [ 'processing' ] ] );

		$this->assertNotContains( 'wc-processing', $args['status'] );
	}

	public function test_status_takes_precedence_over_status_not_when_both_are_sent(): void {
		$args = $this->query_with_hpos( true )->build_args(
			[
				'status'     => [ 'wc-processing' ],
				'status_not' => [ 'wc-pending' ],
			]
		);

		$this->assertSame( [ 'wc-processing' ], $args['status'] );
	}

	/**
	 * Nothing recognized in `status_not` excludes nothing — the honest result is the
	 * FULL valid list, not a silent fallback to the default cancelled/failed exclusion.
	 */
	public function test_a_status_not_request_with_nothing_recognized_excludes_nothing(): void {
		$args = $this->query_with_hpos( true )->build_args( [ 'status_not' => [ 'not-a-real-status' ] ] );

		$this->assertSame( [ 'wc-pending', 'wc-processing', 'wc-cancelled', 'wc-failed' ], $args['status'] );
	}

	public function test_a_blank_status_not_request_still_means_no_override(): void {
		$args = $this->query_with_hpos( true )->build_args( [ 'status_not' => [ '', '   ' ] ] );

		$this->assertSame( [ 'wc-pending', 'wc-processing', 'wc-cancelled', 'wc-failed' ], $args['status'] );
	}

	// ----- pickup-point-presence filter (#836) -----

	public function test_has_pickup_point_true_builds_an_exists_clause(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider(
			Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ], [ 'pickup_point_meta_key' => '_cdek_pickup_point' ] )
		);

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query(
			[
				'carrier'          => 'cdek',
				'has_pickup_point' => true,
			]
		);

		$this->assertSame(
			[
				[
					'key'     => '_cdek_pickup_point',
					'compare' => 'EXISTS',
				],
			],
			$this->meta_query_filter_part( $tree, [ '_cdek_marker' ] )
		);
	}

	/**
	 * The NEGATIVE case is bound to the provider's own marker — see the aggregate
	 * test below for why that binding is not decoration.
	 */
	public function test_has_pickup_point_false_binds_not_exists_to_the_providers_own_marker(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider(
			Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ], [ 'pickup_point_meta_key' => '_cdek_pickup_point' ] )
		);

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query(
			[
				'carrier'          => 'cdek',
				'has_pickup_point' => false,
			]
		);

		$this->assertSame(
			[
				[
					'relation' => 'AND',
					[
						'key'     => '_cdek_marker',
						'compare' => 'EXISTS',
					],
					[
						'key'     => '_cdek_pickup_point',
						'compare' => 'NOT EXISTS',
					],
				],
			],
			$this->meta_query_filter_part( $tree, [ '_cdek_marker' ] )
		);
	}

	/**
	 * ⚠ Needs TWO providers, the same regression shape `has_tracking=false` needed —
	 * unbound, «carrier B has no pickup point» is trivially true of every carrier A
	 * order.
	 */
	public function test_has_pickup_point_false_on_the_aggregate_does_not_match_every_order(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider(
			Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ], [ 'pickup_point_meta_key' => '_cdek_pickup_point' ] )
		);
		$registry->register_provider(
			Orders_Provider::create( 'yandex', 'Яндекс', '_yandex_marker', [ 'yandex' ], [ 'pickup_point_meta_key' => '_yandex_pickup_point' ] )
		);

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query(
			[
				'carrier'          => 'all',
				'has_pickup_point' => false,
			]
		);

		$pickup_part = $this->meta_query_filter_part( $tree, [ '_cdek_marker', '_yandex_marker' ] );

		$this->assertSame( 'OR', $pickup_part['relation'] );

		foreach ( [ 0, 1 ] as $index ) {
			$branch = $pickup_part[ $index ];

			$this->assertSame( 'AND', $branch['relation'], 'each branch must bind marker AND pickup point' );

			$keys = [ $branch[0]['key'], $branch[1]['key'] ];

			$this->assertContains( 'NOT EXISTS', [ $branch[0]['compare'], $branch[1]['compare'] ] );
			$this->assertNotEmpty(
				preg_grep( '/_marker$/', $keys ),
				'a NOT EXISTS branch that names no marker matches the other carrier\'s orders'
			);
		}
	}

	/**
	 * A carrier without a pickup-point concept at all can never report `true`.
	 */
	public function test_has_pickup_point_true_for_a_carrier_without_pickup_point_builds_the_no_match_sentinel(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider( 'cdek', '_cdek_marker' ) );

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query(
			[
				'carrier'          => 'cdek',
				'has_pickup_point' => true,
			]
		);

		$this->assertSame( Orders_Query::NO_MATCH_META_QUERY, $this->meta_query_filter_part( $tree, [ '_cdek_marker' ] ) );
	}

	/**
	 * The same carrier ALWAYS counts as "no pickup point" — never having any
	 * pickup-point concept means it never has a pickup point either.
	 */
	public function test_has_pickup_point_false_for_a_carrier_without_pickup_point_matches_via_its_marker_key(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider( 'cdek', '_cdek_marker' ) );

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query(
			[
				'carrier'          => 'cdek',
				'has_pickup_point' => false,
			]
		);

		$this->assertSame(
			[
				[
					'key'     => '_cdek_marker',
					'compare' => 'EXISTS',
				],
			],
			$this->meta_query_filter_part( $tree, [ '_cdek_marker' ] )
		);
	}

	public function test_has_pickup_point_absent_never_adds_a_meta_query_part(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider( 'cdek', '_cdek_marker' ) );

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query( [ 'carrier' => 'cdek' ] );

		$this->assertSame(
			[
				[
					'key'     => '_cdek_marker',
					'compare' => 'EXISTS',
				],
			],
			$tree
		);
	}

	/**
	 * `has_param()`-style tri-state: an EXPLICIT `false` must still filter, not be
	 * mistaken for "absent" — read via `array_key_exists()`, not `isset()`.
	 */
	public function test_has_pickup_point_explicit_false_is_not_mistaken_for_absent(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider(
			Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ], [ 'pickup_point_meta_key' => '_cdek_pickup_point' ] )
		);

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query(
			[
				'carrier'          => 'cdek',
				'has_pickup_point' => false,
			]
		);

		$this->assertCount( 3, $tree ); // relation + scope part + pickup point part.
	}


	// ----- export-presence filter / "new orders" (SP-10 #841) -----

	/**
	 * #860: "exported" also requires a NON-EMPTY value — `!=` against an INNER JOIN
	 * on this key excludes a row with no such meta at all, same as `NOT EXISTS`
	 * would, which is exactly right: a FAILED export (an unconditionally-written
	 * empty id) must not read as exported.
	 */
	public function test_is_exported_true_excludes_an_empty_stored_value(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider(
			Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ], [ 'carrier_order_id_meta_key' => '_cdek_carrier_order_id' ] )
		);

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query(
			[
				'carrier'     => 'cdek',
				'is_exported' => true,
			]
		);

		$this->assertSame(
			[
				[
					'key'     => '_cdek_carrier_order_id',
					'value'   => '',
					'compare' => '!=',
				],
			],
			$this->meta_query_filter_part( $tree, [ '_cdek_marker' ] )
		);
	}

	/**
	 * The NEGATIVE case is bound to the provider's own marker — see the aggregate
	 * test below for why that binding is not decoration. #860: it now ALSO matches
	 * a present-but-empty value, the exact complement of the positive clause above.
	 */
	public function test_is_exported_false_binds_not_exists_to_the_providers_own_marker(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider(
			Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ], [ 'carrier_order_id_meta_key' => '_cdek_carrier_order_id' ] )
		);

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query(
			[
				'carrier'     => 'cdek',
				'is_exported' => false,
			]
		);

		$this->assertSame(
			[
				[
					'relation' => 'AND',
					[
						'key'     => '_cdek_marker',
						'compare' => 'EXISTS',
					],
					[
						'relation' => 'OR',
						[
							'key'     => '_cdek_carrier_order_id',
							'compare' => 'NOT EXISTS',
						],
						[
							'key'     => '_cdek_carrier_order_id',
							'value'   => '',
							'compare' => '=',
						],
					],
				],
			],
			$this->meta_query_filter_part( $tree, [ '_cdek_marker' ] )
		);
	}

	/**
	 * ⚠ Needs TWO providers, the same regression shape `has_tracking=false` and
	 * `has_pickup_point=false` needed — unbound, «carrier B has no carrier-order-id»
	 * is trivially true of every carrier A order.
	 */
	public function test_is_exported_false_on_the_aggregate_does_not_match_every_order(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider(
			Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ], [ 'carrier_order_id_meta_key' => '_cdek_carrier_order_id' ] )
		);
		$registry->register_provider(
			Orders_Provider::create( 'yandex', 'Яндекс', '_yandex_marker', [ 'yandex' ], [ 'carrier_order_id_meta_key' => '_yandex_carrier_order_id' ] )
		);

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query(
			[
				'carrier'     => 'all',
				'is_exported' => false,
			]
		);

		$exported_part = $this->meta_query_filter_part( $tree, [ '_cdek_marker', '_yandex_marker' ] );

		$this->assertSame( 'OR', $exported_part['relation'] );

		foreach ( [ 0, 1 ] as $index ) {
			$branch = $exported_part[ $index ];

			$this->assertSame( 'AND', $branch['relation'], 'each branch must bind marker AND carrier-order-id' );
			$this->assertSame( 'EXISTS', $branch[0]['compare'] );
			$this->assertMatchesRegularExpression( '/_marker$/', $branch[0]['key'], 'a branch that names no marker matches the other carrier\'s orders' );

			// #860: the carrier-order-id side is now an OR of "absent" and
			// "present but empty" rather than a single NOT EXISTS clause.
			$carrier_order_id_group = $branch[1];

			$this->assertSame( 'OR', $carrier_order_id_group['relation'] );
			$this->assertSame( 'NOT EXISTS', $carrier_order_id_group[0]['compare'] );
			$this->assertSame( '=', $carrier_order_id_group[1]['compare'] );
			$this->assertSame( '', $carrier_order_id_group[1]['value'] );
			$this->assertSame( $carrier_order_id_group[0]['key'], $carrier_order_id_group[1]['key'] );
		}
	}

	/**
	 * A carrier without a declared carrier-order-id key at all can never report
	 * `true` — the framework has no way to know it was exported.
	 */
	public function test_is_exported_true_for_a_carrier_without_carrier_order_id_key_builds_the_no_match_sentinel(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider( 'cdek', '_cdek_marker' ) );

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query(
			[
				'carrier'     => 'cdek',
				'is_exported' => true,
			]
		);

		$this->assertSame( Orders_Query::NO_MATCH_META_QUERY, $this->meta_query_filter_part( $tree, [ '_cdek_marker' ] ) );
	}

	/**
	 * The same carrier ALWAYS counts as "not exported" — the framework never
	 * observes an export it has no key to detect, so every one of its orders is
	 * "new" by definition.
	 */
	public function test_is_exported_false_for_a_carrier_without_carrier_order_id_key_matches_via_its_marker_key(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider( 'cdek', '_cdek_marker' ) );

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query(
			[
				'carrier'     => 'cdek',
				'is_exported' => false,
			]
		);

		$this->assertSame(
			[
				[
					'key'     => '_cdek_marker',
					'compare' => 'EXISTS',
				],
			],
			$this->meta_query_filter_part( $tree, [ '_cdek_marker' ] )
		);
	}

	public function test_is_exported_absent_never_adds_a_meta_query_part(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->provider( 'cdek', '_cdek_marker' ) );

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query( [ 'carrier' => 'cdek' ] );

		$this->assertSame(
			[
				[
					'key'     => '_cdek_marker',
					'compare' => 'EXISTS',
				],
			],
			$tree
		);
	}

	/**
	 * `has_param()`-style tri-state: an EXPLICIT `false` must still filter, not be
	 * mistaken for "absent" — read via `array_key_exists()`, not `isset()`.
	 */
	public function test_is_exported_explicit_false_is_not_mistaken_for_absent(): void {
		$registry = Orders_Registry::instance();
		$registry->register_provider(
			Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ], [ 'carrier_order_id_meta_key' => '_cdek_carrier_order_id' ] )
		);

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query(
			[
				'carrier'     => 'cdek',
				'is_exported' => false,
			]
		);

		$this->assertCount( 3, $tree ); // relation + scope part + exported part.
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

		$tree = $this->query_with_hpos( true, $registry )->build_meta_query(
			[
				'carrier'         => 'cdek',
				'delivery_status' => Delivery_Status::DELIVERED,
				'has_tracking'    => true,
			]
		);

		$this->assertSame( 'AND', $tree['relation'] );
		$this->assertCount( 4, $tree ); // relation + scope part + status part + tracking part.
	}

	// ----- «Все / Любое»: `match=any` (#843) -----

	/** One carrier that has a status concept, a tracking key and a pickup-point key. */
	private function registry_for_match(): Orders_Registry {
		$registry = Orders_Registry::instance();
		$registry->register_provider(
			Orders_Provider::create(
				'cdek',
				'СДЭК',
				'_cdek_marker',
				[ 'cdek' ],
				[
					'status_meta_key'          => '_cdek_status',
					'status_map'               => [ 'CDEK_DONE' => Delivery_Status::DELIVERED ],
					'tracking_meta_key'        => '_cdek_tracking',
					'pickup_point_meta_key'    => '_cdek_pickup',
					'carrier_order_id_meta_key' => '_cdek_order_id',
				]
			)
		);

		return $registry;
	}

	/** The scope part, the delivery-status-`delivered` part and the has-tracking part of {@see self::registry_for_match()}. */
	private function match_parts(): array {
		return [
			[
				[
					'key'     => '_cdek_marker',
					'compare' => 'EXISTS',
				],
			],
			[
				[
					'relation' => 'AND',
					[
						'key'     => '_cdek_status',
						'value'   => [ 'CDEK_DONE' ],
						'compare' => 'IN',
					],
					[
						'key'     => '_woodev_shipment_cancelled_at',
						'compare' => 'NOT EXISTS',
					],
				],
			],
			[
				[
					'key'     => '_cdek_tracking',
					'compare' => 'EXISTS',
				],
			],
		];
	}

	/** «Любое» with two filters: scope AND (filter OR filter) — the OR is ONE part of the AND root. */
	public function test_match_any_ors_the_advanced_filters_under_the_scope(): void {
		[ $scope, $delivery, $tracking ] = $this->match_parts();

		$tree = $this->query_with_hpos( true, $this->registry_for_match() )->build_meta_query(
			[
				'match'           => 'any',
				'delivery_status' => Delivery_Status::DELIVERED,
				'has_tracking'    => true,
			]
		);

		$this->assertSame(
			[
				'relation' => 'AND',
				$scope,
				[
					'relation' => 'OR',
					$delivery,
					$tracking,
				],
			],
			$tree
		);
	}

	/** The scope's own `is_exported` is NOT an advanced filter: it ANDs with the OR, it does not join it. */
	public function test_match_any_keeps_the_export_scope_outside_the_or(): void {
		[ $scope, $delivery, $tracking ] = $this->match_parts();

		$tree = $this->query_with_hpos( true, $this->registry_for_match() )->build_meta_query(
			[
				'match'           => 'any',
				'delivery_status' => Delivery_Status::DELIVERED,
				'has_tracking'    => true,
				'is_exported'     => true,
			]
		);

		$this->assertSame( 'AND', $tree['relation'] );
		$this->assertCount( 4, $tree ); // relation + scope + the OR + export scope.
		$this->assertSame( $scope, $tree[0] );
		$this->assertSame(
			[
				'relation' => 'OR',
				$delivery,
				$tracking,
			],
			$tree[1]
		);
		$this->assertSame( '_cdek_order_id', $tree[2][0]['key'] );
	}

	/** Order status is a leaf of the OR, and the native `status` arg is widened to the full valid list so it cannot AND it back. */
	public function test_match_any_makes_order_status_a_leaf_and_widens_the_native_status_arg(): void {
		[ , $delivery ] = $this->match_parts();

		$query = $this->query_with_hpos( true, $this->registry_for_match() );
		$args  = $query->build_args(
			[
				'match'           => 'any',
				'delivery_status' => Delivery_Status::DELIVERED,
				'status'          => [ 'processing' ],
			]
		);

		$this->assertSame( [ 'wc-pending', 'wc-processing', 'wc-cancelled', 'wc-failed' ], $args['status'], 'the full valid list, cancelled/failed included — the leaf alone narrows' );
		$this->assertSame( [ 11, 12, 13 ], $args['post__in'] );
		$this->assertCount( 1, $query->resolved );
		$this->assertSame(
			[
				'relation' => 'OR',
				$delivery,
				[ [ 'order_status' => [ 'wc-processing' ] ] ],
			],
			$query->resolved[0][1][1]
		);
	}

	/** `status_not` under «Любое»: the leaf carries every valid status except the excluded ones, the same list `status_not` computes against. */
	public function test_match_any_status_not_becomes_a_leaf_of_the_complement(): void {
		$query = $this->query_with_hpos( true, $this->registry_for_match() );
		$args  = $query->build_args(
			[
				'match'      => 'any',
				'has_tracking' => true,
				'status_not' => [ 'cancelled', 'failed' ],
			]
		);

		$this->assertSame( [ 'wc-pending', 'wc-processing', 'wc-cancelled', 'wc-failed' ], $args['status'] );
		$this->assertSame( [ [ 'order_status' => [ 'wc-pending', 'wc-processing' ] ] ], $query->resolved[0][1][1][1] );
	}

	/** No status filter requested: the default view (every status, #1011) stays an AND on the native arg, even under «Любое». */
	public function test_match_any_without_a_status_filter_keeps_the_default_status_view(): void {
		$args = $this->query_with_hpos( true, $this->registry_for_match() )->build_args(
			[
				'match'           => 'any',
				'delivery_status' => Delivery_Status::DELIVERED,
				'has_tracking'    => true,
			]
		);

		$this->assertSame( [ 'wc-pending', 'wc-processing', 'wc-cancelled', 'wc-failed' ], $args['status'] );
	}

	/** #1024: under «Любое» the widened native status list is still cut to the exportable statuses inside the «new» scope. */
	public function test_match_any_inside_the_new_scope_is_still_limited_to_the_exportable_statuses(): void {
		$args = $this->query_with_hpos( true, $this->registry_for_match() )->build_args(
			[
				'match'           => 'any',
				'is_exported'     => false,
				'delivery_status' => Delivery_Status::DELIVERED,
				'status'          => [ 'processing', 'cancelled' ],
			]
		);

		$this->assertSame( [ 'wc-pending', 'wc-processing' ], $args['status'] );
	}

	/** A status request nothing in which is real: that leaf matches nothing, the OR goes on, providers are NOT emptied. */
	public function test_match_any_an_unrecognised_status_request_drops_only_its_own_leaf(): void {
		[ $scope, $delivery ] = $this->match_parts();

		$query = $this->query_with_hpos( true, $this->registry_for_match() );
		$args  = $query->build_args(
			[
				'match'           => 'any',
				'delivery_status' => Delivery_Status::DELIVERED,
				'status'          => [ 'nonsense' ],
			]
		);

		$this->assertSame( [ 11, 12, 13 ], $args['post__in'], 'the other filter still selects rows' );
		$this->assertSame( [ 'wc-pending', 'wc-processing', 'wc-cancelled', 'wc-failed' ], $args['status'] );
		$this->assertSame( [ 'relation' => 'AND', $scope, $delivery ], $query->resolved[0][1], 'the dead leaf leaves the OR; one live part is not wrapped' );
	}

	/** Under `all` the same request keeps today's behaviour: the whole result is nothing. */
	public function test_match_all_an_unrecognised_status_request_still_matches_nothing(): void {
		$query = $this->query_with_hpos( true, $this->registry_for_match() );
		$args  = $query->build_args(
			[
				'match'           => 'all',
				'delivery_status' => Delivery_Status::DELIVERED,
				'status'          => [ 'nonsense' ],
			]
		);

		$this->assertArrayNotHasKey( 'post__in', $args );
		$this->assertSame( Orders_Query::NO_MATCH_META_QUERY, $args['meta_query'] );
		$this->assertSame( [], $query->resolved );
	}

	/** Every leaf of the OR dead => "matches nothing", answered without the database, on both datastores. */
	public function test_match_any_with_every_leaf_dead_lands_on_the_sentinel_without_asking_the_seam(): void {
		foreach ( [ true, false ] as $hpos ) {
			Orders_Registry::instance()->reset_for_tests();
			$registry = Orders_Registry::instance();
			$registry->register_provider( $this->provider( 'bare', '_bare_marker' ) );

			$query = $this->query_with_hpos( $hpos, $registry );
			$args  = $query->build_args(
				[
					'match'               => 'any',
					'delivery_status'     => Delivery_Status::IN_TRANSIT, // a bare carrier maps nothing => nothing is in transit.
					'status'              => [ 'nonsense' ],
				]
			);

			$this->assertArrayNotHasKey( 'post__in', $args );
			$this->assertSame( [], $query->resolved );

			if ( $hpos ) {
				$this->assertSame( Orders_Query::NO_MATCH_META_QUERY, $args['meta_query'] );
			} else {
				$this->assertSame( [], $args[ Orders_Query::QUERY_VAR_MARKER_KEYS ] );
			}
		}
	}

	/** `match=any` with 0 or 1 advanced filter is `all`: the same args and the same tree, byte for byte. */
	public function test_match_any_with_fewer_than_two_advanced_filters_changes_nothing(): void {
		$requests = [
			'none'                       => [],
			'one meta filter'            => [ 'has_tracking' => false ],
			'one delivery filter'        => [ 'delivery_status' => Delivery_Status::DELIVERED ],
			'one status filter'          => [ 'status' => [ 'processing' ] ],
			'one status_not filter'      => [ 'status_not' => [ 'cancelled' ] ],
			'unknown status nothing real' => [ 'status' => [ 'nonsense' ] ],
			'export scope + one filter'  => [
				'is_exported'  => false,
				'has_tracking' => true,
			],
			'a delivery value that is no state does not count as a filter' => [
				'delivery_status' => 'bogus',
				'has_tracking'    => true,
			],
			'search and period are not advanced filters' => [
				'search'       => 'ivanov',
				'after'        => '2026-09-01',
				'has_tracking' => true,
			],
		];

		foreach ( $requests as $label => $request ) {
			$all = $this->query_with_hpos( true, $this->registry_for_match() );
			$any = $this->query_with_hpos( true, $this->registry_for_match() );

			$this->assertSame(
				$all->build_args( $request ),
				$any->build_args( array_merge( $request, [ 'match' => 'any' ] ) ),
				"args differ under match=any: {$label}"
			);
			$this->assertSame(
				$all->build_meta_query( $request ),
				$any->build_meta_query( array_merge( $request, [ 'match' => 'any' ] ) ),
				"tree differs under match=any: {$label}"
			);
		}
	}

	/** `all`, and any value that is not `any`, is today's AND of everything. */
	public function test_match_all_and_unknown_values_keep_the_and_of_everything(): void {
		[ $scope, $delivery, $tracking ] = $this->match_parts();
		$request                          = [
			'delivery_status' => Delivery_Status::DELIVERED,
			'has_tracking'    => true,
		];

		foreach ( [ null, 'all', 'ALL', '', 'sometimes', [ 'any' ] ] as $match ) {
			$tree = $this->query_with_hpos( true, $this->registry_for_match() )->build_meta_query(
				null === $match ? $request : array_merge( $request, [ 'match' => $match ] )
			);

			$this->assertSame( [ 'relation' => 'AND', $scope, $delivery, $tracking ], $tree );
		}
	}

	/** Under `all` the order status stays the native arg and never becomes a leaf. */
	public function test_match_all_keeps_order_status_a_native_arg(): void {
		$query = $this->query_with_hpos( true, $this->registry_for_match() );
		$args  = $query->build_args(
			[
				'match'        => 'all',
				'has_tracking' => true,
				'status'       => [ 'processing' ],
			]
		);

		$this->assertSame( [ 'wc-processing' ], $args['status'] );
		$this->assertStringNotContainsString( 'order_status', (string) json_encode( $query->resolved[0][1] ) );
	}

	/** «Любое» reaches the datastore through ids only: no OR of `meta_query` clauses ever goes into the main query. */
	public function test_match_any_never_puts_an_or_of_meta_clauses_into_the_main_query(): void {
		foreach ( [ true, false ] as $hpos ) {
			$args = $this->query_with_hpos( $hpos, $this->registry_for_match() )->build_args(
				[
					'match'            => 'any',
					'delivery_status'  => Delivery_Status::DELIVERED,
					'has_tracking'     => true,
					'has_pickup_point' => true,
					'status'           => [ 'processing' ],
				]
			);

			$this->assertSame( [ 11, 12, 13 ], $args['post__in'] );
			$this->assertArrayNotHasKey( 'meta_query', $args );
			$this->assertArrayNotHasKey( Orders_Query::QUERY_VAR_MARKER_KEYS, $args );
		}
	}
}
