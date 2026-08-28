<?php
/**
 * Guards on filter RETURN values across `core: api / settings-api / utilities / setup` — #613,
 * from the #599 audit. Task C, tranche 2.
 *
 * The framework hands out a filter and then uses what comes back immediately — an outbound
 * request URI, an `in_array()`/`array_filter()` haystack, arithmetic, or a background-job
 * object. A plugin returning the wrong type does not break the plugin — it fatals the caller.
 * The background-job sites are the worst of these: they fail on the CRON path, invisibly.
 *
 * The rule applied, settled in s100 and reaffirmed on #613: degrade to a safe default; never
 * throw, and never disable a protection. The safe default is always the PRE-FILTER value (or,
 * for the two arithmetic sites, the result of casting the filter's own return — matching the
 * `absint()` pattern already used at class-woodev-job-batch-handler.php:239).
 *
 * Every site gets a PAIR:
 *   - a garbage return must not fatal, and the pre-filter value must survive;
 *   - a legitimate return must still be HONOURED.
 * The second half is what makes the pair worth writing: a guard that simply ignores the filter
 * passes the first test and breaks the hook.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	require_once dirname( __DIR__, 2 ) . '/woodev/api/class-api-base.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin-exception.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/class-control.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/class-setting.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/settings-api/abstract-class-settings.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/utilities/class-woodev-async-request.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/utilities/class-woodev-background-job-handler.php';

	/**
	 * Exposes Woodev_API_Base::get_request_uri() / get_sanitized_request_uri() — both
	 * protected — without needing a real plugin or transport.
	 */
	class Woodev_Testable_Api_Base_For_Guards extends \Woodev_API_Base {

		/**
		 * @param string $request_uri pre-filter request URI.
		 */
		public function __construct( string $request_uri ) {
			$this->request_uri = $request_uri;
		}

		/**
		 * @param array $args unused.
		 * @return null
		 */
		protected function get_new_request( $args = array() ) {
			return null;
		}

		/** @return null */
		protected function get_plugin() {
			return null;
		}

		/** @return string */
		protected function get_api_id() {
			return 'test-api';
		}

		/** @return string */
		public function get_request_uri_public(): string {
			return $this->get_request_uri();
		}

		/** @return string */
		public function get_sanitized_request_uri_public(): string {
			return $this->get_sanitized_request_uri();
		}
	}

	/**
	 * Settings handler double whose register_settings() is driven per-test, so both the
	 * setting-type and control-type registration guards can be exercised with the real
	 * register_setting() / register_control() consumers.
	 */
	class Woodev_Testable_Settings_For_Guards extends \Woodev_Abstract_Settings {

		/** @return void */
		protected function register_settings(): void {
			// Intentionally empty; tests register settings/controls explicitly.
		}
	}

	/**
	 * Minimal handler exposing the real get_job() / get_jobs() / time_exceeded() /
	 * schedule_cron_healthcheck() — no test override — so the filter guards under test run
	 * for real, against a mocked $wpdb.
	 */
	class Woodev_Testable_Job_Handler_For_Guards extends \Woodev_Background_Job_Handler {

		/**
		 * Avoids the real constructor's WP hook wiring.
		 */
		public function __construct() {
			$this->identifier = 'test_job';
		}

		/**
		 * @param int $start_time start_time to force for time_exceeded() arithmetic.
		 * @return void
		 */
		public function set_start_time( int $start_time ): void {
			$this->start_time = $start_time;
		}

		/** @return bool */
		public function time_exceeded_public(): bool {
			return $this->time_exceeded();
		}

		/**
		 * @param mixed  $item unused.
		 * @param object $job  unused.
		 * @return void
		 */
		protected function process_item( $item, $job ) {}
	}
}

namespace Woodev\Framework\Setup {

	require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-step.php';
	require_once dirname( __DIR__, 2 ) . '/woodev/setup/class-setup-wizard.php';

	/**
	 * Exposes build_steps() without running the real constructor (which requires a
	 * Woodev_Plugin and wires REST/admin hooks).
	 */
	class Woodev_Testable_Setup_Wizard_For_Guards extends Setup_Wizard {

		/** @return void */
		protected function register_steps(): void {
			$this->register_step( 'welcome', 'Welcome', [] );
		}

		/** @return string */
		public function get_id(): string {
			return 'test-wizard';
		}

		/** @return void */
		public function build_steps_public(): void {
			$this->build_steps();
		}
	}
}

namespace Woodev\Tests\Unit {

	use Brain\Monkey\Functions;
	use Mockery;

	/**
	 * @coversNothing
	 */
	final class CoreFilterReturnGuardsTest extends TestCase {

		/**
		 * @return void
		 */
		protected function setUp(): void {
			parent::setUp();

			Functions\when( 'wp_parse_args' )->alias(
				static function ( $args, $defaults = [] ) {
					return array_merge( (array) $defaults, (array) $args );
				}
			);
			Functions\when( 'sanitize_key' )->alias( static function ( $key ) {
				return (string) $key;
			} );
			Functions\when( '_doing_it_wrong' )->justReturn( null );
			Functions\when( 'absint' )->alias( static function ( $value ) {
				return abs( (int) $value );
			} );
		}

		/* ------------------------------------------------------------------ *
		 * Woodev_API_Base::get_request_uri() — `woodev_{api_id}_api_request_uri`,
		 * the URI actually sent to the server.
		 * ------------------------------------------------------------------ */

		/**
		 * @return void
		 */
		public function test_get_request_uri_falls_back_when_the_filter_returns_a_non_string(): void {
			$api = new \Woodev_Testable_Api_Base_For_Guards( 'https://example.com/live' );

			$this->filter_returns( 'woodev_test-api_api_request_uri', [ 'not', 'a', 'string' ] );

			$this->assertSame( 'https://example.com/live', $api->get_request_uri_public() );
		}

		/**
		 * The control: a filter that returns a real string is still honoured.
		 *
		 * @return void
		 */
		public function test_get_request_uri_honours_a_filter_that_returns_a_string(): void {
			$api = new \Woodev_Testable_Api_Base_For_Guards( 'https://example.com/live' );

			$this->filter_returns( 'woodev_test-api_api_request_uri', 'https://example.com/replaced' );

			$this->assertSame( 'https://example.com/replaced', $api->get_request_uri_public() );
		}

		/* ------------------------------------------------------------------ *
		 * Woodev_API_Base::get_sanitized_request_uri() — same hook, second
		 * independent call site, for the logged/redacted version.
		 * ------------------------------------------------------------------ */

		/**
		 * @return void
		 */
		public function test_get_sanitized_request_uri_falls_back_when_the_filter_returns_a_non_string(): void {
			$api = new \Woodev_Testable_Api_Base_For_Guards( 'https://example.com/live' );

			$this->filter_returns( 'woodev_test-api_api_request_uri', new \stdClass() );

			$this->assertSame( 'https://example.com/live', $api->get_sanitized_request_uri_public() );
		}

		/**
		 * The control.
		 *
		 * @return void
		 */
		public function test_get_sanitized_request_uri_honours_a_filter_that_returns_a_string(): void {
			$api = new \Woodev_Testable_Api_Base_For_Guards( 'https://example.com/live' );

			$this->filter_returns( 'woodev_test-api_api_request_uri', 'https://example.com/replaced' );

			$this->assertSame( 'https://example.com/replaced', $api->get_sanitized_request_uri_public() );
		}

		/* ------------------------------------------------------------------ *
		 * Woodev_Abstract_Settings::get_setting_types() — `..._settings_api_setting_types`,
		 * consumed by register_setting()'s in_array(), which throws a TypeError on a
		 * non-array haystack.
		 * ------------------------------------------------------------------ */

		/**
		 * @return void
		 */
		public function test_register_setting_does_not_fatal_when_the_setting_types_filter_returns_garbage(): void {
			$settings = new \Woodev_Testable_Settings_For_Guards( 'test-settings' );

			$this->filter_returns( 'woodev_test-settings_settings_api_setting_types', 'not an array' );

			// TYPE_STRING is in the pre-filter default list, so registration succeeds without
			// ever reaching in_array() with a non-array haystack.
			$this->assertTrue( $settings->register_setting( 'greeting', \Woodev_Setting::TYPE_STRING ) );
		}

		/**
		 * The control: a real replacement list is honoured, not ignored.
		 *
		 * @return void
		 */
		public function test_register_setting_honours_a_setting_types_filter_that_returns_an_array(): void {
			$settings = new \Woodev_Testable_Settings_For_Guards( 'test-settings' );

			$this->filter_returns( 'woodev_test-settings_settings_api_setting_types', [ 'custom_type' ] );

			// 'custom_type' is only valid because the filter's own array was used.
			$this->assertTrue( $settings->register_setting( 'custom', 'custom_type' ) );
		}

		/* ------------------------------------------------------------------ *
		 * Woodev_Abstract_Settings::get_control_types() — `..._settings_api_control_types`,
		 * consumed by register_control()'s in_array().
		 * ------------------------------------------------------------------ */

		/**
		 * @return void
		 */
		public function test_register_control_does_not_fatal_when_the_control_types_filter_returns_garbage(): void {
			$settings = new \Woodev_Testable_Settings_For_Guards( 'test-settings' );
			$settings->register_setting( 'greeting', \Woodev_Setting::TYPE_STRING );

			$this->filter_returns( 'woodev_test-settings_settings_api_control_types', 'not an array' );

			// TYPE_TEXT is in the pre-filter default list, so registration succeeds without
			// ever reaching in_array() with a non-array haystack.
			$this->assertTrue( $settings->register_control( 'greeting', \Woodev_Control::TYPE_TEXT ) );
		}

		/**
		 * The control: a real replacement list is honoured — a type NOT in it is rejected.
		 *
		 * @return void
		 */
		public function test_register_control_honours_a_control_types_filter_that_returns_an_array(): void {
			$settings = new \Woodev_Testable_Settings_For_Guards( 'test-settings' );
			$settings->register_setting( 'greeting', \Woodev_Setting::TYPE_STRING );

			$this->filter_returns( 'woodev_test-settings_settings_api_control_types', [ 'custom_control' ] );

			$this->assertTrue( $settings->register_control( 'greeting', 'custom_control' ) );
			$this->assertFalse( $settings->register_control( 'greeting', \Woodev_Control::TYPE_TEXT ) );
		}

		/* ------------------------------------------------------------------ *
		 * Woodev_Abstract_Settings::get_setting_control_types() —
		 * `..._settings_api_setting_control_types`, consumed by register_control()'s
		 * second in_array(), guarded only by `! empty()`.
		 * ------------------------------------------------------------------ */

		/**
		 * @return void
		 */
		public function test_register_control_does_not_fatal_when_the_setting_control_types_filter_returns_garbage(): void {
			$settings = new \Woodev_Testable_Settings_For_Guards( 'test-settings' );
			$settings->register_setting( 'greeting', \Woodev_Setting::TYPE_STRING );

			// Non-empty non-array: `! empty()` alone does not filter this out.
			$this->filter_returns( 'woodev_test-settings_settings_api_setting_control_types', 'not an array' );

			$this->assertTrue( $settings->register_control( 'greeting', \Woodev_Control::TYPE_TEXT ) );
		}

		/**
		 * The control: a real restriction list is honoured — a type NOT in it is rejected.
		 *
		 * @return void
		 */
		public function test_register_control_honours_a_setting_control_types_filter_that_returns_an_array(): void {
			$settings = new \Woodev_Testable_Settings_For_Guards( 'test-settings' );
			$settings->register_setting( 'greeting', \Woodev_Setting::TYPE_STRING );

			$this->filter_returns(
				'woodev_test-settings_settings_api_setting_control_types',
				[ \Woodev_Control::TYPE_TEXTAREA ]
			);

			$this->assertTrue( $settings->register_control( 'greeting', \Woodev_Control::TYPE_TEXTAREA ) );
			$this->assertFalse( $settings->register_control( 'greeting', \Woodev_Control::TYPE_TEXT ) );
		}

		/* ------------------------------------------------------------------ *
		 * Woodev_Background_Job_Handler::get_job() — `{identifier}_returned_job`, on
		 * the background/cron path where a fatal here fails invisibly.
		 * ------------------------------------------------------------------ */

		/**
		 * @return void
		 */
		public function test_get_job_falls_back_to_the_db_sourced_job_when_the_filter_returns_a_non_object(): void {
			$this->stub_wpdb_get_var( wp_json_encode_stub( [ 'id' => 'job-1', 'status' => 'queued' ] ) );

			$this->filter_returns( 'test_job_returned_job', 'not an object' );

			$job = ( new \Woodev_Testable_Job_Handler_For_Guards() )->get_job();

			$this->assertIsObject( $job );
			$this->assertSame( 'job-1', $job->id );
			$this->assertSame( 'queued', $job->status );
		}

		/**
		 * The control.
		 *
		 * @return void
		 */
		public function test_get_job_honours_a_filter_that_returns_a_real_job_object(): void {
			$this->stub_wpdb_get_var( wp_json_encode_stub( [ 'id' => 'job-1', 'status' => 'queued' ] ) );

			$replacement = (object) [ 'id' => 'job-replaced' ];
			$this->filter_returns( 'test_job_returned_job', $replacement );

			$job = ( new \Woodev_Testable_Job_Handler_For_Guards() )->get_job();

			$this->assertSame( $replacement, $job );
		}

		/* ------------------------------------------------------------------ *
		 * Woodev_Background_Job_Handler::get_jobs() — same hook, second
		 * independent call site (batch listing).
		 * ------------------------------------------------------------------ */

		/**
		 * @return void
		 */
		public function test_get_jobs_falls_back_to_the_db_sourced_job_when_the_filter_returns_a_non_object(): void {
			$this->stub_wpdb_get_col( [ wp_json_encode_stub( [ 'id' => 'job-2', 'status' => 'queued' ] ) ] );

			$this->filter_returns( 'test_job_returned_job', false );

			$jobs = ( new \Woodev_Testable_Job_Handler_For_Guards() )->get_jobs();

			$this->assertCount( 1, $jobs );
			$this->assertSame( 'job-2', $jobs[0]->id );
		}

		/**
		 * The control.
		 *
		 * @return void
		 */
		public function test_get_jobs_honours_a_filter_that_returns_a_real_job_object(): void {
			$this->stub_wpdb_get_col( [ wp_json_encode_stub( [ 'id' => 'job-2', 'status' => 'queued' ] ) ] );

			$replacement = (object) [ 'id' => 'job-replaced' ];
			$this->filter_returns( 'test_job_returned_job', $replacement );

			$jobs = ( new \Woodev_Testable_Job_Handler_For_Guards() )->get_jobs();

			$this->assertSame( [ $replacement ], $jobs );
		}

		/* ------------------------------------------------------------------ *
		 * Woodev_Background_Job_Handler::time_exceeded() — `{identifier}_default_time_limit`
		 * feeds `+` arithmetic directly, and degrades to the PRE-FILTER limit.
		 * NOT absint(): that turns garbage into 0, and a limit of 0 makes the very
		 * first check report "time exceeded", stopping every background job before
		 * it processes an item.
		 * ------------------------------------------------------------------ */

		/**
		 * @return void
		 */
		public function test_time_exceeded_does_not_fatal_on_a_non_numeric_filter_return(): void {
			$handler = new \Woodev_Testable_Job_Handler_For_Guards();
			$handler->set_start_time( time() - 5 );

			$this->filter_returns( 'test_job_default_time_limit', 'not-a-number' );

			// Degrades to the pre-filter 20s, which has not elapsed: NOT exceeded.
			// An absint() cast would give 0 here and answer true — a background job
			// runner that stops before doing anything, which is the protection this
			// guard exists to keep.
			$this->assertFalse( $handler->time_exceeded_public() );
		}

		/**
		 * A NEGATIVE numeric return is refused the same way — `-1` would put
		 * `$finish` in the past and disable the runner just as effectively as `0`.
		 *
		 * @return void
		 */
		public function test_time_exceeded_refuses_a_non_positive_filter_return(): void {
			$handler = new \Woodev_Testable_Job_Handler_For_Guards();
			$handler->set_start_time( time() - 5 );

			$this->filter_returns( 'test_job_default_time_limit', -1 );

			$this->assertFalse( $handler->time_exceeded_public() );
		}

		/**
		 * The control: a real numeric override changes the arithmetic outcome.
		 *
		 * @return void
		 */
		public function test_time_exceeded_honours_a_legitimate_numeric_filter_return(): void {
			$handler = new \Woodev_Testable_Job_Handler_For_Guards();
			$handler->set_start_time( time() - 1000000 );

			// start_time + 2,000,000s lands well in the future: not exceeded.
			$this->filter_returns( 'test_job_default_time_limit', 2000000 );

			$this->assertFalse( $handler->time_exceeded_public() );
		}

		/* ------------------------------------------------------------------ *
		 * Woodev_Background_Job_Handler::schedule_cron_healthcheck() —
		 * `{identifier}_cron_interval` feeds `*` arithmetic directly.
		 * ------------------------------------------------------------------ */

		/**
		 * @return void
		 */
		public function test_schedule_cron_healthcheck_does_not_fatal_on_a_non_numeric_filter_return(): void {
			$handler = new \Woodev_Testable_Job_Handler_For_Guards();

			$this->filter_returns( 'test_job_cron_interval', 'not-a-number' );

			$schedules = $handler->schedule_cron_healthcheck( [] );

			// Degrades to the pre-filter 5 minutes. An absint() cast would register
			// a schedule with interval 0 — a WP-Cron entry that never advances, plus
			// an "Every 0 Minutes" label in the admin.
			$this->assertSame( 5 * MINUTE_IN_SECONDS, $schedules['test_job_cron_interval']['interval'] );
		}

		/**
		 * A non-positive numeric return is refused for the same reason.
		 *
		 * @return void
		 */
		public function test_schedule_cron_healthcheck_refuses_a_non_positive_filter_return(): void {
			$handler = new \Woodev_Testable_Job_Handler_For_Guards();

			$this->filter_returns( 'test_job_cron_interval', 0 );

			$schedules = $handler->schedule_cron_healthcheck( [] );

			$this->assertSame( 5 * MINUTE_IN_SECONDS, $schedules['test_job_cron_interval']['interval'] );
		}

		/**
		 * The control.
		 *
		 * @return void
		 */
		public function test_schedule_cron_healthcheck_honours_a_legitimate_numeric_filter_return(): void {
			$handler = new \Woodev_Testable_Job_Handler_For_Guards();

			$this->filter_returns( 'test_job_cron_interval', 15 );

			$schedules = $handler->schedule_cron_healthcheck( [] );

			$this->assertSame( 15 * MINUTE_IN_SECONDS, $schedules['test_job_cron_interval']['interval'] );
		}

		/* ------------------------------------------------------------------ *
		 * Setup_Wizard::build_steps() — `woodev_{id}_setup_wizard_steps`, feeds
		 * array_filter() directly, which throws a TypeError on a non-array argument.
		 * ------------------------------------------------------------------ */

		/**
		 * @return void
		 */
		public function test_build_steps_falls_back_when_the_filter_returns_a_non_array(): void {
			$wizard = $this->wizard();

			$this->filter_returns( 'woodev_test-wizard_setup_wizard_steps', 'not an array' );

			$wizard->build_steps_public();

			$steps = $wizard->get_steps();

			$this->assertArrayHasKey( 'welcome', $steps );
			$this->assertCount( 1, $steps );
		}

		/**
		 * The control: a real replacement step list is honoured.
		 *
		 * @return void
		 */
		public function test_build_steps_honours_a_filter_that_returns_an_array(): void {
			$wizard = $this->wizard();

			$replacement = [ 'extra' => \Woodev\Framework\Setup\Step::settings( 'extra', 'Extra', [] ) ];
			$this->filter_returns( 'woodev_test-wizard_setup_wizard_steps', $replacement );

			$wizard->build_steps_public();

			$steps = $wizard->get_steps();

			$this->assertArrayHasKey( 'extra', $steps );
			$this->assertArrayNotHasKey( 'welcome', $steps );
		}

		/* ------------------------------------------------------------------ *
		 * Helpers
		 * ------------------------------------------------------------------ */

		/**
		 * Builds a wizard test double without running the real constructor (which
		 * requires a Woodev_Plugin and wires REST/admin hooks).
		 *
		 * @return \Woodev\Framework\Setup\Woodev_Testable_Setup_Wizard_For_Guards
		 */
		private function wizard(): \Woodev\Framework\Setup\Woodev_Testable_Setup_Wizard_For_Guards {
			$reflection = new \ReflectionClass( \Woodev\Framework\Setup\Woodev_Testable_Setup_Wizard_For_Guards::class );

			return $reflection->newInstanceWithoutConstructor();
		}

		/**
		 * Stubs global $wpdb for a get_job()-style single-row lookup.
		 *
		 * @param string $json_row encoded option_value the query returns.
		 * @return void
		 */
		private function stub_wpdb_get_var( string $json_row ): void {
			global $wpdb;

			$wpdb          = Mockery::mock();
			$wpdb->options = 'options';
			$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
				static function ( $query ) {
					return $query;
				}
			);
			$wpdb->shouldReceive( 'get_var' )->andReturn( $json_row );
		}

		/**
		 * Stubs global $wpdb for a get_jobs()-style multi-row lookup.
		 *
		 * @param array<int, string> $json_rows encoded option_values the query returns.
		 * @return void
		 */
		private function stub_wpdb_get_col( array $json_rows ): void {
			global $wpdb;

			$wpdb          = Mockery::mock();
			$wpdb->options = 'options';
			$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
				static function ( $query ) {
					return $query;
				}
			);
			$wpdb->shouldReceive( 'get_col' )->andReturn( $json_rows );
		}

		/**
		 * Makes `apply_filters()` return $value for $hook and the unfiltered value otherwise.
		 *
		 * @param string $hook  hook name to intercept.
		 * @param mixed  $value what the plugin returns.
		 * @return void
		 */
		private function filter_returns( string $hook, $value ): void {
			Functions\when( 'apply_filters' )->alias(
				static function ( $tag, $filtered = null ) use ( $hook, $value ) {
					return $hook === $tag ? $value : $filtered;
				}
			);
		}
	}

	/**
	 * json_encode() wrapper, named to read clearly at each get_job()/get_jobs() call site
	 * as "what the DB option_value actually holds" — real json_encode(), not a WP stub.
	 *
	 * @param array $data data to encode.
	 * @return string
	 */
	function wp_json_encode_stub( array $data ): string {
		return (string) json_encode( $data );
	}
}
