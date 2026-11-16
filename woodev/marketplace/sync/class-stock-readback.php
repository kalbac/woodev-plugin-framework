<?php
/**
 * Stock readback and drift.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Sync;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Framework\Marketplace\Jobs\Account_Run;
use Woodev\Framework\Marketplace\Jobs\Claim_Lost;
use Woodev\Framework\Marketplace\Links\Product_Link;
use Woodev\Framework\Marketplace\Stock\Reports_Read_Requests;
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
	private const DRIFT   = 'drift';
	private const MIRROR  = 'mirror';
	private const REFRESH = 'refresh';

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
	 * Told after a link's stock was applied or confirmed: `function ( Account $account, Product_Link $link, int $read_started_at, int $warehouse_id ): void`.
	 *
	 * @var callable|null
	 */
	private $on_applied;

	/**
	 * Unix time: `function (): int`.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Creates the readback.
	 *
	 * @since 2.1.0
	 *
	 * @param Wpdb_Link_Store     $links      Links.
	 * @param Stock_Pusher        $pusher     The marketplace's stock calls.
	 * @param Sync_State_Store    $intents    Pending side of `sync_state`.
	 * @param Sync_State_Queue    $queue      Flusher side of `sync_state`.
	 * @param Mirror_Stock_Writer $mirror     `marketplace` mode writer.
	 * @param Stock_Policy        $policy     The formula in force (the adapter's `stock_policy()`).
	 * @param callable|null       $on_applied `function ( Account $account, Product_Link $link, int $read_started_at, int $warehouse_id ): void`,
	 *                                        called in `marketplace` mode after a link's stock was set from the marketplace or found
	 *                                        equal to it; `$read_started_at` is the moment before the read request, `$warehouse_id`
	 *                                        the warehouse it was read in. Added in 2.1.8.
	 * @param callable|null       $clock      `function (): int`; the system clock when null. Added in 2.1.8.
	 */
	public function __construct( Wpdb_Link_Store $links, Stock_Pusher $pusher, Sync_State_Store $intents, Sync_State_Queue $queue, Mirror_Stock_Writer $mirror, Stock_Policy $policy, ?callable $on_applied = null, ?callable $clock = null ) {
		$this->links      = $links;
		$this->pusher     = $pusher;
		$this->intents    = $intents;
		$this->queue      = $queue;
		$this->mirror     = $mirror;
		$this->policy     = $policy;
		$this->on_applied = $on_applied;
		$this->clock      = $clock ?? 'time';
	}

	/**
	 * Reads and compares the stock of some links of an account, by the account's mode ({@see self::reconcile_page()} without the
	 * progress).
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
		return $this->reconcile_page( $run, $links )->changed();
	}

	/**
	 * Reads and compares the stock of the links of one page and tells how far it got.
	 *
	 * The links are read by warehouse, one request (or more, see {@see Reports_Read_Requests}) per warehouse. When the run's budget
	 * is spent before every warehouse was read, the links of the warehouses not read are in {@see Readback_Progress}: the caller
	 * resumes there and nothing is read twice.
	 *
	 * @since 2.1.8
	 *
	 * @param Account_Run   $run          The run under the account claim; each read counts against its budget.
	 * @param Link_Record[] $links        Links to check; links that are not created cards with stock sync on are skipped.
	 * @param bool          $refresh_only Only store what the marketplace holds (`remote_present`, `remote_reserved`): no shop write, no
	 *                                    drift repair, nothing queued for the marketplace.
	 * @return Readback_Progress
	 * @throws Claim_Lost When another run took the account claim; nothing is written after that.
	 * @throws \Throwable What the marketplace read threw; the caller classifies it.
	 */
	public function reconcile_page( Account_Run $run, array $links, bool $refresh_only = false ): Readback_Progress {
		$account = $run->account();

		if ( Stock_Mode::is_off( $account ) ) {
			return new Readback_Progress( [], [] ); // Nothing is compared and nothing is mirrored.
		}

		$mode    = $refresh_only ? self::REFRESH : ( Stock_Mode::is_site( $account ) ? self::DRIFT : self::MIRROR );
		$groups  = $this->groups( $account, $links );
		$changed = [];
		$unread  = [];

		foreach ( $groups as $warehouse => $products ) {
			$links_of_group = array_values( $products );

			// The HTTP requests the read may send are reserved before it starts: a read the budget cannot cover waits for the next run.
			if ( ! $run->can_request( $this->read_bound( (int) $warehouse, $links_of_group ) ) ) {
				$unread = array_merge( $unread, array_keys( $products ) ); // The caller resumes with these on its next run.
				continue;
			}

			$run->spend_request();

			$started = (int) call_user_func( $this->clock );
			$read    = $this->pusher->read( $account, (int) $warehouse, $links_of_group );

			if ( $this->pusher instanceof Reports_Read_Requests ) {
				$run->add_requests( $this->pusher->last_read_requests() - 1 );
			}

			foreach ( $read as $link_id => $remote ) {
				if ( ! isset( $products[ $link_id ] ) ) {
					continue; // An answer for a link nobody asked about changes nothing.
				}

				$entry = $this->compare( $run, (int) $warehouse, $products[ $link_id ], (int) $link_id, $remote, $mode, $started );

				if ( null !== $entry ) {
					$changed[ $link_id ] = $entry;
				}
			}
		}

		return new Readback_Progress( $changed, $unread );
	}

	/**
	 * Groups the links that can be read by their warehouse.
	 *
	 * @param Account       $account Account.
	 * @param Link_Record[] $links   Links.
	 * @return array<int,array<int,Product_Link>> Product links by link id, by warehouse.
	 */
	private function groups( Account $account, array $links ): array {
		$groups = [];

		foreach ( $links as $link ) {
			$warehouse = Stock_Warehouse::for_link( $account, $link );
			$product   = $this->links->by_product( $account->id(), $link->wc_product_id() );

			if ( null !== $warehouse && null !== $product && $link->sync_stock() && Link_Record::STATE_CREATED === $link->card_state() ) {
				$groups[ $warehouse ][ (int) $link->link_id() ] = $product;
			}
		}

		return $groups;
	}

	/**
	 * Returns the most HTTP requests a read of these links may send: what the pusher promises, one for a pusher that does not page.
	 *
	 * @param int            $warehouse Warehouse.
	 * @param Product_Link[] $links     Links of the read.
	 * @return int At least 1.
	 */
	private function read_bound( int $warehouse, array $links ): int {
		return $this->pusher instanceof Reports_Read_Requests ? max( 1, $this->pusher->max_read_requests( $warehouse, $links ) ) : 1;
	}

	/**
	 * Stores one readback and acts on it, each side effect right after the claim was renewed.
	 *
	 * @param Account_Run                     $run     The run.
	 * @param int                             $warehouse Warehouse.
	 * @param Product_Link                    $link    The pair's link.
	 * @param int                             $link_id Link id.
	 * @param array{present:int,reserved:int} $remote  The marketplace's numbers.
	 * @param string                          $mode    {@see self::DRIFT}, {@see self::MIRROR} or {@see self::REFRESH}.
	 * @param int                             $started The moment before the read request.
	 * @return array{link_id:int,wc_stock:int,expected:int,present:int,reserved:int}|null The entry when something changed.
	 * @throws Claim_Lost When the claim was taken over.
	 */
	private function compare( Account_Run $run, int $warehouse, Product_Link $link, int $link_id, array $remote, string $mode, int $started ): ?array {
		$wc_product_id = $link->wc_product_id();
		$key           = Sync_State_Queue::key( $run->account()->id(), $link_id, $warehouse, Sync_State_Store::KIND_STOCK );
		$product       = wc_get_product( $wc_product_id );
		$row           = $this->queue->get( $key );

		if ( ! $product instanceof \WC_Product || ! $product->managing_stock() || 'trash' === get_post_status( $wc_product_id ) || self::archive_pending( $row ) ) {
			return null; // A removed product is the archive flow's: its stock is not «drift» and its archive must stay pending.
		}

		$wc_stock = (int) $product->get_stock_quantity();
		$expected = self::DRIFT === $mode ? $this->policy->pushed_quantity( $wc_stock, $remote['reserved'] ) : Stock_Policy::mirrored_quantity( $remote['present'], $remote['reserved'] );
		$entry    = [
			'link_id'  => $link_id,
			'wc_stock' => $wc_stock,
			'expected' => $expected,
			'present'  => $remote['present'],
			'reserved' => $remote['reserved'],
		];

		$run->guard();

		if ( self::REFRESH === $mode ) {
			$this->remember( $run, $key, $remote );

			return null; // Only what the marketplace holds is stored: the shop and the marketplace are left as they are.
		}

		if ( self::MIRROR === $mode ) {
			$this->remember( $run, $key, $remote );

			if ( $wc_stock !== $expected ) {
				$this->mirror->mirror( $product, $remote['present'], $remote['reserved'] );
			}

			$this->applied( $run->account(), $link, $started, $warehouse );

			return $wc_stock === $expected ? null : $entry; // `wc_stock` is the value before the mirror wrote `expected`.
		}

		if ( $remote['present'] === $expected ) {
			$this->remember( $run, $key, $remote );

			return null;
		}

		// Drift: send the WC value again, even when it is the one sent last.
		$this->intents->record( $run->account()->id(), $link_id, $warehouse, Sync_State_Store::KIND_STOCK, [ 'stock' => $expected ] );
		$run->write( $this->queue->require_resend( $key, $run->token() ) );
		$run->write( $this->queue->remember_remote( $key, $remote['present'], $remote['reserved'], $run->token() ) );

		return $entry;
	}

	/**
	 * Tells the listener that a link's stock now equals the marketplace's.
	 *
	 * @param Account      $account   Account.
	 * @param Product_Link $link      The link.
	 * @param int          $started   The moment before the read request.
	 * @param int          $warehouse The warehouse the link was read in (resolved, not the link's override).
	 * @return void
	 */
	private function applied( Account $account, Product_Link $link, int $started, int $warehouse ): void {
		if ( null !== $this->on_applied ) {
			call_user_func( $this->on_applied, $account, $link, $started, $warehouse );
		}
	}

	/**
	 * Stores the readback on the pair's row; a pair that has none gets a row with only the readback on it (no pending, in-flight
	 * or sent intent), so nothing is queued for the marketplace.
	 *
	 * @param Account_Run                     $run    The run.
	 * @param array<string,mixed>             $key    Row key.
	 * @param array{present:int,reserved:int} $remote The marketplace's numbers.
	 * @return void
	 * @throws Claim_Lost When the claim was taken over.
	 */
	private function remember( Account_Run $run, array $key, array $remote ): void {
		$run->write( $this->queue->record_remote( $key, $remote['present'], $remote['reserved'], $run->token() ) );
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
