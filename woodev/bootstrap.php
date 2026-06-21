<?php

defined( 'ABSPATH' ) || exit;

use Woodev\Framework\Framework_Resolver;

require_once __DIR__ . '/class-framework-plugin-loader-definition.php';
require_once __DIR__ . '/class-framework-resolver.php';

if ( ! class_exists( 'Woodev_Plugin_Bootstrap' ) ) :

	class Woodev_Plugin_Bootstrap {

		/** @var Woodev_Plugin_Bootstrap The single instance of the class */
		protected static $instance = null;

		/** @var Framework_Resolver Minimal framework resolver. */
		protected Framework_Resolver $resolver;

		/** @var array registered framework plugins */
		protected array $registered_plugins = [];

		/** @var array registered and active framework plugins */
		protected array $active_plugins = [];

		/** @var array of plugins that need to be updated due to an outdated framework */
		protected array $incompatible_framework_plugins = [];

		/** @var array of plugins that require a newer version of WC */
		protected array $incompatible_wc_version_plugins = [];

		/** @var array of plugins that require a newer version of WP */
		protected array $incompatible_wp_version_plugins = [];

		/** @var array of plugins that require a newer version of PHP */
		protected array $incompatible_php_version_plugins = [];

		/** @var array invalid explicit loader definitions */
		protected array $invalid_loader_definitions = [];

		/** @var array<int,array<string,string>> Legacy (v1) plugins quarantined by the mixed-fleet tombstone — see B-1. */
		protected array $mixed_fleet_incompatible_plugins = [];

		/** @var bool Whether the mixed-fleet admin notice has already been hooked — idempotency guard (B-1). */
		private bool $mixed_fleet_notice_hooked = false;

		/**
		 * Hidden constructor.
		 */
		private function __construct() {
			$this->resolver = new Framework_Resolver(
				[ $this, 'render_update_notices' ],
				[ $this, 'render_deactivation_notice' ]
			);

			add_action( 'plugins_loaded', [ $this, 'load_plugins' ] );
			add_action( 'admin_init', [ $this, 'maybe_deactivate_framework_plugins' ] );
		}

		/**
		 * Instantiate the class singleton.
		 *
		 * @return Woodev_Plugin_Bootstrap singleton instance
		 */
		public static function instance(): self {
			return self::$instance ??= new self();
		}

		/**
		 * Registers an explicit Platform v2 loader definition.
		 *
		 * @since 2.0.0
		 *
		 * @param array<string,mixed> $definition Loader definition.
		 * @return bool
		 */
		public function register_loader_definition( array $definition ): bool {
			$accepted = $this->resolver->register_loader_definition( $definition );

			if ( $accepted ) {
				$this->register_early_woocommerce_feature_compatibility( $definition );
			}

			$this->sync_resolver_state();

			return $accepted;
		}

		/**
		 * Tombstone for the legacy v1 registration entry point — mixed-fleet armor (B-1).
		 *
		 * On a site mixing one v2-rewritten plugin with one still-v1 plugin, WordPress loads
		 * plugins directory-alphabetically and the first vendored copy to define
		 * `Woodev_Plugin_Bootstrap` wins the class rendezvous. When this v2 copy wins, a v1
		 * plugin entry file still calls `register_plugin()` (the v1 API). Without this method
		 * that call is an uncaught `Error` -> site-wide WSOD. This tombstone quarantines the
		 * legacy plugin instead: it NEVER invokes the v1 callback and NEVER initializes the
		 * plugin, it only records it so the existing admin-notice path tells the merchant to
		 * update the outdated plugin.
		 *
		 * The signature is intentionally variadic and untyped: the caller is unknown-version
		 * legacy code and nothing it passes (the v1 shape was
		 * `register_plugin( string $framework_version, string $plugin_name, string $path, callable $callback, array $args = [] )`,
		 * but a still-older copy may differ) may trip a TypeError — robustness for
		 * site-availability beats the project's type-declaration convention here. Name and path
		 * are best-effort extracted from the v1 positional shape (name at index 1, path at index 2).
		 *
		 * This is NOT a deprecation shim (ADR-005): it never delegates to a real implementation,
		 * it permanently quarantines the legacy registration.
		 *
		 * @since 2.0.0
		 *
		 * @param mixed ...$args Legacy v1 registration arguments, any shape.
		 * @return void
		 */
		public function register_plugin( ...$args ): void {
			$plugin_name = isset( $args[1] ) && is_string( $args[1] ) && '' !== $args[1] ? $args[1] : __( 'Неизвестный плагин', 'woodev-plugin-framework' );
			$plugin_path = isset( $args[2] ) && is_string( $args[2] ) ? $args[2] : '';

			$this->mixed_fleet_incompatible_plugins[] = [
				'plugin_name' => $plugin_name,
				'path'        => $plugin_path,
			];

			if ( ! $this->mixed_fleet_notice_hooked && is_admin() && ! defined( 'DOING_AJAX' ) ) {
				$this->mixed_fleet_notice_hooked = true;
				add_action( 'admin_notices', [ $this, 'render_mixed_fleet_notice' ] );
			}
		}

		/**
		 * Renders the mixed-fleet quarantine notice for legacy (v1) plugins (B-1).
		 *
		 * @since 2.0.0
		 *
		 * @return void
		 */
		public function render_mixed_fleet_notice(): void {
			if ( empty( $this->mixed_fleet_incompatible_plugins ) ) {
				return;
			}

			// Build the plugin-name list with ONLY WordPress core functions + plain PHP. This runs in
			// the mixed-fleet scenario where the framework runtime (e.g. Woodev_Helper) may NOT be loaded,
			// so it must never reference a framework class — see B-1.
			$names = array_map(
				static function ( array $plugin ): string {
					return sprintf( '<strong>%s</strong>', esc_html( $plugin['plugin_name'] ) );
				},
				$this->mixed_fleet_incompatible_plugins
			);

			$count = count( $names );

			if ( $count > 1 ) {
				$last_name  = (string) array_pop( $names );
				$conjunction = _x( 'и', 'coordinating conjunction for a list of items: a, b и c', 'woodev-plugin-framework' );
				$name_list  = implode( ', ', $names ) . ' ' . $conjunction . ' ' . $last_name;
			} else {
				$name_list = (string) reset( $names );
			}

			echo '<div class="error"><p>';
			echo wp_kses(
				sprintf(
					/* translators: %s — list of plugin names. */
					__(
						'Следующие плагины собраны для устаревшей версии фреймворка Woodev и поэтому были отключены: %s. Пожалуйста, обновите их.',
						'woodev-plugin-framework'
					),
					$name_list
				),
				[ 'strong' => [] ]
			);
			echo '</p></div>';
		}

		/**
		 * Registers early WooCommerce feature compatibility declarations from loader metadata.
		 *
		 * `before_woocommerce_init` can fire before the resolver constructs plugin instances,
		 * so HPOS/Blocks compatibility must not depend on Woocommerce_Plugin::__construct().
		 *
		 * @param array<string,mixed> $definition Raw loader definition.
		 * @return void
		 */
		private function register_early_woocommerce_feature_compatibility( array $definition ): void {
			if ( ! $this->requires_woocommerce_feature_compatibility( $definition ) ) {
				return;
			}

			$plugin_file        = (string) $definition['plugin_file'];
			$supported_features = $this->normalize_supported_features( $definition['supported_features'] ?? [] );

			add_action(
				'before_woocommerce_init',
				static function () use ( $plugin_file, $supported_features ): void {
					if ( ! class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil', false ) ) {
						return;
					}

					$hpos_compatible = true === $supported_features['hpos']
						&& defined( 'WC_VERSION' )
						&& version_compare( WC_VERSION, '7.6', '>=' );

					\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
						'custom_order_tables',
						$plugin_file,
						$hpos_compatible
					);

					$blocks_compatible = true === $supported_features['blocks']['cart']
						&& true === $supported_features['blocks']['checkout'];

					\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
						'cart_checkout_blocks',
						$plugin_file,
						$blocks_compatible
					);
				}
			);
		}

		/**
		 * Determines whether a loader definition needs early WooCommerce feature declarations.
		 *
		 * @param array<string,mixed> $definition Raw loader definition.
		 * @return bool
		 */
		private function requires_woocommerce_feature_compatibility( array $definition ): bool {
			return \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WOOCOMMERCE === ( $definition['platform'] ?? '' );
		}

		/**
		 * Normalizes WooCommerce feature flags to the constructor defaults.
		 *
		 * @param mixed $supported_features Raw supported feature flags.
		 * @return array{hpos: bool, blocks: array{cart: bool, checkout: bool}}
		 */
		private function normalize_supported_features( $supported_features ): array {
			if ( ! is_array( $supported_features ) ) {
				$supported_features = [];
			}

			return array_replace_recursive(
				[
					'hpos'   => false,
					'blocks' => [
						'cart'     => false,
						'checkout' => false,
					],
				],
				$supported_features
			);
		}

		/**
		 * Loads compatible registered plugins.
		 *
		 * @return void
		 */
		public function load_plugins(): void {
			$this->resolver->load_plugins();
			$this->sync_resolver_state();
		}

		/**
		 * Resolves the main-class singleton instance of each active framework plugin.
		 *
		 * Skips legacy callback-registered actives (no loader definition) and any
		 * definition whose main class lacks an `instance()` accessor. Used by the
		 * «Плагины» installed-badge collector.
		 *
		 * @since 2.0.2
		 *
		 * @return array<int,object> Plugin main-class instances.
		 */
		public function get_active_plugin_instances(): array {

			$instances = array();

			foreach ( $this->active_plugins as $plugin ) {

				$definition = $plugin['definition'] ?? null;

				if ( ! $definition instanceof \Woodev\Framework\Framework_Plugin_Loader_Definition ) {
					continue;
				}

				$main_class = $definition->get_main_class();

				if ( null === $main_class || ! is_callable( array( $main_class, 'instance' ) ) ) {
					continue;
				}

				$instances[] = $main_class::instance();
			}

			return $instances;
		}

		/**
		 * Handles the compatibility deactivation action.
		 *
		 * @return void
		 */
		public function maybe_deactivate_framework_plugins(): void {
			$this->resolver->maybe_deactivate_framework_plugins();
			$this->sync_resolver_state();
		}

		/**
		 * Render a notice with a count of the backwards incompatible frameworked plugins that were deactivated.
		 *
		 * @since 1.0.0
		 *
		 * @return void
		 */
		public function render_deactivation_notice(): void {
			$count = isset( $_GET['woodev_framework_deactivate_newer'] ) ? absint( $_GET['woodev_framework_deactivate_newer'] ) : 0;

			if ( $count < 1 ) {
				return;
			}

			echo '<div class="updated"><p>';
			echo $count > 1 ?
				sprintf( _n( 'Deactivated %d plugin', 'Deactivated %d plugins', $count, 'woodev-plugin-framework' ), $count ) :
				esc_html__( 'Deactivated one plugin', 'woodev-plugin-framework' );
			echo '</p></div>';
		}

		/**
		 * Render a notice to update incompatible framework, WooCommerce, or WordPress registrations.
		 *
		 * @since 1.0.0
		 *
		 * @return void
		 */
		public function render_update_notices(): void {
			$this->resolver->render_update_notices();
			$this->sync_resolver_state();
		}

		/**
		 * Is the WooCommerce plugin installed and active?
		 *
		 * Delegates to {@see Woodev_Helper::is_woocommerce_active()} so the two
		 * public static entry points share a single source of truth. Kept on
		 * the bootstrap for backward compatibility - 10+ dependent plugins
		 * call this method directly.
		 *
		 * @return boolean true if the WooCommerce plugin is installed and active
		 */
		public static function is_woocommerce_active(): bool {
			return Woodev_Helper::is_woocommerce_active();
		}

		/**
		 * Compare the two framework versions.
		 *
		 * @param array $a First registered plugin to compare.
		 * @param array $b Second registered plugin to compare.
		 * @return int -1 if $a is less than $b, 0 if they're equal, and 1 if $a is greater than $b.
		 */
		public function framework_compare( array $a, array $b ): int {
			return $this->resolver->framework_compare( $a, $b );
		}

		/**
		 * Returns the plugin path for the given file.
		 *
		 * @param string $file The file.
		 * @return string Plugin path.
		 */
		public function get_plugin_path( string $file ): string {
			return $this->resolver->get_plugin_path( $file );
		}

		/**
		 * Returns the currently loaded framework version.
		 *
		 * @return string Framework version, e.g. '1.4.0'.
		 */
		public function get_framework_version(): string {
			return $this->resolver->get_framework_version();
		}

		/**
		 * Gets invalid explicit loader definitions.
		 *
		 * @since 2.0.0
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public function get_invalid_loader_definitions(): array {
			return $this->resolver->get_invalid_loader_definitions();
		}

		/**
		 * Synchronizes reflected compatibility state from the resolver.
		 *
		 * @return void
		 */
		protected function sync_resolver_state(): void {
			$this->registered_plugins               = $this->resolver->get_registered_plugins();
			$this->active_plugins                   = $this->resolver->get_active_plugins();
			$this->incompatible_framework_plugins   = $this->resolver->get_incompatible_framework_plugins();
			$this->incompatible_wc_version_plugins  = $this->resolver->get_incompatible_wc_version_plugins();
			$this->incompatible_wp_version_plugins  = $this->resolver->get_incompatible_wp_version_plugins();
			$this->incompatible_php_version_plugins = $this->resolver->get_incompatible_php_version_plugins();
			$this->invalid_loader_definitions       = $this->resolver->get_invalid_loader_definitions();
		}
	}

endif;
