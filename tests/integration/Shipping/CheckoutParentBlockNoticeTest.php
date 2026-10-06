<?php
/**
 * Integration tests for the checkout parent-block compatibility notice.
 *
 * @package Woodev\Tests\Integration\Shipping
 */

namespace Woodev\Tests\Integration\Shipping;

use Woodev\Framework\Shipping\Checkout\Checkout_Parent_Block_Notice;
use Woodev\Tests\Integration\TestCase;

/**
 * @since 2.0.2
 */
class CheckoutParentBlockNoticeTest extends TestCase {

	private const NOTICE_ID       = 'woodev-checkout-shipping-blocks-missing';
	private const TEST_PICKUP_ID  = 'woodev_test_shipping';
	private const REALISTIC_PICKUP_ID = 'woodev_realistic_pickup_shipping';
	private const REALISTIC_COURIER_ID = 'woodev_realistic_shipping';

	/** @var \WC_Shipping_Zone[] Zones created by the test. */
	private array $zones = [];

	/** @var int[] Pages created by the test. */
	private array $pages = [];

	/** @var array<string,mixed> Original method settings options to restore. */
	private array $original_settings_options = [];

	/** @var mixed Original configured checkout page ID. */
	private $original_checkout_page_id;

	/** @var bool Whether the checkout page option existed before the test. */
	private bool $checkout_page_option_existed;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->checkout_page_option_existed = false !== get_option( 'woocommerce_checkout_page_id', false );
		$this->original_checkout_page_id   = get_option( 'woocommerce_checkout_page_id', false );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		set_current_screen( 'dashboard' );
		$this->reset_notice_caches();

		// WooCommerce instantiates the zone methods through this registry.
		apply_filters( 'woocommerce_shipping_methods', [] );
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( $this->original_settings_options as $option => $value ) {
			if ( null === $value ) {
				delete_option( $option );
			} else {
				update_option( $option, $value );
			}
		}

		foreach ( $this->zones as $zone ) {
			$zone->delete( true );
		}

		foreach ( $this->pages as $page_id ) {
			wp_delete_post( $page_id, true );
		}

		if ( $this->checkout_page_option_existed ) {
			update_option( 'woocommerce_checkout_page_id', $this->original_checkout_page_id );
		} else {
			delete_option( 'woocommerce_checkout_page_id' );
		}

		$this->reset_notice_caches();
		parent::tearDown();
	}

	/**
	 * An enabled pickup method warns when the address parent block is missing.
	 *
	 * @return void
	 */
	public function test_enabled_pickup_method_warns_when_shipping_address_parent_is_missing(): void {
		$this->add_method_to_zone( self::TEST_PICKUP_ID );
		$this->set_checkout_content( '<!-- wp:woocommerce/checkout --><!-- wp:woocommerce/checkout-shipping-methods-block /-->' );

		$this->assertNoticeRendered();
	}

	/**
	 * An enabled pickup method warns when the shipping methods parent block is missing.
	 *
	 * @return void
	 */
	public function test_enabled_pickup_method_warns_when_shipping_methods_parent_is_missing(): void {
		$this->add_method_to_zone( self::TEST_PICKUP_ID );
		$this->set_checkout_content( '<!-- wp:woocommerce/checkout --><!-- wp:woocommerce/checkout-shipping-address-block /-->' );

		$this->assertNoticeRendered();
	}

	/**
	 * A block checkout containing both required parents does not warn.
	 *
	 * @return void
	 */
	public function test_block_checkout_with_both_parent_blocks_does_not_warn(): void {
		$this->add_method_to_zone( self::TEST_PICKUP_ID );
		$this->set_checkout_content( $this->complete_checkout_content() );

		$this->assertNoNoticeRendered();
	}

	/**
	 * A pickup method disabled in every zone does not warn.
	 *
	 * @return void
	 */
	public function test_disabled_pickup_method_in_every_zone_does_not_warn(): void {
		$this->add_method_to_zone( self::TEST_PICKUP_ID );
		$this->disable_method( self::TEST_PICKUP_ID );
		$this->set_checkout_content( '<!-- wp:woocommerce/checkout -->' );

		$this->assertNoNoticeRendered();
	}

	/**
	 * An enabled courier method from the plugin does not qualify as a pickup method.
	 *
	 * @return void
	 */
	public function test_only_non_pickup_method_enabled_does_not_warn(): void {
		$this->add_method_to_zone( self::REALISTIC_COURIER_ID );
		$this->set_checkout_content( '<!-- wp:woocommerce/checkout -->' );

		$this->assertNoNoticeRendered();
	}

	/**
	 * A classic shortcode checkout page does not warn.
	 *
	 * @return void
	 */
	public function test_classic_checkout_shortcode_does_not_warn(): void {
		$this->add_method_to_zone( self::TEST_PICKUP_ID );
		$this->set_checkout_content( '[woocommerce_checkout]' );

		$this->assertNoNoticeRendered();
	}

	/**
	 * A zero checkout page ID does not warn.
	 *
	 * @return void
	 */
	public function test_checkout_page_id_zero_does_not_warn(): void {
		$this->add_method_to_zone( self::TEST_PICKUP_ID );
		update_option( 'woocommerce_checkout_page_id', 0 );
		$this->assertNoNoticeRendered();
	}

	/**
	 * A nonexistent configured checkout page does not warn.
	 *
	 * @return void
	 */
	public function test_nonexistent_checkout_page_does_not_warn(): void {
		$this->add_method_to_zone( self::TEST_PICKUP_ID );
		update_option( 'woocommerce_checkout_page_id', 99999999 );
		$this->assertNoNoticeRendered();
	}

	/**
	 * A trashed configured checkout page does not warn.
	 *
	 * @return void
	 */
	public function test_trashed_checkout_page_does_not_warn(): void {
		$this->add_method_to_zone( self::TEST_PICKUP_ID );
		$page_id = $this->create_page( '<!-- wp:woocommerce/checkout -->' );
		wp_trash_post( $page_id );
		update_option( 'woocommerce_checkout_page_id', $page_id );
		$this->assertNoNoticeRendered();
	}

	/**
	 * Two eligible shipping plugins register one site-wide notice during a request.
	 *
	 * @return void
	 */
	public function test_two_qualifying_shipping_plugins_register_one_notice(): void {
		$this->add_method_to_zone( self::TEST_PICKUP_ID );
		$this->add_method_to_zone( self::REALISTIC_PICKUP_ID );
		$this->set_checkout_content( '<!-- wp:woocommerce/checkout -->' );

		$output = $this->render_admin_notices();

		$this->assertSame( 1, substr_count( $output, self::NOTICE_ID ) );
	}

	/**
	 * Creates a real checkout page with the given saved block markup.
	 *
	 * @param string $content Saved page content.
	 * @return int
	 */
	private function set_checkout_content( string $content ): int {
		$page_id = $this->create_page( $content );
		update_option( 'woocommerce_checkout_page_id', $page_id );

		return $page_id;
	}

	/**
	 * Creates a published WordPress page and tracks it for cleanup.
	 *
	 * @param string $content Page content.
	 * @return int
	 */
	private function create_page( string $content ): int {
		$page_id = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $content,
			]
		);
		$this->pages[] = $page_id;

		return $page_id;
	}

	/**
	 * Adds a real WooCommerce method instance to a real shipping zone.
	 *
	 * @param string $method_id Registered WooCommerce shipping method ID.
	 * @return int Instance ID.
	 */
	private function add_method_to_zone( string $method_id ): int {
		$zone = new \WC_Shipping_Zone();
		$zone->set_zone_name( 'Checkout parent block notice test' );
		$zone->save();
		$this->zones[] = $zone;

		$instance_id = $zone->add_shipping_method( $method_id );
		$this->remember_settings_option( 'woocommerce_' . $method_id . '_' . $instance_id . '_settings' );

		return $instance_id;
	}

	/**
	 * Disables every zone instance of a method so the test proves the fleet-wide gate.
	 *
	 * @param string $method_id Method ID.
	 * @return void
	 */
	private function disable_method( string $method_id ): void {
		$zones = [ new \WC_Shipping_Zone( 0 ) ];
		foreach ( \WC_Shipping_Zones::get_zones() as $zone_data ) {
			$zones[] = new \WC_Shipping_Zone( (int) $zone_data['zone_id'] );
		}

		foreach ( $zones as $zone ) {
			foreach ( $zone->get_shipping_methods( false ) as $method ) {
				if ( $method_id !== $method->id ) {
					continue;
				}

				$option = 'woocommerce_' . $method_id . '_' . $method->instance_id . '_settings';
				$this->remember_settings_option( $option );
				$settings            = (array) get_option( $option, [] );
				$settings['enabled'] = 'no';
				update_option( $option, $settings );
			}
		}
	}

	/**
	 * Records a method settings option before changing it so the test can restore it.
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	private function remember_settings_option( string $option ): void {
		if ( ! array_key_exists( $option, $this->original_settings_options ) ) {
			$this->original_settings_options[ $option ] = get_option( $option, null );
		}
	}

	/**
	 * Runs the real admin notice action and returns its rendered output.
	 *
	 * @return string
	 */
	private function render_admin_notices(): string {
		ob_start();
		do_action( 'admin_notices' );

		return (string) ob_get_clean();
	}

	/**
	 * Asserts that the real admin-notice output includes exactly one notice.
	 *
	 * @return void
	 */
	private function assertNoticeRendered(): void {
		$this->assertSame( 1, substr_count( $this->render_admin_notices(), self::NOTICE_ID ) );
	}

	/**
	 * Asserts that the real admin-notice output contains no compatibility notice.
	 *
	 * @return void
	 */
	private function assertNoNoticeRendered(): void {
		$this->assertSame( 0, substr_count( $this->render_admin_notices(), self::NOTICE_ID ) );
	}

	/**
	 * Resets request-local static caches so cases are independent within this PHP process.
	 *
	 * @return void
	 */
	private function reset_notice_caches(): void {
		foreach ( [ 'page_results' => [], 'notice_added' => false ] as $name => $value ) {
			$property = new \ReflectionProperty( Checkout_Parent_Block_Notice::class, $name );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$property->setValue( null, $value );
		}
	}

	/**
	 * Returns a block checkout containing both required shipping parent blocks.
	 *
	 * @return string
	 */
	private function complete_checkout_content(): string {
		return '<!-- wp:woocommerce/checkout --><!-- wp:woocommerce/checkout-shipping-address-block /--><!-- wp:woocommerce/checkout-shipping-methods-block /--><!-- /wp:woocommerce/checkout -->';
	}
}
