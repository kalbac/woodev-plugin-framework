<?php
/**
 * Unit: the «Доставка перевозчиками» entry in WooCommerce → Аналитика (#711, stage 3).
 *
 * The gate is the orders page's own: a provider is registered AND the user holds the page capability. Everything else about the
 * entry (that it lands in `Analytics::register_pages()` → `wc_admin_register_page()`, so the bundle is enqueued on its screen) is
 * WooCommerce's behaviour and is checked against the installed source, not here.
 *
 * @package Woodev\Tests\Unit\Shipping\Admin
 */

namespace Woodev\Tests\Unit\Shipping\Admin;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Admin\Orders\{Orders_Provider, Orders_Registry};
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Shipping\Admin\Orders\Orders_Registry::add_analytics_report_menu_item
 */
final class OrdersRegistryAnalyticsReportMenuTest extends TestCase {

	/** @var array<string,string> */
	private const WC_ITEM = [
		'id'     => 'woocommerce-analytics-orders',
		'path'   => '/analytics/orders',
		'parent' => 'woocommerce-analytics',
	];

	protected function setUp(): void {
		parent::setUp();

		Functions\stubs( [ 'add_action', 'add_filter', 'remove_action', 'remove_filter', 'apply_filters' ] );
		Orders_Registry::instance()->reset_for_tests();
	}

	protected function tearDown(): void {
		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	private function registry_with_a_provider(): Orders_Registry {
		$registry = Orders_Registry::instance();
		$registry->register_provider( Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ] ) );

		return $registry;
	}

	public function test_the_entry_is_added_after_woocommerces_own_when_the_gate_is_open(): void {
		Functions\when( 'current_user_can' )->alias( static fn( $capability ) => 'manage_woocommerce' === $capability );

		$items = $this->registry_with_a_provider()->add_analytics_report_menu_item( [ self::WC_ITEM ] );

		$this->assertCount( 2, $items );
		$this->assertSame( self::WC_ITEM, $items[0], 'WooCommerce\'s own entries are untouched' );
		$this->assertSame(
			[
				'id'         => 'woodev-shipping-delivery',
				'title'      => 'Доставка перевозчиками',
				'parent'     => 'woocommerce-analytics',
				'path'       => '/analytics/woodev-delivery',
				'capability' => 'manage_woocommerce',
			],
			$items[1]
		);
	}

	public function test_no_provider_no_entry(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->assertSame( [ self::WC_ITEM ], Orders_Registry::instance()->add_analytics_report_menu_item( [ self::WC_ITEM ] ) );
	}

	public function test_a_user_without_the_page_capability_gets_no_entry(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertSame( [ self::WC_ITEM ], $this->registry_with_a_provider()->add_analytics_report_menu_item( [ self::WC_ITEM ] ) );
	}

	public function test_something_that_is_not_a_list_of_entries_is_handed_back_untouched(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$registry = $this->registry_with_a_provider();

		$this->assertNull( $registry->add_analytics_report_menu_item( null ) );
		$this->assertSame( 'x', $registry->add_analytics_report_menu_item( 'x' ) );
	}

	public function test_the_filter_is_subscribed_by_add_hooks(): void {
		$subscribed = [];
		Functions\when( 'add_filter' )->alias(
			static function ( $hook, $callback ) use ( &$subscribed ) {
				$subscribed[ $hook ] = $callback;

				return true;
			}
		);
		Functions\when( 'add_meta_boxes' )->justReturn( null );

		$registry = $this->registry_with_a_provider();
		$registry->add_hooks();

		$this->assertSame( [ $registry, 'add_analytics_report_menu_item' ], $subscribed['woocommerce_analytics_report_menu_items'] ?? null );
	}

	public function test_the_entry_ids_are_the_ones_the_js_report_registers(): void {
		$this->assertSame( 'woodev-shipping-delivery', Orders_Registry::ANALYTICS_REPORT_ID );
		$this->assertSame( '/analytics/woodev-delivery', Orders_Registry::ANALYTICS_REPORT_PATH );
	}

	/**
	 * Critic round 3: the JS report must be gated like the menu entry. The bundle learns the answer from ONE predicate that the menu uses too.
	 */
	public function test_one_predicate_decides_the_menu_entry_and_the_bundles_flag(): void {
		Functions\when( 'current_user_can' )->alias( static fn( $capability ) => 'manage_woocommerce' === $capability );
		$registry = Orders_Registry::instance();

		$this->assertFalse( $registry->analytics_report_allowed(), 'no provider' );

		$registry->register_provider( Orders_Provider::create( 'cdek', 'СДЭК', '_cdek_marker', [ 'cdek' ] ) );
		$this->assertTrue( $registry->analytics_report_allowed() );
		$this->assertCount( 1, $registry->add_analytics_report_menu_item( [] ) );

		Functions\when( 'current_user_can' )->justReturn( false ); // a reporting-only role: view_woocommerce_reports without the page capability.
		$this->assertFalse( $registry->analytics_report_allowed() );
		$this->assertSame( [], $registry->add_analytics_report_menu_item( [] ) );
	}
}
