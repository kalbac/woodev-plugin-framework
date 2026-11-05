/**
 * The fixed-rows fields table (Settings_Table): fields drawn label-less, a status that follows the row's key,
 * and a check that tests the typed-but-unsaved values and shows its answer under the row.
 *
 * @see src/settings-page/fields-table.tsx
 */

import '@testing-library/jest-dom';
import { useState } from '@wordpress/element';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { computeAccessibleName } from 'dom-accessibility-api';
import FieldsTable, { rowState, findCheck, type TableSchema } from '../../src/settings-page/fields-table';
import SectionView from '../../src/settings-page/section-view';
import { testConnection } from '../../src/settings-page/rest';

jest.mock( '../../src/settings-page/rest', () => ( {
	runTool: jest.fn(),
	testConnection: jest.fn(),
	fetchSchema: jest.fn(),
	saveTab: jest.fn(),
} ) );

const FIELDS = {
	coefficient: { type: 'float', name: 'Коэффициент дороги', controlType: 'number', value: '1.2', description: 'Длинное описание', tooltip: 'Прямая × коэффициент' },
	mapbox_key: { type: 'string', name: 'Ключ API', controlType: 'password', sensitive: true, is_set: true, value: '' },
	mapbox_limit: { type: 'string', name: 'Лимит', controlType: 'number', value: '1000' },
	google_key: { type: 'string', name: 'Ключ API', controlType: 'password', sensitive: true, is_set: false, value: '' },
};

const check = ( connection: string, setting: string, extra = {} ) => ( { type: 'check', connection, settings: [ setting ], label: 'Проверить', supports_test: true, ...extra } );

const TABLE = ( mapboxCheck = {} ): TableSchema => ( {
	row_title: 'Сервис',
	columns: [ { id: 'status', label: 'Статус' }, { id: 'key', label: 'Ключ API', tooltip: 'Где взять ключ' }, { id: 'limit', label: 'Лимит в сутки' }, { id: 'check', label: '' } ],
	rows: [
		{ id: 'line', label: 'По прямой', cells: { status: { type: 'text', text: '● работает', tone: 'ok' }, limit: { type: 'field', setting: 'coefficient', prefix: 'коэф.' } } },
		{ id: 'mapbox', label: 'Mapbox', cells: { status: { type: 'status' }, key: { type: 'field', setting: 'mapbox_key' }, limit: { type: 'field', setting: 'mapbox_limit' }, check: check( 'mapbox', 'mapbox_key', mapboxCheck ) } },
		{ id: 'google', label: 'Google', cells: { status: { type: 'status' }, key: { type: 'field', setting: 'google_key' }, check: check( 'google', 'google_key' ) } },
	],
} as TableSchema );

function Harness( { table = TABLE(), onFieldChange = jest.fn(), onCheck }: { table?: TableSchema; onFieldChange?: jest.Mock; onCheck?: ( c: string, v: Record< string, unknown > ) => Promise< { success: boolean; message: string } > } ) {
	const [ values, setValues ] = useState< Record< string, unknown > >( {} );
	return (
		<FieldsTable
			providerId="range"
			table={ table }
			fields={ FIELDS }
			values={ values }
			onFieldChange={ ( id, v ) => { onFieldChange( id, v ); setValues( ( prev ) => ( { ...prev, [ id ]: v } ) ); } }
			onFieldRevert={ ( id ) => setValues( ( prev ) => { const next = { ...prev }; delete next[ id ]; return next; } ) }
			onCheck={ onCheck }
		/>
	);
}

const rowOf = ( label: string ) => screen.getByRole( 'rowheader', { name: label } ).closest( 'tr' ) as HTMLElement;

beforeEach( () => ( testConnection as jest.Mock ).mockReset() );

test( 'one row per service under the declared headers, the coefficient in the limit column with its prefix', () => {
	render( <Harness /> );

	expect( screen.getAllByRole( 'columnheader' ).map( ( th ) => th.textContent ) ).toEqual( [ 'Сервис', 'Статус', 'Ключ API', 'Лимит в сутки', 'Действия' ] );
	expect( screen.getAllByRole( 'rowheader' ).map( ( th ) => th.textContent ) ).toEqual( [ 'По прямой', 'Mapbox', 'Google' ] );

	const line = within( rowOf( 'По прямой' ) );
	expect( line.getByText( '● работает' ) ).toBeInTheDocument();
	expect( line.getByText( 'коэф.' ) ).toBeInTheDocument();
	expect( line.getByDisplayValue( '1.2' ) ).toBeInTheDocument();
	expect( line.queryByRole( 'button' ) ).toBeNull();
} );

test( 'a field cell holds the control alone: no label or description, only a tip icon for the field\'s own tooltip', () => {
	render( <Harness /> );

	expect( screen.queryByText( 'Коэффициент дороги' ) ).toBeNull();
	expect( screen.queryByText( 'Длинное описание' ) ).toBeNull();
	expect( within( rowOf( 'По прямой' ) ).getByLabelText( 'Прямая × коэффициент' ) ).toBeInTheDocument();
	expect( within( rowOf( 'Mapbox' ) ).queryByRole( 'img' ) ).toBeNull();
} );

test( 'a field without a tooltip gets no tip icon even when it has a description', () => {
	const fields = { ...FIELDS, mapbox_limit: { ...FIELDS.mapbox_limit, description: '<a href="#">Запросов в сутки</a>' } };

	render( <FieldsTable providerId="range" table={ TABLE() } fields={ fields } values={ {} } onFieldChange={ jest.fn() } onFieldRevert={ jest.fn() } /> );

	expect( within( rowOf( 'Mapbox' ) ).queryByRole( 'img' ) ).toBeNull();
	expect( screen.queryByText( 'Запросов в сутки' ) ).toBeNull();
} );

test( 'a column header carries its tip icon, hover-reachable and focusable; a header without a tip has none', () => {
	render( <Harness /> );

	const headers = screen.getAllByRole( 'columnheader' );
	const tipOf = ( label: string ) => headers.find( ( th ) => th.textContent?.startsWith( label ) )!.querySelector( '.woodev-field__tip' );

	expect( tipOf( 'Ключ API' ) ).toHaveAttribute( 'aria-label', 'Где взять ключ' );
	expect( tipOf( 'Ключ API' ) ).toHaveAttribute( 'tabindex', '0' );
	expect( tipOf( 'Лимит в сутки' ) ).toBeNull();
	expect( tipOf( 'Статус' ) ).toBeNull();
} );

test( 'a saved key is masked, never echoed, and the status says «Подключён»', () => {
	render( <Harness /> );

	const mapbox = within( rowOf( 'Mapbox' ) );
	expect( mapbox.getByText( '● Подключён' ) ).toBeInTheDocument();
	expect( mapbox.getByPlaceholderText( '•••••• сохранено' ) ).toHaveValue( '' );
	expect( mapbox.getByRole( 'button', { name: 'Очистить' } ) ).toBeInTheDocument();
	expect( within( rowOf( 'Google' ) ).getByText( '○ Не подключён' ) ).toBeInTheDocument();
} );

test( 'the check button waits for the key, then the status follows the typed key', () => {
	render( <Harness /> );

	const google = within( rowOf( 'Google' ) );
	expect( google.getByRole( 'button', { name: 'Проверить' } ) ).toBeDisabled();

	fireEvent.change( google.getByLabelText( 'Ключ API: Google' ), { target: { value: 'KEY' } } );

	expect( google.getByRole( 'button', { name: 'Проверить' } ) ).toBeEnabled();
	expect( google.getByText( '● Подключён' ) ).toBeInTheDocument();
} );

test( 'a check sends the typed values to the connection route and shows the answer under its row', async () => {
	( testConnection as jest.Mock ).mockResolvedValue( { success: true, message: 'Ключ работает.' } );
	render( <Harness /> );

	fireEvent.change( within( rowOf( 'Google' ) ).getByLabelText( 'Ключ API: Google' ), { target: { value: 'KEY' } } );
	fireEvent.click( within( rowOf( 'Google' ) ).getByRole( 'button', { name: 'Проверить' } ) );

	expect( await screen.findByText( 'Ключ работает.' ) ).toBeInTheDocument();
	expect( testConnection ).toHaveBeenCalledWith( 'range', 'google', { google_key: 'KEY' } );
	expect( rowOf( 'Google' ).nextElementSibling ).toHaveTextContent( 'Ключ работает.' );
} );

test( 'a failed check turns the status to «Ошибка»; editing the key drops the stale answer', async () => {
	( testConnection as jest.Mock ).mockResolvedValue( { success: false, message: 'Ключ отклонён.' } );
	render( <Harness /> );

	fireEvent.click( within( rowOf( 'Mapbox' ) ).getByRole( 'button', { name: 'Проверить' } ) );
	expect( await screen.findByText( 'Ключ отклонён.' ) ).toBeInTheDocument();
	expect( within( rowOf( 'Mapbox' ) ).getByText( '✖ Ошибка' ) ).toBeInTheDocument();

	fireEvent.change( within( rowOf( 'Mapbox' ) ).getByLabelText( 'Ключ API: Mapbox' ), { target: { value: 'NEW' } } );

	expect( screen.queryByText( 'Ключ отклонён.' ) ).toBeNull();
	expect( within( rowOf( 'Mapbox' ) ).getByText( '● Подключён' ) ).toBeInTheDocument();
} );

test( 'a thrown check is reported in the row, not swallowed', async () => {
	( testConnection as jest.Mock ).mockRejectedValue( { message: 'Сеть недоступна.' } );
	render( <Harness /> );

	fireEvent.click( within( rowOf( 'Mapbox' ) ).getByRole( 'button', { name: 'Проверить' } ) );

	expect( await screen.findByText( 'Сеть недоступна.' ) ).toBeInTheDocument();
	await waitFor( () => expect( within( rowOf( 'Mapbox' ) ).getByRole( 'button', { name: 'Проверить' } ) ).toBeEnabled() );
} );

test( 'the answer the server remembered shows on load', () => {
	render( <Harness table={ TABLE( { status: { success: true, message: 'Работает с 12:00.' } } ) } /> );

	expect( screen.getByText( 'Работает с 12:00.' ) ).toBeInTheDocument();
} );

test( 'a cell naming a missing field draws nothing, and a check the handler cannot test has no button', () => {
	const table = TABLE( { supports_test: false } );
	table.rows[ 1 ].cells.limit = { type: 'field', setting: 'ghost' };
	render( <Harness table={ table } /> );

	expect( within( rowOf( 'Mapbox' ) ).queryByRole( 'button', { name: 'Проверить' } ) ).toBeNull();
	expect( within( rowOf( 'Mapbox' ) ).queryByRole( 'spinbutton' ) ).toBeNull();
} );

describe( 'rowState', () => {
	const mapbox = findCheck( TABLE().rows[ 1 ] );

	test( 'no check, no state', () => expect( rowState( undefined, FIELDS, {}, null ) ).toBe( 'none' ) );
	test( 'an unfilled key is «none» even after a good check', () => expect( rowState( findCheck( TABLE().rows[ 2 ] ), FIELDS, {}, { success: true, message: '' } ) ).toBe( 'none' ) );
	test( 'filled and unanswered is ok', () => expect( rowState( mapbox, FIELDS, {}, null ) ).toBe( 'ok' ) );
	test( 'filled and failed is error', () => expect( rowState( mapbox, FIELDS, {}, { success: false, message: 'x' } ) ).toBe( 'error' ) );
} );

test( 'SectionView draws a table group as the table, not as labelled rows', () => {
	render(
		<SectionView
			providerId="range"
			section={ {
				id: 'services',
				label: 'Сервисы',
				description: '',
				fields: FIELDS,
				groups: [ { id: 'services', title: '', description: '', notice: '', fields: [ 'coefficient', 'mapbox_key', 'mapbox_limit' ], actions: [], table: TABLE() } ],
			} }
			values={ {} }
			onFieldChange={ jest.fn() }
			onFieldRevert={ jest.fn() }
		/>
	);

	expect( screen.getByRole( 'table' ) ).toBeInTheDocument();
	expect( screen.queryByText( 'Коэффициент дороги' ) ).toBeNull();
} );

test( 'a table group with no title draws no empty heading', () => {
	render(
		<SectionView
			providerId="range"
			section={ { id: 's', label: 'S', description: '', fields: FIELDS, groups: [ { id: 'g', title: '', description: '', notice: '', fields: [ 'coefficient' ], actions: [], table: TABLE() } ] } }
			values={ {} }
			onFieldChange={ jest.fn() }
			onFieldRevert={ jest.fn() }
		/>
	);

	expect( screen.queryByRole( 'heading' ) ).toBeNull();
} );

test( 'a titleless table group drops the card frame; a titled one keeps it', () => {
	const section = ( title: string ) => ( { id: 's', label: 'S', description: '', fields: FIELDS, groups: [ { id: 'g', title, description: '', notice: '', fields: [ 'coefficient' ], actions: [], table: TABLE() } ] } );
	const { container, rerender } = render( <SectionView providerId="range" section={ section( '' ) } values={ {} } onFieldChange={ jest.fn() } onFieldRevert={ jest.fn() } /> );

	expect( container.querySelector( '.woodev-group--flush' ) ).not.toBeNull();

	rerender( <SectionView providerId="range" section={ section( 'Сервисы' ) } values={ {} } onFieldChange={ jest.fn() } onFieldRevert={ jest.fn() } /> );

	expect( container.querySelector( '.woodev-group--flush' ) ).toBeNull();
} );

test( 'every input in a cell has its own accessible name: column and row, the coefficient by its own name', () => {
	render( <Harness /> );

	const nameOf = ( row: string, selector: string ) => computeAccessibleName( rowOf( row ).querySelector( selector ) as Element );

	expect( nameOf( 'Mapbox', 'input[type="password"]' ) ).toBe( 'Ключ API: Mapbox' );
	expect( nameOf( 'Mapbox', 'input[type="number"]' ) ).toBe( 'Лимит в сутки: Mapbox' );
	expect( nameOf( 'Google', 'input[type="password"]' ) ).toBe( 'Ключ API: Google' );
	expect( nameOf( 'По прямой', 'input[type="number"]' ) ).toBe( 'Коэффициент дороги: По прямой' );

	// The visible labels stay hidden: the name lives on the input, not in a drawn label.
	expect( document.querySelector( '.woodev-field__label' ) ).toBeNull();
} );

test( 'a field show_if hides is an empty cell in the table, while its siblings keep their inputs', () => {
	const fields = {
		enabled: { type: 'boolean', name: 'Включено', controlType: 'toggle', value: false },
		mapbox_key: FIELDS.mapbox_key,
		mapbox_limit: { ...FIELDS.mapbox_limit, show_if: { setting: 'enabled', operator: '=', value: true } },
	};
	const table = { row_title: 'Сервис', columns: [ { id: 'key', label: 'Ключ API' }, { id: 'limit', label: 'Лимит в сутки' } ], rows: [
		{ id: 'mapbox', label: 'Mapbox', cells: { key: { type: 'field', setting: 'mapbox_key' }, limit: { type: 'field', setting: 'mapbox_limit' } } },
	] } as TableSchema;
	const section = { id: 's', label: 'S', description: '', fields, groups: [ { id: 'g', title: '', description: '', notice: '', fields: [ 'mapbox_key', 'mapbox_limit' ], actions: [], table } ] };
	const view = ( enabled: boolean ) => <SectionView providerId="range" section={ section } values={ { enabled } } onFieldChange={ jest.fn() } onFieldRevert={ jest.fn() } />;

	const { rerender } = render( view( false ) );

	const row = within( rowOf( 'Mapbox' ) );
	expect( row.getByLabelText( 'Ключ API: Mapbox' ) ).toBeInTheDocument();
	expect( row.queryByRole( 'spinbutton' ) ).toBeNull();
	expect( rowOf( 'Mapbox' ).querySelectorAll( 'td' ) ).toHaveLength( 2 );
	expect( rowOf( 'Mapbox' ).querySelectorAll( 'td' )[ 1 ] ).toBeEmptyDOMElement();

	rerender( view( true ) );

	expect( within( rowOf( 'Mapbox' ) ).getByRole( 'spinbutton', { name: 'Лимит в сутки: Mapbox' } ) ).toBeInTheDocument();
} );
