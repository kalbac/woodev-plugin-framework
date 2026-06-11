<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_REST_API_License' ) ) :

	/**
	 * REST controller for the license-page React app (`woodev/v1` namespace).
	 *
	 * A thin transport over the transport-agnostic pure operations on
	 * Woodev_Plugins_License (activate()/deactivate()/set_beta_enabled()/get_state()):
	 * it resolves the right license engine from the static instance registry by the
	 * `{plugin_id}` route param (the EDD download id), calls the matching pure op, and
	 * maps any thrown Throwable to an explicit WP_Error — there is no silent failure.
	 *
	 * Registered on core rest_api_init through Woodev_REST_V1_Registrar (NOT through
	 * the WooCommerce-gated Woodev_REST_API), so the licensing UI/REST layer is
	 * WooCommerce-agnostic. The wp_rest nonce is verified by core via the X-WP-Nonce
	 * header; this controller only adds the manage_options capability check.
	 *
	 * @since 2.0.0
	 */
	final class Woodev_REST_API_License {

		/**
		 * Whether boot() has already registered the controller (idempotency guard).
		 *
		 * @since 2.0.0
		 *
		 * @var bool
		 */
		private static $booted = false;

		/**
		 * Registers a single controller instance through the woodev/v1 registrar.
		 *
		 * Idempotent: a second call is a no-op. Called from
		 * Woodev_Plugins_License::add_hooks().
		 *
		 * @since 2.0.0
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
		 * Registers the four license routes under the woodev/v1 namespace.
		 *
		 * @internal
		 *
		 * @since 2.0.0
		 *
		 * @return void
		 */
		public function register_routes(): void {

			$namespace = Woodev_REST_V1_Registrar::ROUTE_NAMESPACE;

			register_rest_route(
				$namespace,
				'/licenses/(?P<plugin_id>[\w-]+)',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				)
			);

			register_rest_route(
				$namespace,
				'/licenses/(?P<plugin_id>[\w-]+)/verify',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'verify_item' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'license_key' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => array( $this, 'validate_license_key' ),
						),
					),
				)
			);

			register_rest_route(
				$namespace,
				'/licenses/(?P<plugin_id>[\w-]+)/deactivate',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'deactivate_item' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				)
			);

			register_rest_route(
				$namespace,
				'/licenses/(?P<plugin_id>[\w-]+)/beta',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'set_beta' ),
					'permission_callback' => array( $this, 'check_permissions' ),
					'args'                => array(
						'enabled' => array(
							'type'              => 'boolean',
							'required'          => true,
							'validate_callback' => static function ( $value ) {
								return is_bool( $value );
							},
						),
					),
				)
			);
		}

		/**
		 * Capability gate for every route.
		 *
		 * @internal
		 *
		 * @since 2.0.0
		 *
		 * @return true|WP_Error True when allowed, a 401/403 WP_Error otherwise.
		 */
		public function check_permissions() {

			if ( current_user_can( 'manage_options' ) ) {
				return true;
			}

			return new WP_Error(
				'woodev_license_forbidden',
				esc_html__( 'Недостаточно прав для управления лицензиями.', 'woodev-plugin-framework' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		/**
		 * Validates the license_key arg against its SANITIZED form.
		 *
		 * WP runs validate_callback BEFORE sanitize_callback, so the raw value is
		 * what arrives here. Validating the raw value lets input like
		 * '<script></script>' pass (non-empty raw), then sanitize to '' and reach
		 * activate() as an empty key — surfacing as a misleading 502. We instead run
		 * sanitize_text_field() up front and reject a non-string, or anything that is
		 * empty after trimming the sanitized form.
		 *
		 * @internal
		 *
		 * @since 2.0.0
		 *
		 * @param mixed $value The submitted (raw) value.
		 *
		 * @return bool
		 */
		public function validate_license_key( $value ): bool {

			if ( ! is_string( $value ) ) {
				return false;
			}

			return '' !== trim( sanitize_text_field( $value ) );
		}

		/**
		 * GET handler: returns the resolved engine's current state.
		 *
		 * @internal
		 *
		 * @since 2.0.0
		 *
		 * @param WP_REST_Request $request The REST request.
		 *
		 * @return WP_REST_Response|WP_Error
		 */
		public function get_item( $request ) {

			$engine = $this->resolve_license( (string) $request->get_param( 'plugin_id' ) );

			if ( $engine instanceof WP_Error ) {
				return $engine;
			}

			return $this->respond(
				static function () use ( $engine ) {
					return $engine->get_state();
				}
			);
		}

		/**
		 * POST /verify handler: activates the submitted key, returns the new state.
		 *
		 * @internal
		 *
		 * @since 2.0.0
		 *
		 * @param WP_REST_Request $request The REST request.
		 *
		 * @return WP_REST_Response|WP_Error
		 */
		public function verify_item( $request ) {

			$engine = $this->resolve_license( (string) $request->get_param( 'plugin_id' ) );

			if ( $engine instanceof WP_Error ) {
				return $engine;
			}

			$license_key = (string) $request->get_param( 'license_key' );

			return $this->respond(
				static function () use ( $engine, $license_key ) {
					return $engine->activate( $license_key );
				}
			);
		}

		/**
		 * POST /deactivate handler: deactivates the license, returns the new state.
		 *
		 * @internal
		 *
		 * @since 2.0.0
		 *
		 * @param WP_REST_Request $request The REST request.
		 *
		 * @return WP_REST_Response|WP_Error
		 */
		public function deactivate_item( $request ) {

			$engine = $this->resolve_license( (string) $request->get_param( 'plugin_id' ) );

			if ( $engine instanceof WP_Error ) {
				return $engine;
			}

			return $this->respond(
				static function () use ( $engine ) {
					return $engine->deactivate();
				}
			);
		}

		/**
		 * POST /beta handler: persists the beta opt-in, returns the new state.
		 *
		 * @internal
		 *
		 * @since 2.0.0
		 *
		 * @param WP_REST_Request $request The REST request.
		 *
		 * @return WP_REST_Response|WP_Error
		 */
		public function set_beta( $request ) {

			$engine = $this->resolve_license( (string) $request->get_param( 'plugin_id' ) );

			if ( $engine instanceof WP_Error ) {
				return $engine;
			}

			$enabled = (bool) $request->get_param( 'enabled' );

			return $this->respond(
				static function () use ( $engine, $enabled ) {
					$engine->set_beta_enabled( $enabled );

					return $engine->get_state();
				}
			);
		}

		/**
		 * Resolves the license engine for a plugin_id from the static registry.
		 *
		 * @since 2.0.0
		 *
		 * @param string $plugin_id The EDD download id from the route.
		 *
		 * @return Woodev_Plugins_License|WP_Error The engine, or a 404 WP_Error when unknown.
		 */
		private function resolve_license( string $plugin_id ) {

			$engine = Woodev_Plugins_License::get_registered_instance( $plugin_id );

			if ( null === $engine ) {
				return new WP_Error(
					'woodev_license_unknown_plugin',
					esc_html__( 'Плагин с указанным идентификатором не найден.', 'woodev-plugin-framework' ),
					array( 'status' => 404 )
				);
			}

			return $engine;
		}

		/**
		 * Runs a pure-op callback and maps any thrown Throwable to a WP_Error.
		 *
		 * No catch swallows the error: a failure always surfaces as a 502 WP_Error
		 * with a non-empty user-facing message (the thrown message, or a Russian
		 * fallback when the throwable carries an empty message).
		 *
		 * @since 2.0.0
		 *
		 * @param callable $operation The pure op to run; returns the state array.
		 *
		 * @return WP_REST_Response|WP_Error
		 */
		private function respond( callable $operation ) {

			try {
				$state = $operation();

				return rest_ensure_response( $state );
			} catch ( \Throwable $e ) {
				$message = trim( (string) $e->getMessage() );

				if ( '' === $message ) {
					$message = esc_html__( 'Не удалось выполнить запрос к серверу лицензий.', 'woodev-plugin-framework' );
				}

				return new WP_Error(
					'woodev_license_request_failed',
					$message,
					array( 'status' => 502 )
				);
			}
		}
	}

endif;
