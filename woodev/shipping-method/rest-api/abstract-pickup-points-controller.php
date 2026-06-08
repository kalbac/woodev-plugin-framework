<?php
/**
 * Woodev Abstract Pickup Points REST Controller
 *
 * Read-only REST controller base for pickup-point search (spec §4.4). It exposes a
 * single collection route that turns a search query into a uniform
 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Point}[] payload: it asks the
 * plugin's {@see \Woodev\Framework\Shipping\Pickup\Pickup_Point_Source} for the raw
 * points, then narrows them with
 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Point_Filter} before serializing.
 *
 * The framework ships only this abstract base — the REST namespace, the route
 * {@see Abstract_Pickup_Points_Controller::get_rest_base() base} and the
 * {@see Abstract_Pickup_Points_Controller::get_pickup_point_source() source} are
 * all supplied by the concrete plugin controller, so the framework mints no live
 * URL contract of its own. Concrete controllers are wired through
 * {@see Shipping_REST_API::get_rest_controllers()}.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping\Rest_Api;

use Woodev\Framework\Shipping\Pickup\Pickup_Point;
use Woodev\Framework\Shipping\Pickup\Pickup_Point_Filter;
use Woodev\Framework\Shipping\Pickup\Pickup_Point_Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Rest_Api\\Abstract_Pickup_Points_Controller' ) ) :

	/**
	 * Pickup-point search controller base.
	 *
	 * A concrete subclass supplies the namespace, the route base and the carrier
	 * {@see Pickup_Point_Source}; this base owns the route registration, query
	 * parsing, filtering and serialization that every carrier shares.
	 *
	 * @since 1.5.0
	 */
	abstract class Abstract_Pickup_Points_Controller extends \WP_REST_Controller {

		/**
		 * Gets the REST namespace the route registers under.
		 *
		 * Supplied by the plugin (its id-dasherized slug, mirrored from
		 * {@see Shipping_REST_API::get_namespace()}); the framework defines no
		 * namespace literal of its own.
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		abstract protected function get_namespace(): string;

		/**
		 * Gets the route base for the pickup-search collection.
		 *
		 * Supplied by the concrete controller so the framework introduces no live
		 * route segment.
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		abstract protected function get_rest_base(): string;

		/**
		 * Gets the carrier pickup-point source to query.
		 *
		 * @since 1.5.0
		 *
		 * @return Pickup_Point_Source
		 */
		abstract protected function get_pickup_point_source(): Pickup_Point_Source;

		/**
		 * Registers the pickup-search route.
		 *
		 * Read-only: a single `GET` collection endpoint.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @return void
		 */
		public function register_routes() {

			register_rest_route(
				$this->get_namespace(),
				'/' . $this->get_rest_base(),
				[
					[
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => [ $this, 'get_items' ],
						'permission_callback' => [ $this, 'get_items_permissions_check' ],
						'args'                => $this->get_collection_params(),
					],
					'schema' => [ $this, 'get_public_item_schema' ],
				]
			);
		}

		/**
		 * Checks whether the current user may search pickup points.
		 *
		 * Read-only access tied to the WooCommerce shop-manager capability; a
		 * concrete controller may override to widen or narrow this.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @param \WP_REST_Request $request request object
		 *
		 * @return bool|\WP_Error
		 */
		public function get_items_permissions_check( $request ) {

			if ( function_exists( 'wc_rest_check_manager_permissions' ) ) {
				$allowed = wc_rest_check_manager_permissions( 'settings', 'read' );
			} else {
				$allowed = current_user_can( 'manage_woocommerce' );
			}

			if ( ! $allowed ) {
				return new \WP_Error(
					'woodev_rest_cannot_view',
					__( 'Sorry, you cannot list resources.', 'woodev-plugin-framework' ),
					[ 'status' => rest_authorization_required_code() ]
				);
			}

			return true;
		}

		/**
		 * Searches the carrier and returns the narrowed pickup points.
		 *
		 * Queries the {@see Pickup_Point_Source} with the request's search
		 * parameters, then applies {@see Pickup_Point_Filter} with its filter
		 * criteria, and serializes each surviving point for the response.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @param \WP_REST_Request $request request object
		 *
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function get_items( $request ) {

			$points = $this->get_pickup_point_source()->search( $this->get_search_params( $request ) );
			$points = Pickup_Point_Filter::apply( $points, $this->get_filter_criteria( $request ) );

			$data = [];

			foreach ( $points as $point ) {
				$data[] = $this->prepare_response_for_collection( $this->prepare_item_for_response( $point, $request ) );
			}

			return rest_ensure_response( $data );
		}

		/**
		 * Prepares a single pickup point for the REST response.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @param Pickup_Point     $item    pickup point to serialize
		 * @param \WP_REST_Request $request request object
		 *
		 * @return \WP_REST_Response
		 */
		public function prepare_item_for_response( $item, $request ) {

			$data = $item instanceof Pickup_Point ? $item->to_array() : [];

			$context = ! empty( $request['context'] ) ? $request['context'] : 'view';
			$data    = $this->add_additional_fields_to_object( $data, $request );
			$data    = $this->filter_response_by_context( $data, $context );

			return rest_ensure_response( $data );
		}

		/**
		 * Extracts the carrier search parameters from the request.
		 *
		 * Only keys the source understands are forwarded; absent/empty values are
		 * dropped so the source receives a clean query.
		 *
		 * @since 1.5.0
		 *
		 * @param \WP_REST_Request $request request object
		 *
		 * @return array search parameters for {@see Pickup_Point_Source::search()}
		 */
		protected function get_search_params( $request ): array {

			$params = [];

			foreach ( [ 'city', 'postal_code' ] as $key ) {
				$value = (string) $request->get_param( $key );
				if ( '' !== $value ) {
					$params[ $key ] = $value;
				}
			}

			foreach ( [ 'lat', 'lng' ] as $key ) {
				if ( null !== $request->get_param( $key ) ) {
					$params[ $key ] = (float) $request->get_param( $key );
				}
			}

			if ( null !== $request->get_param( 'limit' ) ) {
				$params['limit'] = (int) $request->get_param( 'limit' );
			}

			return $params;
		}

		/**
		 * Extracts the {@see Pickup_Point_Filter} criteria from the request.
		 *
		 * Absent keys are omitted, so the filter no-ops on whatever the caller did
		 * not constrain.
		 *
		 * @since 1.5.0
		 *
		 * @param \WP_REST_Request $request request object
		 *
		 * @return array criteria for {@see Pickup_Point_Filter::apply()}
		 */
		protected function get_filter_criteria( $request ): array {

			$criteria = [];

			if ( null !== $request->get_param( 'type' ) ) {
				$criteria['type'] = $request->get_param( 'type' );
			}

			$payment_method = (string) $request->get_param( 'payment_method' );
			if ( '' !== $payment_method ) {
				$criteria['payment_method'] = $payment_method;
			}

			if ( null !== $request->get_param( 'max_weight' ) ) {
				$criteria['max_weight'] = (float) $request->get_param( 'max_weight' );
			}

			if ( null !== $request->get_param( 'max_dimensions' ) ) {
				$criteria['max_dimensions'] = $request->get_param( 'max_dimensions' );
			}

			return $criteria;
		}

		/**
		 * Gets the query parameters accepted by the search collection.
		 *
		 * @since 1.5.0
		 *
		 * @return array
		 */
		public function get_collection_params() {

			return [
				'context'        => $this->get_context_param( [ 'default' => 'view' ] ),
				'city'           => [
					'description' => __( 'City name to search pickup points in.', 'woodev-plugin-framework' ),
					'type'        => 'string',
				],
				'postal_code'    => [
					'description' => __( 'Postal code to search pickup points near.', 'woodev-plugin-framework' ),
					'type'        => 'string',
				],
				'lat'            => [
					'description' => __( 'Latitude for coordinate-based search.', 'woodev-plugin-framework' ),
					'type'        => 'number',
				],
				'lng'            => [
					'description' => __( 'Longitude for coordinate-based search.', 'woodev-plugin-framework' ),
					'type'        => 'number',
				],
				'limit'          => [
					'description' => __( 'Maximum number of pickup points to return.', 'woodev-plugin-framework' ),
					'type'        => 'integer',
				],
				'type'           => [
					'description' => __( 'Restrict results to pickup point type(s).', 'woodev-plugin-framework' ),
				],
				'payment_method' => [
					'description' => __( 'Keep only points that accept this payment method.', 'woodev-plugin-framework' ),
					'type'        => 'string',
				],
				'max_weight'     => [
					'description' => __( 'Parcel weight that must fit the point capacity.', 'woodev-plugin-framework' ),
					'type'        => 'number',
				],
				'max_dimensions' => [
					'description' => __( 'Parcel dimensions that must fit the point capacity.', 'woodev-plugin-framework' ),
				],
			];
		}

		/**
		 * Retrieves the pickup point schema, conforming to JSON Schema.
		 *
		 * Mirrors the core {@see Pickup_Point} value-object fields.
		 *
		 * @since 1.5.0
		 *
		 * @return array
		 */
		public function get_item_schema() {

			$context = [ 'view', 'edit' ];

			$schema = [
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'pickup_point',
				'type'       => 'object',
				'properties' => [
					'code'            => [
						'description' => __( 'Carrier-unique pickup point code.', 'woodev-plugin-framework' ),
						'type'        => 'string',
						'context'     => $context,
						'readonly'    => true,
					],
					'type'            => [
						'description' => __( 'Pickup point type.', 'woodev-plugin-framework' ),
						'type'        => 'string',
						'context'     => $context,
						'readonly'    => true,
					],
					'name'            => [
						'description' => __( 'Human-readable name.', 'woodev-plugin-framework' ),
						'type'        => 'string',
						'context'     => $context,
						'readonly'    => true,
					],
					'address_full'    => [
						'description' => __( 'Full one-line postal address.', 'woodev-plugin-framework' ),
						'type'        => 'string',
						'context'     => $context,
						'readonly'    => true,
					],
					'address'         => [
						'description' => __( 'Structured address parts.', 'woodev-plugin-framework' ),
						'type'        => 'object',
						'context'     => $context,
						'readonly'    => true,
					],
					'lat'             => [
						'description' => __( 'Latitude in decimal degrees.', 'woodev-plugin-framework' ),
						'type'        => 'number',
						'context'     => $context,
						'readonly'    => true,
					],
					'lng'             => [
						'description' => __( 'Longitude in decimal degrees.', 'woodev-plugin-framework' ),
						'type'        => 'number',
						'context'     => $context,
						'readonly'    => true,
					],
					'work_hours'      => [
						'description' => __( 'Working-hours descriptors.', 'woodev-plugin-framework' ),
						'type'        => 'array',
						'context'     => $context,
						'readonly'    => true,
					],
					'payment_methods' => [
						'description' => __( 'Accepted payment methods.', 'woodev-plugin-framework' ),
						'type'        => 'array',
						'context'     => $context,
						'readonly'    => true,
					],
					'max_weight'      => [
						'description' => __( 'Maximum accepted parcel weight.', 'woodev-plugin-framework' ),
						'type'        => 'number',
						'context'     => $context,
						'readonly'    => true,
					],
					'max_dimensions'  => [
						'description' => __( 'Maximum accepted parcel dimensions.', 'woodev-plugin-framework' ),
						'type'        => 'string',
						'context'     => $context,
						'readonly'    => true,
					],
					'phone'           => [
						'description' => __( 'Contact phone.', 'woodev-plugin-framework' ),
						'type'        => 'string',
						'context'     => $context,
						'readonly'    => true,
					],
					'raw'             => [
						'description' => __( 'Original carrier payload (escape hatch).', 'woodev-plugin-framework' ),
						'type'        => 'object',
						'context'     => $context,
						'readonly'    => true,
					],
				],
			];

			return $this->add_additional_fields_schema( $schema );
		}
	}

endif;
