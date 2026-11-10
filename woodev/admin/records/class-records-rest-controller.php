<?php
/**
 * Records engine — REST controller.
 *
 * @package Woodev\Framework\Admin\Records
 */

namespace Woodev\Framework\Admin\Records;

defined( 'ABSPATH' ) || exit;

/**
 * The read routes of the records engine under `woodev/v1/admin/records/{owner}/{table}`:
 * definition, list, children of a row, and the options of an action input field.
 *
 * The request never names a class, URL or callback: `{owner}` and `{table}` are looked up in
 * {@see Records_Registry}. Every route runs the same order, so nothing reaches a provider
 * before it is validated:
 *
 * 1. unknown query parameters → 400;
 * 2. unknown table → 404; missing capability → 403 (permission callback);
 * 3. the provider's definition for this user, validated ({@see Records_Definition});
 * 4. the requested account must be among the options the provider offered → else 404;
 * 5. the query validated against the definition → 400, oversized → 400;
 * 6. the provider's own object check ({@see Records_Provider::authorize()}) → else 404;
 * 7. only then the provider reads rows, and its answer is rebuilt by {@see Row_Normalizer}.
 *
 * The action routes (preview, apply) and the activity route are added by their own cards
 * through the same registry; they are not registered here.
 *
 * @since 2.0.29
 */
final class Records_Rest_Controller {

	/** Route prefix shared by every records route (after the `woodev/v1` namespace). */
	const ROUTE_BASE = '/admin/records/(?P<owner>[a-z0-9][a-z0-9_-]{0,63})/(?P<table>[a-z0-9][a-z0-9_-]{0,63})';

	/** Row id as it appears in a path. */
	const ID_PATTERN = '[A-Za-z0-9_.:-]{1,191}';

	/**
	 * @var Records_Registry
	 */
	private $registry;

	/**
	 * @param Records_Registry $registry Registry the routes resolve tables from.
	 */
	public function __construct( Records_Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Registers the read routes.
	 *
	 * @internal
	 *
	 * @since 2.0.29
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$namespace = \Woodev_REST_V1_Registrar::ROUTE_NAMESPACE;
		$routes    = [
			self::ROUTE_BASE . '/definition'                        => 'get_definition',
			self::ROUTE_BASE                                        => 'get_rows',
			self::ROUTE_BASE . '/(?P<id>' . self::ID_PATTERN . ')/children' => 'get_children',
			self::ROUTE_BASE . '/actions/(?P<action>[a-z0-9][a-z0-9_-]{0,63})/fields/(?P<field>[a-z0-9][a-z0-9_-]{0,63})/options' => 'get_field_options',
		];

		foreach ( $routes as $pattern => $callback ) {
			register_rest_route(
				$namespace,
				$pattern,
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, $callback ],
					'permission_callback' => [ $this, 'check_permission' ],
				]
			);
		}
	}

	/**
	 * Gate: the table must exist and the user must hold its capability.
	 *
	 * The cookie nonce is checked by WordPress core before this runs; a valid nonce proves the
	 * request is the user's own, not that the user may read this table.
	 *
	 * @since 2.0.29
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function check_permission( \WP_REST_Request $request ) {
		$registration = $this->registry->get( $this->route_param( $request, 'owner' ), $this->route_param( $request, 'table' ) );

		if ( null === $registration ) {
			return Records_Error::not_found();
		}

		return current_user_can( $registration->get_capability() ) ? true : Records_Error::forbidden();
	}

	/**
	 * `GET R/definition`.
	 *
	 * @since 2.0.29
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_definition( \WP_REST_Request $request ) {
		$call = $this->open( $request, [ 'context' ] );
		if ( $call instanceof \WP_Error ) {
			return $call;
		}

		$denied = $this->authorize( $call, 'definition', [] );
		if ( null !== $denied ) {
			return $denied;
		}

		$definition = $call['definition']->to_array();
		unset( $definition['schema_version'] );

		return $this->respond(
			[
				'schema_version' => Records_Definition::SCHEMA_VERSION,
				'definition'     => $this->for_user( $definition ),
				'context'        => $call['context'],
			]
		);
	}

	/**
	 * `GET R`.
	 *
	 * @since 2.0.29
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_rows( \WP_REST_Request $request ) {
		$call = $this->open( $request, [ 'context', 'page', 'per_page', 'search', 'sort', 'direction', 'filters' ] );
		if ( $call instanceof \WP_Error ) {
			return $call;
		}

		$params = $request->get_query_params();
		unset( $params['context'] );

		$query = Query_Validator::list_query( $call['definition'], $params );
		if ( $query instanceof \WP_Error ) {
			return $query;
		}

		$denied = $this->authorize( $call, 'list', [] );
		if ( null !== $denied ) {
			return $denied;
		}

		return $this->read(
			$call,
			static function ( Records_Provider $provider ) use ( $call, $query ): array {
				return $provider->query( $call['context'], $query );
			},
			$query,
			false
		);
	}

	/**
	 * `GET R/{id}/children`.
	 *
	 * @since 2.0.29
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_children( \WP_REST_Request $request ) {
		$call = $this->open( $request, [ 'context', 'page', 'per_page' ] );
		if ( $call instanceof \WP_Error ) {
			return $call;
		}

		$parent_id = $this->route_param( $request, 'id' );
		$params    = $request->get_query_params();
		unset( $params['context'] );

		$query = Query_Validator::children_query( $call['definition'], $params );
		if ( $query instanceof \WP_Error ) {
			return $query;
		}

		$denied = $this->authorize( $call, 'children', [ $parent_id ] );
		if ( null !== $denied ) {
			return $denied;
		}

		return $this->read(
			$call,
			static function ( Records_Provider $provider ) use ( $call, $parent_id, $query ): array {
				return $provider->get_children( $call['context'], $parent_id, $query );
			},
			$query,
			true
		);
	}

	/**
	 * `GET R/actions/{action}/fields/{field}/options`.
	 *
	 * @since 2.0.29
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_field_options( \WP_REST_Request $request ) {
		$call = $this->open( $request, [ 'context', 'row_id', 'search', 'cursor' ] );
		if ( $call instanceof \WP_Error ) {
			return $call;
		}

		$action = $this->route_param( $request, 'action' );
		$field  = $this->route_param( $request, 'field' );
		$gate   = $this->option_source_gate( $call, $action, $field );
		if ( $gate instanceof \WP_Error ) {
			return $gate;
		}

		$query = $this->options_query( $request->get_query_params() );
		if ( $query instanceof \WP_Error ) {
			return $query;
		}

		$denied = $this->authorize( $call, 'options', '' === $query['row_id'] ? [] : [ $query['row_id'] ] );
		if ( null !== $denied ) {
			return $denied;
		}

		try {
			$result = $gate->get_field_options( $call['context'], $action, $field, $query );
		} catch ( \Throwable $e ) {
			return $this->fail( $call, 'options', $e );
		}

		$page = Row_Normalizer::options_page( $result );

		return $page instanceof \WP_Error ? $page : $this->respond( $page );
	}

	/**
	 * Steps 1, 3 and 4 shared by every route: parameters, definition, account.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string[]         $allowed Query parameters the route accepts.
	 * @return array{registration:Records_Registration,definition:Records_Definition,context:array{account_id:string}}|\WP_Error
	 */
	private function open( \WP_REST_Request $request, array $allowed ) {
		$query_params = $request->get_query_params();
		$unknown      = Query_Validator::reject_unknown( $query_params, $allowed );
		$registration = $this->registry->get( $this->route_param( $request, 'owner' ), $this->route_param( $request, 'table' ) );

		if ( null !== $unknown ) {
			return $unknown;
		}
		if ( null === $registration ) {
			return Records_Error::not_found();
		}

		$requested = Query_Validator::requested_context( $query_params['context'] ?? null );
		if ( $requested instanceof \WP_Error ) {
			return $requested;
		}

		try {
			$definition = Records_Definition::from_array( $registration->get_provider()->get_definition( $requested ) );
		} catch ( \Throwable $e ) {
			return $this->fail( [ 'registration' => $registration ], 'definition', $e, 'woodev_records_invalid_definition' );
		}

		$context = Query_Validator::resolve_context( $definition, $requested );
		if ( $context instanceof \WP_Error ) {
			return $context;
		}

		return [
			'registration' => $registration,
			'definition'   => $definition,
			'context'      => $context,
		];
	}

	/**
	 * Step 6: the provider's object check. Anything but a strict `true` is a 404.
	 *
	 * @param array<string,mixed> $call      Result of {@see self::open()}.
	 * @param string              $operation Operation name.
	 * @param string[]            $ids       Targeted row ids.
	 * @return \WP_Error|null Null when allowed.
	 */
	private function authorize( array $call, string $operation, array $ids ): ?\WP_Error {
		try {
			$allowed = $call['registration']->get_provider()->authorize( $operation, $call['context'], $ids );
		} catch ( \Throwable $e ) {
			return $this->fail( $call, $operation, $e );
		}

		return true === $allowed ? null : Records_Error::not_found();
	}

	/**
	 * Step 7: runs the provider read and builds the paged envelope.
	 *
	 * @param array<string,mixed> $call     Result of {@see self::open()}.
	 * @param callable            $read     Receives the provider, returns its raw result.
	 * @param array<string,mixed> $query    Validated query (`page`, `per_page`).
	 * @param bool                $children Whether this is a children page (no facets).
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function read( array $call, callable $read, array $query, bool $children ) {
		try {
			$result = $read( $call['registration']->get_provider() );
		} catch ( \Throwable $e ) {
			return $this->fail( $call, 'read', $e );
		}

		$page = $children
			? Row_Normalizer::children_page( $call['definition'], $result, $query['per_page'] )
			: Row_Normalizer::page( $call['definition'], $result, $query['per_page'] );
		if ( $page instanceof \WP_Error ) {
			return $page;
		}

		return $this->respond(
			[
				'rows'        => $page['rows'],
				'total'       => $page['total'],
				'page'        => $query['page'],
				'per_page'    => $query['per_page'],
				'total_pages' => (int) ceil( $page['total'] / $query['per_page'] ),
				'facets'      => $this->json_map( $page['facets'] ),
				'messages'    => $this->json_map( $page['messages'] ),
			]
		);
	}

	/**
	 * The provider must implement the option contract and the field must declare a source.
	 *
	 * @param array<string,mixed> $call   Result of {@see self::open()}.
	 * @param string              $action Action id from the route.
	 * @param string              $field  Field id from the route.
	 * @return Provides_Field_Options|\WP_Error
	 */
	private function option_source_gate( array $call, string $action, string $field ) {
		$definition = $call['definition'];
		$provider   = $call['registration']->get_provider();
		$descriptor = $definition->get_action( $action );
		$spec       = $definition->get_action_field( $action, $field );

		if ( null === $spec || ! $spec['options_source'] || ! $provider instanceof Provides_Field_Options ) {
			return Records_Error::not_found();
		}

		return '' === $descriptor['capability'] || current_user_can( $descriptor['capability'] ) ? $provider : Records_Error::forbidden();
	}

	/**
	 * @param array<string,mixed> $params Raw query parameters.
	 * @return array{row_id:string,search:string,cursor:string}|\WP_Error
	 */
	private function options_query( array $params ) {
		$row_id = $params['row_id'] ?? '';
		$cursor = $params['cursor'] ?? '';
		$search = $params['search'] ?? '';

		if ( '' !== $row_id && ! Query_Validator::is_row_id( $row_id ) ) {
			return Records_Error::invalid( 'row_id' );
		}
		if ( '' !== $cursor && ! Query_Validator::is_row_id( $cursor ) ) {
			return Records_Error::invalid( 'cursor' );
		}

		$search = Query_Validator::normalize_filter_value( [ 'type' => 'search' ], $search );
		if ( null === $search ) {
			return Records_Error::invalid( 'search' );
		}

		return [
			'row_id' => $row_id,
			'search' => $search,
			'cursor' => $cursor,
		];
	}

	/**
	 * Drops the actions the current user lacks the capability for, and turns empty maps into objects.
	 *
	 * @param array<string,mixed> $definition Normalised definition.
	 * @return array<string,mixed>
	 */
	private function for_user( array $definition ): array {
		foreach ( [ 'row_actions', 'bulk_actions' ] as $key ) {
			$definition[ $key ] = array_values(
				array_filter(
					$definition[ $key ],
					static function ( array $action ): bool {
						return '' === $action['capability'] || current_user_can( $action['capability'] );
					}
				)
			);
		}
		$definition['messages'] = $this->json_map( $definition['messages'] );

		return $definition;
	}

	/**
	 * An empty PHP array would encode as `[]`; the client expects a map.
	 *
	 * @param array<string,mixed> $map Map.
	 * @return array<string,mixed>|\stdClass
	 */
	private function json_map( array $map ) {
		return [] === $map ? new \stdClass() : $map;
	}

	/**
	 * A path parameter. Read from the URL params only: `get_param()` lets a query-string value of
	 * the same name win over the route's.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $name    Route parameter name.
	 * @return string Empty string when absent.
	 */
	private function route_param( \WP_REST_Request $request, string $name ): string {
		$params = $request->get_url_params();

		return is_string( $params[ $name ] ?? null ) ? $params[ $name ] : '';
	}

	/**
	 * Wraps a body in a private, uncacheable response.
	 *
	 * @param array<string,mixed> $data Body.
	 * @return \WP_REST_Response
	 */
	private function respond( array $data ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'private, no-store' );

		return $response;
	}

	/**
	 * Logs a provider failure (message only, secrets masked) and returns a generic 500.
	 *
	 * @param array<string,mixed> $call  Anything holding the `registration`.
	 * @param string              $what  Operation that failed.
	 * @param \Throwable          $e     Failure.
	 * @param string              $code  Error code for the response.
	 * @return \WP_Error
	 */
	private function fail( array $call, string $what, \Throwable $e, string $code = 'woodev_records_provider_error' ): \WP_Error {
		$registration = $call['registration'];

		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for a provider failure.
			sprintf(
				'[woodev] records %s/%s %s failed: %s',
				$registration->get_owner(),
				$registration->get_provider()->get_id(),
				$what,
				\Woodev_API_Base::redact_secret_log_text( $e->getMessage(), [], true )
			)
		);

		return Records_Error::internal( $code );
	}
}
