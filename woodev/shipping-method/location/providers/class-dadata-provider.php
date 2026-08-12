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
	 * planning-structure noise filter ({@see self::PLANNING_STRUCTURE_FIAS_LEVEL})
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
		 * DaData's own "planning structure" ФИАС level — suggestion rows at this
		 * level are administrative-boundary noise, not real localities a customer
		 * would pick. Confirmed VERBATIM (string `'65'`, compared with `!==`, NOT
		 * a level-4-or-greater numeric heuristic) in
		 * `plugins-reference/woocommerce-edostavka/assets/js/frontend/fields-autocomplete.js:123-128`
		 * (`onSuggestionsFetch`) and independently in
		 * `plugins-reference/woocommerce-yandex-delivery/woodev/assets/js/frontend/woodev-dadata-suggestions.js`'s
		 * `filterSuggestions()` — two independent production plugins agree on this
		 * exact criterion, so it is applied here at the SETTLEMENT level only
		 * (matching the reference's own `bounds: 'city-settlement'` call site).
		 *
		 * The reference plugins ALSO apply a second, address-level filter
		 * (`fias_level >= 4 OR city_fias_id in [Moscow, SPb]`) that a fresh read
		 * of `fields-autocomplete.js:228-231` shows contains a pre-existing bug
		 * (`$.inArray(...) === 0` evaluated INSIDE the array argument, which can
		 * never be true) — that second filter is deliberately NOT ported: porting
		 * a criterion independent research flagged as broken would encode the bug
		 * into the framework's neutral contract, not "replicate the reference".
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private const PLANNING_STRUCTURE_FIAS_LEVEL = '65';

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
		 * DaData's suggestion registry is RU-centric (spec Task 7). Filterable so
		 * a store that has verified wider coverage (DaData does answer some CIS
		 * queries) can opt in without a framework change.
		 *
		 * @since 2.0.2
		 */
		public function get_countries(): array {
			/**
			 * Filters the countries the bundled DaData provider reports covering.
			 *
			 * @since 2.0.2
			 *
			 * @param string[] $countries ISO-3166 alpha-2 codes. Default `[ 'RU' ]`.
			 */
			return (array) apply_filters( self::FILTER_COUNTRIES, [ 'RU' ] );
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
		 * @since 2.0.2
		 */
		public function get_settings_fields(): array {
			return [
				self::FIELD_TOKEN        => [
					'name'        => __( 'Токен API DaData', 'woodev-plugin-framework' ),
					'type'        => \Woodev_Setting::TYPE_STRING,
					'description' => __( 'API-ключ сервиса DaData (Suggestions API) — используется для подсказок адресов на чекауте.', 'woodev-plugin-framework' ),
					'default'     => '',
					'required'    => true,
					'sensitive'   => true,
				],
				self::FIELD_CLEAN_SECRET => [
					'name'        => __( 'Секретный ключ DaData (Clean API)', 'woodev-plugin-framework' ),
					'type'        => \Woodev_Setting::TYPE_STRING,
					'description' => __( 'Требуется только для нормализации свободных адресов (платный тариф DaData Clean API); необязателен.', 'woodev-plugin-framework' ),
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
		 * Degrades to an empty result — never throws — on a blank query, an
		 * unconfigured provider, or an HTTP/network failure; every failure path
		 * is logged via `do_action( 'woodev_location_dadata_suggest_failed', … )`
		 * so a carrier outage is observable without ever fataling a checkout
		 * render (Task 7 requirement).
		 *
		 * @since 2.0.2
		 */
		public function suggest( string $query, Location_Scope $scope ): array {
			if ( '' === trim( $query ) || ! $this->is_configured() ) {
				return [];
			}

			try {
				$raw_suggestions = $this->client()->suggest_address( $query, $this->build_suggest_body( $scope ) );
			} catch ( \Throwable $exception ) {
				$this->log_failure( 'suggest', $exception );

				return [];
			}

			$records = [];

			foreach ( $raw_suggestions as $raw ) {
				if ( $this->is_planning_structure_noise( $raw, $scope ) ) {
					continue;
				}

				$record = $this->record_from_dadata_fields( (array) ( $raw['data'] ?? [] ), $scope->level(), (string) ( $raw['value'] ?? '' ), $scope->country() );

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
				$body['locations']      = $locations;
				$body['restrict_value'] = true;
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
				return [];
			}

			$parent = $scope->parent_record();

			if ( null !== $parent && self::PROVIDER_ID === $parent->provider_id() && is_array( $parent->raw() ) ) {
				$location = [];

				foreach ( [ 'region_fias_id', 'area_fias_id', 'city_fias_id', 'settlement_fias_id', 'street_fias_id' ] as $id_field ) {
					if ( ! empty( $parent->raw()[ $id_field ] ) ) {
						$location[ $id_field ] = $parent->raw()[ $id_field ];
					}
				}

				if ( [] !== $location ) {
					return [ $location ];
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

			return [] !== $location ? [ $location ] : [];
		}

		/**
		 * Whether a raw suggestion is DaData "planning structure" noise, at the
		 * `settlement` level only — see {@see self::PLANNING_STRUCTURE_FIAS_LEVEL}.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $raw   One raw suggestion (`{ value, data }`).
		 * @param Location_Scope       $scope Lookup scope.
		 *
		 * @return bool
		 */
		private function is_planning_structure_noise( array $raw, Location_Scope $scope ): bool {
			if ( Location_Record::LEVEL_SETTLEMENT !== $scope->level() ) {
				return false;
			}

			$data = (array) ( $raw['data'] ?? [] );

			return self::PLANNING_STRUCTURE_FIAS_LEVEL === (string) ( $data['fias_level'] ?? '' );
		}

		/**
		 * Maps a flat DaData field set (`data.*` from a suggestion, or the
		 * top-level fields of a `clean/address` result — both share the same
		 * field-name family) into a contract-shaped {@see Location_Record}
		 * (spec D12).
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
			];

			try {
				return Location_Record::from_array( $record_data );
			} catch ( \Throwable $exception ) {
				$this->log_failure( 'map', $exception );

				return null;
			}
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
