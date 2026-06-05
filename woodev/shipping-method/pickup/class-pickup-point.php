<?php
/**
 * Woodev Pickup Point Value Object
 *
 * Immutable, WooCommerce-free value object describing a single pickup point
 * (PVZ — пункт выдачи заказов) returned by a carrier. It carries a fixed core
 * schema common to every carrier plus a `raw` escape hatch (decision §6b) that
 * preserves the original provider payload so carrier-specific fields are never
 * lost in the abstraction.
 *
 * Pure PHP — no WooCommerce calls. See
 * docs-internal/platform-v2-s1-shipping-spec.md §4.1.i.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping\Pickup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Pickup\\Pickup_Point' ) ) :

	/**
	 * Carrier pickup point.
	 *
	 * Constructed once and never mutated; all state is exposed through typed
	 * getters. Build from a carrier array with {@see Pickup_Point::from_array()}
	 * and serialize back with {@see Pickup_Point::to_array()} — the two are exact
	 * inverses, including the `raw` escape hatch.
	 *
	 * @since 1.5.0
	 */
	class Pickup_Point implements \JsonSerializable {

		/** @var string carrier-unique pickup point code */
		private string $code;

		/** @var string pickup point type (e.g. 'pvz', 'postamat') */
		private string $type;

		/** @var string human-readable name */
		private string $name;

		/** @var string full one-line postal address */
		private string $address_full;

		/** @var array structured address parts keyed by component */
		private array $address;

		/** @var float latitude in decimal degrees */
		private float $lat;

		/** @var float longitude in decimal degrees */
		private float $lng;

		/** @var array working-hours descriptors */
		private array $work_hours;

		/** @var array accepted payment methods */
		private array $payment_methods;

		/** @var float maximum accepted parcel weight */
		private float $max_weight;

		/** @var string maximum accepted parcel dimensions (carrier-defined, e.g. '120x60x60') */
		private string $max_dimensions;

		/** @var string contact phone */
		private string $phone;

		/** @var array original carrier payload — escape hatch for carrier-specific fields */
		private array $raw;

		/**
		 * Constructor.
		 *
		 * @since 1.5.0
		 *
		 * @param string $code            carrier-unique pickup point code
		 * @param string $type            pickup point type
		 * @param string $name            human-readable name
		 * @param string $address_full    full one-line postal address
		 * @param array  $address         structured address parts
		 * @param float  $lat             latitude in decimal degrees
		 * @param float  $lng             longitude in decimal degrees
		 * @param array  $work_hours      working-hours descriptors
		 * @param array  $payment_methods accepted payment methods
		 * @param float  $max_weight      maximum accepted parcel weight
		 * @param string $max_dimensions  maximum accepted parcel dimensions
		 * @param string $phone           contact phone
		 * @param array  $raw             original carrier payload
		 */
		public function __construct(
			string $code = '',
			string $type = '',
			string $name = '',
			string $address_full = '',
			array $address = [],
			float $lat = 0.0,
			float $lng = 0.0,
			array $work_hours = [],
			array $payment_methods = [],
			float $max_weight = 0.0,
			string $max_dimensions = '',
			string $phone = '',
			array $raw = []
		) {
			$this->code            = $code;
			$this->type            = $type;
			$this->name            = $name;
			$this->address_full    = $address_full;
			$this->address         = $address;
			$this->lat             = $lat;
			$this->lng             = $lng;
			$this->work_hours      = $work_hours;
			$this->payment_methods = $payment_methods;
			$this->max_weight      = $max_weight;
			$this->max_dimensions  = $max_dimensions;
			$this->phone           = $phone;
			$this->raw             = $raw;
		}

		/**
		 * Builds a pickup point from a carrier array.
		 *
		 * Unknown keys are ignored by the core schema but should be preserved by
		 * the caller under the `raw` key; values are cast to the declared types so
		 * the resulting object is always well-formed.
		 *
		 * @since 1.5.0
		 *
		 * @param array $data carrier data keyed by core-schema field name
		 *
		 * @return self
		 */
		public static function from_array( array $data ): self {
			return new self(
				(string) ( $data['code'] ?? '' ),
				(string) ( $data['type'] ?? '' ),
				(string) ( $data['name'] ?? '' ),
				(string) ( $data['address_full'] ?? '' ),
				(array) ( $data['address'] ?? [] ),
				(float) ( $data['lat'] ?? 0.0 ),
				(float) ( $data['lng'] ?? 0.0 ),
				(array) ( $data['work_hours'] ?? [] ),
				(array) ( $data['payment_methods'] ?? [] ),
				(float) ( $data['max_weight'] ?? 0.0 ),
				(string) ( $data['max_dimensions'] ?? '' ),
				(string) ( $data['phone'] ?? '' ),
				(array) ( $data['raw'] ?? [] )
			);
		}

		/**
		 * Exports the pickup point as a plain array.
		 *
		 * The exact inverse of {@see Pickup_Point::from_array()}: feeding the
		 * result back reproduces an identical object, `raw` escape hatch included.
		 *
		 * @since 1.5.0
		 *
		 * @return array canonical representation keyed by core-schema field name
		 */
		public function to_array(): array {
			return [
				'code'            => $this->code,
				'type'            => $this->type,
				'name'            => $this->name,
				'address_full'    => $this->address_full,
				'address'         => $this->address,
				'lat'             => $this->lat,
				'lng'             => $this->lng,
				'work_hours'      => $this->work_hours,
				'payment_methods' => $this->payment_methods,
				'max_weight'      => $this->max_weight,
				'max_dimensions'  => $this->max_dimensions,
				'phone'           => $this->phone,
				'raw'             => $this->raw,
			];
		}

		/**
		 * Specifies data for JSON serialization.
		 *
		 * @since 1.5.0
		 *
		 * @return array the canonical representation
		 */
		public function jsonSerialize(): array {
			return $this->to_array();
		}

		/**
		 * Gets the carrier-unique pickup point code.
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		public function get_code(): string {
			return $this->code;
		}

		/**
		 * Gets the pickup point type.
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		public function get_type(): string {
			return $this->type;
		}

		/**
		 * Gets the human-readable name.
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		public function get_name(): string {
			return $this->name;
		}

		/**
		 * Gets the full one-line postal address.
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		public function get_address_full(): string {
			return $this->address_full;
		}

		/**
		 * Gets the structured address parts.
		 *
		 * @since 1.5.0
		 *
		 * @return array
		 */
		public function get_address(): array {
			return $this->address;
		}

		/**
		 * Gets the latitude in decimal degrees.
		 *
		 * @since 1.5.0
		 *
		 * @return float
		 */
		public function get_lat(): float {
			return $this->lat;
		}

		/**
		 * Gets the longitude in decimal degrees.
		 *
		 * @since 1.5.0
		 *
		 * @return float
		 */
		public function get_lng(): float {
			return $this->lng;
		}

		/**
		 * Gets the working-hours descriptors.
		 *
		 * @since 1.5.0
		 *
		 * @return array
		 */
		public function get_work_hours(): array {
			return $this->work_hours;
		}

		/**
		 * Gets the accepted payment methods.
		 *
		 * @since 1.5.0
		 *
		 * @return array
		 */
		public function get_payment_methods(): array {
			return $this->payment_methods;
		}

		/**
		 * Gets the maximum accepted parcel weight.
		 *
		 * @since 1.5.0
		 *
		 * @return float
		 */
		public function get_max_weight(): float {
			return $this->max_weight;
		}

		/**
		 * Gets the maximum accepted parcel dimensions.
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		public function get_max_dimensions(): string {
			return $this->max_dimensions;
		}

		/**
		 * Gets the contact phone.
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		public function get_phone(): string {
			return $this->phone;
		}

		/**
		 * Gets the original carrier payload.
		 *
		 * The escape hatch (decision §6b) holding carrier-specific fields outside
		 * the core schema.
		 *
		 * @since 1.5.0
		 *
		 * @return array
		 */
		public function get_raw(): array {
			return $this->raw;
		}
	}

endif;
