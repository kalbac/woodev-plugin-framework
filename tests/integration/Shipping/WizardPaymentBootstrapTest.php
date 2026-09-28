<?php
/**
 * Integration: the reference data step ⑤ of the admin order wizard reads from the page bootstrap
 * (#710 D1 / O7, card #971) — built from the REAL WooCommerce gateways and order statuses.
 *
 * The unit test pins the shaping against doubles; only the real thing shows that a real
 * `WC_Payment_Gateway` exposes the `enabled` flag and a title the way the registry reads them, and
 * that the status list is the one the payload validator accepts (`wc_get_order_statuses()`, no
 * `wc-` prefix).
 *
 * NOT RUN BY THE WORKER THAT AUTHORED THIS FILE — the coordinator runs the integration suite.
 *
 * @package Woodev\Tests\Integration\Shipping
 * @since   2.0.2
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Framework\Shipping\Admin\Orders\Order_Actions;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Tests\Integration\TestCase;

class WizardPaymentBootstrapTest extends TestCase {

	/**
	 * Calls the registry's private builder.
	 *
	 * @return array<string, mixed>
	 */
	private function bootstrap(): array {
		$method = new \ReflectionMethod( Orders_Registry::class, 'build_wizard_payment_bootstrap' );
		$method->setAccessible( true );

		return $method->invoke( Orders_Registry::instance() );
	}

	public function test_statuses_are_woocommerces_own_without_the_prefix(): void {
		$data = $this->bootstrap();

		$this->assertArrayHasKey( 'pending', $data['orderStatuses'] );
		$this->assertArrayHasKey( 'processing', $data['orderStatuses'] );
		$this->assertArrayHasKey( 'on-hold', $data['orderStatuses'] );

		foreach ( array_keys( $data['orderStatuses'] ) as $slug ) {
			$this->assertStringStartsNotWith( 'wc-', (string) $slug );
		}

		$this->assertSame( Order_Actions::FINAL_STATUSES, $data['finalStatuses'] );
	}

	public function test_only_enabled_gateways_are_offered_and_each_has_a_readable_title(): void {
		$gateways = WC()->payment_gateways()->payment_gateways();

		$this->assertArrayHasKey( 'bacs', $gateways, 'the stock BACS gateway is registered' );

		$gateways['bacs']->enabled = 'no';
		$this->assertArrayNotHasKey( 'bacs', $this->bootstrap()['paymentMethods'] );

		$gateways['bacs']->enabled = 'yes';
		$methods                   = $this->bootstrap()['paymentMethods'];

		$this->assertArrayHasKey( 'bacs', $methods );
		$this->assertNotSame( '', $methods['bacs'] );

		foreach ( $methods as $id => $title ) {
			$this->assertArrayHasKey( $id, $gateways, 'every offered id is a registered gateway — the validator accepts it' );
			$this->assertSame( wp_strip_all_tags( $title ), $title, 'a title reaches React as text: no markup' );
		}
	}

	public function test_taxes_flag_follows_woocommerce(): void {
		$this->assertSame( (bool) wc_tax_enabled(), $this->bootstrap()['taxesEnabled'] );
	}
}
