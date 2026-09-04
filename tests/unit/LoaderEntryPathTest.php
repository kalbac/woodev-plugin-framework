<?php
/**
 * The prescribed v2 entry path, exercised end to end from a plugin file (#763).
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

require_once dirname( __DIR__, 2 ) . '/woodev/loader.php';

/**
 * Travels the path Rule 3 prescribes: a plugin ENTRY FILE requires `loader.php` and calls
 * `Woodev_Loader::register()` at module level.
 *
 * Until s115 nothing did this. Every fixture required `bootstrap.php` directly, so the framework
 * was already loaded before their definition was evaluated, and `LoaderFacadeTest` calls
 * `register()` with an inline array rather than from a file. The gap is what let the entry-path
 * fatal of #763 reach a live plugin.
 *
 * ⚠ What this test can and cannot prove. It cannot reproduce the fatal itself: under PHPUnit,
 * Composer has already loaded the framework, so a constant in the definition would resolve here.
 * The FORM is pinned by {@see LoaderDefinitionLiteralsTest}, which reads the source. What this
 * test adds is the other half — that the file-level shape actually registers, so the entry path
 * has a consumer inside the repository and not only in production.
 *
 * @covers \Woodev_Loader
 *
 * @since 2.0.2
 */
final class LoaderEntryPathTest extends TestCase {

	private const FIXTURE = '/tests/_fixtures/woodev-entry-path-fixture/woodev-entry-path-fixture.php';

	protected function setUp(): void {
		parent::setUp();
		$this->reset_bootstrap_singleton();

		if ( ! defined( 'WOODEV_FRAMEWORK_DIR' ) ) {
			define( 'WOODEV_FRAMEWORK_DIR', dirname( __DIR__, 2 ) );
		}
	}

	protected function tearDown(): void {
		$this->reset_bootstrap_singleton();
		parent::tearDown();
	}

	/**
	 * Loading the entry file registers the plugin — no test-side call to register().
	 */
	public function test_requiring_a_plugin_entry_file_registers_it(): void {
		$fixture = dirname( __DIR__, 2 ) . self::FIXTURE;

		$this->assertFileExists( $fixture, 'the entry-path fixture must exist' );

		// `include`, not `include_once`: each test gets a fresh registration against the
		// bootstrap singleton this class resets in setUp().
		include $fixture;

		$ids = array_map(
			static function ( array $plugin ): string {
				return $plugin['definition']->get_plugin_id();
			},
			$this->get_registered_plugins()
		);

		$this->assertContains(
			'woodev-entry-path-fixture',
			$ids,
			'requiring the entry file should have registered the plugin through Woodev_Loader'
		);
	}

	/**
	 * The fixture's definition survives as the resolver normalized it, plugin_file injected.
	 */
	public function test_the_entry_file_definition_arrives_intact(): void {
		$fixture = dirname( __DIR__, 2 ) . self::FIXTURE;

		include $fixture;

		$found = null;

		foreach ( $this->get_registered_plugins() as $plugin ) {
			if ( 'woodev-entry-path-fixture' === $plugin['definition']->get_plugin_id() ) {
				$found = $plugin['definition'];
				break;
			}
		}

		$this->assertNotNull( $found, 'the fixture should be registered' );
		$this->assertSame( 'wordpress', $found->get_platform(), 'platform literal should arrive as given' );
		$this->assertSame( '2.0.2', $found->get_framework_version() );
		$this->assertSame(
			realpath( $fixture ),
			realpath( $found->get_plugin_file() ),
			'register() must inject the entry file it was handed'
		);
	}

	/**
	 * Resets the bootstrap singleton via reflection to isolate tests.
	 *
	 * Mirrors `LoaderFacadeTest`; the helper is private there, and this file deliberately does not
	 * widen that class's API just to share it.
	 */
	private function reset_bootstrap_singleton(): void {
		$reflection = new \ReflectionClass( \Woodev_Plugin_Bootstrap::class );
		$instance   = $reflection->getProperty( 'instance' );

		if ( PHP_VERSION_ID < 80100 ) {
			$instance->setAccessible( true );
		}

		$instance->setValue( null, null );
	}

	/**
	 * Reads the protected registered_plugins array from the live bootstrap.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_registered_plugins(): array {
		$bootstrap  = \Woodev_Plugin_Bootstrap::instance();
		$reflection = new \ReflectionClass( $bootstrap );
		$property   = $reflection->getProperty( 'registered_plugins' );

		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}

		return (array) $property->getValue( $bootstrap );
	}
}
