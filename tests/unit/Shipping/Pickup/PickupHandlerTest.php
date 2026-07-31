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
 * unmodified pass-through to the active `Map_Provider`) and Task 17 (the
 * `$replace_address` constructor toggle — default on, `billingOnly` unaffected by it,
 * `target` never emitted) and the nine map-provider i18n keys `get_js_config()` now
 * carries.
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

	use Brain\Monkey\Functions;
	use Woodev\Framework\Shipping\Map\Map_Provider;
	use Woodev\Framework\Shipping\Order\Shipping_Order_Handler;
	use Woodev\Framework\Shipping\Pickup\Pickup_Handler;
	use Woodev\Framework\Shipping\Pickup\Pickup_Point;
	use Woodev\Framework\Shipping\Pickup\Point_Query;
	use Woodev\Framework\Shipping\Pickup\Point_Source;
	use Woodev\Tests\Unit\TestCase;

	require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/api/class-api-exception.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/exceptions/class-shipping-exception.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/map/interface-map-provider.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-point.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-point-query.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-constraint-checker.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/interface-point-source.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/order/class-shipping-order-handler.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-plugin-compatibility.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/compatibility/class-order-compatibility.php';

	if ( ! class_exists( '\\WP_REST_Controller' ) ) {
		require_once dirname( __DIR__, 4 ) . '/tests/unit/Shipping/Rest_Api/wp-rest-controller-stub.php';
	}

	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/rest-api/trait-rest-rate-limit.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/rest-api/class-pickup-controller.php';
	require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-handler.php';

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
			return [];
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
	}

	/**
	 * Probe forcing every asset to report as already built, so enqueue_assets()'s
	 * "built" branch can be exercised without writing a real file into the assets
	 * directory. The base {@see Pickup_Handler} (no override) is used for the opposite
	 * case, since the real assets genuinely do not exist on disk yet.
	 */
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
			int $forced_weight
		) {
			parent::__construct( $plugin_id, $field_id, $source, $map_provider );
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
			$cart,
			bool $load_cart_available = false,
			$cart_after_load = null
		) {
			parent::__construct( $plugin_id, $field_id, $source, $map_provider );
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
	 * Probe exercising the REAL posted_payment_method() precedence logic
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
			$session_value
		) {
			parent::__construct( $plugin_id, $field_id, $source, $map_provider );
			$this->session_value = $session_value;
		}

		protected function wc_session_chosen_payment_method() {
			return $this->session_value;
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
		}

		protected function tearDown(): void {
			$_POST = [];
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
				$this->yandex_provider()
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
				$this->yandex_provider()
			) )->get_js_config();

			$this->assertSame( 'bulk', $config['strategy'] );
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
				$this->embedded_provider()
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
				$this->yandex_provider()
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
			$config   = ( new Pickup_Handler( 'p', 'carrier_pickup_point', $source, $provider ) )->get_js_config();

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

			( new Pickup_Handler( 'carrier-x', 'carrier_pickup_point', $source, $provider ) )->get_js_config();

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
			$config   = ( new Pickup_Handler( 'p', 'carrier_pickup_point', $source, $provider ) )->get_js_config();

			$this->assertConfigHasNoObjectsOrClosures( $config );
			$this->assertStringContainsString( 'apikey=REAL-SECRET-KEY', $config['mapConfig']['scriptUrl'] );
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
				$this->yandex_provider()
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
				$this->yandex_provider()
			) )->get_js_config();

			$this->assertFalse( $config['replaceAddress']['billingOnly'] );
		}

		public function test_config_rest_root_uses_the_sanitized_plugin_segment(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->alias(
				static fn( $path ) => 'https://example.test/wp-json/' . $path
			);
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$source  = $this->source_returning( null );
			$handler = new Pickup_Handler( 'carrier!!!', 'carrier_pickup_point', $source, $this->yandex_provider() );
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
				$this->yandex_provider()
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
				$this->yandex_provider()
			) )->get_js_config();

			$this->assertSame(
				[ 'fieldId', 'strategy', 'provider', 'restRoot', 'nonce', 'i18n', 'mapConfig', 'replaceAddress' ],
				array_keys( $config )
			);
		}

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
				$this->yandex_provider()
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
				$this->yandex_provider()
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
		public function test_config_i18n_carries_all_nine_map_provider_keys_non_empty(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$i18n = ( new Pickup_Handler(
				'p',
				'carrier_pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider()
			) )->get_js_config()['i18n'];

			// Exact expected VALUES, not just key presence — two adjacent keys (e.g.
			// `phone`/`workTime`) swapping their Russian text would leave every key present
			// and non-empty, so a presence-only check cannot catch it. Pinning the exact
			// string per key is what does.
			$expected = [
				'search'         => 'Поиск по адресу',
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

		// -------------------------------------------------------------------------
		// get_settings_fields() (SP-5 Task 16) — pure pass-through to the active
		// Map_Provider's own get_settings_fields(), never reshaped
		// -------------------------------------------------------------------------

		/**
		 * Coordination proof with the REAL Yandex_Map_Provider (not the test double, which
		 * hardcodes `[]`): proves the handler passes the descriptor through UNCHANGED —
		 * `assertSame()` against the same live `$provider` instance's own return value
		 * means both sides move together, so this test cannot see a reshape that mutates
		 * the provider's own field content (e.g. dropping `description`, flipping
		 * `required`, or rebuilding the field in the WooCommerce `form_fields` shape) —
		 * such a mutation would corrupt both sides identically and still compare equal.
		 * That content is genuinely pinned, just not here: see
		 * `tests/unit/Shipping/Map/MapProviderRegistryTest.php` for the assertions against
		 * the descriptor's actual shape and values.
		 */
		public function test_get_settings_fields_passes_the_yandex_providers_descriptor_through_unmodified(): void {
			require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-setting.php';
			require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/map/class-yandex-map-provider.php';

			Functions\when( 'apply_filters' )->returnArg( 2 );

			$provider = new \Woodev\Framework\Shipping\Map\Yandex_Map_Provider( 'FALLBACK-KEY' );
			$handler  = new Pickup_Handler( 'p', 'carrier_pickup_point', $this->source_returning( null ), $provider );

			$this->assertArrayHasKey( 'map_api_key', $handler->get_settings_fields() );
			$this->assertSame( $provider->get_settings_fields(), $handler->get_settings_fields() );
		}

		/**
		 * A plugin using the embedded provider gains no field at all — the handler must
		 * not invent one nor merge in a placeholder shape of its own.
		 */
		public function test_get_settings_fields_is_empty_for_the_embedded_provider(): void {
			require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/map/class-embedded-map-provider.php';

			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'untrailingslashit' )->alias( static fn( string $value ) => rtrim( $value, '/' ) );

			$provider = new \Woodev\Framework\Shipping\Map\Embedded_Map_Provider(
				'https://carrier.example/widget',
				'https://carrier.example'
			);
			$handler = new Pickup_Handler( 'p', 'carrier_pickup_point', $this->source_returning( null ), $provider );

			$this->assertSame( [], $handler->get_settings_fields() );
			$this->assertArrayNotHasKey( 'map_api_key', $handler->get_settings_fields() );
		}

		// -------------------------------------------------------------------------
		// replaceAddress toggle (SP-5 Task 17) — the `$replace_address` constructor arg
		// -------------------------------------------------------------------------

		/**
		 * Default-on proof: a caller that never mentions `$replace_address` at all — every
		 * existing caller in this suite, and every caller wired before Task 17 shipped —
		 * must keep getting `enabled: true`. The flag is purely additive.
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
				$this->yandex_provider()
			);

			$this->assertTrue( $handler->get_js_config()['replaceAddress']['enabled'] );
		}

		/**
		 * `$replace_address` is appended AFTER the `$order_handler` / `$point_field_logical`
		 * pair (see the constructor's own docblock for why), so disabling it here means
		 * passing `null, null` first — exactly what the omitted-persistence case already
		 * defaults to.
		 */
		public function test_replace_address_false_disables_it(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );

			$handler = new Pickup_Handler(
				'p',
				'carrier_pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				null,
				null,
				false
			);

			$this->assertFalse( $handler->get_js_config()['replaceAddress']['enabled'] );
		}

		/**
		 * `billingOnly` must keep mirroring the store setting regardless of whether
		 * replacement itself is on or off, and `target` must never appear — a mutant that
		 * ties the two flags together, or that resurrects a resolved `target` key once the
		 * toggle exists, must fail this.
		 */
		public function test_replace_address_billing_only_still_mirrors_the_store_setting_when_disabled(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
			Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( true );

			$handler = new Pickup_Handler(
				'p',
				'carrier_pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider(),
				null,
				null,
				false
			);

			$config = $handler->get_js_config();

			$this->assertSame( [ 'enabled' => false, 'billingOnly' => true ], $config['replaceAddress'] );
			$this->assertArrayNotHasKey( 'target', $config['replaceAddress'] );
		}

		/**
		 * Existing callers wiring full-point persistence (`$order_handler` +
		 * `$point_field_logical`) must keep getting the default `enabled: true` — proves
		 * the new trailing parameter did not silently shift meaning for the pair that
		 * already occupied that constructor slot.
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

			$handler = new Pickup_Handler( 'p', 'carrier_pickup_point', $source, $this->yandex_provider() );

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
			$handler = new Pickup_Handler( 'p', 'f', $this->source_returning( $point ), $this->yandex_provider() );

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
			$handler = new Pickup_Handler( 'p', 'f', $this->source_returning( $point ), $this->yandex_provider() );

			$this->assertTrue( $handler->validate_selected_point( $point, 'bacs', 0 ) );
		}

		// -------------------------------------------------------------------------
		// validate_posted_point() — the three outcomes, including a non-Woodev_API_Exception
		// -------------------------------------------------------------------------

		public function test_validate_posted_point_returns_true_when_the_fetched_point_is_allowed(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );

			$point   = $this->point();
			$handler = new Pickup_Handler( 'p', 'f', $this->source_returning( $point ), $this->yandex_provider() );

			$this->assertTrue( $handler->validate_posted_point( 'P1', 'bacs', 0 ) );
		}

		public function test_validate_posted_point_returns_false_when_the_fetched_point_is_blocked(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( '__' )->returnArg( 1 );
			Functions\when( 'number_format_i18n' )->returnArg( 1 );
			Functions\when( 'wc_add_notice' )->justReturn( true );

			$point   = $this->point( [ 'accepts_cod' => false ] );
			$handler = new Pickup_Handler( 'p', 'f', $this->source_returning( $point ), $this->yandex_provider() );

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

			$handler = new Pickup_Handler( 'p', 'f', $this->source_returning( null ), $this->yandex_provider() );

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
				$this->yandex_provider()
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
				$this->yandex_provider()
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
				$this->yandex_provider()
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
				$this->yandex_provider()
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
				$this->yandex_provider()
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
				$this->yandex_provider()
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
				$this->yandex_provider()
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
				$this->yandex_provider()
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
			$handler = new Pickup_Handler( 'p', 'pickup_point', $source, $this->yandex_provider() );

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
			$handler = new Pickup_Handler( 'p', 'pickup_point', $source, $this->yandex_provider() );

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

			$handler = new Pickup_Handler( 'p', 'pickup_point', $source, $this->yandex_provider() );
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

			$handler = new Pickup_Handler( 'p', 'pickup_point', $source, $this->yandex_provider() );
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

			$handler = new Pickup_Handler_Weight_Probe( 'p', 'pickup_point', $source, $this->yandex_provider(), 0 );
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

			$handler = new Pickup_Handler_Weight_Probe( 'p', 'pickup_point', $source, $this->yandex_provider(), 2500 );

			Functions\expect( 'wc_add_notice' )->once();
			$handler->handle_checkout_process();
		}

		public function test_handle_checkout_process_forwards_the_converted_cart_weight_when_within_the_limit(): void {
			Functions\when( 'apply_filters' )->returnArg( 2 );

			$point  = $this->point( [ 'max_weight' => 2000 ] );
			$source = $this->source_returning( $point );
			$_POST  = [ 'pickup_point' => 'P1', 'payment_method' => 'bacs' ];

			$handler = new Pickup_Handler_Weight_Probe( 'p', 'pickup_point', $source, $this->yandex_provider(), 1500 );

			Functions\expect( 'wc_add_notice' )->never();
			$handler->handle_checkout_process();
		}

		// -------------------------------------------------------------------------
		// posted_payment_method() — $_POST wins, the session fallback (via the
		// wc_session_chosen_payment_method() seam) is the GET-request fallback
		// (SP-5 rig e2e BLOCKING fix: the points/detail routes are GET, so $_POST
		// is always empty there — see the method's own docblock for why returning
		// '' unconditionally silently disabled the §4.5 COD gate). Exercised via
		// Pickup_Handler_Session_Probe, never via `Functions\when( 'WC' )` — see
		// that probe's own docblock for why mocking WC() itself is unsafe here.
		// -------------------------------------------------------------------------

		/**
		 * Precedence proof: a posted value must win even when the session disagrees
		 * with it — mirrors the real `woocommerce_checkout_process` call site, where
		 * `$_POST` is authoritative. Value-mutant guard: a mutant flipping the
		 * precedence (session wins) would return the probe's 'cod' instead.
		 */
		public function test_posted_payment_method_prefers_the_posted_value_over_the_session(): void {
			$_POST = [ 'payment_method' => 'bacs' ];

			$handler = new Pickup_Handler_Session_Probe(
				'p',
				'f',
				$this->source_returning( null ),
				$this->yandex_provider(),
				'cod'
			);

			$this->assertSame( 'bacs', $handler->posted_payment_method() );
		}

		/**
		 * The BLOCKING fix itself: a GET points/detail request never populates
		 * `$_POST`, so the session — written by WooCommerce's own
		 * `update_order_review` ajax handler the instant the customer picks a
		 * method — is the only server-side source of the live choice.
		 */
		public function test_posted_payment_method_falls_back_to_the_session_when_post_is_empty(): void {
			$_POST = [];

			$handler = new Pickup_Handler_Session_Probe(
				'p',
				'f',
				$this->source_returning( null ),
				$this->yandex_provider(),
				'cod'
			);

			$this->assertSame( 'cod', $handler->posted_payment_method() );
		}

		/**
		 * Value-mutant guard: proves the fallback returns the probe's ACTUAL
		 * value, not a hardcoded 'cod' literal — the previous test alone cannot
		 * distinguish a real forward from a mutant that always answers 'cod'.
		 */
		public function test_posted_payment_method_returns_the_actual_session_value_not_a_hardcoded_one(): void {
			$_POST = [];

			$handler = new Pickup_Handler_Session_Probe(
				'p',
				'f',
				$this->source_returning( null ),
				$this->yandex_provider(),
				'bacs'
			);

			$this->assertSame( 'bacs', $handler->posted_payment_method() );
		}

		/**
		 * No session value available (WC() unavailable, no session started, or no
		 * choice made yet — {@see Pickup_Handler::wc_session_chosen_payment_method()}
		 * returns `null` in all three cases) must degrade to permissive, never
		 * fatal. `''` is never in {@see Constraint_Checker}'s COD method list.
		 */
		public function test_posted_payment_method_is_empty_when_the_session_value_is_absent(): void {
			$_POST = [];

			$handler = new Pickup_Handler_Session_Probe(
				'p',
				'f',
				$this->source_returning( null ),
				$this->yandex_provider(),
				null
			);

			$this->assertSame( '', $handler->posted_payment_method() );
		}

		/**
		 * A non-scalar session value (defensive: the real `WC_Session::get()`
		 * should never return one, but nothing prevents a plugin from writing an
		 * array under this key) must degrade to permissive rather than crash on
		 * the `(string)` cast — same `is_scalar()` guard as `$_POST`.
		 */
		public function test_posted_payment_method_is_empty_for_a_non_scalar_session_value(): void {
			$_POST = [];

			$handler = new Pickup_Handler_Session_Probe(
				'p',
				'f',
				$this->source_returning( null ),
				$this->yandex_provider(),
				[ 'unexpected' => 'array' ]
			);

			$this->assertSame( '', $handler->posted_payment_method() );
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
			$handler = new Pickup_Handler_Probe( 'p', 'f', $this->source_returning( null ), $this->yandex_provider() );

			$this->assertNull( $handler->wc_session_chosen_payment_method_public() );
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
			$probe = new Pickup_Handler_Probe( 'p', 'f', $this->source_returning( null ), $this->yandex_provider() );

			$this->assertSame( 0, $probe->current_cart_weight_grams_public() );
		}

		/**
		 * `wc_cart()`'s OWN default body (not the probe) — proves the seam itself
		 * degrades to `null`, not a fatal, when WC() genuinely does not exist.
		 */
		public function test_wc_cart_is_null_when_wc_is_unavailable(): void {
			$handler = new Pickup_Handler_Probe( 'p', 'f', $this->source_returning( null ), $this->yandex_provider() );

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
				new Shipping_Order_Handler( [ 'pickup_full' => 'cdek_full_point' ] ),
				'pickup_full'
			);
			$first->handle_checkout_order_processed( 1, [], new \WC_Order() );

			$second = new Pickup_Handler(
				'p',
				'pickup_point',
				$this->source_returning( $point ),
				$this->yandex_provider(),
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
			$handler = new Pickup_Handler( 'p', 'pickup_point', $source, $this->yandex_provider() );
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

		public function test_register_wires_the_expected_hooks(): void {
			Functions\expect( 'add_action' )
				->once()
				->with( 'wp_enqueue_scripts', \Mockery::type( 'array' ) );

			Functions\expect( 'add_action' )
				->once()
				->with( 'rest_api_init', \Mockery::type( 'array' ) );

			Functions\expect( 'add_action' )
				->once()
				->with( 'woocommerce_checkout_process', \Mockery::type( 'array' ) );

			Functions\expect( 'add_action' )
				->once()
				->with( 'woocommerce_checkout_order_processed', \Mockery::type( 'array' ), 10, 3 );

			$handler = new Pickup_Handler(
				'p',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider()
			);
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
				$this->yandex_provider()
			);
			$handler->register_rest();

			$this->assertContains( '/shipping/pickup/carrier/points', $registered );
			$this->assertContains( '/shipping/pickup/carrier/points/(?P<id>[^/]+)', $registered );
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
				$this->yandex_provider()
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

			$styles = [];
			Functions\when( 'wp_enqueue_style' )->alias(
				static function ( $handle, $src, $deps, $ver ) use ( &$styles ) {
					$styles[ $handle ] = $src;
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
				$this->yandex_provider()
			);
			$handler->enqueue_assets();

			$this->assertArrayHasKey( 'woodev-pickup-modal', $scripts );
			$this->assertStringContainsString( 'pickup-modal.js', $scripts['woodev-pickup-modal']['src'] );
			$this->assertSame( [], $scripts['woodev-pickup-modal']['deps'] );

			$this->assertArrayHasKey( 'woodev-pickup-datasource', $scripts );
			$this->assertStringContainsString( 'pickup-datasource.js', $scripts['woodev-pickup-datasource']['src'] );
			$this->assertSame( [], $scripts['woodev-pickup-datasource']['deps'] );

			// map-provider-yandex.js (SP-5 Tasks 13/14) exists on disk — see the method
			// docblock above for why this flipped from assertArrayNotHasKey().
			$this->assertArrayHasKey( 'woodev-pickup-map-provider-yandex', $scripts );
			$this->assertStringContainsString(
				'map-provider-yandex.js',
				$scripts['woodev-pickup-map-provider-yandex']['src']
			);

			$this->assertArrayHasKey( 'woodev-pickup-mount', $scripts );
			$this->assertStringContainsString( 'pickup-mount.js', $scripts['woodev-pickup-mount']['src'] );
			$this->assertSame(
				[ 'jquery', 'woodev-pickup-modal', 'woodev-pickup-datasource', 'woodev-pickup-map-provider-yandex' ],
				$scripts['woodev-pickup-mount']['deps']
			);

			// pickup.css (SP-5 Task 15) exists on disk — see the method docblock above for
			// why this flipped from an `expect( 'wp_enqueue_style' )->never()` expectation.
			$this->assertArrayHasKey( 'woodev-pickup-styles', $styles );
			$this->assertStringContainsString( 'pickup.css', $styles['woodev-pickup-styles'] );

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

			$styles = [];
			Functions\when( 'wp_enqueue_style' )->alias(
				static function ( $handle, $src, $deps, $ver ) use ( &$styles ) {
					$styles[ $handle ] = $src;
				}
			);

			Functions\when( 'wp_localize_script' )->justReturn( true );

			$handler = new Pickup_Handler_Assets_Built_Probe(
				'p',
				'pickup_point',
				$this->source_returning( null ),
				$this->yandex_provider()
			);
			$handler->enqueue_assets();

			$this->assertArrayHasKey( 'woodev-pickup-modal', $scripts );
			$this->assertStringContainsString( 'pickup-modal.js', $scripts['woodev-pickup-modal']['src'] );

			$this->assertArrayHasKey( 'woodev-pickup-datasource', $scripts );
			$this->assertStringContainsString( 'pickup-datasource.js', $scripts['woodev-pickup-datasource']['src'] );

			$this->assertArrayHasKey( 'woodev-pickup-map-provider-yandex', $scripts );
			$this->assertStringContainsString(
				'map-provider-yandex.js',
				$scripts['woodev-pickup-map-provider-yandex']['src']
			);

			$this->assertArrayHasKey( 'woodev-pickup-mount', $scripts );
			$this->assertStringContainsString( 'pickup-mount.js', $scripts['woodev-pickup-mount']['src'] );
			$this->assertSame(
				[ 'jquery', 'woodev-pickup-modal', 'woodev-pickup-datasource', 'woodev-pickup-map-provider-yandex' ],
				$scripts['woodev-pickup-mount']['deps']
			);

			$this->assertArrayHasKey( 'woodev-pickup-styles', $styles );
			$this->assertStringContainsString( 'pickup.css', $styles['woodev-pickup-styles'] );
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
				$this->yandex_provider()
			);
			$handler->enqueue_assets();

			$this->assertCount( 1, $localized );
			[ $handle, $object_name, $data ] = $localized[0];
			$this->assertSame( 'woodev-pickup-mount', $handle );
			$this->assertSame( 'woodev_pickup_config_carrier_x', $object_name );
			$this->assertSame( 'pickup_point', $data['fieldId'] );
		}
	}
}
