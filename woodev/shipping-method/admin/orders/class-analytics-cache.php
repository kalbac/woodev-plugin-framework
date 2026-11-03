<?php
/**
 * Delivery analytics — the cache of the aggregate answers
 *
 * @since 2.0.24
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Admin\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Admin\\Orders\\Analytics_Cache' ) ) :

	/**
	 * Keeps the analytics answers in transients (#711) so a page that is opened ten times a day reads the order
	 * tables once per ten minutes, not once per open.
	 *
	 * **Key.** The request, the provider signature and a DATA VERSION. Invalidation is bumping that version — one
	 * option write, no scan of transients — on the events that change what the aggregates say: an order is created, its
	 * status changes, it is exported, its delivery status changes, it is SAVED (`woocommerce_update_order`, fired by both
	 * datastores — an edit of an order's total, its shipping price or its shipping method changes no status and fires none of the others) or it
	 * is trashed, restored or deleted. The bump is DEBOUNCED to once a minute with a short
	 * transient lock, so a busy shop writes one option a minute at most; the price is that a change inside that minute
	 * waits for the TTL, which is why a range containing today lives only {@see self::TTL_LIVE} seconds.
	 *
	 * Transients work with and without a persistent object cache, so there is no second code path.
	 *
	 * @since 2.0.24
	 */
	final class Analytics_Cache {

		/**
		 * Option holding the data version (autoload off).
		 *
		 * @var string
		 */
		public const VERSION_OPTION = 'woodev_shipping_analytics_version';

		/**
		 * Transient that debounces {@see self::bump()}.
		 *
		 * @var string
		 */
		public const LOCK_TRANSIENT = 'woodev_shipping_analytics_bump_lock';

		/**
		 * Prefix of the answer transients.
		 *
		 * @var string
		 */
		public const KEY_PREFIX = 'woodev_ship_an_';

		/**
		 * Lifetime of an answer whose range contains today, in seconds.
		 *
		 * @var int
		 */
		public const TTL_LIVE = 600;

		/**
		 * Lifetime of an answer about days that are over, in seconds.
		 *
		 * @var int
		 */
		public const TTL_PAST = 3600;

		/**
		 * How long one bump shields the version from the next, in seconds.
		 *
		 * @var int
		 */
		public const BUMP_LOCK_SECONDS = 60;

		/**
		 * The events that change what the aggregates say.
		 *
		 * @var string[]
		 */
		private const INVALIDATING_HOOKS = [
			'woodev_shipping_delivery_status_changed',
			'woodev_shipping_order_exported',
			'woocommerce_new_order',
			'woocommerce_order_status_changed',
			'woocommerce_update_order',
			'woocommerce_trash_order',
			'woocommerce_untrash_order',
			'woocommerce_delete_order',
		];

		/**
		 * Subscribes {@see self::bump()} to the invalidating events. Idempotent: WordPress de-duplicates the same static callback.
		 *
		 * @since 2.0.24
		 *
		 * @return void
		 */
		public static function register(): void {
			foreach ( self::INVALIDATING_HOOKS as $hook ) {
				add_action( $hook, [ self::class, 'bump' ], 30, 0 );
			}
		}

		/**
		 * Moves the data version on — at most once per {@see self::BUMP_LOCK_SECONDS}.
		 *
		 * @since 2.0.24
		 *
		 * @return void
		 */
		public static function bump(): void {
			if ( false !== get_transient( self::LOCK_TRANSIENT ) ) {
				return;
			}

			set_transient( self::LOCK_TRANSIENT, '1', self::BUMP_LOCK_SECONDS );
			update_option( self::VERSION_OPTION, (string) microtime( true ), false );
		}

		/**
		 * The seconds an answer for a range ending on `$before` may be served from the cache.
		 *
		 * @since 2.0.24
		 *
		 * @param string        $before last day of the range, `Y-m-d`.
		 * @param \DateTimeZone $zone   the store timezone.
		 * @return int
		 */
		public static function ttl_for( string $before, \DateTimeZone $zone ): int {
			$today = ( new \DateTimeImmutable( 'now', $zone ) )->format( 'Y-m-d' );

			return $before >= $today ? self::TTL_LIVE : self::TTL_PAST;
		}

		/**
		 * Returns the cached answer for a key, or builds it, stores it and returns it.
		 *
		 * @since 2.0.24
		 *
		 * @param array<string,mixed> $key_parts everything the answer depends on.
		 * @param int                 $ttl       lifetime in seconds.
		 * @param callable            $build     `fn(): array`, run on a miss.
		 * @param bool                $refresh   true => ignore a stored answer (the result is stored).
		 * @return array{data:array<string,mixed>,cached:bool}
		 */
		public static function fetch( array $key_parts, int $ttl, callable $build, bool $refresh = false ): array {
			$name = self::KEY_PREFIX . md5( (string) wp_json_encode( [ (string) get_option( self::VERSION_OPTION, '0' ), $key_parts ] ) );

			if ( ! $refresh ) {
				$stored = get_transient( $name );

				if ( is_array( $stored ) ) {
					return [
						'data'   => $stored,
						'cached' => true,
					];
				}
			}

			$data = (array) call_user_func( $build );
			set_transient( $name, $data, $ttl );

			return [
				'data'   => $data,
				'cached' => false,
			];
		}
	}

endif;
