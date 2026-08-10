<?php
/**
 * API request logging tests.
 *
 * Woodev_Plugin::add_api_request_logging() / log_api_request() / get_api_log_message()
 * have never been extracted into a handler (see
 * docs-internal/gotchas/handler-extraction-must-preserve-override-chain.md). This file
 * pins the installed-site contract: the `woodev_{plugin_id}_api_request_performed`
 * action name, its argument list and priority, the log message format, and the two
 * external call sites that reach these methods directly (outside the action).
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';

/**
 * Testable plugin exposing a controllable get_id() and a capturing log().
 */
class Testable_Api_Logging_Plugin extends \Woodev_Plugin {

	/**
	 * Captured log() calls, as [ message, log_id ] pairs.
	 *
	 * @var array<int,array{0:string,1:string|null}>
	 */
	public $logged = [];

	/**
	 * Gets the plugin file.
	 *
	 * @return string
	 */
	protected function get_file() {
		return 'acme-plugin/acme-plugin.php';
	}

	/**
	 * Gets the plugin name.
	 *
	 * @return string
	 */
	public function get_plugin_name() {
		return 'Acme Plugin';
	}

	/**
	 * Gets the download ID.
	 *
	 * @return int
	 */
	public function get_download_id() {
		return 0;
	}

	/**
	 * Captures log() calls instead of writing anywhere.
	 *
	 * @param string      $message Message to log.
	 * @param string|null $log_id  Optional log id.
	 * @return void
	 */
	public function log( $message, $log_id = null ) {
		$this->logged[] = [ $message, $log_id ];
	}
}

/**
 * Class ApiRequestLoggingTest.
 */
class ApiRequestLoggingTest extends TestCase {

	/**
	 * Builds a testable plugin without running the full constructor.
	 *
	 * @param string $id Plugin id.
	 * @return Testable_Api_Logging_Plugin
	 */
	private function make_plugin( string $id = 'acme-plugin' ): Testable_Api_Logging_Plugin {
		$reflection = new \ReflectionClass( Testable_Api_Logging_Plugin::class );
		$plugin     = $reflection->newInstanceWithoutConstructor();

		$id_property = new \ReflectionProperty( \Woodev_Plugin::class, 'id' );
		if ( PHP_VERSION_ID < 80100 ) {
			$id_property->setAccessible( true );
		}
		$id_property->setValue( $plugin, $id );

		return $plugin;
	}

	/**
	 * add_api_request_logging() registers the action on the exact installed-site hook
	 * name, bound to the plugin instance, with the exact 2-argument, priority-10
	 * signature — when no listener is registered yet.
	 *
	 * @return void
	 */
	public function test_add_api_request_logging_registers_action_with_exact_signature(): void {
		$plugin = $this->make_plugin( 'acme-plugin' );

		Functions\when( 'has_action' )->justReturn( false );

		Functions\expect( 'add_action' )
			->once()
			->with(
				'woodev_acme-plugin_api_request_performed',
				[ $plugin, 'log_api_request' ],
				10,
				2
			);

		$plugin->add_api_request_logging();
	}

	/**
	 * add_api_request_logging() is idempotent: it does not add a second listener when
	 * one is already registered for the hook.
	 *
	 * @return void
	 */
	public function test_add_api_request_logging_skips_when_already_registered(): void {
		$plugin = $this->make_plugin();

		Functions\when( 'has_action' )->justReturn( true );

		Functions\expect( 'add_action' )->never();

		$plugin->add_api_request_logging();
	}

	/**
	 * log_api_request() logs both the request and the response, each prefixed and
	 * formatted via get_api_log_message(), passing the log id through unchanged.
	 *
	 * @return void
	 */
	public function test_log_api_request_logs_request_and_response(): void {
		$plugin = $this->make_plugin();

		$plugin->log_api_request(
			[
				'uri'    => 'https://example.com/api',
				'method' => 'GET',
			],
			[ 'body' => 'ok' ],
			'edostavka_license_remote_data'
		);

		$this->assertCount( 2, $plugin->logged );

		$this->assertStringStartsWith( "Запрос\n", $plugin->logged[0][0] );
		$this->assertStringContainsString( 'uri: https://example.com/api', $plugin->logged[0][0] );
		$this->assertSame( 'edostavka_license_remote_data', $plugin->logged[0][1] );

		$this->assertStringStartsWith( "Ответ\n", $plugin->logged[1][0] );
		$this->assertStringContainsString( 'body: ok', $plugin->logged[1][0] );
		$this->assertSame( 'edostavka_license_remote_data', $plugin->logged[1][1] );
	}

	/**
	 * log_api_request() skips logging the response when it is empty.
	 *
	 * @return void
	 */
	public function test_log_api_request_skips_empty_response(): void {
		$plugin = $this->make_plugin();

		$plugin->log_api_request( [ 'uri' => 'https://example.com' ], [] );

		$this->assertCount( 1, $plugin->logged );
	}

	/**
	 * get_api_log_message() labels the message 'Запрос' when a non-empty uri key is
	 * present, and 'Ответ' otherwise, then lists each key/value pair.
	 *
	 * @return void
	 */
	public function test_get_api_log_message_formats_request_and_response_shapes(): void {
		$plugin = $this->make_plugin();

		$request_message = $plugin->get_api_log_message(
			[
				'uri'    => 'https://example.com',
				'method' => 'POST',
			]
		);
		$this->assertStringStartsWith( 'Запрос', $request_message );
		$this->assertStringContainsString( 'uri: https://example.com', $request_message );
		$this->assertStringContainsString( 'method: POST', $request_message );

		$response_message = $plugin->get_api_log_message( [ 'body' => 'ok' ] );
		$this->assertStringStartsWith( 'Ответ', $response_message );
		$this->assertStringContainsString( 'body: ok', $response_message );
	}

	/**
	 * Woodev_Licensing_API::broadcast_request() must keep calling log_api_request()
	 * directly on the plugin, outside the woodev_{id}_api_request_performed action —
	 * a handler extraction that deletes this method (rather than keeping it as a
	 * delegate) breaks that call site.
	 *
	 * @return void
	 */
	public function test_log_api_request_is_still_called_directly_by_licensing_api(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/woodev/licensing/api/class-licensing-api.php' );

		$this->assertStringContainsString( '->plugin->log_api_request(', $source );
	}

	/**
	 * Woodev_Payment_Gateway::log_api_request() must keep calling
	 * get_api_log_message() directly via $this->get_plugin(), outside the
	 * woodev_{id}_api_request_performed action — a handler extraction that deletes
	 * this method (rather than keeping it as a delegate) breaks that call site.
	 *
	 * @return void
	 */
	public function test_get_api_log_message_is_still_called_directly_by_payment_gateway(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/woodev/payment-gateway/class-payment-gateway.php' );

		$this->assertStringContainsString( 'get_plugin()->get_api_log_message(', $source );
	}
}
