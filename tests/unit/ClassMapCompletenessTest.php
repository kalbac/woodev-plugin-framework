<?php
/**
 * Classmap completeness test.
 *
 * Asserts every framework class/interface/trait is reachable via the generated classmap.
 * Guards the production-WSOD-on-first-boot failure class (see gotcha
 * box-packer-interface-unwired-in-includes): a class missing from the map would resolve in
 * tests via Composer's classmap but fatal in a real vendored boot where no Composer exists.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

/**
 * @coversNothing
 */
final class ClassMapCompletenessTest extends TestCase {

	public function test_every_framework_symbol_is_in_the_classmap(): void {
		$root = dirname( __DIR__, 2 );
		$map  = require $root . '/woodev/class-map.php';

		$skip = [
			'woodev/bootstrap.php',
			'woodev/loader.php',
			'woodev/class-map.php',
			'woodev/class-framework-autoloader.php',
		];

		$missing  = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root . '/woodev', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );

			if ( in_array( $relative, $skip, true ) ) {
				continue;
			}

			foreach ( $this->extract_symbols( (string) file_get_contents( $file->getPathname() ) ) as $fqcn ) {
				if ( ! array_key_exists( $fqcn, $map ) ) {
					$missing[] = $fqcn . ' (' . $relative . ')';
				}
			}
		}

		$this->assertSame(
			[],
			$missing,
			"Classmap is stale — run `php bin/generate-class-map.php`. Missing:\n" . implode( "\n", $missing )
		);
	}

	/**
	 * Extracts fully-qualified class/interface/trait names from PHP source via tokens.
	 *
	 * @param string $source PHP source.
	 * @return string[]
	 */
	private function extract_symbols( string $source ): array {
		$tokens    = token_get_all( $source );
		$namespace = '';
		$symbols   = [];
		$count     = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( is_array( $token ) && T_NAMESPACE === $token[0] ) {
				$namespace = '';
				for ( $j = $i + 1; $j < $count; $j++ ) {
					$next = $tokens[ $j ];
					if ( ';' === $next || '{' === $next ) {
						break;
					}
					if ( is_array( $next ) && in_array( $next[0], [ T_STRING, T_NS_SEPARATOR ], true ) ) {
						$namespace .= $next[1];
					}
					if ( is_array( $next ) && defined( 'T_NAME_QUALIFIED' ) && T_NAME_QUALIFIED === $next[0] ) {
						$namespace .= $next[1];
					}
				}
				continue;
			}

			if ( is_array( $token ) && in_array( $token[0], [ T_CLASS, T_INTERFACE, T_TRAIT ], true ) ) {
				$prev = $tokens[ $i - 1 ] ?? null;
				if ( is_array( $prev ) && T_DOUBLE_COLON === $prev[0] ) {
					continue;
				}
				for ( $j = $i + 1; $j < $count; $j++ ) {
					$next = $tokens[ $j ];
					if ( is_array( $next ) && T_WHITESPACE === $next[0] ) {
						continue;
					}
					if ( is_array( $next ) && T_STRING === $next[0] ) {
						$symbols[] = '' !== $namespace ? $namespace . '\\' . $next[1] : $next[1];
					}
					break;
				}
			}
		}

		return $symbols;
	}
}
