<?php
/**
 * WooCommerce shipment status email.
 *
 * @since 2.0.2
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Email;

use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Email\\Delivery_Status_Email' ) && class_exists( '\\WC_Email' ) ) :
	/**
	 * Sends a buyer email for one or more canonical shipment states.
	 *
	 * @since 2.0.2
	 */
	final class Delivery_Status_Email extends \WC_Email {
		/** @var string[] Canonical states that trigger this email. */
		private array $statuses;
		/** @var string Default subject. */
		private string $default_subject;
		/** @var string Default heading. */
		private string $default_heading;
		/** @var string Default body. */
		private string $default_body;
		/** @var bool Whether this email is enabled by default. */
		private bool $default_enabled;
		/** @var Orders_Provider|null Matched carrier. */
		private ?Orders_Provider $provider = null;
		/** @var string Current canonical status. */
		private string $current_status = '';
		/** @var array<string,string> WooCommerce's own placeholders ({site_title}, …), kept beside ours. */
		private array $base_placeholders = [];

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 * @param string   $id              Email id.
		 * @param string   $title           Admin title.
		 * @param string   $description     Admin description.
		 * @param string[] $statuses        Triggering canonical states.
		 * @param string   $subject         Default subject.
		 * @param string   $heading         Default heading.
		 * @param string   $body            Default body.
		 * @param bool     $enabled         Whether enabled by default.
		 */
		public function __construct( string $id, string $title, string $description, array $statuses, string $subject, string $heading, string $body, bool $enabled ) {
			$this->id               = $id;
			$this->title            = $title;
			$this->description      = $description;
			$this->customer_email   = true;
			$this->statuses         = $statuses;
			$this->default_subject  = $subject;
			$this->default_heading  = $heading;
			$this->default_body     = $body;
			$this->default_enabled  = $enabled;
			$this->template_html    = 'emails/shipment-status.php';
			$this->template_plain   = 'emails/plain/shipment-status.php';
			$this->template_base    = dirname( __DIR__ ) . '/templates/';
			parent::__construct();
			$this->base_placeholders = is_array( $this->placeholders ) ? $this->placeholders : [];
		}

		/**
		 * Declares WooCommerce's standard email settings fields.
		 *
		 * @since 2.0.2
		 * @return void
		 */
		public function init_form_fields() {
			$this->form_fields = [
				'enabled'    => [
					'title' => __( 'Включить', 'woodev-plugin-framework' ),
					'type' => 'checkbox',
					'label' => __( 'Отправлять это письмо покупателю', 'woodev-plugin-framework' ),
					'default' => $this->default_enabled ? 'yes' : 'no',
				],
				'subject'    => [
					'title' => __( 'Тема', 'woodev-plugin-framework' ),
					'type' => 'text',
					'description' => __( 'Доступны переменные: {order_number}, {tracking_number}, {tracking_url}, {carrier_name}, {pickup_point}, {delivery_date}.', 'woodev-plugin-framework' ),
					'default' => $this->default_subject,
					'desc_tip' => true,
				],
				'heading'    => [
					'title' => __( 'Заголовок', 'woodev-plugin-framework' ),
					'type' => 'text',
					'default' => $this->default_heading,
				],
				'email_type' => [
					'title' => __( 'Формат письма', 'woodev-plugin-framework' ),
					'type' => 'select',
					'class' => 'email_type wc-enhanced-select',
					'options' => $this->get_email_type_options(),
					'default' => 'html',
				],
				'body'       => [
					'title' => __( 'Текст письма', 'woodev-plugin-framework' ),
					'type' => 'textarea',
					'css' => 'width: 400px; height: 100px;',
					'default' => $this->default_body,
					'description' => __( 'Переменные можно вставлять в фигурных скобках.', 'woodev-plugin-framework' ),
				],
			];
		}

		/**
		 * Handles a published status change.
		 *
		 * @since 2.0.2
		 * @param \WC_Order       $order          Shipment order.
		 * @param string|null     $previous       Previous canonical state.
		 * @param string          $current        Current canonical state.
		 * @param Orders_Provider $provider       Matched carrier.
		 * @return void
		 */
		public function maybe_trigger( \WC_Order $order, ?string $previous, string $current, Orders_Provider $provider ): void {
			if ( ! in_array( $current, $this->statuses, true ) || $current === $previous || ! $this->is_enabled() ) {
				return;
			}
			$this->provider       = $provider;
			$this->current_status = $current;
			$this->trigger( $order->get_id(), $order );
		}

		/**
		 * Sends the email once per order (one flag per email, whichever of its statuses came first).
		 *
		 * @since 2.0.2
		 * @param int            $order_id Order id.
		 * @param \WC_Order|null $order Order.
		 * @return void
		 */
		public function trigger( $order_id, $order = null ) {
			$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order || '' === $order->get_billing_email() || ! in_array( $this->current_status, $this->statuses, true ) ) {
				return;
			}
			// One flag per EMAIL, not per status: «Передан в доставку» answers both `created` and `in_transit`,
			// and a shipment that passes through both must not send it twice.
			$flag = '_woodev_delivery_email_' . sanitize_key( $this->id );
			if ( '' !== (string) \Woodev_Order_Compatibility::get_order_meta( $order, $flag ) ) {
				return;
			}
			$this->object = $order;
			$this->recipient = $order->get_billing_email();
			if ( ! is_email( $this->get_recipient() ) ) {
				return;
			}
			$this->placeholders = $this->get_placeholders( $order, $this->provider );
			// Claim this order/email before the side effect so a re-delivered webhook cannot
			// pass the deduplication check while this send is in progress.
			// The value names the status that sent it, for diagnostics; any non-empty value means "sent".
			\Woodev_Order_Compatibility::update_order_meta( $order, $flag, $this->current_status );
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		/**
		 * Builds the template placeholder registry.
		 *
		 * @since 2.0.2
		 * @param \WC_Order            $order    Order.
		 * @param Orders_Provider|null $provider Carrier descriptor.
		 * @return array<string,string> Placeholder values.
		 */
		private function get_placeholders( \WC_Order $order, ?Orders_Provider $provider ): array {
			$tracking = '';
			$url      = '';
			$pickup   = '';
			$carrier  = null !== $provider ? $provider->get_label() : '';
			if ( null !== $provider && null !== $provider->get_tracking_meta_key() ) {
				$tracking = (string) \Woodev_Order_Compatibility::get_order_meta( $order, $provider->get_tracking_meta_key() );
				$template = $provider->get_tracking_url_template();
				$url      = '' !== $tracking && null !== $template ? str_replace( '{tracking}', rawurlencode( $tracking ), $template ) : '';
			}
			if ( null !== $provider && null !== $provider->get_pickup_point_meta_key() ) {
				$point  = \Woodev_Order_Compatibility::get_order_meta( $order, $provider->get_pickup_point_meta_key() );
				$pickup = is_array( $point ) ? (string) ( $point['address'] ?? $point['name'] ?? '' ) : ( is_scalar( $point ) ? (string) $point : '' );
			}
			$placeholders = [
				'{order_number}'   => (string) $order->get_order_number(),
				'{tracking_number}' => $tracking,
				'{tracking_url}'   => $url,
				'{carrier_name}'   => $carrier,
				'{pickup_point}'   => $pickup,
				'{delivery_date}'  => '',
			];
			/**
			 * Filters shipment email placeholder values for carrier-specific additions.
			 *
			 * @since 2.0.2
			 * @param array<string,string> $placeholders Available placeholder values.
			 * @param \WC_Order            $order        Shipment order.
			 * @param Orders_Provider|null $provider     Carrier descriptor.
			 */
			$filtered = apply_filters( 'woodev_shipping_delivery_email_placeholders', $placeholders, $order, $provider );
			return array_merge( $this->base_placeholders, is_array( $filtered ) ? $filtered : $placeholders );
		}

		/**
		 * Formats the body for the HTML email: placeholder values are escaped, and a tracking link becomes a link.
		 *
		 * Values come from carrier data and order meta; an HTML-looking value must not become markup.
		 *
		 * @param string $text Body with placeholders.
		 * @return string
		 */
		private function format_html( string $text ): string {
			$values = [];
			foreach ( $this->placeholders as $key => $value ) {
				$value        = (string) $value;
				$values[ $key ] = '{tracking_url}' === $key && '' !== $value && '' !== esc_url( $value )
					? '<a href="' . esc_url( $value ) . '">' . esc_html( $value ) . '</a>'
					: esc_html( $value );
			}

			return strtr( $text, $values );
		}

		/** @return string */
		public function get_default_subject() {
			return $this->default_subject; }
		/** @return string */
		public function get_default_heading() {
			return $this->default_heading; }
		/** @return string */
		public function get_default_body(): string {
			return $this->default_body; }

		/** @return string */
		public function get_content_html() {
			return wc_get_template_html(
				$this->template_html,
				[
					'email' => $this,
					'order' => $this->object,
					'heading' => $this->get_heading(),
					'body' => $this->format_html( (string) $this->get_option( 'body', $this->default_body ) ),
				],
				'',
				$this->template_base
			);
		}

		/** @return string */
		public function get_content_plain() {
			return wc_get_template_html(
				$this->template_plain,
				[
					'email' => $this,
					'order' => $this->object,
					'heading' => $this->get_heading(),
					'body' => $this->format_string( $this->get_option( 'body', $this->default_body ) ),
				],
				'',
				$this->template_base
			);
		}
	}
endif;
