<?php
/**
 * Delivery status change event seam.
 *
 * @since 2.0.2
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Order;

use Woodev\Framework\Shipping\Admin\Orders\{Order_Actions, Orders_Provider};

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Order\\Delivery_Status_Events' ) ) :

	/**
	 * Publishes canonical shipment status changes after carrier metadata is persisted.
	 *
	 * The framework itself watches the order meta a carrier writes its raw status into (and the
	 * framework's own cancellation marker) with {@see Delivery_Status_Watcher}, so a carrier gets the
	 * event — and the buyer emails built on it — without calling anything. {@see self::notify()}
	 * stays public for a carrier that records its status in a way the watcher cannot see.
	 *
	 * The event fires once per REAL change: the last canonical state published for an order is kept in
	 * order meta, and a call that finds the same state again publishes nothing.
	 *
	 * Each real change is also appended, with its time and the carrier's raw status, to the order's
	 * {@see Delivery_Status_Log} — the only history of delivery states the framework keeps. The decision
	 * (read the published state, compare, journal, remember the new state) is made under an
	 * {@see Order_Lock} on a freshly re-read order, so two requests that overlap on one order cannot overwrite
	 * each other's journal entry (#1564).
	 *
	 * @since 2.0.2
	 */
	final class Delivery_Status_Events {
		/**
		 * Prefix of the order meta that remembers the last canonical state published for a carrier.
		 * Installed-site data contract: keep byte-for-byte.
		 *
		 * @var string
		 */
		public const BASELINE_META_PREFIX = '_woodev_delivery_status_published_';

		/**
		 * The {@see Order_Lock} scope that serialises the status decision of one order.
		 *
		 * @var string
		 */
		private const LOCK_SCOPE = 'status';

		/**
		 * Seconds a request waits for another request's status decision on the same order. The decision is a few
		 * meta reads and one save, so a held lock means a real overlap, not a slow job.
		 *
		 * @var int
		 */
		private const LOCK_WAIT = 5;

		/**
		 * Resolves and publishes a status change.
		 *
		 * Fires `woodev_shipping_delivery_status_changed` with the order, previous and current
		 * canonical states, and provider — only when the canonical state differs from the previous
		 * one. A null `$previous_status` means "use the last state this framework published for the
		 * order"; with none recorded it stays null (a first, unknown-before status).
		 *
		 * ⚠ The order's meta is re-read once the status lock is held, so persist the carrier's status
		 * (`$order->save()`, or its own meta write) BEFORE calling.
		 *
		 * ⚠ When the lock is not granted nothing is recorded — neither the published state nor the log — and the
		 * current state is still returned: the next call finds the same change and retries it.
		 *
		 * @since 2.0.2
		 * @param \WC_Order       $order           Shipment order.
		 * @param Orders_Provider $provider        Carrier descriptor.
		 * @param string|null     $previous_status Previous canonical state, if known.
		 * @return string Current canonical state.
		 */
		public static function notify( \WC_Order $order, Orders_Provider $provider, ?string $previous_status = null ): string {
			return self::reconcile( $order, $provider, $previous_status, false )['state'];
		}

		/**
		 * Reconciles an order's canonical state with the last one published, after meta writes.
		 *
		 * Used by {@see Delivery_Status_Watcher}. With no recorded state the order is adopted silently
		 * — unless its status was written for the first time in this request — so shipments that were
		 * already in flight when the framework started watching never send a buyer email for an old
		 * status the first time their carrier re-polls them.
		 *
		 * @since 2.0.2
		 * @param \WC_Order       $order       Shipment order.
		 * @param Orders_Provider $provider    Carrier descriptor.
		 * @param bool            $first_write Whether the carrier status was first written in this request.
		 * @return string|null The published current state, or null when nothing was published.
		 */
		public static function sync( \WC_Order $order, Orders_Provider $provider, bool $first_write = false ): ?string {
			$outcome = self::reconcile( $order, $provider, null, ! $first_write );

			return $outcome['published'] ? $outcome['state'] : null;
		}

		/**
		 * The last canonical state published for this order and carrier, or null.
		 *
		 * @since 2.0.2
		 * @param \WC_Order       $order    Shipment order.
		 * @param Orders_Provider $provider Carrier descriptor.
		 * @return string|null
		 */
		public static function baseline( \WC_Order $order, Orders_Provider $provider ): ?string {
			$value = (string) \Woodev_Order_Compatibility::get_order_meta( $order, self::BASELINE_META_PREFIX . $provider->get_id() );

			return '' !== $value ? $value : null;
		}

		/**
		 * The one decision behind {@see self::notify()} and {@see self::sync()}.
		 *
		 * The cheap path first: a repeat of the published state that has nothing new for the log returns without a lock,
		 * which is every ordinary re-poll. Everything else is decided inside {@see Order_Lock} on a re-read order — the
		 * published state, the current state, the log entry and the new published state — and saved once, so a request that
		 * waited for the lock sees what the one before it wrote instead of overwriting it with a stale snapshot. The event
		 * fires after the lock is released.
		 *
		 * @param \WC_Order       $order            Shipment order.
		 * @param Orders_Provider $provider         Carrier descriptor.
		 * @param string|null     $explicit_previous The caller's own previous state; null means the published one.
		 * @param bool            $adopt_unseen     Whether an order with no published state is adopted silently.
		 * @return array{state:string,published:bool} The current state, and whether the event fired.
		 */
		private static function reconcile( \WC_Order $order, Orders_Provider $provider, ?string $explicit_previous, bool $adopt_unseen ): array {
			$resolved = Order_Actions::resolve_delivery_status( $order, $provider );
			$baseline = self::baseline( $order, $provider );
			$previous = $explicit_previous ?? $baseline;
			$order_id = (int) $order->get_id();

			// Nothing to decide: both the caller's previous state and the published one are the current state.
			if ( null !== $previous && $previous === $resolved['canonical'] && $baseline === $resolved['canonical']
				&& ! Delivery_Status_Log::awaits_handover( Delivery_Status_Log::read( $order, $provider ), $provider, (string) $resolved['raw'] ) ) {
				return [
					'state'     => $resolved['canonical'],
					'published' => false,
				];
			}

			// Without an id nothing can be locked or remembered, so nothing is published: it would repeat on every call.
			if ( $order_id <= 0 || ! Order_Lock::acquire( self::LOCK_SCOPE, $order_id, self::LOCK_WAIT ) ) {
				return [
					'state'     => $resolved['canonical'],
					'published' => false,
				];
			}

			try {
				// The caller loaded the order before it waited for the lock; decide on what is persisted now.
				$order->read_meta_data( true );

				$fresh    = self::resolve_fresh( $order, $provider );
				$baseline = (string) $order->get_meta( self::BASELINE_META_PREFIX . $provider->get_id() );
				$baseline = '' !== $baseline ? $baseline : null;
				$previous = $explicit_previous ?? $baseline;
				$state    = $fresh['state'];
				$changed  = null === $previous || $previous !== $state;
				$silent   = null === $previous && $adopt_unseen;
				$dirty    = false;

				// An adopted order was in flight before logging began: it gets no entry, so its log starts at its next change.
				if ( ! $silent ) {
					$dirty = Delivery_Status_Log::stage( $order, $provider, $state, $fresh['raw'], $changed );
				}

				if ( $baseline !== $state ) {
					$order->update_meta_data( self::BASELINE_META_PREFIX . $provider->get_id(), $state );
					$dirty = true;
				}

				if ( $dirty ) {
					$order->save_meta_data();
				}
			} finally {
				Order_Lock::release( self::LOCK_SCOPE, $order_id );
			}

			$published = $changed && ! $silent;

			if ( $published ) {
				/**
				 * Fires when a carrier has persisted a shipment status change.
				 *
				 * @since 2.0.2
				 * @param \WC_Order       $order           Shipment order.
				 * @param string|null     $previous_status Previous canonical state.
				 * @param string          $current_status  Current canonical state.
				 * @param Orders_Provider $provider        Carrier descriptor.
				 */
				do_action( 'woodev_shipping_delivery_status_changed', $order, $previous, $state, $provider );
			}

			return [
				'state'     => $state,
				'published' => $published,
			];
		}

		/**
		 * The canonical state and the raw status behind it, read from the order object itself.
		 *
		 * Used inside the lock right after {@see \WC_Order::read_meta_data()}: the object's own meta is the freshly read
		 * copy, whereas the compatibility getter on the legacy post tables can answer from a cache filled before the lock.
		 * The rule is the one of {@see Order_Actions::resolve_delivery_status()}.
		 *
		 * @param \WC_Order       $order    Shipment order.
		 * @param Orders_Provider $provider Carrier descriptor.
		 * @return array{state:string,raw:string}
		 */
		private static function resolve_fresh( \WC_Order $order, Orders_Provider $provider ): array {
			$key = $provider->get_status_meta_key();
			$raw = null === $key ? '' : (string) $order->get_meta( $key );

			$resolved = Delivery_Status::resolve(
				'' !== $raw ? $raw : null,
				$provider->get_status_map(),
				$provider->get_status_labels(),
				Shipment_Cancellation::is_cancelled( $order )
			);

			return [
				'state' => $resolved['canonical'],
				'raw'   => $raw,
			];
		}
	}

endif;
