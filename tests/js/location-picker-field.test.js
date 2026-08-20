/**
 * Tests for the admin default-locality picker (issue #376, closes #370).
 *
 * Covers the pure helpers (`parseStoredRecord`, `namespaceRoot`), the
 * fetch/debounce contract against `GET woodev/v1/location/default-locality/suggest`,
 * selection storing the serialized record, and the empty/error states the
 * operator's own brief calls out: no results, a failing request, and a
 * stored value that no longer parses. `@wordpress/api-fetch` is mocked —
 * this suite never touches the network.
 *
 * @see src/components/location-picker-field.js
 * @see src/components/control-field.js
 */

import '@testing-library/jest-dom';
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import { createElement } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import LocationPickerField, {
	parseStoredRecord,
	namespaceRoot,
} from '../../src/components/location-picker-field';
import ControlField from '../../src/components/control-field';

jest.mock( '@wordpress/api-fetch' );

const SUGGEST_URL_PREFIX = 'https://example.test/wp-json/woodev/v1/location/default-locality/suggest';

beforeEach( () => {
	apiFetch.mockReset();
	window.woodevSettings = {
		restRoot: 'https://example.test/wp-json/woodev/v1/settings',
		nonce: 'nonce-123',
	};
} );

describe( 'namespaceRoot', () => {
	test( 'strips the trailing /settings segment', () => {
		expect( namespaceRoot( 'https://example.test/wp-json/woodev/v1/settings' ) ).toBe(
			'https://example.test/wp-json/woodev/v1'
		);
	} );

	test( 'is defensive against a missing/empty restRoot', () => {
		expect( namespaceRoot( '' ) ).toBe( '' );
		expect( namespaceRoot( undefined ) ).toBe( '' );
	} );
} );

describe( 'parseStoredRecord', () => {
	test( 'an empty value parses as empty', () => {
		expect( parseStoredRecord( '' ) ).toEqual( { state: 'empty' } );
		expect( parseStoredRecord( undefined ) ).toEqual( { state: 'empty' } );
	} );

	test( 'malformed JSON parses as broken', () => {
		expect( parseStoredRecord( 'not-json{{{' ) ).toEqual( { state: 'broken' } );
	} );

	test( 'well-formed JSON without a usable label parses as broken', () => {
		expect( parseStoredRecord( JSON.stringify( { key: 'dadata:1' } ) ) ).toEqual( { state: 'broken' } );
		expect( parseStoredRecord( JSON.stringify( 'a string, not a record' ) ) ).toEqual( { state: 'broken' } );
	} );

	test( 'a well-formed record parses as ok, carrying its label and key', () => {
		expect(
			parseStoredRecord( JSON.stringify( { key: 'dadata:city-1', label: 'Москва' } ) )
		).toEqual( { state: 'ok', label: 'Москва', key: 'dadata:city-1' } );
	} );
} );

describe( 'LocationPickerField', () => {
	test( 'an empty stored value renders the placeholder trigger', () => {
		render(
			createElement( LocationPickerField, { value: '', country: 'RU', onChange: () => {} } )
		);

		const trigger = screen.getByRole( 'button', { name: 'Выберите локацию…' } );
		expect( trigger.querySelector( '.woodev-select__value' ) ).toHaveClass( 'is-placeholder' );
	} );

	test( 'a valid stored record shows its label on the trigger', () => {
		render(
			createElement( LocationPickerField, {
				value: JSON.stringify( { key: 'dadata:city-1', label: 'Москва' } ),
				country: 'RU',
				onChange: () => {},
			} )
		);

		expect( screen.getByRole( 'button', { name: 'Москва' } ) ).toBeInTheDocument();
	} );

	test( 'a stored value that fails to parse renders a distinct broken-state trigger, never a silent placeholder', () => {
		render(
			createElement( LocationPickerField, {
				value: 'not-json{{{',
				country: 'RU',
				onChange: () => {},
			} )
		);

		const trigger = screen.getByRole( 'button', {
			name: 'Некорректное сохранённое значение — выберите заново',
		} );
		expect( trigger ).toHaveClass( 'is-broken' );
		// Never rendered as an unlabelled placeholder — the two states must stay visually distinct.
		expect( trigger.querySelector( '.woodev-select__value' ) ).not.toHaveClass( 'is-placeholder' );
	} );

	test( 'a query shorter than the minimum length shows a hint and issues no request', async () => {
		render(
			createElement( LocationPickerField, { value: '', country: 'RU', onChange: () => {} } )
		);

		fireEvent.click( screen.getByRole( 'button', { name: 'Выберите локацию…' } ) );

		const search = await screen.findByPlaceholderText( 'Начните вводить название…' );
		fireEvent.change( search, { target: { value: 'M' } } );

		expect( await screen.findByText( 'Введите минимум 2 символа для поиска' ) ).toBeInTheDocument();

		// Give the debounce window a chance to elapse — still must not fetch.
		await act( async () => {
			await new Promise( ( resolve ) => setTimeout( resolve, 350 ) );
		} );
		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	test( 'a query at the minimum length debounces a GET to the admin suggest route, scoped by level/country', async () => {
		apiFetch.mockResolvedValueOnce( {
			suggestions: [
				{ key: 'dadata:city-1', label: 'Москва', level: 'settlement', record: { key: 'dadata:city-1', label: 'Москва' } },
			],
		} );

		render(
			createElement( LocationPickerField, { value: '', country: 'KZ', onChange: () => {} } )
		);

		fireEvent.click( screen.getByRole( 'button', { name: 'Выберите локацию…' } ) );
		const search = await screen.findByPlaceholderText( 'Начните вводить название…' );
		fireEvent.change( search, { target: { value: 'Мос' } } );

		await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 1 ) );

		const call = apiFetch.mock.calls[ 0 ][ 0 ];
		expect( call.url.startsWith( SUGGEST_URL_PREFIX ) ).toBe( true );
		expect( call.method ).toBe( 'GET' );
		expect( call.headers ).toEqual( { 'X-WP-Nonce': 'nonce-123' } );

		const params = new URL( call.url ).searchParams;
		expect( params.get( 'q' ) ).toBe( 'Мос' );
		expect( params.get( 'level' ) ).toBe( 'settlement' );
		expect( params.get( 'country' ) ).toBe( 'KZ' );

		expect( await screen.findByRole( 'option', { name: 'Москва' } ) ).toBeInTheDocument();
	} );

	test( 'selecting a suggestion stores JSON.stringify(entry.record), not the label', async () => {
		const record = { key: 'dadata:city-1', provider_id: 'dadata', level: 'settlement', country: 'RU', label: 'Москва' };
		apiFetch.mockResolvedValueOnce( {
			suggestions: [ { key: 'dadata:city-1', label: 'Москва', level: 'settlement', record } ],
		} );

		const onChange = jest.fn();
		render(
			createElement( LocationPickerField, { value: '', country: 'RU', onChange } )
		);

		fireEvent.click( screen.getByRole( 'button', { name: 'Выберите локацию…' } ) );
		const search = await screen.findByPlaceholderText( 'Начните вводить название…' );
		fireEvent.change( search, { target: { value: 'Мос' } } );

		const option = await screen.findByRole( 'option', { name: 'Москва' } );
		fireEvent.click( option );

		expect( onChange ).toHaveBeenCalledWith( JSON.stringify( record ) );
	} );

	test( 'a completed search with zero results shows "Ничего не найдено", distinct from the short-query hint', async () => {
		apiFetch.mockResolvedValueOnce( { suggestions: [] } );

		render(
			createElement( LocationPickerField, { value: '', country: 'RU', onChange: () => {} } )
		);

		fireEvent.click( screen.getByRole( 'button', { name: 'Выберите локацию…' } ) );
		const search = await screen.findByPlaceholderText( 'Начните вводить название…' );
		fireEvent.change( search, { target: { value: 'Zzz' } } );

		expect( await screen.findByText( 'Ничего не найдено' ) ).toBeInTheDocument();
	} );

	test( 'a failed request shows a distinct error message, never mistaken for zero results', async () => {
		apiFetch.mockRejectedValueOnce( new Error( 'network down' ) );

		render(
			createElement( LocationPickerField, { value: '', country: 'RU', onChange: () => {} } )
		);

		fireEvent.click( screen.getByRole( 'button', { name: 'Выберите локацию…' } ) );
		const search = await screen.findByPlaceholderText( 'Начните вводить название…' );
		fireEvent.change( search, { target: { value: 'Мос' } } );

		expect(
			await screen.findByText( 'Не удалось загрузить подсказки. Попробуйте ещё раз.' )
		).toBeInTheDocument();
		expect( screen.queryByText( 'Ничего не найдено' ) ).not.toBeInTheDocument();
	} );

	test( 'a disabled control renders a disabled, unopenable trigger (D11)', () => {
		render(
			createElement( LocationPickerField, {
				value: '',
				country: 'RU',
				disabled: true,
				onChange: () => {},
			} )
		);

		const trigger = screen.getByRole( 'button', { name: 'Выберите локацию…' } );
		expect( trigger ).toBeDisabled();

		fireEvent.click( trigger );
		expect( screen.queryByPlaceholderText( 'Начните вводить название…' ) ).not.toBeInTheDocument();
	} );
} );

describe( 'ControlField dispatch (#376)', () => {
	test( 'a location-picker controlType renders LocationPickerField, wired to schema.country', async () => {
		apiFetch.mockResolvedValueOnce( { suggestions: [] } );

		render(
			createElement( ControlField, {
				schema: {
					type: 'string',
					controlType: 'location-picker',
					name: 'Зафиксированная локация',
					country: 'KZ',
				},
				value: '',
				onChange: () => {},
				showErrors: false,
			} )
		);

		fireEvent.click( screen.getByRole( 'button', { name: 'Выберите локацию…' } ) );
		const search = await screen.findByPlaceholderText( 'Начните вводить название…' );
		fireEvent.change( search, { target: { value: 'Ал' } } );

		await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 1 ) );
		const params = new URL( apiFetch.mock.calls[ 0 ][ 0 ].url ).searchParams;
		expect( params.get( 'country' ) ).toBe( 'KZ' );
	} );
} );
