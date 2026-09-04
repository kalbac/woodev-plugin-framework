<?php
/**
 * A plugin entry file's loader definition must be buildable with NO framework class loaded.
 *
 * @package Woodev\Framework\Tests\Unit
 */

declare( strict_types=1 );

namespace Woodev\Framework\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Pins the entry-path contract that cost the first real v2 migration a fatal (#763).
 *
 * `Woodev_Loader::register()` is what requires `woodev/bootstrap.php`, and the bootstrap is what
 * registers the autoloader. PHP evaluates the argument array BEFORE the call, so at that moment no
 * framework class exists — not even by autoload. A definition that names one is a hard fatal in a
 * real plugin.
 *
 * This cannot be caught by an ordinary runtime test: under PHPUnit, Composer has already loaded the
 * whole framework, so the constant resolves and any such test is green regardless of the defect.
 * That is exactly why the gap survived until a live migration hit it. The check therefore reads the
 * fixture SOURCE instead of executing it.
 *
 * @since 2.0.2
 */
final class LoaderDefinitionLiteralsTest extends TestCase {

	/**
	 * Plugin entry files: the top-level PHP file of each fixture plugin.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function entry_file_provider(): array {
		$cases = [];

		foreach ( glob( dirname( __DIR__ ) . '/_fixtures/*/*.php' ) ?: [] as $file ) {
			$cases[ basename( $file ) ] = [ $file ];
		}

		return $cases;
	}

	/**
	 * A loader definition may only contain literals — never a framework class reference.
	 *
	 * @dataProvider entry_file_provider
	 *
	 * @param string $file the fixture entry file to inspect
	 */
	public function test_loader_definition_names_no_framework_class( string $file ): void {
		$source = (string) file_get_contents( $file );

		if ( ! str_contains( $source, "'platform'" ) ) {
			$this->assertTrue( true, 'Not a loader definition; nothing to pin.' );

			return;
		}

		$this->assertDoesNotMatchRegularExpression(
			'/\'platform\'\s*=>\s*[^,\r\n]*(?:Framework_Plugin_Loader_Definition|::)/u',
			$source,
			basename( $file ) . ': the loader definition names a framework class. PHP builds this '
				. 'array before Woodev_Loader::register() requires the bootstrap, so no framework '
				. 'class is loaded yet and this is a fatal in a real plugin. Use the literal '
				. "'woocommerce' / 'wordpress' / 'edd' instead."
		);
	}

	/**
	 * The literals the definition may use are exactly the platform constants' values.
	 *
	 * Guards the other direction: if a constant's value is ever changed, the literals every entry
	 * file now hard-codes would silently stop matching, and this test says so.
	 */
	public function test_platform_literals_match_the_constants(): void {
		$this->assertSame(
			'wordpress',
			\Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WORDPRESS,
			'Entry files hard-code this literal because they cannot reference the constant.'
		);
		$this->assertSame(
			'woocommerce',
			\Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WOOCOMMERCE,
			'Entry files hard-code this literal because they cannot reference the constant.'
		);
		$this->assertSame(
			'edd',
			\Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_EDD,
			'Entry files hard-code this literal because they cannot reference the constant.'
		);
	}
}
