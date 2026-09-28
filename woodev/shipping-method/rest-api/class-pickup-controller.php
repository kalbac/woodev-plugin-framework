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
 * ADMIN ROUTES (#959, spec `2026-09-27-710-create-edit-order-design.md` D3): the same
 * list and detail payloads are also served under `shipping/orders/pickup/{plugin}/points`
 * ({@see self::register_admin_routes()}) for the admin order wizard. Those are NOT public
 * — `edit_shop_orders` — and take the weight, payment method and destination record as
 * request params instead of reading the cart/session/visitor chain, which are null in an
 * admin REST request. The public routes above are unchanged.
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

use Woodev\Framework\Http\Rest_Rate_Limit_Trait;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Pickup\Constraint_Checker;
use Woodev\Framework\Shipping\Pickup\Location_Aware_Point_Source;
use Woodev\Framework\Shipping\Pickup\Pickup_Point;
use Woodev\Framework\Shipping\Pickup\Point_Query;
use Woodev\Framework\Shipping\Pickup\Point_Source;
use Woodev\Framework\Shipping\Pickup\Selection_Result;

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
		 * Rate-limit budget for the selection (POST) route.
		 *
		 * Deliberately a quarter of {@see DETAILS_RATE_LIMIT_MAX} rather than a copy of it,
		 * because the two workloads are nothing alike. A detail request is one per balloon
		 * OPENED — a customer comparing points clicks through many of them — whereas a
		 * selection is one per point CONFIRMED, of which a real checkout produces a handful
		 * at the very most, and the picker locks the card while a confirmation is in flight,
		 * so a legitimate customer cannot issue them concurrently either.
		 *
		 * 15/min is one confirmation every four seconds, sustained for a full minute: roughly
		 * five times the worst honest case (a customer who confirms, changes their mind, and
		 * re-confirms a few times) and still far under anything that would let a scripted
		 * client with a valid nonce pump the carrier. This route is the ONLY one whose
		 * `woodev_shipping_pickup_point_selection` seam is documented as allowed to call the
		 * carrier, so its budget buys more protection per unit than either read's does —
		 * which is exactly why it is the tightest of the three, not the loosest.
		 *
		 * @since 2.0.2
		 *
		 * @var int
		 */
		protected const SELECT_RATE_LIMIT_MAX = 15;

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
		 * Returns the shipping method id the order will be placed with.
		 *
		 * A callable for the same reason {@see self::$cart_weight} and
		 * {@see self::$payment_method} are: this controller reads no WooCommerce global at
		 * all, which is exactly what lets its whole dispatch core be unit-tested without
		 * WooCommerce loaded. Reading `WC()->session` here would trade that away.
		 *
		 * Only the `.../select` route's domain seam consumes it — a plugin refusing a point
		 * usually refuses it FOR A METHOD (a carrier's postamats taking no oversized parcel
		 * on the express tariff, say), so a seam handed no method can only answer half the
		 * question. It deliberately does NOT come from the request: see
		 * {@see self::register_routes()} for why the browser must not get to assert which
		 * method the customer is checking out with.
		 *
		 * @since 2.0.2
		 *
		 * @var callable
		 */
		private $shipping_method;

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
		 * Resolves the customer's current Location Provider layer context for THIS plugin
		 * (Task 15; issue #159), or `null` when the plugin has not wired the layer.
		 *
		 * @since 2.0.2
		 *
		 * @var callable|null `fn(): ?array{record: Location_Record, resolved_identity: mixed}`.
		 */
		private $location_context;

		/**
		 * Resolves the Location Provider layer context for an EXPLICIT record — the admin
		 * routes' counterpart of {@see self::$location_context}, which reads the visitor's
		 * own session and so has nothing to answer in an admin request (#959).
		 *
		 * @since 2.0.2
		 *
		 * @var callable|null `fn( Location_Record $record ): ?array{record: Location_Record, resolved_identity: mixed}`.
		 */
		private $location_resolver;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param string        $plugin_id        the plugin id this controller routes for.
		 * @param Point_Source  $source           the carrier's pickup-point source.
		 * @param callable      $cart_weight      `fn(): int` current cart weight in GRAMS; see
		 *                                        {@see self::$cart_weight} for why `0` is a
		 *                                        legitimate, permissive answer.
		 * @param callable      $payment_method   `fn(): string` the chosen gateway id.
		 * @param callable      $shipping_method  `fn(): string` the chosen shipping method id;
		 *                                        see {@see self::$shipping_method}. Required,
		 *                                        not optional: the domain seam behind the
		 *                                        `.../select` route cannot answer correctly
		 *                                        without it, and a value an author can forget
		 *                                        to wire fails silently rather than loudly.
		 * @param callable|null $location_context (Task 15) `fn(): ?array{record: Location_Record,
		 *                                        resolved_identity: mixed}` — the customer's
		 *                                        current Location Provider layer record and
		 *                                        this plugin's own resolved carrier identity for
		 *                                        it, attached to every built {@see Point_Query}
		 *                                        via {@see Point_Query::with_location()}. `null`
		 *                                        (the default) when the owning plugin has not
		 *                                        wired the layer — every query then carries no
		 *                                        record, exactly as before this parameter existed.
		 * @param callable|null $location_resolver (#959) `fn( Location_Record $record ):
		 *                                        ?array{record: Location_Record, resolved_identity:
		 *                                        mixed}` — like `$location_context`, but for a
		 *                                        record the ADMIN routes were handed explicitly
		 *                                        instead of one read off the visitor's session.
		 *                                        `null` (the default) attaches nothing on those
		 *                                        routes. Never read by the public routes.
		 *
		 * SMELL, recorded rather than acted on: `$cart_weight`, `$payment_method` and
		 * `$shipping_method` are three consecutive `callable`s, so nothing at the type level
		 * catches a caller transposing two of them — they would simply feed the wrong values
		 * to the verdict and the domain seam, and no test outside this class would notice.
		 * Not worth refactoring at three: doing so would ripple through every construction
		 * site for no present benefit. TRIGGER: if a FOURTH request-context callable is ever
		 * needed, bundle all of them into a request-context object (named accessors, one
		 * argument) instead of extending this list again. `$location_context` IS that fourth
		 * callable (Task 15) — appended LAST and OPTIONAL rather than triggering the bundle
		 * refactor here: it answers a genuinely different question (Location Provider layer
		 * context for {@see Point_Query} enrichment, never read by the verdict or the `/select`
		 * domain seam) from the three it sits next to, so grouping it WITH them would not
		 * remove the transposition risk the note above warns about — it would only extend it
		 * to a fourth, unrelated slot. A real bundle refactor remains future work if a FIFTH
		 * request-context callable is ever needed.
		 */
		public function __construct(
			string $plugin_id,
			Point_Source $source,
			callable $cart_weight,
			callable $payment_method,
			callable $shipping_method,
			?callable $location_context = null,
			?callable $location_resolver = null
		) {
			$this->plugin_id         = $plugin_id;
			$this->source            = $source;
			$this->cart_weight       = $cart_weight;
			$this->payment_method    = $payment_method;
			$this->shipping_method   = $shipping_method;
			$this->location_context  = $location_context;
			$this->location_resolver = $location_resolver;
			$this->checker           = new Constraint_Checker();
		}

		/**
		 * Registers the pickup-points collection, single-point detail and selection routes.
		 *
		 * Two `GET` reads under `woodev/v1`, both intentionally public, plus one `POST`
		 * selection route which is NOT — see {@see self::check_select_permission()}.
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
			$plugin_segment = $this->route_plugin_segment();

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

			register_rest_route(
				'woodev/v1',
				'/shipping/pickup/' . $plugin_segment . '/select',
				[
					[
						'methods'  => 'POST',
						'callback' => [ $this, 'handle_select_request' ],

						/*
						 * NOT `__return_true`, unlike the two reads above. This route is the
						 * customer CONFIRMING a point, so it drives a write (the browser commits
						 * the point to the checkout field on its answer) and the domain seam
						 * behind it is explicitly allowed to call the carrier — which makes an
						 * unguarded POST a way to burn the merchant's carrier quota through a
						 * visitor's browser. A capability check is impossible here (guests place
						 * orders), so the nonce is the whole barrier.
						 */
						'permission_callback' => [ $this, 'check_select_permission' ],
						'args'                => [

							/*
							 * `minLength => 1` alongside `required => true`, on both ids: they say
							 * different things and only the pair says what is meant. `required`
							 * rejects an ABSENT param; it has nothing to say about a param that is
							 * present and empty, so `point_id=` satisfied it and `''` reached
							 * `fetch_details()` — a carrier round-trip for a point that cannot
							 * exist, spent out of the merchant's quota. WordPress applies this
							 * through the declared `validate_callback`, so it only guards a genuinely
							 * REST-dispatched request; {@see self::handle_select_request()} carries
							 * the same check for everything else, including a value that passes the
							 * schema but cleans down to nothing.
							 */
							'field_id' => [
								'type'              => 'string',
								'required'          => true,
								'minLength'         => 1,
								'validate_callback' => 'rest_validate_request_arg',
								'sanitize_callback' => 'sanitize_text_field',
							],

							/*
							 * No `sanitize_callback`, unlike `field_id` above — deliberate, not an
							 * oversight. `field_id` is a FRAMEWORK-owned identifier whose alphabet
							 * this framework defines (the field-source route captures it as
							 * `[\w-]+`), so narrowing it at the schema level costs nothing. A point
							 * id is an OPAQUE CARRIER token: some carriers embed `:` or `.` in one,
							 * which is why the sibling detail route captures it as the looser
							 * `[^/]+`. Declaring a sanitizer here would be the framework quietly
							 * deciding what a carrier id may contain — harmless with today's
							 * `sanitize_text_field`, silently lossy the day someone "tightens" it to
							 * `sanitize_key`. The id is cleaned in exactly one place instead,
							 * `handle_select_request()`'s `wc_clean` + `cap_length`, matching how
							 * the detail route treats its own `id`.
							 */
							'point_id' => [
								'type'              => 'string',
								'required'          => true,
								'minLength'         => 1,
								'validate_callback' => 'rest_validate_request_arg',
							],
						],

						/*
						 * There is deliberately NO `method_id` arg. The chosen shipping method
						 * reaches the domain seam from {@see self::$shipping_method} instead: a
						 * browser able to assert which method it is checking out with could talk
						 * a domain filter into approving a point for a method the customer never
						 * chose — and the seam's whole job is to answer that question truthfully.
						 */
					],
				]
			);
		}

		/**
		 * Guards the selection route with the REST cookie nonce.
		 *
		 * NON-OBVIOUS, and the reason this looks like dead code at a glance: WordPress's own
		 * `rest_cookie_check_errors()` rejects an INVALID nonce (a stale one from a cached
		 * page, say) before ANY `permission_callback` runs, so this callback never sees that
		 * case. What it does catch is the rest: no `X-WP-Nonce` header at all (a cross-site
		 * script POSTing directly), or a well-formed nonce minted for a DIFFERENT action.
		 * That asymmetry is also why a route declared `__return_true` can still answer 403 —
		 * the 403 came from core, not from the route — which is the confusion behind issue
		 * #157; do not "simplify" this callback away on the grounds that core already checks.
		 *
		 * `$request` is left without a native `\WP_REST_Request` type-hint for the same
		 * reason every other callback here is — see {@see self::handle_points_request()}.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request object.
		 *
		 * @return true|\WP_Error
		 */
		public function check_select_permission( \WP_REST_Request $request ) {

			$nonce = $request->get_header( 'X-WP-Nonce' );

			if ( ! is_string( $nonce ) || '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				return new \WP_Error(
					'woodev_pickup_invalid_nonce',
					__(
						'Страница оформления заказа устарела. Обновите её и попробуйте снова.',
						'woodev-plugin-framework'
					),
					[ 'status' => 403 ]
				);
			}

			return true;
		}

		/**
		 * Handles a pickup-point selection request — the server round-trip that lets the
		 * plugin's domain refuse a point the framework itself has no way to judge.
		 *
		 * The verdict is RECOMPUTED here against the live cart rather than trusted from
		 * whatever the browser last drew: the cart can change between the map being drawn
		 * and a point being confirmed, and the drawn verdict is UX only.
		 *
		 * A carrier failure is translated the same way the two read routes translate it —
		 * a `502` with a customer-safe Russian message (see the class docblock's CARRIER
		 * FAILURE section). Letting it propagate here instead would be a PHP fatal on the
		 * one request the customer is actively waiting on.
		 *
		 * GUARD ORDER is load-bearing and the two guards below are not interchangeable: the
		 * rate limit runs first (a throttled caller must read `429`, not a `400` about its
		 * params), then the empty-id check, and only then the carrier. Every gate that can
		 * reject a request cheaply sits in front of the one call in this flow that costs the
		 * merchant money.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request object.
		 *
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function handle_select_request( \WP_REST_Request $request ) {

			// FIRST, before the point lookup: a throttled request must not reach
			// Point_Source::fetch_details() — nor the domain seam behind it, which is the one
			// hook in this flow documented as allowed to call the carrier. Guarding any later
			// would still answer 429 while having already spent the merchant's quota, which
			// is the whole thing this budget exists to protect. The nonce stops a third-party
			// site; it does not stop a scripted client holding a valid one.
			if ( $this->is_rate_limited( 'woodev_pickup_sel_rl_', self::SELECT_RATE_LIMIT_MAX ) ) {
				return $this->rate_limited_error();
			}

			$field_id = $this->cap_length(
				(string) wc_clean( wp_unslash( $request->get_param( 'field_id' ) ) ),
				self::MAX_PARAM_LENGTH
			);

			$point_id = $this->cap_length(
				(string) wc_clean( wp_unslash( $request->get_param( 'point_id' ) ) ),
				self::MAX_PARAM_LENGTH
			);

			// SECOND, still before the point lookup: neither id may be empty. The route's own
			// `minLength => 1` schema (see register_routes()) is WordPress's gate and only
			// applies to a REST-dispatched request; this one also catches what the schema
			// cannot — a value that is a non-empty string when validated but cleans down to
			// nothing here (whitespace, control characters, a stripped tag). Either way the
			// point is that `fetch_details( '' )` is a carrier round-trip for a point that
			// cannot exist, paid for out of the merchant's quota.
			if ( '' === $point_id || '' === $field_id ) {
				return new \WP_Error(
					'woodev_pickup_invalid_selection',
					__( 'Пункт выдачи не указан.', 'woodev-plugin-framework' ),
					[ 'status' => 400 ]
				);
			}

			try {
				$point = $this->source->fetch_details( $point_id );
			} catch ( \Woodev_API_Exception $e ) {
				$this->log_carrier_failure( $e, 'point selection' );
				return $this->upstream_error();
			}

			if ( null === $point ) {
				return new \WP_Error(
					'woodev_pickup_point_not_found',
					__( 'Пункт выдачи не найден.', 'woodev-plugin-framework' ),
					[ 'status' => 404 ]
				);
			}

			$cart_weight    = ( $this->cart_weight )();
			$payment_method = ( $this->payment_method )();

			$computed = Selection_Result::from_verdict(
				$this->checker->check( $point, $payment_method, $cart_weight )
			);

			$context = [
				'field_id'       => $field_id,
				'method_id'      => (string) ( $this->shipping_method )(),
				'payment_method' => $payment_method,

				// GRAMS, like every other cart weight crossing this framework's seams — see
				// Constraint_Checker::check()'s own contract.
				'cart_weight'    => $cart_weight,
			];

			/**
			 * Filters the result of confirming one pickup point.
			 *
			 * This runs ONCE per confirmation, not once per drawn point, and it is therefore
			 * the one place in the pickup flow a plugin MAY call the carrier. Its sibling
			 * `woodev_shipping_pickup_point_selectable` runs while DRAWING the list — once per
			 * point, on every map pan — and must stay cheap; do not confuse the two.
			 *
			 * It is also the last cheap moment to catch a constraint only the carrier knows.
			 * {@see Constraint_Checker} treats unknown constraint data as PERMISSIVE by design
			 * (a carrier's list response routinely omits `accepts_cod`/`max_weight`), so a
			 * point the framework reports as selectable may still be one this carrier refuses
			 * for this order. After this, the next gate is the checkout POST itself.
			 *
			 * MALFORMED RETURNS FAIL CLOSED, SILENTLY: a return that is not an array, or whose
			 * `allowed`/`reason` pair is missing or wrongly typed, reverts to `$computed`
			 * entirely — no warning, no notice. Nothing tells you your filter was ignored, so
			 * match the documented shape exactly; see
			 * {@see \Woodev\Framework\Shipping\Pickup\Selection_Result::sanitize()} for the
			 * two-tier rule (verdict all-or-nothing, advice normalised key by key).
			 *
			 * `close` and `refresh_checkout` are THREE-STATE, not booleans:
			 * - leave them `null` (or omit them) to DEFER to the plugin's configured default;
			 * - return an explicit `true`/`false` to decide this one selection.
			 * An explicit `false` is preserved as `false` — it is a decision ("do not close"),
			 * never re-read as the unspoken `null`, which would hand control straight back to
			 * the default you just overrode.
			 *
			 * `point` is a CORRECTED POINT, not a flag. The browser replaces the point it is
			 * holding with whatever comes back here, so it must be the same shape
			 * {@see self::to_response_point()} emits on the two read routes — i.e.
			 * {@see Pickup_Point::to_browser_array()} (`id`, `name`, `address`,
			 * `short_address`, `locality`, `postal_code`, `phone`, `instruction`, `work_time`,
			 * `point_short_name`, `lat`, `lng`, `type` => `{ code, label }`, `payment_methods`,
			 * `services`, `photos`, `accepts_cod`, `max_weight`). The easy, correct way to build one is to
			 * mutate the freshly-resolved `$point` and call `to_browser_array()` on it yourself
			 * rather than assembling the keys by hand.
			 *
			 * Whatever you return is REBUILT through {@see Pickup_Point::from_array()} and
			 * re-serialized before it leaves this route, so the browser never receives an
			 * unescaped or unknown field regardless of what a filter hands over — the browser
			 * does not re-escape these strings, so nothing else would. Three things follow:
			 * a point that does not satisfy `from_array()`'s own validation is dropped as if
			 * you had returned `null` (the verdict beside it still stands); a key the point
			 * shape does not know is dropped; and the `selectable` entry is derived from this
			 * result's own `allowed`/`reason` rather than read from your array, so the two can
			 * never disagree. Returning an already-escaped `to_browser_array()` shape is safe —
			 * `esc_html()` does not double-encode. See
			 * {@see \Woodev\Framework\Shipping\Pickup\Selection_Result::sanitize_point()}.
			 *
			 * Populate it when confirmation taught the domain something the listing did not
			 * know — the carrier returned a refined address or a corrected postcode for this
			 * point, say. Leave it `null` (the default) to mean "nothing to update, keep the
			 * point you already have"; `null` is not "clear the point".
			 *
			 * @since 2.0.2
			 *
			 * @param array{
			 *     allowed: bool,
			 *     reason: string|null,
			 *     close: bool|null,
			 *     refresh_checkout: bool|null,
			 *     point: array<string, mixed>|null,
			 * }                    $computed The framework's own result: its
			 *                                {@see Constraint_Checker} verdict, with all three
			 *                                advice fields still unspoken.
			 * @param Pickup_Point   $point    The point being confirmed, freshly resolved from
			 *                                 the carrier.
			 * @param array{
			 *     field_id: string,
			 *     method_id: string,
			 *     payment_method: string,
			 *     cart_weight: int,
			 * }                    $context  The checkout field, the chosen shipping method
			 *                                (from the framework, never from the request), the
			 *                                chosen gateway, and the cart weight in GRAMS.
			 *                                `method_id` is the BARE method id, `:instance_id`
			 *                                already stripped — and it is `''` whenever
			 *                                WooCommerce cannot yet tell us (no session
			 *                                started, no rate chosen); a domain keying off it
			 *                                must treat `''` as "unknown", never match it
			 *                                against a real method id. Same for
			 *                                `payment_method`, and `cart_weight` is `0` on a
			 *                                cart that is not loaded — see
			 *                                {@see self::$cart_weight}.
			 */
			$filtered  = apply_filters( 'woodev_shipping_pickup_point_selection', $computed, $point, $context );
			$sanitized = Selection_Result::sanitize( $filtered, $computed );

			// Fired AFTER the domain filter above and AFTER sanitize() has resolved the
			// FINAL verdict — never off `$computed`, which predates whatever a domain
			// filter just decided. A domain filter is free to FLIP `allowed`, and a
			// point it just refused must never be remembered; gating on the sanitized,
			// post-filter result is what makes that true regardless of what any
			// listener here does. Not the `woodev_shipping_pickup_point_selection`
			// filter itself: that contract is the domain VOLUNTEERING advice on top of
			// a verdict, not a side-effect seam, and this controller stays free of
			// WooCommerce globals either way — firing an action is not reading one.
			if ( true === $sanitized['allowed'] ) {
				// The EFFECTIVE point, not the pre-filter one. The filter above may return a
				// corrected point, and its own contract says the browser REPLACES what it is
				// holding with that — so the address replacement, and with it whatever the
				// checkout later reports as the current locality, follow the CORRECTED point.
				// A listener keying off the pre-filter point would then file the selection
				// under a locality the checkout no longer reports, and the restore would miss
				// silently. `sanitize_point()` already validated this exact array through
				// `Pickup_Point::from_array()`; rebuilding it here re-runs that one method
				// rather than duplicating its rules, and a non-null `$sanitized['point']` is
				// precisely the signal that it validated.
				$effective_point = $point;

				if ( null !== $sanitized['point'] && is_array( $filtered['point'] ?? null ) ) {
					$corrected = Pickup_Point::from_array( $filtered['point'] );

					if ( null !== $corrected ) {
						$effective_point = $corrected;
					}
				}

				/**
				 * Fires after a pickup point selection has been confirmed and allowed —
				 * the write side of pickup-selection persistence (issue #176).
				 *
				 * @since 2.0.2
				 *
				 * @param Pickup_Point         $point   The confirmed point, INCLUDING any
				 *                                       correction a domain filter applied
				 *                                       above — this is the point the browser
				 *                                       ends up holding, so a listener that
				 *                                       derives a storage key from it agrees
				 *                                       with what the checkout will report.
				 * @param array{
				 *     field_id: string,
				 *     method_id: string,
				 *     payment_method: string,
				 *     cart_weight: int,
				 * }                           $context Same shape as the
				 *                                       `woodev_shipping_pickup_point_selection`
				 *                                       filter's own `$context` — see
				 *                                       that filter's docblock just
				 *                                       above for what each key means,
				 *                                       including why `method_id` is
				 *                                       already the bare, normalized id.
				 */
				do_action( 'woodev_shipping_pickup_point_selected', $effective_point, $context );
			}

			return rest_ensure_response( $sanitized );
		}

		/**
		 * Handles a pickup-points collection request.
		 *
		 * Applies the best-effort rate limit, normalizes the query params, dispatches
		 * through {@see self::get_points_data()}, and turns a carrier
		 * `\Woodev_API_Exception` into a `502 WP_Error` (see the class docblock).
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request object.
		 *
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function handle_points_request( \WP_REST_Request $request ) {

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
		 * produces.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request object.
		 *
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function handle_point_request( \WP_REST_Request $request ) {

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
		 * The plugin id as a route-safe path segment.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		private function route_plugin_segment(): string {

			$plugin_segment = (string) preg_replace( '/[^\w-]/', '', $this->plugin_id );

			return '' === $plugin_segment ? 'shipping' : $plugin_segment;
		}

		/**
		 * Registers the ADMIN pickup-points list and point-detail routes (#959, spec D3).
		 *
		 * `GET woodev/v1/shipping/orders/pickup/{plugin}/points` and `…/points/{id}`, for the
		 * admin order wizard (#710). They answer the same payload as the public routes but
		 * take the request context EXPLICITLY — `weight`, `payment_method` and `location` —
		 * because the cart, the session and the visitor's location chain the public routes
		 * read are all null in an admin REST request. The public routes are not touched.
		 *
		 * Unlike them, these are NOT public: they require `edit_shop_orders`, the capability
		 * of the shipping-orders write routes ({@see Orders_Controller}). A missing or
		 * insufficient login is answered by WordPress itself (401 / 403), and a cookie
		 * request without the `wp_rest` nonce never reaches this callback.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function register_admin_routes(): void {

			$base = '/shipping/orders/pickup/' . $this->route_plugin_segment() . '/points';

			$context_args = [
				// Order weight in GRAMS (the checker's unit); 0 = unknown, which passes every limit.
				'weight'         => [
					'type'              => 'integer',
					'minimum'           => 0,
					'default'           => 0,
					'validate_callback' => 'rest_validate_request_arg',
				],
				// Chosen gateway id; empty = not chosen yet, so nothing is refused on COD.
				'payment_method' => [
					'type'              => 'string',
					'default'           => '',
					'validate_callback' => 'rest_validate_request_arg',
				],
			];

			/*
			 * The destination record, a `Location_Record::to_array()` shape, on BOTH routes
			 * (spec D3): the list and the detail resolve the carrier identity from the same
			 * explicit record, so a source whose detail lookup depends on the destination
			 * cannot disagree with the list. No nested schema, for the reason
			 * `/location/select` gives: the contract lives in Location_Record::from_array(),
			 * and a second copy here would only drift. Optional — a carrier that addresses by
			 * bbox needs none.
			 */
			$location_arg = [
				'type'              => 'object',
				'validate_callback' => 'rest_validate_request_arg',
			];

			register_rest_route(
				'woodev/v1',
				$base,
				[
					[
						'methods'             => 'GET',
						'callback'            => [ $this, 'handle_admin_points_request' ],
						'permission_callback' => [ $this, 'check_admin_permission' ],
						'args'                => array_merge(
							$context_args,
							[
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
								'types'    => [
									'type'              => 'string',
									'validate_callback' => 'rest_validate_request_arg',
									'sanitize_callback' => 'sanitize_text_field',
								],
								'location' => $location_arg,
							]
						),
					],
				]
			);

			register_rest_route(
				'woodev/v1',
				$base . '/(?P<id>[^/]+)',
				[
					[
						'methods'             => 'GET',
						'callback'            => [ $this, 'handle_admin_point_request' ],
						'permission_callback' => [ $this, 'check_admin_permission' ],
						'args'                => array_merge(
							$context_args,
							[
								'id'       => [
									'type'              => 'string',
									'required'          => true,
									'validate_callback' => 'rest_validate_request_arg',
								],
								'location' => $location_arg,
							]
						),
					],
				]
			);
		}

		/**
		 * Permission gate for the admin pickup routes: `edit_shop_orders`, the same
		 * capability {@see Orders_Controller::perform_action_permissions_check()} requires.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function check_admin_permission(): bool {
			return current_user_can( 'edit_shop_orders' );
		}

		/**
		 * Handles an admin pickup-points collection request (#959).
		 *
		 * No rate limit, unlike {@see self::handle_points_request()}: that one guards a
		 * public guest endpoint against a scripted client, and this route is behind a
		 * capability check. A carrier failure is still a `502`, never an empty list.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request object.
		 *
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function handle_admin_points_request( \WP_REST_Request $request ) {

			$record = $this->parse_location_param( $request->get_param( 'location' ) );

			if ( $record instanceof \WP_Error ) {
				return $record;
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
				$data = $this->get_points_data_for(
					$params,
					$this->explicit_weight( $request ),
					$this->explicit_payment_method( $request ),
					$record
				);
			} catch ( \Woodev_API_Exception $e ) {
				$this->log_carrier_failure( $e, 'admin points fetch' );
				return $this->upstream_error();
			}

			return rest_ensure_response( $data );
		}

		/**
		 * Handles an admin single-point detail request (#959).
		 *
		 * Takes the same explicit `location` the list route does (spec D3), validated the
		 * same way — a malformed record is a `400` before the carrier is asked anything.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request object.
		 *
		 * @return \WP_REST_Response|\WP_Error
		 */
		public function handle_admin_point_request( \WP_REST_Request $request ) {

			$record = $this->parse_location_param( $request->get_param( 'location' ) );

			if ( $record instanceof \WP_Error ) {
				return $record;
			}

			$id = $this->cap_length(
				(string) wc_clean( wp_unslash( $request->get_param( 'id' ) ) ),
				self::MAX_PARAM_LENGTH
			);

			try {
				$point = $this->get_point_data_for(
					$id,
					$this->explicit_weight( $request ),
					$this->explicit_payment_method( $request ),
					$record
				);
			} catch ( \Woodev_API_Exception $e ) {
				$this->log_carrier_failure( $e, 'admin point details fetch' );
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
		 * Reads the explicit order weight (grams) off an admin request; never negative.
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request object.
		 *
		 * @return int
		 */
		private function explicit_weight( \WP_REST_Request $request ): int {

			$weight = $request->get_param( 'weight' );

			return is_numeric( $weight ) ? max( 0, (int) $weight ) : 0;
		}

		/**
		 * Reads the explicit payment method id off an admin request, `''` when absent or
		 * not a string — an unknown method is permissive in the verdict, never an error.
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request object.
		 *
		 * @return string
		 */
		private function explicit_payment_method( \WP_REST_Request $request ): string {

			$method = $request->get_param( 'payment_method' );

			if ( ! is_string( $method ) ) {
				return '';
			}

			return $this->cap_length( (string) wc_clean( wp_unslash( $method ) ), self::MAX_PARAM_LENGTH );
		}

		/**
		 * Turns the admin `location` param into a {@see Location_Record}.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $raw the request's `location` value.
		 *
		 * @return Location_Record|\WP_Error|null `null` when the param is absent; a `400`
		 *                                        `WP_Error` when present but not a valid record.
		 */
		private function parse_location_param( $raw ) {

			if ( null === $raw || [] === $raw ) {
				return null;
			}

			try {
				if ( ! is_array( $raw ) ) {
					throw new \InvalidArgumentException( 'location must be an object' );
				}

				return Location_Record::from_array( $raw );
			} catch ( \InvalidArgumentException $exception ) {
				return new \WP_Error(
					'woodev_location_invalid_record',
					__( 'Некорректные данные о местоположении.', 'woodev-plugin-framework' ),
					[ 'status' => 400 ]
				);
			}
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

			$query = $this->attach_location_context( $query );

			$cart_weight    = ( $this->cart_weight )();
			$payment_method = ( $this->payment_method )();

			return $this->collect_points( $query, $cart_weight, $payment_method );
		}

		/**
		 * Fetches a query's points and shapes the response — the loop
		 * {@see self::get_points_data()} and {@see self::get_points_data_for()} share, so
		 * the public and the admin list can never diverge in shape or verdict.
		 *
		 * @since 2.0.2
		 *
		 * @param Point_Query $query          the built, strategy-validated query.
		 * @param int         $cart_weight    order weight in grams.
		 * @param string      $payment_method chosen gateway id; `''` when unknown.
		 *
		 * @return array{points: array<int, array<string, mixed>>}
		 *
		 * @throws \Woodev_API_Exception on a carrier transport, auth, or API failure.
		 */
		private function collect_points( Point_Query $query, int $cart_weight, string $payment_method ): array {

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
		 * Dispatches a pickup-points query for an EXPLICIT context (#959, spec D3) — the
		 * admin routes' twin of {@see self::get_points_data()}.
		 *
		 * Same query building, same strategy guarantee, same response shape and same
		 * verdict as the public core; the only difference is where the three inputs come
		 * from. The public core reads them through callables (the cart, the session, the
		 * visitor's location chain), all of which are null in an admin REST request, so
		 * this one takes them as arguments and never touches a callable.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $params         raw query params (`locality`, `bbox`, `q`, `types`).
		 * @param int                  $cart_weight    order weight in grams; `0` passes every limit.
		 * @param string               $payment_method chosen gateway id, `''` when not chosen yet —
		 *                                             then no point is refused on cash-on-delivery.
		 * @param Location_Record|null $record         destination record, or `null` when there is none.
		 *
		 * @return array{points: array<int, array<string, mixed>>}
		 *
		 * @throws \Woodev_API_Exception on a carrier transport, auth, or API failure.
		 */
		public function get_points_data_for( array $params, int $cart_weight, string $payment_method, ?Location_Record $record ): array {

			$query = Point_Query::from_request( $params );

			if ( null === $query || ! $this->query_matches_strategy( $query ) ) {
				return [ 'points' => [] ];
			}

			$query = $this->attach_explicit_location( $query, $record );

			return $this->collect_points( $query, $cart_weight, $payment_method );
		}

		/**
		 * Dispatches a single-point detail lookup for an EXPLICIT context (#959) — the admin
		 * routes' twin of {@see self::get_point_data()}.
		 *
		 * The verdict is computed from the SAME weight and payment method the list route was
		 * given, so a point the list showed as selectable cannot come back refused (or the
		 * other way round) from its own detail.
		 *
		 * The destination record reaches the source too (spec D3): when the source
		 * implements {@see Location_Aware_Point_Source} and the location resolver answered
		 * for `$record`, the detail lookup is {@see Location_Aware_Point_Source::fetch_details_for()}
		 * with the SAME record and carrier identity the list route's {@see Point_Query}
		 * carries. With no record, no resolver, an unusable answer (the list route's
		 * fail-open) or a source that does not opt in, it is the plain
		 * {@see Point_Source::fetch_details()}.
		 *
		 * @since 2.0.2
		 *
		 * @param string               $id             carrier point id.
		 * @param int                  $cart_weight    order weight in grams.
		 * @param string               $payment_method chosen gateway id, `''` when not chosen yet.
		 * @param Location_Record|null $record         destination record, or `null` when there is none.
		 *
		 * @return array<string, mixed>|null the escaped point payload, or null when unknown.
		 *
		 * @throws \Woodev_API_Exception on a carrier transport, auth, or API failure.
		 */
		public function get_point_data_for( string $id, int $cart_weight, string $payment_method, ?Location_Record $record ): ?array {

			$context = $this->resolve_explicit_context( $record );

			if ( null !== $context && $this->source instanceof Location_Aware_Point_Source ) {
				$point = $this->source->fetch_details_for( $id, $context['record'], $context['resolved_identity'] );
			} else {
				$point = $this->source->fetch_details( $id );
			}

			if ( null === $point ) {
				return null;
			}

			return $this->to_response_point( $point, $cart_weight, $payment_method );
		}

		/**
		 * Attaches the Location Provider layer's current record/resolved-identity to
		 * `$query` (Task 15; issue #159), via {@see self::$location_context} when the
		 * owning plugin wired one.
		 *
		 * A no-op — returns `$query` unchanged — when: no `$location_context` callable was
		 * given at all (the plugin has not wired the layer); it answers `null` (no current
		 * record yet); or it answers a malformed shape (defensive — a plugin's own callable
		 * misbehaving must not fatal a public, guest-facing route). {@see Point_Query} is
		 * immutable, so this always returns either the SAME instance (no-op) or a fresh one
		 * from {@see Point_Query::with_location()}, never mutates `$query` in place.
		 *
		 * @since 2.0.2
		 *
		 * @param Point_Query $query The built, strategy-validated query.
		 *
		 * @return Point_Query
		 */
		private function attach_location_context( Point_Query $query ): Point_Query {

			if ( null === $this->location_context ) {
				return $query;
			}

			return $this->apply_location_context( $query, ( $this->location_context )() );
		}

		/**
		 * Attaches the layer context for an EXPLICIT record to `$query` (#959) — the admin
		 * routes' counterpart of {@see self::attach_location_context()}.
		 *
		 * A no-op when no record was given, when the plugin wired no
		 * {@see self::$location_resolver}, or when the resolver answers something unusable
		 * — the same fail-open the public path has, for the same reason.
		 *
		 * @since 2.0.2
		 *
		 * @param Point_Query          $query  the built, strategy-validated query.
		 * @param Location_Record|null $record the destination record the caller named.
		 *
		 * @return Point_Query
		 */
		private function attach_explicit_location( Point_Query $query, ?Location_Record $record ): Point_Query {

			if ( null === $record || null === $this->location_resolver ) {
				return $query;
			}

			return $this->apply_location_context( $query, ( $this->location_resolver )( $record ) );
		}

		/**
		 * Asks the location resolver for the layer context of an EXPLICIT record (#959) — the
		 * detail route's counterpart of {@see self::attach_explicit_location()}, which
		 * applies the same answer to a {@see Point_Query}.
		 *
		 * Same fail-open: `null` when no record was given, when the plugin wired no
		 * {@see self::$location_resolver}, or when the resolver answers anything but the
		 * documented `{ record, resolved_identity }` shape.
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Record|null $record the destination record the caller named.
		 *
		 * @return array{record: Location_Record, resolved_identity: mixed}|null
		 */
		private function resolve_explicit_context( ?Location_Record $record ): ?array {

			if ( null === $record || null === $this->location_resolver ) {
				return null;
			}

			$context = ( $this->location_resolver )( $record );

			if ( ! is_array( $context ) || ! ( $context['record'] ?? null ) instanceof Location_Record ) {
				return null;
			}

			return [
				'record'            => $context['record'],
				'resolved_identity' => $context['resolved_identity'] ?? null,
			];
		}

		/**
		 * Applies a location-layer answer to `$query`, or returns it unchanged when the
		 * answer is not the documented `{ record, resolved_identity }` shape.
		 *
		 * @since 2.0.2
		 *
		 * @param Point_Query $query   the query.
		 * @param mixed       $context what a location callable answered.
		 *
		 * @return Point_Query
		 */
		private function apply_location_context( Point_Query $query, $context ): Point_Query {

			if ( ! is_array( $context ) || ! ( $context['record'] ?? null ) instanceof Location_Record ) {
				return $query;
			}

			return $query->with_location( $context['record'], $context['resolved_identity'] ?? null );
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
		 * `$e->getMessage()` is routed through {@see \Woodev_API_Base::redact_secret_log_text()}
		 * before it is logged: `$e` came out of `Point_Source::fetch_details()`, a
		 * plugin extension seam wrapping a live third-party carrier client, so its
		 * message may never have passed through `Woodev_API_Base`'s own redaction at
		 * all — see that method's docblock for why this is defence in depth, not a
		 * guarantee (#585).
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
					\Woodev_API_Base::redact_secret_log_text( $e->getMessage() )
				)
			);
		}

		// is_rate_limited(), get_client_ip() and cap_length() are provided by
		// Rest_Rate_Limit_Trait (shared with Field_Source_Controller) — see that trait
		// for the rate-limit mechanism and its proxy/IPv6 caveats.
	}

endif;
