<?php
/**
 * Unit tests for the gates of the «Инструменты» REST routes: the confirm flag, the account ownership, the body key
 * list, and the disabled-tool refusal.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Pages\Tools;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Account_Store;
use Woodev\Framework\Marketplace\Admin\Pages\Tools\Tool;
use Woodev\Framework\Marketplace\Admin\Pages\Tools\Tool_Activity;
use Woodev\Framework\Marketplace\Admin\Pages\Tools\Tool_Catalogue;
use Woodev\Framework\Marketplace\Admin\Pages\Tools\Tool_Registry;
use Woodev\Framework\Marketplace\Admin\Pages\Tools\Tool_Runner;
use Woodev\Framework\Marketplace\Admin\Pages\Tools\Tools_Controller;
use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Shipping\Settings\Tool_Result;
use Woodev\Tests\Unit\TestCase;

final class ToolsControllerTest extends TestCase {
	private const ADAPTER = 'fake_seller';

	/**
	 * Every tool run, as `id:account`.
	 *
	 * @var string[]
	 */
	private $ran = [];

	/**
	 * Why a remote tool is held; null when nothing holds it.
	 *
	 * @var string|null
	 */
	private $hold = null;

	/**
	 * Claim the activity reader reports: a time in the future means a background run holds the account.
	 *
	 * @var string|null
	 */
	private $claim_until = null;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'is_wp_error' )->alias(
			static function ( $value ): bool {
				return $value instanceof \WP_Error;
			}
		);
	}

	public function test_a_run_without_the_confirm_flag_is_refused_and_nothing_runs(): void {
		foreach ( [ [], [ 'confirmed' => false ], [ 'confirmed' => 'true' ], [ 'confirmed' => 1 ], [ 'confirmed' => null ] ] as $extra ) {
			$answer = $this->controller()->run( $this->request( 'push', array_merge( [ 'account_id' => 3 ], $extra ) ) );

			$this->assertError( $answer, 'woodev_tools_not_confirmed', 400 );
		}

		$this->assertSame( [], $this->ran );
	}

	public function test_a_confirmed_run_runs_the_tool_for_the_account(): void {
		$answer = $this->controller()->run( $this->request( 'push', [ 'account_id' => 3, 'confirmed' => true ] ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $answer );
		$this->assertSame( 'done', $answer->get_data()['status'] );
		$this->assertTrue( $answer->get_data()['success'] );
		$this->assertSame( [ 'push:3' ], $this->ran );
	}

	public function test_a_text_of_digits_is_an_account_but_other_values_are_not(): void {
		$this->assertInstanceOf( \WP_REST_Response::class, $this->controller()->run( $this->request( 'push', [ 'account_id' => '3', 'confirmed' => true ] ) ) );

		foreach ( [ 0, -3, '3abc', 3.5, true, [ 3 ], null ] as $bad ) {
			$this->assertError( $this->controller()->run( $this->request( 'push', [ 'account_id' => $bad, 'confirmed' => true ] ) ), 'woodev_tools_unknown_account', 404 );
		}
	}

	public function test_an_account_of_another_marketplace_is_not_found(): void {
		$answer = $this->controller()->run( $this->request( 'push', [ 'account_id' => 8, 'confirmed' => true ] ) );

		$this->assertError( $answer, 'woodev_tools_unknown_account', 404 );
		$this->assertSame( [], $this->ran );
	}

	public function test_an_account_that_does_not_exist_is_not_found(): void {
		$answer = $this->controller()->run( $this->request( 'push', [ 'account_id' => 99, 'confirmed' => true ] ) );

		$this->assertError( $answer, 'woodev_tools_unknown_account', 404 );
	}

	public function test_a_switched_off_account_is_refused_with_409(): void {
		$answer = $this->controller()->run( $this->request( 'push', [ 'account_id' => 4, 'confirmed' => true ] ) );

		$this->assertError( $answer, 'woodev_tools_account_disabled', 409 );
		$this->assertSame( [], $this->ran );
	}

	public function test_a_field_the_route_does_not_declare_is_refused(): void {
		$run     = $this->controller()->run( $this->request( 'push', [ 'account_id' => 3, 'confirmed' => true, 'force' => true ] ) );
		$preview = $this->controller()->preview( $this->request( 'push', [ 'account_id' => 3, 'confirmed' => true ] ) );

		$this->assertError( $run, 'woodev_tools_unknown_field', 400 );
		$this->assertError( $preview, 'woodev_tools_unknown_field', 400 );
		$this->assertSame( [], $this->ran );
	}

	public function test_an_unknown_tool_is_not_found(): void {
		$answer = $this->controller()->run( $this->request( 'missing', [ 'account_id' => 3, 'confirmed' => true ] ) );

		$this->assertError( $answer, 'woodev_tools_unknown_tool', 404 );
	}

	public function test_a_remote_tool_held_by_the_licence_or_pause_is_refused_with_its_sentence(): void {
		$this->hold = 'Лицензия плагина не действует.';

		$answer = $this->controller()->run( $this->request( 'push', [ 'account_id' => 3, 'confirmed' => true ] ) );

		$this->assertError( $answer, 'woodev_tools_disabled', 409 );
		$this->assertSame( 'Лицензия плагина не действует.', $answer->message );
		$this->assertSame( [], $this->ran );
	}

	public function test_a_local_tool_still_runs_while_the_licence_holds_remote_tools(): void {
		$this->hold = 'Лицензия плагина не действует.';

		$answer = $this->controller()->run( $this->request( 'migrate', [ 'confirmed' => true ] ) );

		$this->assertSame( 'done', $answer->get_data()['status'] );
		$this->assertSame( [ 'migrate:0' ], $this->ran );
	}

	public function test_a_claim_tool_is_refused_with_409_while_a_background_run_holds_the_account(): void {
		$this->claim_until = gmdate( 'Y-m-d H:i:s', time() + 600 );

		$answer = $this->controller()->run( $this->request( 'rerun', [ 'account_id' => 3, 'confirmed' => true ] ) );

		$this->assertError( $answer, 'woodev_tools_disabled', 409 );
		$this->assertSame( [], $this->ran );
	}

	public function test_a_site_tool_needs_no_account_and_still_needs_the_confirm_flag(): void {
		$this->assertError( $this->controller()->run( $this->request( 'migrate', [] ) ), 'woodev_tools_not_confirmed', 400 );

		$answer = $this->controller()->run( $this->request( 'migrate', [ 'confirmed' => true ] ) );

		$this->assertSame( 'done', $answer->get_data()['status'] );
		$this->assertSame( [ 'migrate:0' ], $this->ran );
	}

	public function test_a_preview_changes_nothing_and_reports_the_hold(): void {
		$free = $this->controller()->preview( $this->request( 'push', [ 'account_id' => 3 ] ) );

		$this->assertTrue( $free->get_data()['can_run'] );
		$this->assertSame( 'Будет обработано: 3.', $free->get_data()['summary'] );

		$this->hold = 'Лицензия плагина не действует.';
		$held       = $this->controller()->preview( $this->request( 'push', [ 'account_id' => 3 ] ) );

		$this->assertFalse( $held->get_data()['can_run'] );
		$this->assertTrue( $held->get_data()['disabled'] );
		$this->assertSame( 'Лицензия плагина не действует.', $held->get_data()['disabled_reason'] );
		$this->assertSame( [], $this->ran );
	}

	public function test_a_preview_of_a_foreign_account_is_not_found(): void {
		$this->assertError( $this->controller()->preview( $this->request( 'push', [ 'account_id' => 8 ] ) ), 'woodev_tools_unknown_account', 404 );
	}

	public function test_the_state_lists_the_tools_with_the_hold(): void {
		$this->hold = 'Лицензия плагина не действует.';

		$state = $this->controller()->get_state( $this->request( '', [ 'account_id' => '3' ] ) )->get_data();

		$by_id = array_column( $state['tools'], null, 'id' );

		$this->assertTrue( $by_id['push']['disabled'] );
		$this->assertFalse( $by_id['migrate']['disabled'] );
		$this->assertSame( 'site', $by_id['migrate']['scope'] );
	}

	private function assertError( $answer, string $code, int $status ): void {
		$this->assertInstanceOf( \WP_Error::class, $answer );
		$this->assertSame( $code, $answer->code );
		$this->assertSame( $status, $answer->data['status'] );
	}

	/**
	 * A request for a tool route: the JSON body is `$body`, and WordPress would also answer `get_param()` from it.
	 *
	 * @param string              $tool Tool id of the route.
	 * @param array<string,mixed> $body JSON body, or the query for the state route.
	 */
	private function request( string $tool, array $body ): \WP_REST_Request {
		$request = Mockery::mock( \WP_REST_Request::class );
		$request->shouldReceive( 'get_json_params' )->andReturn( $body );
		$request->shouldReceive( 'get_param' )->andReturnUsing(
			static function ( string $key ) use ( $tool, $body ) {
				return 'tool' === $key ? $tool : ( $body[ $key ] ?? null );
			}
		);

		return $request;
	}

	private function controller(): Tools_Controller {
		$registry = new Tool_Registry(
			[
				$this->tool( 'push', false, true, Tool::SCOPE_ACCOUNT ),
				$this->tool( 'rerun', true, true, Tool::SCOPE_ACCOUNT ),
				$this->tool( 'migrate', false, false, Tool::SCOPE_SITE ),
			]
		);

		$db = Mockery::mock( \wpdb::class );
		$db->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$db->shouldReceive( 'get_var' )->andReturnUsing( fn () => $this->claim_until );

		$activity  = new Tool_Activity( $db, 'wp_fake_accounts', static fn (): int => time() );
		$catalogue = new Tool_Catalogue( $registry, $activity, fn ( int $account_id ): ?string => $this->hold );
		$runner    = new Tool_Runner(
			$registry,
			static fn ( int $account_id ): Claim_Fence => new Claim_Fence( 'wp_fake_accounts', 'token' ),
			static function ( int $account_id, Claim_Fence $fence ): void {},
			static fn ( int $account_id, Claim_Fence $fence ): bool => true,
			static function ( string $line ): void {}
		);

		return new Tools_Controller( 'fake_seller_plugin', 'manage_woocommerce', self::ADAPTER, $this->accounts(), $registry, $activity, $runner, $catalogue );
	}

	private function accounts(): Account_Store {
		$store = Mockery::mock( Account_Store::class );
		$store->shouldReceive( 'get' )->andReturnUsing(
			static function ( int $id ): ?Account {
				$known = [
					3 => new Account( 3, self::ADAPTER, 'Главный', true ),
					4 => new Account( 4, self::ADAPTER, 'Выключенный', false ),
					8 => new Account( 8, 'other_marketplace', 'Чужой', true ),
				];

				return $known[ $id ] ?? null;
			}
		);
		$store->shouldReceive( 'all' )->andReturn( [ new Account( 3, self::ADAPTER, 'Главный', true ) ] );

		return $store;
	}

	private function tool( string $id, bool $takes_claim, bool $remote, string $scope ): Tool {
		return Tool::create(
			$id,
			$id,
			'Описание',
			'Запустить',
			static fn ( int $account_id ): string => 'Будет обработано: 3.',
			function ( int $account_id ) use ( $id ): Tool_Result {
				$this->ran[] = $id . ':' . $account_id;

				return Tool_Result::success( 'Готово.' );
			},
			$takes_claim,
			$remote,
			$scope
		);
	}
}
