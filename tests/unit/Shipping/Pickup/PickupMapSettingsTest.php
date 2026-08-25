<?php
/**
 * Unit tests for Pickup_Map_Settings — the «Карта» section of the «Доставка» tab
 * (design S1/S7/S9, issue #362, Task 8): the three store-level pickup map behaviour
 * settings (`pickup_button_placement`, `pickup_replace_address`, `pickup_close_on_select`)
 * that a carrier plugin can no longer override per-instance, plus the `current()`
 * accessor {@see \Woodev\Framework\Shipping\Checkout\Checkout_Config} and
 * {@see \Woodev\Framework\Shipping\Pickup\Pickup_Handler} read them through.
 *
 * @package Woodev\Tests\Unit\Shipping\Pickup
 */

namespace Woodev\Tests\Unit\Shipping\Pickup;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Pickup\Pickup_Map_Settings;
use Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/class-plugin-exception.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-control.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/class-setting.php';
require_once dirname( __DIR__, 4 ) . '/woodev/settings-api/abstract-class-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-field-environment.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-field-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-field-policy.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/pickup/class-pickup-map-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/settings/class-shipping-settings-tab.php';

/**
 * @covers \Woodev\Framework\Shipping\Pickup\Pickup_Map_Settings
 */
final class PickupMapSettingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'wp_parse_args' )->alias(
			static function ( $args, $defaults = [] ) {
				return array_merge( (array) $defaults, (array) $args );
			}
		);

		Shipping_Settings_Tab::reset_for_tests();
	}

	protected function tearDown(): void {
		Shipping_Settings_Tab::reset_for_tests();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Owned ids, defaults, option namespace
	// -------------------------------------------------------------------------

	public function test_owned_setting_ids_in_registration_order(): void {
		$s = new Pickup_Map_Settings();

		$this->assertSame(
			[ 'pickup_button_placement', 'pickup_replace_address', 'pickup_close_on_select' ],
			$s->get_owned_setting_ids()
		);
	}

	public function test_defaults_when_nothing_is_stored(): void {
		$s = new Pickup_Map_Settings();

		$this->assertSame( 'rate', $s->get_value( 'pickup_button_placement' ) );
		$this->assertTrue( $s->get_value( 'pickup_replace_address' ) );
		$this->assertFalse( $s->get_value( 'pickup_close_on_select' ) );
	}

	public function test_button_placement_offers_exactly_rate_and_review(): void {
		$s = new Pickup_Map_Settings();

		$this->assertSame(
			[ 'rate', 'review' ],
			array_keys( $s->get_setting( 'pickup_button_placement' )->get_options() )
		);
	}

	/**
	 * The option namespace is `woodev_pickup_map_{id}` (`Woodev_Abstract_Settings::get_option_name_prefix()`
	 * returns `woodev_{$this->id}` for `$this->id = 'pickup_map'`, and `load_settings()` appends
	 * `_{$setting_id}`) — pinned directly so a rename of `Pickup_Map_Settings`'s constructor id
	 * silently breaking every stored option is caught here rather than only on a live site.
	 */
	public function test_option_names_use_the_pickup_map_prefix(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = null ) {
				$stored = [
					'woodev_pickup_map_pickup_button_placement' => 'review',
					'woodev_pickup_map_pickup_replace_address'  => 'no',
					'woodev_pickup_map_pickup_close_on_select'  => 'yes',
				];

				return $stored[ $name ] ?? $default;
			}
		);

		$s = new Pickup_Map_Settings();

		$this->assertSame( 'review', $s->get_value( 'pickup_button_placement' ) );
		$this->assertFalse( $s->get_value( 'pickup_replace_address' ) );
		$this->assertTrue( $s->get_value( 'pickup_close_on_select' ) );
	}

	// -------------------------------------------------------------------------
	// current() — the shared accessor other framework classes read through
	// -------------------------------------------------------------------------

	public function test_current_returns_the_tab_singletons_handler(): void {
		$this->assertSame(
			Shipping_Settings_Tab::instance()->get_map_settings(),
			Pickup_Map_Settings::current()
		);
	}

	/**
	 * `current()` must be safe from a frontend request where no `Shipping_Plugin` has
	 * declared itself yet (the tab was never "needed", `register()` never ran) — both
	 * `Shipping_Settings_Tab::instance()` and `get_map_settings()` are lazy and never
	 * return `null`, so this must still hand back a real, usable handler.
	 */
	public function test_current_is_safe_when_the_tab_was_never_registered(): void {
		$this->assertInstanceOf( Pickup_Map_Settings::class, Pickup_Map_Settings::current() );
		$this->assertSame( 'rate', Pickup_Map_Settings::current()->get_value( 'pickup_button_placement' ) );
	}

	// -------------------------------------------------------------------------
	// Copy coverage (issue #373) — every field of the «Карта» section must carry
	// a tooltip, so the next field added here never ships bare. A single test
	// with an internal loop rather than a `@dataProvider`: providers run BEFORE
	// `setUp()`, and constructing `Pickup_Map_Settings` needs the `get_option`/
	// `wp_parse_args` stubs `setUp()` installs.
	// -------------------------------------------------------------------------

	public function test_every_field_has_a_non_empty_tooltip(): void {
		$s = new Pickup_Map_Settings();

		foreach ( $s->get_owned_setting_ids() as $id ) {
			$control = $s->get_setting( $id )->get_control();

			$this->assertNotNull( $control, "Setting \"{$id}\" has no control at all — a tooltip needs one." );
			$this->assertNotSame( '', $control->get_tooltip(), "Setting \"{$id}\" has an empty tooltip." );
		}
	}
}
