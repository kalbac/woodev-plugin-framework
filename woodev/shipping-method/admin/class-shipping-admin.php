<?php
/**
 * Woodev Shipping Admin Bootstrap
 *
 * The admin-suite bootstrap for a shipping plugin (spec §4.4) — the shipping
 * counterpart of the payment-gateway admin wiring done inside
 * {@see \Woodev_Payment_Gateway_Plugin::init_admin()}. It instantiates nothing the
 * framework cannot know: a carrier constructs it with its own admin handler objects
 * (order, warehouse) and its own admin page definitions, then this class owns the WP
 * plumbing — it registers the handlers on `admin_init` and the menu pages on
 * `admin_menu`.
 *
 * CRITICAL — admin page slugs are installed-site contracts and are NOT derivable.
 * The live slugs do NOT follow one convention: edostavka uses `wc_edostavka_orders`
 * (underscores), yandex uses `wc-yandex-orders` (dashes) (.autodev/INVARIANTS.md
 * `admin_page_slugs`). Dasherizing the plugin id (`edostavka` -> `wc-edostavka-orders`)
 * matches NEITHER live slug and would break the merchant's bookmarked admin URL. So,
 * exactly like the order handler's meta-key map (§4.3) and the AJAX action map (§4.1),
 * the PLUGIN SUPPLIES its exact admin page slug(s) as explicit values; the framework
 * hardcodes and derives none.
 *
 * @since 1.5.0
 */

namespace Woodev\Framework\Shipping\Admin;

use Woodev\Framework\Shipping\Shipping_Exception;
use Woodev\Framework\Shipping\Shipping_Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Admin\\Shipping_Admin' ) ) :

	/**
	 * Registers a shipping plugin's admin handlers and menu pages.
	 *
	 * A carrier constructs this with its own plugin instance, its admin handler
	 * objects (each exposing a `register()` method) and its admin page definitions
	 * (keyed by a logical name, each carrying the plugin-supplied installed-site
	 * `slug`). The base owns registration timing — handlers on `admin_init`, menu
	 * pages on `admin_menu` — and exposes the plugin-supplied slugs through
	 * {@see Shipping_Admin::get_page_slug()}; it derives no contract string.
	 *
	 * @since 1.5.0
	 */
	class Shipping_Admin {

		/** @var Shipping_Plugin the plugin instance this admin suite belongs to */
		private Shipping_Plugin $plugin;

		/** @var object[] plugin-supplied admin handler objects (order, warehouse, …) registered on admin_init */
		private array $handlers;

		/** @var array<string, array<string, mixed>> plugin-supplied admin page definitions: logical name => { slug (installed-site contract), parent, page_title, menu_title, capability, callback } */
		private array $pages;

		/**
		 * Constructor.
		 *
		 * @since 1.5.0
		 *
		 * @param Shipping_Plugin                     $plugin   the plugin instance
		 * @param object[]                            $handlers admin handler objects to register on admin_init; each one exposing a `register()` method (e.g. the order and warehouse admin handlers)
		 * @param array<string, array<string, mixed>> $pages    admin page definitions keyed by a logical name; each carries the plugin-supplied `slug` (the installed-site contract the framework never derives) plus optional `parent`, `page_title`, `menu_title`, `capability` and `callback`
		 */
		public function __construct( Shipping_Plugin $plugin, array $handlers = [], array $pages = [] ) {

			$this->plugin   = $plugin;
			$this->handlers = $handlers;
			$this->pages    = $pages;

			add_action( 'admin_init', [ $this, 'register_handlers' ] );
			add_action( 'admin_menu', [ $this, 'register_pages' ] );
		}

		/**
		 * Registers the plugin-supplied admin handlers.
		 *
		 * Each handler owns its own WP hooks; this only triggers their registration
		 * once the admin request is initialized. A handler without a `register()`
		 * method is skipped rather than fatal — a carrier may pass a plain collaborator.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @return void
		 */
		public function register_handlers(): void {

			foreach ( $this->handlers as $handler ) {

				if ( method_exists( $handler, 'register' ) ) {
					$handler->register();
				}
			}
		}

		/**
		 * Registers the plugin-supplied admin menu pages.
		 *
		 * Every page is mounted under the carrier's own `slug` — the installed-site
		 * contract the framework never derives. A page lacking a slug or a callable
		 * render callback is skipped; the framework adds no menu of its own.
		 *
		 * @internal
		 *
		 * @since 1.5.0
		 *
		 * @return void
		 */
		public function register_pages(): void {

			foreach ( $this->pages as $page ) {

				$slug     = isset( $page['slug'] ) && is_string( $page['slug'] ) ? $page['slug'] : '';
				$callback = $page['callback'] ?? null;

				if ( '' === $slug || ! is_callable( $callback ) ) {
					continue;
				}

				add_submenu_page(
					isset( $page['parent'] ) && is_string( $page['parent'] ) ? $page['parent'] : 'woocommerce',
					isset( $page['page_title'] ) && is_string( $page['page_title'] ) ? $page['page_title'] : '',
					isset( $page['menu_title'] ) && is_string( $page['menu_title'] ) ? $page['menu_title'] : '',
					isset( $page['capability'] ) && is_string( $page['capability'] ) ? $page['capability'] : 'manage_woocommerce',
					$slug,
					$callback
				);
			}
		}

		/**
		 * Gets the plugin-supplied admin page slug for a logical page name.
		 *
		 * Returns the exact installed-site slug the plugin registered (e.g.
		 * `wc_edostavka_orders` or `wc-yandex-orders`); it derives nothing. An unknown
		 * logical name throws rather than fabricating a slug that would 404 or break a
		 * bookmarked URL.
		 *
		 * @since 1.5.0
		 *
		 * @param string $logical logical page name present in the supplied page map
		 * @return string the plugin-supplied admin page slug
		 * @throws Shipping_Exception when the logical name is not in the plugin's page map or carries no slug
		 */
		public function get_page_slug( string $logical ): string {

			$slug = $this->pages[ $logical ]['slug'] ?? null;

			if ( ! is_string( $slug ) || '' === $slug ) {
				throw new Shipping_Exception(
					sprintf( 'Unmapped admin page "%s": the plugin must supply its exact admin page slug.', $logical )
				);
			}

			return $slug;
		}

		/**
		 * Gets the plugin instance this admin suite belongs to.
		 *
		 * @since 1.5.0
		 *
		 * @return Shipping_Plugin
		 */
		public function get_plugin(): Shipping_Plugin {
			return $this->plugin;
		}
	}

endif;
