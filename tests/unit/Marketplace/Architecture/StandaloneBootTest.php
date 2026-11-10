<?php
/**
 * The marketplace layer loads from the framework's own autoloader, with no Composer, and from one copy when two are present.
 *
 * @package Woodev\Tests\Unit\Marketplace
 */

namespace Woodev\Tests\Unit\Marketplace\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A shipped plugin has no Composer: the framework's hand-written autoloader resolves classes from `woodev/class-map.php`, against the
 * first framework copy that registered. This test boots exactly that in a child PHP process (the autoloader's state is process-wide)
 * with two copies of the framework on disk, and loads every symbol of the domain folders through it.
 */
final class StandaloneBootTest extends TestCase {
	/**
	 * Domain folders of the layer.
	 *
	 * @var string[]
	 */
	private const FOLDERS = array( 'account', 'capabilities', 'catalog', 'diagnostics', 'governor', 'ledger', 'links', 'orders', 'price', 'stock' );

	/**
	 * Temporary directory of the test.
	 *
	 * @var string
	 */
	private $tmp = '';

	/**
	 * Makes a scratch directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->tmp = sys_get_temp_dir() . '/woodev-marketplace-boot-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->tmp, 0777, true );
	}

	/**
	 * Removes the scratch directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->remove( $this->tmp );
		parent::tearDown();
	}

	/**
	 * Deletes a directory tree.
	 *
	 * @param string $path Path.
	 * @return void
	 */
	private function remove( string $path ): void {
		if ( '' === $path || ! file_exists( $path ) ) {
			return;
		}

		if ( is_dir( $path ) ) {
			foreach ( array_diff( (array) scandir( $path ), array( '.', '..' ) ) as $entry ) {
				$this->remove( $path . '/' . $entry );
			}
			rmdir( $path );
			return;
		}

		unlink( $path );
	}

	/**
	 * Copies the part of the framework a boot needs into `$target/woodev`: the autoloader, the class map and the layer.
	 *
	 * @param string $target Directory that will contain `woodev/`.
	 * @return void
	 */
	private function install_copy( string $target ): void {
		$root = dirname( __DIR__, 4 ) . '/woodev';

		mkdir( $target . '/woodev', 0777, true );
		copy( $root . '/class-framework-autoloader.php', $target . '/woodev/class-framework-autoloader.php' );
		copy( $root . '/class-map.php', $target . '/woodev/class-map.php' );

		foreach ( self::FOLDERS as $folder ) {
			$this->copy_tree( $root . '/marketplace/' . $folder, $target . '/woodev/marketplace/' . $folder );
		}
	}

	/**
	 * Copies a directory tree.
	 *
	 * @param string $from Source.
	 * @param string $to   Destination.
	 * @return void
	 */
	private function copy_tree( string $from, string $to ): void {
		mkdir( $to, 0777, true );
		foreach ( array_diff( (array) scandir( $from ), array( '.', '..' ) ) as $entry ) {
			is_dir( $from . '/' . $entry ) ? $this->copy_tree( $from . '/' . $entry, $to . '/' . $entry ) : copy( $from . '/' . $entry, $to . '/' . $entry );
		}
	}

	/**
	 * Runs a PHP script in a child process with no Composer autoloader.
	 *
	 * @param string $script PHP source.
	 * @return array{0:int,1:string} Exit code and combined output.
	 */
	private function run_child( string $script ): array {
		$file = $this->tmp . '/probe.php';
		file_put_contents( $file, $script );

		$process = proc_open( array( PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1', $file ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );

		$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return array( proc_close( $process ), $output );
	}

	/**
	 * Every symbol of the domain folders loads through the framework autoloader alone, from the first copy that registered, and a
	 * second copy changes nothing: no Composer class exists in the process.
	 *
	 * @return void
	 */
	public function test_the_layer_boots_from_the_first_of_two_copies_with_no_composer(): void {
		$first  = $this->tmp . '/plugin-a';
		$second = $this->tmp . '/plugin-b';
		$this->install_copy( $first );
		$this->install_copy( $second );

		$script = <<<'PHP'
<?php
define( 'ABSPATH', '/' );
require $argv_first . '/woodev/class-framework-autoloader.php';
Woodev_Framework_Autoloader::register( $argv_first );
Woodev_Framework_Autoloader::register( $argv_second );

if ( class_exists( 'Composer\Autoload\ClassLoader', false ) ) {
	fwrite( STDOUT, "COMPOSER-PRESENT\n" );
	exit( 2 );
}

$map     = require $argv_first . '/woodev/class-map.php';
$loaded  = 0;
$strange = array();
foreach ( $map as $symbol => $relative ) {
	if ( 0 !== strpos( $symbol, 'Woodev\Framework\Marketplace\\' ) || 1 === preg_match( '#^woodev/marketplace/(?!(?:account|capabilities|catalog|diagnostics|governor|ledger|links|orders|price|stock)/)#', $relative ) ) {
		continue;
	}

	if ( ! class_exists( $symbol ) && ! interface_exists( $symbol ) && ! trait_exists( $symbol ) ) {
		$strange[] = 'NOT LOADED: ' . $symbol;
		continue;
	}

	$file = ( new ReflectionClass( $symbol ) )->getFileName();
	if ( 0 !== strpos( (string) $file, realpath( $argv_first ) . '/' ) ) {
		$strange[] = 'WRONG COPY: ' . $symbol . ' from ' . $file;
	}
	++$loaded;
}

echo $strange ? implode( "\n", $strange ) : 'OK ' . $loaded, "\n";
exit( $strange ? 1 : 0 );
PHP;
		$script = str_replace( array( '$argv_first', '$argv_second' ), array( var_export( $first, true ), var_export( $second, true ) ), $script );

		list( $code, $output ) = $this->run_child( $script );

		$this->assertSame( 0, $code, $output );
		$this->assertMatchesRegularExpression( '/^OK (\d+)$/m', $output );
		preg_match( '/^OK (\d+)$/m', $output, $match );
		$this->assertGreaterThan( 80, (int) $match[1], 'the probe loaded the whole layer' );
	}

	/**
	 * The probe can fail: with the layer missing from the copy, the load is reported rather than silently skipped.
	 *
	 * @return void
	 */
	public function test_the_probe_reports_a_symbol_that_cannot_be_loaded(): void {
		$copy = $this->tmp . '/plugin-a';
		$this->install_copy( $copy );
		unlink( $copy . '/woodev/marketplace/price/class-money.php' );

		$script = <<<'PHP'
<?php
define( 'ABSPATH', '/' );
require $argv_copy . '/woodev/class-framework-autoloader.php';
Woodev_Framework_Autoloader::register( $argv_copy );
echo class_exists( 'Woodev\Framework\Marketplace\Price\Money' ) ? 'LOADED' : 'MISSING', "\n";
PHP;
		list( , $output ) = $this->run_child( str_replace( '$argv_copy', var_export( $copy, true ), $script ) );

		$this->assertStringContainsString( 'MISSING', $output );
	}
}
