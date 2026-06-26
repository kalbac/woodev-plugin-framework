<?php
/**
 * Settings page integration tests — menu, schema, save, legacy redirect, caps.
 *
 * @package Woodev\Tests\Integration
 */

namespace Woodev\Tests\Integration;

use Woodev\Framework\Settings\Settings_Page_Registry;
use Woodev\Framework\Settings\Settings_Provider;
use Woodev\Framework\Settings\Settings_Section;

class SettingsPageTest extends TestCase {

	/**
	 * Returns a clean registry with only the test plugin registered.
	 */
	private function registry(): Settings_Page_Registry {
		$registry = Settings_Page_Registry::instance();
		$registry->reset_for_tests();
		$registry->register_plugin( woodev_test_plugin() );

		return $registry;
	}

	public function test_menu_registers_when_provider_present(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$registry = $this->registry();
		$registry->register_page();

		global $submenu;
		$slugs = array_column( $submenu['woodev'] ?? [], 2 );

		$this->assertContains( Settings_Page_Registry::PAGE_SLUG, $slugs );
	}

	public function test_get_schema_returns_quarry_tab(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$registry = $this->registry();
		$tabs     = $registry->get_tabs();

		$ids = array_column( $tabs, 'id' );
		$this->assertContains( 'quarry', $ids );

		$quarry = $tabs[ array_search( 'quarry', $ids, true ) ];
		$this->assertArrayHasKey( 'api_key', $quarry['sections'][0]['fields'] );
	}

	public function test_save_persists_through_handler(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$registry = $this->registry();
		$provider = $registry->get_provider( 'quarry' );
		$provider->get_handler()->update_value( 'api_key', 'persisted-key' );

		$this->assertSame( 'persisted-key', get_option( 'woodev_woodev-test-plugin_api_key' ) );
	}

	public function test_legacy_page_descriptor_is_exposed(): void {
		$registry = $this->registry();
		$provider = $registry->get_provider( 'quarry' );

		// The redirect target is built from this; the wp_safe_redirect/exit path
		// itself is exercised manually on the rig.
		$this->assertSame( 'wc-settings&tab=shipping&section=quarry', $provider->get_legacy_page() );
	}

	/**
	 * Discharges Decision 5 point 4: with a WC-capability tab present the page cap
	 * resolves to manage_woocommerce and the submenu is registered under that cap,
	 * so a shop manager (who holds manage_woocommerce, not manage_options) can reach
	 * it — and the cap-filtered schema hides the manage_options-only quarry tab.
	 *
	 * Registers the submenu directly (not via do_action('admin_menu'), which would
	 * also fire WooCommerce's admin_menu callbacks — some WC versions print a PHP
	 * deprecation notice that PHPUnit treats as unexpected output). WP's own
	 * parent-menu promotion is core behaviour and is not re-tested here.
	 */
	public function test_shop_manager_reaches_settings_submenu(): void {
		$registry = Settings_Page_Registry::instance();
		$registry->reset_for_tests();
		$registry->register_plugin( woodev_test_plugin() );
		// A WC-capability tab so the page cap resolves to manage_woocommerce.
		$registry->register_service(
			Settings_Provider::create(
				'wc_only',
				'WC',
				woodev_test_plugin()->get_settings_handler(),
				[ Settings_Section::create( 'general', 'Общие', [ 'api_key' ] ) ],
				[ 'capability' => 'manage_woocommerce' ]
			)
		);

		$this->assertSame( 'manage_woocommerce', $registry->get_page_capability() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		// Isolate from any prior test's menu registration: the global $submenu
		// accumulates across tests, so an earlier admin/quarry-only register_page()
		// left a manage_options entry with the same slug.
		global $submenu;
		unset( $submenu['woodev'] );

		$registry->register_page();

		$entries = $submenu['woodev'] ?? [];

		$settings_entry = null;
		foreach ( $entries as $entry ) {
			if ( Settings_Page_Registry::PAGE_SLUG === $entry[2] ) {
				$settings_entry = $entry;
				break;
			}
		}

		$this->assertNotNull( $settings_entry, 'The settings submenu must be registered.' );
		// Registered under the cap a shop manager holds → reachable.
		$this->assertSame( 'manage_woocommerce', $settings_entry[1] );

		// Shop manager sees the WC tab but NOT the manage_options-only quarry tab.
		$tab_ids = array_column( $registry->get_tabs(), 'id' );
		$this->assertContains( 'wc_only', $tab_ids );
		$this->assertNotContains( 'quarry', $tab_ids );
	}
}
