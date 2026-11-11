<?php
/**
 * The `link_cards` and `refresh_cards` jobs: gate, claim, matching, linking, the near-match report and the failure ladder.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Jobs;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Catalog\Offer_Id_Likeness;
use Woodev\Framework\Marketplace\Data\Cards_Snapshot_Store;
use Woodev\Framework\Marketplace\Data\Link_Progress_Store;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Link_Report_Store;
use Woodev\Framework\Marketplace\Flows\Card_Linker;
use Woodev\Framework\Marketplace\Flows\Link_Resolve_Exception;
use Woodev\Framework\Marketplace\Jobs\Backoff;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\Link_Jobs;
use Woodev\Framework\Marketplace\Jobs\Link_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Remote_Error;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Export_Test_Case;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Remote_Exception;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Scripted_Link_Resolver;

/**
 * @covers \Woodev\Framework\Marketplace\Jobs\Link_Jobs
 * @covers \Woodev\Framework\Marketplace\Jobs\Link_Scheduler
 */
final class LinkJobsTest extends Export_Test_Case {
	private const LINK    = 'woodev_fake_seller_link_cards';
	private const REFRESH = 'woodev_fake_seller_refresh_cards';
	private const PREFIX  = 'fake_seller_';

	/**
	 * Options and transients of the test, by name.
	 *
	 * @var array<string,mixed>
	 */
	private $wp_storage = [];

	/**
	 * The shop's products with an article.
	 *
	 * @var array<int,array{id:int,parent_id:int,sku:string}>
	 */
	private $shop = [];

	/**
	 * Categories the shop was asked for.
	 *
	 * @var array<int,int|null>
	 */
	private $asked = [];

	/**
	 * The cabinet reader.
	 *
	 * @var Scripted_Link_Resolver
	 */
	private $resolver;

	/**
	 * Boots the stubs and the cabinet: four cards on the marketplace, four products in the shop.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->wp_storage = [];
		$this->asked      = [];
		$this->resolver   = new Scripted_Link_Resolver();

		$read = function ( string $name, $fallback = false ) {
			return array_key_exists( $name, $this->wp_storage ) ? $this->wp_storage[ $name ] : $fallback;
		};
		$drop = function ( string $name ): bool {
			unset( $this->wp_storage[ $name ] );

			return true;
		};
		$save = function ( string $name, $value ): bool {
			$this->wp_storage[ $name ] = $value;

			return true;
		};

		Functions\when( 'get_option' )->alias( $read );
		Functions\when( 'get_transient' )->alias( $read );
		Functions\when( 'delete_option' )->alias( $drop );
		Functions\when( 'delete_transient' )->alias( $drop );
		Functions\when( 'update_option' )->alias( $save );
		Functions\when( 'set_transient' )->alias( $save );

		$this->shop = [
			$this->shop_product( 101, 'OW-TEST-1' ),
			$this->shop_product( 102, 'ow-test-2' ),
			$this->shop_product( 103, 'ОW-TEST-3' ), // The first letter is Cyrillic.
			$this->shop_product( 104, 'NOT-ON-MARKET' ),
		];

		foreach ( [ 'OW-TEST-1', 'OW-TEST-2', 'OW-TEST-3' ] as $index => $offer_id ) {
			$this->resolver->cards[]            = $this->card( 11 + $index, $offer_id );
			$this->resolver->details[ $offer_id ] = $this->detail( 11 + $index, $offer_id );
		}
	}

	/**
	 * Builds a shop product.
	 *
	 * @param int    $id  Product id.
	 * @param string $sku SKU.
	 * @return array{id:int,parent_id:int,sku:string}
	 */
	private function shop_product( int $id, string $sku ): array {
		return [
			'id'        => $id,
			'parent_id' => 0,
			'sku'       => $sku,
		];
	}

	/**
	 * Builds a summary card.
	 *
	 * @param int    $ref      Card reference.
	 * @param string $offer_id Article.
	 * @return array<string,mixed>
	 */
	private function card( int $ref, string $offer_id ): array {
		return [
			'offer_id'   => $offer_id,
			'remote_ids' => [ 'card_ref' => $ref ],
			'archived'   => false,
		];
	}

	/**
	 * Builds the detail of a card.
	 *
	 * @param int    $ref      Card reference.
	 * @param string $offer_id Article.
	 * @return array<string,mixed>
	 */
	private function detail( int $ref, string $offer_id ): array {
		return [
			'offer_id'   => $offer_id,
			'remote_ids' => [
				'card_ref'  => $ref,
				'model_ref' => 500000 + $ref,
			],
			'state'      => Link_Record::STATE_CREATED,
			'is_created' => true,
			'name'       => 'Карточка ' . $offer_id,
		];
	}

	/**
	 * Builds the jobs over the test doubles.
	 *
	 * @return Link_Jobs
	 */
	private function jobs(): Link_Jobs {
		return new Link_Jobs(
			$this->job_gate(),
			$this->links(),
			new Card_Linker( $this->links() ),
			function ( ?int $category_id ): array {
				$this->asked[] = $category_id;

				return $this->shop;
			},
			$this->reports(),
			$this->snapshots(),
			new Link_Scheduler( $this->names() ),
			$this->resolver,
			$this->adapter->article_policy(),
			$this->classifier,
			$this->ring(),
			[ $this, 'log_line' ],
			new Link_Progress_Store( self::PREFIX ),
			$this->clock()
		);
	}

	/**
	 * Reports.
	 *
	 * @return Link_Report_Store
	 */
	private function reports(): Link_Report_Store {
		return new Link_Report_Store( self::PREFIX );
	}

	/**
	 * Snapshot.
	 *
	 * @return Cards_Snapshot_Store
	 */
	private function snapshots(): Cards_Snapshot_Store {
		return new Cards_Snapshot_Store( self::PREFIX );
	}

	/**
	 * Names the kinds of read the resolver answered.
	 *
	 * @return string[]
	 */
	private function read_kinds(): array {
		return array_column( $this->resolver->reads, 0 );
	}

	/**
	 * Exact matches are linked with the details' identifiers and state, switches off; near matches are only reported.
	 *
	 * @return void
	 */
	public function test_exact_matches_are_linked_and_near_matches_are_only_reported(): void {
		$this->jobs()->link_cards( $this->account_id, 0, 0 );

		$this->assertSame( [ 'catalogue', 'details' ], $this->read_kinds(), 'the job only reads' );

		$row = $this->links()->find( $this->account_id, 101 );

		$this->assertNotNull( $row );
		$this->assertSame( 'OW-TEST-1', $row->offer_id() );
		$this->assertSame( 11, $row->remote_id( 'card_ref' ) );
		$this->assertSame( 500011, $row->remote_id( 'model_ref' ) );
		$this->assertSame( Link_Record::STATE_CREATED, $row->card_state() );
		$this->assertFalse( $row->sync_stock() );
		$this->assertFalse( $row->sync_price() );
		$this->assertNull( $this->links()->find( $this->account_id, 102 ), 'a case difference is not linked on its own' );
		$this->assertNull( $this->links()->find( $this->account_id, 103 ), 'a look-alike letter is not linked on its own' );
		$this->assertNull( $this->links()->find( $this->account_id, 104 ) );

		$near = $this->reports()->near_matches( $this->account_id );

		$this->assertSame( [ 102, 103 ], array_column( $near, 'wc_product_id' ) );
		$this->assertSame( [ Offer_Id_Likeness::CASE, Offer_Id_Likeness::LOOK_ALIKE ], array_column( $near, 'relation' ) );
		$this->assertSame( 'OW-TEST-2', $near[0]['card']['offer_id'] );
		$this->assertSame( 12, $near[0]['card']['remote_ids']['card_ref'] );

		$run = $this->reports()->last_run( $this->account_id );

		$this->assertSame( 1, $run['linked'] );
		$this->assertSame( 2, $run['near_matches'] );
		$this->assertSame( 3, $run['cards'] );
		$this->assertSame( '', $run['error'] );
		$this->assertSame( self::NOW, $run['finished_at'] );
	}

	/**
	 * A second run links nothing twice and keeps the report.
	 *
	 * @return void
	 */
	public function test_a_second_run_links_nothing_twice(): void {
		$this->jobs()->link_cards( $this->account_id, 0, 0 );
		$id = $this->links()->find( $this->account_id, 101 )->link_id();

		$this->jobs()->link_cards( $this->account_id, 0, 0 );

		$this->assertSame( $id, $this->links()->find( $this->account_id, 101 )->link_id() );
		$this->assertSame( 1, $this->links()->count( $this->account_id ) );

		$run = $this->reports()->last_run( $this->account_id );

		$this->assertSame( 0, $run['linked'] );
		$this->assertGreaterThanOrEqual( 2, $run['already_linked'], 'the linked card and the linked product are both counted' );
		$this->assertCount( 2, $this->reports()->near_matches( $this->account_id ) );
	}

	/**
	 * An archived card is linked as archived.
	 *
	 * @return void
	 */
	public function test_an_archived_card_is_linked_as_archived(): void {
		$this->resolver->cards[0]['archived'] = true;

		$this->jobs()->link_cards( $this->account_id, 0, 0 );

		$this->assertSame( Link_Record::STATE_ARCHIVED, $this->links()->find( $this->account_id, 101 )->card_state() );
	}

	/**
	 * The category scopes the shop query, 0 meaning the whole shop.
	 *
	 * @return void
	 */
	public function test_the_category_scopes_the_shop_query(): void {
		$this->jobs()->link_cards( $this->account_id, 0, 0 );
		$this->jobs()->link_cards( $this->account_id, 55, 0 );

		$this->assertSame( [ null, 55 ], $this->asked );
	}

	/**
	 * A disabled account makes no read; the merchant sees why.
	 *
	 * @return void
	 */
	public function test_a_disabled_account_makes_no_request(): void {
		$this->accounts()->set_enabled( $this->account_id, false );

		$this->jobs()->link_cards( $this->account_id, 0, 0 );

		$this->assertSame( [], $this->resolver->reads );
		$this->assertNotSame( '', $this->reports()->last_run( $this->account_id )['error'] );
	}

	/**
	 * A lapsed licence waits an hour without reading the marketplace.
	 *
	 * @return void
	 */
	public function test_a_lapsed_licence_waits_without_a_request(): void {
		$this->licensed = false;

		$this->jobs()->link_cards( $this->account_id, 7, 2 );

		$this->assertSame( [], $this->resolver->reads );

		$retry = $this->scheduler->of( self::LINK );

		$this->assertCount( 1, $retry );
		$this->assertSame( [ $this->account_id, 7, 2 ], $retry[0]['args'] );
		$this->assertSame( Job_Gate::LICENCE_DELAY, $retry[0]['delay'] );
		$this->assertNull( $this->reports()->last_run( $this->account_id ), 'no verdict while the job waits' );
	}

	/**
	 * A busy account retries soon with the same attempt count: a wait is not a failure.
	 *
	 * @return void
	 */
	public function test_a_busy_account_retries_in_thirty_seconds(): void {
		$this->assertNotNull( $this->accounts()->claim( $this->account_id, 120 ) );

		$this->jobs()->link_cards( $this->account_id, 7, 2 );

		$this->assertSame( [], $this->resolver->reads );

		$retry = $this->scheduler->of( self::LINK );

		$this->assertCount( 1, $retry );
		$this->assertSame( [ $this->account_id, 7, 2 ], $retry[0]['args'] );
		$this->assertSame( Job_Gate::BUSY_DELAY, $retry[0]['delay'] );
	}

	/**
	 * A refused credential pauses the account and ends the run with a text.
	 *
	 * @return void
	 */
	public function test_a_refused_key_pauses_the_account(): void {
		$this->resolver->failure = Fake_Remote_Exception::of( Remote_Error::PAUSE_ACCOUNT, 'key refused' );

		$this->jobs()->link_cards( $this->account_id, 0, 0 );

		$this->assertSame( [ $this->account_id ], $this->classifier->refused );
		$this->assertSame( [], $this->scheduler->of( self::LINK ) );
		$this->assertNotSame( '', $this->reports()->last_run( $this->account_id )['error'] );
		$this->assertSame( 0, $this->links()->count( $this->account_id ) );
		$this->assertStringContainsString( 'fake_pause_account', implode( "\n", $this->log ) );
	}

	/**
	 * A throttle waits as long as the marketplace asked and does not count as an attempt.
	 *
	 * @return void
	 */
	public function test_a_throttle_waits_without_counting_an_attempt(): void {
		$this->resolver->failure = Fake_Remote_Exception::of( Remote_Error::THROTTLE, 'slow down', 45 );

		$this->jobs()->link_cards( $this->account_id, 0, 2 );

		$retry = $this->scheduler->of( self::LINK );

		$this->assertCount( 1, $retry );
		$this->assertSame( [ $this->account_id, 0, 2 ], $retry[0]['args'] );
		$this->assertSame( 45, $retry[0]['delay'] );
		$this->assertNull( $this->reports()->last_run( $this->account_id ) );
	}

	/**
	 * A server error retries on the ladder, then gives up with a text.
	 *
	 * @return void
	 */
	public function test_a_server_error_retries_then_gives_up(): void {
		$this->resolver->failure = Fake_Remote_Exception::of( Remote_Error::RETRY, 'HTTP 503' );

		$this->jobs()->link_cards( $this->account_id, 0, 0 );

		$retry = $this->scheduler->of( self::LINK );

		$this->assertCount( 1, $retry );
		$this->assertSame( [ $this->account_id, 0, 1 ], $retry[0]['args'] );
		$this->assertSame( Backoff::seconds_after( 1 ), $retry[0]['delay'] );
		$this->assertNull( $this->reports()->last_run( $this->account_id ), 'no verdict while a retry is pending' );

		$this->resolver->failure = Fake_Remote_Exception::of( Remote_Error::RETRY, 'HTTP 503' );

		$this->jobs()->link_cards( $this->account_id, 0, Link_Jobs::MAX_ATTEMPTS - 1 );

		$this->assertCount( 1, $this->scheduler->of( self::LINK ), 'no third retry is scheduled' );
		$this->assertStringContainsString( 'HTTP 503', $this->reports()->last_run( $this->account_id )['error'] );
	}

	/**
	 * A refusal nothing can fix ends the run with the marketplace's text.
	 *
	 * @return void
	 */
	public function test_any_other_refusal_ends_the_run(): void {
		$this->resolver->failure = Fake_Remote_Exception::of( Remote_Error::FAIL, 'not allowed' );

		$this->jobs()->link_cards( $this->account_id, 0, 0 );

		$this->assertSame( [], $this->scheduler->of( self::LINK ) );
		$this->assertStringContainsString( 'not allowed', $this->reports()->last_run( $this->account_id )['error'] );
	}

	/**
	 * An answer that cannot be trusted ends the run with the merchant's text and links nothing.
	 *
	 * @return void
	 */
	public function test_an_untrusted_answer_ends_the_run_with_its_text(): void {
		$this->resolver->failure = Link_Resolve_Exception::foreign_items();

		$this->jobs()->link_cards( $this->account_id, 0, 0 );

		$this->assertSame( [], $this->scheduler->of( self::LINK ) );
		$this->assertSame( Link_Resolve_Exception::foreign_items()->getMessage(), $this->reports()->last_run( $this->account_id )['error'] );
		$this->assertSame( 0, $this->links()->count( $this->account_id ) );
	}

	/**
	 * An exception nobody classified ends the run with a text; nothing is thrown out of the job and the claim is released.
	 *
	 * @return void
	 */
	public function test_an_unexpected_exception_is_recorded_not_thrown(): void {
		$this->resolver->failure = new \RuntimeException( 'boom' );

		$this->jobs()->link_cards( $this->account_id, 0, 0 );

		$this->assertStringContainsString( 'boom', $this->reports()->last_run( $this->account_id )['error'] );
		$this->assertNotNull( $this->accounts()->claim( $this->account_id, 120 ), 'the claim was released' );
	}

	/**
	 * The refresh job fills the snapshot of the tab and clears the «refreshing» mark.
	 *
	 * @return void
	 */
	public function test_the_refresh_job_fills_the_snapshot(): void {
		$this->resolver->snapshot = [ $this->detail( 11, 'OW-TEST-1' ), $this->detail( 12, 'OW-TEST-2' ) ];
		$this->snapshots()->mark_refreshing( $this->account_id );

		$this->assertTrue( $this->snapshots()->is_refreshing( $this->account_id ) );

		$this->jobs()->refresh_cards( $this->account_id, 0 );

		$snapshot = $this->snapshots()->get( $this->account_id );

		$this->assertNotNull( $snapshot );
		$this->assertSame( self::NOW, $snapshot['fetched_at'] );
		$this->assertCount( 2, $snapshot['cards'] );
		$this->assertSame( 'Карточка OW-TEST-1', $snapshot['cards'][0]['name'] );
		$this->assertFalse( $this->snapshots()->is_refreshing( $this->account_id ) );
		$this->assertSame( [ 'snapshot' ], $this->read_kinds() );
	}

	/**
	 * A failed refresh records a text for the tab instead of a snapshot.
	 *
	 * @return void
	 */
	public function test_a_failed_refresh_records_a_text(): void {
		$this->snapshots()->mark_refreshing( $this->account_id );
		$this->resolver->failure = Fake_Remote_Exception::of( Remote_Error::FAIL, 'not allowed' );

		$this->jobs()->refresh_cards( $this->account_id, 0 );

		$this->assertFalse( $this->snapshots()->is_refreshing( $this->account_id ) );
		$this->assertStringContainsString( 'not allowed', $this->snapshots()->last_error( $this->account_id ) );
		$this->assertNull( $this->snapshots()->get( $this->account_id ) );
	}

	/**
	 * The job is hooked under the plugin's own names.
	 *
	 * @return void
	 */
	public function test_register_hooks_both_jobs_under_the_plugins_names(): void {
		\Brain\Monkey\Actions\expectAdded( self::LINK )->once();
		\Brain\Monkey\Actions\expectAdded( self::REFRESH )->once();

		$this->jobs()->register();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * The entry point queues the job once per account and category.
	 *
	 * @return void
	 */
	public function test_the_entry_point_queues_the_job_once(): void {
		$scheduler = new Link_Scheduler( $this->names() );

		$this->assertTrue( $scheduler->link_by_article( $this->account_id, 55 ) );
		$this->assertTrue( $scheduler->link_by_article( $this->account_id, 55 ) );
		$this->assertTrue( $scheduler->link_by_article( $this->account_id ) );
		$this->assertSame( [ [ $this->account_id, 55, 0 ], [ $this->account_id, 0, 0 ] ], array_column( $this->scheduler->of( self::LINK ), 'args' ) );

		$this->assertTrue( $scheduler->refresh_cards( $this->account_id ) );
		$this->assertTrue( $scheduler->refresh_cards( $this->account_id ) );
		$this->assertCount( 1, $this->scheduler->of( self::REFRESH ) );
	}

	/**
	 * A scheduler that Action Scheduler refuses says so.
	 *
	 * @return void
	 */
	public function test_a_refused_schedule_is_reported_to_the_caller(): void {
		$this->scheduler->zero_for[ self::LINK ] = true;

		$this->assertFalse( ( new Link_Scheduler( $this->names() ) )->link_by_article( $this->account_id ) );
	}

	/**
	 * Takes the account claim away right before a statement that matches the pattern.
	 *
	 * @param string $pattern Pattern of the statement.
	 * @return void
	 */
	private function take_over_before( string $pattern ): void {
		$this->db->before_query_pattern = $pattern;
		$this->db->before_query         = function (): void {
			$this->db->update(
				Sqlite_Tables::table( 'accounts' ),
				[
					'claim_token' => 'another-run',
					'claim_until' => '2100-01-01 00:00:00',
				],
				[ 'account_id' => $this->account_id ]
			);
		};
	}

	/**
	 * A run whose claim was taken over just before a new link is inserted creates nothing, reports nothing and retries.
	 *
	 * @return void
	 */
	public function test_a_takeover_before_a_new_link_is_inserted_creates_nothing(): void {
		$this->shop = [ $this->shop[0] ];
		$this->take_over_before( '/^INSERT.*' . Sqlite_Tables::table( 'links' ) . '/s' );

		$this->jobs()->link_cards( $this->account_id, 0, 0 );

		$this->assertSame( 0, $this->links()->count( $this->account_id ), 'the superseded run made no association' );
		$this->assertNull( $this->reports()->last_run( $this->account_id ), 'and did not report a result' );

		$retry = $this->scheduler->of( self::LINK );

		$this->assertCount( 1, $retry );
		$this->assertSame( [ $this->account_id, 0, 0 ], $retry[0]['args'] );
		$this->assertSame( Job_Gate::BUSY_DELAY, $retry[0]['delay'] );
	}

	/**
	 * The same for an idle row that would have been adopted.
	 *
	 * @return void
	 */
	public function test_a_takeover_before_an_idle_row_is_adopted_changes_nothing(): void {
		$this->shop = [ $this->shop[0] ];
		$this->links()->insert_if_absent( Link_Record::offer( $this->account_id, 101, 0, '' ) );
		$this->take_over_before( '/^UPDATE.*' . Sqlite_Tables::table( 'links' ) . '.*SET offer_id/s' );

		$this->jobs()->link_cards( $this->account_id, 0, 0 );

		$this->assertSame( [], $this->links()->find( $this->account_id, 101 )->remote_ids() );
		$this->assertNull( $this->reports()->last_run( $this->account_id ) );
		$this->assertCount( 1, $this->scheduler->of( self::LINK ) );
	}

	/**
	 * A wait in the middle of the details keeps what was read; the next run hands the cursor back and finishes.
	 *
	 * @return void
	 */
	public function test_a_wait_in_the_middle_resumes_with_the_kept_cursor_and_finishes(): void {
		$fired = false;

		$this->resolver->on_read = static function ( string $kind ) use ( &$fired ): void {
			if ( 'details' === $kind && ! $fired ) {
				$fired = true;

				throw Fake_Remote_Exception::of( Remote_Error::THROTTLE, 'governor', 30, false );
			}
		};

		$this->jobs()->link_cards( $this->account_id, 0, 0 );

		$this->assertSame( 0, $this->links()->count( $this->account_id ) );
		$this->assertNull( $this->reports()->last_run( $this->account_id ), 'a wait is not a result' );

		$retry = $this->scheduler->of( self::LINK );

		$this->assertCount( 1, $retry );
		$this->assertSame( [ $this->account_id, 0, 0 ], $retry[0]['args'], 'a wait does not count as a failed attempt' );
		$this->assertNotNull( $this->wp_storage[ self::PREFIX . Link_Progress_Store::OPTION . 'link_' . $this->account_id ] ?? null, 'the pages already read are kept' );

		$this->resolver->reads = [];
		$this->now            += 40;

		$this->jobs()->link_cards( ...$retry[0]['args'] );

		$this->assertSame( 1, $this->resolver->reads[0][2]['reads'], 'the list is read again from the cursor the first run left' );
		$this->assertSame( 1, $this->links()->count( $this->account_id ) );
		$this->assertSame( 1, $this->reports()->last_run( $this->account_id )['linked'] );
		$this->assertArrayNotHasKey( self::PREFIX . Link_Progress_Store::OPTION . 'link_' . $this->account_id, $this->wp_storage, 'a finished run leaves no progress behind' );
		$this->assertCount( 1, $this->scheduler->of( self::LINK ), 'no further retry' );
	}

	/**
	 * A wait during the refresh keeps the cursor and publishes nothing half way.
	 *
	 * @return void
	 */
	public function test_a_wait_during_a_refresh_resumes_and_publishes_the_snapshot(): void {
		$this->resolver->snapshot = [ $this->detail( 11, 'OW-TEST-1' ) ];
		$this->resolver->failure  = Fake_Remote_Exception::of( Remote_Error::THROTTLE, 'governor', 30, false );
		$this->snapshots()->mark_refreshing( $this->account_id );

		$this->jobs()->refresh_cards( $this->account_id, 0 );

		$this->assertNull( $this->snapshots()->get( $this->account_id ), 'nothing is published half way' );
		$this->assertTrue( $this->snapshots()->is_refreshing( $this->account_id ) );

		$retry = $this->scheduler->of( self::REFRESH );

		$this->assertCount( 1, $retry );
		$this->assertSame( [ $this->account_id, 0 ], $retry[0]['args'] );

		$this->resolver->reads = [];

		$this->jobs()->refresh_cards( ...$retry[0]['args'] );

		$this->assertSame( 1, $this->resolver->reads[0][2]['reads'] );
		$this->assertCount( 1, $this->snapshots()->get( $this->account_id )['cards'] );
		$this->assertFalse( $this->snapshots()->is_refreshing( $this->account_id ) );
		$this->assertArrayNotHasKey( self::PREFIX . Link_Progress_Store::OPTION . 'refresh_' . $this->account_id, $this->wp_storage );
	}

	/**
	 * A progress record that is too old is not used: the list is read from the start.
	 *
	 * @return void
	 */
	public function test_an_old_progress_record_is_not_used(): void {
		( new Link_Progress_Store( self::PREFIX ) )->save(
			$this->account_id,
			Link_Progress_Store::KIND_LINK,
			[
				'started_at' => $this->now - Link_Progress_Store::MAX_AGE - 1,
				'scope'      => 0,
				'state'      => [ 'catalogue' => [ 'reads' => 9 ] ],
			]
		);

		$this->jobs()->link_cards( $this->account_id, 0, 0 );

		$this->assertSame( [], $this->resolver->reads[0][2], 'the list was read from the start' );
	}

	/**
	 * A progress record of another category is not used either.
	 *
	 * @return void
	 */
	public function test_a_progress_record_of_another_scope_is_not_used(): void {
		( new Link_Progress_Store( self::PREFIX ) )->save(
			$this->account_id,
			Link_Progress_Store::KIND_LINK,
			[
				'started_at' => $this->now,
				'scope'      => 55,
				'state'      => [ 'catalogue' => [ 'reads' => 9 ] ],
			]
		);

		$this->jobs()->link_cards( $this->account_id, 0, 0 );

		$this->assertSame( [], $this->resolver->reads[0][2] );
	}

	/**
	 * A list and a detail that disagree about the article are neither linked nor reported: the pair would store a card that is not one.
	 *
	 * @return void
	 */
	public function test_a_list_and_detail_that_disagree_are_neither_linked_nor_reported(): void {
		$this->shop                          = [ $this->shop_product( 101, 'OW-TEST-1' ), $this->shop_product( 102, 'ow-test-2' ) ];
		$this->resolver->details['OW-TEST-1'] = $this->detail( 11, 'OW-TEST-1-RENAMED' );
		$this->resolver->details['OW-TEST-2'] = $this->detail( 12, 'OW-TEST-2-RENAMED' );

		$this->jobs()->link_cards( $this->account_id, 0, 0 );

		$this->assertSame( 0, $this->links()->count( $this->account_id ) );
		$this->assertSame( [], $this->reports()->near_matches( $this->account_id ), 'the near entry would store a card that is not one' );
		$this->assertSame( 0, $this->reports()->last_run( $this->account_id )['linked'] );
		$this->assertSame( 1, $this->reports()->last_run( $this->account_id )['busy'], 'the exact pair is counted as not done, to be tried again' );
	}

	/**
	 * A card replaced between the list and the detail by another one that reuses the article is not linked, not marked archived and
	 * not reported as a near match: the framework compares the stable identifiers even when the adapter confirms the article only.
	 *
	 * @return void
	 */
	public function test_a_replacement_card_with_the_same_article_is_neither_linked_nor_reported(): void {
		$this->shop                           = [ $this->shop_product( 101, 'OW-TEST-1' ), $this->shop_product( 102, 'ow-test-2' ) ];
		$this->resolver->cards[0]['archived'] = true;
		// Same article, another remote card: the list showed card 11 and card 12, the details now answer for 911 and 912.
		$this->resolver->details['OW-TEST-1'] = $this->detail( 911, 'OW-TEST-1' );
		$this->resolver->details['OW-TEST-2'] = $this->detail( 912, 'OW-TEST-2' );

		$this->jobs()->link_cards( $this->account_id, 0, 0 );

		$this->assertSame( 0, $this->links()->count( $this->account_id ), 'the replacement is not linked, archived or otherwise' );
		$this->assertSame( [], $this->reports()->near_matches( $this->account_id ) );

		$run = $this->reports()->last_run( $this->account_id );

		$this->assertSame( 0, $run['linked'] );
		$this->assertSame( 1, $run['busy'], 'the exact pair is left for the next run' );
	}

	/**
	 * Two products that normalise to the same article are not linked and are counted.
	 *
	 * @return void
	 */
	public function test_ambiguous_products_are_counted_not_linked(): void {
		$this->shop = [ $this->shop_product( 101, 'OW-TEST-1' ), $this->shop_product( 105, 'OW TEST 1' ), $this->shop_product( 106, 'OW-TEST-1' ) ];

		$this->jobs()->link_cards( $this->account_id, 0, 0 );

		$this->assertSame( 0, $this->links()->count( $this->account_id ) );
		$this->assertSame( 1, $this->reports()->last_run( $this->account_id )['ambiguous'] );
	}
}
