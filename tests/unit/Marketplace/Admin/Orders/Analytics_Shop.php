<?php
/**
 * A WooCommerce shop on SQLite for the «Аналитика» tests, on either order storage, with order lines.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Orders
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Orders;

use Woodev\Framework\Marketplace\Admin\Pages\Orders\Site_Orders_Storage;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;

/**
 * The tables the analytics read — the order store (`wp_wc_orders` with `wp_wc_order_operational_data`, or `wp_posts` with
 * `wp_postmeta`), the order lines (`wp_woocommerce_order_items`, `wp_woocommerce_order_itemmeta`) and the postings registry — and the
 * catalogue the tests share. The site is in Moscow (UTC+3); times below are UTC.
 *
 * Current period 01–07.11.2026, previous period 25–31.10.2026, «now» 07.11.2026 12:00 UTC.
 *
 * | order | account | status | created (UTC) | total | lines | posting status | open | first seen |
 * |---|---|---|---|---|---|---|---|---|
 * | 101 | 1 | processing | 01.11 10:00 | 1000 | #11 ×2 = 1000 | awaiting_packaging | yes | 01.11 10:00 |
 * | 102 | 1 | completed | 03.11 09:00 | 500 | #12 ×1 = 300, «Кружка» ×1 = 200 | delivered | no | |
 * | 103 | 1 | cancelled | 05.11 12:00 | 400 | #11 ×1 = 400 | cancelled | no | |
 * | 104 | 2 | processing | 07.11 20:30 (23:30 site) | 300 | #13 var #14 ×3 = 300 | awaiting_deliver | yes | 07.11 06:00 |
 * | 105 | 1 | processing | 31.10 21:30 (01.11 00:30 site) | 200 | «Кружка» ×2 = 200 | delivering | yes | |
 * | 106 | 1 | processing | 07.11 21:30 (08.11 00:30 site) | 999 | #11 ×9 | awaiting_packaging | yes | 07.11 21:30 |
 * | 107 | 1 | pending | 02.11 10:00 | 70 | | new | yes | |
 * | 108 | 1 | trash | 02.11 10:00 | 70 | | new | yes | |
 * | 201 | 1 | completed | 28.10 10:00 | 800 | #11 ×4 = 800 | delivered | no | |
 * | 202 | 1 | cancelled | 29.10 10:00 | 100 | | cancelled | no | |
 * | 301 | — | completed | 04.11 10:00 | 1500 | the shop's own checkout | | | |
 * | 302 | — | cancelled | 04.11 11:00 | 50 | the shop's own checkout | | | |
 * | 303 | — | completed | 27.10 10:00 | 200 | the shop's own checkout | | | |
 */
final class Analytics_Shop {
	public const CREATED_VIA = 'fake-mp';

	/**
	 * @var Sqlite_Wpdb
	 */
	public $db;

	/**
	 * @var bool
	 */
	public $hpos;

	/**
	 * Builds the tables and the catalogue.
	 *
	 * @param bool $hpos Whether the orders live in the HPOS table.
	 */
	public function __construct( bool $hpos ) {
		$db           = new Sqlite_Wpdb();
		$db->posts    = 'wp_posts';
		$db->postmeta = 'wp_postmeta';
		$this->db     = $db;
		$this->hpos   = $hpos;

		Sqlite_Tables::install( $db );
		$db->define( 'CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_type TEXT, post_status TEXT, post_date_gmt TEXT)' );
		$db->define( 'CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT)' );
		$db->define( 'CREATE TABLE wp_wc_orders (id INTEGER PRIMARY KEY, type TEXT, status TEXT, date_created_gmt TEXT, total_amount REAL)' );
		$db->define( 'CREATE TABLE wp_wc_order_operational_data (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, created_via TEXT)' );
		$db->define( 'CREATE TABLE wp_woocommerce_order_items (order_item_id INTEGER PRIMARY KEY AUTOINCREMENT, order_item_name TEXT, order_item_type TEXT, order_id INTEGER)' );
		$db->define( 'CREATE TABLE wp_woocommerce_order_itemmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, order_item_id INTEGER, meta_key TEXT, meta_value TEXT)' );

		$this->order( 101, 'wc-processing', '2026-11-01 10:00:00', 1000, [ [ 'Футболка', 11, 0, 2, 1000 ] ] );
		$this->posting( 1, 'P-101', 101, 'awaiting_packaging', false, '2026-11-01 10:00:00' );
		$this->order( 102, 'wc-completed', '2026-11-03 09:00:00', 500, [ [ 'Чашка', 12, 0, 1, 300 ], [ 'Кружка', 0, 0, 1, 200 ] ] );
		$this->posting( 1, 'P-102', 102, 'delivered', true );
		$this->order( 103, 'wc-cancelled', '2026-11-05 12:00:00', 400, [ [ 'Футболка', 11, 0, 1, 400 ] ] );
		$this->posting( 1, 'P-103', 103, 'cancelled', true );
		$this->order( 104, 'wc-processing', '2026-11-07 20:30:00', 300, [ [ 'Худи — L', 13, 14, 3, 300 ] ] );
		$this->posting( 2, 'P-104', 104, 'awaiting_deliver', false, '2026-11-07 06:00:00' );
		$this->order( 105, 'wc-processing', '2026-10-31 21:30:00', 200, [ [ 'Кружка', 0, 0, 2, 200 ] ] );
		$this->posting( 1, 'P-105', 105, 'delivering', false );
		$this->order( 106, 'wc-processing', '2026-11-07 21:30:00', 999, [ [ 'Футболка', 11, 0, 9, 999 ] ] );
		$this->posting( 1, 'P-106', 106, 'awaiting_packaging', false, '2026-11-07 21:30:00' );
		$this->order( 107, 'wc-pending', '2026-11-02 10:00:00', 70, [] );
		$this->posting( 1, 'P-107', 107, 'new', false );
		$this->order( 108, 'trash', '2026-11-02 10:00:00', 70, [] );
		$this->posting( 1, 'P-108', 108, 'awaiting_packaging', false );

		$this->order( 201, 'wc-completed', '2026-10-28 10:00:00', 800, [ [ 'Футболка', 11, 0, 4, 800 ] ] );
		$this->posting( 1, 'P-201', 201, 'delivered', true );
		$this->order( 202, 'wc-cancelled', '2026-10-29 10:00:00', 100, [] );
		$this->posting( 1, 'P-202', 202, 'cancelled', true );

		$this->order( 301, 'wc-completed', '2026-11-04 10:00:00', 1500, [ [ 'Футболка', 11, 0, 1, 1500 ] ], 'checkout' );
		$this->order( 302, 'wc-cancelled', '2026-11-04 11:00:00', 50, [], 'checkout' );
		$this->order( 303, 'wc-completed', '2026-10-27 10:00:00', 200, [], 'checkout' );
	}

	/**
	 * The storage descriptor the reads are built with.
	 *
	 * @return Site_Orders_Storage
	 */
	public function storage(): Site_Orders_Storage {
		return new Site_Orders_Storage( $this->hpos, $this->hpos ? 'wp_wc_orders' : 'wp_posts' );
	}

	/**
	 * Adds an order with its lines.
	 *
	 * @param int                             $id          Order id.
	 * @param string                          $status      Status with its `wc-` prefix.
	 * @param string                          $created     Creation time, UTC.
	 * @param float                           $total       Order total.
	 * @param array<int,array<int,int|string>> $lines      `[ name, product id, variation id, quantity, line total ]` each.
	 * @param string                          $created_via Who made the order.
	 * @return void
	 */
	public function order( int $id, string $status, string $created, float $total, array $lines, string $created_via = self::CREATED_VIA ): void {
		if ( $this->hpos ) {
			$this->db->insert( 'wp_wc_orders', [ 'id' => $id, 'type' => 'shop_order', 'status' => $status, 'date_created_gmt' => $created, 'total_amount' => $total ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- A fixture row.
			$this->db->insert( 'wp_wc_order_operational_data', [ 'order_id' => $id, 'created_via' => $created_via ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- A fixture row.
		} else {
			$this->db->insert( 'wp_posts', [ 'ID' => $id, 'post_type' => 'shop_order', 'post_status' => $status, 'post_date_gmt' => $created ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- A fixture row.
			$this->meta( 'wp_postmeta', 'post_id', $id, [ '_order_total' => (string) $total, '_created_via' => $created_via ] );
		}

		foreach ( $lines as [ $name, $product, $variation, $quantity, $line_total ] ) {
			$this->db->insert( 'wp_woocommerce_order_items', [ 'order_item_name' => $name, 'order_item_type' => 'line_item', 'order_id' => $id ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- A fixture row.
			$item = (int) $this->db->get_var( 'SELECT MAX(order_item_id) FROM wp_woocommerce_order_items' );
			$this->meta(
				'wp_woocommerce_order_itemmeta',
				'order_item_id',
				$item,
				[
					'_product_id'   => (string) $product,
					'_variation_id' => (string) $variation,
					'_qty'          => (string) $quantity,
					'_line_total'   => (string) $line_total,
				]
			);
		}

		// A shipping line must not count as a product.
		$this->db->insert( 'wp_woocommerce_order_items', [ 'order_item_name' => 'Доставка', 'order_item_type' => 'shipping', 'order_id' => $id ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- A fixture row.
	}

	/**
	 * Adds a registry row.
	 *
	 * @param int    $account    Account.
	 * @param string $key        Posting number.
	 * @param int    $order_id   Order.
	 * @param string $raw        The marketplace's status.
	 * @param bool   $terminal   Whether the marketplace has finished with it.
	 * @param string $first_seen When the shop first saw it, UTC.
	 * @return void
	 */
	public function posting( int $account, string $key, int $order_id, string $raw, bool $terminal, string $first_seen = '2026-11-01 00:00:00' ): void {
		$this->db->insert(
			Sqlite_Tables::table( 'postings' ),
			[
				'account_id'    => $account,
				'external_key'  => $key,
				'wc_order_id'   => $order_id,
				'status_raw'    => $raw,
				'is_terminal'   => $terminal ? 1 : 0,
				'first_seen_at' => $first_seen,
			]
		);
	}

	/**
	 * Adds meta rows.
	 *
	 * @param string               $table  Meta table.
	 * @param string               $column Owner column.
	 * @param int                  $owner  Owner id.
	 * @param array<string,string> $values Values by key.
	 * @return void
	 */
	private function meta( string $table, string $column, int $owner, array $values ): void {
		foreach ( $values as $key => $value ) {
			$this->db->insert(
				$table,
				[
					$column      => $owner,
					'meta_key'   => $key,
					'meta_value' => $value,
				]
			);
		}
	}
}
