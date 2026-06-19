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

			return rest_ensure_response( array( 'connected' => false ) );
		}
	}

endif;
