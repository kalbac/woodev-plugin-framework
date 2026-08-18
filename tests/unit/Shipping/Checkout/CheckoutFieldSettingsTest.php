<?php
/**
 * Unit tests for Checkout_Field_Settings — the «Поля» settings handler (design
 * S1/S4/S9), its availability rules (design §3.2/D11) and its `effective()`
 * clamp-on-read contract (design §7).
 *
 * @package Woodev\Tests\Unit\Shipping\Checkout
 */

namespace Woodev\Tests\Unit\Shipping\Checkout;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Checkout\Checkout_Field_Environment;
use Woodev\Framework\Shipping\Checkout\Checkout_Field_Settings;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-setting.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/abstract-class-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-field-environment.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-field-settings.php';

/**
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Field_Settings
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Field_Environment
 */
final class CheckoutFieldSettingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_option' )->justReturn( null );
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
	}

	// -------------------------------------------------------------------------
	// Defaults, ids
	// -------------------------------------------------------------------------

	public function test_defaults_and_ids(): void {
		$s = new Checkout_Field_Settings( new Checkout_Field_Environment( false, 1 ) );

		$this->assertSame(
			[ 'field_order_preset', 'country_field', 'region_field', 'address_field', 'postcode_field' ],
			$s->get_owned_setting_ids()
		);
		$this->assertTrue( $s->get_value( 'field_order_preset' ) );
		$this->assertSame( 'show', $s->get_value( 'postcode_field' ) );
		$this->assertSame( 'show', $s->get_value( 'country_field' ) );
		$this->assertSame( 'show', $s->get_value( 'region_field' ) );
		$this->assertSame( 'show', $s->get_value( 'address_field' ) );
	}

	public function test_single_country_classic_checkout_disables_nothing(): void {
		$s = new Checkout_Field_Settings( new Checkout_Field_Environment( false, 1 ) );

		foreach ( $s->get_owned_setting_ids() as $id ) {
			$this->assertFalse( $s->get_setting( $id )->get_control()->is_disabled(), "{$id} should not be disabled" );
		}
	}

	// -------------------------------------------------------------------------
	// country_field availability — single-country gate
	// -------------------------------------------------------------------------

	public function test_country_hide_disabled_with_reason_when_store_ships_to_many_countries(): void {
		$s = new Checkout_Field_Settings( new Checkout_Field_Environment( false, 3 ) );
		$c = $s->get_setting( 'country_field' )->get_control();

		$this->assertTrue( $c->is_disabled() );
		$this->assertStringContainsString( 'одну страну', $c->get_disabled_reason() );
	}

	public function test_country_hide_disabled_with_reason_when_store_ships_to_zero_countries(): void {
		$s = new Checkout_Field_Settings( new Checkout_Field_Environment( false, 0 ) );
		$c = $s->get_setting( 'country_field' )->get_control();

		$this->assertTrue( $c->is_disabled() );
		$this->assertStringContainsString( 'одну страну', $c->get_disabled_reason() );
	}

	// -------------------------------------------------------------------------
	// Block checkout — availability
	// -------------------------------------------------------------------------

	public function test_block_checkout_disables_js_driven_options_and_narrows_postcode(): void {
		$s = new Checkout_Field_Settings( new Checkout_Field_Environment( true, 1 ) );

		$this->assertTrue( $s->get_setting( 'address_field' )->get_control()->is_disabled() );
		$this->assertTrue( $s->get_setting( 'country_field' )->get_control()->is_disabled() );
		$this->assertSame( [ 'show', 'remove' ], array_keys( $s->get_setting( 'postcode_field' )->get_control()->get_options() ) );
		$this->assertFalse( $s->get_setting( 'region_field' )->get_control()->is_disabled() ); // locale instrument reaches blocks
		$this->assertFalse( $s->get_setting( 'field_order_preset' )->get_control()->is_disabled() );
	}

	// -------------------------------------------------------------------------
	// effective() — clamp on read
	// -------------------------------------------------------------------------

	public function test_effective_values_clamp_on_read(): void {
		Functions\when( 'get_option' )->alias( fn( $k, $d = false ) => 'woodev_checkout_fields_country_field' === $k ? 'hide' : $d );
		$s = new Checkout_Field_Settings( new Checkout_Field_Environment( false, 2 ) );
		$this->assertSame( 'show', $s->effective( 'country_field' ) ); // stored `hide`, no longer allowed

		Functions\when( 'get_option' )->alias( fn( $k, $d = false ) => 'woodev_checkout_fields_postcode_field' === $k ? 'hide_for_pickup' : $d );
		$s = new Checkout_Field_Settings( new Checkout_Field_Environment( true, 1 ) );
		$this->assertSame( 'show', $s->effective( 'postcode_field' ) );
	}

	public function test_effective_passes_through_stored_value_when_still_allowed(): void {
		Functions\when( 'get_option' )->alias( fn( $k, $d = false ) => 'woodev_checkout_fields_country_field' === $k ? 'hide' : $d );
		$s = new Checkout_Field_Settings( new Checkout_Field_Environment( false, 1 ) );
		$this->assertSame( 'hide', $s->effective( 'country_field' ) );

		Functions\when( 'get_option' )->alias( fn( $k, $d = false ) => 'woodev_checkout_fields_postcode_field' === $k ? 'hide_for_pickup' : $d );
		$s = new Checkout_Field_Settings( new Checkout_Field_Environment( false, 1 ) );
		$this->assertSame( 'hide_for_pickup', $s->effective( 'postcode_field' ) );

		Functions\when( 'get_option' )->alias( fn( $k, $d = false ) => 'woodev_checkout_fields_address_field' === $k ? 'hide_for_pickup' : $d );
		$s = new Checkout_Field_Settings( new Checkout_Field_Environment( false, 1 ) );
		$this->assertSame( 'hide_for_pickup', $s->effective( 'address_field' ) );
	}

	public function test_effective_address_field_clamps_to_show_on_block_checkout(): void {
		Functions\when( 'get_option' )->alias( fn( $k, $d = false ) => 'woodev_checkout_fields_address_field' === $k ? 'hide_for_pickup' : $d );
		$s = new Checkout_Field_Settings( new Checkout_Field_Environment( true, 1 ) );
		$this->assertSame( 'show', $s->effective( 'address_field' ) );
	}

	public function test_effective_region_field_and_field_order_preset_are_never_clamped(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $k, $d = false ) {
				if ( 'woodev_checkout_fields_region_field' === $k ) {
					return 'remove';
				}
				if ( 'woodev_checkout_fields_field_order_preset' === $k ) {
					return false;
				}
				return $d;
			}
		);
		$s = new Checkout_Field_Settings( new Checkout_Field_Environment( true, 3 ) );

		$this->assertSame( 'remove', $s->effective( 'region_field' ) );
		$this->assertFalse( $s->effective( 'field_order_preset' ) );
	}

	public function test_effective_throws_for_unknown_id(): void {
		$this->expectException( \Woodev_Plugin_Exception::class );

		$s = new Checkout_Field_Settings( new Checkout_Field_Environment( false, 1 ) );
		$s->effective( 'not_a_real_setting' );
	}
}
