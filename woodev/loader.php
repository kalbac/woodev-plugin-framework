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
			// register_loader_definition(). Stay dormant — the caller renders its own notice.
			if ( ! method_exists( $instance, 'register_loader_definition' ) ) {
				return false;
			}

			$definition['plugin_file'] = $plugin_file;

			return (bool) $instance->register_loader_definition( $definition );
		}
	}

endif;
