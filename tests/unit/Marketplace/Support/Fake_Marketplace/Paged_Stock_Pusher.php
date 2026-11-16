<?php
/**
 * A stock pusher whose reads page through several HTTP requests.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Stock\Reports_Read_Requests;
use Woodev\Framework\Marketplace\Stock\Stock_Pusher;

/**
 * Wraps a pusher and reports a fixed number of HTTP requests for every read, as an adapter that pages its answers does.
 */
final class Paged_Stock_Pusher implements Stock_Pusher, Reports_Read_Requests {
	/**
	 * The wrapped pusher.
	 *
	 * @var Stock_Pusher
	 */
	private $inner;

	/**
	 * HTTP requests of every read.
	 *
	 * @var int
	 */
	private $pages;

	/**
	 * Creates the wrapper.
	 *
	 * @param Stock_Pusher $inner Pusher.
	 * @param int          $pages HTTP requests of every read.
	 */
	public function __construct( Stock_Pusher $inner, int $pages ) {
		$this->inner = $inner;
		$this->pages = $pages;
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
		return $this->inner->read( $account, $warehouse_id, $links );
	}

	/**
	 * Returns the HTTP requests of the last read.
	 *
	 * @return int
	 */
	public function last_read_requests(): int {
		return $this->pages;
	}
}
