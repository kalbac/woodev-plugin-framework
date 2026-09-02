<?php
/**
 * Thin plugin entry facade.
 *
 * @package Woodev\Framework
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Loader', false ) ) :

	/**
	 * Centralizes plugin entry boilerplate: framework-dir resolution, bootstrap require,
	 * the B-1 mixed-fleet probe, and loader-definition registration.
	 *
	 * A plugin entry file requires this from its bundled framework copy and registers itself:
	 *
	 *     require_once __DIR__ . '/woodev/loader.php';
	 *     Woodev_Loader::register( __FILE__, [ ...definition without plugin_file/capabilities... ] );
	 *
	 * Plugin type is declared by the `extends` in the registered class — never in the definition.
	 *
	 * @since 2.0.2
	 */
	final class Woodev_Loader {

		/**
		 * Fleet-wide accumulator of plugins left dormant by a legacy (v1) rendezvous winner
		 * (OB-1 / #104). Woodev_Loader is final and guarded by `class_exists(…, false)`, so
		 * exactly one copy of this class — and this property — exists per request no matter
		 * how many v2 plugins are installed; unlike a per-plugin object, a static property
		 * here really is shared across the whole fleet.
		 *
		 * @var array<int,array{plugin_file:string,fallback_name:string}>
		 */
		private static array $dormant_plugins = [];

		/**
		 * Whether the fleet-wide OB-1 dormant notice has already been hooked this request.
		 *
		 * @var bool
		 */
		private static bool $dormant_notice_hooked = false;

		/**
		 * Registers a plugin with the framework bootstrap.
		 *
		 * @since 2.0.2
		 *
		 * @param string              $plugin_file The plugin entry __FILE__.
		 * @param array<string,mixed> $definition  Loader definition (without 'plugin_file').
		 * @return bool True when registered; false when dormant (legacy copy won) or unreadable.
		 */
		public static function register( string $plugin_file, array $definition ): bool {
			$framework_dir = defined( 'WOODEV_FRAMEWORK_DIR' )
				? (string) constant( 'WOODEV_FRAMEWORK_DIR' )
				: dirname( $plugin_file );

			$bootstrap = rtrim( $framework_dir, '/\\' ) . '/woodev/bootstrap.php';

			if ( ! is_readable( $bootstrap ) ) {
				return false;
			}

			if ( ! class_exists( 'Woodev_Plugin_Bootstrap', false ) ) {
				require_once $bootstrap;
			}

			$instance = \Woodev_Plugin_Bootstrap::instance();

			// B-1 mixed-fleet probe: a legacy v1 copy won the class rendezvous and has no
			// register_loader_definition(). Stay dormant, but report it (OB-1 / #104) — the
			// merchant must not be told nothing.
			if ( ! method_exists( $instance, 'register_loader_definition' ) ) {
				self::record_dormant_plugin( $plugin_file, $definition );

				return false;
			}

			$definition['plugin_file'] = $plugin_file;

			return (bool) $instance->register_loader_definition( $definition );
		}


		/**
		 * Records a v2 plugin left dormant by a legacy (v1) rendezvous winner and hooks the
		 * fleet-wide OB-1 admin notice once (#104).
		 *
		 * WordPress core + plain PHP only, exactly like the probe above: the v2 framework
		 * runtime is NOT loaded on this path — a legacy v1 copy is in charge of
		 * Woodev_Plugin_Bootstrap for the whole request.
		 *
		 * @since 2.0.3
		 *
		 * @param string              $plugin_file The dormant plugin's entry __FILE__.
		 * @param array<string,mixed> $definition  The definition passed to register().
		 * @return void
		 */
		private static function record_dormant_plugin( string $plugin_file, array $definition ): void {
			$fallback_name = isset( $definition['plugin_name'] ) && is_string( $definition['plugin_name'] )
				? $definition['plugin_name']
				: '';

			self::$dormant_plugins[] = [
				'plugin_file'   => $plugin_file,
				'fallback_name' => $fallback_name,
			];

			if ( self::$dormant_notice_hooked || ! is_admin() || defined( 'DOING_AJAX' ) ) {
				return;
			}

			self::$dormant_notice_hooked = true;
			add_action( 'admin_notices', [ __CLASS__, 'render_dormant_notice' ] );
		}

		/**
		 * Renders the fleet-wide OB-1 dormant notice: names every quarantined v2 plugin and,
		 * best-effort, the legacy (v1) plugin whose framework copy is blocking them (#104).
		 *
		 * @since 2.0.3
		 *
		 * @return void
		 */
		public static function render_dormant_notice(): void {
			if ( empty( self::$dormant_plugins ) ) {
				return;
			}

			$names = array_map(
				static function ( array $dormant ): string {
					return sprintf(
						'<strong>%s</strong>',
						esc_html( self::dormant_plugin_display_name( $dormant['plugin_file'], $dormant['fallback_name'] ) )
					);
				},
				self::$dormant_plugins
			);

			$count = count( $names );

			if ( $count > 1 ) {
				$last_name   = (string) array_pop( $names );
				$conjunction = _x( 'и', 'coordinating conjunction for a list of items: a, b и c', 'woodev-plugin-framework' );
				$name_list   = implode( ', ', $names ) . ' ' . $conjunction . ' ' . $last_name;
			} else {
				$name_list = (string) reset( $names );
			}

			$conflicting_name = self::resolve_conflicting_plugin_name();

			if ( '' !== $conflicting_name ) {
				$conflicting_markup = sprintf( '<strong>%s</strong>', esc_html( $conflicting_name ) );

				$message = $count > 1
					? sprintf(
						/* translators: 1: list of dormant plugin names, 2: the conflicting plugin name. */
						__( 'Плагины %1$s не запущены: на сайте активен плагин %2$s с устаревшей версией, который блокирует их запуск. Обновите %2$s до последней версии.', 'woodev-plugin-framework' ),
						$name_list,
						$conflicting_markup
					)
					: sprintf(
						/* translators: 1: dormant plugin name, 2: the conflicting plugin name. */
						__( 'Плагин %1$s не запущен: на сайте активен плагин %2$s с устаревшей версией, который блокирует его запуск. Обновите %2$s до последней версии.', 'woodev-plugin-framework' ),
						$name_list,
						$conflicting_markup
					);
			} else {
				$message = $count > 1
					? sprintf(
						/* translators: %s — list of dormant plugin names. */
						__( 'Плагины %s не запущены: на сайте активен другой плагин Woodev с устаревшей версией. Обновите все плагины Woodev до последней версии.', 'woodev-plugin-framework' ),
						$name_list
					)
					: sprintf(
						/* translators: %s — the dormant plugin name. */
						__( 'Плагин %s не запущен: на сайте активен другой плагин Woodev с устаревшей версией. Обновите все плагины Woodev до последней версии.', 'woodev-plugin-framework' ),
						$name_list
					);
			}

			echo '<div class="error"><p>';
			echo wp_kses( $message, [ 'strong' => [] ] );
			echo '</p></div>';
		}

		/**
		 * Best-effort resolves a dormant plugin's display name.
		 *
		 * Tries the plugin's own header via get_plugin_data() first (the authoritative WP
		 * source); falls back to the name the plugin declared in its register() definition;
		 * falls back to its bare filename as a last, always-truthful resort. Never guesses.
		 *
		 * @since 2.0.3
		 *
		 * @param string $plugin_file   The dormant plugin's entry __FILE__.
		 * @param string $fallback_name The plugin_name from its definition, or ''.
		 * @return string
		 */
		private static function dormant_plugin_display_name( string $plugin_file, string $fallback_name ): string {
			if ( function_exists( 'get_plugin_data' ) && is_readable( $plugin_file ) ) {
				$data = get_plugin_data( $plugin_file, false, false );

				if ( ! empty( $data['Name'] ) ) {
					return (string) $data['Name'];
				}
			}

			if ( '' !== $fallback_name ) {
				return $fallback_name;
			}

			return basename( $plugin_file );
		}

		/**
		 * Best-effort resolves the display name of the plugin whose outdated (v1) framework
		 * copy won the Woodev_Plugin_Bootstrap class rendezvous (B-1 / OB-1 / #104).
		 *
		 * Mirrors the resolution the legacy entry-file template already uses: maps the
		 * winning bootstrap.php's own file (via reflection) back to a plugins-dir slug, then
		 * looks up that slug's display name via get_plugins(). WordPress core + reflection
		 * only — never a framework class, since the loaded runtime here is the legacy v1
		 * copy. Returns '' when the owner cannot be determined; the caller then falls back to
		 * generic, still-truthful wording rather than printing a path or a guess.
		 *
		 * @since 2.0.3
		 *
		 * @return string Conflicting plugin display name, or '' if undeterminable.
		 */
		private static function resolve_conflicting_plugin_name(): string {
			if ( ! class_exists( 'Woodev_Plugin_Bootstrap', false ) || ! defined( 'WP_PLUGIN_DIR' ) || ! function_exists( 'wp_normalize_path' ) || ! function_exists( 'get_plugins' ) ) {
				return '';
			}

			try {
				$framework_file = ( new \ReflectionClass( 'Woodev_Plugin_Bootstrap' ) )->getFileName();
			} catch ( \ReflectionException $e ) {
				return '';
			}

			$plugins_dir = constant( 'WP_PLUGIN_DIR' );

			if ( ! is_string( $framework_file ) || '' === $framework_file || ! is_string( $plugins_dir ) || '' === $plugins_dir ) {
				return '';
			}

			$framework_file = wp_normalize_path( $framework_file );
			$plugins_dir    = wp_normalize_path( $plugins_dir );

			if ( 0 !== strpos( $framework_file, $plugins_dir . '/' ) ) {
				return '';
			}

			$relative = ltrim( substr( $framework_file, strlen( $plugins_dir ) ), '/' );
			$slug     = strstr( $relative . '/', '/', true );

			if ( ! is_string( $slug ) || '' === $slug ) {
				return '';
			}

			foreach ( get_plugins() as $plugin_relative_file => $plugin_data ) {
				if ( 0 === strpos( (string) $plugin_relative_file, $slug . '/' ) && ! empty( $plugin_data['Name'] ) ) {
					return (string) $plugin_data['Name'];
				}
			}

			return '';
		}
	}

endif;
