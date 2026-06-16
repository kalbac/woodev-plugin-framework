<?php
/**
 * OB-3 Step 3 — F5 normalization test (s16).
 *
 * Covers F5 from the OB-3 updater review (2026-06-14, s14):
 *   - F5: api_request() was a false multi-action abstraction; $_action was unused
 *         and every call resolved to get_version. The parameter has been removed.
 *
 * @package Woodev\Framework\Tests
 */

namespace Woodev\Tests\Unit;

require_once dirname( __DIR__, 2 ) . '/woodev/plugin-updater/class-plugin-updater.php';

/**
 * Class UpdaterNormalizationF5Test.
 */
class UpdaterNormalizationF5Test extends TestCase {

	// ── F5: api_request() no longer accepts a string action ───────────────────

	/**
	 * api_request() must not declare a string first parameter — the former
	 * $_action was unused (every call resolved to get_version). Source assertion.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_f5_api_request_has_no_action_string_parameter(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/woodev/plugin-updater/class-plugin-updater.php'
		);
		$this->assertNotEmpty( $source, 'class-plugin-updater.php source file could not be read.' );
		$this->assertStringNotContainsString(
			'private function api_request( string $_action',
			$source,
			'F5: api_request() must not have a string $_action parameter — it was unused; every call resolves to get_version.'
		);
	}

	/**
	 * api_request() must still accept an array parameter (the slug-guard data).
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_f5_api_request_accepts_array_data_parameter(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/woodev/plugin-updater/class-plugin-updater.php'
		);
		$this->assertNotEmpty( $source, 'class-plugin-updater.php source file could not be read.' );
		$this->assertStringContainsString(
			'private function api_request( array $_data )',
			$source,
			'F5: api_request() must accept array $_data as its only parameter after removing the unused $_action.'
		);
	}

	/**
	 * api_request() signature must be reflected via ReflectionMethod — confirms the
	 * method exists with exactly ONE parameter (no leftover action arg).
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_f5_api_request_has_exactly_one_parameter(): void {
		$r = new \ReflectionMethod( \Woodev_Plugin_Updater::class, 'api_request' );
		$this->assertCount(
			1,
			$r->getParameters(),
			'F5: api_request() must have exactly one parameter (array $_data) after removing the unused string $_action.'
		);
	}
}
