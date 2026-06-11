<?php
/**
 * B-3 keyless-updater polling tests.
 *
 * The updater is the §4 claim transport for keyless products: it must be
 * constructed in every admin / cron / WP-CLI context EVEN WITHOUT a license key,
 * the public woodev_plugin_updater hook must keep firing with the key arg
 * byte-for-byte, get_api_params() must gain a raw 'url' => home_url() additively,
 * and get_version_from_remote() must feed any 'license_authority' envelope in the
 * parsed response to the claim store without ever letting a consumption Throwable
 * break the update flow.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Mockery;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 2 ) . '/woodev/plugin-updater/class-plugin-updater.php';

/**
 * Class UpdaterKeylessPollingTest.
 */
class UpdaterKeylessPollingTest extends TestCase {

	/**
	 * load_updater(): no key + is_admin() true → updater IS constructed, and the
	 * woodev_plugin_updater hook fires with the (empty) key arg.
	 *
	 * @return void
	 */
	public function test_admin_no_key_constructs_updater_and_fires_hook(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_cron' )->justReturn( false );

		$constructed = $this->run_load_updater( '' );

		$this->assertTrue( $constructed, 'Updater must be constructed in admin context even without a license key.' );
		$this->assertSame( 1, did_action( 'woodev_plugin_updater' ) );
	}

	/**
	 * load_updater(): no key + cron (is_admin false, wp_doing_cron true) → constructed.
	 *
	 * @return void
	 */
	public function test_cron_no_key_constructs_updater(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( true );

		$constructed = $this->run_load_updater( '' );

		$this->assertTrue( $constructed, 'Updater must be constructed in cron context even without a license key.' );
		$this->assertSame( 1, did_action( 'woodev_plugin_updater' ) );
	}

	/**
	 * load_updater(): plain frontend (neither admin nor cron nor CLI) → NOT constructed
	 * and the hook does NOT fire.
	 *
	 * @return void
	 */
	public function test_frontend_does_not_construct_updater(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );

		$constructed = $this->run_load_updater( 'KEY-123' );

		$this->assertFalse( $constructed, 'Updater must NOT be constructed on a plain frontend request.' );
		$this->assertSame( 0, did_action( 'woodev_plugin_updater' ) );
	}

	/**
	 * load_updater(): the woodev_plugin_updater hook receives the license KEY arg
	 * byte-for-byte (public hook contract) — a present key is passed through.
	 *
	 * @return void
	 */
	public function test_hook_fires_with_license_key_arg(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_cron' )->justReturn( false );

		$captured = null;
		Actions\expectDone( 'woodev_plugin_updater' )->once()->whenHappen(
			static function ( $key ) use ( &$captured ) {
				$captured = $key;
			}
		);

		$this->run_load_updater( 'KEY-123' );

		$this->assertSame( 'KEY-123', $captured );
	}

	/**
	 * get_api_params(): gains 'url' => home_url() (raw) and keeps every existing key
	 * byte-for-byte (edd_action, license, item_id, version, slug, beta, php_version,
	 * wp_version).
	 *
	 * @return void
	 */
	public function test_get_api_params_adds_raw_url_and_keeps_existing(): void {
		Functions\when( 'home_url' )->justReturn( 'https://Example.com/' ); // RAW (not normalized).
		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
		// s8-p5 (critic ruling #4a): the §9.5 ack-store read in get_api_params() is
		// NOT wrapped in a swallow — stub get_option (empty store → field ABSENT).
		Functions\when( 'get_option' )->justReturn( false );

		$updater = $this->make_updater(
			array(
				'license' => 'KEY-123',
				'item_id' => 216,
				'version' => '2.0.0',
			),
			'woodev-test-plugin',
			false
		);

		$params = $this->call_private( $updater, 'get_api_params' );

		// php_version is the live runtime value: phpversion() is a PHP internal that is
		// not reliably stubbable across test order (it would need patchwork
		// instrumentation of the already-compiled updater file), so we pin it to the
		// real value rather than a brittle stub.
		$this->assertSame(
			array(
				'edd_action'  => 'get_version',
				'license'     => 'KEY-123',
				'item_id'     => 216,
				'version'     => '2.0.0',
				'slug'        => 'woodev-test-plugin',
				'beta'        => false,
				'php_version' => phpversion(),
				'wp_version'  => '6.5',
				'url'         => 'https://Example.com/', // raw home_url(), appended additively.
			),
			$params
		);
	}

	/**
	 * get_version_from_remote(): a parsed response carrying 'license_authority' is
	 * fed to the plugin's license-instance claim store.
	 *
	 * @return void
	 */
	public function test_get_version_from_remote_consumes_license_authority(): void {
		$envelope = array(
			'payload'   => array( 'site' => 'https://example.com' ),
			'signature' => 'sig',
		);

		$response          = new \stdClass();
		$response->sections = array( 'changelog' => 'x' );
		$response->license_authority = $envelope;

		$captured_claims = null;
		$claims          = Mockery::mock();
		$claims->shouldReceive( 'consume_from_response' )->once()->andReturnUsing(
			static function ( $passed ) use ( &$captured_claims ) {
				$captured_claims = $passed;
			}
		);

		$license = Mockery::mock();
		$license->shouldReceive( 'get_authority_claims' )->andReturn( $claims );

		$plugin = Mockery::mock( \Woodev_Plugin::class );
		$plugin->shouldReceive( 'get_license_instance' )->andReturn( $license );

		$updater = $this->make_updater( array(), 'woodev-test-plugin', false, $plugin );

		$api_handler = Mockery::mock();
		$request     = Mockery::mock();
		$request->shouldReceive( 'get_response_data' )->andReturn( $response );
		$api_handler->shouldReceive( 'make_request' )->andReturn( $request );
		$this->set_private( $updater, 'api_handler', $api_handler );

		Functions\when( 'maybe_unserialize' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		// get_api_params() runs (phpversion() lives), but its values are irrelevant
		// to this test — only get_bloginfo() needs a stub to stay side-effect-free.
		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
		// s8-p5: the §9.5 ack store reads get_option (no swallow — critic #4a), and
		// consume_pull_commands() normalises the object response via wp_json_encode.
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth );
			}
		);

		$result = $this->call_private( $updater, 'get_version_from_remote' );

		$this->assertSame( $response, $result );
		$this->assertSame( $response, $captured_claims, 'The full parsed response is handed to the claim store.' );
	}

	/**
	 * get_version_from_remote(): an AUTHORITY-ONLY response (license_authority but
	 * NO sections — the keyless-free-plugin case B-3 exists for) still feeds the
	 * claim store, and the function returns false exactly as before (holistic-round
	 * ruling #2: the consumption block runs BEFORE the sections early-return).
	 *
	 * @return void
	 */
	public function test_authority_only_response_consumes_claim_and_returns_false(): void {
		$envelope = array(
			'payload'   => array( 'site' => 'https://example.com' ),
			'signature' => 'sig',
		);

		$response = new \stdClass(); // NO ->sections on purpose.
		$response->license_authority = $envelope;

		$captured_claims = null;
		$claims          = Mockery::mock();
		$claims->shouldReceive( 'consume_from_response' )->once()->andReturnUsing(
			static function ( $passed ) use ( &$captured_claims ) {
				$captured_claims = $passed;
			}
		);

		$license = Mockery::mock();
		$license->shouldReceive( 'get_authority_claims' )->andReturn( $claims );

		$plugin = Mockery::mock( \Woodev_Plugin::class );
		$plugin->shouldReceive( 'get_license_instance' )->andReturn( $license );

		$updater = $this->make_updater( array(), 'woodev-test-plugin', false, $plugin );

		$api_handler = Mockery::mock();
		$request     = Mockery::mock();
		$request->shouldReceive( 'get_response_data' )->andReturn( $response );
		$api_handler->shouldReceive( 'make_request' )->andReturn( $request );
		$this->set_private( $updater, 'api_handler', $api_handler );

		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth );
			}
		);

		$result = $this->call_private( $updater, 'get_version_from_remote' );

		$this->assertFalse( $result, 'A sections-less response still returns false (pre-change contract).' );
		$this->assertSame( $response, $captured_claims, 'The claim is consumed BEFORE the sections early-return.' );
	}

	/**
	 * get_version_from_remote(): a Throwable from claim consumption never breaks the
	 * update flow — the parsed response is still returned.
	 *
	 * @return void
	 */
	public function test_get_version_from_remote_swallows_consumption_throwable(): void {
		$response           = new \stdClass();
		$response->sections = array( 'changelog' => 'x' );
		$response->license_authority = array( 'payload' => array(), 'signature' => 's' );

		$claims = Mockery::mock();
		$claims->shouldReceive( 'consume_from_response' )->once()->andThrow( new \RuntimeException( 'boom' ) );

		$license = Mockery::mock();
		$license->shouldReceive( 'get_authority_claims' )->andReturn( $claims );

		$plugin = Mockery::mock( \Woodev_Plugin::class );
		$plugin->shouldReceive( 'get_license_instance' )->andReturn( $license );

		$updater = $this->make_updater( array(), 'woodev-test-plugin', false, $plugin );

		$api_handler = Mockery::mock();
		$request     = Mockery::mock();
		$request->shouldReceive( 'get_response_data' )->andReturn( $response );
		$api_handler->shouldReceive( 'make_request' )->andReturn( $request );
		$this->set_private( $updater, 'api_handler', $api_handler );

		Functions\when( 'maybe_unserialize' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		// get_api_params() runs (phpversion() lives), but its values are irrelevant
		// to this test — only get_bloginfo() needs a stub to stay side-effect-free.
		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
		// s8-p5: the §9.5 ack store reads get_option (no swallow — critic #4a), and
		// consume_pull_commands() normalises the object response via wp_json_encode.
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth );
			}
		);

		// Must NOT throw, and must still return the response.
		$result = $this->call_private( $updater, 'get_version_from_remote' );

		$this->assertSame( $response, $result );
	}

	/**
	 * Source assertion (gotcha framework/includes-wiring): the includes() require
	 * gate for the updater file must stay EXPRESSION-IDENTICAL to load_updater()'s
	 * runtime gate. wp_doing_cron() is filterable; if includes() gated on the
	 * DOING_CRON constant while load_updater() gated on the filterable function, a
	 * filtered wp_doing_cron()=true outside a native cron request would construct
	 * Woodev_Plugin_Updater on an unloaded class → production fatal (the test
	 * classmap masks it). Pattern follows BoxPackerDispatcherWiringTest.
	 *
	 * @return void
	 */
	public function test_includes_updater_require_gate_matches_load_updater_gate(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/woodev/class-plugin.php' );

		// The two gates are De Morgan complements of the SAME predicate triple:
		// load_updater() early-returns on the negated form...
		$load_updater_gate = "if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {";

		$this->assertStringContainsString(
			$load_updater_gate,
			$source,
			'load_updater() must gate on is_admin() / wp_doing_cron() / WP_CLI (negated early-return form).'
		);

		// ...and includes() requires the updater file under the positive form,
		// immediately guarding the require line.
		$includes_gate_block = "if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {\n\t\t\t\trequire_once \$framework_path . '/plugin-updater/class-plugin-updater.php';";

		$this->assertStringContainsString(
			$includes_gate_block,
			$source,
			'includes() must require the updater file under the EXACT positive form of the load_updater() gate (is_admin() || wp_doing_cron() || WP_CLI).'
		);

		// The drift this guards against: a constant-based cron check creeping back in.
		$this->assertStringNotContainsString(
			'DOING_CRON',
			$source,
			'class-plugin.php must use the filterable wp_doing_cron(), never the DOING_CRON constant — the two can disagree and desync the gates.'
		);

		// Exactly one require of the updater file (inside the gate above).
		$this->assertSame(
			1,
			substr_count( $source, "require_once \$framework_path . '/plugin-updater/class-plugin-updater.php';" ),
			'The updater file must be required exactly once, inside the gated block.'
		);
	}

	/* ----------------------------------------------------------------------- *
	 * Helpers
	 * ----------------------------------------------------------------------- */

	/**
	 * Runs Woodev_Plugin::load_updater() against a plugin double, reporting whether a
	 * Woodev_Plugin_Updater was constructed.
	 *
	 * Detection: Woodev_Plugin_Updater's constructor news up a Woodev_Licensing_API
	 * which calls $plugin->get_version()/get_download_id()/get_plugin_file(). We stub
	 * those on the plugin double and flip a flag when get_plugin_file() (only the
	 * updater ctor path reads it here) is touched.
	 *
	 * @param string $license_key The stored license key the engine reports.
	 * @return bool Whether the updater was constructed.
	 */
	private function run_load_updater( string $license_key ): bool {
		$constructed = false;

		$license = Mockery::mock();
		$license->shouldReceive( 'get_license' )->andReturn( '' === $license_key ? false : $license_key );

		$plugin = new Keyless_Updater_Probe_Plugin();
		$plugin->test_license     = $license;
		$plugin->on_updater_built = static function () use ( &$constructed ) {
			$constructed = true;
		};

		$plugin->load_updater();

		return $constructed;
	}

	/**
	 * Builds a Woodev_Plugin_Updater bypassing its constructor, seeding the private
	 * fields get_api_params()/get_version_from_remote() read.
	 *
	 * @param array<string, mixed> $api_data The api_data array (license/item_id/version).
	 * @param string               $slug     The plugin slug.
	 * @param bool                 $beta     The beta flag.
	 * @param object|null          $plugin   Optional plugin double for the consumption path.
	 * @return \Woodev_Plugin_Updater
	 */
	private function make_updater( array $api_data, string $slug, bool $beta, $plugin = null ): \Woodev_Plugin_Updater {
		$updater = ( new \ReflectionClass( \Woodev_Plugin_Updater::class ) )->newInstanceWithoutConstructor();

		$this->set_private( $updater, 'api_data', $api_data );
		$this->set_private( $updater, 'slug', $slug );
		$this->set_private( $updater, 'beta', $beta );
		$this->set_private( $updater, 'version', $api_data['version'] ?? '2.0.0' );
		$this->set_private( $updater, 'name', $slug . '/' . $slug . '.php' );

		if ( null !== $plugin ) {
			$this->set_private( $updater, 'plugin', $plugin );
		}

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

/**
 * Concrete plugin probe for load_updater(): a no-op constructor (skip framework
 * bootstrap) plus an overridden updater-construction seam so the test observes
 * whether the keyless gate constructs the updater — without newing up the real
 * Woodev_Licensing_API HTTP stack.
 */
class Keyless_Updater_Probe_Plugin extends \Woodev_Plugin {

	/**
	 * The license engine double load_updater() resolves.
	 *
	 * @var mixed
	 */
	public $test_license;

	/**
	 * Invoked when load_updater() constructs the updater.
	 *
	 * @var callable
	 */
	public $on_updater_built;

	/**
	 * No-op constructor — skip framework bootstrap for unit tests.
	 */
	public function __construct() {
	}

	/**
	 * @return string
	 */
	public function get_file() {
		return __FILE__;
	}

	/**
	 * @return string
	 */
	public function get_plugin_name() {
		return 'Keyless Probe Plugin';
	}

	/**
	 * @return int
	 */
	public function get_download_id() {
		return 216;
	}

	/**
	 * Returns the injected license engine double.
	 *
	 * @return mixed
	 */
	public function get_license_instance() {
		return $this->test_license;
	}

	/**
	 * Test seam: load_updater() calls this instead of `new Woodev_Plugin_Updater`,
	 * so the keyless construction decision is observable without the HTTP stack.
	 *
	 * @return void
	 */
	protected function construct_updater(): void {
		( $this->on_updater_built )();
	}
}
