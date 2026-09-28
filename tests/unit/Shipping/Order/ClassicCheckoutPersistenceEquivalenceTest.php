<?php
/**
 * Equivalence test for #964 (#710 I1a): the classic checkout must write the SAME order meta
 * and fire the SAME hooks, in the SAME order with the SAME payloads, before and after the
 * order-data writes were extracted into the shared persistence core.
 *
 * The expected event logs below were recorded against `main` BEFORE the extraction (the test
 * was written first and run green on the untouched code), then left unchanged while the
 * handlers were refactored. Every meta write is captured at the lowest layer
 * (`update_post_meta`, reached through `Woodev_Order_Compatibility`) and every hook at
 * `do_action`, into ONE ordered log — so a reordering, a dropped hook or a changed payload
 * fails here, not only a changed meta value.
 *
 * Fires the two `woocommerce_checkout_order_processed` callbacks the way WordPress does for
 * one plugin: each handler's own callback, in registration order (Pickup_Handler then
 * Checkout_Handler — the order the I0 measurement logged on the rig). A second plugin
 * repeats the pair, because every active carrier plugin's handlers run for EVERY order (I0
 * table 2, fan-out).
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace Woodev\Tests\Unit\Shipping\Order {

	use Brain\Monkey\Functions;
	use Woodev\Framework\Shipping\Checkout\Checkout_Fields;
	use Woodev\Framework\Shipping\Checkout\Checkout_Handler;
	use Woodev\Framework\Shipping\Checkout\Field;
	use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
	use Woodev\Framework\Shipping\Order\Shipping_Order_Handler;
	use Woodev\Framework\Shipping\Pickup\Pickup_Handler;
	use Woodev\Framework\Shipping\Pickup\Pickup_Point;
	use Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab;
	use Woodev\Tests\Unit\TestCase;

	require_once __DIR__ . '/order-persistence-fixtures.php';

	/**
	 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::handle_checkout_order_processed
	 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::process
	 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::save
	 * @covers \Woodev\Framework\Shipping\Pickup\Pickup_Handler::handle_checkout_order_processed
	 */
	class ClassicCheckoutPersistenceEquivalenceTest extends TestCase {

		/**
		 * The ONE ordered log every meta write and hook lands in.
		 *
		 * @var array<int, array<int, mixed>>
		 */
		private array $events = [];

		/**
		 * The one order object every handler receives, so payload identity is comparable.
		 *
		 * @var \WC_Order
		 */
		private \WC_Order $order;

		protected function setUp(): void {
			parent::setUp();

			$this->events = [];
			$this->order  = new \WC_Order();

			Functions\when( 'wc_clean' )->returnArg();
			Functions\when( 'wp_unslash' )->returnArg();
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'sanitize_hex_color' )->returnArg();
			Functions\when( 'is_user_logged_in' )->justReturn( false );
			Functions\when( 'get_option' )->justReturn( null );
			Functions\when( 'wc_parse_relative_date_option' )->justReturn( [ 'number' => '', 'unit' => 'days' ] );
			Functions\when( 'wp_parse_args' )->alias(
				static function ( $args, $defaults = [] ) {
					return array_merge( (array) $defaults, (array) $args );
				}
			);
			Functions\when( 'wc_add_notice' )->alias(
				function ( $message, $type = 'success' ) {
					$this->events[] = [ 'notice', $type, $message ];
				}
			);
			Functions\when( 'update_post_meta' )->alias(
				function ( $id, $key, $value ) {
					$this->events[] = [ 'meta', $id, $key, $value ];

					return true;
				}
			);
			Functions\when( 'do_action' )->alias(
				function ( string $hook, ...$args ) {
					$this->events[] = [ 'hook', $hook, $args ];
				}
			);

			Location_Provider_Registry::instance()->reset_for_tests();
			Shipping_Settings_Tab::reset_for_tests();
		}

		protected function tearDown(): void {
			$_POST = [];
			Checkout_Handler::reset_native_field_registry();
			Shipping_Settings_Tab::reset_for_tests();
			parent::tearDown();
		}

		/**
		 * @return Pickup_Point
		 */
		private function point(): Pickup_Point {
			return Pickup_Point::from_array(
				[
					'id'      => 'P1',
					'name'    => 'Точка',
					'lat'     => 55.75,
					'lng'     => 37.61,
					'address' => 'Москва',
					'type'    => [ 'code' => 'PVZ', 'label' => 'ПВЗ' ],
				]
			);
		}

		/**
		 * One plugin's pair of order-processed handlers — the pickup slot, an ordinary field
		 * and a native `billing_city` on the checkout side; full-point persistence wired on
		 * the pickup side (logical `pickup_full` → the plugin's real meta key).
		 *
		 * @param string $prefix       hook prefix / plugin token.
		 * @param string $pickup_field the pickup-slot field id.
		 * @param string $full_key     the real meta key the full point lands under.
		 * @param string $method       the method id the slot belongs to.
		 *
		 * @return array{0: Pickup_Handler, 1: Checkout_Handler}
		 */
		private function plugin_handlers( string $prefix, string $pickup_field, string $full_key, string $method ): array {
			$pickup = new Pickup_Handler(
				$prefix,
				$pickup_field,
				new Order_Persistence_Test_Source( $this->point() ),
				new Order_Persistence_Test_Map_Provider(),
				[ 'center' => [ 55.75, 37.61 ], 'zoom' => 10 ],
				new Shipping_Order_Handler( [ 'pickup_full' => $full_key ] ),
				'pickup_full'
			);

			$checkout = new Checkout_Handler(
				Checkout_Fields::from_array(
					[
						Field::create( $pickup_field )->mark_pickup_slot()->to_array(),
						Field::create( $prefix . '_comment' )->to_array(),
						Field::create( 'billing_city' )->to_array(),
					]
				),
				$prefix
			);
			$checkout->set_requires_pickup_methods( [ $method ] );

			return [ $pickup, $checkout ];
		}

		/**
		 * Fires the classic `woocommerce_checkout_order_processed` callbacks for every plugin
		 * pair, in registration order.
		 *
		 * @param array<int, array{0: Pickup_Handler, 1: Checkout_Handler}> $pairs the plugins.
		 *
		 * @return void
		 */
		private function fire_order_processed( array $pairs ): void {
			foreach ( $pairs as [ $pickup, $checkout ] ) {
				$pickup->handle_checkout_order_processed( 123, [], $this->order );
				$checkout->handle_checkout_order_processed( 123, [], $this->order );
			}
		}

		/**
		 * The metas and hooks for a pickup order: the point id lands as field meta + both
		 * per-field/data hooks, the full point lands under the plugin's key, and the
		 * `processed` hook closes the pipeline.
		 */
		public function test_a_pickup_order_writes_the_same_metas_and_fires_the_same_hooks(): void {
			$_POST = [
				'carrier_pickup_point' => 'P1',
				'carrier_comment'      => 'позвонить',
				'billing_city'         => 'Москва',
				'billing_country'      => 'RU',
				'shipping_method'      => [ 'carrier_pickup:2' ],
			];

			$pair = $this->plugin_handlers( 'carrier', 'carrier_pickup_point', 'cdek_full_point', 'carrier_pickup' );

			$this->fire_order_processed( [ $pair ] );

			$this->assertSame(
				[
					// Pickup_Handler: the full point, under the plugin's own key.
					[ 'meta', 123, 'cdek_full_point', $this->point()->to_array() ],
					// Checkout_Handler::save(): each managed non-native field, hook after each.
					[ 'meta', 123, 'carrier_pickup_point', 'P1' ],
					[ 'hook', 'woodev_shipping_carrier_checkout_field_saved', [ $this->order, 'carrier_pickup_point', 'P1' ] ],
					[ 'meta', 123, 'carrier_comment', 'позвонить' ],
					[ 'hook', 'woodev_shipping_carrier_checkout_field_saved', [ $this->order, 'carrier_comment', 'позвонить' ] ],
					[
						'hook',
						'woodev_shipping_carrier_checkout_data_saved',
						[
							$this->order,
							[
								'carrier_pickup_point' => 'P1',
								'carrier_comment'      => 'позвонить',
								'billing_city'         => 'Москва',
							],
						],
					],
					[
						'hook',
						'woodev_shipping_carrier_checkout_processed',
						[
							$this->order,
							[
								'carrier_pickup_point' => 'P1',
								'carrier_comment'      => 'позвонить',
								'billing_city'         => 'Москва',
							],
						],
					],
				],
				$this->events
			);
		}

		/**
		 * A free-shipping order: the stale pickup value never reaches meta or any hook
		 * payload (#745) and the full point is not stored for it either — no point was
		 * posted, exactly like the rig's `free_shipping` order.
		 */
		public function test_a_non_pickup_order_drops_the_stale_pickup_value_everywhere(): void {
			$_POST = [
				'carrier_pickup_point' => 'STALE',
				'carrier_comment'      => '',
				'billing_city'         => 'Казань',
				'billing_country'      => 'RU',
				'shipping_method'      => [ 'free_shipping:1' ],
			];

			$pair = $this->plugin_handlers( 'carrier', 'carrier_pickup_point', 'cdek_full_point', 'carrier_pickup' );

			$this->fire_order_processed( [ $pair ] );

			$this->assertSame(
				[
					// Pickup_Handler still re-fetches and stores a posted point id — its own
					// contract does not look at the chosen method (the id here is stale, the
					// checkout side is what drops it).
					[ 'meta', 123, 'cdek_full_point', $this->point()->to_array() ],
					[ 'meta', 123, 'carrier_comment', '' ],
					[ 'hook', 'woodev_shipping_carrier_checkout_field_saved', [ $this->order, 'carrier_comment', '' ] ],
					[
						'hook',
						'woodev_shipping_carrier_checkout_data_saved',
						[
							$this->order,
							[
								'carrier_comment' => '',
								'billing_city'    => 'Казань',
							],
						],
					],
					[
						'hook',
						'woodev_shipping_carrier_checkout_processed',
						[
							$this->order,
							[
								'carrier_comment' => '',
								'billing_city'    => 'Казань',
							],
						],
					],
				],
				$this->events
			);
		}

		/**
		 * Two plugins active: every plugin's pair runs for the SAME order, each under its
		 * own hook prefix and its own meta keys (I0 §2 fan-out).
		 */
		public function test_two_active_plugins_each_write_under_their_own_prefix_for_one_order(): void {
			$_POST = [
				'carrier_pickup_point' => 'P1',
				'other-plugin_point'   => 'P1',
				'carrier_comment'      => 'a',
				'other-plugin_comment' => 'b',
				'billing_city'         => 'Москва',
				'billing_country'      => 'RU',
				'shipping_method'      => [ 'carrier_pickup:2' ],
			];

			$first  = $this->plugin_handlers( 'carrier', 'carrier_pickup_point', 'cdek_full_point', 'carrier_pickup' );
			$second = $this->plugin_handlers( 'other-plugin', 'other-plugin_point', 'other_full_point', 'other_pickup' );

			$this->fire_order_processed( [ $first, $second ] );

			$hooks = array_values(
				array_map(
					static fn( array $event ) => $event[1],
					array_filter( $this->events, static fn( array $event ) => 'hook' === $event[0] )
				)
			);

			$this->assertSame(
				[
					'woodev_shipping_carrier_checkout_field_saved',
					'woodev_shipping_carrier_checkout_field_saved',
					'woodev_shipping_carrier_checkout_data_saved',
					'woodev_shipping_carrier_checkout_processed',
					// The second plugin's chosen method is not its own pickup method: its slot
					// is dropped, so it fires only the ordinary field's hook.
					'woodev_shipping_other-plugin_checkout_field_saved',
					'woodev_shipping_other-plugin_checkout_data_saved',
					'woodev_shipping_other-plugin_checkout_processed',
				],
				$hooks
			);

			$metas = array_values(
				array_map(
					static fn( array $event ) => [ $event[2], $event[3] ],
					array_filter( $this->events, static fn( array $event ) => 'meta' === $event[0] )
				)
			);

			$this->assertSame(
				[
					[ 'cdek_full_point', $this->point()->to_array() ],
					[ 'carrier_pickup_point', 'P1' ],
					[ 'carrier_comment', 'a' ],
					[ 'other_full_point', $this->point()->to_array() ],
					[ 'other-plugin_comment', 'b' ],
				],
				$metas
			);
		}

		/**
		 * A failing validation blocks the checkout BEFORE an order exists — the classic
		 * `woocommerce_checkout_process` hook is where that happens, and it must keep adding
		 * its notice through `wc_add_notice`. (`process()` re-validates at order-processed
		 * time too, and on failure saves nothing.)
		 */
		public function test_a_failed_validation_saves_nothing_and_raises_the_notice(): void {
			$_POST = [
				'carrier_pickup_point' => '',
				'carrier_comment'      => '',
				'billing_city'         => 'Москва',
				'billing_country'      => 'RU',
				'shipping_method'      => [ 'carrier_pickup:2' ],
			];

			[ , $checkout ] = $this->plugin_handlers( 'carrier', 'carrier_pickup_point', 'cdek_full_point', 'carrier_pickup' );

			$checkout->handle_checkout_order_processed( 123, [], $this->order );

			$this->assertSame(
				[
					[ 'notice', 'error', 'This shipping method requires you to choose a pickup point.' ],
				],
				$this->events,
				'no meta and no hook — only the notice'
			);
		}
	}
}
