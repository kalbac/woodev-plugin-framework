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
	 * recorded and the carrier's own raw status code that produced it (#1564).
	 *
	 * The framework used to keep only the LAST state it published ({@see Delivery_Status_Events::BASELINE_META_PREFIX}),
	 * so «how long from handover to delivery» could not be answered exactly. This is the missing history. It starts
	 * the day the framework that carries it is installed and is never back-filled: an order that was already in
	 * flight has no entries before then, and the analytics count only orders whose log shows both ends.
	 *
	 * **Storage.** One order meta per carrier, `_woodev_delivery_status_log_{provider id}`, a JSON string of
	 * `[ [ state, unix time, raw code ], … ]`, oldest first, UTC. JSON, not a serialised array: the analytics statement
	 * reads the raw value out of the database and must never `unserialize()` it. An older two-element entry
	 * (`[ state, time ]`) still decodes, with an empty raw code.
	 *
	 * **Who writes.** Only {@see Delivery_Status_Events}, which calls {@see self::stage()} while it holds the order's
	 * status lock and saves the order once: the log, the published baseline and the event are one decision. This class
	 * therefore never saves.
	 *
	 * **Why the raw code.** The canonical state alone cannot say when a carrier took the parcel: CDEK maps «received at
	 * the sender's warehouse» to `created`, the same bucket as «order registered». A provider names such raw codes in
	 * {@see Orders_Provider::get_handover_statuses()} and the log keeps one entry for the first of them even when the
	 * canonical state did not change.
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
		 * Reads the order object's own meta, so the caller decides how fresh it is (inside the status lock the order has
		 * just been re-read).
		 *
		 * @since 2.0.24
		 *
		 * @param \WC_Order       $order    shipment order.
		 * @param Orders_Provider $provider carrier descriptor.
		 * @return array<int,array{0:string,1:int,2:string}> `[ state, unix time, raw code ]` entries.
		 */
		public static function read( \WC_Order $order, Orders_Provider $provider ): array {
			return self::decode( $order->get_meta( self::meta_key( $provider ) ) );
		}

		/**
		 * Puts the observation into the order's log, WITHOUT saving it — the caller saves, under its lock.
		 *
		 * An entry is added when the shipment has just entered a state that is not the log's newest (`$entered`), or when
		 * the raw code is the first the provider declared as a handover ({@see self::awaits_handover()}). Nothing else is
		 * written, so a re-poll that repeats a state costs no entry.
		 *
		 * @since 2.0.24
		 *
		 * @param \WC_Order       $order    shipment order.
		 * @param Orders_Provider $provider carrier descriptor.
		 * @param string          $state    canonical state now.
		 * @param string          $raw      the carrier's raw status behind it, or an empty string.
		 * @param bool            $entered  whether the canonical state just CHANGED (not a repeat of the published one).
		 * @param int|null        $at       unix time of the change; null means now.
		 * @return bool Whether an entry was staged.
		 */
		public static function stage( \WC_Order $order, Orders_Provider $provider, string $state, string $raw, bool $entered, ?int $at = null ): bool {
			$entries = self::read( $order, $provider );
			$last    = end( $entries );

			$new_state = $entered && ( false === $last || $last[0] !== $state );

			if ( ! $new_state && ! self::awaits_handover( $entries, $provider, $raw ) ) {
				return false;
			}

			$entries[] = [ $state, $at ?? time(), $raw ];

			$order->update_meta_data( self::meta_key( $provider ), (string) wp_json_encode( array_slice( $entries, -self::CAP ) ) );

			return true;
		}

		/**
		 * Whether the log should take an entry for this raw code although the canonical state did not change.
		 *
		 * True when the provider declared the raw code a handover and no entry carries a declared code yet. A log with no
		 * entries is NOT eligible: an order the framework adopted silently (already in flight when logging began) must not
		 * get a first entry stamped with «now», which would pass for the real handover time.
		 *
		 * @since 2.0.24
		 *
		 * @param array<int,array{0:string,1:int,2:string}> $entries  a decoded log.
		 * @param Orders_Provider                           $provider carrier descriptor.
		 * @param string                                    $raw      the carrier's raw status now.
		 * @return bool
		 */
		public static function awaits_handover( array $entries, Orders_Provider $provider, string $raw ): bool {
			$declared = $provider->get_handover_statuses();

			if ( [] === $entries || '' === $raw || ! in_array( $raw, $declared, true ) ) {
				return false;
			}

			foreach ( $entries as $entry ) {
				if ( in_array( $entry[2], $declared, true ) ) {
					return false;
				}
			}

			return true;
		}

		/**
		 * Turns a stored value into entries, dropping anything that is not a `[ state, time, raw code ]` entry.
		 *
		 * Takes the raw value so the analytics statement, which reads the meta column directly, shares the parser. A
		 * two-element entry (written before the raw code was kept) decodes with an empty raw code.
		 *
		 * @since 2.0.24
		 *
		 * @param mixed $stored the meta value as stored.
		 * @return array<int,array{0:string,1:int,2:string}>
		 */
		public static function decode( $stored ): array {
			$decoded = is_string( $stored ) && '' !== $stored ? json_decode( $stored, true ) : null;
			$entries = [];

			if ( ! is_array( $decoded ) ) {
				return $entries;
			}

			foreach ( $decoded as $entry ) {
				if ( is_array( $entry ) && isset( $entry[0], $entry[1] ) && is_string( $entry[0] ) && is_numeric( $entry[1] ) ) {
					$entries[] = [ $entry[0], (int) $entry[1], isset( $entry[2] ) && is_string( $entry[2] ) ? $entry[2] : '' ];
				}
			}

			return $entries;
		}

		/**
		 * The seconds from the carrier receiving the parcel to the buyer receiving it, or null when the log cannot say.
		 *
		 * The handover is the FIRST entry that is `in_transit` or whose raw code the provider declared a handover
		 * (`$handover_statuses`, {@see Orders_Provider::get_handover_statuses()}); the delivery is the first `delivered`
		 * entry AFTER it. `ready_for_pickup` NEVER starts the interval — it is arrival at the destination, so a log with
		 * only that and the delivery (a shipment already on its way when logging began) is not counted. A log with no
		 * handover entry before its delivery, a log that reached the cap, and a time outside the range from zero to
		 * {@see self::MAX_SECONDS} all answer null.
		 *
		 * @since 2.0.24
		 *
		 * @param array<int,array{0:string,1:int,2:string}> $entries           a decoded log.
		 * @param string[]                                  $handover_statuses the provider's declared handover raw codes.
		 * @return int|null
		 */
		public static function handover_to_delivery( array $entries, array $handover_statuses = [] ): ?int {
			if ( count( $entries ) >= self::CAP ) {
				return null;
			}

			$handover = null;

			foreach ( $entries as $entry ) {
				if ( null === $handover ) {
					if ( Delivery_Status::IN_TRANSIT === $entry[0] || ( '' !== $entry[2] && in_array( $entry[2], $handover_statuses, true ) ) ) {
						$handover = $entry[1];
					}
				} elseif ( Delivery_Status::DELIVERED === $entry[0] ) {
					$seconds = $entry[1] - $handover;

					return $seconds >= 0 && $seconds <= self::MAX_SECONDS ? $seconds : null;
				}
			}

			return null;
		}
	}

endif;
