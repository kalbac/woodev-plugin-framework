<?php
/**
 * REST license-command controller tests (s8-p3, Brain Monkey).
 *
 * Covers the thin transport layer of Woodev_REST_API_License_Command:
 *  - boot() idempotency: a second call registers nothing new (static guard +
 *    Woodev_REST_V1_Registrar class-level dedupe);
 *  - register_routes(): exactly ONE route under 'woodev/v1', path '/license-command',
 *    method 'POST', permission_callback === '__return_true', NO args schema (the
 *    dispatcher owns ALL validation — core's args layer must not leak field errors);
 *  - handler maps each Woodev_License_Command_Dispatcher::handle_raw_body() result
 *    {status, reason, http} to the exact WP_REST_Response status + body per the
 *    frozen §9.8 HTTP map (table-driven across all 14 outcomes);
 *  - the handler is thin: no try/catch, no extra response keys, no exception text.
 *
 * The dispatcher is NOT exercised here (that is LicenseCommandDispatcherTest's job).
 * Probe_REST_License_Command subclasses the controller and overrides the protected
 * dispatch() seam to return a controlled result — same subclass-for-seam pattern used
 * in LicenseCommandDispatcherTest (Probe_Command_Dispatcher overrides now()).
 *
 * Idempotent-boot and registrar-dedupe are tested via the same capture pattern used
 * in LicenseRestControllerTest (register_rest_route + add_action capture; static
 * guards reset via reflection in setUp/tearDown).
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin-exception.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/class-helper.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/api/interface-api-request.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/api/abstract-api-json-request.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/api/class-api-base.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/licensing/api/class-licensing-api.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/licensing/api/class-licensing-api-request.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-store.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-messages.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-plugin-license.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/rest-api/class-rest-v1-registrar.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/licensing/api/class-rest-api-license.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/functions-license-authority.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-envelope-verifier.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-command-nonce-store.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-command-dispatcher.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/licensing/api/class-rest-api-license-command.php';

	/**
	 * Minimal WP_REST_Response stand-in for unit context.
	 *
	 * Stores the data + HTTP status so assertions can inspect both without a real WP.
	 * Guarded so a real class (or one defined by a sibling test) wins.
	 */
	if ( ! class_exists( 'WP_REST_Response', false ) ) {
		class WP_REST_Response {

			/** @var mixed */
			public $data;

			/** @var int */
			public $status;

			/**
			 * @param mixed $data   Response body.
			 * @param int   $status HTTP status code.
			 */
			public function __construct( $data = null, int $status = 200 ) {
				$this->data   = $data;
				$this->status = $status;
			}

			/** @return mixed */
			public function get_data() {
				return $this->data;
			}

			/** @return int */
			public function get_status(): int {
				return $this->status;
			}
		}
	}

	/**
	 * Minimal WP_REST_Request stand-in for unit context.
	 *
	 * The controller's handle_command() types its parameter as WP_REST_Request
	 * (project convention, critic finding 4); the unit tests build a Mockery mock
	 * OF THIS CLASS so the type declaration is satisfied without loading WP.
	 * Guarded so a real class (or a sibling test's stub) wins.
	 */
	if ( ! class_exists( 'WP_REST_Request', false ) ) {
		class WP_REST_Request {

			/** @var string */
			public $body = '';

			/** @return string */
			public function get_body() {
				return $this->body;
			}
		}
	}

	/**
	 * Controller probe: overrides the dispatch() seam to return a fixed result.
	 *
	 * Same subclass-for-seam pattern as Probe_Command_Dispatcher. The production
	 * controller calls Woodev_License_Command_Dispatcher::handle_raw_body(); this
	 * probe returns a pre-set result so the table-driven HTTP-map tests are entirely
	 * decoupled from the dispatcher internals.
	 *
	 * boot() is intentionally NOT overridden — the static guard lives on the base
	 * class, so the registrar-deduplication tests use the real class directly.
	 */
	class Probe_REST_License_Command extends \Woodev_REST_API_License_Command {

		/**
		 * Result to return from dispatch().
		 *
		 * @var array{status: string, reason?: string, http: int}|null
		 */
		public static $fixed_result = null;

		/**
		 * The last raw body passed to dispatch() — lets tests assert pass-through.
		 *
		 * @var string|null
		 */
		public static $last_body = null;

		/**
		 * Returns the pre-set result instead of calling the real dispatcher.
		 *
		 * @param string $body Raw request body.
		 * @return array{status: string, reason?: string, http: int}
		 */
		protected function dispatch( string $body ): array {
			self::$last_body = $body;
			return self::$fixed_result ?? array( 'status' => 'failed', 'http' => 500 );
		}
	}
}

namespace Woodev\Tests\Unit {

use Mockery;
use Brain\Monkey\Functions;

/**
 * Class LicenseCommandRestTest.
 */
class LicenseCommandRestTest extends TestCase {

	/**
	 * Captured register_rest_route() calls: each entry is [ namespace, route, args ].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $registered_routes = array();

	/**
	 * Captured add_action( 'rest_api_init', ... ) callbacks.
	 *
	 * @var array<int, mixed>
	 */
	private array $rest_api_init_callbacks = array();

	/**
	 * Resets static guards, probe state, and capture buffers before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->registered_routes       = array();
		$this->rest_api_init_callbacks = array();

		\Probe_REST_License_Command::$fixed_result = null;
		\Probe_REST_License_Command::$last_body    = null;

		$this->reset_registrar_statics();
		$this->reset_command_controller_statics();
		$this->reset_license_controller_statics();
	}

	/**
	 * Resets static guards again so a later suite is never polluted.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		\Probe_REST_License_Command::$fixed_result = null;
		\Probe_REST_License_Command::$last_body    = null;

		$this->reset_registrar_statics();
		$this->reset_command_controller_statics();
		$this->reset_license_controller_statics();
		parent::tearDown();
	}

	/* ----------------------------------------------------------------------- *
	 * boot() idempotency
	 * ----------------------------------------------------------------------- */

	/**
	 * boot() is idempotent BY ITS OWN static guard, not merely thanks to the
	 * registrar's class-level dedupe: after the first boot() we empty the
	 * registrar's controllers map via reflection — if the second boot() reached
	 * Woodev_REST_V1_Registrar::register_controller() at all, the map would be
	 * repopulated. It must stay EMPTY because the static guard short-circuits
	 * before the registrar is ever touched (critic finding 2).
	 *
	 * @return void
	 */
	public function test_boot_is_idempotent(): void {
		$this->capture_add_action();

		\Woodev_REST_API_License_Command::boot();

		$this->assertCount( 1, $this->rest_api_init_callbacks );
		$this->assertCount( 1, $this->get_registered_controllers() );

		// Empty the registrar's map: a second boot() that reached the registrar
		// would re-add the controller (the dedupe key is gone with the entry).
		$this->set_registrar_controllers( array() );

		\Woodev_REST_API_License_Command::boot();

		$this->assertCount(
			0,
			$this->get_registered_controllers(),
			'Second boot() must not reach the registrar — the static guard short-circuits first.'
		);
		$this->assertCount(
			1,
			$this->rest_api_init_callbacks,
			'No new rest_api_init hook may be added by the second boot().'
		);
	}

	/**
	 * A second boot() from a different call site (simulating two plugins booting)
	 * registers nothing additional — the registrar class-level dedupe + the static
	 * guard together guarantee exactly one controller + one rest_api_init hook.
	 *
	 * @return void
	 */
	public function test_boot_registers_one_controller_across_multiple_plugins(): void {
		$this->capture_add_action();

		// Simulate two plugins both calling boot().
		\Woodev_REST_API_License_Command::boot();
		\Woodev_REST_API_License_Command::boot();

		$controllers = $this->get_registered_controllers();

		$this->assertCount( 1, $controllers, 'Exactly one controller must be stored after two boot() calls.' );
		$this->assertCount( 1, $this->rest_api_init_callbacks, 'Exactly one rest_api_init hook must be added.' );
	}

	/* ----------------------------------------------------------------------- *
	 * register_routes()
	 * ----------------------------------------------------------------------- */

	/**
	 * register_routes() registers exactly ONE route: POST /license-command under
	 * woodev/v1, with permission_callback === '__return_true' and NO args schema
	 * (the dispatcher owns all validation — core's args layer must not leak field
	 * errors that distinguish rejection causes before the signature check).
	 *
	 * @return void
	 */
	public function test_register_routes_registers_one_public_post_route(): void {
		$this->capture_register_rest_route();

		( new \Woodev_REST_API_License_Command() )->register_routes();

		$this->assertCount( 1, $this->registered_routes, 'Exactly one route must be registered.' );

		$call = $this->registered_routes[0];

		// Namespace is the contract namespace.
		$this->assertSame( 'woodev/v1', $call[0], 'Route must be registered under the woodev/v1 namespace.' );

		// Route path is the frozen contract (s8-p6 will pin this string).
		$this->assertSame( '/license-command', $call[1], 'Route path must be /license-command (frozen contract).' );

		$args = $call[2];

		// Method is POST.
		$this->assertSame( 'POST', $args['methods'], 'Route method must be POST.' );

		// permission_callback is EXACTLY '__return_true' (LOCKED operator decision:
		// auth IS the Ed25519 signature, there is no WP user in this flow).
		$this->assertSame(
			'__return_true',
			$args['permission_callback'],
			'permission_callback must be the literal string "__return_true".'
		);

		// NO args schema — the dispatcher owns all validation; core's args layer
		// must not leak field-level errors that distinguish rejection causes.
		$this->assertArrayNotHasKey(
			'args',
			$args,
			'No args schema must be registered (dispatcher owns validation).'
		);
	}

	/**
	 * The permission_callback returns true for any caller — confirmed by invoking
	 * the registered callback directly (no user setup, proves the public nature).
	 *
	 * @return void
	 */
	public function test_permission_callback_returns_true_for_unauthenticated_call(): void {
		$this->capture_register_rest_route();

		( new \Woodev_REST_API_License_Command() )->register_routes();

		$call   = $this->registered_routes[0];
		$result = call_user_func( $call[2]['permission_callback'] );

		$this->assertTrue( $result, 'permission_callback must return true (no WP user required).' );
	}

	/* ----------------------------------------------------------------------- *
	 * HTTP map — table-driven across all 14 outcome codes
	 * ----------------------------------------------------------------------- */

	/**
	 * Handler correctly maps each dispatcher result to the exact HTTP status + body.
	 *
	 * The 14 outcomes are the complete frozen §9.8 table:
	 *   executed 200, already 200, malformed 400, unsupported_protocol 400,
	 *   unsupported_command 400, invalid_window 400, bad_signature 401,
	 *   site_mismatch 401, unknown_plugin 404, network_active_unsupported 409,
	 *   expired 410, replayed 410, rate_limited 429, failed 500.
	 *
	 * Frozen WIRE contract (webhooks-spec §3.1 + plan §9.4): the ONLY 2xx bodies
	 * are {status:'executed'} and {status:'already'}; EVERY non-2xx body is
	 * exactly {status:'rejected', reason:'<code>'} — including the dispatcher's
	 * internal terminal statuses unsupported_command, network_active_unsupported
	 * and failed (their wire reason code is the internal status itself).
	 * Terminal-vs-retryable lives in the dispatcher result + the pull-path ack
	 * schema, never in the HTTP body shape.
	 *
	 * @dataProvider http_map_provider
	 *
	 * @param array{status: string, reason?: string, http: int} $dispatcher_result Dispatcher return value.
	 * @param int                                               $expected_http     Expected HTTP status.
	 * @param array<string, mixed>                              $expected_body     Expected response body.
	 * @return void
	 */
	public function test_handler_maps_dispatcher_result_to_correct_response(
		array $dispatcher_result,
		int $expected_http,
		array $expected_body
	): void {
		\Probe_REST_License_Command::$fixed_result = $dispatcher_result;

		$controller = new \Probe_REST_License_Command();
		$request    = $this->make_request( 'any body' );

		$response = $controller->handle_command( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame(
			$expected_http,
			$response->get_status(),
			"HTTP status for '{$dispatcher_result['status']}' must be {$expected_http}."
		);
		$this->assertSame(
			$expected_body,
			$response->get_data(),
			"Response body for '{$dispatcher_result['status']}' must match exactly."
		);
	}

	/**
	 * Provides the complete §9.8 HTTP map (14 outcomes).
	 *
	 * @return array<string, array{0: array, 1: int, 2: array}>
	 */
	public function http_map_provider(): array {
		return array(
			'executed → 200 {status:executed}'          => array(
				array( 'status' => 'executed', 'http' => 200 ),
				200,
				array( 'status' => 'executed' ),
			),
			'already → 200 {status:already}'            => array(
				array( 'status' => 'already', 'http' => 200 ),
				200,
				array( 'status' => 'already' ),
			),
			'malformed → 400 rejected'                  => array(
				array( 'status' => 'rejected', 'reason' => 'malformed', 'http' => 400 ),
				400,
				array( 'status' => 'rejected', 'reason' => 'malformed' ),
			),
			'unsupported_protocol → 400 rejected'       => array(
				array( 'status' => 'rejected', 'reason' => 'unsupported_protocol', 'http' => 400 ),
				400,
				array( 'status' => 'rejected', 'reason' => 'unsupported_protocol' ),
			),
			'unsupported_command → 400 rejected (wire)' => array(
				array( 'status' => 'unsupported_command', 'http' => 400 ),
				400,
				array( 'status' => 'rejected', 'reason' => 'unsupported_command' ),
			),
			'invalid_window → 400 rejected'             => array(
				array( 'status' => 'rejected', 'reason' => 'invalid_window', 'http' => 400 ),
				400,
				array( 'status' => 'rejected', 'reason' => 'invalid_window' ),
			),
			'bad_signature → 401 rejected'              => array(
				array( 'status' => 'rejected', 'reason' => 'bad_signature', 'http' => 401 ),
				401,
				array( 'status' => 'rejected', 'reason' => 'bad_signature' ),
			),
			'site_mismatch → 401 rejected'              => array(
				array( 'status' => 'rejected', 'reason' => 'site_mismatch', 'http' => 401 ),
				401,
				array( 'status' => 'rejected', 'reason' => 'site_mismatch' ),
			),
			'unknown_plugin → 404 rejected'             => array(
				array( 'status' => 'rejected', 'reason' => 'unknown_plugin', 'http' => 404 ),
				404,
				array( 'status' => 'rejected', 'reason' => 'unknown_plugin' ),
			),
			'network_active_unsupported → 409 rejected (wire)' => array(
				array( 'status' => 'network_active_unsupported', 'http' => 409 ),
				409,
				array( 'status' => 'rejected', 'reason' => 'network_active_unsupported' ),
			),
			'expired → 410 rejected'                    => array(
				array( 'status' => 'rejected', 'reason' => 'expired', 'http' => 410 ),
				410,
				array( 'status' => 'rejected', 'reason' => 'expired' ),
			),
			'replayed → 410 rejected'                   => array(
				array( 'status' => 'rejected', 'reason' => 'replayed', 'http' => 410 ),
				410,
				array( 'status' => 'rejected', 'reason' => 'replayed' ),
			),
			'rate_limited → 429 rejected'               => array(
				array( 'status' => 'rejected', 'reason' => 'rate_limited', 'http' => 429 ),
				429,
				array( 'status' => 'rejected', 'reason' => 'rate_limited' ),
			),
			'failed → 500 rejected (wire)'              => array(
				array( 'status' => 'failed', 'http' => 500 ),
				500,
				array( 'status' => 'rejected', 'reason' => 'failed' ),
			),
		);
	}

	/**
	 * The handler is a thin transport: no extra response keys beyond the exact
	 * dispatcher result mapping (no internal details, no exception text).
	 *
	 * @return void
	 */
	public function test_handler_adds_no_extra_response_keys(): void {
		$controller = new \Probe_REST_License_Command();
		$request    = $this->make_request( 'any body' );

		// Terminal status: body must be exactly { status }.
		\Probe_REST_License_Command::$fixed_result = array( 'status' => 'executed', 'http' => 200 );
		$response                                  = $controller->handle_command( $request );
		$this->assertSame(
			array( 'status' => 'executed' ),
			$response->get_data(),
			'Terminal body must have only status.'
		);

		// Rejection: body must be exactly { status, reason }.
		\Probe_REST_License_Command::$fixed_result = array( 'status' => 'rejected', 'reason' => 'malformed', 'http' => 400 );
		$response                                  = $controller->handle_command( $request );
		$this->assertSame(
			array( 'status' => 'rejected', 'reason' => 'malformed' ),
			$response->get_data(),
			'Rejection body must have only status + reason.'
		);

		// Dispatcher-internal terminal status with non-2xx http: the WIRE body is
		// still the uniform rejected shape (frozen contract — terminal-vs-retryable
		// never leaks into the HTTP body).
		\Probe_REST_License_Command::$fixed_result = array( 'status' => 'failed', 'http' => 500 );
		$response                                  = $controller->handle_command( $request );
		$this->assertSame(
			array( 'status' => 'rejected', 'reason' => 'failed' ),
			$response->get_data(),
			'A non-2xx dispatcher-terminal status must be rewritten to the uniform rejected wire shape.'
		);
	}

	/**
	 * Fail closed (critic finding 1): a NON-VOCABULARY status must never leak to
	 * the public wire. A registered command handler can return ANY string as its
	 * ack status (the dispatcher passes it through verbatim and maps unknown
	 * statuses to http 500) — the controller whitelists the candidate reason
	 * against the dispatcher's frozen HTTP_MAP vocabulary and emits the generic
	 * retryable 'failed' for anything unknown.
	 *
	 * @return void
	 */
	public function test_non_vocabulary_status_is_masked_as_failed_on_the_wire(): void {
		$controller = new \Probe_REST_License_Command();
		$request    = $this->make_request( 'any body' );

		// An internal token from a handler — must NOT reach the wire.
		\Probe_REST_License_Command::$fixed_result = array( 'status' => 'weird_internal_thing', 'http' => 500 );
		$response                                  = $controller->handle_command( $request );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame(
			array( 'status' => 'rejected', 'reason' => 'failed' ),
			$response->get_data(),
			'A non-vocabulary status must be masked as the generic "failed" reason on the wire.'
		);

		// Same for a non-vocabulary REASON key on a rejection-shaped result.
		\Probe_REST_License_Command::$fixed_result = array( 'status' => 'rejected', 'reason' => 'internal_error_xyz', 'http' => 400 );
		$response                                  = $controller->handle_command( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame(
			array( 'status' => 'rejected', 'reason' => 'failed' ),
			$response->get_data(),
			'A non-vocabulary reason must likewise be masked as "failed".'
		);
	}

	/**
	 * The raw body is passed verbatim to the dispatch() seam; the handler does NOT
	 * decode or pre-process the body before handing it to the dispatcher.
	 *
	 * @return void
	 */
	public function test_handler_passes_raw_body_to_dispatcher(): void {
		$raw_body = '{"payload":{"protocol":1},"signature":"abc"}';

		\Probe_REST_License_Command::$fixed_result = array( 'status' => 'rejected', 'reason' => 'malformed', 'http' => 400 );

		$controller = new \Probe_REST_License_Command();
		$controller->handle_command( $this->make_request( $raw_body ) );

		$this->assertSame(
			$raw_body,
			\Probe_REST_License_Command::$last_body,
			'Raw body must be passed verbatim to dispatch().'
		);
	}

	/**
	 * Oversized body (8193 bytes): the controller passes it through; the response
	 * maps to 400 malformed per the stub (the size gate lives in the dispatcher).
	 *
	 * @return void
	 */
	public function test_oversized_body_reaches_dispatcher_and_maps_to_400(): void {
		$oversized = str_repeat( 'x', 8193 );

		\Probe_REST_License_Command::$fixed_result = array( 'status' => 'rejected', 'reason' => 'malformed', 'http' => 400 );

		$controller = new \Probe_REST_License_Command();
		$response   = $controller->handle_command( $this->make_request( $oversized ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'malformed', $response->get_data()['reason'] );
		// The oversized body was forwarded (not truncated by the controller).
		$this->assertSame( $oversized, \Probe_REST_License_Command::$last_body );
	}

	/**
	 * Garbage JSON body is forwarded to the dispatcher; maps to 400 malformed.
	 *
	 * @return void
	 */
	public function test_garbage_json_body_maps_to_400_malformed(): void {
		\Probe_REST_License_Command::$fixed_result = array( 'status' => 'rejected', 'reason' => 'malformed', 'http' => 400 );

		$controller = new \Probe_REST_License_Command();
		$response   = $controller->handle_command( $this->make_request( '{not valid json]' ) );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Tampered-signature and unknown-plugin responses differ ONLY in the reason code
	 * — the body shape is identical ({ status: 'rejected', reason: '<code>' }).
	 * No internal details, no exception text.
	 *
	 * @return void
	 */
	public function test_rejection_responses_differ_only_in_reason_code(): void {
		$controller = new \Probe_REST_License_Command();
		$request    = $this->make_request( 'body' );

		\Probe_REST_License_Command::$fixed_result = array( 'status' => 'rejected', 'reason' => 'bad_signature', 'http' => 401 );
		$bad_sig_response                          = $controller->handle_command( $request );

		\Probe_REST_License_Command::$fixed_result = array( 'status' => 'rejected', 'reason' => 'unknown_plugin', 'http' => 404 );
		$unknown_response                          = $controller->handle_command( $request );

		$bad_sig_data = $bad_sig_response->get_data();
		$unknown_data = $unknown_response->get_data();

		// Both are rejections with the same structure.
		$this->assertSame( 'rejected', $bad_sig_data['status'] );
		$this->assertSame( 'rejected', $unknown_data['status'] );
		$this->assertArrayHasKey( 'reason', $bad_sig_data );
		$this->assertArrayHasKey( 'reason', $unknown_data );

		// The ONLY difference is the reason code.
		$this->assertNotSame( $bad_sig_data['reason'], $unknown_data['reason'] );
		$this->assertSame( array_keys( $bad_sig_data ), array_keys( $unknown_data ) );
	}

	/* ----------------------------------------------------------------------- *
	 * Helpers
	 * ----------------------------------------------------------------------- */

	/**
	 * Captures add_action() calls, recording rest_api_init callbacks only.
	 *
	 * @return void
	 */
	private function capture_add_action(): void {
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback = null ) {
				if ( 'rest_api_init' === $hook ) {
					$this->rest_api_init_callbacks[] = $callback;
				}
				return true;
			}
		);
	}

	/**
	 * Captures register_rest_route() calls into $this->registered_routes.
	 *
	 * @return void
	 */
	private function capture_register_rest_route(): void {
		Functions\when( 'register_rest_route' )->alias(
			function ( $namespace, $route = '', $args = array() ) {
				$this->registered_routes[] = array( $namespace, $route, $args );
				return true;
			}
		);
	}

	/**
	 * Builds a WP_REST_Request mock (of the guarded stub class, so the handler's
	 * WP_REST_Request type declaration is satisfied) exposing get_body().
	 *
	 * @param string $body The raw request body.
	 * @return \WP_REST_Request
	 */
	private function make_request( string $body ): \WP_REST_Request {
		$request = Mockery::mock( \WP_REST_Request::class );
		$request->shouldReceive( 'get_body' )->andReturn( $body );
		return $request;
	}

	/**
	 * Reads the registrar's private static controllers map via reflection.
	 *
	 * @return array<string, object>
	 */
	private function get_registered_controllers(): array {
		$property = new \ReflectionProperty( \Woodev_REST_V1_Registrar::class, 'controllers' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		return (array) $property->getValue();
	}

	/**
	 * Overwrites the registrar's private static controllers map via reflection.
	 *
	 * @param array<string, object> $controllers The new controllers map.
	 * @return void
	 */
	private function set_registrar_controllers( array $controllers ): void {
		$property = new \ReflectionProperty( \Woodev_REST_V1_Registrar::class, 'controllers' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( null, $controllers );
	}

	/**
	 * Resets the registrar's static controllers map + single-hook guard.
	 *
	 * @return void
	 */
	private function reset_registrar_statics(): void {
		foreach ( array( 'controllers' => array(), 'hooked' => false ) as $name => $value ) {
			$property = new \ReflectionProperty( \Woodev_REST_V1_Registrar::class, $name );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$property->setValue( null, $value );
		}
	}

	/**
	 * Resets the command controller's idempotent-boot guard.
	 *
	 * @return void
	 */
	private function reset_command_controller_statics(): void {
		$property = new \ReflectionProperty( \Woodev_REST_API_License_Command::class, 'booted' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( null, false );
	}

	/**
	 * Resets the license controller's idempotent-boot guard (to avoid cross-test pollution).
	 *
	 * @return void
	 */
	private function reset_license_controller_statics(): void {
		$property = new \ReflectionProperty( \Woodev_REST_API_License::class, 'booted' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( null, false );
	}
}

}
