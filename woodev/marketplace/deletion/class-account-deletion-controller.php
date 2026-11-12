<?php
/**
 * REST route of the account deletion.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Deletion;

use Woodev\Framework\Admin\Records\Records_Error;

defined( 'ABSPATH' ) || exit;

/**
 * `woodev/v1/admin/marketplace/{plugin id}/accounts/{account id}/deletion`:
 *
 * | Route | Does |
 * |---|---|
 * | `GET` | the state: whether the account may be deleted, the preview (counts, what stays, warnings) and the progress; changes nothing |
 * | `POST` `{confirm}` | the confirmed start: `confirm` is the account's identifier as the merchant typed it; answers 202 with the same state |
 *
 * Every request runs the capability first (the WordPress REST cookie nonce is checked by core before that), then the body against
 * its declared keys, then {@see Account_Deletion}, whose refusals become the statuses the service names.
 *
 * @since 2.1.5
 */
final class Account_Deletion_Controller {
	/**
	 * Plugin id, the owner in the route.
	 *
	 * @var string
	 */
	private $owner;

	/**
	 * Capability the user needs.
	 *
	 * @var string
	 */
	private $capability;

	/**
	 * The rules.
	 *
	 * @var Account_Deletion
	 */
	private $deletion;

	/**
	 * Creates the controller.
	 *
	 * @since 2.1.5
	 *
	 * @param string           $owner      Plugin id.
	 * @param string           $capability Capability the user needs.
	 * @param Account_Deletion $deletion   The rules.
	 */
	public function __construct( string $owner, string $capability, Account_Deletion $deletion ) {
		$this->owner      = $owner;
		$this->capability = $capability;
		$this->deletion   = $deletion;
	}

	/**
	 * The path of an account's deletion route below the REST namespace, for the screen.
	 *
	 * @since 2.1.5
	 *
	 * @param string $owner      Plugin id.
	 * @param int    $account_id Account id.
	 * @return string
	 */
	public static function path( string $owner, int $account_id ): string {
		return '/admin/marketplace/' . $owner . '/accounts/' . $account_id . '/deletion';
	}

	/**
	 * Registers the route.
	 *
	 * @since 2.1.5
	 *
	 * @internal
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			\Woodev_REST_V1_Registrar::ROUTE_NAMESPACE,
			'/admin/marketplace/' . $this->owner . '/accounts/(?P<account_id>[1-9][0-9]{0,18})/deletion',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_state' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'start' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
			]
		);
	}

	/**
	 * Gate for every request.
	 *
	 * @since 2.1.5
	 *
	 * @return true|\WP_Error
	 */
	public function check_permission() {
		return current_user_can( $this->capability ) ? true : Records_Error::forbidden();
	}

	/**
	 * `GET`: the state.
	 *
	 * @since 2.1.5
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_state( \WP_REST_Request $request ) {
		try {
			return new \WP_REST_Response( $this->deletion->state( (int) $request->get_param( 'account_id' ) ) );
		} catch ( Account_Deletion_Refused $refusal ) {
			return $this->error( $refusal );
		}
	}

	/**
	 * `POST`: the confirmed start.
	 *
	 * @since 2.1.5
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function start( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : [];

		if ( [ 'confirm' ] !== array_keys( $body ) || ! is_string( $body['confirm'] ) ) {
			return new \WP_Error( 'woodev_account_deletion_bad_body', __( 'Введите идентификатор аккаунта, чтобы подтвердить удаление.', 'woodev-plugin-framework' ), [ 'status' => 400 ] );
		}

		try {
			return new \WP_REST_Response( $this->deletion->start( (int) $request->get_param( 'account_id' ), $body['confirm'] ), 202 );
		} catch ( Account_Deletion_Refused $refusal ) {
			return $this->error( $refusal );
		}
	}

	/**
	 * A REST error from a refusal.
	 *
	 * @param Account_Deletion_Refused $refusal Refusal.
	 * @return \WP_Error
	 */
	private function error( Account_Deletion_Refused $refusal ): \WP_Error {
		return new \WP_Error( 'woodev_account_deletion_refused', $refusal->getMessage(), [ 'status' => $refusal->status() ] );
	}
}
