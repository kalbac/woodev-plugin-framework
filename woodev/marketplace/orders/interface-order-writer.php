<?php
/**
 * WooCommerce order writer contract.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Writes remote orders into WooCommerce, idempotently.
 *
 * The §7.2 sketch shows a final class. Its body needs the posting registry and the `Claimable` fence (cards W1-5 and
 * W4-1), so this card fixes the contract as an interface and `Order_Group` carries the pure aggregation rules; W4-1
 * supplies the implementation and its concurrency tests.
 *
 * @since 2.0.29
 */
interface Order_Writer {
	/**
	 * Writes one remote order as one WooCommerce order (Ozon).
	 *
	 * Idempotent on (account id, external id) through the registry row and its claim: the same DTO twice gives one
	 * order. The line set is replaced from the DTO.
	 *
	 * @since 2.0.29
	 *
	 * @param Order_DTO  $dto Remote order.
	 * @param Status_Map $map Status mapping.
	 * @return \WC_Order
	 */
	public function upsert( Order_DTO $dto, Status_Map $map ): \WC_Order;

	/**
	 * Writes one member of a grouped order (Wildberries: assembly orders sharing an `orderUid`).
	 *
	 * The registry row is keyed by the group key, so the reservation and the claim protect the shared WooCommerce
	 * order: two members of a new cart arriving at once insert the same row and only the claim holder creates the order.
	 * A member update changes only its own line, a member cancellation zeroes only its own line, and the order becomes
	 * `cancelled` only when every member is cancelled (see `Order_Group`).
	 *
	 * @since 2.0.29
	 *
	 * @param Order_DTO  $member One member.
	 * @param Status_Map $map    Status mapping.
	 * @return \WC_Order
	 */
	public function upsert_member( Order_DTO $member, Status_Map $map ): \WC_Order;
}
