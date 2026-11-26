<?php
/**
 * Keeps a price the marketplace turned down for good.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Jobs;

use Woodev\Framework\Marketplace\Ledger\Item_Result;
use Woodev\Framework\Marketplace\Price\Price_Intent;
use Woodev\Framework\Marketplace\Price\Price_Refusal;
use Woodev\Framework\Marketplace\Price\Price_Sync_Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a failed answer about a price that was taken for processing — from the price task ({@see Price_Poll_Runner}) or at once in a
 * pending receipt ({@see Price_Flush_Runner}) — and records it: a quarantine ({@see Price_Refusal::QUARANTINED}) in the framework's
 * sentence with the marketplace's name, every other failure in the marketplace's words. Either way the price is not sent again until
 * it changes ({@see Price_Sync_Queue::refuse()}).
 *
 * @since 2.1.25
 */
final class Price_Refusal_Writer {
	/**
	 * Price queue.
	 *
	 * @var Price_Sync_Queue
	 */
	private $queue;

	/**
	 * Returns the marketplace's name for the merchant: `function (): string`.
	 *
	 * @var callable
	 */
	private $marketplace_name;

	/**
	 * Creates the writer.
	 *
	 * @since 2.1.25
	 *
	 * @param Price_Sync_Queue $queue            Price queue.
	 * @param callable         $marketplace_name `function (): string`, the marketplace's name for the merchant.
	 */
	public function __construct( Price_Sync_Queue $queue, callable $marketplace_name ) {
		$this->queue            = $queue;
		$this->marketplace_name = $marketplace_name;
	}

	/**
	 * Records the refusal of a price in flight.
	 *
	 * @since 2.1.25
	 *
	 * @param int          $account_id Account id.
	 * @param Price_Intent $intent     The price that was sent.
	 * @param Item_Result  $answer     The marketplace's failure.
	 * @param string       $token      Account claim token.
	 * @return string|null The text the merchant reads; null when nothing was written: the claim is no longer held.
	 * @throws \RuntimeException When the database refuses the write.
	 */
	public function write( int $account_id, Price_Intent $intent, Item_Result $answer, string $token ): ?string {
		if ( Price_Refusal::QUARANTINED === $answer->code() ) {
			$code = Price_Refusal::QUARANTINED;
			/* translators: %s: marketplace name. */
			$message = sprintf( __( '%s держит новую цену в карантине: она намного ниже текущей', 'woodev-plugin-framework' ), (string) call_user_func( $this->marketplace_name ) );
		} else {
			$code    = Price_Refusal::REFUSED;
			$message = '' !== $answer->message() ? $answer->message() : __( 'Маркетплейс отклонил новую цену.', 'woodev-plugin-framework' );
		}

		return $this->queue->refuse( $account_id, $intent, $code, $message, $token ) ? $message : null;
	}
}
