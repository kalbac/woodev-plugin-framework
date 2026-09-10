<?php
/**
 * Shipping orders — registry
 *
 * @since 2.0.2
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Admin\Orders;

use Woodev\Framework\Settings\Settings_Page_Registry;
use Woodev\Framework\Shipping\Order\Delivery_Status;
use Woodev\Framework\Shipping\Rest_Api\Orders_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Admin\\Orders\\Orders_Registry' ) ) :

	/**
	 * Singleton aggregator for the framework-owned «Заказы доставки» page (SP-10 spec D1).
	 *
	 * Collects {@see Orders_Provider} descriptors registered by carrier plugins,
	 * registers the page inside WooCommerce's own admin app via `wc_admin_register_page()`
	 * under the `woocommerce` menu — like every shipped v1 plugin, not a page of our own
	 * (increment 2b rewrite; the previous `add_submenu_page()`-under-`woodev` design was
	 * rejected on the rig) — only when at least one provider is present, registers the
	 * aggregated REST controller through {@see \Woodev_REST_V1_Registrar}, and enqueues the
	 * React bundle that attaches to the WooCommerce app through
	 * `addFilter( 'woocommerce_admin_pages_list', ... )` (SP-10 spec D7).
	 *
	 * @since 2.0.2
	 */
	class Orders_Registry {

		/** @var string admin page slug. */
		const PAGE_SLUG = 'woodev-shipping-orders';

		/** @var self|null singleton. */
		private static $instance = null;

		/** @var array<string, Orders_Provider> providers keyed by id. */
		private $providers = [];

		/** @var bool whether the shared hooks were added. */
		private $hooked = false;

		/**
		 * Any one registered plugin, to source the shared framework asset path and
		 * version from (increment 2b). @see self::register_provider().
		 *
		 * @since 2.0.2
		 *
		 * @var \Woodev_Plugin|null
		 */
		private $plugin;

		/**
		 * Returns the singleton.
		 *
		 * @since 2.0.2
		 *
		 * @return self
		 */
		public static function instance(): self {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Registers a carrier's descriptor.
		 *
		 * Keyed by id: registering a second provider under an id already in use replaces
		 * the first (last write wins) rather than silently producing two tabs for the
		 * same id.
		 *
		 * @since 2.0.2
		 *
		 * @param Orders_Provider     $provider carrier descriptor.
		 * @param \Woodev_Plugin|null $plugin  owning plugin, to source the shared framework
		 *                                     asset path/version from (increment 2b). Any one
		 *                                     registered plugin works — the framework copy is
		 *                                     identical across every plugin that vendors it,
		 *                                     the same assumption {@see Settings_Page_Registry::get_asset_plugin()}
		 *                                     already makes. The first plugin passed wins;
		 *                                     later calls (with or without one) do not replace it.
		 * @return void
		 */
		public function register_provider( Orders_Provider $provider, $plugin = null ): void {
			$this->providers[ $provider->get_id() ] = $provider;

			if ( null === $this->plugin && $plugin instanceof \Woodev_Plugin ) {
				$this->plugin = $plugin;
			}

			$this->add_hooks();
		}

		/**
		 * Returns every registered provider, filterable (#{@see 'woodev_shipping_orders_providers'}).
		 *
		 * Always leave this extension point even with no consumer yet — a plugin composing
		 * carriers dynamically (e.g. hiding one behind a feature flag) needs a seam that
		 * does not require touching the framework.
		 *
		 * @since 2.0.2
		 *
		 * @return Orders_Provider[]
		 */
		public function get_providers(): array {
			/**
			 * Filters the registered orders providers.
			 *
			 * @since 2.0.2
			 *
			 * @param Orders_Provider[] $providers providers keyed by id.
			 */
			$filtered = apply_filters( 'woodev_shipping_orders_providers', $this->providers );

			return is_array( $filtered ) ? $filtered : $this->providers;
		}

		/**
		 * Returns one provider by id, or null when unregistered.
		 *
		 * @since 2.0.2
		 *
		 * @param string $id carrier/tab id.
		 * @return Orders_Provider|null
		 */
		public function get_provider( string $id ): ?Orders_Provider {
			return $this->get_providers()[ $id ] ?? null;
		}

		/**
		 * Whether at least one provider is registered.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		public function has_providers(): bool {
			return [] !== $this->get_providers();
		}

		/**
		 * Returns the page/REST capability.
		 *
		 * Reuses {@see Settings_Page_Registry::resolve_capability()} rather than inventing a
		 * second capability rule (per the SP-10 spec's D1 mirror decision). `Orders_Provider`
		 * carries no per-provider capability override in this increment, so every provider is
		 * treated as WooCommerce-dependent — a shipping order is inherently a WC concept —
		 * which resolves to the single constant `manage_woocommerce`.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function get_page_capability(): string {
			return Settings_Page_Registry::resolve_capability( null, true );
		}

		/**
		 * Adds the shared menu / enqueue / REST / CPT-query-translation hooks exactly once.
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function add_hooks(): void {
			if ( $this->hooked ) {
				return;
			}
			$this->hooked = true;

			add_action( 'admin_menu', [ $this, 'register_page' ], 40 );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
			add_action( 'rest_api_init', [ $this, 'register_rest' ], 5 );
			add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', [ $this, 'translate_marker_keys_query_var' ], 10, 2 );
		}

		/**
		 * Registers the «Заказы доставки» page inside WooCommerce's own admin app when
		 * ≥1 provider is present.
		 *
		 * Uses `wc_admin_register_page()` under the `woocommerce` parent menu — the page
		 * lives where every shipped v1 plugin's page already lives (increment 2b rewrite;
		 * rejected on the rig when it lived under our own `woodev` menu). A `wc-admin` page
		 * has no render callback of its own — WooCommerce's app renders whatever component
		 * `./index.tsx` pushes onto `woocommerce_admin_pages_list` for this `path` — so
		 * there is no `render_page()` counterpart any more.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function register_page(): void {
			if ( ! $this->has_providers() ) {
				return;
			}

			if ( ! function_exists( 'wc_admin_register_page' ) ) {
				return;
			}

			wc_admin_register_page( $this->build_page_args() );
		}

		/**
		 * Builds the `wc_admin_register_page()` argument array.
		 *
		 * Split out of {@see self::register_page()} so the argument shape — in particular
		 * the `title`/`page_title` split below, which is the whole of #834's escaping
		 * constraint — is reachable from a unit test. `wc_admin_register_page()` itself is
		 * deliberately never stubbed in this project's unit suite: touching it once leaks
		 * `function_exists( 'wc_admin_register_page' )` as permanently `true` for the rest
		 * of the PHPUnit process, which would disarm {@see self::register_page()}'s own
		 * fail-soft guard test.
		 *
		 * ⚠ `title` and `page_title` MUST both be passed, and only `title` may carry
		 * markup. `wc_admin_register_page()` hands `title` straight through to
		 * `add_submenu_page()` as the MENU title, which WordPress echoes unescaped — that
		 * is what lets the badge render at all (measured on the rig, #834, 11.09.2026).
		 * But WooCommerce's `PageController::register_page()` copies `title` into
		 * `page_title` when the latter is empty, and `page_title` IS escaped, so leaving
		 * it out puts the raw `<span …>` markup into the browser tab's `<title>`.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string,mixed>
		 */
		private function build_page_args(): array {
			$page_title = __( 'Заказы доставки', 'woodev-plugin-framework' );

			return [
				'id'         => self::PAGE_SLUG,
				'title'      => $this->build_menu_title( $page_title ),
				'page_title' => $page_title,
				'parent'     => 'woocommerce',
				'path'       => '/' . self::PAGE_SLUG,
				'capability' => $this->get_page_capability(),
			];
		}

		/**
		 * Appends the "new orders" counter badge to the submenu title (#834).
		 *
		 * The markup is WordPress's own update-counter bubble — the same one core uses
		 * for pending plugin updates and the same one the shipped v1 plugin emits
		 * (`woocommerce-edostavka/includes/admin/class-wc-edostavka-admin.php:71`) — so no
		 * stylesheet of ours is involved and the bubble matches every other count in the
		 * admin menu. Like the v1 plugin, nothing is appended when the count is zero: an
		 * empty bubble reads as "0 waiting", which is noise, not information.
		 *
		 * The one addition over v1 is the `title` attribute carrying the per-carrier
		 * breakdown, one line per registered provider. That is the same plain-attribute
		 * technique the page's own «Статус данных» bar already uses
		 * (`src/shipping-orders-page/app.tsx`, `.woodev-orders-sync__bar[title]`) rather
		 * than a JS tooltip, which the admin menu could not host anyway.
		 *
		 * ⚠ The inner span uses `%s` with {@see number_format_i18n()}, not v1's `%d`:
		 * `sprintf( '%d', '1 234' )` truncates a thousands-separated string to `1`, so the
		 * v1 badge misreports any shop with ≥1000 new orders. The outer `count-%d` class
		 * takes the raw integer, which is what core's own `wp-admin/menu.php` does.
		 *
		 * @since 2.0.2
		 *
		 * @param string $page_title plain, unmarked-up page title.
		 * @return string menu title, with the badge appended when there is one to show.
		 */
		private function build_menu_title( string $page_title ): string {
			// The badge counts orders this user is not necessarily allowed to see. The
			// page capability is the registry's own answer to "may this user look at
			// shipping orders" — reuse it rather than widening it to "can see a menu".
			if ( ! current_user_can( $this->get_page_capability() ) ) {
				return $page_title;
			}

			$query = new Orders_Query( $this );
			$total = (int) $query->get_results( self::new_orders_request() )->total;

			if ( $total < 1 ) {
				return $page_title;
			}

			return $page_title . sprintf(
				' <span class="update-plugins count-%1$d" title="%2$s"><span class="new-count">%3$s</span></span>',
				$total,
				esc_attr( $this->build_new_orders_breakdown( $query ) ),
				number_format_i18n( $total )
			);
		}

		/**
		 * Builds the badge's per-carrier tooltip text — one `«Перевозчик»: N` line per
		 * registered provider, joined by newlines, which is how a `title` attribute
		 * expresses several lines.
		 *
		 * Every provider is listed, including one contributing nothing, so the breakdown
		 * is readable as a complete account of the number in the bubble rather than a
		 * selection from it.
		 *
		 * @since 2.0.2
		 *
		 * @param Orders_Query $query the SAME query object the aggregate count came from,
		 *                            so a per-carrier line cannot be built by a different
		 *                            mechanism than the total it breaks down.
		 * @return string
		 */
		private function build_new_orders_breakdown( Orders_Query $query ): string {
			$lines = [];

			foreach ( $this->get_providers() as $provider ) {
				$count = (int) $query->get_results( self::new_orders_request( $provider->get_id() ) )->total;

				$lines[] = $provider->get_label() . ': ' . number_format_i18n( $count );
			}

			return implode( "\n", $lines );
		}

		/**
		 * The request that defines "new" for the badge: an order nobody has exported to
		 * the carrier yet.
		 *
		 * ⚠ "New" is `is_exported => false` and nothing else — settled by measurement in
		 * #841: an order is new until {@see \Woodev\Framework\Shipping\Order\Abstract_Shipment_Handler::export()}
		 * has written its `carrier_order_id`. There is no separate "new" flag to read.
		 *
		 * ⚠ This goes through {@see Orders_Query} — the very query the table runs — and
		 * NOT through a hand-rolled `wc_get_orders()`/`$wpdb` count. That is what makes
		 * the number in the menu equal what the page shows under the same filter BY
		 * CONSTRUCTION. It also keeps both datastores honest: on the legacy CPT datastore
		 * `wc_get_orders()` silently DROPS `meta_query` (gotcha
		 * `wc-get-orders-drops-meta-query-on-the-legacy-cpt-datastore`), and only
		 * `Orders_Query` knows to pass its own query vars there instead.
		 *
		 * `build_args()` always sets `paginate => true`, so `->total` on the result is the
		 * count and `per_page => 1` keeps the row fetch to a single order.
		 *
		 * @since 2.0.2
		 *
		 * @param string $carrier provider id, or 'all' for the aggregate.
		 * @return array<string,mixed>
		 */
		private static function new_orders_request( string $carrier = 'all' ): array {
			return [
				'carrier'     => $carrier,
				'is_exported' => false,
				'per_page'    => 1,
			];
		}

		/**
		 * Enqueues the shipping-orders-page bundle + inline bootstrap on the `wc-admin`
		 * screen (increment 2b rewrite — no more per-hook `admin_print_scripts-{$hook}`,
		 * `wc_admin_register_page()` returns no hook suffix to hang that off of).
		 *
		 * Gated on {@see wc_admin_is_registered_page()} rather than a hardcoded screen id —
		 * the documented WooCommerce passthrough for "is the current admin page a wc-admin
		 * page" — so the bundle loads only where it is actually usable, not on every admin
		 * screen. Rows are NOT inlined — the app fetches them from
		 * `GET woodev/v1/shipping/orders` (cap-filtered server-side, increment 1); what IS
		 * inlined is what the page needs before its first fetch: the REST root, a nonce, and
		 * the provider list (id and label only, so the carrier picker can render before the
		 * first response — the per-carrier counts arrive WITH the rows, #855, because they
		 * depend on the page's filters and this runs before any of them exist).
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function enqueue_assets(): void {
			if ( ! $this->has_providers() ) {
				return;
			}

			if ( ! $this->is_wc_admin_screen() ) {
				return;
			}

			$plugin = $this->get_asset_plugin();

			if ( ! $plugin ) {
				return;
			}

			$asset_file = $plugin->get_framework_path() . '/assets/build/shipping-orders-page/index.asset.php';

			if ( file_exists( $asset_file ) ) {
				$asset = include $asset_file;
			} else {
				error_log( sprintf( '[woodev] Shipping orders page asset manifest missing: %s', $asset_file ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for a missing build artifact.
				$asset = [
					'dependencies' => [],
					'version'      => $plugin->get_version(),
				];
			}

			// `@wordpress/dependency-extraction-webpack-plugin` (what `wp-scripts` ships) has no
			// idea `@woocommerce/*` exists, so the auto-generated dependency list never carries
			// `wc-components` — it is added by hand here, the documented Route-B pattern (SP-10
			// spec D7 build-seam decision; the same technique a real production plugin uses,
			// e.g. Dokan's `includes/Analytics/Assets.php`). `wc-admin-app` orders our script
			// after the `wc-admin` app shell so `woocommerce_admin_pages_list` is read with our
			// page already pushed onto it.
			// `wc-navigation` carries `@woocommerce/navigation`: the carrier `FilterPicker`
			// above the table changes scope by NAVIGATING, so the page reads the active
			// carrier out of the URL query and listens for history changes.
			// SP-10 spec D11 (increment 7): `wc-date` carries `@woocommerce/date`, which
			// resolves `DateRangeFilterPicker`'s `period`/`compare` into real dates.
			// `wc-currency` carries `@woocommerce/currency`, whose `CurrencyFactory()`
			// instance `AdvancedFilters` requires as its own `currency` prop.
			//
			// `wc-settings` (`@woocommerce/settings`) is READ defensively at runtime
			// (`window.wc.wcSettings?.getSetting(...)`, for the order-status filter's
			// options — a WooCommerce Core admin setting, not ours) but deliberately
			// NOT declared as a script dependency here. Measured against WC 11.1.0:
			// it is only CONDITIONALLY registered (`WCAdminAssets.php:460` guards it
			// with `wp_script_is( 'wc-settings', 'registered' )` rather than assuming
			// it), so a hard dependency on a handle that may not exist drops this
			// entire bundle SILENTLY (WP_Dependencies cannot resolve the chain) — the
			// page would render nothing and read as a broken build, not a missing
			// handle. It is also already pulled in TRANSITIVELY whenever it exists:
			// the same `WCAdminAssets` method injects it into `wc-currency` and
			// `wc-navigation`, both already declared below. And declaring it directly
			// is a known error condition when a script prints in the header
			// (`AssetsController.php:534-556`) — WooCommerce swaps in an error handle
			// for that. So: consume it opportunistically, never require it.
			$dependencies = array_merge(
				(array) $asset['dependencies'],
				[ 'wc-components', 'wc-navigation', 'wc-admin-app', 'wc-date', 'wc-currency' ]
			);

			$build_url     = $plugin->get_framework_assets_url() . '/build/shipping-orders-page';
			$style_path    = $plugin->get_framework_path() . '/assets/build/shipping-orders-page/style-index.css';
			$style_version = file_exists( $style_path ) ? (string) filemtime( $style_path ) : $asset['version'];

			wp_enqueue_style( 'woodev-shipping-orders-page', $build_url . '/style-index.css', [ 'wc-components' ], $style_version );
			wp_enqueue_script( 'woodev-shipping-orders-page', $build_url . '/index.js', $dependencies, $asset['version'], true );

			wp_add_inline_script(
				'woodev-shipping-orders-page',
				'window.woodevShippingOrders = ' . wp_json_encode(
					[
						'restRoot'         => esc_url_raw( rest_url( \Woodev_REST_V1_Registrar::ROUTE_NAMESPACE . '/shipping/orders' ) ),
						'nonce'            => wp_create_nonce( 'wp_rest' ),
						'providers'        => $this->build_bootstrap_providers(),
						// The delivery-status options this shop can actually produce (#837
						// defect 4) — see build_reachable_delivery_statuses() for why the
						// full canonical list was wrong to offer.
						'deliveryStatuses' => $this->build_reachable_delivery_statuses(),
					]
				) . ';',
				'before'
			);
		}

		/**
		 * Whether the current admin screen is a WooCommerce Admin (`wc-admin`) page.
		 *
		 * A protected, overridable seam, not a Brain-Monkey-stubbed function call:
		 * `wc_admin_is_registered_page()` does not exist at all under Brain Monkey (WC's
		 * `wc-admin` bootstrap is never loaded there), and Brain Monkey/Patchwork's
		 * function redefinition leaks `function_exists()` as permanently `true` for the
		 * rest of that PHPUnit process once a symbol is touched once — the same
		 * constraint `LocationControllerTest` documents against `WC()`. Removing `final`
		 * from this class (increment 2b rewrite) exists so a test can override this one
		 * method instead, the same seam shape {@see Orders_Query::is_hpos_enabled()}
		 * already uses.
		 *
		 * @since 2.0.2
		 *
		 * @return bool
		 */
		protected function is_wc_admin_screen(): bool {
			return function_exists( 'wc_admin_is_registered_page' ) && wc_admin_is_registered_page();
		}

		/**
		 * Builds the inlined provider list: the aggregate entry first, then one per
		 * registered provider — `id` and `label`, and nothing else.
		 *
		 * The list itself has to be inlined because the carrier picker renders before the
		 * first REST response arrives; WHICH carriers exist is a fact about the site, and
		 * a page-render-time answer to it is a correct one.
		 *
		 * ⚠ THE COUNTS ARE GONE (#855), and they were not merely redundant here. «How many
		 * orders does this carrier have» is a question that includes the page's filters —
		 * period, scope, statuses — and the bootstrap is written once per page load,
		 * before any of them exist. With «С начала недели» picked the picker read «СДЭК
		 * (71)» beside a table of four, and the number that disagreed with the table was
		 * the one the merchant would carry away. They now travel in the response that
		 * builds the rows, counted under the very same request — see
		 * {@see \Woodev\Framework\Shipping\Rest_Api\Orders_Controller::build_carrier_counts()}.
		 *
		 * @since 2.0.2
		 *
		 * @return array<int,array{id:string,label:string}>
		 */
		private function build_bootstrap_providers(): array {
			$entries   = [];
			$entries[] = [
				'id'    => 'all',
				'label' => __( 'Все перевозчики', 'woodev-plugin-framework' ),
			];

			foreach ( $this->get_providers() as $provider ) {
				$entries[] = [
					'id'    => $provider->get_id(),
					'label' => $provider->get_label(),
				];
			}

			return $entries;
		}

		/**
		 * The canonical delivery statuses THIS SHOP can actually produce — the option
		 * set the delivery-status filter offers (#837 defect 4).
		 *
		 * ⚠ The filter used to offer all ten canonical states unconditionally, and one
		 * of them was unreachable on every shop measured: **no `status_map` anywhere
		 * names `pending`** — both fixtures send their «just created» raw status to
		 * `created` instead (measured s129, and it is why `delivery_status=pending`
		 * returned 0 on the rig). A merchant picking «Ожидает отправки» therefore got
		 * an empty table and concluded the filter was broken. The list is derived here
		 * rather than curated, so it stays true for whatever carriers a shop actually
		 * has, and starts offering a state the day some carrier maps to it.
		 *
		 * `UNKNOWN` is always included and is not derived: it is what
		 * {@see \Woodev\Framework\Shipping\Order\Delivery_Status::resolve()} returns for
		 * a raw status a provider left unmapped AND for an order carrying no status meta
		 * at all, so it is reachable on every shop that has any orders — including one
		 * whose providers declare no `status_map` between them.
		 *
		 * Order follows {@see Delivery_Status::canonical_states()} rather than the order
		 * providers happen to be registered in, so the dropdown reads the same on every
		 * shop.
		 *
		 * @since 2.0.2
		 *
		 * @return array<int,string> canonical slugs, always non-empty (`unknown` at least).
		 */
		private function build_reachable_delivery_statuses(): array {
			$produced = [];

			foreach ( $this->get_providers() as $provider ) {
				foreach ( $provider->get_status_map() as $canonical ) {
					$produced[ $canonical ] = true;
				}
			}

			$reachable = [];

			foreach ( Delivery_Status::canonical_states() as $canonical ) {
				if ( isset( $produced[ $canonical ] ) ) {
					$reachable[] = $canonical;
				}
			}

			$reachable[] = Delivery_Status::UNKNOWN;

			return $reachable;
		}

		/**
		 * Returns any registered plugin to source framework asset paths/version from.
		 *
		 * @since 2.0.2
		 *
		 * @return \Woodev_Plugin|null
		 */
		private function get_asset_plugin(): ?\Woodev_Plugin {
			return $this->plugin;
		}

		/**
		 * Registers the REST controller through the `woodev/v1` registrar.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function register_rest(): void {
			\Woodev_REST_V1_Registrar::register_controller( new Orders_Controller( $this ) );
		}

		/**
		 * Translates {@see Orders_Query}'s custom query vars — marker keys, plus the
		 * optional delivery-status, tracking-presence, pickup-point-presence and
		 * export-presence filter clauses (SP-10 spec D10; pickup-point added #836,
		 * export-presence added #841) — into one real `meta_query` on the legacy CPT
		 * order datastore.
		 *
		 * On HPOS, {@see Orders_Query::build_args()} emits `meta_query` directly —
		 * measured correct against a real HPOS install (SP-10 spec M2). On the legacy CPT
		 * datastore WooCommerce's `WC_Order_Data_Store_CPT` does not support a
		 * `meta_query` arg at all: passing one fires `_doing_it_wrong` (WC ≥9.2) and
		 * silently returns UNFILTERED results — every carrier's orders leaking into every
		 * tab, the worst version of this bug because it fails open, not closed. All three
		 * shipped carrier plugins solve exactly this the same way — a custom query var,
		 * translated into `meta_query` through this exact filter — so this mirrors them
		 * instead of inventing a second mechanism, and now does the same for every new
		 * meta-based filter D10 added, not only the marker-key scope: each of
		 * {@see Orders_Query::QUERY_VAR_MARKER_KEYS},
		 * {@see Orders_Query::QUERY_VAR_STATUS_CLAUSES},
		 * {@see Orders_Query::QUERY_VAR_TRACKING_CLAUSES},
		 * {@see Orders_Query::QUERY_VAR_PICKUP_POINT_CLAUSES} and
		 * {@see Orders_Query::QUERY_VAR_EXPORTED_CLAUSES}, when present, becomes one
		 * `meta_query` part, and the parts are ANDed together through
		 * {@see Orders_Query::combine_meta_queries()} — the SAME combination rule
		 * {@see Orders_Query::build_args()} uses on HPOS, so the two datastore paths
		 * cannot silently diverge on what any of these filters mean.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,mixed> $query      WP_Query-shaped args the CPT datastore is building.
		 * @param array<string,mixed> $query_vars the original wc_get_orders() args.
		 * @return array<string,mixed>
		 */
		public function translate_marker_keys_query_var( array $query, array $query_vars ): array {
			$parts = [];

			if ( array_key_exists( Orders_Query::QUERY_VAR_MARKER_KEYS, $query_vars ) ) {
				$parts[] = Orders_Query::meta_query_for_keys( (array) $query_vars[ Orders_Query::QUERY_VAR_MARKER_KEYS ] );
			}

			if ( array_key_exists( Orders_Query::QUERY_VAR_STATUS_CLAUSES, $query_vars ) ) {
				$parts[] = Orders_Query::meta_query_for_clauses( (array) $query_vars[ Orders_Query::QUERY_VAR_STATUS_CLAUSES ] );
			}

			if ( array_key_exists( Orders_Query::QUERY_VAR_TRACKING_CLAUSES, $query_vars ) ) {
				$parts[] = Orders_Query::meta_query_for_clauses( (array) $query_vars[ Orders_Query::QUERY_VAR_TRACKING_CLAUSES ] );
			}

			if ( array_key_exists( Orders_Query::QUERY_VAR_PICKUP_POINT_CLAUSES, $query_vars ) ) {
				$parts[] = Orders_Query::meta_query_for_clauses( (array) $query_vars[ Orders_Query::QUERY_VAR_PICKUP_POINT_CLAUSES ] );
			}

			if ( array_key_exists( Orders_Query::QUERY_VAR_EXPORTED_CLAUSES, $query_vars ) ) {
				$parts[] = Orders_Query::meta_query_for_clauses( (array) $query_vars[ Orders_Query::QUERY_VAR_EXPORTED_CLAUSES ] );
			}

			if ( [] === $parts ) {
				return $query;
			}

			$query['meta_query'] = Orders_Query::combine_meta_queries( $parts ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- translating the framework's own custom query vars; the only CPT-safe way to scope/filter this query (SP-10 spec D10).

			/**
			 * Filters the CPT-datastore query after the marker-keys var is translated.
			 *
			 * @since 2.0.2
			 *
			 * @param array<string,mixed> $query      translated query.
			 * @param array<string,mixed> $query_vars the original wc_get_orders() args.
			 */
			$filtered = apply_filters( 'woodev_shipping_orders_cpt_query_args', $query, $query_vars );

			return is_array( $filtered ) ? $filtered : $query;
		}

		/**
		 * Resets registration state. Test-only.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public function reset_for_tests(): void {
			remove_action( 'admin_menu', [ $this, 'register_page' ], 40 );
			remove_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
			remove_action( 'rest_api_init', [ $this, 'register_rest' ], 5 );
			remove_filter( 'woocommerce_order_data_store_cpt_get_orders_query', [ $this, 'translate_marker_keys_query_var' ], 10 );

			$this->providers = [];
			$this->hooked    = false;
			$this->plugin    = null;
		}
	}

endif;
