<?php
/**
 * Whether the write actions of a posting are on offer.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Shared;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Order\Posting_Unit;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Actions;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Settings\Posting_Noun;

defined( 'ABSPATH' ) || exit;

/**
 * The one rule behind «Отменить», «Этикетки» and the seller actions on both the «Заказы» table and the order screen: a write
 * action is offered only when the account can write ({@see \Woodev\Framework\Marketplace\Marketplace_Plugin::account_write_gate()},
 * the gate the write jobs ask too) and, for this posting, the marketplace allows it ({@see Posting_Allowed_Actions}); a label also
 * needs the posting to be ready for one ({@see Label_Availability}), and a seller action a unit in one of its statuses
 * ({@see Seller_Action}). Each answer is the sentence for the merchant, or an empty string when the action is on offer.
 *
 * Everything is decided from what the plugin has stored, so no answer costs a call to the marketplace. The server asks again before it
 * acts on a request: an action that is off in the interface is off for a hand-made request too.
 *
 * @since 2.1.17
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
	 * The plugin's write gate: `function ( Account $account ): bool|string`; null lets every account write.
	 *
	 * @var callable|null
	 */
	private $write_gate;

	/**
	 * The marketplace's word for a posting.
	 *
	 * @var Posting_Noun
	 */
	private $noun;

	/**
	 * The adapter's seller actions, when it declares them.
	 *
	 * @var Seller_Actions|null
	 */
	private $seller;

	/**
	 * Creates the rule.
	 *
	 * @since 2.1.17
	 * @since 2.1.23 Takes the posting noun.
	 * @since 2.1.27 Takes the seller actions.
	 *
	 * @param Label_Availability           $labels     The label rule.
	 * @param Posting_Allowed_Actions|null $allowed    The adapter's allowed actions; null for an adapter that has none.
	 * @param callable|null                $write_gate `function ( Account $account ): bool|string`, the plugin's write gate (`true` allows, `false` refuses with the generic sentence, a string refuses with that sentence); null for none.
	 * @param Posting_Noun|null            $noun       The marketplace's word for a posting; null for «отправление».
	 * @param Seller_Actions|null          $seller     The adapter's seller actions, asked for their refusals; null for an adapter that declares none.
	 */
	public function __construct( Label_Availability $labels, ?Posting_Allowed_Actions $allowed = null, ?callable $write_gate = null, ?Posting_Noun $noun = null, ?Seller_Actions $seller = null ) {
		$this->labels     = $labels;
		$this->allowed    = $allowed;
		$this->write_gate = $write_gate;
		$this->noun       = $noun ?? Posting_Noun::shipment();
		$this->seller     = $seller;
	}

	/**
	 * Builds the rule from what an adapter implements: {@see Label_Readiness} and {@see Posting_Allowed_Actions}, each optional.
	 * An adapter's sentences use the noun of its settings descriptor.
	 *
	 * @since 2.1.17
	 *
	 * @param object        $adapter    The marketplace's adapter.
	 * @param callable|null $write_gate `function ( Account $account ): bool|string`, the plugin's write gate (see {@see self::write_refusal()}); null lets every account write.
	 * @return self
	 */
	public static function for_adapter( $adapter, ?callable $write_gate = null ): self {
		$noun = $adapter instanceof Marketplace_Adapter ? Posting_Noun::for_descriptor( $adapter->settings_descriptor() ) : null;

		return new self(
			new Label_Availability( $adapter instanceof Label_Readiness ? $adapter : null, $noun ),
			$adapter instanceof Posting_Allowed_Actions ? $adapter : null,
			$write_gate,
			$noun,
			$adapter instanceof Seller_Actions ? $adapter : null
		);
	}

	/**
	 * Tells why the account cannot write to the marketplace.
	 *
	 * The plugin's write gate answers `true` (the account may write), `false` (it may not, no reason given: the generic sentence below
	 * is shown) or a non-empty string (it may not, and the string is the sentence for the merchant — a plugin that closes writes for a
	 * reason other than a read-only key says so itself). Anything else counts as a refusal with the generic sentence.
	 *
	 * @since 2.1.17
	 * @since 2.1.19 A write gate may answer with the reason.
	 *
	 * @param Account $account The account of the posting.
	 * @return string The sentence for the merchant; empty when the account can write.
	 */
	public function write_refusal( Account $account ): string {
		if ( null === $this->write_gate ) {
			return '';
		}

		$answer = call_user_func( $this->write_gate, $account );

		if ( true === $answer ) {
			return '';
		}

		if ( is_string( $answer ) && '' !== $answer ) {
			return $answer;
		}

		return __( 'Ключ этого аккаунта не позволяет менять данные на маркетплейсе (например, он выдан только для чтения), поэтому действие недоступно.', 'woodev-plugin-framework' );
	}

	/**
	 * Tells why a posting cannot be cancelled, apart from being finished (that is the adapter's {@see \Woodev\Framework\Marketplace\Admin\Order\Order_Canceller::is_finished()}).
	 *
	 * @since 2.1.17
	 *
	 * @param \WC_Order $order The order of the posting.
	 * @return string The sentence for the merchant; empty when the marketplace allows it or says nothing.
	 */
	public function cancel_reason( \WC_Order $order ): string {
		if ( $this->is_allowed( $order, Posting_Allowed_Actions::CANCEL ) ) {
			return '';
		}

		/* translators: %s: the marketplace's word for a posting, «отправление». */
		return sprintf( __( 'Маркетплейс сейчас не разрешает отменить это %s — например, оно уже передано в доставку.', 'woodev-plugin-framework' ), $this->noun->one() );
	}

	/**
	 * Tells why a label cannot be asked for a posting: it is finished, the adapter says it is not ready, or the marketplace has not
	 * allowed the labels.
	 *
	 * @since 2.1.17
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

		/* translators: 1: the marketplace's word for a posting in the genitive, «отправления», 2: the same word, «отправление». */
		return sprintf( __( 'Маркетплейс ещё не выдал этикетку для этого %1$s — она станет доступна, когда %2$s будет собрано.', 'woodev-plugin-framework' ), $this->noun->of_one(), $this->noun->one() );
	}

	/**
	 * Tells why a seller action cannot be applied to a unit: its status is not one the action is declared for, or the marketplace
	 * has said no for this unit ({@see Seller_Actions::seller_action_refusal()}). The account is judged apart
	 * ({@see self::write_refusal()}).
	 *
	 * @since 2.1.27
	 *
	 * @param \WC_Order     $order  The order of the unit.
	 * @param Posting_Unit  $unit   The unit.
	 * @param Seller_Action $action The declared action.
	 * @return string The sentence for the merchant; empty when the action can be applied.
	 */
	public function seller_reason( \WC_Order $order, Posting_Unit $unit, Seller_Action $action ): string {
		if ( ! $action->is_offered_in( $unit->status() ) ) {
			if ( '' === $unit->status_label() ) {
				return __( 'В текущем статусе это действие недоступно.', 'woodev-plugin-framework' );
			}

			/* translators: %s: the marketplace status of the goods, as the merchant reads it. */
			return sprintf( __( 'В статусе «%s» это действие недоступно.', 'woodev-plugin-framework' ), $unit->status_label() );
		}

		return null === $this->seller ? '' : $this->seller->seller_action_refusal( $order, $unit, $action->id() );
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
