<?php
/**
 * Stock push keyed by chrtId.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Ledger\Item_Result;
use Woodev\Framework\Marketplace\Stock\Stock_Pusher;

/**
 * Wildberries stock: `PUT /api/v3/stocks/{warehouseId}`, absolute `amount` per size id (`chrtId`), 1000 per request,
 * amount up to 100000. A link whose card has no `chrtID` yet cannot be pushed.
 */
final class Fake_Stock_Pusher implements Stock_Pusher {
	public const MAX_PER_REQUEST = 1000;
	public const MAX_AMOUNT      = 100000;

	/**
	 * Stored amounts: warehouse id => chrtId => amount.
	 *
	 * @var array<int,array<string,int>>
	 */
	private $stocks = array();

	/**
	 * Requests sent so far.
	 *
	 * @var int
	 */
	private $requests = 0;

	/**
	 * Sets stock.
	 *
	 * @param Account                                $account      Account.
	 * @param int                                    $warehouse_id Warehouse id.
	 * @param \Woodev\Framework\Marketplace\Stock\Stock_Line[] $lines        Lines.
	 * @return Item_Result[] Keyed by link id.
	 */
	public function push( Account $account, int $warehouse_id, array $lines ): array {
		$this->requests += (int) ceil( count( $lines ) / self::MAX_PER_REQUEST );

		$results = array();
		foreach ( $lines as $line ) {
			$link_id = (int) $line->link()->link_id();
			$chrt_id = $line->link()->remote_id( 'chrtID' );
			if ( null === $chrt_id ) {
				$results[ $link_id ] = Item_Result::failure( $link_id, 'chrt_id_missing', 'У карточки ещё нет chrtID: Wildberries не закончил её создание.' );
			} elseif ( $line->quantity() > self::MAX_AMOUNT ) {
				$results[ $link_id ] = Item_Result::failure( $link_id, 'amount_too_large', 'Остаток больше 100000.' );
			} else {
				$this->stocks[ $warehouse_id ][ (string) $chrt_id ] = $line->quantity();
				$results[ $link_id ]                                = Item_Result::success( $link_id );
			}
		}

		return $results;
	}

	/**
	 * Reads stock back; Wildberries has no reserved quantity in this call.
	 *
	 * @param Account                                  $account      Account.
	 * @param int                                      $warehouse_id Warehouse id.
	 * @param \Woodev\Framework\Marketplace\Links\Product_Link[] $links        Links.
	 * @return array<int,array{present:int,reserved:int}> Keyed by link id.
	 */
	public function read( Account $account, int $warehouse_id, array $links ): array {
		$read = array();
		foreach ( $links as $link ) {
			$chrt_id                        = (string) $link->remote_id( 'chrtID' );
			$read[ (int) $link->link_id() ] = array(
				'present'  => $this->stocks[ $warehouse_id ][ $chrt_id ] ?? 0,
				'reserved' => 0,
			);
		}

		return $read;
	}

	/**
	 * Returns how many requests were sent.
	 *
	 * @return int
	 */
	public function request_count(): int {
		return $this->requests;
	}
}
