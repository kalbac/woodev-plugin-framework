<?php
/**
 * Shipping order marker
 *
 * @since 2.0.2
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Order;

use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Shipping_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Order\\Order_Marker' ) ) :

	/**
	 * Marks an order as belonging to a carrier, through the carrier's own writer (#967).
	 *
	 * The framework-owned orders page finds a carrier's orders by a marker order-meta key
	 * ({@see Orders_Provider::get_marker_meta_key()}), but the marker's VALUE and the moment
	 * it is written differ per carrier, so the framework never invents one: each provider
	 * supplies a writer ({@see Orders_Provider::mark_order()}). This class is the ONE place
	 * that decides which providers an order belongs to, runs their writers, persists what they
	 * wrote and checks the result against what the orders page reads. Every path that creates
	 * an order calls it — through
	 * {@see \Woodev\Framework\Shipping\Checkout\Checkout_Handler::persist_values()}, the
	 * persistence core shared by the classic checkout, the Store API checkout and the admin
	 * order editor — so all of them mark identically.
	 *
	 * **Which providers.** Those that declared a writer AND whose method id
	 * ({@see Orders_Provider::get_method_ids()}) is on one of the order's shipping lines. The
	 * checkout handlers run for EVERY order (once per active carrier plugin), so an order shipped
	 * by another carrier, or with free shipping, is never marked here. An order with lines of two
	 * carriers is marked for both; the orders page reports such an order under `WP_DEBUG` (#928).
	 *
	 * @since 2.0.2
	 */
	final class Order_Marker {

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
		 * Whether a marker value is one the orders page can read.
		 *
		 * The list query matches the marker by presence, but
		 * {@see Orders_Registry::resolve_provider_for_order()} — the row owner and the metabox —
		 * treats `''` and `false` as «no marker», and an array value raises a PHP warning when
		 * cast (measured, #962 I0). So: a non-empty scalar. `'1'`, `true` and `'0'` all pass.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $value a stored marker value.
		 * @return bool
		 */
		public static function is_valid_value( $value ): bool {
			return is_scalar( $value ) && '' !== (string) $value;
		}

		/**
		 * Runs the marker writer of every provider the order belongs to and persists the result.
		 *
		 * A writer that throws, or leaves the marker missing or invalid
		 * ({@see self::is_valid_value()}), is logged and skipped — it never breaks the order being
		 * placed. The caller learns the outcome from the return value: an order whose provider is
		 * not in it will NOT appear on the orders page.
		 *
		 * A provider whose marker is already valid is left alone (and reported as marked) unless
		 * `$refresh` is set: the checkout handlers of several carrier plugins reach this for the
		 * same order, and re-running a writer per plugin would repeat work for nothing. The admin
		 * editor passes `$refresh` when it re-saves an order, because a marker derived from the
		 * chosen rate must follow the edit.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order|int       $order   the order, already saved and carrying its shipping line(s).
		 * @param array<string,mixed> $context optional overrides for the writer's context — only the keys
		 *                                     `rate`, `fields`, `pickup_point` and `carrier_fields` are read;
		 *                                     the rest of {@see Orders_Provider::mark_order()}'s context is
		 *                                     derived from the order.
		 * @param bool                $refresh re-run writers even where a valid marker is already present.
		 * @return string[] ids of the providers whose marker is now present and valid.
		 */
		public function mark_order( $order, array $context = [], bool $refresh = false ): array {
			$providers = ( $this->registry ?? Orders_Registry::instance() )->get_providers();

			if ( [] === $providers ) {
				return [];
			}

			if ( ! $order instanceof \WC_Order ) {
				$order = is_numeric( $order ) && $order > 0 ? wc_get_order( (int) $order ) : null;
			}

			if ( ! $order instanceof \WC_Order ) {
				return [];
			}

			$marked = [];

			foreach ( $providers as $provider ) {
				if ( ! $provider->has_marker_writer() ) {
					continue;
				}

				$line = $this->find_shipping_line( $order, $provider );

				if ( null === $line ) {
					continue;
				}

				if ( ! $refresh && $this->carries_valid_marker( $order, $provider ) ) {
					$marked[] = $provider->get_id();
					continue;
				}

				if ( $this->write( $order, $provider, $this->build_context( $provider, $line, $context ) ) ) {
					$marked[] = $provider->get_id();
				}
			}

			return $marked;
		}

		/**
		 * Runs one provider's writer, saves the order's meta and verifies the marker.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order           $order    the order.
		 * @param Orders_Provider     $provider the provider.
		 * @param array<string,mixed> $context  the writer's context.
		 * @return bool whether a valid marker is present afterwards.
		 */
		private function write( \WC_Order $order, Orders_Provider $provider, array $context ): bool {
			try {
				$provider->mark_order( $order, $context );

				if ( $order->get_id() > 0 ) {
					$order->save_meta_data();
				}
			} catch ( \Throwable $e ) {
				$this->log( sprintf( 'marker writer of carrier "%1$s" failed for order %2$d: %3$s', $provider->get_id(), $order->get_id(), $e->getMessage() ) );

				return false;
			}

			if ( ! $this->carries_valid_marker( $order, $provider ) ) {
				$this->log(
					sprintf(
						'marker writer of carrier "%1$s" left no valid "%2$s" on order %3$d — it must write a non-empty scalar; the order will not appear on the orders page.',
						$provider->get_id(),
						$provider->get_marker_meta_key(),
						$order->get_id()
					)
				);

				return false;
			}

			return true;
		}

		/**
		 * Whether the order carries a valid marker for the provider, read the way the orders page
		 * reads it ({@see Orders_Registry::resolve_provider_for_order()}).
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order       $order    the order.
		 * @param Orders_Provider $provider the provider.
		 * @return bool
		 */
		private function carries_valid_marker( \WC_Order $order, Orders_Provider $provider ): bool {
			return self::is_valid_value( \Woodev_Order_Compatibility::get_order_meta( $order, $provider->get_marker_meta_key() ) );
		}

		/**
		 * The order's first shipping line placed with one of the provider's methods.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order       $order    the order.
		 * @param Orders_Provider $provider the provider.
		 * @return \WC_Order_Item_Shipping|null
		 */
		private function find_shipping_line( \WC_Order $order, Orders_Provider $provider ): ?\WC_Order_Item_Shipping {
			foreach ( $provider->get_method_ids() as $method_id ) {
				$line = Shipping_Helper::get_order_shipping_item( $order, $method_id );

				if ( null !== $line ) {
					return $line;
				}
			}

			return null;
		}

		/**
		 * Builds the writer's context ({@see Orders_Provider::mark_order()}): everything derivable
		 * from the order's shipping line, with the caller's overrides laid over it.
		 *
		 * @since 2.0.2
		 *
		 * @param Orders_Provider         $provider  the provider.
		 * @param \WC_Order_Item_Shipping $line     the shipping line that made the order this carrier's.
		 * @param array<string,mixed>     $overrides caller-supplied `rate`, `fields`, `pickup_point`, `carrier_fields`.
		 * @return array<string,mixed>
		 */
		private function build_context( Orders_Provider $provider, \WC_Order_Item_Shipping $line, array $overrides ): array {
			$method_id   = (string) $line->get_method_id();
			$instance_id = (int) $line->get_instance_id();

			$meta = [];

			foreach ( $line->get_meta_data() as $entry ) {
				$meta[ (string) $entry->key ] = $entry->value;
			}

			$context = [
				'provider_id'    => $provider->get_id(),
				'method_id'      => $method_id,
				'instance_id'    => $instance_id,
				'rate'           => [
					'id'          => $instance_id > 0 ? $method_id . ':' . $instance_id : $method_id,
					'method_id'   => $method_id,
					'instance_id' => $instance_id,
					'label'       => (string) $line->get_name(),
					'cost'        => (string) $line->get_total(),
					'meta'        => $meta,
				],
				'fields'         => [],
				'pickup_point'   => null,
				'carrier_fields' => [],
			];

			return array_replace(
				$context,
				array_intersect_key(
					$overrides,
					[
						'rate'           => true,
						'fields'         => true,
						'pickup_point'   => true,
						'carrier_fields' => true,
					]
				)
			);
		}

		/**
		 * Diagnostic line — a marker problem is a plugin bug the merchant can do nothing about, and
		 * must not surface at checkout.
		 *
		 * @since 2.0.2
		 *
		 * @param string $message what went wrong.
		 * @return void
		 */
		private function log( string $message ): void {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for a carrier plugin's marker writer; the customer/manager only ever sees the order missing from the page.
			error_log( '[woodev] ' . $message );
		}
	}

endif;
