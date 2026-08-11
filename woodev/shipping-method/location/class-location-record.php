<?php
/**
 * Woodev Location Record
 *
 * The neutral, contract-shaped result of a location-provider lookup (spec D12). A
 * provider's own payload is mapped into this shape by the provider; the raw payload
 * rides along opaque and untouched under `raw` — adapters and the cascade engine never
 * depend on any provider's own dictionary.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Location;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Location\\Location_Record' ) ) :

	/**
	 * Immutable value object describing one location-provider result.
	 *
	 * The record travels whole everywhere: session slot, adapters, pickup
	 * `[location][type]` map (spec §4.2). An adapter takes from it whatever it can
	 * resolve by; the framework itself never inspects `raw`.
	 *
	 * @since 2.0.2
	 */
	class Location_Record {

		/** Region-level record (spec §3 "Level"). */
		public const LEVEL_REGION = 'region';

		/** Settlement-level record. */
		public const LEVEL_SETTLEMENT = 'settlement';

		/** Address-level record. */
		public const LEVEL_ADDRESS = 'address';

		/**
		 * All valid levels, in the cascade's own order (spec §4.4: Region → Locality →
		 * Address). Other tasks (the provider contract, the scope object) validate
		 * against this list rather than repeating the three literals.
		 *
		 * @since 2.0.2
		 *
		 * @var string[]
		 */
		public const LEVELS = [ self::LEVEL_REGION, self::LEVEL_SETTLEMENT, self::LEVEL_ADDRESS ];

		/**
		 * Normalized record data. Always carries every key listed in
		 * {@see self::from_array()}; absent optional scalars are `''` (strings) or
		 * `null` (numbers, component groups, raw).
		 *
		 * @var array<string, mixed>
		 */
		private array $data;

		/**
		 * Constructor. Use {@see self::from_array()} — it validates.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $data Pre-validated normalized data.
		 */
		private function __construct( array $data ) {
			$this->data = $data;
		}

		/**
		 * Builds a record from a provider-supplied (or previously-serialized) payload.
		 *
		 * Required: `key`, `provider_id`, `level` (one of {@see self::LEVELS}),
		 * `country` (ISO-3166 alpha-2; case-normalized to upper-case on the way in).
		 * Everything else is optional. `region`/`district`/`settlement`/`street` are
		 * `{ name, type }` shapes; `house`/`block`/`flat`/`postcode`/`label` are
		 * display-ish strings; `lat`/`lon` are numeric; `raw` is the opaque provider
		 * payload, stored and returned untouched, never inspected.
		 *
		 * `key`'s own namespace prefix (per {@see Locality_Key::parse()}) MUST agree
		 * with the declared `provider_id` — a mismatch is exactly the "stale
		 * foreign-namespace key" the whole prefixed-key discipline (spec D5) exists to
		 * catch, so it is refused here rather than let through to travel silently into
		 * the customer store or an adapter cache under the wrong namespace.
		 *
		 * Values are rejected rather than coerced for shape-sensitive fields (`level`,
		 * `country`, the component groups, `lat`/`lon`); display-ish string fields
		 * accept any scalar and cast, mirroring {@see \Woodev\Framework\Shipping\Pickup\Pickup_Point::from_array()}'s
		 * precedent — a provider payload may legitimately carry e.g. a numeric house
		 * number as either an int or a string.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $data Raw payload.
		 *
		 * @return self
		 *
		 * @throws \InvalidArgumentException When a required field is missing/empty, or
		 *                                    a field is present but the wrong shape.
		 */
		public static function from_array( array $data ): self {
			$key         = self::require_non_empty_string( $data, 'key' );
			$provider_id = self::require_non_empty_string( $data, 'provider_id' );

			try {
				[ $key_provider_id ] = Locality_Key::parse( $key );
			} catch ( \InvalidArgumentException $exception ) {
				throw new \InvalidArgumentException(
					sprintf( 'Location_Record::from_array(): "key" is not a valid locality key: %s', $exception->getMessage() )
				);
			}

			if ( $key_provider_id !== $provider_id ) {
				throw new \InvalidArgumentException(
					sprintf(
						'Location_Record::from_array(): "key" provider prefix ("%s") does not match "provider_id" ("%s").',
						$key_provider_id,
						$provider_id
					)
				);
			}

			$level = self::require_non_empty_string( $data, 'level' );

			if ( ! in_array( $level, self::LEVELS, true ) ) {
				throw new \InvalidArgumentException(
					sprintf( 'Location_Record::from_array(): "level" must be one of %s, got "%s".', implode( ', ', self::LEVELS ), $level )
				);
			}

			$country = self::require_non_empty_string( $data, 'country' );
			$country = strtoupper( trim( $country ) );

			if ( 1 !== preg_match( '/^[A-Z]{2}$/', $country ) ) {
				throw new \InvalidArgumentException(
					sprintf( 'Location_Record::from_array(): "country" must be a 2-letter ISO-3166 alpha-2 code, got "%s".', $data['country'] )
				);
			}

			return new self(
				[
					'key'         => $key,
					'provider_id' => $provider_id,
					'level'       => $level,
					'country'     => $country,
					'region'      => self::parse_component_group( $data, 'region' ),
					'district'    => self::parse_component_group( $data, 'district' ),
					'settlement'  => self::parse_component_group( $data, 'settlement' ),
					'street'      => self::parse_component_group( $data, 'street' ),
					'house'       => self::optional_string( $data, 'house' ),
					'block'       => self::optional_string( $data, 'block' ),
					'flat'        => self::optional_string( $data, 'flat' ),
					'postcode'    => self::optional_string( $data, 'postcode' ),
					'lat'         => self::optional_float( $data, 'lat' ),
					'lon'         => self::optional_float( $data, 'lon' ),
					'label'       => self::optional_string( $data, 'label' ),
					// Opaque: stored and returned untouched (spec D12). Absent and
					// explicit null are deliberately indistinguishable — the framework
					// never inspects this field, so there is nothing "null" would tell
					// it that "absent" does not.
					'raw'         => array_key_exists( 'raw', $data ) ? $data['raw'] : null,
				]
			);
		}

		/**
		 * Requires `$data[ $field ]` to be a non-empty (after trim), string.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $data  Raw payload.
		 * @param string               $field Field name (used in the exception message).
		 *
		 * @return string
		 *
		 * @throws \InvalidArgumentException When missing, not a string, or whitespace-only.
		 */
		private static function require_non_empty_string( array $data, string $field ): string {
			$value = $data[ $field ] ?? null;

			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				throw new \InvalidArgumentException(
					sprintf( 'Location_Record::from_array() requires a non-empty "%s".', $field )
				);
			}

			return $value;
		}

		/**
		 * Parses an optional `{ name, type }` component group (`region`, `district`,
		 * `settlement`, `street`).
		 *
		 * Absent or explicit `null` both mean "not supplied" and return `null`. A
		 * present-but-non-array value is refused rather than coerced — a bare locality
		 * NAME string is exactly the opaque-string shape this whole layer replaces (spec
		 * §1), so silently accepting one here would let it back in through the side door.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $data  Raw payload.
		 * @param string               $field Field name (`region`, `district`, `settlement`, or `street`).
		 *
		 * @return array{name: string, type: string}|null
		 *
		 * @throws \InvalidArgumentException When present but not an array, or `name`/`type` are
		 *                                    present but not scalar.
		 */
		private static function parse_component_group( array $data, string $field ): ?array {
			if ( ! array_key_exists( $field, $data ) || null === $data[ $field ] ) {
				return null;
			}

			$value = $data[ $field ];

			if ( ! is_array( $value ) ) {
				throw new \InvalidArgumentException(
					sprintf( 'Location_Record::from_array(): "%s" must be an array shaped { name, type }.', $field )
				);
			}

			foreach ( [ 'name', 'type' ] as $sub_field ) {
				if ( isset( $value[ $sub_field ] ) && ! is_scalar( $value[ $sub_field ] ) ) {
					throw new \InvalidArgumentException(
						sprintf( 'Location_Record::from_array(): "%s.%s" must be a scalar.', $field, $sub_field )
					);
				}
			}

			return [
				'name' => isset( $value['name'] ) ? (string) $value['name'] : '',
				'type' => isset( $value['type'] ) ? (string) $value['type'] : '',
			];
		}

		/**
		 * Reads an optional display-ish string field. Absent or explicit `null` become
		 * `''`. Any other scalar is cast to string (Pickup_Point precedent — a provider
		 * may legitimately send a numeric house number or postcode as an int); a
		 * non-scalar value (array/object) is refused.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $data  Raw payload.
		 * @param string               $field Field name.
		 *
		 * @return string
		 *
		 * @throws \InvalidArgumentException When present and not scalar.
		 */
		private static function optional_string( array $data, string $field ): string {
			if ( ! array_key_exists( $field, $data ) || null === $data[ $field ] ) {
				return '';
			}

			if ( ! is_scalar( $data[ $field ] ) ) {
				throw new \InvalidArgumentException(
					sprintf( 'Location_Record::from_array(): "%s" must be a scalar.', $field )
				);
			}

			return (string) $data[ $field ];
		}

		/**
		 * Reads an optional numeric field (`lat`, `lon`). Absent or explicit `null`
		 * become `null`. Rejected, not coerced, when present and non-numeric — a
		 * malformed coordinate must never silently become `0.0` and mis-place a record.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $data  Raw payload.
		 * @param string               $field Field name.
		 *
		 * @return float|null
		 *
		 * @throws \InvalidArgumentException When present and not numeric.
		 */
		private static function optional_float( array $data, string $field ): ?float {
			if ( ! array_key_exists( $field, $data ) || null === $data[ $field ] ) {
				return null;
			}

			if ( ! is_numeric( $data[ $field ] ) ) {
				throw new \InvalidArgumentException(
					sprintf( 'Location_Record::from_array(): "%s" must be numeric.', $field )
				);
			}

			return (float) $data[ $field ];
		}

		/**
		 * Gets the namespaced primary key (`provider_id:native_id`).
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function key(): string {
			return $this->data['key'];
		}

		/**
		 * Gets the owning provider's id.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function provider_id(): string {
			return $this->data['provider_id'];
		}

		/**
		 * Gets the level (one of {@see self::LEVELS}).
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function level(): string {
			return $this->data['level'];
		}

		/**
		 * Gets the ISO-3166 alpha-2 country code (upper-case).
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function country(): string {
			return $this->data['country'];
		}

		/**
		 * Gets the region component group, or null when not supplied.
		 *
		 * @since 2.0.2
		 *
		 * @return array{name: string, type: string}|null
		 */
		public function region(): ?array {
			return $this->data['region'];
		}

		/**
		 * Gets the district component group, or null when not supplied.
		 *
		 * @since 2.0.2
		 *
		 * @return array{name: string, type: string}|null
		 */
		public function district(): ?array {
			return $this->data['district'];
		}

		/**
		 * Gets the settlement component group, or null when not supplied.
		 *
		 * @since 2.0.2
		 *
		 * @return array{name: string, type: string}|null
		 */
		public function settlement(): ?array {
			return $this->data['settlement'];
		}

		/**
		 * Gets the street component group, or null when not supplied.
		 *
		 * @since 2.0.2
		 *
		 * @return array{name: string, type: string}|null
		 */
		public function street(): ?array {
			return $this->data['street'];
		}

		/**
		 * Gets the house number, or an empty string.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function house(): string {
			return $this->data['house'];
		}

		/**
		 * Gets the block/building qualifier, or an empty string.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function block(): string {
			return $this->data['block'];
		}

		/**
		 * Gets the flat/apartment number, or an empty string.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function flat(): string {
			return $this->data['flat'];
		}

		/**
		 * Gets the postcode. Derived, write-only at the checkout layer (spec D13) — this
		 * accessor only reflects whatever the provider supplied.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function postcode(): string {
			return $this->data['postcode'];
		}

		/**
		 * Gets the latitude, or null when not supplied.
		 *
		 * @since 2.0.2
		 *
		 * @return float|null
		 */
		public function lat(): ?float {
			return $this->data['lat'];
		}

		/**
		 * Gets the longitude, or null when not supplied.
		 *
		 * @since 2.0.2
		 *
		 * @return float|null
		 */
		public function lon(): ?float {
			return $this->data['lon'];
		}

		/**
		 * Gets the display label, or an empty string.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function label(): string {
			return $this->data['label'];
		}

		/**
		 * Gets the opaque provider payload, exactly as supplied. Never inspected by the
		 * framework (spec D12) — an adapter is the only consumer allowed to read it.
		 *
		 * @since 2.0.2
		 *
		 * @return mixed
		 */
		public function raw() {
			return $this->data['raw'];
		}

		/**
		 * Returns the canonical array representation. `Location_Record::from_array( $r->to_array() )`
		 * round-trips to an equal record — this is what gets persisted into the customer
		 * location store, sent to an adapter, and stored in the pickup
		 * `[location][type]` map (spec §4.2: "the record travels whole everywhere").
		 *
		 * @since 2.0.2
		 *
		 * @return array<string, mixed>
		 */
		public function to_array(): array {
			return $this->data;
		}
	}

endif;
