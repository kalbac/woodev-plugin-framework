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

use Woodev\Framework\Http\Rest_Rate_Limit_Trait;
use Woodev\Framework\Shipping\Location\Location_Provider;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Location\Location_Scope;
use Woodev\Framework\Shipping\Location\Location_Service;
use Woodev\Framework\Shipping\Location\Popular_Settlement_Entry;
use Woodev\Framework\Shipping\Location\Popular_Settlement_Verification;
use Woodev\Framework\Shipping\Location\Popular_Settlement_Verifier;

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
		 * Rate-limit budget for the admin-only `/default-locality/locate` route
		 * (Task 14) — a discrete manual preview action from the settings page,
		 * not a stream; sized well under {@see self::SELECT_RATE_LIMIT_MAX} since
		 * only admins can even reach it.
		 *
		 * @since 2.0.2
		 *
		 * @var int
		 */
		protected const ADMIN_LOCATE_RATE_LIMIT_MAX = 20;

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
		 * `within_status` value: no `within` param was sent at all.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		public const WITHIN_STATUS_NOT_REQUESTED = 'not_requested';

		/**
		 * `within_status` value: `within` named a level the customer actually
		 * picked, in the SAME country as this search, and
		 * {@see Location_Scope::within()} accepted it as a parent constraint —
		 * see {@see self::build_scope()}.
		 *
		 * Proves ONLY that the SCOPE BUILDER accepted the parent — NOT that
		 * the provider that actually ran the search honoured it (clarified,
		 * s78, adversarial review finding: an earlier reading of this value
		 * treated `applied` as proof the search was narrowed). A mixed-owner
		 * chain can still hand a parent from one provider to another
		 * provider's `suggest()`/`list_localities()` call; whether that
		 * provider could actually narrow by it is invisible from this field
		 * alone — see gotcha `within-applied-reports-the-scope-builder-not-the-provider`
		 * (#333) and the residual cross-provider gap tracked at #353.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		public const WITHIN_STATUS_APPLIED = 'applied';

		/**
		 * `within_status` value: `within` named a key that matches NOTHING in
		 * the customer's own chain (unknown, stale, already superseded, or the
		 * customer has no chain at all yet) — see {@see self::build_scope()}.
		 * Silently degraded to a country-wide scope, same as before this field
		 * existed (issue #330's own rule: "stale client state must never brick
		 * the field"), but now VISIBLE.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		public const WITHIN_STATUS_UNKNOWN_KEY = 'unknown_key';

		/**
		 * `within_status` value: `within` matched a chain record, but that
		 * record's country differs from this search's own `country` — refused
		 * rather than silently moving the search to the parent's country (see
		 * {@see self::build_scope()}'s own comment on why this is an
		 * adversarial-review finding, not merely unhelpful).
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		public const WITHIN_STATUS_CROSS_COUNTRY = 'cross_country';

		/**
		 * `within_status` value: `within` matched a chain record in the right
		 * country, but at the WRONG level for this search (e.g. a region key
		 * "within" a region-level search) — refused by
		 * {@see Location_Scope::within()} itself.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		public const WITHIN_STATUS_BAD_LEVEL = 'bad_level';

		/**
		 * `within_status` value: the client DID send a `within` param, but no
		 * provider serves the requested `$level` at all
		 * ({@see Location_Service::provider_for_level()} -> `null`) — there is
		 * nothing to resolve `within` against, and `build_scope()` never even
		 * runs on this branch (adversarial review finding, s78 — the field
		 * used to report {@see self::WITHIN_STATUS_NOT_REQUESTED} on this
		 * branch UNCONDITIONALLY, including when the client had in fact sent a
		 * `within`; that lied about a param it never inspected). A request
		 * that sent NO `within` on this same branch still reports
		 * `not_requested` — this value exists solely to keep that constant
		 * truthful to its own docblock ("no `within` param was sent at all").
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		public const WITHIN_STATUS_UNSERVED_LEVEL = 'unserved_level';

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
							 * There is deliberately NO `provider` arg on this PUBLIC route.
							 * Which provider answers a suggest call is a STORE decision
							 * resolved server-side through the D15 fallback chain
							 * (Location_Service::provider_for_level()) — a shopper must
							 * NEVER get to choose which provider serves them, so the
							 * schema does not even give a client somewhere to put a value
							 * that would only ever be ignored (D4). The ADMIN counterpart
							 * below (`/location/default-locality/suggest`) DOES accept an
							 * optional `provider` override — see that route's own args
							 * block — because the administrator, gated by
							 * check_admin_permission(), is precisely the person CHOOSING
							 * which provider the default-locality picker previews (issue
							 * #380); see self::perform_suggest()'s own docblock for the
							 * full reasoning behind the split.
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

			/*
			 * Admin-only routes (Task 14; spec D11/§4.6) backing the "fixed"
			 * default-locality policy's picker: the merchant searches for a
			 * locality (and, when the active provider supports it, previews a
			 * geo-IP lookup) from the settings page. Persisting the CHOSEN
			 * record is NOT a route here — it goes through the generic settings
			 * save path (`Location_Provider_Registry::SETTING_DEFAULT_LOCALITY_RECORD`,
			 * a plain settings-API field the admin React surface already knows
			 * how to write via Woodev_Abstract_Settings::update_value()), the
			 * same as every other store-level Location setting. Both routes are
			 * gated by check_admin_permission() — NOT `__return_true` like
			 * `/suggest`/`/list` above: a bare capability check is fine here
			 * because, unlike the customer-facing routes, a guest never needs
			 * to reach it.
			 */

			register_rest_route(
				'woodev/v1',
				'/location/default-locality/suggest',
				[
					[
						'methods'             => 'GET',
						'callback'            => [ $this, 'handle_admin_suggest_request' ],
						'permission_callback' => [ $this, 'check_admin_permission' ],
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
							 * Optional ADMIN-ONLY override (issue #380) — unlike the
							 * public `/suggest` route above, THIS route DOES accept
							 * `provider`: the administrator picking the record IS the
							 * person choosing which provider serves it, and this route
							 * is already gated by check_admin_permission() above, so
							 * there is no shopper here to protect from steering their
							 * own provider (the reasoning that keeps the public route's
							 * schema from ever exposing this arg — see that route's own
							 * comment). self::perform_suggest() validates this against
							 * the registry's own registered ids — an unknown id is a
							 * 400, never a silent fallback to the stored provider — and,
							 * when eligible, resolves that EXACT named provider instead
							 * of walking Location_Service::provider_for_level()'s D15
							 * chosen -> fallback chain; see that method's own docblock.
							 */
							'provider' => [
								'type'              => 'string',
								'validate_callback' => 'rest_validate_request_arg',
							],
						],
					],
				]
			);

			register_rest_route(
				'woodev/v1',
				'/location/default-locality/locate',
				[
					[
						'methods'             => 'GET',
						'callback'            => [ $this, 'handle_admin_locate_request' ],
						'permission_callback' => [ $this, 'check_admin_permission' ],
						'args'                => [
							'ip' => [
								'type'              => 'string',
								'validate_callback' => 'rest_validate_request_arg',

								// Optional: omitted, this previews what the `geoip` policy
								// would resolve for the ADMIN's OWN current request IP
								// (WC_Geolocation::get_ip_address()) — see
								// handle_admin_locate_request()'s own docblock. Given
								// explicitly, it lets the merchant preview a DIFFERENT
								// address (e.g. a known store/warehouse IP) before turning
								// the policy on store-wide.
							],
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
		 * Bridges the WooCommerce cart/session on a plain REST request — the
		 * SAME `wc_load_cart()` bridge
		 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::current_cart_weight_grams()}
		 * already uses, and for the identical reason (see that method's own
		 * docblock): WooCommerce does not initialize `WC()->session` for a
		 * plain custom REST route the way it does for `wc_load_cart()`'s own
		 * callers (only the core Store API calls it). Without this bridge, a
		 * guest's session is never started on the public `/suggest`/`/list`
		 * reads, so {@see Location_Service::get_customer_record()}'s lazy
		 * default-locality trigger (Task 14) can never actually PERSIST what it
		 * resolves — every request re-resolves from scratch (a fresh provider
		 * call on every debounced keystroke, for `geoip`), exactly gotcha
		 * `guest-session-write-needs-the-cart-cookie` describes (review finding
		 * F1). {@see Location_Service}'s own per-request memoization
		 * ({@see Location_Service}'s `$unpersisted_default`) already stops a
		 * failed persist from re-triggering resolution WITHIN one request; this
		 * bridge is what lets the persist actually succeed so the NEXT request
		 * from the same guest gets it for free too.
		 *
		 * Called from every customer-facing handler that touches the customer
		 * record — the two that READ it ({@see self::perform_suggest()},
		 * {@see self::handle_list_request()}) and the one that WRITES it
		 * ({@see self::handle_select_request()}). Never from the two admin-only
		 * `/default-locality/*` routes, which run for a logged-in admin whose
		 * {@see \Woodev\Framework\Shipping\Location\Customer_Location_Store}
		 * reads/writes already go through user meta regardless of session state.
		 *
		 * The write was originally left out (issue #324), because this list was
		 * first written as "handlers that can reach `get_customer_record()`" — a
		 * READ-shaped rule. It cost a guest their locality scope entirely. When a
		 * seam is described by the callers it happens to have, the caller it does
		 * not have yet is invisible: describe it by the CONDITION instead — this
		 * one is needed wherever a guest's session must exist.
		 *
		 * A no-op when a session is already loaded, or when `wc_load_cart()`
		 * itself does not exist (WooCommerce < 3.6, or WooCommerce absent, e.g.
		 * in a unit test).
		 *
		 * `protected`, not `private`: the same WC-global test seam every other
		 * class in this codebase uses this visibility for (mirrors
		 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler::wc_cart()} /
		 * `wc_load_cart_available()`) — a probe overrides this to a spy without
		 * `WC()`/`wc_load_cart()` needing to be real functions in the unit-test
		 * process.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		protected function bridge_wc_session(): void {
			if ( function_exists( 'WC' ) && null !== WC()->session ) {
				return;
			}

			if ( function_exists( 'wc_load_cart' ) ) {
				wc_load_cart();
			}
		}

		/**
		 * Guards the two admin-only `/default-locality/*` routes (Task 14) with
		 * the WooCommerce shop-manager capability — mirrors
		 * {@see \Woodev\Framework\Shipping\Rest_Api\Abstract_Warehouses_Controller::check_permissions()}'s
		 * own `wc_rest_check_manager_permissions()`-first, `manage_woocommerce`
		 * fallback precedent exactly. UNLIKE `/suggest`/`/select`/`/list` above —
		 * a capability check is the right tool here specifically because these
		 * two routes have no legitimate guest caller at all (only the settings
		 * page's own admin picker reaches them), whereas `/select`'s nonce-only
		 * gate exists precisely BECAUSE a guest customer legitimately needs it.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request object.
		 *
		 * @return true|\WP_Error
		 */
		public function check_admin_permission( $request ) {

			$allowed = function_exists( 'wc_rest_check_manager_permissions' )
				? wc_rest_check_manager_permissions( 'settings', 'read' )
				: current_user_can( 'manage_woocommerce' );

			if ( ! $allowed ) {
				return new \WP_Error(
					'woodev_location_admin_forbidden',
					__( 'У вас нет прав для выполнения этого действия.', 'woodev-plugin-framework' ),
					[ 'status' => rest_authorization_required_code() ]
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
			return $this->perform_suggest( $request, 'woodev_location_sug_rl_' );
		}

		/**
		 * Handles an ADMIN suggest request for the `fixed` default-locality
		 * policy's picker (Task 14; spec D11/§4.6) — otherwise identical to
		 * {@see self::handle_suggest_request()} (same hardening, same D15
		 * provider resolution via {@see Location_Service::provider_for_level()}
		 * whenever no override is given, so a record the merchant picks here is
		 * guaranteed resolvable the same way at runtime), gated by
		 * {@see self::check_admin_permission()} instead of the public routes'
		 * `__return_true`, and rate-limited under its OWN counter so an admin
		 * session browsing the picker can never exhaust the public
		 * customer-facing budget (or vice versa).
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Reads the request's own `provider` param and threads it
		 *              into {@see self::perform_suggest()} as an explicit
		 *              override (issue #380) — the ONE difference from
		 *              {@see self::handle_suggest_request()}, which never reads
		 *              that param at all. An absent/empty `provider` keeps the
		 *              original D15-chain behaviour unchanged.
		 *
		 * @param \WP_REST_Request $request request object.
		 *
		 * @return \WP_REST_Response|\WP_Error|array{suggestions: array<int, array<string, mixed>>}
		 */
		public function handle_admin_suggest_request( $request ) {
			$provider_override = $this->normalize_param( $request->get_param( 'provider' ) );

			return $this->perform_suggest(
				$request,
				'woodev_location_admin_sug_rl_',
				'' === $provider_override ? null : $provider_override
			);
		}

		/**
		 * Shared implementation behind {@see self::handle_suggest_request()} and
		 * {@see self::handle_admin_suggest_request()} — see the former's own
		 * docblock for the degradation rules; both routes must degrade
		 * identically, so this is the ONE place that logic lives.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 An empty `country` param now falls back through
		 *              {@see \Woodev\Framework\Shipping\Location\Location_Service::resolve_default_country()}
		 *              instead of reaching `build_scope()`'s own 400 (issue #296).
		 * @since 2.0.2 Response gained `within_applied` (issue #330's third
		 *              point): a `within` that failed to resolve used to be
		 *              indistinguishable from a genuine country-wide search — see
		 *              the `within_applied` line below for the exact semantics,
		 *              including the no-`within`-requested case.
		 * @since 2.0.2 Response gained `within_status` (#333) — the ONE of
		 *              `self::WITHIN_STATUS_*` {@see self::build_scope()}
		 *              actually resolved, so a caller can tell "unknown key"
		 *              from "cross-country" from "wrong level" instead of the
		 *              single `within_applied` boolean collapsing all three
		 *              (and "never requested") into the same `false`.
		 * @since 2.0.2 Gained the optional `$provider_override` parameter
		 *              (issue #380): {@see self::handle_admin_suggest_request()}
		 *              threads its request's own `provider` param through here;
		 *              {@see self::handle_suggest_request()} (the public route)
		 *              keeps passing `null` — it never even reads that param —
		 *              so the public route's D4 refusal is structural, not a
		 *              runtime check that could be bypassed. A non-`null`
		 *              override is validated against the registry's own
		 *              registered ids (400 for an unknown one) and, when
		 *              eligible, resolves to that EXACT provider instead of
		 *              {@see Location_Service::provider_for_level()}'s D15
		 *              chosen -> fallback chain.
		 *
		 * @param \WP_REST_Request $request           request object.
		 * @param string           $rate_limit_key     Per-route rate-limit bucket prefix.
		 * @param string|null      $provider_override  Admin-only explicit provider id
		 *                                              override, or `null` for the
		 *                                              ordinary D15 chain resolution.
		 *
		 * @return \WP_REST_Response|\WP_Error|array{suggestions: array<int, array<string, mixed>>, within_applied: bool, within_status: string}
		 */
		private function perform_suggest( $request, string $rate_limit_key, ?string $provider_override = null ) {

			if ( $this->is_rate_limited( $rate_limit_key, self::SUGGEST_RATE_LIMIT_MAX ) ) {
				return $this->rate_limited_error();
			}

			// See self::bridge_wc_session()'s own docblock (review finding F1):
			// without this, build_scope() below's get_customer_record() call can
			// never persist a resolved default-locality guess on a guest REST
			// request.
			$this->bridge_wc_session();

			$query        = $this->normalize_param( $request->get_param( 'q' ) );
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

			/*
			 * `$provider_override` is the ONLY path by which `perform_suggest()`
			 * ever honours a caller-named provider — see this method's own
			 * `@since` note above. `self::handle_suggest_request()` (the public
			 * route) always passes `null` here and never even reads the
			 * request's own `provider` param, so a shopper can never reach the
			 * branch below (D4) — see the class docblock and register_routes()'s
			 * own comment on the public `/suggest` route.
			 */
			if ( null !== $provider_override ) {
				if ( ! $this->service->has_provider( $provider_override ) ) {
					return new \WP_Error(
						'woodev_location_unknown_provider',
						__( 'Неизвестный провайдер.', 'woodev-plugin-framework' ),
						[ 'status' => 400 ]
					);
				}

				// Level-blind eligibility check (country-blind too, `null`) — mirrors
				// Location_Service::provider_for_level()'s own first pass, just
				// anchored to the ONE named id instead of walking chosen -> fallback.
				// provider_by_id() itself applies is_configured() and the
				// level-eligibility check (Location_Service::provider_serves_level());
				// a registered-but-INELIGIBLE override (unconfigured, or configured
				// but not serving this level at all) degrades EXACTLY like "no
				// provider for this level" below — see this method's own `@since`
				// note: the registry membership check above is what turns an
				// UNKNOWN id into a 400; a KNOWN-but-ineligible one is never an
				// error, only the ordinary empty-suggestions degradation.
				$provider = $this->service->provider_by_id( $provider_override, $level );
			} else {
				$provider = $this->service->provider_for_level( $level );
			}

			if ( null === $provider ) {
				// No scope is ever built on this branch — nothing was looked up,
				// so no parent constraint could have been applied.
				// `within_applied` stays `false` UNCONDITIONALLY here, mirroring
				// its own established precedent on this exact branch: when no
				// provider serves this level AT ALL, there is nothing to resolve
				// `within` against regardless of the request, a bigger
				// degradation than any single `within_status` value below names.
				//
				// `within_status`, unlike `within_applied`, DOES tell apart
				// whether the client actually sent a `within` (adversarial
				// review finding, s78 — FIX 4): reporting `not_requested`
				// unconditionally here used to lie about a param this branch
				// never even inspected. `self::WITHIN_STATUS_NOT_REQUESTED`'s
				// own docblock promises "no `within` param was sent at all" —
				// a promise this branch could not keep for a client that DID
				// send one, since build_scope() (the only place that would
				// have read it) never runs when there is no provider to search
				// with.
				$within_requested = '' !== $this->normalize_param( $request->get_param( 'within' ) );

				return rest_ensure_response(
					[
						'suggestions'    => [],
						'within_applied' => false,
						'within_status'  => $within_requested
							? self::WITHIN_STATUS_UNSERVED_LEVEL
							: self::WITHIN_STATUS_NOT_REQUESTED,
					]
				);
			}

			$country = $this->normalize_param( $request->get_param( 'country' ) );

			// Issue #296: a checkout with no country field at all (common for a single-country
			// store) sends `''` here — location-cascade.js's own `countryFor()` degrades to `''`
			// once its own client-side fallback (the live field, then
			// `config.location.defaultCountry`) has nothing left to try. Mirroring the SAME
			// fallback server-side (through the ONE shared
			// {@see \Woodev\Framework\Shipping\Location\Location_Service::resolve_default_country()}
			// {@see Checkout_Config::build_location_block()} already feeds `defaultCountry` from)
			// keeps this a genuine `/suggest` read for the store's own base country instead of the
			// 400 an un-split `''` would otherwise hit in build_scope() below. A request that DOES
			// carry a country — even an unsupported one — is never second-guessed here.
			if ( '' === $country ) {
				$country = $this->service->resolve_default_country();
			}

			$within = $this->cap_length( $this->normalize_param( $request->get_param( 'within' ) ), self::MAX_PARAM_LENGTH );

			/*
			 * `within_applied` (issue #330's third point) is read from the SCOPE
			 * OBJECT itself ({@see Location_Scope::has_parent()}) at every return
			 * point below that has one, never re-guessed from `$within` — the
			 * whole point is to report what build_scope() actually DID, not what
			 * the request merely ASKED for. Semantics:
			 * - a `within` that resolved into a real parent constraint -> `true`;
			 * - a non-empty `within` that did NOT resolve (unknown/stale key, a
			 *   level-ordering mismatch, or no customer chain at all) -> `false`,
			 *   now VISIBLE to an HTTP probe instead of being indistinguishable
			 *   from a genuine country-wide search — the silence issue #330 named
			 *   as half of why the underlying bug survived;
			 * - no `within` requested at all -> also `false`, DELIBERATELY the
			 *   same value as the failed-resolution case above: this field
			 *   answers "is this response's scope constrained to a parent", not
			 *   "did the client's within request get honored" — a client already
			 *   knows whether it sent one, so the two `false` causes never need
			 *   telling apart from this field alone, and `has_parent()` is
			 *   definitionally `false` in both.
			 *
			 * `within_status` (#333) is the field that DOES tell the two `false`
			 * causes (and the "wrong level"/"cross country" ones) apart — see
			 * {@see self::build_scope()} for exactly which value each cause
			 * maps to. `within_applied` is kept AS IS (a shipped response
			 * field) and is NOT derived from `within_status` here — both are
			 * read from the same `$scope`/`$result` independently, so a future
			 * change to one can never silently desync the other.
			 */
			try {
				$result = $this->build_scope( $country, $level, $within );
			} catch ( \InvalidArgumentException $exception ) {
				return new \WP_Error(
					'woodev_location_invalid_country',
					__( 'Некорректный код страны.', 'woodev-plugin-framework' ),
					[ 'status' => 400 ]
				);
			}

			$scope         = $result['scope'];
			$within_status = $result['within_status'];

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
			 *
			 * With a `$provider_override` (issue #380), `is_country_supported()`
			 * is bypassed entirely — that method re-resolves the D15 chain
			 * (chosen -> fallback) itself, which would silently ignore the
			 * override and check the WRONG provider's country list. The check
			 * instead runs directly against `$provider` (already resolved to
			 * the override above) via the SAME {@see Location_Service::provider_serves_level()}
			 * predicate the chain itself uses.
			 */
			$country_supported = ( null !== $provider_override )
				? $this->service->provider_serves_level( $provider, $level, $country )
				: $this->service->is_country_supported( $country, $level );

			if ( ! $country_supported ) {
				return rest_ensure_response(
					[
						'suggestions'    => [],
						'within_applied' => $scope->has_parent(),
						'within_status'  => $within_status,
					]
				);
			}

			try {
				$records = $provider->suggest( $query, $scope );
			} catch ( \Throwable $exception ) {
				$this->log_failure( $provider->get_id(), 'suggest', $exception );

				return $this->upstream_error();
			}

			return rest_ensure_response(
				[
					'suggestions'    => $this->to_response_records( $records ),
					'within_applied' => $scope->has_parent(),
					'within_status'  => $within_status,
				]
			);
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
		 * `/suggest`'s 200+empty. This is deliberate: `related-list` mode
		 * (this route's own consumer — issue #380 correction: `ajax-select2`
		 * queries `/suggest`, never this route, see
		 * {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::MODE_AJAX_SELECT2}'s
		 * own docblock) is only ever OFFERED to either axis's store setting
		 * when the active provider already declares `list`
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
		 * @since 2.0.2 Response gained `within_status` (#333) — see
		 *              {@see self::build_scope()} for the values; unlike
		 *              `/suggest` this route never shipped a `within_applied`
		 *              boolean, so `within_status` is its ONLY `within`
		 *              signal, not a supplement to one.
		 *
		 * @param \WP_REST_Request $request request object.
		 *
		 * @return \WP_REST_Response|\WP_Error|array{localities: array<int, array<string, mixed>>, truncated: bool, within_status: string}
		 */
		public function handle_list_request( $request ) {

			if ( $this->is_rate_limited( 'woodev_location_list_rl_', self::LIST_RATE_LIMIT_MAX ) ) {
				return $this->rate_limited_error();
			}

			// See self::bridge_wc_session()'s own docblock (review finding F1) —
			// same reasoning as perform_suggest()'s own call: build_scope() below
			// may also reach get_customer_record().
			$this->bridge_wc_session();

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
				$result = $this->build_scope( $country, $level, $within );
			} catch ( \InvalidArgumentException $exception ) {
				return new \WP_Error(
					'woodev_location_invalid_country',
					__( 'Некорректный код страны.', 'woodev-plugin-framework' ),
					[ 'status' => 400 ]
				);
			}

			$scope         = $result['scope'];
			$within_status = $result['within_status'];

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
				$this->log_failure( $provider->get_id(), 'list', $exception );

				return $this->upstream_error();
			}

			$truncated = count( $records ) > $limit;
			$records   = array_slice( $records, 0, $limit );

			return rest_ensure_response(
				[
					'localities'    => $this->to_response_records( $records ),
					'truncated'     => $truncated,
					'within_status' => $within_status,
				]
			);
		}

		/**
		 * Handles a select (persist) request.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Response gained `chain` (location-chain design §8;
		 *              `docs-internal/specs/2026-08-15-location-chain-design.md`):
		 *              every level in the customer's chain AFTER this write, in
		 *              the SAME `{ key, level }` shape as `current` for each
		 *              entry, keyed by level — read straight from
		 *              {@see Location_Service::get_customer_chain()} rather than
		 *              re-derived from `$record` alone, so the client can adopt
		 *              the server's own rebuilt chain wholesale and can never end
		 *              up scoping a later `within` by a key the server itself
		 *              would refuse to resolve.
		 * @since 2.0.2 Gained the D5 lazy-verification step
		 *              (popular-settlements spec D5/D6/D7,
		 *              `docs-internal/specs/2026-08-24-popular-settlements-design.md`):
		 *              a popular-list pick whose `last_verified_at` is stale is
		 *              re-checked against the owning provider INSIDE this same
		 *              request, before the customer's posted record is
		 *              persisted. Not found, or found and fresh, behaves EXACTLY
		 *              as before this step existed. Response MAY now carry
		 *              `cancelled: true` instead of the ordinary shape (D7) —
		 *              see {@see self::cancelled_stale_record_response()}.
		 *
		 * @param \WP_REST_Request $request request object.
		 *
		 * @return \WP_REST_Response|\WP_Error|array{current: array{key: string, level: string}, persisted: bool, chain: array<string, array{key: string, level: string}>}|array{cancelled: bool, reason: string, message: string, current: null, persisted: bool, chain: array<string, array{key: string, level: string}>}
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

			/*
			 * Issue #324. Before the write, never after — the write itself is what
			 * needs the session to exist.
			 *
			 * This route is the only WRITE in the layer, and it was the one route the
			 * bridge was not wired to: see bridge_wc_session()'s own docblock, whose
			 * list enumerates the handlers that READ a customer record. For a GUEST
			 * that omission is total, not partial — Customer_Location_Store::set()
			 * has nowhere to put the record but `WC()->session`, WooCommerce does not
			 * start one on a plain REST request, so the write returned `false`, this
			 * route honestly answered `persisted: false`, and build_scope() then
			 * silently fell back to a country-wide scope on every later `/suggest`.
			 * The customer picked a settlement and got address suggestions from the
			 * whole country, with nothing anywhere saying why.
			 *
			 * Invisible to a logged-in tester by construction: for a logged-in user
			 * `set()` writes user meta and returns `true` without consulting the
			 * session at all.
			 */
			$this->bridge_wc_session();

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

			/*
			 * D5 (popular-settlements spec): a stale popular-list pick is verified
			 * INLINE, in this same request, before the write below. "Not found, or
			 * found and fresh" (the overwhelmingly common path) falls straight
			 * through this block unchanged — one extra indexed store lookup, no
			 * provider call, no changed response.
			 */
			$record_to_persist = $record;

			$popular_store = $this->service->popular_settlement_store();
			$entry         = $popular_store->find_entry_by_key( $record->provider_id(), $record->key() );

			if ( null !== $entry && $popular_store->is_stale( $entry ) ) {
				$provider = $this->service->get_registered_provider( $record->provider_id() );

				if ( null === $provider ) {
					// No such provider registered at all right now — cannot verify.
					// Never block the purchase over it (spec D6: a "failed" row is
					// left completely untouched, exactly like a caught provider
					// exception would leave it).
					$this->log_failure(
						$record->provider_id(),
						'verify_key',
						new \RuntimeException(
							sprintf( 'Popular_Settlement_Verifier: no provider registered for id "%s".', $record->provider_id() )
						)
					);
				} else {
					$verification = ( new Popular_Settlement_Verifier( $popular_store ) )->verify_entry( $provider, $entry );

					switch ( $verification->outcome() ) {
						case Popular_Settlement_Verification::OUTCOME_UPDATED:
							// The provider's fresh record, not the posted one (D1
							// equivalence: search would have returned the new one).
							$fresh = $verification->record();

							if ( null !== $fresh ) {
								$record_to_persist = $fresh;
							}
							break;

						case Popular_Settlement_Verification::OUTCOME_FAILED:
							// The customer's record, as today — a provider outage
							// must never block a purchase.
							$error = $verification->error();

							if ( null !== $error ) {
								$this->log_failure( $record->provider_id(), 'verify_key', $error );
							}
							break;

						case Popular_Settlement_Verification::OUTCOME_GONE:
							// The row is already deleted by now (D6). D7 decides
							// whether to silently adopt a search match or cancel
							// the pick outright.
							$adopted = $this->resolve_stale_pick_replacement( $provider, $entry );

							if ( null !== $adopted ) {
								$record_to_persist = $adopted;
							} else {
								return rest_ensure_response( $this->cancelled_stale_record_response() );
							}
							break;

						// OUTCOME_UNCHANGED: $record_to_persist stays $record — the
						// customer's own posted record, exactly as today.
					}
				}
			}

			$record = $record_to_persist;

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
			 *
			 * `chain` is read AFTER the write, straight from
			 * {@see Location_Service::get_customer_chain()} — the server's own
			 * rebuilt chain, not a client-side guess reconstructed from `$record`
			 * alone (a client cannot know which shallower levels
			 * {@see \\Woodev\\Framework\\Shipping\\Location\\Customer_Location_Store::set()}
			 * kept or dropped). When the write failed (`persisted: false`) this is
			 * simply whatever chain the store already held — still the honest,
			 * current server-side answer.
			 */
			$chain_block = $this->customer_chain_response();

			return rest_ensure_response(
				[
					'current'   => [
						'key'   => $record->key(),
						'level' => $record->level(),
					],
					'persisted' => $persisted,
					'chain'     => $chain_block['chain'],
					'implicit'  => $chain_block['implicit'],
				]
			);
		}


		/**
		 * Builds the `chain` + `implicit` response block {@see self::handle_select_request()}
		 * returns on every shape (ordinary and D7-cancelled) — every level in the
		 * customer's CURRENT chain, `{ key, level }` each, keyed by level, plus the
		 * chain's own `implicit` flag. Reads straight from
		 * {@see Location_Service::get_customer_chain()}, so calling this WITHOUT an
		 * intervening write (the D7 cancel path) honestly reports "the server's chain
		 * as it stands — unchanged by this request" (spec D7), and calling it AFTER
		 * {@see Location_Service::set_customer_record()} reports the freshly-written
		 * state — same accessor, whichever is true at the moment it is called.
		 *
		 * WHY `implicit` IS PUBLISHED HERE AT ALL (issue #502, s91 critic finding
		 * MAJOR-1). It is tempting to reason that a `/select` response is by
		 * definition the result of a customer's own pick, which this route does
		 * persist "always EXPLICIT (spec D11)" — and to conclude the chain it answers
		 * with must therefore be explicit too. That conclusion is FALSE, and it shipped
		 * as a comment in the client before this flag existed. The chain is NOT the
		 * thing this route just persisted: {@see Location_Service::get_customer_chain()}
		 * is itself the LAZY TRIGGER for the store-level default-locality policy, so it
		 * resolves and seeds the merchant's default whenever nothing explicit survives.
		 * Two responses reach the client carrying that default:
		 *
		 * - the D7 `cancelled` shape ({@see self::cancelled_stale_record_response()}),
		 *   which deliberately writes NOTHING before reading the chain;
		 * - any response with `persisted: false` — a guest whose session/cart cookie has
		 *   not initialized (gotcha `guest-session-write-needs-the-cart-cookie`).
		 *
		 * Without this flag the client is structurally unable to tell those apart from a
		 * real pick, and #502 (an implicit default unlocking the address field) re-opens
		 * one click after being fixed. Spec §4.6/D11: "Implicit records participate in
		 * rate calculation but never suppress 'please choose your locality' prompts."
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Returns the chain under a `chain` key alongside `implicit`,
		 *              instead of being the chain itself (issue #502). Both callers are
		 *              private and in this file; nothing outside it consumed the old
		 *              shape.
		 *
		 * @return array{chain: array<string, array{key: string, level: string}>, implicit: bool}
		 */
		private function customer_chain_response(): array {
			$chain    = [];
			$implicit = false;

			$customer_chain = $this->service->get_customer_chain();

			if ( null !== $customer_chain ) {
				// Read ONCE, both values off the same answer: a second
				// get_customer_chain() call would re-run the lazy default resolution and
				// could, in principle, disagree with the first.
				$implicit = ! empty( $customer_chain['implicit'] );

				foreach ( $customer_chain['records'] as $chain_level => $chain_record ) {
					$chain[ $chain_level ] = [
						'key'   => $chain_record->key(),
						'level' => $chain_record->level(),
					];
				}
			}

			return [
				'chain'    => $chain,
				'implicit' => $implicit,
			];
		}

		/**
		 * Builds the D7 "cancel the pick" response (popular-settlements spec D7,
		 * point 3): nothing is written to the customer store — `chain` is
		 * therefore read UNCHANGED, before any write this request might otherwise
		 * have made. `cancelled` is present (and `true`) ONLY on this shape — every
		 * ordinary response omits the key entirely, so a client that has not been
		 * updated for D7 keeps working unchanged (spec: "`cancelled` is absent,
		 * not `false`, on every ordinary response").
		 *
		 * HTTP status stays 200 (the caller still wraps this in
		 * `rest_ensure_response()`): this is a real answer about the data, not a
		 * transport failure.
		 *
		 * The message is NOT optional (spec D7 — the project default is to explain
		 * a blocked/changed control): the customer clicks, the field empties and
		 * the address field re-locks on top of it; silence would read as two
		 * breakages in a row.
		 *
		 * @since 2.0.2
		 *
		 * @return array{cancelled: bool, reason: string, message: string, current: null, persisted: bool, chain: array<string, array{key: string, level: string}>, implicit: bool}
		 */
		private function cancelled_stale_record_response(): array {
			$chain_block = $this->customer_chain_response();

			return [
				'cancelled' => true,
				'reason'    => 'stale_record',
				'message'   => __( 'This information is out of date, please choose again', 'woodev-plugin-framework' ),
				'current'   => null,
				'persisted' => false,
				'chain'     => $chain_block['chain'],
				'implicit'  => $chain_block['implicit'],
			];
		}

		/**
		 * D7: when `resolve_key()` confirms a popular entry is gone, decides
		 * whether the customer's pick can be silently carried forward onto a fresh
		 * record, or must be cancelled instead.
		 *
		 * Runs the ordinary search for the STORED settlement name, scoped to the
		 * stored region (both read from `$entry`'s OWN record — the one that was
		 * just deleted, still held here in memory — never from anything the
		 * provider returned, since a `gone` verification carries no record at
		 * all). An exact, unambiguous match on BOTH settlement name and region
		 * (trimmed, `mb_strtolower`-ed) is adopted; anything else — two
		 * candidates, zero, a mismatched region, or a `suggest()` that throws — is
		 * refused. Never substitutes a near match: a locality display name is not
		 * an identifier (gotcha `a-locality-display-name-is-not-an-identifier`).
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Provider        $provider The provider that just confirmed `$entry` is gone.
		 * @param Popular_Settlement_Entry $entry    The (already-deleted) entry the customer picked.
		 *
		 * @return Location_Record|null The record to silently adopt, or null to cancel the pick.
		 */
		private function resolve_stale_pick_replacement( Location_Provider $provider, Popular_Settlement_Entry $entry ): ?Location_Record {
			$stored = $entry->record();

			$settlement_name = null !== $stored->settlement() ? $stored->settlement()['name'] : '';
			$region          = $stored->region();

			try {
				$scope = Location_Scope::within_components(
					$entry->country(),
					Location_Record::LEVEL_SETTLEMENT,
					null !== $region ? [ 'region' => $region ] : []
				);

				$matches = $provider->suggest( $settlement_name, $scope );
			} catch ( \Throwable $exception ) {
				return null; // A suggest() that throws is not a match (spec D7).
			}

			$target_settlement = $this->normalize_for_stale_pick_comparison( $settlement_name );
			$target_region     = $this->normalize_for_stale_pick_comparison( null !== $region ? $region['name'] : '' );

			$candidates = array_values(
				array_filter(
					$matches,
					function ( Location_Record $candidate ) use ( $target_settlement, $target_region ): bool {
						$candidate_settlement = $this->normalize_for_stale_pick_comparison(
							null !== $candidate->settlement() ? $candidate->settlement()['name'] : ''
						);
						$candidate_region     = $this->normalize_for_stale_pick_comparison(
							null !== $candidate->region() ? $candidate->region()['name'] : ''
						);

						return $candidate_settlement === $target_settlement && $candidate_region === $target_region;
					}
				)
			);

			return 1 === count( $candidates ) ? $candidates[0] : null;
		}

		/**
		 * Normalizes a display name for the D7 exact-match comparison: trimmed,
		 * `mb_strtolower`-ed — never a near/fuzzy match (gotcha
		 * `a-locality-display-name-is-not-an-identifier`).
		 *
		 * @since 2.0.2
		 *
		 * @param string $value Raw display name.
		 *
		 * @return string
		 */
		private function normalize_for_stale_pick_comparison( string $value ): string {
			return mb_strtolower( trim( $value ) );
		}

		/**
		 * Handles an admin-only geo-IP preview request (Task 14; spec D11) — lets
		 * the merchant, from the settings page, see what the `geoip`
		 * default-locality policy would ACTUALLY resolve for an IP before
		 * turning the policy on store-wide (or simply as a quick way to fill the
		 * `fixed` picker from the store's own known address). Delegates entirely
		 * to {@see Location_Service::locate()} — the SAME method the `geoip`
		 * policy itself calls, so this preview can never disagree with runtime
		 * behavior.
		 *
		 * The `ip` param defaults to `WC_Geolocation::get_ip_address()` — the
		 * EXACT SAME source {@see Location_Service::resolve_default()}'s own
		 * `geoip` branch reads — deliberately NOT
		 * {@see Rest_Rate_Limit_Trait::get_client_ip()}: that helper is built
		 * for rate-limit BUCKETING and therefore never returns an unusable
		 * value (it falls back to the literal string `'unknown'`), which would
		 * silently hand a non-IP string to a provider's `locate()`. A missing
		 * `X-Forwarded-For`/`REMOTE_ADDR` here is a genuine "cannot preview"
		 * 400, not a bucket identity.
		 *
		 * Degrades to 404 (mirroring {@see self::handle_list_request()}'s own
		 * "this stopped being true" semantics, not `/suggest`'s 200+empty) when
		 * {@see Location_Service::supports_locate()} is false — a client
		 * reaching this route at all already believes the capability exists
		 * (the settings page only ever offers the `geoip` policy, and therefore
		 * this preview action, when it does). Once past that gate,
		 * `locate()` itself never throws (it swallows a provider failure into
		 * `null`, matching its own docblock), so a `null` result here is
		 * always the legitimate "this IP did not resolve" answer, returned as
		 * `200 { location: null }`.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param \WP_REST_Request $request request object.
		 *
		 * @return \WP_REST_Response|\WP_Error|array{location: array{key: string, label: string, level: string, record: array<string, mixed>}|null}
		 */
		public function handle_admin_locate_request( $request ) {

			if ( $this->is_rate_limited( 'woodev_location_admin_loc_rl_', self::ADMIN_LOCATE_RATE_LIMIT_MAX ) ) {
				return $this->rate_limited_error();
			}

			if ( ! $this->service->supports_locate() ) {
				return new \WP_Error(
					'woodev_location_locate_unavailable',
					__( 'Определение локации по IP-адресу сейчас недоступно.', 'woodev-plugin-framework' ),
					[ 'status' => 404 ]
				);
			}

			$ip = $this->normalize_param( $request->get_param( 'ip' ) );

			if ( '' === $ip && class_exists( '\\WC_Geolocation' ) ) {
				$ip = (string) \WC_Geolocation::get_ip_address();
			}

			if ( '' === $ip ) {
				return new \WP_Error(
					'woodev_location_locate_no_ip',
					__( 'Не удалось определить IP-адрес.', 'woodev-plugin-framework' ),
					[ 'status' => 400 ]
				);
			}

			$record = $this->service->locate( $ip );

			if ( null === $record ) {
				return rest_ensure_response( [ 'location' => null ] );
			}

			return rest_ensure_response( [ 'location' => $this->to_response_records( [ $record ] )[0] ] );
		}

		/**
		 * Builds the lookup scope for a suggest call, resolving the optional
		 * `within` parent constraint — and reports what actually happened to
		 * that constraint via `within_status` (one of the
		 * `self::WITHIN_STATUS_*` constants), so a caller no longer has to
		 * infer it from `Location_Scope::has_parent()` alone (see
		 * {@see self::perform_suggest()}'s own `within_applied` field, whose
		 * docblock now cross-references this one).
		 *
		 * `within` is a locality KEY (not a components blob) the client believes
		 * names one of its own already-picked levels. Since this controller has
		 * no "look up a record by bare key" mechanism, it is resolved by
		 * checking it against {@see Location_Service::get_customer_chain()} —
		 * EVERY level the SAME client itself persisted moments earlier via
		 * `/select` (D8: every cascade step persists before the next step's
		 * suggest call runs), not merely the CURRENT one (issue #330: a
		 * settlement `within` sent alongside an address-level search — the
		 * ordinary shape of an address lookup — used to resolve against the
		 * current record alone, so it was silently ignored the moment `current`
		 * moved past settlement, and the search fell through to a country-wide
		 * scope with nothing anywhere saying why). A key that matches NO record
		 * in the chain at all (unknown, stale, no customer record yet) is
		 * treated exactly like an ABSENT `within` — never an error (spec Task 8:
		 * "stale client state must never brick the field") — and a key that DOES
		 * match but sits at the wrong level for this search (e.g. a region key
		 * "within" a region-level search) is refused by
		 * {@see Location_Scope::within()} itself and swallowed the same way.
		 * The matched record is a REAL, previously-persisted
		 * {@see Location_Record} with its own `raw`, so {@see Location_Scope::within()}
		 * and a provider's own constraint-building (e.g.
		 * {@see \Woodev\Framework\Shipping\Location\Providers\Dadata_Provider::build_locations_constraint()})
		 * need no change at all and still get exact native ids.
		 *
		 * The FOREIGN-PROVIDER half of this — a `within` key resolving to a
		 * record from a provider that no longer owns ITS OWN level — used to
		 * be a silent hand-over to the provider (see gotcha
		 * `within-applied-reports-the-scope-builder-not-the-provider`, #333).
		 * {@see Location_Service::get_customer_chain()} (Part 1 of #346/#333)
		 * now gates every record in the chain against exactly that staleness
		 * (per-level provider ownership + country) before this method ever
		 * sees it, so a record whose OWN level's owner no longer matches it
		 * can no longer BE in `$records` below.
		 *
		 * That is NOT the same as "every parent handed to a provider now
		 * shares that provider" (corrected, s78 — the previous docblock here
		 * overclaimed this as structurally impossible, which is false): the
		 * gate checks a record against the owner of ITS OWN level, never
		 * against the level actually being searched. A legitimate MIXED-OWNER
		 * chain — e.g. `{region: dadata, settlement: carrier}`, valid
		 * precisely because `region` is OPTIONAL and a carrier may serve
		 * `settlement` without serving `region` (issue #352) — still hands a
		 * `dadata`-owned parent to a `carrier` provider when `within` matches
		 * the region and the search level is deeper. Neither bundled provider
		 * implementation reports whether it could actually honour a
		 * cross-provider parent; that residual gap is tracked separately
		 * (#353, not this fix) rather than banned here — banning cross-provider
		 * scopes outright would also break the legitimate, WORKING case (a
		 * DaData address search scoped by a CDEK settlement, measured on the
		 * rig). Do not re-add a same-level provider check here for the
		 * SINGLE-level staleness the chain read already refuses to hand
		 * over — that part remains true.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Resolves `within` against the customer's WHOLE chain
		 *              ({@see Location_Service::get_customer_chain()}), not only
		 *              the current record (issue #330).
		 * @since 2.0.2 Returns `within_status` alongside the scope (#333) —
		 *              the failure this method used to swallow silently is now
		 *              named for the caller.
		 * @since 2.0.2 Passes this method's own already-normalized `$country`
		 *              into {@see Location_Service::get_customer_chain()} as
		 *              its `$for_country` argument (#350/#352 follow-up),
		 *              rather than letting that read fall back to the ambient
		 *              WooCommerce customer country — a `/suggest`/`/list`
		 *              request's own `country` param is the stronger
		 *              authority for THIS request; the ambient customer can
		 *              disagree with it (gotcha
		 *              `wc-customer-default-location-geolocation-fallback`).
		 *
		 * @param string $country    Normalized ISO-3166 alpha-2 country code.
		 * @param string $level      One of {@see Location_Record::LEVELS} — already validated by the caller.
		 * @param string $within_key Normalized `within` param, possibly `''`.
		 *
		 * @return array{scope: Location_Scope, within_status: string}
		 *
		 * @throws \InvalidArgumentException When `$country` is not a well-formed
		 *                                    ISO-3166 alpha-2 code — the caller
		 *                                    converts this to a 400.
		 */
		private function build_scope( string $country, string $level, string $within_key ): array {

			if ( '' === $within_key ) {
				return [
					'scope'         => Location_Scope::for_country( $country, $level ),
					'within_status' => self::WITHIN_STATUS_NOT_REQUESTED,
				];
			}

			$chain   = $this->service->get_customer_chain( $country );
			$records = null !== $chain ? $chain['records'] : [];

			foreach ( $records as $chain_record ) {
				if ( $chain_record->key() !== $within_key ) {
					continue;
				}

				/*
				 * A parent from ANOTHER COUNTRY is refused, not merely unhelpful
				 * (adversarial review): Location_Scope::within() takes the scope's
				 * country FROM THE PARENT RECORD — there is deliberately no
				 * $country argument there, so the two cannot disagree — which means
				 * honouring a stale cross-country `within` would silently move the
				 * whole search to the parent's country while the customer is typing
				 * an address in the one they actually selected.
				 *
				 * `$country` is normalized HERE rather than trusted: it arrives from
				 * `normalize_param()`, which cleans but does NOT upper-case, while a
				 * record's own `country()` is always upper-cased by
				 * {@see Location_Record::from_array()}. Comparing them raw would drop
				 * a perfectly good parent for any client that sent `ru` instead of
				 * `RU` — a silent narrowing introduced by a guard meant to prevent
				 * one.
				 */
				if ( $chain_record->country() !== strtoupper( trim( $country ) ) ) {
					return [
						'scope'         => Location_Scope::for_country( $country, $level ),
						'within_status' => self::WITHIN_STATUS_CROSS_COUNTRY,
					];
				}

				try {
					return [
						'scope'         => Location_Scope::within( $chain_record, $level ),
						'within_status' => self::WITHIN_STATUS_APPLIED,
					];
				} catch ( \InvalidArgumentException $exception ) {
					return [
						'scope'         => Location_Scope::for_country( $country, $level ),
						'within_status' => self::WITHIN_STATUS_BAD_LEVEL,
					];
				}
			}

			// No match at all — unknown/stale key, or no customer chain yet.
			return [
				'scope'         => Location_Scope::for_country( $country, $level ),
				'within_status' => self::WITHIN_STATUS_UNKNOWN_KEY,
			];
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
		 * {@see Pickup_Controller::log_carrier_failure()} uses, INCLUDING
		 * routing the message through {@see \Woodev_API_Base::redact_secret_log_text()}
		 * before it is logged: `$exception` comes from `Location_Provider::suggest()`/
		 * `list_localities()`, a plugin extension seam wrapping a live
		 * third-party SDK, so its message may never have passed through
		 * `Woodev_API_Base`'s own redaction at all — see that method's
		 * docblock for why this is defence in depth, not a guarantee (#585,
		 * #593) — and fires a provider-agnostic action so an external
		 * consumer can observe a degraded suggest/list call without parsing
		 * `error_log()` (#405).
		 *
		 * The `do_action()` below hands the consumer the RAW, unredacted
		 * `$exception` object on purpose — a consumer may legitimately need
		 * the real exception (its class, its code, a chained previous
		 * exception) for its own handling, and this action is not itself a
		 * log boundary. But that also means redaction is NOT applied on this
		 * path: a consumer that logs or serializes `$exception` (its message
		 * included) reintroduces the exact disclosure this method's own
		 * `error_log()` call just closed — redacting it before doing so is
		 * that consumer's job, not this method's.
		 *
		 * NO admin-notice consumer ships for this action yet — #405's own card
		 * raised the question ("заодно решить, нужно ли админское уведомление
		 * о повторяющихся сбоях") and this PR deliberately did not build one:
		 * there is no production telemetry on this rig (a single operator, not
		 * real traffic) to defensibly pick a failure-count/time-window
		 * threshold, and picking one without evidence is exactly what
		 * `docs-internal` gotcha-level guidance warns against. The REST 502
		 * this failure already produces (`self::upstream_error()`), together
		 * with the admin picker's OWN already-built `status: 'error'` state
		 * ({@see \LocationPickerField} — issue #376) and the checkout
		 * typeahead's new `errorText` (issue #405), already give an operator
		 * testing the picker an immediate, honest signal — this hook exists so
		 * a FUTURE repeated-failure notice (once real usage data justifies a
		 * threshold) has something to attach to without needing this fix
		 * rebuilt around it.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Added the `$provider_id` parameter and the
		 *              `woodev_location_provider_operation_failed` action
		 *              (#405).
		 *
		 * @param string     $provider_id The failing provider's {@see Location_Provider::get_id()}.
		 * @param string     $operation   One of `suggest`, `list`.
		 * @param \Throwable $exception   The caught failure.
		 *
		 * @return void
		 */
		private function log_failure( string $provider_id, string $operation, \Throwable $exception ): void {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for a
			// provider failure; the browser only ever sees a generic 502.
			error_log(
				sprintf(
					'[woodev] location %s (%s) failed: %s',
					$operation,
					$provider_id,
					\Woodev_API_Base::redact_secret_log_text( $exception->getMessage() )
				)
			);

			/**
			 * Fires when a Location Provider `suggest()`/`list_localities()` call
			 * fails and the REST layer degrades to its distinct 502 response
			 * instead of the ordinary 200+empty one (#405).
			 *
			 * $exception is the RAW, unredacted failure — see this method's own
			 * docblock. A listener that logs or serializes it must redact it
			 * itself, e.g. via {@see \Woodev_API_Base::redact_secret_log_text()}.
			 *
			 * @since 2.0.2
			 *
			 * @param string     $provider_id The failing provider's own id.
			 * @param string     $operation   One of `suggest`, `list`.
			 * @param \Throwable $exception   The caught failure, UNREDACTED.
			 */
			do_action( 'woodev_location_provider_operation_failed', $provider_id, $operation, $exception );
		}

		// is_rate_limited(), get_client_ip() and cap_length() are provided by
		// Rest_Rate_Limit_Trait (shared with Field_Source_Controller and
		// Pickup_Controller) — see that trait for the rate-limit mechanism and its
		// proxy/IPv6 caveats.
	}

endif;
