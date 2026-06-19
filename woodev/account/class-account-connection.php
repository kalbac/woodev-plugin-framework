<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Account_Connection' ) ) :

	/**
	 * woodev.ru account-connection client.
	 *
	 * Owns the OAuth-style handshake with the woodev-account-connector plugin on the
	 * store, the signed transport for resource requests, and the locally stored
	 * connection state. Legacy-prefixed (mirrors the licensing classes). Stateless:
	 * all state lives in the `woodev_account_data` option and the short-lived
	 * `woodev_account_handshake` transient, so the object is cheap to `new`.
	 *
	 * @since 2.0.2
	 */
	final class Woodev_Account_Connection {

		/** Connector REST namespace. @var string */
		const REST_NAMESPACE = 'woodev-account/v1';

		/** Stored-state option key (installed-site data — design-new, see spec). @var string */
		const OPTION_KEY = 'woodev_account_data';

		/** In-flight handshake transient key. @var string */
		const HANDSHAKE_KEY = 'woodev_account_handshake';

		/** Admin page slug hosting the connect/return handlers. @var string */
		const PAGE_SLUG = 'woodev-extensions';

		/**
		 * The store base URL, overridable for the local rig.
		 *
		 * @since 2.0.2
		 *
		 * @return string Trailing-slash-trimmed base, e.g. https://woodev.ru.
		 */
		private function api_base(): string {

			/**
			 * Filters the woodev.ru base URL the account client talks to.
			 *
			 * Mirrors `woodev_license_base_url`: repoint at the issuer (:8090) for e2e.
			 *
			 * @since 2.0.2
			 *
			 * @param string $base The account API base URL.
			 */
			return untrailingslashit( apply_filters( 'woodev_account_api_url', 'https://woodev.ru' ) );
		}

		/**
		 * Builds the full REST endpoint URL for a connector path.
		 *
		 * Pretty-permalink form (`/wp-json/{ns}{path}`). The store (woodev.ru) and the
		 * rig issuer both serve pretty permalinks; `canonical_for()` still derives the
		 * signed request_uri from whatever URL it is given, so a plain-permalink store
		 * only needs this method changed, not the signing.
		 *
		 * @since 2.0.2
		 *
		 * @param string $path Leading-slash connector path, e.g. '/oauth/me'.
		 *
		 * @return string
		 */
		private function endpoint( string $path ): string {
			return $this->api_base() . '/wp-json/' . self::REST_NAMESPACE . $path;
		}

		/**
		 * Derives the canonical signed-request fields for an OUTGOING request exactly
		 * as the connector's server will reconstruct them from its superglobals.
		 *
		 * host = URL host (+ :port when explicit), request_uri = URL path (+ ?query),
		 * method upper-cased, body the exact bytes sent, timestamp the header value.
		 *
		 * @since 2.0.2
		 *
		 * @param string $url       The full endpoint URL.
		 * @param string $method    HTTP method.
		 * @param string $body      The exact request body bytes ('' for none).
		 * @param string $timestamp The X-Woodev-Timestamp value.
		 *
		 * @return array<string,string>
		 */
		private function canonical_for( string $url, string $method, string $body, string $timestamp ): array {

			$parts = wp_parse_url( $url );

			$host = isset( $parts['host'] ) ? (string) $parts['host'] : '';
			if ( isset( $parts['port'] ) ) {
				$host .= ':' . (int) $parts['port'];
			}

			$request_uri = isset( $parts['path'] ) ? (string) $parts['path'] : '';
			if ( isset( $parts['query'] ) && '' !== (string) $parts['query'] ) {
				$request_uri .= '?' . (string) $parts['query'];
			}

			return array(
				'host'        => $host,
				'request_uri' => $request_uri,
				'method'      => strtoupper( $method ),
				'body'        => $body,
				'timestamp'   => $timestamp,
			);
		}

		/**
		 * Whether a connection token is stored.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function is_connected(): bool {
			$auth = $this->get_auth();
			return '' !== (string) ( $auth['access_token'] ?? '' );
		}

		/**
		 * The account state for the UI / bootstrap.
		 *
		 * @since 2.0.2
		 *
		 * @return array{connected:bool,name:string,email:string,avatar:string,url:string}
		 */
		public function get_account(): array {

			$auth = $this->get_auth();
			$user = $this->get_user_data();

			return array(
				'connected' => '' !== (string) ( $auth['access_token'] ?? '' ),
				'name'      => (string) ( $user['name'] ?? '' ),
				'email'     => (string) ( $user['email'] ?? '' ),
				'avatar'    => (string) ( $user['avatar'] ?? '' ),
				'url'       => (string) ( $auth['url'] ?? $this->api_base() ),
			);
		}

		/**
		 * The nonce'd connect-init admin URL the React app links to.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_connect_url(): string {

			$url = add_query_arg(
				array(
					'page'                   => self::PAGE_SLUG,
					'woodev-account-connect' => '1',
				),
				admin_url( 'admin.php' )
			);

			return wp_nonce_url( $url, 'woodev_account_connect' );
		}

		// ---- Stored state ----------------------------------------------------

		/**
		 * Reads the stored auth sub-array.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string,mixed>
		 */
		private function get_auth(): array {
			$data = get_option( self::OPTION_KEY );
			return ( is_array( $data ) && isset( $data['auth'] ) && is_array( $data['auth'] ) ) ? $data['auth'] : array();
		}

		/**
		 * Reads the stored user-data sub-array.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string,mixed>
		 */
		private function get_user_data(): array {
			$data = get_option( self::OPTION_KEY );
			return ( is_array( $data ) && isset( $data['auth_user_data'] ) && is_array( $data['auth_user_data'] ) ) ? $data['auth_user_data'] : array();
		}

		/**
		 * Persists the auth + user-data state.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $auth      Token bundle.
		 * @param array<string,mixed> $user_data { name, email, avatar }.
		 *
		 * @return void
		 */
		private function store_auth( array $auth, array $user_data ): void {
			update_option(
				self::OPTION_KEY,
				array(
					'auth'           => $auth,
					'auth_user_data' => $user_data,
				),
				false
			);
		}

		/**
		 * Clears all stored connection state.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		private function clear(): void {
			delete_option( self::OPTION_KEY );
		}

		// ---- Transport + handlers added in Tasks 4-5 -------------------------
	}

endif;
