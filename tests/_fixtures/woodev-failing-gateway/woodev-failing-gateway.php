<?php
/**
 * Plugin Name: Woodev Failing Gateway (rig fixture)
 * Description: A payment gateway that FAILS its first attempts on every order, so a failed payment and its retry can be walked in a real browser. NOT for production use.
 * Version:     1.0.0
 * Author:      Woodev
 * Text Domain: woodev-failing-gateway
 *
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * WC requires at least: 9.9
 *
 * WHY THIS EXISTS (SP-11 C-4, #1091): the rig's only gateway is `cod`, which cannot fail, so the
 * «fail payment → retry» flows of the block checkout (`Store_Api_Pickup::validate_order()`, the
 * `_placed` confirmation a retry stands on) were reachable from integration tests only. This gateway
 * makes them reachable from the checkout page.
 *
 * It depends on nothing of the framework — it must keep working whatever the framework does — and
 * it is not mapped into wp-env: load it on the rig from a container-only mu-plugin, e.g.
 *
 *     require '/var/www/html/woodev-framework/tests/_fixtures/woodev-failing-gateway/woodev-failing-gateway.php';
 *
 * BEHAVIOUR. Each order carries a counter (`_woodev_failing_gateway_attempts`). An attempt whose
 * number is not above the `woodev_failing_gateway_fail_count` option (default 1) marks the order
 * `failed` and reports a payment failure; the next one completes the payment. So the default is
 * «first attempt fails, the retry succeeds»; set the option to 0 for a gateway that always pays.
 *
 * @package Woodev_Failing_Gateway
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WC_Payment_Gateway' ) || class_exists( 'Woodev_Failing_Gateway' ) ) {
			return;
		}

		/**
		 * Fails the first attempts on an order, then pays.
		 */
		final class Woodev_Failing_Gateway extends WC_Payment_Gateway {

			public const ID            = 'woodev_failing';
			public const ATTEMPTS_META = '_woodev_failing_gateway_attempts';
			public const COUNT_OPTION  = 'woodev_failing_gateway_fail_count';

			public function __construct() {
				$this->id                 = self::ID;
				$this->method_title       = 'Failing gateway (rig fixture)';
				$this->method_description = 'Fails the first payment attempts on every order.';
				$this->title              = 'Failing gateway (test)';
				$this->description        = 'The first attempt fails; try again to pay.';
				$this->has_fields         = false;
				$this->enabled            = 'yes';
				$this->supports           = [ 'products' ];
			}

			/**
			 * @param int $order_id Order being paid.
			 *
			 * @return array<string, string>
			 *
			 * @throws Exception On an attempt that is meant to fail.
			 */
			public function process_payment( $order_id ) {
				$order    = wc_get_order( $order_id );
				$attempts = (int) $order->get_meta( self::ATTEMPTS_META ) + 1;

				$order->update_meta_data( self::ATTEMPTS_META, (string) $attempts );

				if ( $attempts <= (int) get_option( self::COUNT_OPTION, 1 ) ) {
					$order->update_status( 'failed', 'Failing gateway: attempt ' . $attempts . ' failed on purpose.' );

					throw new Exception( 'The test payment failed (attempt ' . $attempts . '). Please try again.' );
				}

				$order->save();
				$order->payment_complete();

				return [
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				];
			}
		}

		add_filter(
			'woocommerce_payment_gateways',
			static function ( array $gateways ): array {
				$gateways[] = 'Woodev_Failing_Gateway';

				return $gateways;
			}
		);
	}
);

/*
 * The block checkout lists a gateway only when a payment method of that name is registered in the
 * browser, so the fixture registers a minimal one — inline, to stay a single file.
 */
add_action(
	'woocommerce_blocks_payment_method_type_registration',
	static function ( $registry ): void {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) || class_exists( 'Woodev_Failing_Gateway_Blocks' ) ) {
			return;
		}

		/**
		 * Publishes the fixture gateway to the Checkout block.
		 */
		final class Woodev_Failing_Gateway_Blocks extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {

			/** @var string */
			protected $name = 'woodev_failing';

			public function initialize(): void {}

			public function is_active(): bool {
				return true;
			}

			/**
			 * @return string[]
			 */
			public function get_payment_method_script_handles(): array {
				$handle = 'woodev-failing-gateway-blocks';

				if ( ! wp_script_is( $handle, 'registered' ) ) {
					wp_register_script( $handle, '', [ 'wc-blocks-registry', 'wp-element' ], '1.0.0', true );
					wp_add_inline_script(
						$handle,
						"( function () {
							var label = 'Failing gateway (test)';
							var content = function () { return window.wp.element.createElement( 'p', null, 'The first attempt fails; try again to pay.' ); };
							window.wc.wcBlocksRegistry.registerPaymentMethod( {
								name: 'woodev_failing',
								label: label,
								ariaLabel: label,
								content: window.wp.element.createElement( content ),
								edit: window.wp.element.createElement( content ),
								canMakePayment: function () { return true; },
								supports: { features: [ 'products' ] }
							} );
						} )();"
					);
				}

				return [ $handle ];
			}

			/**
			 * @return array<string, mixed>
			 */
			public function get_payment_method_data(): array {
				return [ 'title' => 'Failing gateway (test)' ];
			}
		}

		$registry->register( new Woodev_Failing_Gateway_Blocks() );
	}
);
