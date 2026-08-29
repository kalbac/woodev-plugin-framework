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

namespace Woodev\Framework\Shipping\Exceptions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Exceptions\\Shipping_Exception' ) ) :

	class Shipping_Exception extends \Woodev_Plugin_Exception {}

endif;
