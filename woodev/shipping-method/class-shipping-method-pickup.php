<?php
/**
 * Woodev Shipping Method Pickup
 *
 * Abstract base class for delivery methods where the customer collects from a
 * carrier pickup point rather than receiving a courier delivery.
 *
 * Wires the carrier's normalizing {@see Point_Source} (the sourcing axis) into the
 * method as an abstract seam. The carrier plugin supplies the concrete source — the
 * framework owns no concrete method id or source contract here; this class stays
 * fully abstract. The chosen point itself is owned elsewhere: the §8 checkout field
 * layer holds it during checkout, and the order handler stores it on the placed
 * order — this class does not gate on whether one is selected.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping;

use Woodev\Framework\Shipping\Pickup\Point_Source;

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
		 * The sourcing seam: the concrete source wraps a single carrier API and stays in
		 * the plugin — the framework hardcodes none.
		 *
		 * @since 1.5.0
		 *
		 * @return Point_Source
		 */
		abstract protected function get_point_source(): Point_Source;

		/**
		 * Gets the carrier's normalizing pickup-point source for shared subsystems.
		 *
		 * Overrides the null-default accessor on {@see Shipping_Method} so the inert
		 * base seam resolves to this pickup method's concrete {@see get_point_source()},
		 * letting shared subsystems reach the carrier's normalizing source.
		 *
		 * @since 1.5.0
		 *
		 * @return Point_Source|null
		 */
		public function get_pickup_point_source(): ?Point_Source {
			return $this->get_point_source();
		}
	}

endif;
