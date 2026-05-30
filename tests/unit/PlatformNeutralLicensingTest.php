<?php
/**
 * Platform-neutral licensing helper tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
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
	* Minimal Woodev plugin test double for licensing constructor type checks.
 */
class Testable_Platform_Neutral_Licensing_Plugin extends \Woodev_Plugin {

	/**
	 * Avoid parent construction for isolated helper tests.
	 */
	public function __construct() {}

	/**
	 * Gets the plugin file.
	 *
	 * @return string
	 */
	protected function get_file() {
		return __FILE__;
	}

	/**
	 * Gets the plugin name.
	 *
	 * @return string
	 */
	public function get_plugin_name() {
		return 'Platform Neutral Licensing Test Plugin';
	}

	/**
	 * Gets the download ID.
	 *
	 * @return int
	 */
	public function get_download_id() {
		return 0;
	}
}

/**
 * Class PlatformNeutralLicensingTest.
 */
class PlatformNeutralLicensingTest extends TestCase {

	/**
	 * Licensing action dispatch should stay case-insensitive without WooCommerce helpers.
	 *
	 * @return void
	 */
	public function test_license_dispatch_keeps_case_insensitive_action_validation(): void {
		$plugin      = Mockery::mock();
		$api_handler = Mockery::mock();
		$response    = (object) [ 'license' => 'valid' ];

		$plugin->shouldReceive( 'get_download_id' )->once()->andReturn( 42 );
		$plugin->shouldReceive( 'get_version' )->once()->andReturn( '2.2.0' );

		$api_handler->shouldReceive( 'make_request' )
			->once()
			->with(
				[
					'edd_action' => 'CHECK_LICENSE',
					'license'    => 'license-key',
					'item_id'    => 42,
					'url'        => 'https://example.test',
					'version'    => '2.2.0',
				]
			)
			->andReturn( $response );

		Functions\when( 'home_url' )->justReturn( 'https://example.test' );

		$license = ( new \ReflectionClass( \Woodev_Plugins_License::class ) )->newInstanceWithoutConstructor();

		$this->set_private_property( $license, 'plugin', $plugin );
		$this->set_private_property( $license, 'api_handler', $api_handler );

		$dispatch = new \ReflectionMethod( \Woodev_Plugins_License::class, 'dispatch' );

		$this->assertSame( $response, $dispatch->invoke( $license, 'CHECK_LICENSE', 'license-key' ) );
	}

	/**
	 * Licensing API request stringification should keep the existing print_r-style output without WooCommerce helpers.
	 *
	 * @return void
	 */
	public function test_licensing_api_request_keeps_print_r_output_contract(): void {
		$request = new \Woodev_Licensing_API_Request();

		$this->set_protected_property( $request, 'method', 'POST' );
		$this->set_protected_property(
			$request,
			'params',
			[
				'license' => 'abc123',
				'item_id' => 42,
			]
		);

		$this->assertSame(
			print_r(
				[
					'license' => 'abc123',
					'item_id' => 42,
				],
				true
			),
			$request->to_string()
		);
		$this->assertSame( $request->to_string(), $request->to_string_safe() );
	}

	/**
	 * Licensing API constructor should keep the previous http/https URL validation contract without WooCommerce helpers.
	 *
	 * @return void
	 */
	public function test_licensing_api_keeps_http_https_url_validation_contract(): void {
		$plugin = new Testable_Platform_Neutral_Licensing_Plugin();

		$this->assertSame(
			'https://custom.example/api',
			( new \Woodev_Licensing_API( $plugin, 'https://custom.example/api' ) )->get_url()
		);

		$this->assertSame(
			'http://custom.example/api',
			( new \Woodev_Licensing_API( $plugin, 'http://custom.example/api' ) )->get_url()
		);

		$this->assertSame(
			'https://woodev.ru/',
			( new \Woodev_Licensing_API( $plugin, 'ftp://custom.example/api' ) )->get_url()
		);

		$this->assertSame(
			'https://woodev.ru/',
			( new \Woodev_Licensing_API( $plugin, 'custom.example/api' ) )->get_url()
		);
	}

	/**
	 * License message date formatting should not require WooCommerce helpers in a platform-neutral unit context.
	 *
	 * @return void
	 */
	public function test_license_messages_keep_date_formatting_contract_without_woocommerce_helpers(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $option, $default = false ) {
				if ( 'date_format' === $option ) {
					return 'Y-m-d';
				}

				return $default;
			}
		);

		Functions\when( 'date_i18n' )->alias(
			static function ( string $format, int $timestamp ) {
				return gmdate( $format, $timestamp );
			}
		);

		$messages = ( new \ReflectionClass( \Woodev_License_Messages::class ) )->newInstanceWithoutConstructor();
		$method   = new \ReflectionMethod( \Woodev_License_Messages::class, 'get_date_i18n' );

		$this->assertSame( '2026-05-30', $method->invoke( $messages, 1_780_099_200 ) );
		$this->assertSame( '2026-05-30', $method->invoke( $messages, '2026-05-30 00:00:00' ) );
	}

	/**
	 * Sets a private property value via reflection.
	 *
	 * @param object $object Object to update.
	 * @param string $property Property name.
	 * @param mixed  $value Property value.
	 * @return void
	 */
	private function set_private_property( $object, string $property, $value ): void {
		$reflection_property = new \ReflectionProperty( $object, $property );
		$reflection_property->setValue( $object, $value );
	}

	/**
	 * Sets a protected property value via reflection.
	 *
	 * @param object $object Object to update.
	 * @param string $property Property name.
	 * @param mixed  $value Property value.
	 * @return void
	 */
	private function set_protected_property( $object, string $property, $value ): void {
		$reflection_property = new \ReflectionProperty( $object, $property );
		$reflection_property->setValue( $object, $value );
	}
}
