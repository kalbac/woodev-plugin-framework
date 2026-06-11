<?php
/**
 * REST license controller + woodev/v1 registrar tests.
 *
 * Covers the thin REST transport layer over the s6-p1 pure operations:
 *  - the reusable Woodev_REST_V1_Registrar (single rest_api_init hook, dedupe);
 *  - the Woodev_REST_API_License controller (four routes, permission callback,
 *    plugin resolution, typed arg schema, no-silent-failure error mapping);
 *  - the B-7 invariant: the whole layer boots and handles a request with ZERO
 *    WooCommerce functions defined (Brain Monkey defines none) — the licensing
 *    REST surface is WC-agnostic.
 *
 * register_rest_route / add_action are captured (not executed) so the route map,
 * the namespace string, the permission/args schema, and the single-hook guarantee
 * can be asserted structurally. The static guards on both classes are reset via
 * reflection in setUp/tearDown (gotcha testing/reflection-setaccessible-version-guard).
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

	/**
	 * Minimal WP_Error stand-in for unit context (no WordPress loaded).
	 *
	 * Only the surface the controller and the assertions touch is implemented:
	 * the (code, message, data) constructor + get_error_code()/get_error_message()/
	 * get_error_data() accessors. Guarded so a real WP_Error wins if ever present
	 * (and so a stub from a sibling test file in the same process is not redeclared).
	 */
	if ( ! class_exists( 'WP_Error', false ) ) {
		class WP_Error {

			/** @var string */
			public $code;

			/** @var string */
			public $message;

			/** @var array<string, mixed> */
			public $data;

			/**
			 * @param string               $code    Error code.
			 * @param string               $message Error message.
			 * @param array<string, mixed> $data    Error data.
			 */
			public function __construct( $code = '', $message = '', $data = array() ) {
				$this->code    = $code;
				$this->message = $message;
				$this->data    = $data;
			}

			/** @return string */
			public function get_error_code() {
				return $this->code;
			}

			/** @return string */
			public function get_error_message() {
				return $this->message;
			}

			/** @return array<string, mixed> */
			public function get_error_data() {
				return $this->data;
			}
		}
	}
}

namespace Woodev\Tests\Unit {

use Mockery;
use Brain\Monkey\Functions;

/**
 * Class LicenseRestControllerTest.
 */
class LicenseRestControllerTest extends TestCase {

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
	 * Resets the registrar + controller static guards and the capture buffers.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->registered_routes       = array();
		$this->rest_api_init_callbacks = array();

		$this->reset_registrar_statics();
		$this->reset_controller_statics();
		$this->reset_license_registry();
	}

	/**
	 * Resets the static state again so a later suite is never polluted.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->reset_registrar_statics();
		$this->reset_controller_statics();
		$this->reset_license_registry();
		parent::tearDown();
	}

	/* ----------------------------------------------------------------------- *
	 * Registrar
	 * ----------------------------------------------------------------------- */

	/**
	 * register_controller() hooks rest_api_init EXACTLY once no matter how many
	 * distinct controllers register, and handle_rest_api_init() then calls
	 * register_routes() on each one.
	 *
	 * @return void
	 */
	public function test_registrar_hooks_rest_api_init_once_for_multiple_controllers(): void {
		$this->capture_add_action();

		// Two DISTINCT controller classes (named mocks) so the per-class dedupe
		// keeps both — a type-less Mockery::mock() can collapse to one generated
		// class, which would defeat the "N controllers" assertion.
		$controller_a = Mockery::mock( 'Woodev_Test_Rest_Controller_A' );
		$controller_a->shouldReceive( 'register_routes' )->once();

		$controller_b = Mockery::mock( 'Woodev_Test_Rest_Controller_B' );
		$controller_b->shouldReceive( 'register_routes' )->once();

		\Woodev_REST_V1_Registrar::register_controller( $controller_a );
		\Woodev_REST_V1_Registrar::register_controller( $controller_b );

		// Exactly one rest_api_init hook for N controllers.
		$this->assertCount( 1, $this->rest_api_init_callbacks );
		// Both distinct controllers are stored.
		$this->assertCount( 2, $this->get_registered_controllers() );

		// Firing the hook dispatches register_routes() on every stored controller.
		$this->fire_rest_api_init();
	}

	/**
	 * register_controller() dedupes by controller CLASS: registering the same
	 * concrete class twice stores a single instance, so register_routes() runs once.
	 *
	 * @return void
	 */
	public function test_registrar_dedupes_repeated_controller_class(): void {
		$this->capture_add_action();

		$first  = new \Woodev_REST_API_License();
		$second = new \Woodev_REST_API_License();

		\Woodev_REST_V1_Registrar::register_controller( $first );
		\Woodev_REST_V1_Registrar::register_controller( $second );

		$this->assertCount( 1, $this->rest_api_init_callbacks );

		$controllers = $this->get_registered_controllers();
		$this->assertCount( 1, $controllers );
		// The first registration wins (dedupe keeps the original instance).
		$this->assertSame( $first, reset( $controllers ) );
	}

	/**
	 * The namespace constant is exactly 'woodev/v1' (contract).
	 *
	 * @return void
	 */
	public function test_route_namespace_constant_is_woodev_v1(): void {
		$this->assertSame( 'woodev/v1', \Woodev_REST_V1_Registrar::ROUTE_NAMESPACE );
	}

	/* ----------------------------------------------------------------------- *
	 * boot()
	 * ----------------------------------------------------------------------- */

	/**
	 * boot() registers exactly one controller; a second boot() is a no-op (the
	 * static guard prevents a duplicate registration).
	 *
	 * @return void
	 */
	public function test_boot_is_idempotent(): void {
		$this->capture_add_action();

		\Woodev_REST_API_License::boot();
		\Woodev_REST_API_License::boot();

		$this->assertCount( 1, $this->rest_api_init_callbacks );
		$this->assertCount( 1, $this->get_registered_controllers() );
	}

	/* ----------------------------------------------------------------------- *
	 * register_routes()
	 * ----------------------------------------------------------------------- */

	/**
	 * register_routes() registers the exact four routes under the woodev/v1
	 * namespace, each carrying a permission_callback, with the documented methods
	 * and the typed arg schema for license_key (verify) and enabled (beta).
	 *
	 * @return void
	 */
	public function test_register_routes_registers_four_namespaced_routes_with_schema(): void {
		// NIT 1: exercise (not just inspect) each route's permission_callback —
		// with manage_options denied, every entry must return the forbidden WP_Error.
		Functions\when( 'rest_authorization_required_code' )->justReturn( 401 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->capture_register_rest_route();

		( new \Woodev_REST_API_License() )->register_routes();

		$this->assertCount( 4, $this->registered_routes );

		// Every route lives under the EXACT contract namespace and carries a
		// permission callback on each method entry — and that callback actually
		// denies (woodev_license_forbidden) when the user lacks manage_options.
		foreach ( $this->registered_routes as $call ) {
			$this->assertSame( 'woodev/v1', $call[0] );
			$entries = $this->normalize_route_entries( $call[2] );
			foreach ( $entries as $entry ) {
				$this->assertArrayHasKey( 'permission_callback', $entry );
				$this->assertIsCallable( $entry['permission_callback'] );

				$denied = \call_user_func( $entry['permission_callback'] );
				$this->assertInstanceOf( \WP_Error::class, $denied );
				$this->assertSame( 'woodev_license_forbidden', $denied->get_error_code() );
			}
		}

		$routes = $this->indexed_routes();

		// GET state.
		$this->assertArrayHasKey( '/licenses/(?P<plugin_id>[\w-]+)', $routes );
		$this->assertSame( 'GET', $this->first_method( $routes['/licenses/(?P<plugin_id>[\w-]+)'] ) );

		// POST verify with the license_key schema.
		$verify = $routes['/licenses/(?P<plugin_id>[\w-]+)/verify'];
		$this->assertArrayHasKey( '/licenses/(?P<plugin_id>[\w-]+)/verify', $routes );
		$this->assertSame( 'POST', $this->first_method( $verify ) );
		$verify_entry = $this->normalize_route_entries( $verify )[0];
		$this->assertArrayHasKey( 'args', $verify_entry );
		$this->assertArrayHasKey( 'license_key', $verify_entry['args'] );
		$license_key_arg = $verify_entry['args']['license_key'];
		$this->assertSame( 'string', $license_key_arg['type'] );
		$this->assertTrue( $license_key_arg['required'] );
		$this->assertSame( 'sanitize_text_field', $license_key_arg['sanitize_callback'] );
		$this->assertIsCallable( $license_key_arg['validate_callback'] );

		// POST deactivate.
		$this->assertArrayHasKey( '/licenses/(?P<plugin_id>[\w-]+)/deactivate', $routes );
		$this->assertSame( 'POST', $this->first_method( $routes['/licenses/(?P<plugin_id>[\w-]+)/deactivate'] ) );

		// POST beta with the enabled schema.
		$beta = $routes['/licenses/(?P<plugin_id>[\w-]+)/beta'];
		$this->assertArrayHasKey( '/licenses/(?P<plugin_id>[\w-]+)/beta', $routes );
		$this->assertSame( 'POST', $this->first_method( $beta ) );
		$beta_entry = $this->normalize_route_entries( $beta )[0];
		$this->assertArrayHasKey( 'enabled', $beta_entry['args'] );
		$this->assertSame( 'boolean', $beta_entry['args']['enabled']['type'] );
		$this->assertTrue( $beta_entry['args']['enabled']['required'] );
	}

	/**
	 * The license_key validate_callback rejects an empty string, a
	 * whitespace-only string, a non-string, and — because it validates the
	 * SANITIZED value — markup that sanitizes to '' (e.g. '<script></script>'),
	 * while accepting a real key.
	 *
	 * @return void
	 */
	public function test_license_key_validate_callback_rejects_empty_and_whitespace(): void {
		// validate_callback runs sanitize_text_field() on the value first; stub it
		// to strip tags + trim so '<script></script>' collapses to '' as in WP.
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $value ): string {
				return trim( strip_tags( (string) $value ) );
			}
		);

		$this->capture_register_rest_route();

		( new \Woodev_REST_API_License() )->register_routes();

		$routes   = $this->indexed_routes();
		$validate = $this->normalize_route_entries( $routes['/licenses/(?P<plugin_id>[\w-]+)/verify'] )[0]['args']['license_key']['validate_callback'];

		$this->assertFalse( $validate( '' ) );
		$this->assertFalse( $validate( '   ' ) );
		$this->assertFalse( $validate( 123 ) );
		// MUST-FIX 2: markup that sanitizes to '' is rejected at the validate layer
		// (raw '<script></script>' is non-empty, but the SANITIZED form is '').
		$this->assertFalse( $validate( '<script></script>' ) );
		$this->assertTrue( $validate( 'KEY-123' ) );
	}

	/**
	 * MUST-FIX 1: the `enabled` validate_callback is strict — it accepts only real
	 * JSON booleans (true/false) and rejects WP's loose boolean coercions
	 * ('true' string, 1 int, 'banana', null). Exercised through the ROUTE's
	 * captured args schema (the beta route), not a hand-built callback.
	 *
	 * @return void
	 */
	public function test_enabled_validate_callback_accepts_only_real_booleans(): void {
		$this->capture_register_rest_route();

		( new \Woodev_REST_API_License() )->register_routes();

		$routes        = $this->indexed_routes();
		$beta_entry    = $this->normalize_route_entries( $routes['/licenses/(?P<plugin_id>[\w-]+)/beta'] )[0];
		$enabled_arg   = $beta_entry['args']['enabled'];

		// MUST-FIX 5 (a): the route's enabled schema is a required boolean.
		$this->assertSame( 'boolean', $enabled_arg['type'] );
		$this->assertTrue( $enabled_arg['required'] );

		// MUST-FIX 5 (b): rejection path is exercised through the ROUTE's captured
		// validate_callback, not a hand-built one.
		$this->assertArrayHasKey( 'validate_callback', $enabled_arg );
		$validate = $enabled_arg['validate_callback'];
		$this->assertIsCallable( $validate );

		// Loose coercions are rejected — only real JSON booleans pass.
		$this->assertFalse( $validate( 'true' ) );
		$this->assertFalse( $validate( 1 ) );
		$this->assertFalse( $validate( 'banana' ) );
		$this->assertFalse( $validate( null ) );

		$this->assertTrue( $validate( true ) );
		$this->assertTrue( $validate( false ) );
	}

	/* ----------------------------------------------------------------------- *
	 * Permission callback
	 * ----------------------------------------------------------------------- */

	/**
	 * The permission callback returns a 401/403 WP_Error when the user lacks
	 * manage_options, and true when they have it.
	 *
	 * @return void
	 */
	public function test_permission_callback_enforces_manage_options(): void {
		Functions\when( 'rest_authorization_required_code' )->justReturn( 401 );

		$controller = new \Woodev_REST_API_License();

		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( false );
		$denied = $controller->check_permissions();
		$this->assertInstanceOf( \WP_Error::class, $denied );
		$this->assertSame( 'woodev_license_forbidden', $denied->code );
		$this->assertNotSame( '', $denied->message );
		$this->assertSame( 401, $denied->data['status'] );

		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( true );
		$this->assertTrue( $controller->check_permissions() );
	}

	/* ----------------------------------------------------------------------- *
	 * Plugin resolution
	 * ----------------------------------------------------------------------- */

	/**
	 * An unknown plugin_id resolves to a 404 WP_Error rather than a fatal.
	 *
	 * @return void
	 */
	public function test_unknown_plugin_id_returns_404_wp_error(): void {
		$controller = new \Woodev_REST_API_License();

		$request = $this->make_request( array( 'plugin_id' => 'nope' ) );

		$result = $controller->get_item( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'woodev_license_unknown_plugin', $result->code );
		$this->assertSame( 404, $result->data['status'] );
		$this->assertNotSame( '', $result->message );
	}

	/* ----------------------------------------------------------------------- *
	 * Happy paths — each handler calls the matching pure op once and returns state
	 * ----------------------------------------------------------------------- */

	/**
	 * GET state: resolves the engine and returns its get_state() array verbatim.
	 *
	 * @return void
	 */
	public function test_get_item_returns_engine_state(): void {
		$state = array( 'plugin_id' => '216', 'status' => 'valid' );

		$engine = $this->make_engine_mock();
		$engine->shouldReceive( 'get_state' )->once()->andReturn( $state );
		$this->seed_license_registry( '216', $engine );

		Functions\when( 'rest_ensure_response' )->returnArg();

		$controller = new \Woodev_REST_API_License();
		$result     = $controller->get_item( $this->make_request( array( 'plugin_id' => '216' ) ) );

		$this->assertSame( $state, $result );
	}

	/**
	 * POST verify: calls activate() with the submitted key EXACTLY once and
	 * returns the resulting state.
	 *
	 * @return void
	 */
	public function test_verify_calls_activate_once_and_returns_state(): void {
		$state = array( 'plugin_id' => '216', 'status' => 'valid' );

		$engine = $this->make_engine_mock();
		$engine->shouldReceive( 'activate' )->once()->with( 'KEY-123' )->andReturn( $state );
		$this->seed_license_registry( '216', $engine );

		Functions\when( 'rest_ensure_response' )->returnArg();

		$controller = new \Woodev_REST_API_License();
		$result     = $controller->verify_item(
			$this->make_request(
				array(
					'plugin_id'   => '216',
					'license_key' => 'KEY-123',
				)
			)
		);

		$this->assertSame( $state, $result );
	}

	/**
	 * POST deactivate: calls deactivate() EXACTLY once and returns the state.
	 *
	 * @return void
	 */
	public function test_deactivate_calls_deactivate_once_and_returns_state(): void {
		$state = array( 'plugin_id' => '216', 'status' => '' );

		$engine = $this->make_engine_mock();
		$engine->shouldReceive( 'deactivate' )->once()->andReturn( $state );
		$this->seed_license_registry( '216', $engine );

		Functions\when( 'rest_ensure_response' )->returnArg();

		$controller = new \Woodev_REST_API_License();
		$result     = $controller->deactivate_item( $this->make_request( array( 'plugin_id' => '216' ) ) );

		$this->assertSame( $state, $result );
	}

	/**
	 * POST beta: calls set_beta_enabled(true) EXACTLY once, then get_state() once,
	 * and returns the state.
	 *
	 * @return void
	 */
	public function test_beta_calls_set_beta_enabled_then_returns_state(): void {
		$state = array( 'plugin_id' => '216', 'beta_enabled' => true );

		$engine = $this->make_engine_mock();
		$engine->shouldReceive( 'set_beta_enabled' )->once()->with( true );
		$engine->shouldReceive( 'get_state' )->once()->andReturn( $state );
		$this->seed_license_registry( '216', $engine );

		Functions\when( 'rest_ensure_response' )->returnArg();

		$controller = new \Woodev_REST_API_License();
		$result     = $controller->set_beta(
			$this->make_request(
				array(
					'plugin_id' => '216',
					'enabled'   => true,
				)
			)
		);

		$this->assertSame( $state, $result );
	}

	/* ----------------------------------------------------------------------- *
	 * No silent failure
	 * ----------------------------------------------------------------------- */

	/**
	 * A Woodev_Plugin_Exception from a pure op surfaces its (non-empty) message in
	 * a 502 WP_Error — never a silent success.
	 *
	 * @return void
	 */
	public function test_op_exception_maps_to_wp_error_with_message(): void {
		$engine = $this->make_engine_mock();
		$engine->shouldReceive( 'activate' )->once()->andThrow( new \Woodev_Plugin_Exception( 'Сервер недоступен' ) );
		$this->seed_license_registry( '216', $engine );

		$controller = new \Woodev_REST_API_License();
		$result     = $controller->verify_item(
			$this->make_request(
				array(
					'plugin_id'   => '216',
					'license_key' => 'KEY-123',
				)
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'woodev_license_request_failed', $result->code );
		$this->assertSame( 'Сервер недоступен', $result->message );
		$this->assertSame( 502, $result->data['status'] );
	}

	/**
	 * A throwable with an EMPTY message must NOT yield an empty error message —
	 * the controller substitutes a non-empty Russian fallback (no silent/empty
	 * failure).
	 *
	 * @return void
	 */
	public function test_op_exception_with_empty_message_uses_non_empty_fallback(): void {
		$engine = $this->make_engine_mock();
		$engine->shouldReceive( 'deactivate' )->once()->andThrow( new \Exception( '' ) );
		$this->seed_license_registry( '216', $engine );

		$controller = new \Woodev_REST_API_License();
		$result     = $controller->deactivate_item( $this->make_request( array( 'plugin_id' => '216' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'woodev_license_request_failed', $result->code );
		$this->assertNotSame( '', $result->message );
		$this->assertSame( 502, $result->data['status'] );
	}

	/**
	 * MUST-FIX 3: a throwable whose message is WHITESPACE-ONLY ('   ') must not
	 * yield a visually blank WP_Error — the controller trims the message, sees it
	 * is empty, and substitutes the non-empty Russian fallback.
	 *
	 * @return void
	 */
	public function test_op_exception_with_whitespace_message_uses_non_empty_fallback(): void {
		$engine = $this->make_engine_mock();
		$engine->shouldReceive( 'deactivate' )->once()->andThrow( new \Exception( '   ' ) );
		$this->seed_license_registry( '216', $engine );

		$controller = new \Woodev_REST_API_License();
		$result     = $controller->deactivate_item( $this->make_request( array( 'plugin_id' => '216' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'woodev_license_request_failed', $result->code );
		// The trimmed message is the non-empty Russian fallback, not blank/whitespace.
		$this->assertNotSame( '', trim( $result->message ) );
		$this->assertSame( 'Не удалось выполнить запрос к серверу лицензий.', $result->message );
		$this->assertSame( 502, $result->data['status'] );
	}

	/* ----------------------------------------------------------------------- *
	 * B-7 — WooCommerce-agnostic
	 * ----------------------------------------------------------------------- */

	/**
	 * B-7: the licensing REST layer is WooCommerce-agnostic. This runs in a
	 * SEPARATE PROCESS (gotcha testing/brain-monkey-function-pollution: an
	 * earlier test that `expect`/`when`s a wc_* function would otherwise have
	 * DEFINED it for the rest of this process, making the absence assertion below
	 * vacuously fail). In the clean child process we first PROVE WooCommerce is
	 * entirely absent — no WC() / wc_get_order() functions, no WooCommerce class —
	 * and only then boot the layer, register routes, and run a verify happy path,
	 * asserting the request completed (a state array came back). If any code path
	 * reached for WooCommerce, the absence asserts would fail or the call would
	 * fatal.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_b7_layer_works_with_zero_woocommerce_present(): void {
		// Prove WooCommerce is genuinely absent in this fresh process BEFORE we
		// exercise anything — this is the real WC-agnosticism invariant.
		$this->assertFalse( function_exists( 'WC' ), 'WC() must be undefined for the agnosticism test.' );
		$this->assertFalse( function_exists( 'wc_get_order' ), 'wc_get_order() must be undefined.' );
		$this->assertFalse( class_exists( 'WooCommerce', false ), 'WooCommerce class must be absent.' );

		$this->capture_add_action();
		$this->capture_register_rest_route();

		// boot + registration path.
		\Woodev_REST_API_License::boot();
		$this->fire_rest_api_init();

		$this->assertTrue( class_exists( '\Woodev_REST_API_License' ) );
		$this->assertTrue( class_exists( '\Woodev_REST_V1_Registrar' ) );
		$this->assertCount( 4, $this->registered_routes );

		// a verify request happy path — stub only core WP helpers via Brain Monkey.
		$state  = array( 'plugin_id' => '216', 'status' => 'valid' );
		$engine = $this->make_engine_mock();
		$engine->shouldReceive( 'activate' )->once()->with( 'KEY-123' )->andReturn( $state );
		$this->seed_license_registry( '216', $engine );

		Functions\when( 'rest_ensure_response' )->returnArg();

		$result = ( new \Woodev_REST_API_License() )->verify_item(
			$this->make_request(
				array(
					'plugin_id'   => '216',
					'license_key' => 'KEY-123',
				)
			)
		);

		// The request completed end-to-end: a state array was returned.
		$this->assertIsArray( $result );
		$this->assertSame( $state, $result );
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
	 * Fires every captured rest_api_init callback (after capturing routes too).
	 *
	 * @return void
	 */
	private function fire_rest_api_init(): void {
		foreach ( $this->rest_api_init_callbacks as $callback ) {
			\call_user_func( $callback );
		}
	}

	/**
	 * Builds a Mockery double of the license engine that satisfies the
	 * ?Woodev_Plugins_License return type of get_registered_instance().
	 *
	 * @return \Mockery\MockInterface
	 */
	private function make_engine_mock() {
		return Mockery::mock( \Woodev_Plugins_License::class );
	}

	/**
	 * Builds a lightweight WP_REST_Request stand-in exposing get_param() and
	 * ArrayAccess-style reads over the seeded params.
	 *
	 * @param array<string, mixed> $params Request params.
	 * @return object
	 */
	private function make_request( array $params ) {
		$request = Mockery::mock();
		$request->shouldReceive( 'get_param' )->andReturnUsing(
			static function ( $key ) use ( $params ) {
				return $params[ $key ] ?? null;
			}
		);
		$request->shouldReceive( 'offsetGet' )->andReturnUsing(
			static function ( $key ) use ( $params ) {
				return $params[ $key ] ?? null;
			}
		);

		return $request;
	}

	/**
	 * Normalizes a register_rest_route args array to a list of method-entry arrays.
	 *
	 * WP allows either a single endpoint array or a list of endpoint arrays. The
	 * controller registers a single endpoint per route, so wrap it in a list when
	 * it is not already one.
	 *
	 * @param array<string, mixed> $args register_rest_route args.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_route_entries( array $args ): array {
		// A list-of-endpoints has integer keys; a single endpoint has 'methods'.
		if ( array_key_exists( 'methods', $args ) ) {
			return array( $args );
		}

		return array_values(
			array_filter(
				$args,
				static function ( $value ) {
					return is_array( $value ) && array_key_exists( 'methods', $value );
				}
			)
		);
	}

	/**
	 * Returns the first method string of a route's single endpoint.
	 *
	 * @param array<string, mixed> $args register_rest_route args.
	 * @return string
	 */
	private function first_method( array $args ): string {
		$entry  = $this->normalize_route_entries( $args )[0];
		$method = $entry['methods'];

		return is_array( $method ) ? (string) reset( $method ) : (string) $method;
	}

	/**
	 * Indexes the captured routes by their route regex.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function indexed_routes(): array {
		$indexed = array();
		foreach ( $this->registered_routes as $call ) {
			$indexed[ $call[1] ] = $call[2];
		}

		return $indexed;
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
	 * Resets the controller's idempotent-boot guard.
	 *
	 * @return void
	 */
	private function reset_controller_statics(): void {
		$property = new \ReflectionProperty( \Woodev_REST_API_License::class, 'booted' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( null, false );
	}

	/**
	 * Seeds one engine into the Woodev_Plugins_License static registry.
	 *
	 * @param string $plugin_id Download id key.
	 * @param object $engine    License engine (mock).
	 * @return void
	 */
	private function seed_license_registry( string $plugin_id, $engine ): void {
		$property = new \ReflectionProperty( \Woodev_Plugins_License::class, 'registered_instances' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$registry               = (array) $property->getValue();
		$registry[ $plugin_id ] = $engine;
		$property->setValue( null, $registry );
	}

	/**
	 * Empties the Woodev_Plugins_License static registry.
	 *
	 * @return void
	 */
	private function reset_license_registry(): void {
		$property = new \ReflectionProperty( \Woodev_Plugins_License::class, 'registered_instances' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( null, array() );
	}
}

}
