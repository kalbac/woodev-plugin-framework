<?php

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'Woodev_Box_Packer_Item_With_Product' ) ) :

	/**
	 * WooCommerce Box Packer Item With Product
	 *
	 * Extends the base Woodev_Box_Packer_Item contract with a get_product() method
	 * so that packers that need per-item product metadata (like Packer_Separately)
	 * can rely on a type-safe contract.
	 *
	 * Plugins that implement the base Woodev_Box_Packer_Item continue to work in
	 * all packers EXCEPT Packer_Separately (and any future packer that requires
	 * product access). Plugins that want their custom items to be packable by
	 * Packer_Separately must additionally implement this interface.
	 *
	 * @since 2.0.0
	 */
	interface Woodev_Box_Packer_Item_With_Product extends Woodev_Box_Packer_Item {

		/**
		 * Returns the WooCommerce product associated with this item, or null.
		 *
		 * @return \WC_Product|null the product, or null if no product is associated
		 */
		public function get_product();
	}

endif;
