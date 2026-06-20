<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_REST_API_Account' ) ) :

	/**
	 * REST controller for the account-connection actions (`woodev/v1` namespace).
	 *
	 * Exposes `POST /woodev/v1/account/disconnect` — capability-gated (manage_options)
	 * and protected by the core REST cookie nonce (apiFetch sends X-WP-Nonce). The
	 * connect/return handshake is NOT here: it needs full-page redirects and lives on
	 * the extensions admin-page load (Woodev_Account_Connection). Registered on core
	 * rest_api_init through Woodev_REST_V1_Registrar.
	 *
	 * @since 2.0.2
	 */
	final class Woodev_REST_API_Account {

		/**
		 * Idempotency guard.
		 *
		 * @since 2.0.2
		 *
		 * @var bool
		 */
		private static $booted = false;

		/**
		 * Per-site purchases cache key (short TTL — user-scoped data).
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		const PURCHASES_CACHE_KEY = 'woodev_account_purchases';

		/**
		 * Registers a single controller instance through the woodev/v1 registrar.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public static function boot(): void {

			if ( self::$booted ) {
				return;
			}

			self::$booted = true;

			Woodev_REST_V1_Registrar::register_controller( new self() );
		}

		/**
		 * Registers the disconnect route.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function register_routes(): void {

			register_rest_route(
				Woodev_REST_V1_Registrar::ROUTE_NAMESPACE,
				'/account/disconnect',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_disconnect' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				)
			);

			register_rest_route(
				Woodev_REST_V1_Registrar::ROUTE_NAMESPACE,
				'/account/purchases',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'handle_purchases' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				)
			);

			register_rest_route(
				Woodev_REST_V1_Registrar::ROUTE_NAMESPACE,
				'/account/install',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_install' ),
					'permission_callback' => array( $this, 'check_install_permissions' ),
					'args'                => array(
						'download_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'validate_callback' => static function ( $value ) {
								return is_numeric( $value ) && (int) $value > 0;
							},
						),
					),
				)
			);
		}

		/**
		 * Capability gate — matches the «Плагины» admin page (manage_options).
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return true|WP_Error
		 */
		public function check_permissions() {

			if ( current_user_can( 'manage_options' ) ) {
				return true;
			}

			return new WP_Error(
				'woodev_account_forbidden',
				esc_html__( 'Недостаточно прав для управления подключением аккаунта.', 'woodev-plugin-framework' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		/**
		 * Capability gate for installing plugins — `install_plugins` (stricter than
		 * the page's `manage_options`; matches WordPress's own plugin-install cap).
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return true|WP_Error
		 */
		public function check_install_permissions() {

			if ( current_user_can( 'install_plugins' ) ) {
				return true;
			}

			return new WP_Error(
				'woodev_account_forbidden',
				esc_html__( 'Недостаточно прав для установки плагинов.', 'woodev-plugin-framework' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		/**
		 * POST handler: installs an owned plugin from the connected account.
		 *
		 * Returns `{ installed: true }` on success, or the install WP_Error (which
		 * carries its own HTTP status) on failure. The plugin is installed inactive.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param WP_REST_Request $request The request.
		 *
		 * @return WP_REST_Response|WP_Error|array<string,bool>
		 */
		public function handle_install( $request ) {

			$download_id = (int) $request->get_param( 'download_id' );

			$result = ( new Woodev_Account_Installer() )->install( $download_id );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return rest_ensure_response( array( 'installed' => true ) );
		}

		/**
		 * POST handler: disconnects and returns the new (disconnected) state.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return WP_REST_Response|array<string,bool>
		 */
		public function handle_disconnect() {

			( new Woodev_Account_Connection() )->disconnect();

			delete_transient( self::PURCHASES_CACHE_KEY );

			return rest_ensure_response( array( 'connected' => false ) );
		}

		/**
		 * GET handler: the connected customer's purchases + the badge-id set.
		 *
		 * Returns { purchases, purchased } — the lean list for the «Мои покупки»
		 * tab and the deduped int id list for the «Куплено» catalog badge — in one
		 * payload (one network round-trip, fetched async by the React app). Served
		 * from a short-lived transient when present. A disconnected site returns the
		 * empty shape without any network call; a transport/HTTP failure or a
		 * malformed reply (no `purchases` key) sets `stale: true` and is NOT cached,
		 * so the next load retries.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return WP_REST_Response|array<string,mixed>
		 */
		public function handle_purchases() {

			$connection = new Woodev_Account_Connection();

			if ( ! $connection->is_connected() ) {
				return rest_ensure_response(
					array(
						'purchases' => array(),
						'purchased' => array(),
					)
				);
			}

			$cached = get_transient( self::PURCHASES_CACHE_KEY );

			if ( is_array( $cached ) ) {
				return rest_ensure_response( $cached );
			}

			$response = $connection->request( 'GET', '/purchases' );

			// A genuine connector reply always carries an ARRAY `purchases`. A
			// WP_Error (transport/HTTP failure) or a present-but-non-array value
			// (e.g. "purchases": null from a buggy/hostile issuer) is a bad reply:
			// surface it as stale and do NOT cache it, so the next load retries
			// rather than serving — and caching — a fake empty success for the TTL.
			if ( is_wp_error( $response ) || ! isset( $response['purchases'] ) || ! is_array( $response['purchases'] ) ) {
				return rest_ensure_response(
					array(
						'purchases' => array(),
						'purchased' => array(),
						'stale'     => true,
					)
				);
			}

			$purchases = Woodev_Account_Purchases::normalize( $response );
			$payload   = array(
				'purchases' => $purchases,
				'purchased' => Woodev_Account_Purchases::download_ids( $purchases ),
			);

			set_transient( self::PURCHASES_CACHE_KEY, $payload, 5 * MINUTE_IN_SECONDS );

			return rest_ensure_response( $payload );
		}
	}

endif;
