<?php
/**
 * Plugin Name: Woodev Entry Path Fixture
 * Description: Registers itself the way a REAL v2 plugin does — from the entry file, through
 *              Woodev_Loader::register(). NOT for production use.
 * Version:     1.0.0
 * Author:      Woodev
 * Text Domain: woodev-entry-path-fixture
 *
 * The point of this fixture is the SHAPE, not the plugin. Every other fixture reaches the
 * framework by requiring `bootstrap.php` directly, so the framework is already loaded before
 * their definition is evaluated — which is exactly why the entry-path fatal of #763 could not
 * be caught anywhere. This one travels the path `AGENT-RULES.md` Rule 3 prescribes.
 *
 * ⚠ The definition below may contain ONLY literals. A framework constant here is a hard fatal in
 * a real plugin, because PHP builds this array before register() requires the bootstrap that
 * registers the autoloader. Gotcha:
 * `a-loader-definition-cannot-use-a-framework-class-constant`.
 *
 * @package Woodev_Entry_Path_Fixture
 */

defined( 'ABSPATH' ) || exit;

defined( 'WOODEV_ENTRY_PATH_FIXTURE_FILE' ) || define( 'WOODEV_ENTRY_PATH_FIXTURE_FILE', __FILE__ );
defined( 'WOODEV_ENTRY_PATH_FIXTURE_VERSION' ) || define( 'WOODEV_ENTRY_PATH_FIXTURE_VERSION', '1.0.0' );

// A real plugin requires this from its own bundled copy:
//     require_once plugin_dir_path( __FILE__ ) . 'woodev/loader.php';
// The fixture has no bundled copy, so it resolves the framework the same way Woodev_Loader does —
// via WOODEV_FRAMEWORK_DIR when defined, falling back to this checkout.
require_once ( defined( 'WOODEV_FRAMEWORK_DIR' )
	? rtrim( (string) constant( 'WOODEV_FRAMEWORK_DIR' ), '/\\' )
	: dirname( __DIR__, 3 ) ) . '/woodev/loader.php';

Woodev_Loader::register(
	WOODEV_ENTRY_PATH_FIXTURE_FILE,
	[
		'plugin_id'         => 'woodev-entry-path-fixture',
		'plugin_name'       => 'Woodev Entry Path Fixture',
		'plugin_version'    => WOODEV_ENTRY_PATH_FIXTURE_VERSION,
		'framework_version' => '2.0.2',
		'platform'          => 'wordpress',
		'requirements'      => [
			'php'       => '7.4',
			'wordpress' => '6.3',
		],
		'main_class'        => 'Woodev_Entry_Path_Fixture_Plugin',
	]
);
