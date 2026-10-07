/**
 * Block checkout: tells the server which payment method is chosen, so a fee that depends on it is
 * recalculated (#1144).
 *
 * WooCommerce's Checkout block keeps the active payment method in the client's `wc/store/payment`
 * and sends it to the server only with the order POST — the server's session, which prices the cart,
 * still holds whatever an earlier visit left there. `extensionCartUpdate()` is the supported way to
 * hand the server a value mid-checkout: it runs the namespace's `woocommerce_store_api_register_update_callback`
 * callback (`Fee_Payments::update_cart()`), and WooCommerce then recalculates the cart — shipping
 * included — and replaces the cart store with the reply (`CartExtensionsSchema::get_item_response()`).
 *
 * Switched on by the server only while some shipping method instance limits its fee to payment
 * methods (`Fee_Payments_Blocks_Integration`): a shop that does not use it publishes no data here,
 * and nothing below runs.
 *
 * @package woodev-plugin-framework
 */

import { subscribe } from '@wordpress/data';
import { readActivePaymentMethod } from './pickup-stores';
import { gateCheckout } from './wc-stores';
import { wcRuntime } from './wc-runtime';

interface FeePaymentsData {
	enabled?: boolean;
	namespace?: string;
}

/** The namespace to send the payment method to, or `null` when the server switched the trigger off. */
export function readFeePaymentsNamespace(): string | null {
	const getSetting = wcRuntime()?.wcSettings?.getSetting;

	if ( typeof getSetting !== 'function' ) {
		return null;
	}

	const data = getSetting< FeePaymentsData | null >( 'woodev-shipping-fee-payments_data', null );

	return data && data.enabled === true && typeof data.namespace === 'string' && data.namespace !== '' ? data.namespace : null;
}

/**
 * Sends the active payment method to the server now, and again every time it changes.
 *
 * One request in flight at a time, with Place Order held while it runs: a change made meanwhile is
 * picked up when it lands. A method is sent once — a failed send is not retried for the same method
 * (the next change, or the order POST itself, carries the right one), so a server that keeps
 * refusing is asked once, not in a loop.
 *
 * @return a function that stops watching.
 */
export function watchPaymentMethod( namespace: string ): () => void {
	let sent = '';
	let inFlight = false;

	const sync = (): void => {
		const method = readActivePaymentMethod();
		const update = wcRuntime()?.blocksCheckout?.extensionCartUpdate;

		if ( inFlight || method === '' || method === sent || typeof update !== 'function' ) {
			return;
		}

		inFlight = true;
		sent = method;

		void gateCheckout( async () => {
			try {
				await update( { namespace, data: { payment_method: method } } );
			} catch {
				// see above: not retried for this method
			}
		} ).finally( () => {
			inFlight = false;
			sync();
		} );
	};

	const unsubscribe = subscribe( sync );

	sync();

	return unsubscribe;
}
