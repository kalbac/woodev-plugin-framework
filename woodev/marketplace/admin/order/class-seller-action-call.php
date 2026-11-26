<?php
/**
 * One request to apply a seller action.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

defined( 'ABSPATH' ) || exit;

/**
 * What the framework hands the adapter: the action, the units it is applied to (only those the screens offer it on) and what the
 * merchant typed. The values are the same for every unit of the call.
 *
 * @since 2.1.27
 */
final class Seller_Action_Call {
	/**
	 * @var string
	 */
	private $action_id;

	/**
	 * @var Seller_Action_Target[]
	 */
	private $targets;

	/**
	 * Typed values by field id.
	 *
	 * @var array<string,string>
	 */
	private $values;

	/**
	 * @since 2.1.27
	 *
	 * @param string                 $action_id Id of the action.
	 * @param Seller_Action_Target[] $targets   Units, in display order.
	 * @param array<string,string>   $values    Typed values by field id; an empty field is left out.
	 */
	public function __construct( string $action_id, array $targets, array $values = [] ) {
		$this->action_id = $action_id;
		$this->targets   = array_values( $targets );
		$this->values    = $values;
	}

	/**
	 * @since 2.1.27
	 *
	 * @return string
	 */
	public function action_id(): string {
		return $this->action_id;
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
	 * Returns what was typed into one field.
	 *
	 * @since 2.1.27
	 *
	 * @param string $field_id Field id.
	 * @return string Empty when the field was left empty.
	 */
	public function value( string $field_id ): string {
		return $this->values[ $field_id ] ?? '';
	}
}
