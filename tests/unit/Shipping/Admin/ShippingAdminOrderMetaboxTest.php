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
 * itself unsafe to invoke from a unit test — must refuse an action the
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
	use Woodev\Framework\Shipping\Order\Action_Result;
	use Woodev\Tests\Unit\TestCase;

	require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/class-shipping-helper.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-base.php';
	require_once __DIR__ . '/order-edit-lock-fixtures.php';
	require_once __DIR__ . '/order-edit-lock-cpt-fixtures.php';

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
			\Automattic\WooCommerce\Internal\Admin\Orders\EditLock::$locks = [];

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
			Functions\when( 'wp_create_nonce' )->justReturn( 'nonce-123' );
			Functions\when( 'rest_url' )->alias( static function ( string $path = '' ): string {
				return 'https://example.test/wp-json/' . $path;
			} );
			Functions\when( 'admin_url' )->alias( static function ( string $path = '' ): string {
				return 'https://example.test/wp-admin/' . $path;
			} );
			Functions\when( 'has_action' )->justReturn( false );
			Functions\when( 'disabled' )->alias( static function ( $disabled, $current = true, bool $display = true ): string {
				$attribute = (string) $disabled === (string) $current ? " disabled='disabled'" : '';
				if ( $display ) {
					echo $attribute; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test double.
				}
				return $attribute;
			} );
			Functions\when( 'wp_date' )->alias( static function ( string $format, int $timestamp ): string {
				return gmdate( $format, $timestamp );
			} );
		}

		protected function tearDown(): void {
			Orders_Registry::instance()->reset_for_tests();
			\Automattic\WooCommerce\Internal\Admin\Orders\EditLock::$locks = [];

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
			$registry->shouldReceive( 'enqueue_metabox_style' )->once();
			$registry->shouldReceive( 'enqueue_metabox_script' )->once();

			$captured_title = null;
			Functions\when( 'add_meta_box' )->alias(
				static function ( $id, $title, $callback, $post_type, $context, $priority ) use ( &$captured_title ) {
					$captured_title = $title;
				}
			);

			( new Shipping_Admin_Order( $registry ) )->add_meta_box( 'shop_order', $this->make_order() );

			$this->assertSame( 'Информация СДЭК', $captured_title );
		}

		/**
		 * #947: the box sits in the sidebar's `high` band, right under WooCommerce's «Order actions»
		 * (`side`/`high`) instead of at the bottom of the `default` band.
		 */
		public function test_add_meta_box_registers_in_the_side_high_band_under_order_actions(): void {
			$registry = Mockery::mock( Orders_Registry::class );
			$registry->shouldReceive( 'resolve_provider_for_order' )->once()->andReturn( $this->provider() );
			$registry->shouldReceive( 'enqueue_metabox_style' )->once();
			$registry->shouldReceive( 'enqueue_metabox_script' )->once();

			$captured = [];
			Functions\when( 'add_meta_box' )->alias(
				static function ( $id, $title, $callback, $screen, $context, $priority ) use ( &$captured ) {
					$captured = [ $id, $screen, $context, $priority ];
				}
			);

			( new Shipping_Admin_Order( $registry ) )->add_meta_box( 'shop_order', $this->make_order() );

			$this->assertSame( [ 'woodev_shipping_order', 'shop_order', 'side', 'high' ], $captured );
			// WC's legacy `add_meta_boxes` callback adds «Order actions» at priority 30 — ours must be later.
			$this->assertGreaterThan( 30, Shipping_Admin_Order::METABOX_HOOK_PRIORITY );
		}

		/**
		 * @param array<string,mixed> $boxes
		 * @return array<string,mixed>
		 */
		private function move_box_after( array $boxes, string $moved, string $anchor ): array {
			$method = new \ReflectionMethod( Shipping_Admin_Order::class, 'move_box_after' );

			// The framework supports PHP 7.4, where a private method is only invocable once made accessible.
			if ( PHP_VERSION_ID < 80100 ) {
				$method->setAccessible( true );
			}

			return $method->invoke( null, $boxes, $moved, $anchor );
		}

		/**
		 * #947: WC registers «Order actions», «Order attribution» and «Customer history» as side/high
		 * before `add_meta_boxes` fires, so our box lands behind them — it must be moved right under
		 * «Order actions», the others keeping their relative order.
		 */
		public function test_move_box_after_places_the_box_directly_under_the_anchor_and_keeps_the_rest_in_order(): void {
			$boxes = [
				'woocommerce-order-actions'     => [ 'a' ],
				'woocommerce-order-source-data' => [ 'b' ],
				'woocommerce-customer-history'  => [ 'c' ],
				'woodev_shipping_order'         => [ 'ours' ],
				'woocommerce-order-notes'       => [ 'n' ],
			];

			$result = $this->move_box_after( $boxes, 'woodev_shipping_order', 'woocommerce-order-actions' );

			$this->assertSame(
				[
					'woocommerce-order-actions',
					'woodev_shipping_order',
					'woocommerce-order-source-data',
					'woocommerce-customer-history',
					'woocommerce-order-notes',
				],
				array_keys( $result )
			);
			$this->assertSame( [ 'ours' ], $result['woodev_shipping_order'] );
		}

		public function test_move_box_after_leaves_the_band_alone_when_order_actions_is_absent(): void {
			$boxes = [
				'woocommerce-customer-history' => [ 'c' ],
				'woodev_shipping_order'        => [ 'ours' ],
			];

			$this->assertSame( $boxes, $this->move_box_after( $boxes, 'woodev_shipping_order', 'woocommerce-order-actions' ) );
		}

		public function test_move_box_after_leaves_the_band_alone_when_our_box_is_absent(): void {
			$boxes = [
				'woocommerce-order-actions'    => [ 'a' ],
				'woocommerce-customer-history' => [ 'c' ],
			];

			$this->assertSame( $boxes, $this->move_box_after( $boxes, 'woodev_shipping_order', 'woocommerce-order-actions' ) );
		}

		public function test_move_box_after_is_a_noop_when_already_directly_under_the_anchor(): void {
			$boxes = [
				'woocommerce-order-actions'    => [ 'a' ],
				'woodev_shipping_order'        => [ 'ours' ],
				'woocommerce-customer-history' => [ 'c' ],
			];

			$this->assertSame( $boxes, $this->move_box_after( $boxes, 'woodev_shipping_order', 'woocommerce-order-actions' ) );
		}

		public function test_add_meta_box_reorders_the_registered_side_high_band_under_order_actions(): void {
			$registry = Mockery::mock( Orders_Registry::class );
			$registry->shouldReceive( 'resolve_provider_for_order' )->once()->andReturn( $this->provider() );
			$registry->shouldReceive( 'enqueue_metabox_style' )->once();
			$registry->shouldReceive( 'enqueue_metabox_script' )->once();

			$previous = $GLOBALS['wp_meta_boxes'] ?? null;

			// Stand-in for WP: the real add_meta_box() appends to the band, behind WC's three.
			$GLOBALS['wp_meta_boxes']['shop_order']['side']['high'] = [
				'woocommerce-order-actions'    => [ 'a' ],
				'woocommerce-customer-history' => [ 'c' ],
			];
			Functions\when( 'add_meta_box' )->alias(
				static function ( $id, $title, $callback, $screen, $context, $priority ) {
					$GLOBALS['wp_meta_boxes'][ $screen ][ $context ][ $priority ][ $id ] = [ 'ours' ];
				}
			);

			try {
				( new Shipping_Admin_Order( $registry ) )->add_meta_box( 'shop_order', $this->make_order() );

				$this->assertSame(
					[ 'woocommerce-order-actions', 'woodev_shipping_order', 'woocommerce-customer-history' ],
					array_keys( $GLOBALS['wp_meta_boxes']['shop_order']['side']['high'] )
				);
			} finally {
				if ( null === $previous ) {
					unset( $GLOBALS['wp_meta_boxes'] );
				} else {
					$GLOBALS['wp_meta_boxes'] = $previous;
				}
			}
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
			$this->assertStringNotContainsString( 'История доставки', $html, 'no tracking number must quietly omit the history section' );
			$this->assertStringNotContainsString( '&ndash;', $html, 'KISS: an absent field is omitted, never rendered as a dash' );
		}

		/**
		 * The getters the shipment fingerprint reads (#947), on top of what {@see self::make_order()} gives.
		 *
		 * @param array<string,mixed> $overrides getter name => value
		 * @return array<string,mixed>
		 */
		private function fingerprint_getters( array $overrides = [] ): array {
			return array_merge(
				[
					'get_items'               => [],
					'get_total'               => '1300.00',
					'get_shipping_first_name' => 'Иван',
					'get_shipping_last_name'  => 'Иванов',
					'get_billing_first_name'  => 'Иван',
					'get_billing_last_name'   => 'Иванов',
					'get_shipping_phone'      => '+79991234567',
					'get_shipping_country'    => 'RU',
					'get_shipping_address_2'  => '',
					'get_billing_country'     => 'RU',
					'get_billing_address_2'   => '',
					'get_shipping_city'       => 'Москва',
					'get_shipping_address_1'  => 'ул. Тверская, 1',
					'get_shipping_postcode'   => '101000',
				],
				$overrides
			);
		}

		/** @return string the Russian warning of #947 */
		private function outdated_warning(): string {
			return 'Заказ изменён после передачи в службу доставки';
		}

		public function test_render_metabox_warns_when_the_order_changed_after_the_export(): void {
			Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );
			Functions\when( 'wp_json_encode' )->alias( static fn( $data, int $flags = 0 ) => json_encode( $data, $flags ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test double.

			$provider = $this->provider();

			$this->meta = [
				'_wc_cdek_order_id'                              => 'CDEK-999',
				\Woodev\Framework\Shipping\Order\Shipment_Fingerprint::META => \Woodev\Framework\Shipping\Order\Shipment_Fingerprint::for_order( $this->make_order( $this->fingerprint_getters() ) ),
			];

			ob_start();
			( new Shipping_Admin_Order( Orders_Registry::instance() ) )->render_metabox( $this->make_order( $this->fingerprint_getters( [ 'get_shipping_city' => 'Тверь' ] ) ), $provider );
			$html = ob_get_clean();

			$this->assertStringContainsString( $this->outdated_warning(), $html );
			$this->assertStringContainsString( 'notice-warning', $html, 'the warning reuses the admin notice styling' );
			$this->assertStringNotContainsString( 'data-woodev-order-action="export"', $html, 'a warning, not a «send again» button' );
		}

		public function test_render_metabox_does_not_warn_when_the_order_is_as_it_was_exported(): void {
			Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );
			Functions\when( 'wp_json_encode' )->alias( static fn( $data, int $flags = 0 ) => json_encode( $data, $flags ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test double.

			$provider = $this->provider();

			$this->meta = [
				'_wc_cdek_order_id'                              => 'CDEK-999',
				\Woodev\Framework\Shipping\Order\Shipment_Fingerprint::META => \Woodev\Framework\Shipping\Order\Shipment_Fingerprint::for_order( $this->make_order( $this->fingerprint_getters() ) ),
			];

			ob_start();
			( new Shipping_Admin_Order( Orders_Registry::instance() ) )->render_metabox( $this->make_order( $this->fingerprint_getters() ), $provider );
			$html = ob_get_clean();

			$this->assertStringContainsString( 'CDEK-999', $html );
			$this->assertStringNotContainsString( $this->outdated_warning(), $html );
		}

		public function test_render_metabox_stays_quiet_for_an_order_with_no_fingerprint_or_one_of_another_version(): void {
			$provider = $this->provider();

			foreach ( [ null, 'v0:' . str_repeat( 'a', 64 ) ] as $stored ) {
				$this->meta = [ '_wc_cdek_order_id' => 'CDEK-999' ];

				if ( null !== $stored ) {
					$this->meta[ \Woodev\Framework\Shipping\Order\Shipment_Fingerprint::META ] = $stored;
				}

				ob_start();
				( new Shipping_Admin_Order( Orders_Registry::instance() ) )->render_metabox( $this->make_order(), $provider );
				$html = ob_get_clean();

				$this->assertStringNotContainsString( $this->outdated_warning(), $html, 'nothing to compare with is «unknown», never «changed»' );
			}
		}

		public function test_render_metabox_never_warns_about_an_order_that_is_not_exported(): void {
			$provider = $this->provider();

			$this->meta = [ \Woodev\Framework\Shipping\Order\Shipment_Fingerprint::META => 'v1:' . str_repeat( 'a', 64 ) ];

			ob_start();
			( new Shipping_Admin_Order( Orders_Registry::instance() ) )->render_metabox( $this->make_order(), $provider );
			$html = ob_get_clean();

			$this->assertStringNotContainsString( $this->outdated_warning(), $html );
		}

		public function test_render_metabox_disables_the_button_of_an_order_another_manager_is_editing(): void {
			$provider = $this->provider();
			$order    = $this->make_order();

			$this->meta = [];
			$this->register_handler();

			\Automattic\WooCommerce\Internal\Admin\Orders\EditLock::$locks[123] = [ 'time' => time(), 'user_id' => 7 ];
			$user               = new \stdClass();
			$user->ID           = 7;
			$user->display_name = 'Мария';
			Functions\when( 'get_user_by' )->justReturn( $user );

			ob_start();
			( new Shipping_Admin_Order( Orders_Registry::instance() ) )->render_metabox( $order, $provider );
			$html = ob_get_clean();

			$this->assertMatchesRegularExpression( '/<button[^>]*title="[^"]*Мария[^"]*"[^>]*disabled=\'disabled\'/s', $html, 'the locked button is disabled and carries the lock reason' );
		}

		public function test_render_metabox_keeps_the_button_live_when_nobody_else_holds_the_lock(): void {
			$provider = $this->provider();
			$order    = $this->make_order();

			$this->meta = [];
			$this->register_handler();

			ob_start();
			( new Shipping_Admin_Order( Orders_Registry::instance() ) )->render_metabox( $order, $provider );
			$html = ob_get_clean();

			$this->assertStringContainsString( 'data-woodev-order-action="export"', $html );
			$this->assertStringNotContainsString( "disabled='disabled'", $html );
		}

		/**
		 * #1012: the metabox sits INSIDE WooCommerce's order form; a nested `<form>` closed the outer one
		 * and the status select stopped being submitted. No form tag may survive anywhere in the output.
		 */
		public function test_render_metabox_contains_no_form_element(): void {
			$provider = $this->provider();
			$order    = $this->make_order();

			$this->meta['_wc_cdek_order_id'] = 'CARRIER-1'; // exported => several buttons.
			$this->register_handler( true );

			ob_start();
			( new Shipping_Admin_Order( Orders_Registry::instance() ) )->render_metabox( $order, $provider );
			$html = ob_get_clean();

			$this->assertStringContainsString( '<button', $html, 'the actions still render' );
			$this->assertStringNotContainsString( '<form', $html );
			$this->assertStringNotContainsString( '</form', $html );
		}

		/** #1012: every button carries the whole payload the detached form will post. */
		public function test_render_metabox_buttons_carry_the_payload_as_data_attributes(): void {
			$provider = $this->provider();
			$order    = $this->make_order();

			$this->meta = [];
			$this->register_handler();

			ob_start();
			( new Shipping_Admin_Order( Orders_Registry::instance() ) )->render_metabox( $order, $provider );
			$html = ob_get_clean();

			$this->assertSame( 1, preg_match( '/<button[^>]*data-woodev-order-action="export"[^>]*>/s', $html, $m ) );
			$button = $m[0];

			$this->assertStringContainsString( 'type="button"', $button, 'a submit button would be wrong outside a form of its own' );
			$this->assertStringContainsString( 'data-post-url="https://example.test/wp-admin/admin-post.php"', $button );
			$this->assertStringContainsString( 'data-post-action="' . Shipping_Admin_Order::ADMIN_POST_ACTION . '"', $button );
			$this->assertStringContainsString( 'data-order-id="123"', $button );
			$this->assertStringContainsString( 'data-nonce="nonce-123"', $button );
			$this->assertStringNotContainsString( 'data-confirm', $button, 'a non-destructive action asks nothing' );
		}

		/** #1012: the destructive action keeps its «Вы уверены?» question, now as a data attribute. */
		public function test_render_metabox_destructive_button_carries_the_confirm_question(): void {
			$provider = $this->provider();
			$order    = $this->make_order();

			$this->meta['_wc_cdek_order_id'] = 'CARRIER-1'; // exported => cancel is offered.
			$this->register_handler();

			ob_start();
			( new Shipping_Admin_Order( Orders_Registry::instance() ) )->render_metabox( $order, $provider );
			$html = ob_get_clean();

			$this->assertSame( 1, preg_match( '/<button[^>]*data-woodev-order-action="cancel"[^>]*>/s', $html, $m ) );
			$this->assertStringContainsString( 'data-confirm="Вы уверены?"', $m[0] );
			$this->assertStringNotContainsString( 'onclick', $html, 'no inline handler — the script owns the click' );
		}

		public function test_render_metabox_never_draws_a_button_for_the_edit_row_action(): void {
			$provider = $this->provider();
			$order    = $this->make_order();

			$this->meta = [];
			$this->register_handler();

			ob_start();
			( new Shipping_Admin_Order( Orders_Registry::instance() ) )->render_metabox( $order, $provider );
			$html = ob_get_clean();

			// The orders page's row carries «Редактировать» (#972); the order screen has no wizard to open,
			// and a button posting `edit` would only be refused.
			$this->assertStringContainsString( 'data-woodev-order-action="export"', $html, 'the carrier actions still render' );
			$this->assertStringNotContainsString( 'data-woodev-order-action="edit"', $html );
			$this->assertStringNotContainsString( 'Редактировать', $html );
		}

		public function test_render_metabox_renders_the_orders_page_status_badge_with_its_canonical_tone(): void {
			$provider = $this->provider(
				[
					'status_meta_key' => '_wc_cdek_status',
					'status_map'      => [ 'CDEK_DELIVERED' => 'delivered' ],
				]
			);
			$order    = $this->make_order();

			$this->meta = [
				'_wc_cdek_order_id' => 'CDEK-999',
				'_wc_cdek_status'   => 'CDEK_DELIVERED',
			];

			ob_start();
			( new Shipping_Admin_Order( Orders_Registry::instance() ) )->render_metabox( $order, $provider );
			$html = ob_get_clean();

			$this->assertStringContainsString( 'woodev-orders-status woodev-orders-status--ok', $html );
			$this->assertStringContainsString( '>Доставлено</span>', $html );
		}

		/**
		 * #1037: the metabox draws its status badge from the SAME row the orders page does, so a
		 * shipment the framework recorded as cancelled never shows the carrier's stale «в пути».
		 * (Cancelling clears the stored carrier id, so the metabox is then in its «not transferred»
		 * state, which draws no status at all — the marker can only surface here through the row.)
		 */
		public function test_render_metabox_never_shows_the_stale_raw_status_of_a_cancelled_shipment(): void {
			$provider = $this->provider(
				[
					'status_meta_key' => '_wc_cdek_status',
					'status_map'      => [ 'CDEK_ON_THE_WAY' => 'in_transit' ],
				]
			);
			$order    = $this->make_order();

			$this->meta = [
				'_wc_cdek_status'               => 'CDEK_ON_THE_WAY',
				'_woodev_shipment_cancelled_at' => '1790000000',
			];

			ob_start();
			( new Shipping_Admin_Order( Orders_Registry::instance() ) )->render_metabox( $order, $provider );
			$html = ob_get_clean();

			$this->assertStringNotContainsString( '>В пути</span>', $html );
			$this->assertStringContainsString( 'Заказ ещё не передан перевозчику', $html );
		}

		/**
		 * The status field of an order that IS exported reads the one resolver: should a carrier plugin
		 * ever store a carrier id of its own after a cancellation without clearing the marker, the badge
		 * says what the resolver says — never a different word than the orders page.
		 */
		public function test_render_metabox_status_badge_is_the_resolvers_answer_for_the_marker(): void {
			$provider = $this->provider(
				[
					'status_meta_key' => '_wc_cdek_status',
					'status_map'      => [ 'CDEK_ON_THE_WAY' => 'in_transit' ],
				]
			);
			$order    = $this->make_order();

			$this->meta = [
				'_wc_cdek_order_id'             => 'CDEK-999',
				'_wc_cdek_status'               => 'CDEK_ON_THE_WAY',
				'_woodev_shipment_cancelled_at' => '1790000000',
			];

			ob_start();
			( new Shipping_Admin_Order( Orders_Registry::instance() ) )->render_metabox( $order, $provider );
			$html = ob_get_clean();

			$this->assertStringContainsString( 'woodev-orders-status woodev-orders-status--error', $html );
			$this->assertStringContainsString( '>Отменено</span>', $html );
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
		// which would kill the test process.
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
		private function invoke_perform_action( Shipping_Admin_Order $admin_order, Abstract_Shipment_Handler $handler, \WC_Order $order, string $action, Orders_Provider $provider, array $payload = [] ): void {
			$method = new \ReflectionMethod( Shipping_Admin_Order::class, 'perform_action' );
			if ( PHP_VERSION_ID < 80100 ) {
				$method->setAccessible( true );
			}

			$method->invoke( $admin_order, $handler, $order, $action, $provider, $payload );
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

		public function test_perform_action_reports_a_failed_export_result_with_no_text_as_the_generic_failure(): void {
			$provider = $this->provider();
			$handler  = $this->register_handler();
			$handler->shouldReceive( 'export' )->once()->andReturn( Action_Result::failure() );

			// Exportable status, not yet exported => EXPORT is offered.
			$order = $this->make_order( [ 'get_status' => 'processing' ] );

			$this->capture_flashed_notices();

			$this->invoke_perform_action( new Shipping_Admin_Order( Orders_Registry::instance() ), $handler, $order, Order_Actions::EXPORT, $provider );

			$this->assertSame( [ 'Не удалось выгрузить заказ перевозчику.' ], $this->flashed_notices );
		}

		/**
		 * #872: the carrier's own reason reaches the merchant's notice, prefixed by the carrier
		 * name — the same sentence the REST route returns.
		 */
		public function test_perform_action_flashes_the_carriers_own_reason_prefixed_by_its_name(): void {
			$provider = $this->provider();
			$handler  = $this->register_handler();
			$handler->shouldReceive( 'export' )->once()->andReturn( Action_Result::failure( 'Неверный индекс получателя' ) );

			$order = $this->make_order( [ 'get_status' => 'processing' ] );

			$this->capture_flashed_notices();

			$this->invoke_perform_action( new Shipping_Admin_Order( Orders_Registry::instance() ), $handler, $order, Order_Actions::EXPORT, $provider );

			$this->assertSame( [ 'СДЭК: Неверный индекс получателя' ], $this->flashed_notices );
		}

		public function test_perform_action_flashes_the_carriers_reason_for_a_failed_cancel_and_update(): void {
			$provider = $this->provider();
			$handler  = $this->register_handler( true );
			$handler->shouldReceive( 'cancel' )->once()->andReturn( Action_Result::failure( 'Заказ уже передан курьеру' ) );
			$handler->shouldReceive( 'update' )->once()->andReturn( Action_Result::failure( 'Превышен лимит запросов' ) );

			$this->meta['_wc_cdek_order_id'] = 'CARRIER-1'; // exported => CANCEL and UPDATE are offered.
			$order                            = $this->make_order( [ 'get_status' => 'processing' ] );

			$this->capture_flashed_notices();

			$admin = new Shipping_Admin_Order( Orders_Registry::instance() );
			$this->invoke_perform_action( $admin, $handler, $order, Order_Actions::CANCEL, $provider );
			$this->invoke_perform_action( $admin, $handler, $order, Order_Actions::UPDATE, $provider );

			$this->assertSame( [ 'СДЭК: Заказ уже передан курьеру', 'СДЭК: Превышен лимит запросов' ], $this->flashed_notices );
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
			$handler->shouldReceive( 'cancel' )->once()->andReturn( Action_Result::success() );

			$this->meta['_wc_cdek_order_id'] = 'CARRIER-1'; // exported => CANCEL is offered.
			$order                            = $this->make_order( [ 'get_status' => 'processing' ] );

			Functions\expect( 'set_transient' )->never();

			$this->invoke_perform_action( new Shipping_Admin_Order( Orders_Registry::instance() ), $handler, $order, Order_Actions::CANCEL, $provider );
		}

		// -----------------------------------------------------------------------
		// #1016 — ONE dispatcher. The metabox no longer owns a copy of the action
		// `switch`: what it enforces is what Order_Actions enforces, so a rule added
		// there reaches this surface, and these tests fail if a private dispatcher
		// that skips one comes back.
		// -----------------------------------------------------------------------

		/**
		 * #1000 through the metabox: an order another manager is editing in the wizard is refused with
		 * the lock's own sentence, and the carrier is never called — the same refusal the REST route
		 * gives, because both recompute {@see Order_Actions::for_order()}.
		 */
		public function test_perform_action_refuses_an_order_another_manager_is_editing_and_never_reaches_the_carrier(): void {
			$provider = $this->provider();
			$handler  = $this->register_handler();

			// Exported and in a cancellable state: without the lock CANCEL WOULD be offered.
			$this->meta['_wc_cdek_order_id'] = 'CARRIER-1';
			$order                            = $this->make_order( [ 'get_status' => 'processing' ] );

			$this->capture_flashed_notices();

			\Automattic\WooCommerce\Internal\Admin\Orders\EditLock::$locks[123] = [ 'time' => time(), 'user_id' => 8 ];
			$user               = new \stdClass();
			$user->ID           = 8;
			$user->display_name = 'Мария';
			Functions\when( 'get_user_by' )->justReturn( $user );

			// No expectation on cancel(): Mockery fails the test if the handler is reached.
			$this->invoke_perform_action( new Shipping_Admin_Order( Orders_Registry::instance() ), $handler, $order, Order_Actions::CANCEL, $provider );

			$this->assertCount( 1, $this->flashed_notices );
			$this->assertStringContainsString( 'Мария', $this->flashed_notices[0], 'the merchant is told WHO holds the order' );
		}

		/**
		 * The performing itself is delegated: the admin order hands the call to
		 * {@see Order_Actions::perform()} with exactly what it was given. A private copy of the
		 * `switch` would call the handler directly and leave this expectation unmet.
		 */
		public function test_perform_action_dispatches_through_order_actions_perform(): void {
			$provider = $this->provider();
			$handler  = $this->register_handler();

			$this->meta['_wc_cdek_order_id'] = 'CARRIER-1'; // exported => CANCEL is offered.
			$order                            = $this->make_order( [ 'get_status' => 'processing' ] );

			$performer = Mockery::mock( Order_Actions::class, [ Orders_Registry::instance() ] )->makePartial();
			$performer->shouldReceive( 'perform' )->once()->with( $handler, $order, Order_Actions::CANCEL, $provider, [] )->andReturn( Action_Result::success() );

			$admin    = new Shipping_Admin_Order( Orders_Registry::instance() );
			$property = new \ReflectionProperty( Shipping_Admin_Order::class, 'order_actions' );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$property->setValue( $admin, $performer );

			$this->capture_flashed_notices();

			// The handler carries no cancel() expectation: reaching it directly throws, which flashes.
			$this->invoke_perform_action( $admin, $handler, $order, Order_Actions::CANCEL, $provider );

			$this->assertSame( [], $this->flashed_notices, 'the performer\'s success is the whole outcome — nothing is flashed' );
		}

		// -----------------------------------------------------------------------
		// #1180 — an action with input fields, and the lines a plugin adds to the box.
		// -----------------------------------------------------------------------

		/** The courier call: a day and an optional comment, declared through the real `woodev_shipping_order_actions` seam. */
		private function declare_courier_action( ?callable $extra = null ): void {
			Functions\when( 'sanitize_textarea_field' )->alias(
				static function ( string $text ): string {
					return strip_tags( $text );
				}
			);
			Functions\when( 'apply_filters' )->alias(
				static function ( string $hook, $value, ...$args ) use ( $extra ) {
					if ( 'woodev_shipping_order_actions' === $hook ) {
						$value[] = [
							'action'      => 'call_courier',
							'label'       => 'Вызвать курьера',
							'title'       => '',
							'destructive' => false,
							'fields'      => [
								[
									'id'       => 'day',
									'type'     => 'date',
									'label'    => 'День',
									'required' => true,
									'min'      => '2026-10-12',
								],
								[
									'id'        => 'comment',
									'type'      => 'textarea',
									'label'     => 'Комментарий',
									'maxlength' => 20,
								],
							],
						];
					}

					return null !== $extra ? $extra( $hook, $value, ...$args ) : $value;
				}
			);
		}

		public function test_perform_action_hands_the_validated_payload_to_the_dispatcher(): void {
			$provider = $this->provider();
			$handler  = $this->register_handler();

			$this->meta['_wc_cdek_order_id'] = 'CARRIER-1';
			$order                            = $this->make_order( [ 'get_status' => 'processing' ] );
			$this->declare_courier_action();

			$performer = Mockery::mock( Order_Actions::class, [ Orders_Registry::instance() ] )->makePartial();
			$performer->shouldReceive( 'perform' )->once()->with(
				$handler,
				$order,
				'call_courier',
				$provider,
				[
					'day'     => '2026-10-13',
					'comment' => '',
				]
			)->andReturn( Action_Result::success() );

			$admin    = new Shipping_Admin_Order( Orders_Registry::instance() );
			$property = new \ReflectionProperty( Shipping_Admin_Order::class, 'order_actions' );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$property->setValue( $admin, $performer );

			$this->capture_flashed_notices();

			$this->invoke_perform_action(
				$admin,
				$handler,
				$order,
				'call_courier',
				$provider,
				[
					'day'      => '2026-10-13',
					'injected' => 'not declared, so not forwarded',
				]
			);

			$this->assertSame( [], $this->flashed_notices );
		}

		public function test_perform_action_refuses_a_payload_that_misses_its_declaration_and_names_the_field(): void {
			$provider = $this->provider();
			$handler  = $this->register_handler();

			$this->meta['_wc_cdek_order_id'] = 'CARRIER-1';
			$order                            = $this->make_order( [ 'get_status' => 'processing' ] );
			$this->declare_courier_action(
				static function ( string $hook, $value ) {
					if ( 'woodev_shipping_perform_order_action' === $hook ) {
						throw new \LogicException( 'the carrier must not be reached with values that do not fit' );
					}

					return $value;
				}
			);

			$this->capture_flashed_notices();

			$this->invoke_perform_action(
				new Shipping_Admin_Order( Orders_Registry::instance() ),
				$handler,
				$order,
				'call_courier',
				$provider,
				[ 'day' => '2026-10-01' ]
			);

			$this->assertCount( 1, $this->flashed_notices );
			$this->assertStringContainsString( '«День»', $this->flashed_notices[0] );
			$this->assertStringContainsString( 'вне допустимых пределов', $this->flashed_notices[0] );
		}

		public function test_an_action_without_fields_takes_an_empty_payload_whatever_was_posted(): void {
			$provider = $this->provider();
			$handler  = $this->register_handler();
			$handler->shouldReceive( 'cancel' )->once()->andReturn( Action_Result::success() );

			$this->meta['_wc_cdek_order_id'] = 'CARRIER-1';
			$order                            = $this->make_order( [ 'get_status' => 'processing' ] );

			Functions\expect( 'set_transient' )->never();

			$this->invoke_perform_action( new Shipping_Admin_Order( Orders_Registry::instance() ), $handler, $order, Order_Actions::CANCEL, $provider, [ 'day' => 'x' ] );
		}

		public function test_render_metabox_button_of_an_action_with_fields_carries_them_as_json(): void {
			$provider = $this->provider();
			$order    = $this->make_order();

			$this->meta['_wc_cdek_order_id'] = 'CARRIER-1';
			$this->register_handler();
			$this->declare_courier_action();
			Functions\when( 'wp_json_encode' )->alias( static fn( $value ) => json_encode( $value, JSON_UNESCAPED_UNICODE ) );

			ob_start();
			( new Shipping_Admin_Order( Orders_Registry::instance() ) )->render_metabox( $order, $provider );
			$html = ob_get_clean();

			$this->assertSame( 1, preg_match( '/<button[^>]*data-woodev-order-action="call_courier"[^>]*>/s', $html, $m ) );
			$this->assertSame( 1, preg_match( '/data-fields="([^"]*)"/', $m[0], $f ) );

			$fields = json_decode( html_entity_decode( $f[1] ), true );

			$this->assertSame( [ 'day', 'comment' ], array_column( $fields, 'id' ) );
			$this->assertSame( 1, preg_match( '/data-labels="([^"]*)"/', $m[0], $l ), 'the dialog\'s own sentences travel with the button' );
			$this->assertSame( 'Отмена', json_decode( html_entity_decode( $l[1] ), true )['cancel'] );

			$this->assertSame( 1, preg_match( '/<button[^>]*data-woodev-order-action="cancel"[^>]*>/s', $html, $plain ) );
			$this->assertStringNotContainsString( 'data-fields', $plain[0], 'an action without fields is exactly as before' );
		}

		// -----------------------------------------------------------------------
		// s164 — documents go through the REST route; one button group; icons vs text; destructive red.
		// -----------------------------------------------------------------------

		/** Renders the box of an exported order with the given extra actions (and a document source when `$documents`). */
		private function render_exported( array $extra_actions = [], bool $documents = false ): string {
			$provider = $this->provider( [ 'supports_label_printing' => $documents ] );
			$order    = $this->make_order();

			$this->meta['_wc_cdek_order_id'] = 'CARRIER-1';
			$this->register_handler( true );

			if ( $documents ) {
				Orders_Registry::instance()->register_document_source(
					'cdek',
					new class() implements \Woodev\Framework\Shipping\Order\Document_Source {
						public function get_document_types( \WC_Order $order ): array {
							return [ 'waybill', 'barcode' ];
						}

						public function get_document( \WC_Order $order, string $type ): \Woodev\Framework\Shipping\Order\Document_Result {
							return \Woodev\Framework\Shipping\Order\Document_Result::pending();
						}
					}
				);
			}

			Functions\when( 'wp_json_encode' )->alias( static fn( $value ) => json_encode( $value, JSON_UNESCAPED_UNICODE ) );
			Functions\when( 'apply_filters' )->alias(
				static function ( string $hook, $value ) use ( $extra_actions ) {
					return 'woodev_shipping_order_actions' === $hook ? array_merge( $value, $extra_actions ) : $value;
				}
			);

			ob_start();
			( new Shipping_Admin_Order( Orders_Registry::instance() ) )->render_metabox( $order, $provider );

			return (string) ob_get_clean();
		}

		/**
		 * Root cause of «Накладная» / «Штрихкод» failing in the metabox: the buttons POSTed `waybill` to the action
		 * handler, whose gate ({@see Order_Actions::for_order()}) has never offered a document. A document button now
		 * carries the REST route and nonce and NO post payload.
		 */
		public function test_a_document_button_points_at_the_documents_route_and_posts_nothing(): void {
			$html = $this->render_exported( [], true );

			$this->assertSame( 1, preg_match( '/<button[^>]*data-woodev-order-action="waybill"[^>]*>/s', $html, $m ) );
			$this->assertStringContainsString( 'data-document-url="https://example.test/wp-json/woodev/v1/shipping/orders/123/documents/waybill?format=json"', $m[0] );
			$this->assertStringContainsString( 'data-rest-nonce="nonce-123"', $m[0] );
			$this->assertStringNotContainsString( 'data-post-url', $m[0] );
			$this->assertStringNotContainsString( 'data-nonce', $m[0] );

			$this->assertSame( 1, preg_match( '/<button[^>]*data-woodev-order-action="barcode"[^>]*>/s', $html, $b ) );
			$this->assertStringContainsString( '/documents/barcode?format=json', $b[0] );

			$this->assertSame( 1, preg_match( '/<button[^>]*data-woodev-order-action="update"[^>]*>/s', $html, $update ) );
			$this->assertStringContainsString( 'data-post-url', $update[0], 'a carrier action still posts' );
			$this->assertStringNotContainsString( 'data-document-url', $update[0] );
		}

		public function test_the_actions_are_one_group_in_one_row_and_the_group_carries_the_document_sentences(): void {
			$html = $this->render_exported( [], true );

			$this->assertSame( 1, substr_count( $html, 'class="woodev-shipping-order-actions-buttons' ) );
			$this->assertSame( 1, preg_match( '/<div\s[^>]*class="woodev-shipping-order-actions-buttons[^"]*"[^>]*role="group"[^>]*data-document-labels="([^"]*)"/s', $html, $g ) );
			$this->assertSame( 'Не удалось получить документ у перевозчика.', json_decode( html_entity_decode( $g[1] ), true )['failed'] );
			$this->assertStringContainsString( 'woodev-shipping-order-doc-notice', $html );
		}

		public function test_past_two_actions_the_buttons_are_icon_only_with_the_label_in_aria_label_and_tooltip(): void {
			$html = $this->render_exported( [], true ); // update, cancel, waybill, barcode.

			$this->assertStringContainsString( 'woodev-shipping-order-actions-buttons--icons', $html );
			$this->assertSame( 1, preg_match( '/<button[^>]*data-woodev-order-action="waybill"[^>]*>(.*?)<\/button>/s', $html, $m ) );
			$this->assertStringContainsString( 'aria-label="Накладная"', $m[0] );
			$this->assertStringContainsString( 'title="Накладная — Скачать документ перевозчика"', $m[0] );
			$this->assertStringContainsString( 'dashicons-media-document', $m[1] );
			$this->assertStringNotContainsString( '>Накладная<', $m[0], 'no visible text in an icon-only button' );

			$this->assertSame( 1, preg_match( '/<button[^>]*data-woodev-order-action="barcode"[^>]*>(.*?)<\/button>/s', $html, $b ) );
			$this->assertStringContainsString( 'dashicons-tag', $b[1], 'waybill and barcode do not share a glyph' );
		}

		public function test_with_one_or_two_actions_the_buttons_carry_their_text(): void {
			$html = $this->render_exported(); // update, cancel.

			$this->assertStringNotContainsString( '--icons', $html );
			$this->assertStringNotContainsString( 'aria-label', $html );
			$this->assertStringNotContainsString( 'dashicons', $html );
			$this->assertSame( 1, preg_match( '/<button[^>]*data-woodev-order-action="cancel"[^>]*>(.*?)<\/button>/s', $html, $m ) );
			$this->assertSame( 'Отменить', trim( $m[1] ) );
		}

		public function test_an_extra_action_without_an_icon_gets_the_neutral_glyph_never_the_gear(): void {
			$html = $this->render_exported(
				[
					[
						'action'      => 'call_courier',
						'label'       => 'Вызвать курьера',
						'title'       => '',
						'destructive' => false,
					],
					[
						'action'      => 'send_sms',
						'label'       => 'SMS',
						'title'       => '',
						'destructive' => false,
						'icon'        => 'email',
					],
				]
			);

			$this->assertSame( 1, preg_match( '/<button[^>]*data-woodev-order-action="call_courier"[^>]*>(.*?)<\/button>/s', $html, $m ) );
			$this->assertStringContainsString( 'dashicons-' . Order_Actions::FALLBACK_ICON, $m[1] );
			$this->assertStringNotContainsString( 'admin-generic', $html );

			$this->assertSame( 1, preg_match( '/<button[^>]*data-woodev-order-action="send_sms"[^>]*>(.*?)<\/button>/s', $html, $s ) );
			$this->assertStringContainsString( 'dashicons-email', $s[1], 'a declared icon wins' );
		}

		public function test_a_destructive_action_wears_the_destructive_class(): void {
			$html = $this->render_exported();

			$this->assertSame( 1, preg_match( '/<button[^>]*data-woodev-order-action="cancel"[^>]*>/s', $html, $m ) );
			$this->assertStringContainsString( 'woodev-shipping-order-action--destructive', $m[0] );
			$this->assertSame( 1, preg_match( '/<button[^>]*data-woodev-order-action="update"[^>]*>/s', $html, $u ) );
			$this->assertStringNotContainsString( '--destructive', $u[0] );
		}

		public function test_the_metabox_fields_filter_adds_lines_next_to_the_framework_ones(): void {
			$provider = $this->provider();
			$order    = $this->make_order();

			$this->meta['_wc_cdek_order_id'] = 'CDEK-999';
			Functions\when( 'apply_filters' )->alias(
				static function ( string $hook, $value ) {
					if ( 'woodev_shipping_order_metabox_fields' !== $hook ) {
						return $value;
					}

					$value[] = [
						'label' => 'Заявка на курьера',
						'value' => '№ 77 (принята)',
					];
					// All of these are dropped: no value, no label, not an array.
					$value[] = [
						'label' => 'Пустое',
						'value' => '',
					];
					$value[] = [ 'value' => 'без подписи' ];
					$value[] = 'строка';

					return $value;
				}
			);

			ob_start();
			( new Shipping_Admin_Order( Orders_Registry::instance() ) )->render_metabox( $order, $provider );
			$html = ob_get_clean();

			$this->assertStringContainsString( 'CDEK-999', $html, 'the framework\'s own lines stay' );
			$this->assertStringContainsString( 'Заявка на курьера', $html );
			$this->assertStringContainsString( '№ 77 (принята)', $html );
			$this->assertStringNotContainsString( 'Пустое', $html );
			$this->assertStringNotContainsString( 'без подписи', $html );
		}

		public function test_the_metabox_fields_filter_returning_a_non_array_keeps_the_framework_lines(): void {
			$provider = $this->provider();
			$order    = $this->make_order();

			$this->meta['_wc_cdek_order_id'] = 'CDEK-999';
			Functions\when( 'apply_filters' )->alias(
				static function ( string $hook, $value ) {
					return 'woodev_shipping_order_metabox_fields' === $hook ? null : $value;
				}
			);

			ob_start();
			( new Shipping_Admin_Order( Orders_Registry::instance() ) )->render_metabox( $order, $provider );
			$html = ob_get_clean();

			$this->assertStringContainsString( 'CDEK-999', $html );
		}

		/**
		 * The metabox has no dispatcher of its own to drift from {@see Order_Actions::perform()}.
		 */
		public function test_the_metabox_keeps_no_private_dispatcher(): void {
			$this->assertFalse( method_exists( Shipping_Admin_Order::class, 'dispatch_action' ) );
			$this->assertFalse( method_exists( Shipping_Admin_Order::class, 'resolve_popular_settlement_context' ) );
		}
	}
}
