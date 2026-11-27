<?php
/**
 * Brings the shop's copy of the shipments up to date.
 *
 * @package Woodev\Framework\Marketplace
 */

namespace Woodev\Framework\Marketplace\Flows;

use Woodev\Framework\Marketplace\Account\Account;
use Woodev\Framework\Marketplace\Data\Wpdb_Shipment_Store;
use Woodev\Framework\Marketplace\Orders\Order_Registry;
use Woodev\Framework\Marketplace\Shipments\Shipment_Batches;
use Woodev\Framework\Marketplace\Shipments\Shipment_Contents;

defined( 'ABSPATH' ) || exit;

/**
 * The reads of the marketplace that fill the shipment tables: a page of the list, one shipment with its units, and the units to
 * ship again. The refresh job and the actions go through here, so both write the same way. A unit is tied to its WooCommerce order
 * by the posting key the marketplace gives for it.
 *
 * @since 2.1.28
 */
final class Shipment_Snapshot {
	/**
	 * @var Shipment_Batches
	 */
	private $marketplace;

	/**
	 * @var Wpdb_Shipment_Store
	 */
	private $store;

	/**
	 * Returns the WooCommerce order of a posting: `function ( int $account_id, string $posting_key ): int`, 0 for none.
	 *
	 * @var callable
	 */
	private $order_of;

	/**
	 * @since 2.1.28
	 *
	 * @param Shipment_Batches    $marketplace The marketplace's shipments.
	 * @param Wpdb_Shipment_Store $store       The shop's copy.
	 * @param callable            $order_of    `function ( int $account_id, string $posting_key ): int`, 0 for none.
	 */
	public function __construct( Shipment_Batches $marketplace, Wpdb_Shipment_Store $store, callable $order_of ) {
		$this->marketplace = $marketplace;
		$this->store       = $store;
		$this->order_of    = $order_of;
	}

	/**
	 * The reads over the plugin's order registry: a unit belongs to the order its posting key is registered under.
	 *
	 * @since 2.1.28
	 *
	 * @param Shipment_Batches    $marketplace The marketplace's shipments.
	 * @param Wpdb_Shipment_Store $store       The shop's copy.
	 * @param Order_Registry      $registry    The order registry.
	 * @return self
	 */
	public static function over( Shipment_Batches $marketplace, Wpdb_Shipment_Store $store, Order_Registry $registry ): self {
		return new self(
			$marketplace,
			$store,
			static function ( int $account_id, string $posting_key ) use ( $registry ): int {
				$row = $registry->find( $account_id, $posting_key );

				return null === $row ? 0 : (int) $row['wc_order_id'];
			}
		);
	}

	/**
	 * Reads one page of the list and stores its shipments.
	 *
	 * @since 2.1.28
	 *
	 * @param Account $account Account.
	 * @param string  $cursor  Cursor of the page; empty for the first.
	 * @param int     $run     Generation of the listing.
	 * @return string Cursor of the next page; empty after the last one.
	 * @throws \Throwable When the marketplace or the database fails.
	 */
	public function list_page( Account $account, string $cursor, int $run ): string {
		$page = $this->marketplace->list_shipments( $account, $cursor );

		foreach ( $page->shipments() as $shipment ) {
			$this->store->save_header( $account->id(), $shipment, $run );
		}

		return $page->next_cursor();
	}

	/**
	 * Reads one stored shipment and stores what it holds now.
	 *
	 * @since 2.1.28
	 *
	 * @param Account             $account Account.
	 * @param array<string,mixed> $row     Shipment row.
	 * @return Shipment_Contents What was read.
	 * @throws \Throwable When the marketplace or the database fails.
	 */
	public function read( Account $account, array $row ): Shipment_Contents {
		$contents  = $this->marketplace->read_shipment( $account, $this->store->shipment( $row ) );
		$order_ids = [];

		foreach ( $contents->units() as $unit ) {
			$key = $unit->posting_key();

			if ( '' !== $key && ! isset( $order_ids[ $key ] ) ) {
				$order_ids[ $key ] = (int) call_user_func( $this->order_of, $account->id(), $key );
			}
		}

		$this->store->save_contents( $account->id(), $contents, $order_ids );

		return $contents;
	}

	/**
	 * Reads which units must be shipped again and marks their shipments for reading. Called once a refresh, after the last page of
	 * the list; {@see self::mark_reshipment()} marks the units once they are read.
	 *
	 * @since 2.1.28
	 *
	 * @param Account $account Account.
	 * @return void
	 * @throws \Throwable When the marketplace or the database fails.
	 */
	public function read_reshipment( Account $account ): void {
		$this->store->expect_reshipment( $account->id(), $this->marketplace->units_to_reship( $account ) );
	}

	/**
	 * Marks the units the marketplace said must be shipped again. Calls no marketplace.
	 *
	 * @since 2.1.28
	 *
	 * @param Account $account Account.
	 * @return void
	 * @throws \Throwable When the database fails.
	 */
	public function mark_reshipment( Account $account ): void {
		$this->store->mark_reshipment( $account->id(), $this->store->expected_reshipment( $account->id() ) );
	}
}
