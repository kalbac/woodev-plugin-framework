<?php
/**
 * Settings page REST controller (woodev/v1).
 *
 * @package Woodev\Framework\REST
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_REST_API_Settings_Page' ) ) :

	/**
	 * Serves the aggregated settings schema and persists per-tab values.
	 *
	 * One controller for the whole Woodev > Настройки page (decision 4): GET
	 * returns every accessible tab's schema; POST /{provider_id} routes values to
	 * that provider's handler->update_value() (validation + sanitize + coercion
	 * already live in the Settings API). Registered through Woodev_REST_V1_Registrar.
	 *
	 * Named _Page to avoid colliding with the legacy wc/v3 Woodev_REST_API_Settings.
	 *
	 * @since 2.0.2
	 */
	class Woodev_REST_API_Settings_Page {

		/**
		 * Settings-page registry.
		 *
		 * @since 2.0.2
		 *
		 * @var \Woodev\Framework\Settings\Settings_Page_Registry
		 */
		private $registry;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param \Woodev\Framework\Settings\Settings_Page_Registry $registry registry.
		 */
		public function __construct( $registry ) {
			$this->registry = $registry;
		}

		/**
		 * Registers the settings routes.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function register_routes(): void {
			$base = Woodev_REST_V1_Registrar::ROUTE_NAMESPACE;

			register_rest_route(
				$base,
				'/settings',
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_schema' ],
					'permission_callback' => [ $this, 'read_permissions_check' ],
				]
			);

			register_rest_route(
				$base,
				'/settings/(?P<provider_id>[\w-]+)',
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'save' ],
					'permission_callback' => [ $this, 'save_permissions_check' ],
				]
			);

			register_rest_route(
				$base,
				'/settings/(?P<provider_id>[\w-]+)/connection/(?P<connection_id>[\w-]+)/test',
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'test_connection' ],
					'permission_callback' => [ $this, 'save_permissions_check' ],
				]
			);
		}

		/**
		 * Read gate: the page-level (broadest-reach) capability.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function read_permissions_check(): bool {
			return current_user_can( $this->registry->get_page_capability() );
		}

		/**
		 * Save gate: the targeted provider's resolved capability.
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request.
		 * @return bool
		 */
		public function save_permissions_check( $request ): bool {
			$capability = $this->registry->get_provider_capability( (string) $request->get_param( 'provider_id' ) );

			if ( null === $capability ) {
				return false;
			}

			return current_user_can( $capability );
		}

		/**
		 * Returns the cap-filtered tab schema for the current user.
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request.
		 * @return \WP_REST_Response|array<string,mixed>
		 */
		public function get_schema( $request ) {
			return rest_ensure_response( [ 'tabs' => $this->registry->get_tabs() ] );
		}

		/**
		 * Persists one tab's values through its handler — atomic two-pass.
		 *
		 * Values are scoped to the tab's declared section setting ids (a crafted
		 * request can never reach a setting the handler registered but this tab
		 * does not expose). Pass 1 validates ALL submitted fields and collects a
		 * per-field error map; if any errors exist, nothing is persisted and the
		 * map is returned under data.errors (status 400). Pass 2 persists only
		 * when all fields are valid.
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request.
		 * @return \WP_REST_Response|\WP_Error|array<string,mixed>
		 */
		public function save( $request ) {
			$provider_id = (string) $request->get_param( 'provider_id' );
			$provider    = $this->registry->get_provider( $provider_id );

			if ( null === $provider ) {
				return new WP_Error(
					'woodev_settings_unknown_provider',
					__( 'Неизвестная вкладка настроек.', 'woodev-plugin-framework' ),
					[ 'status' => 404 ]
				);
			}

			$handler = $provider->get_handler();
			$values  = (array) $request->get_param( 'values' );

			// Scope to the tab's DECLARED setting ids (mirrors the wizard's
			// array_intersect_key allow-list): a crafted request must never reach
			// a setting the handler registered but this tab does not expose.
			$allowed = [];
			foreach ( $provider->get_sections() as $section ) {
				$allowed = array_merge( $allowed, $section->get_setting_ids() );
			}
			$values = array_intersect_key( $values, array_flip( $allowed ) );

			// Pass 1 — validate everything; persist nothing on any failure.
			$errors = $handler->validate_values( $values );

			if ( ! empty( $errors ) ) {
				return new WP_Error(
					'woodev_settings_invalid',
					__( 'Проверьте правильность заполнения полей.', 'woodev-plugin-framework' ),
					[
						'status' => 400,
						'errors' => $errors,
					]
				);
			}

			// Pass 2 — persist. A throw here is unexpected (already validated) → 500.
			foreach ( $values as $setting_id => $value ) {
				try {
					$handler->update_value( (string) $setting_id, $value );
				} catch ( \Throwable $e ) {
					error_log( sprintf( '[woodev] settings save failed on "%s": %s', $setting_id, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for an unexpected persistence failure.
					return new WP_Error(
						'woodev_settings_server_error',
						__( 'Внутренняя ошибка сервера. Попробуйте ещё раз.', 'woodev-plugin-framework' ),
						[ 'status' => 500 ]
					);
				}
			}

			return rest_ensure_response(
				[
					'saved'    => true,
					'provider' => $provider_id,
				]
			);
		}

		/**
		 * Runs a connection block's test/connect action through the plugin callback.
		 *
		 * Merges the POSTed (unsaved) values with the stored values for the block's
		 * declared setting ids, so an untouched (masked) secret still reaches the
		 * plugin's test. The plugin owns all auth behavior; the framework only
		 * transports the Woodev_Connection_Result.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request.
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function test_connection( $request ) {
			$provider_id   = (string) $request->get_param( 'provider_id' );
			$connection_id = (string) $request->get_param( 'connection_id' );
			$provider      = $this->registry->get_provider( $provider_id );

			if ( null === $provider ) {
				return new WP_Error(
					'woodev_settings_unknown_provider',
					__( 'Неизвестная вкладка настроек.', 'woodev-plugin-framework' ),
					[ 'status' => 404 ]
				);
			}

			$handler = $provider->get_handler();

			if ( ! $handler instanceof \Woodev_Settings_Connection_Test ) {
				return new WP_Error(
					'woodev_settings_no_connection_test',
					__( 'Проверка подключения для этого раздела недоступна.', 'woodev-plugin-framework' ),
					[ 'status' => 400 ]
				);
			}

			// Find the connection section and its declared setting ids.
			$section_ids = null;
			foreach ( $provider->get_sections() as $section ) {
				if ( $section->get_id() === $connection_id && $section->is_connection() ) {
					$section_ids = $section->get_setting_ids();
					break;
				}
			}

			if ( null === $section_ids ) {
				return new WP_Error(
					'woodev_settings_unknown_connection',
					__( 'Неизвестный блок подключения.', 'woodev-plugin-framework' ),
					[ 'status' => 404 ]
				);
			}

			// Scope POSTed values to the block's declared setting ids (allow-list).
			$posted = array_intersect_key( (array) $request->get_param( 'values' ), array_flip( $section_ids ) );

			// Merge per field. The stored-value fallback exists ONLY to recover a
			// masked secret the browser never held: it applies to a secret field
			// (sensitive or constant-backed) whose POSTed value is absent or empty.
			// A non-secret field always uses its POSTed value when present — even
			// '', 0, or false are valid, intentional inputs and must not be
			// silently replaced by the stored value.
			$merged = [];
			foreach ( $section_ids as $setting_id ) {
				$setting   = $handler->get_setting( $setting_id );
				$is_secret = $setting instanceof \Woodev_Setting
					&& ( $setting->is_sensitive() || null !== $setting->get_constant_name() );

				$use_posted = array_key_exists( $setting_id, $posted )
					&& ( ! $is_secret || '' !== (string) $posted[ $setting_id ] );

				if ( $use_posted ) {
					$merged[ $setting_id ] = $posted[ $setting_id ];
				} else {
					try {
						$merged[ $setting_id ] = $handler->get_value( $setting_id );
					} catch ( \Woodev_Plugin_Exception $e ) {
						$merged[ $setting_id ] = null;
					}
				}
			}

			try {
				$result = $handler->test_connection( $connection_id, $merged );
			} catch ( \Throwable $e ) {
				error_log( sprintf( '[woodev] connection test failed for %s/%s: %s', $provider_id, $connection_id, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for an unexpected callback failure.
				return new WP_Error(
					'woodev_settings_connection_error',
					__( 'Ошибка при проверке подключения.', 'woodev-plugin-framework' ),
					[ 'status' => 500 ]
				);
			}

			return rest_ensure_response( $result->to_array() );
		}
	}

endif;
