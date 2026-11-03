<?php
/**
 * Delivery status log
 *
 * @since 2.0.24
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Order;

use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Order\\Delivery_Status_Log' ) ) :

	/**
	 * A forward-only journal of the canonical states an order's shipment entered, each with the moment it was
	 * recorded (#1564).
	 *
	 * The framework used to keep only the LAST state it published ({@see Delivery_Status_Events::BASELINE_META_PREFIX}),
	 * so «how long from handover to delivery» could not be answered exactly. This is the missing history. It starts
	 * the day the framework that carries it is installed and is never back-filled: an order that was already in
	 * flight has no entries before then, and the analytics count only orders whose log shows both ends.
	 *
	 * **Storage.** One order meta per carrier, `_woodev_delivery_status_log_{provider id}`, a JSON string of
	 * `[ [ state, unix time ], … ]`, oldest first, UTC. JSON, not a serialised array: the analytics statement reads
	 * the raw value out of the database and must never `unserialize()` it. The write goes through the order CRUD
	 * ({@see \Woodev_Order_Compatibility}), so it is HPOS-safe.
	 *
	 * **Who writes.** Only {@see Delivery_Status_Events::notify()}, the one place a REAL change is decided. A state that
	 * repeats the last entry is not written, so a re-poll that re-announces the same state costs no write.
	 *
	 * **Cap.** The newest {@see self::CAP} entries are kept. A log that has reached the cap may have lost its beginning,
	 * so {@see self::handover_to_delivery()} refuses it: a missing figure is honest, a handover time that is really a
	 * later re-entry into «in transit» is not.
	 *
	 * @since 2.0.24
	 */
	final class Delivery_Status_Log {

		/**
		 * Prefix of the order meta that holds the log of a carrier. Installed-site data contract: keep byte-for-byte.
		 *
		 * @var string
		 */
		public const META_PREFIX = '_woodev_delivery_status_log_';

		/**
		 * The most entries a log keeps. A shipment passes through a handful of states; twenty leaves room for a carrier that
		 * flaps between two of them.
		 *
		 * @var int
		 */
		public const CAP = 20;

		/**
		 * The longest handover-to-delivery time the average accepts, in seconds (180 days): the same bound as the
		 * order-to-delivery figure, so a shipment that sat for half a year does not poison the mean.
		 *
		 * @var int
		 */
		public const MAX_SECONDS = 15552000;

		/**
		 * Canonical states that mean the carrier holds the parcel: it is on the road, or already waiting for the buyer at a
		 * pickup point (a carrier that skips «in transit» in its feed still handed the parcel over).
		 *
		 * @var string[]
		 */
		private const HANDOVER_STATES = [ Delivery_Status::IN_TRANSIT, Delivery_Status::READY_FOR_PICKUP ];

		/**
		 * The order meta key of a carrier's log.
		 *
		 * @since 2.0.24
		 *
		 * @param Orders_Provider $provider carrier descriptor.
		 * @return string
		 */
		public static function meta_key( Orders_Provider $provider ): string {
			return self::META_PREFIX . $provider->get_id();
		}

		/**
		 * The log of an order and carrier, oldest entry first; empty when nothing was recorded.
		 *
		 * @since 2.0.24
		 *
		 * @param \WC_Order       $order    shipment order.
		 * @param Orders_Provider $provider carrier descriptor.
		 * @return array<int,array{0:string,1:int}> `[ state, unix time ]` entries.
		 */
		public static function read( \WC_Order $order, Orders_Provider $provider ): array {
			return self::decode( \Woodev_Order_Compatibility::get_order_meta( $order, self::meta_key( $provider ) ) );
		}

		/**
		 * Appends the state the shipment has just entered.
		 *
		 * Nothing is written when the state is the log's newest entry — one write per real change, none for a repeat.
		 *
		 * @since 2.0.24
		 *
		 * @param \WC_Order       $order    shipment order.
		 * @param Orders_Provider $provider carrier descriptor.
		 * @param string          $state    canonical state just entered.
		 * @param int|null        $at       unix time of the change; null means now.
		 * @return bool Whether an entry was written.
		 */
		public static function append( \WC_Order $order, Orders_Provider $provider, string $state, ?int $at = null ): bool {
			$entries = self::read( $order, $provider );
			$last    = end( $entries );

			if ( false !== $last && $last[0] === $state ) {
				return false;
			}

			$entries[] = [ $state, $at ?? time() ];

			\Woodev_Order_Compatibility::update_order_meta(
				$order,
				self::meta_key( $provider ),
				(string) wp_json_encode( array_slice( $entries, -self::CAP ) )
			);

			return true;
		}

		/**
		 * Turns a stored value into entries, dropping anything that is not a `[ state, time ]` pair.
		 *
		 * Takes the raw value so the analytics statement, which reads the meta column directly, shares the parser.
		 *
		 * @since 2.0.24
		 *
		 * @param mixed $stored the meta value as stored.
		 * @return array<int,array{0:string,1:int}>
		 */
		public static function decode( $stored ): array {
			$decoded = is_string( $stored ) && '' !== $stored ? json_decode( $stored, true ) : null;
			$entries = [];

			if ( ! is_array( $decoded ) ) {
				return $entries;
			}

			foreach ( $decoded as $entry ) {
				if ( is_array( $entry ) && isset( $entry[0], $entry[1] ) && is_string( $entry[0] ) && is_numeric( $entry[1] ) ) {
					$entries[] = [ $entry[0], (int) $entry[1] ];
				}
			}

			return $entries;
		}

		/**
		 * The seconds from the carrier receiving the parcel to the buyer receiving it, or null when the log cannot say.
		 *
		 * The handover is the FIRST entry in a state that means the carrier has the parcel; the delivery is the first
		 * `delivered` entry AFTER it. A log with no handover entry before its delivery (the carrier jumped straight to
		 * «delivered», or the order was already in flight when logging began), a log that reached the cap, and a time
		 * outside the range from zero to {@see self::MAX_SECONDS} all answer null.
		 *
		 * @since 2.0.24
		 *
		 * @param array<int,array{0:string,1:int}> $entries a decoded log.
		 * @return int|null
		 */
		public static function handover_to_delivery( array $entries ): ?int {
			if ( count( $entries ) >= self::CAP ) {
				return null;
			}

			$handover = null;

			foreach ( $entries as $entry ) {
				if ( null === $handover && in_array( $entry[0], self::HANDOVER_STATES, true ) ) {
					$handover = $entry[1];
				} elseif ( null !== $handover && Delivery_Status::DELIVERED === $entry[0] ) {
					$seconds = $entry[1] - $handover;

					return $seconds >= 0 && $seconds <= self::MAX_SECONDS ? $seconds : null;
				}
			}

			return null;
		}
	}

endif;
