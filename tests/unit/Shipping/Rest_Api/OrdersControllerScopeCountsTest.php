<?php
/**
 * Unit: `Orders_Controller::get_items()`'s `scope_counts` — the two numbers the
 * «Все (134) | Новые (7)» links above the table render (SP-10 #841).
 *
 * The subject is not "does the number come back" but WHERE IT COMES FROM: every
 * assertion below pins the REQUEST PARAMS handed to {@see Orders_Query}, because
 * the whole design rests on three claims that only the params can prove —
 *
 *  1. both counts go through `Orders_Query`, never a bespoke `wc_get_orders()` or a
 *     `$wpdb` count (on the legacy CPT datastore `wc_get_orders()` silently drops
 *     `meta_query`, so a bespoke count would report the whole table there);
 *  2. both counts inherit every OTHER filter of the request — carrier, dates,
 *     statuses — because the numbers describe what the table would show;
 *  3. only ONE extra query ever runs, because the current view's own total already
 *     IS one of the two counts.
 *
 * `Orders_Query` is subclassed rather than mocked so the recorded params are the
 * real ones, and so the count path cannot quietly stop being `Orders_Query` at all.
 *
 * @package Woodev\Tests\Unit\Shipping\Rest_Api
 */

namespace Woodev\Tests\Unit\Shipping\Rest_Api;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Admin\Orders\Order_Row_Builder;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Query;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Rest_Api\Orders_Controller;
use Woodev\Tests\Unit\TestCase;

if ( ! class_exists( '\\WP_REST_Controller' ) ) {
	require_once __DIR__ . '/wp-rest-controller-stub.php';
}

/**
 * @covers \Woodev\Framework\Shipping\Rest_Api\Orders_Controller::get_items
 */
final class OrdersControllerScopeCountsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubs( [ 'add_action', 'remove_action', 'add_filter', 'remove_filter' ] );
		Functions\when( 'rest_ensure_response' )->returnArg();
		Functions\when( 'wc_string_to_bool' )->alias(
			static function ( $value ): bool {
				return is_bool( $value ) ? $value : ( 'yes' === $value || 1 === $value || 'true' === $value || '1' === $value );
			}
		);

		Orders_Registry::instance()->reset_for_tests();
		Orders_Registry::instance()->register_provider(
			Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ] )
		);
	}

	protected function tearDown(): void {
		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	/**
	 * A recording `Orders_Query`: every `get_results()` call keeps its params and
	 * answers with a canned paginated result, so a test can read back exactly what
	 * the controller asked the query layer — and how many times.
	 *
	 * The canned totals are handed in as a QUEUE rather than one number, because the
	 * order of the calls is itself part of the contract being pinned.
	 *
	 * @param int[] $totals one total per expected `get_results()` call, in order.
	 * @return Orders_Query&object{calls:array<int,array<string,mixed>>}
	 */
	private function recording_query( array $totals ) {
		return new class( Orders_Registry::instance(), $totals ) extends Orders_Query {

			/** @var array<int,array<string,mixed>> every request the controller passed, in order. */
			public array $calls = [];

			/** @var int[] */
			private array $totals;

			/**
			 * @param Orders_Registry $registry registry.
			 * @param int[]           $totals   canned totals, one per call.
			 */
			public function __construct( Orders_Registry $registry, array $totals ) {
				parent::__construct( $registry );

				$this->totals = $totals;
			}

			/**
			 * @param array<string,mixed> $request request params.
			 * @return object
			 */
			public function get_results( array $request = [] ) {
				$this->calls[] = $request;

				$total = array_shift( $this->totals );

				return (object) [
					'orders'        => [],
					'total'         => null === $total ? 0 : $total,
					'max_num_pages' => 1,
				];
			}
		};
	}

	/**
	 * @param array<string,mixed> $params request params.
	 */
	private function request( array $params ): \WP_REST_Request {
		return new \WP_REST_Request( $params + [ 'carrier' => 'all' ] );
	}

	private function controller( Orders_Query $query ): Orders_Controller {
		return new Orders_Controller( Orders_Registry::instance(), $query, new Order_Row_Builder() );
	}

	// ---- the aggregate view: «Все» is the total already in hand ----

	/**
	 * With no `is_exported` in the request the merchant is on «Все», so the row
	 * query's OWN total already IS that link's number. Reusing it is not a saved
	 * query for its own sake: it is what makes the number provably the same one the
	 * table was built from, rather than a second query's opinion of it.
	 */
	public function test_the_all_scope_reuses_the_row_querys_own_total_and_runs_exactly_one_extra_query(): void {
		$query = $this->recording_query( [ 134, 7 ] );

		$data = $this->controller( $query )->get_items( $this->request( [] ) );

		$this->assertSame( [ 'all' => 134, 'new' => 7 ], $data['scope_counts'] );
		$this->assertCount(
			2,
			$query->calls,
			'the rows query plus ONE count query — a third call means a count was asked for twice'
		);
		$this->assertSame( 134, $data['total'], 'the pagination total is untouched by the scope counts' );
	}

	/** The extra query is the «Новые» one: same request, `is_exported` forced to false. */
	public function test_the_extra_query_is_the_new_bucket_is_exported_false(): void {
		$query = $this->recording_query( [ 134, 7 ] );

		$this->controller( $query )->get_items( $this->request( [] ) );

		$this->assertArrayNotHasKey(
			'is_exported',
			$query->calls[0],
			'the rows query must stay unscoped — the aggregate view filters on export state not at all'
		);
		$this->assertFalse(
			$query->calls[1]['is_exported'],
			'«Новые» is is_exported=false: an order is new until export() writes its carrier_order_id'
		);
	}

	// ---- the «Новые» view: the roles swap, and «Все» is the one that costs a query ----

	/**
	 * ⚠ The load-bearing case. On «Новые» the row query's total is the NEW count, so
	 * the link that needs a query of its own is «Все» — and that query must drop
	 * `is_exported` entirely rather than inherit `false`, or both links would report
	 * the same number and the control would be decorative.
	 */
	public function test_the_new_scope_reuses_its_total_for_new_and_queries_the_unscoped_count_for_all(): void {
		$query = $this->recording_query( [ 7, 134 ] );

		$data = $this->controller( $query )->get_items( $this->request( [ 'is_exported' => false ] ) );

		$this->assertSame( [ 'all' => 134, 'new' => 7 ], $data['scope_counts'] );
		$this->assertCount( 2, $query->calls );
		$this->assertFalse( $query->calls[0]['is_exported'], 'the rows query is the scoped one here' );
		$this->assertArrayNotHasKey(
			'is_exported',
			$query->calls[1],
			'the «Все» count must UNSET the scope arg, not inherit false — inheriting it makes both links equal'
		);
	}

	// ---- both counts respect every other active filter ----

	/**
	 * The requirement in the operator's own words: with «Реалистичная доставка»
	 * picked, «Новые» means new among THAT carrier's orders. The numbers describe
	 * what the table would show, so every other filter of the request travels into
	 * the count query untouched.
	 */
	public function test_the_count_query_inherits_every_other_filter_of_the_request(): void {
		$query = $this->recording_query( [ 12, 3 ] );

		$this->controller( $query )->get_items(
			$this->request(
				[
					'carrier'         => 'cdek',
					'after'           => '2026-01-01',
					'before'          => '2026-09-11',
					'status'          => [ 'processing' ],
					'delivery_status' => 'in_transit',
					'search'          => 'Петров',
				]
			)
		);

		$count_call = $query->calls[1];

		$this->assertSame( 'cdek', $count_call['carrier'] );
		$this->assertSame( '2026-01-01', $count_call['after'] );
		$this->assertSame( '2026-09-11', $count_call['before'] );
		$this->assertSame( [ 'processing' ], $count_call['status'] );
		$this->assertSame( 'in_transit', $count_call['delivery_status'] );
		$this->assertSame( 'Петров', $count_call['search'] );
	}

	/**
	 * The count reads only the paginated result's `total`, so the page it asks for is
	 * pure cost — it must ask for the smallest one there is, and never inherit the
	 * table's own page. Inheriting `page` would be worse than wasteful: `total` is the
	 * same on every page, so the bug would be invisible in the numbers.
	 */
	public function test_the_count_query_asks_for_the_smallest_page_not_the_tables_own(): void {
		$query = $this->recording_query( [ 134, 7 ] );

		$this->controller( $query )->get_items( $this->request( [ 'page' => 4, 'per_page' => 50 ] ) );

		$this->assertSame( 4, $query->calls[0]['page'], 'the rows query keeps the requested page' );
		$this->assertSame( 1, $query->calls[1]['page'] );
		$this->assertSame( 1, $query->calls[1]['per_page'] );
	}

	// ---- zero is a number ----

	/**
	 * An empty «Новые» bucket must come back as `0`, not as an absent key: the links
	 * render at zero rather than disappearing, and a missing field would make the
	 * client invent a value.
	 */
	public function test_an_empty_new_bucket_reports_zero_rather_than_omitting_the_count(): void {
		$query = $this->recording_query( [ 134, 0 ] );

		$data = $this->controller( $query )->get_items( $this->request( [] ) );

		$this->assertSame( [ 'all' => 134, 'new' => 0 ], $data['scope_counts'] );
	}

	/**
	 * An `is_exported=true` request is not reachable from the two links, but the REST
	 * arg accepts it — and then NEITHER count may be reused, because the row query's
	 * total describes a third set (the exported ones) that no link shows.
	 */
	public function test_an_explicit_is_exported_true_reuses_neither_total_and_queries_both_counts(): void {
		$query = $this->recording_query( [ 127, 134, 7 ] );

		$data = $this->controller( $query )->get_items( $this->request( [ 'is_exported' => true ] ) );

		$this->assertSame( [ 'all' => 134, 'new' => 7 ], $data['scope_counts'] );
		$this->assertSame( 127, $data['total'], 'the table still reports what IT matched' );
		$this->assertCount( 3, $query->calls );
		$this->assertArrayNotHasKey( 'is_exported', $query->calls[1] );
		$this->assertFalse( $query->calls[2]['is_exported'] );
	}
}
