<?php
/**
 * Tests for #963 / #964: a block-checkout (Store API) order gets the framework's order data.
 *
 * `woocommerce_store_api_checkout_order_processed` passes ONE argument — the order — and none
 * of the classic hooks the handlers listen to ever fire on that path (measured on the rig,
 * #962 I0 §3). Each test fires the Store API callbacks the way WordPress does for the real
 * signature: every plugin's priority-10 callback (`Checkout_Handler`), then every priority-20
 * one (`Pickup_Handler`) — the order `register()` wires them in — with the
 * `woodev_shipping_store_api_posted_data` filter applied for real between them.
 *
 * The order is a stand-in built from what the block checkout leaves behind: its address
 * getters and a shipping line. The pickup point is NOT in it — it lives only in the session's
 * selection map, exactly as after the REST `select` route.
 *
 * @package Woodev\Tests\Unit\Shipping\Order
 */

namespace Woodev\Tests\Unit\Shipping\Order {

	use Brain\Monkey\Functions;
	use Woodev\Framework\Shipping\Checkout\Checkout_Fields;
	use Woodev\Framework\Shipping\Checkout\Checkout_Handler;
	use Woodev\Framework\Shipping\Checkout\Field;
	use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
	use Woodev\Framework\Shipping\Map\Map_Provider;
	use Woodev\Framework\Shipping\Order\Shipping_Order_Handler;
	use Woodev\Framework\Shipping\Pickup\Pickup_Handler;
	use Woodev\Framework\Shipping\Pickup\Pickup_Point;
	use Woodev\Framework\Shipping\Pickup\Pickup_Selection;
	use Woodev\Framework\Shipping\Pickup\Point_Source;
	use Woodev\Framework\Shipping\Pickup\Selection_Scope;
	use Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab;
	use Woodev\Tests\Unit\TestCase;

	require_once __DIR__ . '/order-persistence-fixtures.php';

	/**
	 * Array-backed `\WC_Session` stand-in — get()/set() only.
	 */
	final class Store_Api_Fake_Session {

		/** @var array<string, mixed> */
		public array $store = [];

		/**
		 * @param string $key     session key.
		 * @param mixed  $default fallback when the key is absent.
		 *
		 * @return mixed
		 */
		public function get( $key, $default = null ) {
			return $this->store[ $key ] ?? $default;
		}

		/**
		 * @param string $key   session key.
		 * @param mixed  $value value to store.
		 *
		 * @return void
		 */
		public function set( $key, $value ): void {
			$this->store[ $key ] = $value;
		}
	}

	/**
	 * {@see Pickup_Selection} reading the fake session instead of `WC()->session`.
	 */
	final class Store_Api_Selection extends Pickup_Selection {

		private Store_Api_Fake_Session $fake_session;

		public function __construct( Selection_Scope $scope, Store_Api_Fake_Session $fake_session ) {
			parent::__construct( $scope );
			$this->fake_session = $fake_session;
		}

		protected function session() {
			return $this->fake_session;
		}
	}

	/**
	 * One plugin's selection scope: `carrier_pickup` is its pickup method (type `PVZ`), every
	 * other method carries none; the customer's locality is fixed.
	 */
	final class Store_Api_Scope implements Selection_Scope {

		public function session_key(): string {
			return 'carrier_selection';
		}

		public function locality_for_point( Pickup_Point $point ): string {
			return 'msk';
		}

		public function current_locality(): string {
			return 'msk';
		}

		public function type_for_method( string $method_id ): ?string {
			return 'carrier_pickup' === $method_id ? 'PVZ' : null;
		}
	}

	/**
	 * {@see Pickup_Handler} whose selection map is backed by a fake session. The session's OWN
	 * `chosen_shipping_methods` is deliberately left unwired: the Store API path must read the
	 * method from the ORDER.
	 */
	final class Store_Api_Pickup_Handler extends Pickup_Handler {

		private Pickup_Selection $forced_selection;

		public function __construct(
			string $plugin_id,
			string $field_id,
			Point_Source $source,
			Map_Provider $map_provider,
			Shipping_Order_Handler $order_handler,
			Selection_Scope $scope,
			Pickup_Selection $selection
		) {
			parent::__construct(
				$plugin_id,
				$field_id,
				$source,
				$map_provider,
				[ 'center' => [ 55.75, 37.61 ], 'zoom' => 10 ],
				$order_handler,
				'pickup_full',
				[],
				'#000000',
				'',
				true,
				false,
				$scope
			);

			$this->forced_selection = $selection;
		}

		protected function selection(): ?Pickup_Selection {
			return $this->forced_selection;
		}
	}

	/**
	 * A shipping line, as far as the handlers read it.
	 */
	final class Store_Api_Shipping_Line {

		private string $method_id;

		public function __construct( string $method_id ) {
			$this->method_id = $method_id;
		}

		public function get_method_id(): string {
			return $this->method_id;
		}
	}

	/**
	 * What a Store API order looks like to the handlers: address getters and shipping lines.
	 */
	final class Store_Api_Fake_Order extends \WC_Order {

		/** @var array<int, Store_Api_Shipping_Line> */
		private array $lines;

		private string $city;

		private string $status;

		/**
		 * @param string[] $method_ids the shipping lines' bare method ids.
		 * @param string   $city       the billing city.
		 * @param string   $status     the order status; a Store API draft until payment is attempted.
		 */
		public function __construct( array $method_ids, string $city = 'Москва', string $status = 'checkout-draft' ) {
			$this->lines  = array_map( static fn( string $id ) => new Store_Api_Shipping_Line( $id ), $method_ids );
			$this->city   = $city;
			$this->status = $status;
		}

		/**
		 * @param string|string[] $status status(es) to compare with.
		 *
		 * @return bool
		 */
		public function has_status( $status ) {
			return in_array( $this->status, (array) $status, true );
		}

		/**
		 * @param string $type item type.
		 *
		 * @return array<int, Store_Api_Shipping_Line>
		 */
		public function get_items( $type = 'line_item' ) {
			return 'shipping' === $type ? $this->lines : [];
		}

		public function get_billing_city(): string {
			return $this->city;
		}
	}

	/**
	 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::handle_store_api_order_processed
	 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::handle_store_api_validate_order
	 * @covers \Woodev\Framework\Shipping\Pickup\Pickup_Handler::handle_store_api_order_processed
	 * @covers \Woodev\Framework\Shipping\Pickup\Pickup_Handler::contribute_store_api_posted_data
	 * @covers \Woodev\Framework\Shipping\Pickup\Pickup_Handler::persist_full_point
	 */
	class StoreApiOrderPersistenceTest extends TestCase {

		/**
		 * The ONE ordered log every meta write and hook lands in.
		 *
		 * @var array<int, array<int, mixed>>
		 */
		private array $events = [];

		private Store_Api_Fake_Session $session;

		/** @var array<int, Pickup_Handler> */
		private array $pickup_handlers = [];

		protected function setUp(): void {
			parent::setUp();

			$this->events          = [];
			$this->session         = new Store_Api_Fake_Session();
			$this->pickup_handlers = [];

			Functions\when( 'wc_clean' )->returnArg();
			Functions\when( 'wp_unslash' )->returnArg();
			Functions\when( 'sanitize_hex_color' )->returnArg();
			Functions\when( 'is_user_logged_in' )->justReturn( false );
			Functions\when( 'get_option' )->justReturn( null );
			Functions\when( 'wc_parse_relative_date_option' )->justReturn( [ 'number' => '', 'unit' => 'days' ] );
			Functions\when( 'wp_parse_args' )->alias(
				static function ( $args, $defaults = [] ) {
					return array_merge( (array) $defaults, (array) $args );
				}
			);
			Functions\when( 'update_post_meta' )->alias(
				function ( $id, $key, $value ) {
					$this->events[] = [ 'meta', $key, $value ];

					return true;
				}
			);
			Functions\when( 'do_action' )->alias(
				function ( string $hook, ...$args ) {
					$this->events[] = [ 'hook', $hook, $args ];
				}
			);
			// The real `apply_filters` semantics for the one filter under test: run every pickup
			// handler's contribution, in registration order, threading the value through.
			Functions\when( 'apply_filters' )->alias(
				function ( string $hook, $value, ...$args ) {
					if ( 'woodev_shipping_store_api_posted_data' === $hook ) {
						foreach ( $this->pickup_handlers as $handler ) {
							$value = $handler->contribute_store_api_posted_data( $value, ...$args );
						}
					}

					return $value;
				}
			);

			Location_Provider_Registry::instance()->reset_for_tests();
			Shipping_Settings_Tab::reset_for_tests();
		}

		protected function tearDown(): void {
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
		 * Wires one plugin exactly as its `register()` would, for the Store API: a
		 * `Checkout_Handler` (priority 10) and a `Pickup_Handler` (priority 20).
		 *
		 * @param string $prefix       hook prefix / plugin token.
		 * @param string $pickup_field the pickup-slot field id.
		 * @param string $full_key     the real meta key the full point lands under.
		 *
		 * @return array{0: Checkout_Handler, 1: Store_Api_Pickup_Handler}
		 */
		private function plugin( string $prefix, string $pickup_field, string $full_key ): array {
			$scope = new Store_Api_Scope();

			$pickup = new Store_Api_Pickup_Handler(
				$prefix,
				$pickup_field,
				new Order_Persistence_Test_Source( $this->point() ),
				new Order_Persistence_Test_Map_Provider(),
				new Shipping_Order_Handler( [ 'pickup_full' => $full_key ] ),
				$scope,
				new Store_Api_Selection( $scope, $this->session )
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
			$checkout->set_requires_pickup_methods( [ 'carrier_pickup' ] );

			$this->pickup_handlers[] = $pickup;

			return [ $checkout, $pickup ];
		}

		/**
		 * Remembers a point in the session the way the REST `select` route does.
		 *
		 * @return void
		 */
		private function customer_confirmed_a_point(): void {
			$selection = new Store_Api_Selection( new Store_Api_Scope(), $this->session );
			$selection->remember( 'msk', 'PVZ', 'P1', 'Москва' );
		}

		/**
		 * Fires `woocommerce_store_api_checkout_order_processed` for every plugin: all
		 * priority-10 callbacks first, then all priority-20 ones.
		 *
		 * @param array<int, array{0: Checkout_Handler, 1: Store_Api_Pickup_Handler}> $plugins the plugins.
		 * @param \WC_Order                                                           $order   the order.
		 *
		 * @return void
		 */
		private function fire_store_api_order_processed( array $plugins, \WC_Order $order ): void {
			foreach ( $plugins as [ $checkout ] ) {
				$checkout->handle_store_api_order_processed( $order );
			}

			foreach ( $plugins as [ , $pickup ] ) {
				$pickup->handle_store_api_order_processed( $order );
			}
		}

		/**
		 * The whole block-checkout story for a pickup order: the remembered point reaches the
		 * order as the field meta AND as the full point, the three checkout hooks fire with the
		 * classic payloads, and the selection map is emptied only afterwards.
		 */
		public function test_a_block_checkout_pickup_order_gets_the_point_the_classic_checkout_would_have_saved(): void {
			$this->customer_confirmed_a_point();

			$order  = new Store_Api_Fake_Order( [ 'carrier_pickup' ] );
			$plugin = $this->plugin( 'carrier', 'carrier_pickup_point', 'cdek_full_point' );

			$this->fire_store_api_order_processed( [ $plugin ], $order );

			$values = [
				'carrier_pickup_point' => 'P1',
				'carrier_comment'      => '',
				'billing_city'         => 'Москва',
			];

			$this->assertSame(
				[
					// Checkout_Handler, priority 10 — the same writes and hooks as classic.
					[ 'meta', 'carrier_pickup_point', 'P1' ],
					[ 'hook', 'woodev_shipping_carrier_checkout_field_saved', [ $order, 'carrier_pickup_point', 'P1' ] ],
					[ 'meta', 'carrier_comment', '' ],
					[ 'hook', 'woodev_shipping_carrier_checkout_field_saved', [ $order, 'carrier_comment', '' ] ],
					[ 'hook', 'woodev_shipping_carrier_checkout_data_saved', [ $order, $values ] ],
					[ 'hook', 'woodev_shipping_carrier_checkout_processed', [ $order, $values ] ],
					// Pickup_Handler, priority 20 — the full point under the plugin's own key.
					[ 'meta', 'cdek_full_point', $this->point()->to_array() ],
				],
				$this->events
			);

			$this->assertSame(
				[],
				$this->session->store['carrier_selection'],
				'the remembered selection is cleared once the order exists'
			);
		}

		/**
		 * The block checkout and the classic checkout must produce the SAME order data for the
		 * same customer input — the parity #963 exists for. Compared as the meta set and the
		 * checkout-hook sequence (the two paths run their handlers in a different order, so the
		 * pickup handler's write sits at a different position in the raw log).
		 */
		public function test_the_block_checkout_writes_the_same_metas_and_hooks_as_the_classic_one(): void {
			$this->customer_confirmed_a_point();

			// --- block checkout ---
			$order  = new Store_Api_Fake_Order( [ 'carrier_pickup' ] );
			$plugin = $this->plugin( 'carrier', 'carrier_pickup_point', 'cdek_full_point' );
			$this->fire_store_api_order_processed( [ $plugin ], $order );
			$block = $this->events;

			// --- classic checkout, same input ---
			$this->events = [];
			$_POST        = [
				'carrier_pickup_point' => 'P1',
				'carrier_comment'      => '',
				'billing_city'         => 'Москва',
				'billing_country'      => 'RU',
				'shipping_method'      => [ 'carrier_pickup:2' ],
			];
			[ $checkout, $pickup ] = $this->plugin( 'carrier', 'carrier_pickup_point', 'cdek_full_point' );
			$pickup->handle_checkout_order_processed( 123, [], $order );
			$checkout->handle_checkout_order_processed( 123, [], $order );
			$classic = $this->events;
			$_POST   = [];

			$metas  = static function ( array $log ): array {
				$out = array_map(
					static fn( array $event ) => [ $event[1], $event[2] ],
					array_values( array_filter( $log, static fn( array $event ) => 'meta' === $event[0] ) )
				);
				sort( $out );

				return $out;
			};
			$hooks  = static function ( array $log ): array {
				return array_values( array_filter( $log, static fn( array $event ) => 'hook' === $event[0] ) );
			};

			$this->assertSame( $metas( $classic ), $metas( $block ), 'the same meta set with the same values' );
			$this->assertSame( $hooks( $classic ), $hooks( $block ), 'the same hooks, in the same order, with the same payloads' );
		}

		/**
		 * A non-pickup order: the point remembered for an earlier pickup method is NOT applied
		 * (the order's method carries no pickup type), and no full point is stored.
		 */
		public function test_a_non_pickup_block_order_ignores_the_remembered_point(): void {
			$this->customer_confirmed_a_point();

			$order  = new Store_Api_Fake_Order( [ 'free_shipping' ], 'Казань' );
			$plugin = $this->plugin( 'carrier', 'carrier_pickup_point', 'cdek_full_point' );

			$this->fire_store_api_order_processed( [ $plugin ], $order );

			$keys = array_map(
				static fn( array $event ) => $event[1],
				array_filter( $this->events, static fn( array $event ) => 'meta' === $event[0] )
			);

			$this->assertSame( [ 'carrier_comment' ], array_values( $keys ), 'no pickup id, no full point' );
			$this->assertSame( 'Казань', $this->events[2][2][1]['billing_city'], 'the payload carries the order\'s own address' );
		}

		/**
		 * Two carrier plugins are active; the order was placed with plugin A's pickup method.
		 * Plugin B's handlers still run (like classic) but its scope names no type for that
		 * method, so nothing of A's point leaks into B's fields.
		 */
		public function test_a_second_plugin_does_not_pick_up_the_first_plugins_point(): void {
			$this->customer_confirmed_a_point();

			$order = new Store_Api_Fake_Order( [ 'carrier_pickup' ] );
			$a     = $this->plugin( 'carrier', 'carrier_pickup_point', 'cdek_full_point' );

			// Plugin B: its own session key, and its scope claims no method as a pickup one.
			$scope_b   = new class() implements Selection_Scope {
				public function session_key(): string {
					return 'other_selection';
				}

				public function locality_for_point( Pickup_Point $point ): string {
					return 'msk';
				}

				public function current_locality(): string {
					return 'msk';
				}

				public function type_for_method( string $method_id ): ?string {
					return null;
				}
			};
			$pickup_b  = new Store_Api_Pickup_Handler(
				'other',
				'other_point',
				new Order_Persistence_Test_Source( $this->point() ),
				new Order_Persistence_Test_Map_Provider(),
				new Shipping_Order_Handler( [ 'pickup_full' => 'other_full_point' ] ),
				$scope_b,
				new Store_Api_Selection( $scope_b, $this->session )
			);
			$checkout_b = new Checkout_Handler(
				Checkout_Fields::from_array( [ Field::create( 'other_point' )->mark_pickup_slot()->to_array() ] ),
				'other'
			);
			$checkout_b->set_requires_pickup_methods( [ 'other_pickup' ] );
			$this->pickup_handlers[] = $pickup_b;

			$this->fire_store_api_order_processed( [ $a, [ $checkout_b, $pickup_b ] ], $order );

			$metas = array_map(
				static fn( array $event ) => $event[1],
				array_values( array_filter( $this->events, static fn( array $event ) => 'meta' === $event[0] ) )
			);

			$this->assertContains( 'carrier_pickup_point', $metas );
			$this->assertContains( 'cdek_full_point', $metas );
			$this->assertNotContains( 'other_point', $metas, 'plugin B was not the chosen carrier' );
			$this->assertNotContains( 'other_full_point', $metas );
		}

		/**
		 * The pickup point was never confirmed: the pickup slot is written blank and no
		 * carrier lookup or full point happens. (Such an order is refused before it exists —
		 * see the `handle_store_api_validate_order()` tests below — so this pins the
		 * persistence tail on its own.)
		 */
		public function test_a_pickup_order_without_a_remembered_point_stores_no_full_point(): void {
			$order  = new Store_Api_Fake_Order( [ 'carrier_pickup' ] );
			$plugin = $this->plugin( 'carrier', 'carrier_pickup_point', 'cdek_full_point' );

			$this->fire_store_api_order_processed( [ $plugin ], $order );

			$keys = array_map(
				static fn( array $event ) => $event[1],
				array_values( array_filter( $this->events, static fn( array $event ) => 'meta' === $event[0] ) )
			);

			$this->assertSame( [ 'carrier_pickup_point', 'carrier_comment' ], $keys );
			$this->assertNotContains( 'cdek_full_point', $keys );
		}

		/**
		 * `contribute_store_api_posted_data()` leaves a foreign or malformed filter value alone
		 * and never overrides a field something else already supplied.
		 */
		public function test_the_posted_data_contribution_is_conservative(): void {
			$this->customer_confirmed_a_point();

			$order  = new Store_Api_Fake_Order( [ 'carrier_pickup' ] );
			[ , $pickup ] = $this->plugin( 'carrier', 'carrier_pickup_point', 'cdek_full_point' );

			$this->assertSame( 'not-an-array', $pickup->contribute_store_api_posted_data( 'not-an-array', $order ) );
			$this->assertSame(
				[ 'carrier_pickup_point' => 'SUPPLIED' ],
				$pickup->contribute_store_api_posted_data( [ 'carrier_pickup_point' => 'SUPPLIED' ], $order )
			);
			$this->assertSame(
				[ 'x' => 1, 'carrier_pickup_point' => 'P1' ],
				$pickup->contribute_store_api_posted_data( [ 'x' => 1 ], $order )
			);
		}

		/**
		 * Runs the block checkout's validate-before-payment hook the way the Store API does:
		 * every plugin's callback, in order, on ONE shared `WP_Error`.
		 *
		 * @param array<int, array{0: Checkout_Handler, 1: Store_Api_Pickup_Handler}> $plugins the plugins.
		 * @param \WC_Order                                                           $order   the order.
		 *
		 * @return \WP_Error the collection the Store API would turn into a refusal.
		 */
		private function fire_store_api_validate_order( array $plugins, \WC_Order $order ): \WP_Error {
			$errors = new \WP_Error();

			foreach ( $plugins as [ $checkout ] ) {
				$checkout->handle_store_api_validate_order( $order, $errors );
			}

			return $errors;
		}

		/**
		 * #966: a pickup method with no point confirmed is refused BEFORE the order is placed,
		 * with the same sentence the classic checkout shows for a blank pickup slot.
		 */
		public function test_a_block_checkout_pickup_order_without_a_point_is_refused(): void {
			$order  = new Store_Api_Fake_Order( [ 'carrier_pickup' ] );
			$plugin = $this->plugin( 'carrier', 'carrier_pickup_point', 'cdek_full_point' );

			$errors = $this->fire_store_api_validate_order( [ $plugin ], $order );

			$this->assertSame( [ 'You have not chosen a pickup point.' ], $errors->get_error_messages() );
			$this->assertSame( 'woodev_shipping_pickup_point_required', $errors->get_error_code() );
			$this->assertSame( [], $this->events, 'validation writes nothing and fires no persistence hook' );
		}

		/**
		 * The point the customer confirmed through the REST `select` route lives only in the
		 * session; validation must see it exactly as persistence does, or every valid pickup
		 * order would be refused.
		 */
		public function test_a_block_checkout_pickup_order_with_a_confirmed_point_passes(): void {
			$this->customer_confirmed_a_point();

			$order  = new Store_Api_Fake_Order( [ 'carrier_pickup' ] );
			$plugin = $this->plugin( 'carrier', 'carrier_pickup_point', 'cdek_full_point' );

			$errors = $this->fire_store_api_validate_order( [ $plugin ], $order );

			$this->assertFalse( $errors->has_errors() );
		}

		/**
		 * A method that is not a pickup method never needs a point — even when a point is
		 * absent (the common case), so a courier or free-shipping order is left alone.
		 */
		public function test_a_non_pickup_block_order_is_not_refused(): void {
			$order  = new Store_Api_Fake_Order( [ 'free_shipping' ] );
			$plugin = $this->plugin( 'carrier', 'carrier_pickup_point', 'cdek_full_point' );

			$this->assertFalse( $this->fire_store_api_validate_order( [ $plugin ], $order )->has_errors() );
		}

		/**
		 * `woocommerce_checkout_validate_order_before_payment` also fires for a pay-for-order
		 * request against an EXISTING order: its point was persisted when it was placed and is
		 * no longer in the session, so only a Store API draft is checked.
		 */
		public function test_an_existing_order_being_paid_for_is_not_refused(): void {
			$order  = new Store_Api_Fake_Order( [ 'carrier_pickup' ], 'Москва', 'pending' );
			$plugin = $this->plugin( 'carrier', 'carrier_pickup_point', 'cdek_full_point' );

			$this->assertFalse( $this->fire_store_api_validate_order( [ $plugin ], $order )->has_errors() );
		}

		/**
		 * Two carrier plugins are active and both handlers see the same pickup order: the
		 * buyer gets the sentence once, not once per plugin.
		 */
		public function test_two_plugins_refuse_a_pickup_order_with_one_message(): void {
			$a = $this->plugin( 'carrier', 'carrier_pickup_point', 'cdek_full_point' );
			$b = $this->plugin( 'other', 'other_pickup_point', 'other_full_point' );

			$errors = $this->fire_store_api_validate_order( [ $a, $b ], new Store_Api_Fake_Order( [ 'carrier_pickup' ] ) );

			$this->assertSame( [ 'You have not chosen a pickup point.' ], $errors->get_error_messages() );
		}

		/**
		 * A plugin-supplied whole-sentence override wins on the block checkout too — it is a
		 * statement about the field, not about which checkout path caught it (#327).
		 */
		public function test_a_plugin_supplied_required_message_is_used(): void {
			$handler = new Checkout_Handler(
				Checkout_Fields::from_array(
					[
						Field::create( 'post_office' )->mark_pickup_slot()->set_required_message( 'Выберите отделение.' )->to_array(),
					]
				),
				'post'
			);
			$handler->set_requires_pickup_methods( [ 'carrier_pickup' ] );

			$errors = $this->fire_store_api_validate_order( [ [ $handler ] ], new Store_Api_Fake_Order( [ 'carrier_pickup' ] ) );

			$this->assertSame( [ 'Выберите отделение.' ], $errors->get_error_messages() );
		}

		/**
		 * An explicit `set_requires_pickup_methods( [] )` turns the backstop off on both
		 * paths (the classic `validate()` honours it the same way).
		 */
		public function test_an_explicitly_empty_pickup_method_list_disables_the_refusal(): void {
			[ $checkout ] = $this->plugin( 'carrier', 'carrier_pickup_point', 'cdek_full_point' );
			$checkout->set_requires_pickup_methods( [] );

			$errors = $this->fire_store_api_validate_order( [ [ $checkout ] ], new Store_Api_Fake_Order( [ 'carrier_pickup' ] ) );

			$this->assertFalse( $errors->has_errors() );
		}
	}
}
