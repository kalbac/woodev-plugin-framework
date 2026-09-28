<?php
/**
 * Woodev Shipping Action Result
 *
 * The outcome of one carrier action — export, cancel, update, or a carrier's own
 * extra action performed through the `woodev_shipping_perform_order_action` filter.
 * Replaces the bare `string` / `bool` those methods used to return, neither of
 * which could carry the carrier's own explanation of a failure (#872).
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Order\\Action_Result' ) ) :

	/**
	 * Immutable success/failure outcome of a carrier action, with the carrier's text.
	 *
	 * ⚠ The message is the CARRIER's wording and is for the MERCHANT only — the
	 * REST action route and the order-edit notice show it, prefixed with the carrier
	 * name via {@see self::merchant_message()}. It must never be written to a
	 * customer-visible order note, an email or a storefront string (#608/#610). The
	 * shipment handler therefore never persists it, and a unit test pins that.
	 *
	 * @since 2.0.2
	 */
	final class Action_Result {

		/** @var bool whether the carrier accepted the action */
		private bool $success;

		/** @var string the carrier's text, or '' when it gave none */
		private string $message;

		/** @var string the carrier-assigned order id (export only), or '' */
		private string $carrier_order_id;

		/**
		 * @param bool   $success          whether the carrier accepted the action
		 * @param string $message          the carrier's text, or '' when it gave none
		 * @param string $carrier_order_id the carrier-assigned order id, or ''
		 */
		private function __construct( bool $success, string $message, string $carrier_order_id ) {
			$this->success          = $success;
			$this->message          = trim( $message );
			$this->carrier_order_id = $carrier_order_id;
		}

		/**
		 * The carrier accepted the action.
		 *
		 * @since 2.0.2
		 *
		 * @param string $carrier_order_id the carrier-assigned order id — export only, '' for cancel/update
		 * @param string $message          an optional carrier note shown to the merchant instead of the framework's own success sentence
		 * @return self
		 */
		public static function success( string $carrier_order_id = '', string $message = '' ): self {
			return new self( true, $message, $carrier_order_id );
		}

		/**
		 * The carrier refused the action, or it could not be performed.
		 *
		 * @since 2.0.2
		 *
		 * @param string $message the carrier's reason, in its own words; '' when it gave none — the caller then falls back to a generic sentence
		 * @return self
		 */
		public static function failure( string $message = '' ): self {
			return new self( false, $message, '' );
		}

		/**
		 * Whether the carrier accepted the action.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function is_success(): bool {
			return $this->success;
		}

		/**
		 * The carrier's text, or '' when it gave none.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_message(): string {
			return $this->message;
		}

		/**
		 * The carrier-assigned order id — set by a successful export, '' otherwise.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_carrier_order_id(): string {
			return $this->carrier_order_id;
		}

		/**
		 * The sentence a merchant reads: «СДЭК: Неверный индекс получателя».
		 *
		 * The single place the carrier text is composed for display, so the REST
		 * route and the order-edit notice cannot drift. Returns `$fallback`
		 * untouched when the carrier gave no text.
		 *
		 * @since 2.0.2
		 *
		 * @param string $carrier_label the carrier's display name
		 * @param string $fallback      the framework's own sentence for an action with no carrier text
		 * @return string
		 */
		public function merchant_message( string $carrier_label, string $fallback ): string {
			if ( '' === $this->message ) {
				return $fallback;
			}

			return '' === $carrier_label ? $this->message : $carrier_label . ': ' . $this->message;
		}
	}

endif;
