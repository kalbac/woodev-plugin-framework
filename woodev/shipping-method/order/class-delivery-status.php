<?php
/**
 * Canonical delivery status
 *
 * @since 2.0.2
 *
 * @package Woodev\Framework\Shipping
 */

namespace Woodev\Framework\Shipping\Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Order\\Delivery_Status' ) ) :

	/**
	 * The nine-state canonical delivery-status enum settled in §13 (s32) plus a distinct
	 * `unknown`, and the resolution from a carrier's raw status through its declared
	 * `status_map` (SP-10 spec D4).
	 *
	 * An aggregate table cannot show three carriers' status vocabularies in one column, so
	 * every {@see \Woodev\Framework\Shipping\Admin\Orders\Orders_Provider} declares a
	 * `status_map` translating its OWN raw status strings into this enum. **An unmapped raw
	 * status resolves to {@see self::UNKNOWN}, never to a guessed canonical state** — a
	 * table that silently calls an unrecognised status "pending" is worse than one that says
	 * it does not know. The raw carrier value and its own label always survive alongside the
	 * canonical one, so nothing is lost even when the mapping is incomplete.
	 *
	 * Out of scope: the pull/push sync that MAINTAINS the status (cron, webhooks, status
	 * history, the canonical → WC-status mapping settings) — that is SP-8. This class
	 * RESOLVES and RENDERS a status already on the order; it never keeps one up to date.
	 *
	 * @since 2.0.2
	 */
	final class Delivery_Status {

		/** @var string awaiting carrier acceptance. */
		const PENDING = 'pending';

		/** @var string carrier has created the shipment. */
		const CREATED = 'created';

		/** @var string en route. */
		const IN_TRANSIT = 'in_transit';

		/** @var string waiting at a pickup point for the customer. */
		const READY_FOR_PICKUP = 'ready_for_pickup';

		/** @var string handed to the customer. */
		const DELIVERED = 'delivered';

		/** @var string on its way back to the sender. */
		const RETURNING = 'returning';

		/** @var string back with the sender. */
		const RETURNED = 'returned';

		/** @var string delivery attempt failed. */
		const FAILED = 'failed';

		/** @var string shipment cancelled. */
		const CANCELLED = 'cancelled';

		/**
		 * A raw status with no entry in the provider's `status_map`, or a `status_map` entry
		 * that does not name one of the nine states above — distinct from all nine, never a
		 * guess at one of them.
		 *
		 * @since 2.0.2
		 *
		 * @var string
		 */
		const UNKNOWN = 'unknown';

		/**
		 * Returns every canonical state's Russian label, keyed by state (including
		 * {@see self::UNKNOWN}). Count-neutral phrasing throughout — Russian is the source
		 * language here and there is no `ru` catalogue, so `_n()` would come out wrong.
		 *
		 * Labels are kept to a roughly equal length (6-13 chars) so the status badge
		 * column does not visually jump between rows (#862).
		 *
		 * @since 2.0.2
		 *
		 * @return array<string,string>
		 */
		public static function labels(): array {
			return [
				self::PENDING          => __( 'К отправке', 'woodev-plugin-framework' ),
				self::CREATED          => __( 'Принято', 'woodev-plugin-framework' ),
				self::IN_TRANSIT       => __( 'В пути', 'woodev-plugin-framework' ),
				self::READY_FOR_PICKUP => __( 'К выдаче', 'woodev-plugin-framework' ),
				self::DELIVERED        => __( 'Доставлено', 'woodev-plugin-framework' ),
				self::RETURNING        => __( 'Возврат', 'woodev-plugin-framework' ),
				self::RETURNED         => __( 'Возвращено', 'woodev-plugin-framework' ),
				self::FAILED           => __( 'Не доставлено', 'woodev-plugin-framework' ),
				self::CANCELLED        => __( 'Отменено', 'woodev-plugin-framework' ),
				self::UNKNOWN          => __( 'Неизвестно', 'woodev-plugin-framework' ),
			];
		}

		/**
		 * Returns the presentation tone for every canonical state, keyed by state.
		 *
		 * This is deliberately the PHP counterpart of the shipping orders page's
		 * `DELIVERY_STATUS_TONES`: the order table and order-edit metabox use the
		 * same compiled badge CSS. The JavaScript mirror gate compares both maps in
		 * both directions, so neither surface can silently assign a different
		 * meaning to a colour.
		 *
		 * @since 2.0.2
		 *
		 * @return array<string,string>
		 */
		public static function tones(): array {
			return [
				self::PENDING          => 'warn',
				self::CREATED          => 'warn',
				self::IN_TRANSIT       => 'info',
				self::READY_FOR_PICKUP => 'info',
				self::DELIVERED        => 'ok',
				self::RETURNING        => 'warn',
				self::RETURNED         => 'error',
				self::FAILED           => 'error',
				self::CANCELLED        => 'error',
				self::UNKNOWN          => 'muted',
			];
		}

		/**
		 * Returns one state's badge tone, falling back to the neutral unknown tone.
		 *
		 * @since 2.0.2
		 *
		 * @param string $state canonical state.
		 * @return string badge tone.
		 */
		public static function tone( string $state ): string {
			$tones = self::tones();

			return $tones[ $state ] ?? $tones[ self::UNKNOWN ];
		}

		/**
		 * Returns the nine canonical states, `unknown` excluded — the set a `status_map`
		 * entry is allowed to name.
		 *
		 * @since 2.0.2
		 *
		 * @return string[]
		 */
		public static function canonical_states(): array {
			return array_values(
				array_diff( array_keys( self::labels() ), [ self::UNKNOWN ] )
			);
		}

		/**
		 * Inverts a provider's raw-status => canonical map into canonical => raw[]
		 * (SP-10 spec D10 — the delivery-status filter's server half). A raw value whose
		 * mapped entry does not name one of {@see self::canonical_states()} is treated
		 * exactly as {@see self::resolve()} treats it — as if it were absent — so a query
		 * built from this inversion can never disagree with what the row itself would
		 * render for that raw value.
		 *
		 * @since 2.0.2
		 *
		 * @param array<string,string> $status_map raw status => one of self::canonical_states().
		 * @return array<string,string[]> canonical state => raw values mapping to it. A
		 *                                canonical state with no raw value is simply
		 *                                absent, never an empty array.
		 */
		public static function invert_status_map( array $status_map ): array {
			$inverted = [];

			foreach ( $status_map as $raw => $canonical ) {
				$canonical = (string) $canonical;

				if ( ! in_array( $canonical, self::canonical_states(), true ) ) {
					continue;
				}

				$inverted[ $canonical ][] = (string) $raw;
			}

			return $inverted;
		}

		/**
		 * Returns one state's Russian label. An unrecognized `$state` (never emitted by
		 * {@see self::resolve()}, but this accessor is public) falls back to
		 * {@see self::UNKNOWN}'s label rather than an empty string.
		 *
		 * @since 2.0.2
		 *
		 * @param string $state one of the constants above.
		 * @return string
		 */
		public static function label( string $state ): string {
			$labels = self::labels();

			return $labels[ $state ] ?? $labels[ self::UNKNOWN ];
		}

		/**
		 * Resolves a carrier's raw status into the full row shape:
		 * `{ canonical, canonical_label, raw, raw_label }` (SP-10 spec D4).
		 *
		 * A raw status absent from `$status_map`, OR one whose mapped value is not one of the
		 * nine {@see self::canonical_states()}, resolves to {@see self::UNKNOWN} — a typo or
		 * omission in a carrier's own `status_map` fails closed, never open onto a guessed
		 * state. `$raw` and `$raw_label` always survive when a raw status was present, even
		 * when the canonical side is `unknown`, so nothing is lost while a carrier's mapping
		 * is incomplete. `$status_labels` is optional; a raw status absent from it falls back
		 * to showing the raw value itself.
		 *
		 * @since 2.0.2
		 *
		 * @param string|null          $raw_status    the carrier's own raw status value, or
		 *                                             null/'' when the order carries none.
		 * @param array<string,string> $status_map    raw status => one of
		 *                                             {@see self::canonical_states()}.
		 * @param array<string,string> $status_labels raw status => human label. Optional.
		 * @return array{canonical:string,canonical_label:string,raw:?string,raw_label:?string}
		 */
		public static function resolve( ?string $raw_status, array $status_map, array $status_labels = [] ): array {
			$raw = ( null !== $raw_status && '' !== $raw_status ) ? $raw_status : null;

			$canonical = self::UNKNOWN;

			if ( null !== $raw && array_key_exists( $raw, $status_map ) ) {
				$mapped = (string) $status_map[ $raw ];

				if ( in_array( $mapped, self::canonical_states(), true ) ) {
					$canonical = $mapped;
				}
			}

			/**
			 * Filters the resolved canonical delivery status.
			 *
			 * @since 2.0.2
			 *
			 * @param string               $canonical  resolved canonical state.
			 * @param string|null          $raw        raw carrier status, or null.
			 * @param array<string,string> $status_map the provider's raw => canonical map.
			 */
			$filtered_canonical = apply_filters( 'woodev_shipping_delivery_status_resolved', $canonical, $raw, $status_map );
			$canonical          = is_string( $filtered_canonical ) && array_key_exists( $filtered_canonical, self::labels() )
				? $filtered_canonical
				: $canonical;

			$raw_label = null;
			if ( null !== $raw ) {
				$raw_label = isset( $status_labels[ $raw ] ) && '' !== $status_labels[ $raw ]
					? (string) $status_labels[ $raw ]
					: $raw;
			}

			return [
				'canonical'       => $canonical,
				'canonical_label' => self::label( $canonical ),
				'raw'             => $raw,
				'raw_label'       => $raw_label,
			];
		}
	}

endif;
