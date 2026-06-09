<?php
/**
 * Woodev Abstract Warehouses REST Controller
 *
 * CRUD REST controller base for shipment-origin warehouses (spec §4.4, mirrored
 * from yandex `Warehouses_Rest_Api`). It exposes a collection route
 * (`GET` list / `POST` create) and a single-item route
 * (`GET` / `PUT` / `DELETE`) that read and write
 * {@see \Woodev\Framework\Shipping\Pickup\Warehouse} value objects through the
 * plugin's {@see \Woodev\Framework\Shipping\Pickup\Warehouse_Store}.
 *
 * Every route addresses a warehouse by its storage row id (an integer PK in the
 * backing store), kept strictly separate from the carrier-unique
 * {@see Warehouse::get_id()} that the body exposes as `code`. Updates are
 * read-merge: omitted fields are preserved from the persisted row, so a partial
 * update never overwrites installed-site data it did not touch.
 *
 * The framework ships only this abstract base — the REST namespace, the route
 * {@see Abstract_Warehouses_Controller::get_rest_base() base} and the
 * {@see Abstract_Warehouses_Controller::get_warehouse_store() store} are all
 * supplied by the concrete plugin controller, so the framework mints no live URL
 * contract of its own. Concrete controllers are wired through
 * {@see Shipping_REST_API::get_rest_controllers()}.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping\Rest_Api;

use Woodev\Framework\Shipping\Pickup\Warehouse;
use Woodev\Framework\Shipping\Pickup\Warehouse_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Rest_Api\\Abstract_Warehouses_Controller' ) ) :

	/**
	 * Warehouses CRUD controller base.
	 *
	 * A concrete subclass supplies the namespace, the route base and the carrier
	 * {@see Warehouse_Store}; this base owns the route registration, request
	 * parsing, persistence and serialization that every carrier shares. Every
	 * route addresses a warehouse by its {@see Warehouse_Store storage row id}.
	 *
	 * Carrier-specific fields outside the core schema are added by subclasses
	 * through three seams: {@see get_additional_schema_properties()} (declare),
	 * {@see merge_additional_fields_into_data()} (read into the persisted shape,
	 * typically the `raw` escape hatch) and {@see prepare_additional_response_fields()}
	 * (expose in the response).
	 *
	 * @since 1.5.0
	 */
	abstract class Abstract_Warehouses_Controller extends \WP_REST_Controller {

		/**
		 * Gets the REST namespace the routes register under.
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
		 * Gets the route base for the warehouses collection.
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
		 * Gets the warehouse store to read and write through.
		 *
		 * @since 1.5.0
		 *
		 * @return Warehouse_Store
		 */
		abstract protected function get_warehouse_store(): Warehouse_Store;

		/**
		 * Registers the warehouses collection and single-item routes.
		 *
		 * `/{rest_base}` carries the readable list + creatable collection;
		 * `/{rest_base}/(?P<id>\d+)` carries the readable, editable and deletable
		 * single warehouse, keyed by its integer storage row id.
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
					[
						'methods'             => \WP_REST_Server::CREATABLE,
						'callback'            => [ $this, 'create_item' ],
						'permission_callback' => [ $this, 'create_item_permissions_check' ],
						'args'                => $this->get_endpoint_args_for_item_schema( \WP_REST_Server::CREATABLE ),
					],
					'schema' => [ $this, 'get_public_item_schema' ],
				]
			);

			register_rest_route(
				$this->get_namespace(),
				'/' . $this->get_rest_base() . '/(?P<id>\d+)',
				[
					'args'   => [
						'id' => [
							'description' => __( 'Storage row id of the warehouse.', 'woodev-plugin-framework' ),
							'type'        => 'integer',
						],
					],
					[
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => [ $this, 'get_item' ],
						'permission_callback' => [ $this, 'get_item_permissions_check' ],
						'args'                => [ 'context' => $this->get_context_param( [ 'default' => 'view' ] ) ],
					],
					[
						'methods'             => \WP_REST_Server::EDITABLE,
						'callback'            => [ $this, 'update_item' ],
						'permission_callback' => [ $this, 'update_item_permissions_check' ],
						'args'                => $this->get_endpoint_args_for_item_schema( \WP_REST_Server::EDITABLE ),
					],
					[
						'methods'             => \WP_REST_Server::DELETABLE,
						'callback'            => [ $this, 'delete_item' ],
						'permission_callback' => [ $this, 'delete_item_permissions_check' ],
					],
					'schema' => [ $this, 'get_public_item_schema' ],
				]
			);
		}

		/**
		 * Checks whether the current user may act on warehouses.
		 *
		 * Ties access to the WooCommerce shop-manager capability; a concrete
		 * controller may override the per-method checks to widen or narrow this.
		 *
		 * @since 1.5.0
		 *
		 * @param string $action capability action (`read`, `create`, `edit`, `delete`)
		 * @param string $code   error code returned when access is denied
		 *
		 * @return bool|\WP_Error
		 */
		protected function check_permissions( string $action, string $code ) {

			if ( function_exists( 'wc_rest_check_manager_permissions' ) ) {
				$allowed = wc_rest_check_manager_permissions( 'settings', $action );
			} else {
				$allowed = current_user_can( 'manage_woocommerce' );
			}

			if ( ! $allowed ) {
				return new \WP_Error(
					$code,
					__( 'Sorry, you are not allowed to do that.', 'woodev-plugin-framework' ),
					[ 'status' => rest_authorization_required_code() ]
				);
			}

			return true;
		}

		/**
		 * Checks whether the current user may list warehouses.
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
			return $this->check_permissions( 'read', 'woodev_rest_cannot_view' );
		}

		/**
		 * Checks whether the current user may read a single warehouse.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @param \WP_REST_Request $request request object
		 *
		 * @return bool|\WP_Error
		 */
		public function get_item_permissions_check( $request ) {
			return $this->check_permissions( 'read', 'woodev_rest_cannot_view' );
		}

		/**
		 * Checks whether the current user may create a warehouse.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @param \WP_REST_Request $request request object
		 *
		 * @return bool|\WP_Error
		 */
		public function create_item_permissions_check( $request ) {
			return $this->check_permissions( 'create', 'woodev_rest_cannot_create' );
		}

		/**
		 * Checks whether the current user may update a warehouse.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @param \WP_REST_Request $request request object
		 *
		 * @return bool|\WP_Error
		 */
		public function update_item_permissions_check( $request ) {
			return $this->check_permissions( 'edit', 'woodev_rest_cannot_edit' );
		}

		/**
		 * Checks whether the current user may delete a warehouse.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @param \WP_REST_Request $request request object
		 *
		 * @return bool|\WP_Error
		 */
		public function delete_item_permissions_check( $request ) {
			return $this->check_permissions( 'delete', 'woodev_rest_cannot_delete' );
		}

		/**
		 * Lists every stored warehouse.
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

			$data = [];

			foreach ( $this->get_warehouse_store()->all() as $warehouse ) {
				$data[] = $this->prepare_response_for_collection( $this->prepare_item_for_response( $warehouse, $request ) );
			}

			return rest_ensure_response( $data );
		}

		/**
		 * Reads a single warehouse by its storage row id.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @param \WP_REST_Request $request request object
		 *
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function get_item( $request ) {

			$warehouse = $this->get_warehouse_store()->get( (int) $request['id'] );

			if ( ! $warehouse instanceof Warehouse ) {
				return $this->not_found_error();
			}

			return $this->prepare_item_for_response( $warehouse, $request );
		}

		/**
		 * Creates a warehouse from the request body.
		 *
		 * Always inserts a new row: the value object is built with no existing row
		 * (so its storage id is null), the store inserts, and the response is read
		 * back from the store so the freshly-stamped storage id is exposed.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @param \WP_REST_Request $request request object
		 *
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function create_item( $request ) {

			$store     = $this->get_warehouse_store();
			$warehouse = $this->prepare_item_for_database( $request, null );
			$id        = $store->save( $warehouse );

			$saved = $store->get( $id );

			if ( ! $saved instanceof Warehouse ) {
				return new \WP_Error(
					'woodev_rest_cannot_create',
					__( 'The warehouse could not be created.', 'woodev-plugin-framework' ),
					[ 'status' => 500 ]
				);
			}

			$response = $this->prepare_item_for_response( $saved, $request );
			$response->set_status( 201 );

			return $response;
		}

		/**
		 * Updates an existing warehouse.
		 *
		 * Read-merge: the persisted row is loaded first and used as the base, so
		 * every field omitted from the request body is preserved (carrier id,
		 * coordinates, contacts, carrier-specific `raw` fields). The store updates
		 * the existing row because the merged value object carries its storage id.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @param \WP_REST_Request $request request object
		 *
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function update_item( $request ) {

			$store    = $this->get_warehouse_store();
			$existing = $store->get( (int) $request['id'] );

			if ( ! $existing instanceof Warehouse ) {
				return $this->not_found_error();
			}

			$store->save( $this->prepare_item_for_database( $request, $existing ) );

			$saved = $store->get( (int) $request['id'] );

			if ( ! $saved instanceof Warehouse ) {
				return $this->not_found_error();
			}

			return $this->prepare_item_for_response( $saved, $request );
		}

		/**
		 * Deletes a warehouse by its storage row id.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @param \WP_REST_Request $request request object
		 *
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function delete_item( $request ) {

			$store     = $this->get_warehouse_store();
			$warehouse = $store->get( (int) $request['id'] );

			if ( ! $warehouse instanceof Warehouse ) {
				return $this->not_found_error();
			}

			$previous = $this->prepare_item_for_response( $warehouse, $request );

			if ( ! $store->delete( (int) $request['id'] ) ) {
				return new \WP_Error(
					'woodev_rest_cannot_delete',
					__( 'The warehouse could not be deleted.', 'woodev-plugin-framework' ),
					[ 'status' => 500 ]
				);
			}

			return rest_ensure_response(
				[
					'deleted'  => true,
					'previous' => $previous->get_data(),
				]
			);
		}

		/**
		 * Builds a warehouse value object from the request body, merged onto the
		 * persisted state.
		 *
		 * Read-merge semantics prevent partial-update data loss: the merge starts
		 * from the existing warehouse (when updating) so every field absent from the
		 * request is preserved — including the storage row id, the carrier id, the
		 * `raw` escape hatch, coordinates and contacts. Each generic field is
		 * overlaid ONLY when the request actually supplies it. The route/numeric id
		 * is NEVER written into the carrier id slot.
		 *
		 * @since 1.5.0
		 *
		 * @param \WP_REST_Request $request  request object
		 * @param Warehouse|null   $existing persisted warehouse to merge onto, or null on create
		 *
		 * @return Warehouse
		 */
		protected function prepare_item_for_database( $request, ?Warehouse $existing = null ): Warehouse {

			// Start from the persisted state so omitted fields are preserved on update;
			// on create this is empty and every absent field defaults to the VO default.
			$data = $existing instanceof Warehouse ? $existing->to_array() : [];

			foreach ( [ 'name', 'address', 'contact_name', 'contact_phone', 'contact_email' ] as $key ) {
				if ( null !== $request->get_param( $key ) ) {
					$data[ $key ] = (string) $request->get_param( $key );
				}
			}

			foreach ( [ 'lat', 'lng' ] as $key ) {
				if ( null !== $request->get_param( $key ) ) {
					$data[ $key ] = (float) $request->get_param( $key );
				}
			}

			if ( null !== $request->get_param( 'work_hours' ) ) {
				$data['work_hours'] = (array) $request->get_param( 'work_hours' );
			}

			// The body's `code` carries the carrier-unique id (Warehouse::get_id()),
			// stored as $data['id']. On create with no `code` it stays ''; on update
			// with no `code` the existing carrier id is preserved (already in $data).
			if ( null !== $request->get_param( 'code' ) ) {
				$data['id'] = (string) $request->get_param( 'code' );
			}

			// The storage row id lives only in $data['storage_id']: preserved from the
			// existing warehouse on update, null on create. The route id is never
			// folded into the carrier id.

			$data = $this->merge_additional_fields_into_data( $data, $request );

			return Warehouse::from_array( $data );
		}

		/**
		 * Prepares a single warehouse for the REST response.
		 *
		 * Serializes the core schema (storage row id as `id`, carrier id as `code`),
		 * the `raw` escape hatch and any subclass carrier fields, then filters the
		 * result by the requested context.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @param Warehouse        $item    warehouse to serialize
		 * @param \WP_REST_Request $request request object
		 *
		 * @return \WP_REST_Response
		 */
		public function prepare_item_for_response( $item, $request ) {

			$data = [
				'id'            => $item->get_storage_id(),
				'code'          => $item->get_id(),
				'name'          => $item->get_name(),
				'address'       => $item->get_address(),
				'lat'           => $item->get_lat(),
				'lng'           => $item->get_lng(),
				'contact_name'  => $item->get_contact_name(),
				'contact_phone' => $item->get_contact_phone(),
				'contact_email' => $item->get_contact_email(),
				'work_hours'    => $item->get_work_hours(),
				'raw'           => $item->get_raw(),
			];

			$data = array_merge( $data, $this->prepare_additional_response_fields( $item, $request ) );

			$context = ! empty( $request['context'] ) ? $request['context'] : 'view';
			$data    = $this->add_additional_fields_to_object( $data, $request );
			$data    = $this->filter_response_by_context( $data, $context );

			return rest_ensure_response( $data );
		}

		/**
		 * Declares carrier-specific schema properties contributed by a subclass.
		 *
		 * Merged into the core schema by {@see get_item_schema()}. The base adds
		 * nothing; a concrete controller overrides this to expose its carrier fields
		 * as typed, contextual JSON-Schema entries.
		 *
		 * @since 2.0.0
		 *
		 * @return array<string, array<string, mixed>> property name => JSON-Schema definition
		 */
		protected function get_additional_schema_properties(): array {
			return [];
		}

		/**
		 * Overlays carrier-specific request fields onto the persisted data shape.
		 *
		 * Called by {@see prepare_item_for_database()} after the generic fields are
		 * merged. The base returns the data unchanged; a concrete controller reads
		 * its carrier fields from the request and writes them into the persisted
		 * shape — typically the `raw` escape hatch — preserving read-merge semantics
		 * (only overlay fields the request supplies).
		 *
		 * @since 2.0.0
		 *
		 * @param array            $data    persisted-shape data assembled so far
		 * @param \WP_REST_Request $request request object
		 *
		 * @return array
		 */
		protected function merge_additional_fields_into_data( array $data, $request ): array {
			return $data;
		}

		/**
		 * Exposes carrier-specific response fields contributed by a subclass.
		 *
		 * Merged into the response by {@see prepare_item_for_response()}. The base
		 * adds nothing; a concrete controller reads its carrier fields from the
		 * warehouse `raw` escape hatch (or dedicated getters) and returns them.
		 *
		 * @since 2.0.0
		 *
		 * @param Warehouse        $warehouse warehouse being serialized
		 * @param \WP_REST_Request $request   request object
		 *
		 * @return array carrier field name => value
		 */
		protected function prepare_additional_response_fields( Warehouse $warehouse, $request ): array {
			return [];
		}

		/**
		 * Builds the standard "warehouse not found" error.
		 *
		 * @since 1.5.0
		 *
		 * @return \WP_Error
		 */
		protected function not_found_error(): \WP_Error {

			return new \WP_Error(
				'woodev_rest_warehouse_invalid_id',
				__( 'Invalid warehouse id.', 'woodev-plugin-framework' ),
				[ 'status' => 404 ]
			);
		}

		/**
		 * Gets the query parameters accepted by the warehouses collection.
		 *
		 * @since 1.5.0
		 *
		 * @return array
		 */
		public function get_collection_params() {

			return [
				'context' => $this->get_context_param( [ 'default' => 'view' ] ),
			];
		}

		/**
		 * Retrieves the warehouse schema, conforming to JSON Schema.
		 *
		 * Mirrors the core {@see Warehouse} value-object fields, separating the
		 * read-only storage row `id` from the writable carrier `code`, then merges
		 * in any subclass carrier fields via {@see get_additional_schema_properties()}.
		 *
		 * @since 1.5.0
		 *
		 * @return array
		 */
		public function get_item_schema() {

			$context = [ 'view', 'edit' ];

			$schema = [
				'$schema'    => 'http://json-schema.org/draft-04/schema#',
				'title'      => 'warehouse',
				'type'       => 'object',
				'properties' => [
					'id'            => [
						'description' => __( 'Storage row id of the warehouse.', 'woodev-plugin-framework' ),
						'type'        => 'integer',
						'context'     => $context,
						'readonly'    => true,
					],
					'code'          => [
						'description' => __( 'Carrier-unique warehouse id.', 'woodev-plugin-framework' ),
						'type'        => 'string',
						'context'     => $context,
					],
					'name'          => [
						'description' => __( 'Human-readable name.', 'woodev-plugin-framework' ),
						'type'        => 'string',
						'context'     => $context,
					],
					'address'       => [
						'description' => __( 'Full one-line postal address.', 'woodev-plugin-framework' ),
						'type'        => 'string',
						'context'     => $context,
					],
					'lat'           => [
						'description' => __( 'Latitude in decimal degrees.', 'woodev-plugin-framework' ),
						'type'        => 'number',
						'context'     => $context,
					],
					'lng'           => [
						'description' => __( 'Longitude in decimal degrees.', 'woodev-plugin-framework' ),
						'type'        => 'number',
						'context'     => $context,
					],
					'contact_name'  => [
						'description' => __( 'Contact person name.', 'woodev-plugin-framework' ),
						'type'        => 'string',
						'context'     => $context,
					],
					'contact_phone' => [
						'description' => __( 'Contact phone.', 'woodev-plugin-framework' ),
						'type'        => 'string',
						'context'     => $context,
					],
					'contact_email' => [
						'description' => __( 'Contact email.', 'woodev-plugin-framework' ),
						'type'        => 'string',
						'context'     => $context,
					],
					'work_hours'    => [
						'description' => __( 'Working-hours descriptors.', 'woodev-plugin-framework' ),
						'type'        => 'object',
						'context'     => $context,
					],
					'raw'           => [
						'description' => __( 'Original carrier payload (escape hatch).', 'woodev-plugin-framework' ),
						'type'        => 'object',
						'context'     => $context,
					],
				],
			];

			$schema['properties'] = array_merge(
				$schema['properties'],
				$this->get_additional_schema_properties()
			);

			return $this->add_additional_fields_schema( $schema );
		}
	}

endif;
