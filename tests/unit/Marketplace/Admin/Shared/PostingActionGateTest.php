<?php
/**
 * Unit tests for the rule that says whether «Отменить» and «Этикетки» are on offer for a posting.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Shared;

use Mockery;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Shared\Label_Availability;
use Woodev\Framework\Marketplace\Admin\Shared\Label_Readiness;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Action_Gate;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Allowed_Actions;
use Woodev\Tests\Unit\TestCase;

/**
 * A write is on offer when the account can write and, for the posting, the marketplace allows it. An adapter that says nothing, or has
 * not said for this posting, leaves it on; an account with no write gate can always write.
 */
final class PostingActionGateTest extends TestCase {
	private const NOT_ALLOWED_CANCEL = 'Маркетплейс сейчас не разрешает отменить это отправление — например, оно уже передано в доставку.';
	private const NOT_RELEASED_LABEL = 'Маркетплейс ещё не выдал этикетку для этого отправления — она станет доступна, когда отправление будет собрано.';

	public function test_without_any_contract_nothing_is_refused(): void {
		$gate = new Posting_Action_Gate( new Label_Availability( null ) );

		$this->assertSame( '', $gate->write_refusal( $this->account() ) );
		$this->assertSame( '', $gate->cancel_reason( $this->order() ) );
		$this->assertSame( '', $gate->label_reason( $this->order(), 'awaiting_packaging', false ) );
	}

	public function test_a_cancellation_the_marketplace_lists_is_on_and_one_it_does_not_list_is_off_with_the_sentence(): void {
		$on  = new Posting_Action_Gate( new Label_Availability( null ), $this->allowed( [ 'cancel' ] ) );
		$off = new Posting_Action_Gate( new Label_Availability( null ), $this->allowed( [ 'label' ] ) );
		$none = new Posting_Action_Gate( new Label_Availability( null ), $this->allowed( [] ) );

		$this->assertSame( '', $on->cancel_reason( $this->order() ) );
		$this->assertSame( self::NOT_ALLOWED_CANCEL, $off->cancel_reason( $this->order() ) );
		$this->assertSame( self::NOT_ALLOWED_CANCEL, $none->cancel_reason( $this->order() ), 'An empty list is an answer: nothing is allowed.' );
	}

	public function test_a_label_the_marketplace_lists_is_on_and_one_it_does_not_list_is_off_with_the_sentence(): void {
		$on  = new Posting_Action_Gate( new Label_Availability( null ), $this->allowed( [ 'label' ] ) );
		$off = new Posting_Action_Gate( new Label_Availability( null ), $this->allowed( [ 'cancel' ] ) );

		$this->assertSame( '', $on->label_reason( $this->order(), 'awaiting_deliver', false ) );
		$this->assertSame( self::NOT_RELEASED_LABEL, $off->label_reason( $this->order(), 'awaiting_deliver', false ) );
	}

	public function test_a_posting_the_marketplace_has_not_said_for_is_left_on(): void {
		$gate = new Posting_Action_Gate( new Label_Availability( null ), $this->allowed( null ) );

		$this->assertSame( '', $gate->cancel_reason( $this->order() ) );
		$this->assertSame( '', $gate->label_reason( $this->order(), 'awaiting_deliver', false ) );
	}

	public function test_the_label_rule_keeps_its_own_reasons_first_and_asks_the_allowed_list_only_for_an_open_ready_posting(): void {
		$allowed = Mockery::mock( Posting_Allowed_Actions::class );
		$allowed->shouldNotReceive( 'allowed_posting_actions' );
		$readiness = Mockery::mock( Label_Readiness::class );
		$readiness->shouldReceive( 'label_readiness' )->with( 'awaiting_packaging' )->andReturn( 'Сначала соберите заказ.' );

		$gate = new Posting_Action_Gate( new Label_Availability( $readiness ), $allowed );

		$this->assertSame( 'Отправление завершено — этикетка не нужна.', $gate->label_reason( $this->order(), 'delivered', true ) );
		$this->assertSame( 'Сначала соберите заказ.', $gate->label_reason( $this->order(), 'awaiting_packaging', false ) );
	}

	public function test_an_account_the_write_gate_refuses_gets_the_sentence_and_one_it_allows_gets_none(): void {
		$asked = [];
		$gate  = new Posting_Action_Gate(
			new Label_Availability( null ),
			null,
			static function ( Account $account ) use ( &$asked ): bool {
				$asked[] = $account->id();

				return 7 !== $account->id();
			}
		);

		$this->assertSame( '', $gate->write_refusal( $this->account( 3 ) ) );
		$this->assertStringContainsString( 'выдан только для чтения', $gate->write_refusal( $this->account( 7 ) ) );
		$this->assertSame( [ 3, 7 ], $asked );
	}

	public function test_a_gate_that_answers_false_gets_the_generic_sentence(): void {
		$gate = new Posting_Action_Gate( new Label_Availability( null ), null, '__return_false' );

		$this->assertStringContainsString( 'выдан только для чтения', $gate->write_refusal( $this->account() ) );
	}

	public function test_a_gate_that_answers_with_a_string_shows_the_plugins_own_reason(): void {
		$reason = 'Аккаунт ещё не прошёл проверку после переноса.';
		$gate   = new Posting_Action_Gate(
			new Label_Availability( null ),
			null,
			static function () use ( $reason ): string {
				return $reason;
			}
		);

		$this->assertSame( $reason, $gate->write_refusal( $this->account() ) );
	}

	public function test_an_empty_string_answer_falls_back_to_the_generic_sentence(): void {
		$gate = new Posting_Action_Gate( new Label_Availability( null ), null, static function (): string {
			return '';
		} );

		$this->assertStringContainsString( 'выдан только для чтения', $gate->write_refusal( $this->account() ) );
	}

	public function test_a_gate_that_answers_true_allows_the_write(): void {
		$gate = new Posting_Action_Gate( new Label_Availability( null ), null, '__return_true' );

		$this->assertSame( '', $gate->write_refusal( $this->account() ) );
	}

	public function test_only_a_gate_that_answers_true_lets_the_account_write(): void {
		$gate = new Posting_Action_Gate(
			new Label_Availability( null ),
			null,
			static function (): int {
				return 1; // Not `true`: the plugin's callable is documented to return a bool, anything else is a refusal.
			}
		);

		$this->assertNotSame( '', $gate->write_refusal( $this->account() ) );
	}

	public function test_for_adapter_wires_the_contracts_the_adapter_implements(): void {
		$both = Mockery::mock( Label_Readiness::class, Posting_Allowed_Actions::class );
		$both->shouldReceive( 'label_readiness' )->andReturn( 'Сначала соберите заказ.' );
		$both->shouldReceive( 'allowed_posting_actions' )->andReturn( [] );
		$gate = Posting_Action_Gate::for_adapter( $both, '__return_false' );

		$this->assertSame( 'Сначала соберите заказ.', $gate->label_reason( $this->order(), 'x', false ) );
		$this->assertSame( self::NOT_ALLOWED_CANCEL, $gate->cancel_reason( $this->order() ) );
		$this->assertNotSame( '', $gate->write_refusal( $this->account() ) );

		$bare = Posting_Action_Gate::for_adapter( new \stdClass() );

		$this->assertSame( '', $bare->label_reason( $this->order(), 'x', false ) );
		$this->assertSame( '', $bare->cancel_reason( $this->order() ) );
		$this->assertSame( '', $bare->write_refusal( $this->account() ) );
	}

	/**
	 * @param int $id Account id.
	 * @return Account
	 */
	private function account( int $id = 1 ): Account {
		return new Account( $id, 'fake', 'Магазин', true );
	}

	/**
	 * @return \WC_Order
	 */
	private function order(): \WC_Order {
		return Mockery::mock( '\WC_Order' );
	}

	/**
	 * An adapter's allowed actions that answer one list for every order.
	 *
	 * @param string[]|null $list Allowed actions; null for «not said».
	 * @return Posting_Allowed_Actions
	 */
	private function allowed( ?array $list ): Posting_Allowed_Actions {
		$allowed = Mockery::mock( Posting_Allowed_Actions::class );
		$allowed->shouldReceive( 'allowed_posting_actions' )->andReturn( $list );

		return $allowed;
	}
}
