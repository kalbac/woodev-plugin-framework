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

		// s8-p5: dispatch() parses every successful response for pull commands, so
		// the response double must expose get_response_data() (an array return keeps
		// consume_pull_commands() free of wp_json_encode normalisation).
		$response          = Mockery::mock();
		$response->license = 'valid';
		$response->shouldReceive( 'get_response_data' )->andReturn( array() );

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
		// s8-p5 (critic ruling #4a): the ack-store read is NOT wrapped in a swallow —
		// the test stubs get_option like any other WP function dispatch() touches.
		Functions\when( 'get_option' )->justReturn( false );

		$license = ( new \ReflectionClass( \Woodev_Plugins_License::class ) )->newInstanceWithoutConstructor();

		$this->set_private_property( $license, 'plugin', $plugin );
		$this->set_private_property( $license, 'api_handler', $api_handler );

		$dispatch = new \ReflectionMethod( \Woodev_Plugins_License::class, 'dispatch' );
		if ( PHP_VERSION_ID < 80100 ) {
			$dispatch->setAccessible( true );
		}

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

		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook_name, $value ) {
				return $value;
			}
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
	 * Licensing API request stringification should keep the WooCommerce fallback filter contract.
	 *
	 * @return void
	 */
	public function test_licensing_api_request_keeps_print_r_alternatives_filter_contract(): void {
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

		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook_name, $value, $expression ) {
				if ( 'woocommerce_print_r_alternatives' !== $hook_name ) {
					return $value;
				}

				return [
					[
						'func' => 'json_encode',
						'args' => [ $expression ],
					],
				];
			}
		);

		$this->assertSame( '{"license":"abc123","item_id":42}', $request->to_string() );
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
	 * get_body() merges framework_version into EVERY request's defaults — the
	 * server-side "is this site webhook-capable?" signal (additive request param;
	 * explicit caller params still win via wp_parse_args, url/author/email defaults
	 * unchanged).
	 *
	 * @return void
	 */
	public function test_get_body_adds_framework_version_default(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'get_option' )->justReturn( 'admin@example.com' );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults ) {
				return array_merge( $defaults, $args );
			}
		);

		$plugin = new Testable_Platform_Neutral_Licensing_Plugin();
		$api    = new \Woodev_Licensing_API( $plugin );

		$method = new \ReflectionMethod( \Woodev_Licensing_API::class, 'get_body' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$body = $method->invoke( $api, array( 'edd_action' => 'check_license', 'license' => 'KEY' ) );

		$this->assertSame( \Woodev_Plugin::VERSION, $body['framework_version'] );
		$this->assertSame( 'https://example.com', $body['url'] );
		$this->assertSame( 'Woodev', $body['author'] );
		$this->assertSame( 'check_license', $body['edd_action'] );

		// An explicit caller value must never be overridden by the default.
		$body = $method->invoke( $api, array( 'framework_version' => 'caller-wins' ) );
		$this->assertSame( 'caller-wins', $body['framework_version'] );
	}

	/**
	 * License message date formatting should not require WooCommerce helpers in a platform-neutral unit context.
	 *
	 * This test's contract is the ABSENCE of WooCommerce/WP date helpers
	 * (wc_string_to_datetime, wc_format_datetime, wc_date_format, wp_date, wp_timezone):
	 * Woodev_License_Messages branches on function_exists() and must take its
	 * non-helper fallback path. Because Brain Monkey defines a stubbed function
	 * globally for the WHOLE PHP process (PHP cannot un-define a function), any OTHER
	 * test that stubs one of these would flip function_exists() true here and break
	 * this contract (gotcha: testing/brain-monkey-function-pollution). Run in a
	 * separate process so the fallback path is guaranteed regardless of test order.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
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

		Functions\when( 'wp_date' )->alias(
			static function ( string $format, int $timestamp ) {
				return gmdate( $format, $timestamp );
			}
		);

		$messages = ( new \ReflectionClass( \Woodev_License_Messages::class ) )->newInstanceWithoutConstructor();
		$method   = new \ReflectionMethod( \Woodev_License_Messages::class, 'get_date_i18n' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$this->assertSame( '2026-05-30', $method->invoke( $messages, 1_780_099_200 ) );
		$this->assertSame( '2026-05-30', $method->invoke( $messages, '2026-05-30 00:00:00' ) );
	}

	/**
	 * License message date formatting should keep the WooCommerce date-format filter contract.
	 *
	 * Depends on the WooCommerce/WP date helpers being UNDEFINED (non-helper fallback
	 * path) — isolated to a separate process so prior tests that stub them cannot
	 * pollute function_exists() here (see the without-WooCommerce-helpers test above).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_license_messages_keep_woocommerce_date_format_filter_contract_without_helpers(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $option, $default = false ) {
				if ( 'date_format' === $option ) {
					return 'Y-m-d';
				}

				return $default;
			}
		);

		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook_name, $value ) {
				if ( 'woocommerce_date_format' === $hook_name ) {
					return 'd/m/Y';
				}

				return $value;
			}
		);

		Functions\when( 'date_i18n' )->alias(
			static function ( string $format, int $timestamp ) {
				return gmdate( $format, $timestamp );
			}
		);

		Functions\when( 'wp_date' )->alias(
			static function ( string $format, int $timestamp ) {
				return gmdate( $format, $timestamp );
			}
		);

		$messages = ( new \ReflectionClass( \Woodev_License_Messages::class ) )->newInstanceWithoutConstructor();
		$method   = new \ReflectionMethod( \Woodev_License_Messages::class, 'get_date_i18n' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$this->assertSame( '30/05/2026', $method->invoke( $messages, 1_780_099_200 ) );
	}

	/**
	 * License message string dates should be interpreted in the WordPress timezone.
	 *
	 * Depends on the WooCommerce/WP date helpers being UNDEFINED (non-helper fallback
	 * path) — isolated to a separate process so prior tests that stub them cannot
	 * pollute function_exists() here (see the without-WooCommerce-helpers test above).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_license_messages_keep_wordpress_timezone_contract_for_offset_strings_without_helpers(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $option, $default = false ) {
				if ( 'date_format' === $option ) {
					return 'Y-m-d H:i';
				}

				if ( 'timezone_string' === $option ) {
					return 'Europe/Moscow';
				}

				return $default;
			}
		);

		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook_name, $value ) {
				return $value;
			}
		);

		Functions\when( 'date_i18n' )->alias(
			static function ( string $format, int $timestamp ) {
				return gmdate( $format, $timestamp );
			}
		);

		Functions\when( 'wp_date' )->alias(
			static function ( string $format, int $timestamp, ?\DateTimeZone $timezone = null ) {
				$datetime = new \DateTimeImmutable( '@' . $timestamp );

				return $datetime->setTimezone( $timezone ?: new \DateTimeZone( 'UTC' ) )->format( $format );
			}
		);

		$messages = ( new \ReflectionClass( \Woodev_License_Messages::class ) )->newInstanceWithoutConstructor();
		$method   = new \ReflectionMethod( \Woodev_License_Messages::class, 'get_date_i18n' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$this->assertSame( '2026-05-30 00:30', $method->invoke( $messages, '2026-05-30T00:30:00+03:00' ) );
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
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection_property->setAccessible( true );
		}
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
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection_property->setAccessible( true );
		}
		$reflection_property->setValue( $object, $value );
	}
}
