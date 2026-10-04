<?php
/**
 * Tests for the checkout-surface guard in Checkout_Handler::enqueue_assets() (SP-11 C-1, #1087).
 *
 * The classic DOM adapter is skipped ONLY on a page that renders nothing but the Checkout block. The
 * question is asked of the page being rendered, never of the store's configured checkout page: a
 * classic form on a second page — or next to a block — keeps its fields, location and pickup scripts
 * (round-1 review, finding 7).
 *
 * Each test runs in its own process: the block-template branch asks WooCommerce's `CartCheckoutUtils`,
 * which does not exist in the unit process (and must not, for every other test), so the test declares it.
 *
 * @package Woodev\Tests\Unit\Shipping\Checkout
 */

namespace Woodev\Tests\Unit\Shipping\Checkout;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Checkout\Checkout_Fields;
use Woodev\Framework\Shipping\Checkout\Checkout_Handler;
use Woodev\Framework\Shipping\Checkout\Field;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-field.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-fields.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-condition.php';
require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-handler.php';

/**
 * Thrown by the `wp_enqueue_script` stub: the guard let the classic adapter through. Stopping there
 * keeps the test about the guard, not about everything `enqueue_assets()` builds after it.
 */
final class Classic_Adapter_Enqueued extends \RuntimeException {}

/**
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::enqueue_assets
 * @covers \Woodev\Framework\Shipping\Checkout\Checkout_Handler::is_block_only_checkout_surface
 */
class CheckoutHandlerBlocksSurfaceTest extends TestCase {

	private const BLOCK     = '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->';
	private const SHORTCODE = '<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->';
	private const CLASSIC   = '<!-- wp:woocommerce/classic-shortcode {"shortcode":"checkout"} /-->';

	/**
	 * Renders a checkout page and reports whether the classic adapter was enqueued.
	 *
	 * @param string $content               The CURRENT page's content.
	 * @param bool   $is_store_checkout     Whether the current page is the store's configured checkout page.
	 * @param bool   $default_uses_block    What WooCommerce says about the store's configured checkout page.
	 */
	private function classic_adapter_loads( string $content, bool $is_store_checkout, bool $default_uses_block ): bool {
		eval( 'namespace Automattic\WooCommerce\Blocks\Utils; class CartCheckoutUtils { public static function is_checkout_block_default() { return ' . ( $default_uses_block ? 'true' : 'false' ) . '; } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged

		if ( ! class_exists( '\Woodev_Blocks_Handler', false ) ) {
			eval( 'class Woodev_Blocks_Handler { public static function is_checkout_block_in_use(): bool { return \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::is_checkout_block_default(); } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}

		Functions\when( 'is_checkout' )->justReturn( true );
		Functions\when( 'get_post' )->justReturn( (object) [ 'post_content' => $content ] );
		Functions\when( 'has_block' )->alias( static fn( string $name, $in = null ): bool => is_string( $in ) && false !== strpos( $in, '<!-- wp:' . $name . ' ' ) );
		Functions\when( 'has_shortcode' )->alias( static fn( string $in, string $tag ): bool => false !== strpos( $in, '[' . $tag ) );
		Functions\when( 'wc_get_page_id' )->justReturn( 7 );
		Functions\when( 'is_page' )->justReturn( $is_store_checkout );
		Functions\when( 'plugins_url' )->returnArg( 1 );
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( string $handle ): void {
				throw new Classic_Adapter_Enqueued( $handle );
			}
		);

		$fields = Checkout_Fields::from_array( [ Field::create( 'billing_city' )->source_location( 'settlement' )->to_array() ] );

		try {
			( new Checkout_Handler( $fields, 'carrier' ) )->enqueue_assets();
		} catch ( Classic_Adapter_Enqueued $reached ) {
			$this->assertSame( 'woodev-checkout-field-store', $reached->getMessage() );

			return true;
		}

		return false;
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_page_with_only_the_checkout_block_boots_no_classic_script(): void {
		$this->assertFalse( $this->classic_adapter_loads( self::BLOCK, true, true ) );
	}

	/**
	 * The regression the round-1 review found: the store's checkout page is a block page, and the
	 * shopper is on ANOTHER page that carries the classic shortcode.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_separate_classic_page_keeps_its_adapter_when_the_default_page_is_a_block_page(): void {
		$this->assertTrue( $this->classic_adapter_loads( self::SHORTCODE, false, true ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_page_with_both_the_block_and_the_classic_shortcode_keeps_the_adapter(): void {
		$this->assertTrue( $this->classic_adapter_loads( self::BLOCK . self::SHORTCODE, true, true ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_woocommerces_classic_shortcode_block_keeps_the_adapter(): void {
		$this->assertTrue( $this->classic_adapter_loads( self::CLASSIC, true, false ) );
	}

	/**
	 * A page builder (or a template) renders the classic form and the page content shows neither a
	 * block nor a shortcode: unknown output keeps the adapter.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_page_whose_content_shows_no_checkout_at_all_keeps_the_adapter(): void {
		$this->assertTrue( $this->classic_adapter_loads( '<p>builder</p>', false, true ) );
	}

	/**
	 * A block theme may hold the Checkout block in the checkout TEMPLATE; the page itself is empty.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_store_checkout_page_rendered_by_a_block_template_boots_no_classic_script(): void {
		$this->assertFalse( $this->classic_adapter_loads( '', true, true ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_store_checkout_page_of_a_classic_store_keeps_the_adapter(): void {
		$this->assertTrue( $this->classic_adapter_loads( '', true, false ) );
	}
}
