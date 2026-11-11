<?php
namespace Woodev\Tests\Unit\Admin\Records;

use Brain\Monkey\Functions;
use Woodev\Framework\Admin\Records\{Action_Input_Exception, Action_Rest_Handler, Action_State_Exception, Records_Registry};
use Woodev\Tests\Unit\TestCase;

require_once __DIR__ . '/Action_Test_Doubles.php';
require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-base.php';
require_once dirname( __DIR__, 4 ) . '/woodev/rest-api/class-rest-v1-registrar.php';

/**
 * The preview and apply routes: order of checks, what reaches the provider, and the answers.
 */
class ActionRestHandlerTest extends TestCase {

	/** @var Action_Rest_Handler */
	private $handler;

	/** @var Action_Test_Provider */
	private $provider;

	/** @var string[] Capabilities the current user holds. */
	private $caps = [ 'manage_woocommerce', 'edit_products' ];

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $v ) => trim( strip_tags( $v ) ) );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => in_array( $cap, $this->caps, true ) );

		Records_Registry::reset_for_tests();
		$registry       = Records_Registry::instance();
		$this->provider = new Action_Test_Provider( 'products', Action_Definitions::with_actions() );
		$registry->register( 'ozon-seller', $this->provider );
		$this->handler = new Action_Rest_Handler( $registry );
	}

	protected function tearDown(): void {
		Records_Registry::reset_for_tests();
		parent::tearDown();
	}

	/**
	 * @param mixed               $json   JSON body.
	 * @param string              $action Action id in the route.
	 * @param array<string,mixed> $query  Query-string params.
	 * @param string              $table  Table id in the route.
	 */
	private function request( $json, string $action = 'export', array $query = [], string $table = 'products' ): Action_Test_Request {
		return new Action_Test_Request( $json, [ 'owner' => 'ozon-seller', 'table' => $table, 'action' => $action ], $query );
	}

	/**
	 * A valid body for the `export` bulk action.
	 *
	 * @param array<string,mixed> $overrides Body keys to replace.
	 * @return array<string,mixed>
	 */
	private function body( array $overrides = [] ): array {
		return array_merge(
			[
				'context' => [ 'account_id' => 'a1' ],
				'ids'     => [ 'p:1:a1', 'p:2:a1', 'p:3:a1' ],
				'payload' => [ 'warehouse' => 'w1' ],
			],
			$overrides
		);
	}

	private function apply_body( array $overrides = [] ): array {
		return $this->body( array_merge( [ 'confirmed' => true ], $overrides ) );
	}

	private function assertError( $result, string $code, int $status ): void {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $status, $result->get_error_data()['status'] );
	}

	/** Calls that reached the provider after its definition and object check. */
	private function work_calls(): array {
		return array_values( array_diff( $this->provider->calls, [ 'get_definition', 'authorize' ] ) );
	}

	// -- preview -------------------------------------------------------------------------------

	public function test_preview_plans_and_changes_nothing(): void {
		$response = $this->handler->preview( $this->request( $this->body() ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'get_definition', 'authorize', 'preview' ], $this->provider->calls, 'preview never applies' );
		$this->assertSame(
			[ [ 'account_id' => 'a1' ], 'export', [ 'p:1:a1', 'p:2:a1', 'p:3:a1' ], [ 'warehouse' => 'w1' ] ],
			$this->provider->last['preview']
		);
		$this->assertSame(
			[ 'action', 'scope', 'requested', 'eligible', 'skipped', 'warnings', 'groups', 'messages', 'fields', 'confirm_label', 'can_apply' ],
			array_keys( $data )
		);
		$this->assertSame( [ 'export', 3, 2, 1, true ], [ $data['action'], $data['requested'], $data['eligible'], $data['skipped'], $data['can_apply'] ] );
		$this->assertSame( 'Выгрузить выбранные', $data['confirm_label'] );
		$this->assertSame( 'private, no-store', $response->get_headers()['Cache-Control'] );
		$this->assertEquals( new \stdClass(), $data['messages'] );
	}

	public function test_preview_keeps_the_server_reasons_and_echoes_the_declared_fields(): void {
		$this->provider->preview_result['groups'] = [
			[
				'reason'    => 'Нет артикула',
				'count'     => 4,
				'fix_label' => 'Заполнить',
				'fix_url'   => '/wp-admin/edit.php',
				'examples'  => [ 'Чашка', 'Блюдце' ],
			],
		];

		$data = $this->handler->preview( $this->request( $this->body() ) )->get_data();

		$this->assertSame(
			[ [ 'kind' => 'skipped', 'reason' => 'Нет артикула', 'count' => 4, 'fix_label' => 'Заполнить', 'fix_url' => '/wp-admin/edit.php', 'examples' => [ 'Чашка', 'Блюдце' ] ] ],
			$data['groups']
		);
		$this->assertSame( [ 'warehouse', 'mode', 'qty', 'comment', 'notify', 'tags' ], array_column( $data['fields'], 'id' ) );
	}

	public function test_an_account_wide_preview_states_its_scope_and_takes_no_ids(): void {
		$this->provider->preview_result = [ 'requested' => 340, 'eligible' => 340, 'skipped' => 0 ];

		$data = $this->handler->preview( $this->request( [ 'context' => [ 'account_id' => 'a1' ] ], 'link_all' ) )->get_data();

		$this->assertSame( [ 'type' => 'account', 'sentence' => 'Будут обработаны все товары аккаунта.' ], $data['scope'] );
		$this->assertSame( [], $this->provider->last['preview'][2] );

		$refused = $this->handler->preview( $this->request( [ 'context' => [ 'account_id' => 'a1' ], 'ids' => [ 'p:1:a1' ] ], 'link_all' ) );
		$this->assertError( $refused, 'woodev_records_invalid_param', 400 );
	}

	public function test_the_provider_may_refine_the_scope_sentence_and_the_button(): void {
		$this->provider->preview_result = [
			'requested'      => 3,
			'eligible'       => 3,
			'skipped'        => 0,
			'scope_sentence' => 'Затронет 3 товара.',
			'confirm_label'  => 'Выгрузить 3',
		];

		$data = $this->handler->preview( $this->request( $this->body() ) )->get_data();

		$this->assertSame( [ 'Затронет 3 товара.', 'Выгрузить 3' ], [ $data['scope']['sentence'], $data['confirm_label'] ] );
	}

	public function test_nothing_eligible_cannot_be_applied(): void {
		$this->provider->preview_result = [ 'requested' => 2, 'eligible' => 0, 'skipped' => 2, 'groups' => [ [ 'reason' => 'Уже в очереди', 'count' => 2 ] ] ];

		$this->assertFalse( $this->handler->preview( $this->request( $this->body() ) )->get_data()['can_apply'] );
	}

	// -- apply ---------------------------------------------------------------------------------

	public function test_apply_runs_once_and_returns_the_replanned_counts(): void {
		$response = $this->handler->apply( $this->request( $this->apply_body() ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'get_definition', 'authorize', 'apply' ], $this->provider->calls, 'apply does not call preview' );
		$this->assertSame(
			[ 'status', 'rows', 'removed_ids', 'requested', 'accepted', 'skipped', 'failed', 'groups', 'messages' ],
			array_keys( $data )
		);
		$this->assertSame( [ 'done', 3, 3, 0, 0 ], [ $data['status'], $data['requested'], $data['accepted'], $data['skipped'], $data['failed'] ] );
	}

	public function test_a_partial_result_keeps_every_reason_and_the_rows_that_changed(): void {
		$this->provider->apply_results = [
			[
				'requested'   => 3,
				'accepted'    => 1,
				'skipped'     => 1,
				'failed'      => 1,
				'rows'        => [ Records_Definitions::row( 'p:1:a1' ) ],
				'removed_ids' => [ 'p:3:a1' ],
				'groups'      => [
					[ 'reason' => 'Товар скрыт', 'count' => 1 ],
					[ 'kind' => 'failed', 'reason' => 'Не удалось сохранить', 'count' => 1 ],
				],
				'messages'    => [ 'summary' => 'Принят 1 из 3.' ],
			],
		];

		$data = $this->handler->apply( $this->request( $this->apply_body() ) )->get_data();

		$this->assertSame( 'partial', $data['status'] );
		$this->assertSame( [ 'p:1:a1' ], array_column( $data['rows'], 'id' ) );
		$this->assertSame( [ 'p:3:a1' ], $data['removed_ids'] );
		$this->assertSame( [ 'skipped', 'failed' ], array_column( $data['groups'], 'kind' ) );
		$this->assertSame( [ 'Товар скрыт', 'Не удалось сохранить' ], array_column( $data['groups'], 'reason' ) );
		$this->assertSame( [ 'summary' => 'Принят 1 из 3.' ], $data['messages'] );
	}

	public function test_queued_work_answers_202(): void {
		$this->provider->apply_results = [ [ 'requested' => 3, 'accepted' => 3, 'skipped' => 0, 'failed' => 0, 'queued' => true ] ];

		$response = $this->handler->apply( $this->request( $this->apply_body() ) );

		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( 'queued', $response->get_data()['status'] );
	}

	public function test_a_repeated_confirm_replans_from_current_state(): void {
		$this->provider->apply_results = [
			[ 'requested' => 3, 'accepted' => 3, 'skipped' => 0, 'failed' => 0 ],
			[ 'requested' => 3, 'accepted' => 0, 'skipped' => 3, 'failed' => 0, 'groups' => [ [ 'reason' => 'Уже отправлено', 'count' => 3 ] ] ],
		];

		$first  = $this->handler->apply( $this->request( $this->apply_body() ) )->get_data();
		$second = $this->handler->apply( $this->request( $this->apply_body() ) )->get_data();

		$this->assertSame( [ 'done', 3 ], [ $first['status'], $first['accepted'] ] );
		$this->assertSame( [ 'failed', 0, 3 ], [ $second['status'], $second['accepted'], $second['skipped'] ], 'the engine keeps no receipt; the provider replans' );
		$this->assertSame( 'Уже отправлено', $second['groups'][0]['reason'] );
	}

	/**
	 * @dataProvider unconfirmed_bodies
	 */
	public function test_apply_without_an_explicit_confirmation_is_refused_before_any_work( array $overrides ): void {
		$body = array_diff_key( $this->body(), [ 'confirmed' => 1 ] ) + $overrides;

		$result = $this->handler->apply( $this->request( $body ) );

		$this->assertError( $result, 'woodev_records_invalid_param', 400 );
		$this->assertSame( 'confirmed', $result->get_error_data()['param'] );
		$this->assertSame( [], $this->provider->calls );
	}

	/** @return array<string,array{0:array<string,mixed>}> */
	public function unconfirmed_bodies(): array {
		return [
			'missing' => [ [] ],
			'false'   => [ [ 'confirmed' => false ] ],
			'string'  => [ [ 'confirmed' => 'true' ] ],
			'one'     => [ [ 'confirmed' => 1 ] ],
		];
	}

	// -- download ------------------------------------------------------------------------------

	public function test_a_download_carries_a_url_on_this_site_only(): void {
		Functions\when( 'wp_parse_url' )->alias( static fn( $url, $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'home_url' )->justReturn( 'https://shop.example' );
		Functions\when( 'site_url' )->justReturn( 'https://shop.example' );
		Functions\when( 'admin_url' )->justReturn( 'https://shop.example/wp-admin/' );
		$this->provider->apply_results = [
			[ 'requested' => 3, 'accepted' => 3, 'skipped' => 0, 'failed' => 0, 'download' => [ 'url' => 'https://shop.example/wp-admin/admin-post.php?x=1', 'filename' => 'labels.pdf' ] ],
			[ 'requested' => 3, 'accepted' => 3, 'skipped' => 0, 'failed' => 0, 'download' => [ 'url' => 'https://evil.example/labels.pdf' ] ],
		];

		$own     = $this->handler->apply( $this->request( $this->apply_body( [ 'payload' => [] ] ), 'labels' ) )->get_data();
		$foreign = $this->handler->apply( $this->request( $this->apply_body( [ 'payload' => [] ] ), 'labels' ) );

		$this->assertSame( [ 'url' => 'https://shop.example/wp-admin/admin-post.php?x=1', 'filename' => 'labels.pdf' ], $own['download'] );
		$this->assertError( $foreign, 'woodev_records_invalid_provider_response', 500 );
	}

	public function test_a_download_key_on_an_action_that_is_not_a_download_is_dropped(): void {
		$this->provider->apply_results = [ [ 'requested' => 3, 'accepted' => 3, 'skipped' => 0, 'failed' => 0, 'download' => [ 'url' => '/x' ] ] ];

		$this->assertArrayNotHasKey( 'download', $this->handler->apply( $this->request( $this->apply_body() ) )->get_data() );
	}

	// -- request shape -------------------------------------------------------------------------

	/**
	 * @dataProvider malformed_requests
	 */
	public function test_a_malformed_request_is_a_400_before_the_provider_is_asked_anything( $json, string $param, string $route = 'apply' ): void {
		$result = 'apply' === $route ? $this->handler->apply( $this->request( $json ) ) : $this->handler->preview( $this->request( $json ) );

		$this->assertError( $result, 'woodev_records_invalid_param', 400 );
		$this->assertSame( $param, $result->get_error_data()['param'] );
		$this->assertSame( [], $this->provider->calls );
	}

	/** @return array<string,array{0:mixed,1:string,2?:string}> */
	public function malformed_requests(): array {
		return [
			'no json body'      => [ null, 'body' ],
			'unknown body key'  => [ [ 'context' => [ 'account_id' => 'a1' ], 'ids' => [ 'p:1:a1' ], 'confirmed' => true, 'class' => 'X' ], 'class' ],
			'confirmed on preview' => [ [ 'ids' => [ 'p:1:a1' ], 'confirmed' => true ], 'confirmed', 'preview' ],
			'context not a map' => [ [ 'context' => 'a1', 'confirmed' => true ], 'context' ],
			'foreign context key' => [ [ 'context' => [ 'account_id' => 'a1', 'user' => '1' ], 'confirmed' => true ], 'context' ],
		];
	}

	public function test_a_query_string_on_a_post_is_refused(): void {
		$this->assertError(
			$this->handler->apply( $this->request( $this->apply_body(), 'export', [ 'context' => [ 'account_id' => 'a2' ] ] ) ),
			'woodev_records_invalid_param',
			400
		);
		$this->assertSame( [], $this->provider->calls );
	}

	public function test_a_write_must_name_its_account(): void {
		$result = $this->handler->apply( $this->request( $this->apply_body( [ 'context' => [] ] ) ) );

		$this->assertError( $result, 'woodev_records_invalid_param', 400 );
		$this->assertSame( 'context.account_id', $result->get_error_data()['param'] );
		$this->assertNotContains( 'apply', $this->provider->calls, 'the default account is never used for a write' );
	}

	public function test_a_forged_account_is_not_found_before_any_work(): void {
		$this->assertError( $this->handler->apply( $this->request( $this->apply_body( [ 'context' => [ 'account_id' => 'a999' ] ] ) ) ), 'woodev_records_not_found', 404 );
		$this->assertSame( [ 'get_definition' ], $this->provider->calls );
	}

	public function test_selection_errors(): void {
		$cases = [
			'empty'     => [ [], 'woodev_records_invalid_param' ],
			'duplicate' => [ [ 'p:1:a1', 'p:1:a1' ], 'woodev_records_invalid_param' ],
			'malformed' => [ [ 'p 1' ], 'woodev_records_invalid_param' ],
			'not flat'  => [ [ [ 'p:1:a1' ] ], 'woodev_records_invalid_param' ],
			'too many'  => [ array_map( static fn( $n ) => 'p:' . $n, range( 1, 101 ) ), 'woodev_records_selection_too_large' ],
		];

		foreach ( $cases as $name => [ $ids, $code ] ) {
			$this->assertError( $this->handler->apply( $this->request( $this->apply_body( [ 'ids' => $ids ] ) ) ), $code, 400 );
		}
		$this->assertSame( [ 'get_definition', 'get_definition', 'get_definition', 'get_definition', 'get_definition' ], $this->provider->calls, "no case reached authorize: $name" );
	}

	public function test_a_row_action_takes_exactly_one_id(): void {
		$two = $this->handler->apply( $this->request( $this->apply_body( [ 'ids' => [ 'p:1:a1', 'p:2:a1' ], 'payload' => [] ] ), 'sync' ) );
		$one = $this->handler->apply( $this->request( $this->apply_body( [ 'ids' => [ 'p:1:a1' ], 'payload' => [] ] ), 'sync' ) );

		$this->assertError( $two, 'woodev_records_invalid_param', 400 );
		$this->assertSame( 200, $one->get_status() );
	}

	// -- action gate ---------------------------------------------------------------------------

	public function test_an_unknown_action_and_a_plain_link_have_no_route(): void {
		$this->assertError( $this->handler->apply( $this->request( $this->apply_body(), 'ghost' ) ), 'woodev_records_not_found', 404 );
		$this->assertError( $this->handler->apply( $this->request( $this->apply_body( [ 'payload' => [] ] ), 'open' ) ), 'woodev_records_not_found', 404 );
		$this->assertNotContains( 'apply', $this->provider->calls );
	}

	public function test_the_action_capability_is_required(): void {
		$this->caps = [ 'manage_woocommerce' ];

		$this->assertError( $this->handler->apply( $this->request( $this->apply_body( [ 'payload' => [] ] ), 'sync' ) ), 'woodev_records_forbidden', 403 );
		$this->assertNotContains( 'apply', $this->provider->calls );
	}

	public function test_the_table_capability_is_required_by_the_permission_callback(): void {
		$this->assertTrue( $this->handler->check_permission( $this->request( [] ) ) );

		$this->caps = [];
		$this->assertError( $this->handler->check_permission( $this->request( [] ) ), 'woodev_records_forbidden', 403 );
		$this->assertError( $this->handler->check_permission( $this->request( [], 'export', [], 'ghost' ) ), 'woodev_records_not_found', 404 );
	}

	public function test_a_provider_that_refuses_the_object_is_a_404_and_nothing_runs(): void {
		$this->provider->authorizer = static fn( $operation, $context, $ids ) => 'export' !== $operation;

		$this->assertError( $this->handler->apply( $this->request( $this->apply_body() ) ), 'woodev_records_not_found', 404 );
		$this->assertSame( [], $this->work_calls() );
		$this->assertSame( 'export', $this->provider->last['authorize'][0][0], 'the operation is the action id' );
	}

	// -- input fields --------------------------------------------------------------------------

	public function test_invalid_fields_are_a_422_naming_every_field_and_nothing_runs(): void {
		$result = $this->handler->apply( $this->request( $this->apply_body( [ 'payload' => [ 'mode' => 'slow', 'qty' => 'many' ] ] ) ) );

		$this->assertError( $result, 'woodev_records_invalid_fields', 422 );
		$this->assertSame( [ 'warehouse', 'mode', 'qty' ], array_keys( $result->get_error_data()['errors'] ) );
		$this->assertSame( [], $this->work_calls() );
		$this->assertNotContains( 'authorize', $this->provider->calls );
	}

	public function test_a_payload_that_is_a_list_is_a_400_and_nothing_runs(): void {
		$result = $this->handler->apply( $this->request( $this->apply_body( [ 'payload' => [ 'w1' ] ] ) ) );

		$this->assertError( $result, 'woodev_records_invalid_param', 400 );
		$this->assertSame( 'payload', $result->get_error_data()['param'] );
		$this->assertSame( [], $this->work_calls() );
	}

	public function test_an_undeclared_field_is_a_400(): void {
		$result = $this->handler->apply( $this->request( $this->apply_body( [ 'payload' => [ 'warehouse' => 'w1', 'price' => '1' ] ] ) ) );

		$this->assertError( $result, 'woodev_records_invalid_param', 400 );
		$this->assertSame( 'payload.price', $result->get_error_data()['param'] );
	}

	public function test_typed_values_reach_the_provider(): void {
		$payload = [ 'warehouse' => 'w1', 'mode' => 'fast', 'qty' => '2.5', 'comment' => "a\nb", 'notify' => false, 'tags' => [ 'a', 'b' ] ];

		$this->handler->apply( $this->request( $this->apply_body( [ 'payload' => $payload ] ) ) );

		$this->assertSame( [ 'warehouse' => 'w1', 'mode' => 'fast', 'qty' => 2.5, 'comment' => "a\nb", 'notify' => false, 'tags' => [ 'a', 'b' ] ], $this->provider->last['apply'][3] );
	}

	public function test_the_provider_refuses_a_value_the_engine_could_only_check_by_shape(): void {
		$this->provider->action_throw = new Action_Input_Exception( [ 'warehouse' => 'Такого склада нет.' ] );

		$result = $this->handler->apply( $this->request( $this->apply_body() ) );

		$this->assertError( $result, 'woodev_records_invalid_fields', 422 );
		$this->assertSame( [ 'warehouse' => 'Такого склада нет.' ], $result->get_error_data()['errors'] );
	}

	public function test_a_provider_error_on_an_undeclared_field_is_a_contract_violation(): void {
		$this->provider->action_throw = new Action_Input_Exception( [ 'ghost' => 'x' ] );

		$this->assertError( $this->handler->preview( $this->request( $this->body() ) ), 'woodev_records_invalid_provider_response', 500 );
	}

	// -- state refusals and failures -----------------------------------------------------------

	public function test_a_state_refusal_is_a_409_with_the_providers_reason(): void {
		$this->provider->action_throw = new Action_State_Exception( 'Идёт фоновая работа <b>по аккаунту</b>.' );

		$result = $this->handler->apply( $this->request( $this->apply_body() ) );

		$this->assertError( $result, 'woodev_records_action_conflict', 409 );
		$this->assertSame( 'Идёт фоновая работа по аккаунту.', $result->get_error_message() );
	}

	public function test_a_throwing_provider_is_a_generic_500_with_the_secret_masked_in_the_log(): void {
		$this->provider->action_throw = new \RuntimeException( 'boom api_key=SECRET123' );
		$logged                       = null;
		Functions\expect( 'error_log' )->once()->andReturnUsing(
			static function ( $message ) use ( &$logged ) {
				$logged = $message;
				return true;
			}
		);

		$result = $this->handler->apply( $this->request( $this->apply_body() ) );

		$this->assertError( $result, 'woodev_records_provider_error', 500 );
		$this->assertStringNotContainsString( 'boom', $result->get_error_message() );
		$this->assertStringContainsString( 'ozon-seller/products', (string) $logged );
		$this->assertStringNotContainsString( 'SECRET123', (string) $logged );
	}

	public function test_a_provider_that_breaks_the_answer_contract_is_a_500(): void {
		$this->provider->apply_results = [ [ 'requested' => 1, 'accepted' => 2, 'skipped' => 0, 'failed' => 0 ] ];

		$this->assertError( $this->handler->apply( $this->request( $this->apply_body() ) ), 'woodev_records_invalid_provider_response', 500 );
	}

	// -- wiring --------------------------------------------------------------------------------

	public function test_two_post_routes_are_registered_with_a_permission_callback(): void {
		$registered = [];
		Functions\when( 'register_rest_route' )->alias(
			static function ( $namespace, $route, $args ) use ( &$registered ) {
				$registered[ $route ] = [ $namespace, $args ];
				return true;
			}
		);

		$this->handler->register_routes();

		$this->assertCount( 2, $registered );
		foreach ( $registered as [ $namespace, $args ] ) {
			$this->assertSame( 'woodev/v1', $namespace );
			$this->assertSame( 'POST', $args['methods'] );
			$this->assertSame( [ $this->handler, 'check_permission' ], $args['permission_callback'] );
		}
		$routes = array_keys( $registered );
		$this->assertMatchesRegularExpression( '#/actions/\(\?P<action>[^)]+\)/preview$#', $routes[0] );
		$this->assertMatchesRegularExpression( '#/actions/\(\?P<action>[^)]+\)$#', $routes[1] );
	}
}
