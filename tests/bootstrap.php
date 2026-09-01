<?php
/**
 * PHPUnit Bootstrap File
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$test_suite = getenv( 'TEST_SUITE' ) ?: 'unit';

if ( 'integration' === $test_suite ) {
	bootstrap_integration_tests();
} else {
	// Load Patchwork BEFORE any framework source file. Brain Monkey only loads it
	// lazily at the first Monkey\setUp() — but PHPUnit loads every test file (and
	// the source files they require_once) at suite-BUILD time, before any setUp
	// runs, so call sites in those source files would never be instrumented and
	// the redefinable-internals from patchwork.json (function_exists, error_log)
	// could not be stubbed where it matters. Patchwork's own docs: load it as
	// early as possible.
	require_once dirname( __DIR__ ) . '/vendor/antecedent/patchwork/Patchwork.php';

	// Unit tests need ABSPATH defined (no WordPress loaded).
	defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

	// WordPress core time constants the licensing code relies on (no WP loaded).
	defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
	defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
	defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );
	defined( 'WEEK_IN_SECONDS' ) || define( 'WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS );
	defined( 'MONTH_IN_SECONDS' ) || define( 'MONTH_IN_SECONDS', 30 * DAY_IN_SECONDS );
	defined( 'YEAR_IN_SECONDS' ) || define( 'YEAR_IN_SECONDS', 365 * DAY_IN_SECONDS );

	// Minimal WP_Error stand-in for unit context (no WordPress loaded). Only the
	// (code, message, data) constructor + accessors the assertions touch. Guarded so
	// a real WP_Error wins if present and a sibling test file's stub is not redeclared.
	// Shared here so any single test file run in isolation has WP_Error available.
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

	// Minimal WP_REST_Request stand-in for unit context (no WordPress loaded).
	//
	// SHARED ON PURPOSE (issue #140). Four test files used to declare their own
	// `WP_REST_Request`. Measured before changing anything, because the card's stated
	// mechanism turned out to be only half right:
	//
	//   LicenseCommandRestTest              GLOBAL         \WP_REST_Request
	//   LocationDefaultCountrySeamTest      namespaced     Woodev\Tests\Unit\Shipping\...
	//   LocationControllerTest              namespaced     Woodev\Tests\Unit\Shipping\Rest_Api\...
	//   PickupControllerTest                namespaced     SAME namespace as the line above
	//
	// So there was NO global-versus-namespaced race: the three shipping doubles were
	// made namespace-scoped deliberately, exactly to avoid colliding with the global one.
	// Two real problems remained, and this shared stub fixes both:
	//
	// 1. The last two share ONE namespace, so they are two declarations of one class and
	//    the first file loaded wins. Their bodies happen to be identical today, which is
	//    why nothing has broken yet — but the moment one needs a method the other lacks,
	//    PHPUnit's traversal order decides whether the suite passes.
	// 2. A namespace-scoped double CANNOT satisfy a global `\WP_REST_Request` parameter
	//    type. That — not a race — is why the REST callbacks in `Pickup_Controller`,
	//    `Field_Source_Controller` and `Location_Controller` carried no type declaration
	//    at all, and why adding the correct hint during SP-5 fatally broke four tests.
	//    The project's own "types on every parameter" rule (AGENT-RULES Rule 4) was being
	//    violated by a TEST, not by the code.
	//
	// One global stub, declared before any test file loads, removes both.
	//
	// The body below is the UNION of what those four declared, so every previous caller
	// keeps working:
	//   - `$body` / `get_body()`      — LicenseCommandRestTest
	//   - `get_param()`               — all three shipping tests, identical implementation
	//   - `get_header()`              — ditto; the seam test passed no headers and always
	//                                   got null, which an empty `$headers` reproduces
	//
	// ⚠ `get_header()` deliberately does NOT normalise the key, unlike the real
	// \WP_REST_Request. That was PickupControllerTest's explicit choice and it is
	// load-bearing: a production caller asking for a differently-cased header than the
	// browser sends must MISS here, so the test fails instead of passing by luck.
	//
	// Guarded so a real WP_REST_Request wins if one is ever present.
	if ( ! class_exists( 'WP_REST_Request', false ) ) {
		class WP_REST_Request {

			/** @var string raw request body. */
			public $body = '';

			/** @var array<string, mixed> */
			private $params;

			/** @var array<string, string> */
			private $headers;

			/**
			 * @param array<string, mixed>  $params  request params.
			 * @param array<string, string> $headers request headers, keyed exactly as
			 *                                       {@see self::get_header()} is asked for them.
			 * @param string                $body    raw request body.
			 */
			public function __construct( array $params = [], array $headers = [], string $body = '' ) {
				$this->params  = $params;
				$this->headers = $headers;
				$this->body    = $body;
			}

			/**
			 * @param string $key param name.
			 * @return mixed
			 */
			public function get_param( $key ) {
				return $this->params[ $key ] ?? null;
			}

			/**
			 * @param string $key header name, NOT normalised — see the note above.
			 * @return string|null
			 */
			public function get_header( $key ) {
				return $this->headers[ $key ] ?? null;
			}

			/** @return string */
			public function get_body() {
				return $this->body;
			}
		}
	}

	bootstrap_unit_tests();
}

function bootstrap_unit_tests(): void {
	echo "Running Unit Tests (Brain Monkey)\n";
}

function bootstrap_integration_tests(): void {
	echo "Running Integration Tests (WP_UnitTestCase)\n";

	// Prefer composer-managed wp-phpunit, fall back to wp-env container path.
	$composer_wp_phpunit = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
	$wp_tests_dir       = getenv( 'WP_TESTS_DIR' )
		?: ( is_dir( $composer_wp_phpunit ) ? $composer_wp_phpunit : '/wordpress-phpunit' );

	if ( ! file_exists( $wp_tests_dir . '/includes/functions.php' ) ) {
		echo "ERROR: WordPress test library not found at {$wp_tests_dir}\n";
		echo "Make sure wp-env is running: npx wp-env start\n";
		exit( 1 );
	}

	require_once $wp_tests_dir . '/includes/functions.php';

	// Активируем WooCommerce перед загрузкой наших тестовых плагинов.
	// Это гарантирует что WC_VERSION определена и WC классы доступны.
	tests_add_filter( 'muplugins_loaded', function() {

		// Загружаем WooCommerce.
		$wc_plugin = WP_PLUGIN_DIR . '/woocommerce.latest-stable/woocommerce.php';
		if ( ! file_exists( $wc_plugin ) ) {
			$wc_plugin = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		}
		if ( file_exists( $wc_plugin ) ) {
			require_once $wc_plugin;

			// Simulate WooCommerce being activated so that Woodev_Helper::is_woocommerce_active()
			// returns true. In the WP test environment plugins are loaded via require_once, not
			// through WordPress activation, so active_plugins option is empty by default.
			$active_plugins = (array) get_option( 'active_plugins', [] );
			if ( ! in_array( 'woocommerce/woocommerce.php', $active_plugins, true ) ) {
				$active_plugins[] = 'woocommerce/woocommerce.php';
				update_option( 'active_plugins', $active_plugins );
			}
		}

		$fixtures_dir = dirname( __DIR__ ) . '/tests/_fixtures';

		// 1. Общий плагин — базовый функционал фреймворка.
		require_once $fixtures_dir . '/woodev-test-plugin/woodev-test-plugin.php';

		// 2. Тестовый платёжный шлюз.
		require_once $fixtures_dir . '/woodev-test-payment-gateway/woodev-test-payment-gateway.php';

		// 3. Тестовый метод доставки.
		require_once $fixtures_dir . '/woodev-test-shipping-method/woodev-test-shipping-method.php';
	} );

	require_once $wp_tests_dir . '/includes/bootstrap.php';
}
