<?php
/**
 * A card notification also refreshes that card's row of the stored list.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Data\Wpdb_Card_Store;
use Woodev\Framework\Marketplace\Flows\Card_List_Source;
use Woodev\Framework\Marketplace\Ledger\Inbound_Event;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps the handler a card event type already has (the importer's {@see Card_Event_Handler}, or the no-op one): first it queues the
 * refresh of the card's row in the «Карточки» list ({@see Link_Scheduler::refresh_rows()}), then it hands the event on. It runs under
 * the event's claim, so it only queues and makes no request.
 *
 * The row is refreshed only for an account whose list was loaded once: before that there is no list to keep up to date. A card the
 * marketplace refused is refreshed too — its row shows why ({@see Card_List_Source::event_article()}).
 *
 * @since 2.1.13
 */
final class Card_List_Event_Handler implements Event_Handler {
	/**
	 * The adapter's list part.
	 *
	 * @var Card_List_Source
	 */
	private $source;

	/**
	 * The stored list.
	 *
	 * @var Wpdb_Card_Store
	 */
	private $cards;

	/**
	 * Scheduler.
	 *
	 * @var Link_Scheduler
	 */
	private $scheduler;

	/**
	 * The handler the type had.
	 *
	 * @var Event_Handler
	 */
	private $inner;

	/**
	 * Creates the handler.
	 *
	 * @since 2.1.13
	 *
	 * @param Card_List_Source $source    The adapter's list part.
	 * @param Wpdb_Card_Store  $cards     The stored list.
	 * @param Link_Scheduler   $scheduler Scheduler.
	 * @param Event_Handler    $inner     The handler the event type had before.
	 */
	public function __construct( Card_List_Source $source, Wpdb_Card_Store $cards, Link_Scheduler $scheduler, Event_Handler $inner ) {
		$this->source    = $source;
		$this->cards     = $cards;
		$this->scheduler = $scheduler;
		$this->inner     = $inner;
	}

	/**
	 * Queues the row's refresh, then lets the handler the type had do its work.
	 *
	 * @since 2.1.13
	 *
	 * @param Inbound_Event $event Claimed event.
	 * @return string The inner handler's result, with what was done for the list.
	 * @throws \RuntimeException When the row's refresh could not be queued; the drain retries.
	 */
	public function handle( Inbound_Event $event ): string {
		$article    = $this->source->event_article( $event );
		$account_id = (int) $event->account_id();
		$note       = '';

		if ( '' !== $article && $account_id > 0 && $this->cards->has_list( $account_id ) ) {
			if ( ! $this->scheduler->refresh_rows( $account_id, [ $article ] ) ) {
				throw new \RuntimeException( 'The refresh of the card row could not be queued.' );
			}

			$note = 'card row refresh queued';
		}

		$result = $this->inner->handle( $event );

		return '' === $note ? $result : $note . '; ' . $result;
	}
}
