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

/** The copy persisted before the order: its rate selected, its point confirmed. */
const PERSISTED = {
	extensions: {
		[ NAMESPACE ]: {
			owner: { plugin_id: 'carrier', field_id: 'pickup_point', rate_id: 'carrier_pickup:7', locality: 'city:1' },
			pickup: { carrier: { pickup_point: { point_id: 'P-1', rate_id: 'carrier_pickup:7' } } },
		},
	},
};

/** What the server holds for the new cart: the default rate, no point. */
const SERVER = {
	extensions: { [ NAMESPACE ]: { owner: null, pickup: { carrier: { pickup_point: null } } } },
};

type Data = typeof import('@wordpress/data');

interface Page {
	data: Data;
	/** How many times the cart was read from the server. */
	fetches: () => number;
	cart: () => unknown;
	/** Core's `load` listener: the persisted copy is valid, so no request is needed. */
	coreSkipsTheRequest: () => void;
	settle: () => Promise< void >;
}

/** A fresh registry holding core's cart store, started from the persisted copy. */
function openPage(): Page {
	jest.resetModules();

	// eslint-disable-next-line @typescript-eslint/no-var-requires
	const data = require( '@wordpress/data' ) as Data;
	let fetches = 0;

	data.register(
		data.createReduxStore( CART_STORE, {
			reducer: ( state: { cartData: unknown } = { cartData: PERSISTED }, action: { type: string; cart?: unknown } ) =>
				action.type === 'RECEIVE_CART' ? { cartData: action.cart } : state,
			actions: { receiveCart: ( cart: unknown ) => ( { type: 'RECEIVE_CART', cart } ) },
			selectors: { getCartData: ( state: { cartData: unknown } ) => state.cartData },
			resolvers: {
				getCartData:
					() =>
					async ( { dispatch }: { dispatch: { receiveCart: ( cart: unknown ) => void } } ) => {
						fetches++;
						await Promise.resolve();
						dispatch.receiveCart( SERVER );
					},
			},
		} )
	);

	const cartSelectors = (): { getCartData: () => unknown } =>
		data.select( CART_STORE ) as unknown as { getCartData: () => unknown };

	return {
		data,
		fetches: () => fetches,
		cart: () => cartSelectors().getCartData(),
		coreSkipsTheRequest: () =>
			(
				data.dispatch( CART_STORE ) as unknown as { finishResolution: ( selector: string, args: unknown[] ) => void }
			 ).finishResolution( 'getCartData', [] ),
		settle: async () => {
			for ( let turn = 0; turn < 5; turn++ ) {
				await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
			}
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
