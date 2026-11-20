<?php
/**
 * Unit tests for the read-only details box of a marketplace order.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Order;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Marketplace\Admin\Order\Cancel_Modal;
use Woodev\Framework\Marketplace\Admin\Order\Label_Button;
use Woodev\Framework\Marketplace\Admin\Order\Order_Canceller;
use Woodev\Framework\Marketplace\Admin\Order\Order_Details_Box;
use Woodev\Framework\Marketplace\Admin\Product\Screen_Names;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Tests\Unit\TestCase;

/**
 * The box shows the posting, both statuses, the dates, payment, delivery, the address and the amounts of the order, and the
 * marketplace's own actions as buttons: the cancellation of an adapter that can cancel and the label button of one that has labels. The
 * marketplace's status is a badge beside the posting number in the heading.
 *
 * Each test runs in its own process: the box formats dates with `wc_date_format()`, and a Brain Monkey stub of it would stay defined
 * for every later test — `LicensePureOperationsTest` asserts the path where that function is ABSENT, so a leaked stub fails it
 * depending on the run order (same as `BuyerTrackingTest`).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class OrderDetailsBoxTest extends TestCase {
	/**
	 * Marketplace status the registry answers.
	 *
	 * @var string
	 */
	private $remote_status = 'awaiting_deliver';

	/**
	 * Whether the user may edit orders.
	 *
	 * @var bool
	 */
	private $can = true;

	/**
	 * Boots the stubs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->remote_status = 'awaiting_deliver';
		$this->can           = true;

		Functions\when( 'current_user_can' )->alias(
			function () {
				return $this->can;
			}
		);
		Functions\when( 'wp_create_nonce' )->justReturn( 'n0nce' );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'wc_get_order_status_name' )->justReturn( 'В обработке' );
		Functions\when( 'wc_date_format' )->justReturn( 'd.m.Y' );
		Functions\when( 'wc_time_format' )->justReturn( 'H:i' );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wc_price' )->alias(
			static function ( $amount, $args ) {
				return '<bdi>' . number_format( (float) $amount, 2, '.', '' ) . ' ' . $args['currency'] . '</bdi>';
			}
		);
	}

	public function test_it_adds_one_box_on_top_of_the_normal_column(): void {
		$added = [];
		Functions\when( 'add_meta_box' )->alias(
			static function ( ...$args ) use ( &$added ) {
				$added[] = $args;
			}
		);

		$this->box( $this->adapter() )->add( $this->order( 700 ), 'shop_order' );

		$this->assertCount( 1, $added );
		$this->assertSame( 'woodev-mp-fake-order', $added[0][0] );
		$this->assertSame( 'shop_order', $added[0][3] );
		$this->assertSame( 'normal', $added[0][4] );
		$this->assertSame( 'high', $added[0][5] );
	}

	public function test_it_enqueues_its_stylesheet_and_wraps_the_box_for_it(): void {
		$styles = [];
		Functions\when( 'wp_enqueue_style' )->alias(
			static function ( $handle, $src ) use ( &$styles ) {
				$styles[ $handle ] = $src;
			}
		);

		$html = $this->render( $this->box( $this->adapter() ), $this->order( 700 ) );

		$this->assertStringContainsString( 'woodev-mp-details', $html );
		$this->assertStringStartsWith( 'https://example.test/assets/css/admin/order-details', $styles['woodev-marketplace-order-details'] );
	}

	public function test_it_shows_what_the_order_holds(): void {
		$html = $this->render( $this->box( $this->adapter() ), $this->order( 700 ) );

		$this->assertStringContainsString( 'P-1', $html );
		$this->assertStringContainsString( 'Номер заказа на сайте:</th><td>700', $html );
		$this->assertStringContainsString( 'Статус на сайте:</th><td>В обработке', $html );
		$this->assertStringContainsString( 'Создан:</th><td>20.11.2026 10:30', $html );
		$this->assertStringContainsString( 'Способ оплаты:</th><td>Картой на сайте', $html );
		$this->assertStringContainsString( 'Доставка:</th><td>Курьер', $html );
		$this->assertStringContainsString( 'Адрес:</th><td>Москва, Тверская 1', $html );
		$this->assertStringContainsString( 'Итого:</th><td><bdi>1500.00 RUB</bdi>', $html );
		$this->assertStringContainsString( 'Товары:</th><td><bdi>1300.00 RUB</bdi>', $html );
	}

	public function test_the_buyers_note_is_shown_escaped_and_only_when_there_is_one(): void {
		$box  = $this->box( $this->adapter() );
		$with = $this->render( $box, $this->order( 700, "Позвоните <b>заранее</b>\nдомофон 12" ) );

		$this->assertStringContainsString( 'Примечание покупателя:</th><td>Позвоните &lt;b&gt;заранее&lt;/b&gt;<br />', $with );
		$this->assertStringContainsString( 'домофон 12', $with );
		$this->assertStringNotContainsString( 'Примечание покупателя', $this->render( $box, $this->order( 701 ) ) );
	}

	public function test_the_marketplace_status_is_named_for_the_merchant_never_by_its_code(): void {
		$html = $this->render( $this->box( $this->adapter() ), $this->order( 700 ) );

		$this->assertStringContainsString( 'woodev-mp-badge', $html );
		$this->assertStringNotContainsString( 'awaiting_deliver', $html );
	}

	public function test_the_status_is_a_badge_beside_the_posting_number_in_the_heading_and_not_a_row(): void {
		$html = $this->render( $this->box( $this->adapter() ), $this->order( 700 ) );

		$this->assertMatchesRegularExpression( '#<h2 class="[^"]*">P-1 <span class="woodev-mp-badge is-(success|info|warning|danger|neutral)">[^<]+</span></h2>#', $html );
		$this->assertStringNotContainsString( 'Статус на маркетплейсе', $html );
	}

	public function test_an_order_without_a_status_has_no_badge(): void {
		$this->remote_status = '';

		$html = $this->render( $this->box( $this->adapter() ), $this->order( 700 ) );

		$this->assertStringNotContainsString( 'woodev-mp-badge', $html );
		$this->assertStringContainsString( '>P-1</h2>', $html );
	}

	public function test_an_adapter_that_cannot_cancel_has_no_posting_and_no_actions(): void {
		$html = $this->render( $this->box( Mockery::mock( Marketplace_Adapter::class ) ), $this->order( 700 ) );

		$this->assertStringNotContainsString( 'Номер отправления', $html );
		$this->assertStringNotContainsString( 'class="actions"', $html );
	}

	public function test_the_cancel_button_is_in_the_box_with_the_order_nonce(): void {
		$adapter = $this->adapter();
		$html    = $this->render( $this->box( $adapter, $this->modal( $adapter ) ), $this->order( 700 ) );

		$this->assertStringContainsString( 'class="actions"', $html );
		$this->assertStringContainsString( 'data-order-id="700"', $html );
		$this->assertStringContainsString( 'data-nonce="n0nce"', $html );
		$this->assertStringContainsString( 'data-cancel-action="woodev_fake_cancel_posting"', $html );
	}

	public function test_a_finished_posting_gets_a_sentence_and_no_button(): void {
		$adapter = $this->adapter( true );
		$html    = $this->render( $this->box( $adapter, $this->modal( $adapter ) ), $this->order( 700 ) );

		$this->assertStringContainsString( 'отменить его нельзя', $html );
		$this->assertStringNotContainsString( '<button', $html );
	}

	public function test_the_label_button_is_in_the_box_only_when_the_adapter_has_labels(): void {
		$with    = $this->render( $this->box( $this->adapter(), null, $this->label_button( 5 ) ), $this->order( 700 ) );
		$without = $this->render( $this->box( $this->adapter() ), $this->order( 700 ) );

		$this->assertStringContainsString( '>Этикетка</button>', $with );
		$this->assertStringContainsString( 'data-row="5:700"', $with );
		$this->assertStringNotContainsString( 'Этикетка', $without );
		$this->assertStringNotContainsString( 'Этикетки и документы', $with );
	}

	public function test_the_plugin_adds_its_own_rows_through_the_filter(): void {
		Filters\expectApplied( 'woodev_marketplace_fake_order_details' )->once()->andReturnUsing(
			static function ( array $rows ) {
				$rows['Склад'] = 'Хоругвино';

				return $rows;
			}
		);

		$html = $this->render( $this->box( $this->adapter() ), $this->order( 700 ) );

		$this->assertStringContainsString( 'Склад:</th><td>Хоругвино', $html );
	}

	/**
	 * Prints the box and returns what it printed.
	 *
	 * @param Order_Details_Box $box   Box.
	 * @param \WC_Order         $order Order.
	 * @return string
	 */
	private function render( Order_Details_Box $box, \WC_Order $order ): string {
		ob_start();
		$box->render( $order );

		return (string) ob_get_clean();
	}

	/**
	 * Builds the box over the doubles.
	 *
	 * @param Marketplace_Adapter $adapter    Adapter.
	 * @param Cancel_Modal|null   $modal      The cancel button.
	 * @param Label_Button|null   $label_button The label button.
	 * @return Order_Details_Box
	 */
	private function box( Marketplace_Adapter $adapter, ?Cancel_Modal $modal = null, ?Label_Button $label_button = null ): Order_Details_Box {
		return new Order_Details_Box(
			new Screen_Names( 'fake', 'Fake' ),
			$adapter,
			function (): string {
				return $this->remote_status;
			},
			$modal,
			$label_button,
			'https://example.test/assets/',
			'1.0'
		);
	}

	/**
	 * Builds the label button over a registry that keeps every posting under one account.
	 *
	 * @param int $account_id The account.
	 * @return Label_Button
	 */
	private function label_button( int $account_id ): Label_Button {
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
	 * Builds the cancel button over a canceller.
	 *
	 * @param Order_Canceller $canceller The marketplace's cancellation.
	 * @return Cancel_Modal
	 */
	private function modal( Order_Canceller $canceller ): Cancel_Modal {
		return new Cancel_Modal( new Screen_Names( 'fake', 'Fake' ), $canceller, 'https://example.test/assets/', '1.0' );
	}

	/**
	 * Builds an adapter that can cancel: its posting is `P-1`, finished or not.
	 *
	 * @param bool $finished Whether the posting is finished.
	 * @return Marketplace_Adapter&Order_Canceller
	 */
	private function adapter( bool $finished = false ) {
		$adapter = Mockery::mock( Marketplace_Adapter::class, Order_Canceller::class );

		$adapter->shouldReceive( 'posting_number' )->andReturn( 'P-1' );
		$adapter->shouldReceive( 'is_finished' )->andReturn( $finished );

		return $adapter;
	}

	/**
	 * Builds an order double.
	 *
	 * @param int    $id            Order id.
	 * @param string $customer_note The buyer's note.
	 * @return \WC_Order
	 */
	private function order( int $id, string $customer_note = '' ): \WC_Order {
		$created = Mockery::mock( '\WC_DateTime' );
		$created->shouldReceive( 'date_i18n' )->andReturn( '20.11.2026 10:30' );

		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( $id );
		$order->shouldReceive( 'get_order_number' )->andReturn( (string) $id );
		$order->shouldReceive( 'get_status' )->andReturn( 'processing' );
		$order->shouldReceive( 'get_date_created' )->andReturn( $created );
		$order->shouldReceive( 'get_date_modified' )->andReturn( null );
		$order->shouldReceive( 'get_payment_method_title' )->andReturn( 'Картой на сайте' );
		$order->shouldReceive( 'get_shipping_method' )->andReturn( 'Курьер' );
		$order->shouldReceive( 'get_formatted_billing_address' )->andReturn( 'Москва, Тверская 1' );
		$order->shouldReceive( 'get_customer_note' )->andReturn( $customer_note );
		$order->shouldReceive( 'get_currency' )->andReturn( 'RUB' );
		$order->shouldReceive( 'get_subtotal' )->andReturn( 1300 );
		$order->shouldReceive( 'get_shipping_total' )->andReturn( '200' );
		$order->shouldReceive( 'get_total' )->andReturn( '1500' );

		return $order;
	}
}
