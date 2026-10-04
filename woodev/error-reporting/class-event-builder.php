<?php
/**
 * Builds the anonymised Sentry event payload.
 *
 * @package Woodev\Framework\Error_Reporting
 */

namespace Woodev\Framework\Error_Reporting;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\Woodev\Framework\Error_Reporting\Event_Builder' ) ) :

	/**
	 * Turns a Throwable or a fatal-error array into a Sentry `event` payload — or into null
	 * when the error is not ours.
	 *
	 * What is deliberately NOT in the payload: no `request`, no `user`, no cookies, no
	 * function arguments (the trace is read without `args`), no absolute paths, no site
	 * address. The site is one salted hash; every path is relative; the message is scrubbed.
	 *
	 * @since 2.0.2
	 */
	final class Event_Builder {

		const SDK_NAME    = 'woodev.error-reporter';
		const SDK_VERSION = '1.0.0';

		/** Longest message / function name that leaves the site. */
		const MAX_TEXT = 500;

		/** Frames kept, innermost first. */
		const MAX_FRAMES = 50;

		/** @var Plugin_Scope */
		private Plugin_Scope $scope;

		/** @var array<string,string> */
		private array $context;

		/** @var array<string,string> */
		private array $scrub;

		/**
		 * @param Plugin_Scope         $scope   Which directories are ours.
		 * @param array<string,string> $context Emitted: site, framework_version, wp_version, wc_version, php_version, environment.
		 * @param array<string,string> $scrub   Never emitted, only removed from text: abspath, plugin_dir, home_url.
		 */
		public function __construct( Plugin_Scope $scope, array $context, array $scrub ) {
			$this->scope   = $scope;
			$this->context = $context;
			$this->scrub   = $scrub;
		}

		/**
		 * Builds an event for a Throwable.
		 *
		 * @since 2.0.2
		 *
		 * @param \Throwable  $e         The throwable.
		 * @param string|null $plugin_id Explicit owner (a manual capture), wins over the stack.
		 * @param bool        $handled   False for an uncaught exception.
		 * @return array<string,mixed>|null Null when the error is not ours.
		 */
		public function from_throwable( \Throwable $e, ?string $plugin_id = null, bool $handled = true ): ?array {
			$frames = [];
			$file   = $e->getFile();
			$line   = $e->getLine();

			// Throw-site first: the file/line of the throwable pair with the function of trace[0],
			// then each trace entry's call site pairs with the NEXT entry's function. `args` is
			// never read.
			foreach ( $e->getTrace() as $item ) {
				$frames[] = [
					'file'     => $file,
					'line'     => $line,
					'function' => $this->function_name( $item ),
				];
				$file     = isset( $item['file'] ) ? (string) $item['file'] : '';
				$line     = isset( $item['line'] ) ? (int) $item['line'] : 0;
			}

			$frames[] = [
				'file'     => $file,
				'line'     => $line,
				'function' => '',
			];

			$type = get_class( $e );
			$nul  = strpos( $type, "\0" );

			if ( false !== $nul ) {
				$type = substr( $type, 0, $nul ); // Anonymous class names embed a path after a NUL.
			}

			return $this->build( $type, $e->getMessage(), $handled ? 'error' : 'fatal', $frames, $plugin_id, $handled );
		}

		/**
		 * Builds an event for a fatal error taken from `error_get_last()`.
		 *
		 * An «Uncaught …» fatal carries the call stack as text after `Stack trace:`; the file paths
		 * (and only those — the argument text is dropped) are parsed back out so the scope filter
		 * sees the whole stack, not just the throw site.
		 *
		 * @since 2.0.2
		 *
		 * @param array{type:int,message:string,file:string,line:int} $error The last-error array.
		 * @return array<string,mixed>|null Null when the error is not ours.
		 */
		public function from_fatal( array $error ): ?array {
			$raw     = (string) ( $error['message'] ?? '' );
			$marker  = strpos( $raw, "\nStack trace:" );
			$message = false === $marker ? $raw : substr( $raw, 0, $marker );
			$frames  = [
				[
					'file'     => (string) ( $error['file'] ?? '' ),
					'line'     => (int) ( $error['line'] ?? 0 ),
					'function' => '',
				],
			];

			if ( false !== $marker && preg_match_all( '/^#\d+\s+(.+?)\((\d+)\):\s*([^\s(]+)/m', substr( $raw, $marker ), $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$frames[] = [
						'file'     => $match[1],
						'line'     => (int) $match[2],
						'function' => $match[3],
					];
				}
			}

			return $this->build( self::error_type_name( (int) ( $error['type'] ?? 0 ) ), $message, 'fatal', $frames, null, false );
		}

		/**
		 * A site path made relative: `plugins/<dir>/…` under the plugins dir, otherwise relative to
		 * ABSPATH, otherwise only the base name behind `[external]`.
		 *
		 * @since 2.0.2
		 *
		 * @param string $file Absolute path.
		 * @return string
		 */
		public function relative_path( string $file ): string {
			$file = Plugin_Scope::normalize_path( $file );

			if ( '' === $file ) {
				return '[internal]';
			}

			$plugin_dir = $this->scrub_base( 'plugin_dir' );
			if ( '' !== $plugin_dir && 0 === strpos( $file, $plugin_dir . '/' ) ) {
				return 'plugins/' . substr( $file, strlen( $plugin_dir ) + 1 );
			}

			$abspath = $this->scrub_base( 'abspath' );
			if ( '' !== $abspath && 0 === strpos( $file, $abspath . '/' ) ) {
				return substr( $file, strlen( $abspath ) + 1 );
			}

			return '[external]/' . basename( $file );
		}

		public function scrub_text( string $text ): string {
			$text = str_replace( "\0", '', $text );

			$home = (string) ( $this->scrub['home_url'] ?? '' );
			if ( '' !== $home ) {
				$host = parse_url( $home, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
				$text = str_replace( rtrim( $home, '/' ), '[site]', $text );

				if ( is_string( $host ) && '' !== $host ) {
					$text = str_replace( $host, '[site]', $text );
				}
			}

			// Paths: the text may spell a directory with either slash, and backslashes in a
			// message are also namespace separators — so replace each base in both spellings
			// instead of normalising the whole text.
			$plugin_dir = $this->scrub_base( 'plugin_dir' );
			if ( '' !== $plugin_dir ) {
				$text = str_replace( [ $plugin_dir . '/', str_replace( '/', '\\', $plugin_dir ) . '\\' ], 'plugins/', $text );
			}

			$abspath = $this->scrub_base( 'abspath' );
			if ( '' !== $abspath ) {
				$text = str_replace( [ $abspath . '/', str_replace( '/', '\\', $abspath ) . '\\' ], '', $text );
			}

			$text = (string) preg_replace( '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[email]', $text );

			if ( strlen( $text ) <= self::MAX_TEXT ) {
				return $text;
			}

			// mb_strcut cuts on a character boundary; a half character would make the JSON invalid.
			return ( function_exists( 'mb_strcut' ) ? mb_strcut( $text, 0, self::MAX_TEXT, 'UTF-8' ) : substr( $text, 0, self::MAX_TEXT ) ) . '…';
		}

		/**
		 * The name PHP gives an error-type constant.
		 *
		 * @since 2.0.2
		 *
		 * @param int $type E_* value.
		 * @return string
		 */
		public static function error_type_name( int $type ): string {
			$names = [
				E_ERROR             => 'E_ERROR',
				E_PARSE             => 'E_PARSE',
				E_CORE_ERROR        => 'E_CORE_ERROR',
				E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
				E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
			];

			return $names[ $type ] ?? 'E_UNKNOWN';
		}

		/**
		 * @param string                                                 $type      Exception class or E_* name.
		 * @param string                                                 $message   Raw message.
		 * @param string                                                 $level     Sentry level.
		 * @param array<int,array{file:string,line:int,function:string}> $frames Innermost first.
		 * @param string|null                                            $plugin_id Explicit owner.
		 * @param bool                                                   $handled   Whether the code handled it.
		 * @return array<string,mixed>|null
		 */
		private function build( string $type, string $message, string $level, array $frames, ?string $plugin_id, bool $handled ): ?array {
			$frames = array_slice( $frames, 0, self::MAX_FRAMES );
			$owner  = null !== $plugin_id ? $this->scope->get( $plugin_id ) : null;

			if ( null === $owner ) {
				$owner = $this->scope->match( array_column( $frames, 'file' ) );
			}

			if ( null === $owner ) {
				return null;
			}

			$out = [];

			// Sentry wants the OLDEST frame first.
			foreach ( array_reverse( $frames ) as $frame ) {
				$entry = [
					'filename' => $this->relative_path( $frame['file'] ),
					'lineno'   => $frame['line'],
					'in_app'   => $this->scope->contains( $frame['file'] ),
				];

				if ( '' !== $frame['function'] ) {
					$entry['function'] = $this->scrub_text( $frame['function'] );
				}

				$out[] = $entry;
			}

			$tags = array_filter(
				[
					'plugin'            => $owner['id'],
					'plugin_version'    => $owner['version'],
					'framework_version' => $this->context['framework_version'] ?? '',
					'wp_version'        => $this->context['wp_version'] ?? '',
					'wc_version'        => $this->context['wc_version'] ?? '',
					'php_version'       => $this->context['php_version'] ?? '',
					'site'              => $this->context['site'] ?? '',
				],
				static function ( string $value ): bool {
					return '' !== $value;
				}
			);

			return [
				'event_id'    => bin2hex( random_bytes( 16 ) ),
				'timestamp'   => time(),
				'platform'    => 'php',
				'level'       => $level,
				'logger'      => 'woodev',
				'release'     => $owner['id'] . '@' . $owner['version'],
				'environment' => $this->context['environment'] ?? 'production',
				'server_name' => $this->context['site'] ?? '',
				'sdk'         => [
					'name'    => self::SDK_NAME,
					'version' => self::SDK_VERSION,
				],
				'tags'        => $tags,
				'exception'   => [
					'values' => [
						[
							'type'       => $type,
							'value'      => $this->scrub_text( $message ),
							'mechanism'  => [
								'type'    => 'generic',
								'handled' => $handled,
							],
							'stacktrace' => [ 'frames' => $out ],
						],
					],
				],
			];
		}

		/**
		 * @param array<string,mixed> $item One `getTrace()` entry.
		 * @return string `Class->method`, `Class::method` or `function`.
		 */
		private function function_name( array $item ): string {
			return ( $item['class'] ?? '' ) . ( $item['type'] ?? '' ) . ( $item['function'] ?? '' );
		}

		/**
		 * A scrub path normalized without a trailing slash.
		 *
		 * @param string $key `abspath` or `plugin_dir`.
		 * @return string
		 */
		private function scrub_base( string $key ): string {
			return rtrim( Plugin_Scope::normalize_path( (string) ( $this->scrub[ $key ] ?? '' ) ), '/' );
		}
	}

endif;
