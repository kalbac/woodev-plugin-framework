<?php
/**
 * Woodev_Test_Live_Pochta_Point_Source — OPT-IN STRATEGY_VIEWPORT fixture Point_Source that
 * calls the REAL Russian Post (Почта РФ) pickup-point widget API on `widget.pochta.ru`,
 * proving the framework's viewport strategy — bbox listing, pagination, and lazy per-point
 * detail fetch (#219/#223) — against a genuinely sparse live carrier response instead of the
 * three static points in `Woodev_Test_Viewport_Point_Source` (issue #226).
 *
 * ACTIVATION: never on by default. `woodev-test-shipping-method.php` only constructs this
 * class when `WOODEV_TEST_PICKUP_LIVE_POCHTA` is truthy (defined near
 * `WOODEV_TEST_PICKUP_STRATEGY`/`WOODEV_TEST_PICKUP_LIVE_YANDEX` at the top of that file,
 * default `false`). Neither `composer test:unit` nor `composer test:integration` nor CI ever
 * sets that constant, so this class is declared (cheap — no I/O happens merely by
 * `require_once`ing it) but never INSTANTIATED, and therefore never makes a network call, in
 * either suite. PRECEDENCE: `WOODEV_TEST_PICKUP_LIVE_YANDEX` still wins when both live
 * switches are truthy at once (an operator misconfiguration this fixture does not try to
 * prevent, only to resolve deterministically) — see the wiring file's own comment. When only
 * this source's switch is on, it wins over `WOODEV_TEST_PICKUP_STRATEGY`, the same way Yandex
 * wins over it: Pochta's real API is viewport-only, so "live Pochta + bulk strategy" is not a
 * combination that exists to choose between.
 *
 * ACCOUNT IDENTITY — NOT IN THIS FILE, AND NOT IN THIS REPOSITORY. `accountId` and
 * `settings_id` identify the operator's OWN Pochta widget account/site, and this repository is
 * PUBLIC. They are read at request time from `self::ACCOUNT_ID_CONSTANT`
 * (`WOODEV_TEST_POCHTA_ACCOUNT_ID`) and `self::SETTINGS_ID_CONSTANT`
 * (`WOODEV_TEST_POCHTA_SETTINGS_ID`); when neither resolves, {@see self::resolve_settings_id()}
 * throws instead of calling out, so the failure names its own cause and NO REQUEST reaches the
 * network (pinned by a `never()` transport test in this class's own test suite — see the
 * `public-repo-third-party-credentials` gotcha this rule exists because of).
 *
 * Define them in `wp-config.php`, or in the GITIGNORED `.wp-env.override.json` rather than the
 * tracked `.wp-env.json`:
 *
 *     define( 'WOODEV_TEST_POCHTA_ACCOUNT_ID', '…' );
 *     // OR, skipping the settings lookup entirely:
 *     define( 'WOODEV_TEST_POCHTA_SETTINGS_ID', … );
 *
 * SETTINGS_ID RESOLUTION — a deliberate two-path design. `WOODEV_TEST_POCHTA_SETTINGS_ID`,
 * when defined, is used directly and `POST /api/sites/public_show` is never called — the fast
 * path. Otherwise `WOODEV_TEST_POCHTA_ACCOUNT_ID` is required, and the settings id is resolved
 * from it via that endpoint and cached (own transient, `SETTINGS_CACHE_TTL`, successful
 * responses only). Both paths are real: a fresh install only has an account id and exercises
 * the resolution call at least once; a rig that already knows its settings id can skip it
 * entirely. See {@see self::resolve_settings_id()}. `fetch_details()` needs NEITHER constant —
 * see that method's own docblock for why gating it the same way would guard a dependency the
 * measured `GET /api/pvz/{id}` contract does not have.
 *
 * PAYLOAD, contract given by the operator and measured live 08.08.2026 (issue #226's own body
 * carries the full write-up) — NOT taken from documentation, matching this project's
 * "reference is not enough" lesson. Three endpoints on `https://widget.pochta.ru`:
 *
 *  1. `POST /api/sites/public_show` `{accountId, accountType:"wordpress"}` -> HTTP 200,
 *     carries `id` (or `pickupId`) = the `settings_id` the listing call needs.
 *  2. `POST /api/pvz` `{settings_id, pageSize, page, currentTopRightPoint,
 *     currentBottomLeftPoint, pvzType}` -> `{ boundingBox, data[], pageNumber, totalEntries,
 *     totalPages }`. Measured over central Moscow: 113 points, `totalPages: 1` (79
 *     `russian_post` + 34 `postamat`). The listing record is genuinely SPARSE — only `id`,
 *     `type`, `geo`, `address`, `deliveryPointIndex`. No name, no payment methods, no hours:
 *     this is the whole reason this fixture exists, proving #219's lazy-detail fetch against a
 *     real carrier instead of three fixture points that already carried everything up front.
 *  3. `GET /api/pvz/{id}` -> the full record, adding `cashPayment` (bool — this IS
 *     `accepts_cod`), `cardPayment`, `acceptEcom`, `workTime` (list of strings), `holidays`,
 *     `closed`, `temporaryClosed`, `brandName`, `deliveryPointType`, `withFitting`,
 *     `partialRedemption`, `returnAvailable`, `contentsChecking`, `boxSize`, `typesizeVal`.
 *
 * ⚠️ TRAP 1 — COORDINATES. Both the listing request's bbox corners and each record's
 * `geo.coordinates` are `[lng, lat]`, NOT `[lat, lng]`. Measured control: the SAME rectangle
 * sent as `[lat, lng]` returned 0 points instead of 113, at HTTP 200, with no error — "this
 * city has no pickup points", silently. `Point_Query::get_bounds()` gives
 * `[min_lat, min_lng, max_lat, max_lng]`; `currentTopRightPoint` takes the maxima,
 * `currentBottomLeftPoint` the minima — see {@see self::request_points_page()}. The
 * `geo.coordinates` half was first derived from the vendored widget bundle
 * (`plugins-reference/pochta-widget/main.a7d147fb5267ec1f0932.js` builds its ymaps marker as
 * `geometry.coordinates: t.geo.coordinates.reverse()`, and ymaps wants `[lat, lng]`) and has
 * since been CONFIRMED BY LIVE CAPTURE — see the RAW CAPTURE section below.
 * See {@see self::extract_coordinates()}.
 *
 * ⚠️ TRAP 2 — DETAILS KEY. `fetch_details()` must be called with the numeric `id` from the
 * listing, NEVER `deliveryPointIndex` (the postal index) — measured: `/api/pvz/26600` -> full
 * record (2191 bytes); `/api/pvz/111033` (the postal index of the SAME point) -> HTTP 200 with
 * a 4-byte body. A wrong key is an EMPTY SUCCESS, not a 404 and not an error. The 4-byte body
 * is almost certainly the literal JSON `null` (4 characters); `json_decode()` turns that into
 * PHP `null`, which fails the `is_array($body) && isset($body['id'])` guard in
 * {@see self::request_point_details()} the same way any other unshaped body would — mapped to
 * a `null` RETURN, not a thrown exception. See that method's own docblock for why that is the
 * contractually correct read of the interface's `@return` clause, not its `@throws` one.
 *
 * RAW CAPTURE, 08.08.2026 — the field-level shape below is MEASURED, not inferred. It was
 * captured after this class was first written (the card recorded only the top-level key NAMES
 * of `geo`/`address`, never their contents), and it CONFIRMED the widget-bundle reading that
 * the first draft rested on. Kept verbatim here because a fixture poorer than production is
 * how s49 hid two of its four map defects, and because the next person to touch this file
 * should not have to re-derive the shape from a minified bundle.
 *
 * One sparse LISTING record, exactly as returned:
 *
 *     {"address":{"addressType":"DEFAULT","area":null,"building":null,"corpus":"2",
 *       "hotel":null,"house":"3","id":62170,"index":"111543",
 *       "insertedAt":"2024-01-25T03:02:48","letter":null,"location":null,
 *       "manualInput":false,"numAddressType":null,"office":null,"place":"г. Москва",
 *       "region":"г. Москва","room":null,"slash":null,"street":"ш. Энтузиастов",
 *       "updatedAt":"2026-06-20T03:02:01","vladenie":null},
 *      "addressString":null,"deletedAt":null,"deliveryPointIndex":"111543",
 *      "geo":{"coordinates":[37.692995,55.748245],
 *        "crs":{"properties":{"name":"EPSG:4326"},"type":"name"},"type":"Point"},
 *      "id":62257,"type":"russian_post"}
 *
 * ⚠️ `address.id` (62170) IS NOT THE POINT ID (62257). They coincide on some records and not on
 * others, so a mapper reading `address.id` would look correct on the first record it was tested
 * against and silently mis-key the rest — always take the TOP-LEVEL `id`. Also note the address
 * object carries more slots than the widget composes (`vladenie`, `office`, `room`, `hotel`,
 * `index`), all null on this record.
 *
 * `geo.coordinates: [37.692995, 55.748245]` is Moscow — longitude first. TRAP 1 confirmed.
 *
 * The matching DETAILS record (`GET /api/pvz/62257`, HTTP 200, 1987 bytes) adds, verbatim:
 * `acceptEcom:true`, `boxSize:null`, `brandId:null`, `brandName:"Почта России"`,
 * `cardPayment:false`, `cashPayment:false`, `closed:false`, `contentsChecking:false`,
 * `deliveryPointType:"ГОПС"`, `functionalityChecking:false`, `getto:null`, `holidays:[…]`,
 * `legalName:null`, `legalShortName:null`, `partialRedemption:false`, `pochtaId:null`,
 * `returnAvailable:false`, `temporaryClosed:false`, `typesizeId:null`, `typesizeVal:null`,
 * `withFitting:false`, and
 * `workTime:["пн, выходной","вт, открыто: 10:00 - 19:00","ср, выходной",…]`.
 *
 * NOTE ON `workTime`: unlike Yandex's structured `schedule.restrictions` (which the sibling
 * live source has to flatten), Pochta returns ALREADY HUMAN-READABLE Russian strings, one per
 * weekday. There is nothing to reconstruct — joining them is the whole job, and inventing a
 * parser for them would be a way to introduce bugs, not remove them.
 *
 * `cashPayment: false` on this real record is the live proof the lazy-detail path (#219/#223)
 * was built for: the sparse listing says nothing about cash on delivery, the detail call says
 * "no", and the verdict flips only after that second request lands.
 *
 * TRAP 2 re-confirmed in the same capture: `GET /api/pvz/111543` (this point's own postal
 * index) returned HTTP 200 with the 4-byte body `null`.
 *
 * ADDRESS COMPOSITION — {@see self::compose_address()}'s `place`/`location`/street algorithm
 * follows the vendored widget bundle's own `C()`/`N()` helpers (what fills its
 * `#balloon-address` for a selected point), so our composition matches what a customer sees in
 * Pochta's own widget rather than being a second, divergent idea of the same address.
 * `addressString` is always `null` in both responses (per the brief), which is why the address
 * is built from these structural fields instead.
 *
 * "STRINGIFIED BOOLEAN"-STYLE WORKAROUND (issue #210) — checked, not assumed. Yandex's 5post
 * bug came from a THIRD PARTY pre-composing one finished address STRING upstream of Yandex and
 * stringifying an absent boolean field inside it. Here, {@see self::compose_address()} builds
 * the string itself from typed sub-fields, and {@see self::address_field()} treats a JSON
 * `null` (via `isset()`) or a non-scalar value the same as "absent" — so a boolean `false`
 * sub-field would decode to PHP `''` (an actual empty string, via `(string) false === ''`),
 * never to the four characters "false". No guard is added here because there is no matching
 * failure mode to guard against, not because the check was skipped.
 *
 * TYPE MAP — reuses this fixture's EXISTING `PVZ`/`POSTAMAT` type codes (see
 * `Woodev_Test_Live_Yandex_Point_Source::TYPE_MAP` and the icon registration in
 * `woodev-test-shipping-method.php`), so the already-registered marker icons keep working
 * without any new icon files. `russian_post` -> `PVZ`, `postamat` -> `POSTAMAT`; labels are the
 * exact Russian strings the real widget bundle itself displays for these two types (its own
 * `m()` helper: "Почтовое отделение" / "Почтомат"). The widget bundle exposes a THIRD type,
 * `additional_pvz` ("Партнерский ПВЗ", partner pickup points) — outside this fixture's scope:
 * the operator's own measured `pvzType` filter and point count (79+34=113) never included it,
 * so an `additional_pvz` record here is simply an unrecognised type and is SKIPPED, not fatal,
 * the same as any other unknown type (interface contract).
 *
 * TYPE FILTER (D-10, mandatory for a viewport source) — {@see self::pvz_types_for_query()} maps
 * `Point_Query::get_types()` (our own `PVZ`/`POSTAMAT` codes) back to Pochta's `pvzType`
 * values. An EMPTY `get_types()` means "all types" per that method's own contract; this fixture
 * sends every known `pvzType` value EXPLICITLY rather than omitting the parameter, because
 * omitting it was never part of the operator's measurement — there is no verified evidence an
 * omitted `pvzType` behaves as "no filter" server-side, so this class does not guess that it
 * does. A NON-empty `get_types()` whose codes match NOTHING in `TYPE_MAP` (a filter UI
 * regression, or a future type this fixture does not know) short-circuits to an empty result
 * WITHOUT a network call — no carrier type can match a filter this source cannot even
 * translate, so making the request would only spend a round trip to learn nothing.
 *
 * PAGINATION — real (`page`/`pageSize`/`totalPages`), and followed by
 * {@see self::request_points_paginated()} until `totalPages` is reached, bounded by
 * `MAX_PAGES` (10) so a misbehaving upstream cannot loop forever. `MAX_PAGES * PAGE_SIZE` =
 * 2000 points per viewport request — far beyond the 113-point measured control for central
 * Moscow, the densest single-bbox result this fixture is ever likely to see in practice, while
 * still bounded rather than open-ended. A response whose `totalPages` is missing or
 * non-numeric defaults to `1` (this page is the only page) rather than looping.
 *
 * CACHING — unlike Yandex's ONE global transient (a single bulk/locality-addressed call covers
 * a whole city), a viewport source is queried once PER BBOX, so a naive locality-shaped cache
 * key would create an unbounded number of transients as the customer pans the map across a
 * long rig session. {@see self::cache_key_for_query()} rounds each bbox coordinate to 3 decimal
 * places (~111m at the equator — coarse enough that re-opening roughly the same viewport still
 * hits the cache, fine enough that two genuinely different viewports do not collide) and folds
 * in the sorted `pvzType` filter, so the type-filter chips do not share a cache entry with an
 * unfiltered view. TTL is `15 * MINUTE_IN_SECONDS`, much shorter than Yandex's day-long TTL:
 * that TTL was safe for exactly ONE cache key; this source's key SPACE grows with every
 * distinct viewport the operator explores, and a shorter TTL bounds both how many stale entries
 * accumulate in `wp_options` and how long any one of them can go stale, while still absorbing
 * the rapid repeated re-fetches a panning/zooming map naturally produces within one browsing
 * session. Only a SUCCESSFUL response is cached (both the points cache and the separate
 * settings-id cache skip `set_transient()` on a thrown exception), so a sandbox outage is
 * retried on the very next request rather than remembered.
 *
 * FAIL SOFT — same contract as the Yandex live source: every transport/HTTP/shape failure
 * throws `\Woodev_API_Exception` (never a silently empty/short result for THOSE failure modes),
 * which the REST layer already turns into a `502` + retry UI. The ONE exception is TRAP 2's
 * empty-success body, which is deliberately NOT thrown — see that trap's own paragraph above
 * and {@see self::request_point_details()}'s docblock for why `null` is the contractually
 * correct read there. `wp_safe_remote_post()`/`wp_safe_remote_get()` are used (never the unsafe
 * variants) with an explicit `REQUEST_TIMEOUT` (8s, matching the Yandex source). `widget.pochta.ru`
 * is a public host on the standard port 443, so `http_request_host_is_external` never blocks
 * it — the local-rig gotcha (`docs-internal/gotchas/wp-safe-remote-request-local-rig.md`) only
 * bites a private/loopback host or a non-standard port, neither of which applies here.
 *
 * NAME SYNTHESIS (issue #226 point 1) — neither the sparse listing nor most full records carry
 * a name for `russian_post`/`postamat` (only `additional_pvz`-style partner points get
 * `brandName`, per the widget bundle's own eligibility rules). {@see self::synthesize_name()}
 * uses `brandName` when present, else builds "{type label} №{index}, {city}" — THIS IS OUR OWN
 * CONSTRUCTION, not a carrier field, and it deliberately mirrors the exact template the real
 * widget itself uses for its own point-list subtitle (verified in the vendored bundle), so an
 * operator comparing this fixture's list against Pochta's own would recognise the format.
 *
 * FIELDS WITH NO HOME IN `Pickup_Point` — `boxSize`/`typesizeVal` (dimensions) and
 * `closed`/`temporaryClosed` (availability) are in the measured full-record table but have no
 * matching key in `Pickup_Point::from_array()`'s contract, the same category as Yandex's
 * dropped `dayoffs`/`deactivation_date`. They are NOT mapped here. Surfacing "this point is
 * temporarily closed" to the customer is a real gap, but it needs a NEW mechanism (most
 * plausibly the existing `woodev_shipping_pickup_point_selection` domain filter this very
 * fixture already demonstrates for `DEMO-PVZ-REFUSE`), not a silent field this class invents on
 * its own — explicitly out of scope for #226.
 *
 * `region`/`area` (from the `address` object) are read by `compose_address()`'s underlying
 * sub-fields but deliberately EXCLUDED from the composed string, matching the real widget's own
 * `N()` function, which only joins `place`, `location`, and the street/house line — the
 * region/area are shown by the widget elsewhere (its own shipping-cost panel), not as part of a
 * point's address line, and this fixture follows the same choice.
 *
 * Declared inside the plugin's init callback (`require_once`d from
 * `woodev-test-shipping-method.php`, same arrangement as `class-test-live-yandex-point-source.php`
 * — see that file's own docblock and
 * `docs-internal/gotchas/fixture-classes-must-live-inside-plugin-init.md`): a class naming a
 * `Woodev\Framework\*` interface in `implements` fatals if declared before the bootstrap has
 * selected a framework copy and registered its autoloader.
 *
 * @package Woodev_Test_Shipping_Method
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Test_Live_Pochta_Point_Source' ) ) {

	/**
	 * Class Woodev_Test_Live_Pochta_Point_Source
	 *
	 * Live Russian Post (Почта РФ) widget-API viewport Point_Source — see the file-level
	 * docblock for the full rationale (activation gate, verified payload shape, pagination,
	 * caching, both traps, and fail-soft behaviour).
	 */
	class Woodev_Test_Live_Pochta_Point_Source implements \Woodev\Framework\Shipping\Pickup\Point_Source {

		/**
		 * Name of the constant supplying the operator's Pochta widget account id — see the
		 * file docblock's ACCOUNT IDENTITY section. The value itself is deliberately NOT in
		 * this file: this repository is PUBLIC, and the value identifies the operator's own
		 * site, not ours to publish.
		 */
		private const ACCOUNT_ID_CONSTANT = 'WOODEV_TEST_POCHTA_ACCOUNT_ID';

		/**
		 * Name of the constant supplying an already-known `settings_id`, skipping the
		 * `public_show` resolution call entirely when defined — see the file docblock's
		 * SETTINGS_ID RESOLUTION section.
		 */
		private const SETTINGS_ID_CONSTANT = 'WOODEV_TEST_POCHTA_SETTINGS_ID';

		/** Pochta's widget-API host — verified live 08.08.2026, see file docblock. */
		private const HOST = 'https://widget.pochta.ru';

		/** Verified live 08.08.2026 — settings/`accountId` resolution endpoint. */
		private const SETTINGS_PATH = '/api/sites/public_show';

		/** Verified live 08.08.2026 — sparse, paginated, bbox-addressed point listing. */
		private const LIST_PATH = '/api/pvz';

		/** Verified live 08.08.2026 — full per-point record, keyed by numeric `id` (TRAP 2). */
		private const DETAILS_PATH = '/api/pvz/';

		/** `accountType` value the settings call expects for a WordPress integration. */
		private const ACCOUNT_TYPE = 'wordpress';

		/** Matches the operator's own measured request — see file docblock PAYLOAD section. */
		private const PAGE_SIZE = 200;

		/** Bounded so a misbehaving upstream cannot loop forever — see file docblock PAGINATION. */
		private const MAX_PAGES = 10;

		/** Bounded so a hung sandbox degrades to an error, not an indefinite wait. */
		private const REQUEST_TIMEOUT = 8;

		/** Own transient for the resolved `settings_id` — see file docblock SETTINGS_ID RESOLUTION. */
		private const SETTINGS_CACHE_KEY = 'woodev_test_live_pochta_settings_id';

		/** A day — site/account configuration barely changes; matches Yandex's own convention. */
		private const SETTINGS_CACHE_TTL = DAY_IN_SECONDS;

		/** Prefix for the bbox+type-addressed points cache — see file docblock CACHING section. */
		private const POINTS_CACHE_PREFIX = 'woodev_test_live_pochta_pts_';

		/** See file docblock CACHING section for why this is far shorter than Yandex's day-long TTL. */
		private const POINTS_CACHE_TTL = 15 * MINUTE_IN_SECONDS;

		/** Decimal places a bbox coordinate is rounded to before hashing into a cache key (~111m). */
		private const CACHE_BBOX_PRECISION = 3;

		/**
		 * Maps Pochta's raw `type` values onto the fixture's EXISTING `PVZ`/`POSTAMAT` type
		 * codes so the already-registered marker icons keep working — see the file docblock's
		 * TYPE MAP section. `short` is this fixture's own tab wording (issue #207); like
		 * Yandex's `TYPE_MAP`, it is fed separately into `point_short_name` and never reaches
		 * `Pickup_Point`'s whitelisted `type` array.
		 *
		 * @var array<string, array{code: string, label: string, short: string}>
		 */
		private const TYPE_MAP = [
			'russian_post' => [
				'code'  => 'PVZ',
				'label' => 'Почтовое отделение',
				'short' => 'Почта',
			],
			'postamat'     => [
				'code'  => 'POSTAMAT',
				'label' => 'Почтомат',
				'short' => 'Почтомат',
			],
		];

		/**
		 * @inheritDoc
		 */
		public function get_strategy(): string {
			return self::STRATEGY_VIEWPORT;
		}

		/**
		 * Returns the sparse points inside the requested bbox, honouring the type filter
		 * (D-10, mandatory for a viewport source) — see the file docblock's TYPE FILTER
		 * section.
		 *
		 * @inheritDoc
		 *
		 * @throws \Woodev_API_Exception On a sandbox transport, HTTP, settings-resolution, or
		 *                                 payload-shape failure — see the file docblock's
		 *                                 FAIL SOFT section.
		 */
		public function fetch_points( \Woodev\Framework\Shipping\Pickup\Point_Query $query ): array {
			// Guaranteed non-null for a STRATEGY_VIEWPORT source — see the Point_Source
			// interface docblock.
			[ $min_lat, $min_lng, $max_lat, $max_lng ] = $query->get_bounds();

			$pvz_types = $this->pvz_types_for_query( $query->get_types() );

			if ( [] === $pvz_types ) {
				// Every requested type code is foreign to this source — nothing can match, and
				// there is no verified evidence an empty `pvzType` array means "all types" on
				// the wire (see file docblock TYPE FILTER section), so this refuses the guess
				// rather than risk it being read as "no filter" server-side.
				return [];
			}

			$raw_points = $this->fetch_points_cached( $min_lat, $min_lng, $max_lat, $max_lng, $pvz_types );

			return array_values( array_filter( array_map( [ $this, 'map_sparse_point' ], $raw_points ) ) );
		}

		/**
		 * Fetches one point's full record by its numeric listing `id` (TRAP 2 — see file
		 * docblock). Deliberately requires NEITHER `ACCOUNT_ID_CONSTANT` nor
		 * `SETTINGS_ID_CONSTANT`: the measured `GET /api/pvz/{id}` contract carries no
		 * account-identifying parameter at all, so gating this call behind those constants
		 * would guard a dependency that does not exist — the fixture's activation gate
		 * (`WOODEV_TEST_PICKUP_LIVE_POCHTA`, checked only in the wiring file, mirroring how
		 * Yandex's own live-switch is never referenced inside its class either) is what keeps
		 * this call from ever firing on an unconfigured install.
		 *
		 * @inheritDoc
		 *
		 * @throws \Woodev_API_Exception On a sandbox transport, HTTP, or unshaped-but-not-empty
		 *                                 payload failure — see the file docblock's FAIL SOFT
		 *                                 section. TRAP 2's specific empty-success shape is
		 *                                 NOT one of these — see {@see self::request_point_details()}.
		 */
		public function fetch_details( string $point_id ): ?\Woodev\Framework\Shipping\Pickup\Pickup_Point {
			$raw_point = $this->request_point_details( $point_id );

			if ( null === $raw_point ) {
				return null;
			}

			return $this->map_full_point( $raw_point );
		}

		/**
		 * Maps `Point_Query::get_types()` (this fixture's own `PVZ`/`POSTAMAT` codes) back onto
		 * Pochta's `pvzType` values — see the file docblock's TYPE FILTER section.
		 *
		 * @param string[] $type_codes Requested type codes, or an empty array meaning "all types".
		 *
		 * @return string[] Pochta `pvzType` values to send, or an empty array when the request
		 *                   cannot match anything this source knows how to translate.
		 */
		private function pvz_types_for_query( array $type_codes ): array {
			if ( [] === $type_codes ) {
				return array_keys( self::TYPE_MAP );
			}

			$pvz_types = [];

			foreach ( self::TYPE_MAP as $pvz_type => $mapping ) {
				if ( in_array( $mapping['code'], $type_codes, true ) ) {
					$pvz_types[] = $pvz_type;
				}
			}

			return $pvz_types;
		}

		/**
		 * Returns the raw sparse point records for one bbox+type-filter combination, from
		 * cache when fresh — see the file docblock's CACHING section.
		 *
		 * @param float    $min_lat   Minimum latitude.
		 * @param float    $min_lng   Minimum longitude.
		 * @param float    $max_lat   Maximum latitude.
		 * @param float    $max_lng   Maximum longitude.
		 * @param string[] $pvz_types Pochta `pvzType` values to request.
		 *
		 * @return array<int, mixed> Raw records from the API's `data` array, across all pages.
		 *
		 * @throws \Woodev_API_Exception On a sandbox transport, HTTP, settings-resolution, or
		 *                                 payload-shape failure.
		 */
		private function fetch_points_cached( float $min_lat, float $min_lng, float $max_lat, float $max_lng, array $pvz_types ): array {
			$cache_key = $this->cache_key_for_query( $min_lat, $min_lng, $max_lat, $max_lng, $pvz_types );
			$cached    = get_transient( $cache_key );

			if ( is_array( $cached ) ) {
				return $cached;
			}

			$points = $this->request_points_paginated( $min_lat, $min_lng, $max_lat, $max_lng, $pvz_types );

			set_transient( $cache_key, $points, self::POINTS_CACHE_TTL );

			return $points;
		}

		/**
		 * Builds the bbox+type-addressed cache key — see the file docblock's CACHING section
		 * for why a viewport source cannot reuse Yandex's single global transient.
		 *
		 * @param float    $min_lat   Minimum latitude.
		 * @param float    $min_lng   Minimum longitude.
		 * @param float    $max_lat   Maximum latitude.
		 * @param float    $max_lng   Maximum longitude.
		 * @param string[] $pvz_types Pochta `pvzType` values narrowing this request.
		 *
		 * @return string
		 */
		private function cache_key_for_query( float $min_lat, float $min_lng, float $max_lat, float $max_lng, array $pvz_types ): string {
			$rounded = array_map(
				static fn( float $v ): float => round( $v, self::CACHE_BBOX_PRECISION ),
				[ $min_lat, $min_lng, $max_lat, $max_lng ]
			);

			$sorted_types = $pvz_types;
			sort( $sorted_types );

			return self::POINTS_CACHE_PREFIX . md5( implode( ',', $rounded ) . '|' . implode( ',', $sorted_types ) );
		}

		/**
		 * Performs the live paginated call to Pochta's listing endpoint and returns every
		 * page's `data` merged, bounded by `MAX_PAGES` — see the file docblock's PAGINATION
		 * section.
		 *
		 * @param float    $min_lat   Minimum latitude.
		 * @param float    $min_lng   Minimum longitude.
		 * @param float    $max_lat   Maximum latitude.
		 * @param float    $max_lng   Maximum longitude.
		 * @param string[] $pvz_types Pochta `pvzType` values to request.
		 *
		 * @return array<int, mixed>
		 *
		 * @throws \Woodev_API_Exception On a sandbox transport, HTTP, settings-resolution, or
		 *                                 payload-shape failure.
		 */
		private function request_points_paginated( float $min_lat, float $min_lng, float $max_lat, float $max_lng, array $pvz_types ): array {
			$settings_id = $this->resolve_settings_id();

			$all_points  = [];
			$page        = 1;
			$total_pages = 1;

			do {
				$body = $this->request_points_page( $settings_id, $min_lat, $min_lng, $max_lat, $max_lng, $pvz_types, $page );

				$all_points = array_merge( $all_points, $body['data'] );

				// A missing/non-numeric totalPages defaults to 1 (this page is the only page)
				// rather than looping — an upstream shape change must not spin this forever.
				$total_pages = isset( $body['totalPages'] ) && is_numeric( $body['totalPages'] )
					? max( 1, (int) $body['totalPages'] )
					: 1;

				++$page;
			} while ( $page <= $total_pages && $page <= self::MAX_PAGES );

			return $all_points;
		}

		/**
		 * Performs one page of the live listing call.
		 *
		 * TRAP 1: `currentTopRightPoint`/`currentBottomLeftPoint` are `[lng, lat]`, NOT
		 * `[lat, lng]` — see the file docblock's own TRAP 1 section. `Point_Query::get_bounds()`
		 * gives `[min_lat, min_lng, max_lat, max_lng]`; TopRight takes the maxima, BottomLeft
		 * the minima.
		 *
		 * @param int      $settings_id Resolved settings id.
		 * @param float    $min_lat     Minimum latitude.
		 * @param float    $min_lng     Minimum longitude.
		 * @param float    $max_lat     Maximum latitude.
		 * @param float    $max_lng     Maximum longitude.
		 * @param string[] $pvz_types   Pochta `pvzType` values to request.
		 * @param int      $page        1-based page number.
		 *
		 * @return array<string, mixed> Decoded response body.
		 *
		 * @throws \Woodev_API_Exception On a transport failure, a non-200 HTTP response, or a
		 *                                 body that does not decode to `{ "data": [...] }`.
		 */
		private function request_points_page( int $settings_id, float $min_lat, float $min_lng, float $max_lat, float $max_lng, array $pvz_types, int $page ): array {
			$response = wp_safe_remote_post(
				self::HOST . self::LIST_PATH,
				[
					'timeout' => self::REQUEST_TIMEOUT,
					'headers' => [ 'Content-Type' => 'application/json' ],
					'body'    => wp_json_encode(
						[
							'settings_id'            => $settings_id,
							'pageSize'               => self::PAGE_SIZE,
							'page'                   => $page,
							'currentTopRightPoint'   => [ $max_lng, $max_lat ],
							'currentBottomLeftPoint' => [ $min_lng, $min_lat ],
							'pvzType'                => $pvz_types,
						]
					),
				]
			);

			if ( is_wp_error( $response ) ) {
				throw new \Woodev_API_Exception(
					sprintf( 'Pochta sandbox transport failure: %s', $response->get_error_message() )
				);
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( 200 !== $code ) {
				throw new \Woodev_API_Exception( sprintf( 'Pochta sandbox returned HTTP %d.', $code ), $code );
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( ! is_array( $body ) || ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
				throw new \Woodev_API_Exception( 'Pochta sandbox returned an unexpected payload shape.' );
			}

			return $body;
		}

		/**
		 * Resolves the `settings_id` — the constant when defined, else via
		 * `WOODEV_TEST_POCHTA_ACCOUNT_ID` and a cached call to `public_show` — see the file
		 * docblock's SETTINGS_ID RESOLUTION section.
		 *
		 * @return int
		 *
		 * @throws \Woodev_API_Exception When neither constant is defined (no request is made —
		 *                                 this is the security-relevant guard, pinned by a
		 *                                 `never()` transport test), or on a transport, HTTP, or
		 *                                 payload-shape failure resolving via `ACCOUNT_ID_CONSTANT`.
		 */
		private function resolve_settings_id(): int {
			if ( defined( self::SETTINGS_ID_CONSTANT ) ) {
				return (int) constant( self::SETTINGS_ID_CONSTANT );
			}

			if ( ! defined( self::ACCOUNT_ID_CONSTANT ) ) {
				throw new \Woodev_API_Exception(
					sprintf(
						'Pochta sandbox account not configured: define %s directly, or %s to resolve it via %s.',
						self::SETTINGS_ID_CONSTANT,
						self::ACCOUNT_ID_CONSTANT,
						self::SETTINGS_PATH
					)
				);
			}

			$cached = get_transient( self::SETTINGS_CACHE_KEY );

			// A transient round-trips through the options table as a string in real WordPress
			// (scalars are not re-typed on the way back out), so this checks is_numeric() and
			// casts, rather than is_int() — an is_int() check would never match a REAL cached
			// value and would silently defeat the cache on every request past the first.
			if ( is_numeric( $cached ) ) {
				return (int) $cached;
			}

			$settings_id = $this->request_settings_id();

			set_transient( self::SETTINGS_CACHE_KEY, $settings_id, self::SETTINGS_CACHE_TTL );

			return $settings_id;
		}

		/**
		 * Performs the live call to `public_show` and returns the resolved `settings_id`.
		 *
		 * @return int
		 *
		 * @throws \Woodev_API_Exception On a transport failure, a non-200 HTTP response, or a
		 *                                 body carrying neither `id` nor `pickupId`.
		 */
		private function request_settings_id(): int {
			$response = wp_safe_remote_post(
				self::HOST . self::SETTINGS_PATH,
				[
					'timeout' => self::REQUEST_TIMEOUT,
					'headers' => [ 'Content-Type' => 'application/json' ],
					'body'    => wp_json_encode(
						[
							'accountId'   => (string) constant( self::ACCOUNT_ID_CONSTANT ),
							'accountType' => self::ACCOUNT_TYPE,
						]
					),
				]
			);

			if ( is_wp_error( $response ) ) {
				throw new \Woodev_API_Exception(
					sprintf( 'Pochta sandbox transport failure resolving settings_id: %s', $response->get_error_message() )
				);
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( 200 !== $code ) {
				throw new \Woodev_API_Exception( sprintf( 'Pochta sandbox settings lookup returned HTTP %d.', $code ), $code );
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( ! is_array( $body ) ) {
				throw new \Woodev_API_Exception( 'Pochta sandbox settings lookup returned an unexpected payload shape.' );
			}

			// The measured response carries the settings id as either `id` or `pickupId` — the
			// real widget bundle (vendored at plugins-reference/pochta-widget/) reads `.id`, so
			// that is tried first; `pickupId` is the brief's own alternate name for the same value.
			$settings_id = $body['id'] ?? $body['pickupId'] ?? null;

			if ( ! is_numeric( $settings_id ) ) {
				throw new \Woodev_API_Exception( 'Pochta sandbox settings lookup returned an unexpected payload shape.' );
			}

			return (int) $settings_id;
		}

		/**
		 * Performs the live details call and returns the decoded body, or null for TRAP 2's
		 * empty-success shape — see the file docblock's own TRAP 2 section for why null (not a
		 * thrown exception) is the correct read of the interface's `@return` contract here:
		 * nothing failed upstream, this specific id simply does not resolve to a shaped record.
		 *
		 * @param string $point_id Numeric listing `id` (NOT `deliveryPointIndex` — TRAP 2).
		 *
		 * @return array<string, mixed>|null
		 *
		 * @throws \Woodev_API_Exception On a transport failure or a non-200 HTTP response —
		 *                                 real carrier failures, unlike TRAP 2's empty success.
		 */
		private function request_point_details( string $point_id ): ?array {
			$response = wp_safe_remote_get(
				self::HOST . self::DETAILS_PATH . rawurlencode( $point_id ),
				[ 'timeout' => self::REQUEST_TIMEOUT ]
			);

			if ( is_wp_error( $response ) ) {
				throw new \Woodev_API_Exception(
					sprintf( 'Pochta sandbox transport failure: %s', $response->get_error_message() )
				);
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( 200 !== $code ) {
				throw new \Woodev_API_Exception( sprintf( 'Pochta sandbox returned HTTP %d.', $code ), $code );
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( ! is_array( $body ) || ! isset( $body['id'] ) ) {
				return null;
			}

			return $body;
		}

		/**
		 * Reads one `address` sub-field as a trimmed string, treating an absent key, a JSON
		 * `null` (via `isset()`), or any non-scalar value (an un-flattened nested object) as
		 * "no value" rather than coercing it — the same "drop, do not coerce" rule
		 * `Pickup_Point::sanitize_string_list()` already applies elsewhere (issue #154).
		 *
		 * @param array<string, mixed> $address Raw `address` object.
		 * @param string                $key     Field name to read.
		 *
		 * @return string
		 */
		private static function address_field( array $address, string $key ): string {
			return isset( $address[ $key ] ) && is_scalar( $address[ $key ] ) ? trim( (string) $address[ $key ] ) : '';
		}

		/**
		 * Composes the display address from the structural `address` object — see the file
		 * docblock's ADDRESS COMPOSITION section for where this algorithm comes from.
		 *
		 * @param array<string, mixed> $address Raw `address` object.
		 *
		 * @return string
		 */
		private function compose_address( array $address ): string {
			$house_number = self::address_field( $address, 'house' ) . self::address_field( $address, 'letter' );
			$slash        = self::address_field( $address, 'slash' );
			$corpus       = self::address_field( $address, 'corpus' );
			$building     = self::address_field( $address, 'building' );

			$house_part = implode(
				' ',
				array_filter(
					[
						$house_number,
						'' !== $slash ? '/' . $slash : '',
						'' !== $corpus ? 'к. ' . $corpus : '',
						'' !== $building ? 'стр. ' . $building : '',
					],
					static fn( string $part ): bool => '' !== $part
				)
			);

			$street_part = implode(
				' ',
				array_filter(
					[ self::address_field( $address, 'street' ), $house_part ],
					static fn( string $part ): bool => '' !== $part
				)
			);

			return implode(
				', ',
				array_filter(
					[ self::address_field( $address, 'place' ), self::address_field( $address, 'location' ), $street_part ],
					static fn( string $part ): bool => '' !== $part
				)
			);
		}

		/**
		 * Extracts `[lat, lng]` from a raw record's `geo.coordinates`, or null when the field
		 * is missing, the wrong shape, or non-numeric — see the file docblock's own TRAP 1
		 * section for the `[lng, lat]` -> `[lat, lng]` reorder this performs.
		 *
		 * @param array<string, mixed> $raw_point One raw record from the listing or details call.
		 *
		 * @return array{0: float, 1: float}|null `[lat, lng]`, or null.
		 */
		private function extract_coordinates( array $raw_point ): ?array {
			$geo         = is_array( $raw_point['geo'] ?? null ) ? $raw_point['geo'] : [];
			$coordinates = is_array( $geo['coordinates'] ?? null ) ? array_values( $geo['coordinates'] ) : null;

			if ( null === $coordinates || 2 !== count( $coordinates ) ) {
				return null;
			}

			if ( ! is_numeric( $coordinates[0] ) || ! is_numeric( $coordinates[1] ) ) {
				return null;
			}

			// geo.coordinates is [lng, lat] — see file docblock TRAP 1 section.
			return [ (float) $coordinates[1], (float) $coordinates[0] ];
		}

		/**
		 * Synthesizes a display name for a point — see the file docblock's NAME SYNTHESIS
		 * section for why neither carrier response reliably supplies one, and why this
		 * fallback mirrors the real widget's own subtitle template.
		 *
		 * @param array<string, mixed>                  $raw_point One raw record.
		 * @param array{code: string, label: string, short: string} $type Resolved TYPE_MAP entry.
		 *
		 * @return string
		 */
		private function synthesize_name( array $raw_point, array $type ): string {
			if ( isset( $raw_point['brandName'] ) && is_string( $raw_point['brandName'] ) && '' !== trim( $raw_point['brandName'] ) ) {
				return trim( $raw_point['brandName'] );
			}

			$address = is_array( $raw_point['address'] ?? null ) ? $raw_point['address'] : [];
			$place   = self::address_field( $address, 'place' );
			$index   = $raw_point['deliveryPointIndex'] ?? '';

			return trim( sprintf( '%s №%s, %s', $type['label'], (string) $index, $place ), ' ,' );
		}

		/**
		 * Maps one raw SPARSE listing record onto the framework's normalized payload — no
		 * payment methods, work_time, services, accepts_cod, or max_weight until
		 * `fetch_details()` runs. Returns null (skips the point) for a non-array record, an
		 * unrecognised `type`, or unusable coordinates — one bad record must not empty the
		 * whole map (interface contract).
		 *
		 * @param mixed $raw_point One element of the listing's `data` array.
		 *
		 * @return \Woodev\Framework\Shipping\Pickup\Pickup_Point|null
		 */
		private function map_sparse_point( $raw_point ): ?\Woodev\Framework\Shipping\Pickup\Pickup_Point {
			if ( ! is_array( $raw_point ) ) {
				return null;
			}

			$type = self::TYPE_MAP[ (string) ( $raw_point['type'] ?? '' ) ] ?? null;

			if ( null === $type ) {
				return null;
			}

			$coordinates = $this->extract_coordinates( $raw_point );

			if ( null === $coordinates ) {
				return null;
			}

			[ $lat, $lng ] = $coordinates;
			$address = is_array( $raw_point['address'] ?? null ) ? $raw_point['address'] : [];

			return \Woodev\Framework\Shipping\Pickup\Pickup_Point::from_array(
				[
					'id'               => $raw_point['id'] ?? '',
					'name'             => $this->synthesize_name( $raw_point, $type ),
					'lat'              => $lat,
					'lng'              => $lng,
					'address'          => $this->compose_address( $address ),
					'type'             => [ 'code' => $type['code'], 'label' => $type['label'] ],
					'point_short_name' => $type['short'],
					'locality'         => self::address_field( $address, 'place' ),
				]
			);
		}

		/**
		 * Maps one raw FULL detail record onto the framework's normalized payload, adding
		 * everything the sparse listing omits. Returns null for an unrecognised `type` or
		 * unusable coordinates, same rule as the sparse mapper.
		 *
		 * @param array<string, mixed> $raw_point Decoded body from `GET /api/pvz/{id}`.
		 *
		 * @return \Woodev\Framework\Shipping\Pickup\Pickup_Point|null
		 */
		private function map_full_point( array $raw_point ): ?\Woodev\Framework\Shipping\Pickup\Pickup_Point {
			$type = self::TYPE_MAP[ (string) ( $raw_point['type'] ?? '' ) ] ?? null;

			if ( null === $type ) {
				return null;
			}

			$coordinates = $this->extract_coordinates( $raw_point );

			if ( null === $coordinates ) {
				return null;
			}

			[ $lat, $lng ] = $coordinates;
			$address = is_array( $raw_point['address'] ?? null ) ? $raw_point['address'] : [];

			return \Woodev\Framework\Shipping\Pickup\Pickup_Point::from_array(
				[
					'id'               => $raw_point['id'] ?? '',
					'name'             => $this->synthesize_name( $raw_point, $type ),
					'lat'              => $lat,
					'lng'              => $lng,
					'address'          => $this->compose_address( $address ),
					'type'             => [ 'code' => $type['code'], 'label' => $type['label'] ],
					'point_short_name' => $type['short'],
					'locality'         => self::address_field( $address, 'place' ),
					'work_time'        => $this->format_work_time( $raw_point ),
					'payment_methods'  => $this->map_payment_methods( $raw_point ),
					'services'         => $this->map_services( $raw_point ),
					// cashPayment (bool) IS accepts_cod — see file docblock PAYLOAD section.
					// Absent entirely means "the carrier did not say", matching
					// Pickup_Point::from_array()'s own null default.
					'accepts_cod'      => isset( $raw_point['cashPayment'] ) ? (bool) $raw_point['cashPayment'] : null,
					'photos'           => [],
				]
			);
		}

		/**
		 * Flattens `workTime` (a list of already-formatted strings) and `holidays` into one
		 * display string — unlike Yandex's structured `schedule`, there is nothing here to
		 * parse or group, only to join.
		 *
		 * @param array<string, mixed> $raw_point Full detail record.
		 *
		 * @return string
		 */
		private function format_work_time( array $raw_point ): string {
			$lines = [];

			if ( is_array( $raw_point['workTime'] ?? null ) ) {
				foreach ( $raw_point['workTime'] as $line ) {
					if ( is_string( $line ) && '' !== trim( $line ) ) {
						$lines[] = trim( $line );
					}
				}
			}

			if ( isset( $raw_point['holidays'] ) && is_string( $raw_point['holidays'] ) && '' !== trim( $raw_point['holidays'] ) ) {
				$lines[] = 'Выходные: ' . trim( $raw_point['holidays'] );
			}

			return implode( '; ', $lines );
		}

		/**
		 * Maps the full record's payment-method booleans to Russian display labels.
		 *
		 * @param array<string, mixed> $raw_point Full detail record.
		 *
		 * @return string[]
		 */
		private function map_payment_methods( array $raw_point ): array {
			$labels = [];

			if ( ! empty( $raw_point['cardPayment'] ) ) {
				$labels[] = 'Оплата картой';
			}

			if ( ! empty( $raw_point['cashPayment'] ) ) {
				$labels[] = 'Оплата наличными';
			}

			return $labels;
		}

		/**
		 * Maps the full record's service booleans to Russian display labels — see the file
		 * docblock's PAYLOAD table for which four of the measured flags have a home here.
		 *
		 * @param array<string, mixed> $raw_point Full detail record.
		 *
		 * @return string[]
		 */
		private function map_services( array $raw_point ): array {
			$labels = [];

			if ( ! empty( $raw_point['withFitting'] ) ) {
				$labels[] = 'Примерка';
			}

			if ( ! empty( $raw_point['contentsChecking'] ) ) {
				$labels[] = 'Проверка вложений';
			}

			if ( ! empty( $raw_point['partialRedemption'] ) ) {
				$labels[] = 'Частичный выкуп';
			}

			if ( ! empty( $raw_point['returnAvailable'] ) ) {
				$labels[] = 'Возможен возврат';
			}

			return $labels;
		}
	}
}
