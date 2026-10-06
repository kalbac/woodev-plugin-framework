<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Packer_Input_Item' ) ) :

	/**
	 * Simple value object carrying item dimensions, weight and quantity
	 * for use with Woodev_Packer_Dispatcher.
	 *
	 * @since 1.4.1
	 */
	final class Woodev_Packer_Input_Item implements Woodev_Packer_Packable_Item {

		/** @var float */
		private $length;
		/** @var float */
		private $width;
		/** @var float */
		private $height;
		/** @var float */
		private $weight;
		/** @var int */
		private $quantity;
		/** @var string */
		private $key;
		/** @var int */
		private $product_id;

		/**
		 * @since  1.4.1
		 * @since  2.0.2 Optional `$key` and `$product_id` (#1138).
		 *
		 * @param  float  $length     Item length in cm.
		 * @param  float  $width      Item width in cm.
		 * @param  float  $height     Item height in cm.
		 * @param  float  $weight     Item weight in kg. Default 0.0.
		 * @param  int    $quantity   Number of units. Default 1.
		 * @param  string $key        The item's own key — a cart-item key or an order-item id — that
		 *                            {@see Woodev_Packer_Package_Result::get_items()} reports back. Default
		 *                            '' (the item's position in the input list is reported instead).
		 * @param  int    $product_id The product (the variation, when there is one). Default 0.
		 */
		public function __construct(
			float $length,
			float $width,
			float $height,
			float $weight = 0.0,
			int $quantity = 1,
			string $key = '',
			int $product_id = 0
		) {
			$this->length     = $length;
			$this->width      = $width;
			$this->height     = $height;
			$this->weight     = $weight;
			$this->quantity   = max( 1, $quantity );
			$this->key        = $key;
			$this->product_id = $product_id;
		}

		/**
		 * @since  2.0.2
		 * @return string the item's own key, '' when it has none
		 */
		public function get_key(): string {
			return $this->key;
		}

		/**
		 * @since  2.0.2
		 * @return int the product id (the variation's, when there is one), 0 when unknown
		 */
		public function get_product_id(): int {
			return $this->product_id;
		}

		/**
		 * @since  1.4.1
		 * @return float
		 */
		public function get_length(): float {
			return $this->length;
		}

		/**
		 * @since  1.4.1
		 * @return float
		 */
		public function get_width(): float {
			return $this->width;
		}

		/**
		 * @since  1.4.1
		 * @return float
		 */
		public function get_height(): float {
			return $this->height;
		}

		/**
		 * @since  1.4.1
		 * @return float
		 */
		public function get_weight(): float {
			return $this->weight;
		}

		/**
		 * @since  1.4.1
		 * @return int
		 */
		public function get_quantity(): int {
			return $this->quantity;
		}
	}

endif;
