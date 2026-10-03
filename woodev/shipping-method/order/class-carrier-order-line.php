<?php
/**
 * Carrier order line value object.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Order\\Carrier_Order_Line' ) ) :

	/**
	 * Immutable product line prepared for a carrier order.
	 */
	final class Carrier_Order_Line {

		private string $name;

		private string $sku;

		private int $quantity;

		private int $unit_price_minor;

		private int $total_minor;

		/** @var array<string,mixed> */
		private array $tax_info;

		/**
		 * Creates a carrier order line.
		 *
		 * @since 2.0.2
		 *
		 * @param string              $name            Product name.
		 * @param string              $sku             Product SKU, or an empty string.
		 * @param int                 $quantity        Number of units.
		 * @param int                 $unit_price_minor Unit price in minor currency units.
		 * @param int                 $total_minor     Line total in minor currency units.
		 * @param array<string,mixed> $tax_info        Plain WooCommerce tax metadata.
		 */
		public function __construct( string $name, string $sku, int $quantity, int $unit_price_minor, int $total_minor, array $tax_info = [] ) {
			$this->name             = $name;
			$this->sku              = $sku;
			$this->quantity         = $quantity;
			$this->unit_price_minor = $unit_price_minor;
			$this->total_minor      = $total_minor;
			$this->tax_info         = $tax_info;
		}

		/**
		 * Gets the product name.
		 *
		 * @since 2.0.2
		 * @return string
		 */
		public function get_name(): string {
			return $this->name;
		}

		/**
		 * Gets the product SKU.
		 *
		 * @since 2.0.2
		 * @return string
		 */
		public function get_sku(): string {
			return $this->sku;
		}

		/**
		 * Gets the line quantity.
		 *
		 * @since 2.0.2
		 * @return int
		 */
		public function get_quantity(): int {
			return $this->quantity;
		}

		/**
		 * Gets the unit price in minor currency units.
		 *
		 * @since 2.0.2
		 * @return int
		 */
		public function get_unit_price_minor(): int {
			return $this->unit_price_minor;
		}

		/**
		 * Gets the line total in minor currency units.
		 *
		 * @since 2.0.2
		 * @return int
		 */
		public function get_total_minor(): int {
			return $this->total_minor;
		}

		/**
		 * Gets plain tax metadata for the carrier integration to map.
		 *
		 * @since 2.0.2
		 * @return array<string,mixed>
		 */
		public function get_tax_info(): array {
			return $this->tax_info;
		}

		/**
		 * Returns a copy with adjusted quantity and pricing.
		 *
		 * @since 2.0.2
		 *
		 * @param int $quantity        Number of units.
		 * @param int $unit_price_minor Unit price in minor currency units.
		 * @param int $total_minor     Line total in minor currency units.
		 * @return self
		 */
		public function with_pricing( int $quantity, int $unit_price_minor, int $total_minor ): self {
			return new self( $this->name, $this->sku, $quantity, $unit_price_minor, $total_minor, $this->tax_info );
		}
	}

endif;
