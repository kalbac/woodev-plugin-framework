<?php
/**
 * Integration: settings page REST routes (woodev/v1/settings).
 *
 * Proves the aggregated controller registers under woodev/v1 and that a real
 * dispatch honours the capability gate: an editor (lacks manage_options) is
 * forbidden, an administrator gets the «Карьер» tab schema back.
 *
 * Modelled on SetupWizardRestTest (rest_get_server / WP_REST_Request / dispatch).
 *
 * @package Woodev\Tests\Integration
 */

namespace Woodev\Tests\Integration;

use Woodev\Framework\Settings\Settings_Page_Registry;
use Woodev\Framework\Settings\Settings_Provider;
use Woodev\Framework\Settings\Settings_Section;
use WP_REST_Request;

class SettingsPageRestTest extends TestCase {

	/**
	 * Registers the test plugin's provider and rebuilds the REST server so the
	 * settings controller (re-hooked on rest_api_init) registers its routes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$registry = Settings_Page_Registry::instance();
		$registry->reset_for_tests();
		$registry->register_plugin( woodev_test_plugin() );

		// Force a fresh REST server so rest_api_init fires with the re-added hook.
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
	}

	public function test_settings_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes( 'woodev/v1' );

		$this->assertArrayHasKey( '/woodev/v1/settings', $routes );
		$this->assertArrayHasKey( '/woodev/v1/settings/(?P<provider_id>[\w-]+)', $routes );
	}

	public function test_get_schema_forbidden_for_editor(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/woodev/v1/settings' ) );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_get_schema_returns_quarry_tab_for_admin(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/woodev/v1/settings' ) );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$ids  = array_column( is_array( $data ) ? ( $data['tabs'] ?? [] ) : [], 'id' );
		$this->assertContains( 'quarry', $ids );
	}

	/**
	 * An untouched (masked) secret must reach the test via the stored value.
	 *
	 * @return void
	 */
	public function test_test_connection_merges_stored_secret_for_untouched_field(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		// The provider's handler returns success only when it receives token === 'good'.
		// Pre-store 'good'; POST an EMPTY body → the route must merge the stored secret.
		$this->seed_provider_with_connection( 'good' );

		$request = new WP_REST_Request( 'POST', '/woodev/v1/settings/carrier/connection/api/test' );
		$request->set_param( 'values', [] ); // untouched: nothing typed.
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
	}

	/**
	 * A freshly typed value must override the stored secret.
	 *
	 * @return void
	 */
	public function test_test_connection_uses_posted_value_over_stored(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->seed_provider_with_connection( 'good' ); // stored is good.

		$request = new WP_REST_Request( 'POST', '/woodev/v1/settings/carrier/connection/api/test' );
		$request->set_param( 'values', [ 'token' => 'bad' ] ); // user typed a wrong new token.
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['success'] );
	}

	/**
	 * The action route enforces the provider capability (manage_options here).
	 *
	 * @return void
	 */
	public function test_test_connection_requires_capability(): void {
		$this->seed_provider_with_connection( 'good' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$request = new WP_REST_Request( 'POST', '/woodev/v1/settings/carrier/connection/api/test' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * A non-secret field's explicitly-empty POSTed value must be honored, not
	 * silently replaced by the stored value (the stored-fallback is for masked
	 * secrets only). Stored mode='stored-mode'; the test succeeds iff the merge
	 * yields mode='' (posted, empty) AND token='good' (secret fallback, untouched).
	 *
	 * @return void
	 */
	public function test_test_connection_honors_empty_posted_value_for_non_secret_field(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->seed_provider_with_mixed_connection();

		$request = new WP_REST_Request( 'POST', '/woodev/v1/settings/carrier/connection/api/test' );
		$request->set_param( 'values', [ 'mode' => '' ] ); // intentionally cleared non-secret; token untouched.
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'], 'empty non-secret value must win over the stored value' );
	}

	/**
	 * Registers a `carrier` provider whose connection block mixes a non-secret
	 * `mode` (stored 'stored-mode') with a sensitive `token` (stored 'good'). The
	 * handler succeeds only when the merge yields mode='' AND token='good'.
	 *
	 * @return void
	 */
	private function seed_provider_with_mixed_connection(): void {
		$handler = new class( 'carrier' ) extends \Woodev_Abstract_Settings implements \Woodev_Settings_Connection_Test {

			/**
			 * Registers a non-secret mode + a sensitive token.
			 *
			 * @return void
			 */
			protected function register_settings() {
				$this->register_setting( 'mode', \Woodev_Setting::TYPE_STRING, [ 'name' => 'Mode', 'default' => '' ] );
				$this->register_setting( 'token', \Woodev_Setting::TYPE_STRING, [ 'name' => 'Token', 'sensitive' => true, 'default' => '' ] );
			}

			/**
			 * Succeeds iff mode is the empty string and token === 'good'.
			 *
			 * @param string              $connection_id connection section id.
			 * @param array<string,mixed> $values        merged field values.
			 * @return \Woodev_Connection_Result
			 */
			public function test_connection( string $connection_id, array $values ): \Woodev_Connection_Result {
				return ( '' === ( $values['mode'] ?? null ) && 'good' === ( $values['token'] ?? null ) )
					? \Woodev_Connection_Result::success( 'OK' )
					: \Woodev_Connection_Result::failure( 'Нет.' );
			}
		};

		$handler->update_value( 'mode', 'stored-mode' );
		$handler->update_value( 'token', 'good' );

		$provider = Settings_Provider::create(
			'carrier',
			'Carrier',
			$handler,
			[
				Settings_Section::create( 'api', 'API', [ 'mode', 'token' ], '', true, 'Проверить' ),
			]
		);

		$registry = Settings_Page_Registry::instance();
		$registry->register_service( $provider );

		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
	}

	/**
	 * Registers a `carrier` provider whose handler implements the connection-test
	 * seam (success iff token === 'good') and persists $stored into the token
	 * option so get_value('token') returns it.
	 *
	 * Registered as a framework service (no owning plugin → neutral manage_options
	 * capability), so a subscriber is correctly forbidden by the capability gate.
	 *
	 * @param string $stored value to persist into the connection's token secret.
	 * @return void
	 */
	private function seed_provider_with_connection( string $stored ): void {
		$handler = new class( 'carrier' ) extends \Woodev_Abstract_Settings implements \Woodev_Settings_Connection_Test {

			/**
			 * Registers a single sensitive token secret.
			 *
			 * @return void
			 */
			protected function register_settings() {
				$this->register_setting(
					'token',
					\Woodev_Setting::TYPE_STRING,
					[
						'name'      => 'Token',
						'sensitive' => true,
						'default'   => '',
					]
				);
			}

			/**
			 * Succeeds only when it receives token === 'good'.
			 *
			 * @param string              $connection_id connection section id.
			 * @param array<string,mixed> $values        merged field values.
			 * @return \Woodev_Connection_Result
			 */
			public function test_connection( string $connection_id, array $values ): \Woodev_Connection_Result {
				return ( isset( $values['token'] ) && 'good' === $values['token'] )
					? \Woodev_Connection_Result::success( 'OK' )
					: \Woodev_Connection_Result::failure( 'Неверный токен.' );
			}
		};

		// Persist the secret so an untouched-field test reads it back via get_value().
		$handler->update_value( 'token', $stored );

		$provider = Settings_Provider::create(
			'carrier',
			'Carrier',
			$handler,
			[
				Settings_Section::create( 'api', 'API', [ 'token' ], '', true, 'Проверить' ),
			]
		);

		$registry = Settings_Page_Registry::instance();
		$registry->register_service( $provider );

		// Rebuild the REST server so the freshly added route/provider are visible.
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
	}
}
