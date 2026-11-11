<?php
/**
 * REST route that receives a marketplace's notifications.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Intake;

defined( 'ABSPATH' ) || exit;

/**
 * `POST /wp-json/woodev/v1/marketplace/{marketplace id}/notifications/{account_id}/{token}`. The only unauthenticated route of
 * a marketplace plugin: a marketplace signs nothing, so the 32-character token in the path is the credential, and the account
 * must exist and own that token. Everything else is {@see Notification_Intake}.
 *
 * The route lives in the framework's namespace and carries the adapter's id, so two marketplace plugins on one site each get
 * their own address without a namespace of their own, and one framework copy serves both.
 *
 * WordPress core validates the request before it calls a route callback and answers `400 rest_invalid_json` when the request
 * declares JSON and the body does not parse. An unreadable body must be answered the way the decoder decides (a 4xx counts
 * against the URL in a marketplace's health check), so this one route is answered from the `rest_pre_dispatch` filter, which
 * runs before that validation. The registered callback stays for discovery and does the same work.
 *
 * @since 2.1.0
 */
final class Notifications_Controller {
	public const REST_NAMESPACE = 'woodev/v1';

	private const ACCOUNT_AND_TOKEN = '/(?P<account_id>\d+)/(?P<token>[A-Za-z0-9]{32})';

	/**
	 * The adapter's id.
	 *
	 * @var string
	 */
	private $marketplace_id;

	/**
	 * Intake.
	 *
	 * @var Notification_Intake
	 */
	private $intake;

	/**
	 * Creates the controller.
	 *
	 * @since 2.1.0
	 *
	 * @param string              $marketplace_id The adapter's id.
	 * @param Notification_Intake $intake         Intake.
	 */
	public function __construct( string $marketplace_id, Notification_Intake $intake ) {
		$this->marketplace_id = $marketplace_id;
		$this->intake         = $intake;
	}

	/**
	 * Returns the part of the route every account of a marketplace shares: `/marketplace/{marketplace id}/notifications`.
	 *
	 * @since 2.1.0
	 *
	 * @param string $marketplace_id The adapter's id.
	 * @return string
	 */
	public static function base( string $marketplace_id ): string {
		return '/marketplace/' . $marketplace_id . '/notifications';
	}

	/**
	 * Hooks the route registration.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_filter( 'rest_pre_dispatch', [ $this, 'pre_dispatch' ], 10, 3 );
	}

	/**
	 * Answers a notification before core validates the request body as JSON.
	 *
	 * Only a POST to exactly this route is taken; every other request, and any request another filter already answered, goes on
	 * untouched.
	 *
	 * @since 2.1.0
	 *
	 * @param mixed            $result  Answer of an earlier filter, null for none.
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed A response for our route, `$result` otherwise.
	 */
	public function pre_dispatch( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof \WP_REST_Request || 'POST' !== $request->get_method() ) {
			return $result;
		}

		$pattern = '#^/' . self::REST_NAMESPACE . preg_quote( self::base( $this->marketplace_id ), '#' ) . self::ACCOUNT_AND_TOKEN . '$#';

		if ( 1 !== preg_match( $pattern, $request->get_route(), $matches ) ) {
			return $result;
		}

		return $this->respond( (int) $matches['account_id'], $matches['token'], $request->get_body() );
	}

	/**
	 * Registers the route.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::base( $this->marketplace_id ) . self::ACCOUNT_AND_TOKEN,
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle' ],
				// Public by design: a marketplace cannot log in. The token in the path is checked against the account in the intake.
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Handles one notification.
	 *
	 * @since 2.1.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->respond( (int) $request->get_param( 'account_id' ), (string) $request->get_param( 'token' ), $request->get_body() );
	}

	/**
	 * Hands a notification to the intake and wraps its answer.
	 *
	 * @param int    $account_id Account id from the URL.
	 * @param string $token      Token from the URL.
	 * @param string $raw_body   Raw request body.
	 * @return \WP_REST_Response
	 */
	private function respond( int $account_id, string $token, string $raw_body ): \WP_REST_Response {
		return self::to_rest_response(
			$this->intake->handle_token(
				$account_id,
				$token,
				$raw_body,
				$_SERVER // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The protocol reads only the peer and forwarded addresses and validates each as an IP.
			)
		);
	}

	/**
	 * Converts the intake's answer into a REST response.
	 *
	 * @param Intake_Response $answer Answer.
	 * @return \WP_REST_Response
	 */
	private static function to_rest_response( Intake_Response $answer ): \WP_REST_Response {
		$response = new \WP_REST_Response( $answer->body(), $answer->status() );

		foreach ( $answer->headers() as $name => $value ) {
			$response->header( $name, $value );
		}

		return $response;
	}
}
