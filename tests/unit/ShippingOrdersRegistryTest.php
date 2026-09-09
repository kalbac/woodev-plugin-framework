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
		return Orders_Provider::create( $id, $label, $marker, [ $id ] );
	}

	/**
	 * Calls the private list builder. Private on purpose — it is an implementation
	 * detail of the inlined bootstrap, not API — but the RULE it encodes (#837 defect
	 * 4) is exactly the kind that regresses silently, so it is pinned directly rather
	 * than through the enqueue path, which would need half of wc-admin stubbed.
	 *
	 * @return array<int,string>
	 */
	private function reachable_delivery_statuses(): array {
		$method = new \ReflectionMethod( Orders_Registry::class, 'build_reachable_delivery_statuses' );

		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		return (array) $method->invoke( Orders_Registry::instance() );
	}

	private function provider_with_map( string $id, array $status_map ): Orders_Provider {
		return Orders_Provider::create(
			$id,
			$id,
			'_marker_' . $id,
			[ $id ],
			[
				'status_meta_key' => '_status_' . $id,
				'status_map'      => $status_map,
			]
		);
	}

	/**
	 * #837 defect 4: the delivery-status filter offered every canonical state, and one
	 * of them — `pending` — is produced by no carrier measured anywhere, so picking it
	 * returned an empty table and read as a broken filter.
	 */
	public function test_reachable_delivery_statuses_are_only_those_a_provider_produces(): void {
		Orders_Registry::instance()->register_provider(
			$this->provider_with_map( 'cdek', [ 'CREATED' => 'created', 'ON_THE_WAY' => 'in_transit' ] )
		);

		$this->assertSame( [ 'created', 'in_transit', 'unknown' ], $this->reachable_delivery_statuses() );
	}

	public function test_reachable_delivery_statuses_unions_every_provider(): void {
		Orders_Registry::instance()->register_provider( $this->provider_with_map( 'cdek', [ 'CREATED' => 'created' ] ) );
		Orders_Registry::instance()->register_provider( $this->provider_with_map( 'yandex', [ 'DONE' => 'delivered' ] ) );

		$this->assertSame( [ 'created', 'delivered', 'unknown' ], $this->reachable_delivery_statuses() );
	}

	/**
	 * ⚠ `unknown` is not derived and must never drop out: it is what an unmapped raw
	 * status AND an order with no status meta both resolve to, so it is reachable even
	 * on a shop whose carriers declare no map between them.
	 */
	public function test_unknown_is_always_offered_even_with_no_status_map_anywhere(): void {
		Orders_Registry::instance()->register_provider( $this->provider( 'cdek' ) );

		$this->assertSame( [ 'unknown' ], $this->reachable_delivery_statuses() );
	}

	/**
	 * The order is the canonical one, not registration order — otherwise the dropdown
	 * reads differently on two shops that produce the same set.
	 */
	public function test_reachable_delivery_statuses_follow_the_canonical_order(): void {
		Orders_Registry::instance()->register_provider( $this->provider_with_map( 'a', [ 'X' => 'delivered' ] ) );
		Orders_Registry::instance()->register_provider( $this->provider_with_map( 'b', [ 'Y' => 'created' ] ) );

		$this->assertSame( [ 'created', 'delivered', 'unknown' ], $this->reachable_delivery_statuses() );
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

	/**
	 * `register_page()` is never reachable past `has_providers()` here, so this holds
	 * regardless of whether `wc_admin_register_page()` exists in the running process.
	 */
	public function test_register_page_does_nothing_without_providers(): void {
		Orders_Registry::instance()->register_page();

		$this->assertFalse( Orders_Registry::instance()->has_providers() );
	}

	/**
	 * `wc_admin_register_page()` is never stubbed through Brain Monkey/Patchwork here —
	 * touching it once would leak `function_exists( 'wc_admin_register_page' )` as
	 * permanently `true` for the rest of this PHPUnit process, the exact constraint
	 * `LocationControllerTest` documents against `WC()`. In this real Brain Monkey
	 * environment WooCommerce's `wc-admin` bootstrap is never loaded, so
	 * `function_exists()` genuinely returns `false` here — this proves increment 2b's
	 * ADR-005 fail-soft guard: a provider is registered, yet `register_page()` neither
	 * throws nor calls a function that is not there.
	 */
	public function test_register_page_does_nothing_when_wc_admin_register_page_is_unavailable(): void {
		Orders_Registry::instance()->register_provider( $this->provider( 'cdek' ) );

		Orders_Registry::instance()->register_page();

		$this->assertTrue( Orders_Registry::instance()->has_providers() );
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

	/**
	 * SP-10 spec D10: the delivery-status and tracking-presence filters get the SAME
	 * legacy-CPT translation as the marker-key scope, through the two new query vars
	 * — never left to reach the CPT datastore as `meta_query` directly.
	 */
	public function test_translate_status_clauses_var_alone_produces_its_meta_query_shape(): void {
		$result = Orders_Registry::instance()->translate_marker_keys_query_var(
			[],
			[
				Orders_Query::QUERY_VAR_STATUS_CLAUSES => [
					[
						'key'     => '_cdek_status',
						'value'   => [ 'CDEK_DONE' ],
						'compare' => 'IN',
					],
				],
			]
		);

		$this->assertSame(
			[
				[
					'key'     => '_cdek_status',
					'value'   => [ 'CDEK_DONE' ],
					'compare' => 'IN',
				],
			],
			$result['meta_query']
		);
	}

	public function test_translate_tracking_clauses_var_alone_produces_its_meta_query_shape(): void {
		$result = Orders_Registry::instance()->translate_marker_keys_query_var(
			[],
			[
				Orders_Query::QUERY_VAR_TRACKING_CLAUSES => [
					[
						'key'     => '_cdek_tracking',
						'compare' => 'NOT EXISTS',
					],
				],
			]
		);

		$this->assertSame(
			[
				[
					'key'     => '_cdek_tracking',
					'compare' => 'NOT EXISTS',
				],
			],
			$result['meta_query']
		);
	}

	/**
	 * All three vars present at once — marker keys, delivery status, tracking — must
	 * be ANDed together, never left to silently combine as one flat OR (which would
	 * scope-leak every carrier's orders back in).
	 */
	public function test_translate_all_three_vars_together_ands_them(): void {
		$result = Orders_Registry::instance()->translate_marker_keys_query_var(
			[],
			[
				Orders_Query::QUERY_VAR_MARKER_KEYS      => [ '_cdek_marker' ],
				Orders_Query::QUERY_VAR_STATUS_CLAUSES   => [
					[
						'key'     => '_cdek_status',
						'value'   => [ 'CDEK_DONE' ],
						'compare' => 'IN',
					],
				],
				Orders_Query::QUERY_VAR_TRACKING_CLAUSES => [
					[
						'key'     => '_cdek_tracking',
						'compare' => 'EXISTS',
					],
				],
			]
		);

		$this->assertSame( 'AND', $result['meta_query']['relation'] );
		$this->assertCount( 4, $result['meta_query'] ); // relation + one part per var.
	}

	// -----------------------------------------------------------------------
	// enqueue_assets() — increment 2b rewrite: gated on is_wc_admin_screen(), a
	// protected seam overridden here rather than stubbing wc_admin_is_registered_page()
	// through Brain Monkey — the same function_exists()-leak reason register_page()'s
	// tests above give.
	// -----------------------------------------------------------------------

	/** Builds a fresh (non-singleton) registry with the wc-admin screen check forced true. */
	private function registryOnWcAdminScreen(): Orders_Registry {
		return new class() extends Orders_Registry {
			protected function is_wc_admin_screen(): bool {
				return true;
			}
		};
	}

	public function test_add_hooks_hooks_enqueue_assets_onto_admin_enqueue_scripts(): void {
		$calls = [];
		Functions\when( 'add_action' )->alias(
			static function ( ...$args ) use ( &$calls ): void {
				$calls[] = $args;
			}
		);

		$registry = $this->registryOnWcAdminScreen();
		$registry->register_provider( $this->provider( 'cdek' ) );

		$found = false;
		foreach ( $calls as $call ) {
			if ( 'admin_enqueue_scripts' === $call[0] && [ $registry, 'enqueue_assets' ] === $call[1] ) {
				$found = true;
			}
		}

		$this->assertTrue( $found, 'add_hooks() must hook enqueue_assets() onto admin_enqueue_scripts' );
	}

	public function test_enqueue_assets_does_nothing_off_the_wc_admin_screen(): void {
		Orders_Registry::instance()->register_provider( $this->provider( 'cdek' ) );

		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_enqueue_style' )->never();
		Functions\expect( 'wp_add_inline_script' )->never();

		// The real (singleton) instance's is_wc_admin_screen() is the unoverridden,
		// real implementation — false in this environment, same fail-soft guard
		// register_page()'s tests above already establish.
		Orders_Registry::instance()->enqueue_assets();
	}

	public function test_enqueue_assets_does_nothing_without_a_registered_plugin(): void {
		$registry = $this->registryOnWcAdminScreen();
		$registry->register_provider( $this->provider( 'cdek' ) );

		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_enqueue_style' )->never();
		Functions\expect( 'wp_add_inline_script' )->never();

		$registry->enqueue_assets();
	}

	/**
	 * A non-`Woodev_Plugin` second argument (a caller mistake) must be ignored
	 * rather than accepted and blown up on later — `enqueue_assets()` still finds
	 * no usable plugin and no-ops, exactly like passing none at all.
	 */
	public function test_register_provider_ignores_a_non_plugin_second_argument(): void {
		$registry = $this->registryOnWcAdminScreen();
		$registry->register_provider( $this->provider( 'cdek' ), 'not-a-plugin' );

		Functions\expect( 'wp_enqueue_script' )->never();

		$registry->enqueue_assets();
	}

	public function test_enqueue_assets_enqueues_the_bundle_and_inlines_the_provider_list(): void {
		$plugin = \Mockery::mock( '\Woodev_Plugin' );
		$plugin->shouldReceive( 'get_framework_path' )->andReturn( '/nonexistent/framework' );
		$plugin->shouldReceive( 'get_framework_assets_url' )->andReturn( 'https://example.test/vendor/woodev/framework/assets' );
		$plugin->shouldReceive( 'get_version' )->andReturn( '1.2.3' );

		$registry = $this->registryOnWcAdminScreen();
		$registry->register_provider( $this->provider( 'cdek', 'СДЭК' ), $plugin );
		$registry->register_provider( $this->provider( 'yandex', 'Яндекс' ) ); // no plugin — cdek's already won.

		// file_exists()/filemtime() are left UNSTUBBED — Patchwork cannot redefine
		// them without a patchwork.json entry this project does not carry, and
		// the real function already returns false for this fabricated path, which
		// is exactly the "manifest missing" branch this test wants to exercise.
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'rest_url' )->returnArg( 1 );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce-value' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wc_get_order_types' )->justReturn( [ 'shop_order' ] );
		Functions\when( 'wc_get_order_statuses' )->justReturn( [ 'wc-processing' => 'Processing' ] );
		Functions\when( 'wc_get_orders' )->justReturn( (object) [
			'orders'        => [],
			'total'         => 5,
			'max_num_pages' => 1,
		] );

		Functions\expect( 'wp_enqueue_style' )
			->with( 'woodev-shipping-orders-page', \Mockery::type( 'string' ), [ 'wc-components' ], '1.2.3' )
			->once();
		// The hand-declared WooCommerce handles are the whole of Route B, so the
		// list is pinned exactly rather than loosely: `wc-components` for
		// `TableCard`/`FilterPicker`, `wc-navigation` because the carrier filter is
		// URL-driven, `wc-admin-app` so our script runs after the app shell and
		// `woocommerce_admin_pages_list` is read with our page already on it, then
		// `wc-date` for `DateRangeFilterPicker`'s period resolution and
		// `wc-currency` because `AdvancedFilters` consumes a `CurrencyFactory`.
		//
		// `wc-settings` is deliberately NOT in this list and must not be added: it
		// is only conditionally registered, and naming an unregistered handle makes
		// WordPress drop this whole bundle silently (gotcha
		// `declaring-wc-settings-as-a-script-dependency-silently-drops-the-bundle`).
		// Pinning the list exactly is what would catch that regression, which is
		// why this assertion stays strict.
		Functions\expect( 'wp_enqueue_script' )
			->once()
			->with(
				'woodev-shipping-orders-page',
				\Mockery::type( 'string' ),
				[ 'wc-components', 'wc-navigation', 'wc-admin-app', 'wc-date', 'wc-currency' ],
				'1.2.3',
				true
			);

		$captured = null;
		Functions\expect( 'wp_add_inline_script' )
			->once()
			->with(
				'woodev-shipping-orders-page',
				\Mockery::on(
					static function ( $script ) use ( &$captured ) {
						$captured = $script;
						return is_string( $script );
					}
				),
				'before'
			);

		$registry->enqueue_assets();

		$this->assertNotNull( $captured, 'wp_add_inline_script must have been called' );
		$this->assertStringStartsWith( 'window.woodevShippingOrders = ', $captured );

		$json = rtrim( substr( $captured, strlen( 'window.woodevShippingOrders = ' ) ), ';' );
		$data = json_decode( $json, true );

		$this->assertSame( 'nonce-value', $data['nonce'] );
		$this->assertArrayNotHasKey( 'adminUrl', $data );
		$this->assertSame( [ 'all', 'cdek', 'yandex' ], array_column( $data['providers'], 'id' ) );
		$this->assertSame( [ 'Все перевозчики', 'СДЭК', 'Яндекс' ], array_column( $data['providers'], 'label' ) );
		foreach ( $data['providers'] as $entry ) {
			$this->assertSame( 5, $entry['count'] );
		}
	}
}
