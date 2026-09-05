<?php
/**
 * Woodev Shipping Rate
 *
 * Value Object representing a standardized shipping rate structure
 * compatible with WooCommerce's WC_Shipping_Method::add_rate() method.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Shipping_Rate' ) ) :

	/**
	 * Shipping Rate DTO
	 *
	 * Immutable value object that ensures shipping rates have the correct
	 * structure required by WooCommerce's add_rate() method.
	 *
	 * @since 1.5.0
	 */
	final class Shipping_Rate {

		/**
		 * WooCommerce rate attributes that `WC_Shipping_Method::add_rate()` accepts but
		 * this DTO does not model as properties of its own.
		 *
		 * `add_rate()` parses its argument array against eight defaults: `id`, `label`,
		 * `cost`, `taxes`, `calc_tax`, `meta_data`, `package` and `price_decimals`.
		 * The three named here are the ones left over; they travel in `$args` and are
		 * emitted by {@see self::to_array()} untouched.
		 *
		 * @since 2.0.2
		 * @var string[]
		 */
		const ADD_RATE_ARGS = [ 'taxes', 'calc_tax', 'price_decimals' ];

		/**
		 * Attributes WooCommerce exposes on `WC_Shipping_Rate` but does NOT accept
		 * through `add_rate()`.
		 *
		 * `add_rate()` builds the rate object and sets id, method id, instance id,
		 * label, cost, taxes and tax status on it — and nothing else. `description` and
		 * `delivery_time` (both on `WC_Shipping_Rate` since WooCommerce 9.2.0, both
		 * published per rate by the Store API and rendered on the block checkout) can
		 * therefore only be applied to the rate object AFTER `add_rate()` created it,
		 * which is what {@see Shipping_Method::apply_rate_attributes()} does. Emitting
		 * them into the `add_rate()` array would be silently discarded, so
		 * {@see self::to_array()} deliberately holds them back.
		 *
		 * @since 2.0.2
		 * @var string[]
		 */
		const POST_ADD_RATE_ATTRIBUTES = [ 'description', 'delivery_time' ];

		/**
		 * Identifier for the method
		 *
		 * @var string
		 */
		private string $method_id;

		/**
		 * Unique rate identifier
		 *
		 * @var string
		 */
		private string $id;

		/**
		 * Rate label displayed to customer
		 *
		 * @var string
		 */
		private string $label;

		/**
		 * Rate cost, exactly as it was supplied.
		 *
		 * A number is kept as a number on purpose — see {@see self::normalize_cost()}
		 * for what stringifying it would cost.
		 *
		 * @var string|int|float|array
		 */
		private $cost;

		/**
		 * Package flag (boolean) or package data (array)
		 *
		 * @var bool|array|null
		 */
		private $package;

		/**
		 * Additional rate metadata
		 *
		 * @var array
		 */
		private array $meta_data;

		/**
		 * Further WooCommerce rate attributes, keyed by name.
		 *
		 * Holds {@see self::ADD_RATE_ARGS}, {@see self::POST_ADD_RATE_ATTRIBUTES} and
		 * any key a third party added through the plugin's own rate filter — the whole
		 * lot reaches `add_rate()`, whose `woocommerce_shipping_method_add_rate_args`
		 * filter is where such a key gets consumed.
		 *
		 * @since 2.0.2
		 * @var array
		 */
		private array $args;

		/**
		 * Constructor
		 *
		 * ⚠ This runs on the SHIPPING CALCULATION path, with a customer waiting on a
		 * checkout, so it does not throw over ordinary shop data. An empty label and a
		 * numeric cost are what a merchant's own settings and a carrier's own arithmetic
		 * produce — they are degraded and reported under `WP_DEBUG`, never fatal. The two
		 * identifiers are a different matter: a rate without a method id or a rate id is
		 * a programming error WooCommerce cannot render at all, so those still throw.
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Only the identifiers throw; `label` and `cost` degrade and report
		 *              (#766). `$args` added for the WooCommerce rate attributes this DTO
		 *              does not model explicitly.
		 *
		 * @param string                 $method_id Shipping method ID
		 * @param string                 $id        Unique rate identifier
		 * @param string                 $label     Rate label for customer
		 * @param string|array|int|float $cost      Rate cost (scalar or per-item array)
		 * @param bool|array|null        $package   Package flag or data (optional)
		 * @param array                  $meta_data Additional metadata (optional)
		 * @param array                  $args      Further WooCommerce rate attributes (optional)
		 *
		 * @throws \InvalidArgumentException if the method id or the rate id is empty
		 */
		public function __construct(
			string $method_id,
			string $id,
			string $label,
			$cost = '0',
			$package = null,
			array $meta_data = [],
			array $args = []
		) {

			if ( empty( $method_id ) ) {
				throw new \InvalidArgumentException( 'Shipping method ID cannot be empty' );
			}

			if ( empty( $id ) ) {
				throw new \InvalidArgumentException( 'Rate ID cannot be empty' );
			}

			// Validate package type if provided.
			if ( null !== $package && ! is_bool( $package ) && ! is_array( $package ) ) {
				throw new \InvalidArgumentException( 'Rate package must be a boolean, array, or null' );
			}

			$this->method_id = $method_id;
			$this->id        = $id;
			$this->label     = $label;
			$this->cost      = self::normalize_cost( $cost, $id );
			$this->package   = $package;
			$this->meta_data = $meta_data;
			$this->args      = $args;

			if ( '' === trim( $label ) ) {
				self::report(
					sprintf(
						'Shipping rate "%s" was built with an empty label. WooCommerce drops a rate without one, so the method would vanish from the checkout with no other sign.',
						$id
					)
				);
			}
		}

		/**
		 * Brings a supplied cost into a shape `add_rate()` can price.
		 *
		 * A number is a legitimate cost — `array_sum()` returns one, and so does every
		 * arithmetic a carrier does — so it is passed through UNCHANGED. It is
		 * deliberately not stringified: `(string) $float` emits scientific notation
		 * outside a narrow range (`1.0E-8`, `1.0E+20`), and `wc_format_decimal()` only
		 * handles that correctly for a value that is still a float. Handed the string it
		 * takes the `! is_float()` branch, strips every character outside `[0-9.-]`, and
		 * turns `1.0E+20` into `1.02` — a six-figure delivery quoted at one rouble two
		 * kopecks, with nothing in any log. Measured against WooCommerce 11.0.1.
		 *
		 * `NAN` and `INF` are floats that carry no amount, and so is anything that is
		 * neither number, string nor array. Those degrade to `0` and are reported rather
		 * than cast, because casting either fatals or invents a price (gotcha
		 * `a-cast-is-not-a-degradation`); `(string) NAN` also raises a PHP 8 warning.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed  $cost the supplied cost
		 * @param string $id   the rate id, for the report
		 *
		 * @return string|int|float|array
		 */
		private static function normalize_cost( $cost, string $id ) {

			if ( is_array( $cost ) || is_string( $cost ) || is_int( $cost ) ) {
				return $cost;
			}

			if ( is_float( $cost ) && is_finite( $cost ) ) {
				return $cost;
			}

			self::report(
				sprintf(
					'Shipping rate "%s" was built with a cost of type %s, which carries no amount. Falling back to 0.',
					$id,
					is_float( $cost ) ? 'float ' . ( is_nan( $cost ) ? 'NAN' : 'INF' ) : gettype( $cost )
				)
			);

			return '0';
		}

		/**
		 * Reports a degraded rate to the developer without interrupting the customer.
		 *
		 * Uses `_doing_it_wrong()` — visible under `WP_DEBUG`, silent in production —
		 * which is how the rest of the framework reports a builder that did not hold up
		 * its end of a contract (#758/#759). Guarded by `function_exists()` so the DTO
		 * stays usable where WordPress has not been loaded.
		 *
		 * @since 2.0.2
		 *
		 * @param string $message what the builder got wrong
		 */
		private static function report( string $message ): void {

			if ( function_exists( '_doing_it_wrong' ) ) {
				_doing_it_wrong( __CLASS__ . '::__construct', esc_html( $message ), '2.0.2' );
			}
		}

		/**
		 * Retrieves the method ID.
		 *
		 * @return string The method ID.
		 */
		public function get_method_id(): string {
			return $this->method_id;
		}

		/**
		 * Gets the rate ID
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		public function get_id(): string {
			return $this->id;
		}

		/**
		 * Gets the rate label
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		public function get_label(): string {
			return $this->label;
		}

		/**
		 * Gets the rate cost
		 *
		 * @since 1.5.0
		 * @since 2.0.2 A numeric cost is returned as the number it was supplied as.
		 *
		 * @return string|int|float|array
		 */
		public function get_cost() {
			return $this->cost;
		}

		/**
		 * Gets the package data
		 *
		 * @since 1.5.0
		 *
		 * @return bool|array|null
		 */
		public function get_package() {
			return $this->package;
		}

		/**
		 * Gets the metadata
		 *
		 * @since 1.5.0
		 *
		 * @return array
		 */
		public function get_meta_data(): array {
			return $this->meta_data;
		}

		/**
		 * Gets the further WooCommerce rate attributes.
		 *
		 * @since 2.0.2
		 *
		 * @return array
		 */
		public function get_args(): array {
			return $this->args;
		}

		/**
		 * Gets one further WooCommerce rate attribute.
		 *
		 * @since 2.0.2
		 *
		 * @param string $key           the attribute name
		 * @param mixed  $default_value what to return when the rate does not carry it
		 *
		 * @return mixed
		 */
		public function get_arg( string $key, $default_value = null ) {
			return array_key_exists( $key, $this->args ) ? $this->args[ $key ] : $default_value;
		}

		/**
		 * Gets the attributes that must be applied AFTER `add_rate()` has built the
		 * `WC_Shipping_Rate`, because `add_rate()` itself ignores them.
		 *
		 * @since 2.0.2
		 *
		 * @see self::POST_ADD_RATE_ATTRIBUTES
		 *
		 * @return array the subset of the args keyed by attribute name
		 */
		public function get_post_add_rate_attributes(): array {
			return array_intersect_key( $this->args, array_flip( self::POST_ADD_RATE_ATTRIBUTES ) );
		}

		/**
		 * Converts the rate to array format for WC_Shipping_Method::add_rate()
		 *
		 * The meta is emitted FLAT — exactly the `key => value` pairs the rate was
		 * built with. `WC_Shipping_Rate::add_meta_data()` stores one order-item meta
		 * row per pair, so a flat array is what WooCommerce means by `meta_data`.
		 *
		 * ⚠ Do NOT re-introduce a wrapper keyed by `$this->method_id`. It was there
		 * until 2.0.2 and had zero consumers, while it made a migrating plugin unable
		 * to keep an existing flat meta key: `woocommerce-edostavka` writes
		 * `edostavka_rate` and reads it back off the shipping order item, which is a
		 * release-blocking installed-site contract (ADR-005). The wrapper is also
		 * redundant — the shipping order item already carries its own `method_id`, so
		 * naming the meta key after the method says nothing new — and on an empty
		 * meta array it produced one junk row instead of none.
		 *
		 * Every key in `$args` is emitted alongside the ones the DTO owns, so a rate can
		 * carry `taxes`/`calc_tax`/`price_decimals` and whatever a third party added
		 * through the plugin's own rate filter. Two rules hold that together: the owned
		 * keys are written last and so cannot be shadowed from `$args`, and the
		 * post-`add_rate()` attributes are held back, since `add_rate()` would discard
		 * them without a word.
		 *
		 * @since 1.5.0
		 * @since 2.0.2 Meta is emitted flat; the `method_id` wrapper is gone (#764).
		 * @since 2.0.2 The args travel alongside the owned keys (#766).
		 *
		 * @return array Rate data in WooCommerce format
		 */
		public function to_array(): array {

			$rate = array_diff_key( $this->args, array_flip( self::POST_ADD_RATE_ATTRIBUTES ) );

			$rate['id']        = $this->id;
			$rate['label']     = $this->label;
			$rate['cost']      = $this->cost;
			$rate['meta_data'] = $this->meta_data;

			// Only include package if it was explicitly set.
			if ( null !== $this->package ) {
				$rate['package'] = $this->package;
			}

			return $rate;
		}

		/**
		 * Converts the object to an array.
		 *
		 * @return array The object represented as an array.
		 */
		public function __toArray(): array {
			return $this->to_array();
		}

		/**
		 * Creates a rate with additional metadata
		 *
		 * Returns a new instance with merged metadata (immutability preserved).
		 *
		 * @since 1.5.0
		 *
		 * @param array $meta_data Additional metadata to merge
		 *
		 * @return Shipping_Rate New rate instance with merged metadata
		 */
		public function with_meta_data( array $meta_data ): Shipping_Rate {
			return new self(
				$this->method_id,
				$this->id,
				$this->label,
				$this->cost,
				$this->package,
				array_merge( $this->meta_data, $meta_data ),
				$this->args
			);
		}

		/**
		 * Creates a rate with a different cost
		 *
		 * Returns a new instance with updated cost (immutability preserved).
		 *
		 * @since 1.5.0
		 *
		 * @param string|array|int|float $cost New cost value
		 *
		 * @return Shipping_Rate New rate instance with updated cost
		 */
		public function with_cost( $cost ): Shipping_Rate {
			return new self(
				$this->method_id,
				$this->id,
				$this->label,
				$cost,
				$this->package,
				$this->meta_data,
				$this->args
			);
		}

		/**
		 * Creates a rate with a different label.
		 *
		 * The framework uses this itself to substitute the method's title for an empty
		 * label before handing the rate to WooCommerce, which would otherwise drop the
		 * rate and take the method off the checkout without a word.
		 *
		 * @since 2.0.2
		 *
		 * @param string $label New label
		 *
		 * @return Shipping_Rate New rate instance carrying the given label
		 */
		public function with_label( string $label ): Shipping_Rate {
			return new self(
				$this->method_id,
				$this->id,
				$label,
				$this->cost,
				$this->package,
				$this->meta_data,
				$this->args
			);
		}

		/**
		 * Creates a rate with further WooCommerce attributes merged in.
		 *
		 * @since 2.0.2
		 *
		 * @param array $args Attributes to merge; the supplied keys win
		 *
		 * @return Shipping_Rate New rate instance with the merged args
		 */
		public function with_args( array $args ): Shipping_Rate {
			return new self(
				$this->method_id,
				$this->id,
				$this->label,
				$this->cost,
				$this->package,
				$this->meta_data,
				array_merge( $this->args, $args )
			);
		}
	}

endif;
