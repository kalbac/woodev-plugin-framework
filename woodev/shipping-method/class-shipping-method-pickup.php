<?php
/**
 * Woodev Shipping Method Pickup
 *
 * Abstract base class for pickup point delivery methods.
 * Requires the customer to select a pickup point at checkout.
 *
 * Wires the two PVZ collaborators from Phase 1 into the method as abstract seams,
 * mirroring {@see \Woodev\Framework\Shipping\Ajax\Shipping_AJAX}: a normalizing
 * {@see Pickup_Point_Source} (the sourcing axis) and a session-only
 * {@see Pickup_Selection} (the chosen-point store). Both are supplied by the
 * carrier plugin — the framework owns no concrete method id, source, or session
 * contract string here; this class stays fully abstract.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping;

use Woodev\Framework\Shipping\Pickup\Pickup_Point_Source;
use Woodev\Framework\Shipping\Pickup\Pickup_Selection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Shipping_Method_Pickup' ) ) :

	abstract class Shipping_Method_Pickup extends Shipping_Method {

		/**
		 * Gets the delivery type.
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		final public function get_delivery_type(): string {
			return self::TYPE_PICKUP;
		}

		/**
		 * Gets the carrier's normalizing pickup-point source.
		 *
		 * The sourcing seam (spec §4.1): the concrete source wraps a single carrier
		 * API and stays in the plugin — the framework hardcodes none. Mirrors
		 * {@see \Woodev\Framework\Shipping\Ajax\Shipping_AJAX::get_point_source()}.
		 *
		 * @since 1.5.0
		 *
		 * @return Pickup_Point_Source
		 */
		abstract protected function get_point_source(): Pickup_Point_Source;

		/**
		 * Gets the carrier's normalizing pickup-point source for shared subsystems.
		 *
		 * Overrides the null-default accessor on {@see Shipping_Method} so the inert
		 * base seam resolves to this pickup method's concrete {@see get_point_source()},
		 * letting shared subsystems reach the carrier's normalizing source.
		 *
		 * @since 1.5.0
		 *
		 * @return Pickup_Point_Source
		 */
		public function get_pickup_point_source(): ?Pickup_Point_Source {
			return $this->get_point_source();
		}

		/**
		 * Gets the carrier's session-only selection store.
		 *
		 * The selection seam (spec §4.1.v): the concrete store carries the
		 * plugin-supplied session key — the framework hardcodes no contract string.
		 * Mirrors {@see \Woodev\Framework\Shipping\Ajax\Shipping_AJAX::get_pickup_selection()}.
		 *
		 * @since 1.5.0
		 *
		 * @return Pickup_Selection
		 */
		abstract protected function get_pickup_selection(): Pickup_Selection;

		/**
		 * Determines whether the shopper has chosen a pickup point.
		 *
		 * Reads the carrier's {@see Pickup_Selection}; a pickup method cannot be
		 * completed at checkout until this is true.
		 *
		 * @since 1.5.0
		 *
		 * @return bool true when a point is stored in the session, false otherwise
		 */
		public function has_selected_pickup_point(): bool {
			return null !== $this->get_pickup_selection()->get();
		}

		/**
		 * Validates that a pickup point is selected before the order is placed.
		 *
		 * A pickup method requires a chosen point; when none is selected this adds a
		 * WooCommerce error notice (which blocks checkout) and returns false. The host
		 * plugin wires this into `woocommerce_after_checkout_validation`. Mirrors the
		 * notice-on-failure pattern of {@see \Woodev\Framework\Shipping\Checkout\Checkout_Handler}.
		 *
		 * @since 1.5.0
		 *
		 * @return bool true when a point is selected; false when checkout should be blocked
		 */
		public function validate_pickup_selection(): bool {

			if ( $this->has_selected_pickup_point() ) {
				return true;
			}

			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( esc_html__( 'Please select a pickup point.', 'woodev-plugin-framework' ), 'error' );
			}

			return false;
		}
	}

endif;
