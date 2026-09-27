<?php
/**
 * #935 — shared helpers for the id-query narrowing measurement (forked from the #928 harness).
 *
 * Runs INSIDE the wp-env `cli` container (`wp eval-file`), so the SQL text comes from the REAL
 * WooCommerce query builders (HPOS `OrdersTableQuery`, CPT `WP_Query` via `WC_Order_Data_Store_CPT`)
 * fed by the REAL `Orders_Query::build_args()`. That SQL is captured by a filter that throws before the
 * query executes, and is then RUN through a private mysqli connection against the throwaway
 * `probe935` database — never through `$wpdb`, never against `wordpress`.
 *
 * Nothing here writes to any database other than `probe935`.
 */

namespace Woodev\Research935;

use Woodev\Framework\Shipping\Admin\Orders\Orders_Provider;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Query;
use Woodev\Framework\Shipping\Admin\Orders\Orders_Registry;
use Woodev\Framework\Shipping\Order\Delivery_Status;

const PROBE_DB    = 'probe935';
const REAL_DB     = 'wordpress';
const ALL_TABLES = [ 'wp_posts', 'wp_postmeta', 'wp_wc_orders', 'wp_wc_orders_meta', 'wp_wc_order_addresses', 'wp_wc_order_operational_data', 'wp_woocommerce_order_items', 'wp_woocommerce_order_itemmeta' ];
const STMT_LIMIT  = 30; // seconds; MariaDB's `max_statement_time` (the rig runs MariaDB — `max_execution_time` does not exist there).

final class Abort_Capture extends \RuntimeException {}

/**
 * The real `Orders_Query`, with the id-resolution seam pointed at the probe database: it compiles the tree with
 * the real `Orders_Id_Resolver::compile()` (only `$wpdb->prepare()` is used, no query reaches `wordpress`),
 * runs the SQL through the probe connection, and records what it did. `$mode` picks the id-query form:
 *   today    — the SQL as the framework compiles it now;
 *   a_exists — candidate A: order status + `date_created` window as ONE correlated EXISTS on the order table;
 *   a_join   — candidate A: the same predicates as a JOIN of the order table to the driver;
 *   a_in     — candidate A: `mk.order_id IN (SELECT id FROM orders WHERE …)`, a semi-join the optimizer costs itself;
 *   *_ns     — the same without the status list (date window only);
 *   a_drive  — candidate A: as a_join, but the order table is the DRIVER (STRAIGHT_JOIN) and the row type is pinned.
 */
final class Probe_Query extends Orders_Query {
	public static ?\mysqli $m = null;
	public static int $runs    = 3;
	public string $mode        = 'today';
	/** @var array<int,array<string,mixed>> */
	public array $id_calls = [];
	/** narrowing for the current request, set by the harness from the request: [ 'statuses' => string[], 'from' => ?string, 'to' => ?string ] */
	public array $narrow = [];
	/** true => log the tree the seam receives and answer `[]` without running the id query (candidate B needs the per-carrier trees only). */
	public bool $capture_only = false;
	private bool $hpos;

	public function __construct( ?Orders_Registry $registry, bool $hpos ) {
		parent::__construct( $registry );
		$this->hpos = $hpos;
	}

	protected function is_hpos_enabled(): bool {
		return $this->hpos;
	}

	protected function resolve_order_ids( array $marker_keys, array $meta_query ): array {
		global $wpdb;
		$resolver = new \Woodev\Framework\Shipping\Admin\Orders\Orders_Id_Resolver( $wpdb, $this->hpos );
		$sql      = $resolver->compile( $marker_keys, $meta_query );
		if ( $this->capture_only ) {
			$this->id_calls[] = [ 'keys' => $marker_keys, 'tree' => $meta_query ];
			return [];
		}
		$sql      = narrowed_id_sql( $sql, $this->mode, $this->hpos, $this->narrow );
		$t        = timed( self::$m, $sql, self::$runs );
		if ( $t['error'] ) {
			throw new \RuntimeException( 'id query failed: ' . $t['error'] . "\n" . substr( $sql, 0, 600 ) );
		}
		$ids = [];
		$r   = self::$m->query( $sql );
		while ( $row = $r->fetch_row() ) {
			$ids[] = (int) $row[0];
		}
		$this->id_calls[] = [ 'ms' => $t['ms'], 'n' => count( $ids ), 'bytes' => strlen( implode( ',', $ids ) ), 'sql_len' => strlen( $sql ), 'keys' => $marker_keys, 'tree' => $meta_query, 'sql' => $sql ];
		return $ids;
	}
}

/**
 * Candidate A, harness form: rewrites the compiled id query so the order table narrows the driver by status and
 * created-date. The date window is the request's `after`/`before` widened by one day on each side (UTC column, site
 * timezone offsets are < 1 day) — a SUPERSET on purpose: the main query re-applies WooCommerce's exact `date_created`
 * semantics, so the page is unchanged and only the id list shrinks.
 */
function narrowed_id_sql( string $sql, string $mode, bool $hpos, array $narrow ): string {
	if ( 'today' === $mode || [] === $narrow ) {
		return $sql;
	}
	$o   = $hpos ? [ 'wp_wc_orders', 'id', 'status', 'date_created_gmt' ] : [ 'wp_posts', 'ID', 'post_status', 'post_date' ];
	$pre = [];
	if ( ! empty( $narrow['statuses'] ) && ! in_array( $mode, [ 'a_drive_ns', 'a_in_ns' ], true ) ) {
		$pre[] = "o.{$o[2]} IN ('" . implode( "','", array_map( 'esc_sql', $narrow['statuses'] ) ) . "')";
	}
	if ( ! empty( $narrow['from'] ) ) {
		$pre[] = "o.{$o[3]} >= '" . $narrow['from'] . " 00:00:00'";
	}
	if ( ! empty( $narrow['to'] ) ) {
		$pre[] = "o.{$o[3]} <= '" . $narrow['to'] . " 23:59:59'";
	}
	if ( [] === $pre ) {
		return $sql;
	}
	$idc = $hpos ? 'order_id' : 'post_id';
	if ( 'a_exists' === $mode ) {
		return $sql . " AND EXISTS (SELECT 1 FROM {$o[0]} AS o WHERE o.{$o[1]} = mk.{$idc} AND " . implode( ' AND ', $pre ) . ')';
	}
	if ( in_array( $mode, [ 'a_in', 'a_in_ns' ], true ) ) {
		// A semi-join: the optimizer picks the driver by its own cost, so a wide window should not be forced onto the slow plan.
		$type = $hpos ? 'o.type IN (\'shop_order\')' : 'o.post_type IN (\'shop_order\')';
		return $sql . " AND mk.{$idc} IN (SELECT o.{$o[1]} FROM {$o[0]} AS o WHERE {$type} AND " . implode( ' AND ', $pre ) . ')';
	}
	if ( in_array( $mode, [ 'a_drive', 'a_drive_ns' ], true ) ) {
		// The order table DRIVES: date/status range on its own index first, then a keyed probe into the meta table per order.
		$type = $hpos ? 'o.type IN (\'shop_order\')' : 'o.post_type IN (\'shop_order\')';
		$meta = $hpos ? 'wp_wc_orders_meta' : 'wp_postmeta';
		return str_replace( " FROM {$meta} AS mk WHERE ", " FROM {$o[0]} AS o STRAIGHT_JOIN {$meta} AS mk ON mk.{$idc} = o.{$o[1]} WHERE ", $sql ) . " AND {$type} AND " . implode( ' AND ', $pre );
	}
	// a_join
	return preg_replace( '/ AS mk WHERE /', " AS mk JOIN {$o[0]} AS o ON o.{$o[1]} = mk.{$idc} WHERE ", $sql, 1 ) . ' AND ' . implode( ' AND ', $pre );
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

/** Copies the eight tables' DDL from the real rig DB (SHOW CREATE TABLE, read-only) into probe935, and dumps it. */
function create_schema( \mysqli $m, string $dump_to ): void {
	q( $m, 'CREATE DATABASE IF NOT EXISTS ' . PROBE_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci' );
	q( $m, 'USE ' . PROBE_DB );
	$ddl_all = '';
	foreach ( ALL_TABLES as $t ) {
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
	foreach ( ALL_TABLES as $t ) {
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

	$posts = $orders = $pmeta = $ometa = $addr = [];
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
	multi_insert( $m, 'wp_wc_order_addresses', 'order_id,address_type,first_name,last_name,email', $addr );
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
	foreach ( ALL_TABLES as $t ) {
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
