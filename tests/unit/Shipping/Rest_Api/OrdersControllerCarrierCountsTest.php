<?php
/**
 * Unit: `Orders_Controller::get_items()`'s `carrier_counts` — the number beside each
 * option of the «Перевозчик» picker above the table (SP-10 #855).
 *
 * WHAT THIS IS REPLACING, and why the subject is the REQUEST PARAMS rather than the
 * numbers. The counts used to be inlined into the page bootstrap
 * ({@see \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::build_bootstrap_providers()}),
 * computed once per page load with no filters at all — so under any period, scope or
 * status the picker read «СДЭК (71)» beside a table of four. That defect is not visible
 * in a count's VALUE; it is visible only in what the count was counted OVER. Every
 * assertion below therefore pins the params handed to {@see Orders_Query}.
 *
 * ⚠ TWO PROVIDERS ARE REGISTERED IN EVERY TEST, never one. With a single carrier the
 * aggregate and that carrier count the same rows, so a mix-up between «this option's
 * carrier» and «the request's carrier» produces identical numbers and passes — and this
 * code has already hidden two defects behind exactly that (#694, #837).
 *
 * `Orders_Query` is subclassed rather than mocked so the recorded params are the real
 * ones, matching {@see OrdersControllerScopeCountsTest}, whose pattern this follows.
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
final class OrdersControllerCarrierCountsTest extends TestCase {

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
		Orders_Registry::instance()->register_provider(
			Orders_Provider::create( 'yandex', 'Яндекс Доставка', '_yandex_marker', [ 'yandex' ] )
		);
	}

	protected function tearDown(): void {
		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	/**
	 * A recording `Orders_Query`: every `get_results()` call keeps its params and answers
	 * with a canned paginated result, so a test can read back exactly what the controller
	 * asked the query layer — and in what order.
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

	/**
	 * Every count query this request issued for the carrier picker, keyed by the carrier
	 * it counted. The rows query and the scope count are skipped by position: the
	 * controller runs rows first, then one scope count, then the carrier counts.
	 *
	 * @param object $query the recording query.
	 * @return array<string,array<string,mixed>>
	 */
	private function carrier_count_calls( $query ): array {
		$calls = [];

		foreach ( array_slice( $query->calls, 2 ) as $call ) {
			$calls[ (string) $call['carrier'] ] = $call;
		}

		return $calls;
	}

	// ---- what is counted ----

	/** One number for the aggregate and one per registered provider — the picker's own option set. */
	public function test_it_counts_the_aggregate_and_every_registered_provider(): void {
		$query = $this->recording_query( [ 12, 4, 9, 3 ] );

		$data = $this->controller( $query )->get_items( $this->request( [] ) );

		$this->assertSame( [ 'all' => 12, 'cdek' => 9, 'yandex' => 3 ], $data['carrier_counts'] );
	}

	/**
	 * The aggregate is not the sum of the others and must not be assumed to be: an order
	 * can match two providers' markers, and one can match none. It is counted, like the rest.
	 */
	public function test_the_aggregate_is_counted_rather_than_summed(): void {
		$query = $this->recording_query( [ 12, 4, 9, 3 ] );

		$data = $this->controller( $query )->get_items( $this->request( [ 'carrier' => 'cdek' ] ) );

		// 12 is the cdek view's own total; `all` is the 9 its own query answered, NOT 9 + 3.
		$this->assertSame( 9, $data['carrier_counts']['all'] );
		$this->assertSame( 12, $data['carrier_counts']['cdek'] );
	}

	// ---- what they are counted OVER — the whole point of #855 ----

	/**
	 * ⚠ THE LOAD-BEARING TEST. «Сколько заказов у СДЭК» is a question that includes the
	 * period, and the answer that ignored it is what #855 exists to remove. Every count
	 * query must carry the request's own `after`/`before`.
	 */
	public function test_every_carrier_count_is_taken_under_the_requests_own_period(): void {
		$query = $this->recording_query( [ 12, 4, 9, 3 ] );

		$this->controller( $query )->get_items(
			$this->request(
				[
					'after'  => '2026-09-07',
					'before' => '2026-09-12',
				]
			)
		);

		$calls = $this->carrier_count_calls( $query );

		$this->assertSame( [ 'cdek', 'yandex' ], array_keys( $calls ), 'the current carrier reuses the total instead of querying' );

		foreach ( $calls as $carrier => $call ) {
			$this->assertSame( '2026-09-07', $call['after'], "{$carrier}'s count ignored the period" );
			$this->assertSame( '2026-09-12', $call['before'], "{$carrier}'s count ignored the period" );
		}
	}

	/** The rest of the filter row travels into the count queries the same way. */
	public function test_the_count_queries_inherit_every_other_filter_of_the_request(): void {
		$query = $this->recording_query( [ 12, 4, 9, 3 ] );

		$this->controller( $query )->get_items(
			$this->request(
				[
					'status'          => [ 'processing' ],
					'delivery_status' => 'in_transit',
					'search'          => 'Петров',
				]
			)
		);

		foreach ( $this->carrier_count_calls( $query ) as $carrier => $call ) {
			$this->assertSame( [ 'processing' ], $call['status'], "{$carrier}'s count ignored the status filter" );
			$this->assertSame( 'in_transit', $call['delivery_status'] );
			$this->assertSame( 'Петров', $call['search'] );
		}
	}

	/**
	 * ⚠ The scope is INHERITED here, unlike in `scope_counts` where it is the overridden
	 * axis. Standing in «Новые», «СДЭК (9)» has to mean nine NEW СДЭК orders — the number
	 * describes the table the merchant would land on by clicking that option, and clicking
	 * it does not leave «Новые».
	 */
	public function test_the_count_queries_inherit_the_scope_rather_than_dropping_it(): void {
		$query = $this->recording_query( [ 12, 40, 9, 3 ] );

		$this->controller( $query )->get_items( $this->request( [ 'is_exported' => false ] ) );

		foreach ( $this->carrier_count_calls( $query ) as $carrier => $call ) {
			$this->assertArrayHasKey( 'is_exported', $call, "{$carrier}'s count left the «Новые» scope" );
			$this->assertFalse( $call['is_exported'] );
		}
	}

	/** `carrier` is the ONE axis overridden — it is what the picker selects between. */
	public function test_each_count_overrides_only_the_carrier(): void {
		$query = $this->recording_query( [ 12, 4, 9, 3 ] );

		$this->controller( $query )->get_items( $this->request( [ 'carrier' => 'cdek', 'after' => '2026-09-07' ] ) );

		$calls = $this->carrier_count_calls( $query );

		$this->assertSame( [ 'all', 'yandex' ], array_keys( $calls ) );
		$this->assertSame( 'all', $calls['all']['carrier'] );
		$this->assertSame( 'yandex', $calls['yandex']['carrier'] );
	}

	// ---- cost, and the one count that is never a second opinion ----

	/**
	 * The current carrier's own number IS the row query's total, reused rather than
	 * re-asked. Not an optimisation for its own sake: it makes that option's number
	 * provably the same one the table beside it was built from.
	 */
	public function test_the_current_carrier_reuses_the_row_querys_own_total(): void {
		$query = $this->recording_query( [ 12, 4, 9, 3 ] );

		$data = $this->controller( $query )->get_items( $this->request( [ 'carrier' => 'yandex' ] ) );

		$this->assertSame( 12, $data['carrier_counts']['yandex'] );
		$this->assertSame( 12, $data['total'], 'the pagination total is untouched by the counts' );
		$this->assertArrayNotHasKey( 'yandex', $this->carrier_count_calls( $query ) );
	}

	/**
	 * The counts read only a paginated result's `total`, so the page they ask for is pure
	 * cost — and inheriting the table's own page would be worse than wasteful: `total` is
	 * identical on every page, so the bug would never show in a number.
	 */
	public function test_the_count_queries_ask_for_the_smallest_page_not_the_tables_own(): void {
		$query = $this->recording_query( [ 12, 4, 9, 3 ] );

		$this->controller( $query )->get_items( $this->request( [ 'page' => 4, 'per_page' => 50 ] ) );

		$this->assertSame( 4, $query->calls[0]['page'], 'the rows query keeps the requested page' );

		foreach ( $this->carrier_count_calls( $query ) as $carrier => $call ) {
			$this->assertSame( 1, $call['page'], "{$carrier}'s count paged like the table" );
			$this->assertSame( 1, $call['per_page'] );
		}
	}

	/** Exactly one query per carrier that is not the current one — never two for the same option. */
	public function test_it_runs_one_query_per_other_carrier_and_no_more(): void {
		$query = $this->recording_query( [ 12, 4, 9, 3 ] );

		$this->controller( $query )->get_items( $this->request( [] ) );

		// rows + one scope count + cdek + yandex.
		$this->assertCount( 4, $query->calls );
	}

	// ---- zero is a number ----

	/**
	 * A carrier with nothing in the period reports `0`, not an absent key. The client
	 * tells the two apart deliberately — an absent count renders no number at all,
	 * because «мы ещё не знаем» and «их нет» are different things to tell a merchant.
	 */
	public function test_a_carrier_with_no_matching_orders_reports_zero_rather_than_vanishing(): void {
		$query = $this->recording_query( [ 9, 2, 9, 0 ] );

		$data = $this->controller( $query )->get_items( $this->request( [] ) );

		$this->assertSame( [ 'all' => 9, 'cdek' => 9, 'yandex' => 0 ], $data['carrier_counts'] );
		$this->assertArrayHasKey( 'yandex', $data['carrier_counts'] );
	}

	/**
	 * With no provider registered at all the page does not render, but the route still
	 * answers — and the aggregate is the only option there is.
	 */
	public function test_with_no_providers_registered_only_the_aggregate_is_counted(): void {
		Orders_Registry::instance()->reset_for_tests();

		$query = $this->recording_query( [ 0, 0 ] );

		$data = $this->controller( $query )->get_items( $this->request( [] ) );

		$this->assertSame( [ 'all' => 0 ], $data['carrier_counts'] );
	}
}
