<?php
/**
 * Whether the write actions of a posting are on offer.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Shared;

use Woodev\Framework\Marketplace\Account\Account;

defined( 'ABSPATH' ) || exit;

/**
 * The one rule behind «Отменить» and «Этикетки» on both the «Заказы» table and the order screen: a write action is offered only when
 * the account can write ({@see \Woodev\Framework\Marketplace\Marketplace_Plugin::account_write_gate()}, the gate the write jobs ask
 * too) and, for this posting, the marketplace allows it ({@see Posting_Allowed_Actions}); a label also needs the posting to be ready
 * for one ({@see Label_Availability}). Each answer is the sentence for the merchant, or an empty string when the action is on offer.
 *
 * Everything is decided from what the plugin has stored, so no answer costs a call to the marketplace. The server asks again before it
 * acts on a request: an action that is off in the interface is off for a hand-made request too.
 *
 * @since 2.1.16
 */
final class Posting_Action_Gate {
	/**
	 * The label rule: finished and not-ready postings.
	 *
	 * @var Label_Availability
	 */
	private $labels;

	/**
	 * The adapter's allowed actions, when it has them.
	 *
	 * @var Posting_Allowed_Actions|null
	 */
	private $allowed;

	/**
	 * The plugin's write gate: `function ( Account $account ): bool`; null lets every account write.
	 *
	 * @var callable|null
	 */
	private $write_gate;

	/**
	 * Creates the rule.
	 *
	 * @since 2.1.16
	 *
	 * @param Label_Availability           $labels     The label rule.
	 * @param Posting_Allowed_Actions|null $allowed    The adapter's allowed actions; null for an adapter that has none.
	 * @param callable|null                $write_gate `function ( Account $account ): bool`, the plugin's write gate; null for none.
	 */
	public function __construct( Label_Availability $labels, ?Posting_Allowed_Actions $allowed = null, ?callable $write_gate = null ) {
		$this->labels     = $labels;
		$this->allowed    = $allowed;
		$this->write_gate = $write_gate;
	}

	/**
	 * Builds the rule from what an adapter implements: {@see Label_Readiness} and {@see Posting_Allowed_Actions}, each optional.
	 *
	 * @since 2.1.16
	 *
	 * @param object        $adapter    The marketplace's adapter.
	 * @param callable|null $write_gate `function ( Account $account ): bool`, the plugin's write gate; null lets every account write.
	 * @return self
	 */
	public static function for_adapter( $adapter, ?callable $write_gate = null ): self {
		return new self(
			new Label_Availability( $adapter instanceof Label_Readiness ? $adapter : null ),
			$adapter instanceof Posting_Allowed_Actions ? $adapter : null,
			$write_gate
		);
	}

	/**
	 * Tells why the account cannot write to the marketplace.
	 *
	 * @since 2.1.16
	 *
	 * @param Account $account The account of the posting.
	 * @return string The sentence for the merchant; empty when the account can write.
	 */
	public function write_refusal( Account $account ): string {
		if ( null === $this->write_gate || true === call_user_func( $this->write_gate, $account ) ) {
			return '';
		}

		return __( 'Ключ этого аккаунта не позволяет менять данные на маркетплейсе (например, он выдан только для чтения), поэтому действие недоступно.', 'woodev-plugin-framework' );
	}

	/**
	 * Tells why a posting cannot be cancelled, apart from being finished (that is the adapter's {@see \Woodev\Framework\Marketplace\Admin\Order\Order_Canceller::is_finished()}).
	 *
	 * @since 2.1.16
	 *
	 * @param \WC_Order $order The order of the posting.
	 * @return string The sentence for the merchant; empty when the marketplace allows it or says nothing.
	 */
	public function cancel_reason( \WC_Order $order ): string {
		if ( $this->is_allowed( $order, Posting_Allowed_Actions::CANCEL ) ) {
			return '';
		}

		return __( 'Маркетплейс сейчас не разрешает отменить это отправление — например, оно уже передано в доставку.', 'woodev-plugin-framework' );
	}

	/**
	 * Tells why a label cannot be asked for a posting: it is finished, the adapter says it is not ready, or the marketplace has not
	 * allowed the labels.
	 *
	 * @since 2.1.16
	 *
	 * @param \WC_Order $order       The order of the posting.
	 * @param string    $status      The marketplace's status of the posting, as stored.
	 * @param bool      $is_terminal Whether the marketplace has finished with the posting.
	 * @return string The sentence for the merchant; empty when the label can be asked for.
	 */
	public function label_reason( \WC_Order $order, string $status, bool $is_terminal ): string {
		$reason = $this->labels->reason( $status, $is_terminal );

		if ( '' !== $reason || $this->is_allowed( $order, Posting_Allowed_Actions::LABEL ) ) {
			return $reason;
		}

		return __( 'Маркетплейс ещё не выдал этикетку для этого отправления — она станет доступна, когда отправление будет собрано.', 'woodev-plugin-framework' );
	}

	/**
	 * Whether the marketplace allows an action of the posting; true when the adapter has no say or has not said for this posting.
	 *
	 * @param \WC_Order $order  The order of the posting.
	 * @param string    $action Action id.
	 * @return bool
	 */
	private function is_allowed( \WC_Order $order, string $action ): bool {
		if ( null === $this->allowed ) {
			return true;
		}

		$list = $this->allowed->allowed_posting_actions( $order );

		return null === $list || in_array( $action, $list, true );
	}
}
