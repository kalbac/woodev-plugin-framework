<?php
/**
 * Framework translation loading and lifecycle ordering.
 *
 * @package Woodev\Tests\Unit\Handlers
 */

namespace Woodev\Tests\Unit\Handlers;

use Brain\Monkey\Functions;
use Woodev\Framework\Handlers\Framework_Translations;
use Woodev\Tests\Unit\TestCase;

require_once dirname( __DIR__, 3 ) . '/woodev/handlers/class-framework-translations.php';

/** Shared domain loader contracts. */
class FrameworkTranslationsTest extends TestCase {

	/** The same resolved locale is used in each filename and load call. */
	public function test_external_packs_precede_the_explicit_bundled_fallback(): void {
		defined( 'WP_LANG_DIR' ) || define( 'WP_LANG_DIR', '/srv/wp/languages' );
		Functions\expect( 'determine_locale' )->once()->andReturn( 'ru_RU' );
		Functions\expect( 'apply_filters' )->once()->with( 'plugin_locale', 'ru_RU', 'woodev-plugin-framework' )->andReturn( 'ru_RU' );
		$loaded = [];
		Functions\when( 'load_textdomain' )->alias(
			static function ( string $domain, string $file, string $locale ) use ( &$loaded ): bool {
				$loaded[] = [ $domain, $file, $locale ];
				return true;
			}
		);

		Framework_Translations::load_translations();

		$file = '/woodev-plugin-framework-ru_RU.mo';
		$this->assertSame( [
			[ 'woodev-plugin-framework', WP_LANG_DIR . '/woodev-plugin-framework' . $file, 'ru_RU' ],
			[ 'woodev-plugin-framework', WP_LANG_DIR . '/plugins' . $file, 'ru_RU' ],
			[ 'woodev-plugin-framework', dirname( __DIR__, 3 ) . '/woodev/languages' . $file, 'ru_RU' ],
		], $loaded );
	}

	/** Hook priority zero is distinct from an absent hook; registration is idempotent. */
	public function test_registration_runs_once_before_lifecycle_notices(): void {
		$registered = false;
		Functions\when( 'has_action' )->alias( static function () use ( &$registered ) { return $registered ? 0 : false; } );
		Functions\expect( 'add_action' )->once()->with( 'init', [ Framework_Translations::class, 'load_translations' ], 0 )->andReturnUsing(
			static function () use ( &$registered ): void { $registered = true; }
		);
		Functions\expect( 'add_action' )->once()->with( 'change_locale', [ Framework_Translations::class, 'reload_translations' ], 0 );

		Framework_Translations::register();
		Framework_Translations::register();
	}

	/** Switching before init must not introduce premature translation loading. */
	public function test_early_locale_switch_does_not_load_translations(): void {
		Functions\when( 'did_action' )->justReturn( 0 );
		Functions\expect( 'unload_textdomain' )->never();
		Functions\expect( 'load_textdomain' )->never();
		Framework_Translations::reload_translations();
		$this->addToAssertionCount( 1 );
	}
	/** Actual lifecycle hooks may register first; init still loads the domain before building notices. */
	public function test_framework_domain_is_loaded_before_lifecycle_builds_a_notice(): void {
		defined( 'WP_LANG_DIR' ) || define( 'WP_LANG_DIR', '/srv/wp/languages' );
		$hooks = [];
		Functions\when( 'add_action' )->alias( static function ( string $hook, $callback, int $priority = 10 ) use ( &$hooks ): void {
			$hooks[ $hook ][ $priority ][] = $callback;
		} );
		Functions\when( 'has_action' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'get_option' )->alias( static function ( string $key, $default = false ) {
			return 'woodev_order-test_milestone_messages' === $key ? [ 'first' => 'completed a task' ] : $default;
		} );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'determine_locale' )->justReturn( 'ru_RU' );
		$loaded = false;
		Functions\when( 'load_textdomain' )->alias( static function () use ( &$loaded ): bool { $loaded = true; return true; } );
		Functions\when( '__' )->alias( static function ( string $message ) use ( &$loaded ): string {
			return $loaded ? 'translated ' . $message : $message;
		} );
		$plugin = \Mockery::mock( \Woodev_Plugin::class );
		$plugin->shouldReceive( 'get_plugin_file' )->andReturn( 'order-test.php' );
		$plugin->shouldReceive( 'get_id' )->andReturn( 'order-test' );
		$plugin->shouldReceive( 'get_reviews_url', 'get_support_url' )->andReturn( '' );
		$notices = \Mockery::mock();
		$notices->shouldReceive( 'should_display_notice' )->andReturn( true );
		$notices->shouldReceive( 'add_admin_notice' )->once()->withArgs( static function ( string $message ): bool {
			return 0 === strpos( $message, 'translated ' );
		} );
		$plugin->shouldReceive( 'get_admin_notice_handler' )->andReturn( $notices );
		new \Woodev_Lifecycle( $plugin );
		Framework_Translations::register();
		ksort( $hooks['init'] );
		foreach ( $hooks['init'] as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$callback();
			}
		}
		$this->assertTrue( $loaded );
	}

}
