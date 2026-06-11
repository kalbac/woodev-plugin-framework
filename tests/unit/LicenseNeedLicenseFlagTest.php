<?php
/**
 * is_need_license() presentation-flag tests.
 *
 * Covers the L1 presentation flag Woodev_Plugin::is_need_license() (default true,
 * override-able to false) and that a false flag suppresses the license nag in
 * Woodev_Plugins_License::notices() — while NEVER influencing the enforcement
 * authority (anti-pirate invariant: is_license_valid()/is_active() are unaffected).
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Mockery;
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';
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
 * Class LicenseNeedLicenseFlagTest.
 */
class LicenseNeedLicenseFlagTest extends TestCase {

	/**
	 * The base plugin requires a license by default.
	 *
	 * @return void
	 */
	public function test_default_is_need_license_true(): void {
		$this->assertTrue( ( new Need_License_Default_Plugin() )->is_need_license() );
	}

	/**
	 * A subclass may override the flag to declare itself license-free.
	 *
	 * @return void
	 */
	public function test_override_is_need_license_false(): void {
		$this->assertFalse( ( new Need_License_Free_Plugin() )->is_need_license() );
	}

	/**
	 * notices(): when the plugin does not need a license, no admin notice is added.
	 *
	 * @return void
	 */
	public function test_notices_suppressed_when_license_not_needed(): void {
		$handler = Mockery::mock();
		$handler->shouldNotReceive( 'add_admin_notice' );

		$plugin = Mockery::mock();
		$plugin->shouldReceive( 'is_need_license' )->andReturn( false );
		$plugin->shouldNotReceive( 'get_admin_notice_handler' );

		$license = $this->make_license_for_plugin( $plugin, 'KEY-123', 'expired' );

		$license->notices();
	}

	/**
	 * Anti-pirate invariant: a false is_need_license() flag never validates a license.
	 *
	 * The enforcement authority (is_license_required(), default true) is independent
	 * of the local presentation flag, so a non-valid status still yields the
	 * paid-license outcome even when the plugin declares itself license-free.
	 *
	 * @return void
	 */
	public function test_anti_pirate_flag_does_not_validate_license(): void {
		$plugin = Mockery::mock();
		$plugin->shouldReceive( 'is_need_license' )->andReturn( false );

		$license = $this->make_license_for_plugin( $plugin, 'KEY-123', 'expired' );

		$this->assertFalse( $license->is_license_valid() );
		$this->assertFalse( $license->is_active() );
	}

	/**
	 * A license-free plugin's deactivate() pure op is a clean no-op.
	 *
	 * The legacy admin_init deactivate_license() handler (which could wp_die on a
	 * missing form nonce) was deleted in 2.0.0; the transport-agnostic deactivate()
	 * replaces it. For a license-free product it must return the current state
	 * without dispatching to the store or deleting any stored license data
	 * (the no-op short-circuits before transport — anti-pirate presentation flag).
	 *
	 * @return void
	 */
	public function test_license_free_deactivate_is_noop(): void {
		$plugin = Mockery::mock();
		$plugin->shouldReceive( 'is_need_license' )->andReturn( false );
		$plugin->shouldReceive( 'is_beta_allowed' )->andReturn( false );
		$plugin->shouldReceive( 'get_download_id' )->andReturn( 0 );
		$plugin->shouldReceive( 'get_plugin_name' )->andReturn( 'Test Plugin' );

		Functions\expect( 'current_time' )->andReturn( 1000 );
		Functions\expect( 'delete_option' )->never();
		// wp_kses_post wraps the message in get_state(); passthrough here.
		Functions\when( 'wp_kses_post' )->returnArg();

		$woodev_license          = ( new \ReflectionClass( \Woodev_License::class ) )->newInstanceWithoutConstructor();
		$woodev_license->license = 'valid';
		$woodev_license->expires = 'lifetime';

		$license = $this->make_license_for_plugin( $plugin, 'KEY-123', 'valid' );
		$this->set_private_property( $license, 'woodev_license', $woodev_license );

		// The api_handler must never be touched on a license-free no-op.
		$api_handler = Mockery::mock();
		$api_handler->shouldNotReceive( 'make_request' );
		$this->set_private_property( $license, 'api_handler', $api_handler );

		$state = $license->deactivate();

		$this->assertIsArray( $state );
		$this->assertSame( 'valid', $state['status'] );
	}

	/**
	 * Builds a license engine bound to the given plugin stub, at a status and key.
	 *
	 * @param object $plugin      Plugin stub (provides is_need_license()).
	 * @param string $license_key Stored license key.
	 * @param string $status      Stored license status (e.g. 'valid', 'expired').
	 * @return \Woodev_Plugins_License
	 */
	private function make_license_for_plugin( $plugin, string $license_key, string $status ): \Woodev_Plugins_License {
		$license = ( new \ReflectionClass( \Woodev_Plugins_License::class ) )->newInstanceWithoutConstructor();

		$woodev_license          = ( new \ReflectionClass( \Woodev_License::class ) )->newInstanceWithoutConstructor();
		$woodev_license->license = $status;

		$this->set_private_property( $license, 'plugin', $plugin );
		$this->set_private_property( $license, 'license_key', $license_key );
		$this->set_private_property( $license, 'woodev_license', $woodev_license );

		// License REQUIRED (no verified license-free claim): the anti-pirate assertions
		// rely on is_license_valid()/is_active() routing through the status logic.
		$claims = Mockery::mock( \Woodev_License_Authority_Claims::class );
		$claims->shouldReceive( 'get_verified' )->andReturn( null );
		$this->set_private_property( $license, 'authority_claims', $claims );

		return $license;
	}

	/**
	 * Sets a property value via reflection (handles protected/private).
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

/**
 * Concrete plugin fixture that inherits the base is_need_license() default (true).
 *
 * The constructor is overridden to a no-op so the framework subsystem wiring in
 * Woodev_Plugin::__construct() does not run during unit tests.
 */
class Need_License_Default_Plugin extends \Woodev_Plugin {

	/**
	 * No-op constructor — skip framework bootstrap for unit tests.
	 */
	public function __construct() {
	}

	/**
	 * @return string
	 */
	public function get_file() {
		return __FILE__;
	}

	/**
	 * @return string
	 */
	public function get_plugin_name() {
		return 'Test Plugin';
	}

	/**
	 * @return int
	 */
	public function get_download_id() {
		return 0;
	}
}

/**
 * Concrete plugin fixture that overrides is_need_license() to declare itself license-free.
 */
class Need_License_Free_Plugin extends Need_License_Default_Plugin {

	/**
	 * Test override: this product ships without a license.
	 *
	 * @return bool
	 */
	public function is_need_license(): bool {
		return false;
	}
}
