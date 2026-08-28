<?php
/**
 * Tests for Checkout_Handler::guard_custom_settlement() — the server-side
 * backstop for the #528 custom-settlement opt-in (issue #531).
 *
 * A cached checkout page, a stale JS bundle, or a hand-edited form never runs
 * `location-cascade.js`'s client-side `tags: true` gate at all, so #528's
 * option was previously unenforced server-side. This guard closes that hole:
 * when the option is OFF and the field mode is `ajax-select2`, the posted
 * settlement must match the customer's own picked
 * {@see \Woodev\Framework\Shipping\Location\Location_Record} (a REAL pick
 * leaves a record via `POST /location/select`; a select2 TAG pick does not).
 *
 * Covers the four axes named on the card:
 *   - option ON vs OFF
 *   - field mode `ajax-select2` vs the other two
 *   - posted value matching vs not matching the record
 *   - record absent
 *
 * plus: a blank posted value is not this method's concern (mirrors
 * `Pickup_Handler::handle_checkout_process()`), whitespace/case tolerance in
 * the match, the `label()` fallback when the record carries no `settlement`
 * component, and standing down when no settlement field exists on the
 * requested section at all (Rule 7b/effective_fields() "KNOWN CONSEQUENCE").
 *
 * @package Woodev\Tests\Unit\Shipping\Checkout
 */

namespace Woodev\Tests\Unit\Shipping\Checkout;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Checkout\Checkout_Fields;
use Woodev\Framework\Shipping\Checkout\Checkout_Handler;
use Woodev\Framework\Shipping\Checkout\Field;
use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
use Woodev\Framework\Shipping\Location\Location_Record;
use Woodev\Framework\Shipping\Location\Location_Service;
use Woodev\Tests\Unit\TestCase;

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
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-field.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-fields.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-condition.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-config.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-handler.php';

/**
 * A directly-controlled fake {@see Location_Service} — same "each test builds
 * exactly the shape it needs" discipline as `Checkout_Handler_Fake_Location_Service`
 * in `CheckoutHandlerEnqueueTest`. Only the three methods
 * `guard_custom_settlement()` actually reads are overridden.
 */
final class Checkout_Handler_Custom_Settlement_Fake_Location_Service extends Location_Service {

	private bool $allowed;
	private string $mode;
	private ?Location_Record $record;

	public function __construct( bool $allowed, string $mode, ?Location_Record $record ) {
		$this->allowed = $allowed;
		$this->mode    = $mode;
		$this->record  = $record;
	}

	public function is_custom_settlement_allowed(): bool {
		return $this->allowed;
	}

	public function get_field_mode_settlement(): string {
		return $this->mode;
	}

	public function get_customer_record_at( string $level, ?string $for_country = null ): ?Location_Record {
		return Location_Record::LEVEL_SETTLEMENT === $level ? $this->record : null;
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::guard_custom_settlement
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::settlement_field_id_for_section
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::settlement_record_value
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::normalize_for_settlement_match
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::custom_settlement_error_message
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Config::location_i18n_strings
 */
class CheckoutHandlerCustomSettlementGuardTest extends TestCase {

	private const EXPECTED_MESSAGE = 'The selected locality is not in the list — pick it from the suggestions.';

	protected function setUp(): void {
		parent::setUp();
		// location_i18n_strings() runs the woodev_location_i18n filter — pass the
		// defaults array through unchanged (same pattern as every other test
		// exercising a Checkout_Config/Checkout_Handler filter call).
		Functions\when( 'apply_filters' )->returnArg( 2 );
		// Both columns carry the field by default (the ordinary, non-"force
		// shipping to billing" store) — see AGENT-RULES.md Rule 7b.
		Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );
	}

	/**
	 * @param array<string, mixed> $extra_field_args merged onto the base Field builder chain
	 *
	 * @return Checkout_Fields
	 */
	private static function fields_with_settlement( string $id = 'billing_city' ): Checkout_Fields {
		return Checkout_Fields::from_array( [
			Field::create( $id )->set_type( 'text' )->source_location( 'settlement' )->to_array(),
		] );
	}

	private static function settlement_record( string $name = 'Жуковский', ?string $label = null ): Location_Record {
		return Location_Record::from_array( [
			'key'         => 'dadata:1',
			'provider_id' => 'dadata',
			'level'       => Location_Record::LEVEL_SETTLEMENT,
			'country'     => 'RU',
			'settlement'  => [ 'name' => $name, 'type' => 'г' ],
			'label'       => $label ?? ( 'г ' . $name ),
		] );
	}

	private static function handler( bool $allowed, string $mode, ?Location_Record $record, string $field_id = 'billing_city' ): Checkout_Handler {
		return new Checkout_Handler(
			self::fields_with_settlement( $field_id ),
			'carrier',
			new Checkout_Handler_Custom_Settlement_Fake_Location_Service( $allowed, $mode, $record )
		);
	}

	// -----------------------------------------------------------------------
	// Axis 1 — option ON vs OFF
	// -----------------------------------------------------------------------

	public function test_option_on_passes_even_when_posted_value_does_not_match(): void {
		Functions\expect( 'wc_add_notice' )->never();

		$handler = self::handler( true, Location_Provider_Registry::MODE_AJAX_SELECT2, self::settlement_record( 'Жуковский' ) );

		$this->assertTrue(
			$handler->guard_custom_settlement( [ 'billing_city' => 'Совсем другой город' ], 'RU', 'billing' )
		);
	}

	public function test_option_off_blocks_a_non_matching_posted_value(): void {
		Functions\expect( 'wc_add_notice' )->once()->with( self::EXPECTED_MESSAGE, 'error' );

		$handler = self::handler( false, Location_Provider_Registry::MODE_AJAX_SELECT2, self::settlement_record( 'Жуковский' ) );

		$this->assertFalse(
			$handler->guard_custom_settlement( [ 'billing_city' => 'Совсем другой город' ], 'RU', 'billing' )
		);
	}

	// -----------------------------------------------------------------------
	// Axis 2 — field mode: ajax-select2 vs the other two
	// -----------------------------------------------------------------------

	/**
	 * @dataProvider non_ajax_select2_modes_provider
	 */
	public function test_non_ajax_select2_mode_is_never_checked( string $mode ): void {
		Functions\expect( 'wc_add_notice' )->never();

		$handler = self::handler( false, $mode, self::settlement_record( 'Жуковский' ) );

		$this->assertTrue(
			$handler->guard_custom_settlement( [ 'billing_city' => 'Совсем другой город' ], 'RU', 'billing' )
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function non_ajax_select2_modes_provider(): array {
		return [
			'typeahead'    => [ Location_Provider_Registry::MODE_TYPEAHEAD ],
			'related-list' => [ Location_Provider_Registry::MODE_RELATED_LIST ],
		];
	}

	// -----------------------------------------------------------------------
	// Axis 3 — posted value matching vs not matching the record
	// -----------------------------------------------------------------------

	public function test_posted_value_matching_the_record_passes(): void {
		Functions\expect( 'wc_add_notice' )->never();

		$handler = self::handler( false, Location_Provider_Registry::MODE_AJAX_SELECT2, self::settlement_record( 'Жуковский' ) );

		$this->assertTrue(
			$handler->guard_custom_settlement( [ 'billing_city' => 'Жуковский' ], 'RU', 'billing' )
		);
	}

	/**
	 * Tolerant of what a form round-trip legitimately changes: surrounding
	 * whitespace and case.
	 */
	public function test_match_is_tolerant_of_surrounding_whitespace_and_case(): void {
		Functions\expect( 'wc_add_notice' )->never();

		$handler = self::handler( false, Location_Provider_Registry::MODE_AJAX_SELECT2, self::settlement_record( 'Жуковский' ) );

		$this->assertTrue(
			$handler->guard_custom_settlement( [ 'billing_city' => '  жуковский  ' ], 'RU', 'billing' )
		);
	}

	public function test_posted_value_not_matching_the_record_blocks(): void {
		Functions\expect( 'wc_add_notice' )->once()->with( self::EXPECTED_MESSAGE, 'error' );

		$handler = self::handler( false, Location_Provider_Registry::MODE_AJAX_SELECT2, self::settlement_record( 'Жуковский' ) );

		$this->assertFalse(
			$handler->guard_custom_settlement( [ 'billing_city' => 'Москва' ], 'RU', 'billing' )
		);
	}

	/**
	 * The comparison reads the record's `settlement()['name']` — bare, without its
	 * `type` — never the `label()`, which carries ancestors (e.g. "Московская
	 * обл., г Жуковский"). Posting the label must NOT be treated as a match.
	 */
	public function test_posted_label_text_does_not_match_the_bare_settlement_name(): void {
		Functions\expect( 'wc_add_notice' )->once()->with( self::EXPECTED_MESSAGE, 'error' );

		$handler = self::handler(
			false,
			Location_Provider_Registry::MODE_AJAX_SELECT2,
			self::settlement_record( 'Жуковский', 'Московская обл., г Жуковский' )
		);

		$this->assertFalse(
			$handler->guard_custom_settlement( [ 'billing_city' => 'Московская обл., г Жуковский' ], 'RU', 'billing' )
		);
	}

	/**
	 * When the record carries no `settlement` component group at all, the
	 * derivation falls back to the record's `label()` (mirrors
	 * `fieldValueFor()`'s own fallback in `location-cascade.js`).
	 */
	public function test_falls_back_to_label_when_settlement_component_is_absent(): void {
		Functions\expect( 'wc_add_notice' )->never();

		$record = Location_Record::from_array( [
			'key'         => 'dadata:2',
			'provider_id' => 'dadata',
			'level'       => Location_Record::LEVEL_SETTLEMENT,
			'country'     => 'RU',
			'label'       => 'Жуковский',
		] );

		$handler = self::handler( false, Location_Provider_Registry::MODE_AJAX_SELECT2, $record );

		$this->assertTrue(
			$handler->guard_custom_settlement( [ 'billing_city' => 'Жуковский' ], 'RU', 'billing' )
		);
	}

	// -----------------------------------------------------------------------
	// Axis 4 — record absent
	// -----------------------------------------------------------------------

	public function test_record_absent_blocks_a_non_blank_posted_value(): void {
		Functions\expect( 'wc_add_notice' )->once()->with( self::EXPECTED_MESSAGE, 'error' );

		$handler = self::handler( false, Location_Provider_Registry::MODE_AJAX_SELECT2, null );

		$this->assertFalse(
			$handler->guard_custom_settlement( [ 'billing_city' => 'Жуковский' ], 'RU', 'billing' )
		);
	}

	// -----------------------------------------------------------------------
	// A blank posted value is not this method's concern (mirrors
	// Pickup_Handler::handle_checkout_process()'s own docblock).
	// -----------------------------------------------------------------------

	public function test_blank_posted_value_is_not_this_methods_concern(): void {
		Functions\expect( 'wc_add_notice' )->never();

		$handler = self::handler( false, Location_Provider_Registry::MODE_AJAX_SELECT2, self::settlement_record( 'Жуковский' ) );

		$this->assertTrue(
			$handler->guard_custom_settlement( [ 'billing_city' => '' ], 'RU', 'billing' )
		);
		$this->assertTrue(
			$handler->guard_custom_settlement( [ 'billing_city' => '   ' ], 'RU', 'billing' )
		);
		$this->assertTrue(
			$handler->guard_custom_settlement( [], 'RU', 'billing' )
		);
	}

	// -----------------------------------------------------------------------
	// No settlement field on the requested section — the guard has nothing
	// to check and stands down (Rule 7b / effective_fields() "KNOWN
	// CONSEQUENCE" — a legitimate configuration, not an error).
	// -----------------------------------------------------------------------

	public function test_stands_down_when_no_settlement_field_exists_on_the_active_section(): void {
		Functions\expect( 'wc_add_notice' )->never();
		// force-shipping-to-billing: only the billing_* variant exists at all.
		Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( true );

		$handler = self::handler( false, Location_Provider_Registry::MODE_AJAX_SELECT2, self::settlement_record( 'Жуковский' ) );

		$this->assertTrue(
			$handler->guard_custom_settlement( [ 'shipping_city' => 'Совсем другой город' ], 'RU', 'shipping' )
		);
	}

	// -----------------------------------------------------------------------
	// Both columns carry a settlement field (Rule 7b fan-out, the ordinary
	// non-"force shipping to billing" store) — the section match in
	// settlement_field_id_for_section() must be load-bearing: the OTHER
	// column's posted value must never leak into the comparison.
	// -----------------------------------------------------------------------

	public function test_active_section_billing_reads_the_billing_field_when_both_columns_carry_one(): void {
		Functions\expect( 'wc_add_notice' )->never();

		$handler = self::handler( false, Location_Provider_Registry::MODE_AJAX_SELECT2, self::settlement_record( 'Жуковский' ) );

		$this->assertTrue(
			$handler->guard_custom_settlement(
				[ 'billing_city' => 'Жуковский', 'shipping_city' => 'Москва' ],
				'RU',
				'billing'
			)
		);
	}

	public function test_active_section_shipping_reads_the_shipping_field_when_both_columns_carry_one(): void {
		Functions\expect( 'wc_add_notice' )->once()->with( self::EXPECTED_MESSAGE, 'error' );

		$handler = self::handler( false, Location_Provider_Registry::MODE_AJAX_SELECT2, self::settlement_record( 'Жуковский' ) );

		$this->assertFalse(
			$handler->guard_custom_settlement(
				[ 'billing_city' => 'Жуковский', 'shipping_city' => 'Москва' ],
				'RU',
				'shipping'
			)
		);
	}

	// -----------------------------------------------------------------------
	// Country is passed through explicitly, never left to fall back.
	// -----------------------------------------------------------------------

	public function test_country_is_forwarded_to_get_customer_record_at(): void {
		$record = self::settlement_record( 'Жуковский' );

		$service = new class( $record ) extends Location_Service {
			public ?string $seen_country = null;
			private Location_Record $record;

			public function __construct( Location_Record $record ) {
				$this->record = $record;
			}

			public function is_custom_settlement_allowed(): bool {
				return false;
			}

			public function get_field_mode_settlement(): string {
				return Location_Provider_Registry::MODE_AJAX_SELECT2;
			}

			public function get_customer_record_at( string $level, ?string $for_country = null ): ?Location_Record {
				$this->seen_country = $for_country;

				return $this->record;
			}
		};

		$handler = new Checkout_Handler( self::fields_with_settlement(), 'carrier', $service );

		$handler->guard_custom_settlement( [ 'billing_city' => 'Жуковский' ], 'KZ', 'billing' );

		$this->assertSame( 'KZ', $service->seen_country );
	}
}
