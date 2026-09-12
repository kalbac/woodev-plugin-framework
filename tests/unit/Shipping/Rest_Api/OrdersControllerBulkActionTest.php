<?php
/**
 * Unit: `POST /shipping/orders/bulk/{action}` — performs one action across many orders in a
 * single request (card #874).
 *
 * Covers: skipped-vs-failed accounting (an order the action does not apply to is SKIPPED, not
 * failed — the operator's own rule), «из N» being the ELIGIBLE count rather than the requested
 * one, the Russian plural sentence at 1/2/5 failures, the `eligible === 0` explanatory message,
 * `export()` returning `''` (#860) counting as a failure, one order throwing not aborting the
 * batch, the 100-id cap, and an action outside the per-order recomputed gate being refused for
 * that order alone — never trusting the client's selection, same as the single-order route.
 *
 * ⚠ At least one test registers TWO providers and dispatches a mixed-carrier batch: with a
 * single carrier registered, "this order's own carrier" and "the only carrier there is" are the
 * same object, and a mix-up between them would pass silently (same reasoning
 * `OrdersControllerCarrierCountsTest`'s own docblock gives for its own two providers).
 *
 * @package Woodev\Tests\Unit\Shipping\Rest_Api
 */

namespace Woodev\Tests\Unit\Shipping\Rest_Api;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Order_Actions;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;
use Woodev\Framework\Shipping\Rest_Api\Orders_Controller;
use Woodev\Tests\Unit\TestCase;

if ( ! class_exists( '\\WP_REST_Controller' ) ) {
	require_once __DIR__ . '/wp-rest-controller-stub.php';
}

require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';

/**
 * @covers \Woodev\Framework\Shipping\Rest_Api\Orders_Controller::perform_bulk_action
 * @covers \Woodev\Framework\Shipping\Rest_Api\Orders_Controller::validate_bulk_ids
 */
final class OrdersControllerBulkActionTest extends TestCase {

	/** @var array<int,array<string,mixed>> post meta, keyed by [order_id][meta_key]. */
	private $meta = [];

	/** @var array<int,\WC_Order> orders `wc_get_order()` can resolve, keyed by id. */
	private $orders = [];

	protected function setUp(): void {
		parent::setUp();

		$this->meta   = [];
		$this->orders = [];

		Functions\stubs( [ 'add_action', 'remove_action', 'add_filter', 'remove_filter' ] );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'rest_ensure_response' )->returnArg();
		Functions\when( 'get_post_meta' )->alias(
			function ( int $post_id, string $key, bool $single ) {
				return $this->meta[ $post_id ][ $key ] ?? '';
			}
		);
		Functions\when( 'wc_get_order' )->alias(
			function ( $id ) {
				return $this->orders[ (int) $id ] ?? false;
			}
		);

		// Order_Row_Builder::build() runs for real for every SUCCEEDED order (the response
		// rebuilds its row) — same WC-free stubs OrderRowBuilderTest/OrdersControllerPerformActionTest use.
		Functions\when( 'wc_get_order_status_name' )->alias(
			static function ( string $status ): string {
				return ucfirst( $status );
			}
		);
		Functions\when( 'wc_price' )->alias(
			static function ( $amount ): string {
				return (string) $amount;
			}
		);
		Functions\when( 'wp_strip_all_tags' )->alias(
			static function ( string $text, bool $remove_breaks = false ): string {
				return trim( strip_tags( $text ) );
			}
		);

		Orders_Registry::instance()->reset_for_tests();
	}

	protected function tearDown(): void {
		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	private function controller(): Orders_Controller {
		return new Orders_Controller( Orders_Registry::instance() );
	}

	private function request( string $action, array $ids ): \WP_REST_Request {
		return new \WP_REST_Request(
			[
				'action' => $action,
				'ids'    => $ids,
			]
		);
	}

	private function register_provider( string $id, string $label, string $marker_key, array $args = [] ): Orders_Provider {
		$provider = Orders_Provider::create( $id, $label, $marker_key, [ $id ], $args );

		Orders_Registry::instance()->register_provider( $provider );

		return $provider;
	}

	private function register_handler( string $provider_id ): Abstract_Shipment_Handler {
		$handler = Mockery::mock( Abstract_Shipment_Handler::class );

		Orders_Registry::instance()->register_shipment_handler( $provider_id, $handler );

		return $handler;
	}

	/**
	 * A `\WC_Order` double carrying `$marker_key`'s marker meta, so
	 * {@see Orders_Controller::resolve_matched_provider()} resolves it to that provider.
	 */
	private function order( int $id, string $status, string $marker_key ): \WC_Order {
		$this->meta[ $id ][ $marker_key ] = '1';

		$order = Mockery::mock( '\WC_Order' );

		$defaults = [
			'get_id'                          => $id,
			'get_order_number'                => (string) $id,
			'get_edit_order_url'              => "https://example.test/wp-admin/post.php?post={$id}&action=edit",
			'get_status'                       => $status,
			'get_date_created'                => null,
			'get_formatted_billing_full_name' => 'Иван Иванов',
			'get_customer_id'                  => 0,
			'get_billing_email'                => 'ivan@example.test',
			'get_billing_phone'                => '+79991234567',
			'get_payment_method_title'         => 'Банковская карта',
			'get_formatted_order_total'        => '1000 руб.',
			'needs_payment'                    => false,
			'get_shipping_method'              => 'СДЭК',
			'get_shipping_total'               => '300',
			'get_shipping_postcode'            => '',
			'get_shipping_state'               => '',
			'get_shipping_city'                => '',
			'get_shipping_address_1'           => '',
			'get_billing_postcode'             => '',
			'get_billing_state'                => '',
			'get_billing_city'                 => '',
			'get_billing_address_1'            => '',
			'get_shipping_methods'             => [],
		];

		foreach ( $defaults as $method => $value ) {
			$order->shouldReceive( $method )->andReturn( $value );
		}

		$order->shouldReceive( 'read_meta_data' )->byDefault();

		$this->orders[ $id ] = $order;

		return $order;
	}

	/**
	 * A `WC_Order` double with NO marker at all — belongs to no registered provider.
	 */
	private function unresolvable_order( int $id ): \WC_Order {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( $id );

		$this->orders[ $id ] = $order;

		return $order;
	}

	// ----- skipped vs failed accounting -----

	/**
	 * A batch mixing: a succeeding export, a failing export (#860's `''`), a status the action
	 * does not apply to, an order belonging to no registered provider, and an unknown id —
	 * requested=6, eligible=2 (only the two 'pending' cdek orders), skipped=4, succeeded=1,
	 * failed=1.
	 */
	public function test_skipped_and_failed_are_accounted_separately(): void {
		$this->register_provider( 'cdek', 'СДЭК', '_cdek_marker' );
		$handler = $this->register_handler( 'cdek' );
		$handler->shouldReceive( 'export' )->twice()->andReturnValues( [ 'CARRIER-1', '' ] );

		$this->order( 1, 'pending', '_cdek_marker' );      // eligible, succeeds
		$this->order( 2, 'pending', '_cdek_marker' );      // eligible, export() returns '' => fails
		$this->order( 3, 'completed', '_cdek_marker' );    // wrong status => not in gate => skipped
		$this->unresolvable_order( 4 );                    // no provider matches => skipped
		// id 5 is never registered in $this->orders => wc_get_order() returns false => skipped
		$this->order( 6, 'completed', '_cdek_marker' );    // also wrong status => skipped

		$result = $this->controller()->perform_bulk_action( $this->request( Order_Actions::EXPORT, [ 1, 2, 3, 4, 5, 6 ] ) );

		$this->assertSame( 6, $result['requested'] );
		$this->assertSame( 2, $result['eligible'] );
		$this->assertSame( 4, $result['skipped'] );
		$this->assertSame( 1, $result['succeeded'] );
		$this->assertSame( 1, $result['failed'] );
		$this->assertCount( 1, $result['rows'], 'only the one CHANGED (succeeded) order gets a rebuilt row' );
		$this->assertSame( 1, $result['rows'][0]['id'] );
	}

	/** One order throwing must not abort the rest of the batch. */
	public function test_one_order_throwing_does_not_abort_the_batch(): void {
		$this->register_provider( 'cdek', 'СДЭК', '_cdek_marker' );
		$handler = $this->register_handler( 'cdek' );
		$handler->shouldReceive( 'export' )
			->once()
			->andThrow( new \RuntimeException( 'carrier API down' ) );
		$handler->shouldReceive( 'export' )
			->once()
			->andReturn( 'CARRIER-2' );

		$this->order( 1, 'pending', '_cdek_marker' );
		$this->order( 2, 'pending', '_cdek_marker' );

		$result = $this->controller()->perform_bulk_action( $this->request( Order_Actions::EXPORT, [ 1, 2 ] ) );

		$this->assertSame( 2, $result['eligible'] );
		$this->assertSame( 1, $result['succeeded'] );
		$this->assertSame( 1, $result['failed'] );
	}

	// ----- mixed-carrier aggregate batch (TWO providers registered) -----

	/** Each order dispatches through ITS OWN carrier's handler, never the other one's. */
	public function test_a_mixed_carrier_batch_dispatches_each_order_through_its_own_handler(): void {
		$this->register_provider( 'cdek', 'СДЭК', '_cdek_marker' );
		$this->register_provider( 'yandex', 'Яндекс Доставка', '_yandex_marker' );
		$cdek_handler   = $this->register_handler( 'cdek' );
		$yandex_handler = $this->register_handler( 'yandex' );

		$cdek_handler->shouldReceive( 'export' )->once()->andReturn( 'CDEK-1' );
		$yandex_handler->shouldReceive( 'export' )->once()->andReturn( 'YANDEX-1' );

		$this->order( 1, 'pending', '_cdek_marker' );
		$this->order( 2, 'pending', '_yandex_marker' );

		$result = $this->controller()->perform_bulk_action( $this->request( Order_Actions::EXPORT, [ 1, 2 ] ) );

		$this->assertSame( 2, $result['eligible'] );
		$this->assertSame( 2, $result['succeeded'] );
		$this->assertSame( 0, $result['failed'] );
	}

	// ----- "из N" is the ELIGIBLE count -----

	/** The operator's own worked example: 10 selected, 5 eligible, 3 succeeded, 2 failed. */
	public function test_out_of_n_is_the_eligible_count_not_the_requested_one(): void {
		$this->stub_real_russian_plurals();

		$this->register_provider( 'cdek', 'СДЭК', '_cdek_marker' );
		$handler = $this->register_handler( 'cdek' );
		$handler->shouldReceive( 'export' )->times( 5 )->andReturnValues( [ 'C1', 'C2', 'C3', '', '' ] );

		for ( $i = 1; $i <= 5; $i++ ) {
			$this->order( $i, 'pending', '_cdek_marker' );
		}
		// Five more ids that are simply unresolvable — bulk of the "requested" that is not
		// "eligible" at all.
		for ( $i = 6; $i <= 10; $i++ ) {
			$this->unresolvable_order( $i );
		}

		$result = $this->controller()->perform_bulk_action( $this->request( Order_Actions::EXPORT, range( 1, 10 ) ) );

		$this->assertSame( 10, $result['requested'] );
		$this->assertSame( 5, $result['eligible'] );
		$this->assertSame( 3, $result['succeeded'] );
		$this->assertSame( 2, $result['failed'] );
		$this->assertSame( 'Экспортировано 3 из 5', $result['messages']['success'] );
		$this->assertSame( 'Не удалось экспортировать 2 заказа из 5', $result['messages']['error'] );
	}

	// ----- eligible === 0 -----

	/** Nothing among the requested ids applies to the action: a single explanatory message. */
	public function test_eligible_zero_returns_a_single_explanatory_message(): void {
		$this->register_provider( 'cdek', 'СДЭК', '_cdek_marker' );
		$this->register_handler( 'cdek' );

		$this->order( 1, 'completed', '_cdek_marker' );
		$this->unresolvable_order( 2 );

		$result = $this->controller()->perform_bulk_action( $this->request( Order_Actions::EXPORT, [ 1, 2 ] ) );

		$this->assertSame( 0, $result['eligible'] );
		$this->assertSame( 0, $result['succeeded'] );
		$this->assertSame( 0, $result['failed'] );
		$this->assertArrayNotHasKey( 'success', $result['messages'] );
		$this->assertArrayHasKey( 'error', $result['messages'] );
		$this->assertNotSame( '', $result['messages']['error'] );
	}

	// ----- action outside the recomputed gate -----

	/** An action the server's OWN recomputed gate does not list is refused PER ORDER — skipped. */
	public function test_an_action_outside_the_recomputed_gate_is_skipped_per_order(): void {
		$this->register_provider( 'cdek', 'СДЭК', '_cdek_marker' );
		$this->register_handler( 'cdek' );

		// 'completed' offers neither export (wrong status) nor update/cancel (never exported).
		$this->order( 1, 'completed', '_cdek_marker' );

		$result = $this->controller()->perform_bulk_action( $this->request( Order_Actions::CANCEL, [ 1 ] ) );

		$this->assertSame( 1, $result['requested'] );
		$this->assertSame( 0, $result['eligible'] );
		$this->assertSame( 1, $result['skipped'] );
	}

	// ----- the 100-id cap -----

	public function test_validate_bulk_ids_accepts_exactly_the_cap(): void {
		$this->assertTrue( Orders_Controller::validate_bulk_ids( range( 1, 100 ), new \WP_REST_Request(), 'ids' ) );
	}

	public function test_validate_bulk_ids_rejects_one_over_the_cap(): void {
		$result = Orders_Controller::validate_bulk_ids( range( 1, 101 ), new \WP_REST_Request(), 'ids' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	// ----- _n() plurals at 1, 2, 5 -----

	/**
	 * Brain Monkey's own `_n()` stub is binary (singular vs ONE plural) — it cannot exercise
	 * Russian's three forms («1 заказ» / «2 заказа» / «5 заказов»). This replaces it with the
	 * REAL gettext Plural-Forms formula (`n%10==1 && n%100!=11 ? 0 : n%10 in 2..4 && n%100 not in
	 * 10..19 ? 1 : 2`) over a small catalogue matching exactly the singular/plural pairs
	 * {@see \Woodev\Framework\Shipping\Rest_Api\Orders_Controller::bulk_failure_message()} calls
	 * `_n()` with — proving the controller threads the failed COUNT and the right msgid pair
	 * through `_n()` correctly, which is the part unit-testable without a compiled `.mo`.
	 */
	private function stub_real_russian_plurals(): void {
		$catalog = [
			'Не удалось экспортировать %1$d заказ из %2$d|Не удалось экспортировать %1$d заказов из %2$d' => [
				'Не удалось экспортировать %1$d заказ из %2$d',
				'Не удалось экспортировать %1$d заказа из %2$d',
				'Не удалось экспортировать %1$d заказов из %2$d',
			],
			'Не удалось отменить %1$d заказ из %2$d|Не удалось отменить %1$d заказов из %2$d' => [
				'Не удалось отменить %1$d заказ из %2$d',
				'Не удалось отменить %1$d заказа из %2$d',
				'Не удалось отменить %1$d заказов из %2$d',
			],
			'Не удалось обновить %1$d заказ из %2$d|Не удалось обновить %1$d заказов из %2$d' => [
				'Не удалось обновить %1$d заказ из %2$d',
				'Не удалось обновить %1$d заказа из %2$d',
				'Не удалось обновить %1$d заказов из %2$d',
			],
		];

		Functions\when( '_n' )->alias(
			static function ( $single, $plural, $number ) use ( $catalog ) {
				$key = $single . '|' . $plural;

				if ( ! isset( $catalog[ $key ] ) ) {
					return 1 === (int) $number ? $single : $plural;
				}

				$n = (int) $number;

				if ( 1 === $n % 10 && 11 !== $n % 100 ) {
					$form = 0;
				} elseif ( $n % 10 >= 2 && $n % 10 <= 4 && ! ( $n % 100 >= 10 && $n % 100 <= 19 ) ) {
					$form = 1;
				} else {
					$form = 2;
				}

				return $catalog[ $key ][ $form ];
			}
		);
	}

	/**
	 * @dataProvider provide_plural_counts
	 */
	public function test_the_failure_sentence_uses_the_correct_russian_plural_form( int $failed_count, string $expected_tail ): void {
		$this->stub_real_russian_plurals();

		$this->register_provider( 'cdek', 'СДЭК', '_cdek_marker' );
		$handler = $this->register_handler( 'cdek' );
		$handler->shouldReceive( 'export' )->times( $failed_count )->andReturn( '' );

		$ids = [];
		for ( $i = 1; $i <= $failed_count; $i++ ) {
			$this->order( $i, 'pending', '_cdek_marker' );
			$ids[] = $i;
		}

		$result = $this->controller()->perform_bulk_action( $this->request( Order_Actions::EXPORT, $ids ) );

		$this->assertSame( $failed_count, $result['failed'] );
		$this->assertSame( $expected_tail, $result['messages']['error'] );
	}

	/**
	 * @return array<string, array{0:int, 1:string}>
	 */
	public function provide_plural_counts(): array {
		return [
			'1 order — singular'  => [ 1, 'Не удалось экспортировать 1 заказ из 1' ],
			'2 orders — few'      => [ 2, 'Не удалось экспортировать 2 заказа из 2' ],
			'5 orders — many'     => [ 5, 'Не удалось экспортировать 5 заказов из 5' ],
		];
	}
}
