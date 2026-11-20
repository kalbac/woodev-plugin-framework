<?php
/**
 * Unit tests for the read-only order screen of a marketplace order, on both order storages.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Order;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Marketplace\Admin\Order\Order_Canceller;
use Woodev\Framework\Marketplace\Admin\Order\Order_Details_Box;
use Woodev\Framework\Marketplace\Admin\Order\Order_Screen;
use Woodev\Framework\Marketplace\Admin\Product\Screen_Names;
use Woodev\Framework\Marketplace\Capabilities\Marketplace_Adapter;
use Woodev\Framework\Marketplace\Flows\Order_Meta_Contract;
use Woodev\Tests\Unit\Marketplace\Support\Fake_Marketplace\Fake_Order_Meta;
use Woodev\Tests\Unit\TestCase;

/**
 * HPOS: WooCommerce fires `add_meta_boxes` with the `WC_Order` on `woocommerce_page_wc-orders`. Posts table: with the `WP_Post` on
 * `shop_order`. In both a marketplace order loses the boxes that edit it, gets the details box and is «not editable»; a shop order
 * is left exactly as WooCommerce made it.
 */
final class OrderScreenTest extends TestCase {
	private const EDITING_BOXES = [
		'woocommerce-order-data'      => 'normal',
		'woocommerce-order-downloads' => 'normal',
		'woocommerce-order-actions'   => 'side',
		'postcustom'                  => 'normal',
		'order_custom'                => 'normal',
	];

	/**
	 * Boxes removed: `[ id, screen, context ]`.
	 *
	 * @var array<int,array<int,string>>
	 */
	private $removed = [];

	/**
	 * Boxes added: `[ id, title, screen, context, priority ]`.
	 *
	 * @var array<int,array<int,string>>
	 */
	private $added = [];

	/**
	 * Savers unhooked: `[ callable, priority ]`.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private $unhooked = [];

	/**
	 * Orders by id.
	 *
	 * @var array<int,\WC_Order>
	 */
	private $orders = [];

	/**
	 * Boots the stubs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->removed  = [];
		$this->added    = [];
		$this->unhooked = [];

		Functions\when( 'remove_meta_box' )->alias(
			function ( $id, $screen, $context ) {
				$this->removed[] = [ $id, $screen, $context ];
			}
		);
		Functions\when( 'add_meta_box' )->alias(
			function ( $id, $title, $callback, $screen, $context, $priority ) {
				$this->added[] = [ $id, $title, $screen, $context, $priority ];
			}
		);
		Functions\when( 'remove_action' )->alias(
			function ( $hook, $callback, $priority ) {
				$this->unhooked[] = [ $hook, $callback, $priority ];
			}
		);
		Functions\when( 'wc_get_order' )->alias(
			function ( $id ) {
				return $this->orders[ (int) $id ] ?? false;
			}
		);
	}

	public function test_hpos_a_marketplace_order_loses_the_editing_boxes_and_gets_the_details_box(): void {
		Functions\when( 'wc_get_page_screen_id' )->justReturn( 'woocommerce_page_wc-orders' );
		$order = $this->order( 700, 'fake-seller' );

		$this->screen()->arrange_boxes( 'woocommerce_page_wc-orders', $order );

		$this->assertSame( $this->expected_removals( 'woocommerce_page_wc-orders' ), $this->removed );
		$this->assertSame( [ [ 'woodev-mp-fake-order', 'Заказ Fake — сведения', 'woocommerce_page_wc-orders', 'normal', 'high' ] ], $this->added );
	}

	public function test_the_posts_table_does_the_same_for_the_post_on_the_shop_order_screen(): void {
		Functions\when( 'wc_get_page_screen_id' )->justReturn( 'shop_order' );
		$this->order( 701, 'fake-seller' );

		$this->screen()->arrange_boxes( 'shop_order', $this->post( 701 ) );

		$this->assertSame( $this->expected_removals( 'shop_order' ), $this->removed );
		$this->assertSame( 'shop_order', $this->added[0][2] );
	}

	public function test_a_shop_order_keeps_every_box(): void {
		Functions\when( 'wc_get_page_screen_id' )->justReturn( 'shop_order' );
		$shop = $this->order( 702, 'checkout' );
		$this->order( 703, '' );

		$this->screen()->arrange_boxes( 'shop_order', $shop );
		$this->screen()->arrange_boxes( 'shop_order', $this->post( 702 ) );
		$this->screen()->arrange_boxes( 'shop_order', $this->post( 703 ) );

		$this->assertSame( [], $this->removed );
		$this->assertSame( [], $this->added );
	}

	public function test_something_that_is_not_an_order_is_left_alone(): void {
		Functions\when( 'wc_get_page_screen_id' )->justReturn( 'shop_order' );

		$this->screen()->arrange_boxes( 'product', $this->post( 9999 ) );
		$this->screen()->arrange_boxes( 'product', null );

		$this->assertSame( [], $this->removed );
		$this->assertSame( [], $this->added );
	}

	public function test_an_adapter_that_stamps_nothing_claims_no_order(): void {
		Functions\when( 'wc_get_page_screen_id' )->justReturn( 'shop_order' );
		$order  = $this->order( 704, '' );
		$meta   = Mockery::mock( Order_Meta_Contract::class );
		$screen = new Order_Screen( $meta, $this->box() );

		$meta->shouldReceive( 'created_via' )->andReturn( '' );

		$screen->arrange_boxes( 'shop_order', $order );
		$this->assertTrue( $screen->filter_editable( true, $order ) );

		$this->assertSame( [], $this->removed );
	}

	public function test_only_a_marketplace_order_is_not_editable(): void {
		$screen = $this->screen();

		$this->assertFalse( $screen->filter_editable( true, $this->order( 705, 'fake-seller' ) ) );
		$this->assertTrue( $screen->filter_editable( true, $this->order( 706, 'checkout' ) ) );
		$this->assertFalse( $screen->filter_editable( false, $this->order( 707, 'checkout' ) ) );
		$this->assertTrue( $screen->filter_editable( true, null ) );
	}

	public function test_woocommerce_savers_are_unhooked_for_a_marketplace_order_only(): void {
		$this->order( 708, 'fake-seller' );
		$this->order( 709, 'checkout' );

		$this->screen()->unhook_savers( 709 );
		$this->assertSame( [], $this->unhooked );

		$this->screen()->unhook_savers( 708 );

		$this->assertSame(
			[
				[ 'woocommerce_process_shop_order_meta', 'WC_Meta_Box_Order_Items::save', 10 ],
				[ 'woocommerce_process_shop_order_meta', 'WC_Meta_Box_Order_Downloads::save', 30 ],
				[ 'woocommerce_process_shop_order_meta', 'WC_Meta_Box_Order_Data::save', 40 ],
				[ 'woocommerce_process_shop_order_meta', 'WC_Meta_Box_Order_Actions::save', 50 ],
			],
			$this->unhooked
		);
	}

	public function test_it_hooks_the_boxes_the_savers_and_the_editability(): void {
		Actions\expectAdded( 'add_meta_boxes' )->once()->with( Mockery::type( 'array' ), Order_Screen::BOXES_PRIORITY, 2 );
		Actions\expectAdded( 'woocommerce_process_shop_order_meta' )->once()->with( Mockery::type( 'array' ), 1, 1 );
		Filters\expectAdded( 'wc_order_is_editable' )->once()->with( Mockery::type( 'array' ), 10, 2 );

		$this->screen()->register();
	}

	/**
	 * The removals a marketplace order gets on a screen.
	 *
	 * @param string $screen Screen id.
	 * @return array<int,array<int,string>>
	 */
	private function expected_removals( string $screen ): array {
		$expected = [];

		foreach ( self::EDITING_BOXES as $id => $context ) {
			$expected[] = [ $id, $screen, $context ];
		}

		return $expected;
	}

	/**
	 * Builds the screen policy of the fake marketplace.
	 *
	 * @return Order_Screen
	 */
	private function screen(): Order_Screen {
		return new Order_Screen( new Fake_Order_Meta(), $this->box() );
	}

	/**
	 * Builds the details box over a plain adapter.
	 *
	 * @return Order_Details_Box
	 */
	private function box(): Order_Details_Box {
		$adapter = Mockery::mock( Marketplace_Adapter::class, Order_Canceller::class );

		return new Order_Details_Box(
			new Screen_Names( 'fake', 'Fake' ),
			$adapter,
			static function (): string {
				return '';
			},
			null,
			''
		);
	}

	/**
	 * Builds a post double (the posts table hands the hook a `WP_Post`).
	 *
	 * @param int $id Post id.
	 * @return \WP_Post
	 */
	private function post( int $id ): \WP_Post {
		$post     = Mockery::mock( '\WP_Post' );
		$post->ID = $id;

		return $post;
	}

	/**
	 * Registers an order double.
	 *
	 * @param int    $id          Order id.
	 * @param string $created_via What the order was created via.
	 * @return \WC_Order
	 */
	private function order( int $id, string $created_via ): \WC_Order {
		$order = Mockery::mock( '\WC_Order' );

		$order->shouldReceive( 'get_id' )->andReturn( $id );
		$order->shouldReceive( 'get_created_via' )->andReturn( $created_via );

		$this->orders[ $id ] = $order;

		return $order;
	}
}
