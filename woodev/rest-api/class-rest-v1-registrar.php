<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Woodev_REST_V1_Registrar' ) ) :

	/**
	 * The single registration point for the framework's `woodev/v1` REST namespace.
	 *
	 * This is the ONE owner of `woodev/v1`: every controller that exposes routes
	 * under that namespace registers itself here via register_controller() instead
	 * of hooking rest_api_init on its own. The registrar hooks rest_api_init exactly
	 * once (regardless of how many controllers register) and, when it fires, calls
	 * register_routes() on each stored controller. The S3.2 license controller
	 * (Woodev_REST_API_License) registers through it; the S3.3 webhook controller
	 * (s8) will register through it too — keeping the namespace owned in one place.
	 *
	 * Unlike Woodev_REST_API (WC REST, WooCommerce-gated), this namespace is wired on
	 * core rest_api_init and is WooCommerce-agnostic.
	 *
	 * @since 2.0.0
	 */
	final class Woodev_REST_V1_Registrar {

		/**
		 * The REST namespace this registrar owns.
		 *
		 * @since 2.0.0
		 *
		 * @var string
		 */
		const ROUTE_NAMESPACE = 'woodev/v1';

		/**
		 * Registered controllers keyed by their concrete class name (deduped).
		 *
		 * @since 2.0.0
		 *
		 * @var array<string, object>
		 */
		private static $controllers = array();

		/**
		 * Whether the single rest_api_init hook has already been added.
		 *
		 * @since 2.0.0
		 *
		 * @var bool
		 */
		private static $hooked = false;

		/**
		 * Registers a controller under the `woodev/v1` namespace.
		 *
		 * Deduplicated by $key (one controller per key); adds the shared
		 * rest_api_init hook exactly once across all registrations. The key
		 * defaults to the concrete class name, which is correct for singleton
		 * controllers. Stateful controllers that carry per-plugin identity (e.g.
		 * one instance of the same class per plugin, like the setup-wizard
		 * controller) MUST pass a unique $key — otherwise a second instance of
		 * the same class is silently ignored and its routes never register.
		 *
		 * @since 2.0.0
		 *
		 * @param object      $controller A controller exposing a public register_routes() method.
		 * @param string|null $key        Dedup key; defaults to the concrete class name.
		 *
		 * @return void
		 */
		public static function register_controller( object $controller, ?string $key = null ): void {

			$key = $key ?? get_class( $controller );

			if ( ! isset( self::$controllers[ $key ] ) ) {
				self::$controllers[ $key ] = $controller;
			}

			if ( ! self::$hooked ) {
				add_action( 'rest_api_init', array( self::class, 'handle_rest_api_init' ) );
				self::$hooked = true;
			}
		}

		/**
		 * Registers the routes of every stored controller on rest_api_init.
		 *
		 * @internal
		 *
		 * @since 2.0.0
		 *
		 * @return void
		 */
		public static function handle_rest_api_init(): void {

			foreach ( self::$controllers as $controller ) {
				$controller->register_routes();
			}
		}
	}

endif;
