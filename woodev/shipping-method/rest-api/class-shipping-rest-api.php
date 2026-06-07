<?php
/**
 * Woodev Shipping REST API
 *
 * REST bootstrap for a shipping plugin (spec §4.4) — the shipping counterpart of
 * {@see \Woodev_Payment_Gateway_REST_API}. It extends the framework's base REST
 * handler ({@see \Woodev_REST_API}) and wires the shipping module's warehouses +
 * pickup-points controllers in addition to the inherited settings controller.
 *
 * The REST namespace is the plugin's id-dasherized slug
 * ({@see Shipping_REST_API::get_namespace()}, e.g. yandex `yandex_delivery` →
 * `yandex-delivery`); the framework introduces no hardcoded namespace literal, so it
 * can never mint a new installed-site URL contract.
 *
 * The concrete controllers are plugin-supplied: the framework ships only the abstract
 * bases, wired in later phases. A plugin overrides
 * {@see Shipping_REST_API::get_rest_controllers()} to return its instantiated
 * controllers, so this bootstrap never hard-depends on a controller that has not
 * shipped — the default returns no controllers.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping\Rest_Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Rest_Api\\Shipping_REST_API' ) ) :

	/**
	 * Bootstraps the shipping module's REST API.
	 *
	 * @since 1.5.0
	 */
	class Shipping_REST_API extends \Woodev_REST_API {

		/**
		 * Registers the shipping REST API routes.
		 *
		 * Runs the inherited registration (the plugin settings controller) and then
		 * registers each plugin-supplied controller (warehouses + pickup-points). Each
		 * controller owns its `rest_base` and registers under the plugin's REST namespace
		 * ({@see Shipping_REST_API::get_namespace()}).
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @return void
		 */
		public function register_routes() {

			parent::register_routes();

			foreach ( $this->get_rest_controllers() as $controller ) {

				if ( $controller instanceof \WP_REST_Controller ) {
					$controller->register_routes();
				}
			}
		}

		/**
		 * Gets the REST controllers to register.
		 *
		 * The framework ships only the abstract warehouses + pickup-points controller
		 * bases; a concrete plugin overrides this to return its instantiated controllers.
		 * The default returns none, so the bootstrap never instantiates a controller that
		 * has not shipped.
		 *
		 * @since 1.5.0
		 *
		 * @return \WP_REST_Controller[] controller instances to register
		 */
		protected function get_rest_controllers(): array {
			return [];
		}

		/**
		 * Gets the REST namespace for the shipping controllers.
		 *
		 * Derived from the plugin id ({@see \Woodev_Plugin::get_id_dasherized()}); the
		 * framework hardcodes no namespace literal. A plugin's controllers reuse this
		 * value (e.g. yandex `yandex-delivery`) when registering their routes.
		 *
		 * @since 1.5.0
		 *
		 * @return string
		 */
		public function get_namespace(): string {
			return $this->get_plugin()->get_id_dasherized();
		}
	}

endif;
