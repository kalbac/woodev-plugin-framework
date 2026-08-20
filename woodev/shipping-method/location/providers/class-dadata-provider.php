<?php
/**
 * Woodev DaData Location Provider
 *
 * The framework's bundled, default implementation of the {@see \Woodev\Framework\Shipping\Location\Location_Provider}
 * contract (Task 7; spec D3, D4, D12, D15). Registered under
 * {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::DEFAULT_PROVIDER_ID}
 * (`'dadata'`) by {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::bundled_provider_classes()}
 * — that registry instantiates this class with ZERO constructor arguments and
 * `class_exists()`-guards the call, so every collaborator this class needs
 * (credentials, the HTTP client) is resolved lazily from inside its own methods,
 * never injected.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Location\Providers;

use Woodev\Framework\Shipping\Location\Abstract_Location_Provider;
use Woodev\Framework\Shipping\Location\Location_Provider;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Location\Location_Scope;
use Woodev\Framework\Shipping\Location\Locality_Key;
use Woodev\Framework\Shipping\Location\Location_Provider_Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Location\\Providers\\Dadata_Provider' ) ) :

	/**
	 * Bundled DaData location provider.
	 *
	 * **Reference basis.** DaData request/response shapes are taken from DaData's
	 * own documentation (dadata.ru/api/suggest/address/, .../detect_address_by_ip/,
	 * .../find-address/, .../clean/address/ — fetched 12.08.2026; the exact example
	 * payloads are pinned VERBATIM in `tests/unit/Shipping/Location/DadataProviderTest.php`),
	 * cross-checked against field-level behavior confirmed in the operator's own
	 * production plugins under `plugins-reference/` — specifically the
	 * settlement-granularity filter ({@see self::should_reject_settlement_row()})
	 * and the level→bounds vocabulary (`region`/`area`/`city`/`settlement`/`street`/`house`),
	 * both confirmed independently in `woocommerce-edostavka/assets/js/frontend/fields-autocomplete.js`
	 * AND the more modern `woocommerce-yandex-delivery/woodev/assets/js/frontend/woodev-dadata-suggestions.js`.
	 * None of those reference plugins proxy `suggest/address` or `clean/address`
	 * server-side (see {@see Dadata_Api_Client}'s own docblock) — every call in
	 * THIS class runs server-side, per D4, which is a deliberate departure from
	 * every reference plugin's actual (client-side, token-exposing) behavior.
	 *
	 * @since 2.0.2
	 */
	class Dadata_Provider extends Abstract_Location_Provider {

		/**
		 * This provider's id. MUST equal
		 * {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::DEFAULT_PROVIDER_ID} —
		 * kept as an independent literal (not a reference to that constant) for the
		 * same reason the registry itself documents: this file may not exist when
		 * the registry loads, so the registry cannot import it, and this file does
		 * not need to import the registry either. Pinned equal by
		 * `DadataProviderTest::test_provider_id_matches_the_registry_default_provider_id()`.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const PROVIDER_ID = 'dadata';

		/**
		 * Store-setting field id for the required DaData API token.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const FIELD_TOKEN = 'token';

		/**
		 * Store-setting field id for the optional DaData "Clean" API secret.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const FIELD_CLEAN_SECRET = 'clean_secret';

		/**
		 * Filter tag: widens/narrows the static country list {@see self::get_countries()}
		 * reports. Spec Task 7: "ship `[ 'RU' ]` and leave the filter ... for stores
		 * that want to widen it."
		 *
		 * @since 2.0.2
		 * @var string
		 */
		public const FILTER_COUNTRIES = 'woodev_location_provider_countries';

		/**
		 * The nine countries served by default (before {@see self::FILTER_COUNTRIES}
		 * runs) — the store operator's market-scope decision, not a limit of the
		 * DaData API; see {@see self::get_countries()}'s own docblock for the
		 * full rationale and the three measured data tiers.
		 *
		 * @since 2.0.2
		 * @var string[]
		 */
		private const DEFAULT_COUNTRIES = [ 'RU', 'BY', 'KZ', 'UZ', 'AM', 'AZ', 'KG', 'TJ', 'TM' ];

		/**
		 * Countries resolved by DaData's GeoNames tier — city granularity only,
		 * measured EMPTY for a street/house-bounded (`address`-level) query
		 * (see {@see self::narrow_suggest_levels_for_country()}). RU (ФИАС/ГАР)
		 * and the OpenStreetMap tier (BY, KZ, UZ) both resolve to house
		 * granularity and are deliberately NOT in this list.
		 *
		 * @since 2.0.2
		 * @var string[]
		 */
		private const GEONAMES_TIER_COUNTRIES = [ 'AM', 'AZ', 'KG', 'TJ', 'TM' ];

		/**
		 * RU (ФИАС) fias_level values ACCEPTED at a settlement-bound query:
		 * `4` (city) and `6` (settlement) — the ordinary, unambiguous cases.
		 * `1` (region) is accepted too, but ONLY conditionally — see
		 * {@see self::should_reject_settlement_row()}'s federal-city carve-out —
		 * so it is deliberately NOT in this flat accept-list.
		 *
		 * Supersedes the earlier `fias_level !== '65'`-only filter (the DaData
		 * "planning structure" noise level — confirmed VERBATIM in two
		 * independent reference plugins,
		 * `plugins-reference/woocommerce-edostavka/assets/js/frontend/fields-autocomplete.js:123-128`
		 * and `plugins-reference/woocommerce-yandex-delivery/woodev/assets/js/frontend/woodev-dadata-suggestions.js`'s
		 * `filterSuggestions()`): `65` is simply absent from this accept-list
		 * (and not `1`/`4`/`6` either), so it is rejected by the SAME rule below
		 * rather than by a second, overlapping check — the reference plugins'
		 * OWN second, address-level filter (`fias_level >= 4 OR city_fias_id in
		 * [Moscow, SPb]`) is still deliberately NOT ported: a fresh read of
		 * `fields-autocomplete.js:228-231` shows it contains a pre-existing bug
		 * (`$.inArray(...) === 0` evaluated INSIDE the array argument, which can
		 * never be true), and hardcoding Moscow/SPb GUIDs the way that filter
		 * does is exactly what {@see self::should_reject_settlement_row()}'s
		 * `region_fias_id === city_fias_id` federal-city detection replaces.
		 *
		 * @since 2.0.2
		 * @var string[]
		 */
		private const RU_SETTLEMENT_ACCEPTED_FIAS_LEVELS = [ '4', '6' ];

		/**
		 * The RU (ФИАС) fias_level value denoting a region-level row (`1`).
		 * Accepted at a settlement-bound query ONLY when the row is a FEDERAL
		 * CITY — {@see self::should_reject_settlement_row()} detects that as
		 * `region_fias_id === city_fias_id` (the docs' own definition), never by
		 * hardcoding Moscow/Saint-Petersburg GUIDs the way the reference plugins
		 * do.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private const RU_FIAS_LEVEL_REGION = '1';

		/**
		 * The RU (ФИАС) fias_level value meaning "foreign or empty" per DaData's
		 * own docs — every row outside RU (OpenStreetMap and GeoNames tiers
		 * alike) carries this value; it is meaningless as a granularity signal
		 * there, so {@see self::should_reject_settlement_row()} skips the whole
		 * RU-specific branch when it sees this value and falls back to the
		 * country-agnostic rule alone.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private const FIAS_LEVEL_FOREIGN_OR_EMPTY = '-1';

		/**
		 * Max suggestions requested per DaData call. DaData's own default/cap for
		 * `suggest/address` is 20; 10 matches typical checkout-typeahead list
		 * lengths without over-fetching.
		 *
		 * @since 2.0.2
		 * @var int
		 */
		private const SUGGESTION_COUNT = 10;

		/**
		 * Lazily-built HTTP client, keyed by the exact (token, secret) pair it was
		 * built for — rebuilt when either credential changes underneath it (e.g.
		 * a settings save mid-request-lifecycle in a long-running process; cheap
		 * insurance, not a hot path).
		 *
		 * @since 2.0.2
		 * @var Dadata_Api_Client|null
		 */
		private ?Dadata_Api_Client $client = null;

		/**
		 * The (token, secret) pair {@see self::$client} was built for.
		 *
		 * @since 2.0.2
		 * @var array{0: string, 1: string}|null
		 */
		private ?array $client_credentials = null;

		/**
		 * {@inheritDoc}
		 *
		 * @since 2.0.2
		 */
		public function get_id(): string {
			return self::PROVIDER_ID;
		}

		/**
		 * {@inheritDoc}
		 *
		 * @since 2.0.2
		 */
		public function get_name(): string {
			return __( 'DaData', 'woodev-plugin-framework' );
		}

		/**
		 * {@inheritDoc}
		 *
		 * The nine countries below are the STORE OPERATOR's market-scope
		 * decision, NOT a limit of the DaData API itself — DaData's Suggestions
		 * API genuinely serves more countries than this (measured session
		 * s67/s68, `docs-internal/specs/2026-08-12-location-provider-design.md`
		 * §"country coverage"): a three-tier data model —
		 * ФИАС/ГАР for RU (to apartment), OpenStreetMap for BY/KZ/UZ (to house),
		 * GeoNames for everywhere else, including AM/AZ/KG/TJ/TM (city only —
		 * a street-bounded query is measured EMPTY for those five, pinned by
		 * `tests/_fixtures/dadata/am-address-empty-tier2.json`).
		 *
		 * A country not in this list was EXCLUDED by an explicit operator
		 * decision (business scope: the delivery region the store's plugins
		 * actually serve), not because DaData cannot answer for it — that
		 * decision is revisitable, and widening is done through
		 * {@see self::FILTER_COUNTRIES} below, never by editing this literal
		 * list.
		 *
		 * Transnistria (PMR) has no ISO 3166-1 alpha-2 code of its own and is
		 * served by DaData under `MD` (Moldova) — measured, session s67/s68 —
		 * so it cannot be selected independently of Moldova through this
		 * country-code-keyed contract; Moldova itself is not in the list above
		 * (out of the operator's current market scope), so neither is reachable
		 * today without widening via the filter.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Default widened from `[ 'RU' ]` to the nine served
		 *              countries above (measured tier coverage, D15 amendment
		 *              follow-up).
		 */
		public function get_countries(): array {
			/**
			 * Filters the countries the bundled DaData provider reports covering.
			 *
			 * This is the operator's own market-scope override point — widen (a
			 * verified-working country DaData serves but the default list
			 * excludes, e.g. Moldova for Transnistria) or narrow (a store that
			 * only ever sells within a subset of the nine) without a framework
			 * change.
			 *
			 * @since 2.0.2
			 *
			 * @param string[] $countries ISO-3166 alpha-2 codes. Default the
			 *                             nine served countries (RU, BY, KZ, UZ,
			 *                             AM, AZ, KG, TJ, TM).
			 */
			return (array) apply_filters( self::FILTER_COUNTRIES, self::DEFAULT_COUNTRIES );
		}

		/**
		 * {@inheritDoc}
		 *
		 * DaData is the D15 universal fallback tail — it must serve all three
		 * levels (unlike e.g. a city-only carrier dictionary), so every level is
		 * declared here unconditionally; whether a given level is actually usable
		 * right now is a configuration question ({@see self::is_configured()}),
		 * not a support question.
		 *
		 * @since 2.0.2
		 */
		protected function declare_suggest_levels(): array {
			return Location_Record::LEVELS;
		}

		/**
		 * {@inheritDoc}
		 *
		 * The measured tier boundary (D15 amendment, session s68): the GeoNames
		 * tier ({@see self::GEONAMES_TIER_COUNTRIES}) resolves to CITY
		 * granularity only — a street/house-bounded (`address`-level) query is
		 * measured EMPTY for it (`tests/_fixtures/dadata/am-address-empty-tier2.json`
		 * pins AM directly; the same query returning zero rows for AZ/KG/TJ/TM
		 * is the design brief's own stated measurement — see
		 * `docs-internal/specs/2026-08-12-location-provider-design.md`). RU
		 * (ФИАС/ГАР) and the OpenStreetMap tier (BY, KZ, UZ) both resolve to
		 * house granularity, so neither is narrowed here. A country outside
		 * {@see self::get_countries()} narrows to nothing — this provider makes
		 * no promise about a country it does not cover at all.
		 *
		 * @since 2.0.2
		 */
		protected function narrow_suggest_levels_for_country( array $levels, string $country ): array {
			$normalized = strtoupper( trim( $country ) );

			if ( ! in_array( $normalized, $this->get_countries(), true ) ) {
				return [];
			}

			if ( in_array( $normalized, self::GEONAMES_TIER_COUNTRIES, true ) ) {
				return array_values( array_diff( $levels, [ Location_Record::LEVEL_ADDRESS ] ) );
			}

			return $levels;
		}

		public function get_settings_fields(): array {
			return [
				self::FIELD_TOKEN        => [
					'name'        => __( 'Токен API DaData', 'woodev-plugin-framework' ),
					'type'        => \Woodev_Setting::TYPE_STRING,
					// Issue #373: `tooltip` is the default explainer (what the field
					// does), `description` is reserved for the clickable link — the
					// operator's own rule, matching his "Client ID СДЭК" example.
					'tooltip'     => __( 'API-ключ сервиса DaData (Suggestions API) — без него подсказки городов и адресов на чекауте работать не будут.', 'woodev-plugin-framework' ),
					'description' => __( 'Получить токен можно в <a href="https://dadata.ru/profile/#info" target="_blank" rel="noopener noreferrer">личном кабинете DaData</a>.', 'woodev-plugin-framework' ),
					'default'     => '',
					'required'    => true,
					'sensitive'   => true,
				],
				self::FIELD_CLEAN_SECRET => [
					'name'        => __( 'Секретный ключ DaData (Clean API)', 'woodev-plugin-framework' ),
					'type'        => \Woodev_Setting::TYPE_STRING,
					'tooltip'     => __( 'Секретный ключ платного тарифа DaData Clean API — нужен только для нормализации свободно введённых адресов; без него подсказки на чекауте продолжают работать как обычно.', 'woodev-plugin-framework' ),
					'description' => __( 'Ключ доступен там же — в <a href="https://dadata.ru/profile/#info" target="_blank" rel="noopener noreferrer">личном кабинете DaData</a>, на вкладке Clean API.', 'woodev-plugin-framework' ),
					'default'     => '',
					'required'    => false,
					'sensitive'   => true,
				],
			];
		}

		/**
		 * {@inheritDoc}
		 *
		 * Overrides {@see Abstract_Location_Provider::is_configured()}'s honest
		 * shape-only default with the REAL check the base class's own docblock
		 * requires of any provider with a required field: whether the token is
		 * ACTUALLY stored, not merely declared required.
		 *
		 * @since 2.0.2
		 */
		public function is_configured(): bool {
			return '' !== $this->token();
		}

		/**
		 * {@inheritDoc}
		 *
		 * `normalize()` IS implemented (reflection sees it overridden below), but
		 * the DaData "Clean" API needs the secret in addition to the token (a
		 * paid tier) — so the capability is narrowed away whenever no secret is
		 * configured, pinning `get_capabilities()` to NEVER contain `normalize`
		 * on a token-only store (Task 7 requirement, tested both ways).
		 *
		 * @since 2.0.2
		 */
		protected function narrow_capabilities( array $capabilities ): array {
			if ( '' === $this->clean_secret() ) {
				return array_values( array_diff( $capabilities, [ self::CAPABILITY_NORMALIZE ] ) );
			}

			return $capabilities;
		}

		/**
		 * {@inheritDoc}
		 *
		 * Degrades to an empty result — never throws — on a blank query or an
		 * unconfigured provider: both are "nothing to ask", not a failed request.
		 * An HTTP/network failure is a DIFFERENT case (#405): it THROWS
		 * {@see Location_Provider_Exception}, wrapping the original failure, instead
		 * of degrading — see {@see \Woodev\Framework\Shipping\Location\Location_Provider::suggest()}'s
		 * own "EMPTY VS. FAILED" docblock section for why. Every failure path is
		 * still logged via `do_action( 'woodev_location_dadata_operation_failed', … )`
		 * before the throw, so a carrier outage remains observable the same way it
		 * always was (Task 7 requirement) — the ONLY change is that a checkout/admin
		 * render now also learns about it, instead of being told "nothing found".
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Rethrows an HTTP/network failure as {@see Location_Provider_Exception}
		 *              instead of degrading to `[]` (#405).
		 */
		public function suggest( string $query, Location_Scope $scope ): array {
			if ( '' === trim( $query ) || ! $this->is_configured() ) {
				return [];
			}

			try {
				$raw_suggestions = $this->client()->suggest_address( $query, $this->build_suggest_body( $scope ) );
			} catch ( \Throwable $exception ) {
				$this->log_failure( 'suggest', $exception );

				throw new Location_Provider_Exception( 'DaData suggest request failed.', 0, $exception );
			}

			$records = [];

			foreach ( $raw_suggestions as $raw ) {
				$data = (array) ( $raw['data'] ?? [] );

				if ( Location_Record::LEVEL_SETTLEMENT === $scope->level() && $this->should_reject_settlement_row( $data ) ) {
					continue;
				}

				$record = $this->record_from_dadata_fields( $data, $scope->level(), (string) ( $raw['value'] ?? '' ), $scope->country() );

				if ( null !== $record ) {
					$records[] = $record;
				}
			}

			return $records;
		}

		/**
		 * {@inheritDoc}
		 *
		 * Degrades to `null` — never throws — when unconfigured, on an
		 * HTTP/network failure, or when DaData resolved nothing for the IP; every
		 * failure path is logged (see {@see self::suggest()}).
		 *
		 * @since 2.0.2
		 */
		public function locate( string $ip ): ?Location_Record {
			if ( ! $this->is_configured() ) {
				return null;
			}

			try {
				$raw = $this->client()->iplocate_address( $ip );
			} catch ( \Throwable $exception ) {
				$this->log_failure( 'locate', $exception );

				return null;
			}

			if ( null === $raw ) {
				return null;
			}

			$data    = (array) ( $raw['data'] ?? [] );
			$country = self::extract_country( $data, 'RU' );

			return $this->record_from_dadata_fields( $data, Location_Record::LEVEL_SETTLEMENT, (string) ( $raw['value'] ?? '' ), $country );
		}

		/**
		 * {@inheritDoc}
		 *
		 * Degrades to `null` — never throws — on an HTTP/network failure (which
		 * includes "no secret configured": DaData rejects the request and the
		 * client throws, caught here exactly like any other transport failure —
		 * see {@see Dadata_Api_Client::clean_address()}) or when DaData returned
		 * no usable result; every failure path is logged (see {@see self::suggest()}).
		 *
		 * @since 2.0.2
		 */
		public function normalize( string $free_form, Location_Scope $scope ): ?Location_Record {
			try {
				$raw = $this->client()->clean_address( $free_form );
			} catch ( \Throwable $exception ) {
				$this->log_failure( 'normalize', $exception );

				return null;
			}

			if ( null === $raw || '' === trim( (string) ( $raw['result'] ?? '' ) ) ) {
				return null;
			}

			$country = self::extract_country( $raw, $scope->country() );

			return $this->record_from_dadata_fields( $raw, $scope->level(), (string) $raw['result'], $country );
		}

		/**
		 * Builds the `suggest/address` request body for one scope: the D15
		 * level→bounds mapping (`region`→`area`, `settlement`→`city`→`settlement`,
		 * `address`→`street`→`house` — per Task 7's plan text; NOTE this
		 * intentionally differs from the reference plugins' own `street-flat`
		 * bound for the address field, since `flat` is this contract's own
		 * separate optional component, not part of what "address level" means
		 * here) plus the parent-constraint translation into DaData's `locations` /
		 * `restrict_value` (spec Task 7).
		 *
		 * `restrict_value` is set ONLY when {@see self::build_locations_constraint()}
		 * narrowed to an actual PARENT locality (a region/city record or its
		 * components) — never for the bare country floor a parentless scope now
		 * always carries (P2 review fix). The two are not the same decision:
		 * `restrict_value` strips whatever the `locations` filter matched out of
		 * the returned `value`/`unrestricted_value` label, which is only correct
		 * when that filter names a real locality above the level being searched.
		 * A country-only filter names no locality the DaData label would ever
		 * repeat, so stripping on it would be a no-op at best — matches the
		 * reference client's own split exactly: `fields-autocomplete.js`'s region
		 * field sends `locations: { country_iso_code }` with NO `restrict_value`,
		 * while its city/address constraints (a real region/city `locations`
		 * filter) always pair one with `restrict_value: true` — see
		 * `related-address-autocomplete.js` and the city-field fallback further
		 * down the same file.
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Scope $scope Lookup scope.
		 *
		 * @return array<string, mixed>
		 */
		private function build_suggest_body( Location_Scope $scope ): array {
			[ $from_bound, $to_bound ] = self::suggest_bounds( $scope->level() );

			$body = [
				'count'      => self::SUGGESTION_COUNT,
				'from_bound' => [ 'value' => $from_bound ],
				'to_bound'   => [ 'value' => $to_bound ],
			];

			$locations = $this->build_locations_constraint( $scope );

			if ( [] !== $locations ) {
				$body['locations'] = $locations;

				if ( $scope->has_parent() ) {
					$body['restrict_value'] = true;
				}
			}

			return $body;
		}

		/**
		 * The D15 level→bounds mapping (confirmed valid `from_bound`/`to_bound`
		 * values per DaData docs: country/region/area/city/settlement/street/house/flat).
		 *
		 * @since 2.0.2
		 *
		 * @param string $level One of {@see Location_Record::LEVELS}.
		 *
		 * @return array{0: string, 1: string} `[ from_bound, to_bound ]`.
		 */
		private static function suggest_bounds( string $level ): array {
			switch ( $level ) {
				case Location_Record::LEVEL_REGION:
					return [ 'region', 'area' ];
				case Location_Record::LEVEL_SETTLEMENT:
					return [ 'city', 'settlement' ];
				case Location_Record::LEVEL_ADDRESS:
				default:
					return [ 'street', 'house' ];
			}
		}

		/**
		 * Translates a scope's parent constraint into a DaData `locations` filter
		 * (spec Task 7: "the parent constraint translated into DaData's
		 * `locations`/`restrict_value`").
		 *
		 * Prefers the parent's own native DaData ids (`region_fias_id`,
		 * `area_fias_id`, `city_fias_id`, `settlement_fias_id`) read straight out
		 * of the parent record's `raw` payload (D12: the full DaData `data` object
		 * rides along untouched) whenever the parent record is itself
		 * DaData-native — the exact ids DaData itself would use, sourced from a
		 * prior DaData response. Falls back to a textual `region`/`city` NAME
		 * constraint when the parent is a foreign-provider record (D15
		 * chain: DaData serving as fallback under a chosen non-DaData provider) or
		 * components-only (no id to read) — DaData's `locations` filter accepts
		 * plain name fields too, though this fallback path is not verified against
		 * a live capture (best-effort, documented here rather than asserted as
		 * doc-confirmed).
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Scope $scope Lookup scope.
		 *
		 * @return array<int, array<string, mixed>> Zero or one `locations` entries.
		 */
		private function build_locations_constraint( Location_Scope $scope ): array {
			if ( ! $scope->has_parent() ) {
				// P2 review fix: a parentless scope is the normal path for the
				// first field the customer touches, and DaData's suggestion
				// registry is RU-centric by default (get_countries()) — without
				// this floor, `/location/suggest?country=XX` was not actually
				// scoped by `XX` at all. `country_iso_code` is DaData's own
				// documented `locations` field for this (confirmed against the
				// reference client: `country_iso_code` inside `locations`,
				// `fields-autocomplete.js`).
				return [ [ 'country_iso_code' => $scope->country() ] ];
			}

			/*
			 * The COUNTRY rides in every parent constraint too, not only the parentless one.
			 *
			 * MEASURED against the live API (13.08.2026), one query, four constraint shapes,
			 * scoping a street search to Tashkent (`city_fias_id` = `relation:2216724`, the id
			 * DaData itself returned for that city):
			 *
			 *   [ region_fias_id, city_fias_id ]                  → 0 suggestions
			 *   [ country_iso_code, city_fias_id ]                → 3
			 *   [ country_iso_code, city ]                        → 3
			 *   [ country_iso_code ]                              → 3
			 *
			 * The first row is what this method used to send. Outside Russia DaData's
			 * "fias" ids are not FIAS at all — they are OpenStreetMap-derived
			 * (`relation:`/`way:`) or GeoNames numbers — and the `locations` filter cannot
			 * interpret one without knowing which country's registry it belongs to. So a
			 * customer who picked a foreign settlement got an EMPTY address list for every
			 * query, while the same query with no settlement chosen worked fine — reported
			 * from the rig exactly that way (operator, s70: Tashkent chosen → nothing;
			 * Tashkent cleared → "Yunusabad 19" found immediately, and selecting it
			 * backfilled Tashkent).
			 *
			 * Adding the country is a no-op for Russia — same measurement, Moscow's real
			 * FIAS UUID: 3 suggestions with and without it, identical values.
			 */
			$country = [ 'country_iso_code' => $scope->country() ];
			$parent  = $scope->parent_record();

			if ( null !== $parent && self::PROVIDER_ID === $parent->provider_id() && is_array( $parent->raw() ) ) {
				$location = [];

				foreach ( [ 'region_fias_id', 'area_fias_id', 'city_fias_id', 'settlement_fias_id', 'street_fias_id' ] as $id_field ) {
					if ( ! empty( $parent->raw()[ $id_field ] ) ) {
						$location[ $id_field ] = $parent->raw()[ $id_field ];
					}
				}

				if ( [] !== $location ) {
					return [ array_merge( $country, $location ) ];
				}
			}

			$components = $scope->parent_components();

			if ( null === $components ) {
				return [];
			}

			$location = [];

			if ( ! empty( $components['region']['name'] ) ) {
				$location['region'] = $components['region']['name'];
			}

			if ( ! empty( $components['settlement']['name'] ) ) {
				$location['city'] = $components['settlement']['name'];
			}

			return [] !== $location ? [ array_merge( $country, $location ) ] : [];
		}

		/**
		 * Whether a raw settlement-bound suggestion is FINER than a settlement
		 * (a district, a street, an administrative-planning row) and must be
		 * rejected — by GRANULARITY, not by deduplication (measured defect:
		 * `tests/_fixtures/dadata/ru-settlement-moscow-duplicate.json` shows
		 * DaData returning "г Москва" AND "г Москва, р-н Москворечье-Сабурово"
		 * both at `fias_level '1'` with the SAME `city_fias_id` — deduplicating
		 * by `city_fias_id` would collapse them into one locality carrying
		 * whichever record happened to win, silently discarding the other's
		 * postcode/coordinates; rejecting the finer one by granularity keeps
		 * the better-quality federal-city row and never needs to compare the
		 * two against each other at all).
		 *
		 * Two rules, applied in order:
		 *
		 * 1. **Country-agnostic** (applies everywhere, including where
		 *    `fias_level` is {@see self::FIAS_LEVEL_FOREIGN_OR_EMPTY} — the
		 *    OpenStreetMap and GeoNames tiers, where `fias_level` carries no
		 *    RU-specific meaning at all per DaData's own docs): a settlement
		 *    suggestion is usable only when `city` or `settlement` is filled
		 *    AND `street`/`house` are NOT — a row carrying street/house data at
		 *    a settlement-bound query is finer than what was asked for.
		 * 2. **RU-specific** (only when `fias_level !== `{@see self::FIAS_LEVEL_FOREIGN_OR_EMPTY}`,
		 *    i.e. a real ФИАС row): a non-empty `city_district` is always finer
		 *    than a settlement and is rejected outright; otherwise accept
		 *    {@see self::RU_SETTLEMENT_ACCEPTED_FIAS_LEVELS} (`4` city, `6`
		 *    settlement) unconditionally, accept {@see self::RU_FIAS_LEVEL_REGION}
		 *    (`1`) ONLY when the row is a federal city
		 *    (`region_fias_id === city_fias_id`, the docs' own definition —
		 *    detected structurally, never by hardcoding Moscow/Saint-Petersburg
		 *    GUIDs the way the reference plugins do), and reject every other
		 *    `fias_level` (`0, 3, 5, 7, 8, 9, 65`, and anything unrecognized).
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Generalized from the earlier `fias_level !== '65'`-only
		 *              filter to the full granularity rule above (measured
		 *              defect: the Moscow federal-city/city-district
		 *              duplicate).
		 *
		 * @param array<string, mixed> $data DaData field set for one raw
		 *                                    suggestion's `data` object.
		 *
		 * @return bool `true` when this row must be rejected.
		 */
		private function should_reject_settlement_row( array $data ): bool {
			$has_locality = '' !== trim( (string) ( $data['city'] ?? '' ) ) || '' !== trim( (string) ( $data['settlement'] ?? '' ) );
			$has_finer    = '' !== trim( (string) ( $data['street'] ?? '' ) ) || '' !== trim( (string) ( $data['house'] ?? '' ) );

			if ( ! $has_locality || $has_finer ) {
				return true;
			}

			$fias_level = (string) ( $data['fias_level'] ?? '' );

			if ( self::FIAS_LEVEL_FOREIGN_OR_EMPTY === $fias_level ) {
				// OpenStreetMap/GeoNames tiers: fias_level is meaningless here —
				// the country-agnostic rule above is the only gate.
				return false;
			}

			if ( '' !== trim( (string) ( $data['city_district'] ?? '' ) ) ) {
				return true; // a city district is finer than a settlement.
			}

			if ( in_array( $fias_level, self::RU_SETTLEMENT_ACCEPTED_FIAS_LEVELS, true ) ) {
				return false;
			}

			if ( self::RU_FIAS_LEVEL_REGION === $fias_level ) {
				$region_fias_id = (string) ( $data['region_fias_id'] ?? '' );
				$city_fias_id   = (string) ( $data['city_fias_id'] ?? '' );

				// Federal city: the docs' own definition, detected structurally.
				return ! ( '' !== $region_fias_id && $region_fias_id === $city_fias_id );
			}

			return true;
		}

		/**
		 * Maps a flat DaData field set (`data.*` from a suggestion, or the
		 * top-level fields of a `clean/address` result — both share the same
		 * field-name family) into a contract-shaped {@see Location_Record}
		 * (spec D12).
		 *
		 * Also publishes {@see Location_Record::ancestors()} — the non-empty of
		 * `region_fias_id`, `area_fias_id`, `city_fias_id`, `settlement_fias_id`,
		 * `street_fias_id`, composed the same way `key` is, minus the row's own
		 * `fias_id` (see {@see self::ancestor_keys_from_dadata_fields()}).
		 *
		 * Measured facts (rig, live DaData, 15.08.2026) this rests on — see
		 * `docs-internal/specs/2026-08-15-location-chain-design.md` "Measurement":
		 * - a settlement-level row's own `fias_id` equals the DEEPEST filled of
		 *   (`settlement_fias_id`, `city_fias_id`) — five rows measured, including
		 *   the both-filled case («Нижегородская обл, г Бор, деревня Жуковка» →
		 *   `fias_id` = `settlement_fias_id`);
		 * - abroad these ids are OSM-derived (`relation:2216724` for Ташкент) and
		 *   an address row in that city carries the SAME string in `city_fias_id`,
		 *   so ancestor composition works outside RU too, not only under ФИАС.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $fields  DaData field set.
		 * @param string               $level   One of {@see Location_Record::LEVELS}.
		 * @param string               $label   Display label (`value` for a suggestion,
		 *                                       `result` for a clean response).
		 * @param string               $country ISO-3166 alpha-2 fallback when `$fields`
		 *                                       carries none.
		 *
		 * @return Location_Record|null Null only when the resulting record fails
		 *                               contract validation (defensive — a
		 *                               malformed upstream payload must not fatal
		 *                               a checkout render).
		 */
		private function record_from_dadata_fields( array $fields, string $level, string $label, string $country ): ?Location_Record {
			$fias_id = (string) ( $fields['fias_id'] ?? '' );

			try {
				$key = '' !== $fias_id
					? Locality_Key::compose( self::PROVIDER_ID, $fias_id )
					: Locality_Key::derive( self::PROVIDER_ID, $fields );
			} catch ( \Throwable $exception ) {
				$this->log_failure( 'map', $exception );

				return null;
			}

			// DaData carries BOTH a `city`/`city_type` pair and a SEPARATE
			// `settlement`/`settlement_type` pair — many rural localities have a
			// settlement but no city, and vice versa; `city` is preferred when
			// present since it is the more common, more specific field in
			// practice (matches the reference plugins' own `bounds: 'city-settlement'`
			// framing, which treats `city` as primary).
			$settlement_name_key = ! empty( $fields['city'] ) ? 'city' : 'settlement';
			$settlement_type_key = ! empty( $fields['city'] ) ? 'city_type' : 'settlement_type';

			$record_data = [
				'key'         => $key,
				'provider_id' => self::PROVIDER_ID,
				'level'       => $level,
				'country'     => self::extract_country( $fields, $country ),
				'region'      => self::component_group( $fields, 'region', 'region_type' ),
				'district'    => self::component_group( $fields, 'area', 'area_type' ),
				'settlement'  => self::component_group( $fields, $settlement_name_key, $settlement_type_key ),
				'street'      => self::component_group( $fields, 'street', 'street_type' ),
				'house'       => $fields['house'] ?? '',
				'block'       => $fields['block'] ?? '',
				'flat'        => $fields['flat'] ?? '',
				'postcode'    => $fields['postal_code'] ?? '',
				'lat'         => $fields['geo_lat'] ?? null,
				'lon'         => $fields['geo_lon'] ?? null,
				'label'       => $label,
				'raw'         => $fields,
				'ancestors'   => $this->ancestor_keys_from_dadata_fields( $fields, $fias_id ),
			];

			try {
				return Location_Record::from_array( $record_data );
			} catch ( \Throwable $exception ) {
				$this->log_failure( 'map', $exception );

				return null;
			}
		}

		/**
		 * Composes the {@see Location_Record::ancestors()} set from a flat DaData
		 * field set: the non-empty of `region_fias_id`, `area_fias_id`,
		 * `city_fias_id`, `settlement_fias_id`, `street_fias_id`, each composed
		 * into a locality key the same way `key` itself is, minus the row's own
		 * `fias_id` (that identity is answered by {@see Location_Record::is_within()}
		 * separately, never carried twice over) — see
		 * {@see self::record_from_dadata_fields()}'s own docblock for the
		 * measurement this rests on.
		 *
		 * A malformed upstream id (one {@see Locality_Key::compose()} itself
		 * refuses) is SKIPPED, not fatal — this method already runs inside the
		 * same defensive posture as the rest of this class (`log_failure()`'s own
		 * callers): one bad ancestor field must not take down the whole record.
		 * The result is de-duplicated and kept in the field-check order above.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $fields  DaData field set.
		 * @param string               $fias_id The row's own `fias_id` (already
		 *                                       extracted by the caller), skipped
		 *                                       here if it recurs under another field.
		 *
		 * @return string[]
		 */
		private function ancestor_keys_from_dadata_fields( array $fields, string $fias_id ): array {
			$ancestors = [];

			foreach ( [ 'region_fias_id', 'area_fias_id', 'city_fias_id', 'settlement_fias_id', 'street_fias_id' ] as $id_field ) {
				$native_id = trim( (string) ( $fields[ $id_field ] ?? '' ) );

				if ( '' === $native_id || $native_id === $fias_id ) {
					continue;
				}

				try {
					$ancestor_key = Locality_Key::compose( self::PROVIDER_ID, $native_id );
				} catch ( \Throwable $exception ) {
					continue;
				}

				if ( ! in_array( $ancestor_key, $ancestors, true ) ) {
					$ancestors[] = $ancestor_key;
				}
			}

			return $ancestors;
		}

		/**
		 * Reads a `{ name, type }` component group from a flat DaData field set,
		 * or null when the name field is absent/empty.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $fields    DaData field set.
		 * @param string               $name_key  Field name holding the component's name.
		 * @param string               $type_key  Field name holding the component's type.
		 *
		 * @return array{name: string, type: string}|null
		 */
		private static function component_group( array $fields, string $name_key, string $type_key ): ?array {
			$name = $fields[ $name_key ] ?? '';

			if ( '' === trim( (string) $name ) ) {
				return null;
			}

			return [
				'name' => (string) $name,
				'type' => (string) ( $fields[ $type_key ] ?? '' ),
			];
		}

		/**
		 * Reads `country_iso_code` from a DaData field set, falling back to
		 * `$default` when absent or not a well-formed 2-letter code (DaData's
		 * `country_iso_code` is usually `"RU"`, but the field is genuinely
		 * optional in some response shapes).
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $fields  DaData field set.
		 * @param string               $default Fallback country code.
		 *
		 * @return string
		 */
		private static function extract_country( array $fields, string $default ): string {
			$code = strtoupper( trim( (string) ( $fields['country_iso_code'] ?? '' ) ) );

			return 1 === preg_match( '/^[A-Z]{2}$/', $code ) ? $code : $default;
		}

		/**
		 * Logs a degraded failure (an HTTP/network error, or a malformed upstream
		 * payload) without ever letting it fatal a checkout render (Task 7
		 * requirement: "must degrade... But do NOT swallow it silently... without
		 * a log line").
		 *
		 * @since 2.0.2
		 *
		 * @param string     $operation One of `suggest`, `locate`, `normalize`, `map`.
		 * @param \Throwable $exception The caught failure.
		 *
		 * @return void
		 */
		private function log_failure( string $operation, \Throwable $exception ): void {
			/**
			 * Fires when a DaData provider operation degrades instead of throwing.
			 *
			 * @since 2.0.2
			 *
			 * @param string     $operation One of `suggest`, `locate`, `normalize`, `map`.
			 * @param \Throwable $exception The caught failure.
			 */
			do_action( 'woodev_location_dadata_operation_failed', $operation, $exception );
		}

		/**
		 * Gets the (lazily built) HTTP client, rebuilt if the underlying
		 * credentials changed since the last build.
		 *
		 * @since 2.0.2
		 *
		 * @return Dadata_Api_Client
		 */
		private function client(): Dadata_Api_Client {
			$credentials = [ $this->token(), $this->clean_secret() ];

			if ( null === $this->client || $this->client_credentials !== $credentials ) {
				$this->client            = new Dadata_Api_Client( $credentials[0], $credentials[1] );
				$this->client_credentials = $credentials;
			}

			return $this->client;
		}

		/**
		 * Reads the ACTUAL stored token value directly via `get_option()`, NOT
		 * through the shared `Location_Settings` handler.
		 *
		 * This mirrors {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::resolve_stored_active_provider_id()}'s
		 * own documented reasoning exactly: the settings handler only merges the
		 * ACTIVE provider's fields (`Location_Settings`'s own docblock: "A
		 * registered but NOT-active provider's fields are never merged in"), but
		 * the D15 fallback chain calls `is_configured()` on the BUNDLED provider
		 * even when a different provider is currently active — at that moment no
		 * settings handler has this provider's `token` field registered at all,
		 * so `Woodev_Abstract_Settings::get_value()` would throw
		 * `Woodev_Plugin_Exception: Setting token does not exist`. Reading the raw
		 * option directly sidesteps that entirely and always resolves the SAME
		 * value the handler itself would load when this provider IS active — the
		 * option name is built with the exact same
		 * `'woodev_' . {service id} . '_' . {field id}` construction the registry
		 * uses.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		private function token(): string {
			return (string) get_option( $this->option_name( self::FIELD_TOKEN ), '' );
		}

		/**
		 * Reads the ACTUAL stored "Clean" API secret — see {@see self::token()}
		 * for why this reads the raw option rather than going through the
		 * settings handler.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		private function clean_secret(): string {
			return (string) get_option( $this->option_name( self::FIELD_CLEAN_SECRET ), '' );
		}

		/**
		 * Builds the stored option name for one of this provider's own settings
		 * fields — `woodev_location_{field_id}`, matching
		 * {@see \Woodev\Framework\Shipping\Location\Location_Provider_Registry::SETTINGS_SERVICE_ID}
		 * (`'location'`) exactly. Kept as an independent literal (`'location'`),
		 * not a reference to that constant, for the same load-order reason
		 * {@see self::PROVIDER_ID} is — pinned equal by
		 * `DadataProviderTest::test_option_names_match_the_registrys_settings_service_id()`.
		 *
		 * @since 2.0.2
		 *
		 * @param string $field_id One of {@see self::FIELD_TOKEN}, {@see self::FIELD_CLEAN_SECRET}.
		 *
		 * @return string
		 */
		private function option_name( string $field_id ): string {
			return 'woodev_location_' . $field_id;
		}
	}

endif;
