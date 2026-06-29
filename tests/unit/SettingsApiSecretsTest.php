<?php
/**
 * Settings API secrets/constant threading tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/class-setting.php';
require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/abstract-class-settings.php';

/**
 * Class SettingsApiSecretsTest.
 */
class SettingsApiSecretsTest extends TestCase {

	/**
	 * Builds an anonymous settings handler whose register_settings() runs the
	 * supplied closure and whose save() records every invocation into $saved.
	 *
	 * @param callable    $register closure receiving the handler to register settings on.
	 * @param array|null  $saved    by-reference recorder for save() calls.
	 * @return \Woodev_Abstract_Settings
	 */
	private function make_handler( callable $register, ?array &$saved = null ): \Woodev_Abstract_Settings {

		// Stub the WP plumbing the abstract handler touches during construction.
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = [] ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);

		// When the caller does not care about save() calls, record into a local sink.
		if ( null === $saved ) {
			$sink  = [];
			$saved = &$sink;
		}

		return new class( 'test-plugin', $register, $saved ) extends \Woodev_Abstract_Settings {

			/** @var callable */
			private $register_cb;

			/** @var array recorded save() invocations */
			public $saved_calls;

			/**
			 * @param string   $id       handler ID.
			 * @param callable $register closure registering settings.
			 * @param array    $saved    by-reference save() recorder.
			 */
			public function __construct( string $id, callable $register, array &$saved ) {
				$this->register_cb = $register;
				$this->saved_calls = &$saved;
				parent::__construct( $id );
			}

			/**
			 * Runs the supplied registration closure.
			 *
			 * @return void
			 */
			protected function register_settings() {
				( $this->register_cb )( $this );
			}

			/**
			 * Records the save() call instead of writing to the DB.
			 *
			 * @param string $setting_id setting ID.
			 * @return void
			 */
			public function save( $setting_id = '' ) {
				$this->saved_calls[] = $setting_id;
			}
		};
	}

	/**
	 * register_setting() must thread the sensitive flag and constant name onto the setting.
	 *
	 * @return void
	 */
	public function test_register_setting_threads_sensitive_and_constant_name(): void {
		$handler = $this->make_handler(
			static function ( $h ): void {
				$h->register_setting(
					'token',
					\Woodev_Setting::TYPE_STRING,
					[
						'name'          => 'Токен',
						'sensitive'     => true,
						'constant_name' => 'WOODEV_TOKEN_CONST',
					]
				);
			}
		);

		$setting = $handler->get_setting( 'token' );

		$this->assertTrue( $setting->is_sensitive() );
		$this->assertSame( 'WOODEV_TOKEN_CONST', $setting->get_constant_name() );
	}

	/**
	 * update_value() must not persist a setting whose backing constant is defined.
	 *
	 * @return void
	 */
	public function test_update_value_skips_write_when_constant_defined(): void {
		if ( ! defined( 'WOODEV_SKIP_CONST' ) ) {
			define( 'WOODEV_SKIP_CONST', 'locked' );
		}

		$saved   = [];
		$handler = $this->make_handler(
			static function ( $h ): void {
				$h->register_setting(
					'token',
					\Woodev_Setting::TYPE_STRING,
					[
						'name'          => 'Токен',
						'sensitive'     => true,
						'constant_name' => 'WOODEV_SKIP_CONST',
					]
				);
			},
			$saved
		);

		$handler->update_value( 'token', 'attempt-overwrite' );

		// save() must NOT have been invoked for a constant-backed setting.
		$this->assertSame( [], $saved, 'constant-backed setting must not be persisted' );
		$this->assertSame( 'locked', $handler->get_value( 'token' ) );
	}
}
