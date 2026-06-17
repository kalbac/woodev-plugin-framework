<?php
/**
 * OB-3 Step 2 robustness tests (s16).
 *
 * Covers two robustness findings from the OB-3 updater review (2026-06-14, s14):
 *   - F2:  catch(\Throwable) + error_log in get_version_from_remote()
 *   - F7:  wiring-failure error_log when Acks/Dispatcher classes are absent
 *
 * @package Woodev\Framework\Tests
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/licensing/updater/class-plugin-updater.php';

/**
 * Class UpdaterRobustnessTest.
 */
class UpdaterRobustnessTest extends TestCase {

	// ── F2: catch(\Throwable) + error_log ────────────────────────────────────

	/**
	 * The outer catch in get_version_from_remote() must catch \Throwable (not just
	 * Exception) so that PHP Error subclasses (TypeError, etc.) are captured.
	 *
	 * Source assertion — fails RED when catch block still says "Exception $e".
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_f2_source_catches_throwable_not_just_exception(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/woodev/licensing/updater/class-plugin-updater.php'
		);
		$this->assertNotEmpty( $source, 'class-plugin-updater.php source file could not be read.' );
		$this->assertStringNotContainsString(
			'catch ( Exception $e ) {',
			$source,
			'F2: the outer catch in get_version_from_remote() must not be "catch ( Exception $e )" — it must be \Throwable.'
		);
		$this->assertStringContainsString(
			'catch ( \Throwable $e )',
			$source,
			'F2: the outer catch in get_version_from_remote() must be "catch ( \Throwable $e )" to capture PHP Error subclasses.'
		);
	}

	/**
	 * The outer catch in get_version_from_remote() must call error_log() with the
	 * "get_version_from_remote failed" message so failures are diagnosable.
	 *
	 * Source assertion — fails RED when the catch block is still empty.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_f2_source_logs_throwable_message(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/woodev/licensing/updater/class-plugin-updater.php'
		);
		$this->assertNotEmpty( $source, 'class-plugin-updater.php source file could not be read.' );
		$this->assertStringContainsString(
			"'Woodev updater: get_version_from_remote failed: '",
			$source,
			'F2: catch block in get_version_from_remote() must call error_log() with the "get_version_from_remote failed" prefix.'
		);
	}

	// ── F7: wiring-failure logging ────────────────────────────────────────────

	/**
	 * When Woodev_License_Command_Acks class is absent, error_log() must emit a
	 * diagnostic so wiring bugs are visible in the server log.
	 *
	 * Source assertion — fails RED when the null-ack-store branch has no log call.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_f7_source_logs_when_acks_class_missing(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/woodev/licensing/updater/class-plugin-updater.php'
		);
		$this->assertNotEmpty( $source, 'class-plugin-updater.php source file could not be read.' );
		$this->assertStringContainsString(
			"'Woodev updater: Woodev_License_Command_Acks not available",
			$source,
			'F7: get_version_from_remote() must log a wiring-failure message when Woodev_License_Command_Acks class does not exist.'
		);
	}

	/**
	 * When Woodev_License_Command_Dispatcher class is absent, error_log() must emit
	 * a diagnostic so wiring bugs are visible in the server log.
	 *
	 * Source assertion — fails RED when the else branch has no log call.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_f7_source_logs_when_dispatcher_class_missing(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/woodev/licensing/updater/class-plugin-updater.php'
		);
		$this->assertNotEmpty( $source, 'class-plugin-updater.php source file could not be read.' );
		$this->assertStringContainsString(
			"'Woodev updater: Woodev_License_Command_Dispatcher not available",
			$source,
			'F7: get_version_from_remote() must log a wiring-failure message when Woodev_License_Command_Dispatcher class does not exist.'
		);
	}
}
