<?php
/**
 * Woodev Shipping Admin Order
 *
 * The order-screen admin surface for a shipping plugin (spec §4.4) — the shipping
 * counterpart of {@see \Woodev_Payment_Gateway_Admin_Order}. It adds an order-list
 * column and an order-edit metabox that DISPLAY the shipment's carrier order id,
 * tracking number and chosen pickup point — every value read through
 * {@see \Woodev\Framework\Shipping\Order\Shipping_Order_Handler} so it routes to the
 * plugin's own installed-site order-meta keys (the framework hardcodes none) — and
 * exposes export / track / cancel actions wired to the carrier handlers
 * ({@see \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler} for export/cancel,
 * {@see \Woodev\Framework\Shipping\Order\Abstract_Tracking_Handler} for track).
 *
 * It introduces NO installed-site contract string. The order-list column key is a
 * neutral, plugin-namespaced UI handle (not a contract); whether an order belongs to
 * this plugin is decided by reading the order's chosen shipping-method id and matching
 * it against the ids the plugin itself registered ({@see Shipping_Plugin::get_shipping_method_ids()}) —
 * never against a hardcoded literal. The export/track/cancel buttons post to WP's own
 * `admin-post.php` under a forward-only, plugin-namespaced action; no front-end AJAX
 * action, admin page slug, option key, log-source name or order-meta key is added here.
 *
 * Like every other S1 base class this lands unwired — the plugin instantiates it and
 * registers it as one of {@see \Woodev\Framework\Shipping\Admin\Shipping_Admin}'s
 * handlers (which calls {@see self::register()} on `admin_init`); wiring is the
 * separate plugin-integration task.
 *
 * See docs-internal/platform-v2-s1-shipping-spec.md §4.4.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping\Admin;

use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;
use Woodev\Framework\Shipping\Order\Abstract_Tracking_Handler;
use Woodev\Framework\Shipping\Order\Shipping_Order_Handler;
use Woodev\Framework\Shipping\Shipping_Exception;
use Woodev\Framework\Shipping\Shipping_Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Admin\\Shipping_Admin_Order' ) ) :

	/**
	 * Order-list column + order-edit metabox for a shipping plugin.
	 *
	 * A carrier constructs this with its plugin instance, the HPOS-safe order-meta
	 * handler, the shipment handler (export/cancel) and, optionally, the tracking
	 * handler (track). The displayed fields and the action wiring are carrier-neutral;
	 * only the plugin-supplied order-meta map decides which real keys are read.
	 *
	 * @since 1.5.0
	 */
	class Shipping_Admin_Order {

		/** @var Shipping_Plugin the plugin instance this admin surface belongs to */
		private Shipping_Plugin $plugin;

		/** @var Shipping_Order_Handler HPOS-safe accessor for the plugin's order-meta keys */
		private Shipping_Order_Handler $order_handler;

		/** @var Abstract_Shipment_Handler carrier shipment handler used by export/cancel */
		private Abstract_Shipment_Handler $shipment_handler;

		/** @var Abstract_Tracking_Handler|null carrier tracking handler used by track (optional) */
		private ?Abstract_Tracking_Handler $tracking_handler;

		/** @var array<string, string> metabox fields to display: logical order-meta field => label */
		private array $metabox_fields;

		/** @var string logical order-meta field holding the tracking number passed to the tracking handler */
		private string $tracking_field;

		/** @var string order-list column key (a neutral plugin-namespaced UI handle, NOT a contract) */
		private string $column_key;

		/** @var string order-list column label */
		private string $column_label;

		/** @var string metabox id */
		private string $metabox_id;

		/** @var string metabox title */
		private string $metabox_title;

		/** @var string forward-only, plugin-namespaced admin-post action the metabox form posts to */
		private string $admin_post_action;

		/** @var string nonce action protecting the metabox form */
		private string $nonce_action;

		/**
		 * Constructor.
		 *
		 * Stores the collaborators and resolves display/wiring defaults; it adds no
		 * hooks — call {@see self::register()} (the {@see Shipping_Admin} bootstrap does
		 * this on `admin_init`).
		 *
		 * @since 1.5.0
		 *
		 * @param Shipping_Plugin                $plugin           the plugin instance
		 * @param Shipping_Order_Handler         $order_handler    order-meta accessor keyed by the plugin's logical-field map
		 * @param Abstract_Shipment_Handler      $shipment_handler shipment handler invoked by the export and cancel actions
		 * @param Abstract_Tracking_Handler|null $tracking_handler tracking handler invoked by the track action; null disables track
		 * @param array<string, mixed>           $args {
		 *     Optional display/wiring overrides.
		 *
		 *     @type array<string, string> $metabox_fields logical order-meta field => label to display in the metabox
		 *     @type string                $tracking_field logical order-meta field holding the tracking number
		 *     @type string                $column_key     order-list column key (neutral UI handle)
		 *     @type string                $column_label   order-list column label
		 *     @type string                $metabox_title  metabox title
		 * }
		 */
		public function __construct( Shipping_Plugin $plugin, Shipping_Order_Handler $order_handler, Abstract_Shipment_Handler $shipment_handler, ?Abstract_Tracking_Handler $tracking_handler = null, array $args = [] ) {

			$this->plugin           = $plugin;
			$this->order_handler    = $order_handler;
			$this->shipment_handler = $shipment_handler;
			$this->tracking_handler = $tracking_handler;

			$this->metabox_fields = isset( $args['metabox_fields'] ) && is_array( $args['metabox_fields'] )
				? $args['metabox_fields']
				: [
					'carrier_order_id' => __( 'Carrier order ID', 'woodev-plugin-framework' ),
					'tracking_number'  => __( 'Tracking number', 'woodev-plugin-framework' ),
					'pickup_point'     => __( 'Chosen pickup point', 'woodev-plugin-framework' ),
				];

			$this->tracking_field = isset( $args['tracking_field'] ) && is_string( $args['tracking_field'] )
				? $args['tracking_field']
				: 'tracking_number';

			$this->column_key   = isset( $args['column_key'] ) && is_string( $args['column_key'] )
				? $args['column_key']
				: $plugin->get_id() . '_shipment';
			$this->column_label = isset( $args['column_label'] ) && is_string( $args['column_label'] )
				? $args['column_label']
				: __( 'Shipment', 'woodev-plugin-framework' );

			$this->metabox_id    = $plugin->get_id() . '_shipment';
			$this->metabox_title = isset( $args['metabox_title'] ) && is_string( $args['metabox_title'] )
				? $args['metabox_title']
				: __( 'Shipment', 'woodev-plugin-framework' );

			$this->admin_post_action = 'woodev_shipping_' . $plugin->get_id() . '_order_action';
			$this->nonce_action      = $this->admin_post_action;
		}

		/**
		 * Registers the order-screen hooks.
		 *
		 * Wires the order-list column (legacy posts table and HPOS orders table), the
		 * order-edit metabox and the metabox form's `admin-post.php` handler. Called by
		 * the {@see Shipping_Admin} bootstrap on `admin_init`.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @return void
		 */
		public function register(): void {

			// order-list column — legacy (posts table) and HPOS (orders table).
			add_filter( 'manage_edit-shop_order_columns', [ $this, 'add_order_column' ] );
			add_filter( 'manage_woocommerce_page_wc-orders_columns', [ $this, 'add_order_column' ] );
			add_action( 'manage_shop_order_posts_custom_column', [ $this, 'render_order_column' ], 10, 2 );
			add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ $this, 'render_order_column' ], 10, 2 );

			// order-edit metabox.
			add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ], 10, 2 );

			// metabox action buttons (export / track / cancel) post here.
			add_action( 'admin_post_' . $this->admin_post_action, [ $this, 'handle_order_action' ] );
		}

		/**
		 * Adds the shipment column to an orders-table column set.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @param array<string, string> $columns existing columns
		 * @return array<string, string>
		 */
		public function add_order_column( array $columns ): array {

			$columns[ $this->column_key ] = $this->column_label;

			return $columns;
		}

		/**
		 * Renders the shipment column cell for one order.
		 *
		 * Handles both the legacy posts table (second arg is a post id) and the HPOS
		 * orders table (second arg is a {@see \WC_Order}); only renders for orders that
		 * belong to this plugin.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @param string        $column        current column key
		 * @param int|\WC_Order $post_or_order order id (legacy) or order object (HPOS)
		 * @return void
		 */
		public function render_order_column( string $column, $post_or_order ): void {

			if ( $column !== $this->column_key ) {
				return;
			}

			$order = $this->resolve_order( $post_or_order );

			if ( ! $order instanceof \WC_Order || ! $this->is_our_order( $order ) ) {
				return;
			}

			$carrier_order_id = $this->get_field( $order, 'carrier_order_id' );
			$tracking_number  = $this->get_field( $order, 'tracking_number' );

			if ( '' === $carrier_order_id && '' === $tracking_number ) {
				echo '&ndash;';
				return;
			}

			if ( '' !== $carrier_order_id ) {
				echo esc_html( $carrier_order_id );
			}

			if ( '' !== $tracking_number ) {
				echo '<br /><small>' . esc_html( $tracking_number ) . '</small>';
			}
		}

		/**
		 * Registers the shipment metabox on the order-edit screen.
		 *
		 * Mounts on both the legacy (`shop_order`) and HPOS order screens, only when the
		 * order belongs to this plugin.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @param string                  $post_type     current screen post type / id
		 * @param \WP_Post|\WC_Order|null $post_or_order current post or order object
		 * @return void
		 */
		public function add_meta_box( string $post_type, $post_or_order = null ): void {

			$order = $this->resolve_order( $post_or_order );

			if ( ! $order instanceof \WC_Order || ! $this->is_our_order( $order ) ) {
				return;
			}

			add_meta_box( $this->metabox_id, $this->metabox_title, [ $this, 'render_metabox' ], $post_type, 'side', 'default' );
		}

		/**
		 * Renders the shipment metabox body.
		 *
		 * Prepares the display fields (read through the order handler) and the action
		 * buttons, then includes the view.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @param \WP_Post|\WC_Order $post_or_order current post or order object
		 * @return void
		 */
		public function render_metabox( $post_or_order ): void {

			$order = $this->resolve_order( $post_or_order );

			if ( ! $order instanceof \WC_Order ) {
				return;
			}

			$fields = [];

			foreach ( $this->metabox_fields as $logical => $label ) {
				$fields[] = [
					'label' => (string) $label,
					'value' => $this->get_field( $order, (string) $logical ),
				];
			}

			$actions = [
				[
					'key'   => 'export',
					'label' => __( 'Export', 'woodev-plugin-framework' ),
					'class' => 'button button-primary',
				],
				[
					'key'   => 'track',
					'label' => __( 'Track', 'woodev-plugin-framework' ),
					'class' => 'button',
				],
				[
					'key'   => 'cancel',
					'label' => __( 'Cancel', 'woodev-plugin-framework' ),
					'class' => 'button',
				],
			];

			$admin_post_action = $this->admin_post_action;
			$nonce_action      = $this->nonce_action;
			$order_id          = $order->get_id();

			include $this->plugin->get_shipping_framework_path() . '/admin/views/html-admin-order-metabox.php';

			// Render the shipment's tracking history here -- the metabox is the correct
			// OUTPUT context. (It must not be fired from handle_order_action(), which ends
			// in wp_safe_redirect(): a display-hook subscriber echoing there would be
			// discarded and risk "headers already sent".) Only when a tracking number
			// exists, so unexported orders make no carrier API call.
			if ( null !== $this->tracking_handler ) {

				$tracking_number = (string) $this->get_field( $order, $this->tracking_field );

				if ( '' !== $tracking_number ) {
					$this->tracking_handler->display_admin( $order, $tracking_number );
				}
			}
		}

		/**
		 * Handles an export / track / cancel submission from the metabox form.
		 *
		 * Verifies the nonce and capability, then routes the requested action to the
		 * carrier handlers — export/cancel to the shipment handler, track to the
		 * tracking handler — and redirects back to the order-edit screen.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @return void
		 */
		public function handle_order_action(): void {

			check_admin_referer( $this->nonce_action );

			if ( ! current_user_can( 'edit_shop_orders' ) ) {
				wp_die( esc_html__( 'You do not have permission to manage this shipment.', 'woodev-plugin-framework' ) );
			}

			$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
			$action   = isset( $_POST['woodev_shipping_order_action'] ) ? sanitize_key( wp_unslash( $_POST['woodev_shipping_order_action'] ) ) : '';
			$order    = wc_get_order( $order_id );

			if ( $order instanceof \WC_Order && $this->is_our_order( $order ) ) {

				switch ( $action ) {
					case 'export':
						$this->shipment_handler->export( $order );
						break;
					case 'cancel':
						$this->shipment_handler->cancel( $order );
						break;
					case 'track':
						// Tracking history is rendered on the order-edit screen by
						// render_metabox() (the correct output context). This admin-post
						// handler ends in wp_safe_redirect(), so it must NOT fire the
						// display hook here (output would be discarded / "headers already
						// sent"). Clicking Track simply reloads the order, re-rendering
						// fresh tracking via the metabox.
						break;
				}
			}

			$redirect = $order instanceof \WC_Order ? $order->get_edit_order_url() : admin_url();

			wp_safe_redirect( $redirect );
			exit;
		}

		/**
		 * Determines whether an order belongs to this plugin.
		 *
		 * Reads the order's chosen shipping-method id(s) and matches them against the
		 * ids the plugin registered; it compares against no hardcoded literal.
		 *
		 * @since 1.5.0
		 *
		 * @param \WC_Order $order order to test
		 * @return bool
		 */
		private function is_our_order( \WC_Order $order ): bool {

			$method_ids = $this->plugin->get_shipping_method_ids();

			if ( empty( $method_ids ) ) {
				return false;
			}

			foreach ( $order->get_shipping_methods() as $item ) {

				if ( $item instanceof \WC_Order_Item_Shipping && in_array( $item->get_method_id(), $method_ids, true ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Reads a logical order-meta field, returning '' when the plugin does not map it.
		 *
		 * @since 1.5.0
		 *
		 * @param \WC_Order $order   order to read from
		 * @param string    $logical logical field name
		 * @return string the stored value as a string, or '' when unmapped/empty
		 */
		private function get_field( \WC_Order $order, string $logical ): string {

			try {
				$value = $this->order_handler->get( $order, $logical );
			} catch ( Shipping_Exception $exception ) {
				return '';
			}

			return is_scalar( $value ) ? (string) $value : '';
		}

		/**
		 * Resolves a post id / post / order to a {@see \WC_Order}, or null.
		 *
		 * @since 1.5.0
		 *
		 * @param int|\WP_Post|\WC_Order|null $value order id, post object, or order object
		 * @return \WC_Order|null
		 */
		private function resolve_order( $value ): ?\WC_Order {

			if ( $value instanceof \WC_Order ) {
				return $value;
			}

			$order = wc_get_order( $value instanceof \WP_Post ? $value->ID : $value );

			return $order instanceof \WC_Order ? $order : null;
		}

		/**
		 * Gets the plugin instance this admin surface belongs to.
		 *
		 * @since 1.5.0
		 *
		 * @return Shipping_Plugin
		 */
		public function get_plugin(): Shipping_Plugin {
			return $this->plugin;
		}
	}

endif;
