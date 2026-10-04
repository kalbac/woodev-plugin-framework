<?php
/**
 * Directory scope of the registered Woodev plugins.
 *
 * @package Woodev\Framework\Error_Reporting
 */

namespace Woodev\Framework\Error_Reporting;

use Woodev\Framework\Framework_Plugin_Loader_Definition;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\Woodev\Framework\Error_Reporting\Plugin_Scope' ) ) :

	/**
	 * Answers «does this stack touch one of OUR plugins, and which?».
	 *
	 * The roots come from the resolver's registry (each loader definition's plugin file →
	 * its directory) — never from a `/plugins/woodev-*` name pattern, because our plugins do
	 * not all carry that name and the framework is vendored INSIDE each of them (a frame in
	 * `<plugin>/woodev/...` is under the plugin root and so counts as that plugin's).
	 *
	 * @since 2.0.2
	 */
	final class Plugin_Scope {

		/**
		 * Roots sorted longest-first so a nested directory wins over its parent.
		 *
		 * @var array<int,array{id:string,version:string,root:string}>
		 */
		private array $roots = [];

		/**
		 * URL roots (browser side, #1081), longest-first. `root` is scheme-less and slash-terminated,
		 * `dir` is the plugin's directory name, `base` the original URL the browser is told.
		 *
		 * @var array<int,array{id:string,version:string,root:string,dir:string,base:string}>
		 */
		private array $url_roots = [];

		/**
		 * @param array<int,array{id:string,version:string,dir:string,url?:string}> $plugins Plugin id, version, directory and (optional) asset base URL.
		 */
		public function __construct( array $plugins ) {
			foreach ( $plugins as $plugin ) {
				$root = self::normalize_path( (string) $plugin['dir'] );
				$root = '' === $root ? '' : rtrim( $root, '/' ) . '/';

				// A single-file plugin sits directly in the plugins directory — its dirname would
				// claim every plugin on the site. Refuse a root that is that directory (or the
				// filesystem root).
				if ( strlen( $root ) < 2 || self::is_shared_plugins_dir( $root ) ) {
					continue;
				}

				$this->roots[] = [
					'id'      => (string) $plugin['id'],
					'version' => (string) $plugin['version'],
					'root'    => $root,
				];

				// The same plugin's URL root (browser side, #1081): kept only for a root the directory
				// check above accepted, so a refused plugin has no URL claim either.
				$url = self::normalize_url( (string) ( $plugin['url'] ?? '' ) );

				// A URL with no path (a bare host) would claim the whole site: refused.
				if ( '' !== $url && false !== strpos( substr( $url, 2 ), '/' ) ) {
					$this->url_roots[] = [
						'id'      => (string) $plugin['id'],
						'version' => (string) $plugin['version'],
						'root'    => $url . '/',
						'dir'     => basename( rtrim( $root, '/' ) ),
						'base'    => rtrim( (string) $plugin['url'], '/' ),
					];
				}
			}

			usort(
				$this->roots,
				static function ( array $a, array $b ): int {
					return strlen( $b['root'] ) <=> strlen( $a['root'] );
				}
			);

			usort(
				$this->url_roots,
				static function ( array $a, array $b ): int {
					return strlen( $b['root'] ) <=> strlen( $a['root'] );
				}
			);
		}

		/**
		 * Builds the scope from the resolver's registered-plugin arrays.
		 *
		 * @since 2.0.2
		 *
		 * @param array<int,array<string,mixed>> $registered {@see \Woodev\Framework\Framework_Resolver::get_registered_plugins()}.
		 * @return self
		 */
		public static function from_registered_plugins( array $registered ): self {
			$plugins = [];

			foreach ( $registered as $entry ) {
				$definition = $entry['definition'] ?? null;

				if ( ! $definition instanceof Framework_Plugin_Loader_Definition ) {
					continue;
				}

				$plugins[] = [
					'id'      => $definition->get_plugin_id(),
					'version' => $definition->get_plugin_version(),
					'dir'     => dirname( $definition->get_plugin_file() ),
					'url'     => self::plugin_url( $definition->get_plugin_file() ),
				];
			}

			return new self( $plugins );
		}

		/**
		 * The registered plugin with this id.
		 *
		 * @since 2.0.2
		 *
		 * @param string $plugin_id Plugin id.
		 * @return array{id:string,version:string}|null
		 */
		public function get( string $plugin_id ): ?array {
			foreach ( $this->roots as $entry ) {
				if ( $entry['id'] === $plugin_id ) {
					return [
						'id'      => $entry['id'],
						'version' => $entry['version'],
					];
				}
			}

			return null;
		}

		/**
		 * The owner of the first (innermost) file that lies under a registered plugin directory.
		 *
		 * @since 2.0.2
		 *
		 * @param string[] $files Absolute file paths, innermost frame first.
		 * @return array{id:string,version:string}|null Null when no file is ours.
		 */
		public function match( array $files ): ?array {
			foreach ( $files as $file ) {
				$owner = $this->owner_of( (string) $file );

				if ( null !== $owner ) {
					return $owner;
				}
			}

			return null;
		}

		/**
		 * Whether a single file lies under any registered plugin directory.
		 *
		 * @since 2.0.2
		 *
		 * @param string $file Absolute file path.
		 * @return bool
		 */
		public function contains( string $file ): bool {
			return null !== $this->owner_of( $file );
		}

		/**
		 * Which registered plugin a browser script URL belongs to, and where in it.
		 *
		 * Scheme and query/fragment are ignored (the browser's scheme may differ from the one
		 * `plugins_url()` produced; a query is a cache-buster at best and a leak at worst), the host is
		 * compared case-insensitively, a `..` or `.` path segment or a backslash refuses the URL.
		 * The returned path is anonymised like a PHP path (D5): `plugins/<dir>/…`.
		 *
		 * @since 2.0.2
		 *
		 * @param string $url Script URL as the browser reported it.
		 * @return array{id:string,version:string,path:string}|null Null when the URL is not under a registered plugin.
		 */
		public function locate_url( string $url ): ?array {
			$normalized = self::normalize_url( $url );

			if ( '' === $normalized ) {
				return null;
			}

			foreach ( $this->url_roots as $entry ) {
				if ( 0 === strpos( $normalized, $entry['root'] ) && strlen( $normalized ) > strlen( $entry['root'] ) ) {
					return [
						'id'      => $entry['id'],
						'version' => $entry['version'],
						'path'    => 'plugins/' . $entry['dir'] . '/' . substr( $normalized, strlen( $entry['root'] ) ),
					];
				}
			}

			return null;
		}

		/**
		 * The asset base URL of every registered plugin — what the browser filters its own errors by.
		 *
		 * @since 2.0.2
		 *
		 * @return string[]
		 */
		public function url_bases(): array {
			return array_values( array_unique( array_column( $this->url_roots, 'base' ) ) );
		}

		/**
		 * A script URL reduced to `//host/path`: no scheme, no query, no fragment, no trailing slash.
		 *
		 * @since 2.0.2
		 *
		 * @param string $url Raw URL.
		 * @return string Empty when it is not an http(s) / scheme-relative URL, or has a dot segment.
		 */
		public static function normalize_url( string $url ): string {
			$url = (string) preg_replace( '/[?#].*$/s', '', trim( $url ) );

			if ( 1 !== preg_match( '#^(?:https?:)?//([^/\\\\\s]+)((?:/[^/\\\\\s]*)*)$#i', $url, $match ) ) {
				return '';
			}

			if ( 1 === preg_match( '#/\.\.?(?:/|$)#', $match[2] ) || false !== stripos( $match[2], '%2e' ) ) {
				return '';
			}

			return rtrim( '//' . strtolower( $match[1] ) . $match[2], '/' );
		}

		/**
		 * Forward slashes, no `..` games — good enough for prefix comparison of PHP-reported paths.
		 *
		 * @since 2.0.2
		 *
		 * @param string $path Raw path.
		 * @return string
		 */
		public static function normalize_path( string $path ): string {
			return str_replace( '\\', '/', $path );
		}

		/**
		 * @param string $file Absolute file path.
		 * @return array{id:string,version:string}|null
		 */
		private function owner_of( string $file ): ?array {
			$file = self::normalize_path( $file );

			if ( '' === $file ) {
				return null;
			}

			foreach ( $this->roots as $entry ) {
				if ( 0 === strpos( $file, $entry['root'] ) ) {
					return [
						'id'      => $entry['id'],
						'version' => $entry['version'],
					];
				}
			}

			return null;
		}

		/**
		 * Whether a normalized, slash-terminated root is the shared plugins directory itself.
		 *
		 * @param string $root Normalized root.
		 * @return bool
		 */
		private static function is_shared_plugins_dir( string $root ): bool {
			foreach ( [ 'WP_PLUGIN_DIR', 'WPMU_PLUGIN_DIR' ] as $constant ) {
				if ( defined( $constant ) && rtrim( self::normalize_path( (string) constant( $constant ) ), '/' ) . '/' === $root ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * The asset base URL of a plugin: `plugins_url( '', $plugin_file )`, or '' when it cannot be told.
		 *
		 * Never throws: the scope is built while the plugins are still loading, and a failure here must
		 * cost the browser reporter one plugin, not the PHP reporter its scope.
		 *
		 * @param string $plugin_file Absolute path of the plugin's main file.
		 * @return string
		 */
		private static function plugin_url( string $plugin_file ): string {
			try {
				return function_exists( 'plugins_url' ) ? (string) plugins_url( '', $plugin_file ) : '';
			} catch ( \Throwable $e ) {
				return '';
			}
		}
	}

endif;
