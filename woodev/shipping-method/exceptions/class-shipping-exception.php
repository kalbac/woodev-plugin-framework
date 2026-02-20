<?php
/**
 * Woodev Shipping Exception
 *
 * Generic shipping module exception for handling shipping-related errors
 * such as rate calculation failures, order export errors, webhook validation
 * failures, and API communication issues.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Shipping_Exception' ) ) :

	class Shipping_Exception extends \Woodev_Plugin_Exception {}

endif;
