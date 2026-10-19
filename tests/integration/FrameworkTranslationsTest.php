<?php
/**
 * Real WordPress catalogue merging, locale switching and startup-failure notices.
 *
 * @package Woodev\Tests\Integration
 */

namespace Woodev\Tests\Integration;

use Woodev\Framework\Handlers\Framework_Translations;
use Woodev\Framework\Framework_Resolver;

/** Core l10n semantics cannot be proven by Brain Monkey doubles. */
class FrameworkTranslationsTest extends TestCase {

	/** @var string[] Temporary external packs. */
	private array $packs = [];

	/** @var callable|null File remapping filter. */
	private $file_filter;

	protected function tearDown(): void {
		if ( $this->file_filter ) {
			remove_filter( 'load_textdomain_mofile', $this->file_filter );
		}
		foreach ( $this->packs as $pack ) {
			unlink( $pack );
		}
		unload_textdomain( 'woodev-plugin-framework' );
		parent::tearDown();
	}

	/** @return array<string,array{0:string}> */
	public function external_locations(): array {
		return [ 'custom directory' => [ 'woodev-plugin-framework' ], 'standard directory' => [ 'plugins' ] ];
	}

	/**
	 * An old override keeps its entries, and the explicit bundle fills every missing string.
	 *
	 * @dataProvider external_locations
	 * @param string $directory External pack directory under WP_LANG_DIR.
	 */
	public function test_stale_external_pack_keeps_overrides_and_gets_bundled_fallback( string $directory ): void {
		$this->remap_external_pack( $directory );
		add_filter( 'pre_determine_locale', static function (): string { return 'ru_RU'; } );
		Framework_Translations::reload_translations();

		$this->assertSame( 'Внешнее поздравление', __( 'Wonderful', 'woodev-plugin-framework' ) );
		$this->assertSame( 'Восхитительно', __( 'Amazing', 'woodev-plugin-framework' ) );
	}

	/** Locale switching and restoration must merge overrides again, rather than JIT-loading one file. */
	public function test_switch_to_locale_and_restore_preserve_external_precedence_and_fallback(): void {
		global $wp_locale_switcher;
		$this->remap_external_pack( 'woodev-plugin-framework' );
		$original_switcher = $wp_locale_switcher;
		$locale_filter = static function (): string { return 'en_US'; };
		add_filter( 'locale', $locale_filter, 0 );
		add_filter( 'determine_locale', $locale_filter, 0 );
		add_filter( 'get_available_languages', static function ( array $languages ): array { return array_merge( $languages, [ 'ru_RU' ] ); } );
		$wp_locale_switcher = new \WP_Locale_Switcher();
		$wp_locale_switcher->init();
		Framework_Translations::reload_translations();

		try {
			$this->assertTrue( switch_to_locale( 'ru_RU' ) );
			$this->assertSame( 'Внешнее поздравление', __( 'Wonderful', 'woodev-plugin-framework' ) );
			$this->assertSame( 'Восхитительно', __( 'Amazing', 'woodev-plugin-framework' ) );
			$this->assertTrue( switch_to_locale( 'en_US' ) );
			$this->assertSame( 'Wonderful', __( 'Wonderful', 'woodev-plugin-framework' ) );
			$this->assertSame( 'ru_RU', restore_previous_locale() );
			$this->assertSame( 'Внешнее поздравление', __( 'Wonderful', 'woodev-plugin-framework' ) );
			$this->assertSame( 'Восхитительно', __( 'Amazing', 'woodev-plugin-framework' ) );
		} finally {
			restore_current_locale();
			remove_filter( 'locale', [ $wp_locale_switcher, 'filter_locale' ] );
			remove_filter( 'determine_locale', [ $wp_locale_switcher, 'filter_locale' ] );
			$wp_locale_switcher = $original_switcher;
			remove_filter( 'locale', $locale_filter, 0 );
			remove_filter( 'determine_locale', $locale_filter, 0 );
		}
	}

	/** Refused plugins never construct Translation_Handler, but their resolver notice is translated. */
	public function test_rejected_plugin_still_registers_loader_and_renders_russian_notice(): void {
		remove_action( 'init', [ Framework_Translations::class, 'load_translations' ], 0 );
		remove_action( 'change_locale', [ Framework_Translations::class, 'reload_translations' ], 0 );
		unload_textdomain( 'woodev-plugin-framework' );
		$resolver = new class() extends Framework_Resolver {
			public function get_plugin_path( string $file ): string { return dirname( __DIR__, 2 ); }
		};
		$this->assertTrue( $resolver->register_loader_definition( [
			'plugin_id' => 'translation-refused',
			'download_id' => 99999,
			'plugin_name' => 'Refused plugin',
			'plugin_version' => '1.0.0',
			'framework_version' => '2.0.5',
			'plugin_file' => __FILE__,
			'platform' => 'wordpress',
			'requirements' => [ 'php' => '99.0', 'wordpress' => '6.3' ],
			'callback' => static function (): void { throw new \RuntimeException( 'Rejected plugin started.' ); },
		] ) );
		$resolver->load_plugins();
		$this->assertSame( [], $resolver->get_active_plugins() );
		$this->assertSame( 0, has_action( 'init', [ Framework_Translations::class, 'load_translations' ] ) );
		add_filter( 'pre_determine_locale', static function (): string { return 'ru_RU'; } );
		Framework_Translations::load_translations();
		ob_start();
		$resolver->render_update_notices();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Следующий плагин отключён, так как требует более новую версию PHP:', $html );
		$this->assertStringNotContainsString( 'The following plugin is inactive', $html );
	}

	/** Remaps a real external .mo path to a temporary pack without touching rig language files. */
	private function remap_external_pack( string $directory ): void {
		$mo = new \MO();
		$mo->set_header( 'Language', 'ru_RU' );
		$mo->add_entry( new \Translation_Entry( [ 'singular' => 'Wonderful', 'translations' => [ 'Внешнее поздравление' ] ] ) );
		$temporary = tempnam( sys_get_temp_dir(), 'woodev-i18n-' );
		$pack = $temporary . '.mo';
		rename( $temporary, $pack );
		$this->packs[] = $pack;
		$this->assertTrue( $mo->export_to_file( $pack ) );
		$external = WP_LANG_DIR . '/' . $directory . '/woodev-plugin-framework-ru_RU.mo';
		$this->file_filter = static function ( string $file, string $domain ) use ( $external, $pack ): string {
			return 'woodev-plugin-framework' === $domain && $external === $file ? $pack : $file;
		};
		add_filter( 'load_textdomain_mofile', $this->file_filter, 10, 2 );
	}
}
