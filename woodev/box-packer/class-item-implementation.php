<?php

defined( 'ABSPATH' ) or exit;

if ( ! class_exists( 'Woodev_Packer_Item_Implementation' ) ) :

	class Woodev_Packer_Item_Implementation implements Woodev_Box_Packer_Item {

		/** @var string */
		private $name;
		/** @var float */
		private $weight;
		/** @var float */
		private $height;
		/** @var float */
		private $width;
		/** @var float */
		private $length;
		/** @var float */
		private $volume;
		/** @var float */
		private $value;
		/** @var mixed */
		private $internal_data;
		/** @var WC_Product|null */
		private $product;

		/**
		 * Woodev_Packer_Item_Implementation constructor.
		 *
		 * @param float      $length        .
		 * @param float      $width         .
		 * @param float      $height        .
		 * @param float      $weight        .
		 * @param float      $money_value   Item money value.
		 * @param null|mixed $internal_data .
		 */
		public function __construct( float $length, float $width, float $height, float $weight = 0.0, float $money_value = 0.0, $internal_data = null ) {
			$dimensions = array( $length, $width, $height );
			sort( $dimensions );
			$this->length        = ( float ) $dimensions[2];
			$this->width         = ( float ) $dimensions[1];
			$this->height        = ( float ) $dimensions[0];
			$this->volume        = ( float ) ( $width * $height * $length );
			$this->weight        = ( float ) $weight;
			$this->value         = ( float ) $money_value;
			$this->internal_data = $internal_data;

			if ( is_array( $this->internal_data ) && isset( $this->internal_data['name'] ) ) {
				$this->name = $this->internal_data['name'];
			}
		}

		/**
		 * @param WC_Product $product
		 *
		 * @return void
		 */
		public function set_product( WC_Product $product ): void {
			$this->product = $product;
		}

		/**
		 * @return bool
		 */
		public function has_name(): bool {
			return ! empty( $this->name );
		}

		/**
		 * @return WC_Product|null
		 */
		public function get_product(): ?WC_Product {
			return $this->product;
		}

		/**
		 * @return string
		 */
		public function get_name(): string {

			if ( $this->get_product() ) {
				$this->name = $this->get_product()->get_name();
			}

			return $this->name;
		}

		/**
		 * @return float
		 */
		public function get_volume(): float {
			return $this->volume;
		}

		/**
		 * @return float
		 */
		public function get_height(): float {
			return $this->height;
		}

		/**
		 * @return float
		 */
		public function get_width(): float {
			return $this->width;
		}

		/**
		 * @return float
		 */
		public function get_length(): float {
			return $this->length;
		}

		/**
		 * @return float
		 */
		public function get_weight(): float {
			return $this->weight;
		}

		/**
		 * @return float
		 */
		public function get_value(): float {
			return $this->value;
		}

		public function get_internal_data() {
			return $this->internal_data;
		}
	}

endif;
