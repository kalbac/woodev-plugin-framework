<?php
/**
 * Woodev Popular Settlement Entry
 *
 * One stored row of the popular-settlements table (popular-settlements spec D1-D3):
 * a whole {@see Location_Record} plus the ranking/eviction/freshness metadata that
 * only this table owns. The record is stored in full — not just its key — so that
 * picking a popular entry is the same {@see Location_Record} flowing the same path
 * as picking through search (spec D1).
 *
 * @since 2.0.2
 */

namespace Woodev\Framework\Shipping\Location;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

if ( ! class_exists( '\\Woodev\\Framework\\Shipping\\Location\\Popular_Settlement_Entry' ) ) :

	/**
	 * Immutable value object for one popular-settlement row.
	 *
	 * Built by {@see Popular_Settlement_Store} from a raw database row; nothing else
	 * constructs one.
	 *
	 * @since 2.0.2
	 */
	class Popular_Settlement_Entry {

		/** @var int surrogate row id (spec D3 — never the provider key) */
		private int $id;

		/** @var string the owning provider's id */
		private string $provider_id;

		/** @var string ISO-3166 alpha-2 country code */
		private string $country;

		/** @var Location_Record the whole stored record (spec D1) */
		private Location_Record $record;

		/** @var int ranking counter (spec D2/D3) */
		private int $order_count;

		/** @var int|null usage clock — unix timestamp, or null when never ordered (spec D2) */
		private ?int $last_ordered_at;

		/** @var int|null freshness clock — unix timestamp, or null when never verified (spec D2) */
		private ?int $last_verified_at;

		/** @var int unix timestamp the row was first inserted */
		private int $created_at;

		/**
		 * Constructor.
		 *
		 * @since 2.0.2
		 *
		 * @param int             $id               Surrogate primary key.
		 * @param string          $provider_id      The owning provider's id.
		 * @param string          $country          ISO-3166 alpha-2 country code.
		 * @param Location_Record $record           The whole stored record.
		 * @param int             $order_count      Ranking counter.
		 * @param int|null        $last_ordered_at  Usage clock — unix timestamp, or null.
		 * @param int|null        $last_verified_at Freshness clock — unix timestamp, or null.
		 * @param int             $created_at       Unix timestamp the row was first inserted.
		 */
		public function __construct(
			int $id,
			string $provider_id,
			string $country,
			Location_Record $record,
			int $order_count,
			?int $last_ordered_at,
			?int $last_verified_at,
			int $created_at
		) {
			$this->id               = $id;
			$this->provider_id      = $provider_id;
			$this->country          = $country;
			$this->record           = $record;
			$this->order_count      = $order_count;
			$this->last_ordered_at  = $last_ordered_at;
			$this->last_verified_at = $last_verified_at;
			$this->created_at       = $created_at;
		}

		/**
		 * Gets the surrogate row id.
		 *
		 * @since 2.0.2
		 *
		 * @return int
		 */
		public function id(): int {
			return $this->id;
		}

		/**
		 * Gets the owning provider's id.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function provider_id(): string {
			return $this->provider_id;
		}

		/**
		 * Gets the ISO-3166 alpha-2 country code.
		 *
		 * @since 2.0.2
		 *
		 * @return string
		 */
		public function country(): string {
			return $this->country;
		}

		/**
		 * Gets the whole stored record.
		 *
		 * @since 2.0.2
		 *
		 * @return Location_Record
		 */
		public function record(): Location_Record {
			return $this->record;
		}

		/**
		 * Gets the ranking counter.
		 *
		 * @since 2.0.2
		 *
		 * @return int
		 */
		public function order_count(): int {
			return $this->order_count;
		}

		/**
		 * Gets the usage clock, or null when this entry has never been ordered.
		 *
		 * @since 2.0.2
		 *
		 * @return int|null
		 */
		public function last_ordered_at(): ?int {
			return $this->last_ordered_at;
		}

		/**
		 * Gets the freshness clock, or null when this entry has never been verified.
		 *
		 * Only the accessor ships in this slice — the writer arrives with lazy
		 * verification (popular-settlements spec D5/D6).
		 *
		 * @since 2.0.2
		 *
		 * @return int|null
		 */
		public function last_verified_at(): ?int {
			return $this->last_verified_at;
		}

		/**
		 * Gets the unix timestamp the row was first inserted.
		 *
		 * @since 2.0.2
		 *
		 * @return int
		 */
		public function created_at(): int {
			return $this->created_at;
		}
	}

endif;
