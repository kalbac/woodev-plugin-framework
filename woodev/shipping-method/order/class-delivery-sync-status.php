<?php
/**
 * Delivery-status sync freshness
 *
 * @since 2.0.2
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Order\\Delivery_Sync_Status' ) ) :

	/**
	 * Records and reads *when a carrier's delivery status was last refreshed*
	 * (SP-10 spec D9, #828). Grepping every shipped plugin found no stored last-sync
	 * timestamp anywhere — nothing records this today, on either refresh path — so this
	 * class is the seam both paths write through:
	 *
	 * - {@see Abstract_Webhook_Handler::handle_request()} calls {@see self::record_last_updated()}
	 *   after processing a verified inbound payload;
	 * - a carrier's own cron callback calls the same method after its update run — the
	 *   framework does not schedule or run that cron itself (SP-8), it only offers the
	 *   place to say "I just did".
	 *
	 * **Storage: one option per carrier**, not one option holding every carrier's
	 * timestamp. Two carrier plugins refresh independently — one on its own cron, another
	 * off a webhook that can arrive at any moment — and a single shared option would make
	 * every write a read-modify-write race between them. A per-carrier option key is
	 * written only by that carrier and never needs to merge against a sibling's value.
	 * The option is registered with `autoload => false`: it is read only on the orders
	 * page and its REST route, never on a normal request.
	 *
	 * «Next update» needs no storage at all — it is {@see self::get_next_update()} wrapping
	 * `wp_next_scheduled()` on the carrier's own cron hook, which is why that hook name
	 * lives on {@see \Woodev\Framework\Shipping\Admin\Orders\Orders_Provider} rather than
	 * here: this class has no notion of "which hook", only "which carrier last wrote".
	 *
	 * @since 2.0.2
	 */
	final class Delivery_Sync_Status {

		/**
		 * Option-name prefix a carrier id is appended to (sanitized via `sanitize_key()`).
		 * Namespaced and deliberately explicit — this becomes an installed-site data
		 * contract the moment a carrier plugin starts writing to it.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		const OPTION_PREFIX = 'woodev_shipping_delivery_synced_';

		/**
		 * Records that `$carrier_id` just refreshed its delivery statuses.
		 *
		 * @since 2.0.2
		 *
		 * @param string   $carrier_id the {@see \Woodev\Framework\Shipping\Admin\Orders\Orders_Provider}
		 *                             id this sync belongs to. A blank id is a no-op — there is
		 *                             nothing to key the record under.
		 * @param int|null $timestamp  unix timestamp to record; defaults to now. Exposed mainly
		 *                             for tests and for a caller replaying a webhook it received
		 *                             late.
		 * @return void
		 */
		public static function record_last_updated( string $carrier_id, ?int $timestamp = null ): void {
			if ( '' === $carrier_id ) {
				return;
			}

			$timestamp = $timestamp ?? time();

			/**
			 * Filters the timestamp about to be recorded as a carrier's last delivery-status sync.
			 *
			 * @since 2.0.2
			 *
			 * @param int    $timestamp  unix timestamp about to be stored.
			 * @param string $carrier_id the carrier id the sync belongs to.
			 */
			$timestamp = (int) apply_filters( 'woodev_shipping_delivery_sync_timestamp', $timestamp, $carrier_id );

			update_option( self::option_name( $carrier_id ), $timestamp, false );

			/**
			 * Fires after a carrier's last-sync timestamp has been recorded.
			 *
			 * @since 2.0.2
			 *
			 * @param string $carrier_id the carrier id the sync belongs to.
			 * @param int    $timestamp  the timestamp that was recorded.
			 */
			do_action( 'woodev_shipping_delivery_sync_recorded', $carrier_id, $timestamp );
		}

		/**
		 * Returns when `$carrier_id` last refreshed its delivery statuses, or null when it
		 * never has — a real, distinct state (SP-10 spec D9), never coerced to zero.
		 *
		 * @since 2.0.2
		 *
		 * @param string $carrier_id carrier id to look up.
		 * @return int|null
		 */
		public static function get_last_updated( string $carrier_id ): ?int {
			if ( '' === $carrier_id ) {
				return null;
			}

			$value = get_option( self::option_name( $carrier_id ), null );

			return ( is_numeric( $value ) && (int) $value > 0 ) ? (int) $value : null;
		}

		/**
		 * Returns when `$cron_hook` will next fire, or null when the carrier has no cron
		 * concept of its own — a webhook-only carrier declares no
		 * {@see \Woodev\Framework\Shipping\Admin\Orders\Orders_Provider::get_cron_hook()},
		 * and «Next update» is then simply absent for it, never zero or "unknown".
		 *
		 * @since 2.0.2
		 *
		 * @param string|null $cron_hook the carrier's cron hook name, or null.
		 * @return int|null
		 */
		public static function get_next_update( ?string $cron_hook ): ?int {
			if ( null === $cron_hook || '' === $cron_hook ) {
				return null;
			}

			$next = wp_next_scheduled( $cron_hook );

			return false !== $next ? (int) $next : null;
		}

		/**
		 * Builds the per-carrier option name.
		 *
		 * @since 2.0.2
		 *
		 * @param string $carrier_id carrier id.
		 * @return string
		 */
		private static function option_name( string $carrier_id ): string {
			return self::OPTION_PREFIX . sanitize_key( $carrier_id );
		}
	}

endif;
