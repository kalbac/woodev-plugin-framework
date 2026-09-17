<?php
/**
 * OB-1 dormant-notice tests for Woodev_Loader::register() (#104).
 *
 * Covers the missing direction of the B-1 mixed-fleet gate: when a LEGACY (v1)
 * Woodev_Plugin_Bootstrap copy wins the class rendezvous, Woodev_Loader::register()
 * must stay dormant (return false) AND report it via an admin_notices notice that
 * names the dormant plugin(s) and, best-effort, the conflicting legacy plugin.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use ReflectionClass;

/**
 * @covers \Woodev_Loader
 */
final class LoaderDormantNoticeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->reset_bootstrap_singleton();
		$this->reset_loader_dormant_state();

		if ( ! defined( 'WOODEV_FRAMEWORK_DIR' ) ) {
			define( 'WOODEV_FRAMEWORK_DIR', dirname( __DIR__, 2 ) );
		}

		require_once dirname( __DIR__, 2 ) . '/woodev/loader.php';
	}

	protected function tearDown(): void {
		$this->reset_bootstrap_singleton();
		$this->reset_loader_dormant_state();
		parent::tearDown();
	}

	/**
	 * (1) A legacy bootstrap without register_loader_definition() must leave register()
	 * returning false AND must register the OB-1 notice naming the dormant plugin.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_register_stays_dormant_and_names_the_plugin_when_legacy_bootstrap_wins(): void {
		// Legacy (v1) bootstrap stub — wins the class rendezvous, lacks register_loader_definition().
		// eval() defines a test-only stub class in this isolated process (no untrusted input); this
		// mirrors the established stub pattern in MixedFleetBootstrapGateTest.
		eval(
			'class Woodev_Plugin_Bootstrap {
				private static $instance;
				public static function instance() { return self::$instance ??= new self(); }
				public function register_plugin( $framework_version, $plugin_name, $path, $callback, $args = [] ) {}
			}'
		);

		if ( ! defined( 'WOODEV_FRAMEWORK_DIR' ) ) {
			define( 'WOODEV_FRAMEWORK_DIR', dirname( __DIR__, 2 ) );
		}

		require_once dirname( __DIR__, 2 ) . '/woodev/loader.php';

		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_kses' )->returnArg();

		$added = [];
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback ) use ( &$added ): void {
				$added[] = [ $hook, $callback ];
			}
		);

		// A synthetic, non-existent path: function_exists( 'get_plugin_data' ) is false in this
		// Brain Monkey environment, so resolution falls back to the definition's plugin_name
		// without ever touching the filesystem.
		$plugin_file = dirname( __DIR__, 2 ) . '/dormant-fixture-a/dormant-fixture-a.php';

		$result = \Woodev_Loader::register(
			$plugin_file,
			[
				'plugin_id'         => 'dormant-fixture-a',
				'plugin_name'       => 'Dormant Fixture Plugin',
				'plugin_version'    => '1.0.0',
				'framework_version' => '2.0.2',
				'platform'          => 'wordpress',
				'requirements'      => [
					'php'       => '7.4',
					'wordpress' => '6.3',
				],
				'main_class'        => 'Dormant_Fixture_A_Plugin',
			]
		);

		$this->assertFalse( $result, 'register() must stay dormant when the legacy bootstrap wins the rendezvous.' );

		$admin_notice_hooks = array_values(
			array_filter(
				$added,
				static function ( array $hook ): bool {
					return 'admin_notices' === $hook[0];
				}
			)
		);

		$this->assertCount( 1, $admin_notice_hooks, 'The dormant path must hook exactly one admin_notices notice.' );

		ob_start();
		$admin_notice_hooks[0][1]();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Dormant Fixture Plugin', $output, 'The notice must name the dormant plugin.' );
		$this->assertStringContainsString( '<div class="error">', $output, 'The notice must produce the admin-notice markup.' );
		$this->assertStringNotContainsStringIgnoringCase( 'фреймворк', $output, 'The merchant-facing text must never say "фреймворк" (AGENTS.md Conventions).' );
	}

	/**
	 * (2) A normal v2 bootstrap must register successfully and must never hook the OB-1
	 * dormant notice or record anything in the dormant accumulator.
	 */
	public function test_register_proceeds_without_notice_for_a_normal_v2_bootstrap(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		$added = [];
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback ) use ( &$added ): void {
				$added[] = [ $hook, $callback ];
			}
		);

		$plugin_file = dirname( __DIR__, 2 ) . '/woodev-loader-dormant-happy-path-fixture.php';

		$result = \Woodev_Loader::register(
			$plugin_file,
			[
				'plugin_id'         => 'dormant-notice-happy-path',
				'plugin_name'       => 'Dormant Notice Happy Path',
				'plugin_version'    => '1.0.0',
				'framework_version' => '2.0.2',
				'platform'          => 'wordpress',
				'requirements'      => [
					'php'       => '7.4',
					'wordpress' => '6.3',
				],
				'main_class'        => 'Dormant_Notice_Happy_Path_Plugin',
			]
		);

		$this->assertTrue( $result, 'register() must succeed against a normal v2 bootstrap.' );

		$dormant_notice_hooks = array_filter(
			$added,
			static function ( array $hook ): bool {
				return 'admin_notices' === $hook[0] && is_array( $hook[1] ) && ( $hook[1][0] ?? null ) === \Woodev_Loader::class;
			}
		);

		$this->assertCount( 0, $dormant_notice_hooks, 'A normal v2 registration must never hook the OB-1 dormant notice.' );
		$this->assertSame( [], $this->get_loader_dormant_plugins(), 'The dormant accumulator must stay empty on the happy path.' );
	}

	/**
	 * (3) Two dormant v2 plugins registered in the same request must both be named in the
	 * notice, which must be hooked exactly once for the whole fleet.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_two_dormant_plugins_are_both_named_and_the_notice_hooks_once(): void {
		eval(
			'class Woodev_Plugin_Bootstrap {
				private static $instance;
				public static function instance() { return self::$instance ??= new self(); }
				public function register_plugin( $framework_version, $plugin_name, $path, $callback, $args = [] ) {}
			}'
		);

		if ( ! defined( 'WOODEV_FRAMEWORK_DIR' ) ) {
			define( 'WOODEV_FRAMEWORK_DIR', dirname( __DIR__, 2 ) );
		}

		require_once dirname( __DIR__, 2 ) . '/woodev/loader.php';

		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_kses' )->returnArg();

		$added = [];
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback ) use ( &$added ): void {
				$added[] = [ $hook, $callback ];
			}
		);

		$result_a = \Woodev_Loader::register(
			dirname( __DIR__, 2 ) . '/dormant-fixture-b/dormant-fixture-b.php',
			[
				'plugin_id'         => 'dormant-fixture-b',
				'plugin_name'       => 'Dormant Plugin One',
				'plugin_version'    => '1.0.0',
				'framework_version' => '2.0.2',
				'platform'          => 'wordpress',
				'requirements'      => [
					'php'       => '7.4',
					'wordpress' => '6.3',
				],
				'main_class'        => 'Dormant_Fixture_B_Plugin',
			]
		);

		$result_b = \Woodev_Loader::register(
			dirname( __DIR__, 2 ) . '/dormant-fixture-c/dormant-fixture-c.php',
			[
				'plugin_id'         => 'dormant-fixture-c',
				'plugin_name'       => 'Dormant Plugin Two',
				'plugin_version'    => '1.0.0',
				'framework_version' => '2.0.2',
				'platform'          => 'wordpress',
				'requirements'      => [
					'php'       => '7.4',
					'wordpress' => '6.3',
				],
				'main_class'        => 'Dormant_Fixture_C_Plugin',
			]
		);

		$this->assertFalse( $result_a );
		$this->assertFalse( $result_b );

		$admin_notice_hooks = array_values(
			array_filter(
				$added,
				static function ( array $hook ): bool {
					return 'admin_notices' === $hook[0];
				}
			)
		);

		$this->assertCount( 1, $admin_notice_hooks, 'The dormant notice must be hooked exactly once for the whole fleet, not once per plugin.' );

		ob_start();
		$admin_notice_hooks[0][1]();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Dormant Plugin One', $output, 'The notice must name the first dormant plugin.' );
		$this->assertStringContainsString( 'Dormant Plugin Two', $output, 'The notice must name the second dormant plugin.' );
	}

	/**
	 * (4) The reworded B-1 tombstone notice (bootstrap.php) must never say «фреймворк» either
	 * — this is the rule that keeps regressing (AGENTS.md Conventions).
	 */
	public function test_tombstone_notice_does_not_mention_the_framework_word(): void {
		Functions\stubs( [ 'add_action', 'has_action' ] );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_kses' )->returnArg();

		$bootstrap = \Woodev_Plugin_Bootstrap::instance();
		$bootstrap->register_plugin( '1.4.1', 'Legacy Plugin', '/path/legacy.php', static function (): void {}, [] );

		ob_start();
		$bootstrap->render_mixed_fleet_notice();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Legacy Plugin', $output );
		$this->assertStringNotContainsStringIgnoringCase( 'фреймворк', $output, 'The merchant-facing text must never say "фреймворк" (AGENTS.md Conventions).' );
	}

	/**
	 * The resolver must keep naming the legacy plugin when its plugins directory is not symlinked.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_resolve_conflicting_plugin_name_resolves_non_symlinked_plugins_directory(): void {
		$base        = sys_get_temp_dir() . '/woodev_loader_' . uniqid( '', true );
		$plugins_dir = $base . '/plugins';
		$plugin_dir  = $plugins_dir . '/legacy-woodev-plugin/woodev';
		$stub_file   = $plugin_dir . '/bootstrap.php';

		mkdir( $plugin_dir, 0777, true );
		file_put_contents( $stub_file, $this->legacy_bootstrap_stub_source() );

		try {
			require $stub_file;
			define( 'WP_PLUGIN_DIR', $plugins_dir );
			$this->stub_conflicting_plugin_lookup();

			$this->assertSame( 'Legacy Woodev Plugin', $this->resolve_conflicting_plugin_name() );
		} finally {
			$this->remove_legacy_bootstrap_fixture( $stub_file, $plugin_dir, dirname( $plugin_dir ), $plugins_dir, $base );
		}
	}

	/**
	 * The resolver must resolve both paths before it compares a symlinked plugins directory.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_resolve_conflicting_plugin_name_resolves_symlinked_plugins_directory(): void {
		$base         = sys_get_temp_dir() . '/woodev_loader_' . uniqid( '', true );
		$plugins_dir  = $base . '/plugins';
		$plugins_link = $base . '/plugins-link';
		$plugin_dir   = $plugins_dir . '/legacy-woodev-plugin/woodev';
		$stub_file    = $plugin_dir . '/bootstrap.php';

		mkdir( $plugin_dir, 0777, true );
		$this->assertTrue( symlink( $plugins_dir, $plugins_link ), 'The regression fixture must create a real plugins-directory symlink.' );
		file_put_contents( $stub_file, $this->legacy_bootstrap_stub_source() );

		try {
			require $stub_file;
			define( 'WP_PLUGIN_DIR', $plugins_link );
			$this->stub_conflicting_plugin_lookup();

			$this->assertSame( 'Legacy Woodev Plugin', $this->resolve_conflicting_plugin_name() );
		} finally {
			if ( is_link( $plugins_link ) ) {
				unlink( $plugins_link );
			}
			$this->remove_legacy_bootstrap_fixture( $stub_file, $plugin_dir, dirname( $plugin_dir ), $plugins_dir, $base );
		}
	}

	/**
	 * The resolver must map a bootstrap in an individually symlinked plugin back to its plugin slug.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_resolve_conflicting_plugin_name_resolves_individually_symlinked_plugin_directory(): void {
		$base             = realpath( sys_get_temp_dir() ) . '/woodev_loader_' . uniqid( '', true );
		$plugins_dir      = $base . '/plugins';
		$plugin_link      = $plugins_dir . '/legacy-woodev-plugin';
		$real_plugin_dir  = $base . '/outside/legacy-woodev-plugin';
		$framework_dir    = $real_plugin_dir . '/woodev';
		$stub_file        = $framework_dir . '/bootstrap.php';

		mkdir( $plugins_dir, 0777, true );
		mkdir( $framework_dir, 0777, true );
		$this->assertTrue( symlink( $real_plugin_dir, $plugin_link ), 'The regression fixture must create a real individual-plugin symlink.' );
		file_put_contents( $stub_file, $this->legacy_bootstrap_stub_source() );

		try {
			require $stub_file;
			define( 'WP_PLUGIN_DIR', $plugins_dir );
			$this->stub_conflicting_plugin_lookup();

			global $wp_plugin_paths;
			$wp_plugin_paths = [ $plugin_link => $real_plugin_dir ];

			$this->assertSame( 'Legacy Woodev Plugin', $this->resolve_conflicting_plugin_name() );
		} finally {
			global $wp_plugin_paths;
			$wp_plugin_paths = [];

			if ( is_link( $plugin_link ) ) {
				unlink( $plugin_link );
			}
			$this->remove_legacy_bootstrap_fixture( $stub_file, $framework_dir, $real_plugin_dir, dirname( $real_plugin_dir ), $plugins_dir, $base );
		}
	}

	/**
	 * The resolver must not use a plugin-path mapping whose real directory does not own the bootstrap.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_resolve_conflicting_plugin_name_rejects_individual_plugin_mapping_pointing_elsewhere(): void {
		$base                = realpath( sys_get_temp_dir() ) . '/woodev_loader_' . uniqid( '', true );
		$plugins_dir         = $base . '/plugins';
		$plugin_link         = $plugins_dir . '/legacy-woodev-plugin';
		$real_plugin_dir     = $base . '/outside/legacy-woodev-plugin';
		$unrelated_plugin_dir = $base . '/elsewhere/legacy-woodev-plugin';
		$framework_dir       = $real_plugin_dir . '/woodev';
		$stub_file           = $framework_dir . '/bootstrap.php';

		mkdir( $plugins_dir, 0777, true );
		mkdir( $framework_dir, 0777, true );
		mkdir( $unrelated_plugin_dir, 0777, true );
		$this->assertTrue( symlink( $real_plugin_dir, $plugin_link ), 'The negative-control fixture must create a real individual-plugin symlink.' );
		file_put_contents( $stub_file, $this->legacy_bootstrap_stub_source() );

		try {
			require $stub_file;
			define( 'WP_PLUGIN_DIR', $plugins_dir );
			$this->stub_conflicting_plugin_lookup();

			global $wp_plugin_paths;
			$wp_plugin_paths = [ $plugin_link => $unrelated_plugin_dir ];

			$this->assertSame( '', $this->resolve_conflicting_plugin_name() );
		} finally {
			global $wp_plugin_paths;
			$wp_plugin_paths = [];

			if ( is_link( $plugin_link ) ) {
				unlink( $plugin_link );
			}
			$this->remove_legacy_bootstrap_fixture( $stub_file, $framework_dir, $real_plugin_dir, dirname( $real_plugin_dir ), $unrelated_plugin_dir, dirname( $unrelated_plugin_dir ), $plugins_dir, $base );
		}
	}

	/**
	 * The resolver must not guess an owner for a bootstrap outside a symlinked plugins directory.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_resolve_conflicting_plugin_name_rejects_file_outside_symlinked_plugins_directory(): void {
		$base         = sys_get_temp_dir() . '/woodev_loader_' . uniqid( '', true );
		$plugins_dir  = $base . '/plugins';
		$plugins_link = $base . '/plugins-link';
		$plugin_dir   = $base . '/outside/legacy-woodev-plugin/woodev';
		$stub_file    = $plugin_dir . '/bootstrap.php';

		mkdir( $plugins_dir, 0777, true );
		mkdir( $plugin_dir, 0777, true );
		$this->assertTrue( symlink( $plugins_dir, $plugins_link ), 'The negative-control fixture must create a real plugins-directory symlink.' );
		file_put_contents( $stub_file, $this->legacy_bootstrap_stub_source() );

		try {
			require $stub_file;
			define( 'WP_PLUGIN_DIR', $plugins_link );
			$this->stub_conflicting_plugin_lookup();

			$this->assertSame( '', $this->resolve_conflicting_plugin_name() );
		} finally {
			if ( is_link( $plugins_link ) ) {
				unlink( $plugins_link );
			}
			$this->remove_legacy_bootstrap_fixture( $stub_file, $plugin_dir, dirname( $plugin_dir ), $plugins_dir, $base . '/outside', $base );
		}
	}

	/**
	 * Stubs the legacy bootstrap class source so reflection reports a file path.
	 *
	 * @return string PHP source for the legacy bootstrap stub.
	 */
	private function legacy_bootstrap_stub_source(): string {
		return "<?php\n"
			. 'class Woodev_Plugin_Bootstrap {' . "\n"
			. "\tprivate static \$instance;\n"
			. "\tpublic static function instance() { return self::\$instance ??= new self(); }\n"
			. "\tpublic function register_plugin( ...\$args ) {}\n"
			. "}\n";
	}

	/**
	 * Stubs the WordPress helpers used by the conflicting-plugin resolver.
	 *
	 * @return void
	 */
	private function stub_conflicting_plugin_lookup(): void {
		Functions\when( 'wp_normalize_path' )->alias(
			static function ( string $path ): string {
				return str_replace( '\\', '/', $path );
			}
		);
		Functions\when( 'get_plugins' )->justReturn(
			[ 'legacy-woodev-plugin/legacy-woodev-plugin.php' => [ 'Name' => 'Legacy Woodev Plugin' ] ]
		);
	}

	/**
	 * Invokes the private resolver directly so the regression test covers the production loader.
	 *
	 * @return string Conflicting plugin display name, or '' when it cannot be resolved.
	 */
	private function resolve_conflicting_plugin_name(): string {
		$reflection = new ReflectionClass( \Woodev_Loader::class );
		$method     = $reflection->getMethod( 'resolve_conflicting_plugin_name' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		return (string) $method->invoke( null );
	}

	/**
	 * Removes the temporary file and empty directories created for a legacy bootstrap fixture.
	 *
	 * @param string ...$paths File then directories, ordered from most nested to outermost.
	 * @return void
	 */
	private function remove_legacy_bootstrap_fixture( string ...$paths ): void {
		foreach ( $paths as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			} elseif ( is_dir( $path ) ) {
				rmdir( $path );
			}
		}
	}

	/**
	 * Resets the real Woodev_Plugin_Bootstrap singleton via reflection, if the real class is loaded.
	 *
	 * @return void
	 */
	private function reset_bootstrap_singleton(): void {
		if ( ! class_exists( \Woodev_Plugin_Bootstrap::class, false ) ) {
			return;
		}

		$reflection = new ReflectionClass( \Woodev_Plugin_Bootstrap::class );

		// Skip the v1-shaped stub defined inside a separate-process test.
		if ( ! $reflection->hasMethod( 'register_loader_definition' ) ) {
			return;
		}

		$instance = $reflection->getProperty( 'instance' );
		if ( PHP_VERSION_ID < 80100 ) {
			$instance->setAccessible( true );
		}
		$instance->setValue( null, null );
	}

	/**
	 * Resets Woodev_Loader's fleet-wide dormant-notice static state via reflection.
	 *
	 * @return void
	 */
	private function reset_loader_dormant_state(): void {
		if ( ! class_exists( \Woodev_Loader::class, false ) ) {
			return;
		}

		$reflection = new ReflectionClass( \Woodev_Loader::class );

		$dormant = $reflection->getProperty( 'dormant_plugins' );
		if ( PHP_VERSION_ID < 80100 ) {
			$dormant->setAccessible( true );
		}
		$dormant->setValue( null, [] );

		$hooked = $reflection->getProperty( 'dormant_notice_hooked' );
		if ( PHP_VERSION_ID < 80100 ) {
			$hooked->setAccessible( true );
		}
		$hooked->setValue( null, false );
	}

	/**
	 * Reads Woodev_Loader's protected dormant-plugin accumulator via reflection.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function get_loader_dormant_plugins(): array {
		$reflection = new ReflectionClass( \Woodev_Loader::class );
		$property   = $reflection->getProperty( 'dormant_plugins' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}

		return (array) $property->getValue( null );
	}
}
