<?php
/**
 * Pure-PHP harness: boots just enough of WordPress + the framework to drive
 * Orders_Query::build_args() and to turn its meta_query into REAL SQL through the
 * real WP_Meta_Query, with a stub $wpdb. NOTHING is ever executed against MySQL.
 */

// Overridable so the harness runs from any checkout or worktree; the defaults are
// this machine's primary checkout and its wp-env WordPress copy.
define( 'WOODEV_839_REPO', getenv( 'WOODEV_839_REPO' ) ?: '/Users/maksimmartirosov/Projects/woodev-plugin-framework' );
define( 'WOODEV_839_WP_CORE', getenv( 'WOODEV_839_WP_CORE' ) ?: '/Users/maksimmartirosov/.wp-env/wp-env-woodev-plugin-framework-5fd870b7/WordPress' );

const REPO     = WOODEV_839_REPO;
const WP_CORE  = WOODEV_839_WP_CORE;

define( 'ABSPATH', WP_CORE . '/' );

// WordPress core time constants (no WordPress loaded), as tests/bootstrap.php defines them.
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );
define( 'WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS );
define( 'MONTH_IN_SECONDS', 30 * DAY_IN_SECONDS );
define( 'YEAR_IN_SECONDS', 365 * DAY_IN_SECONDS );

// ---------- WP-ish functions the framework and WP_Meta_Query need ----------

function apply_filters( $tag, $value = null ) { return $value; }
function apply_filters_ref_array( $tag, $args ) { return $args[0]; }
function add_action() {}
function add_filter() {}
function remove_action() {}
function remove_filter() {}
function do_action() {}
function __( $t, $d = null ) { return $t; }
function _x( $t, $c, $d = null ) { return $t; }
function esc_html( $t ) { return $t; }
function esc_html__( $t, $d = null ) { return $t; }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function absint( $v ) { return abs( (int) $v ); }
function is_serialized( $d, $strict = true ) { return false; }
function _get_meta_table( $type ) { return 'post' === $type ? 'wp_postmeta' : false; }
function get_option( $n, $d = false ) { return $d; }

function wc_get_order_types( $for = '' ) { return [ 'shop_order' ]; }
function wc_get_order_statuses() {
	return [
		'wc-pending'    => 'Pending',
		'wc-processing' => 'Processing',
		'wc-on-hold'    => 'On hold',
		'wc-completed'  => 'Completed',
		'wc-cancelled'  => 'Cancelled',
		'wc-failed'     => 'Failed',
	];
}
function wc_string_to_bool( $v ) { return is_bool( $v ) ? $v : in_array( $v, [ 'yes', 1, '1', 'true' ], true ); }

// Minimal $wpdb: only what WP_Meta_Query touches.
class Woodev_Probe_Wpdb {
	public $postmeta = 'wp_postmeta';
	public $posts    = 'wp_posts';
	public function prepare( $query, ...$args ) {
		if ( [] === $args ) { return $query; }
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$query = str_replace( [ '%s', '%d', '%f' ], [ "'%s'", '%d', '%f' ], $query );
		return vsprintf( $query, array_map( static function ( $a ) { return is_string( $a ) ? addslashes( $a ) : $a; }, $args ) );
	}
	public function esc_like( $t ) { return addcslashes( (string) $t, '_%\\' ); }
	public function get_blog_prefix() { return 'wp_'; }
}
$GLOBALS['wpdb'] = new Woodev_Probe_Wpdb();

require_once WP_CORE . '/wp-includes/class-wp-meta-query.php';
require_once REPO . '/vendor/autoload.php';

// ---------- measurement helpers ----------

/** Counts LEAF clauses (each carries a `key`) in a meta_query tree. */
function leaf_count( array $tree ): int {
	$n = 0;
	foreach ( $tree as $k => $v ) {
		if ( 'relation' === $k ) { continue; }
		if ( ! is_array( $v ) ) { continue; }
		$n += isset( $v['key'] ) ? 1 : leaf_count( $v );
	}
	return $n;
}

/** Real LEFT JOIN count, produced by the real WP_Meta_Query. Never executed. */
function join_sql( array $meta_query ): array {
	$mq = new WP_Meta_Query( $meta_query );
	$sql = $mq->get_sql( 'post', 'wp_posts', 'ID', null );
	$joins = preg_match_all( '/\bLEFT\s+JOIN\b/i', $sql['join'] );
	$inner = preg_match_all( '/\bINNER\s+JOIN\b/i', $sql['join'] );
	return [ 'left' => $joins, 'inner' => $inner, 'total' => $joins + $inner, 'sql' => $sql ];
}
