<?php
/**
 * Unit: the «Накладная» / «Штрихкод» row actions (card #1134).
 *
 * @package Woodev\Tests\Unit\Shipping\Admin
 */

namespace Woodev\Tests\Unit\Shipping\Admin;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Order_Actions;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;
use Woodev\Framework\Shipping\Order\Document_Result;
use Woodev\Framework\Shipping\Order\Document_Source;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-document-result.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/interface-document-source.php';
require_once __DIR__ . '/order-edit-lock-fixtures.php';
require_once __DIR__ . '/order-edit-lock-cpt-fixtures.php';

/**
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Order_Actions::for_row
 */
final class OrderActionsDocumentsTest extends TestCase {

	/** @var array<string,mixed> post meta of order 123. */
	private $meta = [];

	protected function setUp(): void {
		parent::setUp();

		$this->meta = [
			'_cdek_marker'           => '1',
			'_cdek_carrier_order_id' => 'CARRIER-1',
		];
		\Automattic\WooCommerce\Internal\Admin\Orders\EditLock::$locks = [];

		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_post_meta' )->alias( function ( int $id, string $key ) { return $this->meta[ $key ] ?? ''; } );
		Functions\when( 'wc_get_order_status_name' )->alias( static function ( string $status ): string { return ucfirst( $status ); } );

		Orders_Registry::instance()->reset_for_tests();
	}

	protected function tearDown(): void {
		\Automattic\WooCommerce\Internal\Admin\Orders\EditLock::$locks = [];
		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	private function provider( bool $flag = true ): Orders_Provider {
		return Orders_Provider::create(
			'cdek',
			'СДЭК',
			'_cdek_marker',
			[ 'cdek' ],
			[
				'carrier_order_id_meta_key' => '_cdek_carrier_order_id',
				'status_meta_key'           => '_cdek_status',
				'status_map'                => [ 'NEW' => 'created' ],
				'supports_label_printing'   => $flag,
			]
		);
	}

	private function order(): \WC_Order {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );
		$order->shouldReceive( 'get_status' )->andReturn( 'processing' );

		return $order;
	}

	private function source( array $types ): Document_Source {
		return new class( $types ) implements Document_Source {
			private $types;

			public function __construct( array $types ) {
				$this->types = $types;
			}

			public function get_document_types( \WC_Order $order ): array {
				return $this->types;
			}

			public function get_document( \WC_Order $order, string $type ): Document_Result {
				return Document_Result::pending();
			}
		};
	}

	private function actions( ?Document_Source $source = null ): Order_Actions {
		$handler = Mockery::mock( Abstract_Shipment_Handler::class );
		$handler->shouldReceive( 'supports_update' )->andReturn( true );
		Orders_Registry::instance()->register_shipment_handler( 'cdek', $handler );

		if ( null !== $source ) {
			Orders_Registry::instance()->register_document_source( 'cdek', $source );
		}

		return new Order_Actions( Orders_Registry::instance() );
	}

	/** @return string[] */
	private function ids( Order_Actions $actions, Orders_Provider $provider ): array {
		return array_column( $actions->for_row( $this->order(), $provider ), 'action' );
	}

	public function test_an_exported_order_of_a_printing_carrier_gets_the_document_actions_after_the_carrier_ones(): void {
		$actions = $this->actions( $this->source( [ 'waybill', 'barcode' ] ) );
		$row     = $actions->for_row( $this->order(), $this->provider() );

		$this->assertSame( [ Order_Actions::UPDATE, Order_Actions::CANCEL, 'waybill', 'barcode' ], array_column( $row, 'action' ) );
		$this->assertSame( 'Накладная', $row[2]['label'] );
		$this->assertSame( 'Штрихкод', $row[3]['label'] );
		$this->assertFalse( $row[2]['destructive'], 'a download needs no «Да / Нет»' );
	}

	public function test_they_are_never_part_of_the_executable_set(): void {
		$actions  = $this->actions( $this->source( [ 'waybill' ] ) );
		$provider = $this->provider();

		$this->assertNotContains( 'waybill', array_column( $actions->for_order( $this->order(), $provider ), 'action' ) );
		$this->assertFalse( $actions->is_offered( $this->order(), $provider, 'waybill' ), 'the REST action route refuses a document id like any unknown action' );
	}

	public function test_only_the_types_the_source_offers_and_the_framework_labels_appear(): void {
		$actions = $this->actions( $this->source( [ 'barcode', 'invoice' ] ) );

		$this->assertSame( [ Order_Actions::UPDATE, Order_Actions::CANCEL, 'barcode' ], $this->ids( $actions, $this->provider() ) );
	}

	public function test_no_documents_without_the_support_flag_a_source_or_a_carrier_order(): void {
		$this->assertSame( [ Order_Actions::UPDATE, Order_Actions::CANCEL ], $this->ids( $this->actions( $this->source( [ 'waybill' ] ) ), $this->provider( false ) ), 'flag off' );

		Orders_Registry::instance()->reset_for_tests();
		$this->assertSame( [ Order_Actions::UPDATE, Order_Actions::CANCEL ], $this->ids( $this->actions( null ), $this->provider() ), 'no source' );

		Orders_Registry::instance()->reset_for_tests();
		$this->meta['_cdek_carrier_order_id'] = '';
		$this->assertNotContains( 'waybill', $this->ids( $this->actions( $this->source( [ 'waybill' ] ) ), $this->provider() ), 'not exported' );
	}

	public function test_another_managers_edit_lock_greys_out_the_carrier_actions_but_not_a_read_only_download(): void {
		\Automattic\WooCommerce\Internal\Admin\Orders\EditLock::$locks[123] = [ 'time' => time(), 'user_id' => 7 ];
		$user               = new \stdClass();
		$user->ID           = 7;
		$user->display_name = 'Мария';
		Functions\when( 'get_user_by' )->justReturn( $user );

		$row = $this->actions( $this->source( [ 'waybill' ] ) )->for_row( $this->order(), $this->provider() );

		foreach ( $row as $action ) {
			if ( 'waybill' === $action['action'] ) {
				$this->assertArrayNotHasKey( 'disabled', $action, 'downloading never edits the order' );
			} else {
				$this->assertTrue( $action['disabled'], $action['action'] );
			}
		}
	}
}
