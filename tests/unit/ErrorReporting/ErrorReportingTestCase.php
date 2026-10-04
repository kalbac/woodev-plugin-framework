<?php
/**
 * Shared helpers for the error-reporter unit tests (#130).
 *
 * @package Woodev\Tests\Unit\ErrorReporting
 */

namespace Woodev\Tests\Unit\ErrorReporting;

use Woodev\Framework\Error_Reporting\Browser_Event_Builder;
use Woodev\Framework\Error_Reporting\Event_Builder;
use Woodev\Framework\Error_Reporting\Plugin_Scope;
use Woodev\Tests\Unit\TestCase;

defined( 'WP_PLUGIN_DIR' ) || define( 'WP_PLUGIN_DIR', '/srv/wp/wp-content/plugins' );

/**
 * Builds throwables with a chosen throw site and call stack, and an Event_Builder over a fixed scope.
 */
abstract class ErrorReportingTestCase extends TestCase {

	protected const HOME  = 'https://shop.example.ru';
	protected const OURS  = '/srv/wp/wp-content/plugins/acme-delivery';
	protected const OTHER = '/srv/wp/wp-content/plugins/some-other-plugin';

	/** Asset base URL of the registered plugin (browser side, #1081). */
	protected const OUR_URL = 'https://shop.example.ru/wp-content/plugins/acme-delivery';

	/** Script files that really exist in {@see self::$plugin_dir} (browser side: a frame URL must name one). */
	protected const SCRIPT_FILES = [
		'a.js',
		'assets/js/a.js',
		'assets/js/map.js',
		'assets/js/mount.js',
		'assets/js/my map.js',
	];

	/** @var string Root of a throw-away directory tree holding the registered plugin's real files. */
	protected string $fs_root = '';

	/** @var string The registered plugin's directory (`<root>/acme-delivery`), with {@see self::SCRIPT_FILES} in it. */
	protected string $plugin_dir = '';

	protected function setUp(): void {
		parent::setUp();

		$this->fs_root    = sys_get_temp_dir() . '/woodev-er-' . bin2hex( random_bytes( 6 ) );
		$this->plugin_dir = $this->fs_root . '/acme-delivery';

		foreach ( self::SCRIPT_FILES as $file ) {
			$this->touch_file( $this->plugin_dir . '/' . $file );
		}

		$this->touch_file( $this->plugin_dir . '/readme.txt' );
		$this->touch_file( $this->fs_root . '/other-plugin/secret.js' );
	}

	protected function tearDown(): void {
		$this->remove_tree( $this->fs_root );

		parent::tearDown();
	}

	/**
	 * @param string $path File to create (with its directories).
	 * @return void
	 */
	protected function touch_file( string $path ): void {
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}

		file_put_contents( $path, '// test' );
	}

	/**
	 * @param string $path File or directory (a symlink is unlinked, never followed).
	 * @return void
	 */
	private function remove_tree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );

			return;
		}

		foreach ( is_dir( $path ) ? (array) scandir( $path ) : [] as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$this->remove_tree( $path . '/' . $entry );
			}
		}

		if ( is_dir( $path ) ) {
			rmdir( $path );
		}
	}

	/**
	 * A throwable that claims to have been thrown at $file:$line with the given trace.
	 *
	 * @param string                           $file    Throw-site file.
	 * @param int                              $line    Throw-site line.
	 * @param array<int,array<string,mixed>>   $trace   `getTrace()` entries.
	 * @param string                           $message Message.
	 * @return \Exception
	 */
	protected function make_exception( string $file, int $line, array $trace = [], string $message = 'boom' ): \Exception {
		$e = new \RuntimeException( $message );

		foreach ( [
			'file'  => $file,
			'line'  => $line,
			'trace' => $trace,
		] as $property => $value ) {
			$reflection = new \ReflectionProperty( \Exception::class, $property );

			if ( PHP_VERSION_ID < 80100 ) {
				$reflection->setAccessible( true ); // Deprecated since 8.5 and a no-op since 8.1.
			}

			$reflection->setValue( $e, $value );
		}

		return $e;
	}

	/**
	 * The event as the transport serialises it (wp_json_encode is absent in unit context).
	 *
	 * @param mixed $data Data.
	 * @return string
	 */
	protected function encode( $data ): string {
		return (string) json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * @return Plugin_Scope One registered plugin: acme-delivery 1.4.0.
	 */
	protected function make_scope(): Plugin_Scope {
		return new Plugin_Scope(
			[
				[
					'id'      => 'acme-delivery',
					'version' => '1.4.0',
					'dir'     => self::OURS,
				],
			]
		);
	}

	/**
	 * @return Event_Builder Builder over {@see self::make_scope()} with fixed context.
	 */
	protected function make_builder(): Event_Builder {
		return new Event_Builder(
			$this->make_scope(),
			[
				'site'              => 'abcdef0123456789',
				'framework_version' => '2.0.1',
				'wp_version'        => '6.8',
				'wc_version'        => '10.0.0',
				'php_version'       => '8.1.0',
				'environment'       => 'production',
			],
			[
				'abspath'    => '/srv/wp/',
				'plugin_dir' => '/srv/wp/wp-content/plugins',
			]
		);
	}

	/**
	 * @return Plugin_Scope One registered plugin with a REAL directory ({@see self::$plugin_dir}) AND an asset base URL.
	 */
	protected function make_browser_scope(): Plugin_Scope {
		return new Plugin_Scope(
			[
				[
					'id'      => 'acme-delivery',
					'version' => '1.4.0',
					'dir'     => $this->plugin_dir,
					'url'     => self::OUR_URL,
				],
			]
		);
	}

	/**
	 * @param array<string,array<string>>|null $pickup_fields Field ids the server knows, by plugin id (default: `acme-delivery` → `pickup_point`).
	 * @return Browser_Event_Builder Builder over {@see self::make_browser_scope()} with fixed context.
	 */
	protected function make_browser_builder( ?array $pickup_fields = null ): Browser_Event_Builder {
		return new Browser_Event_Builder(
			$this->make_browser_scope(),
			[
				'site'              => 'abcdef0123456789',
				'framework_version' => '2.0.1',
				'wp_version'        => '6.8',
				'wc_version'        => '10.0.0',
				'php_version'       => '8.1.0',
				'environment'       => 'production',
			],
			$pickup_fields ?? [ 'acme-delivery' => [ 'pickup_point' ] ]
		);
	}
}
