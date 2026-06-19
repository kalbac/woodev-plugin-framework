<?php

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

// Load the class under test so the behavioral test can instantiate it.
require_once dirname( __DIR__, 2 ) . '/woodev/admin/class-plugin-install-tab.php';

/**
 * Tests for Woodev_Plugin_Install_Tab (OB-8).
 *
 * The «Woodev» plugin-install.php tab no longer renders a legacy marketplace; it
 * redirects to the React «Плагины» catalog (admin.php?page=woodev-extensions),
 * mirroring how WooCommerce's marketplace tab redirects. Source-level assertions
 * verify the redirect wiring; behavioral tests verify the tab label and target.
 *
 * @since 2.0.2
 */
class PluginInstallTabTest extends TestCase {

	/** @var string */
	private string $source;

	/** @var string */
	private string $admin_pages_source;

	protected function setUp(): void {
		parent::setUp();
		$this->source             = (string) file_get_contents( dirname( __DIR__, 2 ) . '/woodev/admin/class-plugin-install-tab.php' );
		$this->admin_pages_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/woodev/admin/class-admin-pages.php' );
	}

	// ── File existence ────────────────────────────────────────────────────────

	public function test_class_file_exists(): void {
		self::assertFileExists( dirname( __DIR__, 2 ) . '/woodev/admin/class-plugin-install-tab.php' );
	}

	// ── Hook wiring (source-level) ────────────────────────────────────────────

	public function test_source_hooks_install_plugins_tabs_filter(): void {
		self::assertStringContainsString( "'install_plugins_tabs'", $this->source );
	}

	public function test_source_hooks_load_plugin_install_screen(): void {
		self::assertStringContainsString( "'load-plugin-install.php'", $this->source );
	}

	public function test_source_performs_safe_redirect(): void {
		self::assertStringContainsString( 'wp_safe_redirect', $this->source );
		self::assertStringContainsString( 'woodev-extensions', $this->source );
	}

	public function test_source_no_longer_renders_legacy_marketplace(): void {
		self::assertStringNotContainsString( 'install_plugins_pre_woodev', $this->source );
		self::assertStringNotContainsString( 'Woodev_Admin_Plugins', $this->source );
	}

	public function test_source_registers_woodev_tab_key(): void {
		self::assertStringContainsString( "'woodev'", $this->source );
	}

	// ── Admin pages integration (source-level) ────────────────────────────────

	public function test_admin_pages_wires_plugin_install_tab(): void {
		self::assertStringContainsString( 'Woodev_Plugin_Install_Tab', $this->admin_pages_source );
	}

	public function test_admin_pages_calls_init_plugin_install_tab(): void {
		self::assertStringContainsString( 'init_plugin_install_tab', $this->admin_pages_source );
	}

	// ── Behavioral ────────────────────────────────────────────────────────────

	/**
	 * register_tab() must insert a 'woodev' => string entry into the tabs array.
	 *
	 * @since 2.0.2
	 */
	public function test_register_tab_adds_woodev_key(): void {
		$tab    = new \Woodev_Plugin_Install_Tab();
		$result = $tab->register_tab( [] );

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'woodev', $result );
		self::assertIsString( $result['woodev'] );
		self::assertNotEmpty( $result['woodev'] );
	}

	/**
	 * get_redirect_url() must target the React extensions catalog page.
	 *
	 * @since 2.0.2
	 */
	public function test_redirect_url_targets_extensions_page(): void {
		Functions\when( 'admin_url' )->returnArg( 1 );

		$tab = new \Woodev_Plugin_Install_Tab();

		self::assertSame( 'admin.php?page=woodev-extensions', $tab->get_redirect_url() );
	}
}
