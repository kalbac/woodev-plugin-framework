<?php
/**
 * Publishes delivery status changes without any carrier code.
 *
 * @since 2.0.2
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Order;

use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Order\\Delivery_Status_Watcher' ) ) :
	/**
	 * Watches the order meta that decides an order's canonical delivery status.
	 *
	 * The framework does not write a carrier's raw status itself — the carrier's webhook handler, tracking
	 * sync or cron poll does, in carrier code — but it knows WHERE each carrier writes it
	 * ({@see \Woodev\Framework\Shipping\Admin\Orders\Orders_Provider::get_status_meta_key()}) and owns the
	 * cancellation marker ({@see Shipment_Cancellation}). Every write of either key, whatever path made it
	 * (webhook, tracking handler, cron re-poll, manual edit, the framework's own cancel/export) marks the
	 * order dirty; at `shutdown`, once all of the request's writes are in, each dirty order is reconciled
	 * with {@see Delivery_Status_Events::sync()}, which publishes `woodev_shipping_delivery_status_changed`
	 * only when the canonical state really changed. A carrier therefore gets buyer emails and the event for
	 * extensions with no extra code. Works for both order storages: HPOS (`*_order_meta`) and posts
	 * (`*_post_meta`); a write seen through both is reconciled once.
	 *
	 * @since 2.0.2
	 */
	final class Delivery_Status_Watcher {
		/**
		 * The one instance.
		 *
		 * @var self|null
		 */
		private static ?self $instance = null;

		/**
		 * Orders touched in this request: order id => whether the carrier status was FIRST written.
		 *
		 * @var array<int,bool>
		 */
		private array $dirty = [];

		/**
		 * Watched meta keys: key => 'cancel' or a provider id; null until first needed.
		 *
		 * @var array<string,string>|null
		 */
		private ?array $keys = null;

		/**
		 * The watcher.
		 *
		 * @since 2.0.2
		 * @return self
		 */
		public static function instance(): self {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Starts watching. Idempotent.
		 *
		 * @since 2.0.2
		 * @return void
		 */
		public function register(): void {
			foreach ( [ 'added', 'updated' ] as $kind ) {
				add_action( $kind . '_order_meta', [ $this, 'on_' . $kind . '_meta' ], 10, 4 );
				add_action( $kind . '_post_meta', [ $this, 'on_' . $kind . '_post_meta' ], 10, 4 );
			}

			add_action( 'deleted_order_meta', [ $this, 'on_deleted_meta' ], 10, 4 );
			add_action( 'deleted_post_meta', [ $this, 'on_deleted_post_meta' ], 10, 4 );
			add_action( 'shutdown', [ $this, 'process' ], 20 );
		}

		/**
		 * Drops the singleton and its queued orders. For tests only.
		 *
		 * @since 2.0.2
		 * @return void
		 */
		public static function reset_for_tests(): void {
			self::$instance = null;
		}

		/**
		 * Forgets the cached key set — call when a provider is registered.
		 *
		 * @since 2.0.2
		 * @return void
		 */
		public static function flush(): void {
			if ( null !== self::$instance ) {
				self::$instance->keys = null;
			}
		}

		/** @since 2.0.2 @param int $meta_id Meta id. @param int $order_id Order id. @param string $key Meta key. @param mixed $value Value. @return void */
		public function on_added_meta( $meta_id, $order_id, $key, $value ): void {
			$this->touch( (int) $order_id, (string) $key, true );
		}

		/** @since 2.0.2 @param int $meta_id Meta id. @param int $order_id Order id. @param string $key Meta key. @param mixed $value Value. @return void */
		public function on_updated_meta( $meta_id, $order_id, $key, $value ): void {
			$this->touch( (int) $order_id, (string) $key, false );
		}

		/** @since 2.0.2 @param mixed $meta_ids Meta ids. @param int $order_id Order id. @param string $key Meta key. @param mixed $value Value. @return void */
		public function on_deleted_meta( $meta_ids, $order_id, $key, $value ): void {
			$this->touch( (int) $order_id, (string) $key, false );
		}

		/** @since 2.0.2 @param int $meta_id Meta id. @param int $post_id Post id. @param string $key Meta key. @param mixed $value Value. @return void */
		public function on_added_post_meta( $meta_id, $post_id, $key, $value ): void {
			$this->touch_post( (int) $post_id, (string) $key, true );
		}

		/** @since 2.0.2 @param int $meta_id Meta id. @param int $post_id Post id. @param string $key Meta key. @param mixed $value Value. @return void */
		public function on_updated_post_meta( $meta_id, $post_id, $key, $value ): void {
			$this->touch_post( (int) $post_id, (string) $key, false );
		}

		/** @since 2.0.2 @param mixed $meta_ids Meta ids. @param int $post_id Post id. @param string $key Meta key. @param mixed $value Value. @return void */
		public function on_deleted_post_meta( $meta_ids, $post_id, $key, $value ): void {
			$this->touch_post( (int) $post_id, (string) $key, false );
		}

		/**
		 * Reconciles every order touched in this request. Runs at `shutdown`.
		 *
		 * @since 2.0.2
		 * @return void
		 */
		public function process(): void {
			$dirty       = $this->dirty;
			$this->dirty = [];

			foreach ( $dirty as $order_id => $first_write ) {
				$order = wc_get_order( $order_id );

				if ( ! $order instanceof \WC_Order ) {
					continue;
				}

				$provider = Orders_Registry::instance()->resolve_provider_for_order( $order );

				if ( null === $provider || null === $provider->get_status_meta_key() ) {
					continue;
				}

				Delivery_Status_Events::sync( $order, $provider, $first_write );
			}
		}

		/**
		 * Post-meta variant: only order posts count.
		 *
		 * @param int    $post_id Post id.
		 * @param string $key     Meta key.
		 * @param bool   $added   Whether the meta was added (first write).
		 * @return void
		 */
		private function touch_post( int $post_id, string $key, bool $added ): void {
			if ( null === $this->watched( $key ) || 'shop_order' !== get_post_type( $post_id ) ) {
				return;
			}

			$this->touch( $post_id, $key, $added );
		}

		/**
		 * Marks an order dirty when the key is one that decides its canonical status.
		 *
		 * @param int    $order_id Order id.
		 * @param string $key      Meta key.
		 * @param bool   $added    Whether the meta was added (first write).
		 * @return void
		 */
		private function touch( int $order_id, string $key, bool $added ): void {
			if ( $order_id <= 0 || null === $this->watched( $key ) ) {
				return;
			}

			// Only a first write of the CARRIER status can be the first status; the cancellation marker never is.
			$first = $added && Shipment_Cancellation::CANCELLED_AT_META !== $key;

			$this->dirty[ $order_id ] = ( $this->dirty[ $order_id ] ?? false ) || $first;
		}

		/**
		 * Whether a meta key is watched: the cancellation marker or any provider's status key.
		 *
		 * @param string $key Meta key.
		 * @return string|null `cancel` or the provider id; null when not watched.
		 */
		private function watched( string $key ): ?string {
			if ( null === $this->keys ) {
				$keys      = [ Shipment_Cancellation::CANCELLED_AT_META => 'cancel' ];
				$providers = Orders_Registry::instance()->get_providers();

				foreach ( $providers as $provider ) {
					if ( null !== $provider->get_status_meta_key() ) {
						$keys[ $provider->get_status_meta_key() ] = $provider->get_id();
					}
				}

				// A set built before any carrier registered would hide every status key for the rest of the request.
				if ( [] === $providers ) {
					return $keys[ $key ] ?? null;
				}

				$this->keys = $keys;
			}

			return $this->keys[ $key ] ?? null;
		}
	}
endif;
