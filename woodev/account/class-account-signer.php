<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Account_Signer' ) ) :

	/**
	 * Canonical HMAC-SHA256 request signer — the framework-side mirror of the
	 * woodev-account-connector's Signer.
	 *
	 * PURE: depends only on its inputs and wp_json_encode. The canonical payload
	 * key order — host, request_uri, method (upper-cased), body, timestamp — is the
	 * contract the woodev.ru connector verifies against, reproduced byte-for-byte
	 * regardless of input order. The timestamp is signed (not a side header) so a
	 * captured request cannot be replayed with a refreshed timestamp.
	 *
	 * @since 2.0.2
	 */
	final class Woodev_Account_Signer {

		/**
		 * Computes the canonical signature for a request with the given key.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $request Parts: host, request_uri, method, body, timestamp.
		 * @param string              $key     The signing secret.
		 *
		 * @return string Hex HMAC-SHA256 signature.
		 */
		public static function sign( array $request, string $key ): string {

			$payload = wp_json_encode(
				array(
					'host'        => (string) ( $request['host'] ?? '' ),
					'request_uri' => (string) ( $request['request_uri'] ?? '' ),
					'method'      => strtoupper( (string) ( $request['method'] ?? '' ) ),
					'body'        => (string) ( $request['body'] ?? '' ),
					'timestamp'   => (string) ( $request['timestamp'] ?? '' ),
				)
			);

			return hash_hmac( 'sha256', (string) $payload, $key );
		}
	}

endif;
