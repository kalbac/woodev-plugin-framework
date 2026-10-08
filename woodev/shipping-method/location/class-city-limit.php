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
		 * Never throws: a location layer that fails here answers «available» — shipping must not vanish
		 * because a provider is down.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed            $mode    Stored mode.
		 * @param mixed            $cities  Stored cities.
		 * @param Location_Service $service Location service.
		 * @param string           $country ISO country of the package destination ('' when unknown).
		 *
		 * @return bool
		 */
		public static function permits( $mode, $cities, Location_Service $service, string $country = '' ): bool {
			$mode = self::normalize_mode( $mode );

			if ( self::MODE_OFF === $mode ) {
				return true;
			}

			try {
				$counted = self::partition( self::decode( $cities ), $service )['current'];

				if ( [] === $counted ) {
					return true;
				}

				$country  = strtoupper( trim( $country ) );
				$customer = $service->get_customer_record_at( Location_Record::LEVEL_SETTLEMENT, '' === $country ? null : $country );

				return self::allows( $mode, $counted, $customer );
			} catch ( \Throwable $exception ) {
				return true;
			}
		}

		/**
		 * What a shipping zone says about regions, from its raw locations (pure — `type` and `code` pairs as
		 * WooCommerce stores them: `country` => `RU`, `state` => `RU:MOW`).
		 *
		 * A country the zone lists WHOLE wins over states of the same country (WooCommerce matches either, so the
		 * zone is not restricted there). The result is what the city search must stay inside:
		 * `states` = country => state codes, empty when the zone has no region restriction.
		 *
		 * @since 2.0.2
		 *
		 * @param array<int, array{0: string, 1: string}> $locations `[ type, code ]` pairs.
		 *
		 * @return array{country: string, states: array<string, string[]>}
		 */
		public static function scope_from_locations( array $locations ): array {
			$countries = [];
			$states    = [];

			foreach ( $locations as $location ) {
				$type = (string) ( $location[0] ?? '' );
				$code = (string) ( $location[1] ?? '' );

				if ( 'country' === $type && '' !== $code ) {
					$countries[] = strtoupper( $code );
				} elseif ( 'state' === $type && false !== strpos( $code, ':' ) ) {
					[ $country, $state ] = explode( ':', $code, 2 );

					$country = strtoupper( $country );

					if ( '' !== $country && '' !== $state ) {
						$states[ $country ][] = $state;
					}
				}
			}

			foreach ( array_keys( $states ) as $country ) {
				if ( in_array( $country, $countries, true ) ) {
					unset( $states[ $country ] );
				}
			}

			$first = array_key_first( $states );

			return [
				'country' => null !== $first ? (string) $first : ( $countries[0] ?? '' ),
				'states'  => $states,
			];
		}

		/**
		 * The region scope of the zone a method instance belongs to.
		 *
		 * Empty (`states` = []) for a zone that covers a whole country, for the «rest of the world» zone, for an
		 * unknown instance, and for a store whose location provider injects no regions (DaData: WooCommerce has
		 * no Russian states) — in all of them the city search is country-wide and nothing is checked against
		 * regions.
		 *
		 * @since 2.0.2
		 *
		 * @param int $instance_id Shipping method instance id.
		 *
		 * @return array{country: string, states: array<string, string[]>}
		 */
		public static function zone_scope( int $instance_id ): array {
			$empty = [
				'country' => '',
				'states'  => [],
			];

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
		 * Whether a stored city lies in the zone's regions. `true` whenever that cannot be told
		 * (no region restriction, no WooCommerce state for the record) — a doubt never drops a city.
		 *
		 * @since 2.0.2
		 *
		 * @param Location_Record         $record  City.
		 * @param array<string, string[]> $states  {@see self::scope_from_locations()} `states`.
		 * @param Location_Service        $service Location service.
		 *
		 * @return bool
		 */
		public static function in_zone( Location_Record $record, array $states, Location_Service $service ): bool {
			if ( [] === $states ) {
				return true;
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
