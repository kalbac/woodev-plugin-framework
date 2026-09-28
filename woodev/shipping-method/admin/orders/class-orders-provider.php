<?php
/**
 * Shipping orders — carrier descriptor
 *
 * @since 2.0.2
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Admin\Orders;

use Woodev\Framework\Shipping\Exceptions\Shipping_Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Admin\\Orders\\Orders_Provider' ) ) :

	/**
	 * Immutable value object a carrier plugin constructs to describe itself to the
	 * framework-owned «Заказы доставки» page (SP-10 spec D2).
	 *
	 * The framework derives none of these strings — the plugin supplies its own id,
	 * label, order-meta keys and shipping method ids, the same rule already enforced by
	 * {@see \Woodev\Framework\Shipping\Order\Shipping_Order_Handler::resolve()} and
	 * {@see \Woodev\Framework\Shipping\Admin\Shipping_Admin::get_page_slug()}. `id`,
	 * `label`, `marker_meta_key` and `method_ids` are required; every other field is
	 * optional and carries a documented meaning for its absence. `legacy_page_slug`, if
	 * declared, is consumed by {@see \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::maybe_redirect_legacy_page()}
	 * (increment 5, #820).
	 *
	 * @since 2.0.2
	 */
	final class Orders_Provider {

		/**
		 * Carrier/tab id.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		private $id;

		/**
		 * Tab label.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		private $label;

		/**
		 * Order-meta key that marks an order as belonging to this carrier
		 * (SP-10 spec M2) — turned into a `meta_query` `EXISTS` clause by
		 * {@see Orders_Query}.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		private $marker_meta_key;

		/**
		 * The WC shipping method ids this carrier ships under — a carrier commonly
		 * ships more than one (courier AND pickup being the usual pair; round 2 of
		 * this increment fixed exactly that miss). The row builder tries each id in
		 * order through
		 * {@see \Woodev\Framework\Shipping\Shipping_Helper::get_order_shipping_item()}
		 * and uses the first match to resolve the row's `type` field.
		 *
		 * @since 2.0.2
		 *
		 * @var string[]
		 */
		private $method_ids;

		/**
		 * Order-meta key the carrier's own raw delivery status lives under, or null when
		 * this carrier has no status concept of its own — a row then resolves to
		 * `Delivery_Status::UNKNOWN` rather than a guessed state.
		 *
		 * @since 2.0.2
		 *
		 * @var string|null
		 */
		private $status_meta_key;

		/**
		 * Raw carrier status => one of `Delivery_Status::canonical_states()`.
		 *
		 * @since 2.0.2
		 *
		 * @var array<string,string>
		 */
		private $status_map;

		/**
		 * Raw carrier status => human label. Optional; a raw status absent here falls
		 * back to showing the raw value itself.
		 *
		 * @since 2.0.2
		 *
		 * @var array<string,string>
		 */
		private $status_labels;

		/**
		 * Tracking-number link template. Accepted, not yet consumed (increment 2).
		 *
		 * @since 2.0.2
		 *
		 * @var string|null
		 */
		private $tracking_url_template;

		/**
		 * Order-meta key the carrier's tracking number lives under, or null when this
		 * carrier has no tracking number of its own.
		 *
		 * @since 2.0.2
		 *
		 * @var string|null
		 */
		private $tracking_meta_key;

		/**
		 * Order-meta key the checkout-chosen pickup point was stored under (the same
		 * real key {@see \Woodev\Framework\Shipping\Order\Shipping_Order_Handler::store_pickup_point()}
		 * resolved to for this carrier), or null when this carrier has no pickup-point
		 * concept. The stored value is a whole
		 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Point::to_array()} — this
		 * descriptor names the KEY, not a scalar; the row builder reads the `address`
		 * entry out of it.
		 *
		 * @since 2.0.2
		 *
		 * @var string|null
		 */
		private $pickup_point_meta_key;

		/**
		 * Order-meta key the carrier writes when it exports an order — the same real
		 * key {@see \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler::export()}
		 * stores its `CARRIER_ORDER_ID_FIELD` under via
		 * {@see \Woodev\Framework\Shipping\Order\Shipping_Order_Handler::set()}, or null
		 * when this carrier has declared no such key. Declared here rather than read
		 * out of the handler's own logical-field map for the same reason
		 * `tracking_number` is: the orders page must not depend on whether the plugin
		 * happens to have built an order handler (SP-10 #841).
		 *
		 * @since 2.0.2
		 *
		 * @var string|null
		 */
		private $carrier_order_id_meta_key;

		/**
		 * Legacy v1 orders-page slug, consumed by
		 * {@see \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::maybe_redirect_legacy_page()}
		 * (increment 5, #820) to redirect a merchant's bookmark to the new page.
		 *
		 * @since 2.0.2
		 *
		 * @var string|null
		 */
		private $legacy_page_slug;

		/**
		 * The carrier's own cron hook that refreshes delivery statuses, or null when this
		 * carrier has no cron concept — e.g. a webhook-only carrier (SP-10 spec D9, #828).
		 * The framework does not know this hook name and must not guess it: it schedules
		 * nothing and never fires it, it only reads its next run via
		 * {@see \Woodev\Framework\Shipping\Order\Delivery_Sync_Status::get_next_update()}.
		 *
		 * @since 2.0.2
		 *
		 * @var string|null
		 */
		private $cron_hook;

		/**
		 * The carrier's own marker writer — `fn( \WC_Order $order, array $context ): void` —
		 * or null when this carrier declared none (#967). See {@see self::mark_order()} for
		 * the contract, and {@see self::has_marker_writer()} for what its absence means.
		 *
		 * @since 2.0.2
		 *
		 * @var callable|null
		 */
		private $marker_writer;

		/**
		 * The carrier's declaration of its own order fields — `fn( array $context ): array` — or
		 * null when this carrier asks for none (#973, spec D7). See {@see self::get_order_fields()}.
		 *
		 * @since 2.0.2
		 *
		 * @var callable|null
		 */
		private $order_fields;

		/**
		 * Use {@see self::create()} instead.
		 *
		 * @since 2.0.2
		 *
		 * @param string               $id                         carrier/tab id.
		 * @param string               $label                      tab label.
		 * @param string               $marker_meta_key            order-meta marker key.
		 * @param string[]             $method_ids                 WC shipping method ids.
		 * @param string|null          $status_meta_key            carrier status order-meta key.
		 * @param array<string,string> $status_map                 raw status => canonical state.
		 * @param array<string,string> $status_labels              raw status => human label.
		 * @param string|null          $tracking_url_template      tracking-number link template.
		 * @param string|null          $tracking_meta_key          tracking-number order-meta key.
		 * @param string|null          $pickup_point_meta_key      pickup-point order-meta key.
		 * @param string|null          $carrier_order_id_meta_key  carrier-order-id order-meta key.
		 * @param string|null          $legacy_page_slug           legacy v1 orders-page slug.
		 * @param string|null          $cron_hook                  carrier cron hook that refreshes delivery statuses.
		 * @param callable|null        $marker_writer              carrier marker writer (#967).
		 * @param callable|null        $order_fields               carrier order-fields declaration (#973).
		 */
		private function __construct(
			string $id,
			string $label,
			string $marker_meta_key,
			array $method_ids,
			?string $status_meta_key,
			array $status_map,
			array $status_labels,
			?string $tracking_url_template,
			?string $tracking_meta_key,
			?string $pickup_point_meta_key,
			?string $carrier_order_id_meta_key,
			?string $legacy_page_slug,
			?string $cron_hook,
			?callable $marker_writer = null,
			?callable $order_fields = null
		) {
			$this->id                        = $id;
			$this->label                     = $label;
			$this->marker_meta_key           = $marker_meta_key;
			$this->method_ids                = $method_ids;
			$this->status_meta_key           = $status_meta_key;
			$this->status_map                = $status_map;
			$this->status_labels             = $status_labels;
			$this->tracking_url_template     = $tracking_url_template;
			$this->tracking_meta_key         = $tracking_meta_key;
			$this->pickup_point_meta_key     = $pickup_point_meta_key;
			$this->carrier_order_id_meta_key = $carrier_order_id_meta_key;
			$this->legacy_page_slug          = $legacy_page_slug;
			$this->cron_hook                 = $cron_hook;
			$this->marker_writer             = $marker_writer;
			$this->order_fields              = $order_fields;
		}

		/**
		 * Named constructor — validates the required fields.
		 *
		 * @since 2.0.2
		 *
		 * @param string              $id              carrier/tab id. Required, non-empty.
		 * @param string              $label           tab label. Required, non-empty.
		 * @param string              $marker_meta_key order-meta marker key (SP-10 M2). Required, non-empty.
		 * @param string[]            $method_ids      WC shipping method ids. Required, non-empty —
		 *                                              a carrier commonly ships more than one (courier
		 *                                              AND pickup being the usual pair).
		 * @param array<string,mixed> $args            {
		 *     Optional fields.
		 *
		 *     @type string               $status_meta_key           carrier status order-meta key.
		 *     @type array<string,string> $status_map                raw status => canonical state.
		 *     @type array<string,string> $status_labels             raw status => human label.
		 *     @type string               $tracking_url_template     tracking-number link template.
		 *     @type string               $tracking_meta_key         tracking-number order-meta key.
		 *     @type string               $pickup_point_meta_key     pickup-point order-meta key.
		 *     @type string               $carrier_order_id_meta_key carrier-order-id order-meta key (SP-10 #841).
		 *     @type string               $legacy_page_slug          legacy v1 orders-page slug.
		 *     @type string               $cron_hook                 carrier cron hook that refreshes delivery statuses.
		 *     @type callable             $marker_writer             `fn( \WC_Order $order, array $context ): void` — writes THIS
		 *                                                           carrier's marker onto an order it owns (#967). Optional: a carrier
		 *                                                           without one still gets its orders LISTED, but the framework will
		 *                                                           never create or edit an order for it (see {@see self::mark_order()}).
		 *     @type callable             $order_fields              `fn( array $context ): array` — the carrier's OWN order fields for one
		 *                                                           tariff, asked for by the admin order wizard (#973, spec D7). Optional: a
		 *                                                           carrier without it asks the manager for nothing extra
		 *                                                           (see {@see self::get_order_fields()}).
		 * }
		 * @return self
		 *
		 * @throws Shipping_Exception when a required field is empty, or `marker_writer` / `order_fields` is present but not callable.
		 */
		public static function create( string $id, string $label, string $marker_meta_key, array $method_ids, array $args = [] ): self {
			foreach ( [
				'id'              => $id,
				'label'           => $label,
				'marker_meta_key' => $marker_meta_key,
			] as $field => $value ) {
				if ( '' === $value ) {
					throw new Shipping_Exception(
						sprintf( 'Orders_Provider requires a non-empty "%s": the plugin must supply it.', $field )
					);
				}
			}

			if ( [] === $method_ids ) {
				throw new Shipping_Exception(
					'Orders_Provider requires a non-empty "method_ids": the plugin must supply it.'
				);
			}

			$method_ids = array_values( array_map( 'strval', $method_ids ) );

			$nullable_string = static function ( array $args, string $key ): ?string {
				return isset( $args[ $key ] ) && '' !== $args[ $key ] ? (string) $args[ $key ] : null;
			};

			$string_map = static function ( array $args, string $key ): array {
				return isset( $args[ $key ] ) && is_array( $args[ $key ] ) ? $args[ $key ] : [];
			};

			// Declared-but-broken is a plugin bug, not an absent writer: silently treating a
			// typo'd callable as "none" would hide the carrier from the order editor with no trace.
			if ( isset( $args['marker_writer'] ) && ! is_callable( $args['marker_writer'] ) ) {
				throw new Shipping_Exception(
					sprintf( 'Orders_Provider "%s": "marker_writer" must be callable.', $id )
				);
			}

			if ( isset( $args['order_fields'] ) && ! is_callable( $args['order_fields'] ) ) {
				throw new Shipping_Exception(
					sprintf( 'Orders_Provider "%s": "order_fields" must be callable.', $id )
				);
			}

			return new self(
				$id,
				$label,
				$marker_meta_key,
				$method_ids,
				$nullable_string( $args, 'status_meta_key' ),
				$string_map( $args, 'status_map' ),
				$string_map( $args, 'status_labels' ),
				$nullable_string( $args, 'tracking_url_template' ),
				$nullable_string( $args, 'tracking_meta_key' ),
				$nullable_string( $args, 'pickup_point_meta_key' ),
				$nullable_string( $args, 'carrier_order_id_meta_key' ),
				$nullable_string( $args, 'legacy_page_slug' ),
				$nullable_string( $args, 'cron_hook' ),
				$args['marker_writer'] ?? null,
				$args['order_fields'] ?? null
			);
		}

		/**
		 * Returns the carrier/tab id.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_id(): string {
			return $this->id;
		}

		/**
		 * Returns the tab label.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_label(): string {
			return $this->label;
		}

		/**
		 * Returns the order-meta marker key.
		 *
		 * The KEY only — the value and the moment it is written are the carrier's, through
		 * its {@see self::mark_order()} writer (#967); the value must be a non-empty scalar.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_marker_meta_key(): string {
			return $this->marker_meta_key;
		}

		/**
		 * Returns the WC shipping method ids, in declaration order.
		 *
		 * CONTRACT (card #842): a carrier that ships more than one method — commonly a
		 * courier AND a pickup method — must name EVERY one of its owning plugin's
		 * registered shipping method ids here. {@see Order_Row_Builder::resolve_type()}
		 * is the only consumer, and tries these ids in order to find the order's
		 * shipping line; an id the plugin ships but this list omits makes that method
		 * silently report `unknown` for every order placed with it, with no error and no
		 * log line. When the owning plugin is a `Shipping_Plugin`,
		 * {@see Orders_Registry::check_method_ids_contract()} gates this under
		 * `WP_DEBUG` — the reverse (naming an id from elsewhere) is not an error.
		 *
		 * @since 2.0.2
		 *
		 * @return string[]
		 */
		public function get_method_ids(): array {
			return $this->method_ids;
		}

		/**
		 * Returns the carrier-status order-meta key, or null when this carrier has no
		 * status concept.
		 *
		 * @since 2.0.2
		 *
		 * @return string|null
		 */
		public function get_status_meta_key(): ?string {
			return $this->status_meta_key;
		}

		/**
		 * Returns the raw-status => canonical-state map.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string,string>
		 */
		public function get_status_map(): array {
			return $this->status_map;
		}

		/**
		 * Returns the raw-status => human-label map.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string,string>
		 */
		public function get_status_labels(): array {
			return $this->status_labels;
		}

		/**
		 * Returns the tracking-number link template, or null when not declared.
		 *
		 * @since 2.0.2
		 *
		 * @return string|null
		 */
		public function get_tracking_url_template(): ?string {
			return $this->tracking_url_template;
		}

		/**
		 * Returns the tracking-number order-meta key, or null when this carrier has no
		 * tracking number of its own.
		 *
		 * @since 2.0.2
		 *
		 * @return string|null
		 */
		public function get_tracking_meta_key(): ?string {
			return $this->tracking_meta_key;
		}

		/**
		 * Returns the pickup-point order-meta key, or null when this carrier has no
		 * pickup-point concept.
		 *
		 * @since 2.0.2
		 *
		 * @return string|null
		 */
		public function get_pickup_point_meta_key(): ?string {
			return $this->pickup_point_meta_key;
		}

		/**
		 * Returns the carrier-order-id order-meta key, or null when this carrier has
		 * declared none (SP-10 #841).
		 *
		 * @since 2.0.2
		 *
		 * @return string|null
		 */
		public function get_carrier_order_id_meta_key(): ?string {
			return $this->carrier_order_id_meta_key;
		}

		/**
		 * Returns the legacy v1 orders-page slug, or null when not declared.
		 *
		 * Consumed by {@see \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::maybe_redirect_legacy_page()}
		 * (increment 5, #820).
		 *
		 * @since 2.0.2
		 *
		 * @return string|null
		 */
		public function get_legacy_page_slug(): ?string {
			return $this->legacy_page_slug;
		}

		/**
		 * Returns the carrier's cron hook that refreshes delivery statuses, or null when
		 * this carrier has no cron concept of its own (SP-10 spec D9, #828).
		 *
		 * @since 2.0.2
		 *
		 * @return string|null
		 */
		public function get_cron_hook(): ?string {
			return $this->cron_hook;
		}

		/**
		 * Whether this carrier declared a marker writer (#967).
		 *
		 * The framework NEVER creates or edits an order for a carrier that has none — the
		 * marker's value and its source differ per carrier (a flag, a value derived from the
		 * chosen rate, the carrier's own raw-status meta), so there is no default to fall back
		 * on. Listing the carrier's existing orders is unaffected.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function has_marker_writer(): bool {
			return null !== $this->marker_writer;
		}

		/**
		 * Marks an order as belonging to this carrier — the framework-level marker contract
		 * (#967, spec `2026-09-27-710-create-edit-order-design.md` D4).
		 *
		 * Calls the carrier's own writer with the live order and a context array. Called by
		 * {@see \Woodev\Framework\Shipping\Order\Order_Marker}, never directly: that class
		 * decides WHICH orders a provider marks, persists the result and enforces the value
		 * rule below, so classic checkout, Store API checkout and the admin order editor all
		 * mark the same way.
		 *
		 * **What the writer must leave behind** — this is what the orders page reads, and it is
		 * an installed-site data contract:
		 *
		 *  - the meta key {@see self::get_marker_meta_key()} present on the order (the list query
		 *    matches it by `EXISTS`, {@see Orders_Query});
		 *  - with a NON-EMPTY SCALAR value ({@see \Woodev\Framework\Shipping\Order\Order_Marker::is_valid_value()}):
		 *    {@see Orders_Registry::resolve_provider_for_order()} treats `''` / `false` as «no
		 *    marker», so the list shows the order while its row and metabox cannot name its
		 *    carrier, and an array value raises an «Array to string conversion» warning on every
		 *    call. `'1'` is the safe value.
		 *
		 * The writer sets the meta on the order OBJECT (`$order->update_meta_data()`); the
		 * framework saves it. It must be idempotent — it runs again when an order is edited.
		 *
		 * `$context` — every key is always present:
		 *
		 *  - `provider_id`    string   this provider's id;
		 *  - `method_id`      string   the order's shipping line that made it this carrier's (bare id);
		 *  - `instance_id`    int      that line's zone-instance id (0 when unknown);
		 *  - `rate`           array    `id`, `method_id`, `instance_id`, `label`, `cost` (string), `meta`
		 *                              (`key => value`) — read back from the order's shipping line, or
		 *                              supplied by the caller (the admin editor hands over the chosen rate);
		 *  - `fields`         array    the carrier's checkout/wizard field values, keyed by field id, after the
		 *                              stale-pickup drop — empty when the caller has none;
		 *  - `pickup_point`   mixed    a {@see \Woodev\Framework\Shipping\Pickup\Pickup_Point}, its `to_array()`,
		 *                              or null — supplied by callers that hold the full point (the admin editor);
		 *                              a checkout caller carries the point id inside `fields` instead;
		 *  - `carrier_fields` array    the carrier's own export fields (spec D7, #973), keyed by field id — the
		 *                              values the wizard collected and the framework already validated against
		 *                              {@see self::get_order_fields()}; empty when the carrier declares none.
		 *                              The framework stores them under their declared `meta_key`s itself;
		 *                              the writer only needs them to derive a marker from them.
		 *
		 * @since 2.0.2
		 *
		 * @param \WC_Order           $order   the order to mark, already carrying its shipping line.
		 * @param array<string,mixed> $context see above.
		 * @return void
		 *
		 * @throws Shipping_Exception when this provider declared no marker writer.
		 */
		public function mark_order( \WC_Order $order, array $context ): void {
			if ( null === $this->marker_writer ) {
				throw new Shipping_Exception(
					sprintf( 'Orders_Provider "%s" declared no marker_writer, so it cannot mark an order.', $this->id )
				);
			}

			call_user_func( $this->marker_writer, $order, $context );
		}

		/**
		 * Whether this carrier declared order fields of its own (#973).
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function has_order_fields(): bool {
			return null !== $this->order_fields;
		}

		/**
		 * The fields this carrier asks the manager for when its tariff is put on an order by hand —
		 * declared value, package dimensions, extra services (#710 spec D7, O13).
		 *
		 * The carrier's callable is asked once per tariff and returns `field id => definition`, in the
		 * order the manager should see them. A definition is written in the Settings API's own
		 * vocabulary ({@see \Woodev\Framework\Shipping\Admin\Orders\Carrier_Field_Set} lists the keys):
		 * `name`, `description`, `type`, `control`, `options`, `default`, `required`, `validate`,
		 * `show_if`, `min` / `max` / `step`, `tooltip`, `placeholder` — and `meta_key`, the order-meta
		 * key the value is stored under, which is the carrier's contract with its own export. The
		 * framework renders the fields with the settings page's React controls (the plugin ships no JS),
		 * validates the values with the settings page's validation and stores them through the same
		 * persistence core as a checkout.
		 *
		 * `$context` — every key is always present:
		 *
		 *  - `provider_id` string                   this provider's id;
		 *  - `method_id`   string                   the tariff's bare shipping method id;
		 *  - `instance_id` int                      its zone-instance id (0 when unknown);
		 *  - `rate_id`     string                   `method_id:instance_id`;
		 *  - `is_pickup`   bool                     whether the tariff delivers to a pickup point;
		 *  - `method`      \WC_Shipping_Method|null the zone-instance method — read its options for defaults.
		 *
		 * A carrier that declares nothing, a callable that throws or returns something that is not an
		 * array all read as «no fields»: the order is still placed, and the failure is logged.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string, mixed> $context see above.
		 * @return array<string, array<string, mixed>> field id => definition.
		 */
		public function get_order_fields( array $context ): array {
			if ( null === $this->order_fields ) {
				return [];
			}

			try {
				$declared = call_user_func( $this->order_fields, $context );
			} catch ( \Throwable $e ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for a carrier plugin's order-field declaration.
				error_log( sprintf( '[woodev] carrier "%1$s": order_fields declaration failed: %2$s', $this->id, $e->getMessage() ) );

				return [];
			}

			return is_array( $declared ) ? $declared : [];
		}
	}

endif;
