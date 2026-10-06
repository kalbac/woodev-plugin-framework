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
			// ONE listener, added now: the emails exist only once WooCommerce has built its mailer, and a webhook or
			// cron request that publishes a status may never have asked for it. {@see self::dispatch()} asks.
			add_action( 'woodev_shipping_delivery_status_changed', [ $this, 'dispatch' ], 10, 4 );
		}

		/**
		 * Hands a published status change to every status email.
		 *
		 * @since 2.0.2
		 * @param \WC_Order                                               $order    Shipment order.
		 * @param string|null                                             $previous Previous canonical state.
		 * @param string                                                  $current  Current canonical state.
		 * @param \Woodev\Framework\Shipping\Admin\Orders\Orders_Provider $provider Matched carrier.
		 * @return void
		 */
		public function dispatch( $order, $previous, $current, $provider ): void {
			if ( function_exists( 'WC' ) && WC() ) {
				// Building the mailer runs `woocommerce_email_classes`, i.e. self::register_emails().
				WC()->mailer();
			}

			foreach ( $this->emails as $email ) {
				$email->maybe_trigger( $order, $previous, (string) $current, $provider );
			}
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
					'subject'     => __( 'Order {order_number} has been handed over for delivery', 'woodev-plugin-framework' ),
					'heading'     => __( 'Your order has been handed over for delivery', 'woodev-plugin-framework' ),
					'body'        => __( 'Your shipment has been handed over to the carrier. Tracking number: {tracking_number}. {tracking_url}', 'woodev-plugin-framework' ),
					'enabled'     => true,
				],
				'customer_shipment_pickup' => [
					'title'       => __( 'Заказ ждёт в пункте выдачи', 'woodev-plugin-framework' ),
					'description' => __( 'Письмо покупателю, когда отправление поступило в пункт выдачи.', 'woodev-plugin-framework' ),
					'statuses'    => [ Delivery_Status::READY_FOR_PICKUP ],
					'subject'     => __( 'Order {order_number} is waiting at the pickup point', 'woodev-plugin-framework' ),
					'heading'     => __( 'Your order is waiting at the pickup point', 'woodev-plugin-framework' ),
					'body'        => __( 'Pick up order {order_number} at the pickup point: {pickup_point}.', 'woodev-plugin-framework' ),
					'enabled'     => true,
				],
				'customer_shipment_delivered' => [
					'title'       => __( 'Доставлено', 'woodev-plugin-framework' ),
					'description' => __( 'Письмо покупателю после доставки заказа.', 'woodev-plugin-framework' ),
					'statuses'    => [ Delivery_Status::DELIVERED ],
					'subject'     => __( 'Order {order_number} has been delivered', 'woodev-plugin-framework' ),
					'heading'     => __( 'Your order has been delivered', 'woodev-plugin-framework' ),
					'body'        => __( 'Order {order_number} has been delivered.', 'woodev-plugin-framework' ),
					'enabled'     => true,
				],
				'customer_shipment_exception' => [
					'title'       => __( 'Возврат / не доставлено', 'woodev-plugin-framework' ),
					'description' => __( 'Письмо покупателю при возврате или неудачной доставке.', 'woodev-plugin-framework' ),
					'statuses'    => [ Delivery_Status::RETURNED, Delivery_Status::FAILED ],
					'subject'     => __( 'There is a problem with the delivery of order {order_number}', 'woodev-plugin-framework' ),
					'heading'     => __( 'There is a problem with your delivery', 'woodev-plugin-framework' ),
					'body'        => __( 'We could not deliver order {order_number}. Please contact the store for details.', 'woodev-plugin-framework' ),
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
			}
			return array_merge( $emails, $this->emails );
		}
	}
endif;
