<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Woodev_Payment_Gateway_Exception' ) ) :

	/**
	 * Payment Gateway Exception - generic payment failure Exception
	 */
	class Woodev_Payment_Gateway_Exception extends Woodev_Plugin_Exception {}

endif;