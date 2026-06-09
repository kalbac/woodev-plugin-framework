<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Packer_Package_Result' ) ) :

	/**
	 * Represents a single physical package produced by Woodev_Packer_Dispatcher.
	 *
	 * @since 1.4.1
	 */
	final class Woodev_Packer_Package_Result {

		/** @var float */
		private $length;
		/** @var float */
		private $width;
		/** @var float */
		private $height;
		/** @var float */
		private $weight;
		/** @var int */
		private $item_count;

		/**
		 * @since  1.4.1
		 * @param  float $length     Package length in cm.
		 * @param  float $width      Package width in cm.
		 * @param  float $height     Package height in cm.
		 * @param  float $weight     Total weight of packed items in kg.
		 * @param  int   $item_count Number of item units in this package.
		 */
		public function __construct(
			float $length,
			float $width,
			float $height,
			float $weight,
			int $item_count
		) {
			$this->length     = $length;
			$this->width      = $width;
			$this->height     = $height;
			$this->weight     = $weight;
			$this->item_count = $item_count;
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
		public function get_item_count(): int {
			return $this->item_count;
		}

		/**
		 * @since  1.4.1
		 * @return float
		 */
		public function get_volume(): float {
			return $this->length * $this->width * $this->height;
		}

		/**
		 * Returns the package data as a plain array.
		 *
		 * @since  1.4.1
		 * @return array{length: float, width: float, height: float, weight: float, volume: float, item_count: int}
		 */
		public function to_array(): array {
			return [
				'length'     => $this->length,
				'width'      => $this->width,
				'height'     => $this->height,
				'weight'     => $this->weight,
				'volume'     => $this->get_volume(),
				'item_count' => $this->item_count,
			];
		}
	}

endif;
