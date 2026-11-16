<?php
/**
 * The work of the remote-cards bulk run: freeze the cards the filter reaches, then feed them to the import job.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Bulk_Run;
use Woodev\Framework\Marketplace\Data\Bulk_Run_Store;

defined( 'ABSPATH' ) || exit;

/**
 * «Импортировать все по фильтру» (#1796). The cards come from one transient snapshot, not from SQL, and the import plan of the ticked
 * mode loads a WooCommerce product per article — so this run does neither at scale. It does not plan at all: the import job is the
 * authority on every card (it reads the card from the marketplace itself, and `Card_Import` decides in its own order), so the run only
 * needs the **articles and their order**.
 *
 * 1. **Freeze** (the first part): read the snapshot once, apply the table's filter, drop the cards a product carries already, put the
 *    cards in the order the import needs and hand them to the runner as the run's `items` — `[ article, group key ]` and nothing else —
 *    to be stored together with the part's cursor, counters and report. After this the snapshot may expire: nothing later reads it.
 * 2. **Feed** (every later part): hand the next {@see self::FEED} articles to {@see Import_Scheduler::import()} — ten actions, each one
 *    second after the previous part so the queue's order is explicit — and move the cursor.
 *
 * **The order** is the rule of `Remote_Cards_Import_Plan::with_products()`: first the articles that are the SKU of a shop product that
 * still carries the import marker (a half-made product of an earlier import — resuming it comes before any sibling of its group can
 * join it), then the rest with the cards of one group side by side. The marker is looked up in one query per
 * {@see self::LOOKUP} articles, never one `wc_get_product()` per article.
 *
 * The cursor is `{ frozen, index }`: `index` is the first article of `items` not fed yet.
 *
 * @since 2.1.10
 */
final class Bulk_Import_Feeder implements Bulk_Run_Worker {
	/**
	 * Cards one part hands to the import job: ten actions of {@see Import_Scheduler::BATCH}.
	 */
	public const FEED = 100;

	/**
	 * Articles one marker lookup asks about.
	 */
	public const LOOKUP = 1000;

	/**
	 * The right a user needs to import cards: the one of the action itself.
	 */
	private const CAPABILITY = 'manage_woocommerce';

	/**
	 * @var Bulk_Run_Store
	 */
	private $runs;

	/**
	 * @var Import_Scheduler
	 */
	private $importer;

	/**
	 * The cards the filter reaches: `function ( int $account_id, array $query ): ?array` — `cards`, the `[ article, group key ]` of every
	 * card no product carries yet, in the snapshot's order, and `on_site`, the articles a product carries already; null when the list of
	 * cards is not loaded or has expired.
	 *
	 * @var callable
	 */
	private $entries;

	/**
	 * Names the articles whose shop product is half-made: `function ( string[] $articles ): string[]`.
	 *
	 * @var callable
	 */
	private $marked;

	/**
	 * The list the last refusal check read, kept for the freeze that follows it in the same tick: `[ run id, entries ]`.
	 *
	 * @var array{0:string,1:array{cards:array<int,array{0:string,1:string}>,on_site:string[]}}|null
	 */
	private $listed = null;

	/**
	 * The frozen list of the run this tick works on: `[ run id, items ]`. One read per tick, not one per part.
	 *
	 * @var array{0:string,1:array<int,array{0:string,1:string}>}|null
	 */
	private $items = null;

	/**
	 * Creates the feeder.
	 *
	 * @since 2.1.10
	 *
	 * @param Bulk_Run_Store   $runs     Runs.
	 * @param Import_Scheduler $importer Queues the import job.
	 * @param callable         $entries  `function ( int $account_id, array $query ): ?array`; `Remote_Cards_Matching::entries()` in production.
	 * @param callable         $marked   `function ( string[] $articles ): string[]`; {@see self::marked_in_database()} in production.
	 */
	public function __construct( Bulk_Run_Store $runs, Import_Scheduler $importer, callable $entries, callable $marked ) {
		$this->runs     = $runs;
		$this->importer = $importer;
		$this->entries  = $entries;
		$this->marked   = $marked;
	}

	/**
	 * Looks the half-made products up in the database: the articles that are the SKU of a product carrying the import marker.
	 *
	 * @since 2.1.10
	 *
	 * @param \wpdb  $db         Database.
	 * @param string $marker_key Meta key an import marks its half-made product with (`Product_Writer::marker_key()`).
	 * @return callable `function ( string[] $articles ): string[]`.
	 */
	public static function marked_in_database( \wpdb $db, string $marker_key ): callable {
		return static function ( array $articles ) use ( $db, $marker_key ): array {
			$marked = [];

			foreach ( array_chunk( array_values( $articles ), self::LOOKUP ) as $chunk ) {
				$holders = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
				$found   = $db->get_col( $db->prepare( "SELECT lookup.sku FROM {$db->prefix}wc_product_meta_lookup AS lookup INNER JOIN {$db->postmeta} AS mark ON mark.post_id = lookup.product_id AND mark.meta_key = %s AND mark.meta_value <> '' WHERE lookup.sku IN ( {$holders} )", array_merge( [ $marker_key ], $chunk ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder -- Core tables; only placeholders are interpolated.

				foreach ( (array) $found as $sku ) {
					$marked[] = (string) $sku;
				}
			}

			return $marked;
		};
	}

	/**
	 * A run that has not frozen its list needs the list of cards to be loaded; a user who may not import stops it.
	 *
	 * @since 2.1.10
	 *
	 * @param Account  $account Account.
	 * @param Bulk_Run $run     The run.
	 * @return string|null
	 */
	public function refusal( Account $account, Bulk_Run $run ): ?string {
		if ( ! user_can( $run->user_id(), self::CAPABILITY ) ) {
			return __( 'У пользователя, запустившего действие, больше нет прав.', 'woodev-plugin-framework' );
		}

		if ( $this->is_frozen( $run ) ) {
			return null;
		}

		$entries = call_user_func( $this->entries, $account->id(), $run->filter() );

		if ( null === $entries ) {
			return __( 'Список карточек устарел — нажмите «Обновить список» и повторите.', 'woodev-plugin-framework' );
		}

		$this->listed = [ $run->run_id(), $entries ];

		return null;
	}

	/**
	 * @since 2.1.10
	 *
	 * @param Account             $account Account.
	 * @param Bulk_Run            $run     The run.
	 * @param array<string,mixed> $cursor  Where the previous part stopped.
	 * @param callable            $owns    `function (): bool`.
	 * @return array{cursor:array<string,mixed>,finished:bool,counts:array<string,int>,groups:array<int,array<string,mixed>>,busy:int,items?:array<int,array{0:string,1:string}>}
	 * @throws Bulk_Run_Lost When this tick lost the run.
	 */
	public function part( Account $account, Bulk_Run $run, array $cursor, callable $owns ): array {
		if ( empty( $cursor['frozen'] ) ) {
			return $this->freeze( $account, $run, $owns );
		}

		return $this->feed( $account, $run, (int) ( $cursor['index'] ?? 0 ), $owns );
	}

	/**
	 * Reads the cards the filter reaches and stores them, in the order the import needs.
	 *
	 * @param Account  $account Account.
	 * @param Bulk_Run $run     The run.
	 * @param callable $owns    `function (): bool`.
	 * @return array{cursor:array<string,mixed>,finished:bool,counts:array<string,int>,groups:array<int,array<string,mixed>>,busy:int,items?:array<int,array{0:string,1:string}>}
	 * @throws Bulk_Run_Lost When this tick lost the run.
	 * @throws \RuntimeException When the list of cards expired between the refusal check and the freeze.
	 */
	private function freeze( Account $account, Bulk_Run $run, callable $owns ): array {
		$entries = $this->listed_for( $run ) ?? call_user_func( $this->entries, $account->id(), $run->filter() );

		if ( null === $entries ) {
			throw new \RuntimeException( 'The list of cards expired before the run froze it.' );
		}

		$this->listed = null;
		$on_site      = $entries['on_site'];
		$items        = $this->in_import_order( $entries['cards'] );

		unset( $entries );

		if ( ! $owns() ) {
			throw new Bulk_Run_Lost( 'The tick lost the run before it froze the list.' );
		}

		// Nothing is stored here. The list goes back with the position it was frozen at, and the runner saves both in ONE fenced write
		// ({@see Bulk_Run_Store::save_frozen()}): a tick that dies before it, or loses the run, leaves a run that has frozen nothing, and
		// the next tick freezes it anew; once it is saved, the list and the cursor that says «frozen» are there together.
		$this->items = [ $run->run_id(), $items ];

		return [
			'cursor'   => $this->cursor( 0 ),
			'finished' => [] === $items,
			'counts'   => $this->counts( 0, 0, count( $on_site ) ),
			'groups'   => $this->on_site_groups( $on_site ),
			'busy'     => 0,
			'items'    => $items,
		];
	}

	/**
	 * Hands the next cards to the import job.
	 *
	 * @param Account  $account Account.
	 * @param Bulk_Run $run     The run.
	 * @param int      $index   The first article of the list not fed yet.
	 * @param callable $owns    `function (): bool`.
	 * @return array{cursor:array<string,mixed>,finished:bool,counts:array<string,int>,groups:array<int,array<string,mixed>>,busy:int,items?:array<int,array{0:string,1:string}>}
	 * @throws Bulk_Run_Lost When this tick lost the run.
	 */
	private function feed( Account $account, Bulk_Run $run, int $index, callable $owns ): array {
		$items = $this->frozen_items( $account, $run );
		$slice = array_slice( $items, $index, self::FEED );

		if ( [] === $slice ) {
			return $this->fed( $index, 0, true );
		}

		if ( ! $owns() ) {
			throw new Bulk_Run_Lost( 'The tick lost the run before it fed a part.' );
		}

		// One second after the previous part: the queue starts same-second actions in an order nobody verified, so the order is made explicit.
		$this->importer->import( $account->id(), array_column( $slice, 0 ), intdiv( $index, self::FEED ) );

		$next = $index + count( $slice );

		return $this->fed( $next, count( $slice ), $next >= count( $items ) );
	}

	/**
	 * @param int  $next     The first article not fed yet.
	 * @param int  $fed      Cards this part fed.
	 * @param bool $finished Whether the list is over.
	 * @return array{cursor:array<string,mixed>,finished:bool,counts:array<string,int>,groups:array<int,array<string,mixed>>,busy:int,items?:array<int,array{0:string,1:string}>}
	 */
	private function fed( int $next, int $fed, bool $finished ): array {
		return [
			'cursor'   => $this->cursor( $next ),
			'finished' => $finished,
			'counts'   => $this->counts( $fed, $fed, 0 ),
			'groups'   => [],
			'busy'     => 0,
		];
	}

	/**
	 * The frozen list of the run, read once per tick.
	 *
	 * @param Account  $account Account.
	 * @param Bulk_Run $run     The run.
	 * @return array<int,array{0:string,1:string}>
	 * @throws \RuntimeException When the run says it froze its list and the row holds none.
	 */
	private function frozen_items( Account $account, Bulk_Run $run ): array {
		if ( null === $this->items || $run->run_id() !== $this->items[0] ) {
			$stored = $this->runs->items( $account->id(), $run->run_id() );

			if ( null === $stored ) {
				throw new \RuntimeException( 'The run has no frozen list of cards.' );
			}

			$this->items = [ $run->run_id(), $stored ];
		}

		return $this->items[1];
	}

	/**
	 * The cards in the order the import needs: the ones that resume a half-made product first, then the rest with the cards of one
	 * group side by side (a card that stands alone keeps its place).
	 *
	 * Memory is the point: a 50 000-card list is held once, as `$cards`, and the order is written as a second list of the **same** pairs
	 * (a pair is shared, never copied) — no bucket array per card, no copy of the list per step — so the freeze costs a slot per card
	 * on top of the list itself, also on PHP 7.4 where every array is heavier.
	 *
	 * @param array<int,array{0:string,1:string}> $cards Article and group key of each card, in the snapshot's order.
	 * @return array<int,array{0:string,1:string}>
	 */
	private function in_import_order( array $cards ): array {
		$resuming = array_flip( (array) call_user_func( $this->marked, array_column( $cards, 0 ) ) );
		$items    = [];
		$next     = [];
		$last     = [];

		// Pass 1: the resuming cards go first; the cards of one group are chained by position, `$next[ a ] = b`, with no array per card.
		foreach ( $cards as $position => $card ) {
			if ( isset( $resuming[ $card[0] ] ) ) {
				$items[] = $card;
			} elseif ( '' !== $card[1] ) {
				if ( isset( $last[ $card[1] ] ) ) {
					$next[ $last[ $card[1] ] ] = $position;
				}

				$last[ $card[1] ] = $position;
			}
		}

		unset( $last );

		// Pass 2: a card that stands alone keeps its place; a group is written whole at the place of its first card. `$items` holds the
		// very pairs of `$cards` (a second reference to each, not a copy), so the order costs one slot per card.
		$placed = [];

		foreach ( $cards as $position => $card ) {
			if ( isset( $resuming[ $card[0] ] ) ) {
				continue;
			}

			if ( '' === $card[1] ) {
				$items[] = $card;
			} elseif ( ! isset( $placed[ $card[1] ] ) ) {
				$placed[ $card[1] ] = true;

				for ( $member = $position; null !== $member; $member = $next[ $member ] ?? null ) {
					$items[] = $cards[ $member ];
				}
			}
		}

		return $items;
	}

	/**
	 * The report line for the cards a product carries already: they are not queued.
	 *
	 * @param string[] $articles Articles.
	 * @return array<int,array<string,mixed>>
	 */
	private function on_site_groups( array $articles ): array {
		if ( [] === $articles ) {
			return [];
		}

		return [
			[
				'kind'     => 'skipped',
				'reason'   => __( 'Уже на сайте', 'woodev-plugin-framework' ),
				'count'    => count( $articles ),
				'examples' => array_slice( $articles, 0, Bulk_Run_Report::EXAMPLES ),
			],
		];
	}

	/**
	 * @param int $done     Cards fed so far in this part.
	 * @param int $accepted Cards queued.
	 * @param int $skipped  Cards left out.
	 * @return array<string,int>
	 */
	private function counts( int $done, int $accepted, int $skipped ): array {
		return [
			'products_done'   => $done,
			'offers_accepted' => $accepted,
			'offers_skipped'  => $skipped,
			'offers_failed'   => 0,
		];
	}

	/**
	 * @param int $index The first article not fed yet.
	 * @return array{frozen:bool,index:int}
	 */
	private function cursor( int $index ): array {
		return [
			'frozen' => true,
			'index'  => $index,
		];
	}

	/**
	 * @param Bulk_Run $run The run.
	 * @return bool Whether the run has stored its list already.
	 */
	private function is_frozen( Bulk_Run $run ): bool {
		return ! empty( $run->cursor()['frozen'] );
	}

	/**
	 * The list the refusal check read for this very run.
	 *
	 * @param Bulk_Run $run The run.
	 * @return array{cards:array<int,array{0:string,1:string}>,on_site:string[]}|null
	 */
	private function listed_for( Bulk_Run $run ): ?array {
		return null !== $this->listed && $run->run_id() === $this->listed[0] ? $this->listed[1] : null;
	}
}
