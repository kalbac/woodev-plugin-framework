<?php
/**
 * Child-process runner for the error reporter's real-PHP-dispatch tests (#130).
 *
 * Usage: php runner.php <scenario>. It stubs the handful of WordPress functions the reporter
 * touches with an in-memory option store, installs the REAL Error_Reporter against a registered
 * plugin whose directory is fixtures/acme-plugin, runs the scenario, and prints a `QUEUE:<json>`
 * line from its own shutdown function (registered AFTER the reporter's, so it runs last).
 * Everything else on stdout is PHP's own output — the tests assert on it, so the original
 * «Fatal error: Uncaught …» text and exit status 255 are visible exactly as PHP wrote them.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_PLUGIN_DIR', __DIR__ );

$GLOBALS['woodev_fixture_options']  = [ 'woodev_error_reporting_enabled' => 'yes' ];
$GLOBALS['woodev_fixture_handler_calls'] = 0;
$GLOBALS['wp_version']              = '6.8';

function get_option( $name, $default = false ) {
	return $GLOBALS['woodev_fixture_options'][ $name ] ?? $default;
}
function update_option( $name, $value ) {
	$GLOBALS['woodev_fixture_options'][ $name ] = $value;
	return true;
}
function delete_option( $name ) {
	unset( $GLOBALS['woodev_fixture_options'][ $name ] );
	return true;
}
function apply_filters( $tag, $value ) {
	return 'woodev_error_reporting_dsn' === $tag ? 'https://key@errors.example.ru/7' : $value;
}
function add_action() {}
function wp_next_scheduled() {
	return false;
}
function wp_schedule_single_event() {
	return true;
}
function home_url() {
	return 'https://shop.example.ru/';
}
function untrailingslashit( $value ) {
	return rtrim( $value, '/' );
}
function wp_remote_post() {
	echo "HTTP-CALLED\n";
	return [];
}

class Woodev_REST_V1_Registrar {
	public static function register_controller( $controller ) {}
}

$root = dirname( __DIR__, 4 ) . '/woodev';
require_once __DIR__ . '/stub-rest-controller.php';
require_once $root . '/class-framework-plugin-loader-definition.php';
foreach ( [ 'plugin-scope', 'dsn', 'rate-limiter', 'event-builder', 'event-queue', 'dispatcher', 'transport', 'consent', 'error-reporter' ] as $file ) {
	require_once $root . '/error-reporting/class-' . $file . '.php';
}

use Woodev\Framework\Error_Reporting\Error_Reporter;
use Woodev\Framework\Framework_Plugin_Loader_Definition;

$scenario = $argv[1] ?? '';

require_once __DIR__ . '/acme-plugin/boom.php';
require_once __DIR__ . '/foreign.php';

$errors     = [];
$definition = Framework_Plugin_Loader_Definition::from_array(
	[
		'plugin_id'         => 'acme-delivery',
		'plugin_name'       => 'Acme',
		'plugin_version'    => '1.4.0',
		'framework_version' => '2.0.1',
		'plugin_file'       => __DIR__ . '/acme-plugin/acme-delivery.php',
		'platform'          => Framework_Plugin_Loader_Definition::PLATFORM_WORDPRESS,
		'download_id'       => 77,
		'main_class'        => 'Acme_Plugin',
		'requirements'      => [
			'php'       => '7.4',
			'wordpress' => '6.3',
		],
	],
	$errors
);

// A handler that was there before the reporter: counts its calls and, in the «previous» scenarios, acts.
if ( in_array( $scenario, [ 'previous', 'previous_throws' ], true ) ) {
	set_exception_handler(
		static function ( \Throwable $e ) use ( $scenario ) {
			++$GLOBALS['woodev_fixture_handler_calls'];
			echo 'PREVIOUS-HANDLER:' . get_class( $e ) . "\n";

			if ( 'previous_throws' === $scenario ) {
				throw new \LogicException( 'the previous handler failed' );
			}
		}
	);
}

Error_Reporter::install( [ $definition->to_legacy_plugin() ] );

register_shutdown_function(
	static function () {
		$queue = $GLOBALS['woodev_fixture_options']['woodev_error_reporting_queue'] ?? [];
		echo "\nQUEUE:" . json_encode( $queue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
	}
);

switch ( $scenario ) {
	case 'ours':
	case 'previous':
	case 'previous_throws':
		( new \Acme_Fixture\Boom() )->start();
		break;

	case 'foreign':
		foreign_fail();
		break;

	case 'anonymous':
		( new \Acme_Fixture\Boom() )->anonymous();
		break;

	case 'replaced':
		// Somebody installs a handler AFTER the reporter and does not chain to it.
		set_exception_handler(
			static function ( \Throwable $e ) {
				throw $e;
			}
		);
		( new \Acme_Fixture\Boom() )->start();
		break;

	case 'engine_fatal':
		( new \Acme_Fixture\Boom() )->exhaust_memory();
		break;

	case 'oom_heap':
		( new \Acme_Fixture\Boom() )->fill_heap();
		break;

	case 'captured':
		try {
			( new \Acme_Fixture\Boom() )->start();
		} catch ( \Throwable $e ) {
			Error_Reporter::capture( $e );
		}
		echo "PAGE-CONTINUES\n";
		break;
}
