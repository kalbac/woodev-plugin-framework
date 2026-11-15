<?php
/**
 * Minimal `WC_Product_Attribute` so that code which builds a product's own attributes loads without WooCommerce.
 *
 * Only declared when no other exists. It holds what the importer's group assembly sets and reads: the name, the values, and the
 * three flags.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

if ( ! class_exists( 'WC_Product_Attribute', false ) ) {
	// phpcs:disable Squiz.Commenting.ClassComment.Missing,Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.VariableComment.Missing -- A double of the WooCommerce class.
	class WC_Product_Attribute {
		private $data = [
			'name'      => '',
			'options'   => [],
			'position'  => 0,
			'visible'   => false,
			'variation' => false,
		];

		public function set_name( $value ) {
			$this->data['name'] = (string) $value;
		}

		public function get_name() {
			return $this->data['name'];
		}

		public function set_options( $value ) {
			$this->data['options'] = (array) $value;
		}

		public function get_options() {
			return $this->data['options'];
		}

		public function set_position( $value ) {
			$this->data['position'] = (int) $value;
		}

		public function get_position() {
			return $this->data['position'];
		}

		public function set_visible( $value ) {
			$this->data['visible'] = (bool) $value;
		}

		public function get_visible() {
			return $this->data['visible'];
		}

		public function set_variation( $value ) {
			$this->data['variation'] = (bool) $value;
		}

		public function get_variation() {
			return $this->data['variation'];
		}
	}
}
