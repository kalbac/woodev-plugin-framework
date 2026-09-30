<?php
/**
 * Unit: the «Сейчас выгружаются N заказов перевозчику» notice (#1007) — its heartbeat answer and the
 * notice on the WooCommerce orders list.
 *
 * @package Woodev\Tests\Unit\Shipping\Admin
 */

namespace Woodev\Tests\Unit\Shipping\Admin;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Admin\Orders\Export_Queue_Notice;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Tests\Unit\TestCase;

/**
 * A notice with the screen decided by the test — `get_current_screen()` is WordPress.
 */
class Woodev_Test_Export_Queue_Notice extends Export_Queue_Notice {

	/** @var bool */
	public bool $on_orders_list = true;

	protected function is_orders_list_screen(): bool {
		return $this->on_orders_list;
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Export_Queue_Notice
 */
final class ExportQueueNoticeTest extends TestCase {

	private const KEY = 'woodev-exports-in-progress';

	/** @var int how many exports the queue double reports. */
	private int $count = 0;

	protected function setUp(): void {
		parent::setUp();

		$this->count = 0;

		Functions\stubs( [ 'remove_action', 'add_filter', 'remove_filter', 'esc_html' ] );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'as_get_scheduled_actions' )->alias(
			function ( array $args ) {
				return 'in-progress' === $args['status'] ? array_slice( range( 1, 50 ), 0, $this->count ) : [];
			}
		);

		Orders_Registry::instance()->reset_for_tests();
		Orders_Registry::instance()->register_provider( Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ] ) );
	}

	protected function tearDown(): void {
		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	private function notice(): Woodev_Test_Export_Queue_Notice {
		return new Woodev_Test_Export_Queue_Notice( Orders_Registry::instance() );
	}

	public function test_a_heartbeat_without_the_key_is_left_alone(): void {
		$this->count = 3;

		$this->assertSame( [ 'other' => 1 ], $this->notice()->answer_heartbeat( [ 'other' => 1 ], [ 'something' => true ] ) );
	}

	public function test_a_heartbeat_with_the_key_is_answered_with_the_count_and_the_sentence(): void {
		$this->count = 1;

		$response = $this->notice()->answer_heartbeat( [], [ self::KEY => true ] );

		$this->assertSame( [ self::KEY => [ 'count' => 1, 'text' => 'Сейчас выгружается 1 заказ перевозчику' ] ], $response );
	}

	public function test_an_empty_queue_answers_zero_and_no_text_so_the_notice_disappears(): void {
		$response = $this->notice()->answer_heartbeat( [], [ self::KEY => true ] );

		$this->assertSame( [ 'count' => 0, 'text' => '' ], $response[ self::KEY ] );
	}

	public function test_a_user_who_may_not_open_the_orders_page_gets_no_answer(): void {
		$this->count = 4;
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertSame( [], $this->notice()->answer_heartbeat( [], [ self::KEY => true ] ) );
	}

	public function test_a_non_array_payload_is_returned_untouched(): void {
		$this->assertSame( 'x', $this->notice()->answer_heartbeat( 'x', [ self::KEY => true ] ) );
		$this->assertSame( [], $this->notice()->answer_heartbeat( [], 'x' ) );
	}

	public function test_the_page_starts_from_the_count_at_load_and_the_key_that_keeps_it_current(): void {
		$this->count = 2;

		$this->assertSame(
			[
				'count'        => 2,
				'text'         => $this->notice()->state()['text'],
				'heartbeatKey' => self::KEY,
			],
			$this->notice()->state()
		);
		$this->assertStringContainsString( '2', $this->notice()->state()['text'] );
	}

	public function test_the_list_notice_is_printed_hidden_while_nothing_is_exported(): void {
		$this->expectOutputString( '<div class="notice notice-info woodev-exports-notice" style="display:none"><p class="woodev-exports-notice__text"></p></div>' );

		$this->notice()->render_list_notice();
	}

	public function test_the_list_notice_is_printed_visible_with_the_sentence_while_exports_run(): void {
		$this->count = 1;
		$this->expectOutputString( '<div class="notice notice-info woodev-exports-notice"><p class="woodev-exports-notice__text">Сейчас выгружается 1 заказ перевозчику</p></div>' );

		$this->notice()->render_list_notice();
	}

	public function test_the_list_notice_is_not_printed_off_the_orders_list(): void {
		$this->count  = 3;
		$notice       = $this->notice();
		$notice->on_orders_list = false;

		$this->expectOutputString( '' );

		$notice->render_list_notice();
	}

	public function test_the_list_notice_is_not_printed_for_a_user_without_the_capability(): void {
		$this->count = 3;
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->expectOutputString( '' );

		$this->notice()->render_list_notice();
	}

	public function test_the_list_script_only_keeps_the_text_and_visibility_current(): void {
		$captured = null;

		Functions\expect( 'wp_enqueue_script' )->once()->with( 'heartbeat' );
		Functions\expect( 'wp_add_inline_script' )->once()->with(
			'heartbeat',
			\Mockery::on(
				static function ( $script ) use ( &$captured ) {
					$captured = $script;

					return is_string( $script );
				}
			)
		);
		Functions\when( 'wp_json_encode' )->alias( static fn( $value ) => json_encode( $value ) );

		$this->notice()->enqueue_list_script();

		$this->assertStringContainsString( '"' . self::KEY . '"', $captured, 'the same key the page sends' );
		$this->assertStringContainsString( 'heartbeat-send', $captured );
		$this->assertStringContainsString( 'heartbeat-tick', $captured );
		$this->assertStringContainsString( 's.text', $captured, 'the finished sentence comes from the server' );
	}

	public function test_no_script_is_loaded_off_the_orders_list(): void {
		$notice                 = $this->notice();
		$notice->on_orders_list = false;

		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_add_inline_script' )->never();

		$notice->enqueue_list_script();

		$this->addToAssertionCount( 1 );
	}
}
