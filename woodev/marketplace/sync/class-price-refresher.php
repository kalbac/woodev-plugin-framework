<?php
/**
 * Prices from the marketplace into WooCommerce.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Sync;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Claim_Fence;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Wpdb_Account_Store;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Jobs\Claim_Lost;
use Woodev\Framework\Marketplace\Price\Price_Pair;
use Woodev\Framework\Marketplace\Price\Price_Sync_Queue;

defined( 'ABSPATH' ) || exit;

/**
 * The `from_marketplace` direction: read the prices the marketplace holds for some articles ({@see Price_Reader}), undo the
 * rule on them and write the result to the linked product, both by {@see Shop_Price_Writer} (the rule's inverse, the sale
 * handling and the {@see Price_Refresh_Guard} that keeps the save from echoing back).
 *
 * Only `created` cards with price sync on, of an account whose direction is `from_marketplace`, are touched. A link whose
 * selling price is an absolute override, a marketplace currency that differs from the cabinet's, a missing price, an unreadable
 * rule: skipped, with the reason in the result. A product whose prices already equal the inverted ones is not saved.
 *
 * Who calls it: the handler of the marketplace's «card changed» notification and the daily reconcile, through the
 * `refresh_prices` job.
 *
 * {@see self::pull_once()} is the merchant's explicit «Загрузить цены» (#1797): the same read and the same writer, but for an
 * account in any price direction, for a link with price sync off too, and it settles the price the shop would have sent so that the
 * pull is not undone by a send queued before it.
 *
 * @since 2.1.0
 */
final class Price_Refresher {
	/**
	 * Times the pull puts a price the merchant saved during the pull back before it gives the article up with a line to check it.
	 */
	private const RESTORE_ROUNDS = 2;

	/**
	 * Reader.
	 *
	 * @var Price_Reader
	 */
	private $reader;

	/**
	 * Links.
	 *
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * The export side's price answers (mapping, profile, rule, rate).
	 *
	 * @var Price_Context
	 */
	private $contexts;

	/**
	 * The shop side: undoes the rule and saves the prices.
	 *
	 * @var Shop_Price_Writer
	 */
	private $writer;

	/**
	 * The send queue of prices, which {@see self::pull_once()} settles; null for a marketplace that sends no prices, which has nothing
	 * queued to settle.
	 *
	 * @var Price_Sync_Queue|null
	 */
	private $queue;

	/**
	 * Creates the refresher.
	 *
	 * @since 2.1.0
	 *
	 * @param Price_Reader          $reader   Reader.
	 * @param Wpdb_Link_Store       $links    Links.
	 * @param Price_Context         $contexts The export side's price answers.
	 * @param Price_Sync_Queue|null $queue    The price send queue (2.1.10), for the pull to settle.
	 */
	public function __construct( Price_Reader $reader, Wpdb_Link_Store $links, Price_Context $contexts, ?Price_Sync_Queue $queue = null ) {
		$this->reader   = $reader;
		$this->links    = $links;
		$this->contexts = $contexts;
		$this->writer   = new Shop_Price_Writer( $contexts );
		$this->queue    = $queue;
	}

	/**
	 * Refreshes the shop prices of articles from the marketplace.
	 *
	 * @since 2.1.0
	 *
	 * @param Account  $account   Account.
	 * @param string[] $offer_ids Articles.
	 * @return Price_Refresh_Result
	 * @throws \UnexpectedValueException When the marketplace answers with an article that was not asked for.
	 * @throws \Throwable When the read fails; the job classifies it.
	 */
	public function refresh( Account $account, array $offer_ids ): Price_Refresh_Result {
		$result = new Price_Refresh_Result();

		if ( Wpdb_Account_Store::PRICE_FROM_MARKETPLACE !== (string) $account->setting( 'price_direction', Wpdb_Account_Store::PRICE_TO_MARKETPLACE ) ) {
			$result->mark_disabled();

			return $result;
		}

		$links = $this->eligible( $account, $offer_ids, $result );
		$read  = $this->reader->read( $account, array_map( 'strval', array_keys( $links ) ) );

		foreach ( $links as $offer_id => $link ) {
			if ( ! isset( $read[ $offer_id ] ) ) {
				$result->add_skipped( (string) $offer_id, __( 'Маркетплейс не вернул цену этого товара.', 'woodev-plugin-framework' ) );
				continue;
			}

			$this->apply( $account, $link, $read[ $offer_id ], $result );
		}

		return $result;
	}

	/**
	 * Pulls the marketplace's prices of some created cards into the shop once, because the merchant asked for it.
	 *
	 * Unlike {@see self::refresh()} it does not look at the account's price direction (a shop on the default `to_marketplace` presses
	 * the button and expects the prices to change) nor at the link's price switch. Each card runs through {@see self::pull_card()}.
	 *
	 * @since 2.1.10
	 *
	 * @param Account       $account   Account.
	 * @param string[]      $offer_ids Articles.
	 * @param Claim_Fence   $fence     The account claim the caller holds; the queue writes are fenced by it.
	 * @param callable|null $hold      `function (): void` that extends the claim and throws when it was taken over; called before each card.
	 * @return Price_Refresh_Result
	 * @throws \UnexpectedValueException When the marketplace answers with an article that was not asked for.
	 * @throws \Throwable When the read fails or the claim is lost; the job classifies it.
	 */
	public function pull_once( Account $account, array $offer_ids, Claim_Fence $fence, ?callable $hold = null ): Price_Refresh_Result {
		$result = new Price_Refresh_Result();
		$links  = $this->eligible( $account, $offer_ids, $result, false );
		$read   = $this->reader->read( $account, array_map( 'strval', array_keys( $links ) ) );

		foreach ( $links as $offer_id => $link ) {
			if ( null !== $hold ) {
				call_user_func( $hold );
			}

			if ( isset( $read[ $offer_id ] ) ) {
				$this->pull_card( $account, $link, $read[ $offer_id ], $fence->token(), $result );
			} else {
				$result->add_skipped( (string) $offer_id, __( 'Маркетплейс не вернул цену этого товара.', 'woodev-plugin-framework' ) );
			}
		}

		return $result;
	}

	/**
	 * Finds the links that may be refreshed, recording why the others cannot.
	 *
	 * @param Account              $account   Account.
	 * @param string[]             $offer_ids Articles.
	 * @param Price_Refresh_Result $result    Result.
	 * @param bool                 $needs_sync_price Whether the link's price switch must be on: it is for the notification-driven refresh and not for the merchant's explicit pull.
	 * @return array<string,Link_Record> Link by article.
	 */
	private function eligible( Account $account, array $offer_ids, Price_Refresh_Result $result, bool $needs_sync_price = true ): array {
		$links = [];

		foreach ( array_unique( array_map( 'strval', $offer_ids ) ) as $offer_id ) {
			$link = $this->links->find_by_offer_id( $account->id(), $offer_id );

			if ( null === $link || ! $link->is_offer() ) {
				$result->add_skipped( $offer_id, __( 'Товар не связан с карточкой маркетплейса.', 'woodev-plugin-framework' ) );
			} elseif ( $needs_sync_price && ! $link->sync_price() ) {
				$result->add_skipped( $offer_id, __( 'Для товара выключена синхронизация цен.', 'woodev-plugin-framework' ) );
			} elseif ( Link_Record::STATE_CREATED !== $link->card_state() ) {
				$result->add_skipped( $offer_id, __( 'Карточка на маркетплейсе ещё не создана.', 'woodev-plugin-framework' ) );
			} else {
				$links[ $offer_id ] = $link;
			}
		}

		return $links;
	}

	/**
	 * Writes one article's marketplace price into its product.
	 *
	 * @param Account              $account Account.
	 * @param Link_Record          $link    Link.
	 * @param Price_Pair           $remote  The marketplace's prices.
	 * @param Price_Refresh_Result $result  Result.
	 * @return void
	 */
	private function apply( Account $account, Link_Record $link, Price_Pair $remote, Price_Refresh_Result $result ): void {
		$offer_id = (string) $link->offer_id();
		$product  = $this->contexts->product( $link->wc_product_id() );

		if ( null === $product ) {
			$result->add_skipped( $offer_id, __( 'Товар удалён с сайта.', 'woodev-plugin-framework' ) );

			return;
		}

		$refusal  = null;
		$restored = $this->writer->restore( $account, $product, $remote, $link->price_rule_override(), $refusal );

		if ( null === $restored ) {
			$result->add_skipped( $offer_id, (string) $refusal );

			return;
		}

		if ( $this->writer->write( $account->adapter_id(), $product, $restored ) ) {
			$result->add_updated( $offer_id );
		} else {
			$result->add_unchanged( $offer_id );
		}
	}

	/**
	 * Writes one article's marketplace price into its product and settles the price the shop had queued for it.
	 *
	 * The order is the point: the pair's pending revision is read first (`$before`), the shop price is written under
	 * {@see Price_Refresh_Guard} (so the save records no new revision), and only then is the pair settled with a statement that applies
	 * to `$before` alone. A merchant who saves the product between the read and the settle leaves a newer revision behind; the
	 * settle then fails and {@see self::restore_newer_edit()} puts that price back, because the pull is older than the edit.
	 *
	 * @param Account              $account Account.
	 * @param Link_Record          $link    Link.
	 * @param Price_Pair           $remote  The marketplace's prices.
	 * @param string               $token   Account claim token.
	 * @param Price_Refresh_Result $result  Result.
	 * @return void
	 */
	private function pull_card( Account $account, Link_Record $link, Price_Pair $remote, string $token, Price_Refresh_Result $result ): void {
		$offer_id = (string) $link->offer_id();
		$product  = $this->contexts->product( $link->wc_product_id() );

		if ( null === $product ) {
			$result->add_skipped( $offer_id, __( 'Товар удалён с сайта.', 'woodev-plugin-framework' ) );

			return;
		}

		$refusal  = null;
		$restored = $this->writer->restore( $account, $product, $remote, $link->price_rule_override(), $refusal );

		if ( null === $restored ) {
			$result->add_skipped( $offer_id, (string) $refusal );

			return;
		}

		$before  = $this->queued_revision( $account, $link );
		$written = $this->writer->write( $account->adapter_id(), $product, $restored );

		if ( $this->settled( $account, $link, $before, $remote, $token ) ) {
			if ( $written ) {
				$result->add_updated( $offer_id );
			} else {
				$result->add_unchanged( $offer_id );
			}

			return;
		}

		$kept = $this->restore_newer_edit( $account, $link, $product );

		$result->add_skipped(
			$offer_id,
			$kept ? __( 'Цену товара изменили во время загрузки — осталась новая цена с сайта.', 'woodev-plugin-framework' ) : __( 'Цена менялась во время загрузки — проверьте товар.', 'woodev-plugin-framework' )
		);
	}

	/**
	 * The pending revision of the link's price, 0 when the shop has queued none.
	 *
	 * @param Account     $account Account.
	 * @param Link_Record $link    Link.
	 * @return int
	 */
	private function queued_revision( Account $account, Link_Record $link ): int {
		$pending = null === $this->queue ? null : $this->queue->pending_of( $account->id(), (int) $link->link_id() );

		return null === $pending ? 0 : $pending->revision();
	}

	/**
	 * Tells whether the pull may stand: nothing was queued and nothing has been since, or the queued price was settled.
	 *
	 * @param Account     $account Account.
	 * @param Link_Record $link    Link.
	 * @param int         $before  The pending revision read before the shop price was written; 0 when none.
	 * @param Price_Pair  $remote  The marketplace's prices.
	 * @param string      $token   Account claim token.
	 * @return bool False when the merchant saved a price after the pull read the queue.
	 * @throws Claim_Lost When the claim fence refused the settle.
	 */
	private function settled( Account $account, Link_Record $link, int $before, Price_Pair $remote, string $token ): bool {
		if ( null === $this->queue ) {
			return true;
		}

		if ( 0 === $before ) {
			// Nothing was queued; a price that has been queued since is the merchant's save.
			return null === $this->queue->pending_of( $account->id(), (int) $link->link_id() );
		}

		if ( $this->queue->settle_pulled( $account->id(), (int) $link->link_id(), $before, $this->contexts->payload( $remote ), $token ) ) {
			return true;
		}

		// The same revision that was read and still not settled means the fence refused: the claim was taken over, not the price edited.
		// The caller's retry pulls the article again under the claim it gets then.
		if ( $this->queued_revision( $account, $link ) === $before ) {
			throw new Claim_Lost( 'The account claim was taken over during the price pull.' );
		}

		return false;
	}

	/**
	 * Puts the shop price of the newest queued intent back into the product. The merchant saved it after the pull read the queue, so
	 * the pull's write must not keep it: the intent's marketplace price is turned back into the shop price the way the pull does it for
	 * a marketplace price. A save that lands while this runs is followed once more; after {@see self::RESTORE_ROUNDS} the article is
	 * left with its newest intent untouched, to be checked by hand.
	 *
	 * @param Account     $account Account.
	 * @param Link_Record $link    Link.
	 * @param \WC_Product $product Product.
	 * @return bool Whether the newest intent's price is in the product.
	 */
	private function restore_newer_edit( Account $account, Link_Record $link, \WC_Product $product ): bool {
		if ( null === $this->queue ) {
			return false;
		}

		for ( $round = 0; $round < self::RESTORE_ROUNDS; $round++ ) {
			$intent = $this->queue->pending_of( $account->id(), (int) $link->link_id() );

			if ( null === $intent ) {
				return false;
			}

			try {
				$refusal = null;
				$shop    = $this->writer->restore( $account, $product, $intent->pair(), $link->price_rule_override(), $refusal );
			} catch ( \InvalidArgumentException $e ) {
				return false;
			}

			if ( null === $shop ) {
				return false;
			}

			$this->writer->write( $account->adapter_id(), $product, $shop );

			$newest = $this->queue->pending_of( $account->id(), (int) $link->link_id() );

			if ( null !== $newest && $newest->revision() === $intent->revision() ) {
				return true;
			}
		}

		return false;
	}
}
