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
		 * @param array<int,array{id:string,version:string,dir:string}> $plugins Plugin id, version and directory.
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
			}

			usort(
				$this->roots,
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
	}

endif;
