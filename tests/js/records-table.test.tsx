import '@testing-library/jest-dom';
import { useState } from '@wordpress/element';
import { render, screen, fireEvent, within, act, waitFor } from '@testing-library/react';
import RecordsTable, { getRowError, parseRecordRows, validateRecordsValue, type RecordColumn } from '../../src/components/records-table';
import ControlField from '../../src/components/control-field';
import { validateField } from '../../src/components/validate';
import apiFetch from '@wordpress/api-fetch';

let mockUseRealPicker = false;
jest.mock( '@wordpress/api-fetch' );

// The real picker searches over REST; the address cell only needs "a record gets picked".
jest.mock( '../../src/components/location-picker-field', () => ( {
	__esModule: true,
	...jest.requireActual( '../../src/components/location-picker-field' ),
	default: ( props: { value: string; level: string; country?: string; provider?: string; providerPinned?: boolean; disabled?: boolean; onChange: ( v: string ) => void } ) => {
		if ( mockUseRealPicker ) {
			const RealPicker = jest.requireActual( '../../src/components/location-picker-field' ).default;
			return <RealPicker { ...props } />;
		}
		const { value, level, country, provider, providerPinned, disabled, onChange } = props;
		return (
			<button type="button" data-level={ level } data-country={ country } data-provider={ provider } data-pinned={ String( !! providerPinned ) } disabled={ disabled } onClick={ () => onChange( JSON.stringify( { key: 'k-new', label: 'г Подольск, ул Ленина, д 1', ...( country ? { country } : {} ) } ) ) }>
				{ 'pick address' + ( value ? ' (set)' : '' ) }
			</button>
		);
	},
} ) );

const COLUMNS: RecordColumn[] = [
	{ id: 'name', type: 'text', label: 'Название', required: true },
	{ id: 'address', type: 'location-picker', label: 'Адрес', required: true, level: 'address' },
	{ id: 'is_main', type: 'main-radio', label: 'Основной' },
];

const place = ( label: string ) => JSON.stringify( { key: 'k-' + label, label } );
const stored = ( ...rows: Array<Record<string, unknown>> ) => JSON.stringify( rows );

function Harness( { initial = '', onValue = jest.fn(), columns = COLUMNS }: { initial?: string; onValue?: ( v: string ) => void; columns?: RecordColumn[] } ) {
	const [ value, setValue ] = useState( initial );
	return (
		<>
			<RecordsTable
				value={ value }
				columns={ columns }
				name="warehouses"
				addLabel="Добавить склад"
				emptyText="Складов пока нет."
				onChange={ ( next ) => { setValue( next ); onValue( next ); } }
			/>
			<output data-testid="value">{ value }</output>
		</>
	);
}

const saved = (): Array<Record<string, unknown>> => JSON.parse( screen.getByTestId( 'value' ).textContent || '[]' );
const fillName = ( text: string ) => fireEvent.change( screen.getByRole( 'textbox', { name: 'Название' } ), { target: { value: text } } );

test( 'an empty table says so and adds the first row as the main one', () => {
	render( <Harness /> );
	expect( screen.getByText( 'Складов пока нет.' ) ).toBeInTheDocument();

	fireEvent.click( screen.getByRole( 'button', { name: 'Добавить склад' } ) );
	fillName( 'Основной склад' );
	fireEvent.click( screen.getByRole( 'button', { name: 'pick address' } ) );
	fireEvent.click( screen.getByRole( 'button', { name: 'Сохранить' } ) );

	expect( saved() ).toEqual( [ { name: 'Основной склад', address: JSON.stringify( { key: 'k-new', label: 'г Подольск, ул Ленина, д 1' } ), is_main: true } ] );
	expect( screen.getByRole( 'radio', { name: 'Основной, 1' } ) ).toBeChecked();
} );

test( 'the address cell is a location picker at the declared level', () => {
	render( <Harness /> );
	fireEvent.click( screen.getByRole( 'button', { name: 'Добавить склад' } ) );

	expect( screen.getByRole( 'button', { name: 'pick address' } ) ).toHaveAttribute( 'data-level', 'address' );
} );

test( 'an address column with a provider hands it to the picker as pinned (#1603)', () => {
	const pinned = COLUMNS.map( ( column ) => ( 'address' === column.id ? { ...column, provider: 'dadata' } : column ) );
	render( <Harness columns={ pinned } /> );
	fireEvent.click( screen.getByRole( 'button', { name: 'Добавить склад' } ) );

	const picker = screen.getByRole( 'button', { name: 'pick address' } );
	expect( picker ).toHaveAttribute( 'data-provider', 'dadata' );
	expect( picker ).toHaveAttribute( 'data-pinned', 'true' );
} );

test( 'an address column without a provider keeps the picker unpinned', () => {
	render( <Harness /> );
	fireEvent.click( screen.getByRole( 'button', { name: 'Добавить склад' } ) );

	const picker = screen.getByRole( 'button', { name: 'pick address' } );
	expect( picker ).toHaveAttribute( 'data-provider', '' );
	expect( picker ).toHaveAttribute( 'data-pinned', 'false' );
} );

test( 'a row with an empty required cell is not saved and says what is missing', () => {
	render( <Harness /> );
	fireEvent.click( screen.getByRole( 'button', { name: 'Добавить склад' } ) );
	fireEvent.click( screen.getByRole( 'button', { name: 'Сохранить' } ) );

	expect( screen.getByRole( 'alert' ) ).toHaveTextContent( 'Заполните «Название».' );
	expect( saved() ).toEqual( [] );

	fillName( 'Склад' );
	fireEvent.click( screen.getByRole( 'button', { name: 'Сохранить' } ) );
	expect( screen.getByRole( 'alert' ) ).toHaveTextContent( 'Выберите «Адрес» из подсказок.' );
} );

test( 'editing a row changes it in place and keeps the keys no column declares', () => {
	render( <Harness initial={ stored( { id: 7, name: 'Старое', address: place( 'г Москва' ), is_main: true } ) } /> );
	fireEvent.click( screen.getByRole( 'button', { name: 'Изменить, 1' } ) );
	fillName( 'Новое' );
	fireEvent.click( screen.getByRole( 'button', { name: 'Сохранить' } ) );

	expect( saved() ).toEqual( [ { id: 7, name: 'Новое', address: place( 'г Москва' ), is_main: true } ] );
	expect( screen.queryByRole( 'group' ) ).toBeNull();
} );

test( 'cancel drops the draft', () => {
	render( <Harness initial={ stored( { name: 'Склад', address: place( 'г Москва' ), is_main: true } ) } /> );
	fireEvent.click( screen.getByRole( 'button', { name: 'Изменить, 1' } ) );
	fillName( 'Не сохранять' );
	fireEvent.click( screen.getByRole( 'button', { name: 'Отмена' } ) );

	expect( saved()[ 0 ].name ).toBe( 'Склад' );
} );

test( 'exactly one row is main: the radio moves it and a saved «основной» row takes it over', () => {
	render( <Harness initial={ stored(
		{ name: 'A', address: place( 'г Москва' ), is_main: true },
		{ name: 'B', address: place( 'г Тула' ), is_main: false }
	) } /> );

	fireEvent.click( screen.getByRole( 'radio', { name: 'Основной, 2' } ) );
	expect( saved().map( ( row ) => row.is_main ) ).toEqual( [ false, true ] );

	fireEvent.click( screen.getByRole( 'button', { name: 'Изменить, 1' } ) );
	fireEvent.click( screen.getByRole( 'checkbox', { name: 'Основной' } ) );
	fireEvent.click( screen.getByRole( 'button', { name: 'Сохранить' } ) );
	expect( saved().map( ( row ) => row.is_main ) ).toEqual( [ true, false ] );
} );

test( 'the main row cannot be deleted while others remain, the last row can', () => {
	render( <Harness initial={ stored(
		{ name: 'A', address: place( 'г Москва' ), is_main: true },
		{ name: 'B', address: place( 'г Тула' ), is_main: false }
	) } /> );

	expect( screen.getByRole( 'button', { name: 'Удалить, 1' } ) ).toBeDisabled();
	fireEvent.click( screen.getByRole( 'button', { name: 'Удалить, 2' } ) );
	expect( saved() ).toHaveLength( 1 );

	fireEvent.click( screen.getByRole( 'button', { name: 'Удалить, 1' } ) );
	expect( screen.getByTestId( 'value' ) ).toBeEmptyDOMElement();
	expect( screen.getByText( 'Складов пока нет.' ) ).toBeInTheDocument();
} );

test( 'a disabled table cannot be changed', () => {
	render( <RecordsTable value={ stored( { name: 'A', address: place( 'г Москва' ), is_main: true } ) } columns={ COLUMNS } name="w" disabled onChange={ jest.fn() } /> );

	screen.getAllByRole( 'button' ).forEach( ( button ) => expect( button ).toBeDisabled() );
	expect( screen.getByRole( 'radio' ) ).toBeDisabled();
} );

test( 'the table shows a stored address by its label', () => {
	render( <RecordsTable value={ stored( { name: 'A', address: place( 'г Москва, Цветной б-р' ), is_main: true } ) } columns={ COLUMNS } name="w" onChange={ jest.fn() } /> );

	expect( within( screen.getByRole( 'table' ) ).getByText( 'г Москва, Цветной б-р' ) ).toBeInTheDocument();
} );

test( 'unreadable stored values read as no rows, never as a crash', () => {
	expect( parseRecordRows( 'not json' ) ).toEqual( [] );
	expect( parseRecordRows( '{"a":1}' ) ).toEqual( [] );
	expect( parseRecordRows( '[1]' ) ).toEqual( [] );
	expect( parseRecordRows( null ) ).toEqual( [] );
} );

test( 'row and table validation mirror the server rules', () => {
	expect( getRowError( COLUMNS, { name: '', address: place( 'x' ) } ) ).toBe( 'Заполните «Название».' );
	expect( getRowError( COLUMNS, { name: 'A', address: 'plain text' } ) ).toBe( 'Выберите «Адрес» из подсказок.' );
	expect( getRowError( COLUMNS, { name: 'A', address: place( 'x' ) } ) ).toBeNull();

	const ok = { name: 'A', address: place( 'x' ) };
	expect( validateRecordsValue( '', COLUMNS ) ).toBeNull();
	expect( validateRecordsValue( stored( { ...ok, is_main: true } ), COLUMNS ) ).toBeNull();
	expect( validateRecordsValue( stored( ok ), COLUMNS ) ).toBe( 'Отметьте ровно одну строку в колонке «Основной».' );
	expect( validateRecordsValue( stored( { ...ok, is_main: true }, { name: '', address: '', is_main: false } ), COLUMNS ) ).toBe( 'Строка 2: Заполните «Название».' );
} );

test( 'ControlField renders the control from the schema and blocks Save on an invalid table', () => {
	const schema = { controlType: 'records-table', name: 'Склады', columns: COLUMNS, add_label: 'Добавить склад', value: '' };
	const onChange = jest.fn();
	render( <ControlField schema={ schema } value="" onChange={ onChange } settingId="warehouses" /> );

	expect( screen.getByText( 'Склады' ) ).toBeInTheDocument();
	expect( screen.getByRole( 'button', { name: 'Добавить склад' } ) ).toBeInTheDocument();
	expect( validateField( schema, stored( { name: 'A', address: place( 'x' ), is_main: false } ) ) ).toBe( 'Отметьте ровно одну строку в колонке «Основной».' );
	expect( validateField( { ...schema, required: true }, '' ) ).toBe( 'Обязательное поле.' );
} );

test( 'the server message for a rejected row shows under the table', () => {
	const schema = { controlType: 'records-table', name: 'Склады', columns: COLUMNS, value: '', serverError: 'Строка 2: Не удалось определить координаты адреса.' };
	render( <ControlField schema={ schema } value="" onChange={ jest.fn() } settingId="warehouses" /> );

	expect( screen.getByText( 'Строка 2: Не удалось определить координаты адреса.' ) ).toBeInTheDocument();
} );

test( 'a table that becomes disabled while its form is open locks the form and refuses the save', () => {
	const onChange = jest.fn();
	const props = { value: stored( { name: 'A', address: place( 'г Москва' ), is_main: true } ), columns: COLUMNS, name: 'w', onChange };
	const { rerender } = render( <RecordsTable { ...props } /> );

	fireEvent.click( screen.getByRole( 'button', { name: 'Изменить, 1' } ) );
	fillName( 'Правка' );
	rerender( <RecordsTable { ...props } disabled /> );

	expect( screen.getByRole( 'textbox', { name: 'Название' } ) ).toBeDisabled();
	expect( screen.getByRole( 'button', { name: /pick address/ } ) ).toBeDisabled();
	expect( screen.getByRole( 'checkbox', { name: 'Основной' } ) ).toBeDisabled();
	expect( screen.getByRole( 'button', { name: 'Сохранить' } ) ).toBeDisabled();
	fireEvent.click( screen.getByRole( 'button', { name: 'Сохранить' } ) );
	expect( onChange ).not.toHaveBeenCalled();
	expect( screen.getByRole( 'button', { name: 'Отмена' } ) ).toBeEnabled();
} );

test( 'a required table is empty for every spelling of an empty JSON list, but not for broken text', () => {
	const schema = { controlType: 'records-table', name: 'Склады', columns: COLUMNS, required: true };

	[ '', '[]', '[ ]', ' [\n] ' ].forEach( ( empty ) => expect( validateField( schema, empty ) ).toBe( 'Обязательное поле.' ) );
	expect( validateField( schema, 'not json' ) ).not.toBe( 'Обязательное поле.' );
	expect( validateField( schema, stored( { name: 'A', address: place( 'x' ), is_main: true } ) ) ).toBeNull();
} );

test( 'ControlField wraps the table so the settings page can give it the whole card width', () => {
	const schema = { controlType: 'records-table', name: 'Склады', columns: COLUMNS };
	const { container } = render( <ControlField schema={ schema } value="" onChange={ jest.fn() } settingId="warehouses" /> );

	// The settings stylesheet stretches `.woodev-records-field .woodev-field`; without the wrapper the table sat in the 425px control column.
	expect( container.querySelector( '.woodev-records-field > .woodev-field .woodev-records' ) ).not.toBeNull();
} );


const COUNTRY_COLUMNS: RecordColumn[] = [
	{ id: 'country', type: 'location-country', label: 'Страна', of: 'city', required: true,
		options: [ { code: 'KZ', name: 'Казахстан' }, { code: 'RU', name: 'Россия' } ],
		in_scope: [ 'RU', 'KZ' ], default: 'RU', names: { RU: 'Россия', KZ: 'Казахстан' } },
	{ id: 'city', type: 'location-picker', label: 'Населённый пункт', level: 'settlement', required: true },
];
const city = ( country: string, label: string ) => JSON.stringify( { key: 'k-' + label, country, label } );
const openCityForm = () => fireEvent.click( screen.getByRole( 'button', { name: 'Добавить склад' } ) );
const pickCity = () => fireEvent.click( screen.getByRole( 'button', { name: /pick address/ } ) );
const saveCity = () => fireEvent.click( screen.getByRole( 'button', { name: 'Сохранить' } ) );

test( 'one country is text without a select, and the derived column is never stored', () => {
	const columns = [ { ...COUNTRY_COLUMNS[ 0 ], options: [ { code: 'RU', name: 'Россия' } ] }, COUNTRY_COLUMNS[ 1 ] ];
	render( <Harness columns={ columns } /> );
	openCityForm();
	expect( screen.queryByRole( 'combobox' ) ).toBeNull();
	expect( screen.getByText( 'Россия' ) ).toBeInTheDocument();
	expect( screen.getByRole( 'button', { name: 'pick address' } ) ).toHaveAttribute( 'data-country', 'RU' );
	pickCity();
	saveCity();
	expect( saved()[ 0 ] ).not.toHaveProperty( 'country' );
	expect( JSON.parse( saved()[ 0 ].city as string ).country ).toBe( 'RU' );
	expect( validateRecordsValue( JSON.stringify( saved() ), columns ) ).toBeNull();
} );

test( 'the server default wins over option order and changing country clears a mismatched pick', () => {
	render( <Harness columns={ COUNTRY_COLUMNS } /> );
	openCityForm();
	expect( screen.getByRole( 'combobox', { name: 'Страна' } ) ).toHaveValue( 'RU' );
	pickCity();
	fireEvent.change( screen.getByRole( 'combobox', { name: 'Страна' } ), { target: { value: 'KZ' } } );
	expect( screen.getByRole( 'button', { name: 'pick address' } ) ).toHaveAttribute( 'data-country', 'KZ' );
	saveCity();
	expect( screen.getByRole( 'alert' ) ).toHaveTextContent( 'Выберите «Населённый пункт» из подсказок.' );
	pickCity();
	saveCity();
	expect( JSON.parse( saved()[ 0 ].city as string ).country ).toBe( 'KZ' );
} );

test( 'add and edit keep country-name order stable within a country', () => {
	render( <Harness columns={ COUNTRY_COLUMNS } initial={ stored( { id: 1, city: city( 'RU', 'Москва' ) }, { id: 2, city: city( 'RU', 'Тула' ) } ) } /> );
	openCityForm();
	fireEvent.change( screen.getByRole( 'combobox' ), { target: { value: 'KZ' } } );
	pickCity();
	saveCity();
	expect( saved().map( ( row ) => JSON.parse( row.city as string ).country ) ).toEqual( [ 'KZ', 'RU', 'RU' ] );
	expect( saved().slice( 1 ).map( ( row ) => row.id ) ).toEqual( [ 1, 2 ] );
	fireEvent.click( screen.getByRole( 'button', { name: 'Изменить, 2' } ) );
	fireEvent.change( screen.getByRole( 'combobox' ), { target: { value: 'KZ' } } );
	pickCity();
	saveCity();
	expect( saved().map( ( row ) => JSON.parse( row.city as string ).country ) ).toEqual( [ 'KZ', 'KZ', 'RU' ] );
	expect( saved()[ 1 ].id ).toBe( 1 );
	expect( saved()[ 2 ].id ).toBe( 2 );
} );

test( 'a flagged country outside options shows and sorts by its server-resolved name', () => {
	const columns = [ { ...COUNTRY_COLUMNS[ 0 ], options: [ { code: 'RU', name: 'Россия' } ], in_scope: [ 'RU' ] }, COUNTRY_COLUMNS[ 1 ] ];
	render( <Harness columns={ columns } initial={ stored( { city: city( 'RU', 'Москва' ) }, { city: city( 'KZ', 'Алматы' ) } ) } /> );
	const first = screen.getAllByRole( 'row' )[ 1 ];
	expect( within( first ).getByText( 'Казахстан' ) ).toBeInTheDocument();
	expect( within( first ).getByText( 'страна не входит в доставку — на оформлении не показывается' ) ).toBeInTheDocument();
	openCityForm();
	pickCity();
	saveCity();
	expect( JSON.parse( saved()[ 0 ].city as string ).country ).toBe( 'KZ' );
	expect( saved() ).toHaveLength( 3 );
} );

test( 'no provider options does not flag a country still in shipping scope or remove its record', () => {
	const columns = [ { ...COUNTRY_COLUMNS[ 0 ], options: [], default: '' }, COUNTRY_COLUMNS[ 1 ] ];
	render( <Harness columns={ columns } initial={ stored( { city: city( 'RU', 'Москва' ) } ) } /> );
	expect( screen.queryByText( 'страна не входит в доставку — на оформлении не показывается' ) ).toBeNull();
	fireEvent.click( screen.getByRole( 'button', { name: 'Изменить, 1' } ) );
	expect( screen.getByRole( 'button', { name: /pick address/ } ) ).toBeDisabled();
	saveCity();
	expect( saved() ).toEqual( [ { city: city( 'RU', 'Москва' ) } ] );
} );

test( 'an unknown country name falls back to its code', () => {
	render( <Harness columns={ COUNTRY_COLUMNS } initial={ stored( { city: city( 'XX', 'Unknown' ) } ) } /> );
	expect( screen.getByText( 'XX' ) ).toBeInTheDocument();
} );

test( 'the real picker request carries the selected row country', async () => {
	mockUseRealPicker = true;
	jest.useFakeTimers();
	const fetch = apiFetch as jest.MockedFunction<typeof apiFetch>;
	fetch.mockResolvedValue( { suggestions: [] } );
	( window as Window & { woodevSettings?: unknown } ).woodevSettings = { restRoot: 'https://example.test/wp-json/woodev/v1/settings', nonce: 'n' };
	try {
		render( <Harness columns={ COUNTRY_COLUMNS } /> );
		openCityForm();
		fireEvent.change( screen.getByRole( 'combobox', { name: 'Страна' } ), { target: { value: 'KZ' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Выберите локацию…' } ) );
		fireEvent.change( await screen.findByPlaceholderText( 'Начните вводить название…' ), { target: { value: 'Алм' } } );
		await act( async () => { jest.advanceTimersByTime( 300 ); } );
		await waitFor( () => expect( fetch ).toHaveBeenCalled() );
		const request = fetch.mock.calls[ fetch.mock.calls.length - 1 ][ 0 ] as { url: string };
		expect( new URL( request.url ).searchParams.get( 'country' ) ).toBe( 'KZ' );
		expect( new URL( request.url ).pathname ).toBe( '/wp-json/woodev/v1/location/default-locality/suggest' );
	} finally {
		mockUseRealPicker = false;
		jest.useRealTimers();
	}
} );


test( 'editing an old foreign row with one offered country uses that country without a select', () => {
	const columns = [ { ...COUNTRY_COLUMNS[ 0 ], options: [ { code: 'RU', name: 'Россия' } ], in_scope: [ 'RU' ] }, COUNTRY_COLUMNS[ 1 ] ];
	render( <Harness columns={ columns } initial={ stored( { city: city( 'KZ', 'Алматы' ) } ) } /> );
	fireEvent.click( screen.getByRole( 'button', { name: 'Изменить, 1' } ) );
	expect( screen.queryByRole( 'combobox' ) ).toBeNull();
	expect( screen.getByRole( 'button', { name: 'pick address' } ) ).toHaveAttribute( 'data-country', 'RU' );
	fireEvent.click( screen.getByRole( 'button', { name: 'Отмена' } ) );
	expect( saved() ).toEqual( [ { city: city( 'KZ', 'Алматы' ) } ] );
} );
