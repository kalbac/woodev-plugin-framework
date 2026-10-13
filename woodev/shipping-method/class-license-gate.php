<?php
/**
 * The licence gate of a carrier plugin — the wording and the one question every gate asks (#1270).
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\License_Gate' ) ) :

	/**
	 * A carrier plugin works while the server-signed statement says its licence is in force
	 * ({@see \Woodev_Plugins_License::is_license_in_force()}); this class is the shipping layer's
	 * single door to that answer and the single home of what a merchant reads when it is «no».
	 *
	 * What is gated, and what is deliberately NOT:
	 *
	 * - gated — the checkout rate ({@see Shipping_Method::calculate_shipping()}), a NEW carrier action
	 *   (export, manual or automatic; cancel; refusal; a document requested from the carrier);
	 * - never gated — status polling, tracking, viewing orders, the settings pages and saving them,
	 *   anything the buyer sees about an order already placed.
	 *
	 * There is no switch that turns the gate off: no constant, filter or option. The only input is the
	 * signed claim.
	 *
	 * @since 2.0.2
	 */
	final class License_Gate {

		/** @var int how many days before the end of the licence the «renew it» reminder starts */
		public const EXPIRY_WARNING_DAYS = 30;

		/** The package key the licence state of the registered carriers travels under (#1270). */
		public const PACKAGE_KEY = 'woodev_license_state';

		/** @var array<string, \Woodev_Plugin> the carrier plugins whose licence state keys the package, by plugin id */
		private static array $tracked = [];

		/** @var bool whether this request already wired the package filter */
		private static bool $hooked = false;

		/**
		 * Whether the plugin's licence is in force.
		 *
		 * @since 2.0.2
		 *
		 * @param \Woodev_Plugin $plugin the carrier plugin.
		 * @return bool
		 */
		public static function is_in_force( \Woodev_Plugin $plugin ): bool {

			// A plugin with no store product has nothing to license: answered before the licence engine is
			// even built, which keeps this off the engine's construction on every rate calculation.
			if ( ! \Woodev_Plugins_License::has_store_product( $plugin->get_download_id() ) ) {
				return true;
			}

			return $plugin->get_license_instance()->is_license_in_force();
		}

		/**
		 * The refusal sentence for a shipment handler's NEW carrier action, or null when it may go on.
		 *
		 * The handler belongs to the plugin that registered it with the shipping orders registry. A handler
		 * registered with none has no licence to ask and is not gated.
		 *
		 * @since 2.0.2
		 *
		 * @param Order\Abstract_Shipment_Handler $handler the handler about to call the carrier.
		 * @param string                          $action  what cannot be done, as an infinitive phrase.
		 * @return string|null
		 */
		public static function handler_refusal( Order\Abstract_Shipment_Handler $handler, string $action ): ?string {

			$plugin = Admin\Orders\Orders_Registry::instance()->get_handler_plugin( $handler );

			if ( null === $plugin || self::is_in_force( $plugin ) ) {
				return null;
			}

			return self::refusal( $action );
		}

		/**
		 * The licence page address. Built with {@see admin_url()}, not `menu_page_url()`, which answers ''
		 * in a REST or cron request — and a refusal is composed there too.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public static function page_url(): string {
			return admin_url( 'admin.php?page=woodev-licenses' );
		}

		/**
		 * The refusal a merchant reads when a new carrier action is not allowed. Plain text — the orders
		 * page, the order metabox and the document download all print it as text, so the address is
		 * written out rather than wrapped in a link.
		 *
		 * @since 2.0.2
		 *
		 * @param string $action what cannot be done, as an infinitive phrase («выгрузить заказ перевозчику»).
		 * @return string
		 */
		public static function refusal( string $action ): string {
			return sprintf(
				/* translators: 1: what the merchant tried to do, 2: the licence page address */
				__( 'Лицензия плагина не действует, поэтому нельзя %1$s. Введите или продлите ключ на странице лицензий: %2$s', 'woodev-plugin-framework' ),
				$action,
				self::page_url()
			);
		}

		/**
		 * The reason a store manager sees in place of a shipping method the gate hid from checkout
		 * ({@see Shipping_Method::report_hidden()}). Plain text for the same reason as {@see self::refusal()}.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public static function hidden_reason(): string {
			return sprintf(
				/* translators: %s: the licence page address */
				__( 'Способ доставки скрыт: лицензия плагина не действует. Введите или продлите ключ на странице лицензий: %s', 'woodev-plugin-framework' ),
				self::page_url()
			);
		}

		/**
		 * Whole days until the licence ends, when it is in force AND ends within
		 * {@see self::EXPIRY_WARNING_DAYS}; null otherwise (not in force, lifetime, licence-free, or later).
		 *
		 * @since 2.0.2
		 *
		 * @param \Woodev_Plugin $plugin the carrier plugin.
		 * @param int            $now    the current Unix time.
		 * @return int|null at least 1.
		 */
		public static function days_until_expiry( \Woodev_Plugin $plugin, int $now ): ?int {

			if ( ! \Woodev_Plugins_License::has_store_product( $plugin->get_download_id() ) ) {
				return null;
			}

			$license = $plugin->get_license_instance();

			if ( ! $license->is_license_in_force() ) {
				return null;
			}

			$expires = $license->get_license_expires();

			if ( null === $expires ) {
				return null;
			}

			// Whole days still ahead; the last partial day reads «1 дн.».
			$days = max( 1, (int) floor( ( $expires - $now ) / DAY_IN_SECONDS ) );

			return $days <= self::EXPIRY_WARNING_DAYS ? $days : null;
		}

		/**
		 * Asks the licence engine to refresh a missing signed claim soon (#1270) — called where the gate has just
		 * said «no» to a buyer's checkout. It only schedules a WP-Cron event (throttled per plugin, see
		 * {@see \Woodev_Plugins_License::schedule_claim_refresh()}), so the buyer's request does no licence-server
		 * call, and it can never break the checkout.
		 *
		 * @since 2.0.2
		 *
		 * @param \Woodev_Plugin $plugin the carrier plugin.
		 * @return void
		 */
		public static function request_claim_refresh( \Woodev_Plugin $plugin ): void {
			try {
				$plugin->get_license_instance()->schedule_claim_refresh();
			} catch ( \Throwable $throwable ) {
				return;
			}
		}

		/**
		 * Makes the plugin's licence state part of every shipping package (#1270).
		 *
		 * WooCommerce keeps a package's calculated rates in the customer session and serves them while the package
		 * hash is unchanged (`WC_Shipping::calculate_shipping_for_package()`); a licence turning on or off changes
		 * neither the cart nor the shipping cache generation, so without this the buyer keeps seeing the rates (or
		 * the absence of them) the old state produced. The state rides on the package, so a flip is a new hash and
		 * a recalculation.
		 *
		 * Fleet-wide: every shipping plugin calls this, the first call wires the one static filter, and N plugins
		 * still run it once (the same reasoning as {@see Fee_Payments::register()}).
		 *
		 * @since 2.0.2
		 *
		 * @param \Woodev_Plugin $plugin the carrier plugin.
		 * @return void
		 */
		public static function track_license_state( \Woodev_Plugin $plugin ): void {

			self::$tracked[ (string) $plugin->get_id() ] = $plugin;

			if ( self::$hooked ) {
				return;
			}

			self::$hooked = true;

			add_filter( 'woocommerce_cart_shipping_packages', [ self::class, 'add_license_state_to_packages' ] );
		}

		/**
		 * The licence state of every tracked carrier that has a store product, as one short deterministic string:
		 * `id:1,id:0` sorted by plugin id. Empty while no tracked plugin has a licence to ask — a shop with only
		 * licence-free carriers keeps its package hashes exactly as they were.
		 *
		 * Cheap: the engine memoizes its claim verification per request.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public static function license_state(): string {

			$ids = array_keys( self::$tracked );
			sort( $ids, SORT_STRING );

			$parts = [];

			foreach ( $ids as $id ) {
				$plugin = self::$tracked[ $id ];

				if ( ! \Woodev_Plugins_License::has_store_product( $plugin->get_download_id() ) ) {
					continue;
				}

				try {
					$in_force = self::is_in_force( $plugin );
				} catch ( \Throwable $throwable ) {
					// A broken engine must not take the whole cart down; the gate itself will refuse the rate.
					$in_force = false;
				}

				$parts[] = $id . ':' . ( $in_force ? '1' : '0' );
			}

			return implode( ',', $parts );
		}

		/**
		 * Adds {@see self::license_state()} to every shipping package.
		 *
		 * @internal Hooked on `woocommerce_cart_shipping_packages`; not for direct calls.
		 *
		 * @since 2.0.2
		 *
		 * @param mixed $packages the cart's shipping packages.
		 * @return mixed
		 */
		public static function add_license_state_to_packages( $packages ) {

			if ( ! is_array( $packages ) || [] === $packages ) {
				return $packages;
			}

			$state = self::license_state();

			if ( '' === $state ) {
				return $packages;
			}

			foreach ( $packages as $key => $package ) {
				if ( is_array( $package ) ) {
					$packages[ $key ][ self::PACKAGE_KEY ] = $state;
				}
			}

			return $packages;
		}

		/**
		 * Forgets the per-request wiring and the tracked plugins. Tests only.
		 *
		 * @internal
		 *
		 * @since 2.0.2
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$tracked = [];
			self::$hooked  = false;
		}
	}

endif;
