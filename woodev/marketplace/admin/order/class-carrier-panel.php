<?php
/**
 * The delivery service of a seller-delivered order, on its order screen.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

use Woodev\Framework\Marketplace\Admin\Shared\Carrier_State;
use Woodev\Framework\Shipping\Order\Tracking_Shipment;

defined( 'ABSPATH' ) || exit;

/**
 * The parts of the details box ({@see Order_Details_Box}) a delivery service gives an order: what the merchant still has to do,
 * the service with its tracking numbers, and the «Оформить доставку» button ({@see Carrier_State}).
 *
 * @since 2.1.29
 */
final class Carrier_Panel {
	/**
	 * @var Carrier_State
	 */
	private $state;

	/**
	 * @since 2.1.29
	 *
	 * @param Carrier_State $state What there is to say about an order.
	 */
	public function __construct( Carrier_State $state ) {
		$this->state = $state;
	}

	/**
	 * Builds the notice and the tracking.
	 *
	 * @since 2.1.29
	 *
	 * @param \WC_Order $order Order.
	 * @return string HTML, escaped; empty when there is neither.
	 */
	public function render( \WC_Order $order ): string {
		$notice = $this->state->notice( $order );
		$html   = '' === $notice ? '' : '<div class="notice notice-warning inline"><p>' . esc_html( $notice ) . '</p></div>';

		$tracking = $this->state->tracking( $order );

		if ( null === $tracking ) {
			return $html;
		}

		$numbers = implode( ', ', array_map( [ self::class, 'parcel' ], $tracking['parcels'] ) );

		return $html . sprintf(
			'<table cellspacing="0" class="woodev-mp-delivery__tracking"><tbody><tr><th>%1$s:</th><td>%2$s</td></tr><tr><th>%3$s:</th><td>%4$s</td></tr></tbody></table>',
			esc_html__( 'Служба доставки', 'woodev-plugin-framework' ),
			esc_html( $tracking['service'] ),
			esc_html__( 'Трек-номер', 'woodev-plugin-framework' ),
			$numbers
		);
	}

	/**
	 * Builds the «Оформить доставку» button.
	 *
	 * @since 2.1.29
	 *
	 * @param \WC_Order $order Order.
	 * @return string HTML, escaped; empty when there is no button.
	 */
	public function button( \WC_Order $order ): string {
		$button = $this->state->button( $order );

		if ( null === $button ) {
			return '';
		}

		if ( '' === $button['url'] ) {
			return sprintf(
				'<span class="woodev-mp-action-off" title="%1$s"><button type="button" class="button" disabled aria-disabled="true">%2$s</button></span>',
				esc_attr( $button['reason'] ),
				esc_html( $button['label'] )
			);
		}

		return sprintf( '<a class="button" href="%1$s">%2$s</a>', esc_url( $button['url'] ), esc_html( $button['label'] ) );
	}

	/**
	 * One parcel: its number, linked when the delivery service gives an address to follow it by.
	 *
	 * @param Tracking_Shipment $parcel Parcel.
	 * @return string HTML, escaped.
	 */
	private static function parcel( Tracking_Shipment $parcel ): string {
		$number = (string) $parcel->get_number();
		$url    = (string) $parcel->get_url();

		if ( '' === $url ) {
			return esc_html( $number );
		}

		return sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( $url ),
			esc_html( '' === $number ? __( 'Отследить', 'woodev-plugin-framework' ) : $number )
		);
	}
}
