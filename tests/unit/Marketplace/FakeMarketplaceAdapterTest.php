<?php
/**
 * Tests that the common interfaces hold against Wildberries shapes.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace;

use PHPUnit\Framework\TestCase;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Account\Account_Store;
use Woodev\Framework\Marketplace\Account\Credential_Health;
use Woodev\Framework\Marketplace\Account\Health_Result;
use Woodev\Framework\Marketplace\Capabilities\Capability;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Catalog\Card_Exporter;
use Woodev\Framework\Marketplace\Catalog\Catalog_Schema;
use Woodev\Framework\Marketplace\Catalog\Mapping;
use Woodev\Framework\Marketplace\Catalog\Profile;
use Woodev\Framework\Marketplace\Governor\Bucket_Spec;
use Woodev\Framework\Marketplace\Governor\Request_Governor;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Framework\Marketplace\Ledger\Event_Ledger;
use Woodev\Framework\Marketplace\Ledger\Inbound_Decoder;
use Woodev\Framework\Marketplace\Links\Link_Store;
use Woodev\Framework\Marketplace\Links\Product_Link;
use Woodev\Framework\Marketplace\Orders\Order_DTO;
use Woodev\Framework\Marketplace\Orders\Order_Source;
use Woodev\Framework\Marketplace\Orders\Order_Writer;
use Woodev\Framework\Marketplace\Price\Money;
use Woodev\Framework\Marketplace\Price\Price_Calculator;
use Woodev\Framework\Marketplace\Price\Price_Line;
use Woodev\Framework\Marketplace\Price\Price_Pusher;
use Woodev\Framework\Marketplace\Price\Price_Rule;
use Woodev\Framework\Marketplace\Stock\Stock_Line;
use Woodev\Framework\Marketplace\Stock\Stock_Pusher;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Account_Store;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Clock;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Event_Ledger;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Inbound_Decoder;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Link_Store;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Order_Writer;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Request_Governor;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace_Adapter;

/**
 * Card W1-1 acceptance: the fake adapter satisfies every interface of §7.2, and each interface holds when the other
 * marketplace is not Ozon. Every test names the Wildberries fact it exercises.
 */
final class FakeMarketplaceAdapterTest extends TestCase {
	/**
	 * Adapter under test.
	 *
	 * @var Fake_Marketplace_Adapter
	 */
	private $adapter;

	/**
	 * Account with a Wildberries-style token.
	 *
	 * @var Account
	 */
	private $account;

	/**
	 * Creates an adapter and an account.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->adapter = new Fake_Marketplace_Adapter();
		$this->account = $this->account( true );
	}

	/**
	 * Builds the account.
	 *
	 * @param bool $enabled Merchant's switch.
	 * @return Account
	 */
	private function account( bool $enabled ): Account {
		$expires = $this->adapter->clock()->now()->modify( '+180 days' );

		return new Account(
			1,
			$this->adapter->id(),
			'WB shop',
			$enabled,
			array(
				'token'       => 'eyJhbGciOiJFUzI1NiJ9.secret.signature',
				'supplier_id' => '987654321',
			),
			$expires
		);
	}

	/**
	 * Builds a link with the given remote ids.
	 *
	 * @param int                      $id         Link id.
	 * @param array<string,int|string> $remote_ids Remote ids.
	 * @param string                   $state      State.
	 * @return Product_Link
	 */
	private function link( int $id, array $remote_ids = array(), string $state = Product_Link::STATE_CREATED ): Product_Link {
		return new Product_Link( 1, 100 + $id, 0, 'SKU-' . $id, $remote_ids, $state, array( 'sync_stock' => true ), null, $id );
	}

	/**
	 * The adapter hands out an implementation of every contract the layer defines for it.
	 *
	 * @return void
	 */
	public function test_the_adapter_satisfies_every_interface_it_must_hand_out(): void {
		$this->assertInstanceOf( Marketplace_Adapter::class, $this->adapter );
		$this->assertInstanceOf( Credential_Health::class, $this->adapter->credential_health() );
		$this->assertInstanceOf( Stock_Pusher::class, $this->adapter->stock_pusher() );
		$this->assertInstanceOf( Price_Pusher::class, $this->adapter->price_pusher() );
		$this->assertInstanceOf( Order_Source::class, $this->adapter->order_source() );
		$this->assertInstanceOf( Catalog_Schema::class, $this->adapter->catalog_schema() );
		$this->assertInstanceOf( Card_Exporter::class, $this->adapter->card_exporter() );
		$this->assertContainsOnlyInstancesOf( Bucket_Spec::class, $this->adapter->bucket_specs() );
		$this->assertNotEmpty( $this->adapter->support_sections( $this->account ) );

		$clock = $this->adapter->clock();
		foreach ( array(
			Account_Store::class    => new Fake_Account_Store(),
			Link_Store::class       => new Fake_Link_Store(),
			Request_Governor::class => new Fake_Request_Governor( $clock, $this->adapter->bucket_specs() ),
			Event_Ledger::class     => new Fake_Event_Ledger( $clock ),
			Inbound_Decoder::class  => new Fake_Inbound_Decoder( $clock ),
			Order_Writer::class     => new Fake_Order_Writer(),
		) as $interface => $service ) {
			$this->assertInstanceOf( $interface, $service );
		}
	}

	/**
	 * Capabilities: Wildberries has no push and no inbound stock; the decoder is null (§7.3 «Event_Ledger + Inbound_Decoder»).
	 *
	 * @return void
	 */
	public function test_a_marketplace_without_push_says_so(): void {
		$this->assertFalse( $this->adapter->supports( Capability::PUSH_NOTIFICATIONS ) );
		$this->assertFalse( $this->adapter->supports( Capability::INBOUND_STOCK ) );
		$this->assertTrue( $this->adapter->supports( Capability::CANCEL_ORDER ) );
		$this->assertNull( $this->adapter->inbound_decoder() );
		$this->assertContains( Capability::PUSH_NOTIFICATIONS, Capability::all() );
	}

	/**
	 * One JWT token, no Client-Id; the 180-day expiry is first-class and an expired token is reported, not hidden
	 * (§7.3 «Account + Credential_Health»).
	 *
	 * @return void
	 */
	public function test_credential_health_reports_token_expiry(): void {
		$health = $this->adapter->credential_health();

		$result = $health->check( $this->account );
		$this->assertTrue( $result->is_ok() );
		$this->assertSame( '2027-05-07', $result->expires_at()->format( 'Y-m-d' ) );

		$this->adapter->clock()->advance( 181 * 86400 );
		$this->assertSame( Health_Result::KEY_EXPIRED, $health->check( $this->account )->status() );

		$blank = new Account( 2, 'fake_wildberries', 'No token', true );
		$this->assertSame( Health_Result::AUTH_FAILED, $health->check( $blank )->status() );
	}

	/**
	 * A failed health check is stored without switching the merchant's account off (v1 disabled it on a timeout).
	 *
	 * @return void
	 */
	public function test_health_failure_never_flips_the_merchants_switch(): void {
		$store = new Fake_Account_Store();
		$id    = $store->save( new Account( 0, 'fake_wildberries', 'WB shop', true, array(), null ) );
		$store->set_health( $id, new Health_Result( Health_Result::UNREACHABLE, 'timeout' ) );

		$this->assertTrue( $store->get( $id )->is_enabled() );
		$this->assertSame( Health_Result::UNREACHABLE, $store->health_of( $id )->status() );
		$this->assertCount( 1, $store->all( 'fake_wildberries', true ) );
		$this->assertCount( 0, $store->all( 'ozon', false ) );
	}

	/**
	 * The support report masks the token whatever the adapter put in the section (§10.1: key never shown).
	 *
	 * @return void
	 */
	public function test_support_report_hides_the_token_and_masks_the_identifier(): void {
		$report = \Woodev\Framework\Marketplace\Diagnostics\Support_Report::build( $this->adapter->support_sections( $this->account ) );

		$this->assertStringNotContainsString( 'eyJhbGci', $report );
		$this->assertStringNotContainsString( 'secret.signature', $report );
		$this->assertStringContainsString( '***4321', $report );
		$this->assertStringNotContainsString( '987654321', $report );
	}

	/**
	 * Stock is keyed by `chrtId` that exists only after the card is created; before that the push is refused per item
	 * (§7.3 «Stock_Pusher», «Product_Link with remote_ids bag»).
	 *
	 * @return void
	 */
	public function test_stock_needs_the_chrt_id_and_reads_back(): void {
		$pusher  = $this->adapter->stock_pusher();
		$waiting = $this->link( 1, array(), Product_Link::STATE_PENDING_REMOTE_IDS );
		$ready   = $this->link( 2, array( 'chrtID' => 9000002 ) );

		$results = $pusher->push( $this->account, 77, array( new Stock_Line( $waiting, 5 ), new Stock_Line( $ready, 7 ) ) );

		$this->assertFalse( $results[1]->is_ok() );
		$this->assertSame( 'chrt_id_missing', $results[1]->code() );
		$this->assertTrue( $results[2]->is_ok() );
		$this->assertSame(
			array(
				2 => array(
					'present'  => 7,
					'reserved' => 0,
				),
			),
			$pusher->read( $this->account, 77, array( $ready ) )
		);
	}

	/**
	 * 1000 items per request: the adapter batches (2500 lines are three requests) and an oversized amount is refused.
	 *
	 * @return void
	 */
	public function test_stock_is_batched_by_one_thousand_and_amounts_are_capped(): void {
		$pusher = $this->adapter->stock_pusher();
		$lines  = array();
		for ( $i = 1; $i <= 2500; $i++ ) {
			$lines[] = new Stock_Line( $this->link( $i, array( 'chrtID' => 9000000 + $i ) ), 1 );
		}

		$pusher->push( $this->account, 77, $lines );
		$this->assertSame( 3, $pusher->request_count() );

		$too_big = $pusher->push( $this->account, 77, array( new Stock_Line( $this->link( 1, array( 'chrtID' => 9000001 ) ), 100001 ) ) );
		$this->assertSame( 'amount_too_large', $too_big[1]->code() );
	}

	/**
	 * The price pusher answers with a pending task; the old remote price is untouched until the task is settled
	 * (§7.3 «Price_Calculator + Price_Pusher»: accepted → pending).
	 *
	 * @return void
	 */
	public function test_price_upload_is_pending_until_settled(): void {
		$pusher = $this->adapter->price_pusher();
		$link   = $this->link( 1, array( 'nmID' => 5000001 ) );
		$pusher->seed_remote_price( '5000001', 80000 );

		$pair    = Price_Calculator::calculate( Money::from_string( '700', 'RUB' ), Money::from_string( '1000', 'RUB' ), new Price_Rule() );
		$receipt = $pusher->submit( $this->account, array( new Price_Line( $link, $pair ) ) );

		$this->assertTrue( $receipt->is_pending() );
		$this->assertSame( array(), $receipt->immediate_results() );
		$this->assertSame( Async_Task::KIND_PRICE, $receipt->pending_task()->kind() );
		$this->assertSame( 80000, $pusher->remote_price( '5000001' ), 'nothing changed before settle()' );

		$results = $pusher->settle( $this->account, $receipt->pending_task() );
		$this->assertTrue( $results[1]->is_ok() );
		$this->assertSame( 70000, $pusher->remote_price( '5000001' ) );
	}

	/**
	 * The price triple maps to Wildberries' integer price and whole-percent discount.
	 *
	 * @return void
	 */
	public function test_price_pair_maps_to_integer_price_and_discount(): void {
		$pair = Price_Calculator::calculate( Money::from_string( '700', 'RUB' ), Money::from_string( '1000', 'RUB' ), new Price_Rule() );

		$this->assertSame(
			array(
				'price'    => 1000,
				'discount' => 30,
			),
			\Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Price_Pusher::to_wb( $pair )
		);
	}

	/**
	 * Accepted → pending → quarantined: a price that drops too far is refused later, the old remote price stays and
	 * the result is `ok = false` (§7.3).
	 *
	 * @return void
	 */
	public function test_a_quarantined_price_keeps_the_old_remote_price(): void {
		$pusher = $this->adapter->price_pusher();
		$link   = $this->link( 1, array( 'nmID' => 5000001 ) );
		$pusher->seed_remote_price( '5000001', 80000 );

		$cheap   = Price_Calculator::calculate( Money::from_string( '200', 'RUB' ), Money::from_string( '1000', 'RUB' ), new Price_Rule() );
		$receipt = $pusher->submit( $this->account, array( new Price_Line( $link, $cheap ) ) );
		$results = $pusher->settle( $this->account, $receipt->pending_task() );

		$this->assertFalse( $results[1]->is_ok() );
		$this->assertSame( 'quarantine', $results[1]->code() );
		$this->assertSame( 80000, $pusher->remote_price( '5000001' ), 'the old price is preserved' );
	}

	/**
	 * HTTP 208: the same upload while the first task is queued reuses that task instead of creating a second one.
	 *
	 * @return void
	 */
	public function test_a_duplicate_upload_reuses_the_queued_task(): void {
		$pusher = $this->adapter->price_pusher();
		$link   = $this->link( 1, array( 'nmID' => 5000001 ) );
		$pair   = Price_Calculator::calculate( Money::from_string( '700', 'RUB' ), Money::from_string( '1000', 'RUB' ), new Price_Rule() );
		$line   = new Price_Line( $link, $pair );

		$first  = $pusher->submit( $this->account, array( $line ) );
		$second = $pusher->submit( $this->account, array( $line ) );

		$this->assertNull( $first->duplicate_of() );
		$this->assertSame( 'price-task-1', $second->duplicate_of() );
		$this->assertSame( $first->pending_task()->remote_task_id(), $second->pending_task()->remote_task_id() );
		$this->assertSame( 1, $pusher->created_task_count() );

		$pusher->settle( $this->account, $first->pending_task() );
		$third = $pusher->submit( $this->account, array( $line ) );
		$this->assertNull( $third->duplicate_of(), 'once settled, the same upload is a new task' );
	}

	/**
	 * Card upload returns no task id; for 30 minutes nothing can be pushed; then the ids arrive and the link becomes
	 * `created` (§7.3 «Card_Exporter», «pending_remote_ids»).
	 *
	 * @return void
	 */
	public function test_card_upload_has_no_task_id_and_a_thirty_minute_window(): void {
		$links = new Fake_Link_Store();
		$id    = $links->save( new Product_Link( 1, 101, 0, 'SKU-1', array(), Product_Link::STATE_PENDING_REMOTE_IDS ) );
		$link  = $links->by_product( 1, 101 );

		$exporter = $this->adapter->card_exporter();
		$task     = $exporter->submit( $this->account, array( $link ), new Mapping( null, array( 'subject_id' => 105 ), null ), new Profile( null, 'Default' ) );

		$this->assertNull( $task->remote_task_id() );
		$this->assertFalse( $task->is_final() );
		$this->assertFalse( $exporter->poll( $this->account, $task )->is_final() );

		$this->adapter->clock()->advance( 1799 );
		$this->assertFalse( $exporter->poll( $this->account, $task )->is_final(), 'still inside the window' );

		$this->adapter->clock()->advance( 1 );
		$status = $exporter->poll( $this->account, $task );
		$this->assertTrue( $status->is_final() );
		$this->assertSame( Async_Task::STATUS_DONE, $status->status() );

		$result = $status->items()[0];
		$this->assertSame( $id, $result->link_id() );
		$this->assertSame( 9000001, $result->remote_ids()['chrtID'] );

		$links->save( $link->with_remote_ids( $result->remote_ids() )->with_state( Product_Link::STATE_CREATED ) );
		$this->assertSame( $id, $links->by_remote_id( 1, 'nmID', '5000001' )->link_id() );
		$this->assertTrue( $links->by_seller_sku( 1, 'SKU-1' )->is_created() );
	}

	/**
	 * A card whose article is missing is reported by the error list after the window; the rest of the batch succeeds.
	 *
	 * @return void
	 */
	public function test_card_errors_arrive_with_the_final_poll(): void {
		$bad  = new Product_Link( 1, 102, 0, '', array(), Product_Link::STATE_PENDING_REMOTE_IDS, array(), null, 2 );
		$good = $this->link( 1, array(), Product_Link::STATE_PENDING_REMOTE_IDS );

		$exporter = $this->adapter->card_exporter();
		$task     = $exporter->submit( $this->account, array( $good, $bad ), new Mapping( null, array(), null ), new Profile( null, 'Default' ) );
		$this->adapter->clock()->advance( 1800 );
		$status = $exporter->poll( $this->account, $task );

		$this->assertSame( Async_Task::STATUS_DONE_WITH_ERRORS, $status->status() );
		$this->assertTrue( $status->items()[0]->is_ok() );
		$this->assertSame( 'empty_vendor_code', $status->items()[1]->code() );
	}

	/**
	 * Orders are poll-only: pages carry a cursor, the window filters by change time, and money stays in kopecks.
	 *
	 * @return void
	 */
	public function test_orders_are_pulled_by_polling_with_a_cursor(): void {
		$source = $this->adapter->order_source();
		foreach ( range( 1, 6 ) as $n ) {
			$changed_at = 1 === $n ? '2026-11-08T09:00:00Z' : '2026-11-08T1' . $n . ':00:00Z';
			$source->publish( Fake_Marketplace_Adapter::wb_order( 1, $n, 'cart-' . $n, 99999 + $n, 'new', 'waiting', $changed_at ) );
		}

		$since  = new \DateTimeImmutable( '2026-11-08T11:00:00Z' );
		$first  = $source->pull_changed( $this->account, $since, null );
		$second = $source->pull_changed( $this->account, $since, $first->next_cursor() );
		$third  = $source->pull_changed( $this->account, $since, $second->next_cursor() );

		$this->assertCount( 2, $first->orders() );
		$this->assertTrue( $first->has_more() );
		$this->assertCount( 2, $second->orders() );
		$this->assertCount( 1, $third->orders(), 'six orders, one before the window: 2 + 2 + 1' );
		$this->assertFalse( $third->has_more() );

		$order = $source->pull_one( $this->account, '2' );
		$this->assertSame( '1000.01', $order->lines()[0]->unit_price()->to_string(), '100001 kopecks is exactly 1000.01' );
		$this->assertNull( $source->pull_one( $this->account, '999' ) );
		$this->assertNull( $source->pull_one( new Account( 2, 'fake_wildberries', 'Other', true ), '2' ), 'another account does not see the order' );
	}

	/**
	 * The polling window compares instants, not text: an offset, a fraction or a trailing `Z` never changes which side of
	 * the window an order falls on (PR #27 critic note on the fake).
	 *
	 * @return void
	 */
	public function test_the_polling_window_compares_instants_not_text(): void {
		$source = $this->adapter->order_source();
		$times  = array(
			1 => '2026-11-08T14:00:00+03:00',     // 11:00:00Z, exactly the start of the window: included.
			2 => '2026-11-08T13:59:59+03:00',     // 10:59:59Z: excluded, although the text sorts after the start.
			3 => '2026-11-08T11:00:00.500Z',      // Half a second into the window: included, although '.' sorts before 'Z'.
			4 => '2026-11-08T10:59:59.999Z',      // A millisecond before: excluded.
			5 => '2026-11-08T09:30:00-02:00',     // 11:30:00Z: included, although the text sorts before the start.
		);
		foreach ( $times as $id => $time ) {
			$source->publish( Fake_Marketplace_Adapter::wb_order( 1, $id, 'cart-' . $id, 10000, 'new', 'waiting', $time ) );
		}
		$source->publish( new Order_DTO( 1, '6', 'cart-6', 'new/waiting', 'new', array() ) );

		$since = new \DateTimeImmutable( '2026-11-08T11:00:00Z' );
		$ids   = array();
		$page  = $source->pull_changed( $this->account, $since, null );
		while ( true ) {
			foreach ( $page->orders() as $order ) {
				$ids[] = $order->external_id();
			}
			if ( ! $page->has_more() ) {
				break;
			}
			$page = $source->pull_changed( $this->account, $since, $page->next_cursor() );
		}

		$this->assertSame( array( '1', '3', '5' ), $ids, 'order 6 has no change time and is left out' );
	}

	/**
	 * The catalogue: parent → subject → characteristics, with a fixed directory behind the dictionary provider
	 * (§7.3 «Catalog_Schema + Field_Definition»).
	 *
	 * @return void
	 */
	public function test_catalog_schema_returns_fields_and_a_fixed_directory(): void {
		$schema   = $this->adapter->catalog_schema();
		$category = $schema->categories()[0];
		$fields   = $schema->fields( $category );

		$this->assertSame( '105', (string) $category['subject_id'] );
		$this->assertCount( 3, $fields );
		$this->assertTrue( $fields[0]->is_required() );
		$this->assertSame( 'kg', $fields[2]->unit() );

		$colour = $fields[1];
		$this->assertTrue( $colour->is_multi() );
		$this->assertCount( 3, $schema->search_dictionary( $colour, '' ) );
		$this->assertCount( 2, $schema->search_dictionary( $colour, 'син' ) );
		$this->assertSame( array(), $schema->search_dictionary( $fields[0], 'anything' ), 'a non-dictionary field has no values' );
	}

	/**
	 * Buckets are data: Wildberries' burst and its «a 4xx costs 10» are numbers in the spec, not governor code
	 * (§7.3 «Request_Governor + Bucket_Spec»).
	 *
	 * @return void
	 */
	public function test_governor_numbers_come_from_bucket_specs(): void {
		$clock    = $this->adapter->clock();
		$governor = new Fake_Request_Governor( $clock, $this->adapter->bucket_specs() );

		for ( $i = 0; $i < 20; $i++ ) {
			$this->assertSame( 0, $governor->acquire( 'a1', 'marketplace' ) );
		}

		$this->assertGreaterThan( 0, $governor->acquire( 'a1', 'marketplace' ), 'burst of 20 is spent' );
		$this->assertSame( 0, $governor->acquire( 'a2', 'marketplace' ), 'another account has its own bucket' );

		$clock->advance( 2 );
		$this->assertSame( 0, $governor->acquire( 'a1', 'marketplace', 10 ), 'five tokens a second: ten tokens after two seconds' );
	}

	/**
	 * A 4xx answer costs ten requests: charged by acquiring `cost_4xx` after the fact.
	 *
	 * @return void
	 */
	public function test_a_4xx_answer_is_charged_ten_requests(): void {
		$specs    = $this->adapter->bucket_specs();
		$governor = new Fake_Request_Governor( $this->adapter->clock(), $specs );
		$cost     = $specs[0]->cost_4xx();

		$this->assertSame( 10, $cost );
		$this->assertSame( 0, $governor->acquire( 'a1', 'marketplace', $cost ) );
		$this->assertSame( 0, $governor->acquire( 'a1', 'marketplace', $cost ), 'two failures spend the 20-token burst' );
		$this->assertGreaterThan( 0, $governor->acquire( 'a1', 'marketplace' ) );
	}

	/**
	 * A 429 with Retry-After empties the bucket for that long; a retired method is remembered.
	 *
	 * @return void
	 */
	public function test_rate_limit_answers_and_retired_methods(): void {
		$clock    = $this->adapter->clock();
		$governor = new Fake_Request_Governor( $clock, $this->adapter->bucket_specs() );

		$governor->report_rate_limited( 'a1', 'prices', 7 );
		$this->assertSame( 7, $governor->acquire( 'a1', 'prices' ) );
		$clock->advance( 7 );
		$this->assertSame( 0, $governor->acquire( 'a1', 'prices' ), 'free again after Retry-After' );

		$this->assertFalse( $governor->is_disabled( 'a1', 'prices' ) );
		$governor->report_disabled( 'a1', 'prices', 'method_retired' );
		$this->assertTrue( $governor->is_disabled( 'a1', 'prices' ) );
		$this->assertFalse( $governor->is_disabled( 'a2', 'prices' ) );
	}

	/**
	 * The ledger contract: duplicates are null, one token wins a batch, an expired lease is taken over and the old
	 * worker's calls are refused (§7.2 «Event_Ledger», R2 finding 7).
	 *
	 * @return void
	 */
	public function test_ledger_claims_are_fenced_by_token(): void {
		$clock   = $this->adapter->clock();
		$ledger  = new Fake_Event_Ledger( $clock );
		$decoder = new Fake_Inbound_Decoder( $clock );

		$one = $decoder->decode( '{"type":"STOCKS_CHANGED","id":"1"}', 1, '10.0.0.1' );
		$two = $decoder->decode( '{"type":"STOCKS_CHANGED","id":"2"}', 1, '10.0.0.1' );
		$this->assertNull( $decoder->decode( 'not json', 1, '10.0.0.1' ) );
		$this->assertSame( array( 'result' => true ), $decoder->success_body() );

		$first_id = $ledger->record( $one );
		$this->assertNotNull( $first_id );
		$this->assertNull( $ledger->record( $one ), 'a re-delivery is a duplicate' );
		$this->assertNotNull( $ledger->record( new \Woodev\Framework\Marketplace\Ledger\Inbound_Event( 2, $one->type(), $one->dedup_key(), array(), $clock->now(), '10.0.0.1', false ) ), 'the same key on another account is a different event' );
		$ledger->record( $two );

		$batch = $ledger->claim( 1, 10 );
		$this->assertCount( 2, $batch->events() );
		$this->assertSame( $first_id, $batch->events()[0]->event_id() );
		$this->assertTrue( $ledger->claim( 1, 10 )->is_empty(), 'leased rows are not claimable' );

		$clock->advance( 121 );
		$takeover = $ledger->claim( 1, 10 );
		$this->assertCount( 2, $takeover->events() );
		$this->assertNotSame( $batch->token(), $takeover->token() );

		$this->assertFalse( $ledger->complete( $first_id, $batch->token(), 'late' ), 'the stale worker lost the row' );
		$this->assertTrue( $ledger->complete( $first_id, $takeover->token(), 'ok' ) );
		$this->assertSame( 'done', $ledger->status_of( $first_id ) );

		$second_id = $takeover->events()[1]->event_id();
		$this->assertTrue( $ledger->retry( $second_id, $takeover->token(), 60, 'busy' ) );
		$this->assertTrue( $ledger->claim( 1, 10 )->is_empty(), 'not due before the delay' );
		$clock->advance( 61 );
		$again = $ledger->claim( 1, 10 );
		$this->assertCount( 1, $again->events() );
		$this->assertTrue( $ledger->fail( $second_id, $again->token(), 'gave up' ) );
	}
}
