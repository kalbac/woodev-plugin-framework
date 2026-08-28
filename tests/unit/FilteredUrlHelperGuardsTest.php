<?php
/**
 * Guards on the shared URL filter-return validator — #613 tranche 2, "URL filters +
 * licensing API".
 *
 * Five independent call sites each filtered a URL on their own — `woodev_account_api_url`
 * (account connection's `api_base()`, the account installer's SECURITY-relevant
 * `is_trusted_package_url()`, and the admin "Плагины" page's `myAccountUrl`),
 * `woodev_account_authorize_url` (account connection's `authorize_url()`), and
 * `woodev_extensions_store_url` (the extensions REST controller's `store_base()`) — sharing
 * the identical crash shape: an untyped `apply_filters()` return fed straight into
 * `untrailingslashit()`/string concatenation, so a plugin returning an array or object
 * fataled whatever page called it. One of those five is a SECURITY check (the
 * package-download trust host). `Woodev_Helper::filtered_url()` closes all five with one
 * validated seam.
 *
 * (The #613 audit counted these as "six FATAL rows"; a source sweep for the three hook
 * names above turned up only five real call sites — see the report for the discrepancy.)
 *
 * Two further, unrelated sites in the licensing API had the same untyped-return shape but
 * took the cheapest possible fix instead of the shared helper: `get_url()` falls back to
 * the constructor's already-validated default via the pre-existing `is_valid_url()`;
 * `is_debug_enabled()` just needed a `(bool)` cast.
 *
 * The rule applied, settled in s100 and reaffirmed on #613: degrade to a safe default;
 * never throw, and never disable a protection — `is_trusted_package_url()` degrades to the
 * PRODUCTION store host on a hostile filter return, it never silently trusts whatever a
 * plugin handed back.
 *
 * Every site gets a PAIR:
 *   - a hostile/garbage return does not fatal, and the pre-filter value survives;
 *   - a legitimate return is still HONOURED — the half that catches a guard which simply
 *     ignores the hook and would silently break the local e2e rig / self-hosted store
 *     override these hooks exist for.
 *
 * `Woodev_Helper::filtered_url()` deliberately does NOT use `wp_http_validate_url()`:
 * several of the hooks it guards exist to repoint at a local rig on a non-standard port
 * (`http://localhost:8090`) or a self-hosted store on a private host, and
 * `wp_http_validate_url()` rejects exactly those hosts/ports by default unless they match
 * the site's own `home` URL. Validation here is syntactic only (scheme +
 * `FILTER_VALIDATE_URL`), mirroring the pre-existing `Woodev_Licensing_API::is_valid_url()`.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/class-helper.php';
require_once dirname( __DIR__, 2 ) . '/woodev/account/class-account-signer.php';
require_once dirname( __DIR__, 2 ) . '/woodev/account/class-account-connection.php';
require_once dirname( __DIR__, 2 ) . '/woodev/account/class-account-installer.php';
require_once dirname( __DIR__, 2 ) . '/woodev/rest-api/controllers/class-rest-api-extensions.php';
require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 2 ) . '/woodev/api/interface-api-request.php';
require_once dirname( __DIR__, 2 ) . '/woodev/api/abstract-api-json-request.php';
require_once dirname( __DIR__, 2 ) . '/woodev/api/class-api-base.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/api/class-licensing-api.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/api/class-licensing-api-request.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-store.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-messages.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-plugin-license.php';

/**
 * Minimal plugin double for the licensing-API constructor.
 */
class Woodev_Test_Plugin_For_Filtered_Url_Guards extends \Woodev_Plugin {

	/** Avoid parent construction for isolated helper tests. */
	public function __construct() {}

	/** @return string */
	protected function get_file() {
		return __FILE__;
	}

	/** @return string */
	public function get_plugin_name() {
		return 'Filtered URL Guards Test Plugin';
	}

	/** @return string */
	public function get_download_id() {
		return 'filtered-url-guards';
	}
}

/**
 * @coversNothing
 */
final class FilteredUrlHelperGuardsTest extends TestCase {

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'untrailingslashit' )->alias(
			static function ( $url ) {
				return rtrim( (string) $url, '/\\' );
			}
		);
		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url ) {
				return parse_url( (string) $url );
			}
		);
		// Default: filters return the passed default value unchanged.
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) {
				return $value;
			}
		);
	}

	/** Invokes a private/protected method via reflection. */
	private function call_private( $object, string $method, array $args = [] ) {
		$ref = new \ReflectionMethod( $object, $method );
		if ( PHP_VERSION_ID < 80100 ) {
			$ref->setAccessible( true );
		}
		return $ref->invokeArgs( $object, $args );
	}

	/** Makes `apply_filters()` return $value for $hook and the unfiltered value otherwise. */
	private function filter_returns( string $hook, $value ): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $default = null ) use ( $hook, $value ) {
				return $hook === $tag ? $value : $default;
			}
		);
	}

	/* ------------------------------------------------------------------ *
	 * Woodev_Helper::filtered_url() — the shared guard itself.
	 * ------------------------------------------------------------------ */

	/**
	 * @return void
	 */
	public function test_filtered_url_falls_back_when_filter_returns_a_non_string(): void {
		$this->filter_returns( 'guards_test_hook', [ 'not', 'a', 'url' ] );

		$this->assertSame( 'https://woodev.ru', \Woodev_Helper::filtered_url( 'guards_test_hook', 'https://woodev.ru' ) );
	}

	/**
	 * @return void
	 */
	public function test_filtered_url_falls_back_when_filter_returns_an_empty_string(): void {
		$this->filter_returns( 'guards_test_hook', '   ' );

		$this->assertSame( 'https://woodev.ru', \Woodev_Helper::filtered_url( 'guards_test_hook', 'https://woodev.ru' ) );
	}

	/**
	 * @return void
	 */
	public function test_filtered_url_falls_back_when_filter_returns_a_non_http_scheme(): void {
		$this->filter_returns( 'guards_test_hook', 'javascript:alert(1)' );

		$this->assertSame( 'https://woodev.ru', \Woodev_Helper::filtered_url( 'guards_test_hook', 'https://woodev.ru' ) );
	}

	/**
	 * @return void
	 */
	public function test_filtered_url_falls_back_when_filter_returns_a_malformed_url(): void {
		$this->filter_returns( 'guards_test_hook', 'not a url at all' );

		$this->assertSame( 'https://woodev.ru', \Woodev_Helper::filtered_url( 'guards_test_hook', 'https://woodev.ru' ) );
	}

	/**
	 * The control: a real, well-formed URL is still honoured.
	 *
	 * @return void
	 */
	public function test_filtered_url_honours_a_legitimate_https_override(): void {
		$this->filter_returns( 'guards_test_hook', 'https://staging.woodev.ru/base/' );

		$this->assertSame( 'https://staging.woodev.ru/base', \Woodev_Helper::filtered_url( 'guards_test_hook', 'https://woodev.ru' ) );
	}

	/**
	 * `wp_http_validate_url()` would reject this (non-standard port, not the site's own
	 * `home` host) — the whole point of these hooks is to survive exactly this override.
	 *
	 * @return void
	 */
	public function test_filtered_url_honours_a_legitimate_local_rig_override_on_a_non_standard_port(): void {
		$this->filter_returns( 'guards_test_hook', 'http://localhost:8090' );

		$this->assertSame( 'http://localhost:8090', \Woodev_Helper::filtered_url( 'guards_test_hook', 'https://woodev.ru' ) );
	}

	/**
	 * @return void
	 */
	public function test_filtered_url_passes_extra_args_through_to_apply_filters(): void {
		$seen = null;
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $default = null, $extra = null ) use ( &$seen ) {
				$seen = $extra;
				return $default;
			}
		);

		$marker = new \stdClass();
		\Woodev_Helper::filtered_url( 'guards_test_hook', 'https://woodev.ru', $marker );

		$this->assertSame( $marker, $seen );
	}

	/* ------------------------------------------------------------------ *
	 * Woodev_Account_Connection::api_base() — `woodev_account_api_url`.
	 * ------------------------------------------------------------------ */

	/**
	 * @return void
	 */
	public function test_account_api_base_falls_back_when_filter_returns_garbage(): void {
		$this->filter_returns( 'woodev_account_api_url', [ 'bad' ] );

		$this->assertSame( 'https://woodev.ru', $this->call_private( new \Woodev_Account_Connection(), 'api_base' ) );
	}

	/**
	 * @return void
	 */
	public function test_account_api_base_honours_a_legitimate_override(): void {
		$this->filter_returns( 'woodev_account_api_url', 'http://host.docker.internal:8090' );

		$this->assertSame( 'http://host.docker.internal:8090', $this->call_private( new \Woodev_Account_Connection(), 'api_base' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Woodev_Account_Connection::authorize_url() — `woodev_account_authorize_url`.
	 * ------------------------------------------------------------------ */

	/**
	 * @return void
	 */
	public function test_authorize_url_falls_back_to_api_base_when_filter_returns_garbage(): void {
		$this->filter_returns( 'woodev_account_authorize_url', new \stdClass() );

		$this->assertSame( 'https://woodev.ru/', $this->call_private( new \Woodev_Account_Connection(), 'authorize_url' ) );
	}

	/**
	 * @return void
	 */
	public function test_authorize_url_honours_a_legitimate_override(): void {
		$this->filter_returns( 'woodev_account_authorize_url', 'http://localhost:8090' );

		$this->assertSame( 'http://localhost:8090/', $this->call_private( new \Woodev_Account_Connection(), 'authorize_url' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Woodev_Account_Installer::is_trusted_package_url() — SECURITY check,
	 * `woodev_account_api_url`.
	 * ------------------------------------------------------------------ */

	/**
	 * A hostile return must not fatal, and must not silently widen trust: the production
	 * store host stays the anchor, and an unrelated host stays rejected.
	 *
	 * @return void
	 */
	public function test_is_trusted_package_url_falls_back_to_the_default_store_when_filter_returns_garbage(): void {
		$this->filter_returns( 'woodev_account_api_url', [ 'bad' ] );

		$this->assertTrue( \Woodev_Account_Installer::is_trusted_package_url( 'https://woodev.ru/index.php?eddfile=1:1:0&token=a' ) );
		$this->assertFalse( \Woodev_Account_Installer::is_trusted_package_url( 'https://attacker.example/payload.zip' ) );
	}

	/**
	 * The control: a legitimate store override moves the trust anchor with it — a guard
	 * that just ignores the hook would keep trusting the (now stale) production host.
	 *
	 * @return void
	 */
	public function test_is_trusted_package_url_honours_a_legitimate_store_override(): void {
		$this->filter_returns( 'woodev_account_api_url', 'https://staging.woodev.ru' );

		$this->assertTrue( \Woodev_Account_Installer::is_trusted_package_url( 'https://staging.woodev.ru/index.php?eddfile=1:1:0&token=a' ) );
		$this->assertFalse( \Woodev_Account_Installer::is_trusted_package_url( 'https://woodev.ru/index.php?eddfile=1:1:0&token=a' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Woodev_REST_API_Extensions::store_base() — `woodev_extensions_store_url`.
	 * ------------------------------------------------------------------ */

	/**
	 * @return void
	 */
	public function test_store_base_falls_back_when_filter_returns_garbage(): void {
		$this->filter_returns( 'woodev_extensions_store_url', false );

		$this->assertSame( 'https://woodev.ru', $this->call_private( new \Woodev_REST_API_Extensions(), 'store_base' ) );
	}

	/**
	 * @return void
	 */
	public function test_store_base_honours_a_legitimate_override(): void {
		$this->filter_returns( 'woodev_extensions_store_url', 'http://localhost:8090' );

		$this->assertSame( 'http://localhost:8090', $this->call_private( new \Woodev_REST_API_Extensions(), 'store_base' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Woodev_Licensing_API::get_url() — `woodev_license_base_url`, the cheapest
	 * fix in the audit: falls back to the constructor's validated default.
	 * ------------------------------------------------------------------ */

	/**
	 * @return void
	 */
	public function test_get_url_falls_back_to_the_constructor_default_when_filter_returns_garbage(): void {
		$plugin = new Woodev_Test_Plugin_For_Filtered_Url_Guards();
		$this->filter_returns( 'woodev_license_base_url', [ 'bad' ] );

		$this->assertSame( 'https://woodev.ru/', ( new \Woodev_Licensing_API( $plugin ) )->get_url() );
	}

	/**
	 * @return void
	 */
	public function test_get_url_honours_a_legitimate_override(): void {
		$plugin = new Woodev_Test_Plugin_For_Filtered_Url_Guards();
		$this->filter_returns( 'woodev_license_base_url', 'https://custom.example/api' );

		$this->assertSame( 'https://custom.example/api', ( new \Woodev_Licensing_API( $plugin ) )->get_url() );
	}

	/* ------------------------------------------------------------------ *
	 * Woodev_Licensing_API::is_debug_enabled() — `woodev_enable_license_logging`,
	 * the other cheapest fix: a (bool) cast.
	 * ------------------------------------------------------------------ */

	/**
	 * A `: bool`-typed method throws a TypeError on an array/object return since PHP does
	 * not coerce those to bool for a return-type declaration.
	 *
	 * @return void
	 */
	public function test_is_debug_enabled_does_not_fatal_when_filter_returns_a_non_bool(): void {
		$plugin = new Woodev_Test_Plugin_For_Filtered_Url_Guards();
		$this->filter_returns( 'woodev_enable_license_logging', [ 'not', 'a', 'bool' ] );

		$this->assertTrue( ( new \Woodev_Licensing_API( $plugin ) )->is_debug_enabled() );
	}

	/**
	 * The control: a real bool flips the flag away from its default (no
	 * WOODEV_LICENSE_DEBUG constant defined here, so the pre-filter default is false).
	 *
	 * @return void
	 */
	public function test_is_debug_enabled_honours_a_real_bool_return(): void {
		$plugin = new Woodev_Test_Plugin_For_Filtered_Url_Guards();
		$this->filter_returns( 'woodev_enable_license_logging', true );

		$this->assertTrue( ( new \Woodev_Licensing_API( $plugin ) )->is_debug_enabled() );
	}
}
