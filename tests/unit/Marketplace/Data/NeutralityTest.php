<?php
/**
 * The persistence layer names no marketplace.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Data;

use PHPUnit\Framework\TestCase;

/**
 * Reads every PHP file of `woodev/marketplace/data/` with the tokenizer and fails when code, a string or an SQL text names a
 * marketplace. A comment may use a marketplace as an example; nothing that runs may.
 */
final class NeutralityTest extends TestCase {
	private const MARKETPLACES = '/ozon|wildberries|yandex|aliexpress|sbermegamarket/i';

	/**
	 * No identifier, constant, string or SQL fragment names a marketplace.
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
	 * No file imports a concrete marketplace or a plugin class: the layer depends on the contracts only.
	 *
	 * @return void
	 */
	public function test_no_file_imports_a_concrete_marketplace(): void {
		foreach ( $this->files() as $file ) {
			preg_match_all( '/^use\s+([^;]+);/m', (string) file_get_contents( $file ), $matches );

			foreach ( $matches[1] as $import ) {
				$this->assertMatchesRegularExpression( '/^(Woodev\\\\Framework\\\\Marketplace\\\\|Woodev\\\\Framework\\\\Marketplace$)/', trim( $import ), basename( $file ) . ' imports ' . $import );
			}
		}
	}

	/**
	 * Every file keeps the direct-access guard.
	 *
	 * @return void
	 */
	public function test_every_file_has_the_abspath_guard(): void {
		foreach ( $this->files() as $file ) {
			$this->assertStringContainsString( "defined( 'ABSPATH' ) || exit;", (string) file_get_contents( $file ), basename( $file ) );
		}
	}

	/**
	 * The files of the persistence folder.
	 *
	 * @return string[]
	 */
	private function files(): array {
		$files = glob( dirname( __DIR__, 4 ) . '/woodev/marketplace/data/*.php' );

		$this->assertNotEmpty( $files );

		return (array) $files;
	}
}
