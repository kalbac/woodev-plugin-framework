<?php
/**
 * A delivery service's news about a seller-delivered order, passed on to the marketplace.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Flows;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Admin\Order\Carrier_Actions;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action_Refused;
use Woodev\Framework\Marketplace\Admin\Shared\Carrier_State;
use Woodev\Framework\Marketplace\Data\Wpdb_Seller_Action_Store;
use Woodev\Framework\Marketplace\Flows\Carrier_Bridge;
use Woodev\Framework\Marketplace\Flows\Carrier_Notice;
use Woodev\Framework\Marketplace\Flows\Code_Required_Accounts;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Delivered_Order_Status;
use Woodev\Framework\Shipping\Order\Delivery_Status;
use Woodev\Framework\Shipping\Settings\Export_Settings;
use Woodev\Framework\Shipping\Shipping_Plugin;
use Woodev\Tests\Unit\Marketplace\Support\Carrier_Test_Case;
use Woodev\Tests\Unit\Marketplace\Support\Stateful_Order;

/**
 * @covers \Woodev\Framework\Marketplace\Flows\Carrier_Bridge
 * @covers \Woodev\Framework\Marketplace\Flows\Carrier_Notice
 * @covers \Woodev\Framework\Marketplace\Flows\Code_Required_Accounts
 * @covers \Woodev\Framework\Marketplace\Admin\Order\Seller_Unit_Result
 */
final class CarrierBridgeTest extends Carrier_Test_Case {
	private const JOB = 'woodev_fake_seller_carrier_action';

	private const ASK_CODE = 'Подтвердите вручение: нужен код покупателя.';

	protected function setUp(): void {
		parent::setUp();

		Orders_Registry::instance()->reset_for_tests();
	}

	protected function tearDown(): void {
		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	public function test_the_bridge_listens_to_both_shipment_events_and_to_its_job(): void {
		Actions\expectAdded( 'woodev_shipping_order_exported' )->once();
		Actions\expectAdded( 'woodev_shipping_delivery_status_changed' )->once();
		Actions\expectAdded( self::JOB )->once();

		$this->bridge()->register();

		$this->addToAssertionCount( 3 );
	}

	public function test_the_shipment_states_are_the_shipping_modules_own(): void {
		$this->assertSame(
			[ Delivery_Status::DELIVERED, Delivery_Status::RETURNING, Delivery_Status::RETURNED ],
			[ Carrier_Bridge::STATE_DELIVERED, Carrier_Bridge::STATE_RETURNING, Carrier_Bridge::STATE_RETURNED ]
		);
	}

	public function test_an_export_queues_one_job_and_sends_nothing_in_the_delivery_services_request(): void {
		$order = $this->delivery_order();

		$this->bridge()->on_exported( $order->order );
		$this->bridge()->on_exported( $order->order );

		$queued = $this->scheduler->of( self::JOB );
		$this->assertCount( 1, $queued );
		$this->assertSame( [ $this->account_id, 700, Carrier_Actions::HANDED_OVER ], $queued[0]['args'] );
		$this->assertSame( [], $this->adapter->calls );
	}

	public function test_an_export_hands_the_order_over_exactly_once(): void {
		$order = $this->delivery_order();
		$this->bridge()->on_exported( $order->order );

		$this->run_jobs();
		$this->bridge()->on_exported( $order->order ); // The same shipment announced again before the order is refreshed.
		$this->run_jobs();

		$this->assertSame( [ 'deliver' ], $this->adapter->sent_actions() );
		$this->assertSame( [], $this->pending() );
		$this->assertArrayNotHasKey( Carrier_Bridge::META_ATTEMPTS, $order->meta );
		$this->assertNull( Carrier_Notice::of( $order->order ) );
	}

	/**
	 * @return array<string,array{0:\Throwable}>
	 */
	public function failures(): array {
		return [
			'no answer'         => [ new \RuntimeException( 'timeout' ) ],
			'nothing was done'  => [ new Seller_Action_Refused( 'Маркетплейс просит подождать.', 502 ) ],
		];
	}

	/**
	 * @dataProvider failures
	 * @param \Throwable $failure What the call throws.
	 * @return void
	 */
	public function test_a_failed_request_is_tried_again_by_the_modules_job( \Throwable $failure ): void {
		$order                  = $this->delivery_order();
		$this->adapter->failure = $failure;
		$this->bridge()->on_exported( $order->order );

		$this->run_jobs();

		$this->assertCount( 1, $this->pending() );
		$this->assertEqualsWithDelta( 60, $this->pending()[0]['delay'], 2 );
		$this->assertSame( [ Carrier_Actions::HANDED_OVER => 1 ], $order->meta[ Carrier_Bridge::META_ATTEMPTS ] );

		$this->adapter->failure = null;
		$this->now             += Wpdb_Seller_Action_Store::KEPT_TTL + 1;
		$this->run_jobs();

		$this->assertSame( [ 'deliver', 'deliver' ], $this->adapter->sent_actions() );
		$this->assertSame( [], $this->pending() );
		$this->assertArrayNotHasKey( Carrier_Bridge::META_ATTEMPTS, $order->meta );
	}

	public function test_a_lost_answer_is_not_sent_again_while_the_action_may_have_gone_through(): void {
		$order                  = $this->delivery_order();
		$this->adapter->failure = new \RuntimeException( 'timeout' );
		$this->bridge()->on_exported( $order->order );
		$this->run_jobs();
		$this->adapter->failure = null;

		$this->run_jobs(); // Within the hour the claim holds the unit.

		$this->assertCount( 1, $this->adapter->calls );
		$this->assertCount( 1, $this->pending() );
	}

	public function test_the_job_gives_up_after_its_last_attempt_and_asks_the_merchant(): void {
		$order                  = $this->delivery_order();
		$this->adapter->failure = new Seller_Action_Refused( 'Маркетплейс не отвечает.', 502 );
		$this->bridge()->on_exported( $order->order );

		for ( $run = 0; $run < Carrier_Bridge::MAX_ATTEMPTS + 3; $run++ ) {
			$this->run_jobs();
		}

		$this->assertCount( Carrier_Bridge::MAX_ATTEMPTS, $this->adapter->calls );
		$this->assertSame( [], $this->pending() );
		$this->assertSame( 'deliver', Carrier_Notice::of( $order->order )['action'] );
		$this->assertStringContainsString( 'Передать в доставку', Carrier_Notice::of( $order->order )['text'] );
		$this->assertStringContainsString( 'Маркетплейс не отвечает.', Carrier_Notice::of( $order->order )['text'] );
		$this->assertCount( 1, $this->notes[700] );
	}

	public function test_a_refused_hand_over_is_not_repeated(): void {
		$order                          = $this->delivery_order();
		$this->adapter->refusals['u-1'] = 'Не заполнен код маркировки.';
		$this->bridge()->on_exported( $order->order );

		$this->run_jobs();

		$this->assertCount( 1, $this->adapter->calls );
		$this->assertSame( [], $this->pending() );
		$this->assertSame( 'Не удалось выполнить действие «Передать в доставку»: Не заполнен код маркировки.', Carrier_Notice::of( $order->order )['text'] );
		$this->assertCount( 1, $this->notes[700], 'The run itself writes the note.' );
	}

	public function test_a_unit_the_marketplace_objects_to_is_left_to_the_merchant(): void {
		$order                            = $this->delivery_order();
		$this->adapter->objections['u-1'] = 'У товара нет кода.';
		$this->bridge()->on_exported( $order->order );

		$this->run_jobs();

		$this->assertSame( [], $this->adapter->calls );
		$this->assertSame( [], $this->pending() );
		$this->assertStringContainsString( 'У товара нет кода.', Carrier_Notice::of( $order->order )['text'] );
		$this->assertStringContainsString( 'У товара нет кода.', $this->notes[700][0] );
	}

	public function test_a_delivery_confirms_the_order_once_without_a_code(): void {
		$order = $this->delivery_order( 700, [ [ 'u-1', 'Кружка', 'deliver' ] ] );

		$this->deliver( $order );
		$this->run_jobs();
		$this->deliver( $order );
		$this->run_jobs();

		$this->assertSame( [ 'receive' ], $this->adapter->sent_actions() );
		$this->assertSame( '', $this->adapter->calls[0]->value( 'code' ) );
		$this->assertSame( [], $this->pending() );
	}

	public function test_a_delivery_hands_the_order_over_first_and_waits_for_the_marketplace_to_move_it(): void {
		$order = $this->delivery_order();
		$this->deliver( $order );

		$this->run_jobs();

		$this->assertSame( [ 'deliver' ], $this->adapter->sent_actions() );
		$this->assertCount( 1, $this->pending() );

		$this->run_jobs(); // Still not refreshed: nothing is sent twice.
		$this->assertSame( [ 'deliver' ], $this->adapter->sent_actions() );

		$this->move( $order, 'deliver' );
		$this->run_jobs();

		$this->assertSame( [ 'deliver', 'receive' ], $this->adapter->sent_actions() );
		$this->assertSame( [], $this->pending() );
	}

	public function test_a_refusal_for_the_buyers_code_asks_the_merchant_and_stops_the_automatic_attempts(): void {
		$first                     = $this->delivery_order( 700, [ [ 'u-1', 'Кружка', 'deliver' ] ] );
		$second                    = $this->delivery_order( 701, [ [ 'u-2', 'Кружка', 'deliver' ] ] );
		$this->adapter->needs_code = [ 'u-1' ];

		$this->deliver( $first );
		$this->run_jobs();
		$this->deliver( $second );
		$this->run_jobs();

		$this->assertSame( [ 'receive' ], $this->adapter->sent_actions(), 'The refused call is the only one.' );
		$this->assertSame( [], $this->pending() );
		$this->assertTrue( ( new Code_Required_Accounts( self::PLUGIN_ID ) )->has( $this->account_id ) );

		foreach ( [ $first, $second ] as $order ) {
			$this->assertSame(
				[
					'action' => 'receive',
					'text'   => self::ASK_CODE,
				],
				Carrier_Notice::of( $order->order )
			);
		}

		$this->assertContains( 'Тестовый маркетплейс: ' . self::ASK_CODE, $this->notes[701] );
	}

	public function test_any_other_refusal_shows_its_reason_and_teaches_the_account_nothing(): void {
		$order                          = $this->delivery_order( 700, [ [ 'u-1', 'Кружка', 'deliver' ] ] );
		$this->adapter->refusals['u-1'] = 'Заказ не найден.';

		$this->deliver( $order );
		$this->run_jobs();

		$this->assertSame( 'Не удалось выполнить действие «Вручён»: Заказ не найден.', Carrier_Notice::of( $order->order )['text'] );
		$this->assertFalse( ( new Code_Required_Accounts( self::PLUGIN_ID ) )->has( $this->account_id ) );
		$this->assertSame( [], $this->pending() );
	}

	public function test_a_delivery_of_an_order_already_confirmed_sends_nothing(): void {
		$order = $this->delivery_order( 700, [ [ 'u-1', 'Кружка', 'sold' ] ] );

		$this->deliver( $order );
		$this->run_jobs();

		$this->assertSame( [], $this->adapter->calls );
		$this->assertSame( [], $this->pending() );
	}

	/**
	 * What the order was before the delivery report, what the merchant did before the job ran, and where it must end up.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function closed_orders(): array {
		return [
			'closed by the delivery service'    => [ 'processing', '', 'processing' ],
			'closed by the merchant beforehand' => [ 'completed', '', 'completed' ],
			'moved on by the merchant since'    => [ 'processing', 'on-hold', 'on-hold' ],
		];
	}

	/**
	 * The delivery service's own «Статус доставленного заказа» step runs between the report and the job, as on a site.
	 *
	 * @dataProvider closed_orders
	 * @param string $before   Status at the delivery report.
	 * @param string $since    Status the merchant set after it; empty for none.
	 * @param string $expected Status after the job.
	 * @return void
	 */
	public function test_an_order_that_waits_for_the_code_does_not_stay_closed_by_the_delivery_service( string $before, string $since, string $expected ): void {
		$order    = $this->delivery_order( 700, [ [ 'u-1', 'Кружка', 'deliver' ] ] );
		$provider = $this->register_delivery_service( 'completed' );

		$this->adapter->needs_code   = [ 'u-1' ];
		$order->props['status']      = $before;
		$order->meta['_cdek_marker'] = '1';

		$this->deliver( $order );
		Delivered_Order_Status::apply( $order->order, Delivery_Status::IN_TRANSIT, Delivery_Status::DELIVERED, $provider );
		$this->assertSame( 'completed', $order->props['status'] );
		$order->props['status'] = '' === $since ? $order->props['status'] : $since;

		$this->run_jobs();

		$this->assertSame( $expected, $order->props['status'] );
		$this->assertSame( self::ASK_CODE, Carrier_Notice::of( $order->order )['text'] );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function return_states(): array {
		return [
			'on its way back' => [ Delivery_Status::RETURNING ],
			'back'            => [ Delivery_Status::RETURNED ],
		];
	}

	/**
	 * @dataProvider return_states
	 * @param string $state Shipment state.
	 * @return void
	 */
	public function test_a_return_asks_the_merchant_and_sends_nothing( string $state ): void {
		$order = $this->delivery_order( 700, [ [ 'u-1', 'Кружка', 'deliver' ] ] );

		$this->bridge()->on_status_changed( $order->order, null, $state );
		$this->bridge()->on_status_changed( $order->order, null, Delivery_Status::RETURNED );

		$this->assertSame( 'reject', Carrier_Notice::of( $order->order )['action'] );
		$this->assertStringContainsString( '«Отказ при получении»', Carrier_Notice::of( $order->order )['text'] );
		$this->assertCount( 1, $this->notes[700] );
		$this->assertSame( [], $this->pending() );
		$this->assertSame( [], $this->adapter->calls );
	}

	/**
	 * @return array<string,array{0:bool,1:string}>
	 */
	public function foreign_orders(): array {
		return [
			'a shop order'                       => [ false, 'checkout' ],
			'another marketplace, seller-delivered' => [ true, 'other-seller' ],
			'this marketplace delivers it'       => [ false, self::CREATED_VIA ],
		];
	}

	/**
	 * @dataProvider foreign_orders
	 * @param bool   $by_seller   Whether the order carries the seller-delivered mark.
	 * @param string $created_via What created it.
	 * @return void
	 */
	public function test_nothing_happens_for_an_order_that_is_not_the_marketplaces_to_deliver( bool $by_seller, string $created_via ): void {
		$order = $this->delivery_order( 700, [ [ 'u-1', 'Кружка', 'deliver' ] ], $by_seller, $created_via );

		$this->bridge()->on_exported( $order->order );
		$this->bridge()->on_status_changed( $order->order, null, Delivery_Status::DELIVERED );
		$this->bridge()->on_status_changed( $order->order, null, Delivery_Status::RETURNED );
		$this->bridge()->run( $this->account_id, 700, Carrier_Actions::DELIVERED );

		$this->assertSame( [], $this->scheduler->of( self::JOB ) );
		$this->assertSame( [], $this->adapter->calls );
		$this->assertNull( Carrier_Notice::of( $order->order ) );
		$this->assertSame( [], $this->notes );
	}

	public function test_other_states_of_a_shipment_do_nothing(): void {
		$order = $this->delivery_order();

		$this->bridge()->on_status_changed( $order->order, null, Delivery_Status::IN_TRANSIT );
		$this->bridge()->on_status_changed( $order->order, null, Delivery_Status::CANCELLED );

		$this->assertSame( [], $this->scheduler->of( self::JOB ) );
		$this->assertNull( Carrier_Notice::of( $order->order ) );
	}

	public function test_news_the_adapter_names_no_action_for_is_ignored(): void {
		$order                = $this->delivery_order();
		$this->adapter->table = [];

		$this->bridge()->on_exported( $order->order );
		$this->bridge()->on_status_changed( $order->order, null, Delivery_Status::DELIVERED );
		$this->bridge()->on_status_changed( $order->order, null, Delivery_Status::RETURNED );

		$this->assertSame( [], $this->scheduler->of( self::JOB ) );
		$this->assertNull( Carrier_Notice::of( $order->order ) );
	}

	public function test_without_a_licence_the_job_waits_and_spends_no_attempt(): void {
		$order          = $this->delivery_order();
		$this->licensed = false;
		$this->bridge()->on_exported( $order->order );

		$this->run_jobs();

		$this->assertSame( [], $this->adapter->calls );
		$this->assertCount( 1, $this->pending() );
		$this->assertEqualsWithDelta( Job_Gate::LICENCE_DELAY, $this->pending()[0]['delay'], 2 );
		$this->assertArrayNotHasKey( Carrier_Bridge::META_ATTEMPTS, $order->meta );
	}

	public function test_a_job_that_cannot_be_stored_is_said_on_the_order_and_never_reaches_the_delivery_service(): void {
		$order                                 = $this->delivery_order();
		$this->scheduler->zero_for[ self::JOB ] = true;

		$this->bridge()->on_exported( $order->order );

		$this->assertStringContainsString( 'вручную', $this->notes[700][0] );
		$this->assertCount( 1, $this->log );
	}

	/**
	 * Registers a delivery service whose plugin moves a delivered order to a status.
	 *
	 * @param string $status Status.
	 * @return Orders_Provider
	 */
	private function register_delivery_service( string $status ): Orders_Provider {
		Functions\when( 'wc_is_order_status' )->justReturn( true );
		Functions\when( 'get_post_meta' )->alias(
			function ( $id, string $key ) {
				return $this->orders[ (int) $id ]->meta[ $key ] ?? '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, string $key, $value ): bool {
				$this->orders[ (int) $id ]->meta[ $key ] = $value;

				return true;
			}
		);

		$settings = \Mockery::mock( Export_Settings::class );
		$settings->shouldReceive( 'get_delivered_status' )->andReturn( $status );

		$plugin = \Mockery::mock( Shipping_Plugin::class );
		$plugin->shouldReceive( 'get_export_settings' )->andReturn( $settings );

		$provider = Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek_courier' ] );

		Orders_Registry::instance()->register_provider( $provider, $plugin );

		return $provider;
	}

	/**
	 * @param Stateful_Order $order Order.
	 * @return void
	 */
	private function deliver( Stateful_Order $order ): void {
		$this->bridge()->on_status_changed( $order->order, Delivery_Status::IN_TRANSIT, Delivery_Status::DELIVERED );
	}

	/**
	 * Runs every pending job once, as the queue runner does.
	 *
	 * @return void
	 */
	private function run_jobs(): void {
		$due = $this->pending();

		$this->scheduler->start_running( self::JOB );

		foreach ( $due as $action ) {
			$this->bridge()->run( ...$action['args'] );
		}

		$this->scheduler->actions = array_values(
			array_filter(
				$this->scheduler->actions,
				static function ( array $action ): bool {
					return 'in-progress' !== $action['status'];
				}
			)
		);
	}

	/**
	 * @return array<int,array<string,mixed>> The pending jobs of the bridge.
	 */
	private function pending(): array {
		return array_values(
			array_filter(
				$this->scheduler->of( self::JOB ),
				static function ( array $action ): bool {
					return 'pending' === $action['status'];
				}
			)
		);
	}

	private function bridge(): Carrier_Bridge {
		return new Carrier_Bridge(
			$this->adapter,
			$this->runner(),
			$this->job_gate(),
			$this->registry(),
			new Code_Required_Accounts( self::PLUGIN_ID ),
			$this->names(),
			self::CREATED_VIA,
			self::MARKETPLACE_NAME,
			[ $this, 'log_line' ],
			new Carrier_State( $this->adapter, self::CREATED_VIA )
		);
	}
}
