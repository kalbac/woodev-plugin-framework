<?php
/**
 * The server side of the seller actions: one path for every screen.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Order;

use Woodev\Framework\Admin\Records\Action_Input_Exception;
use Woodev\Framework\Admin\Records\Records_Definition;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Action_Gate;
use Woodev\Framework\Marketplace\Jobs\License_Gate;
use Woodev\Framework\Marketplace\Settings\Effective_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the «Заказы» table and the order screen ask about the declared actions ({@see Seller_Actions}): which units take
 * one, why one is off, and the run itself. A request is judged here whatever the screen showed, in this order: the account
 * (switched on, receiving orders, able to write) and the licence; the units (the action exists for them, their status allows it,
 * the marketplace has no stored objection); the typed input; only then the marketplace is called, once, with every unit left.
 *
 * The WooCommerce order is not changed beyond a note: the adapter queues a refresh, and the order writer applies what the
 * marketplace then reports.
 *
 * @since 2.1.27
 */
final class Seller_Action_Runner {
	/**
	 * @var Seller_Actions
	 */
	private $adapter;

	/**
	 * @var Posting_Action_Gate
	 */
	private $gate;

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
	 * Writes a line to the plugin's log: `function ( string $line ): void`.
	 *
	 * @var callable|null
	 */
	private $log_error;

	/**
	 * Declared actions by id; null until asked for.
	 *
	 * @var array<string,Seller_Action>|null
	 */
	private $declared;

	/**
	 * @since 2.1.27
	 *
	 * @param Seller_Actions      $adapter     The marketplace's actions.
	 * @param Posting_Action_Gate $gate        The write rule.
	 * @param License_Gate        $license     Licence: no licence in force, no remote call.
	 * @param string              $marketplace Merchant-facing marketplace name.
	 * @param callable|null       $log_error   `function ( string $line ): void`, for failures that are not a refusal.
	 */
	public function __construct( Seller_Actions $adapter, Posting_Action_Gate $gate, License_Gate $license, string $marketplace, ?callable $log_error = null ) {
		$this->adapter     = $adapter;
		$this->gate        = $gate;
		$this->license     = $license;
		$this->marketplace = $marketplace;
		$this->log_error   = $log_error;
	}

	/**
	 * Returns the declared actions offered in a place.
	 *
	 * @since 2.1.27
	 *
	 * @param string $target One of {@see Seller_Action::UNIT}, {@see Seller_Action::ORDER}, {@see Seller_Action::BULK}.
	 * @return Seller_Action[] In the adapter's order.
	 */
	public function actions_for( string $target ): array {
		return array_values(
			array_filter(
				$this->declared(),
				static function ( Seller_Action $action ) use ( $target ): bool {
					return $action->is_for( $target );
				}
			)
		);
	}

	/**
	 * Returns one declared action.
	 *
	 * @since 2.1.27
	 *
	 * @param string $action_id Action id.
	 * @return Seller_Action|null
	 */
	public function action( string $action_id ): ?Seller_Action {
		return $this->declared()[ $action_id ] ?? null;
	}

	/**
	 * Returns the units of an order, from stored data. Two units of the same name are numbered, so the merchant can tell them
	 * apart in a report.
	 *
	 * @since 2.1.27
	 *
	 * @param \WC_Order $order The order.
	 * @return Posting_Unit[]
	 */
	public function units( \WC_Order $order ): array {
		$units = array_values(
			array_filter(
				$this->adapter->posting_units( $order ),
				static function ( $unit ): bool {
					return $unit instanceof Posting_Unit;
				}
			)
		);

		$labels = array_map(
			static function ( Posting_Unit $unit ): string {
				return $unit->label();
			},
			$units
		);
		$uses   = array_count_values( $labels );
		$seen   = [];

		foreach ( $units as $index => $unit ) {
			$label = $labels[ $index ];

			if ( $uses[ $label ] > 1 ) {
				$seen[ $label ]  = ( $seen[ $label ] ?? 0 ) + 1;
				$units[ $index ] = $unit->with_label( $label . ' №' . $seen[ $label ] );
			}
		}

		return $units;
	}

	public function account_refusal( Account $account, Seller_Action $action ): string {
		$refusal = $this->connection_refusal( $account );

		return '' !== $refusal ? $refusal : $this->licence_refusal( $action );
	}

	/**
	 * Tells why one unit cannot take an action: the account, its status or the marketplace.
	 *
	 * @since 2.1.27
	 *
	 * @param Account       $account Account of the order.
	 * @param Seller_Action $action  The action.
	 * @param \WC_Order     $order   The order.
	 * @param Posting_Unit  $unit    The unit.
	 * @return string The sentence for the merchant; empty when the unit takes it.
	 */
	public function unit_reason( Account $account, Seller_Action $action, \WC_Order $order, Posting_Unit $unit ): string {
		$refusal = $this->account_refusal( $account, $action );

		return '' !== $refusal ? $refusal : $this->gate->seller_reason( $order, $unit, $action );
	}

	/**
	 * Tells how an order's buttons look: each on when at least one unit takes its action, and off with the reason when none does.
	 * An action that does not exist for any of the order's units is left out.
	 *
	 * @since 2.1.27
	 *
	 * @param Account   $account Account of the order.
	 * @param \WC_Order $order   The order.
	 * @return array<string,array{enabled:bool,reason:string}> By action id, in the adapter's order.
	 */
	public function order_states( Account $account, \WC_Order $order ): array {
		$actions = $this->actions_for( Seller_Action::ORDER );

		if ( [] === $actions ) {
			return [];
		}

		$units      = $this->units( $order );
		$connection = $this->connection_refusal( $account );
		$states     = [];

		foreach ( $actions as $action ) {
			$taken = array_values(
				array_filter(
					$units,
					static function ( Posting_Unit $unit ) use ( $action ): bool {
						return $unit->has_action( $action->id() );
					}
				)
			);

			if ( [] !== $units && [] === $taken ) {
				continue;
			}

			$reason = '' !== $connection ? $connection : $this->licence_refusal( $action );
			$reason = '' !== $reason ? $reason : $this->units_refusal( $order, $action, $taken );

			$states[ $action->id() ] = [
				'enabled' => '' === $reason,
				'reason'  => mb_substr( $reason, 0, Records_Definition::MAX_STRING_LENGTH ), // The table takes no longer one.
			];
		}

		return $states;
	}

	/**
	 * Lists the choices of one of an action's list fields; empty when the adapter has none to give.
	 *
	 * @since 2.1.27
	 *
	 * @param Account       $account  Account.
	 * @param Seller_Action $action   The action.
	 * @param string        $field_id Field id.
	 * @param string        $search   What the merchant typed.
	 * @return array<int,array{value:string,label:string}>
	 */
	public function field_options( Account $account, Seller_Action $action, string $field_id, string $search ): array {
		if ( ! $this->adapter instanceof Seller_Action_Options || '' !== $this->account_refusal( $account, $action ) ) {
			return [];
		}

		foreach ( $action->fields() as $field ) {
			if ( $field->id() === $field_id && $field->has_options_source() ) {
				return array_slice( array_values( $this->adapter->seller_field_options( $account, $action->id(), $field_id, $search ) ), 0, 100 );
			}
		}

		return [];
	}

	/**
	 * Says what an action would reach, and checks the input. Sends nothing.
	 *
	 * @since 2.1.27
	 *
	 * @param Account             $account Account of the orders.
	 * @param Seller_Action       $action  The action.
	 * @param \WC_Order[]         $orders  Orders of the account.
	 * @param array<string,mixed> $input   Typed values by field id, unslashed.
	 * @param string              $unit_id Only this unit of the orders; empty for every unit.
	 * @return Seller_Action_Plan
	 * @throws Seller_Action_Refused  When the account or the licence does not allow the action.
	 * @throws Action_Input_Exception When the input is refused.
	 */
	public function preview( Account $account, Seller_Action $action, array $orders, array $input, string $unit_id = '' ): Seller_Action_Plan {
		return $this->prepare( $account, $action, $orders, $input, $unit_id )[0];
	}

	/**
	 * Judges the request from current state and applies the action to every unit that takes it.
	 *
	 * @since 2.1.27
	 *
	 * @param Account             $account Account of the orders.
	 * @param Seller_Action       $action  The action.
	 * @param \WC_Order[]         $orders  Orders of the account.
	 * @param array<string,mixed> $input   Typed values by field id, unslashed.
	 * @param string              $unit_id Only this unit of the orders; empty for every unit.
	 * @return Seller_Action_Report
	 * @throws Seller_Action_Refused  When nothing was done: the account, the licence or the marketplace does not allow it, or the
	 *                                marketplace did not answer.
	 * @throws Action_Input_Exception When the input is refused; nothing is sent.
	 */
	public function run( Account $account, Seller_Action $action, array $orders, array $input, string $unit_id = '' ): Seller_Action_Report {
		list( $plan, $values ) = $this->prepare( $account, $action, $orders, $input, $unit_id );

		if ( [] === $plan->targets() ) {
			return new Seller_Action_Report( [], $plan->skipped() );
		}

		try {
			$answers = $this->adapter->run_seller_action( $account, new Seller_Action_Call( $action->id(), $plan->targets(), $values ) );
		} catch ( Seller_Action_Refused $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			$this->log( 'Running the seller action ' . $action->id() . ' failed', $e );
			$this->refresh( $account, $plan->targets() ); // The answer was lost: the action may have gone through.

			throw new Seller_Action_Refused( __( 'Маркетплейс не ответил: действие могло выполниться. Проверьте состояние заказа, прежде чем повторять.', 'woodev-plugin-framework' ), 502 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		$report = new Seller_Action_Report( $this->answered( $plan->targets(), $answers ), $plan->skipped() );

		$this->add_notes( $action, $report );
		$this->refresh( $account, $plan->targets() );

		return $report;
	}

	/**
	 * @param Account             $account Account.
	 * @param Seller_Action       $action  Action.
	 * @param \WC_Order[]         $orders  Orders.
	 * @param array<string,mixed> $input   Typed values.
	 * @param string              $unit_id Only this unit; empty for all.
	 * @return array{0:Seller_Action_Plan,1:array<string,string>} The plan and the checked values.
	 * @throws Seller_Action_Refused  When the account or the licence does not allow the action.
	 * @throws Action_Input_Exception When the input is refused.
	 */
	private function prepare( Account $account, Seller_Action $action, array $orders, array $input, string $unit_id ): array {
		$refusal = $this->account_refusal( $account, $action );

		if ( '' !== $refusal ) {
			throw new Seller_Action_Refused( $refusal, $this->license->is_in_force() ? 409 : 403 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		$plan = $this->plan( $action, $orders, $unit_id );

		if ( [] === $plan->targets() ) {
			return [ $plan, [] ]; // Nothing would be sent, so there is no input to judge.
		}

		$values = Seller_Action_Input::read( $action, $input );

		$this->require_accepted_input( $account, $action, new Seller_Action_Call( $action->id(), $plan->targets(), $values ) );

		return [ $plan, $values ];
	}

	/**
	 * Asks the marketplace's own rule about the input.
	 *
	 * @param Account            $account Account.
	 * @param Seller_Action      $action  Action.
	 * @param Seller_Action_Call $call    The call that would be sent.
	 * @return void
	 * @throws Action_Input_Exception When a field is refused.
	 * @throws Seller_Action_Refused  When the adapter refuses something that is not a declared field.
	 */
	private function require_accepted_input( Account $account, Seller_Action $action, Seller_Action_Call $call ): void {
		$errors = $this->adapter->check_seller_input( $account, $call );

		if ( [] === $errors ) {
			return;
		}

		$declared = [];

		foreach ( $action->fields() as $field ) {
			$declared[ $field->id() ] = true;
		}

		if ( [] !== array_diff_key( $errors, $declared ) ) {
			throw new Seller_Action_Refused( implode( ' ', array_map( 'strval', $errors ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		throw new Action_Input_Exception( $errors ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
	}

	/**
	 * Sorts the units of the orders into those the action reaches and those it leaves out.
	 *
	 * @param Seller_Action $action  Action.
	 * @param \WC_Order[]   $orders  Orders.
	 * @param string        $unit_id Only this unit; empty for all.
	 * @return Seller_Action_Plan
	 */
	private function plan( Seller_Action $action, array $orders, string $unit_id ): Seller_Action_Plan {
		$targets = [];
		$skipped = [];

		foreach ( $orders as $order ) {
			$units = $this->units( $order );
			$asked = array_filter(
				$units,
				static function ( Posting_Unit $unit ) use ( $action, $unit_id ): bool {
					return $unit->has_action( $action->id() ) && ( '' === $unit_id || $unit->id() === $unit_id );
				}
			);

			if ( [] === $asked ) {
				$skipped[] = [
					'order'  => $order,
					'unit'   => '',
					'reason' => [] === $units ? self::no_units() : __( 'Это действие не относится к этому заказу.', 'woodev-plugin-framework' ),
				];
			}

			foreach ( $asked as $unit ) {
				$reason = $this->gate->seller_reason( $order, $unit, $action );

				if ( '' === $reason ) {
					$targets[] = new Seller_Action_Target( $order, $unit );
				} else {
					$skipped[] = [
						'order'  => $order,
						'unit'   => $unit->label(),
						'reason' => $reason,
					];
				}
			}
		}

		return new Seller_Action_Plan( $targets, $skipped );
	}

	/**
	 * Gives every sent unit exactly one answer. A unit the marketplace did not mention is failed, never done; of two answers for
	 * one unit the refusal wins.
	 *
	 * @param Seller_Action_Target[] $targets Units sent.
	 * @param array<mixed>           $answers What the adapter returned.
	 * @return Seller_Unit_Result[] In the order of the targets.
	 */
	private function answered( array $targets, array $answers ): array {
		$by_unit = [];

		foreach ( $answers as $answer ) {
			if ( ! $answer instanceof Seller_Unit_Result ) {
				continue;
			}

			$key = $answer->target()->key();

			if ( ! isset( $by_unit[ $key ] ) || $by_unit[ $key ]->is_done() ) {
				$by_unit[ $key ] = $answer;
			}
		}

		$results = [];

		foreach ( $targets as $target ) {
			$results[] = $by_unit[ $target->key() ] ?? Seller_Unit_Result::refused(
				$target,
				__( 'Маркетплейс не ответил по этой позиции. Проверьте её состояние в кабинете, прежде чем повторять.', 'woodev-plugin-framework' )
			);
		}

		return $results;
	}

	private function add_notes( Seller_Action $action, Seller_Action_Report $report ): void {
		$done   = [];
		$failed = [];
		$orders = [];

		foreach ( $report->done() as $result ) {
			$order                       = $result->target()->order();
			$orders[ $order->get_id() ]  = $order;
			$done[ $order->get_id() ][]  = $result->target()->unit()->label();
		}

		foreach ( $report->failed() as $result ) {
			$order                        = $result->target()->order();
			$orders[ $order->get_id() ]   = $order;
			$failed[ $order->get_id() ][] = $result->target()->unit()->label() . ' — ' . $result->reason();
		}

		foreach ( $orders as $order_id => $order ) {
			/* translators: 1: marketplace name, 2: name of the action. */
			$text = sprintf( __( '%1$s: действие «%2$s», из WooCommerce.', 'woodev-plugin-framework' ), $this->marketplace, $action->label() );

			if ( isset( $done[ $order_id ] ) ) {
				/* translators: %s: goods, separated by semicolons. */
				$text .= ' ' . sprintf( __( 'Выполнено: %s.', 'woodev-plugin-framework' ), implode( '; ', $done[ $order_id ] ) );
			}

			if ( isset( $failed[ $order_id ] ) ) {
				/* translators: %s: goods with the reason of each, separated by semicolons. */
				$text .= ' ' . sprintf( __( 'Не выполнено: %s', 'woodev-plugin-framework' ), implode( '; ', $failed[ $order_id ] ) );
			}

			$order->add_order_note( $text );
		}
	}

	/**
	 * Queues the refresh of the orders that were sent; a failure of the queue is not an error for the merchant.
	 *
	 * @param Account                $account Account.
	 * @param Seller_Action_Target[] $targets Units sent.
	 * @return void
	 */
	private function refresh( Account $account, array $targets ): void {
		$orders = [];

		foreach ( $targets as $target ) {
			$orders[ $target->order()->get_id() ] = $target->order();
		}

		try {
			$this->adapter->refresh_after_action( $account, array_values( $orders ) );
		} catch ( \Throwable $e ) {
			$this->log( 'Queueing the refresh after a seller action failed', $e ); // The catch-up poll refreshes every open posting anyway.
		}
	}

	/**
	 * @param Account $account Account.
	 * @return string Why the account cannot write to the marketplace at all: switched off, not receiving orders, or its key
	 *                cannot write. Empty when it can.
	 */
	private function connection_refusal( Account $account ): string {
		if ( ! $account->is_enabled() ) {
			return __( 'Подключение к маркетплейсу для этого заказа выключено в настройках плагина. Включите его и повторите.', 'woodev-plugin-framework' );
		}

		if ( ! Effective_Settings::for_adapter( $account->adapter_id() )->orders_enabled( $account ) ) {
			return __( 'Приём заказов с маркетплейса выключен в настройках плагина, поэтому заказ не обновится после действия. Включите приём заказов в настройках и повторите.', 'woodev-plugin-framework' );
		}

		return $this->gate->write_refusal( $account );
	}

	/**
	 * @param Seller_Action $action Action.
	 * @return string Why the licence does not allow the action; empty when it does.
	 */
	private function licence_refusal( Seller_Action $action ): string {
		/* translators: %s: name of the action, «Принять в работу». */
		return (string) $this->license->refusal_for( sprintf( __( 'выполнить действие «%s» на маркетплейсе', 'woodev-plugin-framework' ), $action->label() ) );
	}

	/**
	 * @param \WC_Order      $order  Order.
	 * @param Seller_Action  $action Action.
	 * @param Posting_Unit[] $units  The units the action exists for.
	 * @return string Why none of them takes the action (the first one's reason); empty when at least one does.
	 */
	private function units_refusal( \WC_Order $order, Seller_Action $action, array $units ): string {
		$first = '';

		foreach ( $units as $unit ) {
			$reason = $this->gate->seller_reason( $order, $unit, $action );

			if ( '' === $reason ) {
				return '';
			}

			$first = '' === $first ? $reason : $first;
		}

		return '' === $first ? self::no_units() : $first;
	}

	/**
	 * @return array<string,Seller_Action> Declared actions by id.
	 */
	private function declared(): array {
		if ( null === $this->declared ) {
			$this->declared = [];

			foreach ( $this->adapter->seller_actions() as $action ) {
				if ( $action instanceof Seller_Action ) {
					$this->declared[ $action->id() ] = $action;
				}
			}
		}

		return $this->declared;
	}

	/**
	 * @param string     $what What was being done.
	 * @param \Throwable $e    Failure.
	 * @return void
	 */
	private function log( string $what, \Throwable $e ): void {
		if ( null !== $this->log_error ) {
			call_user_func( $this->log_error, sprintf( '%s: %s: %s', $what, get_class( $e ), $e->getMessage() ) );
		}
	}

	/**
	 * @return string Why an order with no stored units takes no action.
	 */
	private static function no_units(): string {
		return __( 'У этого заказа нет сведений о товарах на маркетплейсе, поэтому действие недоступно.', 'woodev-plugin-framework' );
	}
}
