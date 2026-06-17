<?php
/**
 * OB-3 Step 4 contract-touching tests (s18).
 *
 * Covers the three contract-touching findings from the OB-3 updater review
 * (2026-06-14, s14), implemented under operator sign-off:
 *   - F8:  in_plugin_update_message-{$file} must pass the RESPONSE object as its
 *          2nd argument (WP convention), not the plugin-data array twice.
 *   - F9:  show_changelog() must unslash + sanitize the $_REQUEST reads and match
 *          the plugin path strictly against $this->name (no nonce — endpoint is
 *          already capability-gated + read-only, and a nonce would change the
 *          changelog URL shape, a contract).
 *   - F10: the version cache value must stamp its source licensing endpoint so a
 *          changed woodev_license_base_url never serves stale cross-store data.
 *          The frozen option KEY is unchanged.
 *
 * @package Woodev\Framework\Tests
 */

namespace Woodev\Tests\Unit;

use Mockery;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;

require_once dirname( __DIR__, 2 ) . '/woodev/licensing/updater/class-plugin-updater.php';

/**
 * Class UpdaterContractTouchingTest.
 */
class UpdaterContractTouchingTest extends TestCase {

	private const SUBJECT = '/woodev/licensing/updater/class-plugin-updater.php';

	// ── F8: in_plugin_update_message-{$file} 2nd argument ─────────────────────

	/**
	 * show_update_notification() must fire in_plugin_update_message-{$file} with the
	 * update RESPONSE object as its 2nd argument — NOT the plugin-data array twice.
	 *
	 * Behavioral: the action is asserted via Brain Monkey with the exact response
	 * instance for arg 2. Fails RED while the source passes ( $plugin, $plugin ).
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_f8_update_message_action_receives_response_object_as_second_arg(): void {
		$file = 'test-plugin/test-plugin.php';
		$slug = 'test-plugin';

		$response              = new \stdClass();
		$response->new_version = '9.0.0';
		$response->package     = 'https://woodev.ru/x.zip';
		$response->sections    = (object) array( 'changelog' => '<p>notes</p>' );

		$update_cache             = new \stdClass();
		$update_cache->response   = array( $file => $response );
		$plugin_data              = array( 'Name' => 'Test Plugin' );

		Functions\when( 'is_network_admin' )->justReturn( false );
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_site_transient' )->justReturn( $update_cache );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_site_option' )->justReturn( array() );
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.test/changelog' );
		Functions\when( 'self_admin_url' )->returnArg();
		Functions\when( 'wp_nonce_url' )->returnArg();

		$updater = ( new \ReflectionClass( \Woodev_Plugin_Updater::class ) )->newInstanceWithoutConstructor();
		$this->set_prop( $updater, 'name', $file );
		$this->set_prop( $updater, 'slug', $slug );
		$this->set_prop( $updater, 'version', '8.5.0' );

		Actions\expectDone( "in_plugin_update_message-{$file}" )
			->once()
			->with(
				$plugin_data,
				Mockery::on(
					static function ( $arg ) use ( $response ) {
						return $arg === $response;
					}
				)
			);

		ob_start();
		$updater->show_update_notification( $file, $plugin_data );
		ob_get_clean();
	}

	/**
	 * Source guard: the do_action() call must not pass the plugin-data array twice.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_f8_source_does_not_pass_plugin_data_twice(): void {
		$source = $this->subject_source();
		$this->assertStringNotContainsString(
			'"in_plugin_update_message-{$file}", $plugin, $plugin',
			$source,
			'F8: in_plugin_update_message-{$file} must not receive the plugin-data array twice.'
		);
	}

	// ── F9: changelog endpoint hardening ──────────────────────────────────────

	/**
	 * show_changelog() reads $_REQUEST values through wp_unslash() before use.
	 *
	 * Source assertion — show_changelog() calls exit(), so it is not testable
	 * in-process (same constraint as the s15 F13 source tests).
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_f9_source_unslashes_request_reads(): void {
		$source = $this->subject_source();
		$this->assertStringContainsString( 'wp_unslash( $_REQUEST[', $source, 'F9: $_REQUEST reads must be unslashed.' );
	}

	/**
	 * show_changelog() sanitizes the $_REQUEST values it reads.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_f9_source_sanitizes_request_reads(): void {
		$source = $this->subject_source();
		$this->assertStringContainsString( 'sanitize_text_field(', $source, 'F9: $_REQUEST reads must be sanitized.' );
	}

	/**
	 * show_changelog() matches the requested plugin path strictly against $this->name
	 * (not merely a non-empty check).
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_f9_source_matches_plugin_strictly_against_name(): void {
		$source = $this->subject_source();
		$this->assertMatchesRegularExpression(
			'/\$this->name\s*!==\s*\$plugin|\$plugin\s*!==\s*\$this->name/',
			$source,
			'F9: show_changelog() must require the requested plugin path === $this->name.'
		);
	}

	// ── F10: cache value source-stamp ─────────────────────────────────────────

	/**
	 * set_version_info_cache() stamps the licensing endpoint (api_url) into the
	 * cached option value so it can be validated on read.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_f10_set_cache_stamps_source_endpoint(): void {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$captured = null;
		Functions\expect( 'update_option' )
			->once()
			->andReturnUsing(
				static function ( $key, $data ) use ( &$captured ) {
					$captured = $data;
					return true;
				}
			);

		$updater = ( new \ReflectionClass( \Woodev_Plugin_Updater::class ) )->newInstanceWithoutConstructor();
		$this->set_prop( $updater, 'api_url', 'https://store-a.example/' );

		$this->call_method( $updater, 'set_version_info_cache', (object) array( 'new_version' => '9.0.0' ), 'woodev_test_key' );

		$this->assertIsArray( $captured );
		$this->assertArrayHasKey( 'source', $captured );
		$this->assertSame( 'https://store-a.example/', $captured['source'] );
	}

	/**
	 * get_cached_version_info() returns the cached value when the stamped source
	 * matches the current licensing endpoint.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_f10_get_cache_returns_value_when_source_matches(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'timeout' => 9999999999,
				'value'   => json_encode( (object) array( 'new_version' => '9.0.0' ) ),
				'source'  => 'https://store-a.example/',
			)
		);

		$updater = ( new \ReflectionClass( \Woodev_Plugin_Updater::class ) )->newInstanceWithoutConstructor();
		$this->set_prop( $updater, 'api_url', 'https://store-a.example/' );

		$result = $this->call_method( $updater, 'get_cached_version_info', 'woodev_test_key' );

		$this->assertIsObject( $result );
		$this->assertSame( '9.0.0', $result->new_version );
	}

	/**
	 * get_cached_version_info() treats a value stamped with a DIFFERENT endpoint as
	 * a cache miss — the protection against stale cross-store data.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_f10_get_cache_misses_when_source_differs(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'timeout' => 9999999999,
				'value'   => json_encode( (object) array( 'new_version' => '9.0.0' ) ),
				'source'  => 'https://store-b.example/',
			)
		);

		$updater = ( new \ReflectionClass( \Woodev_Plugin_Updater::class ) )->newInstanceWithoutConstructor();
		$this->set_prop( $updater, 'api_url', 'https://store-a.example/' );

		$result = $this->call_method( $updater, 'get_cached_version_info', 'woodev_test_key' );

		$this->assertFalse( $result, 'A cache stamped with a different endpoint must be a miss.' );
	}

	/**
	 * get_cached_version_info() treats an old, unstamped value (no source key) as a
	 * cache miss so legacy caches refresh once after upgrade.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_f10_get_cache_misses_when_source_absent(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'timeout' => 9999999999,
				'value'   => json_encode( (object) array( 'new_version' => '9.0.0' ) ),
			)
		);

		$updater = ( new \ReflectionClass( \Woodev_Plugin_Updater::class ) )->newInstanceWithoutConstructor();
		$this->set_prop( $updater, 'api_url', 'https://store-a.example/' );

		$result = $this->call_method( $updater, 'get_cached_version_info', 'woodev_test_key' );

		$this->assertFalse( $result, 'An unstamped legacy cache must be a miss.' );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Reads the updater source file.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	private function subject_source(): string {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . self::SUBJECT );
		$this->assertNotEmpty( $source, 'class-plugin-updater.php source file could not be read.' );
		return $source;
	}

	/**
	 * Sets a private/protected property via reflection.
	 *
	 * @since 2.0.2
	 *
	 * @param object $object Target object.
	 * @param string $name   Property name.
	 * @param mixed  $value  Value to set.
	 * @return void
	 */
	private function set_prop( object $object, string $name, $value ): void {
		$p = new \ReflectionProperty( $object, $name );
		if ( PHP_VERSION_ID < 80100 ) {
			$p->setAccessible( true );
		}
		$p->setValue( $object, $value );
	}

	/**
	 * Calls a private/protected method via reflection, forwarding any arguments.
	 *
	 * @since 2.0.2
	 *
	 * @param object $object Target object.
	 * @param string $method Method name.
	 * @param mixed  ...$args Arguments to forward.
	 * @return mixed
	 */
	private function call_method( object $object, string $method, ...$args ) {
		$r = new \ReflectionMethod( $object, $method );
		if ( PHP_VERSION_ID < 80100 ) {
			$r->setAccessible( true );
		}
		return $r->invoke( $object, ...$args );
	}
}
