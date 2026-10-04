<?php
/**
 * REST controller for the error-reporting consent checkbox.
 *
 * @package Woodev\Framework\Error_Reporting
 */

namespace Woodev\Framework\Error_Reporting;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\Woodev\Framework\Error_Reporting\Consent_Rest_Controller' ) ) :

	/**
	 * `GET|POST /woodev/v1/error-reporting` — reads and stores the site-wide consent.
	 *
	 * Registered through {@see \Woodev_REST_V1_Registrar} like the license routes; the
	 * `wp_rest` nonce is checked by core, this controller adds the `manage_options` gate.
	 *
	 * @since 2.0.2
	 */
	final class Consent_Rest_Controller {

		/**
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function register_routes(): void {
			register_rest_route(
				\Woodev_REST_V1_Registrar::ROUTE_NAMESPACE,
				'/error-reporting',
				[
					[
						'methods'             => 'GET',
						'callback'            => [ $this, 'get_item' ],
						'permission_callback' => [ $this, 'check_permissions' ],
					],
					[
						'methods'             => 'POST',
						'callback'            => [ $this, 'set_item' ],
						'permission_callback' => [ $this, 'check_permissions' ],
						'args'                => [
							'enabled' => [
								'type'              => 'boolean',
								'required'          => true,
								'validate_callback' => static function ( $value ): bool {
									return is_bool( $value );
								},
							],
						],
					],
				]
			);
		}

		/**
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return true|\WP_Error
		 */
		public function check_permissions() {
			if ( current_user_can( 'manage_options' ) ) {
				return true;
			}

			return new \WP_Error(
				'woodev_error_reporting_forbidden',
				esc_html__( 'Недостаточно прав для изменения этой настройки.', 'woodev-plugin-framework' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		/**
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function get_item() {
			return rest_ensure_response( Consent::get_state() );
		}

		/**
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request The request.
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function set_item( $request ) {
			Consent::set_enabled( (bool) $request->get_param( 'enabled' ) );

			return rest_ensure_response( Consent::get_state() );
		}
	}

endif;
