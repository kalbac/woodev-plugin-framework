<?php
/**
 * Woodev_Test_Cdek_Location_Provider — the rig's SECOND live location provider (issue #343).
 *
 * WHY THIS EXISTS. The rig has exactly one live provider (DaData) and it serves every level,
 * so nothing multi-provider is observable there: per-level arbitration, cross-provider `within`
 * and key namespacing are all exercised only against fixtures that were written from the same
 * assumptions as the code under test (gotcha
 * `an-invented-fixture-tests-your-assumptions-not-the-carrier`). CDEK is the honest counterpart:
 * a real carrier dictionary with regions and settlements and — measured, see below — NO street
 * data at all.
 *
 * WHAT WAS MEASURED (test contour, 16.08.2026). The Locations API has exactly three endpoints,
 * and this was read off CDEK's own documentation inventory rather than inferred from silence:
 *
 * | endpoint                        | what it answers                                        |
 * |---------------------------------|--------------------------------------------------------|
 * | `GET /v2/location/regions`      | the whole region dictionary; `region_code` is identity  |
 * | `GET /v2/location/cities`       | settlements, filterable by `region_code`/`code`/`city`  |
 * | `GET /v2/location/suggest/cities` | settlement suggestions by `name`                      |
 *
 * There is no address/street suggestion endpoint. That is not a gap in this fixture — it is the
 * whole point of issue #343's scenario A: a live provider for which the `address` level must
 * stay a plain input, exactly as the #337 lock rule requires.
 *
 * IDENTITY. CDEK's own identifiers are its dictionary codes — `region_code` for a region,
 * `code` for a settlement — never FIAS. `GET /location/cities` DOES also carry `fias_guid`, and
 * Moscow's is byte-for-byte the guid DaData answers with (`0c5b2444-…`, measured), but this
 * provider deliberately keys off the CDEK code anyway: a {@see \Woodev\Framework\Shipping\Location\Locality_Key}
 * is `provider_id:native_id`, and borrowing another provider's identifier space would hide the
 * very cross-provider seam this fixture exists to expose. The guid rides along under `raw`.
 *
 * SUGGESTION COMPONENTS ARE PARSED FROM `full_name`. `/location/suggest/cities` returns only
 * `code`, `city_uuid`, `full_name` and `country_code` — no components, no `region_code` — while
 * a {@see \Woodev\Framework\Shipping\Location\Location_Record} needs them for backwards fill.
 * `full_name` is CDEK's own composite ("Москва, Россия"; "Московский, Московская область,
 * Россия"; "Московская, Афанасьевский район, Кировская область, Россия"), so it is split on
 * commas: first part is the settlement, last is the country, the last of what remains is the
 * region and anything between it and the settlement is the district. The parse only ever
 * produces DISPLAY components — identity stays the `code` — so a mis-split degrades the label,
 * never the key (gotcha `a-locality-display-name-is-not-an-identifier`). The region name is
 * then looked up in the cached region dictionary to recover `region_code`, which is what lets a
 * suggestion carry a real ancestor key rather than a dangling one.
 *
 * COUNTRIES ARE NARROWED ON PURPOSE. CDEK's dictionary spans ~95 countries (measured). This
 * fixture declares the SAME nine the rig's DaData provider covers, so the two are directly
 * comparable on the same checkout — the point is arbitration between them, not coverage.
 *
 * CREDENTIALS ARE NEVER COMMITTED. CDEK publishes shared test-contour keys (they appear in
 * their own official WooCommerce plugin as `Cdek\Config::TEST_CLIENT_ID` /
 * `TEST_CLIENT_SECRET`), and orders placed with them are never processed — but this repository
 * is public and the rule does not bend for a credential that happens to be public elsewhere
 * (gotcha `public-repo-third-party-credentials`). They are read from the rig's own wp-config
 * constants and bridged into the store option by {@see \Woodev_Test_Credential_Seeder} —
 * since issue #375, into {@see Woodev_Test_Cdek_Integration}'s OWN array-shaped
 * `woocommerce_{plugin_id}_settings` option (via {@see \Woodev_Test_Credential_Seeder::maybe_seed_into_array_option()}),
 * not the flat `woodev_location_*` option `WOODEV_TEST_DADATA_TOKEN` still uses — the
 * credentials moved from the location-provider settings surface to the carrier's own
 * Integration settings, and the seeding had to move with them.
 *
 * Required/declared inside the plugin's own init callback, never at file top level — gotcha
 * `fixture-classes-must-live-inside-plugin-init`.
 *
 * @package Woodev_Test_Shipping_Method
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Test_Cdek_Location_Provider' ) ) {

	/**
	 * Class Woodev_Test_Cdek_Location_Provider
	 */
	class Woodev_Test_Cdek_Location_Provider extends \Woodev\Framework\Shipping\Location\Abstract_Location_Provider {

		/**
		 * Provider id — the {@see \Woodev\Framework\Shipping\Location\Locality_Key}
		 * namespace prefix every record this provider produces carries.
		 *
		 * Prefixed `test-` like every other fixture here: this is a rig provider built to
		 * exercise the multi-provider seams, NOT a shipped CDEK integration, and a stored
		 * key must never be mistaken for one written by such an integration later.
		 *
		 * @var string
		 */
		public const PROVIDER_ID = 'test-cdek';

		// FIELD_CLIENT_ID / FIELD_CLIENT_SECRET moved to {@see Woodev_Test_Cdek_Integration}
		// (issue #375) — the OAuth client id/secret authenticate every CDEK API call, not
		// only the location dictionary, so they belong to the carrier's OWN settings, not
		// to this location provider. See {@see self::get_settings_fields()} and
		// {@see self::is_configured()} below for the reworked contract.

		/**
		 * CDEK's TEST contour. Production (`https://api.cdek.ru/v2`) is deliberately not
		 * reachable from this fixture at all — a rig provider has no business being one
		 * setting away from a live carrier account.
		 *
		 * @var string
		 */
		public const API_BASE = 'https://api.edu.cdek.ru/v2';

		/**
		 * Transient holding the OAuth bearer token.
		 *
		 * @var string
		 */
		private const TOKEN_TRANSIENT = 'woodev_test_cdek_token';

		/**
		 * Transient prefix for the per-country region dictionary.
		 *
		 * @var string
		 */
		private const REGIONS_TRANSIENT_PREFIX = 'woodev_test_cdek_regions_';

		/**
		 * Region dictionary TTL. A carrier's region list changes on the order of years;
		 * a day is already generous for a rig.
		 *
		 * @var int
		 */
		private const REGIONS_TTL = DAY_IN_SECONDS;

		/**
		 * Request timeout, seconds — the same value the rig's live Yandex source uses.
		 *
		 * @var int
		 */
		private const REQUEST_TIMEOUT = 15;

		/**
		 * How many settlements {@see self::list_localities()} enumerates per region.
		 * CDEK's own default is 1000; a region's full settlement list is what the
		 * `related-list`/`ajax-select2` modes render, so the cap is a real one.
		 *
		 * @var int
		 */
		private const LIST_PAGE_SIZE = 500;

		/**
		 * The countries this fixture claims — see the file docblock on why this is
		 * narrower than CDEK's real coverage.
		 *
		 * @var string[]
		 */
		private const COUNTRIES = [ 'AM', 'AZ', 'BY', 'KZ', 'KG', 'RU', 'TJ', 'TM', 'UZ' ];

		/**
		 * {@inheritDoc}
		 */
		public function get_id(): string {
			return self::PROVIDER_ID;
		}

		/**
		 * {@inheritDoc}
		 */
		public function get_name(): string {
			return __( 'СДЭК — тестовый контур (только для рига)', 'woodev-plugin-framework' );
		}

		/**
		 * {@inheritDoc}
		 */
		public function get_countries(): array {
			return self::COUNTRIES;
		}

		/**
		 * {@inheritDoc}
		 *
		 * REGION AND SETTLEMENT, NEVER ADDRESS — the measured shape of CDEK's Locations
		 * API (see the file docblock), and the reason this fixture exists: it is the live
		 * case for #337's rule that the address field stays an ordinary input when the
		 * provider serves no address suggestions.
		 */
		protected function declare_suggest_levels(): array {
			return [
				\Woodev\Framework\Shipping\Location\Location_Record::LEVEL_REGION,
				\Woodev\Framework\Shipping\Location\Location_Record::LEVEL_SETTLEMENT,
			];
		}

		/**
		 * {@inheritDoc}
		 *
		 * ZERO declared fields — issue #375's model 2
		 * ({@see \Woodev\Framework\Shipping\Location\Location_Provider::get_settings_fields()}'s
		 * own contract-fork docblock): CDEK's Client ID/Secret authenticate every CDEK API
		 * call, not only the location dictionary, so they live in
		 * {@see Woodev_Test_Cdek_Integration} (the carrier's own settings, WooCommerce >
		 * Settings > Integrations) instead of being declared here. This is a DELIBERATE
		 * override (not merely inheriting {@see \Woodev\Framework\Shipping\Location\Abstract_Location_Provider}'s
		 * own `[]` default) so a reader of this reference fixture sees the choice made
		 * explicitly, with the reasoning attached, rather than an absence that looks like
		 * an oversight.
		 */
		public function get_settings_fields(): array {
			return [];
		}

		/**
		 * {@inheritDoc}
		 *
		 * MUST override — {@see \Woodev\Framework\Shipping\Location\Abstract_Location_Provider::is_configured()}'s
		 * default derives its answer from {@see self::get_settings_fields()}, which is now
		 * `[]`, and would otherwise dishonestly report `true` ("nothing declared, nothing
		 * to configure") regardless of whether real CDEK credentials exist anywhere. The
		 * honest answer reads BOTH halves of the credential pair from the carrier's own
		 * settings via {@see self::credential()} — see that method's own docblock for the
		 * raw-option discipline this read follows.
		 */
		public function is_configured(): bool {
			return '' !== $this->credential( Woodev_Test_Cdek_Integration::FIELD_CLIENT_ID )
				&& '' !== $this->credential( Woodev_Test_Cdek_Integration::FIELD_CLIENT_SECRET );
		}

		/**
		 * {@inheritDoc}
		 *
		 * Region level: a substring match over the cached region dictionary — CDEK has no
		 * region suggest endpoint, and the dictionary is small enough (tens of rows per
		 * country) that filtering it locally is both cheaper and more predictable than any
		 * remote alternative would be.
		 *
		 * Settlement level: `GET /location/suggest/cities`, then — when the scope names a
		 * parent region — filtered to the suggestions whose parsed region matches it. CDEK's
		 * suggest endpoint takes no region parameter (measured), so the narrowing has to
		 * happen here; a caller's `within` is therefore honoured even though the carrier
		 * cannot honour it itself.
		 *
		 * A transport or payload failure THROWS {@see \Woodev\Framework\Shipping\Location\Location_Provider_Exception}
		 * (issue #405) rather than degrading to `[]` — see {@see self::token()} and
		 * {@see self::request()}, which is where that failure actually originates.
		 * With WRONG keys, {@see self::is_configured()} still answers `true` (both
		 * fields are non-empty), so without this the OAuth exchange failing at
		 * {@see self::token()} used to be indistinguishable from a real "no such
		 * city" — exactly the bug #405 exists to close.
		 */
		public function suggest( string $query, \Woodev\Framework\Shipping\Location\Location_Scope $scope ): array {
			$needle = mb_strtolower( trim( $query ) );

			if ( '' === $needle ) {
				return [];
			}

			if ( \Woodev\Framework\Shipping\Location\Location_Record::LEVEL_REGION === $scope->level() ) {
				return $this->match_regions( $needle, $scope->country() );
			}

			if ( \Woodev\Framework\Shipping\Location\Location_Record::LEVEL_SETTLEMENT === $scope->level() ) {
				return $this->suggest_settlements( $query, $scope );
			}

			// `address` — never served (see declare_suggest_levels()). The framework's own
			// D15 chain walk is what keeps this branch unreached; answering [] rather than
			// throwing keeps a mis-wired caller harmless.
			return [];
		}

		/**
		 * {@inheritDoc}
		 *
		 * Overriding this method is what DECLARES {@see \Woodev\Framework\Shipping\Location\Location_Provider::CAPABILITY_LIST}
		 * — the abstract base reflects capabilities off the declaring class rather than
		 * asking for a list, so there is nothing else to register.
		 *
		 * This is the capability DaData structurally cannot have (a query-driven API cannot
		 * enumerate), and a carrier dictionary structurally can.
		 */
		public function list_localities( \Woodev\Framework\Shipping\Location\Location_Scope $scope ): array {
			if ( \Woodev\Framework\Shipping\Location\Location_Record::LEVEL_REGION === $scope->level() ) {
				return $this->match_regions( '', $scope->country() );
			}

			if ( \Woodev\Framework\Shipping\Location\Location_Record::LEVEL_SETTLEMENT !== $scope->level() ) {
				return [];
			}

			$region_code = $this->region_code_from_scope( $scope );

			if ( null === $region_code ) {
				// Enumerating every settlement of a country is not a list anyone can render,
				// and CDEK would page it into the tens of thousands. An unnarrowed settlement
				// scope gets nothing, deliberately.
				return [];
			}

			$rows = $this->request(
				'/location/cities',
				[
					'region_code'   => $region_code,
					'country_codes' => $scope->country(),
					'size'          => self::LIST_PAGE_SIZE,
				]
			);

			$records = [];

			foreach ( $rows as $row ) {
				$record = $this->record_from_city_row( $row );

				if ( null !== $record ) {
					$records[] = $record;
				}
			}

			return $records;
		}

		/**
		 * {@inheritDoc}
		 *
		 * DECLARES {@see \Woodev\Framework\Shipping\Location\Location_Provider::CAPABILITY_RESOLVE_KEY}
		 * — both `/location/regions?region_code=` and `/location/cities?code=` are
		 * exact single-row lookups by CDEK's own dictionary identity (see the file
		 * docblock's endpoint table), so no scope/country hint is needed to resolve
		 * either half of this provider's own key namespace (`r<region_code>` for a
		 * region — see {@see self::record_from_region()} — a bare settlement `code`
		 * otherwise).
		 *
		 * `null` means CDEK answered with zero rows for the id — the key is gone.
		 * Unconfigured, or a transport/malformed-payload failure, THROWS instead —
		 * {@see self::request()} degrades an unconfigured provider to `[]` for every
		 * OTHER method here (an empty answer is a valid "nothing to ask" for those),
		 * but that would be indistinguishable from "gone" for `resolve_key()`. This
		 * method therefore reads {@see self::token()} itself first — same as
		 * {@see self::request()} does internally — so an unconfigured provider (an
		 * empty token, never thrown) is told apart from a configured-but-failing one
		 * (a thrown {@see \Woodev\Framework\Shipping\Location\Location_Provider_Exception},
		 * propagated as-is) BEFORE `request()` ever gets the chance to collapse either
		 * into a silent `[]` — see {@see \Woodev\Framework\Shipping\Location\Location_Provider::resolve_key()}'s
		 * own "null means asked and told no" contract. Reading `token()` rather than
		 * {@see self::is_configured()} also keeps the cached-transient-token shortcut
		 * every other method here already relies on for testability.
		 */
		public function resolve_key( string $key ): ?\Woodev\Framework\Shipping\Location\Location_Record {
			[ $provider_id, $native_id ] = \Woodev\Framework\Shipping\Location\Locality_Key::parse( $key );

			if ( self::PROVIDER_ID !== $provider_id ) {
				throw new \InvalidArgumentException(
					sprintf(
						'Woodev_Test_Cdek_Location_Provider::resolve_key(): key "%s" belongs to provider "%s", not "%s".',
						$key,
						$provider_id,
						self::PROVIDER_ID
					)
				);
			}

			if ( '' === $this->token() ) {
				throw new \Woodev\Framework\Shipping\Location\Location_Provider_Exception(
					'CDEK test contour resolve_key request failed: provider is not configured.'
				);
			}

			if ( 'r' === substr( $native_id, 0, 1 ) && ctype_digit( substr( $native_id, 1 ) ) ) {
				return $this->resolve_region( (int) substr( $native_id, 1 ) );
			}

			if ( ! ctype_digit( $native_id ) ) {
				return null;
			}

			$rows = $this->request( '/location/cities', [ 'code' => (int) $native_id ] );

			return $this->record_from_city_row( $rows[0] ?? null );
		}

		/**
		 * Resolves a single region by its CDEK `region_code`, or `null` when CDEK
		 * returns no matching row.
		 *
		 * @param int $region_code CDEK region code (the `r`-prefixed native id, minus
		 *                         the prefix).
		 *
		 * @return \Woodev\Framework\Shipping\Location\Location_Record|null
		 */
		private function resolve_region( int $region_code ): ?\Woodev\Framework\Shipping\Location\Location_Record {
			$rows = $this->request( '/location/regions', [ 'region_code' => $region_code ] );
			$row  = $rows[0] ?? null;

			if ( ! is_array( $row ) || empty( $row['region_code'] ) || empty( $row['region'] ) || empty( $row['country_code'] ) ) {
				return null;
			}

			return $this->record_from_region( (int) $row['region_code'], (string) $row['region'], (string) $row['country_code'] );
		}

		// -----------------------------------------------------------------
		// Suggestion building
		// -----------------------------------------------------------------

		/**
		 * Region records whose name contains `$needle` — every region of the country when
		 * `$needle` is empty, which is what {@see self::list_localities()} enumerates.
		 *
		 * @param string $needle Lower-cased search term, or `''` for "all".
		 * @param string $country ISO-3166 alpha-2.
		 *
		 * @return \Woodev\Framework\Shipping\Location\Location_Record[]
		 */
		private function match_regions( string $needle, string $country ): array {
			$records = [];

			foreach ( $this->regions( $country ) as $region_code => $name ) {
				if ( '' !== $needle && false === mb_strpos( mb_strtolower( $name ), $needle ) ) {
					continue;
				}

				$records[] = $this->record_from_region( (int) $region_code, $name, $country );
			}

			return $records;
		}

		/**
		 * Settlement suggestions for `$query`, narrowed to the scope's parent region when it
		 * names one.
		 *
		 * @param string                                            $query Raw search term.
		 * @param \Woodev\Framework\Shipping\Location\Location_Scope $scope Settlement-level scope.
		 *
		 * @return \Woodev\Framework\Shipping\Location\Location_Record[]
		 */
		private function suggest_settlements( string $query, \Woodev\Framework\Shipping\Location\Location_Scope $scope ): array {
			$country     = $scope->country();
			$rows        = $this->request( '/location/suggest/cities', [ 'name' => $query, 'country_code' => $country ] );
			$region_code = $this->region_code_from_scope( $scope );
			$records     = [];

			foreach ( $rows as $row ) {
				$record = $this->record_from_suggest_row( $row, $country );

				if ( null === $record ) {
					continue;
				}

				// The `within` narrowing CDEK's own endpoint cannot do. Compared on the
				// ancestor KEY, never on the region's display name — the name is what the
				// parse produced, the key is what the dictionary lookup confirmed.
				if ( null !== $region_code && ! in_array( self::PROVIDER_ID . ':r' . $region_code, $record->ancestors(), true ) ) {
					continue;
				}

				$records[] = $record;
			}

			return $records;
		}

		/**
		 * Builds a region record.
		 *
		 * Native id is prefixed `r` so a region code and a settlement code — both plain
		 * integers out of two different CDEK dictionaries — can never collide inside one
		 * provider's key namespace.
		 *
		 * @param int    $region_code CDEK region code.
		 * @param string $name        Region name.
		 * @param string $country     ISO-3166 alpha-2.
		 *
		 * @return \Woodev\Framework\Shipping\Location\Location_Record
		 */
		private function record_from_region( int $region_code, string $name, string $country ): \Woodev\Framework\Shipping\Location\Location_Record {
			return \Woodev\Framework\Shipping\Location\Location_Record::from_array(
				[
					'key'         => self::PROVIDER_ID . ':r' . $region_code,
					'provider_id' => self::PROVIDER_ID,
					'level'       => \Woodev\Framework\Shipping\Location\Location_Record::LEVEL_REGION,
					'country'     => $country,
					'region'      => [ 'name' => $name ],
					'label'       => $name,
					'raw'         => [ 'region_code' => $region_code ],
				]
			);
		}

		/**
		 * Builds a settlement record from a `/location/suggest/cities` row, or `null` when
		 * the row carries no usable code.
		 *
		 * @param mixed  $row     One decoded suggestion row.
		 * @param string $country ISO-3166 alpha-2 the search ran under.
		 *
		 * @return \Woodev\Framework\Shipping\Location\Location_Record|null
		 */
		private function record_from_suggest_row( $row, string $country ): ?\Woodev\Framework\Shipping\Location\Location_Record {
			if ( ! is_array( $row ) || empty( $row['code'] ) ) {
				return null;
			}

			$parts       = self::split_full_name( (string) ( $row['full_name'] ?? '' ) );
			$region_name = $parts['region'];

			// A FEDERAL CITY is its own region, and `full_name` says so by omission: Moscow
			// comes back as plain "Москва, Россия", with no region part to parse at all. The
			// region is not GUESSED from that — it is CONFIRMED, by looking the settlement's
			// own name up in CDEK's region dictionary and only accepting an exact hit. CDEK's
			// own `/location/cities` reports the same pairing for both such cities (measured:
			// code 44 → region "Москва"/81, code 137 → "Санкт-Петербург"/82), so this
			// reproduces the carrier's own answer rather than inventing an ancestor the way
			// gotcha `a-derived-ancestor-is-not-the-one-the-customer-picked` warns against.
			// A two-part row whose settlement name is NOT in the dictionary keeps no region,
			// exactly as before.
			if ( '' === $region_name && '' !== $parts['settlement'] && null !== $this->region_code_for_name( $parts['settlement'], $country ) ) {
				$region_name = $parts['settlement'];
			}

			$region_code = '' === $region_name ? null : $this->region_code_for_name( $region_name, $country );

			return \Woodev\Framework\Shipping\Location\Location_Record::from_array(
				array_filter(
					[
						'key'         => self::PROVIDER_ID . ':' . (int) $row['code'],
						'provider_id' => self::PROVIDER_ID,
						'level'       => \Woodev\Framework\Shipping\Location\Location_Record::LEVEL_SETTLEMENT,
						'country'     => is_string( $row['country_code'] ?? null ) && '' !== $row['country_code'] ? $row['country_code'] : $country,
						'settlement'  => [ 'name' => $parts['settlement'] ],
						'region'      => '' === $region_name ? null : [ 'name' => $region_name ],
						'district'    => '' === $parts['district'] ? null : [ 'name' => $parts['district'] ],
						'label'       => (string) ( $row['full_name'] ?? $parts['settlement'] ),
						'raw'         => $row,
						'ancestors'   => null === $region_code ? null : [ self::PROVIDER_ID . ':r' . $region_code ],
					],
					static function ( $value ) {
						return null !== $value;
					}
				)
			);
		}

		/**
		 * Builds a settlement record from a `/location/cities` row — the richer payload:
		 * real components, coordinates and `fias_guid`, no parsing needed.
		 *
		 * @param mixed $row One decoded city row.
		 *
		 * @return \Woodev\Framework\Shipping\Location\Location_Record|null
		 */
		private function record_from_city_row( $row ): ?\Woodev\Framework\Shipping\Location\Location_Record {
			if ( ! is_array( $row ) || empty( $row['code'] ) || empty( $row['country_code'] ) ) {
				return null;
			}

			$region_code = isset( $row['region_code'] ) ? (int) $row['region_code'] : 0;

			return \Woodev\Framework\Shipping\Location\Location_Record::from_array(
				array_filter(
					[
						'key'         => self::PROVIDER_ID . ':' . (int) $row['code'],
						'provider_id' => self::PROVIDER_ID,
						'level'       => \Woodev\Framework\Shipping\Location\Location_Record::LEVEL_SETTLEMENT,
						'country'     => (string) $row['country_code'],
						'settlement'  => [ 'name' => (string) ( $row['city'] ?? '' ) ],
						'region'      => empty( $row['region'] ) ? null : [ 'name' => (string) $row['region'] ],
						'district'    => empty( $row['sub_region'] ) ? null : [ 'name' => (string) $row['sub_region'] ],
						'lat'         => isset( $row['latitude'] ) ? (float) $row['latitude'] : null,
						'lon'         => isset( $row['longitude'] ) ? (float) $row['longitude'] : null,
						'label'       => (string) ( $row['city'] ?? '' ),
						'raw'         => $row,
						'ancestors'   => 0 === $region_code ? null : [ self::PROVIDER_ID . ':r' . $region_code ],
					],
					static function ( $value ) {
						return null !== $value;
					}
				)
			);
		}

		/**
		 * Splits CDEK's `full_name` composite into display components.
		 *
		 * The shape, measured: `settlement[, district…], region, country` — with the region
		 * absent for a federal city ("Москва, Россия"). So: drop the last part (country),
		 * take the first as the settlement, the last of the remainder as the region, and
		 * join anything still in between as the district.
		 *
		 * PUBLIC AND STATIC because it is PURE — no WordPress calls, no network — which is
		 * what makes this one derived rule directly unit-testable without standing a whole
		 * provider (and a carrier) up behind it. Same "pure half extracted so the rule can be
		 * pinned on its own" reasoning {@see \Woodev_Test_Credential_Seeder::should_seed()}
		 * states for its own decision.
		 *
		 * @param string $full_name CDEK's own composite label.
		 *
		 * @return array{settlement: string, district: string, region: string}
		 */
		public static function split_full_name( string $full_name ): array {
			$parts = array_values(
				array_filter(
					array_map( 'trim', explode( ',', $full_name ) ),
					static function ( string $part ): bool {
						return '' !== $part;
					}
				)
			);

			$settlement = array_shift( $parts ) ?? '';

			// The country tail. Absent only for a malformed row, which then simply yields no
			// region rather than treating the country as one.
			array_pop( $parts );

			$region   = (string) array_pop( $parts );
			$district = implode( ', ', $parts );

			return [
				'settlement' => (string) $settlement,
				'district'   => $district,
				'region'     => $region,
			];
		}

		// -----------------------------------------------------------------
		// Region dictionary
		// -----------------------------------------------------------------

		/**
		 * The region dictionary for one country, `region_code => name`, from a transient
		 * when fresh.
		 *
		 * Only a NON-EMPTY answer is cached — an outage must be retried on the next request
		 * rather than remembered as "this country has no regions" for a day (the same rule
		 * the rig's live Yandex source states for its own cache).
		 *
		 * @param string $country ISO-3166 alpha-2.
		 *
		 * @return array<int, string>
		 */
		private function regions( string $country ): array {
			$transient_key = self::REGIONS_TRANSIENT_PREFIX . strtolower( $country );
			$cached        = get_transient( $transient_key );

			if ( is_array( $cached ) && [] !== $cached ) {
				return $cached;
			}

			$regions = [];

			foreach ( $this->request( '/location/regions', [ 'country_codes' => $country, 'size' => 1000 ] ) as $row ) {
				if ( is_array( $row ) && ! empty( $row['region_code'] ) && ! empty( $row['region'] ) ) {
					$regions[ (int) $row['region_code'] ] = (string) $row['region'];
				}
			}

			if ( [] !== $regions ) {
				set_transient( $transient_key, $regions, self::REGIONS_TTL );
			}

			return $regions;
		}

		/**
		 * The CDEK region code whose name equals `$name`, or `null`.
		 *
		 * Exact, case-insensitive match: this resolves a name CDEK itself just produced
		 * against CDEK's own dictionary, so a fuzzy match would only ever paper over a
		 * parse that already went wrong.
		 *
		 * @param string $name    Region name as parsed out of `full_name`.
		 * @param string $country ISO-3166 alpha-2.
		 *
		 * @return int|null
		 */
		private function region_code_for_name( string $name, string $country ): ?int {
			$needle = mb_strtolower( $name );

			foreach ( $this->regions( $country ) as $region_code => $region_name ) {
				if ( mb_strtolower( $region_name ) === $needle ) {
					return (int) $region_code;
				}
			}

			return null;
		}

		/**
		 * The CDEK region code a settlement-level scope is narrowed to — from the parent
		 * record's own key when the caller supplied a record, else from its `region.name`
		 * components. `null` when the scope has no parent, or names one that is not this
		 * provider's.
		 *
		 * A parent key belonging to ANOTHER provider yields `null`, not a guess: a
		 * `Locality_Key` carries its `provider_id` precisely so a foreign key can be
		 * recognised as foreign instead of being read as an opaque string.
		 *
		 * @param \Woodev\Framework\Shipping\Location\Location_Scope $scope Settlement-level scope.
		 *
		 * @return int|null
		 */
		private function region_code_from_scope( \Woodev\Framework\Shipping\Location\Location_Scope $scope ): ?int {
			$parent_record = $scope->parent_record();

			if ( null !== $parent_record ) {
				[ $provider_id, $native_id ] = \Woodev\Framework\Shipping\Location\Locality_Key::parse( $parent_record->key() );

				if ( self::PROVIDER_ID !== $provider_id || 'r' !== substr( $native_id, 0, 1 ) ) {
					return null;
				}

				return (int) substr( $native_id, 1 );
			}

			$components  = $scope->parent_components();
			$region_name = $components['region']['name'] ?? null;

			return is_string( $region_name ) && '' !== $region_name
				? $this->region_code_for_name( $region_name, $scope->country() )
				: null;
		}

		// -----------------------------------------------------------------
		// Transport
		// -----------------------------------------------------------------

		/**
		 * Performs one authenticated GET against the test contour and returns the decoded
		 * LIST body.
		 *
		 * THROWS {@see \Woodev\Framework\Shipping\Location\Location_Provider_Exception} (#405)
		 * on a transport failure, a non-200 response, or a `200` body that is not a
		 * decodable JSON array (malformed JSON, or valid JSON of the wrong shape —
		 * a string, a bare scalar) — a request that could not be completed, or whose
		 * answer could not be understood, is not the same thing as "this carrier
		 * dictionary has nothing here", and conflating any of these with that is
		 * exactly the bug #405 exists to close. Still returns `[]`, never throws,
		 * when {@see self::token()} itself returns `''` (the provider is not
		 * configured at all) — that is the ALREADY-honest #375 signal
		 * ({@see self::is_configured()} answers `false` for it), not this method's own
		 * concern to re-report.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Throws on a transport/non-200/malformed-body failure instead
		 *              of degrading to `[]` (#405).
		 *
		 * @param string               $path   Endpoint path, leading slash.
		 * @param array<string, mixed> $params Query parameters.
		 *
		 * @return array<int, mixed>
		 *
		 * @throws \Woodev\Framework\Shipping\Location\Location_Provider_Exception
		 */
		private function request( string $path, array $params ): array {
			$token = $this->token();

			if ( '' === $token ) {
				return [];
			}

			$response = wp_safe_remote_get(
				add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $params ) ), self::API_BASE . $path ),
				[
					'timeout' => self::REQUEST_TIMEOUT,
					'headers' => [
						'Authorization' => 'Bearer ' . $token,
						'Accept'        => 'application/json',
					],
				]
			);

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				$this->log( sprintf( 'GET %s failed', $path ), $response );

				throw new \Woodev\Framework\Shipping\Location\Location_Provider_Exception(
					sprintf( 'CDEK test contour GET %s failed.', $path )
				);
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( ! is_array( $body ) ) {
				$this->log( sprintf( 'GET %s returned a malformed body', $path ), $response );

				throw new \Woodev\Framework\Shipping\Location\Location_Provider_Exception(
					sprintf( 'CDEK test contour GET %s returned a malformed body.', $path )
				);
			}

			return $body;
		}

		/**
		 * The OAuth bearer token, from a transient when fresh.
		 *
		 * Cached for the lifetime CDEK itself reports, less a minute of slack so a token
		 * cannot expire between being read here and arriving there.
		 *
		 * THROWS {@see \Woodev\Framework\Shipping\Location\Location_Provider_Exception} (#405)
		 * when {@see self::is_configured()} is `true` (both credential fields are
		 * non-empty) but the exchange itself fails — wrong `client_id`/`client_secret`
		 * (CDEK answers 401 the same way a network failure answers non-200: this
		 * fixture does not need to tell the two apart, only "configured but not
		 * working" from "not configured"), an unreachable test contour, or a 200
		 * response carrying no `access_token`. THIS is the exact rig reproduction
		 * #405's own card walks through: `is_configured()` reads only whether the two
		 * fields are non-empty, so wrong keys used to reach this point, fail here,
		 * and return `''` — indistinguishable downstream from "no such city". Still
		 * returns `''`, never throws, when {@see self::is_configured()} is `false` —
		 * that is the ALREADY-honest #375 signal, not this method's own concern.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Throws on a configured-but-failing exchange instead of
		 *              degrading to `''` (#405).
		 *
		 * @return string Empty ONLY when unconfigured.
		 *
		 * @throws \Woodev\Framework\Shipping\Location\Location_Provider_Exception
		 */
		private function token(): string {
			$cached = get_transient( self::TOKEN_TRANSIENT );

			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}

			if ( ! $this->is_configured() ) {
				return '';
			}

			$response = wp_safe_remote_post(
				self::API_BASE . '/oauth/token',
				[
					'timeout' => self::REQUEST_TIMEOUT,
					'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
					'body'    => [
						'grant_type'    => 'client_credentials',
						'client_id'     => $this->credential( Woodev_Test_Cdek_Integration::FIELD_CLIENT_ID ),
						'client_secret' => $this->credential( Woodev_Test_Cdek_Integration::FIELD_CLIENT_SECRET ),
					],
				]
			);

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				$this->log( 'OAuth token exchange failed', $response );

				throw new \Woodev\Framework\Shipping\Location\Location_Provider_Exception(
					'CDEK OAuth token exchange failed — check the configured Client ID/Secret.'
				);
			}

			$body  = json_decode( wp_remote_retrieve_body( $response ), true );
			$token = is_array( $body ) && is_string( $body['access_token'] ?? null ) ? $body['access_token'] : '';

			if ( '' === $token ) {
				$this->log( 'OAuth token exchange returned no access_token', $response );

				throw new \Woodev\Framework\Shipping\Location\Location_Provider_Exception(
					'CDEK OAuth token exchange returned no access_token.'
				);
			}

			$ttl = isset( $body['expires_in'] ) ? max( 60, (int) $body['expires_in'] - 60 ) : 5 * MINUTE_IN_SECONDS;

			set_transient( self::TOKEN_TRANSIENT, $token, $ttl );

			return $token;
		}

		/**
		 * Reads one stored credential from the CARRIER's own settings (issue #375;
		 * {@see Woodev_Test_Cdek_Integration}) — NEVER through
		 * `Location_Settings::get_value()` (this provider declares zero fields there
		 * now, so that handler has nothing registered under `$field_id` to read at
		 * all) and never through the Integration handler's OWN `get_option()` either
		 * (that instance may not have been constructed yet on every code path this
		 * method is reached from). {@see \Woodev\Framework\Shipping\Shipping_Plugin::get_integration_option()}
		 * already implements exactly this raw-option-with-a-handler-when-available
		 * discipline — the same one
		 * {@see \Woodev\Framework\Shipping\Location\Providers\Dadata_Provider::token()}'s
		 * own docblock documents for model 1 providers, applied here to model 2.
		 *
		 * @since 2.0.2 Reads through `Woodev_Test_Shipping_Method_Plugin::get_integration_option()`
		 *              instead of `get_option( 'woodev_location_' . $field_id )` —
		 *              the credentials moved from the location-provider settings
		 *              surface to the carrier's own Integration settings (#375).
		 *
		 * @param string $field_id One of {@see Woodev_Test_Cdek_Integration}'s FIELD_* constants.
		 *
		 * @return string
		 */
		private function credential( string $field_id ): string {
			return trim( (string) Woodev_Test_Shipping_Method_Plugin::instance()->get_integration_option( $field_id, '' ) );
		}

		/**
		 * Logs a failure, when the rig is running with debug logging on.
		 *
		 * @param string $message  What failed.
		 * @param mixed  $response The `wp_remote_*` response or WP_Error behind it.
		 *
		 * @return void
		 */
		private function log( string $message, $response ): void {
			if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
				return;
			}

			$detail = is_wp_error( $response )
				? $response->get_error_message()
				: sprintf( 'HTTP %d', (int) wp_remote_retrieve_response_code( $response ) );

			error_log( sprintf( '[%s] %s: %s', self::PROVIDER_ID, $message, $detail ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
