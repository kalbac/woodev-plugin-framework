<?php
/**
 * Framework runtime autoloader tests.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

require_once dirname( __DIR__, 2 ) . '/woodev/class-framework-autoloader.php';

/**
 * @covers \Woodev_Framework_Autoloader
 */
final class FrameworkAutoloaderTest extends TestCase {

	/** @var string[] Temp dirs to clean up. */
	private array $temp_dirs = [];

	protected function tearDown(): void {
		\Woodev_Framework_Autoloader::reset();

		foreach ( $this->temp_dirs as $dir ) {
			$this->rrmdir( $dir );
		}
		$this->temp_dirs = [];

		parent::tearDown();
	}

	public function test_autoloads_a_mapped_class_from_the_base_path(): void {
		// Build a throwaway framework copy whose classmap maps a class NOT covered by
		// Composer's classmap, so this genuinely exercises our autoloader.
		$base  = $this->make_temp_framework_copy();
		$class = 'Woodev_Autoload_Probe_Mapped';

		file_put_contents( $base . '/woodev/probe-mapped.php', "<?php class {$class} {}\n" );
		file_put_contents(
			$base . '/woodev/class-map.php',
			"<?php return [ '{$class}' => 'woodev/probe-mapped.php' ];\n"
		);

		$this->assertFalse( class_exists( $class, false ), 'probe must not pre-exist' );

		\Woodev_Framework_Autoloader::register( $base );

		$this->assertTrue( class_exists( $class ), 'autoloader should load the mapped class' );
	}

	public function test_returns_silently_for_unmapped_class(): void {
		$base = $this->make_temp_framework_copy();
		file_put_contents( $base . '/woodev/class-map.php', "<?php return [];\n" );

		\Woodev_Framework_Autoloader::register( $base );

		// No warning, no error — just unresolved. failOnWarning would trip if it warned.
		$this->assertFalse( class_exists( 'Totally_Unknown_NonFramework_Class_Xyz', true ) );
	}

	public function test_register_is_idempotent(): void {
		$base = $this->make_temp_framework_copy();
		file_put_contents( $base . '/woodev/class-map.php', "<?php return [];\n" );

		\Woodev_Framework_Autoloader::register( $base );
		$count = count( spl_autoload_functions() );

		\Woodev_Framework_Autoloader::register( $base );

		$this->assertSame( $count, count( spl_autoload_functions() ) );
		$this->assertTrue( \Woodev_Framework_Autoloader::is_registered() );
	}

	public function test_register_is_noop_when_map_missing(): void {
		$base = $this->make_temp_framework_copy(); // no class-map.php written

		\Woodev_Framework_Autoloader::register( $base );

		$this->assertFalse( \Woodev_Framework_Autoloader::is_registered() );
	}

	public function test_real_classmap_maps_known_framework_classes_to_readable_files(): void {
		$root = dirname( __DIR__, 2 );
		$map  = require $root . '/woodev/class-map.php';

		$known = [
			'Woodev_Plugin'                                  => 'woodev/class-plugin.php',
			'Woodev_Payment_Gateway_Plugin'                  => 'woodev/payment-gateway/class-payment-gateway-plugin.php',
			'Woodev\\Framework\\Woocommerce_Plugin'          => 'woodev/class-woocommerce-plugin.php',
			'Woodev\\Framework\\Shipping\\Shipping_Plugin'   => 'woodev/shipping-method/class-shipping-plugin.php',
		];

		foreach ( $known as $fqcn => $expected_path ) {
			$this->assertArrayHasKey( $fqcn, $map, "classmap missing {$fqcn}" );
			$this->assertSame( $expected_path, $map[ $fqcn ] );
			$this->assertFileIsReadable( $root . '/' . $map[ $fqcn ] );
		}
	}

	/**
	 * Creates a temp dir containing an empty `woodev/` folder and tracks it for cleanup.
	 *
	 * @return string Absolute base path (the dir containing `woodev/`).
	 */
	private function make_temp_framework_copy(): string {
		$base = sys_get_temp_dir() . '/woodev-autoload-' . uniqid( '', true );
		mkdir( $base . '/woodev', 0777, true );
		$this->temp_dirs[] = $base;

		return $base;
	}

	/**
	 * Recursively removes a directory.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}

		rmdir( $dir );
	}
}
