<?php
/**
 * Integration: write/read vocabulary agreement on Provider_Selection_Scope
 * (issue #313, review finding F4 on issue #159/PR #312).
 *
 * `Provider_Selection_Scope::locality_for_point()` MUST answer in the SAME
 * vocabulary as `current_locality()` (a Location Provider layer key, e.g.
 * `dadata:fias-1`) — see that abstract class' own docblock. Before this file,
 * nothing proved that agreement for the rig's OWN fixture
 * (`Woodev_Test_Provider_Selection_Scope`): `ProviderSelectionScopeTest`
 * (unit) exercises the abstract contract against its own, deliberately
 * correct subclass, and neither `LocationRouteTest` nor `PickupRouteTest`
 * chains a `/location/select` write through a `/shipping/pickup/.../select`
 * confirmation and back out through `Pickup_Handler::restore_selection()`.
 * A fixture whose `locality_for_point()` regressed to reading the carrier's
 * own city name (`$point->get_locality()`, "Москва") instead of
 * `current_locality()` (`dadata:fias-1`) would still pass every test that
 * existed before this file — `remember()`/`recall()` would simply key off
 * two vocabularies that never match, silently, exactly the failure mode this
 * test exists to catch.
 *
 * @package Woodev\Tests\Integration\Shipping
 * @since   2.0.2
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Framework\Shipping\Location\Location_Provider_Registry;
use Woodev\Tests\Integration\TestCase;
use WP_REST_Request;

class ProviderSelectionScopeAgreementTest extends TestCase {

	/**
	 * The fixture shipping plugin's own id — the literal segment its
	 * `Pickup_Controller` route is registered under (`PLUGIN_ID` on
	 * `Woodev_Test_Shipping_Method_Plugin`).
	 *
	 * @var string
	 */
	private const PLUGIN_SLUG = 'woodev-test-shipping-method';

	/**
	 * The fixture shipping method's own id — the ONLY method
	 * `Woodev_Test_Provider_Selection_Scope::type_for_method()` answers a type
	 * for (its own literal check); every other method id gets `null`, meaning
	 * no selection is ever restored.
	 *
	 * @var string
	 */
	private const METHOD_ID = 'woodev_test_shipping';

	/**
	 * The checkout field id `Pickup_Handler` was constructed with in
	 * `woodev_test_shipping_method_plugin_init()` (2nd constructor argument,
	 * `'carrier_pickup_point'`) — both the write side
	 * (`remember_selection()`'s `$context['field_id']` gate) and the read side
	 * (`restore_selection()`'s `$key` gate) key off this literal.
	 *
	 * @var string
	 */
	private const FIELD_ID = 'carrier_pickup_point';

	/**
	 * The DaData provider's store-level token option — same literal
	 * `LocationRouteTest::OPTION_DADATA_TOKEN` uses, and for the same reason:
	 * `Location_Service::is_active()` gates on it once the registry is open.
	 *
	 * @var string
	 */
	private const OPTION_DADATA_TOKEN = 'woodev_location_token';

	/**
	 * The literal session key `Customer_Location_Store` persists a guest's
	 * current location record under — mirrors `LocationRouteTest`'s own
	 * `CUSTOMER_LOCATION_SESSION_KEY` constant and exists for the same
	 * teardown reason (see that file's own docblock on the matter): a guest
	 * record this test writes must not leak into whichever integration test
	 * runs next in the same PHP process.
	 *
	 * @var string
	 */
	private const CUSTOMER_LOCATION_SESSION_KEY = 'woodev_customer_location';

	/**
	 * The fixture's own `Pickup_Selection` storage key — mirrors
	 * `Woodev_Test_Provider_Selection_Scope::session_key()`'s own literal.
	 * Cleared in {@see self::tearDown()} for the same process-wide-session
	 * leakage reason as {@see self::CUSTOMER_LOCATION_SESSION_KEY}.
	 *
	 * @var string
	 */
	private const PICKUP_SELECTION_SESSION_KEY = 'woodev_test_provider_pickup_selection';

	/**
	 * The option's value as found at the start of THIS test, captured in
	 * {@see self::setUp()} and restored in {@see self::tearDown()}. `false`
	 * means the option did not exist at all.
	 *
	 * @var string|false
	 */
	private $original_dadata_token;

	/**
	 * The ambient fixture plugin's `Pickup_Handler::$source` as found at the
	 * start of THIS test, captured in {@see self::setUp()} and restored in
	 * {@see self::tearDown()} — same reflection idiom `PickupRouteTest` already
	 * uses, and for the same reason: this test must run against a known,
	 * network-free `Point_Source` (`FIX-BULK-1`, locality "Москва")
	 * regardless of which live-carrier wp-config constants the rig happens to
	 * define.
	 *
	 * @var \Woodev\Framework\Shipping\Pickup\Point_Source
	 */
	private $original_ambient_point_source;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->assertTrue(
			function_exists( 'woodev_test_shipping_method_plugin' ),
			'woodev_test_shipping_method_plugin() must exist — the shipping fixture plugin must be active in wp-env.'
		);

		$this->original_ambient_point_source = $this->ambient_point_source();
		$this->force_ambient_point_source( new \Woodev_Test_Bulk_Point_Source() );

		// A fresh gate for every test — same reasoning as LocationRouteTest::setUp():
		// the registry is a fleet-wide singleton a prior test may already have opened.
		Location_Provider_Registry::instance()->reset_for_tests();

		// Neutralise whatever the rig environment happens to have seeded — same
		// reasoning as LocationRouteTest::setUp(): a test asserting behaviour that
		// depends on an explicit token must never rely on ambient DB state.
		$this->original_dadata_token = get_option( self::OPTION_DADATA_TOKEN, false );
		delete_option( self::OPTION_DADATA_TOKEN );
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		if ( false === $this->original_dadata_token ) {
			delete_option( self::OPTION_DADATA_TOKEN );
		} else {
			update_option( self::OPTION_DADATA_TOKEN, $this->original_dadata_token );
		}

		Location_Provider_Registry::instance()->reset_for_tests();

		$this->force_ambient_point_source( $this->original_ambient_point_source );

		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::CUSTOMER_LOCATION_SESSION_KEY, null );
			WC()->session->set( self::PICKUP_SELECTION_SESSION_KEY, null );
			WC()->session->set( 'chosen_shipping_methods', null );
		}

		parent::tearDown();
	}

	/**
	 * Reads the ambient fixture plugin's current `Pickup_Handler::$source` via
	 * reflection — no public accessor exists (production code has no reason to
	 * swap it at runtime).
	 *
	 * @return \Woodev\Framework\Shipping\Pickup\Point_Source
	 */
	private function ambient_point_source(): \Woodev\Framework\Shipping\Pickup\Point_Source {
		$handler  = woodev_test_shipping_method_plugin()->get_pickup_handler();
		$property = new \ReflectionProperty( $handler, 'source' );
		$property->setAccessible( true );

		return $property->getValue( $handler );
	}

	/**
	 * Swaps the ambient fixture plugin's `Pickup_Handler::$source` and rebuilds
	 * the REST server so the swap takes effect — the exact idiom `PickupRouteTest`
	 * already uses.
	 *
	 * @param \Woodev\Framework\Shipping\Pickup\Point_Source $source the source the ambient route should serve.
	 *
	 * @return void
	 */
	private function force_ambient_point_source( \Woodev\Framework\Shipping\Pickup\Point_Source $source ): void {
		$handler  = woodev_test_shipping_method_plugin()->get_pickup_handler();
		$property = new \ReflectionProperty( $handler, 'source' );
		$property->setAccessible( true );
		$property->setValue( $handler, $source );

		do_action( 'rest_api_init' );

		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
	}

	/**
	 * Opens the Location Provider layer gate and rebuilds the REST server —
	 * the same "declare, collect, rebuild" sequence
	 * `LocationRouteTest::activate_and_boot_rest()` uses. Also re-registers the
	 * ambient pickup routes (`Pickup_Handler::register_rest()` is hooked on the
	 * same `rest_api_init` this fires), so a single rebuild covers both halves
	 * of this test's flow.
	 *
	 * @return void
	 */
	private function open_location_gate_and_boot_routes(): void {
		$registry = Location_Provider_Registry::instance();

		$registry->declare_needed();
		$registry->collect();

		do_action( 'rest_api_init' );

		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
	}

	/**
	 * Proves `Woodev_Test_Provider_Selection_Scope::locality_for_point()` and
	 * `current_locality()` speak the same vocabulary end to end, through the
	 * REAL production write/read paths — not by asserting on the scope
	 * directly (that is `ProviderSelectionScopeTest`'s job, against its own
	 * fixture):
	 *
	 * 1. `/woodev/v1/location/select` persists a `dadata:fias-1` locality
	 *    record — the Location Provider layer's own key format.
	 * 2. `WC()->session` records the fixture's shipping method as chosen —
	 *    the ONLY method id the scope's `type_for_method()` answers.
	 * 3. `/woodev/v1/shipping/pickup/woodev-test-shipping-method/select`
	 *    confirms `FIX-BULK-1`, firing `woodev_shipping_pickup_point_selected`
	 *    -> `Pickup_Handler::remember_selection()` -> `Pickup_Selection::remember()`
	 *    keyed on `$scope->locality_for_point( $point )`.
	 * 4. `apply_filters( 'woocommerce_checkout_get_value', ... )` drives
	 *    `Pickup_Handler::restore_selection()` -> `current_selection_pair()`
	 *    -> `Pickup_Selection::recall_latest()` keyed on
	 *    `$scope->current_locality()`.
	 *
	 * If step 3's write and step 4's read ever key off two different
	 * localities, `recall_latest()` returns `null` and the filter falls back
	 * to its incoming `$value` (`null` here) instead of `FIX-BULK-1` —
	 * silently, with no error, exactly the failure mode
	 * `Provider_Selection_Scope`'s own docblock warns about.
	 *
	 * @return void
	 */
	public function test_a_confirmed_point_is_restored_through_the_scopes_own_vocabulary(): void {
		$this->open_location_gate_and_boot_routes();

		wp_set_current_user( 0 );

		$select_locality = new WP_REST_Request( 'POST', '/woodev/v1/location/select' );
		$select_locality->set_param(
			'record',
			[
				'key'         => 'dadata:fias-1',
				'provider_id' => 'dadata',
				'level'       => 'settlement',
				'country'     => 'RU',
			]
		);
		$select_locality->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		// The location write goes through the real REST route too — same
		// active-layer precondition as LocationRouteTest's own active-path
		// tests (a fake, never network-reaching token is enough; see that
		// file's own docblock for why /select never reaches the provider's
		// HTTP client).
		update_option( self::OPTION_DADATA_TOKEN, 'test-integration-token' );

		$locality_response = rest_get_server()->dispatch( $select_locality );

		$this->assertSame(
			200,
			$locality_response->get_status(),
			'setup precondition: the locality write must persist before a pickup selection can be confirmed against it.'
		);

		WC()->session->set( 'chosen_shipping_methods', [ self::METHOD_ID ] );

		$select_point = new WP_REST_Request(
			'POST',
			'/woodev/v1/shipping/pickup/' . self::PLUGIN_SLUG . '/select'
		);
		$select_point->set_param( 'field_id', self::FIELD_ID );
		$select_point->set_param( 'point_id', 'FIX-BULK-1' );
		$select_point->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$select_response = rest_get_server()->dispatch( $select_point );
		$select_data     = $select_response->get_data();

		$this->assertSame( 200, $select_response->get_status(), 'setup precondition: the point confirmation must succeed.' );
		$this->assertTrue(
			$select_data['allowed'],
			'setup precondition: FIX-BULK-1 must be confirmed allowed — remember_selection() only fires on a true verdict.'
		);

		$restored = apply_filters( 'woocommerce_checkout_get_value', null, self::FIELD_ID );

		$this->assertSame(
			'FIX-BULK-1',
			$restored,
			'the confirmed point must be restored — proves locality_for_point() and current_locality() agree on the same vocabulary.'
		);
	}

	/**
	 * Issue #334, end to end through the same real production paths: refining the
	 * ADDRESS inside a settlement the customer already picked must not move the
	 * pickup locality.
	 *
	 * The step that used to break it is step 4 — `/location/select` is posted for
	 * EVERY level of the cascade, address included (`location-cascade.js`'s own
	 * `onSelectFor`), so the customer's CURRENT record becomes address-level as
	 * soon as they pick an address from the suggestions. While
	 * `current_locality()` answered the CURRENT record's key, the point confirmed
	 * at step 3 was written under the settlement key and read back under the
	 * address key: `recall_latest()` missed, the filter fell back to its incoming
	 * `null`, and the customer saw «Выберите ПВЗ» over a point they had already
	 * chosen. Nothing was lost — it became unreachable, silently, which is why no
	 * test and no HTTP probe caught it; the operator found it by clicking.
	 *
	 * The address record carries its settlement in the ancestor set, exactly as
	 * the DaData provider publishes one, so this also exercises the chain's own
	 * ancestor-compatibility check rather than only its "keep when the provider
	 * published nothing" bypass.
	 *
	 * @return void
	 */
	public function test_refining_the_address_does_not_move_the_pickup_locality(): void {
		$this->open_location_gate_and_boot_routes();

		wp_set_current_user( 0 );

		update_option( self::OPTION_DADATA_TOKEN, 'test-integration-token' );

		$select_settlement = new WP_REST_Request( 'POST', '/woodev/v1/location/select' );
		$select_settlement->set_param(
			'record',
			[
				'key'         => 'dadata:fias-1',
				'provider_id' => 'dadata',
				'level'       => 'settlement',
				'country'     => 'RU',
			]
		);
		$select_settlement->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$this->assertSame(
			200,
			rest_get_server()->dispatch( $select_settlement )->get_status(),
			'setup precondition: the settlement write must persist first.'
		);

		WC()->session->set( 'chosen_shipping_methods', [ self::METHOD_ID ] );

		$select_point = new WP_REST_Request(
			'POST',
			'/woodev/v1/shipping/pickup/' . self::PLUGIN_SLUG . '/select'
		);
		$select_point->set_param( 'field_id', self::FIELD_ID );
		$select_point->set_param( 'point_id', 'FIX-BULK-1' );
		$select_point->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$select_data = rest_get_server()->dispatch( $select_point )->get_data();

		$this->assertTrue(
			$select_data['allowed'],
			'setup precondition: FIX-BULK-1 must be confirmed allowed before the address step.'
		);

		// THE STEP THAT USED TO BREAK IT — an address INSIDE dadata:fias-1.
		$select_address = new WP_REST_Request( 'POST', '/woodev/v1/location/select' );
		$select_address->set_param(
			'record',
			[
				'key'         => 'dadata:fias-addr-1',
				'provider_id' => 'dadata',
				'level'       => 'address',
				'country'     => 'RU',
				'street'      => [ 'name' => 'Цветной', 'type' => 'б-р' ],
				'house'       => '1',
				'ancestors'   => [ 'dadata:fias-1' ],
			]
		);
		$select_address->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$address_response = rest_get_server()->dispatch( $select_address );

		$this->assertSame(
			200,
			$address_response->get_status(),
			'setup precondition: the address write must be accepted — the bug is what happens AFTER it.'
		);
		$this->assertSame(
			'address',
			$address_response->get_data()['current']['level'],
			'setup precondition: the customer\'s CURRENT record must really be address-level now, or this test proves nothing.'
		);

		$restored = apply_filters( 'woocommerce_checkout_get_value', null, self::FIELD_ID );

		$this->assertSame(
			'FIX-BULK-1',
			$restored,
			'the point chosen before the address pick must still be restored after it (issue #334).'
		);
	}
}
