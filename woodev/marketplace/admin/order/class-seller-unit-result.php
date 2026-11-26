<?php
/**
 * The marketplace's answer for one unit of a seller action.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

defined( 'ABSPATH' ) || exit;

/**
 * Done, or refused with the sentence the merchant reads for that unit.
 *
 * @since 2.1.27
 */
final class Seller_Unit_Result {
	/**
	 * @var Seller_Action_Target
	 */
	private $target;

	/**
	 * Empty when the unit is done.
	 *
	 * @var string
	 */
	private $reason;

	/**
	 * @param Seller_Action_Target $target Unit.
	 * @param string               $reason Refusal; empty for done.
	 */
	private function __construct( Seller_Action_Target $target, string $reason ) {
		$this->target = $target;
		$this->reason = $reason;
	}

	/**
	 * The marketplace accepted the action for a unit.
	 *
	 * @since 2.1.27
	 *
	 * @param Seller_Action_Target $target Unit of the call.
	 * @return self
	 */
	public static function done( Seller_Action_Target $target ): self {
		return new self( $target, '' );
	}

	/**
	 * The marketplace refused a unit.
	 *
	 * @since 2.1.27
	 *
	 * @param Seller_Action_Target $target Unit of the call.
	 * @param string               $reason The sentence for the merchant, in Russian.
	 * @return self
	 */
	public static function refused( Seller_Action_Target $target, string $reason ): self {
		$reason = trim( $reason );

		return new self( $target, '' === $reason ? __( 'Маркетплейс отказал и не назвал причину.', 'woodev-plugin-framework' ) : $reason );
	}

	/**
	 * @since 2.1.27
	 *
	 * @return Seller_Action_Target
	 */
	public function target(): Seller_Action_Target {
		return $this->target;
	}

	/**
	 * @since 2.1.27
	 *
	 * @return bool
	 */
	public function is_done(): bool {
		return '' === $this->reason;
	}

	/**
	 * @since 2.1.27
	 *
	 * @return string Empty when the unit is done.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
