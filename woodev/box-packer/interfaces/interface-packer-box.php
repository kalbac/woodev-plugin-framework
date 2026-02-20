<?php

defined( 'ABSPATH' ) or exit;

if ( ! interface_exists( 'Woodev_Box_Packer_Box' ) ) :

interface Woodev_Box_Packer_Box extends Woodev_Box_Packer_Item {
	/** @return float|null */
	public function get_max_weight();
	/** @return string */
	public function get_name();
	/** @return string */
	public function get_unique_id();
}

endif;
