<?php
/**
 * Unit tests for the «Этикетка» button of a marketplace order.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Order;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Marketplace\Admin\Order\Label_Button;
use Woodev\Tests\Unit\TestCase;

/**
 * The button carries what the script needs to call the «Заказы» table's own routes — the table, the account, the row id and the REST nonce —
 * and is off, with the table's sentence, for a user who may not edit the order. An order without a posting has none.
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
		$html = $this->button( 3 )->button( $this->order( 700 ) );

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

		$html = $this->button( 3 )->button( $this->order( 700 ) );

		$this->assertStringContainsString( 'aria-disabled="true"', $html );
		$this->assertStringContainsString( 'title="Нет права изменять этот заказ."', $html );
	}

	public function test_an_order_without_a_posting_has_no_button_and_loads_no_script(): void {
		$this->assertSame( '', $this->button( 0 )->button( $this->order( 700 ) ) );
		$this->assertSame( [], $this->scripts );
	}

	/**
	 * Builds the button over a registry that keeps every posting under one account.
	 *
	 * @param int $account_id The account; 0 for an order with no posting.
	 * @return Label_Button
	 */
	private function button( int $account_id ): Label_Button {
		return new Label_Button(
			'https://example.test/wp-json/woodev/v1/admin/records/fake/orders',
			static function () use ( $account_id ): int {
				return $account_id;
			},
			'https://example.test/assets/',
			'1.0'
		);
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
