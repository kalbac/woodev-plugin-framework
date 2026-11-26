<?php
/**
 * «Импортировать все по фильтру» on the «Карточки на маркетплейсе» table: the declaration, the preview and the start of the run, the freeze
 * of the cards, the feed of the import job, the account line and its buttons.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Products
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Products;

use Brain\Monkey\Functions;
use Woodev\Framework\Admin\Records\Action_State_Exception;
use Woodev\Framework\Admin\Records\Records_Definition;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Actions;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Definition;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Matching;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Provider;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Run_Actions;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Run_Line;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Bulk;
use Woodev\Framework\Marketplace\Admin\Pages\Products\Site_Products_Run_Line;
use Woodev\Framework\Marketplace\Data\Bulk_Run;
use Woodev\Framework\Marketplace\Data\Bulk_Run_Store;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Jobs\Bulk_Import_Feeder;
use Woodev\Framework\Marketplace\Jobs\Bulk_Run_Lost;
use Woodev\Framework\Marketplace\Jobs\Bulk_Run_Runner;
use Woodev\Framework\Marketplace\Jobs\Bulk_Run_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Bulk_Run_Worker;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Scripted_Bulk_Run_Lock;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Sellable_Units;

/**
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Run_Actions
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Matching
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Run_Line
 * @covers \Woodev\Framework\Marketplace\Admin\Pages\Products\Remote_Cards_Query::entries
 * @covers \Woodev\Framework\Marketplace\Jobs\Bulk_Import_Feeder
 */
final class RemoteCardsBulkImportTest extends Remote_Cards_Test_Case {
	private const IMPORT_HOOK = 'woodev_fake_seller_import_cards';
	private const RUN_HOOK    = 'woodev_fake_seller_bulk_run';

	/**
	 * @var Bulk_Run_Store
	 */
	private $runs;

	/**
	 * @var Scripted_Bulk_Run_Lock
	 */
	private $lock;

	/**
	 * @var Bulk_Run_Runner
	 */
	private $runner;

	/**
	 * @var Bulk_Import_Feeder
	 */
	private $feeder;

	/**
	 * Articles whose shop product is a half-made import: what the marker lookup answers.
	 *
	 * @var string[]
	 */
	private $half_made = [];

	/**
	 * How many times the marker lookup was asked, and about how many articles.
	 *
	 * @var int[]
	 */
	private $lookups = [];

	/**
	 * @var int
	 */
	private $tokens = 0;

	protected function setUp(): void {
		parent::setUp();

		$this->importable = true;
		$this->half_made  = [];
		$this->lookups    = [];
		$this->tokens     = 0;

		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		Functions\when( 'wp_set_current_user' )->justReturn( null );

		$this->wire();
	}

	protected function tearDown(): void {
		// PHPUnit keeps every test object until the run ends: a 50 000-card snapshot and 5 000 queued actions must not stay with it.
		$this->scheduler->actions = [];
		$this->wire(); // New feeder and runner: the old feeder holds the 50 000-card list it froze.

		parent::tearDown();
	}

	/**
	 * Wires the run engine and the table the way the module wires them, over the harness' database and scripted lock.
	 *
	 * @return void
	 */
	private function wire(): void {
		$this->runs   = new Bulk_Run_Store( $this->db, Sqlite_Tables::table( 'bulk_runs' ), $this->clock() );
		$this->lock   = new Scripted_Bulk_Run_Lock();
		$matching     = new Remote_Cards_Matching( $this->card_store() );
		$this->feeder = $this->new_feeder();
		$this->runner = $this->runner_over( $this->feeder );
		$this->run_actions = new Remote_Cards_Run_Actions(
			$this->runner,
			$this->runs,
			$matching,
			new License_Gate(
				function (): bool {
					return $this->licensed;
				}
			)
		);
	}

	/**
	 * A feeder as a tick builds it: nothing in memory from an earlier tick.
	 *
	 * @return Bulk_Import_Feeder
	 */
	private function new_feeder(): Bulk_Import_Feeder {
		return new Bulk_Import_Feeder(
			$this->runs,
			$this->import_scheduler(),
			[ new Remote_Cards_Matching( $this->card_store() ), 'entries' ],
			function ( array $articles ): array {
				$this->lookups[] = count( $articles );

				return array_values( array_intersect( $articles, $this->half_made ) );
			}
		);
	}

	/**
	 * A runner over a worker, with the harness' store, lock and token counter.
	 *
	 * @param Bulk_Run_Worker $worker Worker.
	 * @return Bulk_Run_Runner
	 */
	private function runner_over( Bulk_Run_Worker $worker ): Bulk_Run_Runner {
		return new Bulk_Run_Runner(
			$this->runs,
			$this->lock,
			new Bulk_Run_Scheduler( $this->names() ),
			$this->accounts(),
			Sqlite_Tables::MARKETPLACE_ID,
			$worker,
			null,
			null,
			function (): string {
				return str_pad( (string) ++$this->tokens, 32, '0', STR_PAD_LEFT );
			}
		);
	}

	/**
	 * A runner over a fresh feeder that a test interrupts: `$before( $call )` runs before part number `$call` (from 1), `$after( $call )`
	 * after it. Throwing {@see Bulk_Run_Lost} from either is a tick that dies there; the runner's own writes of that part are not made.
	 *
	 * @param callable|null $before Hook.
	 * @param callable|null $after  Hook.
	 * @return Bulk_Run_Runner
	 */
	private function interrupted_runner( ?callable $before, ?callable $after ): Bulk_Run_Runner {
		$feeder = $this->new_feeder();

		return $this->runner_over(
			new class( $feeder, $before, $after ) implements Bulk_Run_Worker {
				/** @var Bulk_Run_Worker */
				private $inner;
				/** @var callable|null */
				private $before;
				/** @var callable|null */
				private $after;
				/** @var int */
				private $calls = 0;

				public function __construct( Bulk_Run_Worker $inner, ?callable $before, ?callable $after ) {
					$this->inner  = $inner;
					$this->before = $before;
					$this->after  = $after;
				}

				public function refusal( Account $account, Bulk_Run $run ): ?string {
					return $this->inner->refusal( $account, $run );
				}

				public function part( Account $account, Bulk_Run $run, array $cursor, callable $owns ): array {
					++$this->calls;

					if ( null !== $this->before ) {
						call_user_func( $this->before, $this->calls );
					}

					$part = $this->inner->part( $account, $run, $cursor, $owns );

					if ( null !== $this->after ) {
						call_user_func( $this->after, $this->calls );
					}

					return $part;
				}
			}
		);
	}

	/**
	 * The first part of a run, saved the way the runner saves it: the list and the position in one fenced write.
	 *
	 * @param Bulk_Run $run The run.
	 * @return array<string,mixed> The part.
	 */
	private function freeze( Bulk_Run $run ): array {
		$feeder = $this->new_feeder();

		$this->assertNull( $feeder->refusal( $this->account_now(), $run ) );

		$part = $feeder->part(
			$this->account_now(),
			$run,
			[],
			static function (): bool {
				return true;
			}
		);

		$this->runs->take_tick( $this->account_id, $run->run_id(), 'freezer' );
		$this->assertTrue( $this->runs->save_frozen( $this->account_id, $run->run_id(), 'freezer', $part['items'], $part['cursor'], $part['counts'], $part['groups'] ) );

		return $part;
	}

	/**
	 * The provider with the import by filter wired in.
	 *
	 * @return Remote_Cards_Provider
	 */
	private function matching_provider(): Remote_Cards_Provider {
		return $this->cards_provider();
	}

	/**
	 * @param int    $count  Cards.
	 * @param string $prefix Article prefix.
	 * @return array<int,array<string,mixed>>
	 */
	private function cards( int $count, string $prefix = 'A-' ): array {
		$cards = [];

		for ( $number = 1; $number <= $count; $number++ ) {
			$cards[] = $this->card( $prefix . $number );
		}

		return $cards;
	}

	/**
	 * @param array<string,mixed> $filters Filters.
	 * @return array{search:string,filters:array<string,mixed>}
	 */
	private function query( array $filters = [] ): array {
		return [
			'search'  => '',
			'filters' => $filters,
		];
	}

	/**
	 * Links an article to a product, so the card counts as on the site.
	 *
	 * @param string $article Article.
	 * @param int    $product Product id.
	 * @return void
	 */
	private function put_on_site( string $article, int $product ): void {
		$this->add_link( $this->add_product( [ 'id' => $product, 'sku' => $article ] ), Link_Record::STATE_CREATED, [ 'offer_id' => $article ] );
	}

	/**
	 * Starts the run and hands the harness its id.
	 *
	 * @param array{search:string,filters:array<string,mixed>} $query Query.
	 * @return string Run id.
	 */
	private function start_run( array $query ): string {
		$answer = $this->matching_provider()->apply_matching( $this->context(), Remote_Cards_Definition::IMPORT_SELECTED, $query, [] );

		return (string) $answer['run']['id'];
	}

	/**
	 * Runs the ticks of the run until it is over.
	 *
	 * @param string $run_id Run.
	 * @return Bulk_Run
	 */
	private function drive( string $run_id ): Bulk_Run {
		for ( $tick = 0; $tick < 20; $tick++ ) {
			$run = $this->runs->find( $this->account_id );
			$this->assertNotNull( $run );

			if ( ! $run->is_running() ) {
				return $run;
			}

			$this->runner->tick( $this->account_id, $run_id );
		}

		$this->fail( 'The run did not finish in twenty ticks.' );
	}

	/**
	 * The articles of every queued import chunk, in the order the chunks were queued.
	 *
	 * @return string[]
	 */
	private function queued_articles(): array {
		$articles = [];

		foreach ( $this->scheduler->of( self::IMPORT_HOOK ) as $action ) {
			$articles = array_merge( $articles, $action['args'][1] );
		}

		return $articles;
	}

	// The declaration.

	public function test_the_bulk_import_declares_the_matching_mode_and_the_two_buttons_of_the_line(): void {
		$definition = Records_Definition::from_array( $this->matching_provider()->get_definition( $this->context() ) );
		$ids        = array_map(
			static function ( array $action ): string {
				return $action['id'];
			},
			$definition->to_array()['bulk_actions']
		);

		$by_id = array_column( $definition->to_array()['bulk_actions'], null, 'id' );

		$this->assertTrue( $by_id[ Remote_Cards_Definition::IMPORT_SELECTED ]['all_matching'], 'Only «Импортировать» runs over every card under the filter.' );
		$this->assertArrayNotHasKey( 'all_matching', array_filter( [ $by_id[ Remote_Cards_Definition::REFRESH ]['all_matching'] ?? null ] ) );
		$this->assertContains( Site_Products_Bulk::RUN_STOP, $ids );
		$this->assertContains( Site_Products_Bulk::RUN_REPORT, $ids );
		$this->assertSame( 'hidden', $by_id[ Site_Products_Bulk::RUN_STOP ]['fields'][0]['type'] );
	}

	public function test_a_table_that_cannot_import_has_no_bulk_import_and_no_buttons(): void {
		$this->importable  = false;
		$this->run_actions = null;
		$definition        = Records_Definition::from_array( $this->cards_provider()->get_definition( $this->context() ) );
		$ids               = array_column( $definition->to_array()['bulk_actions'], 'id' );

		$this->assertNotContains( Remote_Cards_Definition::IMPORT_SELECTED, $ids );
		$this->assertNotContains( Site_Products_Bulk::RUN_STOP, $ids );
	}

	// The preview and the start.

	public function test_the_preview_counts_the_cards_under_the_filter_and_plans_nothing(): void {
		$this->put_cards( $this->cards( 30 ) );
		$this->put_on_site( 'A-1', 41 );
		$this->put_on_site( 'A-2', 42 );

		// A plan that must not be asked: the matching mode does not plan at feed or request time.
		$answer = $this->matching_provider()->preview_matching( $this->context(), Remote_Cards_Definition::IMPORT_SELECTED, $this->query( [ 'on_site' => 'no' ] ), [] );

		$this->assertSame( 28, $answer['requested'] );
		$this->assertSame( 28, $answer['eligible'] );
		$this->assertStringContainsString( '28 карточек', $answer['scope_sentence'] );
		$this->assertStringContainsString( 'Картинки загружаются по одной', $answer['scope_sentence'] );
		$this->assertSame( [], $this->lookups, 'No lookup of the shop at preview time.' );
		$this->assertSame( [], $this->scheduler->of( self::IMPORT_HOOK ), 'Nothing is queued by a preview.' );
	}

	/**
	 * A marketplace that sells units lists sizes, and the run over the filter counts cards, as the table does: in the preview, in
	 * the total and in what it queued and skipped — also when one card's sizes are fed in two parts.
	 */
	public function test_the_run_over_a_marketplace_of_units_counts_cards_not_sizes(): void {
		$this->sells_units = true;

		$unit  = function ( string $article, string $size, int $sizes ): array {
			$card = Fake_Sellable_Units::card( $article, $size, [], $sizes );

			return $this->card( $card['offer_id'], '', $card );
		};
		$cards = [
			$unit( 'coat', 'M', 2 ),
			$unit( 'sock', 'S', 2 ),
			$unit( 'belt', 'S', 2 ),
			$unit( 'mug', '', 1 ),
		];

		for ( $size = 1; $size <= 150; $size++ ) {
			$cards[] = $unit( 'ring', (string) $size, 150 );
		}

		$this->put_cards( array_merge( $cards, [ $unit( 'coat', 'L', 2 ), $unit( 'sock', 'M', 2 ), $unit( 'belt', 'M', 2 ) ] ) );
		$this->put_on_site( 'sock|S', 41 );
		$this->put_on_site( 'sock|M', 42 );
		$this->put_on_site( 'belt|S', 43 );

		$preview = function ( array $filters ): array {
			return $this->matching_provider()->preview_matching( $this->context(), Remote_Cards_Definition::IMPORT_SELECTED, $this->query( $filters ), [] );
		};

		$this->assertSame( 4, $preview( [ 'on_site' => 'no' ] )['requested'], 'coat, ring, mug and the half-linked belt' );
		$this->assertSame( 2, $preview( [ 'on_site' => 'yes' ] )['requested'], 'sock and belt' );
		$this->assertStringContainsString( '5 карточек', $preview( [] )['scope_sentence'] );

		$run    = $this->drive( $this->start_run( $this->query() ) );
		$counts = $run->counts();

		$this->assertSame( 5, $counts['products_total'] );
		$this->assertSame( 4, $counts['offers_accepted'], 'cards queued, each once' );
		$this->assertSame( 1, $counts['offers_skipped'], 'only the card with every size on the site' );
		$this->assertCount( 154, $this->queued_articles(), 'every size that is not on the site is imported' );
		$this->assertSame( [ 'sock' ], $run->report()[0]['examples'], 'a card is named by its article, never by a unit key' );
	}

	public function test_the_preview_takes_the_genitive_after_do_for_every_count(): void {
		$this->assertStringContainsString( 'до 1 карточки', Remote_Cards_Run_Line::preview( 1 ) );
		$this->assertStringContainsString( 'до 3 карточек', Remote_Cards_Run_Line::preview( 3 ) );
		$this->assertStringContainsString( 'до 11 карточек', Remote_Cards_Run_Line::preview( 11 ) );
		$this->assertStringContainsString( 'до 21 карточки', Remote_Cards_Run_Line::preview( 21 ) );
	}

	public function test_the_count_follows_the_search_and_the_on_site_filter_like_the_table_does(): void {
		$this->put_cards( array_merge( $this->cards( 5 ), [ $this->card( 'B-1', 'Платье красное' ) ] ) );
		$this->put_on_site( 'A-3', 43 );

		$matching = new Remote_Cards_Matching( $this->card_store() );

		$this->assertSame( 6, $matching->count( $this->account_id, $this->query() ), 'No filter: every card.' );
		$this->assertSame( 5, $matching->count( $this->account_id, $this->query( [ 'on_site' => 'no' ] ) ) );
		$this->assertSame( 1, $matching->count( $this->account_id, $this->query( [ 'on_site' => 'yes' ] ) ) );
		// SQLite's LIKE ignores the case of Latin letters only; MySQL's collation ignores it for Cyrillic too (CardStoreMysqlTest).
		$this->assertSame( 1, $matching->count( $this->account_id, [ 'search' => 'Платье', 'filters' => [ 'on_site' => 'no' ] ] ), 'The search looks in the name too.' );
	}

	public function test_apply_stores_a_run_and_queues_its_first_tick_and_nothing_else(): void {
		$this->put_cards( $this->cards( 30 ) );

		$answer = $this->matching_provider()->apply_matching( $this->context(), Remote_Cards_Definition::IMPORT_SELECTED, $this->query( [ 'on_site' => 'no' ] ), [] );
		$run    = $this->runs->find( $this->account_id );

		$this->assertTrue( $answer['queued'] );
		$this->assertSame( 30, $answer['run']['products_total'] );
		$this->assertNotNull( $run );
		$this->assertSame( $answer['run']['id'], $run->run_id() );
		$this->assertSame( Remote_Cards_Definition::IMPORT_SELECTED, $run->action() );
		$this->assertSame( [ 'on_site' => 'no' ], $run->filter()['filters'], 'The filter is stored, not a list of articles.' );
		$this->assertNull( $this->runs->items( $this->account_id, $run->run_id() ), 'The list is frozen by the first tick, not by the request.' );
		$this->assertSame( [], $run->cursor() );
		$this->assertCount( 1, $this->scheduler->of( self::RUN_HOOK ), 'One tick is queued.' );
		$this->assertSame( [], $this->scheduler->of( self::IMPORT_HOOK ), 'The request queues no import.' );
		$this->assertSame( [], $this->lookups, 'The request asks the shop nothing: no plan, no article list.' );
	}

	public function test_the_import_may_not_start_without_a_licence_a_list_a_card_or_in_the_near_match_view(): void {
		$this->put_cards( $this->cards( 3 ) );

		$this->licensed = false;
		$this->assertRefused( 'Лиценз' );
		$this->licensed = true;

		$this->assertRefused( 'Похожие артикулы', [ 'show' => 'near' ] );
		$this->assertRefused( 'Список карточек устарел', [], false );
		$this->put_cards( [] );
		$this->assertRefused( 'карточек нет' );
		$this->assertNull( $this->runs->find( $this->account_id ), 'A refused start stores nothing.' );
	}

	/**
	 * @param string              $fragment A word of the refusal.
	 * @param array<string,mixed> $filters  Filters.
	 * @param bool                $listed   Whether the list is still loaded.
	 * @return void
	 */
	private function assertRefused( string $fragment, array $filters = [], bool $listed = true ): void {
		if ( ! $listed ) {
			$this->forget_cards();
		}

		foreach ( [ 'preview_matching', 'apply_matching' ] as $method ) {
			try {
				$this->matching_provider()->$method( $this->context(), Remote_Cards_Definition::IMPORT_SELECTED, $this->query( $filters ), [] );
				$this->fail( $method . ' should have been refused.' );
			} catch ( Action_State_Exception $e ) {
				$this->assertStringContainsStringIgnoringCase( $fragment, $e->getMessage() );
			}
		}
	}

	public function test_a_second_start_while_a_run_is_going_is_refused_with_its_name(): void {
		$this->put_cards( $this->cards( 3 ) );
		$this->start_run( $this->query() );

		$this->expectException( Action_State_Exception::class );
		$this->expectExceptionMessage( 'Уже выполняется «Импортировать»' );

		$this->matching_provider()->apply_matching( $this->context(), Remote_Cards_Definition::IMPORT_SELECTED, $this->query(), [] );
	}

	public function test_only_import_runs_over_every_card_and_only_when_the_adapter_can_import(): void {
		$this->put_cards( $this->cards( 3 ) );

		$this->expectException( Action_State_Exception::class );

		$this->matching_provider()->preview_matching( $this->context(), Remote_Cards_Definition::REFRESH, $this->query(), [] );
	}

	// The freeze and the feed.

	public function test_the_first_tick_freezes_the_cards_and_the_next_parts_feed_the_import_job(): void {
		$this->put_cards( $this->cards( 25 ) );
		$run_id = $this->start_run( $this->query() );

		$run = $this->drive( $run_id );

		$this->assertSame( Bulk_Run::DONE, $run->state() );
		$this->assertSame( 25, $run->counts()['offers_accepted'] );
		$this->assertSame( 25, $run->counts()['products_done'] );
		$this->assertSame( array_column( $this->cards( 25 ), 'offer_id' ), $this->queued_articles(), 'Every card is queued once, in the snapshot order.' );
		$this->assertCount( 3, $this->scheduler->of( self::IMPORT_HOOK ), 'Ten cards to an action: 10 + 10 + 5.' );
		$this->assertCount( 25, (array) $this->runs->items( $this->account_id, $run_id ) );
		$this->assertSame( [ 25 ], $this->lookups, 'The shop is asked once, for all the articles together.' );
	}

	public function test_a_part_never_queues_more_than_ten_import_actions_and_the_parts_are_a_second_apart(): void {
		$this->put_cards( $this->cards( 300 ) );
		$this->start_run( $this->query() );
		$run = $this->runs->find( $this->account_id );
		$this->assertNotNull( $run );
		$account = $this->account_now();
		$owns    = static function (): bool {
			return true;
		};

		$first  = $this->freeze( $run );
		$feeder = $this->new_feeder();
		$this->assertTrue( $first['cursor']['frozen'] );
		$this->assertSame( [], $this->scheduler->of( self::IMPORT_HOOK ), 'The freeze feeds nothing.' );

		$cursor = $first['cursor'];

		for ( $part = 0; $part < 3; $part++ ) {
			$before = count( $this->scheduler->of( self::IMPORT_HOOK ) );
			$answer = $feeder->part( $account, $run, $cursor, $owns );
			$cursor = $answer['cursor'];

			$this->assertLessThanOrEqual( 10, count( $this->scheduler->of( self::IMPORT_HOOK ) ) - $before, 'A part queues at most ten actions.' );
			$this->assertSame( Bulk_Import_Feeder::FEED, $answer['counts']['offers_accepted'] );
			$this->assertSame( ( $part + 1 ) * Bulk_Import_Feeder::FEED, $cursor['index'] );
			$this->assertSame( 2 === $part, $answer['finished'], 'Only the last part finishes the run.' );
		}

		$delays = array_map(
			static function ( array $action ): int {
				return $action['delay'];
			},
			$this->scheduler->of( self::IMPORT_HOOK )
		);

		$this->assertEqualsWithDelta( 0, $delays[0], 1, 'Part 0 starts at once.' );
		$this->assertEqualsWithDelta( 1, $delays[10], 1, 'Part 1 one second after part 0.' );
		$this->assertEqualsWithDelta( 2, $delays[20], 1, 'Part 2 one second after part 1.' );
	}

	public function test_the_snapshot_expiring_between_the_freeze_and_the_feed_changes_nothing(): void {
		$this->put_cards( $this->cards( 12 ) );
		$this->start_run( $this->query() );
		$run     = $this->runs->find( $this->account_id );
		$account = $this->account_now();
		$owns    = static function (): bool {
			return true;
		};
		$this->assertNotNull( $run );

		$frozen = $this->freeze( $run );

		$this->forget_cards(); // The list is gone.

		$fed = $this->new_feeder()->part( $account, $run, $frozen['cursor'], $owns );

		$this->assertSame( 12, $fed['counts']['offers_accepted'] );
		$this->assertTrue( $fed['finished'] );
		$this->assertCount( 12, $this->queued_articles() );
	}

	/**
	 * What happens to the snapshot after the tick died: the list is gone, or replaced by an empty one, or by other cards.
	 *
	 * @return array<string,array{0:callable}>
	 */
	public function snapshot_after_the_crash(): array {
		return [
			'expired'                   => [
				function (): void {
					$this->forget_cards();
				},
			],
			'refreshed to no cards'     => [
				function (): void {
					$this->put_cards( [] );
				},
			],
			'refreshed to other cards'  => [
				function (): void {
					$this->put_cards( $this->cards( 3, 'Z-' ) );
				},
			],
		];
	}

	public function test_a_tick_that_dies_before_the_freeze_is_saved_leaves_a_run_that_has_frozen_nothing(): void {
		$this->put_cards( $this->cards( 12 ) );
		$run_id = $this->start_run( $this->query() );

		// The freeze was computed; the process dies before the runner's one write of the list and the position.
		$this->interrupted_runner(
			null,
			static function ( int $call ): void {
				throw new Bulk_Run_Lost( 'The tick died after part ' . $call );
			}
		)->tick( $this->account_id, $run_id );
		$run = $this->runs->find( $this->account_id );

		$this->assertNotNull( $run );
		$this->assertTrue( $run->is_running() );
		$this->assertNull( $this->runs->items( $this->account_id, $run_id ), 'No list without the position that goes with it …' );
		$this->assertSame( [], $run->cursor(), '… and no position without the list.' );
		$this->assertSame( 0, $run->counts()['offers_skipped'] );

		// Recovery freezes it again from the snapshot that is still there, and feeds every card once.
		$this->runner_over( $this->new_feeder() )->tick( $this->account_id, $run_id );
		$done = $this->runs->find( $this->account_id );

		$this->assertNotNull( $done );
		$this->assertSame( Bulk_Run::DONE, $done->state() );
		$this->assertSame( 12, $done->counts()['offers_accepted'] );
		$this->assertSame( array_column( $this->cards( 12 ), 'offer_id' ), $this->queued_articles() );
	}

	public function test_a_run_that_froze_nothing_before_its_tick_died_stops_with_the_apply_sentence_when_the_list_is_gone(): void {
		$this->put_cards( $this->cards( 12 ) );
		$run_id = $this->start_run( $this->query() );

		$this->interrupted_runner(
			null,
			static function ( int $call ): void {
				throw new Bulk_Run_Lost( 'The tick died after part ' . $call );
			}
		)->tick( $this->account_id, $run_id );
		$this->forget_cards();

		$this->runner_over( $this->new_feeder() )->tick( $this->account_id, $run_id );
		$run = $this->runs->find( $this->account_id );

		$this->assertNotNull( $run );
		$this->assertSame( Bulk_Run::STOPPED, $run->state(), 'Nothing durable was frozen, so there is nothing to feed.' );
		$this->assertStringContainsString( 'Список карточек устарел', Remote_Cards_Run_Line::finished( $run ) );
		$this->assertSame( [], $this->queued_articles() );
	}

	/**
	 * @dataProvider snapshot_after_the_crash
	 * @param callable $change What happens to the snapshot.
	 * @return void
	 */
	public function test_a_tick_that_dies_after_the_freeze_is_saved_still_feeds_the_frozen_cards_once_whatever_the_snapshot_became( callable $change ): void {
		$this->put_cards( $this->cards( 12 ) );
		$run_id = $this->start_run( $this->query() );

		// The freeze part is saved; the process dies at the start of the next part, before anything is fed.
		$this->interrupted_runner(
			static function ( int $call ): void {
				if ( 2 === $call ) {
					throw new Bulk_Run_Lost( 'The tick died before part 2' );
				}
			},
			null
		)->tick( $this->account_id, $run_id );
		$run = $this->runs->find( $this->account_id );

		$this->assertNotNull( $run );
		$this->assertCount( 12, (array) $this->runs->items( $this->account_id, $run_id ), 'The list is stored …' );
		$this->assertSame( [ 'frozen' => true, 'index' => 0 ], $run->cursor(), '… with the position that says so.' );
		$this->assertSame( [], $this->queued_articles(), 'Nothing was fed yet.' );

		\Closure::fromCallable( $change )->call( $this ); // The data provider's closures are bound to the provider's instance.
		$this->runner_over( $this->new_feeder() )->tick( $this->account_id, $run_id );
		$done = $this->runs->find( $this->account_id );

		$this->assertNotNull( $done );
		$this->assertSame( Bulk_Run::DONE, $done->state() );
		$this->assertSame( 12, $done->counts()['offers_accepted'], 'The frozen cards are fed, whatever the snapshot is now.' );
		$this->assertSame( 12, $done->counts()['products_done'] );
		$this->assertSame( 0, $done->counts()['offers_skipped'] );
		$this->assertSame( array_column( $this->cards( 12 ), 'offer_id' ), $this->queued_articles(), 'Each card once, in the frozen order.' );
	}

	public function test_a_tick_that_dies_after_the_freeze_keeps_the_counters_and_the_report_of_the_freeze(): void {
		$this->put_cards( $this->cards( 5 ) );
		$this->put_on_site( 'A-2', 42 );
		$run_id = $this->start_run( $this->query() );

		$this->interrupted_runner(
			static function ( int $call ): void {
				if ( 2 === $call ) {
					throw new Bulk_Run_Lost( 'The tick died before part 2' );
				}
			},
			null
		)->tick( $this->account_id, $run_id );
		$this->put_cards( [] );

		$this->runner_over( $this->new_feeder() )->tick( $this->account_id, $run_id );
		$done = $this->runs->find( $this->account_id );

		$this->assertNotNull( $done );
		$this->assertSame( 4, $done->counts()['offers_accepted'] );
		$this->assertSame( 1, $done->counts()['offers_skipped'], 'The card a product carries was counted once, by the freeze that was saved.' );
		$this->assertSame( 'Уже на сайте', $done->report()[0]['reason'] );
		$this->assertSame( [ 'A-1', 'A-3', 'A-4', 'A-5' ], $this->queued_articles() );
	}

	public function test_a_refused_freeze_save_stores_nothing_and_queues_nothing(): void {
		$this->put_cards( $this->cards( 12 ) );
		$run_id = $this->start_run( $this->query() );

		// Another tick takes the run between the freeze and its save.
		$this->interrupted_runner(
			null,
			function () use ( $run_id ): void {
				$this->runs->take_tick( $this->account_id, $run_id, 'intruder' );
			}
		)->tick( $this->account_id, $run_id );
		$run = $this->runs->find( $this->account_id );

		$this->assertNotNull( $run );
		$this->assertTrue( $run->is_running() );
		$this->assertNull( $this->runs->items( $this->account_id, $run_id ) );
		$this->assertSame( [], $run->cursor() );
		$this->assertSame( 0, $run->counts()['offers_skipped'], 'The counters the refused tick computed are not kept.' );
		$this->assertSame( [], $this->queued_articles(), 'It feeds nothing.' );
		$this->assertCount( 1, $this->scheduler->of( self::RUN_HOOK ), 'It queues no next tick: the tick that owns the run does.' );
	}

	public function test_a_refused_freeze_save_does_not_finish_a_run_with_nothing_to_import(): void {
		$this->put_cards( $this->cards( 3 ) );

		foreach ( [ 'A-1' => 41, 'A-2' => 42, 'A-3' => 43 ] as $article => $product ) {
			$this->put_on_site( $article, $product );
		}

		$run_id = $this->start_run( $this->query() );

		// Every card is on the site: the freeze is empty and would finish the run — but the tick lost it.
		$this->interrupted_runner(
			null,
			function () use ( $run_id ): void {
				$this->runs->take_tick( $this->account_id, $run_id, 'intruder' );
			}
		)->tick( $this->account_id, $run_id );
		$run = $this->runs->find( $this->account_id );

		$this->assertNotNull( $run );
		$this->assertSame( Bulk_Run::RUNNING, $run->state(), 'A tick that lost the run does not end it with its own completion.' );
		$this->assertSame( 0, $run->counts()['offers_skipped'] );
	}

	public function test_a_run_whose_list_expired_before_the_freeze_stops_with_the_apply_sentence(): void {
		$this->put_cards( $this->cards( 5 ) );
		$run_id = $this->start_run( $this->query() );

		$this->forget_cards();

		$this->runner->tick( $this->account_id, $run_id );
		$run = $this->runs->find( $this->account_id );

		$this->assertNotNull( $run );
		$this->assertSame( Bulk_Run::STOPPED, $run->state() );
		$this->assertStringContainsString( 'Список карточек устарел', Remote_Cards_Run_Line::finished( $run ) );
		$this->assertSame( [], $this->scheduler->of( self::IMPORT_HOOK ) );
	}

	public function test_the_half_made_product_is_fed_before_the_siblings_of_its_group(): void {
		$cards = [
			$this->card( 'S-1', '', [ 'group' => [ 'key' => 'grp-1' ] ] ),
			$this->card( 'X-1' ),
			$this->card( 'R-1', '', [ 'group' => [ 'key' => 'grp-1' ] ] ),
			$this->card( 'Y-1' ),
			$this->card( 'T-1', '', [ 'group' => [ 'key' => 'grp-2' ] ] ),
			$this->card( 'U-1', '', [ 'group' => [ 'key' => 'grp-1' ] ] ),
		];
		$this->half_made = [ 'R-1' ];
		$this->put_cards( $cards );
		$run_id = $this->start_run( $this->query() );

		$this->drive( $run_id );

		$this->assertSame(
			[ 'R-1', 'S-1', 'U-1', 'X-1', 'Y-1', 'T-1' ],
			$this->queued_articles(),
			'The resuming article first although it sorts after S-1; then the cards of one group side by side, a group at its first card.'
		);
	}

	public function test_the_marker_lookup_goes_in_batches(): void {
		$this->put_cards( $this->cards( 2300 ) );
		$run_id = $this->start_run( $this->query() );

		$this->drive( $run_id );

		$this->assertSame( [ 2300 ], $this->lookups, 'The feeder hands the whole list to the lookup, which batches it.' );
		$this->assertSame( 1000, Bulk_Import_Feeder::LOOKUP );
	}

	public function test_a_card_a_product_carries_is_left_out_with_its_reason(): void {
		$this->put_cards( $this->cards( 5 ) );
		$this->put_on_site( 'A-2', 42 );
		$run_id = $this->start_run( $this->query( [] ) );

		$run = $this->drive( $run_id );

		$this->assertSame( 4, $run->counts()['offers_accepted'] );
		$this->assertSame( 1, $run->counts()['offers_skipped'] );
		$this->assertSame( [ 'A-1', 'A-3', 'A-4', 'A-5' ], $this->queued_articles() );
		$this->assertSame( 'Уже на сайте', $run->report()[0]['reason'] );
		$this->assertSame( [ 'A-2' ], $run->report()[0]['examples'] );
	}

	public function test_near_match_pairs_are_never_imported(): void {
		$this->put_cards( $this->cards( 3 ) );
		$this->reports()->replace_near_matches( $this->account_id, [ $this->near_entry( 41, 'abc-001', 'NEAR-1' ) ] );
		$run_id = $this->start_run( $this->query( [ 'on_site' => 'no' ] ) );

		$this->drive( $run_id );

		$this->assertNotContains( 'NEAR-1', $this->queued_articles(), 'The pairs are a report the merchant answers row by row.' );
		$this->assertCount( 3, $this->queued_articles() );
		$this->assertRefused( 'Похожие артикулы', [ 'show' => 'near' ] );
	}

	public function test_a_new_start_clears_the_list_of_the_run_before(): void {
		$this->put_cards( $this->cards( 4 ) );
		$first = $this->start_run( $this->query() );
		$this->drive( $first );
		$this->assertCount( 4, (array) $this->runs->items( $this->account_id, $first ) );

		$this->put_cards( $this->cards( 2, 'B-' ) );
		$second = $this->start_run( $this->query() );

		$this->assertNull( $this->runs->items( $this->account_id, $second ), 'The new run starts with no list.' );
		$this->assertNull( $this->runs->items( $this->account_id, $first ), 'The old list is gone with the old row.' );

		$this->drive( $second );

		$this->assertSame( [ 'B-1', 'B-2' ], array_column( (array) $this->runs->items( $this->account_id, $second ), 0 ) );
	}

	public function test_a_tick_that_lost_the_run_feeds_nothing(): void {
		$this->put_cards( $this->cards( 4 ) );
		$run_id = $this->start_run( $this->query() );
		$run    = $this->runs->find( $this->account_id );
		$this->assertNotNull( $run );

		$this->feeder->refusal( $this->account_now(), $run );
		$this->expectException( \Woodev\Framework\Marketplace\Jobs\Bulk_Run_Lost::class );

		try {
			$this->feeder->part(
				$this->account_now(),
				$run,
				[],
				static function (): bool {
					return false;
				}
			);
		} finally {
			$this->assertNull( $this->runs->items( $this->account_id, $run_id ), 'A tick that lost the lock stores no list.' );
			$this->assertSame( [], $this->scheduler->of( self::IMPORT_HOOK ) );
		}
	}

	public function test_a_user_who_lost_the_right_stops_the_run(): void {
		$this->put_cards( $this->cards( 4 ) );
		$run_id = $this->start_run( $this->query() );

		Functions\when( 'user_can' )->justReturn( false );
		$this->runner->tick( $this->account_id, $run_id );
		$run = $this->runs->find( $this->account_id );

		$this->assertNotNull( $run );
		$this->assertSame( Bulk_Run::STOPPED, $run->state() );
		$this->assertSame( [], $this->scheduler->of( self::IMPORT_HOOK ) );
	}

	public function test_fifty_thousand_cards_are_counted_frozen_and_fed_without_a_lookup_per_card(): void {
		$cards = [];

		for ( $number = 1; $number <= 50000; $number++ ) {
			$cards[] = [
				'offer_id' => 'A-' . $number,
				'name'     => 'Карточка ' . $number,
				'group'    => 0 === $number % 5 ? [ 'key' => 'g-' . intdiv( $number, 5 ) ] : [],
			];
		}

		$this->put_cards( $cards );
		unset( $cards );
		$matching = new Remote_Cards_Matching( $this->card_store() );

		$this->assertSame( 50000, $matching->count( $this->account_id, $this->query( [ 'on_site' => 'no' ] ) ) );

		$run_id = $this->start_run( $this->query( [ 'on_site' => 'no' ] ) );
		$run    = $this->drive( $run_id );
		$this->assertSame( Bulk_Run::DONE, $run->state() );
		$stored = (string) $this->db->get_var( 'SELECT items FROM ' . Sqlite_Tables::table( 'bulk_runs' ) ); // Not decoded: 50 000 pairs of PHP arrays are the memory the freeze must not need.

		$this->assertSame( 49999, substr_count( $stored, '],[' ), 'One pair per card.' );
		$this->assertSame( 50000, $run->counts()['offers_accepted'] );
		$this->assertSame( [ 50000 ], $this->lookups, 'One lookup call for the list, never one per card.' );
		$this->assertLessThan( 4 * 1024 * 1024, strlen( $stored ), 'The stored list stays far under max_allowed_packet.' );
	}

	// The account line and its buttons.

	public function test_the_account_line_speaks_in_cards_and_carries_stop_and_report(): void {
		$this->put_cards( $this->cards( 30 ) );
		$run_id = $this->start_run( $this->query() );

		$running = $this->matching_provider()->get_activity( $this->context(), [] )['account_activity'];

		$this->assertTrue( $running['active'] );
		$this->assertStringContainsString( 'Импорт по фильтру: готовим список карточек', $running['text'] );
		$this->assertSame( [ Site_Products_Bulk::RUN_STOP, Site_Products_Bulk::RUN_REPORT ], array_column( $running['actions'], 'action' ) );
		$this->assertSame( [ Site_Products_Bulk::RUN_FIELD => $run_id ], $running['actions'][0]['payload'] );

		$this->drive( $run_id );
		$finished = $this->matching_provider()->get_activity( $this->context(), [] )['account_activity'];

		$this->assertTrue( $finished['active'], 'The cards the run queued are still being imported.' );
		$this->assertStringContainsString( 'Поставлено в очередь на импорт: 30 карточек; пропущено 0. Результат каждой карточки — в её строке.', $finished['text'] );
		$this->assertStringContainsString( 'Импортируем карточки…', $finished['text'] );
		$this->assertSame( [ Site_Products_Bulk::RUN_REPORT ], array_column( $finished['actions'], 'action' ) );
	}

	public function test_a_finished_run_of_the_products_table_does_not_hide_the_imports_that_are_going(): void {
		$this->put_cards( $this->cards( 5 ) );

		// «Включить синхронизацию остатков» finished a minute ago and its result stays on the line for a day.
		$this->assertTrue( $this->runs->start( $this->account_id, 'old-run', 'sync_stock_on', [], [ 'search' => '', 'filters' => [] ], 3, 5 ) );
		$this->runs->take_tick( $this->account_id, 'old-run', 'old-tick' );
		$this->runs->finish( $this->account_id, 'old-run', 'old-tick', Bulk_Run::DONE, [], [ 'products_total' => 5, 'products_done' => 5 ], [] );
		$this->import_scheduler()->import( $this->account_id, [ 'A-1', 'A-2' ] ); // A ticked import, selection mode.

		$line = $this->matching_provider()->get_activity( $this->context(), [] )['account_activity'];

		$this->assertTrue( $line['active'], 'Imports are going, whatever an old run says.' );
		$this->assertStringContainsString( 'Готово: товаров 5', $line['text'] );
		$this->assertStringContainsString( 'Импортируем карточки…', $line['text'] );
		$this->assertSame( [ Site_Products_Bulk::RUN_REPORT ], array_column( $line['actions'], 'action' ), 'The old run keeps its own button.' );
	}

	public function test_the_running_import_does_not_say_it_twice(): void {
		$this->put_cards( $this->cards( 5 ) );
		$this->start_run( $this->query() );
		$this->import_scheduler()->import( $this->account_id, [ 'A-1' ] );

		$line = $this->matching_provider()->get_activity( $this->context(), [] )['account_activity'];

		$this->assertTrue( $line['active'] );
		$this->assertStringContainsString( 'Импорт по фильтру', $line['text'] );
		$this->assertStringNotContainsString( 'Импортируем карточки…', $line['text'], 'The run\'s own line already says it.' );
	}

	public function test_the_products_table_reads_an_import_run_in_cards_too(): void {
		$this->put_cards( $this->cards( 3 ) );
		$this->start_run( $this->query() );

		$line = Site_Products_Run_Line::line( $this->runs->find( $this->account_id ), time() );

		$this->assertNotNull( $line );
		$this->assertStringContainsString( 'Импорт по фильтру', $line['text'] );
		$this->assertStringNotContainsString( 'вариантов и простых товаров', $line['text'] );
	}

	public function test_the_report_button_shows_the_stored_groups_and_stop_ends_a_run_nobody_works_on(): void {
		$this->put_cards( $this->cards( 5 ) );
		$this->put_on_site( 'A-1', 41 );
		$run_id = $this->start_run( $this->query() );
		$this->drive( $run_id );

		$report = $this->matching_provider()->preview( $this->context(), Site_Products_Bulk::RUN_REPORT, [], [ Site_Products_Bulk::RUN_FIELD => $run_id ] );

		$this->assertSame( 'Уже на сайте', $report['groups'][0]['reason'] );
		$this->assertFalse( $report['can_apply'] );

		$this->put_cards( $this->cards( 5, 'C-' ) );
		$next = $this->start_run( $this->query() );

		$stopped = $this->matching_provider()->apply( $this->context(), Site_Products_Bulk::RUN_STOP, [], [ Site_Products_Bulk::RUN_FIELD => $next ] );
		$run     = $this->runs->find( $this->account_id );

		$this->assertSame( 1, $stopped['accepted'] );
		$this->assertNotNull( $run );
		$this->assertSame( Bulk_Run::CANCELLED, $run->state() );

		$gone = $this->matching_provider()->apply( $this->context(), Site_Products_Bulk::RUN_STOP, [], [ Site_Products_Bulk::RUN_FIELD => $run_id ] );

		$this->assertSame( 0, $gone['accepted'], 'A run id that is not the current run changes nothing.' );
	}

	public function test_the_wording_of_a_count_of_cards_follows_russian_plurals(): void {
		$this->assertSame( "1 карточка", Remote_Cards_Run_Line::cards( 1 ) );
		$this->assertSame( "3 карточки", Remote_Cards_Run_Line::cards( 3 ) );
		$this->assertSame( "11 карточек", Remote_Cards_Run_Line::cards( 11 ) );
		$this->assertSame( "12\u{00A0}345 карточек", Remote_Cards_Run_Line::cards( 12345 ) );
		$this->assertSame( "21 карточка", Remote_Cards_Run_Line::cards( 21 ) );
	}

	/**
	 * The account of the harness.
	 *
	 * @return Account
	 */
	protected function account_now(): Account {
		return parent::account_now();
	}
}
