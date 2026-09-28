<?php
/**
 * Shipping orders — admin order wizard service: create / update / load
 *
 * @since 2.0.2
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Admin\Orders;

use Woodev\Framework\Shipping\Order\Order_Marker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Admin\\Orders\\Order_Editor' ) ) :

	/**
	 * Creates, updates and loads the WooCommerce orders the admin order wizard works on
	 * (#710 spec D4 / D5, card #968).
	 *
	 * **One writer.** An order made here is written exactly like a checkout one: the carrier's
	 * own checkout handler persists the managed fields
	 * ({@see \Woodev\Framework\Shipping\Checkout\Checkout_Handler::persist_values()}), and the
	 * carrier marker goes through {@see Order_Marker} inside that same call — so a manager's
	 * order appears on the orders page by the very rule a customer's does. The checkout hooks
	 * (`…_checkout_field_saved` / `…_checkout_data_saved` / `…_checkout_processed`) stay
	 * CHECKOUT-ONLY; this path announces itself with `…_admin_order_saved`
	 * ({@see \Woodev\Framework\Shipping\Checkout\Checkout_Handler::announce_admin_order_saved()}).
	 *
	 * **Sequence** (create): `wc_create_order()` → addresses → lines at the edited prices → ONE
	 * shipping line from the chosen rate (cost as the manager left it, rate meta copied as
	 * `WC_Checkout` does) → payment method → persistence + marker → `calculate_totals()` → the
	 * status. WooCommerce's own status transition does stock and e-mail (spec C2/C4).
	 *
	 * **E-mail — measured, #962 I0 contradiction 1.** `set_status( 'processing' | 'on-hold' |
	 * 'completed' )` on a fresh order already sends «New order» (and the customer's mail) through
	 * WooCommerce's own `pending_to_*_notification` actions. An explicit extra
	 * `WC_Email_New_Order::trigger()` would DOUBLE-SEND, so none is issued; a `pending` order sends
	 * nothing, exactly like a checkout order awaiting payment.
	 *
	 * **Errors are returned, not thrown**: every public method answers with its result or a
	 * `\WP_Error` whose `data['status']` is the HTTP status of the transport contract — 404 unknown
	 * order or not a row of the orders page, 409 not editable (D5), 422 validation errors as data
	 * (`data['errors']`, see {@see Order_Payload_Validator}), 500 anything the service did not foresee.
	 *
	 * @since 2.0.2
	 */
	class Order_Editor {

		/**
		 * Carrier registry.
		 *
		 * @since 2.0.2
		 *
		 * @var Orders_Registry
		 */
		private $registry;

		/**
		 * Payload validator.
		 *
		 * @since 2.0.2
		 *
		 * @var Order_Payload_Validator
		 */
		private $validator;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param Orders_Registry|null         $registry  carrier registry; defaults to the shared singleton.
		 * @param Order_Payload_Validator|null $validator payload validator; defaults to one over `$registry`.
		 */
		public function __construct( ?Orders_Registry $registry = null, ?Order_Payload_Validator $validator = null ) {
			$this->registry  = $registry ?? Orders_Registry::instance();
			$this->validator = $validator ?? new Order_Payload_Validator( $this->registry );
		}

		/**
		 * Creates an order.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $payload the request body ({@see Order_Payload_Validator} for its shape).
		 * @return \WC_Order|\WP_Error the saved order, or the transport-contract error.
		 */
		public function create( array $payload ) {
			$checked = $this->validator->validate( $payload, false );

			if ( [] !== $checked['errors'] ) {
				return self::validation_error( $checked['errors'] );
			}

			$data        = $checked['data'];
			$customer_id = $this->resolve_customer( $data );

			if ( $customer_id instanceof \WP_Error ) {
				return $customer_id;
			}

			$order = wc_create_order(
				[
					'status'      => 'pending',
					'customer_id' => $customer_id,
					'created_via' => 'admin',
				]
			);

			if ( ! $order instanceof \WC_Order ) {
				return self::error( 'woodev_shipping_order_create_failed', __( 'Не удалось создать заказ.', 'woodev-plugin-framework' ), 500 );
			}

			try {
				$this->write( $order, $data, false );
			} catch ( \Throwable $exception ) {
				self::log( sprintf( 'creating an order failed: %s', $exception->getMessage() ) );

				// A half-written order would show on the page and confuse the manager: it never existed.
				$order->delete( true );

				return self::error( 'woodev_shipping_order_create_failed', __( 'Не удалось создать заказ.', 'woodev-plugin-framework' ), 500 );
			}

			return self::fresh( $order );
		}

		/**
		 * Updates an order, in place.
		 *
		 * Refuses unless {@see Order_Actions::is_editable()} holds — checked TWICE: first for the
		 * 404 / 409 answers ahead of the 422 ones, then again on a fresh read right before the
		 * writes, because the row can have been exported while the manager typed (spec D5, the
		 * stale-row race).
		 *
		 * @since 2.0.2
		 *
		 * @param int                  $order_id the order.
		 * @param array<string, mixed> $payload  the request body.
		 * @return \WC_Order|\WP_Error the saved order, or the transport-contract error.
		 */
		public function update( int $order_id, array $payload ) {
			$row = $this->find_editable_row( $order_id );

			if ( $row instanceof \WP_Error ) {
				return $row;
			}

			$checked = $this->validator->validate( $payload, true );

			if ( [] !== $checked['errors'] ) {
				return self::validation_error( $checked['errors'] );
			}

			$row = $this->find_editable_row( $order_id );

			if ( $row instanceof \WP_Error ) {
				return $row;
			}

			$data = $checked['data'];

			$customer_id = $this->resolve_customer( $data );

			if ( $customer_id instanceof \WP_Error ) {
				return $customer_id;
			}

			$order = $row['order'];
			$order->set_customer_id( $customer_id );

			try {
				$this->write( $order, $data, true );
			} catch ( \Throwable $exception ) {
				self::log( sprintf( 'updating order %1$d failed: %2$s', $order_id, $exception->getMessage() ) );

				return self::error( 'woodev_shipping_order_update_failed', __( 'Не удалось сохранить заказ. Проверьте его в списке — часть изменений могла примениться.', 'woodev-plugin-framework' ), 500 );
			}

			return self::fresh( $order );
		}

		/**
		 * The wizard's prefill for one order — everything the steps hold, read back.
		 *
		 * Gated exactly like {@see self::update()}: an order that cannot be edited has nothing to
		 * prefill.
		 *
		 * @since 2.0.2
		 *
		 * @param int $order_id the order.
		 * @return array<string, mixed>|\WP_Error the prefill, or the transport-contract error.
		 */
		public function load( int $order_id ) {
			$row = $this->find_editable_row( $order_id );

			if ( $row instanceof \WP_Error ) {
				return $row;
			}

			return $this->build_prefill( $row['order'], $row['provider'] );
		}

		/**
		 * Finds an order of the orders page and applies the editable-state policy (spec D5).
		 *
		 * Reads through {@see \WC_Order::read_meta_data()} with `$force_read`, so a marker or an
		 * export id written a moment ago by another request is seen.
		 *
		 * @since 2.0.2
		 *
		 * @param int $order_id the order id.
		 * @return array{order: \WC_Order, provider: Orders_Provider}|\WP_Error 404 when the order does
		 *         not exist or is not a row of the page (a shop order of any other kind is not this
		 *         page's to reveal), 409 when the policy refuses it.
		 */
		private function find_editable_row( int $order_id ) {
			$order = $order_id > 0 ? wc_get_order( $order_id ) : false;

			if ( ! $order instanceof \WC_Order ) {
				return self::unknown_order();
			}

			$order->read_meta_data( true );

			$provider = $this->registry->resolve_provider_for_order( $order );

			if ( null === $provider ) {
				return self::unknown_order();
			}

			$reason = Order_Actions::not_editable_reason( $order, $provider );

			if ( '' !== $reason ) {
				return self::error( 'woodev_shipping_order_not_editable', $reason, 409 );
			}

			return [
				'order'    => $order,
				'provider' => $provider,
			];
		}

		/**
		 * The customer id to put on the order — creating the account when the manager asked
		 * (spec O11: WooCommerce creates the user and sends its own e-mail).
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $data the validated payload.
		 * @return int|\WP_Error
		 */
		private function resolve_customer( array $data ) {
			if ( ! $data['customer']['create_account'] ) {
				return (int) $data['customer']['id'];
			}

			$created = wc_create_new_customer(
				(string) $data['billing']['email'],
				'',
				'',
				[
					'first_name' => (string) $data['billing']['first_name'],
					'last_name'  => (string) $data['billing']['last_name'],
				]
			);

			if ( is_wp_error( $created ) ) {
				return self::validation_error(
					[
						[
							'field'   => 'billing.email',
							'code'    => 'account_failed',
							'message' => wp_strip_all_tags( $created->get_error_message() ),
						],
					]
				);
			}

			return (int) $created;
		}

		/**
		 * Writes the validated payload onto an order — the create and the update share every step.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order            $order     the order.
		 * @param array<string, mixed> $data      the validated payload.
		 * @param bool                 $is_update whether the order already existed.
		 * @return void
		 *
		 * @throws \Exception When something below fails; the caller decides what the failure means.
		 */
		private function write( \WC_Order $order, array $data, bool $is_update ): void {
			$total_before = (float) $order->get_total();
			$was_paid     = $is_update && $order->is_paid();

			$order->set_address( $data['billing'], 'billing' );
			$order->set_address( $data['shipping'], 'shipping' );

			$this->apply_payment_method( $order, (string) $data['payment_method'] );

			$touched = $this->apply_items( $order, $data['items'], $is_update );

			$this->apply_shipping_line( $order, $data['shipping_line'] );

			$order->save();

			if ( $is_update ) {
				foreach ( $touched as $item ) {
					// WooCommerce's own stock follow-up for an edited line of an order whose stock
					// was already reduced (what its order screen calls after saving items).
					wc_maybe_adjust_line_item_product_stock( $item );
				}
			}

			$written = $this->persist( $order, $data, $is_update );

			$order->calculate_totals( true );

			$this->apply_status( $order, $data['status'] );

			if ( $was_paid && abs( (float) $order->get_total() - $total_before ) > 0.0001 ) {
				$order->add_order_note(
					sprintf(
						/* translators: 1: the order total before the edit, 2: the total after it. */
						__( 'Заказ изменён из админки: сумма была %1$s, стала %2$s. Возврат или доплата делаются штатными средствами WooCommerce.', 'woodev-plugin-framework' ),
						self::money( $total_before, $order ),
						self::money( (float) $order->get_total(), $order )
					)
				);
			}

			$plugin  = $this->registry->get_provider_plugin( $data['shipping_line']['provider']->get_id() );
			$handler = null !== $plugin ? $plugin->get_checkout_handler() : null;

			if ( null !== $handler ) {
				$handler->announce_admin_order_saved(
					$order,
					$written,
					[
						'rate'           => self::rate_context( $data['shipping_line'] ),
						'pickup_point'   => $data['pickup_point'],
						'carrier_fields' => $data['carrier_fields'],
						'is_update'      => $is_update,
					]
				);
			}
		}

		/**
		 * Sets the payment method — its id and its title — from a registered gateway.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order   the order.
		 * @param string    $gateway gateway id, '' for none.
		 * @return void
		 */
		private function apply_payment_method( \WC_Order $order, string $gateway ): void {
			if ( '' === $gateway ) {
				$order->set_payment_method( '' );
				$order->set_payment_method_title( '' );

				return;
			}

			$gateways = WC()->payment_gateways()->payment_gateways();

			if ( isset( $gateways[ $gateway ] ) ) {
				$order->set_payment_method( $gateways[ $gateway ] );

				return;
			}

			$order->set_payment_method( $gateway );
		}

		/**
		 * Reconciles the order's product lines with the wanted ones.
		 *
		 * A wanted line naming an existing `item_id` of the SAME product is edited in place
		 * (quantity and price), so the line keeps its identity, its meta and its stock bookkeeping;
		 * anything else is a new line, and an existing line nobody asked for is removed — after the
		 * stock it holds is released, the way WooCommerce's own order screen removes one.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order                        $order     the order.
		 * @param array<int, array<string, mixed>> $wanted    validated lines.
		 * @param bool                             $is_update whether the order already existed.
		 * @return \WC_Order_Item_Product[] the lines that were added or edited.
		 */
		private function apply_items( \WC_Order $order, array $wanted, bool $is_update ): array {
			/** @var array<int, \WC_Order_Item_Product> $existing */
			$existing = $is_update ? $order->get_items( 'line_item' ) : [];
			$kept     = [];
			$touched  = [];

			foreach ( $wanted as $line ) {
				$item = null;

				if ( $line['item_id'] > 0 && isset( $existing[ $line['item_id'] ] ) ) {
					$candidate = $existing[ $line['item_id'] ];

					if ( (int) $candidate->get_product_id() === $line['product_id'] && (int) $candidate->get_variation_id() === $line['variation_id'] ) {
						$item                          = $candidate;
						$kept[ (int) $candidate->get_id() ] = true;
					}
				}

				if ( null === $item ) {
					$item = new \WC_Order_Item_Product();
					$item->set_product( $line['product'] );

					if ( $line['variation_id'] > 0 ) {
						$item->set_variation( $line['product']->get_variation_attributes() );
					}

					$order->add_item( $item );
				}

				$this->price_line( $item, $line );

				$touched[] = $item;
			}

			foreach ( $existing as $item_id => $old ) {
				if ( isset( $kept[ (int) $item_id ] ) ) {
					continue;
				}

				wc_maybe_adjust_line_item_product_stock( $old, 0 );
				$order->remove_item( (int) $item_id );
			}

			return $touched;
		}

		/**
		 * Quantity and price of one line. The price is per unit, before tax; absent, an edited line
		 * keeps the unit price it had and a new one takes the product's own (spec O8).
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order_Item_Product $item the line.
		 * @param array<string, mixed>   $line the validated wanted line.
		 * @return void
		 */
		private function price_line( \WC_Order_Item_Product $item, array $line ): void {
			$quantity = (int) $line['quantity'];

			if ( null !== $line['price'] ) {
				$unit = (float) $line['price'];
			} elseif ( $item->get_id() > 0 && (int) $item->get_quantity() > 0 ) {
				$unit = (float) $item->get_subtotal() / (int) $item->get_quantity();
			} else {
				$unit = (float) wc_get_price_excluding_tax( $line['product'] );
			}

			$total = wc_format_decimal( round( $unit * $quantity, wc_get_rounding_precision() ) );

			$item->set_quantity( $quantity );
			$item->set_subtotal( $total );
			$item->set_total( $total );
		}

		/**
		 * Replaces the order's shipping line with the chosen rate — cost as the manager left it, the
		 * rate's meta copied onto the line the way `WC_Checkout` copies it.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order            $order the order.
		 * @param array<string, mixed> $line  the validated shipping line.
		 * @return void
		 */
		private function apply_shipping_line( \WC_Order $order, array $line ): void {
			foreach ( array_keys( $order->get_shipping_methods() ) as $existing_id ) {
				$order->remove_item( (int) $existing_id );
			}

			$item = new \WC_Order_Item_Shipping();
			$item->set_method_title( (string) $line['label'] );
			$item->set_method_id( (string) $line['method_id'] );
			$item->set_instance_id( (int) $line['instance_id'] );
			$item->set_total( (string) $line['cost'] );

			foreach ( $line['meta'] as $key => $value ) {
				$item->add_meta_data( (string) $key, $value, true );
			}

			$order->add_item( $item );
		}

		/**
		 * The framework's own writes for the carrier — managed fields, carrier marker, full pickup
		 * point — through the SAME core checkout uses.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order            $order     the saved order, already carrying its shipping line.
		 * @param array<string, mixed> $data      the validated payload.
		 * @param bool                 $is_update whether the order already existed.
		 * @return array<string, mixed> the field values that were written (after the stale-pickup drop).
		 */
		private function persist( \WC_Order $order, array $data, bool $is_update ): array {
			$line     = $data['shipping_line'];
			$provider = $line['provider'];
			$point    = $data['pickup_point'];
			$plugin   = $this->registry->get_provider_plugin( $provider->get_id() );
			$handler  = null !== $plugin ? $plugin->get_checkout_handler() : null;
			$chosen   = $line['instance_id'] > 0 ? $line['method_id'] . ':' . $line['instance_id'] : $line['method_id'];

			$context = [
				'rate'           => self::rate_context( $line ),
				'pickup_point'   => $point,
				'carrier_fields' => $data['carrier_fields'],
				// A re-saved order re-runs the marker writers: a marker derived from the rate must follow the edit.
				'refresh'        => $is_update,
			];

			if ( $is_update ) {
				$this->drop_other_markers( $order, $provider );
			}

			$written = [];

			if ( null !== $handler ) {
				$raw = $data['fields'];

				if ( null !== $point ) {
					foreach ( $handler->pickup_field_ids() as $slot ) {
						$raw[ $slot ] = $point['id'];
					}
				}

				$written = $handler->persist_values( $order, $handler->sanitize_posted_data( $raw ), $chosen, null, $context );

				if ( $is_update ) {
					foreach ( $handler->pickup_field_ids() as $slot ) {
						if ( ! isset( $written[ $slot ] ) || '' === (string) $written[ $slot ] ) {
							\Woodev_Order_Compatibility::delete_order_meta( $order, $slot );
						}
					}
				}
			} else {
				// A carrier plugin with no checkout handler still gets its marker — through the same class.
				( new Order_Marker( $this->registry ) )->mark_order( $order, $context, $is_update );
			}

			$point_meta_key = $provider->get_pickup_point_meta_key();

			if ( null === $point && $is_update && null !== $point_meta_key ) {
				\Woodev_Order_Compatibility::delete_order_meta( $order, $point_meta_key );
			}

			$pickup_handler = null !== $plugin ? $plugin->get_pickup_handler() : null;

			if ( null !== $point && null !== $pickup_handler ) {
				$pickup_handler->persist_full_point( $order, $point['id'] );
			}

			return $written;
		}

		/**
		 * On an edit that moves an order to ANOTHER carrier, removes the previous carrier's marker —
		 * an order carries the marker of at most one carrier (#928).
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order       $order    the order.
		 * @param Orders_Provider $provider the carrier the order now belongs to.
		 * @return void
		 */
		private function drop_other_markers( \WC_Order $order, Orders_Provider $provider ): void {
			foreach ( $this->registry->get_providers() as $other ) {
				if ( $other->get_id() === $provider->get_id() || $other->get_marker_meta_key() === $provider->get_marker_meta_key() ) {
					continue;
				}

				if ( '' !== (string) \Woodev_Order_Compatibility::get_order_meta( $order, $other->get_marker_meta_key() ) ) {
					\Woodev_Order_Compatibility::delete_order_meta( $order, $other->get_marker_meta_key() );
				}
			}
		}

		/**
		 * Moves the order to the requested status, through WooCommerce's own transition.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order   $order  the order.
		 * @param string|null $status the requested status; null keeps the current one.
		 * @return void
		 */
		private function apply_status( \WC_Order $order, ?string $status ): void {
			if ( null === $status || $status === $order->get_status() ) {
				return;
			}

			// The transactional-mail hooks are registered when the mailer is first built, which a REST
			// request does not do by itself — and the transition below is what sends «New order».
			WC()->mailer();

			$order->set_status( $status, '', true );
			$order->save();
		}

		/**
		 * The prefill the wizard opens an edit with.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order       $order    the order.
		 * @param Orders_Provider $provider the order's carrier.
		 * @return array<string, mixed>
		 */
		private function build_prefill( \WC_Order $order, Orders_Provider $provider ): array {
			$plugin  = $this->registry->get_provider_plugin( $provider->get_id() );
			$handler = null !== $plugin ? $plugin->get_checkout_handler() : null;
			$fields  = null !== $handler ? $handler->read_values( $order ) : [];

			$items = [];

			foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
				/** @var \WC_Order_Item_Product $item */
				$quantity = max( 1, (int) $item->get_quantity() );

				$items[] = [
					'item_id'      => (int) $item_id,
					'product_id'   => (int) $item->get_product_id(),
					'variation_id' => (int) $item->get_variation_id(),
					'name'         => (string) $item->get_name(),
					'quantity'     => (int) $item->get_quantity(),
					'price'        => wc_format_decimal( round( (float) $item->get_subtotal() / $quantity, wc_get_rounding_precision() ) ),
				];
			}

			$shipping_line = null;

			foreach ( $order->get_shipping_methods() as $line ) {
				if ( ! in_array( $line->get_method_id(), $provider->get_method_ids(), true ) ) {
					continue;
				}

				$meta = [];

				foreach ( $line->get_meta_data() as $entry ) {
					if ( is_scalar( $entry->value ) ) {
						$meta[ (string) $entry->key ] = $entry->value;
					}
				}

				$instance_id   = (int) $line->get_instance_id();
				$shipping_line = [
					'method_id'   => (string) $line->get_method_id(),
					'instance_id' => $instance_id,
					'rate_id'     => $instance_id > 0 ? $line->get_method_id() . ':' . $instance_id : (string) $line->get_method_id(),
					'label'       => (string) $line->get_name(),
					'cost'        => (string) $line->get_total(),
					'meta'        => $meta,
				];

				break;
			}

			return [
				'order'          => [
					'id'          => (int) $order->get_id(),
					'number'      => (string) $order->get_order_number(),
					'status'      => (string) $order->get_status(),
					'status_name' => (string) wc_get_order_status_name( $order->get_status() ),
					'is_paid'     => (bool) $order->is_paid(),
					'total'       => (string) $order->get_total(),
					'currency'    => (string) $order->get_currency(),
				],
				'carrier'        => $provider->get_id(),
				'customer'       => [
					'id'             => (int) $order->get_customer_id(),
					'create_account' => false,
				],
				'billing'        => $this->read_address( $order, 'billing' ) + [ 'email' => (string) $order->get_billing_email() ],
				'shipping'       => $this->read_address( $order, 'shipping' ),
				'items'          => $items,
				'shipping_line'  => $shipping_line,
				'pickup_point'   => $this->read_pickup_point( $order, $provider, $handler, $fields ),
				'fields'         => $fields,
				// The carrier's own export fields are declared by the carrier (spec D7); the framework stores none of its own.
				'carrier_fields' => [],
				'payment_method' => (string) $order->get_payment_method(),
				'status'         => (string) $order->get_status(),
			];
		}

		/**
		 * One address block, read from the order's own getters.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order   the order.
		 * @param string    $section `billing` or `shipping`.
		 * @return array<string, string>
		 */
		private function read_address( \WC_Order $order, string $section ): array {
			$address = [];

			foreach ( [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone' ] as $key ) {
				$getter          = 'get_' . $section . '_' . $key;
				$address[ $key ] = is_callable( [ $order, $getter ] ) ? (string) $order->$getter() : '';
			}

			return $address;
		}

		/**
		 * The pickup point the order carries: the full record the carrier's key map stored, else the
		 * bare id kept in the handler's pickup-slot field, else none.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order                                                 $order    the order.
		 * @param Orders_Provider                                           $provider the order's carrier.
		 * @param \Woodev\Framework\Shipping\Checkout\Checkout_Handler|null $handler  the carrier's checkout handler.
		 * @param array<string, mixed>                                      $fields   the order's managed field values.
		 * @return array<string, mixed>|null
		 */
		private function read_pickup_point( \WC_Order $order, Orders_Provider $provider, $handler, array $fields ): ?array {
			$key = $provider->get_pickup_point_meta_key();

			if ( null !== $key ) {
				$stored = \Woodev_Order_Compatibility::get_order_meta( $order, $key );

				if ( is_array( $stored ) && isset( $stored['id'] ) && '' !== (string) $stored['id'] ) {
					return $stored;
				}
			}

			if ( null !== $handler ) {
				foreach ( $handler->pickup_field_ids() as $slot ) {
					if ( isset( $fields[ $slot ] ) && '' !== (string) $fields[ $slot ] ) {
						return [ 'id' => (string) $fields[ $slot ] ];
					}
				}
			}

			return null;
		}

		/**
		 * A money amount as plain text for an order note.
		 *
		 * @since 2.0.2
		 *
		 * @param float     $amount the amount.
		 * @param \WC_Order $order  the order, for its currency.
		 * @return string
		 */
		private static function money( float $amount, \WC_Order $order ): string {
			return html_entity_decode( wp_strip_all_tags( wc_price( $amount, [ 'currency' => $order->get_currency() ] ) ), ENT_QUOTES, 'UTF-8' );
		}

		/**
		 * The chosen rate in the shape a carrier's marker writer reads it
		 * ({@see Orders_Provider::mark_order()}, context key `rate`) — the validated shipping line
		 * without the provider object.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $line the validated shipping line.
		 * @return array<string, mixed>
		 */
		private static function rate_context( array $line ): array {
			return [
				'id'          => $line['rate_id'],
				'method_id'   => $line['method_id'],
				'instance_id' => $line['instance_id'],
				'label'       => $line['label'],
				'cost'        => $line['cost'],
				'meta'        => $line['meta'],
			];
		}

		/**
		 * Re-reads a saved order, so the caller sees what the datastore really holds.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order the order just written.
		 * @return \WC_Order
		 */
		private static function fresh( \WC_Order $order ): \WC_Order {
			$reread = wc_get_order( $order->get_id() );

			return $reread instanceof \WC_Order ? $reread : $order;
		}

		/**
		 * The 404 answer: no such order, or not one of this page's.
		 *
		 * @since 2.0.2
		 *
		 * @return \WP_Error
		 */
		private static function unknown_order(): \WP_Error {
			return self::error( 'woodev_shipping_orders_unknown_order', __( 'Заказ не найден.', 'woodev-plugin-framework' ), 404 );
		}

		/**
		 * The 422 answer: the problems as data, one entry per offending request field.
		 *
		 * @since 2.0.2
		 *
		 * @param array<int, array{field: string, code: string, message: string}> $errors the problems.
		 * @return \WP_Error
		 */
		private static function validation_error( array $errors ): \WP_Error {
			return new \WP_Error(
				'woodev_shipping_order_invalid',
				__( 'Заказ не сохранён: проверьте отмеченные поля.', 'woodev-plugin-framework' ),
				[
					'status' => 422,
					'errors' => $errors,
				]
			);
		}

		/**
		 * A transport-contract error.
		 *
		 * @since 2.0.2
		 *
		 * @param string $code    machine code.
		 * @param string $message sentence for the manager.
		 * @param int    $status  HTTP status.
		 * @return \WP_Error
		 */
		private static function error( string $code, string $message, int $status ): \WP_Error {
			return new \WP_Error( $code, $message, [ 'status' => $status ] );
		}

		/**
		 * Diagnostic line — what broke is a plugin problem the manager can do nothing about.
		 *
		 * @since 2.0.2
		 *
		 * @param string $message what went wrong.
		 * @return void
		 */
		private static function log( string $message ): void {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for the admin order editor; the manager only sees a generic failure.
			error_log( '[woodev] ' . $message );
		}
	}

endif;
