/**
 * #1357: overlapping Store API cart requests lose each other's session writes.
 *
 * The server below is WooCommerce's session as the rig measured it (11.2.1, scratchpad probes
 * `session-race.js` / `confirm-race.js`): a request READS the whole session when it starts and WRITES
 * it back whole when it ends (`WC_Session_Handler::save_data()`), so the request that ends last wins.
 * Durations are the rig's: core's address push 1.2–1.8 s (live carrier rates), a rate selection
 * ~80 ms, a pickup confirmation 1.1–2.5 s. Request shapes are core's: `updateCustomerData`,
 * `selectShippingRate` and `extensionCartUpdate` all leave as `POST /wc/store/v1/batch` with ONE
 * request inside (WC 11.2.1 `wc-blocks-data.js`, the DataLoader batch).
 */
import type { APIFetchMiddleware, APIFetchOptions } from '@wordpress/api-fetch';

const mockMiddlewares: APIFetchMiddleware[] = [];

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: { use: ( middleware: APIFetchMiddleware ) => mockMiddlewares.push( middleware ) },
} ) );

type Queue = typeof import('../../src/checkout-blocks/store-api-queue');

let queue: Queue;

interface Session {
	rate: string;
	address_1: string;
	point: string;
}

/** The server's session row, and what each route does to it — on a COPY read when the request starts. */
let session: Session;
const sent: string[] = [];

const DURATION: Record< string, number > = {
	'/wc/store/v1/cart/update-customer': 1500,
	'/wc/store/v1/cart/select-shipping-rate': 80,
	'/wc/store/v1/cart/extensions': 1300,
};

function inner( options: APIFetchOptions ): { path: string; data: Record< string, unknown > } {
	const item = ( ( options.data as { requests?: Array< { path: string; data: Record< string, unknown > } > } )?.requests ?? [] )[ 0 ];

	return item ?? { path: String( options.path ), data: ( options.data ?? {} ) as Record< string, unknown > };
}

/** The innermost handler: WooCommerce on the server. */
function wooCommerce( options: APIFetchOptions ): Promise< unknown > {
	const { path, data } = inner( options );
	const copy: Session = { ...session };

	sent.push( path );

	if ( options.signal?.aborted ) {
		return Promise.reject( { code: 'fetch_error', message: 'aborted' } );
	}

	return new Promise( ( resolve ) => {
		setTimeout( () => {
			if ( path.endsWith( '/update-customer' ) ) {
				copy.address_1 = String( ( data.shipping_address as { address_1?: string } )?.address_1 ?? copy.address_1 );
			} else if ( path.endsWith( '/select-shipping-rate' ) ) {
				copy.rate = String( data.rate_id );
			} else if ( path.endsWith( '/extensions' ) ) {
				// The confirmation moves the address to the point's (`pickup_replace_address`).
				copy.point = String( data.point_id );
				copy.address_1 = 'Point street, 15';
			}

			session = copy;
			resolve( { responses: [ { status: 200, body: { ...copy } } ] } );
		}, DURATION[ path ] ?? 50 );
	} );
}

/** One `apiFetch()` call through the registered middlewares, as `@wordpress/api-fetch` chains them. */
function apiFetch( options: APIFetchOptions, handler: ( options: APIFetchOptions ) => Promise< unknown > = wooCommerce ): Promise< unknown > {
	const run = mockMiddlewares.reduceRight< ( args: APIFetchOptions ) => Promise< unknown > >(
		( next, middleware ) => ( args ) => middleware( args, next ),
		handler
	);

	return run( options );
}

const batch = ( path: string, data: Record< string, unknown > ): APIFetchOptions => ( {
	path: '/wc/store/v1/batch?_locale=site',
	method: 'POST',
	data: { requests: [ { path, method: 'POST', data } ] },
} );

/** Core's focusout push of the address form (the shopper's street, unchanged). */
const addressPush = (): APIFetchOptions =>
	batch( '/wc/store/v1/cart/update-customer', { shipping_address: { address_1: 'Тверская, 1' } } );
/** The shopper picks the carrier's pickup rate. */
const pickRate = (): APIFetchOptions =>
	batch( '/wc/store/v1/cart/select-shipping-rate', { package_id: 0, rate_id: 'carrier_pickup:7' } );
/** The pickup block confirms a point (`confirmPoint()`). */
const confirmPoint = (): APIFetchOptions => batch( '/wc/store/v1/cart/extensions', { point_id: 'MSK2' } );

const later = ( ms: number, request: () => Promise< unknown > ): Promise< unknown > =>
	new Promise( ( resolve ) => setTimeout( resolve, ms ) ).then( request );

beforeEach( () => {
	jest.useFakeTimers();
	jest.resetModules();
	mockMiddlewares.length = 0;
	sent.length = 0;
	delete ( window as unknown as Record< string, unknown > ).__woodevStoreApiMutationQueue;
	session = { rate: 'free_shipping:1', address_1: 'Тверская, 1', point: '' };
	// eslint-disable-next-line @typescript-eslint/no-var-requires
	queue = require( '../../src/checkout-blocks/store-api-queue' ) as Queue;
} );

afterEach( () => {
	jest.useRealTimers();
} );

describe( 'WooCommerce’s session without the queue (the control this fix exists for)', () => {
	it( 'loses the rate the shopper picked while an address push was on the server — symptom (b)', async () => {
		const done = Promise.all( [ apiFetch( addressPush() ), later( 300, () => apiFetch( pickRate() ) ) ] );

		await jest.advanceTimersByTimeAsync( 5000 );
		await done;

		expect( session.rate ).toBe( 'free_shipping:1' );
	} );

	it( 'loses a pickup confirmation a later cart request overlapped — symptom (a)', async () => {
		session.rate = 'carrier_pickup:7';

		const done = Promise.all( [ apiFetch( confirmPoint() ), later( 700, () => apiFetch( addressPush() ) ) ] );

		await jest.advanceTimersByTimeAsync( 5000 );
		await done;

		expect( session.point ).toBe( '' );
		expect( session.address_1 ).toBe( 'Тверская, 1' );
	} );
} );

describe( 'serializeStoreApiMutations (#1357)', () => {
	beforeEach( () => {
		queue.serializeStoreApiMutationsOnce();
	} );

	it( 'keeps the rate the shopper picked while an address push was on the server', async () => {
		const done = Promise.all( [ apiFetch( addressPush() ), later( 300, () => apiFetch( pickRate() ) ) ] );

		await jest.advanceTimersByTimeAsync( 5000 );
		await done;

		expect( session.rate ).toBe( 'carrier_pickup:7' );
	} );

	it( 'keeps a pickup confirmation a later cart request would have overlapped', async () => {
		session.rate = 'carrier_pickup:7';

		const done = Promise.all( [ apiFetch( confirmPoint() ), later( 700, () => apiFetch( addressPush() ) ) ] );

		await jest.advanceTimersByTimeAsync( 5000 );
		await done;

		expect( session.point ).toBe( 'MSK2' );
	} );

	it( 'sends a mutation only once the one before it has been answered, and answers them in order', async () => {
		const answered: string[] = [];
		const push = apiFetch( addressPush() ).then( () => answered.push( 'push' ) );
		const rate = later( 300, () => apiFetch( pickRate() ) ).then( () => answered.push( 'rate' ) );

		await jest.advanceTimersByTimeAsync( 1499 );

		expect( sent ).toEqual( [ '/wc/store/v1/cart/update-customer' ] );

		await jest.advanceTimersByTimeAsync( 1 );

		expect( sent ).toEqual( [ '/wc/store/v1/cart/update-customer', '/wc/store/v1/cart/select-shipping-rate' ] );

		await jest.advanceTimersByTimeAsync( 100 );
		await Promise.all( [ push, rate ] );

		expect( answered ).toEqual( [ 'push', 'rate' ] );
	} );

	it( 'keeps the order of asking for everything queued (FIFO)', async () => {
		const all = Promise.all( [
			apiFetch( confirmPoint() ),
			apiFetch( addressPush() ),
			apiFetch( pickRate() ),
			apiFetch( addressPush() ),
		] );

		await jest.advanceTimersByTimeAsync( 10000 );
		await all;

		expect( sent ).toEqual( [
			'/wc/store/v1/cart/extensions',
			'/wc/store/v1/cart/update-customer',
			'/wc/store/v1/cart/select-shipping-rate',
			'/wc/store/v1/cart/update-customer',
		] );
	} );

	it( 'lets the next mutation go when the previous one is REFUSED', async () => {
		const refusal = { code: 'woocommerce_rest_invalid_address' };
		const refused = apiFetch( addressPush(), () => new Promise( ( _, reject ) => setTimeout( () => reject( refusal ), 200 ) ) );
		const rate = apiFetch( pickRate() );
		const caught = refused.catch( ( error: unknown ) => error );

		await jest.advanceTimersByTimeAsync( 199 );

		expect( sent ).toEqual( [] );

		await jest.advanceTimersByTimeAsync( 1 );

		await expect( caught ).resolves.toBe( refusal );
		expect( sent ).toEqual( [ '/wc/store/v1/cart/select-shipping-rate' ] );

		await jest.advanceTimersByTimeAsync( 100 );
		await rate;

		expect( session.rate ).toBe( 'carrier_pickup:7' );
	} );

	it( 'lets the next mutation go when the previous one throws before it is sent', async () => {
		const broken = apiFetch( addressPush(), () => {
			throw new Error( 'handler failed' );
		} );
		const rate = apiFetch( pickRate() );

		await expect( broken ).rejects.toThrow( 'handler failed' );
		await jest.advanceTimersByTimeAsync( 100 );
		await rate;

		expect( session.rate ).toBe( 'carrier_pickup:7' );
	} );

	it( 'waits for a request that never answers no longer than the cap, then moves on', async () => {
		void apiFetch( addressPush(), () => new Promise( () => undefined ) );
		const rate = apiFetch( pickRate() );

		await jest.advanceTimersByTimeAsync( queue.QUEUE_WAIT_CAP_MS - 1 );

		expect( sent ).toEqual( [] );

		await jest.advanceTimersByTimeAsync( 1 );

		expect( sent ).toEqual( [ '/wc/store/v1/cart/select-shipping-rate' ] );

		await jest.advanceTimersByTimeAsync( 100 );
		await rate;

		expect( session.rate ).toBe( 'carrier_pickup:7' );
	} );

	it( 'lets a request aborted while it waits leave at once, and keeps the queue for the rest', async () => {
		const controller = new AbortController();
		const push = apiFetch( addressPush() );
		const aborted = apiFetch( { ...pickRate(), signal: controller.signal } );
		const confirm = apiFetch( confirmPoint() );

		await jest.advanceTimersByTimeAsync( 100 );
		controller.abort();
		await expect( aborted ).rejects.toEqual( { code: 'fetch_error', message: 'aborted' } );

		expect( sent ).toEqual( [ '/wc/store/v1/cart/update-customer', '/wc/store/v1/cart/select-shipping-rate' ] );

		await jest.advanceTimersByTimeAsync( 1399 );

		expect( sent ).toHaveLength( 2 );

		await jest.advanceTimersByTimeAsync( 1 );

		expect( sent[ 2 ] ).toBe( '/wc/store/v1/cart/extensions' );

		await jest.advanceTimersByTimeAsync( 1300 );
		await Promise.all( [ push, confirm ] );
	} );

	it( 'never holds back a read, or a request that is not the Store API’s', async () => {
		void apiFetch( addressPush() );

		const read = jest.fn( () => Promise.resolve( 'ok' ) );

		await apiFetch( { path: '/wc/store/v1/cart', method: 'GET' }, read );
		await apiFetch( { path: '/wc/store/v1/cart' }, read );
		await apiFetch( { path: '/woodev/v1/shipping/pickup/carrier/points?locality=city%3A1' }, read );
		await apiFetch( { path: '/wp/v2/settings', method: 'POST', data: {} }, read );
		await apiFetch( { path: '/woodev/v1/licenses/activate', method: 'POST', data: {} }, read );

		expect( read ).toHaveBeenCalledTimes( 5 );
	} );

	it.each( [
		[ '/wc/store/v1/batch?_locale=site', 'POST', true ],
		[ '/wc/store/batch', 'POST', true ],
		[ '/wc/store/v1/cart/update-customer', 'POST', true ],
		[ '/wc/store/v1/cart/items/abc123', 'DELETE', true ],
		[ '/wc/store/v1/cart/extensions', 'post', true ],
		[ '/wc/store/v1/checkout?__experimental_calc_totals=true&_locale=site', 'PUT', true ],
		[ '/wc/store/v1/checkout', 'POST', true ],
		[ '/wc/store/v1/checkout/123?key=wc_order_x', 'POST', true ],
		[ '/index.php?rest_route=/wc/store/v1/batch', 'POST', true ],
		[ 'https://shop.example/wp-json/wc/store/v1/cart/select-shipping-rate', 'POST', true ],
		[ '/wc/store/v1/cart', 'GET', false ],
		[ '/wc/store/v1/checkout', 'GET', false ],
		[ '/wc/store/v1/products', 'POST', false ],
		[ '/wc/store/v1/cart-items', 'POST', false ],
		[ '/woodev/v1/shipping/pickup/carrier/points', 'POST', false ],
	] )( 'tells %s (%s) a cart mutation: %s', ( path, method, expected ) => {
		const options = path.startsWith( 'http' ) ? { url: path, method } : { path, method };

		expect( queue.isStoreApiMutation( options ) ).toBe( expected );
	} );
} );

describe( 'the bundle entry', () => {
	/** The WooCommerce globals of a checkout page; `pickup` — whether a carrier has a pickup field. */
	function evaluateBundle( pickup: boolean ): void {
		( window as unknown as { wc?: unknown } ).wc = {
			blocksCheckout: { registerCheckoutBlock: jest.fn() },
			wcSettings: {
				getSetting: ( name: string, fallback: unknown ) =>
					pickup && name === 'woodev-shipping-pickup_data'
						? { enabled: true, namespace: 'woodev-shipping', fields: [ { pluginId: 'carrier', fieldId: 'pickup_point', configKey: 'carrier_config' } ] }
						: fallback,
			},
		};
		// eslint-disable-next-line @typescript-eslint/no-var-requires
		require( '../../src/checkout-blocks/index' );
	}

	afterEach( () => {
		delete ( window as unknown as { wc?: unknown } ).wc;
	} );

	it( 'installs the queue as the OUTERMOST middleware where a framework block registered', () => {
		evaluateBundle( true );

		// `@wordpress/api-fetch` runs the LAST registered middleware first.
		expect( mockMiddlewares[ mockMiddlewares.length - 1 ]?.name ).toBe( 'serializeStoreApiMutations' );
		expect( mockMiddlewares.filter( ( middleware ) => middleware.name === 'serializeStoreApiMutations' ) ).toHaveLength( 1 );
	} );

	it( 'leaves core’s requests alone on a checkout where the framework registered no block', () => {
		evaluateBundle( false );

		expect( mockMiddlewares.some( ( middleware ) => middleware.name === 'serializeStoreApiMutations' ) ).toBe( false );
	} );
} );

describe( 'serializeStoreApiMutationsOnce', () => {
	it( 'installs the middleware once per page, whichever copy of the bundle asks', () => {
		expect( queue.serializeStoreApiMutationsOnce() ).toBe( true );

		jest.resetModules();
		// eslint-disable-next-line @typescript-eslint/no-var-requires
		const secondCopy = require( '../../src/checkout-blocks/store-api-queue' ) as Queue;

		expect( secondCopy.serializeStoreApiMutationsOnce() ).toBe( false );
		expect( queue.serializeStoreApiMutationsOnce() ).toBe( false );
		// The other entries are `customer-data-request.ts`'s own, registered as each copy is evaluated.
		expect(
			mockMiddlewares.filter( ( middleware ) => middleware.name === 'serializeStoreApiMutations' )
		).toEqual( [ queue.serializeStoreApiMutations ] );
	} );
} );
