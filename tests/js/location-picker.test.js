/**
 * Tests for the generalized location picker (#960, increment I4a of #710).
 *
 * `location-picker-field.test.js` keeps covering the settings-page face of the same
 * machinery (the admin route, the stored-JSON value, the provider follow-through); this
 * suite covers what the generalization added — `level` / `within` / `restRoot` /
 * `endpoint` / `params` as props, no dependence on `window.woodevSettings`, the region
 * and address levels, and the pure postcode-fill derivation. `@wordpress/api-fetch` is
 * mocked — it never touches the network.
 *
 * @see src/components/location-picker.tsx
 */

import '@testing-library/jest-dom';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { createElement } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import LocationPicker, { fillFromRecord } from '../../src/components/location-picker';

jest.mock( '@wordpress/api-fetch' );

const REST_ROOT = 'https://example.test/wp-json/woodev/v1';

const settlementRecord = {
	key: 'dadata:city-1',
	provider_id: 'dadata',
	level: 'settlement',
	country: 'RU',
	label: 'Московская обл., г Жуковский',
	region: { name: 'Московская', type: 'обл' },
	settlement: { name: 'Жуковский', type: 'г' },
	postcode: '140180',
};

const addressRecord = {
	key: 'dadata:addr-1',
	provider_id: 'dadata',
	level: 'address',
	country: 'RU',
	label: 'Московская обл., г Жуковский, ул Гагарина, д 1',
	region: { name: 'Московская', type: 'обл' },
	settlement: { name: 'Жуковский', type: 'г' },
	street: { name: 'Гагарина', type: 'ул' },
	house: '1',
	block: 'к2',
	postcode: '140185',
};

const suggestion = ( record ) => ( { key: record.key, label: record.label, level: record.level, record } );

const renderPicker = ( props = {} ) =>
	render(
		createElement( LocationPicker, {
			value: null,
			level: 'settlement',
			country: 'RU',
			restRoot: REST_ROOT,
			onChange: () => {},
			...props,
		} )
	);

const typeQuery = async ( text, triggerName = 'Выберите локацию…' ) => {
	fireEvent.click( screen.getByRole( 'button', { name: triggerName } ) );
	const search = await screen.findByPlaceholderText( 'Начните вводить название…' );
	fireEvent.change( search, { target: { value: text } } );
};

beforeEach( () => {
	apiFetch.mockReset();
	// The picker must not need the settings page's global — make any read of it visible.
	delete window.woodevSettings;
} );

describe( 'fillFromRecord', () => {
	test( 'a settlement record fills region, settlement and postcode by bare name, never the label', () => {
		expect( fillFromRecord( settlementRecord ) ).toEqual( {
			region: 'Московская',
			settlement: 'Жуковский',
			postcode: '140180',
		} );
	} );

	test( 'an address record also fills the address: street WITH its type, then house and block', () => {
		expect( fillFromRecord( addressRecord ) ).toEqual( {
			region: 'Московская',
			settlement: 'Жуковский',
			address: 'ул Гагарина, 1, к2',
			postcode: '140185',
		} );
	} );

	test( 'a region record fills only the region — never a level below it', () => {
		expect(
			fillFromRecord( {
				key: 'dadata:reg-1',
				level: 'region',
				label: 'Московская обл.',
				region: { name: 'Московская', type: 'обл' },
			} )
		).toEqual( { region: 'Московская' } );
	} );

	test( 'no postcode key at all when the record carries none (or a blank one)', () => {
		const { postcode, ...noPostcode } = settlementRecord; // eslint-disable-line no-unused-vars

		expect( fillFromRecord( noPostcode ) ).not.toHaveProperty( 'postcode' );
		expect( fillFromRecord( { ...settlementRecord, postcode: '  ' } ) ).not.toHaveProperty( 'postcode' );
	} );

	test( 'an ancestor the record does not carry is left out, not blanked', () => {
		const { region, ...noRegion } = settlementRecord; // eslint-disable-line no-unused-vars

		expect( fillFromRecord( noRegion ) ).toEqual( { settlement: 'Жуковский', postcode: '140180' } );
	} );

	test( "the record's own level falls back to its label when it has no component (a blank field reads as a failed pick)", () => {
		expect(
			fillFromRecord( { key: 'p:1', level: 'settlement', label: 'Жуковский', postcode: '140180' } )
		).toEqual( { settlement: 'Жуковский', postcode: '140180' } );
	} );

	test( 'a record with no level still yields its postcode, and nothing positional', () => {
		expect( fillFromRecord( { key: 'p:1', postcode: '140180' } ) ).toEqual( { postcode: '140180' } );
	} );
} );

describe( 'LocationPicker', () => {
	test( 'nothing picked -> the placeholder trigger; a custom placeholder overrides the default', () => {
		const { unmount } = renderPicker();
		expect( screen.getByRole( 'button', { name: 'Выберите локацию…' } ).querySelector( '.woodev-select__value' ) )
			.toHaveClass( 'is-placeholder' );
		unmount();

		renderPicker( { placeholder: 'Населённый пункт' } );
		expect( screen.getByRole( 'button', { name: 'Населённый пункт' } ) ).toBeInTheDocument();
	} );

	test( 'a picked value shows its label; broken shows the distinct state, never the placeholder', () => {
		const { unmount } = renderPicker( { value: { key: 'dadata:city-1', label: 'Жуковский' } } );
		expect( screen.getByRole( 'button', { name: 'Жуковский' } ) ).toBeInTheDocument();
		unmount();

		renderPicker( { broken: true } );
		const trigger = screen.getByRole( 'button', { name: 'Некорректное сохранённое значение — выберите заново' } );
		expect( trigger ).toHaveClass( 'is-broken' );
	} );

	test( 'searches the stateless public /location/suggest under the given restRoot, at the given level', async () => {
		apiFetch.mockResolvedValueOnce( { suggestions: [ suggestion( addressRecord ) ] } );

		renderPicker( { level: 'address', country: 'KZ' } );
		await typeQuery( 'Гага' );

		await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 1 ) );

		const call = apiFetch.mock.calls[ 0 ][ 0 ];
		expect( call.url.startsWith( `${ REST_ROOT }/location/suggest?` ) ).toBe( true );
		expect( call.method ).toBe( 'GET' );

		const params = new URL( call.url ).searchParams;
		expect( params.get( 'q' ) ).toBe( 'Гага' );
		expect( params.get( 'level' ) ).toBe( 'address' );
		expect( params.get( 'country' ) ).toBe( 'KZ' );
		expect( params.has( 'within' ) ).toBe( false );
		expect( params.has( 'provider' ) ).toBe( false );
	} );

	test( 'never touches the customer-location store routes (/select, /forget) from a search or a pick', async () => {
		apiFetch.mockResolvedValue( { suggestions: [ suggestion( settlementRecord ) ] } );

		renderPicker();
		await typeQuery( 'Жуко' );
		fireEvent.click( await screen.findByRole( 'option', { name: settlementRecord.label } ) );

		await waitFor( () => expect( apiFetch ).toHaveBeenCalled() );
		apiFetch.mock.calls.forEach( ( [ options ] ) => {
			expect( options.url ).not.toMatch( /\/location\/(select|forget)/ );
			expect( options.method ).toBe( 'GET' );
		} );
	} );

	test( 'within is sent as the parent locality key, and only when given', async () => {
		apiFetch.mockResolvedValueOnce( { suggestions: [] } );

		renderPicker( { level: 'address', within: 'dadata:city-1' } );
		await typeQuery( 'Гага' );

		await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 1 ) );
		expect( new URL( apiFetch.mock.calls[ 0 ][ 0 ].url ).searchParams.get( 'within' ) ).toBe( 'dadata:city-1' );
	} );

	test( 'a changed within re-issues the current search immediately, without a new keystroke', async () => {
		apiFetch.mockResolvedValue( { suggestions: [] } );

		const props = { value: null, level: 'address', country: 'RU', restRoot: REST_ROOT, onChange: () => {} };
		const { rerender } = render( createElement( LocationPicker, { ...props, within: 'dadata:city-1' } ) );
		await typeQuery( 'Гага' );

		await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 1 ) );

		rerender( createElement( LocationPicker, { ...props, within: 'dadata:city-2' } ) );

		await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 2 ) );
		expect( new URL( apiFetch.mock.calls[ 1 ][ 0 ].url ).searchParams.get( 'within' ) ).toBe( 'dadata:city-2' );
	} );

	test( 'a same-content params object does not re-fire the search on re-render', async () => {
		apiFetch.mockResolvedValue( { suggestions: [] } );

		const props = { value: null, level: 'settlement', country: 'RU', restRoot: REST_ROOT, onChange: () => {} };
		const { rerender } = render( createElement( LocationPicker, { ...props, params: { provider: 'a' } } ) );
		await typeQuery( 'Мос' );
		await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 1 ) );

		rerender( createElement( LocationPicker, { ...props, params: { provider: 'a' } } ) );
		await new Promise( ( resolve ) => setTimeout( resolve, 400 ) );

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'endpoint and params redirect the request (the settings page asks its admin route with a provider)', async () => {
		apiFetch.mockResolvedValueOnce( { suggestions: [] } );

		renderPicker( { endpoint: '/location/default-locality/suggest', params: { provider: 'dadata' }, nonce: 'n-1' } );
		await typeQuery( 'Мос' );

		await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 1 ) );

		const call = apiFetch.mock.calls[ 0 ][ 0 ];
		expect( call.url.startsWith( `${ REST_ROOT }/location/default-locality/suggest?` ) ).toBe( true );
		expect( new URL( call.url ).searchParams.get( 'provider' ) ).toBe( 'dadata' );
		expect( call.headers ).toEqual( { 'X-WP-Nonce': 'n-1' } );
	} );

	test( 'no nonce -> no X-WP-Nonce header at all', async () => {
		apiFetch.mockResolvedValueOnce( { suggestions: [] } );

		renderPicker();
		await typeQuery( 'Мос' );

		await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 1 ) );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].headers ).toEqual( {} );
	} );

	test( 'a pick hands the whole suggestion and its derived fill (postcode included) to onChange', async () => {
		apiFetch.mockResolvedValueOnce( { suggestions: [ suggestion( settlementRecord ) ] } );

		const onChange = jest.fn();
		renderPicker( { onChange } );
		await typeQuery( 'Жуко' );

		fireEvent.click( await screen.findByRole( 'option', { name: settlementRecord.label } ) );

		expect( onChange ).toHaveBeenCalledTimes( 1 );
		expect( onChange ).toHaveBeenCalledWith(
			suggestion( settlementRecord ),
			{ region: 'Московская', settlement: 'Жуковский', postcode: '140180' }
		);
	} );

	test( 'a failing request renders the error row, distinct from "nothing found"', async () => {
		apiFetch.mockRejectedValueOnce( new Error( 'boom' ) );

		renderPicker();
		await typeQuery( 'Мос' );

		expect( await screen.findByText( 'Не удалось загрузить подсказки. Попробуйте ещё раз.' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Ничего не найдено' ) ).toBeNull();
	} );

	test( 'a completed search with zero suggestions renders "Ничего не найдено"', async () => {
		apiFetch.mockResolvedValueOnce( { suggestions: [] } );

		renderPicker();
		await typeQuery( 'Мос' );

		expect( await screen.findByText( 'Ничего не найдено' ) ).toBeInTheDocument();
	} );

	test( 'the picked entry is marked selected in the list', async () => {
		apiFetch.mockResolvedValueOnce( { suggestions: [ suggestion( settlementRecord ) ] } );

		renderPicker( { value: { key: settlementRecord.key, label: 'Жуковский' } } );
		await typeQuery( 'Жуко', 'Жуковский' );

		const option = await screen.findByRole( 'option', { name: settlementRecord.label } );
		expect( option ).toHaveAttribute( 'aria-selected', 'true' );
		expect( option ).toHaveClass( 'is-selected' );
	} );
} );
