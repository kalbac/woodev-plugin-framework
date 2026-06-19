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

		// ---- Transport -------------------------------------------------------

		/**
		 * Performs a signed request against a connector resource path and returns the
		 * decoded JSON array, or a WP_Error.
		 *
		 * Resource paths get the `Authorization: Bearer` header; all signed requests
		 * get the canonical X-Woodev-Signature + X-Woodev-Timestamp. The signed body is
		 * the exact bytes sent (empty for a no-body request), so the connector's
		 * get_body() matches.
		 *
		 * @since 2.0.2
		 *
		 * @param string              $method HTTP method.
		 * @param string              $path   Connector path, e.g. '/oauth/me'.
		 * @param array<string,mixed> $body   JSON body (omitted when empty).
		 *
		 * @return array<string,mixed>|WP_Error
		 */
		public function request( string $method, string $path, array $body = array() ) {

			$auth = $this->get_auth();
			$key  = (string) ( $auth['access_token_secret'] ?? '' );

			if ( '' === $key || '' === (string) ( $auth['access_token'] ?? '' ) ) {
				return new WP_Error( 'woodev_account_not_connected', __( 'Аккаунт не подключён.', 'woodev-plugin-framework' ) );
			}

			$url       = $this->endpoint( $path );
			$method    = strtoupper( $method );
			$json_body = array() === $body ? '' : (string) wp_json_encode( $body );
			$timestamp = (string) time();

			$signature = Woodev_Account_Signer::sign(
				$this->canonical_for( $url, $method, $json_body, $timestamp ),
				$key
			);

			$args = array(
				'method'  => $method,
				'timeout' => 15,
				'headers' => array(
					'Authorization'      => 'Bearer ' . (string) $auth['access_token'],
					'X-Woodev-Signature' => $signature,
					'X-Woodev-Timestamp' => $timestamp,
				),
			);

			if ( '' !== $json_body ) {
				$args['headers']['Content-Type'] = 'application/json';
				$args['body']                    = $json_body;
			}

			$response = wp_safe_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return new WP_Error(
					'woodev_account_http_error',
					__( 'Сервер woodev.ru вернул ошибку.', 'woodev-plugin-framework' )
				);
			}

			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

			return is_array( $decoded ) ? $decoded : array();
		}

		/**
		 * Disconnects: best-effort signed invalidate, then always clears local state.
		 *
		 * A revoked or unreachable connector must never strand the admin in a
		 * permanently-"connected" UI, so the local option is deleted even when the
		 * remote call errors.
		 *
		 * @since 2.0.2
		 *
		 * @return bool Always true (local clear cannot fail meaningfully).
		 */
		public function disconnect(): bool {

			if ( $this->is_connected() ) {
				// Best-effort; return value intentionally ignored.
				$this->request( 'POST', '/oauth/invalidate_token' );
			}

			$this->clear();

			return true;
		}

		// ---- Page-load handlers ----------------------------------------------

		/**
		 * Connect-init page handler: opens a handshake and redirects to the issuer's
		 * authorize screen. Hooked on the extensions page load when
		 * `?woodev-account-connect=1` is present.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void Redirects + exits, or returns on failure (caller renders the page).
		 */
		public function handle_connect_init(): void {

			if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'woodev_account_connect' ) ) {
				return;
			}

			$home_url     = home_url();
			$return_nonce = wp_create_nonce( 'woodev_account_return' );
			$redirect_uri = add_query_arg(
				array(
					'page'                  => self::PAGE_SLUG,
					'woodev-account-return' => '1',
					'_wpnonce'              => $return_nonce,
				),
				admin_url( 'admin.php' )
			);

			$response = wp_safe_remote_post(
				$this->endpoint( '/oauth/request_token' ),
				array(
					'timeout' => 15,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode(
						array(
							'home_url'     => $home_url,
							'redirect_uri' => $redirect_uri,
						)
					),
				)
			);

			$secret = '';
			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
				$secret  = is_array( $decoded ) ? (string) ( $decoded['secret'] ?? '' ) : '';
			}

			if ( '' === $secret ) {
				$this->fail_redirect( __( 'Не удалось начать подключение. Попробуйте позже.', 'woodev-plugin-framework' ) );
				return;
			}

			// Store the secret + the EXACT redirect_uri string registered with the
			// connector (the authorize screen tamper-checks it byte-for-byte).
			set_transient(
				self::HANDSHAKE_KEY,
				array(
					'secret'       => $secret,
					'redirect_uri' => $redirect_uri,
					'home_url'     => $home_url,
				),
				15 * MINUTE_IN_SECONDS
			);

			// Cross-origin to the issuer — wp_redirect (NOT wp_safe_redirect). The host
			// is the configured/filtered api_base origin only.
			wp_redirect(
				add_query_arg(
					array(
						'home_url'     => rawurlencode( $home_url ),
						'redirect_uri' => rawurlencode( $redirect_uri ),
						'secret'       => $secret,
					),
					$this->endpoint( '/oauth/authorize' )
				)
			);
			exit;
		}

		/**
		 * Connect-return page handler: verifies the nonce, exchanges the request_token,
		 * fetches the profile, stores state, and redirects to the clean page. Hooked on
		 * the extensions page load when `?woodev-account-return=1` is present.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void Redirects + exits, or returns on failure.
		 */
		public function handle_connect_return(): void {

			if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'woodev_account_return' ) ) {
				return;
			}

			$handshake = get_transient( self::HANDSHAKE_KEY );
			delete_transient( self::HANDSHAKE_KEY ); // single-use, always.

			if ( isset( $_GET['woodev_account_denied'] ) ) {
				$this->fail_redirect( __( 'Подключение отклонено.', 'woodev-plugin-framework' ) );
				return;
			}

			$request_token = isset( $_GET['request_token'] ) ? sanitize_text_field( wp_unslash( $_GET['request_token'] ) ) : '';

			if ( ! is_array( $handshake ) || '' === (string) ( $handshake['secret'] ?? '' ) || '' === $request_token ) {
				$this->fail_redirect( __( 'Сессия подключения истекла. Попробуйте снова.', 'woodev-plugin-framework' ) );
				return;
			}

			if ( ! $this->exchange_token( (string) $handshake['secret'], $request_token ) ) {
				$this->fail_redirect( __( 'Не удалось завершить подключение.', 'woodev-plugin-framework' ) );
				return;
			}

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'                     => self::PAGE_SLUG,
						'woodev-account-connected' => '1',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		/**
		 * Exchanges an approved request_token for tokens (signed with the handshake
		 * secret), then fetches and stores the profile.
		 *
		 * @since 2.0.2
		 *
		 * @param string $secret        The handshake secret.
		 * @param string $request_token The approval code.
		 *
		 * @return bool True on a stored connection.
		 */
		private function exchange_token( string $secret, string $request_token ): bool {

			$url       = $this->endpoint( '/oauth/access_token' );
			$body      = array(
				'request_token' => $request_token,
				'home_url'      => home_url(),
			);
			$json_body = (string) wp_json_encode( $body );
			$timestamp = (string) time();
			$signature = Woodev_Account_Signer::sign(
				$this->canonical_for( $url, 'POST', $json_body, $timestamp ),
				$secret
			);

			$response = wp_safe_remote_post(
				$url,
				array(
					'timeout' => 15,
					'headers' => array(
						'Content-Type'       => 'application/json',
						'X-Woodev-Signature' => $signature,
						'X-Woodev-Timestamp' => $timestamp,
					),
					'body'    => $json_body,
				)
			);

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return false;
			}

			$tokens = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( ! is_array( $tokens ) || '' === (string) ( $tokens['access_token'] ?? '' ) ) {
				return false;
			}

			// Store tokens FIRST so the immediate signed /oauth/me uses them.
			$this->store_auth(
				array(
					'access_token'        => (string) $tokens['access_token'],
					'access_token_secret' => (string) ( $tokens['access_token_secret'] ?? '' ),
					'site_id'             => (string) ( $tokens['site_id'] ?? '' ),
					'url'                 => $this->api_base(),
					'user_id'             => get_current_user_id(),
					'updated'             => time(),
				),
				array()
			);

			$me = $this->request( 'GET', '/oauth/me' );

			if ( ! is_wp_error( $me ) ) {
				$auth = $this->get_auth();
				$this->store_auth(
					$auth,
					array(
						'name'   => (string) ( $me['name'] ?? '' ),
						'email'  => (string) ( $me['email'] ?? '' ),
						'avatar' => (string) ( $me['avatar'] ?? '' ),
					)
				);
			}

			return true;
		}

		/**
		 * Stores a flash error and redirects to the clean extensions page.
		 *
		 * @since 2.0.2
		 *
		 * @param string $message Already-translated error text.
		 *
		 * @return void Redirects + exits.
		 */
		private function fail_redirect( string $message ): void {

			set_transient( 'woodev_account_notice', $message, 60 );

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'                  => self::PAGE_SLUG,
						'woodev-account-failed' => '1',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
	}

endif;
