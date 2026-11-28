<?php
/**
 * The buyer's data of marketplace orders in WooCommerce's privacy tools.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Orders\Seller_Delivery;

defined( 'ABSPATH' ) || exit;

/**
 * An order the seller delivers holds the buyer's data twice: in its own fields, which WooCommerce exports and erases itself, and in
 * the marketplace's data the framework keeps ({@see Seller_Order}): what the marketplace says now, its own data of the order, and
 * the values written into the order's fields. This adds the second to WooCommerce's order exporter and erasure, so every tool that
 * reaches the order handles both: the export and erasure requests of WordPress (they find an order by
 * its billing e-mail), «Remove personal data» of the orders list, and WooCommerce's clean-up of old orders.
 *
 * The erasure also empties the order's comment when the marketplace sent one, and WooCommerce marks the order, so no later read
 * writes the buyer's data again ({@see Seller_Delivery_Writer}).
 *
 * @since 2.1.29
 */
final class Order_Privacy {
	/**
	 * Marketplaces (`created_via`) already hooked by this PHP process.
	 *
	 * @var array<string,true>
	 */
	private static $registered = [];

	/**
	 * The `created_via` of the marketplace's orders.
	 *
	 * @var string
	 */
	private $created_via;

	/**
	 * Creates the hooks of one marketplace.
	 *
	 * @since 2.1.29
	 *
	 * @param string $created_via {@see Order_Meta_Contract::created_via()}.
	 */
	public function __construct( string $created_via ) {
		$this->created_via = $created_via;
	}

	/**
	 * Hooks WooCommerce's order exporter and erasure, once per marketplace.
	 *
	 * @since 2.1.29
	 *
	 * @return void
	 */
	public function register(): void {
		if ( '' === $this->created_via || isset( self::$registered[ $this->created_via ] ) ) {
			return;
		}

		self::$registered[ $this->created_via ] = true;

		add_filter( 'woocommerce_privacy_export_order_personal_data', [ $this, 'export' ], 10, 2 );
		add_action( 'woocommerce_privacy_before_remove_order_personal_data', [ $this, 'erase_comment' ] );
		add_action( 'woocommerce_privacy_remove_order_personal_data', [ $this, 'erase' ] );
	}

	/**
	 * Forgets which marketplaces were hooked. For tests: a process never needs it.
	 *
	 * @internal
	 *
	 * @since 2.1.29
	 *
	 * @return void
	 */
	public static function forget(): void {
		self::$registered = [];
	}

	/**
	 * Adds the marketplace's data of an order to WooCommerce's export of it.
	 *
	 * @since 2.1.29
	 *
	 * @param mixed $data  Name and value pairs.
	 * @param mixed $order The order.
	 * @return mixed
	 */
	public function export( $data, $order = null ) {
		if ( ! is_array( $data ) || ! $this->is_ours( $order ) ) {
			return $data;
		}

		$delivery = Seller_Order::delivery( $order );
		$pairs    = array_merge(
			null === $delivery ? [] : self::delivery_pairs( $delivery ),
			self::seeded_pairs( Seller_Order::seeded( $order ) )
		);

		foreach ( $pairs as $name => $value ) {
			if ( '' !== $value ) {
				$data[] = [
					'name'  => $name,
					'value' => $value,
				];
			}
		}

		$payload = Seller_Order::payload( $order );

		if ( [] !== $payload ) {
			$data[] = [
				'name'  => __( 'Данные заказа от маркетплейса', 'woodev-plugin-framework' ),
				'value' => (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			];
		}

		return $data;
	}

	/**
	 * Empties the comment the marketplace sent, before WooCommerce anonymises the order.
	 *
	 * @since 2.1.29
	 *
	 * @param mixed $order The order.
	 * @return void
	 */
	public function erase_comment( $order = null ): void {
		if ( ! $this->is_ours( $order ) ) {
			return;
		}

		$delivery = Seller_Order::delivery( $order );

		if ( null !== $delivery && '' !== $delivery->comment() ) {
			$order->set_customer_note( '' ); // WooCommerce saves the order right after.
		}
	}

	/**
	 * Removes the marketplace's data of an order after WooCommerce anonymised it.
	 *
	 * @since 2.1.29
	 *
	 * @param mixed $order The order.
	 * @return void
	 */
	public function erase( $order = null ): void {
		if ( $this->is_ours( $order ) ) {
			Seller_Order::erase_buyer_data( $order );
		}
	}

	/**
	 * @param mixed $order Whatever a hook handed over.
	 * @return bool Whether it is an order of this marketplace.
	 */
	private function is_ours( $order ): bool {
		return $order instanceof \WC_Order && $this->created_via === $order->get_created_via();
	}

	/**
	 * The delivery data as the export shows it.
	 *
	 * @param Seller_Delivery $delivery What the marketplace said.
	 * @return array<string,string> Value by name.
	 */
	private static function delivery_pairs( Seller_Delivery $delivery ): array {
		$main   = [
			'phone'     => $delivery->phone(),
			'extension' => $delivery->phone_extension(),
		];
		$phones = [];

		foreach ( array_merge( [ $main ], $delivery->other_phones() ) as $phone ) {
			if ( '' !== $phone['phone'] ) {
				/* translators: 1: phone number, 2: its extension. */
				$phones[] = '' === $phone['extension'] ? $phone['phone'] : sprintf( __( '%1$s, добавочный %2$s', 'woodev-plugin-framework' ), $phone['phone'], $phone['extension'] );
			}
		}

		$coordinates = '' === $delivery->latitude() || '' === $delivery->longitude() ? '' : $delivery->latitude() . ', ' . $delivery->longitude();

		return [
			__( 'Получатель (от маркетплейса)', 'woodev-plugin-framework' )           => $delivery->recipient(),
			__( 'Телефоны (от маркетплейса)', 'woodev-plugin-framework' )             => implode( '; ', $phones ),
			__( 'Адрес доставки (от маркетплейса)', 'woodev-plugin-framework' )       => $delivery->address(),
			__( 'Координаты адреса доставки', 'woodev-plugin-framework' )             => $coordinates,
			__( 'Комментарий покупателя (от маркетплейса)', 'woodev-plugin-framework' ) => $delivery->comment(),
			__( 'Выбранный день доставки', 'woodev-plugin-framework' )                => trim( $delivery->date() . ' ' . $delivery->time_from() . ( '' === $delivery->time_to() ? '' : '–' . $delivery->time_to() ) ),
		];
	}

	/**
	 * The values the framework wrote into the order's fields, as the export shows them. After the merchant corrects a field these
	 * still hold what the marketplace reported then, which neither the order's own fields nor the latest delivery data say.
	 *
	 * @param array<string,array<string,string>> $seeded {@see Seller_Order::seeded()}.
	 * @return array<string,string> Value by name.
	 */
	private static function seeded_pairs( array $seeded ): array {
		$names = [
			'recipient' => __( 'Получатель, записанный в заказ от маркетплейса', 'woodev-plugin-framework' ),
			'phone'     => __( 'Телефон, записанный в заказ от маркетплейса', 'woodev-plugin-framework' ),
			'address'   => __( 'Адрес, записанный в заказ от маркетплейса', 'woodev-plugin-framework' ),
			'comment'   => __( 'Комментарий, записанный в заказ от маркетплейса', 'woodev-plugin-framework' ),
		];
		$pairs = [];

		foreach ( $names as $group => $name ) {
			// The billing and shipping fields of a group usually hold the same value: it is listed once.
			$values = array_unique( array_filter( array_map( 'strval', (array) ( $seeded[ $group ] ?? [] ) ), 'strlen' ) );

			$pairs[ $name ] = implode( ', ', $values );
		}

		return $pairs;
	}
}
