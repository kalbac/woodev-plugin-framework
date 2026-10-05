/**
 * The Checkout block's host for the storefront pickup-map session (SP-11 C-2b, #1089; operator
 * decision D-4 A).
 *
 * `pickup-session.js` is the picker both checkouts share; what differs is where a surface keeps the
 * chosen point and how it asks the server. The classic checkout answers from DOM fields and the
 * `woodev/v1` select route (`pickup-mount.js`: `classicHost()`); this file answers from WooCommerce's
 * stores and the Store API:
 *
 * - CONTEXT is read LIVE, at the moment the session asks — the chosen point from the cart's
 *   confirmed snapshot, the locality KEY from the server's own answer (never the typed city) and the
 *   locality NAME the map centres on from the native shipping address.
 *   The points requests carry the LIVE payment gateway (`getRequestContext()`): the block checkout
 *   keeps the choice in the browser, and the session's own record may be an earlier checkout's.
 * - CONFIRMATION is one `cart/extensions` command, with Place Order blocked while it is in flight.
 * - ADDRESS-WRITE does nothing here: the confirmed snapshot is already in the cart store when the
 *   session applies a selection, and the button renders from it. The store's «replace the address
 *   with the point's» policy is honoured INSIDE the confirmation instead (`confirmPoint()`): the
 *   server moves the destination and binds the confirmation to it in one request — a browser that
 *   rewrote the native address afterwards would change the destination the point was confirmed
 *   for, and drop the confirmation it had just received.
 * - REFRESH is `null`: the confirmation's own reply is the recalculated cart.
 *
 * @package woodev-plugin-framework
 */

import { confirmPoint, currentView, readActivePaymentMethod } from './pickup-stores';
import type {
	PickupConfig,
	PickupData,
	PickupFieldDescriptor,
	PickupPoint,
	PickupSelectionResult,
	PickupSessionHost,
} from './pickup-types';
import { gateCheckout, readShippingAddress } from './wc-stores';

/**
 * The live `wp_rest` nonce for the points routes: the refreshable node's when the page has one
 * (`Pickup_Handler::print_nonce_node()`), the page-load value otherwise.
 */
export function liveNonce( config: PickupConfig ): string {
	const node = config.nonceNodeId ? document.getElementById( config.nonceNodeId ) : null;

	return node?.dataset.woodevPickupNonce || String( config.nonce ?? '' );
}

export interface HostOptions {
	data: PickupData;
	field: PickupFieldDescriptor;
	config: PickupConfig;
	/** The button focus returns to when the dialog closes. */
	trigger: HTMLElement | null;
	/** The dialog closed after a selection: the caller destroys the session it holds. */
	onClose: () => void;
	/**
	 * Whether the shopper is still on the rate this session was opened for — asked when a
	 * confirmation's answer lands (see `confirmPoint()`). Omitted: always.
	 */
	isCurrent?: () => boolean;
}

export function createHost( { data, field, config, trigger, onClose, isCurrent }: HostOptions ): PickupSessionHost {
	const namespace = data.namespace ?? '';

	return {
		returnFocusTo: trigger,

		// Only a point confirmed for THIS field and THIS rate counts as selected.
		getSelectedId: () => {
			const view = currentView( data );

			return view.field === field && view.confirmed ? view.confirmed.point_id : '';
		},

		getLocality: () => readShippingAddress().city,

		// The server's key — the locality the cart holds, the one a confirmation is made against —
		// and NOTHING ELSE. The classic checkout falls back to the typed city here, and there the
		// confirmation reads the same typed address; in the block checkout it reads the cart's
		// resolved locality, so a typed city the server never resolved would list points no
		// confirmation can take (#1110). `''` is the «no locality» answer; the picker does not open
		// a dialog on it (`PickupPicker`).
		getLocalityKey: () => {
			const view = currentView( data );

			return view.field === field ? view.owner?.locality ?? '' : '';
		},

		getNonce: () => liveNonce( config ),

		// The gateway chosen NOW, for the listing's and the card's «can this point be chosen»
		// verdict. The server honours it only when it names a gateway the store offers
		// (`Pickup_Handler::declared_payment_method()`); nothing chosen sends nothing.
		getRequestContext: () => {
			const payment = readActivePaymentMethod();

			return payment !== '' ? { payment_method: payment } : null;
		},

		confirmSelection: ( point: PickupPoint ): Promise< PickupSelectionResult > => {
			const pointId = String( point?.id ?? '' );
			const billingIsShipping = config.replaceAddress?.billingOnly === true;
			let outcome: Promise< PickupSelectionResult > | null = null;

			// The gate may run `work` later or not at all (see `gateCheckout()`); the request is
			// made exactly once either way, and its verdict — or its failure — is what the session
			// gets, never the gate's own.
			const send = (): Promise< PickupSelectionResult > =>
				( outcome ??= confirmPoint( namespace, field, pointId, billingIsShipping, isCurrent ) );

			return gateCheckout( () =>
				send().then(
					() => undefined,
					() => undefined
				)
			).then( send, send );
		},

		applySelection: () => undefined,

		close: onClose,

		checkoutRefresh: null,
	};
}
