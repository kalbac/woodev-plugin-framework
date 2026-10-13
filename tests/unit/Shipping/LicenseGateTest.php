<?php
/**
 * Unit: the shipping layer's licence gate (#1270) — the question every gate asks, the wording a merchant reads,
 * and the two licence notices on the carrier's settings tab and the shipping orders page.
 *
 * @package Woodev\Tests\Unit\Shipping
 */

namespace Woodev\Tests\Unit\Shipping;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Shipping\License_Gate;
use Woodev\Framework\Shipping\Shipping_Plugin;
use Woodev\Tests\Unit\TestCase;

/**
 * @covers \Woodev\Framework\Shipping\License_Gate
 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::add_license_admin_notices
 * @covers \Woodev\Framework\Shipping\Shipping_Plugin::is_license_notice_screen
 */
final class LicenseGateTest extends TestCase {

	private const NOW = 1800000000;

	/** @var array<string, array{message: string, params: array}> notices handed to the notice handler */
	private array $notices = [];

	protected function setUp(): void {
		parent::setUp();

		$this->notices = [];
		$_GET          = [];

		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://shop.test/wp-admin/' . $path );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		$_GET = [];

		parent::tearDown();
	}

	/**
	 * A plugin whose licence engine answers as given.
	 *
	 * @param bool|null $in_force true / false for a paid plugin; null = no store product.
	 * @param int|null  $expires  the verified licence expiry the engine reports.
	 * @param string    $key      the stored licence key ('' = none).
	 * @return Shipping_Plugin&\Mockery\MockInterface
	 */
	private function plugin( ?bool $in_force, ?int $expires = null, string $key = 'KEY-1' ) {
		$plugin = Mockery::mock( Shipping_Plugin::class )->makePartial()->shouldAllowMockingProtectedMethods();
		$plugin->shouldReceive( 'get_download_id' )->andReturn( null === $in_force ? 0 : 9001 );
		$plugin->shouldReceive( 'get_id' )->andReturn( 'cdek' );
		$plugin->shouldReceive( 'get_id_dasherized' )->andReturn( 'cdek' );
		$plugin->shouldReceive( 'get_plugin_name' )->andReturn( 'СДЭК' );

		if ( null !== $in_force ) {
			$engine = Mockery::mock( \Woodev_Plugins_License::class );
			$engine->shouldReceive( 'is_license_in_force' )->andReturn( $in_force );
			$engine->shouldReceive( 'get_license_expires' )->andReturn( $expires );
			$engine->shouldReceive( 'get_license' )->andReturn( '' === $key ? false : $key );
			$plugin->shouldReceive( 'get_license_instance' )->andReturn( $engine );
		}

		$handler = Mockery::mock( \Woodev_Admin_Notice_Handler::class );
		$handler->shouldReceive( 'add_admin_notice' )->andReturnUsing(
			function ( string $message, string $id, array $params = [] ) {
				$this->notices[ $id ] = [
					'message' => $message,
					'params'  => $params,
				];
			}
		);
		$plugin->shouldReceive( 'get_admin_notice_handler' )->andReturn( $handler );

		return $plugin;
	}

	/**
	 * @param Shipping_Plugin $plugin the plugin.
	 * @return void
	 */
	private function run_notices( $plugin ): void {
		$method = new \ReflectionMethod( Shipping_Plugin::class, 'add_license_admin_notices' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		$method->invoke( $plugin );
	}

	// ---- the question and the words ------------------------------------------------------------------------------

	public function test_a_plugin_with_no_store_product_is_in_force_without_asking_the_engine(): void {
		$plugin = $this->plugin( null );
		$plugin->shouldNotReceive( 'get_license_instance' );

		$this->assertTrue( License_Gate::is_in_force( $plugin ) );
	}

	public function test_a_paid_plugin_follows_its_engine(): void {
		$this->assertTrue( License_Gate::is_in_force( $this->plugin( true ) ) );
		$this->assertFalse( License_Gate::is_in_force( $this->plugin( false ) ) );
	}

	public function test_a_claim_refresh_request_reaches_the_engine_and_can_never_break_the_caller(): void {
		$engine = Mockery::mock( \Woodev_Plugins_License::class );
		$engine->shouldReceive( 'schedule_claim_refresh' )->once()->andReturn( true );
		$plugin = Mockery::mock( Shipping_Plugin::class );
		$plugin->shouldReceive( 'get_license_instance' )->andReturn( $engine );

		License_Gate::request_claim_refresh( $plugin );

		$broken = Mockery::mock( Shipping_Plugin::class );
		$broken->shouldReceive( 'get_license_instance' )->andThrow( new \RuntimeException( 'boom' ) );
		License_Gate::request_claim_refresh( $broken );

		$this->addToAssertionCount( 1 ); // reaching this line is the assertion: nothing escaped.
	}

	public function test_the_refusal_names_the_action_and_carries_the_licence_page_address(): void {
		$text = License_Gate::refusal( 'выгрузить заказ перевозчику' );

		$this->assertStringContainsString( 'Лицензия плагина не действует, поэтому нельзя выгрузить заказ перевозчику.', $text );
		$this->assertStringContainsString( 'https://shop.test/wp-admin/admin.php?page=woodev-licenses', $text );
		$this->assertSame( strip_tags( $text ), $text, 'plain text: the pages that print it escape it' );
	}

	public function test_the_manager_reason_carries_the_licence_page_address(): void {
		$this->assertStringContainsString( 'https://shop.test/wp-admin/admin.php?page=woodev-licenses', License_Gate::hidden_reason() );
	}

	/**
	 * @return array<string, array{0: ?int, 1: ?int}> expiry offset in days (null = none), expected days left (null = no reminder)
	 */
	public static function expiry_windows(): array {
		return [
			'lifetime'        => [ null, null ],
			'31 days'         => [ 31, null ],
			'30 days'         => [ 30, 30 ],
			'10 days'         => [ 10, 10 ],
			'tomorrow'        => [ 1, 1 ],
			'part of a day'   => [ 0, 1 ],
		];
	}

	/**
	 * @dataProvider expiry_windows
	 *
	 * @param int|null $days     days until the licence ends, or null for none.
	 * @param int|null $expected days left reported, or null for no reminder.
	 * @return void
	 */
	public function test_the_reminder_starts_thirty_days_before_the_end( ?int $days, ?int $expected ): void {
		// ten minutes past the whole day: still `$days`, and the last partial day reads «1».
		$expires = null === $days ? null : self::NOW + $days * DAY_IN_SECONDS + 600;

		$this->assertSame( $expected, License_Gate::days_until_expiry( $this->plugin( true, $expires ), self::NOW ) );
	}

	public function test_no_reminder_when_the_licence_is_not_in_force_or_the_product_is_licence_free(): void {
		$this->assertNull( License_Gate::days_until_expiry( $this->plugin( false, self::NOW + DAY_IN_SECONDS ), self::NOW ) );
		$this->assertNull( License_Gate::days_until_expiry( $this->plugin( true, null ), self::NOW ), 'a licence-free or lifetime product has no end to remind of' );
	}

	// ---- the notices -----------------------------------------------------------------------------------------------

	public function test_a_licence_not_in_force_gets_a_persistent_error_notice_with_a_link_on_the_carriers_tab(): void {
		$_GET = [
			'page' => 'woodev-settings',
			'tab'  => 'cdek',
		];

		$this->run_notices( $this->plugin( false ) );

		$this->assertCount( 1, $this->notices );
		$notice = $this->notices['woodev-cdek-license-inactive'];
		$this->assertStringContainsString( 'Лицензия плагина «СДЭК» не действует', $notice['message'] );
		$this->assertStringContainsString( '<a href="https://shop.test/wp-admin/admin.php?page=woodev-licenses">', $notice['message'] );
		$this->assertFalse( $notice['params']['dismissible'], 'persistent: no dismiss button' );
		$this->assertSame( 'notice-error', $notice['params']['notice_class'] );
	}

	public function test_no_key_at_all_is_told_apart_and_still_noticed(): void {
		$_GET = [
			'page' => 'woodev-settings',
			'tab'  => 'cdek',
		];

		$this->run_notices( $this->plugin( false, null, '' ) );

		$notice = $this->notices['woodev-cdek-license-inactive'];
		$this->assertStringContainsString( 'не введён лицензионный ключ', $notice['message'] );
		$this->assertFalse( $notice['params']['dismissible'] );
	}

	public function test_the_notice_shows_on_the_shipping_orders_page_in_wc_admin(): void {
		$_GET = [
			'page' => 'wc-admin',
			'path' => '/woodev-shipping-orders',
		];

		$this->run_notices( $this->plugin( false ) );

		$this->assertArrayHasKey( 'woodev-cdek-license-inactive', $this->notices );
	}

	public function test_the_notice_shows_on_the_legacy_orders_page_slug_too(): void {
		$_GET = [ 'page' => 'woodev-shipping-orders' ];

		$this->run_notices( $this->plugin( false ) );

		$this->assertArrayHasKey( 'woodev-cdek-license-inactive', $this->notices );
	}

	/**
	 * @return array<string, array{0: array<string, string>}>
	 */
	public static function other_screens(): array {
		return [
			'dashboard'               => [ [] ],
			'another carriers tab'    => [
				[
					'page' => 'woodev-settings',
					'tab'  => 'yandex',
				],
			],
			'woocommerce settings'    => [
				[
					'page' => 'wc-settings',
					'tab'  => 'shipping',
				],
			],
			'another wc-admin page'   => [
				[
					'page' => 'wc-admin',
					'path' => '/analytics/orders',
				],
			],
			'the licence page itself' => [ [ 'page' => 'woodev-licenses' ] ],
		];
	}

	/**
	 * @dataProvider other_screens
	 *
	 * @param array<string, string> $query the request.
	 * @return void
	 */
	public function test_no_notice_anywhere_else( array $query ): void {
		$_GET = $query;

		$this->run_notices( $this->plugin( false ) );

		$this->assertSame( [], $this->notices );
	}

	public function test_a_licence_in_force_far_from_its_end_gets_no_notice(): void {
		$_GET = [
			'page' => 'woodev-settings',
			'tab'  => 'cdek',
		];

		$this->run_notices( $this->plugin( true, time() + 90 * DAY_IN_SECONDS ) );
		$this->run_notices( $this->plugin( true, null ) );
		$this->run_notices( $this->plugin( null ) );

		$this->assertSame( [], $this->notices );
	}

	public function test_a_licence_ending_soon_gets_a_dismissible_reminder_that_returns_each_week(): void {
		$_GET = [
			'page' => 'woodev-settings',
			'tab'  => 'cdek',
		];

		$this->run_notices( $this->plugin( true, time() + 10 * DAY_IN_SECONDS + 600 ) );

		$this->assertCount( 1, $this->notices );
		$id = array_key_first( $this->notices );
		$this->assertSame( 'woodev-cdek-license-expires-' . gmdate( 'o-\WW' ), $id, 'the week is part of the id: a dismissal lasts the week' );

		$notice = $this->notices[ $id ];
		$this->assertStringContainsString( 'Срок лицензии закончится через 10 дн. — не забудьте', $notice['message'] );
		$this->assertStringContainsString( 'admin.php?page=woodev-licenses', $notice['message'] );
		$this->assertTrue( $notice['params']['dismissible'] );
		$this->assertSame( 'notice-warning', $notice['params']['notice_class'] );
	}
}
