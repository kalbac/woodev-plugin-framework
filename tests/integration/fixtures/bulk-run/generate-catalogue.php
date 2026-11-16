<?php
/**
 * Generates the catalogue of the bulk run scale test (#1795, E2): 10 000 parents, 18 592 offers, 16 592 linked.
 *
 * Usage: `wp eval-file generate-catalogue.php <adapter id> <account id> [reset|delete]` — `delete` removes the fixture and stops, for the cleanup after the measurement
 *
 * Direct SQL on purpose: it writes only what the «Товары сайта» table, the reader and the link store read — the post, its type term,
 * `_sku`, the price and stock meta, the lookup row and, for a variation, `attribute_size`. It does NOT build a product the shop could
 * export (no images, no categories): the acceptance runs flags, warehouse and an export that only has to *reach* the offers.
 * Never run it on a production site: it creates ten thousand products.
 *
 * @package Woodev\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Woodev\Framework\Marketplace\Data\Link_Record;
use Woodev\Framework\Marketplace\Data\Schema;
use Woodev\Framework\Marketplace\Data\Wpdb_Link_Store;

global $wpdb;

$adapter = isset( $args[0] ) ? (string) $args[0] : '';
$account = isset( $args[1] ) ? (int) $args[1] : 0;
$delete  = isset( $args[2] ) && 'delete' === $args[2];
$reset   = $delete || ( isset( $args[2] ) && 'reset' === $args[2] );

if ( '' === $adapter || $account <= 0 ) {
	WP_CLI::error( 'Usage: wp eval-file generate-catalogue.php <adapter id> <account id> [reset|delete]' );
}

const WOODEV_FIXTURE_PREFIX = 'BULKRUN-';
const WOODEV_SIMPLE         = 8000;
const WOODEV_LINKED_SIMPLE  = 6000;
const WOODEV_VARIABLE_FIVE  = 1998;
const WOODEV_SIZES          = [ 'S', 'M', 'L', 'XL', 'XXL' ];

$schema = new Schema( $adapter );
$links  = new Wpdb_Link_Store( $wpdb, $schema->table_name( 'links', $wpdb->prefix ), $schema->table_name( 'link_ids', $wpdb->prefix ) );

$fixture_ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value LIKE %s", $wpdb->esc_like( WOODEV_FIXTURE_PREFIX ) . '%' ) );

if ( [] !== $fixture_ids && ! $reset ) {
	WP_CLI::error( count( $fixture_ids ) . ' fixture products exist already. Pass `reset` to delete and rebuild.' );
}

if ( [] !== $fixture_ids ) {
	$chunks = array_chunk( array_map( 'intval', $fixture_ids ), 1000 );

	foreach ( $chunks as $chunk ) {
		$in = implode( ',', $chunk );
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$in}) OR post_parent IN ({$in})" );
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$in})" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id IN ({$in})" );
		$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$in})" );
		// The sync state of a link is keyed by `link_id`, so it goes first, while the link rows that name it still exist.
		$wpdb->query( $wpdb->prepare( 'DELETE s FROM %i s INNER JOIN %i l ON l.link_id = s.link_id WHERE l.account_id = %d AND l.wc_product_id IN (' . $in . ')', $schema->table_name( 'sync_state', $wpdb->prefix ), $schema->table_name( 'links', $wpdb->prefix ), $account ) ); // phpcs:ignore
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE account_id = %d AND wc_product_id IN (' . $in . ')', $schema->table_name( 'links', $wpdb->prefix ), $account ) ); // phpcs:ignore
	}

	WP_CLI::log( 'Removed ' . count( $fixture_ids ) . ' fixture products.' );
}

if ( $delete ) {
	WP_CLI::success( 'Fixture deleted; nothing rebuilt.' );
	return;
}

$simple_term   = (int) $wpdb->get_var( "SELECT tt.term_taxonomy_id FROM {$wpdb->term_taxonomy} tt JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tt.taxonomy = 'product_type' AND t.slug = 'simple'" );
$variable_term = (int) $wpdb->get_var( "SELECT tt.term_taxonomy_id FROM {$wpdb->term_taxonomy} tt JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tt.taxonomy = 'product_type' AND t.slug = 'variable'" );

if ( ! $simple_term || ! $variable_term ) {
	WP_CLI::error( 'The product_type terms «simple» and «variable» are missing: is WooCommerce active?' );
}

/**
 * Inserts rows with one statement per call.
 *
 * @param string                 $table   Table.
 * @param string[]               $columns Columns.
 * @param array<int,array<mixed>> $rows    Rows.
 */
function woodev_fixture_insert( string $table, array $columns, array $rows ): void {
	global $wpdb;

	if ( [] === $rows ) {
		return;
	}

	$one    = '(' . implode( ',', array_fill( 0, count( $columns ), '%s' ) ) . ')';
	$values = [];
	$flat   = [];

	foreach ( $rows as $row ) {
		$values[] = $one;
		foreach ( $row as $cell ) {
			$flat[] = $cell;
		}
	}

	$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (" . implode( ',', $columns ) . ') VALUES ' . implode( ',', $values ), $flat ) ); // phpcs:ignore
}

/**
 * Writes a batch of products or variations.
 *
 * @param array<int,array<string,mixed>> $batch Each: id, parent, type (`product` / `product_variation`), title, sku, term (type term id), size.
 */
function woodev_fixture_write( array $batch ): void {
	global $wpdb;

	$now   = current_time( 'mysql' );
	$gmt   = current_time( 'mysql', true );
	$posts = [];
	$meta  = [];
	$terms = [];
	$look  = [];

	foreach ( $batch as $item ) {
		$status  = 'publish';
		$posts[] = [ $item['id'], 1, $now, $gmt, '', $item['title'], '', $status, 'closed', 'closed', sanitize_title( $item['sku'] ), $now, $gmt, '', $item['parent'], '', 0, $item['type'], '', 0 ];

		foreach ( [ '_sku' => $item['sku'], '_regular_price' => '100', '_price' => '100', '_manage_stock' => 'yes', '_stock' => '10', '_stock_status' => 'instock', '_virtual' => 'no', '_downloadable' => 'no' ] as $key => $value ) {
			$meta[] = [ $item['id'], $key, $value ];
		}

		if ( 'product_variation' === $item['type'] ) {
			$meta[] = [ $item['id'], 'attribute_size', $item['size'] ];
		} else {
			$terms[] = [ $item['id'], $item['term'], 0 ];

			if ( 'variable' === $item['kind'] ) {
				$meta[] = [ $item['id'], '_product_attributes', serialize( [ 'size' => [ 'name' => 'size', 'value' => implode( ' | ', WOODEV_SIZES ), 'position' => 0, 'is_visible' => 1, 'is_variation' => 1, 'is_taxonomy' => 0 ] ] ) ];
			}
		}

		$look[] = [ $item['id'], $item['sku'], 0, 0, 10, 'instock', 100, 100, 0, 0, 0, 0, '' ];
	}

	woodev_fixture_insert( $wpdb->posts, [ 'ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_name', 'post_modified', 'post_modified_gmt', 'post_content_filtered', 'post_parent', 'guid', 'menu_order', 'post_type', 'post_mime_type', 'comment_count' ], $posts );
	woodev_fixture_insert( $wpdb->postmeta, [ 'post_id', 'meta_key', 'meta_value' ], $meta );
	woodev_fixture_insert( $wpdb->term_relationships, [ 'object_id', 'term_taxonomy_id', 'term_order' ], $terms );
	woodev_fixture_insert( $wpdb->prefix . 'wc_product_meta_lookup', [ 'product_id', 'sku', 'virtual', 'downloadable', 'stock_quantity', 'stock_status', 'min_price', 'max_price', 'onsale', 'rating_count', 'average_rating', 'total_sales', 'tax_class' ], $look );
}

$next    = (int) $wpdb->get_var( "SELECT GREATEST(COALESCE(MAX(ID), 0), 0) + 1000000 FROM {$wpdb->posts}" );
$batch   = [];
$link_of = [];
$stats   = [ 'parents' => 0, 'offers' => 0, 'linked' => 0 ];

$flush = static function () use ( &$batch ): void {
	woodev_fixture_write( $batch );
	$batch = [];
};

// 8 000 simple products: the first 6 000 linked, the last 2 000 not.
for ( $i = 1; $i <= WOODEV_SIMPLE; $i++ ) {
	$id      = $next++;
	$sku     = WOODEV_FIXTURE_PREFIX . 'S' . $i;
	$batch[] = [ 'id' => $id, 'parent' => 0, 'type' => 'product', 'kind' => 'simple', 'title' => 'Простой товар ' . $i, 'sku' => $sku, 'term' => $simple_term, 'size' => '' ];
	++$stats['parents'];
	++$stats['offers'];

	if ( $i <= WOODEV_LINKED_SIMPLE ) {
		$link_of[] = [ $id, 0, $sku ];
	}

	if ( count( $batch ) >= 400 ) {
		$flush();
	}
}

// 1 998 variable products with 5 variations, then one with 101 and one with 501.
$variable_shapes = array_merge( array_fill( 0, WOODEV_VARIABLE_FIVE, 5 ), [ 101, 501 ] );

foreach ( $variable_shapes as $n => $variations ) {
	$parent  = $next++;
	$sku     = WOODEV_FIXTURE_PREFIX . 'V' . ( $n + 1 );
	$batch[] = [ 'id' => $parent, 'parent' => 0, 'type' => 'product', 'kind' => 'variable', 'title' => 'Вариативный товар ' . ( $n + 1 ), 'sku' => $sku, 'term' => $variable_term, 'size' => '' ];
	++$stats['parents'];

	for ( $v = 1; $v <= $variations; $v++ ) {
		$id      = $next++;
		$vsku    = $sku . '-' . $v;
		$batch[] = [ 'id' => $id, 'parent' => $parent, 'type' => 'product_variation', 'kind' => 'variation', 'title' => 'Вариант ' . $v, 'sku' => $vsku, 'term' => 0, 'size' => WOODEV_SIZES[ ( $v - 1 ) % 5 ] . ( $variations > 5 ? (string) $v : '' ) ];
		++$stats['offers'];
		$link_of[] = [ $id, $parent, $vsku ];

		if ( count( $batch ) >= 400 ) {
			$flush();
		}
	}
}

$flush();
WP_CLI::log( sprintf( 'Products written: %d parents, %d offers. Linking %d offers…', $stats['parents'], $stats['offers'], count( $link_of ) ) );

// Link rows through the framework's own store, so every column and index is as the plugin writes it. Both switches off.
foreach ( $link_of as $row ) {
	$links->insert_if_absent( Link_Record::offer( $account, $row[0], $row[1], $row[2], [ 'card_state' => Link_Record::STATE_CREATED, 'sync_stock' => 0, 'sync_price' => 0 ] ) );
	++$stats['linked'];
}

clean_post_cache( 0 );
wp_cache_flush();

WP_CLI::success( sprintf( '%d parents, %d offers, %d linked (expected 10000 / 18592 / 16592), %d unlinked simple products.', $stats['parents'], $stats['offers'], $stats['linked'], WOODEV_SIMPLE - WOODEV_LINKED_SIMPLE ) );
