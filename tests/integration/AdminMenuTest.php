<?php
/**
 * Admin menu integration tests — top-level «Woodev» parent menu registration.
 *
 * Regression guard for the class-map autoload defect: load_admin_pages() used
 * `! class_exists( 'Woodev_Admin_Pages' )` as a fleet-once signal, but the s27
 * runtime class-map autoloader resolves that class on demand, so class_exists()
 * (autoload on) always returned true and Woodev_Admin_Pages::instance() was never
 * called — the top-level «Woodev» menu (parent of Licenses / Плагины / Настройки)
 * was never registered. Composer preloads the class in tests too, so this is the
 * exact production condition.
 *
 * @package Woodev\Tests\Integration
 */

namespace Woodev\Tests\Integration;

class AdminMenuTest extends TestCase {

	/**
	 * Finds the Woodev_Admin_Pages instance hooked onto admin_menu, if any.
	 *
	 * @return \Woodev_Admin_Pages|null
	 */
	private function hooked_admin_pages() {
		global $wp_filter;

		if ( empty( $wp_filter['admin_menu'] ) ) {
			return null;
		}

		foreach ( $wp_filter['admin_menu']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$fn = $callback['function'];
				if ( is_array( $fn ) && isset( $fn[0] ) && $fn[0] instanceof \Woodev_Admin_Pages ) {
					return $fn[0];
				}
			}
		}

		return null;
	}

	/**
	 * Removes any top-level «woodev» entry from the accumulating $menu global.
	 */
	private function clear_woodev_top_menu(): void {
		global $menu;

		if ( ! is_array( $menu ) ) {
			return;
		}

		foreach ( $menu as $i => $item ) {
			if ( isset( $item[2] ) && 'woodev' === $item[2] ) {
				unset( $menu[ $i ] );
			}
		}
	}

	public function test_load_admin_pages_registers_top_menu_when_class_already_loaded(): void {
		// Admin context so load_admin_pages() runs its body.
		set_current_screen( 'dashboard' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		// The bug bites because Woodev_Admin_Pages is AUTOLOADABLE (runtime class-map):
		// the guard's `! class_exists( 'Woodev_Admin_Pages' )` (autoload on) resolves the
		// class on demand → always true → instance() was never called. Asserting with
		// autoload on mirrors exactly what the buggy guard evaluates.
		$this->assertTrue( class_exists( 'Woodev_Admin_Pages' ), 'Precondition: the class is autoloadable, so the class_exists() guard short-circuits.' );

		// Reset the fleet-once flag so load_admin_pages() will run for this assertion.
		// Guarded with property_exists so the test fails (not errors) before the fix lands.
		if ( property_exists( \Woodev_Plugin::class, 'admin_pages_initialized' ) ) {
			$flag = new \ReflectionProperty( \Woodev_Plugin::class, 'admin_pages_initialized' );
			$flag->setAccessible( true );
			$flag->setValue( null, false );
		}

		$load = new \ReflectionMethod( \Woodev_Plugin::class, 'load_admin_pages' );
		$load->setAccessible( true );
		$load->invoke( woodev_test_plugin() );

		$admin_pages = $this->hooked_admin_pages();
		$this->assertNotNull(
			$admin_pages,
			'load_admin_pages() must wire Woodev_Admin_Pages onto admin_menu even when the class is already loaded.'
		);

		// And firing that callback must register the top-level «woodev» parent menu.
		$this->clear_woodev_top_menu();
		$admin_pages->admin_menu();

		global $menu;
		$slugs = array_column( (array) $menu, 2 );
		$this->assertContains( 'woodev', $slugs, 'The top-level «Woodev» menu must be registered.' );
	}
}
