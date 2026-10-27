<?php
/**
 * Translation handler.
 *
 * @package Woodev\Framework\Handlers
 */

namespace Woodev\Framework\Handlers;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\Woodev\Framework\Handlers\Translation_Handler' ) ) :
	/**
	 * Loads each plugin's own text domain.
	 *
	 * Framework_Translations handles the shared domain once from the resolver's winning copy.
	 *
	 * @since 2.0.0
	 */
	class Translation_Handler {

		/** @var string the fixed framework text domain */
		private const FRAMEWORK_TEXTDOMAIN = 'woodev-plugin-framework';

		/** @var \Woodev_Plugin current plugin instance */
		private \Woodev_Plugin $plugin;

		/**
		 * Translation handler constructor.
		 *
		 * @since 2.0.0
		 *
		 * @param \Woodev_Plugin $plugin the plugin instance
		 */
		public function __construct( \Woodev_Plugin $plugin ) {

			$this->plugin = $plugin;

			// hook for translations separately to ensure they're loaded
			add_action( 'init', [ $this, 'load_translations' ] );
			add_action( 'change_locale', [ $this, 'reload_translations' ] );
		}

		/**
		 * Gets the fixed framework text domain.
		 *
		 * @since 2.0.0
		 *
		 * @return string
		 */
		public function get_framework_textdomain(): string {
			return self::FRAMEWORK_TEXTDOMAIN;
		}

		/**
		 * Load the plugin's own text domain.
		 *
		 * @since 2.0.0
		 *
		 * @return void
		 */
		public function load_translations(): void {

			// if this plugin passes along its text domain, load its translation files
			if ( $this->plugin->get_textdomain() ) {
				$this->load_plugin_textdomain();
			}
		}

		/**
		 * Reloads this plugin's catalogue after WordPress changes locale.
		 *
		 * @since 2.0.15
		 *
		 * @return void
		 */
		public function reload_translations(): void {
			if ( ! did_action( 'init' ) ) {
				return;
			}

			$textdomain = $this->plugin->get_textdomain();

			if ( ! $textdomain ) {
				return;
			}

			unload_textdomain( $textdomain );
			$this->load_plugin_textdomain();
		}

		/**
		 * Loads the plugin textdomain.
		 *
		 * @since 2.0.0
		 *
		 * @return void
		 */
		private function load_plugin_textdomain(): void {
			$this->load_textdomain( $this->plugin->get_textdomain(), dirname( plugin_basename( $this->plugin->get_plugin_file() ) ) );
		}

		/**
		 * Loads a textdomain.
		 *
		 * @since 2.0.0
		 *
		 * @param string $textdomain the plugin textdomain
		 * @param string $path the i18n path
		 * @return void
		 */
		private function load_textdomain( string $textdomain, string $path ): void {
			$locale = apply_filters( 'plugin_locale', determine_locale(), $textdomain );
			$file   = $textdomain . '-' . $locale . '.mo';

			load_textdomain( $textdomain, WP_LANG_DIR . '/' . $textdomain . '/' . $file, $locale );

			load_plugin_textdomain( $textdomain, false, untrailingslashit( $path ) . '/languages' );
			load_textdomain( $textdomain, WP_LANG_DIR . '/plugins/' . $file, $locale );

			// load_plugin_textdomain() only registers a JIT path on WordPress 6.7+.
			// Explicitly load the bundle after external packs so they fill catalogue gaps.
			load_textdomain( $textdomain, WP_PLUGIN_DIR . '/' . untrailingslashit( $path ) . '/languages/' . $file, $locale );
		}
	}

endif;
