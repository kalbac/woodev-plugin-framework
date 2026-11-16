<?php
/**
 * A card of a half-made group waits for the card that resumes the group's product, instead of being skipped (#1796).
 *
 * The import job, the importer and the group assembler run for real over the links table and a shop in memory; only the product writer
 * is played, by its two halves, and the queue is Action Scheduler's fake, run by hand in the order Action Scheduler would.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Jobs;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Catalog\Card_Content;
use Woodev\Framework\Marketplace\Data\Bulk_Run_Store;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Link_Report_Store;
use Woodev\Framework\Marketplace\Flows\Card_Import;
use Woodev\Framework\Marketplace\Flows\Card_Linker;
use Woodev\Framework\Marketplace\Flows\Group_Assembler;
use Woodev\Framework\Marketplace\Flows\Product_Writer;
use Woodev\Framework\Marketplace\Jobs\Bulk_Import_Feeder;
use Woodev\Framework\Marketplace\Jobs\Budget_Spent;
use Woodev\Framework\Marketplace\Jobs\Import_Jobs;
use Woodev\Framework\Marketplace\Jobs\Import_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Settings\Effective_Settings;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Export_Test_Case;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Scripted_Card_Importer;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Shop;

/**
 * @covers \Woodev\Framework\Marketplace\Jobs\Import_Jobs
 * @covers \Woodev\Framework\Marketplace\Flows\Card_Import
 * @covers \Woodev\Framework\Marketplace\Jobs\Import_Scheduler
 */
final class ImportGroupWaitTest extends Export_Test_Case {
	private const HOOK   = 'woodev_fake_seller_import_cards';
	private const PREFIX = 'fake_seller_';
	private const GROUP  = 'model-7';

	/**
	 * @var Fake_Shop
	 */
	private $shop;

	/**
	 * @var Scripted_Card_Importer
	 */
	private $marketplace;

	/**
	 * Options of the test, by name.
	 *
	 * @var array<string,mixed>
	 */
	private $options = [];

	/**
	 * Articles whose `complete` throws {@see Budget_Spent} once — the image step ran out of time.
	 *
	 * @var array<string,bool>
	 */
	private $budget_spent = [];

	/**
	 * Articles whose `complete` throws a plain error once — the run died after the first save.
	 *
	 * @var array<string,bool>
	 */
	private $dies = [];

	protected function setUp(): void {
		parent::setUp();

		$this->options      = [];
		$this->budget_spent = [];
		$this->dies         = [];
		$this->shop         = new Fake_Shop();
		$this->shop->install();
		$this->marketplace = new Scripted_Card_Importer();

		// The queue as the import scheduler reads it: the actions of a hook that are in one of the asked states, as objects with their arguments.
		Functions\when( 'as_get_scheduled_actions' )->alias(
			function ( array $query = [] ): array {
				if ( isset( $query['args'] ) ) {
					return array_keys( $this->scheduler->matching( (string) $query['hook'], (array) $query['args'], (string) ( $query['status'] ?? '' ) ) );
				}

				$asked   = (array) ( $query['status'] ?? [] );
				$pending = array_filter(
					$this->scheduler->of( (string) $query['hook'] ),
					static function ( array $action ) use ( $asked ): bool {
						return in_array( $action['status'], $asked, true );
					}
				);

				return array_map(
					static function ( array $action ) {
						return new class( $action['args'] ) {
							/** @var array<int,mixed> */
							private $args;

							/** @param array<int,mixed> $args Arguments. */
							public function __construct( array $args ) {
								$this->args = $args;
							}

							/** @return array<int,mixed> */
							public function get_args(): array {
								return $this->args;
							}
						};
					},
					array_values( $pending )
				);
			}
		);
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
	}

	/**
	 * @param string $article Article.
	 * @param string $group   Group key, '' for a card that stands alone.
	 * @return Card_Content
	 */
	private function card( string $article, string $group = '' ): Card_Content {
		$content = new Card_Content(
			[
				'offer_id'   => $article,
				'remote_ids' => [ 'card_ref' => crc32( $article ) % 100000 ],
				'state'      => Link_Record::STATE_CREATED,
				'is_created' => true,
				'archived'   => false,
			],
			[
				'name'          => 'Футболка ' . $article,
				'primary_image' => 'https://img.example.test/' . $article . '.jpg',
				'images'        => [],
				'group'         => [
					'key'     => $group,
					'size'    => '' === $group ? 0 : 2,
					'aspects' => '' === $group ? [] : [ 'Цвет' => $article ],
				],
			]
		);

		$this->marketplace->cards[ $article ] = $content;

		return $content;
	}

	/**
	 * The job over the played writer.
	 *
	 * @return Import_Jobs
	 */
	private function jobs(): Import_Jobs {
		return new Import_Jobs(
			$this->job_gate(),
			$this->marketplace,
			$this->importer(),
			$this->notes(),
			new Import_Scheduler( $this->names() ),
			$this->classifier,
			$this->ring(),
			[ $this, 'log_line' ]
		);
	}

	/**
	 * @return Card_Import
	 */
	private function importer(): Card_Import {
		return new Card_Import(
			$this->links(),
			new Card_Linker( $this->links() ),
			$this->notes(),
			new Effective_Settings(
				Sqlite_Tables::MARKETPLACE_ID,
				function ( string $name, $fallback = false ) {
					return $this->options[ $name ] ?? $fallback;
				}
			),
			new Group_Assembler( $this->links() ),
			function ( Account $account, Card_Content $content, bool $create_categories, ?callable $prepare = null ): \WC_Product {
				$product = $this->shop->product( 'simple' );

				$product->set_name( $content->name() );
				$product->set_status( 'draft' );
				$product->set_sku( (string) $content->card()['offer_id'] );
				$product->update_meta_data( Product_Writer::marker_key( Sqlite_Tables::MARKETPLACE_ID ), '1' );

				if ( null !== $prepare ) {
					$prepare( $product );
				}

				$product->save();

				return $product;
			},
			function ( Account $account, Card_Content $content, \WC_Product $product, bool $publish ): array {
				$article = (string) $content->card()['offer_id'];

				if ( isset( $this->dies[ $article ] ) ) {
					unset( $this->dies[ $article ] );

					throw new \RuntimeException( 'The run died.' );
				}

				if ( isset( $this->budget_spent[ $article ] ) ) {
					unset( $this->budget_spent[ $article ] );

					throw new Budget_Spent( 'The image step ran out of time.' );
				}

				$product->set_regular_price( '100' );
				$product->set_status( $publish ? 'publish' : ( $product->is_type( 'variation' ) ? 'private' : 'draft' ) );
				$product->delete_meta_data( Product_Writer::marker_key( Sqlite_Tables::MARKETPLACE_ID ) );
				$product->save();

				return [];
			}
		);
	}

	/**
	 * @return Link_Report_Store
	 */
	private function notes(): Link_Report_Store {
		return new Link_Report_Store( self::PREFIX );
	}

	/**
	 * Leaves the product of a card half-made: the writer's first save stored it with its marker, the rest never ran.
	 *
	 * @param string $article Article.
	 * @return void
	 */
	private function leave_half_made( string $article ): void {
		$this->dies[ $article ] = true;
		$held                   = $this->job_gate()->claim( $this->account_id );
		$this->assertNotNull( $held );

		try {
			$this->importer()->import( $this->account_now(), $this->marketplace->cards[ $article ], $held, static function (): void {} );
			$this->fail( 'The run should have died.' );
		} catch ( \RuntimeException $died ) {
			$this->assertSame( 'The run died.', $died->getMessage() );
		} finally {
			$this->job_gate()->release( $this->account_id, $held );
		}
	}

	/**
	 * Runs the oldest pending import action, as the queue runner would: it is running while the job works.
	 *
	 * @return array{0:string[],1:int}|null The articles and the attempt of the action that ran; null when none is pending.
	 */
	private function run_next(): ?array {
		foreach ( $this->scheduler->actions as $index => $action ) {
			if ( self::HOOK !== $action['hook'] || 'pending' !== $action['status'] ) {
				continue;
			}

			$this->scheduler->actions[ $index ]['status'] = 'in-progress';
			$this->jobs()->import_cards( ...$action['args'] );
			$this->scheduler->actions[ $index ]['status'] = 'complete';

			return [ $action['args'][1], $action['args'][2] ];
		}

		return null;
	}

	/**
	 * Runs the queue dry.
	 *
	 * @return array<int,array{0:string[],1:int}>
	 */
	private function run_all(): array {
		$ran = [];

		for ( $guard = 0; $guard < 20; $guard++ ) {
			$next = $this->run_next();

			if ( null === $next ) {
				return $ran;
			}

			$ran[] = $next;
		}

		$this->fail( 'The queue did not run dry.' );
	}

	/**
	 * @param string $article Article.
	 * @return string|null The merchant's note.
	 */
	private function note( string $article ): ?string {
		foreach ( $this->notes()->import_notes( $this->account_id ) as $note ) {
			if ( $article === $note['offer_id'] ) {
				return $note['text'];
			}
		}

		return null;
	}

	/**
	 * The feeder's order for a half-made group: the resuming card first, then ten fillers, so the sibling lands in the next chunk.
	 *
	 * @return string[]
	 */
	private function queue_the_group(): array {
		$this->card( 'R-1', self::GROUP );
		$this->card( 'S-1', self::GROUP );
		$fillers = [];

		for ( $number = 1; $number <= 9; $number++ ) {
			$this->card( 'F-' . $number );
			$fillers[] = 'F-' . $number;
		}

		$this->leave_half_made( 'R-1' );

		$articles = array_merge( [ 'R-1' ], $fillers, [ 'S-1' ] );
		( new Import_Scheduler( $this->names() ) )->import( $this->account_id, $articles );

		return $articles;
	}

	public function test_a_forced_budget_spent_in_the_resuming_card_does_not_skip_the_sibling_and_both_end_imported(): void {
		$articles = $this->queue_the_group();
		$this->budget_spent['R-1'] = true; // The image step of the resuming card runs out of time once.

		$this->assertCount( 2, $this->scheduler->of( self::HOOK ), 'The resuming card and its nine fillers go in one chunk, the sibling in the next.' );

		$ran = $this->run_all();

		$this->assertSame( [ 'S-1' ], $ran[1][0], 'The sibling runs before the retry of the resuming card — the queue order alone does not hold.' );
		$this->assertSame( [ 'S-1' ], $ran[3][0], 'The sibling came back after the card it waited for.' );
		$this->assertSame( 1, $ran[3][1], 'A wait is an attempt.' );

		foreach ( $articles as $article ) {
			$this->assertNotNull( $this->links()->find_by_offer_id( $this->account_id, $article ), $article . ' ends imported.' );
		}

		$this->assertNull( $this->note( 'S-1' ), 'The sibling is not left with the «не завершён» note.' );
		$this->assertNull( $this->note( 'R-1' ) );
	}

	public function test_the_sibling_waits_a_gate_delay_not_a_second(): void {
		$this->queue_the_group();
		$this->budget_spent['R-1'] = true;

		$this->run_next(); // The resuming card is cut: its chunk is queued again.
		$this->run_next(); // The sibling meets the half-made product.

		$waiting = array_values(
			array_filter(
				$this->scheduler->of( self::HOOK ),
				static function ( array $action ): bool {
					return [ 'S-1' ] === $action['args'][1] && 'pending' === $action['status'];
				}
			)
		);

		$this->assertCount( 1, $waiting );
		$this->assertEqualsWithDelta( Job_Gate::BUSY_DELAY, $waiting[0]['delay'], 1 );
	}

	public function test_a_sibling_is_still_skipped_with_a_note_when_nothing_is_queued_to_resume_the_group(): void {
		$this->card( 'R-1', self::GROUP );
		$this->card( 'S-1', self::GROUP );
		$this->leave_half_made( 'R-1' );

		( new Import_Scheduler( $this->names() ) )->import( $this->account_id, [ 'S-1' ] );
		$this->run_all();

		$this->assertNull( $this->links()->find_by_offer_id( $this->account_id, 'S-1' ) );
		$this->assertStringContainsString( 'не завершён', (string) $this->note( 'S-1' ) );
		$this->assertCount( 1, $this->scheduler->of( self::HOOK ), 'Nothing waits for a card nobody queued.' );
	}

	public function test_a_sibling_gives_up_when_its_attempts_are_spent(): void {
		$this->card( 'R-1', self::GROUP );
		$this->card( 'S-1', self::GROUP );
		$this->leave_half_made( 'R-1' );

		( new Import_Scheduler( $this->names() ) )->import( $this->account_id, [ 'R-1' ] ); // Queued, so the group has a resuming card…
		$this->scheduler->actions[] = [
			'hook'   => self::HOOK,
			'args'   => [ $this->account_id, [ 'S-1' ], 2 ], // …but the sibling has already waited twice.
			'group'  => '',
			'kind'   => 'single',
			'status' => 'pending',
			'delay'  => 0,
		];
		$this->scheduler->actions[0]['status'] = 'in-progress'; // The resuming card is running.

		$this->scheduler->actions[1]['status'] = 'in-progress';
		$this->jobs()->import_cards( $this->account_id, [ 'S-1' ], 2 );

		$this->assertStringContainsString( 'не завершён', (string) $this->note( 'S-1' ), 'The third run skips it with the note.' );
		$this->assertCount( 2, $this->scheduler->of( self::HOOK ), 'No fourth run is queued.' );
	}

	public function test_the_card_that_resumes_a_half_made_group_is_fed_first_although_it_sorts_after_its_sibling_and_both_end_imported(): void {
		$this->card( 'R-1', self::GROUP );
		$this->card( 'S-1', self::GROUP );
		$this->leave_half_made( 'R-1' );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		// The snapshot lists the sibling first; the lookup says R-1's product is the half-made one.
		$store  = new Bulk_Run_Store( $this->db, Sqlite_Tables::table( 'bulk_runs' ), $this->clock() );
		$feeder = new Bulk_Import_Feeder(
			$store,
			new Import_Scheduler( $this->names() ),
			static function (): array {
				return [ [ 'S-1', self::GROUP, false ], [ 'R-1', self::GROUP, false ] ];
			},
			static function ( array $articles ): array {
				return array_values( array_intersect( $articles, [ 'R-1' ] ) );
			}
		);
		$this->assertTrue( $store->start( $this->account_id, 'run-1', 'import_selected', [], [ 'search' => '', 'filters' => [] ], 3, 2 ) );
		$run = $store->find( $this->account_id );
		$this->assertNotNull( $run );

		$this->assertNull( $feeder->refusal( $this->account_now(), $run ) );
		$frozen = $feeder->part( $this->account_now(), $run, [], static function (): bool {
			return true;
		} );
		$feeder->part( $this->account_now(), $run, $frozen['cursor'], static function (): bool {
			return true;
		} );

		$this->assertSame( [ [ $this->account_id, [ 'R-1', 'S-1' ], 0 ] ], array_column( $this->scheduler->of( self::HOOK ), 'args' ), 'One chunk, the resuming article first.' );

		$this->run_all();

		$this->assertNotNull( $this->links()->find_by_offer_id( $this->account_id, 'R-1' ) );
		$this->assertNotNull( $this->links()->find_by_offer_id( $this->account_id, 'S-1' ) );
		$this->assertNull( $this->note( 'S-1' ) );
	}
}
