<?php
/**
 * Unit: Orders_Registry aggregator behaviour (SP-10 increment 1).
 *
 * Modelled on SettingsPageRegistryTest — the registry this one structurally mirrors
 * (SP-10 spec D1).
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Query;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;

class ShippingOrdersRegistryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubs( [ 'add_action', 'remove_action', 'add_filter', 'remove_filter', 'apply_filters' ] );

		Orders_Registry::instance()->reset_for_tests();
	}

	protected function tearDown(): void {
		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	private function provider( string $id, string $label = 'Label', string $marker = '_marker' ): Orders_Provider {
		return Orders_Provider::create( $id, $label, $marker, $id );
	}

	public function test_has_providers_is_false_initially(): void {
		$this->assertFalse( Orders_Registry::instance()->has_providers() );
	}

	public function test_register_provider_makes_has_providers_true(): void {
		Orders_Registry::instance()->register_provider( $this->provider( 'cdek' ) );

		$this->assertTrue( Orders_Registry::instance()->has_providers() );
	}

	public function test_get_provider_returns_the_registered_provider(): void {
		$provider = $this->provider( 'cdek', 'СДЭК' );
		Orders_Registry::instance()->register_provider( $provider );

		$this->assertSame( $provider, Orders_Registry::instance()->get_provider( 'cdek' ) );
	}

	public function test_get_provider_returns_null_for_an_unknown_id(): void {
		$this->assertNull( Orders_Registry::instance()->get_provider( 'unknown' ) );
	}

	public function test_get_providers_lists_every_registered_carrier(): void {
		Orders_Registry::instance()->register_provider( $this->provider( 'cdek', 'СДЭК' ) );
		Orders_Registry::instance()->register_provider( $this->provider( 'yandex', 'Яндекс' ) );

		$ids = array_keys( Orders_Registry::instance()->get_providers() );

		$this->assertSame( [ 'cdek', 'yandex' ], $ids );
	}

	/**
	 * Registering two providers under the same id must not silently produce two tabs
	 * — last write wins, but it must actually win (not merely be ignored).
	 */
	public function test_registering_a_duplicate_id_replaces_the_first_last_write_wins(): void {
		Orders_Registry::instance()->register_provider( $this->provider( 'cdek', 'СДЭК v1' ) );
		Orders_Registry::instance()->register_provider( $this->provider( 'cdek', 'СДЭК v2' ) );

		$providers = Orders_Registry::instance()->get_providers();

		$this->assertCount( 1, $providers );
		$this->assertSame( 'СДЭК v2', $providers['cdek']->get_label() );
	}

	public function test_get_page_capability_is_manage_woocommerce(): void {
		$this->assertSame( 'manage_woocommerce', Orders_Registry::instance()->get_page_capability() );
	}

	public function test_register_page_does_not_register_a_submenu_without_providers(): void {
		Functions\expect( 'add_submenu_page' )->never();

		Orders_Registry::instance()->register_page();
	}

	public function test_register_page_registers_a_submenu_when_a_provider_is_present(): void {
		Orders_Registry::instance()->register_provider( $this->provider( 'cdek' ) );

		Functions\expect( 'add_submenu_page' )
			->once()
			->with(
				'woodev',
				\Mockery::any(),
				\Mockery::any(),
				'manage_woocommerce',
				Orders_Registry::PAGE_SLUG,
				[ Orders_Registry::instance(), 'render_page' ]
			);

		Orders_Registry::instance()->register_page();
	}

	public function test_reset_for_tests_clears_registered_providers(): void {
		Orders_Registry::instance()->register_provider( $this->provider( 'cdek' ) );
		Orders_Registry::instance()->reset_for_tests();

		$this->assertFalse( Orders_Registry::instance()->has_providers() );
		$this->assertNull( Orders_Registry::instance()->get_provider( 'cdek' ) );
	}

	// -----------------------------------------------------------------------
	// translate_marker_keys_query_var() — round 2: the legacy CPT datastore does not
	// support `meta_query` at all (fires `_doing_it_wrong` and silently returns
	// UNFILTERED results), so this filter is what turns the framework's own
	// Orders_Query::QUERY_VAR_MARKER_KEYS var into a real `meta_query` there.
	// -----------------------------------------------------------------------

	public function test_translate_marker_keys_leaves_the_query_untouched_when_the_var_is_absent(): void {
		$query = [ 'post_type' => 'shop_order' ];

		$result = Orders_Registry::instance()->translate_marker_keys_query_var( $query, [ 'status' => 'wc-processing' ] );

		$this->assertSame( $query, $result );
	}

	public function test_translate_marker_keys_single_key_produces_one_exists_clause(): void {
		$result = Orders_Registry::instance()->translate_marker_keys_query_var(
			[ 'post_type' => 'shop_order' ],
			[ Orders_Query::QUERY_VAR_MARKER_KEYS => [ '_cdek_marker' ] ]
		);

		$this->assertSame(
			[
				[
					'key'     => '_cdek_marker',
					'compare' => 'EXISTS',
				],
			],
			$result['meta_query']
		);
	}

	public function test_translate_marker_keys_several_keys_produce_relation_or(): void {
		$result = Orders_Registry::instance()->translate_marker_keys_query_var(
			[],
			[ Orders_Query::QUERY_VAR_MARKER_KEYS => [ '_cdek_marker', '_yandex_marker' ] ]
		);

		$this->assertSame(
			[
				'relation' => 'OR',
				[
					'key'     => '_cdek_marker',
					'compare' => 'EXISTS',
				],
				[
					'key'     => '_yandex_marker',
					'compare' => 'EXISTS',
				],
			],
			$result['meta_query']
		);
	}

	/**
	 * The worst version of the round-2 bug: on the legacy datastore, a marker-keys var
	 * present but EMPTY (zero providers, or an unknown carrier) must still translate
	 * into a "matches nothing" meta_query — never be left untouched, which would let
	 * WooCommerce return every order unfiltered.
	 */
	public function test_translate_marker_keys_empty_array_produces_the_no_match_sentinel(): void {
		$result = Orders_Registry::instance()->translate_marker_keys_query_var(
			[],
			[ Orders_Query::QUERY_VAR_MARKER_KEYS => [] ]
		);

		$this->assertSame( Orders_Query::NO_MATCH_META_QUERY, $result['meta_query'] );
	}
}
