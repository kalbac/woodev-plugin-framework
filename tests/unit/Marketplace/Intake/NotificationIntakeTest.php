<?php
/**
 * The notification intake: record, schedule, answer — and never a remote call.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Intake;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Intake\Intake_Response;
use Woodev\Framework\Marketplace\Intake\Last_Notification_Reader;
use Woodev\Framework\Marketplace\Intake\Notification_Account_Reader;
use Woodev\Framework\Marketplace\Intake\Notification_Intake;
use Woodev\Framework\Marketplace\Intake\Push_Rate_Limiter;
use Woodev\Framework\Marketplace\Intake\Push_Registrar;
use Woodev\Framework\Marketplace\Intake\Registration_Refresher;
use Woodev\Framework\Marketplace\Intake\Sender_Network;
use Woodev\Framework\Marketplace\Intake\Webhook_Switches;
use Woodev\Framework\Marketplace\Jobs\Drain_Events_Runner;
use Woodev\Framework\Marketplace\Jobs\Drain_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Event_Handler_Registry;
use Woodev\Framework\Marketplace\Ledger\Event_Ledger;
use Woodev\Framework\Marketplace\Ledger\Wpdb_Event_Ledger;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Push_Protocol;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Scripted_Inbound_Decoder;
use Woodev\Tests\Unit\Marketplace\Support\Sync_Test_Case;

/**
 * Moved from the Ozon plugin's `NotificationIntakeTest`, against a marketplace with its own body, its own allow-list and its
 * own error format: the intake stores and schedules, answers what the protocol gave it, and calls nothing remote.
 */
final class NotificationIntakeTest extends Sync_Test_Case {
	private const DRAIN = 'woodev_fake_seller_drain_events';
	private const SWEEP = 'woodev_fake_seller_drain_events_sweep';

	/**
	 * Account A.
	 *
	 * @var int
	 */
	private $a = 0;

	/**
	 * Account B.
	 *
	 * @var int
	 */
	private $b = 0;

	/**
	 * Intake under test.
	 *
	 * @var Notification_Intake
	 */
	private $intake;

	/**
	 * What the merchant saved on the notification switches, by option name.
	 *
	 * @var array<string,string>
	 */
	private $switch_options = [];

	/**
	 * Two accounts and the intake over the real ledger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->a      = $this->account_id;
		$this->b      = $this->accounts()->save( new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'Второй', true, [ 'cabinet_ref' => '777002', 'api_token' => 'tok-2' ] ) );
		$this->intake = $this->build_intake( $this->ledger() );

		$this->db->query( 'UPDATE ' . Sqlite_Tables::table( 'accounts' ) . ' SET seller_id = 4242 WHERE account_id = ' . $this->b );
	}

	/**
	 * A notification is answered 200 with the marketplace's success body, stored as new for its account, and nothing remote is
	 * called on the way.
	 *
	 * @return void
	 */
	public function test_a_notification_is_stored_and_answered_with_the_success_body(): void {
		$answer = $this->push( $this->order_created() );

		$this->assertSame( 200, $answer->status() );
		$this->assertSame( Scripted_Inbound_Decoder::SUCCESS, $answer->body() );
		$this->assertCount( 1, $this->events() );
		$this->assertSame( 'new', $this->events()[0]['status'] );
		$this->assertSame( (string) $this->a, (string) $this->events()[0]['account_id'] );
		$this->assertSame( '1', (string) $this->events()[0]['ip_trusted'] );
		$this->assertSame( [], $this->market->requests, 'request time calls nothing remote' );
	}

	/**
	 * A notification queues one drain and the recurring sweep, in the plugin's group.
	 *
	 * @return void
	 */
	public function test_a_notification_queues_one_drain_and_the_sweep(): void {
		$this->push( $this->order_created() );

		$this->assertSame( [ 'recurring:' . self::SWEEP . ':pending', 'async:' . self::DRAIN . ':pending' ], $this->scheduler->summary() );
		$this->assertSame( [ 'woodev-fake-seller', 'woodev-fake-seller' ], array_column( $this->scheduler->actions, 'group' ) );
	}

	/**
	 * The same notification sent again is answered 200 and stored once; nothing more is queued.
	 *
	 * @return void
	 */
	public function test_a_duplicate_is_answered_200_and_stored_once(): void {
		$first   = $this->push( $this->order_created() );
		$actions = count( $this->scheduler->actions );
		$second  = $this->push( $this->order_created() + [ 'time' => 'later' ] );

		$this->assertSame( 200, $first->status() );
		$this->assertSame( 200, $second->status() );
		$this->assertCount( 1, $this->events() );
		$this->assertCount( $actions, $this->scheduler->actions );
	}

	/**
	 * A burst of notifications queues the drain once.
	 *
	 * @return void
	 */
	public function test_a_burst_queues_the_drain_once(): void {
		$this->push( $this->order_created( '1-1' ) );
		$this->push( $this->order_created( '2-1' ) );
		$this->push( $this->order_created( '3-1' ) );

		$this->assertCount( 3, $this->events() );
		$this->assertCount( 2, $this->scheduler->actions, 'one async drain and one sweep' );
	}

	/**
	 * The same payload in two accounts is two events.
	 *
	 * @return void
	 */
	public function test_the_same_payload_in_two_accounts_is_two_events(): void {
		$this->push( $this->order_created() );
		$this->push( $this->order_created(), $this->b );

		$this->assertSame( [ (string) $this->a, (string) $this->b ], array_map( 'strval', array_column( $this->events(), 'account_id' ) ) );
	}

	/**
	 * What the decoder calls noise leaves no row and queues nothing.
	 *
	 * @return void
	 */
	public function test_a_ping_leaves_no_row(): void {
		$answer = $this->push( [ 'kind' => 'ping' ] );

		$this->assertSame( 200, $answer->status() );
		$this->assertSame( Scripted_Inbound_Decoder::SUCCESS, $answer->body() );
		$this->assertSame( [], $this->events() );
		$this->assertSame( [], $this->scheduler->actions );
	}

	/**
	 * A body the decoder cannot read, and a type it does not serve, are answered 200 and stored ignored; no drain is queued.
	 *
	 * @return void
	 */
	public function test_what_the_decoder_ignores_is_stored_and_never_worked(): void {
		$this->assertSame( 200, $this->push( '{broken' )->status() );
		$this->assertSame( 200, $this->push( [ 'kind' => 'chat_message', 'id' => 'x' ] )->status() );

		$this->assertSame( [ 'ignored', 'ignored' ], array_column( $this->events(), 'status' ) );
		$this->assertSame( [ 'invalid json', 'unsupported type' ], array_column( $this->events(), 'result' ) );
		$this->assertSame( [], $this->scheduler->actions );
	}

	/**
	 * A wrong token — a made-up one, or another account's — is stored without an account and never worked.
	 *
	 * @return void
	 */
	public function test_a_wrong_token_is_stored_without_an_account_and_never_worked(): void {
		$answer = $this->intake->handle_token( $this->a, str_repeat( 'c', 32 ), $this->json( $this->order_created( '1-1' ) ), $this->from( Fake_Push_Protocol::TRUSTED_IP ) );
		$this->intake->handle_token( $this->b, $this->token( $this->a ), $this->json( $this->order_created( '2-1' ) ), $this->from( Fake_Push_Protocol::TRUSTED_IP ) );

		$this->assertSame( 200, $answer->status() );
		$this->assertSame( [ null, null ], array_column( $this->events(), 'account_id' ) );
		$this->assertSame( [ 'unknown account', 'unknown account' ], array_column( $this->events(), 'result' ) );
		$this->assertSame( [], $this->scheduler->actions );
	}

	/**
	 * With a token, an address outside the marketplace's ranges is recorded on the event and does not refuse the request.
	 *
	 * @return void
	 */
	public function test_an_untrusted_address_with_a_token_is_flagged_not_refused(): void {
		$answer = $this->push( $this->order_created(), $this->a, Fake_Push_Protocol::FOREIGN_IP );

		$this->assertSame( 200, $answer->status() );
		$this->assertSame( '0', (string) $this->events()[0]['ip_trusted'] );
		$this->assertSame( 'new', $this->events()[0]['status'] );
		$this->assertNotSame( [], $this->scheduler->actions );
	}

	/**
	 * A storage failure answers 500 with the marketplace's own error format, so the marketplace sends the notification again.
	 *
	 * @return void
	 */
	public function test_a_storage_failure_answers_500_in_the_marketplaces_format(): void {
		$broken = $this->build_intake( new Wpdb_Event_Ledger( $this->db, 'wp_no_such_table', $this->clock() ) );
		$answer = $broken->handle_token( $this->a, $this->token( $this->a ), $this->json( $this->order_created() ), $this->from( Fake_Push_Protocol::TRUSTED_IP ) );

		$this->assertSame( 500, $answer->status() );
		$this->assertSame( Intake_Response::REASON_NOT_STORED, $answer->body()['fault'] );
		$this->assertNotSame( '', $answer->body()['text'] );
		$this->assertSame( [], $this->scheduler->actions );
		$this->assertStringContainsString( 'could not be stored', $this->log[0] );
	}

	/**
	 * A failed first enqueue is recovered by the sweep without another notification.
	 *
	 * @return void
	 */
	public function test_a_failed_first_enqueue_is_recovered_by_the_sweep(): void {
		$this->scheduler->throw_for[ self::DRAIN ] = true;

		$answer = $this->push( $this->order_created() );

		$this->assertSame( 200, $answer->status() );
		$this->assertSame( [ 'recurring:' . self::SWEEP . ':pending' ], $this->scheduler->summary(), 'the sweep exists although the async enqueue threw' );
		$this->assertSame( [ 'new' ], array_column( $this->events(), 'status' ) );

		$this->run_sweep();

		$this->assertSame( [ 'done' ], array_column( $this->events(), 'status' ) );
	}

	/**
	 * An enqueue that returns zero is logged and covered by the sweep.
	 *
	 * @return void
	 */
	public function test_an_enqueue_that_returns_zero_is_logged_and_covered_by_the_sweep(): void {
		$this->scheduler->zero_for[ self::DRAIN ] = true;

		$answer = $this->push( $this->order_created() );

		$this->assertSame( 200, $answer->status() );
		$this->assertSame( [ 'recurring:' . self::SWEEP . ':pending' ], $this->scheduler->summary() );
		$this->assertStringContainsString( 'sweep will pick the event up', $this->log[0] );
	}

	/**
	 * With no recovery at all the notification is not acknowledged; the marketplace's retry lands on the duplicate path, which
	 * schedules.
	 *
	 * @return void
	 */
	public function test_without_a_recovery_the_notification_is_retried_and_the_duplicate_path_schedules(): void {
		$this->scheduler->zero_for[ self::SWEEP ] = true;

		$first = $this->push( $this->order_created() );

		$this->assertSame( 500, $first->status() );
		$this->assertCount( 1, $this->events(), 'the event itself is durable' );

		$this->scheduler->zero_for = [];
		$retry                     = $this->push( $this->order_created() );

		$this->assertSame( 200, $retry->status() );
		$this->assertCount( 1, $this->events(), 'still one event' );
		$this->assertCount( 2, $this->scheduler->actions, 'the retry created the sweep and the drain' );
	}

	/**
	 * A notification during a running drain queues the next drain.
	 *
	 * @return void
	 */
	public function test_a_notification_during_a_running_drain_queues_the_next_drain(): void {
		$this->push( $this->order_created( '1-1' ) );
		$this->scheduler->start_running( self::DRAIN );
		$this->push( $this->order_created( '2-1' ) );

		$this->assertSame(
			[
				'recurring:' . self::SWEEP . ':pending',
				'async:' . self::DRAIN . ':in-progress',
				'async:' . self::DRAIN . ':pending',
			],
			$this->scheduler->summary()
		);
	}

	/**
	 * A failed account read is retried, never filed as «unknown account», on either URL.
	 *
	 * @return void
	 */
	public function test_a_failed_account_read_is_retried_and_not_filed_as_unknown(): void {
		$token = $this->token( $this->a );

		$this->db->fail_reads = true;

		$with_token    = $this->intake->handle_token( $this->a, $token, $this->json( $this->order_created() ), $this->from( Fake_Push_Protocol::TRUSTED_IP ) );
		$without_token = $this->intake->handle_tokenless( 'cabinet-4417', $this->json( $this->order_created() ), $this->from( Fake_Push_Protocol::TRUSTED_IP ) );

		$this->db->fail_reads = false;

		$this->assertSame( [ 500, 500 ], [ $with_token->status(), $without_token->status() ] );
		$this->assertSame( Intake_Response::REASON_NOT_STORED, $with_token->body()['fault'] );
		$this->assertSame( [], $this->events(), 'no ignored row for a notification that has an account' );

		$this->assertSame( 200, $this->push( $this->order_created() )->status() );
		$this->assertSame( (string) $this->a, (string) $this->events()[0]['account_id'] );
		$this->assertSame( 'new', $this->events()[0]['status'] );
	}

	/**
	 * The 601st request of a token in a minute is refused with a Retry-After; another token has its own budget.
	 *
	 * @return void
	 */
	public function test_the_601st_request_of_a_token_in_a_minute_is_refused(): void {
		for ( $i = 0; $i < Push_Rate_Limiter::BUDGET; $i++ ) {
			$this->assertSame( 200, $this->push( [ 'kind' => 'ping' ] )->status() );
		}

		$answer = $this->push( $this->order_created() );

		$this->assertSame( 429, $answer->status() );
		$this->assertSame( [ 'Retry-After' => '60' ], $answer->headers() );
		$this->assertSame( Intake_Response::REASON_RATE_LIMITED, $answer->body()['fault'] );
		$this->assertSame( [], $this->events() );
		$this->assertSame( 200, $this->push( $this->order_created(), $this->b )->status(), 'another token has its own budget' );

		$this->now += Push_Rate_Limiter::WINDOW;
		$this->assertSame( 200, $this->push( $this->order_created( '9-9' ) )->status(), 'the next minute is a new budget' );
	}

	/**
	 * A type the merchant switched off is answered 200 like any other and stored as ignored, never worked: no drain is queued,
	 * and the switches of the other types are untouched.
	 *
	 * @return void
	 */
	public function test_a_switched_off_type_is_stored_as_ignored_and_never_worked(): void {
		$this->switch_options['woodev_fake_seller_webhook_order_created'] = 'no';

		$off = $this->push( $this->order_created( '1-1' ) );

		$this->assertSame( 200, $off->status() );
		$this->assertSame( Scripted_Inbound_Decoder::SUCCESS, $off->body() );
		$this->assertSame( 'ignored', $this->events()[0]['status'] );
		$this->assertSame( [], $this->scheduler->actions, 'nothing is queued for a switched-off type' );

		$this->push( [ 'kind' => 'order_changed', 'id' => '2-1' ] );

		$this->assertSame( [ 'ignored', 'new' ], array_column( $this->events(), 'status' ) );
		$this->assertNotSame( [], $this->scheduler->actions );
	}

	/**
	 * An account that does not sync stock drops the stock notification like a switched-off type, and still takes the others.
	 *
	 * @return void
	 */
	public function test_an_account_with_stock_off_drops_the_stock_notification_only(): void {
		$this->accounts()->update_fields( $this->a, [ 'stock_mode' => 'off' ] );

		$this->assertSame( 200, $this->push( [ 'kind' => 'stock_changed', 'id' => '2-1' ] )->status() );
		$this->assertSame( [ 'ignored' ], array_column( $this->events(), 'status' ) );
		$this->assertSame( [], $this->scheduler->actions, 'nothing is queued for a stock notification of an account with stock off' );

		$this->push( $this->order_created( '3-1' ) );
		$this->push( [ 'kind' => 'stock_changed', 'id' => '2-2' ], $this->b );

		$this->assertSame( [ 'ignored', 'new', 'new' ], array_column( $this->events(), 'status' ) );
	}

	/**
	 * The intake, not the decoder, says whether the sender is the marketplace's: the decoder reports every address as foreign.
	 *
	 * @return void
	 */
	public function test_the_intake_records_whether_the_sender_is_in_the_marketplaces_network(): void {
		$this->push( $this->order_created( '1-1' ), null, Fake_Push_Protocol::TRUSTED_IP );
		$this->push( $this->order_created( '1-2' ), null, Fake_Push_Protocol::FOREIGN_IP );

		$this->assertSame( [ '1', '0' ], array_map( 'strval', array_column( $this->events(), 'ip_trusted' ) ) );
	}

	/**
	 * Without a token the address is the only proof: one outside the marketplace's ranges is refused with 403 in the
	 * marketplace's format, and nothing is stored — whatever the body or a forwarded header claims.
	 *
	 * @return void
	 */
	public function test_without_a_token_a_foreign_address_is_refused(): void {
		$forged = [
			'REMOTE_ADDR'          => Fake_Push_Protocol::FOREIGN_IP,
			'HTTP_X_FORWARDED_FOR' => Fake_Push_Protocol::TRUSTED_IP,
		];

		$answer = $this->intake->handle_tokenless( 'cabinet-4417', $this->json( $this->order_created() ), $forged );
		$ping   = $this->intake->handle_tokenless( '', $this->json( [ 'kind' => 'ping' ] ), $forged );

		$this->assertSame( [ 403, 403 ], [ $answer->status(), $ping->status() ] );
		$this->assertSame(
			[
				'fault' => Intake_Response::REASON_UNAUTHORISED,
				'text'  => 'Unauthorized request',
			],
			$answer->body()
		);
		$this->assertSame( [], $this->events() );
	}

	/**
	 * Without a token the account is the one the body names: by the cabinet identifier first, else by the seller id.
	 *
	 * @return void
	 */
	public function test_without_a_token_the_account_is_the_one_the_body_names(): void {
		$trusted = $this->from( Fake_Push_Protocol::TRUSTED_IP );

		$this->assertSame( 200, $this->intake->handle_tokenless( 'cabinet-4417', $this->json( $this->order_created( '1-1' ) ), $trusted )->status() );
		$this->intake->handle_tokenless( '4242', $this->json( $this->order_created( '2-1' ) ), $trusted );
		$this->intake->handle_tokenless( '', $this->json( [ 'kind' => 'ping' ] ), $trusted );

		$this->assertSame( [ (string) $this->a, (string) $this->b ], array_map( 'strval', array_column( $this->events(), 'account_id' ) ) );
		$this->assertNotSame( [], $this->scheduler->actions );
	}

	/**
	 * An unknown owner, an empty one, and a seller id two accounts share get no account: an event is never filed under the
	 * wrong cabinet.
	 *
	 * @return void
	 */
	public function test_an_unknown_or_ambiguous_owner_gets_no_account(): void {
		$third = $this->accounts()->save( new Account( 0, Sqlite_Tables::MARKETPLACE_ID, 'Третий', true, [ 'cabinet_ref' => '777003', 'api_token' => 'tok-3' ] ) );
		$this->db->query( 'UPDATE ' . Sqlite_Tables::table( 'accounts' ) . ' SET seller_id = 4242 WHERE account_id = ' . $third );

		$trusted = $this->from( Fake_Push_Protocol::TRUSTED_IP );

		$this->intake->handle_tokenless( '31337', $this->json( $this->order_created( '9-1' ) ), $trusted );
		$this->intake->handle_tokenless( '4242', $this->json( $this->order_created( '9-2' ) ), $trusted );
		$this->intake->handle_tokenless( '', $this->json( $this->order_created( '9-3' ) ), $trusted );

		$this->assertSame( [ null, null, null ], array_column( $this->events(), 'account_id' ) );
		$this->assertSame( [], $this->scheduler->actions );
	}

	/**
	 * The reader sees the features of an account that decide which notification types it wants, and never its secrets.
	 *
	 * @return void
	 */
	public function test_the_reader_exposes_the_features_and_no_secret(): void {
		$this->set_account(
			[
				'stock_mode'      => 'marketplace',
				'price_direction' => 'from_marketplace',
				'orders_enabled'  => false,
			]
		);

		$before  = count( $this->db->statements );
		$reader  = $this->reader();
		$account = $reader->find( $this->a );

		$this->assertSame( 'cabinet-4417', $account->external_id() );
		$this->assertTrue( $account->is_enabled() );
		$this->assertSame( [ 'marketplace', 'from_marketplace', false, false ], [ $account->stock_mode(), $account->price_direction(), $account->orders_enabled(), $account->import_enabled() ] );
		$this->assertSame( '', $account->seller_id() );
		$this->assertSame( '4242', $reader->find( $this->b )->seller_id() );
		$this->assertCount( 2, $reader->all() );
		$this->assertNull( $reader->find( 999 ) );
		$this->assertNull( $reader->find_by_token( $this->a, '' ), 'an empty token never matches' );
		$this->assertStringNotContainsString( 'secret', implode( ' ', array_slice( $this->db->statements, $before ) ), 'the reader never selects the secrets' );
	}

	/**
	 * What the marketplace says about our URL is stored on the account with the time it was asked; «not registered» clears it,
	 * and a marketplace that cannot be asked leaves the stored state as it was.
	 *
	 * @return void
	 */
	public function test_the_registration_state_is_refreshed_from_the_marketplace(): void {
		\Brain\Monkey\Functions\when( 'rest_url' )->alias(
			static function ( string $path = '' ): string {
				return 'https://shop.test/wp-json/' . ltrim( $path, '/' );
			}
		);

		$reader    = $this->reader();
		$refresher = new Registration_Refresher( $reader, Sqlite_Tables::MARKETPLACE_ID );
		$account   = $reader->find( $this->a );
		$registrar = new class() implements Push_Registrar {
			/**
			 * URLs asked about.
			 *
			 * @var string[]
			 */
			public $asked = [];

			/**
			 * The next answer; a Throwable is thrown.
			 *
			 * @var mixed
			 */
			public $answer;

			/**
			 * Answers about a URL.
			 *
			 * @param string $url URL.
			 * @return array<string,mixed>|null
			 * @throws \Throwable The scripted failure.
			 */
			public function registration_of( string $url ): ?array {
				$this->asked[] = $url;

				if ( $this->answer instanceof \Throwable ) {
					throw $this->answer;
				}

				return $this->answer;
			}
		};

		$registrar->answer = [ 'id' => 17, 'healthy' => true ];
		$refresher->refresh( $registrar, $account );

		$this->assertSame( [ 'https://shop.test/wp-json/woodev/v1/marketplace/fake/notifications/' . $this->a . '/' . $account->token() ], $registrar->asked );
		$this->assertSame( 17, $reader->find( $this->a )->state()['id'] );
		$this->assertEqualsWithDelta( time(), $reader->find( $this->a )->state()['checked_at'], 5 );

		$registrar->answer = new \RuntimeException( 'the marketplace is down' );

		try {
			$refresher->refresh( $registrar, $account );
			$this->fail( 'The failure must reach the caller.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 17, $reader->find( $this->a )->state()['id'], 'the stored state is left as it was' );
		}

		$registrar->answer = null;
		$refresher->refresh( $registrar, $account );

		$this->assertSame( [], $reader->find( $this->a )->state() );
	}

	/**
	 * The settings page reads the latest notification that was meant to be processed from our own table.
	 *
	 * @return void
	 */
	public function test_the_latest_notification_is_read_locally(): void {
		$reader = new Last_Notification_Reader( $this->db, Sqlite_Tables::table( 'events' ) );

		$this->assertNull( $reader->latest( $this->a ) );

		$this->push( $this->order_created( '1-1' ) );
		$this->push( [ 'kind' => 'stock_changed', 'id' => '2-1' ] );
		$this->push( [ 'kind' => 'chat_message', 'id' => '3-1' ] );

		$this->assertSame( 'stock_changed', $reader->latest( $this->a )['type'], 'an ignored event is not the latest' );
		$this->assertSame( 'new', $reader->latest( $this->a )['status'] );
		$this->assertSame( self::NOW, $reader->latest( $this->a )['received_at'] );
		$this->assertNull( $reader->latest( $this->b ) );
		$this->assertSame( [], $this->market->requests );
	}

	/**
	 * Sends a notification to the URL with the token of an account.
	 *
	 * @param array<string,mixed>|string $body       Body, or raw text.
	 * @param int|null                   $account_id Account; A when null.
	 * @param string                     $ip         Sender address.
	 * @return Intake_Response
	 */
	private function push( $body, ?int $account_id = null, string $ip = Fake_Push_Protocol::TRUSTED_IP ): Intake_Response {
		$account_id = $account_id ?? $this->a;

		return $this->intake->handle_token( $account_id, $this->token( $account_id ), is_string( $body ) ? $body : $this->json( $body ), $this->from( $ip ) );
	}

	/**
	 * An «order created» notification.
	 *
	 * @param string $id The marketplace's order id.
	 * @return array<string,mixed>
	 */
	private function order_created( string $id = '0-1' ): array {
		return [
			'kind' => 'order_created',
			'id'   => $id,
		];
	}

	/**
	 * Encodes a body.
	 *
	 * @param array<string,mixed> $body Body.
	 * @return string
	 */
	private function json( array $body ): string {
		return (string) json_encode( $body );
	}

	/**
	 * Server variables of a request from an address.
	 *
	 * @param string $ip Address.
	 * @return array<string,string>
	 */
	private function from( string $ip ): array {
		return [ 'REMOTE_ADDR' => $ip ];
	}

	/**
	 * Returns the URL token of an account.
	 *
	 * @param int $account_id Account id.
	 * @return string
	 */
	private function token( int $account_id ): string {
		return (string) $this->accounts()->get( $account_id )->setting( 'notification_token' );
	}

	/**
	 * The account reader.
	 *
	 * @return Notification_Account_Reader
	 */
	private function reader(): Notification_Account_Reader {
		return new Notification_Account_Reader( $this->db, Sqlite_Tables::table( 'accounts' ) );
	}

	/**
	 * The ledger over the test clock.
	 *
	 * @return Wpdb_Event_Ledger
	 */
	private function ledger(): Wpdb_Event_Ledger {
		return new Wpdb_Event_Ledger( $this->db, Sqlite_Tables::table( 'events' ), $this->clock() );
	}

	/**
	 * Builds the intake over a ledger, with a rate limiter that counts in memory on the test clock.
	 *
	 * @param Event_Ledger $ledger Ledger.
	 * @return Notification_Intake
	 */
	private function build_intake( Event_Ledger $ledger ): Notification_Intake {
		$limiter = new class( $this->clock() ) extends Push_Rate_Limiter {
			/**
			 * Clock.
			 *
			 * @var callable
			 */
			private $clock;

			/**
			 * Counters by key.
			 *
			 * @var array<string,int>
			 */
			private $counters = [];

			/**
			 * Creates the limiter.
			 *
			 * @param callable $clock Clock.
			 */
			public function __construct( callable $clock ) {
				$this->clock = $clock;
			}

			/**
			 * Counts in memory.
			 *
			 * @param string $key Key.
			 * @param int    $ttl Unused.
			 * @return int
			 */
			protected function increment_rate_limit_counter( string $key, int $ttl ): int {
				$this->counters[ $key ] = ( $this->counters[ $key ] ?? 0 ) + 1;

				return $this->counters[ $key ];
			}

			/**
			 * Reads the test clock.
			 *
			 * @return int
			 */
			protected function rate_limit_now(): int {
				return (int) call_user_func( $this->clock );
			}
		};

		$protocol = new Fake_Push_Protocol();
		$network  = new Sender_Network( Sqlite_Tables::MARKETPLACE_ID, $protocol );
		$switches = new Webhook_Switches(
			$protocol->webhook_types(),
			'woodev_fake_seller_',
			function ( string $name, $default = null ) {
				return $this->switch_options[ $name ] ?? $default;
			}
		);

		return new Notification_Intake( new Scripted_Inbound_Decoder(), $protocol, $network, $switches, $ledger, $this->reader(), new Drain_Scheduler( $this->names() ), $limiter, [ $this, 'log_line' ] );
	}

	/**
	 * Runs the drain as the recurring sweep would.
	 *
	 * @return void
	 */
	private function run_sweep(): void {
		( new Drain_Events_Runner( $this->ledger(), $this->reader(), new Event_Handler_Registry(), new Drain_Scheduler( $this->names() ) ) )->run( $this->a );
	}

	/**
	 * Returns the event rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function events(): array {
		return $this->db->rows( 'SELECT * FROM ' . Sqlite_Tables::table( 'events' ) . ' ORDER BY event_id' );
	}
}
