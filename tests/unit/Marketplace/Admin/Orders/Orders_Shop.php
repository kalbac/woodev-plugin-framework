<?php
/**
 * A small WooCommerce order store on SQLite for the «Заказы» tests, on either storage.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Orders
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Orders;

use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Storage;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;

/**
 * Lays out the table the reader joins — `wp_wc_orders` for HPOS, `wp_posts` and `wp_postmeta` for the posts store; the columns are the
 * ones the real tables have — and offers `order()` and `posting()` to fill it together with the postings registry.
 *
 * The catalogue the tests share (account 1 unless said otherwise), dates in UTC:
 *
 * | order | status | created | total | posting | marketplace status | finished |
 * |---|---|---|---|---|---|---|
 * | 101 | processing | 2026-11-01 10:00 | 500 | P-101 | awaiting_packaging | no |
 * | 102 | completed | 2026-11-03 09:00 | 1500.5 | P-102 | delivered | yes |
 * | 103 | cancelled | 2026-11-05 12:00 | 99 | P-103 | cancelled | yes |
 * | 104 | processing | 2026-11-07 08:30 | 250 | P-104 | delivering | no |
 *
 * And what must never be listed: 105 (trashed), 106 (registry row with no order yet), 107 (the order is gone), 108 (a refund),
 * 201 (account 2's).
 */
final class Orders_Shop {
	public const ACCOUNT = 1;
	public const OTHER   = 2;

	/**
	 * @var Sqlite_Wpdb
	 */
	public $db;

	/**
	 * @var bool
	 */
	public $hpos;

	/**
	 * Builds the order tables and the shared catalogue.
	 *
	 * @param Sqlite_Wpdb $db   A database that already holds the marketplace tables.
	 * @param bool        $hpos Whether the orders live in the HPOS table.
	 * @param int         $account Account the shared registry rows belong to.
	 */
	public function __construct( Sqlite_Wpdb $db, bool $hpos, int $account = self::ACCOUNT ) {
		$this->db       = $db;
		$this->hpos     = $hpos;
		$db->posts      = 'wp_posts';
		$db->postmeta   = 'wp_postmeta';

		$db->define( 'CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_type TEXT, post_status TEXT, post_date_gmt TEXT)' );
		$db->define( 'CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT)' );
		$db->define( 'CREATE TABLE wp_wc_orders (id INTEGER PRIMARY KEY, type TEXT, status TEXT, date_created_gmt TEXT, total_amount REAL)' );

		$this->order( 101, 'wc-processing', '2026-11-01 10:00:00', 500, $account, 'P-101', 'awaiting_packaging', false );
		$this->order( 102, 'wc-completed', '2026-11-03 09:00:00', 1500.5, $account, 'P-102', 'delivered', true );
		$this->order( 103, 'wc-cancelled', '2026-11-05 12:00:00', 99, $account, 'P-103', 'cancelled', true );
		$this->order( 104, 'wc-processing', '2026-11-07 08:30:00', 250, $account, 'P-104', 'delivering', false );

		$this->order( 105, 'trash', '2026-11-08 08:00:00', 10, $account, 'P-105', 'new', false );
		$this->posting( $account, 'P-106', null, 'new', false );
		$this->posting( $account, 'P-107', 107, 'new', false );
		$this->order( 108, 'wc-completed', '2026-11-09 08:00:00', 10, $account, 'P-108', 'new', false, 'shop_order_refund' );
		$this->order( 201, 'wc-processing', '2026-11-02 08:00:00', 70, self::OTHER, 'P-201', 'new', false );
	}

	/**
	 * The storage descriptor the reader is built with.
	 *
	 * @return Site_Orders_Storage
	 */
	public function storage(): Site_Orders_Storage {
		return new Site_Orders_Storage( $this->hpos, $this->hpos ? 'wp_wc_orders' : 'wp_posts' );
	}

	/**
	 * Adds an order to the store and its registry row to the postings.
	 *
	 * @param int    $id         Order id.
	 * @param string $status     Status with its `wc-` prefix.
	 * @param string $created    Creation time, UTC.
	 * @param float  $total      Order total.
	 * @param int    $account    Account of the registry row.
	 * @param string $key        Posting number.
	 * @param string $raw        The marketplace's status.
	 * @param bool   $terminal   Whether the marketplace has finished with the posting.
	 * @param string $type       Order type.
	 * @return void
	 */
	public function order( int $id, string $status, string $created, float $total, int $account, string $key, string $raw, bool $terminal, string $type = 'shop_order' ): void {
		if ( $this->hpos ) {
			$this->db->insert(
				'wp_wc_orders',
				[
					'id'               => $id,
					'type'             => $type,
					'status'           => $status,
					'date_created_gmt' => $created,
					'total_amount'     => $total,
				]
			);
		} else {
			$this->db->insert(
				'wp_posts',
				[
					'ID'            => $id,
					'post_type'     => $type,
					'post_status'   => $status,
					'post_date_gmt' => $created,
				]
			);
			$this->db->insert(
				'wp_postmeta',
				[
					'post_id'    => $id,
					'meta_key'   => '_order_total',
					'meta_value' => (string) $total,
				]
			);
		}

		$this->posting( $account, $key, $id, $raw, $terminal );
	}

	/**
	 * Adds a registry row.
	 *
	 * @param int         $account  Account.
	 * @param string      $key      Posting number.
	 * @param int|null    $order_id Order the posting became; null while it is being created.
	 * @param string      $raw      The marketplace's status.
	 * @param bool        $terminal Whether the marketplace has finished with the posting.
	 * @param string|null $claim    UTC time until which a writer holds the row.
	 * @return void
	 */
	public function posting( int $account, string $key, ?int $order_id, string $raw, bool $terminal, ?string $claim = null ): void {
		$this->db->insert(
			Sqlite_Tables::table( 'postings' ),
			[
				'account_id'    => $account,
				'external_key'  => $key,
				'wc_order_id'   => $order_id,
				'status_raw'    => $raw,
				'is_terminal'   => $terminal ? 1 : 0,
				'claim_until'   => $claim,
				'first_seen_at' => '2026-11-01 00:00:00',
			]
		);
	}
}
