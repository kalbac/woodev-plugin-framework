#!/usr/bin/env node
/**
 * Deletes the orders the coexistence specs create — every order whose billing e-mail is `y15*@example.com` — and prints how
 * many went. Run it after a session; the specs never delete their own orders, so a failure can be inspected.
 *
 *   node tests/e2e/coexistence/rig/cleanup-orders.js [--dry-run] [--keep=1444,1445]
 *
 * `--keep` spares orders you want to keep — e.g. the one that was exported to CDEK for real.
 */

const { wpEval } = require( '../helpers' );

const dry  = process.argv.includes( '--dry-run' );
const keep = ( process.argv.find( ( a ) => a.startsWith( '--keep=' ) ) || '--keep=' ).slice( 7 ).split( ',' ).filter( Boolean ).map( Number );

console.log(
	wpEval(
		`
$keep = ${ JSON.stringify( keep ) };
$ids  = wc_get_orders( [ 'limit' => -1, 'return' => 'ids', 'status' => array_keys( wc_get_order_statuses() ) ] );
$ids  = array_filter( $ids, static fn( $id ) => ! in_array( (int) $id, $keep, true ) && 1 === preg_match( '/^y15.*@example\\.com$/', (string) wc_get_order( $id )->get_billing_email() ) );
${ dry ? '' : 'foreach ( $ids as $id ) { wc_get_order( $id )->delete( true ); }' }
echo ${ dry ? "'would delete '" : "'deleted '" }, count( $ids ), ' order(s): ', implode( ',', $ids );
`
	)
);
