<?php
/**
 * The order and label flows name no marketplace.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Reads every PHP file of `woodev/marketplace/flows/` with the tokenizer and fails when code or a string names a
 * marketplace, or when a file depends on anything but the marketplace module. A comment may use a marketplace as an example;
 * nothing that runs may: every marketplace rule these flows need is an answer of the adapter.
 *
 * The `jobs/` folder has its own gate in {@see SyncNeutralityTest}.
 */
final class FlowsNeutralityTest extends TestCase {
	private const MARKETPLACES = '/ozon|wildberries|yandex|aliexpress|sbermegamarket/i';

	private const FOLDERS = [ 'flows' ];

	/**
	 * No identifier, constant or string names a marketplace.
	 *
	 * @return void
	 */
	public function test_no_marketplace_is_named_outside_comments(): void {
		$offences = [];

		foreach ( $this->files() as $file ) {
			foreach ( token_get_all( (string) file_get_contents( $file ) ) as $token ) {
				if ( ! is_array( $token ) || in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
					continue;
				}

				if ( 1 === preg_match( self::MARKETPLACES, $token[1] ) ) {
					$offences[] = basename( $file ) . ':' . $token[2] . ' ' . trim( $token[1] );
				}
			}
		}

		$this->assertSame( [], $offences );
	}

	/**
	 * No file imports a concrete marketplace, a plugin class or another module of the framework: the flows depend on the
	 * marketplace module's own contracts only.
	 *
	 * @return void
	 */
	public function test_no_file_imports_anything_but_the_marketplace_module(): void {
		foreach ( $this->files() as $file ) {
			preg_match_all( '/^use\s+([^;]+);/m', (string) file_get_contents( $file ), $matches );

			foreach ( $matches[1] as $import ) {
				$this->assertStringStartsWith( 'Woodev\\Framework\\Marketplace\\', trim( $import ), basename( $file ) . ' imports ' . $import );
			}
		}
	}

	/**
	 * No moved flow reaches into delivery: the shipping module is not loaded by a marketplace plugin.
	 *
	 * @return void
	 */
	public function test_no_file_names_the_shipping_module_in_code(): void {
		$offences = [];

		foreach ( $this->files() as $file ) {
			foreach ( token_get_all( (string) file_get_contents( $file ) ) as $token ) {
				if ( is_array( $token ) && ! in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT ], true ) && false !== stripos( $token[1], 'Framework\\Shipping' ) ) {
					$offences[] = basename( $file ) . ':' . $token[2];
				}
			}
		}

		$this->assertSame( [], $offences );
	}

	/**
	 * Every file keeps the direct-access guard and declares the symbol its name promises to the autoloader.
	 *
	 * @return void
	 */
	public function test_every_file_has_the_abspath_guard_and_its_own_symbol(): void {
		foreach ( $this->files() as $file ) {
			$source = (string) file_get_contents( $file );
			$symbol = str_replace( ' ', '_', ucwords( str_replace( '-', ' ', (string) preg_replace( '/^(class|interface|trait)-|\.php$/', '', basename( $file ) ) ) ) );

			$this->assertStringContainsString( "defined( 'ABSPATH' ) || exit;", $source, basename( $file ) );
			$this->assertMatchesRegularExpression( '/^(?:final |abstract )?(?:class|interface|trait) ' . $symbol . '\b/m', $source, basename( $file ) . ' declares ' . $symbol );
		}
	}

	/**
	 * The files of the folder.
	 *
	 * @return string[]
	 */
	private function files(): array {
		$files = [];

		foreach ( self::FOLDERS as $folder ) {
			$found = glob( dirname( __DIR__, 4 ) . '/woodev/marketplace/' . $folder . '/*.php' );

			$this->assertNotEmpty( $found, $folder );

			$files = array_merge( $files, (array) $found );
		}

		return $files;
	}
}
