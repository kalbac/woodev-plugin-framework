<?php
/**
 * A small WooCommerce catalogue on SQLite for the «Товары сайта» tests: posts, lookup rows, terms and a link per product.
 *
 * @package Woodev\Tests\Unit\Marketplace\Admin\Products
 */

namespace Woodev\Tests\Unit\Marketplace\Admin\Products;

use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;
use Woodev\Tests\Unit\Marketplace\Data\Support\Sqlite_Tables;
use Woodev\Tests\Unit\Marketplace\Support\Sqlite_Wpdb;

/**
 * Lays out the WordPress tables the reader joins (the real ones are MySQL; the columns it reads are the same) and offers
 * `product()`, `variation()` and `link()` to fill them.
 *
 * The catalogue the tests share:
 *
 * | id | what | SKU | stock | price | link |
 * |---|---|---|---|---|---|
 * | 10 | simple «Платье» | P-10 | 5 | 100 | created, article OZ-10 |
 * | 20 | variable «Кроссовки» (category 7) | S-20 | — | — | none |
 * | 21 | its variation, attribute «красный» | S-21 | 3 | 200 | failed, error text |
 * | 22 | its variation, attribute «синий» | S-22 | 4 | 250 | none |
 * | 30 | simple «Сумка» | B-30 | 0 | 50 | none |
 */
final class Products_Shop {
	public const ACCOUNT = 1;
	public const OTHER   = 2;

	/**
	 * @var Sqlite_Wpdb
	 */
	public $db;

	/**
	 * Account the shared links belong to.
	 *
	 * @var int
	 */
	public $account;

	/**
	 * @var Wpdb_Link_Store
	 */
	public $links;

	/**
	 * Builds the WordPress tables and the shared catalogue.
	 *
	 * @param Sqlite_Wpdb|null $db      A database that already holds the marketplace tables; a fresh one when null.
	 * @param int              $account Account the shared links belong to.
	 */
	public function __construct( ?Sqlite_Wpdb $db = null, int $account = self::ACCOUNT ) {
		$this->account = $account;
		$this->db      = $db ?? new Sqlite_Wpdb();

		if ( null === $db ) {
			Sqlite_Tables::install( $this->db );
		}

		$this->db->posts              = 'wp_posts';
		$this->db->postmeta           = 'wp_postmeta';
		$this->db->terms              = 'wp_terms';
		$this->db->term_taxonomy      = 'wp_term_taxonomy';
		$this->db->term_relationships = 'wp_term_relationships';

		foreach ( $this->tables() as $sql ) {
			$this->db->define( $sql );
		}

		$this->links = new Wpdb_Link_Store(
			$this->db,
			Sqlite_Tables::table( 'links' ),
			Sqlite_Tables::table( 'link_ids' ),
			static function (): int {
				return 1794139200;
			}
		);

		$this->term( 1, 'simple', 'product_type' );
		$this->term( 2, 'variable', 'product_type' );
		$this->term( 7, 'Обувь', 'product_cat' );
		$this->term( 8, 'Кеды', 'product_cat' );

		$this->product( 10, 'Платье', 'P-10', 5, '100', 'simple' );
		$this->product( 20, 'Кроссовки', 'S-20', null, null, 'variable', [ 7 ] );
		$this->variation( 21, 20, 'S-21', 3, '200', 'красный' );
		$this->variation( 22, 20, 'S-22', 4, '250', 'синий' );
		$this->product( 30, 'Сумка', 'B-30', 0, '50', 'simple' );

		$this->link( 10, 0, 'OZ-10', Link_Record::STATE_CREATED, [], $account );
		$this->link( 21, 20, 'S-21', Link_Record::STATE_FAILED, [ 'last_error_text' => 'Не заполнен бренд' ], $account );
	}

	/**
	 * Adds a listed product.
	 *
	 * @param int        $id       Id.
	 * @param string     $title    Title.
	 * @param string     $sku      SKU.
	 * @param int|null   $stock    Stock.
	 * @param string|null $price   Price.
	 * @param string     $type     `simple` or `variable`.
	 * @param int[]      $category Category term ids.
	 * @param string     $status   Post status.
	 * @return void
	 */
	public function product( int $id, string $title, string $sku, ?int $stock, ?string $price, string $type, array $category = [], string $status = 'publish' ): void {
		$this->post( $id, 0, 'product', $title, $status );
		$this->lookup( $id, $sku, $stock, $price );
		$this->db->insert( 'wp_term_relationships', [ 'object_id' => $id, 'term_taxonomy_id' => 'variable' === $type ? 2 : 1 ] );

		foreach ( $category as $term_id ) {
			$this->db->insert( 'wp_term_relationships', [ 'object_id' => $id, 'term_taxonomy_id' => $term_id ] );
		}
	}

	/**
	 * Adds a published variation with one attribute.
	 *
	 * @param int        $id        Id.
	 * @param int        $parent    Parent.
	 * @param string     $sku       SKU.
	 * @param int|null   $stock     Stock.
	 * @param string|null $price    Price.
	 * @param string     $attribute Attribute value; the empty string means «any».
	 * @return void
	 */
	public function variation( int $id, int $parent, string $sku, ?int $stock, ?string $price, string $attribute ): void {
		$this->post( $id, $parent, 'product_variation', 'Вариант ' . $id, 'publish', $id );
		$this->lookup( $id, $sku, $stock, $price );
		$this->db->insert( 'wp_postmeta', [ 'post_id' => $id, 'meta_key' => 'attribute_color', 'meta_value' => $attribute ] );
	}

	/**
	 * Adds an offer row of the shared account.
	 *
	 * @param int                 $product Product or variation id.
	 * @param int                 $parent  Parent id.
	 * @param string              $offer   Article.
	 * @param string              $state   Card state.
	 * @param array<string,mixed> $more    More columns.
	 * @param int|null            $account Account; the shop's own when null.
	 * @return Link_Record
	 */
	public function link( int $product, int $parent, string $offer, string $state, array $more = [], ?int $account = null ): Link_Record {
		$account = $account ?? $this->account;

		$error = (string) ( $more['last_error_text'] ?? '' );
		unset( $more['last_error_text'] );

		$this->links->insert_if_absent( Link_Record::offer( $account, $product, $parent, $offer, array_merge( [ 'card_state' => $state, 'sync_stock' => 1, 'sync_price' => 1 ], $more ) ) );
		$link = $this->links->find( $account, $product );

		if ( '' !== $error ) {
			$this->links->record_blocked( (int) $link->link_id(), 'test', $error );
		}

		return $link;
	}

	/**
	 * Stores what the last stock readback saw.
	 *
	 * @param int $link_id  Link id.
	 * @param int $present  Present.
	 * @param int $reserved Reserved.
	 * @return void
	 */
	public function readback( int $link_id, int $present, int $reserved ): void {
		$this->db->insert(
			Sqlite_Tables::table( 'sync_state' ),
			[
				'account_id'      => $this->account,
				'link_id'         => $link_id,
				'warehouse_id'    => 22,
				'kind'            => 'stock',
				'remote_present'  => $present,
				'remote_reserved' => $reserved,
				'remote_read_at'  => '2026-11-12 08:00:00',
			]
		);
	}

	/**
	 * @param int    $id     Id.
	 * @param int    $parent Parent.
	 * @param string $type   Post type.
	 * @param string $title  Title.
	 * @param string $status Status.
	 * @param int    $order  Menu order.
	 * @return void
	 */
	private function post( int $id, int $parent, string $type, string $title, string $status, int $order = 0 ): void {
		$this->db->insert(
			'wp_posts',
			[
				'ID'          => $id,
				'post_parent' => $parent,
				'post_type'   => $type,
				'post_title'  => $title,
				'post_status' => $status,
				'menu_order'  => $order,
			]
		);
	}

	/**
	 * @param int         $id    Id.
	 * @param string      $sku   SKU.
	 * @param int|null    $stock Stock.
	 * @param string|null $price Price.
	 * @return void
	 */
	private function lookup( int $id, string $sku, ?int $stock, ?string $price ): void {
		$this->db->insert(
			'wp_wc_product_meta_lookup',
			[
				'product_id'     => $id,
				'sku'            => $sku,
				'stock_quantity' => $stock,
				'min_price'      => $price,
			]
		);
		$this->db->insert( 'wp_postmeta', [ 'post_id' => $id, 'meta_key' => '_sku', 'meta_value' => $sku ] );
	}

	/**
	 * @param int    $id       Term id (also its taxonomy row id).
	 * @param string $name     Name and slug.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	private function term( int $id, string $name, string $taxonomy ): void {
		$this->db->insert( 'wp_terms', [ 'term_id' => $id, 'name' => $name, 'slug' => $name ] );
		$this->db->insert( 'wp_term_taxonomy', [ 'term_taxonomy_id' => $id, 'term_id' => $id, 'taxonomy' => $taxonomy ] );
	}

	/**
	 * The WordPress tables, in SQLite's dialect.
	 *
	 * @return string[]
	 */
	private function tables(): array {
		return [
			'CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_parent INTEGER NOT NULL DEFAULT 0, post_type TEXT NOT NULL, post_title TEXT NOT NULL DEFAULT "", post_status TEXT NOT NULL, menu_order INTEGER NOT NULL DEFAULT 0)',
			'CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, meta_key TEXT, meta_value TEXT)',
			'CREATE TABLE wp_terms (term_id INTEGER PRIMARY KEY, name TEXT, slug TEXT)',
			'CREATE TABLE wp_term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY, term_id INTEGER, taxonomy TEXT)',
			'CREATE TABLE wp_term_relationships (object_id INTEGER, term_taxonomy_id INTEGER)',
			'CREATE TABLE wp_wc_product_meta_lookup (product_id INTEGER PRIMARY KEY, sku TEXT, stock_quantity INTEGER NULL, min_price NUMERIC NULL)',
		];
	}
}
