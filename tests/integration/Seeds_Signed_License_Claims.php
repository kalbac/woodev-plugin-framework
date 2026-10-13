<?php
/**
 * Gives the integration environment an in-force licence the honest way (#1270).
 *
 * The licence gate closes checkout, export and cancel for any plugin that has a store product and no
 * VERIFIED signed claim. The fixture plugins (download ids 9001-9006, 216, 821) have none, so without
 * this every shipping integration test would meet a closed gate. Nothing here switches the gate off:
 *
 * - a throw-away Ed25519 keypair is generated for the process;
 * - its public key is handed to the claim store through the `woodev_license_authority_pubkey` filter,
 *   the same test-only seam LicenseCommandEndpointTest uses;
 * - for every registered licence engine with a store product, a paid, `valid`, lifetime claim bound to
 *   `home_url()` and to the plugin's download id is signed and fed to the engine the way a licence-server
 *   response is: {@see \Woodev_License_Authority_Claims::consume_from_response()}, which verifies it.
 *
 * `WP_UnitTestCase` rolls the options back after each test, but the claim store memoizes its verified
 * payload on the engine, which lives for the whole PHP process — so the memo is dropped on both sides of
 * every test, or a claim would outlive the option that backs it.
 *
 * @package Woodev\Tests\Integration
 */

namespace Woodev\Tests\Integration;

/**
 * @since 2.0.2
 */
trait Seeds_Signed_License_Claims {

	/**
	 * The secret half of the process-wide test keypair, or null before the first use.
	 *
	 * @var string|null
	 */
	private static ?string $license_test_secret_key = null;

	/**
	 * The public half of the process-wide test keypair (base64), or null before the first use.
	 *
	 * @var string|null
	 */
	private static ?string $license_test_public_key = null;

	/**
	 * Points the claim store at the test public key and seeds an in-force claim for every fixture plugin.
	 *
	 * @return void
	 */
	protected function seed_in_force_license_claims(): void {

		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			$this->markTestSkipped( 'ext-sodium not available in this PHP runtime (CI runs it).' );
		}

		if ( null === self::$license_test_secret_key ) {
			$keypair                         = sodium_crypto_sign_keypair();
			self::$license_test_secret_key   = sodium_crypto_sign_secretkey( $keypair );
			self::$license_test_public_key   = base64_encode( sodium_crypto_sign_publickey( $keypair ) );
		}

		// Priority 10: a test that installs its own key at a later priority (LicenseCommandEndpointTest, 99)
		// still wins. WP_UnitTestCase restores the hook table after each test, so this never leaks.
		$public_key = self::$license_test_public_key;
		add_filter(
			'woodev_license_authority_pubkey',
			static function () use ( $public_key ): string {
				return $public_key;
			},
			10
		);

		foreach ( $this->licensed_engines() as $engine ) {
			$this->forget_license_claim( $engine );
			$engine->get_authority_claims()->consume_from_response( array( 'license_authority' => $this->sign_in_force_claim( $engine ) ) );

			// consume_from_response() swallows every failure by design, so a claim that did not take would
			// surface as a closed gate far from here. Say so at the source.
			$this->assertTrue(
				$engine->is_license_in_force(),
				sprintf( 'The signed test claim for download id %s did not verify; the licence gate would stay closed.', $engine->get_plugin()->get_download_id() )
			);
		}
	}

	/**
	 * Drops the memoized claim of every licence engine, so what the next read sees is the option store.
	 *
	 * @return void
	 */
	protected function forget_license_claims(): void {
		foreach ( $this->licensed_engines() as $engine ) {
			$this->forget_license_claim( $engine );
		}
	}

	/**
	 * Removes a plugin's stored claim, as if the licence server had never answered for it.
	 *
	 * @param \Woodev_Plugin $plugin the plugin whose claim goes.
	 *
	 * @return void
	 */
	protected function remove_license_claim( \Woodev_Plugin $plugin ): void {
		delete_option( $plugin->get_plugin_option_name( 'license_required' ) );
		$this->forget_license_claim( $plugin->get_license_instance() );
	}

	/**
	 * Every registered licence engine whose plugin has a store product (the only ones the gate asks about).
	 *
	 * @return \Woodev_Plugins_License[]
	 */
	private function licensed_engines(): array {

		$engines = array();

		foreach ( \Woodev_Plugins_License::get_registered_instances() as $engine ) {
			if ( \Woodev_Plugins_License::has_store_product( $engine->get_plugin()->get_download_id() ) ) {
				$engines[] = $engine;
			}
		}

		return $engines;
	}

	/**
	 * Signs a paid, valid, lifetime licence statement for the engine's plugin and wraps it in an envelope.
	 *
	 * @param \Woodev_Plugins_License $engine the engine the claim is for.
	 *
	 * @return array{payload: array<string, mixed>, signature: string}
	 */
	private function sign_in_force_claim( \Woodev_Plugins_License $engine ): array {

		$now     = time();
		$payload = array(
			'license_required' => true,
			'license_status'   => 'valid',
			'license_expires'  => null,
			'issued_at'        => $now,
			'expires_at'       => $now + ( 14 * DAY_IN_SECONDS ),
			'site'             => home_url(),
			'plugin_id'        => (string) $engine->get_plugin()->get_download_id(),
		);

		return array(
			'payload'   => $payload,
			'signature' => base64_encode( sodium_crypto_sign_detached( \Woodev_License_Envelope_Verifier::canonical_json( $payload ), self::$license_test_secret_key ) ),
		);
	}

	/**
	 * Resets the claim store's per-process memo of the verified payload.
	 *
	 * @param \Woodev_Plugins_License $engine the engine whose store is reset.
	 *
	 * @return void
	 */
	private function forget_license_claim( \Woodev_Plugins_License $engine ): void {

		$property = new \ReflectionProperty( \Woodev_License_Authority_Claims::class, 'memoized' );

		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}

		$property->setValue( $engine->get_authority_claims(), false );
	}
}
