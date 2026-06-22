<?php
/**
 * Woodev_REST_V1_Registrar dedup-key tests.
 *
 * Guards the multi-plugin fix: a STATEFUL controller (one instance of the same
 * class per plugin, e.g. Woodev_REST_API_Setup) must register once PER KEY, not
 * once per class — otherwise a second plugin's instance is silently dropped and
 * its routes never register. The static guards are reset via reflection
 * (gotcha testing/reflection-setaccessible-version-guard).
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	require_once dirname( __DIR__, 2 ) . '/woodev/rest-api/class-rest-v1-registrar.php';

	if ( ! class_exists( 'Registrar_Key_Test_Controller' ) ) {

		/**
		 * Minimal controller stub (two instances share this class name).
		 */
		class Registrar_Key_Test_Controller {

			/**
			 * @return void
			 */
			public function register_routes(): void {}
		}
	}
}

namespace Woodev\Tests\Unit {

	use Brain\Monkey\Functions;

	/**
	 * Class RestV1RegistrarKeyTest.
	 */
	class RestV1RegistrarKeyTest extends TestCase {

		/**
		 * @return void
		 */
		protected function setUp(): void {
			parent::setUp();

			Functions\when( 'add_action' )->justReturn( true );
			$this->reset_registrar();
		}

		/**
		 * @return void
		 */
		protected function tearDown(): void {
			$this->reset_registrar();

			parent::tearDown();
		}

		/**
		 * Resets the registrar's private static state between tests.
		 *
		 * @return void
		 */
		private function reset_registrar(): void {
			$ref = new \ReflectionClass( \Woodev_REST_V1_Registrar::class );

			foreach ( [ 'controllers' => [], 'hooked' => false ] as $prop => $value ) {
				$property = $ref->getProperty( $prop );

				if ( PHP_VERSION_ID < 80100 ) {
					$property->setAccessible( true );
				}

				$property->setValue( null, $value );
			}
		}

		/**
		 * Reads the registrar's stored controllers.
		 *
		 * @return array<string,object>
		 */
		private function stored(): array {
			$property = ( new \ReflectionClass( \Woodev_REST_V1_Registrar::class ) )->getProperty( 'controllers' );

			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}

			return (array) $property->getValue();
		}

		/**
		 * Default key (class name) dedups two instances of the same class.
		 *
		 * @return void
		 */
		public function test_default_key_dedups_same_class(): void {
			\Woodev_REST_V1_Registrar::register_controller( new \Registrar_Key_Test_Controller() );
			\Woodev_REST_V1_Registrar::register_controller( new \Registrar_Key_Test_Controller() );

			$this->assertCount( 1, $this->stored() );
		}

		/**
		 * Distinct keys register two instances of the SAME class (the multi-plugin
		 * setup-wizard case).
		 *
		 * @return void
		 */
		public function test_distinct_keys_register_same_class_twice(): void {
			\Woodev_REST_V1_Registrar::register_controller( new \Registrar_Key_Test_Controller(), 'Setup_plugin_a' );
			\Woodev_REST_V1_Registrar::register_controller( new \Registrar_Key_Test_Controller(), 'Setup_plugin_b' );

			$this->assertCount( 2, $this->stored() );
		}
	}
}
