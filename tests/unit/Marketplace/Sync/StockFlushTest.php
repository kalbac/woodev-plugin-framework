<?php
/**
 * The stock flush, the archive and the single sender of an account.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Sync;

use Brain\Monkey\Filters;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Jobs\Account_Run;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\Remote_Error;
use Woodev\Framework\Marketplace\Ledger\Item_Result;
use Woodev\Framework\Marketplace\Stock\Stock_Policy;
use Woodev\Framework\Marketplace\Sync\Stock_Flusher;
use Woodev\Framework\Marketplace\Sync\Stock_Intents;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Remote_Exception;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Scripted_Price_Pusher;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Scripted_Stock_Pusher;
use Woodev\Tests\Unit\Marketplace\Support\Sync_Test_Case;

/**
 * Moved from the Ozon plugin's `StockFlushTest`: the flush, the archive, one sender per account, and the price half that runs
 * under the same claim with what is left of the run's budget.
 */
final class StockFlushTest extends Sync_Test_Case {
	/**
	 * A stock change becomes one request with the article and the quantity; once the marketplace accepted it there is nothing to send.
	 *
	 * @return void
	 */
	public function test_a_stock_change_is_sent_once(): void {
		$product = $this->stock_product( 'simple', 101 );
		$this->hook_stock();
		$this->set_stock( $product, 7 );

		$result = $this->flush();

		$this->assertSame( 1, $result->sent );
		$this->assertSame( 'Отправлено: 1', $result->message() );
		$this->assertSame( [ [ Scripted_Stock_Pusher::PUSH, self::WAREHOUSE, [ 'simple' => 7 ] ] ], $this->market->requests );

		$row = $this->stock_row( $product );
		$this->assertSame( [ 'stock' => 7 ], $row['sent_payload'] );
		$this->assertSame( 7, $this->last_pushed_stock( $product ) );

		$again = $this->flush();
		$this->assertSame( 0, $again->requests );
		$this->assertSame( 'Нечего отправлять (значения совпадают)', $again->message() );
	}

	/**
	 * Each verdict of a line settles its pair: accepted acknowledges, «not ready» waits five minutes with the text and counts no
	 * failure, «wait» waits 31 s (or what the marketplace named) with no text and counts no failure.
	 *
	 * @return void
	 */
	public function test_the_answers_settle_each_pair(): void {
		$red   = $this->stock_product( 'red', 201 );
		$black = $this->stock_product( 'black', 202 );
		$nope  = $this->stock_product( 'nope', 203 );
		$this->hook_stock();

		$this->set_stock( $red, 4 );
		$this->set_stock( $black, 6 );
		$this->set_stock( $nope, 1 );

		$this->market->script[] = function (): array {
			return $this->accepted( $this->product_of( 'red' ), $this->product_of( 'black' ) ) + $this->refused( $this->product_of( 'nope' ), Scripted_Stock_Pusher::CODE_UNKNOWN, 'card is being created' );
		};
		$result                 = $this->flush();

		$this->assertSame( 2, $result->sent );
		$this->assertSame( 1, $result->waiting );
		$this->assertSame( [ 'stock' => 4 ], $this->stock_row( $red )['sent_payload'] );
		$this->assertSame( [ 'stock' => 6 ], $this->stock_row( $black )['sent_payload'] );

		$unknown = $this->stock_row( $nope );
		$this->assertNull( $unknown['sent_payload'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $this->now + 300 ), $unknown['next_attempt_at'] );
		$this->assertSame( 0, (int) $unknown['attempts'] );
		$this->assertSame( Scripted_Stock_Pusher::CODE_UNKNOWN . ': card is being created', $unknown['last_error'] );

		// The throttled answer: 31 s, no failure counted, the value stays pending.
		$this->set_stock( $red, 11 );
		$this->market->script[] = function (): array {
			return $this->refused( $this->product_of( 'red' ), Scripted_Stock_Pusher::CODE_SLOW_DOWN, 'too fast' );
		};
		$this->flush();

		$throttled = $this->stock_row( $red );
		$this->assertSame( [ 'stock' => 11 ], $throttled['pending_payload'] );
		$this->assertSame( [ 'stock' => 4 ], $throttled['sent_payload'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $this->now + 31 ), $throttled['next_attempt_at'] );
		$this->assertSame( 0, (int) $throttled['attempts'] );
		$this->assertSame( '', (string) $throttled['last_error'] );

		$this->now += 31;
		$this->assertSame( 1, $this->flush()->sent );
		$this->assertSame( [ 'stock' => 11 ], $this->stock_row( $red )['sent_payload'] );
	}

	/**
	 * The wait a throttled line names is the wait the pair gets.
	 *
	 * @return void
	 */
	public function test_a_throttled_line_waits_what_the_marketplace_named(): void {
		$product = $this->stock_product( 'named', 251 );
		$this->hook_stock();
		$this->set_stock( $product, 3 );

		$this->market->script[] = function () use ( $product ): array {
			return $this->refused( $product, Scripted_Stock_Pusher::CODE_SLOW_DOWN, 'too fast', 75 );
		};
		$result                 = $this->flush();

		$this->assertSame( 1, $result->waiting );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $this->now + 75 ), $this->stock_row( $product )['next_attempt_at'] );
	}

	/**
	 * The flush job takes the account claim; while another run holds it, a second instance exits without a request.
	 *
	 * @return void
	 */
	public function test_a_second_sender_of_the_account_exits(): void {
		$product = $this->stock_product( 'single', 301 );
		$this->hook_stock();
		$this->set_stock( $product, 5 );

		$other = $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );
		$this->assertNotNull( $other );
		$this->assertNull( $this->flush_runner()->run( $this->account_id ) );
		$this->assertSame( [], $this->market->requests );

		$this->accounts()->release_claim( $this->account_id, (string) $other );
		$result = $this->flush_runner()->run( $this->account_id );

		$this->assertSame( 1, $result->sent );
		$this->assertCount( 1, $this->market->requests );
		$this->assertSame( [], $this->log );
	}

	/**
	 * A save while the request is in flight bumps the pending revision; the answer settles only the in-flight one, so the newer
	 * value goes out on the next run.
	 *
	 * @return void
	 */
	public function test_a_save_during_a_send_keeps_its_newer_revision(): void {
		$product = $this->stock_product( 'during', 401 );
		$this->hook_stock();
		$this->set_stock( $product, 8 );

		$this->market->script[] = function () use ( $product ) {
			$this->set_stock( $product, 6 );

			return null;
		};
		$this->flush();

		$row = $this->stock_row( $product );
		$this->assertSame( [ 'stock' => 8 ], $row['sent_payload'] );
		$this->assertSame( 1, (int) $row['sent_rev'] );
		$this->assertSame( [ 'stock' => 6 ], $row['pending_payload'] );
		$this->assertSame( 2, (int) $row['pending_rev'] );

		$this->flush();
		$this->assertSame( [ 'during' => 6 ], $this->market->sent( Scripted_Stock_Pusher::PUSH )[1] );
		$this->assertSame( [ 'stock' => 6 ], $this->stock_row( $product )['sent_payload'] );
	}

	/**
	 * Revision 1 times out; the shop saves revision 2; the next request is revision 2 and nothing else is sent.
	 *
	 * @return void
	 */
	public function test_an_uncertain_send_is_followed_by_the_newest_payload_only(): void {
		$product = $this->stock_product( 'uncertain', 501 );
		$this->hook_stock();
		$this->set_stock( $product, 9 );

		$this->market->script[] = static function (): void {
			throw Fake_Remote_Exception::of( Remote_Error::RETRY, 'Operation timed out after 15001 milliseconds' );
		};
		$first                  = $this->flush();
		$this->assertSame( 0, $first->sent );

		$row = $this->stock_row( $product );
		$this->assertSame( [ 'stock' => 9 ], $row['in_flight_payload'], 'the uncertain snapshot stays' );
		$this->assertNull( $row['sent_payload'] );
		$this->assertSame( 1, (int) $row['attempts'] );

		$this->set_stock( $product, 4 );
		$this->assertSame( 0, $this->flush()->requests, 'the pair backs off for a minute' );

		$this->now += 60;
		$this->flush();
		$this->flush();

		$this->assertSame( [ [ 'uncertain' => 9 ], [ 'uncertain' => 4 ] ], $this->market->sent( Scripted_Stock_Pusher::PUSH ), 'revision 2 is the next and only request' );
		$this->assertSame( 2, (int) $this->stock_row( $product )['sent_rev'] );
	}

	/**
	 * A line the marketplace calls archived: the link becomes archived and the pending value is dropped with the reason; nothing
	 * is sent again.
	 *
	 * @return void
	 */
	public function test_an_archived_card_drops_its_pending_value(): void {
		$product = $this->stock_product( 'gone', 601 );
		$this->hook_stock();
		$this->set_stock( $product, 3 );

		$this->market->script[] = function () use ( $product ): array {
			return $this->refused( $product, Scripted_Stock_Pusher::CODE_SHELVED, 'Product is archived' );
		};
		$result                 = $this->flush();

		$this->assertSame( [ 'Product is archived' ], $result->errors );
		$this->assertSame( Link_Record::STATE_ARCHIVED, $this->link_of( $product )->card_state() );

		$row = $this->stock_row( $product );
		$this->assertNull( $row['pending_payload'] );
		$this->assertStringContainsString( Scripted_Stock_Pusher::CODE_SHELVED, (string) $row['last_error'] );

		$this->set_stock( $product, 2 );
		$this->flush();
		$this->assertCount( 1, $this->market->sent( Scripted_Stock_Pusher::PUSH ) );
	}

	/**
	 * A line the marketplace left out of its answer is a counted failure with the framework's own code.
	 *
	 * @return void
	 */
	public function test_a_line_without_an_answer_is_a_counted_failure(): void {
		$product = $this->stock_product( 'silent', 651 );
		$this->hook_stock();
		$this->set_stock( $product, 3 );

		$this->market->script[] = static function (): array {
			return [];
		};
		$result                 = $this->flush();

		$row = $this->stock_row( $product );
		$this->assertSame( 0, $result->sent );
		$this->assertSame( 1, (int) $row['attempts'] );
		$this->assertStringStartsWith( Stock_Flusher::CODE_NO_ANSWER . ': ', (string) $row['last_error'] );
		$this->assertSame( [ 'Маркетплейс не ответил по этому товару.' ], $result->errors );
	}

	/**
	 * Warehouse resolution: link override, else the account default; an oversize product without its own warehouse and an account
	 * without a default get nothing recorded and nothing sent («Не выбран склад»).
	 *
	 * @return void
	 */
	public function test_a_pair_without_a_warehouse_sends_nothing(): void {
		$own      = $this->stock_product( 'own', 701, 10, [ 'warehouse_id' => 99 ] );
		$oversize = $this->stock_product( 'oversize', 702, 10, [ 'oversize' => true ] );
		$this->hook_stock();

		$this->set_stock( $own, 1 );
		$this->set_stock( $oversize, 1 );

		$this->assertSame( [ 'stock' => 1 ], $this->stock_row( $own, 99 )['pending_payload'] );
		$this->assertNull( $this->stock_row( $oversize ) );

		$this->set_account( [ 'warehouse_id' => 0 ] );
		$plain = $this->stock_product( 'plain', 703 );
		$this->set_stock( $plain, 1 );

		$this->assertNull( $this->stock_row( $plain ) );
		$this->assertNull( $this->stock_row( $plain, 0 ) );
	}

	/**
	 * `marketplace` mode: nothing is pushed. A card not created yet waits.
	 *
	 * @return void
	 */
	public function test_marketplace_mode_and_uncreated_cards_send_nothing(): void {
		$later = $this->stock_product( 'later', 801, 10, [ 'state' => Link_Record::STATE_SUBMITTED ] );
		$this->hook_stock();
		$this->set_stock( $later, 2 );

		$this->assertSame( 1, $this->flush()->waiting );
		$this->assertSame( [], $this->market->requests );

		$this->set_account( [ 'stock_mode' => 'marketplace' ] );
		$mirror = $this->stock_product( 'mirror', 802 );
		$this->set_stock( $mirror, 3 );

		$this->assertNull( $this->stock_row( $mirror ) );
	}

	/**
	 * The formula is the adapter's: plain WC stock sends the stock, the reservation formula adds the last read reservation.
	 *
	 * @return void
	 */
	public function test_the_pushed_quantity_follows_the_adapters_formula(): void {
		$product                           = $this->stock_product( 'formula', 901 );
		$this->market->reserved['formula'] = 2;
		$this->hook_stock();

		$this->set_stock( $product, 7 );
		$this->flush();
		$this->reconcile(); // Stores reserved = 2 on the pair.
		$this->set_stock( $product, 7 );

		$this->assertSame( [ 'stock' => 7 ], $this->stock_row( $product )['pending_payload'], 'plain WC stock in force' );

		$intents = new Stock_Intents( [ $this->accounts(), 'get' ], $this->links(), $this->intents(), $this->queue(), new Stock_Policy( Stock_Policy::FORMULA_PLUS_RESERVED ) );
		$intents->record_stock( $this->link_of( $product ) );

		$this->assertSame( [ 'stock' => 9 ], $this->stock_row( $product )['pending_payload'], 'with the reservation: 7 + 2 reserved' );
	}

	/**
	 * A trashed product with a created card sends 0, then archives the card once the marketplace accepted the zero.
	 *
	 * @return void
	 */
	public function test_a_trashed_product_is_zeroed_then_archived(): void {
		$product = $this->stock_product( 'trash', 1001, 5 );
		$this->hook_stock();
		$this->trash( $product );

		$this->assertSame(
			[
				'stock'   => 0,
				'archive' => true,
			],
			$this->stock_row( $product )['pending_payload']
		);

		$result = $this->flush();

		$this->assertSame( [ [ 'trash' => 0 ] ], $this->market->sent( Scripted_Stock_Pusher::PUSH ) );
		$this->assertSame( [ [ 'trash' ] ], $this->market->sent( Scripted_Stock_Pusher::ARCHIVE ) );
		$this->assertSame( 1, $result->archived );
		$this->assertSame( Link_Record::STATE_ARCHIVED, $this->link_of( $product )->card_state() );
		$this->assertSame( 0, $this->flush()->requests );
	}

	/**
	 * Taking the product out of the trash before the flush records its stock again, which cancels the archive.
	 *
	 * @return void
	 */
	public function test_a_restored_product_cancels_the_archive_not_yet_done(): void {
		$product = $this->stock_product( 'back', 1051, 5 );
		$this->hook_stock();

		$this->trash( $product );
		$this->untrash( $product );

		$this->assertSame( [ 'stock' => 5 ], $this->stock_row( $product )['pending_payload'] );

		$this->flush();

		$this->assertSame( [], $this->market->sent( Scripted_Stock_Pusher::ARCHIVE ) );
		$this->assertSame( Link_Record::STATE_CREATED, $this->link_of( $product )->card_state() );
	}

	/**
	 * A refused archive makes the pair due again; the archive is retried and succeeds.
	 *
	 * @return void
	 */
	public function test_a_refused_archive_is_retried(): void {
		$product = $this->stock_product( 'refused', 1101, 5 );
		$this->hook_stock();
		$this->trash( $product );

		$this->market->script[] = static function () {
			return null;
		};
		$this->market->script[] = static function (): bool {
			return false;
		};
		$this->flush();

		$this->assertSame( Link_Record::STATE_CREATED, $this->link_of( $product )->card_state() );

		$this->now += 60;
		$this->flush();

		$this->assertCount( 2, $this->market->sent( Scripted_Stock_Pusher::ARCHIVE ) );
		$this->assertSame( Link_Record::STATE_ARCHIVED, $this->link_of( $product )->card_state() );
	}

	/**
	 * The real price half runs inside this runner's claim, after stock, with what is left of the request budget: with a budget of
	 * one the stock request spends it and the price waits; with two, both go out in the same run, and the price request leaves
	 * while the account claim is held.
	 *
	 * @return void
	 */
	public function test_the_price_half_runs_under_the_claim_with_the_remaining_budget(): void {
		$product = $this->stock_product( 'priced', 1401, 10, [ 'sync_price' => true ] );
		$this->hook_stock();
		$this->set_stock( $product, 4 );
		$this->want_price( $product );

		$held                  = [];
		$pusher                = new Scripted_Price_Pusher();
		$pusher->before_answer = function () use ( &$held ): void {
			$held[] = null === $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );
		};
		$prices                = $this->price_runner( $pusher );

		$first = $this->flush_runner( [ $prices, 'flush_under_claim' ], 1 )->run( $this->account_id );

		$this->assertSame( 1, $first->requests );
		$this->assertSame( 0, $first->price_requests, 'the stock request spent the budget' );
		$this->assertSame( [], $pusher->requests );

		$this->set_stock( $product, 3 );
		$second = $this->flush_runner( [ $prices, 'flush_under_claim' ], 2 )->run( $this->account_id );

		$this->assertSame( 1, $second->requests );
		$this->assertSame( 1, $second->price_requests );
		$this->assertCount( 1, $pusher->requests );
		$this->assertSame( [ true ], $held, 'the price request left while the run held the account claim' );
		$this->assertNotNull( $this->price_row( $product )['sent_payload'] );
		$this->assertSame( [], $this->log );
	}

	/**
	 * The run's 20 s are shared by stock and price and enforced per request. Three price intents come due one after another and
	 * every answer takes 11 s: the requests start at 0 and 11 s, and none starts at 22 s.
	 *
	 * @return void
	 */
	public function test_the_price_half_starts_no_request_after_the_run_deadline(): void {
		$products = [];

		foreach ( [ 1, 2, 3 ] as $number ) {
			$products[] = $this->stock_product( 'p' . $number, 1600 + $number, 10, [ 'sync_price' => true ] );
		}

		$this->want_price( $products[0] );

		$starts = [];
		$begin  = $this->now;
		$pusher = new Scripted_Price_Pusher();

		foreach ( [ 0, 1, 2 ] as $index ) {
			$pusher->script[] = function () use ( $index, $products, &$starts, $begin ) {
				$starts[]   = $this->now - $begin;
				$this->now += 11;

				if ( $index < 2 ) {
					$this->want_price( $products[ $index + 1 ] );
				}

				return null;
			};
		}

		$result = $this->flush_runner( [ $this->price_runner( $pusher ), 'flush_under_claim' ], 10 )->run( $this->account_id );

		$this->assertNotNull( $result );
		$this->assertSame( [ 0, 11 ], $starts, 'no price request starts at or after the run\'s 20 s' );
		$this->assertSame( 2, $result->price_requests );
	}

	/**
	 * The batch preparation (here its snapshot UPDATE) uses up the run's time after the early check, so no price request may start.
	 *
	 * @return void
	 */
	public function test_the_price_half_starts_no_request_when_batch_preparation_used_the_deadline(): void {
		$product = $this->stock_product( 'slowdb', 1704, 10, [ 'sync_price' => true ] );
		$this->want_price( $product );

		$advanced                       = false;
		$this->db->before_query_pattern = '/SET attempts = CASE WHEN in_flight_rev/';
		$this->db->before_query         = function () use ( &$advanced ): void {
			$this->now += 21;
			$advanced   = true;
		};

		$pusher = new Scripted_Price_Pusher();
		$this->flush_runner( [ $this->price_runner( $pusher ), 'flush_under_claim' ], 10 )->run( $this->account_id );

		$this->assertTrue( $advanced, 'the snapshot UPDATE ran' );
		$this->assertSame( [], $pusher->requests, 'the snapshot work had already used the run\'s 20 s' );
	}

	/**
	 * The price half is not entered at all when less of the run is left than one marketplace request may take.
	 *
	 * @return void
	 */
	public function test_the_price_half_waits_when_less_than_one_request_of_time_is_left(): void {
		$product = $this->stock_product( 'late', 1751 );
		$this->hook_stock();
		$this->set_stock( $product, 4 );

		// The stock answer takes 6 s: 14 s of the run's 20 are left, one request may take 15.
		$this->market->script[] = function () {
			$this->now += 6;

			return null;
		};

		$called = false;
		$result = $this->flush_runner(
			static function () use ( &$called ): int {
				$called = true;

				return 0;
			}
		)->run( $this->account_id );

		$this->assertSame( 1, $result->sent );
		$this->assertFalse( $called );
	}

	/**
	 * One `flush_sync` run is one sender for stock and price. The price half runs under the same claim, after stock, with what is
	 * left of the 10-request budget, and the run never sends more than the budget.
	 *
	 * @return void
	 */
	public function test_the_price_half_gets_the_same_claim_and_the_remaining_budget(): void {
		$here  = $this->stock_product( 'here', 1201 );
		$there = $this->stock_product( 'there', 1202, 10, [ 'warehouse_id' => 99 ] );
		$this->hook_stock();
		$this->set_stock( $here, 4 );
		$this->set_stock( $there, 5 );

		$calls  = [];
		$result = $this->flush_runner(
			function ( int $account_id, string $token, int $remaining, Account_Run $run ) use ( &$calls ): int {
				$calls[] = [ $account_id, $remaining, $this->accounts()->holds_claim( $account_id, $token ), $run->token() === $token ];

				return 3;
			}
		)->run( $this->account_id );

		$this->assertSame( 2, $result->requests, 'one stock request per warehouse' );
		$this->assertSame( [ [ $this->account_id, 8, true, true ] ], $calls, 'the price half gets the held claim and 10 − 2 requests' );
		$this->assertSame( 3, $result->price_requests );
		$this->assertLessThanOrEqual( Stock_Flusher::MAX_REQUESTS, $result->requests + $result->price_requests );
		$this->assertNotNull( $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL ), 'the claim is released after both halves' );
	}

	/**
	 * When stock uses the whole budget the price half is not called, and stock itself stops at the budget.
	 *
	 * @return void
	 */
	public function test_a_spent_budget_leaves_nothing_for_prices(): void {
		$here  = $this->stock_product( 'here', 1301 );
		$there = $this->stock_product( 'there', 1302, 10, [ 'warehouse_id' => 99 ] );
		$this->hook_stock();
		$this->set_stock( $here, 4 );
		$this->set_stock( $there, 5 );

		$called = false;
		$result = $this->flush_runner(
			static function () use ( &$called ): int {
				$called = true;

				return 0;
			},
			1
		)->run( $this->account_id );

		$this->assertSame( 1, $result->requests );
		$this->assertCount( 1, $this->market->sent( Scripted_Stock_Pusher::PUSH ) );
		$this->assertFalse( $called );
	}

	/**
	 * A price half that reports more requests than it was given is logged.
	 *
	 * @return void
	 */
	public function test_a_price_half_that_overspends_is_logged(): void {
		$result = $this->flush_runner(
			static function (): int {
				return 4;
			},
			3
		)->run( $this->account_id );

		$this->assertSame( 4, $result->price_requests );
		$this->assertCount( 1, $this->log );
		$this->assertStringContainsString( 'the price half sent 4 requests, 3 were left', $this->log[0] );
	}

	/**
	 * Without an injected price half the runner asks the plugin's filter.
	 *
	 * @return void
	 */
	public function test_the_price_half_can_come_from_the_filter(): void {
		$seen = [];

		Filters\expectApplied( 'woodev_fake_seller_flush_sync_price_half' )->once()->andReturn(
			static function ( int $account_id, string $token, int $remaining ) use ( &$seen ): int {
				$seen[] = [ $account_id, strlen( $token ), $remaining ];

				return 1;
			}
		);

		$result = $this->flush_runner()->run( $this->account_id );

		$this->assertSame( [ [ $this->account_id, 32, Stock_Flusher::MAX_REQUESTS ] ], $seen, 'nothing due for stock: the whole budget' );
		$this->assertSame( 1, $result->price_requests );
	}

	/**
	 * A lapsed licence, a paused account and the plugin's write gate each end the run before the claim is taken: no request leaves.
	 *
	 * @return void
	 */
	public function test_the_gates_stop_the_run_before_any_request(): void {
		$product = $this->stock_product( 'gated', 1801 );
		$this->hook_stock();
		$this->set_stock( $product, 2 );

		$this->licensed = false;
		$this->assertNull( $this->flush_runner()->run( $this->account_id ) );

		$this->licensed   = true;
		$this->write_gate = static function (): bool {
			return false;
		};
		$this->assertNull( $this->flush_runner()->run( $this->account_id ) );

		$this->write_gate = null;
		$this->accounts()->set_enabled( $this->account_id, false );
		$this->assertNull( $this->flush_runner()->run( $this->account_id ) );

		$this->assertSame( [], $this->market->requests );
		$this->assertNotNull( $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL ), 'no gated run took the claim' );
	}

	/**
	 * Every enabled account gets its recurring flush in the plugin's group, and the check is not repeated within the hour.
	 *
	 * @return void
	 */
	public function test_every_enabled_account_gets_its_recurring_flush(): void {
		$transients = [];

		\Brain\Monkey\Functions\when( 'get_transient' )->alias(
			static function ( string $key ) use ( &$transients ) {
				return $transients[ $key ] ?? false;
			}
		);
		\Brain\Monkey\Functions\when( 'set_transient' )->alias(
			static function ( string $key, $value ) use ( &$transients ): bool {
				$transients[ $key ] = $value;

				return true;
			}
		);

		$this->flush_runner()->ensure_schedules();
		$this->flush_runner()->ensure_schedules();

		$this->assertSame( [ 'recurring:woodev_fake_seller_flush_sync:pending' ], $this->scheduler->summary() );
		$this->assertSame( [ $this->account_id ], $this->scheduler->actions[0]['args'] );
		$this->assertSame( 'woodev-fake-seller', $this->scheduler->actions[0]['group'] );
		$this->assertSame( [ 'woodev_fake_seller_flush_check' ], array_keys( $transients ) );
	}

	/**
	 * Finds a product of this test by its article.
	 *
	 * @param string $offer Article.
	 * @return int
	 */
	private function product_of( string $offer ): int {
		return $this->links()->find_by_offer_id( $this->account_id, $offer )->wc_product_id();
	}

	/**
	 * Reads a link's `last_pushed_stock`.
	 *
	 * @param int $product_id Product id.
	 * @return int
	 */
	private function last_pushed_stock( int $product_id ): int {
		return (int) $this->db->get_var( 'SELECT last_pushed_stock FROM ' . Sqlite_Tables::table( 'links' ) . ' WHERE link_id = ' . $this->link_id( $product_id ) );
	}
}
