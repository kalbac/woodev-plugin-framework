<?php
/**
 * The «card created or changed» notification: the five answers of the event path.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Jobs;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Persistence_Exception;
use Woodev\Framework\Marketplace\Jobs\Card_Event_Handler;
use Woodev\Framework\Marketplace\Jobs\Event_Handler_Registry;
use Woodev\Framework\Marketplace\Jobs\Import_Scheduler;
use Woodev\Framework\Marketplace\Jobs\Price_Scheduler;
use Woodev\Framework\Marketplace\Ledger\Inbound_Event;
use Woodev\Framework\Marketplace\Settings\Effective_Settings;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Export_Test_Case;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Scripted_Card_Importer;

/**
 * @covers \Woodev\Framework\Marketplace\Jobs\Card_Event_Handler
 */
final class CardEventHandlerTest extends Export_Test_Case {
	private const IMPORT = 'woodev_fake_seller_import_cards';
	private const PRICES = 'woodev_fake_seller_refresh_prices';

	/**
	 * Options of the test, by name.
	 *
	 * @var array<string,mixed>
	 */
	private $options = [];

	/**
	 * The marketplace; the handler must never read from it.
	 *
	 * @var Scripted_Card_Importer
	 */
	private $importer;

	/**
	 * Stubs the options.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->options  = [];
		$this->importer = new Scripted_Card_Importer();

		Functions\when( 'get_option' )->alias(
			function ( string $name, $fallback = false ) {
				return $this->options[ $name ] ?? $fallback;
			}
		);
	}

	/**
	 * Builds the handler.
	 *
	 * @param bool $with_price_reader Whether the adapter can read the marketplace's prices.
	 * @return Card_Event_Handler
	 */
	private function handler( bool $with_price_reader = true ): Card_Event_Handler {
		return new Card_Event_Handler(
			$this->importer,
			$this->accounts(),
			$this->links(),
			new Effective_Settings( Sqlite_Tables::MARKETPLACE_ID, [ $this, 'read_option' ] ),
			new Import_Scheduler( $this->names() ),
			$with_price_reader ? new Price_Scheduler( $this->names() ) : null
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
	 * Builds an event about an article.
	 *
	 * @param string   $article    Article, '' for a body that names none.
	 * @param int|null $account_id Account the event was received for.
	 * @return Inbound_Event
	 */
	private function event( string $article, ?int $account_id = null ): Inbound_Event {
		return new Inbound_Event( $account_id ?? $this->account_id, 'CARD_CHANGED', sha1( $article ), [ 'offer_id' => $article ], new \DateTimeImmutable( '2026-11-15T10:00:00Z' ), '203.0.113.10', true );
	}

	/**
	 * Links an article to a shop product.
	 *
	 * @param string $article Article.
	 * @return void
	 */
	private function link( string $article ): void {
		$this->links()->insert_if_absent( Link_Record::offer( $this->account_id, 501, 0, $article, [ 'remote_ids' => [ 'card_ref' => 9 ] ] ) );
	}

	/**
	 * Nothing was queued, and the marketplace was not asked.
	 *
	 * @return void
	 */
	private function assertNothingQueued(): void {
		$this->assertSame( [], $this->scheduler->of( self::IMPORT ) );
		$this->assertSame( [], $this->scheduler->of( self::PRICES ) );
		$this->assertSame( [], $this->importer->reads, 'no remote call at event time' );
	}

	/**
	 * Row 1: a body that names no card, or a failed one, ends the event.
	 *
	 * @return void
	 */
	public function test_an_event_with_no_card_acts_on_nothing(): void {
		$this->assertSame( 'no card to act on', $this->handler()->handle( $this->event( '' ) ) );
		$this->assertNothingQueued();
	}

	/**
	 * An event of an account that is gone acts on nothing either.
	 *
	 * @return void
	 */
	public function test_an_event_of_an_unknown_account_acts_on_nothing(): void {
		$this->assertSame( 'no card to act on', $this->handler()->handle( $this->event( 'A-1', 9999 ) ) );
		$this->assertNothingQueued();
	}

	/**
	 * Row 2: a linked card of an account that reads prices from the marketplace has its price refreshed.
	 *
	 * @return void
	 */
	public function test_a_linked_card_refreshes_its_price_when_prices_come_from_the_marketplace(): void {
		$this->link( 'A-1' );
		$this->set_account( [ 'price_direction' => 'from_marketplace' ] );

		$this->assertSame( 'price refresh queued', $this->handler()->handle( $this->event( 'A-1' ) ) );
		$this->assertSame( [ $this->account_id, [ 'A-1' ] ], $this->scheduler->of( self::PRICES )[0]['args'] );
		$this->assertSame( [], $this->scheduler->of( self::IMPORT ) );
	}

	/**
	 * Row 3: a linked card of any other price direction needs nothing.
	 *
	 * @return void
	 */
	public function test_a_linked_card_with_another_price_direction_needs_nothing(): void {
		$this->link( 'A-1' );
		$this->set_account( [ 'price_direction' => 'to_marketplace' ] );

		$this->assertSame( 'card is linked; nothing to do', $this->handler()->handle( $this->event( 'A-1' ) ) );
		$this->assertNothingQueued();
	}

	/**
	 * A linked card whose adapter cannot read prices needs nothing, whatever the account says.
	 *
	 * @return void
	 */
	public function test_a_linked_card_with_no_price_reader_needs_nothing(): void {
		$this->link( 'A-1' );
		$this->set_account( [ 'price_direction' => 'from_marketplace' ] );

		$this->assertSame( 'card is linked; nothing to do', $this->handler( false )->handle( $this->event( 'A-1' ) ) );
		$this->assertNothingQueued();
	}

	/**
	 * Row 4: a card that is not on the site waits while auto-import is off.
	 *
	 * @return void
	 */
	public function test_a_new_card_is_ignored_while_auto_import_is_off(): void {
		$this->assertSame( 'card is not linked; auto-import is off', $this->handler()->handle( $this->event( 'A-1' ) ) );
		$this->assertNothingQueued();
	}

	/**
	 * Row 5: with auto-import on, the card's import is queued, one card to the action, and nothing is read.
	 *
	 * @return void
	 */
	public function test_a_new_card_is_queued_for_import_when_auto_import_is_on(): void {
		$this->accounts()->update_fields( $this->account_id, [ 'import_enabled' => 1 ] );

		$this->assertSame( 'import queued', $this->handler()->handle( $this->event( 'A-1' ) ) );
		$this->assertSame( [ $this->account_id, [ 'A-1' ], 0 ], $this->scheduler->of( self::IMPORT )[0]['args'] );
		$this->assertSame( [], $this->importer->reads, 'no remote call at event time' );
	}

	/**
	 * The same notification twice queues one action.
	 *
	 * @return void
	 */
	public function test_the_same_notification_twice_queues_one_import(): void {
		$this->accounts()->update_fields( $this->account_id, [ 'import_enabled' => 1 ] );

		$this->handler()->handle( $this->event( 'A-1' ) );
		$this->handler()->handle( $this->event( 'A-1' ) );

		$this->assertCount( 1, $this->scheduler->of( self::IMPORT ) );
	}

	/**
	 * A scheduling failure is thrown, so the drain's retry ladder applies.
	 *
	 * @return void
	 */
	public function test_a_scheduling_failure_is_thrown_for_the_drain_to_retry(): void {
		$this->accounts()->update_fields( $this->account_id, [ 'import_enabled' => 1 ] );
		$this->scheduler->zero_for[ self::IMPORT ] = true;

		$this->expectException( Persistence_Exception::class );

		$this->handler()->handle( $this->event( 'A-1' ) );
	}

	/**
	 * The registry hands the handler out for the adapter's event types.
	 *
	 * @return void
	 */
	public function test_the_registry_serves_the_handler_for_the_adapters_types(): void {
		$registry = new Event_Handler_Registry();
		$handler  = $this->handler();

		foreach ( $this->importer->import_event_types() as $type ) {
			$registry->register( $type, $handler );
		}

		$this->assertSame( $handler, $registry->for_type( 'CARD_CHANGED' ) );
	}
}
