<?php
/**
 * Woodev_Admin_Pages render tests — license page mount point + enqueue.
 *
 * Covers:
 *  (1) license_page() outputs only the React mount point <div> and the
 *      settings-section include; NO <form> element.
 *  (2) load_licenses_page_scripts() reads deps/version from index.asset.php,
 *      enqueues the correct handles, and inlines window.woodevLicenses with
 *      restRoot, restNonce, and the initial plugin states.
 *  (3) B-7 invariant: both tests run with ZERO WooCommerce functions defined
 *      (Brain Monkey defines none). The licensing admin surface is WC-agnostic.
 *
 * Approach for stubbing the html-settings-section.php include:
 *   get_settings_section() calls
 *   include_once $this->woodev_plugin->get_framework_path() .
 *               '/admin/pages/views/html-settings-section.php'.
 *   We point get_framework_path() at the test-fixture directory
 *   (tests/_fixtures/woodev-test-plugin) which contains a minimal no-op stub
 *   of that view file.  This exercises the real include code path without
 *   loading WordPress or the real file's markup.
 *
 * Approach for the static license registry:
 *   Woodev_Plugins_License::$registered_instances is reset/seeded via
 *   reflection (gotcha testing/reflection-setaccessible-version-guard).
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Mockery;
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 2 ) . '/woodev/class-helper.php';
require_once dirname( __DIR__, 2 ) . '/woodev/api/interface-api-request.php';
require_once dirname( __DIR__, 2 ) . '/woodev/api/abstract-api-json-request.php';
require_once dirname( __DIR__, 2 ) . '/woodev/api/class-api-base.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/api/class-licensing-api.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/api/class-licensing-api-request.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-store.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-messages.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-plugin-license.php';
require_once dirname( __DIR__, 2 ) . '/woodev/admin/class-admin-pages.php';

/**
 * Class LicensePageRenderTest.
 *
 * B-7 note: Brain Monkey stubs translation functions and escape functions (see
 * TestCase::setUp) but defines ZERO WooCommerce functions. Tests here run in
 * the same process as other unit tests — no WC functions are defined prior to
 * this suite because the licensing UI was built WC-agnostic (spec §3/§4.4).
 */
class LicensePageRenderTest extends TestCase {

	/**
	 * Absolute path to the test-plugin fixture root.
	 *
	 * get_framework_path() is stubbed to return this path so that
	 * get_settings_section()'s include resolves to a minimal no-op stub.
	 *
	 * @var string
	 */
	private string $fixture_path;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->fixture_path = dirname( __DIR__ ) . '/_fixtures/woodev-test-plugin';
		$this->reset_license_registry();
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		$this->reset_license_registry();
		parent::tearDown();
	}

	/* ----------------------------------------------------------------------- *
	 * (1) license_page() output
	 * ----------------------------------------------------------------------- */

	/**
	 * license_page() must output the #woodev-licenses-app mount div and must NOT
	 * contain any <form element.
	 *
	 * @return void
	 */
	public function test_license_page_outputs_mount_div_without_form(): void {
		$pages = $this->make_admin_pages();

		ob_start();
		$pages->license_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="woodev-licenses-app"', $output );
		$this->assertStringNotContainsString( '<form', $output );
	}

	/**
	 * license_page() output contains the React mount div exactly once.
	 *
	 * @return void
	 */
	public function test_license_page_contains_exactly_one_mount_div(): void {
		$pages = $this->make_admin_pages();

		ob_start();
		$pages->license_page();
		$output = ob_get_clean();

		$this->assertSame( 1, substr_count( $output, 'id="woodev-licenses-app"' ) );
	}

	/* ----------------------------------------------------------------------- *
	 * (2) load_licenses_page_scripts() enqueue
	 * ----------------------------------------------------------------------- */

	/**
	 * load_licenses_page_scripts() reads the fixture index.asset.php and calls
	 * wp_enqueue_script with the correct handle, URL, deps, version, and
	 * the in-footer flag set to true.
	 *
	 * The inline script payload must contain restRoot, restNonce, and a
	 * plugins array that includes the seeded engine's get_state().
	 *
	 * @return void
	 */
	public function test_load_licenses_page_scripts_enqueues_correct_handle_and_inlines_state(): void {

		// Seed a license engine whose get_state() we assert appears in the payload.
		$engine_state = array(
			'plugin_id'       => '216',
			'plugin_name'     => 'Test Plugin',
			'license_key'     => 'KEY-123',
			'status'          => 'valid',
			'status_label'    => 'License is valid',
			'message'         => '',
			'message_variant' => 'success',
			'expires'         => 'lifetime',
			'is_valid'        => true,
			'is_active'       => true,
			'is_need_license' => true,
			'beta_enabled'    => false,
		);

		$engine = Mockery::mock( \Woodev_Plugins_License::class );
		$engine->shouldReceive( 'get_state' )->once()->andReturn( $engine_state );
		$this->seed_license_registry( '216', $engine );

		// Capture wp_enqueue_style calls.
		$enqueued_styles = array();
		Functions\when( 'wp_enqueue_style' )->alias(
			function ( $handle, $src = false, $deps = array(), $ver = false, $media = false ) use ( &$enqueued_styles ) {
				$enqueued_styles[ $handle ] = array(
					'handle' => $handle,
					'src'    => $src,
					'deps'   => $deps,
					'ver'    => $ver,
				);
			}
		);

		// Capture wp_enqueue_script calls.
		$enqueued_scripts = array();
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle, $src = false, $deps = array(), $ver = false, $in_footer = false ) use ( &$enqueued_scripts ) {
				$enqueued_scripts[ $handle ] = array(
					'handle'    => $handle,
					'src'       => $src,
					'deps'      => $deps,
					'ver'       => $ver,
					'in_footer' => $in_footer,
				);
			}
		);

		// Capture wp_add_inline_script calls.
		$inline_scripts = array();
		Functions\when( 'wp_add_inline_script' )->alias(
			function ( $handle, $data, $position = 'after' ) use ( &$inline_scripts ) {
				$inline_scripts[] = array(
					'handle'   => $handle,
					'data'     => $data,
					'position' => $position,
				);
			}
		);

		// Stub WP functions used inside load_licenses_page_scripts().
		Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_create_nonce' )->justReturn( 'test-nonce-value' );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);

		$pages = $this->make_admin_pages();
		$pages->load_licenses_page_scripts();

		// Assert wp_enqueue_style('wp-components') was called.
		$this->assertArrayHasKey( 'wp-components', $enqueued_styles );
		$this->assertFalse( $enqueued_styles['wp-components']['src'] );

		// Assert app styles registered with wp-components dep and fixture version.
		$this->assertArrayHasKey( 'woodev-license-app', $enqueued_styles );
		$this->assertSame( array( 'wp-components' ), $enqueued_styles['woodev-license-app']['deps'] );
		$this->assertSame( 'fixture-v1', $enqueued_styles['woodev-license-app']['ver'] );

		// Assert app script registered with full fixture dep array and in-footer=true.
		$this->assertArrayHasKey( 'woodev-license-app', $enqueued_scripts );
		$this->assertSame(
			array( 'wp-api-fetch', 'wp-components', 'wp-element', 'wp-i18n' ),
			$enqueued_scripts['woodev-license-app']['deps']
		);
		$this->assertSame( 'fixture-v1', $enqueued_scripts['woodev-license-app']['ver'] );
		$this->assertTrue( $enqueued_scripts['woodev-license-app']['in_footer'] );

		// Assert inline script inlined BEFORE the bundle with required payload keys.
		$found_inline = null;
		foreach ( $inline_scripts as $inline ) {
			if ( 'woodev-license-app' === $inline['handle'] && 'before' === $inline['position'] ) {
				$found_inline = $inline['data'];
				break;
			}
		}

		$this->assertNotNull( $found_inline, 'No woodev-license-app inline script with position "before" was captured.' );

		// Extract the JSON payload from the inline script string.
		// Expected form: window.woodevLicenses = {...};
		$matches = array();
		$this->assertSame(
			1,
			preg_match( '/window\.woodevLicenses\s*=\s*(\{.*\});/s', $found_inline, $matches ),
			'Inline script must contain "window.woodevLicenses = {...};"'
		);

		$payload = json_decode( $matches[1], true );
		$this->assertIsArray( $payload, 'window.woodevLicenses JSON must decode to an array.' );

		// Exact top-level keys.
		$this->assertArrayHasKey( 'restRoot', $payload );
		$this->assertArrayHasKey( 'restNonce', $payload );
		$this->assertArrayHasKey( 'plugins', $payload );

		// Exact values for root/nonce.
		$this->assertSame( 'https://example.test/wp-json/', $payload['restRoot'] );
		$this->assertSame( 'test-nonce-value', $payload['restNonce'] );

		// plugins[0] must exactly match the seeded engine's get_state() array.
		$this->assertIsArray( $payload['plugins'] );
		$this->assertCount( 1, $payload['plugins'] );
		$this->assertSame( $engine_state, $payload['plugins'][0] );
	}

	/**
	 * load_licenses_page_scripts() falls back to empty deps + plugin version
	 * when index.asset.php does not exist (e.g. fresh checkout before build).
	 *
	 * @return void
	 */
	public function test_load_licenses_page_scripts_falls_back_when_asset_file_absent(): void {

		$enqueued_scripts = array();
		Functions\when( 'wp_enqueue_style' )->justReturn();
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle, $src = false, $deps = array(), $ver = false, $in_footer = false ) use ( &$enqueued_scripts ) {
				$enqueued_scripts[ $handle ] = array( 'deps' => $deps, 'ver' => $ver );
			}
		);
		Functions\when( 'wp_add_inline_script' )->justReturn();
		Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$pages = $this->make_admin_pages_without_asset_file();
		$pages->load_licenses_page_scripts();

		// With a missing asset file the fallback uses empty deps and plugin version.
		$this->assertArrayHasKey( 'woodev-license-app', $enqueued_scripts );
		$this->assertSame( array(), $enqueued_scripts['woodev-license-app']['deps'] );
		$this->assertSame( '2.0.0-test', $enqueued_scripts['woodev-license-app']['ver'] );
	}

	/* ----------------------------------------------------------------------- *
	 * (3) B-7 — WooCommerce-agnostic
	 * ----------------------------------------------------------------------- */

	/**
	 * B-7: the license admin page loads and renders correctly with ZERO
	 * WooCommerce functions defined.
	 *
	 * Brain Monkey stubs only translation/escape functions (see TestCase::setUp)
	 * and we explicitly assert WC is absent before exercising any method. If any
	 * code path on this page required WC, it would throw/fatal in this context.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_b7_license_page_works_with_zero_woocommerce_present(): void {
		// Prove WooCommerce is absent before exercising anything.
		$this->assertFalse( function_exists( 'WC' ), 'WC() must be undefined for the agnosticism test.' );
		$this->assertFalse( function_exists( 'wc_get_order' ), 'wc_get_order() must be undefined.' );
		$this->assertFalse( class_exists( 'WooCommerce', false ), 'WooCommerce class must be absent.' );

		$pages = $this->make_admin_pages();

		ob_start();
		$pages->license_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="woodev-licenses-app"', $output );
		$this->assertStringNotContainsString( '<form', $output );
	}

	/* ----------------------------------------------------------------------- *
	 * Helpers
	 * ----------------------------------------------------------------------- */

	/**
	 * Builds a Woodev_Admin_Pages instance with a plugin stub that points
	 * get_framework_path() at the test-plugin fixture (for the settings-section
	 * include) and get_framework_assets_url() at a dummy URL.
	 *
	 * @return \Woodev_Admin_Pages
	 */
	private function make_admin_pages(): \Woodev_Admin_Pages {
		return $this->make_admin_pages_with_framework_path( $this->fixture_path );
	}

	/**
	 * Builds a Woodev_Admin_Pages instance with a non-existent asset-file path
	 * so that load_licenses_page_scripts() exercises the fallback branch.
	 *
	 * @return \Woodev_Admin_Pages
	 */
	private function make_admin_pages_without_asset_file(): \Woodev_Admin_Pages {
		// /nonexistent/path will not contain index.asset.php.
		return $this->make_admin_pages_with_framework_path( '/nonexistent/path' );
	}

	/**
	 * Shared factory: builds a Woodev_Admin_Pages with the given framework path.
	 *
	 * @param string $framework_path Path returned by get_framework_path().
	 * @return \Woodev_Admin_Pages
	 */
	private function make_admin_pages_with_framework_path( string $framework_path ): \Woodev_Admin_Pages {
		$plugin = Mockery::mock( \Woodev_Plugin::class );
		$plugin->shouldReceive( 'get_framework_path' )->andReturn( $framework_path );
		$plugin->shouldReceive( 'get_framework_assets_url' )->andReturn( 'https://example.test/assets' );
		$plugin->shouldReceive( 'get_version' )->andReturn( '2.0.0-test' );

		$pages = new \Woodev_Admin_Pages();

		$this->set_property( $pages, 'woodev_plugin', $plugin );

		return $pages;
	}

	/**
	 * Seeds one engine into the Woodev_Plugins_License static registry.
	 *
	 * @param string $plugin_id Download id key.
	 * @param object $engine    License engine mock.
	 * @return void
	 */
	private function seed_license_registry( string $plugin_id, $engine ): void {
		$property = new \ReflectionProperty( \Woodev_Plugins_License::class, 'registered_instances' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$registry               = (array) $property->getValue();
		$registry[ $plugin_id ] = $engine;
		$property->setValue( null, $registry );
	}

	/**
	 * Empties the Woodev_Plugins_License static registry.
	 *
	 * @return void
	 */
	private function reset_license_registry(): void {
		$property = new \ReflectionProperty( \Woodev_Plugins_License::class, 'registered_instances' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( null, array() );
	}

	/**
	 * Sets a property value via reflection (handles private/protected).
	 *
	 * @param object $object   Target object.
	 * @param string $property Property name.
	 * @param mixed  $value    Value to set.
	 * @return void
	 */
	private function set_property( $object, string $property, $value ): void {
		$reflection_property = new \ReflectionProperty( $object, $property );
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection_property->setAccessible( true );
		}
		$reflection_property->setValue( $object, $value );
	}
}
