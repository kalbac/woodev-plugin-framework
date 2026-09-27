<?php
/**
 * Loader definition download_id validation tests (#916).
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

require_once dirname( __DIR__, 2 ) . '/woodev/class-framework-plugin-loader-definition.php';

use Woodev\Framework\Framework_Plugin_Loader_Definition;

/**
 * `download_id` is the EDD product id on woodev.ru — the license identity the resolver
 * later refuses to let two plugins share (#916). It is a required, positive-integer field
 * of the loader definition, validated the same way as every other required field.
 *
 * @covers \Woodev\Framework\Framework_Plugin_Loader_Definition
 */
final class LoaderDefinitionDownloadIdTest extends TestCase {

	/**
	 * Returns a valid loader definition with optional overrides.
	 *
	 * @param array<string,mixed> $overrides Definition overrides.
	 * @return array<string,mixed>
	 */
	private function get_definition( array $overrides = [] ): array {
		return array_merge(
			[
				'plugin_id'         => 'download-id-test-plugin',
				'download_id'       => 4242,
				'plugin_name'       => 'Download Id Test Plugin',
				'plugin_version'    => '1.0.0',
				'framework_version' => '2.0.0',
				'plugin_file'       => __FILE__,
				'platform'          => Framework_Plugin_Loader_Definition::PLATFORM_WORDPRESS,
				'requirements'      => [
					'php'       => '7.4',
					'wordpress' => '6.3',
				],
				'callback'          => static function (): void {},
			],
			$overrides
		);
	}

	/**
	 * A missing download_id makes the definition invalid.
	 */
	public function test_missing_download_id_is_invalid(): void {
		$definition = $this->get_definition();
		unset( $definition['download_id'] );

		$errors = [];
		$result = Framework_Plugin_Loader_Definition::from_array( $definition, $errors );

		$this->assertNull( $result );
		$this->assertContains( 'Missing required loader definition field: download_id.', $errors );
	}

	/**
	 * A zero download_id makes the definition invalid.
	 */
	public function test_zero_download_id_is_invalid(): void {
		$errors = [];
		$result = Framework_Plugin_Loader_Definition::from_array( $this->get_definition( [ 'download_id' => 0 ] ), $errors );

		$this->assertNull( $result );
		$this->assertContains(
			'Loader definition download_id must be a positive integer — it is the EDD product id on woodev.ru and two plugins sharing one is always an author error.',
			$errors
		);
	}

	/**
	 * A negative download_id makes the definition invalid.
	 */
	public function test_negative_download_id_is_invalid(): void {
		$errors = [];
		$result = Framework_Plugin_Loader_Definition::from_array( $this->get_definition( [ 'download_id' => -5 ] ), $errors );

		$this->assertNull( $result );
		$this->assertContains(
			'Loader definition download_id must be a positive integer — it is the EDD product id on woodev.ru and two plugins sharing one is always an author error.',
			$errors
		);
	}

	/**
	 * A non-numeric download_id makes the definition invalid.
	 */
	public function test_non_numeric_download_id_is_invalid(): void {
		$errors = [];
		$result = Framework_Plugin_Loader_Definition::from_array( $this->get_definition( [ 'download_id' => 'not-a-number' ] ), $errors );

		$this->assertNull( $result );
		$this->assertContains(
			'Loader definition download_id must be a positive integer — it is the EDD product id on woodev.ru and two plugins sharing one is always an author error.',
			$errors
		);
	}

	/**
	 * A valid positive-integer download_id is accepted and the getter returns it as an int,
	 * including when the raw value arrives as a numeric string (WordPress option/JSON round-trips
	 * hand back strings, never int literals).
	 */
	public function test_valid_download_id_is_accepted_and_returned_as_int(): void {
		$errors     = [];
		$definition = Framework_Plugin_Loader_Definition::from_array( $this->get_definition( [ 'download_id' => '4242' ] ), $errors );

		$this->assertSame( [], $errors );
		$this->assertNotNull( $definition );
		$this->assertSame( 4242, $definition->get_download_id() );
		$this->assertIsInt( $definition->get_download_id() );
	}
}
