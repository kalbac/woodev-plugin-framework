<?php
/**
 * Script translations helper tests (#1032).
 *
 * @package Woodev\Tests\Unit\Handlers
 */

namespace Woodev\Tests\Unit\Handlers;

use Woodev\Tests\Unit\TestCase;

use Brain\Monkey\Functions;
use Mockery;
use Woodev\Framework\Handlers\Script_Translations;

require_once dirname( __DIR__, 3 ) . '/woodev/handlers/class-script-translations.php';

/**
 * Class ScriptTranslationsTest.
 *
 * @covers \Woodev\Framework\Handlers\Script_Translations
 */
class ScriptTranslationsTest extends TestCase {

	/**
	 * Builds a plugin double that reports where the loaded framework copy lives.
	 *
	 * @return \Woodev_Plugin&\Mockery\MockInterface
	 */
	private function make_plugin() {
		$plugin = Mockery::mock( \Woodev_Plugin::class );
		$plugin->shouldReceive( 'get_framework_path' )->andReturn( '/srv/wp/plugins/acme/vendor/woodev/woodev' );

		return $plugin;
	}

	/**
	 * The handle, the FIXED framework domain and the loaded copy's own languages directory are
	 * what WordPress is told — a path derived from anywhere else would never find the JSON.
	 *
	 * @return void
	 */
	public function test_registers_the_handle_against_the_framework_languages_directory(): void {
		Functions\expect( 'wp_set_script_translations' )
			->once()
			->with( 'woodev-settings-page', 'woodev-plugin-framework', '/srv/wp/plugins/acme/vendor/woodev/woodev/languages' )
			->andReturn( true );

		$this->assertTrue( Script_Translations::register( $this->make_plugin(), 'woodev-settings-page' ) );
	}

	/**
	 * WordPress answers false for a handle it does not know; the helper reports it unchanged
	 * rather than pretending the bundle is translated.
	 *
	 * @return void
	 */
	public function test_reports_false_when_wordpress_rejects_the_handle(): void {
		Functions\when( 'wp_set_script_translations' )->justReturn( false );

		$this->assertFalse( Script_Translations::register( $this->make_plugin(), 'woodev-unknown' ) );
	}

	/**
	 * Every built bundle the framework enqueues must be wired. A bundle added later without the
	 * call would silently render its English msgids in a Russian admin again.
	 *
	 * @return void
	 */
	public function test_every_enqueued_build_bundle_is_registered_for_translations(): void {
		$root     = dirname( __DIR__, 3 ) . '/woodev';
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		$seen     = [];

		foreach ( $iterator as $file ) {
			if ( 'php' !== $file->getExtension() || str_contains( $file->getPathname(), '/assets/' ) ) {
				continue;
			}

			$source = file_get_contents( $file->getPathname() );

			if ( ! preg_match_all( "/wp_enqueue_script\(\s*'([^']+)',\s*\\\$build_url \. '\/index\.js'/", $source, $enqueued ) ) {
				continue;
			}

			foreach ( $enqueued[1] as $handle ) {
				$seen[] = $handle;

				$this->assertMatchesRegularExpression(
					"/Script_Translations::register\(\s*[^,]+,\s*'" . preg_quote( $handle, '/' ) . "'\s*\)/",
					$source,
					sprintf( 'bundle handle "%s" is enqueued in %s without Script_Translations::register()', $handle, $file->getFilename() )
				);
			}
		}

		sort( $seen );

		$this->assertSame(
			[ 'woodev-license-app', 'woodev-plugins-app', 'woodev-settings-page', 'woodev-setup-wizard', 'woodev-shipping-orders-page', 'woodev-ui-kit-gallery' ],
			$seen,
			'the scan must find all six built bundles, or it proves nothing'
		);
	}
}
