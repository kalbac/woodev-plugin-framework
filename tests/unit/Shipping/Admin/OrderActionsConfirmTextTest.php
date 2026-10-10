<?php
/**
 * Unit: the optional confirmation sentence of a destructive extra action (s164).
 *
 * `Order_Actions::for_order()` re-validates what `woodev_shipping_order_actions` returns; a destructive action may word
 * its own «Вы уверены…» question there (`confirm`), the way the courier plugin warns that a cancelled call may not be
 * repeated. Pinned: it survives for a destructive action, is dropped for a harmless one, is plain text, and an action
 * without one keeps exactly the shape it always had.
 *
 * @package Woodev\Tests\Unit\Shipping\Admin
 */

namespace Woodev\Tests\Unit\Shipping\Admin;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Order_Actions;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';
require_once __DIR__ . '/order-edit-lock-fixtures.php';
require_once __DIR__ . '/order-edit-lock-cpt-fixtures.php';

/**
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Order_Actions::for_order
 */
final class OrderActionsConfirmTextTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		\Automattic\WooCommerce\Internal\Admin\Orders\EditLock::$locks = [];
		Orders_Registry::instance()->reset_for_tests();

		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( string $text ): string => strip_tags( $text ) );

		$handler = Mockery::mock( Abstract_Shipment_Handler::class );
		$handler->shouldReceive( 'supports_update' )->andReturn( false );
		// #1204: a carrier that declares nothing keeps today's behaviour.
		$handler->shouldReceive( 'supports_refusal' )->andReturn( false )->byDefault();
		$handler->shouldReceive( 'get_handed_over_statuses' )->andReturn( [] )->byDefault();
		$handler->shouldReceive( 'get_refusable_statuses' )->andReturn( [] )->byDefault();
		Orders_Registry::instance()->register_shipment_handler( 'cdek', $handler );
	}

	protected function tearDown(): void {
		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	/** @return array<string,mixed>|null the entry the filter declared, as the set returns it. */
	private function declared( array $extra ): ?array {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, ...$args ) use ( $extra ) {
				if ( 'woodev_shipping_order_actions' === $hook ) {
					$value[] = array_merge(
						[
							'action' => 'cancel_intake',
							'label'  => 'Отменить вызов',
						],
						$extra
					);
				}

				return $value;
			}
		);

		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );
		$order->shouldReceive( 'get_status' )->andReturn( 'processing' );

		$provider = Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ], [ 'carrier_order_id_meta_key' => '_cdek_carrier_order_id' ] );
		$set      = ( new Order_Actions( Orders_Registry::instance() ) )->for_order( $order, $provider );

		foreach ( $set as $entry ) {
			if ( 'cancel_intake' === $entry['action'] ) {
				return $entry;
			}
		}

		return null;
	}

	public function test_a_destructive_action_keeps_its_own_confirmation_sentence(): void {
		$entry = $this->declared(
			[
				'destructive' => true,
				'confirm'     => 'После отмены СДЭК может не принять новый вызов курьера по этому заказу.',
			]
		);

		$this->assertSame( 'После отмены СДЭК может не принять новый вызов курьера по этому заказу.', $entry['confirm'] );
	}

	public function test_the_sentence_is_one_plain_line(): void {
		$entry = $this->declared(
			[
				'destructive' => true,
				'confirm'     => "Вы   уверены?\n<script>alert(1)</script>",
			]
		);

		$this->assertSame( 'Вы уверены? alert(1)', $entry['confirm'] );
	}

	public function test_a_harmless_action_has_nothing_to_confirm_so_its_sentence_is_dropped(): void {
		$entry = $this->declared( [ 'confirm' => 'Не нужно' ] );

		$this->assertArrayNotHasKey( 'confirm', $entry );
	}

	public function test_a_destructive_action_without_a_sentence_keeps_the_shape_it_always_had(): void {
		$entry = $this->declared( [ 'destructive' => true ] );

		$this->assertSame( [ 'action', 'label', 'title', 'destructive' ], array_keys( $entry ) );
	}

	public function test_an_unusable_sentence_is_dropped(): void {
		foreach ( [ '', '   ', '<b></b>', [ 'x' ], 5, null ] as $bad ) {
			$this->assertArrayNotHasKey(
				'confirm',
				$this->declared(
					[
						'destructive' => true,
						'confirm'     => $bad,
					]
				)
			);
		}
	}
}
