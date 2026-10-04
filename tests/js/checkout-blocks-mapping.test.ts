import {
	matchState,
	namesCity,
	normalizeCity,
	normalizeCityName,
	normalizeRegionName,
	recordCity,
	recordCityComponent,
	resolveNativeAddress,
} from '../../src/checkout-blocks/mapping';
import { isSelectionStale, judgeSavedRecord, selectionFor } from '../../src/checkout-blocks/invalidation';
import type { LocationRecord, Selection } from '../../src/checkout-blocks/types';

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

/*
 * The name contract — the SAME table as `name_contract_cases()` in
 * `tests/unit/Shipping/Location/LocationServiceTest.php`: [ record name, record type, native city, names? ].
 * What the server keeps the chooser keeps, and what the server drops the chooser drops.
 */
const NAME_CASES: Array< [ string, string, string, string, boolean ] > = [
	// The record's OWN type is a prefix the shopper's text may carry.
	[ 'own type: рп Мостовской', 'Мостовской', 'рп', 'рп Мостовской', true ],
	[ 'own type with a dot', 'Мостовской', 'рп', 'рп. Мостовской', true ],
	[ 'own type: аул Панахес', 'Панахес', 'аул', 'аул Панахес', true ],
	[ 'common type, upper case', 'Подольск', 'г', 'г. ПОДОЛЬСК', true ],
	[ 'ё in the record, е in the form', 'Орёл', 'г', 'Орел', true ],
	[ 'е in the record, ё in the form', 'Орел', 'г', 'Орёл', true ],
	[ 'bare name against a typed one', 'Москва', 'г', 'г. Москва', true ],
	[ 'a record with no type at all', 'Москва', '', 'г. Москва', true ],
	// Another settlement's type is not this record's prefix.
	[ 'a type the record does not carry', 'Мостовской', 'г', 'рп Мостовской', false ],
	// Never guessed: a spelling alias or a transliteration is a different string.
	[ 'spelling alias', 'Санкт-Петербург', 'г', 'Санкт Петербург', false ],
	[ 'transliteration', 'Москва', 'г', 'Moscow', false ],
	[ 'another city', 'Подольск', 'г', 'Казань', false ],
];

describe( 'the record-aware name contract (normalizeCityName / namesCity)', () => {
	it.each( NAME_CASES )( '%s', ( _label, name, type, city, names ) => {
		expect( namesCity( name, type, city ) ).toBe( names );
	} );

	it( 'normalizes exactly as Location_Record::normalize_city_name() does', () => {
		expect( normalizeCityName( '  г.Подольск ' ) ).toBe( 'подольск' );
		expect( normalizeCityName( 'город  Орёл' ) ).toBe( 'орел' );
		expect( normalizeCityName( 'ст-ца Каневская' ) ).toBe( 'каневская' );
		// ONE leading type word, and only when a name remains behind it.
		expect( normalizeCityName( 'г. п. Бутово' ) ).toBe( 'п. бутово' );
		expect( normalizeCityName( 'Село' ) ).toBe( 'село' );
		expect( normalizeCityName( 'г.' ) ).toBe( 'г.' );
		// The record's own type, dots and case trimmed, goes first.
		expect( normalizeCityName( 'РП Мостовской', ' рп. ' ) ).toBe( 'мостовской' );
		// A type word is a prefix, never a part of a longer word.
		expect( normalizeCityName( 'Горки' ) ).toBe( 'горки' );
	} );

	it( 'reads the type from the component the city is read from', () => {
		expect( recordCityComponent( record( { settlement: { name: 'Мостовской', type: 'рп' }, region: { name: 'Краснодарский', type: 'край' } } ) ) ).toEqual( { name: 'Мостовской', type: 'рп' } );
		expect( recordCityComponent( record( { region: { name: 'Москва', type: 'г' } } ) ) ).toEqual( { name: 'Москва', type: 'г' } );
		expect( recordCityComponent( record() ) ).toEqual( { name: '', type: '' } );
	} );
} );

describe( 'isSelectionStale (manual-edit invalidation)', () => {
	const selection: Selection = { key: 'dadata:1', city: 'Подольск', cityType: 'г', country: 'RU', state: null };
	const vouched: Selection = { ...selection, state: 'МОСКОВСКАЯ ОБЛАСТЬ' };

	it( 'keeps a selection while the address still says what it wrote', () => {
		expect( isSelectionStale( selection, { city: 'Подольск', state: '', country: 'RU' } ) ).toBe( false );
	} );

	it( 'compares by value — case, ё and spacing are not an edit', () => {
		expect( isSelectionStale( selection, { city: '  подольск ', state: '', country: 'ru' } ) ).toBe( false );
		expect( isSelectionStale( { ...selection, city: 'Орёл' }, { city: 'орел', state: '', country: 'RU' } ) ).toBe( false );
		expect( normalizeCity( 'Нижний   Новгород' ) ).toBe( 'нижний новгород' );
	} );

	it.each( NAME_CASES )( 'judges the city by the name contract — %s', ( _label, name, type, city, names ) => {
		expect( isSelectionStale( { ...selection, city: name, cityType: type }, { city, state: '', country: 'RU' } ) ).toBe( ! names );
	} );

	it( 'drops the selection when the shopper edits the city by hand', () => {
		expect( isSelectionStale( selection, { city: 'Подольс', state: '', country: 'RU' } ) ).toBe( true );
		expect( isSelectionStale( selection, { city: '', state: '', country: 'RU' } ) ).toBe( true );
	} );

	it( 'drops the selection when the country changes', () => {
		expect( isSelectionStale( selection, { city: 'Подольск', state: '', country: 'KZ' } ) ).toBe( true );
	} );

	it( 'drops the selection when the State names ANOTHER region than the record stands for', () => {
		expect( isSelectionStale( vouched, { city: 'Подольск', state: 'МОСКВА', country: 'RU' } ) ).toBe( true );
		expect( isSelectionStale( vouched, { city: 'Подольск', state: 'МОСКОВСКАЯ ОБЛАСТЬ', country: 'RU' } ) ).toBe( false );
	} );

	it( 'does not judge by a blank State, nor by a region it cannot vouch for', () => {
		expect( isSelectionStale( vouched, { city: 'Подольск', state: '', country: 'RU' } ) ).toBe( false );
		expect( isSelectionStale( selection, { city: 'Подольск', state: 'МОСКВА', country: 'RU' } ) ).toBe( false );
	} );
} );

describe( 'selectionFor / judgeSavedRecord (hydrating the server-held locality)', () => {
	const podolsk = record( { key: 'dadata:podolsk', region: { name: 'Московская область' }, settlement: { name: 'Подольск', type: 'г' } } );
	const context = { states: RU_STATES, regionFieldRemoved: false, authoritative: true };

	it( 'derives the native State the record stands for from the supplied state list', () => {
		expect( selectionFor( podolsk, context ) ).toEqual( {
			key: 'dadata:podolsk',
			city: 'Подольск',
			cityType: 'г',
			country: 'RU',
			state: 'МОСКОВСКАЯ ОБЛАСТЬ',
		} );
		expect( selectionFor( podolsk, { ...context, states: {} } ).state ).toBeNull();
		expect( selectionFor( podolsk, { ...context, regionFieldRemoved: true } ).state ).toBeNull();
	} );

	it( 'claims a record the address names — city, region and country', () => {
		expect( judgeSavedRecord( podolsk, { city: 'г. ПОДОЛЬСК', state: 'МОСКОВСКАЯ ОБЛАСТЬ', country: 'RU' }, context ).status ).toBe( 'claimed' );
		expect( judgeSavedRecord( podolsk, { city: 'Подольск', state: '', country: 'ru' }, context ).status ).toBe( 'claimed' );
	} );

	it( 'orphans a record the address names another place than', () => {
		expect( judgeSavedRecord( podolsk, { city: 'Казань', state: '', country: 'RU' }, context ).status ).toBe( 'orphaned' );
		expect( judgeSavedRecord( podolsk, { city: 'Подольск', state: 'МОСКВА', country: 'RU' }, context ).status ).toBe( 'orphaned' );
		expect( judgeSavedRecord( podolsk, { city: 'Подольск', state: '', country: 'KZ' }, context ).status ).toBe( 'orphaned' );
	} );

	it( 'leaves a record undecided while the address cannot disagree', () => {
		// The cart has not loaded: the store still holds its empty defaults.
		expect( judgeSavedRecord( podolsk, { city: '', state: '', country: '' }, { ...context, authoritative: false } ).status ).toBe( 'pending' );
		expect( judgeSavedRecord( podolsk, { city: 'Казань', state: '', country: 'RU' }, { ...context, authoritative: false } ).status ).toBe( 'pending' );
		// A blank City names no place.
		expect( judgeSavedRecord( podolsk, { city: '  ', state: 'МОСКВА', country: 'RU' }, context ).status ).toBe( 'pending' );
		// A record with no name cannot be shown to disagree.
		expect( judgeSavedRecord( record(), { city: 'Казань', state: '', country: 'RU' }, context ).status ).toBe( 'pending' );
	} );
} );
