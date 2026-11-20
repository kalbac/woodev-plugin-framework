<?php
/**
 * Unit tests for the shared dialog's assets and the cancel dialog's markup.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Admin;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Admin\Dialog_Assets;
use Woodev\Framework\Marketplace\Admin\Order\Cancel_Modal;
use Woodev\Framework\Marketplace\Admin\Product\Screen_Names;
use Woodev\Tests\Unit\Marketplace\Admin\Support\Fake_Order_Canceller;
use Woodev\Tests\Unit\TestCase;

/**
 * Every dialog of the plain-PHP marketplace screens is the shared `.woodev-dialog`: its stylesheet and script come from one place,
 * and the markup PHP prints has the same parts the script builds.
 */
final class DialogAssetsTest extends TestCase {
	/**
	 * Enqueued styles by handle: `[ src, deps, version ]`.
	 *
	 * @var array<string,array<int,mixed>>
	 */
	private $styles = [];

	/**
	 * Enqueued scripts by handle: `[ src, deps, version ]`.
	 *
	 * @var array<string,array<int,mixed>>
	 */
	private $scripts = [];

	/**
	 * Records what is enqueued.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->styles  = [];
		$this->scripts = [];

		Functions\when( 'wp_enqueue_style' )->alias(
			function ( $handle, $src, $deps, $version ) {
				$this->styles[ $handle ] = [ $src, $deps, $version ];
			}
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle, $src, $deps, $version ) {
				$this->scripts[ $handle ] = [ $src, $deps, $version ];
			}
		);
	}

	public function test_it_enqueues_the_stylesheet_and_the_script_under_one_handle_and_returns_it(): void {
		$handle = Dialog_Assets::enqueue( 'https://shop.test/marketplace/assets/', '2.1.13' );

		$this->assertSame( Dialog_Assets::HANDLE, $handle );
		$this->assertStringStartsWith( 'https://shop.test/marketplace/assets/css/admin/woodev-dialog', $this->styles[ $handle ][0] );
		$this->assertStringStartsWith( 'https://shop.test/marketplace/assets/js/admin/woodev-dialog', $this->scripts[ $handle ][0] );
		$this->assertSame( '2.1.13', $this->styles[ $handle ][2] );
		$this->assertSame( '2.1.13', $this->scripts[ $handle ][2] );
	}

	public function test_the_cancel_dialog_loads_the_shared_dialog_before_its_own_assets(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_create_nonce' )->justReturn( 'n0nce' );
		Functions\when( 'add_action' )->justReturn( true );

		$canceller = new Fake_Order_Canceller();
		$modal     = new Cancel_Modal( new Screen_Names( 'fake', 'Fake' ), $canceller, 'https://shop.test/marketplace/assets/', '2.1.13' );
		$order     = \Mockery::mock( '\WC_Order' );

		$order->shouldReceive( 'get_id' )->andReturn( 700 );
		$order->shouldReceive( 'get_meta' )->andReturnUsing(
			static function ( $key ) {
				return Fake_Order_Canceller::META_POSTING === $key ? 'P-1' : '';
			}
		);
		$modal->button( $order );

		$own = 'woodev-marketplace-order-cancel';

		$this->assertContains( Dialog_Assets::HANDLE, $this->styles[ $own ][1] );
		$this->assertContains( Dialog_Assets::HANDLE, $this->scripts[ $own ][1] );
	}

	public function test_the_cancel_dialog_prints_the_shared_parts(): void {
		Functions\when( 'admin_url' )->justReturn( 'https://shop.test/wp-admin/admin-ajax.php' );

		$modal = new Cancel_Modal( new Screen_Names( 'fake', 'Fake' ), new Fake_Order_Canceller(), 'https://shop.test/marketplace/assets/', '2.1.13' );

		ob_start();
		$modal->print_dialog();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'class="woodev-dialog woodev-mp-cancel-dialog"', $html );
		$this->assertStringContainsString( 'aria-labelledby="woodev-mp-cancel-title"', $html );
		$this->assertStringContainsString( 'id="woodev-mp-cancel-title"', $html );
		$this->assertLessThan( strpos( $html, 'woodev-dialog__body' ), strpos( $html, 'woodev-dialog__header' ) );
		$this->assertLessThan( strpos( $html, 'woodev-dialog__footer' ), strpos( $html, 'woodev-dialog__body' ) );
		$this->assertStringContainsString( 'class="woodev-dialog__close" data-woodev-dialog-close', $html );
		$this->assertStringContainsString( 'id="woodev-mp-cancel-close" data-woodev-dialog-close', $html );
	}
}
