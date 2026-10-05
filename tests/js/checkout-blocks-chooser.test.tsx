import '@testing-library/jest-dom';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useSyncExternalStore as mockUseSyncExternalStore } from 'react';

type Address = Record< string, string >;

/*
 * An in-memory stand-in for `wc/store/cart` + `wc/store/checkout`. It is the seam the chooser talks to
 * (`wc-stores.ts` is a thin wrapper over `@wordpress/data`'s `select`/`dispatch`/`useSelect`), so the
 * tests claim exactly what the chooser does to the NATIVE address and to the checkout gate.
 *
 * Two pieces of core behaviour are reproduced because the chooser's guarantees depend on them, both
 * read from WooCommerce 11.1 (`wc-blocks-data.js`: `Na=`, `wi=`):
 * - `disableCheckoutFor( work )` counts a calculation for as long as `work()` is unsettled;
 * - `updateCustomerData( data, editing )` takes the SERVER's address into the store when `editing`
 *   is false (`receiveCart`) and leaves the store's address alone when it is true
 *   (`receiveCartContents`). The «server's address» is the one the request was sent with.
 */
const mockStore = {
	customer: {
		shippingAddress: { first_name: 'Анна', address_1: 'Ленина 1', city: '', state: '', country: 'RU' } as Address,
		billingAddress: { first_name: 'Анна', address_1: 'Ленина 1', city: '', state: '', country: 'RU' } as Address,
	},
	useShippingAsBilling: false,
	listeners: new Set< () => void >(),
	setShippingAddress: jest.fn(),
	setBillingAddress: jest.fn(),
	invalidateResolutionForStoreSelector: jest.fn(),
	updateCustomerData: jest.fn(),
	/** How many `disableCheckoutFor` calls are unsettled: Place Order is blocked while > 0. */
	calculating: 0,
	/** `false` simulates a WooCommerce without `disableCheckoutFor`. */
	hasGate: true,
	/** When set, `updateCustomerData` answers only once this settles. */
	refreshHold: null as Promise< void > | null,
	/**
	 * `hasFinishedResolution( 'getCartData' )`: `false` is the page before WooCommerce's cart data
	 * arrived (the store holds its empty defaults). `undefined` simulates a store without the
	 * meta-selector.
	 */
	cartLoaded: undefined as boolean | undefined,
};

const notify = (): void => mockStore.listeners.forEach( ( listener ) => listener() );

jest.mock( '@wordpress/data', () => {
	const selectFn = () => ( {
		getCustomerData: () => mockStore.customer,
		getUseShippingAsBilling: () => mockStore.useShippingAsBilling,
		...( mockStore.cartLoaded === undefined
			? {}
			: { hasFinishedResolution: ( name: string ) => name === 'getCartData' && mockStore.cartLoaded === true } ),
	} );

	return {
		select: selectFn,
		dispatch: () => ( {
			setShippingAddress: ( address: Address ) => {
				mockStore.setShippingAddress( address );
				mockStore.customer = { ...mockStore.customer, shippingAddress: address };
				notify();
			},
			setBillingAddress: ( address: Address ) => {
				mockStore.setBillingAddress( address );
				mockStore.customer = { ...mockStore.customer, billingAddress: address };
				notify();
			},
			invalidateResolutionForStoreSelector: mockStore.invalidateResolutionForStoreSelector,
			updateCustomerData: async ( data: { shipping_address: Address }, editing = true, ratesChanged = false ) => {
				mockStore.updateCustomerData( data, editing, ratesChanged );
				await mockStore.refreshHold;

				if ( ! editing ) {
					mockStore.customer = {
						...mockStore.customer,
						shippingAddress: { ...mockStore.customer.shippingAddress, ...data.shipping_address },
					};
					notify();
				}

				return {};
			},
			...( mockStore.hasGate
				? {
						disableCheckoutFor: async ( work: () => Promise< unknown > ) => {
							mockStore.calculating++;

							try {
								return await work();
							} finally {
								mockStore.calculating--;
							}
						},
				  }
				: {} ),
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
import { LocalityChooser, resetLocalityMemory } from '../../src/checkout-blocks/locality-chooser';
// eslint-disable-next-line import/first
import { registerLocalityBlock } from '../../src/checkout-blocks/register';
// eslint-disable-next-line import/first
import type { LocationConfig } from '../../src/checkout-blocks/types';

const RU_STATES = { 'МОСКОВСКАЯ ОБЛАСТЬ': 'Московская область', 'МОСКВА': 'Москва', 'ТАТАРСТАН': 'Республика Татарстан' };

const baseConfig = ( over: Partial< LocationConfig > = {} ): LocationConfig => ( {
	endpoints: {
		suggest: 'https://shop.test/wp-json/woodev/v1/location/suggest',
		select: 'https://shop.test/wp-json/woodev/v1/location/select',
		list: 'https://shop.test/wp-json/woodev/v1/location/list',
		forget: 'https://shop.test/wp-json/woodev/v1/location/forget',
	},
	nonce: 'rest-nonce',
	countries: [ 'RU', 'KZ' ],
	levels: {
		RU: { region: false, settlement: true, address: true },
		KZ: { region: false, settlement: true, address: true },
	},
	regionFieldRemoved: false,
	current: null,
	chain: [],
	implicit: false,
	savedCityUnresolved: null,
	selection: null,
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
		syncFailed: 'Your locality could not be updated.',
		retry: 'Try again',
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

const kazan = {
	key: 'dadata:kazan',
	label: 'Казань, Республика Татарстан',
	level: 'settlement',
	record: {
		key: 'dadata:kazan',
		provider_id: 'dadata',
		level: 'settlement',
		country: 'RU',
		region: { name: 'Республика Татарстан' },
		settlement: { name: 'Казань' },
	},
};

/** A config whose server-published selection is Podolsk, with the native address naming it. */
const podolskSaved = ( over: Partial< LocationConfig > = {} ): LocationConfig =>
	baseConfig( { selection: { record: podolsk.record }, ...over } );

type Write = 'select' | 'forget';

interface HeldRequest {
	url: string;
	init?: RequestInit;
	resolve: ( response: Response ) => void;
	reject: ( error: unknown ) => void;
	answered: boolean;
}

let fetchCalls: Array< { url: string; init?: RequestInit } > = [];
let selectReply: Record< string, unknown > = { persisted: true };
let suggestReply: unknown[] = [ podolsk ];
/** Writes that are NOT answered until the test calls `answer()` — the deterministic slow network. */
let holding = new Set< Write >();
let heldRequests: HeldRequest[] = [];

const reply = ( body: unknown, status = 200 ): Response =>
	( { ok: status >= 200 && status < 300, status, json: async () => body } ) as Response;

const writeOf = ( url: string ): Write | null => {
	if ( url.includes( '/location/select' ) ) {
		return 'select';
	}

	return url.includes( '/location/forget' ) ? 'forget' : null;
};

function mockNetwork(): void {
	fetchCalls = [];
	heldRequests = [];
	global.fetch = jest.fn( ( input: RequestInfo | URL, init?: RequestInit ) => {
		const url = String( input );
		const write = writeOf( url );
		fetchCalls.push( { url, init } );

		if ( write && holding.has( write ) ) {
			return new Promise< Response >( ( resolve, reject ) => {
				heldRequests.push( { url, init, resolve, reject, answered: false } );
			} );
		}

		if ( url.includes( '/location/suggest' ) ) {
			return Promise.resolve( reply( { suggestions: suggestReply } ) );
		}

		return Promise.resolve( reply( write === 'select' ? selectReply : {} ) );
	} ) as typeof fetch;
}

const sent = ( write: Write ): Array< { url: string; init?: RequestInit } > =>
	fetchCalls.filter( ( call ) => writeOf( call.url ) === write );

const sentRecordKeys = (): string[] =>
	sent( 'select' ).map( ( call ) => JSON.parse( String( call.init?.body ) ).record.key as string );

const unanswered = ( write: Write ): HeldRequest[] =>
	heldRequests.filter( ( request ) => ! request.answered && writeOf( request.url ) === write );

const settle = (): Promise< void > => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

/** Answers the oldest held `write` — with a response, or with a network failure. */
async function answer( write: Write, response: Response | Error ): Promise< void > {
	await waitFor( () => expect( unanswered( write ).length ).toBeGreaterThan( 0 ) );

	const request = unanswered( write )[ 0 ];
	request.answered = true;

	await act( async () => {
		if ( response instanceof Error ) {
			request.reject( response );
		} else {
			request.resolve( response );
		}

		await settle();
	} );
}

/** The shopper edits the NATIVE address form. */
function editNative( patch: Address ): void {
	act( () => {
		mockStore.customer = {
			...mockStore.customer,
			shippingAddress: { ...mockStore.customer.shippingAddress, ...patch },
		};
		notify();
	} );
}

async function chooseFirstSuggestion( typed = 'Подол' ): Promise< void > {
	fireEvent.change( screen.getByRole( 'combobox' ), { target: { value: typed } } );
	const option = await screen.findByRole( 'option', {}, { timeout: 2000 } );
	fireEvent.mouseDown( option );
}

const clearButton = (): HTMLElement | null => screen.queryByRole( 'button', { name: 'Clear the chosen locality' } );

beforeEach( () => {
	mockStore.customer = {
		shippingAddress: { first_name: 'Анна', address_1: 'Ленина 1', city: '', state: '', country: 'RU' },
		billingAddress: { first_name: 'Анна', address_1: 'Ленина 1', city: '', state: '', country: 'RU' },
	};
	mockStore.useShippingAsBilling = false;
	mockStore.listeners.clear();
	mockStore.setShippingAddress.mockReset();
	mockStore.setBillingAddress.mockReset();
	mockStore.invalidateResolutionForStoreSelector.mockReset();
	mockStore.updateCustomerData.mockReset();
	mockStore.calculating = 0;
	mockStore.hasGate = true;
	mockStore.refreshHold = null;
	mockStore.cartLoaded = undefined;
	selectReply = { persisted: true };
	suggestReply = [ podolsk ];
	holding = new Set();
	resetLocalityMemory();
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
		global.fetch = jest.fn( async () => reply( {}, 502 ) ) as typeof fetch;

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

		const select = sent( 'select' )[ 0 ];
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
	it( 'drops the selection, forgets it on the server and recalculates the rates when City is edited by hand', async () => {
		render( <LocalityChooser config={ baseConfig() } /> );
		await chooseFirstSuggestion();
		await waitFor( () => expect( mockStore.setShippingAddress ).toHaveBeenCalledTimes( 1 ) );
		expect( await screen.findByRole( 'button', { name: 'Clear the chosen locality' } ) ).toBeInTheDocument();

		editNative( { city: 'Подольс' } );

		await waitFor( () => expect( clearButton() ).not.toBeInTheDocument() );
		await waitFor( () => expect( sent( 'forget' ) ).toHaveLength( 1 ) );
		// The rates are recalculated for what the form NOW says, taking only the cart contents back.
		await waitFor( () =>
			expect( mockStore.updateCustomerData ).toHaveBeenCalledWith(
				{ shipping_address: { city: 'Подольс', state: 'МОСКОВСКАЯ ОБЛАСТЬ', country: 'RU' } },
				true,
				true
			)
		);
		expect( mockStore.invalidateResolutionForStoreSelector ).not.toHaveBeenCalled();
	} );

	it( 'does not treat its own native write as a hand edit', async () => {
		render( <LocalityChooser config={ baseConfig() } /> );
		await chooseFirstSuggestion();
		await waitFor( () => expect( mockStore.setShippingAddress ).toHaveBeenCalledTimes( 1 ) );

		expect( await screen.findByRole( 'button', { name: 'Clear the chosen locality' } ) ).toBeInTheDocument();
		await act( settle );
		expect( sent( 'forget' ) ).toHaveLength( 0 );
	} );

	it( 'drops the selection when the country changes', async () => {
		render( <LocalityChooser config={ baseConfig() } /> );
		await chooseFirstSuggestion();
		await waitFor( () => expect( mockStore.setShippingAddress ).toHaveBeenCalledTimes( 1 ) );

		editNative( { country: 'KZ' } );

		await waitFor( () => expect( sent( 'forget' ) ).toHaveLength( 1 ) );
	} );

	it( 'drops the selection when the shopper changes the State the chooser wrote', async () => {
		render( <LocalityChooser config={ baseConfig() } /> );
		await chooseFirstSuggestion();
		await waitFor( () => expect( mockStore.setShippingAddress ).toHaveBeenCalledTimes( 1 ) );

		editNative( { state: 'ТАТАРСТАН' } );

		await waitFor( () => expect( clearButton() ).not.toBeInTheDocument() );
		await waitFor( () => expect( sent( 'forget' ) ).toHaveLength( 1 ) );
	} );

	it( 'keeps the selection when the shopper picks a State the chooser could not match itself', async () => {
		( window as unknown as { wc: { wcSettings: { getSetting: unknown } } } ).wc.wcSettings.getSetting = ( name: string, fallback: unknown ) =>
			( { countryData: { RU: { states: { 'RU-MOS': 'Подмосковье' } } } } as Record< string, unknown > )[ name ] ?? fallback;

		render( <LocalityChooser config={ baseConfig() } /> );
		await chooseFirstSuggestion();
		await waitFor( () => expect( mockStore.setShippingAddress ).toHaveBeenCalledTimes( 1 ) );

		editNative( { state: 'RU-MOS' } );
		await act( settle );

		expect( clearButton() ).toBeInTheDocument();
		expect( sent( 'forget' ) ).toHaveLength( 0 );
	} );
} );

describe( 'LocalityChooser — hydrate (guest and logged in)', () => {
	it( 'restores a saved locality whose settlement and country the native address names', async () => {
		mockStore.customer.shippingAddress = { ...mockStore.customer.shippingAddress, city: 'подольск ' };

		render( <LocalityChooser config={ podolskSaved() } /> );

		expect( screen.getByRole( 'combobox' ) ).toHaveValue( 'Подольск' );
		expect( clearButton() ).toBeInTheDocument();
		await act( settle );
		expect( sent( 'forget' ) ).toHaveLength( 0 );
	} );

	it( 'does not claim a saved locality for a DIFFERENT city, and clears that provenance', async () => {
		// A failed forget followed by a reload: the server kept Podolsk, WooCommerce holds Kazan.
		mockStore.customer.shippingAddress = { ...mockStore.customer.shippingAddress, city: 'Казань' };

		render( <LocalityChooser config={ podolskSaved() } /> );

		expect( clearButton() ).not.toBeInTheDocument();
		expect( screen.getByRole( 'combobox' ) ).toHaveValue( '' );
		await waitFor( () => expect( sent( 'forget' ) ).toHaveLength( 1 ) );
	} );

	it( 'does not claim a saved locality of another country, and clears that provenance', async () => {
		mockStore.customer.shippingAddress = { ...mockStore.customer.shippingAddress, city: 'Подольск', country: 'KZ' };

		render( <LocalityChooser config={ podolskSaved() } /> );

		expect( clearButton() ).not.toBeInTheDocument();
		await waitFor( () => expect( sent( 'forget' ) ).toHaveLength( 1 ) );
	} );

	it( 'neither claims nor clears a saved locality while the native address names no city', async () => {
		// The chain is shared with the classic checkout, the cart and My Account: a City that has
		// simply not been filled in is no reason to erase a locality the customer chose there.
		render( <LocalityChooser config={ podolskSaved() } /> );

		expect( clearButton() ).not.toBeInTheDocument();
		await act( settle );
		expect( sent( 'forget' ) ).toHaveLength( 0 );
		expect( mockStore.calculating ).toBe( 0 );
	} );

	it( 'claims the undecided locality once the shopper types a City that names it', async () => {
		render( <LocalityChooser config={ podolskSaved() } /> );

		editNative( { city: 'г. Подольск' } );

		await waitFor( () => expect( clearButton() ).toBeInTheDocument() );
		expect( screen.getByRole( 'combobox' ) ).toHaveValue( 'Подольск' );
		await act( settle );
		expect( sent( 'forget' ) ).toHaveLength( 0 );
		// The native text is the shopper's: it is not rewritten.
		expect( mockStore.setShippingAddress ).not.toHaveBeenCalled();
	} );

	it( 'clears the undecided locality once the shopper types a City that names another place', async () => {
		render( <LocalityChooser config={ podolskSaved() } /> );

		editNative( { city: 'Казань' } );

		await waitFor( () => expect( sent( 'forget' ) ).toHaveLength( 1 ) );
		expect( clearButton() ).not.toBeInTheDocument();
	} );

	it( 'does not judge the saved locality before WooCommerce has loaded the address', async () => {
		// Before the cart data arrives the store holds its empty defaults — no city, no country.
		mockStore.cartLoaded = false;
		mockStore.customer.shippingAddress = { city: '', state: '', country: '' };

		render( <LocalityChooser config={ podolskSaved() } /> );
		await act( settle );
		expect( sent( 'forget' ) ).toHaveLength( 0 );

		// A default country alone, still not the customer's address, proves nothing either.
		editNative( { city: 'Казань', country: 'RU' } );
		await act( settle );
		expect( sent( 'forget' ) ).toHaveLength( 0 );

		// The cart arrives, and the address names the saved locality — in its own words.
		mockStore.cartLoaded = true;
		editNative( { first_name: 'Анна', city: 'г. ПОДОЛЬСК', state: 'МОСКОВСКАЯ ОБЛАСТЬ', country: 'RU' } );

		await waitFor( () => expect( clearButton() ).toBeInTheDocument() );
		await act( settle );
		expect( sent( 'forget' ) ).toHaveLength( 0 );
	} );

	it( 'clears a saved locality the loaded address does not name — only once it HAS loaded', async () => {
		mockStore.cartLoaded = false;
		mockStore.customer.shippingAddress = { city: '', state: '', country: '' };

		render( <LocalityChooser config={ podolskSaved() } /> );

		mockStore.cartLoaded = true;
		editNative( { city: 'Казань', state: 'ТАТАРСТАН', country: 'RU' } );

		await waitFor( () => expect( sent( 'forget' ) ).toHaveLength( 1 ) );
		expect( clearButton() ).not.toBeInTheDocument();
	} );

	/*
	 * Critic round 2, F12: the chooser keeps exactly what the server's rule (c) keeps — the SAME cases
	 * as `name_contract_cases()` in LocationServiceTest.php, here at the component boundary.
	 */
	it.each( [
		[ 'Мостовской', 'рп', 'рп Мостовской' ],
		[ 'Панахес', 'аул', 'аул Панахес' ],
		[ 'Подольск', 'г', 'г. ПОДОЛЬСК' ],
		[ 'Орёл', 'г', 'Орел' ],
		[ 'Орел', 'г', 'Орёл' ],
		[ 'Москва', 'г', 'г. Москва' ],
	] )( 'restores a saved «%s» (type «%s») for the native City «%s» and never forgets it', async ( name, type, city ) => {
		mockStore.customer.shippingAddress = { ...mockStore.customer.shippingAddress, city };
		const saved = { ...podolsk.record, key: 'dadata:named', settlement: { name, type } };

		render( <LocalityChooser config={ baseConfig( { selection: { record: saved } } ) } /> );

		expect( screen.getByRole( 'combobox' ) ).toHaveValue( name );
		expect( clearButton() ).toBeInTheDocument();
		await act( settle );
		expect( sent( 'forget' ) ).toHaveLength( 0 );
	} );

	it.each( [
		[ 'Санкт-Петербург', 'г', 'Санкт Петербург' ],
		[ 'Москва', 'г', 'Moscow' ],
		[ 'Мостовской', 'г', 'рп Мостовской' ],
	] )( 'does not guess that «%s» (type «%s») is the native City «%s»', async ( name, type, city ) => {
		mockStore.customer.shippingAddress = { ...mockStore.customer.shippingAddress, city };
		const saved = { ...podolsk.record, key: 'dadata:named', settlement: { name, type } };

		render( <LocalityChooser config={ baseConfig( { selection: { record: saved } } ) } /> );

		expect( clearButton() ).not.toBeInTheDocument();
		await waitFor( () => expect( sent( 'forget' ) ).toHaveLength( 1 ) );
	} );

	// Critic round 2, F11: the restored record's State is derived from WooCommerce's state list.
	it( 'does not claim a saved locality whose region the native State is not, and clears it', async () => {
		mockStore.customer.shippingAddress = { ...mockStore.customer.shippingAddress, city: 'Подольск', state: 'ТАТАРСТАН' };

		render( <LocalityChooser config={ podolskSaved() } /> );

		expect( clearButton() ).not.toBeInTheDocument();
		await waitFor( () => expect( sent( 'forget' ) ).toHaveLength( 1 ) );
	} );

	it( 'reload, then a State-only edit: the restored selection is dropped and forgotten', async () => {
		mockStore.customer.shippingAddress = { ...mockStore.customer.shippingAddress, city: 'Подольск', state: 'МОСКОВСКАЯ ОБЛАСТЬ' };

		render( <LocalityChooser config={ podolskSaved() } /> );
		expect( clearButton() ).toBeInTheDocument();

		// The City still says «Подольск»; only the region moved.
		editNative( { state: 'ТАТАРСТАН' } );

		await waitFor( () => expect( clearButton() ).not.toBeInTheDocument() );
		await waitFor( () => expect( sent( 'forget' ) ).toHaveLength( 1 ) );
		await waitFor( () =>
			expect( mockStore.updateCustomerData ).toHaveBeenCalledWith(
				{ shipping_address: { city: 'Подольск', state: 'ТАТАРСТАН', country: 'RU' } },
				true,
				true
			)
		);
	} );

	it( 'reload with a blank State: the selection is restored, and a State naming another region drops it', async () => {
		mockStore.customer.shippingAddress = { ...mockStore.customer.shippingAddress, city: 'Подольск' };

		render( <LocalityChooser config={ podolskSaved() } /> );
		expect( clearButton() ).toBeInTheDocument();

		// The record's own region is no disagreement…
		editNative( { state: 'МОСКОВСКАЯ ОБЛАСТЬ' } );
		await act( settle );
		expect( clearButton() ).toBeInTheDocument();
		expect( sent( 'forget' ) ).toHaveLength( 0 );

		// …another one is.
		editNative( { state: 'МОСКВА' } );
		await waitFor( () => expect( sent( 'forget' ) ).toHaveLength( 1 ) );
		expect( clearButton() ).not.toBeInTheDocument();
	} );

	it( 'reload with the region field removed: the State is not the shopper\'s to fix and is not judged', async () => {
		mockStore.customer.shippingAddress = { ...mockStore.customer.shippingAddress, city: 'Подольск', state: 'ТАТАРСТАН' };

		render( <LocalityChooser config={ podolskSaved( { regionFieldRemoved: true } ) } /> );

		expect( clearButton() ).toBeInTheDocument();
		await act( settle );
		expect( sent( 'forget' ) ).toHaveLength( 0 );
	} );

	it( 'never counts the store default (implicit) as a pick, and never forgets it', async () => {
		mockStore.customer.shippingAddress = { ...mockStore.customer.shippingAddress, city: 'Казань' };

		// The server publishes `selection: null` for an implicit record, whatever `chain` says.
		render( <LocalityChooser config={ baseConfig( { implicit: true, chain: { settlement: { key: 'dadata:moscow', level: 'settlement' } } } ) } /> );

		expect( clearButton() ).not.toBeInTheDocument();
		await act( settle );
		expect( sent( 'forget' ) ).toHaveLength( 0 );
	} );

	it( 'asks for a pick when WooCommerce holds a city the location layer could not resolve', () => {
		mockStore.customer.shippingAddress = { ...mockStore.customer.shippingAddress, city: 'Деревня Пупкино' };

		render( <LocalityChooser config={ baseConfig( { savedCityUnresolved: 'Деревня Пупкино' } ) } /> );

		expect( screen.getByText( 'Select a locality from the suggestions' ) ).toBeInTheDocument();
	} );

	it( 'remembers the selection across a remount instead of re-reading the page-load config', async () => {
		const config = baseConfig();
		const first = render( <LocalityChooser config={ config } /> );
		await chooseFirstSuggestion();
		await waitFor( () => expect( clearButton() ).toBeInTheDocument() );

		// WooCommerce unmounts the shipping address block (local pickup) and mounts it again.
		first.unmount();
		render( <LocalityChooser config={ config } /> );

		expect( screen.getByRole( 'combobox' ) ).toHaveValue( 'Подольск' );
		expect( clearButton() ).toBeInTheDocument();
		await act( settle );
		expect( sent( 'forget' ) ).toHaveLength( 0 );
	} );
} );

/*
 * Findings 1–6 of the round-1 review: every chain write goes through ONE serialized queue with a
 * generation counter. These tests hold a reply back and let the shopper act in the meantime.
 */
describe( 'LocalityChooser — ordering under a slow network', () => {
	it( 'a native edit made DURING the rate refresh is not overwritten by its reply', async () => {
		mockStore.customer.shippingAddress = { ...mockStore.customer.shippingAddress, city: 'Подольск' };
		let release = (): void => undefined;
		mockStore.refreshHold = new Promise< void >( ( resolve ) => ( release = resolve ) );

		render( <LocalityChooser config={ podolskSaved() } /> );

		// The shopper types another city: the selection goes, the forget lands, the refresh starts —
		// all before core's own 1.5 s address push.
		editNative( { city: 'Казань' } );
		await waitFor( () => expect( mockStore.updateCustomerData ).toHaveBeenCalledTimes( 1 ) );
		// The refresh SENDS the form's city, so the server rates what the shopper typed, not Podolsk.
		expect( mockStore.updateCustomerData.mock.calls[ 0 ][ 0 ] ).toEqual( {
			shipping_address: { city: 'Казань', state: '', country: 'RU' },
		} );

		// …and the shopper keeps typing while it is in flight.
		editNative( { city: 'Набережные Челны' } );
		await act( async () => {
			release();
			await settle();
		} );

		expect( mockStore.customer.shippingAddress.city ).toBe( 'Набережные Челны' );
		expect( mockStore.invalidateResolutionForStoreSelector ).not.toHaveBeenCalled();
		expect( mockStore.setShippingAddress ).not.toHaveBeenCalled();
	} );

	it( 'clear-then-pick with a slow forget: the old forget cannot erase the new pick', async () => {
		mockStore.customer.shippingAddress = { ...mockStore.customer.shippingAddress, city: 'Подольск' };
		holding.add( 'forget' );
		suggestReply = [ kazan ];

		render( <LocalityChooser config={ podolskSaved() } /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Clear the chosen locality' } ) );
		await waitFor( () => expect( unanswered( 'forget' ) ).toHaveLength( 1 ) );

		await chooseFirstSuggestion( 'Каза' );
		await act( settle );

		// The pick waits its turn: nothing reaches the server while the forget is unanswered.
		expect( sent( 'select' ) ).toHaveLength( 0 );
		expect( mockStore.setShippingAddress ).not.toHaveBeenCalled();

		await answer( 'forget', reply( {} ) );

		await waitFor( () => expect( sentRecordKeys() ).toEqual( [ 'dadata:kazan' ] ) );
		await waitFor( () => expect( mockStore.customer.shippingAddress.city ).toBe( 'Казань' ) );
		expect( clearButton() ).toBeInTheDocument();

		// The server saw forget THEN select, and no forget follows the pick.
		await act( settle );
		expect( fetchCalls.map( ( call ) => writeOf( call.url ) ).filter( Boolean ) ).toEqual( [ 'forget', 'select' ] );
	} );

	it( 'a country change during a select: the reply is not written and the saved record is erased', async () => {
		holding.add( 'select' );

		render( <LocalityChooser config={ baseConfig() } /> );
		await chooseFirstSuggestion();
		await waitFor( () => expect( unanswered( 'select' ) ).toHaveLength( 1 ) );

		editNative( { country: 'KZ' } );
		await answer( 'select', reply( { persisted: true } ) );

		// The server DID save the RU record — it gets its compensating forget.
		await waitFor( () => expect( sent( 'forget' ) ).toHaveLength( 1 ) );
		expect( mockStore.setShippingAddress ).not.toHaveBeenCalled();
		expect( mockStore.customer.shippingAddress.country ).toBe( 'KZ' );
		expect( clearButton() ).not.toBeInTheDocument();
	} );

	it( 'a city typed during a select is not overwritten by the reply', async () => {
		holding.add( 'select' );

		render( <LocalityChooser config={ baseConfig() } /> );
		await chooseFirstSuggestion();
		await waitFor( () => expect( unanswered( 'select' ) ).toHaveLength( 1 ) );

		editNative( { city: 'Казань' } );
		await answer( 'select', reply( { persisted: true } ) );

		await waitFor( () => expect( sent( 'forget' ) ).toHaveLength( 1 ) );
		expect( mockStore.setShippingAddress ).not.toHaveBeenCalled();
		expect( mockStore.customer.shippingAddress.city ).toBe( 'Казань' );
	} );

	it( 'an unmount during a select: nothing is written and the saved record is erased', async () => {
		holding.add( 'select' );

		const view = render( <LocalityChooser config={ baseConfig() } /> );
		await chooseFirstSuggestion();
		await waitFor( () => expect( unanswered( 'select' ) ).toHaveLength( 1 ) );

		view.unmount();
		// Place Order stays blocked: the queue is still draining without its component.
		expect( mockStore.calculating ).toBe( 1 );

		await answer( 'select', reply( { persisted: true } ) );

		await waitFor( () => expect( sent( 'forget' ) ).toHaveLength( 1 ) );
		await waitFor( () => expect( mockStore.calculating ).toBe( 0 ) );
		expect( mockStore.setShippingAddress ).not.toHaveBeenCalled();

		// A remount must not claim the abandoned pick.
		render( <LocalityChooser config={ baseConfig() } /> );
		expect( clearButton() ).not.toBeInTheDocument();
	} );

	it( 'keeps an applied choice when unmounted during rate recalculation', async () => {
		let release: () => void = () => undefined;
		mockStore.refreshHold = new Promise< void >( ( resolve ) => ( release = resolve ) );

		const view = render( <LocalityChooser config={ baseConfig() } /> );
		await chooseFirstSuggestion();
		await waitFor( () => expect( mockStore.setShippingAddress ).toHaveBeenCalledTimes( 1 ) );
		await waitFor( () => expect( mockStore.calculating ).toBe( 1 ) );

		view.unmount();
		render( <LocalityChooser config={ baseConfig() } /> );

		expect( sent( 'forget' ) ).toHaveLength( 0 );
		expect( mockStore.customer.shippingAddress.city ).toBe( 'Подольск' );
		release();
		await waitFor( () => expect( mockStore.calculating ).toBe( 0 ) );
		await act( async () => {
			await Promise.resolve();
		} );
		expect( sent( 'forget' ) ).toHaveLength( 0 );
	} );

	it( 'a pick queued behind an applied choice whose rates are recalculating: an unmount keeps the applied one (#1102)', async () => {
		let release: () => void = () => undefined;
		mockStore.refreshHold = new Promise< void >( ( resolve ) => ( release = resolve ) );

		const config = baseConfig();
		const view = render( <LocalityChooser config={ config } /> );
		await chooseFirstSuggestion();
		await waitFor( () => expect( mockStore.setShippingAddress ).toHaveBeenCalledTimes( 1 ) );
		await waitFor( () => expect( mockStore.calculating ).toBe( 1 ) );

		// Kazan is picked while Podolsk's rates are still being recalculated: it waits in the queue.
		suggestReply = [ kazan ];
		await chooseFirstSuggestion( 'Каза' );
		await act( settle );
		expect( sentRecordKeys() ).toEqual( [ 'dadata:podolsk' ] );

		// The block unmounts and mounts again. Kazan was never sent, so it left no record to erase —
		// and Podolsk, applied and still in the form, must not be erased with it.
		view.unmount();
		render( <LocalityChooser config={ config } /> );

		expect( screen.getByRole( 'combobox' ) ).toHaveValue( 'Подольск' );
		expect( clearButton() ).toBeInTheDocument();

		release();
		await waitFor( () => expect( mockStore.calculating ).toBe( 0 ) );
		await act( settle );

		expect( sentRecordKeys() ).toEqual( [ 'dadata:podolsk' ] );
		expect( sent( 'forget' ) ).toHaveLength( 0 );
		expect( mockStore.customer.shippingAddress.city ).toBe( 'Подольск' );
	} );

	it( 'an unmount with a pick still queued: the pick is never sent', async () => {
		mockStore.customer.shippingAddress = { ...mockStore.customer.shippingAddress, city: 'Подольск' };
		holding.add( 'forget' );
		suggestReply = [ kazan ];

		const view = render( <LocalityChooser config={ podolskSaved() } /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Clear the chosen locality' } ) );
		await waitFor( () => expect( unanswered( 'forget' ) ).toHaveLength( 1 ) );
		await chooseFirstSuggestion( 'Каза' );

		view.unmount();
		await answer( 'forget', reply( {} ) );
		await answer( 'forget', reply( {} ) );

		await waitFor( () => expect( mockStore.calculating ).toBe( 0 ) );
		expect( sent( 'select' ) ).toHaveLength( 0 );
		expect( mockStore.setShippingAddress ).not.toHaveBeenCalled();
	} );

	/*
	 * Critic round 2, F10: the queue and its generations belong to the PAGE. WooCommerce remounts the
	 * address block while the old mount's request is still on the wire.
	 */
	it( 'a remount BEFORE the old queue drains: the old cleanup forget cannot erase the new mount\'s pick', async () => {
		holding.add( 'select' );
		const config = baseConfig();

		const first = render( <LocalityChooser config={ config } /> );
		await chooseFirstSuggestion();
		await waitFor( () => expect( unanswered( 'select' ) ).toHaveLength( 1 ) );

		// Unmount with Podolsk's `/select` on the wire: its cleanup queues a `/forget`.
		first.unmount();

		// The block is mounted again and the shopper picks Kazan — before anything has drained.
		suggestReply = [ kazan ];
		render( <LocalityChooser config={ config } /> );
		await chooseFirstSuggestion( 'Каза' );
		await act( settle );

		// ONE queue: Kazan waits behind the old mount's request instead of racing it.
		expect( sentRecordKeys() ).toEqual( [ 'dadata:podolsk' ] );
		expect( sent( 'forget' ) ).toHaveLength( 0 );
		expect( mockStore.calculating ).toBe( 1 );

		// Release the old request. The server did save Podolsk…
		await answer( 'select', reply( { persisted: true } ) );
		// …and the newer pick overwrites it; the superseded cleanup forget is never sent.
		await answer( 'select', reply( { persisted: true } ) );

		await waitFor( () => expect( mockStore.customer.shippingAddress.city ).toBe( 'Казань' ) );
		await waitFor( () => expect( mockStore.calculating ).toBe( 0 ) );
		await act( settle );

		// What the server saw, in order: Kazan is the LAST write, so Kazan is what it holds.
		expect( fetchCalls.map( ( call ) => writeOf( call.url ) ).filter( Boolean ) ).toEqual( [ 'select', 'select' ] );
		expect( sentRecordKeys() ).toEqual( [ 'dadata:podolsk', 'dadata:kazan' ] );
		expect( mockStore.setShippingAddress ).toHaveBeenCalledTimes( 1 );
		expect( screen.getByRole( 'combobox' ) ).toHaveValue( 'Казань' );
		expect( clearButton() ).toBeInTheDocument();
	} );

	it( 'a remount before the old queue drains, with no new pick: the abandoned pick is still erased', async () => {
		holding.add( 'select' );
		const config = baseConfig();

		const first = render( <LocalityChooser config={ config } /> );
		await chooseFirstSuggestion();
		await waitFor( () => expect( unanswered( 'select' ) ).toHaveLength( 1 ) );

		first.unmount();
		render( <LocalityChooser config={ config } /> );
		expect( clearButton() ).not.toBeInTheDocument();

		await answer( 'select', reply( { persisted: true } ) );

		await waitFor( () => expect( sent( 'forget' ) ).toHaveLength( 1 ) );
		await waitFor( () => expect( mockStore.calculating ).toBe( 0 ) );
		expect( fetchCalls.map( ( call ) => writeOf( call.url ) ).filter( Boolean ) ).toEqual( [ 'select', 'forget' ] );
		expect( mockStore.setShippingAddress ).not.toHaveBeenCalled();
	} );

	it( 'a remount pick the server REFUSES overwrote nothing: the old mount\'s saved pick is still erased', async () => {
		holding.add( 'select' );
		const config = baseConfig();

		const first = render( <LocalityChooser config={ config } /> );
		await chooseFirstSuggestion();
		await waitFor( () => expect( unanswered( 'select' ) ).toHaveLength( 1 ) );

		first.unmount();
		suggestReply = [ kazan ];
		render( <LocalityChooser config={ config } /> );
		await chooseFirstSuggestion( 'Каза' );

		// Podolsk was saved; Kazan — which superseded Podolsk's cleanup forget — is not.
		await answer( 'select', reply( { persisted: true } ) );
		await answer( 'select', reply( { persisted: false } ) );

		await waitFor( () => expect( sent( 'forget' ) ).toHaveLength( 1 ) );
		await waitFor( () => expect( mockStore.calculating ).toBe( 0 ) );
		expect( fetchCalls.map( ( call ) => writeOf( call.url ) ).filter( Boolean ) ).toEqual( [ 'select', 'select', 'forget' ] );
		expect( screen.getByText( 'Could not save your choice — please try again.' ) ).toBeInTheDocument();
		expect( mockStore.setShippingAddress ).not.toHaveBeenCalled();
		expect( clearButton() ).not.toBeInTheDocument();
	} );

	it( 'a second pick during a slow select: only the newer locality is written', async () => {
		holding.add( 'select' );

		render( <LocalityChooser config={ baseConfig() } /> );
		await chooseFirstSuggestion();
		await waitFor( () => expect( unanswered( 'select' ) ).toHaveLength( 1 ) );

		suggestReply = [ kazan ];
		await chooseFirstSuggestion( 'Каза' );
		await act( settle );
		expect( sent( 'select' ) ).toHaveLength( 1 );

		await answer( 'select', reply( { persisted: true } ) );
		expect( mockStore.setShippingAddress ).not.toHaveBeenCalled();

		await answer( 'select', reply( { persisted: true } ) );

		await waitFor( () => expect( mockStore.setShippingAddress ).toHaveBeenCalledTimes( 1 ) );
		expect( sentRecordKeys() ).toEqual( [ 'dadata:podolsk', 'dadata:kazan' ] );
		expect( mockStore.customer.shippingAddress ).toEqual( expect.objectContaining( { city: 'Казань', state: 'ТАТАРСТАН' } ) );
		expect( sent( 'forget' ) ).toHaveLength( 0 );
	} );
} );

describe( 'LocalityChooser — checkout gate and failures', () => {
	it( 'blocks Place Order while a pick is being saved', async () => {
		holding.add( 'select' );

		render( <LocalityChooser config={ baseConfig() } /> );
		expect( mockStore.calculating ).toBe( 0 );

		await chooseFirstSuggestion();
		await waitFor( () => expect( unanswered( 'select' ) ).toHaveLength( 1 ) );
		expect( mockStore.calculating ).toBe( 1 );

		await answer( 'select', reply( { persisted: true } ) );

		await waitFor( () => expect( mockStore.calculating ).toBe( 0 ) );
		expect( mockStore.setShippingAddress ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'blocks Place Order while a forget is pending, until the rates are recalculated', async () => {
		mockStore.customer.shippingAddress = { ...mockStore.customer.shippingAddress, city: 'Подольск' };
		holding.add( 'forget' );
		let release = (): void => undefined;
		mockStore.refreshHold = new Promise< void >( ( resolve ) => ( release = resolve ) );

		render( <LocalityChooser config={ podolskSaved() } /> );

		editNative( { city: 'Казань' } );
		await waitFor( () => expect( unanswered( 'forget' ) ).toHaveLength( 1 ) );
		expect( mockStore.calculating ).toBe( 1 );

		await answer( 'forget', reply( {} ) );
		await waitFor( () => expect( mockStore.updateCustomerData ).toHaveBeenCalledTimes( 1 ) );
		// The record is gone but the rates still belong to it: the gate holds.
		expect( mockStore.calculating ).toBe( 1 );

		await act( async () => {
			release();
			await settle();
		} );

		expect( mockStore.calculating ).toBe( 0 );
	} );

	it( 'a failed forget is retried, then surfaced with a way to try again — and the gate is released', async () => {
		mockStore.customer.shippingAddress = { ...mockStore.customer.shippingAddress, city: 'Подольск' };
		holding.add( 'forget' );

		render( <LocalityChooser config={ podolskSaved() } retryDelayMs={ 1 } /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Clear the chosen locality' } ) );

		await answer( 'forget', reply( {}, 500 ) );
		// One automatic retry, still behind the gate.
		await answer( 'forget', new TypeError( 'network' ) );

		expect( await screen.findByText( 'Your locality could not be updated.' ) ).toBeInTheDocument();
		await waitFor( () => expect( mockStore.calculating ).toBe( 0 ) );
		expect( sent( 'forget' ) ).toHaveLength( 2 );
		expect( mockStore.updateCustomerData ).not.toHaveBeenCalled();

		fireEvent.click( screen.getByRole( 'button', { name: 'Try again' } ) );
		await answer( 'forget', reply( {} ) );

		await waitFor( () => expect( screen.queryByText( 'Your locality could not be updated.' ) ).not.toBeInTheDocument() );
		expect( screen.queryByRole( 'button', { name: 'Try again' } ) ).not.toBeInTheDocument();
		await waitFor( () => expect( mockStore.updateCustomerData ).toHaveBeenCalledTimes( 1 ) );
	} );

	it( 'a select with no answer is treated as possibly saved: it is erased and the shopper is told', async () => {
		holding.add( 'select' );

		render( <LocalityChooser config={ baseConfig() } /> );
		await chooseFirstSuggestion();

		await answer( 'select', new TypeError( 'network' ) );

		expect( await screen.findByText( 'Could not save your choice — please try again.' ) ).toBeInTheDocument();
		await waitFor( () => expect( sent( 'forget' ) ).toHaveLength( 1 ) );
		expect( mockStore.setShippingAddress ).not.toHaveBeenCalled();
	} );

	it( 'a select the server declined leaves the saved chain alone', async () => {
		holding.add( 'select' );

		render( <LocalityChooser config={ baseConfig() } /> );
		await chooseFirstSuggestion();

		await answer( 'select', reply( { code: 'rest_cookie_invalid_nonce' }, 403 ) );

		expect( await screen.findByText( 'Could not save your choice — please try again.' ) ).toBeInTheDocument();
		await act( settle );
		expect( sent( 'forget' ) ).toHaveLength( 0 );
		expect( mockStore.setShippingAddress ).not.toHaveBeenCalled();
	} );

	it( 'asks for the rates itself when a pick changes nothing in the native address', async () => {
		mockStore.customer.shippingAddress = { ...mockStore.customer.shippingAddress, city: 'Подольск', state: 'МОСКОВСКАЯ ОБЛАСТЬ' };

		render( <LocalityChooser config={ baseConfig() } /> );
		await chooseFirstSuggestion();

		// Core has no dirty field to push, so the new record would never be rated.
		await waitFor( () =>
			expect( mockStore.updateCustomerData ).toHaveBeenCalledWith(
				{ shipping_address: { city: 'Подольск', state: 'МОСКОВСКАЯ ОБЛАСТЬ', country: 'RU' } },
				true,
				true
			)
		);
	} );

	// Critic round 2, F13: core pushes a changed address only after its 1.5 s debounce.
	it( 'Place Order right after a pick that changed the address stays blocked until the rates for it settle', async () => {
		let release = (): void => undefined;
		mockStore.refreshHold = new Promise< void >( ( resolve ) => ( release = resolve ) );

		render( <LocalityChooser config={ baseConfig() } /> );
		await chooseFirstSuggestion();

		// The pick is saved and written into the form…
		await waitFor( () => expect( mockStore.setShippingAddress ).toHaveBeenCalledTimes( 1 ) );
		expect( mockStore.customer.shippingAddress ).toEqual( expect.objectContaining( { city: 'Подольск', state: 'МОСКОВСКАЯ ОБЛАСТЬ' } ) );

		// …and the rates are asked for at once, for the address as it NOW stands, taking only the
		// cart contents back. Until that answers, the old rates must not be orderable.
		await waitFor( () => expect( mockStore.updateCustomerData ).toHaveBeenCalledTimes( 1 ) );
		expect( mockStore.updateCustomerData ).toHaveBeenCalledWith(
			{ shipping_address: { city: 'Подольск', state: 'МОСКОВСКАЯ ОБЛАСТЬ', country: 'RU' } },
			true,
			true
		);
		await act( settle );
		expect( mockStore.calculating ).toBe( 1 );

		await act( async () => {
			release();
			await settle();
		} );

		expect( mockStore.calculating ).toBe( 0 );
		expect( clearButton() ).toBeInTheDocument();
	} );

	it( 'a native edit made while the rates for a pick are recalculated is not overwritten', async () => {
		let release = (): void => undefined;
		mockStore.refreshHold = new Promise< void >( ( resolve ) => ( release = resolve ) );

		render( <LocalityChooser config={ baseConfig() } /> );
		await chooseFirstSuggestion();
		await waitFor( () => expect( mockStore.updateCustomerData ).toHaveBeenCalledTimes( 1 ) );

		editNative( { address_1: 'Мира 5' } );
		await act( async () => {
			release();
			await settle();
		} );

		expect( mockStore.customer.shippingAddress ).toEqual( expect.objectContaining( { address_1: 'Мира 5', city: 'Подольск' } ) );
		expect( mockStore.setShippingAddress ).toHaveBeenCalledTimes( 1 );
	} );

	// Critic round 2, F11: what the server holds after this is covered by its rule (c) —
	// LocationServiceTest::test_gate_drops_a_record_whose_region_the_native_state_is_not.
	it( 'a failed State-only forget is retried, then surfaced with a way to try again', async () => {
		mockStore.customer.shippingAddress = { ...mockStore.customer.shippingAddress, city: 'Подольск', state: 'МОСКОВСКАЯ ОБЛАСТЬ' };
		holding.add( 'forget' );

		render( <LocalityChooser config={ podolskSaved() } retryDelayMs={ 1 } /> );
		expect( clearButton() ).toBeInTheDocument();

		editNative( { state: 'ТАТАРСТАН' } );

		await answer( 'forget', reply( {}, 500 ) );
		// Place Order is blocked through the automatic retry.
		expect( mockStore.calculating ).toBe( 1 );
		await answer( 'forget', new TypeError( 'network' ) );

		expect( await screen.findByText( 'Your locality could not be updated.' ) ).toBeInTheDocument();
		expect( clearButton() ).not.toBeInTheDocument();
		expect( sent( 'forget' ) ).toHaveLength( 2 );

		fireEvent.click( screen.getByRole( 'button', { name: 'Try again' } ) );
		await answer( 'forget', reply( {} ) );

		await waitFor( () => expect( screen.queryByText( 'Your locality could not be updated.' ) ).not.toBeInTheDocument() );
		await waitFor( () => expect( mockStore.calculating ).toBe( 0 ) );
	} );

	it( 'still works on a WooCommerce without disableCheckoutFor', async () => {
		mockStore.hasGate = false;

		render( <LocalityChooser config={ baseConfig() } /> );
		await chooseFirstSuggestion();

		await waitFor( () => expect( mockStore.setShippingAddress ).toHaveBeenCalledTimes( 1 ) );
		expect( mockStore.calculating ).toBe( 0 );
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
