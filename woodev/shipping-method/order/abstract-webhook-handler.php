<?php
/**
 * Woodev Abstract Webhook Handler
 *
 * Base class for receiving an INBOUND webhook from a shipping carrier (e.g. a
 * carrier→order status-sync callback) and dispatching its verified payload to
 * subscribers. It exposes a single carrier seam — signature verification
 * ({@see self::verify_signature()}) — so the framework can authenticate a raw
 * request body before any subscriber sees it, and a payload-parsing seam
 * ({@see self::parse_payload()}) so the carrier's wire shape never leaks past
 * this class. The verified payload is broadcast through a forward-only,
 * plugin-namespaced action hook (`woodev_shipping_{prefix}_webhook_received`);
 * the framework itself mutates no order.
 *
 * The inbound URL is a WP REST route the plugin names
 * ({@see self::get_namespace()} / {@see self::get_route()}); both are forward
 * contracts supplied by the plugin. No installed-site contract string is
 * introduced here — no shipping-method id, no existing REST namespace, no
 * existing hook name. In particular this base does NOT use the legacy
 * `woocommerce_api_*` callback (an installed-site order contract); a new inbound
 * carrier should expose a fresh REST route instead.
 *
 * SCAFFOLDING ONLY (spec §4.3, decision §6d). Yandex has NO inbound webhook —
 * it is outbound-only (yandex checklist §Operational Surface) — so this base is
 * deliberately NOT exercised by the yandex fixture. Real end-to-end validation
 * happens later, when edostavka is rewritten onto the framework. Kept minimal on
 * purpose; carrier-specific behaviour belongs in the concrete subclass.
 *
 * See docs-internal/platform-v2-s1-shipping-spec.md §4.3.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping\Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Order\\Abstract_Webhook_Handler' ) ) :

	/**
	 * Receives, authenticates, and dispatches an inbound carrier webhook.
	 *
	 * The authentication boundary is {@see self::verify_request()}, wired as the
	 * REST route's `permission_callback`: an unsigned or forged request never
	 * reaches {@see self::handle_request()}, because WordPress runs the callback
	 * only after the permission check passes. Concrete carriers supply the actual
	 * signature check ({@see self::verify_signature()}) and the payload mapping
	 * ({@see self::parse_payload()}).
	 *
	 * @since 1.5.0
	 */
	abstract class Abstract_Webhook_Handler {

		/** @var string plugin-supplied token that namespaces this handler's forward hooks */
		protected string $hook_prefix;

		/**
		 * Constructor.
		 *
		 * @since 1.5.0
		 *
		 * @param string $hook_prefix plugin-supplied token (e.g. the plugin id) that namespaces forward hooks; defaults to none
		 */
		public function __construct( string $hook_prefix = '' ) {
			$this->hook_prefix = $hook_prefix;
		}

		/**
		 * Registers the inbound webhook REST route.
		 *
		 * Call once from the plugin during bootstrap. The route's
		 * `permission_callback` is the signature-verification seam, so an
		 * unauthenticated request is rejected by WordPress before the handler runs.
		 *
		 * @since 1.5.0
		 *
		 * @return void
		 */
		public function register(): void {
			add_action( 'rest_api_init', array( $this, 'register_route' ) );
		}

		/**
		 * Registers the REST route on `rest_api_init`.
		 *
		 * The namespace and route are forward contracts supplied by the plugin; the
		 * framework hardcodes neither.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @return void
		 */
		public function register_route(): void {

			register_rest_route(
				$this->get_namespace(),
				$this->get_route(),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_request' ),
					'permission_callback' => array( $this, 'verify_request' ),
				)
			);
		}

		/**
		 * Permission callback: authenticates the raw request via the carrier seam.
		 *
		 * Hands the unparsed body and headers to {@see self::verify_signature()}.
		 * Returning false makes WordPress answer 401 and never invoke
		 * {@see self::handle_request()} — this is where an unsigned or forged
		 * payload is rejected.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @param \WP_REST_Request $request the inbound request
		 * @return bool true when the signature is valid, false otherwise
		 */
		public function verify_request( \WP_REST_Request $request ): bool {
			return $this->verify_signature( $request->get_body(), $request->get_headers() );
		}

		/**
		 * Route callback: parses the verified payload and broadcasts it.
		 *
		 * Only reached for requests that already passed {@see self::verify_request()}.
		 * Subscribers act on the typed payload (e.g. sync the order status); the
		 * framework changes no order itself.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @param \WP_REST_Request $request the verified inbound request
		 * @return \WP_REST_Response an acknowledgement response
		 */
		public function handle_request( \WP_REST_Request $request ): \WP_REST_Response {

			$payload = $this->parse_payload( $request->get_body() );

			/**
			 * Fires when a verified inbound carrier webhook is received.
			 *
			 * @since 1.5.0
			 *
			 * @param array            $payload the carrier-neutral payload parsed from the request body
			 * @param \WP_REST_Request $request the verified inbound request
			 */
			do_action( $this->hook( 'webhook_received' ), $payload, $request );

			return new \WP_REST_Response( array( 'received' => true ), 200 );
		}

		/**
		 * Verifies the authenticity of a raw inbound request.
		 *
		 * Each carrier signs its webhooks differently (HMAC header, shared secret,
		 * IP allow-list, …), so the concrete handler implements the check against
		 * the raw, unparsed body — parsing before verifying would trust unverified
		 * input. Must return false for an unsigned or forged request.
		 *
		 * @since 1.5.0
		 *
		 * @param string               $raw_body the unparsed request body
		 * @param array<string, mixed> $headers  the request headers
		 * @return bool true when the request is authentic, false otherwise
		 */
		abstract protected function verify_signature( string $raw_body, array $headers ): bool;

		/**
		 * Parses a verified raw request body into a carrier-neutral payload.
		 *
		 * Called only after {@see self::verify_signature()} has authenticated the
		 * request. The concrete handler maps the carrier's wire shape into the array
		 * subscribers consume.
		 *
		 * @since 1.5.0
		 *
		 * @param string $raw_body the verified, unparsed request body
		 * @return array the carrier-neutral payload
		 */
		abstract protected function parse_payload( string $raw_body ): array;

		/**
		 * Returns the REST namespace the inbound route is registered under.
		 *
		 * A forward contract supplied by the plugin (e.g. the plugin's dasherized
		 * id); the framework hardcodes none.
		 *
		 * @since 1.5.0
		 *
		 * @return string the REST namespace
		 */
		abstract protected function get_namespace(): string;

		/**
		 * Returns the REST route the inbound webhook is registered at.
		 *
		 * A forward contract supplied by the plugin; the framework hardcodes none.
		 *
		 * @since 1.5.0
		 *
		 * @return string the REST route, e.g. `/webhook`
		 */
		abstract protected function get_route(): string;

		/**
		 * Builds a namespaced forward-hook name.
		 *
		 * @since 1.5.0
		 *
		 * @param string $name bare hook suffix
		 * @return string the full hook name, e.g. `woodev_shipping_{prefix}_{name}`
		 */
		protected function hook( string $name ): string {

			$prefix = '' !== $this->hook_prefix ? $this->hook_prefix . '_' : '';

			return 'woodev_shipping_' . $prefix . $name;
		}
	}

endif;
