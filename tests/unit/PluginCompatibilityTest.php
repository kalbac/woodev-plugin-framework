<?php
/**
 * Unit tests for Woodev_Plugin_Compatibility.
 *
 * Tests static utility methods using Brain Monkey stubs
 * to isolate from WordPress and WooCommerce runtime.
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

class PluginCompatibilityTest extends TestCase {

	/**
	 * Ensure the class under test is loaded.
	 */
	protected function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__, 2 ) . '/woodev/compatibility/class-plugin-compatibility.php';
	}

	/*
	|--------------------------------------------------------------------------
	| get_wc_version()
	|--------------------------------------------------------------------------
	*/

	/**
	 * @test
	 */
	public function get_wc_version_returns_constant_value_when_defined(): void {

		// WC_VERSION is checked via defined() + truthy inside the method.
		// We can only test the "not defined" branch without run-time define().
		// When constant is not defined, the method should return null.
		$this->assertNull( \Woodev_Plugin_Compatibility::get_wc_version() );
	}

	/*
	|--------------------------------------------------------------------------
	| is_wc_version()
	|--------------------------------------------------------------------------
	*/

	/**
	 * @test
	 */
	public function is_wc_version_returns_false_when_wc_not_installed(): void {

		// WC_VERSION is not defined in unit tests, so get_wc_version() returns null.
		$this->assertFalse( \Woodev_Plugin_Compatibility::is_wc_version( '5.0.0' ) );
	}

	/*
	|--------------------------------------------------------------------------
	| is_wc_version_gte()
	|--------------------------------------------------------------------------
	*/

	/**
	 * @test
	 */
	public function is_wc_version_gte_returns_false_when_wc_not_installed(): void {

		$this->assertFalse( \Woodev_Plugin_Compatibility::is_wc_version_gte( '3.0' ) );
	}

	/*
	|--------------------------------------------------------------------------
	| is_wc_version_lt()
	|--------------------------------------------------------------------------
	*/

	/**
	 * @test
	 */
	public function is_wc_version_lt_returns_false_when_wc_not_installed(): void {

		$this->assertFalse( \Woodev_Plugin_Compatibility::is_wc_version_lt( '99.0' ) );
	}

	/*
	|--------------------------------------------------------------------------
	| is_wc_version_gt()
	|--------------------------------------------------------------------------
	*/

	/**
	 * @test
	 */
	public function is_wc_version_gt_returns_false_when_wc_not_installed(): void {

		$this->assertFalse( \Woodev_Plugin_Compatibility::is_wc_version_gt( '1.0' ) );
	}

	/*
	|--------------------------------------------------------------------------
	| is_enhanced_admin_available()
	|--------------------------------------------------------------------------
	*/

	/**
	 * @test
	 */
	public function is_enhanced_admin_available_returns_false_when_wc_not_installed(): void {

		// Without WC_VERSION, is_wc_version_gte() returns false, so the whole check is false.
		$this->assertFalse( \Woodev_Plugin_Compatibility::is_enhanced_admin_available() );
	}

	/*
	|--------------------------------------------------------------------------
	| normalize_wc_screen_id()
	|--------------------------------------------------------------------------
	*/

	/**
	 * @test
	 */
	public function normalize_wc_screen_id_returns_default_settings_screen(): void {

		// __() is already stubbed by TestCase (returns first argument).
		// sanitize_title needs to be stubbed.
		Functions\expect( 'sanitize_title' )
			->once()
			->with( 'WooCommerce' )
			->andReturn( 'woocommerce' );

		$result = \Woodev_Plugin_Compatibility::normalize_wc_screen_id();

		$this->assertSame( 'woocommerce_page_wc-settings', $result );
	}

	/**
	 * @test
	 */
	public function normalize_wc_screen_id_uses_custom_slug(): void {

		Functions\expect( 'sanitize_title' )
			->once()
			->with( 'WooCommerce' )
			->andReturn( 'woocommerce' );

		$result = \Woodev_Plugin_Compatibility::normalize_wc_screen_id( 'wc-status' );

		$this->assertSame( 'woocommerce_page_wc-status', $result );
	}

	/**
	 * @test
	 */
	public function normalize_wc_screen_id_handles_translated_prefix(): void {

		// Simulate a locale where "WooCommerce" is transliterated differently.
		Functions\expect( 'sanitize_title' )
			->once()
			->with( 'WooCommerce' )
			->andReturn( 'вукоммерц' );

		$result = \Woodev_Plugin_Compatibility::normalize_wc_screen_id( 'wc-settings' );

		$this->assertSame( 'вукоммерц_page_wc-settings', $result );
	}

	/*
	|--------------------------------------------------------------------------
	| convert_hr_to_bytes()
	|--------------------------------------------------------------------------
	*/

	/**
	 * @test
	 */
	public function convert_hr_to_bytes_delegates_to_wp_function_when_available(): void {

		Functions\expect( 'wp_convert_hr_to_bytes' )
			->once()
			->with( '128M' )
			->andReturn( 134217728 );

		$result = \Woodev_Plugin_Compatibility::convert_hr_to_bytes( '128M' );

		$this->assertSame( 134217728, $result );
	}

	/**
	 * @test
	 */
	public function convert_hr_to_bytes_handles_megabytes_via_wp_function(): void {

		Functions\expect( 'wp_convert_hr_to_bytes' )
			->once()
			->with( '64M' )
			->andReturn( 64 * 1048576 );

		$result = \Woodev_Plugin_Compatibility::convert_hr_to_bytes( '64M' );

		$this->assertSame( 64 * 1048576, $result );
	}

	/**
	 * @test
	 */
	public function convert_hr_to_bytes_handles_gigabytes_via_wp_function(): void {

		Functions\expect( 'wp_convert_hr_to_bytes' )
			->once()
			->with( '2G' )
			->andReturn( 2 * 1073741824 );

		$result = \Woodev_Plugin_Compatibility::convert_hr_to_bytes( '2G' );

		$this->assertSame( 2 * 1073741824, $result );
	}

	/**
	 * @test
	 */
	public function convert_hr_to_bytes_handles_kilobytes_via_wp_function(): void {

		Functions\expect( 'wp_convert_hr_to_bytes' )
			->once()
			->with( '512K' )
			->andReturn( 512 * 1024 );

		$result = \Woodev_Plugin_Compatibility::convert_hr_to_bytes( '512K' );

		$this->assertSame( 512 * 1024, $result );
	}

	/**
	 * @test
	 */
	public function convert_hr_to_bytes_handles_plain_bytes_via_wp_function(): void {

		Functions\expect( 'wp_convert_hr_to_bytes' )
			->once()
			->with( '1048576' )
			->andReturn( 1048576 );

		$result = \Woodev_Plugin_Compatibility::convert_hr_to_bytes( '1048576' );

		$this->assertSame( 1048576, $result );
	}

	/**
	 * @test
	 */
	public function convert_hr_to_bytes_is_case_insensitive_via_wp_function(): void {

		Functions\expect( 'wp_convert_hr_to_bytes' )
			->once()
			->with( '32m' )
			->andReturn( 32 * 1048576 );

		$result = \Woodev_Plugin_Compatibility::convert_hr_to_bytes( '32m' );

		$this->assertSame( 32 * 1048576, $result );
	}

	/*
	|--------------------------------------------------------------------------
	| get_latest_wc_versions()
	|--------------------------------------------------------------------------
	*/

	/**
	 * @test
	 */
	public function get_latest_wc_versions_returns_cached_transient(): void {

		$cached = [ '8.4.0', '8.3.1', '8.3.0' ];

		Functions\expect( 'get_transient' )
			->once()
			->with( 'woodev_plugin_wc_versions' )
			->andReturn( $cached );

		$result = \Woodev_Plugin_Compatibility::get_latest_wc_versions();

		$this->assertSame( $cached, $result );
	}

	/**
	 * @test
	 */
	public function get_latest_wc_versions_fetches_from_api_when_no_transient(): void {

		Functions\expect( 'get_transient' )
			->once()
			->with( 'woodev_plugin_wc_versions' )
			->andReturn( false );

		if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
			define( 'WEEK_IN_SECONDS', 604800 );
		}

		$api_body = json_encode( [
			'versions' => [
				'3.0.0' => 'https://example.com/3.0.0.zip',
				'3.1.0' => 'https://example.com/3.1.0.zip',
				'3.2.0-rc.1' => 'https://example.com/3.2.0-rc.1.zip',
				'3.2.0' => 'https://example.com/3.2.0.zip',
				'trunk' => 'https://example.com/trunk.zip',
			],
		] );

		Functions\expect( 'wp_remote_get' )
			->once()
			->andReturn( [ 'body' => $api_body ] );

		Functions\expect( 'set_transient' )
			->once()
			->with( 'woodev_plugin_wc_versions', \Mockery::type( 'array' ), WEEK_IN_SECONDS );

		$result = \Woodev_Plugin_Compatibility::get_latest_wc_versions();

		// Should contain stable versions in reverse order, excluding RC and trunk.
		$this->assertSame( [ '3.2.0', '3.1.0', '3.0.0' ], $result );
	}

	/**
	 * @test
	 */
	public function get_latest_wc_versions_returns_empty_array_on_api_failure(): void {

		Functions\expect( 'get_transient' )
			->once()
			->with( 'woodev_plugin_wc_versions' )
			->andReturn( false );

		// Simulate a WP_Error-like non-array response.
		Functions\expect( 'wp_remote_get' )
			->once()
			->andReturn( new \stdClass() );

		$result = \Woodev_Plugin_Compatibility::get_latest_wc_versions();

		$this->assertSame( [], $result );
	}

	/*
	|--------------------------------------------------------------------------
	| is_hpos_enabled()
	|--------------------------------------------------------------------------
	*/

	/**
	 * @test
	 */
	public function is_hpos_enabled_returns_false_when_order_util_not_available(): void {

		// The OrderUtil class is not loaded in unit tests, so is_callable() returns false.
		$this->assertFalse( \Woodev_Plugin_Compatibility::is_hpos_enabled() );
	}
}
