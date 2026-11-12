<?php
/**
 * Cancelling a posting from the «Заказы» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Orders;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Order\Cancel_Refused;
use Woodev\Framework\Marketplace\Admin\Order\Order_Canceller;
use Woodev\Framework\Marketplace\Data\Persistence_Exception;
use Woodev\Framework\Marketplace\Jobs\License_Gate;

defined( 'ABSPATH' ) || exit;

/**
 * The same cancellation as the order screen's metabox ({@see \Woodev\Framework\Marketplace\Admin\Order\Cancel_Ajax}), for a table row:
 * the marketplace's {@see Order_Canceller} does the remote half, and the order gets a note and a queued refresh. The WooCommerce order
 * is not changed here; the order writer moves it to `cancelled` once the marketplace reports the cancellation.
 *
 * The checks run in the order the metabox runs them — the account must be switched on and receive orders, the licence must be in force,
 * the posting must not be finished — and carry the metabox's sentences, so a merchant reads the same words on both screens.
 *
 * @since 2.1.5
 */
final class Site_Orders_Cancel {
	/**
	 * @var Order_Canceller
	 */
	private $canceller;

	/**
	 * @var License_Gate
	 */
	private $license;

	/**
	 * Merchant-facing name of the marketplace.
	 *
	 * @var string
	 */
	private $marketplace;

	/**
	 * Creates the cancellation.
	 *
	 * @since 2.1.5
	 *
	 * @param Order_Canceller $canceller   The marketplace's cancellation.
	 * @param License_Gate    $license     Licence: no licence in force, no remote call.
	 * @param string          $marketplace Merchant-facing marketplace name.
	 */
	public function __construct( Order_Canceller $canceller, License_Gate $license, string $marketplace ) {
		$this->canceller   = $canceller;
		$this->license     = $license;
		$this->marketplace = $marketplace;
	}

	/**
	 * Tells whether the marketplace has finished with the posting of an order.
	 *
	 * @since 2.1.5
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public function is_finished( \WC_Order $order ): bool {
		return $this->canceller->is_finished( $order );
	}

	/**
	 * The sentence that says why the account cannot cancel now, or null when it can.
	 *
	 * @since 2.1.5
	 *
	 * @param Account $account Account of the order.
	 * @return string|null
	 */
	public function refusal( Account $account ): ?string {
		if ( ! $account->is_enabled() ) {
			return __( 'Подключение к маркетплейсу для этого заказа выключено в настройках плагина. Включите его и повторите.', 'woodev-plugin-framework' );
		}

		if ( ! (bool) $account->setting( 'orders_enabled', true ) ) {
			return __( 'Приём заказов с маркетплейса выключен в настройках плагина, поэтому заказ не обновится после отмены. Включите приём заказов в настройках и повторите.', 'woodev-plugin-framework' );
		}

		return $this->license->refusal_for( __( 'отменить отправление на маркетплейсе', 'woodev-plugin-framework' ) );
	}

	/**
	 * The reasons the marketplace offers for cancelling the posting of an order: one remote read.
	 *
	 * @since 2.1.5
	 *
	 * @param Account   $account Account of the order.
	 * @param \WC_Order $order   Order.
	 * @return array<int,array{value:string,label:string}> Empty when the order has no posting or the marketplace offers none.
	 * @throws Cancel_Refused When the marketplace refuses or does not answer.
	 */
	public function reasons( Account $account, \WC_Order $order ): array {
		$posting = $this->canceller->posting_number( $order );

		if ( '' === $posting ) {
			return [];
		}

		$options = [];

		foreach ( $this->canceller->reasons( $account, $posting ) as $reason ) {
			$options[] = [
				'value' => (string) $reason['id'],
				'label' => (string) $reason['title'],
			];
		}

		return $options;
	}

	/**
	 * Whether a reason asks the merchant for a free-text message.
	 *
	 * @since 2.1.5
	 *
	 * @param int $reason_id Reason id.
	 * @return bool
	 */
	public function needs_message( int $reason_id ): bool {
		return $reason_id === $this->canceller->other_reason_id();
	}

	/**
	 * Cancels the posting of an order and queues the refresh that brings the cancellation into it.
	 *
	 * @since 2.1.5
	 *
	 * @param Account   $account   Account of the order.
	 * @param \WC_Order $order     Order.
	 * @param int       $reason_id Chosen reason.
	 * @param string    $message   Free text for the reason that asks for it.
	 * @return void
	 * @throws Cancel_Refused When the order has no posting, the posting is finished, or the marketplace refuses or fails.
	 */
	public function cancel( Account $account, \WC_Order $order, int $reason_id, string $message ): void {
		$posting = $this->canceller->posting_number( $order );

		if ( '' === $posting ) {
			throw new Cancel_Refused( sprintf( /* translators: %s: marketplace name. */ __( 'Заказ не найден среди заказов «%s» этого сайта. Обновите страницу.', 'woodev-plugin-framework' ), $this->marketplace ), 404 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		if ( $this->canceller->is_finished( $order ) ) {
			throw new Cancel_Refused( __( 'Это отправление уже завершено или отменено, отменить его нельзя.', 'woodev-plugin-framework' ), 409 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		$reason = $this->canceller->cancel( $account, $posting, $reason_id, $message );

		/* translators: 1: posting number, 2: marketplace name, 3: cancellation reason. */
		$order->add_order_note( sprintf( __( 'Отправление %1$s отменено: %2$s, из WooCommerce. Причина: %3$s.', 'woodev-plugin-framework' ), $posting, $this->marketplace, $reason['title'] ) );

		try {
			$this->canceller->queue_refresh( $account, $posting );
		} catch ( Persistence_Exception $e ) {
			return; // The cancellation is done remotely; the catch-up poll refreshes every open posting anyway.
		}
	}
}
