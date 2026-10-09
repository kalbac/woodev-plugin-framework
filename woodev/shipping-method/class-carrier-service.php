<?php
/**
 * An additional service a carrier offers on top of the delivery itself (#1145).
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\Carrier_Service' ) ) :

	/**
	 * One entry of the list a carrier declares through {@see Shipping_Method::declare_services()}.
	 *
	 * The carrier owns the list: the code is the carrier's own (CDEK's `INSURANCE`, `CARTON_BOX_1`, …), the
	 * name is what the merchant reads in the method's settings (Russian — it is an admin string). The framework
	 * owns the rest: the instance option that picks services, the resolver both the rate request and the order
	 * export call, and the rate-cache identity.
	 *
	 * **The parameter.** Some services carry a value — the sum an insurance covers, the number of boxes. A
	 * service that has one names it (`parameter`, the carrier's own field name, informational) and says where
	 * the value comes from (`parameter_source`):
	 *
	 * - {@see self::SOURCE_DECLARED_VALUE} — the goods value of the package or order lines
	 *   ({@see Shipping_Helper::get_package_declared_value()}), the same number insurance uses;
	 * - {@see self::SOURCE_CUSTOM} — the carrier computes it in {@see Shipping_Method::resolve_service_parameter()}.
	 *
	 * **Selectable or automatic.** A selectable service (the default) is offered in the method's settings and
	 * goes into the quote only when the merchant ticked it. An automatic one (`selectable` false) is never
	 * offered: it is added whenever its computed parameter is not `null` — the way CDEK's boxes travel as
	 * `CARTON_BOX_*` services whose parameter is the number of boxes of that kind. An automatic service
	 * therefore needs a parameter source of its own; without a parameter nothing could say when it applies.
	 *
	 * Immutable.
	 *
	 * @since 2.0.2
	 */
	final class Carrier_Service {

		/** The parameter is the goods value of the package / order lines, in store currency. */
		public const SOURCE_DECLARED_VALUE = 'declared_value';

		/** The carrier computes the parameter in {@see Shipping_Method::resolve_service_parameter()}. */
		public const SOURCE_CUSTOM = 'custom';

		/** @var string */
		private string $code;

		/** @var string */
		private string $name;

		/** @var string */
		private string $description;

		/** @var string|null */
		private ?string $parameter;

		/** @var string */
		private string $parameter_source;

		/** @var bool */
		private bool $selectable;

		/**
		 * Builds a service.
		 *
		 * @since 2.0.2
		 *
		 * @param string      $code             the carrier's code of the service; trimmed, must not be empty.
		 * @param string      $name             what the merchant reads; falls back to the code when empty.
		 * @param string|null $parameter        the carrier's name of the service's value, or `null` for a service without one.
		 * @param string      $parameter_source one of the `SOURCE_*` constants; ignored when there is no parameter.
		 * @param string      $description      one line for the merchant, optional.
		 * @param bool        $selectable       `false` for an automatic service (see the class docblock).
		 *
		 * @throws \InvalidArgumentException When the code is empty or the source is unknown.
		 */
		public function __construct(
			string $code,
			string $name = '',
			?string $parameter = null,
			string $parameter_source = self::SOURCE_DECLARED_VALUE,
			string $description = '',
			bool $selectable = true
		) {

			$code = trim( $code );

			if ( '' === $code ) {
				throw new \InvalidArgumentException( 'A carrier service needs a non-empty code.' );
			}

			if ( ! in_array( $parameter_source, [ self::SOURCE_DECLARED_VALUE, self::SOURCE_CUSTOM ], true ) ) {
				throw new \InvalidArgumentException( sprintf( 'Unknown carrier service parameter source "%s".', $parameter_source ) );
			}

			$parameter = null === $parameter ? null : trim( $parameter );

			$this->code             = $code;
			$this->name             = '' === trim( $name ) ? $code : trim( $name );
			$this->description      = trim( $description );
			$this->parameter        = '' === $parameter ? null : $parameter;
			$this->parameter_source = $parameter_source;
			$this->selectable       = $selectable;
		}

		/**
		 * Builds a service from the array a carrier may declare instead of an object.
		 *
		 * Keys: `code` (required), `name`, `parameter`, `parameter_source`, `description`, `selectable`.
		 * Anything unusable gives `null` rather than an exception, so one bad entry cannot take down the
		 * method's settings screen.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $data the declaration.
		 * @return self|null
		 */
		public static function from_array( array $data ): ?self {

			$parameter = $data['parameter'] ?? null;

			try {
				return new self(
					is_string( $data['code'] ?? null ) ? $data['code'] : '',
					is_string( $data['name'] ?? null ) ? $data['name'] : '',
					is_string( $parameter ) ? $parameter : null,
					is_string( $data['parameter_source'] ?? null ) ? $data['parameter_source'] : self::SOURCE_DECLARED_VALUE,
					is_string( $data['description'] ?? null ) ? $data['description'] : '',
					! array_key_exists( 'selectable', $data ) || (bool) $data['selectable']
				);
			} catch ( \InvalidArgumentException $exception ) {
				return null;
			}
		}

		/**
		 * Turns whatever a carrier returned into the list the framework works with: objects keyed by code.
		 *
		 * Entries that are neither a {@see self::class} nor a usable array are dropped; the first entry of a
		 * code wins.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $declared the carrier's return value.
		 * @return array<string,self>
		 */
		public static function normalize_list( $declared ): array {

			$services = [];

			foreach ( is_array( $declared ) ? $declared : [] as $entry ) {

				$service = $entry instanceof self ? $entry : ( is_array( $entry ) ? self::from_array( $entry ) : null );

				if ( null !== $service && ! isset( $services[ $service->get_code() ] ) ) {
					$services[ $service->get_code() ] = $service;
				}
			}

			return $services;
		}

		/**
		 * @since 2.0.2
		 * @return string
		 */
		public function get_code(): string {
			return $this->code;
		}

		/**
		 * @since 2.0.2
		 * @return string
		 */
		public function get_name(): string {
			return $this->name;
		}

		/**
		 * @since 2.0.2
		 * @return string
		 */
		public function get_description(): string {
			return $this->description;
		}

		/**
		 * The carrier's name of the value, or `null` for a service without one.
		 *
		 * @since 2.0.2
		 * @return string|null
		 */
		public function get_parameter(): ?string {
			return $this->parameter;
		}

		/**
		 * @since 2.0.2
		 * @return bool
		 */
		public function has_parameter(): bool {
			return null !== $this->parameter;
		}

		/**
		 * @since 2.0.2
		 * @return string One of the `SOURCE_*` constants.
		 */
		public function get_parameter_source(): string {
			return $this->parameter_source;
		}

		/**
		 * Whether the merchant picks the service (`true`) or the carrier adds it by itself (`false`).
		 *
		 * @since 2.0.2
		 * @return bool
		 */
		public function is_selectable(): bool {
			return $this->selectable;
		}
	}

endif;
