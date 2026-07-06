<?php
/**
 * Pickup Field Checkout Field Preset
 *
 * Static factory returning a pre-configured {@see Field} builder for a
 * hidden field that carries the chosen pickup point code. The field is
 * conditionally required when one of the caller-supplied shipping method
 * ids is the active chosen shipping method, and is tagged as a pickup slot
 * so the classic checkout adapter can mount the SP-5 slot anchor.
 *
 * Pure sugar — no domain data baked in; field id and method ids are
 * supplied entirely by the host plugin.
 *
 * Usage:
 *
 *   $field = Pickup_Field::create(
 *       'yandex_pickup_point',
 *       [ 'yandex_pickup', 'yandex_pickup_express' ]
 *   );
 *
 *   $checkout_fields->add( $field );
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Checkout\Presets;

use Woodev\Framework\Shipping\Checkout\Field;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

require_once dirname( __DIR__ ) . '/class-field.php';

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Checkout\\Presets\\Pickup_Field' ) ) :

	/**
	 * Preset factory for a hidden pickup-point field with conditional required.
	 *
	 * Returns a {@see Field} builder pre-configured as a `hidden` input that is
	 * required only when the chosen shipping method is one of the supplied
	 * pickup method ids, and marked as a pickup slot via
	 * {@see Field::mark_pickup_slot()} so the checkout adapter can locate the
	 * correct injection anchor.
	 *
	 * @since 2.0.2
	 */
	class Pickup_Field {

		/**
		 * Creates a Field builder pre-configured as a hidden pickup-point field.
		 *
		 * @since 2.0.2
		 *
		 * @param string   $id                Field identifier supplied by the host plugin.
		 * @param string[] $pickup_method_ids Shipping method ids that indicate a pickup delivery
		 *                                    (e.g. `[ 'carrier_pickup', 'carrier_pickup_express' ]`).
		 *
		 * @return Field Fluent builder ready for further configuration.
		 */
		public static function create( string $id, array $pickup_method_ids ): Field {
			$required_spec = [
				'state'    => 'chosen_shipping_method',
				'operator' => 'in',
				'value'    => array_values( $pickup_method_ids ),
			];

			return Field::create( $id )
				->set_type( 'hidden' )
				->set_required( $required_spec )
				->mark_pickup_slot();
		}
	}

endif;
