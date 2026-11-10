<?php
/**
 * Dependency rule of the marketplace layer.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Architecture;

use PHPUnit\Framework\TestCase;
use Woodev\Tests\Unit\Marketplace\Support\Layer_Scanner;

/**
 * The domain folders of `woodev/marketplace/` may depend on nothing that is marketplace-specific, plugin-specific or WordPress
 * (ADR-013, «Domain layer placement»). This is the file scan that rule asks for: it reads every file of those folders with the
 * tokenizer. The infrastructure folders that arrive in later cards (`data/`, `jobs/`, `admin/`, ...) sit outside it.
 */
final class LayerDependencyTest extends TestCase {
	/**
	 * Domain folders of the layer.
	 *
	 * @var string[]
	 */
	private const FOLDERS = array( 'account', 'governor', 'ledger', 'links', 'stock', 'price', 'orders', 'catalog', 'capabilities', 'diagnostics' );

	/**
	 * Returns the repository root.
	 *
	 * @return string
	 */
	private static function root(): string {
		return dirname( __DIR__, 4 );
	}

	/**
	 * Returns every PHP file of the layer.
	 *
	 * @return string[]
	 */
	private function layer_files(): array {
		$files = array();
		foreach ( self::FOLDERS as $folder ) {
			$root = self::root() . '/woodev/marketplace/' . $folder;
			if ( ! is_dir( $root ) ) {
				continue;
			}

			foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
				if ( 'php' === $file->getExtension() ) {
					$files[] = $file->getPathname();
				}
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * The scan has something to scan: every folder of the map exists and holds files.
	 *
	 * @return void
	 */
	public function test_the_layer_has_every_folder_of_the_component_map(): void {
		$files = $this->layer_files();
		$this->assertGreaterThan( 80, count( $files ) );

		foreach ( self::FOLDERS as $folder ) {
			$inside = array_filter(
				$files,
				static function ( string $path ) use ( $folder ): bool {
					return false !== strpos( $path, '/woodev/marketplace/' . $folder . '/' );
				}
			);
			$this->assertNotEmpty( $inside, 'woodev/marketplace/' . $folder . ' is missing or empty.' );
		}
	}

	/**
	 * The rule itself: no file of the layer names a concrete marketplace, WordPress or the plugin.
	 *
	 * @return void
	 */
	public function test_no_file_of_the_layer_depends_on_ozon_wordpress_or_the_plugin(): void {
		$found = array();
		foreach ( $this->layer_files() as $path ) {
			foreach ( Layer_Scanner::violations( (string) file_get_contents( $path ) ) as $violation ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test read.
				$found[] = substr( $path, strlen( self::root() ) + 1 ) . ': ' . $violation;
			}
		}

		$this->assertSame( array(), $found );
	}

	/**
	 * Each file declares the symbol its path says, in the layer's namespace, so the framework's runtime autoloader (the class map,
	 * with no Composer in a vendored boot) finds it.
	 *
	 * @return void
	 */
	public function test_every_file_declares_the_symbol_the_autoloader_expects(): void {
		$map = require self::root() . '/woodev/class-map.php';

		foreach ( $this->layer_files() as $path ) {
			$symbol = Layer_Scanner::declared_symbol( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test read.
			$this->assertNotNull( $symbol, $path . ' declares no class, interface or trait.' );
			$this->assertArrayHasKey( ltrim( (string) $symbol, '\\' ), $map, $symbol . ' is not in woodev/class-map.php.' );
			$this->assertSame( realpath( $path ), realpath( self::root() . '/' . $map[ ltrim( (string) $symbol, '\\' ) ] ), $path . ' is not where the class map looks for ' . $symbol );
		}
	}

	/**
	 * The scanner would notice a violation: a passing scan is not a blind one.
	 *
	 * @dataProvider violating_snippets
	 * @param string $code Code that breaks the rule.
	 * @return void
	 */
	public function test_the_scanner_catches_violations( string $code ): void {
		$this->assertNotEmpty( Layer_Scanner::violations( '<?php ' . $code ), $code );
	}

	/**
	 * Code that breaks the rule.
	 *
	 * @return array<string,string[]>
	 */
	public function violating_snippets(): array {
		return array(
			'Ozon plugin import'       => array( 'use Woodev\Ozon_Seller\Plugin;' ),
			'Ozon class'               => array( 'class Ozon_Client {}' ),
			'Ozon string'              => array( '$table = "woodev_ozon_accounts";' ),
			'Ozon qualified call'      => array( '\Woodev\Ozon_Seller\Autoloader::register();' ),
			'framework import'         => array( 'use Woodev\Framework\Plugin;' ),
			'other framework module'   => array( 'use Woodev\Framework\Shipping\Shipping_Plugin;' ),
			'framework new'            => array( '$x = new \Woodev_Plugin();' ),
			'WordPress function'       => array( '$x = get_option( "a" );' ),
			'qualified WordPress call' => array( '$x = \wp_json_encode( array() );' ),
			'WordPress class'          => array( '$x = new \WP_Error();' ),
			'WordPress constant'       => array( '$x = MINUTE_IN_SECONDS;' ),
			'foreign namespace'        => array( 'namespace Woodev\Ozon_Seller;' ),
		);
	}

	/**
	 * What the layer may use is not flagged, and a comment that mentions Ozon is not a dependency.
	 *
	 * @return void
	 */
	public function test_the_scanner_allows_the_permitted_dependencies(): void {
		$code = <<<'PHP'
<?php
namespace Woodev\Framework\Marketplace\Orders;

use Woodev\Framework\Marketplace\Price\Money;
use DateTimeImmutable;

/**
 * Ozon posting_number is the external id here.
 */
final class Sample {
	// Ozon and Wildberries both send this.
	public function run( \WC_Order $order, \WC_Product $product, \wpdb $db, Money $money ): int {
		defined( 'ABSPATH' ) || exit;
		$when = new \DateTimeImmutable();
		return strlen( 'a' ) + PHP_INT_MAX + count( array() ) + self::LIMIT;
	}
}
PHP;
		$this->assertSame( array(), Layer_Scanner::violations( $code ) );
	}
}
