<?php
/**
 * The notification that a card was created or changed.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Flows\Card_Importer;
use Woodev\Framework\Marketplace\Ledger\Inbound_Event;
use Woodev\Framework\Marketplace\Settings\Effective_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a «card created or changed» event into queued work. It runs under the event's claim, so it only queues, and it makes no
 * request to the marketplace; the drain that calls it is licence-gated already. The body of the event is trusted for the article
 * only: the import job reads the card again.
 *
 * | The event's article | Account | Result stored on the event row |
 * |---|---|---|
 * | '' (no card, or a failed card) | — | `no card to act on` |
 * | linked | prices from the marketplace | the price refresh is queued: `price refresh queued` |
 * | linked | any other price direction | `card is linked; nothing to do` |
 * | not linked | auto-import off | `card is not linked; auto-import is off` |
 * | not linked | auto-import on | the import is queued: `import queued` |
 *
 * A scheduling failure throws, so the drain's retry ladder applies.
 *
 * @since 2.1.6
 */
final class Card_Event_Handler implements Event_Handler {
	/**
	 * The adapter's importer part.
	 *
	 * @var Card_Importer
	 */
	private $importer;

	/**
	 * Accounts.
	 *
	 * @var Wpdb_Account_Store
	 */
	private $accounts;

	/**
	 * Links.
	 *
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * Settings.
	 *
	 * @var Effective_Settings
	 */
	private $settings;

	/**
	 * Import scheduler.
	 *
	 * @var Import_Scheduler
	 */
	private $imports;

	/**
	 * Price scheduler, null when the adapter cannot read the marketplace's prices.
	 *
	 * @var Price_Scheduler|null
	 */
	private $prices;

	/**
	 * Creates the handler.
	 *
	 * @since 2.1.6
	 *
	 * @param Card_Importer        $importer Adapter's importer part.
	 * @param Wpdb_Account_Store   $accounts Accounts.
	 * @param Wpdb_Link_Store      $links    Links.
	 * @param Effective_Settings   $settings Settings.
	 * @param Import_Scheduler     $imports  Import scheduler.
	 * @param Price_Scheduler|null $prices   Price scheduler, or null when the adapter has no price reader.
	 */
	public function __construct( Card_Importer $importer, Wpdb_Account_Store $accounts, Wpdb_Link_Store $links, Effective_Settings $settings, Import_Scheduler $imports, ?Price_Scheduler $prices ) {
		$this->importer = $importer;
		$this->accounts = $accounts;
		$this->links    = $links;
		$this->settings = $settings;
		$this->imports  = $imports;
		$this->prices   = $prices;
	}

	/**
	 * Processes one event.
	 *
	 * @since 2.1.6
	 *
	 * @param Inbound_Event $event Claimed event.
	 * @return string
	 * @throws \Throwable When the work could not be queued; the drain retries.
	 */
	public function handle( Inbound_Event $event ): string {
		$article = $this->importer->imported_article( $event );
		$account = null === $event->account_id() ? null : $this->accounts->get( $event->account_id() );

		if ( '' === $article || null === $account ) {
			return 'no card to act on';
		}

		if ( null !== $this->links->find_by_offer_id( $account->id(), $article ) ) {
			return $this->linked( $account->id(), $article, Wpdb_Account_Store::PRICE_FROM_MARKETPLACE === (string) $account->setting( 'price_direction', Wpdb_Account_Store::PRICE_TO_MARKETPLACE ) );
		}

		if ( ! $this->settings->import_enabled( $account ) ) {
			return 'card is not linked; auto-import is off';
		}

		$this->imports->import( $account->id(), [ $article ] );

		return 'import queued';
	}

	/**
	 * A card that is on the site: its price is refreshed when the account reads prices from the marketplace.
	 *
	 * @param int    $account_id Account id.
	 * @param string $article    Article.
	 * @param bool   $reads      Whether the account reads prices from the marketplace.
	 * @return string
	 */
	private function linked( int $account_id, string $article, bool $reads ): string {
		if ( ! $reads || null === $this->prices ) {
			return 'card is linked; nothing to do';
		}

		$this->prices->queue_refresh( $account_id, [ $article ] );

		return 'price refresh queued';
	}
}
