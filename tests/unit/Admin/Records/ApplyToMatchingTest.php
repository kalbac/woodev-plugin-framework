<?php
namespace Woodev\Tests\Unit\Admin\Records;

use Brain\Monkey\Functions;
use Woodev\Framework\Admin\Records\{Action_Rest_Handler, Applies_To_Matching, Records_Registry};
use Woodev\Tests\Unit\TestCase;

require_once __DIR__ . '/Action_Test_Doubles.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-base.php';
require_once dirname( __DIR__, 4 ) . '/woodev/rest-api/class-rest-v1-registrar.php';

/**
 * A provider that can also serve «every row under the filter».
 */
class Matching_Test_Provider extends Action_Test_Provider implements Applies_To_Matching {

	public function preview_matching( array $context, string $action, array $query, array $payload ): array {
		$this->calls[]         = 'preview_matching';
		$this->last['preview'] = [ $context, $action, $query, $payload ];

		return $this->preview_result;
	}

	public function apply_matching( array $context, string $action, array $query, array $payload ): array {
		$this->calls[]       = 'apply_matching';
		$this->last['apply'] = [ $context, $action, $query, $payload ];
		++$this->applied;

		return $this->apply_results[0];
	}
}

/**
 * The `matching` target of the action routes: the contract that decides whether the request reaches
 * the provider at all.
 */
class ApplyToMatchingTest extends TestCase {

	/** @var Action_Rest_Handler */
	private $handler;

	/** @var Matching_Test_Provider */
	private $provider;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $v ) => trim( strip_tags( $v ) ) );
		Functions\when( 'current_user_can' )->justReturn( true );

		Records_Registry::reset_for_tests();
		$this->provider = new Matching_Test_Provider( 'products', self::definition() );
		Records_Registry::instance()->register( 'ozon-seller', $this->provider );
		$this->handler = new Action_Rest_Handler( Records_Registry::instance() );
	}

	protected function tearDown(): void {
		Records_Registry::reset_for_tests();
		parent::tearDown();
	}

	/**
	 * The shared definition plus an `all_matching` selection action carrying one hidden field.
	 *
	 * @return array<string,mixed>
	 */
	private static function definition(): array {
		$definition                   = Action_Definitions::with_actions();
		$definition['bulk_actions'][] = [
			'id'           => 'send_stock',
			'label'        => 'Отправить остатки',
			'capability'   => 'edit_products',
			'effect'       => 'mutate',
			'interaction'  => 'dialog',
			'all_matching' => true,
		];

		return $definition;
	}

	/**
	 * @param array<string,mixed> $body   JSON body.
	 * @param string              $action Action id.
	 */
	private function request( array $body, string $action = 'send_stock' ): Action_Test_Request {
		return new Action_Test_Request( $body, [ 'owner' => 'ozon-seller', 'table' => 'products', 'action' => $action ] );
	}

	/**
	 * @param array<string,mixed> $overrides Body keys to replace.
	 * @return array<string,mixed>
	 */
	private function matching_body( array $overrides = [] ): array {
		return array_merge(
			[
				'context'  => [ 'account_id' => 'a1' ],
				'matching' => [
					'filters' => [ 'state' => 'ok' ],
					'search'  => 'кружка',
				],
				'payload'  => [],
			],
			$overrides
		);
	}

	private function assertInvalid( $result ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'woodev_records_invalid_param', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/** Calls that reached the provider after its definition. */
	private function provider_calls(): array {
		return array_values( array_diff( $this->provider->calls, [ 'get_definition' ] ) );
	}

	public function test_a_matching_preview_reaches_only_preview_matching_with_the_validated_query(): void {
		$response = $this->handler->preview( $this->request( $this->matching_body() ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'preview_matching' ], $this->provider_calls(), 'no ids call, no object check' );
		$this->assertSame(
			[ [ 'account_id' => 'a1' ], 'send_stock', [ 'search' => 'кружка', 'filters' => [ 'state' => 'ok' ] ], [] ],
			$this->provider->last['preview']
		);
	}

	public function test_a_matching_apply_reaches_apply_matching(): void {
		$response = $this->handler->apply( $this->request( $this->matching_body( [ 'confirmed' => true ] ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'apply_matching' ], $this->provider_calls() );
	}

	public function test_an_empty_matching_is_the_whole_table(): void {
		$this->handler->apply( $this->request( $this->matching_body( [ 'confirmed' => true, 'matching' => [] ] ) ) );

		$this->assertSame( [ 'search' => '', 'filters' => [] ], $this->provider->last['apply'][2] );
	}

	public function test_matching_together_with_ids_is_a_400_and_nothing_runs(): void {
		$result = $this->handler->preview( $this->request( $this->matching_body( [ 'ids' => [ 'p:1:a1' ] ] ) ) );

		$this->assertInvalid( $result );
		$this->assertSame( [], $this->provider_calls() );
	}

	public function test_a_matching_request_to_a_provider_without_the_interface_is_a_400(): void {
		Records_Registry::reset_for_tests();
		$plain = new Action_Test_Provider( 'products', self::definition() );
		Records_Registry::instance()->register( 'ozon-seller', $plain );

		$result = ( new Action_Rest_Handler( Records_Registry::instance() ) )->preview( $this->request( $this->matching_body() ) );

		$this->assertInvalid( $result );
		$this->assertSame( [ 'get_definition' ], $plain->calls );
	}

	/**
	 * @dataProvider not_a_matching_action
	 */
	public function test_an_action_that_does_not_declare_all_matching_refuses_matching( string $action ): void {
		$this->assertInvalid( $this->handler->preview( $this->request( $this->matching_body(), $action ) ) );
		$this->assertSame( [], $this->provider_calls() );
	}

	public function not_a_matching_action(): array {
		return [
			'a selection action without all_matching' => [ 'export' ],
			'an account action'                       => [ 'link_all' ],
		];
	}

	/**
	 * @dataProvider broken_matching
	 *
	 * @param mixed $matching Raw `matching`.
	 */
	public function test_a_matching_the_list_read_would_refuse_is_a_400( $matching ): void {
		$this->assertInvalid( $this->handler->preview( $this->request( $this->matching_body( [ 'matching' => $matching ] ) ) ) );
		$this->assertSame( [], $this->provider_calls() );
	}

	public function broken_matching(): array {
		return [
			'an undeclared filter'          => [ [ 'filters' => [ 'color' => 'red' ] ] ],
			'an option the filter lacks'    => [ [ 'filters' => [ 'state' => 'gone' ] ] ],
			'the search box as a filter'    => [ [ 'filters' => [ 'q' => 'x' ] ] ],
			'a page, which is not part of it' => [ [ 'page' => 2 ] ],
			'a sort, which is not part of it' => [ [ 'sort' => 'name' ] ],
			'an over-long search'           => [ [ 'search' => str_repeat( 'я', 201 ) ] ],
			'filters as a list'             => [ [ 'filters' => [ 'ok' ] ] ],
			'a list'                        => [ [ 'ok' ] ],
			'null'                          => [ null ],
			'a string'                      => [ 'state=ok' ],
		];
	}

	public function test_a_matching_call_needs_the_action_capability_and_has_no_object_check(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$result = $this->handler->preview( $this->request( $this->matching_body() ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( [], $this->provider_calls() );
	}

	public function test_an_apply_answer_keeps_the_run_block_and_nothing_else_of_it(): void {
		$this->provider->apply_results = [
			[
				'requested' => 3,
				'accepted'  => 3,
				'skipped'   => 0,
				'failed'    => 0,
				'queued'    => true,
				'run'       => [ 'id' => 'abc123', 'products_total' => 12345, 'cursor' => 'secret' ],
			],
		];

		$response = $this->handler->apply( $this->request( $this->matching_body( [ 'confirmed' => true ] ) ) );

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( [ 'id' => 'abc123', 'products_total' => 12345 ], $response->get_data()['run'] );
	}

	public function test_an_apply_without_a_run_carries_none(): void {
		$data = $this->handler->apply( $this->request( $this->matching_body( [ 'confirmed' => true ] ) ) )->get_data();

		$this->assertArrayNotHasKey( 'run', $data );
	}

	/**
	 * @dataProvider broken_run
	 *
	 * @param mixed $run Provider `run`.
	 */
	public function test_a_run_that_breaks_the_shape_is_a_generic_500( $run ): void {
		$this->provider->apply_results = [ [ 'requested' => 1, 'accepted' => 1, 'skipped' => 0, 'failed' => 0, 'run' => $run ] ];

		$result = $this->handler->apply( $this->request( $this->matching_body( [ 'confirmed' => true ] ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'woodev_records_invalid_provider_response', $result->get_error_code() );
	}

	public function broken_run(): array {
		return [
			'a string'           => [ 'abc' ],
			'no id'              => [ [ 'products_total' => 5 ] ],
			'an id with a space' => [ [ 'id' => 'a b', 'products_total' => 5 ] ],
			'no total'           => [ [ 'id' => 'abc' ] ],
			'a negative total'   => [ [ 'id' => 'abc', 'products_total' => -1 ] ],
			'a float total'      => [ [ 'id' => 'abc', 'products_total' => 1.5 ] ],
		];
	}

	public function test_an_ids_request_still_takes_the_ids_path(): void {
		$this->handler->preview( $this->request( [ 'context' => [ 'account_id' => 'a1' ], 'ids' => [ 'p:1:a1' ], 'payload' => [] ], 'send_stock' ) );

		$this->assertSame( [ 'authorize', 'preview' ], $this->provider_calls() );
	}
}
