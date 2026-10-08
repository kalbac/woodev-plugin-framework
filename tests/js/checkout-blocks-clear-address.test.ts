import { shouldClearAddress } from '../../src/checkout-blocks/invalidation';
import type { Selection } from '../../src/checkout-blocks/types';

const podolskSelection: Selection = { key: 'dadata:podolsk', city: 'Подольск', cityType: 'г', country: 'RU', state: null };
const kazan = { key: 'dadata:kazan', city: 'Казань', cityType: 'г' };
const podolsk = { key: 'dadata:podolsk', city: 'Подольск', cityType: 'г' };
const address = ( city: string ) => ( { city, state: '', country: 'RU' } );
const on = { clearAddressOnChange: true };

describe( 'shouldClearAddress', () => {
	it( 'never clears when the option is off or was not published', () => {
		expect( shouldClearAddress( { clearAddressOnChange: false }, null, address( 'Москва' ), kazan ) ).toBe( false );
		expect( shouldClearAddress( {}, null, address( 'Москва' ), kazan ) ).toBe( false );
		expect( shouldClearAddress( { clearAddressOnChange: false }, podolskSelection, address( 'Подольск' ), kazan ) ).toBe( false );
	} );

	it( 'clears when the pick replaces a chosen locality with another record', () => {
		expect( shouldClearAddress( on, podolskSelection, address( 'Подольск' ), kazan ) ).toBe( true );
	} );

	it( 'does not clear a re-pick of the chosen locality', () => {
		expect( shouldClearAddress( on, podolskSelection, address( 'Подольск' ), podolsk ) ).toBe( false );
	} );

	it( 'without a chosen locality it judges the native City: blank is the initial fill', () => {
		expect( shouldClearAddress( on, null, address( '' ), kazan ) ).toBe( false );
		expect( shouldClearAddress( on, null, address( '   ' ), kazan ) ).toBe( false );
	} );

	it( 'without a chosen locality, a City that names the picked place is the same place', () => {
		expect( shouldClearAddress( on, null, address( 'Подольск' ), podolsk ) ).toBe( false );
		expect( shouldClearAddress( on, null, address( 'г. Подольск' ), podolsk ) ).toBe( false );
	} );

	it( 'without a chosen locality, a City naming another place means the street is the previous city\'s', () => {
		expect( shouldClearAddress( on, null, address( 'Москва' ), kazan ) ).toBe( true );
	} );
} );
