<?php
/**
 * Base of the tests of the orders the seller delivers with a delivery service.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action_Runner;
use Woodev\Framework\Marketplace\Admin\Shared\Label_Availability;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Action_Gate;
use Woodev\Framework\Marketplace\Data\Wpdb_Seller_Action_Store;
use Woodev\Framework\Marketplace\Flows\Seller_Order;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Settings\Posting_Noun;
use Woodev\Tests\Unit\Marketplace\Admin\Support\Fake_Carrier_Adapter;
use Woodev\Tests\Unit\Marketplace\Admin\Support\Fake_Seller_Adapter;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;

/**
 * The real tables (SQLite), the real seller action runner over a scripted marketplace, and orders that remember what was written
 * into them. `$orders` are the orders `wc_get_order()` finds; `$notes` the notes added to each.
 */
abstract class Carrier_Test_Case extends Sync_Test_Case {
	protected const CREATED_VIA = 'fake-seller';

	/**
	 * @var Fake_Carrier_Adapter
	 */
	protected $adapter;

	/**
	 * Orders by id.
	 *
	 * @var array<int,Stateful_Order>
	 */
	protected $orders = [];

	/**
	 * Notes by order id.
	 *
	 * @var array<int,string[]>
	 */
	protected $notes = [];

	/**
	 * Options written.
	 *
	 * @var array<string,mixed>
	 */
	protected $options = [];

	protected function setUp(): void {
		parent::setUp();

		$this->adapter = new Fake_Carrier_Adapter();
		$this->orders  = [];
		$this->notes   = [];
		$this->options = [];

		Functions\when( 'sanitize_text_field' )->alias( 'strip_tags' );
		Functions\when( 'wc_get_order' )->alias(
			function ( $id ) {
				return isset( $this->orders[ (int) $id ] ) ? $this->orders[ (int) $id ]->order : false;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( string $name, $fallback = false ) {
				return $this->options[ $name ] ?? $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;

				return true;
			}
		);
	}

	/**
	 * Creates an order the seller delivers, registered as a posting of the test account.
	 *
	 * @param int                     $id          Order id.
	 * @param array<int,array<mixed>> $units       Units: id, label, status.
	 * @param bool                    $by_seller   Whether the seller delivers it.
	 * @param string                  $created_via What created it.
	 * @return Stateful_Order
	 */
	protected function delivery_order( int $id = 700, array $units = [ [ 'u-1', 'Кружка', 'confirm' ] ], bool $by_seller = true, string $created_via = self::CREATED_VIA ): Stateful_Order {
		$order = new Stateful_Order( $id, $created_via );

		$order->meta[ Fake_Seller_Adapter::META_UNITS ] = $units;

		if ( $by_seller ) {
			$order->meta[ Seller_Order::META_SELLER_DELIVERED ] = 'yes';
		}

		$order->order->shouldReceive( 'add_order_note' )->andReturnUsing(
			function ( string $note ) use ( $id ): int {
				$this->notes[ $id ][] = $note;

				return 1;
			}
		);

		$order->props['status'] = 'processing';

		$order->order->shouldReceive( 'get_status' )->andReturnUsing(
			static function () use ( $order ): string {
				return $order->props['status'];
			}
		);
		$order->order->shouldReceive( 'update_status' )->andReturnUsing(
			static function ( string $status ) use ( $order ): bool {
				$order->props['status'] = $status;

				return true;
			}
		);

		$registry = $this->registry();
		$key      = 'P-' . $id;

		$registry->reserve( $this->account_id, $key );
		$registry->attach_order( $this->account_id, $key, (string) $registry->claim( $this->account_id, $key ), $id );

		$this->orders[ $id ] = $order;

		return $order;
	}

	/**
	 * Moves every unit of an order to a status, as a refresh of the marketplace's order would.
	 *
	 * @param Stateful_Order $order  Order.
	 * @param string         $status Status.
	 * @return void
	 */
	protected function move( Stateful_Order $order, string $status ): void {
		foreach ( $order->meta[ Fake_Seller_Adapter::META_UNITS ] as $index => $unit ) {
			$order->meta[ Fake_Seller_Adapter::META_UNITS ][ $index ][2] = $status;
		}
	}

	/**
	 * The seller action runner over the scripted marketplace.
	 *
	 * @return Seller_Action_Runner
	 */
	protected function runner(): Seller_Action_Runner {
		$noun    = Posting_Noun::shipment();
		$gate    = new Posting_Action_Gate( new Label_Availability( null, $noun ), null, $this->write_gate, $noun, $this->adapter );
		$license = new License_Gate(
			function (): bool {
				return $this->licensed;
			}
		);
		$sent    = new Wpdb_Seller_Action_Store( $this->db, Sqlite_Tables::table( 'seller_actions' ), $this->clock() );

		return new Seller_Action_Runner( $this->adapter, $gate, $license, self::MARKETPLACE_NAME, $sent, [ $this, 'log_line' ] );
	}
}
