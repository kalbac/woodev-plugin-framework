<?php
/**
 * Woodev Shipping API Interface
 *
 * Defines the contract for shipping provider API implementations.
 * All shipping API classes must implement this interface to ensure
 * consistent communication with shipping carrier services.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! interface_exists( '\\Woodev\\Framework\\Shipping\\Shipping_API' ) ) :

	/**
	 * WooCommerce Shipping Method API
	 *
	 * Provides a standardized interface for interacting with shipping carrier APIs,
	 * including rate calculation, order management, tracking, and pickup point retrieval.
	 *
	 * @since 1.5.0
	 */
	interface Shipping_API {

		/**
		 * Calculates shipping rates for the given parameters.
		 *
		 * Sends a rate calculation request to the shipping carrier API and returns
		 * available shipping options with costs and delivery times.
		 *
		 * @since 1.5.0
		 *
		 * @param array $params {
		 *     Rate calculation parameters.
		 *
		 *     @type string $origin      Origin address or postal code.
		 *     @type string $destination  Destination address or postal code.
		 *     @type array  $packages     Package dimensions and weights.
		 *     @type string $currency     Currency code for rate amounts.
		 * }
		 *
		 * @return Woodev_Shipping_API_Rate_Response rate calculation response
		 * @throws Woodev_Shipping_Exception on network timeouts, API errors, or invalid parameters
		 */
		public function calculate_rates( array $params ): Woodev_Shipping_API_Rate_Response;


		/**
		 * Retrieves available pickup points for the given parameters.
		 *
		 * Queries the shipping carrier API for pickup point locations based on
		 * the provided search criteria such as city, postal code, or coordinates.
		 *
		 * @since 1.5.0
		 *
		 * @param array $params {
		 *     Pickup point search parameters.
		 *
		 *     @type string $city        City name to search in.
		 *     @type string $postal_code Postal code to search near.
		 *     @type float  $latitude    Latitude for coordinate-based search.
		 *     @type float  $longitude   Longitude for coordinate-based search.
		 *     @type int    $limit       Maximum number of results to return.
		 * }
		 *
		 * @return Woodev_Shipping_API_Pickup_Points_Response pickup points response
		 * @throws Woodev_Shipping_Exception on network timeouts, API errors, or invalid parameters
		 */
		public function get_pickup_points( array $params ): Woodev_Shipping_API_Pickup_Points_Response;


		/**
		 * Creates a shipping order with the carrier.
		 *
		 * Submits the order to the shipping carrier API for fulfillment,
		 * returning an order identifier and tracking number if available.
		 *
		 * @since 1.5.0
		 *
		 * @param Woodev_Exportable_Order $order the order to export to the shipping carrier
		 *
		 * @return Woodev_Shipping_API_Order_Response order creation response
		 * @throws Woodev_Shipping_Exception on network timeouts, API errors, or validation failures
		 */
		public function create_order( Woodev_Exportable_Order $order ): Woodev_Shipping_API_Order_Response;


		/**
		 * Retrieves the current status of a shipping order.
		 *
		 * Queries the carrier API for the latest information about an existing order,
		 * including its status and tracking number.
		 *
		 * @since 1.5.0
		 *
		 * @param string $order_id the carrier-assigned order identifier
		 *
		 * @return Woodev_Shipping_API_Order_Response order status response
		 * @throws Woodev_Shipping_Exception on network timeouts, API errors, or if the order is not found
		 */
		public function get_order( string $order_id ): Woodev_Shipping_API_Order_Response;


		/**
		 * Cancels a shipping order with the carrier.
		 *
		 * Sends a cancellation request to the carrier API. The response indicates
		 * whether the cancellation was successful and the updated order status.
		 *
		 * @since 1.5.0
		 *
		 * @param string $order_id the carrier-assigned order identifier
		 *
		 * @return Woodev_Shipping_API_Order_Response order cancellation response
		 * @throws Woodev_Shipping_Exception on network timeouts, API errors, or if cancellation is not allowed
		 */
		public function cancel_order( string $order_id ): Woodev_Shipping_API_Order_Response;


		/**
		 * Retrieves tracking information for a shipment.
		 *
		 * Queries the carrier API for the full tracking history and current
		 * delivery status of a shipment by its tracking number.
		 *
		 * @since 1.5.0
		 *
		 * @param string $tracking_number the carrier-assigned tracking number
		 *
		 * @return Woodev_Shipping_API_Tracking_Response tracking information response
		 * @throws Woodev_Shipping_Exception on network timeouts, API errors, or if the tracking number is not found
		 */
		public function get_tracking( string $tracking_number ): Woodev_Shipping_API_Tracking_Response;


		/**
		 * Returns the most recent request object.
		 *
		 * @since 1.5.0
		 *
		 * @return \Woodev_API_Request the most recent request object
		 */
		public function get_request(): \Woodev_API_Request;


		/**
		 * Returns the most recent response object.
		 *
		 * @since 1.5.0
		 *
		 * @return \Woodev_API_Response the most recent response object
		 */
		public function get_response(): \Woodev_API_Response;
	}

endif;
