<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Packer_Dispatcher' ) ) :

	/**
	 * Routes packing requests to the correct algorithm and returns a normalised result.
	 *
	 * Usage in plugin business logic:
	 *
	 *     $algorithm = $this->get_option( 'packing_algorithm' ); // e.g. 'virtual'
	 *     $items     = [
	 *         new Woodev_Packer_Input_Item( $length, $width, $height, $weight, $qty ),
	 *     ];
	 *     $result = Woodev_Packer_Dispatcher::pack( $algorithm, $items );
	 *     $data   = $result->to_array(); // standardised array
	 *
	 * @since 1.4.1
	 */
	class Woodev_Packer_Dispatcher {

		/**
		 * Pack all items into a single minimal virtual bounding box.
		 * Best for shipments where all items travel in one parcel.
		 *
		 * @since 1.4.1
		 */
		const ALGORITHM_VIRTUAL = 'virtual';

		/**
		 * Each item unit becomes its own package.
		 * Best for drop-shipping or per-item rate calculation.
		 *
		 * @since 1.4.1
		 */
		const ALGORITHM_SEPARATELY = 'separately';

		/**
		 * All items packed into one box, sized by summing one axis and taking the max of the other two.
		 * Best for small orders where items stack along a single dimension.
		 *
		 * @since 1.4.1
		 */
		const ALGORITHM_SINGLE = 'single';

		/**
		 * Run the named algorithm against the supplied items.
		 *
		 * @since  1.4.1
		 *
		 * @param  string                        $algorithm_id One of the ALGORITHM_* constants.
		 * @param  Woodev_Packer_Packable_Item[] $items        Item data. Must not be empty.
		 *
		 * @return Woodev_Packer_Result
		 *
		 * @throws Woodev_Packer_Exception If $items is empty or $algorithm_id is not registered.
		 */
		public static function pack( string $algorithm_id, array $items ): Woodev_Packer_Result {
			if ( empty( $items ) ) {
				throw new Woodev_Packer_Exception(
					__( 'No items to pack!', 'woodev-plugin-framework' )
				);
			}

			switch ( $algorithm_id ) {
				case self::ALGORITHM_VIRTUAL:
					return self::pack_virtual( $items );

				case self::ALGORITHM_SEPARATELY:
					return self::pack_separately( $items );

				case self::ALGORITHM_SINGLE:
					return self::pack_single( $items );

				default:
					throw new Woodev_Packer_Exception(
						sprintf(
							/* translators: %s: algorithm ID */
							__( 'Unknown packing algorithm: %s', 'woodev-plugin-framework' ),
							$algorithm_id
						)
					);
			}
		}

		/**
		 * Returns algorithm IDs mapped to localised labels for use in settings dropdowns.
		 *
		 * @since  1.4.1
		 * @return array<string, string>
		 */
		public static function get_algorithms(): array {
			return [
				self::ALGORITHM_VIRTUAL    => __( 'Virtual box (minimal size)', 'woodev-plugin-framework' ),
				self::ALGORITHM_SEPARATELY => __( 'Each item in a separate box', 'woodev-plugin-framework' ),
				self::ALGORITHM_SINGLE     => __( 'Single box (items stacked along one axis)', 'woodev-plugin-framework' ),
			];
		}

		// -----------------------------------------------------------------------
		// Private helpers
		// -----------------------------------------------------------------------

		/**
		 * Pack all items into one virtual bounding box.
		 *
		 * @param  Woodev_Packer_Packable_Item[] $items
		 * @return Woodev_Packer_Result
		 */
		private static function pack_virtual( array $items ): Woodev_Packer_Result {
			$packer = new Woodev_Packer_Virtual_Box();
			$units  = self::expand_to_units( $items );

			$total_weight = (float) array_sum(
				array_map( fn( Woodev_Packer_Item_Implementation $u ) => $u->get_weight(), $units )
			);

			foreach ( $units as $unit ) {
				$packer->add_item( $unit );
			}

			$packer->pack();

			$packages = [];
			foreach ( $packer->get_packages() as $packed_box ) {
				$box        = $packed_box->get_box();
				$packages[] = new Woodev_Packer_Package_Result(
					$box->get_length(),
					$box->get_width(),
					$box->get_height(),
					$total_weight,
					count( $units )
				);
			}

			return new Woodev_Packer_Result( self::ALGORITHM_VIRTUAL, $packages );
		}

		/**
		 * Each item unit becomes its own package.
		 *
		 * @param  Woodev_Packer_Packable_Item[] $items
		 * @return Woodev_Packer_Result
		 */
		private static function pack_separately( array $items ): Woodev_Packer_Result {
			$packages = [];

			foreach ( $items as $input ) {
				// Pass through Item_Implementation so dimensions are normalised (length >= width >= height).
				$unit = new Woodev_Packer_Item_Implementation(
					$input->get_length(),
					$input->get_width(),
					$input->get_height(),
					$input->get_weight()
				);

				for ( $q = 0; $q < $input->get_quantity(); $q++ ) {
					$packages[] = new Woodev_Packer_Package_Result(
						$unit->get_length(),
						$unit->get_width(),
						$unit->get_height(),
						$unit->get_weight(),
						1
					);
				}
			}

			return new Woodev_Packer_Result( self::ALGORITHM_SEPARATELY, $packages );
		}

		/**
		 * All items packed into a single box sized by Woodev_Packer_Single_Box.
		 *
		 * @param  Woodev_Packer_Packable_Item[] $items
		 * @return Woodev_Packer_Result
		 */
		private static function pack_single( array $items ): Woodev_Packer_Result {
			$packer = new Woodev_Packer_Single_Box( 'package' );
			$units  = self::expand_to_units( $items );

			$total_weight = (float) array_sum(
				array_map( fn( Woodev_Packer_Item_Implementation $u ) => $u->get_weight(), $units )
			);

			foreach ( $units as $unit ) {
				$packer->add_item( $unit );
			}

			$packer->pack();

			$packages = [];
			foreach ( $packer->get_packages() as $packed_box ) {
				$box        = $packed_box->get_box();
				$packages[] = new Woodev_Packer_Package_Result(
					$box->get_length(),
					$box->get_width(),
					$box->get_height(),
					$total_weight,
					count( $units )
				);
			}

			return new Woodev_Packer_Result( self::ALGORITHM_SINGLE, $packages );
		}

		/**
		 * Expands input items by quantity into individual Woodev_Packer_Item_Implementation instances.
		 *
		 * @param  Woodev_Packer_Packable_Item[] $items
		 * @return Woodev_Packer_Item_Implementation[]
		 */
		private static function expand_to_units( array $items ): array {
			$units = [];

			foreach ( $items as $input ) {
				for ( $q = 0; $q < $input->get_quantity(); $q++ ) {
					$units[] = new Woodev_Packer_Item_Implementation(
						$input->get_length(),
						$input->get_width(),
						$input->get_height(),
						$input->get_weight()
					);
				}
			}

			return $units;
		}
	}

endif;
