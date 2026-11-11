<?php
/**
 * A stock double that remembers what it was sent.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Ledger\Item_Result;
use Woodev\Framework\Marketplace\Stock\Stock_Pusher;
use Woodev\Framework\Marketplace\Sync\Stock_Rules;

/**
 * The marketplace of the stock tests: a push sets `present` per article, the readback reports it, the archive answers `true`.
 * A test replaces the next answers through {@see self::$script}: each entry is
 * `function ( string $call, int $warehouse_id, array $items )` and returns the answer of that call (`Item_Result[]` by link id
 * for a push, a bool for an archive), null for the default answer, or throws.
 *
 * It is both halves a real adapter supplies: the pusher and the rules of a stock send. Its three refusal codes are its own,
 * unlike any real marketplace's, so a test proves the flusher reads the verdict and never a code.
 */
final class Scripted_Stock_Pusher implements Stock_Pusher, Stock_Rules {
	public const PUSH    = 'push';
	public const READ    = 'read';
	public const ARCHIVE = 'archive';

	public const CODE_SLOW_DOWN = 'fake_slow_down';
	public const CODE_UNKNOWN   = 'fake_no_such_card';
	public const CODE_SHELVED   = 'fake_card_shelved';

	/**
	 * What the marketplace holds: article => units.
	 *
	 * @var array<string,int>
	 */
	public $present = [];

	/**
	 * Units reserved by open orders: article => units.
	 *
	 * @var array<string,int>
	 */
	public $reserved = [];

	/**
	 * Calls received: `[ call, warehouse id, items ]`; the items of a push are article => quantity, of the others a list of
	 * articles.
	 *
	 * @var array<int,array{0:string,1:int,2:array}>
	 */
	public $requests = [];

	/**
	 * Answers that replace the defaults, used in order.
	 *
	 * @var callable[]
	 */
	public $script = [];

	/**
	 * Sets stock.
	 *
	 * @param Account                                          $account      Account.
	 * @param int                                              $warehouse_id Warehouse id.
	 * @param \Woodev\Framework\Marketplace\Stock\Stock_Line[] $lines        Lines.
	 * @return Item_Result[] Keyed by link id.
	 */
	public function push( Account $account, int $warehouse_id, array $lines ): array {
		$items = [];

		foreach ( $lines as $line ) {
			$items[ $line->link()->seller_sku() ] = $line->quantity();
		}

		$scripted = $this->scripted( self::PUSH, $warehouse_id, $items );

		if ( null !== $scripted ) {
			return $scripted;
		}

		$results = [];

		foreach ( $lines as $line ) {
			$link_id                                  = (int) $line->link()->link_id();
			$this->present[ $line->link()->seller_sku() ] = $line->quantity();
			$results[ $link_id ]                      = Item_Result::success( $link_id );
		}

		return $results;
	}

	/**
	 * Reads stock back.
	 *
	 * @param Account                                            $account      Account.
	 * @param int                                                $warehouse_id Warehouse id.
	 * @param \Woodev\Framework\Marketplace\Links\Product_Link[] $links        Links.
	 * @return array<int,array{present:int,reserved:int}> Keyed by link id.
	 */
	public function read( Account $account, int $warehouse_id, array $links ): array {
		$articles = [];

		foreach ( $links as $link ) {
			$articles[] = $link->seller_sku();
		}

		$scripted = $this->scripted( self::READ, $warehouse_id, $articles );

		if ( null !== $scripted ) {
			return $scripted;
		}

		$read = [];

		foreach ( $links as $link ) {
			if ( isset( $this->present[ $link->seller_sku() ] ) ) {
				$read[ (int) $link->link_id() ] = [
					'present'  => $this->present[ $link->seller_sku() ],
					'reserved' => $this->reserved[ $link->seller_sku() ] ?? 0,
				];
			}
		}

		return $read;
	}

	/**
	 * Tells what a refused line means.
	 *
	 * @param Item_Result $answer The answer.
	 * @return string
	 */
	public function verdict( Item_Result $answer ): string {
		$verdicts = [
			self::CODE_SLOW_DOWN => self::WAIT,
			self::CODE_UNKNOWN   => self::NOT_READY,
			self::CODE_SHELVED   => self::ARCHIVED,
		];

		return $verdicts[ $answer->code() ] ?? self::FAILED;
	}

	/**
	 * Archives cards.
	 *
	 * @param Account                                            $account Account.
	 * @param \Woodev\Framework\Marketplace\Links\Product_Link[] $links   Links.
	 * @return bool
	 */
	public function archive( Account $account, array $links ): bool {
		$articles = [];

		foreach ( $links as $link ) {
			$articles[] = $link->seller_sku();
		}

		$scripted = $this->scripted( self::ARCHIVE, 0, $articles );

		return null === $scripted ? true : (bool) $scripted;
	}

	/**
	 * Returns the items of every call of one kind.
	 *
	 * @param string $call `PUSH`, `READ` or `ARCHIVE`.
	 * @return array<int,array>
	 */
	public function sent( string $call ): array {
		$items = [];

		foreach ( $this->requests as $request ) {
			if ( $call === $request[0] ) {
				$items[] = $request[2];
			}
		}

		return $items;
	}

	/**
	 * Builds a refused line.
	 *
	 * @param int    $link_id Link id.
	 * @param string $code    One of the codes, or anything else for a plain failure.
	 * @param string $message The marketplace's text.
	 * @return Item_Result
	 */
	public static function refused( int $link_id, string $code, string $message = 'нет' ): Item_Result {
		return Item_Result::failure( $link_id, $code, $message );
	}

	/**
	 * Records a call and returns the scripted answer, if the script has one for it.
	 *
	 * @param string $call         Call.
	 * @param int    $warehouse_id Warehouse id.
	 * @param array  $items        Items.
	 * @return mixed Null for the default answer.
	 */
	private function scripted( string $call, int $warehouse_id, array $items ) {
		$this->requests[] = [ $call, $warehouse_id, $items ];

		if ( [] === $this->script ) {
			return null;
		}

		return call_user_func( array_shift( $this->script ), $call, $warehouse_id, $items );
	}
}
