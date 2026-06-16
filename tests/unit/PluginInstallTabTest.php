<?php

namespace Woodev\Tests\Unit;

// Load the class under test so the behavioral test can instantiate it.
require_once dirname( __DIR__, 2 ) . '/woodev/admin/class-plugin-install-tab.php';

/**
 * Tests for Woodev_Plugin_Install_Tab (OB-8).
 *
 * Source-level assertions verify structural contracts (hook wiring, view wiring).
 * The behavioral test verifies register_tab() actually inserts the 'woodev' key.
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

	public function test_view_file_exists(): void {
		self::assertFileExists( dirname( __DIR__, 2 ) . '/woodev/admin/pages/views/html-plugin-install-tab.php' );
	}

	// ── Hook wiring (source-level) ────────────────────────────────────────────

	public function test_source_hooks_install_plugins_tabs_filter(): void {
		self::assertNotEmpty( $this->source, 'class-plugin-install-tab.php must not be empty' );
		self::assertStringContainsString( "'install_plugins_tabs'", $this->source );
	}

	public function test_source_hooks_install_plugins_pre_woodev_action(): void {
		self::assertNotEmpty( $this->source, 'class-plugin-install-tab.php must not be empty' );
		self::assertStringContainsString( "'install_plugins_pre_woodev'", $this->source );
	}

	public function test_source_enqueues_styles_via_admin_enqueue_scripts(): void {
		self::assertNotEmpty( $this->source, 'class-plugin-install-tab.php must not be empty' );
		self::assertStringContainsString( "'admin_enqueue_scripts'", $this->source );
	}

	public function test_source_registers_woodev_tab_key(): void {
		self::assertNotEmpty( $this->source, 'class-plugin-install-tab.php must not be empty' );
		self::assertStringContainsString( "'woodev'", $this->source );
	}

	// ── Admin pages integration (source-level) ────────────────────────────────

	public function test_admin_pages_wires_plugin_install_tab(): void {
		self::assertNotEmpty( $this->admin_pages_source, 'class-admin-pages.php must not be empty' );
		self::assertStringContainsString( 'Woodev_Plugin_Install_Tab', $this->admin_pages_source );
	}

	public function test_admin_pages_calls_init_plugin_install_tab(): void {
		self::assertNotEmpty( $this->admin_pages_source, 'class-admin-pages.php must not be empty' );
		self::assertStringContainsString( 'init_plugin_install_tab', $this->admin_pages_source );
	}

	// ── Behavioral: register_tab() ────────────────────────────────────────────

	/**
	 * register_tab() must insert a 'woodev' => string entry into the tabs array.
	 *
	 * Uses newInstanceWithoutConstructor() to avoid the Woodev_Plugin dependency
	 * (constructor type is not checked when bypassed via Reflection).
	 *
	 * @since 2.0.2
	 */
	public function test_register_tab_adds_woodev_key(): void {
		$ref    = new \ReflectionClass( \Woodev_Plugin_Install_Tab::class );
		$inst   = $ref->newInstanceWithoutConstructor();
		$method = $ref->getMethod( 'register_tab' );

		$result = $method->invoke( $inst, [] );

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'woodev', $result );
		self::assertIsString( $result['woodev'] );
	}
}
