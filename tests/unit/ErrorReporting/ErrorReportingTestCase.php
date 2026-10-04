<?php
/**
 * Shared helpers for the error-reporter unit tests (#130).
 *
 * @package Woodev\Tests\Unit\ErrorReporting
 */

namespace Woodev\Tests\Unit\ErrorReporting;

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
				'home_url'   => self::HOME,
			]
		);
	}
}
