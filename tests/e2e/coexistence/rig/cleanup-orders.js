#!/usr/bin/env node
/**
 * Deletes the orders the coexistence specs created — EXACTLY the ids they recorded in the order ledger
 * (`helpers.js` → `ORDER_LEDGER`), never a pattern match — and prints how many went. Run it after a session; the specs never
 * delete their own orders, so a failure can be inspected.
 *
 *   node tests/e2e/coexistence/rig/cleanup-orders.js [--dry-run] [--keep=1444,1445]
 *
 * `--keep` spares more orders on top of the ones the specs already marked to keep (the order exported to CDEK for real).
 * After a real run the ledger keeps only the spared ids.
 */

const fs = require( 'node:fs' );
const { ORDER_LEDGER, recordedOrders, wpEval } = require( '../helpers' );

const dry   = process.argv.includes( '--dry-run' );
const extra = ( process.argv.find( ( a ) => a.startsWith( '--keep=' ) ) || '--keep=' ).slice( 7 ).split( ',' ).filter( Boolean ).map( Number );

const { ids, keep } = recordedOrders();
const spare         = new Set( [ ...keep, ...extra ] );
const doomed        = ids.filter( ( id ) => ! spare.has( id ) );

if ( 0 === doomed.length ) {
	console.log( `nothing to delete (ledger ${ ORDER_LEDGER }: ${ ids.length } recorded, ${ spare.size } spared)` );
	process.exit( 0 );
}

console.log(
	wpEval(
		`
$ids  = ${ JSON.stringify( doomed ) };
$gone = [];
foreach ( $ids as $id ) {
	$order = wc_get_order( $id );
	if ( $order ) {
		${ dry ? '' : '$order->delete( true );' }
		$gone[] = $id;
	}
}
echo ${ dry ? "'would delete '" : "'deleted '" }, count( $gone ), ' order(s): ', implode( ',', $gone );
`
	)
);

if ( ! dry ) {
	fs.writeFileSync( ORDER_LEDGER, [ ...spare ].map( ( id ) => `keep:${ id }\n` ).join( '' ) );
}
