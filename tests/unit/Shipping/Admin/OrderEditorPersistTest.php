<?php
/**
 * Unit: how the order editor reaches the framework's own writes (#710 spec D4, card #968).
 *
 * The editor must write an order exactly like checkout does — through the owning plugin's
 * checkout handler's `persist_values()` (the persistence core, marker included) and the plugin's
 * pickup handler's `persist_full_point()` — never through code of its own. Pinned with doubles for
 * both handlers: what they are handed, in what order, and what the editor cleans up on an edit.
 *
 * @package Woodev\Tests\Unit\Shipping\Admin
 */

namespace Woodev\Tests\Unit\Shipping\Admin;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Carrier_Field_Set;
use Woodev\Framework\Shipping\Admin\Orders\Order_Editor;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Checkout\Checkout_Handler;
use Woodev\Framework\Shipping\Pickup\Pickup_Handler;
use Woodev\Framework\Shipping\Shipping_Plugin;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__ ) . '/Order/order-persistence-fixtures.php';

/**
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Order_Editor::persist
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Order_Editor::drop_other_markers
 */
final class OrderEditorPersistTest extends TestCase {

	/** @var array<int,array<string,mixed>> the fake post meta table: order id => key => value. */
	private $meta = [];

	/** @var string[] `delete_post_meta()` calls, as `id:key`. */
	private $deleted = [];

	protected function setUp(): void {
		parent::setUp();

		$this->meta    = [];
		$this->deleted = [];

		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'get_post_meta' )->alias(
			function ( int $post_id, string $key ) {
				return $this->meta[ $post_id ][ $key ] ?? '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( int $post_id, string $key, $value ) {
				$this->meta[ $post_id ][ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = [] ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( int $post_id, string $key ) {
				$this->deleted[] = $post_id . ':' . $key;
				unset( $this->meta[ $post_id ][ $key ] );

				return true;
			}
		);

		Orders_Registry::instance()->reset_for_tests();
	}

	protected function tearDown(): void {
		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	private function order(): \WC_Order {
		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );

		return $order;
	}

	/**
	 * Registers a carrier owned by a plugin double.
	 *
	 * @param Checkout_Handler|null $checkout checkout handler the plugin returns.
	 * @param Pickup_Handler|null   $pickup   pickup handler the plugin returns.
	 * @param string[]              $method_ids the carrier's methods.
	 * @param string                $id        carrier id.
	 * @param string                $marker    marker meta key.
	 * @param array<string,mixed>   $extra_args more `Orders_Provider::create()` arguments.
	 * @return Orders_Provider
	 */
	private function register( $checkout, $pickup, array $method_ids = [ 'cdek_courier', 'cdek_pickup' ], string $id = 'cdek', string $marker = '_cdek_marker', array $extra_args = [] ): Orders_Provider {
		$provider = Orders_Provider::create(
			$id,
			'СДЭК',
			$marker,
			$method_ids,
			[
				'pickup_point_meta_key' => '_cdek_pickup_point',
				'marker_writer'         => static function ( \WC_Order $order, array $context ): void {
				},
			] + $extra_args
		);

		$plugin = Mockery::mock( Shipping_Plugin::class );
		$plugin->shouldReceive( 'get_checkout_handler' )->andReturn( $checkout );
		$plugin->shouldReceive( 'get_pickup_handler' )->andReturn( $pickup );

		Orders_Registry::instance()->register_provider( $provider, $plugin );

		return $provider;
	}

	/**
	 * A checkout handler double: one pickup slot, sanitising by keeping every posted value.
	 *
	 * @param array<int,array<string,mixed>> $calls   collects each `persist_values()` call.
	 * @param array<string,mixed>            $written what `persist_values()` reports as written.
	 * @return Checkout_Handler
	 */
	private function checkout_handler( array &$calls, array $written = [ 'carrier_pickup_point' => 'PVZ-1' ] ): Checkout_Handler {
		$handler = Mockery::mock( Checkout_Handler::class );
		$handler->shouldReceive( 'pickup_field_ids' )->andReturn( [ 'carrier_pickup_point' ] );
		$handler->shouldReceive( 'sanitize_posted_data' )->andReturnUsing(
			static function ( array $raw ): array {
				return $raw;
			}
		);
		$handler->shouldReceive( 'persist_values' )->andReturnUsing(
			function ( $order, array $values, string $chosen, $after, array $context ) use ( &$calls, $written ) {
				$calls[] = [
					'values'  => $values,
					'chosen'  => $chosen,
					'after'   => $after,
					'context' => $context,
				];

				return $written;
			}
		);

		return $handler;
	}

	/**
	 * A validated payload, as the validator hands it over.
	 *
	 * @param Orders_Provider          $provider the carrier.
	 * @param array<string,mixed>|null $point    the pickup point, or null.
	 * @param string                   $method   bare method id.
	 * @return array<string,mixed>
	 */
	private function data( Orders_Provider $provider, ?array $point, string $method = 'cdek_pickup' ): array {
		return [
			'shipping_line'  => [
				'provider'    => $provider,
				'method_id'   => $method,
				'instance_id' => 3,
				'rate_id'     => $method . ':3',
				'label'       => 'ПВЗ',
				'cost'        => '250',
				'meta'        => [ 'delivery_time' => '2 дня' ],
			],
			'pickup_point'   => $point,
			'fields'         => [ 'carrier_comment' => 'позвонить' ],
			'carrier_fields' => [ 'declared_value' => 100 ],
		];
	}

	/**
	 * Calls the private `persist()`.
	 *
	 * @param array<string,mixed> $data      validated payload.
	 * @param bool                $is_update whether the order already existed.
	 * @param Carrier_Field_Set|null $previous_fields the fields of the tariff an edit replaced.
	 * @return array<string,mixed>
	 */
	private function persist( array $data, bool $is_update, ?Carrier_Field_Set $previous_fields = null ): array {
		$method = new \ReflectionMethod( Order_Editor::class, 'persist' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true ); // A no-op (and deprecated) from 8.1, needed on 7.4 / 8.0.
		}

		return $method->invoke( new Order_Editor( Orders_Registry::instance() ), $this->order(), $data, $is_update, $previous_fields );
	}

	public function test_the_point_is_put_under_every_pickup_slot_and_handed_to_the_persistence_core(): void {
		$calls    = [];
		$provider = $this->register( $this->checkout_handler( $calls ), null );

		$written = $this->persist( $this->data( $provider, [ 'id' => 'PVZ-1' ] ), false );

		$this->assertSame( [ 'carrier_pickup_point' => 'PVZ-1' ], $written );
		$this->assertCount( 1, $calls );
		$this->assertSame( 'PVZ-1', $calls[0]['values']['carrier_pickup_point'] );
		$this->assertSame( 'позвонить', $calls[0]['values']['carrier_comment'] );
		$this->assertSame( 'cdek_pickup:3', $calls[0]['chosen'], 'the chosen method is method:instance, as checkout posts it' );
		$this->assertNull( $calls[0]['after'], 'the per-field checkout hook is checkout-only' );
	}

	public function test_the_marker_context_carries_the_rate_the_point_and_the_carrier_fields(): void {
		$calls    = [];
		$provider = $this->register( $this->checkout_handler( $calls ), null );

		$this->persist( $this->data( $provider, [ 'id' => 'PVZ-1' ] ), false );

		$context = $calls[0]['context'];

		$this->assertSame(
			[
				'id'          => 'cdek_pickup:3',
				'method_id'   => 'cdek_pickup',
				'instance_id' => 3,
				'label'       => 'ПВЗ',
				'cost'        => '250',
				'meta'        => [ 'delivery_time' => '2 дня' ],
			],
			$context['rate'],
			'the rate without the provider object'
		);
		$this->assertSame( [ 'id' => 'PVZ-1' ], $context['pickup_point'] );
		$this->assertSame( [ 'declared_value' => 100 ], $context['carrier_fields'] );
	}

	public function test_a_create_does_not_refresh_the_marker_and_an_update_does(): void {
		$calls    = [];
		$provider = $this->register( $this->checkout_handler( $calls ), null );

		$this->persist( $this->data( $provider, [ 'id' => 'PVZ-1' ] ), false );
		$this->persist( $this->data( $provider, [ 'id' => 'PVZ-1' ] ), true );

		$this->assertFalse( $calls[0]['context']['refresh'] );
		$this->assertTrue( $calls[1]['context']['refresh'], 'a re-saved order must re-run the marker writers' );
	}

	public function test_the_full_point_is_persisted_through_the_plugins_pickup_handler(): void {
		$calls  = [];
		$pickup = Mockery::mock( Pickup_Handler::class );
		$pickup->shouldReceive( 'persist_full_point' )->once()->with( Mockery::type( '\WC_Order' ), 'PVZ-1' );

		$provider = $this->register( $this->checkout_handler( $calls ), $pickup );

		$this->persist( $this->data( $provider, [ 'id' => 'PVZ-1' ] ), false );
	}

	public function test_no_point_means_no_pickup_slot_and_no_full_point_write(): void {
		$calls  = [];
		$pickup = Mockery::mock( Pickup_Handler::class );
		$pickup->shouldNotReceive( 'persist_full_point' );

		$provider = $this->register( $this->checkout_handler( $calls, [] ), $pickup );

		$this->persist( $this->data( $provider, null, 'cdek_courier' ), false );

		$this->assertArrayNotHasKey( 'carrier_pickup_point', $calls[0]['values'] );
	}

	public function test_an_update_that_leaves_pickup_deletes_the_stale_slot_and_point_metas(): void {
		$calls    = [];
		$provider = $this->register( $this->checkout_handler( $calls, [] ), null );

		$this->persist( $this->data( $provider, null, 'cdek_courier' ), true );

		$this->assertContains( '42:carrier_pickup_point', $this->deleted, 'the slot the stale-pickup drop skipped' );
		$this->assertContains( '42:_cdek_pickup_point', $this->deleted, "the carrier's full-point meta" );
	}

	/**
	 * A carrier declaring two fields for a courier tariff and one for a pickup one.
	 *
	 * @return array<string,mixed> `Orders_Provider::create()` arguments.
	 */
	private function declaring(): array {
		return [
			'order_fields' => static function ( array $context ): array {
				$fields = [
					'declared_value' => [
						'meta_key' => '_cdek_declared_value',
						'control'  => 'number',
						'type'     => 'float',
						'name'     => 'Объявленная ценность',
					],
				];

				// Keyed by the method id: a unit test has no zone to resolve the method instance from.
				if ( 'cdek_pickup' !== $context['method_id'] ) {
					$fields['call_before'] = [
						'meta_key' => '_cdek_call_before',
						'control'  => 'toggle',
						'name'     => 'Позвонить',
					];
				}

				return $fields;
			},
		];
	}

	public function test_the_carrier_fields_are_stored_under_their_declared_meta_keys(): void {
		$calls    = [];
		$provider = $this->register( $this->checkout_handler( $calls ), null, [ 'cdek_courier', 'cdek_pickup' ], 'cdek', '_cdek_marker', $this->declaring() );

		$data                   = $this->data( $provider, null, 'cdek_courier' );
		$data['carrier_fields'] = [ 'declared_value' => 1500.5, 'call_before' => true ];

		$this->persist( $data, false );

		$this->assertSame( 1500.5, $this->meta[42]['_cdek_declared_value'] );
		$this->assertSame( 'yes', $this->meta[42]['_cdek_call_before'], 'a boolean is stored yes / no' );
		$this->assertSame( [ 'declared_value' => 1500.5, 'call_before' => true ], $calls[0]['context']['carrier_fields'], 'the marker writer sees the same values' );
	}

	public function test_a_carrier_that_declares_no_fields_stores_none(): void {
		$calls    = [];
		$provider = $this->register( $this->checkout_handler( $calls ), null );

		$this->persist( $this->data( $provider, null, 'cdek_courier' ), false );

		$this->assertArrayNotHasKey( '_cdek_declared_value', $this->meta[42] ?? [] );
	}

	public function test_an_edit_that_changes_the_tariff_removes_the_fields_the_new_one_does_not_ask_for(): void {
		$calls    = [];
		$provider = $this->register( $this->checkout_handler( $calls, [] ), null, [ 'cdek_courier', 'cdek_pickup' ], 'cdek', '_cdek_marker', $this->declaring() );

		// The order was a courier one and stored both fields; it is now moved to the pickup tariff.
		$previous       = Carrier_Field_Set::for_rate( $provider, 'cdek_courier', 0 );
		$this->meta[42] = [
			'_cdek_declared_value' => '1500.5',
			'_cdek_call_before'    => 'yes',
		];

		$data                   = $this->data( $provider, [ 'id' => 'PVZ-1' ], 'cdek_pickup' );
		$data['carrier_fields'] = [ 'declared_value' => 1500.5 ];

		$this->persist( $data, true, $previous );

		$this->assertSame( 1500.5, $this->meta[42]['_cdek_declared_value'], 'a field both tariffs ask for is kept' );
		$this->assertArrayNotHasKey( '_cdek_call_before', $this->meta[42], 'the courier-only field is gone' );
	}

	public function test_a_create_deletes_nothing(): void {
		$calls    = [];
		$provider = $this->register( $this->checkout_handler( $calls, [] ), null );

		$this->persist( $this->data( $provider, null, 'cdek_courier' ), false );

		$this->assertSame( [], $this->deleted );
	}

	public function test_an_update_keeps_the_slot_the_core_just_wrote(): void {
		$calls    = [];
		$provider = $this->register( $this->checkout_handler( $calls ), null );

		$this->persist( $this->data( $provider, [ 'id' => 'PVZ-1' ] ), true );

		$this->assertNotContains( '42:carrier_pickup_point', $this->deleted );
		$this->assertNotContains( '42:_cdek_pickup_point', $this->deleted, 'a chosen point keeps its full record' );
	}

	public function test_an_update_moving_the_order_to_another_carrier_drops_the_old_marker(): void {
		$calls    = [];
		$provider = $this->register( $this->checkout_handler( $calls ), null );

		$other_calls = [];
		$this->register( $this->checkout_handler( $other_calls ), null, [ 'yandex_courier' ], 'yandex', '_yandex_marker' );

		$this->meta[42]['_yandex_marker'] = 'NEW';
		$this->meta[42]['_cdek_marker']   = '1';

		$this->persist( $this->data( $provider, null, 'cdek_courier' ), true );

		$this->assertContains( '42:_yandex_marker', $this->deleted );
		$this->assertNotContains( '42:_cdek_marker', $this->deleted, "the chosen carrier's own marker is the marker writer's, never deleted here" );
	}

	public function test_a_create_never_drops_a_marker(): void {
		$calls    = [];
		$provider = $this->register( $this->checkout_handler( $calls ), null );

		$other_calls = [];
		$this->register( $this->checkout_handler( $other_calls ), null, [ 'yandex_courier' ], 'yandex', '_yandex_marker' );

		$this->meta[42]['_yandex_marker'] = 'NEW';

		$this->persist( $this->data( $provider, null, 'cdek_courier' ), false );

		$this->assertSame( [], $this->deleted );
	}

	public function test_a_carrier_plugin_without_a_checkout_handler_still_gets_its_marker(): void {
		$marked   = [];
		$provider = Orders_Provider::create(
			'cdek',
			'СДЭК',
			'_cdek_marker',
			[ 'cdek_courier' ],
			[
				'marker_writer' => function ( \WC_Order $order, array $context ) use ( &$marked ): void {
					$marked[] = $context['method_id'];

					// What the real writer's `update_meta_data()` + the framework's save leave in the store.
					$this->meta[42]['_cdek_marker'] = '1';
				},
			]
		);

		$plugin = Mockery::mock( Shipping_Plugin::class );
		$plugin->shouldReceive( 'get_checkout_handler' )->andReturn( null );
		$plugin->shouldReceive( 'get_pickup_handler' )->andReturn( null );
		Orders_Registry::instance()->register_provider( $provider, $plugin );

		$order = Mockery::mock( '\WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 42 );
		$line = Mockery::mock( '\WC_Order_Item_Shipping' );
		$line->shouldReceive( 'get_method_id' )->andReturn( 'cdek_courier' );
		$line->shouldReceive( 'get_instance_id' )->andReturn( 3 );
		$line->shouldReceive( 'get_name' )->andReturn( 'Курьер' );
		$line->shouldReceive( 'get_total' )->andReturn( '250' );
		$line->shouldReceive( 'get_meta_data' )->andReturn( [] );
		$order->shouldReceive( 'get_shipping_methods' )->andReturn( [ $line ] );
		$order->shouldReceive( 'get_items' )->andReturn( [ $line ] );
		$order->shouldReceive( 'update_meta_data' );
		$order->shouldReceive( 'save_meta_data' );

		$method = new \ReflectionMethod( Order_Editor::class, 'persist' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true ); // A no-op (and deprecated) from 8.1, needed on 7.4 / 8.0.
		}

		$written = $method->invoke( new Order_Editor( Orders_Registry::instance() ), $order, $this->data( $provider, null, 'cdek_courier' ), false );

		$this->assertSame( [], $written );
		$this->assertSame( [ 'cdek_courier' ], $marked, 'the marker goes through Order_Marker even with no checkout handler' );
	}
}
