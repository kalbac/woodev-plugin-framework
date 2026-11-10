<?php
namespace Woodev\Tests\Unit\Admin\Records;

use Brain\Monkey\Functions;
use Woodev\Framework\Admin\Records\{Records_Registry, Records_Rest_Controller};
use Woodev\Tests\Unit\TestCase;

require_once __DIR__ . '/Records_Fixtures.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-base.php';
require_once dirname( __DIR__, 4 ) . '/woodev/rest-api/class-rest-v1-registrar.php';

class RecordsRestControllerTest extends TestCase {

	/** @var Records_Registry */
	private $registry;

	/** @var Records_Rest_Controller */
	private $controller;

	/** @var Fake_Provider Owner "ozon-seller". */
	private $ozon;

	/** @var Fake_Provider Owner "wildberries": an independent provider with the same table id. */
	private $wb;

	/** @var string[] Capabilities the current user holds. */
	private $caps = [ 'manage_woocommerce', 'edit_products' ];

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $v ) => trim( strip_tags( $v ) ) );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => in_array( $cap, $this->caps, true ) );

		Records_Registry::reset_for_tests();
		$this->registry   = Records_Registry::instance();
		$this->ozon       = new Fake_Provider( 'products' );
		$this->wb         = new Fake_Provider( 'products', Records_Definitions::standard( [ 'context' => null ] ) );
		$this->ozon->rows = [ Records_Definitions::row( 'p:1:a1' ), Records_Definitions::row( 'p:2:a1' ) ];
		$this->wb->rows   = [ Records_Definitions::row( 'wb:9' ) ];
		$this->registry->register( 'ozon-seller', $this->ozon );
		$this->registry->register( 'wildberries', $this->wb );
		$this->controller = new Records_Rest_Controller( $this->registry );
	}

	protected function tearDown(): void {
		Records_Registry::reset_for_tests();
		parent::tearDown();
	}

	private function request( array $query = [], string $owner = 'ozon-seller', string $table = 'products', array $url = [] ): Records_Test_Request {
		return new Records_Test_Request( array_merge( [ 'owner' => $owner, 'table' => $table ], $url ), $query );
	}

	private function assertError( $result, string $code, int $status ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $status, $result->get_error_data()['status'] );
	}

	// -- two independent providers -------------------------------------------------------------

	public function test_two_independent_providers_serve_only_their_own_rows(): void {
		$ozon = $this->controller->get_rows( $this->request( [ 'context' => [ 'account_id' => 'a1' ] ] ) );
		$wb   = $this->controller->get_rows( $this->request( [], 'wildberries' ) );

		$this->assertSame( [ 'p:1:a1', 'p:2:a1' ], array_column( $ozon->get_data()['rows'], 'id' ) );
		$this->assertSame( [ 'wb:9' ], array_column( $wb->get_data()['rows'], 'id' ) );
		$this->assertSame( [ 'get_definition', 'authorize', 'query' ], $this->ozon->calls );
		$this->assertNotContains( 'query', array_diff( $this->wb->calls, [ 'get_definition', 'authorize', 'query' ] ) );
		$this->assertSame( [ 'account_id' => 'a1' ], $this->ozon->last['query'][0] );
		$this->assertSame( [ 'account_id' => '' ], $this->wb->last['query'][0], 'a table without a selector gets an empty account' );
	}

	public function test_the_list_envelope_and_headers(): void {
		$response = $this->controller->get_rows( $this->request( [ 'per_page' => '10' ] ) );
		$data     = $response->get_data();

		$this->assertSame( [ 'rows', 'total', 'page', 'per_page', 'total_pages', 'facets', 'messages' ], array_keys( $data ) );
		$this->assertSame( [ 2, 1, 10, 1 ], [ $data['total'], $data['page'], $data['per_page'], $data['total_pages'] ] );
		$this->assertEquals( new \stdClass(), $data['facets'] );
		$this->assertSame( 'private, no-store', $response->get_headers()['Cache-Control'] );
		$this->assertSame( 'a1', $this->ozon->last['query'][0]['account_id'], 'the default account is used for reads' );
		$this->assertSame( 10, $this->ozon->last['query'][1]['per_page'] );
	}

	public function test_the_definition_route_filters_actions_by_capability_and_reports_the_context(): void {
		$this->caps = [ 'manage_woocommerce' ];

		$data = $this->controller->get_definition( $this->request( [ 'context' => [ 'account_id' => 'a2' ] ] ) )->get_data();

		$this->assertSame( 1, $data['schema_version'] );
		$this->assertSame( [ 'account_id' => 'a2' ], $data['context'] );
		$this->assertSame( [ 'open' ], array_column( $data['definition']['row_actions'], 'id' ), 'sync needs edit_products' );
		$this->assertArrayNotHasKey( 'schema_version', $data['definition'] );
		$this->assertNull( $data['definition']['analytics'] );
		$this->assertSame( [ 'definition' ], array_column( $this->ozon->last['authorize'], 0 ) );
	}

	// -- forged account / id -------------------------------------------------------------------

	public function test_a_forged_account_is_not_found_before_any_read(): void {
		$result = $this->controller->get_rows( $this->request( [ 'context' => [ 'account_id' => 'a999' ] ] ) );

		$this->assertError( $result, 'woodev_records_not_found', 404 );
		$this->assertNotContains( 'query', $this->ozon->calls );
		$this->assertNotContains( 'authorize', $this->ozon->calls );
	}

	public function test_a_forged_account_gets_the_same_answer_as_an_unknown_table(): void {
		$forged  = $this->controller->get_rows( $this->request( [ 'context' => [ 'account_id' => 'a999' ] ] ) );
		$unknown = $this->controller->get_rows( $this->request( [], 'ozon-seller', 'ghost' ) );

		$this->assertSame( $forged->get_error_code(), $unknown->get_error_code() );
		$this->assertSame( $forged->get_error_message(), $unknown->get_error_message() );
		$this->assertSame( $forged->get_error_data(), $unknown->get_error_data() );
	}

	public function test_another_owners_table_is_unreachable_through_the_wrong_owner(): void {
		$this->assertError( $this->controller->get_rows( $this->request( [], 'wildberries', 'orders' ) ), 'woodev_records_not_found', 404 );
		$this->assertError( $this->controller->check_permission( $this->request( [], 'nobody' ) ), 'woodev_records_not_found', 404 );
	}

	public function test_a_provider_that_refuses_the_object_is_a_404_and_no_rows_are_read(): void {
		$this->ozon->authorizer = static function ( string $operation, array $context ): bool {
			return 'a2' !== $context['account_id'];
		};

		$result = $this->controller->get_rows( $this->request( [ 'context' => [ 'account_id' => 'a2' ] ] ) );

		$this->assertError( $result, 'woodev_records_not_found', 404 );
		$this->assertNotContains( 'query', $this->ozon->calls );
	}

	public function test_a_provider_whose_authorisation_throws_fails_closed(): void {
		$this->ozon->authorizer = static function (): bool {
			throw new \RuntimeException( 'cannot decide' );
		};
		Functions\expect( 'error_log' )->once();

		$result = $this->controller->get_rows( $this->request() );

		$this->assertError( $result, 'woodev_records_provider_error', 500 );
		$this->assertNotContains( 'query', $this->ozon->calls );
	}

	public function test_the_wrong_capability_is_forbidden(): void {
		$this->caps = [];

		$this->assertError( $this->controller->check_permission( $this->request() ), 'woodev_records_forbidden', 403 );

		$this->caps = [ 'manage_woocommerce' ];
		$this->assertTrue( $this->controller->check_permission( $this->request() ) );
	}

	public function test_route_params_cannot_be_overridden_from_the_query_string(): void {
		$result = $this->controller->get_rows( $this->request( [ 'owner' => 'wildberries' ] ) );

		$this->assertError( $result, 'woodev_records_invalid_param', 400 );
		$this->assertSame( [], $this->wb->calls );
		$this->assertSame( [], $this->ozon->calls );
	}

	// -- invalid filters / sorts / lists -------------------------------------------------------

	/**
	 * @dataProvider invalid_list_requests
	 *
	 * @param array<string,mixed> $query Query that must be refused.
	 */
	public function test_invalid_filters_and_sorts_are_rejected_before_the_provider_reads( array $query ): void {
		$result = $this->controller->get_rows( $this->request( $query ) );

		$this->assertError( $result, 'woodev_records_invalid_param', 400 );
		$this->assertNotContains( 'query', $this->ozon->calls );
		$this->assertNotContains( 'authorize', $this->ozon->calls, 'validation comes before the object check' );
	}

	/** @return array<string,array{0:array<string,mixed>}> */
	public function invalid_list_requests(): array {
		return [
			'unknown sort key'          => [ [ 'sort' => 'status' ] ],
			'unknown direction'         => [ [ 'sort' => 'name', 'direction' => 'up' ] ],
			'unknown filter'            => [ [ 'filters' => [ 'color' => 'red' ] ] ],
			'select outside options'    => [ [ 'filters' => [ 'state' => 'gone' ] ] ],
			'reversed date range'       => [ [ 'filters' => [ 'created' => [ 'from' => '2026-05-01', 'to' => '2026-01-01' ] ] ] ],
			'undeclared query field'    => [ [ 'where' => '1=1' ] ],
			'unknown context key'       => [ [ 'context' => [ 'account_id' => 'a1', 'user_id' => '1' ] ] ],
			'account id with an quote'  => [ [ 'context' => [ 'account_id' => "a1'" ] ] ],
			'page zero'                 => [ [ 'page' => '0' ] ],
		];
	}

	public function test_an_oversized_page_is_rejected_before_any_work(): void {
		$result = $this->controller->get_rows( $this->request( [ 'per_page' => '51' ] ) );

		$this->assertError( $result, 'woodev_records_per_page_too_large', 400 );
		$this->assertNotContains( 'query', $this->ozon->calls );
		$this->assertNotContains( 'authorize', $this->ozon->calls );
	}

	public function test_an_oversized_children_page_is_rejected_before_any_work(): void {
		$result = $this->controller->get_children( $this->request( [ 'per_page' => '21' ], 'ozon-seller', 'products', [ 'id' => 'p:1:a1' ] ) );

		$this->assertError( $result, 'woodev_records_per_page_too_large', 400 );
		$this->assertNotContains( 'get_children', $this->ozon->calls );
	}

	// -- children ------------------------------------------------------------------------------

	public function test_children_are_read_for_an_authorised_parent_with_the_same_envelope(): void {
		$this->ozon->child_rows = [ Records_Definitions::row( 'v:1' ), Records_Definitions::row( 'v:2' ) ];

		$data = $this->controller->get_children( $this->request( [ 'context' => [ 'account_id' => 'a2' ] ], 'ozon-seller', 'products', [ 'id' => 'p:1:a2' ] ) )->get_data();

		$this->assertSame( [ 'v:1', 'v:2' ], array_column( $data['rows'], 'id' ) );
		$this->assertSame( [ 2, 1, 10 ], [ $data['total'], $data['page'], $data['per_page'] ] );
		$this->assertSame( [ [ 'account_id' => 'a2' ], 'p:1:a2', [ 'page' => 1, 'per_page' => 10 ] ], $this->ozon->last['children'] );
		$this->assertSame( [ 'children', [ 'account_id' => 'a2' ], [ 'p:1:a2' ] ], $this->ozon->last['authorize'][0] );
	}

	public function test_a_forged_parent_id_is_not_found_and_reads_nothing(): void {
		$this->ozon->authorizer = static function ( string $operation, array $context, array $ids ): bool {
			return 'p:1:a1' === ( $ids[0] ?? '' );
		};

		$result = $this->controller->get_children( $this->request( [], 'ozon-seller', 'products', [ 'id' => 'p:1:a2' ] ) );

		$this->assertError( $result, 'woodev_records_not_found', 404 );
		$this->assertNotContains( 'get_children', $this->ozon->calls );
	}

	public function test_a_table_without_children_has_no_children_route(): void {
		$flat = new Fake_Provider( 'flat', Records_Definitions::standard( [ 'children' => null ] ) );
		$this->registry->register( 'ozon-seller', $flat );

		$result = $this->controller->get_children( $this->request( [], 'ozon-seller', 'flat', [ 'id' => 'p:1' ] ) );

		$this->assertError( $result, 'woodev_records_not_found', 404 );
		$this->assertNotContains( 'get_children', $flat->calls );
	}

	// -- field options -------------------------------------------------------------------------

	public function test_action_field_options_come_from_a_declared_source_only(): void {
		$this->ozon->field_options = [ [ 'value' => 'w1', 'label' => 'Склад 1' ] ];

		$data = $this->controller->get_field_options(
			$this->request( [ 'search' => ' скл ', 'row_id' => 'p:1:a1' ], 'ozon-seller', 'products', [ 'action' => 'sync', 'field' => 'warehouse' ] )
		)->get_data();

		$this->assertSame( [ 'options' => [ [ 'value' => 'w1', 'label' => 'Склад 1' ] ], 'next_cursor' => null ], $data );
		$this->assertSame(
			[ [ 'account_id' => 'a1' ], 'sync', 'warehouse', [ 'row_id' => 'p:1:a1', 'search' => 'скл', 'cursor' => '' ] ],
			$this->ozon->last['field_options']
		);
		$this->assertSame( [ 'options', [ 'account_id' => 'a1' ], [ 'p:1:a1' ] ], $this->ozon->last['authorize'][0] );
	}

	/**
	 * @dataProvider unreachable_option_sources
	 *
	 * @param string $action Action id.
	 * @param string $field  Field id.
	 */
	public function test_a_field_without_a_declared_source_is_not_found( string $action, string $field ): void {
		$result = $this->controller->get_field_options( $this->request( [], 'ozon-seller', 'products', [ 'action' => $action, 'field' => $field ] ) );

		$this->assertError( $result, 'woodev_records_not_found', 404 );
		$this->assertNotContains( 'get_field_options', $this->ozon->calls );
	}

	/** @return array<string,array{0:string,1:string}> */
	public function unreachable_option_sources(): array {
		return [
			'field with static type'  => [ 'sync', 'note' ],
			'unknown field'           => [ 'sync', 'ghost' ],
			'unknown action'          => [ 'ghost', 'warehouse' ],
			'action without fields'   => [ 'open', 'warehouse' ],
		];
	}

	public function test_option_requests_are_validated_and_capability_gated(): void {
		$route = [ 'action' => 'sync', 'field' => 'warehouse' ];

		foreach ( [ [ 'row_id' => 'p 1' ], [ 'cursor' => '<x>' ], [ 'search' => [ 'a' ] ], [ 'search' => str_repeat( 'x', 201 ) ] ] as $query ) {
			$this->assertError( $this->controller->get_field_options( $this->request( $query, 'ozon-seller', 'products', $route ) ), 'woodev_records_invalid_param', 400 );
		}
		$this->assertNotContains( 'get_field_options', $this->ozon->calls );

		$this->caps = [ 'manage_woocommerce' ];
		$this->assertError( $this->controller->get_field_options( $this->request( [], 'ozon-seller', 'products', $route ) ), 'woodev_records_forbidden', 403 );
	}

	public function test_a_provider_without_the_option_contract_has_no_options_route(): void {
		// A provider that does not implement Provides_Field_Options.
		$plain = new class() implements \Woodev\Framework\Admin\Records\Records_Provider {
			public function get_id(): string { return 'plain'; }
			public function get_definition( array $context ): array { return Records_Definitions::standard(); }
			public function authorize( string $operation, array $context, array $ids ): bool { return true; }
			public function query( array $context, array $query ): array { return [ 'rows' => [], 'total' => 0 ]; }
			public function get_children( array $context, string $parent_id, array $query ): array { return [ 'rows' => [], 'total' => 0 ]; }
			public function preview( array $context, string $action, array $ids, array $payload ): array { return []; }
			public function apply( array $context, string $action, array $ids, array $payload ): array { return []; }
			public function get_activity( array $context, array $ids ): array { return []; }
		};
		$this->registry->register( 'ozon-seller', $plain );

		$result = $this->controller->get_field_options( $this->request( [], 'ozon-seller', 'plain', [ 'action' => 'sync', 'field' => 'warehouse' ] ) );

		$this->assertError( $result, 'woodev_records_not_found', 404 );
	}

	// -- provider failures ---------------------------------------------------------------------

	public function test_a_throwing_provider_is_a_generic_500_with_the_secret_masked_in_the_log(): void {
		$this->ozon->throw = new \RuntimeException( 'boom api_key=SECRET123' );
		$logged            = null;
		Functions\expect( 'error_log' )->once()->andReturnUsing(
			static function ( $message ) use ( &$logged ) {
				$logged = $message;
				return true;
			}
		);

		$result = $this->controller->get_rows( $this->request() );

		$this->assertError( $result, 'woodev_records_provider_error', 500 );
		$this->assertStringNotContainsString( 'boom', $result->get_error_message() );
		$this->assertStringContainsString( 'ozon-seller/products', (string) $logged );
		$this->assertStringNotContainsString( 'SECRET123', (string) $logged );
	}

	public function test_an_invalid_definition_is_a_500_and_nothing_is_read(): void {
		$this->ozon->definition = Records_Definitions::standard( [ 'analytics' => [ 'report' => 'x' ] ] );
		Functions\expect( 'error_log' )->once();

		$result = $this->controller->get_rows( $this->request() );

		$this->assertError( $result, 'woodev_records_invalid_definition', 500 );
		$this->assertSame( [ 'get_definition' ], $this->ozon->calls );
	}

	public function test_a_provider_that_returns_too_many_rows_is_a_500_not_a_truncation(): void {
		$this->ozon->rows = array_map( static fn( $n ) => Records_Definitions::row( 'p:' . $n ), range( 1, 11 ) );

		$result = $this->controller->get_rows( $this->request( [ 'per_page' => '10' ] ) );

		$this->assertError( $result, 'woodev_records_invalid_provider_response', 500 );
	}

	// -- wiring --------------------------------------------------------------------------------

	public function test_the_read_routes_are_registered_with_a_permission_callback(): void {
		$registered = [];
		Functions\when( 'register_rest_route' )->alias(
			static function ( $namespace, $route, $args ) use ( &$registered ) {
				$registered[ $route ] = [ $namespace, $args ];
				return true;
			}
		);

		$this->controller->register_routes();

		$this->assertCount( 4, $registered );
		foreach ( $registered as $route => [ $namespace, $args ] ) {
			$this->assertSame( 'woodev/v1', $namespace );
			$this->assertSame( 'GET', $args['methods'] );
			$this->assertSame( [ $this->controller, 'check_permission' ], $args['permission_callback'] );
			$this->assertStringStartsWith( '/admin/records/(?P<owner>', $route );
		}
		$this->assertMatchesRegularExpression( '#^/admin/records/\(\?P<owner>[^)]+\)/\(\?P<table>[^)]+\)/definition$#', array_keys( $registered )[0] );
	}
}
