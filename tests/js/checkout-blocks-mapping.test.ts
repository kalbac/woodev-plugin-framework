import { matchState, normalizeCity, normalizeRegionName, recordCity, resolveNativeAddress } from '../../src/checkout-blocks/mapping';
import { isSelectionStale } from '../../src/checkout-blocks/invalidation';
import type { LocationRecord } from '../../src/checkout-blocks/types';

const record = ( over: Partial< LocationRecord > = {} ): LocationRecord => ( {
	key: 'dadata:1',
	provider_id: 'dadata',
	level: 'settlement',
	country: 'RU',
	...over,
} );

// What WooCommerce's `countryData.RU.states` looks like after the related-list injection: the code is
// the upper-cased label, the label is the human text.
const RU_STATES = {
	'МОСКВА': 'Москва',
	'МОСКОВСКАЯ ОБЛАСТЬ': 'Московская область',
	'САНКТ-ПЕТЕРБУРГ': 'Санкт-Петербург',
	'ЛЕНИНГРАДСКАЯ ОБЛАСТЬ': 'Ленинградская область',
};

describe( 'normalizeRegionName', () => {
	it( 'folds case, ё and administrative type words', () => {
		expect( normalizeRegionName( 'Московская область' ) ).toBe( 'московская' );
		expect( normalizeRegionName( 'Московская обл.' ) ).toBe( 'московская' );
		expect( normalizeRegionName( 'Республика Алтай' ) ).toBe( 'алтай' );
		expect( normalizeRegionName( 'Орёл' ) ).toBe( 'орел' );
	} );
} );

describe( 'recordCity', () => {
	it( 'is the settlement bare name, never the display label', () => {
		expect( recordCity( record( { settlement: { name: 'Подольск', type: 'г' }, label: 'г Подольск, Московская обл' } ) ) ).toBe( 'Подольск' );
	} );

	it( 'falls back to the region name for a federal city published without a settlement', () => {
		expect( recordCity( record( { region: { name: 'Москва', type: 'г' } } ) ) ).toBe( 'Москва' );
	} );

	it( 'is empty when the record names nothing', () => {
		expect( recordCity( record() ) ).toBe( '' );
	} );
} );

describe( 'matchState', () => {
	it( 'matches a real region by label, to the real WC code', () => {
		const code = matchState( record( { region: { name: 'Московская область' }, settlement: { name: 'Подольск' } } ), RU_STATES );

		expect( code ).toBe( 'МОСКОВСКАЯ ОБЛАСТЬ' );
	} );

	it( 'matches a native ISO-coded list by label', () => {
		const states = { MOW: 'Moscow', MOS: 'Moscow Oblast' };

		expect( matchState( record( { region: { name: 'Moscow Oblast' } } ), states ) ).toBe( 'MOS' );
	} );

	it( 'does not take a homonymous city for a region when the record carries a region', () => {
		// A town called like another region, standing in Moscow Oblast: the region decides.
		const code = matchState( record( { region: { name: 'Московская область' }, settlement: { name: 'Ленинградская' } } ), RU_STATES );

		expect( code ).toBe( 'МОСКОВСКАЯ ОБЛАСТЬ' );
	} );

	it( 'matches a city of federal significance whose region and settlement are one entity (same key)', () => {
		const viaRegion = record( { key: 'dadata:77', region: { name: 'Москва', type: 'г' }, settlement: { name: 'Москва', type: 'г' } } );
		const viaSettlementOnly = record( { key: 'dadata:77', settlement: { name: 'Санкт-Петербург', type: 'г' } } );

		expect( matchState( viaRegion, RU_STATES ) ).toBe( 'МОСКВА' );
		expect( matchState( viaSettlementOnly, RU_STATES ) ).toBe( 'САНКТ-ПЕТЕРБУРГ' );
	} );

	it( 'prefers the exact name over the type-stripped one', () => {
		const states = { A1: 'Алтай', A2: 'Республика Алтай' };

		expect( matchState( record( { region: { name: 'Алтай' } } ), states ) ).toBe( 'A1' );
	} );

	it( 'refuses to guess when two options only match once the type words are dropped', () => {
		const states = { A1: 'Республика Алтай', A2: 'Алтай край' };

		expect( matchState( record( { region: { name: 'Алтай' } } ), states ) ).toBeNull();
	} );

	it( 'answers null when nothing matches', () => {
		expect( matchState( record( { region: { name: 'Нигдеевская область' } } ), RU_STATES ) ).toBeNull();
	} );
} );

describe( 'resolveNativeAddress', () => {
	const podolsk = record( { region: { name: 'Московская область' }, settlement: { name: 'Подольск' } } );

	it( 'writes the city and a real WC state code', () => {
		expect( resolveNativeAddress( podolsk, { states: RU_STATES, regionFieldRemoved: false } ) ).toEqual( {
			city: 'Подольск',
			state: 'МОСКОВСКАЯ ОБЛАСТЬ',
			stateMatched: true,
		} );
	} );

	it( 'leaves the state untouched when the region field is removed', () => {
		expect( resolveNativeAddress( podolsk, { states: RU_STATES, regionFieldRemoved: true } ) ).toEqual( {
			city: 'Подольск',
			state: null,
			stateMatched: true,
		} );
	} );

	it( 'clears the state for a country with no state list, without calling it a miss', () => {
		expect( resolveNativeAddress( podolsk, { states: {}, regionFieldRemoved: false } ) ).toEqual( {
			city: 'Подольск',
			state: '',
			stateMatched: true,
		} );
	} );

	it( 'never writes an invalid state: an unmatched region clears it and says so', () => {
		const stranger = record( { region: { name: 'Нигдеевская область' }, settlement: { name: 'Нигдеевск' } } );

		expect( resolveNativeAddress( stranger, { states: RU_STATES, regionFieldRemoved: false } ) ).toEqual( {
			city: 'Нигдеевск',
			state: '',
			stateMatched: false,
		} );
	} );

	it( 'maps Moscow to Moscow for city AND state (same-key federal city)', () => {
		const moscow = record( { key: 'dadata:77', region: { name: 'Москва', type: 'г' }, settlement: { name: 'Москва', type: 'г' } } );

		expect( resolveNativeAddress( moscow, { states: RU_STATES, regionFieldRemoved: false } ) ).toEqual( {
			city: 'Москва',
			state: 'МОСКВА',
			stateMatched: true,
		} );
	} );
} );

describe( 'isSelectionStale (manual-edit invalidation)', () => {
	const selection = { key: 'dadata:1', city: 'Подольск', country: 'RU' };

	it( 'keeps a selection while the address still says what it wrote', () => {
		expect( isSelectionStale( selection, { city: 'Подольск', country: 'RU' } ) ).toBe( false );
	} );

	it( 'compares by value — case, ё and spacing are not an edit', () => {
		expect( isSelectionStale( selection, { city: '  подольск ', country: 'ru' } ) ).toBe( false );
		expect( isSelectionStale( { ...selection, city: 'Орёл' }, { city: 'орел', country: 'RU' } ) ).toBe( false );
		expect( normalizeCity( 'Нижний   Новгород' ) ).toBe( 'нижний новгород' );
	} );

	it( 'drops the selection when the shopper edits the city by hand', () => {
		expect( isSelectionStale( selection, { city: 'Подольс', country: 'RU' } ) ).toBe( true );
		expect( isSelectionStale( selection, { city: '', country: 'RU' } ) ).toBe( true );
	} );

	it( 'drops the selection when the country changes', () => {
		expect( isSelectionStale( selection, { city: 'Подольск', country: 'KZ' } ) ).toBe( true );
	} );
} );
