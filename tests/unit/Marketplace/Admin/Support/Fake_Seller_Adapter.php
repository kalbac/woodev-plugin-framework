<?php
/**
 * A marketplace's seller actions, scripted.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Support;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Order\Posting_Unit;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action_Call;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action_Field;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action_Options;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action_Target;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Actions;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Unit_Result;

/**
 * Reads the units of an order from its `_fake_units` meta; records every call it was given.
 *
 * Its actions: «Принять в работу» (order and bulk, status `new`), «Вручён» (unit and order, status `deliver`, optional code),
 * «Код маркировки» (unit only, status `confirm`, required code), «Добавить в поставку» (bulk, a list from the adapter).
 */
class Fake_Seller_Adapter implements Seller_Actions, Seller_Action_Options {
	public const META_UNITS = '_fake_units';

	/**
	 * Calls of {@see self::run_seller_action()}.
	 *
	 * @var Seller_Action_Call[]
	 */
	public $calls = [];

	/**
	 * Calls of {@see self::check_seller_input()}.
	 *
	 * @var Seller_Action_Call[]
	 */
	public $checked = [];

	/**
	 * Unit id => the refusal the marketplace answers with.
	 *
	 * @var array<string,string>
	 */
	public $refusals = [];

	/**
	 * Unit ids the marketplace does not mention in its answer.
	 *
	 * @var string[]
	 */
	public $unanswered = [];

	/**
	 * Unit id => the stored objection to every action.
	 *
	 * @var array<string,string>
	 */
	public $objections = [];

	/**
	 * Thrown by the run instead of an answer.
	 *
	 * @var \Throwable|null
	 */
	public $failure;

	/**
	 * Errors the input check answers with.
	 *
	 * @var array<string,string>
	 */
	public $input_errors = [];

	/**
	 * Order ids of every refresh queued.
	 *
	 * @var int[]
	 */
	public $refreshed = [];

	/**
	 * @return Seller_Action[]
	 */
	public function seller_actions(): array {
		return [
			new Seller_Action( 'confirm', 'Принять в работу', [ Seller_Action::ORDER, Seller_Action::BULK ], [ 'statuses' => [ 'new' ], 'tone' => 'primary', 'confirmation' => 'После этого покупатель не сможет отменить заказ.' ] ),
			new Seller_Action(
				'receive',
				'Вручён',
				[ Seller_Action::UNIT, Seller_Action::ORDER ],
				[
					'statuses' => [ 'deliver' ],
					'fields'   => [ new Seller_Action_Field( 'code', 'Код подтверждения от покупателя', Seller_Action_Field::CODE ) ],
				]
			),
			new Seller_Action(
				'marking',
				'Код маркировки',
				[ Seller_Action::UNIT ],
				[
					'statuses' => [ 'confirm' ],
					'fields'   => [ new Seller_Action_Field( 'sgtin', 'Код «Честный знак»', Seller_Action_Field::CODE, [ 'required' => true ] ) ],
				]
			),
			new Seller_Action(
				'supply',
				'Добавить в поставку',
				[ Seller_Action::BULK ],
				[
					'statuses' => [ 'new' ],
					'fields'   => [ new Seller_Action_Field( 'batch', 'Поставка', Seller_Action_Field::SELECT, [ 'required' => true, 'options_source' => true ] ) ],
				]
			),
		];
	}

	/**
	 * @param \WC_Order $order Order.
	 * @return Posting_Unit[]
	 */
	public function posting_units( \WC_Order $order ): array {
		return array_map(
			static function ( array $unit ): Posting_Unit {
				return new Posting_Unit( $unit[0], $unit[1], $unit[2], 'Статус ' . $unit[2], $unit[3] ?? null );
			},
			(array) ( $order->get_meta( self::META_UNITS ) ?: [] )
		);
	}

	/**
	 * @param \WC_Order    $order     Order.
	 * @param Posting_Unit $unit      Unit.
	 * @param string       $action_id Action.
	 * @return string
	 */
	public function seller_action_refusal( \WC_Order $order, Posting_Unit $unit, string $action_id ): string {
		return $this->objections[ $unit->id() ] ?? '';
	}

	/**
	 * @param Account            $account Account.
	 * @param Seller_Action_Call $call    Call.
	 * @return array<string,string>
	 */
	public function check_seller_input( Account $account, Seller_Action_Call $call ): array {
		$this->checked[] = $call;

		return $this->input_errors;
	}

	/**
	 * @param Account            $account Account.
	 * @param Seller_Action_Call $call    Call.
	 * @return Seller_Unit_Result[]
	 * @throws \Throwable The scripted failure.
	 */
	public function run_seller_action( Account $account, Seller_Action_Call $call ): array {
		$this->calls[] = $call;

		if ( null !== $this->failure ) {
			throw $this->failure;
		}

		$answers = [];

		foreach ( $call->targets() as $target ) {
			$id = $target->unit()->id();

			if ( ! in_array( $id, $this->unanswered, true ) ) {
				$answers[] = isset( $this->refusals[ $id ] ) ? Seller_Unit_Result::refused( $target, $this->refusals[ $id ] ) : Seller_Unit_Result::done( $target );
			}
		}

		return $answers;
	}

	/**
	 * @param Account     $account Account.
	 * @param \WC_Order[] $orders  Orders.
	 * @return void
	 */
	public function refresh_after_action( Account $account, array $orders ): void {
		foreach ( $orders as $order ) {
			$this->refreshed[] = $order->get_id();
		}
	}

	/**
	 * @param Account $account   Account.
	 * @param string  $action_id Action.
	 * @param string  $field_id  Field.
	 * @param string  $search    Search.
	 * @return array<int,array{value:string,label:string}>
	 */
	public function seller_field_options( Account $account, string $action_id, string $field_id, string $search ): array {
		return [
			[
				'value' => 'WB-1',
				'label' => 'Поставка от 26.11',
			],
		];
	}

	/**
	 * Names the units of a call, for assertions.
	 *
	 * @param Seller_Action_Call $call Call.
	 * @return string[]
	 */
	public static function unit_ids( Seller_Action_Call $call ): array {
		return array_map(
			static function ( Seller_Action_Target $target ): string {
				return $target->unit()->id();
			},
			$call->targets()
		);
	}
}
