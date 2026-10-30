<?php
/**
 * Shared framework translations from the resolver's selected copy.
 *
 * @package Woodev\Framework\Handlers
 */

namespace Woodev\Framework\Handlers;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\\Woodev\\Framework\\Handlers\\Framework_Translations' ) ) :

	/**
	 * Loads external overrides before the bundled fallback, independently of plugin startup.
	 *
	 * @since 2.0.5
	 */
	final class Framework_Translations {

		/**
		 * @var string Framework text domain.
		 */
		private const DOMAIN = 'woodev-plugin-framework';

		/**
		 * Registers once, after the resolver has selected the framework copy.
		 *
		 * Init priority zero is safe for locale resolution and precedes lifecycle notices at ten.
		 *
		 * @since 2.0.5
		 *
		 * @return void
		 */
		public static function register(): void {
			if ( false !== has_action( 'init', [ self::class, 'load_translations' ] ) ) {
				return;
			}

			add_action( 'init', [ self::class, 'load_translations' ], 0 );
			add_action( 'change_locale', [ self::class, 'reload_translations' ], 0 );
		}

		/**
		 * Explicitly merges all catalogues: first-loaded entries win in WordPress.
		 *
		 * load_plugin_textdomain() only registers a JIT path since WordPress 6.7; it cannot
		 * fill gaps in an already-loaded external pack. Use the absolute bundled path instead.
		 *
		 * @since 2.0.5
		 *
		 * @return void
		 */
		public static function load_translations(): void {
			$locale = apply_filters( 'plugin_locale', determine_locale(), self::DOMAIN );
			$file   = self::DOMAIN . '-' . $locale . '.mo';

			load_textdomain( self::DOMAIN, WP_LANG_DIR . '/' . self::DOMAIN . '/' . $file, $locale );
			load_textdomain( self::DOMAIN, WP_LANG_DIR . '/plugins/' . $file, $locale );
			load_textdomain( self::DOMAIN, dirname( __DIR__ ) . '/languages/' . $file, $locale );
		}

		/**
		 * Rebuilds override precedence after WordPress has switched or restored the locale.
		 *
		 * Core's locale switcher may JIT-load the bundle before change_locale fires. Unload
		 * that domain fully so the external overrides are first again, including on restore.
		 *
		 * @since 2.0.5
		 *
		 * @return void
		 */
		public static function reload_translations(): void {
			if ( ! did_action( 'init' ) ) {
				return;
			}

			unload_textdomain( self::DOMAIN );
			self::load_translations();
		}
	}

endif;
