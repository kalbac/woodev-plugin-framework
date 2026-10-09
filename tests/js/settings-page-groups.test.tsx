/**
 * Groups inside a settings section (Settings_Group): one titled card holding some fields and some action
 * buttons, buttons in ONE row with ONE shared result line, the notice shown once. Ungrouped sections render
 * exactly as before, and a section with nothing to save has no «Сохранить».
 *
 * @see src/settings-page/group-card.tsx
 * @see src/settings-page/section-view.js
 */

import '@testing-library/jest-dom';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import SectionView from '../../src/settings-page/section-view';
import { arrangeFields, groupNotices } from '../../src/settings-page/group-card';
import App, { sectionHasSaveButton } from '../../src/settings-page/app';
import { runTool, fetchSchema } from '../../src/settings-page/rest';

jest.mock( '../../src/settings-page/rest', () => ( {
	runTool: jest.fn(),
	testConnection: jest.fn(),
	fetchSchema: jest.fn(),
	saveTab: jest.fn(),
} ) );

const field = ( name: string ) => ( { type: 'string', name, controlType: 'text', value: '', description: '', tooltip: '', placeholder: '' } );

const action = ( id: string, button: string, extra = {} ) => ( { id, name: button, desc: '', button, disabled: false, status_text: '', ...extra } );

const group = ( id: string, fields: string[], actions: string[] = [], extra = {} ) => ( {
	id, title: `Группа ${ id }`, description: '', notice: '', fields, actions, ...extra,
} );

const renderSection = ( section: Record< string, unknown > ) => render(
	<SectionView
		providerId="cdek"
		section={ { id: 's', label: 'S', description: '', ...section } }
		values={ {} }
		onFieldChange={ jest.fn() }
		onFieldRevert={ jest.fn() }
	/>
);

beforeEach( () => {
	( runTool as jest.Mock ).mockReset();
} );

describe( 'arrangeFields', () => {
	it( 'puts a group where its first field is declared and keeps ungrouped fields in place', () => {
		const out = arrangeFields( [ 'a', 'b', 'c', 'd' ], [ group( 'g', [ 'b', 'd' ] ) ] );

		expect( out.map( ( item ) => ( 'field' === item.kind ? item.id : `group:${ item.group.id }` ) ) ).toEqual( [ 'a', 'group:g', 'c' ] );
		expect( out[ 1 ] ).toMatchObject( { fieldIds: [ 'b', 'd' ] } );
	} );

	it( 'puts a group with no visible field after every ungrouped field', () => {
		const out = arrangeFields( [ 'a', 'b' ], [ group( 'g', [], [ 'x' ] ) ] );

		expect( out.map( ( item ) => item.kind ) ).toEqual( [ 'field', 'field', 'group' ] );
	} );

	it( 'is the identity for an ungrouped section', () => {
		expect( arrangeFields( [ 'a', 'b' ], [] ) ).toEqual( [ { kind: 'field', id: 'a' }, { kind: 'field', id: 'b' } ] );
	} );
} );

describe( 'groupNotices', () => {
	it( 'shows the group notice and each distinct disabled-action reason once', () => {
		const lines = groupNotices(
			group( 'g', [], [ 'x', 'y' ], { notice: 'Только локально' } ),
			[
				action( 'x', 'X', { disabled: true, status_text: 'Только локально' } ),
				action( 'y', 'Y', { disabled: true, status_text: 'Нет ключа' } ),
				action( 'z', 'Z', { disabled: false, status_text: 'live status' } ),
			]
		);

		expect( lines ).toEqual( [ 'Только локально', 'Нет ключа' ] );
	} );
} );

describe( 'SectionView with groups', () => {
	it( 'renders a group as one card with its title, description, fields and a single row of buttons', () => {
		const { container } = renderSection( {
			fields: { token: field( 'Токен' ), mode: field( 'Режим' ), other: field( 'Прочее' ) },
			actions: [ action( 'on', 'Подключить' ), action( 'off', 'Отключить' ) ],
			groups: [ group( 'hooks', [ 'token', 'mode' ], [ 'on', 'off' ], { description: 'Про вебхуки', notice: 'Сайт доступен только локально' } ) ],
		} );

		const cards = container.querySelectorAll( '.woodev-group' );
		expect( cards ).toHaveLength( 1 );
		expect( cards[ 0 ] ).toHaveTextContent( 'Группа hooks' );
		expect( cards[ 0 ] ).toHaveTextContent( 'Про вебхуки' );
		expect( cards[ 0 ].querySelectorAll( '.woodev-group__actions button' ) ).toHaveLength( 2 );
		expect( cards[ 0 ].querySelectorAll( '.woodev-group__notice' ) ).toHaveLength( 1 );
		expect( cards[ 0 ] ).toHaveTextContent( 'Токен' );
		expect( cards[ 0 ] ).toHaveTextContent( 'Режим' );
		expect( cards[ 0 ] ).not.toHaveTextContent( 'Прочее' );
		expect( container.querySelector( '.woodev-tool' ) ).toBeNull(); // no per-action card for grouped actions
	} );

	it( 'keeps ungrouped actions in their own cards below', () => {
		const { container } = renderSection( {
			fields: { token: field( 'Токен' ) },
			actions: [ action( 'sync', 'Обновить' ), action( 'on', 'Подключить' ) ],
			groups: [ group( 'hooks', [], [ 'on' ] ) ],
		} );

		expect( container.querySelectorAll( '.woodev-group' ) ).toHaveLength( 1 );
		expect( container.querySelectorAll( '.woodev-tool' ) ).toHaveLength( 1 );
		expect( container.querySelector( '.woodev-tool' ) ).toHaveTextContent( 'Обновить' );
	} );

	it( 'shows one shared result line — the last clicked button\'s — and busies every button meanwhile', async () => {
		let resolveFirst: ( value: unknown ) => void = () => {};
		( runTool as jest.Mock )
			.mockReturnValueOnce( new Promise( ( resolve ) => { resolveFirst = resolve; } ) )
			.mockResolvedValueOnce( { success: false, message: 'Второй упал' } );

		const { container } = renderSection( {
			fields: {},
			actions: [ action( 'on', 'Подключить' ), action( 'off', 'Отключить' ) ],
			groups: [ group( 'hooks', [], [ 'on', 'off' ] ) ],
		} );

		fireEvent.click( screen.getByRole( 'button', { name: 'Подключить' } ) );
		expect( runTool ).toHaveBeenCalledWith( 'cdek', 'on', {} );
		expect( screen.getByRole( 'button', { name: 'Отключить' } ) ).toBeDisabled();

		resolveFirst( { success: true, message: 'Первый ок' } );
		await waitFor( () => expect( screen.getByText( 'Первый ок' ) ).toBeInTheDocument() );

		fireEvent.click( screen.getByRole( 'button', { name: 'Отключить' } ) );
		await waitFor( () => expect( screen.getByText( 'Второй упал' ) ).toBeInTheDocument() );

		expect( screen.queryByText( 'Первый ок' ) ).not.toBeInTheDocument();
		expect( container.querySelectorAll( '.woodev-group__result' ) ).toHaveLength( 1 );
		expect( container.querySelector( '.woodev-group__result' ) ).toHaveClass( 'is-error' );
	} );

	it( 'renders no empty card when every member is hidden by show_if', () => {
		const hidden = { ...field( 'Скрытое' ), show_if: [ { field: 'switch', operator: '==', value: 'on' } ] };
		const { container } = renderSection( {
			fields: { hidden, switch: { ...field( 'Переключатель' ), value: 'off' } },
			groups: [ group( 'g', [ 'hidden' ] ) ],
		} );

		expect( container.querySelectorAll( '.woodev-group' ) ).toHaveLength( 0 );
	} );

	it( 'renders a section without groups exactly as before — no group markup at all', () => {
		const { container } = renderSection( {
			fields: { token: field( 'Токен' ), mode: field( 'Режим' ) },
			actions: [ action( 'sync', 'Обновить' ) ],
		} );

		expect( container.querySelector( '.woodev-group' ) ).toBeNull();
		expect( container.querySelectorAll( '.woodev-field' ).length ).toBeGreaterThanOrEqual( 2 );
		expect( container.querySelectorAll( '.woodev-tool' ) ).toHaveLength( 1 );
		// Fields first, the action block after them.
		const html = container.innerHTML;
		expect( html.indexOf( 'Режим' ) ).toBeLessThan( html.indexOf( 'woodev-tool' ) );
	} );
} );

describe( 'sectionHasSaveButton', () => {
	it( 'is true for a section that owns fields', () => {
		expect( sectionHasSaveButton( { fields: { a: {} } } ) ).toBe( true );
	} );

	it( 'is false for a section of only actions (grouped or not) and for a tools block', () => {
		expect( sectionHasSaveButton( { fields: {}, actions: [ { id: 'x' } ], groups: [ { id: 'g' } ] } ) ).toBe( false );
		expect( sectionHasSaveButton( { fields: {}, is_tools: true } ) ).toBe( false );
	} );

	it( 'keeps the button on a connection block, as before', () => {
		expect( sectionHasSaveButton( { fields: {}, is_connection: true } ) ).toBe( true );
	} );
} );

describe( 'App: «Сохранить» on a section of only grouped actions', () => {
	// The helper test above cannot see the guard inside App's renderSection() (see the #505 M3 test header).
	const tab = ( sections: unknown[] ) => ( { id: 'cdek', label: 'СДЭК', capability: 'manage_woocommerce', sections } );

	it( 'is absent when the open section has no field, present when it has one', async () => {
		( fetchSchema as jest.Mock ).mockResolvedValue( { tabs: [ tab( [
			{
				id: 'hooks', label: 'Вебхуки', description: '', fields: {},
				actions: [ action( 'on', 'Подключить' ) ], groups: [ group( 'hooks', [], [ 'on' ] ) ],
			},
			{ id: 'fields', label: 'Поля', description: '', fields: { token: field( 'Токен' ) } },
		] ) ] } );

		render( <App /> );

		await waitFor( () => expect( screen.getByRole( 'button', { name: 'Подключить' } ) ).toBeInTheDocument() );
		expect( screen.queryByRole( 'button', { name: 'Сохранить' } ) ).not.toBeInTheDocument();

		fireEvent.click( screen.getByText( 'Поля' ) );
		await waitFor( () => expect( screen.getByRole( 'button', { name: 'Сохранить' } ) ).toBeInTheDocument() );
	} );
} );
