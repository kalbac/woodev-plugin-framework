<?php
/**
 * Verifies the B-2 fix: get_woocommerce_uploads_path() lives on Woodev_Woocommerce_Plugin,
 * and Woodev_Plugin keeps only a deprecated shim that delegates.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	require_once dirname( __DIR__, 2 ) . '/woodev/class-woocommerce-plugin.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/class-woocommerce-plugin-alias.php';

}

namespace Woodev\Tests\Unit {

	use Brain\Monkey\Functions;

	class WoocommerceUploadsPathLocationTest extends TestCase {

		/**
		 * Mocked wp_upload_dir() basedir used to compute the expected path.
		 *
		 * @var string
		 */
		private $basedir = '/var/www/wp-content/uploads';

		/**
		 * @return void
		 */
		protected function setUp(): void {
			parent::setUp();

			Functions\stubs(
				[
					'wp_upload_dir' => static function () {
						return [ 'basedir' => '/var/www/wp-content/uploads' ];
					},
				]
			);
		}

		/**
		 * The canonical DECLARATION of get_woocommerce_uploads_path() must be on Woodev_Woocommerce_Plugin,
		 * not inherited from Woodev_Plugin. This proves the WC-specific helper has actually moved off
		 * the platform-neutral base class.
		 *
		 * @return void
		 */
		public function test_woocommerce_plugin_declares_method(): void {
			$reflection = new \ReflectionClass( \Woodev_Woocommerce_Plugin::class );
			$this->assertTrue(
				$reflection->hasMethod( 'get_woocommerce_uploads_path' ),
				'Woodev_Woocommerce_Plugin must declare get_woocommerce_uploads_path().'
			);
			$this->assertSame(
				\Woodev\Framework\Woocommerce_Plugin::class,
				$reflection->getMethod( 'get_woocommerce_uploads_path' )->getDeclaringClass()->getName(),
				'get_woocommerce_uploads_path() must be declared on Woodev_Woocommerce_Plugin, not inherited from Woodev_Plugin.'
			);
		}

		/**
		 * The canonical method on Woodev_Woocommerce_Plugin must return the WC uploads path.
		 *
		 * @return void
		 */
		public function test_woocommerce_plugin_method_returns_path(): void {
			$path = \Woodev\Framework\Woocommerce_Plugin::get_woocommerce_uploads_path();
			$this->assertSame( $this->basedir . '/woocommerce_uploads', $path );
		}

		/**
		 * The base-class shim must still return the correct path for backward compatibility
		 * with 10+ existing plugins that call Woodev_Plugin::get_woocommerce_uploads_path().
		 *
		 * @return void
		 */
		public function test_base_shim_still_returns_path(): void {
			Functions\expect( '_deprecated_function' )
				->once()
				->with( \Woodev_Plugin::class . '::get_woocommerce_uploads_path', '2.0.0', 'Woodev_Woocommerce_Plugin::get_woocommerce_uploads_path()' );

			$path = \Woodev_Plugin::get_woocommerce_uploads_path();
			$this->assertSame( $this->basedir . '/woocommerce_uploads', $path );
		}

		/**
		 * The shim must reference the FQCN \Woodev\Framework\Woocommerce_Plugin so that the
		 * `class_exists( ..., false )` branch actually delegates to the WC class.
		 *
		 * The bare short name `Woocommerce_Plugin::class` resolves to the global-namespace
		 * `\Woocommerce_Plugin` (which does not exist — the class lives under
		 * `Woodev\Framework\`), so a shim that uses the short name silently falls through
		 * to the inline `wp_upload_dir()` path. Since the WC class's method and the inline
		 * fallback return the same string, a return-value assertion cannot distinguish the
		 * two paths — we have to inspect the shim's source.
		 *
		 * @return void
		 */
		public function test_base_shim_uses_fqcn_for_woocommerce_plugin(): void {
			$source = file_get_contents( dirname( __DIR__, 2 ) . '/woodev/class-plugin.php' );

			$this->assertNotFalse( $source, 'class-plugin.php must be readable.' );

			$pattern = '/function\s+get_woocommerce_uploads_path\s*\(\s*\)\s*\{[^}]*class_exists\s*\(\s*\\\\?Woodev\\\\Framework\\\\Woocommerce_Plugin::class/s';

			$this->assertSame(
				1,
				preg_match( $pattern, $source ),
				'Woodev_Plugin::get_woocommerce_uploads_path() shim must reference the FQCN \\Woodev\\Framework\\Woocommerce_Plugin::class. A bare short name Woocommerce_Plugin::class resolves to the global namespace \\Woocommerce_Plugin (which does not exist), which causes the shim to always fall through to the inline wp_upload_dir() path.'
			);
		}
	}

}
