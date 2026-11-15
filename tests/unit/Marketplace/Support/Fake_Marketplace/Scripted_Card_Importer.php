<?php
/**
 * The importer part of a fake marketplace, scripted by the test.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Catalog\Card_Content;
use Woodev\Framework\Marketplace\Flows\Card_Importer;
use Woodev\Framework\Marketplace\Ledger\Inbound_Event;

/**
 * Answers `read_cards()` from a list the test fills, records every read, and can throw or run a hook after it read.
 */
final class Scripted_Card_Importer implements Card_Importer {
	/**
	 * The cards the marketplace holds, by article.
	 *
	 * @var array<string,Card_Content>
	 */
	public $cards = [];

	/**
	 * Reads asked for: `[ context, articles ]` each.
	 *
	 * @var array<int,array{0:string,1:string[]}>
	 */
	public $reads = [];

	/**
	 * What the read throws, when set.
	 *
	 * @var \Throwable|null
	 */
	public $failure;

	/**
	 * Runs after the read answered (a slow read advances the clock here).
	 *
	 * @var callable|null
	 */
	public $after_read;

	/**
	 * Names the event type that announces a card.
	 *
	 * @return string[]
	 */
	public function import_event_types(): array {
		return [ 'CARD_CHANGED' ];
	}

	/**
	 * Returns the article an event carries.
	 *
	 * @param Inbound_Event $event Event.
	 * @return string
	 */
	public function imported_article( Inbound_Event $event ): string {
		return (string) ( $event->payload()['offer_id'] ?? '' );
	}

	/**
	 * Names the request path of the import.
	 *
	 * @return string
	 */
	public function import_path(): string {
		return '/fake/cards/import';
	}

	/**
	 * Reads the cards of a batch.
	 *
	 * @param Account  $account   Account.
	 * @param string   $context   Job hook.
	 * @param string[] $offer_ids Articles.
	 * @param callable $renew     Claim renewal, called once as a real read does between its requests.
	 * @return array<string,Card_Content>
	 * @throws \Throwable The scripted failure.
	 */
	public function read_cards( Account $account, string $context, array $offer_ids, callable $renew ): array {
		$this->reads[] = [ $context, $offer_ids ];

		$renew();

		if ( null !== $this->failure ) {
			throw $this->failure;
		}

		if ( null !== $this->after_read ) {
			call_user_func( $this->after_read );
		}

		return array_intersect_key( $this->cards, array_flip( $offer_ids ) );
	}
}
