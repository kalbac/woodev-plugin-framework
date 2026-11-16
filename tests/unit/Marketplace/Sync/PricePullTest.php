<?php
/**
 * The merchant's one-time «Загрузить цены» (#1797, D3): the pull, its settling of the queued send, the restore of a price the
 * merchant saved meanwhile, and the job that runs it under the account claim.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Sync;

use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Marketplace\Diagnostics\Api_Error_Ring;
use Woodev\Framework\Marketplace\Jobs\Claim_Lost;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\Price_Refresh_Runner;
use Woodev\Framework\Marketplace\Jobs\Price_Scheduler;
use Woodev\Framework\Marketplace\Links\Product_Link;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Framework\Marketplace\Price\Price_Pair;
use Woodev\Framework\Marketplace\Sync\Price_Intents;
use Woodev\Framework\Marketplace\Sync\Price_Refresh_Guard;
use Woodev\Framework\Marketplace\Sync\Price_Refresh_Result;
use Woodev\Framework\Marketplace\Sync\Price_Refresher;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Price_Context;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Price_Reader;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Policy_Price_Context;
use Woodev\Tests\Unit\Marketplace\Support\Sync_Test_Case;

/**
 * The price pull over the SQLite tables of the sync harness: products are doubles whose prices live in {@see self::$shop}, the export
 * side is the identity rule of {@see Fake_Price_Context} (the marketplace's price is the shop's), the marketplace is
 * {@see Fake_Price_Reader}. A merchant's save is simulated by {@see self::merchant_saves()}: the shop price changes and the price
 * recorder stores the intent, as WooCommerce's product-saved hook does in the merchant's own request.
 *
 * @covers \Woodev\Framework\Marketplace\Sync\Price_Refresher
 * @covers \Woodev\Framework\Marketplace\Price\Price_Sync_Queue
 * @covers \Woodev\Framework\Marketplace\Jobs\Price_Refresh_Runner
 * @covers \Woodev\Framework\Marketplace\Jobs\Price_Scheduler
 */
final class PricePullTest extends Sync_Test_Case {
	private const REFRESH = 'woodev_fake_seller_refresh_prices';

	/**
	 * The export side: the identity rule, or the production policy for a test that needs a rule that rounds.
	 *
	 * @var Fake_Price_Context|Policy_Price_Context
	 */
	private $contexts;

	/**
	 * The marketplace's prices.
	 *
	 * @var Fake_Price_Reader
	 */
	private $reader;

	/**
	 * Shop prices of the product doubles: product id => `[ regular, sale, from, to, guarded saves ]`.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $shop = [];

	/**
	 * What WooCommerce's product-saved hook calls inside a product double's `save()`: `function ( \WC_Product ): void`.
	 *
	 * @var callable|null
	 */
	private $on_save;

	/**
	 * What happens, one callable per read, right before the pull writes the shop price: the merchant's save lands there.
	 *
	 * @var callable[]
	 */
	private $before_write = [];

	/**
	 * Whether a scripted merchant save is running.
	 *
	 * @var bool
	 */
	private $in_hook = false;

	/**
	 * A `woocommerce_product_get_*_price` filter of the shop: `function ( string $price ): string`, applied to the `view` context.
	 *
	 * @var callable|null
	 */
	private $view_filter;

	/**
	 * Builds the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->contexts     = new Fake_Price_Context();
		$this->reader       = new Fake_Price_Reader();
		$this->shop         = [];
		$this->on_save      = null;
		$this->before_write = [];
		$this->in_hook      = false;
		$this->view_filter  = null;
	}

	/**
	 * A shop on the default `to_marketplace` direction presses the button and the product changes; the save runs under the guard, so
	 * no price revision is recorded by it.
	 *
	 * @return void
	 */
	public function test_a_pull_on_a_to_marketplace_account_writes_the_price_and_records_no_revision(): void {
		$product = $this->priced( 'a', '700.00' );
		$this->echo_saves_to_the_recorder();
		$this->reader->prices['a'] = $this->pair( '450.00', '600.00' );

		$result = $this->pull( [ 'a' ] );

		$this->assertSame( [ 'a' ], $result->updated() );
		$this->assertSame( [ '600.00', '450.00' ], [ $this->shop[ $product ]['regular'], $this->shop[ $product ]['sale'] ] );
		$this->assertSame( [ true ], $this->shop[ $product ]['guarded'], 'The save ran under the marketplace\'s guard.' );
		$this->assertNull( $this->price_row( $product ), 'The guarded save recorded no intent, so no revision exists.' );
	}

	/**
	 * A shop price of 150 queued while the marketplace holds 100: the pull settles the pair, so the sender no longer selects it and
	 * 100 is not replaced by 150 a minute later.
	 *
	 * @return void
	 */
	public function test_a_queued_send_is_settled_by_the_pull_and_is_no_longer_due(): void {
		$product = $this->queued( 'a', '150.00' );
		$this->reader->prices['a'] = $this->pair( '100.00', null );

		$this->assertCount( 1, $this->price_queue()->due( $this->account_id, 10 ), 'Before the pull the 150 is due.' );

		$result = $this->pull( [ 'a' ] );
		$row    = $this->price_row( $product );

		$this->assertSame( [ 'a' ], $result->updated() );
		$this->assertSame( '100.00', $this->shop[ $product ]['regular'] );
		$this->assertSame( '100.00', $row['pending_payload']['price'] );
		$this->assertSame( $row['pending_payload'], $row['sent_payload'], 'Pending equals sent.' );
		$this->assertSame( [], $this->price_queue()->due( $this->account_id, 10 ) );
		$this->assertEquals( 100.0, $this->db->get_var( 'SELECT last_pushed_price FROM ' . Sqlite_Tables::table( 'links' ) . ' WHERE link_id = ' . $this->link_id( $product ) ), 'What the marketplace holds is recorded as the last pushed price.' );
	}

	/**
	 * A price a send had in flight when its sender died is cleared by the pull, and the dead sender's late acknowledgement, fenced
	 * by its lost claim token, changes nothing.
	 *
	 * @return void
	 */
	public function test_a_stale_in_flight_snapshot_is_cleared_and_the_dead_senders_accept_changes_nothing(): void {
		$product = $this->queued( 'a', '150.00' );
		$this->reader->prices['a'] = $this->pair( '100.00', null );

		$dead    = (string) $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );
		$intents = $this->price_queue()->due( $this->account_id, 10 );

		$this->assertTrue( $this->price_queue()->begin( $this->account_id, $intents[0], $dead ) );
		$this->assertNotNull( $this->price_row( $product )['in_flight_payload'] );

		$this->now += Job_Gate::CLAIM_TTL + 5; // The dead sender never released; its claim lapsed.
		$this->pull( [ 'a' ] );

		$row = $this->price_row( $product );

		$this->assertNull( $row['in_flight_payload'], 'The stale snapshot is cleared.' );
		$this->assertFalse( $this->price_queue()->accept( $this->account_id, $intents[0], $dead ), 'The dead sender no longer owns the account.' );
		$this->assertSame( '100.00', $this->price_row( $product )['sent_payload']['price'], 'Its acknowledgement changed nothing.' );
	}

	/**
	 * The `from_marketplace` direction is the same read; a leftover queued price from before the direction changed is settled too.
	 *
	 * @return void
	 */
	public function test_on_a_from_marketplace_account_the_pull_is_the_same(): void {
		$this->set_account( [ 'price_direction' => 'from_marketplace' ] );
		$product = $this->priced( 'a', '150.00' );
		$this->want_price( $product, [ 'price' => '150.00' ] );
		$this->reader->prices['a'] = $this->pair( '100.00', null );

		$this->assertSame( [ 'a' ], $this->pull( [ 'a' ] )->updated() );
		$this->assertSame( '100.00', $this->shop[ $product ]['regular'] );
		$this->assertSame( [], $this->price_queue()->due( $this->account_id, 10 ) );
	}

	/**
	 * A link with price sync off is pulled all the same: the merchant asked explicitly. A card that is not created is skipped with the
	 * reason.
	 *
	 * @return void
	 */
	public function test_a_link_with_price_sync_off_is_pulled_and_a_card_that_is_not_created_is_skipped(): void {
		$muted   = $this->priced( 'muted', '100.00', null, [ 'sync_price' => false ] );
		$waiting = $this->priced( 'waiting', '100.00', null, [ 'state' => 'pending_remote_ids' ] );

		$this->reader->prices['muted']   = $this->pair( '300.00', null );
		$this->reader->prices['waiting'] = $this->pair( '300.00', null );

		$result = $this->pull( [ 'muted', 'waiting' ] );

		$this->assertSame( [ 'muted' ], $result->updated() );
		$this->assertSame( '300.00', $this->shop[ $muted ]['regular'] );
		$this->assertSame( [ 'waiting' => 'Карточка на маркетплейсе ещё не создана.' ], $result->skipped() );
		$this->assertSame( '', $this->shop[ $waiting ]['regular'], 'Nothing was written.' );
		$this->assertSame( [ [ 'muted' ] ], $this->reader->reads, 'Only the created card is read.' );
	}

	/**
	 * A price the rule cannot be undone for is skipped with the writer's own sentence, the product is left, and the queue is left.
	 *
	 * @return void
	 */
	public function test_a_price_the_writer_cannot_restore_is_skipped_with_its_own_sentence(): void {
		$product = $this->queued( 'a', '150.00' );
		$this->db->query( 'UPDATE ' . Sqlite_Tables::table( 'links' ) . " SET price_rule_override = '{\"absolute\":\"999\"}' WHERE link_id = " . $this->link_id( $product ) );
		$this->reader->prices['a'] = $this->pair( '100.00', null );

		$result = $this->pull( [ 'a' ] );

		$this->assertStringContainsString( 'обратный расчёт невозможен', $result->skipped()['a'] );
		$this->assertCount( 1, $this->price_queue()->due( $this->account_id, 10 ), 'A skipped article keeps its queued send.' );
	}

	/**
	 * After the pull the merchant saves another price: it is recorded as usual and sent.
	 *
	 * @return void
	 */
	public function test_a_real_edit_after_the_pull_is_sent(): void {
		$product = $this->queued( 'a', '150.00' );
		$this->reader->prices['a'] = $this->pair( '100.00', null );
		$this->pull( [ 'a' ] );

		$this->assertSame( [], $this->price_queue()->due( $this->account_id, 10 ) );

		$this->merchant_saves( $product, '120.00' );
		$due = $this->price_queue()->due( $this->account_id, 10 );

		$this->assertCount( 1, $due );
		$this->assertSame( '120.00', $due[0]->payload()['price'] );
	}

	/**
	 * The merchant saves 200 after the pull read the queue and before it wrote the shop price (the edit-BEFORE-the-WC-write
	 * interleaving): the pull writes 100, its settle fails, and the restore puts 200 back — the merchant's edit is newer and wins. The
	 * pair holds 200 as pending and sends it.
	 *
	 * @return void
	 */
	public function test_an_edit_before_the_shop_write_is_restored_and_sent(): void {
		$product = $this->queued( 'a', '150.00' );
		$this->reader->prices['a'] = $this->pair( '100.00', null );
		$this->before_write[]      = function () use ( $product ): void {
			$this->merchant_saves( $product, '200.00' );
		};

		$result = $this->pull( [ 'a' ] );
		$due    = $this->price_queue()->due( $this->account_id, 10 );

		$this->assertSame( '200.00', $this->shop[ $product ]['regular'], 'The shop holds the merchant\'s price, not the pulled one.' );
		$this->assertSame( [ true, true ], $this->shop[ $product ]['guarded'], 'The pull wrote 100 and the restore wrote 200, both under the guard.' );
		$this->assertSame( '200.00', $this->price_row( $product )['pending_payload']['price'] );
		$this->assertCount( 1, $due );
		$this->assertSame( '200.00', $due[0]->payload()['price'], 'The 200 is sent.' );
		$this->assertSame( 'Цену товара изменили во время загрузки — осталась новая цена с сайта.', $result->skipped()['a'] );
	}

	/**
	 * The same interleaving when nothing was queued before the pull: a price queued meanwhile is the merchant's save and is restored too.
	 *
	 * @return void
	 */
	public function test_an_edit_before_the_shop_write_is_restored_when_nothing_was_queued(): void {
		$product = $this->priced( 'a', '150.00' );
		$this->reader->prices['a'] = $this->pair( '100.00', null );
		$this->before_write[]      = function () use ( $product ): void {
			$this->merchant_saves( $product, '200.00' );
		};

		$this->pull( [ 'a' ] );

		$this->assertSame( '200.00', $this->shop[ $product ]['regular'] );
		$this->assertCount( 1, $this->price_queue()->due( $this->account_id, 10 ) );
	}

	/**
	 * The merchant saves 200 after the pull wrote the shop price and before its settle: the settle fails, the intent for 200 stays
	 * pending, and it is still sent.
	 *
	 * @return void
	 */
	public function test_an_edit_between_the_write_and_the_settle_is_still_sent(): void {
		$product = $this->queued( 'a', '150.00' );
		$this->reader->prices['a'] = $this->pair( '100.00', null );
		$this->on_save             = function () use ( $product ): void {
			$this->on_save = null;
			$this->merchant_saves( $product, '200.00' );
		};

		$this->pull( [ 'a' ] );
		$due = $this->price_queue()->due( $this->account_id, 10 );

		$this->assertSame( '200.00', $this->shop[ $product ]['regular'] );
		$this->assertCount( 1, $due );
		$this->assertSame( '200.00', $due[0]->payload()['price'] );
	}

	/**
	 * A save that lands again during the restore is followed once more; the third lands after the last round, so the article is left
	 * with the report line and its newest intent untouched.
	 *
	 * @return void
	 */
	public function test_an_edit_during_the_restore_is_retried_twice_then_left_with_a_line_to_check(): void {
		$product = $this->queued( 'a', '150.00' );
		$this->reader->prices['a'] = $this->pair( '100.00', null );

		foreach ( [ '200.00', '300.00', '400.00' ] as $edit ) {
			$this->before_write[] = function () use ( $product, $edit ): void {
				$this->merchant_saves( $product, $edit );
			};
		}

		$result = $this->pull( [ 'a' ] );

		$this->assertSame( 'Цена менялась во время загрузки — проверьте товар.', $result->skipped()['a'] );
		$this->assertSame( '400.00', $this->price_row( $product )['pending_payload']['price'], 'The newest intent is untouched.' );
		$this->assertCount( 3, $this->shop[ $product ]['guarded'], 'The pull and two restore rounds, no more.' );
		$this->assertSame( [ '400.00' ], array_map( static fn( $intent ) => $intent->payload()['price'], $this->price_queue()->due( $this->account_id, 10 ) ) );
	}

	/**
	 * The production rounding `to_10`: the merchant saves 201, which goes out as 200, after the pull read the queue and before it wrote
	 * the shop price. The restore puts back the 201 the merchant saved — the exact shop price recorded with the intent — and not the
	 * 200 that inverting the outbound price would give; the pending price stays 200.
	 *
	 * @return void
	 */
	public function test_a_rounding_rule_keeps_the_exact_shop_price_the_merchant_saved(): void {
		$this->contexts            = new Policy_Price_Context();
		$product                   = $this->queued( 'a', '150.00' );
		$this->reader->prices['a'] = $this->pair( '100.00', null );
		$this->before_write[]      = function () use ( $product ): void {
			$this->merchant_saves( $product, '201.00' );
		};

		$result = $this->pull( [ 'a' ] );
		$due    = $this->price_queue()->due( $this->account_id, 10 );

		$this->assertSame( '201.00', $this->shop[ $product ]['regular'], 'The merchant\'s 201 is back, not the rounded 200.' );
		$this->assertSame( '200.00', $this->price_row( $product )['pending_payload']['price'], 'The pending price stays the rounded 200.' );
		$this->assertSame( [ true, true ], $this->shop[ $product ]['guarded'] );
		$this->assertSame( [ '200.00' ], array_map( static fn( $intent ) => $intent->payload()['price'], $due ) );
		$this->assertSame( 'Цену товара изменили во время загрузки — осталась новая цена с сайта.', $result->skipped()['a'] );
	}

	/**
	 * An intent that was recorded without the shop price (the card's creation seeds one) is not guessed from the outbound price: the
	 * product keeps what the pull wrote and the article is left with the line to check.
	 *
	 * @return void
	 */
	public function test_an_intent_without_a_recorded_shop_price_is_not_guessed(): void {
		$product                   = $this->queued( 'a', '150.00' );
		$this->reader->prices['a'] = $this->pair( '100.00', null );
		$this->before_write[]      = function () use ( $product ): void {
			$this->want_price( $product, [ 'price' => '200.00' ] );
		};

		$result = $this->pull( [ 'a' ] );

		$this->assertSame( '100.00', $this->shop[ $product ]['regular'] );
		$this->assertSame( 'Цена менялась во время загрузки — проверьте товар.', $result->skipped()['a'] );
		$this->assertSame( '200.00', $this->price_row( $product )['pending_payload']['price'] );
	}

	/**
	 * A price saved again that goes out as the same payload (201, then 202, both 200) is a new revision, so a pull in progress sees the
	 * edit; nothing is sent, because the sender compares the payloads and they are equal.
	 *
	 * @return void
	 */
	public function test_a_new_shop_price_with_the_same_outbound_price_is_a_revision_but_sends_nothing(): void {
		$this->contexts = new Policy_Price_Context();
		$product        = $this->priced( 'a', '201.00' );
		$this->shop[ $product ]['regular'] = '201.00';

		$this->merchant_saves( $product, '201.00' );
		$token = (string) $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );
		$due   = $this->price_queue()->due( $this->account_id, 10 );

		$this->assertTrue( $this->price_queue()->begin( $this->account_id, $due[0], $token ) );
		$this->assertTrue( $this->price_queue()->accept( $this->account_id, $due[0], $token ) );
		$this->accounts()->release_claim( $this->account_id, $token );

		$this->merchant_saves( $product, '202.00' );

		$this->assertSame( 2, $this->price_queue()->pending_of( $this->account_id, $this->link_id( $product ) )->revision() );
		$this->assertSame( [], $this->price_queue()->due( $this->account_id, 10 ), 'The payloads are equal: nothing is sent.' );
		$this->assertSame( '202.00', $this->price_queue()->pending_of( $this->account_id, $this->link_id( $product ) )->source()['regular'] );
	}

	/**
	 * MySQL and MariaDB report changed rows, not matched ones: settling a pair an earlier pull already left settled changes none. That
	 * is a settle that stands — read back as settled under the live claim — not a lost claim; a pair of another revision, or a claim that
	 * is not held, is still refused.
	 *
	 * @return void
	 */
	public function test_an_already_settled_pair_stands_when_the_database_counts_changed_rows(): void {
		$product                   = $this->queued( 'a', '150.00' );
		$this->reader->prices['a'] = $this->pair( '100.00', null );
		$this->pull( [ 'a' ] );

		$row     = $this->price_row( $product );
		$link_id = $this->link_id( $product );
		$token   = (string) $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );

		$this->db->count_changed_rows = true;

		$this->assertTrue( $this->price_queue()->settle_pulled( $this->account_id, $link_id, 1, $row['pending_payload'], $token ), 'An identical second settle stands.' );
		$this->assertFalse( $this->price_queue()->settle_pulled( $this->account_id, $link_id, 1, $row['pending_payload'], 'not-the-holder' ), 'Without the claim it does not.' );
		$this->assertFalse( $this->price_queue()->settle_pulled( $this->account_id, $link_id, 2, $row['pending_payload'], $token ), 'Another revision does not.' );

		$this->db->query( 'UPDATE ' . Sqlite_Tables::table( 'sync_state' ) . ' SET attempts = 2 WHERE link_id = ' . $link_id );

		$this->assertTrue( $this->price_queue()->settle_pulled( $this->account_id, $link_id, 1, $row['pending_payload'], $token ), 'A retry state is cleared by the settle itself.' );
		$this->assertSame( 0, (int) $this->price_row( $product )['attempts'] );
		$this->accounts()->release_claim( $this->account_id, $token );
	}

	/**
	 * A batch whose first article is already settled and whose second needs an update loads both: the first is not taken for a lost
	 * claim (which would requeue the whole batch for ever), the second is written and settled.
	 *
	 * @return void
	 */
	public function test_a_batch_with_an_already_settled_first_article_still_loads_the_second(): void {
		$first  = $this->queued( 'a', '150.00' );
		$second = $this->queued( 'b', '160.00' );

		$this->reader->prices['a'] = $this->pair( '100.00', null );
		$this->pull( [ 'a' ] );

		$this->reader->prices['b']    = $this->pair( '110.00', null );
		$this->db->count_changed_rows = true;

		$result = $this->pull( [ 'a', 'b' ] );

		$this->assertSame( [ 'a' ], $result->unchanged() );
		$this->assertSame( [ 'b' ], $result->updated() );
		$this->assertSame( '110.00', $this->shop[ $second ]['regular'] );
		$this->assertSame( [], $this->price_queue()->due( $this->account_id, 10 ) );
		$this->assertSame( '100.00', $this->shop[ $first ]['regular'] );
	}

	/**
	 * What the job leaves behind: every article it did not load, with its own sentence, in the log. Article a cannot be inverted;
	 * article b, second in the batch, runs out of restore rounds — its sentence must survive next to a's.
	 *
	 * @return void
	 */
	public function test_the_job_logs_every_skipped_article_with_its_own_sentence(): void {
		$first  = $this->queued( 'a', '150.00' );
		$second = $this->queued( 'b', '150.00' );
		$this->db->query( 'UPDATE ' . Sqlite_Tables::table( 'links' ) . " SET price_rule_override = '{\"absolute\":\"999\"}' WHERE link_id = " . $this->link_id( $first ) );
		$this->reader->prices['a'] = $this->pair( '100.00', null );
		$this->reader->prices['b'] = $this->pair( '100.00', null );

		foreach ( [ '200.00', '300.00', '400.00' ] as $edit ) {
			$this->before_write[] = function () use ( $second, $edit ): void {
				$this->merchant_saves( $second, $edit );
			};
		}

		$this->runner()->run( $this->account_id, [ 'a', 'b' ], 0, Price_Scheduler::PULL_MODE );

		$lines = array_values( preg_grep( '/refresh_prices \(pull\)/', $this->log ) );

		$this->assertCount( 2, $lines );
		$this->assertStringContainsString( 'article a not loaded:', $lines[0] );
		$this->assertStringContainsString( 'обратный расчёт невозможен', $lines[0] );
		$this->assertStringContainsString( 'article b not loaded: Цена менялась во время загрузки — проверьте товар.', $lines[1] );
	}

	/**
	 * A shop that filters its price getters (a price shown doubled) must not have the filtered value recorded as the merchant's price:
	 * the snapshot is the raw stored value, a product that already holds it is not saved again, and a restore after the pull puts back
	 * the raw 201, not the 402 the filter shows.
	 *
	 * @return void
	 */
	public function test_the_recorded_shop_price_is_the_raw_stored_value_not_a_filtered_one(): void {
		$product              = $this->queued( 'a', '150.00' );
		$this->view_filter    = static function ( string $price ): string {
			return number_format( (float) $price * 2, 2, '.', '' );
		};

		$this->merchant_saves( $product, '201.00' );

		$source = $this->price_queue()->pending_of( $this->account_id, $this->link_id( $product ) )->source();

		$this->assertSame( '402.00', $this->products[ $product ]->get_regular_price(), 'The shop shows the filtered price.' );
		$this->assertSame( '201.00', $source['regular'], 'The source is the raw stored one.' );
		$this->assertFalse( ( new \Woodev\Framework\Marketplace\Sync\Shop_Price_Writer( $this->contexts ) )->put_back( Sqlite_Tables::MARKETPLACE_ID, $this->products[ $product ], $source ), 'It already holds that: no save.' );
	}

	/**
	 * The same through a whole pull: the merchant's save lands before the shop write, the restore runs, and the stored price is 201.
	 *
	 * @return void
	 */
	public function test_a_restore_puts_back_the_raw_price_when_the_getters_are_filtered(): void {
		$product                   = $this->queued( 'a', '150.00' );
		$this->reader->prices['a'] = $this->pair( '100.00', null );
		$this->view_filter         = static function ( string $price ): string {
			return number_format( (float) $price * 2, 2, '.', '' );
		};
		$this->before_write[]      = function () use ( $product ): void {
			$this->merchant_saves( $product, '201.00' );
		};

		$this->pull( [ 'a' ] );

		$this->assertSame( '201.00', $this->shop[ $product ]['regular'], 'The stored price is the merchant\'s, not the doubled view of it.' );
	}

	/**
	 * A queue that cannot be read (here: the column the new code needs is missing) is not «nothing is queued»: the pull stops with an
	 * error before it writes the shop price, instead of reporting success and leaving the old send due to undo it.
	 *
	 * @return void
	 */
	public function test_an_unreadable_queue_stops_the_pull_instead_of_reading_as_nothing_queued(): void {
		$product                   = $this->queued( 'a', '150.00' );
		$this->reader->prices['a'] = $this->pair( '100.00', null );
		$this->db->query( 'ALTER TABLE ' . Sqlite_Tables::table( 'sync_state' ) . ' DROP COLUMN pending_source' );

		try {
			$this->pull( [ 'a' ] );
			$this->fail( 'The pull must not report success on a queue it cannot read.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'could not be read', $e->getMessage() );
		}

		$this->assertSame( '150.00', $this->shop[ $product ]['regular'], 'Nothing was written to the product.' );
	}

	/**
	 * A pull whose claim was taken over stops instead of undoing itself: the pulled price stays in the product, the queue is untouched
	 * and the next try, under a claim it holds, settles it.
	 *
	 * @return void
	 */
	public function test_a_pull_that_lost_its_claim_stops_and_the_next_try_settles(): void {
		$product = $this->queued( 'a', '150.00' );
		$this->reader->prices['a'] = $this->pair( '100.00', null );

		try {
			$this->refresher()->pull_once( $this->account_now(), [ 'a' ], new Claim_Fence( $this->accounts()->table(), 'not-the-holder' ) );
			$this->fail( 'A pull without the claim must not settle.' );
		} catch ( Claim_Lost $e ) {
			$this->assertStringContainsString( 'claim', $e->getMessage() );
		}

		$this->assertCount( 1, $this->price_queue()->due( $this->account_id, 10 ), 'The queue was not touched.' );

		$this->pull( [ 'a' ] );

		$this->assertSame( [], $this->price_queue()->due( $this->account_id, 10 ) );
		$this->assertSame( '100.00', $this->shop[ $product ]['regular'] );
	}

	/**
	 * The pull is the refresh job in `pull` mode, run in the account-job skeleton: while a sender holds the account claim the batch
	 * waits {@see Job_Gate::BUSY_DELAY} with its mode and runs after the sender is done.
	 *
	 * @return void
	 */
	public function test_a_pull_waits_while_a_sender_holds_the_claim_and_runs_after_it(): void {
		$product = $this->queued( 'a', '150.00' );
		$this->reader->prices['a'] = $this->pair( '100.00', null );

		$sender = (string) $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );
		$runner = $this->runner();

		$runner->run( $this->account_id, [ 'a' ], 0, Price_Scheduler::PULL_MODE );

		$this->assertSame( [ [ $this->account_id, [ 'a' ], 0, 'pull' ] ], array_column( $this->scheduler->of( self::REFRESH ), 'args' ) );
		$this->assertEqualsWithDelta( Job_Gate::BUSY_DELAY, $this->scheduler->of( self::REFRESH )[0]['delay'], 2 );
		$this->assertSame( [], $this->reader->reads, 'Nothing is read while the sender works.' );
		$this->assertSame( '150.00', $this->shop[ $product ]['regular'], 'The shop price is not touched while the sender works.' );

		$this->accounts()->release_claim( $this->account_id, $sender );
		$runner->run( $this->account_id, [ 'a' ], 0, Price_Scheduler::PULL_MODE );

		$this->assertSame( [ [ 'a' ] ], $this->reader->reads );
		$this->assertSame( '100.00', $this->shop[ $product ]['regular'] );
		$this->assertSame( [], $this->price_queue()->due( $this->account_id, 10 ) );
		$this->assertTrue( $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL ) !== null, 'The claim is released after the job.' );
	}

	/**
	 * The notification-driven refresh keeps its shape: it runs without the claim and waits for nothing; only the pull takes it.
	 *
	 * @return void
	 */
	public function test_the_plain_refresh_is_not_a_pull_and_takes_no_claim(): void {
		$this->set_account( [ 'price_direction' => 'from_marketplace' ] );
		$product                   = $this->priced( 'a', '100.00' );
		$this->reader->prices['a'] = $this->pair( '300.00', null );
		$sender                    = (string) $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );

		$this->runner()->run( $this->account_id, [ 'a' ] );

		$this->assertSame( '300.00', $this->shop[ $product ]['regular'] );
		$this->assertSame( [], $this->scheduler->of( self::REFRESH ) );
		$this->accounts()->release_claim( $this->account_id, $sender );
	}

	/**
	 * The pull is queued 100 articles at a time, in the refresh job's hook, with the mode argument; the retry keeps the mode.
	 *
	 * @return void
	 */
	public function test_pull_batches_carry_the_mode_and_hold_100_articles(): void {
		$offers = [];

		for ( $i = 1; $i <= 205; $i++ ) {
			$offers[] = 'offer-' . $i;
		}

		$scheduler = new Price_Scheduler( $this->names() );
		$queued    = $scheduler->queue_pull( $this->account_id, array_merge( $offers, [ 'offer-1' ] ) );

		$this->assertSame( 3, $queued );
		$this->assertSame( [ 100, 100, 5 ], array_map( 'count', array_column( array_column( $this->scheduler->of( self::REFRESH ), 'args' ), 1 ) ) );
		$this->assertSame( [ 0, 'pull' ], array_slice( $this->scheduler->of( self::REFRESH )[0]['args'], 2 ) );

		$scheduler->refresh_later( $this->account_id, [ 'a' ], 1, 30, Price_Scheduler::PULL_MODE );
		$scheduler->refresh_later( $this->account_id, [ 'a' ], 1, 30 );

		$this->assertSame( [ $this->account_id, [ 'a' ], 1, 'pull' ], $this->scheduler->of( self::REFRESH )[3]['args'] );
		$this->assertSame( [ $this->account_id, [ 'a' ], 1 ], $this->scheduler->of( self::REFRESH )[4]['args'], 'The notification-driven retry keeps its three arguments.' );
	}

	/**
	 * Runs one pull the way the job does: under the account claim.
	 *
	 * @param string[] $offer_ids Articles.
	 * @return Price_Refresh_Result
	 */
	private function pull( array $offer_ids ): Price_Refresh_Result {
		$token = (string) $this->accounts()->claim( $this->account_id, Job_Gate::CLAIM_TTL );

		try {
			return $this->refresher()->pull_once( $this->account_now(), $offer_ids, new Claim_Fence( $this->accounts()->table(), $token ) );
		} finally {
			$this->accounts()->release_claim( $this->account_id, $token );
		}
	}

	/**
	 * Makes WooCommerce's product-saved hook call the price recorder inside every save, with the shop prices of the saved product as
	 * the wanted ones: what the real hook does, and what the guard must keep from recording.
	 *
	 * @return void
	 */
	private function echo_saves_to_the_recorder(): void {
		$this->on_save = function ( \WC_Product $saved ): void {
			$prices                                    = $this->shop[ $saved->get_id() ];
			$this->contexts->pairs[ $saved->get_id() ] = $this->pair( '' === $prices['sale'] ? $prices['regular'] : $prices['sale'], '' === $prices['sale'] ? null : $prices['regular'] );

			$this->recorder()->on_product_updated( $saved, [ 'regular_price', 'sale_price', 'price' ] );
		};
	}

	/**
	 * The merchant saves a price in another request: the shop holds it and the recorder wants it on the marketplace.
	 *
	 * @param int    $product_id Product id.
	 * @param string $price      New price.
	 * @return void
	 */
	private function merchant_saves( int $product_id, string $price ): void {
		$this->shop[ $product_id ]['regular']   = $price;
		$this->contexts->pairs[ $product_id ] = $this->pair( $price, null );

		$this->assertSame( Price_Intents::RECORDED, $this->recorder()->record_for_link( $this->account_now(), $this->link_of( $product_id ) ) );
	}

	/**
	 * A product whose price the shop has queued for the marketplace (revision 1), the shop holding the same price.
	 *
	 * @param string $offer Article.
	 * @param string $price Shop price.
	 * @return int Product id.
	 */
	private function queued( string $offer, string $price ): int {
		$product                         = $this->priced( $offer, $price );
		$this->shop[ $product ]['regular'] = $price;

		$this->assertSame( Price_Intents::RECORDED, $this->recorder()->record_for_link( $this->account_now(), $this->link_of( $product ) ) );

		return $product;
	}

	/**
	 * Creates a product with a created card and price sync on; the export side wants the given price for it.
	 *
	 * @param string              $offer     Article.
	 * @param string              $price     Wanted price.
	 * @param string|null         $old_price Wanted old price.
	 * @param array<string,mixed> $options   Link options.
	 * @return int Product id.
	 */
	private function priced( string $offer, string $price, ?string $old_price = null, array $options = [] ): int {
		static $remote_id = 9000;

		$id                = $this->product( 10 );
		$this->shop[ $id ] = [
			'regular' => '',
			'sale'    => '',
			'from'    => '',
			'to'      => '',
			'guarded' => [],
		];
		$product           = $this->products[ $id ];

		foreach ( [ 'regular' => 'regular_price', 'sale' => 'sale_price', 'from' => 'date_on_sale_from', 'to' => 'date_on_sale_to' ] as $key => $prop ) {
			$product->shouldReceive( 'get_' . $prop )->andReturnUsing(
				function ( $context = 'view' ) use ( $id, $key ) {
					if ( 'regular' === $key && [] !== $this->before_write && ! $this->in_hook ) {
						// The merchant's save reads the product too: it must not trigger the next scripted save.
						$this->in_hook = true;
						call_user_func( array_shift( $this->before_write ) );
						$this->in_hook = false;
					}

					// WooCommerce runs the shop's getter filters in the `view` context only; `edit` is the raw stored value.
					$value = $this->shop[ $id ][ $key ];

					return 'view' === $context && null !== $this->view_filter && in_array( $key, [ 'regular', 'sale' ], true ) && '' !== $value ? call_user_func( $this->view_filter, $value ) : $value;
				}
			);
			$product->shouldReceive( 'set_' . $prop )->andReturnUsing(
				function ( $value ) use ( $id, $key ): void {
					$this->shop[ $id ][ $key ] = (string) $value;
				}
			);
		}

		$product->shouldReceive( 'get_price' )->andReturnUsing(
			function () use ( $id ): string {
				return '' !== $this->shop[ $id ]['sale'] ? $this->shop[ $id ]['sale'] : $this->shop[ $id ]['regular'];
			}
		);

		$product->shouldReceive( 'save' )->andReturnUsing(
			function () use ( $id, $product ): int {
				$this->shop[ $id ]['guarded'][] = Price_Refresh_Guard::is_active( Sqlite_Tables::MARKETPLACE_ID );

				if ( null !== $this->on_save ) {
					call_user_func( $this->on_save, $product );
				}

				return $id;
			}
		);

		$this->links()->save(
			new Product_Link(
				$this->account_id,
				$id,
				0,
				$offer,
				[ 'item' => ++$remote_id ],
				$options['state'] ?? Product_Link::STATE_CREATED,
				[ 'sync_price' => $options['sync_price'] ?? true ]
			)
		);

		$this->contexts->products[ $id ] = $product;
		$this->contexts->pairs[ $id ]    = $this->pair( $price, $old_price );

		return $id;
	}

	/**
	 * Builds a pair in roubles.
	 *
	 * @param string      $price     Price.
	 * @param string|null $old_price Old price.
	 * @return Price_Pair
	 */
	private function pair( string $price, ?string $old_price ): Price_Pair {
		return new Price_Pair( Money::from_string( $price, 'RUB' ), null === $old_price ? null : Money::from_string( $old_price, 'RUB' ) );
	}

	/**
	 * Builds the price recorder (a fresh one reads the accounts again).
	 *
	 * @return Price_Intents
	 */
	private function recorder(): Price_Intents {
		return new Price_Intents( $this->accounts(), Sqlite_Tables::MARKETPLACE_ID, $this->links(), $this->contexts, $this->intents(), [ $this, 'log_line' ] );
	}

	/**
	 * Builds the refresher with the price queue it settles.
	 *
	 * @return Price_Refresher
	 */
	private function refresher(): Price_Refresher {
		return new Price_Refresher( $this->reader, $this->links(), $this->contexts, $this->price_queue() );
	}

	/**
	 * Builds the refresh job.
	 *
	 * @return Price_Refresh_Runner
	 */
	private function runner(): Price_Refresh_Runner {
		return new Price_Refresh_Runner(
			$this->job_gate(),
			$this->refresher(),
			new Price_Scheduler( $this->names() ),
			$this->classifier,
			[ $this, 'log_line' ],
			new Api_Error_Ring( $this->db, Sqlite_Tables::table( 'api_errors' ), $this->clock() )
		);
	}
}
