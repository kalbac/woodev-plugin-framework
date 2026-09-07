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
	 * label and order-meta marker key, the same rule already enforced by
	 * {@see \Woodev\Framework\Shipping\Order\Shipping_Order_Handler::resolve()} and
	 * {@see \Woodev\Framework\Shipping\Admin\Shipping_Admin::get_page_slug()}. `id`,
	 * `label` and `marker_meta_key` are required for THIS increment; `tracking_url_template`
	 * and `legacy_page_slug` are accepted and stored now (so a carrier can declare a full
	 * descriptor once) but are not consumed until increments 2 and 5 respectively (SP-10
	 * spec, «Increments»).
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
		 * Tracking-number link template. Accepted, not yet consumed (increment 2).
		 *
		 * @since 2.0.2
		 *
		 * @var string|null
		 */
		private $tracking_url_template;

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
		 * @param string      $id                     carrier/tab id.
		 * @param string      $label                  tab label.
		 * @param string      $marker_meta_key        order-meta marker key.
		 * @param string|null $tracking_url_template  tracking-number link template.
		 * @param string|null $legacy_page_slug       legacy v1 orders-page slug.
		 */
		private function __construct( string $id, string $label, string $marker_meta_key, ?string $tracking_url_template, ?string $legacy_page_slug ) {
			$this->id                    = $id;
			$this->label                 = $label;
			$this->marker_meta_key       = $marker_meta_key;
			$this->tracking_url_template = $tracking_url_template;
			$this->legacy_page_slug      = $legacy_page_slug;
		}

		/**
		 * Named constructor — validates the required fields.
		 *
		 * @since 2.0.2
		 *
		 * @param string              $id              carrier/tab id. Required, non-empty.
		 * @param string              $label           tab label. Required, non-empty.
		 * @param string              $marker_meta_key order-meta marker key (SP-10 M2). Required, non-empty.
		 * @param array<string,mixed> $args            {
		 *    Optional. Fields accepted but not yet consumed by this increment.
		 *
		 *     @type string $tracking_url_template tracking-number link template.
		 *     @type string $legacy_page_slug      legacy v1 orders-page slug.
		 * }
		 * @return self
		 *
		 * @throws Shipping_Exception when a required field is empty.
		 */
		public static function create( string $id, string $label, string $marker_meta_key, array $args = [] ): self {
			foreach ( [
				'id' => $id,
				'label' => $label,
				'marker_meta_key' => $marker_meta_key,
			] as $field => $value ) {
				if ( '' === $value ) {
					throw new Shipping_Exception(
						sprintf( 'Orders_Provider requires a non-empty "%s": the plugin must supply it.', $field )
					);
				}
			}

			$tracking_url_template = isset( $args['tracking_url_template'] ) && '' !== $args['tracking_url_template']
				? (string) $args['tracking_url_template']
				: null;

			$legacy_page_slug = isset( $args['legacy_page_slug'] ) && '' !== $args['legacy_page_slug']
				? (string) $args['legacy_page_slug']
				: null;

			return new self( $id, $label, $marker_meta_key, $tracking_url_template, $legacy_page_slug );
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
		 * Returns the tracking-number link template, or null when not declared.
		 *
		 * Not yet consumed — stored for increment 2.
		 *
		 * @since 2.0.2
		 *
		 * @return string|null
		 */
		public function get_tracking_url_template(): ?string {
			return $this->tracking_url_template;
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
