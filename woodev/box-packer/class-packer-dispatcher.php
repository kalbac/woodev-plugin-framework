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
		 * Items packed into the merchant's own boxes ({@see Woodev_Packer_Boxes}), the smallest set of boxes
		 * that holds them. A unit that fits NO box — too big in one dimension, or heavier than any box allows —
		 * is never dropped: it travels in a parcel of its own, sized by the item itself, exactly as with
		 * {@see self::ALGORITHM_SEPARATELY}. With no boxes defined every unit is such a parcel.
		 *
		 * @since 2.0.2
		 */
		const ALGORITHM_BOXES = 'boxes';

		/**
		 * Run the named algorithm against the supplied items.
		 *
		 * @since  1.4.1
		 *
		 * @since  2.0.2 Optional `$boxes`, the box set of {@see self::ALGORITHM_BOXES} (#1138).
		 *
		 * @param  string                        $algorithm_id One of the ALGORITHM_* constants.
		 * @param  Woodev_Packer_Packable_Item[] $items        Item data. Must not be empty.
		 * @param  Woodev_Box_Packer_Box[]|null  $boxes        The boxes {@see self::ALGORITHM_BOXES} packs into, in
		 *                                                     the packer's cm / kg; any other algorithm ignores them.
		 *                                                     Null is no boxes — {@see Woodev_WC_Packer_Dispatcher::pack()}
		 *                                                     reads the store's own list when it is null.
		 *
		 * @param string                        $leftovers Single or separately for units that fit no box.
		 * @return Woodev_Packer_Result
		 *
		 * @throws Woodev_Packer_Exception If $items is empty or $algorithm_id is not registered.
		 *                                 Its message is a PLAIN English string, deliberately not
		 *                                 wrapped in `__()` — i18n rule 3 (`AGENTS.md` →
		 *                                 Conventions): a string that never reaches a screen needs
		 *                                 no catalogue entry. Measured on the only real consumer
		 *                                 (`plugins-reference/woocommerce-edostavka/includes/class-wc-edostavka-box-packer.php:49`),
		 *                                 which catches this and passes `getMessage()` straight to
		 *                                 `->log()`. The four `Woodev_Packer::pack()`
		 *                                 implementations follow the same rule and now say the
		 *                                 SAME thing — `class-packer-virtual-box.php` used to say
		 *                                 it in Russian alone (#567).
		 */
		public static function pack( string $algorithm_id, array $items, ?array $boxes = null, string $leftovers = self::ALGORITHM_SEPARATELY ): Woodev_Packer_Result {
			if ( empty( $items ) ) {
				throw new Woodev_Packer_Exception( 'No items to pack!' );
			}

			// the item's position is what a package reports for an item that carries no key of its own
			$items = array_values( $items );

			switch ( $algorithm_id ) {
				case self::ALGORITHM_VIRTUAL:
					return self::pack_virtual( $items );

				case self::ALGORITHM_SEPARATELY:
					return self::pack_separately( $items );

				case self::ALGORITHM_SINGLE:
					return self::pack_single( $items );

				case self::ALGORITHM_BOXES:
					return self::pack_boxes( $items, (array) $boxes, $leftovers );

				default:
					throw new Woodev_Packer_Exception(
						sprintf( 'Unknown packing algorithm: %s', $algorithm_id )
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
				self::ALGORITHM_BOXES      => __( 'Store packaging (items packed into the boxes set up in the store)', 'woodev-plugin-framework' ),
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
					count( $units ),
					self::allocate( $units, $items )
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

			foreach ( self::expand_to_units( $items ) as $unit ) {
				$packages[] = self::unit_package( $unit, $items );
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
					count( $units ),
					self::allocate( $units, $items )
				);
			}

			return new Woodev_Packer_Result( self::ALGORITHM_SINGLE, $packages );
		}

		/**
		 * Items packed into the merchant's boxes; what fits no box travels separately.
		 *
		 * The packed boxes come first, in the order {@see Woodev_Packer_Boxes} fills them, then one parcel per
		 * unit that fitted none. A box's package carries the box's own inner size and the weight of its items
		 * PLUS the box's own weight — what the carrier weighs; a box's max weight counts that gross figure too.
		 *
		 * @param  Woodev_Packer_Packable_Item[] $items
		 * @param  array                         $boxes anything that is not a Woodev_Box_Packer_Box is ignored
		 * @param string                        $leftovers Single or separately.
		 * @return Woodev_Packer_Result
		 */
		private static function pack_boxes( array $items, array $boxes, string $leftovers ): Woodev_Packer_Result {
			$boxes    = array_values(
				array_filter(
					$boxes,
					static function ( $box ): bool {
						return $box instanceof Woodev_Box_Packer_Box;
					}
				)
			);
			$units    = self::expand_to_units( $items );
			$loose    = $units;
			$packages = [];

			if ( [] !== $boxes ) {
				$packer = new Woodev_Packer_Boxes();

				foreach ( $boxes as $box ) {
					$packer->add_box( $box );
				}

				foreach ( $units as $unit ) {
					$packer->add_item( $unit );
				}

				$packer->pack();

				foreach ( $packer->get_packages() as $packed_box ) {
					$box    = $packed_box->get_box();
					$packed = $packed_box->get_packed_items();

					$packages[] = new Woodev_Packer_Package_Result(
						$box->get_length(),
						$box->get_width(),
						$box->get_height(),
						$packed_box->get_packed_weight(),
						count( $packed ),
						self::allocate( $packed, $items ),
						(string) $box->get_unique_id(),
						(string) $box->get_name(),
						is_array( $box->get_internal_data() ) ? $box->get_internal_data() : []
					);
				}

				$loose = $packer->get_items_cannot_pack();
			}

			if ( self::ALGORITHM_SINGLE === $leftovers && [] !== $loose ) {
				$packer = new Woodev_Packer_Single_Box( 'package' );
				foreach ( $loose as $unit ) {
					$packer->add_item( $unit );
				}
				$packer->pack();
				$box = $packer->get_packages()[0]->get_box();
				$packages[] = new Woodev_Packer_Package_Result(
					$box->get_length(),
					$box->get_width(),
					$box->get_height(),
					(float) array_sum( array_map( static fn( $unit ) => $unit->get_weight(), $loose ) ),
					count( $loose ),
					self::allocate( $loose, $items )
				);
				$loose = [];
			}

			foreach ( $loose as $unit ) {
				$packages[] = self::unit_package( $unit, $items );
			}

			return new Woodev_Packer_Result( self::ALGORITHM_BOXES, $packages );
		}

		/**
		 * One unit in a parcel of its own, sized by the unit itself.
		 *
		 * @param  Woodev_Packer_Item_Implementation $unit
		 * @param  Woodev_Packer_Packable_Item[]     $items the input list the unit came from
		 * @return Woodev_Packer_Package_Result
		 */
		private static function unit_package( Woodev_Packer_Item_Implementation $unit, array $items ): Woodev_Packer_Package_Result {
			return new Woodev_Packer_Package_Result(
				$unit->get_length(),
				$unit->get_width(),
				$unit->get_height(),
				$unit->get_weight(),
				1,
				self::allocate( [ $unit ], $items )
			);
		}

		/**
		 * Tells which input items a set of units came from: one entry per input item, with the number of
		 * its units in the set. The unit carries its source's position in `$items` as its internal data
		 * ({@see self::expand_to_units()}).
		 *
		 * @param  Woodev_Box_Packer_Item[]      $units
		 * @param  Woodev_Packer_Packable_Item[] $items the input list (re-indexed from 0)
		 * @return array<int, array{key: string, product_id: int, quantity: int}>
		 */
		private static function allocate( array $units, array $items ): array {
			$quantities = [];

			foreach ( $units as $unit ) {
				$index                = (int) $unit->get_internal_data();
				$quantities[ $index ] = ( $quantities[ $index ] ?? 0 ) + 1;
			}

			$allocation = [];

			foreach ( $quantities as $index => $quantity ) {
				$input = $items[ $index ] ?? null;

				$allocation[] = [
					'key'        => $input instanceof Woodev_Packer_Input_Item && '' !== $input->get_key() ? $input->get_key() : (string) $index,
					'product_id' => $input instanceof Woodev_Packer_Input_Item ? $input->get_product_id() : 0,
					'quantity'   => $quantity,
				];
			}

			return $allocation;
		}

		/**
		 * Expands input items by quantity into individual Woodev_Packer_Item_Implementation instances.
		 *
		 * Each unit's internal data is the position of the input item it came from — what
		 * {@see self::allocate()} reads back.
		 *
		 * @param  Woodev_Packer_Packable_Item[] $items
		 * @return Woodev_Packer_Item_Implementation[]
		 */
		private static function expand_to_units( array $items ): array {
			$units = [];

			foreach ( $items as $index => $input ) {
				$quantity = $input->get_quantity();

				for ( $q = 0; $q < $quantity; $q++ ) {
					// Item_Implementation normalises the dimensions (length >= width >= height).
					$units[] = new Woodev_Packer_Item_Implementation(
						$input->get_length(),
						$input->get_width(),
						$input->get_height(),
						$input->get_weight(),
						0.0,
						$index
					);
				}
			}

			return $units;
		}
	}

endif;
