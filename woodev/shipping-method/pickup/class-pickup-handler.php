<?php
/**
 * Woodev Pickup Handler
 *
 * The framework's thin coordination point for the checkout pickup-point picker (SP-5
 * "pickup points + map" plan, Task 8). It hands the browser a JS-safe config (see
 * {@see self::get_js_config()}), enqueues the picker's frontend assets, registers the
 * `woodev/v1` pickup-points REST routes, and runs the server-side authority behind the
 * client's UX-only verdict: a re-check of the chosen point's constraints on
 * `woocommerce_checkout_process`, plus — when the owning plugin wires it, see
 * {@see self::__construct()} — persistence of the full normalized point alongside the id
 * §8 already saves.
 *
 * Two deliberate deviations from the SP-5 plan text, both load-bearing:
 *
 * 1. **The constructor takes a {@see Point_Source}, not a bare strategy string.** The
 *    plan's signature was `( $plugin_id, $field_id, $strategy, $provider )`. A separately
 *    passed strategy can silently disagree with what the source actually declares via
 *    {@see Point_Source::get_strategy()} — and
 *    {@see \Woodev\Framework\Shipping\Rest_Api\Pickup_Controller} now ENFORCES the
 *    source's own declaration, refusing any query that does not match it. Advertising a
 *    different strategy to the browser than the one the REST layer enforces would make
 *    the client send queries the controller silently empties — a blank map with no error.
 *    `strategy` in {@see self::get_js_config()} is therefore always
 *    `$source->get_strategy()`, never a constructor argument.
 * 2. **`replaceAddress` carries `billingOnly`, never a resolved `target`.** The plan had
 *    this emit `[ 'enabled' => bool, 'target' => Address_Target::resolve( … ) ]` — a
 *    target baked in at PHP render time. `ship_to_different_address` is a LIVE checkbox
 *    the customer can tick after the page renders; a target resolved before that goes
 *    stale, and the mount would write the pickup address into `billing_*` while the real
 *    delivery fieldset (`shipping_*`) stays untouched — silently overwriting a genuinely
 *    separate billing address, exactly what
 *    {@see \Woodev\Framework\Shipping\Pickup\Address_Target}'s own docblock exists to
 *    prevent. Only the STABLE half — `wc_ship_to_billing_address_only()`, a store setting
 *    that cannot change mid-page — is emitted; the browser re-applies
 *    {@see \Woodev\Framework\Shipping\Pickup\Address_Target::resolve()}'s rule against the
 *    live checkbox at write time.
 *
 * `replaceAddress.enabled` follows the `$replace_address` constructor argument (SP-5 Task 17,
 * default `true`) — the merchant-configurable on/off toggle. `mapConfig` comes straight from the
 * active {@see \Woodev\Framework\Shipping\Map\Map_Provider}'s own
 * {@see \Woodev\Framework\Shipping\Map\Map_Provider::get_js_config()} (SP-5 Task 9) — this
 * handler owns none of that shape itself, only passes through a request-scoped `$context`.
 * {@see self::get_settings_fields()} (SP-5 Task 16) applies the same pass-through discipline to
 * the active provider's own {@see \Woodev\Framework\Shipping\Map\Map_Provider::get_settings_fields()}
 * — the handler never learns what a map key IS, only that the active provider may want one.
 *
 * The client verdict rendered in the modal is UX only. `woocommerce_checkout_process`
 * hooks {@see self::handle_checkout_process()}, the real authority: it re-fetches the
 * chosen point via {@see Point_Source::fetch_details()} (the order only ever carries the
 * point id — the §8 field value) and re-runs {@see Constraint_Checker} against the live
 * cart weight and chosen payment method. A `null` result is an AUTHORITATIVE negative —
 * the carrier itself does not know this point — and blocks. Any THROWN exception — a
 * carrier {@see \Woodev_API_Exception}, but just as importantly a `\TypeError`, a
 * transport-library exception, or the plugin's own exception type, since
 * {@see Point_Source::fetch_details()} is a plugin seam wrapping a live carrier SDK — is a
 * failure mode entirely distinct from the identity gate §8 already guarantees: an uncaught
 * throw here is a FATAL on the checkout POST, not a validation notice, so every one of
 * them is caught (`\Throwable`, not a single exception class) and, by default, ALLOWS the
 * order through rather than converting a transient carrier hiccup or a plugin bug into a
 * lost order over a refinement check (COD + weight). This is filterable via
 * `woodev_shipping_pickup_recheck_outage_allows_checkout` for a merchant who would rather
 * block and resolve questionable points by hand; see {@see self::log_carrier_failure()}
 * for how the log line still tells a genuine carrier outage apart from an unexpected error.
 *
 * This class deliberately does NOT re-implement "a pickup point is required" —
 * {@see \Woodev\Framework\Shipping\Checkout\Checkout_Handler} already enforces that twice
 * over (a conditional-required field spec, plus an independent `requires_pickup` backstop
 * keyed by shipping-method id). A third gate here would either double the customer-visible
 * error or silently diverge from it, so a blank posted field value is simply not this
 * handler's concern.
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Pickup;

use Woodev\Framework\Shipping\Map\Map_Provider;
use Woodev\Framework\Shipping\Order\Shipping_Order_Handler;
use Woodev\Framework\Shipping\Rest_Api\Pickup_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Pickup\\Pickup_Handler' ) ) :

	/**
	 * Orchestrates the pickup-point picker: JS config, asset enqueueing, the server-side
	 * constraint re-check, and full-point persistence.
	 *
	 * @since 2.0.2
	 */
	class Pickup_Handler {

		/**
		 * Plugin identifier used to build the REST route and the JS config global name.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private string $plugin_id;

		/**
		 * The §8 checkout field id holding the chosen point code — the SAME id §8's
		 * Checkout_Handler already uses as the order-meta key for the point id.
		 *
		 * @since 2.0.2
		 * @var string
		 */
		private string $field_id;

		/**
		 * The plugin's carrier pickup-point source.
		 *
		 * @since 2.0.2
		 * @var Point_Source
		 */
		private Point_Source $source;

		/**
		 * The active map provider.
		 *
		 * Owns everything drawn inside the modal's map container; see
		 * {@see Map_Provider} for the seam. `get_js_config()` reads this provider's
		 * own {@see Map_Provider::get_id()} and
		 * {@see Map_Provider::get_js_config()} — never a bare id string —
		 * and {@see self::enqueue_assets()} enqueues under
		 * {@see Map_Provider::get_script_handle()} verbatim, so the `provider`
		 * config value and the enqueued HANDLE can never silently disagree with the
		 * provider. The enqueued script's FILE PATH is still built from
		 * {@see Map_Provider::get_id()} separately (`map-provider-{id}.js`) — that
		 * half is convention, not an enforced invariant; see
		 * {@see Map_Provider::get_script_handle()}'s own docblock.
		 *
		 * @since 2.0.2
		 * @var Map_Provider
		 */
		private Map_Provider $map_provider;

		/**
		 * The plugin's order-meta accessor, or null when the plugin has not wired
		 * full-point persistence.
		 *
		 * @since 2.0.2
		 * @var Shipping_Order_Handler|null
		 */
		private ?Shipping_Order_Handler $order_handler;

		/**
		 * The logical field name {@see Shipping_Order_Handler::store_pickup_point()} stores
		 * the full point under, or null when the plugin has not wired full-point
		 * persistence.
		 *
		 * @since 2.0.2
		 * @var string|null
		 */
		private ?string $point_field_logical;

		/**
		 * Whether the picker replaces the delivery address fields with the selected
		 * point's address (SP-5 Task 17). Only the STABLE half of the rule — this flag,
		 * plus `wc_ship_to_billing_address_only()` — travels to the browser via
		 * {@see self::get_js_config()}; see the class docblock's second deviation for why
		 * a resolved `billing`/`shipping` target never does.
		 *
		 * @since 2.0.2
		 * @var bool
		 */
		private bool $replace_address;

		/**
		 * Per-request memoization of {@see Point_Source::fetch_details()}, keyed by point
		 * id — see {@see self::fetch_point()}.
		 *
		 * @since 2.0.2
		 * @var array<string, Pickup_Point|null>
		 */
		private array $fetched_points = [];

		/**
		 * Per-request memoization of a thrown fetch failure, keyed by point id — a repeat
		 * lookup for the SAME id in the same request re-throws the same failure rather than
		 * hitting the carrier again.
		 *
		 * @since 2.0.2
		 * @var array<string, \Throwable>
		 */
		private array $fetch_failures = [];

		/**
		 * Constructor.
		 *
		 * `$order_handler` and `$point_field_logical` are optional and go together: when
		 * either is omitted, full-point persistence is skipped entirely rather than the
		 * framework coining a key of its own — see
		 * {@see self::handle_checkout_order_processed()}.
		 *
		 * @since 2.0.2
		 *
		 * @param string                      $plugin_id           plugin identifier (REST route +
		 *                                                          JS config global name).
		 * @param string                      $field_id            the §8 checkout field id holding
		 *                                                          the chosen point code.
		 * @param Point_Source                $source              the plugin's carrier
		 *                                                          pickup-point source; its
		 *                                                          {@see Point_Source::get_strategy()}
		 *                                                          is the single source of truth
		 *                                                          for the strategy — see the
		 *                                                          class docblock's first
		 *                                                          deviation.
		 * @param Map_Provider                $map_provider        the active map provider.
		 * @param Shipping_Order_Handler|null $order_handler   the plugin's order-meta
		 *                                                      accessor, holding its own
		 *                                                      logical→real key map. Omit to
		 *                                                      skip full-point persistence.
		 * @param string|null                 $point_field_logical the logical field name to store
		 *                                                          the full point under via
		 *                                                          {@see Shipping_Order_Handler::store_pickup_point()}.
		 *                                                          Omit to skip full-point
		 *                                                          persistence.
		 * @param bool                        $replace_address     whether the picker replaces the
		 *                                                          delivery address fields with the
		 *                                                          selected point's address (SP-5
		 *                                                          Task 17). Appended last, after the
		 *                                                          `$order_handler` /
		 *                                                          `$point_field_logical` pair, so
		 *                                                          the overwhelming majority of
		 *                                                          callers — who want the default
		 *                                                          `true` — never have to pass
		 *                                                          anything for it, including every
		 *                                                          caller that DOES wire full-point
		 *                                                          persistence.
		 */
		public function __construct(
			string $plugin_id,
			string $field_id,
			Point_Source $source,
			Map_Provider $map_provider,
			?Shipping_Order_Handler $order_handler = null,
			?string $point_field_logical = null,
			bool $replace_address = true
		) {
			$this->plugin_id           = $plugin_id;
			$this->field_id            = $field_id;
			$this->source              = $source;
			$this->map_provider        = $map_provider;
			$this->order_handler       = $order_handler;
			$this->point_field_logical = $point_field_logical;
			$this->replace_address     = $replace_address;
		}

		/**
		 * Builds the JS-safe config the browser mounts the picker from.
		 *
		 * Never emits a carrier credential, a callable, or the {@see Point_Source} instance
		 * itself — mirrors
		 * {@see \Woodev\Framework\Shipping\Checkout\Checkout_Config}'s discipline. See the
		 * class docblock for why `strategy` always comes from the source and
		 * `replaceAddress` never carries a resolved target. `restRoot` is the points
		 * COLLECTION url (see {@see self::rest_root()}); the client concatenates `/{id}`
		 * for the single-point detail route.
		 *
		 * @since 2.0.2
		 *
		 * @return array{
		 *     fieldId: string,
		 *     strategy: string,
		 *     provider: string,
		 *     restRoot: string,
		 *     nonce: string,
		 *     i18n: array<string, string>,
		 *     mapConfig: array<string, mixed>,
		 *     replaceAddress: array{enabled: bool, billingOnly: bool}
		 * }
		 */
		public function get_js_config(): array {
			return [
				'fieldId'  => $this->field_id,
				'strategy' => $this->source->get_strategy(),
				'provider' => $this->map_provider->get_id(),
				'restRoot' => $this->rest_root(),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'i18n'     => [
					'modalTitle'     => __( 'Выберите пункт выдачи', 'woodev-plugin-framework' ),
					'close'          => __( 'Закрыть', 'woodev-plugin-framework' ),
					'select'         => __( 'Выбрать этот пункт', 'woodev-plugin-framework' ),
					'loading'        => __( 'Загрузка пунктов выдачи…', 'woodev-plugin-framework' ),
					'error'          => __(
						'Не удалось загрузить пункты выдачи. Попробуйте ещё раз.',
						'woodev-plugin-framework'
					),
					'noResults'      => __( 'Пункты выдачи не найдены.', 'woodev-plugin-framework' ),
					'blocked'        => __(
						'Этот пункт выдачи недоступен для вашего заказа.',
						'woodev-plugin-framework'
					),
					// Consumed by the mount script (Task 12), not by the modal shell or the map
					// provider — see Pickup_Mount's own docblock for why it reads these keys.
					'trigger'        => __( 'Выбрать пункт выдачи', 'woodev-plugin-framework' ),
					'retry'          => __( 'Повторить', 'woodev-plugin-framework' ),
					'upstreamError'  => __(
						'Сервис пунктов выдачи временно недоступен. Попробуйте ещё раз позже.',
						'woodev-plugin-framework'
					),
					'rateLimited'    => __(
						'Слишком много запросов. Подождите немного и попробуйте снова.',
						'woodev-plugin-framework'
					),
					'notFound'       => __(
						'Этот пункт выдачи больше не найден. Пожалуйста, выберите другой.',
						'woodev-plugin-framework'
					),
					// Consumed by the map provider scripts (Tasks 13/14), not by this handler or
					// the modal shell — a missing key here renders BLANK in the provider's UI
					// rather than throwing, so every one of these nine must stay exact: the
					// provider reads them by name, never falls back to a hardcoded default.
					'search'         => __( 'Поиск по адресу', 'woodev-plugin-framework' ),
					'drawerTitle'    => __( 'Пункты выдачи в этой области', 'woodev-plugin-framework' ),
					'howToGet'       => __( 'Как добраться', 'woodev-plugin-framework' ),
					'paymentMethods' => __( 'Способы оплаты', 'woodev-plugin-framework' ),
					'workTime'       => __( 'Часы работы', 'woodev-plugin-framework' ),
					'phone'          => __( 'Телефон', 'woodev-plugin-framework' ),
					'maxWeight'      => __( 'Максимальный вес', 'woodev-plugin-framework' ),
					'allTypes'       => __( 'Все типы пунктов', 'woodev-plugin-framework' ),
					'detailsError'   => __(
						'Не удалось загрузить подробности о пункте выдачи. Вы всё ещё можете его выбрать.',
						'woodev-plugin-framework'
					),
				],

				'mapConfig'      => $this->map_provider->get_js_config( [ 'plugin_id' => $this->plugin_id ] ),

				'replaceAddress' => [
					'enabled'     => $this->replace_address,
					'billingOnly' => (bool) wc_ship_to_billing_address_only(),
				],
			];
		}

		/**
		 * Gets the settings fields the active map provider needs, if any.
		 *
		 * A pure pass-through to {@see Map_Provider::get_settings_fields()} — this handler
		 * does not know what a map key IS, only that the active provider may want one (SP-5
		 * Task 16).
		 *
		 * This is NOT automatic. Nothing on the framework side calls this method — the
		 * plugin that owns the shipping integration MUST call it itself and merge the
		 * result into its own settings registration for the merchant-facing
		 * `map_api_key` field to exist at all. Spec §10.8 amends §4.7's "auto-registers"
		 * wording, which described the field as something a plugin "automatically gains";
		 * the framework cannot register a field into a plugin's own settings provider
		 * without owning it, the same boundary §10.6 already drew for the fallback key.
		 * Skip the call and every install of the plugin stays pinned to the plugin's
		 * shared fallback key — exactly the quota risk §4.7 flagged as a watch item.
		 *
		 * The descriptor is returned UNMODIFIED — in the Woodev settings-API
		 * `register_setting()` args shape (`name`, `type`, `default`, `required`,
		 * `sensitive`, `description`), not the WooCommerce `form_fields` shape — so the
		 * caller merges it into the shipping integration's own settings as-is.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string, array<string, mixed>> settings field definitions keyed by field id.
		 */
		public function get_settings_fields(): array {
			return $this->map_provider->get_settings_fields();
		}

		/**
		 * Re-checks a resolved point's constraints against the cart and payment method.
		 *
		 * Pure constraint check — delegates to {@see Constraint_Checker}, adding a
		 * WooCommerce error notice (which blocks placement) on failure. Does not itself
		 * fetch anything; see {@see self::validate_posted_point()} for the id-based entry
		 * point {@see self::handle_checkout_process()} actually calls.
		 *
		 * @since 2.0.2
		 *
		 * @param Pickup_Point $point          the resolved point to re-check.
		 * @param string       $payment_method the chosen WooCommerce payment method id.
		 * @param int          $cart_weight    current cart weight in GRAMS.
		 *
		 * @return bool true when the point is still selectable; false when checkout is blocked.
		 */
		public function validate_selected_point( Pickup_Point $point, string $payment_method, int $cart_weight ): bool {
			$verdict = ( new Constraint_Checker() )->check( $point, $payment_method, $cart_weight );

			if ( ! $verdict['allowed'] ) {
				$reason = null !== $verdict['reason'] ? $verdict['reason'] : self::default_blocked_message();
				$this->add_error( $reason );

				return false;
			}

			return true;
		}

		/**
		 * Resolves the posted point id via the {@see Point_Source} and re-checks it — the
		 * authoritative server-side backstop behind the client's UX-only verdict.
		 *
		 * Three outcomes:
		 * - The point is found → delegates to {@see self::validate_selected_point()}.
		 * - `null` → an AUTHORITATIVE negative (the carrier itself does not know this
		 *   point); blocks with a message asking the customer to choose again.
		 * - Any thrown `\Throwable` (a carrier `\Woodev_API_Exception`, but also a
		 *   `\TypeError`, a transport-library exception, or the plugin's own exception
		 *   type — see the class docblock) → NOT an identity failure; ALLOWS the order
		 *   through by default (filterable — see {@see self::evaluate_recheck_outage()})
		 *   and logs.
		 *
		 * @since 2.0.2
		 *
		 * @param string $point_id       the posted point id (the §8 field value).
		 * @param string $payment_method the chosen WooCommerce payment method id.
		 * @param int    $cart_weight    current cart weight in GRAMS.
		 *
		 * @return bool true when checkout may proceed; false when it is blocked.
		 */
		public function validate_posted_point( string $point_id, string $payment_method, int $cart_weight ): bool {
			try {
				$point = $this->fetch_point( $point_id );
			} catch ( \Throwable $e ) {
				return $this->evaluate_recheck_outage( $e, $point_id );
			}

			if ( null === $point ) {
				$this->add_error( self::point_unavailable_message() );

				return false;
			}

			return $this->validate_selected_point( $point, $payment_method, $cart_weight );
		}

		/**
		 * Fetches a point via the {@see Point_Source}, memoized per instance by point id.
		 *
		 * `woocommerce_checkout_process` and `woocommerce_checkout_order_processed` both
		 * fire inside the SAME `WC_Checkout::process_checkout()` call against the SAME
		 * `$_POST`, and both need the same point — without memoization that is two carrier
		 * round-trips on the most latency-sensitive request in the store, the second one
		 * AFTER the order already exists (delaying the thank-you redirect for an order
		 * already placed). Safe because a `Pickup_Handler` instance is built once per
		 * request; a repeat lookup for the SAME id re-throws the SAME failure rather than
		 * calling the carrier again.
		 *
		 * @since 2.0.2
		 *
		 * @param string $point_id the point id to fetch.
		 *
		 * @return Pickup_Point|null
		 *
		 * @throws \Throwable whatever {@see Point_Source::fetch_details()} threw on the
		 *                     first lookup for this id.
		 */
		private function fetch_point( string $point_id ): ?Pickup_Point {
			if ( array_key_exists( $point_id, $this->fetch_failures ) ) {
				throw $this->fetch_failures[ $point_id ];
			}

			if ( array_key_exists( $point_id, $this->fetched_points ) ) {
				return $this->fetched_points[ $point_id ];
			}

			try {
				$point = $this->source->fetch_details( $point_id );
			} catch ( \Throwable $e ) {
				$this->fetch_failures[ $point_id ] = $e;

				throw $e;
			}

			$this->fetched_points[ $point_id ] = $point;

			return $point;
		}

		/**
		 * Evaluates whether an outage encountered while re-checking the posted point
		 * allows checkout to proceed.
		 *
		 * Not a hook callback despite the `\Throwable` parameter — named without the
		 * `handle_` prefix this project reserves for `add_action`/`add_filter` targets.
		 * Logs the real exception (never shown to the customer, see
		 * {@see self::log_carrier_failure()}) and applies the
		 * `woodev_shipping_pickup_recheck_outage_allows_checkout` filter, which defaults to
		 * ALLOW — see the class docblock for why an outage (or any other thrown failure)
		 * must not block checkout by default.
		 *
		 * @since 2.0.2
		 *
		 * @param \Throwable $e        the caught exception — a carrier outage or an
		 *                             unexpected error (see {@see self::log_carrier_failure()}).
		 * @param string     $point_id the point id that failed to resolve.
		 *
		 * @return bool true when checkout may proceed despite the failure; false when blocked.
		 */
		private function evaluate_recheck_outage( \Throwable $e, string $point_id ): bool {
			$this->log_carrier_failure( $e, 'checkout re-check' );

			/**
			 * Filters whether a carrier outage (or other unexpected failure) during the
			 * checkout re-check allows checkout to proceed.
			 *
			 * Defaults to `true`: the re-check is a refinement (COD + weight), not the
			 * identity gate §8 already guarantees, and blocking on a transient failure
			 * converts it into a lost order. A merchant who would rather block and resolve
			 * questionable points by hand can return `false`.
			 *
			 * @since 2.0.2
			 *
			 * @param bool       $allow     Whether checkout may proceed. Default true.
			 * @param \Throwable $exception The caught exception.
			 * @param string     $plugin_id The owning plugin id.
			 * @param string     $point_id  The point id that failed to resolve.
			 */
			$allow = (bool) apply_filters(
				'woodev_shipping_pickup_recheck_outage_allows_checkout',
				true,
				$e,
				$this->plugin_id,
				$point_id
			);

			if ( ! $allow ) {
				$this->add_error( self::outage_blocked_message() );
			}

			return $allow;
		}

		/**
		 * Wires the handler into WooCommerce checkout.
		 *
		 * Enqueues the picker's frontend assets on `wp_enqueue_scripts` (checkout page
		 * only), registers the `woodev/v1` pickup-points REST routes on `rest_api_init`
		 * (see {@see self::register_rest()} — closing the gap where a plugin wires this
		 * handler but forgets {@see Pickup_Controller}, and the map fetches 404 with no
		 * diagnostic), hooks the server-side re-check onto `woocommerce_checkout_process`,
		 * and hooks full-point persistence onto `woocommerce_checkout_order_processed` —
		 * which fires AFTER the order is saved, matching
		 * {@see \Woodev\Framework\Shipping\Checkout\Checkout_Handler::register()}'s own
		 * reasoning for why persisting any earlier silently drops the meta on classic
		 * storage.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function register(): void {
			add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
			add_action( 'rest_api_init', [ $this, 'register_rest' ] );
			add_action( 'woocommerce_checkout_process', [ $this, 'handle_checkout_process' ] );
			add_action( 'woocommerce_checkout_order_processed', [ $this, 'handle_checkout_order_processed' ], 10, 3 );
		}

		/**
		 * Registers the `woodev/v1` pickup-points REST routes for this handler.
		 *
		 * Constructs {@see Pickup_Controller} with the SAME {@see Point_Source} this
		 * handler re-checks against, and with this handler's own request-context readers —
		 * {@see self::current_cart_weight_grams()} and {@see self::posted_payment_method()}
		 * — as its cart-weight and payment-method callables, closing the asymmetry where
		 * §8's `Checkout_Handler::register()` wires its own REST controller but nothing
		 * wired this one.
		 *
		 * The points/detail GET requests these routes actually serve fire on map panning,
		 * before the checkout form is ever posted, so `$_POST['payment_method']` is empty.
		 * That is NOT the same as the payment method being unknown — the "unknown is
		 * permissive" rule {@see Constraint_Checker::check()} documents was written for a
		 * carrier's genuinely sparse list response, where the framework has no way to learn
		 * the missing value at all. Here the value is knowable: WooCommerce writes the
		 * customer's live choice to `WC()->session` on every `update_order_review` ajax call
		 * the checkout form fires the instant a payment method is picked, and
		 * {@see self::posted_payment_method()} now reads it — see that method's own docblock
		 * for why treating this as permissive was the bug an SP-5 rig e2e caught, not a
		 * legitimate use of the sparse-response rule.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function register_rest(): void {
			( new Pickup_Controller(
				$this->plugin_id,
				$this->source,
				[ $this, 'current_cart_weight_grams' ],
				[ $this, 'posted_payment_method' ]
			) )->register_routes();
		}

		/**
		 * Enqueues the picker's frontend assets on the checkout page.
		 *
		 * Registers handles for JS files owned by SP-5 tasks — the modal shell
		 * (`pickup-modal.js`, Task 10), the dataSource (`pickup-datasource.js`, Task 11),
		 * the mount script (`pickup-mount.js`, Task 12) and the active provider's script
		 * (`map-provider-{$provider}.js`, Tasks 13/14) have all LANDED; only the
		 * stylesheet (`pickup.css`, Task 15) has NOT. That still-pending file is skipped
		 * entirely via {@see self::enqueue_style_if_built()}'s {@see self::asset_exists()}
		 * check rather than enqueueing a `href` that will 404 — a missing style tag is
		 * invisible, but a 404ing one next to fully wired JS is a live checkout regression
		 * waiting for Task 15 to land. The mount config is therefore localized only when
		 * the mount script itself was actually enqueued (it now always is, since every one
		 * of its declared dependencies — including the provider handle — is a registered
		 * handle as of this fix; see
		 * {@see PickupHandlerTest::test_enqueue_assets_enqueues_only_the_assets_already_built()}).
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function enqueue_assets(): void {
			if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
				return;
			}

			$provider_handle = $this->map_provider->get_script_handle();

			$this->enqueue_script_if_built( 'woodev-pickup-modal', 'js/frontend/pickup-modal.js', [] );
			$this->enqueue_script_if_built( 'woodev-pickup-datasource', 'js/frontend/pickup-datasource.js', [] );
			$this->enqueue_script_if_built(
				$provider_handle,
				'js/frontend/map-provider-' . $this->map_provider->get_id() . '.js',
				[]
			);

			// `jquery`: the mount script binds `updated_checkout` through jQuery when it is
			// present (see pickup-mount.js's own docblock) — declared explicitly here rather
			// than free-riding on `checkout-field-classic.js` happening to also require it.
			$mount_enqueued = $this->enqueue_script_if_built(
				'woodev-pickup-mount',
				'js/frontend/pickup-mount.js',
				[ 'jquery', 'woodev-pickup-modal', 'woodev-pickup-datasource', $provider_handle ]
			);

			$this->enqueue_style_if_built( 'woodev-pickup-styles', 'css/frontend/pickup.css' );

			if ( $mount_enqueued ) {
				wp_localize_script(
					'woodev-pickup-mount',
					'woodev_pickup_config_' . $this->config_object_suffix(),
					$this->get_js_config()
				);
			}
		}

		/**
		 * Enqueues one script handle, but only when its file actually exists on disk.
		 *
		 * @since 2.0.2
		 *
		 * @param string   $handle   the script handle to register.
		 * @param string   $relative path relative to the assets directory.
		 * @param string[] $deps     script dependencies.
		 *
		 * @return bool true when the script was enqueued; false when its file is missing.
		 */
		private function enqueue_script_if_built( string $handle, string $relative, array $deps ): bool {
			$path = self::asset_path( $relative );

			if ( ! static::asset_exists( $path ) ) {
				return false;
			}

			wp_enqueue_script( $handle, self::asset_url( $relative ), $deps, self::asset_version( $path ), true );

			return true;
		}

		/**
		 * Enqueues one stylesheet handle, but only when its file actually exists on disk.
		 *
		 * @since 2.0.2
		 *
		 * @param string $handle   the style handle to register.
		 * @param string $relative path relative to the assets directory.
		 *
		 * @return bool true when the style was enqueued; false when its file is missing.
		 */
		private function enqueue_style_if_built( string $handle, string $relative ): bool {
			$path = self::asset_path( $relative );

			if ( ! static::asset_exists( $path ) ) {
				return false;
			}

			wp_enqueue_style( $handle, self::asset_url( $relative ), [], self::asset_version( $path ) );

			return true;
		}

		/**
		 * Reads the posted value of the managed pickup field.
		 *
		 * Guards with `is_scalar()` before casting: an array-valued `$_POST[$field_id]`
		 * (e.g. a malformed or multi-value form field) would otherwise emit an "Array to
		 * string conversion" notice, silently become the literal string `"Array"`, and
		 * trigger a pointless carrier lookup for point id `"Array"`.
		 *
		 * @since 2.0.2
		 *
		 * @return string sanitized field value, or empty string when absent or non-scalar.
		 */
		protected function posted_field_value(): string {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC verifies the nonce before hooks fire.
			$value = $_POST[ $this->field_id ] ?? '';

			return is_scalar( $value ) ? wc_clean( (string) wp_unslash( $value ) ) : '';
		}

		/**
		 * Reads the chosen WooCommerce payment method (gateway) id.
		 *
		 * `public`, not `protected`: also used as the payment-method callable
		 * {@see self::register_rest()} hands to {@see Pickup_Controller} — see
		 * {@see self::current_cart_weight_grams()} for why that requires public visibility.
		 *
		 * `$_POST['payment_method']` is tried FIRST and returned as-is when present — it is
		 * authoritative on `woocommerce_checkout_process`
		 * ({@see self::handle_checkout_process()}), where WooCommerce has already verified
		 * the checkout nonce and the posted value IS the customer's final choice. This
		 * method's behaviour on THAT call site is unchanged by the fallback below.
		 *
		 * The points/detail REST routes ({@see self::register_rest()}) are GET requests
		 * fired while the customer pans the map, well before the checkout form ever posts —
		 * `$_POST` is empty there, not because the payment method is unknown, but because
		 * nothing has been submitted yet. Returning `''` unconditionally in that case (the
		 * previous behaviour) silently disabled the §4.5 COD gate for every map request:
		 * `''` is never in {@see Constraint_Checker}'s COD method list, so a COD-refusing
		 * point always looked selectable. The value IS knowable server-side — WooCommerce
		 * writes it to `WC()->session` under `chosen_payment_method` on every
		 * `update_order_review` ajax call the checkout form fires the moment a payment
		 * method is picked — so this falls back to {@see self::wc_session_chosen_payment_method()}
		 * instead. A REQUEST PARAMETER would be the obvious alternative and is deliberately
		 * NOT used: it is client-supplied, so it cannot serve a §4.5 verdict that spec
		 * requires to be server-authoritative; the session is the only server-side source a
		 * GET request has.
		 *
		 * Guards with `is_scalar()` for the same reason as {@see self::posted_field_value()}.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function posted_payment_method(): string {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC verifies the nonce before hooks fire.
			$posted = $_POST['payment_method'] ?? '';
			$posted = is_scalar( $posted ) ? wc_clean( (string) wp_unslash( $posted ) ) : '';

			if ( '' !== $posted ) {
				return $posted;
			}

			$chosen = $this->wc_session_chosen_payment_method();

			return is_scalar( $chosen ) ? wc_clean( (string) $chosen ) : '';
		}

		/**
		 * Reads WooCommerce's own record of the customer's live payment-method choice —
		 * `WC()->session->get( 'chosen_payment_method' )` — or `null` when WooCommerce is
		 * unavailable or no session has been started yet (WC loaded but no session store
		 * started, the same "not wrong, just not there yet" case
		 * {@see self::wc_cart()} guards for the cart).
		 *
		 * `protected`, not inlined into {@see self::posted_payment_method()}: a test
		 * subclass overrides this single line to exercise the fallback's precedence and
		 * value handling WITHOUT `WC()` needing to be a real function in the unit-test
		 * process — see {@see self::asset_exists()}'s own docblock for why this project's
		 * test doubles override a single forwarding seam rather than faking WordPress
		 * globals. Every real call site is this method's own default body; only
		 * `PickupHandlerTest`'s probes override it.
		 *
		 * @since 2.0.2
		 *
		 * @return mixed the raw session value, or null when unavailable.
		 */
		protected function wc_session_chosen_payment_method() {
			if ( ! function_exists( 'WC' ) || ! WC()->session ) {
				return null;
			}

			return WC()->session->get( 'chosen_payment_method' );
		}

		/**
		 * Returns the current WooCommerce cart weight in GRAMS.
		 *
		 * `public`, not `protected`: {@see self::register_rest()} hands this method,
		 * `[ $this, 'current_cart_weight_grams' ]`, to {@see Pickup_Controller} as its
		 * injected cart-weight callable — a PHP callable array invoked from OUTSIDE this
		 * class's scope requires public visibility, or the call fatals with "Call to
		 * protected method ... from scope Pickup_Controller".
		 *
		 * On `woocommerce_checkout_process` ({@see self::handle_checkout_process()}) the
		 * cart is already loaded — WooCommerce guarantees a live cart/session by the time
		 * that hook fires — so the load-fallback below never triggers there; a `0` from
		 * THAT caller still only means an actually-empty cart (or `WC()` being unavailable,
		 * e.g. in a unit test), exactly as before this fix. This method's behaviour on that
		 * call site is unchanged.
		 *
		 * The points/detail REST routes ({@see self::register_rest()}) are a different
		 * story: WooCommerce does NOT initialize `WC()->cart` for a plain custom REST route
		 * (only the core Store API does, via `wc_load_cart()`, for this exact reason) — a
		 * customer panning the map before `update_order_review` has ever run finds
		 * `WC()->cart` null even though a real cart/session exists. Returning `0`
		 * unconditionally in that case (the previous behaviour) silently disabled the §4.5
		 * weight-limit gate for every map request. `wc_load_cart()` (WC 3.6+, guarded by
		 * {@see self::wc_load_cart_available()}) is exactly what the Store API itself calls
		 * to bridge this gap, so this method now does the same — but ONLY when the cart is
		 * not already loaded, never a redundant reload on the checkout-process path.
		 * {@see Pickup_Controller::get_points_data()} and
		 * {@see Pickup_Controller::get_point_data()} each invoke this callable at most ONCE
		 * per REST request — never once per returned point — so the extra initialization
		 * cost lands once per debounced map pan (client-side, 300ms), not once per point on
		 * screen; the same amortization the Store API's own callers already accept for the
		 * identical `wc_load_cart()` call. A cart that still cannot be loaded (WooCommerce
		 * absent, or the load itself leaves `WC()->cart` null) legitimately stays `0` —
		 * permissive, matching {@see Pickup_Controller}'s own docblock for why `0` is a
		 * frequent, expected answer on this path.
		 *
		 * Delegates the actual unit conversion to
		 * {@see Constraint_Checker::to_grams()}, the single conversion authority both this
		 * class and {@see Pickup_Controller} must share.
		 *
		 * @since 2.0.2
		 *
		 * @return int
		 */
		public function current_cart_weight_grams(): int {
			$cart = $this->wc_cart();

			if ( ! $cart && $this->wc_load_cart_available() ) {
				$this->load_wc_cart();
				$cart = $this->wc_cart();
			}

			if ( ! $cart ) {
				return 0;
			}

			return Constraint_Checker::to_grams( $cart->get_cart_contents_weight() );
		}

		/**
		 * Reads the live `WC()->cart`, or `null` when WooCommerce is unavailable or no
		 * cart has been initialized for this request yet.
		 *
		 * `protected`, not inlined into {@see self::current_cart_weight_grams()}: a test
		 * subclass overrides this single line to simulate every cart-availability
		 * combination WITHOUT `WC()` needing to be a real function in the unit-test
		 * process — mirrors {@see self::asset_exists()}'s own seam, added for the identical
		 * reason (there, faking a built asset without writing one to disk; here, faking a
		 * WooCommerce global without loading WooCommerce). Every real call site is this
		 * method's own default body; only `PickupHandlerTest`'s probes override it.
		 *
		 * @since 2.0.2
		 *
		 * @return object|null
		 */
		protected function wc_cart() {
			return function_exists( 'WC' ) ? WC()->cart : null;
		}

		/**
		 * Reports whether `wc_load_cart()` exists (WooCommerce 3.6+).
		 *
		 * `protected` for the same test-seam reason as {@see self::wc_cart()}.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		protected function wc_load_cart_available(): bool {
			return function_exists( 'wc_load_cart' );
		}

		/**
		 * Initializes the WooCommerce cart via `wc_load_cart()` — the same call WooCommerce's
		 * own Store API makes to bridge the identical gap (see
		 * {@see self::current_cart_weight_grams()}'s own docblock).
		 *
		 * `protected` for the same test-seam reason as {@see self::wc_cart()}: a probe
		 * overrides this to simulate a successful (or still-failed) load without the real
		 * `wc_load_cart()` function needing to exist in the unit-test process.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		protected function load_wc_cart(): void {
			wc_load_cart();
		}

		/**
		 * Handles `woocommerce_checkout_process` — the server-side constraint re-check.
		 *
		 * A blank posted field value means nothing is our concern here — see the class
		 * docblock's "does NOT re-implement a pickup point is required" note.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function handle_checkout_process(): void {
			$point_id = $this->posted_field_value();

			if ( '' === $point_id ) {
				return;
			}

			$this->validate_posted_point(
				$point_id,
				$this->posted_payment_method(),
				$this->current_cart_weight_grams()
			);
		}

		/**
		 * Handles `woocommerce_checkout_order_processed` — persists the full normalized
		 * point alongside the id §8 already saved, when the owning plugin has wired
		 * full-point persistence.
		 *
		 * Skips ENTIRELY when {@see self::$order_handler} or
		 * {@see self::$point_field_logical} is absent (see {@see self::__construct()}) —
		 * the framework must not coin an installed-site order-meta key of its own; the
		 * plugin-supplied key map on {@see Shipping_Order_Handler} is the only sanctioned
		 * destination (see
		 * {@see Shipping_Order_Handler::store_pickup_point()}). The id itself is already
		 * persisted by §8 regardless, so a plugin that has not wired this loses nothing
		 * beyond the extra copy.
		 *
		 * Re-reads the field value from `$_POST` directly (rather than trusting
		 * `$posted_data`), mirroring
		 * {@see \Woodev\Framework\Shipping\Checkout\Checkout_Handler::handle_checkout_order_processed()}'s
		 * own convention. A blank value, an unknown point, or any thrown `\Throwable`
		 * while re-fetching are all degraded-but-safe: this method only ever adds to the
		 * id §8 already persisted and never blocks or (re-)throws after the order exists —
		 * see the class docblock for why an uncaught throw here is strictly worse than one
		 * on `woocommerce_checkout_process` (the order row is already committed, and later
		 * priority callbacks on this same hook — including other plugins' — never run).
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param int                  $order_id    the created order id (unused; see above).
		 * @param array<string, mixed> $posted_data the posted checkout data (unused; see above).
		 * @param \WC_Order            $order       the created, saved order.
		 *
		 * @return void
		 */
		public function handle_checkout_order_processed( int $order_id, array $posted_data, \WC_Order $order ): void {
			if ( null === $this->order_handler || null === $this->point_field_logical ) {
				return;
			}

			$point_id = $this->posted_field_value();

			if ( '' === $point_id ) {
				return;
			}

			try {
				$point = $this->fetch_point( $point_id );
			} catch ( \Throwable $e ) {
				$this->log_carrier_failure( $e, 'order persistence re-fetch' );

				return;
			}

			if ( null === $point ) {
				return;
			}

			// store_pickup_point() writes to_array() — the canonical, UNESCAPED
			// representation — through the plugin's own logical→real key map. Order meta
			// is data at rest that a later stage sends back to the carrier on export;
			// to_browser_array() exists solely for the REST response and must never reach
			// the database.
			$this->order_handler->store_pickup_point( $order, $this->point_field_logical, $point );
		}

		/**
		 * Logs a swallowed exception's real message.
		 *
		 * `protected`, not `private`, so a test subclass can override and silence it rather
		 * than letting a fake, credential-shaped carrier message reach real test stderr —
		 * mirrors
		 * {@see \Woodev\Framework\Shipping\Rest_Api\Pickup_Controller::log_carrier_failure()}.
		 * Accepts `\Throwable`, not just `\Woodev_API_Exception` (see the class docblock):
		 * {@see Point_Source::fetch_details()} is a plugin seam wrapping a live carrier
		 * SDK, so a `\TypeError`, a transport-library exception, or the plugin's own
		 * exception type all reach here too. The log line still tells a genuine carrier
		 * outage apart from an unexpected error — a merchant reading the log needs to know
		 * whether to blame the carrier or file a plugin bug.
		 *
		 * @since 2.0.2
		 *
		 * @param \Throwable $e       the caught exception.
		 * @param string     $context short description of the failing call.
		 *
		 * @return void
		 */
		protected function log_carrier_failure( \Throwable $e, string $context ): void {
			$kind = $e instanceof \Woodev_API_Exception ? 'carrier outage' : 'unexpected error';

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic
			// for a carrier failure; the browser/customer only ever sees a generic notice.
			error_log(
				sprintf(
					'[woodev] pickup %s failed for plugin "%s" (%s): %s',
					$context,
					$this->plugin_id,
					$kind,
					$e->getMessage()
				)
			);
		}

		/**
		 * Adds a WooCommerce error notice when one is available.
		 *
		 * @since 2.0.2
		 *
		 * @param string $message the error message.
		 *
		 * @return void
		 */
		private function add_error( string $message ): void {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( $message, 'error' );
			}
		}

		/**
		 * Default "blocked" message used only if a filtered {@see Constraint_Checker}
		 * verdict is blocked with a null reason (should not happen in practice, but
		 * `wc_add_notice()` must never receive an empty string).
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		private static function default_blocked_message(): string {
			return __(
				'Этот пункт выдачи недоступен для вашего заказа. Пожалуйста, выберите другой пункт выдачи.',
				'woodev-plugin-framework'
			);
		}

		/**
		 * Message shown when the carrier authoritatively does not know the posted point.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		private static function point_unavailable_message(): string {
			return __(
				'Выбранный пункт выдачи больше недоступен. Пожалуйста, выберите пункт выдачи заново.',
				'woodev-plugin-framework'
			);
		}

		/**
		 * Message shown when a merchant's outage filter blocks checkout instead of
		 * allowing it through.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		private static function outage_blocked_message(): string {
			return __(
				'Не удалось проверить пункт выдачи. Пожалуйста, повторите оформление заказа через несколько минут.',
				'woodev-plugin-framework'
			);
		}

		/**
		 * Builds the REST route the browser fetches pickup points from.
		 *
		 * Mirrors
		 * {@see \Woodev\Framework\Shipping\Rest_Api\Pickup_Controller::register_routes()}'s
		 * own plugin-segment derivation exactly, so the emitted URL always matches the
		 * ACTUALLY registered route.
		 *
		 * Despite the key name, this is the points COLLECTION url (`GET .../points`), not
		 * a root the client resolves further sub-paths against generically — the mount
		 * task concatenates `/{id}` onto it for the single-point detail route
		 * (`GET .../points/{id}`, see {@see Pickup_Controller::register_routes()}).
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		private function rest_root(): string {
			return rtrim( rest_url( 'woodev/v1' ), '/' ) . '/shipping/pickup/' . $this->plugin_segment() . '/points';
		}

		/**
		 * Sanitizes the plugin id into a route-safe segment, falling back to `shipping`.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		private function plugin_segment(): string {
			$segment = preg_replace( '/[^\w-]/', '', $this->plugin_id );

			return '' !== (string) $segment ? (string) $segment : 'shipping';
		}

		/**
		 * Returns a JS-identifier-safe version of the plugin id, used as the suffix in the
		 * `woodev_pickup_config_{suffix}` JS config global name.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		private function config_object_suffix(): string {
			return preg_replace( '/[^a-z0-9_]/i', '_', $this->plugin_id );
		}

		/**
		 * Resolves the filesystem path to a shipping-framework asset.
		 *
		 * Mirrors
		 * {@see \Woodev\Framework\Shipping\Checkout\Checkout_Handler}'s own asset-path
		 * helper. This file lives in `pickup/`, a direct child of the shipping-method root;
		 * `assets/` is ALSO a direct child of that root — a sibling of `pickup/`, not of
		 * this file's own directory.
		 *
		 * @since 2.0.2
		 *
		 * @param string $relative path relative to the assets directory.
		 *
		 * @return string absolute filesystem path to the asset.
		 */
		private static function asset_path( string $relative ): string {
			return dirname( __DIR__ ) . '/assets/' . ltrim( $relative, '/' );
		}

		/**
		 * Resolves a URL within the shipping-framework assets directory.
		 *
		 * @since 2.0.2
		 *
		 * @param string $relative path relative to the assets directory.
		 *
		 * @return string absolute URL to the asset.
		 */
		private static function asset_url( string $relative ): string {
			$file = self::asset_path( $relative );

			return plugins_url( basename( $file ), $file );
		}

		/**
		 * Reports whether an asset file exists on disk.
		 *
		 * `protected static`, not `private`, purely so a test subclass can force it `true`
		 * to exercise {@see self::enqueue_script_if_built()}/{@see self::enqueue_style_if_built()}'s
		 * "built" branch without writing a real file into the assets directory. The real
		 * (default) implementation is a plain `file_exists()` — each SP-5 asset reports
		 * `true`/`false` independently as its own task lands (`pickup-modal.js` as of
		 * Task 10, `pickup-datasource.js` as of Task 11; `pickup-mount.js`, the
		 * map-provider script, and the stylesheet still report `false` until Tasks 12–15
		 * build them), which is exactly the "skip a not-yet-built asset" behaviour
		 * {@see self::enqueue_assets()} exists to get right.
		 *
		 * @since 2.0.2
		 *
		 * @param string $path absolute filesystem path to the asset.
		 *
		 * @return bool
		 */
		protected static function asset_exists( string $path ): bool {
			return file_exists( $path );
		}

		/**
		 * Resolves an asset's cache-busting version string.
		 *
		 * Uses the file's own mtime (so a later task's file gets a fresh version the
		 * moment it lands), falling back to {@see \Woodev_Plugin::VERSION} only as a
		 * defensive fallback for a file removed between
		 * {@see self::asset_exists()}'s check and this call — every real call site checks
		 * `asset_exists()` first, so the fallback branch is not expected to be reached in
		 * practice.
		 *
		 * @since 2.0.2
		 *
		 * @param string $path absolute filesystem path to the asset.
		 *
		 * @return string
		 */
		private static function asset_version( string $path ): string {
			return file_exists( $path ) ? (string) filemtime( $path ) : (string) \Woodev_Plugin::VERSION;
		}
	}

endif;
