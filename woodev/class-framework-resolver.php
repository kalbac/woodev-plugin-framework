<?php
/**
 * Platform v2 minimal framework resolver.
 *
 * @package Woodev\Framework
 */

namespace Woodev\Framework;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Framework_Resolver::class, false ) ) :

	/**
	 * Resolves registered framework copies and invokes compatible plugin callbacks.
	 *
	 * This class owns early loading infrastructure only. Runtime behavior stays in
	 * platform plugin classes and specialized modules.
	 *
	 * @since 2.0.0
	 */
	class Framework_Resolver {

		/** @var array<int,array<string,mixed>> Registered plugin arrays. */
		protected array $registered_plugins = [];

		/** @var array<int,array<string,mixed>> Active plugin arrays. */
		protected array $active_plugins = [];

		/** @var array<int,array<string,mixed>> Framework-incompatible plugins. */
		protected array $incompatible_framework_plugins = [];

		/** @var array<int,array<string,mixed>> WooCommerce-incompatible plugins. */
		protected array $incompatible_wc_version_plugins = [];

		/** @var array<int,array<string,mixed>> WordPress-incompatible plugins. */
		protected array $incompatible_wp_version_plugins = [];

		/** @var array<int,array<string,mixed>> PHP-incompatible plugins. */
		protected array $incompatible_php_version_plugins = [];

		/** @var array<int,array<string,mixed>> Invalid loader definitions. */
		protected array $invalid_loader_definitions = [];

		/** @var array<string,bool> Plugin IDs already registered — prevents duplicates from colliding on options, cron, license keys, and logger handles. */
		protected array $plugin_ids = [];

		/** @var bool Guards load_plugins() against double execution in long-running processes (WP-Cron, Action Scheduler). */
		protected bool $loaded = false;

		/** @var callable Wired to admin_notices when incompatible plugins are registered. Defaults to a no-op so the resolver stays decoupled from the legacy bootstrap. */
		protected $update_notice_renderer;

		/** @var callable Wired to admin_notices when the deactivation action is requested. Defaults to a no-op for the same reason. */
		protected $deactivation_notice_renderer;

		/**
		 * Constructor.
		 *
		 * @since 2.0.0
		 *
		 * @param callable|null $update_notice_renderer       Callback wired to admin_notices when incompatible plugins are registered. Default no-op keeps the resolver decoupled from the legacy bootstrap.
		 * @param callable|null $deactivation_notice_renderer Callback wired to admin_notices when the framework deactivation action is requested. Default no-op for the same reason.
		 */
		public function __construct( ?callable $update_notice_renderer = null, ?callable $deactivation_notice_renderer = null ) {
			$this->update_notice_renderer       = $update_notice_renderer ?? static function (): void {};
			$this->deactivation_notice_renderer = $deactivation_notice_renderer ?? static function (): void {};
		}

		/**
		 * Registers an explicit Platform v2 loader definition.
		 *
		 * @since 2.0.0
		 *
		 * @param array<string,mixed> $definition Raw loader definition.
		 * @return bool True when the definition is accepted.
		 */
		public function register_loader_definition( array $definition ): bool {
			$errors            = [];
			$loader_definition = Framework_Plugin_Loader_Definition::from_array( $definition, $errors );

			if ( null === $loader_definition ) {
				$this->invalid_loader_definitions[] = [
					'definition' => $definition,
					'errors'     => $errors,
				];

				return false;
			}

			if ( isset( $this->plugin_ids[ $loader_definition->get_plugin_id() ] ) ) {
				$this->invalid_loader_definitions[] = [
					'definition' => $definition,
					'errors'     => [ sprintf( 'Duplicate plugin_id: %s.', $loader_definition->get_plugin_id() ) ],
				];

				return false;
			}

			$this->plugin_ids[ $loader_definition->get_plugin_id() ] = true;
			$this->registered_plugins[]                              = $loader_definition->to_legacy_plugin();

			return true;
		}

		/**
		 * Loads compatible registered plugins.
		 *
		 * @since 2.0.0
		 *
		 * @return void
		 */
		public function load_plugins(): void {
			if ( $this->loaded ) {
				return;
			}

			$this->loaded = true;

			usort( $this->registered_plugins, [ $this, 'framework_compare' ] );

			$loaded_framework = null;
			foreach ( $this->registered_plugins as $plugin ) {
				if ( null === $loaded_framework ) {
					$loaded_framework = $plugin;

					// Register the framework autoloader against the winning copy (first after
					// the version sort) BEFORE any typed plugin class (`extends ...`) is parsed.
					// This is what lets a plugin declare its type by inheritance alone — no
					// capabilities hint, no hard-coded base-class requires.
					$winner_path = $this->get_plugin_path( $loaded_framework['path'] );
					$autoloader  = $winner_path . '/woodev/class-framework-autoloader.php';

					if ( ! class_exists( 'Woodev_Framework_Autoloader', false ) && is_readable( $autoloader ) ) {
						require_once $autoloader;
					}

					if ( class_exists( 'Woodev_Framework_Autoloader', false ) ) {
						\Woodev_Framework_Autoloader::register( $winner_path );
					}
				}

				$is_base_plugin_loaded = class_exists( '\Woodev_Plugin', false );
				if ( ! $is_base_plugin_loaded ) {
					require_once $this->get_plugin_path( $plugin['path'] ) . '/woodev/class-plugin.php';
				}

				$backwards_compatible     = $loaded_framework['args']['backwards_compatible'] ?? '';
				$is_framework_incompatible = '' !== $backwards_compatible && version_compare( $backwards_compatible, $plugin['version'], '>' );
				if ( $is_framework_incompatible ) {
					$this->incompatible_framework_plugins[] = $plugin;
					continue;
				}

				if ( $this->fails_php_requirement( $plugin ) ) {
					$this->incompatible_php_version_plugins[] = $plugin;
					continue;
				}

				if ( $this->fails_wordpress_requirement( $plugin ) ) {
					$this->incompatible_wp_version_plugins[] = $plugin;
					continue;
				}

				if ( $this->fails_woocommerce_requirement( $plugin ) ) {
					$this->incompatible_wc_version_plugins[] = $plugin;
					continue;
				}

				if ( ! $this->invoke_plugin( $plugin ) ) {
					continue;
				}

				if ( ! in_array( $plugin, $this->active_plugins, true ) ) {
					$this->active_plugins[] = $plugin;
				}
			}

			if ( $this->has_update_notices() && is_admin() && ! defined( 'DOING_AJAX' ) && ! has_action( 'admin_notices', $this->update_notice_renderer ) ) {
				add_action( 'admin_notices', $this->update_notice_renderer );
			}

			do_action( 'woodev_plugins_loaded' );
		}

		/**
		 * Handles the compatibility deactivation action.
		 *
		 * @since 2.0.0
		 *
		 * @return void
		 */
		public function maybe_deactivate_framework_plugins(): void {
			if ( ! isset( $_GET['woodev_framework_deactivate_newer'] ) ) {
				return;
			}

			if ( 'yes' === sanitize_text_field( $_GET['woodev_framework_deactivate_newer'] ) ) {
				if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'woodev_framework_deactivate' ) ) {
					return;
				}

				if ( 0 === count( $this->incompatible_framework_plugins ) ) {
					return;
				}

				$plugins = [];

				foreach ( $this->active_plugins as $plugin ) {
					$plugins[] = plugin_basename( $plugin['path'] );
				}

				deactivate_plugins( $plugins );

				wp_safe_redirect(
					add_query_arg(
						[
							'plugin_status'                     => 'inactive',
							'woodev_framework_deactivate_newer' => count( $plugins ),
						],
						admin_url( 'plugins.php' )
					)
				);

				exit;
			}

			add_action( 'admin_notices', $this->deactivation_notice_renderer );
		}

		/**
		 * Renders update notices for incompatible registrations.
		 *
		 * @since 2.0.0
		 *
		 * @return void
		 */
		public function render_update_notices(): void {
			// Must update plugin notice.
			if ( ! empty( $this->incompatible_framework_plugins ) ) {
				$incompatible_plugin_count = count( $this->incompatible_framework_plugins );
				$active_plugin_count       = count( $this->active_plugins );

				$message  = '<p>';
				$message .= sprintf(
					_n( '%1$sAttention!%2$s The plugin %3$s was disabled because it is out of date and incompatible with the', '%1$sAttention!%2$s The plugins %3$s were disabled because they are out of date and incompatible with the', $incompatible_plugin_count, 'woodev-plugin-framework' ),
					'<strong>',
					'</strong>',
					\Woodev_Helper::list_array_items(
						array_map(
							function ( $plugin ) {
								return sprintf( '<strong>%s</strong>', esc_html( $plugin['plugin_name'] ) );
							},
							$this->incompatible_framework_plugins
						)
					)
				);
				$message .= sprintf(
					_n( ' newer plugin %s.', ' newer plugins %s.', $active_plugin_count, 'woodev-plugin-framework' ),
					\Woodev_Helper::list_array_items(
						array_map(
							function ( $plugin ) {
								return sprintf( '<strong>%s</strong>', esc_html( $plugin['plugin_name'] ) );
							},
							$this->active_plugins
						)
					)
				);
				$message .= '</p><p>';
				$message .= sprintf(
					__( 'To resolve this, please %1$supdate%2$s (recommended) or %1$sdeactivate%2$s', 'woodev-plugin-framework' ),
					'<a href="' . esc_url( admin_url( 'update-core.php' ) ) . '">',
					'</a>'
				);
				$message .= sprintf(
					_n( ' the plugin %1$s, or %2$sdeactivate%3$s', ' the plugins %1$s, or %2$sdeactivate%3$s', $incompatible_plugin_count, 'woodev-plugin-framework' ),
					\Woodev_Helper::list_array_items(
						array_map(
							function ( $plugin ) {
								return sprintf( '<strong>%s</strong>', esc_html( $plugin['plugin_name'] ) );
							},
							$this->incompatible_framework_plugins
						)
					),
					'<a href="' . esc_url( wp_nonce_url( admin_url( 'plugins.php?woodev_framework_deactivate_newer=yes' ), 'woodev_framework_deactivate' ) ) . '">',
					'</a>'
				);
				$message .= sprintf(
					_n( ' the plugin %s.', ' the plugins %s.', $active_plugin_count, 'woodev-plugin-framework' ),
					\Woodev_Helper::list_array_items(
						array_map(
							function ( $plugin ) {
								return sprintf( '<strong>%s</strong>', esc_html( $plugin['plugin_name'] ) );
							},
							$this->active_plugins
						)
					)
				);
				$message .= '</p>';

				echo '<div class="error">';
				echo $message;
				echo '</div>';
			}

			if ( ! empty( $this->incompatible_php_version_plugins ) ) {
				printf( '<div class="error"><p>%s</p><ul>', count( $this->incompatible_php_version_plugins ) > 1 ? esc_html__( 'The following plugins are inactive because they require a newer version of PHP:', 'woodev-plugin-framework' ) : esc_html__( 'The following plugin is inactive because it requires a newer version of PHP:', 'woodev-plugin-framework' ) );

				foreach ( $this->incompatible_php_version_plugins as $plugin ) {
					echo '<li>' . sprintf( esc_html__( '%1$s requires PHP %2$s or newer', 'woodev-plugin-framework' ), esc_html( $plugin['plugin_name'] ), esc_html( $plugin['args']['minimum_php_version'] ) ) . '</li>';
				}

				echo '</ul></div>';
			}

			if ( ! empty( $this->incompatible_wc_version_plugins ) ) {
				printf( '<div class="error"><p>%s</p><ul>', count( $this->incompatible_wc_version_plugins ) > 1 ? esc_html__( 'The following plugins are inactive because they require a newer version of WooCommerce:', 'woodev-plugin-framework' ) : esc_html__( 'The following plugin is inactive because it requires a newer version of WooCommerce:', 'woodev-plugin-framework' ) );

				foreach ( $this->incompatible_wc_version_plugins as $plugin ) {
					/* translators: Placeholders: %1$s - plugin name, %2$s - WooCommerce version number */
					echo '<li>' . sprintf( esc_html__( '%1$s requires WooCommerce %2$s or newer', 'woodev-plugin-framework' ), esc_html( $plugin['plugin_name'] ), esc_html( $plugin['args']['minimum_wc_version'] ) ) . '</li>';
				}

				/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag */
				echo '</ul><p>' . sprintf( esc_html__( 'Please %1$supdate WooCommerce%2$s', 'woodev-plugin-framework' ), '<a href="' . esc_url( admin_url( 'update-core.php' ) ) . '">', '&nbsp;&raquo;</a>' ) . '</p></div>';
			}

			if ( ! empty( $this->incompatible_wp_version_plugins ) ) {
				printf( '<div class="error"><p>%s</p>', count( $this->incompatible_wp_version_plugins ) > 1 ? esc_html__( 'The following plugins are inactive because they require a newer version of WordPress:', 'woodev-plugin-framework' ) : esc_html__( 'The following plugin is inactive because it requires a newer version of WordPress:', 'woodev-plugin-framework' ) );
				echo '<ul>';

				foreach ( $this->incompatible_wp_version_plugins as $plugin ) {
					echo '<li>' . sprintf( esc_html__( '%1$s requires WordPress %2$s or newer', 'woodev-plugin-framework' ), esc_html( $plugin['plugin_name'] ), esc_html( $plugin['args']['minimum_wp_version'] ) ) . '</li>';
				}

				echo '</ul>';
				echo '<p>' . sprintf( esc_html__( 'Please %1$supdate WordPress%2$s', 'woodev-plugin-framework' ), '<a href="' . esc_url( admin_url( 'update-core.php' ) ) . '">', '&nbsp;&raquo;</a>' ) . '</p></div>';
			}
		}

		/**
		 * Gets registered plugin arrays.
		 *
		 * @since 2.0.0
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public function get_registered_plugins(): array {
			return $this->registered_plugins;
		}

		/**
		 * Gets active plugin arrays.
		 *
		 * @since 2.0.0
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public function get_active_plugins(): array {
			return $this->active_plugins;
		}

		/**
		 * Gets framework-incompatible plugin arrays.
		 *
		 * @since 2.0.0
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public function get_incompatible_framework_plugins(): array {
			return $this->incompatible_framework_plugins;
		}

		/**
		 * Gets WooCommerce-incompatible plugin arrays.
		 *
		 * @since 2.0.0
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public function get_incompatible_wc_version_plugins(): array {
			return $this->incompatible_wc_version_plugins;
		}

		/**
		 * Gets WordPress-incompatible plugin arrays.
		 *
		 * @since 2.0.0
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public function get_incompatible_wp_version_plugins(): array {
			return $this->incompatible_wp_version_plugins;
		}

		/**
		 * Gets PHP-incompatible plugin arrays.
		 *
		 * @since 2.0.0
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public function get_incompatible_php_version_plugins(): array {
			return $this->incompatible_php_version_plugins;
		}

		/**
		 * Gets invalid loader definitions.
		 *
		 * @since 2.0.0
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public function get_invalid_loader_definitions(): array {
			return $this->invalid_loader_definitions;
		}

		/**
		 * Compares two framework versions for highest-first sorting.
		 *
		 * @since 2.0.0
		 *
		 * @param array $a First registered plugin.
		 * @param array $b Second registered plugin.
		 * @return int
		 */
		public function framework_compare( array $a, array $b ): int {
			return version_compare( $b['version'], $a['version'] );
		}

		/**
		 * Returns the plugin path for a plugin file.
		 *
		 * @since 2.0.0
		 *
		 * @param string $file Plugin file.
		 * @return string
		 */
		public function get_plugin_path( string $file ): string {
			return untrailingslashit( plugin_dir_path( $file ) );
		}

		/**
		 * Gets the currently loaded framework version.
		 *
		 * @since 2.0.0
		 *
		 * @return string
		 */
		public function get_framework_version(): string {
			$is_base_plugin_loaded = class_exists( '\Woodev_Plugin', false );
			return $is_base_plugin_loaded ? \Woodev_Plugin::VERSION : '';
		}

		/**
		 * Checks whether a plugin fails the PHP requirement.
		 *
		 * @since 2.0.0
		 *
		 * @param array<string,mixed> $plugin Registered plugin.
		 * @return bool
		 */
		protected function fails_php_requirement( array $plugin ): bool {
			$definition = $plugin['definition'] ?? null;

			if ( ! $definition instanceof Framework_Plugin_Loader_Definition ) {
				return false;
			}

			$requirements = $definition->get_requirements();
			$minimum      = $requirements['php'] ?? null;

			if ( null === $minimum || '0' === $minimum ) {
				return false;
			}

			return version_compare( PHP_VERSION, $minimum, '<' );
		}

		/**
		 * Checks whether a plugin fails the WordPress requirement.
		 *
		 * @since 2.0.0
		 *
		 * @param array<string,mixed> $plugin Registered plugin.
		 * @return bool
		 */
		protected function fails_wordpress_requirement( array $plugin ): bool {
			if ( empty( $plugin['args']['minimum_wp_version'] ) ) {
				return false;
			}

			return version_compare( get_bloginfo( 'version' ), $plugin['args']['minimum_wp_version'], '<' );
		}

		/**
		 * Checks whether a plugin fails the WooCommerce requirement.
		 *
		 * @since 2.0.0
		 *
		 * @param array<string,mixed> $plugin Registered plugin.
		 * @return bool
		 */
		protected function fails_woocommerce_requirement( array $plugin ): bool {
			$definition = $plugin['definition'] ?? null;

			if ( ! $definition instanceof Framework_Plugin_Loader_Definition ) {
				return false;
			}

			if ( Framework_Plugin_Loader_Definition::PLATFORM_WOOCOMMERCE !== $definition->get_platform() ) {
				return false;
			}

			$requirements = $definition->get_requirements();
			$minimum      = $requirements['woocommerce'] ?? null;

			if ( null === $minimum ) {
				return false;
			}

			$current = $this->get_wc_version();

			if ( null === $current ) {
				return true;
			}

			return version_compare( $current, $minimum, '<' );
		}

		/**
		 * Invokes a registered plugin callback or main class bootstrap method.
		 *
		 * @since 2.0.0
		 *
		 * @param array<string,mixed> $plugin Registered plugin.
		 * @return bool True when a plugin callback or main class was invoked.
		 */
		protected function invoke_plugin( array $plugin ): bool {
			if ( is_callable( $plugin['callback'] ) ) {
				$plugin['callback']();
				return true;
			}

			$definition = $plugin['definition'] ?? null;
			if ( ! $definition instanceof Framework_Plugin_Loader_Definition ) {
				return false;
			}

			$main_class = $definition->get_main_class();
			if ( null === $main_class || ! class_exists( $main_class ) ) {
				$this->invalid_loader_definitions[] = [
					'definition' => $definition,
					'errors'     => [ sprintf( 'Loader definition main_class does not exist: %s.', (string) $main_class ) ],
				];
				return false;
			}

			if ( is_callable( [ $main_class, 'instance' ] ) ) {
				$main_class::instance();
				return true;
			}

			new $main_class();
			return true;
		}

		/**
		 * Determines whether any update notices are pending.
		 *
		 * @since 2.0.0
		 *
		 * @return bool
		 */
		protected function has_update_notices(): bool {
			return $this->incompatible_framework_plugins || $this->incompatible_wc_version_plugins || $this->incompatible_wp_version_plugins || $this->incompatible_php_version_plugins;
		}

		/**
		 * Gets the WooCommerce version number.
		 *
		 * @since 2.0.0
		 *
		 * @return string|null
		 */
		protected function get_wc_version(): ?string {
			if ( defined( 'WC_VERSION' ) && WC_VERSION ) {
				return WC_VERSION;
			}

			return null;
		}
	}

endif;
