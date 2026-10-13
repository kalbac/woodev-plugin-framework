<?php
/**
 * The licence-in-force predicate (#1270) and the claim rules behind it.
 *
 * Envelopes are signed at runtime with the published fixture keypair (seed = chr(1) x 32), the same way
 * LicenseAuthorityClaimsTest does, and stored in an in-memory option map — so the whole path is real:
 * consume_from_response() → verify at rest → is_license_in_force(). Time is pinned through the claim
 * store's own `now()` seam.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Mockery;
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/woodev/class-plugin.php';
require_once dirname( __DIR__, 2 ) . '/woodev/functions-license-authority.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-envelope-verifier.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-license-authority-claims.php';
require_once dirname( __DIR__, 2 ) . '/woodev/licensing/class-plugin-license.php';

/**
 * Class LicenseInForceTest.
 */
class LicenseInForceTest extends TestCase {

	/** @var string the published test-vector public key (base64), seed = chr(1) x 32 */
	private const VECTOR_PUBKEY = 'iojj3XQJ8ZX9UtstPLpdcspnCb8dlBIb83SIAbQPb1w=';

	/** @var string the option the test plugin's claim is stored under */
	private const CLAIM_OPTION = 'woodev_test_plugin_license_required';

	/** @var int the moment the tests live at */
	private const NOW = 1800000000;

	/** @var array<string, mixed> the in-memory option table */
	private array $options = [];

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->options = [];

		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth );
			}
		);
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);
		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url, $component = -1 ) {
				return \parse_url( (string) $url );
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
		Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				return 'woodev_license_authority_pubkey' === $hook ? self::VECTOR_PUBKEY : $value;
			}
		);

		if ( ! function_exists( 'sodium_crypto_sign_detached' ) ) {
			$this->markTestSkipped( 'ext-sodium not available in this PHP runtime.' );
		}
	}

	/**
	 * A paid-product payload: valid, ending in ten days, signed a day ago, valid for 14 days.
	 *
	 * @param array<string, mixed> $overrides fields to replace (a null value removes the key).
	 * @return array<string, mixed>
	 */
	private function paid( array $overrides = [] ): array {
		$payload = [
			'site'            => 'https://example.com',
			'plugin_id'       => '9001',
			'license_required' => true,
			'license_status'  => 'valid',
			'license_expires' => self::NOW + 10 * DAY_IN_SECONDS,
			'issued_at'       => self::NOW - DAY_IN_SECONDS,
			'expires_at'      => self::NOW + 13 * DAY_IN_SECONDS,
		];

		foreach ( $overrides as $key => $value ) {
			if ( '__remove' === $value ) {
				unset( $payload[ $key ] );
			} else {
				$payload[ $key ] = $value;
			}
		}

		return $payload;
	}

	/**
	 * A licence-free payload — today's claim, no licence statement.
	 *
	 * @return array<string, mixed>
	 */
	private function free(): array {
		return [
			'site'             => 'https://example.com',
			'plugin_id'        => '9001',
			'license_required' => false,
			'issued_at'        => self::NOW - DAY_IN_SECONDS,
			'expires_at'       => self::NOW + 13 * DAY_IN_SECONDS,
		];
	}

	/**
	 * @param array<string, mixed> $payload the payload to sign.
	 * @return array<string, mixed> the { payload, signature } envelope.
	 */
	private function sign( array $payload ): array {
		$keypair = sodium_crypto_sign_seed_keypair( str_repeat( "\x01", 32 ) );

		return [
			'payload'   => $payload,
			'signature' => base64_encode(
				sodium_crypto_sign_detached(
					\Woodev_License_Envelope_Verifier::canonical_json( $payload ),
					sodium_crypto_sign_secretkey( $keypair )
				)
			),
		];
	}

	/**
	 * A claim store of plugin `$download_id`, with the clock pinned at `$now`.
	 *
	 * @param int $download_id the plugin's download id.
	 * @param int $now         the Unix time the store sees.
	 * @return \Woodev_License_Authority_Claims
	 */
	private function claims( int $download_id = 9001, int $now = self::NOW ): \Woodev_License_Authority_Claims {
		$plugin = Mockery::mock( \Woodev_Plugin::class );
		$plugin->shouldReceive( 'get_plugin_option_name' )->with( 'license_required' )->andReturn( self::CLAIM_OPTION )->byDefault();
		$plugin->shouldReceive( 'get_download_id' )->andReturn( $download_id )->byDefault();

		return new class( $plugin, $now ) extends \Woodev_License_Authority_Claims {

			private int $pinned_now;

			public function __construct( \Woodev_Plugin $plugin, int $now ) {
				parent::__construct( $plugin );

				$this->pinned_now = $now;
			}

			protected function now(): int {
				return $this->pinned_now;
			}
		};
	}

	/**
	 * Feeds a payload through the real consume path and answers whether the licence is then in force.
	 *
	 * @param array<string, mixed> $payload the payload the server signed.
	 * @return bool
	 */
	private function in_force_after_consuming( array $payload ): bool {
		$claims = $this->claims();
		$claims->consume_from_response( [ 'license_authority' => $this->sign( $payload ) ] );

		return $claims->is_license_in_force();
	}

	/**
	 * @return void
	 */
	public function test_no_claim_is_not_in_force(): void {
		$this->assertFalse( $this->claims()->is_license_in_force() );
	}

	/**
	 * @return void
	 */
	public function test_a_licence_free_claim_is_in_force(): void {
		$this->assertTrue( $this->in_force_after_consuming( $this->free() ) );
	}

	/**
	 * A licence-free claim from before the licence statement existed carries no `issued_at` at all.
	 *
	 * @return void
	 */
	public function test_a_licence_free_claim_without_issued_at_is_still_in_force(): void {
		$payload = $this->free();
		unset( $payload['issued_at'] );

		$this->assertTrue( $this->in_force_after_consuming( $payload ) );
	}

	/**
	 * @return void
	 */
	public function test_a_valid_paid_claim_is_in_force(): void {
		$this->assertTrue( $this->in_force_after_consuming( $this->paid() ) );
	}

	/**
	 * @return void
	 */
	public function test_a_lifetime_licence_is_in_force(): void {
		$this->assertTrue( $this->in_force_after_consuming( $this->paid( [ 'license_expires' => null ] ) ) );
	}

	/**
	 * The licence ends at `license_expires`, inclusive: the last second still counts, the next does not.
	 *
	 * @return void
	 */
	public function test_the_licence_ends_by_its_date_inclusive(): void {
		$expires = self::NOW + 100;
		$payload = $this->paid( [ 'license_expires' => $expires ] );

		$claims = $this->claims( 9001, $expires );
		$claims->consume_from_response( [ 'license_authority' => $this->sign( $payload ) ] );
		$this->assertTrue( $claims->is_license_in_force(), 'now === license_expires is still in force' );

		$this->options = [];
		$late          = $this->claims( 9001, $expires + 1 );
		$late->consume_from_response( [ 'license_authority' => $this->sign( $payload ) ] );
		$this->assertFalse( $late->is_license_in_force(), 'one second later it is not' );
	}

	/**
	 * A licence that was valid when it was signed but ends BEFORE the claim itself does: the stored claim still
	 * verifies, and the gate closes on the licence date, not on the claim's own expiry.
	 *
	 * @return void
	 */
	public function test_a_stored_claim_stops_being_in_force_when_its_licence_date_passes(): void {
		$payload = $this->paid( [ 'license_expires' => self::NOW + 100 ] );

		$this->claims()->consume_from_response( [ 'license_authority' => $this->sign( $payload ) ] );

		$this->assertTrue( $this->claims( 9001, self::NOW + 50 )->is_license_in_force() );
		$this->assertFalse( $this->claims( 9001, self::NOW + 101 )->is_license_in_force() );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function not_valid_statuses(): array {
		return [
			'expired'       => [ 'expired' ],
			'disabled'      => [ 'disabled' ],
			'site_inactive' => [ 'site_inactive' ],
			'invalid'       => [ 'invalid' ],
			'inactive'      => [ 'inactive' ],
			'item mismatch' => [ 'invalid_item_id' ],
		];
	}

	/**
	 * @dataProvider not_valid_statuses
	 *
	 * @param string $status the EDD status.
	 * @return void
	 */
	public function test_a_licence_that_is_not_valid_is_not_in_force( string $status ): void {
		$this->assertFalse( $this->in_force_after_consuming( $this->paid( [ 'license_status' => $status ] ) ) );
	}

	/**
	 * A tampered claim: the stored envelope's licence statement is rewritten after signing.
	 *
	 * @return void
	 */
	public function test_a_tampered_claim_is_not_in_force(): void {
		$envelope                               = $this->sign( $this->paid( [ 'license_status' => 'expired' ] ) );
		$envelope['payload']['license_status']  = 'valid';
		$this->options[ self::CLAIM_OPTION ]    = $envelope;

		$this->assertFalse( $this->claims()->is_license_in_force() );
	}

	/**
	 * A forged envelope signed with another key never verifies.
	 *
	 * @return void
	 */
	public function test_an_envelope_signed_with_another_key_is_not_in_force(): void {
		$payload = $this->paid();
		$other   = sodium_crypto_sign_seed_keypair( str_repeat( "\x02", 32 ) );

		$this->options[ self::CLAIM_OPTION ] = [
			'payload'   => $payload,
			'signature' => base64_encode( sodium_crypto_sign_detached( \Woodev_License_Envelope_Verifier::canonical_json( $payload ), sodium_crypto_sign_secretkey( $other ) ) ),
		];

		$this->assertFalse( $this->claims()->is_license_in_force() );
	}

	/**
	 * @return void
	 */
	public function test_a_claim_for_another_site_is_not_in_force(): void {
		$this->assertFalse( $this->in_force_after_consuming( $this->paid( [ 'site' => 'https://other.example' ] ) ) );
	}

	/**
	 * @return void
	 */
	public function test_a_claim_for_another_plugin_is_not_in_force(): void {
		$this->assertFalse( $this->in_force_after_consuming( $this->paid( [ 'plugin_id' => '9002' ] ) ) );
	}

	/**
	 * The claim itself runs out after its 14 days.
	 *
	 * @return void
	 */
	public function test_a_claim_past_its_own_expiry_is_not_in_force(): void {
		$payload = $this->paid( [ 'expires_at' => self::NOW + 10 ] );

		$this->claims()->consume_from_response( [ 'license_authority' => $this->sign( $payload ) ] );

		$this->assertTrue( $this->claims( 9001, self::NOW + 10 )->is_license_in_force() );
		$this->assertFalse( $this->claims( 9001, self::NOW + 11 )->is_license_in_force() );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function malformed_paid_claims(): array {
		return [
			'status is an int'          => [ [ 'license_status' => 1 ] ],
			'status is a bool'          => [ [ 'license_status' => true ] ],
			'status is empty'           => [ [ 'license_status' => '' ] ],
			'status is missing'         => [ [ 'license_status' => '__remove' ] ],
			'expires is a string'       => [ [ 'license_expires' => '1800000000' ] ],
			'expires is a float'        => [ [ 'license_expires' => 1800000000.5 ] ],
			'expires is a bool'         => [ [ 'license_expires' => true ] ],
			'expires is missing'        => [ [ 'license_expires' => '__remove' ] ],
			'issued_at is a string'     => [ [ 'issued_at' => '1799999999' ] ],
			'issued_at is missing'      => [ [ 'issued_at' => '__remove' ] ],
			'license_required not bool' => [ [ 'license_required' => 1 ] ],
		];
	}

	/**
	 * A malformed paid claim is rejected whole: it is never stored, so nothing is in force.
	 *
	 * @dataProvider malformed_paid_claims
	 *
	 * @param array<string, mixed> $overrides what to break.
	 * @return void
	 */
	public function test_a_malformed_paid_claim_is_rejected_whole( array $overrides ): void {
		$claims = $this->claims();
		$claims->consume_from_response( [ 'license_authority' => $this->sign( $this->paid( $overrides ) ) ] );

		$this->assertArrayNotHasKey( self::CLAIM_OPTION, $this->options, 'never stored' );
		$this->assertFalse( $claims->is_license_in_force() );
	}

	/**
	 * A malformed claim cannot replace a good one either.
	 *
	 * @return void
	 */
	public function test_a_malformed_claim_leaves_the_stored_one_alone(): void {
		$claims = $this->claims();
		$claims->consume_from_response( [ 'license_authority' => $this->sign( $this->paid() ) ] );
		$claims->consume_from_response( [ 'license_authority' => $this->sign( $this->paid( [ 'license_status' => 7, 'issued_at' => self::NOW ] ) ) ] );

		$this->assertTrue( $claims->is_license_in_force() );
	}

	/**
	 * Anti-rollback: a replayed OLD statement ("valid", signed last month) does not replace a newer one.
	 *
	 * @return void
	 */
	public function test_an_older_claim_does_not_replace_a_newer_one(): void {
		$claims = $this->claims();

		$claims->consume_from_response( [ 'license_authority' => $this->sign( $this->paid( [ 'license_status' => 'expired', 'issued_at' => self::NOW - 100 ] ) ) ] );
		$this->assertFalse( $claims->is_license_in_force() );

		// The pirate's replay: the old «valid», signed before the expiry was recorded.
		$claims->consume_from_response( [ 'license_authority' => $this->sign( $this->paid( [ 'license_status' => 'valid', 'issued_at' => self::NOW - 200 ] ) ) ] );

		$this->assertFalse( $claims->is_license_in_force(), 'the older «valid» is ignored' );
		$this->assertFalse( $this->claims()->is_license_in_force(), 'and so it is on the next request' );
	}

	/**
	 * @return void
	 */
	public function test_a_newer_claim_replaces_the_stored_one(): void {
		$claims = $this->claims();

		$claims->consume_from_response( [ 'license_authority' => $this->sign( $this->paid( [ 'issued_at' => self::NOW - 200 ] ) ) ] );
		$this->assertTrue( $claims->is_license_in_force() );

		$claims->consume_from_response( [ 'license_authority' => $this->sign( $this->paid( [ 'license_status' => 'expired', 'issued_at' => self::NOW - 100 ] ) ) ] );

		$this->assertFalse( $claims->is_license_in_force(), 'the newer «expired» wins at once' );
	}

	/**
	 * Equal `issued_at` replaces: a check made within the same second must be able to correct itself.
	 *
	 * @return void
	 */
	public function test_an_equal_issued_at_replaces(): void {
		$claims = $this->claims();

		$claims->consume_from_response( [ 'license_authority' => $this->sign( $this->paid( [ 'issued_at' => self::NOW - 50 ] ) ) ] );
		$claims->consume_from_response( [ 'license_authority' => $this->sign( $this->paid( [ 'license_status' => 'disabled', 'issued_at' => self::NOW - 50 ] ) ) ] );

		$this->assertFalse( $claims->is_license_in_force() );
	}

	/**
	 * A stored claim that no longer verifies (its own 14 days are over) is not protected from rollback: anything
	 * that verifies replaces it, whatever its `issued_at`.
	 *
	 * @return void
	 */
	public function test_a_stored_claim_that_no_longer_verifies_is_replaced_by_an_older_issued_at(): void {
		$old = $this->paid( [ 'issued_at' => self::NOW - 20 * DAY_IN_SECONDS, 'expires_at' => self::NOW - 6 * DAY_IN_SECONDS ] );
		$this->options[ self::CLAIM_OPTION ] = $this->sign( $old );

		$fresh = $this->paid( [ 'issued_at' => self::NOW - 30 * DAY_IN_SECONDS, 'expires_at' => self::NOW + DAY_IN_SECONDS ] );
		$claims = $this->claims();
		$claims->consume_from_response( [ 'license_authority' => $this->sign( $fresh ) ] );

		$this->assertTrue( $claims->is_license_in_force() );
	}

	/**
	 * A licence-free claim is also protected from being replaced by an older one.
	 *
	 * @return void
	 */
	public function test_an_older_licence_free_claim_does_not_replace_a_newer_paid_one(): void {
		$claims = $this->claims();
		$claims->consume_from_response( [ 'license_authority' => $this->sign( $this->paid( [ 'license_status' => 'expired', 'issued_at' => self::NOW - 10 ] ) ) ] );
		$claims->consume_from_response( [ 'license_authority' => $this->sign( $this->free() ) ] );

		$this->assertFalse( $claims->is_license_in_force(), 'the free claim is a day older than the paid one' );
	}

	/**
	 * @return void
	 */
	public function test_the_licence_expiry_is_reported_for_a_paid_claim_only(): void {
		$claims = $this->claims();
		$this->assertNull( $claims->get_license_expires(), 'no claim' );

		$claims->consume_from_response( [ 'license_authority' => $this->sign( $this->paid( [ 'license_expires' => self::NOW + 5 * DAY_IN_SECONDS ] ) ) ] );
		$this->assertSame( self::NOW + 5 * DAY_IN_SECONDS, $claims->get_license_expires() );

		$this->options = [];
		$free          = $this->claims();
		$free->consume_from_response( [ 'license_authority' => $this->sign( $this->free() ) ] );
		$this->assertNull( $free->get_license_expires(), 'a licence-free product has no expiry to report' );

		$this->options = [];
		$lifetime      = $this->claims();
		$lifetime->consume_from_response( [ 'license_authority' => $this->sign( $this->paid( [ 'license_expires' => null ] ) ) ] );
		$this->assertNull( $lifetime->get_license_expires(), 'a lifetime licence has none either' );
	}

	// ---- the engine's predicate -----------------------------------------------------------------------------

	/**
	 * The engine around a real claim store.
	 *
	 * @param object $plugin the plugin stub.
	 * @return \Woodev_Plugins_License
	 */
	private function engine( $plugin ): \Woodev_Plugins_License {
		$license = ( new \ReflectionClass( \Woodev_Plugins_License::class ) )->newInstanceWithoutConstructor();

		$this->set_private_property( $license, 'plugin', $plugin );
		$this->set_private_property( $license, 'authority_claims', $this->claims() );

		return $license;
	}

	/**
	 * The engine answers from the claim and from nothing else: not the plain status option (which a pirate
	 * writes), not the local `is_need_license()` flag (which a pirate edits).
	 *
	 * @return void
	 */
	public function test_the_engine_reads_neither_the_status_option_nor_the_need_license_flag(): void {
		$plugin = Mockery::mock();
		$plugin->shouldReceive( 'get_download_id' )->andReturn( 9001 );
		$plugin->shouldNotReceive( 'is_need_license' );

		$license = $this->engine( $plugin );

		// The forged state: a plain «valid» status, a key, no signed statement.
		$woodev_license          = ( new \ReflectionClass( \Woodev_License::class ) )->newInstanceWithoutConstructor();
		$woodev_license->license = 'valid';
		$this->set_private_property( $license, 'woodev_license', $woodev_license );
		$this->set_private_property( $license, 'license_key', 'FORGED-KEY' );

		$this->assertTrue( $license->is_license_valid(), 'the forgeable accessor believes the option' );
		$this->assertFalse( $license->is_license_in_force(), 'the gate does not' );
	}

	/**
	 * @return void
	 */
	public function test_the_engine_follows_the_signed_claim(): void {
		$plugin = Mockery::mock();
		$plugin->shouldReceive( 'get_download_id' )->andReturn( 9001 );

		$license = $this->engine( $plugin );
		$this->assertFalse( $license->is_license_in_force() );

		$license->get_authority_claims()->consume_from_response( [ 'license_authority' => $this->sign( $this->paid() ) ] );

		$this->assertTrue( $license->is_license_in_force() );
		$this->assertSame( self::NOW + 10 * DAY_IN_SECONDS, $license->get_license_expires() );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function ids_with_no_store_product(): array {
		return [
			'zero'      => [ 0 ],
			'empty'     => [ '' ],
			'negative'  => [ -3 ],
			'not a number' => [ 'abc' ],
		];
	}

	/**
	 * A plugin with no store product has nothing to license: never gated, with no claim at all.
	 *
	 * @dataProvider ids_with_no_store_product
	 *
	 * @param mixed $download_id the id.
	 * @return void
	 */
	public function test_a_plugin_with_no_store_product_is_never_gated( $download_id ): void {
		$plugin = Mockery::mock();
		$plugin->shouldReceive( 'get_download_id' )->andReturn( $download_id );

		$license = $this->engine( $plugin );

		$this->assertTrue( $license->is_license_in_force() );
		$this->assertNull( $license->get_license_expires() );
	}

	/**
	 * Saving a key leaves a fresh signed claim in place at once: activate() — the only path the React
	 * licence page's save goes through — hands the activation response to the claim store, so a
	 * `site_inactive` or `valid` statement riding it takes effect before the request ends. (The weekly check
	 * and the page's «Проверить» button go through validate_license(), which does the same.)
	 *
	 * @return void
	 */
	public function test_a_signed_claim_riding_an_activation_response_lifts_the_block_at_once(): void {
		$plugin = Mockery::mock();
		$plugin->shouldReceive( 'get_download_id' )->andReturn( 9001 );

		$license = $this->engine( $plugin );

		// Before the key is saved: the stored statement says the licence ran out.
		$license->get_authority_claims()->consume_from_response( [ 'license_authority' => $this->sign( $this->paid( [ 'license_status' => 'expired', 'issued_at' => self::NOW - 1000 ] ) ) ] );
		$this->assertFalse( $license->is_license_in_force() );

		// The activation response of a renewed key carries the newer, valid statement.
		$response = (object) [
			'license'           => 'valid',
			'license_authority' => json_decode( (string) json_encode( $this->sign( $this->paid( [ 'issued_at' => self::NOW - 1 ] ) ) ) ),
		];
		$license->get_authority_claims()->consume_from_response( $response );

		$this->assertTrue( $license->is_license_in_force(), 'an object response (the shape get_response_data() returns) is consumed too' );
	}

	// ---- prompt refresh of a missing claim (round 2) --------------------------------------------------------

	/** @var array<string, mixed> the in-memory transient table */
	private array $transients = [];

	/** @var string[] the single events scheduled so far (hook names) */
	private array $scheduled = [];

	/**
	 * An engine around a stored key, a real claim store and in-memory cron / transients; its
	 * validate_license() only counts the calls.
	 *
	 * @param string $key         the stored licence key ('' = none).
	 * @param int    $download_id the plugin's download id.
	 * @return \Woodev_Plugins_License
	 */
	private function refreshing_engine( string $key = 'KEY-1', int $download_id = 9001 ): \Woodev_Plugins_License {
		$this->transients = [];
		$this->scheduled  = [];

		Functions\when( 'get_transient' )->alias( fn( $name ) => $this->transients[ $name ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value ) {
				$this->transients[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'wp_next_scheduled' )->alias( fn( $hook ) => in_array( $hook, $this->scheduled, true ) ? self::NOW : false );
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( $hook ) {
				$this->scheduled = array_values( array_diff( $this->scheduled, [ $hook ] ) );

				return 1;
			}
		);
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $timestamp, $hook ) {
				$this->scheduled[] = $hook;

				return true;
			}
		);

		$plugin = Mockery::mock( \Woodev_Plugin::class );
		$plugin->shouldReceive( 'get_download_id' )->andReturn( $download_id );
		$plugin->shouldReceive( 'get_id' )->andReturn( 'test_plugin' );
		$plugin->shouldReceive( 'get_plugin_option_name' )->with( 'claim_refresh' )->andReturn( 'woodev_test_plugin_claim_refresh' );
		$plugin->shouldReceive( 'get_plugin_option_name' )->with( 'claim_schedule' )->andReturn( 'woodev_test_plugin_claim_schedule' );

		$license = new class() extends \Woodev_Plugins_License {

			/** @var string[] the keys validate_license() was called with */
			public array $validated = [];

			/** @var callable|null runs inside validate_license() — lets a test land a claim as a real refresh would */
			public $on_validate = null;

			public function __construct() {}

			public function validate_license( $license = '', $deprecated = false, $ajax = false ) {
				$this->validated[] = $license;

				if ( null !== $this->on_validate ) {
					( $this->on_validate )();
				}
			}
		};

		// The properties are private to the parent class, so they are set through IT, not the anonymous subclass.
		foreach ( [
			'plugin'           => $plugin,
			'authority_claims' => $this->claims( $download_id ),
			'license_key'      => $key,
		] as $property => $value ) {
			$reflection_property = new \ReflectionProperty( \Woodev_Plugins_License::class, $property );
			if ( PHP_VERSION_ID < 80100 ) {
				$reflection_property->setAccessible( true );
			}
			$reflection_property->setValue( $license, $value );
		}

		return $license;
	}

	/**
	 * @return void
	 */
	public function test_a_key_with_no_claim_schedules_one_refresh_and_the_second_call_is_throttled(): void {
		$license = $this->refreshing_engine();

		$this->assertTrue( $license->needs_claim_refresh() );
		$this->assertTrue( $license->schedule_claim_refresh() );
		$this->assertSame( [ 'woodev_test_plugin_refresh_license_claim' ], $this->scheduled );

		$this->assertFalse( $license->schedule_claim_refresh(), 'the second call within the window schedules nothing' );
		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( [], $license->validated, 'scheduling does no licence-server call' );
	}

	/**
	 * @return void
	 */
	public function test_the_throttle_window_ends_and_a_pending_event_is_not_doubled(): void {
		$license = $this->refreshing_engine();
		$license->schedule_claim_refresh();

		// The window has passed but the event is still waiting for cron: still a single event.
		$this->transients = [];
		$this->assertFalse( $license->schedule_claim_refresh() );
		$this->assertCount( 1, $this->scheduled );

		// The event ran (and found nothing); the next window may schedule again.
		$this->scheduled  = [];
		$this->transients = [];
		$this->assertTrue( $license->schedule_claim_refresh() );
	}

	/**
	 * @return void
	 */
	public function test_a_claim_that_expired_at_rest_is_refreshed_like_an_absent_one(): void {
		$license = $this->refreshing_engine();
		// Stored, genuinely signed, but its own 14 days are over (the store's clock says NOW).
		$this->options[ self::CLAIM_OPTION ] = $this->sign( $this->paid( [ 'expires_at' => self::NOW - 1 ] ) );

		$this->assertTrue( $license->needs_claim_refresh() );
		$this->assertTrue( $license->schedule_claim_refresh() );
	}

	/**
	 * A verified claim that SAYS expired / invalid is the server's answer, not a missing one: no refresh storm,
	 * the normal weekly path carries on.
	 *
	 * @return void
	 */
	public function test_a_signed_expired_claim_does_not_trigger_a_refresh(): void {
		foreach ( [ 'expired', 'invalid', 'site_inactive', 'disabled' ] as $status ) {
			$license = $this->refreshing_engine();
			$license->get_authority_claims()->consume_from_response( [ 'license_authority' => $this->sign( $this->paid( [ 'license_status' => $status ] ) ) ] );

			$this->assertFalse( $license->is_license_in_force() );
			$this->assertFalse( $license->needs_claim_refresh(), $status );
			$this->assertFalse( $license->schedule_claim_refresh(), $status );
			$this->assertSame( [], $this->scheduled, $status );

			$license->run_claim_refresh();
			$this->assertSame( [], $license->validated, $status );
		}
	}

	/**
	 * @return void
	 */
	public function test_no_key_or_no_store_product_schedules_nothing(): void {
		$keyless = $this->refreshing_engine( '' );
		$this->assertFalse( $keyless->needs_claim_refresh() );
		$this->assertFalse( $keyless->schedule_claim_refresh() );
		$this->assertSame( [], $this->scheduled );

		$unlicensed = $this->refreshing_engine( 'KEY-1', 0 );
		$this->assertFalse( $unlicensed->schedule_claim_refresh() );
		$this->assertSame( [], $this->scheduled );
	}

	/**
	 * @return void
	 */
	public function test_the_scheduled_run_asks_the_server_once_and_only_while_the_claim_is_missing(): void {
		$license = $this->refreshing_engine( 'KEY-1' );
		$license->run_claim_refresh();
		$this->assertSame( [ 'KEY-1' ], $license->validated );

		$license->get_authority_claims()->consume_from_response( [ 'license_authority' => $this->sign( $this->paid() ) ] );
		$license->run_claim_refresh();
		$this->assertSame( [ 'KEY-1' ], $license->validated, 'the claim arrived: nothing more to fetch' );
		$this->assertTrue( $license->is_license_in_force(), 'and the next request is in force' );
	}

	/**
	 * @return void
	 */
	public function test_a_manager_in_admin_refreshes_at_once_under_the_same_throttle(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'manage_woocommerce' === $cap );

		$license = $this->refreshing_engine();
		$license->refresh_claim_on_admin_init();
		$license->refresh_claim_on_admin_init();

		$this->assertSame( [ 'KEY-1' ], $license->validated, 'one attempt per window' );
	}

	/**
	 * The round-3 starvation: with WP-Cron off the scheduled event never runs, and buyer traffic keeps asking
	 * for it. The manager's own synchronous refresh must still happen — once per window — and must clear the
	 * pending event when it lands a claim.
	 *
	 * @return void
	 */
	public function test_buyer_scheduling_never_starves_the_managers_synchronous_refresh(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'manage_woocommerce' === $cap );

		$license = $this->refreshing_engine();
		$hook    = 'woodev_test_plugin_refresh_license_claim';

		// Checkout traffic: one event is queued, then window after window of buyers meet it still pending.
		$this->assertTrue( $license->schedule_claim_refresh() );
		for ( $window = 0; $window < 3; $window++ ) {
			unset( $this->transients['woodev_test_plugin_claim_schedule'] );
			$this->assertFalse( $license->schedule_claim_refresh() );
		}
		$this->assertSame( [ $hook ], $this->scheduled, 'the event stays pending, never doubled' );
		$this->assertSame( [], $license->validated );

		// The claim lands during the manager's synchronous refresh, as validate_license() would store it.
		$license->on_validate = function () use ( $license ) {
			$license->get_authority_claims()->consume_from_response( [ 'license_authority' => $this->sign( $this->paid() ) ] );
		};

		$license->refresh_claim_on_admin_init();
		$this->assertSame( [ 'KEY-1' ], $license->validated, 'the manager refreshes although an event is pending' );
		$this->assertSame( [], $this->scheduled, 'the now-pointless pending event is unscheduled' );
		$this->assertTrue( $license->is_license_in_force() );
	}

	/**
	 * A failed manager refresh leaves the event alone, and the admin throttle holds for the whole window even
	 * while buyers keep scheduling.
	 *
	 * @return void
	 */
	public function test_a_second_admin_init_inside_the_window_makes_no_second_attempt(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'manage_woocommerce' === $cap );

		$license = $this->refreshing_engine();
		$license->schedule_claim_refresh();

		$license->refresh_claim_on_admin_init(); // The server is unreachable: no claim arrives.
		$this->assertSame( [ 'woodev_test_plugin_refresh_license_claim' ], $this->scheduled, 'a failed refresh keeps the event' );

		unset( $this->transients['woodev_test_plugin_claim_schedule'] );
		$license->schedule_claim_refresh();
		$license->refresh_claim_on_admin_init();

		$this->assertSame( [ 'KEY-1' ], $license->validated, 'one admin attempt per window, buyers or not' );
	}

	/**
	 * @return void
	 */
	public function test_admin_init_refreshes_nothing_for_a_non_manager_an_ajax_call_or_a_cron_run(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		$license = $this->refreshing_engine();
		$license->refresh_claim_on_admin_init();
		$this->assertSame( [], $license->validated, 'a non-manager' );

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		$license = $this->refreshing_engine();
		$license->refresh_claim_on_admin_init();
		$this->assertSame( [], $license->validated, 'an AJAX call' );

		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		$license = $this->refreshing_engine();
		$license->refresh_claim_on_admin_init();
		$this->assertSame( [], $license->validated, 'a cron run' );
	}

	/**
	 * @param object $object   object to update.
	 * @param string $property property name.
	 * @param mixed  $value    value.
	 * @return void
	 */
	private function set_private_property( $object, string $property, $value ): void {
		$reflection_property = new \ReflectionProperty( $object, $property );
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection_property->setAccessible( true );
		}
		$reflection_property->setValue( $object, $value );
	}
}
