<?php
/**
 * The delivery of an order the seller delivers, on its order screen.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

use Woodev\Framework\Marketplace\Admin\Product\Screen_Names;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Flows\Seller_Order;
use Woodev\Framework\Marketplace\Orders\Seller_Delivery;

defined( 'ABSPATH' ) || exit;

/**
 * The part of the details box ({@see Order_Details_Box}) an order the seller delivers gets, and the one thing of a marketplace order
 * the merchant may change: the recipient, the phone and the shipping address. Beside a field the merchant has changed it shows the
 * marketplace's value. The day the buyer chose, a pickup point address, other phones and a phone that needs an extension are shown
 * above the fields.
 *
 * The fields are saved with the order screen's own form (the box adds the save button, since the order actions box is gone): the
 * framework's saver runs on `woocommerce_process_shop_order_meta`, on both order storages. Items, prices, totals and statuses
 * stay the marketplace's ({@see Order_Screen}). A value saved here differs from what the marketplace wrote, so no later read writes
 * over it ({@see \Woodev\Framework\Marketplace\Flows\Seller_Delivery_Writer}).
 *
 * @since 2.1.29
 */
final class Seller_Delivery_Panel {
	/**
	 * Name of the form array that holds the fields.
	 */
	public const INPUT = 'woodev_mp_delivery';

	/**
	 * Name of the nonce field.
	 */
	public const NONCE_FIELD = 'woodev_mp_delivery_nonce';

	/**
	 * @var Screen_Names
	 */
	private $names;

	/**
	 * @var Marketplace_Adapter
	 */
	private $adapter;

	/**
	 * @since 2.1.29
	 *
	 * @param Screen_Names        $names   Names.
	 * @param Marketplace_Adapter $adapter The adapter, asked for the names of its delivery schemes.
	 */
	public function __construct( Screen_Names $names, Marketplace_Adapter $adapter ) {
		$this->names   = $names;
		$this->adapter = $adapter;
	}

	/**
	 * Returns the name of an order's delivery scheme.
	 *
	 * @since 2.1.29
	 *
	 * @param \WC_Order $order Order.
	 * @return string The adapter's name, else the code; '' for an order with no scheme.
	 */
	public function scheme_name( \WC_Order $order ): string {
		$code    = Seller_Order::scheme( $order );
		$schemes = $this->adapter instanceof Delivery_Schemes ? $this->adapter->delivery_schemes() : [];

		return $schemes[ $code ] ?? $code;
	}

	/**
	 * Builds the panel.
	 *
	 * @since 2.1.29
	 *
	 * @param \WC_Order $order Order.
	 * @return string HTML, escaped; empty for an order the marketplace delivers.
	 */
	public function render( \WC_Order $order ): string {
		if ( ! Seller_Order::is_seller_delivered( $order ) ) {
			return '';
		}

		$delivery = Seller_Order::delivery( $order );
		$html     = '<h3>' . esc_html__( 'Получатель и доставка', 'woodev-plugin-framework' ) . '</h3>';

		foreach ( $this->warnings( $order, $delivery ) as $warning ) {
			$html .= '<div class="notice notice-warning inline"><p>' . esc_html( $warning ) . '</p></div>';
		}

		$html .= $this->facts( $delivery );
		$html .= '<div class="woodev-mp-delivery__fields">';

		foreach ( $this->fields() as $id => $label ) {
			$html .= $this->field( $order, $delivery, $id, $label );
		}

		$html .= '</div><p><button type="submit" class="button button-primary" name="save" value="woodev_mp_delivery">'
			. esc_html__( 'Сохранить получателя и адрес', 'woodev-plugin-framework' ) . '</button></p>';
		// WooCommerce's own order nonce comes with its order data box, which a marketplace order does not have.
		$html .= wp_nonce_field( 'woocommerce_save_data', 'woocommerce_meta_nonce', false, false );
		$html .= wp_nonce_field( self::nonce_action( $order->get_id() ), self::NONCE_FIELD, false, false );

		return '<div class="woodev-mp-delivery">' . $html . '</div>';
	}

	/**
	 * Saves the fields the order screen's form sent. Does nothing for an order the marketplace delivers, a request without the
	 * panel's nonce and a user who may not edit the order.
	 *
	 * @since 2.1.29
	 *
	 * @param \WC_Order $order Order.
	 * @return bool Whether something changed.
	 */
	public function save( \WC_Order $order ): bool {
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';

		if ( ! Seller_Order::is_seller_delivered( $order )
			|| 1 > (int) wp_verify_nonce( $nonce, self::nonce_action( $order->get_id() ) )
			|| ! current_user_can( 'edit_shop_order', $order->get_id() )
			|| ! isset( $_POST[ self::INPUT ] ) || ! is_array( $_POST[ self::INPUT ] ) ) {
			return false;
		}

		$input   = map_deep( wp_unslash( $_POST[ self::INPUT ] ), 'sanitize_text_field' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by map_deep().
		$changed = false;

		foreach ( array_keys( $this->fields() ) as $id ) {
			if ( ! isset( $input[ $id ] ) || ! is_string( $input[ $id ] ) || $input[ $id ] === self::value( $order, $id ) ) {
				continue;
			}

			foreach ( self::order_fields( $id ) as $field ) {
				$order->{"set_$field"}( $input[ $id ] );
			}

			$changed = true;
		}

		if ( $changed ) {
			$order->save();
		}

		return $changed;
	}

	/**
	 * @return array<string,string> Label by field id.
	 */
	private function fields(): array {
		return [
			'first_name' => __( 'Имя получателя', 'woodev-plugin-framework' ),
			'last_name'  => __( 'Фамилия получателя', 'woodev-plugin-framework' ),
			'phone'      => __( 'Телефон', 'woodev-plugin-framework' ),
			'address_1'  => __( 'Адрес', 'woodev-plugin-framework' ),
			'address_2'  => __( 'Квартира, подъезд, этаж', 'woodev-plugin-framework' ),
			'city'       => __( 'Город', 'woodev-plugin-framework' ),
			'state'      => __( 'Область, край', 'woodev-plugin-framework' ),
			'postcode'   => __( 'Почтовый индекс', 'woodev-plugin-framework' ),
		];
	}

	/**
	 * The order fields a field of the panel writes: the recipient and the phone are the same on the billing side, which WooCommerce
	 * and the orders table read.
	 *
	 * @param string $id Field id.
	 * @return string[]
	 */
	private static function order_fields( string $id ): array {
		return in_array( $id, [ 'first_name', 'last_name', 'phone' ], true ) ? [ "shipping_$id", "billing_$id" ] : [ "shipping_$id" ];
	}

	/**
	 * The value a field shows: the shipping one, else the billing one for the recipient and the phone.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $id    Field id.
	 * @return string
	 */
	private static function value( \WC_Order $order, string $id ): string {
		foreach ( self::order_fields( $id ) as $field ) {
			$value = (string) $order->{"get_$field"}( 'edit' );

			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * One labelled input, with the marketplace's value beside it when the field says something else.
	 *
	 * @param \WC_Order            $order    Order.
	 * @param Seller_Delivery|null $delivery What the marketplace says.
	 * @param string               $id       Field id.
	 * @param string               $label    Label.
	 * @return string HTML, escaped.
	 */
	private function field( \WC_Order $order, ?Seller_Delivery $delivery, string $id, string $label ): string {
		$value = self::value( $order, $id );
		$told  = null === $delivery ? '' : self::told( $delivery, $id );
		$hint  = '';

		if ( '' !== $told && $told !== $value ) {
			/* translators: 1: marketplace name, 2: the value the marketplace gives. */
			$hint = '<span class="description">' . esc_html( sprintf( __( '%1$s сообщает: %2$s', 'woodev-plugin-framework' ), $this->names->name(), $told ) ) . '</span>';
		}

		return sprintf(
			'<p class="woodev-mp-delivery__field is-%1$s"><label for="woodev_mp_delivery_%1$s">%2$s</label><input type="text" id="woodev_mp_delivery_%1$s" name="%3$s[%1$s]" value="%4$s" />%5$s</p>',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( self::INPUT ),
			esc_attr( $value ),
			$hint
		);
	}

	/**
	 * What the marketplace says a field holds, in the form {@see \Woodev\Framework\Marketplace\Flows\Seller_Delivery_Writer} writes it.
	 *
	 * @param Seller_Delivery $delivery What the marketplace says.
	 * @param string          $id       Field id.
	 * @return string '' for a field the marketplace says nothing about.
	 */
	private static function told( Seller_Delivery $delivery, string $id ): string {
		switch ( $id ) {
			case 'first_name':
				return sanitize_text_field( $delivery->recipient() );
			case 'phone':
				return wc_sanitize_phone_number( $delivery->phone() );
			case 'address_1':
				return sanitize_text_field( $delivery->address() );
			default:
				return '';
		}
	}

	/**
	 * What the merchant must see before handing the order over.
	 *
	 * @param \WC_Order            $order    Order.
	 * @param Seller_Delivery|null $delivery What the marketplace says.
	 * @return string[]
	 */
	private function warnings( \WC_Order $order, ?Seller_Delivery $delivery ): array {
		if ( null === $delivery ) {
			return [];
		}

		$warnings = [];

		if ( '' === self::value( $order, 'address_1' ) ) {
			$warnings[] = '' !== $delivery->address_note()
				? $delivery->address_note()
				: __( 'Адрес доставки от маркетплейса ещё не получен.', 'woodev-plugin-framework' );
		}

		if ( '' !== $delivery->phone_extension() && wc_sanitize_phone_number( $delivery->phone() ) === self::value( $order, 'phone' ) ) {
			/* translators: %s: phone extension. */
			$warnings[] = sprintf( __( 'Для связи по этому номеру нужен добавочный: %s. Служба доставки не наберёт его сама.', 'woodev-plugin-framework' ), $delivery->phone_extension() );
		}

		return $warnings;
	}

	/**
	 * The facts of the delivery the merchant only reads.
	 *
	 * @param Seller_Delivery|null $delivery What the marketplace says.
	 * @return string HTML, escaped.
	 */
	private function facts( ?Seller_Delivery $delivery ): string {
		if ( null === $delivery ) {
			return '';
		}

		$rows = [];
		$day  = \DateTimeImmutable::createFromFormat( '!Y-m-d', $delivery->date() );

		if ( '' !== $delivery->date() ) {
			$time = trim( $delivery->time_from() . ( '' === $delivery->time_to() ? '' : '–' . $delivery->time_to() ) );

			$rows[ __( 'Покупатель выбрал доставку', 'woodev-plugin-framework' ) ] = trim( ( false === $day ? $delivery->date() : $day->format( 'd.m.Y' ) ) . ' ' . $time );
		}

		if ( $delivery->is_pickup_point() ) {
			$rows[ __( 'Куда доставить', 'woodev-plugin-framework' ) ] = __( 'В пункт выдачи: это адрес пункта, а не покупателя.', 'woodev-plugin-framework' );
		}

		foreach ( $delivery->other_phones() as $other ) {
			/* translators: 1: phone number, 2: its extension. */
			$rows[ __( 'Запасной номер', 'woodev-plugin-framework' ) ][] = '' === $other['extension'] ? $other['phone'] : sprintf( __( '%1$s, добавочный %2$s', 'woodev-plugin-framework' ), $other['phone'], $other['extension'] );
		}

		$html = '';

		foreach ( $rows as $label => $value ) {
			$html .= '<tr><th>' . esc_html( (string) $label ) . ':</th><td>' . esc_html( implode( '; ', (array) $value ) ) . '</td></tr>';
		}

		return '' === $html ? '' : '<table cellspacing="0"><tbody>' . $html . '</tbody></table>';
	}

	/**
	 * @param int $order_id Order id.
	 * @return string The nonce action of an order's fields.
	 */
	public static function nonce_action( int $order_id ): string {
		return 'woodev_mp_delivery_' . $order_id;
	}
}
