<?php
/**
 * Tracking shipment value object
 *
 * @since 2.0.25
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Order\\Tracking_Shipment' ) ) :

	/**
	 * One parcel a buyer can follow: a tracking number, a tracking link, and optionally the parcel's own current status.
	 *
	 * This is the only shape the buyer-facing tracking block ({@see Buyer_Tracking}) and the shipment status emails read,
	 * so a carrier plugin describes its parcels once — through the `shipments_resolver` of its
	 * {@see \Woodev\Framework\Shipping\Admin\Orders\Orders_Provider} — and the My Account page and the email agree.
	 *
	 * Everything is checked in {@see self::create()}, so a consumer can print the fields without re-validating them:
	 * the number is trimmed, a link that is not an absolute http(s) URL becomes `null`, an empty status label becomes
	 * `null`. A shipment with neither a number nor a link is {@see self::is_empty()} and is dropped by the provider.
	 *
	 * @since 2.0.25
	 */
	final class Tracking_Shipment {
		/**
		 * @var string the human tracking number; empty only for a link-only shipment.
		 */
		private $number;

		/**
		 * @var string|null the absolute http(s) tracking URL.
		 */
		private $url;

		/**
		 * @var string|null the parcel's own current status, for carriers that ship several parcels per order.
		 */
		private $status_label;

		/**
		 * @var int|null unix time of that status.
		 */
		private $status_time;

		/**
		 * Use {@see self::create()} instead.
		 *
		 * @since 2.0.25
		 *
		 * @param string      $number       tracking number.
		 * @param string|null $url          tracking URL.
		 * @param string|null $status_label status label.
		 * @param int|null    $status_time  status unix time.
		 */
		private function __construct( string $number, ?string $url, ?string $status_label, ?int $status_time ) {
			$this->number       = $number;
			$this->url          = $url;
			$this->status_label = $status_label;
			$this->status_time  = $status_time;
		}

		/**
		 * Builds a shipment, normalising every field.
		 *
		 * @since 2.0.25
		 *
		 * @param string      $number       the tracking number a buyer quotes. May be empty when `$url` is given
		 *                                  (a carrier whose only buyer-facing handle is a tracking link).
		 * @param string|null $url          absolute http(s) tracking URL; anything else is dropped to `null`.
		 * @param string|null $status_label the parcel's own current status. Needed only when an order has several
		 *                                  parcels — a single parcel shows the order's status log instead.
		 * @param int|null    $status_time  unix time of that status; zero or negative means «no date».
		 * @return self
		 */
		public static function create( string $number, ?string $url = null, ?string $status_label = null, ?int $status_time = null ): self {
			$status_label = null === $status_label ? '' : trim( $status_label );

			return new self(
				trim( $number ),
				self::valid_url( $url ),
				'' === $status_label ? null : $status_label,
				null !== $status_time && $status_time > 0 ? $status_time : null
			);
		}

		/**
		 * Returns the value when it is an absolute http(s) URL, otherwise null.
		 *
		 * The one rule for «a link a buyer may be sent to»; the provider's `tracking_url_meta_key` reads through it too.
		 *
		 * @since 2.0.25
		 *
		 * @param mixed $value candidate URL.
		 * @return string|null
		 */
		public static function valid_url( $value ): ?string {
			if ( ! is_string( $value ) || false === filter_var( $value, FILTER_VALIDATE_URL ) ) {
				return null;
			}

			$parts = wp_parse_url( $value );
			if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), [ 'http', 'https' ], true ) ) {
				return null;
			}

			return $value;
		}

		/**
		 * The tracking number; empty for a link-only shipment.
		 *
		 * @since 2.0.25
		 *
		 * @return string
		 */
		public function get_number(): string {
			return $this->number;
		}

		/**
		 * The tracking URL, or null when the shipment has none.
		 *
		 * @since 2.0.25
		 *
		 * @return string|null
		 */
		public function get_url(): ?string {
			return $this->url;
		}

		/**
		 * The parcel's own status label, or null when the carrier gave none.
		 *
		 * @since 2.0.25
		 *
		 * @return string|null
		 */
		public function get_status_label(): ?string {
			return $this->status_label;
		}

		/**
		 * Unix time of the parcel's own status, or null when unknown.
		 *
		 * @since 2.0.25
		 *
		 * @return int|null
		 */
		public function get_status_time(): ?int {
			return $this->status_time;
		}

		public function is_empty(): bool {
			return '' === $this->number && null === $this->url;
		}

		/**
		 * The text a buyer reads as the link: the number, or — for a link-only shipment — a generic call to follow it.
		 *
		 * @since 2.0.25
		 *
		 * @return string
		 */
		public function get_display_text(): string {
			return '' !== $this->number ? $this->number : __( 'Track shipment', 'woodev-plugin-framework' );
		}
	}

endif;
