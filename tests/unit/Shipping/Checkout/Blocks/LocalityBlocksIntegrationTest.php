<?php
/**
 * Tests for Locality_Blocks_Integration — the script/data half of the Checkout Blocks locality
 * chooser (SP-11 C-1, #1087).
 *
 * The class implements WooCommerce Blocks' `IntegrationInterface`, which does not exist in the unit
 * process (and must not: another test asserts its ABSENCE degrades gracefully). Each test therefore
 * runs in its own process and declares the interface itself.
 *
 * @package Woodev\Tests\Unit\Shipping\Checkout\Blocks
 */

namespace Woodev\Tests\Unit\Shipping\Checkout\Blocks;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\Checkout\Checkout_Handler;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 5 ) . '/woodev/shipping-method/checkout/class-field.php';
require_once dirname( __DIR__, 5 ) . '/woodev/shipping-method/checkout/class-checkout-fields.php';
require_once dirname( __DIR__, 5 ) . '/woodev/shipping-method/checkout/class-checkout-condition.php';
require_once dirname( __DIR__, 5 ) . '/woodev/shipping-method/checkout/class-checkout-handler.php';
require_once dirname( __DIR__, 5 ) . '/woodev/shipping-method/checkout/blocks/class-locality-blocks.php';

/**
 * @covers \Woodev\Framework\Shipping\Checkout\Blocks\Locality_Blocks_Integration
 */
class LocalityBlocksIntegrationTest extends TestCase {

	/** @var string */
	private $build_dir = '';

	protected function setUp(): void {
		parent::setUp();

		if ( ! interface_exists( '\Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface', false ) ) {
			eval( 'namespace Automattic\WooCommerce\Blocks\Integrations; interface IntegrationInterface { public function get_name(); public function initialize(); public function get_script_handles(); public function get_editor_script_handles(); public function get_script_data(); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}

		require_once dirname( __DIR__, 5 ) . '/woodev/shipping-method/checkout/blocks/class-locality-blocks-integration.php';

		eval( 'namespace Woodev\Tests\Unit\Shipping\Checkout\Blocks; class Built_Locality_Integration extends \Woodev\Framework\Shipping\Checkout\Blocks\Locality_Blocks_Integration { public static $dir = ""; protected static function build_path(): string { return self::$dir; } protected static function build_url(): string { return "https://example.test/build"; } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged

		$this->build_dir = sys_get_temp_dir() . '/woodev-locality-' . uniqid( '', true );
		mkdir( $this->build_dir );
		Built_Locality_Integration::$dir = $this->build_dir;
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->build_dir . '/*' ) as $file ) {
			unlink( $file );
		}
		if ( '' !== $this->build_dir && is_dir( $this->build_dir ) ) {
			rmdir( $this->build_dir );
		}
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed>|null $location What the handler's `locality_blocks_config()` answers.
	 */
	private function integration( ?array $location ): Built_Locality_Integration {
		$handler = Mockery::mock( Checkout_Handler::class );
		$handler->shouldReceive( 'locality_blocks_config' )->andReturn( $location );

		return new Built_Locality_Integration( $handler );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_it_is_named_for_the_data_key_the_bundle_reads(): void {
		$this->assertSame( 'woodev-shipping-locality', $this->integration( null )->get_name() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_unbuilt_bundle_registers_no_handle_so_nothing_can_404(): void {
		Functions\expect( 'wp_register_script' )->never();
		Functions\when( 'wp_script_is' )->justReturn( false );

		$integration = $this->integration( null );
		$integration->initialize();

		$this->assertSame( [], $integration->get_script_handles() );
		$this->assertSame( [], $integration->get_editor_script_handles() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_built_bundle_registers_with_the_woocommerce_handles_declared_by_hand(): void {
		file_put_contents( $this->build_dir . '/index.asset.php', "<?php return array( 'dependencies' => array( 'wp-element', 'wp-data' ), 'version' => 'abc123' );" );
		file_put_contents( $this->build_dir . '/style-index.css', '' );
		Functions\when( 'plugins_url' )->justReturn( 'https://example.test/build/index.js' );

		Functions\expect( 'wp_register_script' )
			->once()
			->with(
				'woodev-checkout-blocks',
				'https://example.test/build/index.js',
				[ 'wp-element', 'wp-data', 'wc-blocks-checkout', 'wc-blocks-data-store', 'wc-settings' ],
				'abc123',
				true
			);
		Functions\expect( 'wp_register_style' )->once()->with( 'woodev-checkout-blocks', 'https://example.test/build/style-index.css', [], 'abc123' );
		// Registered, never enqueued here: `initialize()` runs on every request, checkout or not.
		Functions\expect( 'wp_enqueue_style' )->never();

		$this->integration( null )->initialize();
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_script_data_is_disabled_in_the_editor_and_never_carries_the_nonce(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		$data = $this->integration( [ 'nonce' => 'SECRET' ] )->get_script_data();

		$this->assertSame( [ 'enabled' => false ], $data );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_script_data_is_disabled_when_the_location_layer_is_inactive(): void {
		Functions\when( 'is_admin' )->justReturn( false );

		$this->assertSame( [ 'enabled' => false ], $this->integration( null )->get_script_data() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_script_data_publishes_the_location_block_with_the_chooser_strings_and_enqueues_the_style(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_style_is' )->justReturn( true );
		Functions\expect( 'wp_enqueue_style' )->once()->with( 'woodev-checkout-blocks' );

		$data = $this->integration( [ 'nonce' => 'N', 'i18n' => [ 'noResults' => 'No results found.' ] ] )->get_script_data();

		$this->assertTrue( $data['enabled'] );
		$this->assertSame( 'N', $data['location']['nonce'] );
		// The location layer's own strings stay; the chooser's own are merged in beside them.
		$this->assertSame( 'No results found.', $data['location']['i18n']['noResults'] );
		$this->assertSame( 'Find your locality', $data['location']['i18n']['label'] );
	}
}
