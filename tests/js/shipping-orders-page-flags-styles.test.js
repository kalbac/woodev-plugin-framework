/**
 * The row flags' tones (s164) live in three places that must agree: the server's whitelist
 * (`Order_Row_Flags::TONES`), the TypeScript type, and one SCSS rule per tone. A tone the server accepts but the
 * stylesheet does not know would draw an unstyled badge; this pins the three together.
 */

const fs = require( 'fs' );
const path = require( 'path' );

const ROOT = path.resolve( __dirname, '../..' );
const read = ( file ) => fs.readFileSync( path.join( ROOT, file ), 'utf8' );

function serverTones() {
	const match = /public const TONES = \[([^\]]+)\];/.exec( read( 'woodev/shipping-method/admin/orders/class-order-row-flags.php' ) );

	return match[ 1 ].split( ',' ).map( ( tone ) => tone.trim().replace( /'/g, '' ) ).sort();
}

describe( 'row flag tones', () => {
	test( 'the SCSS has a rule for every tone the server accepts, and no other', () => {
		const scss = read( 'src/shipping-orders-page/style.scss' );
		const start = scss.indexOf( '.woodev-orders-flag {' );
		// The rule ends where the next doc comment begins.
		const block = scss.slice( start, scss.indexOf( '/**', start ) );
		const styled = [ ...block.matchAll( /&--([a-z]+)\s*\{/g ) ].map( ( m ) => m[ 1 ] ).sort();

		expect( styled ).toEqual( serverTones() );
	} );

	test( 'the icon-only form has a colour rule for every tone, from design tokens rather than hex values', () => {
		const scss = read( 'src/shipping-orders-page/style.scss' );
		const start = scss.indexOf( '.woodev-orders-flag-icon {' );
		const block = scss.slice( start, scss.indexOf( '\n}\n', start ) );
		const styled = [ ...block.matchAll( /&--([a-z]+)\s*\{\s*color:\s*([^;]+);/g ) ];

		expect( styled.map( ( m ) => m[ 1 ] ).sort() ).toEqual( serverTones() );
		styled.forEach( ( m ) => expect( m[ 2 ] ).toMatch( /^wd\.\$[a-z-]+$/ ) );
		expect( styled.find( ( m ) => 'warn' === m[ 1 ] )[ 2 ] ).toBe( 'wd.$warn' );
	} );

	test( 'the TypeScript type names the same tones', () => {
		const rest = read( 'src/shipping-orders-page/rest.ts' );
		const type = /export type OrderRowFlagTone = ([^;]+);/.exec( rest )[ 1 ];
		const tones = type.split( '|' ).map( ( tone ) => tone.trim().replace( /'/g, '' ) ).sort();

		expect( tones ).toEqual( serverTones() );
	} );

	test( 'the tones are the delivery badge\'s own five', () => {
		const delivery = read( 'src/shipping-orders-page/columns.ts' );
		const type = /export type StatusTone = ([^;]+);/.exec( delivery )[ 1 ];

		expect( type.split( '|' ).map( ( tone ) => tone.trim().replace( /'/g, '' ) ).sort() ).toEqual( serverTones() );
	} );
} );
