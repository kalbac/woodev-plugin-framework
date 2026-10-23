<?php
/**
 * Tests for the location layer's live-nonce node (#1426): a page cache serves a stale or foreign
 * `wp_rest` nonce, so the cascade reads a node that every `update_checkout` replaces.
 *
 * @package Woodev\Tests\Unit\Shipping\Checkout
 */

namespace Woodev\Tests\Unit\Shipping\Checkout;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Checkout\{Checkout_Fields, Checkout_Handler};
use Woodev\Framework\Shipping\Location\Location_Service;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-locality-key.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-record.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-scope.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/abstract-location-provider.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-settings.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-provider-registry.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-customer-location-store.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/interface-location-adapter.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-resolution-cache.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/location/class-location-service.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-field.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-fields.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-condition.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-config.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-handler.php';

final class Location_Nonce_Fake_Service extends Location_Service {

	public bool $active = true;

	public string $provenance = '';

	public function __construct() {
	}

	public function is_active(): bool {
		return $this->active;
	}

	public function get_customer_provenance(): string {
		return $this->provenance;
	}
}

final class CheckoutHandlerLocationNonceTest extends TestCase {

	private function stub_nonce(): void {
		Functions\when( 'wp_create_nonce' )->alias(
			static function ( $action ) {
				return 'NONCE-FOR-' . $action;
			}
		);
	}

	public function test_register_wires_one_static_callback_pair_however_many_plugins_register(): void {
		Functions\when( 'add_action' )->justReturn( true );
		Functions\expect( 'add_filter' )
			->twice()
			->with( 'woocommerce_update_order_review_fragments', [ Checkout_Handler::class, 'inject_location_nonce_fragment' ] );
		Functions\expect( 'add_filter' )->zeroOrMoreTimes()->withAnyArgs();

		// A `[ ClassName, 'method' ]` callback has one id: WordPress stores it once for both plugins,
		// so the page carries ONE node, never a duplicate id.
		( new Checkout_Handler( Checkout_Fields::from_array( [] ), 'carrier-a', new Location_Nonce_Fake_Service() ) )->register();
		( new Checkout_Handler( Checkout_Fields::from_array( [] ), 'carrier-b', new Location_Nonce_Fake_Service() ) )->register();
	}

	public function test_the_footer_prints_the_node_on_the_checkout_page(): void {
		Functions\when( 'is_checkout' )->justReturn( true );
		$this->stub_nonce();

		ob_start();
		Checkout_Handler::print_location_nonce_node();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="woodev-location-nonce"', $html );
		$this->assertStringContainsString( 'data-woodev-location-nonce="NONCE-FOR-wp_rest"', $html );
		$this->assertStringContainsString( 'hidden', $html );
	}

	/**
	 * The first render may come from a page cache, captured for somebody else: it must make no claim
	 * about who is looking. Only the `update_checkout` fragment — an AJAX answer for THIS request's
	 * cookies, never cached — says whether the visitor is logged in.
	 */
	public function test_the_footer_node_makes_no_claim_about_who_is_logged_in(): void {
		Functions\when( 'is_checkout' )->justReturn( true );
		Functions\expect( 'is_user_logged_in' )->never();
		$this->stub_nonce();

		ob_start();
		Checkout_Handler::print_location_nonce_node();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'data-woodev-location-live', $html );
		$this->assertStringNotContainsString( 'data-woodev-location-auth', $html );
	}

	public function test_the_footer_prints_nothing_off_the_checkout_page(): void {
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\expect( 'wp_create_nonce' )->never();

		ob_start();
		Checkout_Handler::print_location_nonce_node();

		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_the_fragment_is_keyed_by_the_node_id_and_carries_a_freshly_minted_nonce(): void {
		$this->stub_nonce();

		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$fragments = Checkout_Handler::inject_location_nonce_fragment( [] );

		$this->assertSame( [ '#' . Checkout_Handler::LOCATION_NONCE_NODE_ID ], array_keys( $fragments ) );
		$this->assertStringContainsString( 'id="woodev-location-nonce"', $fragments['#woodev-location-nonce'] );
		$this->assertStringContainsString( 'data-woodev-location-nonce="NONCE-FOR-wp_rest"', $fragments['#woodev-location-nonce'] );
	}

	public function test_the_fragment_is_live_and_says_a_guest_is_a_guest(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		$this->stub_nonce();

		$markup = Checkout_Handler::inject_location_nonce_fragment( [] )['#woodev-location-nonce'];

		$this->assertStringContainsString( 'data-woodev-location-live="1"', $markup );
		$this->assertStringContainsString( 'data-woodev-location-auth="0"', $markup );
	}

	public function test_the_fragment_says_a_logged_in_customer_is_logged_in(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		$this->stub_nonce();

		$markup = Checkout_Handler::inject_location_nonce_fragment( [] )['#woodev-location-nonce'];

		$this->assertStringContainsString( 'data-woodev-location-live="1"', $markup );
		$this->assertStringContainsString( 'data-woodev-location-auth="1"', $markup );
	}

	/**
	 * `woocommerce_update_order_review_fragments` is a SHARED array — WooCommerce's own
	 * order-review fragment lives in it. Replacing it instead of adding a key blanks the totals.
	 */
	public function test_the_fragment_leaves_every_other_fragment_untouched(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		$this->stub_nonce();

		$fragments = Checkout_Handler::inject_location_nonce_fragment(
			[
				'.woocommerce-checkout-review-order-table' => '<table>totals</table>',
				'#other-plugin-node'                       => '<span>other</span>',
			]
		);

		$this->assertCount( 3, $fragments );
		$this->assertSame( '<table>totals</table>', $fragments['.woocommerce-checkout-review-order-table'] );
		$this->assertSame( '<span>other</span>', $fragments['#other-plugin-node'] );
	}
}
