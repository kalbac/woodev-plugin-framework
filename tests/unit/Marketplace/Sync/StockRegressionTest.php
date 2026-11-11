<?php
/**
 * The stock flow's regressions: crashes, lost claims, warehouse moves and failure counts.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Sync;

use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Jobs\Claim_Lost;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\Remote_Error;
use Woodev\Framework\Marketplace\Orders\Status_Map;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Remote_Exception;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Scripted_Stock_Pusher;
use Woodev\Tests\Unit\Marketplace\Support\Sync_Test_Case;

/**
 * Moved from the Ozon plugin's `StockCriticRegressionTest`: one test per defect a critic found in the stock flow, each of which
 * failed on the commit it was reported on.
 */
final class StockRegressionTest extends Sync_Test_Case {
	/**
	 * An SQL error on the ledger write rolls the line's stock write back, so the retry subtracts once.
	 *
	 * @return void
	 */
	public function test_a_failed_ledger_write_rolls_the_stock_back(): void {
		$product  = $this->stock_product( 'a', 1001 );
		$postings = Sqlite_Tables::table( 'postings' );
		$failed   = false;

		// The table disappears right before the `qty_applied` write, inside the line's transaction: the statement fails for real.
		$this->db->before_query_pattern = '/SET `lines` = /';
		$this->db->before_query         = function () use ( &$failed, $postings ): void {
			$failed = true;
			$this->db->define( 'ALTER TABLE ' . $postings . ' RENAME TO postings_gone' );
		};

		try {
			$this->accounting()->account( $this->sale( 'ledger-error' ), $this->account_now() );
			$this->fail( 'The ledger write must fail.' );
		} catch ( \RuntimeException $e ) {
			unset( $e ); // The failure is expected; the state after it is what matters.
		}

		$this->assertTrue( $failed, 'one ledger write failed' );
		$this->assertSame( 10, $this->wc_stock( $product ), 'the stock write went back with the failed ledger write' );

		$this->now += Job_Gate::CLAIM_TTL + 1;
		$this->accounting()->account( $this->sale( 'ledger-error' ), $this->account_now() );

		$this->assertSame( 9, $this->wc_stock( $product ), 'the retry subtracts once' );
	}

	/**
	 * A death between recording an order from before the takeover and marking its baseline must not turn the retry into a sale.
	 *
	 * @return void
	 */
	public function test_an_interrupted_baseline_is_completed_by_the_retry(): void {
		$product = $this->stock_product( 'a', 1001, 9 );
		$this->set_account(
			[
				'orders_enabled' => false,
				'takeover_at'    => $this->now - 3600,
			]
		);

		$order = $this->sale( 'baseline-crash', Status_Map::AWAITING_SHIPMENT, [ 'created_at' => gmdate( 'c', $this->now - 7200 ) ] );
		$this->die_at( '/SET baseline = 1/', 'death before baseline' );

		try {
			$this->accounting()->account( $order, $this->account_now() );
			$this->fail( 'The run must die before the baseline.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'death before baseline', $e->getMessage() );
		}

		$this->now += Job_Gate::CLAIM_TTL + 1;
		$this->accounting()->account( $order, $this->account_now() );

		$this->assertSame( 9, $this->wc_stock( $product ), 'a sale the earlier generation already took does not decrement' );
		$this->assertTrue( $this->registry()->find( $this->account_id, 'baseline-crash' )['baseline'] );
	}

	/**
	 * Send 5, then 7 times out after the marketplace applied it, then the shop goes back to 5: the next request is 5.
	 *
	 * @return void
	 */
	public function test_an_uncertain_send_is_followed_by_the_newest_value_even_when_it_was_sent_before(): void {
		$product = $this->stock_product( 'a', 1001 );
		$this->hook_stock();

		$this->set_stock( $product, 5 );
		$this->flush();

		$this->now += 31;
		$this->set_stock( $product, 7 );
		$this->market->script[] = function (): void {
			$this->market->present['a'] = 7;

			throw Fake_Remote_Exception::of( Remote_Error::RETRY, 'timeout' );
		};
		$this->flush();

		$this->set_stock( $product, 5 );
		$this->now += 60;
		$this->flush();

		$this->assertSame( 5, $this->market->present['a'], 'the newest absolute value follows the uncertain request' );
	}

	/**
	 * A death right after the marketplace accepted the zero must not strand the archive; the retry archives the card.
	 *
	 * @return void
	 */
	public function test_a_death_after_the_zero_still_archives_on_the_retry(): void {
		$product = $this->stock_product( 'a', 1001, 5 );
		$this->hook_stock();
		$this->trash( $product );

		$this->die_after( '/SET sent_payload =/', 'death after the acknowledgement' );

		try {
			$this->flush();
			$this->fail( 'The run must die after the acknowledgement.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'death after the acknowledgement', $e->getMessage() );
		}

		$this->assertSame( [], $this->market->sent( Scripted_Stock_Pusher::ARCHIVE ) );

		$this->now += Job_Gate::CLAIM_TTL + 1;
		$this->flush();

		$this->assertCount( 1, $this->market->sent( Scripted_Stock_Pusher::ARCHIVE ), 'the retry finishes the archive' );
		$this->assertCount( 1, $this->market->sent( Scripted_Stock_Pusher::PUSH ), 'without sending the zero again' );
		$this->assertSame( Link_Record::STATE_ARCHIVED, $this->link_of( $product )->card_state() );
	}

	/**
	 * A sender whose claim was taken over while its zero was in flight stops; it does not archive.
	 *
	 * @return void
	 */
	public function test_a_sender_that_lost_its_claim_does_not_archive(): void {
		$product = $this->stock_product( 'a', 1001, 5 );
		$this->hook_stock();
		$this->trash( $product );

		$this->market->script[] = function () {
			$this->now += Job_Gate::CLAIM_TTL + 1;
			$this->assertNotNull( $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL ) );

			return null;
		};
		$result                 = $this->flush();

		$this->assertTrue( $result->claim_lost );
		$this->assertCount( 0, $this->market->sent( Scripted_Stock_Pusher::ARCHIVE ), 'the stale sender stops' );
		$this->assertSame( Link_Record::STATE_CREATED, $this->link_of( $product )->card_state() );
		$this->assertNull( $this->stock_row( $product )['sent_payload'], 'and settles nothing' );
	}

	/**
	 * A readback whose claim was taken over during the read does not write WooCommerce stock.
	 *
	 * @return void
	 */
	public function test_a_readback_that_lost_its_claim_does_not_mirror(): void {
		$product = $this->stock_product( 'a', 1001, 10 );
		$this->set_account( [ 'stock_mode' => 'marketplace' ] );
		$this->market->present['a'] = 9;

		$this->market->script[] = function () {
			$this->now += Job_Gate::CLAIM_TTL + 1;
			$this->assertNotNull( $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL ) );

			return null;
		};

		try {
			$this->reconcile();
			$this->fail( 'The readback must report the lost claim.' );
		} catch ( Claim_Lost $e ) {
			unset( $e ); // Reported as a lost claim.
		}

		$this->assertSame( 10, $this->wc_stock( $product ), 'the stale readback does not write WC' );
	}

	/**
	 * An older intent for a warehouse that is no longer selected is not sent.
	 *
	 * @return void
	 */
	public function test_a_pending_pair_follows_the_current_warehouse(): void {
		$product = $this->stock_product( 'a', 1001 );
		$this->hook_stock();
		$this->set_stock( $product, 4 );

		$this->set_account( [ 'warehouse_id' => 0 ] );
		$this->flush();

		$this->assertCount( 0, $this->market->sent( Scripted_Stock_Pusher::PUSH ), 'no warehouse means no send' );
		$this->assertSame( 'Не выбран склад.', $this->stock_row( $product )['last_error'] );

		$this->set_account( [ 'warehouse_id' => 99 ] );
		$this->set_stock( $product, 3 );
		$this->flush();
		$this->flush();

		$this->assertSame( [ [ Scripted_Stock_Pusher::PUSH, 99, [ 'a' => 3 ] ] ], $this->market->requests, 'one warehouse, one send' );
	}

	/**
	 * A readback between a refused archive and its retry keeps the trashed product at zero and the archive pending.
	 *
	 * @return void
	 */
	public function test_a_readback_keeps_a_refused_archive_pending(): void {
		$product = $this->stock_product( 'a', 1001, 5 );
		$this->hook_stock();
		$this->trash( $product );

		$this->market->script = [ self::refuse_archive(), self::refuse_archive() ];
		$this->flush();
		$this->reconcile();

		$this->now += 60;
		$this->flush();

		$this->assertSame( 0, $this->market->present['a'], 'the trashed product stays at zero while the archive is retried' );
		$this->assertCount( 2, $this->market->sent( Scripted_Stock_Pusher::ARCHIVE ) );
		$this->assertSame( 5, $this->wc_stock( $product ), 'the readback did not touch the removed product' );
	}

	/**
	 * The archive call counts toward the run's request budget.
	 *
	 * @return void
	 */
	public function test_the_archive_counts_toward_the_budget(): void {
		$product = $this->stock_product( 'a', 1001, 5 );
		$this->hook_stock();
		$this->trash( $product );

		$result = $this->flush( 1 );

		$this->assertCount( 1, $this->market->requests, 'a budget of one sends one request' );
		$this->assertSame( 1, $result->requests );

		$this->flush( 1 );

		$this->assertCount( 1, $this->market->sent( Scripted_Stock_Pusher::ARCHIVE ), 'the next run archives' );
		$this->assertCount( 1, $this->market->sent( Scripted_Stock_Pusher::PUSH ), 'without sending the zero again' );
	}

	/**
	 * A pair the marketplace refuses five times is failed and kept with its text, not sent a sixth time.
	 *
	 * @return void
	 */
	public function test_a_pair_is_failed_after_five_refusals(): void {
		$product = $this->stock_product( 'a', 1001 );
		$this->hook_stock();
		$this->set_stock( $product, 4 );

		$failed = 0;

		for ( $attempt = 0; $attempt < 6; $attempt++ ) {
			$this->market->script = [
				function () use ( $product ): array {
					return $this->refused( $product, 'fake_bad_item', 'invalid item' );
				},
			];
			$failed              += $this->flush()->failed;
			$this->now           += 1801;
		}

		$this->assertCount( 5, $this->market->sent( Scripted_Stock_Pusher::PUSH ), 'no sixth automatic send' );
		$this->assertSame( 1, $failed, 'the fifth refusal is reported as the failure' );

		$row = $this->stock_row( $product );
		$this->assertSame( [ 'stock' => 4 ], $row['pending_payload'], 'the value is kept' );
		$this->assertStringContainsString( 'invalid item', (string) $row['last_error'] );

		$this->market->script = [];
		$this->set_stock( $product, 3 );
		$this->flush();

		$this->assertCount( 6, $this->market->sent( Scripted_Stock_Pusher::PUSH ), 'a new value is a new try' );
	}

	/**
	 * An obsolete warehouse row does not overwrite the newer intent already recorded for the current one.
	 *
	 * @return void
	 */
	public function test_a_warehouse_move_keeps_the_newer_destination_intent(): void {
		$product = $this->stock_product( 'a', 1001 );
		$this->hook_stock();
		$this->set_stock( $product, 4 );

		++$this->now;
		$this->set_account( [ 'warehouse_id' => 99 ] );
		$this->set_stock( $product, 3 );
		$this->flush();
		$this->flush();

		$this->assertSame( [ [ Scripted_Stock_Pusher::PUSH, 99, [ 'a' => 3 ] ] ], $this->market->requests, 'the newer value of the current warehouse survives the move, and nothing goes to the old one' );
	}

	/**
	 * A death right after the obsolete row was retired must not forget its payload.
	 *
	 * @return void
	 */
	public function test_a_death_during_a_warehouse_move_keeps_the_intent(): void {
		$product = $this->stock_product( 'a', 1001 );
		$this->hook_stock();
		$this->set_stock( $product, 4 );
		$this->set_account( [ 'warehouse_id' => 99 ] );

		$this->die_after( '/SET pending_payload = NULL/', 'death after the retirement' );

		try {
			$this->flush();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'death after the retirement', $e->getMessage() );
		}

		$this->db->before_query = null;
		$this->now             += Job_Gate::CLAIM_TTL + 1;
		$this->flush();
		$this->flush();

		$this->assertSame( [ [ Scripted_Stock_Pusher::PUSH, 99, [ 'a' => 4 ] ] ], $this->market->requests, 'the moved payload is sent once, to the current warehouse' );
	}

	/**
	 * An old acknowledged zero is not the zero of a later archive when a nonzero send after it is unresolved.
	 *
	 * @return void
	 */
	public function test_an_uncertain_nonzero_needs_a_new_zero_before_the_archive(): void {
		$product = $this->stock_product( 'a', 1001 );
		$this->hook_stock();

		$this->set_stock( $product, 0 );
		$this->flush();

		$this->now += 31;
		$this->set_stock( $product, 5 );
		$this->market->script[] = function (): void {
			$this->market->present['a'] = 5;

			throw Fake_Remote_Exception::of( Remote_Error::RETRY, 'timeout' );
		};
		$this->flush();

		$this->trash( $product );
		$this->now += 60;
		$this->flush();

		$this->assertSame( 0, $this->market->present['a'], 'the trashed product is zeroed again before its archive' );
		$this->assertCount( 1, $this->market->sent( Scripted_Stock_Pusher::ARCHIVE ) );
		$this->assertSame( Link_Record::STATE_ARCHIVED, $this->link_of( $product )->card_state() );
	}

	/**
	 * The warehouse goes 99 → 22 → 99 within one second. The destination's value 4 was already accepted, so the newest value 3 of
	 * the retired row must replace it, although both carry the same `pending_since`.
	 *
	 * @return void
	 */
	public function test_a_warehouse_move_within_one_second_keeps_the_newest_value(): void {
		$product = $this->stock_product( 'tie', 1501 );
		$this->hook_stock();

		$this->set_account( [ 'warehouse_id' => 99 ] );
		$this->set_stock( $product, 4 );
		$this->flush();

		$this->set_account( [ 'warehouse_id' => 22 ] );
		$this->set_stock( $product, 3 );
		$this->set_account( [ 'warehouse_id' => 99 ] );
		$this->flush();

		$this->now += 31;
		$this->flush();

		$pushes = $this->market->sent( Scripted_Stock_Pusher::PUSH );
		$this->assertSame( [ 'tie' => 3 ], end( $pushes ), 'warehouse 99 receives the latest value 3, not the older 4' );
	}

	/**
	 * The marketplace refuses every archive call. The accepted zero is not pushed again for each retry, so the refusals are
	 * counted and the sixth automatic archive never happens.
	 *
	 * @return void
	 */
	public function test_a_refused_archive_stops_after_five_attempts(): void {
		$product = $this->stock_product( 'refuse', 1502, 5 );
		$this->hook_stock();
		$this->trash( $product );

		for ( $run = 0; $run < 6; $run++ ) {
			$this->market->script = [ self::refuse_archive(), self::refuse_archive() ];
			$this->flush();
			$this->now += 1801;
		}

		$this->assertCount( 5, $this->market->sent( Scripted_Stock_Pusher::ARCHIVE ), 'no sixth automatic archive after five refusals' );
		$this->assertCount( 1, $this->market->sent( Scripted_Stock_Pusher::PUSH ), 'the accepted zero is not resent for archive-only retries' );
		$this->assertNotSame( '', (string) $this->stock_row( $product )['last_error'], 'the row stays failed with its text' );
	}

	/**
	 * An archive call that fails as a request is a counted failure with the marketplace's text; the card stays.
	 *
	 * @return void
	 */
	public function test_an_archive_call_that_fails_is_a_counted_failure(): void {
		$product = $this->stock_product( 'down', 1503, 5 );
		$this->hook_stock();
		$this->trash( $product );

		$this->market->script = [
			static function () {
				return null;
			},
			static function (): void {
				throw Fake_Remote_Exception::of( Remote_Error::RETRY, 'archive is down' );
			},
		];
		$result               = $this->flush();

		$this->assertSame( [ 'archive is down' ], $result->errors );
		$this->assertSame( Link_Record::STATE_CREATED, $this->link_of( $product )->card_state() );
		$this->assertSame( 1, (int) $this->stock_row( $product )['attempts'] );
	}

	/**
	 * An older source row that backs off must not replace a newer value the destination already holds, even when both were
	 * recorded within one second.
	 *
	 * @return void
	 */
	public function test_an_older_throttled_source_cannot_replace_a_newer_accepted_destination(): void {
		$product = $this->stock_product( 'accepted', 1702 );
		$this->hook_stock();
		$this->set_stock( $product, 4 );

		$this->market->script[] = function () use ( $product ): array {
			return $this->refused( $product, Scripted_Stock_Pusher::CODE_SLOW_DOWN, 'wait' );
		};
		$this->flush();

		$this->set_account( [ 'warehouse_id' => 99 ] );
		$this->set_stock( $product, 3 );
		$this->flush();

		$this->now += 31;
		$this->flush();
		$this->now += 31;
		$this->flush();

		$this->assertSame( 3, $this->market->present['accepted'], 'the older value of the retired warehouse does not come back' );
	}

	/**
	 * Both rows are still unsent in the same second; the newest intent wins by revision.
	 *
	 * @return void
	 */
	public function test_an_unsent_same_second_source_survives_by_revision(): void {
		$product = $this->stock_product( 'unsent', 1701 );
		$this->hook_stock();

		$this->set_account( [ 'warehouse_id' => 99 ] );
		$this->set_stock( $product, 4 );
		$this->set_account( [ 'warehouse_id' => 22 ] );
		$this->set_stock( $product, 3 );
		$this->set_account( [ 'warehouse_id' => 99 ] );
		$this->flush();

		$this->now += 31;
		$this->flush();

		$this->assertSame( 3, $this->market->present['unsent'] );
	}

	/**
	 * An archive that reuses a zero accepted under an earlier revision keeps its failure count too.
	 *
	 * @return void
	 */
	public function test_an_archive_after_an_earlier_zero_stops_after_five_attempts(): void {
		$product = $this->stock_product( 'oldzero', 1703 );
		$this->hook_stock();

		$this->set_stock( $product, 0 );
		$this->flush();
		$this->trash( $product );

		for ( $run = 0; $run < 7; $run++ ) {
			$this->market->script = [ self::refuse_archive(), self::refuse_archive() ];
			$this->flush();
			$this->now += 1801;
		}

		$this->assertCount( 5, $this->market->sent( Scripted_Stock_Pusher::ARCHIVE ), 'no sixth automatic archive after five refusals' );
	}

	/**
	 * A whole request the marketplace throttles makes its pairs wait what it named, counts no failure and ends the run.
	 *
	 * @return void
	 */
	public function test_a_throttled_request_waits_and_ends_the_run(): void {
		$here  = $this->stock_product( 'here', 1801 );
		$there = $this->stock_product( 'there', 1802, 10, [ 'warehouse_id' => 99 ] );
		$this->hook_stock();
		$this->set_stock( $here, 4 );
		$this->set_stock( $there, 5 );

		$this->market->script[] = static function (): void {
			throw Fake_Remote_Exception::of( Remote_Error::THROTTLE, 'slow down', 40 );
		};
		$result                 = $this->flush();

		$this->assertSame( 1, $result->requests, 'the second warehouse is not tried in this run' );
		$this->assertSame( 1, $result->waiting );
		$this->assertSame( [], $result->errors );

		$row = $this->stock_row( $here );
		$this->assertSame( 0, (int) $row['attempts'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $this->now + 40 ), $row['next_attempt_at'] );
	}

	/**
	 * A request refused for the account's credentials pauses the account, keeps the pairs uncounted and ends the run.
	 *
	 * @return void
	 */
	public function test_refused_credentials_pause_the_account(): void {
		$product = $this->stock_product( 'auth', 1901 );
		$this->hook_stock();
		$this->set_stock( $product, 4 );

		$this->market->script[] = static function (): void {
			throw Fake_Remote_Exception::of( Remote_Error::PAUSE_ACCOUNT, 'key revoked' );
		};
		$result                 = $this->flush();

		$this->assertSame( [ 'key revoked' ], $result->errors );
		$this->assertSame( [ $this->account_id ], $this->classifier->refused );
		$this->assertSame( 0, (int) $this->stock_row( $product )['attempts'], 'a refused key is not the pair\'s failure' );
		$this->assertGreaterThan( 0, $this->pause()->remaining( $this->account_now() ) );
		$this->assertNull( $this->flush_runner()->run( $this->account_id ), 'the paused account sends nothing' );
		$this->assertCount( 1, $this->market->requests );
	}

	/**
	 * An error the adapter does not know is not a marketplace answer: it leaves the flusher as it is.
	 *
	 * @return void
	 */
	public function test_an_unknown_error_is_not_swallowed(): void {
		$product = $this->stock_product( 'bug', 1951 );
		$this->hook_stock();
		$this->set_stock( $product, 4 );

		$this->market->script[] = static function (): void {
			throw new \LogicException( 'a bug in the pusher' );
		};

		$this->expectException( \LogicException::class );
		$this->flush();
	}

	/**
	 * A one-line order of product A.
	 *
	 * @param string               $number Order number.
	 * @param string               $status Canonical status.
	 * @param array<string,string> $dates  Dates.
	 * @return \Woodev\Framework\Marketplace\Orders\Order_DTO
	 */
	private function sale( string $number, string $status = Status_Map::AWAITING_SHIPMENT, array $dates = [] ) {
		return $this->market_order( $number, $status, [ 'a' => [ 1001, 1 ] ], $dates );
	}

	/**
	 * A script entry that refuses the archive call and leaves any other call to its default.
	 *
	 * @return callable
	 */
	private static function refuse_archive(): callable {
		return static function ( string $call ) {
			return Scripted_Stock_Pusher::ARCHIVE === $call ? false : null;
		};
	}
}
