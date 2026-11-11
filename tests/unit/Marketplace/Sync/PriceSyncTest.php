<?php
/**
 * Price intents, the `from_marketplace` refresh and their jobs.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Sync;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Jobs\Account_Pause;
use Woodev\Framework\Marketplace\Jobs\Price_Recompute_Runner;
use Woodev\Framework\Marketplace\Jobs\Price_Refresh_Runner;
use Woodev\Framework\Marketplace\Jobs\Price_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Remote_Error;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Framework\Marketplace\Price\Price_Currency;
use Woodev\Framework\Marketplace\Price\Price_Pair;
use Woodev\Framework\Marketplace\Sync\Price_Intents;
use Woodev\Framework\Marketplace\Sync\Price_Refresh_Guard;
use Woodev\Framework\Marketplace\Sync\Price_Refresher;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Price_Context;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Price_Reader;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Remote_Exception;
use Woodev\Tests\Unit\Marketplace\Support\Sync_Test_Case;

/**
 * Moved from the Ozon plugin's `PriceFlushTest` (the wish recorder), `PriceRefreshTest`, `PriceTriggersTest` and
 * `PriceHooksTest`. How a product's price is worked out from its profile is the export flow's and stays with it; here that
 * side is a double, and the tests are about what the price sync does with its answers.
 */
final class PriceSyncTest extends Sync_Test_Case {
	private const RECOMPUTE = 'woodev_fake_seller_recompute_prices';
	private const REFRESH   = 'woodev_fake_seller_refresh_prices';

	/**
	 * The export side.
	 *
	 * @var Fake_Price_Context
	 */
	private $contexts;

	/**
	 * The marketplace's prices.
	 *
	 * @var Fake_Price_Reader
	 */
	private $reader;

	/**
	 * Shop prices of the product doubles: product id => `[ regular, sale, sale from, sale to, saves ]`.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $shop = [];

	/**
	 * Builds the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->contexts = new Fake_Price_Context();
		$this->reader   = new Fake_Price_Reader();
		$this->shop     = [];
	}

	/**
	 * Saving a linked product with a changed price property wants the whole payload; the same payload again adds no revision;
	 * a save that touches no price property is ignored.
	 *
	 * @return void
	 */
	public function test_a_saved_product_records_the_whole_payload_once(): void {
		$product = $this->priced( 'a', '500.00', '700.00' );
		$link_id = $this->link_id( $product );

		$this->recorder()->on_product_updated( $this->products[ $product ], [ 'sale_price', 'price' ] );

		$pending = $this->intents()->pending_of( $this->account_id, $link_id, 0, 'price' );
		$this->assertSame(
			[
				'price'         => '500.00',
				'old_price'     => '700.00',
				'min_price'     => null,
				'currency_code' => 'RUB',
			],
			$pending['payload']
		);
		$this->assertSame( 1, $pending['rev'] );

		$this->recorder()->on_product_updated( $this->products[ $product ], [ 'regular_price' ] );
		$this->assertSame( 1, $this->intents()->pending_of( $this->account_id, $link_id, 0, 'price' )['rev'], 'the same payload does not inflate the revision' );

		$other = $this->priced( 'b', '100.00' );
		$this->recorder()->on_product_updated( $this->products[ $other ], [ 'stock_quantity', 'name' ] );
		$this->recorder()->on_product_updated( 'not a product', [ 'price' ] );

		$this->assertNull( $this->intents()->pending_of( $this->account_id, $this->link_id( $other ), 0, 'price' ), 'a save that touches no price property is ignored' );
		$this->assertSame( [], $this->market->requests, 'request time calls nothing remote' );
	}

	/**
	 * The recorder listens to WooCommerce's product save with the changed properties.
	 *
	 * @return void
	 */
	public function test_the_recorder_is_hooked_to_the_product_save(): void {
		$recorder = $this->recorder();
		$recorder->register();

		$this->assertSame( 10, has_action( 'woocommerce_product_object_updated_props', [ $recorder, 'on_product_updated' ] ) );
	}

	/**
	 * Nothing is wanted for a card that is not created, a link with price sync off, or an account that does not push prices.
	 *
	 * @return void
	 */
	public function test_nothing_is_wanted_without_a_created_card_or_a_price_switch(): void {
		$waiting = $this->link_of( $this->priced( 'a-1', '100.00', null, [ 'state' => 'pending_remote_ids' ] ) );
		$muted   = $this->link_of( $this->priced( 'a-2', '100.00', null, [ 'sync_price' => false ] ) );
		$active  = $this->link_of( $this->priced( 'a-3', '100.00' ) );
		$account = $this->account_now();

		$this->assertSame( Price_Intents::NOT_CREATED, $this->recorder()->record_for_link( $account, $waiting ) );
		$this->assertSame( Price_Intents::OFF, $this->recorder()->record_for_link( $account, $muted ) );
		$this->assertSame( Price_Intents::RECORDED, $this->recorder()->record_for_link( $account, $active ) );

		foreach ( [ 'from_marketplace', 'off' ] as $direction ) {
			$this->set_account( [ 'price_direction' => $direction ] );

			$this->assertSame( Price_Intents::OFF, $this->recorder()->record_for_link( $this->account_now(), $active ), $direction . ': the account pushes nothing' );
			$this->assertSame( 0, $this->recorder()->recompute_page( $this->account_now(), 0, 100 ) );
		}
	}

	/**
	 * A product without a price, or one that is gone, wants nothing; a rule that cannot be read wants nothing and is logged.
	 *
	 * @return void
	 */
	public function test_a_price_that_cannot_be_worked_out_is_not_wanted(): void {
		$free   = $this->priced( 'free', '100.00' );
		$broken = $this->priced( 'broken', '100.00' );
		$gone   = $this->priced( 'gone', '100.00' );

		$this->contexts->pairs[ $free ]   = null;
		$this->contexts->pairs[ $broken ] = new \InvalidArgumentException( 'the shop and the cabinet differ in currency' );
		unset( $this->contexts->products[ $gone ] );

		$account = $this->account_now();

		$this->assertSame( Price_Intents::NO_PRICE, $this->recorder()->record_for_link( $account, $this->link_of( $free ) ) );
		$this->assertSame( Price_Intents::INVALID, $this->recorder()->record_for_link( $account, $this->link_of( $broken ) ) );
		$this->assertSame( Price_Intents::NO_PRICE, $this->recorder()->record_for_link( $account, $this->link_of( $gone ) ) );
		$this->assertNull( $this->price_row( $broken ) );
		$this->assertCount( 1, $this->log );
		$this->assertStringContainsString( 'differ in currency', $this->log[0] );
	}

	/**
	 * A failure while a product save is recorded never reaches WooCommerce's save: it is logged.
	 *
	 * @return void
	 */
	public function test_a_failure_in_the_save_hook_is_logged_not_thrown(): void {
		$product = $this->priced( 'a', '100.00' );

		$this->db->query( 'DROP TABLE ' . Sqlite_Tables::table( 'sync_state' ) );
		$this->recorder()->on_product_updated( $this->products[ $product ], [ 'price' ] );

		$this->assertCount( 1, $this->log );
		$this->assertStringContainsString( 'price intent after a product save failed', $this->log[0] );
	}

	/**
	 * While the guard is up the saved-product hook records nothing; outside it, it records. An exception inside guarded work
	 * still drops the guard.
	 *
	 * @return void
	 */
	public function test_a_guarded_save_does_not_echo_back(): void {
		$product = $this->priced( 'a', '100.00' );

		$inside = Price_Refresh_Guard::run(
			function () use ( $product ): bool {
				$this->recorder()->on_product_updated( $this->products[ $product ], [ 'price' ] );

				return Price_Refresh_Guard::is_active();
			}
		);

		$this->assertTrue( $inside );
		$this->assertFalse( Price_Refresh_Guard::is_active() );
		$this->assertNull( $this->price_row( $product ) );

		try {
			Price_Refresh_Guard::run(
				static function (): void {
					throw new \RuntimeException( 'save failed' );
				}
			);
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}

		$this->assertFalse( Price_Refresh_Guard::is_active() );

		$this->recorder()->on_product_updated( $this->products[ $product ], [ 'price' ] );
		$this->assertNotNull( $this->price_row( $product ) );
	}

	/**
	 * A link's override is stored and its price wanted at once.
	 *
	 * @return void
	 */
	public function test_a_link_override_is_stored_and_wanted(): void {
		$product = $this->priced( 'a', '100.00' );

		$outcome = $this->recorder()->change_override( $this->account_now(), $this->link_of( $product ), [ 'price' => [ 'kind' => 'percent', 'value' => 20 ] ] );

		$this->assertSame( Price_Intents::RECORDED, $outcome );
		$this->assertSame( '20', (string) $this->link_of( $product )->price_rule_override()['price']['value'] );
		$this->assertNotNull( $this->price_row( $product ) );
	}

	/**
	 * The recompute works through the account's links one page at a time and schedules itself with the cursor.
	 *
	 * @return void
	 */
	public function test_the_recompute_job_pages_through_the_links(): void {
		$products = [];

		for ( $i = 1; $i <= Price_Scheduler::RECOMPUTE_PAGE + 5; $i++ ) {
			$products[] = $this->priced( 'bulk-' . $i, '100.00' );
		}

		$scheduler = new Price_Scheduler( $this->names() );
		$scheduler->queue_recompute( $this->account_id );
		$scheduler->queue_recompute( $this->account_id );

		$this->assertSame( [ 'async:' . self::RECOMPUTE . ':pending' ], $this->scheduler->summary(), 'a request that waits unstarted covers the second one' );
		$this->assertSame( [ $this->account_id, 0 ], $this->scheduler->actions[0]['args'] );

		$runner = $this->recompute_runner();
		$runner->run( $this->account_id, 0 );

		$cursor = $this->link_id( $products[ Price_Scheduler::RECOMPUTE_PAGE - 1 ] );
		$this->assertSame( [ $this->account_id, $cursor ], $this->scheduler->actions[1]['args'] );
		$this->assertNotNull( $this->price_row( $products[0] ) );
		$this->assertNull( $this->price_row( end( $products ) ) );

		$runner->run( $this->account_id, $cursor );

		$this->assertNotNull( $this->price_row( end( $products ) ) );
		$this->assertCount( 2, $this->scheduler->actions, 'the last page schedules nothing' );
		$this->assertSame( 10, $this->hooked( $runner ) );
	}

	/**
	 * A recompute waits out the licence and the pause with its cursor, and ends silently for a disabled account.
	 *
	 * @return void
	 */
	public function test_a_recompute_waits_for_the_gate_with_its_cursor(): void {
		$this->priced( 'a', '100.00' );

		$this->licensed = false;
		$this->recompute_runner()->run( $this->account_id, 40 );

		$this->assertSame( [ 'single:' . self::RECOMPUTE . ':pending' ], $this->scheduler->summary() );
		$this->assertSame( [ $this->account_id, 40 ], $this->scheduler->actions[0]['args'] );
		$this->assertEqualsWithDelta( 3600, $this->scheduler->actions[0]['delay'], 2 );

		$this->accounts()->set_enabled( $this->account_id, false );
		$this->recompute_runner()->run( $this->account_id, 0 );

		$this->assertCount( 1, $this->scheduler->actions );
	}

	/**
	 * A recompute page that fails is run again with the same cursor on the ladder, not abandoned together with every later
	 * page; the fifth failure gives up with a log line.
	 *
	 * @return void
	 */
	public function test_a_failed_recompute_page_is_retried_from_its_cursor(): void {
		$first  = $this->priced( 'a', '100.00' );
		$cursor = $this->link_id( $first );
		$this->priced( 'b', '100.00' );
		$this->db->query( 'DROP TABLE ' . Sqlite_Tables::table( 'sync_state' ) );

		$this->recompute_runner()->run( $this->account_id, $cursor );

		$this->assertSame( [ $this->account_id, $cursor, 1 ], $this->scheduler->actions[0]['args'] );
		$this->assertEqualsWithDelta( 60, $this->scheduler->actions[0]['delay'], 2 );

		$this->recompute_runner()->run( $this->account_id, $cursor, 3 );
		$this->assertSame( [ $this->account_id, $cursor, 4 ], $this->scheduler->actions[1]['args'] );
		$this->assertEqualsWithDelta( 900, $this->scheduler->actions[1]['delay'], 2 );

		$this->recompute_runner()->run( $this->account_id, $cursor, 4 );

		$this->assertCount( 2, $this->scheduler->actions, 'the fifth failure schedules nothing' );
		$this->assertStringContainsString( 'gave up after link ' . $cursor, end( $this->log ) );
	}

	/**
	 * The marketplace's prices come back into the product: its sale is mirrored, the save runs under the guard, and a product
	 * that already has those prices is not saved again.
	 *
	 * @return void
	 */
	public function test_prices_come_back_and_the_save_runs_under_the_guard(): void {
		$this->set_account( [ 'price_direction' => 'from_marketplace' ] );
		$product                   = $this->priced( 'a', '100.00' );
		$this->reader->prices['a'] = $this->pair( '450.00', '600.00' );

		$result = $this->refresher()->refresh( $this->account_now(), [ 'a' ] );

		$this->assertSame( [ 'a' ], $result->updated() );
		$this->assertSame( 'Цены на сайте обновлены: 1', $result->message() );
		$this->assertSame( [ '600.00', '450.00' ], [ $this->shop[ $product ]['regular'], $this->shop[ $product ]['sale'] ] );
		$this->assertSame( [ true ], $this->shop[ $product ]['guarded'], 'the save ran under the guard' );
		$this->assertSame( [ [ 'a' ] ], $this->reader->reads );

		$again = $this->refresher()->refresh( $this->account_now(), [ 'a' ] );

		$this->assertSame( [ 'a' ], $again->unchanged() );
		$this->assertSame( 'Нечего обновлять (цены совпадают)', $again->message() );
		$this->assertCount( 1, $this->shop[ $product ]['guarded'], 'an unchanged product is not saved' );
	}

	/**
	 * An old price of 0 (or not above the price) ends the sale: regular = the price, sale cleared.
	 *
	 * @return void
	 */
	public function test_a_zero_old_price_clears_the_sale(): void {
		$this->set_account( [ 'price_direction' => 'from_marketplace' ] );
		$product                         = $this->priced( 'a', '100.00' );
		$this->shop[ $product ]['regular'] = '600.00';
		$this->shop[ $product ]['sale']    = '450.00';
		$this->reader->prices['a']       = $this->pair( '480.00', null );

		$this->refresher()->refresh( $this->account_now(), [ 'a' ] );

		$this->assertSame( [ '480.00', '' ], [ $this->shop[ $product ]['regular'], $this->shop[ $product ]['sale'] ] );
	}

	/**
	 * A sale schedule is cleared even when the amounts match: a start date in the future would keep selling at the regular
	 * price, an end date would later end the mirrored sale.
	 *
	 * @return void
	 */
	public function test_a_sale_schedule_is_cleared_even_when_the_amounts_match(): void {
		$this->set_account( [ 'price_direction' => 'from_marketplace' ] );
		$product                         = $this->priced( 'a', '100.00' );
		$this->shop[ $product ]['regular'] = '600.00';
		$this->shop[ $product ]['sale']    = '450.00';
		$this->shop[ $product ]['from']    = '2027-01-01';
		$this->reader->prices['a']       = $this->pair( '450.00', '600.00' );

		$result = $this->refresher()->refresh( $this->account_now(), [ 'a' ] );

		$this->assertSame( [ 'a' ], $result->updated() );
		$this->assertSame( [ '', '' ], [ $this->shop[ $product ]['from'], $this->shop[ $product ]['to'] ] );
	}

	/**
	 * Numeric articles keep their identity: PHP turns the key "12345" into an integer.
	 *
	 * @return void
	 */
	public function test_numeric_articles_are_read_and_refreshed(): void {
		$this->set_account( [ 'price_direction' => 'from_marketplace' ] );
		$product                       = $this->priced( '12345', '100.00' );
		$this->reader->prices['12345'] = $this->pair( '300.00', null );

		$result = $this->refresher()->refresh( $this->account_now(), [ '12345', '12345' ] );

		$this->assertSame( [ '12345' ], $result->updated() );
		$this->assertSame( [ [ '12345' ] ], $this->reader->reads, 'one article, asked for once, as text' );
		$this->assertSame( '300.00', $this->shop[ $product ]['regular'] );
	}

	/**
	 * What cannot be inverted or does not apply is skipped with a reason, and nothing is written.
	 *
	 * @return void
	 */
	public function test_links_that_cannot_be_refreshed_are_skipped_with_a_reason(): void {
		$this->set_account( [ 'price_direction' => 'from_marketplace' ] );

		$muted    = $this->priced( 'muted', '100.00', null, [ 'sync_price' => false ] );
		$waiting  = $this->priced( 'waiting', '100.00', null, [ 'state' => 'pending_remote_ids' ] );
		$deleted  = $this->priced( 'deleted', '100.00' );
		$silent   = $this->priced( 'silent', '100.00' );
		$foreign  = $this->priced( 'foreign', '100.00' );
		$absolute = $this->priced( 'absolute', '100.00' );
		$broken   = $this->priced( 'broken', '100.00' );
		$zero     = $this->priced( 'zero', '100.00' );

		unset( $this->contexts->products[ $deleted ] );
		// The double of the export side reads these two shapes; the link store would refuse to write them, so they go in directly.
		$this->db->query( 'UPDATE ' . Sqlite_Tables::table( 'links' ) . " SET price_rule_override = '{\"absolute\":\"999\"}' WHERE link_id = " . $this->link_id( $absolute ) );
		$this->db->query( 'UPDATE ' . Sqlite_Tables::table( 'links' ) . " SET price_rule_override = '{\"broken\":true}' WHERE link_id = " . $this->link_id( $broken ) );

		foreach ( [ 'muted', 'waiting', 'deleted', 'absolute', 'broken' ] as $offer ) {
			$this->reader->prices[ $offer ] = $this->pair( '300.00', null );
		}

		$this->reader->prices['foreign'] = new Price_Pair( Money::from_string( '30.00', 'CNY' ) );
		$this->reader->prices['zero']    = new Price_Pair( Money::zero( 'RUB' ) );

		$result  = $this->refresher()->refresh( $this->account_now(), [ 'stranger', 'muted', 'waiting', 'deleted', 'silent', 'foreign', 'absolute', 'broken', 'zero' ] );
		$skipped = $result->skipped();

		$this->assertSame( [], $result->updated() );
		$this->assertSame( [ 'stranger', 'muted', 'waiting', 'deleted', 'silent', 'foreign', 'absolute', 'broken', 'zero' ], array_map( 'strval', array_keys( $skipped ) ) );
		$this->assertSame( 'Товар не связан с карточкой маркетплейса.', $skipped['stranger'] );
		$this->assertSame( 'Для товара выключена синхронизация цен.', $skipped['muted'] );
		$this->assertSame( 'Карточка на маркетплейсе ещё не создана.', $skipped['waiting'] );
		$this->assertSame( 'Товар удалён с сайта.', $skipped['deleted'] );
		$this->assertSame( 'Маркетплейс не вернул цену этого товара.', $skipped['silent'] );
		$this->assertSame( 'Маркетплейс хранит цену в другой валюте (CNY), чем кабинет (RUB).', $skipped['foreign'] );
		$this->assertStringContainsString( 'обратный расчёт невозможен', $skipped['absolute'] );
		$this->assertStringContainsString( 'the override is not a rule', $skipped['broken'] );
		$this->assertStringContainsString( 'нулевой', $skipped['zero'] );
		$this->assertStringContainsString( 'пропущено: 9 (Товар не связан с карточкой маркетплейса.)', $result->message() );
		$this->assertSame( [ [ 'deleted', 'silent', 'foreign', 'absolute', 'broken', 'zero' ] ], $this->reader->reads, 'only what may be refreshed is read' );

		foreach ( [ $muted, $waiting, $silent, $foreign, $absolute, $broken, $zero ] as $product ) {
			$this->assertSame( [], $this->shop[ $product ]['guarded'], 'nothing is written' );
		}
	}

	/**
	 * Without a currency rule, a cabinet in another currency than the shop cannot be turned into shop prices; a rule that
	 * cannot be read says so.
	 *
	 * @return void
	 */
	public function test_a_currency_gap_or_an_unreadable_rule_is_skipped(): void {
		$this->set_account( [ 'price_direction' => 'from_marketplace' ] );
		$product                   = $this->priced( 'a', '100.00' );
		$this->reader->prices['a'] = $this->pair( '300.00', null );

		$this->contexts->currency = Price_Currency::for_product( 'RUB', 'CNY', '', $this->products[ $product ] );
		$gap                      = $this->refresher()->refresh( $this->account_now(), [ 'a' ] );

		$this->assertSame( [ 'a' => 'Валюта магазина и валюта кабинета разные, а правила валюты в профиле нет.' ], $gap->skipped() );

		$this->contexts->terms_failure = new \InvalidArgumentException( 'the rate is not a number' );
		$unreadable                    = $this->refresher()->refresh( $this->account_now(), [ 'a' ] );

		$this->assertSame( [ 'a' => 'Правило цены, курс или своя цена не читаются: the rate is not a number' ], $unreadable->skipped() );
		$this->assertSame( [], $this->shop[ $product ]['guarded'] );
	}

	/**
	 * An account that pushes its prices, or has prices off, never takes them from the marketplace, and the read is not made.
	 *
	 * @return void
	 */
	public function test_an_account_that_does_not_take_prices_reads_nothing(): void {
		$this->priced( 'a', '100.00' );

		foreach ( [ 'to_marketplace', 'off' ] as $direction ) {
			$this->set_account( [ 'price_direction' => $direction ] );

			$result = $this->refresher()->refresh( $this->account_now(), [ 'a' ] );

			$this->assertSame( 'В настройках аккаунта цены не берутся с маркетплейса.', $result->message() );
		}

		$this->assertSame( [], $this->reader->reads );
	}

	/**
	 * Refresh batches are queued 100 articles at a time, each article once.
	 *
	 * @return void
	 */
	public function test_refresh_batches_hold_100_articles(): void {
		$offers = [];

		for ( $i = 1; $i <= 205; $i++ ) {
			$offers[] = 'offer-' . $i;
		}

		$queued = ( new Price_Scheduler( $this->names() ) )->queue_refresh( $this->account_id, array_merge( $offers, [ 'offer-1' ] ) );

		$this->assertSame( 3, $queued );
		$this->assertSame( [ 100, 100, 5 ], array_map( 'count', array_column( array_column( $this->scheduler->of( self::REFRESH ), 'args' ), 1 ) ) );
		$this->assertSame( 'woodev-fake-seller', $this->scheduler->actions[0]['group'] );
	}

	/**
	 * A ready account is read; a disabled account ends the job silently; an account behind the gate waits with its batch.
	 *
	 * @return void
	 */
	public function test_a_refresh_reads_a_ready_account_and_respects_the_gate(): void {
		$this->set_account( [ 'price_direction' => 'from_marketplace' ] );
		$this->priced( 'a', '100.00' );
		$this->reader->prices['a'] = $this->pair( '300.00', null );

		$runner = $this->refresh_runner();
		$runner->register();
		$runner->run( $this->account_id, [ 'a' ] );

		$this->assertSame( [ [ 'a' ] ], $this->reader->reads );
		$this->assertSame( 10, has_action( self::REFRESH, [ $runner, 'run' ] ) );

		$this->licensed = false;
		$this->refresh_runner()->run( $this->account_id, [ 'a' ], 2 );

		$this->assertSame( [ $this->account_id, [ 'a' ], 2 ], $this->scheduler->actions[0]['args'] );
		$this->assertEqualsWithDelta( 3600, $this->scheduler->actions[0]['delay'], 2 );

		$this->licensed = true;
		$this->accounts()->set_enabled( $this->account_id, false );
		$this->refresh_runner()->run( $this->account_id, [ 'a' ] );
		$this->refresh_runner()->run( $this->account_id, [] );

		$this->assertCount( 1, $this->reader->reads, 'a disabled account is not read' );
		$this->assertCount( 1, $this->scheduler->actions );
	}

	/**
	 * A refused read follows the consequence: refused credentials pause the account and the batch waits the pause out; a wait
	 * is not a failed attempt; an uncertain failure retries on the ladder and gives up after five attempts; a permanent refusal
	 * is logged and dropped.
	 *
	 * @return void
	 */
	public function test_a_refused_refresh_follows_the_consequence(): void {
		$this->set_account( [ 'price_direction' => 'from_marketplace' ] );
		$this->priced( 'a', '100.00' );

		$this->reader->failures[] = Fake_Remote_Exception::of( Remote_Error::THROTTLE, 'slow down', 45 );
		$this->refresh_runner()->run( $this->account_id, [ 'a' ] );

		$this->reader->failures[] = Fake_Remote_Exception::of( Remote_Error::RETRY, 'bad gateway' );
		$this->refresh_runner()->run( $this->account_id, [ 'a' ], 1 );

		$this->reader->failures[] = Fake_Remote_Exception::of( Remote_Error::RETRY, 'bad gateway' );
		$this->refresh_runner()->run( $this->account_id, [ 'a' ], 4 );

		$this->reader->failures[] = Fake_Remote_Exception::of( Remote_Error::FAIL, 'unknown filter' );
		$this->refresh_runner()->run( $this->account_id, [ 'a' ] );

		$this->reader->failures[] = Fake_Remote_Exception::of( Remote_Error::PAUSE_ACCOUNT, 'key revoked' );
		$this->refresh_runner()->run( $this->account_id, [ 'a' ] );

		$actions = $this->scheduler->of( self::REFRESH );

		$this->assertCount( 3, $actions, 'the fifth uncertain failure and the permanent refusal schedule nothing' );
		$this->assertSame( [ [ $this->account_id, [ 'a' ], 0 ], [ $this->account_id, [ 'a' ], 2 ], [ $this->account_id, [ 'a' ], 0 ] ], array_column( $actions, 'args' ) );
		$this->assertEqualsWithDelta( 45, $actions[0]['delay'], 2 );
		$this->assertEqualsWithDelta( 180, $actions[1]['delay'], 2 );
		$this->assertEqualsWithDelta( Account_Pause::SECONDS, $actions[2]['delay'], 2 );
		$this->assertSame( [ $this->account_id ], $this->classifier->refused );
		$this->assertNotSame( [], preg_grep( '/gave up on 1 articles after 5 attempts/', $this->log ) );
	}

	/**
	 * A failure that is not a marketplace answer — the marketplace answering with an article nobody asked for, a database
	 * error while a product is saved — is logged and the batch is retried whole.
	 *
	 * @return void
	 */
	public function test_a_failure_that_is_not_an_answer_is_retried(): void {
		$this->set_account( [ 'price_direction' => 'from_marketplace' ] );
		$this->priced( 'a', '100.00' );

		$this->reader->failures[] = new \UnexpectedValueException( 'the answer holds an article that was not asked for' );
		$this->refresh_runner()->run( $this->account_id, [ 'a' ] );

		$this->assertSame( [ [ $this->account_id, [ 'a' ], 1 ] ], array_column( $this->scheduler->of( self::REFRESH ), 'args' ) );
		$this->assertStringContainsString( 'UnexpectedValueException', $this->log[0] );
	}

	/**
	 * Creates a product with a created card and price sync on; the export side wants the given prices for it.
	 *
	 * @param string              $offer     Article.
	 * @param string              $price     Wanted price.
	 * @param string|null         $old_price Wanted old price.
	 * @param array<string,mixed> $options   Link options.
	 * @return int Product id.
	 */
	private function priced( string $offer, string $price, ?string $old_price = null, array $options = [] ): int {
		static $remote_id = 7000;

		$id                    = $this->product( 10 );
		$this->shop[ $id ]     = [
			'regular' => '',
			'sale'    => '',
			'from'    => '',
			'to'      => '',
			'guarded' => [],
		];
		$product               = $this->products[ $id ];

		foreach ( [ 'regular' => 'regular_price', 'sale' => 'sale_price', 'from' => 'date_on_sale_from', 'to' => 'date_on_sale_to' ] as $key => $prop ) {
			$product->shouldReceive( 'get_' . $prop )->andReturnUsing(
				function () use ( $id, $key ) {
					return $this->shop[ $id ][ $key ];
				}
			);
			$product->shouldReceive( 'set_' . $prop )->andReturnUsing(
				function ( $value ) use ( $id, $key ): void {
					$this->shop[ $id ][ $key ] = (string) $value;
				}
			);
		}

		$product->shouldReceive( 'save' )->andReturnUsing(
			function () use ( $id ): int {
				$this->shop[ $id ]['guarded'][] = Price_Refresh_Guard::is_active();

				return $id;
			}
		);

		$this->links()->save(
			new \Woodev\Framework\Marketplace\Links\Product_Link(
				$this->account_id,
				$id,
				0,
				$offer,
				[ 'item' => ++$remote_id ],
				$options['state'] ?? \Woodev\Framework\Marketplace\Links\Product_Link::STATE_CREATED,
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
	 * Builds the wish recorder (a fresh one reads the accounts again).
	 *
	 * @return Price_Intents
	 */
	private function recorder(): Price_Intents {
		return new Price_Intents( $this->accounts(), Sqlite_Tables::MARKETPLACE_ID, $this->links(), $this->contexts, $this->intents(), [ $this, 'log_line' ] );
	}

	/**
	 * Builds the refresher.
	 *
	 * @return Price_Refresher
	 */
	private function refresher(): Price_Refresher {
		return new Price_Refresher( $this->reader, $this->links(), $this->contexts );
	}

	/**
	 * Builds the recompute job.
	 *
	 * @return Price_Recompute_Runner
	 */
	private function recompute_runner(): Price_Recompute_Runner {
		return new Price_Recompute_Runner( $this->job_gate(), $this->recorder(), new Price_Scheduler( $this->names() ), [ $this, 'log_line' ] );
	}

	/**
	 * Builds the refresh job.
	 *
	 * @return Price_Refresh_Runner
	 */
	private function refresh_runner(): Price_Refresh_Runner {
		return new Price_Refresh_Runner( $this->job_gate(), $this->refresher(), new Price_Scheduler( $this->names() ), $this->classifier, [ $this, 'log_line' ] );
	}

	/**
	 * Hooks a recompute runner and returns the priority it was hooked with.
	 *
	 * @param Price_Recompute_Runner $runner Runner.
	 * @return int|false
	 */
	private function hooked( Price_Recompute_Runner $runner ) {
		$runner->register();

		return has_action( self::RECOMPUTE, [ $runner, 'run' ] );
	}
}
