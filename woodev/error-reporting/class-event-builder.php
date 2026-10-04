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
	 * **No free text from an exception ever leaves the site** (operator decision, s150): an
	 * exception message routinely carries order data, names, addresses, SQL values or tokens, and
	 * no scrubber can tell them from the rest. The event carries the exception CLASS, its integer
	 * code, the throw site `file:line` and the call stack as relative paths plus function and class
	 * names. For a fatal taken from `error_get_last()` the engine's own message is sent (it names
	 * code, not data — «Call to undefined function», «Allowed memory size exhausted»), except an
	 * «Uncaught …» fatal, whose embedded exception message is cut down to the exception class.
	 *
	 * Also never in the payload: `request`, `user`, cookies, function arguments (the trace is read
	 * without `args`), absolute paths, the site address. The site is one salted hash.
	 *
	 * @since 2.0.2
	 */
	final class Event_Builder {

		const SDK_NAME    = 'woodev.error-reporter';
		const SDK_VERSION = '1.0.0';

		/** Longest engine message that leaves the site. */
		const MAX_TEXT = 500;

		/** Frames kept, innermost first. Scope matching runs on ALL frames before this cut. */
		const MAX_FRAMES = 50;

		/** Longest class / function name kept. */
		const MAX_SYMBOL = 200;

		/** @var Plugin_Scope */
		private Plugin_Scope $scope;

		/** @var array<string,string> */
		private array $context;

		/** @var array<string,string> */
		private array $paths;

		/**
		 * @param Plugin_Scope         $scope   Which directories are ours.
		 * @param array<string,string> $context Emitted: site, framework_version, wp_version, wc_version, php_version, environment.
		 * @param array<string,string> $paths   Never emitted, only used to relativise paths: abspath, plugin_dir.
		 */
		public function __construct( Plugin_Scope $scope, array $context, array $paths ) {
			$this->scope   = $scope;
			$this->context = $context;
			$this->paths   = $paths;
		}

		/**
		 * Builds an event for a Throwable. The message is NOT read.
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

			$code = $e->getCode();

			return $this->build(
				self::symbol( get_class( $e ) ),
				null,
				$handled ? 'error' : 'fatal',
				$frames,
				$plugin_id,
				$handled,
				is_int( $code ) && 0 !== $code ? $code : null
			);
		}

		/**
		 * Builds an event for a fatal error taken from `error_get_last()`.
		 *
		 * An «Uncaught …» fatal carries the exception message and a textual call stack: the message
		 * is dropped (only «Uncaught <Class>» stays) and the stack is parsed back for file paths and
		 * function names (the argument text is dropped) so the scope filter sees the whole stack.
		 *
		 * @since 2.0.2
		 *
		 * @param array{type:int,message:string,file:string,line:int} $error The last-error array.
		 * @return array<string,mixed>|null Null when the error is not ours.
		 */
		public function from_fatal( array $error ): ?array {
			$raw    = (string) ( $error['message'] ?? '' );
			$frames = [
				[
					'file'     => (string) ( $error['file'] ?? '' ),
					'line'     => (int) ( $error['line'] ?? 0 ),
					'function' => '',
				],
			];

			if ( 0 === strpos( $raw, 'Uncaught ' ) ) {
				$value  = self::uncaught_value( $raw );
				$frames = array_merge( $frames, $this->frames_from_trace_text( $raw ) );
			} else {
				$value = $this->engine_message( $raw );
			}

			return $this->build( self::error_type_name( (int) ( $error['type'] ?? 0 ) ), $value, 'fatal', $frames, null, false, null );
		}

		/**
		 * «Uncaught <Class>» — the exception class out of an «Uncaught …» fatal, nothing else.
		 *
		 * @since 2.0.2
		 *
		 * @param string $raw The `error_get_last()` message.
		 * @return string
		 */
		public static function uncaught_value( string $raw ): string {
			if ( 1 === preg_match( '/^Uncaught ([A-Za-z_\\\\\x80-\xff][\w\\\\\x80-\xff]*(?:@anonymous)?)/', $raw, $match ) ) {
				return 'Uncaught ' . self::symbol( $match[1] );
			}

			return 'Uncaught exception';
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
			$file = Plugin_Scope::normalize_path( str_replace( "\0", '', $file ) );

			if ( '' === $file ) {
				return '[internal]';
			}

			$plugin_dir = $this->path_base( 'plugin_dir' );
			if ( '' !== $plugin_dir && 0 === strpos( $file, $plugin_dir . '/' ) ) {
				return 'plugins/' . substr( $file, strlen( $plugin_dir ) + 1 );
			}

			$abspath = $this->path_base( 'abspath' );
			if ( '' !== $abspath && 0 === strpos( $file, $abspath . '/' ) ) {
				return substr( $file, strlen( $abspath ) + 1 );
			}

			return '[external]/' . basename( $file );
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
		 * A class or function name made safe to send: an anonymous class name embeds a file path
		 * after a NUL byte, and nothing but identifier characters is kept.
		 *
		 * @since 2.0.2
		 *
		 * @param string $name Raw class / function name.
		 * @return string
		 */
		public static function symbol( string $name ): string {
			$nul = strpos( $name, "\0" );

			if ( false !== $nul ) {
				$name = substr( $name, 0, $nul );
			}

			// PHP 8.4 puts the declaring file into a closure's name: `{closure:/path/f.php:3}`.
			$name = (string) preg_replace( '/\{closure:[^}]*\}/', '{closure}', $name );
			$name = (string) preg_replace( '/[^\w\\\\:>@{}\x80-\xff.-]/', '', $name );

			return substr( $name, 0, self::MAX_SYMBOL );
		}

		/**
		 * @param string                                                 $type      Exception class or E_* name.
		 * @param string|null                                            $value     Engine message of a fatal, null for an exception.
		 * @param string                                                 $level     Sentry level.
		 * @param array<int,array{file:string,line:int,function:string}> $frames    Innermost first, complete.
		 * @param string|null                                            $plugin_id Explicit owner.
		 * @param bool                                                   $handled   Whether the code handled it.
		 * @param int|null                                               $code      Exception code.
		 * @return array<string,mixed>|null
		 */
		private function build( string $type, ?string $value, string $level, array $frames, ?string $plugin_id, bool $handled, ?int $code ): ?array {
			// Scope runs on the WHOLE trace: an owned frame beyond the kept 50 still owns the event.
			$owner = null !== $plugin_id ? $this->scope->get( $plugin_id ) : null;

			if ( null === $owner ) {
				$owner = $this->scope->match( array_column( $frames, 'file' ) );
			}

			if ( null === $owner ) {
				return null;
			}

			$out = [];

			// Sentry wants the OLDEST frame first.
			foreach ( array_reverse( array_slice( $frames, 0, self::MAX_FRAMES ) ) as $frame ) {
				$entry = [
					'filename' => $this->relative_path( $frame['file'] ),
					'lineno'   => $frame['line'],
					'in_app'   => $this->scope->contains( $frame['file'] ),
				];

				if ( '' !== $frame['function'] ) {
					$entry['function'] = $frame['function'];
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
				static function ( string $tag ): bool {
					return '' !== $tag;
				}
			);

			$mechanism = [
				'type'    => 'generic',
				'handled' => $handled,
			];

			if ( null !== $code ) {
				$mechanism['data'] = [ 'code' => $code ];
			}

			$exception = [
				'type' => $type,
			];

			if ( null !== $value ) {
				$exception['value'] = $value;
			}

			$exception['mechanism']  = $mechanism;
			$exception['stacktrace'] = [ 'frames' => $out ];

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
				'exception'   => [ 'values' => [ $exception ] ],
			];
		}

		/**
		 * Frames parsed out of the textual stack of an «Uncaught …» fatal.
		 *
		 * The LAST `Stack trace:` marker is used: an exception message can contain the marker (and
		 * fake `#0 …` lines), but the real trace always follows the message, so the last marker is
		 * the real one. A function token that is not a plain identifier is dropped.
		 *
		 * @param string $raw The `error_get_last()` message.
		 * @return array<int,array{file:string,line:int,function:string}>
		 */
		private function frames_from_trace_text( string $raw ): array {
			$marker = strrpos( $raw, "\nStack trace:" );

			if ( false === $marker || ! preg_match_all( '/^#\d+\s+(.+?)\((\d+)\):\s*([^\s(]+)/m', substr( $raw, $marker ), $matches, PREG_SET_ORDER ) ) {
				return [];
			}

			$frames = [];

			foreach ( $matches as $match ) {
				$frames[] = [
					'file'     => $match[1],
					'line'     => (int) $match[2],
					'function' => self::symbol( $match[3] ),
				];
			}

			return $frames;
		}

		/**
		 * An engine fatal message: sent as PHP wrote it, with absolute paths relativised (a failed
		 * `require` names its file), NUL bytes removed and the length capped.
		 *
		 * @param string $raw The `error_get_last()` message.
		 * @return string
		 */
		private function engine_message( string $raw ): string {
			$text = str_replace( "\0", '', $raw );
			$text = (string) preg_replace_callback(
				'~(?<![\w./])(?:[A-Za-z]:[\\\\/]|/)(?:[^\s\'"()<>:*?|\\\\/]+[\\\\/])*[^\s\'"()<>:*?|\\\\/]+~',
				function ( array $match ): string {
					return $this->relative_path( $match[0] );
				},
				$text
			);

			if ( strlen( $text ) <= self::MAX_TEXT ) {
				return $text;
			}

			// mb_strcut cuts on a character boundary; a half character would make the JSON invalid.
			return ( function_exists( 'mb_strcut' ) ? mb_strcut( $text, 0, self::MAX_TEXT, 'UTF-8' ) : substr( $text, 0, self::MAX_TEXT ) ) . '…';
		}

		/**
		 * @param array<string,mixed> $item One `getTrace()` entry.
		 * @return string `Class->method`, `Class::method` or `function`.
		 */
		private function function_name( array $item ): string {
			$type = isset( $item['class'] ) && in_array( $item['type'] ?? '', [ '->', '::' ], true ) ? $item['type'] : '';

			// Each part on its own: an anonymous class name is cut at its NUL byte, which must not
			// swallow the method name that follows it.
			return self::symbol( (string) ( $item['class'] ?? '' ) ) . $type . self::symbol( (string) ( $item['function'] ?? '' ) );
		}

		/**
		 * A path base normalized without a trailing slash.
		 *
		 * @param string $key `abspath` or `plugin_dir`.
		 * @return string
		 */
		private function path_base( string $key ): string {
			return rtrim( Plugin_Scope::normalize_path( (string) ( $this->paths[ $key ] ?? '' ) ), '/' );
		}
	}

endif;
