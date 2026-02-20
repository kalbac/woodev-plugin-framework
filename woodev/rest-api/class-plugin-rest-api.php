<?php

defined( 'ABSPATH' ) or exit;

if ( ! class_exists( 'Woodev_REST_API' ) ) :

	/**
	 * The plugin REST API handler class.
	 *
	 * This is responsible for hooking in to the WC REST API to add data for existing
	 * routes and/or register new routes.
	 */
class Woodev_REST_API {

	/** @var Woodev_Plugin plugin instance */
	private $plugin;

	/**
	 * Constructs the class.
	 *
	 * @since 5.2.0
	 *
	 * @param Woodev_Plugin $plugin plugin instance
	 */
	public function __construct( Woodev_Plugin $plugin ) {

		$this->plugin = $plugin;

		$this->add_hooks();
	}

	/**
	 * Adds the action and filter hooks.
	 *
	 */
	protected function add_hooks() {
		// add plugin data to the system status
		add_filter( 'woocommerce_rest_prepare_system_status', array( $this, 'add_system_status_data' ), 10, 3 );
		// registers new WC REST API routes
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Adds plugin data to the system status.
	 *
	 * @internal
	 *
	 * @since 5.2.0
	 *
	 * @param WP_REST_Response $response REST API response object
	 * @param array $system_status system status data
	 * @param WP_REST_Request $request REST API request object
	 * @return WP_REST_Response
	 */
	public function add_system_status_data( $response, $system_status, $request ) {

		$data = array(
			'is_payment_gateway' => $this->get_plugin() instanceof Woodev_Payment_Gateway_Plugin
		);

		$data = array_merge( $data, $this->get_system_status_data() );

		/**
		 * Filters the data added to the WooCommerce REST API System Status response.
		 *
		 * @since 5.2.0
		 *
		 * @param array $data system status response data
		 * @param WP_REST_Response $response REST API response object
		 * @param WP_REST_Request $request REST API request object
		 */
		$data = apply_filters( 'wc_' . $this->get_plugin()->get_id() . '_rest_api_system_status_data', $data, $response, $request );

		$response->data[ 'wc_' . $this->get_plugin()->get_id() ] = $data;

		return $response;
	}

	/**
	 * Gets the data to add to the WooCommerce REST API System Status response.
	 *
	 * Plugins can override this to add their own data.
	 *
	 * @return array
	 */
	protected function get_system_status_data() {
		return array();
	}

	/**
	 * Registers new WC REST API routes.
	 */
	public function register_routes() {

		if ( $settings = $this->get_plugin()->get_settings_handler() ) {

			$settings_controller = new Woodev_REST_API_Settings( $settings );

			$settings_controller->register_routes();
		}
	}

	/**
	 * Gets the plugin instance.
	 *
	 * @since 5.2.0
	 *
	 * @return Woodev_Plugin|Woodev_Payment_Gateway_Plugin
	 */
	protected function get_plugin() {
		return $this->plugin;
	}
}


endif;
