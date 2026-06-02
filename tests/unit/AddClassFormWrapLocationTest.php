<?php
/**
 * Verifies the M-4 fix: add_class_form_wrap_start() / add_class_form_wrap_end()
 * live on Woodev_Woocommerce_Plugin, and Woodev_Plugin keeps only a deprecated
 * shim that delegates when the instance is a WC plugin.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	require_once dirname( __DIR__, 2 ) . '/woodev/class-woocommerce-plugin.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/class-woocommerce-plugin-alias.php';

}

namespace Woodev\Tests\Unit {

	use Brain\Monkey\Actions;
	use Brain\Monkey\Filters;
	use Brain\Monkey\Functions;

	/**
	 * Concrete WC plugin used to verify the WC class method takes precedence
	 * over the base shim.
	 */
	class ClassFormWrapShimWcPlugin extends \Woodev_Woocommerce_Plugin {
		public function get_file(): string {
			return __FILE__;
		}

		public function get_plugin_name(): string {
			return 'WC Shim Plugin';
		}

		public function get_download_id(): string {
			return '0';
		}
	}

	/**
	 * Concrete non-WC plugin used to exercise the base shim no-op path.
	 */
	class ClassFormWrapShimPurePlugin extends \Woodev_Plugin {
		public function get_file(): string {
			return __FILE__;
		}

		public function get_plugin_name(): string {
			return 'Pure WP Plugin';
		}

		public function get_download_id(): string {
			return '0';
		}
	}

	class AddClassFormWrapLocationTest extends TestCase {

		/**
		 * Both wrap methods must be declared on Woodev_Woocommerce_Plugin,
		 * not inherited from Woodev_Plugin. This proves the WC-specific
		 * output has actually moved off the platform-neutral base class.
		 */
		public function test_woocommerce_plugin_declares_wrap_methods(): void {
			$reflection = new \ReflectionClass( \Woodev_Woocommerce_Plugin::class );

			$this->assertTrue( $reflection->hasMethod( 'add_class_form_wrap_start' ) );
			$this->assertTrue( $reflection->hasMethod( 'add_class_form_wrap_end' ) );
			$this->assertSame(
				\Woodev\Framework\Woocommerce_Plugin::class,
				$reflection->getMethod( 'add_class_form_wrap_start' )->getDeclaringClass()->getName(),
				'add_class_form_wrap_start() must be declared on Woodev_Woocommerce_Plugin.'
			);
			$this->assertSame(
				\Woodev\Framework\Woocommerce_Plugin::class,
				$reflection->getMethod( 'add_class_form_wrap_end' )->getDeclaringClass()->getName(),
				'add_class_form_wrap_end() must be declared on Woodev_Woocommerce_Plugin.'
			);
		}

		/**
		 * The base-class shim must emit a deprecation warning when invoked.
		 * On a WC plugin instance PHP resolves the call to the WC class method
		 * directly (no deprecation, no shim). On a non-WC plugin instance the
		 * shim fires — exercises the deprecation path without recursing into
		 * the WC class.
		 *
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_base_shim_emits_deprecation_on_non_wc_plugin(): void {
			Functions\when( 'is_admin' )->justReturn( false );
			Functions\when( 'get_option' )->justReturn( [] );

			$pure_plugin = ( new \ReflectionClass( ClassFormWrapShimPurePlugin::class ) )
				->newInstanceWithoutConstructor();

			Functions\expect( '_deprecated_function' )
				->once()
				->with(
					\Woodev_Plugin::class . '::add_class_form_wrap_start',
					'2.0.0',
					\Woodev\Framework\Woocommerce_Plugin::class . '::add_class_form_wrap_start()'
				);

			ob_start();
			$pure_plugin->add_class_form_wrap_start();
			$output = ob_get_clean();

			$this->assertSame( '', $output, 'Shim on a non-WC plugin must not echo.' );
		}

		/**
		 * A WC plugin instance calling add_class_form_wrap_end() resolves
		 * to the WC class method (no shim, no deprecation). The method must
		 * not echo because we are not on the plugin settings page.
		 *
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_wc_plugin_calls_wc_class_method_directly(): void {
			Functions\when( 'is_admin' )->justReturn( false );
			Functions\when( 'get_option' )->justReturn( [] );

			$wc_plugin = ( new \ReflectionClass( ClassFormWrapShimWcPlugin::class ) )
				->newInstanceWithoutConstructor();

			// No _deprecated_function expectation: WC class method takes precedence.

			ob_start();
			$wc_plugin->add_class_form_wrap_end();
			$output = ob_get_clean();

			$this->assertSame( '', $output );
		}
	}
}
