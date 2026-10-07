import '@testing-library/jest-dom';
import { useState } from '@wordpress/element';
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import BoxesTable, { parseBoxRows, serializeBoxRows, validateBoxesText } from '../../src/components/boxes-table';
import SearchSelectField from '../../src/components/search-select-field';
import ControlField from '../../src/components/control-field';
import { buildSavePayload, validatableFields } from '../../src/settings-page/app';
import { evaluateConditions, isFieldDisabled } from '../../src/components/validate';

jest.mock( '@wordpress/api-fetch', () => ( { __esModule: true, default: jest.fn() } ) );
const fetchMock = apiFetch as jest.Mock;

beforeEach( () => { fetchMock.mockReset(); } );

function BoxesForm( { initial = '' } ) {
	const [ value, setValue ] = useState( initial );
	return <><BoxesTable value={ value } onChange={ setValue } /><output data-testid="saved">{ value }</output></>;
}

test( 'packaging table adds, edits and deletes compatible rows', () => {
	render( <BoxesForm /> );
	fireEvent.click( screen.getByRole( 'button', { name: 'Добавить упаковку' } ) );
	const name = screen.getByRole( 'textbox', { name: 'Название, 1' } );
	fireEvent.change( name, { target: { value: 'Малая' } } );
	for ( const field of [ 'Длина, см', 'Ширина, см', 'Высота, см' ] ) {
		fireEvent.change( screen.getByRole( 'textbox', { name: `${ field }, 1` } ), { target: { value: '10' } } );
	}
	fireEvent.change( screen.getByRole( 'textbox', { name: 'Макс. вес, кг, 1' } ), { target: { value: '2' } } );
	fireEvent.change( screen.getByRole( 'textbox', { name: 'Вес упаковки, кг, 1' } ), { target: { value: '0,1' } } );
	expect( screen.queryByRole( 'alert' ) ).toBeNull();
	expect( screen.getByTestId( 'saved' ) ).toHaveTextContent( 'Малая; 10; 10; 10; 2; 0.1' );
	fireEvent.click( screen.getByRole( 'button', { name: 'Удалить' } ) );
	expect( screen.getByTestId( 'saved' ) ).toBeEmptyDOMElement();
} );

test( 'invalid packaging cells show errors and block field validation', () => {
	render( <BoxesForm initial="Box; 10; 10; 10" /> );
	fireEvent.change( screen.getByRole( 'textbox', { name: 'Длина, см, 1' } ), { target: { value: '0' } } );
	expect( screen.getByRole( 'alert' ) ).toHaveTextContent( 'Размеры должны быть числами больше нуля.' );
	fireEvent.change( screen.getByRole( 'textbox', { name: 'Длина, см, 1' } ), { target: { value: '20' } } );
	fireEvent.change( screen.getByRole( 'textbox', { name: 'Вес упаковки, кг, 1' } ), { target: { value: '-1' } } );
	expect( screen.getByRole( 'alert' ) ).toHaveTextContent( 'Вес должен быть числом не меньше нуля.' );
	expect( validateBoxesText( 'Box; 1e999; 10; 10' ) ).toBeTruthy();
	expect( validateBoxesText( 'Box; 10; 10; 10; 0; 0' ) ).toBeNull();
	expect( validateBoxesText( 'Name; 10; 10; 10; 0; 0; extra' ) ).toBeTruthy();
} );

test( 'the table displays cm/kg and serializes back to store units', () => {
	const rows = parseBoxRows( 'Small; 200; 150; 100; 2000; 100', 0.1, 0.001 );
	expect( rows[ 0 ] ).toEqual( [ 'Small', '20', '15', '10', '2', '0.1', '', 'yes' ] );
	expect( serializeBoxRows( rows, 0.1, 0.001 ) ).toBe( 'Small; 200; 150; 100; 2000; 100; ; yes' );
} );

test( 'a disabled packaging table disables cells and row actions', () => {
	render( <BoxesTable value="Small; 10; 10; 10" onChange={ jest.fn() } disabled /> );
	screen.getAllByRole( 'textbox' ).forEach( ( input ) => expect( input ).toBeDisabled() );
	screen.getAllByRole( 'button' ).forEach( ( button ) => expect( button ).toBeDisabled() );
} );

test( 'disabled_if follows unsaved values and renders its separate reason', () => {
	const schema = { name: 'Пункт', type: 'string', controlType: 'text', value: 'saved', description: 'Описание', disabled_if: { setting: 'mode', value: 'auto' }, disabled_reason: 'Автоматический режим' };
	const props = { schema, value: 'saved', onChange: jest.fn(), conditionValues: { mode: 'manual' } };
	const view = render( <ControlField { ...props } /> );
	expect( screen.getByRole( 'textbox' ) ).toBeEnabled();
	view.rerender( <ControlField { ...props } conditionValues={ { mode: 'auto' } } /> );
	expect( screen.getByRole( 'textbox' ) ).toBeDisabled();
	expect( screen.getByText( 'Автоматический режим' ) ).toBeInTheDocument();
	expect( screen.getByText( 'Описание' ) ).toBeInTheDocument();
	expect( screen.getByRole( 'textbox' ) ).toHaveValue( 'saved' );
	view.rerender( <ControlField { ...props } /> );
	expect( screen.getByRole( 'textbox' ) ).toBeEnabled();
	expect( screen.queryByText( 'Автоматический режим' ) ).toBeNull();
} );

test( 'disabled_if uses show_if operators/relations and excludes disabled edits from validation/save', () => {
	const rule = { relation: 'OR', 0: { setting: 'mode', operator: 'in', value: [ 'auto', 'off' ] }, 1: { setting: 'enabled', value: false } };
	const field = { disabled_if: rule, value: 42, required: true };
	expect( isFieldDisabled( field, { mode: 'auto', enabled: true } ) ).toBe( evaluateConditions( rule, { mode: 'auto', enabled: true } ) );
	expect( isFieldDisabled( { disabled_if: [] }, {} ) ).toBe( false );
	expect( isFieldDisabled( { disabled: true }, {} ) ).toBe( true );
	const fields = { city: field, mode: { value: 'manual' } };
	expect( buildSavePayload( fields, { city: '', mode: 'auto' }, { mode: 'auto', enabled: true } ) ).toEqual( { mode: 'auto' } );
	expect( validatableFields( fields, { mode: 'auto', enabled: true } ) ).toEqual( { mode: fields.mode } );
	expect( buildSavePayload( fields, { city: 99, mode: 'manual' }, { mode: 'manual', enabled: true } ) ).toEqual( { city: 99, mode: 'manual' } );
} );

test( 'search-select hydrates saved labels and debounces at two characters, preserving numeric ids', async () => {
	fetchMock.mockResolvedValue( { options: [ { value: 99, label: 'Тула' } ] } );
	const onChange = jest.fn();
	const view = render( <SearchSelectField value={ 42 } savedValue={ 42 } valueLabel="Москва" searchUrl="https://example.test/search" nonce="nonce" onChange={ onChange } /> );
	fireEvent.click( screen.getByRole( 'button', { name: 'Москва' } ) );
	await screen.findByRole( 'searchbox' );
	fireEvent.change( screen.getByRole( 'searchbox' ), { target: { value: 'Т' } } );
	await act( async () => { await new Promise( ( resolve ) => setTimeout( resolve, 330 ) ); } );
	expect( fetchMock ).not.toHaveBeenCalled();
	fireEvent.change( screen.getByRole( 'searchbox' ), { target: { value: 'Тул' } } );
	fireEvent.change( screen.getByRole( 'searchbox' ), { target: { value: 'Тула' } } );
	await screen.findByRole( 'option', { name: 'Тула' } );
	expect( fetchMock ).toHaveBeenCalledTimes( 1 );
	expect( fetchMock.mock.calls[ 0 ][ 0 ].headers ).toEqual( { 'X-WP-Nonce': 'nonce' } );
	expect( new URL( fetchMock.mock.calls[ 0 ][ 0 ].url ).searchParams.get( 'term' ) ).toBe( 'Тула' );
	fireEvent.click( screen.getByRole( 'option', { name: 'Тула' } ) );
	expect( onChange ).toHaveBeenCalledWith( 99 );
	view.rerender( <SearchSelectField value={ 99 } savedValue={ 42 } valueLabel="Москва" searchUrl="https://example.test/search" nonce="nonce" onChange={ onChange } /> );
	expect( screen.getByRole( 'button', { name: 'Тула' } ) ).toBeInTheDocument();
} );

test( 'search-select shows errors and ignores late results after the query changes', async () => {
	let finish: ( result: unknown ) => void = () => {};
	fetchMock.mockImplementationOnce( () => new Promise( ( resolve ) => { finish = resolve; } ) ).mockRejectedValueOnce( new Error( 'offline' ) );
	render( <SearchSelectField value="" searchUrl="https://example.test/search" onChange={ jest.fn() } /> );
	fireEvent.click( screen.getByRole( 'button' ) );
	fireEvent.change( await screen.findByRole( 'searchbox' ), { target: { value: 'Мо' } } );
	await waitFor( () => expect( fetchMock ).toHaveBeenCalledTimes( 1 ) );
	fireEvent.change( screen.getByRole( 'searchbox' ), { target: { value: 'Ту' } } );
	await act( async () => finish( { options: [ { value: 'old', label: 'Старый ответ' } ] } ) );
	expect( screen.queryByText( 'Старый ответ' ) ).toBeNull();
	await screen.findByText( 'Не удалось загрузить список.' );
} );

test( 'a disabled search-select cannot open and an unknown saved value has no invented label', () => {
	render( <SearchSelectField value={ 999 } savedValue={ 999 } valueLabel="" searchUrl="https://example.test/search" disabled onChange={ jest.fn() } /> );
	expect( screen.getByRole( 'button', { name: 'Выберите…' } ) ).toBeDisabled();
	expect( fetchMock ).not.toHaveBeenCalled();
} );


test( 'store table preserves free enabled legacy boxes and edits monetary cost and enabled', () => {
	render( <BoxesForm initial="Old; 10; 10; 10; 0; 0" /> );
	expect( screen.getByRole( 'checkbox', { name: 'Использовать, 1' } ) ).toBeChecked();
	fireEvent.change( screen.getByRole( 'textbox', { name: 'Стоимость, 1' } ), { target: { value: '2%' } } );
	fireEvent.click( screen.getByRole( 'checkbox', { name: 'Использовать, 1' } ) );
	expect( screen.getByTestId( 'saved' ) ).toHaveTextContent( 'Old; 10; 10; 10; 0; 0; 2%; no' );
	expect( validateBoxesText( 'Old; 10; 10; 10; 0; 0; -1; yes' ) ).toBeTruthy();
	expect( validateBoxesText( 'Old; 10; 10; 10; 0; 0; 2%; no' ) ).toBeNull();
} );

test( 'carrier preset block renders enabled, carrier charge, fixed read-only and merchant cost controls', () => {
	const fields = [
		{ name: 'Коробка M', type: 'boolean', controlType: 'toggle', value: false },
		{ name: 'Учитывать стоимость', type: 'boolean', controlType: 'toggle', value: true },
		{ name: 'Стоимость M', type: 'string', controlType: 'text', value: '7', disabled: true },
		{ name: 'Стоимость L', type: 'string', controlType: 'text', value: '2%' },
	];
	render( <section aria-label="Упаковка">{ fields.map( ( schema ) => <ControlField key={ schema.name } schema={ schema } value={ schema.value } onChange={ jest.fn() } /> ) }</section> );
	expect( screen.getAllByRole( 'checkbox' )[ 0 ] ).not.toBeChecked();
	expect( screen.getAllByRole( 'checkbox' )[ 1 ] ).toBeChecked();
	expect( screen.getAllByRole( 'textbox' )[ 0 ] ).toBeDisabled();
	expect( screen.getAllByRole( 'textbox' )[ 1 ] ).toBeEnabled();
} );
