<?php
/**
 * Aggregate of the members of one grouped order.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Orders;

use Woodev\Framework\Marketplace\Price\Money;

defined( 'ABSPATH' ) || exit;

/**
 * The pure rules behind `Order_Writer::upsert_member()`: several remote orders (Wildberries assembly orders sharing an
 * `orderUid`) are members of one WooCommerce order.
 *
 * - A member update replaces only that member; a duplicate delivery changes nothing.
 * - A member that is cancelled stays cancelled: a late or repeated «new» snapshot never revives it.
 * - A snapshot older than the stored one (by `updated_at`) is ignored, so the result does not depend on arrival order.
 * - A cancelled member counts for nothing: zero units, zero money. The group is `cancelled` only when every member is.
 * - Otherwise the group's status is the least progressed status among the live members.
 *
 * The object is immutable; `with_member()` returns the next state. The real writer applies this under the registry
 * claim and stores `member_registry()` in the order meta `_woodev_mp_group_members`.
 *
 * @since 2.0.29
 */
final class Order_Group {
	/**
	 * Group key shared by every member.
	 *
	 * @var string
	 */
	private $group_key;

	/**
	 * Latest accepted snapshot of each member, keyed by external id.
	 *
	 * @var array<string,Order_DTO>
	 */
	private $members;

	/**
	 * Use `start()`.
	 *
	 * @param string                  $group_key Group key.
	 * @param array<string,Order_DTO> $members   Members keyed by external id.
	 */
	private function __construct( string $group_key, array $members ) {
		$this->group_key = $group_key;
		$this->members   = $members;
	}

	/**
	 * Starts an empty group.
	 *
	 * @since 2.0.29
	 *
	 * @param string $group_key Group key.
	 * @return self
	 */
	public static function start( string $group_key ): self {
		return new self( $group_key, [] );
	}

	/**
	 * Applies one member snapshot.
	 *
	 * @since 2.0.29
	 *
	 * @param Order_DTO $member Snapshot of one member.
	 * @return self The next state; the same state when the snapshot is stale or revives a cancelled member.
	 * @throws \InvalidArgumentException When the member belongs to another group.
	 */
	public function with_member( Order_DTO $member ): self {
		if ( $member->group_key() !== $this->group_key ) {
			throw new \InvalidArgumentException( 'Member belongs to another group.' );
		}

		$known = $this->members[ $member->external_id() ] ?? null;
		if ( null !== $known && ( $this->is_stale( $known, $member ) || ( $known->is_cancelled() && ! $member->is_cancelled() ) ) ) {
			return $this;
		}

		// Not array_merge(): it renumbers integer-like keys, and external ids such as "11" are exactly that.
		$members                           = $this->members;
		$members[ $member->external_id() ] = $member;

		return new self( $this->group_key, $members );
	}

	/**
	 * Returns the group key.
	 *
	 * @since 2.0.29
	 *
	 * @return string
	 */
	public function group_key(): string {
		return $this->group_key;
	}

	/**
	 * Returns every member, cancelled ones included.
	 *
	 * @since 2.0.29
	 *
	 * @return array<string,Order_DTO>
	 */
	public function members(): array {
		return $this->members;
	}

	/**
	 * Returns the members that are not cancelled.
	 *
	 * @since 2.0.29
	 *
	 * @return array<string,Order_DTO>
	 */
	public function live_members(): array {
		$live = [];
		foreach ( $this->members as $external_id => $member ) {
			if ( ! $member->is_cancelled() ) {
				$live[ $external_id ] = $member;
			}
		}

		return $live;
	}

	/**
	 * Returns the canonical status of the whole order.
	 *
	 * @since 2.0.29
	 *
	 * @return string|null Null for an empty group.
	 */
	public function status(): ?string {
		if ( [] === $this->members ) {
			return null;
		}

		$live = $this->live_members();
		if ( [] === $live ) {
			return Status_Map::CANCELLED;
		}

		$lowest = null;
		foreach ( $live as $member ) {
			if ( null === $lowest || Status_Map::rank( $member->status_canonical() ) < Status_Map::rank( $lowest ) ) {
				$lowest = $member->status_canonical();
			}
		}

		return $lowest;
	}

	/**
	 * Returns the units on the live members' lines.
	 *
	 * @since 2.0.29
	 *
	 * @return int
	 */
	public function quantity(): int {
		$units = 0;
		foreach ( $this->live_members() as $member ) {
			$units += $member->quantity();
		}

		return $units;
	}

	/**
	 * Returns the money on the live members' lines, exact.
	 *
	 * @since 2.0.29
	 *
	 * @param string $currency Currency of the order.
	 * @return Money
	 */
	public function total( string $currency ): Money {
		$total = Money::zero( $currency );
		foreach ( $this->live_members() as $member ) {
			foreach ( $member->lines() as $line ) {
				$total = $total->add( $line->line_total() );
			}
		}

		return $total;
	}

	/**
	 * Returns the member registry the writer stores in `_woodev_mp_group_members`, sorted by external id so the stored JSON does not depend on arrival order.
	 *
	 * @since 2.0.29
	 *
	 * @return array<string,array{status_canonical:string,quantity:int}> A cancelled member keeps its status and counts zero units.
	 */
	public function member_registry(): array {
		$registry = [];
		foreach ( $this->members as $external_id => $member ) {
			$registry[ $external_id ] = [
				'status_canonical' => $member->status_canonical(),
				'quantity'         => $member->is_cancelled() ? 0 : $member->quantity(),
			];
		}

		ksort( $registry );

		return $registry;
	}

	/**
	 * Tells whether a new snapshot is older than the stored one.
	 *
	 * @param Order_DTO $known    Stored snapshot.
	 * @param Order_DTO $incoming New snapshot.
	 * @return bool
	 */
	private function is_stale( Order_DTO $known, Order_DTO $incoming ): bool {
		// Instants, not strings: "10:00:00Z" < "10:00:00.500Z" is true as text but they must order the other way round,
		// and "+03:00" offsets never sort as text.
		$known_at    = $known->updated_at_instant();
		$incoming_at = $incoming->updated_at_instant();

		return null !== $known_at && null !== $incoming_at && $incoming_at < $known_at;
	}
}
