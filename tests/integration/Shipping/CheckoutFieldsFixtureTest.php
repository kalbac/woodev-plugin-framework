<?php
/**
 * Integration: fixture checkout fields wiring.
 *
 * Proves that the shipping fixture's `get_checkout_handler()` override returns a
 * fully-configured `Checkout_Handler` and that every major branch of the field
 * layer (root select, dependent suggest, pickup slot) is exercised against real
 * WP + WC plumbing.
 *
 * Run with: composer test:integration
 * (requires wp-env; set WOODEV_FRAMEWORK_DIR inside the container or run from
 * inside wp-env's PHP context via `npx wp-env run tests-php phpunit ...`)
 *
 * @package Woodev\Tests\Integration\Shipping
 * @since   2.0.2
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Framework\Shipping\Checkout\Checkout_Handler;
use Woodev\Framework\Shipping\Checkout\Checkout_Fields;
use Woodev\Tests\Integration\TestCase;

class CheckoutFieldsFixtureTest extends TestCase {

	/**
	 * The shipping fixture plugin instance.
	 *
	 * @var \Woodev\Framework\Shipping\Shipping_Plugin
	 */
	private $plugin;

	/**
	 * Set up — resolve the fixture plugin and assert it's loaded.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->assertTrue(
			function_exists( 'woodev_test_shipping_method_plugin' ),
			'woodev_test_shipping_method_plugin() must exist — make sure the shipping fixture plugin is active in wp-env.'
		);

		$this->plugin = woodev_test_shipping_method_plugin();
	}

	// -------------------------------------------------------------------------
	// 1. Handler existence + type
	// -------------------------------------------------------------------------

	/**
	 * `get_checkout_handler()` must return a non-null Checkout_Handler.
	 *
	 * @return void
	 */
	public function test_get_checkout_handler_returns_handler_instance(): void {
		$handler = $this->plugin->get_checkout_handler();

		$this->assertInstanceOf(
			Checkout_Handler::class,
			$handler,
			'get_checkout_handler() should return a Checkout_Handler, not null or another type.'
		);
	}

	/**
	 * Repeated calls to `get_checkout_handler()` must return the SAME instance
	 * (caching pattern, matching other `get_*_handler()` seams).
	 *
	 * @return void
	 */
	public function test_get_checkout_handler_returns_same_instance_on_repeated_calls(): void {
		$first  = $this->plugin->get_checkout_handler();
		$second = $this->plugin->get_checkout_handler();

		$this->assertSame(
			$first,
			$second,
			'get_checkout_handler() must return the same cached instance on repeated calls.'
		);
	}

	// -------------------------------------------------------------------------
	// 2. Field presence
	// -------------------------------------------------------------------------

	/**
	 * The handler must manage `billing_state`, `billing_city`, and
	 * `carrier_pickup_point`.
	 *
	 * @return void
	 */
	public function test_handler_fields_contain_expected_ids(): void {
		$handler = $this->plugin->get_checkout_handler();
		$fields  = $handler->get_fields()->get_fields();

		$this->assertArrayHasKey( 'billing_state', $fields, 'billing_state field must be registered.' );
		$this->assertArrayHasKey( 'billing_city', $fields, 'billing_city field must be registered.' );
		$this->assertArrayHasKey( 'carrier_pickup_point', $fields, 'carrier_pickup_point field must be registered.' );
	}

	// -------------------------------------------------------------------------
	// 3. inject() enhances native billing fields in-place
	// -------------------------------------------------------------------------

	/**
	 * inject() must leave TAKEOVER fields exactly as WooCommerce shaped them.
	 *
	 * The server renders a guessed country (a guest has no country yet) while the customer
	 * may be looking at a different one, so a server-side type swap would mismatch what is
	 * displayed. Regions instead reach the field as WooCommerce NATIVE states through
	 * {@see Checkout_Handler::inject_states()}, and the city select is converted by the
	 * classic adapter for the ACTUAL country. With JavaScript off, WooCommerce's own field
	 * survives — acceptable progressive enhancement.
	 *
	 * @return void
	 */
	public function test_inject_leaves_takeover_fields_to_woocommerce(): void {
		$handler = $this->plugin->get_checkout_handler();

		// Simulate a WC checkout fields array with pre-existing billing section.
		$input = [
			'billing' => [
				'billing_state' => [ 'type' => 'text', 'label' => 'State', 'required' => false ],
				'billing_city'  => [ 'type' => 'text', 'label' => 'City', 'required' => false ],
			],
		];

		$result = $handler->inject( $input );

		$this->assertArrayHasKey( 'billing', $result, 'Billing section must exist after inject().' );

		$this->assertSame(
			$input['billing']['billing_state'],
			$result['billing']['billing_state'],
			'billing_state is a takeover field — inject() must not touch it.'
		);
		$this->assertSame(
			$input['billing']['billing_city'],
			$result['billing']['billing_city'],
			'billing_city is a takeover field — inject() must not touch it.'
		);
	}

	/**
	 * The pickup slot is NOT a takeover field, so inject() still owns it: it declares no
	 * section of its own and must therefore land in the caller-supplied default section.
	 * Guards that skipping takeover fields did not disable injection wholesale.
	 *
	 * @return void
	 */
	public function test_inject_still_adds_non_takeover_fields(): void {
		$result = $this->plugin->get_checkout_handler()->inject( [ 'billing' => [] ], 'order' );

		$this->assertArrayHasKey(
			'carrier_pickup_point',
			$result['order'],
			'A non-takeover field must still be injected into the default section.'
		);
	}

	/**
	 * inject() must preserve existing WC args it does not own (e.g. `class`,
	 * `priority`) — conservative merge must not clobber them.
	 *
	 * @return void
	 */
	public function test_inject_preserves_existing_wc_args(): void {
		$handler = $this->plugin->get_checkout_handler();

		$input = [
			'billing' => [
				'billing_state' => [
					'type'     => 'text',
					'label'    => 'State',
					'required' => false,
					'class'    => [ 'form-row-wide' ],
					'priority' => 40,
				],
			],
		];

		$result  = $handler->inject( $input );
		$bs      = $result['billing']['billing_state'] ?? [];

		$this->assertSame( [ 'form-row-wide' ], $bs['class'], 'class should be preserved after inject().' );
		$this->assertSame( 40, $bs['priority'], 'priority should be preserved after inject().' );
	}

	// -------------------------------------------------------------------------
	// 4. billing_state source callback
	// -------------------------------------------------------------------------

	/**
	 * The billing_state source must return 3 regions for RU and an empty list
	 * for any other country.
	 *
	 * We call the source directly from the normalized field descriptor.
	 *
	 * @return void
	 */
	public function test_billing_state_source_returns_ru_regions_for_ru_country(): void {
		$field  = $this->plugin->get_checkout_handler()->get_fields()->get_field( 'billing_state' );
		$source = $field['source'];

		$ru_options = $source( [ 'country' => 'RU' ] );

		$this->assertCount( 3, $ru_options, 'RU should yield 3 region entries.' );

		$values = array_column( $ru_options, 'value' );
		$this->assertContains( '77', $values, 'Москва (77) should be in RU regions.' );
		$this->assertContains( '78', $values, 'Санкт-Петербург (78) should be in RU regions.' );
		$this->assertContains( '23', $values, 'Краснодарский край (23) should be in RU regions.' );
	}

	/**
	 * billing_state source must return an empty list for a non-CIS country.
	 *
	 * @return void
	 */
	public function test_billing_state_source_returns_empty_for_non_cis_country(): void {
		$field  = $this->plugin->get_checkout_handler()->get_fields()->get_field( 'billing_state' );
		$source = $field['source'];

		$fr_options = $source( [ 'country' => 'FR' ] );

		$this->assertSame( [], $fr_options, 'Non-CIS country must yield an empty region list.' );
	}

	// -------------------------------------------------------------------------
	// 5. billing_city source callback (suggest with parent + q)
	// -------------------------------------------------------------------------

	/**
	 * billing_city source must return all cities for region 77 when q is empty.
	 *
	 * @return void
	 */
	public function test_billing_city_source_returns_all_cities_for_region_77(): void {
		$field  = $this->plugin->get_checkout_handler()->get_fields()->get_field( 'billing_city' );
		$source = $field['source'];

		$cities = $source( [ 'parent' => '77', 'q' => '' ] );

		$this->assertCount( 3, $cities, 'Region 77 should have 3 cities when q is empty.' );
		$labels = array_column( $cities, 'label' );
		$this->assertContains( 'Москва', $labels );
		$this->assertContains( 'Зеленоград', $labels );
		$this->assertContains( 'Троицк', $labels );
	}

	/**
	 * billing_city source must filter cities by a case-insensitive substring match
	 * on the query string (simulating the suggest endpoint: q=мос in region 77).
	 *
	 * Note: this also verifies the behaviour exercised by the REST field-source
	 * endpoint GET /woodev/v1/shipping/checkout/<plugin_id>/field-source/billing_city?parent=77&q=мос
	 * without having to dispatch an actual HTTP request.
	 *
	 * @return void
	 */
	public function test_billing_city_source_filters_by_query_substring(): void {
		$field  = $this->plugin->get_checkout_handler()->get_fields()->get_field( 'billing_city' );
		$source = $field['source'];

		$result = $source( [ 'parent' => '77', 'q' => 'мос' ] );

		// Only 'Москва' contains 'мос' (case-insensitive).
		$this->assertCount( 1, $result, 'Only Москва should match "мос" in region 77.' );
		$this->assertSame( 'Москва', $result[0]['label'] );
	}

	/**
	 * billing_city source must return empty for an unknown region.
	 *
	 * @return void
	 */
	public function test_billing_city_source_returns_empty_for_unknown_region(): void {
		$field  = $this->plugin->get_checkout_handler()->get_fields()->get_field( 'billing_city' );
		$source = $field['source'];

		$result = $source( [ 'parent' => '99', 'q' => '' ] );

		$this->assertSame( [], $result, 'Unknown region must yield an empty city list.' );
	}

	// -------------------------------------------------------------------------
	// 6. carrier_pickup_point field shape
	// -------------------------------------------------------------------------

	/**
	 * The carrier_pickup_point field must be type `hidden` and marked as a pickup
	 * slot, with a condition-spec `required` keyed by the fixture method id.
	 *
	 * @return void
	 */
	public function test_carrier_pickup_point_field_is_hidden_pickup_slot(): void {
		$field = $this->plugin->get_checkout_handler()->get_fields()->get_field( 'carrier_pickup_point' );

		$this->assertNotNull( $field, 'carrier_pickup_point field must exist.' );
		$this->assertSame( 'hidden', $field['type'], 'carrier_pickup_point must be type hidden.' );
		$this->assertTrue( $field['is_pickup_slot'], 'carrier_pickup_point must be marked as pickup slot.' );

		// The condition-spec required must name the fixture shipping method id.
		$required = $field['required'];
		$this->assertIsArray( $required, 'carrier_pickup_point required must be a condition-spec array.' );
		$this->assertSame( 'in', $required['operator'], 'Required spec operator must be "in".' );
		$this->assertContains(
			\Woodev_Test_Shipping_Method::METHOD_ID,
			$required['value'],
			'Required spec must include the fixture shipping method id "' . \Woodev_Test_Shipping_Method::METHOD_ID . '".'
		);
	}

	// -------------------------------------------------------------------------
	// 7. takeover_condition on billing_state
	// -------------------------------------------------------------------------

	/**
	 * The billing_state takeover_condition must return true for BY, KZ and UZ,
	 * and false for everything else — INCLUDING RU.
	 *
	 * RU is excluded on purpose (issue #294): `woocommerce_states` is keyed by COUNTRY,
	 * not by field, so a takeover covering RU registers this fixture's three hardcoded
	 * regions as RU's states and WooCommerce then renders EVERY `*_state` field for RU as
	 * a `<select>` — including `shipping_state`, which the location-provider layer declares
	 * as a text typeahead. Keeping RU here made the layer's entire region level
	 * unobservable on the rig.
	 *
	 * @return void
	 */
	public function test_billing_state_takeover_condition(): void {
		$field     = $this->plugin->get_checkout_handler()->get_fields()->get_field( 'billing_state' );
		$predicate = $field['takeover_condition'];

		$this->assertIsCallable( $predicate, 'takeover_condition must be callable.' );

		foreach ( [ 'BY', 'KZ', 'UZ' ] as $cis_code ) {
			$this->assertTrue(
				$predicate( [ 'country' => $cis_code ] ),
				"takeover_condition should be true for CIS country '{$cis_code}'."
			);
		}

		$this->assertFalse(
			$predicate( [ 'country' => 'RU' ] ),
			'RU must stay OUT of the state takeover so the location layer owns its region field (#294).'
		);

		foreach ( [ 'DE', 'FR', 'US' ] as $non_cis ) {
			$this->assertFalse(
				$predicate( [ 'country' => $non_cis ] ),
				"takeover_condition should be false for non-CIS country '{$non_cis}'."
			);
		}
	}

	// -------------------------------------------------------------------------
	// 8. inject() pre-fills options for RU billing_state (options-kind root field)
	// -------------------------------------------------------------------------

	/**
	 * RU must receive NO injected states — the property the location-provider layer's
	 * region level depends on.
	 *
	 * `inject_states()` feeds `woocommerce_states`, which is keyed by COUNTRY: any entry
	 * under `RU` makes WooCommerce render every `*_state` field for RU as a `<select>`,
	 * `shipping_state` included, and the location typeahead can then neither attach nor be
	 * backwards-filled. Measured on the rig (s70): WooCommerce ships no RU states of its
	 * own, so whatever appears here is exclusively this fixture's doing.
	 *
	 * The takeover mechanism itself is still exercised — by BY/KZ/UZ in
	 * {@see self::test_billing_state_takeover_condition()} — and this fixture's source
	 * callable only ever returns options for RU, so the state half of the §8 demo is
	 * dormant until #294 settles how the two subsystems share the `state` field.
	 *
	 * @return void
	 */
	public function test_inject_states_leaves_ru_to_the_location_layer(): void {
		$states = $this->plugin->get_checkout_handler()->inject_states( [] );

		$this->assertArrayNotHasKey(
			'RU',
			$states,
			'RU must keep NO states, or WooCommerce turns shipping_state into a select (#294).'
		);
	}

	/**
	 * A country the fixture does not serve must keep whatever states WooCommerce already
	 * had — the takeover never blanks out an unrelated country.
	 *
	 * @return void
	 */
	public function test_inject_states_leaves_unserved_country_alone(): void {
		$states = $this->plugin->get_checkout_handler()->inject_states( [ 'US' => [ 'CA' => 'California' ] ] );

		$this->assertSame( [ 'CA' => 'California' ], $states['US'] );
	}
}
