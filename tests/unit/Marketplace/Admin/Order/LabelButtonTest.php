<?php
/**
 * Unit tests for the «Этикетка» button of a marketplace order.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Order;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Admin\Order\Label_Button;
use Woodev\Framework\Marketplace\Admin\Shared\Label_Availability;
use Woodev\Framework\Marketplace\Admin\Shared\Label_Readiness;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Action_Gate;
use Woodev\Framework\Marketplace\Admin\Shared\Posting_Allowed_Actions;
use Woodev\Tests\Unit\TestCase;

/**
 * The button carries what the script needs to call the «Заказы» table's own routes — the table, the account, the row id and the REST nonce —
 * and is off, with the reason as its tooltip, for a user who may not edit the order, for a finished posting and for a posting the adapter
 * says is not ready, for a posting the marketplace does not allow a label for, and for an account whose key cannot write. An adapter that
 * says nothing keeps it on. An order without a posting has none.
 */
final class LabelButtonTest extends TestCase {
	/**
	 * Whether the user may edit the order.
	 *
	 * @var bool
	 */
	private $can = true;

	/**
	 * Scripts enqueued, by handle.
	 *
	 * @var array<string,string>
	 */
	private $scripts = [];

	/**
	 * Boots the stubs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->can     = true;
		$this->scripts = [];

		Functions\when( 'current_user_can' )->alias(
			function () {
				return $this->can;
			}
		);
		Functions\when( 'wp_create_nonce' )->justReturn( 'rest-nonce' );
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle, $src ) {
				$this->scripts[ $handle ] = $src;
			}
		);
	}

	public function test_it_names_the_table_the_account_the_row_and_the_rest_nonce(): void {
		$html = $this->button( $this->posting( 3 ) )->button( $this->order( 700 ) );

		$this->assertStringContainsString( 'data-url="https://example.test/wp-json/woodev/v1/admin/records/fake/orders"', $html );
		$this->assertStringContainsString( 'data-account="3"', $html );
		$this->assertStringContainsString( 'data-row="3:700"', $html );
		$this->assertStringContainsString( 'data-nonce="rest-nonce"', $html );
		$this->assertStringContainsString( '>Этикетка</button>', $html );
		$this->assertStringContainsString( 'aria-disabled="false"', $html );
		$this->assertStringStartsWith( 'https://example.test/assets/js/admin/order-label', $this->scripts['woodev-marketplace-order-label'] );
	}

	public function test_a_user_who_may_not_edit_the_order_gets_a_button_that_is_off_and_says_why(): void {
		$this->can = false;

		$html = $this->button( $this->posting( 3 ) )->button( $this->order( 700 ) );

		$this->assertStringContainsString( 'aria-disabled="true"', $html );
		$this->assertStringContainsString( 'title="Нет права изменять этот заказ."', $html );
	}

	public function test_a_finished_posting_has_the_button_off_for_every_adapter(): void {
		$html = $this->button( $this->posting( 3, 'delivered', true ) )->button( $this->order( 700 ) );

		$this->assertStringContainsString( 'aria-disabled="true"', $html );
		$this->assertStringContainsString( 'title="Отправление завершено — этикетка не нужна."', $html );
	}

	public function test_a_posting_the_adapter_says_is_not_ready_has_the_button_off_with_its_reason(): void {
		$readiness = $this->readiness( [ 'awaiting_packaging' => 'Этикетка появится, когда заказ будет собран.' ] );

		$off = $this->button( $this->posting( 3, 'awaiting_packaging' ), $readiness )->button( $this->order( 700 ) );
		$on  = $this->button( $this->posting( 3, 'awaiting_deliver' ), $readiness )->button( $this->order( 700 ) );

		$this->assertStringContainsString( 'aria-disabled="true"', $off );
		$this->assertStringContainsString( 'title="Этикетка появится, когда заказ будет собран."', $off );
		$this->assertStringContainsString( 'aria-disabled="false"', $on );
	}

	public function test_an_adapter_that_says_nothing_keeps_the_button_on_for_any_open_status(): void {
		$html = $this->button( $this->posting( 3, 'awaiting_packaging' ) )->button( $this->order( 700 ) );

		$this->assertStringContainsString( 'aria-disabled="false"', $html );
		$this->assertStringNotContainsString( 'title=', $html );
	}

	public function test_a_posting_the_marketplace_does_not_allow_a_label_for_has_the_button_off_with_a_sentence(): void {
		$allowed = $this->allowed( [ 'cancel' ] );

		$off = $this->button( $this->posting( 3 ), null, $allowed )->button( $this->order( 700 ) );

		$this->assertStringContainsString( 'aria-disabled="true"', $off );
		$this->assertStringContainsString( 'title="Маркетплейс ещё не выдал этикетку для этого отправления — она станет доступна, когда отправление будет собрано."', $off );
	}

	public function test_a_posting_the_marketplace_allows_a_label_for_or_has_not_said_for_keeps_the_button_on(): void {
		$this->assertStringContainsString( 'aria-disabled="false"', $this->button( $this->posting( 3 ), null, $this->allowed( [ 'label', 'cancel' ] ) )->button( $this->order( 700 ) ) );
		$this->assertStringContainsString( 'aria-disabled="false"', $this->button( $this->posting( 3 ), null, $this->allowed( null ) )->button( $this->order( 700 ) ) );
	}

	public function test_an_account_whose_key_cannot_write_has_the_button_off_with_the_sentence(): void {
		$html = $this->button( $this->posting( 3 ), null, null, static function (): bool {
			return false;
		} )->button( $this->order( 700 ) );

		$this->assertStringContainsString( 'aria-disabled="true"', $html );
		$this->assertStringContainsString( 'title="Ключ этого аккаунта не позволяет менять данные на маркетплейсе', $html );
	}

	public function test_an_account_that_is_gone_is_not_judged_here_the_server_refuses_it_later(): void {
		$button = new Label_Button(
			'https://example.test/wp-json/woodev/v1/admin/records/fake/orders',
			function (): array {
				return $this->posting( 3 );
			},
			new Posting_Action_Gate( new Label_Availability( null ), null, '__return_false' ),
			static function (): ?Account {
				return null;
			},
			'https://example.test/assets/',
			'1.0'
		);

		$this->assertStringContainsString( 'aria-disabled="false"', $button->button( $this->order( 700 ) ) );
	}

	public function test_a_stored_label_keeps_the_button_on_for_a_finished_posting_a_key_that_cannot_write_and_an_unlisted_label(): void {
		$stored = static function ( int $account_id, string $key ): bool {
			return 3 === $account_id && 'P-1' === $key;
		};
		$cannot = static function (): bool {
			return false;
		};

		$finished = $this->button( $this->posting( 3, 'delivered', true ), null, null, null, $stored )->button( $this->order( 700 ) );
		$read     = $this->button( $this->posting( 3 ), null, null, $cannot, $stored )->button( $this->order( 700 ) );
		$unlisted = $this->button( $this->posting( 3 ), null, $this->allowed( [ 'cancel' ] ), null, $stored )->button( $this->order( 700 ) );

		foreach ( [ $finished, $read, $unlisted ] as $html ) {
			$this->assertStringContainsString( 'aria-disabled="false"', $html );
			$this->assertStringNotContainsString( 'title=', $html );
		}
	}

	public function test_a_label_that_is_not_stored_is_gated_as_before_and_the_edit_right_still_comes_first(): void {
		$none   = static function (): bool {
			return false;
		};
		$cannot = static function (): bool {
			return false;
		};

		$this->assertStringContainsString( 'aria-disabled="true"', $this->button( $this->posting( 3, 'delivered', true ), null, null, null, $none )->button( $this->order( 700 ) ) );
		$this->assertStringContainsString( 'aria-disabled="true"', $this->button( $this->posting( 3 ), null, null, $cannot, $none )->button( $this->order( 700 ) ) );

		$this->can = false;
		$stored    = static function (): bool {
			return true;
		};

		$this->assertStringContainsString( 'title="Нет права изменять этот заказ."', $this->button( $this->posting( 3 ), null, null, null, $stored )->button( $this->order( 700 ) ) );
	}

	public function test_an_adapter_with_no_label_state_gates_every_posting_as_before(): void {
		$html = $this->button( $this->posting( 3, 'delivered', true ) )->button( $this->order( 700 ) );

		$this->assertStringContainsString( 'aria-disabled="true"', $html );
	}

	public function test_an_order_without_a_posting_has_no_button_and_loads_no_script(): void {
		$this->assertSame( '', $this->button( null )->button( $this->order( 700 ) ) );
		$this->assertSame( [], $this->scripts );
	}

	/**
	 * Builds the button over a registry that answers one posting for every order.
	 *
	 * @param array<string,mixed>|null     $posting    The posting; null for an order with none.
	 * @param Label_Readiness|null         $readiness  The adapter's readiness.
	 * @param Posting_Allowed_Actions|null $allowed    The adapter's allowed actions.
	 * @param callable|null                $write_gate The plugin's write gate.
	 * @param callable|null                $label_stored `function ( int $account_id, string $key ): bool`, whether a label is stored.
	 * @return Label_Button
	 */
	private function button( ?array $posting, ?Label_Readiness $readiness = null, ?Posting_Allowed_Actions $allowed = null, ?callable $write_gate = null, ?callable $label_stored = null ): Label_Button {
		return new Label_Button(
			'https://example.test/wp-json/woodev/v1/admin/records/fake/orders',
			static function () use ( $posting ): ?array {
				return $posting;
			},
			new Posting_Action_Gate( new Label_Availability( $readiness ), $allowed, $write_gate ),
			static function ( int $account_id ): Account {
				return new Account( $account_id, 'fake', 'Магазин', true );
			},
			'https://example.test/assets/',
			'1.0',
			$label_stored
		);
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

	/**
	 * @param int    $account_id  Account.
	 * @param string $status      The marketplace's status.
	 * @param bool   $is_terminal Whether the posting is finished.
	 * @return array{account_id:int,status_raw:string,is_terminal:bool}
	 */
	private function posting( int $account_id, string $status = 'awaiting_deliver', bool $is_terminal = false ): array {
		return [
			'account_id'   => $account_id,
			'external_key' => 'P-1',
			'status_raw'   => $status,
			'is_terminal'  => $is_terminal,
		];
	}

	/**
	 * An adapter's readiness that refuses the statuses it names.
	 *
	 * @param array<string,string> $reasons Reason by status.
	 * @return Label_Readiness
	 */
	private function readiness( array $reasons ): Label_Readiness {
		$readiness = Mockery::mock( Label_Readiness::class );
		$readiness->shouldReceive( 'label_readiness' )->andReturnUsing(
			static function ( string $status ) use ( $reasons ): ?string {
				return $reasons[ $status ] ?? null;
			}
		);

		return $readiness;
	}

	/**
	 * Builds an order double.
	 *
	 * @param int $id Order id.
	 * @return \WC_Order
	 */
	private function order( int $id ): \WC_Order {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( $id );

		return $order;
	}
}
