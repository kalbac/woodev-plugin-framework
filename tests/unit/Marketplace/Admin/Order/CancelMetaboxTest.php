<?php
/**
 * Unit tests for the order metabox on both order storages.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Order;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Admin\Order\Cancel_Metabox;
use Woodev\Framework\Marketplace\Admin\Order\Cancel_Modal;
use Woodev\Framework\Marketplace\Admin\Product\Screen_Names;
use Woodev\Tests\Unit\Marketplace\Admin\Support\Fake_Order_Canceller;
use Woodev\Tests\Unit\TestCase;

/**
 * HPOS: WooCommerce fires `add_meta_boxes` with the `WC_Order` and the screen is `woocommerce_page_wc-orders`. Posts table: it
 * fires it with the `WP_Post` and the screen is `shop_order`. The box goes to exactly the screen being shown, for a marketplace
 * order only.
 */
final class CancelMetaboxTest extends TestCase {
	/**
	 * Screens a box was added to: `[ id, title, screen, context ]`.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private $added = [];

	/**
	 * Orders by id.
	 *
	 * @var array<int,\WC_Order>
	 */
	private $orders = [];

	/**
	 * Whether the user may edit orders.
	 *
	 * @var bool
	 */
	private $can = true;

	/**
	 * The scripted marketplace.
	 *
	 * @var Fake_Order_Canceller
	 */
	private $canceller;

	/**
	 * Boots the stubs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->added = [];
		$this->can   = true;

		Functions\when( 'current_user_can' )->alias(
			function () {
				return $this->can;
			}
		);
		Functions\when( 'wc_get_order' )->alias(
			function ( $id ) {
				return $this->orders[ (int) $id ] ?? false;
			}
		);
		Functions\when( 'add_meta_box' )->alias(
			function ( $id, $title, $callback, $screen, $context ) {
				$this->added[] = [ $id, $title, $screen, $context ];
			}
		);
		Functions\when( 'wp_create_nonce' )->justReturn( 'n0nce' );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'add_action' )->justReturn( true );

		$this->canceller = new Fake_Order_Canceller();
	}

	public function test_hpos_adds_the_box_for_the_order_object_on_the_hpos_screen(): void {
		Functions\when( 'wc_get_page_screen_id' )->justReturn( 'woocommerce_page_wc-orders' );
		$order = $this->order( 700, 'P-1', false );

		$this->metabox()->add( 'woocommerce_page_wc-orders', $order );

		$this->assertCount( 1, $this->added );
		$this->assertSame( 'woocommerce_page_wc-orders', $this->added[0][2] );
		$this->assertSame( 'Fake', $this->added[0][1] );
		$this->assertSame( 'side', $this->added[0][3] );
	}

	public function test_the_posts_table_adds_the_box_for_the_post_on_the_shop_order_screen(): void {
		Functions\when( 'wc_get_page_screen_id' )->justReturn( 'shop_order' );
		$this->order( 701, 'P-2', false );
		$post = $this->post( 701 );

		$this->metabox()->add( 'shop_order', $post );

		$this->assertCount( 1, $this->added );
		$this->assertSame( 'shop_order', $this->added[0][2] );
	}

	public function test_no_box_for_an_order_without_a_posting(): void {
		Functions\when( 'wc_get_page_screen_id' )->justReturn( 'shop_order' );
		$order = $this->order( 702, '', false );

		$this->metabox()->add( 'shop_order', $order );
		$this->metabox()->add( 'shop_order', $this->post( 702 ) );

		$this->assertSame( [], $this->added );
	}

	public function test_no_box_for_something_that_is_not_an_order(): void {
		Functions\when( 'wc_get_page_screen_id' )->justReturn( 'shop_order' );

		$this->metabox()->add( 'product', $this->post( 9999 ) );
		$this->metabox()->add( 'product', null );

		$this->assertSame( [], $this->added );
	}

	public function test_no_box_for_a_user_who_may_not_edit_orders(): void {
		Functions\when( 'wc_get_page_screen_id' )->justReturn( 'shop_order' );
		$order     = $this->order( 703, 'P-3', false );
		$this->can = false;

		$this->metabox()->add( 'shop_order', $order );

		$this->assertSame( [], $this->added );
	}

	public function test_the_box_shows_the_posting_and_the_button_with_the_order_nonce(): void {
		$order = $this->order( 704, 'P-4', false );

		ob_start();
		$this->metabox()->render( $order );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'P-4', $html );
		$this->assertStringContainsString( 'data-order-id="704"', $html );
		$this->assertStringContainsString( 'data-nonce="n0nce"', $html );
		$this->assertStringContainsString( 'data-cancel-action="woodev_fake_cancel_posting"', $html );
		$this->assertStringContainsString( 'data-reasons-action="woodev_fake_cancel_reasons"', $html );
	}

	public function test_a_finished_posting_gets_a_sentence_and_no_button(): void {
		$order = $this->order( 705, 'P-5', true );

		ob_start();
		$this->metabox()->render( $order );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'P-5', $html );
		$this->assertStringNotContainsString( '<button', $html );
	}

	public function test_the_button_is_empty_for_a_user_who_may_not_edit_orders(): void {
		$order     = $this->order( 706, 'P-6', false );
		$this->can = false;

		$this->assertSame( '', $this->modal()->button( $order ) );
	}

	/**
	 * Builds the metabox over the doubles.
	 *
	 * @return Cancel_Metabox
	 */
	private function metabox(): Cancel_Metabox {
		return new Cancel_Metabox( $this->names(), $this->canceller, $this->modal() );
	}

	/**
	 * Builds the button.
	 *
	 * @return Cancel_Modal
	 */
	private function modal(): Cancel_Modal {
		return new Cancel_Modal( $this->names(), $this->canceller, 'https://example.test/assets/', '1.0' );
	}

	/**
	 * Names of the fake marketplace.
	 *
	 * @return Screen_Names
	 */
	private function names(): Screen_Names {
		return new Screen_Names( 'fake', 'Fake' );
	}

	/**
	 * Builds a post double (the posts table hands the metabox a `WP_Post`).
	 *
	 * @param int $id Post id.
	 * @return \WP_Post
	 */
	private function post( int $id ): \WP_Post {
		$post     = \Mockery::mock( '\WP_Post' );
		$post->ID = $id;

		return $post;
	}

	/**
	 * Registers an order double.
	 *
	 * @param int    $id       Order id.
	 * @param string $posting  Posting number.
	 * @param bool   $finished Whether the posting is finished.
	 * @return \WC_Order
	 */
	private function order( int $id, string $posting, bool $finished ): \WC_Order {
		$meta  = [
			Fake_Order_Canceller::META_POSTING  => $posting,
			Fake_Order_Canceller::META_FINISHED => $finished ? '1' : '0',
		];
		$order = \Mockery::mock( '\WC_Order' );

		$order->shouldReceive( 'get_id' )->andReturn( $id );
		$order->shouldReceive( 'get_meta' )->andReturnUsing(
			static function ( $key ) use ( $meta ) {
				return $meta[ $key ] ?? '';
			}
		);

		$this->orders[ $id ] = $order;

		return $order;
	}
}
