import '@testing-library/jest-dom';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useSyncExternalStore as mockUseSyncExternalStore } from 'react';

/*
 * An in-memory stand-in for `wc/store/cart` + `wc/store/checkout`. It is the seam the chooser talks to
 * (`wc-stores.ts` is a thin wrapper over `@wordpress/data`'s `select`/`dispatch`/`useSelect`), so the
 * tests claim exactly what the chooser does to the NATIVE address — never what WooCommerce does with it.
 */
const mockStore = {
	customer: {
		shippingAddress: { first_name: 'Анна', address_1: 'Ленина 1', city: '', state: '', country: 'RU' },
		billingAddress: { first_name: 'Анна', address_1: 'Ленина 1', city: '', state: '', country: 'RU' },
	},
	useShippingAsBilling: false,
	listeners: new Set< () => void >(),
	setShippingAddress: jest.fn(),
	setBillingAddress: jest.fn(),
	invalidateResolutionForStoreSelector: jest.fn(),
};

const notify = (): void => mockStore.listeners.forEach( ( listener ) => listener() );

jest.mock( '@wordpress/data', () => {
	const selectFn = () => ( {
		getCustomerData: () => mockStore.customer,
		getUseShippingAsBilling: () => mockStore.useShippingAsBilling,
	} );

	return {
		select: selectFn,
		dispatch: () => ( {
			setShippingAddress: ( address: Record< string, string > ) => {
				mockStore.setShippingAddress( address );
				mockStore.customer = { ...mockStore.customer, shippingAddress: address as never };
				notify();
			},
			setBillingAddress: ( address: Record< string, string > ) => {
				mockStore.setBillingAddress( address );
				mockStore.customer = { ...mockStore.customer, billingAddress: address as never };
				notify();
			},
			invalidateResolutionForStoreSelector: mockStore.invalidateResolutionForStoreSelector,
		} ),
		useSelect: ( mapper: ( select: typeof selectFn ) => unknown ) =>
			mockUseSyncExternalStore(
				( listener ) => {
					mockStore.listeners.add( listener );
					return () => mockStore.listeners.delete( listener );
				},
				() => mapper( selectFn )
			),
	};
} );

// eslint-disable-next-line import/first
import { LocalityChooser } from '../../src/checkout-blocks/locality-chooser';
// eslint-disable-next-line import/first
import { registerLocalityBlock } from '../../src/checkout-blocks/register';
// eslint-disable-next-line import/first
import type { LocationConfig } from '../../src/checkout-blocks/types';

const RU_STATES = { 'МОСКОВСКАЯ ОБЛАСТЬ': 'Московская область', 'МОСКВА': 'Москва' };

const baseConfig = ( over: Partial< LocationConfig > = {} ): LocationConfig => ( {
	endpoints: {
		suggest: 'https://shop.test/wp-json/woodev/v1/location/suggest',
		select: 'https://shop.test/wp-json/woodev/v1/location/select',
		list: 'https://shop.test/wp-json/woodev/v1/location/list',
		forget: 'https://shop.test/wp-json/woodev/v1/location/forget',
	},
	nonce: 'rest-nonce',
	countries: [ 'RU' ],
	levels: { RU: { region: false, settlement: true, address: true } },
	regionFieldRemoved: false,
	current: null,
	chain: [],
	implicit: false,
	savedCityUnresolved: null,
	i18n: {
		label: 'Find your locality',
		hint: 'Choose a locality to fill in the city and region.',
		searching: 'Searching…',
		listLabel: 'Locality suggestions',
		clear: 'Clear the chosen locality',
		noResults: 'No results found.',
		unavailable: 'Suggestions unavailable.',
		notPersisted: 'Could not save your choice — please try again.',
		regionNotSet: 'The region could not be matched.',
		pickFromSuggestions: 'Select a locality from the suggestions',
	},
	...over,
} );

const podolsk = {
	key: 'dadata:podolsk',
	label: 'Подольск, Московская область',
	level: 'settlement',
	record: {
		key: 'dadata:podolsk',
		provider_id: 'dadata',
		level: 'settlement',
		country: 'RU',
		region: { name: 'Московская область' },
		settlement: { name: 'Подольск' },
	},
};

let fetchCalls: Array< { url: string; init?: RequestInit } > = [];
let selectReply: Record< string, unknown > = { persisted: true };

function mockNetwork( suggestions: unknown[] = [ podolsk ] ): void {
	fetchCalls = [];
	global.fetch = jest.fn( async ( input: RequestInfo | URL, init?: RequestInit ) => {
		const url = String( input );
		fetchCalls.push( { url, init } );

		if ( url.includes( '/location/suggest' ) ) {
			return { ok: true, status: 200, json: async () => ( { suggestions } ) } as Response;
		}

		if ( url.includes( '/location/select' ) ) {
			return { ok: true, status: 200, json: async () => selectReply } as Response;
		}

		return { ok: true, status: 200, json: async () => ( {} ) } as Response;
	} ) as typeof fetch;
}

async function chooseFirstSuggestion(): Promise< void > {
	fireEvent.change( screen.getByRole( 'combobox' ), { target: { value: 'Подол' } } );
	const option = await screen.findByRole( 'option', {}, { timeout: 2000 } );
	fireEvent.mouseDown( option );
}

beforeEach( () => {
	mockStore.customer = {
		shippingAddress: { first_name: 'Анна', address_1: 'Ленина 1', city: '', state: '', country: 'RU' },
		billingAddress: { first_name: 'Анна', address_1: 'Ленина 1', city: '', state: '', country: 'RU' },
	};
	mockStore.useShippingAsBilling = false;
	mockStore.listeners.clear();
	mockStore.setShippingAddress.mockClear();
	mockStore.setBillingAddress.mockClear();
	mockStore.invalidateResolutionForStoreSelector.mockClear();
	selectReply = { persisted: true };
	( window as unknown as { wc: unknown } ).wc = {
		wcSettings: {
			getSetting: ( name: string, fallback: unknown ) =>
				( { countryData: { RU: { states: RU_STATES } } } as Record< string, unknown > )[ name ] ?? fallback,
		},
	};
	mockNetwork();
} );

describe( 'LocalityChooser — provider absence and fallback', () => {
	it( 'renders nothing for a country the provider chain does not serve, so the native fields work alone', () => {
		mockStore.customer.shippingAddress = { ...mockStore.customer.shippingAddress, country: 'DE' };

		const { container } = render( <LocalityChooser config={ baseConfig() } /> );

		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'renders nothing when the country is listed but no provider serves the settlement level', () => {
		const config = baseConfig( { levels: { RU: { region: false, settlement: false, address: true } } } );

		const { container } = render( <LocalityChooser config={ config } /> );

		expect( container ).toBeEmptyDOMElement();
		expect( global.fetch ).not.toHaveBeenCalled();
	} );

	it( 'shows the chooser for a served country', () => {
		render( <LocalityChooser config={ baseConfig() } /> );

		expect( screen.getByLabelText( 'Find your locality' ) ).toBeInTheDocument();
	} );

	it( 'says so — and writes nothing — when the suggestion service is down', async () => {
		global.fetch = jest.fn( async () => ( { ok: false, status: 502, json: async () => ( {} ) } ) as Response ) as typeof fetch;

		render( <LocalityChooser config={ baseConfig() } /> );
		fireEvent.change( screen.getByRole( 'combobox' ), { target: { value: 'Подол' } } );

		expect( await screen.findByText( 'Suggestions unavailable.', {}, { timeout: 2000 } ) ).toBeInTheDocument();
		expect( mockStore.setShippingAddress ).not.toHaveBeenCalled();
	} );
} );

describe( 'LocalityChooser — choosing a locality', () => {
	it( 'persists the record, then writes the native City and a real State into the current address', async () => {
		render( <LocalityChooser config={ baseConfig() } /> );
		await chooseFirstSuggestion();

		await waitFor( () => expect( mockStore.setShippingAddress ).toHaveBeenCalledTimes( 1 ) );

		const select = fetchCalls.find( ( call ) => call.url.includes( '/location/select' ) );
		expect( select?.init?.method ).toBe( 'POST' );
		expect( ( select?.init?.headers as Record< string, string > )[ 'X-WP-Nonce' ] ).toBe( 'rest-nonce' );
		expect( JSON.parse( String( select?.init?.body ) ) ).toEqual( { record: podolsk.record } );

		// Merged into the CURRENT address: the street and the name survive.
		expect( mockStore.setShippingAddress ).toHaveBeenCalledWith( {
			first_name: 'Анна',
			address_1: 'Ленина 1',
			city: 'Подольск',
			state: 'МОСКОВСКАЯ ОБЛАСТЬ',
			country: 'RU',
		} );
		expect( mockStore.setBillingAddress ).not.toHaveBeenCalled();
	} );

	it( 'asks the server BEFORE it touches the native address, so the rates see the saved locality', async () => {
		const order: string[] = [];
		const network = global.fetch;
		global.fetch = jest.fn( async ( input: RequestInfo | URL, init?: RequestInit ) => {
			if ( String( input ).includes( '/location/select' ) ) {
				order.push( 'select' );
			}
			return network( input, init );
		} ) as typeof fetch;
		mockStore.setShippingAddress.mockImplementation( () => order.push( 'native-write' ) );

		render( <LocalityChooser config={ baseConfig() } /> );
		await chooseFirstSuggestion();
		await waitFor( () => expect( order ).toEqual( [ 'select', 'native-write' ] ) );
	} );

	it( 'mirrors the locality into billing when core says shipping is billing', async () => {
		mockStore.useShippingAsBilling = true;

		render( <LocalityChooser config={ baseConfig() } /> );
		await chooseFirstSuggestion();

		await waitFor( () => expect( mockStore.setBillingAddress ).toHaveBeenCalledTimes( 1 ) );
		expect( mockStore.setBillingAddress ).toHaveBeenCalledWith(
			expect.objectContaining( { city: 'Подольск', state: 'МОСКОВСКАЯ ОБЛАСТЬ' } )
		);
	} );

	it( 'leaves the state alone when the region field is removed', async () => {
		mockStore.customer.shippingAddress = { ...mockStore.customer.shippingAddress, state: 'МОСКВА' };

		render( <LocalityChooser config={ baseConfig( { regionFieldRemoved: true } ) } /> );
		await chooseFirstSuggestion();

		await waitFor( () => expect( mockStore.setShippingAddress ).toHaveBeenCalledTimes( 1 ) );
		expect( mockStore.setShippingAddress ).toHaveBeenCalledWith( expect.objectContaining( { city: 'Подольск', state: 'МОСКВА' } ) );
	} );

	it( 'does not write the native address when the server did not keep the choice', async () => {
		selectReply = { persisted: false };

		render( <LocalityChooser config={ baseConfig() } /> );
		await chooseFirstSuggestion();

		expect( await screen.findByText( 'Could not save your choice — please try again.' ) ).toBeInTheDocument();
		expect( mockStore.setShippingAddress ).not.toHaveBeenCalled();
	} );

	it( 'does not write the native address for a cancelled (stale) pick, and shows the server message', async () => {
		selectReply = { cancelled: true, message: 'That locality is gone.', persisted: false };

		render( <LocalityChooser config={ baseConfig() } /> );
		await chooseFirstSuggestion();

		expect( await screen.findByText( 'That locality is gone.' ) ).toBeInTheDocument();
		expect( mockStore.setShippingAddress ).not.toHaveBeenCalled();
	} );

	it( 'tells the shopper to choose the region when none matched, instead of writing a bad code', async () => {
		( window as unknown as { wc: { wcSettings: { getSetting: unknown } } } ).wc.wcSettings.getSetting = ( name: string, fallback: unknown ) =>
			( { countryData: { RU: { states: { 'КАЗАНЬ-ОБЛ': 'Татарстан' } } } } as Record< string, unknown > )[ name ] ?? fallback;

		render( <LocalityChooser config={ baseConfig() } /> );
		await chooseFirstSuggestion();

		await waitFor( () => expect( mockStore.setShippingAddress ).toHaveBeenCalledTimes( 1 ) );
		expect( mockStore.setShippingAddress ).toHaveBeenCalledWith( expect.objectContaining( { city: 'Подольск', state: '' } ) );
		expect( await screen.findByText( 'The region could not be matched.' ) ).toBeInTheDocument();
	} );
} );

describe( 'LocalityChooser — manual-edit invalidation', () => {
	it( 'drops the selection, forgets it on the server and refreshes the rates when City is edited by hand', async () => {
		render( <LocalityChooser config={ baseConfig() } /> );
		await chooseFirstSuggestion();
		await waitFor( () => expect( mockStore.setShippingAddress ).toHaveBeenCalledTimes( 1 ) );
		expect( await screen.findByRole( 'button', { name: 'Clear the chosen locality' } ) ).toBeInTheDocument();

		// The shopper edits the native City field.
		act( () => {
			mockStore.customer = { ...mockStore.customer, shippingAddress: { ...mockStore.customer.shippingAddress, city: 'Подольс' } };
			notify();
		} );

		await waitFor( () => expect( screen.queryByRole( 'button', { name: 'Clear the chosen locality' } ) ).not.toBeInTheDocument() );
		await waitFor( () => expect( fetchCalls.some( ( call ) => call.url.includes( '/location/forget' ) ) ).toBe( true ) );
		await waitFor( () => expect( mockStore.invalidateResolutionForStoreSelector ).toHaveBeenCalledWith( 'getCartData' ) );
	} );

	it( 'does not treat its own native write as a hand edit', async () => {
		render( <LocalityChooser config={ baseConfig() } /> );
		await chooseFirstSuggestion();
		await waitFor( () => expect( mockStore.setShippingAddress ).toHaveBeenCalledTimes( 1 ) );

		expect( await screen.findByRole( 'button', { name: 'Clear the chosen locality' } ) ).toBeInTheDocument();
		expect( fetchCalls.some( ( call ) => call.url.includes( '/location/forget' ) ) ).toBe( false );
	} );

	it( 'drops the selection when the country changes', async () => {
		render( <LocalityChooser config={ baseConfig( { countries: [ 'RU', 'KZ' ], levels: { RU: { region: false, settlement: true, address: true }, KZ: { region: false, settlement: true, address: true } } } ) } /> );
		await chooseFirstSuggestion();
		await waitFor( () => expect( mockStore.setShippingAddress ).toHaveBeenCalledTimes( 1 ) );

		act( () => {
			mockStore.customer = { ...mockStore.customer, shippingAddress: { ...mockStore.customer.shippingAddress, country: 'KZ' } };
			notify();
		} );

		await waitFor( () => expect( fetchCalls.some( ( call ) => call.url.includes( '/location/forget' ) ) ).toBe( true ) );
	} );
} );

describe( 'LocalityChooser — hydrate (guest and logged in)', () => {
	it( 'restores a locality the server kept when the native address still carries a city', () => {
		mockStore.customer.shippingAddress = { ...mockStore.customer.shippingAddress, city: 'Подольск' };

		render( <LocalityChooser config={ baseConfig( { chain: { settlement: { key: 'dadata:podolsk', level: 'settlement' } } } ) } /> );

		expect( screen.getByRole( 'combobox' ) ).toHaveValue( 'Подольск' );
		expect( screen.getByRole( 'button', { name: 'Clear the chosen locality' } ) ).toBeInTheDocument();
	} );

	it( 'never counts the store default (implicit) as a pick', () => {
		mockStore.customer.shippingAddress = { ...mockStore.customer.shippingAddress, city: 'Москва' };

		render( <LocalityChooser config={ baseConfig( { implicit: true, chain: { settlement: { key: 'dadata:moscow', level: 'settlement' } } } ) } /> );

		expect( screen.queryByRole( 'button', { name: 'Clear the chosen locality' } ) ).not.toBeInTheDocument();
	} );

	it( 'asks for a pick when WooCommerce holds a city the location layer could not resolve', () => {
		mockStore.customer.shippingAddress = { ...mockStore.customer.shippingAddress, city: 'Деревня Пупкино' };

		render( <LocalityChooser config={ baseConfig( { savedCityUnresolved: 'Деревня Пупкино' } ) } /> );

		expect( screen.getByText( 'Select a locality from the suggestions' ) ).toBeInTheDocument();
	} );
} );

describe( 'registerLocalityBlock — feature detection', () => {
	const published = ( enabled: boolean ) => ( name: string, fallback: unknown ) =>
		name === 'woodev-shipping-locality_data' ? { enabled, location: enabled ? baseConfig() : undefined } : fallback;

	it( 'registers nothing without registerCheckoutBlock from WooCommerce Blocks', () => {
		( window as unknown as { wc: unknown } ).wc = { wcSettings: { getSetting: published( true ) } };

		expect( registerLocalityBlock() ).toBe( false );
	} );

	it( 'registers nothing when the server published no data (location layer inactive)', () => {
		const register = jest.fn();
		( window as unknown as { wc: unknown } ).wc = {
			blocksCheckout: { registerCheckoutBlock: register },
			wcSettings: { getSetting: published( false ) },
		};

		expect( registerLocalityBlock() ).toBe( false );
		expect( register ).not.toHaveBeenCalled();
	} );

	it( 'registers a FORCED inner block of the shipping address block', () => {
		const register = jest.fn();
		( window as unknown as { wc: unknown } ).wc = {
			blocksCheckout: { registerCheckoutBlock: register },
			wcSettings: { getSetting: published( true ) },
		};

		expect( registerLocalityBlock() ).toBe( true );
		expect( register ).toHaveBeenCalledTimes( 1 );

		const options = register.mock.calls[ 0 ][ 0 ];

		expect( options.force ).toBe( true );
		expect( options.metadata.name ).toBe( 'woodev/shipping-locality' );
		expect( options.metadata.parent ).toEqual( [ 'woocommerce/checkout-shipping-address-block' ] );
		expect( typeof options.component ).toBe( 'function' );
	} );
} );
