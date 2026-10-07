/*
 * Block checkout «payment method changed» trigger (#1144) — `payment-recalc.ts`.
 *
 * `@wordpress/data` is replaced by an in-memory `wc/store/payment`; `window.wc` carries the two core
 * pieces the module consumes (`extensionCartUpdate`, `wcSettings.getSetting`). `disableCheckoutFor`
 * is the real contract reduced to «run the work»: the module only has to hand it the work.
 */
const mockStore = {
	payment: 'cod',
	listeners: new Set< () => void >(),
};

const notify = (): void => mockStore.listeners.forEach( ( listener ) => listener() );

jest.mock( '@wordpress/data', () => ( {
	select: () => ( { getActivePaymentMethod: () => mockStore.payment } ),
	dispatch: () => ( { disableCheckoutFor: ( work: () => Promise< unknown > ) => work() } ),
	subscribe: ( listener: () => void ) => {
		mockStore.listeners.add( listener );

		return () => mockStore.listeners.delete( listener );
	},
} ) );

import { readFeePaymentsNamespace, watchPaymentMethod } from '../../src/checkout-blocks/payment-recalc';
import { captureWcRuntime } from '../../src/checkout-blocks/wc-runtime';

type Update = ( args: { namespace: string; data: unknown } ) => Promise< unknown >;

interface Wc {
	blocksCheckout: { extensionCartUpdate: jest.Mock< ReturnType< Update >, Parameters< Update > > };
	wcSettings: { getSetting: jest.Mock };
}

function installWc( data: unknown ): Wc {
	const wc: Wc = {
		blocksCheckout: { extensionCartUpdate: jest.fn( () => Promise.resolve( {} ) ) },
		wcSettings: { getSetting: jest.fn( () => data ) },
	};

	( window as unknown as { wc?: Wc } ).wc = wc;
	captureWcRuntime();

	return wc;
}

const settle = (): Promise< void > => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

beforeEach( () => {
	mockStore.payment = 'cod';
	mockStore.listeners.clear();
} );

afterEach( () => {
	delete ( window as unknown as { wc?: Wc } ).wc;
	captureWcRuntime();
} );

describe( 'readFeePaymentsNamespace', () => {
	it( 'is null without WooCommerce settings', () => {
		expect( readFeePaymentsNamespace() ).toBeNull();
	} );

	it( 'is null while the server switched the trigger off', () => {
		installWc( { enabled: false } );

		expect( readFeePaymentsNamespace() ).toBeNull();
	} );

	it( 'is the published namespace while the server switched it on', () => {
		const wc = installWc( { enabled: true, namespace: 'woodev-shipping-fee-payments' } );

		expect( readFeePaymentsNamespace() ).toBe( 'woodev-shipping-fee-payments' );
		expect( wc.wcSettings.getSetting ).toHaveBeenCalledWith( 'woodev-shipping-fee-payments_data', null );
	} );

	it( 'refuses a malformed payload', () => {
		installWc( { enabled: true, namespace: 5 } );

		expect( readFeePaymentsNamespace() ).toBeNull();
	} );
} );

describe( 'watchPaymentMethod', () => {
	it( 'sends the active method once when it starts', async () => {
		const wc = installWc( null );

		watchPaymentMethod( 'ns' );
		await settle();

		expect( wc.blocksCheckout.extensionCartUpdate ).toHaveBeenCalledTimes( 1 );
		expect( wc.blocksCheckout.extensionCartUpdate ).toHaveBeenCalledWith( { namespace: 'ns', data: { payment_method: 'cod' } } );
	} );

	it( 'sends nothing while no method is active, and the method once one is', async () => {
		const wc = installWc( null );

		mockStore.payment = '';
		watchPaymentMethod( 'ns' );
		await settle();
		expect( wc.blocksCheckout.extensionCartUpdate ).not.toHaveBeenCalled();

		mockStore.payment = 'bacs';
		notify();
		await settle();
		expect( wc.blocksCheckout.extensionCartUpdate ).toHaveBeenCalledTimes( 1 );
		expect( wc.blocksCheckout.extensionCartUpdate.mock.calls[ 0 ][ 0 ].data ).toEqual( { payment_method: 'bacs' } );
	} );

	it( 'does not repeat itself for a store change that left the method alone', async () => {
		const wc = installWc( null );

		watchPaymentMethod( 'ns' );
		await settle();
		notify();
		notify();
		await settle();

		expect( wc.blocksCheckout.extensionCartUpdate ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'sends again when the customer switches method', async () => {
		const wc = installWc( null );

		watchPaymentMethod( 'ns' );
		await settle();
		mockStore.payment = 'bacs';
		notify();
		await settle();

		expect( wc.blocksCheckout.extensionCartUpdate.mock.calls.map( ( call ) => call[ 0 ].data ) ).toEqual( [
			{ payment_method: 'cod' },
			{ payment_method: 'bacs' },
		] );
	} );

	it( 'keeps one request in flight, then sends the method the customer ended on', async () => {
		const wc = installWc( null );
		let release: () => void = () => undefined;

		wc.blocksCheckout.extensionCartUpdate.mockImplementationOnce( () => new Promise( ( resolve ) => { release = () => resolve( {} ); } ) );

		watchPaymentMethod( 'ns' );
		mockStore.payment = 'bacs';
		notify();
		mockStore.payment = 'cheque';
		notify();
		expect( wc.blocksCheckout.extensionCartUpdate ).toHaveBeenCalledTimes( 1 );

		release();
		await settle();

		expect( wc.blocksCheckout.extensionCartUpdate.mock.calls.map( ( call ) => call[ 0 ].data ) ).toEqual( [
			{ payment_method: 'cod' },
			{ payment_method: 'cheque' },
		] );
	} );

	it( 'does not retry a refused send for the same method', async () => {
		const wc = installWc( null );

		wc.blocksCheckout.extensionCartUpdate.mockImplementation( () => Promise.reject( new Error( 'refused' ) ) );

		watchPaymentMethod( 'ns' );
		await settle();
		notify();
		await settle();

		expect( wc.blocksCheckout.extensionCartUpdate ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'stops watching when told to', async () => {
		const wc = installWc( null );
		const stop = watchPaymentMethod( 'ns' );

		await settle();
		stop();
		mockStore.payment = 'bacs';
		notify();
		await settle();

		expect( wc.blocksCheckout.extensionCartUpdate ).toHaveBeenCalledTimes( 1 );
	} );
} );
