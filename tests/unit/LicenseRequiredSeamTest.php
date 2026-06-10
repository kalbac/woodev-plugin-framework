<?php
/**
 * License enforcement seam tests.
 *
 * Covers the L2 authority method Woodev_Plugins_License::is_license_required()
 * and the byte-for-byte routing of is_license_valid()/is_active() through it
 * while the default remains true (no behavior change).
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Mockery;

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
 * Class LicenseRequiredSeamTest.
 */
class LicenseRequiredSeamTest extends TestCase {

	/**
	 * The enforcement seam defaults to true (license always required for now).
	 *
	 * @return void
	 */
	public function test_is_license_required_defaults_true(): void {
		$license = $this->make_license();

		$this->assertTrue( $license->is_license_required() );
	}

	/**
	 * is_license_valid() is byte-for-byte unchanged under the default-true seam.
	 *
	 * @return void
	 */
	public function test_is_license_valid_unchanged_when_license_required(): void {
		$this->assertTrue( $this->make_license_with_status( 'valid', 'KEY-123' )->is_license_valid() );
		$this->assertFalse( $this->make_license_with_status( 'expired', 'KEY-123' )->is_license_valid() );
	}

	/**
	 * An empty license key never validates, even with a 'valid' stored status.
	 *
	 * @return void
	 */
	public function test_is_license_valid_false_for_empty_key(): void {
		$this->assertFalse( $this->make_license_with_status( 'valid', '' )->is_license_valid() );
	}

	/**
	 * is_active() is byte-for-byte unchanged under the default-true seam.
	 *
	 * @return void
	 */
	public function test_is_active_unchanged_when_license_required(): void {
		$this->assertTrue( $this->make_license_with_status( 'valid', 'KEY-123' )->is_active() );
		$this->assertFalse( $this->make_license_with_status( 'expired', 'KEY-123' )->is_active() );
	}

	/**
	 * Builds a Woodev_Plugins_License without invoking its constructor.
	 *
	 * @return \Woodev_Plugins_License
	 */
	private function make_license(): \Woodev_Plugins_License {
		return ( new \ReflectionClass( \Woodev_Plugins_License::class ) )->newInstanceWithoutConstructor();
	}

	/**
	 * Builds a license engine with an injected Woodev_License stub at the given status and key.
	 *
	 * @param string $status      Stored license status (e.g. 'valid', 'expired').
	 * @param string $license_key Stored license key.
	 * @return \Woodev_Plugins_License
	 */
	private function make_license_with_status( string $status, string $license_key ): \Woodev_Plugins_License {
		$license = $this->make_license();

		$woodev_license          = ( new \ReflectionClass( \Woodev_License::class ) )->newInstanceWithoutConstructor();
		$woodev_license->license = $status;

		$this->set_private_property( $license, 'license_key', $license_key );
		$this->set_private_property( $license, 'woodev_license', $woodev_license );

		return $license;
	}

	/**
	 * Sets a private property value via reflection.
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
