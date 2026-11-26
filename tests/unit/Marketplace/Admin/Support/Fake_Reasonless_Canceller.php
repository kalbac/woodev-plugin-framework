<?php
/**
 * A cancellation that takes no reason, scripted.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Support;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Order\Reasonless_Canceller;

/**
 * {@see Fake_Order_Canceller} for a marketplace that gives no reasons: any call is accepted and names no reason.
 */
final class Fake_Reasonless_Canceller extends Fake_Order_Canceller implements Reasonless_Canceller {
	/**
	 * Cancels.
	 *
	 * @param Account $account        Account.
	 * @param string  $posting_number Posting.
	 * @param int     $reason_id      Reason.
	 * @param string  $message        Message.
	 * @return array{title:string}
	 */
	public function cancel( Account $account, string $posting_number, int $reason_id, string $message ): array {
		$this->cancelled[] = [ $account->id(), $posting_number, $reason_id, $message ];

		return [ 'title' => '' ];
	}

	/**
	 * @return string
	 */
	public function cancel_warning(): string {
		return 'За отмену по вине продавца начисляется штраф.';
	}
}
