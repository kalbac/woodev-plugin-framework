<?php
/**
 * Shipment status email plain-text content.
 *
 * @package Woodev\Framework\Shipping
 * @var string $heading
 * @var string $body
 */
defined( 'ABSPATH' ) || exit;
echo wptexturize( $heading ) . "\n\n";
echo wptexturize( $body ) . "\n";
