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

					\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
						'custom_order_tables',
						$plugin_file,
						true === $supported_features['hpos']
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
			if ( \Woodev\Framework\Framework_Plugin_Loader_Definition::PLATFORM_WOOCOMMERCE === ( $definition['platform'] ?? '' ) ) {
				return true;
			}

			$capabilities = isset( $definition['capabilities'] ) ? (array) $definition['capabilities'] : [];
			$woocommerce_capabilities = [
				\Woodev\Framework\Framework_Plugin_Loader_Definition::CAPABILITY_WOOCOMMERCE_PLUGIN,
				\Woodev\Framework\Framework_Plugin_Loader_Definition::CAPABILITY_PAYMENT_GATEWAY,
				\Woodev\Framework\Framework_Plugin_Loader_Definition::CAPABILITY_SHIPPING_METHOD,
			];

			return [] !== array_intersect( $woocommerce_capabilities, $capabilities );
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
