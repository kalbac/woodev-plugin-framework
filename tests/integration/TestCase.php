<?php
/**
 * Base Integration Test Case
 *
 * Все интеграционные тесты наследуются от этого класса.
 * Требует запущенного wp-env.
 * Имеет доступ к реальному WordPress и WooCommerce.
 */

namespace Woodev\Tests\Integration;

use WP_UnitTestCase;

abstract class TestCase extends WP_UnitTestCase {

	use Seeds_Signed_License_Claims;

	/**
	 * Инициализация перед каждым тестом.
	 */
	protected function setUp(): void {
		parent::setUp();

		// Убеждаемся что фреймворк инициализирован
		if ( ! class_exists( 'Woodev_Plugin_Bootstrap' ) ) {
			$this->fail( 'Woodev Framework is not loaded. Make sure wp-env is running.' );
		}

		// The shipping-tools registry memoizes its collection for the whole PHP process
		// (`Shipping_Tools_Registry::$collected`), while `Shipping_Settings_Tab::register()`
		// runs on `init` 25 in EVERY request. Without this reset, the FIRST test in the run
		// that reaches `init` freezes the tool list -- and the provider-selector options
		// built from it -- for every test after it.
		//
		// Latent today: no integration test asserts on tools yet, so nothing is currently
		// wrong. It is here because that class of leak does not present as "the harness is
		// stale". It presents as a defect in the code under test, which is expensive to
		// diagnose and one line to prevent (#514/m2).
		//
		// `Location_Provider_Registry` is deliberately NOT reset here. The two tests that
		// need it reset it at the point in the test where the registry must be empty, not
		// at setUp, and hoisting that would change what they measure.
		if ( class_exists( '\Woodev\Framework\Shipping\Settings\Shipping_Tools_Registry' ) ) {
			\Woodev\Framework\Shipping\Settings\Shipping_Tools_Registry::reset_for_tests();
		}

		// The licence gate (#1270) refuses checkout, export and cancel for a plugin with a store product and
		// no verified signed claim. The fixture plugins have none, so each test starts with an in-force one
		// signed by a test key; a test that needs the closed gate removes it (see remove_license_claim()).
		$this->seed_in_force_license_claims();
	}

	/**
	 * Drops the claim store's per-process memo: the option behind it is rolled back with the test, the
	 * memo would not be.
	 */
	protected function tearDown(): void {
		$this->forget_license_claims();

		parent::tearDown();
	}

	/**
	 * Хелпер: получить экземпляр тестового плагина.
	 */
	protected function get_test_plugin(): \Woodev_Plugin {
		return woodev_test_plugin();
	}
}
