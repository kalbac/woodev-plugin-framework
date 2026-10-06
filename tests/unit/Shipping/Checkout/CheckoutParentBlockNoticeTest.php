<?php
/** Tests the checkout parent-block compatibility detector. */

namespace Woodev\Tests\Unit\Shipping\Checkout;

use Brain\Monkey\Functions;
use Woodev\Framework\Shipping\Checkout\Checkout_Parent_Block_Notice;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 4 ) . '/woodev/shipping-method/checkout/class-checkout-parent-block-notice.php';

/** @covers \Woodev\Framework\Shipping\Checkout\Checkout_Parent_Block_Notice */
class CheckoutParentBlockNoticeTest extends TestCase {

	private const PARENTS = '<!-- wp:woocommerce/checkout-shipping-address-block /--><!-- wp:woocommerce/checkout-shipping-methods-block /-->';

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'has_block' )->alias( static fn( string $block, string $content ): bool => false !== strpos( $content, '<!-- wp:' . $block ) );
		Functions\when( 'has_shortcode' )->alias( static fn( string $content, string $tag ): bool => false !== strpos( $content, '[' . $tag ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_complete_checkout_parents_do_not_warn(): void {
		$this->assertFalse( Checkout_Parent_Block_Notice::should_warn( '<!-- wp:woocommerce/checkout -->' . self::PARENTS, true ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_missing_parent_blocks_warn_when_pickup_is_available(): void {
		$this->assertTrue( Checkout_Parent_Block_Notice::should_warn( '<!-- wp:woocommerce/checkout -->', true ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_either_missing_parent_warns_independently(): void {
		$this->assertTrue( Checkout_Parent_Block_Notice::should_warn( '<!-- wp:woocommerce/checkout --><!-- wp:woocommerce/checkout-shipping-address-block /-->', true ) );
		$this->assertTrue( Checkout_Parent_Block_Notice::should_warn( '<!-- wp:woocommerce/checkout --><!-- wp:woocommerce/checkout-shipping-methods-block /-->', true ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_nested_checkout_parents_are_detected(): void {
		$content = '<!-- wp:woocommerce/checkout --><!-- wp:woocommerce/checkout-fields-block -->' . self::PARENTS . '<!-- /wp:woocommerce/checkout-fields-block --><!-- /wp:woocommerce/checkout -->';
		$this->assertFalse( Checkout_Parent_Block_Notice::should_warn( $content, true ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_classic_shortcode_page_does_not_warn(): void {
		$this->assertFalse( Checkout_Parent_Block_Notice::should_warn( '[woocommerce_checkout]', true ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_shortcode_inside_block_checkout_does_not_warn(): void {
		$this->assertFalse( Checkout_Parent_Block_Notice::should_warn( '<!-- wp:woocommerce/checkout -->[woocommerce_checkout]', true ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_missing_parents_do_not_warn_without_pickup_method(): void {
		$this->assertFalse( Checkout_Parent_Block_Notice::should_warn( '<!-- wp:woocommerce/checkout -->', false ) );
	}
}
