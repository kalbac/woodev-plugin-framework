<?php
/**
 * #1273 — the updater records a failed version request and pauses further calls to the licence server.
 *
 * Every updater path reaches the server through api_request(): it spends a per-plugin budget BEFORE the
 * network (15 m → 1 h → 6 h → 24 h, capped), a usable answer clears it, and while a pause runs no HTTP call
 * is made — by the update check, the plugin-information popup or the multisite row notice alike.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/updater/class-plugin-updater.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/api/class-licensing-api.php';

/**
 * Class UpdaterFailedRequestPauseTest.
 */
class UpdaterFailedRequestPauseTest extends TestCase {

	private const SLUG = 'woodev-test-plugin';

	private const OPTION = 'woodev_failed_http_test';

	/** @var array<string, mixed> the options table: option name => value */
	public array $options = [];

	/** @var mixed the global `$wpdb` before the test replaced it */
	private $previous_wpdb = null;

	/** @var string the PHP error_log setting before the test */
	private string $previous_log = '';

	/** @var int the number of HTTP calls (make_request) made so far */
	private int $http_calls = 0;

	/** @var object|false|null what the next make_request() answers; null = throw, false = empty body */
	private $next_response = null;

	/** @var object the wpdb double */
	private object $wpdb;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->options       = [];
		$this->http_calls    = 0;
		$this->next_response = null;
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->previous_log  = (string) ini_get( 'error_log' );

		// The updater logs a failed request with error_log(); keep the test output clean.
		ini_set( 'error_log', '/dev/null' ); // phpcs:ignore WordPress.PHP.IniSet.Risky

		Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );

				return true;
			}
		);
		Functions\when( 'maybe_serialize' )->alias( static fn( $value ) => is_scalar( $value ) ? $value : serialize( $value ) );
		Functions\when( 'maybe_unserialize' )->alias( static fn( $value ) => is_string( $value ) && '' !== $value && false !== @unserialize( $value ) ? unserialize( $value ) : $value );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( static fn( $data, $options = 0, $depth = 512 ) => json_encode( $data, $options, $depth ) );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'get_bloginfo' )->justReturn( '6.5' );
		Functions\when( 'is_ssl' )->justReturn( true );

		$this->wpdb      = $this->make_wpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		ini_set( 'error_log', $this->previous_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky

		if ( null === $this->previous_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		}

		parent::tearDown();
	}

	/**
	 * A failed request (the transport throws) writes the first pause: one failure, 15 minutes.
	 *
	 * @return void
	 */
	public function test_a_failed_request_records_the_first_pause(): void {
		$updater = $this->make_updater();

		$before = time();
		$this->assertFalse( $this->call_api_request( $updater ) );
		$after = time();

		$this->assertSame( 1, $this->http_calls );
		$this->assertSame( 1, $this->options[ self::OPTION ]['failures'] );
		$this->assertGreaterThanOrEqual( $before + 15 * MINUTE_IN_SECONDS, $this->options[ self::OPTION ]['next_at'] );
		$this->assertLessThanOrEqual( $after + 15 * MINUTE_IN_SECONDS, $this->options[ self::OPTION ]['next_at'] );
	}

	/**
	 * While the pause runs, a second check makes NO HTTP call and leaves the state untouched.
	 *
	 * @return void
	 */
	public function test_a_second_check_within_the_pause_makes_no_http_call(): void {
		$updater = $this->make_updater();

		$this->call_api_request( $updater );
		$state = $this->options[ self::OPTION ];

		$this->assertFalse( $this->call_api_request( $updater ) );
		$this->assertFalse( $this->call_api_request( $updater ) );

		$this->assertSame( 1, $this->http_calls, 'one attempt, then silence' );
		$this->assertSame( $state, $this->options[ self::OPTION ] );
	}

	/**
	 * The full check_update() path honours the pause as well and still returns the transient.
	 *
	 * @return void
	 */
	public function test_check_update_honours_the_pause(): void {
		$updater = $this->make_updater();

		$this->options[ self::OPTION ] = [ 'failures' => 1, 'next_at' => time() + 600 ];

		$transient = $updater->check_update( new \stdClass() );

		$this->assertSame( 0, $this->http_calls );
		$this->assertObjectNotHasProperty( 'response', $transient );
		$this->assertSame( '2.0.0', $transient->checked[ self::SLUG . '/' . self::SLUG . '.php' ] );
	}

	/**
	 * Each further failure moves one step up the ladder, and the 24-hour step is the cap.
	 *
	 * @dataProvider provide_the_ladder
	 *
	 * @param int $failures_so_far Failures recorded before this attempt.
	 * @param int $expected_wait   The pause this attempt must write.
	 * @return void
	 */
	public function test_the_pause_escalates_15_minutes_1_hour_6_hours_then_24_hours( int $failures_so_far, int $expected_wait ): void {
		$updater = $this->make_updater();

		$this->options[ self::OPTION ] = [ 'failures' => $failures_so_far, 'next_at' => time() - 1 ];

		$before = time();
		$this->assertFalse( $this->call_api_request( $updater ) );

		$this->assertSame( 1, $this->http_calls, 'an expired pause lets one attempt through' );
		$this->assertSame( $failures_so_far + 1, $this->options[ self::OPTION ]['failures'] );
		$this->assertGreaterThanOrEqual( $before + $expected_wait, $this->options[ self::OPTION ]['next_at'] );
		$this->assertLessThanOrEqual( time() + $expected_wait, $this->options[ self::OPTION ]['next_at'] );
	}

	/**
	 * @return array<string, array{int, int}>
	 */
	public function provide_the_ladder(): array {
		return [
			'1st failure → 15 min' => [ 0, 15 * MINUTE_IN_SECONDS ],
			'2nd failure → 1 h'    => [ 1, HOUR_IN_SECONDS ],
			'3rd failure → 6 h'    => [ 2, 6 * HOUR_IN_SECONDS ],
			'4th failure → 24 h'   => [ 3, DAY_IN_SECONDS ],
			'5th failure → 24 h'   => [ 4, DAY_IN_SECONDS ],
			'40th failure → 24 h'  => [ 39, DAY_IN_SECONDS ],
		];
	}

	/**
	 * A usable answer clears the pause, so the next check is free again.
	 *
	 * @return void
	 */
	public function test_a_successful_request_clears_the_pause(): void {
		$updater = $this->make_updater();

		$this->options[ self::OPTION ] = [ 'failures' => 3, 'next_at' => time() - 1 ];
		$this->next_response           = $this->version_payload();

		$result = $this->call_api_request( $updater );

		$this->assertIsObject( $result );
		$this->assertSame( '2.1.0', $result->new_version );
		$this->assertArrayNotHasKey( self::OPTION, $this->options );
		$this->assertSame( 1, $this->http_calls );

		// And the next call goes out again without waiting.
		$this->call_api_request( $updater );
		$this->assertSame( 2, $this->http_calls );
	}

	/**
	 * A 2xx answer that carries no version payload is not a usable answer: it counts as a failure too (the
	 * keyless-poll response of B-3 is cached nowhere, so without a pause it would be re-asked on every check).
	 *
	 * @return void
	 */
	public function test_an_answer_without_version_info_counts_as_a_failure(): void {
		$updater = $this->make_updater();

		$this->next_response = new \stdClass();

		$this->assertFalse( $this->call_api_request( $updater ) );
		$this->assertSame( 1, $this->options[ self::OPTION ]['failures'] );

		$this->next_response = false; // An empty body surfaces as falsy response data.
		$this->options[ self::OPTION ]['next_at'] = time() - 1;
		$this->assertFalse( $this->call_api_request( $updater ) );
		$this->assertSame( 2, $this->options[ self::OPTION ]['failures'] );
	}

	/**
	 * The plugin-information popup (plugins_api) honours the pause: no HTTP call, nothing cached, and the
	 * incoming value is handed back to core untouched (it used to be written to as `false->plugin`).
	 *
	 * @return void
	 */
	public function test_plugins_api_filter_honours_the_pause(): void {
		$updater = $this->make_updater();

		$this->options[ self::OPTION ] = [ 'failures' => 2, 'next_at' => time() + 3600 ];
		$before                        = $this->options;

		$result = $updater->plugins_api_filter( false, 'plugin_information', (object) [ 'slug' => self::SLUG ] );

		$this->assertFalse( $result );
		$this->assertSame( 0, $this->http_calls );
		$this->assertSame( $before, $this->options, 'no cache row and no state change' );
	}

	/**
	 * The popup after a failed attempt (the pause is not running yet): one call, no cached `false`, the pause
	 * is written, and a second popup open is silent.
	 *
	 * @return void
	 */
	public function test_plugins_api_filter_records_a_failure_and_then_stays_silent(): void {
		$updater = $this->make_updater();
		$args    = (object) [ 'slug' => self::SLUG ];

		$this->assertFalse( $updater->plugins_api_filter( false, 'plugin_information', $args ) );
		$this->assertFalse( $updater->plugins_api_filter( false, 'plugin_information', $args ) );

		$this->assertSame( 1, $this->http_calls );
		$this->assertSame( [ self::OPTION ], array_keys( $this->options ), 'only the pause is stored' );
	}

	/**
	 * A success through the popup is cached exactly as before and clears the pause.
	 *
	 * @return void
	 */
	public function test_plugins_api_filter_success_still_caches_the_response(): void {
		$updater = $this->make_updater();

		$this->next_response = $this->version_payload();

		$result = $updater->plugins_api_filter( false, 'plugin_information', (object) [ 'slug' => self::SLUG ] );

		$this->assertSame( '2.1.0', $result->new_version );
		$this->assertArrayNotHasKey( self::OPTION, $this->options );

		$cached = array_values( array_filter( $this->options, static fn( $value ) => is_array( $value ) && isset( $value['timeout'], $value['value'] ) ) );
		$this->assertCount( 1, $cached );
		$this->assertSame( '2.1.0', json_decode( $cached[0]['value'] )->new_version );
	}

	/**
	 * Of two requests that read the same open window, only one goes to the network.
	 *
	 * @return void
	 */
	public function test_of_two_concurrent_requests_only_the_first_proceeds(): void {
		$first  = $this->make_updater();
		$second = $this->make_updater();

		$this->wpdb->stale_read = null; // Both read «no row yet».
		$this->call_api_request( $first );
		$this->call_api_request( $second );

		$this->assertSame( 1, $this->http_calls );
		$this->assertSame( [ 1, 0 ], $this->wpdb->writes, 'the INSERT IGNORE lost for the second racer' );

		// Same on an existing row whose pause has just expired.
		$old                           = [ 'failures' => 2, 'next_at' => time() - 1 ];
		$this->options[ self::OPTION ] = $old;
		$this->wpdb->stale_read        = serialize( $old );
		$this->wpdb->writes            = [];
		$this->http_calls              = 0;

		$this->call_api_request( $first );
		$this->call_api_request( $second );

		$this->assertSame( 1, $this->http_calls );
		$this->assertSame( [ 1, 0 ], $this->wpdb->writes );
		$this->assertSame( 3, $this->options[ self::OPTION ]['failures'] );
	}

	/**
	 * Without a `$wpdb` (a bare CLI bootstrap) the budget falls back to the plain option write.
	 *
	 * @return void
	 */
	public function test_without_a_database_handle_the_plain_option_write_is_used(): void {
		unset( $GLOBALS['wpdb'] );

		$updater = $this->make_updater();

		$this->call_api_request( $updater );
		$this->call_api_request( $updater );

		$this->assertSame( 1, $this->http_calls );
		$this->assertSame( 1, $this->options[ self::OPTION ]['failures'] );
	}

	/**
	 * A corrupt or foreign value in the option reads as «no failures yet» and never blocks the updater.
	 *
	 * @return void
	 */
	public function test_a_corrupt_stored_state_does_not_block_the_updater(): void {
		$updater = $this->make_updater();

		$this->options[ self::OPTION ] = 'garbage';
		$this->call_api_request( $updater );
		$this->assertSame( 1, $this->http_calls );

		$this->options[ self::OPTION ] = 4102444800; // A bare timestamp (the shape the old reader expected).
		$this->call_api_request( $updater );
		$this->assertSame( 2, $this->http_calls );
	}

	/**
	 * The pause is keyed per store URL AND plugin: two plugins of one site never share it, and neither do
	 * two stores. Built through the real constructor.
	 *
	 * @return void
	 */
	public function test_the_pause_key_differs_per_plugin_and_per_store(): void {
		$store = 'https://woodev.ru/';

		Functions\when( 'trailingslashit' )->alias( static fn( $value ) => rtrim( $value, '/' ) . '/' );
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) use ( &$store ) {
				return 'woodev_license_base_url' === $hook ? $store : $value;
			}
		);
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );

		$key = static function ( string $slug ): string {
			$license = Mockery::mock();
			$license->shouldReceive( 'get_license' )->andReturn( 'KEY-1' );

			$plugin = Mockery::mock( \Woodev_Plugin::class );
			$plugin->shouldReceive( 'get_version' )->andReturn( '2.0.0' );
			$plugin->shouldReceive( 'get_download_id' )->andReturn( 216 );
			$plugin->shouldReceive( 'get_plugin_file' )->andReturn( $slug . '/' . $slug . '.php' );
			$plugin->shouldReceive( 'get_license_instance' )->andReturn( $license );
			$plugin->shouldReceive( 'is_beta_allowed' )->andReturn( false );

			$updater = new \Woodev_Plugin_Updater( $plugin );

			return ( new \ReflectionProperty( $updater, 'failed_request_cache_key' ) )->getValue( $updater );
		};

		$first = $key( 'plugin-a' );

		$this->assertNotSame( $first, $key( 'plugin-b' ), 'another plugin, another pause' );
		$this->assertSame( $first, $key( 'plugin-a' ), 'stable for the same plugin and store' );

		$store = 'https://other.example/';
		$this->assertNotSame( $first, $key( 'plugin-a' ), 'another store, another pause' );
	}

	/* ----------------------------------------------------------------------- *
	 * Helpers
	 * ----------------------------------------------------------------------- */

	/**
	 * A parsed version response with a usable payload.
	 *
	 * @return \stdClass
	 */
	private function version_payload(): \stdClass {
		$response              = new \stdClass();
		$response->new_version = '2.1.0';
		$response->sections    = [ 'changelog' => 'x' ];

		return $response;
	}

	/**
	 * Builds a Woodev_Plugin_Updater bypassing its constructor, with a transport double that counts calls.
	 *
	 * @return \Woodev_Plugin_Updater
	 */
	private function make_updater(): \Woodev_Plugin_Updater {
		$updater = ( new \ReflectionClass( \Woodev_Plugin_Updater::class ) )->newInstanceWithoutConstructor();

		$license = Mockery::mock();
		$license->shouldReceive( 'get_authority_claims' )->andReturn( Mockery::mock( [ 'consume_from_response' => null ] ) );
		$license->shouldReceive( 'reset_claim_refresh_backoff_if_claimed' )->andReturnNull();

		$plugin = Mockery::mock( \Woodev_Plugin::class );
		$plugin->shouldReceive( 'get_license_instance' )->andReturn( $license );

		$api_handler = Mockery::mock();
		$api_handler->shouldReceive( 'is_debug_enabled' )->andReturn( false );
		$api_handler->shouldReceive( 'make_request' )->andReturnUsing(
			function () {
				++$this->http_calls;

				if ( null === $this->next_response ) {
					throw new \RuntimeException( 'woodev.ru is down' );
				}

				$request = Mockery::mock();
				$request->shouldReceive( 'get_response_data' )->andReturn( $this->next_response );

				return $request;
			}
		);

		$values = [
			'plugin'                   => $plugin,
			'api_handler'              => $api_handler,
			'api_url'                  => 'https://woodev.ru/',
			'api_data'                 => [ 'version' => '2.0.0', 'item_id' => 216, 'license' => 'KEY-1', 'beta' => false ],
			'slug'                     => self::SLUG,
			'beta'                     => false,
			'version'                  => '2.0.0',
			'name'                     => self::SLUG . '/' . self::SLUG . '.php',
			'failed_request_cache_key' => self::OPTION,
		];

		foreach ( $values as $property => $value ) {
			( new \ReflectionProperty( $updater, $property ) )->setValue( $updater, $value );
		}

		return $updater;
	}

	/**
	 * Calls the private api_request() the way both public callers do.
	 *
	 * @param \Woodev_Plugin_Updater $updater The updater.
	 * @return mixed
	 */
	private function call_api_request( \Woodev_Plugin_Updater $updater ) {
		return ( new \ReflectionMethod( $updater, 'api_request' ) )->invoke( $updater, [ 'slug' => self::SLUG ] );
	}

	/**
	 * The options table as `$wpdb` sees it: the in-memory option map, with the real semantics of the two
	 * statements the budget uses — a compare-and-swap UPDATE and INSERT IGNORE.
	 *
	 * @return object
	 */
	private function make_wpdb(): object {
		return new class( $this ) {

			public string $options = 'wp_options';

			/** @var string|null|false when not false, get_var() returns this instead of the live row — a racer's stale read */
			public $stale_read = false;

			/** @var int[] the row counts the write statements returned, in order */
			public array $writes = [];

			/** @var UpdaterFailedRequestPauseTest the test whose option map is the table */
			private $test;

			public function __construct( UpdaterFailedRequestPauseTest $test ) {
				$this->test = $test;
			}

			public function prepare( string $sql, ...$args ): string {
				return json_encode( [ $sql, $args ] );
			}

			public function get_var( string $prepared ) {
				if ( false !== $this->stale_read ) {
					return $this->stale_read;
				}

				$name = json_decode( $prepared, true )[1][0];
				$map  = &$this->test->options;

				return array_key_exists( $name, $map ) ? serialize( $map[ $name ] ) : null;
			}

			public function update( string $table, array $data, array $where ) {
				$map = &$this->test->options;
				$hit = array_key_exists( $where['option_name'], $map ) && serialize( $map[ $where['option_name'] ] ) === $where['option_value'];

				if ( $hit ) {
					$map[ $where['option_name'] ] = unserialize( $data['option_value'] );
				}

				return $this->writes[] = $hit ? 1 : 0;
			}

			public function query( string $prepared ) {
				[ , $args ] = json_decode( $prepared, true );
				$map        = &$this->test->options;
				$free       = ! array_key_exists( $args[0], $map );

				if ( $free ) {
					$map[ $args[0] ] = unserialize( $args[1] );
				}

				return $this->writes[] = $free ? 1 : 0;
			}
		};
	}
}
