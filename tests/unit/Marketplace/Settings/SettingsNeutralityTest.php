<?php
/**
 * Neutrality and text-domain audit for F10 settings, guard, notices, diagnostics and shared mapper.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Settings;

use PHPUnit\Framework\TestCase;

final class SettingsNeutralityTest extends TestCase {
	private const DIRECTORIES = [
		'woodev/marketplace/settings',
		'woodev/marketplace/admin/guard',
		'woodev/marketplace/admin/notices',
		'woodev/marketplace/admin/diagnostics',
		'woodev/marketplace/admin/shared',
	];

	public function test_no_marketplace_specific_ozon_literal_exists_outside_comments(): void {
		$root  = dirname( __DIR__, 4 );
		$files = $this->php_files( $root );

		$this->assertNotEmpty( $files );

		foreach ( $files as $file ) {
			$tokens = token_get_all( (string) file_get_contents( $file ) );
			foreach ( $tokens as $token ) {
				if ( ! is_array( $token ) ) {
					continue;
				}
				list( $id, $text, $line ) = $token;
				if ( in_array( $id, [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
					continue;
				}

				$this->assertStringNotContainsStringIgnoringCase(
					'ozon',
					$text,
					sprintf( 'Marketplace-specific literal found in %s:%d (%s)', str_replace( $root . '/', '', $file ), $line, $text )
				);
			}
		}
	}

	/**
	 * @param string $root Repo root.
	 * @return string[]
	 */
	private function php_files( string $root ): array {
		$found = [];

		foreach ( self::DIRECTORIES as $rel ) {
			$dir = $root . '/' . $rel;
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir ) );
			foreach ( $iterator as $entry ) {
				if ( $entry instanceof \SplFileInfo && $entry->isFile() && 'php' === $entry->getExtension() ) {
					$found[] = $entry->getPathname();
				}
			}
		}

		sort( $found );

		return $found;
	}
}
