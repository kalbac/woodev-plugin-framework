<?php
/**
 * The `link_cards`, `refresh_cards`, `refresh_card_rows` and daily jobs: gate, claim, matching, linking, the near-match report, the
 * stored cards list (paging, the sweep of deleted cards, notifications) and the failure ladder.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Jobs;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Catalog\Offer_Id_Likeness;
use Woodev\Framework\Marketplace\Data\Link_Progress_Store;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Link_Report_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Card_Store;
use Woodev\Framework\Marketplace\Flows\Card_Linker;
use Woodev\Framework\Marketplace\Flows\Link_Resolve_Exception;
use Woodev\Framework\Marketplace\Flows\Link_Resolver;
use Woodev\Framework\Marketplace\Flows\Sellable_Units;
use Woodev\Framework\Marketplace\Flows\Unit_Matcher;
use Woodev\Framework\Marketplace\Jobs\Account_Job;
use Woodev\Framework\Marketplace\Jobs\Backoff;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\Link_Jobs;
use Woodev\Framework\Marketplace\Jobs\Link_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Remote_Error;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Export_Test_Case;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Remote_Exception;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Sellable_Units;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Paged_Link_Resolver;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Scripted_Link_Resolver;

/**
 * @covers \Woodev\Framework\Marketplace\Jobs\Link_Jobs
 * @covers \Woodev\Framework\Marketplace\Jobs\Link_Scheduler
 */
final class LinkJobsTest extends Export_Test_Case {
	private const LINK    = 'woodev_fake_seller_link_cards';
	private const REFRESH = 'woodev_fake_seller_refresh_cards';
	private const ROWS    = 'woodev_fake_seller_refresh_card_rows';
	private const DAILY   = 'woodev_fake_seller_refresh_cards_daily';
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
	 * @param Card_Linker|null   $linker   The linker the job writes through; the plain one (switches off) by default.
	 * @param Link_Resolver|null $resolver The cabinet reader; the scripted one by default.
	 * @param int[]              $accounts The enabled accounts the daily run sees.
	 * @return Link_Jobs
	 */
	private function jobs( ?Card_Linker $linker = null, ?Link_Resolver $resolver = null, array $accounts = [], ?Sellable_Units $units = null ): Link_Jobs {
		return new Link_Jobs(
			$this->job_gate(),
			$this->links(),
			$linker ?? new Card_Linker( $this->links() ),
			function ( ?int $category_id ): array {
				$this->asked[] = $category_id;

				return $this->shop;
			},
			$this->reports(),
			$this->card_store(),
			new Link_Scheduler( $this->names() ),
			$resolver ?? $this->resolver,
			$this->adapter->article_policy(),
			$this->classifier,
			$this->ring(),
			[ $this, 'log_line' ],
			new Link_Progress_Store( self::PREFIX ),
			$this->clock(),
			static function () use ( $accounts ): array {
				return $accounts;
			},
			$units
		);
	}

	/**
	 * A marketplace that sells units: the cards are linked by the adapter's keys with their barcodes, and a card several products
	 * match is reported as a conflict, not guessed.
	 *
	 * @return void
	 */
	public function test_units_are_linked_by_the_match_keys_and_a_conflict_is_reported(): void {
		$this->shop = [];

		foreach ( [ 101 => [ 'x', [ '12345Ejf5' ] ], 102 => [ 'coat-L', [] ], 103 => [ 'coat_L', [] ] ] as $id => list( $sku, $barcodes ) ) {
			$this->shop[] = $this->shop_product( $id, $sku ) + [
				'barcodes'  => $barcodes,
				'importing' => '',
			];
		}

		$this->resolver->cards   = [
			Fake_Sellable_Units::card( 'coat', 'M', [ '12345Ejf5', '2000000000001' ], 2 ),
			Fake_Sellable_Units::card( 'coat', 'L', [], 2 ),
		];
		$this->resolver->details = array_column( $this->resolver->cards, null, 'offer_id' );

		$this->jobs( null, null, [], new Fake_Sellable_Units() )->link_cards( $this->account_id );

		$link = $this->links()->find_by_offer_id( $this->account_id, 'coat|M' );

		$this->assertSame( 101, $link->wc_product_id() );
		$this->assertSame( [ '12345Ejf5', '2000000000001' ], $this->links()->barcodes_of( (int) $link->link_id() ) );
		$this->assertNull( $this->links()->find_by_offer_id( $this->account_id, 'coat|L' ) );
		$this->assertSame(
			[
				[
					'offer_id'    => 'coat|L',
					'reason'      => Unit_Matcher::AMBIGUOUS,
					'product_ids' => [ 102, 103 ],
				],
			],
			$this->reports()->conflicts( $this->account_id )
		);
		$this->assertSame( 1, $this->reports()->last_run( $this->account_id )['ambiguous'] );
		$this->assertSame( [], $this->reports()->near_matches( $this->account_id ) );
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
	 * The stored cards list.
	 *
	 * @return Wpdb_Card_Store
	 */
	private function card_store(): Wpdb_Card_Store {
		return new Wpdb_Card_Store( $this->db, Sqlite_Tables::table( 'cards' ), Sqlite_Tables::table( 'links' ), self::PREFIX, $this->clock() );
	}

	/**
	 * The articles of the account's list, in the marketplace's order.
	 *
	 * @return string[]
	 */
	private function listed(): array {
		return array_column( $this->card_store()->page( $this->account_id, null, '', 1, 1000 )['cards'], 'offer_id' );
	}

	/**
	 * A page of list rows, `{prefix}-1` … `{prefix}-{count}`.
	 *
	 * @param string $prefix Article prefix.
	 * @param int    $count  Rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function list_rows( string $prefix, int $count ): array {
		$rows = [];

		for ( $i = 1; $i <= $count; $i++ ) {
			$rows[] = $this->detail( $i, $prefix . '-' . $i );
		}

		return $rows;
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
	 * With «sync on link» the exact pairs are linked with both switches on and the first send is recorded; with it off, as before (#1793).
	 *
	 * @return void
	 */
	public function test_the_link_job_follows_the_sync_on_link_decision(): void {
		$first = [];
		$spy   = function ( bool $on ) use ( &$first ): Card_Linker {
			return new Card_Linker(
				$this->links(),
				static function () use ( $on ): bool {
					return $on;
				},
				static function ( Link_Record $link ) use ( &$first ): void {
					$first[] = $link->wc_product_id();
				}
			);
		};

		$this->jobs( $spy( true ) )->link_cards( $this->account_id, 0, 0 );

		$row = $this->links()->find( $this->account_id, 101 );

		$this->assertTrue( $row->sync_stock() );
		$this->assertTrue( $row->sync_price() );
		$this->assertSame( [ 101 ], $first, 'the first send is recorded for the exact pair, not for the near matches' );

		$this->db->query( 'DELETE FROM ' . Sqlite_Tables::table( 'links' ) );
		$this->db->query( 'DELETE FROM ' . Sqlite_Tables::table( 'link_ids' ) );
		$first = [];

		$this->jobs( $spy( false ) )->link_cards( $this->account_id, 0, 0 );

		$this->assertFalse( $this->links()->find( $this->account_id, 101 )->sync_stock() );
		$this->assertSame( [], $first );
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
	 * «Нет, это разные» is kept: the next run finds the same pair again but does not offer it, while the other pair stays.
	 *
	 * @return void
	 */
	public function test_a_pair_answered_no_is_not_offered_again_by_the_next_run(): void {
		$this->jobs()->link_cards( $this->account_id, 0, 0 );
		$this->assertTrue( $this->reports()->dismiss_near_match( $this->account_id, 102, 'OW-TEST-2' ) );

		$this->jobs()->link_cards( $this->account_id, 0, 0 );

		$this->assertSame( [ 103 ], array_column( $this->reports()->near_matches( $this->account_id ), 'wc_product_id' ) );
		$this->assertNull( $this->links()->find( $this->account_id, 102 ), 'and it is still not linked' );
	}

	/**
	 * A run of one category replaces only the pairs of the products it looked at; the others keep waiting.
	 *
	 * @return void
	 */
	public function test_a_category_run_keeps_the_pairs_of_other_products(): void {
		$this->jobs()->link_cards( $this->account_id, 0, 0 );
		$this->assertSame( [ 102, 103 ], array_column( $this->reports()->near_matches( $this->account_id ), 'wc_product_id' ) );

		$this->shop = [ $this->shop_product( 104, 'NOT-ON-MARKET' ) ];
		$this->jobs()->link_cards( $this->account_id, 55, 0 );

		$this->assertSame( [ 102, 103 ], array_column( $this->reports()->near_matches( $this->account_id ), 'wc_product_id' ), 'the category had no pair: the others stay' );

		$this->shop = [ $this->shop_product( 103, 'OW-TEST-3' ) ]; // Latin letters now: an exact match, no longer a look-alike.
		$this->jobs()->link_cards( $this->account_id, 55, 0 );

		$this->assertSame( [ 102 ], array_column( $this->reports()->near_matches( $this->account_id ), 'wc_product_id' ), 'the product the run looked at is replaced, the other stays' );
	}

	/**
	 * A run of the whole shop replaces the whole report.
	 *
	 * @return void
	 */
	public function test_a_whole_shop_run_replaces_the_whole_report(): void {
		$this->jobs()->link_cards( $this->account_id, 0, 0 );
		$this->assertCount( 2, $this->reports()->near_matches( $this->account_id ) );

		$this->shop = [ $this->shop_product( 104, 'NOT-ON-MARKET' ) ];
		$this->jobs()->link_cards( $this->account_id, 0, 0 );

		$this->assertSame( [], $this->reports()->near_matches( $this->account_id ) );
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
		$this->assertSame( [ $this->account_id, 7, 2, 1 ], $retry[0]['args'] );
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
		$this->assertSame( [ $this->account_id, 0, 2, 1 ], $retry[0]['args'], 'the attempt stays, the wait is counted apart' );
		$this->assertSame( 45, $retry[0]['delay'] );
		$this->assertNull( $this->reports()->last_run( $this->account_id ) );
	}

	/**
	 * A linking run that has waited as often as the ceiling allows gives up with a text instead of waiting again.
	 *
	 * @return void
	 */
	public function test_a_throttle_past_the_ceiling_gives_the_run_up(): void {
		$this->resolver->on_read = static function (): void {
			throw Fake_Remote_Exception::of( Remote_Error::THROTTLE, 'slow down', 45 );
		};

		$this->jobs()->link_cards( $this->account_id, 0, 0, Account_Job::MAX_PUT_OFFS );

		$this->assertSame( [], $this->scheduler->of( self::LINK ) );
		$this->assertNotNull( $this->reports()->last_run( $this->account_id )['error'] ?? null );
	}

	/**
	 * A long walk that keeps its pages between short waits is never cut off: every run that advanced the cursor starts the count again.
	 *
	 * @return void
	 */
	public function test_waits_between_runs_that_make_progress_never_reach_the_ceiling(): void {
		$args = [ $this->account_id, 0, 0 ];

		for ( $run = 0; $run < Account_Job::MAX_PUT_OFFS + 6; ++$run ) {
			$this->resolver->failure = Fake_Remote_Exception::of( Remote_Error::THROTTLE, 'governor', 30, false );

			$this->jobs()->link_cards( ...$args );

			$retry = $this->scheduler->of( self::LINK );
			$args  = end( $retry )['args'];
		}

		$this->assertNull( $this->reports()->last_run( $this->account_id ), 'the pass was not given up' );
		$this->assertSame( [ $this->account_id, 0, 0, 1 ], $args, 'the count started again on every run' );
		$this->assertCount( Account_Job::MAX_PUT_OFFS + 6, $this->scheduler->of( self::LINK ) );
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
	 * A resolver that cannot page fills the list in one piece and clears the «refreshing» mark.
	 *
	 * @return void
	 */
	public function test_the_refresh_job_fills_the_list_from_a_resolver_that_cannot_page(): void {
		$this->resolver->snapshot = [ $this->detail( 11, 'OW-TEST-1' ), $this->detail( 12, 'OW-TEST-2' ) ];
		$this->card_store()->mark_refreshing( $this->account_id );

		$this->assertTrue( $this->card_store()->is_refreshing( $this->account_id ) );

		$this->jobs()->refresh_cards( $this->account_id, 0 );

		$this->assertSame( self::NOW, $this->card_store()->refreshed_at( $this->account_id ) );
		$this->assertSame( [ 'OW-TEST-1', 'OW-TEST-2' ], $this->listed() );
		$this->assertSame( 'Карточка OW-TEST-1', $this->card_store()->find( $this->account_id, Wpdb_Card_Store::key( 'OW-TEST-1' ) )['name'] );
		$this->assertFalse( $this->card_store()->is_refreshing( $this->account_id ) );
		$this->assertSame( [ 'snapshot' ], $this->read_kinds() );
	}

	/**
	 * A failed refresh records a text for the tab instead of a snapshot.
	 *
	 * @return void
	 */
	public function test_a_failed_refresh_records_a_text(): void {
		$this->card_store()->mark_refreshing( $this->account_id );
		$this->resolver->failure = Fake_Remote_Exception::of( Remote_Error::FAIL, 'not allowed' );

		$this->jobs()->refresh_cards( $this->account_id, 0 );

		$this->assertFalse( $this->card_store()->is_refreshing( $this->account_id ) );
		$this->assertStringContainsString( 'not allowed', $this->card_store()->last_error( $this->account_id ) );
		$this->assertFalse( $this->card_store()->has_list( $this->account_id ) );
	}

	/**
	 * The job is hooked under the plugin's own names.
	 *
	 * @return void
	 */
	public function test_register_hooks_both_jobs_under_the_plugins_names(): void {
		\Brain\Monkey\Actions\expectAdded( self::LINK )->once();
		\Brain\Monkey\Actions\expectAdded( self::REFRESH )->once();
		\Brain\Monkey\Actions\expectAdded( self::ROWS )->once();
		\Brain\Monkey\Actions\expectAdded( self::DAILY )->once();

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
		$this->assertSame( [ $this->account_id, 0, 0, 1 ], $retry[0]['args'], 'a wait does not count as a failed attempt' );
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
	public function test_a_wait_during_a_refresh_resumes_and_publishes_the_list(): void {
		$this->resolver->snapshot = [ $this->detail( 11, 'OW-TEST-1' ) ];
		$this->resolver->failure  = Fake_Remote_Exception::of( Remote_Error::THROTTLE, 'governor', 30, false );
		$this->card_store()->mark_refreshing( $this->account_id );

		$this->jobs()->refresh_cards( $this->account_id, 0 );

		$this->assertFalse( $this->card_store()->has_list( $this->account_id ), 'nothing is published half way' );
		$this->assertTrue( $this->card_store()->is_refreshing( $this->account_id ) );

		$retry = $this->scheduler->of( self::REFRESH );

		$this->assertCount( 1, $retry );
		$this->assertSame( [ $this->account_id, 0, 1 ], $retry[0]['args'] );

		$this->resolver->reads = [];

		$this->jobs()->refresh_cards( ...$retry[0]['args'] );

		$this->assertSame( 1, $this->resolver->reads[0][2]['reads'] );
		$this->assertSame( [ 'OW-TEST-1' ], $this->listed() );
		$this->assertFalse( $this->card_store()->is_refreshing( $this->account_id ) );
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

	/**
	 * A resolver that pages: each page is written before the next is read, in the marketplace's order, and the refresh is recorded
	 * after the last.
	 *
	 * @return void
	 */
	public function test_a_paged_refresh_writes_every_page_in_order_and_records_the_time(): void {
		$paged        = new Paged_Link_Resolver();
		$paged->pages = [ $this->list_rows( 'A', 3 ), $this->list_rows( 'B', 2 ) ];
		$this->card_store()->mark_refreshing( $this->account_id );

		$this->jobs( null, $paged )->refresh_cards( $this->account_id, 0 );

		$this->assertSame( [ 'A-1', 'A-2', 'A-3', 'B-1', 'B-2' ], $this->listed() );
		$this->assertSame( [ [], [ 'next' => 1 ] ], array_column( $paged->reads, 1 ), 'the second page gets the cursor the first left' );
		$this->assertSame( self::NOW, $this->card_store()->refreshed_at( $this->account_id ) );
		$this->assertFalse( $this->card_store()->is_refreshing( $this->account_id ) );
		$this->assertArrayNotHasKey( self::PREFIX . Link_Progress_Store::OPTION . 'refresh_' . $this->account_id, $this->wp_storage, 'a finished refresh leaves no progress' );
		$this->assertSame( [], $this->scheduler->of( self::REFRESH ), 'nothing left to continue' );
	}

	/**
	 * A run reads at most its page budget, then queues its continuation; the screen shows what was written so far, and the
	 * continuation goes on from the next page.
	 *
	 * @return void
	 */
	public function test_a_long_refresh_stops_at_its_page_budget_and_continues_from_the_next_page(): void {
		$paged = new Paged_Link_Resolver();

		for ( $page = 1; $page <= Link_Jobs::PAGES_PER_RUN + 2; $page++ ) {
			$paged->pages[] = $this->list_rows( 'P' . $page, 2 );
		}

		$this->card_store()->mark_refreshing( $this->account_id );
		$this->jobs( null, $paged )->refresh_cards( $this->account_id, 0 );

		$this->assertCount( 2 * Link_Jobs::PAGES_PER_RUN, $this->listed(), 'the first part is on the screen already' );
		$this->assertSame( 0, $this->card_store()->refreshed_at( $this->account_id ), 'the refresh is not finished' );
		$this->assertTrue( $this->card_store()->is_refreshing( $this->account_id ) );

		$next = $this->scheduler->of( self::REFRESH );

		$this->assertCount( 1, $next );
		$this->assertSame( [ $this->account_id, 0 ], $next[0]['args'], 'a continuation starts with fresh counters' );

		$this->jobs( null, $paged )->refresh_cards( ...$next[0]['args'] );

		$this->assertCount( 2 * ( Link_Jobs::PAGES_PER_RUN + 2 ), $this->listed() );
		$this->assertSame( 'P' . ( Link_Jobs::PAGES_PER_RUN + 1 ) . '-1', $this->listed()[ 2 * Link_Jobs::PAGES_PER_RUN ], 'the order goes on where it stopped' );
		$this->assertSame( [ 'next' => Link_Jobs::PAGES_PER_RUN ], $paged->reads[ Link_Jobs::PAGES_PER_RUN ][1] );
		$this->assertSame( self::NOW, $this->card_store()->refreshed_at( $this->account_id ) );
	}

	/**
	 * A card deleted on the marketplace leaves the list — but only after the refresh's last page: until then the screen keeps it.
	 *
	 * @return void
	 */
	public function test_a_refresh_sweeps_the_cards_it_did_not_see_only_after_its_last_page(): void {
		$paged        = new Paged_Link_Resolver();
		$paged->pages = [ $this->list_rows( 'K', 3 ) ];
		$this->jobs( null, $paged )->refresh_cards( $this->account_id, 0 );
		$this->assertSame( [ 'K-1', 'K-2', 'K-3' ], $this->listed() );

		// K-2 is deleted on the marketplace; the new refresh is interrupted after its first page.
		$paged->pages    = [ [ $this->detail( 1, 'K-1' ) ], [ $this->detail( 3, 'K-3' ) ] ];
		$paged->failures = [ 1 => Fake_Remote_Exception::of( Remote_Error::THROTTLE, 'governor', 30, false ) ];
		$paged->reads    = [];

		$this->jobs( null, $paged )->refresh_cards( $this->account_id, 0 );

		$this->assertSame( [ 'K-1', 'K-2', 'K-3' ], $this->listed(), 'nothing is swept half way' );

		$retry = $this->scheduler->of( self::REFRESH );
		$this->jobs( null, $paged )->refresh_cards( ...end( $retry )['args'] );

		$this->assertSame( [ 'next' => 1 ], end( $paged->reads )[1], 'the refresh went on from the page it reached' );
		$this->assertSame( [ 'K-1', 'K-3' ], $this->listed(), 'the deleted card left with the last page' );
	}

	/**
	 * A failed refresh keeps the last list on the screen and says why.
	 *
	 * @return void
	 */
	public function test_a_failed_refresh_keeps_the_last_list(): void {
		$paged        = new Paged_Link_Resolver();
		$paged->pages = [ $this->list_rows( 'L', 2 ) ];
		$this->jobs( null, $paged )->refresh_cards( $this->account_id, 0 );

		$paged->failures = [ 0 => Fake_Remote_Exception::of( Remote_Error::FAIL, 'not allowed' ) ];
		$this->card_store()->mark_refreshing( $this->account_id );
		$this->jobs( null, $paged )->refresh_cards( $this->account_id, 0 );

		$this->assertSame( [ 'L-1', 'L-2' ], $this->listed() );
		$this->assertStringContainsString( 'not allowed', $this->card_store()->last_error( $this->account_id ) );
		$this->assertFalse( $this->card_store()->is_refreshing( $this->account_id ) );
		$this->assertSame( self::NOW, $this->card_store()->refreshed_at( $this->account_id ), 'the time of the last good refresh stays' );
	}

	/**
	 * A notification's card is rewritten from a single read: a new card is added, a known one updated in its place.
	 *
	 * @return void
	 */
	public function test_the_rows_of_named_cards_are_rewritten_from_a_single_read(): void {
		$paged        = new Paged_Link_Resolver();
		$paged->pages = [ $this->list_rows( 'N', 3 ) ];
		$this->jobs( null, $paged )->refresh_cards( $this->account_id, 0 );

		$paged->single = [
			'N-2' => array_merge( $this->detail( 2, 'N-2' ), [ 'name' => 'Новое имя', 'failed' => true, 'status_text' => 'Нет фото', 'price' => '990', 'stock' => 4 ] ),
			'NEW' => $this->detail( 9, 'NEW' ),
		];

		$this->jobs( null, $paged )->refresh_card_rows( $this->account_id, [ 'N-2', 'NEW', 'GONE' ], 0 );

		$card = $this->card_store()->find( $this->account_id, Wpdb_Card_Store::key( 'N-2' ) );

		$this->assertSame( [ 'cards', [], [ 'N-2', 'NEW', 'GONE' ] ], end( $paged->reads ) );
		$this->assertSame( 'Новое имя', $card['name'] );
		$this->assertTrue( $card['failed'] );
		$this->assertSame( 'Нет фото', $card['status_text'] );
		$this->assertSame( '990.00', $card['price'] );
		$this->assertSame( 4, $card['stock'] );
		$this->assertSame( [ 'NEW', 'N-1', 'N-2', 'N-3' ], $this->listed(), 'a known card keeps its place; a new one goes first' );
	}

	/**
	 * A card a notification wrote during a refresh is not swept by it, though the refresh did not see it.
	 *
	 * @return void
	 */
	public function test_a_card_written_by_a_notification_during_a_refresh_survives_its_sweep(): void {
		$paged = new Paged_Link_Resolver();

		for ( $page = 1; $page <= Link_Jobs::PAGES_PER_RUN + 1; $page++ ) {
			$paged->pages[] = $this->list_rows( 'R' . $page, 1 );
		}

		$this->jobs( null, $paged )->refresh_cards( $this->account_id, 0 ); // Stops at its budget.

		$paged->single = [ 'FRESH' => $this->detail( 7, 'FRESH' ) ];
		$this->jobs( null, $paged )->refresh_card_rows( $this->account_id, [ 'FRESH' ], 0 );

		$next = $this->scheduler->of( self::REFRESH );
		$this->jobs( null, $paged )->refresh_cards( ...end( $next )['args'] );

		$this->assertContains( 'FRESH', $this->listed() );
		$this->assertCount( Link_Jobs::PAGES_PER_RUN + 2, $this->listed() );
	}

	/**
	 * A resolver that cannot read single cards leaves the list to the refreshes.
	 *
	 * @return void
	 */
	public function test_a_resolver_that_cannot_read_single_cards_leaves_the_rows_alone(): void {
		$this->jobs()->refresh_card_rows( $this->account_id, [ 'OW-TEST-1' ], 0 );

		$this->assertSame( [], $this->resolver->reads );
		$this->assertSame( [], $this->listed() );
	}

	/**
	 * The daily run refreshes every account whose list was loaded once and is not being refreshed — and nothing else.
	 *
	 * @return void
	 */
	public function test_the_daily_run_refreshes_only_the_lists_that_were_loaded(): void {
		$paged        = new Paged_Link_Resolver();
		$paged->pages = [ $this->list_rows( 'D', 1 ) ];
		$this->jobs( null, $paged )->refresh_cards( $this->account_id, 0 );

		$this->jobs( null, $paged, [ $this->account_id, 999 ] )->refresh_daily();

		$queued = $this->scheduler->of( self::REFRESH );

		$this->assertCount( 1, $queued );
		$this->assertSame( [ $this->account_id, 0 ], $queued[0]['args'] );
		$this->assertTrue( $this->card_store()->is_refreshing( $this->account_id ), 'the screen says the list is being refreshed' );

		$this->jobs( null, $paged, [ $this->account_id ] )->refresh_daily();

		$this->assertCount( 1, $this->scheduler->of( self::REFRESH ), 'a refresh already marked is not queued again' );
	}

	/**
	 * The daily run is created once, as a recurring action of the plugin's group.
	 *
	 * @return void
	 */
	public function test_the_daily_run_is_scheduled_once(): void {
		$scheduler = new Link_Scheduler( $this->names() );

		$scheduler->ensure_daily();
		$scheduler->ensure_daily();

		$daily = $this->scheduler->of( self::DAILY );

		$this->assertCount( 1, $daily );
		$this->assertSame( 'recurring', $daily[0]['kind'] );
	}

	/**
	 * A refresh that gave up half way and is started again reads under a new generation: a card it saw before giving up and that
	 * was deleted meanwhile leaves the list with the successful refresh (#1868 Must 1).
	 *
	 * @return void
	 */
	public function test_a_refresh_started_over_after_a_give_up_sweeps_the_cards_of_the_abandoned_pages(): void {
		$paged           = new Paged_Link_Resolver();
		$paged->pages    = [ [ $this->detail( 1, 'A' ) ], [ $this->detail( 2, 'X' ) ] ];
		$paged->failures = [ 1 => Fake_Remote_Exception::of( Remote_Error::FAIL, 'denied' ) ];

		$this->jobs( null, $paged )->refresh_cards( $this->account_id, 0 );

		$this->assertSame( [ 'A' ], $this->listed(), 'the first page was written before the refusal' );
		$this->assertStringContainsString( 'denied', $this->card_store()->last_error( $this->account_id ) );

		// A is deleted on the marketplace; the merchant refreshes again and the cabinet holds B only.
		$paged->pages = [ [ $this->detail( 3, 'B' ) ] ];
		$paged->reads = [];

		$this->jobs( null, $paged )->refresh_cards( $this->account_id, 0 );

		$this->assertSame( [ [] ], array_column( $paged->reads, 1 ), 'the new refresh starts from the first page' );
		$this->assertSame( [ 'B' ], $this->listed(), 'the card of the abandoned page is swept' );
		$this->assertSame( '', $this->card_store()->last_error( $this->account_id ) );
	}

	/**
	 * A cursor older than the progress store trusts is a traversal started over: new generation, first page.
	 *
	 * @return void
	 */
	public function test_an_expired_cursor_starts_the_traversal_over_under_a_new_generation(): void {
		$paged        = new Paged_Link_Resolver();
		$paged->pages = [ [ $this->detail( 1, 'OLD' ) ] ];
		$this->jobs( null, $paged )->refresh_cards( $this->account_id, 0 );

		$abandoned = $this->card_store()->begin_refresh( $this->account_id, self::NOW );
		( new Link_Progress_Store( self::PREFIX ) )->save(
			$this->account_id,
			Link_Progress_Store::KIND_REFRESH,
			[
				'started_at' => self::NOW - Link_Progress_Store::MAX_AGE - 1,
				'scope'      => 0,
				'state'      => [ 'run' => $abandoned, 'page' => [ 'next' => 1 ], 'position' => 2 ],
			]
		);
		$this->card_store()->upsert( $this->account_id, [ $this->detail( 2, 'GONE' ) ], $abandoned, 2, self::NOW );

		$paged->pages = [ [ $this->detail( 3, 'NEW' ) ] ];
		$paged->reads = [];
		$this->jobs( null, $paged )->refresh_cards( $this->account_id, 0 );

		$this->assertSame( [ [] ], array_column( $paged->reads, 1 ) );
		$this->assertSame( [ 'NEW' ], $this->listed() );
	}

	/**
	 * A run whose account claim is taken over after its last page writes nothing and sweeps nothing: the new owner's list stands
	 * (#1868 Should 3).
	 *
	 * @return void
	 */
	public function test_a_refresh_that_lost_its_claim_after_the_last_page_neither_writes_nor_sweeps(): void {
		$paged        = new Paged_Link_Resolver();
		$paged->pages = [ [ $this->detail( 1, 'KEEP-1' ), $this->detail( 2, 'KEEP-2' ) ] ];
		$this->jobs( null, $paged )->refresh_cards( $this->account_id, 0 );

		$paged->pages   = [ [ $this->detail( 9, 'STALE' ) ] ];
		$paged->on_page = function (): void {
			$this->db->query( $this->db->prepare( 'UPDATE %i SET claim_token = %s, claim_until = %s WHERE account_id = %d', Sqlite_Tables::table( 'accounts' ), str_repeat( 'b', 32 ), '2099-01-01 00:00:00', $this->account_id ) );
		};

		$this->jobs( null, $paged )->refresh_cards( $this->account_id, 0 );

		$this->assertSame( [ 'KEEP-1', 'KEEP-2' ], $this->listed() );
		$this->assertSame( self::NOW, $this->card_store()->refreshed_at( $this->account_id ) );
	}
}
