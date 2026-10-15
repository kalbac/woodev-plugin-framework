<?php
/**
 * Contract: every hand-written css/js ships a `.min` sibling, nothing minified is orphaned, and the
 * enqueue code selects the sibling through `Woodev_Plugin::get_assets_suffix()` (card #247).
 *
 * The siblings are produced by `npm run build:min` and their FRESHNESS is the CI `assets` job's
 * concern (it rebuilds and fails on a diff). This test covers what that job cannot see from the
 * file tree alone: that the PHP side actually asks for them.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Asset-tree contract tests.
 */
class MinifiedAssetsContractTest extends TestCase {

	/** Every `assets/` root whose hand-written css/js reaches a browser — mirrors scripts/build-min-assets.mjs. */
	private const ASSET_ROOTS = [
		'woodev/assets/js',
		'woodev/assets/css',
		'woodev/payment-gateway/assets/js',
		'woodev/payment-gateway/assets/css',
		'woodev/shipping-method/assets/js',
		'woodev/shipping-method/assets/css',
	];

	/** Third-party `.min` files shipped as they are; the only `.min` names PHP may spell literally. */
	private const VENDORED = [
		'woodev/assets/css/admin/font-awesome.min.css',
		'woodev/assets/css/admin/jquery-confirm.min.css',
		'woodev/assets/js/admin/jquery.jquery-confirm.min.js',
		'woodev/shipping-method/assets/js/vendor/imask.min.js',
	];

	/** Directories whose files are not hand-written sources. */
	private const SKIPPED_DIRECTORIES = [ 'vendor', 'build', 'dist', 'fonts', 'images' ];

	/**
	 * @return string
	 */
	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Every css/js file under the asset roots, relative to the repository root.
	 *
	 * @return string[]
	 */
	private static function asset_files(): array {
		$files = [];

		foreach ( self::ASSET_ROOTS as $root ) {
			$base = self::root() . '/' . $root;

			if ( ! is_dir( $base ) ) {
				continue;
			}

			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveCallbackFilterIterator(
					new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ),
					static function ( \SplFileInfo $entry ): bool {
						return ! ( $entry->isDir() && in_array( $entry->getFilename(), self::SKIPPED_DIRECTORIES, true ) );
					}
				)
			);

			foreach ( $iterator as $entry ) {
				if ( $entry->isFile() && 1 === preg_match( '/\.(js|css)$/', $entry->getFilename() ) ) {
					$files[] = ltrim( str_replace( self::root(), '', str_replace( '\\', '/', $entry->getPathname() ) ), '/' );
				}
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * @param string $file Relative asset path.
	 * @return bool
	 */
	private static function is_minified( string $file ): bool {
		return 1 === preg_match( '/\.min\.(js|css)$/', $file );
	}

	/**
	 * Hand-written (source) files — everything that is not a `.min` file.
	 *
	 * @return string[]
	 */
	private static function sources(): array {
		return array_values( array_filter( self::asset_files(), static fn( string $f ): bool => ! self::is_minified( $f ) ) );
	}

	public function test_the_asset_tree_is_not_empty(): void {
		$this->assertGreaterThan( 20, count( self::sources() ), 'the scan found no hand-written assets — a root moved?' );
	}

	public function test_every_hand_written_asset_has_a_min_sibling(): void {
		$missing = [];

		foreach ( self::sources() as $source ) {
			$min = (string) preg_replace( '/\.(js|css)$/', '.min.$1', $source );

			if ( ! is_file( self::root() . '/' . $min ) ) {
				$missing[] = $min;
			}
		}

		$this->assertSame( [], $missing, 'no .min sibling on disk — run `npm run build:min` and commit the result' );
	}

	public function test_every_min_file_has_a_source_or_is_vendored(): void {
		$orphans = [];

		foreach ( self::asset_files() as $file ) {
			if ( ! self::is_minified( $file ) || in_array( $file, self::VENDORED, true ) ) {
				continue;
			}

			$source = (string) preg_replace( '/\.min\.(js|css)$/', '.$1', $file );

			if ( ! is_file( self::root() . '/' . $source ) ) {
				$orphans[] = $file;
			}
		}

		$this->assertSame( [], $orphans, 'a .min file whose source is gone — delete it (`npm run build:min` does)' );
	}

	public function test_the_vendored_allowlist_still_exists(): void {
		foreach ( self::VENDORED as $file ) {
			$this->assertFileExists( self::root() . '/' . $file, 'stale VENDORED entry' );
		}
	}

	/**
	 * Code string literals of one framework PHP file (comments and docblocks are ignored).
	 *
	 * @param string $path Absolute path.
	 * @return string[]
	 */
	private static function string_literals( string $path ): array {
		$literals = [];

		foreach ( token_get_all( (string) file_get_contents( $path ) ) as $token ) {
			if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
				$literals[] = substr( $token[1], 1, -1 );
			}
		}

		return $literals;
	}

	/**
	 * Every PHP file of the framework.
	 *
	 * @return string[] Absolute paths.
	 */
	private static function php_files(): array {
		$files    = [];
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::root() . '/woodev', \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $entry ) {
			$path = str_replace( '\\', '/', $entry->getPathname() );

			if ( $entry->isFile() && 'php' === $entry->getExtension() && false === strpos( $path, '/vendor/' ) && false === strpos( $path, '/assets/build/' ) ) {
				$files[] = $path;
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * A file that names a hand-written asset as a path must pick the sibling through the helper.
	 *
	 * Heuristic by design: it finds string literals that contain `<dir>/<stem>` of a source file
	 * (`js/frontend/location-cascade`, `/css/admin/woodev-plugins-table`, …) and requires the same
	 * file to call `get_assets_suffix()`. A new enqueue that spells a source path in a file that
	 * never asks for the suffix fails here.
	 */
	public function test_files_that_enqueue_a_hand_written_asset_use_the_suffix_helper(): void {
		$needles = [];

		foreach ( self::sources() as $source ) {
			// `js/frontend/location-cascade` — the last two path segments minus the extension.
			$needles[ $source ] = (string) preg_replace( '/\.(js|css)$/', '', implode( '/', array_slice( explode( '/', $source ), -3 ) ) );
		}

		$checked = 0;
		$failing = [];

		foreach ( self::php_files() as $php ) {
			$uses_helper = false !== strpos( (string) file_get_contents( $php ), 'get_assets_suffix' );
			$literals    = self::string_literals( $php );
			$names       = [];

			foreach ( $needles as $source => $needle ) {
				// Handlers pass `js/frontend/x.js`; plugin classes build `'/js/frontend/x' . suffix . '.js'`.
				foreach ( $literals as $literal ) {
					if ( 1 === preg_match( '#(^|/)' . preg_quote( $needle, '#' ) . '(\.(js|css))?$#', $literal ) ) {
						$names[] = $source;
						break;
					}
				}
			}

			if ( [] === $names ) {
				continue;
			}

			++$checked;

			if ( ! $uses_helper ) {
				$failing[ str_replace( self::root() . '/', '', $php ) ] = $names;
			}
		}

		$this->assertGreaterThanOrEqual( 8, $checked, 'the scan matched too few enqueue sites — has the naming scheme changed?' );
		$this->assertSame( [], $failing, 'enqueues a hand-written asset without calling Woodev_Plugin::get_assets_suffix()' );
	}

	/**
	 * `.min` is spelled in exactly one place (the helper). A literal `.min.js` / `.min.css` in PHP is
	 * only legitimate for a vendored third-party file.
	 */
	public function test_no_php_file_spells_a_min_name_except_for_vendored_files(): void {
		// WooCommerce's own tipTip, loaded from WC's plugin directory (not ours to minify).
		$vendored_names = array_merge( array_map( 'basename', self::VENDORED ), [ 'jquery.tipTip.min.js' ] );
		$offenders      = [];

		foreach ( self::php_files() as $php ) {
			foreach ( self::string_literals( $php ) as $literal ) {
				// Path-or-file-name literals only: a regex such as '/\.min\.(js|css)$/' is not a name.
				if ( 1 !== preg_match( '#^[A-Za-z0-9_./-]+$#', $literal ) || 1 !== preg_match( '/\.min(\.(js|css))?$/', $literal ) ) {
					continue;
				}

				if ( in_array( basename( $literal ), $vendored_names, true ) ) {
					continue;
				}

				$offenders[] = str_replace( self::root() . '/', '', $php ) . ' → ' . $literal;
			}
		}

		// Only the helper itself returns the bare '.min' literal.
		$offenders = array_values( array_filter( $offenders, static fn( string $o ): bool => ! preg_match( '#^woodev/class-plugin\.php → \.min$#', $o ) ) );

		$this->assertSame( [], $offenders, 'ad-hoc .min string — go through Woodev_Plugin::get_assets_suffix()' );
	}

	/**
	 * A complete asset path literal (`/dist/admin/x.js`, `js/vendor/imask.min.js`) must resolve to a file
	 * under one of the asset directories. This is what a typo'd directory (`/js/admin/` for `/dist/admin/`)
	 * looks like: the enqueue compiles, the browser 404s.
	 */
	public function test_complete_asset_path_literals_resolve_to_a_file(): void {
		$roots   = [ 'woodev/assets', 'woodev/payment-gateway/assets', 'woodev/shipping-method/assets' ];
		$missing = [];
		$checked = 0;

		foreach ( self::php_files() as $php ) {
			foreach ( self::string_literals( $php ) as $literal ) {
				if ( 1 !== preg_match( '#^/?(js|css|dist)/[A-Za-z0-9_./-]+\.(js|css)$#', $literal ) ) {
					continue;
				}

				++$checked;
				$relative = ltrim( $literal, '/' );
				$found    = false;

				foreach ( $roots as $root ) {
					if ( is_file( self::root() . '/' . $root . '/' . $relative ) ) {
						$found = true;
						break;
					}
				}

				if ( ! $found ) {
					$missing[] = str_replace( self::root() . '/', '', $php ) . ' → ' . $literal;
				}
			}
		}

		$this->assertGreaterThan( 0, $checked );
		$this->assertSame( [], $missing, 'an asset path literal points at a file that does not exist' );
	}
}
