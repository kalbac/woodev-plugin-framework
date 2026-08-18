<?php
/**
 * Unit tests for Checkout_Field_Policy — the store-level singleton that turns the
 * «Поля» settings (Task 5's Checkout_Field_Settings) into real WooCommerce checkout
 * behaviour via two instruments (issue #362, design §4.3):
 *
 *  - `woocommerce_get_country_locale` (Instrument A) — reaches BOTH the classic and the
 *    block checkout.
 *  - `woocommerce_checkout_fields` (Instrument B) — classic checkout only, by
 *    construction.
 *
 * The two pure contribution methods ({@see Checkout_Field_Policy::locale_contribution()}
 * / {@see Checkout_Field_Policy::checkout_fields_contribution()}) are this file's main
 * subject — they touch no WordPress function at all. A second group exercises
 * {@see Checkout_Field_Policy::register()} and its two filter callbacks against a real
 * {@see Checkout_Field_Settings} handler.
 *
 * @package Woodev\Tests\Unit\Shipping\Checkout
 */

namespace Woodev\Tests\Unit\Shipping\Checkout;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Checkout\Checkout_Field_Environment;
use Woodev\Framework\Shipping\Checkout\Checkout_Field_Policy;
use Woodev\Framework\Shipping\Checkout\Checkout_Field_Settings;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-setting.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/abstract-class-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-field-environment.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-field-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-field-policy.php';

/**
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Field_Policy
 */
final class CheckoutFieldPolicyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Checkout_Field_Policy::reset_for_tests();
	}

	protected function tearDown(): void {
		Checkout_Field_Policy::reset_for_tests();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// locale_contribution() — Instrument A, pure
	// -------------------------------------------------------------------------

	public function test_locale_contribution_for_preset_only(): void {
		$out = Checkout_Field_Policy::locale_contribution( [ 'field_order_preset' => true, 'region_field' => 'show', 'postcode_field' => 'show' ], [ 'RU' => [] ], [ 'RU', 'KZ' ] );
		$this->assertSame( 10, $out['RU']['country']['priority'] );
		$this->assertSame( 20, $out['RU']['state']['priority'] );
		$this->assertSame( 30, $out['RU']['city']['priority'] );
		$this->assertSame( 40, $out['RU']['address_1']['priority'] );
		$this->assertSame( 50, $out['RU']['address_2']['priority'] );
		$this->assertSame( 60, $out['RU']['postcode']['priority'] );
		$this->assertSame( 20, $out['KZ']['state']['priority'] );        // every shipping country (S5)
		$this->assertTrue( $out['RU']['city']['required'] );            // settlement invariant, always
		$this->assertArrayNotHasKey( 'hidden', $out['RU']['state'] );
	}

	public function test_locale_contribution_removes_region_and_postcode(): void {
		$out = Checkout_Field_Policy::locale_contribution( [ 'field_order_preset' => false, 'region_field' => 'remove', 'postcode_field' => 'remove' ], [], [ 'RU' ] );
		$this->assertTrue( $out['RU']['state']['hidden'] );
		$this->assertFalse( $out['RU']['state']['required'] );
		$this->assertTrue( $out['RU']['postcode']['hidden'] );
		$this->assertArrayNotHasKey( 'priority', $out['RU']['country'] );
		// existing locale keys survive
		$out2 = Checkout_Field_Policy::locale_contribution( [ 'field_order_preset' => false, 'region_field' => 'show', 'postcode_field' => 'show' ], [ 'RU' => [ 'postcode' => [ 'label' => 'Индекс' ] ] ], [ 'RU' ] );
		$this->assertSame( 'Индекс', $out2['RU']['postcode']['label'] );
	}

	/**
	 * S5: an unconfigured store (no shipping countries) gets no contribution at all —
	 * the locale array is returned unchanged, never a spurious empty-country entry.
	 */
	public function test_locale_contribution_is_a_no_op_when_no_shipping_countries(): void {
		$locale = [ 'RU' => [ 'postcode' => [ 'label' => 'Индекс' ] ] ];
		$out    = Checkout_Field_Policy::locale_contribution( [ 'field_order_preset' => true, 'region_field' => 'remove', 'postcode_field' => 'remove' ], $locale, [] );

		$this->assertSame( $locale, $out );
	}

	// -------------------------------------------------------------------------
	// checkout_fields_contribution() — Instrument B, pure
	// -------------------------------------------------------------------------

	public function test_checkout_fields_late_unset_removes_from_both_sections(): void {
		$fields = [ 'billing' => [ 'billing_state' => [], 'billing_postcode' => [], 'billing_city' => [ 'required' => true ] ], 'shipping' => [ 'shipping_state' => [], 'shipping_postcode' => [], 'shipping_city' => [ 'required' => true ] ] ];
		$out    = Checkout_Field_Policy::checkout_fields_contribution( [ 'region_field' => 'remove', 'postcode_field' => 'show' ], $fields );
		$this->assertArrayNotHasKey( 'billing_state', $out['billing'] );
		$this->assertArrayNotHasKey( 'shipping_state', $out['shipping'] );
		$this->assertArrayHasKey( 'shipping_postcode', $out['shipping'] );
	}

	/**
	 * A `postcode_field=remove` setting must ALSO unset the postcode field — not only
	 * the region test above — proving both settings are wired independently, not just
	 * one accidentally covering the other.
	 */
	public function test_checkout_fields_removes_postcode_independently_of_region(): void {
		$fields = [ 'billing' => [ 'billing_state' => [], 'billing_postcode' => [] ], 'shipping' => [ 'shipping_state' => [], 'shipping_postcode' => [] ] ];
		$out    = Checkout_Field_Policy::checkout_fields_contribution( [ 'region_field' => 'show', 'postcode_field' => 'remove' ], $fields );

		$this->assertArrayHasKey( 'billing_state', $out['billing'] );
		$this->assertArrayNotHasKey( 'billing_postcode', $out['billing'] );
		$this->assertArrayNotHasKey( 'shipping_postcode', $out['shipping'] );
	}

	/**
	 * A `hide_for_pickup` postcode value is a JS-driven, classic-only VALUE (T2) — this
	 * PHP instrument must never unset for anything other than the literal `'remove'`
	 * string.
	 */
	public function test_checkout_fields_does_not_touch_hide_for_pickup(): void {
		$fields = [ 'billing' => [ 'billing_postcode' => [] ], 'shipping' => [ 'shipping_postcode' => [] ] ];
		$out    = Checkout_Field_Policy::checkout_fields_contribution( [ 'region_field' => 'show', 'postcode_field' => 'hide_for_pickup' ], $fields );

		$this->assertArrayHasKey( 'billing_postcode', $out['billing'] );
		$this->assertArrayHasKey( 'shipping_postcode', $out['shipping'] );
	}

	// -------------------------------------------------------------------------
	// restore_invariants() — settlement-field invariant restoration (S8), instance
	// -------------------------------------------------------------------------

	public function test_settlement_removed_by_a_third_party_is_restored_and_recorded(): void {
		$fields = [ 'billing' => [ 'billing_address_1' => [] ], 'shipping' => [ 'shipping_address_1' => [] ] ];
		$policy = new Checkout_Field_Policy();
		$out    = $policy->restore_invariants( $fields, [ 'city' => [ 'label' => 'Город', 'required' => true, 'class' => [ 'form-row-wide' ], 'priority' => 70 ] ] );

		$this->assertTrue( $out['billing']['billing_city']['required'] );
		$this->assertTrue( $out['shipping']['shipping_city']['required'] );
		$this->assertSame(
			[ [ 'field' => 'city', 'what' => 'restored' ], [ 'field' => 'city', 'what' => 'restored' ] ],
			array_map( static fn( $o ) => [ 'field' => $o['field'], 'what' => $o['what'] ], $policy->get_overrides() )
		);
	}

	public function test_settlement_made_optional_is_made_required_again(): void {
		$fields = [ 'billing' => [ 'billing_city' => [ 'required' => false ] ], 'shipping' => [ 'shipping_city' => [ 'required' => false ] ] ];
		$policy = new Checkout_Field_Policy();
		$out    = $policy->restore_invariants( $fields, [ 'city' => [ 'required' => true ] ] );

		$this->assertTrue( $out['shipping']['shipping_city']['required'] );
		$this->assertSame( 'required', $policy->get_overrides()[0]['what'] );
	}

	public function test_other_fields_are_left_to_the_field_manager(): void {
		$fields = [ 'billing' => [ 'billing_city' => [ 'required' => true ], 'billing_phone' => [ 'required' => false ] ], 'shipping' => [ 'shipping_city' => [ 'required' => true ] ] ];
		$policy = new Checkout_Field_Policy();
		$out    = $policy->restore_invariants( $fields, [ 'city' => [ 'required' => true ] ] );

		$this->assertFalse( $out['billing']['billing_phone']['required'] );
		$this->assertSame( [], $policy->get_overrides() );
	}

	/**
	 * A second call describes only the outcome of THAT pass — overrides never
	 * accumulate across repeat filter applications within the same request.
	 */
	public function test_restore_invariants_resets_overrides_on_each_call(): void {
		$policy = new Checkout_Field_Policy();

		$policy->restore_invariants( [ 'billing' => [], 'shipping' => [] ], [ 'city' => [ 'required' => true ] ] );
		$this->assertNotSame( [], $policy->get_overrides() );

		$policy->restore_invariants(
			[ 'billing' => [ 'billing_city' => [ 'required' => true ] ], 'shipping' => [ 'shipping_city' => [ 'required' => true ] ] ],
			[ 'city' => [ 'required' => true ] ]
		);
		$this->assertSame( [], $policy->get_overrides(), 'a second call must not accumulate overrides from the first' );
	}

	// -------------------------------------------------------------------------
	// register() / filter callbacks — real Checkout_Field_Settings
	// -------------------------------------------------------------------------

	private function settings_handler( array $stored = [] ): Checkout_Field_Settings {
		Functions\when( 'get_option' )->alias(
			static function ( $name ) use ( $stored ) {
				foreach ( $stored as $id => $value ) {
					if ( false !== strpos( (string) $name, $id ) ) {
						return $value;
					}
				}

				return null;
			}
		);
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = [] ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $default = null ) {
				return $default;
			}
		);
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'delete_option' )->justReturn( true );

		return new Checkout_Field_Settings( new Checkout_Field_Environment( false, 1 ) );
	}

	public function test_register_hooks_both_filters_at_the_late_priority(): void {
		$calls = [];
		Functions\when( 'add_filter' )->alias(
			static function ( $hook, $callback, $priority = 10 ) use ( &$calls ) {
				$calls[] = [ $hook, $priority ];

				return true;
			}
		);

		Checkout_Field_Policy::instance()->register( $this->settings_handler() );

		$this->assertSame(
			[
				[ 'woocommerce_get_country_locale', Checkout_Field_Policy::LATE ],
				[ 'woocommerce_checkout_fields', Checkout_Field_Policy::LATE ],
			],
			$calls
		);
	}

	public function test_register_is_idempotent_across_repeat_calls(): void {
		$call_count = 0;
		Functions\when( 'add_filter' )->alias(
			static function () use ( &$call_count ) {
				++$call_count;

				return true;
			}
		);

		$policy   = Checkout_Field_Policy::instance();
		$settings = $this->settings_handler();

		$policy->register( $settings );
		$policy->register( $settings );
		$policy->register( $settings );

		$this->assertSame( 2, $call_count, 'exactly two filters must be added, regardless of how many times register() is called' );
	}

	/**
	 * Isolated in its own process — same idiom, and for the same reason, as
	 * `CheckoutConfigTest::test_wc_states_degrades_a_false_get_states_result_to_states_present_false()`:
	 * Brain Monkey/Patchwork instruments a mocked `WC()` for the REST OF THE PHP
	 * PROCESS once any test stubs it, which would otherwise silently poison every
	 * other test in the suite relying on `function_exists( 'WC' ) === false`.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_filter_country_locale_reads_effective_settings_and_shipping_countries(): void {
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'WC' )->justReturn(
			(object) [
				'countries' => new class() {
					public function get_shipping_countries(): array {
						return [ 'RU' => 'Россия', 'KZ' => 'Казахстан' ];
					}
				},
			]
		);

		$policy = Checkout_Field_Policy::instance();
		$policy->register( $this->settings_handler( [ 'region_field' => 'remove' ] ) );

		$out = $policy->filter_country_locale( [] );

		$this->assertTrue( $out['RU']['state']['hidden'] );
		$this->assertTrue( $out['KZ']['state']['hidden'] );
	}

	public function test_filter_checkout_fields_unsets_the_removed_field(): void {
		Functions\when( 'add_filter' )->justReturn( true );

		$policy = Checkout_Field_Policy::instance();
		$policy->register( $this->settings_handler( [ 'postcode_field' => 'remove' ] ) );

		$out = $policy->filter_checkout_fields(
			[
				'billing'  => [ 'billing_postcode' => [] ],
				'shipping' => [ 'shipping_postcode' => [] ],
			]
		);

		$this->assertArrayNotHasKey( 'billing_postcode', $out['billing'] );
		$this->assertArrayNotHasKey( 'shipping_postcode', $out['shipping'] );
	}

	// -------------------------------------------------------------------------
	// filter_checkout_fields() end-to-end — restoration is persisted (S8)
	// -------------------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_filter_checkout_fields_restores_and_persists_the_override_report(): void {
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'WC' )->justReturn(
			(object) [
				'countries' => new class() {
					public function get_default_address_fields(): array {
						return [ 'city' => [ 'label' => 'Город', 'required' => true, 'priority' => 70 ] ];
					}
				},
			]
		);

		$policy = Checkout_Field_Policy::instance();
		$policy->register( $this->settings_handler() );

		$written = null;
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$written ) {
				$written = [ $name, $value ];

				return true;
			}
		);
		Functions\when( 'delete_option' )->justReturn( true );

		$out = $policy->filter_checkout_fields(
			[
				'billing'  => [ 'billing_address_1' => [] ],
				'shipping' => [ 'shipping_address_1' => [] ],
			]
		);

		$this->assertTrue( $out['billing']['billing_city']['required'] );
		$this->assertSame( Checkout_Field_Policy::OPTION_LAST_OVERRIDES, $written[0] );
		$this->assertNotEmpty( $written[1] );
	}

	/**
	 * The control case (design S8): once the offending plugin stops interfering —
	 * the very next observation finds nothing to restore — the stale report must be
	 * cleared, not left behind accusing a plugin that is no longer active.
	 */
	public function test_filter_checkout_fields_clears_a_stale_report_once_nothing_is_overridden(): void {
		Functions\when( 'add_filter' )->justReturn( true );

		$policy = Checkout_Field_Policy::instance();
		$policy->register( $this->settings_handler() );

		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = null ) {
				return false !== strpos( (string) $name, Checkout_Field_Policy::OPTION_LAST_OVERRIDES )
					? [ [ 'field' => 'city', 'section' => 'billing', 'what' => 'restored' ] ]
					: $default;
			}
		);

		$deleted = false;
		Functions\when( 'delete_option' )->alias(
			static function ( $name ) use ( &$deleted ) {
				$deleted = ( Checkout_Field_Policy::OPTION_LAST_OVERRIDES === $name );

				return true;
			}
		);
		Functions\when( 'update_option' )->justReturn( true );

		$policy->filter_checkout_fields(
			[
				'billing'  => [ 'billing_city' => [ 'required' => true ] ],
				'shipping' => [ 'shipping_city' => [ 'required' => true ] ],
			]
		);

		$this->assertTrue( $deleted, 'once nothing needs restoring, the stale report must be cleared' );
	}
}
