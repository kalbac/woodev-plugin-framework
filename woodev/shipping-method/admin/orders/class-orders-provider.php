<?php
/**
 * Shipping orders — carrier descriptor
 *
 * @since 2.0.2
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Admin\Orders;

use Woodev\Framework\Shipping\Exceptions\Shipping_Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Admin\\Orders\\Orders_Provider' ) ) :

	/**
	 * Immutable value object a carrier plugin constructs to describe itself to the
	 * framework-owned «Заказы доставки» page (SP-10 spec D2).
	 *
	 * The framework derives none of these strings — the plugin supplies its own id,
	 * label, order-meta keys and shipping method ids, the same rule already enforced by
	 * {@see \Woodev\Framework\Shipping\Order\Shipping_Order_Handler::resolve()} and
	 * {@see \Woodev\Framework\Shipping\Admin\Shipping_Admin::get_page_slug()}. `id`,
	 * `label`, `marker_meta_key` and `method_ids` are required; every other field is
	 * optional and carries a documented meaning for its absence. `legacy_page_slug` is
	 * accepted and stored now but not consumed until increment 5.
	 *
	 * @since 2.0.2
	 */
	final class Orders_Provider {

		/**
		 * Carrier/tab id.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		private $id;

		/**
		 * Tab label.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		private $label;

		/**
		 * Order-meta key that marks an order as belonging to this carrier
		 * (SP-10 spec M2) — turned into a `meta_query` `EXISTS` clause by
		 * {@see Orders_Query}.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		private $marker_meta_key;

		/**
		 * The WC shipping method ids this carrier ships under — a carrier commonly
		 * ships more than one (courier AND pickup being the usual pair; round 2 of
		 * this increment fixed exactly that miss). The row builder tries each id in
		 * order through
		 * {@see \Woodev\Framework\Shipping\Shipping_Helper::get_order_shipping_item()}
		 * and uses the first match to resolve the row's `type` field.
		 *
		 * @since 2.0.2
		 *
		 * @var string[]
		 */
		private $method_ids;

		/**
		 * Order-meta key the carrier's own raw delivery status lives under, or null when
		 * this carrier has no status concept of its own — a row then resolves to
		 * `Delivery_Status::UNKNOWN` rather than a guessed state.
		 *
		 * @since 2.0.2
		 *
		 * @var string|null
		 */
		private $status_meta_key;

		/**
		 * Raw carrier status => one of `Delivery_Status::canonical_states()`.
		 *
		 * @since 2.0.2
		 *
		 * @var array<string,string>
		 */
		private $status_map;

		/**
		 * Raw carrier status => human label. Optional; a raw status absent here falls
		 * back to showing the raw value itself.
		 *
		 * @since 2.0.2
		 *
		 * @var array<string,string>
		 */
		private $status_labels;

		/**
		 * Tracking-number link template. Accepted, not yet consumed (increment 2).
		 *
		 * @since 2.0.2
		 *
		 * @var string|null
		 */
		private $tracking_url_template;

		/**
		 * Order-meta key the carrier's tracking number lives under, or null when this
		 * carrier has no tracking number of its own.
		 *
		 * @since 2.0.2
		 *
		 * @var string|null
		 */
		private $tracking_meta_key;

		/**
		 * Order-meta key the checkout-chosen pickup point was stored under (the same
		 * real key {@see \Woodev\Framework\Shipping\Order\Shipping_Order_Handler::store_pickup_point()}
		 * resolved to for this carrier), or null when this carrier has no pickup-point
		 * concept. The stored value is a whole
		 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Point::to_array()} — this
		 * descriptor names the KEY, not a scalar; the row builder reads the `address`
		 * entry out of it.
		 *
		 * @since 2.0.2
		 *
		 * @var string|null
		 */
		private $pickup_point_meta_key;

		/**
		 * Legacy v1 orders-page slug, for the future redirect. Accepted, not yet
		 * consumed (increment 5).
		 *
		 * @since 2.0.2
		 *
		 * @var string|null
		 */
		private $legacy_page_slug;

		/**
		 * Use {@see self::create()} instead.
		 *
		 * @since 2.0.2
		 *
		 * @param string               $id                     carrier/tab id.
		 * @param string               $label                  tab label.
		 * @param string               $marker_meta_key        order-meta marker key.
		 * @param string[]             $method_ids             WC shipping method ids.
		 * @param string|null          $status_meta_key        carrier status order-meta key.
		 * @param array<string,string> $status_map             raw status => canonical state.
		 * @param array<string,string> $status_labels          raw status => human label.
		 * @param string|null          $tracking_url_template  tracking-number link template.
		 * @param string|null          $tracking_meta_key      tracking-number order-meta key.
		 * @param string|null          $pickup_point_meta_key  pickup-point order-meta key.
		 * @param string|null          $legacy_page_slug       legacy v1 orders-page slug.
		 */
		private function __construct(
			string $id,
			string $label,
			string $marker_meta_key,
			array $method_ids,
			?string $status_meta_key,
			array $status_map,
			array $status_labels,
			?string $tracking_url_template,
			?string $tracking_meta_key,
			?string $pickup_point_meta_key,
			?string $legacy_page_slug
		) {
			$this->id                     = $id;
			$this->label                  = $label;
			$this->marker_meta_key        = $marker_meta_key;
			$this->method_ids             = $method_ids;
			$this->status_meta_key        = $status_meta_key;
			$this->status_map             = $status_map;
			$this->status_labels          = $status_labels;
			$this->tracking_url_template  = $tracking_url_template;
			$this->tracking_meta_key      = $tracking_meta_key;
			$this->pickup_point_meta_key  = $pickup_point_meta_key;
			$this->legacy_page_slug       = $legacy_page_slug;
		}

		/**
		 * Named constructor — validates the required fields.
		 *
		 * @since 2.0.2
		 *
		 * @param string              $id              carrier/tab id. Required, non-empty.
		 * @param string              $label           tab label. Required, non-empty.
		 * @param string              $marker_meta_key order-meta marker key (SP-10 M2). Required, non-empty.
		 * @param string[]            $method_ids      WC shipping method ids. Required, non-empty —
		 *                                              a carrier commonly ships more than one (courier
		 *                                              AND pickup being the usual pair).
		 * @param array<string,mixed> $args            {
		 *     Optional fields.
		 *
		 *     @type string               $status_meta_key       carrier status order-meta key.
		 *     @type array<string,string> $status_map            raw status => canonical state.
		 *     @type array<string,string> $status_labels         raw status => human label.
		 *     @type string               $tracking_url_template tracking-number link template.
		 *     @type string               $tracking_meta_key     tracking-number order-meta key.
		 *     @type string               $pickup_point_meta_key pickup-point order-meta key.
		 *     @type string               $legacy_page_slug      legacy v1 orders-page slug.
		 * }
		 * @return self
		 *
		 * @throws Shipping_Exception when a required field is empty.
		 */
		public static function create( string $id, string $label, string $marker_meta_key, array $method_ids, array $args = [] ): self {
			foreach ( [
				'id'              => $id,
				'label'           => $label,
				'marker_meta_key' => $marker_meta_key,
			] as $field => $value ) {
				if ( '' === $value ) {
					throw new Shipping_Exception(
						sprintf( 'Orders_Provider requires a non-empty "%s": the plugin must supply it.', $field )
					);
				}
			}

			if ( [] === $method_ids ) {
				throw new Shipping_Exception(
					'Orders_Provider requires a non-empty "method_ids": the plugin must supply it.'
				);
			}

			$method_ids = array_values( array_map( 'strval', $method_ids ) );

			$nullable_string = static function ( array $args, string $key ): ?string {
				return isset( $args[ $key ] ) && '' !== $args[ $key ] ? (string) $args[ $key ] : null;
			};

			$string_map = static function ( array $args, string $key ): array {
				return isset( $args[ $key ] ) && is_array( $args[ $key ] ) ? $args[ $key ] : [];
			};

			return new self(
				$id,
				$label,
				$marker_meta_key,
				$method_ids,
				$nullable_string( $args, 'status_meta_key' ),
				$string_map( $args, 'status_map' ),
				$string_map( $args, 'status_labels' ),
				$nullable_string( $args, 'tracking_url_template' ),
				$nullable_string( $args, 'tracking_meta_key' ),
				$nullable_string( $args, 'pickup_point_meta_key' ),
				$nullable_string( $args, 'legacy_page_slug' )
			);
		}

		/**
		 * Returns the carrier/tab id.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_id(): string {
			return $this->id;
		}

		/**
		 * Returns the tab label.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_label(): string {
			return $this->label;
		}

		/**
		 * Returns the order-meta marker key.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_marker_meta_key(): string {
			return $this->marker_meta_key;
		}

		/**
		 * Returns the WC shipping method ids, in declaration order.
		 *
		 * @since 2.0.2
		 *
		 * @return string[]
		 */
		public function get_method_ids(): array {
			return $this->method_ids;
		}

		/**
		 * Returns the carrier-status order-meta key, or null when this carrier has no
		 * status concept.
		 *
		 * @since 2.0.2
		 *
		 * @return string|null
		 */
		public function get_status_meta_key(): ?string {
			return $this->status_meta_key;
		}

		/**
		 * Returns the raw-status => canonical-state map.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string,string>
		 */
		public function get_status_map(): array {
			return $this->status_map;
		}

		/**
		 * Returns the raw-status => human-label map.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string,string>
		 */
		public function get_status_labels(): array {
			return $this->status_labels;
		}

		/**
		 * Returns the tracking-number link template, or null when not declared.
		 *
		 * @since 2.0.2
		 *
		 * @return string|null
		 */
		public function get_tracking_url_template(): ?string {
			return $this->tracking_url_template;
		}

		/**
		 * Returns the tracking-number order-meta key, or null when this carrier has no
		 * tracking number of its own.
		 *
		 * @since 2.0.2
		 *
		 * @return string|null
		 */
		public function get_tracking_meta_key(): ?string {
			return $this->tracking_meta_key;
		}

		/**
		 * Returns the pickup-point order-meta key, or null when this carrier has no
		 * pickup-point concept.
		 *
		 * @since 2.0.2
		 *
		 * @return string|null
		 */
		public function get_pickup_point_meta_key(): ?string {
			return $this->pickup_point_meta_key;
		}

		/**
		 * Returns the legacy v1 orders-page slug, or null when not declared.
		 *
		 * Not yet consumed — stored for increment 5.
		 *
		 * @since 2.0.2
		 *
		 * @return string|null
		 */
		public function get_legacy_page_slug(): ?string {
			return $this->legacy_page_slug;
		}
	}

endif;
