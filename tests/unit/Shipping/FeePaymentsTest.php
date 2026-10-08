<?php
/**
 * «Fee only for chosen payment methods» (#1144): the method's gate and the shared plumbing around it.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Fee_Payments;
use Woodev\Framework\Shipping\Shipping_Method;
use Woodev\Tests\Unit\TestCase;

// the method probe and the `WC_Shipping_Method` stub live there; whichever file loads first defines them
require_once __DIR__ . '/ShippingRateCacheTest.php';

/** The probe, opened up: `apply_fee_for_package()` is protected, as a carrier sees it. */
class Woodev_Test_Fee_Payments_Method extends Woodev_Test_Shipping_Method_For_Rate_Cache {

	/**
	 * @param float  $cost    cost.
	 * @param string $fee     fee.
	 * @param float  $base    base for a percentage.
	 * @param array  $package package.
	 * @return float
	 */
	public function fee( float $cost, string $fee, float $base, array $package ): float {
		return $this->apply_fee_for_package( $cost, $fee, $base, $package );
	}
}

/**
 * @coversDefaultClass \Woodev\Framework\Shipping\Fee_Payments
 */
final class FeePaymentsTest extends TestCase {

	/** @var array<string, mixed> options by name. */
	private array $options = [];

	/** @var array<string, mixed> what the session holds. */
	private array $session = [];

	/** @var array<int, array{string, mixed}> update_option calls, in order. */
	private array $updates = [];

	/** @var string[] delete_option calls, in order. */
	private array $deletes = [];

	/** @return void */
	protected function setUp(): void {
		parent::setUp();

		$this->options = [];
		$this->session = [];
		$this->updates = [];
		$this->deletes = [];

		Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->updates[]       = [ $name, $value ];
				$this->options[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				$this->deletes[] = $name;
				unset( $this->options[ $name ] );

				return true;
			}
		);
		Functions\when( 'wc_clean' )->returnArg( 1 );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );

		$session = new class( $this->session ) {
			/** @var array */
			private array $data;

			/** @param array $data backing array, shared by reference below. */
			public function __construct( array &$data ) {
				$this->data = &$data;
			}

			/**
			 * @param string $key key.
			 * @return mixed
			 */
			public function get( $key ) {
				return $this->data[ $key ] ?? null;
			}

			/**
			 * @param string $key   key.
			 * @param mixed  $value value.
			 * @return void
			 */
			public function set( $key, $value ): void {
				$this->data[ $key ] = $value;
			}
		};

		$this->wc = new class( $session ) {
			/** @var object|null */
			public $session;

			/** @var object|null */
			public $gateways = null;

			/** @param object $session the session double. */
			public function __construct( $session ) {
				$this->session = $session;
			}

			/** @return object|null */
			public function payment_gateways() {
				return $this->gateways;
			}
		};

		Fee_Payments::reset();
		Fee_Payments::use_runtime_for_tests( $this->wc );
	}

	/** @var object|null the stand-in for `WC()`. */
	private $wc;

	/** @return void */
	protected function tearDown(): void {
		Fee_Payments::reset();

		parent::tearDown();
	}

	/**
	 * @param mixed $list     the stored `fee_payments` value.
	 * @param bool  $declared whether the method declared the feature.
	 * @return Woodev_Test_Fee_Payments_Method
	 */
	private function method( $list, bool $declared = true ): Woodev_Test_Fee_Payments_Method {
		$method = new Woodev_Test_Fee_Payments_Method();

		if ( $declared ) {
			$method->supports[] = Shipping_Method::FEATURE_FEE_PAYMENTS;
		}

		$method->option_values[ Fee_Payments::OPTION_KEY ] = $list;

		return $method;
	}

	/**
	 * @param string $payment chosen payment method, `''` for none.
	 * @return array
	 */
	private function package( string $payment = '' ): array {
		return '' === $payment ? [ 'contents_cost' => 100 ] : [ 'contents_cost' => 100, 'chosen_payment_method' => $payment ];
	}

	// ---- the gate ---------------------------------------------------------------------------------------

	/** @return void */
	public function test_a_method_that_did_not_declare_the_feature_always_applies_its_fee(): void {
		$method = $this->method( [ 'cod' ], false );

		$this->assertTrue( $method->fee_applies_for_package( $this->package( 'bacs' ) ) );
		$this->assertTrue( $method->fee_applies_for_package( $this->package() ) );
	}

	/** @return array<string, array{0: mixed}> */
	public function empty_lists(): array {
		return [
			'empty array'       => [ [] ],
			'empty string'      => [ '' ],
			'never saved'       => [ null ],
			'blank ids'         => [ [ '', '  ' ] ],
		];
	}

	/**
	 * @dataProvider empty_lists
	 * @param mixed $list the stored value.
	 * @return void
	 */
	public function test_an_empty_list_applies_the_fee_whatever_is_chosen( $list ): void {
		$method = $this->method( [] );
		$method->option_values[ Fee_Payments::OPTION_KEY ] = $list;

		$this->assertTrue( $method->fee_applies_for_package( $this->package( 'bacs' ) ) );
		$this->assertTrue( $method->fee_applies_for_package( $this->package() ), 'nothing chosen yet, nothing restricted' );
	}

	/** @return void */
	public function test_a_listed_payment_method_applies_the_fee(): void {
		$method = $this->method( [ 'cod', 'cheque' ] );

		$this->assertTrue( $method->fee_applies_for_package( $this->package( 'cod' ) ) );
		$this->assertTrue( $method->fee_applies_for_package( $this->package( 'cheque' ) ) );
	}

	/** @return void */
	public function test_an_unlisted_payment_method_does_not(): void {
		$method = $this->method( [ 'cod' ] );

		$this->assertFalse( $method->fee_applies_for_package( $this->package( 'bacs' ) ) );
		$this->assertFalse( $method->fee_applies_for_package( $this->package( 'COD' ) ), 'ids are compared exactly, as the v1 plugin did' );
	}

	/** @return void */
	public function test_no_chosen_payment_method_yet_does_not_apply_a_restricted_fee(): void {
		$method = $this->method( [ 'cod' ] );

		$this->assertFalse( $method->fee_applies_for_package( $this->package() ), 'v1 parity: `! empty( chosen ) && in_array()`' );
	}

	/** @return void */
	public function test_the_session_answers_when_the_package_carries_none(): void {
		$method          = $this->method( [ 'cod' ] );
		$method->payment = 'cod';

		$this->assertTrue( $method->fee_applies_for_package( $this->package() ) );

		$method->payment = 'bacs';
		$this->assertFalse( $method->fee_applies_for_package( $this->package() ) );
	}

	/** @return void */
	public function test_the_package_wins_over_the_session(): void {
		$method          = $this->method( [ 'cod' ] );
		$method->payment = 'bacs';

		$this->assertTrue( $method->fee_applies_for_package( $this->package( 'cod' ) ) );
	}

	/** @return void */
	public function test_apply_fee_for_package_adds_the_fee_only_when_it_applies(): void {
		$method = $this->method( [ 'cod' ] );

		$this->assertSame( 110.0, $method->fee( 100.0, '10%', 100.0, $this->package( 'cod' ) ) );
		$this->assertSame( 100.0, $method->fee( 100.0, '10%', 100.0, $this->package( 'bacs' ) ) );
		$this->assertSame( 150.0, $this->method( [] )->fee( 100.0, '50', 0.0, $this->package( 'bacs' ) ), 'no restriction: the flat fee is added for any method' );
	}

	/** @return void */
	public function test_the_list_is_read_back_clean(): void {
		$this->assertSame( [ 'cod', 'bacs' ], $this->method( [ ' cod ', 'bacs', 'cod', '', 5 ] )->get_fee_payments() );
	}

	// ---- the cache key ---------------------------------------------------------------------------------------

	/** @return void */
	public function test_the_cache_context_names_the_payment_method_on_the_package_and_falls_back_to_the_session(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->alias( fn( $tag, $value = null ) => $value );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'RUB' );
		Functions\when( 'wp_parse_args' )->alias( fn( $args, $defaults = [] ) => array_merge( (array) $defaults, (array) $args ) );
		\Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab::reset_for_tests();

		$method          = $this->method( [ 'cod' ] );
		$method->payment = 'bacs';

		$this->assertSame( 'cod', $method->get_rate_cache_context( $this->package( 'cod' ) )['payment'], 'the package, which is what the fee gate reads' );
		$this->assertSame( 'bacs', $method->get_rate_cache_context( $this->package() )['payment'], 'no package value: the session' );
	}

	// ---- the registry ---------------------------------------------------------------------------------------

	/** @return array<string, array{0: mixed, 1: string[]}> */
	public function normalized(): array {
		return [
			'array'      => [ [ 'cod', 'bacs' ], [ 'cod', 'bacs' ] ],
			'duplicates' => [ [ 'cod', 'cod' ], [ 'cod' ] ],
			'trimmed'    => [ [ ' cod ' ], [ 'cod' ] ],
			'junk'       => [ [ 1, null, [], 'cod' ], [ 'cod' ] ],
			'string'     => [ 'cod', [] ],
			'null'       => [ null, [] ],
			'false'      => [ false, [] ],
		];
	}

	/**
	 * @dataProvider normalized
	 * @param mixed    $value    stored value.
	 * @param string[] $expected ids.
	 * @return void
	 */
	public function test_normalize( $value, array $expected ): void {
		$this->assertSame( $expected, Fee_Payments::normalize( $value ) );
	}

	/** @return void */
	public function test_an_instance_with_a_list_enters_the_registry(): void {
		$this->assertFalse( Fee_Payments::is_used() );

		Fee_Payments::on_option_updated( 'woocommerce_cdek_7_settings', [], [ 'fee_payments' => [ 'cod' ] ] );

		$this->assertTrue( Fee_Payments::is_used() );
		$this->assertSame( [ Fee_Payments::REGISTRY_OPTION, [ 'woocommerce_cdek_7_settings' => true ] ], $this->updates[0] );
	}

	/** @return void */
	public function test_a_first_save_counts_as_well_as_an_update(): void {
		Fee_Payments::on_option_added( 'woocommerce_cdek_7_settings', [ 'fee_payments' => [ 'cod' ] ] );

		$this->assertTrue( Fee_Payments::is_used() );
	}

	/** @return void */
	public function test_emptying_the_list_leaves_the_registry_and_the_last_one_removes_it(): void {
		Fee_Payments::on_option_added( 'woocommerce_cdek_7_settings', [ 'fee_payments' => [ 'cod' ] ] );
		Fee_Payments::on_option_added( 'woocommerce_cdek_8_settings', [ 'fee_payments' => [ 'bacs' ] ] );

		Fee_Payments::on_option_updated( 'woocommerce_cdek_7_settings', [], [ 'fee_payments' => [] ] );
		$this->assertSame( [ 'woocommerce_cdek_8_settings' => true ], $this->options[ Fee_Payments::REGISTRY_OPTION ] );

		Fee_Payments::on_option_updated( 'woocommerce_cdek_8_settings', [], [ 'title' => 'x' ] );
		$this->assertFalse( Fee_Payments::is_used() );
		$this->assertSame( [ Fee_Payments::REGISTRY_OPTION ], $this->deletes );
	}

	/** @return void */
	public function test_a_deleted_instance_leaves_the_registry(): void {
		Fee_Payments::on_option_added( 'woocommerce_cdek_7_settings', [ 'fee_payments' => [ 'cod' ] ] );

		Fee_Payments::on_option_deleted( 'woocommerce_cdek_7_settings' );

		$this->assertFalse( Fee_Payments::is_used() );
	}

	/** @return void */
	public function test_conditional_insurance_activates_the_existing_classic_payment_trigger(): void {
		Functions\when( 'is_checkout' )->justReturn( true );
		Functions\when( 'plugins_url' )->justReturn( 'https://example.test/fee-payments-classic.js' );
		Functions\expect( 'wp_enqueue_script' )->once()->with(
			'woodev-fee-payments-classic',
			'https://example.test/fee-payments-classic.js',
			[ 'jquery', 'wc-checkout' ],
			\Mockery::type( 'string' ),
			true
		);
		Fee_Payments::on_option_added( 'woocommerce_cdek_7_settings', [ 'include_insurance' => 'delivery_payment' ] );
		Fee_Payments::enqueue_classic_script();
	}

	/** @return array<string, array{0: string}> */
	public function foreign_options(): array {
		return [
			'the plugin settings, no instance' => [ 'woocommerce_cdek_settings' ],
			'a core option'                    => [ 'woocommerce_currency' ],
			'not WooCommerce'                  => [ 'cdek_7_settings' ],
			'an instance-like suffix'          => [ 'woocommerce_cdek_7_settings_backup' ],
		];
	}

	/**
	 * @dataProvider foreign_options
	 * @param string $name option name.
	 * @return void
	 */
	public function test_other_options_never_reach_the_registry( string $name ): void {
		Fee_Payments::on_option_updated( $name, [], [ 'fee_payments' => [ 'cod' ] ] );

		$this->assertFalse( Fee_Payments::is_used() );
		$this->assertSame( [], $this->updates );
	}

	/** @return void */
	public function test_an_unchanged_state_writes_nothing(): void {
		Fee_Payments::on_option_updated( 'woocommerce_cdek_7_settings', [], [ 'title' => 'x' ] );
		Fee_Payments::on_option_deleted( 'woocommerce_cdek_7_settings' );
		$this->assertSame( [], $this->updates );
		$this->assertSame( [], $this->deletes );

		Fee_Payments::on_option_added( 'woocommerce_cdek_7_settings', [ 'fee_payments' => [ 'cod' ] ] );
		Fee_Payments::on_option_updated( 'woocommerce_cdek_7_settings', [], [ 'fee_payments' => [ 'bacs' ] ] );
		$this->assertCount( 1, $this->updates, 'still in use: no second write' );
	}

	// ---- the package ---------------------------------------------------------------------------------------

	/** @return void */
	public function test_packages_are_left_alone_while_nothing_uses_the_option(): void {
		$this->session['chosen_payment_method'] = 'cod';
		$packages                               = [ [ 'contents_cost' => 100 ] ];

		$this->assertSame( $packages, Fee_Payments::add_chosen_payment_to_packages( $packages ) );
	}

	/** @return void */
	public function test_every_package_carries_the_chosen_method_while_something_uses_it(): void {
		$this->options[ Fee_Payments::REGISTRY_OPTION ] = [ 'woocommerce_cdek_7_settings' => true ];
		$this->session['chosen_payment_method']         = 'cod';

		$this->assertSame(
			[ [ 'contents_cost' => 100, 'chosen_payment_method' => 'cod' ], [ 'chosen_payment_method' => 'cod' ] ],
			Fee_Payments::add_chosen_payment_to_packages( [ [ 'contents_cost' => 100 ], [] ] )
		);
	}

	/** @return void */
	public function test_conditional_insurance_reuses_the_payment_registry_and_package_hash(): void {
		$this->session['chosen_payment_method'] = 'cod';
		Fee_Payments::on_option_added( 'woocommerce_cdek_7_settings', [ 'include_insurance' => 'delivery_payment' ] );
		$this->assertTrue( Fee_Payments::is_used() );
		$cod = Fee_Payments::add_chosen_payment_to_packages( [ [ 'contents_cost' => 100 ] ] );
		$this->assertSame( 'cod', $cod[0]['chosen_payment_method'] );
		$this->session['chosen_payment_method'] = 'bacs';
		$prepaid = Fee_Payments::add_chosen_payment_to_packages( [ [ 'contents_cost' => 100 ] ] );
		$this->assertNotSame( json_encode( $cod ), json_encode( $prepaid ), 'WooCommerce hashes the package including the chosen gateway' );

		Fee_Payments::on_option_updated( 'woocommerce_cdek_7_settings', [], [ 'include_insurance' => 'always' ] );
		$this->assertFalse( Fee_Payments::is_used() );
		Fee_Payments::on_option_updated( 'woocommerce_cdek_7_settings', [], [ 'include_insurance' => 'delivery_payment', 'fee_payments' => [ 'cod' ] ] );
		Fee_Payments::on_option_updated( 'woocommerce_cdek_7_settings', [], [ 'include_insurance' => 'none', 'fee_payments' => [ 'cod' ] ] );
		$this->assertTrue( Fee_Payments::is_used(), 'disabling insurance must retain an existing fee dependency' );
		Fee_Payments::on_option_deleted( 'woocommerce_cdek_7_settings' );
		$this->assertFalse( Fee_Payments::is_used() );
	}

	/** @return void */
	public function test_no_chosen_method_leaves_the_packages_as_they_were(): void {
		$this->options[ Fee_Payments::REGISTRY_OPTION ] = [ 'woocommerce_cdek_7_settings' => true ];
		$packages                                       = [ [ 'contents_cost' => 100 ] ];

		$this->assertSame( $packages, Fee_Payments::add_chosen_payment_to_packages( $packages ) );

		$this->session['chosen_payment_method'] = [ 'not', 'a string' ];
		$this->assertSame( $packages, Fee_Payments::add_chosen_payment_to_packages( $packages ) );
	}

	/** @return void */
	public function test_a_malformed_package_list_comes_back_untouched(): void {
		$this->options[ Fee_Payments::REGISTRY_OPTION ] = [ 'woocommerce_cdek_7_settings' => true ];
		$this->session['chosen_payment_method']         = 'cod';

		$this->assertSame( 'x', Fee_Payments::add_chosen_payment_to_packages( 'x' ) );
		$this->assertSame( [], Fee_Payments::add_chosen_payment_to_packages( [] ) );
		$this->assertSame( [ 'junk' ], Fee_Payments::add_chosen_payment_to_packages( [ 'junk' ] ) );
	}

	/** @return void */
	public function test_no_session_means_no_method(): void {
		$this->options[ Fee_Payments::REGISTRY_OPTION ] = [ 'woocommerce_cdek_7_settings' => true ];
		$this->wc->session                              = null;
		$packages                                       = [ [ 'contents_cost' => 100 ] ];

		$this->assertSame( $packages, Fee_Payments::add_chosen_payment_to_packages( $packages ) );
	}

	// ---- the block checkout's command ---------------------------------------------------------------------------------------

	/** @return void */
	public function test_the_store_api_command_saves_the_payment_method_in_the_session(): void {
		Fee_Payments::update_cart( [ 'payment_method' => ' bacs ' ] );

		$this->assertSame( 'bacs', $this->session['chosen_payment_method'] );
	}

	/** @return array<string, array{0: mixed}> */
	public function bad_commands(): array {
		return [
			'not an array'   => [ 'bacs' ],
			'no method'      => [ [] ],
			'not a string'   => [ [ 'payment_method' => [ 'bacs' ] ] ],
			'too long'       => [ [ 'payment_method' => str_repeat( 'a', 101 ) ] ],
		];
	}

	/**
	 * @dataProvider bad_commands
	 * @param mixed $data the command.
	 * @return void
	 */
	public function test_a_malformed_command_changes_nothing( $data ): void {
		$this->session['chosen_payment_method'] = 'cod';

		Fee_Payments::update_cart( $data );

		$this->assertSame( 'cod', $this->session['chosen_payment_method'] );
	}

	// ---- the control's options ---------------------------------------------------------------------------------------

	/**
	 * @param string $id           gateway id.
	 * @param string $enabled      `yes`/`no`.
	 * @param string $title        the title the customer sees.
	 * @param string $method_title the title the admin sees.
	 * @return object
	 */
	private function gateway( string $id, string $enabled, string $title, string $method_title ): object {
		return new class( $id, $enabled, $title, $method_title ) {
			/** @var string */
			public $enabled;
			/** @var string */
			private $title;
			/** @var string */
			private $method_title;

			/** @param string $id id (unused: the registry key carries it). @param string $enabled flag. @param string $title title. @param string $method_title admin title. */
			public function __construct( string $id, string $enabled, string $title, string $method_title ) {
				$this->enabled      = $enabled;
				$this->title        = $title;
				$this->method_title = $method_title;
			}

			/** @return string */
			public function get_title() {
				return $this->title;
			}

			/** @return string */
			public function get_method_title() {
				return $this->method_title;
			}
		};
	}

	/** @return void */
	public function test_the_options_are_the_enabled_gateways_plus_what_is_already_chosen(): void {
		$gateways = [
			'cod'    => $this->gateway( 'cod', 'yes', 'Cash on delivery', 'Cash on delivery' ),
			'bacs'   => $this->gateway( 'bacs', 'yes', 'Pay by transfer', 'Direct bank transfer' ),
			'cheque' => $this->gateway( 'cheque', 'no', 'Cheque', 'Cheque payments' ),
		];

		$this->wc->gateways = new class( $gateways ) {
			/** @var array */
			private array $gateways;

			/** @param array $gateways gateways by id. */
			public function __construct( array $gateways ) {
				$this->gateways = $gateways;
			}

			/** @return array */
			public function payment_gateways() {
				return $this->gateways;
			}
		};

		$this->assertSame(
			[
				'cod'  => 'Cash on delivery',
				'bacs' => 'Pay by transfer (Direct bank transfer)',
				'old'  => 'old',
			],
			Fee_Payments::gateway_options( [ 'old', 'cod' ] ),
			'a disabled gateway is not offered; an id already saved is kept'
		);
	}
}
