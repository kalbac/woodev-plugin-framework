<?php
/**
 * Plugin Name: Y15 coexistence rig helper
 * Description: Rig-only helper for tests/e2e/coexistence (kalbac/woocommerce-yandex-delivery#18). Inert until its options are set.
 *
 * Lives in the container's `wp-content/mu-plugins/` — NOT in any plugin and not in the repository's runtime code. Install and
 * remove it with `tests/e2e/coexistence/rig/install.sh`. Two switches, both off by default:
 *
 *  - `y15_flaky_gateway` = `yes` — offers the «Y15 flaky gateway». It declines the FIRST payment attempt of every order and
 *    accepts the next one, so a spec can fail a payment and re-checkout on the very same order (WooCommerce resumes a pending
 *    or failed order while its cart hash is unchanged).
 *  - `y15_equal_rates` = a number — every pickup rate of the two carriers (`edostavka_pickup`, `yandex_delivery_pickup`) costs
 *    that much. WooCommerce's cart hash includes the total, so the two carriers' different prices would otherwise make the
 *    second attempt a NEW order; a shop whose two carriers both ship free above a threshold meets exactly this equal total.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'woocommerce_payment_gateways',
	static function ( array $gateways ): array {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return $gateways;
		}

		if ( ! class_exists( 'Y15_Flaky_Gateway' ) ) {
			class Y15_Flaky_Gateway extends WC_Payment_Gateway {
				public function __construct() {
					$this->id                 = 'y15_flaky';
					$this->method_title       = 'Y15 flaky gateway';
					$this->method_description = 'Rig-only: declines the first payment attempt of an order, accepts the next.';
					$this->has_fields         = false;
					$this->title              = 'Y15 flaky gateway';
					$this->enabled            = 'yes';
				}

				public function is_available() {
					return 'yes' === get_option( 'y15_flaky_gateway' );
				}

				public function process_payment( $order_id ) {
					$order    = wc_get_order( $order_id );
					$attempts = (int) $order->get_meta( '_y15_payment_attempts' );

					$order->update_meta_data( '_y15_payment_attempts', $attempts + 1 );

					if ( 0 === $attempts ) {
						$order->update_status( 'failed', 'Y15 flaky gateway declined the first attempt.' );
						wc_add_notice( 'Y15 flaky gateway: payment declined, try again.', 'error' );

						return [ 'result' => 'failure' ];
					}

					$order->payment_complete();
					WC()->cart->empty_cart();

					return [
						'result'   => 'success',
						'redirect' => $this->get_return_url( $order ),
					];
				}
			}
		}

		$gateways[] = 'Y15_Flaky_Gateway';

		return $gateways;
	}
);

add_filter(
	'woocommerce_package_rates',
	static function ( $rates ) {
		$price = get_option( 'y15_equal_rates' );

		if ( ! is_array( $rates ) || ! is_numeric( $price ) ) {
			return $rates;
		}

		foreach ( $rates as $rate ) {
			if ( in_array( $rate->get_method_id(), [ 'edostavka_pickup', 'yandex_delivery_pickup' ], true ) ) {
				$rate->set_cost( (string) $price );
				$rate->set_taxes( [] );
			}
		}

		return $rates;
	},
	9999
);
