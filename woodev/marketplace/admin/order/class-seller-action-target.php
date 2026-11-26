<?php
/**
 * A unit of an order that a seller action is applied to.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

defined( 'ABSPATH' ) || exit;

/**
 * One unit together with the WooCommerce order it belongs to: a call of {@see Seller_Actions} names them in pairs.
 *
 * @since 2.1.27
 */
final class Seller_Action_Target {
	/**
	 * @var \WC_Order
	 */
	private $order;

	/**
	 * @var Posting_Unit
	 */
	private $unit;

	/**
	 * @since 2.1.27
	 *
	 * @param \WC_Order    $order The order.
	 * @param Posting_Unit $unit  Its unit.
	 */
	public function __construct( \WC_Order $order, Posting_Unit $unit ) {
		$this->order = $order;
		$this->unit  = $unit;
	}

	/**
	 * @since 2.1.27
	 *
	 * @return \WC_Order
	 */
	public function order(): \WC_Order {
		return $this->order;
	}

	/**
	 * @since 2.1.27
	 *
	 * @return Posting_Unit
	 */
	public function unit(): Posting_Unit {
		return $this->unit;
	}

	/**
	 * Returns the key that tells this unit apart from every other of a call.
	 *
	 * @since 2.1.27
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->order->get_id() . '|' . $this->unit->id();
	}
}
