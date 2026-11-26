<?php
/**
 * A price double of a marketplace that keeps one price for the sizes of a card.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Framework\Marketplace\Ledger\Item_Result;
use Woodev\Framework\Marketplace\Links\Product_Link;
use Woodev\Framework\Marketplace\Price\Price_Grouping;
use Woodev\Framework\Marketplace\Price\Price_Line;
use Woodev\Framework\Marketplace\Price\Price_Pusher;
use Woodev\Framework\Marketplace\Price\Push_Receipt;

/**
 * {@see Scripted_Price_Pusher} with price groups: the sizes of one card (the remote id `card`) make one group. A card listed in
 * `$size_prices` takes a price per size; every other card keeps one.
 */
final class Grouped_Price_Pusher implements Price_Pusher, Price_Grouping {
	/**
	 * Cards that take a price per size.
	 *
	 * @var int[]
	 */
	public $size_prices = [];

	/**
	 * Articles of the groups the sender asked about, one list per question.
	 *
	 * @var array<int,string[]>
	 */
	public $asked = [];

	/**
	 * What the next read of a price task answers.
	 *
	 * @var Item_Result[]
	 */
	public $decisions = [];

	/**
	 * What answers the requests and remembers them.
	 *
	 * @var Scripted_Price_Pusher
	 */
	public $inner;

	/**
	 * Builds the double.
	 */
	public function __construct() {
		$this->inner = new Scripted_Price_Pusher();
	}

	/**
	 * Names the identifier of a card.
	 *
	 * @return string
	 */
	public function price_group_id_name(): string {
		return 'card';
	}

	/**
	 * Tells whether the card of a group takes a price per size.
	 *
	 * @param Account        $account Account.
	 * @param Product_Link[] $members Links of the group.
	 * @return bool
	 */
	public function allows_member_prices( Account $account, array $members ): bool {
		$this->asked[] = array_map(
			static function ( Product_Link $link ): string {
				return $link->seller_sku();
			},
			$members
		);

		return in_array( $members[0]->remote_id( 'card' ), $this->size_prices, true );
	}

	/**
	 * Submits prices.
	 *
	 * @param Account      $account Account.
	 * @param Price_Line[] $lines   Prices.
	 * @return Push_Receipt
	 */
	public function submit( Account $account, array $lines ): Push_Receipt {
		return $this->inner->submit( $account, $lines );
	}

	/**
	 * Answers a price task with the scripted decisions; a request is pending only when the test scripts such a receipt.
	 *
	 * @param Account    $account Account.
	 * @param Async_Task $task    Task.
	 * @return Item_Result[]
	 */
	public function settle( Account $account, Async_Task $task ): array {
		return $this->decisions;
	}
}
