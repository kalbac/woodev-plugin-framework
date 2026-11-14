<?php
/**
 * The marketplace's side of importing cards as shop products.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Catalog\Card_Content;
use Woodev\Framework\Marketplace\Ledger\Inbound_Event;

defined( 'ABSPATH' ) || exit;

/**
 * An optional part of a marketplace adapter, next to {@see Link_Resolver}: the adapter implements it when the marketplace can announce
 * a created or changed card and read whole cards. The framework wires the importer only when the adapter implements this interface
 * and reports {@see \Woodev\Framework\Marketplace\Capabilities\Capability::IMPORT_PRODUCTS}, so an adapter that does not implement it
 * keeps booting unchanged.
 *
 * The importer never names a request, a path or a field: the adapter says which events announce a card, which article an event is
 * about and how its answer becomes a {@see Card_Content}.
 *
 * @since 2.1.6
 */
interface Card_Importer {
	/**
	 * Names the event types that announce a created or changed card, in the decoder's names.
	 *
	 * @since 2.1.6
	 *
	 * @return string[]
	 */
	public function import_event_types(): array;

	/**
	 * Returns the article an event is about.
	 *
	 * @since 2.1.6
	 *
	 * @param Inbound_Event $event Event of one of {@see self::import_event_types()}.
	 * @return string The article, or '' when the event names no card or reports a failed card.
	 */
	public function imported_article( Inbound_Event $event ): string;

	/**
	 * Names the request path the error ring stores next to a failed import run.
	 *
	 * @since 2.1.6
	 *
	 * @return string
	 */
	public function import_path(): string;

	/**
	 * Reads whole cards by article.
	 *
	 * `$renew` extends the account claim between requests and throws when another run took it. A card the marketplace did not
	 * answer for is absent from the result.
	 *
	 * @since 2.1.6
	 *
	 * @param Account  $account   Account.
	 * @param string   $context   Job hook, for the client's context.
	 * @param string[] $offer_ids Articles to read.
	 * @param callable $renew     `function (): void`, extends the account claim or throws.
	 * @return array<string,Card_Content> Cards by article.
	 * @throws \Throwable What the adapter's client throws (the adapter's error classifier sorts it).
	 */
	public function read_cards( Account $account, string $context, array $offer_ids, callable $renew ): array;
}
