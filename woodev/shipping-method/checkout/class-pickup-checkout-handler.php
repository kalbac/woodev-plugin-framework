<?php
/**
 * Woodev Pickup Checkout Handler
 *
 * Checkout specialization (spec §4.1.vi + §4.2) that wires the pickup-point (PVZ)
 * map modal into the WooCommerce checkout. It is the glue between three Phase-1/2
 * collaborators — a {@see Map_Provider} (assets + adapter config), the carrier's
 * session-only {@see Pickup_Selection} (the already-chosen point, for prefill), and
 * the plugin-supplied AJAX action map + nonce — and the front-end map core
 * (`assets/js/frontend/pickup-map.js`) it drives through `checkout.js`.
 *
 * Contract-neutral by construction: the shipping-method ids that open the modal,
 * the hidden selected-point field id, the AJAX action strings and the nonce action
 * are ALL supplied by the host plugin (installed-site contracts the carrier owns);
 * the framework hardcodes and derives none of them. The only new framework strings
 * here are forward hooks and DOM ids, neither of which is an installed-site contract.
 *
 * See docs-internal/platform-v2-s1-shipping-spec.md §4.1.vi and §4.2.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping\Checkout;

use Woodev\Framework\Shipping\Ajax\Shipping_AJAX;
use Woodev\Framework\Shipping\Map\Map_Provider;
use Woodev\Framework\Shipping\Order\Shipping_Order_Handler;
use Woodev\Framework\Shipping\Pickup\Pickup_Point;
use Woodev\Framework\Shipping\Pickup\Pickup_Selection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Checkout\\Pickup_Checkout_Handler' ) ) :

	/**
	 * Wires the PVZ map modal into the checkout.
	 *
	 * Extends {@see Checkout_Handler} (the sanitize → validate → save backbone) and
	 * adds the front-end half: it enqueues the map provider's assets plus
	 * `checkout.js`, localizes the provider-agnostic config the map core consumes,
	 * and renders the modal shell + balloon template into the page. The host plugin
	 * hooks {@see Pickup_Checkout_Handler::enqueue()} onto `wp_enqueue_scripts` and
	 * {@see Pickup_Checkout_Handler::render()} onto a checkout/footer hook.
	 *
	 * @since 1.5.0
	 */
	class Pickup_Checkout_Handler extends Checkout_Handler {

		/** @var Map_Provider map provider supplying the JS adapter + its runtime config */
		private Map_Provider $map_provider;

		/** @var Pickup_Selection session-only store read to prefill an already-chosen point */
		private Pickup_Selection $pickup_selection;

		/** @var string host-supplied hidden checkout field id that holds the chosen point code */
		private string $field_id;

		/** @var array<int, string> host-supplied shipping-method ids whose selection opens the modal */
		private array $method_ids;

		/** @var array<string, string> host-supplied map: logical endpoint => real AJAX action string */
		private array $action_map;

		/** @var string host-supplied nonce action localized for the map AJAX requests */
		private string $nonce_action;

		/** @var string global JS constructor name of the map adapter to instantiate */
		private string $adapter_global;

		/** @var string token namespacing this handler's forward hooks (own copy; the backbone's is private) */
		private string $forward_hook_prefix;

		/** @var Shipping_Order_Handler|null host order-meta handler the chosen point is persisted through */
		private ?Shipping_Order_Handler $order_handler;

		/** @var string logical field (in the order handler's key map) the chosen point is stored under */
		private string $point_meta_field;

		/**
		 * Constructor.
		 *
		 * @since 1.5.0
		 *
		 * @param Checkout_Fields             $fields           checkout fields managed by the backbone (must include $field_id)
		 * @param Map_Provider                $map_provider     provider whose assets + config drive the map
		 * @param Pickup_Selection            $pickup_selection session store read for prefill
		 * @param string                      $field_id         hidden checkout field id receiving the chosen point code (host-supplied)
		 * @param array<int, string>          $method_ids       shipping-method ids that trigger the modal (host-supplied contract values)
		 * @param array<string, string>       $action_map       logical endpoint => real AJAX action string (host-supplied; same map as {@see Shipping_AJAX})
		 * @param string                      $nonce_action     nonce action the map AJAX requests carry (host-supplied)
		 * @param string                      $hook_prefix      token namespacing this handler's forward hooks; defaults to none
		 * @param string                      $adapter_global   global JS constructor of the map adapter; defaults to the Leaflet adapter
		 * @param Shipping_Order_Handler|null $order_handler order-meta handler the chosen point is persisted through at order save; defaults to none (no session→order handoff)
		 * @param string                      $point_meta_field logical field (present in the order handler's key map) the chosen point is stored under; defaults to none
		 */
		public function __construct(
			Checkout_Fields $fields,
			Map_Provider $map_provider,
			Pickup_Selection $pickup_selection,
			string $field_id,
			array $method_ids,
			array $action_map,
			string $nonce_action,
			string $hook_prefix = '',
			string $adapter_global = 'WoodevPickupMapLeafletAdapter',
			?Shipping_Order_Handler $order_handler = null,
			string $point_meta_field = ''
		) {
			parent::__construct( $fields, $hook_prefix );

			$this->map_provider     = $map_provider;
			$this->pickup_selection = $pickup_selection;
			$this->field_id         = $field_id;
			$this->method_ids       = array_values( array_filter( array_map( 'strval', $method_ids ) ) );
			$this->action_map          = $action_map;
			$this->nonce_action        = $nonce_action;
			$this->adapter_global      = $adapter_global;
			$this->forward_hook_prefix = $hook_prefix;
			$this->order_handler       = $order_handler;
			$this->point_meta_field    = $point_meta_field;
		}

		/**
		 * Wires the checkout backbone plus the pickup-map front end.
		 *
		 * Extends the backbone's {@see Checkout_Handler::register()} (field injection +
		 * posted-data processing/save, including the session→order handoff in
		 * {@see self::save()}) with the front-end half: the map assets/script on
		 * `wp_enqueue_scripts` and the modal + balloon templates on `wp_footer`.
		 *
		 * @since 1.5.0
		 *
		 * @return void
		 */
		public function register(): void {
			parent::register();

			add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
			add_action( 'wp_footer', [ $this, 'render' ] );
		}

		/**
		 * Saves the managed fields, routing the chosen pickup point to its real meta key.
		 *
		 * The hidden pickup field is excluded from the backbone's per-field persistence
		 * (which would write it raw under the field id); instead the chosen point is read
		 * from the WC session ({@see Pickup_Selection}) and persisted through the host's
		 * {@see Shipping_Order_Handler} key map — the carrier's real, installed-site
		 * order-meta key. Other managed fields are saved by the backbone as usual.
		 *
		 * @since 1.5.0
		 *
		 * @param \WC_Order|int        $order  order object or id to save onto
		 * @param array<string, mixed> $values clean values keyed by field id
		 *
		 * @return void
		 */
		public function save( $order, array $values ): void {

			// Only divert the hidden pickup field away from the backbone's per-field
			// persistence when the session->order handoff is actually configured (order
			// handler + logical meta field supplied). Without it, store_selected_point()
			// is a no-op, so removing the field unconditionally would lose the chosen
			// point entirely -- let the backbone persist it under the field id instead.
			if ( null !== $this->order_handler && '' !== $this->point_meta_field ) {
				unset( $values[ $this->field_id ] );
			}

			parent::save( $order, $values );

			$this->store_selected_point( $order );
		}

		/**
		 * Persists the session-chosen pickup point to order meta via the order handler.
		 *
		 * A no-op when no order handler / logical field was supplied, the order is not a
		 * real {@see \WC_Order}, or no point is selected — so a courier (non-pickup) order
		 * stores nothing. The point is stored under the carrier's plugin-supplied
		 * order-meta key (resolved by {@see Shipping_Order_Handler}); no key is derived
		 * and nothing is written raw under the hidden field id.
		 *
		 * @since 1.5.0
		 *
		 * @param \WC_Order|int $order order object or id being saved
		 *
		 * @return void
		 */
		private function store_selected_point( $order ): void {

			if ( null === $this->order_handler || '' === $this->point_meta_field || ! $order instanceof \WC_Order ) {
				return;
			}

			$point = $this->pickup_selection->get();

			if ( ! $point instanceof Pickup_Point ) {
				return;
			}

			$this->order_handler->store_pickup_point( $order, $this->point_meta_field, $point );
		}

		/**
		 * Validates the pickup field ONLY when a pickup shipping method is chosen.
		 *
		 * The hidden pickup field is required for pickup methods, but a courier/postal
		 * order leaves it blank -- so running the backbone's required-field validation
		 * unconditionally would block valid non-pickup checkouts. Gate it on the chosen
		 * method being one of this handler's pickup method ids.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @return void
		 */
		public function handle_checkout_process(): void {
			if ( $this->is_pickup_method_chosen() ) {
				parent::handle_checkout_process();
			}
		}

		/**
		 * Persists the managed fields + chosen point ONLY for pickup-method orders.
		 *
		 * For a courier/postal order there is nothing to store, and re-validating the
		 * required pickup field after the order is created would add stray error notices.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @param int                  $order_id    the created order id
		 * @param array<string, mixed> $posted_data the posted checkout data
		 * @param \WC_Order            $order       the created, saved order
		 *
		 * @return void
		 */
		public function handle_checkout_order_processed( int $order_id, array $posted_data, \WC_Order $order ): void {
			if ( $this->order_uses_pickup_method( $order ) ) {
				parent::handle_checkout_order_processed( $order_id, $posted_data, $order );
			}
		}

		/**
		 * Whether the customer's chosen shipping method (WC session) is a pickup method.
		 *
		 * Used during the checkout validation phase, before any order exists. A chosen
		 * rate id is `{method_id}` or `{method_id}:{instance_id}`; compare its method-id
		 * part against this handler's host-supplied pickup `method_ids`.
		 *
		 * @since 1.5.0
		 *
		 * @return bool
		 */
		protected function is_pickup_method_chosen(): bool {

			if ( ! function_exists( 'WC' ) || ! WC()->session instanceof \WC_Session ) {
				return false;
			}

			$chosen = WC()->session->get( 'chosen_shipping_methods' );

			if ( ! is_array( $chosen ) ) {
				return false;
			}

			foreach ( $chosen as $rate_id ) {
				$method_id = explode( ':', (string) $rate_id )[0];

				if ( in_array( $method_id, $this->method_ids, true ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Whether a created order uses one of this handler's pickup methods.
		 *
		 * Used during the save phase, when the order's shipping items are available.
		 *
		 * @since 1.5.0
		 *
		 * @param \WC_Order $order the created order
		 *
		 * @return bool
		 */
		protected function order_uses_pickup_method( \WC_Order $order ): bool {

			foreach ( $order->get_shipping_methods() as $item ) {
				if ( $item instanceof \WC_Order_Item_Shipping && in_array( $item->get_method_id(), $this->method_ids, true ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Enqueues the map assets and the checkout script, then localizes its config.
		 *
		 * Delegates the provider/adapter/library enqueue to the {@see Map_Provider},
		 * loads `checkout.js` depending on the provider's adapter handle (which pulls
		 * in the map core transitively), and attaches the provider-agnostic config the
		 * script reads. Intended for `wp_enqueue_scripts` on the checkout page.
		 *
		 * @since 1.5.0
		 *
		 * @return void
		 */
		public function enqueue(): void {

			$this->map_provider->enqueue_assets();

			$handle = $this->script_handle();

			wp_enqueue_script(
				$handle,
				self::asset_url( 'js/frontend/checkout.js' ),
				[ 'jquery', $this->map_provider->get_js_adapter_handle() ],
				(string) \Woodev_Plugin::VERSION,
				true
			);

			wp_localize_script( $handle, 'woodev_pickup_checkout_params', $this->build_config() );
		}

		/**
		 * Renders the modal shell and the balloon template into the page.
		 *
		 * Outputs the two views the front-end fills at runtime. Intended for a footer
		 * hook on the checkout page (e.g. `wp_footer`).
		 *
		 * @since 1.5.0
		 *
		 * @return void
		 */
		public function render(): void {

			$this->load_template(
				'html-pickup-modal.php',
				[
					'modal_id' => $this->modal_id(),
					'map_id'   => $this->map_id(),
					'title'    => $this->modal_title(),
				]
			);

			$this->load_template(
				'html-pickup-balloon.php',
				[ 'template_id' => $this->balloon_template_id() ]
			);
		}

		/**
		 * Builds the provider-agnostic config localized to the checkout script.
		 *
		 * Carries the DOM ids, the host-supplied wiring (method ids, hidden field id,
		 * AJAX actions + freshly-minted nonce), the provider's map config and any
		 * already-chosen point for prefill. The assembled config is passed through a
		 * forward filter so the host plugin can refine it (e.g. add `requestParams` or
		 * swap the adapter global) without the framework owning a contract value.
		 *
		 * @since 1.5.0
		 *
		 * @return array<string, mixed>
		 */
		private function build_config(): array {

			$config = [
				'fieldId'           => $this->field_id,
				'methodIds'         => $this->method_ids,
				'modalId'           => $this->modal_id(),
				'mapId'             => $this->map_id(),
				'balloonTemplateId' => $this->balloon_template_id(),
				'adapter'           => $this->adapter_global,
				'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
				'nonce'             => wp_create_nonce( $this->nonce_action ),
				'actions'           => [
					'fetchPoints' => $this->action_map[ Shipping_AJAX::ENDPOINT_SEARCH ] ?? '',
					'selectPoint' => $this->action_map[ Shipping_AJAX::ENDPOINT_SET ] ?? '',
				],
				'mapConfig'         => $this->map_provider->get_localized_config(),
				'requestParams'     => [],
				'selected'          => $this->selected_point(),
				'i18n'              => [
					'choose' => __( 'Select a pickup point', 'woodev-plugin-framework' ),
					'change' => __( 'Change pickup point', 'woodev-plugin-framework' ),
				],
			];

			/**
			 * Filters the config localized to the pickup checkout script.
			 *
			 * @since 1.5.0
			 *
			 * @param array<string, mixed> $config the assembled config
			 */
			return (array) apply_filters( $this->hook( 'pickup_checkout_config' ), $config );
		}

		/**
		 * Gets the already-chosen point as a plain array for prefill.
		 *
		 * @since 1.5.0
		 *
		 * @return array<string, mixed> the point array, or empty when none is selected
		 */
		private function selected_point(): array {

			$point = $this->pickup_selection->get();

			return $point instanceof Pickup_Point ? $point->to_array() : [];
		}

		/**
		 * Builds a namespaced forward-hook name (mirrors the backbone's scheme).
		 *
		 * @since 1.5.0
		 *
		 * @param string $name bare hook suffix
		 *
		 * @return string the full hook name, e.g. `woodev_shipping_{prefix}_{name}`
		 */
		private function hook( string $name ): string {

			$prefix = '' !== $this->forward_hook_prefix ? $this->forward_hook_prefix . '_' : '';

			return 'woodev_shipping_' . $prefix . $name;
		}

		/**
		 * Gets the modal-shell DOM id.
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		private function modal_id(): string {
			return 'woodev-pickup-modal-' . $this->dom_suffix();
		}

		/**
		 * Gets the map-container DOM id.
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		private function map_id(): string {
			return 'woodev-pickup-map-' . $this->dom_suffix();
		}

		/**
		 * Gets the balloon-template DOM id.
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		private function balloon_template_id(): string {
			return 'woodev-pickup-balloon-' . $this->dom_suffix();
		}

		/**
		 * Gets the registered handle for the checkout script.
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		private function script_handle(): string {
			return 'woodev-pickup-checkout-' . $this->dom_suffix();
		}

		/**
		 * Gets the modal title.
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		private function modal_title(): string {
			return __( 'Choose a pickup point', 'woodev-plugin-framework' );
		}

		/**
		 * Builds a DOM-safe suffix from the host-supplied field id.
		 *
		 * Keeps every framework-emitted id/handle unique per handler without baking in
		 * any contract value (the field id is host-owned; this only sanitizes it).
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		private function dom_suffix(): string {

			$suffix = sanitize_html_class( $this->field_id );

			return '' !== $suffix ? $suffix : 'default';
		}

		/**
		 * Includes a checkout view from the local `views/` directory.
		 *
		 * @since 1.5.0
		 *
		 * @param string               $template view file name within `views/`
		 * @param array<string, mixed> $vars     variables extracted into the view scope
		 *
		 * @return void
		 */
		private function load_template( string $template, array $vars ): void {

			$file = __DIR__ . '/views/' . $template;

			if ( ! is_readable( $file ) ) {
				return;
			}

			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- bounded, framework-supplied view vars.
			extract( $vars );

			require $file;
		}

		/**
		 * Resolves a URL within the shipping-framework assets directory.
		 *
		 * This file lives in `checkout/`, a direct child of the shipping-method root;
		 * `assets/` is that root's sibling. Resolving from this file keeps the handler
		 * self-contained — it needs no plugin instance to locate its assets.
		 *
		 * @since 1.5.0
		 *
		 * @param string $relative path relative to the assets directory
		 *
		 * @return string absolute URL to the asset
		 */
		private static function asset_url( string $relative ): string {

			$file = dirname( __DIR__ ) . '/assets/' . ltrim( $relative, '/' );

			return plugins_url( basename( $file ), $file );
		}
	}

endif;
