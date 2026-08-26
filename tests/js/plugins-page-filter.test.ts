import { filterProducts, formatPrice } from '../../src/plugins-page/filter';

describe( 'filterProducts', () => {
	const products = [
		{ title: 'Yandex Delivery', excerpt: 'Ship via Yandex', categories: [ 'delivery' ] },
		{ title: 'CDEK', excerpt: 'Ship via CDEK', categories: [ 'delivery' ] },
		{ title: 'License Manager', excerpt: 'Manage licenses', categories: [ 'core' ] },
	];

	it( 'returns everything for the "all" category and no search', () => {
		expect( filterProducts( products ) ).toHaveLength( 3 );
	} );

	it( 'filters by category slug', () => {
		const result = filterProducts( products, { category: 'core' } );
		expect( result.map( ( p ) => p.title ) ).toEqual( [ 'License Manager' ] );
	} );

	it( 'matches a case-insensitive title or excerpt search', () => {
		const result = filterProducts( products, { search: 'cdek' } );
		expect( result.map( ( p ) => p.title ) ).toEqual( [ 'CDEK' ] );
	} );

	it( 'treats a missing product list as empty', () => {
		expect( filterProducts( undefined ) ).toEqual( [] );
	} );
} );

describe( 'formatPrice', () => {
	it( 'formats an integer with ru-RU thousands separators', () => {
		// ru-RU groups with U+00A0 (non-breaking space), not a plain ASCII space.
		expect( formatPrice( 12500 ) ).toBe( '12 500' );
	} );

	it( 'treats a missing price as zero', () => {
		expect( formatPrice( undefined ) ).toBe( '0' );
	} );
} );
