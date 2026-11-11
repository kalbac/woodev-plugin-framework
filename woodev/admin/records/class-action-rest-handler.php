<?php
/**
 * Records engine — REST routes of the table actions.
 *
 * @package Woodev\Framework\Admin\Records
 */

namespace Woodev\Framework\Admin\Records;

defined( 'ABSPATH' ) || exit;

/**
 * The two write routes of the records engine under `woodev/v1/admin/records/{owner}/{table}`:
 *
 * - `POST R/actions/{action}/preview` — a read-only plan: counts, grouped reasons, the scope
 *   sentence. It changes nothing, queues nothing and calls no remote service.
 * - `POST R/actions/{action}` — the apply. It REPLANS from current state with the provider's own
 *   planner; there is no preview receipt to forge or to go stale. Changed eligibility comes back
 *   as grouped skips with the real counts.
 *
 * Every request runs the order of {@see Records_Rest_Controller}, so nothing reaches a provider
 * before it is validated:
 *
 * 1. the body's shape, keys and explicit confirmation ({@see Action_Request}) → 400;
 * 2. unknown table → 404; missing table capability → 403 (permission callback);
 * 3. the provider's definition for this user; the account must be named (a write never falls
 *    back to a default account) and must be among the options the provider offered → 404;
 * 4. the action must be declared and executable here → 404; its capability → 403;
 * 5. ids for the action's scope → 400, oversized → 400; payload fields → 400 / 422
 *    ({@see Action_Input});
 * 6. the provider's object check ({@see Records_Provider::authorize()}, operation = action id)
 *    → 404;
 * 7. only then the provider plans or applies; its answer is rebuilt by {@see Action_Response}.
 *
 * Kept apart from {@see Records_Rest_Controller}, which serves the reads; it is handed to the
 * REST registrar by {@see Records_Registry} next to it.
 *
 * @since 2.0.30
 */
final class Action_Rest_Handler {

	/** An action id as it appears in a path. */
	const ACTION_PATTERN = '[a-z0-9][a-z0-9_-]{0,63}';

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
	 * Registers the preview and apply routes.
	 *
	 * @internal
	 *
	 * @since 2.0.30
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$namespace = \Woodev_REST_V1_Registrar::ROUTE_NAMESPACE;
		$base      = Records_Rest_Controller::ROUTE_BASE . '/actions/(?P<action>' . self::ACTION_PATTERN . ')';
		$routes    = [
			$base . '/preview' => 'preview',
			$base              => 'apply',
		];

		foreach ( $routes as $pattern => $callback ) {
			register_rest_route(
				$namespace,
				$pattern,
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, $callback ],
					'permission_callback' => [ $this, 'check_permission' ],
				]
			);
		}
	}

	/**
	 * Gate: the table must exist and the user must hold its capability.
	 *
	 * @since 2.0.30
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
	 * `POST R/actions/{action}/preview`.
	 *
	 * @since 2.0.30
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function preview( \WP_REST_Request $request ) {
		$call = $this->prepare( $request, false );
		if ( $call instanceof \WP_Error ) {
			return $call;
		}

		$result = $this->run(
			$call,
			static function ( Records_Provider $provider ) use ( $call ): array {
				return $provider->preview( $call['context'], $call['action']['id'], $call['ids'], $call['payload'] );
			}
		);
		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		$body = Action_Response::preview( $call['action'], $result );

		return $body instanceof \WP_Error ? $body : $this->respond( $body, 200 );
	}

	/**
	 * `POST R/actions/{action}`.
	 *
	 * @since 2.0.30
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function apply( \WP_REST_Request $request ) {
		$call = $this->prepare( $request, true );
		if ( $call instanceof \WP_Error ) {
			return $call;
		}

		$result = $this->run(
			$call,
			static function ( Records_Provider $provider ) use ( $call ): array {
				return $provider->apply( $call['context'], $call['action']['id'], $call['ids'], $call['payload'] );
			}
		);
		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		$body = Action_Response::apply( $call['definition'], $call['action'], $result );
		if ( $body instanceof \WP_Error ) {
			return $body;
		}

		return $this->respond( $body, Action_Response::STATUS_QUEUED === $body['status'] ? 202 : 200 );
	}

	/**
	 * Steps 1 to 6: everything up to, and including, the provider's object check.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param bool             $apply   Whether this is the apply route.
	 * @return array{registration:Records_Registration,definition:Records_Definition,context:array{account_id:string},action:array<string,mixed>,ids:string[],payload:array<string,mixed>}|\WP_Error
	 */
	private function prepare( \WP_REST_Request $request, bool $apply ) {
		$body = Action_Request::body( $request, $apply );
		if ( $body instanceof \WP_Error ) {
			return $body;
		}

		$call = $this->open( $request, $body['context'] );
		if ( $call instanceof \WP_Error ) {
			return $call;
		}

		$action = $this->declared_action( $call['definition'], $this->route_param( $request, 'action' ) );
		if ( $action instanceof \WP_Error ) {
			return $action;
		}

		$ids = Action_Request::targets( $call['definition'], $action, $body['ids'] );
		if ( $ids instanceof \WP_Error ) {
			return $ids;
		}

		$payload = Action_Input::validate( $action, $body['payload'] );
		if ( $payload instanceof \WP_Error ) {
			return $payload;
		}

		$call += [
			'action'  => $action,
			'ids'     => $ids,
			'payload' => $payload,
		];

		$denied = $this->authorize( $call );

		return null === $denied ? $call : $denied;
	}

	/**
	 * Table, definition and account. A write must name its account when the table has a selector.
	 *
	 * @param \WP_REST_Request         $request   Request.
	 * @param array{account_id:string} $requested Account the body asked for.
	 * @return array{registration:Records_Registration,definition:Records_Definition,context:array{account_id:string}}|\WP_Error
	 */
	private function open( \WP_REST_Request $request, array $requested ) {
		$registration = $this->registry->get( $this->route_param( $request, 'owner' ), $this->route_param( $request, 'table' ) );
		if ( null === $registration ) {
			return Records_Error::not_found();
		}

		try {
			$definition = Records_Definition::from_array( $registration->get_provider()->get_definition( $requested ) );
		} catch ( \Throwable $e ) {
			return $this->fail( [ 'registration' => $registration ], 'definition', $e, 'woodev_records_invalid_definition' );
		}

		if ( null !== $definition->get_context() && '' === $requested[ Records_Definition::CONTEXT_ACCOUNT ] ) {
			return Records_Error::invalid( 'context.account_id' );
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
	 * Step 4: the action must be declared, run through REST, and be one the user may use.
	 *
	 * A `link` action is plain navigation and has no route. An action the user lacks the
	 * capability for answers 403, like the options route; the definition already left it out of
	 * what this user sees.
	 *
	 * @param Records_Definition $definition Definition built for the current user.
	 * @param string             $id         Action id from the route.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function declared_action( Records_Definition $definition, string $id ) {
		$action = $definition->get_action( $id );

		if ( null === $action || 'link' === $action['interaction'] ) {
			return Records_Error::not_found();
		}

		return '' === $action['capability'] || current_user_can( $action['capability'] ) ? $action : Records_Error::forbidden();
	}

	/**
	 * Step 6: the provider's object check, with the action id as the operation.
	 *
	 * @param array<string,mixed> $call Everything {@see self::prepare()} has so far.
	 * @return \WP_Error|null Null when allowed.
	 */
	private function authorize( array $call ): ?\WP_Error {
		try {
			$allowed = $call['registration']->get_provider()->authorize( $call['action']['id'], $call['context'], $call['ids'] );
		} catch ( \Throwable $e ) {
			return $this->fail( $call, 'authorize', $e );
		}

		return true === $allowed ? null : Records_Error::not_found();
	}

	/**
	 * Step 7: runs the provider and turns its refusals into 422 and 409.
	 *
	 * @param array<string,mixed> $call Result of {@see self::prepare()}.
	 * @param callable            $work Receives the provider, returns its raw result.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function run( array $call, callable $work ) {
		try {
			return $work( $call['registration']->get_provider() );
		} catch ( Action_Input_Exception $e ) {
			return Action_Error::from_provider( $e->get_errors(), $call['action']['fields'] );
		} catch ( Action_State_Exception $e ) {
			return Action_Error::conflict( $e->getMessage() );
		} catch ( \Throwable $e ) {
			return $this->fail( $call, $call['action']['id'], $e );
		}
	}

	/**
	 * A path parameter, from the URL params only (see {@see Records_Rest_Controller}).
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
	 * @param array<string,mixed> $data   Body.
	 * @param int                 $status HTTP status.
	 * @return \WP_REST_Response
	 */
	private function respond( array $data, int $status ): \WP_REST_Response {
		// An empty PHP array would encode as `[]`; the client expects a map.
		$data['messages'] = [] === $data['messages'] ? new \stdClass() : $data['messages'];

		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'private, no-store' );

		return $response;
	}

	/**
	 * Logs a provider failure (message only, secrets masked) and returns a generic 500.
	 *
	 * @param array<string,mixed> $call Anything holding the `registration`.
	 * @param string              $what Operation that failed.
	 * @param \Throwable          $e    Failure.
	 * @param string              $code Error code for the response.
	 * @return \WP_Error
	 */
	private function fail( array $call, string $what, \Throwable $e, string $code = 'woodev_records_provider_error' ): \WP_Error {
		$registration = $call['registration'];

		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for a provider failure.
			sprintf(
				'[woodev] records %s/%s action %s failed: %s',
				$registration->get_owner(),
				$registration->get_provider()->get_id(),
				$what,
				\Woodev_API_Base::redact_secret_log_text( $e->getMessage(), [], true )
			)
		);

		return Records_Error::internal( $code );
	}
}
