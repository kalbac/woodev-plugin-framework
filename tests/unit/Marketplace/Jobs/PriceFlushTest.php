<?php
/**
 * The price half of the account's flush.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\Remote_Error;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Framework\Marketplace\Ledger\Item_Result;
use Woodev\Framework\Marketplace\Price\Flush_Result;
use Woodev\Framework\Marketplace\Price\Push_Receipt;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Remote_Exception;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Scripted_Price_Pusher;
use Woodev\Tests\Unit\Marketplace\Support\Sync_Test_Case;

/**
 * Moved from the Ozon plugin's `PriceFlushTest`: what the price half sends, what an answer settles, how a refusal backs off,
 * and that a sender never writes without the account claim it was handed.
 */
final class PriceFlushTest extends Sync_Test_Case {
	/**
	 * The marketplace double.
	 *
	 * @var Scripted_Price_Pusher
	 */
	private $prices;

	/**
	 * Builds the double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->prices = new Scripted_Price_Pusher();
	}

	/**
	 * A wanted price goes out whole — price, old price, minimum price — and once the marketplace accepted it there is nothing
	 * to send.
	 *
	 * @return void
	 */
	public function test_a_wanted_price_is_sent_once(): void {
		$product = $this->priced( 'a' );
		$this->want_price( $product, [ 'price' => '500.00', 'old_price' => '700.00', 'min_price' => '400.00' ] );

		$this->assertSame( 'Отправлено: 1', $this->flush_now()->message() );
		$this->assertSame( [ [ 'a' => [ '500.00', '700.00', '400.00' ] ] ], $this->prices->requests );

		$this->now += 400;
		$this->assertSame( 'Нечего отправлять (значения совпадают)', $this->flush_now()->message() );
		$this->assertCount( 1, $this->prices->requests );
	}

	/**
	 * A change of the old price alone, or of the minimum price alone, is a change of the payload and is sent.
	 *
	 * @return void
	 */
	public function test_a_change_of_any_part_of_the_payload_is_sent(): void {
		$product = $this->priced( 'a' );

		$this->want_price( $product, [ 'price' => '500.00', 'old_price' => '700.00' ] );
		$this->flush_now();

		$this->now += 400; // The spacing per product has passed.
		$this->want_price( $product, [ 'price' => '500.00', 'old_price' => '750.00' ] );
		$this->flush_now();

		$this->now += 400;
		$this->want_price( $product, [ 'price' => '500.00', 'old_price' => '750.00', 'min_price' => '400.00' ] );
		$this->flush_now();

		$this->assertSame( [ [ '500.00', '700.00', null ], [ '500.00', '750.00', null ], [ '500.00', '750.00', '400.00' ] ], array_column( $this->prices->requests, 'a' ) );
		$this->assertSame( 3, (int) $this->price_row( $product )['sent_rev'] );
	}

	/**
	 * A flush sends at most one request's worth of prices per request.
	 *
	 * @return void
	 */
	public function test_a_flush_cuts_the_prices_into_requests(): void {
		$this->price_batch = 2;

		foreach ( [ 'a', 'b', 'c' ] as $offer ) {
			$this->want_price( $this->priced( $offer ) );
		}

		$result = $this->flush_now();

		$this->assertSame( 'Отправлено: 3', $result->message() );
		$this->assertSame( [ 2, 1 ], array_map( 'count', $this->prices->requests ) );
	}

	/**
	 * A save while the request is in flight bumps the pending revision; the answer settles only the snapshot.
	 *
	 * @return void
	 */
	public function test_the_acknowledgement_settles_only_the_in_flight_revision(): void {
		$product = $this->priced( 'a' );
		$this->want_price( $product, [ 'price' => '500.00' ] );

		$this->prices->script[] = function () use ( $product ) {
			$this->want_price( $product, [ 'price' => '510.00' ] ); // A product save while the request is in flight.

			return null;
		};
		$this->flush_now();

		$row = $this->price_row( $product );
		$this->assertSame( 1, (int) $row['sent_rev'] );
		$this->assertSame( 2, (int) $row['pending_rev'] );
		$this->assertSame( '500.00', $row['sent_payload']['price'], 'what the marketplace accepted is what was snapshotted, not the newer wish' );

		$this->now += 400;
		$this->flush_now();

		$this->assertSame( '510.00', $this->prices->requests[1]['a'][0] );
		$this->assertSame( 2, (int) $this->price_row( $product )['sent_rev'] );
	}

	/**
	 * A refused price keeps the marketplace's text verbatim and backs off on the ladder; five failures of one revision stop
	 * the retries until the payload changes.
	 *
	 * @return void
	 */
	public function test_a_refused_price_keeps_the_text_and_backs_off(): void {
		$product = $this->priced( 'a' );
		$this->want_price( $product, [ 'min_price' => '600.00' ] );

		$this->prices->script[] = $this->refusal( $product, 'the minimum price is above the price' );
		$result                 = $this->flush_now();

		$this->assertSame( 'Ошибка: the minimum price is above the price', $result->message() );

		$row = $this->price_row( $product );
		$this->assertSame( 'the minimum price is above the price', $row['last_error'] );
		$this->assertSame( 1, (int) $row['attempts'] );
		$this->assertNull( $row['sent_payload'] );

		$this->flush_now();
		$this->assertCount( 1, $this->prices->requests, 'inside the backoff nothing is sent' );

		foreach ( [ 70, 200, 430, 910 ] as $wait ) {
			$this->now             += $wait;
			$this->prices->script[] = $this->refusal( $product, 'the minimum price is above the price' );
			$this->flush_now();
		}

		$this->assertCount( 5, $this->prices->requests );
		$this->assertSame( 5, (int) $this->price_row( $product )['attempts'] );

		$this->now += 4000;
		$this->flush_now();
		$this->assertCount( 5, $this->prices->requests, 'five failures of one revision stop the retries' );

		$this->want_price( $product, [ 'min_price' => '80.00' ] );
		$this->flush_now();

		$this->assertCount( 6, $this->prices->requests, 'a changed payload is sent at once' );
		$this->assertSame( 0, (int) $this->price_row( $product )['attempts'] );
	}

	/**
	 * A line the marketplace left out of its answer is a refusal with the framework's own sentence.
	 *
	 * @return void
	 */
	public function test_a_line_without_an_answer_is_refused(): void {
		$product = $this->priced( 'a' );
		$this->want_price( $product );

		$this->prices->script[] = static function (): array {
			return [];
		};
		$result                 = $this->flush_now();

		$this->assertSame( 1, $result->failed() );
		$this->assertSame( 'Маркетплейс не вернул ответ по этому товару.', $this->price_row( $product )['last_error'] );
	}

	/**
	 * A timeout is an uncertain outcome: the snapshot stays, the next request waits at least one price interval and carries the
	 * newest revision only.
	 *
	 * @return void
	 */
	public function test_after_a_timeout_the_next_request_carries_the_newest_revision_only(): void {
		$product = $this->priced( 'a' );
		$this->want_price( $product, [ 'price' => '500.00' ] );

		$this->prices->script[] = static function (): void {
			throw Fake_Remote_Exception::of( Remote_Error::RETRY, 'Operation timed out' );
		};
		$this->assertSame( 1, $this->flush_now()->failed() );

		$row = $this->price_row( $product );
		$this->assertNull( $row['sent_payload'] );
		$this->assertSame( 1, (int) $row['in_flight_rev'], 'the snapshot of the uncertain send is kept' );
		$this->assertCount( 1, $this->log, 'the refused call is logged' );

		$this->want_price( $product, [ 'price' => '505.00' ] );
		$this->now += 70;
		$this->flush_now();
		$this->assertCount( 1, $this->prices->requests, 'the timed-out request may have changed the price: the next one waits a price interval' );

		$this->now += 400;
		$this->flush_now();

		$this->assertCount( 2, $this->prices->requests );
		$this->assertSame( '505.00', $this->prices->requests[1]['a'][0] );
		$this->assertSame( 2, (int) $this->price_row( $product )['sent_rev'] );
	}

	/**
	 * At most one accepted price per product within the cadence's interval.
	 *
	 * @return void
	 */
	public function test_one_price_per_product_per_interval(): void {
		$product = $this->priced( 'a' );
		$this->want_price( $product, [ 'price' => '500.00' ] );
		$this->flush_now();

		$this->want_price( $product, [ 'price' => '520.00' ] );
		$this->now += 300;
		$this->flush_now();
		$this->assertCount( 1, $this->prices->requests );

		$this->now += 70;
		$this->flush_now();
		$this->assertCount( 2, $this->prices->requests );
	}

	/**
	 * A pending intent of an account that does not push prices is never sent, and a card that is not created waits.
	 *
	 * @return void
	 */
	public function test_the_flush_respects_direction_and_card_state(): void {
		$created = $this->priced( 'a-1' );
		$waiting = $this->priced( 'a-2', [ 'state' => 'pending_remote_ids' ] );
		$this->want_price( $created );
		$this->want_price( $waiting );

		foreach ( [ 'from_marketplace', 'off' ] as $direction ) {
			$this->set_account( [ 'price_direction' => $direction ] );

			$this->assertSame( 'Отправка цен сейчас выключена в настройках аккаунта.', $this->flush_now()->message() );
		}

		$this->assertSame( [], $this->prices->requests );

		$this->set_account( [ 'price_direction' => 'to_marketplace' ] );
		$this->flush_now();

		$this->assertSame( [ [ 'a-1' ] ], array_map( 'array_keys', $this->prices->requests ), 'a card that is not created waits' );
	}

	/**
	 * An account the plugin's write gate holds back sends no price and says so.
	 *
	 * @return void
	 */
	public function test_the_write_gate_holds_the_prices_back(): void {
		$this->want_price( $this->priced( 'a' ) );

		$this->write_gate = static function (): bool {
			return false;
		};
		$result           = $this->flush_now();

		$this->assertSame( [], $this->prices->requests );
		$this->assertStringContainsString( 'не готов к записи', $result->message() );
	}

	/**
	 * The half never acquires or releases the claim and never exceeds the budget it was given.
	 *
	 * @return void
	 */
	public function test_the_half_runs_under_the_callers_claim_and_within_its_budget(): void {
		$this->price_batch = 2;

		foreach ( [ 'a', 'b', 'c' ] as $offer ) {
			$this->want_price( $this->priced( $offer ) );
		}

		$token  = (string) $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );
		$runner = $this->price_runner( $this->prices );

		$this->assertSame( 0, $runner->flush_under_claim( $this->account_id, $token, 0 ), 'no budget, no request' );
		$this->assertSame( 1, $runner->flush_under_claim( $this->account_id, $token, 1 ), 'one request of the budget used' );
		$this->assertCount( 1, $this->prices->requests );
		$this->assertTrue( $this->accounts()->holds_claim( $this->account_id, $token ), 'the caller still holds its claim: the half never released it' );
		$this->assertNull( $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL ), 'and nobody else can take it' );
		$this->assertSame( 1, $runner->flush_under_claim( $this->account_id, $token, 5 ), 'the rest goes out in one more request' );
		$this->assertCount( 2, $this->prices->requests );
	}

	/**
	 * A sender that stalls past its claim while its request is in flight settles nothing; the current sender sends and settles.
	 *
	 * @return void
	 */
	public function test_a_sender_whose_claim_was_taken_over_settles_nothing(): void {
		$product = $this->priced( 'a' );
		$this->want_price( $product );

		$old                    = (string) $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );
		$other                  = '';
		$this->prices->script[] = function () use ( &$other ) {
			$this->now += 200; // The old sender stalls past its claim; a new sender takes the account.
			$other      = (string) $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );

			return null;
		};

		$result = new Flush_Result();
		$this->price_runner( $this->prices )->flush_under_claim( $this->account_id, $old, 10, null, $result );

		$row = $this->price_row( $product );
		$this->assertNotSame( '', $other, 'the new sender took the account' );
		$this->assertSame( 0, $result->sent(), 'a refused acknowledgement is not a sent price' );
		$this->assertNull( $row['sent_payload'], 'the stale acknowledgement settled nothing' );
		$this->assertSame( 1, (int) $row['in_flight_rev'], 'and left the snapshot to the current sender' );
		$this->assertNotSame( [], preg_grep( '/taken over/', $this->log ) );

		$this->price_runner( $this->prices )->flush_under_claim( $this->account_id, $other, 10 );

		$this->assertSame( 1, (int) $this->price_row( $product )['sent_rev'], 'the current sender sends it again and settles it' );
	}

	/**
	 * A sender whose claim is already gone prepares no batch and sends nothing.
	 *
	 * @return void
	 */
	public function test_a_stale_sender_does_not_prepare_a_batch(): void {
		$product = $this->priced( 'a' );
		$this->want_price( $product );

		$old        = (string) $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );
		$this->now += 200;
		$this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );

		$this->assertSame( 0, $this->price_runner( $this->prices )->flush_under_claim( $this->account_id, $old, 10 ) );
		$this->assertNull( $this->price_row( $product )['in_flight_rev'] );
		$this->assertSame( [], $this->prices->requests );
	}

	/**
	 * A sender that loses its claim between taking the snapshot and the request sends nothing.
	 *
	 * @return void
	 */
	public function test_a_claim_lost_while_preparing_the_batch_stops_the_request(): void {
		$product = $this->priced( 'a' );
		$this->want_price( $product );

		$token = (string) $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );

		// Right after the snapshot UPDATE the sender stalls and another run takes the account.
		$this->db->before_query_pattern = '/SET attempts = CASE WHEN in_flight_rev/';
		$this->db->before_query         = function (): void {
			$this->db->before_query_pattern = '/./';
			$this->db->before_query         = function (): void {
				$this->now += 200;
				$this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );
			};
		};

		$this->want_price( $this->priced( 'b' ) );

		$this->assertSame( 0, $this->price_runner( $this->prices )->flush_under_claim( $this->account_id, $token, 10 ) );
		$this->assertSame( [], $this->prices->requests, 'no request leaves without a fresh proof of ownership' );
	}

	/**
	 * A line the marketplace asks to wait for is not a failed attempt, and a new revision does not cancel the wait.
	 *
	 * @return void
	 */
	public function test_a_new_revision_does_not_cancel_an_item_throttle(): void {
		$product = $this->priced( 'a' );
		$this->want_price( $product, [ 'price' => '500.00' ] );

		$this->prices->script[] = function () use ( $product ): array {
			return $this->refused( $product, 'fake_limit', 'limit', 360 );
		};
		$result                 = $this->flush_now();

		$this->assertSame( 1, $result->waiting() );
		$this->assertSame( 0, (int) $this->price_row( $product )['attempts'], 'a throttle is not a failed attempt' );

		$this->want_price( $product, [ 'price' => '505.00' ] );
		$this->now += 60;
		$this->flush_now();
		$this->assertCount( 1, $this->prices->requests, 'an edit inside the throttle does not send' );

		$this->now += 400;
		$this->flush_now();

		$this->assertCount( 2, $this->prices->requests );
		$this->assertSame( '505.00', $this->prices->requests[1]['a'][0], 'the newest payload goes out when the deadline passes' );
	}

	/**
	 * A throttled call makes its pairs wait what the marketplace named, counts no failure and ends the run.
	 *
	 * @return void
	 */
	public function test_a_throttled_call_waits_and_ends_the_run(): void {
		$this->price_batch = 1;
		$first             = $this->priced( 'a' );
		$this->want_price( $first );
		$this->want_price( $this->priced( 'b' ) );

		$this->prices->script[] = static function (): void {
			throw Fake_Remote_Exception::of( Remote_Error::THROTTLE, 'slow down', 90 );
		};
		$result                 = $this->flush_now();

		$this->assertCount( 1, $this->prices->requests, 'the run stops after the throttled call' );
		$this->assertSame( 1, $result->waiting() );
		$this->assertSame( 0, (int) $this->price_row( $first )['attempts'] );

		$this->now += 89;
		$this->flush_now();
		$this->assertSame( [ [ 'b' ] ], array_map( 'array_keys', array_slice( $this->prices->requests, 1 ) ), 'the throttled pair still waits; the other goes out' );
	}

	/**
	 * Refused credentials pause the account and the pairs wait without a counted failure.
	 *
	 * @return void
	 */
	public function test_refused_credentials_pause_the_account(): void {
		$product = $this->priced( 'a' );
		$this->want_price( $product );

		$this->prices->script[] = static function (): void {
			throw Fake_Remote_Exception::of( Remote_Error::PAUSE_ACCOUNT, 'key revoked' );
		};
		$result                 = $this->flush_now();

		$this->assertSame( 1, $result->waiting() );
		$this->assertSame( [ $this->account_id ], $this->classifier->refused );
		$this->assertSame( 0, (int) $this->price_row( $product )['attempts'] );
		$this->assertGreaterThan( 0, $this->pause()->remaining( $this->account_now() ) );
	}

	/**
	 * A call held back before it left the site does not count against the run's request budget.
	 *
	 * @return void
	 */
	public function test_a_call_that_never_left_is_not_a_spent_request(): void {
		$this->want_price( $this->priced( 'a' ) );

		$this->prices->script[] = static function (): void {
			throw Fake_Remote_Exception::of( Remote_Error::THROTTLE, 'held back by the rate limiter', 30, false );
		};

		$token = (string) $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );

		$this->assertSame( 0, $this->price_runner( $this->prices )->flush_under_claim( $this->account_id, $token, 10 ) );
	}

	/**
	 * A price whose link was detached is refused with a sentence, and the rest of the batch still goes out.
	 *
	 * @return void
	 */
	public function test_a_price_of_a_vanished_link_is_refused(): void {
		$gone = $this->priced( 'gone' );
		$kept = $this->priced( 'kept' );
		$this->want_price( $gone );
		$this->want_price( $kept );

		// The card row is still `created`, so the pair is due, but it has no article any more.
		$this->db->query( 'UPDATE ' . \Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables::table( 'links' ) . ' SET offer_id = NULL WHERE link_id = ' . $this->link_id( $gone ) );

		$result = $this->flush_now();

		$this->assertSame( [ [ 'kept' ] ], array_map( 'array_keys', $this->prices->requests ) );
		$this->assertSame( [ 1, 1 ], [ $result->sent(), $result->failed() ] );
		$this->assertSame( 'Товар или его связь с карточкой маркетплейса больше не существуют.', $this->price_row( $gone )['last_error'] );
	}

	/**
	 * Asynchronous acceptance is not supported: nothing settles a price task later. A pending receipt therefore carries no
	 * answer the sender can use, and its pairs are counted as unanswered — never parked as «waiting» and sent again without end.
	 * The fifth such answer stops the retries of the revision; a changed payload is a new try.
	 *
	 * @return void
	 */
	public function test_a_pending_receipt_is_a_bounded_failure_not_an_endless_resend(): void {
		$product = $this->priced( 'a' );
		$this->want_price( $product );

		$pending = function (): Push_Receipt {
			return Push_Receipt::pending( new Async_Task( $this->account_id, Async_Task::KIND_PRICE, 'price-task-1', Async_Task::STATUS_POLLING, 1, new \DateTimeImmutable( '@' . $this->now ) ) );
		};

		$this->prices->script[] = $pending;
		$first                  = $this->flush_now();
		$row                    = $this->price_row( $product );

		$this->assertSame( [ 0, 1, 0 ], [ $first->sent(), $first->failed(), $first->waiting() ], 'not sent, not waiting: refused' );
		$this->assertSame( 1, (int) $row['attempts'] );
		$this->assertSame( 'Маркетплейс не вернул ответ по этому товару.', $row['last_error'] );
		$this->assertNotSame( [], preg_grep( '/pending receipt, which this sender does not settle/', $this->log ) );

		// The run the critic's probe made: one price interval later the pair is due again and the receipt is pending again.
		for ( $run = 0; $run < 8; $run++ ) {
			$this->now           += 4000;
			$this->prices->script = [ $pending ];
			$this->flush_now();
		}

		$row = $this->price_row( $product );

		$this->assertCount( 5, $this->prices->requests, 'the attempt cap ends the re-submission' );
		$this->assertSame( 5, (int) $row['attempts'] );
		$this->assertNull( $row['sent_payload'], 'nothing was ever reported as accepted' );

		$this->prices->script = [];
		$this->want_price( $product, [ 'price' => '120.00' ] );
		$this->flush_now();

		$this->assertCount( 6, $this->prices->requests, 'a changed payload is a new try' );
	}

	/**
	 * An error the adapter does not know is not a marketplace answer: it leaves the half as it is.
	 *
	 * @return void
	 */
	public function test_an_unknown_error_is_not_swallowed(): void {
		$this->want_price( $this->priced( 'a' ) );

		$this->prices->script[] = static function (): void {
			throw new \LogicException( 'a bug in the pusher' );
		};

		$this->expectException( \LogicException::class );
		$this->flush_now();
	}

	/**
	 * Creates a product with a created card and price sync on.
	 *
	 * @param string              $offer   Article.
	 * @param array<string,mixed> $options Link options.
	 * @return int Product id.
	 */
	private function priced( string $offer, array $options = [] ): int {
		static $remote_id = 5000;

		return $this->stock_product( $offer, ++$remote_id, 10, [ 'sync_price' => true ] + $options );
	}

	/**
	 * A script entry in which the marketplace refuses one product's price.
	 *
	 * @param int    $product_id Product id.
	 * @param string $message    The marketplace's text.
	 * @return callable
	 */
	private function refusal( int $product_id, string $message ): callable {
		return function () use ( $product_id, $message ): array {
			return $this->refused( $product_id, 'fake_refused', $message );
		};
	}

	/**
	 * Plays the outer sender of `flush_sync`: takes the account claim, runs the price half under it, releases the claim.
	 *
	 * @param int $budget Requests the half may use.
	 * @return Flush_Result
	 */
	private function flush_now( int $budget = 10 ): Flush_Result {
		$result = new Flush_Result();
		$token  = $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );

		$this->assertNotNull( $token, 'the outer sender must get the account claim' );

		try {
			$this->price_runner( $this->prices )->flush_under_claim( $this->account_id, (string) $token, $budget, null, $result );
		} finally {
			$this->accounts()->release_claim( $this->account_id, (string) $token );
		}

		return $result;
	}
}
