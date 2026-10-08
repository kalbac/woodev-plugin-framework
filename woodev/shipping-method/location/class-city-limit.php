<?php
/**
 * The city limit of a shipping method: «available only in these cities» / «not available in these cities».
 *
 * Stored per method instance as two WooCommerce instance settings — the mode and the list of cities. The list
 * holds WHOLE {@see Location_Record}s (key, provider, level, country, region/settlement names, label, ancestors),
 * never bare keys: when the store switches location provider the names are what is left to re-resolve a city by,
 * exactly as the `fixed` default locality does ({@see Location_Service::reresolve_stranded_record()}).
 *
 * The decision itself ({@see self::allows()}) is pure; {@see self::permits()} wires it to the customer's record at
 * checkout. Two rules, both decided by the coordinator (#1176):
 *  - NO customer record yet → the method stays available (fail-open). A limit must never hide shipping for lack of
 *    data; WooCommerce re-rates on order submit, so the limit is enforced as soon as a city is known.
 *  - A stored city the current provider cannot vouch for (stale) is IGNORED — an «only these» list of nothing but
 *    stale cities does not make the method vanish for everyone. The settings form warns about it.
 *
 * @package Woodev\Framework\Shipping\Location
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Location;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\City_Limit' ) ) :

	/**
	 * Class City_Limit
	 *
	 * @since 2.0.2
	 */
	final class City_Limit {

		/** No limit. */
		public const MODE_OFF = '';

		/** Available only in the listed cities. */
		public const MODE_INCLUDE = 'include';

		/** Available everywhere except the listed cities. */
		public const MODE_EXCLUDE = 'exclude';

		/** Instance setting holding the mode. */
		public const OPTION_MODE = 'city_limit_mode';

		/** Instance setting holding the cities (JSON list of records). */
		public const OPTION_CITIES = 'city_limit_cities';

		/** The custom WooCommerce form-field type of the cities list. */
		public const FIELD_TYPE = 'woodev_city_limit';

		/** A list longer than this is cut: the form is a handful of cities, not an import. */
		public const MAX_CITIES = 200;

		/** A zone with more regions than this is searched country-wide (and filtered), not region by region. */
		public const MAX_SEARCH_REGIONS = 8;

		/**
		 * The modes, value => merchant-facing label.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string, string>
		 */
		public static function modes(): array {
			return [
				self::MODE_OFF     => __( 'Без ограничений', 'woodev-plugin-framework' ),
				self::MODE_INCLUDE => __( 'Доступен только в городах', 'woodev-plugin-framework' ),
				self::MODE_EXCLUDE => __( 'Недоступен в городах', 'woodev-plugin-framework' ),
			];
		}

		/**
		 * Clamps anything to a known mode (an unknown or missing value means «no limit»).
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $mode Raw mode.
		 *
		 * @return string
		 */
		public static function normalize_mode( $mode ): string {
			$mode = is_string( $mode ) ? trim( $mode ) : '';

			return in_array( $mode, [ self::MODE_INCLUDE, self::MODE_EXCLUDE ], true ) ? $mode : self::MODE_OFF;
		}

		/**
		 * Reads the stored cities. Tolerant by design: a broken blob, a malformed entry, a non-settlement
		 * level or a repeated key is skipped, never an error — the list is read on every availability check.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $raw The JSON string (or an already decoded array) of the stored setting.
		 *
		 * @return Location_Record[]
		 */
		public static function decode( $raw ): array {
			if ( is_string( $raw ) ) {
				$raw = '' === trim( $raw ) ? [] : json_decode( $raw, true );
			}

			if ( ! is_array( $raw ) ) {
				return [];
			}

			$records = [];

			foreach ( $raw as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				try {
					$record = Location_Record::from_array( $entry );
				} catch ( \InvalidArgumentException $exception ) {
					continue;
				}

				if ( Location_Record::LEVEL_SETTLEMENT !== $record->level() || isset( $records[ $record->key() ] ) ) {
					continue;
				}

				$records[ $record->key() ] = $record;

				if ( count( $records ) >= self::MAX_CITIES ) {
					break;
				}
			}

			return array_values( $records );
		}

		/**
		 * The stored form of a list: compact records (the provider's opaque `raw` payload dropped — it can be
		 * kilobytes per city and is never read here).
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Record[] $records Cities.
		 *
		 * @return string JSON, `[]` for none.
		 */
		public static function encode( array $records ): string {
			$list = [];

			foreach ( $records as $record ) {
				if ( $record instanceof Location_Record ) {
					$list[] = array_merge( $record->to_array(), [ 'raw' => null ] );
				}
			}

			// Unescaped: a classic settings page strips slashes from what it posts, and `Д` would not survive that.
			$json = wp_json_encode( $list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

			return is_string( $json ) ? $json : '[]';
		}

		/**
		 * Splits a list into the cities the current provider still answers for and the ones it does not
		 * (the store switched provider after the merchant picked them).
		 *
		 * The same ownership rule as the customer record's staleness gate
		 * ({@see Location_Service::is_customer_record_stale()}, rule a): the provider the D15 chain resolves for the
		 * record's level and country must be the one that produced it.
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Record[] $records Cities.
		 * @param Location_Service  $service Location service.
		 *
		 * @return array{current: Location_Record[], stale: Location_Record[]}
		 */
		public static function partition( array $records, Location_Service $service ): array {
			$current = [];
			$stale   = [];

			foreach ( $records as $record ) {
				try {
					$owner = $service->provider_for_level( $record->level(), $record->country() );
				} catch ( \Throwable $exception ) {
					$owner = null;
				}

				if ( null !== $owner && $owner->get_id() === $record->provider_id() ) {
					$current[] = $record;
				} else {
					$stale[] = $record;
				}
			}

			return [
				'current' => $current,
				'stale'   => $stale,
			];
		}

		/**
		 * The decision, pure: is the method available to this customer?
		 *
		 * @since 2.0.2
		 *
		 * @param string               $mode     One of the MODE_ constants.
		 * @param Location_Record[]    $cities   The cities that still count (see {@see self::partition()}).
		 * @param Location_Record|null $customer The customer's settlement-level record, or `null` when unknown.
		 *
		 * @return bool
		 */
		public static function allows( string $mode, array $cities, ?Location_Record $customer ): bool {
			$mode = self::normalize_mode( $mode );

			if ( self::MODE_OFF === $mode || [] === $cities || null === $customer ) {
				return true;
			}

			$listed = false;

			foreach ( $cities as $city ) {
				if ( $customer->is_within( $city->key() ) ) {
					$listed = true;
					break;
				}
			}

			return self::MODE_INCLUDE === $mode ? $listed : ! $listed;
		}

		/**
		 * Whether a method with this stored limit is available to the customer at checkout right now.
		 *
		 * Only a city the CUSTOMER chose counts. The store's guessed default locality (the `fixed` / GeoIP policies) is a
		 * prefill, not an answer — {@see Checkout_Config} refuses it as a selection for the same reason — so an absent
		 * chain and an implicit one both follow D1: available.
		 *
		 * Cities outside the zone's current regions are dropped before the empty-list check, exactly as the settings form
		 * says they are (`$zone`, {@see self::zone_scope()}): a zone edited after the list was made must not leave the
		 * old cities blocking the new region's buyers.
		 *
		 * Never throws: a location layer that fails here answers «available» — shipping must not vanish
		 * because a provider is down.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed            $mode    Stored mode.
		 * @param mixed            $cities  Stored cities.
		 * @param Location_Service $service Location service.
		 * @param string           $country ISO country of the package destination ('' when unknown).
		 * @param array            $zone    {@see self::zone_scope()} of the method's zone; `[]` for «no zone restriction».
		 *
		 * @return bool
		 */
		public static function permits( $mode, $cities, Location_Service $service, string $country = '', array $zone = [] ): bool {
			$mode = self::normalize_mode( $mode );

			if ( self::MODE_OFF === $mode ) {
				return true;
			}

			try {
				$counted = self::partition( self::decode( $cities ), $service )['current'];

				if ( [] !== $zone ) {
					$counted = array_values(
						array_filter(
							$counted,
							static function ( Location_Record $record ) use ( $zone, $service ): bool {
								return self::in_zone( $record, (array) ( $zone['states'] ?? [] ), $service, (array) ( $zone['countries'] ?? [] ) );
							}
						)
					);
				}

				if ( [] === $counted ) {
					return true;
				}

				$country = strtoupper( trim( $country ) );
				$chain   = $service->get_customer_chain( '' === $country ? null : $country );

				if ( null === $chain || ! empty( $chain['implicit'] ) ) {
					return true;
				}

				return self::allows( $mode, $counted, $chain['records'][ Location_Record::LEVEL_SETTLEMENT ] ?? null );
			} catch ( \Throwable $exception ) {
				return true;
			}
		}

		/**
		 * What a shipping zone says about regions, from its raw locations (pure — `type` and `code` pairs as
		 * WooCommerce stores them: `country` => `RU`, `state` => `RU:MOW`, `continent` => `EU`).
		 *
		 * WooCommerce matches a package against country, state and continent rows with OR, so all three count. A
		 * continent is expanded to its countries through WooCommerce's own table ({@see self::continent_countries()}), and —
		 * like a country listed whole — it overrides a narrower state row of any country it covers.
		 *
		 * `countries` are the countries the zone reaches by name, in zone order; `country` is the first. `states` =
		 * country => state codes, only for a country the zone restricts to regions.
		 *
		 * If a continent cannot be expanded the country list would be partial, so it is never offered as exhaustive: the
		 * result is the empty scope (no country or region restriction), the same as for a zone that names no country.
		 *
		 * @since 2.0.2
		 *
		 * @param array<int, array{0: string, 1: string}> $locations `[ type, code ]` pairs.
		 *
		 * @return array{country: string, countries: string[], states: array<string, string[]>}
		 */
		public static function scope_from_locations( array $locations ): array {
			$countries = [];
			$whole     = [];
			$states    = [];

			foreach ( $locations as $location ) {
				$type = (string) ( $location[0] ?? '' );
				$code = (string) ( $location[1] ?? '' );

				if ( 'country' === $type && '' !== $code ) {
					$country = strtoupper( $code );

					$whole[]     = $country;
					$countries[] = $country;
				} elseif ( 'continent' === $type && '' !== $code ) {
					$covered = self::continent_countries( $code );

					if ( null === $covered ) {
						return [
							'country'   => '',
							'countries' => [],
							'states'    => [],
						];
					}

					foreach ( $covered as $country ) {
						$whole[]     = $country;
						$countries[] = $country;
					}
				} elseif ( 'state' === $type && false !== strpos( $code, ':' ) ) {
					[ $country, $state ] = explode( ':', $code, 2 );

					$country = strtoupper( $country );

					if ( '' !== $country && '' !== $state ) {
						$states[ $country ][] = $state;
						$countries[]          = $country;
					}
				}
			}

			foreach ( array_keys( $states ) as $country ) {
				if ( in_array( $country, $whole, true ) ) {
					unset( $states[ $country ] );
				}
			}

			$countries = array_values( array_unique( $countries ) );

			return [
				'country'   => $countries[0] ?? '',
				'countries' => $countries,
				'states'    => $states,
			];
		}

		/**
		 * @var array<string, string[]>|null A test's stand-in for WooCommerce's continent table.
		 */
		private static $continents_for_tests = null;

		/**
		 * Test-only: replaces WooCommerce's continent table (`null` puts the real one back).
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, string[]>|null $continents Continent code => country codes.
		 *
		 * @return void
		 */
		public static function use_continents_for_tests( ?array $continents ): void {
			self::$continents_for_tests = $continents;
		}

		/**
		 * The countries of a continent, from WooCommerce's own table (`WC()->countries->get_continents()`, the data
		 * its zone matching uses) — nothing is hardcoded here.
		 *
		 * @since 2.0.2
		 *
		 * @param string $continent Continent code (`EU`).
		 *
		 * @return string[]|null Upper-case country codes, or `null` when WooCommerce cannot say.
		 */
		public static function continent_countries( string $continent ): ?array {
			if ( null !== self::$continents_for_tests ) {
				$list = self::$continents_for_tests[ $continent ] ?? null;
			} elseif ( function_exists( 'WC' ) && ! empty( WC()->countries ) && method_exists( WC()->countries, 'get_continents' ) ) {
				$list = WC()->countries->get_continents()[ $continent ]['countries'] ?? null;
			} else {
				$list = null;
			}

			if ( ! is_array( $list ) || [] === $list ) {
				return null;
			}

			return array_values( array_unique( array_map( 'strtoupper', array_map( 'strval', $list ) ) ) );
		}

		/**
		 * The region scope of the zone a method instance belongs to.
		 *
		 * Empty for the «rest of the world» zone, for an unknown instance, and without WooCommerce. A zone that covers
		 * a whole country, or a store whose location provider injects no regions (DaData: WooCommerce has no Russian
		 * states), has countries but no `states` — the city search is then country-wide and nothing is checked against
		 * regions.
		 *
		 * @since 2.0.2
		 *
		 * @param int $instance_id Shipping method instance id.
		 *
		 * @return array{country: string, countries: string[], states: array<string, string[]>}
		 */
		public static function zone_scope( int $instance_id ): array {
			$empty = [
				'country'   => '',
				'countries' => [],
				'states'    => [],
			];

			if ( null !== self::$zone_pairs_for_tests ) {
				return self::scope_from_locations( (array) call_user_func( self::$zone_pairs_for_tests, $instance_id ) );
			}

			if ( $instance_id <= 0 || ! class_exists( '\WC_Shipping_Zones' ) ) {
				return $empty;
			}

			$zone = \WC_Shipping_Zones::get_zone_by( 'instance_id', $instance_id );

			// An unknown instance comes back as a blank zone whose id is NULL (the «rest of the world» zone is 0).
			if ( ! $zone instanceof \WC_Shipping_Zone || null === $zone->get_id() ) {
				return $empty;
			}

			$pairs = [];

			foreach ( $zone->get_zone_locations() as $location ) {
				$pairs[] = [ (string) ( $location->type ?? '' ), (string) ( $location->code ?? '' ) ];
			}

			return self::scope_from_locations( $pairs );
		}

		/**
		 * @var callable|null A test's stand-in for the zone's raw locations (`instance id` => `[ type, code ]` pairs).
		 */
		private static $zone_pairs_for_tests = null;

		/**
		 * Test-only: replaces the WooCommerce zone lookup (`null` puts the real one back).
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param callable|null $pairs Called with the instance id, answers `[ type, code ]` pairs.
		 *
		 * @return void
		 */
		public static function use_zone_pairs_for_tests( ?callable $pairs ): void {
			self::$zone_pairs_for_tests = $pairs;
		}

		/**
		 * The countries a zone reaches, as a picker offers them.
		 *
		 * @since 2.0.2
		 *
		 * @param string[] $codes ISO country codes.
		 *
		 * @return array<int, array{code: string, name: string}> Name falls back to the code without WooCommerce.
		 */
		public static function country_options( array $codes ): array {
			$names = function_exists( 'WC' ) && ! empty( WC()->countries ) ? WC()->countries->get_countries() : [];
			$names = is_array( $names ) ? $names : [];
			$out   = [];

			foreach ( $codes as $code ) {
				$out[] = [
					'code' => (string) $code,
					'name' => (string) ( $names[ $code ] ?? $code ),
				];
			}

			return $out;
		}

		/**
		 * The display names of WooCommerce states — what a provider's region search is asked for.
		 *
		 * @since 2.0.2
		 *
		 * @param string   $country ISO country code.
		 * @param string[] $codes   State codes (`$country`'s keys in `woocommerce_states`).
		 *
		 * @return array<string, string> code => label; a code WooCommerce does not know is left out.
		 */
		public static function state_labels( string $country, array $codes ): array {
			if ( ! function_exists( 'WC' ) || empty( WC()->countries ) ) {
				return [];
			}

			$states = WC()->countries->get_states( $country );
			$labels = [];

			if ( ! is_array( $states ) ) {
				return [];
			}

			foreach ( $codes as $code ) {
				if ( isset( $states[ $code ] ) && '' !== trim( (string) $states[ $code ] ) ) {
					$labels[ (string) $code ] = (string) $states[ $code ];
				}
			}

			return $labels;
		}

		/**
		 * Whether a stored city lies in the zone: in one of its countries, and — for a country the zone restricts to
		 * regions — in one of those regions. `true` whenever that cannot be told (no restriction, no WooCommerce state
		 * for the record) — a doubt never drops a city.
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Record         $record    City.
		 * @param array<string, string[]> $states    {@see self::scope_from_locations()} `states`.
		 * @param Location_Service        $service   Location service.
		 * @param string[]                $countries {@see self::scope_from_locations()} `countries`; `[]` = any.
		 *
		 * @return bool
		 */
		public static function in_zone( Location_Record $record, array $states, Location_Service $service, array $countries = [] ): bool {
			if ( [] !== $countries && ! in_array( $record->country(), $countries, true ) ) {
				return false;
			}

			$wanted = $states[ $record->country() ] ?? null;

			// No region restriction for that country (the zone lists it whole, or does not mention it).
			if ( null === $wanted ) {
				return true;
			}

			$code = $service->wc_state_code_for_record( $record );

			return null === $code || in_array( $code, $wanted, true );
		}
	}

endif;
