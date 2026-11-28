<?php
/**
 * What the merchant still has to do about a seller-delivered order after the delivery service's news.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

defined( 'ABSPATH' ) || exit;

/**
 * One sentence on an order, tied to the seller action that settles it. The screens show it only while a unit of the order still
 * takes that action ({@see \Woodev\Framework\Marketplace\Admin\Shared\Carrier_State::notice()}), so it goes away by itself once
 * the action went through, whoever ran it.
 *
 * @since 2.1.29
 */
final class Carrier_Notice {
	/**
	 * Order meta: `[ 'action' => seller action id, 'text' => sentence ]`.
	 */
	public const META = '_woodev_mp_carrier_notice';

	/**
	 * Returns the stored notice of an order.
	 *
	 * @since 2.1.29
	 *
	 * @param \WC_Order $order Order.
	 * @return array{action:string,text:string}|null Null when there is none.
	 */
	public static function of( \WC_Order $order ): ?array {
		$stored = $order->get_meta( self::META );

		if ( ! is_array( $stored ) || empty( $stored['action'] ) || empty( $stored['text'] ) ) {
			return null;
		}

		return [
			'action' => (string) $stored['action'],
			'text'   => (string) $stored['text'],
		];
	}

	/**
	 * Puts a notice on an order, over any other.
	 *
	 * @since 2.1.29
	 *
	 * @param \WC_Order $order     Order.
	 * @param string    $action_id Seller action that settles it.
	 * @param string    $text      Sentence for the merchant, in Russian.
	 * @return bool False when the order already says exactly this.
	 */
	public static function put( \WC_Order $order, string $action_id, string $text ): bool {
		$notice = [
			'action' => $action_id,
			'text'   => $text,
		];

		if ( self::of( $order ) === $notice ) {
			return false;
		}

		$order->update_meta_data( self::META, $notice );
		$order->save_meta_data();

		return true;
	}

	/**
	 * Takes the notice of an action off an order; a notice of another action stays.
	 *
	 * @since 2.1.29
	 *
	 * @param \WC_Order $order     Order.
	 * @param string    $action_id Seller action.
	 * @return void
	 */
	public static function clear( \WC_Order $order, string $action_id ): void {
		$notice = self::of( $order );

		if ( null === $notice || $notice['action'] !== $action_id ) {
			return;
		}

		$order->delete_meta_data( self::META );
		$order->save_meta_data();
	}
}
