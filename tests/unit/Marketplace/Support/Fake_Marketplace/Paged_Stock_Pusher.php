<?php
/**
 * A stock pusher whose reads page through several HTTP requests, and says how many.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Stock\Reports_Read_Requests;
use Woodev\Framework\Marketplace\Stock\Stock_Pusher;

/**
 * Wraps a pusher and sends one HTTP request per link of a read, as an adapter that pages its answers does: the bound it promises
 * before a read and the requests it reports after it are both the number of links times {@see self::__construct()}'s `$pages_per_link`.
 */
final class Paged_Stock_Pusher implements Stock_Pusher, Reports_Read_Requests {
	/**
	 * The wrapped pusher.
	 *
	 * @var Stock_Pusher
	 */
	private $inner;

	/**
	 * HTTP requests per link of a read.
	 *
	 * @var int
	 */
	private $pages_per_link;

	/**
	 * HTTP requests of the last read.
	 *
	 * @var int
	 */
	private $last = 1;

	/**
	 * HTTP requests of every read so far, by run: reset by the test.
	 *
	 * @var int
	 */
	public $total = 0;

	/**
	 * Creates the wrapper.
	 *
	 * @param Stock_Pusher $inner          Pusher.
	 * @param int          $pages_per_link HTTP requests per link of a read.
	 */
	public function __construct( Stock_Pusher $inner, int $pages_per_link = 1 ) {
		$this->inner          = $inner;
		$this->pages_per_link = $pages_per_link;
	}

	/**
	 * Sets stock.
	 *
	 * @param Account $account      Account.
	 * @param int     $warehouse_id Warehouse id.
	 * @param array   $lines        Lines.
	 * @return array
	 */
	public function push( Account $account, int $warehouse_id, array $lines ): array {
		return $this->inner->push( $account, $warehouse_id, $lines );
	}

	/**
	 * Reads stock back.
	 *
	 * @param Account $account      Account.
	 * @param int     $warehouse_id Warehouse id.
	 * @param array   $links        Links.
	 * @return array
	 */
	public function read( Account $account, int $warehouse_id, array $links ): array {
		$this->last   = $this->max_read_requests( $warehouse_id, $links );
		$this->total += $this->last;

		return $this->inner->read( $account, $warehouse_id, $links );
	}

	/**
	 * Returns the most HTTP requests a read of these links sends.
	 *
	 * @param int   $warehouse_id Warehouse id.
	 * @param array $links        Links.
	 * @return int
	 */
	public function max_read_requests( int $warehouse_id, array $links ): int {
		return max( 1, count( $links ) * $this->pages_per_link );
	}

	/**
	 * Returns the HTTP requests of the last read.
	 *
	 * @return int
	 */
	public function last_read_requests(): int {
		return $this->last;
	}
}
