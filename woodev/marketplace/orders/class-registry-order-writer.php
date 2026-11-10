<?php
/**
 * The order writer on the registry and its claim.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Orders;

use Woodev\Framework\Marketplace\Price\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Writes remote orders into WooCommerce idempotently (spec 5.5 step 3, 7.2), for one-order-per-remote-order marketplaces
 * (Ozon, {@see self::upsert()}) and for grouped ones (Wildberries carts, {@see self::upsert_member()}).
 *
 * Every write goes through the registry row of the group and its claim (spec 3.5):
 *
 * 1. reserve the row (`INSERT IGNORE`), claim it; no claim → {@see Order_Claim_Exception::busy()};
 * 2. find the order: the one the row registered, else one with the group's identity meta (left by a writer that died
 *    between creating it and registering it), else create one — right after `holds()` — in an inert status that takes no
 *    stock;
 * 3. register it with the token-fenced `UPDATE … WHERE wc_order_id IS NULL AND claim_token = …`; zero rows → the claim was
 *    lost or another writer registered first: an order this writer created is discarded **before it ever took stock**, the
 *    loss is reported, and {@see Order_Claim_Exception::lost()} is thrown — the current holder completes the work;
 * 4. after `holds()`, give the order its data and status (this is when WooCommerce reduces stock), then record the refresh
 *    in the row, fenced by the token. When that record loses, the order may hold this writer's older view:
 *    {@see Order_Claim_Exception::stale()} tells the caller to refresh again.
 *
 * @since 2.0.29
 */
final class Registry_Order_Writer implements Order_Writer {
	/**
	 * Registry.
	 *
	 * @var Order_Registry
	 */
	private $registry;

	/**
	 * WooCommerce side.
	 *
	 * @var Order_Store
	 */
	private $store;

	/**
	 * Returns the default WooCommerce status of an account's new orders: `function ( int $account_id ): string`.
	 *
	 * @var callable
	 */
	private $default_status;

	/**
	 * Tells whether a remote order is finished: `function ( Order_DTO $order ): bool`.
	 *
	 * @var callable
	 */
	private $is_terminal;

	/**
	 * Reports a lost claim: `function ( int $account_id, string $key, string $stage ): void`.
	 *
	 * @var callable|null
	 */
	private $on_claim_lost;

	/**
	 * Called at named points of a write: `function ( string $point ): void`. A test seam for interleavings; a no-op otherwise.
	 *
	 * @var callable|null
	 */
	private $checkpoint;

	/**
	 * Creates the writer.
	 *
	 * @since 2.0.29
	 *
	 * @param Order_Registry $registry       Registry.
	 * @param Order_Store    $store          WooCommerce side.
	 * @param callable       $default_status `function ( int $account_id ): string`; `wc-` prefixes are accepted.
	 * @param callable|null  $is_terminal    `function ( Order_DTO $order ): bool`; delivered or cancelled when null.
	 * @param callable|null  $on_claim_lost  `function ( int $account_id, string $key, string $stage ): void`.
	 * @param callable|null  $checkpoint     `function ( string $point ): void`, for tests.
	 */
	public function __construct( Order_Registry $registry, Order_Store $store, callable $default_status, ?callable $is_terminal = null, ?callable $on_claim_lost = null, ?callable $checkpoint = null ) {
		$this->registry       = $registry;
		$this->store          = $store;
		$this->default_status = $default_status;
		$this->is_terminal    = $is_terminal ?? static function ( Order_DTO $order ): bool {
			return in_array( $order->status_canonical(), [ Status_Map::DELIVERED, Status_Map::CANCELLED ], true );
		};
		$this->on_claim_lost  = $on_claim_lost;
		$this->checkpoint     = $checkpoint;
	}

	/**
	 * Writes one remote order as one WooCommerce order.
	 *
	 * @since 2.0.29
	 *
	 * @param Order_DTO  $dto Remote order.
	 * @param Status_Map $map Status mapping.
	 * @return \WC_Order
	 * @throws Order_Claim_Exception When another writer holds the row, or the claim was lost during the write.
	 */
	public function upsert( Order_DTO $dto, Status_Map $map ): \WC_Order {
		$token = $this->claim( $dto );

		try {
			$members = Order_Group::start( $dto->group_key() )->with_member( $dto )->member_registry();

			return $this->write( $dto, $map, $token, $members, null );
		} finally {
			$this->registry->release_claim( $dto->account_id(), $dto->group_key(), $token );
		}
	}

	/**
	 * Writes one member of a grouped order. The member snapshots live in the registry row, so the claim holder always folds
	 * the newest state of every member; a writer whose claim was taken over cannot overwrite what the new holder wrote.
	 *
	 * @since 2.0.29
	 *
	 * @param Order_DTO  $member One member.
	 * @param Status_Map $map    Status mapping.
	 * @return \WC_Order
	 * @throws Order_Claim_Exception When another writer holds the row, or the claim was lost during the write.
	 */
	public function upsert_member( Order_DTO $member, Status_Map $map ): \WC_Order {
		$token = $this->claim( $member );

		try {
			$row   = $this->registry->find( $member->account_id(), $member->group_key() );
			$group = Order_Group::start( $member->group_key() );

			foreach ( null === $row ? [] : $row['members'] as $external_id => $snapshot ) {
				$group = $group->with_member( self::thaw( $member->account_id(), $member->group_key(), (string) $external_id, (array) $snapshot ) );
			}

			$group = $group->with_member( $member );

			return $this->write( self::fold( $group, $member ), $map, $token, $group->member_registry(), self::freeze( $group ) );
		} finally {
			$this->registry->release_claim( $member->account_id(), $member->group_key(), $token );
		}
	}

	/**
	 * Reserves and claims the group's row.
	 *
	 * @param Order_DTO $dto Remote order or member.
	 * @return string Claim token.
	 * @throws Order_Claim_Exception When another writer holds the row.
	 */
	private function claim( Order_DTO $dto ): string {
		$this->registry->reserve( $dto->account_id(), $dto->group_key() );

		$token = $this->registry->claim( $dto->account_id(), $dto->group_key() );

		if ( null === $token ) {
			throw Order_Claim_Exception::busy( $dto->group_key() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
		}

		return $token;
	}

	/**
	 * Writes the whole order under a held claim.
	 *
	 * @param Order_DTO                                                 $order     Whole order.
	 * @param Status_Map                                                $map       Status mapping.
	 * @param string                                                    $token     Claim token.
	 * @param array<string,array{status_canonical:string,quantity:int}> $members  Member registry.
	 * @param array<string,mixed>|null                                  $snapshots Member snapshots of a grouped order.
	 * @return \WC_Order
	 * @throws \RuntimeException When the registered order no longer exists, or (as Order_Claim_Exception) when the claim was lost.
	 */
	private function write( Order_DTO $order, Status_Map $map, string $token, array $members, ?array $snapshots ): \WC_Order {
		$row = $this->registry->find( $order->account_id(), $order->group_key() );

		if ( null !== $row && null !== $row['wc_order_id'] ) {
			$wc_order = $this->store->get( $row['wc_order_id'] );

			if ( null === $wc_order ) {
				throw new \RuntimeException( 'The order registered for ' . $order->group_key() . ' no longer exists.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
			}

			$created = $this->store->is_provisional( $wc_order );
		} else {
			list( $wc_order, $created ) = $this->register( $order, $token );
		}

		$this->ensure_holds( $order, $token, 'update' );
		$this->checkpoint( 'before_update' );

		// Spec 5.5 step 3: everything but delivered / cancelled takes the configured default, on new and existing orders alike.
		$default = self::bare_status( (string) call_user_func( $this->default_status, $order->account_id() ) );
		$this->store->update( $wc_order, $order, $map->wc_status( $order->status_canonical(), $default ), $members, $created );

		$recorded = $this->registry->record(
			$order->account_id(),
			$order->group_key(),
			$token,
			$order->status_raw(),
			(bool) call_user_func( $this->is_terminal, $order ),
			self::registry_lines( $order ),
			$snapshots
		);

		if ( ! $recorded ) {
			// The WooCommerce order was already written from this writer's view, which may be older than the new holder's: the
			// caller must refresh again so a fresh read repairs it (critic PR #41 finding 1).
			$this->lost( $order, 'record', true );
		}

		return $wc_order;
	}

	/**
	 * Finds or creates the order and registers it in the row.
	 *
	 * @param Order_DTO $order Whole order.
	 * @param string    $token Claim token.
	 * @return array{0:\WC_Order,1:bool} The order, and whether it still needs its first real status.
	 * @throws Order_Claim_Exception When the registration lost.
	 */
	private function register( Order_DTO $order, string $token ): array {
		$wc_order = $this->store->find( $order );
		$created  = false;

		if ( null === $wc_order ) {
			$this->ensure_holds( $order, $token, 'create' );
			$this->checkpoint( 'before_create' );

			$wc_order = $this->store->create( $order );
			$created  = true;
		}

		if ( $this->registry->attach_order( $order->account_id(), $order->group_key(), $token, $wc_order->get_id() ) ) {
			return [ $wc_order, $created || $this->store->is_provisional( $wc_order ) ];
		}

		$row = $this->registry->find( $order->account_id(), $order->group_key() );

		// Discard only an order this writer made and nobody adopted: a found order may be the winner's own.
		if ( $created && ( null === $row || $row['wc_order_id'] !== $wc_order->get_id() ) ) {
			$this->store->discard( $wc_order );
		}

		$this->lost( $order, 'register' );
	}

	/**
	 * Stops the write when the claim is gone (spec 3.5 rule 2).
	 *
	 * @param Order_DTO $order Whole order.
	 * @param string    $token Claim token.
	 * @param string    $stage Where the check ran.
	 * @return void
	 * @throws Order_Claim_Exception When the token no longer owns the row.
	 */
	private function ensure_holds( Order_DTO $order, string $token, string $stage ): void {
		if ( ! $this->registry->holds_claim( $order->account_id(), $order->group_key(), $token ) ) {
			$this->lost( $order, $stage );
		}
	}

	/**
	 * Reports a lost claim and stops.
	 *
	 * @param Order_DTO $order Whole order.
	 * @param string    $stage Where the loss was seen.
	 * @param bool      $after_write True when the WooCommerce order was already written: the loss is then `stale`, not `lost`.
	 * @return never
	 * @throws Order_Claim_Exception Always.
	 */
	private function lost( Order_DTO $order, string $stage, bool $after_write = false ) {
		if ( null !== $this->on_claim_lost ) {
			call_user_func( $this->on_claim_lost, $order->account_id(), $order->group_key(), $stage );
		}

		throw $after_write ? Order_Claim_Exception::stale( $order->group_key() ) : Order_Claim_Exception::lost( $order->group_key() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not HTML output.
	}

	/**
	 * Runs the test seam.
	 *
	 * @param string $point Point name.
	 * @return void
	 */
	private function checkpoint( string $point ): void {
		if ( null !== $this->checkpoint ) {
			call_user_func( $this->checkpoint, $point );
		}
	}

	/**
	 * Lines in the registry's shape.
	 *
	 * @param Order_DTO $order Whole order.
	 * @return array<int,array{offer_id:string,sku:string,qty_seen:int}>
	 */
	private static function registry_lines( Order_DTO $order ): array {
		$lines = [];
		foreach ( $order->lines() as $line ) {
			$remote  = $line->remote_ids();
			$lines[] = [
				'offer_id' => $line->seller_sku(),
				'sku'      => isset( $remote['sku'] ) ? (string) $remote['sku'] : '',
				'qty_seen' => $line->quantity(),
			];
		}

		return $lines;
	}

	/**
	 * Folds the live members of a group into the one DTO of the WooCommerce order. A cancelled member contributes no line.
	 *
	 * @param Order_Group $group  Group after the member was applied.
	 * @param Order_DTO   $member The member being written.
	 * @return Order_DTO
	 */
	private static function fold( Order_Group $group, Order_DTO $member ): Order_DTO {
		$members = $group->members();
		ksort( $members, SORT_STRING );

		$status = (string) $group->status();
		$raw    = $member->status_raw();
		$lines  = [];

		foreach ( $members as $one ) {
			if ( $one->status_canonical() === $status ) {
				$raw = $one->status_raw();
			}

			if ( ! $one->is_cancelled() ) {
				$lines = array_merge( $lines, $one->lines() );
			}
		}

		$totals = [];
		if ( [] !== $lines ) {
			$totals['items'] = $group->total( $lines[0]->unit_price()->currency() );
		}

		$first = reset( $members );
		$dates = $first instanceof Order_DTO && isset( $first->dates()['created_at'] ) ? [ 'created_at' => $first->dates()['created_at'] ] : [];

		return new Order_DTO(
			$member->account_id(),
			$group->group_key(),
			$group->group_key(),
			$raw,
			$status,
			$lines,
			$totals,
			$member->buyer(),
			$dates,
			$member->shipment(),
			[ 'members' => array_keys( $members ) ]
		);
	}

	/**
	 * Serialises every member for the registry row.
	 *
	 * @param Order_Group $group Group.
	 * @return array<string,mixed>
	 */
	private static function freeze( Order_Group $group ): array {
		$frozen = [];

		foreach ( $group->members() as $external_id => $member ) {
			$lines = [];
			foreach ( $member->lines() as $line ) {
				$lines[] = [
					'seller_sku' => $line->seller_sku(),
					'remote_ids' => $line->remote_ids(),
					'quantity'   => $line->quantity(),
					'unit_minor' => $line->unit_price()->minor(),
					'currency'   => $line->unit_price()->currency(),
					'name'       => $line->name(),
				];
			}

			$frozen[ (string) $external_id ] = [
				'status_raw'       => $member->status_raw(),
				'status_canonical' => $member->status_canonical(),
				'updated_at'       => (string) $member->updated_at(),
				'created_at'       => (string) ( $member->dates()['created_at'] ?? '' ),
				'lines'            => $lines,
			];
		}

		ksort( $frozen, SORT_STRING );

		return $frozen;
	}

	/**
	 * Rebuilds a member from its registry snapshot.
	 *
	 * @param int                 $account_id  Account id.
	 * @param string              $group_key   Group key.
	 * @param string              $external_id Member id.
	 * @param array<string,mixed> $snapshot    Stored snapshot.
	 * @return Order_DTO
	 */
	private static function thaw( int $account_id, string $group_key, string $external_id, array $snapshot ): Order_DTO {
		$lines = [];
		foreach ( (array) ( $snapshot['lines'] ?? [] ) as $line ) {
			$line    = (array) $line;
			$lines[] = new Order_Line(
				(string) ( $line['seller_sku'] ?? '' ),
				(array) ( $line['remote_ids'] ?? [] ),
				(int) ( $line['quantity'] ?? 0 ),
				Money::of( (int) ( $line['unit_minor'] ?? 0 ), (string) ( $line['currency'] ?? 'RUB' ) ),
				(string) ( $line['name'] ?? '' )
			);
		}

		$dates = [];
		foreach ( [ 'updated_at', 'created_at' ] as $name ) {
			if ( '' !== (string) ( $snapshot[ $name ] ?? '' ) ) {
				$dates[ $name ] = (string) $snapshot[ $name ];
			}
		}

		$canonical = (string) ( $snapshot['status_canonical'] ?? Status_Map::NEW );

		return new Order_DTO(
			$account_id,
			$external_id,
			$group_key,
			(string) ( $snapshot['status_raw'] ?? '' ),
			Status_Map::is_canonical( $canonical ) ? $canonical : Status_Map::NEW,
			$lines,
			[],
			null,
			$dates
		);
	}

	/**
	 * Drops the `wc-` prefix of a stored status setting.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private static function bare_status( string $status ): string {
		$status = 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;

		return '' === $status ? 'processing' : $status;
	}
}
