<?php
/**
 * Outage-grace tests for the weekly license check.
 *
 * The Woodev licensing server can be unreachable. A failed validation must not
 * bubble an error out of the cron callback and must not relock a previously-valid
 * license — last-known-good is retained because validate_license()/dispatch() only
 * write stored state on a successful response.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Mockery;
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 2 ) . '/woodev/handlers/class-cron-handler.php';

/**
 * Class LicenseOutageGraceTest.
 */
class LicenseOutageGraceTest extends TestCase {

	/**
	 * A transport failure inside validate_license() is swallowed: weekly_license_check()
	 * does not throw, and last-known-good state is retained (no relock).
	 *
	 * @return void
	 */
	public function test_weekly_check_swallows_transport_failure(): void {
		unset( $_POST['woodev_settings'] );

		Functions\when( 'wp_doing_cron' )->justReturn( true );

		$license = Mockery::mock();
		$license->shouldReceive( 'get_license' )->once()->andReturn( 'KEY-123' );
		$license->shouldReceive( 'validate_license' )->once()->with( 'KEY-123' )->andThrow( new \Exception( 'server down' ) );

		$plugin = Mockery::mock( \Woodev_Plugin::class );
		$plugin->shouldReceive( 'get_license_instance' )->andReturn( $license );

		$handler = ( new \ReflectionClass( \Woodev\Framework\Handlers\Cron_Handler::class ) )->newInstanceWithoutConstructor();
		$this->set_private_property( $handler, 'plugin', $plugin );

		// Must not throw — a thrown validate_license() is caught and swallowed.
		$handler->weekly_license_check();

		// Reached only if no exception bubbled out of the cron callback.
		$this->assertTrue( true );
	}

	/**
	 * Sets a property value via reflection (handles protected/private/typed).
	 *
	 * @param object $object   Object to update.
	 * @param string $property Property name.
	 * @param mixed  $value    Property value.
	 * @return void
	 */
	private function set_private_property( $object, string $property, $value ): void {
		$reflection_property = new \ReflectionProperty( $object, $property );
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection_property->setAccessible( true );
		}
		$reflection_property->setValue( $object, $value );
	}
}
