<?php
/**
 * The REST route that receives a marketplace's notifications.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Intake;

require_once dirname( __DIR__, 2 ) . '/Shipping/Rest_Api/wp-rest-controller-stub.php';

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Intake\Notification_Account_Reader;
use Woodev\Framework\Marketplace\Intake\Notification_Intake;
use Woodev\Framework\Marketplace\Intake\Notification_Url;
use Woodev\Framework\Marketplace\Intake\Notifications_Controller;
use Woodev\Framework\Marketplace\Intake\Push_Rate_Limiter;
use Woodev\Framework\Marketplace\Jobs\Drain_Scheduler;
use Woodev\Framework\Marketplace\Ledger\Wpdb_Event_Ledger;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Push_Protocol;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Scripted_Inbound_Decoder;
use Woodev\Tests\Unit\Marketplace\Support\Sync_Test_Case;

/**
 * Moved from the Ozon plugin's `NotificationRoutesTest`: the route is public and carries a 32-character token, a body core
 * would refuse as invalid JSON is answered by the intake before core sees it, and no other request is touched.
 *
 * The route decision of the move: `woodev/v1/marketplace/{marketplace id}/notifications/{account_id}/{token}` — the framework's
 * namespace with the adapter's id in the path, so two marketplace plugins never share an address.
 */
final class NotificationsControllerTest extends Sync_Test_Case {
	private const ROUTE = '/woodev/v1/marketplace/fake/notifications/';

	/**
	 * Controller under test.
	 *
	 * @var Notifications_Controller
	 */
	private $controller;

	/**
	 * `$_SERVER['REMOTE_ADDR']` before the test.
	 *
	 * @var string|null
	 */
	private $remote_addr;

	/**
	 * Builds the controller over the real intake.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->remote_addr      = $_SERVER['REMOTE_ADDR'] ?? null;
		$_SERVER['REMOTE_ADDR'] = Fake_Push_Protocol::TRUSTED_IP;

		$limiter = new class() extends Push_Rate_Limiter {
			/**
			 * Never limits.
			 *
			 * @param string $key Key.
			 * @param int    $ttl TTL.
			 * @return int
			 */
			protected function increment_rate_limit_counter( string $key, int $ttl ): int {
				return 1;
			}
		};

		$this->controller = new Notifications_Controller(
			Sqlite_Tables::MARKETPLACE_ID,
			new Notification_Intake(
				new Scripted_Inbound_Decoder(),
				new Fake_Push_Protocol(),
				new Wpdb_Event_Ledger( $this->db, Sqlite_Tables::table( 'events' ), $this->clock() ),
				new Notification_Account_Reader( $this->db, Sqlite_Tables::table( 'accounts' ) ),
				new Drain_Scheduler( $this->names() ),
				$limiter,
				[ $this, 'log_line' ]
			)
		);
	}

	/**
	 * Restores the server variable.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( null === $this->remote_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->remote_addr;
		}

		parent::tearDown();
	}

	/**
	 * The route lives in the framework's namespace under the adapter's id, takes POST only, is public, and its token is exactly
	 * 32 letters and digits.
	 *
	 * @return void
	 */
	public function test_the_route_is_a_public_post_route_with_a_32_character_token(): void {
		$registered = [];

		Functions\when( 'register_rest_route' )->alias(
			static function ( string $namespace, string $route, array $args ) use ( &$registered ): bool {
				$registered[] = [ $namespace, $route, $args ];

				return true;
			}
		);

		$this->controller->register_routes();

		$this->assertCount( 1, $registered );
		list( $namespace, $route, $args ) = $registered[0];

		$this->assertSame( 'woodev/v1', $namespace );
		$this->assertSame( \Woodev_REST_V1_Registrar::ROUTE_NAMESPACE, Notifications_Controller::REST_NAMESPACE );
		$this->assertSame( '/marketplace/fake/notifications', Notifications_Controller::base( 'fake' ) );
		$this->assertSame( 'POST', $args['methods'] );
		$this->assertSame( '__return_true', $args['permission_callback'] );
		$this->assertSame( [ $this->controller, 'handle' ], $args['callback'] );

		$pattern = '#^' . $route . '$#';
		$this->assertSame( 1, preg_match( $pattern, '/marketplace/fake/notifications/5/' . str_repeat( 'a', 32 ) ) );
		$this->assertSame( 0, preg_match( $pattern, '/marketplace/fake/notifications/5/' . str_repeat( 'a', 31 ) ) );
		$this->assertSame( 0, preg_match( $pattern, '/marketplace/fake/notifications/5/' . str_repeat( 'a', 33 ) ) );
		$this->assertSame( 0, preg_match( $pattern, '/marketplace/fake/notifications/x/' . str_repeat( 'a', 32 ) ) );
		$this->assertSame( 0, preg_match( $pattern, '/marketplace/other/notifications/5/' . str_repeat( 'a', 32 ) ) );
	}

	/**
	 * The route registration and the early answer are hooked.
	 *
	 * @return void
	 */
	public function test_it_hooks_the_route_and_the_early_answer(): void {
		$this->controller->register();

		$this->assertSame( 10, has_action( 'rest_api_init', [ $this->controller, 'register_routes' ] ) );
		$this->assertSame( 10, has_filter( 'rest_pre_dispatch', [ $this->controller, 'pre_dispatch' ] ) );
	}

	/**
	 * The registered callback returns the intake's answer as a REST response: status, body and headers.
	 *
	 * @return void
	 */
	public function test_the_callback_returns_the_intakes_answer_as_a_rest_response(): void {
		$request = \Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_param' )->with( 'account_id' )->andReturn( (string) $this->account_id );
		$request->shouldReceive( 'get_param' )->with( 'token' )->andReturn( $this->token() );
		$request->shouldReceive( 'get_body' )->andReturn( '{"kind":"order_created","id":"1-1"}' );

		$response = $this->controller->handle( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( Scripted_Inbound_Decoder::SUCCESS, $response->get_data() );
		$this->assertSame( [ 'new' ], array_column( $this->events(), 'status' ) );
		$this->assertSame( [], $this->market->requests, 'no remote call at request time' );
	}

	/**
	 * A body that is not JSON would be refused by core with a 400 before the callback runs; the early answer hands it to the
	 * intake instead, which answers 200 and records it.
	 *
	 * @return void
	 */
	public function test_a_malformed_json_notification_is_answered_before_core_validation(): void {
		$response = $this->controller->pre_dispatch( null, null, $this->request( 'POST', self::ROUTE . $this->account_id . '/' . $this->token(), '{broken' ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'ignored' ], array_column( $this->events(), 'status' ) );
		$this->assertSame( [ 'invalid json' ], array_column( $this->events(), 'result' ) );
	}

	/**
	 * The early answer still checks the token: a wrong one is stored without an account.
	 *
	 * @return void
	 */
	public function test_the_early_answer_still_checks_the_token(): void {
		$response = $this->controller->pre_dispatch( null, null, $this->request( 'POST', self::ROUTE . $this->account_id . '/' . str_repeat( 'z', 32 ), '{"kind":"order_created","id":"1-1"}' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ null ], array_column( $this->events(), 'account_id' ) );
		$this->assertSame( [], $this->scheduler->actions );
	}

	/**
	 * Every other request goes on untouched: another method, another route, another marketplace's address, a request another
	 * filter already answered, and anything that is not a request.
	 *
	 * @return void
	 */
	public function test_the_early_answer_leaves_other_requests_alone(): void {
		$ours   = self::ROUTE . $this->account_id . '/' . $this->token();
		$body   = '{"kind":"order_created","id":"1-1"}';
		$answer = new \WP_REST_Response( [ 'earlier' => true ], 202 );

		$this->assertNull( $this->controller->pre_dispatch( null, null, $this->request( 'GET', $ours, $body ) ) );
		$this->assertNull( $this->controller->pre_dispatch( null, null, $this->request( 'POST', '/woodev/v1/settings', $body ) ) );
		$this->assertNull( $this->controller->pre_dispatch( null, null, $this->request( 'POST', str_replace( '/fake/', '/other/', $ours ), $body ) ) );
		$this->assertNull( $this->controller->pre_dispatch( null, null, $this->request( 'POST', $ours . '/more', $body ) ) );
		$this->assertNull( $this->controller->pre_dispatch( null, null, 'not a request' ) );
		$this->assertSame( $answer, $this->controller->pre_dispatch( $answer, null, $this->request( 'POST', $ours, $body ) ) );
		$this->assertSame( [], $this->events() );
	}

	/**
	 * The URL the marketplace is asked to call is the route with the account's id and token.
	 *
	 * @return void
	 */
	public function test_the_url_of_an_account_is_its_route(): void {
		Functions\when( 'rest_url' )->alias(
			static function ( string $path = '' ): string {
				return 'https://shop.test/wp-json/' . ltrim( $path, '/' );
			}
		);

		$account = ( new Notification_Account_Reader( $this->db, Sqlite_Tables::table( 'accounts' ) ) )->find( $this->account_id );

		$this->assertSame( 'https://shop.test/wp-json' . self::ROUTE . $this->account_id . '/' . $this->token(), Notification_Url::for_account( Sqlite_Tables::MARKETPLACE_ID, $account ) );
	}

	/**
	 * Builds a request double.
	 *
	 * @param string $method Method.
	 * @param string $route  Route.
	 * @param string $body   Body.
	 * @return \WP_REST_Request
	 */
	private function request( string $method, string $route, string $body ): \WP_REST_Request {
		$request = \Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_method' )->andReturn( $method );
		$request->shouldReceive( 'get_route' )->andReturn( $route );
		$request->shouldReceive( 'get_body' )->andReturn( $body );

		return $request;
	}

	/**
	 * Returns the URL token of the account.
	 *
	 * @return string
	 */
	private function token(): string {
		return (string) $this->account_now()->setting( 'notification_token' );
	}

	/**
	 * Returns the event rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function events(): array {
		return $this->db->rows( 'SELECT * FROM ' . Sqlite_Tables::table( 'events' ) . ' ORDER BY event_id' );
	}
}
