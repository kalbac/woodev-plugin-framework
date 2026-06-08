<?php
/**
 * WooCommerce platform plugin base.
 *
 * @package Woodev\Framework
 */

namespace Woodev\Framework;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Woocommerce_Plugin::class, false ) ) :

	/**
	 * Base class for plugins that require WooCommerce runtime behavior.
	 *
	 * The class is intentionally thin at this step. Runtime WooCommerce ownership
	 * moves here in later Platform v2 phases after resolver and loader contracts
	 * are covered by tests.
	 *
	 * @since 2.0.0
	 */
	abstract class Woocommerce_Plugin extends \Woodev_Plugin {

		/**
		 * Plugin compatibility flags for WooCommerce runtime features.
		 *
		 * @since 2.0.0
		 *
		 * @var array{ hpos?: bool, blocks?: array{ cart?: bool, checkout?: bool }}
		 */
		protected $supported_features = [];

		/**
		 * WooCommerce logger instance.
		 *
		 * @since 2.0.0
		 *
		 * @var \WC_Logger_Interface|null
		 */
		private $logger;

		/**
		 * Initialize the WooCommerce plugin.
		 *
		 * @since 2.0.0
		 *
		 * @param string $id Plugin ID.
		 * @param string $version Plugin version number.
		 * @param array{
		 *     supported_features?: array{
		 *          hpos?: bool,
		 *          blocks?: array{
		 *               cart?: bool,
		 *               checkout?: bool
		 *          }
		 *     }
		 * } $args Plugin arguments.
		 */
		public function __construct( string $id, string $version, array $args = [] ) {
			$args = wp_parse_args(
				$args,
				[
					'supported_features' => [
						'hpos'   => false,
						'blocks' => [
							'cart'     => false,
							'checkout' => false,
						],
					],
				]
			);

			$this->supported_features = $args['supported_features'];

			parent::__construct( $id, $version, $args );

			// Build the WooCommerce Blocks handler only for WooCommerce plugins.
			$this->init_blocks_handler();

			// Register WooCommerce runtime hooks owned by this WooCommerce plugin class.
			$this->register_woocommerce_hooks();
		}

		private function register_woocommerce_hooks(): void {

			foreach ( [ 'shipping', 'checkout', 'integration' ] as $tab ) {
				add_action( 'woocommerce_before_settings_' . $tab, [ $this, 'add_class_form_wrap_start' ] );
				add_action( 'woocommerce_after_settings_' . $tab, [ $this, 'add_class_form_wrap_end' ] );
			}

			// add any PHP incompatibilities to the system status report
			add_filter(
				'woocommerce_system_status_environment_rows',
				[
					$this,
					'add_system_status_php_information',
				]
			);
		}

		/**
		 * Adds any PHP incompatibilities to the WooCommerce system status report.
		 *
		 * @since 2.0.0
		 *
		 * @param array<string,mixed> $rows WooCommerce system status rows.
		 * @return array<string,mixed>
		 */
		public function add_system_status_php_information( $rows ) {

			foreach ( $this->get_dependency_handler()->get_incompatible_php_settings() as $setting => $values ) {

				if ( isset( $values['type'] ) && 'min' === $values['type'] ) {

					// if this setting already has a higher minimum from another plugin, skip it
					if ( isset( $rows[ $setting ]['expected'] ) && $values['expected'] < $rows[ $setting ]['expected'] ) {
						continue;
					}

					$note = __( '%1$s - A minimum of %2$s is required.', 'woodev-plugin-framework' );

				} else {

					// if this requirement is already listed, skip it
					if ( isset( $rows[ $setting ] ) ) {
						continue;
					}

					$note = __( 'Set as %1$s - %2$s is required.', 'woodev-plugin-framework' );
				}

				$note = sprintf( $note, $values['actual'], $values['expected'] );

				$rows[ $setting ] = [
					'name'     => $setting,
					'note'     => $note,
					'success'  => false,
					'expected' => $values['expected'], // WC doesn't use this, but it's useful for us
				];
			}

			return $rows;
		}

		/**
		 * Outputs the start of a `<div class="woodev-licence-need">` wrap before WooCommerce settings tabs.
		 *
		 * The hook is installed in {@see register_woocommerce_hooks()} and only fires for
		 * `shipping`, `checkout`, and `integration` tabs when the current screen is
		 * the plugin settings page and the license is invalid. This method is the
		 * platform-correct home for the WC-specific output; the base class retains
		 * a deprecated shim for backward compatibility.
		 *
		 * @since 1.0.0
		 *
		 * @return void
		 */
		public function add_class_form_wrap_start() {
			if ( $this->is_plugin_settings() && ! $this->get_license_instance()->is_license_valid() ) {
				echo '<div class="woodev-licence-need">';
			}
		}

		/**
		 * Outputs the end of the `<div class="woodev-licence-need">` wrap after WooCommerce settings tabs.
		 *
		 * Pair to {@see add_class_form_wrap_start()}. Lives on the WooCommerce
		 * subclass because the wrap is only opened for WC settings pages.
		 *
		 * @since 1.0.0
		 *
		 * @return void
		 */
		public function add_class_form_wrap_end() {
			if ( $this->is_plugin_settings() && ! $this->get_license_instance()->is_license_valid() ) {
				echo '</div><!-- .woodev-licence-need end-->';
			}
		}

		/**
		 * Saves errors or messages to WooCommerce Log (woocommerce/logs/plugin-id-xxx.txt).
		 *
		 * @since 1.0.0
		 *
		 * @param string      $message Error or message to save to log.
		 * @param string|null $log_id Optional log id to segment the files by, defaults to plugin id.
		 * @return void
		 */
		public function log( $message, $log_id = null ) {

			if ( is_null( $log_id ) ) {
				$log_id = $this->get_id();
			}

			$this->logger()->add( $log_id, $message );
		}

		/**
		 * Gets the WooCommerce logger instance.
		 *
		 * @since 1.4.0
		 *
		 * @return \WC_Logger_Interface
		 */
		protected function logger(): \WC_Logger_Interface {
			return $this->logger ??= wc_get_logger();
		}

		/**
		 * Determines whether the plugin supports HPOS.
		 *
		 * @since 2.0.0
		 *
		 * @return bool
		 */
		public function is_hpos_compatible() {
			return isset( $this->supported_features['hpos'] )
				&& true === $this->supported_features['hpos']
				&& \Woodev_Plugin_Compatibility::is_wc_version_gte( '7.6' );
		}

		/**
		 * Loads and outputs a WooCommerce template file HTML.
		 *
		 * @since 1.0.0
		 *
		 * @param string $template Template name/part.
		 * @param array  $args Associative array of optional template arguments.
		 * @param string $path Optional template path, can be empty, as themes can override this.
		 * @param string $default_path Optional default template path, will normally use the plugin's own template path unless overridden.
		 * @return void
		 *
		 * @see wc_get_template() except we define automatically the default path.
		 */
		public function load_template( $template, array $args = [], $path = '', $default_path = '' ) {

			if ( '' === $default_path || ! is_string( $default_path ) ) {
				$default_path = trailingslashit( $this->get_template_path() );
			}

			if ( function_exists( 'wc_get_template' ) ) {
				wc_get_template( $template, $args, $path, $default_path );
			}
		}

		/**
		 * Gets a list of the plugin's WooCommerce compatibility flags.
		 *
		 * @since 2.0.0
		 *
		 * @return array{ hpos?: bool, blocks?: array{ cart?: bool, checkout?: bool }}
		 */
		public function get_supported_features(): array {
			return $this->supported_features;
		}

		/**
		 * Gets the WooCommerce uploads path, without trailing slash.
		 *
		 * WC core does not provide a helper for this. The path lives under
		 * wp_upload_dir()['basedir'] and is the canonical storage location for
		 * WC-generated export files and customer uploads.
		 *
		 * @since 2.0.0
		 *
		 * @return string
		 */
		public static function get_woocommerce_uploads_path(): string {

			$upload_dir = wp_upload_dir();

			return $upload_dir['basedir'] . '/woocommerce_uploads';
		}
	}

endif;
