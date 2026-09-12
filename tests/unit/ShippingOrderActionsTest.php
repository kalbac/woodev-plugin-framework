<?php
/**
 * Unit: Order_Actions — the per-row action-set gate (card #824).
 *
 * Pins every row of the brief's gate table, both directions, plus the two "no
 * actions at all" cases (`$provider === null`, no handler registered) and the
 * `woodev_shipping_order_actions` filter's own re-validation.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Order_Actions;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;
use Woodev\Framework\Shipping\Order\Delivery_Status;

require_once dirname( __DIR__, 2 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 2 ) . '/woodev/compatibility/class-order-compatibility.php';

class ShippingOrderActionsTest extends TestCase {

	/** @var array<string,mixed> post meta, keyed by meta key, for the active test. */
	private $meta = [];

	protected function setUp(): void {
		parent::setUp();

		$this->meta = [];

		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_post_meta' )->alias(
			function ( int $post_id, string $key, bool $single ) {
				return $this->meta[ $key ] ?? '';
			}
		);

		Orders_Registry::instance()->reset_for_tests();
	}

	protected function tearDown(): void {
		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	private function provider( array $args = [] ): Orders_Provider {
		return Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ], $args );
	}

	private function order( string $status = 'processing' ): \WC_Order {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );
		$order->shouldReceive( 'get_status' )->andReturn( $status );

		return $order;
	}

	private function actions(): Order_Actions {
		return new Order_Actions( Orders_Registry::instance() );
	}

	/**
	 * Registers a fake shipment handler for 'cdek', so `for_order()` gets past its
	 * own "no handler registered" gate.
	 */
	private function register_handler( bool $supports_update = false ): Abstract_Shipment_Handler {
		$handler = Mockery::mock( Abstract_Shipment_Handler::class );
		$handler->shouldReceive( 'supports_update' )->andReturn( $supports_update );

		Orders_Registry::instance()->register_shipment_handler( 'cdek', $handler );

		return $handler;
	}

	private function action_ids( array $actions ): array {
		return array_column( $actions, 'action' );
	}

	// ----- no actions at all -----

	public function test_no_actions_when_provider_is_null(): void {
		$this->assertSame( [], $this->actions()->for_order( $this->order(), null ) );
	}

	public function test_no_actions_when_no_handler_is_registered(): void {
		$this->assertSame( [], $this->actions()->for_order( $this->order(), $this->provider() ) );
	}

	// ----- export -----

	public function test_export_is_offered_for_every_exportable_status(): void {
		$this->register_handler();

		foreach ( [ 'pending', 'on-hold', 'processing' ] as $status ) {
			$actions = $this->actions()->for_order( $this->order( $status ), $this->provider() );

			$this->assertSame( [ Order_Actions::EXPORT ], $this->action_ids( $actions ), "status: {$status}" );
		}
	}

	public function test_export_is_not_offered_for_a_non_exportable_status(): void {
		$this->register_handler();

		$actions = $this->actions()->for_order( $this->order( 'completed' ), $this->provider() );

		$this->assertNotContains( Order_Actions::EXPORT, $this->action_ids( $actions ) );
	}

	public function test_export_is_not_offered_once_exported(): void {
		$this->register_handler();
		$this->meta['_cdek_carrier_order_id'] = 'CARRIER-1';

		$provider = $this->provider( [ 'carrier_order_id_meta_key' => '_cdek_carrier_order_id' ] );

		$actions = $this->actions()->for_order( $this->order( 'pending' ), $provider );

		$this->assertNotContains( Order_Actions::EXPORT, $this->action_ids( $actions ) );
	}

	/**
	 * #860 folded in: a stored EMPTY carrier-order-id (a failed export) must not
	 * count as exported — export stays offered so the merchant can retry.
	 */
	public function test_export_is_still_offered_when_the_stored_carrier_order_id_is_empty(): void {
		$this->register_handler();
		$this->meta['_cdek_carrier_order_id'] = '';

		$provider = $this->provider( [ 'carrier_order_id_meta_key' => '_cdek_carrier_order_id' ] );

		$actions = $this->actions()->for_order( $this->order( 'pending' ), $provider );

		$this->assertContains( Order_Actions::EXPORT, $this->action_ids( $actions ) );
	}

	// ----- update -----

	public function test_update_is_offered_when_exported_and_handler_supports_it(): void {
		$this->register_handler( true );
		$this->meta['_cdek_carrier_order_id'] = 'CARRIER-1';

		$provider = $this->provider( [ 'carrier_order_id_meta_key' => '_cdek_carrier_order_id' ] );

		$actions = $this->actions()->for_order( $this->order( 'processing' ), $provider );

		$this->assertContains( Order_Actions::UPDATE, $this->action_ids( $actions ) );
	}

	public function test_update_is_not_offered_when_the_handler_does_not_support_it(): void {
		$this->register_handler( false );
		$this->meta['_cdek_carrier_order_id'] = 'CARRIER-1';

		$provider = $this->provider( [ 'carrier_order_id_meta_key' => '_cdek_carrier_order_id' ] );

		$actions = $this->actions()->for_order( $this->order( 'processing' ), $provider );

		$this->assertNotContains( Order_Actions::UPDATE, $this->action_ids( $actions ) );
	}

	public function test_update_is_not_offered_when_not_exported_even_if_the_handler_supports_it(): void {
		$this->register_handler( true );

		$actions = $this->actions()->for_order( $this->order( 'processing' ), $this->provider() );

		$this->assertNotContains( Order_Actions::UPDATE, $this->action_ids( $actions ) );
	}

	// ----- cancel -----

	public function test_cancel_is_offered_when_exported_and_the_canonical_status_is_not_retired(): void {
		$this->register_handler();
		$this->meta['_cdek_carrier_order_id'] = 'CARRIER-1';
		$this->meta['_cdek_status']           = 'CDEK_IN_TRANSIT';

		$provider = $this->provider(
			[
				'carrier_order_id_meta_key' => '_cdek_carrier_order_id',
				'status_meta_key'           => '_cdek_status',
				'status_map'                => [ 'CDEK_IN_TRANSIT' => Delivery_Status::IN_TRANSIT ],
			]
		);

		$actions = $this->actions()->for_order( $this->order( 'processing' ), $provider );

		$this->assertContains( Order_Actions::CANCEL, $this->action_ids( $actions ) );
	}

	/**
	 * @dataProvider retired_canonical_statuses
	 */
	public function test_cancel_is_not_offered_once_the_canonical_status_is_retired( string $canonical ): void {
		$this->register_handler();
		$this->meta['_cdek_carrier_order_id'] = 'CARRIER-1';
		$this->meta['_cdek_status']           = 'RAW';

		$provider = $this->provider(
			[
				'carrier_order_id_meta_key' => '_cdek_carrier_order_id',
				'status_meta_key'           => '_cdek_status',
				'status_map'                => [ 'RAW' => $canonical ],
			]
		);

		$actions = $this->actions()->for_order( $this->order( 'processing' ), $provider );

		$this->assertNotContains( Order_Actions::CANCEL, $this->action_ids( $actions ) );
	}

	/**
	 * @return array<string,array<int,string>>
	 */
	public function retired_canonical_statuses(): array {
		return [
			'delivered' => [ Delivery_Status::DELIVERED ],
			'returned'  => [ Delivery_Status::RETURNED ],
			'cancelled' => [ Delivery_Status::CANCELLED ],
			'failed'    => [ Delivery_Status::FAILED ],
		];
	}

	public function test_cancel_is_not_offered_when_not_exported(): void {
		$this->register_handler();

		$actions = $this->actions()->for_order( $this->order( 'processing' ), $this->provider() );

		$this->assertNotContains( Order_Actions::CANCEL, $this->action_ids( $actions ) );
	}

	/**
	 * A provider with no `status_meta_key` resolves to UNKNOWN, which is not in the
	 * retired set — cancel stays offered rather than being silently withheld for a
	 * carrier the framework cannot read a status from at all.
	 */
	public function test_cancel_is_offered_when_exported_and_the_provider_has_no_status_meta_key(): void {
		$this->register_handler();
		$this->meta['_cdek_carrier_order_id'] = 'CARRIER-1';

		$provider = $this->provider( [ 'carrier_order_id_meta_key' => '_cdek_carrier_order_id' ] );

		$actions = $this->actions()->for_order( $this->order( 'processing' ), $provider );

		$this->assertContains( Order_Actions::CANCEL, $this->action_ids( $actions ) );
	}

	// ----- the woodev_shipping_order_actions filter -----

	public function test_filter_can_add_a_carrier_specific_action(): void {
		$this->register_handler();

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $actions ) {
				$actions[] = [
					'action'      => 'print_label',
					'label'       => 'Печать этикетки',
					'title'       => '',
					'destructive' => false,
				];

				return $actions;
			}
		);

		$actions = $this->actions()->for_order( $this->order( 'completed' ), $this->provider() );

		$this->assertSame( [ 'print_label' ], $this->action_ids( $actions ) );
	}

	public function test_filter_malformed_entries_are_dropped(): void {
		$this->register_handler();

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $actions ) {
				return [
					[ 'label' => 'Missing action id' ],
					[
						'action' => '',
						'label'  => 'Empty action id',
					],
					[
						'action' => 'ok',
						'label'  => '',
					],
					'not-an-array',
					[
						'action' => 'ok',
						'label'  => 'OK',
					],
				];
			}
		);

		$actions = $this->actions()->for_order( $this->order( 'pending' ), $this->provider() );

		$this->assertSame(
			[
				[
					'action'      => 'ok',
					'label'       => 'OK',
					'title'       => '',
					'destructive' => false,
				],
			],
			$actions
		);
	}

	public function test_filter_returning_a_non_array_is_ignored(): void {
		$this->register_handler();

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $actions ) {
				return 'not-an-array';
			}
		);

		$actions = $this->actions()->for_order( $this->order( 'pending' ), $this->provider() );

		$this->assertSame( [ Order_Actions::EXPORT ], $this->action_ids( $actions ) );
	}
}
