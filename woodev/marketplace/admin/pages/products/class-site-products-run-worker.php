<?php
/**
 * The work of a «Товары сайта» bulk run: read the next part under the filter, do it.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Products;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Bulk_Run;
use Woodev\Framework\Marketplace\Jobs\Account_Pause;
use Woodev\Framework\Marketplace\Jobs\Bulk_Run_Worker;
use Woodev\Framework\Marketplace\Jobs\Job_Gate;
use Woodev\Framework\Marketplace\Jobs\License_Gate;

defined( 'ABSPATH' ) || exit;

/**
 * One part of an action applied to every product under a filter (#1795). The cursor is `{ parent, child }`, both product ids:
 *
 * 1. while `child > 0`, the current variable product's next variations are read after `child` (`post_parent = parent`, `ID > child`,
 *    `ORDER BY ID LIMIT n`, `n` the room left in the part); a read shorter than `n` finishes the parent;
 * 2. otherwise the next parents are read under the filter after `parent` (the list's own WHERE plus `ID > parent`, ascending). A simple
 *    product is one offer and finishes at once; a variable product enters step 1.
 *
 * So a part never holds more than its size, a variable product of any size is walked over as many parts as it needs, every offer is
 * reached once (a variation belongs to one parent and the `(parent, child)` order only grows), and a part runs the same
 * {@see Site_Products_Bulk} code the ticked mode does. The filter is read again for every new parent: a product is taken as it is when its
 * turn comes. A parent already entered is finished even if it leaves the filter half way.
 *
 * Part sizes are proposals, kept as constants ({@see self::SIZES}).
 *
 * @since 2.1.8
 */
final class Site_Products_Run_Worker implements Bulk_Run_Worker {
	/**
	 * Offers in one part, by action: the export validates and builds the whole card of every offer (one `export_batch`), detaching takes
	 * the account claim, the rest records an intent or flips a column.
	 */
	public const SIZES = [
		Site_Products_Bulk::EXPORT => 100,
		Site_Products_Bulk::DETACH => 200,
	];

	/**
	 * Offers in one part of any other action.
	 */
	public const DEFAULT_SIZE = 500;

	/**
	 * Actions that lead to a remote write: a lapsed licence or a paused account ends the run before it queues more work.
	 */
	private const REMOTE = [
		Site_Products_Bulk::EXPORT,
		Site_Products_Bulk::SEND_STOCK,
		Site_Products_Bulk::SEND_PRICE,
		Site_Products_Bulk::ARCHIVE,
	];

	/**
	 * @var Site_Products_Reader
	 */
	private $reader;

	/**
	 * @var Site_Products_Bulk
	 */
	private $bulk;

	/**
	 * @var License_Gate
	 */
	private $license;

	/**
	 * @var Account_Pause|null
	 */
	private $pause;

	/**
	 * Creates the worker.
	 *
	 * @since 2.1.8
	 *
	 * @param Site_Products_Reader $reader  Reader.
	 * @param Site_Products_Bulk   $bulk    The actions.
	 * @param License_Gate         $license Licence gate.
	 * @param Account_Pause|null   $pause   Account pause; none when null.
	 */
	public function __construct( Site_Products_Reader $reader, Site_Products_Bulk $bulk, License_Gate $license, ?Account_Pause $pause = null ) {
		$this->reader  = $reader;
		$this->bulk    = $bulk;
		$this->license = $license;
		$this->pause   = $pause;
	}

	/**
	 * Offers in one part of an action.
	 *
	 * @since 2.1.8
	 *
	 * @param string $action Action id.
	 * @return int
	 */
	public static function size_of( string $action ): int {
		return self::SIZES[ $action ] ?? self::DEFAULT_SIZE;
	}

	/**
	 * @since 2.1.8
	 *
	 * @param Account  $account Account.
	 * @param Bulk_Run $run     The run.
	 * @return string|null
	 */
	public function refusal( Account $account, Bulk_Run $run ): ?string {
		if ( ! user_can( $run->user_id(), Site_Products_Definition::EDIT ) ) {
			return __( 'У пользователя, запустившего действие, больше нет прав.', 'woodev-plugin-framework' );
		}

		if ( ! in_array( $run->action(), self::REMOTE, true ) ) {
			return null;
		}

		$phrase = Site_Products_Bulk::license_phrase( $run->action() );
		$lapsed = null === $phrase ? null : $this->license->refusal_for( $phrase );

		if ( null !== $lapsed ) {
			return $lapsed;
		}

		return null !== $this->pause && $this->pause->remaining( $account ) > 0
			? __( 'Аккаунт приостановлен после отказа маркетплейса в доступе. Проверьте ключи в настройках аккаунта.', 'woodev-plugin-framework' )
			: null;
	}

	/**
	 * @since 2.1.8
	 *
	 * @param Account             $account Account.
	 * @param Bulk_Run            $run     The run.
	 * @param array<string,mixed> $cursor  Where the previous part stopped.
	 * @param callable            $owns    `function (): bool`.
	 * @return array{cursor:array<string,mixed>,finished:bool,counts:array<string,int>,groups:array<int,array<string,mixed>>,busy:int}
	 */
	public function part( Account $account, Bulk_Run $run, array $cursor, callable $owns ): array {
		$filter = Site_Products_Filter::from_query( $account->id(), $run->filter() );
		$read   = $this->read( $filter, (int) ( $cursor['parent'] ?? 0 ), (int) ( $cursor['child'] ?? 0 ), self::size_of( $run->action() ) );
		$empty  = [
			'products_done'   => 0,
			'offers_accepted' => 0,
			'offers_skipped'  => 0,
			'offers_failed'   => 0,
		];

		if ( [] === $read['offers'] ) {
			return [
				'cursor'   => $read['cursor'],
				'finished' => true,
				'counts'   => [ 'products_done' => $read['products'] ] + $empty,
				'groups'   => [],
				'busy'     => 0,
			];
		}

		$done = $this->bulk->run_part( $account, $run->action(), $read['offers'], $read['editable'], Site_Products_Bulk::SET_WAREHOUSE === $run->action() ? (int) ( $run->payload()['warehouse'] ?? 0 ) : null, $owns );

		if ( $done['busy'] ) {
			return [
				'cursor'   => $cursor,
				'finished' => false,
				'counts'   => $empty,
				'groups'   => [],
				'busy'     => Job_Gate::BUSY_DELAY,
			];
		}

		return [
			'cursor'   => $read['cursor'],
			'finished' => $read['exhausted'],
			'counts'   => [
				'products_done'   => $read['products'],
				'offers_accepted' => $done['accepted'],
				'offers_skipped'  => $done['skipped'],
				'offers_failed'   => $done['failed'],
			],
			'groups'   => $done['groups'],
			'busy'     => 0,
		];
	}

	/**
	 * Reads the next part from the cursor.
	 *
	 * @param Site_Products_Filter $filter Filters.
	 * @param int                  $parent The last product the run entered.
	 * @param int                  $child  The last variation of it that was read; 0 when the parent is finished.
	 * @param int                  $size   Most offers.
	 * @return array{offers:int[],editable:array<int,int>,cursor:array{parent:int,child:int},products:int,exhausted:bool}
	 */
	private function read( Site_Products_Filter $filter, int $parent, int $child, int $size ): array {
		$offers   = [];
		$editable = [];
		$products = 0;
		$walking  = $child > 0;

		while ( count( $offers ) < $size ) {
			$room = $size - count( $offers );

			if ( $walking ) {
				$ids = $this->reader->variation_ids_after( $parent, $child, $room );

				foreach ( $ids as $id ) {
					$offers[]       = $id;
					$editable[ $id ] = $parent;
				}

				// A read shorter than the room means the parent has no more variations.
				$walking = count( $ids ) >= $room;
				$child   = $walking ? (int) end( $ids ) : 0;
				$products += $walking ? 0 : 1;
				continue;
			}

			$next = $this->reader->ids_after( $filter, $parent, $room );

			if ( [] === $next ) {
				return $this->part_of( $offers, $editable, $parent, $child, $products, true );
			}

			foreach ( $next as $id => $variable ) {
				$parent = $id;

				if ( $variable ) {
					$walking = true;
					$child   = 0;
					break;
				}

				$offers[]        = $id;
				$editable[ $id ] = $id;
				++$products;

				if ( count( $offers ) >= $size ) {
					break;
				}
			}
		}

		return $this->part_of( $offers, $editable, $parent, $child, $products, false );
	}

	/**
	 * @param int[]            $offers    Offers.
	 * @param array<int,int>   $editable  Offer => product whose right decides.
	 * @param int              $parent    Cursor parent.
	 * @param int              $child     Cursor child.
	 * @param int              $products  Parents finished in this part.
	 * @param bool             $exhausted Whether there is nothing after this part.
	 * @return array{offers:int[],editable:array<int,int>,cursor:array{parent:int,child:int},products:int,exhausted:bool}
	 */
	private function part_of( array $offers, array $editable, int $parent, int $child, int $products, bool $exhausted ): array {
		return [
			'offers'    => $offers,
			'editable'  => $editable,
			'cursor'    => [
				'parent' => $parent,
				'child'  => $child,
			],
			'products'  => $products,
			'exhausted' => $exhausted,
		];
	}
}
