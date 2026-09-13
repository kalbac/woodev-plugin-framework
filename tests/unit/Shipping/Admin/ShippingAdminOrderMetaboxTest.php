<?php
/**
 * Unit: Shipping_Admin_Order — the order-edit metabox rehung onto the v2
 * Orders_Provider contract (card #856).
 *
 * Proves the four behaviours the card's gate depends on:
 *  - the framework registers the metabox only when the order matches a
 *    registered provider (nobody else constructs this class any more);
 *  - the two states switch on the SAME `is_exported` {@see Order_Row_Builder}
 *    computes for the «Заказы доставки» table/REST rows;
 *  - a field the carrier did not supply is simply absent — never a dash
 *    (KISS, operator, #856);
 *  - the delivery history renders by default, through the framework's own
 *    renderer, when nothing hooked `{prefix}_tracking_admin_display` to
 *    replace it.
 *
 * Round 2 (#856, critic DO NOT MERGE finding): `perform_action()` — the
 * `handle_order_action()` sibling actually reached by a posted action, minus
 * the trailing `wp_safe_redirect()` + `exit` that makes `handle_order_action()`
 * itself unsafe to invoke from a unit test (same reasoning as
 * ShippingAdminOrderPopularSettlementContextTest) — must refuse an action the
 * shared {@see Order_Actions::for_order()} gate does not offer instead of
 * dispatching it straight to the carrier handler, and must classify the
 * handler's outcome (empty export id, thrown exception) as a failure instead
 * of redirecting as though it succeeded.
 *
 * @package Woodev\Tests\Unit\Shipping\Admin
 */

namespace Woodev\Tests\Unit\Shipping\Admin {

	use Brain\Monkey\Functions;
	use Mockery;
	use Woodev\Framework\Shipping\Admin\Orders\Order_Actions;
	use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
	use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
	use Woodev\Framework\Shipping\Admin\Shipping_Admin_Order;
	use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;
	use Woodev\Framework\Shipping\Order\Abstract_Tracking_Handler;
	use Woodev\Tests\Unit\TestCase;

	require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/class-shipping-helper.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-base.php';

	/**
	 * @covers \Woodev\Framework\Shipping\Admin\Shipping_Admin_Order
	 */
	final class ShippingAdminOrderMetaboxTest extends TestCase {

		/** @var array<string,mixed> post meta, keyed by meta key, for the active test. */
		private $meta = [];

		/** @var array<int,string> messages passed to `set_transient()`, once {@see self::capture_flashed_notices()} stubs it. */
		private $flashed_notices = [];

		protected function setUp(): void {
			parent::setUp();

			Orders_Registry::instance()->reset_for_tests();

			$this->meta = [];

			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'get_post_meta' )->alias(
				function ( int $post_id, string $key, bool $single ) {
					return $this->meta[ $key ] ?? '';
				}
			);
			Functions\when( 'wc_price' )->alias( static function ( $amount ): string {
				return (string) $amount;
			} );
			Functions\when( 'wp_strip_all_tags' )->alias(
				static function ( string $text, bool $remove_breaks = false ): string {
					return trim( strip_tags( $text ) );
				}
			);
			Functions\when( 'get_edit_user_link' )->alias( static function ( int $user_id ): string {
				return "https://example.test/wp-admin/user-edit.php?user_id={$user_id}";
			} );
			Functions\when( 'wc_get_order_status_name' )->alias( static function ( string $status ): string {
				return ucfirst( $status );
			} );

			// Metabox-specific WP surface.
			Functions\when( 'add_meta_box' )->justReturn( null );
			Functions\when( 'wp_nonce_field' )->justReturn( '' );
			Functions\when( 'admin_url' )->alias( static function ( string $path = '' ): string {
				return 'https://example.test/wp-admin/' . $path;
			} );
			Functions\when( 'has_action' )->justReturn( false );
			Functions\when( 'wp_date' )->alias( static function ( string $format, int $timestamp ): string {
				return gmdate( $format, $timestamp );
			} );
		}

		protected function tearDown(): void {
			Orders_Registry::instance()->reset_for_tests();

			parent::tearDown();
		}

		/**
		 * Builds a WC_Order double with sane defaults, overridable per test.
		 * Mirrors OrderRowBuilderTest's fixture — Order_Row_Builder::build() (which
		 * render_metabox() calls) needs every one of these regardless of which
		 * field the test actually inspects.
		 *
		 * @param array<string,mixed> $overrides getter name => return value.
		 * @return \WC_Order
		 */
		private function make_order( array $overrides = [] ): \WC_Order {
			$created = Mockery::mock( '\WC_DateTime' );
			$created->shouldReceive( 'date' )->with( \DATE_ATOM )->andReturn( '2026-09-07T12:00:00+00:00' );

			$defaults = [
				'get_id'                           => 123,
				'get_order_number'                 => '123',
				'get_edit_order_url'                => 'https://example.test/wp-admin/post.php?post=123&action=edit',
				'get_status'                        => 'processing',
				'get_date_created'                 => $created,
				'get_formatted_billing_full_name'  => 'Иван Иванов',
				'get_customer_id'                   => 0,
				'get_billing_email'                 => 'ivan@example.test',
				'get_billing_phone'                 => '+79991234567',
				'get_payment_method_title'           => 'Банковская карта',
				'get_formatted_order_total'          => '1000 руб.',
				'needs_payment'                      => false,
				'get_shipping_method'                => 'СДЭК',
				'get_shipping_total'                 => '300',
				'get_shipping_postcode'              => '',
				'get_shipping_state'                 => '',
				'get_shipping_city'                  => '',
				'get_shipping_address_1'             => '',
				'get_billing_postcode'               => '',
				'get_billing_state'                  => '',
				'get_billing_city'                   => '',
				'get_billing_address_1'              => '',
				'get_shipping_methods'               => [],
			];

			$values = array_merge( $defaults, $overrides );

			$order = Mockery::mock( '\WC_Order' );
			foreach ( $values as $method => $value ) {
				$order->shouldReceive( $method )->andReturn( $value );
			}

			return $order;
		}

		private function provider( array $args = [] ): Orders_Provider {
			return Orders_Provider::create(
				'cdek',
				'СДЭК',
				'_wc_cdek_marker',
				[ 'flat_rate' ],
				array_merge(
					[
						'carrier_order_id_meta_key' => '_wc_cdek_order_id',
						'tracking_meta_key'         => '_wc_cdek_tracking',
					],
					$args
				)
			);
		}

		// -----------------------------------------------------------------------
		// add_meta_box() — the framework builds it only when a provider matches.
		// -----------------------------------------------------------------------

		public function test_add_meta_box_does_nothing_when_no_provider_matches_the_order(): void {
			$registry = Mockery::mock( Orders_Registry::class );
			$registry->shouldReceive( 'resolve_provider_for_order' )->once()->andReturn( null );

			Functions\expect( 'add_meta_box' )->never();

			( new Shipping_Admin_Order( $registry ) )->add_meta_box( 'shop_order', $this->make_order() );
		}

		public function test_add_meta_box_registers_the_metabox_with_the_carrier_label_in_the_title(): void {
			$provider = $this->provider();

			$registry = Mockery::mock( Orders_Registry::class );
			$registry->shouldReceive( 'resolve_provider_for_order' )->once()->andReturn( $provider );

			$captured_title = null;
			Functions\when( 'add_meta_box' )->alias(
				static function ( $id, $title, $callback, $post_type, $context, $priority ) use ( &$captured_title ) {
					$captured_title = $title;
				}
			);

			( new Shipping_Admin_Order( $registry ) )->add_meta_box( 'shop_order', $this->make_order() );

			$this->assertSame( 'Информация СДЭК', $captured_title );
		}

		// -----------------------------------------------------------------------
		// render_metabox() — two states on the SAME is_exported, KISS field list.
		// -----------------------------------------------------------------------

		public function test_render_metabox_shows_info_text_and_no_field_table_when_not_exported(): void {
			$provider = $this->provider();
			$order    = $this->make_order();

			// carrier_order_id meta absent => Order_Row_Builder/Order_Actions both
			// resolve is_exported => false, the same source the table itself reads.
			$this->meta = [];

			ob_start();
			( new Shipping_Admin_Order( Orders_Registry::instance() ) )->render_metabox( $order, $provider );
			$html = ob_get_clean();

			$this->assertStringContainsString( 'СДЭК', $html );
			$this->assertStringNotContainsString( 'widefat', $html, 'the fields table must not render before export' );
		}

		public function test_render_metabox_omits_an_unsupplied_field_without_a_dash_when_exported(): void {
			$provider = $this->provider();
			$order    = $this->make_order();

			$this->meta = [
				'_wc_cdek_order_id' => 'CDEK-999', // present => is_exported = true.
				'_wc_cdek_tracking' => '',         // absent => KISS: no row, not a dash.
			];

			ob_start();
			( new Shipping_Admin_Order( Orders_Registry::instance() ) )->render_metabox( $order, $provider );
			$html = ob_get_clean();

			$this->assertStringContainsString( 'ID заказа у перевозчика', $html );
			$this->assertStringContainsString( 'CDEK-999', $html );
			$this->assertStringNotContainsString( 'Трек-номер', $html, 'a field the carrier did not supply must be absent entirely' );
			$this->assertStringNotContainsString( '&ndash;', $html, 'KISS: an absent field is omitted, never rendered as a dash' );
		}

		public function test_render_metabox_renders_the_default_delivery_history_when_nothing_replaces_it(): void {
			$provider = $this->provider();
			$order    = $this->make_order();

			$this->meta = [
				'_wc_cdek_order_id' => 'CDEK-999',
				'_wc_cdek_tracking' => 'TRACK-1',
			];

			$tracking_handler = Mockery::mock( Abstract_Tracking_Handler::class );
			$tracking_handler->shouldReceive( 'get_admin_display_hook' )->andReturn( 'woodev_shipping_cdek_tracking_admin_display' );
			$tracking_handler->shouldReceive( 'get_history' )->with( 'TRACK-1' )->andReturn(
				[
					[
						'status'      => 'in_transit',
						'description' => 'Принят в отделении',
						'timestamp'   => 1700000000,
						'location'    => 'Москва',
					],
				]
			);

			Orders_Registry::instance()->register_tracking_handler( 'cdek', $tracking_handler );

			ob_start();
			( new Shipping_Admin_Order( Orders_Registry::instance() ) )->render_metabox( $order, $provider );
			$html = ob_get_clean();

			$this->assertStringContainsString( 'Принят в отделении', $html );
			$this->assertStringContainsString( 'Москва', $html );
		}

		public function test_render_metabox_defers_entirely_to_a_plugin_that_replaces_the_history_output(): void {
			$provider = $this->provider();
			$order    = $this->make_order();

			$this->meta = [
				'_wc_cdek_order_id' => 'CDEK-999',
				'_wc_cdek_tracking' => 'TRACK-1',
			];

			$tracking_handler = Mockery::mock( Abstract_Tracking_Handler::class );
			$tracking_handler->shouldReceive( 'get_admin_display_hook' )->andReturn( 'woodev_shipping_cdek_tracking_admin_display' );
			$tracking_handler->shouldReceive( 'get_history' )->never();
			$tracking_handler->shouldReceive( 'display_admin' )->once()->with( $order, 'TRACK-1' )->andReturnUsing(
				static function () {
					echo 'PLUGIN-REPLACED-HISTORY';
				}
			);

			Orders_Registry::instance()->register_tracking_handler( 'cdek', $tracking_handler );

			Functions\when( 'has_action' )->justReturn( true );

			ob_start();
			( new Shipping_Admin_Order( Orders_Registry::instance() ) )->render_metabox( $order, $provider );
			$html = ob_get_clean();

			$this->assertStringContainsString( 'PLUGIN-REPLACED-HISTORY', $html );
		}

		// -----------------------------------------------------------------------
		// perform_action() — round 2 (#856): never trusts the posted action, and
		// classifies the handler's outcome instead of assuming success.
		//
		// Invoked via reflection, never through handle_order_action(): that public
		// method ends in wp_safe_redirect()+exit (a real WP admin-post handler),
		// which would kill the test process — the same reasoning
		// ShippingAdminOrderPopularSettlementContextTest documents for
		// resolve_popular_settlement_context().
		// -----------------------------------------------------------------------

		/**
		 * Registers a fake shipment handler for 'cdek', so `Order_Actions::for_order()`
		 * (and thus the gate `perform_action()` recomputes) gets past its own
		 * "no handler registered" case.
		 */
		private function register_handler( bool $supports_update = false ): Abstract_Shipment_Handler {
			$handler = Mockery::mock( Abstract_Shipment_Handler::class );
			$handler->shouldReceive( 'supports_update' )->andReturn( $supports_update );

			Orders_Registry::instance()->register_shipment_handler( 'cdek', $handler );

			return $handler;
		}

		/**
		 * Invokes the private perform_action() — see the class docblock for why
		 * this is reflection rather than a call through handle_order_action().
		 */
		private function invoke_perform_action( Shipping_Admin_Order $admin_order, Abstract_Shipment_Handler $handler, \WC_Order $order, string $action, Orders_Provider $provider ): void {
			$method = new \ReflectionMethod( Shipping_Admin_Order::class, 'perform_action' );
			if ( PHP_VERSION_ID < 80100 ) {
				$method->setAccessible( true );
			}

			$method->invoke( $admin_order, $handler, $order, $action, $provider );
		}

		/**
		 * Stubs `get_current_user_id()` + `set_transient()`, so every flashed
		 * message lands in {@see self::$flashed_notices} — an instance property
		 * rather than a local, since a plain array returned by value would not
		 * reflect calls the stubbed closure makes AFTER the return.
		 */
		private function capture_flashed_notices(): void {
			$this->flashed_notices = [];

			Functions\when( 'get_current_user_id' )->justReturn( 7 );
			Functions\when( 'set_transient' )->alias(
				function ( string $key, $value, int $expiration ): bool {
					$this->flashed_notices[] = $value;
					return true;
				}
			);
		}

		public function test_perform_action_refuses_an_action_the_shared_gate_does_not_offer(): void {
			$provider = $this->provider();
			$handler  = $this->register_handler();

			// Never exported (no carrier_order_id meta) => for_order() offers only
			// EXPORT, never CANCEL — the same gate REST's perform_action() recomputes.
			$order = $this->make_order( [ 'get_status' => 'processing' ] );

			$this->capture_flashed_notices();

			// The carrier handler must never be reached — no expectation is set on
			// cancel(), so Mockery fails the test loudly if it is.
			$this->invoke_perform_action( new Shipping_Admin_Order( Orders_Registry::instance() ), $handler, $order, Order_Actions::CANCEL, $provider );

			$this->assertNotEmpty( $this->flashed_notices, 'a refused action must flash a notice for the merchant' );
			$this->assertStringContainsString( 'выгружен', $this->flashed_notices[0], 'the flashed reason must be Order_Actions::unavailable_reason()\'s own sentence' );
		}

		public function test_perform_action_reports_an_empty_export_result_as_a_failure_not_a_success(): void {
			$provider = $this->provider();
			$handler  = $this->register_handler();
			$handler->shouldReceive( 'export' )->once()->andReturn( '' );

			// Exportable status, not yet exported => EXPORT is offered.
			$order = $this->make_order( [ 'get_status' => 'processing' ] );

			$this->capture_flashed_notices();

			$this->invoke_perform_action( new Shipping_Admin_Order( Orders_Registry::instance() ), $handler, $order, Order_Actions::EXPORT, $provider );

			$this->assertSame( [ 'Не удалось выгрузить заказ перевозчику.' ], $this->flashed_notices );
		}

		public function test_perform_action_catches_a_thrown_carrier_exception_and_flashes_a_notice_instead_of_a_fatal(): void {
			$provider = $this->provider();
			$handler  = $this->register_handler();
			$handler->shouldReceive( 'cancel' )->once()->andThrow( new \RuntimeException( 'carrier gateway timed out' ) );

			$this->meta['_wc_cdek_order_id'] = 'CARRIER-1'; // exported => CANCEL is offered.
			$order                            = $this->make_order( [ 'get_status' => 'processing' ] );

			$this->capture_flashed_notices();

			$logged = null;
			Functions\expect( 'error_log' )->once()->with(
				Mockery::on(
					static function ( $message ) use ( &$logged ) {
						$logged = $message;
						return true;
					}
				)
			);

			// Must not throw out of perform_action() — the exception is caught.
			$this->invoke_perform_action( new Shipping_Admin_Order( Orders_Registry::instance() ), $handler, $order, Order_Actions::CANCEL, $provider );

			$this->assertSame(
				[ 'Сервис перевозчика временно недоступен. Попробуйте повторить действие позже.' ],
				$this->flashed_notices
			);
			$this->assertStringContainsString( 'carrier gateway timed out', $logged );
		}

		public function test_perform_action_happy_path_dispatches_and_flashes_nothing(): void {
			$provider = $this->provider();
			$handler  = $this->register_handler();
			$handler->shouldReceive( 'cancel' )->once()->andReturn( true );

			$this->meta['_wc_cdek_order_id'] = 'CARRIER-1'; // exported => CANCEL is offered.
			$order                            = $this->make_order( [ 'get_status' => 'processing' ] );

			Functions\expect( 'set_transient' )->never();

			$this->invoke_perform_action( new Shipping_Admin_Order( Orders_Registry::instance() ), $handler, $order, Order_Actions::CANCEL, $provider );
		}
	}
}
