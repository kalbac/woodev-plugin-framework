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
	/**
	 * Issue #391 — the test-environment feature was dead, and silently.
	 *
	 * `private array $environments = []` is a TYPED property with an initialiser, so it is
	 * never unset and the `! isset( $this->environments )` guard around the seeding branch
	 * could never open. `get_environments()` therefore returned `[]` forever:
	 * `init_form_fields()` gates the environment selector on `count( … ) > 1` (so `0 > 1`,
	 * never rendered) and `get_environment_name()` fell through to the raw id.
	 *
	 * `Woodev_Payment_Gateway` runs the identical idiom correctly only because its property
	 * carries neither type nor initialiser (`class-payment-gateway.php:141`) — which is why
	 * this survived a copy-paste review.
	 *
	 * Integration rather than unit: `esc_html_x()` is a real WordPress call, and the point is
	 * what a constructed integration actually reports.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_get_environments_carries_production(): void {

		$integration = new \Woodev_Test_Cdek_Integration();

		$environments = $integration->get_environments();

		$this->assertNotSame( [], $environments, 'the seeding branch must actually run' );
		$this->assertArrayHasKey( \Woodev\Framework\Shipping\Shipping_Integration::ENVIRONMENT_PRODUCTION, $environments );
	}

	/**
	 * The consequence, pinned separately from the cause. `get_environment_name()` takes NO
	 * argument — it resolves whatever `get_environment()` reads from the saved options — and
	 * with an EMPTY environment set its `isset()` lookup always missed, so it returned the raw
	 * id `production` instead of the label. That reads as a display bug three layers away from
	 * the property that caused it.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_get_environment_name_resolves_the_current_environment_to_its_label(): void {

		$integration = new \Woodev_Test_Cdek_Integration();
		$production  = \Woodev\Framework\Shipping\Shipping_Integration::ENVIRONMENT_PRODUCTION;

		$this->assertSame( $production, $integration->get_environment(), 'the default with nothing saved' );

		$this->assertNotSame(
			$production,
			$integration->get_environment_name(),
			'the current environment must resolve to a label, not fall through to the id'
		);
		$this->assertSame( $integration->get_environments()[ $production ], $integration->get_environment_name() );
	}

	/**
	 * The control: the fall-through is still there for an id the set does NOT carry. Without
	 * it, the assertion above would pass for a method that had stopped looking anything up and
	 * simply returned a constant label.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_control_get_environment_name_falls_through_for_an_unknown_id(): void {

		$integration = new \Woodev_Test_Cdek_Integration();

		$integration->update_option( 'environment', 'no-such-environment' );

		$this->assertSame( 'no-such-environment', $integration->get_environment_name() );

		$integration->update_option( 'environment', \Woodev\Framework\Shipping\Shipping_Integration::ENVIRONMENT_PRODUCTION );
	}


	/**
	 * Issue #399 — `add_support()` announced itself on `wc_payment_gateway_{id}_supports_{x}`,
	 * copied wholesale from the payment gateway. Its own sibling `remove_support()` already
	 * used `woodev_shipping_integration_…`, which is what proves the prefix was a mistake
	 * rather than a decision.
	 *
	 * Both hooks are asserted in one test, because the pairing is the point: a subscriber
	 * has to be able to hear both halves in the same namespace.
	 *
	 * @since 2.0.2
	 *
	 * @return void
	 */
	public function test_support_hooks_fire_in_the_shipping_integration_namespace(): void {

		$integration = new \Woodev_Test_Cdek_Integration();
		$id          = $integration->id;
		$heard       = [];

		$record = static function () use ( &$heard ): void {
			$heard[] = current_action();
		};

		$added   = 'woodev_shipping_integration_' . $id . '_supports_widgets';
		$removed = 'woodev_shipping_integration_' . $id . '_removed_support_widgets';
		$legacy  = 'wc_payment_gateway_' . $id . '_supports_widgets';

		add_action( $added, $record );
		add_action( $removed, $record );
		add_action( $legacy, $record );

		$integration->add_support( 'widgets' );
		$integration->remove_support( 'widgets' );

		remove_action( $added, $record );
		remove_action( $removed, $record );
		remove_action( $legacy, $record );

		$this->assertSame( [ $added, $removed ], $heard );
		$this->assertNotContains( $legacy, $heard, 'the payment-gateway namespace must be silent' );
	}
}
