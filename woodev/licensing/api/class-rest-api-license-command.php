<?php
/**
 * REST controller for the signed license-command endpoint (woodev/v1/license-command).
 *
 * This is a deliberately thin transport layer — all security and validation logic
 * lives in Woodev_License_Command_Dispatcher::handle_raw_body(). The controller:
 *
 *  1. Registers itself once through Woodev_REST_V1_Registrar (election §9.3 — the
 *     registrar dedupes by class and owns the rest_api_init hook).
 *  2. Exposes ONE route: POST woodev/v1/license-command.
 *  3. Uses permission_callback '__return_true' — LOCKED operator decision (§9.3):
 *     authentication IS the Ed25519 signature; there is no WP user in this flow.
 *     Carrying no WP authentication at all makes the endpoint indistinguishable from
 *     any other unauthenticated webhook — an attacker cannot learn which plugins are
 *     installed from the auth tier alone.
 *  4. Passes the raw body verbatim to the dispatcher and maps the returned
 *     {status, reason?, http} to a WP_REST_Response per the frozen §9.8 HTTP map.
 *  5. Has NO try/catch swallowing: a dispatcher Throwable is a bug and must surface.
 *  6. Registers NO args schema: WP Core's args layer would emit distinguishable
 *     field-level validation errors — letting an attacker enumerate schema mismatches
 *     before the signature check. The dispatcher runs ALL validation internally and
 *     returns indistinguishable rejection shapes (§9.4).
 *
 * Response body shapes (exact — frozen wire contract, webhooks-spec §3.1):
 *  - 2xx:     { "status": "executed" } or { "status": "already" } — the ONLY 2xx bodies.
 *  - non-2xx: { "status": "rejected", "reason": "<code>" } — uniform for EVERY
 *             failure, including the dispatcher-internal terminal statuses
 *             unsupported_command (400), network_active_unsupported (409) and
 *             failed (500). Terminal-vs-retryable is a dispatcher/ack concern,
 *             never a wire-shape concern.
 *
 * @package Woodev\Framework\Licensing\API
 * @since 2.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_REST_API_License_Command' ) ) :

	/**
	 * REST controller for the public license-command webhook endpoint.
	 *
	 * Thin transport over Woodev_License_Command_Dispatcher (s8-p2). Registered
	 * on core rest_api_init through Woodev_REST_V1_Registrar (NOT through the
	 * WooCommerce-gated Woodev_REST_API), making this endpoint WooCommerce-agnostic.
	 *
	 * Not final: the unit test subclasses it to inject a controlled dispatcher result
	 * via the dispatch() seam so the HTTP-map coverage is table-driven without a full
	 * dispatcher setup (same subclass-for-seam pattern as Probe_Command_Dispatcher in
	 * LicenseCommandDispatcherTest).
	 *
	 * @since 2.0.0
	 */
	class Woodev_REST_API_License_Command {

		/**
		 * Whether boot() has already registered the controller (idempotency guard).
		 *
		 * @since 2.0.0
		 *
		 * @var bool
		 */
		private static $booted = false;

		/**
		 * Registers a single controller instance through the woodev/v1 registrar.
		 *
		 * Idempotent: a second call is a no-op. Called from
		 * Woodev_Plugins_License::add_hooks().
		 *
		 * @since 2.0.0
		 *
		 * @return void
		 */
		public static function boot(): void {

			if ( self::$booted ) {
				return;
			}

			self::$booted = true;

			Woodev_REST_V1_Registrar::register_controller( new self() );
		}

		/**
		 * Registers the license-command route under the woodev/v1 namespace.
		 *
		 * @internal
		 *
		 * @since 2.0.0
		 *
		 * @return void
		 */
		public function register_routes(): void {

			register_rest_route(
				Woodev_REST_V1_Registrar::ROUTE_NAMESPACE,
				'/license-command',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_command' ),
					// LOCKED operator decision (§9.3): auth IS the Ed25519 signature.
					// There is no WP user in the server→client signed-command flow.
					// '__return_true' is the standard WP idiom for a fully public route.
					'permission_callback' => '__return_true',
					// NO 'args' key: core's args layer would emit distinguishable field
					// errors BEFORE the signature check, leaking schema information to
					// unauthenticated callers (§9.4 indistinguishable-rejection rule).
					// The dispatcher validates everything internally.
				)
			);
		}

		/**
		 * Handles a POST woodev/v1/license-command request.
		 *
		 * Passes the raw body to the dispatcher and maps its result to a
		 * WP_REST_Response per the frozen §9.8 HTTP map. The body shapes are:
		 *  - 2xx:     { status }          — 'executed' or 'already' ONLY.
		 *  - non-2xx: { status, reason }  — always status='rejected' + reason code,
		 *             uniform for every failure (reason falls back to the
		 *             dispatcher's internal status when no reason key is present).
		 *
		 * No try/catch here: a dispatcher Throwable is a programming bug and must
		 * surface (the task spec says "let it surface in tests", acceptance item 3).
		 *
		 * @internal
		 *
		 * @since 2.0.0
		 *
		 * @param WP_REST_Request $request The REST request.
		 *
		 * @return WP_REST_Response
		 */
		public function handle_command( \WP_REST_Request $request ): \WP_REST_Response {

			$result = $this->dispatch( (string) $request->get_body() );

			$status = (string) ( $result['status'] ?? 'failed' );
			$http   = (int) ( $result['http'] ?? 500 );

			// Frozen wire contract (webhooks-spec §3.1 + plan §9.4): the ONLY 2xx
			// bodies are {status:'executed'} and {status:'already'}; EVERY non-2xx
			// body is exactly {status:'rejected', reason:'<code>'} — including the
			// dispatcher-internal terminal statuses unsupported_command (400),
			// network_active_unsupported (409) and failed (500). The terminal-vs-
			// retryable distinction lives in the dispatcher result + the pull-path
			// ack schema, NEVER in the HTTP body shape.
			if ( $http >= 300 ) {
				$reason = (string) ( $result['reason'] ?? $status );

				// Fail closed: only the frozen §9.8 vocabulary may reach the public
				// wire. A registered command handler can return ANY string as its ack
				// status (the dispatcher passes it through verbatim and maps unknown
				// statuses to 500), and an internal token must never leak to an
				// unauthenticated caller. The dispatcher's HTTP_MAP keys ARE the
				// frozen outcome vocabulary (single source, pinned by the s8-p6
				// parity test) — anything outside it is emitted as the generic
				// retryable 'failed'.
				if ( ! array_key_exists( $reason, Woodev_License_Command_Dispatcher::HTTP_MAP ) ) {
					$reason = 'failed';
				}

				$body = array(
					'status' => 'rejected',
					'reason' => $reason,
				);
			} else {
				$body = array(
					'status' => $status,
				);
			}

			return new \WP_REST_Response( $body, $http );
		}

		/**
		 * Delegates the raw body to the dispatcher and returns its result array.
		 *
		 * Protected seam: unit tests subclass the controller and override this method
		 * to inject a controlled result without a full dispatcher setup (same
		 * subclass-for-seam pattern as Probe_Command_Dispatcher). Production code
		 * always calls Woodev_License_Command_Dispatcher::handle_raw_body() verbatim.
		 *
		 * @since 2.0.0
		 *
		 * @param string $body The raw request body.
		 * @return array{status: string, reason?: string, http: int}
		 */
		protected function dispatch( string $body ): array {
			return Woodev_License_Command_Dispatcher::handle_raw_body( $body );
		}
	}

endif;
