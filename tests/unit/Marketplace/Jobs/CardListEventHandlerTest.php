<?php
/**
 * A card notification also refreshes the card's row of the stored list (#1863).
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Jobs;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Data\Wpdb_Card_Store;
use Woodev\Framework\Marketplace\Jobs\Card_List_Event_Handler;
use Woodev\Framework\Marketplace\Jobs\Event_Handler;
use Woodev\Framework\Marketplace\Jobs\Link_Scheduler;
use Woodev\Framework\Marketplace\Ledger\Inbound_Event;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Export_Test_Case;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Paged_Link_Resolver;

/**
 * @covers \Woodev\Framework\Marketplace\Jobs\Card_List_Event_Handler
 */
final class CardListEventHandlerTest extends Export_Test_Case {
	private const ROWS = 'woodev_fake_seller_refresh_card_rows';

	/**
	 * Options of the test, by name.
	 *
	 * @var array<string,mixed>
	 */
	private $options = [];

	/**
	 * The events the wrapped handler was handed.
	 *
	 * @var Inbound_Event[]
	 */
	private $inner_seen = [];

	/**
	 * The marketplace; the handler must never read from it.
	 *
	 * @var Paged_Link_Resolver
	 */
	private $source;

	protected function setUp(): void {
		parent::setUp();

		$this->options    = [];
		$this->inner_seen = [];
		$this->source     = new Paged_Link_Resolver();

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
	}

	private function cards(): Wpdb_Card_Store {
		return new Wpdb_Card_Store( $this->db, Sqlite_Tables::table( 'cards' ), Sqlite_Tables::table( 'links' ), 'woodev_test_', $this->clock() );
	}

	private function handler(): Card_List_Event_Handler {
		$inner = new class( $this->inner_seen ) implements Event_Handler {
			/** @var Inbound_Event[] */
			private $seen;

			/** @param Inbound_Event[] $seen Where the handled events go. */
			public function __construct( array &$seen ) {
				$this->seen = &$seen;
			}

			/**
			 * @param Inbound_Event $event Event.
			 * @return string
			 */
			public function handle( Inbound_Event $event ): string {
				$this->seen[] = $event;

				return 'import queued';
			}
		};

		return new Card_List_Event_Handler( $this->source, $this->cards(), new Link_Scheduler( $this->names() ), $inner );
	}

	private function event( string $article, ?int $account_id = null ): Inbound_Event {
		return new Inbound_Event( $account_id ?? $this->account_id, 'card_changed', sha1( $article ), [ 'offer_id' => $article ], new \DateTimeImmutable( '2026-11-15T10:00:00Z' ), '203.0.113.10', true );
	}

	private function load_list(): void {
		$store = $this->cards();
		$run   = $store->begin_refresh( $this->account_id, self::NOW );

		$store->finish_refresh( $this->account_id, $run, self::NOW );
	}

	public function test_a_card_event_queues_its_row_and_hands_the_event_on(): void {
		$this->load_list();

		$result = $this->handler()->handle( $this->event( 'ART-1' ) );

		$queued = $this->scheduler->of( self::ROWS );

		$this->assertCount( 1, $queued );
		$this->assertSame( [ $this->account_id, [ 'ART-1' ], 0 ], $queued[0]['args'] );
		$this->assertSame( 'card row refresh queued; import queued', $result );
		$this->assertCount( 1, $this->inner_seen, 'the importer still gets the event' );
		$this->assertSame( [], $this->source->reads, 'no remote call at event time' );
	}

	public function test_the_same_card_twice_is_queued_once(): void {
		$this->load_list();

		$this->handler()->handle( $this->event( 'ART-1' ) );
		$this->handler()->handle( $this->event( 'ART-1' ) );

		$this->assertCount( 1, $this->scheduler->of( self::ROWS ) );
	}

	public function test_an_account_whose_list_was_never_loaded_queues_nothing(): void {
		$result = $this->handler()->handle( $this->event( 'ART-1' ) );

		$this->assertSame( [], $this->scheduler->of( self::ROWS ) );
		$this->assertSame( 'import queued', $result );
		$this->assertCount( 1, $this->inner_seen );
	}

	public function test_an_event_with_no_article_or_no_account_queues_nothing(): void {
		$this->load_list();

		$this->handler()->handle( $this->event( '' ) );
		$this->handler()->handle( new Inbound_Event( null, 'card_changed', 'x', [ 'offer_id' => 'ART-1' ], new \DateTimeImmutable(), '203.0.113.10', true ) );

		$this->assertSame( [], $this->scheduler->of( self::ROWS ) );
		$this->assertCount( 2, $this->inner_seen );
	}

	/**
	 * A notification that arrives while the read of the same card runs gets one follow-up behind it: the running read may have
	 * seen the card before this change (#1868 Must 2). More notifications meanwhile add no second follow-up.
	 *
	 * @return void
	 */
	public function test_a_notification_while_the_cards_read_runs_queues_one_follow_up(): void {
		$this->load_list();

		$this->handler()->handle( $this->event( 'ART-1' ) );
		$this->scheduler->start_running( self::ROWS );

		$this->handler()->handle( $this->event( 'ART-1' ) );
		$this->handler()->handle( $this->event( 'ART-1' ) );

		$this->assertSame( [ 'in-progress', 'pending' ], array_column( $this->scheduler->of( self::ROWS ), 'status' ) );
	}
}
