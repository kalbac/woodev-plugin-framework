<?php
/**
 * #928 wave 2 — shared helpers for the negative-filter form measurement.
 *
 * Runs INSIDE the wp-env `cli` container (`wp eval-file`), so the SQL text comes from the REAL
 * WooCommerce query builders (HPOS `OrdersTableQuery`, CPT `WP_Query` via `WC_Order_Data_Store_CPT`)
 * fed by the REAL `Orders_Query::build_args()`. That SQL is captured by a filter that throws before the
 * query executes, and is then RUN through a private mysqli connection against the throwaway
 * `probe928` database — never through `$wpdb`, never against `wordpress`.
 *
 * Nothing here writes to any database other than `probe928`.
 */

namespace Woodev\Research928;

use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Query;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Delivery_Status;

const PROBE_DB    = 'probe928';
const REAL_DB     = 'wordpress';
const STMT_LIMIT  = 30; // seconds; MariaDB's `max_statement_time` (the rig runs MariaDB — `max_execution_time` does not exist there).

final class Abort_Capture extends \RuntimeException {}

final class Probe_Query extends Orders_Query {
	private bool $hpos;
	public function __construct( ?Orders_Registry $registry, bool $hpos ) {
		parent::__construct( $registry );
		$this->hpos = $hpos;
	}
	protected function is_hpos_enabled(): bool {
		return $this->hpos;
	}
}

/** Admin connection. `$db` null => no default schema. */
function connect( ?string $db = PROBE_DB ): \mysqli {
	mysqli_report( MYSQLI_REPORT_OFF );
	$m = new \mysqli( 'mysql', 'root', 'password', $db ?? '' );
	if ( $m->connect_errno ) {
		throw new \RuntimeException( 'mysqli: ' . $m->connect_error );
	}
	$m->set_charset( 'utf8mb4' );
	$m->query( 'SET SESSION max_statement_time=' . STMT_LIMIT );
	return $m;
}

function q( \mysqli $m, string $sql ) {
	$r = $m->query( $sql );
	if ( false === $r ) {
		throw new \RuntimeException( "SQL error {$m->errno}: {$m->error}\n" . substr( $sql, 0, 400 ) );
	}
	return $r;
}

/** Copies the four tables' DDL from the real rig DB (SHOW CREATE TABLE, read-only) into probe928, and dumps it. */
function create_schema( \mysqli $m, string $dump_to ): void {
	q( $m, 'CREATE DATABASE IF NOT EXISTS ' . PROBE_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci' );
	q( $m, 'USE ' . PROBE_DB );
	$ddl_all = '';
	foreach ( [ 'wp_posts', 'wp_postmeta', 'wp_wc_orders', 'wp_wc_orders_meta' ] as $t ) {
		$row = q( $m, 'SHOW CREATE TABLE ' . REAL_DB . '.' . $t )->fetch_row();
		$ddl = preg_replace( '/ AUTO_INCREMENT=\d+/', '', $row[1] );
		$ddl_all .= $ddl . ";\n\n";
		q( $m, "DROP TABLE IF EXISTS $t" );
		q( $m, $ddl );
	}
	file_put_contents( $dump_to, $ddl_all );
}

// ------------------------------------------------------------------ carriers ----------------------

function registry_of( int $mapped, int $bare ): Orders_Registry {
	$registry = Orders_Registry::instance();
	$registry->reset_for_tests();
	for ( $i = 1; $i <= $mapped; $i++ ) {
		$registry->register_provider(
			Orders_Provider::create(
				"m{$i}", "M{$i}", "_m{$i}_marker", [ "m{$i}" ],
				[
					'status_meta_key' => "_m{$i}_status",
					'status_map'      => [
						"M{$i}_GO"   => Delivery_Status::IN_TRANSIT,
						"M{$i}_DONE" => Delivery_Status::DELIVERED,
					],
				]
			)
		);
	}
	for ( $i = 1; $i <= $bare; $i++ ) {
		$registry->register_provider( Orders_Provider::create( "b{$i}", "B{$i}", "_b{$i}_marker", [ "b{$i}" ] ) );
	}
	return $registry;
}

/** Filter name => [request, the raw status values that DISQUALIFY an order, per mapped carrier i]. */
function filter_spec( string $name, int $mapped ): array {
	$raw = static function ( array $suffixes ) use ( $mapped ): array {
		$out = [];
		for ( $i = 1; $i <= $mapped; $i++ ) {
			$out[ "_m{$i}_status" ] = array_map( static fn( $s ) => "M{$i}_{$s}", $suffixes );
		}
		return $out;
	};
	switch ( $name ) {
		case 'unknown':
			return [ [ 'delivery_status' => 'unknown' ], $raw( [ 'GO', 'DONE' ] ) ];
		case 'not_in_transit':
			return [ [ 'delivery_status_not' => 'in_transit' ], $raw( [ 'GO' ] ) ];
		case 'not_delivered':
			return [ [ 'delivery_status_not' => 'delivered' ], $raw( [ 'DONE' ] ) ];
	}
	throw new \InvalidArgumentException( $name );
}

// ------------------------------------------------------------------ seeding -----------------------

const UNRELATED_KEYS = [
	'_order_key', '_customer_user', '_payment_method', '_payment_method_title', '_transaction_id',
	'_customer_ip_address', '_customer_user_agent', '_created_via', '_order_currency', '_cart_hash',
	'_billing_first_name', '_billing_last_name', '_billing_address_1', '_billing_city', '_billing_postcode',
	'_billing_email', '_billing_phone', '_shipping_first_name', '_order_total', '_order_shipping',
];

function multi_insert( \mysqli $m, string $table, string $cols, array &$rows ): void {
	foreach ( array_chunk( $rows, 4000 ) as $chunk ) {
		q( $m, "INSERT INTO $table ($cols) VALUES " . implode( ',', $chunk ) );
	}
	$rows = [];
}

/** Orders 1..$n in BOTH datastores, ~20 unrelated meta rows each. Deterministic. */
function seed_base( \mysqli $m, int $n ): void {
	foreach ( [ 'wp_posts', 'wp_postmeta', 'wp_wc_orders', 'wp_wc_orders_meta' ] as $t ) {
		q( $m, "TRUNCATE TABLE $t" );
	}
	mt_srand( 928 );
	$pool = [];
	for ( $i = 0; $i < 4000; $i++ ) {
		$pool[] = "'" . substr( md5( (string) $i ) . md5( (string) ( $i * 7 ) ), 0, mt_rand( 6, 40 ) ) . "'";
	}
	$statuses = [ 'wc-completed' => 70, 'wc-processing' => 15, 'wc-on-hold' => 5, 'wc-pending' => 5, 'wc-cancelled' => 3, 'wc-failed' => 2 ];
	$bag      = [];
	foreach ( $statuses as $s => $w ) {
		$bag = array_merge( $bag, array_fill( 0, $w, $s ) );
	}
	$base = strtotime( '2023-01-01 00:00:00 UTC' );
	$span = 3 * 365 * 86400;

	$posts = $orders = $pmeta = $ometa = [];
	for ( $id = 1; $id <= $n; $id++ ) {
		$ts     = $base + (int) ( $span * $id / $n ) + mt_rand( 0, 3600 );
		$date   = gmdate( 'Y-m-d H:i:s', $ts );
		$status = $bag[ mt_rand( 0, 99 ) ];
		$posts[]  = "($id,1,'$date','$date','','Order $id','','$status','closed','closed','','order-$id','','','$date','$date','',0,'',0,'shop_order','',0)";
		$orders[] = "($id,'$status','RUB','shop_order',0,100,1,'a$id@example.com','$date','$date',0,'cod','Cash','',NULL,NULL,NULL)";
		foreach ( array_slice( UNRELATED_KEYS, 0, (int) ( getenv( 'UNRELATED' ) ?: count( UNRELATED_KEYS ) ) ) as $k ) {
			$v        = $pool[ mt_rand( 0, 3999 ) ];
			$pmeta[]  = "($id,'$k',$v)";
			$ometa[]  = "($id,'$k',$v)";
		}
		if ( count( $pmeta ) >= 8000 ) {
			multi_insert( $m, 'wp_postmeta', 'post_id,meta_key,meta_value', $pmeta );
			multi_insert( $m, 'wp_wc_orders_meta', 'order_id,meta_key,meta_value', $ometa );
		}
		if ( count( $posts ) >= 4000 ) {
			multi_insert( $m, 'wp_posts', 'ID,post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_password,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count', $posts );
			multi_insert( $m, 'wp_wc_orders', 'id,status,currency,type,tax_amount,total_amount,customer_id,billing_email,date_created_gmt,date_updated_gmt,parent_order_id,payment_method,payment_method_title,transaction_id,ip_address,user_agent,customer_note', $orders );
		}
	}
	multi_insert( $m, 'wp_postmeta', 'post_id,meta_key,meta_value', $pmeta );
	multi_insert( $m, 'wp_wc_orders_meta', 'order_id,meta_key,meta_value', $ometa );
	multi_insert( $m, 'wp_posts', 'ID,post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_password,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count', $posts );
	multi_insert( $m, 'wp_wc_orders', 'id,status,currency,type,tax_amount,total_amount,customer_id,billing_email,date_created_gmt,date_updated_gmt,parent_order_id,payment_method,payment_method_title,transaction_id,ip_address,user_agent,customer_note', $orders );
}

/**
 * Replaces the carrier meta: 5 % of orders carry no marker; the rest are spread evenly over M mapped + B bare
 * carriers, ONE marker per order (the operator's rule of 26.09.2026). A mapped carrier's order has status meta
 * `none 10 % / GO 20 % / DONE 55 % / JUNK 15 %` — a mature store, where most orders have delivered.
 */
function seed_carriers( \mysqli $m, int $n, int $mapped, int $bare ): array {
	q( $m, "DELETE FROM wp_postmeta WHERE meta_key REGEXP '^_(m|b)[0-9]+_(marker|status)\$'" );
	q( $m, "DELETE FROM wp_wc_orders_meta WHERE meta_key REGEXP '^_(m|b)[0-9]+_(marker|status)\$'" );
	mt_srand( 928 + 100 * $mapped + $bare );
	$total = $mapped + $bare;
	$pm    = $om = [];
	$stat  = [ 'markers' => 0, 'none' => 0, 'GO' => 0, 'DONE' => 0, 'JUNK' => 0 ];
	for ( $id = 1; $id <= $n; $id++ ) {
		if ( mt_rand( 1, 100 ) <= 5 ) {
			continue;
		}
		$c = mt_rand( 1, $total );
		if ( $c <= $mapped ) {
			$rows = [ [ "_m{$c}_marker", '1' ] ];
			$r    = mt_rand( 1, 100 );
			if ( $r <= 10 ) {
				$stat['none']++;
			} elseif ( $r <= 30 ) {
				$rows[] = [ "_m{$c}_status", "M{$c}_GO" ];
				$stat['GO']++;
			} elseif ( $r <= 85 ) {
				$rows[] = [ "_m{$c}_status", "M{$c}_DONE" ];
				$stat['DONE']++;
			} else {
				$rows[] = [ "_m{$c}_status", 'JUNK' ];
				$stat['JUNK']++;
			}
		} else {
			$b    = $c - $mapped;
			$rows = [ [ "_b{$b}_marker", '1' ] ];
		}
		$stat['markers']++;
		foreach ( $rows as [ $k, $v ] ) {
			$pm[] = "($id,'$k','$v')";
			$om[] = "($id,'$k','$v')";
		}
		if ( count( $pm ) >= 8000 ) {
			multi_insert( $m, 'wp_postmeta', 'post_id,meta_key,meta_value', $pm );
			multi_insert( $m, 'wp_wc_orders_meta', 'order_id,meta_key,meta_value', $om );
		}
	}
	multi_insert( $m, 'wp_postmeta', 'post_id,meta_key,meta_value', $pm );
	multi_insert( $m, 'wp_wc_orders_meta', 'order_id,meta_key,meta_value', $om );
	foreach ( [ 'wp_posts', 'wp_postmeta', 'wp_wc_orders', 'wp_wc_orders_meta' ] as $t ) {
		q( $m, "ANALYZE TABLE $t" )->free();
	}
	return $stat;
}

// ------------------------------------------------------------------ SQL capture -------------------

/**
 * Builds the REAL query for `$store` ('hpos'|'cpt') from `$args` and returns its SQL without executing it.
 * `$inject` (optional) = ['where' => raw SQL] appended through the datastore's supported SQL hook (form H).
 *
 * @return array{main:string,count:?string,build_ms:float}
 */
function capture( string $store, array $args, ?array $inject = null ): array {
	$main  = $count = null;
	$hooks = [];
	$add   = static function ( string $tag, callable $cb, int $prio, int $n ) use ( &$hooks ) {
		add_filter( $tag, $cb, $prio, $n );
		$hooks[] = [ $tag, $cb, $prio ];
	};

	if ( 'hpos' === $store ) {
		if ( $inject ) {
			$add( 'woocommerce_orders_table_query_clauses', static function ( $c ) use ( $inject ) {
				$c['where'] .= ' AND ' . $inject['where_hpos'];
				return $c;
			}, 50, 1 );
		}
		$add( 'woocommerce_orders_table_query_sql', static function ( $sql ) use ( &$main ) {
			$main = $sql;
			return $sql;
		}, 999, 1 );
		$add( 'woocommerce_orders_table_query_count_sql', static function ( $sql ) use ( &$count ) {
			$count = $sql;
			throw new Abort_Capture();
		}, 999, 1 );
	} else {
		if ( $inject ) {
			$add( 'posts_clauses', static function ( $c ) use ( $inject ) {
				$c['where'] .= ' AND ' . $inject['where_cpt'];
				return $c;
			}, 50, 1 );
		}
		$add( 'posts_request', static function ( $sql ) use ( &$main ) {
			$main = $sql;
			throw new Abort_Capture();
		}, 999, 1 );
	}

	$t0 = microtime( true );
	try {
		if ( 'hpos' === $store ) {
			wc_get_orders( $args );
		} else {
			( new \WC_Order_Data_Store_CPT() )->query( $args );
		}
	} catch ( Abort_Capture $e ) {
		// expected: SQL captured, nothing executed.
	} finally {
		$ms = ( microtime( true ) - $t0 ) * 1000;
		foreach ( $hooks as [ $tag, $cb, $prio ] ) {
			remove_filter( $tag, $cb, $prio );
		}
	}

	if ( null === $main ) {
		throw new \RuntimeException( "capture($store) got no SQL" );
	}
	return [ 'main' => $main, 'count' => $count, 'build_ms' => $ms ];
}

/** Args for the aggregate (all carriers) page 1, exactly as the page builds them. */
function page_args( string $store, Orders_Registry $registry, array $request ): array {
	return ( new Probe_Query( $registry, 'hpos' === $store ) )->build_args( $request );
}

// ------------------------------------------------------------------ subquery / id-set SQL ---------

function meta_tbl( string $store ): array {
	return 'hpos' === $store
		? [ 'wp_wc_orders_meta', 'order_id', 'wp_wc_orders.id' ]
		: [ 'wp_postmeta', 'post_id', 'wp_posts.ID' ];
}

/** `(meta_key=… AND meta_value IN (…)) OR …` over the disqualifying raw values, quoted like $wpdb->prepare would. */
function disqualifier( array $raw_by_key, string $alias = '' ): string {
	global $wpdb;
	$p     = '' === $alias ? '' : $alias . '.';
	$parts = [];
	foreach ( $raw_by_key as $key => $vals ) {
		$in      = implode( ',', array_map( static fn( $v ) => $wpdb->prepare( '%s', $v ), $vals ) );
		$parts[] = $wpdb->prepare( "({$p}meta_key = %s AND {$p}meta_value IN ($in))", $key );
	}
	return '(' . implode( ' OR ', $parts ) . ')';
}

/** Form (H): the WHERE fragment injected through the SQL hook. `$variant` = notin | notexists. */
function h_fragment( string $store, string $variant, array $raw_by_key ): string {
	[ $mt, $fk, $id ] = meta_tbl( $store );
	if ( 'notin' === $variant ) {
		$null_guard = 'hpos' === $store ? " AND $fk IS NOT NULL" : '';
		return "$id NOT IN (SELECT $fk FROM $mt WHERE " . disqualifier( $raw_by_key ) . "$null_guard)";
	}
	return "NOT EXISTS (SELECT 1 FROM $mt s WHERE s.$fk = $id AND " . disqualifier( $raw_by_key, 's' ) . ')';
}

/** Form (H, full): the marker SCOPE as a correlated EXISTS too, so the query carries no meta join at all. */
function h_full_fragment( string $store, array $marker_keys, array $raw_by_key ): string {
	[ $mt, $fk, $id ] = meta_tbl( $store );
	global $wpdb;
	$in = implode( ',', array_map( static fn( $k ) => $wpdb->prepare( '%s', $k ), $marker_keys ) );
	return "EXISTS (SELECT 1 FROM $mt k WHERE k.$fk = $id AND k.meta_key IN ($in)) AND " . h_fragment( $store, 'notexists', $raw_by_key );
}

/** Form (X), exclusion id set: one flat query, no join. */
function x_excl_sql( string $store, array $raw_by_key ): string {
	[ $mt, $fk ] = meta_tbl( $store );
	return "SELECT DISTINCT $fk FROM $mt WHERE " . disqualifier( $raw_by_key );
}

/** Form (X), inclusion id set: registered-marker orders that are NOT disqualified (the complement, computed in SQL). */
function x_incl_sql( string $store, array $marker_keys, array $raw_by_key ): string {
	[ $mt, $fk ] = meta_tbl( $store );
	global $wpdb;
	$in = implode( ',', array_map( static fn( $k ) => $wpdb->prepare( '%s', $k ), $marker_keys ) );
	return "SELECT DISTINCT mk.$fk FROM $mt mk WHERE mk.meta_key IN ($in) AND NOT EXISTS (SELECT 1 FROM $mt s WHERE s.$fk = mk.$fk AND " . disqualifier( $raw_by_key, 's' ) . ')';
}

// ------------------------------------------------------------------ timing ------------------------

function no_cache( string $sql ): string {
	return preg_replace( '/^\s*SELECT\s+/i', 'SELECT SQL_NO_CACHE ', ltrim( $sql ), 1 );
}

/**
 * Runs `$sql` (`1 warm-up + $n` timed) and returns median ms. Stops early on the statement timeout.
 *
 * @return array{ms:?float,timeout:bool,rows:?int,scalar:?string,runs:array<int,float>,error:?string}
 */
function timed( \mysqli $m, string $sql, int $n = 5, bool $scalar = false ): array {
	$sql   = no_cache( $sql );
	$runs  = [];
	$rows  = null;
	$val   = null;
	$out   = [ 'timeout' => false, 'error' => null ];
	for ( $i = 0; $i <= $n; $i++ ) {
		$t0 = microtime( true );
		$r  = $m->query( $sql );
		if ( false === $r ) {
			$out['timeout'] = ( 1969 === $m->errno ); // ER_STATEMENT_TIMEOUT
			$out['error']   = $m->errno . ' ' . $m->error;
			$runs[]         = ( microtime( true ) - $t0 ) * 1000;
			if ( $out['timeout'] && $i >= 1 ) {
				break; // two strikes are enough; do not burn 6 x 30 s.
			}
			if ( ! $out['timeout'] ) {
				break;
			}
			continue;
		}
		if ( $scalar ) {
			$val = $r->fetch_row()[0] ?? null;
		} else {
			$rows = $r->num_rows;
		}
		$r->free();
		$ms = ( microtime( true ) - $t0 ) * 1000;
		if ( $i > 0 ) {
			$runs[] = $ms; // run 0 is the warm-up.
		}
	}
	sort( $runs );
	$ok = ! $out['error'];
	return $out + [
		'ms'     => $ok ? $runs[ intdiv( count( $runs ), 2 ) ] : null,
		'rows'   => $rows,
		'scalar' => $val,
		'runs'   => array_map( static fn( $x ) => round( $x, 1 ), $runs ),
	];
}

/** Compact EXPLAIN: one `table:type:key:rows` token per row. */
function explain( \mysqli $m, string $sql ): string {
	$r = $m->query( 'EXPLAIN ' . no_cache( $sql ) );
	if ( false === $r ) {
		return 'EXPLAIN failed: ' . $m->error;
	}
	$out = [];
	while ( $row = $r->fetch_assoc() ) {
		$out[] = sprintf( '%s:%s:%s:%s%s', $row['table'] ?? '-', $row['type'] ?? '-', $row['key'] ?? '-', $row['rows'] ?? '-', isset( $row['Extra'] ) && $row['Extra'] ? ' [' . $row['Extra'] . ']' : '' );
	}
	return implode( ' | ', $out );
}

function join_counts( string $sql ): array {
	return [
		'left'  => preg_match_all( '/\bLEFT\s+JOIN\b/i', $sql ),
		'inner' => preg_match_all( '/\b(?:INNER\s+)?JOIN\b/i', preg_replace( '/\bLEFT\s+JOIN\b/i', '', $sql ) ),
	];
}
