<?php
/**
 * The seller actions of the «Заказы» table.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Admin\Pages\Orders;

use Woodev\Framework\Admin\Records\Action_Input_Exception;
use Woodev\Framework\Admin\Records\Action_Response;
use Woodev\Framework\Admin\Records\Action_State_Exception;
use Woodev\Framework\Admin\Records\Records_Definition;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action_Field;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action_Refused;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action_Runner;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the declared actions ({@see Seller_Action}) into the records engine's row and bulk actions and the runner's answers
 * ({@see Seller_Action_Runner}) into the engine's plan and report. The counts of a plan and a report are **goods** (units), not
 * orders: the marketplace answers for each, and every refusal is shown with its own sentence and the goods it concerns.
 *
 * The engine keeps row and bulk actions in one id space, so an action has the id `act-{id}` on an order's row, `unit-{id}` on the
 * row of one unit ({@see Site_Orders_Units}) and `bulk-{id}` among the bulk actions.
 *
 * @since 2.1.27
 */
final class Site_Orders_Seller {
	private const ROW_PREFIX = 'act-';

	private const BULK_PREFIX = 'bulk-';

	private const UNIT_PREFIX = 'unit-';

	/**
	 * Longest `short_label` the engine takes.
	 */
	private const SHORT_LABEL_LENGTH = 32;

	/**
	 * @var Seller_Action_Runner
	 */
	private $runner;

	/**
	 * @since 2.1.27
	 *
	 * @param Seller_Action_Runner $runner The seller actions.
	 */
	public function __construct( Seller_Action_Runner $runner ) {
		$this->runner = $runner;
	}

	/**
	 * Tells whether an engine action id is one of a seller action.
	 *
	 * @since 2.1.27
	 *
	 * @param string $engine_id Action id of a request.
	 * @return bool
	 */
	public function handles( string $engine_id ): bool {
		return null !== $this->action_of( $engine_id );
	}

	/**
	 * Tells whether the table has bulk actions, so its rows can be ticked.
	 *
	 * @since 2.1.27
	 *
	 * @return bool
	 */
	public function has_bulk(): bool {
		return [] !== $this->runner->actions_for( Seller_Action::BULK );
	}

	/**
	 * Declares the row actions.
	 *
	 * @since 2.1.27
	 *
	 * @param string $capability Capability the actions ask for.
	 * @return array<int,array<string,mixed>>
	 */
	public function row_definitions( string $capability ): array {
		return array_map(
			function ( Seller_Action $action ) use ( $capability ): array {
				return $this->definition( $action, self::ROW_PREFIX, $capability ) + [ 'icon' => $action->icon() ];
			},
			$this->runner->actions_for( Seller_Action::ORDER )
		);
	}

	/**
	 * Declares the bulk actions.
	 *
	 * @since 2.1.27
	 *
	 * @param string $capability Capability the actions ask for.
	 * @return array<int,array<string,mixed>>
	 */
	public function bulk_definitions( string $capability ): array {
		return array_map(
			function ( Seller_Action $action ) use ( $capability ): array {
				return $this->definition( $action, self::BULK_PREFIX, $capability );
			},
			$this->runner->actions_for( Seller_Action::BULK )
		);
	}

	/**
	 * Tells whether an engine action id is one of an action on one unit.
	 *
	 * @since 2.1.28
	 *
	 * @param string $engine_id Action id of a request.
	 * @return bool
	 */
	public function handles_unit( string $engine_id ): bool {
		return 0 === strpos( $engine_id, self::UNIT_PREFIX ) && $this->handles( $engine_id );
	}

	/**
	 * Declares the actions of a unit's row.
	 *
	 * @since 2.1.28
	 *
	 * @param string $capability Capability the actions ask for.
	 * @return array<int,array<string,mixed>>
	 */
	public function unit_definitions( string $capability ): array {
		return array_map(
			function ( Seller_Action $action ) use ( $capability ): array {
				return $this->definition( $action, self::UNIT_PREFIX, $capability ) + [ 'icon' => $action->icon() ];
			},
			$this->runner->actions_for( Seller_Action::UNIT )
		);
	}

	/**
	 * The states of the actions on each unit the marketplace lists for an order, by the rule of the order screen.
	 *
	 * @since 2.1.28
	 *
	 * @param Account   $account  Account of the order.
	 * @param \WC_Order $order    Order.
	 * @param bool      $editable Whether the user may edit the order.
	 * @return array<string,array<int,array{id:string,enabled:bool,reason:string}>> Keyed by `#` and the unit id.
	 */
	public function unit_states( Account $account, \WC_Order $order, bool $editable ): array {
		$actions = $this->runner->actions_for( Seller_Action::UNIT );
		$states  = [];

		foreach ( [] === $actions ? [] : $this->runner->units( $order ) as $unit ) {
			foreach ( $actions as $action ) {
				if ( ! $unit->has_action( $action->id() ) ) {
					continue;
				}

				$reason = $editable
					? $this->runner->unit_reason( $account, $action, $order, $unit )
					: __( 'Нет права изменять этот заказ.', 'woodev-plugin-framework' );

				$states[ '#' . $unit->id() ][] = [
					'id'      => self::UNIT_PREFIX . $action->id(),
					'enabled' => '' === $reason,
					'reason'  => mb_substr( $reason, 0, Records_Definition::MAX_STRING_LENGTH ),
				];
			}
		}

		return $states;
	}

	/**
	 * The states of the actions on an order's row.
	 *
	 * @since 2.1.27
	 *
	 * @param Account   $account Account of the order.
	 * @param \WC_Order $order   Order.
	 * @return array<int,array{id:string,enabled:bool,reason:string}>
	 */
	public function row_states( Account $account, \WC_Order $order ): array {
		$states = [];

		foreach ( $this->runner->order_states( $account, $order ) as $action_id => $state ) {
			$states[] = [ 'id' => self::ROW_PREFIX . $action_id ] + $state;
		}

		return $states;
	}

	/**
	 * The plan of an action over the asked orders. Calls no marketplace.
	 *
	 * @since 2.1.27
	 *
	 * @param Account             $account   Account.
	 * @param string              $engine_id Action id.
	 * @param \WC_Order[]         $orders    Orders of the account.
	 * @param array<string,mixed> $payload   Validated fields.
	 * @param string              $unit_id   Only this unit of the order (since 2.1.28); empty for every unit.
	 * @return array<string,mixed>
	 * @throws Action_State_Exception When the account or the licence does not allow the action.
	 * @throws Action_Input_Exception When the input is refused.
	 */
	public function preview( Account $account, string $engine_id, array $orders, array $payload, string $unit_id = '' ): array {
		try {
			$plan = $this->runner->preview( $account, $this->action_of( $engine_id ), $orders, $payload, $unit_id );
		} catch ( Seller_Action_Refused $e ) {
			throw new Action_State_Exception( $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return [
			'requested' => $plan->requested(),
			'eligible'  => count( $plan->targets() ),
			'skipped'   => count( $plan->skipped() ),
			'groups'    => $this->groups( [], $plan->skipped() ),
			'messages'  => [ 'summary' => self::counted_sentence() ],
		];
	}

	/**
	 * Applies an action to the asked orders.
	 *
	 * @since 2.1.27
	 *
	 * @param Account             $account   Account.
	 * @param string              $engine_id Action id.
	 * @param \WC_Order[]         $orders    Orders of the account.
	 * @param array<string,mixed> $payload   Validated fields.
	 * @param string              $unit_id   Only this unit of the order (since 2.1.28); empty for every unit.
	 * @return array<string,mixed> The engine's apply answer, without rows.
	 * @throws Action_State_Exception When nothing was done: the account, the licence or the marketplace does not allow it.
	 * @throws Action_Input_Exception When the input is refused.
	 */
	public function apply( Account $account, string $engine_id, array $orders, array $payload, string $unit_id = '' ): array {
		try {
			$report = $this->runner->run( $account, $this->action_of( $engine_id ), $orders, $payload, $unit_id );
		} catch ( Seller_Action_Refused $e ) {
			throw new Action_State_Exception( $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return [
			'requested' => $report->requested(),
			'accepted'  => count( $report->done() ),
			'skipped'   => count( $report->skipped() ),
			'failed'    => count( $report->failed() ),
			'groups'    => $this->groups( $report->failed(), $report->skipped() ),
			'messages'  => [ 'summary' => self::counted_sentence() ],
		];
	}

	/**
	 * The choices of one list field of a dialog.
	 *
	 * @since 2.1.27
	 *
	 * @param Account $account   Account.
	 * @param string  $engine_id Action id.
	 * @param string  $field     Field id.
	 * @param string  $search    What the merchant typed.
	 * @return array<int,array{value:string,label:string}>
	 */
	public function field_options( Account $account, string $engine_id, string $field, string $search ): array {
		return $this->runner->field_options( $account, $this->action_of( $engine_id ), $field, $search );
	}

	/**
	 * @param string $engine_id Action id.
	 * @return Seller_Action|null The action the id names, when it is offered in that place.
	 */
	private function action_of( string $engine_id ): ?Seller_Action {
		foreach ( [
			self::ROW_PREFIX  => Seller_Action::ORDER,
			self::BULK_PREFIX => Seller_Action::BULK,
			self::UNIT_PREFIX => Seller_Action::UNIT,
		] as $prefix => $target ) {
			if ( 0 !== strpos( $engine_id, $prefix ) ) {
				continue;
			}

			$action = $this->runner->action( substr( $engine_id, strlen( $prefix ) ) );

			return null !== $action && $action->is_for( $target ) ? $action : null;
		}

		return null;
	}

	/**
	 * One declared action in the engine's shape.
	 *
	 * @param Seller_Action $action     Action.
	 * @param string        $prefix     Id prefix of the place.
	 * @param string        $capability Capability.
	 * @return array<string,mixed>
	 */
	private function definition( Seller_Action $action, string $prefix, string $capability ): array {
		$short = $action->short_label();

		return [
			'id'             => $prefix . $action->id(),
			'label'          => $action->label(),
			'short_label'    => mb_strlen( $short ) > self::SHORT_LABEL_LENGTH ? '' : $short,
			'tone'           => $action->tone(),
			'effect'         => 'mutate',
			'interaction'    => 'dialog',
			'scope'          => 'selection',
			'capability'     => $capability,
			'scope_sentence' => mb_substr( $action->confirmation(), 0, Records_Definition::MAX_STRING_LENGTH ),
			'confirm_label'  => $action->label(),
			'fields'         => array_map( [ self::class, 'field' ], $action->fields() ),
		];
	}

	/**
	 * One declared field in the engine's shape. A code is a line of text there, marked as a scanned code so that it keeps the
	 * group separator.
	 *
	 * @param Seller_Action_Field $field Field.
	 * @return array<string,mixed>
	 */
	private static function field( Seller_Action_Field $field ): array {
		$types = [
			Seller_Action_Field::TEXT     => 'text',
			Seller_Action_Field::CODE     => 'text',
			Seller_Action_Field::TEXTAREA => 'textarea',
			Seller_Action_Field::SELECT   => 'select',
		];

		$definition = [
			'id'       => $field->id(),
			'label'    => $field->label(),
			'type'     => $types[ $field->type() ],
			'required' => $field->is_required(),
			'help'     => $field->help(),
		];

		if ( Seller_Action_Field::CODE === $field->type() ) {
			$definition['scanned_code'] = true;
		}

		if ( Seller_Action_Field::SELECT === $field->type() ) {
			$definition['options']        = $field->options();
			$definition['options_source'] = $field->has_options_source();
		}

		return $definition;
	}

	/**
	 * The reasons of a plan or a report, the refusals first: one group per sentence, with the first few goods it concerns. The
	 * engine takes {@see Action_Response::MAX_GROUPS} groups, so sentences beyond that share the last one.
	 *
	 * @param \Woodev\Framework\Marketplace\Admin\Order\Seller_Unit_Result[] $failed  Refused units.
	 * @param array<int,array{order:\WC_Order,unit:string,reason:string}>    $skipped Units never sent.
	 * @return array<int,array<string,mixed>>
	 */
	private function groups( array $failed, array $skipped ): array {
		$entries = [];

		foreach ( $failed as $result ) {
			$entries[] = [ 'failed', $result->reason(), self::example( $result->target()->order(), $result->target()->unit()->label() ) ];
		}

		foreach ( $skipped as $skip ) {
			$entries[] = [ 'skipped', $skip['reason'], self::example( $skip['order'], $skip['unit'] ) ];
		}

		$groups = [];

		foreach ( $entries as list( $kind, $reason, $example ) ) {
			$reason = mb_substr( $reason, 0, Records_Definition::MAX_STRING_LENGTH );
			$key    = $kind . '|' . $reason;

			if ( ! isset( $groups[ $key ] ) && count( $groups ) >= Action_Response::MAX_GROUPS - 1 ) {
				$reason = __( 'Другие причины: выберите меньше заказов, чтобы увидеть каждую.', 'woodev-plugin-framework' );
				$key    = 'rest';
			}

			$groups[ $key ] = $groups[ $key ] ?? [
				'kind'     => $kind,
				'reason'   => $reason,
				'count'    => 0,
				'examples' => [],
			];

			++$groups[ $key ]['count'];

			if ( count( $groups[ $key ]['examples'] ) < Action_Response::MAX_EXAMPLES ) {
				$groups[ $key ]['examples'][] = $example;
			}
		}

		return array_values( $groups );
	}

	/**
	 * «№ 123: Футболка, M» — the order the merchant sees and the goods.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $unit  Goods; empty for the whole order.
	 * @return string
	 */
	private static function example( \WC_Order $order, string $unit ): string {
		$text = '№ ' . $order->get_order_number() . ( '' === $unit ? '' : ': ' . $unit );

		return mb_substr( $text, 0, Action_Response::MAX_SHORT_LENGTH );
	}

	/**
	 * @return string What the numbers of a plan and a report count.
	 */
	private static function counted_sentence(): string {
		return __( 'Числа ниже — товары в выбранных заказах.', 'woodev-plugin-framework' );
	}
}
