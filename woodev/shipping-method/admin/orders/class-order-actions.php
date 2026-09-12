<?php
/**
 * Shipping orders — per-row action set
 *
 * @since 2.0.2
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Admin\Orders;

use Woodev\Framework\Shipping\Order\Delivery_Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Admin\\Orders\\Order_Actions' ) ) :

	/**
	 * Declares the per-order action set — «Выгрузить» / «Обновить» / «Отменить» — ONCE
	 * (card #824), so the row column, bulk actions (SP-10 increment 3) and the order
	 * metabox (#856) all read the same gate rather than each growing their own copy.
	 *
	 * Pure: given an order + provider (the provider's registered shipment handler is
	 * resolved internally through {@see Orders_Registry}), which actions are
	 * available. No rendering, no HTTP — {@see \Woodev\Framework\Shipping\Rest_Api\Orders_Controller}
	 * is the one place that turns an action id into an actual carrier call.
	 *
	 * @since 2.0.2
	 */
	class Order_Actions {

		/** @var string */
		public const EXPORT = 'export';

		/** @var string */
		public const UPDATE = 'update';

		/** @var string */
		public const CANCEL = 'cancel';

		/**
		 * WC order statuses «Выгрузить» is offered on: still early enough in the
		 * order's own lifecycle to be worth shipping (mirrors the shipped v1
		 * plugins' own gate, see class docblock of
		 * {@see \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler}).
		 *
		 * @since 2.0.2
		 *
		 * @var string[]
		 */
		private const EXPORTABLE_STATUSES = [ 'pending', 'on-hold', 'processing' ];

		/**
		 * Canonical delivery statuses that retire «Отменить»: the shipment already
		 * reached an end state at the carrier, so cancelling it is meaningless.
		 *
		 * The framework cannot read a carrier's own vocabulary (edostavka's own
		 * `is_exported()` additionally excludes its carrier statuses
		 * NEW/CANCELED/INVALID), but it owns the CANONICAL status a provider's
		 * `status_map` produces — this is the framework-side equivalent of that same
		 * line. {@see \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler::cancel()}
		 * never clears `carrier_order_id`, so without this clause a cancelled order
		 * would offer «Отменить» forever.
		 *
		 * @since 2.0.2
		 *
		 * @var string[]
		 */
		private const CANCEL_RETIRED_STATUSES = [
			Delivery_Status::DELIVERED,
			Delivery_Status::RETURNED,
			Delivery_Status::CANCELLED,
			Delivery_Status::FAILED,
		];

		/**
		 * Registry a provider's registered shipment handler is resolved through.
		 *
		 * @since 2.0.2
		 *
		 * @var Orders_Registry
		 */
		private Orders_Registry $registry;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param Orders_Registry $registry registry a provider's shipment handler is
		 *                                  resolved through.
		 */
		public function __construct( Orders_Registry $registry ) {
			$this->registry = $registry;
		}

		/**
		 * Returns the actions available on one order row.
		 *
		 * `$provider === null` (an unresolvable carrier) and a provider with no
		 * shipment handler registered against {@see Orders_Registry} both offer no
		 * actions at all — the framework cannot act on an order it cannot route to a
		 * carrier's handler.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order            $order    the order.
		 * @param Orders_Provider|null $provider the matched carrier, or null when it
		 *                                        could not be resolved.
		 * @return array<int,array<string,mixed>> each: [
		 *     'action'      => string,  // one of the three ids above, or a carrier extra.
		 *     'label'       => string,  // button text, Russian.
		 *     'title'       => string,  // tooltip; '' when none.
		 *     'destructive' => bool,    // true => the client confirms first.
		 * ]
		 */
		public function for_order( \WC_Order $order, ?Orders_Provider $provider ): array {
			if ( null === $provider ) {
				return [];
			}

			$handler = $this->registry->get_shipment_handler( $provider->get_id() );

			if ( null === $handler ) {
				return [];
			}

			$is_exported = self::is_exported( $order, $provider );
			$actions     = [];

			if ( ! $is_exported && in_array( $order->get_status(), self::EXPORTABLE_STATUSES, true ) ) {
				$actions[] = self::build_action(
					self::EXPORT,
					__( 'Выгрузить', 'woodev-plugin-framework' ),
					__( 'Передать заказ перевозчику', 'woodev-plugin-framework' ),
					false
				);
			}

			if ( $is_exported && $handler->supports_update() ) {
				$actions[] = self::build_action(
					self::UPDATE,
					__( 'Обновить', 'woodev-plugin-framework' ),
					__( 'Запросить у перевозчика текущий статус заказа', 'woodev-plugin-framework' ),
					false
				);
			}

			if ( $is_exported && ! in_array( self::resolve_canonical_status( $order, $provider ), self::CANCEL_RETIRED_STATUSES, true ) ) {
				$actions[] = self::build_action(
					self::CANCEL,
					__( 'Отменить', 'woodev-plugin-framework' ),
					__( 'Отменить заказ у перевозчика', 'woodev-plugin-framework' ),
					true
				);
			}

			/**
			 * Filters the actions available on one order row.
			 *
			 * @since 2.0.2
			 *
			 * @param array<int,array<string,mixed>> $actions  built actions.
			 * @param \WC_Order                       $order    the order.
			 * @param Orders_Provider|null            $provider the matched carrier, or null.
			 */
			$filtered = apply_filters( 'woodev_shipping_order_actions', $actions, $order, $provider );

			return is_array( $filtered ) ? self::sanitize_actions( $filtered ) : $actions;
		}

		/**
		 * Whether an order has ever been exported to its carrier (card #860): the
		 * provider's own `carrier_order_id` meta key is present AND non-empty.
		 *
		 * A provider with no declared `carrier_order_id_meta_key` never counts as
		 * exported — the framework has no way to know, so it does not guess (the same
		 * asymmetry {@see Orders_Query::is_exported_meta_clauses()} documents).
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order       $order    order.
		 * @param Orders_Provider $provider matched carrier.
		 * @return bool
		 */
		private static function is_exported( \WC_Order $order, Orders_Provider $provider ): bool {
			$meta_key = $provider->get_carrier_order_id_meta_key();

			if ( null === $meta_key ) {
				return false;
			}

			return '' !== (string) \Woodev_Order_Compatibility::get_order_meta( $order, $meta_key );
		}

		/**
		 * Resolves the order's canonical delivery status for the cancel gate, the
		 * same mapping {@see \Woodev\Framework\Shipping\Admin\Orders\Order_Row_Builder::resolve_delivery_status()}
		 * uses for display — duplicated rather than shared to avoid a dependency in
		 * the wrong direction (`Order_Row_Builder` already depends on this class to
		 * build the `actions` row field).
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order       $order    order.
		 * @param Orders_Provider $provider matched carrier.
		 * @return string one of {@see Delivery_Status::canonical_states()} or
		 *                {@see Delivery_Status::UNKNOWN}.
		 */
		private static function resolve_canonical_status( \WC_Order $order, Orders_Provider $provider ): string {
			if ( null === $provider->get_status_meta_key() ) {
				return Delivery_Status::UNKNOWN;
			}

			$raw = (string) \Woodev_Order_Compatibility::get_order_meta( $order, $provider->get_status_meta_key() );

			return Delivery_Status::resolve( '' !== $raw ? $raw : null, $provider->get_status_map() )['canonical'];
		}

		/**
		 * Builds one action entry.
		 *
		 * @since 2.0.2
		 *
		 * @param string $action      one of the action ids.
		 * @param string $label       button text.
		 * @param string $title       tooltip; '' when none.
		 * @param bool   $destructive true => the client confirms first.
		 * @return array<string,mixed>
		 */
		private static function build_action( string $action, string $label, string $title, bool $destructive ): array {
			return [
				'action'      => $action,
				'label'       => $label,
				'title'       => $title,
				'destructive' => $destructive,
			];
		}

		/**
		 * Re-validates a filtered actions array's shape, dropping malformed entries
		 * rather than shipping them to the client.
		 *
		 * @since 2.0.2
		 *
		 * @param array<mixed> $actions filtered value, of unknown shape.
		 * @return array<int,array<string,mixed>>
		 */
		private static function sanitize_actions( array $actions ): array {
			$sanitized = [];

			foreach ( $actions as $action ) {
				if ( ! is_array( $action ) ) {
					continue;
				}

				if ( ! isset( $action['action'] ) || ! is_string( $action['action'] ) || '' === $action['action'] ) {
					continue;
				}

				if ( ! isset( $action['label'] ) || ! is_string( $action['label'] ) || '' === $action['label'] ) {
					continue;
				}

				$sanitized[] = [
					'action'      => $action['action'],
					'label'       => $action['label'],
					'title'       => isset( $action['title'] ) && is_string( $action['title'] ) ? $action['title'] : '',
					'destructive' => (bool) ( $action['destructive'] ?? false ),
				];
			}

			return $sanitized;
		}
	}

endif;
