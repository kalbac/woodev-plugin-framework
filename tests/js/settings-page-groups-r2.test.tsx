/**
 * Groups, round 2: a selector-backed action keeps its input inside a group card, and box presets render
 * inside their owning group's card (instead of being dropped from grouping).
 *
 * @see src/settings-page/group-card.tsx
 * @see src/settings-page/section-view.js
 */

import '@testing-library/jest-dom';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { useState } from '@wordpress/element';
import SectionView from '../../src/settings-page/section-view';
import { runTool } from '../../src/settings-page/rest';

jest.mock( '../../src/settings-page/rest', () => ( {
	runTool: jest.fn(),
	testConnection: jest.fn(),
	fetchSchema: jest.fn(),
	saveTab: jest.fn(),
} ) );

const field = ( name: string ) => ( { type: 'string', name, controlType: 'text', value: '', description: '', tooltip: '', placeholder: '' } );

const selector = {
	name: 'provider_id',
	description: 'Провайдер',
	placeholder: '',
	default: 'dadata',
	options: [ { value: 'dadata', label: 'DaData' }, { value: 'yandex', label: 'Yandex' } ],
};

const action = ( id: string, button: string, extra = {} ) => ( { id, name: button, desc: '', button, disabled: false, status_text: '', ...extra } );

const group = ( id: string, fields: string[], actions: string[] = [] ) => ( {
	id, title: `Группа ${ id }`, description: '', notice: '', fields, actions,
} );

function presetFields() {
	const fields: Record< string, any > = {};
	for ( const [ id, name, mode ] of [ [ 'M', 'Коробка M', 'carrier' ], [ 'L', 'Коробка L', 'merchant' ] ] ) {
		const preset = { id, name, cost_mode: mode, length: 30, width: 20, height: 15, max_weight: 20, box_weight: 0.2 };
		fields[ `box_${ id }_enabled` ] = { name, value: false, controlType: 'toggle', tooltip: '', box_preset: { ...preset, field: 'enabled' } };
		const role = 'carrier' === mode ? 'charge' : 'cost';
		fields[ `box_${ id }_${ role }` ] = { name: 'Стоимость', value: 'carrier' === mode ? true : '2%', controlType: 'carrier' === mode ? 'toggle' : 'text', tooltip: '', box_preset: { ...preset, field: role } };
	}
	return fields;
}

const PRESET_IDS = [ 'box_M_enabled', 'box_M_charge', 'box_L_enabled', 'box_L_cost' ];

// Renders the section with live values and records every change, so a payload can be compared across layouts.
function renderSection( section: Record< string, unknown > ) {
	const changed = jest.fn();
	function Form() {
		const [ values, setValues ] = useState< Record< string, string | boolean > >( {} );
		return (
			<SectionView
				providerId="cdek"
				section={ { id: 's', label: 'S', description: '', ...section } }
				values={ values }
				onFieldChange={ ( id: string, value: string | boolean ) => { changed( id, value ); setValues( ( prev ) => ( { ...prev, [ id ]: value } ) ); } }
				onFieldRevert={ jest.fn() }
			/>
		);
	}
	return { ...render( <Form /> ), changed };
}

const exercisePresets = ( changed: jest.Mock ) => {
	fireEvent.click( screen.getByRole( 'checkbox', { name: 'Использовать, Коробка M' } ) );
	fireEvent.click( screen.getByRole( 'checkbox', { name: 'Учитывать стоимость, Коробка M' } ) );
	fireEvent.change( screen.getByRole( 'textbox', { name: 'Стоимость, Коробка L' } ), { target: { value: '5%' } } );
	return changed.mock.calls.map( ( call ) => call.slice() );
};

beforeEach( () => {
	( runTool as jest.Mock ).mockReset();
} );

describe( 'a selector-backed action in a group', () => {
	const section = () => ( {
		fields: {},
		actions: [ action( 'check', 'Проверить', { selector } ), action( 'plain', 'Без выбора' ) ],
		groups: [ group( 'g', [], [ 'check', 'plain' ] ) ],
	} );

	it( 'renders its selector inside the card and sends the named arg with the run', async () => {
		( runTool as jest.Mock ).mockResolvedValue( { success: true, message: 'готово' } );
		const { container } = renderSection( section() );

		expect( container.querySelectorAll( '.woodev-group .woodev-tool__selector' ) ).toHaveLength( 1 );
		expect( screen.getByText( 'Провайдер' ) ).toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'button', { name: 'Проверить' } ) );
		expect( runTool ).toHaveBeenCalledWith( 'cdek', 'check', { provider_id: 'dadata' } );
		await waitFor( () => screen.getByText( 'готово' ) );

		fireEvent.click( screen.getByRole( 'button', { name: 'Без выбора' } ) );
		expect( runTool ).toHaveBeenLastCalledWith( 'cdek', 'plain', {} );
		await waitFor( () => expect( container.querySelectorAll( '.woodev-group__result' ) ).toHaveLength( 1 ) );
	} );

	it( 'sends the changed selection and clears the shared result when the selection changes', async () => {
		( runTool as jest.Mock ).mockResolvedValue( { success: true, message: 'Удалено: 37' } );
		const { container } = renderSection( section() );

		fireEvent.click( screen.getByRole( 'button', { name: 'Проверить' } ) );
		await waitFor( () => screen.getByText( 'Удалено: 37' ) );

		fireEvent.click( container.querySelector( '.woodev-select__trigger' ) as Element );
		await waitFor( () => expect( screen.getByText( 'Yandex' ) ).toBeTruthy() );
		fireEvent.click( screen.getByRole( 'option', { name: 'Yandex' } ) );

		await waitFor( () => expect( screen.queryByText( 'Удалено: 37' ) ).not.toBeInTheDocument() );

		fireEvent.click( screen.getByRole( 'button', { name: 'Проверить' } ) );
		expect( runTool ).toHaveBeenLastCalledWith( 'cdek', 'check', { provider_id: 'yandex' } );
		await waitFor( () => screen.getByText( 'Удалено: 37' ) );
	} );
} );

describe( 'box presets and groups', () => {
	it( 'renders a preset-only group as one card holding the table, and saves the same payload as ungrouped', () => {
		const ungrouped = renderSection( { fields: presetFields() } );
		expect( ungrouped.container.querySelector( '.woodev-group' ) ).toBeNull();
		const expected = exercisePresets( ungrouped.changed );
		ungrouped.unmount();

		const grouped = renderSection( { fields: presetFields(), groups: [ group( 'packing', PRESET_IDS ) ] } );
		const cards = grouped.container.querySelectorAll( '.woodev-group' );
		expect( cards ).toHaveLength( 1 );
		expect( cards[ 0 ] ).toHaveTextContent( 'Группа packing' );
		expect( cards[ 0 ].querySelectorAll( 'table' ) ).toHaveLength( 1 );
		expect( cards[ 0 ].querySelectorAll( 'tbody tr' ) ).toHaveLength( 2 ); // complete rows: both presets
		expect( grouped.container.querySelectorAll( 'table' ) ).toHaveLength( 1 ); // not duplicated outside

		expect( exercisePresets( grouped.changed ) ).toEqual( expected );
		expect( expected ).toEqual( [ [ 'box_M_enabled', true ], [ 'box_M_charge', false ], [ 'box_L_cost', '5%' ] ] );
	} );

	it( 'puts the table inside a group that mixes presets with an ordinary field', () => {
		const { container } = renderSection( {
			fields: { token: field( 'Токен' ), ...presetFields(), other: field( 'Прочее' ) },
			groups: [ group( 'mix', [ 'token', ...PRESET_IDS ] ) ],
		} );

		const card = container.querySelector( '.woodev-group' ) as Element;
		expect( card ).toHaveTextContent( 'Токен' );
		expect( card.querySelectorAll( 'table' ) ).toHaveLength( 1 );
		expect( container.querySelectorAll( 'table' ) ).toHaveLength( 1 );
		expect( card ).not.toHaveTextContent( 'Прочее' );
	} );

	it( 'leaves ungrouped presets in the shared table below the fields while another group stays a card', () => {
		const { container } = renderSection( {
			fields: { token: field( 'Токен' ), ...presetFields() },
			groups: [ group( 'g', [ 'token' ] ) ],
		} );

		expect( container.querySelectorAll( '.woodev-group table' ) ).toHaveLength( 0 );
		expect( container.querySelectorAll( 'table' ) ).toHaveLength( 1 );
		expect( container.innerHTML.indexOf( 'woodev-group' ) ).toBeLessThan( container.innerHTML.indexOf( '<table' ) );
	} );

	it( 'splits presets between their own groups and the loose table', () => {
		const { container } = renderSection( {
			fields: presetFields(),
			groups: [ group( 'only-M', [ 'box_M_enabled', 'box_M_charge' ] ) ],
		} );

		expect( container.querySelectorAll( '.woodev-group tbody tr' ) ).toHaveLength( 1 );
		expect( container.querySelectorAll( 'tbody tr' ) ).toHaveLength( 2 );
	} );
} );
