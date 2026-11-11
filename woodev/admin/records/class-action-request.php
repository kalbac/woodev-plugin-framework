<?php
/**
 * Records engine — the body of a preview or apply request.
 *
 * @package Woodev\Framework\Admin\Records
 */

namespace Woodev\Framework\Admin\Records;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and checks the shape of what a client POSTs to an action route, before any provider code
 * runs: which keys may appear, whether the confirmation is explicit, and which row ids the
 * action's scope allows.
 *
 * - Preview takes `context`, `ids`, `payload`. Apply takes the same plus `confirmed`, which must
 *   be exactly `true` — a missing or truthy-looking value is a malformed request, never a yes.
 * - An action scoped to the selection needs a flat, distinct, bounded id list (a row action
 *   exactly one id). An account-wide action takes no ids at all: its reach is the account, and
 *   the preview says so.
 *
 * @since 2.1.0
 */
final class Action_Request {

	/** Body keys of a preview request. */
	const PREVIEW_KEYS = [ 'context', 'ids', 'payload' ];

	/** Body keys of an apply request. */
	const APPLY_KEYS = [ 'context', 'ids', 'payload', 'confirmed' ];

	/**
	 * Parses the JSON body.
	 *
	 * @since 2.1.0
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param bool             $apply   Whether this is the apply route (adds `confirmed`).
	 * @return array{context:array{account_id:string},ids:mixed,payload:mixed}|\WP_Error `ids` and `payload` are still raw.
	 */
	public static function body( \WP_REST_Request $request, bool $apply ) {
		$unknown = Query_Validator::reject_unknown( $request->get_query_params(), [] );
		if ( null !== $unknown ) {
			return $unknown;
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return Records_Error::invalid( 'body' );
		}

		foreach ( array_keys( $body ) as $key ) {
			if ( ! in_array( (string) $key, $apply ? self::APPLY_KEYS : self::PREVIEW_KEYS, true ) ) {
				return Records_Error::invalid( (string) $key );
			}
		}

		if ( $apply && true !== ( $body['confirmed'] ?? null ) ) {
			return Records_Error::invalid( 'confirmed' );
		}

		$context = Query_Validator::requested_context( $body['context'] ?? null );
		if ( $context instanceof \WP_Error ) {
			return $context;
		}

		return [
			'context' => $context,
			'ids'     => $body['ids'] ?? [],
			'payload' => $body['payload'] ?? [],
		];
	}

	/**
	 * Checks the id list against the action's scope.
	 *
	 * @since 2.1.0
	 *
	 * @param Records_Definition  $definition Definition built for the current user.
	 * @param array<string,mixed> $action     Normalised action descriptor.
	 * @param mixed               $ids        Raw `ids`.
	 * @return string[]|\WP_Error Empty list for an account-wide action.
	 */
	public static function targets( Records_Definition $definition, array $action, $ids ) {
		if ( 'account' === $action['scope'] ) {
			return [] === $ids ? [] : Records_Error::invalid( 'ids' );
		}

		$ids = Query_Validator::row_ids( $definition, $ids );
		if ( $ids instanceof \WP_Error ) {
			return $ids;
		}

		$is_row_action = in_array( $action['id'], $definition->get_row_action_ids(), true );

		return $is_row_action && 1 !== count( $ids ) ? Records_Error::invalid( 'ids' ) : $ids;
	}
}
