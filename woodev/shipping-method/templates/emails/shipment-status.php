<?php
/**
 * Shipment status email HTML content.
 *
 * @package Woodev\Framework\Shipping
 * @var WC_Email $email
 * @var WC_Order $order
 * @var string   $heading
 * @var string   $body
 */
defined( 'ABSPATH' ) || exit;
do_action( 'woocommerce_email_header', $heading, $email );
echo wpautop( wptexturize( $body ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
do_action( 'woocommerce_email_order_details', $order, false, false, $email );
do_action( 'woocommerce_email_footer', $email );
