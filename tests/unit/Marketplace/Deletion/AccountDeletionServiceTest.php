<?php
/**
 * The request to delete an account: who may, what the preview says, and the REST route in front of it.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Deletion;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Wpdb_Label_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Deletion\Account_Deletion;
use Woodev\Framework\Marketplace\Deletion\Account_Deletion_Controller;
use Woodev\Framework\Marketplace\Deletion\Account_Deletion_Preview;
use Woodev\Framework\Marketplace\Deletion\Account_Deletion_Progress;
use Woodev\Framework\Marketplace\Deletion\Account_Deletion_Refused;
use Woodev\Framework\Marketplace\Deletion\Account_Deletion_Scheduler;
use Woodev\Framework\Marketplace\Ledger\Wpdb_Task_Ledger;
use Woodev\Framework\Marketplace\Orders\Order_Registry;
use Woodev\Framework\Marketplace\Settings\Effective_Settings;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Sync_Test_Case;

/**
 * Real account store and stores over SQLite; the Action Scheduler is the fake. A refused request queues nothing and writes no
 * progress.
 */
final class AccountDeletionServiceTest extends Sync_Test_Case {
	private const IDENTIFIER = 'cabinet-4417';
	private const HOOK       = 'woodev_fake_seller_delete_account';

	/**
	 * Option and transient storage.
	 *
	 * @var array<string,mixed>
	 */
	private $storage = [];

	/**
	 * Progress.
	 *
	 * @var Account_Deletion_Progress
	 */
	private $progress;

	/**
	 * Whether the stub user may manage the shop.
	 *
	 * @var bool
	 */
	private $may = true;

	/**
	 * Backs the WordPress storage functions with an array.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->storage = [];
		$this->may     = true;
		$read          = function ( string $name, $fallback = false ) {
			return array_key_exists( $name, $this->storage ) ? $this->storage[ $name ] : $fallback;
		};
		$write         = function ( string $name, $value ): bool {
			$this->storage[ $name ] = $value;

			return true;
		};
		$drop          = function ( string $name ): bool {
			unset( $this->storage[ $name ] );

			return true;
		};

		Functions\when( 'get_option' )->alias( $read );
		Functions\when( 'get_transient' )->alias( $read );
		Functions\when( 'update_option' )->alias( $write );
		Functions\when( 'set_transient' )->alias( $write );
		Functions\when( 'delete_option' )->alias( $drop );
		Functions\when( 'delete_transient' )->alias( $drop );
		Functions\when( 'current_user_can' )->alias(
			function (): bool {
				return $this->may;
			}
		);

		$this->progress = new Account_Deletion_Progress( 'woodev_fake_seller_', $this->clock() );
	}

	/**
	 * An enabled account cannot be deleted: the state says why, the start is refused with 409, and nothing is queued.
	 *
	 * @return void
	 */
	public function test_an_enabled_account_is_refused(): void {
		$state = $this->service()->state( $this->account_id );

		$this->assertFalse( $state['can_delete'] );
		$this->assertSame( 'Сначала выключите аккаунт.', $state['refusal'] );
		$this->assertNull( $state['preview'] );

		$this->assertRefused( 409, function (): void {
			$this->service()->start( $this->account_id, self::IDENTIFIER );
		} );
		$this->assertSame( [], $this->scheduler->summary() );
		$this->assertNull( $this->progress->get( $this->account_id ) );
	}

	/**
	 * The identifier must be typed as stored. Anything else, and an empty text, is refused with 400.
	 *
	 * @return void
	 */
	public function test_a_wrong_confirmation_is_refused(): void {
		$this->accounts()->set_enabled( $this->account_id, false );

		foreach ( [ '', '   ', 'cabinet-4418', 'CABINET-4417', 'cabinet-4417-', '4417' ] as $typed ) {
			$this->assertRefused( 400, function () use ( $typed ): void {
				$this->service()->start( $this->account_id, $typed );
			}, $typed );
		}

		$this->assertSame( [], $this->scheduler->summary(), 'nothing was queued' );
		$this->assertNull( $this->progress->get( $this->account_id ) );
	}

	/**
	 * The right identifier, with the account off, queues the job once and shows it running.
	 *
	 * @return void
	 */
	public function test_a_confirmed_start_queues_the_job_once(): void {
		$this->accounts()->set_enabled( $this->account_id, false );

		$state = $this->service()->start( $this->account_id, '  ' . self::IDENTIFIER . ' ' );
		$this->service()->start( $this->account_id, self::IDENTIFIER );

		$this->assertSame( 'running', $state['deletion']['state'] );
		$this->assertCount( 1, $this->scheduler->of( self::HOOK ), 'a second request does not queue a second job' );
		$this->assertSame( [ $this->account_id, 0 ], $this->scheduler->of( self::HOOK )[0]['args'] );
		$this->assertNotNull( $this->accounts()->get( $this->account_id ), 'the request deletes nothing itself' );
	}

	/**
	 * A queue that refuses the job fails the request and records why.
	 *
	 * @return void
	 */
	public function test_a_queue_that_refuses_fails_the_request(): void {
		$this->accounts()->set_enabled( $this->account_id, false );
		$this->scheduler->zero_for[ self::HOOK ] = true;

		$this->assertRefused( 500, function (): void {
			$this->service()->start( $this->account_id, self::IDENTIFIER );
		} );
		$this->assertSame( 'failed', $this->progress->get( $this->account_id )['state'] );
	}

	/**
	 * An unknown account, and one of another marketplace, are «not found».
	 *
	 * @return void
	 */
	public function test_an_unknown_account_is_not_found(): void {
		$this->assertRefused( 404, function (): void {
			$this->service()->state( 999 );
		} );
		$this->assertRefused( 404, function (): void {
			$this->service( 'another_market' )->state( $this->account_id );
		} );
		$this->assertRefused( 404, function (): void {
			$this->service()->start( 999, self::IDENTIFIER );
		} );
	}

	/**
	 * Once the account is gone the screen that waited for it is told so, for a day.
	 *
	 * @return void
	 */
	public function test_a_deleted_account_answers_from_the_result_the_job_left(): void {
		$this->progress->begin( 999 );
		$this->progress->finish( 999, [ 'Уберите адрес в кабинете.' ] );

		$state = $this->service()->state( 999 );

		$this->assertTrue( $state['deleted'] );
		$this->assertFalse( $state['can_delete'] );
		$this->assertSame( [ 'Уберите адрес в кабинете.' ], $state['deletion']['notes'] );
	}

	/**
	 * The preview counts what the account owns — links, tasks, postings, documents — and says WooCommerce orders stay.
	 *
	 * @return void
	 */
	public function test_the_preview_counts_what_the_account_owns(): void {
		$this->accounts()->set_enabled( $this->account_id, false );
		$this->own_rows( $this->account_id, 3, 2, 4, 1 );
		$this->own_rows( 77, 9, 9, 9, 9 );

		$preview = $this->service()->state( $this->account_id )['preview'];

		$this->assertSame(
			[ 'links' => 3, 'tasks' => 2, 'postings' => 4, 'documents' => 1 ],
			array_column( $preview['counts'], 'count', 'key' ),
			'only this account\'s rows are counted'
		);
		$this->assertStringContainsString( 'Заказы WooCommerce остаются', $preview['kept'] );
	}

	/**
	 * Postings that are the only stock record are warned about when order import is off; the push address is warned about
	 * for a marketplace that has push.
	 *
	 * @return void
	 */
	public function test_the_warnings_follow_the_account(): void {
		$this->accounts()->set_enabled( $this->account_id, false );
		$this->own_rows( $this->account_id, 0, 0, 2, 0 );

		$off = $this->service()->state( $this->account_id )['preview']['warnings'];
		$this->assertCount( 1, $off );
		$this->assertStringContainsString( 'импорт заказов', $off[0] );

		$this->accounts()->update_fields( $this->account_id, [ 'import_enabled' => true ] );
		$this->assertSame( [], $this->service()->state( $this->account_id )['preview']['warnings'], 'order import keeps the stock record' );

		$this->assertCount( 1, $this->service( Sqlite_Tables::MARKETPLACE_ID, true )->state( $this->account_id )['preview']['warnings'], 'push: the address warning' );
	}

	/**
	 * A deletion that says running but has not moved for a quarter of an hour is shown as failed, so it can be started again.
	 *
	 * @return void
	 */
	public function test_a_cut_off_deletion_is_shown_as_failed(): void {
		$this->progress->begin( $this->account_id );
		$this->progress->record( $this->account_id, 'links', 10 );
		$this->assertSame( 'running', $this->progress->get( $this->account_id )['state'] );

		$this->now += 901;

		$this->assertSame( 'failed', $this->progress->get( $this->account_id )['state'] );
		$this->assertSame( [ 'links' => 10 ], $this->progress->get( $this->account_id )['removed'] );
		$this->assertTrue( $this->progress->is_running( $this->account_id ), 'nothing was written: a run that comes back goes on' );
	}

	/**
	 * Every route is for those who manage the shop.
	 *
	 * @return void
	 */
	public function test_the_route_needs_the_capability(): void {
		$this->may = false;
		$denied    = $this->controller()->check_permission();

		$this->assertInstanceOf( \WP_Error::class, $denied );
		$this->assertSame( 403, $denied->data['status'] );

		$this->may = true;
		$this->assertTrue( $this->controller()->check_permission() );
	}

	/**
	 * The start takes `confirm` and nothing else.
	 *
	 * @return void
	 */
	public function test_the_start_takes_only_the_confirmation(): void {
		$this->accounts()->set_enabled( $this->account_id, false );

		foreach ( [ [], [ 'confirm' => 5 ], [ 'confirm' => self::IDENTIFIER, 'force' => true ], [ 'account_id' => 1 ] ] as $body ) {
			$answer = $this->controller()->start( $this->request( $body ) );

			$this->assertInstanceOf( \WP_Error::class, $answer );
			$this->assertSame( 400, $answer->data['status'] );
		}

		$this->assertSame( [], $this->scheduler->summary() );
	}

	/**
	 * The route answers with the service's statuses: 202 for a start, 409 and 400 for the refusals, 404 for no account.
	 *
	 * @return void
	 */
	public function test_the_route_answers_with_the_services_statuses(): void {
		$this->assertSame( 409, $this->controller()->start( $this->request( [ 'confirm' => self::IDENTIFIER ] ) )->data['status'] );
		$this->assertSame( 404, $this->controller()->get_state( $this->request( [], 999 ) )->data['status'] );

		$this->accounts()->set_enabled( $this->account_id, false );
		$this->assertSame( 400, $this->controller()->start( $this->request( [ 'confirm' => 'nope' ] ) )->data['status'] );

		$started = $this->controller()->start( $this->request( [ 'confirm' => self::IDENTIFIER ] ) );
		$this->assertInstanceOf( \WP_REST_Response::class, $started );
		$this->assertSame( 202, $started->get_status() );
		$this->assertSame( 'running', $started->get_data()['deletion']['state'] );
	}

	/**
	 * Builds the service over the test doubles.
	 *
	 * @param string $adapter_id Adapter id the plugin serves.
	 * @param bool   $has_push   Whether the marketplace has push notifications.
	 * @return Account_Deletion
	 */
	private function service( string $adapter_id = Sqlite_Tables::MARKETPLACE_ID, bool $has_push = false ): Account_Deletion {
		$tasks     = new Wpdb_Task_Ledger( $this->db, Sqlite_Tables::table( 'tasks' ), Sqlite_Tables::table( 'task_items' ) );
		$postings  = new Order_Registry( $this->db, Sqlite_Tables::table( 'postings' ) );
		$documents = new Wpdb_Label_Store( $this->db, Sqlite_Tables::table( 'tasks' ), Sqlite_Tables::table( 'documents' ) );
		$links     = new Wpdb_Link_Store( $this->db, Sqlite_Tables::table( 'links' ), Sqlite_Tables::table( 'link_ids' ) );

		return new Account_Deletion(
			$this->accounts(),
			$adapter_id,
			'cabinet_ref',
			new Account_Deletion_Preview(
				[
					'links'     => [ $links, 'count_for_account' ],
					'tasks'     => [ $tasks, 'count_for_account' ],
					'postings'  => [ $postings, 'count_for_account' ],
					'documents' => [ $documents, 'count_for_account' ],
				],
				new Effective_Settings( Sqlite_Tables::MARKETPLACE_ID ),
				$has_push
			),
			$this->progress,
			new Account_Deletion_Scheduler( $this->names() )
		);
	}

	/**
	 * Builds the controller over the service.
	 *
	 * @return Account_Deletion_Controller
	 */
	private function controller(): Account_Deletion_Controller {
		return new Account_Deletion_Controller( self::PLUGIN_ID, 'manage_woocommerce', $this->service() );
	}

	/**
	 * A request for the harness' account.
	 *
	 * @param array<string,mixed> $body       JSON body.
	 * @param int|null            $account_id Account in the route; the harness' account when null.
	 * @return \WP_REST_Request
	 */
	private function request( array $body, ?int $account_id = null ): \WP_REST_Request {
		$id      = $account_id ?? $this->account_id;
		$request = Mockery::mock( \WP_REST_Request::class );
		$request->shouldReceive( 'get_json_params' )->andReturn( $body );
		$request->shouldReceive( 'get_param' )->andReturnUsing(
			static function ( string $key ) use ( $id ) {
				return 'account_id' === $key ? $id : null;
			}
		);

		return $request;
	}

	/**
	 * Asserts a call is refused with a status.
	 *
	 * @param int      $status  Expected HTTP status.
	 * @param callable $call    The call.
	 * @param string   $message Failure message.
	 * @return void
	 */
	private function assertRefused( int $status, callable $call, string $message = '' ): void {
		try {
			$call();
		} catch ( Account_Deletion_Refused $refusal ) {
			$this->assertSame( $status, $refusal->status(), $message );
			return;
		}

		$this->fail( 'Expected a refusal with ' . $status . '. ' . $message );
	}

	/**
	 * Gives an account rows to count.
	 *
	 * @param int $account   Account id.
	 * @param int $links     Links.
	 * @param int $tasks     Tasks.
	 * @param int $postings  Postings.
	 * @param int $documents Documents.
	 * @return void
	 */
	private function own_rows( int $account, int $links, int $tasks, int $postings, int $documents ): void {
		$now = '2026-11-12 10:00:00';

		for ( $i = 1; $i <= $links; $i++ ) {
			$this->db->insert( Sqlite_Tables::table( 'links' ), [ 'account_id' => $account, 'wc_product_id' => $account * 1000 + $i ] );
		}
		for ( $i = 1; $i <= $tasks; $i++ ) {
			$this->db->insert( Sqlite_Tables::table( 'tasks' ), [ 'account_id' => $account, 'kind' => 'import', 'submitted_at' => $now ] );
		}
		for ( $i = 1; $i <= $postings; $i++ ) {
			$this->db->insert( Sqlite_Tables::table( 'postings' ), [ 'account_id' => $account, 'external_key' => "P{$i}", 'first_seen_at' => $now ] );
		}
		for ( $i = 1; $i <= $documents; $i++ ) {
			$this->db->insert( Sqlite_Tables::table( 'documents' ), [ 'account_id' => $account, 'created_at' => $now ] );
		}
	}
}
