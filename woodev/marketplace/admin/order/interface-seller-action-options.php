<?php
/**
 * The choices of a seller action's list fields.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

use Woodev\Framework\Marketplace\Account\Account;

defined( 'ABSPATH' ) || exit;

/**
 * Optional companion of {@see Seller_Actions}, for an action with a list field whose choices are not fixed
 * ({@see Seller_Action_Field} with `options_source`): the supply an order goes into, say. The list is read from what the plugin
 * stored, so opening a dialog never calls the marketplace.
 *
 * @since 2.1.27
 */
interface Seller_Action_Options {
	/**
	 * Lists the choices of one field.
	 *
	 * @since 2.1.27
	 *
	 * @param Account $account   Account of the orders.
	 * @param string  $action_id Id of a declared action.
	 * @param string  $field_id  Id of one of its fields.
	 * @param string  $search    What the merchant typed to narrow the list; empty for all.
	 * @return array<int,array{value:string,label:string}> At most 100 choices. A value holds letters, digits, `_ . : -` only.
	 */
	public function seller_field_options( Account $account, string $action_id, string $field_id, string $search ): array;
}
