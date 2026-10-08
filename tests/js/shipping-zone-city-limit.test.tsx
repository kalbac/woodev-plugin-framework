/**
 * The cities list of a shipping method's city limit (#1176): the pure list helpers, the list component, and the
 * mounting into the markup `City_Limit_Form::render()` prints (the zone modal inserts it after page load, so the
 * mount must repeat on `wc_backbone_modal_loaded` and let go of what the modal took away).
 *
 * `@wordpress/api-fetch` is mocked — the generalized picker never touches the network here.
 *
 * @see src/shipping-zone-city-limit
 */

import '@testing-library/jest-dom';
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import $ from 'jquery';
import apiFetch from '@wordpress/api-fetch';
import CityLimitList, {
	addCity,
	removeCity,
	serializeCities,
	cityLabel,
	MAX_CITIES,
	SEARCH_ENDPOINT,
	type CityItem,
	type CityLimitConfig,
} from '../../src/shipping-zone-city-limit/city-limit-list';
import { mountAll, readConfig, releaseDetached, start } from '../../src/shipping-zone-city-limit/mount';
import type { LocationRecord } from '../../src/components/location-picker';

jest.mock( '@wordpress/api-fetch', () => ( { __esModule: true, default: jest.fn() } ) );

const fetchMock = apiFetch as unknown as jest.Mock;

const record = ( key: string, label: string, extra: Record<string, unknown> = {} ): LocationRecord =>
	( {
		key,
		provider_id: key.split( ':' )[ 0 ],
		level: 'settlement',
		country: 'RU',
		label,
		settlement: { name: label, type: '' },
		...extra,
	} as LocationRecord );

const item = ( key: string, label: string, state: CityItem['state'] = 'ok' ): CityItem => ( { record: record( key, label ), state } );

const config = ( items: CityItem[] = [], extra: Partial<CityLimitConfig> = {} ): CityLimitConfig => ( {
	inputId: 'woocommerce_local_pickup_city_limit_cities',
	instanceId: 262,
	country: 'RU',
	active: true,
	restRoot: 'https://example.test/wp-json/woodev/v1',
	nonce: 'nonce-1',
	items,
	...extra,
} );

beforeEach( () => {
	fetchMock.mockReset();
	document.body.innerHTML = '';
	( window as unknown as { jQuery: unknown } ).jQuery = $;
} );

// ---- the pure helpers -------------------------------------------------------------------------------

test( 'a picked city is appended once, as a counted entry, without the provider payload', () => {
	const picked = record( 'test-cdek:394', 'Пушкин', { raw: { big: 'x'.repeat( 40 ) } } );
	const list = addCity( [], picked );

	expect( list ).toHaveLength( 1 );
	expect( list[ 0 ].state ).toBe( 'ok' );
	expect( list[ 0 ].record ).not.toHaveProperty( 'raw' );
	expect( addCity( list, picked ) ).toBe( list );
} );

test( 'the list stops growing at the cap the server enforces too', () => {
	let list: CityItem[] = [];

	for ( let i = 0; i < MAX_CITIES + 5; i++ ) {
		list = addCity( list, record( 'test-cdek:' + i, 'Город ' + i ) );
	}

	expect( list ).toHaveLength( MAX_CITIES );
} );

test( 'a city is removed by key', () => {
	const list = [ item( 'test-cdek:1', 'Один' ), item( 'test-cdek:2', 'Два' ) ];

	expect( removeCity( list, 'test-cdek:1' ).map( ( entry ) => entry.record.key ) ).toEqual( [ 'test-cdek:2' ] );
	expect( removeCity( list, 'nope' ) ).toHaveLength( 2 );
} );

test( 'the hidden input carries whole records as JSON — what the server re-resolves by name later', () => {
	const json = serializeCities( [ item( 'test-cdek:1', 'Один' ) ] );
	const back = JSON.parse( json );

	expect( back ).toHaveLength( 1 );
	expect( back[ 0 ] ).toMatchObject( { key: 'test-cdek:1', provider_id: 'test-cdek', level: 'settlement', country: 'RU', label: 'Один' } );
	expect( serializeCities( [] ) ).toBe( '[]' );
} );

test( 'a chip reads its label, then the settlement name, then the key', () => {
	expect( cityLabel( record( 'a:1', 'Пушкин' ) ) ).toBe( 'Пушкин' );
	expect( cityLabel( { ...record( 'a:1', '' ), label: '', settlement: { name: 'Репино', type: '' } } as LocationRecord ) ).toBe( 'Репино' );
	expect( cityLabel( { key: 'a:1' } as LocationRecord ) ).toBe( 'a:1' );
} );

// ---- the component ----------------------------------------------------------------------------------

test( 'the chips show the cities and mark the ones the server ignores', () => {
	render(
		<CityLimitList
			config={ config( [ item( 'test-cdek:1', 'Один' ), item( 'dadata:2', 'Два', 'stale' ), item( 'test-cdek:3', 'Три', 'outside' ) ] ) }
			onChange={ jest.fn() }
		/>
	);

	const chips = document.querySelectorAll( '.woodev-city-limit__chip' );

	expect( chips ).toHaveLength( 3 );
	expect( chips[ 0 ] ).toHaveClass( 'is-ok' );
	expect( chips[ 1 ] ).toHaveClass( 'is-stale' );
	expect( chips[ 1 ] ).toHaveAttribute( 'title', expect.stringContaining( 'другого сервиса' ) );
	expect( chips[ 2 ] ).toHaveClass( 'is-outside' );
	expect( chips[ 2 ] ).toHaveAttribute( 'title', expect.stringContaining( 'не входит в регионы' ) );
	expect( chips[ 0 ] ).not.toHaveAttribute( 'title' );
} );

test( 'removing a chip reports the shorter list', () => {
	const onChange = jest.fn();

	render( <CityLimitList config={ config( [ item( 'test-cdek:1', 'Один' ), item( 'test-cdek:2', 'Два' ) ] ) } onChange={ onChange } /> );

	fireEvent.click( screen.getByRole( 'button', { name: 'Удалить город: Один' } ) );

	expect( onChange ).toHaveBeenCalledTimes( 1 );
	expect( JSON.parse( onChange.mock.calls[ 0 ][ 0 ] ).map( ( r: LocationRecord ) => r.key ) ).toEqual( [ 'test-cdek:2' ] );
	expect( document.querySelectorAll( '.woodev-city-limit__chip' ) ).toHaveLength( 1 );
} );

test( 'a city picked in the search is added, searching the zone through the method instance', async () => {
	jest.useFakeTimers();

	const onChange = jest.fn();
	const pushkin = record( 'test-cdek:394', 'Пушкин' );

	fetchMock.mockResolvedValue( { suggestions: [ { key: pushkin.key, label: 'Пушкин', level: 'settlement', record: pushkin } ] } );

	render( <CityLimitList config={ config( [] ) } onChange={ onChange } /> );

	fireEvent.click( screen.getByRole( 'button', { name: 'Добавить город…' } ) );
	fireEvent.change( await screen.findByPlaceholderText( 'Начните вводить название…' ), { target: { value: 'Пуш' } } );
	await act( async () => {
		jest.advanceTimersByTime( 400 );
	} );

	await waitFor( () => expect( fetchMock ).toHaveBeenCalled() );

	const url = new URL( fetchMock.mock.calls[ 0 ][ 0 ].url );

	expect( url.pathname ).toBe( '/wp-json/woodev/v1' + SEARCH_ENDPOINT );
	expect( url.searchParams.get( 'instance_id' ) ).toBe( '262' );
	expect( url.searchParams.get( 'q' ) ).toBe( 'Пуш' );
	expect( url.searchParams.get( 'level' ) ).toBe( 'settlement' );
	expect( url.searchParams.get( 'country' ) ).toBe( 'RU' );
	expect( fetchMock.mock.calls[ 0 ][ 0 ].headers ).toEqual( { 'X-WP-Nonce': 'nonce-1' } );

	fireEvent.click( await screen.findByRole( 'option', { name: 'Пушкин' } ) );

	expect( onChange ).toHaveBeenCalledTimes( 1 );
	expect( JSON.parse( onChange.mock.calls[ 0 ][ 0 ] ).map( ( r: LocationRecord ) => r.key ) ).toEqual( [ 'test-cdek:394' ] );
	expect( document.querySelectorAll( '.woodev-city-limit__chip' ) ).toHaveLength( 1 );

	jest.useRealTimers();
} );

test( 'with the location layer unusable the picker is disabled rather than silent', () => {
	render( <CityLimitList config={ config( [], { active: false } ) } onChange={ jest.fn() } /> );

	expect( screen.getByRole( 'button', { name: 'Добавить город…' } ) ).toBeDisabled();
} );

// ---- the mount --------------------------------------------------------------------------------------

/** React 18 renders a root asynchronously; the mount is a state update as far as the test is concerned. */
const mount = (): void => {
	act( () => {
		mountAll();
	} );
};

/** The markup `City_Limit_Form::render()` prints, as far as the mount cares. */
function printRow( cfg: CityLimitConfig, id = 'row' ): HTMLElement {
	const row = document.createElement( 'div' );

	row.id = id;
	row.innerHTML =
		`<input type="hidden" id="${ cfg.inputId }" value="[]" />` +
		`<div class="woodev-city-limit" data-config='${ JSON.stringify( cfg ).replace( /'/g, '&#39;' ) }'><ul class="woodev-city-limit__static"><li>no script</li></ul></div>`;
	document.body.appendChild( row );

	return row;
}

test( 'the mount reads the printed config and refuses a broken one', () => {
	const row = printRow( config( [ item( 'test-cdek:1', 'Один' ) ] ) );

	expect( readConfig( row.querySelector( '.woodev-city-limit' ) as Element ) ).toMatchObject( { instanceId: 262, items: [ { state: 'ok' } ] } );

	const broken = document.createElement( 'div' );

	broken.setAttribute( 'data-config', 'not json' );
	expect( readConfig( broken ) ).toBeNull();
	broken.setAttribute( 'data-config', '{"inputId":5}' );
	expect( readConfig( broken ) ).toBeNull();
	expect( readConfig( document.createElement( 'div' ) ) ).toBeNull();
} );

test( 'mounting replaces the no-script list with the control and writes picks to the form input', () => {
	const cfg = config( [ item( 'test-cdek:1', 'Один' ) ] );

	printRow( cfg );
	mount();

	expect( document.querySelector( '.woodev-city-limit__static' ) ).toBeNull();
	expect( document.querySelectorAll( '.woodev-city-limit__chip' ) ).toHaveLength( 1 );

	const input = document.getElementById( cfg.inputId ) as HTMLInputElement;
	const changed = jest.fn();

	input.addEventListener( 'change', changed );
	act( () => {
		fireEvent.click( screen.getByRole( 'button', { name: 'Удалить город: Один' } ) );
	} );

	expect( input.value ).toBe( '[]' );
	expect( changed ).toHaveBeenCalledTimes( 1 );

	// the show-if script and WooCommerce's modal listen on the document, so the event must bubble
	const onDocument = jest.fn();

	document.addEventListener( 'change', onDocument );
	act( () => {
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	} );
	document.removeEventListener( 'change', onDocument );
	expect( onDocument ).toHaveBeenCalled();
} );

test( 'a second pass mounts nothing twice, and a new modal row is picked up', () => {
	printRow( config( [ item( 'test-cdek:1', 'Один' ) ] ), 'one' );
	mount();
	mount();

	expect( document.querySelectorAll( '.woodev-city-limit__chip' ) ).toHaveLength( 1 );

	printRow( config( [ item( 'test-cdek:2', 'Два' ) ], { inputId: 'second_input' } ), 'two' );
	mount();

	expect( document.querySelectorAll( '.woodev-city-limit__chip' ) ).toHaveLength( 2 );
} );

test( 'a row without its input is left as the server printed it', () => {
	const cfg = config( [ item( 'test-cdek:1', 'Один' ) ] );

	printRow( cfg );
	( document.getElementById( cfg.inputId ) as HTMLElement ).remove();
	mount();

	expect( document.querySelector( '.woodev-city-limit__static' ) ).not.toBeNull();
} );

test( 'roots of a closed modal are released and the same row can be mounted again', () => {
	const row = printRow( config( [ item( 'test-cdek:1', 'Один' ) ] ) );

	mount();
	expect( document.querySelectorAll( '.woodev-city-limit__chip' ) ).toHaveLength( 1 );

	row.remove();
	act( () => {
		releaseDetached();
	} );

	printRow( config( [ item( 'test-cdek:1', 'Один' ) ] ) );
	mount();

	expect( document.querySelectorAll( '.woodev-city-limit__chip' ) ).toHaveLength( 1 );
} );

test( 'the modal opening (a jQuery event on the body) mounts the freshly inserted row', () => {
	act( () => {
		start();
	} );
	expect( document.querySelector( '.woodev-city-limit__chip' ) ).toBeNull();

	printRow( config( [ item( 'test-cdek:1', 'Один' ) ] ) );
	act( () => {
		$( document.body ).trigger( 'wc_backbone_modal_loaded' );
	} );

	expect( document.querySelectorAll( '.woodev-city-limit__chip' ) ).toHaveLength( 1 );
} );
