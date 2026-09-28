<?php
/**
 * Shipping orders — rate calculator for an admin-built package
 *
 * @since 2.0.2
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Admin\Orders;

use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Pickup\Constraint_Checker;
use Woodev\Framework\Shipping\Shipping_Method;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Admin\\Orders\\Admin_Rate_Calculator' ) ) :

	/**
	 * Prices a package the admin order wizard built by hand and answers the carriers' rates for it,
	 * grouped by {@see Orders_Provider} (#710 spec D2, card #965).
	 *
	 * Nothing here belongs to a shopper: there is no cart, no session and no customer object in an
	 * admin REST request (all three are null). So this does NOT use
	 * `WC()->shipping()->calculate_shipping_for_package()`, which fatals without a session
	 * (measured, #962 §4); it resolves the zone-instance methods for the destination itself and asks
	 * each one of OUR methods through {@see Shipping_Method::get_admin_rates_for_package()} — the seam
	 * that lifts the REST/admin veto for one call — which keeps the rates' meta intact.
	 *
	 * The four mines of the spec, and where each is defused:
	 *
	 * - Mine 1 (veto) — the seam on `Shipping_Method`, per instance and per call.
	 * - Mine 1b (zone, session, state codes) — {@see self::resolve_methods()} goes through
	 *   `WC_Shipping_Zones::get_zone_matching_package()`; {@see self::resolve_state_code()} maps the
	 *   record's region to the WooCommerce state code the checkout would have posted.
	 * - Mine 2 (per-package session cache) — never reached, see above; the package also carries a
	 *   marker key so a package-hash-keyed carrier cache cannot collide with a shopper's package.
	 * - Mine 3 (destination read from the customer store) — the record travels into the call
	 *   ({@see \Woodev\Framework\Shipping\Location\Location_Service::with_explicit_record()}).
	 *
	 * @since 2.0.2
	 */
	class Admin_Rate_Calculator {

		/**
		 * Key the package carries so it is never the same package (or the same hash) as a shopper's.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		public const PACKAGE_MARKER = 'woodev_admin_calculator';

		/**
		 * Registry to read the providers from.
		 *
		 * @since 2.0.2
		 *
		 * @var Orders_Registry
		 */
		private $registry;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param Orders_Registry $registry orders registry.
		 */
		public function __construct( Orders_Registry $registry ) {
			$this->registry = $registry;
		}

		/**
		 * Normalizes the wizard's destination into the shape of a WooCommerce package destination.
		 *
		 * The location record fills whatever the manager did not type (country, region, settlement,
		 * postcode). The state is always a WooCommerce STATE CODE afterwards, never a label — see
		 * {@see self::resolve_state_code()}.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $destination `country`, `state`, `city`, `postcode`, `address`, `address_2`.
		 * @param Location_Record|null $record      Destination location record, when the wizard has one.
		 *
		 * @return array{country: string, state: string, postcode: string, city: string, address: string, address_1: string, address_2: string}
		 */
		public function normalize_destination( array $destination, ?Location_Record $record = null ): array {

			$text = static function ( array $source, string $key ): string {
				return isset( $source[ $key ] ) && is_scalar( $source[ $key ] ) ? trim( (string) $source[ $key ] ) : '';
			};

			$country = strtoupper( $text( $destination, 'country' ) );

			if ( '' === $country && null !== $record ) {
				$country = strtoupper( $record->country() );
			}

			$state_input = $text( $destination, 'state' );

			if ( '' === $state_input && null !== $record && null !== $record->region() ) {
				$state_input = (string) $record->region()['name'];
			}

			$city = $text( $destination, 'city' );

			if ( '' === $city && null !== $record && null !== $record->settlement() ) {
				$city = (string) $record->settlement()['name'];
			}

			$postcode = $text( $destination, 'postcode' );

			if ( '' === $postcode && null !== $record ) {
				$postcode = $record->postcode();
			}

			if ( '' !== $postcode ) {
				$postcode = $this->format_postcode( $postcode, $country );
			}

			$address = $text( $destination, 'address' );

			return [
				'country'   => $country,
				'state'     => $this->resolve_state_code( $country, $state_input ),
				'postcode'  => $postcode,
				'city'      => $city,
				'address'   => $address,
				'address_1' => $address,
				'address_2' => $text( $destination, 'address_2' ),
			];
		}

		/**
		 * Maps what a location record (or a manager) calls a region to the WooCommerce state code.
		 *
		 * A record carries a label («Москва»); a WooCommerce package and its zones carry a CODE. For
		 * Russia the code is the upper-cased region name («МОСКВА»), elsewhere it is short («CA»), so
		 * neither can be derived — the value is looked up in the country's own state list, by code
		 * first and then by label, case-insensitively. Measured on the rig (#962): the classic
		 * checkout accepts `МОСКВА` and refuses `MOW`, and a zone restricted by state never matches a
		 * label.
		 *
		 * A country WooCommerce keeps no state list for takes the value as typed (its checkout takes
		 * free text there); a country WITH a list and a value not in it yields `''` — a zone that
		 * restricts by state then does not match, rather than matching on a made-up code.
		 *
		 * @since 2.0.2
		 *
		 * @param string $country ISO-3166 alpha-2 country code.
		 * @param string $value   State code or label.
		 *
		 * @return string
		 */
		public function resolve_state_code( string $country, string $value ): string {

			$value = trim( $value );

			if ( '' === $value || '' === $country ) {
				return $value;
			}

			$states = $this->get_country_states( $country );

			if ( [] === $states ) {
				return $value;
			}

			if ( isset( $states[ $value ] ) ) {
				return $value;
			}

			$lowered = mb_strtolower( $value );

			foreach ( $states as $code => $label ) {
				if ( mb_strtolower( (string) $code ) === $lowered || mb_strtolower( (string) $label ) === $lowered ) {
					return (string) $code;
				}
			}

			return '';
		}

		/**
		 * The state list WooCommerce keeps for `$country`: code => label, empty when it keeps none.
		 *
		 * Protected so a unit test can stand in for `WC()->countries` without defining a global
		 * `WC()` function that would outlive it.
		 *
		 * @since 2.0.2
		 *
		 * @param string $country ISO-3166 alpha-2 country code.
		 *
		 * @return array<string, string>
		 */
		protected function get_country_states( string $country ): array {

			if ( ! function_exists( 'WC' ) || ! is_object( WC()->countries ) ) {
				return [];
			}

			$states = WC()->countries->get_states( $country );

			return is_array( $states ) ? $states : [];
		}

		/**
		 * Formats a postcode the way WooCommerce does for `$country`.
		 *
		 * Protected for the same reason as {@see self::get_country_states()}.
		 *
		 * @since 2.0.2
		 *
		 * @param string $postcode Postcode as typed.
		 * @param string $country  ISO-3166 alpha-2 country code.
		 *
		 * @return string
		 */
		protected function format_postcode( string $postcode, string $country ): string {

			return function_exists( 'wc_format_postcode' ) ? (string) wc_format_postcode( $postcode, $country ) : $postcode;
		}

		/**
		 * Builds the WooCommerce shipping package for the wizard's lines.
		 *
		 * `contents` mirrors a cart's shape — carrier code reads `data` (the product), `quantity`
		 * and the `line_*` totals off it — at the EDITED price. Lines whose product needs no
		 * shipping are left out, as `WC_Cart` leaves them out of a package.
		 *
		 * @since 2.0.2
		 *
		 * @param array<int, array{product: \WC_Product, quantity: int, price: float|null}> $lines       Wizard lines; `price` is the per-unit price override, `null` keeps the product's own.
		 * @param array<string, string>                                                     $destination A {@see self::normalize_destination()} result.
		 * @param int                                                                       $customer_id Customer user id, 0 for a guest.
		 *
		 * @return array<string, mixed> Package; `contents` is empty when nothing needs shipping.
		 */
		public function build_package( array $lines, array $destination, int $customer_id = 0 ): array {

			$contents = [];
			$cost     = 0.0;

			foreach ( array_values( $lines ) as $index => $line ) {

				$product  = $line['product'];
				$quantity = max( 1, (int) $line['quantity'] );

				if ( ! $product->needs_shipping() ) {
					continue;
				}

				$unit_price = null !== $line['price'] ? (float) $line['price'] : (float) $product->get_price();
				$line_total = $unit_price * $quantity;
				$is_variant = $product instanceof \WC_Product_Variation;
				$key        = md5( $product->get_id() . ':' . $index );

				$contents[ $key ] = [
					'key'               => $key,
					'product_id'        => $is_variant ? $product->get_parent_id() : $product->get_id(),
					'variation_id'      => $is_variant ? $product->get_id() : 0,
					'variation'         => $is_variant ? (array) $product->get_variation_attributes() : [],
					'quantity'          => $quantity,
					'data'              => $product,
					'line_tax_data'     => [
						'subtotal' => [],
						'total'    => [],
					],
					'line_subtotal'     => $line_total,
					'line_subtotal_tax' => 0,
					'line_total'        => $line_total,
					'line_tax'          => 0,
				];

				$cost += $line_total;
			}

			return [
				'contents'              => $contents,
				'contents_cost'         => $cost,
				'cart_subtotal'         => $cost,
				'applied_coupons'       => [],
				'user'                  => [ 'ID' => $customer_id ],
				'destination'           => $destination,
				self::PACKAGE_MARKER    => true,
			];
		}

		/**
		 * Prices the wizard's lines for a destination.
		 *
		 * @since 2.0.2
		 *
		 * @param array<int, array{product: \WC_Product, quantity: int, price: float|null}> $lines       Wizard lines, see {@see self::build_package()}.
		 * @param array<string, string>                                                     $destination A {@see self::normalize_destination()} result.
		 * @param Location_Record|null                                                      $record      Destination location record, or `null`.
		 * @param int                                                                       $customer_id Customer user id, 0 for a guest.
		 *
		 * @return array{
		 *     destination: array<string, string>,
		 *     needs_shipping: bool,
		 *     weight: int,
		 *     zone: array{id: int, name: string}|null,
		 *     providers: array<int, array{id: string, label: string, rates: array<int, array<string, mixed>>}>
		 * } Every registered provider appears, with an empty `rates` list when it offers nothing for
		 *   this package — the wizard can then say so instead of silently dropping a carrier.
		 */
		public function calculate( array $lines, array $destination, ?Location_Record $record = null, int $customer_id = 0 ): array {

			$package   = $this->build_package( $lines, $destination, $customer_id );
			$providers = $this->registry->get_providers();
			$zone      = null;
			$methods   = [];

			if ( [] !== $package['contents'] ) {
				list( $zone, $methods ) = $this->resolve_methods( $package );
			}

			$groups = [];

			foreach ( $providers as $provider ) {

				$rates = [];

				foreach ( $methods as $method ) {

					if ( ! in_array( $method->id, $provider->get_method_ids(), true ) ) {
						continue;
					}

					// The carrier's own order fields (D7) are declared per tariff: asked once per method
					// instance, and every rate it yields carries them.
					$order_fields = Carrier_Field_Set::for_rate( $provider, (string) $method->id, (int) ( $method->instance_id ?? 0 ), $method )->to_schema();

					foreach ( $method->get_admin_rates_for_package( $package, $record ) as $rate ) {
						$rates[] = $this->format_rate( $rate, $method, $order_fields );
					}
				}

				$groups[] = [
					'id'    => $provider->get_id(),
					'label' => $provider->get_label(),
					'rates' => $rates,
				];
			}

			return [
				'destination'    => $destination,
				'needs_shipping' => [] !== $package['contents'],
				'weight'         => $this->package_weight_grams( $package ),
				'zone'           => null === $zone ? null : [
					'id'   => (int) $zone->get_id(),
					'name' => (string) $zone->get_zone_name(),
				],
				'providers'      => $groups,
			];
		}

		/**
		 * The package's weight in GRAMS — what the wizard hands the admin pickup-points routes as
		 * their explicit `weight`, the same unit and the same conversion authority
		 * ({@see Constraint_Checker::to_grams()}) the storefront's cart weight goes through, so
		 * a point's weight limit gives the same verdict in both places.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $package a {@see self::build_package()} result.
		 *
		 * @return int grams, 0 when nothing in it has a weight.
		 */
		private function package_weight_grams( array $package ): int {

			$weight = 0.0;

			foreach ( (array) $package['contents'] as $line ) {
				$weight += (float) $line['data']->get_weight() * max( 1, (int) $line['quantity'] );
			}

			return $weight > 0 ? Constraint_Checker::to_grams( $weight ) : 0;
		}

		/**
		 * Resolves the enabled framework methods of the zone the package's destination falls into.
		 *
		 * `WC_Shipping_Zones::get_zone_matching_package()` → `get_shipping_methods( true )` is what
		 * WooCommerce's own aggregate does first, and it needs neither session nor cart. What WOULD
		 * be offered depends on the zone: the instance ids differ per zone and a zone can omit a
		 * provider's pickup method (#962 table 4).
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $package WooCommerce package.
		 *
		 * @return array{0: \WC_Shipping_Zone, 1: Shipping_Method[]}
		 */
		protected function resolve_methods( array $package ): array {

			$zone    = \WC_Shipping_Zones::get_zone_matching_package( $package );
			$methods = [];

			foreach ( $zone->get_shipping_methods( true ) as $method ) {
				if ( $method instanceof Shipping_Method ) {
					$methods[] = $method;
				}
			}

			return [ $zone, $methods ];
		}

		/**
		 * Shapes one WooCommerce rate for the wizard.
		 *
		 * `meta` is the rate's meta as WooCommerce holds it: the order-create route copies it onto
		 * the shipping item, exactly as `WC_Checkout` does.
		 *
		 * `order_fields` are the carrier's own fields for this tariff ({@see Carrier_Field_Set::to_schema()}) —
		 * empty for a carrier that declares none; the wizard renders them under the chosen tariff (spec D7).
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Shipping_Rate                $rate         The rate.
		 * @param Shipping_Method                  $method       The method that produced it.
		 * @param array<int, array<string, mixed>> $order_fields The carrier's order-field schema for this tariff.
		 *
		 * @return array<string, mixed>
		 */
		private function format_rate( \WC_Shipping_Rate $rate, Shipping_Method $method, array $order_fields = [] ): array {

			return [
				'id'            => (string) $rate->get_id(),
				'method_id'     => (string) $rate->get_method_id(),
				'instance_id'   => (int) $rate->get_instance_id(),
				'label'         => (string) $rate->get_label(),
				'cost'          => (float) $rate->get_cost(),
				'delivery_time' => method_exists( $rate, 'get_delivery_time' ) ? (string) $rate->get_delivery_time() : '',
				'description'   => method_exists( $rate, 'get_description' ) ? (string) $rate->get_description() : '',
				'is_pickup'     => $method->is_pickup_shipping(),
				'meta'          => (array) $rate->get_meta_data(),
				'order_fields'  => $order_fields,
			];
		}
	}

endif;
