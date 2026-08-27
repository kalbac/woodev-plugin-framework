<?php
/**
 * Shipping_Integration construction — integration tests.
 *
 * @package Woodev\Tests\Integration\Shipping
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Tests\Integration\TestCase;

/**
 * Covers the no-argument construction path WooCommerce itself takes.
 *
 * This lives in the integration suite deliberately: the defect it guards can only be
 * reproduced against a real `WC_Integration`/`WC_Settings_API`, whose `init_settings()`
 * the constructor calls. The unit suite's `WC_Integration` stand-in is an empty anonymous
 * class, so a unit test here would prove nothing about the real path.
 *
 * @since 2.0.2
 */
class ShippingIntegrationConstructionTest extends TestCase {

	/**
	 * WooCommerce constructs every registered integration with NO arguments, so the
	 * optional `$plugin` parameter is the path that actually runs.
	 *
	 * Regression: `__construct()` assigned `$this->plugin = $plugin ?? $this->init_plugin()`
	 * and then dereferenced `$plugin` — still null on that path — for the id, the title and
	 * the version. The result was a fatal raised from `WC_Integrations::__construct()` during
	 * WordPress `init`, i.e. before any test could run: the whole integration suite died in
	 * bootstrap with "Call to a member function get_id_underscored() on null". A store with a
	 * shipping integration on the «Интеграции» tab hit the same fatal on every admin request.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_constructing_without_a_plugin_falls_back_to_init_plugin(): void {

		$integration = new \Woodev_Test_Cdek_Integration();

		$plugin = \Woodev_Test_Shipping_Method_Plugin::instance();

		$this->assertSame(
			$plugin->get_id_underscored(),
			$integration->id,
			'the id must come from the plugin init_plugin() supplied, not from a null parameter'
		);

		$this->assertStringContainsString(
			$plugin->get_plugin_name(),
			$integration->method_title,
			'the method title must be built from the same plugin'
		);

		$this->assertStringContainsString(
			$plugin->get_version(),
			$integration->method_title,
			'the method title carries the plugin version'
		);
	}

	/**
	 * An explicitly passed plugin is still honoured.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_an_explicitly_passed_plugin_is_used(): void {

		$plugin = \Woodev_Test_Shipping_Method_Plugin::instance();

		$integration = new \Woodev_Test_Cdek_Integration( $plugin );

		$this->assertSame( $plugin->get_id_underscored(), $integration->id );
	}
}
