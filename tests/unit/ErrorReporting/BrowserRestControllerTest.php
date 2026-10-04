<?php
/**
 * Browser_Rest_Controller + Error_Reporter::report_browser() — the public route's gates and what it queues (#1081, D7).
 *
 * @package Woodev\Tests\Unit\ErrorReporting
 */

namespace Woodev\Tests\Unit\ErrorReporting;

use Brain\Monkey\Functions;
use Woodev\Framework\Error_Reporting\Browser_Rest_Controller;
use Woodev\Framework\Error_Reporting\Browser_Script;
use Woodev\Framework\Error_Reporting\Error_Reporter;
use Woodev\Framework\Error_Reporting\Event_Queue;
use Woodev\Framework\Error_Reporting\Plugin_Scope;
use Woodev\Framework\Framework_Plugin_Loader_Definition;

/**
 * @covers \Woodev\Framework\Error_Reporting\Browser_Rest_Controller
 * @covers \Woodev\Framework\Error_Reporting\Browser_Script
 * @covers \Woodev\Framework\Error_Reporting\Error_Reporter::report_browser
 */
final class BrowserRestControllerTest extends ErrorReportingTestCase {

	private const SCRIPT = self::OUR_URL . '/assets/js/map.js';

	/** @var array<string,mixed> */
	private array $options = [];

	/** @var array<string,mixed> */
	private array $transients = [];

	/** @var int what every rate-limit counter reads as before this request's own increment */
	private int $counter = 0;

	/** @var array<int,array{int,string}> */
	private array $scheduled = [];

	/** @var array<int,string> wp_remote_post URLs — must stay empty: the route never sends */
	private array $posts = [];

	/** @var array<string,mixed> */
	private array $calls = [];

	/** @var array<string,array<string>> what the pickup handlers declare */
	private array $pickup_fields = [];

	/** @var array<string,mixed> filter overrides by tag */
	private array $filters = [];

	protected function setUp(): void {
		parent::setUp();

		Error_Reporter::reset();

		global $wp_version;
		$wp_version = '6.8';

		$this->options    = [ 'woodev_error_reporting_enabled' => 'yes' ];
		$this->transients = [];
		$this->counter    = 0;
		$this->scheduled  = [];
		$this->posts      = [];
		$this->calls      = [];

		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return $this->options[ $name ] ?? $default;
			}
		);
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
		$this->pickup_fields = [ 'acme-delivery' => [ 'pickup_point' ] ];
		$this->filters       = [];

		Functions\when( 'apply_filters' )->alias(
			function ( $tag, $value ) {
				if ( 'woodev_error_reporting_dsn' === $tag ) {
					return 'https://k@errors.example.ru/7';
				}

				// What the pickup handlers declare through `Pickup_Handler::register()`.
				if ( 'woodev_error_reporting_pickup_fields' === $tag ) {
					return $this->pickup_fields;
				}

				return $this->filters[ $tag ] ?? $value;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				$this->calls['transient_keys'][] = $key;

				return $this->counter > 0 ? $this->counter : ( $this->transients[ $key ] ?? false );
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;

				return true;
			}
		);
		// The rate-limit trait probes function_exists() first, so whether this is defined
		// depends on which test ran before — stub it, or the result depends on test order.
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $when, $hook ) {
				$this->scheduled[] = [ $when, $hook ];

				return true;
			}
		);
		Functions\when( 'home_url' )->justReturn( self::HOME . '/' );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( $value, '/' );
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->alias(
			static function ( $nonce ) {
				return 'good-nonce' === $nonce ? 1 : false;
			}
		);
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url ) {
				$this->posts[] = $url;

				return [];
			}
		);
	}

	protected function tearDown(): void {
		Error_Reporter::reset();
		unset( $_SERVER['REMOTE_ADDR'] );

		parent::tearDown();
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function registry(): array {
		$errors     = [];
		$definition = Framework_Plugin_Loader_Definition::from_array(
			[
				'plugin_id'         => 'acme-delivery',
				'plugin_name'       => 'Acme',
				'plugin_version'    => '1.4.0',
				'framework_version' => '2.0.1',
				'plugin_file'       => $this->plugin_dir . '/acme-delivery.php',
				'platform'          => Framework_Plugin_Loader_Definition::PLATFORM_WORDPRESS,
				'download_id'       => 77,
				'main_class'        => 'Acme_Plugin',
				'requirements'      => [
					'php'       => '7.4',
					'wordpress' => '6.3',
				],
			],
			$errors
		);

		return [ $definition->to_legacy_plugin() ];
	}

	/**
	 * Installs the reporter against a registry whose plugin has an asset base URL.
	 *
	 * `plugins_url()` is not defined in unit context, so the URL comes from a stub.
	 */
	private function install(): void {
		Functions\when( 'plugins_url' )->justReturn( self::OUR_URL );

		$this->assertTrue( Error_Reporter::install( $this->registry() ) );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function queued(): array {
		$queue = $this->options[ Event_Queue::OPTION ] ?? [];

		return is_array( $queue ) ? $queue : [];
	}

	/**
	 * @param array<mixed>|string  $body    Body (an array is JSON-encoded).
	 * @param array<string,string> $headers Headers.
	 * @return \WP_REST_Request
	 */
	private function request( $body, array $headers = [ 'X-WP-Nonce' => 'good-nonce' ] ): \WP_REST_Request {
		return new \WP_REST_Request( [], $headers, is_string( $body ) ? $body : (string) json_encode( $body ) );
	}

	/**
	 * @return array<string,mixed> A valid error report from our script.
	 */
	private function error_report(): array {
		return [
			'source' => 'error',
			'type'   => 'TypeError',
			'frames' => [
				[
					'url'  => self::SCRIPT . '?ver=3',
					'line' => 10,
					'col'  => 20,
				],
			],
		];
	}

	public function test_it_registers_under_the_framework_namespace_as_a_sibling_of_the_consent_route(): void {
		$this->assertSame( 'woodev/v1', \Woodev_REST_V1_Registrar::ROUTE_NAMESPACE );
		$this->assertSame( '/error-reporting/browser', Browser_Rest_Controller::ROUTE );

		$registered = [];
		Functions\when( 'register_rest_route' )->alias(
			static function ( $namespace, $route, $args ) use ( &$registered ) {
				$registered = [ $namespace, $route, $args ];

				return true;
			}
		);

		( new Browser_Rest_Controller() )->register_routes();

		$this->assertSame( 'woodev/v1', $registered[0] );
		$this->assertSame( '/error-reporting/browser', $registered[1] );
		$this->assertSame( 'POST', $registered[2][0]['methods'] );
		$this->assertArrayHasKey( 'permission_callback', $registered[2][0] );
	}

	public function test_it_refuses_while_reporting_is_inactive(): void {
		$this->install();
		$controller = new Browser_Rest_Controller();

		$this->options = [];
		$result        = $controller->check_permissions( $this->request( $this->error_report() ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'woodev_error_reporting_inactive', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_it_refuses_a_missing_or_invalid_nonce_and_accepts_a_valid_one_for_a_guest(): void {
		$this->install();
		$controller = new Browser_Rest_Controller();

		foreach ( [ [], [ 'X-WP-Nonce' => '' ], [ 'X-WP-Nonce' => 'stale' ] ] as $headers ) {
			$result = $controller->check_permissions( $this->request( $this->error_report(), $headers ) );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'woodev_error_reporting_invalid_nonce', $result->get_error_code() );
		}

		$this->assertTrue( $controller->check_permissions( $this->request( $this->error_report() ) ) );
	}

	public function test_a_report_from_our_script_is_queued_without_any_http(): void {
		$this->install();

		$result = ( new Browser_Rest_Controller() )->create_item( $this->request( $this->error_report() ) );

		$this->assertSame( [ 'queued' => true ], $result );
		$this->assertCount( 1, $this->queued() );
		$this->assertSame( [], $this->posts, 'the route only enqueues; the cron sends' );
		$this->assertNotSame( [], $this->scheduled );

		$event = $this->queued()[0];

		$this->assertSame( 'javascript', $event['platform'] );
		$this->assertSame( 'plugins/acme-delivery/assets/js/map.js', $event['exception']['values'][0]['stacktrace']['frames'][0]['filename'] );
		$this->assertArrayNotHasKey( 'value', $event['exception']['values'][0] );
	}

	public function test_a_foreign_script_is_rejected_by_the_server_even_though_the_browser_sent_it(): void {
		$this->install();

		$report           = $this->error_report();
		$report['frames'] = [ [ 'url' => 'https://shop.example.ru/wp-content/plugins/other-plugin/x.js' ] ];

		$this->assertSame( [ 'queued' => false ], ( new Browser_Rest_Controller() )->create_item( $this->request( $report ) ) );
		$this->assertSame( [], $this->queued() );
	}

	public function test_the_same_report_twice_is_queued_once(): void {
		$this->install();
		$controller = new Browser_Rest_Controller();

		$this->assertSame( [ 'queued' => true ], $controller->create_item( $this->request( $this->error_report() ) ) );
		$this->assertSame( [ 'queued' => false ], $controller->create_item( $this->request( $this->error_report() ) ) );
		$this->assertCount( 1, $this->queued() );
	}

	public function test_the_pickup_event_is_queued_with_plugin_field_and_code_only(): void {
		$this->install();

		$result = ( new Browser_Rest_Controller() )->create_item(
			$this->request(
				[
					'source'   => 'pickup',
					'pluginId' => 'acme-delivery',
					'fieldId'  => 'pickup_point',
					'code'     => 'map_script',
				]
			)
		);

		$this->assertSame( [ 'queued' => true ], $result );
		$this->assertSame( 'acme-delivery:pickup_point:map_script', $this->queued()[0]['exception']['values'][0]['value'] );
	}

	public function test_a_pickup_event_for_an_unregistered_plugin_is_not_queued(): void {
		$this->install();

		$result = ( new Browser_Rest_Controller() )->create_item(
			$this->request(
				[
					'source'   => 'pickup',
					'pluginId' => 'someone-else',
					'fieldId'  => 'pickup_point',
					'code'     => 'map_script',
				]
			)
		);

		$this->assertSame( [ 'queued' => false ], $result );
		$this->assertSame( [], $this->queued() );
	}

	public function test_a_message_in_the_payload_is_refused_not_stripped_silently(): void {
		$this->install();

		$report            = $this->error_report();
		$report['message'] = 'Иван Иванов, +7 900 123-45-67';
		$result            = ( new Browser_Rest_Controller() )->create_item( $this->request( $report ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( [], $this->queued() );

		$pickup = ( new Browser_Rest_Controller() )->create_item(
			$this->request(
				[
					'source'   => 'pickup',
					'pluginId' => 'acme-delivery',
					'fieldId'  => 'pickup_point',
					'code'     => 'map_script',
					'message'  => 'Иван Иванов',
				]
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $pickup );
		$this->assertSame( [], $this->queued() );
	}

	/**
	 * @return array<string,array{array<mixed>|string}>
	 */
	public function invalid_bodies(): array {
		$frame = [
			'url'  => self::SCRIPT,
			'line' => 1,
		];
		$ok    = [
			'source' => 'error',
			'type'   => 'Error',
			'frames' => [ $frame ],
		];

		return [
			'not json'             => [ 'not json {' ],
			'json scalar'          => [ '"text"' ],
			'empty body'           => [ '' ],
			'unknown source'       => [ array_merge( $ok, [ 'source' => 'console' ] ) ],
			'no source'            => [ array_diff_key( $ok, [ 'source' => 1 ] ) ],
			'unknown top-level key' => [ array_merge( $ok, [ 'extra' => 1 ] ) ],
			'type not a string'    => [ array_merge( $ok, [ 'type' => [ 'x' ] ] ) ],
			'type not identifier'  => [ array_merge( $ok, [ 'type' => 'Bad name' ] ) ],
			'type too long'        => [ array_merge( $ok, [ 'type' => str_repeat( 'A', 65 ) ] ) ],
			'frames missing'       => [ array_diff_key( $ok, [ 'frames' => 1 ] ) ],
			'frames not a list'    => [ array_merge( $ok, [ 'frames' => [ 'a' => $frame ] ] ) ],
			'thirty-one frames'    => [ array_merge( $ok, [ 'frames' => array_fill( 0, 31, $frame ) ] ) ],
			'frame not an object'  => [ array_merge( $ok, [ 'frames' => [ 'text' ] ] ) ],
			'frame unknown key'    => [ array_merge( $ok, [ 'frames' => [ array_merge( $frame, [ 'message' => 'x' ] ) ] ] ) ],
			'frame without url'    => [ array_merge( $ok, [ 'frames' => [ [ 'line' => 1 ] ] ] ) ],
			'frame url too long'   => [ array_merge( $ok, [ 'frames' => [ [ 'url' => self::SCRIPT . str_repeat( 'a', 500 ) ] ] ] ) ],
			'line as string'       => [ array_merge( $ok, [ 'frames' => [ array_merge( $frame, [ 'line' => '1' ] ) ] ] ) ],
			'negative column'      => [ array_merge( $ok, [ 'frames' => [ array_merge( $frame, [ 'col' => -1 ] ) ] ] ) ],
			'line over the cap'    => [ array_merge( $ok, [ 'frames' => [ array_merge( $frame, [ 'line' => 10000001 ] ) ] ] ) ],
			'function name sent'   => [ array_merge( $ok, [ 'frames' => [ array_merge( $frame, [ 'fn' => 'John.Smith' ] ) ] ] ) ],
			'error with pluginId'  => [ array_merge( $ok, [ 'pluginId' => 'acme-delivery' ] ) ],
			'pickup with frames'   => [
				[
					'source'   => 'pickup',
					'pluginId' => 'acme-delivery',
					'fieldId'  => 'pickup_point',
					'code'     => 'map_script',
					'frames'   => [ $frame ],
				],
			],
			'pickup missing code'  => [
				[
					'source'   => 'pickup',
					'pluginId' => 'acme-delivery',
					'fieldId'  => 'pickup_point',
				],
			],
			'pickup bad token'     => [
				[
					'source'   => 'pickup',
					'pluginId' => 'acme-delivery',
					'fieldId'  => 'pickup point',
					'code'     => 'map_script',
				],
			],
		];
	}

	/**
	 * @dataProvider invalid_bodies
	 * @param array<mixed>|string $body Body.
	 */
	public function test_a_body_that_breaks_the_schema_is_answered_400_and_queues_nothing( $body ): void {
		$this->install();

		$result = ( new Browser_Rest_Controller() )->create_item( $this->request( $body ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'woodev_error_reporting_invalid', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( [], $this->queued() );
	}

	public function test_a_body_over_the_size_cap_is_answered_413(): void {
		$this->install();

		$result = ( new Browser_Rest_Controller() )->create_item( $this->request( str_repeat( 'x', Browser_Rest_Controller::MAX_BODY + 1 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 413, $result->get_error_data()['status'] );
	}

	public function test_a_client_over_its_budget_is_answered_429_before_anything_else_is_read(): void {
		$this->install();

		$this->counter = Browser_Rest_Controller::RATE_LIMIT_MAX; // This request's own increment makes it the 11th.
		$result        = ( new Browser_Rest_Controller() )->create_item( $this->request( 'not even json' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'woodev_error_reporting_rate_limited', $result->get_error_code() );
		$this->assertSame( 429, $result->get_error_data()['status'] );
	}

	public function test_a_client_under_its_budget_passes_and_no_raw_address_is_stored(): void {
		$this->install();

		( new Browser_Rest_Controller() )->create_item( $this->request( $this->error_report() ) );

		$keys = array_merge( array_keys( $this->transients ), $this->calls['transient_keys'] ?? [] );

		$this->assertNotSame( [], $keys );

		$limits = [];

		foreach ( $keys as $key ) {
			$this->assertStringNotContainsString( '203.0.113.7', $key );

			if ( 0 === strpos( $key, 'woodev_er_js_rl_' ) ) {
				$limits[] = $key;
			}
		}

		$this->assertNotSame( [], $limits, 'the per-client counter has its own prefix: its own budget' );
	}

	public function test_report_browser_refuses_when_reporting_was_switched_off_after_the_permission_check(): void {
		$this->install();

		$this->options['woodev_error_reporting_enabled'] = 'no';

		$this->assertFalse( Error_Reporter::report_browser( $this->error_report() ) );
		$this->assertSame( [], $this->queued() );
	}

	public function test_report_browser_does_nothing_before_install(): void {
		$this->assertFalse( Error_Reporter::report_browser( $this->error_report() ) );
	}

	public function test_install_hooks_the_browser_script_only_when_reporting_is_active(): void {
		$added = [];
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $callback ) use ( &$added ) {
				$added[] = [ $hook, $callback ];

				return true;
			}
		);
		Functions\when( 'plugins_url' )->justReturn( self::OUR_URL );

		$this->options = [];
		Error_Reporter::install( $this->registry() );
		$this->assertNotContains( [ 'wp_enqueue_scripts', [ Error_Reporter::class, 'enqueue_browser_script' ] ], $added );

		Error_Reporter::reset();
		$added = [];

		$this->options = [ 'woodev_error_reporting_enabled' => 'yes' ];
		Error_Reporter::install( $this->registry() );
		$this->assertContains( [ 'wp_enqueue_scripts', [ Error_Reporter::class, 'enqueue_browser_script' ] ], $added );
	}

	public function test_the_script_is_enqueued_with_endpoint_nonce_and_bases_and_never_the_dsn(): void {
		$localized = [];
		$enqueued  = [];

		Functions\when( 'plugins_url' )->alias(
			static function ( $path ) {
				return self::OUR_URL . '/woodev/' . $path;
			}
		);
		Functions\when( 'wp_register_script' )->justReturn( true );
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle ) use ( &$enqueued ) {
				$enqueued[] = $handle;
			}
		);
		Functions\when( 'rest_url' )->alias(
			static function ( $path ) {
				return 'https://shop.example.ru/wp-json/' . $path;
			}
		);
		Functions\when( 'wp_create_nonce' )->justReturn( 'fresh-nonce' );
		Functions\when( 'wp_localize_script' )->alias(
			static function ( $handle, $name, $data ) use ( &$localized ) {
				$localized = [ $handle, $name, $data ];

				return true;
			}
		);

		Browser_Script::enqueue( $this->make_browser_scope() );

		$this->assertSame( [ 'woodev-error-reporter' ], $enqueued );
		$this->assertSame( 'woodevErrorReporting', $localized[1] );
		$this->assertSame(
			[
				'endpoint' => 'https://shop.example.ru/wp-json/woodev/v1/error-reporting/browser',
				'nonce'    => 'fresh-nonce',
				'bases'    => [ self::OUR_URL ],
			],
			$localized[2]
		);
		$this->assertStringNotContainsString( 'errors.example.ru', $this->encode( $localized ) );
	}

	public function test_nothing_is_enqueued_when_no_plugin_has_a_base_url(): void {
		$enqueued = [];
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle ) use ( &$enqueued ) {
				$enqueued[] = $handle;
			}
		);

		Browser_Script::enqueue( new Plugin_Scope( [] ) );

		$this->assertSame( [], $enqueued );
	}

	/**
	 * Every REST request is a fresh PHP process: the per-request queue counter starts again.
	 *
	 * @return void
	 */
	private function next_request(): void {
		Error_Reporter::reset();
		$this->install();
	}

	/**
	 * @param int $line Makes the signature unique.
	 * @return array<string,mixed> A valid error report from our script.
	 */
	private function error_report_at( int $line ): array {
		$report                    = $this->error_report();
		$report['frames'][0]['line'] = $line;

		return $report;
	}

	public function test_browser_reports_never_evict_a_php_event_from_the_queue(): void {
		$this->install();

		// The critic's repro: one PHP event waiting, then twenty-five distinct valid browser reports.
		$this->assertTrue( Event_Queue::push( $this->make_builder()->from_throwable( $this->make_exception( self::OURS . '/a.php', 7 ), 'acme-delivery' ) ) );

		$accepted = 0;

		for ( $line = 1; $line <= 25; ++$line ) {
			$this->next_request();

			$accepted += Error_Reporter::report_browser( $this->error_report_at( $line ) ) ? 1 : 0;
		}

		$this->assertSame( Event_Queue::BROWSER_LIMIT, $accepted, 'the browser keeps its own share and is refused beyond it' );

		$sources = array_map(
			static function ( array $event ): string {
				return (string) ( $event['tags']['source'] ?? 'php' );
			},
			$this->queued()
		);

		$this->assertCount( 1, array_keys( $sources, 'php', true ), 'the PHP event is still queued' );
		$this->assertCount( Event_Queue::BROWSER_LIMIT, array_keys( $sources, 'browser', true ) );
	}

	public function test_a_queue_full_of_php_events_refuses_the_browser_instead_of_evicting(): void {
		$this->install();

		for ( $line = 1; $line <= Event_Queue::LIMIT; ++$line ) {
			Event_Queue::push( $this->make_builder()->from_throwable( $this->make_exception( self::OURS . '/a.php', $line ), 'acme-delivery' ) );
		}

		$before = $this->queued();

		$this->assertFalse( Error_Reporter::report_browser( $this->error_report() ) );
		$this->assertSame( $before, $this->queued() );
	}

	public function test_the_whole_site_may_queue_only_so_many_browser_reports_an_hour(): void {
		$this->filters['woodev_error_reporting_browser_hourly_cap'] = 3;

		$results = [];

		for ( $line = 1; $line <= 5; ++$line ) {
			$this->next_request(); // Five requests — as many different clients as you like.

			$results[] = Error_Reporter::report_browser( $this->error_report_at( $line ) );
		}

		$this->assertSame( [ true, true, true, false, false ], $results );
		$this->assertCount( 3, $this->queued() );
		$this->assertArrayHasKey( 'woodev_er_js_in_' . gmdate( 'YmdH' ), $this->transients );
	}

	public function test_a_report_that_is_not_ours_does_not_use_up_the_intake_cap(): void {
		$this->install();

		$report           = $this->error_report();
		$report['frames'] = [ [ 'url' => 'https://shop.example.ru/wp-content/plugins/other-plugin/x.js' ] ];

		Error_Reporter::report_browser( $report );

		$this->assertArrayNotHasKey( 'woodev_er_js_in_' . gmdate( 'YmdH' ), $this->transients );
	}

	public function test_a_frame_url_made_of_customer_text_is_answered_200_and_queues_nothing(): void {
		$this->install();

		foreach (
			[
				self::OUR_URL . '/Иван/Ленина%201/john@example.com.js',
				self::OUR_URL . '/%D0%98%D0%B2%D0%B0%D0%BD/john%40example.com.js',
				self::OUR_URL . '/assets/js/John.Smith.js',
				self::OUR_URL . '/assets%2fjs%2fmap.js',
			] as $url
		) {
			$report                  = $this->error_report();
			$report['frames'][0]['url'] = $url;

			$this->assertSame( [ 'queued' => false ], ( new Browser_Rest_Controller() )->create_item( $this->request( $report ) ), $url );
		}

		$this->assertSame( [], $this->queued() );
	}

	public function test_the_critics_pickup_payload_is_not_queued_and_an_unknown_code_is_replaced(): void {
		$this->install();
		$controller = new Browser_Rest_Controller();

		$this->assertSame(
			[ 'queued' => false ],
			$controller->create_item(
				$this->request(
					[
						'source'   => 'pickup',
						'pluginId' => 'acme-delivery',
						'fieldId'  => 'John.Smith',
						'code'     => '79001234567',
					]
				)
			)
		);
		$this->assertSame( [], $this->queued() );

		$this->assertSame(
			[ 'queued' => true ],
			$controller->create_item(
				$this->request(
					[
						'source'   => 'pickup',
						'pluginId' => 'acme-delivery',
						'fieldId'  => 'pickup_point',
						'code'     => '79001234567',
					]
				)
			)
		);

		$json = $this->encode( $this->queued() );

		$this->assertStringContainsString( 'acme-delivery:pickup_point:unknown', $json );
		$this->assertStringNotContainsString( '79001234567', $json );
	}

	public function test_a_pickup_field_that_no_handler_declared_is_not_queued(): void {
		$this->pickup_fields = [];
		$this->install();

		$result = ( new Browser_Rest_Controller() )->create_item(
			$this->request(
				[
					'source'   => 'pickup',
					'pluginId' => 'acme-delivery',
					'fieldId'  => 'pickup_point',
					'code'     => 'map_script',
				]
			)
		);

		$this->assertSame( [ 'queued' => false ], $result );
	}
}
