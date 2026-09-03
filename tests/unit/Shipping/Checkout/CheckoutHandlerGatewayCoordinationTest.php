<?php
/**
 * Tests for the #713 payment-gateway coordination hook:
 * `Checkout_Handler::apply_gateway_coordination()` — wired onto
 * `woocommerce_available_payment_gateways` — and the
 * `woodev_shipping_available_payment_gateways` contract filter it fires.
 *
 * Scope, per the issue: the framework fires ONE filter carrying (available gateways,
 * chosen method, chosen pickup point) and makes NO decision itself — no gateway is
 * removed here, no commission/insurance-sum math happens here. That stays domain logic
 * in the carrier plugin (issue #713, item 3).
 *
 * Bracketed-namespace style, same reason as `ShippingMethodFilterReturnGuardsTest.php`
 * and `CheckoutConfigPickupMethodFixture.php`: `WC_Shipping_Method` must be declared in
 * the GLOBAL namespace before `Shipping_Method` (which `extends \WC_Shipping_Method`
 * directly) loads. The stub below is shaped IDENTICALLY to the one in
 * `ShippingMethodFilterReturnGuardsTest.php` (same `public array $supports` property,
 * same `supports()` body) — not the THINNER one in `CheckoutConfigPickupMethodFixture.php`
 * — specifically so it does not matter which of those two files' `class_exists( ...,
 * false )` guard wins PHPUnit's suite-collection race: either winner is fully
 * compatible with a `supports_cod()` / `add_support()` call.
 *
 * @package Woodev\Tests\Unit\Shipping\Checkout
 */

namespace {

	if ( ! class_exists( 'WC_Shipping_Method', false ) ) {
		/**
		 * Minimal WooCommerce shipping method base, shaped like the one in
		 * `ShippingMethodFilterReturnGuardsTest.php` — see this file's own docblock for
		 * why the shape must match.
		 */
		class WC_Shipping_Method {

			/** @var string */
			public $id;

			/** @var array */
			public array $supports = [];

			/** @var array */
			public $instance_form_fields = [];

			/**
			 * @param string $feature feature flag.
			 * @return bool
			 */
			public function supports( $feature ) {
				return in_array( $feature, $this->supports, true );
			}
		}
	}

	// Must run AFTER the WC_Shipping_Method stub above and BEFORE the
	// Shipping_Method-extending fixture below.
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/class-shipping-plugin.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/class-shipping-rate.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/class-shipping-method.php';
}

namespace Woodev\Tests\Unit\Shipping\Checkout {

	use Brain\Monkey\Functions;
	use Woodev\Framework\Shipping\Checkout\Checkout_Fields;
	use Woodev\Framework\Shipping\Checkout\Checkout_Handler;
	use Woodev\Framework\Shipping\Checkout\Presets\Pickup_Field;
	use Woodev\Framework\Shipping\Shipping_Method;
	use Woodev\Tests\Unit\TestCase;

	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-field.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-fields.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-condition.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-handler.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/presets/class-pickup-field.php';

	/**
	 * A `Shipping_Method` double whose constructor bypasses the real one entirely,
	 * mirroring `Woodev_Test_Shipping_Method_For_Guards` in
	 * `ShippingMethodFilterReturnGuardsTest.php`. `get_plugin()` is never called by any
	 * code path under test here, so it throws rather than building an unused double.
	 */
	final class Coordination_Fake_Shipping_Method extends Shipping_Method {

		private bool $pickup;

		public function __construct( string $id, bool $pickup ) {
			$this->id     = $id;
			$this->pickup = $pickup;
		}

		public static function get_method_id(): string {
			return 'coordination_fake_shipping_method';
		}

		public function get_delivery_type(): string {
			return $this->pickup ? self::TYPE_PICKUP : self::TYPE_COURIER;
		}

		protected function get_method_form_fields(): array {
			return [];
		}

		protected function rate_package( array $package, ?\Woodev_Packer_Result $packed ): ?\Woodev\Framework\Shipping\Shipping_Rate {
			return null;
		}

		protected function get_plugin(): \Woodev\Framework\Shipping\Shipping_Plugin {
			throw new \RuntimeException( 'not needed by these tests' );
		}
	}

	/**
	 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::apply_gateway_coordination
	 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::resolve_own_pickup_point
	 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::resolve_chosen_shipping_method_instance
	 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::register
	 */
	class CheckoutHandlerGatewayCoordinationTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();

			Functions\when( 'wc_clean' )->returnArg();
			Functions\when( 'wp_unslash' )->returnArg();
		}

		protected function tearDown(): void {
			Checkout_Handler::reset_native_field_registry();
			unset( $_POST['shipping_method'], $_POST['carrier_pickup_point'] );
			parent::tearDown();
		}

		// -------------------------------------------------------------------------
		// register() wires a STATIC callback. That is the piece that makes WordPress
		// collapse N plugins' registrations into ONE stored callback (see that
		// method's own docblock) — a `[ $this, ... ]` callback here would silently
		// reintroduce the "fires once per installed plugin" bug class (#736/#746/#749).
		// -------------------------------------------------------------------------

		public function test_register_wires_a_static_not_per_instance_callback(): void {
			$registered = [];

			Functions\when( 'add_filter' )->alias(
				static function ( $hook, $callback = null ) use ( &$registered ) {
					if ( 'woocommerce_available_payment_gateways' === $hook ) {
						$registered[] = $callback;
					}

					return true;
				}
			);
			Functions\when( 'add_action' )->justReturn( true );

			( new Checkout_Handler( Checkout_Fields::from_array( [] ), 'plugin_a' ) )->register();
			( new Checkout_Handler( Checkout_Fields::from_array( [] ), 'plugin_b' ) )->register();

			$this->assertCount(
				2,
				$registered,
				'both plugins DID call add_filter — WordPress itself, not this class, is what dedupes the two'
			);
			$this->assertSame( [ Checkout_Handler::class, 'apply_gateway_coordination' ], $registered[0] );
			$this->assertSame(
				$registered[0],
				$registered[1],
				"both registrations are the IDENTICAL static callback array — the property WordPress's own "
				. '_wp_filter_build_unique_id() dedupes on, so only one is ever actually stored/fired'
			);
		}

		// -------------------------------------------------------------------------
		// The filter fires with the chosen method and the gateway list.
		// -------------------------------------------------------------------------

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_apply_gateway_coordination_fires_with_the_resolved_chosen_method(): void {
			$chosen = new Coordination_Fake_Shipping_Method( 'carrier_courier', false );
			$this->stub_wc_shipping( [ $chosen ] );

			$_POST['shipping_method'] = [ 'carrier_courier:1' ];

			$captured = null;
			Functions\when( 'apply_filters' )->alias(
				static function ( $tag, $value = null, ...$args ) use ( &$captured ) {
					if ( 'woodev_shipping_available_payment_gateways' === $tag ) {
						$captured = [ $value, ...$args ];
					}

					return $value;
				}
			);

			$gateways = [ 'cod' => 'COD Gateway' ];
			$result   = Checkout_Handler::apply_gateway_coordination( $gateways );

			$this->assertSame( $gateways, $result, 'the framework makes no decision itself when nothing consumes the filter' );
			$this->assertNotNull( $captured, 'the contract filter must fire' );

			[ $captured_gateways, $captured_method, $captured_point ] = $captured;

			$this->assertSame( $gateways, $captured_gateways );
			$this->assertSame( $chosen, $captured_method );
			$this->assertNull( $captured_point, 'carrier_courier is not a pickup method' );
		}

		// -------------------------------------------------------------------------
		// Null pickup point when none is chosen.
		// -------------------------------------------------------------------------

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_pickup_point_is_null_when_no_shipping_method_is_chosen_yet(): void {
			$this->stub_wc_shipping( [] );
			// No $_POST['shipping_method'] at all — e.g. first checkout page load.

			$captured = null;
			Functions\when( 'apply_filters' )->alias(
				static function ( $tag, $value = null, ...$args ) use ( &$captured ) {
					if ( 'woodev_shipping_available_payment_gateways' === $tag ) {
						$captured = $args;
					}

					return $value;
				}
			);

			Checkout_Handler::apply_gateway_coordination( [] );

			$this->assertNotNull( $captured );

			[ $captured_method, $captured_point ] = $captured;

			$this->assertNull( $captured_method );
			$this->assertNull( $captured_point );
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_pickup_point_is_null_when_the_chosen_method_is_not_a_pickup_method(): void {
			$chosen = new Coordination_Fake_Shipping_Method( 'carrier_courier', false );
			$this->stub_wc_shipping( [ $chosen ] );

			$_POST['shipping_method']      = [ 'carrier_courier' ];
			// A posted point, but for a NON-pickup chosen method — must be ignored.
			$_POST['carrier_pickup_point'] = 'PVZ-001';

			$fields  = Checkout_Fields::from_array( [ Pickup_Field::create( 'carrier_pickup_point', [ 'carrier_pickup' ] )->to_array() ] );
			$handler = new Checkout_Handler( $fields, 'carrier' );

			Functions\when( 'add_filter' )->justReturn( true );
			Functions\when( 'add_action' )->justReturn( true );
			$handler->register();

			$captured = null;
			Functions\when( 'apply_filters' )->alias(
				static function ( $tag, $value = null, ...$args ) use ( &$captured ) {
					if ( 'woodev_shipping_available_payment_gateways' === $tag ) {
						$captured = $args;
					}

					return $value;
				}
			);

			Checkout_Handler::apply_gateway_coordination( [] );

			[ $captured_method, $captured_point ] = $captured;

			$this->assertSame( $chosen, $captured_method );
			$this->assertNull( $captured_point );
		}

		// -------------------------------------------------------------------------
		// A resolved, non-null pickup point when the chosen method IS a pickup method
		// and its own field has a posted value.
		// -------------------------------------------------------------------------

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_pickup_point_is_resolved_when_the_chosen_method_is_pickup_and_a_point_is_posted(): void {
			$chosen = new Coordination_Fake_Shipping_Method( 'carrier_pickup', true );
			$this->stub_wc_shipping( [ $chosen ] );

			$_POST['shipping_method']      = [ 'carrier_pickup:2' ];
			$_POST['carrier_pickup_point'] = 'PVZ-001';

			$fields  = Checkout_Fields::from_array( [ Pickup_Field::create( 'carrier_pickup_point', [ 'carrier_pickup' ] )->to_array() ] );
			$handler = new Checkout_Handler( $fields, 'carrier' );

			Functions\when( 'add_filter' )->justReturn( true );
			Functions\when( 'add_action' )->justReturn( true );
			$handler->register();

			$captured = null;
			Functions\when( 'apply_filters' )->alias(
				static function ( $tag, $value = null, ...$args ) use ( &$captured ) {
					if ( 'woodev_shipping_available_payment_gateways' === $tag ) {
						$captured = $args;
					}

					return $value;
				}
			);

			Checkout_Handler::apply_gateway_coordination( [] );

			[ $captured_method, $captured_point ] = $captured;

			$this->assertSame( $chosen, $captured_method );
			$this->assertSame( 'PVZ-001', $captured_point );
		}

		// -------------------------------------------------------------------------
		// An undeclared method's behaviour is unchanged: nothing removed, nothing
		// throws — the important test per the #713 brief.
		// -------------------------------------------------------------------------

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_undeclared_methods_gateway_list_is_untouched_and_nothing_throws(): void {
			$chosen = new Coordination_Fake_Shipping_Method( 'carrier_courier', false );
			$this->stub_wc_shipping( [ $chosen ] );

			$_POST['shipping_method'] = [ 'carrier_courier' ];

			// No consumer hooked woodev_shipping_available_payment_gateways at all —
			// the real apply_filters() passthrough behaviour, not a value-substituting
			// stub, so this also proves the coordination method makes no decision by
			// itself.
			Functions\when( 'apply_filters' )->returnArg( 2 );

			$gateways = [
				'cod'           => 'COD Gateway',
				'bank_transfer' => 'Bank Transfer',
			];

			$result = Checkout_Handler::apply_gateway_coordination( $gateways );

			$this->assertSame(
				$gateways,
				$result,
				'nothing is removed when the chosen method never declared any of the three capability flags'
			);
			$this->assertFalse( $chosen->supports_cod() );
			$this->assertFalse( $chosen->supports_insurance() );
			$this->assertFalse( $chosen->supports_declared_value() );
		}

		/**
		 * WC() unavailable (e.g. before WooCommerce has loaded) must not throw — the
		 * chosen method degrades to `null`, exactly like
		 * {@see \Woodev\Framework\Shipping\Checkout\Checkout_Config::pickup_method_ids()}
		 * degrades for the identical reason.
		 *
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function test_apply_gateway_coordination_does_not_throw_when_wc_is_unavailable(): void {
			unset( $_POST['shipping_method'] );

			$gateways = [ 'cod' => 'COD Gateway' ];
			$result   = Checkout_Handler::apply_gateway_coordination( $gateways );

			$this->assertSame( $gateways, $result );
		}

		/**
		 * A non-array `$available_gateways` (a filter earlier in the chain
		 * misbehaving) must not throw either — coerced to `[]`, mirroring every other
		 * filter-return guard in this codebase (see
		 * `ShippingMethodFilterReturnGuardsTest.php`'s own class docblock: "degrade to
		 * a safe default, never throw").
		 */
		public function test_apply_gateway_coordination_coerces_a_non_array_input_to_an_empty_array(): void {
			unset( $_POST['shipping_method'] );

			Functions\when( 'apply_filters' )->returnArg( 2 );

			$result = Checkout_Handler::apply_gateway_coordination( 'not an array' );

			$this->assertSame( [], $result );
		}

		/**
		 * Stubs `WC()->shipping()->get_shipping_methods()` to return the given methods.
		 *
		 * @param array<int, \Woodev\Framework\Shipping\Shipping_Method> $methods methods to expose.
		 *
		 * @return void
		 */
		private function stub_wc_shipping( array $methods ): void {
			$shipping = new class( $methods ) {
				/** @var array */
				private array $methods;

				public function __construct( array $methods ) {
					$this->methods = $methods;
				}

				/** @return array */
				public function get_shipping_methods(): array {
					return $this->methods;
				}
			};

			$wc = new class( $shipping ) {
				public $shipping_service;

				public function __construct( $shipping_service ) {
					$this->shipping_service = $shipping_service;
				}

				public function shipping() {
					return $this->shipping_service;
				}
			};

			Functions\when( 'WC' )->justReturn( $wc );
		}
	}
}
