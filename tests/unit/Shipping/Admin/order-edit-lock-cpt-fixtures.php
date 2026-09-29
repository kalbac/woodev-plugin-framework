<?php
/**
 * Test-only stand-ins for WordPress's legacy CPT post-lock API (#982).
 *
 * @package WoodevTests\Unit\Shipping\Admin
 */

if ( ! function_exists( 'wp_check_post_lock' ) ) {

	/** @return int|false the fake lock owner's id, or false. */
	function wp_check_post_lock( int $post_id ) {
		$lock = \Automattic\WooCommerce\Internal\Admin\Orders\EditLock::$locks[ $post_id ] ?? false;

		return is_array( $lock ) ? (int) $lock['user_id'] : false;
	}
}

if ( ! function_exists( 'wp_set_post_lock' ) ) {

	/** @return array{0:int,1:int} the fake current manager's refreshed lock. */
	function wp_set_post_lock( int $post_id ): array {
		return [ time(), 1 ];
	}
}
