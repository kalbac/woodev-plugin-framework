<?php
/**
 * Tests for Pickup_Handler — the checkout pickup-point picker's coordination point
 * (SP-5 "pickup points + map" plan, Task 8): the JS config (no secrets, no callables,
 * strategy derived from the source, `billingOnly` not a resolved target), the
 * server-side constraint re-check (all three `validate_posted_point()` outcomes,
 * catching `\Throwable` — not just `\Woodev_API_Exception` — plus the outage filter),
 * per-request fetch memoization, REST controller registration, and full-point
 * persistence delegated to `Shipping_Order_Handler::store_pickup_point()` (never a
 * framework-coined meta key). Also covers Task 16 (`get_settings_fields()` as a pure,
 * unmodified pass-through to the active `Map_Provider`) and Task 17's `replaceAddress`
 * shape (`billingOnly` mirrors `wc_ship_to_billing_address_only()`, `target` never
 * emitted) and the nine map-provider i18n keys `get_js_config()` now carries.
 *
 * Task 8 (issue #362, design S7) removed the `$replace_address`/`$close_on_select`
 * constructor arguments (clean-break v2 line, ADR-005): `replaceAddress.enabled` and
 * `selection.close` now read a STORE setting via `Pickup_Map_Settings::current()`
 * instead — a customer sees both across every carrier at once, so the store decides
 * them, never a per-carrier constructor argument. `selection.refreshCheckout` stays a
 * constructor argument (a carrier's own price-behaviour fact).
 *
 * @package Woodev\Tests\Unit\Shipping\Pickup
 */

namespace {

	if ( ! class_exists( 'WC_Order' ) ) {
		/**
		 * Minimal global \WC_Order stand-in. `get_id()` is needed because the real
		 * persistence path now runs through {@see \Woodev_Order_Compatibility::update_order_meta()},
		 * whose non-HPOS branch calls it.
		 */
		class WC_Order {
			public function get_id() {
				return 123;
			}
		}
	}

	if ( ! class_exists( 'Woodev_Plugin' ) ) {
		/**
		 * Minimal global \Woodev_Plugin stand-in, providing only the VERSION constant
		 * Pickup_Handler::asset_version() falls back to when an asset file does not yet
		 * exist on disk (true for every asset this task registers — see enqueue_assets()).
		 */
		class Woodev_Plugin {
			const VERSION = '2.0.2-test';
		}
	}
}

namespace Woodev\Tests\Unit\Shipping\Pickup {

	use Brain\Monkey\Filters;
	use Brain\Monkey\Functions;
	use Woodev\Framework\Shipping\Location\Abstract_Location_Provider;
	use Woodev\Framework\Shipping\Location\Customer_Location_Store;
	use Woodev\Framework\Shipping\Location\Location_Adapter;
	use Woodev\Framework\Shipping\Location\Location_Provider;
	use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
	use Woodev\Framework\Shipping\Location\Location_Record;
	use Woodev\Framework\Shipping\Location\Location_Scope;
	use Woodev\Framework\Shipping\Location\Location_Service;
	use Woodev\Framework\Shipping\Map\Map_Provider;
	use Woodev\Framework\Shipping\Order\Shipping_Order_Handler;
	use Woodev\Framework\Shipping\Pickup\Pickup_Handler;
	use Woodev\Framework\Shipping\Pickup\Pickup_Map_Settings;
	use Woodev\Framework\Shipping\Pickup\Pickup_Point;
	use Woodev\Framework\Shipping\Pickup\Pickup_Selection;
	use Woodev\Framework\Shipping\Pickup\Point_Query;
	use Woodev\Framework\Shipping\Pickup\Point_Source;
	use Woodev\Framework\Shipping\Pickup\Selection_Scope;
	use Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab;
	use Woodev\Framework\Shipping\Shipping_Plugin;
	use Woodev\Tests\Unit\TestCase;

	require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-exception.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/exceptions/class-shipping-exception.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/map/interface-map-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-point.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-point-query.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-constraint-checker.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/interface-point-source.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/interface-selection-scope.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-selection.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-shipping-order-handler.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';

	// Task 15 (issue #159): Location Provider layer chain, same requires
	// LocationServiceTest.php needs to build a bare Shipping_Plugin fixture and inject a
	// fake-session-backed Location_Service.
	require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/class-woocommerce-plugin.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/class-shipping-plugin.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-control.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-setting.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/abstract-class-settings.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/settings-page/class-settings-section.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/settings-page/class-settings-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/settings-page/class-settings-page-registry.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-scope.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/abstract-location-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-settings.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-provider-registry.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-customer-location-store.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-adapter.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-resolution-cache.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-service.php';

	// «Доставка» tab (Task 8, issue #362, design S7): get_js_config() now reads
	// replaceAddress.enabled / selection.close through Pickup_Map_Settings::current(),
	// which reaches Shipping_Settings_Tab::instance()->get_map_settings() — the same
	// require chain ShippingSettingsTabTest/CheckoutHandlerEnqueueTest already load.
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-field-policy.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-map-settings.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/settings/class-shipping-settings-tab.php';

	if ( ! class_exists( '\\WP_REST_Controller' ) ) {
		require_once dirname( __DIR__, 4 ) . '/tests/unit/Shipping/Rest_Api/wp-rest-controller-stub.php';
	}

	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/rest-api/trait-rest-rate-limit.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/rest-api/class-pickup-controller.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-handler.php';

	/**
	 * Minimal `\WC_Session` stand-in — same shape as every other Location Provider layer
	 * test's own fake session (e.g. `Customer_Location_Store_Fake_Session`).
	 */
	final class Pickup_Handler_Location_Fake_Session {

		/** @var array<string, mixed> */
		private array $store = [];

		/**
		 * @param string $key     Session key.
		 * @param mixed  $default Fallback when the key is absent.
		 *
		 * @return mixed
		 */
		public function get( $key, $default = null ) {
			return $this->store[ $key ] ?? $default;
		}

		/**
		 * @param string $key   Session key.
		 * @param mixed  $value Value to store.
		 *
		 * @return void
		 */
		public function set( $key, $value ): void {
			$this->store[ $key ] = $value;
		}
	}

	/**
	 * Probe substituting a {@see Pickup_Handler_Location_Fake_Session} (or `null`) for the
	 * real `WC()->session` global — mirrors `Customer_Location_Store_Probe` in
	 * `CustomerLocationStoreTest.php`.
	 */
	final class Pickup_Handler_Customer_Location_Store_Probe extends Customer_Location_Store {

		private ?Pickup_Handler_Location_Fake_Session $fake_session;

		public function __construct( ?Pickup_Handler_Location_Fake_Session $fake_session ) {
			$this->fake_session = $fake_session;
		}

		protected function session() {
			return $this->fake_session;
		}
	}

	/**
	 * A store whose session only becomes readable once something RAISES it — exactly what a
	 * guest's `WC()->session` does on a REST request, where WooCommerce starts no session of
	 * its own (`class-woocommerce.php:891` gates session+cart on `is_request( 'frontend' )`,
	 * which excludes every REST request).
	 *
	 * The record is already in the store; the request simply has not attached to it yet. That
	 * is the state issue #324's rig pass exposed on the points route.
	 */
	final class Pickup_Handler_Deferred_Session_Store extends Customer_Location_Store {

		private Pickup_Handler_Location_Fake_Session $pending;

		/** @var Pickup_Handler_Location_Fake_Session|null */
		private ?Pickup_Handler_Location_Fake_Session $raised = null;

		public function __construct( Pickup_Handler_Location_Fake_Session $pending ) {
			$this->pending = $pending;
		}

		/** Makes the session readable, as `wc_load_cart()` does. */
		public function raise(): void {
			$this->raised = $this->pending;
		}

		/** Puts the store back to "this request has no session", the REST starting state. */
		public function hide(): void {
			$this->raised = null;
		}

		protected function session() {
			return $this->raised;
		}
	}

	/**
	 * A minimal `dadata`-id fixture provider claiming EVERY level in `RU` — the owning
	 * provider {@see Pickup_Handler_Location_Service_Active_Probe::provider_for_level()}
	 * always hands back, so #346/#333's staleness gate
	 * ({@see Location_Service::is_customer_record_stale()}) never drops this file's own
	 * `dadata:...` fixture records (see {@see PickupHandlerTest::location_record()} /
	 * {@see PickupHandlerTest::address_record()}) — this file is about `Pickup_Handler`
	 * downstream of a VALID record, not about the gate itself (that is
	 * `LocationServiceTest`'s job).
	 */
	final class Pickup_Handler_Test_Owning_Provider extends Abstract_Location_Provider {

		public function get_id(): string {
			return 'dadata';
		}

		public function get_name(): string {
			return 'dadata';
		}

		public function get_countries(): array {
			return [ 'RU' ];
		}

		public function is_configured(): bool {
			return true;
		}

		protected function declare_suggest_levels(): array {
			return Location_Record::LEVELS;
		}

		public function suggest( string $query, Location_Scope $scope ): array {
			return [];
		}
	}

	/**
	 * A {@see Location_Service} whose {@see Location_Service::is_active()} answers a FIXED
	 * value the test controls directly (review finding F1, rig-verified) — bypassing the
	 * registry/active-provider/`is_configured()` machinery `LocationServiceTest.php`
	 * exercises for real. This file only needs `is_active()`'s two observable OUTCOMES (the
	 * `location` block present vs omitted), never how the layer arrives at either one; a
	 * PLUGIN WIRED BUT NEVER CONFIGURED (`is_active() === false`) is exactly the
	 * review-finding scenario — {@see Pickup_Handler::location_config_block()} used to gate
	 * on `$plugin` alone and leaked the block through anyway.
	 *
	 * Also overrides {@see Location_Service::provider_for_level()} (#346/#333) — same
	 * "bypass the registry machinery, this file needs only the outcome" reasoning as
	 * `is_active()` above: every stored record this file builds
	 * ({@see PickupHandlerTest::location_record()}, {@see PickupHandlerTest::address_record()})
	 * is `dadata`-owned, so this always hands back {@see Pickup_Handler_Test_Owning_Provider}
	 * rather than requiring every one of this file's dozens of fixtures to also open the
	 * real provider-registry gate.
	 */
	final class Pickup_Handler_Location_Service_Active_Probe extends Location_Service {

		private bool $active;

		public function __construct( Location_Provider_Registry $registry, Customer_Location_Store $store, bool $active ) {
			parent::__construct( $registry, $store );
			$this->active = $active;
		}

		public function is_active(): bool {
			return $this->active;
		}

		public function provider_for_level( string $level, ?string $country = null ): ?Location_Provider {
			return new Pickup_Handler_Test_Owning_Provider();
		}

		/**
		 * #346/#333, rule (b) — the real implementation now falls through to
		 * {@see Location_Service::resolve_default_country()}, which calls
		 * `wc_get_base_location()`, unstubbed anywhere in this file (this file
		 * is about `Pickup_Handler` downstream of a valid record, not the
		 * gate's own country chain — that is `LocationServiceTest`'s job).
		 * Fixed to `'RU'`, matching every `dadata:...` fixture this file
		 * builds ({@see PickupHandlerTest::location_record()},
		 * {@see PickupHandlerTest::address_record()}).
		 */
		protected function customer_shipping_country(): string {
			return 'RU';
		}
	}

	/**
	 * Bare fixture Shipping_Plugin (Task 15; issue #159) — built via
	 * `newInstanceWithoutConstructor()`, same discipline as `LocationServiceTest`'s own
	 * `Location_Service_Fixture_Plugin`. Overrides `get_location_service()` to return an
	 * INJECTED instance rather than the base class' lazily-built `new Location_Service()`
	 * — the base's default reads the real `WC()->session` global (absent in this unit
	 * process), so every test needing a controllable customer record must substitute its
	 * own, exactly like `Provider_Selection_Scope_Test_Scope` does one directory over.
	 */
	class Pickup_Handler_Location_Fixture_Plugin extends Shipping_Plugin {

		public string $fake_id = 'test_plugin';
		public ?Location_Adapter $fake_adapter = null;
		public ?Location_Service $fake_location_service = null;

		protected function get_shipping_method_classes(): array {
			return [];
		}

		public function get_api(): ?\Woodev\Framework\Shipping\Shipping_API {
			return null;
		}

		protected function get_file() {
			return __FILE__;
		}

		public function get_plugin_name() {
			return 'Stub';
		}

		public function get_download_id() {
			return 0;
		}

		public function get_id() {
			return $this->fake_id;
		}

		public function needs_location_provider(): bool {
			return true;
		}

		public function get_location_adapter(): ?Location_Adapter {
			return $this->fake_adapter;
		}

		public function get_location_service(): Location_Service {
			return $this->fake_location_service ?? parent::get_location_service();
		}
	}

	/**
	 * Configurable {@see Point_Source} test double: fixed strategy, plus an injectable
	 * closure for fetch_details() so tests can return a point, null, or throw ANY
	 * `\Throwable` (a carrier `\Woodev_API_Exception`, or a plain `\RuntimeException`
	 * proving the catch is not narrowed to one exception class).
	 * fetch_points() is unused by Pickup_Handler and always returns an empty list.
	 */
	final class Pickup_Handler_Test_Source implements Point_Source {

		/** @var string */
		private string $strategy;

		/** @var callable */
		private $details_provider;

		/** @var int number of times fetch_details() was called. */
		public int $fetch_details_calls = 0;

		/**
		 * @param string   $strategy         one of Point_Source's STRATEGY_* constants.
		 * @param callable $details_provider `fn( string $id ): ?Pickup_Point`.
		 */
		public function __construct( string $strategy, callable $details_provider ) {
			$this->strategy         = $strategy;
			$this->details_provider = $details_provider;
		}

		public function get_strategy(): string {
			return $this->strategy;
		}

		public function fetch_points( Point_Query $query ): array {
			return [];
		}

		public function fetch_details( string $point_id ): ?Pickup_Point {
			++$this->fetch_details_calls;

			return ( $this->details_provider )( $point_id );
		}
	}

	/**
	 * Configurable {@see Map_Provider} test double, standing in for the real
	 * {@see \Woodev\Framework\Shipping\Map\Yandex_Map_Provider} /
	 * {@see \Woodev\Framework\Shipping\Map\Embedded_Map_Provider} — Pickup_Handler only
	 * ever calls the {@see Map_Provider} interface, never a concrete class, so a fake id +
	 * an injectable config is all a Pickup_Handler test needs.
	 */
	final class Pickup_Handler_Test_Map_Provider implements Map_Provider {

		/** @var string */
		private string $id;

		/** @var array<string, mixed> */
		private array $js_config;

		/**
		 * Recording spy: the LAST `$context` this provider's get_js_config() was called
		 * with, so a test can assert what {@see Pickup_Handler} actually passes through —
		 * a mutant emptying the real call site to `get_js_config( [] )` changes nothing
		 * observable in `mapConfig` itself (an ignored parameter), so only recording what
		 * was RECEIVED catches it.
		 *
		 * @var array<string, mixed>|null
		 */
		public ?array $received_context = null;

		/**
		 * What {@see self::get_settings_fields()} returns — public so a collision test
		 * (a provider naming its own field the same as the framework's `pickup_accent_color`)
		 * can set it directly without a constructor argument every other test would have
		 * to pass a default for.
		 *
		 * @var array<string, array<string, mixed>>
		 */
		public array $settings_fields_to_return = [];

		/**
		 * @param string               $id        provider id {@see Pickup_Handler} reads via
		 *                                         {@see self::get_id()}.
		 * @param array<string, mixed> $js_config what {@see self::get_js_config()} returns.
		 */
		public function __construct( string $id, array $js_config = [] ) {
			$this->id        = $id;
			$this->js_config = $js_config;
		}

		public function get_id(): string {
			return $this->id;
		}

		public function get_label(): string {
			return $this->id;
		}

		public function get_script_handle(): string {
			return 'woodev-pickup-map-provider-' . $this->id;
		}

		public function get_settings_fields(): array {
			return $this->settings_fields_to_return;
		}

		/**
		 * Not exercised by {@see Pickup_Handler} yet — a fixed `false` (the "Yandex"
		 * shape) is enough for this double to satisfy the interface.
		 */
		public function owns_chrome(): bool {
			return false;
		}

		public function get_js_config( array $context ): array {
			$this->received_context = $context;

			return $this->js_config;
		}
	}

	/**
	 * Probe exposing Pickup_Handler's protected logging seam as a public spy, plus the
	 * default cart-weight accessor — mirrors the SpyCheckoutHandler pattern used by
	 * CheckoutHandlerValidateTest. Persistence itself is NOT spied here any more: it goes
	 * through the real {@see Shipping_Order_Handler}, which is itself exercised directly.
	 */
	class Pickup_Handler_Probe extends Pickup_Handler {

		/** @var array<int, array{context: string, message: string}> */
		public array $logged = [];

		protected function log_carrier_failure( \Throwable $e, string $context ): void {
			$this->logged[] = [
				'context' => $context,
				'message' => $e->getMessage(),
			];
		}

		/**
		 * Exposes the protected default-weight accessor for direct assertions.
		 */
		public function current_cart_weight_grams_public(): int {
			return $this->current_cart_weight_grams();
		}

		/**
		 * Exposes the protected wc_cart() seam's OWN default body for direct
		 * assertions — proves the seam itself (not a probe override) degrades
		 * safely when WC() is unavailable.
		 *
		 * @return object|null
		 */
		public function wc_cart_public() {
			return $this->wc_cart();
		}

		/**
		 * Exposes the protected wc_session_chosen_payment_method() seam's OWN
		 * default body for direct assertions — same reasoning as
		 * {@see self::wc_cart_public()}.
		 *
		 * @return mixed
		 */
		public function wc_session_chosen_payment_method_public() {
			return $this->wc_session_chosen_payment_method();
		}

		/**
		 * Exposes the protected wc_session_chosen_shipping_methods() seam's OWN
		 * default body for direct assertions — same reasoning as
		 * {@see self::wc_cart_public()}.
		 *
		 * @return mixed
		 */
		public function wc_session_chosen_shipping_methods_public() {
			return $this->wc_session_chosen_shipping_methods();
		}
	}

	/**
	 * Probe forcing every asset to report as already built, so enqueue_assets()'s
	 * "built" branch can be exercised without writing a real file into the assets
	 * directory. The base {@see Pickup_Handler} (no override) is used for the opposite
	 * case, since the real assets genuinely do not exist on disk yet.
	 */
	/**
	 * Issue #324's rig fallout: a handler whose WooCommerce context is absent until
	 * `load_wc_cart()` runs, wired to a {@see Pickup_Handler_Deferred_Session_Store} that
	 * only answers once the same call has happened. Reproduces the points route's own
	 * starting state — a guest REST request with no session — so a test can observe whether
	 * the handler raises it BEFORE reading the customer's location record.
	 */
	final class Pickup_Handler_Location_Bridge_Probe extends Pickup_Handler {

		/** @var int */
		public int $load_wc_cart_calls = 0;

		/** @var object|null */
		private $cart = null;

		/** @var Pickup_Handler_Deferred_Session_Store */
		private Pickup_Handler_Deferred_Session_Store $store;

		public function bind_store( Pickup_Handler_Deferred_Session_Store $store ): void {
			$this->store = $store;
		}

		protected function wc_cart() {
			return $this->cart;
		}

		protected function wc_load_cart_available(): bool {
			return true;
		}

		protected function load_wc_cart(): void {
			++$this->load_wc_cart_calls;

			// wc_load_cart() raises BOTH, and that is the point: the session is what the
			// location record needs, the cart is merely what this class already asked for.
			$this->cart = new \stdClass();
			$this->store->raise();
		}
	}

	final class Pickup_Handler_Assets_Built_Probe extends Pickup_Handler {

		protected static function asset_exists( string $path ): bool {
			return true;
		}
	}

	/**
	 * Probe forcing a fixed cart weight, so handle_checkout_process()'s delegation can be
	 * exercised end-to-end without bootstrapping WC()->cart.
	 */
	final class Pickup_Handler_Weight_Probe extends Pickup_Handler {

		/** @var int */
		private int $forced_weight;

		public function __construct(
			string $plugin_id,
			string $field_id,
			Point_Source $source,
			Map_Provider $map_provider,
			array $default_location,
			int $forced_weight
		) {
			parent::__construct( $plugin_id, $field_id, $source, $map_provider, $default_location );
			$this->forced_weight = $forced_weight;
		}

		public function current_cart_weight_grams(): int {
			return $this->forced_weight;
		}
	}

	/**
	 * Minimal cart double exposing only get_cart_contents_weight() — the single
	 * method {@see Pickup_Handler::current_cart_weight_grams()} calls on whatever
	 * {@see Pickup_Handler::wc_cart()} returns.
	 */
	final class Pickup_Handler_Test_Cart {

		/** @var float */
		private float $weight;

		public function __construct( float $weight ) {
			$this->weight = $weight;
		}

		/** @return float */
		public function get_cart_contents_weight() {
			return $this->weight;
		}
	}

	/**
	 * Probe exercising the REAL current_cart_weight_grams() orchestration —
	 * including the "load only when absent, then re-read" branch — while
	 * overriding only the three WC()-touching seams
	 * ({@see Pickup_Handler::wc_cart()}, {@see Pickup_Handler::wc_load_cart_available()},
	 * {@see Pickup_Handler::load_wc_cart()}). This is deliberately NOT done via
	 * Brain Monkey's `Functions\when( 'WC' )`: WC() is not a real WordPress
	 * function in this unit-test process, and once ANY test in this suite mocks
	 * it, Brain Monkey's underlying Patchwork redefinition makes `function_exists(
	 * 'WC' )` report `true` for the REST OF THE PROCESS — breaking every other
	 * test (in this file and others, e.g. CheckoutHandlerInjectTest) that relies
	 * on WC() genuinely not existing. Confirmed empirically while building this
	 * fix. Overriding the small protected seams instead keeps `WC()` untouched.
	 *
	 * `load_wc_cart()` sets `$cart` to `$cart_after_load` and counts its own
	 * calls, so a test can assert BOTH the returned weight AND that loading was
	 * (or was not) actually attempted — catching a mutant that drops the
	 * `load_wc_cart()` call entirely (the cart would then never become available).
	 */
	final class Pickup_Handler_Cart_Probe extends Pickup_Handler {

		/** @var object|null */
		private $cart;

		/** @var bool */
		private bool $load_cart_available;

		/** @var object|null */
		private $cart_after_load;

		/** @var int number of times load_wc_cart() was called. */
		public int $load_wc_cart_calls = 0;

		/**
		 * @param object|null $cart                 what wc_cart() returns before any load.
		 * @param bool        $load_cart_available   what wc_load_cart_available() returns.
		 * @param object|null $cart_after_load       what wc_cart() returns AFTER load_wc_cart() runs.
		 */
		public function __construct(
			string $plugin_id,
			string $field_id,
			Point_Source $source,
			Map_Provider $map_provider,
			array $default_location,
			$cart,
			bool $load_cart_available = false,
			$cart_after_load = null
		) {
			parent::__construct( $plugin_id, $field_id, $source, $map_provider, $default_location );
			$this->cart                = $cart;
			$this->load_cart_available = $load_cart_available;
			$this->cart_after_load     = $cart_after_load;
		}

		protected function wc_cart() {
			return $this->cart;
		}

		protected function wc_load_cart_available(): bool {
			return $this->load_cart_available;
		}

		protected function load_wc_cart(): void {
			++$this->load_wc_cart_calls;
			$this->cart = $this->cart_after_load;
		}
	}

	/**
	 * Probe exercising the REAL rest_payment_method() precedence logic
	 * ($_POST first, session fallback second) while overriding only
	 * {@see Pickup_Handler::wc_session_chosen_payment_method()} — for the same
	 * "never mock WC() itself" reason {@see Pickup_Handler_Cart_Probe} documents.
	 */
	final class Pickup_Handler_Session_Probe extends Pickup_Handler {

		/** @var mixed */
		private $session_value;

		/**
		 * @param mixed $session_value what wc_session_chosen_payment_method() returns.
		 */
		public function __construct(
			string $plugin_id,
			string $field_id,
			Point_Source $source,
			Map_Provider $map_provider,
			array $default_location,
			$session_value
		) {
			parent::__construct( $plugin_id, $field_id, $source, $map_provider, $default_location );
			$this->session_value = $session_value;
		}

		protected function wc_session_chosen_payment_method() {
			return $this->session_value;
		}
	}

	/**
	 * Probe exercising the REAL rest_shipping_method() reading of
	 * `WC()->session->get( 'chosen_shipping_methods' )` while overriding only
	 * {@see Pickup_Handler::wc_session_chosen_shipping_methods()} — for the same
	 * "never mock WC() itself" reason {@see Pickup_Handler_Session_Probe} documents.
	 */
	final class Pickup_Handler_Shipping_Session_Probe extends Pickup_Handler {

		/** @var mixed */
		private $session_value;

		/**
		 * @param mixed $session_value what wc_session_chosen_shipping_methods() returns.
		 */
		public function __construct(
			string $plugin_id,
			string $field_id,
			Point_Source $source,
			Map_Provider $map_provider,
			array $default_location,
			$session_value
		) {
			parent::__construct( $plugin_id, $field_id, $source, $map_provider, $default_location );
			$this->session_value = $session_value;
		}

		protected function wc_session_chosen_shipping_methods() {
			return $this->session_value;
		}
	}

	/**
	 * Probe combining a forced cart weight ({@see Pickup_Handler_Weight_Probe}'s own
	 * reason for existing) with a forced WC-session payment-method value
	 * ({@see Pickup_Handler_Session_Probe}'s), so `handle_checkout_process()` can be
	 * exercised end-to-end while a session value is present — the only way to prove
	 * {@see Pickup_Handler::checkout_payment_method()} never reads it, unlike
	 * {@see Pickup_Handler::rest_payment_method()}, which {@see Pickup_Handler_Session_Probe}
	 * alone already exercises. A mutant that routes `handle_checkout_process()` back to
	 * `rest_payment_method()`, or that swaps the two readers' bodies, makes the forced
	 * session value leak into the checkout re-check — see the tests using this probe for
	 * the assertions that catch it.
	 */
	final class Pickup_Handler_Checkout_Session_Probe extends Pickup_Handler {

		/** @var int */
		private int $forced_weight;

		/** @var mixed */
		private $session_value;

		/**
		 * @param int   $forced_weight what current_cart_weight_grams() returns.
		 * @param mixed $session_value what wc_session_chosen_payment_method() returns.
		 */
		public function __construct(
			string $plugin_id,
			string $field_id,
			Point_Source $source,
			Map_Provider $map_provider,
			array $default_location,
			int $forced_weight,
			$session_value
		) {
			parent::__construct( $plugin_id, $field_id, $source, $map_provider, $default_location );
			$this->forced_weight = $forced_weight;
			$this->session_value = $session_value;
		}

		public function current_cart_weight_grams(): int {
			return $this->forced_weight;
		}

		protected function wc_session_chosen_payment_method() {
			return $this->session_value;
		}
	}

	// -------------------------------------------------------------------------
	// Issue #176 — pickup-selection persistence test doubles
	// -------------------------------------------------------------------------

	/**
	 * Configurable {@see Selection_Scope} test double: every method delegates to an
	 * injected closure, mirroring {@see Pickup_Handler_Test_Source}'s own pattern —
	 * each test wires only the closures it actually cares about.
	 */
	final class Pickup_Handler_Selection_Test_Scope implements Selection_Scope {

		/** @var string */
		private string $key;

		/** @var callable */
		private $locality_for_point;

		/** @var callable */
		private $current_locality;

		/** @var callable */
		private $type_for_method;

		/**
		 * @param string   $key                session key.
		 * @param callable $locality_for_point `fn( Pickup_Point $point ): string`.
		 * @param callable $current_locality   `fn(): string`.
		 * @param callable $type_for_method    `fn( string $method_id ): ?string`.
		 */
		public function __construct(
			string $key,
			callable $locality_for_point,
			callable $current_locality,
			callable $type_for_method
		) {
			$this->key                = $key;
			$this->locality_for_point = $locality_for_point;
			$this->current_locality   = $current_locality;
			$this->type_for_method    = $type_for_method;
		}

		public function session_key(): string {
			return $this->key;
		}

		public function locality_for_point( Pickup_Point $point ): string {
			return ( $this->locality_for_point )( $point );
		}

		public function current_locality(): string {
			return ( $this->current_locality )();
		}

		public function type_for_method( string $method_id ): ?string {
			return ( $this->type_for_method )( $method_id );
		}
	}

	/**
	 * Minimal `\WC_Session` stand-in for {@see Pickup_Selection}'s own protected
	 * `session()` seam — an array-backed get()/set() pair, nothing else.
	 */
	final class Pickup_Handler_Fake_Session {

		/** @var array<string, mixed> */
		private array $store = [];

		/**
		 * @param string $key     session key.
		 * @param mixed  $default fallback when the key is absent.
		 *
		 * @return mixed
		 */
		public function get( $key, $default = null ) {
			return $this->store[ $key ] ?? $default;
		}

		/**
		 * @param string $key   session key.
		 * @param mixed  $value value to store.
		 *
		 * @return void
		 */
		public function set( $key, $value ): void {
			$this->store[ $key ] = $value;
		}
	}

	/**
	 * {@see Pickup_Selection} wired to a {@see Pickup_Handler_Fake_Session} instead of
	 * the real `WC()->session` — the same "override the protected seam, never mock
	 * WC() itself" discipline every other probe in this file already follows.
	 */
	final class Pickup_Handler_Selection_Probe extends Pickup_Selection {

		/** @var Pickup_Handler_Fake_Session|null */
		private ?Pickup_Handler_Fake_Session $fake_session;

		public function __construct( Selection_Scope $scope, ?Pickup_Handler_Fake_Session $fake_session ) {
			parent::__construct( $scope );
			$this->fake_session = $fake_session;
		}

		protected function session() {
			return $this->fake_session;
		}
	}

	/**
	 * Probe exercising the REAL {@see Pickup_Handler::remember_selection()} /
	 * {@see Pickup_Handler::restore_selection()} / the `forget_all()` call inside
	 * {@see Pickup_Handler::handle_checkout_order_processed()}, while overriding only
	 * two seams: {@see Pickup_Handler::selection()} (substitutes a
	 * {@see Pickup_Handler_Selection_Probe}, so no real `WC()->session` is ever
	 * touched) and {@see Pickup_Handler::wc_session_chosen_shipping_methods()}
	 * (supplies a forced session value for `restore_selection()`'s own method read,
	 * the same forced-session pattern {@see Pickup_Handler_Shipping_Session_Probe}
	 * already uses).
	 */
	final class Pickup_Handler_With_Selection_Probe extends Pickup_Handler {

		/** @var Pickup_Selection|null */
		private ?Pickup_Selection $forced_selection;

		/** @var mixed */
		private $chosen_shipping_methods_value;

		/**
		 * @param Selection_Scope|null $selection_scope                the scope to construct
		 *                                                              {@see Pickup_Handler}
		 *                                                              with.
		 * @param Pickup_Selection|null $forced_selection               what
		 *                                                              {@see self::selection()}
		 *                                                              returns.
		 * @param mixed                 $chosen_shipping_methods_value what
		 *                                                              {@see self::wc_session_chosen_shipping_methods()}
		 *                                                              returns.
		 */
		public function __construct(
			string $plugin_id,
			string $field_id,
			Point_Source $source,
			Map_Provider $map_provider,
			array $default_location,
			?Selection_Scope $selection_scope,
			?Pickup_Selection $forced_selection,
			$chosen_shipping_methods_value = null
		) {
			parent::__construct(
				$plugin_id,
				$field_id,
				$source,
				$map_provider,
				$default_location,
				null,
				null,
				[],
				'#000000',
				'',
				true,
				false,
				$selection_scope
			);
			$this->forced_selection             = $forced_selection;
			$this->chosen_shipping_methods_value = $chosen_shipping_methods_value;
		}

		protected function selection(): ?Pickup_Selection {
			return $this->forced_selection;
		}

		protected function wc_session_chosen_shipping_methods() {
			return $this->chosen_shipping_methods_value;
		}
	}

	/**
	 * {@see Pickup_Selection} whose underlying `session()` seam is ABSENT until
	 * {@see self::make_available()} is called — lets a test prove
	 * {@see Pickup_Handler::remember_selection()}'s `wc_load_cart()` bridge is the
	 * thing that makes the write possible, rather than the fake session simply being
	 * "live" from the start (which is what every OTHER selection probe in this file
	 * does, and which is exactly what leaves the bridge unpinned).
	 */
	final class Pickup_Handler_Bridging_Selection_Probe extends Pickup_Selection {

		/** @var Pickup_Handler_Fake_Session */
		private Pickup_Handler_Fake_Session $fake_session;

		/** @var bool */
		private bool $available = false;

		public function __construct( Selection_Scope $scope ) {
			parent::__construct( $scope );
			$this->fake_session = new Pickup_Handler_Fake_Session();
		}

		/**
		 * Simulates `wc_load_cart()` having run: the session becomes readable/writable
		 * from this point on, never before.
		 */
		public function make_available(): void {
			$this->available = true;
		}

		protected function session() {
			return $this->available ? $this->fake_session : null;
		}
	}

	/**
	 * Probe exercising the REAL {@see Pickup_Handler::remember_selection()} bridge
	 * logic (`if ( ! $this->wc_cart() && $this->wc_load_cart_available() ) { $this->load_wc_cart(); }`)
	 * end-to-end: `wc_cart()` starts absent, `wc_load_cart_available()` reports
	 * `true`, and `load_wc_cart()` — instead of merely being counted, like
	 * {@see Pickup_Handler_Cart_Probe} — actually flips the injected
	 * {@see Pickup_Handler_Bridging_Selection_Probe}'s session from absent to
	 * available. A mutant deleting the bridge call entirely leaves the session
	 * permanently absent, so `remember()` becomes a silent no-op — the same failure
	 * mode a real REST request would hit in production.
	 */
	final class Pickup_Handler_Bridge_Probe extends Pickup_Handler {

		/** @var Pickup_Handler_Bridging_Selection_Probe */
		private Pickup_Handler_Bridging_Selection_Probe $bridging_selection;

		/** @var int number of times load_wc_cart() was called. */
		public int $load_wc_cart_calls = 0;

		public function __construct(
			string $plugin_id,
			string $field_id,
			Point_Source $source,
			Map_Provider $map_provider,
			array $default_location,
			?Selection_Scope $selection_scope,
			Pickup_Handler_Bridging_Selection_Probe $bridging_selection
		) {
			parent::__construct(
				$plugin_id,
				$field_id,
				$source,
				$map_provider,
				$default_location,
				null,
				null,
				[],
				'#000000',
				'',
				true,
				false,
				$selection_scope
			);
			$this->bridging_selection = $bridging_selection;
		}

		protected function selection(): ?Pickup_Selection {
			return $this->bridging_selection;
		}

		protected function wc_cart() {
			// Always absent — forces the bridge branch to be the only path to a
			// working session.
			return null;
		}

		protected function wc_load_cart_available(): bool {
			return true;
		}

		protected function load_wc_cart(): void {
			++$this->load_wc_cart_calls;
			$this->bridging_selection->make_available();
		}
	}

	/**
	 * @covers \Woodev\Framework\Shipping\Pickup\Pickup_Handler
	 */
	final class PickupHandlerTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();
			$_POST = [];

			Functions\when( 'wp_unslash' )->returnArg();
			Functions\when( 'wc_clean' )->alias(
				static fn( $value ) => is_string( $value ) ? trim( $value ) : $value
			);

			// get_js_config() now calls resolve_accent_color() -> sanitize_hex_color()
			// UNCONDITIONALLY (Task 8B), so every single test that builds a config needs
			// this stubbed, not just the accent-colour-focused ones — global, like the two
			// stubs above, rather than repeated in ~40 call sites.
			$this->stub_sanitize_hex_color();

			// Task 15 (issue #159): Customer_Location_Store::get()/set() (reached only by
			// the tests that actually wire a $plugin) call is_user_logged_in() — global,
			// same reasoning as the two stubs above, rather than repeated per test.
			Functions\when( 'is_user_logged_in' )->justReturn( false );

			// A fresh gate every test — Location_Provider_Registry::instance() is a
			// process-wide singleton (LocationServiceTest's own setUp documents the same
			// discipline).
			Location_Provider_Registry::instance()->reset_for_tests();

			// Task 8 (issue #362, design S7): get_js_config() now reaches
			// Pickup_Map_Settings::current(), which lazily constructs a real
			// Pickup_Map_Settings through Woodev_Abstract_Settings — stub the WP
			// primitives that path touches, same as CheckoutFieldSettingsTest /
			// CheckoutHandlerEnqueueTest / ShippingSettingsTabTest. No test in this file
			// stubbed `get_option`/`wp_parse_args` before this task, so a global default
			// here is safe.
			Functions\when( 'get_option' )->justReturn( null );
			Functions\when( 'wp_parse_args' )->alias(
				static function ( $args, $defaults = [] ) {
					return array_merge( (array) $defaults, (array) $args );
				}
			);

			// Shipping_Settings_Tab is a process-wide singleton (same discipline as
			// Location_Provider_Registry above) — reset it so a get_option alias a test
			// sets AFTER this point always builds a FRESH Pickup_Map_Settings, never one
			// cached from an earlier test's option values (gotcha
			// `woodev-setting-get-value-is-cached-not-a-live-option-read`).
			Shipping_Settings_Tab::reset_for_tests();
		}

		protected function tearDown(): void {
			$_POST = [];
			Shipping_Settings_Tab::reset_for_tests();
			parent::tearDown();
		}

		/**
		 * Builds a point with a valid base payload, overridden by $extra.
		 *
		 * @param array<string, mixed> $extra
		 */
		private function point( array $extra = [] ): Pickup_Point {
			return Pickup_Point::from_array(
				array_merge(
					[
						'id'      => 'P1',
						'name'    => 'Точка',
						'lat'     => 55.75,
						'lng'     => 37.61,
						'address' => 'Москва',
						'type'    => [ 'code' => 'PVZ', 'label' => 'ПВЗ' ],
					],
					$extra
				)
			);
		}

		/**
		 * @param ?Pickup_Point $point    what fetch_details() returns.
		 * @param string        $strategy one of Point_Source's STRATEGY_* constants.
		 */
		private function source_returning(
			?Pickup_Point $point,
			string $strategy = Point_Source::STRATEGY_BULK
		): Pickup_Handler_Test_Source {
			return new Pickup_Handler_Test_Source( $strategy, static fn( string $id ) => $point );
		}

		/**
		 * @param \Throwable $e        what fetch_details() throws — a carrier
		 *                             `\Woodev_API_Exception` or any other `\Throwable`.
		 * @param string     $strategy one of Point_Source's STRATEGY_* constants.
		 */
		private function source_throwing(
			\Throwable $e,
			string $strategy = Point_Source::STRATEGY_BULK
		): Pickup_Handler_Test_Source {
			return new Pickup_Handler_Test_Source(
				$strategy,
				static function ( string $id ) use ( $e ) {
					throw $e;
				}
			);
		}

		/**
		 * Builds a {@see Pickup_Handler_Test_Map_Provider} with id `yandex`.
		 *
		 * @param array<string, mixed> $js_config what get_js_config() returns.
		 */
		private function yandex_provider( array $js_config = [] ): Pickup_Handler_Test_Map_Provider {
			return new Pickup_Handler_Test_Map_Provider( 'yandex', $js_config );
		}

		/**
		 * Builds a {@see Pickup_Handler_Test_Map_Provider} with id `embedded`.
		 *
		 * @param array<string, mixed> $js_config what get_js_config() returns.
		 */
		private function embedded_provider( array $js_config = [] ): Pickup_Handler_Test_Map_Provider {
			return new Pickup_Handler_Test_Map_Provider( 'embedded', $js_config );
		}

		/**
		 * A representative, valid default viewport (Moscow) — Task 8 made this constructor
		 * argument mandatory, so every test that does not itself examine `defaultLocation`
		 * needs SOME valid value to pass; this is that value, kept in one place so a future
		 * change to what "valid" means only has to happen here.
		 *
		 * @return array{center: array{0: float, 1: float}, zoom: int}
		 */
		private function default_location(): array {
			return [ 'center' => [ 55.75, 37.61 ], 'zoom' => 10 ];
		}

		/**
		 * Builds a {@see Pickup_Handler} with sensible defaults for every constructor
		 * argument Task 8/8B added, overridable via `$overrides`. Introduced for the
		 * accent-colour and viewport/icon tests, which would otherwise each have to restate
		 * every unrelated constructor argument; pre-existing tests keep constructing
		 * {@see Pickup_Handler} directly.
		 *
		 * Deliberately does NOT stub any WordPress function — every test using this helper
		 * still stubs `apply_filters()`/`rest_url()`/`wp_create_nonce()`/
		 * `wc_ship_to_billing_address_only()` itself, exactly like every other test in this
		 * file, so a test that needs `Filters\expectApplied()` (a specific expectation) is
		 * never fighting a generic stub this helper set up behind its back.
		 *
		 * @param array<string, mixed> $overrides
		 */
		private function make_handler( array $overrides = [] ): Pickup_Handler {
			return new Pickup_Handler(
				$overrides['plugin_id'] ?? 'p',
				$overrides['field_id'] ?? 'carrier_pickup_point',
				$overrides['source'] ?? $this->source_returning( null ),
				$overrides['map_provider'] ?? $this->yandex_provider(),
				$overrides['default_location'] ?? $this->default_location(),
				$overrides['order_handler'] ?? null,
				$overrides['point_field_logical'] ?? null,
				$overrides['point_icons'] ?? [],
				$overrides['accent_color'] ?? '#06aedd',
				$overrides['setting_accent'] ?? '',
				$overrides['search_enabled'] ?? true,
				$overrides['refresh_checkout'] ?? false,
				$overrides['selection_scope'] ?? null,
				$overrides['plugin'] ?? null
			);
		}

		// -------------------------------------------------------------------
		// Task 15 (issue #159): Location Provider layer wiring.
		// -------------------------------------------------------------------

		/**
		 * Builds a {@see Pickup_Handler_Location_Fixture_Plugin} via
		 * `newInstanceWithoutConstructor()` (same discipline as
		 * `LocationServiceTest::plugin()`), with `get_location_service()` overridden to
		 * return a {@see Location_Service} backed by a session probe seeded with `$record`
		 * (or nothing, when `$record` is `null`).
		 *
		 * @param Location_Record|null  $record   The customer's current record, or `null`.
		 * @param Location_Adapter|null $adapter  The adapter `resolve_for()` calls; `null`
		 *                                        (the default) mirrors a plugin that has not
		 *                                        wired one.
		 * @param bool                  $active   What {@see Location_Service::is_active()}
		 *                                        answers (review finding F1) — defaults to
		 *                                        `true` (a configured, usable layer), the
		 *                                        assumption every EXISTING caller of this
		 *                                        helper already made implicitly before
		 *                                        `is_active()` gated anything here. Pass
		 *                                        `false` to build the "wired but never
		 *                                        configured" plugin the finding itself
		 *                                        describes.
		 * @param bool                  $implicit Whether `$record` was written as a default
		 *                                        guess rather than a real customer choice
		 *                                        (issue #309; spec D11/§4.6) — ignored when
		 *                                        `$record` is `null`. Defaults to `false`
		 *                                        (a real choice), the assumption every
		 *                                        EXISTING caller of this helper already made.
		 */
		private function location_plugin( ?Location_Record $record, ?Location_Adapter $adapter = null, bool $active = true, bool $implicit = false ): Pickup_Handler_Location_Fixture_Plugin {
			$instance = ( new \ReflectionClass( Pickup_Handler_Location_Fixture_Plugin::class ) )->newInstanceWithoutConstructor();

			$store = new Pickup_Handler_Customer_Location_Store_Probe( new Pickup_Handler_Location_Fake_Session() );

			if ( null !== $record ) {
				$store->set( $record, $implicit );
			}

			$instance->fake_adapter         = $adapter;
			$instance->fake_location_service = new Pickup_Handler_Location_Service_Active_Probe(
				Location_Provider_Registry::instance(),
				$store,
				$active
			);

			return $instance;
		}

		private function location_record( string $key = 'dadata:fias-1' ): Location_Record {
			return Location_Record::from_array(
				[
					'key'         => $key,
					'provider_id' => explode( ':', $key )[0],
					'level'       => Location_Record::LEVEL_SETTLEMENT,
					'country'     => 'RU',
					'settlement'  => [ 'name' => 'Москва', 'type' => 'г' ],
				]
			);
		}

		/**
		 * Builds an ADDRESS-level record with an explicit `ancestors` set — issue #336's
		 * settlement-preferred/current-fallback tests need to control whether the chain
		 * {@see Customer_Location_Store::set()} rebuilds keeps a shallower settlement record
		 * or drops it, and only an address record naming that settlement's key in its own
		 * `ancestors` proves ancestry (mirrors `CustomerLocationStoreTest::record_with_ancestors()`).
		 *
		 * @param string   $key       The record's own key.
		 * @param string[] $ancestors The `ancestors` set to publish.
		 */
		private function address_record( string $key, array $ancestors ): Location_Record {
			return Location_Record::from_array(
				[
					'key'         => $key,
					'provider_id' => explode( ':', $key )[0],
					'level'       => Location_Record::LEVEL_ADDRESS,
					'country'     => 'RU',
					'ancestors'   => $ancestors,
				]
			);
		}

		/**
		 * Builds a {@see Pickup_Handler_Location_Fixture_Plugin} whose customer store holds a
		 * TWO-LEVEL chain (issue #336) — `$settlement` written first, then `$address` (which
		 * must name `$settlement`'s key in its own `ancestors` for {@see Customer_Location_Store::set()}
		 * to keep the settlement level rather than drop it — see {@see self::address_record()}).
		 * `current` ends up at the address level, exactly what an address pick inside an
		 * already-chosen settlement looks like on the rig (issue #336's own measurement).
		 *
		 * @param Location_Record      $settlement The settlement-level record picked first.
		 * @param Location_Record      $address    The address-level record picked second.
		 * @param Location_Adapter|null $adapter   Optional adapter for {@see Pickup_Handler::location_context()}.
		 */
		private function location_plugin_with_chain( Location_Record $settlement, Location_Record $address, ?Location_Adapter $adapter = null ): Pickup_Handler_Location_Fixture_Plugin {
			$instance = ( new \ReflectionClass( Pickup_Handler_Location_Fixture_Plugin::class ) )->newInstanceWithoutConstructor();

			$store = new Pickup_Handler_Customer_Location_Store_Probe( new Pickup_Handler_Location_Fake_Session() );
			$store->set( $settlement );
			$store->set( $address );

			$instance->fake_adapter          = $adapter;
			$instance->fake_location_service = new Pickup_Handler_Location_Service_Active_Probe(
				Location_Provider_Registry::instance(),
				$store,
				true
			);

			return $instance;
		}

		/**
		 * Installs a faithful-enough Brain Monkey stand-in for `sanitize_hex_color()` —
		 * NOT currently stubbed anywhere else in this codebase. Verified against real
		 * WordPress core (`wp-includes/formatting.php`): returns the input UNCHANGED (no
		 * lower-casing) when it is `#` followed by exactly 3 or 6 hex digits, `''` for an
		 * empty-string input, `null` for anything else (including an 8-digit/alpha hex —
		 * real `sanitize_hex_color()` does not support those). Lower-casing is
		 * {@see Pickup_Handler::resolve_accent_color()}'s own job, done exactly once, AFTER
		 * this function runs — this stub must not pre-empt that or the two responsibilities
		 * would be impossible to tell apart in a test.
		 */
		private function stub_sanitize_hex_color(): void {
			Functions\when( 'sanitize_hex_color' )->alias(
				static function ( $color ) {
					if ( '' === $color ) {
						return '';
					}

					return 1 === preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', (string) $color ) ? $color : null;
				}
			);
		}

		/**
		 * Recursively asserts an array contains no object/closure value, and no key shaped
		 * like a carrier/provider credential (e.g. `apiKey`, `api_key`, `secret`, `token`)
		 * anywhere in its tree — the guard SP-5 Task 9 strengthens once `mapConfig` stops
		 * being an empty array: a provider's `get_js_config()` may legitimately embed a key
		 * INSIDE a URL value (see {@see \Woodev\Framework\Shipping\Map\Yandex_Map_Provider}),
		 * but must never expose one under a bare credential-shaped key name. The pattern is
		 * anchored at the START of the key so a legitimate boolean flag like `hasApiKey`
		 * (see {@see \Woodev\Framework\Shipping\Map\Yandex_Map_Provider::get_js_config()})
		 * does not false-positive — it is not itself credential-shaped, it reports whether
		 * one is configured.
		 *
		 * @param array<string, mixed> $data
		 */
		private function assertConfigHasNoObjectsOrClosures( array $data ): void {
			foreach ( $data as $key => $value ) {
				$this->assertFalse( is_object( $value ), "config value for \"{$key}\" must not be an object" );

				if ( is_string( $key ) ) {
					$this->assertDoesNotMatchRegularExpression(
						'/^(api[_-]?key|secret|token|password)/i',
						$key,
						"config key \"{$key}\" looks credential-shaped and must not be emitted as a bare key"
					);
				}

				if ( is_array( $value ) ) {
					$this->assertConfigHasNoObjectsOrClosures( $value );
				}
			}
		}

		// -------------------------------------------------------------------------
		// get_js_config() — baseline + strategy/secrets/replaceAddress
		// -------------------------------------------------------------------------

		public function test_config_exposes_the_strategy_and_route_but_no_secrets(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$source = $this->source_returning( null, Point_Source::STRATEGY_VIEWPORT );
			$config = ( new Pickup_Handler(
				'p',
				'carrier_pickup_point',
				$source,
				$this->yandex_provider(),
				$this->default_location()
			) )->get_js_config();

			$this->assertSame( 'viewport', $config['strategy'] );
			$this->assertSame( 'carrier_pickup_point', $config['fieldId'] );
			$this->assertSame( 'yandex', $config['provider'] );
			$this->assertArrayNotHasKey( 'api_secret', $config );
		}

		/**
		 * The strategy must come from the SOURCE, not any constructor argument — there is
		 * no separate "strategy" parameter any more. Proven by varying the source alone.
		 */
		public function test_config_strategy_comes_from_the_source_for_bulk_too(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$source = $this->source_returning( null, Point_Source::STRATEGY_BULK );
			$config = ( new Pickup_Handler(
				'p',
				'carrier_pickup_point',
				$source,
				$this->yandex_provider(),
				$this->default_location()
			) )->get_js_config();

			$this->assertSame( 'bulk', $config['strategy'] );
		}

		// -------------------------------------------------------------------------
		// get_js_config() — the `location` block (Task 15; issue #159)
		// -------------------------------------------------------------------------

		public function test_config_carries_no_location_block_when_no_plugin_was_wired(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			// Every pre-existing constructor call in this file (no $plugin argument) must
			// keep behaving exactly as before this task — the browser then falls back to
			// its own pre-existing DOM read, never a bare `''` masquerading as "resolved".
			$config = $this->make_handler()->get_js_config();

			$this->assertArrayNotHasKey( 'location', $config );
		}

		public function test_config_carries_an_empty_key_when_a_plugin_is_wired_active_but_has_no_record_yet(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$config = $this->make_handler( [ 'plugin' => $this->location_plugin( null, null, true ) ] )->get_js_config();

			// PRESENT with an empty key, never OMITTED — an empty answer is the layer
			// genuinely refusing to name a locality yet (gotcha
			// `an-empty-domain-key-is-not-a-key`), which the browser must not paper over
			// with a DOM guess once it knows to look here at all. This is the ACTIVE-layer
			// case — see the two `..._is_not_active` tests just below for the DIFFERENT
			// (review finding F1) case where the block must be OMITTED instead.
			$this->assertArrayHasKey( 'location', $config );
			$this->assertSame( '', $config['location']['current']['key'] );
			// No record at all — an implicit flag is only meaningful attached to an actual
			// record (issue #309; same convention Checkout_Config::build_location_block()
			// already uses for its own sibling `implicit` key).
			$this->assertFalse( $config['location']['implicit'] );
		}

		public function test_config_carries_the_customer_current_record_key(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$plugin = $this->location_plugin( $this->location_record( 'dadata:fias-1' ), null, true );
			$config = $this->make_handler( [ 'plugin' => $plugin ] )->get_js_config();

			$this->assertSame( 'dadata:fias-1', $config['location']['current']['key'] );
		}

		/**
		 * `location_config_block()`'s `current.key` follows the SAME settlement-preferred
		 * rule as `current_location_record()` (issue #336) — see that method's own tests
		 * under "current_location_record() settlement-preferred / current-fallback rule"
		 * for the full reasoning; this proves the SAME preference reaches the browser
		 * config, not just `location_context()`'s server-side enrichment.
		 */
		public function test_config_carries_the_settlement_record_key_when_the_chain_has_one(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$settlement = $this->location_record( 'dadata:settlement-pushkino' );
			$address    = $this->address_record( 'dadata:address-cherkizovo', [ 'dadata:settlement-pushkino' ] );

			$plugin = $this->location_plugin_with_chain( $settlement, $address );
			$config = $this->make_handler( [ 'plugin' => $plugin ] )->get_js_config();

			// The settlement rides in its OWN field. `current` keeps meaning the CURRENT
			// record — an earlier draft wrote the settlement into `current.key` itself, which
			// left the block naming one record in `current` while `implicit` described another
			// (adversarial review).
			$this->assertSame( 'dadata:settlement-pushkino', $config['location']['settlementKey'] );
			$this->assertSame(
				'dadata:address-cherkizovo',
				$config['location']['current']['key'],
				'current must still identify the CURRENT record, not the map\'s addressing locality'
			);
		}

		/**
		 * The fallback half of the same rule — an address typed without ever picking a
		 * settlement must still publish a usable key (its own), never `''`, which would
		 * needlessly disable the browser's own DOM fallback (gotcha
		 * `an-empty-domain-key-is-not-a-key` — `''` must stay reserved for "no record at
		 * all").
		 */
		public function test_config_carries_the_current_record_key_when_the_chain_has_no_settlement(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$address = $this->address_record( 'dadata:address-typed-only', [] );

			$plugin = $this->location_plugin( $address, null, true );
			$config = $this->make_handler( [ 'plugin' => $plugin ] )->get_js_config();

			$this->assertSame(
				'',
				$config['location']['settlementKey'],
				'no settlement in the chain — the field says so honestly rather than naming the address'
			);
			$this->assertSame(
				'dadata:address-typed-only',
				$config['location']['current']['key'],
				'and the browser falls back to THIS, so the map keeps working (the half #334\'s rule must not be copied onto)'
			);
		}

		/**
		 * Issue #309 (spec D11/§4.6): {@see Pickup_Handler::location_config_block()} used to
		 * discard {@see Location_Service::get_customer_record()}'s own `implicit` flag —
		 * {@see Pickup_Handler::current_location_record()} calls that method and narrows the
		 * result to the bare {@see Location_Record} on the very next line, throwing the flag
		 * away before `location_config_block()` ever saw it. A plugin/theme reading this
		 * config needs the flag too, per that method's own docblock ("e.g. to decide whether
		 * to still show a 'please choose your locality' prompt").
		 */
		public function test_config_carries_implicit_false_for_a_real_customer_choice(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$plugin = $this->location_plugin( $this->location_record( 'dadata:fias-1' ), null, true, false );
			$config = $this->make_handler( [ 'plugin' => $plugin ] )->get_js_config();

			$this->assertSame( 'dadata:fias-1', $config['location']['current']['key'] );
			$this->assertFalse( $config['location']['implicit'] );
		}

		/**
		 * Same seam, the other value — issue #309.
		 */
		public function test_config_carries_implicit_true_for_a_default_guess(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$plugin = $this->location_plugin( $this->location_record( 'dadata:fias-1' ), null, true, true );
			$config = $this->make_handler( [ 'plugin' => $plugin ] )->get_js_config();

			$this->assertSame( 'dadata:fias-1', $config['location']['current']['key'] );
			$this->assertTrue( $config['location']['implicit'] );
		}

		/**
		 * Review finding F1, rig-verified: a plugin wired with `$plugin` but whose
		 * {@see Location_Service::is_active()} answers `false` (a registered provider that
		 * is not, or not yet, configured) used to still emit `location.current.key: ''` —
		 * gating only on `$plugin` being non-null. That empty key then permanently disabled
		 * `pickup-mount.js`'s own DOM fallback and was sent to the server as the addressing
		 * locality itself, breaking the picker on every fresh checkout. The block must now
		 * be OMITTED entirely in this case, same as when no plugin was wired at all — see
		 * `Checkout_Config::build()`'s own sibling `location` block, which this method now
		 * gates identically.
		 */
		public function test_config_carries_no_location_block_when_the_plugin_is_wired_but_the_layer_is_not_active(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$plugin = $this->location_plugin( null, null, false );
			$config = $this->make_handler( [ 'plugin' => $plugin ] )->get_js_config();

			$this->assertArrayNotHasKey( 'location', $config );
		}

		/**
		 * Same review finding F1, with an existing customer record still on file (e.g. the
		 * provider was configured when the record was written, then unconfigured later) —
		 * the block must be OMITTED regardless of whether a record happens to exist, because
		 * `is_active()` false means no `/select` round trip can ever complete right now
		 * either way; a present-but-stale key would be just as misleading as an empty one.
		 */
		public function test_config_carries_no_location_block_when_the_layer_is_not_active_even_with_an_existing_record(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$plugin = $this->location_plugin( $this->location_record( 'dadata:fias-1' ), null, false );
			$config = $this->make_handler( [ 'plugin' => $plugin ] )->get_js_config();

			$this->assertArrayNotHasKey( 'location', $config );
		}

		// -------------------------------------------------------------------------
		// location_context() (Task 15; issue #159) — the Point_Query enrichment seam
		// register_rest() hands Pickup_Controller.
		// -------------------------------------------------------------------------

		public function test_location_context_is_null_when_no_plugin_was_wired(): void {
			$this->assertNull( $this->make_handler()->location_context() );
		}

		public function test_location_context_is_null_when_the_plugin_has_no_current_record(): void {
			$handler = $this->make_handler( [ 'plugin' => $this->location_plugin( null ) ] );

			$this->assertNull( $handler->location_context() );
		}

		/**
		 * Issue #324's rig fallout, and the defect this handler shares with the route that
		 * caused it: `Pickup_Controller::get_points_data()` reads the customer's location
		 * record (`attach_location_context()`) BEFORE anything raises a WooCommerce session,
		 * and the only `wc_load_cart()` on that path — {@see Pickup_Handler::current_cart_weight_grams()}
		 * — runs two lines LATER. For a guest, whose record lives only in `WC()->session`,
		 * the record was therefore never attached and the live source fell back to matching
		 * the raw locality KEY the browser sends, which matches nothing: every guest saw
		 * «В выбранном населённом пункте нет пунктов выдачи» over 812 live points.
		 *
		 * It only became visible once #324 gave a guest a persisted record at all — before
		 * that, `location_config_block()` emitted an empty key and the browser fell back to
		 * reading the city from the DOM, which the source did match. The hole predates the
		 * fix; the fix stopped hiding it.
		 *
		 * Measured on the rig as a real guest, same session cookie on both requests:
		 * `?locality=dadata:0c5b2444-…` → 0 points, `?locality=Moscow` → 812.
		 */
		public function test_location_context_raises_the_wc_session_before_reading_the_record(): void {
			$record  = $this->location_record( 'dadata:fias-1' );
			$session = new Pickup_Handler_Location_Fake_Session();
			$store   = new Pickup_Handler_Deferred_Session_Store( $session );

			// The record is already stored — a previous front-end request put it there.
			$store->raise();
			$store->set( $record );
			// …and this request starts the way a guest REST request does: not attached to it.
			$store->hide();

			$plugin = ( new \ReflectionClass( Pickup_Handler_Location_Fixture_Plugin::class ) )->newInstanceWithoutConstructor();

			// An adapter is required, not decoration: with none wired, `resolve_for()` throws
			// and `location_context()` legitimately answers null — which would make this test
			// pass for the wrong reason before the fix and fail for the wrong reason after.
			$plugin->fake_adapter          = new class implements Location_Adapter {
				public function resolve( Location_Record $record ) {
					return 'carrier-city-77';
				}
			};
			$plugin->fake_location_service = new Pickup_Handler_Location_Service_Active_Probe(
				Location_Provider_Registry::instance(),
				$store,
				true
			);

			$handler = new Pickup_Handler_Location_Bridge_Probe(
				'p',
				'carrier_pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				null,
				null,
				[],
				'#06aedd',
				'',
				true,
				false,
				null,
				$plugin
			);
			$handler->bind_store( $store );

			$context = $handler->location_context();

			$this->assertSame( 1, $handler->load_wc_cart_calls, 'the session must be raised, exactly once' );
			$this->assertNotNull( $context, 'the record is in the store; it must be readable' );
			$this->assertSame( 'dadata:fias-1', $context['record']->key() );
		}

		public function test_location_context_carries_the_record_and_resolved_identity(): void {
			$record  = $this->location_record( 'dadata:fias-1' );
			$adapter = new class implements Location_Adapter {
				public function resolve( Location_Record $record ) {
					return 'carrier-city-77';
				}
			};

			$handler = $this->make_handler( [ 'plugin' => $this->location_plugin( $record, $adapter ) ] );
			$context = $handler->location_context();

			$this->assertNotNull( $context );
			$this->assertSame( $record->key(), $context['record']->key() );
			$this->assertSame( 'carrier-city-77', $context['resolved_identity'] );
		}

		public function test_location_context_carries_a_null_resolved_identity_when_the_carrier_does_not_serve_the_locality(): void {
			// A legitimate, first-class answer (Location_Adapter::resolve()'s own
			// docblock) — must round-trip as null, not be confused with "no context at all"
			// (which this method ALSO answers with null — Point_Query::get_record() is what
			// tells the two apart, per that class' own docblock).
			$record  = $this->location_record();
			$adapter = new class implements Location_Adapter {
				public function resolve( Location_Record $record ) {
					return null;
				}
			};

			$handler = $this->make_handler( [ 'plugin' => $this->location_plugin( $record, $adapter ) ] );
			$context = $handler->location_context();

			$this->assertNotNull( $context );
			$this->assertSame( $record->key(), $context['record']->key() );
			$this->assertNull( $context['resolved_identity'] );
		}

		public function test_location_context_is_null_when_the_adapter_throws(): void {
			// A transient failure (Location_Adapter::resolve()'s own contract) must not
			// fatal a public, guest-facing points request — the fetch simply proceeds
			// without location context.
			$record  = $this->location_record();
			$adapter = new class implements Location_Adapter {
				public function resolve( Location_Record $record ) {
					throw new \RuntimeException( 'carrier API timeout' );
				}
			};

			$handler = $this->make_handler( [ 'plugin' => $this->location_plugin( $record, $adapter ) ] );

			$this->assertNull( $handler->location_context() );
		}

		// -------------------------------------------------------------------------
		// current_location_record() settlement-preferred / current-fallback rule
		// (issue #336), exercised through location_context() — current_location_record()
		// is `protected` and has exactly one caller.
		// -------------------------------------------------------------------------

		/**
		 * Issue #336's own rig measurement: after picking a settlement then an address
		 * inside it, `current` sits at the address level, whose OWN settlement component
		 * can be a DIFFERENT, deeper locality than the one the customer chose (9 of 10
		 * addresses under «г Пушкино» nested a different settlement). The map must
		 * address itself by the settlement the customer actually picked, not the
		 * current record.
		 */
		public function test_location_context_addresses_by_the_settlement_record_when_the_chain_has_one(): void {
			$settlement = $this->location_record( 'dadata:settlement-pushkino' );
			$address    = $this->address_record( 'dadata:address-cherkizovo', [ 'dadata:settlement-pushkino' ] );
			$adapter    = new class implements Location_Adapter {
				public function resolve( Location_Record $record ) {
					return 'carrier-city';
				}
			};

			$plugin  = $this->location_plugin_with_chain( $settlement, $address, $adapter );
			$handler = $this->make_handler( [ 'plugin' => $plugin ] );

			$context = $handler->location_context();

			$this->assertNotNull( $context );
			$this->assertSame( 'dadata:settlement-pushkino', $context['record']->key(), 'the map must address by the settlement the customer picked, not the deeper address record' );
		}

		/**
		 * Deliberately the OPPOSITE of #334's storage-key rule (spec: "Deliberately OUT of
		 * scope" section) — a customer who typed an address WITHOUT ever picking a
		 * settlement (`backwardsFill()` writes the settlement FIELD's text but creates no
		 * settlement RECORD) must still see pickup points: the map falls back to the
		 * current (address) record rather than refusing.
		 */
		public function test_location_context_falls_back_to_the_current_record_when_the_chain_has_no_settlement(): void {
			// No settlement ever picked — an address record with NO ancestors at all, the
			// one-entry chain #336's own "known, accepted degradation" section describes.
			$address = $this->address_record( 'dadata:address-typed-only', [] );
			$adapter = new class implements Location_Adapter {
				public function resolve( Location_Record $record ) {
					return 'carrier-city';
				}
			};

			$plugin  = $this->location_plugin( $address, $adapter );
			$handler = $this->make_handler( [ 'plugin' => $plugin ] );

			$context = $handler->location_context();

			$this->assertNotNull( $context, 'a working map must not regress into refusing here' );
			$this->assertSame( 'dadata:address-typed-only', $context['record']->key() );
		}

		/**
		 * `location_context()` must resolve the adapter for the SAME record it returns
		 * (issue #336) — the settlement-preferred one, not a re-derived current record —
		 * so the carrier's own city resolution agrees with what the map is addressed by.
		 */
		public function test_location_context_resolves_the_adapter_against_the_settlement_record_not_the_current_one(): void {
			$settlement = $this->location_record( 'dadata:settlement-pushkino' );
			$address    = $this->address_record( 'dadata:address-cherkizovo', [ 'dadata:settlement-pushkino' ] );

			$adapter = new class implements Location_Adapter {
				/** @var string[] */
				public array $received_keys = [];

				public function resolve( Location_Record $record ) {
					$this->received_keys[] = $record->key();

					return 'carrier-city';
				}
			};

			$plugin  = $this->location_plugin_with_chain( $settlement, $address, $adapter );
			$handler = $this->make_handler( [ 'plugin' => $plugin ] );

			$handler->location_context();

			$this->assertSame( [ 'dadata:settlement-pushkino' ], $adapter->received_keys, 'the adapter must resolve for the settlement record, never the deeper current one' );
		}

		/**
		 * Value-mutant guard: proves `provider` in the config tracks the constructor
		 * argument rather than a hardcoded 'yandex' literal — every other test in this
		 * file happens to use 'yandex', so only a second, different value can catch that.
		 */
		public function test_config_provider_matches_the_constructor_argument_not_a_hardcoded_value(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$source = $this->source_returning( null );
			$config = ( new Pickup_Handler(
				'p',
				'carrier_pickup_point',
				$source,
				$this->embedded_provider(),
				$this->default_location()
			) )->get_js_config();

			$this->assertSame( 'embedded', $config['provider'] );
		}

		/**
		 * `mapConfig` is empty when the active provider's own get_js_config() returns
		 * nothing — proves the handler does not invent a placeholder shape of its own.
		 */
		public function test_config_map_config_is_empty_when_the_provider_supplies_nothing(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$source = $this->source_returning( null );
			$config = ( new Pickup_Handler(
				'p',
				'carrier_pickup_point',
				$source,
				$this->yandex_provider(),
				$this->default_location()
			) )->get_js_config();

			$this->assertSame( [], $config['mapConfig'] );
		}

		/**
		 * SP-5 Task 9 wiring proof: `mapConfig` comes straight from the active provider's
		 * OWN get_js_config() — not a hardcoded `[]`, not a copy, the SAME array. Uses a
		 * distinctive, provider-supplied value (`scriptUrl`) no other code path could
		 * produce, so a mutant that ignores the provider or substitutes an empty array
		 * cannot pass.
		 */
		public function test_config_map_config_is_populated_from_the_active_provider(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$source   = $this->source_returning( null );
			$provider = $this->yandex_provider( [ 'scriptUrl' => 'https://api-maps.yandex.ru/2.1/?apikey=TEST' ] );
			$config   = ( new Pickup_Handler(
				'p',
				'carrier_pickup_point',
				$source,
				$provider,
				$this->default_location()
			) )->get_js_config();

			$this->assertSame(
				[ 'scriptUrl' => 'https://api-maps.yandex.ru/2.1/?apikey=TEST' ],
				$config['mapConfig']
			);
		}

		/**
		 * `$context` is plumbed through to the provider, not just accepted and ignored — a
		 * mutant emptying the real call site to `get_js_config( [] )` produces no visible
		 * difference in `mapConfig` itself (an unused parameter changes no output), so this
		 * asserts what the provider actually RECEIVED via a recording spy instead.
		 */
		public function test_get_js_config_passes_the_plugin_id_as_context_to_the_provider(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$source   = $this->source_returning( null );
			$provider = new Pickup_Handler_Test_Map_Provider( 'yandex' );

			( new Pickup_Handler(
				'carrier-x',
				'carrier_pickup_point',
				$source,
				$provider,
				$this->default_location()
			) )->get_js_config();

			$this->assertSame( [ 'plugin_id' => 'carrier-x' ], $provider->received_context );
		}

		/**
		 * Coordination proof with {@see Yandex_Map_Provider}: the REAL provider's config —
		 * not a test double — must still pass the credential-shaped-key guard once wired
		 * into `mapConfig`. The API key reaches the browser only INSIDE the `scriptUrl`
		 * value, never under a bare `apiKey`/`api_key` key.
		 */
		public function test_the_real_yandex_provider_config_passes_the_credential_shaped_key_guard(): void {
			require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/map/class-yandex-map-provider.php';

			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );
			Functions\when( 'get_locale' )->justReturn( 'ru_RU' );
			// Faithful (non-encoding) stand-in for the REAL add_query_arg() — see
			// MapProviderRegistryTest for why http_build_query() would mask a missing
			// rawurlencode() call.
			Functions\when( 'add_query_arg' )->alias(
				static function ( array $args, string $url ) {
					$pairs = [];

					foreach ( $args as $key => $value ) {
						$pairs[] = $key . '=' . $value;
					}

					return $url . '?' . implode( '&', $pairs );
				}
			);

			$source   = $this->source_returning( null );
			$provider = new \Woodev\Framework\Shipping\Map\Yandex_Map_Provider( 'REAL-SECRET-KEY' );
			$config   = ( new Pickup_Handler(
				'p',
				'carrier_pickup_point',
				$source,
				$provider,
				$this->default_location()
			) )->get_js_config();

			$this->assertConfigHasNoObjectsOrClosures( $config );
			$this->assertStringContainsString( 'apikey=REAL-SECRET-KEY', $config['mapConfig']['scriptUrl'] );
		}

		/**
		 * T20 wiring proof: the REAL {@see Embedded_Map_Provider}'s `mapConfig.ownsChrome` is
		 * `true` — the exact flag `pickup-mount.js` reads to decide whether to construct the
		 * framework's own list/card panels at all (T20). Exercised against the real provider
		 * class, not a test double, so a mutant that de-syncs `Embedded_Map_Provider::get_js_config()`
		 * from its own `owns_chrome()` cannot pass unnoticed.
		 */
		public function test_the_real_embedded_provider_config_carries_owns_chrome_true(): void {
			require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/map/class-embedded-map-provider.php';

			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );
			Functions\when( 'untrailingslashit' )->alias( static fn( string $value ) => rtrim( $value, '/' ) );

			$provider = new \Woodev\Framework\Shipping\Map\Embedded_Map_Provider(
				'https://carrier.example/widget',
				'https://carrier.example'
			);
			$config   = ( new Pickup_Handler(
				'p',
				'carrier_pickup_point',
				$this->source_returning( null ),
				$provider,
				$this->default_location()
			) )->get_js_config();

			$this->assertTrue( $config['mapConfig']['ownsChrome'] );
		}

		/**
		 * The other side of the same proof: the REAL {@see Yandex_Map_Provider} carries no
		 * `ownsChrome` key at all (it never draws the whole chrome) — `pickup-mount.js` treats
		 * an ABSENT key as falsy, so this is the "not owning chrome" shape a real consumer
		 * produces, not just a test double's default.
		 */
		public function test_the_real_yandex_provider_config_carries_no_owns_chrome_key(): void {
			require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/map/class-yandex-map-provider.php';

			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );
			Functions\when( 'get_locale' )->justReturn( 'ru_RU' );
			Functions\when( 'add_query_arg' )->alias(
				static function ( array $args, string $url ) {
					$pairs = [];

					foreach ( $args as $key => $value ) {
						$pairs[] = $key . '=' . $value;
					}

					return $url . '?' . implode( '&', $pairs );
				}
			);

			$provider = new \Woodev\Framework\Shipping\Map\Yandex_Map_Provider( 'REAL-SECRET-KEY' );
			$config   = ( new Pickup_Handler(
				'p',
				'carrier_pickup_point',
				$this->source_returning( null ),
				$provider,
				$this->default_location()
			) )->get_js_config();

			$this->assertArrayNotHasKey( 'ownsChrome', $config['mapConfig'] );
		}

		public function test_config_replace_address_carries_billing_only_and_never_a_target(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( true );

			$source = $this->source_returning( null );
			$config = ( new Pickup_Handler(
				'p',
				'carrier_pickup_point',
				$source,
				$this->yandex_provider(),
				$this->default_location()
			) )->get_js_config();

			$this->assertSame( [ 'enabled' => true, 'billingOnly' => true ], $config['replaceAddress'] );
			$this->assertArrayNotHasKey( 'target', $config['replaceAddress'] );
		}

		public function test_config_replace_address_billing_only_reflects_the_store_setting(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$source = $this->source_returning( null );
			$config = ( new Pickup_Handler(
				'p',
				'carrier_pickup_point',
				$source,
				$this->yandex_provider(),
				$this->default_location()
			) )->get_js_config();

			$this->assertFalse( $config['replaceAddress']['billingOnly'] );
		}

		// -------------------------------------------------------------------------
		// get_js_config() — the `selection` block (Task 4): what the browser falls
		// back to when the domain's verdict says nothing about a flag
		// -------------------------------------------------------------------------

		/**
		 * Both flags are `false` by default, and that is a decision, not an accident —
		 * `close` reads {@see Pickup_Map_Settings}'s own `pickup_close_on_select` default
		 * (Task 8, issue #362, design S7); `refreshCheckout` is
		 * {@see Pickup_Handler::$refresh_checkout}'s own default. Pinned here so a
		 * "harmless" default flip (which would silently close the modal on every
		 * carrier, or bill every carrier for a checkout refresh) cannot land with the
		 * suite green.
		 */
		public function test_config_selection_flags_both_default_to_false(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			$this->stub_config_dependencies_except_filters();

			$config = $this->make_handler()->get_js_config();

			$this->assertSame(
				[ 'close' => false, 'refreshCheckout' => false ],
				$config['selection']
			);
		}

		/**
		 * `close` now comes from the STORE setting (Task 8, issue #362, design S7) — no
		 * longer a constructor argument, since a customer must see the same close-on-select
		 * behaviour across every carrier at once. `refreshCheckout` stays a per-carrier
		 * constructor argument, since a refresh is a fact about how THAT carrier's price
		 * behaves.
		 */
		public function test_config_selection_close_comes_from_the_store_refresh_from_the_constructor(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			$this->stub_config_dependencies_except_filters();
			Functions\when( 'get_option' )->alias(
				fn( $k, $d = false ) => 'woodev_pickup_map_pickup_close_on_select' === $k ? 'yes' : $d
			);
			Shipping_Settings_Tab::reset_for_tests();

			$config = $this->make_handler( [ 'refresh_checkout' => true ] )->get_js_config();

			$this->assertSame(
				[ 'close' => true, 'refreshCheckout' => true ],
				$config['selection']
			);
		}

		/**
		 * The two flags are independent switches, not one setting under two names — a
		 * store that wants the modal to close on select does not thereby bill every
		 * carrier for a checkout refresh, and a carrier opting into a refresh does not
		 * thereby close the modal for every OTHER carrier too. A mutant conflating the
		 * store setting with the constructor argument survives the test above; it does
		 * not survive this one.
		 */
		public function test_config_selection_flags_are_independent(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			$this->stub_config_dependencies_except_filters();

			Functions\when( 'get_option' )->alias(
				fn( $k, $d = false ) => 'woodev_pickup_map_pickup_close_on_select' === $k ? 'yes' : $d
			);
			Shipping_Settings_Tab::reset_for_tests();
			$close_only = $this->make_handler()->get_js_config();

			Functions\when( 'get_option' )->justReturn( null );
			Shipping_Settings_Tab::reset_for_tests();
			$refresh = $this->make_handler( [ 'refresh_checkout' => true ] )->get_js_config();

			$this->assertSame( [ 'close' => true, 'refreshCheckout' => false ], $close_only['selection'] );
			$this->assertSame( [ 'close' => false, 'refreshCheckout' => true ], $refresh['selection'] );
		}

		/**
		 * Both store settings at once, in the shape they actually reach the browser
		 * together — `replaceAddress.enabled` and `selection.close` both read the store
		 * ({@see Pickup_Map_Settings}), `selection.refreshCheckout` stays the constructor
		 * argument. Proves the two store reads do not accidentally share a cached value
		 * (a mutant reading `pickup_replace_address` for both would report `false` twice
		 * or `true` twice, never the mixed pair this test pins).
		 */
		public function test_js_config_reads_replace_address_and_close_from_the_store(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			$this->stub_config_dependencies_except_filters();
			Functions\when( 'get_option' )->alias(
				fn( $k, $d = false ) => [
					'woodev_pickup_map_pickup_replace_address' => false,
					'woodev_pickup_map_pickup_close_on_select' => true,
				][ $k ] ?? $d
			);
			Shipping_Settings_Tab::reset_for_tests();

			$config = $this->make_handler()->get_js_config();

			$this->assertFalse( $config['replaceAddress']['enabled'] );
			$this->assertTrue( $config['selection']['close'] );
			$this->assertFalse( $config['selection']['refreshCheckout'] ); // still the ctor arg
		}

		/**
		 * The confirmation strings (Task 4, plus #297's `selectFailedEmbedded`) the CTA's
		 * busy/failure states read by name. A missing key here renders BLANK under a button
		 * the customer just pressed, so presence AND non-emptiness are both asserted — the
		 * same contract the panel keys carry.
		 */
		public function test_config_i18n_carries_the_confirmation_strings(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			$this->stub_config_dependencies_except_filters();

			$i18n = $this->make_handler()->get_js_config()['i18n'];

			foreach ( [ 'confirming', 'selectFailed', 'selectFailedEmbedded', 'stalePage' ] as $key ) {
				$this->assertArrayHasKey( $key, $i18n, "i18n is missing the \"{$key}\" confirmation key" );
				$this->assertNotSame( '', $i18n[ $key ], "i18n[\"{$key}\"] must not be empty" );
			}

			// `selectFailed` is deliberately NOT the generic `error` string: that one is
			// written for a failed points FETCH and would be misleading under a confirm button.
			$this->assertNotSame( $i18n['error'], $i18n['selectFailed'] );

			// #297: `selectFailedEmbedded` is the `ownsChrome` counterpart of `selectFailed` and
			// must actually say something different -- the whole point is that it stops
			// promising a repeat of the carrier's own confirm press.
			$this->assertNotSame( $i18n['selectFailed'], $i18n['selectFailedEmbedded'] );
			$this->assertStringNotContainsString(
				'Попробуйте ещё раз',
				$i18n['selectFailedEmbedded'],
				'selectFailedEmbedded must not promise a retry the ownsChrome customer cannot make'
			);
		}

		public function test_config_rest_root_uses_the_sanitized_plugin_segment(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->alias(
				static fn( $path ) => 'https://example.test/wp-json/' . $path
			);
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$source  = $this->source_returning( null );
			$handler = new Pickup_Handler(
				'carrier!!!',
				'carrier_pickup_point',
				$source,
				$this->yandex_provider(),
				$this->default_location()
			);
			$config  = $handler->get_js_config();

			$this->assertSame(
				'https://example.test/wp-json/woodev/v1/shipping/pickup/carrier/points',
				$config['restRoot']
			);
		}

		public function test_config_rest_root_falls_back_to_shipping_for_an_all_symbol_plugin_id(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->alias(
				static fn( $path ) => 'https://example.test/wp-json/' . $path
			);
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$source = $this->source_returning( null );
			$config = ( new Pickup_Handler(
				'!!!',
				'carrier_pickup_point',
				$source,
				$this->yandex_provider(),
				$this->default_location()
			) )->get_js_config();

			$this->assertSame(
				'https://example.test/wp-json/woodev/v1/shipping/pickup/shipping/points',
				$config['restRoot']
			);
		}

		/**
		 * Allowlist, not a blacklist: a blacklist of forbidden key names can never fail
		 * closed against a regression whose name nobody predicted. Asserting the exact
		 * top-level key set catches ANY new top-level key the instant it appears — this
		 * held even while `mapConfig` was still `[]` (pre-Task-9) and continues to hold
		 * now that it carries the active provider's own config.
		 */
		public function test_config_top_level_keys_are_exactly_the_allowlisted_set(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$source = $this->source_returning( null );
			$config = ( new Pickup_Handler(
				'p',
				'carrier_pickup_point',
				$source,
				$this->yandex_provider(),
				$this->default_location()
			) )->get_js_config();

			$this->assertSame(
				[
					'fieldId',
					'strategy',
					'maxAccumulatedPoints',
					'provider',
					'restRoot',
					'nonce',
					'nonceNodeId',
					'i18n',
					'chosenAddress',
					'selections',
					'defaultLocation',
					'pointIcons',
					'pointGlyphs',
					'mapConfig',
					'replaceAddress',
					'selection',
					'accentColor',
					'accentFillColor',
					'accentContrastColor',
					'modal',
					'search',
				],
				array_keys( $config )
			);
		}

		// -------------------------------------------------------------------------
		// maxAccumulatedPoints (#234) — the viewport point-pool cap seam.
		// -------------------------------------------------------------------------

		public function test_js_config_defaults_max_accumulated_points_to_zero_meaning_unlimited(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			$this->stub_config_dependencies_except_filters();

			$config = $this->make_handler()->get_js_config();

			$this->assertSame( 0, $config['maxAccumulatedPoints'] );
		}

		public function test_js_config_max_accumulated_points_is_filterable_and_never_negative(): void {
			Filters\expectApplied( 'woodev_pickup_max_accumulated_points' )
				->once()
				->with( 0, 'p' )
				->andReturn( -5 );
			$this->stub_config_dependencies_except_filters();

			$config = $this->make_handler()->get_js_config();

			$this->assertSame( 0, $config['maxAccumulatedPoints'] );
		}

		public function test_js_config_passes_a_positive_max_accumulated_points_through(): void {
			Filters\expectApplied( 'woodev_pickup_max_accumulated_points' )
				->once()
				->with( 0, 'p' )
				->andReturn( 3000 );
			$this->stub_config_dependencies_except_filters();

			$config = $this->make_handler()->get_js_config();

			$this->assertSame( 3000, $config['maxAccumulatedPoints'] );
		}

		/**
		 * Adversarial review, 09.08.2026: a bare `(int)` cast turns a NON-EMPTY ARRAY into `1`,
		 * so a filter that mistakenly returns its whole settings structure — an easy slip —
		 * would bound the browser's pool to a SINGLE point and silently reinstate the defect
		 * #234 exists to fix, in its worst form. A filter nobody wrote correctly must change
		 * nothing, never impose a hostile bound.
		 */
		public function test_js_config_max_accumulated_points_ignores_an_array_filter_return(): void {
			Filters\expectApplied( 'woodev_pickup_max_accumulated_points' )
				->once()
				->with( 0, 'p' )
				->andReturn( [ 'max' => 500 ] );
			$this->stub_config_dependencies_except_filters();

			$config = $this->make_handler()->get_js_config();

			$this->assertSame( 0, $config['maxAccumulatedPoints'] );
		}

		/**
		 * Same rule for a non-numeric scalar: `'unlimited'`, `null` and `false` all cast to `0`
		 * anyway, but `is_numeric()` makes that the DECLARED behaviour rather than a coincidence
		 * of PHP's cast table — and it is what keeps the array case above from being special.
		 */
		public function test_js_config_max_accumulated_points_ignores_a_non_numeric_filter_return(): void {
			Filters\expectApplied( 'woodev_pickup_max_accumulated_points' )
				->once()
				->with( 0, 'p' )
				->andReturn( 'unlimited' );
			$this->stub_config_dependencies_except_filters();

			$config = $this->make_handler()->get_js_config();

			$this->assertSame( 0, $config['maxAccumulatedPoints'] );
		}

		/**
		 * A numeric STRING is a legitimate return (options come back as strings from the DB) and
		 * must survive — the guard rejects non-numeric input, not non-int input.
		 */
		public function test_js_config_max_accumulated_points_accepts_a_numeric_string(): void {
			Filters\expectApplied( 'woodev_pickup_max_accumulated_points' )
				->once()
				->with( 0, 'p' )
				->andReturn( '2500' );
			$this->stub_config_dependencies_except_filters();

			$config = $this->make_handler()->get_js_config();

			$this->assertSame( 2500, $config['maxAccumulatedPoints'] );
		}

		// -------------------------------------------------------------------------
		// modal size + search flag (Task 18, spec V-1 / V-6)
		// -------------------------------------------------------------------------

		/**
		 * The dialog sizes itself before any content exists (spec V-1) — these two values
		 * used to live only in CSS, on the map element, which is why the modal opened as a
		 * header-tall strip until the map mounted. `search` defaults to `true` — see
		 * {@see Pickup_Handler::$search_enabled}'s own docblock for why it is a handler
		 * property rather than a `Map_Provider` method.
		 */
		public function test_config_carries_the_modal_size_and_the_search_flag(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			$this->stub_config_dependencies_except_filters();

			$config = $this->make_handler()->get_js_config();

			// 1024, not the original 920: the sidebar takes a fixed 320px whenever it is open,
			// so at 920 the map was left under 600px wide (operator's live review, s51).
			$this->assertSame( 1024, $config['modal']['width'] );
			$this->assertSame( 'min(80vh, 800px)', $config['modal']['bodyHeight'] );
			$this->assertTrue( $config['search'] );
		}

		public function test_search_can_be_disabled_via_the_constructor(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			$this->stub_config_dependencies_except_filters();

			$config = $this->make_handler( [ 'search_enabled' => false ] )->get_js_config();

			$this->assertFalse( $config['search'] );
		}

		public function test_a_filter_overrides_the_search_flag(): void {
			Filters\expectApplied( 'woodev_pickup_map_search_enabled' )
				->once()
				->with( true, 'p' )
				->andReturn( false );
			$this->stub_config_dependencies_except_filters();

			$config = $this->make_handler()->get_js_config();

			$this->assertFalse( $config['search'] );
		}

		// -------------------------------------------------------------------------
		// get_js_config() — defaultLocation (Task 8 / D-7) and pointIcons (Task 8 / D-5)
		// -------------------------------------------------------------------------

		/**
		 * The required `$default_location` constructor argument reaches the browser
		 * verbatim under `defaultLocation` — the fallback the client uses when the
		 * geocoder cascade (viewport strategy) or the initial bulk load cannot yet centre
		 * the map on the buyer's own city (D-7).
		 */
		public function test_the_default_location_reaches_the_browser_config(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$handler = $this->make_handler( [ 'default_location' => [ 'center' => [ 55.76, 37.64 ], 'zoom' => 12 ] ] );

			$this->assertSame(
				[ 'center' => [ 55.76, 37.64 ], 'zoom' => 12 ],
				$handler->get_js_config()['defaultLocation']
			);
		}

		/**
		 * @param mixed $default_location
		 *
		 * @dataProvider provide_invalid_default_locations
		 */
		public function test_an_invalid_default_location_is_rejected_on_construction( $default_location ): void {
			$this->expectException( \InvalidArgumentException::class );

			$this->make_handler( [ 'default_location' => $default_location ] );
		}

		/**
		 * @return array<string, array{0: array<string, mixed>}>
		 */
		public function provide_invalid_default_locations(): array {
			return [
				'missing center key'         => [ [ 'zoom' => 10 ] ],
				'missing zoom key'           => [ [ 'center' => [ 55.75, 37.61 ] ] ],
				'center with three elements' => [ [ 'center' => [ 55.75, 37.61, 0 ], 'zoom' => 10 ] ],
				'center as a string'         => [ [ 'center' => '55.75,37.61', 'zoom' => 10 ] ],
				'latitude above 90'          => [ [ 'center' => [ 95.0, 37.61 ], 'zoom' => 10 ] ],
				'latitude below -90'         => [ [ 'center' => [ -95.0, 37.61 ], 'zoom' => 10 ] ],
				'longitude above 180'        => [ [ 'center' => [ 55.75, 185.0 ], 'zoom' => 10 ] ],
				'longitude below -180'       => [ [ 'center' => [ 55.75, -185.0 ], 'zoom' => 10 ] ],
				// NAN fails every ordinary comparison (including `< -90`/`> 90`), so it would
				// silently sail through a range check alone — is_finite() is what catches it.
				// INF/-INF are legitimate `is_float()` values too, and would otherwise reach
				// wp_json_encode(), which cannot represent either.
				'latitude is NAN'            => [ [ 'center' => [ NAN, 37.61 ], 'zoom' => 10 ] ],
				'latitude is INF'            => [ [ 'center' => [ INF, 37.61 ], 'zoom' => 10 ] ],
				'latitude is -INF'           => [ [ 'center' => [ -INF, 37.61 ], 'zoom' => 10 ] ],
				'longitude is NAN'           => [ [ 'center' => [ 55.75, NAN ], 'zoom' => 10 ] ],
				'longitude is INF'           => [ [ 'center' => [ 55.75, INF ], 'zoom' => 10 ] ],
				'longitude is -INF'          => [ [ 'center' => [ 55.75, -INF ], 'zoom' => 10 ] ],
				'zoom as a float'            => [ [ 'center' => [ 55.75, 37.61 ], 'zoom' => 10.5 ] ],
				'zoom as a numeric string'   => [ [ 'center' => [ 55.75, 37.61 ], 'zoom' => '10' ] ],
				'zoom below the map minimum' => [ [ 'center' => [ 55.75, 37.61 ], 'zoom' => 7 ] ],
				'zoom above the map maximum' => [ [ 'center' => [ 55.75, 37.61 ], 'zoom' => 19 ] ],
			];
		}

		/**
		 * A latitude/longitude expressed as an INT (e.g. a whole-degree city centre) is
		 * just as valid as a float — "two floats" in the spec means "two real numbers",
		 * not "must already be typed float".
		 */
		public function test_default_location_accepts_integer_latitude_and_longitude(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$handler = $this->make_handler( [ 'default_location' => [ 'center' => [ 56, 38 ], 'zoom' => 10 ] ] );

			$this->assertSame( [ 'center' => [ 56, 38 ], 'zoom' => 10 ], $handler->get_js_config()['defaultLocation'] );
		}

		/**
		 * The zoom bounds are inclusive at both ends — 8 and 18 (the map's own
		 * `minZoom`/`maxZoom`, see D-7) must both be accepted, not just the interior range.
		 */
		public function test_default_location_accepts_zoom_at_the_inclusive_bounds(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$min = $this->make_handler( [ 'default_location' => [ 'center' => [ 55.75, 37.61 ], 'zoom' => 8 ] ] );
			$max = $this->make_handler( [ 'default_location' => [ 'center' => [ 55.75, 37.61 ], 'zoom' => 18 ] ] );

			$this->assertSame( 8, $min->get_js_config()['defaultLocation']['zoom'] );
			$this->assertSame( 18, $max->get_js_config()['defaultLocation']['zoom'] );
		}

		/**
		 * Icons are passed through with `active` falling back to `default` when a plugin
		 * supplies only one image per type (D-5) — reproduces CDEK's single-image,
		 * size-only active state.
		 */
		public function test_icons_are_passed_through_with_active_falling_back_to_default(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$handler = $this->make_handler( [
				'point_icons' => [ 'postamat' => [ 'default' => 'https://example.test/pm.svg' ] ],
			] );

			$this->assertSame(
				[
					'postamat' => [
						'default' => 'https://example.test/pm.svg',
						'active'  => 'https://example.test/pm.svg',
					],
				],
				$handler->get_js_config()['pointIcons']
			);
		}

		/**
		 * A plugin supplying BOTH images per type (the Yandex reference's shape) must see
		 * both come through unchanged, not collapsed to the default-falls-back-to-active
		 * shape {@see self::test_icons_are_passed_through_with_active_falling_back_to_default()}
		 * exercises.
		 */
		public function test_icons_with_both_states_keep_both_states_distinct(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$handler = $this->make_handler( [
				'point_icons' => [
					'pvz' => [
						'default' => 'https://example.test/pvz.svg',
						'active'  => 'https://example.test/pvz-active.svg',
					],
				],
			] );

			$this->assertSame(
				[
					'pvz' => [
						'default' => 'https://example.test/pvz.svg',
						'active'  => 'https://example.test/pvz-active.svg',
					],
				],
				$handler->get_js_config()['pointIcons']
			);
		}

		/**
		 * @see esc-url-raw-for-js-consumed-urls — this is JSON, not HTML: `esc_url_raw()`,
		 * never `esc_url()`, or `&` in a querystring would come through as `&#038;`.
		 */
		public function test_icon_urls_are_escaped_for_a_json_payload(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$handler = $this->make_handler( [
				'point_icons' => [ 'pvz' => [ 'default' => 'https://example.test/a.svg?x=1&y=2' ] ],
			] );

			$this->assertStringContainsString( '&y=2', $handler->get_js_config()['pointIcons']['pvz']['default'] );
			$this->assertStringNotContainsString( '&amp;', $handler->get_js_config()['pointIcons']['pvz']['default'] );
		}

		/**
		 * The previous test only proves `default` is escaped with `esc_url_raw()` — a
		 * mutation that dropped escaping for `active` specifically would survive it, since
		 * every OTHER icon test either omits `active` entirely or uses a URL with no `&` to
		 * reveal the difference. This uses an `active` URL whose `&` would visibly become
		 * `&amp;` under the wrong (`esc_url()`) function, proving BOTH URLs go through
		 * `esc_url_raw()`, not just whichever one the other tests happened to check.
		 */
		public function test_the_active_icon_url_is_escaped_too_not_only_default(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$handler = $this->make_handler( [
				'point_icons' => [
					'pvz' => [
						'default' => 'https://example.test/a.svg',
						'active'  => 'https://example.test/a-active.svg?x=1&y=2',
					],
				],
			] );

			$active = $handler->get_js_config()['pointIcons']['pvz']['active'];

			$this->assertStringContainsString( '&y=2', $active );
			$this->assertStringNotContainsString( '&amp;', $active );
		}

		/**
		 * A type with no `default` at all is dropped entirely — the framework refuses to
		 * invent a placeholder icon, and an `active`-only entry is not a usable type.
		 */
		public function test_icon_normalization_drops_a_type_with_no_default_key(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$handler = $this->make_handler( [
				'point_icons' => [
					'orphan_active_only' => [ 'active' => 'https://example.test/orphan-active.svg' ],
					'pvz'                => [ 'default' => 'https://example.test/pvz.svg' ],
				],
			] );

			$icons = $handler->get_js_config()['pointIcons'];

			$this->assertArrayNotHasKey( 'orphan_active_only', $icons );
			$this->assertArrayHasKey( 'pvz', $icons );
		}

		/**
		 * A `default` URL that survives `esc_url_raw()` as an empty string — a
		 * `javascript:` URL is exactly the case WordPress's own escaping strips to nothing
		 * — must drop the whole type, not emit an icon pointing at `""`.
		 */
		public function test_icon_normalization_drops_a_type_whose_default_becomes_empty_after_escaping(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );
			// Faithful-enough stand-in for esc_url_raw()'s real bad-protocol stripping
			// (wp_kses_bad_protocol()) for the one case this test needs: a disallowed
			// scheme collapses to the empty string, exactly like the real function.
			Functions\when( 'esc_url_raw' )->alias(
				static function ( $url ) {
					return 0 === stripos( ltrim( (string) $url ), 'javascript:' ) ? '' : $url;
				}
			);

			$handler = $this->make_handler( [
				'point_icons' => [
					'malicious' => [ 'default' => 'javascript:alert(1)' ],
					'pvz'       => [ 'default' => 'https://example.test/pvz.svg' ],
				],
			] );

			$icons = $handler->get_js_config()['pointIcons'];

			$this->assertArrayNotHasKey( 'malicious', $icons );
			$this->assertArrayHasKey( 'pvz', $icons );
		}

		/**
		 * No plugin-supplied icons at all is a legitimate, common case (a plugin that
		 * hasn't opted into custom pins yet) — `pointIcons` must be an empty array, never
		 * missing or null.
		 */
		public function test_point_icons_defaults_to_an_empty_array(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$this->assertSame( [], $this->make_handler()->get_js_config()['pointIcons'] );
		}

		// -------------------------------------------------------------------------
		// pointGlyphs (issue #195) — sidebar list / point card glyph overrides, via the
		// `woodev_pickup_map_point_glyphs` filter. Separate map from pointIcons above: that one
		// drives the MAP's own marker pins, this one drives the list row and card chip.
		// -------------------------------------------------------------------------

		/**
		 * No plugin override at all is the common case — `pointGlyphs` must be an empty
		 * array, never missing or null; the CLIENT is what supplies the `warehouse` default
		 * for every type this map says nothing about (see `pickup-panels.js`'s
		 * `pointGlyphMarkup()`), never this method fabricating an entry.
		 */
		public function test_point_glyphs_defaults_to_an_empty_array(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			$this->stub_config_dependencies_except_filters();

			$this->assertSame( [], $this->make_handler()->get_js_config()['pointGlyphs'] );
		}

		/**
		 * A plugin naming one of the framework's two built-in glyphs for a type gets back
		 * `{ glyph: '<name>', markup: null }` — the client picks the OTHER built-in
		 * ({@see GLYPH_SVG} in pickup-panels.js) rather than treating the string as markup.
		 */
		public function test_a_builtin_glyph_key_is_passed_through(): void {
			Filters\expectApplied( 'woodev_pickup_map_point_glyphs' )
				->once()
				->with( [], 'p' )
				->andReturn( [ 'POSTAMAT' => 'package' ] );
			$this->stub_config_dependencies_except_filters();

			$this->assertSame(
				[ 'POSTAMAT' => [ 'glyph' => 'package', 'markup' => null ] ],
				$this->make_handler()->get_js_config()['pointGlyphs']
			);
		}

		/**
		 * A type the filter never mentions is simply absent — never filled in with a
		 * `warehouse` entry the client already defaults to on its own.
		 */
		public function test_a_type_the_filter_does_not_mention_is_absent_not_defaulted(): void {
			Filters\expectApplied( 'woodev_pickup_map_point_glyphs' )
				->once()
				->andReturn( [ 'POSTAMAT' => 'package' ] );
			$this->stub_config_dependencies_except_filters();

			$this->assertArrayNotHasKey( 'PVZ', $this->make_handler()->get_js_config()['pointGlyphs'] );
		}

		/**
		 * Raw SVG markup (anything that is not one of the two built-in glyph keys) survives
		 * sanitisation as `{ glyph: null, markup: '<sanitised svg>' }` — the client writes
		 * `markup` straight into `innerHTML` when present, so this proves BOTH override
		 * outcomes a plugin can reach are wired: swapping the built-in, and supplying its own.
		 */
		public function test_raw_svg_markup_is_sanitised_and_passed_through_as_markup(): void {
			Functions\when( 'wp_kses' )->alias(
				static function ( $html ) {
					// Faithful-enough stand-in for this test's own needs: strip <script>…</script>
					// like real wp_kses() would against an allowlist that excludes it — same
					// technique the richtext-setting test in SettingUpdateValueTest.php uses.
					return preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $html );
				}
			);
			Filters\expectApplied( 'woodev_pickup_map_point_glyphs' )
				->once()
				->andReturn( [ 'CUSTOM' => '<svg viewBox="0 0 24 24"><path d="M1 1"/></svg>' ] );
			$this->stub_config_dependencies_except_filters();

			$this->assertSame(
				[ 'CUSTOM' => [ 'glyph' => null, 'markup' => '<svg viewBox="0 0 24 24"><path d="M1 1"/></svg>' ] ],
				$this->make_handler()->get_js_config()['pointGlyphs']
			);
		}

		/**
		 * Markup that sanitises down to something with no `<svg` tag left at all (a plugin
		 * trying to smuggle a `<script>`, or simply returning plain, non-SVG text) drops the
		 * whole type entirely — the framework never ships a broken/empty override; the
		 * client's own `warehouse` default still applies for it.
		 */
		public function test_unsafe_markup_is_dropped_entirely(): void {
			Functions\when( 'wp_kses' )->alias(
				static function ( $html ) {
					return preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $html );
				}
			);
			Filters\expectApplied( 'woodev_pickup_map_point_glyphs' )
				->once()
				->andReturn( [
					'MALICIOUS' => '<script>alert(1)</script>',
					'PVZ'       => 'package',
				] );
			$this->stub_config_dependencies_except_filters();

			$glyphs = $this->make_handler()->get_js_config()['pointGlyphs'];

			$this->assertArrayNotHasKey( 'MALICIOUS', $glyphs );
			$this->assertArrayHasKey( 'PVZ', $glyphs );
		}

		/**
		 * A non-array return from the filter (a plugin mistake) is defensively treated as
		 * "no overrides" rather than fataling on the `foreach` below.
		 */
		public function test_a_non_array_filter_return_is_treated_as_no_overrides(): void {
			Filters\expectApplied( 'woodev_pickup_map_point_glyphs' )->once()->andReturn( 'not-an-array' );
			$this->stub_config_dependencies_except_filters();

			$this->assertSame( [], $this->make_handler()->get_js_config()['pointGlyphs'] );
		}

		// -------------------------------------------------------------------------
		// accentColor (Task 8B / D-15) — merchant setting -> plugin default ->
		// framework default, sanitised on BOTH ends (server + filter output)
		// -------------------------------------------------------------------------

		/**
		 * Stubs the four WP functions every `get_js_config()` call needs, EXCEPT
		 * `apply_filters` — a test using `Filters\expectApplied()` sets that up itself, and
		 * stubbing it here too would leave two competing expectations on the same
		 * mocked function.
		 */
		private function stub_config_dependencies_except_filters(): void {
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );
		}

		public function test_the_plugin_default_accent_reaches_the_browser_config(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			$this->stub_config_dependencies_except_filters();

			$handler = $this->make_handler( [ 'accent_color' => '#FCE000' ] );

			$this->assertSame( '#fce000', $handler->get_js_config()['accentColor'] );
		}

		public function test_a_merchant_setting_overrides_the_plugin_default(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			$this->stub_config_dependencies_except_filters();

			$handler = $this->make_handler( [ 'accent_color' => '#FCE000', 'setting_accent' => '#0a8c37' ] );

			$this->assertSame( '#0a8c37', $handler->get_js_config()['accentColor'] );
		}

		public function test_an_empty_merchant_setting_leaves_the_plugin_default_alone(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			$this->stub_config_dependencies_except_filters();

			$handler = $this->make_handler( [ 'accent_color' => '#FCE000', 'setting_accent' => '' ] );

			$this->assertSame( '#fce000', $handler->get_js_config()['accentColor'] );
		}

		/**
		 * The value is interpolated into CSS on the client, so a merchant-editable string
		 * reaching `setProperty()` unsanitised is not a cosmetic bug — this and the next
		 * test are the two that matter most in this section.
		 */
		public function test_a_malformed_colour_falls_back_instead_of_reaching_css(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			$this->stub_config_dependencies_except_filters();

			$handler = $this->make_handler( [
				'accent_color'   => '#FCE000',
				'setting_accent' => 'red; } body { display:none } .x {',
			] );

			$this->assertSame( '#fce000', $handler->get_js_config()['accentColor'] );
		}

		public function test_a_filter_overrides_everything(): void {
			Filters\expectApplied( 'woodev_pickup_accent_color' )->andReturn( '#1937ff' );
			$this->stub_config_dependencies_except_filters();

			$handler = $this->make_handler( [ 'accent_color' => '#FCE000' ] );

			$this->assertSame( '#1937ff', $handler->get_js_config()['accentColor'] );
		}

		/**
		 * The non-obvious half: the filter is the one input a site owner controls that no
		 * settings-page validation ever sees, so its return value must be sanitised exactly
		 * like the merchant setting and the plugin default are.
		 */
		public function test_a_filter_returning_garbage_is_sanitised_too(): void {
			Filters\expectApplied( 'woodev_pickup_accent_color' )->andReturn( 'javascript:alert(1)' );
			$this->stub_config_dependencies_except_filters();

			$handler = $this->make_handler( [ 'accent_color' => '#FCE000' ] );

			$this->assertSame( '#fce000', $handler->get_js_config()['accentColor'] );
		}

		/**
		 * A filter returning garbage falls back to the PLUGIN's own default, not straight
		 * to the framework's — the framework default is the last resort, reached only when
		 * the plugin's own default is ALSO unusable (see the next test).
		 */
		public function test_a_filter_returning_garbage_falls_back_to_the_plugin_default_not_the_framework_one(): void {
			Filters\expectApplied( 'woodev_pickup_accent_color' )->andReturn( 'not-a-colour' );
			$this->stub_config_dependencies_except_filters();

			$handler = $this->make_handler( [ 'accent_color' => '#0a8c37' ] );

			$this->assertSame( '#0a8c37', $handler->get_js_config()['accentColor'] );
		}

		/**
		 * When NEITHER the filtered value NOR the plugin's own default sanitises cleanly,
		 * the framework's own hardcoded default is the final backstop — `accentColor` must
		 * never be empty or malformed.
		 */
		public function test_the_framework_default_is_the_final_fallback(): void {
			Filters\expectApplied( 'woodev_pickup_accent_color' )->andReturn( 'also-not-a-colour' );
			$this->stub_config_dependencies_except_filters();

			// The PLUGIN's own default is itself malformed here (a plugin bug) — proves the
			// fallback chain does not stop at a value that never made it to CSS anyway.
			$handler = $this->make_handler( [ 'accent_color' => 'not-a-colour-either' ] );

			$this->assertSame( '#06aedd', $handler->get_js_config()['accentColor'] );
		}

		/**
		 * `resolve_accent_color()` lower-cases the resolved value regardless of which of
		 * the three sources produced it, so this pins the merchant-setting branch too, not
		 * only the plugin-default branch {@see self::test_the_plugin_default_accent_reaches_the_browser_config()}
		 * already covers.
		 */
		public function test_a_merchant_setting_is_lower_cased_too(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			$this->stub_config_dependencies_except_filters();

			$handler = $this->make_handler( [ 'accent_color' => '#06aedd', 'setting_accent' => '#1937FF' ] );

			$this->assertSame( '#1937ff', $handler->get_js_config()['accentColor'] );
		}

		// -------------------------------------------------------------------------
		// accentFillColor / accentContrastColor (issue #203) — the surface under
		// contrast-coloured text, split from the brand-identity accent above.
		// Derivation: white on the accent unchanged if it already clears 4.5:1;
		// else darken 5%-30% (mirroring wc_hex_darker()) until white does; else
		// black on the undarkened accent. Computed server-side ONCE and shipped as
		// literal hex values — never mirrored in JS (see
		// Pickup_Handler::resolve_accent_fill_color()'s own docblock).
		// -------------------------------------------------------------------------

		/**
		 * White already clears 4.5:1 on WordPress's own blue (5.17:1, issue #203's own
		 * measurement table) — no darkening needed, fill is the accent unchanged.
		 */
		public function test_white_direct_needs_no_darkening(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			$this->stub_config_dependencies_except_filters();

			$config = $this->make_handler( [ 'accent_color' => '#2271b1' ] )->get_js_config();

			$this->assertSame( '#2271b1', $config['accentFillColor'] );
			$this->assertSame( '#ffffff', $config['accentContrastColor'] );
		}

		/**
		 * CDEK's own green (`#0a8c37`) fails white directly (4.36:1, issue #203's own table)
		 * but clears it after only a SINGLE 5% darkening step (~4.76:1) — proves the loop
		 * tries intermediate steps, not just the 30% ceiling. Notably DIFFERENT from what the
		 * IDENTITY decision (`contrastFor()`, client-side, spec D-15) picks for the same raw
		 * colour (black — 4.82:1 beats white's 4.36:1 undarkened): the two algorithms answer
		 * different questions on purpose, and this is the case that proves it.
		 */
		public function test_white_after_a_single_darkening_step(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			$this->stub_config_dependencies_except_filters();

			$config = $this->make_handler( [ 'accent_color' => '#0a8c37' ] )->get_js_config();

			$this->assertSame( '#098534', $config['accentFillColor'] );
			$this->assertSame( '#ffffff', $config['accentContrastColor'] );
		}

		/**
		 * The exact case the operator noticed (issue #203): `#06aedd` needs the FULL 30%
		 * ceiling before white clears 4.5:1 (~4.93:1) — pins the boundary, not just an
		 * interior step. `make_handler()`'s own default accent is already `#06aedd`.
		 */
		public function test_white_after_darkening_to_the_full_thirty_percent_cap(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			$this->stub_config_dependencies_except_filters();

			$config = $this->make_handler()->get_js_config();

			$this->assertSame( '#047a9b', $config['accentFillColor'] );
			$this->assertSame( '#ffffff', $config['accentContrastColor'] );
		}

		/**
		 * `#ffeb3b` (issue #203's own light-colour example) still fails white even at the
		 * full 30% darkening ceiling (2.55:1) — falls back to black on the UNDARKENED accent
		 * (17.20:1), not the 30%-darkened "khaki" the issue explicitly rejects.
		 */
		public function test_black_fallback_on_the_undarkened_accent_for_a_light_colour(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			$this->stub_config_dependencies_except_filters();

			$config = $this->make_handler( [ 'accent_color' => '#ffeb3b' ] )->get_js_config();

			$this->assertSame( '#ffeb3b', $config['accentFillColor'] );
			$this->assertSame( '#000000', $config['accentContrastColor'] );
		}

		/**
		 * The fill/contrast derivation reads {@see Pickup_Handler::resolve_accent_color()}'s
		 * FINAL resolved value (merchant setting wins over the plugin default), not the raw
		 * constructor `accent_color` — a plugin defaulting to `#06aedd` but a merchant
		 * overriding to `#ffeb3b` must derive from `#ffeb3b`.
		 */
		public function test_fill_and_contrast_derive_from_the_resolved_accent_not_the_plugin_default(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			$this->stub_config_dependencies_except_filters();

			$config = $this->make_handler( [
				'accent_color'   => '#06aedd',
				'setting_accent' => '#ffeb3b',
			] )->get_js_config();

			$this->assertSame( '#ffeb3b', $config['accentFillColor'] );
			$this->assertSame( '#000000', $config['accentContrastColor'] );
		}

		/**
		 * A filter overrides the derived FILL — independently of contrast, which stays the
		 * DERIVED default for the (untouched) resolved accent.
		 */
		public function test_a_filter_overrides_the_fill_color(): void {
			Filters\expectApplied( 'woodev_pickup_accent_fill_color' )->andReturn( '#123456' );
			$this->stub_config_dependencies_except_filters();

			$config = $this->make_handler( [ 'accent_color' => '#06aedd' ] )->get_js_config();

			$this->assertSame( '#123456', $config['accentFillColor'] );
			$this->assertSame( '#ffffff', $config['accentContrastColor'] );
		}

		/**
		 * A filter overrides the derived CONTRAST — independently of fill, which stays the
		 * DERIVED default for the (untouched) resolved accent.
		 */
		public function test_a_filter_overrides_the_contrast_color(): void {
			Filters\expectApplied( 'woodev_pickup_accent_contrast_color' )->andReturn( '#123456' );
			$this->stub_config_dependencies_except_filters();

			$config = $this->make_handler( [ 'accent_color' => '#06aedd' ] )->get_js_config();

			$this->assertSame( '#123456', $config['accentContrastColor'] );
			$this->assertSame( '#047a9b', $config['accentFillColor'] );
		}

		/**
		 * Same discipline as the accent's own filter (spec D-15): the fill value is
		 * interpolated into CSS on the client, so a filter returning garbage falls back to
		 * the derived default instead of reaching `setProperty()` unsanitised.
		 */
		public function test_a_fill_filter_returning_garbage_is_sanitised_too(): void {
			Filters\expectApplied( 'woodev_pickup_accent_fill_color' )->andReturn( 'javascript:alert(1)' );
			$this->stub_config_dependencies_except_filters();

			$config = $this->make_handler( [ 'accent_color' => '#06aedd' ] )->get_js_config();

			$this->assertSame( '#047a9b', $config['accentFillColor'] );
		}

		/**
		 * Same discipline for the contrast filter.
		 */
		public function test_a_contrast_filter_returning_garbage_is_sanitised_too(): void {
			Filters\expectApplied( 'woodev_pickup_accent_contrast_color' )->andReturn( 'javascript:alert(1)' );
			$this->stub_config_dependencies_except_filters();

			$config = $this->make_handler( [ 'accent_color' => '#06aedd' ] )->get_js_config();

			$this->assertSame( '#ffffff', $config['accentContrastColor'] );
		}

		/**
		 * The `pickup_accent_color` field's `type` is the setting's underlying VALUE type
		 * (`Woodev_Setting::TYPE_STRING`) and its `controlType` is the UI widget
		 * (`Woodev_Control::TYPE_COLOR`) — two distinct keys, matching the established
		 * shape {@see \Woodev\Framework\Settings\Field_Schema::from_handler()} already uses
		 * for every other settings field in this codebase (`'type' => $setting->get_type()`,
		 * `'controlType' => $control->get_type()`). `default` carries the PLUGIN's own
		 * accent colour, not the framework's.
		 */
		/**
		 * Pins the WHOLE field descriptor, not just `type`/`controlType`/`default` — the
		 * Russian `name` and `description` and `required => false` were previously
		 * unpinned, so a mutation to any one of them (e.g. flipping `required` to `true`,
		 * or blanking `description`) would have survived the suite green.
		 */
		public function test_the_accent_is_offered_as_a_colour_setting_field(): void {
			require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-setting.php';
			require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-control.php';

			$fields = $this->make_handler( [ 'accent_color' => '#FCE000' ] )->get_settings_fields();

			$this->assertSame(
				[
					'name'        => 'Акцентный цвет карты',
					'type'        => \Woodev_Setting::TYPE_STRING,
					'controlType' => \Woodev_Control::TYPE_COLOR,
					'description' => 'Цвет кнопок, активных пунктов и кластеров на карте пунктов выдачи.',
					'default'     => '#FCE000',
					'required'    => false,
				],
				$fields['pickup_accent_color']
			);
		}

		// -------------------------------------------------------------------------
		public function test_config_contains_no_object_or_closure_anywhere(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$source = $this->source_returning( null );
			$config = ( new Pickup_Handler(
				'p',
				'carrier_pickup_point',
				$source,
				$this->yandex_provider(),
				$this->default_location()
			) )->get_js_config();

			$this->assertConfigHasNoObjectsOrClosures( $config );
		}

		/**
		 * A wrong nonce action would 403 every logged-in customer via `X-WP-Nonce`
		 * regardless of the pickup routes being public (WordPress verifies the nonce
		 * against the CURRENT user before the route's own `permission_callback` runs).
		 */
		public function test_config_nonce_uses_the_wp_rest_action(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			Functions\expect( 'wp_create_nonce' )->once()->with( 'wp_rest' )->andReturn( 'NONCE' );

			( new Pickup_Handler(
				'p',
				'carrier_pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location()
			) )->get_js_config();
		}

		// -------------------------------------------------------------------------
		// get_js_config() — the nine map-provider i18n keys (Tasks 13/14 consumers)
		// -------------------------------------------------------------------------

		/**
		 * The map provider scripts (Tasks 13/14, out of this task's scope) read these nine
		 * keys by NAME and render blank — not an error — when one is missing, so a typo
		 * here is a silent UI hole nothing else would catch. Assert every key is present
		 * AND non-empty; the exact-set list also fails loudly the instant one is renamed.
		 */
		public function test_config_i18n_carries_all_map_provider_keys_non_empty(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$i18n = ( new Pickup_Handler(
				'p',
				'carrier_pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location()
			) )->get_js_config()['i18n'];

			// Exact expected VALUES, not just key presence — two adjacent keys (e.g.
			// `phone`/`workTime`) swapping their Russian text would leave every key present
			// and non-empty, so a presence-only check cannot catch it. Pinning the exact
			// string per key is what does.
			$expected = [
				'drawerTitle'    => 'Пункты выдачи в этой области',
				'howToGet'       => 'Как добраться',
				'paymentMethods' => 'Способы оплаты',
				'workTime'       => 'Часы работы',
				'phone'          => 'Телефон',
				'maxWeight'      => 'Максимальный вес',
				'allTypes'       => 'Все типы пунктов',
				'detailsError'   => 'Не удалось загрузить подробности о пункте выдачи.'
					. ' Вы всё ещё можете его выбрать.',
			];

			foreach ( $expected as $key => $value ) {
				$this->assertArrayHasKey(
					$key,
					$i18n,
					"i18n is missing the \"{$key}\" key the map provider reads by name"
				);
				$this->assertSame(
					$value,
					$i18n[ $key ],
					"i18n[\"{$key}\"] must be the exact expected Russian string, not a swapped/blank one"
				);
			}
		}

		/**
		 * The panels (Tasks 12-15) read twelve i18n keys by name (Task 8) and the checkout
		 * trigger reads a thirteenth, `triggerChange` (Task 8B) — none of these were pinned
		 * anywhere: the existing i18n test above only covers the ORIGINAL nine map-provider
		 * keys, so a typo in any of these thirteen would ship a BLANK label to the customer
		 * with the full suite still green. This asserts the COMPLETE i18n map — every key,
		 * exact value, exact key SET (`assertSame` on `array_keys`, not just presence) — so
		 * a missing, extra, or renamed key fails loudly, and pins the full non-Russian-
		 * fallback contract in one place rather than leaving the newest keys as the only
		 * unpinned ones.
		 *
		 * `zoomInLabel`/`zoomOutLabel` (Task 14, spec V-13) were added later, for the zoom
		 * control's two `aria-label`s — kept in this one COMPLETE assertion rather than a second
		 * one-off test, for the same reason the rest of this docblock gives.
		 *
		 * `address` (Task 15, spec V-12) was added later still: the point card's sectioned body
		 * gained an "Адрес" section title distinct from `yourAddress` (the search field's label).
		 */
		public function test_config_i18n_carries_every_key_with_its_exact_value(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$i18n = $this->make_handler()->get_js_config()['i18n'];

			$expected = [
				'modalTitle'       => 'Выберите пункт выдачи',
				'close'            => 'Закрыть',
				'select'           => 'Выбрать этот пункт',
				'loading'          => 'Загрузка пунктов выдачи…',
				'error'            => 'Не удалось загрузить пункты выдачи. Попробуйте ещё раз.',
				'noResults'        => 'Поиск не дал результатов.',
				'blocked'          => 'Этот пункт выдачи недоступен для вашего заказа.',
				'trigger'          => 'Выбрать пункт выдачи',
				'retry'            => 'Повторить',
				'upstreamError'    => 'Сервис пунктов выдачи временно недоступен. Попробуйте ещё раз позже.',
				'rateLimited'      => 'Слишком много запросов. Подождите немного и попробуйте снова.',
				'notFound'         => 'Этот пункт выдачи больше не найден. Пожалуйста, выберите другой.',
				'drawerTitle'      => 'Пункты выдачи в этой области',
				// #168: the sidebar toggle's second name — `drawerTitle` names it while the
				// drawer is closed, this one while it is open (and it is the visible text of
				// the mobile open-list bar, the one state that renders a label at all).
				'showMap'          => 'Показать карту',
				'howToGet'         => 'Как добраться',
				'paymentMethods'   => 'Способы оплаты',
				'workTime'         => 'Часы работы',
				'phone'            => 'Телефон',
				'maxWeight'        => 'Максимальный вес',
				'allTypes'         => 'Все типы пунктов',
				'detailsError'     => 'Не удалось загрузить подробности о пункте выдачи.'
					. ' Вы всё ещё можете его выбрать.',
				// The twelve Task 8 panel keys.
				// The Task 15 (spec V-12) card-section key.
				'address'          => 'Адрес',
				'services'         => 'Услуги',
				'yourAddress'      => 'Ваш адрес',
				'nearestTo'        => 'Ближайшие к «%s»',
				'resetSearch'      => 'Сбросить',
				'nothingNearby'    => 'Рядом с этим адресом пунктов выдачи нет.',
				'showNearest'      => 'Показать ближайший',
				'continueCheckout' => 'Продолжить оформление заказа',
				'zoomIn'           => 'Приблизьте карту, чтобы увидеть пункты выдачи',
				'sectionPoints'    => 'Пункты выдачи',
				'sectionAddresses' => 'Адреса',
				'filterTypes'      => 'Тип пунктов',
				'emptyInView'      => 'В этой области пунктов выдачи нет',
				// The Task 17 (spec V-5) key: a genuinely empty LOCALITY, distinct from
				// `emptyInView` (a viewport statement) and `noResults` (search found nothing).
				'emptyLocality'    => 'В выбранном населённом пункте нет пунктов выдачи',
				// The Task 8B trigger-state key.
				'triggerChange'    => 'Выбрать другой пункт выдачи',
				// The chosen-address block's label (issue #274 item 2).
				'chosenPointAddress' => 'Выбранный пункт выдачи:',
				// The two triggers' distinguishing aria-label context (issue #308 item 4) --
				// never shown to a sighted customer, see pickup-mount.js's own
				// `placementAriaContext()`.
				'triggerReviewContext' => 'в сводке заказа',
				'triggerRateContext'   => 'у выбранного способа доставки',
				// The Task 14 (spec V-13) zoom control keys.
				'zoomInLabel'      => 'Приблизить карту',
				'zoomOutLabel'     => 'Отдалить карту',
				// The Task 4 confirmation keys — the server round-trip's three states.
				// `selectFailed` is deliberately not `error`: that one describes a failed
				// points FETCH, not a refused confirmation.
				'confirming'       => 'Проверяем…',
				// Issue #223: a SEPARATE CTA state from `confirming` above. That one is shown
				// while a confirmation is already travelling to the server; this one while the
				// viewport strategy's lazy detail fetch (#219) is still deciding whether the
				// sparse listing's permissive-by-omission verdict even holds. Two different
				// questions, two independently released locks -- see `setVerdictPending()`.
				'checkingAvailability' => 'Проверяем доступность…',
				'selectFailed'     => 'Не удалось подтвердить выбор. Попробуйте ещё раз.',
				// #297: the `ownsChrome` counterpart — never promises a repeat of an action the
				// carrier's own widget (not the framework) controls. See class-pickup-handler.php.
				'selectFailedEmbedded' => 'Не удалось подтвердить выбор. Выберите пункт ещё раз.',
				'stalePage'        => 'Страница устарела. Обновите её и выберите пункт выдачи заново.',
			];

			$this->assertSame(
				array_keys( $expected ),
				array_keys( $i18n ),
				'the i18n key SET must match exactly -- a missing or extra key must fail loudly'
			);

			foreach ( $expected as $key => $value ) {
				$this->assertSame(
					$value,
					$i18n[ $key ],
					"i18n[\"{$key}\"] must be the exact expected Russian string, not a swapped/blank one"
				);
			}
		}

		/**
		 * Task 17 (spec V-5): the whole i18n map passes through a filter before it is returned,
		 * so a plugin can reword ANY key -- not just override a generic set -- because an empty
		 * result is domain language (Russian Post has no pickup points, it has post offices),
		 * not framework language. Rather than a second, parallel `messages` array beside this
		 * one, the existing map IS the override surface: one string system, not two.
		 */
		public function test_i18n_passes_through_a_filter_so_a_plugin_can_reword_it(): void {
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			Functions\when( 'apply_filters' )->alias(
				static function ( string $hook, $value, $plugin_id = null ) {
					if ( 'woodev_pickup_map_i18n' !== $hook ) {
						return $value;
					}

					$value['emptyLocality'] = 'В данном населённом пункте нет отделений Почты России';

					return $value;
				}
			);

			$config = $this->make_handler()->get_js_config();

			$this->assertSame(
				'В данном населённом пункте нет отделений Почты России',
				$config['i18n']['emptyLocality']
			);
		}

		/**
		 * The framework's own default for the newly-added key -- distinct from `emptyInView`
		 * (a viewport-strategy "none in this view" statement) and from `noResults` (search found
		 * nothing): "there are no points in the locality you asked for" is neither of those.
		 */
		public function test_i18n_ships_a_default_empty_locality_string(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$config = $this->make_handler()->get_js_config();

			$this->assertArrayHasKey( 'emptyLocality', $config['i18n'] );
			$this->assertNotSame( '', $config['i18n']['emptyLocality'] );
		}

		// -------------------------------------------------------------------------
		// get_settings_fields() (SP-5 Task 16, merged with `pickup_accent_color` as of
		// Task 8B / D-15) — the provider's own fields, plus the framework's accent field
		// -------------------------------------------------------------------------

		/**
		 * Coordination proof with the REAL Yandex_Map_Provider (not the test double, which
		 * hardcodes `[]`): proves the handler passes the PROVIDER's descriptor through
		 * UNCHANGED — comparing against the same live `$provider` instance's own return
		 * value means both sides move together, so this test cannot see a reshape that
		 * mutates the provider's own field content (e.g. dropping `description`, flipping
		 * `required`, or rebuilding the field in the WooCommerce `form_fields` shape) —
		 * such a mutation would corrupt both sides identically and still compare equal.
		 * That content is genuinely pinned, just not here: see
		 * `tests/unit/Shipping/Map/MapProviderRegistryTest.php` for the assertions against
		 * the descriptor's actual shape and values. The handler's OWN merged-in
		 * `pickup_accent_color` field is asserted separately below, and by
		 * {@see self::test_the_accent_is_offered_as_a_colour_setting_field()}.
		 */
		public function test_get_settings_fields_passes_the_yandex_providers_descriptor_through_unmodified(): void {
			require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-setting.php';
			require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-control.php';
			require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/map/class-yandex-map-provider.php';

			Functions\when( 'apply_filters' )->returnArg( 2 );

			$provider = new \Woodev\Framework\Shipping\Map\Yandex_Map_Provider( 'FALLBACK-KEY' );
			$handler  = new Pickup_Handler(
				'p',
				'carrier_pickup_point',
				$this->source_returning( null ),
				$provider,
				$this->default_location()
			);

			$fields = $handler->get_settings_fields();

			$this->assertArrayHasKey( 'map_api_key', $fields );
			$this->assertArrayHasKey( 'pickup_accent_color', $fields );
			$this->assertSame(
				$provider->get_settings_fields(),
				array_diff_key( $fields, [ 'pickup_accent_color' => null ] ),
				"the provider's own fields must pass through unmodified alongside the framework's field"
			);
		}

		/**
		 * A plugin using the embedded provider gains no PROVIDER field at all, but still
		 * gets the framework's own `pickup_accent_color` — the handler must not invent a
		 * provider field of its own, but the accent field is framework-owned, not
		 * provider-owned, so it is never conditional on which provider is active.
		 */
		public function test_get_settings_fields_has_only_the_accent_field_for_the_embedded_provider(): void {
			require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-setting.php';
			require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-control.php';
			require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/map/class-embedded-map-provider.php';

			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'untrailingslashit' )->alias( static fn( string $value ) => rtrim( $value, '/' ) );

			$provider = new \Woodev\Framework\Shipping\Map\Embedded_Map_Provider(
				'https://carrier.example/widget',
				'https://carrier.example'
			);
			$handler = new Pickup_Handler(
				'p',
				'carrier_pickup_point',
				$this->source_returning( null ),
				$provider,
				$this->default_location()
			);

			$fields = $handler->get_settings_fields();

			$this->assertSame( [ 'pickup_accent_color' ], array_keys( $fields ) );
			$this->assertArrayNotHasKey( 'map_api_key', $fields );
		}

		/**
		 * Collision guard: a (misbehaving) provider that names one of ITS OWN fields
		 * `pickup_accent_color` must never win — the framework's own field is merged in
		 * LAST and always wins, so a provider accidentally reusing this key can never
		 * silently shadow the framework's accent setting.
		 */
		public function test_a_provider_field_cannot_shadow_the_frameworks_accent_field(): void {
			require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-setting.php';
			require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-control.php';

			Functions\when( 'apply_filters' )->returnArg( 2 );

			$provider = new Pickup_Handler_Test_Map_Provider( 'yandex' );
			$provider->settings_fields_to_return = [
				'pickup_accent_color' => [ 'type' => 'string', 'default' => '#deadbf' ],
			];

			$handler = $this->make_handler( [ 'map_provider' => $provider, 'accent_color' => '#FCE000' ] );

			$this->assertSame( '#FCE000', $handler->get_settings_fields()['pickup_accent_color']['default'] );
		}

		// -------------------------------------------------------------------------
		// replaceAddress toggle (Task 8, issue #362, design S7) — a STORE setting
		// (`Pickup_Map_Settings::current()->get_value( 'pickup_replace_address' )`), no
		// longer a constructor argument (removed, clean-break v2 line, ADR-005)
		// -------------------------------------------------------------------------

		/**
		 * Default-on proof: a store that never touched the «Карта» → «Заполнять адрес…»
		 * setting — every existing installation, since the option row does not exist yet —
		 * must keep getting `enabled: true`. {@see Pickup_Map_Settings}'s own
		 * `pickup_replace_address` default is `true`.
		 */
		public function test_replace_address_defaults_to_enabled(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$handler = new Pickup_Handler(
				'p',
				'carrier_pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location()
			);

			$this->assertTrue( $handler->get_js_config()['replaceAddress']['enabled'] );
		}

		/**
		 * The store's `pickup_replace_address` setting is read at `get_js_config()` time via
		 * {@see Pickup_Map_Settings::current()} — `Shipping_Settings_Tab::reset_for_tests()`
		 * runs AFTER aliasing `get_option`, so the lazily-cached `Pickup_Map_Settings`
		 * instance is rebuilt against the new option value (gotcha
		 * `woodev-setting-get-value-is-cached-not-a-live-option-read`), not the one `setUp()`
		 * already built against the default `get_option` stub.
		 */
		public function test_replace_address_disabled_by_the_store_setting(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );
			Functions\when( 'get_option' )->alias(
				fn( $k, $d = false ) => 'woodev_pickup_map_pickup_replace_address' === $k ? 'no' : $d
			);
			Shipping_Settings_Tab::reset_for_tests();

			$handler = new Pickup_Handler(
				'p',
				'carrier_pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location()
			);

			$this->assertFalse( $handler->get_js_config()['replaceAddress']['enabled'] );
		}

		/**
		 * `billingOnly` must keep mirroring the store's `wc_ship_to_billing_address_only()`
		 * setting regardless of whether replacement itself is on or off, and `target` must
		 * never appear — a mutant that ties the two flags together, or that resurrects a
		 * resolved `target` key, must fail this.
		 */
		public function test_replace_address_billing_only_still_mirrors_the_store_setting_when_disabled(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( true );
			Functions\when( 'get_option' )->alias(
				fn( $k, $d = false ) => 'woodev_pickup_map_pickup_replace_address' === $k ? 'no' : $d
			);
			Shipping_Settings_Tab::reset_for_tests();

			$handler = new Pickup_Handler(
				'p',
				'carrier_pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location()
			);

			$config = $handler->get_js_config();

			$this->assertSame( [ 'enabled' => false, 'billingOnly' => true ], $config['replaceAddress'] );
			$this->assertArrayNotHasKey( 'target', $config['replaceAddress'] );
		}

		/**
		 * Existing callers wiring full-point persistence (`$order_handler` +
		 * `$point_field_logical`) must keep getting the default `enabled: true` — proves
		 * `pickup_replace_address` being a store setting now did not silently couple it to
		 * whether full-point persistence happens to be wired.
		 */
		public function test_replace_address_still_defaults_to_enabled_when_persistence_is_wired(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$order_handler = new Shipping_Order_Handler( [ 'pickup_full' => 'cdek_full_point' ] );
			$handler       = new Pickup_Handler(
				'p',
				'carrier_pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				$order_handler,
				'pickup_full'
			);

			$this->assertTrue( $handler->get_js_config()['replaceAddress']['enabled'] );
		}

		// -------------------------------------------------------------------------
		// validate_selected_point() — baseline (pure constraint check)
		// -------------------------------------------------------------------------

		public function test_a_blocked_point_fails_the_server_recheck(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( '__' )->returnArg( 1 );
			Functions\when( 'number_format_i18n' )->returnArg( 1 );
			Functions\when( 'wc_add_notice' )->justReturn( true );

			$point  = $this->point( [ 'accepts_cod' => false ] );
			$source = $this->source_returning( $point );

			$handler = new Pickup_Handler(
				'p',
				'carrier_pickup_point',
				$source,
				$this->yandex_provider(),
				$this->default_location()
			);

			$this->assertFalse( $handler->validate_selected_point( $point, 'cod', 0 ) );
			$this->assertTrue( $handler->validate_selected_point( $point, 'bacs', 0 ) );
		}

		public function test_validate_selected_point_adds_the_blocked_reason_as_a_wc_notice(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( '__' )->returnArg( 1 );
			Functions\when( 'number_format_i18n' )->returnArg( 1 );

			$captured = [];
			Functions\when( 'wc_add_notice' )->alias(
				static function ( $message, $type ) use ( &$captured ) {
					$captured[] = [ $message, $type ];
				}
			);

			$point   = $this->point( [ 'accepts_cod' => false ] );
			$handler = new Pickup_Handler(
				'p',
				'f',
				$this->source_returning( $point ),
				$this->yandex_provider(),
				$this->default_location()
			);

			$handler->validate_selected_point( $point, 'cod', 0 );

			$this->assertCount( 1, $captured );
			$this->assertSame( 'error', $captured[0][1] );
			// Pins that the ACTUAL Constraint_Checker reason is forwarded, not a mutant that
			// always substitutes the generic default_blocked_message() fallback instead.
			$this->assertSame(
				'В этом пункте выдачи недоступна оплата при получении.'
				. ' Выберите другой пункт или другой способ оплаты.',
				$captured[0][0]
			);
		}

		public function test_validate_selected_point_adds_no_notice_when_allowed(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );

			Functions\expect( 'wc_add_notice' )->never();

			$point   = $this->point();
			$handler = new Pickup_Handler(
				'p',
				'f',
				$this->source_returning( $point ),
				$this->yandex_provider(),
				$this->default_location()
			);

			$this->assertTrue( $handler->validate_selected_point( $point, 'bacs', 0 ) );
		}

		// -------------------------------------------------------------------------
		// validate_posted_point() — the three outcomes, including a non-Woodev_API_Exception
		// -------------------------------------------------------------------------

		public function test_validate_posted_point_returns_true_when_the_fetched_point_is_allowed(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );

			$point   = $this->point();
			$handler = new Pickup_Handler(
				'p',
				'f',
				$this->source_returning( $point ),
				$this->yandex_provider(),
				$this->default_location()
			);

			$this->assertTrue( $handler->validate_posted_point( 'P1', 'bacs', 0 ) );
		}

		public function test_validate_posted_point_returns_false_when_the_fetched_point_is_blocked(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( '__' )->returnArg( 1 );
			Functions\when( 'number_format_i18n' )->returnArg( 1 );
			Functions\when( 'wc_add_notice' )->justReturn( true );

			$point   = $this->point( [ 'accepts_cod' => false ] );
			$handler = new Pickup_Handler(
				'p',
				'f',
				$this->source_returning( $point ),
				$this->yandex_provider(),
				$this->default_location()
			);

			$this->assertFalse( $handler->validate_posted_point( 'P1', 'cod', 0 ) );
		}

		public function test_validate_posted_point_blocks_with_a_choose_again_message_for_an_unknown_point(): void {
			Functions\when( '__' )->returnArg( 1 );

			$captured = [];
			Functions\when( 'wc_add_notice' )->alias(
				static function ( $message, $type ) use ( &$captured ) {
					$captured[] = [ $message, $type ];
				}
			);

			$handler = new Pickup_Handler(
				'p',
				'f',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location()
			);

			$this->assertFalse( $handler->validate_posted_point( 'unknown', 'bacs', 0 ) );
			$this->assertCount( 1, $captured );
			$this->assertSame(
				'Выбранный пункт выдачи больше недоступен. Пожалуйста, выберите пункт выдачи заново.',
				$captured[0][0]
			);
			$this->assertSame( 'error', $captured[0][1] );
		}

		public function test_validate_posted_point_allows_checkout_by_default_on_a_carrier_outage(): void {
			Functions\expect( 'wc_add_notice' )->never();

			$probe = new Pickup_Handler_Probe(
				'p',
				'f',
				$this->source_throwing( new \Woodev_API_Exception( 'carrier down' ) ),
				$this->yandex_provider(),
				$this->default_location()
			);

			// No apply_filters stub attached at all — simulates the real "no merchant
			// filter registered" case, so apply_filters() must return its own $default (true).
			Functions\when( 'apply_filters' )->returnArg( 2 );

			$this->assertTrue( $probe->validate_posted_point( 'P1', 'bacs', 0 ) );
			$this->assertCount( 1, $probe->logged );
			$this->assertSame( 'checkout re-check', $probe->logged[0]['context'] );
		}

		/**
		 * BLOCKING fix proof: a plugin seam calling a live carrier SDK can throw ANYTHING
		 * — a `\TypeError`, a transport-library exception, the plugin's own exception
		 * type — not only `\Woodev_API_Exception`. The catch must be `\Throwable`, and
		 * this outage must be handled exactly like a carrier outage: allow by default.
		 */
		public function test_validate_posted_point_allows_checkout_by_default_on_a_non_carrier_throwable(): void {
			Functions\expect( 'wc_add_notice' )->never();

			$probe = new Pickup_Handler_Probe(
				'p',
				'f',
				$this->source_throwing( new \RuntimeException( 'the carrier SDK blew up' ) ),
				$this->yandex_provider(),
				$this->default_location()
			);

			Functions\when( 'apply_filters' )->returnArg( 2 );

			$this->assertTrue( $probe->validate_posted_point( 'P1', 'bacs', 0 ) );
			$this->assertCount( 1, $probe->logged );
			$this->assertSame( 'checkout re-check', $probe->logged[0]['context'] );
		}

		/**
		 * Explicit value-mutant guard: pins that the DEFAULT argument passed to
		 * apply_filters() for the outage tag is `true`, not `false` — kills a mutant that
		 * flips the outage default from allow to block.
		 */
		public function test_the_outage_filter_default_argument_is_true_not_false(): void {
			$captured = [];
			Functions\when( 'apply_filters' )->alias(
				static function ( $tag, $default = null, ...$args ) use ( &$captured ) {
					$captured[] = [ $tag, $default, $args ];

					return $default;
				}
			);

			$probe = new Pickup_Handler_Probe(
				'p',
				'f',
				$this->source_throwing( new \Woodev_API_Exception( 'carrier down' ) ),
				$this->yandex_provider(),
				$this->default_location()
			);

			$probe->validate_posted_point( 'P1', 'bacs', 0 );

			$this->assertCount( 1, $captured );
			[ $tag, $default, $args ] = $captured[0];
			$this->assertSame( 'woodev_shipping_pickup_recheck_outage_allows_checkout', $tag );
			$this->assertTrue( $default, 'the outage default must be true (allow), not false' );
			$this->assertSame( [ 'p', 'P1' ], [ $args[1], $args[2] ], 'plugin_id and point_id must reach the filter' );
		}

		public function test_validate_posted_point_blocks_on_outage_when_the_filter_overrides_the_default(): void {
			Functions\when( '__' )->returnArg( 1 );
			Functions\when( 'apply_filters' )->justReturn( false );

			$captured = [];
			Functions\when( 'wc_add_notice' )->alias(
				static function ( $message, $type ) use ( &$captured ) {
					$captured[] = [ $message, $type ];
				}
			);

			$probe = new Pickup_Handler_Probe(
				'p',
				'f',
				$this->source_throwing( new \Woodev_API_Exception( 'carrier down' ) ),
				$this->yandex_provider(),
				$this->default_location()
			);

			$this->assertFalse( $probe->validate_posted_point( 'P1', 'bacs', 0 ) );
			$this->assertCount( 1, $captured );
			$this->assertSame( 'error', $captured[0][1] );
		}

		/**
		 * The same filter-override-blocks behaviour must hold for a non-carrier
		 * `\Throwable` too — the filter does not discriminate on exception type.
		 */
		public function test_validate_posted_point_blocks_on_a_non_carrier_throwable_when_filter_overrides(): void {
			Functions\when( '__' )->returnArg( 1 );
			Functions\when( 'apply_filters' )->justReturn( false );

			$captured = [];
			Functions\when( 'wc_add_notice' )->alias(
				static function ( $message, $type ) use ( &$captured ) {
					$captured[] = [ $message, $type ];
				}
			);

			$probe = new Pickup_Handler_Probe(
				'p',
				'f',
				$this->source_throwing( new \TypeError( 'unexpected argument shape' ) ),
				$this->yandex_provider(),
				$this->default_location()
			);

			$this->assertFalse( $probe->validate_posted_point( 'P1', 'bacs', 0 ) );
			$this->assertCount( 1, $captured );
			$this->assertSame( 'error', $captured[0][1] );
		}

		public function test_validate_posted_point_never_leaks_the_carrier_message_to_the_customer(): void {
			Functions\when( '__' )->returnArg( 1 );
			Functions\when( 'apply_filters' )->justReturn( false );

			$captured = [];
			Functions\when( 'wc_add_notice' )->alias(
				static function ( $message, $type ) use ( &$captured ) {
					$captured[] = $message;
				}
			);

			$probe = new Pickup_Handler_Probe(
				'p',
				'f',
				$this->source_throwing( new \Woodev_API_Exception( 'https://carrier.example/secret?token=abc' ) ),
				$this->yandex_provider(),
				$this->default_location()
			);

			$probe->validate_posted_point( 'P1', 'bacs', 0 );

			$this->assertStringNotContainsString( 'carrier.example', $captured[0] );
			$this->assertStringContainsString( 'carrier.example', $probe->logged[0]['message'] );
		}

		// -------------------------------------------------------------------------
		// log_carrier_failure() — tells a carrier outage apart from an unexpected error
		// -------------------------------------------------------------------------

		/**
		 * Exercises the REAL log_carrier_failure() (not the logging-spy probe, which
		 * bypasses its own message formatting) to pin the "carrier outage" label for a
		 * genuine `\Woodev_API_Exception`.
		 */
		public function test_log_carrier_failure_labels_a_carrier_exception_as_a_carrier_outage(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );

			Functions\expect( 'error_log' )
				->once()
				->with( \Mockery::on( static fn( $message ) => false !== strpos( $message, 'carrier outage' ) ) );

			$handler = new Pickup_Handler(
				'p',
				'f',
				$this->source_throwing( new \Woodev_API_Exception( 'down' ) ),
				$this->yandex_provider(),
				$this->default_location()
			);
			$handler->validate_posted_point( 'P1', 'bacs', 0 );
		}

		/**
		 * The same real path, for a non-carrier `\Throwable`, must be labelled
		 * differently — a merchant reading the log needs to tell "blame the carrier" apart
		 * from "file a plugin bug".
		 */
		public function test_log_carrier_failure_labels_a_non_carrier_throwable_as_an_unexpected_error(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );

			Functions\expect( 'error_log' )
				->once()
				->with( \Mockery::on( static fn( $message ) => false !== strpos( $message, 'unexpected error' ) ) );

			$handler = new Pickup_Handler(
				'p',
				'f',
				$this->source_throwing( new \RuntimeException( 'boom' ) ),
				$this->yandex_provider(),
				$this->default_location()
			);
			$handler->validate_posted_point( 'P1', 'bacs', 0 );
		}

		// -------------------------------------------------------------------------
		// fetch memoization — only one carrier call per point id, per instance
		// -------------------------------------------------------------------------

		public function test_fetch_details_is_memoized_across_both_hooks_for_the_same_point_id(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'update_post_meta' )->justReturn( true );

			$point         = $this->point();
			$source        = $this->source_returning( $point );
			$order_handler = new Shipping_Order_Handler( [ 'pickup_full' => 'cdek_full_point' ] );

			$handler = new Pickup_Handler(
				'p',
				'pickup_point',
				$source,
				$this->yandex_provider(),
				$this->default_location(),
				$order_handler,
				'pickup_full'
			);
			$_POST   = [ 'pickup_point' => 'P1', 'payment_method' => 'bacs' ];

			$handler->validate_posted_point( 'P1', 'bacs', 0 );
			$handler->handle_checkout_order_processed( 1, [], new \WC_Order() );

			$this->assertSame(
				1,
				$source->fetch_details_calls,
				'the same point id must be fetched from the carrier only once per request'
			);
		}

		/**
		 * A repeat lookup for the SAME id, after the FIRST one threw, must re-throw the
		 * SAME failure rather than calling the carrier again.
		 */
		public function test_a_repeat_fetch_failure_for_the_same_id_does_not_call_the_carrier_again(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'error_log' )->justReturn( true );

			$source  = $this->source_throwing( new \Woodev_API_Exception( 'down' ) );
			$handler = new Pickup_Handler(
				'p',
				'pickup_point',
				$source,
				$this->yandex_provider(),
				$this->default_location()
			);

			$handler->validate_posted_point( 'P1', 'bacs', 0 );
			$handler->validate_posted_point( 'P1', 'bacs', 0 );

			$this->assertSame( 1, $source->fetch_details_calls );
		}

		/**
		 * Value-mutant guard: the memoization cache is keyed by point id, not a single
		 * "has anything been fetched yet" flag — two DIFFERENT ids must each reach the
		 * carrier once.
		 */
		public function test_fetch_details_is_called_once_per_distinct_point_id(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );

			$source  = new Pickup_Handler_Test_Source(
				Point_Source::STRATEGY_BULK,
				fn( string $id ) => $this->point( [ 'id' => $id ] )
			);
			$handler = new Pickup_Handler(
				'p',
				'pickup_point',
				$source,
				$this->yandex_provider(),
				$this->default_location()
			);

			$handler->validate_posted_point( 'P1', 'bacs', 0 );
			$handler->validate_posted_point( 'P2', 'bacs', 0 );

			$this->assertSame( 2, $source->fetch_details_calls, 'two DIFFERENT point ids must each be fetched' );
		}

		// -------------------------------------------------------------------------
		// handle_checkout_process() — the woocommerce_checkout_process hook
		// -------------------------------------------------------------------------

		public function test_handle_checkout_process_does_nothing_when_no_point_was_posted(): void {
			$source = $this->source_returning( $this->point() );
			$_POST  = [ 'payment_method' => 'bacs' ];

			$handler = new Pickup_Handler(
				'p',
				'pickup_point',
				$source,
				$this->yandex_provider(),
				$this->default_location()
			);
			$handler->handle_checkout_process();

			$this->assertSame( 0, $source->fetch_details_calls, 'a blank posted field must never trigger a fetch' );
		}

		/**
		 * BLOCKING-related hardening: an array-valued posted field (a malformed or
		 * multi-value form field) must never reach the carrier as the literal string
		 * "Array".
		 */
		public function test_handle_checkout_process_ignores_an_array_valued_posted_field(): void {
			$source = $this->source_returning( $this->point() );
			$_POST  = [ 'pickup_point' => [ 'a', 'b' ], 'payment_method' => 'bacs' ];

			$handler = new Pickup_Handler(
				'p',
				'pickup_point',
				$source,
				$this->yandex_provider(),
				$this->default_location()
			);
			$handler->handle_checkout_process();

			$this->assertSame( 0, $source->fetch_details_calls, 'an array-valued field must never trigger a fetch' );
		}

		public function test_handle_checkout_process_uses_the_posted_payment_method(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( '__' )->returnArg( 1 );
			Functions\when( 'number_format_i18n' )->returnArg( 1 );

			$captured = [];
			Functions\when( 'wc_add_notice' )->alias(
				static function ( $message, $type ) use ( &$captured ) {
					$captured[] = $message;
				}
			);

			$point  = $this->point( [ 'accepts_cod' => false ] );
			$source = $this->source_returning( $point );
			$_POST  = [ 'pickup_point' => 'P1', 'payment_method' => 'cod' ];

			$handler = new Pickup_Handler_Weight_Probe(
				'p',
				'pickup_point',
				$source,
				$this->yandex_provider(),
				$this->default_location(),
				0
			);
			$handler->handle_checkout_process();

			$this->assertCount( 1, $captured, 'cod payment method must trigger the COD-blocked notice' );
		}

		public function test_handle_checkout_process_forwards_the_converted_cart_weight_when_over_the_limit(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( '__' )->returnArg( 1 );
			Functions\when( 'number_format_i18n' )->returnArg( 1 );

			$point  = $this->point( [ 'max_weight' => 2000 ] );
			$source = $this->source_returning( $point );
			$_POST  = [ 'pickup_point' => 'P1', 'payment_method' => 'bacs' ];

			$handler = new Pickup_Handler_Weight_Probe(
				'p',
				'pickup_point',
				$source,
				$this->yandex_provider(),
				$this->default_location(),
				2500
			);

			Functions\expect( 'wc_add_notice' )->once();
			$handler->handle_checkout_process();
		}

		public function test_handle_checkout_process_forwards_the_converted_cart_weight_when_within_the_limit(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );

			$point  = $this->point( [ 'max_weight' => 2000 ] );
			$source = $this->source_returning( $point );
			$_POST  = [ 'pickup_point' => 'P1', 'payment_method' => 'bacs' ];

			$handler = new Pickup_Handler_Weight_Probe(
				'p',
				'pickup_point',
				$source,
				$this->yandex_provider(),
				$this->default_location(),
				1500
			);

			Functions\expect( 'wc_add_notice' )->never();
			$handler->handle_checkout_process();
		}

		// -------------------------------------------------------------------------
		// handle_checkout_process() — checkout_payment_method() must never fall back
		// to the WC session (post-ac57dc2 review finding, MEDIUM): the checkout POST
		// is authoritative, so an ABSENT $_POST['payment_method'] must be treated as
		// "no payment method", never overridden by a stale `chosen_payment_method`
		// left in WC()->session by an earlier, abandoned choice. Contrast with the
		// rest_payment_method() tests below, which prove the OPPOSITE precedence is
		// correct for the REST points/detail routes.
		// -------------------------------------------------------------------------

		/**
		 * The MEDIUM finding this task fixes: a checkout that posts no payment method
		 * at all (e.g. an order needing no payment) must not have a stale session
		 * value silently substituted in. A COD-refusing point must therefore NOT be
		 * blocked here — '' is never in Constraint_Checker's COD method list, exactly
		 * restoring this class's pre-ac57dc2 behaviour on this call site.
		 *
		 * Mutation guard: if handle_checkout_process() were routed back to
		 * rest_payment_method() (or the two readers' bodies were swapped), the
		 * forced session value 'cod' would leak in, the COD-refusing point would be
		 * blocked, and wc_add_notice() would be called — failing the `never()`
		 * expectation below.
		 */
		public function test_handle_checkout_process_does_not_leak_a_stale_session_payment_method(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( '__' )->returnArg( 1 );
			Functions\when( 'number_format_i18n' )->returnArg( 1 );

			Functions\expect( 'wc_add_notice' )->never();

			$point  = $this->point( [ 'accepts_cod' => false ] );
			$source = $this->source_returning( $point );
			$_POST  = [ 'pickup_point' => 'P1' ];

			$handler = new Pickup_Handler_Checkout_Session_Probe(
				'p',
				'pickup_point',
				$source,
				$this->yandex_provider(),
				$this->default_location(),
				0,
				'cod'
			);
			$handler->handle_checkout_process();
		}

		/**
		 * A non-empty `$_POST['payment_method']` must still win on the checkout path
		 * even when a conflicting value sits in the session — proves
		 * checkout_payment_method() is POST-first, not merely POST-only-when-the-
		 * session-is-absent. Uses a COD-refusing point with a POSTED non-COD method
		 * ('bacs') while the session disagrees ('cod'): if the posted value did not
		 * win, nothing here would distinguish this test from the leak test above, so
		 * this asserts the ALLOWED outcome specifically.
		 */
		public function test_handle_checkout_process_posted_payment_method_wins_over_a_conflicting_session(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );

			Functions\expect( 'wc_add_notice' )->never();

			$point  = $this->point( [ 'accepts_cod' => false ] );
			$source = $this->source_returning( $point );
			$_POST  = [ 'pickup_point' => 'P1', 'payment_method' => 'bacs' ];

			$handler = new Pickup_Handler_Checkout_Session_Probe(
				'p',
				'pickup_point',
				$source,
				$this->yandex_provider(),
				$this->default_location(),
				0,
				'cod'
			);
			$handler->handle_checkout_process();
		}

		// -------------------------------------------------------------------------
		// rest_payment_method() — $_POST wins, the session fallback (via the
		// wc_session_chosen_payment_method() seam) is the GET-request fallback
		// (SP-5 rig e2e BLOCKING fix: the points/detail routes are GET, so $_POST
		// is always empty there — see the method's own docblock for why returning
		// '' unconditionally silently disabled the §4.5 COD gate). REST-ONLY: never
		// used by handle_checkout_process() — see the section above and
		// checkout_payment_method()'s own docblock. Exercised via
		// Pickup_Handler_Session_Probe, never via `Functions\when( 'WC' )` — see
		// that probe's own docblock for why mocking WC() itself is unsafe here.
		// -------------------------------------------------------------------------

		/**
		 * Precedence proof: a posted value must win even when the session disagrees
		 * with it — mirrors the real `woocommerce_checkout_process` call site, where
		 * `$_POST` is authoritative. Value-mutant guard: a mutant flipping the
		 * precedence (session wins) would return the probe's 'cod' instead.
		 */
		public function test_rest_payment_method_prefers_the_posted_value_over_the_session(): void {
			$_POST = [ 'payment_method' => 'bacs' ];

			$handler = new Pickup_Handler_Session_Probe(
				'p',
				'f',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				'cod'
			);

			$this->assertSame( 'bacs', $handler->rest_payment_method() );
		}

		/**
		 * The BLOCKING fix itself: a GET points/detail request never populates
		 * `$_POST`, so the session — written by WooCommerce's own
		 * `update_order_review` ajax handler the instant the customer picks a
		 * method — is the only server-side source of the live choice.
		 */
		public function test_rest_payment_method_falls_back_to_the_session_when_post_is_empty(): void {
			$_POST = [];

			$handler = new Pickup_Handler_Session_Probe(
				'p',
				'f',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				'cod'
			);

			$this->assertSame( 'cod', $handler->rest_payment_method() );
		}

		/**
		 * Value-mutant guard: proves the fallback returns the probe's ACTUAL
		 * value, not a hardcoded 'cod' literal — the previous test alone cannot
		 * distinguish a real forward from a mutant that always answers 'cod'.
		 */
		public function test_rest_payment_method_returns_the_actual_session_value_not_a_hardcoded_one(): void {
			$_POST = [];

			$handler = new Pickup_Handler_Session_Probe(
				'p',
				'f',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				'bacs'
			);

			$this->assertSame( 'bacs', $handler->rest_payment_method() );
		}

		/**
		 * No session value available (WC() unavailable, no session started, or no
		 * choice made yet — {@see Pickup_Handler::wc_session_chosen_payment_method()}
		 * returns `null` in all three cases) must degrade to permissive, never
		 * fatal. `''` is never in {@see Constraint_Checker}'s COD method list.
		 */
		public function test_rest_payment_method_is_empty_when_the_session_value_is_absent(): void {
			$_POST = [];

			$handler = new Pickup_Handler_Session_Probe(
				'p',
				'f',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				null
			);

			$this->assertSame( '', $handler->rest_payment_method() );
		}

		/**
		 * A non-scalar session value (defensive: the real `WC_Session::get()`
		 * should never return one, but nothing prevents a plugin from writing an
		 * array under this key) must degrade to permissive rather than crash on
		 * the `(string)` cast — same `is_scalar()` guard as `$_POST`.
		 */
		public function test_rest_payment_method_is_empty_for_a_non_scalar_session_value(): void {
			$_POST = [];

			$handler = new Pickup_Handler_Session_Probe(
				'p',
				'f',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				[ 'unexpected' => 'array' ]
			);

			$this->assertSame( '', $handler->rest_payment_method() );
		}

		/**
		 * `wc_session_chosen_payment_method()`'s OWN default body (not the probe) —
		 * proves the seam itself degrades to `null`, not a fatal, when WC()
		 * genuinely does not exist in this unit-test process. Mirrors the same
		 * truthful exercise
		 * {@see self::test_current_cart_weight_grams_defaults_to_zero_when_wc_is_unavailable()}
		 * relies on for the cart seam.
		 */
		public function test_wc_session_chosen_payment_method_is_null_when_wc_is_unavailable(): void {
			$handler = new Pickup_Handler_Probe(
				'p',
				'f',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location()
			);

			$this->assertNull( $handler->wc_session_chosen_payment_method_public() );
		}

		// -------------------------------------------------------------------------
		// rest_shipping_method() — the fifth callable Pickup_Controller is built
		// with, feeding the `.../select` route's domain seam. Session-only: the
		// selection request is a standalone POST from the modal, so the checkout
		// form's own shipping_method[0] is never part of it.
		// -------------------------------------------------------------------------

		/**
		 * The `:instance_id` suffix must be stripped — the rest of the framework
		 * (condition specs, `requires_pickup`, the JS store) speaks the BARE method
		 * id, so a domain seam handed `carrier_pickup:3` would fail every comparison
		 * made against `carrier_pickup`. A mutant returning the raw session value
		 * fails here, not on any of the tests below.
		 */
		public function test_rest_shipping_method_strips_the_instance_id_suffix(): void {
			$handler = new Pickup_Handler_Shipping_Session_Probe(
				'p',
				'f',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				[ 'carrier_pickup:3' ]
			);

			$this->assertSame( 'carrier_pickup', $handler->rest_shipping_method() );
		}

		/**
		 * Package 0 is the primary method, matching Checkout_Handler's own reading of
		 * the posted value — a mutant taking the last package instead answers
		 * 'flat_rate'.
		 */
		public function test_rest_shipping_method_reads_the_first_package(): void {
			$handler = new Pickup_Handler_Shipping_Session_Probe(
				'p',
				'f',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				[ 'carrier_pickup', 'flat_rate' ]
			);

			$this->assertSame( 'carrier_pickup', $handler->rest_shipping_method() );
		}

		/**
		 * No session, no packages, or a non-scalar entry (defensive — nothing stops a
		 * plugin writing junk under this key) must degrade to `''`, never fatal on the
		 * `(string)` cast. `''` is a method no plugin matches, so the domain seam simply
		 * cannot key off it.
		 *
		 * @dataProvider provide_unusable_shipping_session_values
		 *
		 * @param mixed $session_value what the seam returns.
		 */
		public function test_rest_shipping_method_is_empty_for_an_unusable_session_value( $session_value ): void {
			$handler = new Pickup_Handler_Shipping_Session_Probe(
				'p',
				'f',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				$session_value
			);

			$this->assertSame( '', $handler->rest_shipping_method() );
		}

		/**
		 * @return array<string, array{0: mixed}>
		 */
		public function provide_unusable_shipping_session_values(): array {
			return [
				'no session at all'   => [ null ],
				'not an array'        => [ 'carrier_pickup' ],
				'empty package list'  => [ [] ],
				'non-scalar package'  => [ [ [ 'unexpected' => 'array' ] ] ],
			];
		}

		/**
		 * `wc_session_chosen_shipping_methods()`'s OWN default body (not the probe) —
		 * proves the seam itself degrades to `null`, not a fatal, when WC() genuinely
		 * does not exist in this unit-test process. Same reasoning as
		 * {@see self::test_wc_session_chosen_payment_method_is_null_when_wc_is_unavailable()}.
		 */
		public function test_wc_session_chosen_shipping_methods_is_null_when_wc_is_unavailable(): void {
			$handler = new Pickup_Handler_Probe(
				'p',
				'f',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location()
			);

			$this->assertNull( $handler->wc_session_chosen_shipping_methods_public() );
		}

		// -------------------------------------------------------------------------
		// current_cart_weight_grams() — the grams conversion is now Constraint_Checker's;
		// see ConstraintCheckerTest for the wc_get_weight()-target-unit mutation guard.
		// SP-5 rig e2e BLOCKING fix: the points/detail REST routes never had a
		// WooCommerce-initialized cart to read from (see class docblock), so this now
		// also covers the wc_load_cart() fallback path. Exercised via
		// Pickup_Handler_Cart_Probe, never via `Functions\when( 'WC' )` — see that
		// probe's own docblock for why mocking WC() itself is unsafe here.
		// -------------------------------------------------------------------------

		public function test_current_cart_weight_grams_defaults_to_zero_when_wc_is_unavailable(): void {
			// WC() genuinely does not exist in this unit-test process — a truthful exercise
			// of the "cart not loaded" branch, not a simulation.
			$probe = new Pickup_Handler_Probe(
				'p',
				'f',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location()
			);

			$this->assertSame( 0, $probe->current_cart_weight_grams_public() );
		}

		/**
		 * `wc_cart()`'s OWN default body (not the probe) — proves the seam itself
		 * degrades to `null`, not a fatal, when WC() genuinely does not exist.
		 */
		public function test_wc_cart_is_null_when_wc_is_unavailable(): void {
			$handler = new Pickup_Handler_Probe(
				'p',
				'f',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location()
			);

			$this->assertNull( $handler->wc_cart_public() );
		}

		/**
		 * The checkout-process case: the cart is already loaded, so the value is
		 * read directly and `load_wc_cart()` must never even be consulted — proven
		 * by the call counter, not just the returned value.
		 */
		public function test_current_cart_weight_grams_reads_an_already_loaded_cart_without_loading_one(): void {
			Functions\when( 'wc_get_weight' )->alias( static fn( $weight, $to_unit ) => $weight * 1000 );

			$handler = new Pickup_Handler_Cart_Probe(
				'p',
				'f',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				new Pickup_Handler_Test_Cart( 1.5 )
			);

			$this->assertSame( 1500, $handler->current_cart_weight_grams() );
			$this->assertSame( 0, $handler->load_wc_cart_calls );
		}

		/**
		 * The BLOCKING fix itself: the cart starts absent (the real state on the
		 * points/detail REST routes, which WooCommerce never initializes a cart for
		 * — see the method's own docblock), but loading is available, so it must be
		 * attempted, and the weight read AFTER it runs — the carrier weight limit
		 * gate now actually fires on a GET map request instead of silently
		 * reporting 0. A dropped `load_wc_cart()` call site would leave the cart
		 * null and this assertion would see `0`, not `2500`.
		 */
		public function test_current_cart_weight_grams_loads_the_cart_when_absent_but_loadable(): void {
			Functions\when( 'wc_get_weight' )->alias( static fn( $weight, $to_unit ) => $weight * 1000 );

			$handler = new Pickup_Handler_Cart_Probe(
				'p',
				'f',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				null,
				true,
				new Pickup_Handler_Test_Cart( 2.5 )
			);

			$this->assertSame( 2500, $handler->current_cart_weight_grams() );
			$this->assertSame( 1, $handler->load_wc_cart_calls );
		}

		/**
		 * Neither branch helps: the cart is absent AND loading is unavailable (an
		 * older WC install, pre-3.6) — must degrade to the permissive `0` without
		 * ever attempting to load.
		 */
		public function test_current_cart_weight_grams_is_zero_when_the_cart_is_absent_and_not_loadable(): void {
			$handler = new Pickup_Handler_Cart_Probe(
				'p',
				'f',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				null,
				false
			);

			$this->assertSame( 0, $handler->current_cart_weight_grams() );
			$this->assertSame( 0, $handler->load_wc_cart_calls );
		}

		/**
		 * A cart that STILL cannot be loaded (load_wc_cart() ran but left the cart
		 * null — e.g. a corrupt session) must also degrade to `0` rather than fatal
		 * on a null `get_cart_contents_weight()` call, while proving the load WAS
		 * attempted (distinguishing this from the "not loadable at all" case above).
		 */
		public function test_current_cart_weight_grams_is_zero_when_the_cart_is_still_absent_after_loading(): void {
			$handler = new Pickup_Handler_Cart_Probe(
				'p',
				'f',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				null,
				true,
				null
			);

			$this->assertSame( 0, $handler->current_cart_weight_grams() );
			$this->assertSame( 1, $handler->load_wc_cart_calls );
		}

		// -------------------------------------------------------------------------
		// handle_checkout_order_processed() — delegated persistence via
		// Shipping_Order_Handler::store_pickup_point(), never a framework-coined key
		// -------------------------------------------------------------------------

		public function test_persists_the_full_point_as_the_canonical_array_not_the_browser_escaped_one(): void {
			// Real escaping (not the base TestCase's pass-through stub) so to_array() vs
			// to_browser_array() are actually distinguishable.
			Functions\when( 'esc_html' )->alias(
				static fn( $value ) => htmlspecialchars( (string) $value, ENT_QUOTES )
			);
			Functions\when( 'esc_url_raw' )->returnArg();
			Functions\when( 'apply_filters' )->returnArg( 2 );

			$captured = [];
			Functions\when( 'update_post_meta' )->alias(
				static function ( $id, $key, $value ) use ( &$captured ) {
					$captured[] = [ 'id' => $id, 'key' => $key, 'value' => $value ];

					return true;
				}
			);

			$point         = $this->point( [ 'name' => 'ООО "Ромашка" & Ко' ] );
			$source        = $this->source_returning( $point );
			$order_handler = new Shipping_Order_Handler( [ 'pickup_full' => 'cdek_full_point' ] );
			$_POST         = [ 'pickup_point' => 'P1' ];

			$handler = new Pickup_Handler(
				'p',
				'pickup_point',
				$source,
				$this->yandex_provider(),
				$this->default_location(),
				$order_handler,
				'pickup_full'
			);
			$handler->handle_checkout_order_processed( 1, [], new \WC_Order() );

			$this->assertCount( 1, $captured );
			$this->assertSame( $point->to_array(), $captured[0]['value'] );
			$this->assertSame( 'ООО "Ромашка" & Ко', $captured[0]['value']['name'] );
			$this->assertNotSame( $point->to_browser_array(), $captured[0]['value'] );
		}

		/**
		 * BLOCKING fix proof: persistence goes through the plugin-supplied key map on
		 * {@see Shipping_Order_Handler}, not a framework-coined `{field_id}_full`
		 * suffix. Two DIFFERENT key maps for the SAME field id must produce two
		 * different real meta keys, proving the plugin's map — not the field id — decides
		 * the destination.
		 */
		public function test_persistence_uses_the_plugin_supplied_key_map_not_a_coined_suffix(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );

			$captured = [];
			Functions\when( 'update_post_meta' )->alias(
				static function ( $id, $key, $value ) use ( &$captured ) {
					$captured[] = $key;

					return true;
				}
			);

			$point  = $this->point();
			$_POST  = [ 'pickup_point' => 'P1' ];

			$first = new Pickup_Handler(
				'p',
				'pickup_point',
				$this->source_returning( $point ),
				$this->yandex_provider(),
				$this->default_location(),
				new Shipping_Order_Handler( [ 'pickup_full' => 'cdek_full_point' ] ),
				'pickup_full'
			);
			$first->handle_checkout_order_processed( 1, [], new \WC_Order() );

			$second = new Pickup_Handler(
				'p',
				'pickup_point',
				$this->source_returning( $point ),
				$this->yandex_provider(),
				$this->default_location(),
				new Shipping_Order_Handler( [ 'pickup_full' => 'yandex_delivery_point_data' ] ),
				'pickup_full'
			);
			$second->handle_checkout_order_processed( 1, [], new \WC_Order() );

			$this->assertSame( [ 'cdek_full_point', 'yandex_delivery_point_data' ], $captured );
			$this->assertNotContains( 'pickup_point_full', $captured, 'no framework-coined suffix must ever appear' );
		}

		/**
		 * BLOCKING fix proof: with no {@see Shipping_Order_Handler} wired at all,
		 * persistence must be skipped ENTIRELY — the framework must not fall back to
		 * coining a key of its own. The id itself is still §8's job, unaffected here.
		 */
		public function test_persistence_is_skipped_entirely_when_no_order_handler_is_wired(): void {
			$source = $this->source_returning( $this->point() );
			$_POST  = [ 'pickup_point' => 'P1' ];

			Functions\expect( 'update_post_meta' )->never();

			// The 4-arg constructor — no Shipping_Order_Handler, no logical field name.
			$handler = new Pickup_Handler(
				'p',
				'pickup_point',
				$source,
				$this->yandex_provider(),
				$this->default_location()
			);
			$handler->handle_checkout_order_processed( 1, [], new \WC_Order() );

			$this->assertSame( 0, $source->fetch_details_calls, 'must skip before ever fetching the point' );
		}

		public function test_persistence_is_skipped_when_no_point_was_posted(): void {
			$source        = $this->source_returning( $this->point() );
			$order_handler = new Shipping_Order_Handler( [ 'pickup_full' => 'cdek_full_point' ] );
			$_POST         = [];

			Functions\expect( 'update_post_meta' )->never();

			$handler = new Pickup_Handler(
				'p',
				'pickup_point',
				$source,
				$this->yandex_provider(),
				$this->default_location(),
				$order_handler,
				'pickup_full'
			);
			$handler->handle_checkout_order_processed( 1, [], new \WC_Order() );

			$this->assertSame( 0, $source->fetch_details_calls );
		}

		public function test_persistence_is_skipped_when_the_point_is_unknown(): void {
			$source        = $this->source_returning( null );
			$order_handler = new Shipping_Order_Handler( [ 'pickup_full' => 'cdek_full_point' ] );
			$_POST         = [ 'pickup_point' => 'unknown' ];

			Functions\expect( 'update_post_meta' )->never();

			$handler = new Pickup_Handler(
				'p',
				'pickup_point',
				$source,
				$this->yandex_provider(),
				$this->default_location(),
				$order_handler,
				'pickup_full'
			);
			$handler->handle_checkout_order_processed( 1, [], new \WC_Order() );
		}

		/**
		 * Any thrown `\Throwable` (not only `\Woodev_API_Exception`) while re-fetching for
		 * persistence must be swallowed silently — the order already exists, so a
		 * re-thrown failure here would be a fatal AFTER the order row is committed,
		 * strictly worse than one during `woocommerce_checkout_process`.
		 */
		public function test_persistence_is_skipped_silently_on_any_throwable_and_is_logged(): void {
			$source        = $this->source_throwing( new \RuntimeException( 'carrier SDK exploded' ) );
			$order_handler = new Shipping_Order_Handler( [ 'pickup_full' => 'cdek_full_point' ] );
			$_POST         = [ 'pickup_point' => 'P1' ];

			$probe = new Pickup_Handler_Probe(
				'p',
				'pickup_point',
				$source,
				$this->yandex_provider(),
				$this->default_location(),
				$order_handler,
				'pickup_full'
			);

			Functions\expect( 'update_post_meta' )->never();

			// Must not throw — the order already exists.
			$probe->handle_checkout_order_processed( 1, [], new \WC_Order() );

			$this->assertCount( 1, $probe->logged );
			$this->assertSame( 'order persistence re-fetch', $probe->logged[0]['context'] );
		}

		// -------------------------------------------------------------------------
		// register() — hook wiring
		// -------------------------------------------------------------------------

		/**
		 * Asserts not merely that a callback array is registered on each hook, but
		 * WHICH method is bound — `\Mockery::type('array')` (the original shape of
		 * this test) would still pass if, say, `restore_selection` were bound to
		 * `woodev_shipping_pickup_point_selected` instead of `remember_selection`, or
		 * `woocommerce_checkout_get_value` were wired to the wrong method entirely. The
		 * handler is constructed FIRST so the expectations can assert the exact
		 * `[ $handler, 'method_name' ]` pair for every hook it registers.
		 */
		public function test_register_wires_the_expected_hooks(): void {
			$handler = new Pickup_Handler(
				'p',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location()
			);

			Functions\expect( 'add_action' )
				->once()
				->with( 'wp_enqueue_scripts', [ $handler, 'enqueue_assets' ] );

			Functions\expect( 'add_action' )
				->once()
				->with( 'rest_api_init', [ $handler, 'register_rest' ] );

			Functions\expect( 'add_action' )
				->once()
				->with( 'woocommerce_checkout_process', [ $handler, 'handle_checkout_process' ] );

			Functions\expect( 'add_action' )
				->once()
				->with( 'woocommerce_checkout_order_processed', [ $handler, 'handle_checkout_order_processed' ], 10, 3 );

			// Issue #157: the nonce-refresh channel — the footer node and the fragment that
			// replaces it on every update_checkout.
			Functions\expect( 'add_action' )
				->once()
				->with( 'wp_footer', [ $handler, 'print_nonce_node' ] );

			Functions\expect( 'add_filter' )
				->once()
				->with( 'woocommerce_update_order_review_fragments', [ $handler, 'inject_nonce_fragment' ] );

			// Issue #176: pickup-selection persistence — the write side (an action, not
			// the pre-existing woodev_shipping_pickup_point_selection filter — see
			// Pickup_Controller::handle_select_request()) and the restore side.
			Functions\expect( 'add_action' )
				->once()
				->with( 'woodev_shipping_pickup_point_selected', [ $handler, 'remember_selection' ], 10, 2 );

			Functions\expect( 'add_filter' )
				->once()
				->with( 'woocommerce_checkout_get_value', [ $handler, 'restore_selection' ], 10, 2 );

			$handler->register();
		}

		// -------------------------------------------------------------------------
		// register_rest() — closes the gap where nothing registered Pickup_Controller
		// -------------------------------------------------------------------------

		public function test_register_rest_registers_the_pickup_controllers_routes(): void {
			$registered = [];
			Functions\when( 'register_rest_route' )->alias(
				static function ( $namespace, $route, $args ) use ( &$registered ) {
					$registered[] = $route;
				}
			);

			$handler = new Pickup_Handler(
				'carrier',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location()
			);
			$handler->register_rest();

			$this->assertContains( '/shipping/pickup/carrier/points', $registered );
			$this->assertContains( '/shipping/pickup/carrier/points/(?P<id>[^/]+)', $registered );
		}

		/**
		 * The other half of the Task 15 (issue #159) server<->client join: proves
		 * register_rest() actually HANDS Pickup_Controller a `location_context` callable
		 * bound to THIS handler when a plugin was wired — reflection, since the
		 * controller instance itself is otherwise anonymous
		 * ( `( new Pickup_Controller(...) )->register_routes()` never exposes it).
		 */
		public function test_register_rest_wires_the_location_context_callable_when_a_plugin_is_present(): void {
			$captured_args = null;
			Functions\when( 'register_rest_route' )->alias(
				static function ( $namespace, $route, $args ) use ( &$captured_args ) {
					// Grab the FIRST route registration's args only — both routes are
					// constructed from the SAME controller instance.
					$captured_args = $captured_args ?? $args;
				}
			);

			$plugin  = $this->location_plugin( $this->location_record() );
			$handler = $this->make_handler( [ 'plugin' => $plugin ] );
			$handler->register_rest();

			$this->assertNotNull( $captured_args );
			$controller = $captured_args[0]['callback'][0];

			$property = new \ReflectionProperty( $controller, 'location_context' );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$bound_callable = $property->getValue( $controller );

			$this->assertIsCallable( $bound_callable );
			$this->assertSame( $handler, $bound_callable[0] );
			$this->assertSame( 'location_context', $bound_callable[1] );
		}

		public function test_register_rest_wires_no_location_context_callable_without_a_plugin(): void {
			$captured_args = null;
			Functions\when( 'register_rest_route' )->alias(
				static function ( $namespace, $route, $args ) use ( &$captured_args ) {
					$captured_args = $captured_args ?? $args;
				}
			);

			$handler = $this->make_handler();
			$handler->register_rest();

			$controller = $captured_args[0]['callback'][0];

			$property = new \ReflectionProperty( $controller, 'location_context' );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}

			$this->assertNull( $property->getValue( $controller ) );
		}

		// -------------------------------------------------------------------------
		// nonce refresh through a checkout fragment (issue #157)
		// -------------------------------------------------------------------------

		/**
		 * Returns a `wp_create_nonce` stub minting a DIFFERENT value on every call, so a
		 * test can tell a freshly-minted nonce from the one baked into an earlier
		 * `get_js_config()` — the entire point of the fragment. A `justReturn('NONCE')`
		 * stub cannot distinguish the two and would pass against the very bug #157 is about.
		 */
		private function stub_incrementing_nonce(): void {
			$calls = 0;
			Functions\when( 'wp_create_nonce' )->alias(
				static function () use ( &$calls ) {
					++$calls;

					return 'NONCE-' . $calls;
				}
			);
		}

		public function test_the_nonce_node_id_is_derived_from_the_config_object_suffix(): void {
			$sanitized = $this->make_handler( [ 'plugin_id' => 'carrier!!!' ] )->nonce_node_id();
			$plain     = $this->make_handler( [ 'plugin_id' => 'carrier' ] )->nonce_node_id();

			// The id must be unique per config object — two handlers on one checkout page
			// (two shipping plugins) would otherwise fight over one node and one fragment.
			$this->assertNotSame( $plain, $sanitized );
			$this->assertStringContainsString( 'carrier', $plain );
		}

		/**
		 * @dataProvider provide_colliding_plugin_ids
		 *
		 * @param string $first  one plugin id.
		 * @param string $second another plugin id that used to sanitise to the same suffix.
		 */
		public function test_two_plugin_ids_that_differ_only_in_punctuation_do_not_collide(
			string $first,
			string $second
		): void {
			// REGRESSION (Codex finding 7 / issue #142): `preg_replace( '/[^a-z0-9_]/i', '_' )`
			// mapped `carrier-a`, `carrier_a` and `carrier.a` onto ONE suffix, so two shipping
			// plugins on one checkout page shared a nonce node, a checkout-fragment key and a
			// JS config global — the second silently overwriting the first's REST nonce and
			// pickup field id.
			$this->assertNotSame(
				$this->make_handler( [ 'plugin_id' => $first ] )->nonce_node_id(),
				$this->make_handler( [ 'plugin_id' => $second ] )->nonce_node_id()
			);
		}

		/**
		 * @return array<string, array{0: string, 1: string}>
		 */
		public function provide_colliding_plugin_ids(): array {
			return [
				'hyphen vs underscore' => [ 'carrier-a', 'carrier_a' ],
				'dot vs underscore'    => [ 'carrier.a', 'carrier_a' ],
				'dot vs hyphen'        => [ 'carrier.a', 'carrier-a' ],
				'slash vs underscore'  => [ 'carrier/a', 'carrier_a' ],
			];
		}

		public function test_a_plugin_id_that_is_already_a_js_identifier_keeps_its_plain_suffix(): void {
			// The suffix stays readable for the overwhelmingly common case — only an id that
			// had to be rewritten pays for the disambiguator.
			$this->assertSame(
				'woodev-pickup-nonce-carrier_a',
				$this->make_handler( [ 'plugin_id' => 'carrier_a' ] )->nonce_node_id()
			);
		}

		public function test_a_rewritten_suffix_is_still_a_valid_js_identifier(): void {
			$id = $this->make_handler( [ 'plugin_id' => 'carrier.a!' ] )->nonce_node_id();

			$this->assertMatchesRegularExpression(
				'/^woodev-pickup-nonce-[A-Za-z0-9_]+$/',
				$id,
				'the suffix also names a JS global, so it must stay identifier-safe'
			);
		}

		public function test_config_carries_the_nonce_node_id_the_handler_prints(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			$this->stub_config_dependencies_except_filters();

			$handler = $this->make_handler( [ 'plugin_id' => 'carrier' ] );

			$this->assertSame( $handler->nonce_node_id(), $handler->get_js_config()['nonceNodeId'] );
		}

		public function test_print_nonce_node_prints_a_hidden_span_carrying_a_fresh_nonce(): void {
			Functions\when( 'is_checkout' )->justReturn( true );
			$this->stub_incrementing_nonce();

			$handler = $this->make_handler( [ 'plugin_id' => 'carrier' ] );

			ob_start();
			$handler->print_nonce_node();
			$html = (string) ob_get_clean();

			$this->assertStringContainsString( 'id="' . $handler->nonce_node_id() . '"', $html );
			$this->assertStringContainsString( 'data-woodev-pickup-nonce="NONCE-1"', $html );
			$this->assertStringContainsString( 'hidden', $html );
		}

		public function test_the_nonce_fragment_is_keyed_by_the_node_id_and_carries_a_fresh_nonce(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );
			$this->stub_incrementing_nonce();

			$handler = $this->make_handler( [ 'plugin_id' => 'carrier' ] );

			// The page-load config takes NONCE-1; the fragment must not repeat it.
			$page_nonce = $handler->get_js_config()['nonce'];
			$fragments  = $handler->inject_nonce_fragment( [] );

			$key = '#' . $handler->nonce_node_id();

			$this->assertArrayHasKey( $key, $fragments );
			$this->assertStringContainsString( 'id="' . $handler->nonce_node_id() . '"', $fragments[ $key ] );
			$this->assertStringContainsString( 'data-woodev-pickup-nonce="NONCE-2"', $fragments[ $key ] );
			$this->assertStringNotContainsString( $page_nonce . '"', $fragments[ $key ] );
		}

		/**
		 * `woocommerce_update_order_review_fragments` is a SHARED array — WooCommerce's own
		 * order-review fragment lives in it, and so does every other plugin's. Replacing the
		 * array instead of adding one key silently blanks the checkout totals.
		 */
		public function test_the_nonce_fragment_leaves_every_other_fragment_untouched(): void {
			$this->stub_incrementing_nonce();

			$handler = $this->make_handler( [ 'plugin_id' => 'carrier' ] );

			$fragments = $handler->inject_nonce_fragment(
				[
					'.woocommerce-checkout-review-order-table' => '<table>totals</table>',
					'#other-plugin-node'                       => '<span>other</span>',
				]
			);

			$this->assertCount( 3, $fragments );
			$this->assertSame( '<table>totals</table>', $fragments['.woocommerce-checkout-review-order-table'] );
			$this->assertSame( '<span>other</span>', $fragments['#other-plugin-node'] );
		}

		// -------------------------------------------------------------------------
		// enqueue_assets() — handles, paths, localization, and the "not yet built" guard
		// -------------------------------------------------------------------------

		public function test_enqueue_assets_does_nothing_off_the_checkout_page(): void {
			Functions\when( 'is_checkout' )->justReturn( false );

			Functions\expect( 'wp_enqueue_script' )->never();
			Functions\expect( 'wp_enqueue_style' )->never();
			Functions\expect( 'wp_localize_script' )->never();

			$handler = new Pickup_Handler(
				'p',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location()
			);
			$handler->enqueue_assets();
		}

		/**
		 * Guards `enqueue_script_if_built()`/`enqueue_style_if_built()`'s "only enqueue
		 * what exists on disk" behaviour — the real reason a vendored boot must never
		 * register a dependency on a handle nothing ever registered.
		 *
		 * Every SP-5 frontend asset has now landed, so this test asserts the complete set:
		 * the modal (Task 10), the dataSource (Task 11), the mount (Task 12), the active
		 * provider's script (`map-provider-yandex.js`, Tasks 13/14) and the stylesheet
		 * (`css/frontend/pickup.css`, Task 15).
		 *
		 * This test has now outlived TWO premises, both legitimately: it first asserted the
		 * provider script was absent (dead once Tasks 13/14 landed the file), then that the
		 * stylesheet was absent (dead once Task 15 landed `pickup.css`). Neither flip was a
		 * regression — each was a deliberately-recorded expectation expiring on schedule.
		 * What the test actually guards is unchanged throughout: an asset missing from disk
		 * must never be enqueued.
		 */
		public function test_enqueue_assets_enqueues_only_the_assets_already_built(): void {
			Functions\when( 'is_checkout' )->justReturn( true );
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );
			Functions\when( 'plugins_url' )->alias(
				static fn( $path, $file ) => 'https://example.test/wp-content/plugins/x/' . $path
			);

			$scripts = [];
			Functions\when( 'wp_enqueue_script' )->alias(
				static function ( $handle, $src, $deps, $ver, $footer ) use ( &$scripts ) {
					$scripts[ $handle ] = [ 'src' => $src, 'deps' => $deps ];
				}
			);

			// D-13: the chrome stylesheet is registered ONCE, framework-side, by
			// Woodev_Plugin::frontend_enqueue_scripts() — Pickup_Handler must never register it
			// itself, only depend on it (checked below via woodev-pickup-styles' own deps).
			Functions\expect( 'wp_register_style' )->never();

			$styles = [];
			Functions\when( 'wp_enqueue_style' )->alias(
				static function ( $handle, $src, $deps, $ver ) use ( &$styles ) {
					$styles[ $handle ] = [
						'src'  => $src,
						'deps' => $deps,
					];
				}
			);

			$localized = [];
			Functions\when( 'wp_localize_script' )->alias(
				static function ( $handle, $object_name, $data ) use ( &$localized ) {
					$localized[] = [ $handle, $object_name, $data ];
				}
			);

			$handler = new Pickup_Handler(
				'p',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location()
			);
			$handler->enqueue_assets();

			// The modal is registered ONCE, framework-side, by
			// Woodev_Plugin::frontend_enqueue_scripts() (see WoodevPluginFrontendEnqueueScriptsTest)
			// — Pickup_Handler must never register it itself, only depend on it (checked below via
			// the mount handle's deps). A mutant that reintroduced a direct registration here, under
			// EITHER the new or the old handle, must fail one of these two assertions.
			$this->assertArrayNotHasKey( 'woodev-modal', $scripts );
			$this->assertArrayNotHasKey( 'woodev-pickup-modal', $scripts );

			$this->assertArrayHasKey( 'woodev-pickup-datasource', $scripts );
			$this->assertStringContainsString( 'pickup-datasource.js', $scripts['woodev-pickup-datasource']['src'] );
			$this->assertSame( [], $scripts['woodev-pickup-datasource']['deps'] );

			// pickup-geo.js (SP-5 Task 9, T20 wiring): pure functions, no dependencies of its
			// own.
			$this->assertArrayHasKey( 'woodev-pickup-geo', $scripts );
			$this->assertStringContainsString( 'pickup-geo.js', $scripts['woodev-pickup-geo']['src'] );
			$this->assertSame( [], $scripts['woodev-pickup-geo']['deps'] );

			// pickup-panels.js (SP-5 Tasks 12-16, T20 wiring): depends on pickup-geo.js for its
			// distance/colour arithmetic.
			$this->assertArrayHasKey( 'woodev-pickup-panels', $scripts );
			$this->assertStringContainsString( 'pickup-panels.js', $scripts['woodev-pickup-panels']['src'] );
			$this->assertSame( [ 'woodev-pickup-geo' ], $scripts['woodev-pickup-panels']['deps'] );

			// map-provider-yandex.js (SP-5 Tasks 13/14) exists on disk — see the method
			// docblock above for why this flipped from assertArrayNotHasKey(). Now depends on
			// pickup-geo.js too (T20): map-provider-yandex.js calls its safeColor()/nearest()/
			// boundsFor()/matchPoints().
			$this->assertArrayHasKey( 'woodev-pickup-map-provider-yandex', $scripts );
			$this->assertStringContainsString(
				'map-provider-yandex.js',
				$scripts['woodev-pickup-map-provider-yandex']['src']
			);
			$this->assertSame( [ 'woodev-pickup-geo' ], $scripts['woodev-pickup-map-provider-yandex']['deps'] );

			$this->assertArrayHasKey( 'woodev-pickup-mount', $scripts );
			$this->assertStringContainsString( 'pickup-mount.js', $scripts['woodev-pickup-mount']['src'] );
			$this->assertSame(
				[
					'jquery',
					'woodev-modal',
					'woodev-pickup-datasource',
					'woodev-pickup-geo',
					'woodev-pickup-panels',
					'woodev-pickup-map-provider-yandex',
				],
				$scripts['woodev-pickup-mount']['deps']
			);

			// pickup.css (SP-5 Task 15) exists on disk — see the method docblock above for
			// why this flipped from an `expect( 'wp_enqueue_style' )->never()` expectation.
			$this->assertArrayHasKey( 'woodev-pickup-styles', $styles );
			$this->assertStringContainsString( 'pickup.css', $styles['woodev-pickup-styles']['src'] );
			// D-13: declares the framework-registered chrome stylesheet as a dependency,
			// exactly like the mount script's own 'woodev-modal' script dependency above.
			$this->assertSame( [ 'woodev-modal' ], $styles['woodev-pickup-styles']['deps'] );

			$this->assertCount( 1, $localized );
			[ $handle, $object_name ] = $localized[0];
			$this->assertSame( 'woodev-pickup-mount', $handle );
			$this->assertSame( 'woodev_pickup_config_p', $object_name );
		}

		public function test_enqueue_assets_registers_the_expected_handles_and_paths_once_built(): void {
			Functions\when( 'is_checkout' )->justReturn( true );
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );
			Functions\when( 'plugins_url' )->alias(
				static fn( $path, $file ) => 'https://example.test/wp-content/plugins/x/' . $path
			);

			$scripts = [];
			Functions\when( 'wp_enqueue_script' )->alias(
				static function ( $handle, $src, $deps, $ver, $footer ) use ( &$scripts ) {
					$scripts[ $handle ] = [ 'src' => $src, 'deps' => $deps ];
				}
			);

			Functions\expect( 'wp_register_style' )->never();

			$styles = [];
			Functions\when( 'wp_enqueue_style' )->alias(
				static function ( $handle, $src, $deps, $ver ) use ( &$styles ) {
					$styles[ $handle ] = [
						'src'  => $src,
						'deps' => $deps,
					];
				}
			);

			Functions\when( 'wp_localize_script' )->justReturn( true );

			$handler = new Pickup_Handler_Assets_Built_Probe(
				'p',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location()
			);
			$handler->enqueue_assets();

			// See test_enqueue_assets_enqueues_only_the_assets_already_built()'s own comment:
			// registration lives framework-side now, this class only depends on the handle.
			$this->assertArrayNotHasKey( 'woodev-modal', $scripts );
			$this->assertArrayNotHasKey( 'woodev-pickup-modal', $scripts );

			$this->assertArrayHasKey( 'woodev-pickup-datasource', $scripts );
			$this->assertStringContainsString( 'pickup-datasource.js', $scripts['woodev-pickup-datasource']['src'] );

			$this->assertArrayHasKey( 'woodev-pickup-geo', $scripts );
			$this->assertStringContainsString( 'pickup-geo.js', $scripts['woodev-pickup-geo']['src'] );

			$this->assertArrayHasKey( 'woodev-pickup-panels', $scripts );
			$this->assertStringContainsString( 'pickup-panels.js', $scripts['woodev-pickup-panels']['src'] );
			$this->assertSame( [ 'woodev-pickup-geo' ], $scripts['woodev-pickup-panels']['deps'] );

			$this->assertArrayHasKey( 'woodev-pickup-map-provider-yandex', $scripts );
			$this->assertStringContainsString(
				'map-provider-yandex.js',
				$scripts['woodev-pickup-map-provider-yandex']['src']
			);
			$this->assertSame( [ 'woodev-pickup-geo' ], $scripts['woodev-pickup-map-provider-yandex']['deps'] );

			$this->assertArrayHasKey( 'woodev-pickup-mount', $scripts );
			$this->assertStringContainsString( 'pickup-mount.js', $scripts['woodev-pickup-mount']['src'] );
			$this->assertSame(
				[
					'jquery',
					'woodev-modal',
					'woodev-pickup-datasource',
					'woodev-pickup-geo',
					'woodev-pickup-panels',
					'woodev-pickup-map-provider-yandex',
				],
				$scripts['woodev-pickup-mount']['deps']
			);

			$this->assertArrayHasKey( 'woodev-pickup-styles', $styles );
			$this->assertStringContainsString( 'pickup.css', $styles['woodev-pickup-styles']['src'] );
			$this->assertSame( [ 'woodev-modal' ], $styles['woodev-pickup-styles']['deps'] );
		}

		public function test_enqueue_assets_localizes_the_config_onto_the_mount_handle_once_built(): void {
			Functions\when( 'is_checkout' )->justReturn( true );
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );
			Functions\when( 'plugins_url' )->alias(
				static fn( $path, $file ) => 'https://example.test/' . $path
			);
			Functions\when( 'wp_enqueue_script' )->justReturn( true );
			Functions\when( 'wp_enqueue_style' )->justReturn( true );

			$localized = [];
			Functions\when( 'wp_localize_script' )->alias(
				static function ( $handle, $object_name, $data ) use ( &$localized ) {
					$localized[] = [ $handle, $object_name, $data ];
				}
			);

			$handler = new Pickup_Handler_Assets_Built_Probe(
				'carrier-x',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location()
			);
			$handler->enqueue_assets();

			$this->assertCount( 1, $localized );
			[ $handle, $object_name, $data ] = $localized[0];
			$this->assertSame( 'woodev-pickup-mount', $handle );
			// `carrier-x` is not a valid JS identifier, so the suffix is the rewritten form
			// plus the disambiguator that keeps it distinct from a plugin genuinely called
			// `carrier_x` — see Pickup_Handler::config_object_suffix() and issue #142.
			$this->assertStringStartsWith( 'woodev_pickup_config_carrier_x_', $object_name );
			$this->assertSame( 'pickup_point', $data['fieldId'] );
		}

		// -------------------------------------------------------------------------
		// Issue #176 — pickup-selection persistence
		// -------------------------------------------------------------------------

		/**
		 * Builds a bare, valid {@see Pickup_Point} for the persistence tests below —
		 * none of them care about anything but `id` and `type.code`.
		 *
		 * @param string $id   point id.
		 * @param string $type point type code.
		 */
		private function selection_point( string $id = 'P1', string $type = 'pvz' ): Pickup_Point {
			return Pickup_Point::from_array(
				[
					'id'      => $id,
					'name'    => 'Точка',
					'lat'     => 55.75,
					'lng'     => 37.61,
					'address' => 'Тверская, 1',
					'type'    => [ 'code' => $type, 'label' => 'ПВЗ' ],
				]
			);
		}

		// --- remember_selection() — the write side ---

		public function test_remember_selection_writes_the_confirmed_point(): void {
			$scope = new Pickup_Handler_Selection_Test_Scope(
				'key',
				static fn( Pickup_Point $point ) => 'msk',
				static fn() => '',
				static fn( string $method_id ) => null
			);
			$selection = new Pickup_Handler_Selection_Probe( $scope, new Pickup_Handler_Fake_Session() );

			$handler = new Pickup_Handler_With_Selection_Probe(
				'p',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				$scope,
				$selection
			);

			$handler->remember_selection( $this->selection_point( 'P1', 'pvz' ), [ 'field_id' => 'pickup_point' ] );

			$this->assertSame( 'P1', $selection->recall( 'msk', 'pvz' ) );
		}

		/**
		 * The action is global (every {@see Pickup_Handler} instance listens on the
		 * same `woodev_shipping_pickup_point_selected` hook), so a selection belonging
		 * to a DIFFERENT pickup field must be ignored, never remembered under this
		 * handler's own scope.
		 */
		public function test_remember_selection_ignores_a_selection_for_a_different_field(): void {
			$scope = new Pickup_Handler_Selection_Test_Scope(
				'key',
				static fn( Pickup_Point $point ) => 'msk',
				static fn() => '',
				static fn( string $method_id ) => null
			);
			$selection = new Pickup_Handler_Selection_Probe( $scope, new Pickup_Handler_Fake_Session() );

			$handler = new Pickup_Handler_With_Selection_Probe(
				'p',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				$scope,
				$selection
			);

			$handler->remember_selection( $this->selection_point(), [ 'field_id' => 'some_other_field' ] );

			$this->assertNull( $selection->recall( 'msk', 'pvz' ) );
		}

		/**
		 * "No scope → no persistence" — the same discipline
		 * {@see Pickup_Handler::handle_checkout_order_processed()} already applies to
		 * full-point persistence. Must not throw.
		 */
		public function test_remember_selection_does_nothing_without_a_scope(): void {
			$handler = new Pickup_Handler_With_Selection_Probe(
				'p',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				null,
				null
			);

			$handler->remember_selection( $this->selection_point(), [ 'field_id' => 'pickup_point' ] );

			$this->assertTrue( true );
		}

		/**
		 * The load-bearing bridge test: {@see Pickup_Handler_Bridge_Probe} starts with
		 * NO usable session at all (`wc_cart()` forced absent) — it becomes usable
		 * ONLY once `remember_selection()` calls `load_wc_cart()`, exactly the way a
		 * real `/select` REST request arrives with no cart/session bootstrapped yet.
		 * Every OTHER `remember_selection()` test in this file injects an
		 * already-live fake session via {@see Pickup_Handler_Selection_Probe}, which
		 * leaves `if ( ! $this->wc_cart() && $this->wc_load_cart_available() ) {
		 * $this->load_wc_cart(); }` completely unpinned — deleting it leaves those
		 * tests green while a real REST request would silently persist nothing.
		 */
		public function test_remember_selection_bridges_to_a_freshly_loaded_cart_session(): void {
			$scope = new Pickup_Handler_Selection_Test_Scope(
				'key',
				static fn( Pickup_Point $point ) => 'msk',
				static fn() => '',
				static fn( string $method_id ) => null
			);
			$selection = new Pickup_Handler_Bridging_Selection_Probe( $scope );

			$handler = new Pickup_Handler_Bridge_Probe(
				'p',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				$scope,
				$selection
			);

			$handler->remember_selection( $this->selection_point( 'P1', 'pvz' ), [ 'field_id' => 'pickup_point' ] );

			$this->assertSame( 1, $handler->load_wc_cart_calls, 'the cart/session bridge must actually be invoked' );
			$this->assertSame( 'P1', $selection->recall( 'msk', 'pvz' ), 'the write must land once the session becomes available' );
		}

		// --- remember_selection() also remembers the address — issue #274 item 2 ---

		/**
		 * The whole point of #274 item 2: `remember_selection()` must remember the confirmed
		 * point's `short_address` alongside its id, using the SAME field the browser eventually
		 * reads through `to_browser_array()` — no separate carrier request, no second derivation.
		 */
		public function test_remember_selection_also_writes_the_points_short_address(): void {
			$scope = new Pickup_Handler_Selection_Test_Scope(
				'key',
				static fn( Pickup_Point $point ) => 'msk',
				static fn() => '',
				static fn( string $method_id ) => null
			);
			$selection = new Pickup_Handler_Selection_Probe( $scope, new Pickup_Handler_Fake_Session() );

			$handler = new Pickup_Handler_With_Selection_Probe(
				'p',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				$scope,
				$selection
			);

			$handler->remember_selection( $this->selection_point( 'P1', 'pvz' ), [ 'field_id' => 'pickup_point' ] );

			$this->assertSame( 'Тверская, 1', $selection->recall_address( 'msk', 'pvz' ) );
		}

		/**
		 * `Pickup_Point::from_array()` derives `short_address` from `address` when the payload
		 * carries no separate short form (issue #263) — `remember_selection()` must read that
		 * ALREADY-derived value, never fall back to the raw `address` a second time itself. A
		 * point built with an explicit `short_address` proves the field actually consulted is
		 * `short_address`, not `address` — the two differ here on purpose.
		 */
		public function test_remember_selection_stores_the_derived_short_address_not_the_raw_address(): void {
			$scope = new Pickup_Handler_Selection_Test_Scope(
				'key',
				static fn( Pickup_Point $point ) => 'msk',
				static fn() => '',
				static fn( string $method_id ) => null
			);
			$selection = new Pickup_Handler_Selection_Probe( $scope, new Pickup_Handler_Fake_Session() );

			$handler = new Pickup_Handler_With_Selection_Probe(
				'p',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				$scope,
				$selection
			);

			$point = Pickup_Point::from_array(
				[
					'id'            => 'P1',
					'name'          => 'Точка',
					'lat'           => 55.75,
					'lng'           => 37.61,
					'address'       => 'г. Москва, ул. Тверская, д. 1',
					'short_address' => 'Тверская, 1',
					'type'          => [ 'code' => 'pvz', 'label' => 'ПВЗ' ],
				]
			);

			$handler->remember_selection( $point, [ 'field_id' => 'pickup_point' ] );

			$this->assertSame( 'Тверская, 1', $selection->recall_address( 'msk', 'pvz' ) );
		}

		// --- restore_selection() — the woocommerce_checkout_get_value read side ---

		/**
		 * A real scope AND a stored entry for THIS handler's own field — not just an
		 * absent scope — so removing the field-id gate (`if ( $this->field_id !==
		 * $key )`) has somewhere real to leak into. Without them, the "no scope"
		 * short-circuit further down the method would mask the gate's removal: a
		 * mutant reading `if ( false ) { return $value; }` instead of the real check
		 * would fall through to that "no scope" branch too and return `$value`
		 * unchanged by coincidence. With a live scope and a stored `(msk, pvz)` entry,
		 * the same mutant instead restores the OTHER field's own pickup selection.
		 */
		public function test_restore_selection_returns_the_incoming_value_for_a_different_field(): void {
			$scope = new Pickup_Handler_Selection_Test_Scope(
				'key',
				static fn( Pickup_Point $point ) => 'msk',
				static fn() => 'msk',
				static fn( string $method_id ) => 'pvz'
			);
			$selection = new Pickup_Handler_Selection_Probe( $scope, new Pickup_Handler_Fake_Session() );
			$selection->remember( 'msk', 'pvz', 'P1' );

			$handler = new Pickup_Handler_With_Selection_Probe(
				'p',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				$scope,
				$selection,
				[ 'carrier_pickup' ]
			);

			$this->assertSame( 'incoming', $handler->restore_selection( 'incoming', 'billing_city' ) );
		}

		/**
		 * A non-pickup shipping method — {@see Selection_Scope::type_for_method()}
		 * returning `null` — is the whole gate spec §5 documents: nothing is restored,
		 * and the incoming value passes through unchanged.
		 */
		public function test_restore_selection_leaves_the_value_untouched_for_a_non_pickup_method(): void {
			$scope = new Pickup_Handler_Selection_Test_Scope(
				'key',
				static fn( Pickup_Point $point ) => 'msk',
				static fn() => 'msk',
				static fn( string $method_id ) => null
			);
			$selection = new Pickup_Handler_Selection_Probe( $scope, new Pickup_Handler_Fake_Session() );
			$selection->remember( 'msk', 'pvz', 'P1' );

			$handler = new Pickup_Handler_With_Selection_Probe(
				'p',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				$scope,
				$selection,
				[ 'flat_rate' ]
			);

			$this->assertSame( 'incoming', $handler->restore_selection( 'incoming', 'pickup_point' ) );
		}

		/**
		 * A typed method restores the exact `(locality, type)` entry, even when a
		 * DIFFERENT type is also stored for the same locality — proving the restore
		 * does not fall back to "any type" when a real type code is available.
		 */
		public function test_restore_selection_restores_the_matching_type_when_two_types_are_stored(): void {
			$scope = new Pickup_Handler_Selection_Test_Scope(
				'key',
				static fn( Pickup_Point $point ) => 'msk',
				static fn() => 'msk',
				static fn( string $method_id ) => 'carrier_pickup' === $method_id ? 'postamat' : null
			);
			$selection = new Pickup_Handler_Selection_Probe( $scope, new Pickup_Handler_Fake_Session() );
			$selection->remember( 'msk', 'pvz', 'P-PVZ' );
			$selection->remember( 'msk', 'postamat', 'P-POSTAMAT' );

			$handler = new Pickup_Handler_With_Selection_Probe(
				'p',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				$scope,
				$selection,
				[ 'carrier_pickup' ]
			);

			$this->assertSame( 'P-POSTAMAT', $handler->restore_selection( '', 'pickup_point' ) );
		}

		/**
		 * A VALID pickup method (a real type code, not `null`) with NOTHING stored for
		 * that (locality, type) pair yet — the incoming `$value` (WooCommerce's own
		 * resolved value) must be returned unchanged, not blanked. A mutant reading
		 * `return $point_id ?? '';` instead of
		 * `return null !== $point_id ? $point_id : $value;` folds the `null` recall
		 * into an empty string and discards whatever WooCommerce already had.
		 */
		public function test_restore_selection_returns_the_incoming_value_for_a_valid_method_with_nothing_stored(): void {
			$scope = new Pickup_Handler_Selection_Test_Scope(
				'key',
				static fn( Pickup_Point $point ) => 'msk',
				static fn() => 'msk',
				static fn( string $method_id ) => 'pvz'
			);
			$selection = new Pickup_Handler_Selection_Probe( $scope, new Pickup_Handler_Fake_Session() );
			// Nothing remembered for (msk, pvz).

			$handler = new Pickup_Handler_With_Selection_Probe(
				'p',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				$scope,
				$selection,
				[ 'carrier_pickup' ]
			);

			$this->assertSame( 'incoming', $handler->restore_selection( 'incoming', 'pickup_point' ) );
		}

		/**
		 * Every other restore test in this file hands `wc_session_chosen_shipping_methods()`
		 * a bare method id. A real WooCommerce session value carries the shipping
		 * RATE id, `method:instance_id` — spec §9 documents that
		 * {@see Selection_Scope::type_for_method()} must receive it already stripped.
		 * A mutant removing the `explode( ':', ... )[0]` call would hand the scope the
		 * whole `woodev_test_shipping:7` string, which this scope's closure does not
		 * recognise, so it would answer `null` and the stored selection would never
		 * restore.
		 */
		public function test_restore_selection_strips_the_instance_id_from_the_chosen_method(): void {
			$scope = new Pickup_Handler_Selection_Test_Scope(
				'key',
				static fn( Pickup_Point $point ) => 'msk',
				static fn() => 'msk',
				static fn( string $method_id ) => 'woodev_test_shipping' === $method_id ? 'pvz' : null
			);
			$selection = new Pickup_Handler_Selection_Probe( $scope, new Pickup_Handler_Fake_Session() );
			$selection->remember( 'msk', 'pvz', 'P1' );

			$handler = new Pickup_Handler_With_Selection_Probe(
				'p',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				$scope,
				$selection,
				[ 'woodev_test_shipping:7' ]
			);

			$this->assertSame( 'P1', $handler->restore_selection( '', 'pickup_point' ) );
		}

		// --- issue #176 opacity contract: the locality/type keys are never derived,
		// normalized, lowercased or sanitized (spec §3.2). Every OTHER selection test
		// in this file happens to use all-lowercase keys ('msk', 'pvz', 'postamat'),
		// so a stray strtolower()/sanitize_key() would leave every one of them green.

		/**
		 * Write side: {@see Pickup_Handler::remember_selection()} reads
		 * `$point->to_array()['type']['code']` verbatim. A mutant lower-casing it
		 * (`strtolower(...)`) would store `'pvz'` for a point whose real type code is
		 * `'PVZ'` — indistinguishable from correct behaviour under any all-lowercase
		 * fixture, which is exactly what every other test here uses.
		 */
		public function test_remember_selection_preserves_the_points_type_code_case_exactly(): void {
			$scope = new Pickup_Handler_Selection_Test_Scope(
				'key',
				static fn( Pickup_Point $point ) => 'MSK-77',
				static fn() => '',
				static fn( string $method_id ) => null
			);
			$selection = new Pickup_Handler_Selection_Probe( $scope, new Pickup_Handler_Fake_Session() );

			$handler = new Pickup_Handler_With_Selection_Probe(
				'p',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				$scope,
				$selection
			);

			$handler->remember_selection( $this->selection_point( 'P1', 'PVZ' ), [ 'field_id' => 'pickup_point' ] );

			$this->assertSame( 'P1', $selection->recall( 'MSK-77', 'PVZ' ), 'the type code must be stored with its exact case' );
			$this->assertNull( $selection->recall( 'MSK-77', 'pvz' ), 'a lower-cased type must not also incidentally match' );
		}

		/**
		 * Restore side: {@see Pickup_Handler::restore_selection()} reads
		 * `$scope->current_locality()` verbatim. A mutant running it through
		 * `sanitize_key()` would lower-case `'MSK-77'` to `'msk-77'` — a DIFFERENT
		 * string from what was stored, so the recall would silently miss.
		 */
		public function test_restore_selection_preserves_the_current_localitys_case_exactly(): void {
			$scope = new Pickup_Handler_Selection_Test_Scope(
				'key',
				static fn( Pickup_Point $point ) => 'MSK-77',
				static fn() => 'MSK-77',
				static fn( string $method_id ) => 'PVZ'
			);
			$selection = new Pickup_Handler_Selection_Probe( $scope, new Pickup_Handler_Fake_Session() );
			$selection->remember( 'MSK-77', 'PVZ', 'P1' );

			$handler = new Pickup_Handler_With_Selection_Probe(
				'p',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				$scope,
				$selection,
				[ 'carrier_pickup' ]
			);

			$this->assertSame( 'P1', $handler->restore_selection( '', 'pickup_point' ) );
		}

		/**
		 * {@see Selection_Scope::TYPE_ANY} restores the most recently written entry
		 * for the locality, regardless of type — spec §5 step 4.
		 */
		public function test_restore_selection_type_any_restores_the_most_recently_written_entry(): void {
			$scope = new Pickup_Handler_Selection_Test_Scope(
				'key',
				static fn( Pickup_Point $point ) => 'msk',
				static fn() => 'msk',
				static fn( string $method_id ) => Selection_Scope::TYPE_ANY
			);
			$selection = new Pickup_Handler_Selection_Probe( $scope, new Pickup_Handler_Fake_Session() );
			$selection->remember( 'msk', 'pvz', 'P-PVZ' );
			$selection->remember( 'msk', 'postamat', 'P-POSTAMAT' );

			$handler = new Pickup_Handler_With_Selection_Probe(
				'p',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				$scope,
				$selection,
				[ 'carrier_pickup' ]
			);

			$this->assertSame( 'P-POSTAMAT', $handler->restore_selection( '', 'pickup_point' ) );
		}

		/**
		 * "No scope → the handler behaves exactly as today" — guards every EXISTING
		 * consumer of `woocommerce_checkout_get_value`: a plugin that has not wired
		 * selection persistence must see the filter change nothing at all.
		 */
		public function test_restore_selection_without_a_scope_returns_the_incoming_value_unchanged(): void {
			$handler = new Pickup_Handler_With_Selection_Probe(
				'p',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				null,
				null,
				[ 'carrier_pickup' ]
			);

			$this->assertSame( 'whatever-wc-had', $handler->restore_selection( 'whatever-wc-had', 'pickup_point' ) );
		}

		// --- get_js_config()'s chosenAddress — issue #274 item 2 ---

		public function test_get_js_config_emits_chosen_address_when_something_is_remembered(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$scope = new Pickup_Handler_Selection_Test_Scope(
				'key',
				static fn( Pickup_Point $point ) => 'msk',
				static fn() => 'msk',
				static fn( string $method_id ) => 'pvz'
			);
			$selection = new Pickup_Handler_Selection_Probe( $scope, new Pickup_Handler_Fake_Session() );
			$selection->remember( 'msk', 'pvz', 'P1', 'Тверская, 1' );

			$handler = new Pickup_Handler_With_Selection_Probe(
				'p',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				$scope,
				$selection,
				[ 'carrier_pickup' ]
			);

			$this->assertSame( 'Тверская, 1', $handler->get_js_config()['chosenAddress'] );
		}

		public function test_get_js_config_chosen_address_is_empty_when_nothing_is_remembered(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$scope = new Pickup_Handler_Selection_Test_Scope(
				'key',
				static fn( Pickup_Point $point ) => 'msk',
				static fn() => 'msk',
				static fn( string $method_id ) => 'pvz'
			);
			$selection = new Pickup_Handler_Selection_Probe( $scope, new Pickup_Handler_Fake_Session() );
			// Nothing remembered for (msk, pvz).

			$handler = new Pickup_Handler_With_Selection_Probe(
				'p',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				$scope,
				$selection,
				[ 'carrier_pickup' ]
			);

			$this->assertSame( '', $handler->get_js_config()['chosenAddress'] );
		}

		/**
		 * The legacy degrade this feature must not fatal on: a session written before #274
		 * shipped has an id but no `address` key at all — the same shape
		 * {@see PickupSelectionTest::test_recall_address_returns_null_for_a_legacy_id_only_entry()}
		 * pins at the `Pickup_Selection` layer, exercised here through the whole
		 * `get_js_config()` seam. The id restore path is asserted alongside it, proving the
		 * degrade is scoped to the address alone — the id keeps restoring exactly as #176 already
		 * guarantees.
		 */
		public function test_get_js_config_chosen_address_degrades_to_empty_for_a_legacy_id_only_entry(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$scope = new Pickup_Handler_Selection_Test_Scope(
				'key',
				static fn( Pickup_Point $point ) => 'msk',
				static fn() => 'msk',
				static fn( string $method_id ) => 'pvz'
			);
			$session = new Pickup_Handler_Fake_Session();
			$session->set( 'key', [ 'msk' => [ 'pvz' => [ 'id' => 'P1', 'seq' => 1 ] ] ] );
			$selection = new Pickup_Handler_Selection_Probe( $scope, $session );

			$handler = new Pickup_Handler_With_Selection_Probe(
				'p',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				$scope,
				$selection,
				[ 'carrier_pickup' ]
			);

			$this->assertSame( 'P1', $handler->restore_selection( '', 'pickup_point' ), 'the id restore path must be unaffected' );
			$this->assertSame(
				'',
				$handler->get_js_config()['chosenAddress'],
				'a legacy id-only entry must degrade to no address, never fatal'
			);
		}

		public function test_get_js_config_chosen_address_is_empty_without_a_scope(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$handler = new Pickup_Handler_With_Selection_Probe(
				'p',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				null,
				null
			);

			$this->assertSame( '', $handler->get_js_config()['chosenAddress'] );
		}

		/**
		 * {@see Selection_Scope::TYPE_ANY} resolves the address of the most recently written
		 * entry, mirroring {@see self::test_restore_selection_type_any_restores_the_most_recently_written_entry()}'s
		 * own id-side proof.
		 */
		public function test_get_js_config_chosen_address_type_any_uses_the_most_recently_written_entry(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$scope = new Pickup_Handler_Selection_Test_Scope(
				'key',
				static fn( Pickup_Point $point ) => 'msk',
				static fn() => 'msk',
				static fn( string $method_id ) => Selection_Scope::TYPE_ANY
			);
			$selection = new Pickup_Handler_Selection_Probe( $scope, new Pickup_Handler_Fake_Session() );
			$selection->remember( 'msk', 'pvz', 'P-PVZ', 'Тверская, 1' );
			$selection->remember( 'msk', 'postamat', 'P-POSTAMAT', 'Ленина, 1' );

			$handler = new Pickup_Handler_With_Selection_Probe(
				'p',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				$scope,
				$selection,
				[ 'carrier_pickup' ]
			);

			$this->assertSame( 'Ленина, 1', $handler->get_js_config()['chosenAddress'] );
		}

		// --- resolve_chosen_address() vs $_POST (issue #308 item 3) ---

		/**
		 * The ordinary case: nothing posted for the field (a fresh page load, GET request) —
		 * `resolve_chosen_address()` falls straight through to the session, exactly as before
		 * this fix. Proves the fix is additive, not a behaviour change for the common path.
		 */
		public function test_get_js_config_chosen_address_uses_the_session_when_nothing_is_posted(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$scope = new Pickup_Handler_Selection_Test_Scope(
				'key',
				static fn( Pickup_Point $point ) => 'msk',
				static fn() => 'msk',
				static fn( string $method_id ) => 'pvz'
			);
			$selection = new Pickup_Handler_Selection_Probe( $scope, new Pickup_Handler_Fake_Session() );
			$selection->remember( 'msk', 'pvz', 'P1', 'Тверская, 1' );

			$handler = new Pickup_Handler_With_Selection_Probe(
				'p',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				$scope,
				$selection,
				[ 'carrier_pickup' ]
			);

			$this->assertSame( 'Тверская, 1', $handler->get_js_config()['chosenAddress'] );
		}

		/**
		 * The fix's core proof: `$_POST` names the SAME id the session remembers an address
		 * for — WooCommerce's own precedence (`$_POST` before `woocommerce_checkout_get_value`)
		 * shows this id, and the remembered address genuinely belongs to it, so it is used.
		 */
		public function test_get_js_config_chosen_address_is_used_when_the_posted_id_matches_the_remembered_one(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$scope = new Pickup_Handler_Selection_Test_Scope(
				'key',
				static fn( Pickup_Point $point ) => 'msk',
				static fn() => 'msk',
				static fn( string $method_id ) => 'pvz'
			);
			$selection = new Pickup_Handler_Selection_Probe( $scope, new Pickup_Handler_Fake_Session() );
			$selection->remember( 'msk', 'pvz', 'P1', 'Тверская, 1' );

			$_POST = [ 'pickup_point' => 'P1' ];

			$handler = new Pickup_Handler_With_Selection_Probe(
				'p',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				$scope,
				$selection,
				[ 'carrier_pickup' ]
			);

			$this->assertSame( 'Тверская, 1', $handler->get_js_config()['chosenAddress'] );
		}

		/**
		 * The bug this fix closes: a POSTED id that DISAGREES with the session's remembered
		 * id for the pair (a stale/second-tab session, or simply a different point) must never
		 * surface the wrong point's address next to the id WooCommerce is about to show — the
		 * id path (via `$_POST`, before `restore_selection()` ever runs) and this method must
		 * describe the SAME point, or neither.
		 */
		public function test_get_js_config_chosen_address_is_empty_when_the_posted_id_disagrees_with_the_remembered_one(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$scope = new Pickup_Handler_Selection_Test_Scope(
				'key',
				static fn( Pickup_Point $point ) => 'msk',
				static fn() => 'msk',
				static fn( string $method_id ) => 'pvz'
			);
			$selection = new Pickup_Handler_Selection_Probe( $scope, new Pickup_Handler_Fake_Session() );
			$selection->remember( 'msk', 'pvz', 'P1', 'Тверская, 1' );

			// A second tab completed an order (forget_all()) and remembered a NEW point since,
			// or simply posted a stale value — either way, this id is NOT what the session
			// has an address on file for.
			$_POST = [ 'pickup_point' => 'P-OTHER' ];

			$handler = new Pickup_Handler_With_Selection_Probe(
				'p',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				$scope,
				$selection,
				[ 'carrier_pickup' ]
			);

			$this->assertSame(
				'',
				$handler->get_js_config()['chosenAddress'],
				'a posted id this handler has no address for must render no address, never P1\'s'
			);
		}

		/**
		 * WooCommerce's OWN precedence check is `! empty( $_POST[ $input ] )` (`class-wc-
		 * checkout.php`, see gotcha `custom-checkout-field-is-empty-on-reload-by-construction`)
		 * — an explicitly empty posted value is treated as "nothing posted", not as "post an
		 * empty id". `resolve_chosen_address()` must apply the identical `''` !== check, or it
		 * would refuse the session address here even though WooCommerce itself falls through
		 * to the session for the id too.
		 */
		public function test_get_js_config_chosen_address_treats_an_explicitly_empty_post_as_nothing_posted(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$scope = new Pickup_Handler_Selection_Test_Scope(
				'key',
				static fn( Pickup_Point $point ) => 'msk',
				static fn() => 'msk',
				static fn( string $method_id ) => 'pvz'
			);
			$selection = new Pickup_Handler_Selection_Probe( $scope, new Pickup_Handler_Fake_Session() );
			$selection->remember( 'msk', 'pvz', 'P1', 'Тверская, 1' );

			$_POST = [ 'pickup_point' => '' ];

			$handler = new Pickup_Handler_With_Selection_Probe(
				'p',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				$scope,
				$selection,
				[ 'carrier_pickup' ]
			);

			$this->assertSame( 'Тверская, 1', $handler->get_js_config()['chosenAddress'] );
		}

		// --- handle_checkout_order_processed() — clearing on order creation ---

		/**
		 * The trap the plan itself calls out: `handle_checkout_order_processed()`
		 * returns EARLY when the plugin has not wired full-point persistence
		 * (`$order_handler`/`$point_field_logical` both null, as every
		 * {@see Pickup_Handler_With_Selection_Probe} in this file constructs). The
		 * selection map must STILL be cleared — the clear must not sit behind that
		 * early return.
		 */
		public function test_handle_checkout_order_processed_clears_the_selection_map_even_without_full_point_persistence(): void {
			$scope = new Pickup_Handler_Selection_Test_Scope(
				'key',
				static fn( Pickup_Point $point ) => 'msk',
				static fn() => '',
				static fn( string $method_id ) => null
			);
			$selection = new Pickup_Handler_Selection_Probe( $scope, new Pickup_Handler_Fake_Session() );
			$selection->remember( 'msk', 'pvz', 'P1' );

			$handler = new Pickup_Handler_With_Selection_Probe(
				'p',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				$scope,
				$selection
			);

			$handler->handle_checkout_order_processed( 1, [], new \WC_Order() );

			$this->assertNull( $selection->recall( 'msk', 'pvz' ), 'the selection map must be cleared on order creation' );
		}

		public function test_handle_checkout_order_processed_without_a_scope_does_not_crash(): void {
			$handler = new Pickup_Handler_With_Selection_Probe(
				'p',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				$this->default_location(),
				null,
				null
			);

			// Must not throw.
			$handler->handle_checkout_order_processed( 1, [], new \WC_Order() );

			$this->assertTrue( true );
		}
	}
}
