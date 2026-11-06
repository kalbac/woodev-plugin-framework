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
	needsShipping: true,
	forcedBillingAddress: false,
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
		getNeedsShipping: () => mockStore.needsShipping,
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
import { readSettlementScope, resetSettlementScope } from '../../src/checkout-blocks/address-scope';
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
	billingOnly: false,
	i18n: {
		label: 'Find your locality',
		placeholder: 'Select…',
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
let suggestReply: unknown[] = [];
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

function editBilling( patch: Address ): void {
	act( () => {
		mockStore.customer = {
			...mockStore.customer,
			billingAddress: { ...mockStore.customer.billingAddress, ...patch },
		};
		notify();
	} );
}


const MOSCOW = {
	key: 'dadata:moscow',
	label: 'г Москва',
	level: 'settlement',
	value: 'Москва',
	record: {
		key: 'dadata:moscow',
		provider_id: 'dadata',
		level: 'settlement',
		country: 'RU',
		region: { name: 'Москва' },
		settlement: { name: 'Москва' },
	},
};

const KHIMKI = {
	key: 'dadata:khimki',
	label: 'г Химки, Московская область',
	level: 'settlement',
	value: 'Химки',
	record: {
		key: 'dadata:khimki',
		provider_id: 'dadata',
		level: 'settlement',
		country: 'RU',
		region: { name: 'Московская область' },
		settlement: { name: 'Химки' },
	},
};

// «рп Черкизово» under «г Пушкино»: the record's settlement is the parent, the item's value its own name.
const CHERKIZOVO = {
	key: 'dadata:cherkizovo',
	label: 'рп Черкизово, г Пушкино',
	level: 'settlement',
	value: 'Черкизово',
	record: {
		key: 'dadata:cherkizovo',
		provider_id: 'dadata',
		level: 'settlement',
		country: 'RU',
		region: { name: 'Московская область' },
		settlement: { name: 'Пушкино', type: 'г' },
	},
};

const cityMode = ( places: Array< typeof MOSCOW >, over: Partial< LocationConfig > = {} ): LocationConfig =>
	baseConfig( {
		levels: { RU: { region: false, settlement: true, address: true }, KZ: { region: false, settlement: true, address: true } },
		allowed: { RU: places },
		hideSettlement: false,
		...over,
	} );

const suggestRequests = (): string[] => fetchCalls.map( ( call ) => call.url ).filter( ( url ) => url.includes( '/location/suggest' ) );

/** The native shipping form's City and State rows, as Blocks renders them. */
function installNativeRows(): void {
	document.body.innerHTML = `
		<div class="wc-block-components-address-form">
			<div class="wc-block-components-address-form__city"><label for="shipping-city">Населённый пункт</label><input id="shipping-city" /></div>
			<div class="wc-block-components-address-form__state"><input id="shipping-state" /></div>
			<div class="wc-block-components-address-form__address_1"><input id="shipping-address_1" /></div>
		</div>`;
}

beforeEach( () => {
	mockStore.customer = {
		shippingAddress: { first_name: 'Анна', address_1: 'Ленина 1', postcode: '101000', city: '', state: '', country: 'RU' },
		billingAddress: { first_name: 'Анна', address_1: 'Ленина 1', postcode: '101000', city: '', state: '', country: 'RU' },
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
	mockStore.needsShipping = true;
	mockStore.forcedBillingAddress = false;
	selectReply = { persisted: true };
	suggestReply = [];
	holding = new Set();
	resetLocalityMemory();
	resetSettlementScope();
	document.body.innerHTML = '';
	( window as unknown as { wc: unknown } ).wc = {
		wcSettings: {
			getSetting: ( name: string, fallback: unknown ) =>
				( { countryData: { RU: { states: RU_STATES } }, forcedBillingAddress: mockStore.forcedBillingAddress } as Record< string, unknown > )[ name ] ?? fallback,
		},
	};
	mockNetwork();
} );

describe( 'city mode, several places — Blocks chooser', () => {
	it( 'is a plain select of exactly the listed places, and never calls /suggest', async () => {
		render( <LocalityChooser config={ cityMode( [ MOSCOW, KHIMKI ] ) } /> );

		const select = screen.getByRole( 'combobox' ) as HTMLSelectElement;

		expect( select.tagName ).toBe( 'SELECT' );
		expect( Array.from( select.options ).map( ( option ) => [ option.value, option.textContent ] ) ).toEqual( [
			[ '', 'Select…' ],
			[ 'dadata:moscow', 'Москва' ],
			[ 'dadata:khimki', 'Химки' ],
		] );
		// No search box and no suggestion list to type into.
		expect( document.querySelector( 'input[type="text"]' ) ).toBeNull();
		expect( screen.queryByRole( 'listbox' ) ).toBeNull();

		await act( async () => {
			await new Promise( ( resolve ) => setTimeout( resolve, 400 ) );
		} );
		expect( suggestRequests() ).toEqual( [] );
	} );

	it( 'takes the merchant\'s wording for the native City field as its label', () => {
		installNativeRows();
		render( <LocalityChooser config={ cityMode( [ MOSCOW, KHIMKI ] ) } /> );

		expect( document.querySelector( '.woodev-locality-chooser__label' )?.textContent ).toBe( 'Населённый пункт' );
	} );

	it( 'replaces the native City row and hides the State row, flag or not', async () => {
		installNativeRows();
		render( <LocalityChooser config={ cityMode( [ MOSCOW, KHIMKI ], { hideSettlement: true } ) } /> );

		await act( async () => {
			await settle();
		} );

		expect( document.querySelector( '.wc-block-components-address-form__city' ) ).toHaveClass( 'woodev-field--hidden' );
		expect( document.querySelector( '.wc-block-components-address-form__state' ) ).toHaveClass( 'woodev-field--hidden' );
		expect( document.querySelector( '.wc-block-components-address-form__address_1' ) ).not.toHaveClass( 'woodev-field--hidden' );
		expect( screen.getByRole( 'combobox' ) ).toBeInTheDocument();
	} );

	it( 'a pick persists the record, then writes the native City and State of the place', async () => {
		render( <LocalityChooser config={ cityMode( [ MOSCOW, KHIMKI ] ) } /> );
		fireEvent.change( screen.getByRole( 'combobox' ), { target: { value: KHIMKI.key } } );

		await waitFor( () => expect( mockStore.setShippingAddress ).toHaveBeenCalledTimes( 1 ) );

		expect( JSON.parse( String( sent( 'select' )[ 0 ]?.init?.body ) ) ).toEqual( { record: KHIMKI.record } );
		expect( mockStore.setShippingAddress ).toHaveBeenCalledWith( {
			first_name: 'Анна',
			address_1: 'Ленина 1',
			postcode: '101000',
			city: 'Химки',
			state: 'МОСКОВСКАЯ ОБЛАСТЬ',
			country: 'RU',
		} );
		expect( suggestRequests() ).toEqual( [] );
	} );

	it( 'keeps the State row visible when a pick left it empty, so the shopper can choose it', async () => {
		installNativeRows();
		const nowhere = { ...KHIMKI, record: { ...KHIMKI.record, region: { name: 'Нигде' } } };
		render( <LocalityChooser config={ cityMode( [ MOSCOW, nowhere ] ) } /> );
		await act( async () => {
			await settle();
		} );

		expect( document.querySelector( '.wc-block-components-address-form__state' ) ).toHaveClass( 'woodev-field--hidden' );

		fireEvent.change( screen.getByRole( 'combobox' ), { target: { value: nowhere.key } } );
		await waitFor( () => expect( mockStore.setShippingAddress ).toHaveBeenCalledTimes( 1 ) );
		await act( async () => {
			await settle();
		} );

		expect( mockStore.customer.shippingAddress ).toMatchObject( { city: 'Химки', state: '' } );
		expect( document.querySelector( '.wc-block-components-address-form__state' ) ).not.toHaveClass( 'woodev-field--hidden' );
	} );

	it( 'a nested place posts its OWN name (Черкизово), not its parent\'s (Пушкино), and the pick is kept', async () => {
		render( <LocalityChooser config={ cityMode( [ CHERKIZOVO, KHIMKI ] ) } /> );
		fireEvent.change( screen.getByRole( 'combobox' ), { target: { value: CHERKIZOVO.key } } );

		await waitFor( () => expect( mockStore.setShippingAddress ).toHaveBeenCalledTimes( 1 ) );

		expect( mockStore.customer.shippingAddress.city ).toBe( 'Черкизово' );
		// The write is not mistaken for a hand edit: the selection survives it.
		await act( async () => {
			await settle();
		} );
		expect( screen.getByRole( 'combobox' ) ).toHaveValue( CHERKIZOVO.key );
		expect( sent( 'forget' ) ).toHaveLength( 0 );
	} );

	it( 'a remount restores a saved nested pick that the address names by its own name — it is not orphaned', async () => {
		mockStore.customer.shippingAddress = { ...mockStore.customer.shippingAddress, city: 'Черкизово', state: 'МОСКОВСКАЯ ОБЛАСТЬ' };
		render( <LocalityChooser config={ cityMode( [ CHERKIZOVO, KHIMKI ], { selection: { record: CHERKIZOVO.record } } ) } /> );

		await act( async () => {
			await settle();
		} );

		expect( screen.getByRole( 'combobox' ) ).toHaveValue( CHERKIZOVO.key );
		expect( sent( 'forget' ) ).toHaveLength( 0 );
	} );

	it( 'a saved city nobody listed is cleared once with its street and postcode; a listed one is kept', async () => {
		mockStore.customer.shippingAddress = { ...mockStore.customer.shippingAddress, city: 'Тула', state: 'ТУЛЬСКАЯ ОБЛАСТЬ' };
		render( <LocalityChooser config={ cityMode( [ MOSCOW, KHIMKI ] ) } /> );

		await waitFor( () => expect( mockStore.setShippingAddress ).toHaveBeenCalledTimes( 1 ) );

		expect( mockStore.customer.shippingAddress ).toMatchObject( { city: '', state: '', address_1: '', postcode: '' } );
	} );

	it( 'keeps a saved city the merchant listed', async () => {
		mockStore.customer.shippingAddress = { ...mockStore.customer.shippingAddress, city: 'Химки', state: 'МОСКОВСКАЯ ОБЛАСТЬ' };
		render( <LocalityChooser config={ cityMode( [ MOSCOW, KHIMKI ] ) } /> );

		await act( async () => {
			await settle();
		} );

		expect( mockStore.setShippingAddress ).not.toHaveBeenCalled();
	} );

	it( 'does not touch a country the merchant listed no places for: that shopper still searches', async () => {
		mockStore.customer.shippingAddress = { ...mockStore.customer.shippingAddress, country: 'KZ' };
		suggestReply = [ KHIMKI ];
		render( <LocalityChooser config={ cityMode( [ MOSCOW, KHIMKI ] ) } /> );

		fireEvent.change( screen.getByRole( 'combobox' ), { target: { value: 'Алм' } } );
		await screen.findByRole( 'option', {}, { timeout: 2000 } );

		expect( suggestRequests() ).toHaveLength( 1 );
	} );
} );

describe( 'city mode, one place shown — Blocks', () => {
	it( 'writes the place into City and State and shows it as plain text — no chooser, no field', async () => {
		installNativeRows();
		render( <LocalityChooser config={ cityMode( [ MOSCOW ] ) } /> );

		await waitFor( () => expect( mockStore.setShippingAddress ).toHaveBeenCalledTimes( 1 ) );

		expect( document.querySelector( '.woodev-locality-chooser' ) ).toBeNull();
		expect( screen.queryByRole( 'combobox' ) ).toBeNull();
		expect( mockStore.customer.shippingAddress ).toMatchObject( { city: 'Москва', state: 'МОСКВА', country: 'RU', address_1: 'Ленина 1' } );
		// The text, under the merchant's own wording for the field; the native City row makes way for it.
		const text = document.querySelector( '.woodev-city-mode-place' ) as HTMLElement;
		expect( text.textContent ).toBe( 'Населённый пунктМосква' );
		expect( text.querySelector( 'strong' )?.textContent ).toBe( 'Москва' );
		expect( document.querySelector( '.wc-block-components-address-form__city' ) ).toHaveClass( 'woodev-field--hidden' );
		// A saved BLANK city is not a rejected one: the street is left alone.
		expect( mockStore.customer.shippingAddress.postcode ).toBe( '101000' );
	} );

	it( 'a saved Москва with another region\'s State gets the place\'s State written, not left alone', async () => {
		mockStore.customer.shippingAddress = { ...mockStore.customer.shippingAddress, city: 'Москва', state: 'ТУЛЬСКАЯ ОБЛАСТЬ' };
		render( <LocalityChooser config={ cityMode( [ MOSCOW ] ) } /> );

		await waitFor( () => expect( mockStore.setShippingAddress ).toHaveBeenCalledTimes( 1 ) );

		// The city already names the place, so the street and postcode stay.
		expect( mockStore.customer.shippingAddress ).toMatchObject( {
			city: 'Москва', state: 'МОСКВА', address_1: 'Ленина 1', postcode: '101000',
		} );
	} );

	it( 'a State the shopper chose is kept where the place maps to no single option', async () => {
		mockStore.customer.shippingAddress = { ...mockStore.customer.shippingAddress, city: 'Москва', state: 'ТАТАРСТАН' };
		const place = { ...MOSCOW, record: { ...MOSCOW.record, region: { name: 'Нигде' } } };
		render( <LocalityChooser config={ cityMode( [ place ] ) } /> );

		await act( async () => {
			await settle();
		} );

		expect( mockStore.setShippingAddress ).not.toHaveBeenCalled();
		expect( mockStore.customer.shippingAddress.state ).toBe( 'ТАТАРСТАН' );
	} );

	it( 'publishes the place as the scope of the address suggestions', () => {
		render( <LocalityChooser config={ cityMode( [ MOSCOW ] ) } /> );

		expect( readSettlementScope() ).toBe( 'dadata:moscow' );
	} );

	it( 'replaces a saved city that is not the place, and its street and postcode with it', async () => {
		mockStore.customer.shippingAddress = { ...mockStore.customer.shippingAddress, city: 'Тула', state: 'ТУЛЬСКАЯ ОБЛАСТЬ' };
		render( <LocalityChooser config={ cityMode( [ MOSCOW ] ) } /> );

		await waitFor( () => expect( mockStore.setShippingAddress ).toHaveBeenCalledTimes( 1 ) );

		expect( mockStore.customer.shippingAddress ).toMatchObject( { city: 'Москва', state: 'МОСКВА', address_1: '', postcode: '' } );
	} );

	it( 'waits for WooCommerce\'s own cart before writing, so its empty defaults are not the shopper\'s address', async () => {
		mockStore.cartLoaded = false;
		render( <LocalityChooser config={ cityMode( [ MOSCOW ] ) } /> );

		await act( async () => {
			await settle();
		} );

		expect( mockStore.setShippingAddress ).not.toHaveBeenCalled();
	} );
} );

describe( 'city mode, one place hidden — Blocks', () => {
	it( 'hides the City and State rows by class and carries the place in the address STORE, not in a hidden field', async () => {
		installNativeRows();
		render( <LocalityChooser config={ cityMode( [ MOSCOW ], { hideSettlement: true } ) } /> );

		await waitFor( () => expect( mockStore.setShippingAddress ).toHaveBeenCalledTimes( 1 ) );
		await act( async () => {
			await settle();
		} );

		expect( document.querySelector( '.woodev-locality-chooser' ) ).toBeNull();
		// The flag hides the text too: nothing of ours is on the page.
		expect( document.querySelector( '.woodev-city-mode-place' ) ).toBeNull();
		expect( document.querySelector( '.wc-block-components-address-form__city' ) ).toHaveClass( 'woodev-field--hidden' );
		expect( document.querySelector( '.wc-block-components-address-form__state' ) ).toHaveClass( 'woodev-field--hidden' );
		expect( document.querySelector( '.wc-block-components-address-form__address_1' ) ).not.toHaveClass( 'woodev-field--hidden' );
		// What Blocks submits is the store's address: city, state and country of the place, street untouched.
		expect( mockStore.customer.shippingAddress ).toMatchObject( {
			city: 'Москва', state: 'МОСКВА', country: 'RU', address_1: 'Ленина 1', postcode: '101000',
		} );
	} );

	it( 'hides the State row only once the address carries the place\'s State (a saved wrong one is rewritten first)', async () => {
		installNativeRows();
		mockStore.customer.shippingAddress = { ...mockStore.customer.shippingAddress, city: 'Москва', state: 'ТУЛЬСКАЯ ОБЛАСТЬ' };
		render( <LocalityChooser config={ cityMode( [ MOSCOW ], { hideSettlement: true } ) } /> );

		await waitFor( () => expect( mockStore.customer.shippingAddress.state ).toBe( 'МОСКВА' ) );
		await act( async () => {
			await settle();
		} );

		expect( document.querySelector( '.wc-block-components-address-form__state' ) ).toHaveClass( 'woodev-field--hidden' );
	} );

	it( 'keeps the State row visible while no single region matched, so the shopper can still pick it', async () => {
		installNativeRows();
		const place = { ...MOSCOW, record: { ...MOSCOW.record, region: { name: 'Нигде' }, settlement: { name: 'Москва' } } };
		render( <LocalityChooser config={ cityMode( [ place ], { hideSettlement: true } ) } /> );

		await waitFor( () => expect( mockStore.setShippingAddress ).toHaveBeenCalledTimes( 1 ) );
		await act( async () => {
			await settle();
		} );

		expect( document.querySelector( '.wc-block-components-address-form__city' ) ).toHaveClass( 'woodev-field--hidden' );
		expect( document.querySelector( '.wc-block-components-address-form__state' ) ).not.toHaveClass( 'woodev-field--hidden' );
	} );

	it( 'follows the delivery section: the billing-only chooser writes the BILLING address', async () => {
		mockStore.forcedBillingAddress = true;
		render( <LocalityChooser config={ cityMode( [ MOSCOW ], { hideSettlement: true, billingOnly: true } ) } addressTarget="billing" /> );

		await waitFor( () => expect( mockStore.setBillingAddress ).toHaveBeenCalled() );

		expect( mockStore.customer.billingAddress ).toMatchObject( { city: 'Москва', state: 'МОСКВА' } );
	} );

	it( 'the flag does nothing while several places are listed: the select stays', async () => {
		installNativeRows();
		render( <LocalityChooser config={ cityMode( [ MOSCOW, KHIMKI ], { hideSettlement: true } ) } /> );

		await act( async () => {
			await settle();
		} );

		expect( screen.getByRole( 'combobox' ).tagName ).toBe( 'SELECT' );
		expect( document.querySelector( '.woodev-city-mode-place' ) ).toBeNull();
	} );
} );
