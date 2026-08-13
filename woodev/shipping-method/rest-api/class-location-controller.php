<?php
/**
 * Woodev Location REST Controller
 *
 * Serves the store-level Location Provider layer's client seam (Task 8; spec D1,
 * D4, D8, D15): `GET woodev/v1/location/suggest` (query-driven suggestions),
 * `POST woodev/v1/location/select` (persist a chosen record), and
 * `GET woodev/v1/location/list` (full enumeration within a scope — Task 13,
 * feeding the `related-list`/`ajax-select2` field modes, spec D7). Unlike the sibling
 * shipping controllers ({@see Field_Source_Controller}, {@see Pickup_Controller}),
 * this one is FLEET-WIDE, not per-plugin: there is exactly one active location
 * provider per store (spec §4.1), so the route carries no `plugin_id` path
 * segment and this controller is registered once by
 * {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry} — the
 * same once-per-fleet owner that already hooks `init` (provider collection) and
 * `wp_login` (guest-to-account migration) — rather than through
 * {@see Shipping_REST_API::get_rest_controllers()}, which is a PER-PLUGIN
 * bootstrap keyed to one plugin's own REST namespace. (Contradicts the plan's
 * file list, which named `class-shipping-rest-api.php` as the registration
 * site — see that file's own docblock: "concrete controllers are wired through
 * `get_rest_controllers()`" is only ever true for the warehouses controller in
 * this codebase; `Field_Source_Controller` and `Pickup_Controller` both
 * self-register via their OWNING HANDLER's own `add_action( 'rest_api_init', … )`
 * instead, and Location has no per-plugin handler to be that owner, so the
 * registry itself — already the fleet-wide hook owner — is the correct home.)
 *
 * SECURITY (D4: tokens are store settings, held server-side; the client talks
 * only to this REST seam):
 * - `/suggest` and `/list` are both PUBLIC guest-checkout reads, mirroring
 *   {@see Field_Source_Controller} and {@see Pickup_Controller} exactly: every
 *   param is normalized/capped before use, every response field is either the
 *   provider's OWN {@see Location_Record} shape (never provider settings/
 *   credentials — this controller never even calls
 *   {@see Location_Provider::get_settings_fields()}/`is_configured()`'s
 *   underlying option reads) or explicitly escaped (`label`), and a best-effort
 *   per-IP rate limit ({@see Rest_Rate_Limit_Trait}) raises the bar against
 *   abuse. `/list` degrades to 404 rather than `/suggest`'s 200+empty when
 *   nothing can answer it — see {@see self::handle_list_request()}'s own
 *   docblock for why that asymmetry is intentional.
 * - `/select` is NOT public-read: it is the customer's write into the
 *   server-side customer-location store, so it is nonce-gated exactly like
 *   {@see Pickup_Controller::check_select_permission()} — a capability check is
 *   impossible here (guests check out), so the `wp_rest` REST cookie nonce is
 *   the whole barrier. See {@see self::check_select_permission()}'s own
 *   docblock for the exact WordPress nonce semantics this mirrors (gotchas
 *   `rest-cookie-nonce-auth-semantics`, `rest-endpoint-not-for-browser-cookie-auth`).
 *
 * A request may NOT name which provider answers it (spec D15: the store setting
 * — and its fallback chain — decides, invisibly to the client): `/suggest`
 * declares no `provider` arg and this controller never reads one; the provider
 * is always {@see Location_Service::provider_for_level()}'s own server-side
 * resolution.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Rest_Api;

use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Location\Location_Scope;
use Woodev\Framework\Shipping\Location\Location_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Rest_Api\\Location_Controller' ) ) :

	/**
	 * Location suggest/select dispatch controller.
	 *
	 * @since 2.0.2
	 */
	class Location_Controller extends \WP_REST_Controller {

		use Rest_Rate_Limit_Trait;

		/**
		 * Minimum accepted length (chars, mb-aware) for the `q` param — below
		 * this a query is too short to search on (§8 hardening precedent:
		 * {@see Field_Source_Controller::MAX_PARAM_LENGTH}'s sibling bound).
		 *
		 * @since 2.0.2
		 *
		 * @var int
		 */
		protected const MIN_QUERY_LENGTH = 2;

		/**
		 * Maximum accepted length (chars, mb-aware) for the free-text `q` /
		 * `within` params — same cap the sibling §8 controllers use.
		 *
		 * @since 2.0.2
		 *
		 * @var int
		 */
		protected const MAX_PARAM_LENGTH = 128;

		/**
		 * Rate-limit budget for `/suggest` — a cascade dropdown fired on typing,
		 * the same workload {@see Field_Source_Controller::RATE_LIMIT_MAX} was
		 * sized for.
		 *
		 * @since 2.0.2
		 *
		 * @var int
		 */
		protected const SUGGEST_RATE_LIMIT_MAX = 60;

		/**
		 * Rate-limit budget for `/select` — a discrete confirmation, not a
		 * continuous stream, but the location cascade can have up to three
		 * customer-driven confirmations in one checkout (region, settlement,
		 * address) plus the occasional correction, so this sits between
		 * {@see Field_Source_Controller::RATE_LIMIT_MAX} (a read) and
		 * {@see Pickup_Controller::SELECT_RATE_LIMIT_MAX} (one write per whole
		 * checkout): 30/min is ten times the worst honest case (three
		 * selections revised a couple of times each within a minute) while
		 * still far under anything that would let a scripted client with a
		 * valid nonce spam the customer-location store.
		 *
		 * @since 2.0.2
		 *
		 * @var int
		 */
		protected const SELECT_RATE_LIMIT_MAX = 30;

		/**
		 * Rate-limit budget for `/list` (Task 13) — a discrete lookup fired on a
		 * parent-region change under `related-list`/`ajax-select2` modes, not a
		 * per-keystroke stream the way `/suggest` is; sits at the same budget as
		 * `/suggest` regardless, since a related-list select2's own remote-data
		 * mode (spec D7) can re-query as the customer scrolls/searches within it.
		 *
		 * @since 2.0.2
		 *
		 * @var int
		 */
		protected const LIST_RATE_LIMIT_MAX = 60;

		/**
		 * Hard cap on the number of localities `/list` ever returns in one
		 * response, and the default when no (or an out-of-range) `limit` request
		 * arg is given (PR #304 review finding 5).
		 *
		 * The provider contract itself is unbounded (`list_localities()`: "every
		 * locality within scope") and `within` cannot narrow it for a GUEST
		 * customer either (it only resolves against an already-persisted
		 * customer record, see {@see self::build_scope()}), so a country-wide
		 * `settlement`/`address` enumeration can legitimately be a dictionary
		 * provider's ENTIRE city table — with every record's full `raw` payload —
		 * per request. 500 is chosen deliberately: comfortably above the largest
		 * real `region` list (Russia: 85 federal subjects, the top of what this
		 * layer's own supported countries ever enumerate at that level), while
		 * still bounding a `settlement`/`address` dictionary dump — which can run
		 * into the tens of thousands of rows — to a JSON payload and DOM size a
		 * single request/render can comfortably handle.
		 *
		 * The response is honest about a cap actually having been hit
		 * ({@see self::handle_list_request()}'s own `truncated` field) rather
		 * than silently returning a partial list that reads as "this is all
		 * there is".
		 *
		 * @since 2.0.2
		 *
		 * @var int
		 */
		protected const LIST_HARD_CAP = 500;

		/**
		 * The façade this controller dispatches through. Defaults to a fresh
		 * {@see Location_Service} (which itself defaults to the shared
		 * {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry}
		 * singleton) — accepting one via the constructor is purely a test seam,
		 * mirroring {@see Pickup_Controller}'s own constructor-injected
		 * collaborators.
		 *
		 * @since 2.0.2
		 *
		 * @var Location_Service
		 */
		private Location_Service $service;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Service|null $service The façade to dispatch through;
		 *                                        defaults to a fresh instance.
		 */
		public function __construct( ?Location_Service $service = null ) {
			$this->service = $service ?? new Location_Service();
		}

		/**
		 * Registers the `/location/suggest` and `/location/select` routes.
		 *
		 * Unlike every sibling shipping controller, the route carries no
		 * `plugin_id` path segment — see the class docblock for why this
		 * controller is fleet-wide rather than per-plugin.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function register_routes(): void {

			register_rest_route(
				'woodev/v1',
				'/location/suggest',
				[
					[
						'methods'  => 'GET',
						'callback' => [ $this, 'handle_suggest_request' ],

						/*
						 * Intentionally public read, mirroring Field_Source_Controller
						 * and Pickup_Controller exactly: a customer checking out is
						 * usually not logged in, and every returned field is either a
						 * neutral Location_Record component or the explicitly-escaped
						 * `label` — never a provider credential (D4; see the class
						 * docblock). A future SENSITIVE source must add its own auth.
						 */
						'permission_callback' => '__return_true',
						'args'                => [
							'q'       => [
								'type'              => 'string',
								'required'          => true,
								'validate_callback' => 'rest_validate_request_arg',
							],
							'level'   => [
								'type'              => 'string',
								'required'          => true,
								'validate_callback' => 'rest_validate_request_arg',
							],
							'country' => [
								'type'              => 'string',
								'required'          => true,
								'validate_callback' => 'rest_validate_request_arg',
							],
							'within'  => [
								'type'              => 'string',
								'validate_callback' => 'rest_validate_request_arg',
							],

							/*
							 * There is deliberately NO `provider` arg. Which provider
							 * answers a suggest call is a STORE decision resolved
							 * server-side through the D15 fallback chain
							 * (Location_Service::provider_for_level()) — a request may
							 * not name one, so the schema does not even give a client
							 * somewhere to put a value that would only ever be ignored.
							 */
						],
					],
				]
			);

			register_rest_route(
				'woodev/v1',
				'/location/select',
				[
					[
						'methods'  => 'POST',
						'callback' => [ $this, 'handle_select_request' ],

						/*
						 * NOT `__return_true`, unlike the read above. This route is the
						 * customer CONFIRMING a location — it persists into the
						 * server-side customer-location store — so it drives a write. A
						 * capability check is impossible here (guests place orders), so
						 * the nonce is the whole barrier (mirrors
						 * Pickup_Controller::check_select_permission() exactly).
						 */
						'permission_callback' => [ $this, 'check_select_permission' ],
						'args'                => [

							/*
							 * No nested schema beyond `type => object`: `record`'s real
							 * shape is Location_Record's own contract, validated by
							 * Location_Record::from_array() in handle_select_request().
							 * Declaring a parallel REST args schema for every one of that
							 * contract's fields would just be a second, divergence-prone
							 * copy of validation that already lives in exactly one place
							 * (same reasoning the license-command controller documents
							 * for registering no args schema at all).
							 */
							'record' => [
								'type'     => 'object',
								'required' => true,
							],
						],
					],
				]
			);

			register_rest_route(
				'woodev/v1',
				'/location/list',
				[
					[
						'methods'  => 'GET',
						'callback' => [ $this, 'handle_list_request' ],

						// Intentionally public read — same reasoning as `/suggest` above
						// (see the class docblock's SECURITY paragraph): every returned
						// field is a neutral Location_Record component or the
						// explicitly-escaped `label`, never a provider credential.
						'permission_callback' => '__return_true',
						'args'                => [
							'level'   => [
								'type'              => 'string',
								'required'          => true,
								'validate_callback' => 'rest_validate_request_arg',
							],
							'country' => [
								'type'              => 'string',
								'required'          => true,
								'validate_callback' => 'rest_validate_request_arg',
							],
							'within'  => [
								'type'              => 'string',
								'validate_callback' => 'rest_validate_request_arg',
							],
							'limit'   => [
								'type'              => 'integer',
								'validate_callback' => 'rest_validate_request_arg',

								// Clamped server-side to [1, LIST_HARD_CAP] by the
								// callback — see handle_list_request()'s own docblock
								// (PR #304 review finding 5). A client value <= 0,
								// missing, or malformed simply falls back to the hard
								// cap; there is no error path for this arg.
							],

							// Deliberately no `q` (this is enumeration, not a query-driven
							// search — spec D7/Location_Provider::list_localities()) and no
							// `provider` (same reasoning as `/suggest`'s own args block: the
							// D15-adjacent chain — Location_Service::provider_for_list() —
							// resolves it server-side, never the client).
						],
					],
				]
			);
		}

		/**
		 * Guards the `/select` route with the REST cookie nonce.
		 *
		 * Identical semantics to {@see Pickup_Controller::check_select_permission()}
		 * — see that method's own docblock for the full explanation of WHY this is
		 * not dead code despite `/select` living behind core's own
		 * `rest_cookie_check_errors()`: that check rejects an INVALID nonce before
		 * any permission callback runs, but lets a request with NO `X-WP-Nonce`
		 * header through as anonymous — this is what actually catches a bare
		 * cross-site POST. Gotchas `rest-cookie-nonce-auth-semantics` (a missing
		 * nonce demotes to anonymous rather than erroring; only an INVALID nonce
		 * is caught by core) and `rest-endpoint-not-for-browser-cookie-auth`
		 * (why this MUST stay a nonce check, not an `is_user_logged_in()` gate)
		 * both apply directly.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request object.
		 *
		 * @return true|\WP_Error
		 */
		public function check_select_permission( $request ) {

			$nonce = $request->get_header( 'X-WP-Nonce' );

			if ( ! is_string( $nonce ) || '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				return new \WP_Error(
					'woodev_location_invalid_nonce',
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
		 * Handles a suggest request.
		 *
		 * Degradation (spec §4.7, D15): "the whole layer is inactive", "no
		 * configured provider serves THIS level", and "the request's country is
		 * well-formed but not one the LEVEL'S RESOLVED provider covers" ({@see
		 * Location_Service::is_country_supported()}, called WITH `$level` —
		 * block PR-B) are DELIBERATELY collapsed
		 * into the SAME outcome — `{ suggestions: [] }`, HTTP 200 — because
		 * {@see Location_Service::provider_for_level()} itself already answers
		 * `null` for the first two (its `get_active_provider()` returns `null`
		 * while the registry gate is closed, so the D15 chain never even resolves
		 * a fallback), and the country check exists precisely so an unsupported
		 * country never even reaches the provider (a P2 review fix — a request
		 * for a country the provider does not cover was otherwise still spending
		 * upstream quota for a result that was always going to be unusable). A
		 * read endpoint answering "nothing to show yet" rather than an error is
		 * this codebase's own established idiom for exactly this shape of
		 * degradation — {@see Field_Source_Controller::get_field_source()}
		 * returns an empty list for an unknown field id, and
		 * {@see Pickup_Controller::get_points_data()} returns an empty point list
		 * for an unusable query — both 200, never 404/500. `/select` (a WRITE) is
		 * the one route in this pair that DOES 404 when the layer is inactive
		 * ({@see self::handle_select_request()}), because attempting to PERSIST
		 * against nothing is a genuine error a read never has. A MALFORMED
		 * country (not a well-formed ISO-3166 alpha-2 code) is a different case
		 * entirely and keeps its own 400 — see {@see self::build_scope()}.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request object.
		 *
		 * @return \WP_REST_Response|\WP_Error|array{suggestions: array<int, array<string, mixed>>}
		 */
		public function handle_suggest_request( $request ) {

			if ( $this->is_rate_limited( 'woodev_location_sug_rl_', self::SUGGEST_RATE_LIMIT_MAX ) ) {
				return $this->rate_limited_error();
			}

			$query      = $this->normalize_param( $request->get_param( 'q' ) );
			$query_length = $this->mb_length( $query );

			if ( $query_length < self::MIN_QUERY_LENGTH || $query_length > self::MAX_PARAM_LENGTH ) {
				return new \WP_Error(
					'woodev_location_invalid_query',
					__( 'Слишком короткий или слишком длинный поисковый запрос.', 'woodev-plugin-framework' ),
					[ 'status' => 400 ]
				);
			}

			$level = $this->normalize_param( $request->get_param( 'level' ) );

			if ( ! in_array( $level, Location_Record::LEVELS, true ) ) {
				return new \WP_Error(
					'woodev_location_invalid_level',
					__( 'Некорректный уровень поиска.', 'woodev-plugin-framework' ),
					[ 'status' => 400 ]
				);
			}

			// Deliberately never reads `$request->get_param( 'provider' )` — see the
			// class docblock and register_routes()'s own comment on this route.
			$provider = $this->service->provider_for_level( $level );

			if ( null === $provider ) {
				return rest_ensure_response( [ 'suggestions' => [] ] );
			}

			$country = $this->normalize_param( $request->get_param( 'country' ) );
			$within  = $this->cap_length( $this->normalize_param( $request->get_param( 'within' ) ), self::MAX_PARAM_LENGTH );

			try {
				$scope = $this->build_scope( $country, $level, $within );
			} catch ( \InvalidArgumentException $exception ) {
				return new \WP_Error(
					'woodev_location_invalid_country',
					__( 'Некорректный код страны.', 'woodev-plugin-framework' ),
					[ 'status' => 400 ]
				);
			}

			/*
			 * A well-formed but UNSUPPORTED country (the provider that will
			 * actually serve THIS LEVEL — see $provider above — simply does not
			 * cover it — spec D2/D15, Location_Service::is_country_supported())
			 * degrades exactly like "no provider for this level" above: 200 +
			 * empty, BEFORE the provider is ever called. Placed AFTER
			 * build_scope()'s own format validation deliberately — a MALFORMED
			 * country keeps its own 400 above; is_country_supported() would
			 * otherwise mask that same malformed input as an unsupported-country
			 * 200 (it degrades to false for both).
			 *
			 * The `$level` argument is load-bearing (D15 gate fix, block PR-B):
			 * omitting it would gate against the ACTIVE provider's own country
			 * list regardless of which provider the D15 chain actually resolved
			 * for this level above — wrongly suppressing a country only the
			 * FALLBACK covers, or wrongly admitting one the fallback does NOT
			 * cover when the active provider merely happens to list it.
			 */
			if ( ! $this->service->is_country_supported( $country, $level ) ) {
				return rest_ensure_response( [ 'suggestions' => [] ] );
			}

			try {
				$records = $provider->suggest( $query, $scope );
			} catch ( \Throwable $exception ) {
				$this->log_failure( 'suggest', $exception );

				return $this->upstream_error();
			}

			return rest_ensure_response( [ 'suggestions' => $this->to_response_records( $records ) ] );
		}

		/**
		 * Handles a list (enumeration) request (Task 13; spec D7).
		 *
		 * Degradation deliberately differs from `/suggest` above (see that
		 * method's own docblock for why the read/write distinction there does
		 * NOT apply the same way here): a well-formed country the resolved
		 * `list`-capable provider does not cover, or no provider anywhere in the
		 * D15-adjacent chain ({@see Location_Service::provider_for_list()})
		 * declaring `list` at all, is a 404 — mirroring `/select`'s
		 * inactive-layer 404 ({@see self::handle_select_request()}), not
		 * `/suggest`'s 200+empty. This is deliberate: `related-list`/
		 * `ajax-select2` modes are only ever OFFERED to the store setting when
		 * the active provider already declares `list`
		 * ({@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::get_offered_field_modes()}),
		 * so a client legitimately reaching this route at all already believes
		 * the capability exists — a 404 here is a genuine "this stopped being
		 * true" signal, not the routine "nothing typed yet" a `/suggest` empty
		 * read represents.
		 *
		 * The response is capped at {@see self::LIST_HARD_CAP} records (PR #304
		 * review finding 5): the provider contract itself promises "every
		 * locality within scope" with no size bound, and a guest customer has no
		 * way to narrow an unscoped `settlement`/`address` enumeration via
		 * `within` either (it only resolves against an already-persisted
		 * customer record, {@see self::build_scope()}), so an unbounded request
		 * could ask a dictionary provider for its entire city table, full `raw`
		 * payloads included, in one response. The optional `limit` request arg
		 * narrows the cap further (clamped to `[1, LIST_HARD_CAP]`; a missing or
		 * out-of-range value falls back to the hard cap outright, never an
		 * error). The response is honest about truncation — a `truncated: true`
		 * flag when the provider returned more than what was actually sent, so
		 * a client can tell "this is everything" from "there is more, refine
		 * `within`" instead of a silently-cut list reading as the former.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Capped at {@see self::LIST_HARD_CAP} records, with an
		 *              optional clamped `limit` arg and a `truncated` response
		 *              flag (PR #304 review finding 5).
		 *
		 * @param \WP_REST_Request $request request object.
		 *
		 * @return \WP_REST_Response|\WP_Error|array{localities: array<int, array<string, mixed>>, truncated: bool}
		 */
		public function handle_list_request( $request ) {

			if ( $this->is_rate_limited( 'woodev_location_list_rl_', self::LIST_RATE_LIMIT_MAX ) ) {
				return $this->rate_limited_error();
			}

			$level = $this->normalize_param( $request->get_param( 'level' ) );

			if ( ! in_array( $level, Location_Record::LEVELS, true ) ) {
				return new \WP_Error(
					'woodev_location_invalid_level',
					__( 'Некорректный уровень поиска.', 'woodev-plugin-framework' ),
					[ 'status' => 400 ]
				);
			}

			$country = $this->normalize_param( $request->get_param( 'country' ) );
			$within  = $this->cap_length( $this->normalize_param( $request->get_param( 'within' ) ), self::MAX_PARAM_LENGTH );

			$raw_limit = $request->get_param( 'limit' );
			$limit     = null === $raw_limit ? self::LIST_HARD_CAP : (int) $raw_limit;
			$limit     = $limit > 0 ? min( $limit, self::LIST_HARD_CAP ) : self::LIST_HARD_CAP;

			try {
				$scope = $this->build_scope( $country, $level, $within );
			} catch ( \InvalidArgumentException $exception ) {
				return new \WP_Error(
					'woodev_location_invalid_country',
					__( 'Некорректный код страны.', 'woodev-plugin-framework' ),
					[ 'status' => 400 ]
				);
			}

			// Deliberately never reads `$request->get_param( 'provider' )` — same
			// reasoning as `/suggest` above (see the class docblock and
			// register_routes()'s own comment on this route).
			$provider = $this->service->provider_for_list( $country );

			if ( null === $provider ) {
				return new \WP_Error(
					'woodev_location_list_unavailable',
					__( 'Список населённых пунктов сейчас недоступен.', 'woodev-plugin-framework' ),
					[ 'status' => 404 ]
				);
			}

			try {
				$records = $provider->list_localities( $scope );
			} catch ( \Throwable $exception ) {
				$this->log_failure( 'list', $exception );

				return $this->upstream_error();
			}

			$truncated = count( $records ) > $limit;
			$records   = array_slice( $records, 0, $limit );

			return rest_ensure_response(
				[
					'localities' => $this->to_response_records( $records ),
					'truncated'  => $truncated,
				]
			);
		}

		/**
		 * Handles a select (persist) request.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request object.
		 *
		 * @return \WP_REST_Response|\WP_Error|array{current: array{key: string, level: string}, persisted: bool}
		 */
		public function handle_select_request( $request ) {

			if ( $this->is_rate_limited( 'woodev_location_sel_rl_', self::SELECT_RATE_LIMIT_MAX ) ) {
				return $this->rate_limited_error();
			}

			if ( ! $this->service->is_active() ) {
				return new \WP_Error(
					'woodev_location_inactive',
					__( 'Сервис определения местоположения сейчас недоступен.', 'woodev-plugin-framework' ),
					[ 'status' => 404 ]
				);
			}

			$raw = $request->get_param( 'record' );

			if ( ! is_array( $raw ) ) {
				return new \WP_Error(
					'woodev_location_invalid_record',
					__( 'Некорректные данные о местоположении.', 'woodev-plugin-framework' ),
					[ 'status' => 400 ]
				);
			}

			try {
				$record = Location_Record::from_array( $raw );
			} catch ( \InvalidArgumentException $exception ) {
				return new \WP_Error(
					'woodev_location_invalid_record',
					__( 'Некорректные данные о местоположении.', 'woodev-plugin-framework' ),
					[ 'status' => 400 ]
				);
			}

			// Always EXPLICIT (spec D11): a customer's own selection through this
			// route is never an implicit/default guess — only
			// Location_Service::resolve_default() (a later task) writes implicit
			// records.
			$persisted = $this->service->set_customer_record( $record, false );

			/*
			 * Response shape chosen for what the D8 client flow needs NEXT: the
			 * client's very next action is to fire
			 * `jQuery(document.body).trigger('update_checkout')` itself (D8), and a
			 * later task exposes a `current: { key, level }` block in the checkout
			 * config (Task 9) — reusing that EXACT shape here lets the client update
			 * its own local "current" state immediately from this response, without
			 * waiting on a full checkout-config refetch, and without the two call
			 * sites ever disagreeing about the shape. `persisted` surfaces
			 * Customer_Location_Store::set()'s own boolean (it can legitimately be
			 * `false` — a guest whose session/cart cookie has not initialized yet,
			 * gotcha `guest-session-write-needs-the-cart-cookie` — a silent failure
			 * a later client task needs to be able to detect rather than assume
			 * away). The full record is NOT echoed back: the client already holds
			 * the exact same shape it just posted (round-tripped from `/suggest`),
			 * so resending it would be pure duplication.
			 */
			return rest_ensure_response(
				[
					'current'   => [
						'key'   => $record->key(),
						'level' => $record->level(),
					],
					'persisted' => $persisted,
				]
			);
		}

		/**
		 * Builds the lookup scope for a suggest call, resolving the optional
		 * `within` parent constraint.
		 *
		 * `within` is a locality KEY (not a components blob) the client believes
		 * names its current parent. Since this controller has no "look up a
		 * record by bare key" mechanism, it is resolved by checking it against
		 * {@see Location_Service::get_customer_record()} — the record the SAME
		 * client itself persisted moments earlier via `/select` (D8: every
		 * cascade step persists before the next step's suggest call runs). A key
		 * that does not match (unknown, stale, no customer record at all) is
		 * treated exactly like an ABSENT `within` — never an error (spec Task 8:
		 * "stale client state must never brick the field") — and a key that DOES
		 * match but sits at the wrong level for this search (e.g. a region key
		 * "within" a region-level search) is refused by
		 * {@see Location_Scope::within()} itself and swallowed the same way.
		 *
		 * @since 2.0.2
		 *
		 * @param string $country    Normalized ISO-3166 alpha-2 country code.
		 * @param string $level      One of {@see Location_Record::LEVELS} — already validated by the caller.
		 * @param string $within_key Normalized `within` param, possibly `''`.
		 *
		 * @return Location_Scope
		 *
		 * @throws \InvalidArgumentException When `$country` is not a well-formed
		 *                                    ISO-3166 alpha-2 code — the caller
		 *                                    converts this to a 400.
		 */
		private function build_scope( string $country, string $level, string $within_key ): Location_Scope {

			if ( '' !== $within_key ) {
				$current = $this->service->get_customer_record();

				if ( null !== $current && $current['record']->key() === $within_key ) {
					try {
						return Location_Scope::within( $current['record'], $level );
					} catch ( \InvalidArgumentException $exception ) {
						// Level-ordering mismatch — treated exactly like an unknown key:
						// fall through to the country-wide scope below.
					}
				}

				// No match at all (unknown/stale key, or no customer record yet) —
				// deliberately silent, as if `within` had never been sent.
			}

			return Location_Scope::for_country( $country, $level );
		}

		/**
		 * Maps raw provider records into the wire shape (spec Task 8): shared by
		 * `/suggest` (its own `suggestions` array) and `/list` (its own
		 * `localities` array, Task 13) — same shape either way, `{ key, label,
		 * level, record }`. `label` is explicitly escaped for direct display;
		 * `record` is the record's OWN `to_array()`, UNTOUCHED, so the client can
		 * round-trip it back to `/select` verbatim (D12/D5).
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Renamed from `to_response_suggestions()` — shared by
		 *              `/list` too now (Task 13).
		 *
		 * @param Location_Record[] $records Provider records (suggest matches or a full enumeration).
		 *
		 * @return array<int, array{key: string, label: string, level: string, record: array<string, mixed>}>
		 */
		private function to_response_records( array $records ): array {

			$mapped = [];

			foreach ( $records as $record ) {

				if ( ! $record instanceof Location_Record ) {
					continue; // Defensive: a misbehaving provider must not break the field.
				}

				$mapped[] = [
					'key'    => $record->key(),
					'label'  => esc_html( $record->label() ),
					'level'  => $record->level(),
					'record' => $record->to_array(),
				];
			}

			return array_values( $mapped );
		}

		/**
		 * Sanitizes one free-text request param without capping it — length
		 * bounds differ per param ({@see MIN_QUERY_LENGTH}/{@see MAX_PARAM_LENGTH}
		 * for `q`, {@see MAX_PARAM_LENGTH} only for `within`, no bound for
		 * `level`/`country`), so capping is the CALLER's job, not this one's.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $raw Raw request param value.
		 *
		 * @return string
		 */
		private function normalize_param( $raw ): string {
			return (string) wc_clean( wp_unslash( (string) ( $raw ?? '' ) ) );
		}

		/**
		 * Multibyte-aware string length — Cyrillic queries are the common case
		 * here, not an edge case (mirrors {@see Rest_Rate_Limit_Trait::cap_length()}'s
		 * own `mb_substr`/`substr` fallback pair).
		 *
		 * @since 2.0.2
		 *
		 * @param string $value Value to measure.
		 *
		 * @return int
		 */
		private function mb_length( string $value ): int {
			return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
		}

		/**
		 * Builds the "provider service unavailable" error.
		 *
		 * @since 2.0.2
		 *
		 * @return \WP_Error
		 */
		private function upstream_error(): \WP_Error {
			return new \WP_Error(
				'woodev_location_upstream_error',
				__(
					'Сервис определения местоположения временно недоступен. Попробуйте обновить страницу позже.',
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
				'woodev_location_rate_limited',
				__( 'Слишком много запросов. Пожалуйста, подождите немного.', 'woodev-plugin-framework' ),
				[ 'status' => 429 ]
			);
		}

		/**
		 * Logs a swallowed provider exception's real message — same
		 * swallowed-exception diagnostic convention
		 * {@see Pickup_Controller::log_carrier_failure()} uses.
		 *
		 * @since 2.0.2
		 *
		 * @param string     $operation One of `suggest`, `list`.
		 * @param \Throwable $exception The caught failure.
		 *
		 * @return void
		 */
		private function log_failure( string $operation, \Throwable $exception ): void {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for a
			// provider failure; the browser only ever sees a generic 502.
			error_log(
				sprintf(
					'[woodev] location %s failed: %s',
					$operation,
					$exception->getMessage()
				)
			);
		}

		// is_rate_limited(), get_client_ip() and cap_length() are provided by
		// Rest_Rate_Limit_Trait (shared with Field_Source_Controller and
		// Pickup_Controller) — see that trait for the rate-limit mechanism and its
		// proxy/IPv6 caveats.
	}

endif;
