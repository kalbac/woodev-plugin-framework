<?php
/**
 * Stock readback and drift.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Sync;

use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Jobs\Account_Run;
use Woodev\Framework\Marketplace\Jobs\Claim_Lost;
use Woodev\Framework\Marketplace\Stock\Stock_Policy;
use Woodev\Framework\Marketplace\Stock\Stock_Pusher;
use Woodev\Framework\Marketplace\Stock\Sync_State_Queue;
use Woodev\Framework\Marketplace\Stock\Sync_State_Store;

defined( 'ABSPATH' ) || exit;

/**
 * The stock step of the daily reconcile: read `present` / `reserved` of the given links from the marketplace, store them on the
 * pair, then act on the account's mode:
 *
 * - `site`: the marketplace should hold what the policy pushes for the current WC stock. A pair where it does not is **drift**:
 *   the WC value becomes pending again and is sent by the next flush even when it equals the last sent one;
 * - `marketplace`: WC stock takes `max(0, present − reserved)` through {@see Mirror_Stock_Writer}.
 *
 * The runner, its cursor across runs and its schedule belong to the reconcile job; this class is the comparison it calls inside
 * an {@see Account_Run}: the claim is renewed before every read and again right before every WooCommerce or intent write, so a
 * readback that was taken over during its read writes nothing. A trashed product, or a pair whose archive is still pending, is
 * left to the archive flow: its retained WC stock is not drift.
 *
 * @since 2.1.0
 */
final class Stock_Readback {
	/**
	 * Links.
	 *
	 * @var Wpdb_Link_Store
	 */
	private $links;

	/**
	 * The marketplace's stock calls.
	 *
	 * @var Stock_Pusher
	 */
	private $pusher;

	/**
	 * Pending side of `sync_state`.
	 *
	 * @var Sync_State_Store
	 */
	private $intents;

	/**
	 * Flusher side of `sync_state`.
	 *
	 * @var Sync_State_Queue
	 */
	private $queue;

	/**
	 * `marketplace` mode writer.
	 *
	 * @var Mirror_Stock_Writer
	 */
	private $mirror;

	/**
	 * Formula.
	 *
	 * @var Stock_Policy
	 */
	private $policy;

	/**
	 * Creates the readback.
	 *
	 * @since 2.1.0
	 *
	 * @param Wpdb_Link_Store     $links   Links.
	 * @param Stock_Pusher        $pusher  The marketplace's stock calls.
	 * @param Sync_State_Store    $intents Pending side of `sync_state`.
	 * @param Sync_State_Queue    $queue   Flusher side of `sync_state`.
	 * @param Mirror_Stock_Writer $mirror  `marketplace` mode writer.
	 * @param Stock_Policy        $policy  The formula in force (the adapter's `stock_policy()`).
	 */
	public function __construct( Wpdb_Link_Store $links, Stock_Pusher $pusher, Sync_State_Store $intents, Sync_State_Queue $queue, Mirror_Stock_Writer $mirror, Stock_Policy $policy ) {
		$this->links   = $links;
		$this->pusher  = $pusher;
		$this->intents = $intents;
		$this->queue   = $queue;
		$this->mirror  = $mirror;
		$this->policy  = $policy;
	}

	/**
	 * Reads and compares the stock of some links of an account.
	 *
	 * @since 2.1.0
	 *
	 * @param Account_Run   $run   The run under the account claim; each read counts against its budget.
	 * @param Link_Record[] $links Links to check; links that are not created cards with stock sync on are skipped.
	 * @return array<int,array{link_id:int,wc_stock:int,expected:int,present:int,reserved:int}> The drift found (`site` mode) or
	 *                                                                                            mirrored (`marketplace` mode), by link id.
	 * @throws Claim_Lost When another run took the account claim; nothing is written after that.
	 * @throws \Throwable What the marketplace read threw; the caller classifies it.
	 */
	public function reconcile( Account_Run $run, array $links ): array {
		$account = $run->account();

		if ( Stock_Mode::is_off( $account ) ) {
			return []; // Nothing is compared and nothing is mirrored.
		}

		$site   = Stock_Mode::is_site( $account );
		$groups = [];

		foreach ( $links as $link ) {
			$warehouse = Stock_Warehouse::for_link( $account, $link );
			$product   = $this->links->by_product( $account->id(), $link->wc_product_id() );

			if ( null !== $warehouse && null !== $product && $link->sync_stock() && Link_Record::STATE_CREATED === $link->card_state() ) {
				$groups[ $warehouse ][ (int) $link->link_id() ] = $product;
			}
		}

		$changed = [];

		foreach ( $groups as $warehouse => $products ) {
			if ( ! $run->can_request() ) {
				break; // The caller pages the rest on its next run.
			}

			$run->spend_request();

			foreach ( $this->pusher->read( $account, (int) $warehouse, array_values( $products ) ) as $link_id => $remote ) {
				if ( ! isset( $products[ $link_id ] ) ) {
					continue; // An answer for a link nobody asked about changes nothing.
				}

				$entry = $this->compare( $run, (int) $warehouse, $products[ $link_id ]->wc_product_id(), (int) $link_id, $remote, $site );

				if ( null !== $entry ) {
					$changed[ $link_id ] = $entry;
				}
			}
		}

		return $changed;
	}

	/**
	 * Stores one readback and acts on it, each side effect right after the claim was renewed.
	 *
	 * @param Account_Run                     $run           The run.
	 * @param int                             $warehouse     Warehouse.
	 * @param int                             $wc_product_id Product id.
	 * @param int                             $link_id       Link id.
	 * @param array{present:int,reserved:int} $remote        The marketplace's numbers.
	 * @param bool                            $site          Whether the account is in `site` mode.
	 * @return array{link_id:int,wc_stock:int,expected:int,present:int,reserved:int}|null The entry when something changed.
	 * @throws Claim_Lost When the claim was taken over.
	 */
	private function compare( Account_Run $run, int $warehouse, int $wc_product_id, int $link_id, array $remote, bool $site ): ?array {
		$key     = Sync_State_Queue::key( $run->account()->id(), $link_id, $warehouse, Sync_State_Store::KIND_STOCK );
		$product = wc_get_product( $wc_product_id );
		$row     = $this->queue->get( $key );

		if ( ! $product instanceof \WC_Product || ! $product->managing_stock() || 'trash' === get_post_status( $wc_product_id ) || self::archive_pending( $row ) ) {
			return null; // A removed product is the archive flow's: its stock is not «drift» and its archive must stay pending.
		}

		$wc_stock = (int) $product->get_stock_quantity();
		$expected = $site ? $this->policy->pushed_quantity( $wc_stock, $remote['reserved'] ) : Stock_Policy::mirrored_quantity( $remote['present'], $remote['reserved'] );
		$entry    = [
			'link_id'  => $link_id,
			'wc_stock' => $wc_stock,
			'expected' => $expected,
			'present'  => $remote['present'],
			'reserved' => $remote['reserved'],
		];

		$run->guard();

		if ( ! $site ) {
			$this->remember( $run, $key, $row, $remote );

			if ( $wc_stock === $expected ) {
				return null;
			}

			$this->mirror->mirror( $product, $remote['present'], $remote['reserved'] );

			return $entry; // `wc_stock` is the value before the mirror wrote `expected`.
		}

		if ( $remote['present'] === $expected ) {
			$this->remember( $run, $key, $row, $remote );

			return null;
		}

		// Drift: send the WC value again, even when it is the one sent last.
		$this->intents->record( $run->account()->id(), $link_id, $warehouse, Sync_State_Store::KIND_STOCK, [ 'stock' => $expected ] );
		$run->write( $this->queue->require_resend( $key, $run->token() ) );
		$run->write( $this->queue->remember_remote( $key, $remote['present'], $remote['reserved'], $run->token() ) );

		return $entry;
	}

	/**
	 * Stores the readback on the pair's row when the pair has one (a `marketplace` mode pair usually has none).
	 *
	 * @param Account_Run                     $run    The run.
	 * @param array<string,mixed>             $key    Row key.
	 * @param array<string,mixed>|null        $row    The row, or null.
	 * @param array{present:int,reserved:int} $remote The marketplace's numbers.
	 * @return void
	 * @throws Claim_Lost When the claim was taken over.
	 */
	private function remember( Account_Run $run, array $key, ?array $row, array $remote ): void {
		if ( null !== $row ) {
			$run->write( $this->queue->remember_remote( $key, $remote['present'], $remote['reserved'], $run->token() ) );
		}
	}

	/**
	 * Tells whether a row still wants its card archived.
	 *
	 * @param array<string,mixed>|null $row Row.
	 * @return bool
	 */
	private static function archive_pending( ?array $row ): bool {
		return null !== $row && is_array( $row['pending_payload'] ) && true === ( $row['pending_payload'][ Stock_Intents::ARCHIVE_FLAG ] ?? false );
	}
}
