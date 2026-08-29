<?php
/**
 * Guards on filter RETURN values at the shipping-method sites — #613 tranche 2, from
 * the #599 audit (`woodev/shipping-method/`).
 *
 * The rule applied, settled in s100 and reaffirmed on #613: degrade to a safe default —
 * always the PRE-FILTER value; never throw, and never disable a protection.
 *
 * Every site gets a PAIR:
 *   - a garbage return must not fatal, and the pre-filter value must survive;
 *   - a legitimate return must still be HONOURED.
 * The second half is what makes the pair worth writing: a guard that simply ignores the
 * filter passes the first test and breaks the hook.
 *
 * @package Woodev\Tests\Unit
 */

namespace {

	if ( ! class_exists( 'WC_Shipping_Method', false ) ) {
		/**
		 * Minimal WooCommerce shipping method base. `Shipping_Method` extends this
		 * directly (`extends \WC_Shipping_Method`), so every property it reads off
		 * `$this` without going through its own constructor must be declared here.
		 */
		class WC_Shipping_Method {

			/** @var string */
			public $id;

			/**
			 * Typed to match `ShippingMethodBoxPackingTest_WC_Shipping_Method_Stub`'s own
			 * `public array $supports` — whichever file's `class_exists( 'WC_Shipping_Method',
			 * false )` guard runs first during suite collection wins, so the two stubs must
			 * declare this property identically or the loser's subclass fails to override it.
			 *
			 * @var array
			 */
			public array $supports = [];

			/** @var array */
			public $instance_form_fields = [];

			/** @var array */
			public $settings = [];

			/** @var string */
			public $title = '';

			/**
			 * @param string $feature feature flag.
			 * @return bool
			 */
			public function supports( $feature ) {
				return in_array( $feature, $this->supports, true );
			}

			/**
			 * @param string $key     option key.
			 * @param mixed  $default fallback.
			 * @return mixed
			 */
			public function get_option( $key, $default = null ) {
				return $this->settings[ $key ] ?? $default;
			}

			/** @return string */
			public function get_title() {
				return $this->title;
			}
		}
	}

	if ( ! class_exists( 'WC_Integration', false ) ) {
		/**
		 * Minimal WooCommerce integration base. `Shipping_Integration` extends this
		 * directly (`extends \WC_Integration`).
		 */
		class WC_Integration {

			/** @var string */
			public $id;

			/** @var array */
			public $form_fields = [];

			/** @var string */
			public $method_title = '';

			/** @var string */
			public $method_description = '';

			/** @var array */
			public $settings = [];
		}
	}
}

namespace Woodev\Tests\Unit\Shipping {

	use Brain\Monkey\Functions;
	use Mockery;
	use Woodev\Framework\Shipping\Map\Map_Provider;
	use Woodev\Framework\Shipping\Pickup\Pickup_Handler;
	use Woodev\Framework\Shipping\Pickup\Point_Source;
	use Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab;
	use Woodev\Framework\Shipping\Settings\Shipping_Integration;
	use Woodev\Framework\Shipping\Shipping_Method;
	use Woodev\Framework\Shipping\Shipping_Plugin;
	use Woodev\Framework\Shipping\Shipping_Rate;
	use Woodev\Tests\Unit\TestCase;

	/**
	 * Minimal Shipping_Plugin double. Bypasses the real constructor (which calls
	 * includes()/add_hooks()) entirely, mirroring Woodev_Payment_Gateway's own test
	 * doubles in PaymentGatewayFilterReturnGuardsTest.
	 */
	class Woodev_Test_Shipping_Plugin_For_Guards extends Shipping_Plugin {

		public function __construct() {}

		/** @return array */
		protected function get_shipping_method_classes(): array {
			return [];
		}

		/** @return string */
		protected function get_file() {
			return __FILE__;
		}

		/** @return string */
		public function get_plugin_name() {
			return 'Guards Shipping Plugin';
		}

		/** @return int */
		public function get_download_id() {
			return 0;
		}

		/** @return string */
		public function get_id() {
			return 'guards-shipping';
		}

		/** @return string */
		public function get_id_underscored() {
			return 'guards_shipping';
		}

		/** @return null */
		public function get_api(): ?\Woodev\Framework\Shipping\Api\Shipping_API {
			return null;
		}
	}

	/**
	 * Minimal Shipping_Method double. `$id` is declared directly (the trap this slice
	 * shares with the payment-gateway one: `WC_Shipping_Method` is a bare test stub, so
	 * a double must declare every property it reads off `$this`).
	 *
	 * `$supports`, `$instance_form_fields` and `add_rate()` are ALSO redeclared here,
	 * even though the `WC_Shipping_Method` stub above already provides them: at least
	 * one other test file in this suite (`ShippingMethodBoxPackingTest.php`) declares
	 * its OWN `if ( ! class_exists( 'WC_Shipping_Method', false ) )`-guarded stub, and
	 * PHPUnit's suite collection includes every test file before any test runs — so
	 * whichever file's guard runs first wins, and that file's stub has neither
	 * property nor `add_rate()`. Redeclaring on the double itself makes it correct
	 * regardless of suite load order, instead of silently depending on winning a race.
	 */
	class Woodev_Test_Shipping_Method_For_Guards extends Shipping_Method {

		/** @var string */
		public $id = 'guards-method';

		/** @var array */
		public array $supports = [];

		/** @var array */
		public $instance_form_fields = [];

		/** @var array<int, array> every args array handed to add_rate(). */
		public array $added_rates = [];

		/** @var Shipping_Plugin */
		private Shipping_Plugin $test_plugin;

		/** @var Shipping_Rate|null what rate_package() returns when calculate_rate() runs. */
		public ?Shipping_Rate $rate_package_return = null;

		public function __construct() {
			$this->test_plugin = new Woodev_Test_Shipping_Plugin_For_Guards();
		}

		/**
		 * @param array $args rate args.
		 * @return void
		 */
		public function add_rate( $args = [] ) {
			$this->added_rates[] = $args;
		}

		/** @return string */
		public static function get_method_id(): string {
			return 'guards-method';
		}

		/** @return string */
		public function get_delivery_type(): string {
			return self::TYPE_COURIER;
		}

		/** @return array */
		protected function get_method_form_fields(): array {
			return [];
		}

		/** @return Shipping_Plugin */
		protected function get_plugin(): Shipping_Plugin {
			return $this->test_plugin;
		}

		/**
		 * @param array                      $package unused.
		 * @param \Woodev_Packer_Result|null $packed  unused.
		 * @return Shipping_Rate|null
		 */
		protected function rate_package( array $package, ?\Woodev_Packer_Result $packed ): ?Shipping_Rate {
			return $this->rate_package_return;
		}

		/**
		 * Test-only exposure of the protected guard site — production behaviour is
		 * unchanged, this only lets the test reach it directly.
		 *
		 * @param array $package package data.
		 * @return bool
		 */
		public function expose_is_available_for_package( array $package ): bool {
			return $this->is_available_for_package( $package );
		}
	}

	/**
	 * Minimal Shipping_Integration double.
	 */
	class Woodev_Test_Shipping_Integration_For_Guards extends Shipping_Integration {

		/** @var string */
		public $id = 'guards_shipping';

		/** @var array */
		public $form_fields = [];

		public function __construct() {}

		/** @return array */
		protected function get_method_form_fields(): array {
			return [];
		}

		/** @return Shipping_Plugin */
		protected function init_plugin(): Shipping_Plugin {
			return new Woodev_Test_Shipping_Plugin_For_Guards();
		}
	}

	/**
	 * @coversNothing
	 */
	final class ShippingMethodFilterReturnGuardsTest extends TestCase {

		/**
		 * @return void
		 */
		protected function setUp(): void {
			parent::setUp();

			Functions\when( 'is_admin' )->justReturn( false );
			Functions\when( 'admin_url' )->returnArg( 1 );
			Functions\when( 'sanitize_file_name' )->returnArg( 1 );
			Functions\when( 'wp_hash' )->returnArg( 1 );
			Functions\when( 'get_option' )->justReturn( null );
			Functions\when( 'wp_create_nonce' )->justReturn( 'test-nonce' );
			Functions\when( 'rest_url' )->alias(
				static function ( $path ) {
					return 'https://example.test/wp-json/' . ltrim( $path, '/' );
				}
			);
			Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );
			Functions\when( 'wp_parse_args' )->alias(
				static function ( $args, $defaults = [] ) {
					return array_merge( $defaults, (array) $args );
				}
			);
			Functions\when( 'sanitize_hex_color' )->alias(
				static function ( $color ) {
					return is_string( $color ) && preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $color )
						? $color
						: null;
				}
			);

			Shipping_Settings_Tab::reset_for_tests();
		}

		/**
		 * @return void
		 */
		protected function tearDown(): void {
			Shipping_Settings_Tab::reset_for_tests();
			parent::tearDown();
		}

		/* ------------------------------------------------------------------ *
		 * Site 1 — Shipping_Method::init_form_fields()
		 * `woodev_shipping_method_{id}_form_fields`
		 * ------------------------------------------------------------------ */

		/**
		 * @return void
		 */
		public function test_method_form_fields_falls_back_when_the_filter_returns_a_non_array(): void {
			$this->filter_returns( 'woodev_shipping_method_guards-method_form_fields', 'not an array' );

			$method = new Woodev_Test_Shipping_Method_For_Guards();
			$method->init_form_fields();

			$this->assertArrayHasKey( 'title', $method->instance_form_fields );
			$this->assertArrayHasKey( 'description', $method->instance_form_fields );
		}

		/**
		 * The control.
		 *
		 * @return void
		 */
		public function test_method_form_fields_is_honoured_when_it_returns_an_array(): void {
			$this->filter_returns( 'woodev_shipping_method_guards-method_form_fields', [ 'mine' => [ 'type' => 'text' ] ] );

			$method = new Woodev_Test_Shipping_Method_For_Guards();
			$method->init_form_fields();

			$this->assertSame( [ 'mine' => [ 'type' => 'text' ] ], $method->instance_form_fields );
		}

		/* ------------------------------------------------------------------ *
		 * Site 2 — Shipping_Method::calculate_shipping()
		 * `woodev_shipping_method_pre_calculate_rate`
		 * ------------------------------------------------------------------ */

		/**
		 * @return void
		 */
		public function test_pre_calculate_rate_falls_back_when_the_filter_returns_garbage(): void {
			$this->filter_returns( 'woodev_shipping_method_pre_calculate_rate', 'garbage' );

			$method                      = new Woodev_Test_Shipping_Method_For_Guards();
			$method->rate_package_return = new Shipping_Rate( 'guards-method', 'rate-1', 'Rate One', '100' );

			$method->calculate_shipping( [] );

			// Coerced to null, so calculate_rate() ran normally and ITS rate was added.
			$this->assertSame( [ $method->rate_package_return->to_array() ], $method->added_rates );
		}

		/**
		 * The control: a real Shipping_Rate short-circuits calculate_rate() entirely.
		 *
		 * @return void
		 */
		public function test_pre_calculate_rate_is_honoured_when_it_returns_a_real_rate(): void {
			$legit_rate = new Shipping_Rate( 'guards-method', 'legit-1', 'Legit Rate', '250' );

			$this->filter_returns( 'woodev_shipping_method_pre_calculate_rate', $legit_rate );

			// Deliberately left null: if the guard wrongly called calculate_rate() anyway,
			// rate_package() would return null and no rate would be added at all.
			$method = new Woodev_Test_Shipping_Method_For_Guards();

			$method->calculate_shipping( [] );

			$this->assertSame( [ $legit_rate->to_array() ], $method->added_rates );
		}

		/* ------------------------------------------------------------------ *
		 * Site 3 — Shipping_Method::calculate_shipping()
		 * `woodev_shipping_method_calculated_rate` — the most severe site in the
		 * slice: the old code checked truthiness only and then called
		 * $rate->to_array(), a fatal while a customer is calculating shipping.
		 * ------------------------------------------------------------------ */

		/**
		 * @return void
		 */
		public function test_calculated_rate_falls_back_when_the_filter_returns_garbage(): void {
			$this->filter_returns( 'woodev_shipping_method_calculated_rate', 'garbage' );

			$method                      = new Woodev_Test_Shipping_Method_For_Guards();
			$method->rate_package_return = new Shipping_Rate( 'guards-method', 'rate-2', 'Rate Two', '150' );

			$method->calculate_shipping( [] );

			$this->assertSame( [ $method->rate_package_return->to_array() ], $method->added_rates );
		}

		/**
		 * The control.
		 *
		 * @return void
		 */
		public function test_calculated_rate_is_honoured_when_it_returns_a_real_rate(): void {
			$replacement = new Shipping_Rate( 'guards-method', 'replacement', 'Replacement Rate', '999' );

			$this->filter_returns( 'woodev_shipping_method_calculated_rate', $replacement );

			$method                      = new Woodev_Test_Shipping_Method_For_Guards();
			$method->rate_package_return = new Shipping_Rate( 'guards-method', 'rate-3', 'Rate Three', '150' );

			$method->calculate_shipping( [] );

			$this->assertSame( [ $replacement->to_array() ], $method->added_rates );
		}

		/* ------------------------------------------------------------------ *
		 * Site 4 — Shipping_Method::is_available_for_package()
		 * `woodev_shipping_{id}_is_available`
		 * ------------------------------------------------------------------ */

		/**
		 * @return void
		 */
		public function test_is_available_falls_back_when_the_filter_returns_a_non_bool(): void {
			$this->filter_returns( 'woodev_shipping_guards-method_is_available', 'not a bool' );

			$method = new Woodev_Test_Shipping_Method_For_Guards();

			$this->assertTrue( $method->expose_is_available_for_package( [] ) );
		}

		/**
		 * The control.
		 *
		 * @return void
		 */
		public function test_is_available_is_honoured_when_it_returns_a_bool(): void {
			$this->filter_returns( 'woodev_shipping_guards-method_is_available', false );

			$method = new Woodev_Test_Shipping_Method_For_Guards();

			$this->assertFalse( $method->expose_is_available_for_package( [] ) );
		}

		/* ------------------------------------------------------------------ *
		 * Site 5 — Shipping_Integration::init_form_fields()
		 * `woodev_shipping_plugin_settings_{id}_form_fields`
		 * ------------------------------------------------------------------ */

		/**
		 * @return void
		 */
		public function test_settings_form_fields_falls_back_when_the_filter_returns_a_non_array(): void {
			$this->filter_returns( 'woodev_shipping_plugin_settings_guards_shipping_form_fields', 'not an array' );

			$integration = new Woodev_Test_Shipping_Integration_For_Guards();
			$integration->init_form_fields();

			$this->assertArrayHasKey( 'enable_debug', $integration->form_fields );
		}

		/**
		 * The control.
		 *
		 * @return void
		 */
		public function test_settings_form_fields_is_honoured_when_it_returns_an_array(): void {
			$this->filter_returns( 'woodev_shipping_plugin_settings_guards_shipping_form_fields', [ 'mine' => [ 'type' => 'text' ] ] );

			$integration = new Woodev_Test_Shipping_Integration_For_Guards();
			$integration->init_form_fields();

			$this->assertSame( [ 'mine' => [ 'type' => 'text' ] ], $integration->form_fields );
		}

		/* ------------------------------------------------------------------ *
		 * Site 6 — Shipping_Plugin::register_shipping_methods()
		 * `woodev_shipping_plugin_method_classes` — not a fatal, but a silent
		 * no-op that makes every shipping method vanish from checkout.
		 * ------------------------------------------------------------------ */

		/**
		 * @return void
		 */
		public function test_method_classes_falls_back_when_the_filter_returns_a_non_array(): void {
			$this->filter_returns( 'woodev_shipping_plugin_method_classes', 'not an array' );

			$plugin = new Woodev_Test_Shipping_Plugin_For_Guards();
			$result = $plugin->register_shipping_methods( [ 'seed' => 'Seed_Class' ] );

			$this->assertSame( [ 'seed' => 'Seed_Class' ], $result );
		}

		/**
		 * The control: a real class list is actually registered.
		 *
		 * @return void
		 */
		public function test_method_classes_is_honoured_when_it_returns_an_array(): void {
			$this->filter_returns(
				'woodev_shipping_plugin_method_classes',
				[ Woodev_Test_Shipping_Method_For_Guards::class ]
			);

			$plugin = new Woodev_Test_Shipping_Plugin_For_Guards();
			$result = $plugin->register_shipping_methods( [] );

			$this->assertSame( [ 'guards-method' => Woodev_Test_Shipping_Method_For_Guards::class ], $result );
		}

		/* ------------------------------------------------------------------ *
		 * Site 7 — Shipping_Plugin::register_shipping_methods()
		 * `woodev_shipping_plugin_registered_methods`
		 * ------------------------------------------------------------------ */

		/**
		 * @return void
		 */
		public function test_registered_methods_falls_back_when_the_filter_returns_a_non_array(): void {
			$this->filter_returns( 'woodev_shipping_plugin_registered_methods', 'not an array' );

			$plugin = new Woodev_Test_Shipping_Plugin_For_Guards();
			$result = $plugin->register_shipping_methods( [ 'seed' => 'Seed_Class' ] );

			$this->assertSame( [ 'seed' => 'Seed_Class' ], $result );
		}

		/**
		 * The control.
		 *
		 * @return void
		 */
		public function test_registered_methods_is_honoured_when_it_returns_an_array(): void {
			$this->filter_returns( 'woodev_shipping_plugin_registered_methods', [ 'replaced' => 'Replaced_Class' ] );

			$plugin = new Woodev_Test_Shipping_Plugin_For_Guards();
			$result = $plugin->register_shipping_methods( [ 'seed' => 'Seed_Class' ] );

			$this->assertSame( [ 'replaced' => 'Replaced_Class' ], $result );
		}

		/* ------------------------------------------------------------------ *
		 * Site 8 — Shipping_Plugin::get_accepted_currencies()
		 * `woodev_shipping_plugin_{id}_accepted_currencies`
		 * ------------------------------------------------------------------ */

		/**
		 * @return void
		 */
		public function test_accepted_currencies_falls_back_when_the_filter_returns_a_non_array(): void {
			$this->filter_returns( 'woodev_shipping_plugin_guards_shipping_accepted_currencies', 'not an array' );

			$plugin = new Woodev_Test_Shipping_Plugin_For_Guards();

			$this->assertSame( [], $plugin->get_accepted_currencies() );
		}

		/**
		 * The control.
		 *
		 * @return void
		 */
		public function test_accepted_currencies_is_honoured_when_it_returns_an_array(): void {
			$this->filter_returns( 'woodev_shipping_plugin_guards_shipping_accepted_currencies', [ 'USD' ] );

			$plugin = new Woodev_Test_Shipping_Plugin_For_Guards();

			$this->assertSame( [ 'USD' ], $plugin->get_accepted_currencies() );
		}

		/* ------------------------------------------------------------------ *
		 * Site 9 — Shipping_Plugin::get_accepted_countries()
		 * `woodev_shipping_plugin_{id}_accepted_countries` — sits on the
		 * checkout availability path.
		 * ------------------------------------------------------------------ */

		/**
		 * @return void
		 */
		public function test_accepted_countries_falls_back_when_the_filter_returns_a_non_array(): void {
			$this->filter_returns( 'woodev_shipping_plugin_guards_shipping_accepted_countries', 'not an array' );

			$plugin = new Woodev_Test_Shipping_Plugin_For_Guards();

			$this->assertSame( [], $plugin->get_accepted_countries() );
		}

		/**
		 * The control.
		 *
		 * @return void
		 */
		public function test_accepted_countries_is_honoured_when_it_returns_an_array(): void {
			$this->filter_returns( 'woodev_shipping_plugin_guards_shipping_accepted_countries', [ 'RU' ] );

			$plugin = new Woodev_Test_Shipping_Plugin_For_Guards();

			$this->assertSame( [ 'RU' ], $plugin->get_accepted_countries() );
		}

		/* ------------------------------------------------------------------ *
		 * Site 10 — Pickup_Handler::get_js_config()
		 * `woodev_pickup_map_i18n` — NOT an `(array)` cast: that turns a scalar
		 * return into a one-element list keyed `0`, so the map would render with
		 * every label missing. The hostile case proves the framework strings
		 * survive, not merely that nothing fatals.
		 * ------------------------------------------------------------------ */

		/**
		 * @return void
		 */
		public function test_pickup_map_i18n_falls_back_when_the_filter_returns_a_non_array(): void {
			$this->filter_returns( 'woodev_pickup_map_i18n', 'not an array' );

			$config = $this->pickup_handler()->get_js_config();

			$this->assertSame( 'Choose a pickup point', $config['i18n']['modalTitle'] );
			$this->assertSame( 'Close', $config['i18n']['close'] );
		}

		/**
		 * The control: a real array is honoured, and every value is coerced to a
		 * string via `array_map( 'strval', ... )`.
		 *
		 * @return void
		 */
		public function test_pickup_map_i18n_is_honoured_and_coerces_values_to_strings(): void {
			$this->filter_returns(
				'woodev_pickup_map_i18n',
				[
					'modalTitle' => 'Custom Title',
					'close'      => 123,
				]
			);

			$config = $this->pickup_handler()->get_js_config();

			$this->assertSame( 'Custom Title', $config['i18n']['modalTitle'] );
			$this->assertSame( '123', $config['i18n']['close'] );
		}

		/**
		 * Builds a real Pickup_Handler with its two collaborators mocked and every
		 * optional constructor argument omitted (no order handler, no selection
		 * scope, no owning plugin) — the framework's own documented "skip this
		 * entirely" degradation path, which keeps the object graph this test needs
		 * to a minimum.
		 *
		 * @return Pickup_Handler
		 */
		private function pickup_handler(): Pickup_Handler {

			$source = Mockery::mock( Point_Source::class );
			$source->shouldReceive( 'get_strategy' )->andReturn( Point_Source::STRATEGY_BULK );

			$map_provider = Mockery::mock( Map_Provider::class );
			$map_provider->shouldReceive( 'get_id' )->andReturn( 'test-provider' );
			$map_provider->shouldReceive( 'get_js_config' )->andReturn( [] );

			return new Pickup_Handler(
				'guards-pickup',
				'guards_pickup_field',
				$source,
				$map_provider,
				[
					'center' => [ 55.75, 37.62 ],
					'zoom'   => 10,
				]
			);
		}

		/**
		 * Makes `apply_filters()` return $value for $hook and the unfiltered value
		 * otherwise.
		 *
		 * @param string $hook  hook name to intercept.
		 * @param mixed  $value what the plugin returns.
		 * @return void
		 */
		private function filter_returns( string $hook, $value ): void {
			Functions\when( 'apply_filters' )->alias(
				static function ( $tag, $filtered = null ) use ( $hook, $value ) {
					return $hook === $tag ? $value : $filtered;
				}
			);
		}
	}
}
