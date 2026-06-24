<?php
/**
 * Setup wizard REST controller (woodev/v1).
 *
 * @package Woodev\Framework\REST
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_REST_API_Setup' ) ) :

	/**
	 * Serves the wizard bootstrap, persists per-step values, and finalizes setup.
	 *
	 * Registered through Woodev_REST_V1_Registrar (neutral woodev/v1 namespace).
	 *
	 * @since 2.0.2
	 */
	class Woodev_REST_API_Setup {

		/**
		 * Wizard handler.
		 *
		 * @since 2.0.2
		 *
		 * @var \Woodev\Framework\Setup\Setup_Wizard
		 */
		private $wizard;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param \Woodev\Framework\Setup\Setup_Wizard $wizard wizard handler.
		 */
		public function __construct( $wizard ) {
			$this->wizard = $wizard;
		}

		/**
		 * Registers the wizard routes.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function register_routes(): void {
			$id   = $this->wizard->get_id();
			$base = Woodev_REST_V1_Registrar::ROUTE_NAMESPACE;

			register_rest_route(
				$base,
				"/{$id}/setup/steps/(?P<step_id>[\w-]+)",
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'save_step' ],
					'permission_callback' => [ $this, 'permissions_check' ],
				]
			);

			register_rest_route(
				$base,
				"/{$id}/setup/complete",
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'complete' ],
					'permission_callback' => [ $this, 'permissions_check' ],
				]
			);
		}

		/**
		 * Capability gate mirroring the wizard page.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function permissions_check(): bool {
			return current_user_can( $this->wizard->get_required_capability() );
		}

		/**
		 * Validates + persists one step's values, then runs the optional on_save.
		 *
		 * Each setting is persisted as it passes validation: if setting N fails,
		 * settings 0..N-1 are already saved. This is intentional and idempotent —
		 * re-submitting the step overwrites any already-saved values. Settings are
		 * persisted BEFORE on_save; a thrown on_save reports an error while settings
		 * are already saved (on_save must therefore be idempotent too).
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request.
		 * @return \WP_REST_Response|\WP_Error|array<string,mixed>
		 */
		public function save_step( $request ) {
			$step_id = (string) $request->get_param( 'step_id' );
			$step    = $this->wizard->get_steps()[ $step_id ] ?? null;

			if ( null === $step ) {
				return new WP_Error(
					'woodev_setup_unknown_step',
					__( 'Неизвестный шаг.', 'woodev-plugin-framework' ),
					[ 'status' => 404 ]
				);
			}

			$handler = $this->wizard->get_plugin()->get_settings_handler();
			$values  = (array) $request->get_param( 'values' );

			if ( $handler ) {
				foreach ( $step->get_setting_ids() as $sid ) {
					if ( array_key_exists( $sid, $values ) ) {
						try {
							// update_value() validates (throws Woodev_Plugin_Exception) AND persists.
							$handler->update_value( $sid, $values[ $sid ] );
						} catch ( \Woodev_Plugin_Exception $e ) {
							return new WP_Error(
								'woodev_setup_invalid',
								$e->getMessage(),
								[
									'status' => $e->getCode() ?: 400,
									'field' => $sid,
								]
							);
						} catch ( \Throwable $e ) {
							// Unexpected failure (e.g. a third-party hook on update_option threw):
							// log for traceability and return a generic 500 — never leak internals.
							error_log( sprintf( '[woodev] setup wizard save_step failed on "%s": %s', $sid, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for an unexpected persistence failure.
							return new WP_Error(
								'woodev_setup_server_error',
								__( 'Внутренняя ошибка сервера. Попробуйте ещё раз.', 'woodev-plugin-framework' ),
								[ 'status' => 500 ]
							);
						}
					}
				}
			}

			$on_save = $step->get_on_save();
			if ( is_callable( $on_save ) ) {
				// Hand the callback only the values for fields declared on this step —
				// never arbitrary extra keys a crafted request may have included.
				$step_values = array_intersect_key( $values, array_flip( $step->get_setting_ids() ) );
				try {
					call_user_func( $on_save, $step_values, $request );
				} catch ( \Exception $e ) {
					// on_save is the plugin's own callback; surface its message as a 400
					// (settings are already persisted — on_save must be idempotent), and log.
					error_log( sprintf( '[woodev] setup wizard on_save failed for step "%s": %s', $step_id, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for an on_save failure.
					return new WP_Error( 'woodev_setup_step_failed', $e->getMessage(), [ 'status' => 400 ] );
				}
			}

			return rest_ensure_response(
				[
					'saved' => true,
					'step' => $step_id,
				]
			);
		}

		/**
		 * Finalizes the wizard (server-side authority).
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request.
		 * @return \WP_REST_Response|\WP_Error|array<string,mixed>
		 */
		public function complete( $request ) {
			$state = 'skipped' === $request->get_param( 'state' ) ? 'skipped' : 'completed';

			try {
				$this->wizard->complete_setup( $state );
			} catch ( \Throwable $e ) {
				// Never report success if the completion option was not persisted.
				error_log( sprintf( '[woodev] setup wizard complete failed: %s', $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for a completion persistence failure.
				return new WP_Error(
					'woodev_setup_complete_failed',
					__( 'Не удалось сохранить статус настройки. Попробуйте ещё раз.', 'woodev-plugin-framework' ),
					[ 'status' => 500 ]
				);
			}

			return rest_ensure_response(
				[
					'complete' => true,
					'state' => $state,
				]
			);
		}
	}

endif;
