<?php
/**
 * Tests for the My Account address forms (issue #332): the asset gate in
 * Checkout_Handler::enqueue_assets() and the `woocommerce_customer_save_address` hook.
 *
 * WooCommerce writes the address TEXT to user meta itself; the hook only decides whether our stored
 * settlement RECORD still names the customer's delivery city, and never writes anything.
 *
 * @package Woodev\Tests\Unit\Shipping\Checkout
 */

namespace Woodev\Tests\Unit\Shipping\Checkout;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Checkout\Checkout_Fields;
use Woodev\Framework\Shipping\Checkout\Checkout_Handler;
use Woodev\Framework\Shipping\Checkout\Field;
use Woodev\Framework\Shipping\Location\Location_Provider;
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
 * Fake service: active flag and the settlement-level customer record vary; the rest is inert.
 */
final class Account_Address_Fake_Service extends Location_Service {

	public int $forgotten = 0;
	private bool $active;
	private ?Location_Record $record;

	public function __construct( bool $active, ?Location_Record $record = null ) {
		$this->active = $active;
		$this->record = $record;
	}

	public function is_active(): bool {
		return $this->active;
	}

	public function get_customer_record_at( string $level, ?string $for_country = null ): ?Location_Record {
		return $this->record;
	}

	public function forget_customer_record(): void {
		++$this->forgotten;
	}

	public function get_customer_record( ?string $for_country = null ): ?array {
		return null === $this->record ? null : [ 'record' => $this->record, 'implicit' => true ];
	}

	public function get_customer_chain( ?string $for_country = null ): ?array {
		return null;
	}

	public function get_default_locality_policy(): string {
		return 'none';
	}

	public function is_country_supported( string $country, ?string $level = null ): bool {
		return false;
	}

	public function provider_for_level( string $level, ?string $country = null ): ?Location_Provider {
		return null;
	}

	public function get_field_mode_region(): string {
		return \Woodev\Framework\Shipping\Location\Location_Provider_Registry::MODE_TYPEAHEAD;
	}

	public function get_field_mode_settlement(): string {
		return \Woodev\Framework\Shipping\Location\Location_Provider_Registry::MODE_TYPEAHEAD;
	}

	public function is_region_field_removed(): bool {
		return false;
	}

	public function is_custom_settlement_allowed(): bool {
		return false;
	}

	public function owns_region_states( string $country, array $final_states ): bool {
		return false;
	}

	public function resolve_default_country(): string {
		return 'RU';
	}

	public function get_popular_settlements_for_country( string $country ): array {
		return [];
	}
}

/**
 * Every asset reports as built; the WooCommerce customer is a controllable fake.
 */
final class Account_Address_Handler extends Checkout_Handler {

	public ?object $customer = null;
	public string $slug      = '';
	public ?object $saved    = null;

	protected static function asset_exists( string $path ): bool {
		return true;
	}

	protected function wc_customer() {
		return $this->customer;
	}

	protected function saved_customer( int $user_id ): ?object {
		return $this->saved;
	}

	protected function account_edit_address_slug(): string {
		return $this->slug;
	}
}

/**
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::enqueue_assets
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::handle_customer_save_address
 */
class CheckoutHandlerAccountAddressTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'rest_url' )->justReturn( 'https://example.test/wp-json/woodev/v1' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE' );
		Functions\when( 'plugins_url' )->alias(
			static fn( $path, $file ) => 'https://example.test/wp-content/plugins/x/' . basename( $path )
		);
		Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'wp_parse_args' )->alias(
			static fn( $args, $defaults = [] ) => array_merge( (array) $defaults, (array) $args )
		);
		Functions\when( 'sanitize_title' )->returnArg();

		\Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab::reset_for_tests();
	}

	protected function tearDown(): void {
		\Woodev\Framework\Shipping\Settings\Shipping_Settings_Tab::reset_for_tests();
		parent::tearDown();
	}

	private function record(): Location_Record {
		return Location_Record::from_array(
			[
				'key'         => 'test:44',
				'provider_id' => 'test',
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
				'label'       => 'Казань, Татарстан',
				'settlement'  => [ 'name' => 'Казань', 'type' => 'г' ],
			]
		);
	}

	private function customer( string $billing_city, string $shipping_city ): object {
		return new class( $billing_city, $shipping_city ) {
			private string $billing;
			private string $shipping;
			public function __construct( string $billing, string $shipping ) {
				$this->billing  = $billing;
				$this->shipping = $shipping;
			}
			public function get_billing_city(): string {
				return $this->billing;
			}
			public function get_shipping_city(): string {
				return $this->shipping;
			}
		};
	}

	// -------------------------------------------------------------------------
	// Enqueue gate
	// -------------------------------------------------------------------------

	/**
	 * @return array{0: array, 1: array}
	 */
	private function enqueue_on_account( ?string $slug, bool $active = true, ?Location_Record $record = null, string $saved_city = '', bool $logged_in = true ): array {
		Functions\when( 'is_user_logged_in' )->justReturn( $logged_in );

		$scripts   = [];
		$localized = [];
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle, $src, $deps ) use ( &$scripts ) {
				$scripts[ $handle ] = [ 'src' => $src, 'deps' => $deps ];
			}
		);
		Functions\when( 'wp_enqueue_style' )->justReturn( true );
		Functions\when( 'wp_localize_script' )->alias(
			static function ( $handle, $name, $data ) use ( &$localized ) {
				$localized[] = [ $handle, $name, $data ];
			}
		);

		$fields  = Checkout_Fields::from_array( [ Field::create( 'billing_city' )->source_location( 'settlement' )->to_array() ] );
		$handler = new Account_Address_Handler( $fields, 'carrier', new Account_Address_Fake_Service( $active, $record ) );
		$handler->customer = $this->customer( $saved_city, $saved_city );
		$handler->slug     = (string) $slug;
		$handler->enqueue_assets();

		return [ $scripts, $localized ];
	}

	public function test_the_billing_form_enqueues_the_location_layer_with_billing_ids_only(): void {
		[ $scripts, $localized ] = $this->enqueue_on_account( 'billing' );

		$this->assertArrayHasKey( 'woodev-location-cascade', $scripts );
		$this->assertArrayNotHasKey( 'woodev-checkout-field-classic', $scripts );
		$this->assertArrayNotHasKey( 'woodev-phone-mask', $scripts );
		$this->assertCount( 1, $localized );

		$config = $localized[0][2];
		$this->assertSame( 'account', $config['context'] );
		$this->assertSame( 'billing', $config['accountSection'] );
		$this->assertSame( [ 'billing_city' ], array_keys( $config['fields'] ) );
		$this->assertSame( [], $config['takeover'] );
		$this->assertSame( [], $config['pickup_method_ids'] );
	}

	public function test_the_shipping_form_describes_the_shipping_field_only(): void {
		[ , $localized ] = $this->enqueue_on_account( 'shipping' );

		$config = $localized[0][2];
		$this->assertSame( 'shipping', $config['accountSection'] );
		$this->assertSame( [ 'shipping_city' ], array_keys( $config['fields'] ) );
		$this->assertSame( 'shipping', $config['fields']['shipping_city']['section'] );
	}

	public function test_the_address_overview_and_other_account_pages_enqueue_nothing(): void {
		[ $scripts, $localized ] = $this->enqueue_on_account( null );
		$this->assertSame( [], $scripts );
		$this->assertSame( [], $localized );

		[ $scripts ] = $this->enqueue_on_account( '' );
		$this->assertSame( [], $scripts );

		[ $scripts ] = $this->enqueue_on_account( 'something-else' );
		$this->assertSame( [], $scripts );
	}

	public function test_a_logged_out_visitor_gets_nothing_enqueued(): void {
		[ $scripts, $localized ] = $this->enqueue_on_account( 'billing', true, null, '', false );

		$this->assertSame( [], $scripts );
		$this->assertSame( [], $localized );
	}

	public function test_an_inactive_layer_enqueues_nothing(): void {
		[ $scripts, $localized ] = $this->enqueue_on_account( 'billing', false );

		$this->assertSame( [], $scripts );
		$this->assertSame( [], $localized );
	}

	public function test_a_record_the_saved_city_names_still_scopes_the_form(): void {
		[ , $localized ] = $this->enqueue_on_account( 'shipping', true, $this->record(), 'г. Казань' );

		$location = $localized[0][2]['location'];
		$this->assertSame( 'settlement', $location['current']['level'] );
		$this->assertNull( $location['defaultLocality'] );
	}

	public function test_a_record_the_saved_city_does_not_name_is_not_handed_to_the_form(): void {
		foreach ( [ 'Москва', '' ] as $saved ) {
			[ , $localized ] = $this->enqueue_on_account( 'shipping', true, $this->record(), $saved );

			$location = $localized[0][2]['location'];
			$this->assertNull( $location['current'], "saved city '{$saved}'" );
			$this->assertSame( [], $location['chain'] );
			$this->assertFalse( $location['implicit'] );
			$this->assertNull( $location['defaultLocality'] );
		}
	}

	// -------------------------------------------------------------------------
	// woocommerce_customer_save_address
	// -------------------------------------------------------------------------

	private function save( string $type, string $billing, string $shipping, ?Location_Record $record, bool $billing_only = false, int $user = 7, int $current = 7 ): int {
		Functions\when( 'get_current_user_id' )->justReturn( $current );
		Functions\when( 'wc_ship_to_billing_address_only' )->justReturn( $billing_only );

		$service = new Account_Address_Fake_Service( true, $record );
		$handler = new Account_Address_Handler( Checkout_Fields::from_array( [] ), 'carrier', $service );

		$handler->handle_customer_save_address( $user, $type, [], $this->customer( $billing, $shipping ) );

		return $service->forgotten;
	}

	/**
	 * The pre-9.8 hook signature: `( $user_id, $load_address )`, no customer object. The saved
	 * city is read from the freshly loaded customer, never from the pre-save `WC()->customer`.
	 */
	private function save_legacy( string $type, string $saved_billing, string $saved_shipping, string $stale_city, ?Location_Record $record ): int {
		Functions\when( 'get_current_user_id' )->justReturn( 7 );

		$service = new Account_Address_Fake_Service( true, $record );
		$handler = new Account_Address_Handler( Checkout_Fields::from_array( [] ), 'carrier', $service );

		$handler->customer = $this->customer( $stale_city, $stale_city );
		$handler->saved    = $this->customer( $saved_billing, $saved_shipping );

		$handler->handle_customer_save_address( 7, $type );

		return $service->forgotten;
	}

	public function test_the_legacy_hook_signature_reads_the_saved_city_not_the_pre_save_customer(): void {
		// The pre-save copy still says Kazan (matches the record); the saved city is Moscow.
		$this->assertSame( 1, $this->save_legacy( 'shipping', 'Москва', 'Москва', 'Казань', $this->record() ) );
		// The pre-save copy says Moscow; the saved city is Kazan, which the record names.
		$this->assertSame( 0, $this->save_legacy( 'shipping', 'Казань', 'Казань', 'Москва', $this->record() ) );
	}

	public function test_the_legacy_hook_signature_without_a_loadable_customer_changes_nothing(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 7 );

		$service = new Account_Address_Fake_Service( true, $this->record() );
		$handler = new Account_Address_Handler( Checkout_Fields::from_array( [] ), 'carrier', $service );

		$handler->handle_customer_save_address( 7, 'billing' );

		$this->assertSame( 0, $service->forgotten );
	}

	public function test_a_saved_shipping_city_that_names_another_settlement_forgets_the_record(): void {
		$this->assertSame( 1, $this->save( 'shipping', 'Казань', 'Москва', $this->record() ) );
	}

	public function test_a_saved_shipping_city_that_names_the_record_keeps_it(): void {
		$this->assertSame( 0, $this->save( 'shipping', 'Москва', ' казань ', $this->record() ) );
	}

	public function test_saving_the_billing_form_re_checks_the_record_against_the_delivery_city(): void {
		// The delivery (shipping) city is Kazan: a billing save keeps the record whatever the billing city says.
		$this->assertSame( 0, $this->save( 'billing', 'Москва', 'Казань', $this->record() ) );
		// The delivery city is elsewhere: a record left by a billing pick no longer describes it.
		$this->assertSame( 1, $this->save( 'billing', 'Казань', 'Москва', $this->record() ) );
	}

	public function test_without_a_saved_shipping_city_the_billing_city_is_the_delivery_one(): void {
		$this->assertSame( 0, $this->save( 'billing', 'Казань', '', $this->record() ) );
		$this->assertSame( 1, $this->save( 'billing', 'Москва', '', $this->record() ) );
	}

	public function test_a_ship_to_billing_store_judges_the_billing_city_whatever_shipping_holds(): void {
		$this->assertSame( 0, $this->save( 'billing', 'Казань', 'Москва', $this->record(), true ) );
		$this->assertSame( 1, $this->save( 'billing', 'Москва', 'Казань', $this->record(), true ) );
	}

	public function test_a_blank_delivery_city_and_an_absent_record_never_forget(): void {
		$this->assertSame( 0, $this->save( 'shipping', '', '', $this->record() ) );
		$this->assertSame( 0, $this->save( 'shipping', 'Москва', 'Москва', null ) );
	}

	public function test_a_save_for_another_user_or_an_unknown_address_type_is_ignored(): void {
		$this->assertSame( 0, $this->save( 'shipping', 'Москва', 'Москва', $this->record(), false, 7, 9 ) );
		$this->assertSame( 0, $this->save( 'other', 'Москва', 'Москва', $this->record() ) );
	}
}
