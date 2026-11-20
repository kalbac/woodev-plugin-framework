<?php
/**
 * Unit tests for the «Источник» filter of the WooCommerce orders list.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Order;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Marketplace\Admin\Order\Order_Source_Filter;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace_Adapter;
use Woodev\Tests\Unit\TestCase;

/**
 * One filter serves both order storages and every marketplace plugin: it hooks once, applies the user's choice to the list's query,
 * counts the views without loading orders, and says so while marketplace orders are hidden.
 */
final class OrderSourceFilterTest extends TestCase {
	private const USER = 7;

	/**
	 * The user meta, by user id.
	 *
	 * @var array<int,string>
	 */
	private $meta = [];

	/**
	 * The `wc_get_orders()` calls of the test.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $order_queries = [];

	/**
	 * What a `wc_get_orders()` count answers: 10 orders, 3 of them the marketplaces'.
	 *
	 * @var array{all:int,marketplace:int}
	 */
	private $totals = [
		'all'         => 10,
		'marketplace' => 3,
	];

	/**
	 * What a count of one status answers while a choice is active, by status.
	 *
	 * @var array<string,int>
	 */
	private $status_totals = [];

	protected function setUp(): void {
		parent::setUp();

		$this->meta          = [];
		$this->order_queries = [];
		$this->totals        = [
			'all'         => 10,
			'marketplace' => 3,
		];
		$this->status_totals = [];
		$_GET                = [];

		Order_Source_Filter::instance()->reset_for_tests();

		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( self::USER );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'number_format_i18n' )->returnArg();
		Functions\when( 'remove_query_arg' )->justReturn( '/wp-admin/admin.php?page=wc-orders&status=wc-processing' );
		Functions\when( 'add_query_arg' )->alias(
			function ( string $key, string $value, string $url ): string {
				return $url . '&' . $key . '=' . $value;
			}
		);
		Functions\when( 'selected' )->alias(
			function ( $selected, $current ): string {
				return (string) $selected === (string) $current ? ' selected="selected"' : '';
			}
		);
		Functions\when( 'get_user_meta' )->alias(
			function ( int $user_id ) {
				return $this->meta[ $user_id ] ?? '';
			}
		);
		Functions\when( 'update_user_meta' )->alias(
			function ( int $user_id, string $key, $value ) {
				$this->meta[ $user_id ] = $value;

				return true;
			}
		);
		Functions\when( 'wc_get_orders' )->alias(
			function ( array $args ) {
				$this->order_queries[] = $args;

				if ( isset( $args['status'] ) ) {
					return (object) [ 'total' => $this->status_totals[ implode( ',', (array) $args['status'] ) ] ?? 0 ];
				}

				return (object) [ 'total' => isset( $args['meta_query'] ) || isset( $args['created_via'] ) ? $this->totals['marketplace'] : $this->totals['all'] ];
			}
		);
	}

	protected function tearDown(): void {
		$_GET = [];
		unset( $GLOBALS['pagenow'] );

		Order_Source_Filter::instance()->reset_for_tests();
		parent::tearDown();
	}

	/**
	 * A filter with the Ozon-like fake marketplace and a second one registered.
	 */
	private function filter(): Order_Source_Filter {
		$filter = Order_Source_Filter::instance();

		$filter->add_source( 'fake-seller', 'Fake Market' );
		$filter->add_source( 'other_api', 'Other Market' );

		return $filter;
	}

	public function test_a_marketplace_plugin_registers_its_created_via_and_name_from_its_adapter(): void {
		Order_Source_Filter::register( new Fake_Marketplace_Adapter() );

		$_GET['woodev_order_source'] = 'fake-seller';

		$args = Order_Source_Filter::instance()->filter_orders_table_args( [] );

		$this->assertSame( [ 'fake-seller' ], $args['created_via'], 'The adapter supplies the created_via; nothing is hard-coded.' );
	}

	public function test_nothing_is_hooked_outside_wp_admin_or_for_a_marketplace_without_created_via(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Order_Source_Filter::register( new Fake_Marketplace_Adapter() );

		Order_Source_Filter::instance()->add_source( '', 'Nameless' );

		$this->assertFalse( has_filter( 'woocommerce_shop_order_list_table_prepare_items_query_args' ) );
		$this->assertFalse( has_action( 'pre_get_posts' ) );
	}

	public function test_both_lists_are_hooked_once_however_many_marketplaces_register(): void {
		$filter = $this->filter();

		$this->assertSame( 10, has_filter( 'woocommerce_shop_order_list_table_prepare_items_query_args', [ $filter, 'filter_orders_table_args' ] ) );
		$this->assertSame( 10, has_action( 'pre_get_posts', [ $filter, 'filter_posts_query' ] ) );
		$this->assertSame( 10, has_filter( 'views_woocommerce_page_wc-orders', [ $filter, 'add_views' ] ) );
		$this->assertSame( 10, has_filter( 'views_edit-shop_order', [ $filter, 'add_views' ] ) );
		$this->assertSame( 10, has_action( 'woocommerce_order_list_table_restrict_manage_orders', [ $filter, 'render_dropdown' ] ) );
		$this->assertSame( 10, has_action( 'restrict_manage_posts', [ $filter, 'render_dropdown' ] ) );
	}

	public function test_a_user_who_never_chose_sees_the_shop_only_on_the_orders_table(): void {
		$args = $this->filter()->filter_orders_table_args( [ 'limit' => 20 ] );

		$this->assertSame( 20, $args['limit'] );
		$this->assertSame( 'NOT IN', $args['field_query'][0]['compare'] );
		$this->assertSame( [ 'fake-seller', 'other_api' ], $args['field_query'][0]['value'] );
	}

	public function test_the_request_choice_is_applied_and_remembered_for_the_next_visit(): void {
		$_GET['woodev_order_source'] = 'marketplace';

		$this->assertSame( [ 'fake-seller', 'other_api' ], $this->filter()->filter_orders_table_args( [] )['created_via'] );
		$this->assertSame( 'marketplace', $this->meta[ self::USER ] );

		// The next visit carries no choice.
		$_GET = [];
		Order_Source_Filter::instance()->reset_for_tests();

		$this->assertSame( [ 'fake-seller', 'other_api' ], $this->filter()->filter_orders_table_args( [] )['created_via'] );
	}

	public function test_the_posts_list_main_query_gets_the_choice_as_a_meta_query(): void {
		$GLOBALS['pagenow'] = 'edit.php';
		$query              = Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'post_type' )->andReturn( 'shop_order' );
		$query->shouldReceive( 'get' )->with( 'meta_query' )->andReturn( '' );
		$query->shouldReceive( 'set' )->once()->with(
			'meta_query',
			Mockery::on(
				static function ( $meta_query ): bool {
					return 'OR' === $meta_query[0]['relation'] && 'NOT IN' === $meta_query[0][0]['compare'] && '_created_via' === $meta_query[0][0]['key'];
				}
			)
		);

		$this->filter()->filter_posts_query( $query );
	}

	public function test_another_query_is_not_touched(): void {
		$GLOBALS['pagenow'] = 'edit.php';
		$query              = Mockery::mock( 'WP_Query' );
		$query->shouldReceive( 'is_main_query' )->andReturn( true );
		$query->shouldReceive( 'get' )->with( 'post_type' )->andReturn( 'product' );
		$query->shouldReceive( 'set' )->never();

		$this->filter()->filter_posts_query( $query );

		$GLOBALS['pagenow'] = 'index.php';
		$other              = Mockery::mock( 'WP_Query' );
		$other->shouldReceive( 'is_main_query' )->andReturn( true );
		$other->shouldReceive( 'set' )->never();

		$this->filter()->filter_posts_query( $other );
	}

	public function test_the_views_count_the_orders_with_two_cheap_queries_and_mark_the_current_one(): void {
		$views = $this->filter()->add_views( [ 'all' => '<a>Все (10)</a>' ] );

		$this->assertSame( 'all', array_key_first( $views ), 'The status views stay first.' );
		$this->assertStringContainsString( 'Все <span class="count">(10)</span>', $views['woodev_source_all'] );
		$this->assertStringContainsString( 'Магазин <span class="count">(7)</span>', $views['woodev_source_shop'] );
		$this->assertStringContainsString( 'Маркетплейсы <span class="count">(3)</span>', $views['woodev_source_marketplace'] );
		$this->assertStringContainsString( 'class="current"', $views['woodev_source_shop'], 'The default is the shop.' );
		$this->assertStringNotContainsString( 'class="current"', $views['woodev_source_marketplace'] );
		$this->assertStringContainsString( 'woodev_order_source=marketplace', $views['woodev_source_marketplace'] );
		$this->assertStringContainsString( 'status=wc-processing', $views['woodev_source_marketplace'], 'The status the manager is on is kept.' );

		$this->assertCount( 2, $this->order_queries );

		foreach ( $this->order_queries as $query ) {
			$this->assertSame( 1, $query['limit'] );
			$this->assertTrue( $query['paginate'] );
			$this->assertSame( 'ids', $query['return'], 'Counted, never loaded.' );
		}
	}

	public function test_the_dropdown_lists_each_marketplace_by_name_and_selects_the_choice(): void {
		$_GET['woodev_order_source'] = 'other_api';

		ob_start();
		$this->filter()->render_dropdown( 'shop_order', 'top' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="woodev_order_source"', $html );
		$this->assertStringContainsString( '<option value="shop">Только магазин</option>', $html );
		$this->assertStringContainsString( '<option value="fake-seller">Fake Market</option>', $html );
		$this->assertStringContainsString( '<option value="other_api" selected="selected">Other Market</option>', $html );
	}

	public function test_the_dropdown_is_not_printed_for_another_list_or_the_bottom_bar(): void {
		ob_start();
		$this->filter()->render_dropdown( 'shop_subscription', 'top' );
		$this->filter()->render_dropdown( 'shop_order', 'bottom' );

		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_the_hidden_line_is_printed_on_the_orders_list_while_marketplace_orders_are_hidden(): void {
		Functions\when( 'get_current_screen' )->justReturn( (object) [ 'id' => 'woocommerce_page_wc-orders' ] );

		ob_start();
		$this->filter()->render_hidden_notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Заказы маркетплейсов скрыты · ', $html );
		$this->assertStringContainsString( 'woodev_order_source=all', $html );
		$this->assertStringContainsString( 'показать</a>', $html );
	}

	public function test_the_hidden_line_is_silent_when_nothing_is_hidden(): void {
		Functions\when( 'get_current_screen' )->justReturn( (object) [ 'id' => 'edit-shop_order' ] );

		// The choice is every order.
		$_GET['woodev_order_source'] = 'all';
		ob_start();
		$this->filter()->render_hidden_notice();
		$this->assertSame( '', (string) ob_get_clean() );

		// The shop is chosen but no marketplace order exists.
		$_GET = [];
		Order_Source_Filter::instance()->reset_for_tests();
		$this->totals['marketplace'] = 0;
		ob_start();
		$this->filter()->render_hidden_notice();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_the_hidden_line_is_silent_on_other_screens(): void {
		Functions\when( 'get_current_screen' )->justReturn( (object) [ 'id' => 'dashboard' ] );

		ob_start();
		$this->filter()->render_hidden_notice();

		$this->assertSame( '', (string) ob_get_clean() );
		$this->assertSame( [], $this->order_queries, 'Not even a count is paid for on another screen.' );
	}

	public function test_an_hpos_shop_on_a_woocommerce_older_than_8_2_gets_no_filter_at_all(): void {
		Order_Source_Filter::instance()->reset_for_tests( true, '8.1.0' );
		$this->filter();

		$this->assertFalse( has_filter( 'woocommerce_shop_order_list_table_prepare_items_query_args' ) );
		$this->assertFalse( has_filter( 'views_woocommerce_page_wc-orders' ) );
		$this->assertFalse( has_action( 'woocommerce_order_list_table_restrict_manage_orders' ) );
		$this->assertFalse( has_action( 'admin_notices' ) );
	}

	public function test_an_hpos_shop_from_woocommerce_8_2_is_filtered_and_from_8_6_its_status_counts_follow(): void {
		Order_Source_Filter::instance()->reset_for_tests( true, '8.2.0' );
		$filter = $this->filter();

		$this->assertSame( 10, has_filter( 'woocommerce_shop_order_list_table_prepare_items_query_args', [ $filter, 'filter_orders_table_args' ] ) );
		$this->assertFalse( has_filter( 'woocommerce_shop_order_list_table_order_count' ), 'The hook the counts need does not exist before 8.6.' );

		Order_Source_Filter::instance()->reset_for_tests( true, '8.6.0' );
		$filter = $this->filter();

		$this->assertSame( 10, has_filter( 'woocommerce_shop_order_list_table_order_count', [ $filter, 'filter_orders_table_count' ] ) );
		$this->assertSame( 10, has_filter( 'woocommerce_shop_order_list_table_should_render_blank_state', [ $filter, 'filter_blank_state' ] ) );
	}

	public function test_a_posts_table_shop_is_filtered_whatever_the_woocommerce_version(): void {
		Order_Source_Filter::instance()->reset_for_tests( false, '7.0.0' );
		$filter = $this->filter();

		$this->assertSame( 10, has_action( 'pre_get_posts', [ $filter, 'filter_posts_query' ] ) );
		$this->assertSame( 10, has_filter( 'wp_count_posts', [ $filter, 'filter_posts_counts' ] ) );
	}

	public function test_an_hpos_status_view_counts_the_orders_the_shop_only_list_shows(): void {
		$this->status_totals = [ 'wc-processing' => 2 ];

		$filter = $this->filter();

		$this->assertSame( 2, $filter->filter_orders_table_count( 5, [ 'wc-processing' ] ), 'Processing (5) is Processing (2) while marketplace orders are hidden.' );
		$this->assertSame( 0, $filter->filter_orders_table_count( 0, [ 'wc-on-hold' ] ), 'A status without orders is not counted.' );

		$status_counts = array_filter(
			$this->order_queries,
			static function ( array $query ): bool {
				return isset( $query['status'] );
			}
		);
		$query         = reset( $status_counts );

		$this->assertSame( 1, $query['limit'] );
		$this->assertSame( 'ids', $query['return'], 'Counted, never loaded.' );
		$this->assertSame( [ 'wc-processing' ], $query['status'] );
		$this->assertArrayHasKey( 'meta_query', $query, 'The same constraint the list uses.' );
	}

	public function test_an_hpos_status_count_is_left_alone_when_every_order_is_shown(): void {
		$_GET['woodev_order_source'] = 'all';

		$this->assertSame( 5, $this->filter()->filter_orders_table_count( 5, [ 'wc-processing' ] ) );
		$this->assertSame( [], $this->order_queries );
	}

	public function test_the_hpos_list_is_not_blank_while_the_shop_has_orders_only_the_marketplaces_hide(): void {
		$this->assertFalse( $this->filter()->filter_blank_state( null ) );
	}

	public function test_the_hpos_list_is_blank_when_there_is_no_order_at_all(): void {
		$this->totals = [
			'all'         => 0,
			'marketplace' => 0,
		];

		$this->assertNull( $this->filter()->filter_blank_state( null ) );
		$this->assertTrue( $this->filter()->filter_blank_state( true ) );
	}

	public function test_the_posts_list_status_views_follow_the_choice_on_their_own_screen_only(): void {
		Functions\when( 'get_current_screen' )->justReturn( (object) [ 'id' => 'edit-shop_order' ] );
		$this->status_totals = [
			'wc-processing' => 2,
			'trash'         => 1,
		];
		$counts              = (object) [
			'wc-processing' => '5',
			'wc-on-hold'    => '0',
			'trash'         => '3',
			'auto-draft'    => '4',
		];

		$result = $this->filter()->filter_posts_counts( $counts, 'shop_order', 'readable' );

		$this->assertSame( 2, $result->{'wc-processing'} );
		$this->assertSame( '0', $result->{'wc-on-hold'} );
		$this->assertSame( 1, $result->trash );
		$this->assertSame( '4', $result->{'auto-draft'}, 'A status the list does not show is not touched.' );
		$this->assertSame( '5', $counts->{'wc-processing'}, 'The cached counts are not changed.' );
	}

	public function test_wp_count_posts_keeps_the_real_counts_for_every_other_caller(): void {
		Functions\when( 'get_current_screen' )->justReturn( (object) [ 'id' => 'edit-shop_order' ] );
		$this->status_totals = [ 'wc-processing' => 2 ];
		$counts              = (object) [ 'wc-processing' => '5' ];
		$filter              = $this->filter();

		// WooCommerce's blank-state check asks without a permission; another post type is not ours.
		$this->assertSame( $counts, $filter->filter_posts_counts( $counts, 'shop_order', '' ) );
		$this->assertSame( $counts, $filter->filter_posts_counts( $counts, 'product', 'readable' ) );

		Functions\when( 'get_current_screen' )->justReturn( (object) [ 'id' => 'dashboard' ] );
		$this->assertSame( $counts, $filter->filter_posts_counts( $counts, 'shop_order', 'readable' ) );
		$this->assertSame( [], $this->order_queries );
	}

	public function test_the_posts_list_status_views_are_left_alone_when_every_order_is_shown(): void {
		Functions\when( 'get_current_screen' )->justReturn( (object) [ 'id' => 'edit-shop_order' ] );
		$_GET['woodev_order_source'] = 'all';
		$counts                      = (object) [ 'wc-processing' => '5' ];

		$this->assertSame( $counts, $this->filter()->filter_posts_counts( $counts, 'shop_order', 'readable' ) );
	}
}
