<?php
/**
 * Verifies the licensing v2 split: Woodev_License_Settings moves to
 * Woodev_Woocommerce_License_Settings (WC-coupled), gated on
 * is_woocommerce_active(). The old class is retained as a deprecated
 * shim that emits _doing_it_wrong() when instantiated directly.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-woocommerce-license-settings.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-plugin-license-settings.php';

}

namespace Woodev\Tests\Unit {

	class WoocommerceLicenseSettingsLocationTest extends TestCase {

		/**
		 * The new class must be autoloadable via the classmap and must declare
		 * the three public methods that the licensing settings page needs.
		 *
		 * @return void
		 */
		public function test_woocommerce_license_settings_class_exists_and_declares_methods(): void {
			$this->assertTrue(
				class_exists( \Woodev_Woocommerce_License_Settings::class, false ),
				'Woodev_Woocommerce_License_Settings must be autoloadable from the classmap.'
			);

			$reflection = new \ReflectionClass( \Woodev_Woocommerce_License_Settings::class );

			$this->assertTrue(
				$reflection->hasMethod( 'set_wc_screen_ids' ),
				'Woodev_Woocommerce_License_Settings must declare set_wc_screen_ids().'
			);
			$this->assertSame(
				\Woodev_Woocommerce_License_Settings::class,
				$reflection->getMethod( 'set_wc_screen_ids' )->getDeclaringClass()->getName(),
				'set_wc_screen_ids() must be declared on Woodev_Woocommerce_License_Settings.'
			);

			$this->assertTrue(
				$reflection->hasMethod( 'register_license_settings' ),
				'Woodev_Woocommerce_License_Settings must declare register_license_settings().'
			);
			$this->assertTrue(
				$reflection->getMethod( 'register_license_settings' )->isPublic(),
				'register_license_settings() must remain public on the new class.'
			);

			$this->assertTrue(
				$reflection->hasMethod( 'do_license_fields' ),
				'Woodev_Woocommerce_License_Settings must declare do_license_fields().'
			);
			$this->assertTrue(
				$reflection->getMethod( 'do_license_fields' )->isPublic(),
				'do_license_fields() must remain public on the new class.'
			);
		}

		/**
		 * The Woodev_Plugin::load_license_settings_fields() loader must:
		 *  - gate the require_once on Woodev_Helper::is_woocommerce_active()
		 *  - instantiate the new Woodev_Woocommerce_License_Settings class
		 *
		 * Without the gate, pure-WP plugins would still register a callback on
		 * the woocommerce_screen_ids filter in is_admin() — defeating the v2 split.
		 *
		 * @return void
		 */
		public function test_loader_uses_new_fqcn_and_is_woocommerce_active_gate(): void {
			$source = file_get_contents( dirname( __DIR__, 2 ) . '/woodev/class-plugin.php' );

			$this->assertNotFalse( $source, 'class-plugin.php must be readable.' );

			// The new class must be loaded/used inside the load_license_settings_fields() method.
			$loader_pattern = '/function\s+load_license_settings_fields\s*\([^)]*\)\s*\{.*?Woodev_Woocommerce_License_Settings.*?\}/s';
			$this->assertSame(
				1,
				preg_match( $loader_pattern, $source ),
				'load_license_settings_fields() must reference Woodev_Woocommerce_License_Settings.'
			);

			// The loader must gate the require/new on is_woocommerce_active().
			$gate_pattern = '/function\s+load_license_settings_fields\s*\([^)]*\)\s*\{[^}]*is_woocommerce_active\s*\(\s*\)/s';
			$this->assertSame(
				1,
				preg_match( $gate_pattern, $source ),
				'load_license_settings_fields() must gate the WC-coupled load on Woodev_Helper::is_woocommerce_active().'
			);
		}

		/**
		 * The old Woodev_License_Settings class must still exist (backward compat
		 * for any dependent plugin that does class_exists() or instanceof checks),
		 * but its constructor must emit _doing_it_wrong() to flag the move.
		 *
		 * The framework's own loader no longer instantiates it; the shim exists
		 * only for type-compatibility.
		 *
		 * @return void
		 */
		public function test_old_class_is_deprecated_shim_with_doing_it_wrong(): void {
			$this->assertTrue(
				class_exists( \Woodev_License_Settings::class, false ),
				'Woodev_License_Settings must still be defined for backward compat.'
			);

			$source = file_get_contents( dirname( __DIR__, 2 ) . '/woodev/licensing/class-plugin-license-settings.php' );

			$this->assertNotFalse( $source, 'class-plugin-license-settings.php must be readable.' );

			// The constructor must call _doing_it_wrong() so any external
			// instantiation flags the migration to the new class.
			$constructor_pattern = '/function\s+__construct\s*\([^)]*\)\s*\{[^}]*_doing_it_wrong\s*\(/s';
			$this->assertSame(
				1,
				preg_match( $constructor_pattern, $source ),
				'Woodev_License_Settings::__construct() must call _doing_it_wrong() to mark the class as a deprecated shim.'
			);
		}
	}

}
