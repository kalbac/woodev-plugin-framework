<?php
/**
 * Integration: the carrier metabox, rendered for a real order inside the order form, leaves the
 * order form's other fields inside that form — on BOTH WooCommerce order datastores (#1012).
 *
 * The metabox sits in the side column of WooCommerce's order form, ahead of the main column that
 * holds `#order_status`. It used to draw each action button inside its own `<form>`; HTML forbids a
 * nested form, so the browser dropped the inner opening tag and the inner `</form>` closed the OUTER
 * order form — the status select (and every main-column field) landed outside the form and was never
 * submitted. The fix is structural: no `<form>` inside the metabox.
 *
 * @package Woodev\Tests\Integration\Shipping
 * @since   2.0.2
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Admin\Shipping_Admin_Order;
use Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler;
use Woodev\Tests\Integration\TestCase;

class OrderMetaboxInsideOrderFormTest extends TestCase {

	private const MARKER = '_woodev_test_metabox_form_marker';

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$handler = $this->createMock( Abstract_Shipment_Handler::class );
		$handler->method( 'supports_update' )->willReturn( true );

		$registry = Orders_Registry::instance();
		$registry->reset_for_tests();
		$registry->register_provider(
			Orders_Provider::create(
				'metabox_form',
				'Metabox Form',
				self::MARKER,
				[ 'metabox_form' ],
				[ 'carrier_order_id_meta_key' => '_woodev_test_metabox_form_carrier_id' ]
			)
		);
		$registry->register_shipment_handler( 'metabox_form', $handler );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		wp_set_current_user( 0 );

		Orders_Registry::instance()->reset_for_tests();

		parent::tearDown();
	}

	/**
	 * @return array<string,array{0:bool}>
	 */
	public function datastore_provider(): array {
		return [
			'HPOS'       => [ true ],
			'legacy CPT' => [ false ],
		];
	}

	/**
	 * Renders the metabox for a real order (exported, so the destructive «cancel» button renders too)
	 * inside the same shape WooCommerce gives the edit screen: one order form, the side column first,
	 * the main column — with the status select — after it.
	 *
	 * @param bool $hpos true => HPOS; false => legacy CPT.
	 * @return string the whole screen fragment.
	 */
	private function render_order_screen( bool $hpos ): string {
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		update_option( 'woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no' );

		$this->assertSame( $hpos, \Woodev_Plugin_Compatibility::is_hpos_enabled(), 'the framework must see the datastore this test selected' );

		$order = wc_create_order();
		$order->set_status( 'processing' );
		$order->update_meta_data( self::MARKER, '1' );
		$order->update_meta_data( '_woodev_test_metabox_form_carrier_id', 'CARRIER-1' );
		$order->save();

		$provider = Orders_Registry::instance()->resolve_provider_for_order( $order );

		$this->assertNotNull( $provider, 'the order must match the test carrier, or the metabox is never drawn' );

		ob_start();
		( new Shipping_Admin_Order( Orders_Registry::instance() ) )->render_metabox( $order, $provider );
		$metabox = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-woodev-order-action="cancel"', $metabox, 'the exported order offers its destructive action' );

		return '<form id="order" method="post" action="post.php">'
			. '<div id="postbox-container-1">' . $metabox . '</div>'
			. '<div id="postbox-container-2"><select id="order_status" name="order_status"><option value="wc-processing">Processing</option></select></div>'
			. '</form>';
	}

	/**
	 * @dataProvider datastore_provider
	 *
	 * @param bool $hpos true => HPOS; false => legacy CPT.
	 * @return void
	 */
	public function test_the_metabox_draws_no_form_of_its_own( bool $hpos ): void {
		$screen = $this->render_order_screen( $hpos );

		$this->assertSame( 1, substr_count( $screen, '<form' ), 'only the order form itself — a second one is the #1012 bug' );
		$this->assertSame( 1, substr_count( $screen, '</form' ), 'a stray </form> would close the order form early' );
	}

	/**
	 * @dataProvider datastore_provider
	 *
	 * @param bool $hpos true => HPOS; false => legacy CPT.
	 * @return void
	 */
	public function test_the_status_select_stays_inside_the_order_form( bool $hpos ): void {
		$screen = $this->render_order_screen( $hpos );

		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><html><body>' . $screen . '</body></html>' );
		libxml_clear_errors();

		$xpath = new \DOMXPath( $dom );

		$this->assertSame( 1, $xpath->query( '//form' )->length, 'exactly one form on the screen' );
		$this->assertSame(
			1,
			$xpath->query( '//form[@id="order"]//select[@name="order_status"]' )->length,
			'the status select is a descendant of the order form, so a save submits it'
		);
		$this->assertGreaterThan(
			0,
			$xpath->query( '//form[@id="order"]//button[@type="button"][@data-woodev-order-action]' )->length,
			'the action buttons are still inside the order form, but as plain buttons that submit nothing'
		);
		$this->assertSame( 0, $xpath->query( '//button[@data-woodev-order-action][not(@type="button")]' )->length, 'no action button may be a submit button' );
	}
}
