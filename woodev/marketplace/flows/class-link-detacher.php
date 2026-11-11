<?php
/**
 * «Снять связь»: ends a link's identity with its marketplace card.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Ledger\Task_Ledger;
use Woodev\Framework\Marketplace\Stock\Sync_State_Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Detaching a product from its card is more than clearing the row. Everything the plugin remembers **about the old card** is remote
 * identity state, not a setting, and must not leak into whatever card the product is tied to next:
 *
 * - the link row goes back to `unlinked` with no marketplace ids and no article ({@see Wpdb_Link_Store::detach()}); the switches, the
 *   warehouse, the price override and the mapping are the merchant's settings and stay;
 * - **the send history ends** ({@see Task_Ledger::forget_sent()}): an earlier export request no longer freezes the article, so a
 *   new article chosen after the detach is the one the next export sends, not the old card's;
 * - **the `sync_state` rows of the link are retired** ({@see Sync_State_Queue::forget_link()}): the accepted price and stock, the
 *   readback and the errors described the old card, and an unchanged desired price must be due again for the new one.
 *
 * The retirement is fenced by the account claim, the one the per-account flusher holds while it sends. The detacher takes the claim
 * for the call; when a sender holds it, nothing is changed and the caller is told to try again, so an in-flight sender can never
 * acknowledge its old card's value into the replacement's rows.
 *
 * @since 2.1.0
 */
final class Link_Detacher {
	public const CLAIM_TTL = 60;

	/**
	 * Links.
	 *
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * Intents and readbacks.
	 *
	 * @var Sync_State_Queue
	 */
	private $queue;

	/**
	 * Task ledger.
	 *
	 * @var Task_Ledger
	 */
	private $ledger;

	/**
	 * Accounts, for the claim.
	 *
	 * @var Wpdb_Account_Store
	 */
	private $accounts;

	/**
	 * Creates the detacher.
	 *
	 * @since 2.1.0
	 *
	 * @param Wpdb_Link_Store    $links    Links.
	 * @param Sync_State_Queue   $queue    Intents and readbacks.
	 * @param Task_Ledger        $ledger   Task ledger.
	 * @param Wpdb_Account_Store $accounts Accounts.
	 */
	public function __construct( Wpdb_Link_Store $links, Sync_State_Queue $queue, Task_Ledger $ledger, Wpdb_Account_Store $accounts ) {
		$this->links    = $links;
		$this->queue    = $queue;
		$this->ledger   = $ledger;
		$this->accounts = $accounts;
	}

	/**
	 * Detaches offer rows of one account.
	 *
	 * @since 2.1.0
	 *
	 * @param int           $account_id Account id.
	 * @param Link_Record[] $rows       Rows to detach (offer rows with a card).
	 * @return array{detached:int,kept:int,busy:bool} `busy` is true when the account's sender holds the claim: nothing was changed.
	 * @throws \RuntimeException When the database refuses a write.
	 */
	public function detach( int $account_id, array $rows ): array {
		$token = $this->accounts->claim( $account_id, self::CLAIM_TTL );

		if ( null === $token ) {
			return [
				'detached' => 0,
				'kept'     => 0,
				'busy'     => true,
			];
		}

		$detached = 0;
		$kept     = 0;

		try {
			foreach ( $rows as $row ) {
				$link_id = (int) $row->link_id();

				if ( ! $this->links->detach( $account_id, $link_id ) ) {
					++$kept; // Being submitted right now: the request carries its article.
					continue;
				}

				$this->ledger->forget_sent( $link_id );
				$this->queue->forget_link( $account_id, $link_id, $token );
				++$detached;
			}
		} finally {
			$this->accounts->release_claim( $account_id, $token );
		}

		return [
			'detached' => $detached,
			'kept'     => $kept,
			'busy'     => false,
		];
	}
}
