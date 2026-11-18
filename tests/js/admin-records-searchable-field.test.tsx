/**
 * A searchable select field of an action: the merchant types in a search box, the server filters (a product of the shop,
 * say) and the list shows what the server returned, unfiltered. The route already took `search`; the field now sends it,
 * debounced, and starts every new search on the first page.
 *
 * @see src/admin-records/actions/fields.tsx
 * @see src/admin-records/actions/use-field-options.ts
 */

import '@testing-library/jest-dom';
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { RecordsTable, useActionExtensions } from '../../src/admin-records';
import { SEARCH_DELAY_MS } from '../../src/admin-records/actions/fields';
import {
	fixtureDefinition,
	fixtureDefinitionEnvelope,
	fixtureList,
	fixtureRow,
} from '../../src/admin-records/testing/fixture';
import type { ActionDescriptor, ActionField } from '../../src/admin-records/types';
import type { TableCardProps } from '../../src/admin-records/wc-runtime';

jest.mock( '@wordpress/api-fetch' );

const fetchMock = apiFetch as unknown as jest.Mock;

const PRODUCT: ActionField = {
	id: 'product',
	label: 'Товар сайта',
	type: 'select',
	required: true,
	options: [],
	options_source: true,
	searchable: true,
	placeholder: 'Начните вводить название',
	help: '',
};

const LINK: ActionDescriptor = {
	id: 'link_to',
	label: 'Связать с…',
	tone: 'neutral',
	capability: '',
	effect: 'mutate',
	interaction: 'dialog',
	scope: 'selection',
	scope_sentence: '',
	confirm_label: '',
	skip_preview: false,
	fields: [ PRODUCT ],
};

function FakeTableCard( props: TableCardProps ) {
	if ( props.isLoading ) {
		return <div data-testid="table-loading" />;
	}

	return (
		<>
			<div className="fake-card-header">{ props.actions }</div>
		<table aria-label={ props.title }>
			<tbody>
				{ props.rows.map( ( row, index ) => (
					<tr key={ props.rowKey( row, index ) }>
						{ row.map( ( cell, column ) => (
							<td key={ column }>{ cell.display }</td>
						) ) }
					</tr>
				) ) }
			</tbody>
		</table>
		</>
	);
}

const PLAN = {
	action: 'link_to',
	scope: { type: 'selection', sentence: '' },
	requested: 1,
	eligible: 1,
	skipped: 0,
	warnings: [],
	groups: [],
	messages: {},
	fields: [],
	confirm_label: '',
	can_apply: true,
};

const COPY = { action_preview: 'Проверить', action_cancel: 'Отмена', action_choose: 'Выберите товар', action_options_more: 'Ещё товары' };

function definitionEnvelope() {
	const base = fixtureDefinition();

	return fixtureDefinitionEnvelope( { ...base, row_actions: [ LINK ], bulk_actions: [], messages: { ...base.messages, ...COPY } } );
}

function Page() {
	return <RecordsTable owner="shop" table="cards" title="Карточки" query={ {} } navigate={ jest.fn() } extensions={ useActionExtensions() } />;
}

/** The option routes the page asked, decoded. */
function optionReads(): string[] {
	return fetchMock.mock.calls
		.map( ( [ options ] ) => decodeURIComponent( options.path ) )
		.filter( ( path ) => path.split( '?' )[ 0 ].endsWith( '/options' ) );
}

/** The search term of every option read, in order ('' when none was sent). */
function searches(): string[] {
	return optionReads().map( ( path ) => new URL( `https://shop.test${ path }` ).searchParams.get( 'search' ) ?? '' );
}

/** The product listing the server answers with: a search narrows it down by the label. */
function products( path: string ) {
	const term = new URL( `https://shop.test${ path }` ).searchParams.get( 'search' ) ?? '';
	const all = [
		{ value: '11', label: 'Кружка синяя (#11)' },
		{ value: '12', label: 'Кружка красная (#12)' },
		{ value: '21', label: 'Футболка (#21)' },
		{ value: '31', label: 'Футболка хлопковая красная (TSH-01)' },
	];
	// Like the shop's own search: every word of the term must be found, in any order, not the whole phrase.
	const words = term.split( ' ' ).filter( ( word ) => word !== '' );

	return { options: all.filter( ( option ) => words.every( ( word ) => option.label.toLowerCase().includes( word.toLowerCase() ) ) ), next_cursor: null };
}

function previews() {
	return fetchMock.mock.calls.map( ( [ options ] ) => options ).filter( ( options ) => String( options.path ).endsWith( '/preview' ) );
}

function applies() {
	return fetchMock.mock.calls.map( ( [ options ] ) => options ).filter( ( options ) => String( options.path ).endsWith( '/actions/link_to' ) );
}

async function openDialog(): Promise< HTMLElement > {
	render( <Page /> );
	await screen.findByRole( 'table', { name: 'Карточки' } );
	fireEvent.click( screen.getByRole( 'button', { name: 'Связать с…' } ) );

	return screen.findByRole( 'dialog' );
}

beforeEach( () => {
	fetchMock.mockReset();
	( window as unknown as { wc: unknown } ).wc = { components: { TableCard: FakeTableCard } };
	fetchMock.mockImplementation( ( { path }: { path: string } ) => {
		const route = decodeURIComponent( path ).split( '?' )[ 0 ];

		if ( route.endsWith( '/definition' ) ) {
			return Promise.resolve( definitionEnvelope() );
		}
		if ( route.endsWith( '/options' ) ) {
			return Promise.resolve( products( decodeURIComponent( path ) ) );
		}
		if ( route.endsWith( '/preview' ) ) {
			return Promise.resolve( PLAN );
		}
		if ( route.endsWith( '/actions/link_to' ) ) {
			return Promise.resolve( { status: 'done', rows: [], removed_ids: [], requested: 1, accepted: 1, skipped: 0, failed: 0, groups: [], messages: {} } );
		}

		return Promise.resolve(
			fixtureList( [
				fixtureRow( 'c1', 'Карточка 1', { actions: [ { id: 'link_to', enabled: true, reason: '' } ] } ),
			] )
		);
	} );
} );

afterEach( () => {
	delete ( window as unknown as { wc?: unknown } ).wc;
} );

describe( 'a searchable select field', () => {
	test( 'lists the options once, with no search term', async () => {
		const dialog = await openDialog();

		await within( dialog ).findByRole( 'searchbox', { name: 'Товар сайта' } );
		await waitFor( () => expect( optionReads() ).toHaveLength( 1 ) );

		expect( optionReads()[ 0 ] ).not.toContain( 'search=' );
	} );

	test( 'asks the server for what the merchant typed, once he stops typing', async () => {
		const dialog = await openDialog();
		const input = await within( dialog ).findByRole( 'searchbox', { name: 'Товар сайта' } );
		await waitFor( () => expect( optionReads() ).toHaveLength( 1 ) );

		fireEvent.change( input, { target: { value: 'Кр' } } );
		fireEvent.change( input, { target: { value: 'Кружка кра' } } );

		expect( optionReads() ).toHaveLength( 1 ); // Debounced: the keystrokes alone ask for nothing.
		await waitFor( () => expect( optionReads() ).toHaveLength( 2 ), { timeout: SEARCH_DELAY_MS * 4 } );

		expect( searches() ).toEqual( [ '', 'Кружка кра' ] ); // Not 'Кр': the first keystroke was superseded before it was sent.
	} );

	test( 'a new search starts on the first page and drops the cursor of the old one', async () => {
		fetchMock.mockImplementation( ( { path }: { path: string } ) => {
			const decoded = decodeURIComponent( path );
			const route = decoded.split( '?' )[ 0 ];

			if ( route.endsWith( '/definition' ) ) {
				return Promise.resolve( definitionEnvelope() );
			}
			if ( route.endsWith( '/options' ) ) {
				return Promise.resolve( { options: [ { value: '11', label: 'Кружка синяя (#11)' } ], next_cursor: 'page2' } );
			}

			return Promise.resolve( fixtureList( [ fixtureRow( 'c1', 'Карточка 1', { actions: [ { id: 'link_to', enabled: true, reason: '' } ] } ) ] ) );
		} );

		const dialog = await openDialog();
		const input = await within( dialog ).findByRole( 'searchbox', { name: 'Товар сайта' } );
		await waitFor( () => expect( optionReads() ).toHaveLength( 1 ) );
		fireEvent.click( await within( dialog ).findByRole( 'button', { name: 'Ещё товары' } ) );
		await waitFor( () => expect( optionReads() ).toHaveLength( 2 ) );
		expect( optionReads()[ 1 ] ).toContain( 'cursor=page2' );

		fireEvent.change( input, { target: { value: 'Фут' } } );
		await waitFor( () => expect( optionReads() ).toHaveLength( 3 ), { timeout: SEARCH_DELAY_MS * 4 } );

		expect( optionReads()[ 2 ] ).toContain( 'search=Фут' );
		expect( optionReads()[ 2 ] ).not.toContain( 'cursor=' );
	} );

	test( 'coming back to an earlier search starts on its first page, not on the page the merchant had reached', async () => {
		fetchMock.mockImplementation( ( { path }: { path: string } ) => {
			const decoded = decodeURIComponent( path );
			const route = decoded.split( '?' )[ 0 ];
			const params = new URL( `https://shop.test${ decoded }` ).searchParams;

			if ( route.endsWith( '/definition' ) ) {
				return Promise.resolve( definitionEnvelope() );
			}
			if ( route.endsWith( '/options' ) ) {
				if ( params.get( 'search' ) === 'Фут' ) {
					return Promise.resolve( { options: [ { value: '21', label: 'Футболка (#21)' } ], next_cursor: null } );
				}

				return Promise.resolve(
					params.get( 'cursor' ) === 'page2'
						? { options: [ { value: '12', label: 'Кружка красная (#12)' } ], next_cursor: null }
						: { options: [ { value: '11', label: 'Кружка синяя (#11)' } ], next_cursor: 'page2' }
				);
			}

			return Promise.resolve( fixtureList( [ fixtureRow( 'c1', 'Карточка 1', { actions: [ { id: 'link_to', enabled: true, reason: '' } ] } ) ] ) );
		} );

		const dialog = await openDialog();
		const input = await within( dialog ).findByRole( 'searchbox', { name: 'Товар сайта' } );
		await waitFor( () => expect( optionReads() ).toHaveLength( 1 ) );
		fireEvent.focus( input );
		fireEvent.click( await within( dialog ).findByRole( 'button', { name: 'Ещё товары' } ) );
		await within( dialog ).findByRole( 'option', { name: 'Кружка красная (#12)' } );

		fireEvent.change( input, { target: { value: 'Фут' } } );
		await within( dialog ).findByRole( 'option', { name: 'Футболка (#21)' }, { timeout: SEARCH_DELAY_MS * 4 } );
		fireEvent.change( input, { target: { value: '' } } );
		await waitFor( () => expect( optionReads() ).toHaveLength( 4 ), { timeout: SEARCH_DELAY_MS * 4 } );

		// The empty search is read from its first page, and the list shows that page alone: nothing of the other search or of page 2.
		expect( optionReads()[ 3 ] ).not.toContain( 'cursor=' );
		expect( await within( dialog ).findByRole( 'option', { name: 'Кружка синяя (#11)' } ) ).toBeInTheDocument();
		expect( within( dialog ).queryByRole( 'option', { name: 'Футболка (#21)' } ) ).not.toBeInTheDocument();
		expect( within( dialog ).queryByRole( 'option', { name: 'Кружка красная (#12)' } ) ).not.toBeInTheDocument();
	} );

	test( 'shows that the list is being read while the server answers', async () => {
		let answer: ( value: unknown ) => void = () => undefined;
		const base = fetchMock.getMockImplementation() as ( request: { path: string } ) => Promise< unknown >;
		fetchMock.mockImplementation( ( request: { path: string } ) =>
			decodeURIComponent( request.path ).split( '?' )[ 0 ].endsWith( '/options' )
				? new Promise( ( resolve ) => {
						answer = resolve;
				  } )
				: base( request )
		);

		const dialog = await openDialog();
		const status = await within( dialog ).findByRole( 'status' );

		expect( status ).toBeInTheDocument();
		await act( async () => answer( { options: [], next_cursor: null } ) );
		await waitFor( () => expect( within( dialog ).queryByRole( 'status' ) ).not.toBeInTheDocument() );
	} );

	test( 'is one control: a labelled box with the placeholder, and the list of what the server found under it', async () => {
		const dialog = await openDialog();
		const input = await within( dialog ).findByRole( 'searchbox', { name: 'Товар сайта' } );

		expect( input ).toHaveAttribute( 'placeholder', 'Начните вводить название' );
		expect( within( dialog ).queryByRole( 'combobox' ) ).not.toBeInTheDocument();
		await waitFor( () => expect( optionReads() ).toHaveLength( 1 ) );

		fireEvent.focus( input );
		expect( await within( dialog ).findByRole( 'listbox', { name: 'Товар сайта' } ) ).toBeInTheDocument();
		expect( await within( dialog ).findByRole( 'option', { name: 'Кружка синяя (#11)' } ) ).toBeInTheDocument();
	} );

	test( 'a picked product is written into the box, closes the list and is what is sent', async () => {
		const dialog = await openDialog();
		const input = await within( dialog ).findByRole( 'searchbox', { name: 'Товар сайта' } );

		fireEvent.focus( input );
		fireEvent.click( await within( dialog ).findByRole( 'option', { name: 'Кружка синяя (#11)' } ) );

		expect( input ).toHaveValue( 'Кружка синяя (#11)' );
		expect( within( dialog ).queryByRole( 'listbox' ) ).not.toBeInTheDocument();
		fireEvent.click( within( dialog ).getByRole( 'button', { name: 'Проверить' } ) );
		await waitFor( () => expect( previews() ).toHaveLength( 1 ) );

		expect( previews()[ 0 ].data ).toMatchObject( { ids: [ 'c1' ], payload: { product: '11' } } );
	} );

	test( 'while the plan is being made the list cannot be used, so the box always names what is confirmed', async () => {
		let answer: ( plan: unknown ) => void = () => undefined;
		const base = fetchMock.getMockImplementation() as ( request: { path: string } ) => Promise< unknown >;
		fetchMock.mockImplementation( ( request: { path: string } ) =>
			decodeURIComponent( request.path ).split( '?' )[ 0 ].endsWith( '/preview' )
				? new Promise( ( resolve ) => {
						answer = resolve;
				  } )
				: base( request )
		);

		const dialog = await openDialog();
		const input = await within( dialog ).findByRole( 'searchbox', { name: 'Товар сайта' } );

		fireEvent.focus( input );
		fireEvent.click( await within( dialog ).findByRole( 'option', { name: 'Кружка синяя (#11)' } ) );
		fireEvent.focus( input ); // The merchant reopens the list, then asks for the plan.
		await within( dialog ).findByRole( 'option', { name: 'Кружка красная (#12)' } );
		fireEvent.click( within( dialog ).getByRole( 'button', { name: 'Проверить' } ) );

		// Planning: the list is gone, and a pick made on a list that was already in hand changes nothing.
		await waitFor( () => expect( within( dialog ).queryByRole( 'option' ) ).not.toBeInTheDocument() );
		expect( input ).toBeDisabled();
		expect( input ).toHaveValue( 'Кружка синяя (#11)' );

		await act( async () => answer( PLAN ) );
		fireEvent.click( await within( dialog ).findByRole( 'button', { name: 'Связать с…' } ) );
		await waitFor( () => expect( applies() ).toHaveLength( 1 ) );

		expect( input ).toHaveValue( 'Кружка синяя (#11)' );
		expect( previews()[ 0 ].data ).toMatchObject( { payload: { product: '11' } } );
		expect( applies()[ 0 ].data ).toMatchObject( { payload: { product: '11' } } );
	} );

	test( 'typing again drops the pick: the box no longer names it', async () => {
		const dialog = await openDialog();
		const input = await within( dialog ).findByRole( 'searchbox', { name: 'Товар сайта' } );

		fireEvent.focus( input );
		fireEvent.click( await within( dialog ).findByRole( 'option', { name: 'Кружка синяя (#11)' } ) );
		fireEvent.change( input, { target: { value: 'Футб' } } );
		fireEvent.click( within( dialog ).getByRole( 'button', { name: 'Проверить' } ) );

		await waitFor( () => expect( previews() ).toHaveLength( 1 ) );
		expect( previews()[ 0 ].data.payload ).toEqual( {} ); // The pick is gone: the server is asked with no product and refuses it.
		expect( await within( dialog ).findByRole( 'listbox', { name: 'Товар сайта' } ) ).toBeInTheDocument();
	} );

	test( 'lists what the server found for a several-word search, even when no label holds the whole phrase', async () => {
		const dialog = await openDialog();
		const input = await within( dialog ).findByRole( 'searchbox', { name: 'Товар сайта' } );
		await waitFor( () => expect( optionReads() ).toHaveLength( 1 ) );

		fireEvent.change( input, { target: { value: 'футболка красная' } } );
		await waitFor( () => expect( optionReads() ).toHaveLength( 2 ), { timeout: SEARCH_DELAY_MS * 4 } );

		// The server answered with the one product that has both words; its label has not "футболка красная" in it.
		expect( await within( dialog ).findByRole( 'option', { name: 'Футболка хлопковая красная (TSH-01)' } ) ).toBeInTheDocument();
	} );
} );
