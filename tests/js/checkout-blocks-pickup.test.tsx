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
 *   with the Store API's error object. The cart's ADDRESSES are part of that whenever the shopper
 *   has no unsaved edit (`WOOCOMMERCE_CHECKOUT_IS_CUSTOMER_DATA_DIRTY`) — the tests that depend on
 *   it say so (`coreTakesReplyAddress`, #1091);
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
	/** `core/notices`, by notice id: what the checkout shows in its own notice areas. */
	notices: {} as Record< string, { message: string; context: string; isDismissible: boolean } >,
	/** `true` simulates a page where the `core/notices` store offers no such actions. */
	withoutNotices: false,
	/** How many `disableCheckoutFor` calls are unsettled: Place Order is blocked while > 0. */
	calculating: 0,
	refreshes: [] as unknown[][],
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
			updateCustomerData: ( ...args: unknown[] ) => {
				mockStore.refreshes.push( args );
				return Promise.resolve();
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
			...( mockStore.withoutNotices
				? {}
				: {
						createErrorNotice: (
							message: string,
							options: { id: string; context: string; isDismissible: boolean }
						) => {
							mockStore.notices[ options.id ] = {
								message,
								context: options.context,
								isDismissible: options.isDismissible,
							};
						},
						removeNotice: ( id: string, context: string ) => {
							if ( mockStore.notices[ id ]?.context === context ) {
								delete mockStore.notices[ id ];
							}
						},
				  } ),
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
import { createHost } from '../../src/checkout-blocks/pickup-host';
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
	i18n: {
		required: 'Please choose a pickup point.',
		chooseLocality: 'Choose your locality from the suggestions to see pickup points.',
	},
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
const checkoutFailListeners = new Set< () => void | Promise< void > >();
const subscribeCheckoutFail = jest.fn( ( listener: () => void | Promise< void > ) => {
	checkoutFailListeners.add( listener );
	return () => checkoutFailListeners.delete( listener );
} );

/**
 * The cart `extensionCartUpdate()` resolves with — taken into the store first, as core does
 * (`wc-blocks-data.js`: `gi=`), so what the block reads when the answer lands is that answer.
 */
const cartAnswer = ( rate: string, point: PickupSnapshot | null ): { extensions: Record< string, unknown > } => {
	serverAnswers( rate, point );
	notify();

	return { extensions: mockStore.extensions };
};

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
	mockStore.notices = {};
	mockStore.withoutNotices = false;
	mockStore.calculating = 0;
	mockStore.refreshes = [];
	mockStore.listeners.clear();
	chooseRate( PICKUP_RATE );
	serverAnswers( PICKUP_RATE, null );
	extensionCartUpdate.mockReset();
	setExtensionData.mockReset();
	checkoutFailListeners.clear();
	subscribeCheckoutFail.mockClear();

	const page = window as unknown as Record< string, unknown >;

	page.woodev_pickup_config_carrier = config;
	page.wc = {
		blocksCheckout: { extensionCartUpdate },
		blocksCheckoutEvents: { checkoutEvents: { onCheckoutFail: subscribeCheckoutFail } },
	};
	delete page.WoodevPickupSession;
} );

describe( 'PickupPicker — shown for the framework’s pickup rates only', () => {
	it( 'refreshes the cart through WooCommerce after a failed checkout attempt', async () => {
		renderPicker();

		expect( subscribeCheckoutFail ).toHaveBeenCalledTimes( 1 );
		const onFailure = [ ...checkoutFailListeners ][ 0 ];

		act( () => {
			expect( onFailure() ).toBeUndefined();
		} );

		expect( mockStore.refreshes ).toEqual( [ [ { shipping_address: { city: 'Москва', state: '', country: 'RU' } }, true, true ] ] );
	} );

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
		extensionCartUpdate.mockImplementation( async () => cartAnswer( PICKUP_RATE, snapshot() ) );

		renderPicker();
		fireEvent.click( trigger() as HTMLElement );

		await act( async () => {
			await session.host().confirmSelection( { id: 'P1' } );
		} );

		expect( extensionCartUpdate.mock.calls[ 0 ][ 0 ].data.pickup.carrier.carrier_point ).toEqual( { point_id: 'P1' } );
	} );

	it( 'addresses the points by the locality the cart holds', () => {
		const session = fakeSession();

		renderPicker();
		fireEvent.click( trigger() as HTMLElement );

		expect( session.open ).toHaveBeenCalledTimes( 1 );
		expect( session.host().getLocalityKey() ).toBe( 'dadata:msk' );
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
			answer( cartAnswer( PICKUP_RATE, snapshot() ) );
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
		extensionCartUpdate.mockImplementation( async () => cartAnswer( PICKUP_RATE, snapshot() ) );

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

	/*
	 * #1091, measured in the browser against WooCommerce 11.1: the shopper's street has been pushed
	 * (nothing unsaved), so core takes the reply's shipping address — already the point's — into the
	 * cart store BEFORE `extensionCartUpdate()` resolves. That is this confirmation's own move, not
	 * an edit: the dialog must get the verdict (it showed «Could not confirm your choice» over a
	 * point the server had kept), and billing must still follow where the two are one address.
	 */
	describe( 'when core has already taken the reply’s address into the store (#1091)', () => {
		/** `extensionCartUpdate()` as core runs it for a shopper with no unsaved edit. */
		const coreTakesReplyAddress = ( destination: Record< string, string >, over: Partial< PickupSnapshot > = {} ) =>
			extensionCartUpdate.mockImplementationOnce( async () => {
				serverAnswers( PICKUP_RATE, snapshot( { destination, ...over } as Partial< PickupSnapshot > ) );
				mockStore.shipping = { ...mockStore.shipping, ...destination };
				notify();

				return { extensions: mockStore.extensions };
			} );

		it( 'answers the dialog with the verdict instead of «superseded»', async () => {
			const session = fakeSession();

			coreTakesReplyAddress( MOVED );
			renderPicker();
			fireEvent.click( trigger() as HTMLElement );

			let verdict: unknown;

			await act( async () => {
				verdict = await session.host().confirmSelection( { id: 'P1' } );
			} );

			expect( verdict ).toMatchObject( { allowed: true } );
			expect( mockStore.shipping ).toEqual( { ...HOME, ...MOVED } );
			// Core wrote the shipping address; nothing was left for the block to write.
			expect( mockStore.addressWrites ).toEqual( [] );
		} );

		it( 'still moves the billing address where the two are one address', async () => {
			const session = fakeSession();

			mockStore.useShippingAsBilling = true;
			coreTakesReplyAddress( MOVED );
			renderPicker();
			fireEvent.click( trigger() as HTMLElement );

			await act( async () => {
				await session.host().confirmSelection( { id: 'P1' } );
			} );

			expect( mockStore.billing ).toEqual( { ...HOME, ...MOVED } );
		} );

		it( 'takes a second point’s move that names the street only, after the first moved the postcode too', async () => {
			const session = fakeSession();
			const ARBAT = { address_1: 'Арбат, 2' };

			coreTakesReplyAddress( MOVED );
			coreTakesReplyAddress( ARBAT, { point_id: 'P2' } );
			renderPicker();
			fireEvent.click( trigger() as HTMLElement );

			const host = session.host();
			let second: unknown;

			await act( async () => {
				// Both asked before either answered: the second was asked for the shopper's own address.
				const first = host.confirmSelection( { id: 'P1' } );

				second = await host.confirmSelection( { id: 'P2' } );
				await first;
			} );

			expect( second ).toMatchObject( { allowed: true } );
			expect( mockStore.shipping ).toEqual( { ...HOME, ...MOVED, ...ARBAT } );
		} );

		it( 'still leaves a street the shopper typed while the confirmation was in flight', async () => {
			const session = fakeSession();

			// The edit is unsaved, so core keeps the reply's addresses out of the store.
			extensionCartUpdate.mockImplementationOnce( async () => {
				mockStore.shipping = { ...HOME, address_1: 'New manual street' };
				serverAnswers( PICKUP_RATE, snapshot( { destination: MOVED } as Partial< PickupSnapshot > ) );
				notify();

				return { extensions: mockStore.extensions };
			} );
			renderPicker();
			fireEvent.click( trigger() as HTMLElement );

			let error: unknown;

			await act( async () => {
				await session.host().confirmSelection( { id: 'P1' } ).catch( ( reason: unknown ) => {
					error = reason;
				} );
			} );

			expect( error ).toMatchObject( { code: 'woodev_pickup_superseded' } );
			expect( mockStore.shipping ).toEqual( { ...HOME, address_1: 'New manual street' } );
		} );
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

/*
 * #1110, from the C-4 browser acceptance: a hand-typed city the chooser never resolved leaves the
 * cart with no locality. The points route is not a Store API request and listed the store's default
 * locality's points under whatever City said; the confirmation sees no locality and refused every
 * one of them with the generic «choose a pickup point». The order was impossible and the shopper was
 * never told to pick the city from the suggestions.
 */
describe( 'PickupPicker — a cart with no resolved locality (#1110)', () => {
	const HINT = 'Choose your locality from the suggestions to see pickup points.';

	/** The server's answer for a typed city it never resolved: our rate, no locality key. */
	const unresolved = (): void => {
		mockStore.shipping = { ...HOME, city: 'Краснодар' };
		mockStore.extensions = {
			[ NAMESPACE ]: {
				pickup: { carrier: { carrier_point: null } },
				owner: { plugin_id: 'carrier', field_id: 'carrier_point', rate_id: PICKUP_RATE, locality: '' },
			},
		};
	};

	it( 'opens no dialog and asks for no foreign points — it says to choose the locality', () => {
		const session = fakeSession();

		unresolved();
		renderPicker();
		fireEvent.click( trigger() as HTMLElement );

		expect( session.open ).not.toHaveBeenCalled();
		expect( extensionCartUpdate ).not.toHaveBeenCalled();
		expect( screen.getByRole( 'status' ) ).toHaveTextContent( HINT );
		expect( trigger() ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( trigger() ).not.toBeDisabled();
		expect( trigger() ).toHaveAttribute( 'aria-describedby', `${ ERROR_ID }-hint` );
	} );

	it( 'never addresses the points by the typed city', () => {
		unresolved();

		const host = createHost( {
			data,
			field: data.fields![ 0 ],
			config,
			trigger: null,
			onClose: () => undefined,
		} );

		// The name the map centres on stays the typed one; the KEY the points are asked by does not.
		expect( host.getLocality() ).toBe( 'Краснодар' );
		expect( host.getLocalityKey() ).toBe( '' );
	} );

	it( 'puts the same words on the order’s validation error instead of «choose a pickup point»', () => {
		unresolved();
		renderPicker();

		expect( mockStore.validation[ ERROR_ID ] ).toEqual( { message: HINT, hidden: true } );

		act( () => {
			mockStore.validation = { [ ERROR_ID ]: { message: HINT, hidden: false } };
			notify();
		} );

		// One copy of the words: the revealed error replaces the standing hint.
		expect( screen.getByRole( 'alert' ) ).toHaveTextContent( HINT );
		expect( screen.queryByRole( 'status' ) ).not.toBeInTheDocument();
		expect( trigger() ).toHaveAttribute( 'aria-describedby', `${ ERROR_ID }-error` );
	} );

	it( 'opens the dialog, on that locality’s points, once the cart holds one', () => {
		const session = fakeSession();

		unresolved();
		renderPicker();

		expect( screen.getByRole( 'status' ) ).toHaveTextContent( HINT );

		// The shopper picks «Краснодар» from the suggestions; the cart answers with its key.
		act( () => {
			mockStore.extensions = {
				[ NAMESPACE ]: {
					pickup: { carrier: { carrier_point: null } },
					owner: { plugin_id: 'carrier', field_id: 'carrier_point', rate_id: PICKUP_RATE, locality: 'dadata:krd' },
				},
			};
			notify();
		} );

		expect( screen.queryByRole( 'status' ) ).not.toBeInTheDocument();
		expect( trigger() ).not.toHaveAttribute( 'aria-disabled' );
		expect( mockStore.validation[ ERROR_ID ] ).toEqual( { message: 'Please choose a pickup point.', hidden: true } );

		fireEvent.click( trigger() as HTMLElement );

		expect( session.open ).toHaveBeenCalledTimes( 1 );
		expect( session.host().getLocalityKey() ).toBe( 'dadata:krd' );
	} );

	it( 'keeps the store’s default locality for an empty city — it arrives as the owner’s key', () => {
		const session = fakeSession();

		mockStore.shipping = { ...HOME, city: '' };
		renderPicker();
		fireEvent.click( trigger() as HTMLElement );

		expect( screen.queryByRole( 'status' ) ).not.toBeInTheDocument();
		expect( session.open ).toHaveBeenCalledTimes( 1 );
		expect( session.host().getLocalityKey() ).toBe( 'dadata:msk' );
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

	/*
	 * #1091, measured on a phone viewport against WooCommerce 11.1: Place Order was pressed with
	 * every native field valid and no point chosen. Core then scrolls to the TOP of the checkout —
	 * it goes to an invalid field only when the field is its own — so the message under the button
	 * was ~700 px out of view, nothing at the top said anything, and the button looked dead.
	 */
	describe( 'when WooCommerce reveals the error (#1091)', () => {
		const REVEALED = { message: 'Please choose a pickup point.', hidden: false };
		const NOTICE = { message: 'Please choose a pickup point.', context: 'wc/checkout', isDismissible: false };
		const reveal = (): void =>
			act( () => {
				mockStore.validation = { ...mockStore.validation, [ ERROR_ID ]: REVEALED };
				notify();
			} );

		it( 'says it in the checkout’s own notices too, where core scrolls to', () => {
			renderPicker();

			// A hidden error blocks the order; it is not shown anywhere yet.
			expect( mockStore.notices ).toEqual( {} );

			reveal();

			expect( mockStore.notices ).toEqual( { [ ERROR_ID ]: NOTICE } );
			// The message under the button stays, tied to it.
			expect( screen.getByRole( 'alert' ) ).toHaveTextContent( 'Please choose a pickup point.' );
		} );

		it( 'takes the notice back once a point is confirmed', () => {
			renderPicker();
			reveal();

			act( () => {
				cartAnswer( PICKUP_RATE, snapshot() );
			} );

			expect( mockStore.notices ).toEqual( {} );
		} );

		it( 'takes the notice back when the shopper leaves the rate, and when the block goes away', () => {
			const { unmount } = renderPicker();

			reveal();

			act( () => {
				chooseRate( COURIER_RATE );
				notify();
			} );

			expect( mockStore.notices ).toEqual( {} );

			act( () => {
				chooseRate( PICKUP_RATE );
				notify();
			} );
			reveal();
			expect( mockStore.notices ).toEqual( { [ ERROR_ID ]: NOTICE } );

			unmount();

			expect( mockStore.notices ).toEqual( {} );
		} );

		it( 'still shows the message under the button where the page has no notices to say it in', () => {
			mockStore.withoutNotices = true;

			const { unmount } = renderPicker();

			reveal();

			expect( screen.getByRole( 'alert' ) ).toHaveTextContent( 'Please choose a pickup point.' );
			expect( mockStore.validation[ ERROR_ID ] ).toEqual( REVEALED );
			expect( () => unmount() ).not.toThrow();
		} );

		it( 'never touches another notice of the checkout', () => {
			mockStore.notices.payment = { message: 'Card declined', context: 'wc/checkout', isDismissible: true };

			const { unmount } = renderPicker();

			reveal();
			unmount();

			expect( mockStore.notices ).toEqual( {
				payment: { message: 'Card declined', context: 'wc/checkout', isDismissible: true },
			} );
		} );
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
 * SP-11 C-3 (#1090): answers that arrive late, out of order, or after a failed order. Every request
 * is held open by the test and answered at a moment the test chooses, so the order of events is
 * the test's own — no timers, no real network.
 */
describe( 'PickupPicker — late and repeated answers (#1090)', () => {
	const MOVED = { address_1: 'Тверская, 1', postcode: '101000' };

	type Held = { resolve: ( cart: unknown ) => void; reject: ( error: unknown ) => void };

	/** Holds every `extensionCartUpdate()` open; `held[ n ]` settles the n-th one. */
	const holdConfirmations = (): Held[] => {
		const held: Held[] = [];

		extensionCartUpdate.mockImplementation(
			() =>
				new Promise( ( resolve, reject ) => {
					held.push( { resolve, reject } );
				} )
		);

		return held;
	};

	/** A confirmation's outcome as a value, so a rejection is asserted rather than thrown. */
	const settle = ( pending: Promise< unknown > ): Promise< unknown > =>
		pending.then(
			( verdict ) => ( { verdict } ),
			( error ) => ( { error } )
		);

	it( 'moves no address when the confirmation answers after the shopper left the rate', async () => {
		const session = fakeSession();
		const held = holdConfirmations();
		const { container } = renderPicker();

		fireEvent.click( trigger() as HTMLElement );

		let outcome: Promise< unknown > = Promise.resolve();

		await act( async () => {
			outcome = settle( session.host().confirmSelection( { id: 'P1' } ) );
			await Promise.resolve();
		} );

		expect( mockStore.calculating ).toBe( 1 );

		// The rate changes under the open dialog; WooCommerce selects it locally at once.
		act( () => {
			chooseRate( COURIER_RATE );
			notify();
		} );

		expect( session.destroy ).toHaveBeenCalledTimes( 1 );
		expect( container ).toBeEmptyDOMElement();

		/*
		 * The confirmation reached the server first: it confirmed the point and moved the destination
		 * to it. Its late cart also puts the pickup rate back into the store (`receiveCart()` takes
		 * the reply's rates) — the block must not read that as the shopper having come back.
		 */
		await act( async () => {
			chooseRate( PICKUP_RATE );
			held[ 0 ].resolve( cartAnswer( PICKUP_RATE, snapshot( { destination: MOVED } ) ) );
			await outcome;
		} );

		expect( await outcome ).toEqual( { error: { status: 0, code: 'woodev_pickup_superseded', message: '' } } );
		expect( mockStore.shipping ).toEqual( HOME );
		expect( mockStore.billing ).toEqual( HOME );
		expect( mockStore.addressWrites ).toEqual( [] );
		expect( mockStore.calculating ).toBe( 0 );

		// The rate switch's own answer arrives last, and settles what the shopper sees.
		act( () => {
			chooseRate( COURIER_RATE );
			serverAnswers( COURIER_RATE, null );
			notify();
		} );

		expect( container ).toBeEmptyDOMElement();
		expect( lastEcho() ).toBeNull();
		expect( mockStore.validation[ ERROR_ID ] ).toBeUndefined();
	} );

	it( 'moves no address when the confirmation answers after the block went away', async () => {
		const session = fakeSession();
		const held = holdConfirmations();
		const { unmount } = renderPicker();

		fireEvent.click( trigger() as HTMLElement );

		let outcome: Promise< unknown > = Promise.resolve();

		await act( async () => {
			outcome = settle( session.host().confirmSelection( { id: 'P1' } ) );
			await Promise.resolve();
		} );

		unmount();

		await act( async () => {
			held[ 0 ].resolve( cartAnswer( PICKUP_RATE, snapshot( { destination: MOVED } ) ) );
			await outcome;
		} );

		expect( await outcome ).toMatchObject( { error: { code: 'woodev_pickup_superseded' } } );
		expect( mockStore.addressWrites ).toEqual( [] );
		expect( mockStore.calculating ).toBe( 0 );
		expect( mockStore.validation[ ERROR_ID ] ).toBeUndefined();
	} );

	it( 'still takes the answer of a dialog the shopper only dismissed', async () => {
		const session = fakeSession();
		const held = holdConfirmations();

		renderPicker();
		fireEvent.click( trigger() as HTMLElement );

		const first = session.host();
		let outcome: Promise< unknown > = Promise.resolve();

		await act( async () => {
			outcome = settle( first.confirmSelection( { id: 'P1' } ) );
			await Promise.resolve();
		} );

		// Dismissed and opened again on the SAME rate: the server's confirmation still stands.
		fireEvent.click( trigger() as HTMLElement );

		await act( async () => {
			held[ 0 ].resolve( cartAnswer( PICKUP_RATE, snapshot( { destination: MOVED } ) ) );
			await outcome;
		} );

		expect( await outcome ).toMatchObject( { verdict: { allowed: true } } );
		expect( mockStore.shipping ).toEqual( { ...HOME, ...MOVED } );
		expect( screen.getByText( 'Тверская, 1' ) ).toBeInTheDocument();
	} );

	it( 'sends two confirmations one at a time, so the last one asked is the one shown', async () => {
		const session = fakeSession();
		const held = holdConfirmations();

		renderPicker();
		fireEvent.click( trigger() as HTMLElement );

		const host = session.host();
		let first: Promise< unknown > = Promise.resolve();
		let second: Promise< unknown > = Promise.resolve();

		await act( async () => {
			first = settle( host.confirmSelection( { id: 'P1' } ) );
			second = settle( host.confirmSelection( { id: 'P2' } ) );
			await Promise.resolve();
		} );

		// The second command waits for the first one's answer: nothing can overtake it.
		expect( extensionCartUpdate ).toHaveBeenCalledTimes( 1 );
		expect( mockStore.calculating ).toBe( 2 );

		await act( async () => {
			held[ 0 ].resolve( cartAnswer( PICKUP_RATE, snapshot() ) );
			await first;
		} );

		expect( extensionCartUpdate ).toHaveBeenCalledTimes( 2 );
		expect( extensionCartUpdate.mock.calls[ 1 ][ 0 ].data.pickup.carrier.carrier_point.point_id ).toBe( 'P2' );

		await act( async () => {
			held[ 1 ].resolve( cartAnswer( PICKUP_RATE, snapshot( { point_id: 'P2', summary: 'Арбат, 2' } ) ) );
			await second;
		} );

		expect( await first ).toMatchObject( { verdict: { allowed: true } } );
		expect( await second ).toMatchObject( { verdict: { allowed: true } } );
		expect( screen.getByText( 'Арбат, 2' ) ).toBeInTheDocument();
		expect( lastEcho() ).toMatchObject( { point_id: 'P2' } );
		expect( mockStore.calculating ).toBe( 0 );
	} );

	it( 'sends the next confirmation after a failed one', async () => {
		const session = fakeSession();
		const held = holdConfirmations();

		renderPicker();
		fireEvent.click( trigger() as HTMLElement );

		const host = session.host();
		let first: Promise< unknown > = Promise.resolve();
		let second: Promise< unknown > = Promise.resolve();

		await act( async () => {
			first = settle( host.confirmSelection( { id: 'P1' } ) );
			second = settle( host.confirmSelection( { id: 'P2' } ) );
			await Promise.resolve();
		} );

		await act( async () => {
			held[ 0 ].reject( new Error( 'offline' ) );
			await first;
		} );

		expect( await first ).toMatchObject( { error: { message: 'offline' } } );
		expect( extensionCartUpdate ).toHaveBeenCalledTimes( 2 );

		await act( async () => {
			held[ 1 ].resolve( cartAnswer( PICKUP_RATE, snapshot( { point_id: 'P2' } ) ) );
			await second;
		} );

		expect( lastEcho() ).toMatchObject( { point_id: 'P2' } );
		expect( mockStore.calculating ).toBe( 0 );
	} );

	/*
	 * The critic's repro (round 1): the shopper dismisses the dialog while «Checking…», stays on
	 * the rate, and types their own street. The rate latch still says «current» — the address is
	 * what moved on.
	 */
	it( 'leaves a street the shopper typed after asking, when the answer lands on the same rate', async () => {
		const session = fakeSession();
		const held = holdConfirmations();

		renderPicker();
		fireEvent.click( trigger() as HTMLElement );

		let outcome: Promise< unknown > = Promise.resolve();

		await act( async () => {
			outcome = settle( session.host().confirmSelection( { id: 'P1' } ) );
			await Promise.resolve();
		} );

		// Dismissed without leaving the rate; the edit is in the store, core's push is still debounced.
		session.host().close();
		mockStore.shipping = { ...HOME, address_1: 'New manual street' };

		await act( async () => {
			held[ 0 ].resolve( cartAnswer( PICKUP_RATE, snapshot( { destination: MOVED } ) ) );
			await outcome;
		} );

		expect( await outcome ).toEqual( { error: { status: 0, code: 'woodev_pickup_superseded', message: '' } } );
		expect( mockStore.shipping ).toEqual( { ...HOME, address_1: 'New manual street' } );
		expect( mockStore.addressWrites ).toEqual( [] );
		expect( mockStore.calculating ).toBe( 0 );
	} );

	it( 'leaves a postcode edited in a store that ships to the billing address', async () => {
		const session = fakeSession();
		const held = holdConfirmations();

		( window as unknown as Record< string, unknown > ).woodev_pickup_config_carrier = {
			...config,
			replaceAddress: { enabled: true, billingOnly: true },
		};
		renderPicker();
		fireEvent.click( trigger() as HTMLElement );

		let outcome: Promise< unknown > = Promise.resolve();

		await act( async () => {
			outcome = settle( session.host().confirmSelection( { id: 'P1' } ) );
			await Promise.resolve();
		} );

		// There the form the shopper edits is the billing address.
		mockStore.billing = { ...HOME, postcode: '654321' };

		await act( async () => {
			held[ 0 ].resolve( cartAnswer( PICKUP_RATE, snapshot( { destination: MOVED } ) ) );
			await outcome;
		} );

		expect( await outcome ).toMatchObject( { error: { code: 'woodev_pickup_superseded' } } );
		expect( mockStore.billing ).toEqual( { ...HOME, postcode: '654321' } );
		expect( mockStore.shipping ).toEqual( HOME );
	} );

	it( 'does not take its own previous move for the shopper’s edit when two confirmations queue', async () => {
		const session = fakeSession();
		const held = holdConfirmations();

		renderPicker();
		fireEvent.click( trigger() as HTMLElement );

		const host = session.host();
		let first: Promise< unknown > = Promise.resolve();
		let second: Promise< unknown > = Promise.resolve();

		await act( async () => {
			first = settle( host.confirmSelection( { id: 'P1' } ) );
			second = settle( host.confirmSelection( { id: 'P2' } ) );
			await Promise.resolve();
		} );

		await act( async () => {
			held[ 0 ].resolve( cartAnswer( PICKUP_RATE, snapshot( { destination: MOVED } ) ) );
			await first;
		} );

		expect( mockStore.shipping ).toEqual( { ...HOME, ...MOVED } );

		const ARBAT = { address_1: 'Арбат, 2', postcode: '119002' };

		await act( async () => {
			held[ 1 ].resolve( cartAnswer( PICKUP_RATE, snapshot( { point_id: 'P2', destination: ARBAT } ) ) );
			await second;
		} );

		expect( await second ).toMatchObject( { verdict: { allowed: true } } );
		expect( mockStore.shipping ).toEqual( { ...HOME, ...ARBAT } );
	} );

	it( 'leaves the shopper’s edit to every confirmation asked before it', async () => {
		const session = fakeSession();
		const held = holdConfirmations();

		renderPicker();
		fireEvent.click( trigger() as HTMLElement );

		const host = session.host();
		let first: Promise< unknown > = Promise.resolve();
		let second: Promise< unknown > = Promise.resolve();

		await act( async () => {
			first = settle( host.confirmSelection( { id: 'P1' } ) );
			second = settle( host.confirmSelection( { id: 'P2' } ) );
			await Promise.resolve();
		} );

		// Both were asked for the old street; the second one has not even left yet.
		mockStore.shipping = { ...HOME, address_1: 'New manual street' };

		await act( async () => {
			held[ 0 ].resolve( cartAnswer( PICKUP_RATE, snapshot( { destination: MOVED } ) ) );
			await first;
		} );
		await act( async () => {
			held[ 1 ].resolve( cartAnswer( PICKUP_RATE, snapshot( { point_id: 'P2', destination: MOVED } ) ) );
			await second;
		} );

		expect( await first ).toMatchObject( { error: { code: 'woodev_pickup_superseded' } } );
		expect( await second ).toMatchObject( { error: { code: 'woodev_pickup_superseded' } } );
		expect( mockStore.addressWrites ).toEqual( [] );

		// A point asked for AFTER the edit replaces the address, as the store's policy says.
		let third: Promise< unknown > = Promise.resolve();

		await act( async () => {
			third = settle( host.confirmSelection( { id: 'P1' } ) );
			await Promise.resolve();
		} );
		await act( async () => {
			held[ 2 ].resolve( cartAnswer( PICKUP_RATE, snapshot( { destination: MOVED } ) ) );
			await third;
		} );

		expect( await third ).toMatchObject( { verdict: { allowed: true } } );
		expect( mockStore.shipping ).toEqual( { ...HOME, ...MOVED } );
	} );

	/*
	 * The critic's repro (round 1): the server's selection filter corrects the point — it keeps
	 * and answers with P1-fixed, and its verdict carries the corrected point. That is the
	 * confirmation of this command, not a missing one (`StoreApiPickupFlowTest` has the server half).
	 */
	it( 'takes the point the server corrected the choice to, with its address and its echo', async () => {
		const session = fakeSession();
		const corrected = { id: 'P1-fixed', short_address: 'Тверская, 1 стр. 2' };

		renderPicker();
		fireEvent.click( trigger() as HTMLElement );

		extensionCartUpdate.mockImplementation( async () =>
			cartAnswer(
				PICKUP_RATE,
				snapshot( {
					point_id: 'P1-fixed',
					summary: 'Тверская, 1 стр. 2',
					destination: MOVED,
					selection: { allowed: true, reason: null, close: null, refresh_checkout: null, point: corrected },
				} )
			)
		);

		let outcome: unknown;

		await act( async () => {
			outcome = await settle( session.host().confirmSelection( { id: 'P1' } ) );
		} );

		// The verdict hands the corrected point to the session, which replaces the one it holds.
		expect( outcome ).toEqual( {
			verdict: { allowed: true, reason: null, close: null, refresh_checkout: null, point: corrected },
		} );
		expect( mockStore.shipping ).toEqual( { ...HOME, ...MOVED } );
		expect( screen.getByText( 'Тверская, 1 стр. 2' ) ).toBeInTheDocument();
		expect( lastEcho() ).toMatchObject( { point_id: 'P1-fixed' } );
		expect( mockStore.validation[ ERROR_ID ] ).toBeUndefined();
	} );

	it( 'still rejects a reply whose point is neither the one asked for nor a correction of it', async () => {
		const session = fakeSession();

		renderPicker();
		fireEvent.click( trigger() as HTMLElement );

		extensionCartUpdate.mockImplementation( async () =>
			cartAnswer( PICKUP_RATE, snapshot( { point_id: 'P9', destination: MOVED } ) )
		);

		let outcome: unknown;

		await act( async () => {
			outcome = await settle( session.host().confirmSelection( { id: 'P1' } ) );
		} );

		expect( outcome ).toMatchObject( { error: { code: 'woodev_pickup_not_confirmed' } } );
		expect( mockStore.addressWrites ).toEqual( [] );
	} );

	it( 'takes the echo back and blocks the order when a later cart no longer carries the point', () => {
		serverAnswers( PICKUP_RATE, snapshot() );
		renderPicker();

		expect( lastEcho() ).toMatchObject( { point_id: 'P1' } );

		// The address push answers: the server holds no confirmation for the new destination.
		act( () => {
			serverAnswers( PICKUP_RATE, null );
			notify();
		} );

		expect( screen.getByRole( 'button', { name: 'Select a pickup point' } ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Тверская, 1' ) ).not.toBeInTheDocument();
		expect( lastEcho() ).toBeNull();
		expect( mockStore.validation[ ERROR_ID ] ).toEqual( { message: 'Please choose a pickup point.', hidden: true } );

		// A rapid edit back to the first address: the server shows the confirmation it kept for it.
		act( () => {
			serverAnswers( PICKUP_RATE, snapshot() );
			notify();
		} );

		expect( lastEcho() ).toMatchObject( { point_id: 'P1' } );
		expect( mockStore.validation[ ERROR_ID ] ).toBeUndefined();
		expect( extensionCartUpdate ).not.toHaveBeenCalled();
	} );

	it( 'keeps echoing the point through a failed payment, and asks the server again once the cart forgot it', async () => {
		const session = fakeSession();

		serverAnswers( PICKUP_RATE, snapshot() );
		renderPicker();

		/*
		 * The payment fails: the checkout answers with an error and no cart, so the store — and with
		 * it the echo the retry sends — is unchanged. The server accepts that echo against the point
		 * the order already carries (`StoreApiPickupFlowTest`).
		 */
		expect( lastEcho() ).toMatchObject( { point_id: 'P1', rate_id: PICKUP_RATE } );
		expect( mockStore.validation[ ERROR_ID ] ).toBeUndefined();

		// The shopper switches the payment method: the draft update answers with a cart, and the
		// first attempt has emptied the server's memory of the point.
		act( () => {
			serverAnswers( PICKUP_RATE, null );
			notify();
		} );

		expect( lastEcho() ).toBeNull();
		expect( mockStore.validation[ ERROR_ID ] ).toEqual( { message: 'Please choose a pickup point.', hidden: true } );

		// Picking the SAME point again is a real request — the block no longer counts it as chosen.
		fireEvent.click( trigger() as HTMLElement );

		expect( session.host().getSelectedId() ).toBe( '' );

		extensionCartUpdate.mockImplementation( async () => cartAnswer( PICKUP_RATE, snapshot() ) );

		await act( async () => {
			await session.host().confirmSelection( { id: 'P1' } );
		} );

		expect( extensionCartUpdate ).toHaveBeenCalledTimes( 1 );
		expect( lastEcho() ).toMatchObject( { point_id: 'P1' } );
		expect( mockStore.validation[ ERROR_ID ] ).toBeUndefined();
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

	/*
	 * The critic's repro (round 1) on the REAL session and dialog: Escape closes the dialog while
	 * the confirmation is pending, the shopper types a street, the old answer lands.
	 */
	it( 'leaves a street typed after Escape closed the pending dialog', async () => {
		let answer: ( cart: unknown ) => void = () => undefined;

		extensionCartUpdate.mockImplementation(
			() =>
				new Promise( ( resolve ) => {
					answer = resolve;
				} )
		);

		renderPicker();

		await act( async () => {
			fireEvent.click( trigger() as HTMLElement );
			await Promise.resolve();
		} );
		await act( async () => {
			pick( { id: 'P1' } );
			await Promise.resolve();
		} );

		expect( extensionCartUpdate ).toHaveBeenCalledTimes( 1 );

		fireEvent.keyDown( document, { key: 'Escape', keyCode: 27 } );

		expect( document.querySelector( '[role="dialog"]' ) ).not.toBeInTheDocument();

		mockStore.shipping = { ...HOME, address_1: 'New manual street' };

		await act( async () => {
			answer( cartAnswer( PICKUP_RATE, snapshot( { destination: { address_1: 'Point street', postcode: '101000' } } ) ) );
			await Promise.resolve();
			await Promise.resolve();
			await Promise.resolve();
		} );

		expect( mockStore.shipping ).toEqual( { ...HOME, address_1: 'New manual street' } );
		expect( mockStore.addressWrites ).toEqual( [] );
		expect( mockStore.calculating ).toBe( 0 );
	} );

	it( 'keeps the dialog open and shows the server’s refusal in it', async () => {
		const refusal ='This pickup point does not accept cash on delivery. Choose another point or another payment method.';

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

		expect( [ ...reads ].sort() ).toEqual( [ 'blocksCheckout', 'blocksCheckoutEvents', 'wcBlocksRegistry', 'wcSettings' ] );

		reads.length = 0;

		expect( wcRuntime()?.wcSettings?.getSetting ).toBe( getSetting );
		expect( wcRuntime()?.blocksCheckout?.extensionCartUpdate ).toBe( extensionCartUpdate );
		expect( wcRuntime()?.blocksCheckoutEvents?.checkoutEvents?.onCheckoutFail ).toBeUndefined();
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
