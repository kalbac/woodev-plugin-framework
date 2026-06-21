<?php
/**
 * Framework runtime autoloader.
 *
 * @package Woodev\Framework
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_Framework_Autoloader', false ) ) :

	/**
	 * Hand-written spl_autoload for framework classes (no Composer in shipped plugins).
	 *
	 * Owns only classes present in the generated classmap, resolved against the selected
	 * framework copy's root. Returns silently for anything else so other autoloaders run.
	 *
	 * @since 2.0.2
	 */
	final class Woodev_Framework_Autoloader {

		/** @var bool Whether the autoloader has been registered. */
		private static bool $registered = false;

		/** @var string Absolute path to the selected framework copy root (the dir containing woodev/). */
		private static string $base_path = '';

		/** @var array<string,string> FQCN => relative path map. */
		private static array $map = [];

		/**
		 * Registers the autoloader against the selected framework copy.
		 *
		 * Idempotent: the first registered copy wins, matching the bootstrap's
		 * highest-version selection. Subsequent calls are no-ops.
		 *
		 * @since 2.0.2
		 *
		 * @param string $base_path Absolute path to the dir that contains the `woodev/` folder.
		 * @return void
		 */
		public static function register( string $base_path ): void {
			if ( self::$registered ) {
				return;
			}

			$map_file = rtrim( $base_path, '/\\' ) . '/woodev/class-map.php';

			if ( ! is_readable( $map_file ) ) {
				return;
			}

			self::$base_path  = rtrim( $base_path, '/\\' );
			self::$map        = (array) require $map_file;
			self::$registered = true;

			spl_autoload_register( [ self::class, 'autoload' ] );
		}

		/**
		 * Resolves a framework class to its file and loads it.
		 *
		 * @since 2.0.2
		 *
		 * @param string $class Fully-qualified class name.
		 * @return void
		 */
		public static function autoload( string $class ): void {
			$relative = self::$map[ $class ] ?? null;

			if ( null === $relative ) {
				return;
			}

			$file = self::$base_path . '/' . $relative;

			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}

		/**
		 * Whether the autoloader is currently registered.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public static function is_registered(): bool {
			return self::$registered;
		}

		/**
		 * Resets state. Test-only.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public static function reset(): void {
			if ( self::$registered ) {
				spl_autoload_unregister( [ self::class, 'autoload' ] );
			}

			self::$registered = false;
			self::$base_path  = '';
			self::$map        = [];
		}
	}

endif;
