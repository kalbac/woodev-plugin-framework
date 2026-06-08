<?php
/**
 * Woodev Warehouse Value Object
 *
 * Immutable, WooCommerce-free value object describing a shipment origin
 * warehouse (the point a parcel is dispatched from). It carries a fixed core
 * schema plus a `raw` escape hatch that preserves the original provider payload
 * so carrier-specific fields are never lost in the abstraction.
 *
 * Pure PHP — no WooCommerce calls. See
 * docs-internal/platform-v2-s1-shipping-spec.md §4.1.iii.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping\Pickup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Pickup\\Warehouse' ) ) :

	/**
	 * Shipment origin warehouse.
	 *
	 * Constructed once and never mutated; all state is exposed through typed
	 * getters. Build from a carrier array with {@see Warehouse::from_array()} and
	 * serialize back with {@see Warehouse::to_array()} — the two are exact
	 * inverses, including the `raw` escape hatch.
	 *
	 * @since 1.5.0
	 */
	class Warehouse {

		/** @var string carrier-unique warehouse id */
		private string $id;

		/** @var string human-readable name */
		private string $name;

		/** @var string full one-line postal address */
		private string $address;

		/** @var float latitude in decimal degrees */
		private float $lat;

		/** @var float longitude in decimal degrees */
		private float $lng;

		/** @var string contact person name */
		private string $contact_name;

		/** @var string contact phone */
		private string $contact_phone;

		/** @var string contact email */
		private string $contact_email;

		/** @var array working-hours descriptors */
		private array $work_hours;

		/** @var array original carrier payload — escape hatch for carrier-specific fields */
		private array $raw;

		/**
		 * Constructor.
		 *
		 * @since 1.5.0
		 *
		 * @param string $id            carrier-unique warehouse id
		 * @param string $name          human-readable name
		 * @param string $address       full one-line postal address
		 * @param float  $lat           latitude in decimal degrees
		 * @param float  $lng           longitude in decimal degrees
		 * @param string $contact_name  contact person name
		 * @param string $contact_phone contact phone
		 * @param string $contact_email contact email
		 * @param array  $work_hours    working-hours descriptors
		 * @param array  $raw           original carrier payload
		 */
		public function __construct(
			string $id = '',
			string $name = '',
			string $address = '',
			float $lat = 0.0,
			float $lng = 0.0,
			string $contact_name = '',
			string $contact_phone = '',
			string $contact_email = '',
			array $work_hours = [],
			array $raw = []
		) {
			$this->id            = $id;
			$this->name          = $name;
			$this->address       = $address;
			$this->lat           = $lat;
			$this->lng           = $lng;
			$this->contact_name  = $contact_name;
			$this->contact_phone = $contact_phone;
			$this->contact_email = $contact_email;
			$this->work_hours    = $work_hours;
			$this->raw           = $raw;
		}

		/**
		 * Builds a warehouse from a carrier array.
		 *
		 * Values are cast to the declared types so the resulting object is always
		 * well-formed; the `raw` key preserves the untouched provider payload.
		 *
		 * @since 1.5.0
		 *
		 * @param array $data carrier data keyed by core-schema field name
		 *
		 * @return self
		 */
		public static function from_array( array $data ): self {
			return new self(
				(string) ( $data['id'] ?? '' ),
				(string) ( $data['name'] ?? '' ),
				(string) ( $data['address'] ?? '' ),
				(float) ( $data['lat'] ?? 0.0 ),
				(float) ( $data['lng'] ?? 0.0 ),
				(string) ( $data['contact_name'] ?? '' ),
				(string) ( $data['contact_phone'] ?? '' ),
				(string) ( $data['contact_email'] ?? '' ),
				(array) ( $data['work_hours'] ?? [] ),
				(array) ( $data['raw'] ?? [] )
			);
		}

		/**
		 * Exports the warehouse as a plain array.
		 *
		 * The exact inverse of {@see Warehouse::from_array()}: feeding the result
		 * back reproduces an identical object, `raw` escape hatch included.
		 *
		 * @since 1.5.0
		 *
		 * @return array canonical representation keyed by core-schema field name
		 */
		public function to_array(): array {
			return [
				'id'            => $this->id,
				'name'          => $this->name,
				'address'       => $this->address,
				'lat'           => $this->lat,
				'lng'           => $this->lng,
				'contact_name'  => $this->contact_name,
				'contact_phone' => $this->contact_phone,
				'contact_email' => $this->contact_email,
				'work_hours'    => $this->work_hours,
				'raw'           => $this->raw,
			];
		}

		/**
		 * Gets the carrier-unique warehouse id.
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		public function get_id(): string {
			return $this->id;
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
		public function get_address(): string {
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
		 * Gets the contact person name.
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		public function get_contact_name(): string {
			return $this->contact_name;
		}

		/**
		 * Gets the contact phone.
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		public function get_contact_phone(): string {
			return $this->contact_phone;
		}

		/**
		 * Gets the contact email.
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		public function get_contact_email(): string {
			return $this->contact_email;
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
		 * Gets the original carrier payload.
		 *
		 * The escape hatch holding carrier-specific fields outside the core schema.
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
