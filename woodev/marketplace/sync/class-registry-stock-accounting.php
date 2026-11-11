<?php
/**
 * Stock accounting of marketplace orders that create no WooCommerce order.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Sync;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Orders\Order_Claim_Exception;
use Woodev\Framework\Marketplace\Orders\Order_DTO;
use Woodev\Framework\Marketplace\Orders\Order_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * «Registry-only accounting»: in `site` mode an account with `orders_enabled` off still sells through the marketplace, so each
 * marketplace order takes its units from WC stock through its registry row, under the row's claim. Per line,
 * `delta = target − qty_applied` where the target is `qty_seen` (0 once the order is cancelled); a non-zero delta changes WC
 * stock by `−delta` through `wc_update_product_stock()` and records `qty_applied = target`, then the next line. A failure on
 * line B leaves line A accounted and B pending for the retry.
 *
 * **The crash window is closed, not accepted.** The stock write and its `qty_applied` write run in one database transaction
 * ({@see Stock_Transaction}); a process that dies between them commits neither, so the retry applies the line once. The stock
 * hook's `sync_state` intent rides in the same transaction, so a rolled-back write is never pushed to the marketplace either.
 *
 * **Baseline.** A plugin that took over from an earlier generation may tell, per account, the moment before which sales were
 * already taken from WC stock (`$baseline_before`). An order first seen here that was created before it becomes a baseline
 * that starts where the order stands (`qty_applied = qty_seen`, 0 for a cancelled one; nothing is subtracted), and a later
 * cancellation gives its units back once. An order created at or after that moment takes its sale normally. An order without a
 * creation time is treated as new: taking a unit twice under-sells, giving one back that was never taken over-sells.
 *
 * Accounts in `marketplace` mode are left alone: the marketplace's stock notifications set WC stock there.
 *
 * @since 2.1.0
 */
final class Registry_Stock_Accounting {
	public const RESULT_APPLIED = 'stock accounted';
	public const RESULT_SKIPPED = 'marketplace mode: nothing to account';

	/**
	 * Registry.
	 *
	 * @var Order_Registry
	 */
	private $registry;

	/**
	 * Finds the product of a line: `function ( int $account_id, string $item_id, string $offer_id ): ?\WC_Product`.
	 *
	 * @var callable
	 */
	private $product_for;

	/**
	 * Transaction around each line.
	 *
	 * @var Stock_Transaction
	 */
	private $transaction;

	/**
	 * Tells whether an order is finished: `function ( Order_DTO $order ): bool`.
	 *
	 * @var callable
	 */
	private $is_terminal;

	/**
	 * Queues the order again after a failure: `function ( int $account_id, string $group_key, int $delay ): void`.
	 *
	 * @var callable
	 */
	private $retry;

	/**
	 * Records a failure where support looks: `function ( int $account_id, string $message ): void`.
	 *
	 * @var callable
	 */
	private $report;

	/**
	 * Name of the remote identifier a registry line keeps next to the article.
	 *
	 * @var string
	 */
	private $item_id_name;

	/**
	 * Returns the Unix time before which an account's sales were already taken from WC stock, 0 for none:
	 * `function ( Account $account ): int`. Null when the plugin has no earlier generation.
	 *
	 * @var callable|null
	 */
	private $baseline_before;

	/**
	 * Interleaving seam for tests: `function ( string $point, string $line_key ): void`.
	 *
	 * @var callable|null
	 */
	private $checkpoint;

	/**
	 * Creates the accounting.
	 *
	 * @since 2.1.0
	 *
	 * @param Order_Registry    $registry        Registry.
	 * @param callable          $product_for     `function ( int $account_id, string $item_id, string $offer_id ): ?\WC_Product`.
	 * @param Stock_Transaction $transaction     Transaction around each line.
	 * @param callable          $is_terminal     `function ( Order_DTO $order ): bool`.
	 * @param callable          $retry           `function ( int $account_id, string $group_key, int $delay ): void`.
	 * @param callable          $report          `function ( int $account_id, string $message ): void`.
	 * @param string            $item_id_name    Name of the remote identifier of an order line the registry keeps (the
	 *                                           marketplace's own item id in `Order_Line::remote_ids()`).
	 * @param callable|null     $baseline_before `function ( Account $account ): int`, the Unix time before which sales were
	 *                                           already accounted; null or 0 for none.
	 * @param callable|null     $checkpoint      `function ( string $point, string $line_key ): void`; points `before_line`,
	 *                                           `between_writes`.
	 */
	public function __construct( Order_Registry $registry, callable $product_for, Stock_Transaction $transaction, callable $is_terminal, callable $retry, callable $report, string $item_id_name, ?callable $baseline_before = null, ?callable $checkpoint = null ) {
		$this->registry        = $registry;
		$this->product_for     = $product_for;
		$this->transaction     = $transaction;
		$this->is_terminal     = $is_terminal;
		$this->retry           = $retry;
		$this->report          = $report;
		$this->item_id_name    = $item_id_name;
		$this->baseline_before = $baseline_before;
		$this->checkpoint      = $checkpoint;
	}

	/**
	 * Hooks the accounting to the action the order refresh fires for an order of an account that creates no WooCommerce order:
	 * `do_action( $action, Order_DTO $order, Account $account )`.
	 *
	 * @since 2.1.0
	 *
	 * @param string $action Action name.
	 * @return void
	 */
	public function register( string $action ): void {
		add_action( $action, [ $this, 'on_order' ], 10, 2 );
	}

	/**
	 * Accounts a refreshed order; a busy row or a failure queues the refresh again, so nothing is thrown into the refresh job.
	 *
	 * @since 2.1.0
	 *
	 * @param mixed $order   The marketplace order.
	 * @param mixed $account Its account.
	 * @return void
	 */
	public function on_order( $order, $account ): void {
		if ( ! $order instanceof Order_DTO || ! $account instanceof Account ) {
			return;
		}

		try {
			$this->account( $order, $account );
		} catch ( Order_Claim_Exception $e ) {
			// A lost claim is the new holder's to finish; a busy row or a write that lost its fence is tried again.
			if ( $e->is_busy() || $e->needs_retry() ) {
				call_user_func( $this->retry, $account->id(), $order->group_key(), Order_Claim_Exception::RETRY_SECONDS );
			}
		} catch ( \Throwable $e ) {
			call_user_func( $this->report, $account->id(), self::failure_text( $order, $e ) );
			call_user_func( $this->retry, $account->id(), $order->group_key(), Order_Claim_Exception::RETRY_SECONDS );
		}
	}

	/**
	 * Accounts one order.
	 *
	 * @since 2.1.0
	 *
	 * @param Order_DTO $order   Marketplace order.
	 * @param Account   $account Its account.
	 * @return string What happened.
	 * @throws Order_Claim_Exception When another run holds the row (busy) or the claim was lost mid-way; any other failure of a
	 *                               stock or registry write is thrown as it is, and the lines accounted before it stay accounted.
	 */
	public function account( Order_DTO $order, Account $account ): string {
		if ( ! Stock_Mode::is_site( $account ) ) {
			return self::RESULT_SKIPPED;
		}

		$key = $order->group_key();
		$this->registry->reserve( $account->id(), $key );
		$token = $this->registry->claim( $account->id(), $key );

		if ( null === $token ) {
			throw Order_Claim_Exception::busy( $key ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		try {
			$this->apply( $order, $account, $token );
		} finally {
			$this->registry->release_claim( $account->id(), $key, $token );
		}

		return self::RESULT_APPLIED;
	}

	/**
	 * Records the order in its row, baselines one that predates the plugin's takeover, then accounts each line.
	 *
	 * @param Order_DTO $order   Marketplace order.
	 * @param Account   $account Account.
	 * @param string    $token   Claim token.
	 * @return void
	 * @throws Order_Claim_Exception When the claim was lost.
	 */
	private function apply( Order_DTO $order, Account $account, string $token ): void {
		$key      = $order->group_key();
		$before   = $this->registry->find( $account->id(), $key );
		$is_new   = null === $before || ( '' === $before['status_raw'] && [] === $before['lines'] );
		$terminal = (bool) call_user_func( $this->is_terminal, $order );

		// The row is marked finished only once every line is accounted, so the catch-up poll keeps refreshing an unfinished one.
		if ( $is_new && $this->predates_baseline( $order, $account ) ) {
			// The first contents and the baseline are one fact: a death between them must not leave a row that the retry takes for
			// an existing order and decrements.
			$this->transaction->run(
				function () use ( $order, $account, $token, $key ): void {
					$this->record( $order, $account, $token, false );

					if ( ! $this->registry->mark_baseline( $account->id(), $key, $token, $order->is_cancelled() ) ) {
						throw Order_Claim_Exception::lost( $key ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
					}
				}
			);
		} else {
			$this->record( $order, $account, $token, false );
		}

		$row = $this->registry->find( $account->id(), $key );

		foreach ( null === $row ? [] : $row['lines'] as $line ) {
			$this->apply_line( $order, $account, $token, $line );
		}

		if ( $terminal ) {
			$this->record( $order, $account, $token, true );
		}
	}

	/**
	 * Accounts one line: the stock write and its `qty_applied` in one transaction, right after the claim was checked.
	 *
	 * @param Order_DTO           $order   Marketplace order.
	 * @param Account             $account Account.
	 * @param string              $token   Claim token.
	 * @param array<string,mixed> $line    Registry line.
	 * @return void
	 * @throws Order_Claim_Exception When the claim was lost (the line's stock write is rolled back with it).
	 */
	private function apply_line( Order_DTO $order, Account $account, string $token, array $line ): void {
		$target = $order->is_cancelled() ? 0 : (int) ( $line['qty_seen'] ?? 0 );
		$delta  = $target - (int) ( $line['qty_applied'] ?? 0 );

		if ( 0 === $delta ) {
			return;
		}

		$product = call_user_func( $this->product_for, $account->id(), (string) ( $line['sku'] ?? '' ), (string) ( $line['offer_id'] ?? '' ) );

		if ( ! $product instanceof \WC_Product || ! $product->managing_stock() ) {
			return; // An unlinked line or one without managed stock takes nothing; it is accounted once it is linked.
		}

		$key      = $order->group_key();
		$line_key = Order_Registry::line_identity( $line );
		$this->checkpoint( 'before_line', $line_key );

		if ( ! $this->registry->holds_claim( $account->id(), $key, $token ) ) {
			throw Order_Claim_Exception::lost( $key ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		$this->transaction->run(
			function () use ( $product, $delta, $account, $key, $token, $line_key, $target ): void {
				wc_update_product_stock( $product, abs( $delta ), $delta > 0 ? 'decrease' : 'increase' );
				$this->checkpoint( 'between_writes', $line_key );

				if ( ! $this->registry->apply_line( $account->id(), $key, $token, $line_key, $target ) ) {
					throw Order_Claim_Exception::stale( $key ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
				}
			},
			[ $product->get_id(), $product->get_stock_managed_by_id() ]
		);
	}

	/**
	 * Records the order's status and lines in its row.
	 *
	 * @param Order_DTO $order    Marketplace order.
	 * @param Account   $account  Account.
	 * @param string    $token    Claim token.
	 * @param bool      $terminal Whether to mark the row finished.
	 * @return void
	 * @throws Order_Claim_Exception When the claim was lost.
	 */
	private function record( Order_DTO $order, Account $account, string $token, bool $terminal ): void {
		$lines = [];

		foreach ( $order->lines() as $line ) {
			$lines[] = [
				'offer_id' => $line->seller_sku(),
				'sku'      => (string) ( $line->remote_ids()[ $this->item_id_name ] ?? '' ),
				'qty_seen' => $line->quantity(),
			];
		}

		if ( ! $this->registry->record( $account->id(), $order->group_key(), $token, $order->status_raw(), $terminal, $lines, null ) ) {
			throw Order_Claim_Exception::lost( $order->group_key() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}
	}

	/**
	 * Tells whether an order was created before the moment the plugin took the account's stock accounting over.
	 *
	 * @param Order_DTO $order   Marketplace order.
	 * @param Account   $account Account.
	 * @return bool
	 */
	private function predates_baseline( Order_DTO $order, Account $account ): bool {
		$baseline = null === $this->baseline_before ? 0 : (int) call_user_func( $this->baseline_before, $account );
		$created  = strtotime( (string) ( $order->dates()['created_at'] ?? '' ) );

		return $baseline > 0 && false !== $created && $created < $baseline;
	}

	/**
	 * The line support reads when an order could not be accounted in full.
	 *
	 * @param Order_DTO  $order Marketplace order.
	 * @param \Throwable $error What failed.
	 * @return string
	 */
	private static function failure_text( Order_DTO $order, \Throwable $error ): string {
		return sprintf(
			/* translators: 1: marketplace order number, 2: error text */
			__( 'Остатки по заказу маркетплейса %1$s учтены не полностью, повтор через 2 минуты: %2$s', 'woodev-plugin-framework' ),
			$order->group_key(),
			$error->getMessage()
		);
	}

	/**
	 * Calls the test seam.
	 *
	 * @param string $point    Point name.
	 * @param string $line_key Line.
	 * @return void
	 */
	private function checkpoint( string $point, string $line_key ): void {
		if ( null !== $this->checkpoint ) {
			call_user_func( $this->checkpoint, $point, $line_key );
		}
	}
}
