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

			$rate = $this->calculate_rate( $package );

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
		 * Calculate shipping rate for the package.
		 *
		 * Must be implemented by concrete shipping method classes.
		 *
		 * @param array $package Package data
		 *
		 * @return Shipping_Rate|null Shipping rate object or null if no rate should be added
		 * @since 1.4.0
		 */
		abstract protected function calculate_rate( array $package ): ?Shipping_Rate;

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
