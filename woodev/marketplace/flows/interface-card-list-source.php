<?php
/**
 * The marketplace's side of the stored card list: one page at a time, and single cards on a notification.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Ledger\Inbound_Event;

defined( 'ABSPATH' ) || exit;

/**
 * An optional part of the adapter's {@see Link_Resolver}: the «Карточки» screen keeps the cabinet's cards in its own table
 * ({@see \Woodev\Framework\Marketplace\Data\Wpdb_Card_Store}), and a cabinet can hold tens of thousands of them. A resolver that
 * implements this interface is read **one page per call**, and each page goes to the table before the next is asked for, so a full
 * refresh never holds the whole cabinet in memory and an interrupted one goes on from the page it reached. A resolver that does not
 * implement it is read with {@see Link_Resolver::snapshot()} in one piece, as before; its notifications then leave the list alone.
 *
 * A **list row** is the card of {@see Link_Resolver::snapshot()} (`offer_id`, `remote_ids`, `name`, `state`, `is_created`, `failed`,
 * `archived`, `details_ok`, `status_text`, `group`), plus what the screen shows when the marketplace says it: `image_url` (URL of the
 * main picture), `price` (the selling price as a number or numeric string) and `stock` (units the marketplace has, an integer). An absent
 * key is stored empty (`price` and `stock` as unknown, never as 0).
 *
 * The link to a card in the seller's cabinet is not part of this contract: the screen asks the adapter's
 * `Remote_Card_Address::remote_card_url()` for it (#1867).
 *
 * @since 2.1.13
 */
interface Card_List_Source {
	/**
	 * Reads the next page of the cabinet's list rows.
	 *
	 * `$state` is the cursor, as in every read of {@see Link_Resolver}: empty for the first page, kept by the job between calls and
	 * across interruptions. The implementation advances it as it reads; the job stores it after it has written the page.
	 *
	 * @since 2.1.13
	 *
	 * @param Account             $account Account.
	 * @param string              $context Job hook, for the client's context.
	 * @param callable            $renew   `function (): void`, extends the account claim or throws.
	 * @param array<string,mixed> $state   Cursor of the read; updated as it advances.
	 * @return array{cards:array<int,array<string,mixed>>,done:bool} The rows of this page, and whether it was the last.
	 * @throws Link_Resolve_Exception When the marketplace's answer cannot be trusted.
	 */
	public function list_page( Account $account, string $context, callable $renew, array &$state ): array;

	/**
	 * Reads the list rows of named cards: a card notification refreshes its own row and nothing else.
	 *
	 * @since 2.1.13
	 *
	 * @param Account  $account   Account.
	 * @param string   $context   Job hook.
	 * @param string[] $offer_ids Articles.
	 * @return array<int,array<string,mixed>> Rows of the cards the marketplace answered for; a card it does not know is absent.
	 */
	public function list_cards( Account $account, string $context, array $offer_ids ): array;

	/**
	 * Names the event types that announce a created or changed card, in the decoder's names.
	 *
	 * @since 2.1.13
	 *
	 * @return string[]
	 */
	public function card_event_types(): array;

	/**
	 * Returns the article a card event is about — a card the marketplace refused included: its row shows the reason.
	 *
	 * @since 2.1.13
	 *
	 * @param Inbound_Event $event Card event.
	 * @return string '' when the event names no card.
	 */
	public function event_article( Inbound_Event $event ): string;
}
