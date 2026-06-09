<?php
/**
 * Woodev Shipping Method
 *
 * Base shipping method class providing common functionality for all
 * shipping method types (courier, pickup, postal).
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping;

use Woodev\Framework\Shipping\Pickup\Pickup_Point_Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Shipping_Method' ) ) :

	abstract class Shipping_Method extends \WC_Shipping_Method {

		/** Courier delivery type */
		const TYPE_COURIER = 'courier';

		/** Pickup point delivery type */
		const TYPE_PICKUP = 'pickup';

		/** Postal delivery type */
		const TYPE_POSTAL = 'postal';

		const SHIPPING_CLASS_NONE = 'none';

		const SHIPPING_CLASS_ANY = 'any';

		/** Shipping zones feature */
		const FEATURE_SHIPPING_ZONES = 'shipping-zones';

		/** Instance settings feature */
		const FEATURE_INSTANCE_SETTINGS = 'instance-settings';

		const FEATURE_SHIPPING_CLASSES = 'shipping-classes';

		/** Box-packing feature: lets the method combine package contents into parcels. */
		const FEATURE_BOX_PACKING = 'box-packing';

		/**
		 * Gets the unique method identifier.
		 *
		 * Used for WC registration. Must be unique across all methods.
		 *
		 * @since 1.5.0
		 *
		 * @return string method ID
		 */
		abstract public static function get_method_id(): string;

		/**
		 * Gets the delivery type for this method.
		 *
		 * @since 1.5.0
		 *
		 * @return string one of TYPE_COURIER, TYPE_PICKUP, TYPE_POSTAL
		 */
		abstract public function get_delivery_type(): string;

		/**
		 * Constructor.
		 *
		 * @since 1.5.0
		 *
		 * @param int $instance_id shipping method instance ID
		 */
		public function __construct( $instance_id = 0 ) {

			parent::__construct( $instance_id );

			$this->id = static::get_method_id();

			$this->get_plugin()->set_shipping_method( $this->get_method_id(), $this );

			$this->supports = [
				self::FEATURE_SHIPPING_ZONES,
				self::FEATURE_INSTANCE_SETTINGS,
			];

			// Load form fields
			$this->init_form_fields();

			// Initialize and load settings
			$this->init_settings();

			// admin only
			if ( is_admin() ) {
				add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
			}
		}

		public function init_form_fields() {

			$this->instance_form_fields = [

				'title'       => [
					'title'    => esc_html__( 'Title', 'woodev-plugin-framework' ),
					'type'     => 'text',
					'desc_tip' => esc_html__(
						'Shipping method title that the customer will see during checkout.',
						'woodev-plugin-framework'
					),
					'default'  => $this->get_default_title(),
				],

				'description' => [
					'title'       => esc_html__( 'Description', 'woodev-plugin-framework' ),
					'type'        => 'textarea',
					'desc_tip'    => esc_html__( 'Shipping method description that the customer will see during checkout.', 'woodev-plugin-framework' ),
					'default'     => $this->get_default_description(),
					'css'         => 'max-width:400px;',
					'placeholder' => esc_attr__( 'Enter description here', 'woodev-plugin-framework' ),
				],
			];

			$this->instance_form_fields = array_merge( $this->instance_form_fields, $this->get_method_form_fields() );

			if ( $this->supports( self::FEATURE_SHIPPING_CLASSES ) ) {

				$this->instance_form_fields['shipping_class_id'] = [
					'title'    => esc_html__( 'Shipping class', 'woodev-plugin-framework' ),
					'type'     => 'select',
					'class'    => 'wc-enhanced-select',
					'default'  => self::SHIPPING_CLASS_ANY,
					'options'  => $this->get_shipping_classes_options(),
					'desc_tip' => esc_html__( 'Select the shipping class that this method applies to.', 'woodev-plugin-framework' ),
				];
			}

			if ( $this->supports( self::FEATURE_BOX_PACKING ) ) {

				$this->instance_form_fields['packing_algorithm'] = [
					'title'    => esc_html__( 'Packing algorithm', 'woodev-plugin-framework' ),
					'type'     => 'select',
					'class'    => 'wc-enhanced-select',
					'default'  => \Woodev_Packer_Dispatcher::ALGORITHM_VIRTUAL,
					'options'  => \Woodev_Packer_Dispatcher::get_algorithms(),
					'desc_tip' => esc_html__( 'How cart items are combined into parcels before rate calculation.', 'woodev-plugin-framework' ),
				];
			}

			$this->instance_form_fields = apply_filters( 'woodev_shipping_method_' . $this->get_id() . '_form_fields', $this->instance_form_fields, $this );
		}

		/**
		 * Returns an array of form fields specific for this method.
		 *
		 * @since 1.5.0
		 * @return array of form fields
		 */
		abstract protected function get_method_form_fields(): array;

		/**
		 * Get the default shipping method title, which is configurable within the
		 * admin and displayed on checkout
		 *
		 * @since 1.5.0
		 * @return string shipping method title to show on checkout
		 */
		protected function get_default_title(): string {

			if ( $this->is_courier_shipping() ) {
				return esc_html__( 'Courier delivery', 'woodev-plugin-framework' );
			} elseif ( $this->is_pickup_shipping() ) {
				return esc_html__( 'Pickup delivery', 'woodev-plugin-framework' );
			} elseif ( $this->is_postal_shipping() ) {
				return esc_html__( 'Postal delivery', 'woodev-plugin-framework' );
			}

			return '';
		}


		/**
		 * Get the default shipping method description, which is configurable
		 * within the admin and displayed on checkout
		 *
		 * @since 1.5.0
		 * @return string shipping method description to show on checkout
		 */
		protected function get_default_description(): string {

			if ( $this->is_courier_shipping() ) {
				return esc_html__( 'Delivery by courier to customer address.', 'woodev-plugin-framework' );
			} elseif ( $this->is_pickup_shipping() ) {
				return esc_html__( 'Delivery to pickup point.', 'woodev-plugin-framework' );
			} elseif ( $this->is_postal_shipping() ) {
				return esc_html__( 'Delivery to postal office.', 'woodev-plugin-framework' );
			}

			return '';
		}

		/**
		 * Final calculate_shipping method - delegates to abstract calculate_rate()
		 *
		 * @param array $package Package data
		 *
		 * @since 1.4.0
		 */
		final public function calculate_shipping( $package = [] ): void {

			/**
			 * Shipping Method Before Calculate Shipping Action.
			 *
			 * Triggered before shipping calculation begins.
			 *
			 * @param array $package Package data
			 * @param Shipping_Method $method Method instance
			 */
			do_action( 'woodev_shipping_method_before_calculate_shipping', $package, $this );

			if ( ! $this->should_send_cart_api_request() ) {
				return;
			}

			if ( ! $this->is_available_for_package( $package ) ) {
				return;
			}

			$this->before_calculate( $package );

			/**
			 * Shipping Method Pre-Calculate Rate Filter.
			 *
			 * Lets a cache layer short-circuit rate calculation by returning a
			 * previously stored rate for this package. Returning a non-null value
			 * skips the (potentially expensive, API-backed) calculate_rate() call;
			 * the resulting rate still passes through the
			 * `woodev_shipping_method_calculated_rate` filter below, where a cache
			 * can persist freshly computed rates.
			 *
			 * @since 1.5.0
			 *
			 * @param Shipping_Rate|null $rate Cached rate to use, or null to calculate normally
			 * @param array $package Package data
			 * @param Shipping_Method $method Method instance
			 */
			$rate = apply_filters( 'woodev_shipping_method_pre_calculate_rate', null, $package, $this );

			if ( null === $rate ) {
				$rate = $this->calculate_rate( $package );
			}

			/**
			 * Shipping Method Rate Filter.
			 *
			 * Allow actors to modify the calculated rate before it's added.
			 *
			 * @param Shipping_Rate|null $rate Calculated rate or null
			 * @param array $package Package data
			 * @param Shipping_Method $method Method instance
			 */
			$rate = apply_filters( 'woodev_shipping_method_calculated_rate', $rate, $package, $this );

			if ( $rate ) {
				/**
				 * Shipping Method Before Add Rate Action.
				 *
				 * Triggered before a rate is added.
				 *
				 * @param Shipping_Rate $rate Rate object
				 * @param array $package Package data
				 * @param Shipping_Method $method Method instance
				 */
				do_action( 'woodev_shipping_method_before_add_rate', $rate, $package, $this );

				// Convert Shipping_Rate object to array for WC compatibility
				$this->add_rate( $rate->to_array() );

				/**
				 * Shipping Method After Add Rate Action.
				 *
				 * Triggered after a rate is added.
				 *
				 * @param Shipping_Rate $rate Rate object
				 * @param array $package Package data
				 * @param Shipping_Method $method Method instance
				 */
				do_action( 'woodev_shipping_method_after_add_rate', $rate, $package, $this );
			}

			$this->after_calculate( $package, $rate );

			/**
			 * Shipping Method After Calculate Shipping Action.
			 *
			 * Triggered after shipping calculation completes.
			 *
			 * @param array $package Package data
			 * @param Shipping_Rate|null $rate Calculated rate or null
			 * @param Shipping_Method $method Method instance
			 */
			do_action( 'woodev_shipping_method_after_calculate_shipping', $package, $rate, $this );
		}

		/**
		 * Calculates the shipping rate for the package.
		 *
		 * Template method: when this method opts into {@see self::FEATURE_BOX_PACKING},
		 * the cart contents are packed into parcels via {@see self::pack_package()} and
		 * the (nullable) result is handed to {@see self::rate_package()}. Methods that do
		 * not support box-packing receive a null packed result. This method is final
		 * so the packing wiring cannot be bypassed — implement {@see self::rate_package()}
		 * for carrier-specific rating, or hook {@see 'woodev_shipping_method_pre_calculate_rate'}
		 * to short-circuit (e.g. a cache layer).
		 *
		 * @since 1.4.0
		 *
		 * @param array $package Package data.
		 * @return Shipping_Rate|null Shipping rate object, or null if no rate should be added.
		 */
		final protected function calculate_rate( array $package ): ?Shipping_Rate {

			$packed = $this->supports( self::FEATURE_BOX_PACKING )
				? $this->pack_package( $package )
				: null;

			return $this->rate_package( $package, $packed );
		}

		/**
		 * Produces the shipping rate for a package.
		 *
		 * Implemented by concrete shipping methods. When this method supports
		 * {@see self::FEATURE_BOX_PACKING} and the cart has physical contents, $packed
		 * carries the parcels produced by the configured packing algorithm; the carrier
		 * decides how to quote them (typically one multi-place request, not a sum of
		 * per-parcel prices). $packed is null when this method does not support
		 * box-packing OR there is nothing physical to pack (e.g. a virtual-only cart).
		 *
		 * @since 2.0.0
		 *
		 * @param array                      $package Package data.
		 * @param \Woodev_Packer_Result|null $packed  Packed parcels, or null (see above).
		 * @return Shipping_Rate|null Shipping rate object, or null if no rate should be added.
		 */
		abstract protected function rate_package( array $package, ?\Woodev_Packer_Result $packed ): ?Shipping_Rate;

		/**
		 * Check if method is available for package.
		 *
		 * @param array $package Package data
		 *
		 * @return bool
		 * @since 1.4.0
		 */
		protected function is_available_for_package( array $package ): bool {

			$is_available = true;

			if ( $this->get_plugin()->get_accepted_countries() ) {

				$country_code = Shipping_Helper::get_package_country( $package );

				if ( ! empty( $country_code ) && ! in_array( $country_code, $this->get_plugin()->get_accepted_countries() ) ) {

					$is_available = false;

					$this->get_plugin()->log(
						sprintf( 'The shipping method %s is not available for country %s', $this->get_title(), $country_code ),
						sprintf( '%s_%s', $this->get_plugin()->get_id(), $this->get_id() )
					);
				}
			}

			if ( $this->supports( self::FEATURE_SHIPPING_CLASSES ) && ! $this->has_only_selected_shipping_class( $package ) ) {

				$is_available = false;

				$this->get_plugin()->log(
					sprintf( 'Shipping cost calculation for the "%s" method was stopped because the cart contains items that do not match the selected shipping class.', $this->get_title() ),
					sprintf( '%s_%s', $this->get_plugin()->get_id(), $this->get_id() )
				);
			}

			/**
			 * Shipping Method Is Available Filter.
			 *
			 * Allow actors to modify method availability for a package.
			 *
			 * @param bool $is_available Whether method is available
			 * @param array $package Package data
			 * @param Shipping_Method $method Method instance
			 */
			return apply_filters( 'woodev_shipping_' . $this->id . '_is_available', $is_available, $package, $this );
		}

		/**
		 * Hook called before calculate_rate().
		 *
		 * @param array $package Package data
		 *
		 * @since 1.4.0
		 */
		protected function before_calculate( array $package ): void {}

		/**
		 * Hook called after calculate_rate().
		 *
		 * @param array              $package Package data
		 * @param Shipping_Rate|null $rate Calculated rate or null
		 *
		 * @since 1.4.0
		 */
		protected function after_calculate( array $package, ?Shipping_Rate $rate ): void {}

		/**
		 * Packs the package contents into parcels using the configured algorithm.
		 *
		 * Converts the WooCommerce package's cart contents into packer input
		 * items via {@see \Woodev_WC_Packer_Dispatcher::from_cart_items()} and
		 * runs the algorithm selected in this method's settings (falling back to
		 * the virtual minimal box). Returns null when there is nothing physical
		 * to pack or the WooCommerce-aware dispatcher is unavailable, so callers
		 * can skip dimensional rate logic without catching exceptions.
		 *
		 * @since 2.0.0
		 *
		 * @param array $package WooCommerce shipping package (expects a 'contents' array of cart items).
		 * @return \Woodev_Packer_Result|null packed result, or null when nothing is packable
		 */
		protected function pack_package( array $package ): ?\Woodev_Packer_Result {

			if ( ! class_exists( '\\Woodev_WC_Packer_Dispatcher' ) ) {
				return null;
			}

			$contents = isset( $package['contents'] ) && is_array( $package['contents'] ) ? $package['contents'] : [];

			$items = \Woodev_WC_Packer_Dispatcher::from_cart_items( $contents );

			if ( [] === $items ) {
				return null;
			}

			return \Woodev_WC_Packer_Dispatcher::pack( $this->get_packing_algorithm(), $items );
		}

		/**
		 * Gets the packing algorithm configured for this method.
		 *
		 * Falls back to the virtual minimal box when the stored value is not a
		 * registered algorithm, so an out-of-range option can never reach the
		 * dispatcher (which would otherwise throw).
		 *
		 * @since 2.0.0
		 *
		 * @return string one of the \Woodev_Packer_Dispatcher::ALGORITHM_* constants
		 */
		protected function get_packing_algorithm(): string {

			$algorithm = (string) $this->get_option( 'packing_algorithm', \Woodev_Packer_Dispatcher::ALGORITHM_VIRTUAL );

			return array_key_exists( $algorithm, \Woodev_Packer_Dispatcher::get_algorithms() )
				? $algorithm
				: \Woodev_Packer_Dispatcher::ALGORITHM_VIRTUAL;
		}

		/**
		 * Gets the plugin instance that owns this shipping method.
		 *
		 * @since 1.5.0
		 *
		 * @return Shipping_Plugin
		 */
		abstract protected function get_plugin(): Shipping_Plugin;

		/**
		 * Gets the order meta prefixed used for the *_order_meta() methods
		 *
		 * Defaults to `_wc_{gateway_id}_`
		 *
		 * @since 2.2.0
		 *
		 * @return string
		 */
		public function get_order_meta_prefix(): string {
			return sprintf( '_woodev_%s_', $this->get_id() );
		}

		/**
		 * Gets the method ID (for compatibility with WC_Shipping_Method).
		 *
		 * @return string
		 * @since 1.4.0
		 */
		public function get_id(): string {
			return $this->id;
		}

		/**
		 * Returns the shipping method id with dashes in place of underscores, and
		 * appropriate for use in frontend element names, classes and ids
		 *
		 * @since 1.5.0
		 * @return string shipping method id with dashes in place of underscores
		 */
		public function get_id_dasherized(): string {
			return str_replace( '_', '-', $this->get_id() );
		}

		public function get_id_underscored() {
			return str_replace( '-', '_', $this->get_id() );
		}

		public function is_courier_shipping(): bool {
			return $this->get_delivery_type() === self::TYPE_COURIER;
		}

		public function is_pickup_shipping(): bool {
			return $this->get_delivery_type() === self::TYPE_PICKUP;
		}

		public function is_postal_shipping(): bool {
			return $this->get_delivery_type() === self::TYPE_POSTAL;
		}

		/**
		 * Gets this method's pickup-point source, if it is a pickup method.
		 *
		 * Accessor seam (spec §4.5): lets shared subsystems (checkout, AJAX) reach a
		 * method's normalizing {@see Pickup_Point_Source} without knowing the concrete
		 * method class. The base method has no source and returns null; a pickup method
		 * overrides this to expose its {@see Pickup_Point_Source}.
		 *
		 * @since 1.5.0
		 *
		 * @return Pickup_Point_Source|null the pickup-point source, or null for non-pickup methods
		 */
		public function get_pickup_point_source(): ?Pickup_Point_Source {
			return null;
		}

		/**
		 * Determines whether a cart API request should be sent based on the current context.
		 *
		 * This method evaluates the execution environment and checks specific conditions such as
		 * whether the request is made in an admin context, during a REST API call, or via XML-RPC.
		 *
		 * @return bool True if a cart API request should be sent, false otherwise.
		 */
		private function should_send_cart_api_request(): bool {
			return ! (
				( is_admin() && did_action( 'woocommerce_cart_loaded_from_session' ) ) ||
				( defined( 'REST_REQUEST' ) || defined( 'REST_API_REQUEST' ) || defined( 'XMLRPC_REQUEST' ) )
			);
		}

		protected function get_shipping_classes_options(): array {

			$shipping_classes = WC()->shipping()->get_shipping_classes();

			$options = [
				self::SHIPPING_CLASS_ANY  => __( 'Any shipping class', 'woodev-plugin-framework' ),
				self::SHIPPING_CLASS_NONE => __( 'No shipping class', 'woodev-plugin-framework' ),
			];

			if ( ! empty( $shipping_classes ) ) {
				$options += wp_list_pluck( $shipping_classes, 'name', 'term_id' );
			}

			return $options;
		}

		protected function has_only_selected_shipping_class( $package ): bool {

			$shipping_class_id = $this->get_option( 'shipping_class_id', self::SHIPPING_CLASS_ANY );

			if ( self::SHIPPING_CLASS_ANY === $shipping_class_id ) {
				return true;
			}

			foreach ( $package['contents'] as $values ) {
				/** @var \WC_Product $product */
				$product = $values['data'];
				$qty     = $values['quantity'];

				if ( $qty > 0 && $product->needs_shipping() ) {

					$product_class_id = absint( $product->get_shipping_class_id() );

					if ( $shipping_class_id === self::SHIPPING_CLASS_NONE && $product_class_id !== 0 ) {
						return false;
					} elseif ( $product_class_id !== (int) $shipping_class_id ) {
						return false;
					}
				}
			}

			return true;
		}

		/**
		 * Adds support for the named feature or features.
		 *
		 * @since 1.5.0
		 *
		 * @param string|string[] $feature the feature name or names supported by this shipping method
		 */
		public function add_support( $feature ) {

			if ( ! is_array( $feature ) ) {
				$feature = [ $feature ];
			}

			foreach ( $feature as $name ) {

				// add support for feature if it's not already declared
				if ( ! in_array( $name, $this->supports ) ) {

					$this->supports[] = $name;

					/**
					 * Shipping Method Add Support Action.
					 *
					 * Fired when declaring support for a specific method feature.
					 * Allows other actors (including ourselves) to take action when support is declared.
					 *
					 * @since 1.0.0
					 *
					 * @param Shipping_Method $instance instance
					 * @param string $name of supported feature being added
					 */
					do_action( 'woodev_shipping_method_' . $this->get_id() . '_supports_' . str_replace( '-', '_', $name ), $this, $name );
				}
			}

			$this->supports = array_values( $this->supports );
		}
	}

endif;
