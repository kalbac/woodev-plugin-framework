<?php

defined( 'ABSPATH' ) or exit;

if ( ! interface_exists( 'Woodev_Box_Packer_Item' ) ) :

interface Woodev_Box_Packer_Item {
	/** @return string */
	public function get_name();
	/** @return float */
	public function get_volume();
	/** @return float */
	public function get_height();
	/** @return float */
	public function get_width();
	/** @return float */
	public function get_length();
	/** @return float */
	public function get_weight();
	/** @return float */
	public function get_value();
	/** @return mixed */
	public function get_internal_data();
}

endif;
