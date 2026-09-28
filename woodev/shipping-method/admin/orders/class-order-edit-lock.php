<?php
/**
 * Shipping orders — native order edit lock seam
 *
 * @since 2.0.2
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Admin\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Admin\\Orders\\Order_Edit_Lock' ) ) :

	/**
	 * Bridges the native edit lock of whichever WooCommerce order datastore is active.
	 *
	 * HPOS orders use WooCommerce's `EditLock`; the legacy post datastore instead uses
	 * WordPress's post lock, because WooCommerce deliberately keeps `_edit_lock` out of
	 * `WC_Order` meta there. Both native APIs use the same value format and expiry window.
	 *
	 * @since 2.0.2
	 */
	class Order_Edit_Lock {

		/**
		 * Returns the other manager holding this order's live native edit lock.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order to inspect.
		 * @return array{user_id:int,display_name:string}|null the other lock owner, or null.
		 */
		public static function get_owner( \WC_Order $order ): ?array {
			if ( ! self::is_locked_by_another_user( $order ) ) {
				return null;
			}

			$user_id = self::is_hpos_enabled()
				? self::hpos_lock_owner_id( $order )
				: self::cpt_lock_owner_id( $order );
			$user    = $user_id > 0 ? get_user_by( 'id', $user_id ) : false;

			if ( ! $user ) {
				return null;
			}

			return [
				'user_id'      => (int) $user->ID,
				'display_name' => (string) $user->display_name,
			];
		}

		/**
		 * Whether another manager holds this order's live native edit lock.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order to inspect.
		 * @return bool
		 */
		public static function is_locked_by_another_user( \WC_Order $order ): bool {
			if ( self::is_hpos_enabled() ) {
				$lock = self::hpos_edit_lock();

				return null !== $lock && $lock->is_locked_by_another_user( $order );
			}

			self::load_cpt_functions();

			return false !== wp_check_post_lock( $order->get_id() );
		}

		/**
		 * Takes the native edit lock for the current manager.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order to lock.
		 * @return bool whether the native backend accepted the lock.
		 */
		public static function lock( \WC_Order $order ): bool {
			if ( self::is_hpos_enabled() ) {
				$lock = self::hpos_edit_lock();

				return null !== $lock && false !== $lock->lock( $order );
			}

			self::load_cpt_functions();

			return false !== wp_set_post_lock( $order->get_id() );
		}

		/**
		 * Refreshes the current manager's native edit lock.
		 *
		 * Both WordPress's post-lock API and WooCommerce's HPOS API refresh by taking
		 * the current manager's lock again.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order to lock.
		 * @return bool whether the native backend accepted the refresh.
		 */
		public static function refresh( \WC_Order $order ): bool {
			return self::lock( $order );
		}

		/**
		 * Gets the active HPOS lock owner id.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order to inspect.
		 * @return int
		 */
		private static function hpos_lock_owner_id( \WC_Order $order ): int {
			$lock      = self::hpos_edit_lock();
			$lock_data = null !== $lock ? $lock->get_lock( $order ) : false;

			return is_array( $lock_data ) ? (int) ( $lock_data['user_id'] ?? 0 ) : 0;
		}

		/**
		 * Gets the active CPT lock owner id.
		 *
		 * `wp_check_post_lock()` has already validated the lock's expiry, user and owner.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order to inspect.
		 * @return int
		 */
		private static function cpt_lock_owner_id( \WC_Order $order ): int {
			self::load_cpt_functions();

			return (int) wp_check_post_lock( $order->get_id() );
		}

		/**
		 * Gets WooCommerce's shared HPOS edit-lock service when WooCommerce has loaded it.
		 *
		 * @since 2.0.2
		 *
		 * @return object|null WooCommerce's EditLock service, or null outside WooCommerce.
		 */
		private static function hpos_edit_lock(): ?object {
			$class = '\\Automattic\\WooCommerce\\Internal\\Admin\\Orders\\EditLock';

			return class_exists( $class ) ? new $class() : null;
		}

		/**
		 * Loads WordPress's post-lock API for a REST request.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		private static function load_cpt_functions(): void {
			if ( ! function_exists( 'wp_check_post_lock' ) || ! function_exists( 'wp_set_post_lock' ) ) {
				require_once ABSPATH . 'wp-admin/includes/post.php';
			}
		}

		/**
		 * Whether the active order datastore is HPOS.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		private static function is_hpos_enabled(): bool {
			return \Woodev_Plugin_Compatibility::is_hpos_enabled();
		}
	}

endif;
