<?php
/**
 * The city limit for WooCommerce's own «Самовывоз» (`local_pickup`) — done by the framework, for every store that runs
 * a Woodev shipping plugin, with no opt-in on the plugin's side.
 *
 * A method we do not own offers three seams and this class uses all three: `woocommerce_shipping_instance_form_fields_{id}`
 * (the two fields of {@see City_Limit_Form}), the custom field type's `woocommerce_generate_{type}_html` (registered by
 * {@see City_Limit_Form::register()}), and `woocommerce_shipping_{id}_is_available` (the decision, {@see City_Limit::permits()}).
 *
 * Registered ONCE per request, store-wide: only the framework copy that wins the bootstrap has its classes loaded, so a
 * static instance and a `$hooked` flag are the whole guard — a second shipping plugin calling {@see self::register()} is a
 * no-op (gotcha `a-process-static-once-per-request-gate-checks-only-the-first-plugin` is about a gate in a class every
 * plugin owns its OWN copy of; this one has a single copy).
 *
 * Classic checkout. The Checkout block's own pickup (`pickup_location`) is a separate card.
 *
 * @package Woodev\Framework\Shipping\Location
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Location;

use Woodev\Framework\Shipping\Instance_Field_Conditions;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\Core_Pickup_City_Limit' ) ) :

	/**
	 * Class Core_Pickup_City_Limit
	 *
	 * @since 2.0.2
	 */
	final class Core_Pickup_City_Limit {

		/** WooCommerce's «Самовывоз» method id. */
		public const METHOD_ID = 'local_pickup';

		/**
		 * @var self|null
		 */
		private static ?self $instance = null;

		/**
		 * @var bool
		 */
		private bool $hooked = false;

		/**
		 * @var Location_Service|null
		 */
		private ?Location_Service $service = null;

		/**
		 * The store-wide instance.
		 *
		 * @since 2.0.2
		 *
		 * @return self
		 */
		public static function instance(): self {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Test-only: drops the instance (and the hooks it added) so the next test starts clean.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public static function reset_for_tests(): void {
			if ( null !== self::$instance && self::$instance->hooked ) {
				remove_filter( 'woocommerce_shipping_instance_form_fields_' . self::METHOD_ID, [ self::$instance, 'add_fields' ] );
				remove_filter( 'woocommerce_shipping_' . self::METHOD_ID . '_is_available', [ self::$instance, 'filter_is_available' ] );
			}

			self::$instance = null;

			City_Limit_Form::reset_for_tests();
		}

		/**
		 * Adds the three hooks — once.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function register(): void {
			if ( $this->hooked ) {
				return;
			}

			$this->hooked = true;

			City_Limit_Form::register();

			add_filter( 'woocommerce_shipping_instance_form_fields_' . self::METHOD_ID, [ $this, 'add_fields' ] );
			add_filter( 'woocommerce_shipping_' . self::METHOD_ID . '_is_available', [ $this, 'filter_is_available' ], 10, 3 );
		}

		/**
		 * `woocommerce_shipping_instance_form_fields_local_pickup` — appends the city limit's two fields.
		 *
		 * Only while the location layer is wanted by some plugin: without it there is nothing to search cities with,
		 * and a control that can never be filled in is worse than none.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $fields The method's instance form fields.
		 *
		 * @return mixed
		 */
		public function add_fields( $fields ) {
			if ( ! is_array( $fields ) || ! Location_Provider_Registry::instance()->is_needed() ) {
				return $fields;
			}

			$ours = Instance_Field_Conditions::apply(
				City_Limit_Form::fields(),
				static function ( $key ): string {
					return 'woocommerce_' . self::METHOD_ID . '_' . $key;
				}
			);

			return array_merge( $fields, $ours );
		}

		/**
		 * `woocommerce_shipping_local_pickup_is_available` — applies the limit.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $available Whether the method is available so far.
		 * @param mixed $package   The package being rated.
		 * @param mixed $method    The method instance.
		 *
		 * @return mixed
		 */
		public function filter_is_available( $available, $package = [], $method = null ) {
			if ( true !== $available || ! $method instanceof \WC_Shipping_Method ) {
				return $available;
			}

			$mode = City_Limit::normalize_mode( $method->get_option( City_Limit::OPTION_MODE, '' ) );

			// The common case — no limit set — never builds the location service.
			if ( City_Limit::MODE_OFF === $mode ) {
				return $available;
			}

			$country = is_array( $package ) ? (string) ( $package['destination']['country'] ?? '' ) : '';

			if ( null === $this->service ) {
				$this->service = new Location_Service();
			}

			return City_Limit::permits( $mode, $method->get_option( City_Limit::OPTION_CITIES, '' ), $this->service, $country, City_Limit::zone_scope( (int) $method->instance_id ) );
		}
	}

endif;
