<?php
/**
 * API request logger handler.
 *
 * @package Woodev\Framework\Handlers
 */

namespace Woodev\Framework\Handlers;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\Woodev\Framework\Handlers\API_Logger' ) ) :
	/**
	 * Formats and logs API requests/responses broadcast by Woodev_API_Base.
	 *
	 * Registration is NOT unconditional in the constructor (unlike Cron_Handler /
	 * Translation_Handler): `Woodev_Payment_Gateway_Plugin::add_api_request_logging()`
	 * no-ops the base's registration hook because payment gateways log per-gateway via
	 * their own `Woodev_Payment_Gateway::add_api_request_logging()` instead (kept
	 * per-gateway log files). Constructing this handler eagerly and self-registering
	 * would double-log on every live payment plugin. So `Woodev_Plugin` keeps
	 * `add_api_request_logging()` as an overridable method whose base implementation
	 * calls `register()` below; the no-op override on the payment gateway plugin is
	 * untouched and still suppresses it.
	 *
	 * `get_api_log_message()` is also called directly (outside the action) by
	 * `Woodev_Payment_Gateway::log_api_request()` via `$this->get_plugin()->…`, and
	 * `log_api_request()` is called directly by `Woodev_Licensing_API::broadcast_request()`
	 * via `$this->plugin->…`. Both remain public methods on `Woodev_Plugin`, thinly
	 * delegating here, so neither call site needed to change.
	 *
	 * The action hook name, its argument count/order, and the log message format are
	 * preserved exactly as they were inline on Woodev_Plugin to keep the installed-site
	 * contract stable.
	 *
	 * @since 2.0.1
	 */
	class API_Logger {

		/** @var \Woodev_Plugin current plugin instance */
		private \Woodev_Plugin $plugin;

		/**
		 * API logger constructor.
		 *
		 * @since 2.0.1
		 *
		 * @param \Woodev_Plugin $plugin the plugin instance
		 */
		public function __construct( \Woodev_Plugin $plugin ) {
			$this->plugin = $plugin;
		}

		/**
		 * Registers the `woodev_{plugin_id}_api_request_performed` listener that
		 * automatically logs API requests/responses when using Woodev_API_Base.
		 *
		 * @since 2.0.1
		 *
		 * @see Woodev_API_Base::broadcast_request()
		 *
		 * @return void
		 */
		public function register(): void {

			$hook = 'woodev_' . $this->plugin->get_id() . '_api_request_performed';

			if ( ! has_action( $hook ) ) {
				add_action(
					$hook,
					array(
						$this,
						'log_api_request',
					),
					10,
					2
				);
			}
		}

		/**
		 * Logs API requests/responses.
		 *
		 * @since 2.0.1
		 *
		 * @param array       $request request data, see Woodev_API_Base::broadcast_request() for format
		 * @param array       $response response data
		 * @param string|null $log_id log to write data to
		 *
		 * @return void
		 */
		public function log_api_request( $request, $response, $log_id = null ) {

			$this->plugin->log( "Запрос\n" . $this->get_api_log_message( $request ), $log_id );

			if ( ! empty( $response ) ) {
				$this->plugin->log( "Ответ\n" . $this->get_api_log_message( $response ), $log_id );
			}
		}

		/**
		 * Transform the API request/response data into a string suitable for logging
		 *
		 * @since 2.0.1
		 *
		 * @param array $data
		 *
		 * @return string
		 */
		public function get_api_log_message( $data ) {

			$messages = array();

			$messages[] = isset( $data['uri'] ) && $data['uri'] ? 'Запрос' : 'Ответ';

			foreach ( (array) $data as $key => $value ) {
				$messages[] = sprintf( '%s: %s', $key, is_array( $value ) || ( is_object( $value ) && 'stdClass' == get_class( $value ) ) ? print_r( (array) $value, true ) : $value );
			}

			return implode( "\n", $messages );
		}
	}

endif;
