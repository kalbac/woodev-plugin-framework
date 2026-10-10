<?php
/**
 * Shipping orders — per-row action set
 *
 * @since 2.0.2
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Admin\Orders;

use Woodev\Framework\Shipping\Location\Location_Provider;
use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;
use Woodev\Framework\Shipping\Order\Action_Result;
use Woodev\Framework\Shipping\Order\Bulk_Document_Source;
use Woodev\Framework\Shipping\Order\Carrier_Cancel;
use Woodev\Framework\Shipping\Order\Delivery_Status;
use Woodev\Framework\Shipping\Order\Order_Automation;
use Woodev\Framework\Shipping\Order\Shipment_Cancellation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Admin\\Orders\\Order_Actions' ) ) :

	/**
	 * Declares the per-order action set — «Экспорт» / «Обновить» / «Отменить» — ONCE
	 * (card #824), so the row column, bulk actions (SP-10 increment 3) and the order
	 * metabox (#856) all read the same gate rather than each growing their own copy.
	 *
	 * Pure: given an order + provider (the provider's registered shipment handler is
	 * resolved internally through {@see Orders_Registry}), which actions are
	 * available. No rendering, no HTTP — {@see self::perform()} is the one place that turns an action
	 * id into an actual carrier call, and the callers (the orders REST routes, the order wizard's
	 * immediate export) own the gate's refusal wording and the error handling.
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
		 * «Оформить отказ» (#1204) — the recipient refuses a parcel already on its way and it returns to the
		 * sender, normally for a fee. Offered only where the carrier supports it
		 * ({@see Abstract_Shipment_Handler::supports_refusal()}) and the shipment is in a state the carrier
		 * declares refusable; always behind a confirmation that names the cost. A person's click only: the
		 * background cancellation never refuses, and the bulk route skips it.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		public const REFUSE = 'refuse';

		/**
		 * «Редактировать» — opens the order wizard (#710, card #972). A CLIENT-side action: it appears on
		 * a row through {@see self::for_row()} only, and is not something the action / bulk routes or the
		 * metabox can execute, so it is not part of {@see self::for_order()}.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		public const EDIT = 'edit';

		/**
		 * The carrier documents a row offers, by action id. CLIENT-side like {@see self::EDIT}: the click
		 * downloads the file through the documents REST route and never reaches the action routes, so none
		 * of them is part of {@see self::for_order()} — the metabox downloads them the same way the orders
		 * page does ({@see self::is_document()}).
		 *
		 * @since 2.0.2
		 *
		 * @var string[]
		 */
		public const DOCUMENTS = [ 'waybill', 'barcode' ];

		/**
		 * The icon of an action that is not offered with one of its own: the neutral «run» glyph, never the
		 * settings gear. Used by both surfaces when an extra action declares no `icon`.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		public const FALLBACK_ICON = 'controls-play';

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
		 * WC order statuses an order is FINAL in: the admin order wizard neither edits an order
		 * in one of them nor moves an order into one (#710 spec D5) — refunds, cancellations and
		 * the like stay with WooCommerce's own tools.
		 *
		 * @since 2.0.2
		 *
		 * @var string[]
		 */
		public const FINAL_STATUSES = [ 'completed', 'cancelled', 'refunded', 'failed' ];

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
		 * An order another manager holds the native edit lock on (#1000) offers none either: this
		 * is the gate the action route, the bulk route and the metabox all recompute, so a lock
		 * taken by the wizard after the row was drawn refuses the click server-side — an export
		 * landing under an open wizard would make its save fail on the now-exported order. The
		 * lock of the CURRENT manager never counts ({@see self::edit_lock_owner()}), so WooCommerce's
		 * own order screen, which takes that lock for its user, is untouched. The row still shows
		 * the actions, greyed out — {@see self::for_row()}.
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
		 *     'destructive' => bool,    // true => the client confirms first AND draws the button red.
		 *     'fields'      => array,   // optional (#1180): the input the action asks for, {@see Order_Action_Fields}.
		 *     'icon'        => string,  // optional: a Dashicons slug without the `dashicons-` prefix, e.g. 'upload'. None => the neutral {@see self::FALLBACK_ICON}, never a gear.
		 *     'confirm'     => string,  // optional (s164), `destructive` actions only: the sentence the confirmation asks. None => each surface's generic question.
		 * ]
		 */
		public function for_order( \WC_Order $order, ?Orders_Provider $provider ): array {
			if ( null !== self::edit_lock_owner( $order ) ) {
				return [];
			}

			return $this->carrier_actions( $order, $provider );
		}

		/**
		 * The carrier actions the order's own state offers, whoever holds its edit lock — the set
		 * {@see self::for_order()} returns for an unlocked order and {@see self::for_row()} greys
		 * out for a locked one (#1000).
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order            $order    the order.
		 * @param Orders_Provider|null $provider the matched carrier, or null.
		 * @return array<int,array<string,mixed>> the shape {@see self::for_order()} documents.
		 */
		private function carrier_actions( \WC_Order $order, ?Orders_Provider $provider ): array {
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
					__( 'Экспорт', 'woodev-plugin-framework' ),
					__( 'Выгрузить заказ в систему перевозчика', 'woodev-plugin-framework' ),
					false,
					'upload'
				);
			}

			if ( $is_exported && $handler->supports_update() ) {
				$actions[] = self::build_action(
					self::UPDATE,
					__( 'Обновить', 'woodev-plugin-framework' ),
					__( 'Запросить у перевозчика текущий статус заказа', 'woodev-plugin-framework' ),
					false,
					'update'
				);
			}

			if ( $is_exported && ! in_array( self::resolve_canonical_status( $order, $provider ), self::CANCEL_RETIRED_STATUSES, true ) ) {
				$actions[] = self::build_action(
					self::CANCEL,
					__( 'Отменить', 'woodev-plugin-framework' ),
					__( 'Отменить заказ у перевозчика', 'woodev-plugin-framework' ),
					true,
					'remove'
				);
			}

			// #1204: never automatic and never bulk — the merchant's own click, behind a confirmation that names the cost.
			if ( $is_exported && self::refusal_is_open( $handler, $order, $provider ) ) {
				$actions[] = array_merge(
					self::build_action(
						self::REFUSE,
						__( 'Оформить отказ', 'woodev-plugin-framework' ),
						__( 'Отказаться от посылки, которая уже в пути: она вернётся к вам', 'woodev-plugin-framework' ),
						true,
						'undo'
					),
					[ 'confirm' => __( 'Получатель откажется от посылки, она вернётся к вам. Возврат платный — перевозчик начислит стоимость обратной доставки.', 'woodev-plugin-framework' ) ]
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
		 * The actions a ROW of the orders page shows: {@see self::for_order()}'s set plus «Редактировать»
		 * when the order may still be edited (#710 spec D5, card #972).
		 *
		 * ⚠ Deliberately a SECOND method, not an extra entry in `for_order()`. `for_order()` is the set of
		 * things the server can EXECUTE for an order — the REST action route, the bulk route and the
		 * metabox all recompute it to refuse a stale click, and each would have to learn that `edit` is
		 * not a carrier call. «Редактировать» opens the wizard on the client and never reaches those
		 * routes; they keep refusing it, as they refuse any unknown id.
		 *
		 * The button is offered by {@see self::is_editable()} — the one policy the load / update routes
		 * re-check — so it cannot outlive what the server would accept. Unlike the carrier actions it
		 * does NOT need a registered shipment handler (#988): editing never calls the carrier, so a
		 * carrier with rates but no export still gets it; only the carrier actions of
		 * {@see self::for_order()} stay behind the handler.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order            $order    the order.
		 * @param Orders_Provider|null $provider the matched carrier, or null when it could not be resolved.
		 * @return array<int,array<string,mixed>> the same shape as {@see self::for_order()}; the edit
		 *                                        action, when offered, comes first.
		 */
		public function for_row( \WC_Order $order, ?Orders_Provider $provider ): array {
			$lock_owner = self::edit_lock_owner( $order );
			$actions    = $this->carrier_actions( $order, $provider );

			if ( null !== $lock_owner ) {
				// #1000: greyed out, not removed — the manager sees what the row would offer and why
				// it cannot be used. The server refuses them all the same ({@see self::for_order()}).
				foreach ( $actions as $index => $carrier_action ) {
					$actions[ $index ] = self::lock_action( $carrier_action, $lock_owner );
				}
			}

			// Document downloads only READ from the carrier, so another manager's edit lock does not grey them out.
			$actions = array_merge( $actions, $this->document_actions( $order, $provider ) );

			if ( ! self::is_editable( $order, $provider ) ) {
				return $actions;
			}

			$edit_action = self::build_action(
				self::EDIT,
				__( 'Редактировать', 'woodev-plugin-framework' ),
				__( 'Изменить заказ, пока он не выгружен перевозчику', 'woodev-plugin-framework' ),
				false,
				'edit'
			);

			if ( null !== $lock_owner ) {
				$edit_action = self::lock_action( $edit_action, $lock_owner );
			}

			array_unshift(
				$actions,
				$edit_action
			);

			return $actions;
		}

		/**
		 * Document downloads are client-side actions, offered only on rows, for an order that has a carrier order
		 * ({@see self::is_exported()} — the same precondition the download route enforces).
		 *
		 * @since 2.0.2
		 * @param \WC_Order            $order order.
		 * @param Orders_Provider|null $provider matched carrier.
		 * @return array<int,array<string,mixed>>
		 */
		private function document_actions( \WC_Order $order, ?Orders_Provider $provider ): array {
			if ( null === $provider || ! self::is_exported( $order, $provider ) || ! $provider->supports_label_printing() ) {
				return [];
			}

			$source = $this->registry->get_document_source( $provider->get_id() );
			if ( null === $source ) {
				return [];
			}

			$labels = [
				'waybill' => __( 'Накладная', 'woodev-plugin-framework' ),
				'barcode' => __( 'Штрихкод', 'woodev-plugin-framework' ),
			];
			// Two different glyphs: a waybill is a page, a barcode is the label stuck on the parcel.
			$icons = [
				'waybill' => 'media-document',
				'barcode' => 'tag',
			];
			// #1192: a carrier that can print this document for SEVERAL orders says so on the row, so the orders page
			// offers «Печать накладных» / «Печать штрихкодов» only where it will work. Absent => single-order only.
			$bulk_types = $source instanceof Bulk_Document_Source ? $source->get_bulk_document_types() : [];
			$actions    = [];
			foreach ( $source->get_document_types( $order ) as $type ) {
				if ( isset( $labels[ $type ] ) ) {
					$action = self::build_action( $type, $labels[ $type ], __( 'Скачать документ перевозчика', 'woodev-plugin-framework' ), false, $icons[ $type ] );
					if ( in_array( $type, $bulk_types, true ) ) {
						$action['bulk'] = true;
					}
					$actions[] = $action;
				}
			}

			return $actions;
		}

		/**
		 * Whether `$action` is currently offered on this order.
		 *
		 * Wraps the exact `array_column()`/`in_array()` shape {@see \Woodev\Framework\Shipping\Rest_Api\Orders_Controller::perform_action()}
		 * already applies to {@see self::for_order()}'s result, so a second caller — the
		 * order-edit metabox ({@see \Woodev\Framework\Shipping\Admin\Shipping_Admin_Order::handle_order_action()})
		 * — never trusts a posted action either. Both recompute the gate from the SAME
		 * `for_order()` call; a stale client (a delivered order posting `cancel`, a
		 * carrier whose `supports_update()` just turned false) is refused here exactly as
		 * REST refuses it, not routed straight to the carrier handler (#856 round 2).
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order            $order    the order.
		 * @param Orders_Provider|null $provider the matched carrier, or null.
		 * @param string               $action   the action id to check.
		 * @return bool
		 */
		public function is_offered( \WC_Order $order, ?Orders_Provider $provider, string $action ): bool {
			return in_array( $action, array_column( $this->for_order( $order, $provider ), 'action' ), true );
		}

		/**
		 * The input fields one action of an already computed action set declares ({@see Order_Action_Fields}).
		 *
		 * Takes the set the caller has just gated on rather than asking {@see self::for_order()} again:
		 * the `woodev_shipping_order_actions` filter runs on every call, and the declaration that is
		 * validated against must be the one that was offered.
		 *
		 * @since 2.0.2
		 *
		 * @param array<int,array<string,mixed>> $offered the set {@see self::for_order()} returned.
		 * @param string                         $action  the action id.
		 * @return array<int,array<string,mixed>> the sanitised field list; `[]` when the action declares none or is not in the set.
		 */
		public static function fields_of( array $offered, string $action ): array {
			foreach ( $offered as $entry ) {
				if ( ( $entry['action'] ?? null ) === $action ) {
					return $entry['fields'] ?? [];
				}
			}

			return [];
		}

		/**
		 * Checks a posted payload against the fields the offered action declares — the ONE place the
		 * REST route and the metabox turn what a merchant typed into what {@see self::perform()} takes.
		 *
		 * An action that declares no fields yields an empty payload whatever was posted: nothing
		 * undeclared is forwarded to a handler.
		 *
		 * @since 2.0.2
		 *
		 * @param array<int,array<string,mixed>> $offered the set {@see self::for_order()} returned.
		 * @param string                         $action  the action id.
		 * @param mixed                          $raw     the posted payload, of unknown shape.
		 * @return array{values: array<string,mixed>, errors: array<int,array{field:string,code:string,message:string}>}
		 */
		public static function resolve_payload( array $offered, string $action, $raw ): array {
			$fields = self::fields_of( $offered, $action );

			if ( [] === $fields ) {
				return [
					'values' => [],
					'errors' => [],
				];
			}

			return Order_Action_Fields::validate( $fields, $raw );
		}

		/**
		 * Whether «Отменить» is on offer for the order's state — the gate of the button, without the
		 * native edit lock that {@see self::for_order()} also applies (#1007).
		 *
		 * The background cancellation of a cancelled / fully refunded WooCommerce order asks this. It
		 * runs without a current manager (every edit lock looks «another manager's» there), and the lock
		 * exists to keep an export from landing under an open wizard: an order that has a shipment cannot
		 * be edited by the wizard at all, so there is nothing for it to protect. Everything else is the
		 * button's own gate — exported, the delivery not in an end state ({@see self::CANCEL_RETIRED_STATUSES}),
		 * and whatever a plugin did to the set through `woodev_shipping_order_actions`.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order            $order    the order.
		 * @param Orders_Provider|null $provider the matched carrier, or null.
		 * @return bool
		 */
		public function can_cancel( \WC_Order $order, ?Orders_Provider $provider ): bool {
			return in_array( self::CANCEL, array_column( $this->carrier_actions( $order, $provider ), 'action' ), true );
		}

		/**
		 * Whether «Оформить отказ» is on offer for the order's state — the gate of the button, without the native
		 * edit lock (the same relation to {@see self::for_order()} as {@see self::can_cancel()}, #1204).
		 *
		 * The background cancellation asks it only to word its order note: it points to the button when the
		 * button exists. It never performs the refusal.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order            $order    the order.
		 * @param Orders_Provider|null $provider the matched carrier, or null.
		 * @return bool
		 */
		public function can_refuse( \WC_Order $order, ?Orders_Provider $provider ): bool {
			return in_array( self::REFUSE, array_column( $this->carrier_actions( $order, $provider ), 'action' ), true );
		}

		/**
		 * Whether the order's parcel is already «handed to delivery» (#1204): exported, not in an end state, and in
		 * a delivery state its carrier declares deletion impossible from
		 * ({@see Abstract_Shipment_Handler::get_handed_over_statuses()}).
		 *
		 * The ONE answer behind the background cancellation (no doomed request, a clear note instead) and the
		 * order-edit screen's warning before a manager sets such an order to «Отменён». A carrier that declares
		 * nothing — the default — is never «handed over», so its behaviour is the one it always had. Reads local
		 * data only: safe while an order page is drawn.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order            $order    the order.
		 * @param Orders_Provider|null $provider the matched carrier, or null.
		 * @return bool
		 */
		public function is_handed_over( \WC_Order $order, ?Orders_Provider $provider ): bool {
			if ( null === $provider || ! self::is_exported( $order, $provider ) ) {
				return false;
			}

			$handler = $this->registry->get_shipment_handler( $provider->get_id() );

			if ( null === $handler ) {
				return false;
			}

			$canonical = self::resolve_canonical_status( $order, $provider );

			return ! in_array( $canonical, self::CANCEL_RETIRED_STATUSES, true )
				&& in_array( $canonical, $handler->get_handed_over_statuses(), true );
		}

		/**
		 * Whether the carrier supports a refusal and the shipment is in a state it declares refusable.
		 *
		 * @since 2.0.2
		 *
		 * @param Abstract_Shipment_Handler $handler  the carrier's handler.
		 * @param \WC_Order                 $order    the order.
		 * @param Orders_Provider           $provider the matched carrier.
		 * @return bool
		 */
		private static function refusal_is_open( Abstract_Shipment_Handler $handler, \WC_Order $order, Orders_Provider $provider ): bool {
			if ( ! $handler->supports_refusal() ) {
				return false;
			}

			// Accepted once for this very shipment: not offered again, whatever the stored delivery state still says (the
			// carrier's status may lag behind its own answer).
			if ( Carrier_Cancel::has_refusal_requested( $order, Order_Automation::carrier_order_id( $order, $provider ) ) ) {
				return false;
			}

			$canonical = self::resolve_canonical_status( $order, $provider );

			return ! in_array( $canonical, self::CANCEL_RETIRED_STATUSES, true )
				&& in_array( $canonical, $handler->get_refusable_statuses(), true );
		}

		/**
		 * Whether the merchant's explicit confirmation must travel with the action's request (#1204): the paid
		 * refusal. The confirmation dialog of the orders page and of the order-edit metabox sends it once the
		 * merchant said yes; a request without it — a hand-made REST call, a stale page — is refused by the
		 * server before the carrier is called. Every other action is unaffected.
		 *
		 * @since 2.0.2
		 *
		 * @param string $action the action id.
		 * @return bool
		 */
		public static function requires_confirmation( string $action ): bool {
			return self::REFUSE === $action;
		}

		/**
		 * The reason a request must be stopped for want of the merchant's confirmation, or '' when it may go on —
		 * the ONE check the REST route and the metabox's admin-post route share (#1204).
		 *
		 * @since 2.0.2
		 *
		 * @param string $action    the action id.
		 * @param mixed  $confirmed the posted `confirmed` value, of unknown shape (`true`, `1`, `'1'`, `'true'` confirm).
		 * @return string
		 */
		public static function unconfirmed_reason( string $action, $confirmed ): string {
			if ( ! self::requires_confirmation( $action ) ) {
				return '';
			}

			if ( true === $confirmed || 1 === $confirmed || '1' === $confirmed || 'true' === $confirmed ) {
				return '';
			}

			return __( 'Подтвердите отказ: возврат платный.', 'woodev-plugin-framework' );
		}

		/**
		 * Performs one action against the carrier's shipment handler — the ONE place an action id
		 * becomes a carrier call, shared by the row / bulk routes ({@see \Woodev\Framework\Shipping\Rest_Api\Orders_Controller})
		 * the order-edit metabox ({@see \Woodev\Framework\Shipping\Admin\Shipping_Admin_Order}, #1016) and the
		 * wizard's «сразу выгрузить перевозчику» ({@see Order_Editor::export_created()}, #710 D6). A rule that
		 * belongs to performing an action is added HERE, never in a caller.
		 *
		 * Does NOT gate: the caller has already asked {@see self::is_offered()} (each keeps its own
		 * refusal wording), and catches what the carrier call throws — the routes log it and answer
		 * their own generic sentence.
		 *
		 * Every verb returns an {@see Action_Result} (card #872), so a failure carries the carrier's
		 * own text. A carrier response with no id (card #860) is a failure there too, with no text.
		 * The framework itself performs only its own three verbs — export / cancel / update. Anything
		 * else is a carrier extra declared via the `woodev_shipping_order_actions` filter
		 * ({@see self::for_order()}); the `default:` branch below is the matching PERFORMING-side
		 * extension point, so such an action is not merely advertised but actually executed by the
		 * carrier plugin that declared it. An action nothing hooks still fails honestly rather than
		 * reporting a fake success.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Round 2 (MEDIUM 3): the `default:` branch applies the
		 *              `woodev_shipping_perform_order_action` filter instead of unconditionally
		 *              returning false, so a carrier's own declared action can actually be performed.
		 * @since 2.0.2 Card #872: returns an {@see Action_Result}; the filter's value is an
		 *              `Action_Result` too, and anything else a callback returns is treated as a failure.
		 * @since 2.0.2 Card #974: moved here from `Orders_Controller::dispatch_action()`, unchanged.
		 * @since 2.0.2 Card #1180: takes the action's validated `$payload`, passed on to the filter below.
		 *
		 * @param Abstract_Shipment_Handler $handler  handler resolved for the order's carrier.
		 * @param \WC_Order                 $order    the order.
		 * @param string                    $action   one of this class's action ids, or a carrier extra.
		 * @param Orders_Provider           $provider the matched carrier descriptor.
		 * @param array<string,mixed>       $payload  the values the action's declared `fields` collected, ALREADY validated by
		 *                                            the caller against that declaration ({@see self::resolve_payload()}); `[]`
		 *                                            for an action that declares none. Reaches a carrier's own action through the
		 *                                            `woodev_shipping_perform_order_action` filter; the framework's three verbs
		 *                                            take no input and ignore it.
		 * @return Action_Result
		 */
		public function perform( Abstract_Shipment_Handler $handler, \WC_Order $order, string $action, Orders_Provider $provider, array $payload = [] ): Action_Result {
			switch ( $action ) {
				case self::EXPORT:
					[ $settlement, $settlement_provider ] = $this->resolve_popular_settlement_context( $order );

					return $handler->export( $order, $settlement, $settlement_provider );

				case self::CANCEL:
					return $handler->cancel( $order );

				case self::REFUSE:
					return $this->refuse( $handler, $order, $provider );

				case self::UPDATE:
					// A carrier overrides update(), so the framework marks the call from outside: it gets the «export» timeout (#954).
					return \Woodev_API_Request_Purpose::run( \Woodev_API_Request_Purpose::EXPORT, fn() => $handler->update( $order ) );

				default:
					/**
					 * Performs a carrier's own extra order action (one declared via the
					 * `woodev_shipping_order_actions` filter, not one of the framework's
					 * own export/update/cancel verbs).
					 *
					 * The carrier plugin that declared the action is the only one that
					 * knows how to perform it, so it hooks this filter, checks `$action`
					 * (and, if it serves more than one carrier, `$provider`) is its own,
					 * performs the action against its own API, and returns whether it
					 * succeeded. Defaults to a failure with no text, so an action nothing
					 * hooks still fails honestly instead of reporting success it never
					 * earned. A callback returns {@see Action_Result::success()} or
					 * {@see Action_Result::failure()} with the carrier's reason — shown to
					 * the merchant, prefixed with the carrier name, never to a buyer.
					 *
					 * @since 2.0.2
					 * @since 2.0.2 Card #872: the value is an {@see Action_Result}, not a bool.
					 * @since 2.0.2 Card #1180: the fifth argument, `$payload` — what the action's declared `fields` collected.
					 *
					 * @param Action_Result       $result   the outcome so far; default a failure with no text.
					 * @param string              $action   the action id, as declared by the carrier's filter.
					 * @param \WC_Order           $order    the order the action was requested for.
					 * @param Orders_Provider     $provider the matched carrier descriptor.
					 * @param array<string,mixed> $payload  the validated values, keyed by field id: a `date` is `'Y-m-d'`, a
					 *                                      `select` an option value, a `time_range` `[ 'from' => 'H:i', 'to' => 'H:i' ]`,
					 *                                      a `textarea` a string; an empty optional field is `''` (a time range with
					 *                                      empty `from` and `to`). `[]` for an action that declares no fields.
					 */
					$result = apply_filters( 'woodev_shipping_perform_order_action', Action_Result::failure(), $action, $order, $provider, $payload );

					return $result instanceof Action_Result ? $result : Action_Result::failure();
			}
		}

		/**
		 * Refuses the order's shipment at the carrier and says what happened on the order (#1204): a private note
		 * for the outcome either way. The one place the note is written, so the orders page and the order-edit
		 * metabox leave the same trail. A success also clears the «не отменён у перевозчика» marker — the
		 * merchant has dealt with the live shipment the failed cancellation left.
		 *
		 * @since 2.0.2
		 *
		 * @param Abstract_Shipment_Handler $handler the carrier's handler.
		 * @param \WC_Order                 $order   the order.
		 * @param Orders_Provider           $provider the matched carrier.
		 * @return Action_Result
		 * @throws \Throwable Whatever the carrier call throws, after the note is written.
		 */
		private function refuse( Abstract_Shipment_Handler $handler, \WC_Order $order, Orders_Provider $provider ): Action_Result {
			try {
				$result = $handler->refuse( $order );
			} catch ( \Throwable $exception ) {
				$order->add_order_note( __( 'Не удалось оформить отказ от посылки: сервис перевозчика недоступен', 'woodev-plugin-framework' ) );

				throw $exception;
			}

			if ( $result->is_success() ) {
				Carrier_Cancel::clear_failed( $order );
				// The framework's own idempotency record (the carrier keeps no bookkeeping of it): bound to this shipment's
				// carrier id, so a retry finds the refusal closed, and a re-export (a new id) opens it afresh.
				Carrier_Cancel::mark_refusal_requested( $order, Order_Automation::carrier_order_id( $order, $provider ) );
				$order->add_order_note( __( 'Оформлен отказ от посылки: она вернётся к вам, возврат платный', 'woodev-plugin-framework' ) );

				return $result;
			}

			$order->add_order_note(
				sprintf(
					/* translators: %s: the carrier's reason for refusing the refusal */
					__( 'Не удалось оформить отказ от посылки: %s', 'woodev-plugin-framework' ),
					'' !== $result->get_message() ? $result->get_message() : __( 'перевозчик не назвал причину', 'woodev-plugin-framework' )
				)
			);

			return $result;
		}

		/**
		 * Resolves the popular-settlements enrolment context for an order about to be exported —
		 * the settlement the customer picked at checkout and the SAME provider that produced it
		 * (#488 slice 2).
		 *
		 * Reads the framework's own shared singleton ({@see Location_Provider_Registry::instance()}).
		 * An order made in the admin has no such candidate (the admin is not the buyer), so the
		 * answer is then `[ null, null ]`. It returns `[ null, null ]` too when the provider that
		 * produced the candidate is no longer registered — the export still runs, just without
		 * enrolment.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Card #974: moved here from `Orders_Controller`, unchanged.
		 * @since 2.0.2 Card #1016: the order-edit metabox's own copy is gone — this is the only one.
		 *
		 * @param \WC_Order $order the order about to be exported.
		 * @return array{0: Location_Record|null, 1: Location_Provider|null}
		 */
		private function resolve_popular_settlement_context( \WC_Order $order ): array {
			$settlement = Location_Provider_Registry::instance()->popular_settlement_store()->recall_candidate( $order );

			if ( null === $settlement ) {
				return [ null, null ];
			}

			$provider = Location_Provider_Registry::instance()->get_providers()[ $settlement->provider_id() ] ?? null;

			return [ $settlement, $provider ];
		}

		/**
		 * The ONE editable-state policy of the admin order wizard (#710 spec D5, card #968):
		 * whether a row of the orders page may still be edited.
		 *
		 * Read by the row action AND by the update / load routes, so the button and the server
		 * can never disagree — the routes re-evaluate it on every call and never trust the
		 * client's row (a stale row is exactly the race D5 names).
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order            $order    the order.
		 * @param Orders_Provider|null $provider the matched carrier, or null when the order is not a
		 *                                        row of this page.
		 * @return bool
		 */
		public static function is_editable( \WC_Order $order, ?Orders_Provider $provider ): bool {
			return '' === self::not_editable_reason( $order, $provider );
		}

		/**
		 * Returns the user holding a live native edit lock for another manager.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order to inspect.
		 * @return array{user_id:int,display_name:string}|null the other lock owner, or null.
		 */
		public static function edit_lock_owner( \WC_Order $order ): ?array {
			return Order_Edit_Lock::get_owner( $order );
		}

		/**
		 * The sentence a refusal or a greyed-out button gives when another manager holds the
		 * order's edit lock — or `''` when none does (#1000).
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order to inspect.
		 * @return string a sentence for the manager; empty when the order is not locked by another.
		 */
		public static function edit_locked_reason( \WC_Order $order ): string {
			$lock_owner = self::edit_lock_owner( $order );

			return null === $lock_owner ? '' : self::locked_by_message( $lock_owner['display_name'] );
		}

		/**
		 * «Этот заказ уже редактируется пользователем {имя}» — ONE sentence for the row button, the
		 * server's refusals and the wizard's own lock error.
		 *
		 * @since 2.0.2
		 *
		 * @param string $display_name display name of the manager holding the lock.
		 * @return string
		 */
		public static function locked_by_message( string $display_name ): string {
			return sprintf(
				/* translators: %s: display name of the manager currently editing the order. */
				__( 'This order is already being edited by %s', 'woodev-plugin-framework' ),
				$display_name
			);
		}

		/**
		 * Marks one built action as unusable because another manager holds the order's lock.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed>                    $action     a built action.
		 * @param array{user_id:int,display_name:string} $lock_owner the manager holding the lock.
		 * @return array<string,mixed>
		 */
		private static function lock_action( array $action, array $lock_owner ): array {
			$action['disabled']   = true;
			$action['lock_owner'] = $lock_owner['display_name'];
			$action['title']      = self::locked_by_message( $lock_owner['display_name'] );

			return $action;
		}

		/**
		 * Refreshes the current manager's native edit lock.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order to lock.
		 * @return bool whether the native backend accepted the lock.
		 */
		public static function refresh_edit_lock( \WC_Order $order ): bool {
			return Order_Edit_Lock::refresh( $order );
		}

		/**
		 * Why an order cannot be edited, in one merchant-readable sentence — or `''` when it can.
		 *
		 * Editable means ALL of: the order is a row of this page (`$provider` resolved, spec O3);
		 * it was not exported to the carrier (the carrier-order-id meta the «Новые» tab reads,
		 * {@see self::is_exported()}; spec O4 — cancel the export first); its status is not final
		 * ({@see self::FINAL_STATUSES}); and the delivery has not reached an end state
		 * ({@see self::CANCEL_RETIRED_STATUSES} — the framework has no other final-state API,
		 * `Delivery_Status` only names the canonical states).
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order            $order    the order.
		 * @param Orders_Provider|null $provider the matched carrier, or null.
		 * @return string a sentence for the manager; empty when the order is editable.
		 */
		public static function not_editable_reason( \WC_Order $order, ?Orders_Provider $provider ): string {
			if ( null === $provider ) {
				return __( 'Для этого заказа не удалось определить перевозчика.', 'woodev-plugin-framework' );
			}

			if ( self::is_exported( $order, $provider ) ) {
				return __( 'Заказ уже выгружен перевозчику — чтобы изменить его, сначала отмените выгрузку.', 'woodev-plugin-framework' );
			}

			if ( in_array( $order->get_status(), self::FINAL_STATUSES, true ) ) {
				return sprintf(
					/* translators: %s: WooCommerce order status name, e.g. "Выполнен". */
					__( 'Заказ в статусе «%s» — редактировать его нельзя.', 'woodev-plugin-framework' ),
					wc_get_order_status_name( $order->get_status() )
				);
			}

			// The framework's own cancellation marker does not count: a cancelled shipment may be edited and exported again.
			$canonical = self::resolve_canonical_status( $order, $provider, false );

			if ( in_array( $canonical, self::CANCEL_RETIRED_STATUSES, true ) ) {
				return sprintf(
					/* translators: %s: canonical delivery status label, e.g. "Доставлено". */
					__( 'Отправление уже в конечном статусе «%s» — редактировать заказ нельзя.', 'woodev-plugin-framework' ),
					Delivery_Status::label( $canonical )
				);
			}

			return '';
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
			$locked_reason = self::edit_locked_reason( $order );

			if ( '' !== $locked_reason ) {
				return $locked_reason;
			}

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

				case self::REFUSE:
					if ( ! $is_exported ) {
						return __( 'Заказ ещё не выгружен перевозчику — отказываться не от чего.', 'woodev-plugin-framework' );
					}

					if ( Carrier_Cancel::has_refusal_requested( $order, Order_Automation::carrier_order_id( $order, $provider ) ) ) {
						return __( 'Отказ от этой посылки уже оформлен — ждём её возврата.', 'woodev-plugin-framework' );
					}

					return __( 'Отказ можно оформить только для посылки, которая уже в пути, и только если перевозчик это умеет.', 'woodev-plugin-framework' );
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
		 * Public since #1007: the background cancel asks the same question before it calls the carrier.
		 *
		 * @param \WC_Order       $order    order.
		 * @param Orders_Provider $provider matched carrier.
		 * @return bool
		 */
		public static function is_exported( \WC_Order $order, Orders_Provider $provider ): bool {
			$meta_key = $provider->get_carrier_order_id_meta_key();

			if ( null === $meta_key ) {
				return false;
			}

			return '' !== (string) \Woodev_Order_Compatibility::get_order_meta( $order, $meta_key );
		}

		/**
		 * Resolves the order's canonical delivery status — the ONE resolution every reader shares
		 * (#1037): the orders page row ({@see \Woodev\Framework\Shipping\Admin\Orders\Order_Row_Builder}, and
		 * through it the order-edit metabox), the cancel gate, the edit gate and the background
		 * cancel's order note. Its database-side twin is
		 * {@see \Woodev\Framework\Shipping\Admin\Orders\Orders_Query}'s delivery-status filter, which
		 * must agree with it order by order.
		 *
		 * The carrier's raw status goes through {@see Delivery_Status::resolve()}, EXCEPT that an order
		 * whose shipment the framework recorded as cancelled ({@see Shipment_Cancellation}) resolves
		 * {@see Delivery_Status::CANCELLED} whatever the raw status still says — the raw status and its
		 * label are kept beside it. A provider with no status meta key still honours the marker (it is
		 * the framework's own fact, not the carrier's); an order with no provider at all has no carrier
		 * to speak for and resolves unknown.
		 *
		 * `$honour_cancellation` is `false` only for the edit gate ({@see self::not_editable_reason()}):
		 * a cancelled shipment has no live carrier order any more, so editing the order and exporting it
		 * again is exactly what the merchant may do next.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Card #1037: the single shared resolver; the row builder no longer keeps a copy.
		 *
		 * @param \WC_Order            $order               order.
		 * @param Orders_Provider|null $provider            matched carrier, or null.
		 * @param bool                 $honour_cancellation false => ignore the framework's cancellation marker.
		 * @return array{canonical:string,canonical_label:string,raw:?string,raw_label:?string}
		 */
		public static function resolve_delivery_status( \WC_Order $order, ?Orders_Provider $provider, bool $honour_cancellation = true ): array {
			if ( null === $provider ) {
				return Delivery_Status::resolve( null, [] );
			}

			$cancelled = $honour_cancellation && Shipment_Cancellation::is_cancelled( $order );

			if ( null === $provider->get_status_meta_key() ) {
				return Delivery_Status::resolve( null, [], [], $cancelled );
			}

			$raw = (string) \Woodev_Order_Compatibility::get_order_meta( $order, $provider->get_status_meta_key() );

			return Delivery_Status::resolve( '' !== $raw ? $raw : null, $provider->get_status_map(), $provider->get_status_labels(), $cancelled );
		}

		/**
		 * Resolves the order's canonical delivery status for the cancel gate and the order notes —
		 * the `canonical` of {@see self::resolve_delivery_status()}.
		 *
		 * Public since #1007: the background cancel names this status in its order note.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Card #1037: reads {@see self::resolve_delivery_status()}, so it agrees with the row.
		 *
		 * @param \WC_Order       $order               order.
		 * @param Orders_Provider $provider            matched carrier.
		 * @param bool            $honour_cancellation false => ignore the framework's cancellation marker.
		 * @return string one of {@see Delivery_Status::canonical_states()} or
		 *                {@see Delivery_Status::UNKNOWN}.
		 */
		public static function resolve_canonical_status( \WC_Order $order, Orders_Provider $provider, bool $honour_cancellation = true ): string {
			return self::resolve_delivery_status( $order, $provider, $honour_cancellation )['canonical'];
		}

		/**
		 * Builds one action entry.
		 *
		 * @since 2.0.2
		 *
		 * @param string $action      one of the action ids.
		 * @param string $label       button text.
		 * @param string $title       tooltip; '' when none.
		 * @param bool   $destructive true => the client confirms first and draws the button red.
		 * @param string $icon        Dashicons slug (no `dashicons-` prefix); '' => the entry carries none.
		 * @return array<string,mixed>
		 */
		private static function build_action( string $action, string $label, string $title, bool $destructive, string $icon = '' ): array {
			$built = [
				'action'      => $action,
				'label'       => $label,
				'title'       => $title,
				'destructive' => $destructive,
			];

			if ( '' !== $icon ) {
				$built['icon'] = $icon;
			}

			return $built;
		}

		/**
		 * Whether `$action` is a carrier document download ({@see self::DOCUMENTS}) — a client-side action that
		 * is fetched through the documents REST route and never posted to an action route.
		 *
		 * @since 2.0.2
		 *
		 * @param string $action the action id.
		 * @return bool
		 */
		public static function is_document( string $action ): bool {
			return in_array( $action, self::DOCUMENTS, true );
		}

		/**
		 * The icon slug an action declares, or '' when it declares none (or an unusable one).
		 *
		 * A slug is a Dashicons name without the `dashicons-` prefix — lowercase letters, digits and dashes —
		 * because both surfaces draw it as `dashicons-{slug}`: the orders page next to the REST row, the
		 * order-edit metabox from the PHP view. Anything else is dropped rather than echoed into a class
		 * attribute.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $icon the declared value, of unknown shape.
		 * @return string
		 */
		public static function sanitize_icon( $icon ): string {
			return is_string( $icon ) && 1 === preg_match( '/^[a-z][a-z0-9-]{0,63}$/', $icon ) ? $icon : '';
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

				if ( isset( $action['disabled'] ) ) {
					$sanitized[ count( $sanitized ) - 1 ]['disabled'] = (bool) $action['disabled'];
				}

				if ( isset( $action['lock_owner'] ) && is_string( $action['lock_owner'] ) ) {
					$sanitized[ count( $sanitized ) - 1 ]['lock_owner'] = $action['lock_owner'];
				}

				// #1180: an action that needs input declares it; an action with none keeps exactly the shape it always had.
				$fields = Order_Action_Fields::sanitize( $action['fields'] ?? null );

				if ( [] !== $fields ) {
					$sanitized[ count( $sanitized ) - 1 ]['fields'] = $fields;
				}

				// An action may declare its own icon (a Dashicons slug); one without it gets the neutral fallback on the client.
				$icon = self::sanitize_icon( $action['icon'] ?? null );

				if ( '' !== $icon ) {
					$sanitized[ count( $sanitized ) - 1 ]['icon'] = $icon;
				}

				// s164: a destructive action may word its own confirmation; without it both surfaces ask their generic question.
				$confirm = $sanitized[ count( $sanitized ) - 1 ]['destructive'] ? Order_Action_Fields::sanitize_help( $action['confirm'] ?? null ) : '';

				if ( '' !== $confirm ) {
					$sanitized[ count( $sanitized ) - 1 ]['confirm'] = $confirm;
				}
			}

			return $sanitized;
		}
	}

endif;
