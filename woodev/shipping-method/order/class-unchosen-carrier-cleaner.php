<?php
/**
 * Removes the data of the carriers an order was not placed with.
 *
 * @since 2.0.2
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Order;

use Woodev\Framework\Shipping\Admin\Orders\{Carrier_Field_Set, Orders_Provider, Orders_Registry};
use Woodev\Framework\Shipping\Shipping_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Order\\Unchosen_Carrier_Cleaner' ) ) :

	/**
	 * Leaves an order with the data of the carrier the buyer chose — and only that one (#1260).
	 *
	 * Two carrier plugins on one site each keep the point they were handed. A buyer who picks a
	 * CDEK point, switches to Yandex and back, or whose payment failed and who then paid with the
	 * other carrier on the SAME reused order, would otherwise leave the abandoned carrier's point
	 * keys, marker and export baseline on the order they finally place.
	 *
	 * Runs after every checkout write (priority 999) on both the classic and the Store API order
	 * hooks, so nothing a carrier's handler stored earlier in the request survives. The chosen
	 * carriers are those with a shipping line on the order; every OTHER carrier is cleaned:
	 *
	 *  - the marker ({@see Orders_Provider::get_marker_meta_key()}),
	 *  - the point id and the full point the carrier's checkout stored,
	 *  - the carrier's own export fields ({@see Carrier_Field_Set::forget_except()}),
	 *  - the export baseline `Delivery_Status_Events` keeps per carrier.
	 *
	 * A key shared with a chosen carrier is never removed, and neither is anything that is not
	 * a named carrier key. A carrier that declared no method ids cannot be told apart from the
	 * chosen one, so it is left alone.
	 *
	 * @since 2.0.2
	 */
	final class Unchosen_Carrier_Cleaner {

		/**
		 * Registry to read providers from; null resolves {@see Orders_Registry::instance()} lazily.
		 *
		 * @since 2.0.2
		 *
		 * @var Orders_Registry|null
		 */
		private $registry;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param Orders_Registry|null $registry providers source; defaults to the shared singleton.
		 */
		public function __construct( ?Orders_Registry $registry = null ) {
			$this->registry = $registry;
		}

		/**
		 * `woocommerce_checkout_order_processed` — the classic checkout.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $order_id    the created order id (unused).
		 * @param mixed $posted_data the posted checkout data (unused).
		 * @param mixed $order       the created, saved order.
		 * @return void
		 */
		public static function handle_checkout_order_processed( $order_id = 0, $posted_data = [], $order = null ): void {
			if ( $order instanceof \WC_Order ) {
				( new self() )->forget_unchosen( $order );
			}
		}

		/**
		 * `woocommerce_store_api_checkout_order_processed` — the block checkout.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $order the created order.
		 * @return void
		 */
		public static function handle_store_api_order_processed( $order = null ): void {
			if ( $order instanceof \WC_Order ) {
				( new self() )->forget_unchosen( $order );
			}
		}

		/**
		 * Removes the data of every carrier the order has no shipping line of.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the created order.
		 * @return string[] ids of the carriers whose data was looked at (the unchosen ones).
		 */
		public function forget_unchosen( \WC_Order $order ): array {
			$registry  = $this->registry ?? Orders_Registry::instance();
			$providers = $registry->get_providers();

			if ( [] === $providers ) {
				return [];
			}

			$chosen = [];
			$other  = [];

			foreach ( $providers as $provider ) {
				if ( [] === $provider->get_method_ids() ) {
					continue;
				}

				$lines = $this->lines_of( $order, $provider );

				if ( [] !== $lines ) {
					$chosen[ $provider->get_id() ] = [ $provider, $lines ];
				} else {
					$other[ $provider->get_id() ] = $provider;
				}
			}

			if ( [] === $other ) {
				return [];
			}

			$keep = [];

			foreach ( $chosen as list( $provider, $lines ) ) {
				$keep = array_merge( $keep, $this->keys_of( $registry, $provider, $lines ) );
			}

			foreach ( $other as $provider ) {
				$this->forget( $order, $provider, $this->keys_of( $registry, $provider, [] ), $keep );
			}

			return array_keys( $other );
		}

		/**
		 * The order's shipping lines placed with one of the provider's methods.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order       $order    the order.
		 * @param Orders_Provider $provider the carrier.
		 * @return array<int, array{0: string, 1: int}> bare method id and instance id of each line.
		 */
		private function lines_of( \WC_Order $order, Orders_Provider $provider ): array {
			$lines = [];

			foreach ( $order->get_shipping_methods() as $item ) {
				foreach ( $provider->get_method_ids() as $method_id ) {
					if ( Shipping_Helper::is_method_of( (string) $item->get_method_id(), $method_id ) ) {
						$lines[] = [ $method_id, (int) $item->get_instance_id() ];

						break;
					}
				}
			}

			return $lines;
		}

		/**
		 * Every plain order-meta key a carrier owns: marker, point keys, export baseline.
		 *
		 * The carrier's own export fields are NOT in the list — they depend on the tariff, and are
		 * removed through {@see Carrier_Field_Set::forget_except()} by {@see self::forget()}; for a
		 * chosen carrier they are added from the lines it was chosen with.
		 *
		 * @since 2.0.2
		 *
		 * @param Orders_Registry                      $registry the providers source.
		 * @param Orders_Provider                      $provider the carrier.
		 * @param array<int, array{0: string, 1: int}> $lines  the lines of the chosen carrier; none for an unchosen one.
		 * @return string[]
		 */
		private function keys_of( Orders_Registry $registry, Orders_Provider $provider, array $lines ): array {
			$keys = [
				$provider->get_marker_meta_key(),
				Delivery_Status_Events::BASELINE_META_PREFIX . $provider->get_id(),
			];

			if ( null !== $provider->get_pickup_point_meta_key() ) {
				$keys[] = $provider->get_pickup_point_meta_key();
			}

			$plugin = $registry->get_provider_plugin( $provider->get_id() );

			if ( null !== $plugin ) {
				$checkout_handler = $plugin->get_checkout_handler();
				$pickup_handler   = $plugin->get_pickup_handler();

				if ( null !== $checkout_handler ) {
					$keys = array_merge( $keys, $checkout_handler->pickup_field_ids() );
				}

				if ( null !== $pickup_handler ) {
					$keys = array_merge( $keys, $pickup_handler->order_meta_keys() );
				}
			}

			foreach ( $lines as list( $method_id, $instance_id ) ) {
				$keys = array_merge( $keys, $this->field_keys( $provider, $method_id, $instance_id ) );
			}

			return array_values( array_unique( array_filter( $keys, 'is_string' ) ) );
		}

		/**
		 * Removes an unchosen carrier's keys from the order, except those a chosen carrier shares.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order       $order    the order.
		 * @param Orders_Provider $provider the unchosen carrier.
		 * @param string[]        $keys     the carrier's plain keys.
		 * @param string[]        $keep     the keys of the chosen carriers, which stay.
		 * @return void
		 */
		private function forget( \WC_Order $order, Orders_Provider $provider, array $keys, array $keep ): void {
			foreach ( array_diff( $keys, $keep ) as $meta_key ) {
				$meta_key = (string) $meta_key;
				$value = \Woodev_Order_Compatibility::get_order_meta( $order, $meta_key );

				// Writes nothing for a key the order does not carry.
				if ( '' !== $meta_key && null !== $value && false !== $value && '' !== $value && [] !== $value ) {
					\Woodev_Order_Compatibility::delete_order_meta( $order, $meta_key );
				}
			}

			foreach ( $provider->get_method_ids() as $method_id ) {
				$set = $this->field_set( $provider, $method_id, 0 );

				if ( null !== $set ) {
					$set->forget_except( $order, $keep );
				}
			}
		}

		/**
		 * The meta keys of the carrier's own export fields for one tariff.
		 *
		 * @since 2.0.2
		 *
		 * @param Orders_Provider $provider    the carrier.
		 * @param string          $method_id   bare method id.
		 * @param int             $instance_id zone-instance id.
		 * @return string[]
		 */
		private function field_keys( Orders_Provider $provider, string $method_id, int $instance_id ): array {
			$set = $this->field_set( $provider, $method_id, $instance_id );

			return null !== $set ? array_values( $set->meta_keys() ) : [];
		}

		/**
		 * The carrier's export-field set for a tariff — `null` when the carrier declares none or its
		 * declaration throws (a carrier's bug must never break the order being placed).
		 *
		 * @since 2.0.2
		 *
		 * @param Orders_Provider $provider    the carrier.
		 * @param string          $method_id   bare method id.
		 * @param int             $instance_id zone-instance id.
		 * @return Carrier_Field_Set|null
		 */
		private function field_set( Orders_Provider $provider, string $method_id, int $instance_id ): ?Carrier_Field_Set {
			if ( ! $provider->has_order_fields() ) {
				return null;
			}

			try {
				return Carrier_Field_Set::for_rate( $provider, $method_id, $instance_id );
			} catch ( \Throwable $e ) {
				return null;
			}
		}
	}

endif;
