import '@testing-library/jest-dom';
import { useState } from '@wordpress/element';
import { render, screen, fireEvent, within } from '@testing-library/react';
import RecordsTable, { getRowError, parseRecordRows, validateRecordsValue, type RecordColumn } from '../../src/components/records-table';
import ControlField from '../../src/components/control-field';
import { validateField } from '../../src/components/validate';

// The real picker searches over REST; the address cell only needs "a record gets picked".
jest.mock( '../../src/components/location-picker-field', () => ( {
	__esModule: true,
	...jest.requireActual( '../../src/components/location-picker-field' ),
	default: ( { value, level, provider, providerPinned, disabled, onChange }: { value: string; level: string; provider?: string; providerPinned?: boolean; disabled?: boolean; onChange: ( v: string ) => void } ) => (
		<button type="button" data-level={ level } data-provider={ provider } data-pinned={ String( !! providerPinned ) } disabled={ disabled } onClick={ () => onChange( JSON.stringify( { key: 'k-new', label: 'г Подольск, ул Ленина, д 1' } ) ) }>
			{ 'pick address' + ( value ? ' (set)' : '' ) }
		</button>
	),
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
