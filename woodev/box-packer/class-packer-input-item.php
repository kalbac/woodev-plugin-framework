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

		/**
		 * @since  1.4.1
		 * @param  float $length   Item length in cm.
		 * @param  float $width    Item width in cm.
		 * @param  float $height   Item height in cm.
		 * @param  float $weight   Item weight in kg. Default 0.0.
		 * @param  int   $quantity Number of units. Default 1.
		 */
		public function __construct(
			float $length,
			float $width,
			float $height,
			float $weight = 0.0,
			int $quantity = 1
		) {
			$this->length   = $length;
			$this->width    = $width;
			$this->height   = $height;
			$this->weight   = $weight;
			$this->quantity = max( 1, $quantity );
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
