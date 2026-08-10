<?php
/**
 * API logger handler tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Handlers\API_Logger;

require_once dirname( __DIR__, 3 ) . '/woodev/handlers/class-api-logger.php';

/**
 * Class ApiLoggerTest.
 */
class ApiLoggerTest extends TestCase {

	/**
	 * Builds a minimal plugin test double.
	 *
	 * @param string $id Plugin id to return from get_id().
	 * @return \Woodev_Plugin&\Mockery\MockInterface
	 */
	private function make_plugin( string $id = 'edostavka' ) {
		$plugin = Mockery::mock( \Woodev_Plugin::class );
		$plugin->shouldReceive( 'get_id' )->andReturn( $id );

		return $plugin;
	}

	/**
	 * Unlike Cron_Handler/Translation_Handler, constructing API_Logger must NOT
	 * register the action hook by itself — Woodev_Payment_Gateway_Plugin no-ops the
	 * overridable add_api_request_logging() that would call register(), and eager
	 * self-registration in the constructor would double-log for payment gateways.
	 *
	 * @return void
	 */
	public function test_constructor_does_not_register_any_hook(): void {
		$plugin = $this->make_plugin();

		Functions\expect( 'add_action' )->never();

		new API_Logger( $plugin );
	}

	/**
	 * register() adds the action on the exact installed-site hook name, with the
	 * exact 2-argument, priority-10 signature preserved from Woodev_Plugin.
	 *
	 * @return void
	 */
	public function test_register_adds_action_with_exact_hook_name_and_args(): void {
		$plugin = $this->make_plugin( 'edostavka' );

		Functions\when( 'has_action' )->justReturn( false );

		$handler = new API_Logger( $plugin );

		Functions\expect( 'add_action' )
			->once()
			->with(
				'woodev_edostavka_api_request_performed',
				[ $handler, 'log_api_request' ],
				10,
				2
			);

		$handler->register();
	}

	/**
	 * register() is idempotent: it does not add a second listener when one is
	 * already registered for the hook.
	 *
	 * @return void
	 */
	public function test_register_skips_when_already_registered(): void {
		$plugin = $this->make_plugin();

		Functions\when( 'has_action' )->justReturn( true );

		Functions\expect( 'add_action' )->never();

		( new API_Logger( $plugin ) )->register();
	}

	/**
	 * log_api_request() logs both the request and the response, each prefixed and
	 * formatted via get_api_log_message(), passing the log id through unchanged.
	 *
	 * @return void
	 */
	public function test_log_api_request_logs_request_and_response(): void {
		$plugin = $this->make_plugin();

		$logged = [];
		$plugin->shouldReceive( 'log' )->andReturnUsing(
			static function ( $message, $log_id ) use ( &$logged ): void {
				$logged[] = [ $message, $log_id ];
			}
		);

		$handler = new API_Logger( $plugin );

		$handler->log_api_request(
			[ 'uri' => 'https://example.com/api', 'method' => 'GET' ],
			[ 'body' => 'ok' ],
			'edostavka_license_remote_data'
		);

		$this->assertCount( 2, $logged );
		$this->assertStringStartsWith( "Запрос\n", $logged[0][0] );
		$this->assertStringContainsString( 'uri: https://example.com/api', $logged[0][0] );
		$this->assertSame( 'edostavka_license_remote_data', $logged[0][1] );

		$this->assertStringStartsWith( "Ответ\n", $logged[1][0] );
		$this->assertStringContainsString( 'body: ok', $logged[1][0] );
		$this->assertSame( 'edostavka_license_remote_data', $logged[1][1] );
	}

	/**
	 * log_api_request() skips logging the response when it is empty.
	 *
	 * @return void
	 */
	public function test_log_api_request_skips_empty_response(): void {
		$plugin = $this->make_plugin();

		$logged = [];
		$plugin->shouldReceive( 'log' )->andReturnUsing(
			static function ( $message, $log_id ) use ( &$logged ): void {
				$logged[] = $message;
			}
		);

		( new API_Logger( $plugin ) )->log_api_request( [ 'uri' => 'https://example.com' ], [] );

		$this->assertCount( 1, $logged );
	}

	/**
	 * get_api_log_message() labels the message 'Запрос' when a non-empty uri key
	 * is present, and 'Ответ' otherwise, then lists each key/value pair.
	 *
	 * @return void
	 */
	public function test_get_api_log_message_formats_request_and_response_shapes(): void {
		$handler = new API_Logger( $this->make_plugin() );

		$request_message = $handler->get_api_log_message( [ 'uri' => 'https://example.com', 'method' => 'POST' ] );
		$this->assertStringStartsWith( 'Запрос', $request_message );
		$this->assertStringContainsString( 'uri: https://example.com', $request_message );
		$this->assertStringContainsString( 'method: POST', $request_message );

		$response_message = $handler->get_api_log_message( [ 'body' => 'ok' ] );
		$this->assertStringStartsWith( 'Ответ', $response_message );
		$this->assertStringContainsString( 'body: ok', $response_message );
	}
}
