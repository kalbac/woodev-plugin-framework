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
		/** @var array<int, array{key: string, product_id: int, quantity: int}> */
		private $items;
		/** @var string */
		private $box_id;
		/** @var string */
		private $box_name;

		/**
		 * @since  1.4.1
		 * @since  2.0.2 Optional `$items`, `$box_id` and `$box_name` (#1138).
		 *
		 * @param  float                                                          $length     Package length in cm.
		 * @param  float                                                          $width      Package width in cm.
		 * @param  float                                                          $height     Package height in cm.
		 * @param  float                                                          $weight     Total weight of the package in kg — the packed items, plus the box's own weight when the package is a merchant's box.
		 * @param  int                                                            $item_count Number of item units in this package.
		 * @param  array<int, array{key: string, product_id: int, quantity: int}> $items      Which input items went into this package, see {@see self::get_items()}.
		 * @param  string                                                         $box_id     Id of the merchant's box this package is, '' when it is not a box of the store's list.
		 * @param  string                                                         $box_name   Name of that box, '' when there is none.
		 */
		public function __construct(
			float $length,
			float $width,
			float $height,
			float $weight,
			int $item_count,
			array $items = [],
			string $box_id = '',
			string $box_name = ''
		) {
			$this->length     = $length;
			$this->width      = $width;
			$this->height     = $height;
			$this->weight     = $weight;
			$this->item_count = $item_count;
			$this->items      = $items;
			$this->box_id     = $box_id;
			$this->box_name   = $box_name;
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
		 * Which input items — and how many units of each — went into this package.
		 *
		 * One entry per input item that has at least one unit here: `key` is the item's own key
		 * ({@see Woodev_Packer_Input_Item::get_key()}: the cart-item key, or the order-item id when an
		 * order is packed; the item's position in the input list for an item that carries none),
		 * `product_id` the product (the variation, when there is one; 0 when unknown) and `quantity`
		 * the number of its units in this package. Across all packages of a result the quantities of
		 * one key add up to that item's quantity — no unit is dropped, whatever the algorithm.
		 *
		 * @since  2.0.2
		 * @return array<int, array{key: string, product_id: int, quantity: int}>
		 */
		public function get_items(): array {
			return $this->items;
		}

		/**
		 * Id of the merchant's box ({@see Woodev_Packer_Boxes}) this package is; '' for a package that
		 * is not one — a virtual / single / separate parcel, and a unit that fitted no box.
		 *
		 * @since  2.0.2
		 * @return string
		 */
		public function get_box_id(): string {
			return $this->box_id;
		}

		/**
		 * Name of the merchant's box, '' when {@see self::get_box_id()} is.
		 *
		 * @since  2.0.2
		 * @return string
		 */
		public function get_box_name(): string {
			return $this->box_name;
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
		 * @since  2.0.2 Adds `items`, `box_id` and `box_name`.
		 * @return array{length: float, width: float, height: float, weight: float, volume: float, item_count: int, items: array, box_id: string, box_name: string}
		 */
		public function to_array(): array {
			return [
				'length'     => $this->length,
				'width'      => $this->width,
				'height'     => $this->height,
				'weight'     => $this->weight,
				'volume'     => $this->get_volume(),
				'item_count' => $this->item_count,
				'items'      => $this->items,
				'box_id'     => $this->box_id,
				'box_name'   => $this->box_name,
			];
		}
	}

endif;
