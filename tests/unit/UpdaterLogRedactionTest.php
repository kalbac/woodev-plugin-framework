<?php
/**
 * Tests for the log-boundary redaction fix at the updater's own log boundary — #585.
 *
 * get_version_from_remote()'s outer catch (see UpdaterRobustnessTest.php's F2
 * coverage of the catch/log SHAPE itself) logs a caught `\Throwable`'s message.
 * That `\Throwable` can come from `$this->api_handler->make_request()` — a
 * response parser or the underlying HTTP transport, neither of which is
 * `Woodev_API_Base` — so its message may never have passed through that
 * class's own redaction at all. This file pins that the message is routed
 * through {@see \Woodev_API_Base::redact_secret_log_text()} before it reaches
 * `error_log()`.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;

require_once dirname( __DIR__, 2 ) . '/woodev/api/class-api-base.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/updater/class-plugin-updater.php';

/**
 * Class UpdaterLogRedactionTest.
 */
class UpdaterLogRedactionTest extends TestCase {

	/**
	 * The secret a foreign transport/parser exception embeds in its message.
	 *
	 * @var string
	 */
	private const SECRET = 'LIVESECRET';

	/**
	 * A foreign exception message carrying a secret must have it redacted
	 * before `get_version_from_remote()`'s catch block logs it.
	 *
	 * @return void
	 */
	public function test_get_version_from_remote_redacts_a_secret_in_a_foreign_exception_message(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		// get_api_params() reads the §9.5 pending-ack store before make_request() is
		// even called — Woodev_License_Command_Acks::get_pending() reads get_option().
		Functions\when( 'get_option' )->justReturn( false );

		$updater = $this->make_updater( [], 'woodev-test-plugin', false );

		$api_handler = Mockery::mock();
		$api_handler->shouldReceive( 'make_request' )->andThrow(
			new \Exception( 'carrier rejected api_key=' . self::SECRET )
		);
		$this->set_private( $updater, 'api_handler', $api_handler );

		$captured = null;
		Functions\expect( 'error_log' )
			->once()
			->with(
				Mockery::on(
					static function ( $message ) use ( &$captured ) {
						$captured = $message;
						return true;
					}
				)
			);

		$result = $this->call_private( $updater, 'get_version_from_remote' );

		$this->assertFalse( $result );
		$this->assertSame(
			'Woodev updater: get_version_from_remote failed: carrier rejected api_key=' . \Woodev_API_Base::SECRET_VALUE_MASK,
			$captured
		);
	}

	/**
	 * Control: an exception message carrying NO secret must reach the
	 * rendered error_log() line byte-for-byte — asserted on the COMPLETE
	 * rendered line, not merely a substring, so a redactor that mangled
	 * anything else in the line could not pass silently.
	 *
	 * @return void
	 */
	public function test_get_version_from_remote_leaves_a_message_without_a_secret_untouched(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		// get_api_params() reads the §9.5 pending-ack store before make_request() is
		// even called — Woodev_License_Command_Acks::get_pending() reads get_option().
		Functions\when( 'get_option' )->justReturn( false );

		$updater = $this->make_updater( [], 'woodev-test-plugin', false );

		$api_handler = Mockery::mock();
		$api_handler->shouldReceive( 'make_request' )->andThrow( new \Exception( 'carrier unreachable' ) );
		$this->set_private( $updater, 'api_handler', $api_handler );

		$captured = null;
		Functions\expect( 'error_log' )
			->once()
			->with(
				Mockery::on(
					static function ( $message ) use ( &$captured ) {
						$captured = $message;
						return true;
					}
				)
			);

		$this->call_private( $updater, 'get_version_from_remote' );

		$this->assertSame(
			'Woodev updater: get_version_from_remote failed: carrier unreachable',
			$captured
		);
	}

	/* ----------------------------------------------------------------------- *
	 * Helpers — mirror UpdaterKeylessPollingTest.php's minimal-updater builder.
	 * ----------------------------------------------------------------------- */

	/**
	 * Builds a Woodev_Plugin_Updater bypassing its constructor, seeding the private
	 * fields get_api_params()/get_version_from_remote() read.
	 *
	 * @param array<string, mixed> $api_data The api_data array (license/item_id/version).
	 * @param string               $slug     The plugin slug.
	 * @param bool                 $beta     The beta flag.
	 * @return \Woodev_Plugin_Updater
	 */
	private function make_updater( array $api_data, string $slug, bool $beta ): \Woodev_Plugin_Updater {
		$updater = ( new \ReflectionClass( \Woodev_Plugin_Updater::class ) )->newInstanceWithoutConstructor();

		$this->set_private( $updater, 'api_data', $api_data );
		$this->set_private( $updater, 'slug', $slug );
		$this->set_private( $updater, 'beta', $beta );
		$this->set_private( $updater, 'version', $api_data['version'] ?? '2.0.0' );
		$this->set_private( $updater, 'name', $slug . '/' . $slug . '.php' );

		return $updater;
	}

	/**
	 * Calls a private method via reflection.
	 *
	 * @param object $object Target.
	 * @param string $method Method name.
	 * @return mixed
	 */
	private function call_private( $object, string $method ) {
		$reflection = new \ReflectionMethod( $object, $method );
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}

		return $reflection->invoke( $object );
	}

	/**
	 * Sets a private property via reflection.
	 *
	 * @param object $object   Target.
	 * @param string $property Property name.
	 * @param mixed  $value    Value.
	 * @return void
	 */
	private function set_private( $object, string $property, $value ): void {
		$reflection = new \ReflectionProperty( $object, $property );
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}
		$reflection->setValue( $object, $value );
	}
}
