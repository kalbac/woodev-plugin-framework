<?php
/**
 * Unit: «сразу выгрузить перевозчику» — the service half (#710 spec D6, card #974).
 *
 * `Order_Editor::export_created()` runs the export through {@see Order_Actions} — the very gate and
 * performer the row action uses — and answers `{ success, message }` for the merchant. Pinned:
 * the carrier's own text reaches the answer prefixed with its name («СДЭК: …») and nothing else
 * (no order note, no status change: the order stays whatever the carrier says); a status the
 * export is not offered in is refused with the framework's own reason and never reaches the
 * carrier; a carrier call that throws is logged and answered generically.
 *
 * @package Woodev\Tests\Unit\Shipping\Admin
 */

namespace Woodev\Tests\Unit\Shipping\Admin;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Order_Editor;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;
use Woodev\Framework\Shipping\Order\Action_Result;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';

/**
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Order_Editor::export_created
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Order_Actions::perform
 */
final class OrderEditorExportTest extends TestCase {

	/** @var array<string,mixed> post meta, keyed by meta key, for the active order. */
	private $meta = [];

	/** @var string|false the `error_log` target before a test redirected it. */
	private $previous_error_log;

	protected function setUp(): void {
		parent::setUp();

		$this->meta               = [];
		$this->previous_error_log = ini_get( 'error_log' );

		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_post_meta' )->alias(
			function ( int $post_id, string $key, bool $single ) {
				return $this->meta[ $key ] ?? '';
			}
		);
		Functions\when( 'wc_get_order_status_name' )->alias(
			static function ( string $status ): string {
				return ucfirst( $status );
			}
		);

		Orders_Registry::instance()->reset_for_tests();
	}

	protected function tearDown(): void {
		ini_set( 'error_log', (string) $this->previous_error_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- restoring the test's own redirect.

		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	private function register_provider(): void {
		Orders_Registry::instance()->register_provider(
			Orders_Provider::create(
				'cdek',
				'СДЭК',
				'_cdek_marker',
				[ 'cdek_courier' ],
				[ 'carrier_order_id_meta_key' => '_cdek_carrier_order_id' ]
			)
		);
	}

	private function register_handler(): Abstract_Shipment_Handler {
		$handler = Mockery::mock( Abstract_Shipment_Handler::class );
		$handler->shouldReceive( 'supports_update' )->andReturn( false );

		Orders_Registry::instance()->register_shipment_handler( 'cdek', $handler );

		return $handler;
	}

	/**
	 * A strict order double: only what the gate and the export read is allowed, so an
	 * `add_order_note()` / `set_status()` / `save()` the export must not make fails the test.
	 *
	 * @param string $status order status.
	 * @return \WC_Order
	 */
	private function order( string $status = 'pending' ): \WC_Order {
		$this->meta['_cdek_marker'] = '1';

		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );
		$order->shouldReceive( 'get_status' )->andReturn( $status );
		$order->shouldReceive( 'read_meta_data' )->with( true )->andReturnNull();

		return $order;
	}

	private function editor(): Order_Editor {
		return new Order_Editor( Orders_Registry::instance() );
	}

	public function test_a_successful_export_says_so_without_a_carrier_prefix_when_the_carrier_gave_no_text(): void {
		$this->register_provider();
		$order = $this->order();
		$this->register_handler()->shouldReceive( 'export' )->once()->with( $order, null, null )->andReturn( Action_Result::success( 'CARRIER-1' ) );

		$outcome = $this->editor()->export_created( $order );

		$this->assertTrue( $outcome['success'] );
		$this->assertSame( 'Заказ выгружен перевозчику.', $outcome['message'] );
	}

	public function test_a_carrier_note_on_success_replaces_the_frameworks_sentence_and_names_the_carrier(): void {
		$this->register_provider();
		$order = $this->order();
		$this->register_handler()->shouldReceive( 'export' )->once()->andReturn( Action_Result::success( 'CARRIER-1', 'Накладная 42' ) );

		$outcome = $this->editor()->export_created( $order );

		$this->assertTrue( $outcome['success'] );
		$this->assertSame( 'СДЭК: Накладная 42', $outcome['message'] );
	}

	public function test_the_carriers_own_refusal_text_reaches_the_merchant_prefixed_with_its_name(): void {
		$this->register_provider();
		$order = $this->order();
		$this->register_handler()->shouldReceive( 'export' )->once()->andReturn( Action_Result::failure( 'Неверный индекс получателя' ) );

		$outcome = $this->editor()->export_created( $order );

		$this->assertFalse( $outcome['success'] );
		$this->assertSame( 'СДЭК: Неверный индекс получателя', $outcome['message'] );
	}

	public function test_a_refusal_without_text_falls_back_to_the_frameworks_words_and_points_at_the_list_button(): void {
		$this->register_provider();
		$order = $this->order();
		$this->register_handler()->shouldReceive( 'export' )->once()->andReturn( Action_Result::failure() );

		$outcome = $this->editor()->export_created( $order );

		$this->assertFalse( $outcome['success'] );
		$this->assertStringContainsString( 'Экспорт', $outcome['message'] );
		$this->assertStringNotContainsString( 'СДЭК', $outcome['message'] );
	}

	public function test_a_failed_export_leaves_the_order_alone(): void {
		// The double is strict: an `add_order_note()`, `set_status()`, `save()` or `delete()` here
		// would throw. The carrier's text is the merchant's, never a note the buyer could read.
		$this->register_provider();
		$order = $this->order();
		$this->register_handler()->shouldReceive( 'export' )->once()->andReturn( Action_Result::failure( 'Неверный индекс получателя' ) );

		$outcome = $this->editor()->export_created( $order );

		// Reaching here at all is the assertion; the failure is reported, not acted on.
		$this->assertFalse( $outcome['success'] );
	}

	public function test_a_status_the_export_is_not_offered_in_never_reaches_the_carrier(): void {
		$this->register_provider();
		$order = $this->order( 'completed' );
		$this->register_handler()->shouldReceive( 'export' )->never();

		$outcome = $this->editor()->export_created( $order );

		$this->assertFalse( $outcome['success'] );
		$this->assertStringContainsString( 'Выгрузить можно только заказ в одном из статусов', $outcome['message'] );
	}

	public function test_an_already_exported_order_is_not_exported_twice(): void {
		$this->meta['_cdek_carrier_order_id'] = 'CARRIER-1';
		$this->register_provider();
		$order = $this->order();
		$this->register_handler()->shouldReceive( 'export' )->never();

		$outcome = $this->editor()->export_created( $order );

		$this->assertFalse( $outcome['success'] );
		$this->assertSame( 'Заказ уже выгружен перевозчику.', $outcome['message'] );
	}

	public function test_a_carrier_without_a_shipment_handler_is_answered_with_the_reason(): void {
		$this->register_provider();
		$order = $this->order();

		$outcome = $this->editor()->export_created( $order );

		$this->assertFalse( $outcome['success'] );
		$this->assertStringContainsString( 'обработчик отправлений', $outcome['message'] );
	}

	public function test_an_order_that_is_not_a_row_of_the_page_is_answered_with_the_reason(): void {
		$this->register_provider();

		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );
		$order->shouldReceive( 'get_status' )->andReturn( 'pending' );
		$order->shouldReceive( 'read_meta_data' )->with( true )->andReturnNull();

		$outcome = $this->editor()->export_created( $order );

		$this->assertFalse( $outcome['success'] );
		$this->assertStringContainsString( 'перевозчика', $outcome['message'] );
	}

	public function test_a_carrier_call_that_throws_is_logged_and_answered_generically(): void {
		$log = tempnam( sys_get_temp_dir(), 'woodev-export-log' );
		ini_set( 'error_log', (string) $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- capturing the diagnostic line.

		$this->register_provider();
		$order = $this->order();
		$this->register_handler()->shouldReceive( 'export' )->once()->andThrow( new \RuntimeException( 'cURL error 28: timeout' ) );

		$outcome = $this->editor()->export_created( $order );

		$this->assertFalse( $outcome['success'] );
		$this->assertStringContainsString( 'временно недоступен', $outcome['message'] );
		$this->assertStringNotContainsString( 'cURL', $outcome['message'], 'the raw exception text is for the log, not the screen' );
		$this->assertStringContainsString( 'cURL error 28', (string) file_get_contents( (string) $log ) );

		unlink( (string) $log );
	}
}
