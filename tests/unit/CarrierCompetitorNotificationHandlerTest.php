<?php
/**
 * Fleet-wide carrier competitor notices.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Competitor\Carrier_Competitor_Notification_Handler;

require_once dirname( __DIR__, 2 ) . '/woodev/competitor/class-competitor-rule.php';
require_once dirname( __DIR__, 2 ) . '/woodev/competitor/interface-competitor-notice-renderer.php';
require_once dirname( __DIR__, 2 ) . '/woodev/competitor/class-competitor-notification-handler.php';
require_once dirname( __DIR__, 2 ) . '/woodev/competitor/class-admin-notice-renderer.php';
require_once dirname( __DIR__, 2 ) . '/woodev/competitor/class-carrier-catalogue.php';
require_once dirname( __DIR__, 2 ) . '/woodev/competitor/class-carrier-competitor-notification-handler.php';

class CarrierCompetitorNotificationHandlerTest extends TestCase {
	/** @var string[] active plugin basenames supplied by the WordPress option stub */
	private static array $active_plugins = [];

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'sanitize_key' )->alias( static fn( $key ) => strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $key ) ) );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://example.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn( $args, $url ) => $url . '?' . http_build_query( $args ) );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		self::$active_plugins = [];
		Functions\when( 'get_option' )->alias(
			static function ( $option, $default = false ) {
				return 'active_plugins' === $option ? self::$active_plugins : $default;
			}
		);
		Functions\when( 'get_site_option' )->justReturn( [] );

		$evaluated = new \ReflectionProperty( Carrier_Competitor_Notification_Handler::class, 'recommendations_evaluated' );
		if ( PHP_VERSION_ID < 80100 ) {
			$evaluated->setAccessible( true );
		}
		$evaluated->setValue( null, false );
	}

	public function test_cdek_conflict_is_rendered_by_its_own_plugin(): void {
		$notices = [];
		$plugin  = $this->make_plugin( [ 'woocommerce-edostavka/woocommerce-edostavka.php', 'cdekdelivery/cdek.php' ], $notices );
		$handler = new Carrier_Competitor_Notification_Handler( $plugin, 'cdek' );
		$handler->run();

		$this->assertCount( 1, $notices );
		$this->assertStringContainsString( 'Информация от Woodev: обнаружен плагин СДЭК стороннего разработчика', $notices[0]['message'] );
		$this->assertStringContainsString( 'На сайте активен плагин CDEKDelivery.', $notices[0]['message'] );
		$this->assertStringContainsString( 'Во избежание конфликтов отключите плагин CDEKDelivery.', $notices[0]['message'] );
		$this->assertStringContainsString( 'Отключить CDEKDelivery', $notices[0]['message'] );
		$this->assertStringContainsString( 'Скрыть', $notices[0]['message'] );
	}

	public function test_cdek_plugin_can_recommend_yandex_once(): void {
		$notices = [];
		$plugin  = $this->make_plugin( [ 'woocommerce-edostavka/woocommerce-edostavka.php', 'yandex-go-delivery/yandex-go-delivery.php' ], $notices );
		( new Carrier_Competitor_Notification_Handler( $plugin, 'cdek' ) )->run();

		$this->assertCount( 1, $notices );
		$this->assertStringContainsString( 'Яндекс Доставка', $notices[0]['message'] );
		$this->assertSame( 'woodev-competitor-recommend-yandex', $notices[0]['id'] );
		$this->assertStringContainsString( 'Подробнее о плагине', $notices[0]['message'] );
	}

	public function test_yandex_recommendation_is_not_duplicated_across_woodev_plugins(): void {
		$notices = [];
		$plugin  = $this->make_plugin(
			[
				'woocommerce-edostavka/woocommerce-edostavka.php',
				'woodev-russian-post/woodev-russian-post.php',
				'yandex-go-delivery/yandex-go-delivery.php',
			],
			$notices
		);

		( new Carrier_Competitor_Notification_Handler( $plugin, 'cdek' ) )->run();
		( new Carrier_Competitor_Notification_Handler( $plugin, 'russian-post' ) )->run();

		$this->assertCount( 1, $notices );
		$this->assertStringContainsString( 'Яндекс Доставка', $notices[0]['message'] );
	}

	public function test_yandex_conflict_takes_over_its_recommendation(): void {
		$notices = [];
		$plugin  = $this->make_plugin( [ 'woocommerce-yandex-delivery/woocommerce-yandex-delivery.php', 'yandex-go-delivery/yandex-go-delivery.php' ], $notices );
		( new Carrier_Competitor_Notification_Handler( $plugin, 'yandex' ) )->run();

		$this->assertCount( 1, $notices );
		$this->assertStringContainsString( 'Во избежание конфликтов отключите плагин Яндекс Доставка.', $notices[0]['message'] );
		$this->assertSame( 'woodev-competitor-conflict-yandex-go-delivery-yandex-go-delivery-php', $notices[0]['id'] );
	}

	public function test_legacy_cdek_plugin_file_is_detected(): void {
		$legacy_files = [
			'cdek-for-woocommerce/cdek-for-woocommerce.php' => 'CDEK для WooCommerce',
			'woo-cdek/woo-cdek.php' => 'Woo CDEK',
		];

		foreach ( $legacy_files as $file => $name ) {
			$notices = [];
			$plugin  = $this->make_plugin( [ 'woocommerce-edostavka/woocommerce-edostavka.php', $file ], $notices );
			( new Carrier_Competitor_Notification_Handler( $plugin, 'cdek' ) )->run();

			$this->assertCount( 1, $notices );
			$this->assertStringContainsString( $name, $notices[0]['message'] );
			$this->assertStringContainsString( rawurlencode( $file ), $notices[0]['message'] );
		}
	}

	public function test_other_woodev_product_is_matched_by_main_filename_after_folder_rename(): void {
		$notices = [];
		$plugin  = $this->make_plugin( [ 'custom-yandex-folder/woocommerce-yandex-delivery.php' ], $notices );
		( new Carrier_Competitor_Notification_Handler( $plugin, 'cdek' ) )->run();

		$this->assertCount( 0, $notices );
	}

	public function test_competitor_is_detected_from_network_active_plugin_keys(): void {
		$notices = [];
		Functions\when( 'get_site_option' )->alias(
			static fn( $option, $default = false ) => 'active_sitewide_plugins' === $option ? [ 'cdekdelivery/cdek.php' => 1 ] : $default
		);
		$plugin = $this->make_plugin( [ 'woocommerce-edostavka/woocommerce-edostavka.php' ], $notices );
		( new Carrier_Competitor_Notification_Handler( $plugin, 'cdek' ) )->run();

		$this->assertCount( 1, $notices );
		$this->assertStringContainsString( 'CDEKDelivery', $notices[0]['message'] );
	}

	public function test_generic_cdek_filename_in_another_folder_is_not_detected(): void {
		$notices = [];
		$plugin  = $this->make_plugin( [ 'unrelated-plugin/cdek.php' ], $notices );
		( new Carrier_Competitor_Notification_Handler( $plugin, 'cdek' ) )->run();

		$this->assertCount( 0, $notices );
	}

	public function test_admin_notice_renderer_is_used_when_wc_notes_are_unavailable(): void {
		$this->assertFalse( class_exists( 'Automattic\\WooCommerce\\Admin\\Notes\\Note' ) );

		$notices = [];
		$plugin  = $this->make_plugin( [ 'woocommerce-edostavka/woocommerce-edostavka.php', 'cdekdelivery/cdek.php' ], $notices );
		( new Carrier_Competitor_Notification_Handler( $plugin, 'cdek' ) )->run();

		$this->assertCount( 1, $notices );
		$this->assertStringContainsString( 'CDEKDelivery', $notices[0]['message'] );
	}

	/**
	 * @param string[] $active active plugin basenames
	 * @param array<int,array{id:string,message:string}> $notices captured notices
	 * @return \Woodev_Plugin
	 */
	private function make_plugin( array $active, array &$notices ) {
		self::$active_plugins = $active;
		$admin_notices = Mockery::mock( 'Woodev_Admin_Notice_Handler' );
		$admin_notices->shouldReceive( 'add_admin_notice' )->andReturnUsing(
			static function ( $message, $id ) use ( &$notices ): void {
				$notices[] = [ 'id' => $id, 'message' => $message ];
			}
		);

		$plugin = Mockery::mock( 'Woodev_Plugin' );
		$plugin->shouldReceive( 'get_admin_notice_handler' )->andReturn( $admin_notices );

		return $plugin;
	}
}
