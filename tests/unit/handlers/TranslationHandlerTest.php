<?php
/**
 * Translation handler tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Handlers\Translation_Handler;

require_once dirname( __DIR__, 3 ) . '/woodev/handlers/class-translation-handler.php';

/**
 * Class TranslationHandlerTest.
 */
class TranslationHandlerTest extends TestCase {

	/**
	 * Builds a plugin test double exposing the accessors the handler relies on.
	 *
	 * @param string $textdomain Plugin textdomain to return from get_textdomain().
	 * @return \Woodev_Plugin&\Mockery\MockInterface
	 */
	private function make_plugin( string $textdomain ) {
		$plugin = Mockery::mock( \Woodev_Plugin::class );
		$plugin->shouldReceive( 'get_textdomain' )->andReturn( $textdomain );
		$plugin->shouldReceive( 'get_framework_file' )->andReturn( '/srv/wp/plugins/acme/woodev/class-plugin.php' );
		$plugin->shouldReceive( 'get_plugin_file' )->andReturn( 'acme/acme.php' );

		return $plugin;
	}

	/**
	 * Constructing the handler registers the init action for translation loading.
	 *
	 * @return void
	 */
	public function test_constructor_registers_init_action(): void {
		$plugin = $this->make_plugin( 'acme' );

		Functions\expect( 'add_action' )
			->once()
			->with( 'init', Mockery::type( 'array' ) );

		new Translation_Handler( $plugin );
	}

	/**
	 * The framework textdomain accessor returns the fixed framework domain.
	 *
	 * @return void
	 */
	public function test_framework_textdomain_is_fixed(): void {
		$plugin = $this->make_plugin( 'acme' );

		Functions\when( 'add_action' )->justReturn( true );

		$handler = new Translation_Handler( $plugin );

		$this->assertSame( 'woodev-plugin-framework', $handler->get_framework_textdomain() );
	}

	/**
	 * load_translations() loads both the framework and the plugin textdomains.
	 *
	 * @return void
	 */
	public function test_load_translations_loads_framework_and_plugin_domains(): void {
		$plugin = $this->make_plugin( 'acme-plugin' );

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'get_locale' )->justReturn( 'ru_RU' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'plugin_basename' )->returnArg( 1 );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $value ): string {
				return rtrim( (string) $value, '/' );
			}
		);

		if ( ! defined( 'WP_LANG_DIR' ) ) {
			define( 'WP_LANG_DIR', '/srv/wp/languages' );
		}

		$loaded_textdomains        = [];
		$loaded_plugin_textdomains = [];

		Functions\when( 'load_textdomain' )->alias(
			static function ( $domain, $mofile ) use ( &$loaded_textdomains ): bool {
				$loaded_textdomains[ $domain ] = $mofile;

				return true;
			}
		);
		Functions\when( 'load_plugin_textdomain' )->alias(
			static function ( $domain, $deprecated, $path ) use ( &$loaded_plugin_textdomains ): bool {
				$loaded_plugin_textdomains[ $domain ] = $path;

				return true;
			}
		);

		$handler = new Translation_Handler( $plugin );

		$handler->load_translations();

		// framework domain must be loaded with the fixed string
		$this->assertArrayHasKey( 'woodev-plugin-framework', $loaded_textdomains );
		$this->assertArrayHasKey( 'woodev-plugin-framework', $loaded_plugin_textdomains );

		// the plugin's own domain (from get_textdomain()) must be loaded too
		$this->assertArrayHasKey( 'acme-plugin', $loaded_textdomains );
		$this->assertArrayHasKey( 'acme-plugin', $loaded_plugin_textdomains );
	}

	/**
	 * When the plugin has no textdomain, only the framework domain is loaded.
	 *
	 * @return void
	 */
	public function test_load_translations_skips_plugin_domain_when_empty(): void {
		$plugin = $this->make_plugin( '' );

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'get_locale' )->justReturn( 'ru_RU' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'plugin_basename' )->returnArg( 1 );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $value ): string {
				return rtrim( (string) $value, '/' );
			}
		);

		if ( ! defined( 'WP_LANG_DIR' ) ) {
			define( 'WP_LANG_DIR', '/srv/wp/languages' );
		}

		$loaded_textdomains = [];

		Functions\when( 'load_textdomain' )->alias(
			static function ( $domain, $mofile ) use ( &$loaded_textdomains ): bool {
				$loaded_textdomains[ $domain ] = $mofile;

				return true;
			}
		);
		Functions\when( 'load_plugin_textdomain' )->justReturn( true );

		$handler = new Translation_Handler( $plugin );

		$handler->load_translations();

		$this->assertArrayHasKey( 'woodev-plugin-framework', $loaded_textdomains );
		$this->assertArrayNotHasKey( '', $loaded_textdomains );
		$this->assertCount( 1, $loaded_textdomains );
	}
}
