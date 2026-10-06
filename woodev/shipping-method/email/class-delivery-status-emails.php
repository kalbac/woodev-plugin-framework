<?php
/**
 * Registers the ready-made shipment status emails with WooCommerce.
 *
 * @since 2.0.2
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Email;

use Woodev\Framework\Shipping\Order\Delivery_Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Email\\Delivery_Status_Emails' ) ) :
	/**
	 * Adds framework shipment emails to WooCommerce's standard email settings screen.
	 *
	 * @since 2.0.2
	 */
	final class Delivery_Status_Emails {
		/** @var self|null */
		private static ?self $instance = null;
		/** @var array<string,Delivery_Status_Email> Registered email instances. */
		private array $emails = [];

		/** @since 2.0.2 @return self */
		public static function instance(): self {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/** @since 2.0.2 */
		private function __construct() {
			add_filter( 'woocommerce_email_classes', [ $this, 'register_emails' ] );
		}

		/**
		 * Adds the four standard status emails.
		 *
		 * @since 2.0.2
		 * @param array<string,\WC_Email> $emails Existing WooCommerce email classes.
		 * @return array<string,\WC_Email>
		 */
		public function register_emails( array $emails ): array {
			if ( ! class_exists( '\WC_Email' ) ) {
				return $emails;
			}
			require_once __DIR__ . '/class-delivery-status-email.php';
			if ( [] !== $this->emails ) {
				return array_merge( $emails, $this->emails );
			}
			$definitions = [
				'customer_shipment_created' => [
					'title'       => __( 'Передан в доставку + трек', 'woodev-plugin-framework' ),
					'description' => __( 'Письмо покупателю после передачи отправления перевозчику.', 'woodev-plugin-framework' ),
					'statuses'    => [ Delivery_Status::CREATED, Delivery_Status::IN_TRANSIT ],
					'subject'     => __( 'Заказ {order_number} передан в доставку', 'woodev-plugin-framework' ),
					'heading'     => __( 'Заказ передан в доставку', 'woodev-plugin-framework' ),
					'body'        => __( 'Отправление передано перевозчику. Номер для отслеживания: {tracking_number}. {tracking_url}', 'woodev-plugin-framework' ),
					'enabled'     => true,
				],
				'customer_shipment_pickup' => [
					'title'       => __( 'Заказ ждёт в пункте выдачи', 'woodev-plugin-framework' ),
					'description' => __( 'Письмо покупателю, когда отправление поступило в пункт выдачи.', 'woodev-plugin-framework' ),
					'statuses'    => [ Delivery_Status::READY_FOR_PICKUP ],
					'subject'     => __( 'Заказ {order_number} ждёт в пункте выдачи', 'woodev-plugin-framework' ),
					'heading'     => __( 'Заказ ждёт в пункте выдачи', 'woodev-plugin-framework' ),
					'body'        => __( 'Заберите заказ {order_number} в пункте выдачи: {pickup_point}.', 'woodev-plugin-framework' ),
					'enabled'     => true,
				],
				'customer_shipment_delivered' => [
					'title'       => __( 'Доставлено', 'woodev-plugin-framework' ),
					'description' => __( 'Письмо покупателю после доставки заказа.', 'woodev-plugin-framework' ),
					'statuses'    => [ Delivery_Status::DELIVERED ],
					'subject'     => __( 'Заказ {order_number} доставлен', 'woodev-plugin-framework' ),
					'heading'     => __( 'Заказ доставлен', 'woodev-plugin-framework' ),
					'body'        => __( 'Заказ {order_number} доставлен.', 'woodev-plugin-framework' ),
					'enabled'     => true,
				],
				'customer_shipment_exception' => [
					'title'       => __( 'Возврат / не доставлено', 'woodev-plugin-framework' ),
					'description' => __( 'Письмо покупателю при возврате или неудачной доставке.', 'woodev-plugin-framework' ),
					'statuses'    => [ Delivery_Status::RETURNED, Delivery_Status::FAILED ],
					'subject'     => __( 'Проблема с доставкой заказа {order_number}', 'woodev-plugin-framework' ),
					'heading'     => __( 'Проблема с доставкой', 'woodev-plugin-framework' ),
					'body'        => __( 'Не удалось доставить заказ {order_number}. Свяжитесь с магазином для уточнения деталей.', 'woodev-plugin-framework' ),
					'enabled'     => false,
				],
			];
			foreach ( $definitions as $id => $definition ) {
				$email = new Delivery_Status_Email(
					$id,
					$definition['title'],
					$definition['description'],
					$definition['statuses'],
					$definition['subject'],
					$definition['heading'],
					$definition['body'],
					$definition['enabled']
				);
				$this->emails[ $email->id ] = $email;
				add_action( 'woodev_shipping_delivery_status_changed', [ $email, 'maybe_trigger' ], 10, 4 );
			}
			return array_merge( $emails, $this->emails );
		}
	}
endif;
