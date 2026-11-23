<?php
/**
 * The cabinets of one marketplace, as the WooCommerce orders list sees them.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

use Woodev\Framework\Marketplace\Account\Account_Store;
use Woodev\Framework\Marketplace\Orders\Order_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Tells the orders list which account (cabinet) each order of a marketplace belongs to, and which orders an account has.
 *
 * The answer comes from the postings registry, the same one the order screen asks for the posting of an order
 * ({@see Order_Registry::posting_of_order()}), so the «Кабинет» column and the filter agree with the details box.
 *
 * @since 2.1.16
 */
final class Order_Source_Accounts {
	/**
	 * @var Account_Store
	 */
	private $accounts;

	/**
	 * @var Order_Registry
	 */
	private $registry;

	/**
	 * The marketplace's adapter id, the key its accounts are stored under.
	 *
	 * @var string
	 */
	private $adapter_id;

	/**
	 * Account names by id, once read.
	 *
	 * @var array<int,string>|null
	 */
	private $names;

	/**
	 * Creates the lookup.
	 *
	 * @since 2.1.16
	 *
	 * @param Account_Store  $accounts   The plugin's accounts.
	 * @param Order_Registry $registry   Its postings registry.
	 * @param string         $adapter_id The marketplace's adapter id.
	 */
	public function __construct( Account_Store $accounts, Order_Registry $registry, string $adapter_id ) {
		$this->accounts   = $accounts;
		$this->registry   = $registry;
		$this->adapter_id = $adapter_id;
	}

	/**
	 * Returns the marketplace's accounts, switched-off ones too: their orders are still in the shop.
	 *
	 * @since 2.1.16
	 *
	 * @return array<int,string> Account name by account id.
	 */
	public function names(): array {
		if ( null === $this->names ) {
			$this->names = [];

			foreach ( $this->accounts->all( $this->adapter_id, false ) as $account ) {
				$this->names[ $account->id() ] = $account->name();
			}
		}

		return $this->names;
	}

	/**
	 * Returns the name of the account an order came through.
	 *
	 * @since 2.1.16
	 *
	 * @param int $order_id WooCommerce order id.
	 * @return string Empty when the order has no posting or its account is gone.
	 */
	public function name_of_order( int $order_id ): string {
		$posting = $this->registry->posting_of_order( $order_id );

		return null === $posting ? '' : ( $this->names()[ $posting['account_id'] ] ?? '' );
	}

	/**
	 * Returns the orders an account has.
	 *
	 * @since 2.1.16
	 *
	 * @param int $account_id Account id.
	 * @return int[] WooCommerce order ids.
	 */
	public function order_ids( int $account_id ): array {
		return $this->registry->order_ids_of_account( $account_id );
	}
}
