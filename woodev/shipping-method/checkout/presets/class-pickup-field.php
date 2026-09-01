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
 * supplied entirely by the host plugin, or (issue #709) left unspecified and
 * derived from {@see \Woodev\Framework\Shipping\Shipping_Method::is_pickup_shipping()}.
 *
 * Usage:
 *
 *   // Explicit method-id list — unchanged from before #709.
 *   $field = Pickup_Field::create(
 *       'yandex_pickup_point',
 *       [ 'yandex_pickup', 'yandex_pickup_express' ]
 *   );
 *
 *   // Or (#709): omit the list and let it track is_pickup_shipping() lazily.
 *   $field = Pickup_Field::create( 'yandex_pickup_point' );
 *
 *   $checkout_fields->add( $field );
 *
 * @since 2.0.2
 * @since 2.0.2 `$pickup_method_ids` is optional (issue #709).
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
	 * pickup method ids (or, since #709, whichever methods the framework itself
	 * derives as pickup — see {@see self::create()}), and marked as a pickup
	 * slot via {@see Field::mark_pickup_slot()} so the checkout adapter can
	 * locate the correct injection anchor.
	 *
	 * @since 2.0.2
	 */
	class Pickup_Field {

		/**
		 * Creates a Field builder pre-configured as a hidden pickup-point field.
		 *
		 * The field's visual `label` is intentionally left unset — its visible
		 * control is the pickup-point button/modal, not a native input, so a
		 * checkout-form label would be redundant. A sensible default
		 * `error_label` («Пункт выдачи») is set instead, so the framework's own
		 * required-field messages stay human even though `label` is blank
		 * (#299, #134). Call {@see Field::set_error_label()} again on the
		 * returned builder to override the default text.
		 *
		 * `$pickup_method_ids` is now OPTIONAL (issue #709). Omitted (`null`), the
		 * field is required exactly when the chosen method's own
		 * {@see \Woodev\Framework\Shipping\Shipping_Method::is_pickup_shipping()} says
		 * so — resolved LAZILY, at evaluation time, via the `is_pickup_method`
		 * condition-spec operator and
		 * {@see \Woodev\Framework\Shipping\Checkout\Checkout_Config::resolve_required()}
		 * — never baked into a static id list at THIS call, which can run before
		 * WooCommerce has lazily loaded the shipping-method class at all (see that
		 * method's own docblock). An explicit list still works exactly as before and
		 * still overrides the derived default — pass it when this field's requiredness
		 * must track a NARROWER or DIFFERENT set than "every method this plugin marked
		 * pickup".
		 *
		 * @since 2.0.2 Set a default `error_label` («Пункт выдачи») (#299, #134).
		 * @since 2.0.2 `$pickup_method_ids` is optional; omitted, the id list is derived
		 *              from `is_pickup_shipping()` lazily (#709).
		 *
		 * @param string        $id                Field identifier supplied by the host plugin.
		 * @param string[]|null $pickup_method_ids Shipping method ids that indicate a pickup
		 *                                         delivery (e.g. `[ 'carrier_pickup', 'carrier_pickup_express' ]`),
		 *                                         or `null` (default) to derive the list from
		 *                                         `is_pickup_shipping()` at evaluation time.
		 *
		 * @return Field Fluent builder ready for further configuration.
		 */
		public static function create( string $id, ?array $pickup_method_ids = null ): Field {
			$required_spec = null === $pickup_method_ids
				? [
					'state'    => 'chosen_shipping_method',
					'operator' => 'is_pickup_method',
				]
				: [
					'state'    => 'chosen_shipping_method',
					'operator' => 'in',
					'value'    => array_values( $pickup_method_ids ),
				];

			return Field::create( $id )
				->set_type( 'hidden' )
				->set_required( $required_spec )
				->set_error_label( __( 'Pickup point', 'woodev-plugin-framework' ) )
				->mark_pickup_slot();
		}
	}

endif;
