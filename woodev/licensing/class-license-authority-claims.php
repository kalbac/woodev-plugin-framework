<?php
/**
 * §4 signed-claim store.
 *
 * One of the two consumers of the shared Ed25519 envelope verifier (the other is
 * the §3.4.1 command dispatcher). It owns the SEMANTICS the verifier deliberately
 * does not: site binding, plugin binding, and the 14-day outage-grace expiry. It is
 * the SOLE source of a non-default answer to Woodev_Plugins_License::is_license_required()
 * — and the safe default never flips: ANY doubt → license required (return null
 * from get_verified()).
 *
 * Invariants (release-blocking, anti-pirate):
 *   - never store an unverified envelope;
 *   - never delete a previously-stored valid claim on a failed refresh
 *     (absent/invalid `license_authority` is a no-op = last-known-good);
 *   - re-verify the stored claim AT REST on every read (tamper-at-rest → null);
 *   - a consumption Throwable must never escape (it must not break validate_license()
 *     or the update flow).
 *
 * The claim is persisted under woodev_{id_underscored}_license_required (autoload
 * false), value = the raw verified envelope array — never a bare boolean.
 *
 * @package Woodev\Framework\Licensing
 * @since 2.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_License_Authority_Claims' ) ) :

	/**
	 * Consumes, verifies, stores and reads back the §4 signed license-free claim.
	 *
	 * Not final: Woodev_Plugins_License resolves its store through the typed accessor
	 * get_authority_claims() and is unit-tested against a substitute store (the seam
	 * tests inject a double to exercise the enforcement branches in isolation), which
	 * requires this class to be sub-classable — the same rationale as the non-final
	 * Woodev_Plugins_License it backs.
	 *
	 * @since 2.0.0
	 */
	class Woodev_License_Authority_Claims {

		/**
		 * The owning plugin (supplies the option name + download id binding).
		 *
		 * @since 2.0.0
		 *
		 * @var Woodev_Plugin
		 */
		private $plugin;

		/**
		 * Per-request memo of the verified payload.
		 *
		 * Sentinel false = "not yet resolved this request"; null = "resolved to no
		 * valid claim"; array = "resolved to a verified payload". The sentinel keeps a
		 * resolved null from triggering a second option read + sodium verify.
		 *
		 * @since 2.0.0
		 *
		 * @var array<string, mixed>|null|false
		 */
		private $memoized = false;

		/**
		 * Constructor.
		 *
		 * @since 2.0.0
		 *
		 * @param Woodev_Plugin $plugin The owning plugin.
		 */
		public function __construct( Woodev_Plugin $plugin ) {
			$this->plugin = $plugin;
		}

		/**
		 * Extracts, verifies and (only if fully valid) stores a claim from a response.
		 *
		 * Reads the top-level `license_authority` field (object or array). An ABSENT
		 * key is a no-op — the previously stored claim is KEPT (last-known-good within
		 * the outage window). A present-but-failing envelope is also a no-op: an
		 * unverified envelope is NEVER stored and a previously valid claim is NEVER
		 * deleted. Only a fully verified, site-bound, plugin-bound, unexpired claim is
		 * persisted (raw envelope array, autoload false). Any Throwable is swallowed.
		 *
		 * @since 2.0.0
		 *
		 * @param mixed $response_data The parsed API response (object or array).
		 * @return void
		 */
		public function consume_from_response( $response_data ): void {

			try {

				$envelope = is_object( $response_data )
					? ( $response_data->license_authority ?? null )
					: ( is_array( $response_data ) ? ( $response_data['license_authority'] ?? null ) : null );

				// Normalize a JSON object to the array shape the verifier expects.
				if ( is_object( $envelope ) ) {
					$envelope = json_decode( (string) wp_json_encode( $envelope ), true );
				}

				if ( ! is_array( $envelope ) ) {
					// Absent / unusable → keep last-known-good (safe).
					return;
				}

				if ( null === $this->verify_claim( $envelope ) ) {
					// Present but invalid → keep last-known-good, never store (safe).
					return;
				}

				update_option( $this->plugin->get_plugin_option_name( 'license_required' ), $envelope, false );

				// A fresh write supersedes any memoized read.
				$this->memoized = false;

			} catch ( \Throwable $throwable ) {
				// A consumption failure must never break validate_license()/the update
				// flow: swallow and keep last-known-good (safe default = license required).
				return;
			}
		}

		/**
		 * Returns the verified payload of the stored claim, or null on any doubt.
		 *
		 * Reads the per-plugin option (array guard), re-verifies it AT REST (so a
		 * tampered-at-rest option yields null), and memoizes the result for the rest
		 * of the request so a second call performs zero option reads / sodium verifies.
		 *
		 * @since 2.0.0
		 *
		 * @return array<string, mixed>|null The verified payload, or null.
		 */
		public function get_verified(): ?array {

			if ( false !== $this->memoized ) {
				return $this->memoized;
			}

			try {

				$stored = get_option( $this->plugin->get_plugin_option_name( 'license_required' ) );

				$this->memoized = is_array( $stored ) ? $this->verify_claim( $stored ) : null;

			} catch ( \Throwable $throwable ) {
				// Any doubt → no valid claim (safe default = license required).
				$this->memoized = null;
			}

			return $this->memoized;
		}

		/**
		 * Verifies an envelope's signature THEN its §4 binding semantics.
		 *
		 * Order: Ed25519 signature (via the shared verifier) → site binding (normalized
		 * payload.site is non-null AND equals the normalized home_url()) → plugin
		 * binding (payload.plugin_id === this plugin's download id, string-compared) →
		 * expiry (expires_at is an int AND now <= expires_at, inclusive boundary) →
		 * license_required is a STRICT bool. The last gate is enforcement-critical: a
		 * signed payload carrying 0 / '' / null / [] would otherwise be cast to false
		 * downstream (= unlock); absent or non-bool rejects the WHOLE claim — it is
		 * never stored and enforcement stays locked. Any failure → null.
		 *
		 * @since 2.0.0
		 *
		 * @param array<string, mixed> $envelope The envelope { payload, signature, kid? }.
		 * @return array<string, mixed>|null The verified payload, or null.
		 */
		private function verify_claim( array $envelope ): ?array {

			$payload = Woodev_License_Envelope_Verifier::verify( $envelope, $this->get_public_key() );

			if ( null === $payload ) {
				return null;
			}

			$site = woodev_normalize_site( (string) ( $payload['site'] ?? '' ) );

			if ( null === $site || $site !== woodev_normalize_site( home_url() ) ) {
				return null;
			}

			if ( (string) ( $payload['plugin_id'] ?? '' ) !== (string) $this->plugin->get_download_id() ) {
				return null;
			}

			$expires_at = $payload['expires_at'] ?? null;

			if ( ! is_int( $expires_at ) || time() > $expires_at ) {
				return null;
			}

			// Strict type gate: only a genuine boolean may drive the enforcement seam.
			if ( ! array_key_exists( 'license_required', $payload ) || ! is_bool( $payload['license_required'] ) ) {
				return null;
			}

			return $payload;
		}

		/**
		 * Resolves the base64 public key used to verify claims.
		 *
		 * Defaults to the embedded WOODEV_LICENSE_AUTHORITY_PUBKEY constant (the
		 * production key), overridable through the woodev_license_authority_pubkey
		 * filter so tests can inject the fixture key. An empty/undecodable key makes
		 * every verification fail — the safe default (license required).
		 *
		 * @since 2.0.0
		 *
		 * @return string The base64 public key.
		 */
		private function get_public_key(): string {

			$default = defined( 'WOODEV_LICENSE_AUTHORITY_PUBKEY' ) ? (string) WOODEV_LICENSE_AUTHORITY_PUBKEY : '';

			return (string) apply_filters( 'woodev_license_authority_pubkey', $default );
		}
	}

endif;
