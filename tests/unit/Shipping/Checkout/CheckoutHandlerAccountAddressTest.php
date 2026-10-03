<?php
/**
 * Tests for the My Account address forms (issue #332): the asset gate in
 * Checkout_Handler::enqueue_assets() and the `woocommerce_customer_save_address` hook.
 *
 * WooCommerce writes the address TEXT to user meta itself; the hook decides which settlement RECORD
 * describes the customer's delivery city: the record the form carried in its hidden field when it is
 * acceptable and names the saved city, otherwise the stored one unless it still names it.
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
	/** @var Location_Record[] records written through set_customer_record() */
	public array $written     = [];
	public bool $accepts_pick = true;
	public bool $write_result = true;
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

	public function set_customer_record( Location_Record $record, bool $implicit = false ): bool {
		$this->written[] = [ $record, $implicit ];

		return $this->write_result;
	}

	public function accepts_posted_pick( Location_Record $record ): bool {
		return $this->accepts_pick;
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
	public string $posted    = '';

	protected function posted_picked_record(): string {
		return $this->posted;
	}

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

	// -------------------------------------------------------------------------
	// The picked record on «Save address» (issue #332)
	// -------------------------------------------------------------------------

	private function picked_json( ?Location_Record $record = null ): string {
		return (string) json_encode( ( $record ?? $this->record() )->to_array(), JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Runs the hook with `$posted` as the form's hidden field.
	 *
	 * @return Account_Address_Fake_Service
	 */
	private function save_with_pick( string $posted, string $shipping, ?Location_Record $stored, array $options = [] ): Account_Address_Fake_Service {
		Functions\when( 'get_current_user_id' )->justReturn( $options['current'] ?? 7 );

		$service               = new Account_Address_Fake_Service( $options['active'] ?? true, $stored );
		$service->accepts_pick = $options['accepts'] ?? true;
		$service->write_result = $options['write_result'] ?? true;
		$handler               = new Account_Address_Handler( Checkout_Fields::from_array( [] ), 'carrier', $service );
		$handler->posted       = $posted;

		if ( ! empty( $options['legacy'] ) ) {
			$handler->saved = $this->customer( $shipping, $shipping );
			$handler->handle_customer_save_address( 7, 'shipping' );
		} else {
			$handler->handle_customer_save_address( 7, 'shipping', [], $this->customer( $shipping, $shipping ) );
		}

		return $service;
	}

	public function test_a_picked_record_naming_the_saved_city_is_persisted_as_the_explicit_record(): void {
		$service = $this->save_with_pick( $this->picked_json(), 'Казань', null );

		$this->assertCount( 1, $service->written );
		$this->assertSame( 'test:44', $service->written[0][0]->key() );
		$this->assertFalse( $service->written[0][1], 'an explicit record, never an implicit default' );
		$this->assertSame( 0, $service->forgotten );
	}

	public function test_a_picked_record_replaces_a_stored_record_for_another_city_without_forgetting_it(): void {
		$stored = Location_Record::from_array(
			[
				'key'         => 'test:77',
				'provider_id' => 'test',
				'level'       => Location_Record::LEVEL_SETTLEMENT,
				'country'     => 'RU',
				'label'       => 'Тверь',
				'settlement'  => [ 'name' => 'Тверь', 'type' => 'г' ],
			]
		);

		$service = $this->save_with_pick( $this->picked_json(), 'Казань', $stored );

		$this->assertCount( 1, $service->written );
		$this->assertSame( 0, $service->forgotten );
	}

	public function test_a_picked_record_naming_another_city_is_not_persisted_and_the_stale_record_is_forgotten(): void {
		$service = $this->save_with_pick( $this->picked_json(), 'Москва', $this->record() );

		$this->assertSame( [], $service->written );
		$this->assertSame( 1, $service->forgotten );
	}

	public function test_a_malformed_or_non_settlement_picked_record_falls_back_to_the_forget_path(): void {
		$region = Location_Record::from_array(
			[
				'key'         => 'test:r1',
				'provider_id' => 'test',
				'level'       => Location_Record::LEVEL_REGION,
				'country'     => 'RU',
				'label'       => 'Казань',
				'region'      => [ 'name' => 'Казань', 'type' => 'г' ],
			]
		);

		foreach ( [ 'not json', '"Казань"', '{"key":"x"}', '[]', $this->picked_json( $region ) ] as $posted ) {
			$service = $this->save_with_pick( $posted, 'Москва', $this->record() );

			$this->assertSame( [], $service->written, $posted );
			$this->assertSame( 1, $service->forgotten, $posted );
		}
	}

	public function test_a_pick_the_service_refuses_is_not_persisted(): void {
		$service = $this->save_with_pick( $this->picked_json(), 'Казань', null, [ 'accepts' => false ] );

		$this->assertSame( [], $service->written );
	}

	public function test_a_pick_the_service_refuses_leaves_a_stale_stored_record_forgotten(): void {
		$service = $this->save_with_pick( $this->picked_json(), 'Москва', $this->record(), [ 'accepts' => false ] );

		$this->assertSame( [], $service->written );
		$this->assertSame( 1, $service->forgotten );
	}

	public function test_a_failed_write_falls_back_to_the_forget_path(): void {
		$service = $this->save_with_pick( $this->picked_json(), 'Казань', $this->record(), [ 'write_result' => false ] );

		$this->assertCount( 1, $service->written );
		$this->assertSame( 0, $service->forgotten, 'the stored record still names the saved city' );
	}

	public function test_no_pick_keeps_the_current_behaviour(): void {
		$this->assertSame( [], $this->save_with_pick( '', 'Казань', $this->record() )->written );
		$this->assertSame( 0, $this->save_with_pick( '', 'Казань', $this->record() )->forgotten );
		$this->assertSame( 1, $this->save_with_pick( '', 'Москва', $this->record() )->forgotten );
	}

	public function test_a_blank_saved_city_or_an_inactive_layer_never_persists_a_pick(): void {
		$this->assertSame( [], $this->save_with_pick( $this->picked_json(), '', null )->written );
		$this->assertSame( [], $this->save_with_pick( $this->picked_json(), 'Казань', null, [ 'active' => false ] )->written );
	}

	public function test_a_pick_is_never_written_for_another_user(): void {
		$service = $this->save_with_pick( $this->picked_json(), 'Казань', null, [ 'current' => 9 ] );

		$this->assertSame( [], $service->written );
		$this->assertSame( 0, $service->forgotten );
	}

	public function test_a_guest_saves_nothing(): void {
		// A guest has no user id: the hook's own gate returns before anything is read.
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		$service         = new Account_Address_Fake_Service( true, null );
		$handler         = new Account_Address_Handler( Checkout_Fields::from_array( [] ), 'carrier', $service );
		$handler->posted = $this->picked_json();

		$handler->handle_customer_save_address( 0, 'shipping', [], $this->customer( 'Казань', 'Казань' ) );

		$this->assertSame( [], $service->written );
	}

	public function test_the_legacy_hook_signature_persists_the_pick_against_the_freshly_saved_city(): void {
		$service = $this->save_with_pick( $this->picked_json(), 'Казань', null, [ 'legacy' => true ] );
		$this->assertCount( 1, $service->written );

		$service = $this->save_with_pick( $this->picked_json(), 'Москва', $this->record(), [ 'legacy' => true ] );
		$this->assertSame( [], $service->written );
		$this->assertSame( 1, $service->forgotten );
	}

	public function test_the_pick_is_judged_against_the_delivery_city_not_the_form_that_was_saved(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 7 );

		// Saving the billing form while shipping delivers to Moscow: a Kazan pick must not become the record.
		$service         = new Account_Address_Fake_Service( true, null );
		$handler         = new Account_Address_Handler( Checkout_Fields::from_array( [] ), 'carrier', $service );
		$handler->posted = $this->picked_json();
		$handler->handle_customer_save_address( 7, 'billing', [], $this->customer( 'Казань', 'Москва' ) );

		$this->assertSame( [], $service->written );

		// No shipping city saved: the billing city is the delivery one and the pick is written.
		$service         = new Account_Address_Fake_Service( true, null );
		$handler         = new Account_Address_Handler( Checkout_Fields::from_array( [] ), 'carrier', $service );
		$handler->posted = $this->picked_json();
		$handler->handle_customer_save_address( 7, 'billing', [], $this->customer( 'Казань', '' ) );

		$this->assertCount( 1, $service->written );
	}

	// -- the request reader (the real posted_picked_record()) ------------------

	private function save_through_the_real_reader( array $post, bool $nonce_valid ): Account_Address_Fake_Service {
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->alias(
			static fn( $nonce, $action ) => $nonce_valid && 'N0NCE' === $nonce && 'woocommerce-edit_address' === $action ? 1 : false
		);

		$service = new Account_Address_Fake_Service( true, null );
		$handler = new Checkout_Handler( Checkout_Fields::from_array( [] ), 'carrier', $service );

		$_POST = $post;

		try {
			$handler->handle_customer_save_address( 7, 'shipping', [], $this->customer( 'Казань', 'Казань' ) );
		} finally {
			$_POST = [];
		}

		return $service;
	}

	public function test_the_real_reader_takes_the_field_from_a_nonce_checked_request(): void {
		$post = [
			'woocommerce-edit-address-nonce'   => 'N0NCE',
			Checkout_Handler::ACCOUNT_RECORD_FIELD => $this->picked_json(),
		];

		$this->assertCount( 1, $this->save_through_the_real_reader( $post, true )->written );
	}

	public function test_the_real_reader_ignores_the_field_without_a_valid_wc_nonce(): void {
		$post = [ Checkout_Handler::ACCOUNT_RECORD_FIELD => $this->picked_json() ];
		$this->assertSame( [], $this->save_through_the_real_reader( $post, true )->written );

		$post['woocommerce-edit-address-nonce'] = 'N0NCE';
		$this->assertSame( [], $this->save_through_the_real_reader( $post, false )->written );

		$post[ Checkout_Handler::ACCOUNT_RECORD_FIELD ] = [ 'array' => 'not a string' ];
		$this->assertSame( [], $this->save_through_the_real_reader( $post, true )->written );
	}
}
