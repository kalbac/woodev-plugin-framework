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
use Mockery;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Query;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;

class ShippingOrdersRegistryTest extends TestCase {

	/**
	 * In-memory stand-in for the options table behind get/set/delete_transient(),
	 * wired up by {@see self::stubOrdersQueryEnvironment()} and emptied between tests.
	 *
	 * @var array<string,mixed>
	 */
	private $fake_transients = [];

	protected function setUp(): void {
		parent::setUp();

		Functions\stubs( [ 'add_action', 'remove_action', 'add_filter', 'remove_filter', 'apply_filters' ] );

		$this->fake_transients = [];

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

	// ----- shipment handlers (card #824) -----

	public function test_get_shipment_handler_returns_null_when_nothing_was_registered(): void {
		$this->assertNull( Orders_Registry::instance()->get_shipment_handler( 'cdek' ) );
	}

	public function test_get_shipment_handler_returns_the_registered_handler(): void {
		$handler = Mockery::mock( Abstract_Shipment_Handler::class );

		Orders_Registry::instance()->register_shipment_handler( 'cdek', $handler );

		$this->assertSame( $handler, Orders_Registry::instance()->get_shipment_handler( 'cdek' ) );
	}

	/**
	 * Registering a handler for a provider id nothing declared a descriptor for yet
	 * is allowed — the order in which a plugin registers its provider and its
	 * handler is that plugin's business, not this registry's.
	 */
	public function test_register_shipment_handler_does_not_require_a_matching_provider(): void {
		$handler = Mockery::mock( Abstract_Shipment_Handler::class );

		Orders_Registry::instance()->register_shipment_handler( 'unregistered', $handler );

		$this->assertSame( $handler, Orders_Registry::instance()->get_shipment_handler( 'unregistered' ) );
		$this->assertNull( Orders_Registry::instance()->get_provider( 'unregistered' ) );
	}

	public function test_registering_a_second_handler_for_the_same_id_replaces_the_first(): void {
		$first  = Mockery::mock( Abstract_Shipment_Handler::class );
		$second = Mockery::mock( Abstract_Shipment_Handler::class );

		Orders_Registry::instance()->register_shipment_handler( 'cdek', $first );
		Orders_Registry::instance()->register_shipment_handler( 'cdek', $second );

		$this->assertSame( $second, Orders_Registry::instance()->get_shipment_handler( 'cdek' ) );
	}

	/**
	 * Round 2 (HIGH 2): registering a provider descriptor a SECOND time under an id
	 * already in use is a REPLACEMENT — the previous descriptor's shipment handler must
	 * not survive it, or a replacement registered by a different carrier's plugin would
	 * expose action buttons that still execute the OLD carrier's handler.
	 */
	public function test_replacing_a_provider_descriptor_drops_the_previous_shipment_handler(): void {
		$handler = Mockery::mock( Abstract_Shipment_Handler::class );

		Orders_Registry::instance()->register_provider( $this->provider( 'cdek', 'СДЭК v1' ) );
		Orders_Registry::instance()->register_shipment_handler( 'cdek', $handler );

		// A different carrier's plugin (or the same one, reloaded) replaces the descriptor.
		Orders_Registry::instance()->register_provider( $this->provider( 'cdek', 'СДЭК v2' ) );

		$this->assertNull( Orders_Registry::instance()->get_shipment_handler( 'cdek' ) );
	}

	/**
	 * The dropped handler slot is not permanently broken — a handler registered
	 * AFTERWARDS for the new descriptor works normally.
	 */
	public function test_a_handler_registered_after_a_replacement_works_normally(): void {
		Orders_Registry::instance()->register_provider( $this->provider( 'cdek', 'СДЭК v1' ) );
		Orders_Registry::instance()->register_shipment_handler( 'cdek', Mockery::mock( Abstract_Shipment_Handler::class ) );

		Orders_Registry::instance()->register_provider( $this->provider( 'cdek', 'СДЭК v2' ) );

		$new_handler = Mockery::mock( Abstract_Shipment_Handler::class );
		Orders_Registry::instance()->register_shipment_handler( 'cdek', $new_handler );

		$this->assertSame( $new_handler, Orders_Registry::instance()->get_shipment_handler( 'cdek' ) );
	}

	/**
	 * A FIRST-time registration under an id must never drop a handler — a plugin is free
	 * to call `register_shipment_handler()` BEFORE `register_provider()`
	 * ({@see self::test_register_shipment_handler_does_not_require_a_matching_provider()}),
	 * and that handler must survive the descriptor's own (first) registration.
	 */
	public function test_registering_a_provider_for_the_first_time_does_not_drop_a_handler_registered_before_it(): void {
		$handler = Mockery::mock( Abstract_Shipment_Handler::class );

		Orders_Registry::instance()->register_shipment_handler( 'cdek', $handler );
		Orders_Registry::instance()->register_provider( $this->provider( 'cdek' ) );

		$this->assertSame( $handler, Orders_Registry::instance()->get_shipment_handler( 'cdek' ) );
	}

	public function test_reset_for_tests_clears_registered_shipment_handlers(): void {
		Orders_Registry::instance()->register_shipment_handler( 'cdek', Mockery::mock( Abstract_Shipment_Handler::class ) );

		Orders_Registry::instance()->reset_for_tests();

		$this->assertNull( Orders_Registry::instance()->get_shipment_handler( 'cdek' ) );
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

	/**
	 * #853: the badge cache must flush itself the moment ANY order is
	 * exported, via the framework-wide `woodev_shipping_order_exported` action
	 * fired by Abstract_Shipment_Handler::export() — not a plugin-prefixed hook,
	 * since `$hook_prefix` varies per plugin and the framework cannot build a
	 * fixed hook name from it.
	 */
	public function test_add_hooks_subscribes_the_flush_to_the_order_exported_action(): void {
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
			if ( 'woodev_shipping_order_exported' === $call[0] && [ $registry, 'flush_new_order_counts' ] === $call[1] ) {
				$found = true;
			}
		}

		$this->assertTrue( $found, 'add_hooks() must subscribe flush_new_order_counts() to woodev_shipping_order_exported' );
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

		/*
		 * ⚠ ID AND LABEL ONLY (#855). The bootstrap used to inline a count per carrier,
		 * run at page-render time with no filters at all — so with any period or scope
		 * picked the picker read «СДЭК (71)» beside a table of four, and the number that
		 * disagreed with the table was the one the merchant would carry away. The counts
		 * now come back with the rows, counted under the same request
		 * (Orders_Controller::build_carrier_counts()).
		 *
		 * Asserting the KEY SET rather than merely the absence of `count`: a stray extra
		 * field here is inlined into every page load, and this is the only gate that sees it.
		 */
		foreach ( $data['providers'] as $entry ) {
			$this->assertSame( [ 'id', 'label' ], array_keys( $entry ) );
		}
	}

	// -----------------------------------------------------------------------
	// #834 — the «Заказы доставки» submenu item carries a badge with the number
	// of NEW orders, and hovering it breaks that number down per carrier.
	//
	// `wc_admin_register_page()` is still never stubbed here (see register_page()'s
	// own tests above for why), so the two things under test are reached through the
	// private builders they were split into: build_page_args() and build_menu_title().
	// -----------------------------------------------------------------------

	/**
	 * A provider that declares a carrier-order-id key — without one there is nothing
	 * for `is_exported` to be false ABOUT, and the badge would count differently.
	 */
	private function exportable_provider( string $id, string $label ): Orders_Provider {
		return Orders_Provider::create(
			$id,
			$label,
			'_marker_' . $id,
			[ $id ],
			[ 'carrier_order_id_meta_key' => '_carrier_order_id_' . $id ]
		);
	}

	/**
	 * Stubs everything Orders_Query::build_args() reaches for, plus the two functions
	 * the badge itself calls, and routes wc_get_orders() through $totals — a callback
	 * handed the BUILT args, so a test can return a different count per carrier.
	 *
	 * Every call's args land in $captured, which is what lets a test assert the badge
	 * asked the table's own question rather than one of its own.
	 *
	 * @param callable         $totals   built args => row count.
	 * @param array<int,array> $captured out; one entry per wc_get_orders() call.
	 * @param bool             $user_can what current_user_can() answers.
	 * @return void
	 */
	private function stubOrdersQueryEnvironment( callable $totals, array &$captured, bool $user_can = true ): void {
		/*
		 * A real round-tripping transient store, not a pair of no-ops: the badge's cache
		 * is only observable if a value written by set_transient() comes back out of
		 * get_transient(), and every "does not re-query" assertion below rests on that.
		 *
		 * `apply_filters` is re-stubbed here because Brain Monkey's plain stubs() default
		 * is returnArg() — argument ONE, the hook name — so the registry would read its
		 * TTL filter as the string 'woodev_shipping_orders_new_counts_ttl' and cast it to
		 * 0, silently disabling the very cache under test. returnArg( 2 ) is what an
		 * unhooked filter actually does.
		 */
		$store = &$this->fake_transients;

		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_transient' )->alias(
			static function ( string $key ) use ( &$store ) {
				return array_key_exists( $key, $store ) ? $store[ $key ] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, $value, $ttl = 0 ) use ( &$store ): bool {
				$store[ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( string $key ) use ( &$store ): bool {
				unset( $store[ $key ] );

				return true;
			}
		);

		Functions\when( 'current_user_can' )->justReturn( $user_can );
		Functions\when( 'number_format_i18n' )->alias(
			static function ( $number ): string {
				return number_format( (float) $number, 0, ',', ' ' );
			}
		);
		Functions\when( 'wc_get_order_types' )->justReturn( [ 'shop_order' ] );
		Functions\when( 'wc_get_order_statuses' )->justReturn( [ 'wc-processing' => 'Processing' ] );
		Functions\when( 'wc_string_to_bool' )->alias(
			static function ( $value ): bool {
				return is_bool( $value ) ? $value : ( 'yes' === $value || 'true' === $value || '1' === $value || 1 === $value );
			}
		);
		Functions\when( 'wc_get_orders' )->alias(
			static function ( array $args ) use ( &$captured, $totals ) {
				$captured[] = $args;

				return (object) [
					'orders'        => [],
					'total'         => (int) $totals( $args ),
					'max_num_pages' => 1,
				];
			}
		);
	}

	/**
	 * Calls the private menu-title builder — same reflection route, and for the same
	 * reason, as {@see self::reachable_delivery_statuses()}.
	 *
	 * @param Orders_Registry $registry registry under test.
	 * @return string
	 */
	private function menu_title( Orders_Registry $registry ): string {
		$method = new \ReflectionMethod( Orders_Registry::class, 'build_menu_title' );

		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		return (string) $method->invoke( $registry, 'Заказы доставки' );
	}

	/**
	 * Calls the private page-args builder.
	 *
	 * @param Orders_Registry $registry registry under test.
	 * @return array<string,mixed>
	 */
	private function page_args( Orders_Registry $registry ): array {
		$method = new \ReflectionMethod( Orders_Registry::class, 'build_page_args' );

		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		return (array) $method->invoke( $registry );
	}

	/**
	 * The shipped v1 plugin appends the bubble only when the count is above zero
	 * (`woocommerce-edostavka/includes/admin/class-wc-edostavka-admin.php:69`), and so
	 * does this: an empty bubble reads as "0 waiting", which is noise.
	 */
	public function test_menu_title_carries_no_badge_when_nothing_is_new(): void {
		$captured = [];
		$this->stubOrdersQueryEnvironment(
			static function (): int {
				return 0;
			},
			$captured
		);

		Orders_Registry::instance()->register_provider( $this->exportable_provider( 'cdek', 'СДЭК' ) );

		$this->assertSame( 'Заказы доставки', $this->menu_title( Orders_Registry::instance() ) );
	}

	/**
	 * Pinned as an exact string, not "a span exists": the markup IS the requirement —
	 * it is WordPress's own update-counter bubble, the one core uses for pending plugin
	 * updates, so no stylesheet of ours is involved and it matches every other count in
	 * the admin menu.
	 */
	public function test_menu_title_appends_wordpress_own_update_counter_markup(): void {
		$captured = [];
		$this->stubOrdersQueryEnvironment(
			static function (): int {
				return 3;
			},
			$captured
		);

		Orders_Registry::instance()->register_provider( $this->exportable_provider( 'cdek', 'СДЭК' ) );

		$this->assertSame(
			'Заказы доставки <span class="update-plugins count-3" title="СДЭК: 3"><span class="new-count">3</span></span>',
			$this->menu_title( Orders_Registry::instance() )
		);
	}

	/**
	 * The defect this test exists to prevent is a bespoke `wc_get_orders()`/`$wpdb`
	 * count: a number that agrees with the table by coincidence and drifts the first
	 * time either side changes. The badge must run the table's OWN query.
	 */
	public function test_badge_count_runs_the_tables_own_query_with_is_exported_false(): void {
		$captured = [];
		$this->stubOrdersQueryEnvironment(
			static function (): int {
				return 2;
			},
			$captured
		);

		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->exportable_provider( 'cdek', 'СДЭК' ) );

		$this->menu_title( $registry );

		$this->assertNotSame( [], $captured, 'the badge must actually have run a query' );

		$expected = ( new Orders_Query( $registry ) )->build_args(
			[
				'carrier'     => 'all',
				'is_exported' => false,
				'per_page'    => 1,
			]
		);

		$this->assertSame( $expected, $captured[0] );

		/*
		 * Spelled out as well, so the assertion above cannot pass vacuously if both
		 * sides ever stop asking about exports. Brain Monkey reports the legacy CPT
		 * datastore, where `meta_query` is silently DROPPED by wc_get_orders() — the
		 * export filter has to travel as Orders_Query's own query var instead, which
		 * is precisely what a hand-rolled count would get wrong.
		 */
		$this->assertSame(
			[
				[
					'relation' => 'AND',
					[
						'key'     => '_marker_cdek',
						'compare' => 'EXISTS',
					],
					[
						'relation' => 'OR',
						[
							'key'     => '_carrier_order_id_cdek',
							'compare' => 'NOT EXISTS',
						],
						[
							'key'     => '_carrier_order_id_cdek',
							'value'   => '',
							'compare' => '=',
						],
					],
				],
			],
			$captured[0][ Orders_Query::QUERY_VAR_EXPORTED_CLAUSES ]
		);

		// `paginate => true` is what makes ->total the count; per_page => 1 keeps the
		// row fetch down to a single order.
		$this->assertTrue( $captured[0]['paginate'] );
		$this->assertSame( 1, $captured[0]['limit'] );
	}

	public function test_badge_tooltip_breaks_the_count_down_per_carrier(): void {
		$captured = [];
		$this->stubOrdersQueryEnvironment(
			static function ( array $args ): int {
				$keys = $args[ Orders_Query::QUERY_VAR_MARKER_KEYS ];

				if ( [ '_marker_cdek' ] === $keys ) {
					return 2;
				}

				if ( [ '_marker_yandex' ] === $keys ) {
					return 5;
				}

				return 6; // The aggregate — deliberately NOT 2 + 5; see below.
			},
			$captured
		);

		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->exportable_provider( 'cdek', 'СДЭК' ) );
		$registry->register_provider( $this->exportable_provider( 'yandex', 'Яндекс' ) );

		$title = $this->menu_title( $registry );

		// A `title` attribute expresses several lines with newlines — the same plain
		// technique the page's own «Статус данных» bar uses, not a JS tooltip.
		$this->assertStringContainsString( 'title="СДЭК: 2' . "\n" . 'Яндекс: 5"', $title );

		/*
		 * The bubble shows the aggregate query's answer, never the sum of the lines:
		 * one order can carry two carriers' markers, so the aggregate is its own
		 * query. A badge built by adding the breakdown up would print 7 here.
		 */
		$this->assertStringContainsString( '<span class="new-count">6</span>', $title );
		$this->assertStringContainsString( 'count-6"', $title );
	}

	/**
	 * The badge counts orders a viewer may not be allowed to see. `get_page_capability()`
	 * is the registry's own answer to "may this user look at shipping orders" — reuse it,
	 * never widen it, and do not even run the query for someone who fails it.
	 */
	public function test_no_badge_and_no_query_for_a_user_without_the_page_capability(): void {
		$captured = [];
		$this->stubOrdersQueryEnvironment(
			static function (): int {
				return 9;
			},
			$captured,
			false
		);

		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->exportable_provider( 'cdek', 'СДЭК' ) );

		$this->assertSame( 'Заказы доставки', $this->menu_title( $registry ) );
		$this->assertSame( [], $captured, 'a user who may not open the page must not have its orders counted for them' );
	}

	/**
	 * ⚠ `page_title` must be passed explicitly and must stay plain. `title` reaches
	 * `add_submenu_page()` as the MENU title, which WordPress echoes unescaped — that
	 * is what lets the badge render. But WooCommerce's `PageController::register_page()`
	 * copies `title` into `page_title` when that is empty, and `page_title` IS escaped,
	 * so omitting it puts the raw `<span …>` into the browser tab's `<title>`
	 * (measured on the rig, #834, 11.09.2026).
	 */
	public function test_page_args_pass_page_title_explicitly_and_free_of_markup(): void {
		$captured = [];
		$this->stubOrdersQueryEnvironment(
			static function (): int {
				return 4;
			},
			$captured
		);

		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->exportable_provider( 'cdek', 'СДЭК' ) );

		$args = $this->page_args( $registry );

		$this->assertSame( 'Заказы доставки', $args['page_title'] );
		$this->assertStringNotContainsString( '<', $args['page_title'] );
		$this->assertStringContainsString( '<span class="update-plugins count-4"', $args['title'] );

		$this->assertSame( Orders_Registry::PAGE_SLUG, $args['id'] );
		$this->assertSame( 'woocommerce', $args['parent'] );
		$this->assertSame( '/' . Orders_Registry::PAGE_SLUG, $args['path'] );
		$this->assertSame( 'manage_woocommerce', $args['capability'] );
	}

	/**
	 * The shipped v1 plugin prints the already-formatted number through `%d`, and
	 * `sprintf( '%d', '1 234' )` truncates it back to 1 — its badge misreports every
	 * shop with a four-figure backlog. Only the visible number is formatted here; the
	 * class name keeps the raw integer, which is what core's own `wp-admin/menu.php`
	 * does.
	 */
	public function test_badge_count_is_locale_formatted_and_not_truncated_at_a_thousand(): void {
		$captured = [];
		$this->stubOrdersQueryEnvironment(
			static function (): int {
				return 1234;
			},
			$captured
		);

		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->exportable_provider( 'cdek', 'СДЭК' ) );

		$title = $this->menu_title( $registry );

		$this->assertStringContainsString( 'count-1234"', $title );
		$this->assertStringContainsString( '<span class="new-count">1 234</span>', $title );
	}

	// -----------------------------------------------------------------------
	// #834 follow-up — the counts are cached, because register_page() is hooked on
	// `admin_menu` and would otherwise query on EVERY admin page load. Measured on
	// the rig: ~43 ms and three queries for two carriers, with three joins on the
	// order-meta table PER PROVIDER; card #839 measured the same shape sitting in
	// MySQL `Sending data` for over four minutes on the CPT datastore at four
	// carriers.
	//
	// The query COUNT is the observable throughout. It genuinely discriminates:
	// test_a_zero_ttl_disables_the_cache_entirely below runs the same fixture with
	// caching off and pins SIX, against three here.
	// -----------------------------------------------------------------------

	/** Registers the two-carrier fixture the caching tests share. */
	private function register_two_carriers(): Orders_Registry {
		$registry = Orders_Registry::instance();
		$registry->register_provider( $this->exportable_provider( 'cdek', 'СДЭК' ) );
		$registry->register_provider( $this->exportable_provider( 'yandex', 'Яндекс' ) );

		return $registry;
	}

	/**
	 * Counts by marker key, so a carrier's line and the bubble are distinguishable.
	 * The two carriers are disjoint here — 48 + 53 = 101, the shape a real shop has,
	 * where an order carries exactly one carrier's marker.
	 */
	private function disjoint_carrier_totals(): callable {
		return static function ( array $args ): int {
			$keys = $args[ Orders_Query::QUERY_VAR_MARKER_KEYS ];

			if ( [ '_marker_cdek' ] === $keys ) {
				return 48;
			}

			if ( [ '_marker_yandex' ] === $keys ) {
				return 53;
			}

			return 101;
		};
	}

	/**
	 * The point of the cache: a second badge build inside one request must not go
	 * back to the database.
	 */
	public function test_the_second_badge_build_in_one_request_does_not_query_again(): void {
		$captured = [];
		$this->stubOrdersQueryEnvironment( $this->disjoint_carrier_totals(), $captured );

		$registry = $this->register_two_carriers();

		$first = $this->menu_title( $registry );
		$this->assertCount( 3, $captured, 'the cold build runs the aggregate plus one query per carrier' );

		$second = $this->menu_title( $registry );

		$this->assertCount( 3, $captured, 'the second build must be served from the cache, not re-queried' );
		$this->assertSame( $first, $second );
	}

	/**
	 * One transient holds BOTH numbers. Two entries could expire at different moments
	 * and then disagree, and the tooltip's whole job is to account for the bubble.
	 */
	public function test_both_numbers_live_in_one_shared_cache_entry(): void {
		$captured = [];
		$this->stubOrdersQueryEnvironment( $this->disjoint_carrier_totals(), $captured );

		$this->menu_title( $this->register_two_carriers() );

		$this->assertSame(
			[ Orders_Registry::NEW_COUNTS_TRANSIENT ],
			array_keys( $this->fake_transients ),
			'the badge must write exactly one cache entry, never one per carrier'
		);

		$payload = $this->fake_transients[ Orders_Registry::NEW_COUNTS_TRANSIENT ];

		$this->assertSame( 101, $payload['total'] );
		$this->assertSame(
			[
				'cdek'   => 48,
				'yandex' => 53,
			],
			$payload['carriers']
		);

		/*
		 * The key is deliberately NOT per-user. Past the get_page_capability() gate the
		 * count is identical for everyone: Orders_Query scopes rows by carrier marker
		 * meta and order status only, with no author/assignee/per-user term in it.
		 */
		$this->assertStringNotContainsString( 'user', Orders_Registry::NEW_COUNTS_TRANSIENT );
	}

	/**
	 * The property the coordinator measured on the rig — 48 + 53 = 101 — must survive
	 * caching. It survives because both numbers come out of ONE snapshot, so this
	 * asserts on the SECOND build, the one served entirely from cache.
	 *
	 * ⚠ The equality itself is a property of the data (each order carries one carrier's
	 * marker), not an invariant the code enforces — an order marked by two carriers
	 * counts in both lines. What the code guarantees, and what this pins, is that the
	 * bubble and its lines are never two different snapshots.
	 */
	public function test_the_breakdown_still_accounts_for_the_bubble_when_both_come_from_cache(): void {
		$captured = [];
		$this->stubOrdersQueryEnvironment( $this->disjoint_carrier_totals(), $captured );

		$registry = $this->register_two_carriers();

		$this->menu_title( $registry );
		$queries_after_warm = count( $captured );

		$title = $this->menu_title( $registry );

		$this->assertCount( $queries_after_warm, $captured, 'the asserted build must be the cached one' );

		$this->assertStringContainsString( '<span class="new-count">101</span>', $title );
		$this->assertStringContainsString( 'title="СДЭК: 48' . "\n" . 'Яндекс: 53"', $title );
		$this->assertSame( 101, 48 + 53 );
	}

	/**
	 * The seam the export path will use: once an order stops being new, the badge
	 * should say so without waiting out the TTL.
	 */
	public function test_flushing_the_counts_makes_the_next_badge_query_again(): void {
		$captured = [];
		$this->stubOrdersQueryEnvironment( $this->disjoint_carrier_totals(), $captured );

		$registry = $this->register_two_carriers();

		$this->menu_title( $registry );
		$this->assertCount( 3, $captured );

		$registry->flush_new_order_counts();

		$this->assertSame( [], $this->fake_transients, 'the flush must actually delete the entry' );

		$this->menu_title( $registry );

		$this->assertCount( 6, $captured, 'after a flush the next build must recompute' );
	}

	/**
	 * `add_filter( 'woodev_shipping_orders_new_counts_ttl', '__return_zero' )` is what a
	 * shop chasing a stale badge reaches for, so zero is honoured as "do not cache"
	 * rather than handed to set_transient(), where WordPress reads 0 as "never expire"
	 * — the exact opposite.
	 *
	 * This is also what proves the query-count assertions above discriminate: same
	 * fixture, same two calls, six queries instead of three.
	 */
	public function test_a_zero_ttl_disables_the_cache_entirely(): void {
		$captured = [];
		$this->stubOrdersQueryEnvironment( $this->disjoint_carrier_totals(), $captured );

		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				return 'woodev_shipping_orders_new_counts_ttl' === $hook ? 0 : $value;
			}
		);

		$registry = $this->register_two_carriers();

		$this->menu_title( $registry );
		$this->menu_title( $registry );

		$this->assertCount( 6, $captured, 'a zero TTL must not cache' );
		$this->assertSame( [], $this->fake_transients, 'and must not write an entry WordPress would keep forever' );
	}

	/**
	 * The registered set changes between requests — a carrier plugin activated, or one
	 * hidden through `woodev_shipping_orders_providers`. A snapshot naming other
	 * carriers cannot account for today's bubble, so it is discarded, not patched.
	 */
	public function test_a_cached_snapshot_for_a_different_carrier_set_is_discarded(): void {
		$captured = [];
		$this->stubOrdersQueryEnvironment( $this->disjoint_carrier_totals(), $captured );

		$this->fake_transients[ Orders_Registry::NEW_COUNTS_TRANSIENT ] = [
			'total'    => 99,
			'carriers' => [ 'dhl' => 99 ],
		];

		$title = $this->menu_title( $this->register_two_carriers() );

		$this->assertNotSame( [], $captured, 'a snapshot for other carriers must not be served' );
		$this->assertStringContainsString( '<span class="new-count">101</span>', $title );
		$this->assertStringContainsString( 'title="СДЭК: 48' . "\n" . 'Яндекс: 53"', $title );
	}

	/**
	 * A shop with nothing new is the common case and must stay at ONE query, not N + 1:
	 * when the aggregate is zero every carrier is zero too, because a single-carrier
	 * request narrows the query to a subset of the aggregate's matches.
	 */
	public function test_the_empty_state_costs_one_query_and_still_caches(): void {
		$captured = [];
		$this->stubOrdersQueryEnvironment(
			static function (): int {
				return 0;
			},
			$captured
		);

		$registry = $this->register_two_carriers();

		$this->assertSame( 'Заказы доставки', $this->menu_title( $registry ) );
		$this->assertCount( 1, $captured, 'a zero aggregate implies zero per carrier — do not query for it' );

		$this->assertSame(
			[
				'cdek'   => 0,
				'yandex' => 0,
			],
			$this->fake_transients[ Orders_Registry::NEW_COUNTS_TRANSIENT ]['carriers'],
			'the empty state must cache a COMPLETE snapshot, or it can never be served back'
		);

		$this->menu_title( $registry );

		$this->assertCount( 1, $captured, 'and the empty state must be cached like any other' );
	}

	/**
	 * Builds a `$submenu['woocommerce']` in WooCommerce's own shape — `[ page_title,
	 * capability, slug, ... ]` — with our page wherever `$ours_at` says.
	 *
	 * @param string $orders_slug the slug WooCommerce's own orders entry carries.
	 * @param int    $ours_at     index our entry is registered at.
	 *
	 * @return array<int,array<int,string>>
	 */
	private function woocommerce_submenu( string $orders_slug = 'wc-orders', int $ours_at = 5 ): array {
		$items = [
			[ 'Home', 'read', 'admin.php?page=wc-admin' ],
			[ 'Orders', 'edit_shop_orders', $orders_slug ],
			[ 'Customers', 'read', 'admin.php?page=wc-admin&path=/customers' ],
			[ 'Coupons', 'read', 'admin.php?page=coupons-moved' ],
			[ 'Reports', 'read', 'wc-reports' ],
			[ 'Settings', 'manage_woocommerce', 'wc-settings' ],
		];

		array_splice(
			$items,
			$ours_at,
			0,
			[ [ 'Заказы доставки', 'read', 'admin.php?page=wc-admin&path=/woodev-shipping-orders' ] ]
		);

		return $items;
	}

	/** The visible titles of a submenu, in order — what the merchant actually reads. */
	private function submenu_titles( array $submenu ): array {
		return array_map(
			static function ( $item ) {
				return $item[0];
			},
			$submenu
		);
	}

	public function test_our_page_is_moved_directly_below_woocommerce_orders(): void {
		global $submenu;

		$submenu = [ 'woocommerce' => $this->woocommerce_submenu() ];

		Orders_Registry::instance()->move_menu_item_after_orders();

		$this->assertSame(
			[ 'Home', 'Orders', 'Заказы доставки', 'Customers', 'Coupons', 'Reports', 'Settings' ],
			$this->submenu_titles( $submenu['woocommerce'] )
		);
	}

	/**
	 * The legacy post store spells that entry `edit.php?post_type=shop_order`. A shop that
	 * has not migrated to HPOS must not silently lose the placement — and the rig runs HPOS,
	 * so this branch has no other witness.
	 */
	public function test_the_legacy_post_store_spelling_of_orders_is_recognised(): void {
		global $submenu;

		$submenu = [ 'woocommerce' => $this->woocommerce_submenu( 'edit.php?post_type=shop_order' ) ];

		Orders_Registry::instance()->move_menu_item_after_orders();

		$titles = $this->submenu_titles( $submenu['woocommerce'] );

		$this->assertSame( 'Заказы доставки', $titles[2] );
		$this->assertSame( 'Orders', $titles[1] );
	}

	/**
	 * ⚠ Both no-op cases assert the submenu is UNCHANGED, not merely that nothing crashed.
	 * A menu in the wrong order is a blemish; a menu this method mangled is a support
	 * ticket, so «leave it exactly as found» is the contract when either end is missing.
	 */
	public function test_a_submenu_without_our_page_is_left_untouched(): void {
		global $submenu;

		$without_ours = $this->woocommerce_submenu();
		unset( $without_ours[5] );
		$without_ours = array_values( $without_ours );

		$submenu = [ 'woocommerce' => $without_ours ];

		Orders_Registry::instance()->move_menu_item_after_orders();

		$this->assertSame( $without_ours, $submenu['woocommerce'] );
	}

	public function test_a_submenu_without_woocommerce_orders_is_left_untouched(): void {
		global $submenu;

		$without_orders = $this->woocommerce_submenu();
		unset( $without_orders[1] );
		$without_orders = array_values( $without_orders );

		$submenu = [ 'woocommerce' => $without_orders ];

		Orders_Registry::instance()->move_menu_item_after_orders();

		$this->assertSame( $without_orders, $submenu['woocommerce'] );
	}

	public function test_an_absent_woocommerce_menu_is_not_created(): void {
		global $submenu;

		$submenu = [];

		Orders_Registry::instance()->move_menu_item_after_orders();

		$this->assertSame( [], $submenu );
	}
}
