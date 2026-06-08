<?php
/**
 * Platform-neutral dependency helper regression tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 2 ) . '/woodev/class-woodev-plugin-dependencies.php';

/**
 * Minimal Woodev plugin test double for dependency checks.
 */
class Testable_Platform_Neutral_Dependencies_Plugin extends \Woodev_Plugin {

	/**
	 * Avoid parent construction for isolated dependency helper tests.
	 */
	public function __construct() {
		$property = new \ReflectionProperty( \Woodev_Plugin::class, 'id' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( $this, 'platform-neutral-dependencies' );
	}

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
		return 'Platform Neutral Dependencies Test Plugin';
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
 * Dependency wrapper that skips WordPress hook registration for focused tests.
 */
class Testable_Platform_Neutral_Plugin_Dependencies extends \Woodev_Plugin_Dependencies {

	/**
	 * Skips WordPress hook registration during focused unit tests.
	 *
	 * @return void
	 */
	protected function add_hooks() {
	}
}

/**
 * Class PlatformNeutralDependenciesTest.
 */
class PlatformNeutralDependenciesTest extends TestCase {

	/**
	 * PHP setting size parsing should keep the byte-conversion contract without WooCommerce helpers.
	 *
	 * @return void
	 */
	public function test_php_setting_size_parser_keeps_byte_contract_without_woocommerce_helpers(): void {
		Functions\when( 'wp_parse_args' )->alias(
			static function ( array $args, array $defaults ): array {
				return array_merge( $defaults, $args );
			}
		);
		Functions\when( 'size_format' )->alias(
			static function ( int $bytes ): string {
				return (string) $bytes;
			}
		);

		$selected_setting = $this->get_size_php_setting_fixture();
		$actual_num       = $this->convert_hr_size_to_bytes( $selected_setting['actual'] );
		$expected_num     = $actual_num + 1024;
		$plugin           = new Testable_Platform_Neutral_Dependencies_Plugin();
		$dependencies     = new Testable_Platform_Neutral_Plugin_Dependencies(
			$plugin,
			[
				'php_settings' => [
					$selected_setting['name'] => $expected_num,
				],
			]
		);

		$incompatible_settings = $dependencies->get_incompatible_php_settings();

		$this->assertArrayHasKey( $selected_setting['name'], $incompatible_settings );
		$this->assertSame( (string) $expected_num, $incompatible_settings[ $selected_setting['name'] ]['expected'] );
		$this->assertSame( (string) $actual_num, $incompatible_settings[ $selected_setting['name'] ]['actual'] );
		$this->assertSame( 'min', $incompatible_settings[ $selected_setting['name'] ]['type'] );
	}

	/**
	 * Base-owned dependency handling should not call wc_let_to_num() directly anymore.
	 *
	 * @return void
	 */
	public function test_dependency_file_does_not_call_wc_let_to_num_directly(): void {
		$contents = file_get_contents( dirname( __DIR__, 2 ) . '/woodev/class-woodev-plugin-dependencies.php' );

		$this->assertIsString( $contents );
		$this->assertStringNotContainsString( 'wc_let_to_num(', $contents );
	}

	/**
	 * Finds a real size-based PHP setting for the focused regression test.
	 *
	 * @return array{name:string,actual:string}
	 */
	private function get_size_php_setting_fixture(): array {
		foreach ( [ 'upload_max_filesize', 'post_max_size', 'memory_limit' ] as $setting_name ) {
			$actual = ini_get( $setting_name );

			if ( ! is_string( $actual ) || '' === $actual || '-1' === $actual ) {
				continue;
			}

			if ( is_numeric( substr( $actual, -1 ) ) ) {
				continue;
			}

			return [
				'name'   => $setting_name,
				'actual' => $actual,
			];
		}

		$this->markTestSkipped( 'No size-based PHP setting is available for the platform-neutral dependency regression test.' );
	}

	/**
	 * Converts shorthand PHP size notation to bytes for assertions.
	 *
	 * @param string $size PHP size string.
	 * @return int
	 */
	private function convert_hr_size_to_bytes( string $size ): int {
		$unit  = strtoupper( substr( $size, -1 ) );
		$value = (float) substr( $size, 0, -1 );

		switch ( $unit ) {
			case 'P':
				$value *= 1024;
			case 'T':
				$value *= 1024;
			case 'G':
				$value *= 1024;
			case 'M':
				$value *= 1024;
			case 'K':
				$value *= 1024;
		}

		return (int) $value;
	}
}
