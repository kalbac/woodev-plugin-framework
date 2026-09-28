<?php
/**
 * Test-only stand-in for WooCommerce's EditLock service (#982).
 *
 * Unit tests do not load WooCommerce's internal classes. The production code checks that
 * class lazily, so this narrow double lets the unit suite exercise the shared-lock branch
 * without claiming a replacement implementation.
 *
 * @package WoodevTests\Unit\Shipping\Admin
 */

namespace Automattic\WooCommerce\Internal\Admin\Orders;

if ( ! class_exists( __NAMESPACE__ . '\\EditLock', false ) ) {

	final class EditLock {

		/** @var array<int,array{time:int,user_id:int}> */
		public static $locks = [];

		/** @param \WC_Order $order order to inspect. @return array{time:int,user_id:int}|false */
		public function get_lock( \WC_Order $order ) {
			return self::$locks[ $order->get_id() ] ?? false;
		}

		/** @param \WC_Order $order order to inspect. @return bool whether another user has the lock. */
		public function is_locked_by_another_user( \WC_Order $order ): bool {
			return false !== $this->get_lock( $order );
		}

		/** @param \WC_Order $order order to lock. @return string lock value. */
		public function lock( \WC_Order $order ): string {
			return 'locked';
		}
	}
}
