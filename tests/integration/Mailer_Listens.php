<?php
/**
 * Makes sure the WooCommerce mailer of a test listens for its transactional e-mails.
 *
 * @package Woodev\Tests\Integration
 */

namespace Woodev\Tests\Integration;

/**
 * Shared by the integration tests that count the e-mails WooCommerce sends. Moved out of `OrderEditorDatastoresTest` (#981) when
 * the history import's no-mail test (#1925) needed it too: without it a test sees 0 e-mails on WC 8.5.1 and 9.3.0 whatever the code
 * under test does. The using class is a `WP_UnitTestCase`.
 */
trait Mailer_Listens {
	/**
	 * Makes sure THIS test's WooCommerce mailer is listening for its transactional e-mails, and
	 * asserts it rather than assuming it.
	 *
	 * WooCommerce's e-mail objects register their `…_notification` listeners in their constructors,
	 * i.e. when the mailer singleton is FIRST built. WP_UnitTestCase restores the hook table after
	 * every test, so once the singleton was built inside some earlier test's scope its listeners are
	 * gone for the rest of the process while `WC()->mailer()` stays a no-op — every «New order» after
	 * that is a `_notification` action nobody hears (0 e-mails on WC 8.5.1 / 9.3.0 in CI; WC 11
	 * builds the mailer during bootstrap, so its listeners sit in the backup and survive; traced in
	 * docs-internal/research/2026-09-28-981-new-order-email-trace/).
	 *
	 * The repair, `WC_Emails::init()`, rebuilds every e-mail object and hooks the NEW ones; it never
	 * unhooks the old ones. So it runs only when the mailer's own «New order» listener is missing,
	 * every callback the OLD objects still hold anywhere is removed first, and afterwards the two
	 * e-mails this file counts are asserted to be hooked exactly once — a partially restored hook
	 * table can neither silence them nor double-send them unnoticed (critic, round 2). WP_UnitTestCase
	 * drops whatever this adds once the test ends.
	 *
	 * @return void
	 */
	private function ensure_mailer_listens(): void {
		$mailer = \WC()->mailer();
		$hook   = 'woocommerce_order_status_pending_to_processing_notification';

		if ( ! isset( $mailer->emails['WC_Email_New_Order'] ) || false === has_action( $hook, [ $mailer->emails['WC_Email_New_Order'], 'trigger' ] ) ) {
			foreach ( $mailer->emails as $stale ) {
				$this->unhook_object( $stale );
			}

			$mailer->init();
		}

		foreach ( [ 'WC_Email_New_Order', 'WC_Email_Customer_Processing_Order' ] as $class ) {
			$this->assertSame( 1, $this->count_listeners( $hook, $class, 'trigger' ), "{$class} listens for pending→processing exactly once" );
		}
	}

	/**
	 * Removes every callback an object holds, on any hook at any priority.
	 *
	 * @param object $subject the object whose callbacks go.
	 * @return void
	 */
	private function unhook_object( object $subject ): void {
		foreach ( $GLOBALS['wp_filter'] as $hook => $wp_hook ) {
			foreach ( $wp_hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback['function'] ) && isset( $callback['function'][0] ) && $callback['function'][0] === $subject ) {
						remove_filter( (string) $hook, $callback['function'], (int) $priority );
					}
				}
			}
		}
	}

	/**
	 * How many `[ <instance of $class>, $method ]` callbacks sit on a hook.
	 *
	 * @param string $hook   the hook.
	 * @param string $class  class of the callback's object.
	 * @param string $method the callback's method.
	 * @return int
	 */
	private function count_listeners( string $hook, string $class, string $method ): int {
		$count = 0;

		foreach ( ( $GLOBALS['wp_filter'][ $hook ]->callbacks ?? [] ) as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && isset( $callback['function'][0] ) && $callback['function'][0] instanceof $class && $method === $callback['function'][1] ) {
					++$count;
				}
			}
		}

		return $count;
	}
}
