<?php
/**
 * Generic Ed25519 signed-envelope verifier.
 *
 * The single cryptographic primitive consumed by both the §4 license-free claim
 * store and the §3.4.1 signed command dispatcher. It does ONE thing: given an
 * envelope { payload, signature, kid? }, confirm the detached Ed25519 signature
 * over the canonical JSON of payload against an embedded public key, honoring the
 * optional key-id (kid) rule. It carries NO site/plugin/time/nonce semantics —
 * those belong to the callers, which interpret the returned payload array.
 *
 * @package Woodev\Framework\Licensing
 * @since 2.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_License_Envelope_Verifier' ) ) :

	/**
	 * Verifies Ed25519-signed envelopes and canonicalizes payloads.
	 *
	 * @since 2.0.0
	 */
	final class Woodev_License_Envelope_Verifier {

		/**
		 * The Woodev authority PUBLIC key (base64, Ed25519/32 bytes) embedded in the framework.
		 *
		 * The PRODUCTION key captured from woodev.ru on 2026-06-12 (woodev-core License_Authority,
		 * option woodev_license_authority_keys; capture procedure in the woodev-core signing spec).
		 * A class constant, not a global one, so nothing defined earlier can replace it. Rotating
		 * the server key invalidates every issued claim and command and REQUIRES updating this
		 * value in lockstep. An empty or undecodable value makes every verification fail — the
		 * safe default.
		 *
		 * @since 2.0.26
		 *
		 * @var string
		 */
		const PRODUCTION_PUBKEY = '6N6HaUIrqZMuyDTYjvazMoQjpHwdeyLbmz5Zu3Fh2rM=';

		/**
		 * Name of the constant that enables the key override in test environments.
		 *
		 * @since 2.0.26
		 *
		 * @var string
		 */
		const TEST_KEYS_CONSTANT = 'WOODEV_LICENSE_TEST_KEYS';

		/**
		 * Ed25519 detached-signature length in bytes (protocol-fixed).
		 *
		 * Equals SODIUM_CRYPTO_SIGN_BYTES; declared here as a literal so the length
		 * check never references an ext-sodium constant that is undefined when the
		 * extension is absent (the function_exists() guard then returns null safely).
		 *
		 * @since 2.0.0
		 *
		 * @var int
		 */
		const SIGNATURE_BYTES = 64;

		/**
		 * Ed25519 public-key length in bytes (protocol-fixed).
		 *
		 * Equals SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES; see SIGNATURE_BYTES.
		 *
		 * @since 2.0.0
		 *
		 * @var int
		 */
		const PUBLIC_KEY_BYTES = 32;

		/**
		 * Canonicalizes a payload to the exact bytes that were signed.
		 *
		 * Recursively sorts keys with SORT_STRING (stable across PHP versions and
		 * locales) then JSON-encodes with unescaped slashes and unicode so the byte
		 * stream matches the server's. This is the cross-repo contract — the
		 * published test vector pins the output byte-for-byte.
		 *
		 * @since 2.0.0
		 *
		 * @param array<string, mixed> $payload Payload to canonicalize.
		 * @return string Canonical JSON.
		 */
		public static function canonical_json( array $payload ): string {

			self::recursive_ksort( $payload );

			return (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		/**
		 * Recursively sorts array keys in place with SORT_STRING.
		 *
		 * @since 2.0.0
		 *
		 * @param array<string, mixed> $array Array to sort, by reference.
		 * @return void
		 */
		private static function recursive_ksort( array &$array ): void {

			ksort( $array, SORT_STRING );

			foreach ( $array as &$value ) {
				if ( is_array( $value ) ) {
					self::recursive_ksort( $value );
				}
			}
		}

		/**
		 * Verifies an envelope and returns its payload on success.
		 *
		 * Performs, in order: schema sanity (payload array + signature string);
		 * strict base64 of the signature with a 64-byte length check; strict base64
		 * of the public key with a 32-byte length check; the optional kid rule
		 * (envelope kid absent, or equal to the first-16-hex of sha256 over the raw
		 * key); a sodium-availability guard; and finally the detached Ed25519 verify
		 * over canonical_json( payload ). Any failure — including a missing sodium
		 * extension or empty/placeholder key — returns null and never raises an error.
		 *
		 * @since 2.0.0
		 *
		 * @param array<string, mixed> $envelope       Envelope { payload, signature, kid? }.
		 * @param string|null          $public_key_b64 Base64 public key; defaults to the embedded production key.
		 * @return array<string, mixed>|null The verified payload, or null on any failure.
		 */
		public static function verify( array $envelope, ?string $public_key_b64 = null ): ?array {

			$payload   = $envelope['payload'] ?? null;
			$signature = $envelope['signature'] ?? null;

			if ( ! is_array( $payload ) || ! is_string( $signature ) ) {
				return null;
			}

			$raw_signature = base64_decode( $signature, true );

			if ( false === $raw_signature || self::SIGNATURE_BYTES !== strlen( $raw_signature ) ) {
				return null;
			}

			$public_key_b64 = $public_key_b64 ?? self::PRODUCTION_PUBKEY;

			if ( '' === $public_key_b64 ) {
				return null;
			}

			$raw_public_key = base64_decode( $public_key_b64, true );

			if ( false === $raw_public_key || self::PUBLIC_KEY_BYTES !== strlen( $raw_public_key ) ) {
				return null;
			}

			// kid sits OUTSIDE the signed payload, so its mere PRESENCE must be binding:
			// absent is fine (single embedded key), but a present kid of ANY non-matching
			// shape or value — including null — is rejected. Using `?? null` here would
			// let an attacker erase a mismatching kid by setting it to null.
			if ( array_key_exists( 'kid', $envelope ) ) {
				$kid          = $envelope['kid'];
				$expected_kid = substr( hash( 'sha256', $raw_public_key ), 0, 16 );

				if ( ! is_string( $kid ) || ! hash_equals( $expected_kid, $kid ) ) {
					return null;
				}
			}

			if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
				return null;
			}

			$verified = sodium_crypto_sign_verify_detached( $raw_signature, self::canonical_json( $payload ), $raw_public_key );

			return true === $verified ? $payload : null;
		}

		/**
		 * Resolves the base64 public key used to verify licence envelopes.
		 *
		 * Returns the embedded production key (self::PRODUCTION_PUBKEY). The woodev_license_authority_pubkey
		 * filter is applied only when the WOODEV_LICENSE_TEST_KEYS constant is truthy,
		 * so the key can be overridden in test environments and nowhere else. An
		 * empty/undecodable key makes every verification fail — the safe default.
		 *
		 * @since 2.0.26
		 *
		 * @param bool|null $allow_override Whether the filter is honoured; null reads WOODEV_LICENSE_TEST_KEYS (a test seam).
		 * @return string The base64 public key.
		 */
		public static function resolve_public_key( ?bool $allow_override = null ): string {

			$allow_override = $allow_override ?? ( defined( self::TEST_KEYS_CONSTANT ) && (bool) constant( self::TEST_KEYS_CONSTANT ) );

			return $allow_override ? (string) apply_filters( 'woodev_license_authority_pubkey', self::PRODUCTION_PUBKEY ) : self::PRODUCTION_PUBKEY;
		}
	}

endif;

// Legacy alias of the embedded key for code that still reads the old global; the framework
// itself no longer reads it, so defining it earlier does not change the authority key.
defined( 'WOODEV_LICENSE_AUTHORITY_PUBKEY' ) || define( 'WOODEV_LICENSE_AUTHORITY_PUBKEY', Woodev_License_Envelope_Verifier::PRODUCTION_PUBKEY );
