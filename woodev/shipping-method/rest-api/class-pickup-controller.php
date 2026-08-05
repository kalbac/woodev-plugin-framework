<?php
/**
 * Woodev Pickup Points REST Controller
 *
 * Serves normalized pickup points for the checkout pickup-point picker (SP-5 "pickup
 * points + map" plan §7). It exposes two read-only `woodev/v1` routes: a collection
 * route for a locality or a viewport bounding box, and a single-item detail route for
 * the viewport-strategy carriers whose list response is sparse. The framework owns this
 * REST surface, the {@see \Woodev\Framework\Shipping\Pickup\Pickup_Point} shape and the
 * selectable verdict; the plugin owns only the carrier {@see Point_Source} it is
 * constructed with.
 *
 * SECURITY: this is a PUBLIC guest-checkout endpoint, mirroring
 * {@see \Woodev\Framework\Shipping\Rest_Api\Field_Source_Controller} — a customer
 * checking out is usually not logged in, and only already-normalized points are ever
 * returned, never a carrier credential. Every request parameter is capped and sanitized
 * BEFORE it reaches {@see Point_Query::from_request()}, and a best-effort per-IP rate
 * limit ({@see Rest_Rate_Limit_Trait}, shared with `Field_Source_Controller`) raises the
 * bar against abuse. The route is intentionally public because normalized pickup-point
 * data is not sensitive; a future SENSITIVE source must add its own authorization.
 *
 * STRATEGY GUARANTEE: {@see Point_Source} documents a contract the framework owes its
 * implementers — a source declaring `STRATEGY_BULK` is always handed a query with a
 * non-null locality, a source declaring `STRATEGY_VIEWPORT` is always handed a query
 * with non-null bounds. This controller is what makes that promise true: a query that
 * does not match the source's declared strategy never reaches {@see Point_Source::fetch_points()};
 * it yields the same empty-points shape as a genuinely empty locality instead. A source
 * declaring neither constant (a typo, or a future strategy this framework version does
 * not know about) matches nothing and also fails closed to the empty shape — see
 * {@see self::query_matches_strategy()}.
 *
 * CARRIER FAILURE vs. EMPTY RESULT: {@see Point_Source} documents that a carrier
 * transport, auth or API failure surfaces as `\Woodev_API_Exception` (a malformed single
 * entry is skipped by the source, not thrown). `get_points_data()` / `get_point_data()`
 * let that exception propagate uncaught — they stay pure, WC-free dispatch, matching
 * {@see Field_Source_Controller::get_field_source()}'s shape — and it is the REST
 * callbacks that catch it and translate it into a `502 WP_Error` with a customer-safe
 * Russian message, never the carrier's own message (see {@see self::log_carrier_failure()}
 * for where that goes instead).
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Rest_Api;

use Woodev\Framework\Shipping\Pickup\Constraint_Checker;
use Woodev\Framework\Shipping\Pickup\Pickup_Point;
use Woodev\Framework\Shipping\Pickup\Point_Query;
use Woodev\Framework\Shipping\Pickup\Point_Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Rest_Api\\Pickup_Controller' ) ) :

	/**
	 * Pickup-points dispatch controller.
	 *
	 * Constructed with the owning plugin id, the carrier {@see Point_Source}, and two
	 * callables the plugin supplies for the current request: the cart weight in grams
	 * and the chosen payment method id. Both callables feed
	 * {@see Constraint_Checker::check()}, which this controller constructs internally —
	 * plugins customise the verdict only via its `woodev_shipping_pickup_point_selectable`
	 * filter, never a constructor argument.
	 *
	 * @since 2.0.2
	 */
	class Pickup_Controller extends \WP_REST_Controller {

		use Rest_Rate_Limit_Trait;

		/**
		 * Maximum accepted length (chars) for the free-text `q` / `locality` / `bbox` /
		 * `id` params.
		 *
		 * @since 2.0.2
		 *
		 * @var int
		 */
		protected const MAX_PARAM_LENGTH = 128;

		/**
		 * Rate-limit budget for the points (collection) route — a viewport carrier fires
		 * one request per pan/zoom, a continuous-interaction stream, not a discrete click.
		 *
		 * Derivation (revisit together if either number changes): assumes a client-side
		 * pan/zoom debounce of ~300ms (the map provider wiring, a later task, picks the
		 * exact interval — that task and this budget must be chosen together), giving a
		 * worst-case continuous-pan rate of ~3.3 req/s ≈ 200/min. 240/min leaves roughly
		 * 20% headroom above that theoretical ceiling so a customer panning continuously
		 * for a full minute is not falsely limited, while still bounding scripted abuse.
		 *
		 * @since 2.0.2
		 *
		 * @var int
		 */
		protected const POINTS_RATE_LIMIT_MAX = 240;

		/**
		 * Rate-limit budget for the point-detail route — one request per balloon opened,
		 * a discrete click, not a continuous stream. Mirrors
		 * {@see Field_Source_Controller::RATE_LIMIT_MAX}'s original cascade-dropdown budget.
		 *
		 * @since 2.0.2
		 *
		 * @var int
		 */
		protected const DETAILS_RATE_LIMIT_MAX = 60;

		/**
		 * The owning plugin id this controller answers for.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		private string $plugin_id;

		/**
		 * The carrier's pickup-point source.
		 *
		 * @since 2.0.2
		 *
		 * @var Point_Source
		 */
		private Point_Source $source;

		/**
		 * Returns the current cart weight in GRAMS.
		 *
		 * WooCommerce's own weight unit is a store setting (`woocommerce_weight_unit`);
		 * converting to grams is the CALLER's responsibility, mirroring
		 * {@see Constraint_Checker::check()}'s own contract. On a REST request the
		 * WooCommerce cart is frequently NOT loaded (no session yet resolved), so this
		 * callable can legitimately return `0` — that is fine and MUST stay fine. A zero
		 * weight passes every positive limit, so the verdict computed here stays
		 * permissive; the authoritative gate is the server re-check at
		 * `woocommerce_checkout_process` (a later task), which runs once the real cart is
		 * loaded. Do not "fix" this into throwing or into a hard failure when the cart is
		 * absent — that would turn a routine, cart-less pre-checkout request (e.g. a
		 * customer panning the map before adding anything) into a broken picker.
		 *
		 * @since 2.0.2
		 *
		 * @var callable
		 */
		private $cart_weight;

		/**
		 * Returns the chosen WooCommerce payment method (gateway) id.
		 *
		 * @since 2.0.2
		 *
		 * @var callable
		 */
		private $payment_method;

		/**
		 * The constraint checker, constructed internally so plugins customise the
		 * verdict only through its `woodev_shipping_pickup_point_selectable` filter.
		 *
		 * @since 2.0.2
		 *
		 * @var Constraint_Checker
		 */
		private Constraint_Checker $checker;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param string       $plugin_id      the plugin id this controller routes for.
		 * @param Point_Source $source         the carrier's pickup-point source.
		 * @param callable     $cart_weight    `fn(): int` current cart weight in GRAMS; see
		 *                                     {@see self::$cart_weight} for why `0` is a
		 *                                     legitimate, permissive answer.
		 * @param callable     $payment_method `fn(): string` the chosen gateway id.
		 */
		public function __construct(
			string $plugin_id,
			Point_Source $source,
			callable $cart_weight,
			callable $payment_method
		) {
			$this->plugin_id      = $plugin_id;
			$this->source         = $source;
			$this->cart_weight    = $cart_weight;
			$this->payment_method = $payment_method;
			$this->checker        = new Constraint_Checker();
		}

		/**
		 * Registers the pickup-points collection and single-point detail routes.
		 *
		 * Read-only: two `GET` endpoints under `woodev/v1`, both intentionally public.
		 * Every declared arg carries `validate_callback => rest_validate_request_arg`, so
		 * WordPress itself rejects a wrongly-shaped param (e.g. an array-valued `locality`
		 * from a repeated query key) before the callback ever runs — a defense-in-depth
		 * layer alongside {@see Point_Query::from_request()}'s own type guards, which are
		 * the ones a direct (non-REST) caller still falls back on.
		 *
		 * The detail route's `id` capture (`[^/]+`) is deliberately looser than
		 * `Field_Source_Controller`'s `field_id` pattern (`[\w-]+`): a carrier's point id
		 * is not guaranteed to be `\w`-safe (some carriers embed `:` or `.` in their ids),
		 * so anything up to the next path-segment boundary is accepted; the arg's own
		 * `validate_callback` plus {@see self::cap_length()} still bound its shape and
		 * length before it ever reaches the source.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function register_routes(): void {

			// The plugin id is baked into the route PATH as a literal (not a
			// `(?P<plugin_id>…)` capture) so that each shipping plugin registers a DISTINCT
			// route — the same reasoning as Field_Source_Controller::register_routes().
			$plugin_segment = preg_replace( '/[^\w-]/', '', $this->plugin_id );

			if ( '' === (string) $plugin_segment ) {
				$plugin_segment = 'shipping';
			}

			register_rest_route(
				'woodev/v1',
				'/shipping/pickup/' . $plugin_segment . '/points',
				[
					[
						'methods'  => 'GET',
						'callback' => [ $this, 'handle_points_request' ],

						/*
						 * Intentionally public read: normalized pickup-point data is not
						 * sensitive. A future SENSITIVE source must add its own auth.
						 */
						'permission_callback' => '__return_true',
						'args'                => [
							'locality' => [
								'type'              => 'string',
								'validate_callback' => 'rest_validate_request_arg',
							],
							'bbox'     => [
								'type'              => 'string',
								'validate_callback' => 'rest_validate_request_arg',
							],
							'q'        => [
								'type'              => 'string',
								'validate_callback' => 'rest_validate_request_arg',
							],

							/*
							 * Comma-separated point-type codes (D-10) — a viewport carrier is
							 * queried per pan/zoom, so filtering by type belongs on the server;
							 * see Point_Query::get_types() and the Point_Source contract.
							 * sanitize_callback here is belt-and-suspenders alongside
							 * normalize_points_params()'s own wc_clean()+cap_length() pass —
							 * Point_Query::from_request() does the actual comma-splitting, the
							 * one parser for this param.
							 */
							'types'    => [
								'type'              => 'string',
								'validate_callback' => 'rest_validate_request_arg',
								'sanitize_callback' => 'sanitize_text_field',
							],
						],
					],
				]
			);

			register_rest_route(
				'woodev/v1',
				'/shipping/pickup/' . $plugin_segment . '/points/(?P<id>[^/]+)',
				[
					[
						'methods'  => 'GET',
						'callback' => [ $this, 'handle_point_request' ],

						// Intentionally public read — see register_routes() above.
						'permission_callback' => '__return_true',
						'args'                => [
							'id' => [
								'type'              => 'string',
								'required'          => true,
								'validate_callback' => 'rest_validate_request_arg',
							],
						],
					],
				]
			);
		}

		/**
		 * Handles a pickup-points collection request.
		 *
		 * Applies the best-effort rate limit, normalizes the query params, dispatches
		 * through {@see self::get_points_data()}, and turns a carrier
		 * `\Woodev_API_Exception` into a `502 WP_Error` (see the class docblock).
		 *
		 * `$request` is deliberately left without a native `\WP_REST_Request` type-hint
		 * (docblock-only, matching every REST callback in this codebase): a hand-written
		 * WC-free unit test needs a lightweight `get_param()` double, and the GLOBAL
		 * `\WP_REST_Request` symbol may already have been declared, more narrowly, by a
		 * different test file's own stub loaded earlier in the same PHPUnit process — a
		 * native type-hint against that symbol would then reject a perfectly good
		 * namespace-scoped test double, or crash outright against an incompatible one.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request object.
		 *
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function handle_points_request( $request ) {

			if ( $this->is_rate_limited( 'woodev_pickup_pts_rl_', self::POINTS_RATE_LIMIT_MAX ) ) {
				return $this->rate_limited_error();
			}

			$params = $this->normalize_points_params(
				[
					'locality' => $request->get_param( 'locality' ),
					'bbox'     => $request->get_param( 'bbox' ),
					'q'        => $request->get_param( 'q' ),
					'types'    => $request->get_param( 'types' ),
				]
			);

			try {
				$data = $this->get_points_data( $params );
			} catch ( \Woodev_API_Exception $e ) {
				$this->log_carrier_failure( $e, 'points fetch' );
				return $this->upstream_error();
			}

			return rest_ensure_response( $data );
		}

		/**
		 * Handles a single pickup-point detail request.
		 *
		 * Same rate-limit and carrier-failure handling as
		 * {@see self::handle_points_request()} (its own, separate rate-limit budget — see
		 * {@see DETAILS_RATE_LIMIT_MAX}). An unknown point (the source legitimately has
		 * nothing for that id) is a `404`, distinct from the `502` a carrier outage
		 * produces. `$request` is left untyped for the same reason as
		 * {@see self::handle_points_request()} — see its docblock.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request object.
		 *
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function handle_point_request( $request ) {

			if ( $this->is_rate_limited( 'woodev_pickup_dtl_rl_', self::DETAILS_RATE_LIMIT_MAX ) ) {
				return $this->rate_limited_error();
			}

			$id = $this->cap_length(
				(string) wc_clean( wp_unslash( $request->get_param( 'id' ) ) ),
				self::MAX_PARAM_LENGTH
			);

			try {
				$point = $this->get_point_data( $id );
			} catch ( \Woodev_API_Exception $e ) {
				$this->log_carrier_failure( $e, 'point details fetch' );
				return $this->upstream_error();
			}

			if ( null === $point ) {
				return new \WP_Error(
					'woodev_pickup_point_not_found',
					__( 'Пункт выдачи не найден.', 'woodev-plugin-framework' ),
					[ 'status' => 404 ]
				);
			}

			return rest_ensure_response( $point );
		}

		/**
		 * Dispatches a pickup-points query (pure, WC-free core).
		 *
		 * Builds a {@see Point_Query} from `$params` and returns an empty point list —
		 * NOT an error — for every case where the query is unusable: no addressing mode
		 * at all ({@see Point_Query::from_request()} returns null), or an addressing mode
		 * that does not match {@see Point_Source::get_strategy()} (see
		 * {@see self::query_matches_strategy()} and the class docblock's STRATEGY
		 * GUARANTEE section). Every returned point carries `selectable: { allowed, reason }`
		 * from {@see Constraint_Checker}, and the list is always a true (0-indexed) PHP
		 * list — a keyed array would otherwise serialize as a JSON object and break the
		 * map's client-side rendering.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $params raw query params (`locality`, `bbox`, `q`, `types`).
		 *
		 * @return array{points: array<int, array<string, mixed>>}
		 *
		 * @throws \Woodev_API_Exception on a carrier transport, auth, or API failure (see
		 *                                the class docblock's CARRIER FAILURE section).
		 */
		public function get_points_data( array $params ): array {

			$query = Point_Query::from_request( $params );

			if ( null === $query || ! $this->query_matches_strategy( $query ) ) {
				return [ 'points' => [] ];
			}

			$cart_weight    = ( $this->cart_weight )();
			$payment_method = ( $this->payment_method )();

			$points = [];

			foreach ( $this->source->fetch_points( $query ) as $point ) {

				if ( ! $point instanceof Pickup_Point ) {
					continue; // Defensive: a misbehaving source returning junk must not break the map.
				}

				$points[] = $this->to_response_point( $point, $cart_weight, $payment_method );
			}

			// array_values(): a dropped/sparse-keyed entry above must not leave gaps — a
			// keyed array serializes as a JSON object, not an array, and breaks the map JS.
			return [ 'points' => array_values( $points ) ];
		}

		/**
		 * Dispatches a single pickup-point detail lookup (pure, WC-free core).
		 *
		 * Returns null when the source has nothing for `$id` — a legitimately unknown
		 * point, distinct from a carrier failure (see the class docblock's CARRIER
		 * FAILURE section). The verdict is recomputed here (not reused from a prior list
		 * response) because `accepts_cod` / `max_weight` are frequently absent from a
		 * carrier's list response and arrive only with the details call.
		 *
		 * @since 2.0.2
		 *
		 * @param string $id carrier point id.
		 *
		 * @return array<string, mixed>|null the escaped point payload, or null when unknown.
		 *
		 * @throws \Woodev_API_Exception on a carrier transport, auth, or API failure.
		 */
		public function get_point_data( string $id ): ?array {

			$point = $this->source->fetch_details( $id );

			if ( null === $point ) {
				return null;
			}

			return $this->to_response_point( $point, ( $this->cart_weight )(), ( $this->payment_method )() );
		}

		/**
		 * Builds the browser-safe response payload for one point.
		 *
		 * Uses {@see Pickup_Point::to_browser_array()} — the escaped representation —
		 * NEVER {@see Pickup_Point::to_array()}, which is the canonical, unescaped shape
		 * meant only for order-meta persistence. Getting this backwards would ship
		 * unescaped carrier strings into a checkout page.
		 *
		 * @since 2.0.2
		 *
		 * @param Pickup_Point $point          point to serialize.
		 * @param int          $cart_weight    current cart weight in grams.
		 * @param string       $payment_method chosen gateway id.
		 *
		 * @return array<string, mixed>
		 */
		private function to_response_point( Pickup_Point $point, int $cart_weight, string $payment_method ): array {

			$data               = $point->to_browser_array();
			$data['selectable'] = $this->checker->check( $point, $payment_method, $cart_weight );

			return $data;
		}

		/**
		 * Checks a built query against the source's declared strategy.
		 *
		 * This is the enforcement point for the framework's strategy/query guarantee
		 * (see the class docblock): a `STRATEGY_BULK` source may only be queried with a
		 * non-null locality; a `STRATEGY_VIEWPORT` source may only be queried with
		 * non-null bounds. Any other strategy value (a source misconfiguration, a typo,
		 * or a future strategy this framework version does not recognize) matches nothing
		 * and fails closed to an empty result via the `default` branch, not a guess.
		 *
		 * @since 2.0.2
		 *
		 * @param Point_Query $query the built query.
		 *
		 * @return bool
		 */
		private function query_matches_strategy( Point_Query $query ): bool {

			switch ( $this->source->get_strategy() ) {
				case Point_Source::STRATEGY_BULK:
					return null !== $query->get_locality();

				case Point_Source::STRATEGY_VIEWPORT:
					return null !== $query->get_bounds();

				default:
					return false;
			}
		}

		/**
		 * Normalizes the raw request parameters into a safe dispatch context.
		 *
		 * SECURITY: applied BEFORE {@see Point_Query::from_request()} runs, but does NOT
		 * coerce a value's type. `wc_clean()` returns whatever shape it is given — a
		 * string stays a string, an array stays an array (recursively cleaned) — and a
		 * non-string value is passed through UNCHANGED rather than `(string)`-cast, so
		 * {@see Point_Query::from_request()}'s own `is_string()` guards are what reject
		 * it. That guard exists specifically so a non-scalar `q`/`locality`/`bbox` cannot
		 * silently become the literal string `"Array"`; casting here would make that
		 * guard unreachable from this, the only production caller. This is reachable, not
		 * theoretical: `register_routes()` declares these args as `type => 'string'` with
		 * a `validate_callback`, but a caller that bypasses REST arg validation (or an
		 * older WordPress without it wired for hand-written routes) can still hand this
		 * method an array (e.g. a repeated `locality[]=a&locality[]=b` query key).
		 *
		 * A genuine string is still sanitized and capped to {@see MAX_PARAM_LENGTH}
		 * characters. `bbox`'s shape (arity, numeric range, span cap) is validated by
		 * {@see Point_Query} itself and is NOT re-implemented here — only its length is
		 * capped, so a malformed value cannot bypass that cap before reaching it. `types`
		 * is treated identically: its comma-splitting is {@see Point_Query}'s job alone.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $raw raw request params (`locality`, `bbox`, `q`, `types`).
		 *
		 * @return array<string, mixed> normalized params — capped strings, or an
		 *                              unchanged non-string value for
		 *                              {@see Point_Query::from_request()} to reject.
		 */
		protected function normalize_points_params( array $raw ): array {

			return [
				'locality' => $this->clean_and_cap( $raw['locality'] ?? '' ),
				'bbox'     => $this->clean_and_cap( $raw['bbox'] ?? '' ),
				'q'        => $this->clean_and_cap( $raw['q'] ?? '' ),
				'types'    => $this->clean_and_cap( $raw['types'] ?? '' ),
			];
		}

		/**
		 * Sanitizes one request param without coercing its type.
		 *
		 * See {@see self::normalize_points_params()} for why a non-string is passed
		 * through rather than cast.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $value raw request param value.
		 *
		 * @return mixed the capped string, or the unchanged non-string value.
		 */
		private function clean_and_cap( $value ) {

			$cleaned = wc_clean( wp_unslash( $value ) );

			return is_string( $cleaned ) ? $this->cap_length( $cleaned, self::MAX_PARAM_LENGTH ) : $cleaned;
		}

		/**
		 * Builds the "carrier temporarily unavailable" error.
		 *
		 * `502` (Bad Gateway) is the honest status here — an upstream (the carrier)
		 * failed, this server did not — distinguishing it from a genuinely empty
		 * `{ points: [] }` result, which the map's error/retry UI relies on. See the class
		 * docblock's CARRIER FAILURE section for the full rationale.
		 *
		 * @since 2.0.2
		 *
		 * @return \WP_Error
		 */
		private function upstream_error(): \WP_Error {

			return new \WP_Error(
				'woodev_pickup_upstream_error',
				__(
					'Сервис пунктов выдачи временно недоступен. Попробуйте обновить страницу позже.',
					'woodev-plugin-framework'
				),
				[ 'status' => 502 ]
			);
		}

		/**
		 * Builds the rate-limit error.
		 *
		 * @since 2.0.2
		 *
		 * @return \WP_Error
		 */
		private function rate_limited_error(): \WP_Error {

			return new \WP_Error(
				'woodev_pickup_rate_limited',
				__( 'Слишком много запросов. Пожалуйста, подождите немного.', 'woodev-plugin-framework' ),
				[ 'status' => 429 ]
			);
		}

		/**
		 * Logs a swallowed carrier exception's real message.
		 *
		 * This controller has no plugin instance to reach a `Woodev_Plugin`-scoped
		 * logger through — `error_log()` with a `[woodev]` prefix is the reachable
		 * framework logging path, the same swallowed-exception diagnostic convention
		 * `class-rest-api-settings-page.php` and `class-rest-api-setup.php` already use.
		 * `protected`, not `private`, so a test subclass can override and silence it
		 * rather than letting the carrier's (fake, but credential-shaped) message reach
		 * the real test-suite stderr.
		 *
		 * @since 2.0.2
		 *
		 * @param \Woodev_API_Exception $e       the caught carrier exception.
		 * @param string                $context short description of the failing call.
		 *
		 * @return void
		 */
		protected function log_carrier_failure( \Woodev_API_Exception $e, string $context ): void {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for a
			// carrier failure; the browser only ever sees a generic 502.
			error_log(
				sprintf(
					'[woodev] pickup %s failed for plugin "%s": %s',
					$context,
					$this->plugin_id,
					$e->getMessage()
				)
			);
		}

		// is_rate_limited(), get_client_ip() and cap_length() are provided by
		// Rest_Rate_Limit_Trait (shared with Field_Source_Controller) — see that trait
		// for the rate-limit mechanism and its proxy/IPv6 caveats.
	}

endif;
