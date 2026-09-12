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
		 * Declares which of export/update/cancel are available on the row (card
		 * #824). Defaults to one wired against the framework's shared registry
		 * singleton — the same optional-dependency-defaults-to-a-singleton idiom
		 * {@see \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler}'s own
		 * constructor already uses for `$popular_settlement_store` — so an existing
		 * `new Order_Row_Builder()` call site keeps compiling unchanged.
		 *
		 * @since 2.0.2
		 *
		 * @var Order_Actions
		 */
		private Order_Actions $order_actions;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param Order_Actions|null $order_actions action-set builder; defaults to one
		 *                                          wired against {@see Orders_Registry::instance()}.
		 */
		public function __construct( ?Order_Actions $order_actions = null ) {
			$this->order_actions = $order_actions ?? new Order_Actions( Orders_Registry::instance() );
		}

		/**
		 * Builds the full row for one order.
		 *
		 * @since 2.0.2
		 * @since 2.0.2 Added `is_exported` and `actions` (card #824).
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
				'is_exported'     => self::is_exported( $order, $provider ),
				'actions'         => $this->order_actions->for_order( $order, $provider ),
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
		 * Builds what a shop owner needs to see about one order WITHOUT opening it (SP-10 card
		 * #875) — a deliberate selection, not a dump: billing/shipping blocks, the shipping
		 * method, the item table and the same actions the row itself offers. WooCommerce's own
		 * `a.order-preview` panel is the reference for what belongs here.
		 *
		 * Reuses {@see self::build_customer()}, {@see self::build_payment()},
		 * {@see self::build_tracking()} and {@see self::resolve_delivery_status()} — the row and
		 * the preview describe the same order, so those four groups are byte-for-byte the row's
		 * own, not second copies.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order            $order    the order to preview.
		 * @param Orders_Provider|null $provider the matched carrier, or null when it could not be
		 *                                        resolved.
		 * @return array<string,mixed>
		 */
		public function build_preview( \WC_Order $order, ?Orders_Provider $provider ): array {
			$status  = $order->get_status();
			$created = $order->get_date_created();

			$preview = [
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
				'billing'         => $this->build_preview_billing( $order ),
				'shipping'        => $this->build_preview_shipping( $order, $provider ),
				'payment'         => $this->build_payment( $order ),
				'delivery_status' => $this->resolve_delivery_status( $order, $provider ),
				'tracking'        => $this->build_tracking( $order, $provider ),
				'items'           => $this->build_preview_items( $order ),
				'customer_note'   => (string) $order->get_customer_note(),
				'actions'         => $this->order_actions->for_order( $order, $provider ),
			];

			/**
			 * Filters the fully-built order preview before it is returned.
			 *
			 * @since 2.0.2
			 *
			 * @param array<string,mixed>  $preview  built preview.
			 * @param \WC_Order            $order    the order the preview was built from.
			 * @param Orders_Provider|null $provider the matched carrier, or null.
			 */
			$filtered = apply_filters( 'woodev_shipping_orders_preview', $preview, $order, $provider );

			return is_array( $filtered ) ? $filtered : $preview;
		}

		/**
		 * Builds the preview's `billing` field group.
		 *
		 * ⚠ HPOS-safe: the address comes from WooCommerce's own
		 * `WC_Order::get_formatted_billing_address()`, never hand-assembled from
		 * `get_post_meta()`. Returned as ONE string with real `\n` line breaks (via
		 * {@see self::to_multiline_plain_text()}) rather than the method's own `<br/>`-joined
		 * HTML — a REST row is JSON consumed by React, not PHP-rendered markup.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order order.
		 * @return array<string,mixed>
		 */
		private function build_preview_billing( \WC_Order $order ): array {
			return [
				'address' => self::without_leading_name(
					self::to_multiline_plain_text( (string) $order->get_formatted_billing_address( '' ) ),
					$order->get_formatted_billing_full_name()
				),
				'email'   => $order->get_billing_email(),
				'phone'   => $order->get_billing_phone(),
			];
		}

		/**
		 * Builds the preview's `shipping` field group: the formatted shipping address ALONGSIDE
		 * (not instead of) the resolved destination {@see self::resolve_destination()} already
		 * gives the row — a pickup point overrides the destination shown to the merchant, but the
		 * address on file is still worth showing next to it.
		 *
		 * ⚠ HPOS-safe: `get_formatted_shipping_address()`, never hand-assembled.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order            $order    order.
		 * @param Orders_Provider|null $provider matched carrier, or null.
		 * @return array<string,mixed>
		 */
		private function build_preview_shipping( \WC_Order $order, ?Orders_Provider $provider ): array {
			$destination = $this->resolve_destination( $order, $provider );

			return [
				'address'          => self::without_leading_name(
					self::to_multiline_plain_text( (string) $order->get_formatted_shipping_address( '' ) ),
					$order->get_formatted_shipping_full_name()
				),
				'method_title'     => $order->get_shipping_method(),
				'destination_kind' => $destination['kind'],
				'destination_text' => $destination['text'],
			];
		}

		/**
		 * Builds the preview's `items` field group — one entry per product line item. Fee,
		 * shipping and tax lines are not order CONTENTS a shop owner is asking about here, so
		 * they are left out exactly as `WC_Order::get_items()`'s own default `'line_item'` type
		 * filter already does.
		 *
		 * ⚠ A missing SKU renders as `null`, never `''` or `'0'` — the field is genuinely absent,
		 * not empty text.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order order.
		 * @return array<int,array<string,mixed>>
		 */
		private function build_preview_items( \WC_Order $order ): array {
			$items = [];

			foreach ( $order->get_items() as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}

				$product = $item->get_product();
				$sku     = $product instanceof \WC_Product ? (string) $product->get_sku() : '';

				$items[] = [
					'name'            => $item->get_name(),
					'sku'             => '' !== $sku ? $sku : null,
					'quantity'        => (int) $item->get_quantity(),
					'formatted_total' => self::to_plain_text( wc_price( $item->get_total() ) ),
				];
			}

			return $items;
		}

		/**
		 * Reduces a WooCommerce address string to plain text with real `\n` line breaks.
		 *
		 * `WC_Order::get_formatted_billing_address()` / `get_formatted_shipping_address()`
		 * return HTML with `<br/>`-joined lines — correct for PHP-rendered admin markup, wrong
		 * for a REST field a React panel prints as text (same reasoning as
		 * {@see self::to_plain_text()}, which this extends). `<br>` variants are converted to
		 * `\n` BEFORE the remaining tags are stripped, so the line breaks survive the strip.
		 *
		 * @since 2.0.2
		 *
		 * @param string $markup address markup, possibly carrying `<br>` line breaks.
		 * @return string
		 */
		private static function to_multiline_plain_text( string $markup ): string {
			$with_breaks = (string) preg_replace( '/<br\s*\/?>/i', "\n", $markup );

			return trim( html_entity_decode( wp_strip_all_tags( $with_breaks ), ENT_QUOTES, 'UTF-8' ) );
		}

		/**
		 * Drops a leading line that merely repeats a name the panel already shows.
		 *
		 * WooCommerce's address format opens with `{name}`, so a formatted address always
		 * starts with the recipient — correct on an order screen that shows nothing else, and
		 * a visible duplicate in the preview panel, where the customer's name is already the
		 * heading of the block the address sits in. Caught on the rig, s134: «Александра
		 * Константинова-Виноградова» appeared twice, two lines apart.
		 *
		 * Compares the WHOLE first line, trimmed — not a prefix match. A street that merely
		 * begins with the customer's surname keeps its line.
		 *
		 * @since 2.0.2
		 *
		 * @param string $address plain-text address with `\n` line breaks.
		 * @param string $name    the name already displayed beside it.
		 * @return string
		 */
		private static function without_leading_name( string $address, string $name ): string {
			$name = trim( $name );

			if ( '' === $name || '' === $address ) {
				return $address;
			}

			$lines = explode( "\n", $address );

			if ( trim( (string) reset( $lines ) ) === $name ) {
				array_shift( $lines );
			}

			return trim( implode( "\n", $lines ) );
		}

		/**
		 * Whether an order has ever been exported to its carrier (card #860): the
		 * provider's own `carrier_order_id` meta key is present AND non-empty.
		 *
		 * Follows the same read pattern as {@see self::build_tracking()}. A provider
		 * with no declared `carrier_order_id_meta_key` → false: the framework cannot
		 * know, and does not guess (the same asymmetry
		 * {@see Orders_Query::is_exported_meta_clauses()} documents).
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order            $order    order.
		 * @param Orders_Provider|null $provider matched carrier, or null.
		 * @return bool
		 */
		private static function is_exported( \WC_Order $order, ?Orders_Provider $provider ): bool {
			if ( null === $provider || null === $provider->get_carrier_order_id_meta_key() ) {
				return false;
			}

			return '' !== (string) \Woodev_Order_Compatibility::get_order_meta( $order, $provider->get_carrier_order_id_meta_key() );
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
		 * `! is_exported() && needs_payment()`, and only `needs_payment` is emitted
		 * here — a carrier-specific hint is that carrier's own column (D3), not this
		 * one.
		 *
		 * ⚠ The framework DOES own «exported» since #841: it is the presence of the
		 * meta a provider declares as `carrier_order_id_meta_key`, and
		 * {@see Orders_Query}'s `is_exported` argument filters on it. This row is
		 * still not gated on it, deliberately — that would put a carrier fact into a
		 * shared column. Do not read this paragraph as "the concept is missing": it
		 * said exactly that until #841 landed, and that sentence is why #841 spent a
		 * session looking for a definition the framework had no field for.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order $order order.
		 * @return array<string,mixed>
		 */
		private function build_payment( \WC_Order $order ): array {
			return [
				'method_title'    => $order->get_payment_method_title(),
				'formatted_total' => self::to_plain_text( $order->get_formatted_order_total() ),
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
				'formatted_total'   => self::to_plain_text( wc_price( $order->get_shipping_total() ) ),
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
		 * Reduces a WooCommerce money string to plain display text.
		 *
		 * `wc_price()` and `WC_Order::get_formatted_order_total()` return MARKUP —
		 * `<span class="woocommerce-Price-amount"><bdi>2 400,00&nbsp;<span …>&#8381;</span></bdi></span>` —
		 * which is correct for a PHP-rendered admin column and wrong for a REST row.
		 * The row is JSON consumed by React, which escapes it and prints the tags as
		 * text, so the boundary is where the markup has to go: a component cannot
		 * un-print it without `dangerouslySetInnerHTML`, and injecting server HTML
		 * into the table is a worse answer than not sending it.
		 *
		 * Tags are stripped BEFORE entities are decoded, so a decoded `&lt;` can
		 * never turn into a tag that the strip already ran past. The result keeps
		 * WooCommerce's own separators verbatim — a NO-BREAK SPACE (U+00A0) before
		 * the currency symbol — because the shop's currency settings, not this
		 * builder, decide how money reads.
		 *
		 * @since 2.0.2
		 *
		 * @param string $markup money string, possibly carrying markup.
		 * @return string
		 */
		private static function to_plain_text( string $markup ): string {
			return trim( html_entity_decode( wp_strip_all_tags( $markup ), ENT_QUOTES, 'UTF-8' ) );
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
		 * Tries every declared {@see Orders_Provider::get_method_ids()} in order and
		 * uses the FIRST match (round 2: every real carrier ships at least two methods
		 * — courier and pickup — and a single `method_id` reported `unknown` for
		 * whichever one it did not name).
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

			$item = null;
			foreach ( $provider->get_method_ids() as $method_id ) {
				$item = Shipping_Helper::get_order_shipping_item( $order, $method_id );

				if ( null !== $item ) {
					break;
				}
			}

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
