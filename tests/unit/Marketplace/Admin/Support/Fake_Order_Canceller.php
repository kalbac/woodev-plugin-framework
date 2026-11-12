<?php
/**
 * The marketplace's cancellation, scripted.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Support;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Order\Cancel_Refused;
use Woodev\Framework\Marketplace\Admin\Order\Order_Canceller;

/**
 * Reads the posting from the `_fake_posting` meta of the order; records what it was asked to do.
 */
class Fake_Order_Canceller implements Order_Canceller {
	public const META_POSTING  = '_fake_posting';
	public const META_FINISHED = '_fake_finished';

	/**
	 * Reasons it offers.
	 *
	 * @var array<int,array{id:int,title:string}>
	 */
	public $reasons = [
		[
			'id'    => 1,
			'title' => 'Нет товара',
		],
		[
			'id'    => 9,
			'title' => 'Другое',
		],
	];

	/**
	 * Cancellations asked for: `[ account id, posting, reason id, message ]`.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public $cancelled = [];

	/**
	 * Refreshes queued: `[ account id, posting ]`.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public $refreshed = [];

	/**
	 * Reasons-lists asked for.
	 *
	 * @var int
	 */
	public $reason_calls = 0;

	/**
	 * Returns the posting of an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	public function posting_number( \WC_Order $order ): string {
		return (string) $order->get_meta( self::META_POSTING );
	}

	/**
	 * Tells whether the posting is finished.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public function is_finished( \WC_Order $order ): bool {
		return '1' === (string) $order->get_meta( self::META_FINISHED );
	}

	/**
	 * Lists the reasons.
	 *
	 * @param Account $account        Account.
	 * @param string  $posting_number Posting.
	 * @return array<int,array{id:int,title:string}>
	 */
	public function reasons( Account $account, string $posting_number ): array {
		++$this->reason_calls;

		return $this->reasons;
	}

	/**
	 * Returns the «other» reason.
	 *
	 * @return int
	 */
	public function other_reason_id(): int {
		return 9;
	}

	/**
	 * Cancels.
	 *
	 * @param Account $account        Account.
	 * @param string  $posting_number Posting.
	 * @param int     $reason_id      Reason.
	 * @param string  $message        Message.
	 * @return array{title:string}
	 * @throws Cancel_Refused When the reason is unknown.
	 */
	public function cancel( Account $account, string $posting_number, int $reason_id, string $message ): array {
		foreach ( $this->reasons as $reason ) {
			if ( $reason['id'] === $reason_id ) {
				$this->cancelled[] = [ $account->id(), $posting_number, $reason_id, $message ];

				return [ 'title' => $reason['title'] ];
			}
		}

		throw new Cancel_Refused( 'Неизвестная причина', 422 );
	}

	/**
	 * Queues the refresh.
	 *
	 * @param Account $account        Account.
	 * @param string  $posting_number Posting.
	 * @return void
	 */
	public function queue_refresh( Account $account, string $posting_number ): void {
		$this->refreshed[] = [ $account->id(), $posting_number ];
	}
}
