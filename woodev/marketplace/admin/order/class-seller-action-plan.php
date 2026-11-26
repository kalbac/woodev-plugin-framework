<?php
/**
 * What a seller action would reach.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

defined( 'ABSPATH' ) || exit;

/**
 * The units an action can be applied to now, and the ones left out with the reason ({@see Seller_Action_Runner}).
 *
 * @since 2.1.27
 */
final class Seller_Action_Plan {
	/**
	 * @var Seller_Action_Target[]
	 */
	private $targets;

	/**
	 * @var array<int,array{order:\WC_Order,unit:string,reason:string}>
	 */
	private $skipped;

	/**
	 * @since 2.1.27
	 *
	 * @param Seller_Action_Target[]                                      $targets Units the action can be applied to.
	 * @param array<int,array{order:\WC_Order,unit:string,reason:string}> $skipped Units left out: the order, the unit's label
	 *                                                                            (empty for a whole order) and why.
	 */
	public function __construct( array $targets, array $skipped ) {
		$this->targets = array_values( $targets );
		$this->skipped = array_values( $skipped );
	}

	/**
	 * @since 2.1.27
	 *
	 * @return Seller_Action_Target[]
	 */
	public function targets(): array {
		return $this->targets;
	}

	/**
	 * @since 2.1.27
	 *
	 * @return array<int,array{order:\WC_Order,unit:string,reason:string}>
	 */
	public function skipped(): array {
		return $this->skipped;
	}

	/**
	 * @since 2.1.27
	 *
	 * @return int Units asked for: the eligible and the left out.
	 */
	public function requested(): int {
		return count( $this->targets ) + count( $this->skipped );
	}
}
