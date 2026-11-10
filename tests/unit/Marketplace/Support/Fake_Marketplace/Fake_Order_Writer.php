<?php
/**
 * In-memory order writer built on Order_Group.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Orders\Order_DTO;
use Woodev\Framework\Marketplace\Orders\Order_Group;
use Woodev\Framework\Marketplace\Orders\Order_Writer;
use Woodev\Framework\Marketplace\Orders\Status_Map;

/**
 * Stands in for the WooCommerce-backed writer of card W4-1: one `Order_Group` per (account, group key), so a test can
 * apply members in any order and read the aggregate. The returned `WC_Order` is a Mockery double.
 */
final class Fake_Order_Writer implements Order_Writer {
	/**
	 * Groups by "account|group key".
	 *
	 * @var array<string,Order_Group>
	 */
	private $groups = array();

	/**
	 * Writes a whole order.
	 *
	 * @param Order_DTO  $dto Remote order.
	 * @param Status_Map $map Status mapping.
	 * @return \WC_Order
	 */
	public function upsert( Order_DTO $dto, Status_Map $map ): \WC_Order {
		return $this->apply( $dto );
	}

	/**
	 * Writes one member of a grouped order.
	 *
	 * @param Order_DTO  $member Member.
	 * @param Status_Map $map    Status mapping.
	 * @return \WC_Order
	 */
	public function upsert_member( Order_DTO $member, Status_Map $map ): \WC_Order {
		return $this->apply( $member );
	}

	/**
	 * Returns the group of an order key.
	 *
	 * @param int    $account_id Account id.
	 * @param string $group_key  Group key.
	 * @return Order_Group|null
	 */
	public function group( int $account_id, string $group_key ): ?Order_Group {
		return $this->groups[ $account_id . '|' . $group_key ] ?? null;
	}

	/**
	 * Returns how many orders exist, one per group key.
	 *
	 * @return int
	 */
	public function order_count(): int {
		return count( $this->groups );
	}

	/**
	 * Applies a snapshot to its group.
	 *
	 * @param Order_DTO $dto Snapshot.
	 * @return \WC_Order
	 */
	private function apply( Order_DTO $dto ): \WC_Order {
		$key                  = $dto->account_id() . '|' . $dto->group_key();
		$group                = $this->groups[ $key ] ?? Order_Group::start( $dto->group_key() );
		$this->groups[ $key ] = $group->with_member( $dto );

		/**
		 * Test double of the WooCommerce order.
		 *
		 * @var \WC_Order $order
		 */
		$order = \Mockery::mock( 'WC_Order' );

		return $order;
	}
}
