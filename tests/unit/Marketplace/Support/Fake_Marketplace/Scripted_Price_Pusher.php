<?php
/**
 * A price double that answers at once and remembers what it was sent.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Ledger\Async_Task;
use Woodev\Framework\Marketplace\Ledger\Item_Result;
use Woodev\Framework\Marketplace\Price\Price_Line;
use Woodev\Framework\Marketplace\Price\Price_Pusher;
use Woodev\Framework\Marketplace\Price\Push_Receipt;

/**
 * A marketplace that accepts prices synchronously (the shape the donor's sender was written for): every line is accepted
 * unless the test scripts the next answers. Each script entry is `function ( Price_Line[] $lines )` and returns a
 * `Push_Receipt`, an `Item_Result[]` by link id, null for the default, or throws.
 *
 * The asynchronous shape is {@see Fake_Price_Pusher}.
 */
final class Scripted_Price_Pusher implements Price_Pusher {
	/**
	 * Requests received: per request, article => `[ price, old price, min price ]` as strings.
	 *
	 * @var array<int,array<string,array{0:string,1:string|null,2:string|null}>>
	 */
	public $requests = [];

	/**
	 * Answers that replace the default, used in order.
	 *
	 * @var callable[]
	 */
	public $script = [];

	/**
	 * Called right before a request is answered: `function (): void`.
	 *
	 * @var callable|null
	 */
	public $before_answer;

	/**
	 * Submits prices.
	 *
	 * @param Account      $account Account.
	 * @param Price_Line[] $lines   Prices.
	 * @return Push_Receipt
	 */
	public function submit( Account $account, array $lines ): Push_Receipt {
		$request = [];

		foreach ( $lines as $line ) {
			$pair                                   = $line->pair();
			$request[ $line->link()->seller_sku() ] = [
				$pair->price()->to_string(),
				null === $pair->old_price() ? null : $pair->old_price()->to_string(),
				null === $pair->min_price() ? null : $pair->min_price()->to_string(),
			];
		}

		$this->requests[] = $request;

		if ( null !== $this->before_answer ) {
			call_user_func( $this->before_answer );
		}

		$scripted = [] === $this->script ? null : call_user_func( array_shift( $this->script ), $lines );

		if ( $scripted instanceof Push_Receipt ) {
			return $scripted;
		}

		if ( is_array( $scripted ) ) {
			return Push_Receipt::immediate( $scripted );
		}

		$results = [];

		foreach ( $lines as $line ) {
			$link_id             = (int) $line->link()->link_id();
			$results[ $link_id ] = Item_Result::success( $link_id );
		}

		return Push_Receipt::immediate( $results );
	}

	/**
	 * Nothing is ever pending here.
	 *
	 * @param Account    $account Account.
	 * @param Async_Task $task    Task.
	 * @return Item_Result[]
	 */
	public function settle( Account $account, Async_Task $task ): array {
		return [];
	}
}
