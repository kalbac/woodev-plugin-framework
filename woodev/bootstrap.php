<?php

defined( 'ABSPATH' ) or exit;

if ( ! class_exists( 'Woodev_Plugin_Bootstrap' ) ) :

	/**
	 * Woodev Plugin Framework Bootstrap
	 *
	 * The purpose of this class is to find and load the highest versioned
	 * framework of the activated framework plugins, and then initialize any
	 * compatible framework plugins.
	 *
	 * @since 1.0.0
	 */
	class Woodev_Plugin_Bootstrap {

		/** @var Woodev_Plugin_Bootstrap The single instance of the class */
		protected static $instance = null;

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

		/**
		 * Hidden constructor
		 */
		private function __construct() {
			add_action( 'plugins_loaded', [ $this, 'load_plugins' ] );
			add_action( 'admin_init', [ $this, 'maybe_deactivate_framework_plugins' ] );
		}

		/**
		 * Instantiate the class singleton
		 *
		 * @return Woodev_Plugin_Bootstrap singleton instance
		 */
		public static function instance(): self {
			return self::$instance ??= new self();
		}

		/**
		 * Register a frameworked plugin
		 *
		 * @param  string  $framework_version the framework version
		 * @param  string  $plugin_name       the plugin name
		 * @param  string  $path              the plugin path
		 * @param callable $callback          function to initialize the plugin
		 * @param array    $args              optional plugin arguments
		 */
		public function register_plugin(
			string $framework_version,
			string $plugin_name,
			string $path,
			callable $callback,
			array $args = []
		) {
			$this->registered_plugins[] = [
				'version'     => $framework_version,
				'plugin_name' => $plugin_name,
				'path'        => $path,
				'callback'    => $callback,
				'args'        => $args
			];
		}

		public function load_plugins() {

			usort( $this->registered_plugins, [ $this, 'framework_compare' ] );

			$loaded_framework = null;

			foreach ( $this->registered_plugins as $plugin ) {

				if ( ! class_exists( 'Woodev_Plugin' ) ) {
					require_once( $this->get_plugin_path( $plugin['path'] ) . '/woodev/class-plugin.php' );
					$loaded_framework       = $plugin;
					$this->active_plugins[] = $plugin;
				}

				if ( ! empty( $loaded_framework['args']['backwards_compatible'] ) && version_compare( $loaded_framework['args']['backwards_compatible'], $plugin['version'], '>' ) ) {

					$this->incompatible_framework_plugins[] = $plugin;
					continue;
				}

				if ( ! empty( $plugin['args']['minimum_wc_version'] ) && version_compare( $this->get_wc_version(), $plugin['args']['minimum_wc_version'], '<' ) ) {

					$this->incompatible_wc_version_plugins[] = $plugin;
					continue;
				}

				if ( ! empty( $plugin['args']['minimum_wp_version'] ) && version_compare( get_bloginfo( 'version' ), $plugin['args']['minimum_wp_version'], '<' ) ) {

					$this->incompatible_wp_version_plugins[] = $plugin;
					continue;
				}

				if ( ! in_array( $plugin, $this->active_plugins ) ) {
					$this->active_plugins[] = $plugin;
				}

				if ( isset( $plugin['args']['is_payment_gateway'] ) && ! class_exists( 'Woodev_Payment_Gateway_Plugin' ) ) {
					require_once( $this->get_plugin_path( $plugin['path'] ) . '/woodev/payment-gateway/class-payment-gateway-plugin.php' );
				}

				if ( isset( $plugin['args']['load_shipping_method'] ) && ! class_exists( '\\Woodev\\Framework\\Shipping\\Shipping_Plugin' ) ) {
					require_once( $this->get_plugin_path( $plugin['path'] ) . '/woodev/shipping-method/class-shipping-plugin.php' );
				}

				$plugin['callback']();
			}

			if ( ( $this->incompatible_framework_plugins || $this->incompatible_wc_version_plugins || $this->incompatible_wp_version_plugins ) && is_admin() && ! defined( 'DOING_AJAX' ) && ! has_action( 'admin_notices', array(
					$this,
					'render_update_notices'
				) ) ) {

				add_action( 'admin_notices', [ $this, 'render_update_notices' ] );
			}

			do_action( 'woodev_plugins_loaded' );
		}

		public function maybe_deactivate_framework_plugins() {

			if ( isset( $_GET['woodev_framework_deactivate_newer'] ) ) {
				if ( 'yes' === sanitize_text_field( $_GET['woodev_framework_deactivate_newer'] ) ) {

					if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'woodev_framework_deactivate' ) ) {
						return;
					}

					if ( count( $this->incompatible_framework_plugins ) === 0 ) {
						return;
					}

					$plugins = [];

					foreach ( $this->active_plugins as $plugin ) {
						$plugins[] = plugin_basename( $plugin['path'] );
					}

					deactivate_plugins( $plugins );

					wp_redirect( add_query_arg( [
						'plugin_status' => 'inactive',
						'woodev_framework_deactivate_newer' => count( $plugins )
					], admin_url( 'plugins.php' ) ) );

					exit;

				} else {
					add_action( 'admin_notices', array( $this, 'render_deactivation_notice' ) );
				}
			}
		}

		/**
		 * Render a notice with a count of the backwards incompatible frameworked plugins that were deactivated
		 *
		 * @since 1.0.0
		 */
		public function render_deactivation_notice() {
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
		 * Render a notice to update any plugins with incompatible framework versions,
		 * or incompatiblities with the current WooCommerce or WordPress versions
		 *
		 * @since 1.0.0
		 */
		public function render_update_notices() {

			// must update plugin notice
			if ( ! empty( $this->incompatible_framework_plugins ) ) {

				$incompatible_plugin_count = count( $this->incompatible_framework_plugins );
				$active_plugin_count       = count( $this->active_plugins );

				$message = '<p>';

				$message .= sprintf(
					_n( '%1$sAttention!%2$s The plugin %3$s was disabled because it is out of date and incompatible with the', '%1$sAttention!%2$s The plugins %3$s were disabled because they are out of date and incompatible with the', $incompatible_plugin_count, 'woodev-plugin-framework' ),
					'<strong>', '</strong>',
					Woodev_Helper::list_array_items( array_map( function ( $plugin ) {
						return sprintf( '<strong>%s</strong>', esc_html( $plugin['plugin_name'] ) );
					}, $this->incompatible_framework_plugins ) )
				);

				$message .= sprintf(
					_n( ' newer plugin %s.', ' newer plugins %s.', $active_plugin_count, 'woodev-plugin-framework' ),
					Woodev_Helper::list_array_items( array_map( function ( $plugin ) {
						return sprintf( '<strong>%s</strong>', esc_html( $plugin['plugin_name'] ) );
					}, $this->active_plugins ) )
				);

				$message .= '</p>';

				$message .= '<p>';

				$message .= sprintf(
					__( 'To resolve this, please %1$supdate%2$s (recommended) or %1$sdeactivate%2$s', 'woodev-plugin-framework' ),
					'<a href="' . esc_url( admin_url( 'update-core.php' ) ) . '">', '</a>'
				);

				$message .= sprintf(
					_n( ' the plugin %1$s, or %2$sdeactivate%3$s', ' the plugins %1$s, or %2$sdeactivate%3$s', $incompatible_plugin_count, 'woodev-plugin-framework' ),
					Woodev_Helper::list_array_items( array_map( function ( $plugin ) {
						return sprintf( '<strong>%s</strong>', esc_html( $plugin['plugin_name'] ) );
					}, $this->incompatible_framework_plugins ) ),
					'<a href="' . esc_url( wp_nonce_url( admin_url( 'plugins.php?woodev_framework_deactivate_newer=yes' ), 'woodev_framework_deactivate' ) ) . '">', '</a>'
				);

				$message .= sprintf(
					_n( ' the plugin %s.', ' the plugins %s.', $active_plugin_count, 'woodev-plugin-framework' ),
					Woodev_Helper::list_array_items( array_map( function ( $plugin ) {
						return sprintf( '<strong>%s</strong>', esc_html( $plugin['plugin_name'] ) );
					}, $this->active_plugins ) )
				);

				$message .= '</p>';

				echo '<div class="error">';

				echo $message;

				echo '</div>';
			}

			// must update WC notice
			if ( ! empty( $this->incompatible_wc_version_plugins ) ) {

				printf( '<div class="error"><p>%s</p><ul>', count( $this->incompatible_wc_version_plugins ) > 1 ? esc_html__( 'The following plugins are inactive because they require a newer version of WooCommerce:', 'woodev-plugin-framework' ) : esc_html__( 'The following plugin is inactive because it requires a newer version of WooCommerce:', 'woodev-plugin-framework' ) );

				foreach ( $this->incompatible_wc_version_plugins as $plugin ) {

					/* translators: Placeholders: %1$s - plugin name, %2$s - WooCommerce version number */
					echo '<li>' . sprintf( esc_html__( '%1$s requires WooCommerce %2$s or newer', 'woodev-plugin-framework' ), esc_html( $plugin['plugin_name'] ), esc_html( $plugin['args']['minimum_wc_version'] ) ) . '</li>';
				}

				/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag */
				echo '</ul><p>' . sprintf( esc_html__( 'Please %1$supdate WooCommerce%2$s', 'woodev-plugin-framework' ), '<a href="' . esc_url( admin_url( 'update-core.php' ) ) . '">', '&nbsp;&raquo;</a>' ) . '</p></div>';
			}

			// must update WP notice
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
		 * Is the WooCommerce plugin installed and active? This method is handy for
		 * frameworked plugins that are listed on wordpress.org and thus don't have
		 * access to the Woo Helper functions bundled with WooThemes-listed plugins.
		 *
		 * Notice: For now you can't rely on this method being available, since the
		 * bootstrap class is the only piece of the framework which is loaded
		 * simply according to the lexical order of plugin directories. Therefore
		 * to use, you should first check that this method exists, or if you really
		 * need to check for WooCommerce being active, define your own method.
		 *
		 * @return boolean true if the WooCommerce plugin is installed and active
		 */
		public static function is_woocommerce_active(): bool {

			$active_plugins = (array) get_option( 'active_plugins', array() );

			if ( is_multisite() ) {
				$active_plugins = array_merge( $active_plugins, get_site_option( 'active_sitewide_plugins', array() ) );
			}

			return in_array( 'woocommerce/woocommerce.php', $active_plugins ) || array_key_exists( 'woocommerce/woocommerce.php', $active_plugins );
		}

		/**
		 * Compare the two framework versions.  Returns -1 if $a is less than $b, 0 if
		 * they're equal, and 1 if $a is greater than $b
		 *
		 * @param  array  $a first registered plugin to compare
		 * @param  array  $b second registered plugin to compare
		 *
		 * @return int -1 if $a is less than $b, 0 if they're equal, and 1 if $a is greater than $b
		 */
		public function framework_compare( array $a, array $b ): int {
			return version_compare( $b['version'], $a['version'] );
		}

		/**
		 * Returns the plugin path for the given $file
		 *
		 * @param  string  $file the file
		 *
		 * @return string plugin path
		 */
		public function get_plugin_path( string $file ): string {
			return untrailingslashit( plugin_dir_path( $file ) );
		}

		/**
		 * Returns the WooCommerce version number, backwards compatible to WC 1.5
		 *
		 * @return null|string
		 */
		protected function get_wc_version(): ?string {

			if ( defined( 'WC_VERSION' ) && WC_VERSION ) {
				return WC_VERSION;
			}
			if ( defined( 'WOOCOMMERCE_VERSION' ) && WOOCOMMERCE_VERSION ) {
				return WOOCOMMERCE_VERSION;
			}

			return null;
		}
	}

endif;
