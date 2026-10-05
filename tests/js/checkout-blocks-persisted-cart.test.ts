/**
 * The bundle against WooCommerce's persisted cart (#1111) — on the REAL `@wordpress/data`, because
 * what is under test is the registry's own resolution bookkeeping, which a stand-in would only
 * restate.
 *
 * The cart store below reproduces the two things core does (read from WooCommerce 11.1
 * `wc-blocks-data.js` and 9.9.0 `data/cart/index.ts`): it STARTS from the copy persisted in
 * `localStorage`, and on the window's `load` it marks `getCartData` resolved without running the
 * resolver. Its resolver answers with the server's cart, as core's does from the page's preload.
 */

const CART_STORE = 'wc/store/cart';
const NAMESPACE = 'woodev-shipping';

type Address = Record< string, string >;

/** The address in the persisted copy, and the one the server holds: another street. */
const PERSISTED_ADDRESS: Address = { country: 'RU', state: '', city: 'Москва', postcode: '101000', address_1: 'Тверская, 7' };
const SERVER_ADDRESS: Address = { ...PERSISTED_ADDRESS, address_1: 'Ленина, 5' };

/** The copy persisted before the order: its rate selected, its point confirmed. */
const PERSISTED = {
	shippingAddress: PERSISTED_ADDRESS,
	billingAddress: PERSISTED_ADDRESS,
	extensions: {
		[ NAMESPACE ]: {
			owner: { plugin_id: 'carrier', field_id: 'pickup_point', rate_id: 'carrier_pickup:7', locality: 'city:1' },
			pickup: { carrier: { pickup_point: { point_id: 'P-1', rate_id: 'carrier_pickup:7' } } },
		},
	},
};

/** What the server holds for the new cart: the default rate, no point. */
const SERVER = {
	shippingAddress: SERVER_ADDRESS,
	billingAddress: SERVER_ADDRESS,
	extensions: { [ NAMESPACE ]: { owner: null, pickup: { carrier: { pickup_point: null } } } },
};

type Data = typeof import('@wordpress/data');

interface Page {
	data: Data;
	/** How many times the cart was read from the server. */
	fetches: () => number;
	cart: () => unknown;
	shipping: () => Address;
	/** The shopper types into the shipping form: core writes it into the cart store at once. */
	type: ( fields: Address ) => void;
	/** A later reply of the page — a cart core takes whole, outside any resolution. */
	laterReply: ( cart: typeof SERVER ) => void;
	/** Core's `load` listener: the persisted copy is valid, so no request is needed. */
	coreSkipsTheRequest: () => void;
	settle: () => Promise< void >;
	/** One turn of the timers: a queued resolver starts. */
	turn: () => Promise< void >;
	/** Lets a held server answer through (`openPage( true )`). */
	serverAnswers: () => void;
	/** Core's address push goes out / is answered (`isCustomerDataUpdating`). */
	push: ( inFlight: boolean ) => void;
}

/**
 * A fresh registry holding core's cart store, started from the persisted copy. `held`: the server's
 * answer waits for {@link Page.serverAnswers}, so the resolver can be caught mid-flight.
 */
function openPage( held = false ): Page {
	jest.resetModules();

	// eslint-disable-next-line @typescript-eslint/no-var-requires
	const data = require( '@wordpress/data' ) as Data;
	let fetches = 0;
	let pushing = false;
	let serverAnswers = (): void => {};
	const answer = held ? new Promise< void >( ( resolve ) => ( serverAnswers = resolve ) ) : Promise.resolve();

	data.register(
		data.createReduxStore( CART_STORE, {
			reducer: (
				state: { cartData: typeof SERVER } = { cartData: PERSISTED },
				action: { type: string; cart?: typeof SERVER; address?: Address }
			) => {
				if ( action.type === 'RECEIVE_CART' && action.cart ) {
					return { cartData: action.cart };
				}

				// Core's SET_SHIPPING_ADDRESS: the form's value goes into `cartData` itself.
				return action.type === 'SET_SHIPPING_ADDRESS' && action.address
					? { cartData: { ...state.cartData, shippingAddress: action.address } }
					: state;
			},
			actions: {
				receiveCart: ( cart: unknown ) => ( { type: 'RECEIVE_CART', cart } ),
				setShippingAddress: ( address: Address ) => ( { type: 'SET_SHIPPING_ADDRESS', address } ),
			},
			selectors: {
				getCartData: ( state: { cartData: unknown } ) => state.cartData,
				getCustomerData: ( state: { cartData: typeof SERVER } ) => state.cartData,
				isCustomerDataUpdating: () => pushing,
			},
			resolvers: {
				getCartData:
					() =>
					async ( { dispatch }: { dispatch: { receiveCart: ( cart: unknown ) => void } } ) => {
						fetches++;
						await answer;
						dispatch.receiveCart( SERVER );
					},
			},
		} )
	);

	const cartSelectors = (): { getCartData: () => unknown; getCustomerData: () => typeof SERVER } =>
		data.select( CART_STORE ) as unknown as { getCartData: () => unknown; getCustomerData: () => typeof SERVER };
	const cartActions = () =>
		data.dispatch( CART_STORE ) as unknown as {
			finishResolution: ( selector: string, args: unknown[] ) => void;
			receiveCart: ( cart: unknown ) => void;
			setShippingAddress: ( address: Address ) => void;
		};

	return {
		data,
		fetches: () => fetches,
		cart: () => cartSelectors().getCartData(),
		shipping: () => cartSelectors().getCustomerData().shippingAddress,
		type: ( fields ) =>
			cartActions().setShippingAddress( { ...cartSelectors().getCustomerData().shippingAddress, ...fields } ),
		laterReply: ( cart ) => cartActions().receiveCart( cart ),
		coreSkipsTheRequest: () => cartActions().finishResolution( 'getCartData', [] ),
		settle: async () => {
			for ( let turn = 0; turn < 5; turn++ ) {
				await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
			}
		},
		turn: () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) ),
		serverAnswers: () => serverAnswers(),
		push: ( inFlight ) => {
			pushing = inFlight;
		},
	};
}

/** The WooCommerce globals of a checkout page; `pickup` — whether a carrier has a pickup field. */
function publishRuntime( pickup: boolean ): jest.Mock {
	const registerCheckoutBlock = jest.fn();

	( window as unknown as { wc?: unknown } ).wc = {
		blocksCheckout: { registerCheckoutBlock },
		wcSettings: {
			getSetting: ( name: string, fallback: unknown ) =>
				pickup && name === 'woodev-shipping-pickup_data'
					? {
							enabled: true,
							namespace: NAMESPACE,
							fields: [ { pluginId: 'carrier', fieldId: 'pickup_point', configKey: 'carrier_config' } ],
					  }
					: fallback,
		},
	};

	return registerCheckoutBlock;
}

/** Evaluates the bundle, as the page's `<script>` does — before the window's `load`. */
function evaluateBundle(): void {
	// eslint-disable-next-line @typescript-eslint/no-var-requires
	require( '../../src/checkout-blocks/index' );
}

afterEach( () => {
	delete ( window as unknown as { wc?: unknown } ).wc;
} );

describe( 'WooCommerce’s persisted cart (#1111)', () => {
	it( 'is what core leaves in the store when its hash matches — the control this fix exists for', async () => {
		const page = openPage();

		page.coreSkipsTheRequest();

		expect( page.cart() ).toBe( PERSISTED );

		await page.settle();

		expect( page.fetches() ).toBe( 0 );
		expect( page.cart() ).toBe( PERSISTED );
	} );

	it( 'is replaced by the server’s cart once the bundle is on the page', async () => {
		const page = openPage();
		const registerCheckoutBlock = publishRuntime( true );

		evaluateBundle();
		page.coreSkipsTheRequest();
		await page.settle();

		expect( registerCheckoutBlock ).toHaveBeenCalledTimes( 1 );
		expect( page.fetches() ).toBe( 1 );
		expect( page.cart() ).toBe( SERVER );
	} );

	it( 'reads the cart once — the checkout’s own reads start no second one', async () => {
		const page = openPage();

		publishRuntime( true );
		evaluateBundle();
		page.cart();
		await page.settle();
		page.cart();
		await page.settle();

		expect( page.fetches() ).toBe( 1 );
	} );

	it( 'leaves core alone on a checkout where the framework registered no block', async () => {
		const page = openPage();
		const registerCheckoutBlock = publishRuntime( false );

		evaluateBundle();
		page.coreSkipsTheRequest();
		await page.settle();

		expect( registerCheckoutBlock ).not.toHaveBeenCalled();
		expect( page.fetches() ).toBe( 0 );
		expect( page.cart() ).toBe( PERSISTED );
	} );

	it( 'does nothing on a page without the cart store', () => {
		jest.resetModules();
		publishRuntime( true );

		expect( evaluateBundle ).not.toThrow();
	} );
} );

/*
 * Core's `load` shortcut lets the address form render over the persisted copy while the queued
 * resolver has not brought the server's cart yet, and that cart is taken whole — the resolver's
 * `receiveCart()` does not look at the shopper's unsaved edit (WooCommerce 9.9.0 and 11.1). What was
 * typed in that window is written back over the arrived address.
 */
describe( 'an address edit made before the server’s cart arrives (#1111)', () => {
	it( 'is lost to the arriving cart on a page without the bundle’s watch — the control', async () => {
		const page = openPage();

		// The same resolver, queued by a read of the page itself instead of the bundle.
		page.cart();
		page.coreSkipsTheRequest();
		page.type( { postcode: '654321' } );
		await page.settle();

		expect( page.shipping() ).toBe( SERVER_ADDRESS );
	} );

	it( 'survives the arriving cart, which still brings everything nobody typed', async () => {
		const page = openPage();

		publishRuntime( true );
		evaluateBundle();
		page.coreSkipsTheRequest();
		// The form is on screen; the resolver's timer has not started.
		page.type( { postcode: '654' } );
		page.type( { postcode: '654321' } );
		await page.settle();

		expect( page.fetches() ).toBe( 1 );
		// The typed postcode over the server's address: its street is the server's, not the persisted copy's.
		expect( page.shipping() ).toEqual( { ...SERVER_ADDRESS, postcode: '654321' } );
		expect( ( page.cart() as typeof SERVER ).extensions ).toBe( SERVER.extensions );
	} );

	/*
	 * The order measured on the rig (WooCommerce 11.1): the resolver's timer has already fired when
	 * `load` marks the selector resolved, so the cart lands on a selector that reads «resolved, not
	 * resolving» — the resolution state cannot say that this reply is the import.
	 */
	it( 'survives when the resolver was already running as core marked the cart resolved', async () => {
		const page = openPage( true );

		publishRuntime( true );
		evaluateBundle();
		await page.turn();
		page.coreSkipsTheRequest();
		page.type( { postcode: '654321' } );
		page.serverAnswers();
		await page.settle();

		expect( page.fetches() ).toBe( 1 );
		expect( page.shipping() ).toEqual( { ...SERVER_ADDRESS, postcode: '654321' } );
	} );

	it( 'stands down once core’s own address push is in flight — that reply answers the push', async () => {
		const page = openPage( true );

		publishRuntime( true );
		evaluateBundle();
		await page.turn();
		page.coreSkipsTheRequest();
		page.type( { postcode: '654321' } );
		page.push( true );
		page.type( { postcode: '654321', address_1: 'Мира, 9' } );
		page.serverAnswers();
		await page.settle();

		expect( page.shipping() ).toBe( SERVER_ADDRESS );
	} );

	it( 'writes nothing when nothing was typed', async () => {
		const page = openPage();

		publishRuntime( true );
		evaluateBundle();
		page.coreSkipsTheRequest();
		await page.settle();

		// The very object the server sent: no address write followed it.
		expect( page.cart() ).toBe( SERVER );
	} );

	it( 'writes nothing when what was typed is what arrives', async () => {
		const page = openPage();

		publishRuntime( true );
		evaluateBundle();
		page.coreSkipsTheRequest();
		page.type( { address_1: SERVER_ADDRESS.address_1 } );
		await page.settle();

		expect( page.cart() ).toBe( SERVER );
	} );

	it( 'ends with that first cart — a later reply is core’s to reconcile with the form', async () => {
		const page = openPage();

		publishRuntime( true );
		evaluateBundle();
		page.coreSkipsTheRequest();
		await page.settle();
		page.type( { postcode: '654321' } );
		page.laterReply( { ...SERVER } );
		await page.settle();

		expect( page.shipping() ).toBe( SERVER_ADDRESS );
	} );
} );
