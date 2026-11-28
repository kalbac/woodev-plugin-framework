<?php
/**
 * What the screens say about the delivery service of a seller-delivered order.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Shared;

use Woodev\Framework\Marketplace\Admin\Order\Carrier_Actions;
use Woodev\Framework\Marketplace\Admin\Order\Posting_Unit;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action;
use Woodev\Framework\Marketplace\Flows\Carrier_Notice;
use Woodev\Framework\Marketplace\Flows\Seller_Order;
use Woodev\Framework\Shipping\Admin\Orders\Order_Actions;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Delivered_Order_Status;
use Woodev\Framework\Shipping\Order\Tracking_Shipment;

defined( 'ABSPATH' ) || exit;

/**
 * The one reading of it, for the order screen, the «Заказы» table and {@see \Woodev\Framework\Marketplace\Flows\Carrier_Bridge}: whether «Оформить доставку» is on offer, what the merchant
 * still has to do after the delivery service's news, and the parcels to follow. Everything comes from stored data and the shipping
 * module's registry of delivery services; nothing calls a marketplace or a delivery service. An order that is not the
 * marketplace's, or that the marketplace delivers, gets nothing.
 *
 * @since 2.1.29
 */
final class Carrier_State {
	/**
	 * @var Carrier_Actions
	 */
	private $adapter;

	/**
	 * What the marketplace's orders are created via.
	 *
	 * @var string
	 */
	private $created_via;

	/**
	 * @since 2.1.29
	 *
	 * @param Carrier_Actions $adapter     The marketplace's actions.
	 * @param string          $created_via What the marketplace's orders are created via.
	 */
	public function __construct( Carrier_Actions $adapter, string $created_via ) {
		$this->adapter     = $adapter;
		$this->created_via = $created_via;
	}

	/**
	 * Tells whether a delivery can be arranged on this site at all: a delivery plugin that takes orders from the admin is active,
	 * and the user may open its wizard.
	 *
	 * @since 2.1.29
	 *
	 * @return bool
	 */
	public function delivery_available(): bool {
		$registry = Orders_Registry::instance();

		if ( '' === $this->hand_over_id() ) {
			return false;
		}

		foreach ( $registry->get_providers() as $provider ) {
			if ( $provider->has_marker_writer() ) {
				return current_user_can( $registry->get_page_capability() );
			}
		}

		return false;
	}

	/**
	 * The «Оформить доставку» button of an order: on offer while the marketplace's hand-over exists for it and no shipment was
	 * created yet. Once a delivery service is chosen it reads «Изменить доставку».
	 *
	 * @since 2.1.29
	 *
	 * @param \WC_Order $order Order.
	 * @return array{changes:bool,label:string,url:string,reason:string}|null Null when there is no button; an empty `url` comes
	 *                                                                         with the reason it is off.
	 */
	public function button( \WC_Order $order ): ?array {
		if ( ! $this->owns( $order ) || ! $this->delivery_available() || [] === $this->units_with( $order, $this->hand_over_id() ) ) {
			return null;
		}

		$registry = Orders_Registry::instance();
		$entry    = $registry->get_delivery_entry( $order );
		$provider = $registry->resolve_provider_for_order( $order );

		if ( null === $entry || ( null !== $provider && Order_Actions::is_exported( $order, $provider ) ) ) {
			return null;
		}

		$url    = $registry->get_delivery_url( $order );
		$reason = '' === $url ? Order_Actions::delivery_refusal_reason( $order, $provider, $entry ) : '';

		if ( '' === $url && '' === $reason ) {
			return null;
		}

		return [
			'changes' => null !== $provider,
			'label'   => null === $provider ? __( 'Оформить доставку', 'woodev-plugin-framework' ) : __( 'Изменить доставку', 'woodev-plugin-framework' ),
			'url'     => $url,
			'reason'  => $reason,
		];
	}

	/**
	 * What the merchant still has to do after the delivery service's news ({@see Carrier_Notice}).
	 *
	 * @since 2.1.29
	 *
	 * @param \WC_Order $order Order.
	 * @return string '' when there is nothing, or the action that settles it is no longer on offer for any unit.
	 */
	public function notice( \WC_Order $order ): string {
		$notice = $this->owns( $order ) ? Carrier_Notice::of( $order ) : null;

		if ( null === $notice ) {
			return '';
		}

		$units = $this->units_with( $order, $notice['action'] );

		foreach ( $this->adapter->seller_actions() as $action ) {
			if ( $action instanceof Seller_Action && $action->id() === $notice['action'] ) {
				foreach ( $units as $unit ) {
					if ( $action->is_offered_in( $unit->status() ) ) {
						return $notice['text'];
					}
				}
			}
		}

		return '';
	}

	/**
	 * The delivery service of an order and the parcels to follow, as the buyer sees them.
	 *
	 * @since 2.1.29
	 *
	 * @param \WC_Order $order Order.
	 * @return array{service:string,parcels:Tracking_Shipment[]}|null Null while there is nothing to follow.
	 */
	public function tracking( \WC_Order $order ): ?array {
		$provider = $this->owns( $order ) ? Orders_Registry::instance()->resolve_provider_for_order( $order ) : null;
		$parcels  = null === $provider ? [] : $provider->get_tracking_shipments( $order );

		if ( [] === $parcels ) {
			return null;
		}

		return [
			'service' => $provider->get_label(),
			'parcels' => $parcels,
		];
	}

	/**
	 * The status the delivery service's «Статус доставленного заказа» setting has applied to an order
	 * ({@see Delivered_Order_Status}); the setting also marks an order that was in that status already.
	 *
	 * @since 2.1.29
	 *
	 * @param \WC_Order $order Order.
	 * @return string Status without `wc-`; '' when the setting did not act on the order.
	 */
	public function delivered_status( \WC_Order $order ): string {
		$registry = Orders_Registry::instance();
		$provider = $registry->resolve_provider_for_order( $order );

		if ( null === $provider || '' === (string) $order->get_meta( Delivered_Order_Status::APPLIED_META_PREFIX . $provider->get_id() ) ) {
			return '';
		}

		$plugin = $registry->get_provider_plugin( $provider->get_id() );

		return null === $plugin ? '' : (string) $plugin->get_export_settings()->get_delivered_status();
	}

	/**
	 * @param \WC_Order $order Order.
	 * @return bool Whether the order is the marketplace's and the seller delivers it.
	 */
	private function owns( \WC_Order $order ): bool {
		return Seller_Order::belongs_to( $order, $this->created_via );
	}

	/**
	 * @return string Id of the hand-over action; '' when the adapter names none.
	 */
	private function hand_over_id(): string {
		return (string) ( $this->adapter->carrier_actions()[ Carrier_Actions::HANDED_OVER ] ?? '' );
	}

	/**
	 * @param \WC_Order $order     Order.
	 * @param string    $action_id Action id.
	 * @return Posting_Unit[] The units of the order the action exists for.
	 */
	private function units_with( \WC_Order $order, string $action_id ): array {
		return array_filter(
			$this->adapter->posting_units( $order ),
			static function ( $unit ) use ( $action_id ): bool {
				return $unit instanceof Posting_Unit && $unit->has_action( $action_id );
			}
		);
	}
}
