<?php
/**
 * Unit tests for the orders list query of an «Источник» choice.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Order;

use Woodev\Framework\Marketplace\Admin\Order\Order_Source_Choice;
use Woodev\Framework\Marketplace\Admin\Order\Order_Source_Query;
use Woodev\Tests\Unit\TestCase;

/**
 * The query is plain arrays for `wc_get_orders()` (HPOS) and `WP_Query` (posts table): what a choice keeps is decided by the
 * `created_via` values the registered marketplaces create orders with, and nothing else.
 */
final class OrderSourceQueryTest extends TestCase {
	private function query(): Order_Source_Query {
		return new Order_Source_Query( [ 'ozon_api', 'wb_api' ] );
	}

	public function test_all_orders_leave_both_queries_alone(): void {
		$args = [ 'limit' => 20 ];

		$this->assertSame( $args, $this->query()->for_orders_table( $args, Order_Source_Choice::ALL ) );
		$this->assertSame( [], $this->query()->for_posts( [], Order_Source_Choice::ALL ) );
	}

	public function test_marketplace_orders_on_the_orders_table_are_selected_by_created_via(): void {
		$this->assertSame(
			[
				'limit'       => 20,
				'created_via' => [ 'ozon_api', 'wb_api' ],
			],
			$this->query()->for_orders_table( [ 'limit' => 20 ], Order_Source_Choice::MARKETPLACES )
		);
	}

	public function test_one_marketplace_on_the_orders_table_is_selected_by_its_created_via(): void {
		$this->assertSame( [ 'created_via' => [ 'wb_api' ] ], $this->query()->for_orders_table( [], 'wb_api' ) );
	}

	public function test_the_shop_on_the_orders_table_excludes_every_marketplace_and_keeps_orders_without_created_via(): void {
		$args = $this->query()->for_orders_table( [], Order_Source_Choice::SHOP );

		$this->assertArrayNotHasKey( 'created_via', $args );
		$this->assertSame(
			[
				'relation' => 'OR',
				[
					'field'   => 'created_via',
					'compare' => 'NOT IN',
					'value'   => [ 'ozon_api', 'wb_api' ],
				],
				[
					'field'   => 'created_via',
					'compare' => 'NOT EXISTS',
				],
			],
			$args['field_query']
		);
	}

	public function test_the_shop_keeps_a_field_query_another_plugin_set(): void {
		$theirs = [
			[
				'field'   => 'billing_email',
				'compare' => 'LIKE',
				'value'   => 'a@b.c',
			],
		];

		$args = $this->query()->for_orders_table( [ 'field_query' => $theirs ], Order_Source_Choice::SHOP );

		$this->assertSame( 'AND', $args['field_query']['relation'] );
		$this->assertSame( $theirs, $args['field_query'][0] );
		$this->assertSame( 'OR', $args['field_query'][1]['relation'] );
	}

	public function test_a_channel_the_manager_chose_with_woocommerce_own_filter_wins(): void {
		$args = [ 'created_via' => [ 'admin' ] ];

		$this->assertSame( $args, $this->query()->for_orders_table( $args, Order_Source_Choice::SHOP ) );
		$this->assertSame( $args, $this->query()->for_orders_table( $args, Order_Source_Choice::MARKETPLACES ) );
	}

	public function test_marketplace_orders_in_the_posts_table_are_a_meta_query_in(): void {
		// A list of clauses: WP_Meta_Query skips a clause given bare at the top level.
		$this->assertSame(
			[
				[
					'key'     => '_created_via',
					'value'   => [ 'ozon_api', 'wb_api' ],
					'compare' => 'IN',
				],
			],
			$this->query()->for_posts( [], Order_Source_Choice::MARKETPLACES )
		);
		$this->assertSame( [ 'wb_api' ], $this->query()->for_posts( [], 'wb_api' )[0]['value'] );
	}

	public function test_the_shop_in_the_posts_table_excludes_every_marketplace_and_keeps_orders_without_the_meta(): void {
		$this->assertSame(
			[
				[
					'relation' => 'OR',
					[
						'key'     => '_created_via',
						'value'   => [ 'ozon_api', 'wb_api' ],
						'compare' => 'NOT IN',
					],
					[
						'key'     => '_created_via',
						'compare' => 'NOT EXISTS',
					],
				],
			],
			$this->query()->for_posts( [], Order_Source_Choice::SHOP )
		);
	}

	public function test_the_posts_meta_query_keeps_the_one_the_list_already_had(): void {
		$theirs = [
			[
				'key'   => '_customer_user',
				'value' => '5',
			],
		];

		$meta_query = $this->query()->for_posts( $theirs, Order_Source_Choice::MARKETPLACES );

		$this->assertSame( 'AND', $meta_query['relation'] );
		$this->assertSame( $theirs, $meta_query[0] );
		$this->assertSame( 'IN', $meta_query[1]['compare'] );
	}

	public function test_what_a_choice_keeps_is_counted_with_the_lists_own_constraint_on_each_storage(): void {
		$this->assertSame( [ 'created_via' => [ 'ozon_api', 'wb_api' ] ], $this->query()->matching( true, Order_Source_Choice::MARKETPLACES ) );
		$this->assertSame( $this->query()->for_orders_table( [], Order_Source_Choice::SHOP ), $this->query()->matching( true, Order_Source_Choice::SHOP ) );
		$this->assertSame(
			[
				'meta_query' => [
					[
						'key'     => '_created_via',
						'value'   => [ 'wb_api' ],
						'compare' => 'IN',
					],
				],
			],
			$this->query()->matching( false, 'wb_api' )
		);
		$this->assertSame( [], $this->query()->matching( true, Order_Source_Choice::ALL ) );
		$this->assertSame( [], $this->query()->matching( false, Order_Source_Choice::ALL ) );
	}

	/**
	 * @dataProvider storage_support
	 *
	 * @param bool        $hpos       Orders in the HPOS tables.
	 * @param string|null $wc_version WooCommerce version.
	 * @param bool        $expected   Whether the filter can run.
	 */
	public function test_an_hpos_list_needs_woocommerce_8_2_for_the_field_query( bool $hpos, ?string $wc_version, bool $expected ): void {
		$this->assertSame( $expected, Order_Source_Query::supports_storage( $hpos, $wc_version ) );
	}

	/**
	 * @return array<string,array{bool,string|null,bool}>
	 */
	public function storage_support(): array {
		return [
			'posts table, any version'  => [ false, '7.0.0', true ],
			'posts table, unknown'      => [ false, null, true ],
			'hpos on 7.9'               => [ true, '7.9.0', false ],
			'hpos on 8.1.9'             => [ true, '8.1.9', false ],
			'hpos on 8.2'               => [ true, '8.2.0', true ],
			'hpos on 11.3'              => [ true, '11.3.0', true ],
			'hpos, version unknown'     => [ true, null, false ],
		];
	}

	public function test_the_hpos_status_counts_need_woocommerce_8_6(): void {
		$this->assertFalse( Order_Source_Query::supports_orders_table_status_counts( '8.5.9' ) );
		$this->assertFalse( Order_Source_Query::supports_orders_table_status_counts( null ) );
		$this->assertTrue( Order_Source_Query::supports_orders_table_status_counts( '8.6.0' ) );
	}
}
