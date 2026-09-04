<?php
/**
 * Woodev Shipping Rate
 *
 * Value Object representing a standardized shipping rate structure
 * compatible with WooCommerce's WC_Shipping_Method::add_rate() method.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Shipping_Rate' ) ) :

	/**
	 * Shipping Rate DTO
	 *
	 * Immutable value object that ensures shipping rates have the correct
	 * structure required by WooCommerce's add_rate() method.
	 *
	 * @since 1.5.0
	 */
	final class Shipping_Rate {

		/**
		 * Identifier for the method
		 *
		 * @var string
		 */
		private string $method_id;

		/**
		 * Unique rate identifier
		 *
		 * @var string
		 */
		private string $id;

		/**
		 * Rate label displayed to customer
		 *
		 * @var string
		 */
		private string $label;

		/**
		 * Rate cost (numeric string or array for complex costs)
		 *
		 * @var string|array
		 */
		private $cost;

		/**
		 * Package flag (boolean) or package data (array)
		 *
		 * @var bool|array|null
		 */
		private $package;

		/**
		 * Additional rate metadata
		 *
		 * @var array
		 */
		private array $meta_data;

		/**
		 * Constructor
		 *
		 * @since 1.5.0
		 *
		 * @param string       $method_id Shipping method ID
		 * @param string       $id        Unique rate identifier
		 * @param string       $label     Rate label for customer
		 * @param string|array $cost      Rate cost (string or array)
		 * @param bool|array   $package   Package flag or data (optional)
		 * @param array        $meta_data Additional metadata (optional)
		 *
		 * @throws \InvalidArgumentException if required fields are invalid
		 */
		public function __construct(
			string $method_id,
			string $id,
			string $label,
			$cost = '0',
			$package = null,
			array $meta_data = []
		) {

			if ( empty( $method_id ) ) {
				throw new \InvalidArgumentException( 'Shipping method ID cannot be empty' );
			}
			// Validate required fields
			if ( empty( $id ) ) {
				throw new \InvalidArgumentException( 'Rate ID cannot be empty' );
			}

			if ( empty( $label ) ) {
				throw new \InvalidArgumentException( 'Rate label cannot be empty' );
			}

			// Validate cost type
			if ( ! is_string( $cost ) && ! is_array( $cost ) ) {
				throw new \InvalidArgumentException( 'Rate cost must be a string or array' );
			}

			// Validate package type if provided
			if ( null !== $package && ! is_bool( $package ) && ! is_array( $package ) ) {
				throw new \InvalidArgumentException( 'Rate package must be a boolean, array, or null' );
			}

			$this->method_id = $method_id;
			$this->id        = $id;
			$this->label     = $label;
			$this->cost      = $cost;
			$this->package   = $package;
			$this->meta_data = $meta_data;
		}

		/**
		 * Retrieves the method ID.
		 *
		 * @return string The method ID.
		 */
		public function get_method_id(): string {
			return $this->method_id;
		}

		/**
		 * Gets the rate ID
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		public function get_id(): string {
			return $this->id;
		}

		/**
		 * Gets the rate label
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		public function get_label(): string {
			return $this->label;
		}

		/**
		 * Gets the rate cost
		 *
		 * @since 1.5.0
		 *
		 * @return string|array
		 */
		public function get_cost() {
			return $this->cost;
		}

		/**
		 * Gets the package data
		 *
		 * @since 1.5.0
		 *
		 * @return bool|array|null
		 */
		public function get_package() {
			return $this->package;
		}

		/**
		 * Gets the metadata
		 *
		 * @since 1.5.0
		 *
		 * @return array
		 */
		public function get_meta_data(): array {
			return $this->meta_data;
		}

		/**
		 * Converts the rate to array format for WC_Shipping_Method::add_rate()
		 *
		 * The meta is emitted FLAT — exactly the `key => value` pairs the rate was
		 * built with. `WC_Shipping_Rate::add_meta_data()` stores one order-item meta
		 * row per pair, so a flat array is what WooCommerce means by `meta_data`.
		 *
		 * ⚠ Do NOT re-introduce a wrapper keyed by `$this->method_id`. It was there
		 * until 2.0.2 and had zero consumers, while it made a migrating plugin unable
		 * to keep an existing flat meta key: `woocommerce-edostavka` writes
		 * `edostavka_rate` and reads it back off the shipping order item, which is a
		 * release-blocking installed-site contract (ADR-005). The wrapper is also
		 * redundant — the shipping order item already carries its own `method_id`, so
		 * naming the meta key after the method says nothing new — and on an empty
		 * meta array it produced one junk row instead of none.
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Meta is emitted flat; the `method_id` wrapper is gone (#764).
		 *
		 * @return array Rate data in WooCommerce format
		 */
		public function to_array(): array {
			$rate = [
				'id'        => $this->id,
				'label'     => $this->label,
				'cost'      => $this->cost,
				'meta_data' => $this->meta_data,
			];

			// Only include package if it was explicitly set
			if ( null !== $this->package ) {
				$rate['package'] = $this->package;
			}

			return $rate;
		}

		/**
		 * Converts the object to an array.
		 *
		 * @return array The object represented as an array.
		 */
		public function __toArray(): array {
			return $this->to_array();
		}
		/**
		 * Creates a rate with additional metadata
		 *
		 * Returns a new instance with merged metadata (immutability preserved).
		 *
		 * @since 1.5.0
		 *
		 * @param array $meta_data Additional metadata to merge
		 *
		 * @return Shipping_Rate New rate instance with merged metadata
		 */
		public function with_meta_data( array $meta_data ): Shipping_Rate {
			return new self(
				$this->method_id,
				$this->id,
				$this->label,
				$this->cost,
				$this->package,
				array_merge( $this->meta_data, $meta_data )
			);
		}

		/**
		 * Creates a rate with a different cost
		 *
		 * Returns a new instance with updated cost (immutability preserved).
		 *
		 * @since 1.5.0
		 *
		 * @param string|array $cost New cost value
		 *
		 * @return Shipping_Rate New rate instance with updated cost
		 */
		public function with_cost( $cost ): Shipping_Rate {
			return new self(
				$this->method_id,
				$this->id,
				$this->label,
				$cost,
				$this->package,
				$this->meta_data
			);
		}
	}

endif;
