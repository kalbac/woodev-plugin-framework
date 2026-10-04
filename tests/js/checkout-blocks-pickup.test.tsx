import '@testing-library/jest-dom';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { useSyncExternalStore as mockUseSyncExternalStore } from 'react';

/*
 * An in-memory stand-in for the WooCommerce stores the pickup button talks to (`pickup-stores.ts`
 * is a thin wrapper over `@wordpress/data`): `wc/store/cart` (the shipping rates and the
 * `woodev-shipping` extension data), `wc/store/payment`, `wc/store/validation` and
 * `wc/store/checkout`'s `disableCheckoutFor`.
 *
 * Two pieces of core behaviour are reproduced because the button's guarantees depend on them, both
 * read from WooCommerce 11.1 (`wc-blocks-data.js`: `gi=`, `Na=`):
 * - `extensionCartUpdate()` takes the server's cart into the store BEFORE it resolves, and rejects
 *   with the Store API's error object;
 * - `disableCheckoutFor( work )` counts a calculation for as long as `work()` is unsettled.
 */
type Rate = { rate_id: string; selected: boolean };
type Address = Record< string, string >;

const mockStore = {
	extensions: {} as Record< string, unknown >,
	rates: [] as Rate[],
	payment: 'cod',
	shipping: {} as Address,
	billing: {} as Address,
	useShippingAsBilling: false,
	/** How many `disableCheckoutFor` calls were unsettled at each address write. */
	addressWrites: [] as number[],
	validation: {} as Record< string, { message: string; hidden: boolean } >,
	/** How many `disableCheckoutFor` calls are unsettled: Place Order is blocked while > 0. */
	calculating: 0,
	listeners: new Set< () => void >(),
};

const notify = (): void => mockStore.listeners.forEach( ( listener ) => listener() );

jest.mock( '@wordpress/data', () => {
	const selectFn = () => ( {
		getCartData: () => ( { extensions: mockStore.extensions } ),
		getShippingRates: () => [ { shipping_rates: mockStore.rates } ],
		getActivePaymentMethod: () => mockStore.payment,
		getValidationError: ( id: string ) => mockStore.validation[ id ],
		getCustomerData: () => ( { shippingAddress: mockStore.shipping, billingAddress: mockStore.billing } ),
		getUseShippingAsBilling: () => mockStore.useShippingAsBilling,
	} );

	return {
		select: selectFn,
		dispatch: () => ( {
			setShippingAddress: ( address: Address ) => {
				mockStore.shipping = address;
				mockStore.addressWrites.push( mockStore.calculating );
			},
			setBillingAddress: ( address: Address ) => {
				mockStore.billing = address;
				mockStore.addressWrites.push( mockStore.calculating );
			},
			setValidationErrors: ( errors: Record< string, { message: string; hidden: boolean } > ) => {
				mockStore.validation = { ...mockStore.validation, ...errors };
				notify();
			},
			clearValidationError: ( id: string ) => {
				const { [ id ]: _dropped, ...rest } = mockStore.validation;

				mockStore.validation = rest;
				notify();
			},
			disableCheckoutFor: async ( work: () => Promise< unknown > ) => {
				mockStore.calculating++;

				try {
					return await work();
				} finally {
					mockStore.calculating--;
				}
			},
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
import { PickupPicker } from '../../src/checkout-blocks/pickup-picker';
// eslint-disable-next-line import/first
import { registerPickupBlock } from '../../src/checkout-blocks/register';
// eslint-disable-next-line import/first
import { captureWcRuntime, wcRuntime } from '../../src/checkout-blocks/wc-runtime';
// eslint-disable-next-line import/first
import type { PickupData, PickupSessionHost, PickupSnapshot } from '../../src/checkout-blocks/pickup-types';

const NAMESPACE = 'woodev-shipping';
const PICKUP_RATE = 'carrier_pickup:7';
const OTHER_PICKUP_RATE = 'carrier_pickup:8';
const COURIER_RATE = 'flat_rate:1';
const ERROR_ID = 'woodev-pickup-carrier-carrier_point';

const data: PickupData = {
	enabled: true,
	namespace: NAMESPACE,
	fields: [ { pluginId: 'carrier', fieldId: 'carrier_point', configKey: 'woodev_pickup_config_carrier' } ],
	i18n: { required: 'Please choose a pickup point.' },
};

const config = {
	fieldId: 'carrier_point',
	pluginId: 'carrier-plugin',
	provider: 'stub',
	strategy: 'bulk',
	restRoot: 'https://shop.test/wp-json/woodev/v1/shipping/pickup/carrier/points',
	nonce: 'rest-nonce',
	mapConfig: { ownsChrome: true },
	selection: { close: true, refreshCheckout: true },
	themeButtonClass: 'wp-element-button',
	accentColor: '#112233',
	i18n: {
		trigger: 'Select a pickup point',
		triggerChange: 'Choose a different pickup point',
		chosenPointAddress: 'Chosen pickup point:',
		modalTitle: 'Choose a pickup point',
		close: 'Close',
		retry: 'Try again',
		loading: 'Loading pickup points…',
		error: 'Could not load pickup points. Please try again.',
		blocked: 'This pickup point is not available for your order.',
		confirming: 'Checking…',
		selectFailed: 'Could not confirm the pickup point. Try again.',
		selectFailedEmbedded: 'Could not confirm the pickup point.',
	},
};

const snapshot = ( over: Partial< PickupSnapshot > = {} ): PickupSnapshot => ( {
	plugin_id: 'carrier',
	field_id: 'carrier_point',
	point_id: 'P1',
	locality: 'dadata:msk',
	rate_id: PICKUP_RATE,
	summary: 'Тверская, 1',
	selection: { allowed: true, reason: null, close: null, refresh_checkout: null, point: null },
	...over,
} );

/** The cart extension data the server answers with for `rate`, holding `point` (or nothing). */
const serverExtension = ( rate: string, point: PickupSnapshot | null ) => ( {
	[ NAMESPACE ]: {
		pickup: { carrier: { carrier_point: point && point.rate_id === rate ? point : null } },
		owner: rate.startsWith( 'carrier_pickup' )
			? { plugin_id: 'carrier', field_id: 'carrier_point', rate_id: rate, locality: 'dadata:msk' }
			: null,
	},
} );

/** The shopper clicks another rate: WooCommerce marks it selected; the server has not answered yet. */
const chooseRate = ( rate: string ): void => {
	mockStore.rates = [ PICKUP_RATE, OTHER_PICKUP_RATE, COURIER_RATE ].map( ( id ) => ( {
		rate_id: id,
		selected: id === rate,
	} ) );
};

/** …and the server's answer to that selection arrives. */
const serverAnswers = ( rate: string, point: PickupSnapshot | null ): void => {
	mockStore.extensions = serverExtension( rate, point );
};

const extensionCartUpdate = jest.fn();
const setExtensionData = jest.fn();

/** The last echo the checkout request would carry for the pickup field. */
const lastEcho = (): unknown => {
	const calls = setExtensionData.mock.calls;

	return calls.length ? calls[ calls.length - 1 ][ 2 ].carrier.carrier_point : undefined;
};

/** A stand-in for `pickup-session.js` that records what it was opened with. */
const fakeSession = () => {
	const destroy = jest.fn();
	const open = jest.fn( () => ( { modal: {}, refresh: () => Promise.resolve(), destroy } ) );

	( window as unknown as Record< string, unknown > ).WoodevPickupSession = { open };

	return {
		open,
		destroy,
		host: (): PickupSessionHost => ( open.mock.calls[ open.mock.calls.length - 1 ] as unknown[] )[ 1 ] as PickupSessionHost,
	};
};

const renderPicker = () => render( <PickupPicker data={ data } checkoutExtensionData={ { setExtensionData } } /> );

const trigger = (): HTMLElement | null => document.querySelector( '.woodev-pickup-trigger' );

const HOME: Address = { city: 'Москва', state: '', country: 'RU', address_1: 'Ленина, 5', postcode: '123456' };
const OFFICE: Address = { city: 'Тула', state: '', country: 'RU', address_1: 'Мира, 9', postcode: '300000' };

beforeEach( () => {
	mockStore.payment = 'cod';
	mockStore.shipping = { ...HOME };
	mockStore.billing = { ...HOME };
	mockStore.useShippingAsBilling = false;
	mockStore.addressWrites = [];
	mockStore.validation = {};
	mockStore.calculating = 0;
	mockStore.listeners.clear();
	chooseRate( PICKUP_RATE );
	serverAnswers( PICKUP_RATE, null );
	extensionCartUpdate.mockReset();
	setExtensionData.mockReset();

	const page = window as unknown as Record< string, unknown >;

	page.woodev_pickup_config_carrier = config;
	page.wc = { blocksCheckout: { extensionCartUpdate } };
	delete page.WoodevPickupSession;
} );

describe( 'PickupPicker — shown for the framework’s pickup rates only', () => {
	it( 'shows the button for a rate the server says one of our fields owns', () => {
		renderPicker();

		expect( screen.getByRole( 'button', { name: 'Select a pickup point' } ) ).toBeInTheDocument();
		expect( trigger() ).toHaveClass( 'button', 'woodev-pickup-trigger', 'wp-element-button' );
		expect( trigger() ).toHaveAttribute( 'aria-haspopup', 'dialog' );
		expect( trigger()?.style.getPropertyValue( '--woodev-pickup-accent' ) ).toBe( '#112233' );
	} );

	it( 'shows nothing for a courier rate', () => {
		chooseRate( COURIER_RATE );
		serverAnswers( COURIER_RATE, null );

		const { container } = renderPicker();

		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'shows nothing when the server published no extension data (WooCommerce below 9.9)', () => {
		mockStore.extensions = {};

		const { container } = renderPicker();

		expect( container ).toBeEmptyDOMElement();
		expect( mockStore.validation ).toEqual( {} );
	} );

	it( 'shows nothing for an owner the bundle has no field for', () => {
		mockStore.extensions = {
			[ NAMESPACE ]: {
				pickup: {},
				owner: { plugin_id: 'other', field_id: 'other_point', rate_id: PICKUP_RATE, locality: '' },
			},
		};

		const { container } = renderPicker();

		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'shows nothing when the owning handler localized no picker config', () => {
		delete ( window as unknown as Record< string, unknown > ).woodev_pickup_config_carrier;

		const { container } = renderPicker();

		expect( container ).toBeEmptyDOMElement();
		expect( mockStore.validation ).toEqual( {} );
	} );
} );

describe( 'PickupPicker — confirmation through the Store API', () => {
	it( 'sends the C-2a command and renders the point from the returned snapshot', async () => {
		const session = fakeSession();

		extensionCartUpdate.mockImplementation( async () => {
			serverAnswers( PICKUP_RATE, snapshot() );
			notify();

			return { extensions: mockStore.extensions };
		} );

		renderPicker();
		fireEvent.click( trigger() as HTMLElement );

		expect( session.open ).toHaveBeenCalledTimes( 1 );
		expect( session.open.mock.calls[ 0 ][ 0 ] ).toBe( config );

		const host = session.host();

		expect( host.returnFocusTo ).toBe( trigger() );
		expect( host.checkoutRefresh ).toBeNull();
		expect( host.getSelectedId() ).toBe( '' );
		expect( host.getLocality() ).toBe( 'Москва' );
		expect( host.getLocalityKey() ).toBe( 'dadata:msk' );
		expect( host.getNonce() ).toBe( 'rest-nonce' );

		let result: unknown;

		await act( async () => {
			result = await host.confirmSelection( { id: 'P1', address: 'ignored' } );
		} );

		// Only the point's ID travels — never the point data, the rate or the carrier.
		expect( extensionCartUpdate ).toHaveBeenCalledTimes( 1 );
		expect( extensionCartUpdate ).toHaveBeenCalledWith( {
			namespace: NAMESPACE,
			data: { pickup: { carrier: { carrier_point: { point_id: 'P1', payment_method: 'cod' } } } },
		} );
		expect( result ).toEqual( { allowed: true, reason: null, close: null, refresh_checkout: null, point: null } );

		expect( screen.getByRole( 'button', { name: 'Choose a different pickup point' } ) ).toBeInTheDocument();
		expect( screen.getByText( 'Тверская, 1' ) ).toBeInTheDocument();
		expect( screen.getByText( /Chosen pickup point:/ ) ).toBeInTheDocument();
		expect( host.getSelectedId() ).toBe( 'P1' );

		// The checkout request echoes the confirmation's identity, and nothing blocks the order.
		expect( lastEcho() ).toEqual( {
			plugin_id: 'carrier',
			field_id: 'carrier_point',
			point_id: 'P1',
			locality: 'dadata:msk',
			rate_id: PICKUP_RATE,
		} );
		expect( mockStore.validation[ ERROR_ID ] ).toBeUndefined();
	} );

	it( 'omits the payment method when the shopper has not chosen one', async () => {
		const session = fakeSession();

		mockStore.payment = '';
		extensionCartUpdate.mockResolvedValue( { extensions: serverExtension( PICKUP_RATE, snapshot() ) } );

		renderPicker();
		fireEvent.click( trigger() as HTMLElement );

		await act( async () => {
			await session.host().confirmSelection( { id: 'P1' } );
		} );

		expect( extensionCartUpdate.mock.calls[ 0 ][ 0 ].data.pickup.carrier.carrier_point ).toEqual( { point_id: 'P1' } );
	} );

	it( 'falls back to the native city when the server holds no locality key', () => {
		const session = fakeSession();

		mockStore.extensions = {
			[ NAMESPACE ]: {
				pickup: { carrier: { carrier_point: null } },
				owner: { plugin_id: 'carrier', field_id: 'carrier_point', rate_id: PICKUP_RATE, locality: '' },
			},
		};

		renderPicker();
		fireEvent.click( trigger() as HTMLElement );

		expect( session.host().getLocalityKey() ).toBe( 'Москва' );
	} );

	it( 'blocks Place Order for as long as a confirmation is in flight', async () => {
		const session = fakeSession();
		let answer: ( cart: unknown ) => void = () => undefined;

		extensionCartUpdate.mockImplementation( () => new Promise( ( resolve ) => ( answer = resolve ) ) );

		renderPicker();
		fireEvent.click( trigger() as HTMLElement );

		expect( mockStore.calculating ).toBe( 0 );

		let pending: Promise< unknown > = Promise.resolve();

		await act( async () => {
			pending = session.host().confirmSelection( { id: 'P1' } );
			await Promise.resolve();
		} );

		expect( mockStore.calculating ).toBe( 1 );
		expect( extensionCartUpdate ).toHaveBeenCalledTimes( 1 );

		await act( async () => {
			answer( { extensions: serverExtension( PICKUP_RATE, snapshot() ) } );
			await pending;
		} );

		expect( mockStore.calculating ).toBe( 0 );
		expect( extensionCartUpdate ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'releases Place Order when the confirmation fails', async () => {
		const session = fakeSession();

		extensionCartUpdate.mockRejectedValue( new Error( 'offline' ) );

		renderPicker();
		fireEvent.click( trigger() as HTMLElement );

		await act( async () => {
			await expect( session.host().confirmSelection( { id: 'P1' } ) ).rejects.toEqual( {
				status: 0,
				code: '',
				message: 'offline',
			} );
		} );

		expect( mockStore.calculating ).toBe( 0 );
	} );
} );

/*
 * WooCommerce 11.1's public payment registry (`wc-blocks-registry.js`): registrations keyed by NAME,
 * each carrying the server gateway's `paymentMethodId` (which defaults to the name).
 */
const registerPayments = ( regular: Record< string, unknown >, express: Record< string, unknown > = {} ): void => {
	( window as unknown as { wc: Record< string, unknown > } ).wc.wcBlocksRegistry = {
		getPaymentMethods: () => regular,
		getExpressPaymentMethods: () => express,
	};
};

describe( 'PickupPicker — the gateway id, not the registration name (#1089)', () => {
	const sentPayment = async ( session: ReturnType< typeof fakeSession > ): Promise< unknown > => {
		extensionCartUpdate.mockResolvedValue( { extensions: serverExtension( PICKUP_RATE, snapshot() ) } );

		await act( async () => {
			await session.host().confirmSelection( { id: 'P1' } );
		} );

		return extensionCartUpdate.mock.calls[ 0 ][ 0 ].data.pickup.carrier.carrier_point.payment_method;
	};

	it( 'confirms with the paymentMethodId of an alias registration', async () => {
		const session = fakeSession();

		// The critic's repro: the payment store names the registration, the server knows the gateway.
		mockStore.payment = 'prepaid-ui';
		registerPayments( { 'prepaid-ui': { name: 'prepaid-ui', paymentMethodId: 'bacs' }, cod: { paymentMethodId: 'cod' } } );

		renderPicker();
		fireEvent.click( trigger() as HTMLElement );

		expect( session.host().getRequestContext() ).toEqual( { payment_method: 'bacs' } );
		expect( await sentPayment( session ) ).toBe( 'bacs' );
	} );

	it( 'resolves an express registration too, and lets a regular one of the same name win', async () => {
		const session = fakeSession();

		mockStore.payment = 'wallet';
		registerPayments( {}, { wallet: { paymentMethodId: 'wallet_gateway' } } );

		renderPicker();
		fireEvent.click( trigger() as HTMLElement );

		expect( session.host().getRequestContext() ).toEqual( { payment_method: 'wallet_gateway' } );

		registerPayments( { wallet: { paymentMethodId: 'regular_gateway' } }, { wallet: { paymentMethodId: 'wallet_gateway' } } );

		expect( await sentPayment( session ) ).toBe( 'regular_gateway' );
	} );

	it( 'sends the name itself when the registry does not know it, or is not there', async () => {
		const session = fakeSession();

		// `constructor` is on every object's prototype: only an OWN registration may answer.
		mockStore.payment = 'constructor';
		registerPayments( { cod: { paymentMethodId: 'cod' } } );

		renderPicker();
		fireEvent.click( trigger() as HTMLElement );

		expect( session.host().getRequestContext() ).toEqual( { payment_method: 'constructor' } );

		delete ( window as unknown as { wc: Record< string, unknown > } ).wc.wcBlocksRegistry;
		mockStore.payment = 'cod';

		expect( await sentPayment( session ) ).toBe( 'cod' );
	} );

	it( 'sends no payment context when nothing is chosen', () => {
		const session = fakeSession();

		mockStore.payment = '';
		registerPayments( { cod: { paymentMethodId: 'cod' } } );

		renderPicker();
		fireEvent.click( trigger() as HTMLElement );

		expect( session.host().getRequestContext() ).toBeNull();
	} );
} );

describe( 'PickupPicker — the store’s address-replacement policy (#1089)', () => {
	const MOVED = { address_1: 'Тверская, 1', postcode: '101000' };

	/** The server confirmed the point and, when `destination` is given, moved the destination to it. */
	const confirmWith = async ( destination: unknown, over: Record< string, unknown > = {} ): Promise< void > => {
		const session = fakeSession();

		( window as unknown as Record< string, unknown > ).woodev_pickup_config_carrier = { ...config, ...over };
		extensionCartUpdate.mockImplementation( async () => {
			serverAnswers( PICKUP_RATE, snapshot( { destination } as Partial< PickupSnapshot > ) );
			notify();

			return { extensions: mockStore.extensions };
		} );

		renderPicker();
		fireEvent.click( trigger() as HTMLElement );

		await act( async () => {
			await session.host().confirmSelection( { id: 'P1' } );
		} );
	};

	it( 'takes the point’s address into the native shipping address, and keeps the confirmation', async () => {
		mockStore.billing = { ...OFFICE };

		await confirmWith( MOVED );

		// Street and postcode are the point's; the city and everything else stay the shopper's.
		expect( mockStore.shipping ).toEqual( { ...HOME, ...MOVED } );
		// A separate billing address is never touched.
		expect( mockStore.billing ).toEqual( OFFICE );
		// Written while Place Order was still held by the confirmation's own gate.
		expect( mockStore.addressWrites ).toEqual( [ 1 ] );
		expect( mockStore.calculating ).toBe( 0 );

		// The confirmation survived the move: the point is shown and echoed, nothing blocks the order.
		expect( screen.getByRole( 'button', { name: 'Choose a different pickup point' } ) ).toBeInTheDocument();
		expect( ( lastEcho() as { point_id: string } ).point_id ).toBe( 'P1' );
		expect( mockStore.validation[ ERROR_ID ] ).toBeUndefined();
	} );

	it( 'moves the billing address with it only where the two are one address', async () => {
		mockStore.useShippingAsBilling = true;

		await confirmWith( MOVED );

		expect( mockStore.shipping ).toEqual( { ...HOME, ...MOVED } );
		expect( mockStore.billing ).toEqual( { ...HOME, ...MOVED } );
	} );

	it( 'moves the billing address in a store that ships to the billing address', async () => {
		await confirmWith( MOVED, { replaceAddress: { enabled: true, billingOnly: true } } );

		expect( mockStore.billing ).toEqual( { ...HOME, ...MOVED } );
	} );

	it.each( [
		{ destination: [] },
		{ destination: undefined },
		{ destination: null },
		{ destination: { address_1: '', postcode: 7 } },
	] )( 'leaves the native address alone when the server moved nothing (the setting is off): %j', async ( { destination } ) => {
		mockStore.useShippingAsBilling = true;
		mockStore.billing = { ...OFFICE };

		await confirmWith( destination );

		expect( mockStore.shipping ).toEqual( HOME );
		expect( mockStore.billing ).toEqual( OFFICE );
		expect( mockStore.addressWrites ).toEqual( [] );
		expect( screen.getByRole( 'button', { name: 'Choose a different pickup point' } ) ).toBeInTheDocument();
	} );

	it( 'writes nothing when the store already holds the moved address', async () => {
		mockStore.shipping = { ...HOME, ...MOVED };

		await confirmWith( MOVED );

		expect( mockStore.addressWrites ).toEqual( [] );
	} );
} );

describe( 'PickupPicker — server refusals', () => {
	it( 'hands the server’s own refusal to the dialog as the point’s verdict', async () => {
		const session = fakeSession();
		const refusal = 'This pickup point does not accept cash on delivery. Choose another point or another payment method.';

		extensionCartUpdate.mockRejectedValue( { code: 'woodev_pickup_validation', message: refusal, data: { status: 400 } } );

		renderPicker();
		fireEvent.click( trigger() as HTMLElement );

		let result: unknown;

		await act( async () => {
			result = await session.host().confirmSelection( { id: 'P1' } );
		} );

		expect( result ).toEqual( { allowed: false, reason: refusal } );

		// Nothing was confirmed: no point is shown or echoed, and the order stays blocked.
		expect( screen.getByRole( 'button', { name: 'Select a pickup point' } ) ).toBeInTheDocument();
		expect( lastEcho() ).toBeNull();
		expect( mockStore.validation[ ERROR_ID ] ).toEqual( { message: 'Please choose a pickup point.', hidden: true } );
	} );

	it( 'treats an exhausted quota as a failed request, not as a verdict on the point', async () => {
		const session = fakeSession();

		extensionCartUpdate.mockRejectedValue( {
			code: 'woodev_pickup_validation',
			message: 'Too many requests. Please wait a moment and try again.',
			data: { status: 429 },
		} );

		renderPicker();
		fireEvent.click( trigger() as HTMLElement );

		await act( async () => {
			await expect( session.host().confirmSelection( { id: 'P1' } ) ).rejects.toMatchObject( {
				status: 429,
				code: 'woodev_pickup_validation',
			} );
		} );
	} );

	it( 'rejects a reply that carries no confirmation for the point', async () => {
		const session = fakeSession();

		extensionCartUpdate.mockResolvedValue( { extensions: serverExtension( PICKUP_RATE, null ) } );

		renderPicker();
		fireEvent.click( trigger() as HTMLElement );

		await act( async () => {
			await expect( session.host().confirmSelection( { id: 'P1' } ) ).rejects.toMatchObject( {
				code: 'woodev_pickup_not_confirmed',
			} );
		} );
	} );

	it( 'rejects when WooCommerce offers no cart-extension transport', async () => {
		const session = fakeSession();

		( window as unknown as Record< string, unknown > ).wc = { blocksCheckout: {} };

		renderPicker();
		fireEvent.click( trigger() as HTMLElement );

		await act( async () => {
			await expect( session.host().confirmSelection( { id: 'P1' } ) ).rejects.toMatchObject( {
				code: 'woodev_pickup_transport_missing',
			} );
		} );
	} );
} );

describe( 'PickupPicker — a required point blocks the order', () => {
	it( 'sets a hidden validation error while our rate has no point, and shows it once WooCommerce reveals it', () => {
		renderPicker();

		expect( mockStore.validation[ ERROR_ID ] ).toEqual( { message: 'Please choose a pickup point.', hidden: true } );
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();

		// The shopper pressed Place Order: WooCommerce un-hides every validation error.
		act( () => {
			mockStore.validation = { [ ERROR_ID ]: { message: 'Please choose a pickup point.', hidden: false } };
			notify();
		} );

		expect( screen.getByRole( 'alert' ) ).toHaveTextContent( 'Please choose a pickup point.' );
		expect( trigger() ).toHaveAttribute( 'aria-describedby', `${ ERROR_ID }-error` );
	} );

	it( 'clears only its own error when the block goes away', () => {
		mockStore.validation = { 'billing_first_name': { message: 'Required', hidden: true } };

		const { unmount } = renderPicker();

		expect( mockStore.validation[ ERROR_ID ] ).toBeDefined();

		unmount();

		expect( mockStore.validation ).toEqual( { 'billing_first_name': { message: 'Required', hidden: true } } );
		expect( lastEcho() ).toBeNull();
	} );
} );

describe( 'PickupPicker — restore and clear', () => {
	it( 'restores the confirmed point after a reload from the cart’s snapshot', () => {
		const session = fakeSession();

		serverAnswers( PICKUP_RATE, snapshot() );

		renderPicker();

		expect( screen.getByRole( 'button', { name: 'Choose a different pickup point' } ) ).toBeInTheDocument();
		expect( screen.getByText( 'Тверская, 1' ) ).toBeInTheDocument();
		expect( lastEcho() ).toMatchObject( { point_id: 'P1', rate_id: PICKUP_RATE } );
		expect( mockStore.validation[ ERROR_ID ] ).toBeUndefined();
		expect( extensionCartUpdate ).not.toHaveBeenCalled();

		fireEvent.click( trigger() as HTMLElement );

		expect( session.host().getSelectedId() ).toBe( 'P1' );
	} );

	it( 'clears the point the moment the shopper switches to a courier rate — before the server answers', () => {
		serverAnswers( PICKUP_RATE, snapshot() );

		const { container } = renderPicker();

		expect( screen.getByText( 'Тверская, 1' ) ).toBeInTheDocument();

		// The click: the rate is selected locally, the extension data is still the old rate's.
		act( () => {
			chooseRate( COURIER_RATE );
			notify();
		} );

		expect( container ).toBeEmptyDOMElement();
		expect( lastEcho() ).toBeNull();
		expect( mockStore.validation[ ERROR_ID ] ).toBeUndefined();

		// The server's answer changes nothing more.
		act( () => {
			serverAnswers( COURIER_RATE, snapshot() );
			notify();
		} );

		expect( container ).toBeEmptyDOMElement();
		expect( lastEcho() ).toBeNull();
	} );

	it( 'asks for a point again on another pickup rate, never showing the previous rate’s point', () => {
		serverAnswers( PICKUP_RATE, snapshot() );

		renderPicker();

		act( () => {
			chooseRate( OTHER_PICKUP_RATE );
			serverAnswers( OTHER_PICKUP_RATE, snapshot() );
			notify();
		} );

		expect( screen.getByRole( 'button', { name: 'Select a pickup point' } ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Тверская, 1' ) ).not.toBeInTheDocument();
		expect( lastEcho() ).toBeNull();
		expect( mockStore.validation[ ERROR_ID ] ).toEqual( { message: 'Please choose a pickup point.', hidden: true } );
	} );

	it( 'does not show a snapshot that names another rate than the one chosen', () => {
		mockStore.extensions = {
			[ NAMESPACE ]: {
				pickup: { carrier: { carrier_point: snapshot( { rate_id: OTHER_PICKUP_RATE } ) } },
				owner: { plugin_id: 'carrier', field_id: 'carrier_point', rate_id: PICKUP_RATE, locality: 'dadata:msk' },
			},
		};

		renderPicker();

		expect( screen.getByRole( 'button', { name: 'Select a pickup point' } ) ).toBeInTheDocument();
		expect( lastEcho() ).toBeNull();
	} );

	it( 'shows the point again when the shopper returns to the rate it was confirmed for', () => {
		serverAnswers( PICKUP_RATE, snapshot() );

		renderPicker();

		act( () => {
			chooseRate( COURIER_RATE );
			serverAnswers( COURIER_RATE, snapshot() );
			notify();
		} );
		act( () => {
			chooseRate( PICKUP_RATE );
			serverAnswers( PICKUP_RATE, snapshot() );
			notify();
		} );

		expect( screen.getByText( 'Тверская, 1' ) ).toBeInTheDocument();
		expect( lastEcho() ).toMatchObject( { point_id: 'P1' } );
	} );

	it( 'tears an open session down when the rate changes under it, and on unmount', () => {
		const session = fakeSession();
		const { unmount } = renderPicker();

		fireEvent.click( trigger() as HTMLElement );

		expect( session.destroy ).not.toHaveBeenCalled();

		act( () => {
			chooseRate( OTHER_PICKUP_RATE );
			serverAnswers( OTHER_PICKUP_RATE, null );
			notify();
		} );

		expect( session.destroy ).toHaveBeenCalledTimes( 1 );

		fireEvent.click( trigger() as HTMLElement );
		unmount();

		expect( session.destroy ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'opens one session at a time and says so when the map runtime is missing', () => {
		renderPicker();
		fireEvent.click( trigger() as HTMLElement );

		expect( screen.getByRole( 'alert' ) ).toHaveTextContent( 'Could not load pickup points. Please try again.' );

		const session = fakeSession();

		fireEvent.click( trigger() as HTMLElement );
		fireEvent.click( trigger() as HTMLElement );

		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
		expect( session.open ).toHaveBeenCalledTimes( 2 );
		expect( session.destroy ).toHaveBeenCalledTimes( 1 );
	} );
} );

/*
 * The REAL map session (`pickup-session.js`) and the REAL dialog (`woodev-modal.js`) under the
 * block's host — the extraction's other consumer. The map provider is a stub that owns the whole
 * dialog body (`ownsChrome`), so no panels and no points fetch are involved.
 */
describe( 'PickupPicker — with the shared map session', () => {
	type Handler = ( payload?: unknown ) => void;

	let provider: { handlers: Record< string, Handler[] >; destroyed: boolean } | null = null;

	beforeEach( () => {
		provider = null;

		const page = window as unknown as Record< string, unknown >;

		// eslint-disable-next-line @typescript-eslint/no-var-requires
		require( '../../woodev/assets/js/frontend/woodev-modal' );
		// eslint-disable-next-line @typescript-eslint/no-var-requires
		page.WoodevPickupSession = require( '../../woodev/shipping-method/assets/js/frontend/pickup-session' );
		page.WoodevPickupDataSource = () => ( {} );
		page.WoodevPickupMapProviders = {
			stub: function StubProvider( this: { on: unknown; init: unknown; destroy: unknown } ) {
				const state = { handlers: {} as Record< string, Handler[] >, destroyed: false };

				provider = state;
				this.on = ( event: string, handler: Handler ) => {
					( state.handlers[ event ] ||= [] ).push( handler );
				};
				this.init = () => Promise.resolve();
				this.destroy = () => {
					state.destroyed = true;
				};
			},
		};
	} );

	afterEach( () => {
		document.body.querySelectorAll( '.woodev-modal' ).forEach( ( node ) => node.remove() );
	} );

	const pick = ( point: unknown ): void => provider?.handlers.select.forEach( ( handler ) => handler( point ) );

	it( 'opens an accessible dialog, confirms through the Store API and closes on an accepted point', async () => {
		extensionCartUpdate.mockImplementation( async () => {
			serverAnswers( PICKUP_RATE, snapshot() );
			notify();

			return { extensions: mockStore.extensions };
		} );

		renderPicker();

		await act( async () => {
			fireEvent.click( trigger() as HTMLElement );
			await Promise.resolve();
		} );

		const dialog = document.querySelector( '[role="dialog"]' );

		expect( dialog ).toHaveAttribute( 'aria-modal', 'true' );
		expect( dialog ).toHaveTextContent( 'Choose a pickup point' );

		const selected = jest.fn();

		document.body.addEventListener( 'woodev_pickup_point_selected', selected );

		await act( async () => {
			pick( { id: 'P1', short_address: 'Тверская, 1' } );
			await Promise.resolve();
			await Promise.resolve();
			await Promise.resolve();
		} );

		document.body.removeEventListener( 'woodev_pickup_point_selected', selected );

		expect( extensionCartUpdate ).toHaveBeenCalledTimes( 1 );
		expect( selected ).toHaveBeenCalledTimes( 1 );
		expect( document.querySelector( '[role="dialog"]' ) ).not.toBeInTheDocument();
		expect( provider?.destroyed ).toBe( true );
		expect( screen.getByText( 'Тверская, 1' ) ).toBeInTheDocument();
		expect( mockStore.calculating ).toBe( 0 );
	} );

	/*
	 * #1089, the critic's repro: the session still says «cod» from an earlier checkout, the shopper
	 * has switched the block checkout to a prepaid method. The points routes must be asked with the
	 * LIVE gateway, or a point that takes no cash comes back not selectable and its CTA is dead.
	 * (The server half — the declared gateway beats the stale session only when the store offers
	 * it — is `PickupHandlerTest::test_a_declared_available_gateway_beats_a_stale_session_cod`.)
	 */
	it( 'asks for points and details with the live gateway, read per request', async () => {
		const page = window as unknown as Record< string, unknown >;
		const factory = jest.fn( ( _options: Record< string, unknown > ) => ( {} ) );

		page.WoodevPickupDataSource = factory;
		mockStore.payment = 'prepaid-ui';
		registerPayments( { 'prepaid-ui': { paymentMethodId: 'bacs' }, cod: { paymentMethodId: 'cod' } } );

		renderPicker();

		await act( async () => {
			fireEvent.click( trigger() as HTMLElement );
			await Promise.resolve();
		} );

		const options = factory.mock.calls[ 0 ][ 0 ] as { context: () => unknown };

		expect( options.context() ).toEqual( { payment_method: 'bacs' } );

		// What the session handed over, through the real data source: both routes carry it.
		// eslint-disable-next-line @typescript-eslint/no-var-requires
		const createDataSource = require( '../../woodev/shipping-method/assets/js/frontend/pickup-datasource' );
		const fetchMock = jest.fn( () =>
			Promise.resolve( { ok: true, status: 200, json: () => Promise.resolve( { points: [] } ) } )
		);
		const globals = global as unknown as { fetch?: unknown };

		globals.fetch = fetchMock;

		try {
			const dataSource = createDataSource( { ...options, debounceMs: 0 } );

			await dataSource.fetchPoints( { locality: 'dadata:msk' } );
			await dataSource.fetchDetails( 'P1' );

			mockStore.payment = 'cod';
			await dataSource.fetchDetails( 'P1' );
		} finally {
			delete globals.fetch;
		}

		expect( fetchMock.mock.calls.map( ( call ) => ( call as unknown[] )[ 0 ] ) ).toEqual( [
			`${ config.restRoot }?locality=dadata%3Amsk&payment_method=bacs`,
			`${ config.restRoot }/P1?payment_method=bacs`,
			`${ config.restRoot }/P1?payment_method=cod`,
		] );
	} );

	it( 'keeps the dialog open and shows the server’s refusal in it', async () => {
		const refusal = 'This pickup point does not accept cash on delivery. Choose another point or another payment method.';

		extensionCartUpdate.mockRejectedValue( { code: 'woodev_pickup_validation', message: refusal, data: { status: 400 } } );

		renderPicker();

		await act( async () => {
			fireEvent.click( trigger() as HTMLElement );
			await Promise.resolve();
		} );
		await act( async () => {
			pick( { id: 'P1' } );
			await Promise.resolve();
			await Promise.resolve();
			await Promise.resolve();
		} );

		const dialog = document.querySelector( '[role="dialog"]' );

		expect( dialog ).toBeInTheDocument();
		expect( dialog ).toHaveTextContent( refusal );
		expect( screen.getByRole( 'button', { name: 'Select a pickup point' } ) ).toBeInTheDocument();
		expect( mockStore.calculating ).toBe( 0 );
	} );

	it( 'shows the confirmation failure in the dialog when the request itself fails', async () => {
		extensionCartUpdate.mockRejectedValue( new Error( 'offline' ) );

		renderPicker();

		await act( async () => {
			fireEvent.click( trigger() as HTMLElement );
			await Promise.resolve();
		} );
		await act( async () => {
			pick( { id: 'P1' } );
			await Promise.resolve();
			await Promise.resolve();
			await Promise.resolve();
		} );

		expect( document.querySelector( '[role="dialog"]' ) ).toHaveTextContent( 'Could not confirm the pickup point.' );
	} );

	it( 'closes without asking again when the shopper confirms the point already chosen', async () => {
		serverAnswers( PICKUP_RATE, snapshot() );

		renderPicker();

		await act( async () => {
			fireEvent.click( trigger() as HTMLElement );
			await Promise.resolve();
		} );
		await act( async () => {
			pick( { id: 'P1' } );
			await Promise.resolve();
		} );

		expect( extensionCartUpdate ).not.toHaveBeenCalled();
		expect( document.querySelector( '[role="dialog"]' ) ).not.toBeInTheDocument();
	} );

	it( 'never triggers a classic checkout refresh', async () => {
		const jqueryTrigger = jest.fn();
		const page = window as unknown as Record< string, unknown >;

		page.jQuery = () => ( { trigger: jqueryTrigger, one: jest.fn(), off: jest.fn(), on: jest.fn() } );

		extensionCartUpdate.mockImplementation( async () => {
			serverAnswers( PICKUP_RATE, snapshot( { selection: { allowed: true, close: true, refresh_checkout: true } } ) );
			notify();

			return { extensions: mockStore.extensions };
		} );

		renderPicker();

		await act( async () => {
			fireEvent.click( trigger() as HTMLElement );
			await Promise.resolve();
		} );
		await act( async () => {
			pick( { id: 'P1' } );
			await Promise.resolve();
			await Promise.resolve();
			await Promise.resolve();
		} );

		delete page.jQuery;

		expect( jqueryTrigger ).not.toHaveBeenCalled();
		expect( document.querySelector( '[role="dialog"]' ) ).not.toBeInTheDocument();
	} );
} );

describe( 'registerPickupBlock — feature detection', () => {
	const published = ( value: unknown ) => ( name: string, fallback: unknown ) =>
		name === 'woodev-shipping-pickup_data' ? value : fallback;

	it( 'registers nothing without registerCheckoutBlock from WooCommerce Blocks', () => {
		( window as unknown as { wc: unknown } ).wc = { wcSettings: { getSetting: published( data ) } };

		expect( registerPickupBlock() ).toBe( false );
	} );

	it( 'registers nothing when no carrier has a pickup field', () => {
		const register = jest.fn();

		for ( const value of [ null, { enabled: false }, { enabled: true, namespace: NAMESPACE, fields: [] } ] ) {
			( window as unknown as { wc: unknown } ).wc = {
				blocksCheckout: { registerCheckoutBlock: register },
				wcSettings: { getSetting: published( value ) },
			};

			expect( registerPickupBlock() ).toBe( false );
		}

		expect( register ).not.toHaveBeenCalled();
	} );

	it( 'registers a FORCED inner block of the shipping methods block', () => {
		const register = jest.fn();

		( window as unknown as { wc: unknown } ).wc = {
			blocksCheckout: { registerCheckoutBlock: register },
			wcSettings: { getSetting: published( data ) },
		};

		expect( registerPickupBlock() ).toBe( true );
		expect( register ).toHaveBeenCalledTimes( 1 );

		const options = register.mock.calls[ 0 ][ 0 ];

		expect( options.force ).toBe( true );
		expect( options.metadata.name ).toBe( 'woodev/shipping-pickup' );
		expect( options.metadata.parent ).toEqual( [ 'woocommerce/checkout-shipping-methods-block' ] );
		expect( typeof options.component ).toBe( 'function' );
	} );
} );

/*
 * WooCommerce's dependency detection (11.1, SCRIPT_DEBUG) names the script behind every read of
 * `window.wc.<exported key>`. It can only do that reliably while the script is being evaluated, so
 * the bundle takes its globals then and never goes back to `window.wc` — a later read was reported
 * as «an inline or unknown script» although the dependency is declared (#1089, measured on the rig).
 */
describe( 'the WooCommerce globals are taken once, at evaluation', () => {
	const page = window as unknown as { wc?: unknown };

	/** `window.wc` as a proxy that counts reads of the keys WooCommerce watches. */
	function watchedRuntime( runtime: Record< string, unknown > ): { reads: string[] } {
		const reads: string[] = [];

		page.wc = new Proxy( runtime, {
			get( target, key ) {
				reads.push( String( key ) );

				return Reflect.get( target, key );
			},
		} );

		return { reads };
	}

	afterEach( () => {
		delete page.wc;
		captureWcRuntime();
	} );

	it( 'reads each global once and answers later reads from the capture', () => {
		const getSetting = jest.fn();
		const extensionCartUpdate = jest.fn();
		const getPaymentMethods = jest.fn();
		const { reads } = watchedRuntime( {
			blocksCheckout: { extensionCartUpdate },
			wcBlocksRegistry: { getPaymentMethods },
			wcSettings: { getSetting },
			wcBlocksData: {},
		} );

		captureWcRuntime();

		expect( [ ...reads ].sort() ).toEqual( [ 'blocksCheckout', 'wcBlocksRegistry', 'wcSettings' ] );

		reads.length = 0;

		expect( wcRuntime()?.wcSettings?.getSetting ).toBe( getSetting );
		expect( wcRuntime()?.blocksCheckout?.extensionCartUpdate ).toBe( extensionCartUpdate );
		expect( wcRuntime()?.wcBlocksRegistry?.getPaymentMethods ).toBe( getPaymentMethods );
		expect( reads ).toEqual( [] );
	} );

	it( 'reads the page live when nothing was captured', () => {
		delete page.wc;
		captureWcRuntime();

		expect( wcRuntime() ).toBeUndefined();

		const getSetting = jest.fn();

		page.wc = { wcSettings: { getSetting } };

		expect( wcRuntime()?.wcSettings?.getSetting ).toBe( getSetting );
	} );
} );
