<?php
/**
 * «Оформить доставку» for the orders a marketplace's seller delivers.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Flows\Order_Units;
use Woodev\Framework\Marketplace\Flows\Seller_Order;
use Woodev\Framework\Marketplace\Orders\Order_Registry;
use Woodev\Framework\Shipping\Admin\Orders\Delivery_Entry;

defined( 'ABSPATH' ) || exit;

/**
 * Hands the shipping module's order wizard the marketplace's seller-delivered orders: it picks a delivery service's tariff or
 * pickup point and saves the shipping line onto the same order, items and prices untouched.
 *
 * A delivery is arranged only while the marketplace takes the hand-over ({@see Carrier_Actions::HANDED_OVER}) for every unit of
 * the order that can still be handed over, and the account may send it, the licence included. What the manager must know first stands above the address: a phone
 * that needs an extension (it is not passed to the delivery service), the day the buyer chose, a pickup point address.
 *
 * @since 2.1.29
 */
final class Carrier_Delivery_Entry implements Delivery_Entry {
	/**
	 * @var Carrier_Actions
	 */
	private $adapter;

	/**
	 * @var Seller_Action_Runner
	 */
	private $runner;

	/**
	 * @var Order_Registry
	 */
	private $postings;

	/**
	 * @var Wpdb_Account_Store
	 */
	private $accounts;

	/**
	 * What the marketplace's orders are created via.
	 *
	 * @var string
	 */
	private $created_via;

	/**
	 * @since 2.1.29
	 *
	 * @param Carrier_Actions      $adapter     The marketplace's actions.
	 * @param Seller_Action_Runner $runner      Judges the hand-over action.
	 * @param Order_Registry       $postings    The postings registry: which account an order belongs to.
	 * @param Wpdb_Account_Store   $accounts    Accounts.
	 * @param string               $created_via What the marketplace's orders are created via.
	 */
	public function __construct( Carrier_Actions $adapter, Seller_Action_Runner $runner, Order_Registry $postings, Wpdb_Account_Store $accounts, string $created_via ) {
		$this->adapter     = $adapter;
		$this->runner      = $runner;
		$this->postings    = $postings;
		$this->accounts    = $accounts;
		$this->created_via = $created_via;
	}

	/**
	 * @since 2.1.29
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public function claims( \WC_Order $order ): bool {
		return Seller_Order::belongs_to( $order, $this->created_via ) && isset( $this->adapter->carrier_actions()[ Carrier_Actions::HANDED_OVER ] );
	}

	public function refusal_reason( \WC_Order $order ): string {
		$posting = $this->postings->posting_of_order( $order->get_id() );
		$account = null === $posting ? null : $this->accounts->get( $posting['account_id'] );
		$action  = $this->runner->action( (string) ( $this->adapter->carrier_actions()[ Carrier_Actions::HANDED_OVER ] ?? '' ) );

		if ( null === $account || null === $action ) {
			return __( 'Подключение, через которое пришёл этот заказ, не найдено, поэтому оформить доставку нельзя.', 'woodev-plugin-framework' );
		}

		$refusal = $this->runner->account_refusal( $account, $action );

		return '' !== $refusal ? $refusal : $this->units_refusal( $order, $action );
	}

	/**
	 * One shipment carries the whole order, so a delivery waits until EVERY unit that can still be handed over takes the
	 * hand-over: a cancelled unit and one the marketplace no longer lists the action for do not count.
	 *
	 * @param \WC_Order     $order  Order.
	 * @param Seller_Action $action The hand-over action.
	 * @return string '' when the delivery may be arranged.
	 */
	private function units_refusal( \WC_Order $order, Seller_Action $action ): string {
		$cancelled = [];

		foreach ( Order_Units::of( $order ) as $record ) {
			if ( $record->is_cancelled() ) {
				$cancelled[ '#' . $record->id() ] = true;
			}
		}

		$ready   = 0;
		$waiting = null;

		foreach ( $this->runner->units( $order ) as $unit ) {
			if ( ! $unit->has_action( $action->id() ) || isset( $cancelled[ '#' . $unit->id() ] ) ) {
				continue;
			}

			if ( $action->is_offered_in( $unit->status() ) ) {
				++$ready;
			} elseif ( null === $waiting ) {
				$waiting = $unit;
			}
		}

		if ( null === $waiting ) {
			if ( $ready > 0 ) {
				return '';
			}

			/* translators: %s: name of an action, «Передать в доставку». */
			return sprintf( __( 'Сейчас доставку оформить нельзя: действие «%s» для этого заказа недоступно.', 'woodev-plugin-framework' ), $action->label() );
		}

		$status = '' === $waiting->status_label() ? $waiting->status() : $waiting->status_label();

		if ( 0 === $ready ) {
			/* translators: 1: the marketplace status of the goods, 2: name of an action, «Передать в доставку». */
			return sprintf( __( 'Сейчас заказ в статусе «%1$s»: доставку можно оформить, когда доступно действие «%2$s».', 'woodev-plugin-framework' ), $status, $action->label() );
		}

		/* translators: 1: the goods, 2: their marketplace status, 3: name of an action, «Передать в доставку». */
		return sprintf( __( 'Не все товары заказа готовы: «%1$s» — в статусе «%2$s». Доставку можно оформить, когда действие «%3$s» доступно для всех товаров заказа.', 'woodev-plugin-framework' ), $waiting->label(), $status, $action->label() );
	}

	/**
	 * @since 2.1.29
	 *
	 * @param \WC_Order $order Order.
	 * @return string[]
	 */
	public function notices( \WC_Order $order ): array {
		$delivery = Seller_Order::delivery( $order );

		if ( null === $delivery ) {
			return [];
		}

		$notices   = [];
		$extension = Seller_Delivery_Panel::extension_warning( $order, $delivery );
		$chosen    = Seller_Delivery_Panel::chosen_time( $delivery );

		if ( '' !== $extension ) {
			$notices[] = $extension;
		}

		if ( '' !== $chosen ) {
			/* translators: %s: day and time. */
			$notices[] = sprintf( __( 'Покупатель выбрал доставку: %s.', 'woodev-plugin-framework' ), $chosen );
		}

		if ( $delivery->is_pickup_point() ) {
			$notices[] = __( 'Доставка в пункт выдачи: это адрес пункта, а не покупателя.', 'woodev-plugin-framework' );
		}

		return $notices;
	}
}
