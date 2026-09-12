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
		 * PUBLIC because it is part of what this class DECLARES, not an implementation
		 * detail: the same gate feeds the row column, the bulk actions and the order
		 * metabox, `unavailable_reason()` renders it into the sentence a merchant reads,
		 * and a plugin author asking "from which statuses can my carrier export?" deserves
		 * an answer that cannot drift from the gate itself.
		 *
		 * @since 2.0.2
		 *
		 * @var string[]
		 */
		public const EXPORTABLE_STATUSES = [ 'pending', 'on-hold', 'processing' ];

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
		 * Explains, in one merchant-readable Russian sentence, why `$action` is not on
		 * offer for this order.
		 *
		 * ⚠ Operator, s134, on the refusal text «Это действие недоступно для данного
		 * заказа.»: *«не хватает причины — пользователи будут писать в поддержку с
		 * вопросом "Что это означает?"»*. He is right, and the fix belongs HERE rather
		 * than in the REST layer: the gate in {@see self::for_order()} is computed from
		 * framework-owned state — the stored carrier order id, the WC order status and the
		 * canonical delivery status — so at the moment of refusal this class is the only
		 * thing that knows WHICH of those failed. The controller was throwing that away
		 * and reporting the bare fact.
		 *
		 * ⚠ This answers only for the framework's OWN gate. A refusal that comes from the
		 * CARRIER (a rejected address, a rate limit) is a different question with a
		 * different owner, and the carrier has no way to describe it today — `export()`
		 * returns a string and `cancel()`/`update()` return `bool`, so every carrier-side
		 * failure flattens into one sentence. That boundary is #819's.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order            $order    the order.
		 * @param Orders_Provider|null $provider the matched carrier, or null.
		 * @param string               $action   the refused action id.
		 * @return string a sentence for the merchant; never empty.
		 */
		public function unavailable_reason( \WC_Order $order, ?Orders_Provider $provider, string $action ): string {
			if ( null === $provider ) {
				return __( 'Для этого заказа не удалось определить перевозчика.', 'woodev-plugin-framework' );
			}

			if ( null === $this->registry->get_shipment_handler( $provider->get_id() ) ) {
				return __( 'Для этого перевозчика не настроен обработчик отправлений — действия недоступны.', 'woodev-plugin-framework' );
			}

			$is_exported = self::is_exported( $order, $provider );

			switch ( $action ) {
				case self::EXPORT:
					if ( $is_exported ) {
						return __( 'Заказ уже выгружен перевозчику.', 'woodev-plugin-framework' );
					}

					return sprintf(
						/* translators: %s: comma-separated WooCommerce order status names. */
						__( 'Выгрузить можно только заказ в одном из статусов: %s.', 'woodev-plugin-framework' ),
						self::exportable_status_names()
					);

				case self::UPDATE:
					if ( ! $is_exported ) {
						return __( 'Заказ ещё не выгружен перевозчику — обновлять нечего.', 'woodev-plugin-framework' );
					}

					return __( 'Этот перевозчик не умеет запрашивать статус заказа из админки.', 'woodev-plugin-framework' );

				case self::CANCEL:
					if ( ! $is_exported ) {
						return __( 'Заказ ещё не выгружен перевозчику — отменять нечего.', 'woodev-plugin-framework' );
					}

					return sprintf(
						/* translators: %s: canonical delivery status label, e.g. "Доставлено". */
						__( 'Отправление уже в конечном статусе «%s» — отменить его нельзя.', 'woodev-plugin-framework' ),
						Delivery_Status::label( self::resolve_canonical_status( $order, $provider ) )
					);
			}

			return __( 'Это действие недоступно для данного заказа.', 'woodev-plugin-framework' );
		}

		/**
		 * The WooCommerce status NAMES an export is allowed from, comma-separated.
		 *
		 * Built from {@see self::EXPORTABLE_STATUSES} through
		 * `wc_get_order_status_name()` rather than typed out, so the sentence cannot drift
		 * from the gate it describes — and so it reads in the merchant's own locale
		 * («В ожидании оплаты, На удержании, Обработка») instead of exposing our slugs.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		private static function exportable_status_names(): string {
			return implode(
				', ',
				array_map( 'wc_get_order_status_name', self::EXPORTABLE_STATUSES )
			);
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
