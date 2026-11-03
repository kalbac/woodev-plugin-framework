<?php
/**
 * WooCommerce shipment status email.
 *
 * @since 2.0.2
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Email;

use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Order\Buyer_Tracking;
use Woodev\Framework\Shipping\Order\Tracking_Shipment;

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
		/**
		 * @var string[] Canonical states that trigger this email.
		 */
		private array $statuses;

		/**
		 * @var string Default subject.
		 */
		private string $default_subject;

		/**
		 * @var string Default heading.
		 */
		private string $default_heading;

		/**
		 * @var string Default body.
		 */
		private string $default_body;

		/**
		 * @var bool Whether this email is enabled by default.
		 */
		private bool $default_enabled;

		/**
		 * @var Orders_Provider|null Matched carrier.
		 */
		private ?Orders_Provider $provider = null;

		/**
		 * @var string Current canonical status.
		 */
		private string $current_status = '';

		/**
		 * @var array<string,string> WooCommerce's own placeholders ({site_title}, …), kept beside ours.
		 */
		private array $base_placeholders = [];

		/**
		 * @var Tracking_Shipment[] The parcels of the order being mailed, from {@see Orders_Provider::get_tracking_shipments()}.
		 */
		private array $shipments = [];

		/**
		 * @var string The `{tracking_number}` value this class computed for several parcels (captions, one per line).
		 *             Compared with the final value to tell whether a filter replaced it.
		 */
		private string $own_tracking_number = '';

		/**
		 * @var string Default body used instead of {@see self::$default_body} when the order has several parcels and the
		 *             merchant has not written a body of their own; empty when this email has no such variant.
		 */
		private string $default_body_multiple;

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
		 * @param string   $body_multiple   Default body for an order with several parcels (@since 2.0.25); empty for none.
		 */
		public function __construct( string $id, string $title, string $description, array $statuses, string $subject, string $heading, string $body, bool $enabled, string $body_multiple = '' ) {
			$this->id               = $id;
			$this->title            = $title;
			$this->description      = $description;
			$this->customer_email   = true;
			$this->statuses         = $statuses;
			$this->default_subject  = $subject;
			$this->default_heading  = $heading;
			$this->default_body     = $body;
			$this->default_body_multiple = $body_multiple;
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
					'title' => __( 'Enabled', 'woodev-plugin-framework' ),
					'type' => 'checkbox',
					'label' => __( 'Send this email to the customer', 'woodev-plugin-framework' ),
					'default' => $this->default_enabled ? 'yes' : 'no',
				],
				'subject'    => [
					'title' => __( 'Subject', 'woodev-plugin-framework' ),
					'type' => 'text',
					'description' => __( 'Available placeholders: {order_number}, {tracking_number}, {tracking_url}, {carrier_name}, {pickup_point}, {delivery_date}.', 'woodev-plugin-framework' ),
					'default' => $this->default_subject,
					'desc_tip' => true,
				],
				'heading'    => [
					'title' => __( 'Heading', 'woodev-plugin-framework' ),
					'type' => 'text',
					'default' => $this->default_heading,
				],
				'email_type' => [
					'title' => __( 'Email type', 'woodev-plugin-framework' ),
					'type' => 'select',
					'class' => 'email_type wc-enhanced-select',
					'options' => $this->get_email_type_options(),
					'default' => 'html',
				],
				'body'       => [
					'title' => __( 'Email body', 'woodev-plugin-framework' ),
					'type' => 'textarea',
					'css' => 'width: 400px; height: 100px;',
					'default' => $this->default_body,
					'description' => __( 'Placeholders can be inserted in curly braces.', 'woodev-plugin-framework' ),
					'desc_tip' => true,
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
			$pickup  = '';
			$carrier = null !== $provider ? $provider->get_label() : '';
			if ( null !== $provider ) {
				$plugin = \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::instance()->get_provider_plugin( $provider->get_id() );
				if ( null !== $plugin ) {
					$carrier = $plugin->get_carrier_name();
				}
			}
			// The same parcels the My Account block shows (#1575), so the page and the mail agree.
			$this->shipments           = null !== $provider ? $provider->get_tracking_shipments( $order ) : [];
			$this->own_tracking_number = $this->tracking_number_text();
			if ( null !== $provider && null !== $provider->get_pickup_point_meta_key() ) {
				$point  = \Woodev_Order_Compatibility::get_order_meta( $order, $provider->get_pickup_point_meta_key() );
				$pickup = is_array( $point ) ? (string) ( $point['address'] ?? $point['name'] ?? '' ) : ( is_scalar( $point ) ? (string) $point : '' );
			}
			$placeholders = [
				'{order_number}'   => (string) $order->get_order_number(),
				'{tracking_number}' => $this->own_tracking_number,
				// One parcel: its link. Several: every link sits inside its own line of {tracking_number}.
				'{tracking_url}'   => 1 === count( $this->shipments ) ? (string) $this->shipments[0]->get_url() : '',
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
				$values[ $key ] = $this->html_value( (string) $key, (string) $value );
			}

			return strtr( $text, $values );
		}

		/**
		 * One placeholder value as safe HTML: escaped, except the framework's own markup — the tracking link, and the
		 * per-parcel links of an order with several parcels.
		 *
		 * @param string $key   Placeholder, e.g. `{tracking_url}`.
		 * @param string $value Its value.
		 * @return string
		 */
		private function html_value( string $key, string $value ): string {
			if ( '{tracking_url}' === $key && '' !== $value && '' !== esc_url( $value ) ) {
				return '<a href="' . esc_url( $value ) . '">' . esc_html( $value ) . '</a>';
			}

			// A filter that replaced the value is escaped like any other; only OUR text becomes per-parcel links.
			if ( '{tracking_number}' === $key && count( $this->shipments ) > 1 && $value === $this->own_tracking_number ) {
				return $this->tracking_numbers_html();
			}

			return esc_html( $value );
		}

		/**
		 * The `{tracking_number}` text: nothing, the number, or — for several parcels — one «Shipment N of M: number —
		 * link» line each. Plain text, so a filter, the plain-text mail and a custom subject all read it as it is.
		 *
		 * @return string
		 */
		private function tracking_number_text(): string {
			$count = count( $this->shipments );
			if ( $count <= 1 ) {
				return 1 === $count ? $this->shipments[0]->get_number() : '';
			}

			$lines = [];
			foreach ( $this->shipments as $index => $shipment ) {
				$line    = Buyer_Tracking::shipment_caption( $index + 1, $count ) . ': ' . $shipment->get_display_text();
				$lines[] = null === $shipment->get_url() ? $line : $line . ' — ' . $shipment->get_url();
			}

			return implode( "\n", $lines );
		}

		/**
		 * The several-parcels `{tracking_number}` for the HTML mail: each number is its own link when it has one.
		 *
		 * @return string
		 */
		private function tracking_numbers_html(): string {
			$count = count( $this->shipments );
			$lines = [];
			foreach ( $this->shipments as $index => $shipment ) {
				$text    = esc_html( $shipment->get_display_text() );
				$href    = null === $shipment->get_url() ? '' : esc_url( $shipment->get_url() );
				$lines[] = esc_html( Buyer_Tracking::shipment_caption( $index + 1, $count ) ) . ': '
					. ( '' === $href ? $text : '<a href="' . $href . '">' . $text . '</a>' );
			}

			return implode( '<br>', $lines );
		}

		/**
		 * The numbers alone, comma-separated — what a subject or heading can carry, with no captions or line breaks.
		 *
		 * @return string
		 */
		private function tracking_numbers_inline(): string {
			$numbers = array_filter(
				array_map(
					static function ( Tracking_Shipment $shipment ): string {
						return $shipment->get_number();
					},
					$this->shipments
				),
				'strlen'
			);

			return implode( ', ', $numbers );
		}

		/**
		 * Runs a WooCommerce getter with the short, one-line form of `{tracking_number}` when it is still ours.
		 *
		 * The subject and the heading go through the same placeholders as the body, and several «Shipment N of M»
		 * lines do not belong in either.
		 *
		 * @param callable $getter Reads the subject or the heading.
		 * @return string
		 */
		private function with_inline_tracking_numbers( callable $getter ): string {
			$original = $this->placeholders['{tracking_number}'] ?? null;
			if ( count( $this->shipments ) <= 1 || $original !== $this->own_tracking_number ) {
				return (string) $getter();
			}

			$this->placeholders['{tracking_number}'] = $this->tracking_numbers_inline();
			try {
				return (string) $getter();
			} finally {
				$this->placeholders['{tracking_number}'] = $original;
			}
		}

		/**
		 * @since 2.0.25
		 *
		 * @return string
		 */
		public function get_subject(): string {
			return $this->with_inline_tracking_numbers(
				function () {
					return parent::get_subject();
				}
			);
		}

		/**
		 * @since 2.0.25
		 *
		 * @return string
		 */
		public function get_heading(): string {
			return $this->with_inline_tracking_numbers(
				function () {
					return parent::get_heading();
				}
			);
		}

		/**
		 * @return string
		 */
		public function get_default_subject() {
			return $this->default_subject;
		}

		/**
		 * @return string
		 */
		public function get_default_heading() {
			return $this->default_heading;
		}

		/**
		 * @return string
		 */
		public function get_default_body(): string {
			return $this->default_body;
		}

		/**
		 * The body text with placeholders: the merchant's, or the default.
		 *
		 * An order with zero or one parcel gets the default exactly as it has always read. Several parcels get the
		 * variant laid out for a list of numbers — but only while the body is still the framework's default; a body the
		 * merchant wrote or edited is never replaced.
		 *
		 * @return string
		 */
		private function body_text(): string {
			$body = (string) $this->get_option( 'body', $this->default_body );

			if ( count( $this->shipments ) > 1 && '' !== $this->default_body_multiple && $body === $this->default_body ) {
				return $this->default_body_multiple;
			}

			return $body;
		}

		/**
		 * @return string
		 */
		public function get_content_html() {
			return wc_get_template_html(
				$this->template_html,
				[
					'email' => $this,
					'order' => $this->object,
					'heading' => $this->get_heading(),
					'body' => $this->format_html( $this->body_text() ),
				],
				'',
				$this->template_base
			);
		}

		/**
		 * @return string
		 */
		public function get_content_plain() {
			return wc_get_template_html(
				$this->template_plain,
				[
					'email' => $this,
					'order' => $this->object,
					'heading' => $this->get_heading(),
					'body' => $this->format_string( $this->body_text() ),
				],
				'',
				$this->template_base
			);
		}
	}
endif;
