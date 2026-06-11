<?php
/**
 * Outage-grace tests for the weekly license check.
 *
 * The Woodev licensing server can be unreachable. A failed validation must not
 * bubble an error out of the cron callback and must not relock a previously-valid
 * license — last-known-good is retained because validate_license()/dispatch() only
 * write stored state on a successful response. The §4 claim consumption must sit
 * strictly AFTER a successful dispatch: a transport throw never touches the claim
 * store (asserted against the REAL validate_license()/activate() bodies, not a
 * mocked engine — the cron test above mocks validate_license() and cannot see it).
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Mockery;
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 2 ) . '/woodev/handlers/class-cron-handler.php';
require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 2 ) . '/woodev/api/interface-api-request.php';
require_once dirname( __DIR__, 2 ) . '/woodev/api/abstract-api-json-request.php';
require_once dirname( __DIR__, 2 ) . '/woodev/api/class-api-base.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/api/class-licensing-api.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/api/class-licensing-api-request.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-store.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-messages.php';
require_once dirname( __DIR__, 2 ) . '/woodev/functions-license-authority.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-envelope-verifier.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-authority-claims.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-plugin-license.php';

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
	 * REAL validate_license(): a transport failure (make_request throws) must NEVER
	 * touch the §4 claim store — consumption sits strictly post-dispatch, so the
	 * stored last-known-good claim survives an outage (outage grace §3.2).
	 *
	 * @return void
	 */
	public function test_real_validate_license_transport_failure_never_touches_claims(): void {
		$license = $this->make_real_engine_with_throwing_transport();

		Functions\expect( 'home_url' )->andReturn( 'https://example.test' );
		// Belt-and-braces: the per-plugin claim option is never written either.
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'delete_option' )->never();

		// $ajax = false → the catch block swallows the transport exception silently.
		$license->validate_license( 'KEY-123' );

		// Reached without the claims mock's shouldNotReceive() tripping.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * REAL activate(): a transport failure propagates as an exception AND never
	 * touches the §4 claim store (consumption is post-dispatch only).
	 *
	 * @return void
	 */
	public function test_real_activate_transport_failure_never_touches_claims(): void {
		$license = $this->make_real_engine_with_throwing_transport();

		Functions\expect( 'sanitize_text_field' )->andReturnFirstArg();
		Functions\expect( 'home_url' )->andReturn( 'https://example.test' );
		// Exactly ONE write — the legacy-parity license_key option — and never the
		// claim option (claims mock would also trip on consume_from_response).
		Functions\expect( 'update_option' )->once()->with( 'woodev_test_plugin_license_key', 'KEY-123' );
		Functions\expect( 'delete_transient' )->never();

		$this->expectException( \Exception::class );

		$license->activate( 'KEY-123' );
	}

	/**
	 * Builds a REAL Woodev_Plugins_License whose api_handler throws on make_request,
	 * with a §4 claim-store double that fails the test on ANY consume_from_response
	 * call (get_verified() is allowed and reports no claim).
	 *
	 * @return \Woodev_Plugins_License
	 */
	private function make_real_engine_with_throwing_transport(): \Woodev_Plugins_License {
		$plugin = Mockery::mock();
		$plugin->shouldReceive( 'is_need_license' )->andReturn( true );
		$plugin->shouldReceive( 'get_download_id' )->andReturn( 216 );
		$plugin->shouldReceive( 'get_version' )->andReturn( '2.0.0' );
		$plugin->shouldReceive( 'get_plugin_name' )->andReturn( 'Test Plugin' );
		$plugin->shouldReceive( 'get_plugin_option_name' )->with( 'license_key' )->andReturn( 'woodev_test_plugin_license_key' );

		$woodev_license          = ( new \ReflectionClass( \Woodev_License::class ) )->newInstanceWithoutConstructor();
		$woodev_license->license = ''; // not 'valid' → activate() does not short-circuit pre-dispatch.

		$license = ( new \ReflectionClass( \Woodev_Plugins_License::class ) )->newInstanceWithoutConstructor();
		$this->set_private_property( $license, 'plugin', $plugin );
		$this->set_private_property( $license, 'license_key', 'KEY-123' );
		$this->set_private_property( $license, 'woodev_license', $woodev_license );
		$this->set_private_property( $license, 'item_name', 'Test Plugin' );

		// The transport throws — the §3.2 outage case.
		$api_handler = Mockery::mock();
		$api_handler->shouldReceive( 'make_request' )->andThrow( new \Exception( 'server down' ) );
		$this->set_private_property( $license, 'api_handler', $api_handler );

		// The claim store must NEVER be consumed on a failed dispatch. get_verified()
		// is allowed (activate()'s pre-dispatch is_license_valid() check reads it).
		$claims = Mockery::mock( \Woodev_License_Authority_Claims::class );
		$claims->shouldReceive( 'get_verified' )->andReturn( null );
		$claims->shouldNotReceive( 'consume_from_response' );
		$this->set_private_property( $license, 'authority_claims', $claims );

		return $license;
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
