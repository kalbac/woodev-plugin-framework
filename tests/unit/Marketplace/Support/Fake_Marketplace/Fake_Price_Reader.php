<?php
/**
 * Price read double.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Price\Price_Pair;
use Woodev\Framework\Marketplace\Sync\Price_Reader;

/**
 * Answers with the prices the test put into {@see self::$prices}; an article it does not hold is absent from the answer, as a
 * marketplace leaves out a card it does not know. A queued {@see self::$failures} entry is thrown instead of an answer.
 */
final class Fake_Price_Reader implements Price_Reader {
	/**
	 * Prices by article.
	 *
	 * @var array<string,Price_Pair>
	 */
	public $prices = [];

	/**
	 * Articles asked for, per read.
	 *
	 * @var array<int,string[]>
	 */
	public $reads = [];

	/**
	 * What the next reads throw, in order.
	 *
	 * @var \Throwable[]
	 */
	public $failures = [];

	/**
	 * Reads prices.
	 *
	 * @param Account  $account   Account.
	 * @param string[] $offer_ids Articles.
	 * @return array<string,Price_Pair>
	 * @throws \Throwable A queued failure.
	 */
	public function read( Account $account, array $offer_ids ): array {
		$this->reads[] = array_values( $offer_ids );

		if ( [] !== $this->failures ) {
			throw array_shift( $this->failures );
		}

		return array_intersect_key( $this->prices, array_flip( $offer_ids ) );
	}
}
