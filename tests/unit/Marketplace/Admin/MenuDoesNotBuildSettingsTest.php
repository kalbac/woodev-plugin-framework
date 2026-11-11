<?php
/**
 * Building the marketplace menu does not build the settings sections (#1727): a section may read the plugin's tables, and the
 * menu is built on every `rest_api_init`, including the first request after a fresh activation, before the tables exist.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin
 */

namespace Woodev\Tests\Unit\Marketplace\Admin;

use Brain\Monkey\Functions;
use Woodev\Framework\Marketplace\Settings\Settings_Handler;
use Woodev\Framework\Marketplace\Settings\Settings_Section_Provider;
use Woodev\Framework\Settings\Settings_Section;
use Woodev\Tests\Unit\TestCase;

require_once __DIR__ . '/Test_Marketplace_Plugin.php';

/**
 * A section provider that counts its `sections()` calls and can play a failed table read.
 */
final class Counting_Section_Provider implements Settings_Section_Provider {

	/** @var int */
	public $calls = 0;

	/** @var bool */
	public $throws = false;

	public function register_settings( Settings_Handler $handler ): void {
	}

	public function sections(): array {
		++$this->calls;

		if ( $this->throws ) {
			throw new \RuntimeException( 'no such table: wp_woodev_fake_accounts' );
		}

		return [ Settings_Section::create( 'accounts', 'Аккаунты', [] ) ];
	}
}

/**
 * @covers \Woodev\Framework\Marketplace\Marketplace_Plugin
 * @covers \Woodev\Framework\Marketplace\Settings\Settings_Handler::get_settings_provider
 * @covers \Woodev\Framework\Settings\Settings_Provider::create_lazy
 */
final class MenuDoesNotBuildSettingsTest extends TestCase {

	private const MENU = [
		'id'       => 'ozon-seller',
		'title'    => 'OZON Seller',
		'icon'     => 'https://example.test/icon.svg',
		'position' => '55.63',
	];

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'admin_url' )->alias(
			static function ( string $path = '' ): string {
				return 'https://shop.test/wp-admin/' . $path;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ): string {
				return $url . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
			}
		);
		Functions\when( 'sanitize_key' )->alias(
			static function ( string $key ): string {
				return strtolower( (string) preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) );
			}
		);
		Functions\when( 'get_option' )->justReturn( false );
	}

	/**
	 * @param Counting_Section_Provider $sections The section provider behind the plugin's settings tab.
	 * @return Test_Marketplace_Plugin
	 */
	private function plugin_with( Counting_Section_Provider $sections ): Test_Marketplace_Plugin {
		$handler = new Settings_Handler( 'ozon-seller', [ $sections ], 'OZON Seller' );

		return new Test_Marketplace_Plugin( 'ozon-seller', self::MENU, null, [ $handler->get_settings_provider() ] );
	}

	public function test_a_section_that_cannot_be_built_does_not_stop_the_menu(): void {
		$sections         = new Counting_Section_Provider();
		$sections->throws = true;

		$menu = $this->plugin_with( $sections )->get_marketplace_admin_menu();

		$this->assertNotNull( $menu, 'the menu is built' );
		$this->assertContains( 'settings', array_map( static function ( $page ) { return $page->get_slug(); }, $menu->get_pages() ) );
		$this->assertSame( 0, $sections->calls, 'sections() is never called while the menu is built' );
	}

	public function test_the_sections_are_built_on_the_first_read_and_kept(): void {
		$sections = new Counting_Section_Provider();
		$provider = $this->plugin_with( $sections )->get_settings_providers()[0];

		$this->assertSame( 0, $sections->calls );
		$this->assertCount( 1, $provider->get_sections() );
		$this->assertCount( 1, $provider->get_sections() );
		$this->assertSame( 1, $sections->calls, 'built once' );
	}

	public function test_a_read_that_failed_is_tried_again_by_the_next_one(): void {
		$sections         = new Counting_Section_Provider();
		$sections->throws = true;
		$provider         = $this->plugin_with( $sections )->get_settings_providers()[0];

		try {
			$provider->get_sections();
			$this->fail( 'the failed read reaches the caller' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 1, $sections->calls );
		}

		$sections->throws = false;

		$this->assertCount( 1, $provider->get_sections(), 'the tables exist now: the sections are built' );
		$this->assertSame( 2, $sections->calls );
	}
}
