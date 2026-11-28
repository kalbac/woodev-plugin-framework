<?php
/**
 * A marketplace whose seller delivers some orders, scripted.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Support;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Order\Carrier_Actions;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action_Call;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Action_Field;
use Woodev\Framework\Marketplace\Admin\Order\Seller_Unit_Result;

/**
 * {@see Fake_Seller_Adapter} with the actions of an order the seller delivers: «Передать в доставку» (status `confirm`), then
 * «Вручён» and «Отказ при получении» (status `deliver`, optional code). Units listed in `$needs_code` are refused for the lack of
 * the buyer's code.
 */
final class Fake_Carrier_Adapter extends Fake_Seller_Adapter implements Carrier_Actions {
	/**
	 * Unit ids the marketplace refuses until the buyer's code is typed.
	 *
	 * @var string[]
	 */
	public $needs_code = [];

	/**
	 * The adapter's table of carrier news.
	 *
	 * @var array<string,string>
	 */
	public $table = [
		Carrier_Actions::HANDED_OVER => 'deliver',
		Carrier_Actions::DELIVERED   => 'receive',
		Carrier_Actions::RETURNED    => 'reject',
	];

	/**
	 * @return array<string,string>
	 */
	public function carrier_actions(): array {
		return $this->table;
	}

	/**
	 * @return Seller_Action[]
	 */
	public function seller_actions(): array {
		$code = [ new Seller_Action_Field( 'code', 'Код подтверждения от покупателя', Seller_Action_Field::CODE ) ];

		return [
			new Seller_Action( 'deliver', 'Передать в доставку', [ Seller_Action::ORDER ], [ 'statuses' => [ 'confirm' ] ] ),
			new Seller_Action(
				'receive',
				'Вручён',
				[ Seller_Action::ORDER ],
				[
					'statuses' => [ 'deliver' ],
					'fields'   => $code,
				]
			),
			new Seller_Action(
				'reject',
				'Отказ при получении',
				[ Seller_Action::ORDER ],
				[
					'statuses' => [ 'deliver' ],
					'fields'   => $code,
				]
			),
		];
	}

	/**
	 * @param Account            $account Account.
	 * @param Seller_Action_Call $call    Call.
	 * @return Seller_Unit_Result[]
	 * @throws \Throwable The scripted failure.
	 */
	public function run_seller_action( Account $account, Seller_Action_Call $call ): array {
		$answers = parent::run_seller_action( $account, $call );

		foreach ( $answers as $index => $answer ) {
			if ( in_array( $answer->target()->unit()->id(), $this->needs_code, true ) ) {
				$answers[ $index ] = Seller_Unit_Result::code_required( $answer->target(), 'Нужен код подтверждения.' );
			}
		}

		return $answers;
	}

	/**
	 * The action ids of every call, in order.
	 *
	 * @return string[]
	 */
	public function sent_actions(): array {
		return array_map(
			static function ( Seller_Action_Call $call ): string {
				return $call->action_id();
			},
			$this->calls
		);
	}
}
