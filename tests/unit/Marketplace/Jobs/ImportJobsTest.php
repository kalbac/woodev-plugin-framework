<?php
/**
 * The `import_cards` job: gate, claim, the batch, the claim and the budget, and the failure ladder.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Jobs;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Catalog\Card_Content;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Link_Report_Store;
use Woodev\Framework\Marketplace\Flows\Card_Import;
use Woodev\Framework\Marketplace\Flows\Card_Linker;
use Woodev\Framework\Marketplace\Flows\Group_Assembler;
use Woodev\Framework\Marketplace\Jobs\Account_Job;
use Woodev\Framework\Marketplace\Jobs\Backoff;
use Woodev\Framework\Marketplace\Jobs\Import_Jobs;
use Woodev\Framework\Marketplace\Jobs\Import_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\Remote_Error;
use Woodev\Framework\Marketplace\Settings\Effective_Settings;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Export_Test_Case;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Remote_Exception;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Scripted_Card_Importer;

/**
 * @covers \Woodev\Framework\Marketplace\Jobs\Import_Jobs
 * @covers \Woodev\Framework\Marketplace\Jobs\Import_Budget
 * @covers \Woodev\Framework\Marketplace\Jobs\Import_Scheduler
 */
final class ImportJobsTest extends Export_Test_Case {
	private const HOOK   = 'woodev_fake_seller_import_cards';
	private const PREFIX = 'fake_seller_';
	private const GIVE_UP = 'Не удалось импортировать карточку. Попробуйте ещё раз позже.';

	/**
	 * Options of the test, by name.
	 *
	 * @var array<string,mixed>
	 */
	private $options = [];

	/**
	 * Product ids by SKU.
	 *
	 * @var array<string,int>
	 */
	private $by_sku = [];

	/**
	 * The marketplace.
	 *
	 * @var Scripted_Card_Importer
	 */
	private $importer;

	/**
	 * The test clock of the budget, in seconds.
	 *
	 * @var float
	 */
	private $mono = 1000.0;

	/**
	 * Seconds the writer spends on one card.
	 *
	 * @var float
	 */
	private $card_seconds = 0.0;

	/**
	 * Seconds the writer spends on one image.
	 *
	 * @var float
	 */
	private $image_seconds = 0.0;

	/**
	 * Images the writer loads for a card, by article.
	 *
	 * @var array<string,int>
	 */
	private $images = [];

	/**
	 * Images loaded so far.
	 *
	 * @var int
	 */
	private $downloaded = 0;

	/**
	 * Images already stored, as `article:index`: the next run finds them by their source URL and downloads nothing.
	 *
	 * @var array<string,true>
	 */
	private $cached = [];

	/**
	 * Images whose download fails, as `article:index`.
	 *
	 * @var array<string,true>
	 */
	private $failing = [];

	/**
	 * Whether a product is an unfinished import, by product id.
	 *
	 * @var array<int,bool>
	 */
	private $marked = [];

	/**
	 * Products the writer created, by article.
	 *
	 * @var int
	 */
	private $products_made = 0;

	/**
	 * Articles the writer refuses, with the message.
	 *
	 * @var array<string,string>
	 */
	private $refused = [];

	/**
	 * Next product id.
	 *
	 * @var int
	 */
	private $next_id = 900;

	/**
	 * Stubs the shop and fills the marketplace with three cards.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->options       = [];
		$this->by_sku        = [];
		$this->mono          = 1000.0;
		$this->card_seconds  = 0.0;
		$this->image_seconds = 0.0;
		$this->images        = [];
		$this->downloaded    = 0;
		$this->refused       = [];
		$this->cached        = [];
		$this->failing       = [];
		$this->marked        = [];
		$this->products_made       = 0;
		$this->next_id       = 900;
		$this->importer      = new Scripted_Card_Importer();

		Functions\when( 'get_option' )->alias(
			function ( string $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				unset( $this->options[ $name ] );

				return true;
			}
		);
		Functions\when( 'wc_get_product_id_by_sku' )->alias(
			function ( string $sku ): int {
				return $this->by_sku[ $sku ] ?? 0;
			}
		);

		foreach ( [ 'A-1', 'A-2', 'A-3' ] as $index => $article ) {
			$this->importer->cards[ $article ] = $this->card( $article, 11 + $index );
		}
	}

	/**
	 * Builds a card.
	 *
	 * @param string $article Article.
	 * @param int    $ref     Card reference.
	 * @return Card_Content
	 */
	private function card( string $article, int $ref ): Card_Content {
		return new Card_Content(
			[
				'offer_id'   => $article,
				'remote_ids' => [ 'card_ref' => $ref ],
				'state'      => Link_Record::STATE_CREATED,
				'is_created' => true,
			],
			[ 'name' => 'Карточка ' . $article ]
		);
	}

	/**
	 * Builds the job over the scripted marketplace and a writer that spends the time the test says.
	 *
	 * @return Import_Jobs
	 */
	private function jobs(): Import_Jobs {
		$notes  = $this->notes();
		$import = new Card_Import(
			$this->links(),
			new Card_Linker( $this->links() ),
			$notes,
			new Effective_Settings( Sqlite_Tables::MARKETPLACE_ID, [ $this, 'read_option' ] ),
			new Group_Assembler( $this->links() ),
			function ( Account $account, Card_Content $content, bool $create_categories ): \WC_Product {
				return $this->start( (string) $content->card()['offer_id'] );
			},
			function ( Account $account, Card_Content $content, \WC_Product $product, bool $publish, callable $renew, ?callable $stored = null ): array {
				$this->load_images( (string) $content->card()['offer_id'], $renew, $stored );
				$this->marked[ (int) $product->get_id() ] = false;

				return [];
			}
		);

		return new Import_Jobs(
			$this->job_gate(),
			$this->importer,
			$import,
			$notes,
			new Import_Scheduler( $this->names() ),
			$this->classifier,
			$this->ring(),
			[ $this, 'log_line' ],
			function (): float {
				return $this->mono;
			}
		);
	}

	/**
	 * Option reader of the settings.
	 *
	 * @param string $name     Option.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public function read_option( string $name, $fallback = false ) {
		return $this->options[ $name ] ?? $fallback;
	}

	/**
	 * Plays the first save of the product writer: the product exists, marked. The images and the final save (the marker goes)
	 * are the writer's second half, which the importer calls next.
	 *
	 * @param string $article Article.
	 * @return \WC_Product
	 */
	private function start( string $article ): \WC_Product {
		if ( isset( $this->refused[ $article ] ) ) {
			throw new \RuntimeException( $this->refused[ $article ] );
		}

		$id      = $this->next_id++;
		$product = \Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_id' )->andReturn( $id );
		$product->shouldReceive( 'get_parent_id' )->andReturn( 0 );
		$product->shouldReceive( 'get_meta' )->andReturnUsing(
			function () use ( $id ): string {
				return ! empty( $this->marked[ $id ] ) ? '1' : '';
			}
		);

		++$this->products_made;
		$this->products[ $id ]    = $product;
		$this->by_sku[ $article ] = $id;
		$this->marked[ $id ]      = true;

		return $product;
	}

	/**
	 * Plays the image loop of the writer: ask before each image, find a stored one by its URL, else download it; only a new
	 * image that was stored is reported.
	 *
	 * @param string        $article Article.
	 * @param callable      $renew   Claim renewal and budget check.
	 * @param callable|null $stored  Called after a new image was stored.
	 * @return void
	 */
	private function load_images( string $article, callable $renew, ?callable $stored ): void {
		for ( $image = 0; $image < ( $this->images[ $article ] ?? 0 ); $image++ ) {
			$renew();

			$key = $article . ':' . $image;

			if ( isset( $this->cached[ $key ] ) ) {
				continue;
			}

			++$this->downloaded;
			$this->mono += $this->image_seconds;

			if ( isset( $this->failing[ $key ] ) ) {
				continue;
			}

			$this->cached[ $key ] = true;

			if ( null !== $stored ) {
				$stored();
			}
		}

		$this->mono += $this->card_seconds;
	}

	/**
	 * Notes.
	 *
	 * @return Link_Report_Store
	 */
	private function notes(): Link_Report_Store {
		return new Link_Report_Store( self::PREFIX );
	}

	/**
	 * The note of a card.
	 *
	 * @param string $article Article.
	 * @return array{offer_id:string,text:string,detail:string}|null
	 */
	private function note( string $article ): ?array {
		foreach ( $this->notes()->import_notes( $this->account_id ) as $note ) {
			if ( $article === $note['offer_id'] ) {
				return $note;
			}
		}

		return null;
	}

	/**
	 * Articles that have a link now.
	 *
	 * @return string[]
	 */
	private function linked(): array {
		$linked = [];

		foreach ( [ 'A-1', 'A-2', 'A-3' ] as $article ) {
			if ( null !== $this->links()->find_by_offer_id( $this->account_id, $article ) ) {
				$linked[] = $article;
			}
		}

		return $linked;
	}

	/**
	 * Claim renewals the database saw.
	 *
	 * @return int
	 */
	private function renewals(): int {
		return count(
			array_filter(
				$this->db->statements,
				static function ( string $sql ): bool {
					return 1 === preg_match( '/^UPDATE\s+\S*accounts\S*\s+SET\s+claim_until\s*=/i', $sql );
				}
			)
		);
	}

	/**
	 * Takes the account claim away right before the next statement that renews it.
	 *
	 * @return void
	 */
	private function take_over(): void {
		$this->db->update(
			Sqlite_Tables::table( 'accounts' ),
			[
				'claim_token' => 'another-run',
				'claim_until' => '2100-01-01 00:00:00',
			],
			[ 'account_id' => $this->account_id ]
		);
	}

	/**
	 * The batch is read once, every card is imported, nothing is queued, and the claim is let go.
	 *
	 * @return void
	 */
	public function test_a_batch_is_read_once_and_every_card_is_imported(): void {
		$this->jobs()->import_cards( $this->account_id, [ 'A-1', 'A-2', 'A-3' ], 0 );

		$this->assertSame( [ [ self::HOOK, [ 'A-1', 'A-2', 'A-3' ] ] ], $this->importer->reads, 'one request for the whole batch' );
		$this->assertSame( [ 'A-1', 'A-2', 'A-3' ], $this->linked() );
		$this->assertSame( [], $this->scheduler->of( self::HOOK ) );
		$this->assertSame( [], $this->notes()->import_notes( $this->account_id ) );
		$this->assertNotNull( $this->accounts()->claim( $this->account_id, 120 ), 'the claim was released' );
	}

	/**
	 * A batch with no article does nothing, not even a read.
	 *
	 * @return void
	 */
	public function test_an_empty_batch_reads_nothing(): void {
		$this->jobs()->import_cards( $this->account_id, [], 0 );

		$this->assertSame( [], $this->importer->reads );
	}

	/**
	 * The job is hooked with its four arguments: the account, the articles, the attempts and the put-offs.
	 *
	 * @return void
	 */
	public function test_the_job_is_hooked_with_four_arguments(): void {
		Actions\expectAdded( self::HOOK )->once()->with( \Mockery::type( 'array' ), 10, 4 );

		$this->jobs()->register();

		$this->addToAssertionCount( 1 ); // The expectation above is the assertion.
	}

	/**
	 * A card the marketplace did not answer for gets a note; the rest of the batch goes on.
	 *
	 * @return void
	 */
	public function test_a_card_the_marketplace_did_not_answer_for_gets_a_note(): void {
		unset( $this->importer->cards['A-2'] );

		$this->jobs()->import_cards( $this->account_id, [ 'A-1', 'A-2', 'A-3' ], 0 );

		$this->assertSame( [ 'A-1', 'A-3' ], $this->linked() );
		$this->assertNotNull( $this->note( 'A-2' ) );
		$this->assertSame( [], $this->scheduler->of( self::HOOK ), 'nothing to retry: the next click or notification asks again' );
	}

	/**
	 * A card that fails on its own is noted, the exception goes to `detail`, and the batch goes on.
	 *
	 * @return void
	 */
	public function test_a_card_that_fails_on_its_own_is_noted_and_the_batch_goes_on(): void {
		$this->refused['A-2'] = 'Неверный или повторяющийся артикул.';

		$this->jobs()->import_cards( $this->account_id, [ 'A-1', 'A-2', 'A-3' ], 0 );

		$this->assertSame( [ 'A-1', 'A-3' ], $this->linked() );
		$this->assertSame( 'Не удалось импортировать карточку.', $this->note( 'A-2' )['text'] );
		$this->assertSame( 'RuntimeException: Неверный или повторяющийся артикул.', $this->note( 'A-2' )['detail'] );
		$this->assertStringContainsString( 'A-2', implode( "\n", $this->log ) );
		$this->assertSame( [], $this->scheduler->of( self::HOOK ) );
	}

	/**
	 * The claim is renewed before every card and before every image, and by the read.
	 *
	 * @return void
	 */
	public function test_the_claim_is_renewed_before_every_card_and_every_image(): void {
		$this->images = [
			'A-1' => 2,
			'A-2' => 1,
		];

		$this->jobs()->import_cards( $this->account_id, [ 'A-1', 'A-2', 'A-3' ], 0 );

		// The read (1), then each card (3) and each image (3).
		$this->assertSame( 7, $this->renewals() );
		$this->assertSame( 3, $this->downloaded );
	}

	/**
	 * A claim another run took stops the run before any card, and the batch comes back later with the same attempt.
	 *
	 * @return void
	 */
	public function test_a_lost_claim_stops_the_run_and_retries_the_batch(): void {
		$this->importer->after_read = function (): void {
			$this->take_over();
		};

		$this->jobs()->import_cards( $this->account_id, [ 'A-1', 'A-2', 'A-3' ], 1 );

		$this->assertSame( [], $this->linked(), 'no card of the run that lost the claim' );
		$this->assertSame( [], $this->notes()->import_notes( $this->account_id ), 'and no note' );

		$retry = $this->scheduler->of( self::HOOK );

		$this->assertCount( 1, $retry );
		$this->assertSame( [ $this->account_id, [ 'A-1', 'A-2', 'A-3' ], 1 ], $retry[0]['args'] );
		$this->assertSame( Job_Gate::BUSY_DELAY, $retry[0]['delay'] );
	}

	/**
	 * The claim lost in the middle of the batch stops it at the next card.
	 *
	 * @return void
	 */
	public function test_a_claim_lost_between_two_cards_stops_the_run_there(): void {
		$renewals = 0;
		$arm      = null;
		$arm      = function () use ( &$renewals, &$arm ): void {
			// The first renewal is the read's, the second comes before A-1: the takeover is for the third, before A-2.
			if ( ++$renewals < 3 ) {
				$this->db->before_query = $arm;

				return;
			}

			$this->take_over();
		};

		$this->db->before_query_pattern = '/^UPDATE\s+\S*accounts\S*\s+SET\s+claim_until\s*=/i';
		$this->db->before_query         = $arm;

		$this->jobs()->import_cards( $this->account_id, [ 'A-1', 'A-2', 'A-3' ], 0 );

		$this->assertSame( [ 'A-1' ], $this->linked(), 'the card before the takeover is done, nothing after it' );
		$this->assertSame( Job_Gate::BUSY_DELAY, $this->scheduler->of( self::HOOK )[0]['delay'] );
	}

	/**
	 * The budget spent: the cards not finished go back with the attempt unchanged.
	 *
	 * @return void
	 */
	public function test_the_budget_requeues_the_unfinished_cards_and_keeps_the_attempt(): void {
		$this->card_seconds = 15.0;

		$this->jobs()->import_cards( $this->account_id, [ 'A-1', 'A-2', 'A-3' ], 1 );

		$this->assertSame( [ 'A-1', 'A-2' ], $this->linked(), 'two cards fit in twenty seconds' );

		$retry = $this->scheduler->of( self::HOOK );

		$this->assertCount( 1, $retry );
		$this->assertSame( [ $this->account_id, [ 'A-3' ], 1 ], $retry[0]['args'], 'the attempt is unchanged' );
		$this->assertSame( [], $this->notes()->import_notes( $this->account_id ), 'running late is not a failure' );
	}

	/**
	 * The budget is checked before every image: a card of new images is cut in the middle, the card in hand goes back with the
	 * cards after it, and the next run resumes it by its marker without a second product.
	 *
	 * @return void
	 */
	public function test_a_card_of_new_images_is_cut_by_the_budget_and_resumed_without_a_duplicate(): void {
		$this->images        = [ 'A-1' => 5 ];
		$this->image_seconds = 10.0;

		$this->jobs()->import_cards( $this->account_id, [ 'A-1', 'A-2' ], 0 );

		$this->assertSame( 2, $this->downloaded, 'two new images fit in twenty seconds; the third is refused before it starts' );
		$this->assertSame( [], $this->linked(), 'the card in hand is not linked yet' );

		$retry = $this->scheduler->of( self::HOOK );

		$this->assertCount( 1, $retry );
		$this->assertSame( [ $this->account_id, [ 'A-1', 'A-2' ], 0 ], $retry[0]['args'], 'the current card is included, the attempt is unchanged' );

		$this->scheduler->actions = [];
		$this->jobs()->import_cards( ...$retry[0]['args'] );

		$this->assertSame( 4, $this->downloaded, 'the stored images are found, two new ones are loaded' );
		$this->assertSame( 1, $this->products_made, 'the same product is resumed' );
		$this->assertSame( [], $this->linked() );

		$retry = $this->scheduler->of( self::HOOK );
		$this->scheduler->actions = [];
		$this->jobs()->import_cards( ...$retry[0]['args'] );

		$this->assertSame( 5, $this->downloaded, 'the last image' );
		$this->assertSame( 2, $this->products_made, 'one product for each of the two cards, none twice' );
		$this->assertSame( [ 'A-1', 'A-2' ], $this->linked(), 'the card is linked and the next one is imported' );
		$this->assertSame( [], $this->scheduler->of( self::HOOK ) );
	}

	/**
	 * A read that used the whole budget by itself still ends with progress: a card without images is finished.
	 *
	 * @return void
	 */
	public function test_a_read_that_used_the_whole_budget_still_finishes_one_card(): void {
		$this->importer->after_read = function (): void {
			$this->mono += 100.0;
		};

		$this->jobs()->import_cards( $this->account_id, [ 'A-1', 'A-2', 'A-3' ], 0 );

		$this->assertSame( [ 'A-1' ], $this->linked(), 'one card was finished' );
		$this->assertSame( [ $this->account_id, [ 'A-2', 'A-3' ], 0 ], $this->scheduler->of( self::HOOK )[0]['args'] );
	}

	/**
	 * A read that used the whole budget still stores one new image, then the card goes back.
	 *
	 * @return void
	 */
	public function test_a_read_that_used_the_whole_budget_still_stores_one_image(): void {
		$this->importer->after_read = function (): void {
			$this->mono += 100.0;
		};
		$this->images = [ 'A-1' => 3 ];

		$this->jobs()->import_cards( $this->account_id, [ 'A-1', 'A-2' ], 0 );

		$this->assertSame( 1, $this->downloaded );
		$this->assertSame( [ $this->account_id, [ 'A-1', 'A-2' ], 0 ], $this->scheduler->of( self::HOOK )[0]['args'] );
	}

	/**
	 * The r1 loop: a slow read and a card of several images. A stored image is not progress the next time (it is found, not
	 * loaded), so every run must load a new one: one more image per run, the attempt never grows, and the card ends.
	 *
	 * @return void
	 */
	public function test_a_requeue_after_a_slow_read_stores_one_more_image_every_run(): void {
		$this->importer->after_read = function (): void {
			$this->mono += 100.0;
		};
		$this->images = [ 'A-1' => 4 ];

		$args = [ $this->account_id, [ 'A-1' ], 0 ];

		for ( $run = 1; $run <= 4; $run++ ) {
			$this->scheduler->actions = [];
			$this->jobs()->import_cards( ...$args );

			$this->assertSame( $run, $this->downloaded, 'run ' . $run . ' loaded exactly one new image' );

			$retry = $this->scheduler->of( self::HOOK );

			if ( $run < 4 ) {
				$this->assertCount( 1, $retry );
				$args = $retry[0]['args'];
				$this->assertSame( 0, $args[2], 'the attempt did not grow' );
			} else {
				$this->assertSame( [], $retry, 'the fourth image finishes the card' );
			}
		}

		$this->assertSame( [ 'A-1' ], $this->linked() );
		$this->assertSame( 1, $this->products_made );
	}

	/**
	 * Run after run with the same slow read, every requeue finishes a card: the batch drains and the attempt never grows.
	 *
	 * @return void
	 */
	public function test_a_requeue_after_a_slow_read_drains_the_batch_run_after_run(): void {
		$this->importer->after_read = function (): void {
			$this->mono += 100.0;
		};

		$args = [ $this->account_id, [ 'A-1', 'A-2', 'A-3' ], 0 ];

		for ( $run = 1; $run <= 3; $run++ ) {
			$this->scheduler->actions = [];
			$this->jobs()->import_cards( ...$args );

			$this->assertCount( $run, $this->linked(), 'run ' . $run . ' finished one card' );

			$retry = $this->scheduler->of( self::HOOK );

			if ( $run < 3 ) {
				$this->assertCount( 1, $retry );
				$args = $retry[0]['args'];
				$this->assertSame( 0, $args[2], 'the attempt did not grow' );
			} else {
				$this->assertSame( [], $retry, 'the last run leaves nothing' );
			}
		}
	}

	/**
	 * An image that fails is no progress: the run is not cut by a failure, and the card finishes without it.
	 *
	 * @return void
	 */
	public function test_a_failing_image_is_no_progress_and_never_cuts_the_run(): void {
		$this->images        = [ 'A-1' => 3 ];
		$this->image_seconds = 30.0;
		$this->failing       = [
			'A-1:0' => true,
			'A-1:1' => true,
			'A-1:2' => true,
		];

		$this->jobs()->import_cards( $this->account_id, [ 'A-1', 'A-2' ], 0 );

		$this->assertSame( [ 'A-1' ], $this->linked(), 'the card finished without its images' );
		$this->assertSame( 3, $this->downloaded, 'all three were tried' );
		$this->assertSame( [ $this->account_id, [ 'A-2' ], 0 ], $this->scheduler->of( self::HOOK )[0]['args'] );
	}

	/**
	 * A disabled account: no read, and every card of the batch is told so.
	 *
	 * @return void
	 */
	public function test_a_disabled_account_makes_no_request_and_the_cards_say_why(): void {
		$this->accounts()->set_enabled( $this->account_id, false );

		$this->jobs()->import_cards( $this->account_id, [ 'A-1', 'A-2' ], 0 );

		$this->assertSame( [], $this->importer->reads );
		$this->assertSame( 'Аккаунт маркетплейса отключён или удалён.', $this->note( 'A-1' )['text'] );
		$this->assertSame( '', $this->note( 'A-1' )['detail'] );
		$this->assertNotNull( $this->note( 'A-2' ) );
	}

	/**
	 * A lapsed licence waits: no read, no note, the same attempt.
	 *
	 * @return void
	 */
	public function test_a_lapsed_licence_waits(): void {
		$this->licensed = false;

		$this->jobs()->import_cards( $this->account_id, [ 'A-1' ], 2 );

		$this->assertSame( [], $this->importer->reads );
		$this->assertSame( [], $this->notes()->import_notes( $this->account_id ) );

		$retry = $this->scheduler->of( self::HOOK );

		$this->assertSame( [ $this->account_id, [ 'A-1' ], 2, 1 ], $retry[0]['args'], 'the wait is counted apart from the attempts' );
		$this->assertSame( Job_Gate::LICENCE_DELAY, $retry[0]['delay'] );
	}

	/**
	 * A batch that has waited as often as the ceiling allows is not put off again: it gives up with a note and queues nothing.
	 *
	 * @return void
	 */
	public function test_a_lapsed_licence_past_the_ceiling_gives_the_batch_up(): void {
		$this->licensed = false;

		$this->jobs()->import_cards( $this->account_id, [ 'A-1' ], 0, Account_Job::MAX_PUT_OFFS );

		$this->assertSame( [], $this->importer->reads );
		$this->assertSame( [], $this->scheduler->of( self::HOOK ) );
		$this->assertNotNull( $this->note( 'A-1' ) );
	}

	/**
	 * A server error retries on the ladder with the attempt counted, then gives up with the fixed sentence and the reason in `detail`.
	 *
	 * @return void
	 */
	public function test_a_server_error_retries_then_gives_up_with_a_fixed_sentence(): void {
		$this->importer->failure = Fake_Remote_Exception::of( Remote_Error::RETRY, 'HTTP 503' );

		$this->jobs()->import_cards( $this->account_id, [ 'A-1', 'A-2' ], 0 );

		$retry = $this->scheduler->of( self::HOOK );

		$this->assertCount( 1, $retry );
		$this->assertSame( [ $this->account_id, [ 'A-1', 'A-2' ], 1 ], $retry[0]['args'] );
		$this->assertSame( Backoff::seconds_after( 1 ), $retry[0]['delay'] );
		$this->assertSame( [], $this->notes()->import_notes( $this->account_id ), 'no verdict while a retry is pending' );

		$this->jobs()->import_cards( $this->account_id, [ 'A-1', 'A-2' ], Account_Job::MAX_ATTEMPTS - 1 );

		$this->assertCount( 1, $this->scheduler->of( self::HOOK ), 'no third retry' );

		foreach ( [ 'A-1', 'A-2' ] as $article ) {
			$this->assertSame( self::GIVE_UP, $this->note( $article )['text'] );
			$this->assertStringContainsString( 'HTTP 503', $this->note( $article )['detail'] );
		}
	}

	/**
	 * An exception nobody classified never reaches the merchant's sentence: it goes to `detail` only.
	 *
	 * @return void
	 */
	public function test_an_unexpected_exception_is_in_the_detail_never_in_the_text(): void {
		$this->importer->failure = new \RuntimeException( 'boom: SQLSTATE[42S02] secret table' );

		$this->jobs()->import_cards( $this->account_id, [ 'A-1' ], 0 );

		$note = $this->note( 'A-1' );

		$this->assertSame( self::GIVE_UP, $note['text'] );
		$this->assertStringNotContainsString( 'boom', $note['text'] );
		$this->assertStringContainsString( 'boom: SQLSTATE[42S02] secret table', $note['detail'] );
		$this->assertNotNull( $this->accounts()->claim( $this->account_id, 120 ), 'the claim was released' );
	}

	/**
	 * A refused key pauses the account and the cards say the run was given up.
	 *
	 * @return void
	 */
	public function test_a_refused_key_pauses_the_account(): void {
		$this->importer->failure = Fake_Remote_Exception::of( Remote_Error::PAUSE_ACCOUNT, 'key refused' );

		$this->jobs()->import_cards( $this->account_id, [ 'A-1' ], 0 );

		$this->assertSame( [ $this->account_id ], $this->classifier->refused );
		$this->assertSame( self::GIVE_UP, $this->note( 'A-1' )['text'] );
		$this->assertSame( [], $this->scheduler->of( self::HOOK ) );
	}
}
