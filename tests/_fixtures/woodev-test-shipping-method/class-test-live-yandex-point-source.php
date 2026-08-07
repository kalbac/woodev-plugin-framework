<?php
/**
 * Woodev_Test_Live_Yandex_Point_Source — OPT-IN STRATEGY_BULK fixture Point_Source that
 * calls the REAL Yandex.Delivery `pickup-points/list` endpoint on Yandex's own TEST host,
 * proving the framework's `Point_Source` contract against a live carrier response instead
 * of a static array (issue #185).
 *
 * PER-POINT ICONS BY OPERATOR (issue #193): {@see self::icons_for_operator()} gives every
 * `5post` point its own icon, keyed off the raw record's `operator_id` — the field the
 * type-level tier cannot read at all, and the only one that separates a 5post point from a
 * Yandex.Market one when both report the SAME `type: "pickup_point"` (see PAYLOAD below).
 * This is `Pickup_Point`'s new per-point `icons` override (cascade tier 1) wired to a real
 * consumer on real data.
 *
 * ACTIVATION: never on by default. `woodev-test-shipping-method.php` only constructs this
 * class when `WOODEV_TEST_PICKUP_LIVE_YANDEX` is truthy (defined near
 * `WOODEV_TEST_PICKUP_STRATEGY` at the top of that file, default `false`). Neither
 * `composer test:unit` nor `composer test:integration` nor CI ever set that constant, so
 * this class is declared (cheap — no I/O happens merely by `require_once`ing or
 * `class_exists`-guarding it) but never INSTANTIATED, and therefore never makes a network
 * call, in either suite. An operator flips it on the same way as the strategy switch — a
 * `define( 'WOODEV_TEST_PICKUP_LIVE_YANDEX', true );` in wp-config.php or the `.wp-env.json`
 * `config` block — to drive the rig's map off real Yandex sandbox data.
 *
 * TOKEN — NOT IN THIS FILE, AND NOT IN THIS REPOSITORY. The bearer token is read at request
 * time from the constant named by `self::TOKEN_CONSTANT`
 * (`WOODEV_TEST_YANDEX_SANDBOX_TOKEN`); when it is undefined the source throws instead of
 * calling out, so the failure names its own cause.
 *
 * It is deliberately not a literal here. The value lives in the reference plugin
 * (`plugins-reference/woocommerce-yandex-delivery/woocommerce-yandex-delivery.php`, the
 * `is_test_environment()` branch), and that directory is gitignored — so committing the
 * literal would have moved a THIRD PARTY's credential into a PUBLIC repository's history for
 * the first time. That it is only a sandbox token, and that it ships inside every install of
 * that plugin already, lowers the impact but does not make publishing it ours to do: a
 * credential scanner hitting a public repo is a plausible route to that token being revoked,
 * which would break the reference plugin's test mode for its actual customers.
 *
 * Define it alongside the activation constant — in `wp-config.php`, or in the GITIGNORED
 * `.wp-env.override.json` rather than the tracked `.wp-env.json`:
 *
 *     define( 'WOODEV_TEST_YANDEX_SANDBOX_TOKEN', '…' );
 *
 * A real shipping plugin supplies ITS OWN token, from its own Yandex.Delivery cabinet, and
 * likewise never commits it.
 *
 * PAYLOAD, verified by hand 07.08.2026 (not taken from documentation — see the "reference
 * is not enough" lesson this project has been burned by before): four live calls against
 * `POST /api/b2b/platform/pickup-points/list` with `geo_id: 213` (Moscow):
 *  - `{}` (no `type`)         -> HTTP 200, 812 points: 808 `pickup_point` + 4 `terminal`.
 *  - `{"type":"pickup_point"}` -> HTTP 200, 808 points, all `pickup_point` (type DOES filter
 *     server-side, contrary to an earlier, unverified assumption that an unrecognised/any
 *     `type` value is ignored).
 *  - `{"type":"terminal"}`     -> HTTP 200, 4 points, all `terminal`.
 *  - `{"type":"bogus_type"}`   -> HTTP 400, `{"code":"400","message":"Value of 'type' (bogus_type)
 *     is not parseable into enum"}` — an unrecognised type ERRORS, it is not ignored.
 * This class therefore omits `type` entirely and fetches BOTH shapes in one call, mapping
 * each raw record's own `type` field client-side (see `TYPE_MAP`) — one HTTP round trip and
 * one cache entry serves every marker on the map, and the interface docblock explicitly
 * permits a STRATEGY_BULK source to ignore `Point_Query::get_types()` and let the framework
 * filter client-side.
 *
 * Observed record shape (both `pickup_point` and `terminal`): `id`, `operator_station_id`,
 * `operator_id`, `name`, `type`, `position{latitude,longitude}`,
 * `address{geoId,country,region,subRegion,locality,street,house,housing,apartment,building,
 * comment,full_address,postal_code}`, `instruction`, `payment_methods[]`, `contact{phone}`,
 * `schedule{time_zone,restrictions[{days[],time_from{hours,minutes},time_to{...}}]}`,
 * `is_yandex_branded`, `is_market_partner`, `is_dark_store`, `is_post_office`, `dayoffs[]`,
 * `deactivation_date`, `deactivation_date_predicted_debt`, `available_for_dropoff`,
 * `available_for_c2c_dropoff`, `pickup_services{is_fitting_allowed,is_partial_refuse_allowed,
 * is_paperless_pickup_allowed,is_unboxing_allowed}`. No `max_weight`/weight-limit field
 * anywhere in the payload, so `Pickup_Point::get_max_weight()` stays null for every live
 * point — this is an honest "the carrier did not say", not a mapping gap. The `address.house`
 * string is DIRTY for one operator — see {@see self::strip_stringified_boolean()} (issue #210).
 *
 * SCOPE: like `Woodev_Test_Bulk_Point_Source`, this fixture serves exactly one city
 * (Moscow, Yandex `geo_id` 213) — `MOSCOW_GEO_ID` is hardcoded rather than resolved from
 * the requested locality string, because building a real locality->geo_id resolver
 * (Yandex's own `location/detect` endpoint) is a second live integration this card never
 * asked for. `fetch_points()` still gates on the requested locality matching this fixture's
 * own city string, exactly like the static bulk source, so the rig's `emptyLocality` state
 * stays reachable.
 *
 * FAIL SOFT: the `Point_Source` interface REQUIRES a transport/auth/API failure to surface
 * as a thrown `\Woodev_API_Exception`, never as a silently empty list (see the interface's
 * own `@throws` docblock) — and the REST layer (`class-pickup-controller.php`) already
 * catches exactly that exception and turns it into a `502 WP_Error` the browser's
 * error/retry UI understands (see `upstream_error()` there). So "fail soft" here means:
 * throw on every real failure (transport, non-200, unparseable/unexpected body) and let the
 * framework's EXISTING retry surface handle it — reinventing a bespoke fallback (e.g.
 * silently returning stale/empty data) would both violate the interface contract and hide a
 * real sandbox outage as "this city has no pickup points". `wp_safe_remote_post()` is used
 * (not the unsafe variant) with an explicit `REQUEST_TIMEOUT` so a hung sandbox degrades to
 * a bounded error, not an indefinitely blocked picker. NOTE: unlike the local-issuer case in
 * `docs-internal/gotchas/wp-safe-remote-request-local-rig.md`, no `http_request_host_is_external`
 * / `http_allowed_safe_ports` workaround is needed here — Yandex's TEST host is a PUBLIC
 * host on the standard port 443, exactly what `wp_safe_remote_request()`'s validator allows;
 * that gotcha only bites a PRIVATE/loopback host or a non-standard port.
 *
 * CACHING: one transient covers the whole Moscow point list (both types), refreshed once
 * per `CACHE_TTL`. The value matches this framework's own established convention for
 * cacheable API responses — `Woodev_Cacheable_Request_Trait::$cache_lifetime` (see
 * `woodev/api/traits/cacheable-request-trait.php`) defaults to `86400` (one day) — rather
 * than inventing a fixture-specific number. A day is long enough that a rig session never
 * re-hits the sandbox on every map open/reload (the whole point of caching here), and short
 * enough that the next day's session sees a genuinely fresh pull rather than a
 * week-stale one. Real pickup-point lists do not churn minute-to-minute, and this is a
 * manually-opted-in dev/demo path, not a production freshness guarantee.
 *
 * SCHEDULE: `Pickup_Point::work_time` is a plain string; Yandex's `schedule` is structured
 * (per-weekday time restrictions). `flatten_schedule()` groups consecutive weekdays sharing
 * an identical time range into readable spans ("Пн–Вс 00:00–23:59"). This is a flattening,
 * not a redesign of the point contract for structured schedules — that is issue #152,
 * explicitly out of scope here. `dayoffs`/`deactivation_date` (specific calendar exceptions)
 * have no home in a flat string either and are dropped rather than mushed in.
 *
 * PAYMENT METHODS: raw API codes (`already_paid`, `card_on_receipt`, `bound_card`,
 * `postpay` — confirmed live, matching the brief) are display text once they reach the
 * customer (issue #154), so `PAYMENT_METHOD_LABELS` maps each known code to a Russian
 * label; an unrecognised code is DROPPED rather than shown as a raw English string to the
 * customer, the same "drop, do not coerce" rule `Pickup_Point::sanitize_string_list()`
 * already applies to a non-string element.
 *
 * WORDING (issue #200, operator's decision, 08.08.2026): payment methods now render as
 * CHIPS, same as services — a long label breaks a chip's layout exactly the way it broke a
 * tab in #199, so short register matters here too. `already_paid` was flatly wrong
 * ("Товар уже оплачен" reads as "the item is already paid for", not "pay in advance") and is
 * now `'Предоплата'`. `card_on_receipt` repeated "Оплата" that the section's own "Способы
 * оплаты" title already says, so the redundant word is dropped:
 * `'Оплата картой при получении'` -> `'Картой при получении'`. `bound_card`/`postpay` were
 * already short nouns in the same register as the other two — left unchanged.
 *
 * SHORT NAME (issue #207): Yandex's payload carries no short/abbreviated name — the observed
 * record shape below has `name` (the branch's own name, e.g. "Пятёрочка") and nothing else in
 * that register — so the tab label for co-located points fell through to `type.label` and read
 * "Пункт выдачи заказов 1" / "Пункт выдачи заказов 2", which does not fit a tab. `TYPE_MAP`
 * therefore carries a `short` string per type, fed into `point_short_name`: the framework
 * numbers co-located tabs, the domain names them (`buildTabs()` in `pickup-panels.js`).
 * `type.label` itself deliberately stays LONG — it is rendered only in the type-filter chips,
 * which have a full row to themselves and where "ПВЗ" would be needlessly terse. The two are
 * different surfaces, not two names for the same slot.
 *
 * Declared inside the plugin's init callback (`require_once`d from
 * `woodev-test-shipping-method.php`, same arrangement as `class-test-bulk-point-source.php`
 * — see that file's own docblock and
 * `docs-internal/gotchas/fixture-classes-must-live-inside-plugin-init.md`): a class naming a
 * `Woodev\Framework\*` interface in `implements` fatals if declared before the bootstrap has
 * selected a framework copy and registered its autoloader.
 *
 * @package Woodev_Test_Shipping_Method
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Test_Live_Yandex_Point_Source' ) ) {

	/**
	 * Class Woodev_Test_Live_Yandex_Point_Source
	 *
	 * Live Yandex.Delivery sandbox Point_Source — see the file-level docblock for the full
	 * rationale (activation gate, verified payload shape, caching, fail-soft behaviour).
	 */
	class Woodev_Test_Live_Yandex_Point_Source implements \Woodev\Framework\Shipping\Pickup\Point_Source {

		/**
		 * Name of the constant supplying the sandbox bearer token — see the file docblock's
		 * TOKEN section. The token itself is deliberately NOT in this file: this repository
		 * is PUBLIC, and the value is a third party's credential, not ours to publish.
		 */
		private const TOKEN_CONSTANT = 'WOODEV_TEST_YANDEX_SANDBOX_TOKEN';

		/** Yandex's TEST host — never the production `b2b-authproxy.taxi.yandex.net`. */
		private const TEST_HOST = 'https://b2b.taxi.tst.yandex.net';

		/** Verified live 07.08.2026 — see the file docblock's PAYLOAD section. */
		private const LIST_PATH = '/api/b2b/platform/pickup-points/list';

		/** Yandex `geo_id` for Moscow — see the file docblock's SCOPE section. */
		private const MOSCOW_GEO_ID = 213;

		/**
		 * The `operator_id` this fixture singles out for its own per-point icon (issue
		 * #193) — see {@see self::icons_for_operator()}.
		 */
		private const FIVE_POST_OPERATOR_ID = '5post';

		/**
		 * The fixture's one static "city" — mirrors
		 * `Woodev_Test_Bulk_Point_Source::FIXTURE_LOCALITY` so the rig's locality gating
		 * behaves identically regardless of which bulk source is active.
		 */
		private const FIXTURE_LOCALITY = 'Москва';

		/** Bounded so a hung sandbox degrades to an error, not an indefinite wait. */
		private const REQUEST_TIMEOUT = 8;

		/** One transient covers both point types for the one city this fixture serves. */
		private const CACHE_TRANSIENT_KEY = 'woodev_test_live_yandex_points_213';

		/** One day — matches `Woodev_Cacheable_Request_Trait`'s own default; see file docblock. */
		private const CACHE_TTL = DAY_IN_SECONDS;

		/**
		 * Maps Yandex's raw `type` values onto the fixture's existing type codes so the
		 * existing point icons (registered in `woodev-test-shipping-method.php` under
		 * exactly these `PVZ`/`POSTAMAT` keys) keep working — the framework compares type
		 * codes case-sensitively.
		 *
		 * `short` is this fixture's own tab wording (issue #207), NOT part of the framework's
		 * type shape: `Pickup_Point::from_array()` whitelists `code`/`label` out of the `type`
		 * array, so the extra key never reaches the point. It is fed separately into
		 * `point_short_name` by {@see self::map_point()} — see the file docblock's SHORT NAME
		 * section for why the label itself stays long.
		 */
		private const TYPE_MAP = [
			'pickup_point' => [
				'code'  => 'PVZ',
				'label' => 'Пункт выдачи заказов',
				'short' => 'ПВЗ',
			],
			'terminal'     => [
				'code'  => 'POSTAMAT',
				'label' => 'Постамат',
				'short' => 'Постамат',
			],
		];

		/**
		 * Russian display labels for the raw API payment-method codes — see the file
		 * docblock's WORDING note (issue #200) for why `already_paid`/`card_on_receipt`
		 * changed and the other two did not.
		 */
		private const PAYMENT_METHOD_LABELS = [
			'already_paid'    => 'Предоплата',
			'card_on_receipt' => 'Картой при получении',
			'bound_card'      => 'Привязанная карта',
			'postpay'         => 'Постоплата',
		];

		/** Russian weekday abbreviations, Yandex's 1 (Monday) .. 7 (Sunday). */
		private const DAY_LABELS = [
			1 => 'Пн',
			2 => 'Вт',
			3 => 'Ср',
			4 => 'Чт',
			5 => 'Пт',
			6 => 'Сб',
			7 => 'Вс',
		];

		/**
		 * @inheritDoc
		 */
		public function get_strategy(): string {
			return self::STRATEGY_BULK;
		}

		/**
		 * Returns every live Moscow point when the requested locality is this fixture's own
		 * city, or an empty list otherwise — mirrors
		 * `Woodev_Test_Bulk_Point_Source::fetch_points()` (issue #162).
		 *
		 * @inheritDoc
		 *
		 * @throws \Woodev_API_Exception On a sandbox transport, HTTP, or payload-shape
		 *                                 failure — see the file docblock's FAIL SOFT section.
		 */
		public function fetch_points( \Woodev\Framework\Shipping\Pickup\Point_Query $query ): array {
			if ( ! $this->locality_matches( $query->get_locality() ) ) {
				return [];
			}

			return array_values( array_filter( array_map(
				[ $this, 'map_point' ],
				$this->fetch_all_points_cached()
			) ) );
		}

		/**
		 * @inheritDoc
		 *
		 * @throws \Woodev_API_Exception On a sandbox transport, HTTP, or payload-shape
		 *                                 failure — see the file docblock's FAIL SOFT section.
		 */
		public function fetch_details( string $point_id ): ?\Woodev\Framework\Shipping\Pickup\Pickup_Point {
			foreach ( $this->fetch_all_points_cached() as $raw_point ) {
				if ( is_array( $raw_point ) && isset( $raw_point['id'] ) && $point_id === (string) $raw_point['id'] ) {
					return $this->map_point( $raw_point );
				}
			}

			return null;
		}

		/**
		 * Whether the requested locality names this fixture's own city, ignoring case and
		 * surrounding whitespace — identical rule to `Woodev_Test_Bulk_Point_Source`.
		 *
		 * @param string|null $locality Requested locality, guaranteed non-null by the
		 *                               framework for a STRATEGY_BULK source; the
		 *                               null-coalesce below is defensive only.
		 *
		 * @return bool
		 */
		private function locality_matches( ?string $locality ): bool {
			return mb_strtolower( trim( $locality ?? '' ) ) === mb_strtolower( self::FIXTURE_LOCALITY );
		}

		/**
		 * Returns the raw Yandex point list (both types, unmapped), from cache when fresh.
		 *
		 * Only a SUCCESSFUL fetch is cached — a thrown exception never reaches
		 * `set_transient()`, so a sandbox outage is retried on the very next request rather
		 * than being remembered as empty for a full day.
		 *
		 * @return array<int, mixed> Raw point records as decoded from the API's `points` array.
		 *
		 * @throws \Woodev_API_Exception On a sandbox transport, HTTP, or payload-shape failure.
		 */
		private function fetch_all_points_cached(): array {
			$cached = get_transient( self::CACHE_TRANSIENT_KEY );

			if ( is_array( $cached ) ) {
				return $cached;
			}

			$points = $this->request_points_from_yandex();

			set_transient( self::CACHE_TRANSIENT_KEY, $points, self::CACHE_TTL );

			return $points;
		}

		/**
		 * Performs the live call to Yandex's TEST sandbox and returns the raw `points` array.
		 *
		 * `type` is deliberately omitted from the request body — see the file docblock's
		 * PAYLOAD section for why an omitted `type` (both shapes in one call) is preferred
		 * over filtering server-side.
		 *
		 * @return array<int, mixed>
		 *
		 * @throws \Woodev_API_Exception On a transport failure (`wp_safe_remote_post()`
		 *                                 returning a `WP_Error`), a non-200 HTTP response, or
		 *                                 a body that does not decode to `{ "points": [...] }`.
		 */
		private function request_points_from_yandex(): array {
			$token = defined( self::TOKEN_CONSTANT ) ? (string) constant( self::TOKEN_CONSTANT ) : '';

			// Fail with the same exception type as every other failure here, so the REST layer
			// turns it into the existing 502 + retry UI rather than a bespoke path. The message
			// names the constant, because "no points" with a silent cause is exactly the kind of
			// thing that reads as a broken picker.
			if ( '' === $token ) {
				throw new \Woodev_API_Exception(
					sprintf(
						'Yandex.Delivery sandbox token missing: define %s to use the live point source.',
						self::TOKEN_CONSTANT
					)
				);
			}

			$response = wp_safe_remote_post(
				self::TEST_HOST . self::LIST_PATH,
				[
					'timeout' => self::REQUEST_TIMEOUT,
					'headers' => [
						'Authorization'   => 'Bearer ' . $token,
						'Content-Type'    => 'application/json',
						'Accept-Language' => 'ru',
					],
					'body'    => wp_json_encode( [ 'geo_id' => self::MOSCOW_GEO_ID ] ),
				]
			);

			if ( is_wp_error( $response ) ) {
				throw new \Woodev_API_Exception(
					sprintf( 'Yandex.Delivery sandbox transport failure: %s', $response->get_error_message() )
				);
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( 200 !== $code ) {
				throw new \Woodev_API_Exception(
					sprintf( 'Yandex.Delivery sandbox returned HTTP %d.', $code ),
					$code
				);
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( ! is_array( $body ) || ! isset( $body['points'] ) || ! is_array( $body['points'] ) ) {
				throw new \Woodev_API_Exception( 'Yandex.Delivery sandbox returned an unexpected payload shape.' );
			}

			return $body['points'];
		}

		/**
		 * Maps one raw Yandex record onto the framework's normalized payload shape and
		 * builds a {@see \Woodev\Framework\Shipping\Pickup\Pickup_Point} from it.
		 *
		 * Returns null (skips the point) for a non-array record or an unrecognised `type` —
		 * one bad/foreign-shaped record must not empty the whole map (interface contract),
		 * and a type outside `TYPE_MAP` has no matching marker icon anyway (file docblock).
		 * Every other validation (required fields, coordinate range) is delegated to
		 * `Pickup_Point::from_array()`, the framework's single source of truth for what a
		 * valid point looks like.
		 *
		 * @param mixed $raw_point One element of the API's `points` array.
		 *
		 * @return \Woodev\Framework\Shipping\Pickup\Pickup_Point|null
		 */
		private function map_point( $raw_point ): ?\Woodev\Framework\Shipping\Pickup\Pickup_Point {
			if ( ! is_array( $raw_point ) ) {
				return null;
			}

			$type = self::TYPE_MAP[ (string) ( $raw_point['type'] ?? '' ) ] ?? null;

			if ( null === $type ) {
				return null;
			}

			$position = is_array( $raw_point['position'] ?? null ) ? $raw_point['position'] : [];
			$address  = is_array( $raw_point['address'] ?? null ) ? $raw_point['address'] : [];
			$contact  = is_array( $raw_point['contact'] ?? null ) ? $raw_point['contact'] : [];
			$schedule = is_array( $raw_point['schedule'] ?? null ) ? $raw_point['schedule'] : [];
			$services = is_array( $raw_point['pickup_services'] ?? null ) ? $raw_point['pickup_services'] : [];

			return \Woodev\Framework\Shipping\Pickup\Pickup_Point::from_array(
				[
					'id'               => $raw_point['id'] ?? '',
					'name'             => $raw_point['name'] ?? '',
					'lat'              => $position['latitude'] ?? null,
					'lng'              => $position['longitude'] ?? null,
					'address'          => self::strip_stringified_boolean( $address['full_address'] ?? '' ),
					'type'             => $type,
					// Issue #207 — the domain names the tab, the framework numbers it. Yandex's
					// payload carries no short name of its own (see the file docblock's SHORT
					// NAME section), so the fixture supplies one per type.
					'point_short_name' => $type['short'],
					'locality'         => $address['locality'] ?? '',
					'postal_code'      => $address['postal_code'] ?? '',
					'phone'            => $contact['phone'] ?? '',
					'instruction'      => $raw_point['instruction'] ?? '',
					'work_time'        => $this->flatten_schedule( $schedule ),
					'payment_methods'  => $this->map_payment_methods(
						is_array( $raw_point['payment_methods'] ?? null ) ? $raw_point['payment_methods'] : []
					),
					'services'         => $this->map_services( $services ),
					'photos'           => [],
					'icons'            => $this->icons_for_operator( $raw_point['operator_id'] ?? null ),
				]
			);
		}

		/**
		 * Removes an upstream stringified boolean from the address (issue #210).
		 *
		 * The `5post` operator composes its `house` field upstream as
		 * `{house} к{housing} стр{building}` and renders a MISSING value as the literal
		 * `false`, so the customer is shown "Островитянова ул 19/22 кfalse". Yandex passes the
		 * string through untouched — its own structured `housing`/`building` keys arrive EMPTY,
		 * which is how we know the composition happened before Yandex, not here.
		 *
		 * Measured on the live sandbox, `geo_id: 213`, 812 points (08.08.2026): 679 affected,
		 * every one of them `operator_id: 5post`; the artefact is always the exact substring
		 * " кfalse" (space + Cyrillic `к` U+043A + `false`), always inside `house`; zero
		 * occurrences of `true`, `стрfalse`, or any other form. The `стр` slot is covered here
		 * anyway because the SAME composition can put the same literal there — that is the
		 * shape of the upstream bug, not speculation about a future one.
		 *
		 * Deliberately narrow: only a boolean literal directly behind one of those two
		 * prefixes is removed, so a real housing value ("17 к6") and any word that merely
		 * contains the letters ("Falsettoвая") survive. This lives in the FIXTURE, not the
		 * framework: the framework must never guess at the meaning of an address string, and a
		 * real carrier plugin owns the hygiene of the data it hands over — the same division
		 * of labour as `point_short_name` in issue #207.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $address Raw `address.full_address` from the API.
		 *
		 * @return string
		 */
		private static function strip_stringified_boolean( $address ): string {
			return (string) preg_replace( '/\s(?:к|стр)(?:false|true)\b/iu', '', (string) $address );
		}

		/**
		 * This fixture's consumer of {@see \Woodev\Framework\Shipping\Pickup\Pickup_Point}'s
		 * new per-point icon override (issue #193, cascade tier 1). Only a `5post` point
		 * gets one — every other operator (`market_l4g`, or the field absent entirely) falls
		 * through to the existing `PVZ`/`POSTAMAT` TYPE-level tier, unchanged.
		 *
		 * Live measurement, `geo_id: 213` (Moscow), 812 points: `5post`/`pickup_point`: 679,
		 * `market_l4g`/`pickup_point`: 129, `market_l4g`/`terminal`: 4. The type code alone
		 * cannot separate the 679 5post points from the 129 Yandex.Market ones — both report
		 * `type: "pickup_point"` — so this is the real-data case the whole cascade tier
		 * exists for, wired to the fixture's own live consumer.
		 *
		 * Reuses the fixture's EXISTING terminal SVGs — `yandex-delivery-map-pin-terminal
		 * [-active].svg`, already shipped for the `POSTAMAT` type-level icon just below in
		 * `woodev-test-shipping-method.php` — rather than adding new artwork: the office pin
		 * already draws every OTHER `pickup_point` (the type tier), so the terminal pin
		 * reused here is immediately visually distinguishable on the rig without a new file.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $operator_id Raw `operator_id` value from the API, or null when the
		 *                            record carries none.
		 *
		 * @return array{default: string, active: string}|null
		 */
		private function icons_for_operator( $operator_id ): ?array {
			if ( self::FIVE_POST_OPERATOR_ID !== $operator_id ) {
				return null;
			}

			$icons_url = plugins_url( 'assets/images', __FILE__ );

			return [
				'default' => $icons_url . '/yandex-delivery-map-pin-terminal.svg',
				'active'  => $icons_url . '/yandex-delivery-map-pin-terminal-active.svg',
			];
		}

		/**
		 * Maps raw API payment-method codes to Russian display labels, dropping any code
		 * this fixture does not recognise — see the file docblock's PAYMENT METHODS section.
		 *
		 * @param array<int, mixed> $codes Raw `payment_methods` values from the API.
		 *
		 * @return string[]
		 */
		private function map_payment_methods( array $codes ): array {
			$labels = [];

			foreach ( $codes as $code ) {
				if ( is_string( $code ) && isset( self::PAYMENT_METHOD_LABELS[ $code ] ) ) {
					$labels[] = self::PAYMENT_METHOD_LABELS[ $code ];
				}
			}

			return $labels;
		}

		/**
		 * Maps the `pickup_services` boolean flags to Russian display labels.
		 *
		 * @param array<string, mixed> $flags Raw `pickup_services` object from the API.
		 *
		 * @return string[]
		 */
		private function map_services( array $flags ): array {
			$labels = [];

			if ( ! empty( $flags['is_fitting_allowed'] ) ) {
				$labels[] = 'Примерка';
			}

			if ( ! empty( $flags['is_partial_refuse_allowed'] ) ) {
				$labels[] = 'Частичный отказ от заказа';
			}

			if ( ! empty( $flags['is_unboxing_allowed'] ) ) {
				$labels[] = 'Вскрытие упаковки';
			}

			if ( ! empty( $flags['is_paperless_pickup_allowed'] ) ) {
				$labels[] = 'Выдача без бумажных документов';
			}

			return $labels;
		}

		/**
		 * Flattens Yandex's structured `schedule.restrictions` into a readable string —
		 * see the file docblock's SCHEDULE section for why this stays a flattening rather
		 * than a redesign of `Pickup_Point::work_time` (issue #152).
		 *
		 * Consecutive weekdays sharing an identical time range are grouped into one span
		 * (e.g. every day 00:00-23:59 becomes "Пн–Вс 00:00–23:59" instead of seven repeated
		 * entries); non-consecutive or differing spans are joined with "; ".
		 *
		 * @param array<string, mixed> $schedule Raw `schedule` object from the API.
		 *
		 * @return string
		 */
		private function flatten_schedule( array $schedule ): string {
			$restrictions = is_array( $schedule['restrictions'] ?? null ) ? $schedule['restrictions'] : [];

			$range_by_day = [];

			foreach ( $restrictions as $restriction ) {
				if ( ! is_array( $restriction ) ) {
					continue;
				}

				$range = $this->format_time_range( $restriction );

				if ( '' === $range ) {
					continue;
				}

				foreach ( (array) ( $restriction['days'] ?? [] ) as $day ) {
					$day = (int) $day;

					if ( isset( self::DAY_LABELS[ $day ] ) ) {
						$range_by_day[ $day ] = $range;
					}
				}
			}

			if ( [] === $range_by_day ) {
				return '';
			}

			ksort( $range_by_day );

			return implode( '; ', $this->group_consecutive_days( $range_by_day ) );
		}

		/**
		 * Groups a day => time-range map into "Пн–Пт HH:MM–HH:MM"-style spans, merging
		 * consecutive weekday numbers that share an identical range.
		 *
		 * @param array<int, string> $range_by_day Sorted by day number; day => "HH:MM–HH:MM".
		 *
		 * @return string[]
		 */
		private function group_consecutive_days( array $range_by_day ): array {
			$groups       = [];
			$group_start  = null;
			$group_range  = null;
			$previous_day = null;

			foreach ( $range_by_day as $day => $range ) {
				$is_contiguous = ( null !== $previous_day && $day === $previous_day + 1 && $range === $group_range );

				if ( ! $is_contiguous ) {
					if ( null !== $group_start ) {
						$groups[] = $this->format_day_group( $group_start, $previous_day, $group_range );
					}

					$group_start = $day;
					$group_range = $range;
				}

				$previous_day = $day;
			}

			$groups[] = $this->format_day_group( $group_start, $previous_day, $group_range );

			return $groups;
		}

		/**
		 * Formats one grouped day span, e.g. "Пн–Пт 09:00–21:00" or "Вс 10:00–18:00".
		 *
		 * @param int    $start First day number of the span.
		 * @param int    $end   Last day number of the span.
		 * @param string $range Already-formatted "HH:MM–HH:MM" time range.
		 *
		 * @return string
		 */
		private function format_day_group( int $start, int $end, string $range ): string {
			$label = ( $start === $end )
				? self::DAY_LABELS[ $start ]
				: self::DAY_LABELS[ $start ] . '–' . self::DAY_LABELS[ $end ];

			return $label . ' ' . $range;
		}

		/**
		 * Formats one `{time_from, time_to}` restriction as "HH:MM–HH:MM", or '' when either
		 * side is missing its `hours`/`minutes`.
		 *
		 * @param array<string, mixed> $restriction One raw restriction entry.
		 *
		 * @return string
		 */
		private function format_time_range( array $restriction ): string {
			$from = is_array( $restriction['time_from'] ?? null ) ? $restriction['time_from'] : [];
			$to   = is_array( $restriction['time_to'] ?? null ) ? $restriction['time_to'] : [];

			if ( ! isset( $from['hours'], $from['minutes'], $to['hours'], $to['minutes'] ) ) {
				return '';
			}

			return sprintf(
				'%02d:%02d–%02d:%02d',
				(int) $from['hours'],
				(int) $from['minutes'],
				(int) $to['hours'],
				(int) $to['minutes']
			);
		}
	}
}
