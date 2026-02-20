<?php

defined( 'ABSPATH' ) or exit;

if ( ! interface_exists( 'Woodev_Packer_Interface' ) ) :

interface Woodev_Packer_Interface {

	public function add_box( Woodev_Box_Packer_Box $box );

	public function add_item( Woodev_Box_Packer_Item $item );

	public function get_packages();

	public function get_items_cannot_pack();

	public function pack();
}

endif;
