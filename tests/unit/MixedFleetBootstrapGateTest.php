<?php
/**
 * Mixed-fleet bootstrap gate test (B-1).
 *
 * Covers both directions of the mixed v1/v2 fleet hard-gate:
 *  - Direction A: a v2 plugin entry file loaded against a legacy (v1) bootstrap that
 *    lacks register_loader_definition() must stay dormant + warn, never fatal.
 *  - Direction B: a legacy (v1) plugin calling register_plugin() on the real v2 bootstrap
 *    must be quarantined — callback never invoked, plugin recorded, notice path engaged,
 *    and any garbage argument shape tolerated without throwing.
 *
 * @package Woodev\Tests\Unit
 */

namespace Woodev\Tests\Unit;

use Brain\Monkey\Functions;
use ReflectionClass;

/**
 * Class MixedFleetBootstrapGateTest
 */
class MixedFleetBootstrapGateTest extends TestCase {

	/**
	 * Reset the real bootstrap singleton before each test to avoid pollution.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->reset_bootstrap_singleton();
	}

	/**
	 * Tear down: reset the singleton to leave a clean state.
	 */
	protected function tearDown(): void {
		$this->reset_bootstrap_singleton();
		parent::tearDown();
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

		// Skip the v1-shaped stub defined inside the Direction A separate process.
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
	 * Reads a protected property from the bootstrap via reflection.
	 *
	 * @param \Woodev_Plugin_Bootstrap $bootstrap The bootstrap instance.
	 * @param string                   $name      Property name.
	 * @return mixed
	 */
	private function get_protected_property( \Woodev_Plugin_Bootstrap $bootstrap, string $name ) {
		$reflection = new ReflectionClass( $bootstrap );
		$property   = $reflection->getProperty( $name );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}

		return $property->getValue( $bootstrap );
	}

	/**
	 * Direction A: a v2 entry file on a legacy (v1) bootstrap must stay dormant + warn, never fatal.
	 *
	 * A v1-shaped stub Woodev_Plugin_Bootstrap (instance() + register_plugin(), but NO
	 * register_loader_definition()) is defined BEFORE including the fixture entry file. Reaching
	 * register_loader_definition() on the stub would be a fatal Error, so completing the include
	 * proves the probe short-circuited.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_direction_a_v2_entry_file_stays_dormant_on_legacy_bootstrap(): void {
		// Legacy (v1) bootstrap stub — wins the class rendezvous, lacks register_loader_definition().
		// eval() defines a test-only stub class in this isolated process (no untrusted input); this
		// mirrors the established FeaturesUtil-stub pattern in BootstrapRegistrationTest.
		eval(
			'class Woodev_Plugin_Bootstrap {
				private static $instance;
				public static function instance() { return self::$instance ??= new self(); }
				public function register_plugin( $framework_version, $plugin_name, $path, $callback, $args = [] ) {}
			}'
		);

		Functions\stubEscapeFunctions();
		Functions\when( 'wp_kses' )->returnArg();

		// Point the fixture at the real framework root so its file_exists() guard passes; the
		// stub already defines Woodev_Plugin_Bootstrap, so the real bootstrap.php is never required.
		if ( ! defined( 'WOODEV_FRAMEWORK_DIR' ) ) {
			define( 'WOODEV_FRAMEWORK_DIR', dirname( __DIR__, 2 ) );
		}

		$added = [];
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback ) use ( &$added ): void {
				$added[] = [ $hook, $callback ];
			}
		);

		require __DIR__ . '/../_fixtures/woodev-test-plugin/woodev-test-plugin.php';

		// register_loader_definition() was never reached (the stub lacks it — reaching it would fatal).
		$this->assertFalse(
			method_exists( \Woodev_Plugin_Bootstrap::instance(), 'register_loader_definition' ),
			'The legacy stub must remain the loaded bootstrap.'
		);

		$admin_notice_hooks = array_filter(
			$added,
			static function ( array $hook ): bool {
				return 'admin_notices' === $hook[0];
			}
		);

		$this->assertCount( 1, $admin_notice_hooks, 'The probe must hook exactly one admin_notices warning.' );
	}

	/**
	 * Direction B: a legacy (v1) register_plugin() call on the real v2 bootstrap is quarantined.
	 *
	 * The callback must never fire, the plugin must be recorded, the notice path must engage, and
	 * the hooked callback must be the bootstrap render method. The render-purity assertion (the
	 * renderer touches no framework runtime class) lives in its own separate-process test below,
	 * because it requires a clean class table to mean anything.
	 */
	public function test_direction_b_legacy_register_plugin_is_quarantined(): void {
		Functions\stubs( [ 'add_action' ] );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( '_x' )->returnArg();

		unset( $GLOBALS['b1_cb'] );

		$added = [];
		Functions\when( 'has_action' )->justReturn( false );
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback ) use ( &$added ): void {
				$added[] = [ $hook, $callback ];
			}
		);

		$bootstrap = \Woodev_Plugin_Bootstrap::instance();

		// v1 positional signature: register_plugin( $framework_version, $plugin_name, $path, $callback, $args ).
		$bootstrap->register_plugin(
			'1.4.1',
			'Legacy Plugin',
			'/path/legacy.php',
			static function (): void {
				$GLOBALS['b1_cb'] = true;
			},
			[]
		);

		$this->assertArrayNotHasKey( 'b1_cb', $GLOBALS, 'The legacy v1 callback must never be invoked.' );

		$recorded = $this->get_protected_property( $bootstrap, 'mixed_fleet_incompatible_plugins' );
		$this->assertCount( 1, $recorded, 'The legacy plugin must be recorded in the mixed-fleet list.' );
		$this->assertSame( 'Legacy Plugin', $recorded[0]['plugin_name'] );
		$this->assertSame( '/path/legacy.php', $recorded[0]['path'] );

		$admin_notice_hooks = array_filter(
			$added,
			static function ( array $hook ): bool {
				return 'admin_notices' === $hook[0];
			}
		);
		$this->assertCount( 1, $admin_notice_hooks, 'The tombstone must engage the admin_notices path.' );

		$render_callback = $admin_notice_hooks[ array_key_first( $admin_notice_hooks ) ][1];
		$this->assertSame(
			[ $bootstrap, 'render_mixed_fleet_notice' ],
			$render_callback,
			'The hooked callback must be the bootstrap render method.'
		);
	}

	/**
	 * Direction B: registering a second legacy plugin must NOT add admin_notices hook twice.
	 *
	 * The bootstrap uses a private flag $mixed_fleet_notice_hooked to ensure the admin_notices hook
	 * is registered exactly once, even when multiple legacy v1 plugins are quarantined.
	 */
	public function test_direction_b_second_legacy_plugin_does_not_re_hook_admin_notices(): void {
		Functions\stubs( [ 'add_action' ] );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( '_x' )->returnArg();

		$added = [];
		Functions\when( 'has_action' )->justReturn( false );
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback ) use ( &$added ): void {
				$added[] = [ $hook, $callback ];
			}
		);

		$bootstrap = \Woodev_Plugin_Bootstrap::instance();

		// Register first legacy plugin.
		$bootstrap->register_plugin(
			'1.4.1',
			'Legacy Plugin One',
			'/path/legacy-one.php',
			static function (): void {},
			[]
		);

		// Register second legacy plugin.
		$bootstrap->register_plugin(
			'1.4.0',
			'Legacy Plugin Two',
			'/path/legacy-two.php',
			static function (): void {},
			[]
		);

		$recorded = $this->get_protected_property( $bootstrap, 'mixed_fleet_incompatible_plugins' );
		$this->assertCount( 2, $recorded, 'Both legacy plugins must be recorded.' );
		$this->assertSame( 'Legacy Plugin One', $recorded[0]['plugin_name'] );
		$this->assertSame( 'Legacy Plugin Two', $recorded[1]['plugin_name'] );

		$admin_notice_hooks = array_filter(
			$added,
			static function ( array $hook ): bool {
				return 'admin_notices' === $hook[0];
			}
		);
		$this->assertCount( 1, $admin_notice_hooks, 'The admin_notices hook must be added exactly once, not twice.' );
	}

	/**
	 * Direction B (render purity): the quarantine renderer must NOT touch any framework runtime class.
	 *
	 * "Not loaded" is exactly the mixed-fleet scenario the tombstone serves — in production the framework
	 * runtime (e.g. \Woodev_Helper) is genuinely unavailable, so the old
	 * \Woodev_Helper::list_array_items() call fatals. Under the classmap autoloader a reference here would
	 * instead SILENTLY load the class, so we snapshot the declared framework classes around the render call
	 * and assert the set did not grow.
	 *
	 * This runs in a SEPARATE PROCESS with global state disabled so the class table is provably clean: no
	 * earlier test can have preloaded \Woodev_Helper, which means the hard assertFalse() below can never be
	 * a false skip — a present class is a real regression, never test pollution. The autoloader is inherited
	 * by the child process, so referencing \Woodev_Plugin_Bootstrap still resolves via the classmap.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_direction_b_render_notice_loads_no_framework_class(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( '_x' )->returnArg();
		Functions\when( 'has_action' )->justReturn( false );

		$added = [];
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback ) use ( &$added ): void {
				$added[] = [ $hook, $callback ];
			}
		);

		$bootstrap = \Woodev_Plugin_Bootstrap::instance();

		$bootstrap->register_plugin( '1.4.1', 'Legacy Plugin', '/path/legacy.php', static function (): void {}, [] );

		$admin_notice_hooks = array_filter(
			$added,
			static function ( array $hook ): bool {
				return 'admin_notices' === $hook[0];
			}
		);
		$render_callback = $admin_notice_hooks[ array_key_first( $admin_notice_hooks ) ][1];

		// Hard guard, not a skip: in a separate process the class table is clean, so a present Woodev_Helper
		// here means a real regression (the renderer or something it touched loaded it), never test pollution.
		$this->assertFalse(
			class_exists( 'Woodev_Helper', false ),
			'Woodev_Helper must not be loaded before the renderer runs — the separate process guarantees a clean class table.'
		);

		$framework_classes_before = $this->loaded_framework_classes();

		ob_start();
		( $bootstrap->{$render_callback[1]}() );
		$output = (string) ob_get_clean();

		$framework_classes_after = $this->loaded_framework_classes();

		$this->assertStringContainsString( 'Legacy Plugin', $output, 'The rendered notice must name the quarantined plugin.' );
		$this->assertStringContainsString( '<div class="error">', $output, 'The rendered notice must produce the admin-notice markup.' );
		$this->assertSame(
			$framework_classes_before,
			$framework_classes_after,
			'The renderer must not load any Woodev_* framework runtime class — it has to survive the no-framework mixed-fleet case.'
		);
	}

	/**
	 * Snapshot of currently-declared framework runtime classes (Woodev_* and Woodev\Framework\*).
	 *
	 * Used to prove the mixed-fleet renderer pulls in NO framework class as a side effect — in a real
	 * mixed-fleet site those classes are unavailable and any reference fatals; here the classmap
	 * autoloader would silently load them on reference, so a grown snapshot reveals the regression.
	 *
	 * @return array<int,string>
	 */
	private function loaded_framework_classes(): array {
		$framework = array_filter(
			get_declared_classes(),
			static function ( string $class ): bool {
				return 0 === strpos( $class, 'Woodev_' ) || 0 === strpos( $class, 'Woodev\\Framework\\' );
			}
		);

		sort( $framework );

		return $framework;
	}

	/**
	 * Direction B (XSS guard): the quarantine renderer must escape plugin names in output.
	 *
	 * A legacy plugin with a malicious name like '<script>alert(1)</script>' must be rendered
	 * as plain text, not executed. This guard ensures the renderer never outputs unescaped data.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_direction_b_render_escapes_plugin_names(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\stubEscapeFunctions();
		Functions\when( 'esc_html' )->alias(
			static function ( string $text ): string {
				return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
			}
		);
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( '_x' )->returnArg();
		Functions\when( 'has_action' )->justReturn( false );

		$added = [];
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback ) use ( &$added ): void {
				$added[] = [ $hook, $callback ];
			}
		);

		$bootstrap = \Woodev_Plugin_Bootstrap::instance();

		$bootstrap->register_plugin(
			'1.4.1',
			'<script>alert(1)</script>Plugin',
			'/path/malicious.php',
			static function (): void {},
			[]
		);

		$admin_notice_hooks = array_filter(
			$added,
			static function ( array $hook ): bool {
				return 'admin_notices' === $hook[0];
			}
		);
		$render_callback = $admin_notice_hooks[ array_key_first( $admin_notice_hooks ) ][1];

		ob_start();
		( $bootstrap->{$render_callback[1]}() );
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString(
			'<script>',
			$output,
			'The rendered output must not contain unescaped <script> tags — plugin name must be escaped.'
		);
		$this->assertStringContainsString(
			'alert(1)',
			$output,
			'The plugin name text must appear in output (escaped), not be stripped.'
		);
	}

	/**
	 * Direction B: the tombstone tolerates ANY argument shape without throwing.
	 */
	public function test_direction_b_tombstone_tolerates_garbage_arguments(): void {
		Functions\stubs( [ 'add_action', 'has_action' ] );
		Functions\when( 'is_admin' )->justReturn( false );

		$bootstrap = \Woodev_Plugin_Bootstrap::instance();

		// None of these may raise a TypeError — the caller is unknown-version legacy code.
		$bootstrap->register_plugin();
		$bootstrap->register_plugin( null );
		$bootstrap->register_plugin( 42, [ 'not-a-string' ], new \stdClass() );

		$recorded = $this->get_protected_property( $bootstrap, 'mixed_fleet_incompatible_plugins' );
		$this->assertCount( 3, $recorded, 'Every garbage call must still be recorded without throwing.' );
		$this->assertSame( '', $recorded[2]['path'] ?? 'unset', 'A non-string path argument falls back to an empty string.' );
		// A non-string plugin_name argument falls back to the localized placeholder. Brain Monkey stubs __()
		// to echo its source string, so the recorded name must equal the Russian source verbatim.
		$this->assertSame( 'Неизвестный плагин', $recorded[2]['plugin_name'] ?? 'unset', 'A non-string plugin-name argument falls back to the localized placeholder.' );
	}
}
