<?php
/**
 * Dependent Select Checkout Field Preset
 *
 * Static factory returning a pre-configured {@see Field} builder for a
 * `select` input whose available options depend on the value of a parent
 * field. Pure sugar — no domain data baked in; the caller supplies both the
 * field id and the parent field id.
 *
 * Usage:
 *
 *   $field = Dependent_Select::create( 'billing_city', 'billing_state' )
 *       ->set_label( 'Город' )
 *       ->set_source( $city_source_callback );
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

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Checkout\\Presets\\Dependent_Select' ) ) :

	/**
	 * Preset factory for a select field that depends on a parent field.
	 *
	 * Returns a {@see Field} builder pre-configured with type `'select'` and
	 * `depends_on` set to the given parent field id. All other builder methods
	 * remain available for further configuration by the host plugin.
	 *
	 * @since 2.0.2
	 */
	class Dependent_Select {

		/**
		 * Creates a Field builder pre-configured as a dependent select.
		 *
		 * @since 2.0.2
		 *
		 * @param string $id        Field identifier supplied by the host plugin.
		 * @param string $parent_id Id of the parent field whose value controls this field's options.
		 *
		 * @return Field Fluent builder ready for further configuration.
		 */
		public static function create( string $id, string $parent_id ): Field {
			return Field::create( $id )
				->set_type( 'select' )
				->depends_on( $parent_id );
		}
	}

endif;
