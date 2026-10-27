<?php
/**
 * Translation handler tests.
 *
 * @package Woodev\Tests\Unit\Handlers
 */

namespace Woodev\Tests\Unit\Handlers;

use Woodev\Tests\Unit\TestCase;

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
		Functions\expect( 'add_action' )
			->once()
			->with( 'change_locale', Mockery::type( 'array' ) );

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
	 * load_translations() loads only the plugin domain; the resolver owns the shared domain.
	 *
	 * @return void
	 */
	public function test_load_translations_loads_only_plugin_domain(): void {
		$plugin = $this->make_plugin( 'acme-plugin' );

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'get_locale' )->justReturn( 'ru_RU' );
		Functions\when( 'determine_locale' )->justReturn( 'ru_RU' );
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
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			define( 'WP_PLUGIN_DIR', '/srv/wp/wp-content/plugins' );
		}

		$loaded_textdomains        = [];
		$loaded_plugin_textdomains = [];

		Functions\when( 'load_textdomain' )->alias(
			static function ( $domain, $mofile ) use ( &$loaded_textdomains ): bool {
				$loaded_textdomains[] = [ $domain, $mofile ];

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

		// The framework loader belongs to the resolver, independent of plugin instances.
		$this->assertArrayNotHasKey( 'woodev-plugin-framework', $loaded_textdomains );
		$this->assertArrayNotHasKey( 'woodev-plugin-framework', $loaded_plugin_textdomains );

		// the plugin's own domain (from get_textdomain()) must be loaded too
		$this->assertSame( [
			[ 'acme-plugin', WP_LANG_DIR . '/acme-plugin/acme-plugin-ru_RU.mo' ],
			[ 'acme-plugin', WP_LANG_DIR . '/plugins/acme-plugin-ru_RU.mo' ],
			[ 'acme-plugin', WP_PLUGIN_DIR . '/acme/languages/acme-plugin-ru_RU.mo' ],
		], $loaded_textdomains );
		$this->assertArrayHasKey( 'acme-plugin', $loaded_plugin_textdomains );
	}

	/** A locale switch before init must not trigger an early plugin catalogue load. */
	public function test_reload_translations_skips_before_init(): void {
		$plugin = $this->make_plugin( 'acme-plugin' );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'did_action' )->justReturn( 0 );
		Functions\expect( 'unload_textdomain' )->never();
		Functions\expect( 'load_textdomain' )->never();

		$handler = new Translation_Handler( $plugin );
		$handler->reload_translations();
		$this->addToAssertionCount( 1 );
	}

	/** Admin profile locale must not override the active switched locale during reload. */
	public function test_reload_translations_uses_switched_locale_and_restores_profile_locale(): void {
		$plugin = $this->make_plugin( 'acme-plugin' );
		$current_locale = 'ru_RU';
		$loaded_locales = [];
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'get_user_locale' )->justReturn( 'en_US' );
		Functions\when( 'determine_locale' )->alias( static function () use ( &$current_locale ): string { return $current_locale; } );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'plugin_basename' )->returnArg( 1 );
		Functions\when( 'untrailingslashit' )->alias( static function ( $value ): string { return rtrim( (string) $value, '/' ); } );
		Functions\when( 'load_textdomain' )->alias( static function ( $domain, $file, $locale ) use ( &$loaded_locales ): bool { $loaded_locales[] = $locale; return true; } );
		Functions\when( 'load_plugin_textdomain' )->justReturn( true );
		Functions\when( 'unload_textdomain' )->justReturn( true );

		if ( ! defined( 'WP_LANG_DIR' ) ) {
			define( 'WP_LANG_DIR', '/srv/wp/languages' );
		}
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			define( 'WP_PLUGIN_DIR', '/srv/wp/wp-content/plugins' );
		}

		$handler = new Translation_Handler( $plugin );
		$handler->reload_translations();
		$this->assertSame( [ 'ru_RU', 'ru_RU', 'ru_RU' ], $loaded_locales );

		$current_locale = 'en_US';
		$handler->reload_translations();
		$this->assertSame( [ 'ru_RU', 'ru_RU', 'ru_RU', 'en_US', 'en_US', 'en_US' ], $loaded_locales );
	}

	/**
	 * When the plugin has no textdomain, no per-plugin translation loading is needed.
	 *
	 * @return void
	 */
	public function test_load_translations_skips_plugin_domain_when_empty(): void {
		$plugin = $this->make_plugin( '' );

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'get_locale' )->justReturn( 'ru_RU' );
		Functions\when( 'determine_locale' )->justReturn( 'ru_RU' );
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

		$this->assertArrayNotHasKey( 'woodev-plugin-framework', $loaded_textdomains );
		$this->assertArrayNotHasKey( '', $loaded_textdomains );
		$this->assertCount( 0, $loaded_textdomains );
	}
}
