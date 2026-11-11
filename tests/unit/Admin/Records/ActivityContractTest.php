<?php
namespace Woodev\Tests\Unit\Admin\Records;

use Brain\Monkey\Functions;
use Woodev\Framework\Admin\Records\{Activity_Normalizer, Records_Definition, Records_Registry, Records_Rest_Controller};
use Woodev\Tests\Unit\TestCase;

require_once __DIR__ . '/Activity_Fixtures.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-base.php';
require_once dirname( __DIR__, 4 ) . '/woodev/rest-api/class-rest-v1-registrar.php';

/**
 * The activity contract: `RowActivity`, `account_activity` and `POST R/activity`, against a fixture provider.
 */
class ActivityContractTest extends TestCase {

	/** @var Records_Definition */
	private $definition;

	/** @var Records_Rest_Controller */
	private $controller;

	/** @var Activity_Fixture_Provider */
	private $provider;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $v ) => trim( strip_tags( $v ) ) );
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->definition = Records_Definition::from_array( Activity_States::definition() );

		Records_Registry::reset_for_tests();
		$this->provider = new Activity_Fixture_Provider( 'products', Activity_States::definition() );
		Records_Registry::instance()->register( 'ozon-seller', $this->provider );
		$this->controller = new Records_Rest_Controller( Records_Registry::instance() );
	}

	protected function tearDown(): void {
		Records_Registry::reset_for_tests();
		parent::tearDown();
	}

	/**
	 * @param array<string,mixed> $body Request body.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function post( array $body ) {
		return $this->controller->post_activity( new Activity_Test_Request( $body ) );
	}

	/** @return array<string,mixed> */
	private function read( array $ids, array $result = [] ): array {
		$this->provider->activity_result = $result;
		$response                        = $this->post( [ 'context' => [ 'account_id' => 'a1' ], 'ids' => $ids ] );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );

		return $response->get_data();
	}

	private function assertError( $result, string $code, int $status ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $status, $result->get_error_data()['status'] );
	}

	// -- RowActivity ------------------------------------------------------------------------------

	public function test_a_row_without_activity_is_idle(): void {
		$activity = Activity_Normalizer::row( $this->definition, null );

		$this->assertFalse( $activity['locked'] );
		$this->assertSame( [], $activity['blocked_actions'] );
		$this->assertSame( [], $activity['processes'] );
		$this->assertMatchesRegularExpression( Activity_Normalizer::TIME_PATTERN, $activity['observed_at'] );
	}

	public function test_a_locked_row_keeps_its_reason_and_blocked_actions(): void {
		$activity = Activity_Normalizer::row( $this->definition, Activity_States::locked( [ Activity_States::process( 'obs-1' ) ] ) );

		$this->assertTrue( $activity['locked'] );
		$this->assertSame( 'Товар уже отправляется на маркетплейс.', $activity['reason'] );
		$this->assertSame( [ 'sync', 'sync_all' ], $activity['blocked_actions'] );
		$this->assertSame( '2026-11-11T10:00:00Z', $activity['observed_at'] );
	}

	public function test_several_processes_coexist_each_with_its_own_progress(): void {
		$activity = Activity_Normalizer::row(
			$this->definition,
			Activity_States::locked(
				[
					Activity_States::process( 'obs-stock', [ 'label' => 'Остатки', 'completed' => 1, 'total' => 2, 'percent' => 50 ] ),
					Activity_States::process( 'obs-price', [ 'state' => 'queued', 'label' => 'Цены', 'completed' => 0, 'total' => 4, 'percent' => 0 ] ),
					Activity_States::process( 'obs-card', [ 'state' => 'failed', 'label' => 'Карточка', 'completed' => null, 'total' => null, 'percent' => null ] ),
				]
			)
		);

		$this->assertSame( [ 'obs-stock', 'obs-price', 'obs-card' ], array_column( $activity['processes'], 'id' ) );
		$this->assertSame( [ 50, 0, null ], array_column( $activity['processes'], 'percent' ) );
		$this->assertSame( [ 'running', 'queued', 'failed' ], array_column( $activity['processes'], 'state' ) );
	}

	public function test_an_indeterminate_process_has_null_counts_and_no_percent(): void {
		$activity = Activity_Normalizer::row( $this->definition, [ 'processes' => [ Activity_States::indeterminate( 'obs-1' ) ] ] );

		$process = $activity['processes'][0];
		$this->assertNull( $process['completed'] );
		$this->assertNull( $process['total'] );
		$this->assertNull( $process['percent'] );
		$this->assertSame( 'Идёт обработка', $process['text'] );
	}

	public function test_a_percent_without_both_counts_is_a_broken_contract(): void {
		$this->expectException( \UnexpectedValueException::class );

		Activity_Normalizer::row( $this->definition, [ 'processes' => [ Activity_States::process( 'obs-1', [ 'total' => null, 'percent' => 40 ] ) ] ] );
	}

	/**
	 * @dataProvider broken_activity
	 *
	 * @param array<string,mixed> $activity Broken value.
	 */
	public function test_a_provider_that_breaks_the_shape_is_refused( array $activity ): void {
		$this->expectException( \UnexpectedValueException::class );

		Activity_Normalizer::row( $this->definition, $activity );
	}

	/** @return array<string,array{0:array<string,mixed>}> */
	public function broken_activity(): array {
		$process = Activity_States::process( 'obs-1' );

		return [
			'a lock without a reason'          => [ [ 'locked' => true ] ],
			'a blocked action without a reason' => [ [ 'blocked_actions' => [ 'sync' ] ] ],
			'an undeclared blocked action'     => [ [ 'reason' => 'x', 'blocked_actions' => [ 'delete_all' ] ] ],
			'a repeated blocked action'        => [ [ 'reason' => 'x', 'blocked_actions' => [ 'sync', 'sync' ] ] ],
			'a non-boolean lock'               => [ [ 'locked' => 'yes', 'reason' => 'x' ] ],
			'an unknown process state'         => [ [ 'processes' => [ Activity_States::process( 'obs-1', [ 'state' => 'stuck' ] ) ] ] ],
			'a process without a label'        => [ [ 'processes' => [ Activity_States::process( 'obs-1', [ 'label' => '' ] ) ] ] ],
			'completed above total'            => [ [ 'processes' => [ Activity_States::process( 'obs-1', [ 'completed' => 9, 'total' => 8, 'percent' => null ] ) ] ] ],
			'a negative count'                 => [ [ 'processes' => [ Activity_States::process( 'obs-1', [ 'completed' => -1 ] ) ] ] ],
			'a percent above 100'              => [ [ 'processes' => [ Activity_States::process( 'obs-1', [ 'completed' => 8, 'total' => 8, 'percent' => 101 ] ) ] ] ],
			'a process id that is not opaque'  => [ [ 'processes' => [ Activity_States::process( 'a b' ) ] ] ],
			'a repeated process id'            => [ [ 'processes' => [ $process, $process ] ] ],
			'too many processes'               => [ [ 'processes' => array_map( [ Activity_States::class, 'indeterminate' ], array_map( static fn( $n ) => 'obs-' . $n, range( 1, 11 ) ) ) ] ],
			'a time without a zone'            => [ [ 'observed_at' => '2026-11-11 10:00:00' ] ],
			'not a map'                        => [ [ 'processes' => 'running' ] ],
		];
	}

	public function test_keys_the_shape_does_not_know_are_dropped_not_forwarded(): void {
		$process = Activity_States::process( 'obs-1' ) + [ 'claim_token' => 'secret-token', 'payload' => [ 'sku' => 'A' ] ];
		$out     = Activity_Normalizer::row( $this->definition, [ 'processes' => [ $process ], 'task_hash' => 'abc' ] );

		$this->assertSame( [ 'locked', 'reason', 'blocked_actions', 'processes', 'observed_at' ], array_keys( $out ) );
		$this->assertSame( [ 'id', 'state', 'label', 'completed', 'total', 'percent', 'text' ], array_keys( $out['processes'][0] ) );
		$this->assertStringNotContainsString( 'secret-token', json_encode( $out ) );
	}

	public function test_markup_in_a_reason_or_label_is_stripped(): void {
		$out = Activity_Normalizer::row(
			$this->definition,
			[ 'locked' => true, 'reason' => '<b>Занято</b><script>x()</script>', 'processes' => [ Activity_States::process( 'obs-1', [ 'label' => '<i>Цены</i>' ] ) ] ]
		);

		$this->assertSame( 'Занятоx()', $out['reason'] );
		$this->assertSame( 'Цены', $out['processes'][0]['label'] );
	}

	// -- account activity -------------------------------------------------------------------------

	public function test_account_activity_carries_the_line_and_the_blocked_account_actions(): void {
		$out = Activity_Normalizer::account(
			$this->definition,
			[ 'active' => true, 'text' => 'По аккаунту идёт фоновая работа.', 'blocked_actions' => [ 'sync_all' ] ]
		);

		$this->assertTrue( $out['active'] );
		$this->assertSame( 'По аккаунту идёт фоновая работа.', $out['text'] );
		$this->assertSame( [ 'sync_all' ], $out['blocked_actions'] );
	}

	public function test_no_account_activity_is_idle_and_an_active_one_must_say_what_is_going_on(): void {
		$this->assertFalse( Activity_Normalizer::account( $this->definition, null )['active'] );

		$this->expectException( \UnexpectedValueException::class );
		Activity_Normalizer::account( $this->definition, [ 'active' => true ] );
	}

	// -- the list and children reads --------------------------------------------------------------

	public function test_work_started_elsewhere_is_in_the_first_list_read(): void {
		$this->provider->rows   = [
			Activity_States::row( 'p:1', Activity_States::locked( [ Activity_States::indeterminate( 'cron-1' ) ] ) ),
			Records_Definitions::row( 'p:2' ),
		];
		$this->provider->activity_result = [];

		$data = $this->controller->get_rows( new Records_Test_Request( [ 'owner' => 'ozon-seller', 'table' => 'products' ], [] ) )->get_data();

		$this->assertTrue( $data['rows'][0]['activity']['locked'] );
		$this->assertSame( 'cron-1', $data['rows'][0]['activity']['processes'][0]['id'] );
		$this->assertFalse( $data['rows'][1]['activity']['locked'], 'a row the provider says nothing about is idle' );
		$this->assertFalse( $data['account_activity']['active'] );
	}

	public function test_the_list_carries_account_activity_but_children_do_not(): void {
		$this->provider->rows        = [ Records_Definitions::row( 'p:1' ) ];
		$this->provider->child_rows  = [ Records_Definitions::row( 'v:1' ) ];
		$list                        = $this->controller->get_rows( new Records_Test_Request( [ 'owner' => 'ozon-seller', 'table' => 'products' ], [] ) )->get_data();
		$children                    = $this->controller->get_children( new Records_Test_Request( [ 'owner' => 'ozon-seller', 'table' => 'products', 'id' => 'p:1' ], [] ) )->get_data();

		$this->assertArrayHasKey( 'account_activity', $list );
		$this->assertArrayNotHasKey( 'account_activity', $children );
		$this->assertArrayHasKey( 'activity', $children['rows'][0], 'a child row has its own activity' );
	}

	// -- POST R/activity --------------------------------------------------------------------------

	public function test_the_activity_response_has_the_documented_shape(): void {
		$data = $this->read( [ 'p:1' ], [ 'rows' => [ Activity_States::row( 'p:1', Activity_States::locked( [ Activity_States::process( 'obs-1' ) ] ) ) ] ] );

		$this->assertSame( [ 'rows', 'removed_ids', 'account_activity', 'observed_at', 'refresh_after_ms', 'messages' ], array_keys( $data ) );
		$this->assertSame( 'p:1', $data['rows'][0]['id'] );
		$this->assertSame( [ 'sync', 'sync_all' ], $data['rows'][0]['activity']['blocked_actions'] );
		$this->assertSame( [], $data['removed_ids'] );
		$this->assertEquals( new \stdClass(), $data['messages'] );
		$this->assertMatchesRegularExpression( Activity_Normalizer::TIME_PATTERN, $data['observed_at'] );
	}

	public function test_the_response_is_private_and_uncacheable(): void {
		$this->provider->activity_result = [];
		$response                        = $this->post( [ 'context' => [ 'account_id' => 'a1' ], 'ids' => [] ] );

		$this->assertSame( 'private, no-store', $response->get_headers()['Cache-Control'] );
	}

	public function test_reading_activity_asks_the_provider_only_for_local_state(): void {
		$this->read( [ 'p:1', 'p:2' ] );

		$this->assertSame( [ 'get_definition', 'authorize', 'get_activity' ], $this->provider->calls );
		$this->assertSame( [ [ 'account_id' => 'a1' ], [ 'p:1', 'p:2' ] ], $this->provider->last['activity'] );
		$this->assertSame( 'activity', $this->provider->last['authorize'][0][0] );
		$this->assertNotContains( 'query', $this->provider->calls );
		$this->assertNotContains( 'apply', $this->provider->calls );
	}

	public function test_the_cadence_follows_the_work(): void {
		$busy  = $this->read( [ 'p:1' ], [ 'rows' => [ Activity_States::row( 'p:1', [ 'processes' => [ Activity_States::process( 'obs-1', [ 'state' => 'queued', 'completed' => null, 'total' => null, 'percent' => null ] ) ] ] ) ] ] );
		$done  = $this->read( [ 'p:1' ], [ 'rows' => [ Activity_States::row( 'p:1', [ 'processes' => [ Activity_States::process( 'obs-1', [ 'state' => 'completed', 'completed' => 8, 'total' => 8, 'percent' => 100 ] ) ] ] ) ] ] );
		$acct  = $this->read( [], [ 'account_activity' => [ 'active' => true, 'text' => 'Идёт работа.' ] ] );
		$quiet = $this->read( [ 'p:1' ] );

		$this->assertSame( 2000, $busy['refresh_after_ms'], 'queued work: the table cadence' );
		$this->assertSame( 30000, $done['refresh_after_ms'], 'finished work: the slow ceiling' );
		$this->assertSame( 2000, $acct['refresh_after_ms'], 'account work counts too' );
		$this->assertSame( 30000, $quiet['refresh_after_ms'] );
	}

	public function test_removed_ids_are_reported_and_a_row_cannot_be_both_present_and_removed(): void {
		$data = $this->read( [ 'p:1', 'p:2' ], [ 'rows' => [ Records_Definitions::row( 'p:1' ) ], 'removed_ids' => [ 'p:2' ] ] );

		$this->assertSame( [ 'p:2' ], $data['removed_ids'] );

		$this->provider->activity_result = [ 'rows' => [ Records_Definitions::row( 'p:1' ) ], 'removed_ids' => [ 'p:1' ] ];
		$this->assertError( $this->post( [ 'context' => [ 'account_id' => 'a1' ], 'ids' => [ 'p:1' ] ] ), 'woodev_records_invalid_provider_response', 500 );
	}

	/**
	 * @dataProvider provider_answers_that_break_the_contract
	 *
	 * @param array<string,mixed> $result What get_activity() returns.
	 */
	public function test_a_provider_answer_that_breaks_the_contract_is_a_generic_500( array $result ): void {
		$this->provider->activity_result = $result;

		$this->assertError( $this->post( [ 'context' => [ 'account_id' => 'a1' ], 'ids' => [ 'p:1' ] ] ), 'woodev_records_invalid_provider_response', 500 );
	}

	/** @return array<string,array{0:array<string,mixed>}> */
	public function provider_answers_that_break_the_contract(): array {
		return [
			'a row nobody asked about'     => [ [ 'rows' => [ Records_Definitions::row( 'p:99' ) ] ] ],
			'removed ids nobody asked for' => [ [ 'removed_ids' => [ 'p:99' ] ] ],
			'more rows than ids'           => [ [ 'rows' => [ Records_Definitions::row( 'p:1' ), Records_Definitions::row( 'p:2' ) ] ] ],
			'a lock with no reason'        => [ [ 'rows' => [ Records_Definitions::row( 'p:1', [ 'activity' => [ 'locked' => true ] ] ) ] ] ],
			'a broken account line'        => [ [ 'account_activity' => [ 'active' => true ] ] ],
		];
	}

	public function test_a_throwing_provider_is_a_generic_500(): void {
		Functions\when( 'error_log' )->justReturn( true );
		$this->provider->activity_throw = new \RuntimeException( 'database is down' );

		$this->assertError( $this->post( [ 'context' => [ 'account_id' => 'a1' ], 'ids' => [ 'p:1' ] ] ), 'woodev_records_provider_error', 500 );
	}

	// -- request validation -----------------------------------------------------------------------

	/**
	 * @dataProvider bad_requests
	 *
	 * @param array<string,mixed>|null $body Request body.
	 */
	public function test_a_bad_request_is_a_400_before_the_provider_is_asked_for_anything_but_the_definition( $body ): void {
		$result = $this->controller->post_activity( new Activity_Test_Request( $body ) );

		$this->assertError( $result, 'woodev_records_invalid_param', 400 );
		$this->assertNotContains( 'get_activity', $this->provider->calls );
		$this->assertNotContains( 'authorize', $this->provider->calls );
	}

	/** @return array<string,array{0:mixed}> */
	public function bad_requests(): array {
		$context = [ 'account_id' => 'a1' ];

		return [
			'a body that is not a map' => [ 'p:1' ],
			'an unknown key'           => [ [ 'context' => $context, 'ids' => [], 'claim' => true ] ],
			'ids that are not a list'  => [ [ 'context' => $context, 'ids' => 'p:1' ] ],
			'a keyed id map'           => [ [ 'context' => $context, 'ids' => [ 'a' => 'p:1' ] ] ],
			'a non-string id'          => [ [ 'context' => $context, 'ids' => [ 7 ] ] ],
			'a malformed id'           => [ [ 'context' => $context, 'ids' => [ 'a b' ] ] ],
			'a repeated id'            => [ [ 'context' => $context, 'ids' => [ 'p:1', 'p:1' ] ] ],
			'an unknown context key'   => [ [ 'context' => [ 'account_id' => 'a1', 'x' => 'y' ], 'ids' => [] ] ],
		];
	}

	public function test_more_ids_than_the_declared_limit_are_refused_not_truncated(): void {
		$result = $this->post( [ 'context' => [ 'account_id' => 'a1' ], 'ids' => [ 'p:1', 'p:2', 'p:3', 'p:4', 'p:5', 'p:6' ] ] );

		$this->assertError( $result, 'woodev_records_activity_too_large', 400 );
		$this->assertNotContains( 'get_activity', $this->provider->calls );
	}

	public function test_an_empty_id_list_reads_only_the_account_line(): void {
		$data = $this->read( [], [ 'account_activity' => [ 'active' => true, 'text' => 'Идёт работа.', 'blocked_actions' => [ 'sync_all' ] ] ] );

		$this->assertSame( [], $data['rows'] );
		$this->assertSame( [ 'sync_all' ], $data['account_activity']['blocked_actions'] );
	}

	public function test_a_table_that_declares_no_activity_has_no_activity_route(): void {
		Records_Registry::reset_for_tests();
		$plain = new Activity_Fixture_Provider( 'products', Records_Definitions::standard() );
		Records_Registry::instance()->register( 'ozon-seller', $plain );
		$controller = new Records_Rest_Controller( Records_Registry::instance() );

		$result = $controller->post_activity( new Activity_Test_Request( [ 'context' => [ 'account_id' => 'a1' ], 'ids' => [ 'p:1' ] ] ) );

		$this->assertError( $result, 'woodev_records_not_found', 404 );
		$this->assertNotContains( 'get_activity', $plain->calls );
	}

	public function test_a_forged_account_is_not_found_and_reads_nothing(): void {
		$result = $this->controller->post_activity( new Activity_Test_Request( [ 'context' => [ 'account_id' => 'forged' ], 'ids' => [ 'p:1' ] ] ) );

		$this->assertError( $result, 'woodev_records_not_found', 404 );
		$this->assertNotContains( 'get_activity', $this->provider->calls );
	}

	public function test_a_provider_that_refuses_the_ids_gets_a_404_and_reads_nothing(): void {
		$this->provider->authorizer = static function ( string $operation ): bool {
			return 'activity' !== $operation;
		};

		$this->assertError( $this->post( [ 'context' => [ 'account_id' => 'a1' ], 'ids' => [ 'p:1' ] ] ), 'woodev_records_not_found', 404 );
		$this->assertNotContains( 'get_activity', $this->provider->calls );
	}

	public function test_the_route_params_name_the_table_not_the_body(): void {
		$result = $this->controller->post_activity( new Activity_Test_Request( [ 'context' => [ 'account_id' => 'a1' ], 'ids' => [] ], [ 'table' => 'unknown' ] ) );

		$this->assertError( $result, 'woodev_records_not_found', 404 );
	}

	public function test_an_activity_url_query_is_refused(): void {
		$result = $this->controller->post_activity( new Activity_Test_Request( [ 'ids' => [] ], [], [ 'sort' => 'name' ] ) );

		$this->assertError( $result, 'woodev_records_invalid_param', 400 );
	}

	// -- wiring -----------------------------------------------------------------------------------

	public function test_the_activity_route_is_a_post_with_the_same_permission_gate(): void {
		$registered = [];
		Functions\when( 'register_rest_route' )->alias(
			static function ( $namespace, $route, $args ) use ( &$registered ) {
				$registered[ $route ] = $args;
				return true;
			}
		);

		$this->controller->register_routes();

		$routes = array_filter( $registered, static fn( $route ) => '/activity' === substr( $route, -9 ), ARRAY_FILTER_USE_KEY );
		$this->assertCount( 1, $routes );
		$args = reset( $routes );
		$this->assertSame( 'POST', $args['methods'] );
		$this->assertSame( [ $this->controller, 'post_activity' ], $args['callback'] );
		$this->assertSame( [ $this->controller, 'check_permission' ], $args['permission_callback'] );
	}
}
