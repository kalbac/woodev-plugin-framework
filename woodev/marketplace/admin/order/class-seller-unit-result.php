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
	 * Whether the refusal means that the buyer's confirmation code is needed.
	 *
	 * @var bool
	 */
	private $asks_code;

	/**
	 * @param Seller_Action_Target $target    Unit.
	 * @param string               $reason    Refusal; empty for done.
	 * @param bool                 $asks_code Whether the refusal asks for the buyer's confirmation code.
	 */
	private function __construct( Seller_Action_Target $target, string $reason, bool $asks_code = false ) {
		$this->target    = $target;
		$this->reason    = $reason;
		$this->asks_code = $asks_code;
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
	 * The marketplace refused a unit because it needs the buyer's confirmation code. Meaningful for the action a marketplace
	 * names under {@see Carrier_Actions::DELIVERED}, which the framework sends without a code: this answer makes it ask the
	 * merchant for the code instead.
	 *
	 * @since 2.1.29
	 *
	 * @param Seller_Action_Target $target Unit of the call.
	 * @param string               $reason The sentence for the merchant, in Russian.
	 * @return self
	 */
	public static function code_required( Seller_Action_Target $target, string $reason ): self {
		return new self( $target, self::refused( $target, $reason )->reason(), true );
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

	/**
	 * @since 2.1.29
	 *
	 * @return bool Whether the unit was refused for the lack of the buyer's confirmation code.
	 */
	public function asks_code(): bool {
		return $this->asks_code;
	}
}
