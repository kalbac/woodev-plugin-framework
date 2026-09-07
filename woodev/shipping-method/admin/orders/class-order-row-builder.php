<?php
/**
 * Shipping orders — row builder
 *
 * @since 2.0.2
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Admin\Orders;

use Woodev\Framework\Shipping\Order\Delivery_Status;
use Woodev\Framework\Shipping\Shipping_Helper;
use Woodev\Framework\Shipping\Shipping_Method;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Admin\\Orders\\Order_Row_Builder' ) ) :

	/**
	 * Builds one «Заказы доставки» table row (SP-10 spec M1, D3, D4).
	 *
	 * DATA ONLY — no markup, no formatting a human would read as presentation.
	 * Formatting dates, building `<mark>` badges and linking are the page shell's job in
	 * increment 2b. M1 measured what a row needs across all three shipped plugins; this
	 * class assembles exactly that, computed once per row rather than three times over.
	 *
	 * A row is buildable even when `$provider` is null (an aggregate row whose carrier
	 * could not be resolved — should not happen given the scoped query, but this class
	 * never assumes it) or when any of a provider's optional fields are absent: `type`
	 * and `delivery_status` degrade to `unknown`, `tracking` degrades to null number/url,
	 * and the destination falls through to the formatted shipping/billing address.
	 *
	 * @since 2.0.2
	 */
	class Order_Row_Builder {

		/**
		 * Builds the full row for one order.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order            $order    matched order.
		 * @param Orders_Provider|null $provider the carrier this row belongs to, or null
		 *                                        when it could not be resolved.
		 * @return array<string,mixed>
		 */
		public function build( \WC_Order $order, ?Orders_Provider $provider ): array {
			$status  = $order->get_status();
			$created = $order->get_date_created();

			$row = [
				'id'              => $order->get_id(),
				'order_number'    => $order->get_order_number(),
				'edit_url'        => $order->get_edit_order_url(),
				'date_created'    => $created ? $created->date( \DATE_ATOM ) : null,
				'status'          => [
					'slug'  => $status,
					'label' => wc_get_order_status_name( $status ),
				],
				'carrier'         => null !== $provider
					? [
						'id'    => $provider->get_id(),
						'label' => $provider->get_label(),
					]
					: null,
				'customer'        => $this->build_customer( $order ),
				'payment'         => $this->build_payment( $order ),
				'shipping'        => $this->build_shipping( $order, $provider ),
				'type'            => $this->resolve_type( $order, $provider ),
				'tracking'        => $this->build_tracking( $order, $provider ),
				'delivery_status' => $this->resolve_delivery_status( $order, $provider ),
			];

			/**
			 * Filters the fully-built row before it is returned.
			 *
			 * @since 2.0.2
			 *
			 * @param array<string,mixed>  $row      built row.
			 * @param \WC_Order            $order    the order the row was built from.
			 * @param Orders_Provider|null $provider the matched carrier, or null.
			 */
			$filtered = apply_filters( 'woodev_shipping_orders_row', $row, $order, $provider );

			return is_array( $filtered ) ? $filtered : $row;
		}

		/**
		 * Builds the `customer` field group.
		 *
		 * M1: byte-for-byte identical in two shipped plugins, including the fallback to
		 * `display_name` when the billing name is empty.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order order.
		 * @return array<string,mixed>
		 */
		private function build_customer( \WC_Order $order ): array {
			$name    = $order->get_formatted_billing_full_name();
			$user_id = $order->get_customer_id();

			if ( '' === trim( (string) $name ) && $user_id > 0 ) {
				$user = get_user_by( 'id', $user_id );
				$name = $user ? ucwords( $user->display_name ) : '';
			}

			return [
				'name'          => $name,
				'email'         => $order->get_billing_email(),
				'phone'         => $order->get_billing_phone(),
				'user_id'       => $user_id,
				'user_edit_url' => $user_id > 0 ? get_edit_user_link( $user_id ) : null,
			];
		}

		/**
		 * Builds the `payment` field group.
		 *
		 * M1: byte-for-byte identical across the shipped plugins for the title+total
		 * pair. The reference's «Заказ может быть неоплаченым» hint is gated on
		 * `! is_exported() && needs_payment()` — `is_exported()` is a carrier concept
		 * this framework does not own yet, so only `needs_payment` is emitted; a
		 * carrier-specific hint is that carrier's own column (D3), not this one.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order order.
		 * @return array<string,mixed>
		 */
		private function build_payment( \WC_Order $order ): array {
			return [
				'method_title'    => $order->get_payment_method_title(),
				'formatted_total' => $order->get_formatted_order_total(),
				'needs_payment'   => $order->needs_payment(),
			];
		}

		/**
		 * Builds the `shipping` field group: method + total + destination (D3's one
		 * carrier-overridable seam).
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order            $order    order.
		 * @param Orders_Provider|null $provider matched carrier, or null.
		 * @return array<string,mixed>
		 */
		private function build_shipping( \WC_Order $order, ?Orders_Provider $provider ): array {
			$destination = $this->resolve_destination( $order, $provider );

			return [
				'method_title'      => $order->get_shipping_method(),
				'formatted_total'   => wc_price( $order->get_shipping_total() ),
				'destination_kind'  => $destination['kind'],
				'destination_text'  => $destination['text'],
			];
		}

		/**
		 * Resolves the destination: the chosen pickup point when the carrier declares a
		 * `pickup_point_meta_key` and the order has one populated, else the formatted
		 * shipping address, else the formatted billing address (D3).
		 *
		 * The pickup point is stored as a whole
		 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Point::to_array()} under that
		 * key (not a scalar) — its `address` entry is what this reads. Read directly
		 * rather than rehydrated through `Pickup_Point::from_array()`, which validates
		 * and throws: a display-only read of possibly legacy or partially-written data
		 * must never break the row it belongs to, let alone the whole list.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order            $order    order.
		 * @param Orders_Provider|null $provider matched carrier, or null.
		 * @return array{kind:string,text:string}
		 */
		private function resolve_destination( \WC_Order $order, ?Orders_Provider $provider ): array {
			$destination = null;

			if ( null !== $provider && null !== $provider->get_pickup_point_meta_key() ) {
				$stored = \Woodev_Order_Compatibility::get_order_meta( $order, $provider->get_pickup_point_meta_key() );

				if ( is_array( $stored ) && ! empty( $stored['address'] ) ) {
					$destination = [
						'kind' => 'pickup',
						'text' => (string) $stored['address'],
					];
				}
			}

			if ( null === $destination ) {
				$shipping_text = $this->format_address(
					$order->get_shipping_postcode(),
					$order->get_shipping_state(),
					$order->get_shipping_city(),
					$order->get_shipping_address_1()
				);

				$destination = [
					'kind' => 'address',
					'text' => '' !== $shipping_text
						? $shipping_text
						: $this->format_address(
							$order->get_billing_postcode(),
							$order->get_billing_state(),
							$order->get_billing_city(),
							$order->get_billing_address_1()
						),
				];
			}

			/**
			 * Filters the resolved shipping destination.
			 *
			 * @since 2.0.2
			 *
			 * @param array{kind:string,text:string} $destination resolved destination.
			 * @param \WC_Order                       $order       the order.
			 * @param Orders_Provider|null            $provider    the matched carrier, or null.
			 */
			$filtered = apply_filters( 'woodev_shipping_orders_row_destination', $destination, $order, $provider );

			return is_array( $filtered ) ? $filtered : $destination;
		}

		/**
		 * Joins non-empty address parts with a comma, matching the reference plugins'
		 * own formatting (M1).
		 *
		 * @since 2.0.2
		 *
		 * @param string ...$parts address parts, coarsest first.
		 * @return string
		 */
		private function format_address( string ...$parts ): string {
			return implode( ', ', array_filter( $parts, static fn( string $part ): bool => '' !== $part ) );
		}

		/**
		 * Resolves `type`: `courier` | `pickup` | `postal` | `unknown` (M1) — computed by
		 * the framework from the order's own shipping method, never declared by a
		 * carrier.
		 *
		 * CONTRADICTS the brief's own sketch, which named `instanceof
		 * Shipping_Method_Courier`/`_Pickup`/`_Postal` as the mechanism: those three are
		 * OPTIONAL convenience base classes, not the authority. {@see Shipping_Method}
		 * declares `get_delivery_type()` abstract — every concrete method must implement
		 * it, and a real one may extend `Shipping_Method` directly without going through
		 * any of the three flavor subclasses (exactly what
		 * `Checkout_Config_Fake_Shipping_Method` in the checkout test suite already
		 * does). `is_courier_shipping()` / `is_pickup_shipping()` / `is_postal_shipping()`
		 * already wrap that one abstract method and are the established idiom elsewhere
		 * in this class (e.g. `Shipping_Plugin`'s own use of the same distinction) — an
		 * `instanceof` chain against the three subclasses would silently misclassify any
		 * method that skips them, which the framework does not forbid.
		 *
		 * A missing shipping line, a method the current zone no longer has, or a
		 * resolved object that is not a `Shipping_Method` at all (WC's own `false`
		 * "not found" included) all resolve to `unknown`, never a fatal.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order            $order    order.
		 * @param Orders_Provider|null $provider matched carrier, or null.
		 * @return string
		 */
		private function resolve_type( \WC_Order $order, ?Orders_Provider $provider ): string {
			if ( null === $provider ) {
				return 'unknown';
			}

			$item = Shipping_Helper::get_order_shipping_item( $order, $provider->get_method_id() );

			if ( null === $item ) {
				return 'unknown';
			}

			$instance_id = $item->get_instance_id();

			if ( ! $instance_id ) {
				return 'unknown';
			}

			$method = $this->get_shipping_method_by_instance_id( $instance_id );

			if ( ! $method instanceof Shipping_Method ) {
				return 'unknown';
			}

			if ( $method->is_courier_shipping() ) {
				return 'courier';
			}

			if ( $method->is_pickup_shipping() ) {
				return 'pickup';
			}

			if ( $method->is_postal_shipping() ) {
				return 'postal';
			}

			return 'unknown';
		}

		/**
		 * Thin wrapper over `\WC_Shipping_Zones::get_shipping_method()`, `protected` so a
		 * unit test can stand in a fake shipping-method instance without a real
		 * WooCommerce zone/instance store — `WC_Shipping_Zones` does real DB lookups and
		 * is exercised for real only in the integration suite, exactly like the same
		 * call in {@see \Woodev\Framework\Shipping\Shipping_Plugin}.
		 *
		 * @since 2.0.2
		 *
		 * @param int $instance_id shipping method instance id.
		 * @return \WC_Shipping_Method|false
		 */
		protected function get_shipping_method_by_instance_id( int $instance_id ) {
			return \WC_Shipping_Zones::get_shipping_method( $instance_id );
		}

		/**
		 * Builds the `tracking` field group. No number means null number AND null url —
		 * `'—'` is a display fallback, not this class's job.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order            $order    order.
		 * @param Orders_Provider|null $provider matched carrier, or null.
		 * @return array{number:?string,url:?string}
		 */
		private function build_tracking( \WC_Order $order, ?Orders_Provider $provider ): array {
			$number = null;

			if ( null !== $provider && null !== $provider->get_tracking_meta_key() ) {
				$value = (string) \Woodev_Order_Compatibility::get_order_meta( $order, $provider->get_tracking_meta_key() );

				$number = '' !== $value ? $value : null;
			}

			$template = null !== $provider ? $provider->get_tracking_url_template() : null;
			$url      = ( null !== $number && null !== $template )
				? str_replace( '{tracking}', rawurlencode( $number ), $template )
				: null;

			return [
				'number' => $number,
				'url'    => $url,
			];
		}

		/**
		 * Resolves the `delivery_status` field group via {@see Delivery_Status::resolve()}.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order            $order    order.
		 * @param Orders_Provider|null $provider matched carrier, or null.
		 * @return array{canonical:string,canonical_label:string,raw:?string,raw_label:?string}
		 */
		private function resolve_delivery_status( \WC_Order $order, ?Orders_Provider $provider ): array {
			if ( null === $provider || null === $provider->get_status_meta_key() ) {
				return Delivery_Status::resolve( null, [] );
			}

			$raw = (string) \Woodev_Order_Compatibility::get_order_meta( $order, $provider->get_status_meta_key() );

			return Delivery_Status::resolve( '' !== $raw ? $raw : null, $provider->get_status_map(), $provider->get_status_labels() );
		}
	}

endif;
