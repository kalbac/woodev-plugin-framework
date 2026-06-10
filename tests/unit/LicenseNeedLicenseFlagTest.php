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
	 * A license-free plugin's deactivate handler never reaches wp_die on a license-form submit.
	 *
	 * The shared license page still renders a "Save changes" button; pressing it posts
	 * option_page=woodev_license_fields_group with no per-plugin nonce. Without the
	 * is_need_license() guard, deactivate_license() would wp_die( 403 ) on the missing
	 * nonce. The guard makes it a clean no-op before the option_page/nonce checks.
	 *
	 * @return void
	 */
	public function test_deactivate_license_no_wp_die_when_license_not_needed(): void {
		$_POST['option_page'] = 'woodev_license_fields_group';

		Functions\expect( 'wp_die' )->never();

		$plugin = Mockery::mock();
		$plugin->shouldReceive( 'is_need_license' )->andReturn( false );
		$plugin->shouldNotReceive( 'get_id_dasherized' );

		$license = $this->make_license_for_plugin( $plugin, 'KEY-123', 'valid' );

		try {
			$license->deactivate_license();
		} finally {
			unset( $_POST['option_page'] );
		}

		$this->assertTrue( true );
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
